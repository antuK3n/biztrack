<?php

namespace App\Services\KwikPay;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\FeeAssessment;
use App\Models\Payment;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WorkflowService;
use App\Support\Audit;
use App\Support\Numbering;
use App\Support\PaymentMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Paying through KwikPay: open a deposit, and settle it once KwikPay confirms.
 *
 * ── The one rule everything here serves ─────────────────────────────────────
 *
 * Only two things mean PAID (merchant docs FAQ, "When is it safe to credit"):
 * status 5 on a callback whose signature verifies, or "5" from /api/query. A
 * "1" from /api/transfer means the order was accepted and nothing more. So
 * `open()` never completes anything; `complete()` is reached only from the
 * callback and from `check()`.
 *
 * ── Idempotent by construction ──────────────────────────────────────────────
 *
 * The same outcome can arrive several times — a callback retried after a
 * network failure, a support-triggered resend, a callback racing the
 * reconciliation job. `complete()` and `fail()` move a payment with ONE
 * conditional UPDATE (`… WHERE status = 'pending'`), and only the caller whose
 * UPDATE touched the row goes on to call `onPaymentCompleted`. A second arrival
 * finds nothing pending and does nothing, whichever path it came by.
 *
 * ── Never double-create ─────────────────────────────────────────────────────
 *
 * A transfer that times out leaves its payment PENDING, not failed: the order
 * may well exist at KwikPay and be paid. Reconciliation asks about it. Only a
 * clear refusal (KwikPayResult::clearlyRejected) marks it failed on the spot.
 */
class KwikPayGateway
{
    /** Leave a fresh order alone this long before the first query. */
    public const FIRST_CHECK_AFTER_MINUTES = 2;

    /** Pending this long and staff are told. Never an automatic failure. */
    public const FLAG_AFTER_HOURS = 24;

    public function __construct(
        private KwikPayClient $client,
        private WorkflowService $workflow,
        private NotificationService $notify,
    ) {}

    /**
     * Open a KwikPay deposit for what is owed and hand back the pending payment.
     * The caller reads `status`: pending with a `pay_url` to send the owner to,
     * pending without one (KwikPay did not answer — reconciliation will), or
     * failed (KwikPay refused).
     */
    public function open(FeeAssessment $fee, PaymentMethod $method, float $amount): Payment
    {
        $reference = Numbering::paymentReference();
        $charge = self::chargeFor($amount);

        $payment = Payment::create([
            'application_id' => $fee->application_id,
            'fee_assessment_id' => $fee->id,
            'reference_number' => $reference,
            'gateway' => Payment::GATEWAY_KWIKPAY,
            // ASCII, no spaces, & or = (docs FAQ on order_id). Str::random is
            // [A-Za-z0-9]; upper-cased so a clerk can read it aloud.
            'gateway_order_id' => $reference.'-'.strtoupper(Str::random(6)),
            'amount' => $amount,
            // What KwikPay is asked to collect: the bill, or the test amount
            // while the super admin's charge switch says `test`. Kept, because
            // the switch can move before this payment is confirmed and the
            // confirmation is checked against what was actually requested.
            'gateway_amount' => $charge,
            'method' => $method,
            'status' => PaymentStatus::Pending,
            'next_check_at' => now()->addMinutes(self::FIRST_CHECK_AFTER_MINUTES),
        ]);

        $result = $this->client->transfer([
            'payment_type' => (string) config('payments.kwikpay.payment_type'),
            'amount' => number_format($charge, 2, '.', ''),
            'order_id' => $payment->gateway_order_id,
            'bank_code' => (string) $method->kwikpayBankCode(),
            'callback_url' => self::callbackUrl(),
            'return_url' => self::returnUrl($payment),
        ]);

        self::log('transfer', $payment, [
            'http' => $result->httpStatus,
            'no_answer' => $result->noAnswer,
            'body' => $result->body,
        ]);

        if (! $result->noAnswer && $result->httpStatus >= 200 && $result->httpStatus < 300 && $result->status() === '1') {
            [$url, $kind] = self::payUrl($result->body);
            $payment->update([
                'pay_url' => $url,
                'pay_url_kind' => $kind,
                'gateway_note' => $url === null ? 'Order accepted, but no payment address came back.' : null,
            ]);
            Audit::log('payment.opened', $payment, [
                'gateway' => 'kwikpay',
                'amount' => (string) $payment->amount,
                'order_id' => $payment->gateway_order_id,
            ]);

            return $payment->fresh();
        }

        if ($result->clearlyRejected()) {
            $payment->update([
                'status' => PaymentStatus::Failed,
                'gateway_note' => Str::limit($result->message(), 250),
                'next_check_at' => null,
            ]);
            Audit::log('payment.failed', $payment, [
                'gateway' => 'kwikpay', 'source' => 'transfer', 'http' => $result->httpStatus,
            ]);

            return $payment->fresh();
        }

        // Timed out, never connected, or a 5xx: unknown, so still pending.
        $payment->update(['gateway_note' => Str::limit($result->message(), 250)]);
        Audit::log('payment.unconfirmed', $payment, [
            'gateway' => 'kwikpay', 'source' => 'transfer', 'http' => $result->httpStatus,
        ]);

        return $payment->fresh();
    }

    /**
     * Ask KwikPay once what became of a pending payment, and act on the answer.
     * Used by the reconciliation job and by the owner's "Check payment status".
     *
     *   "5"      completed (amount checked when KwikPay reports one)
     *   "3"      failed
     *   "1"/"0"  left pending; "0" is never an outcome (docs §4, FAQ)
     */
    public function check(Payment $payment, string $source = 'query'): Payment
    {
        if (! $payment->isKwikPay() || ! $payment->isPending() || ! $payment->gateway_order_id) {
            return $payment;
        }

        $result = $this->client->query($payment->gateway_order_id);
        self::log('query', $payment, [
            'source' => $source,
            'http' => $result->httpStatus,
            'no_answer' => $result->noAnswer,
            'body' => $result->body,
        ]);

        $attempts = $payment->check_attempts + 1;
        $payment->update([
            'check_attempts' => $attempts,
            'next_check_at' => now()->addMinutes(self::backoffMinutes($attempts)),
            'gateway_note' => Str::limit($result->message(), 250),
        ]);

        $ok = ! $result->noAnswer && $result->httpStatus >= 200 && $result->httpStatus < 300;
        $echoed = (string) ($result->body['order_id'] ?? $payment->gateway_order_id);

        /*
         * Only when the switch says so does the answer settle anything. By
         * default the signed callback alone marks a payment paid (PaymentMode,
         * "What marks a KwikPay payment paid"), and this call just keeps the
         * note above current.
         */
        $outcome = $ok && $echoed === $payment->gateway_order_id ? self::queryOutcome($result) : null;

        if ($outcome !== null) {
            if ($outcome === 'paid') {
                $reported = $result->body['amount'] ?? null;
                if ($reported !== null && (float) $reported > 0 && ! self::sameAmount($reported, $payment)) {
                    $this->flag($payment, 'KwikPay reports it paid, but for '.$reported.' instead of '.self::requestedAmount($payment).'. Not credited.');

                    return $payment->fresh();
                }
                $this->complete($payment, $source);

                return $payment->fresh();
            }

            if ($outcome === 'failed') {
                $this->fail($payment, $source, 'KwikPay reports the payment failed.');

                return $payment->fresh();
            }
        }

        $this->flagIfOverdue($payment);

        return $payment->fresh();
    }

    /**
     * Still pending a day after it was opened, and nobody told yet: tell the
     * super admin. Asked after every check, and by the reconciliation run
     * about a payment whose check failed outright, so a payment that cannot
     * be settled still reaches staff.
     */
    public function flagIfOverdue(Payment $payment): void
    {
        $fresh = $payment->fresh();
        if ($fresh->isPending() && $fresh->flagged_at === null
            && $fresh->created_at->lte(now()->subHours(self::FLAG_AFTER_HOURS))) {
            $this->flag($fresh, 'Still not confirmed after '.self::FLAG_AFTER_HOURS.' hours.');
        }
    }

    /**
     * Mark it paid and move the application on — once, and together. Returns
     * false when it was not pending (already settled by another path), which
     * is not an error.
     *
     * ── Both or neither ──────────────────────────────────────────────────
     *
     * The conditional UPDATE and the filing's move are one transaction. They
     * were two steps, and a throw between them (a mail server down inside
     * `onPaymentCompleted`, in the scenario run) left the payment Completed on
     * a filing still at Pending Payment. Reconciliation reads pending payments
     * only, so nothing looked at it again, and the owner's next Pay opened an
     * order for ₱0.00 (payments-kwikpay 19). Rolled back, the payment is still
     * pending: reconciliation asks about it again — and settles it, where the
     * switch lets /api/query do that — and one still pending after a day is
     * flagged for the super admin like any other.
     */
    public function complete(Payment $payment, string $source, array $context = []): bool
    {
        return DB::transaction(function () use ($payment, $source, $context) {
            $moved = Payment::query()
                ->whereKey($payment->id)
                ->where('status', PaymentStatus::Pending->value)
                ->update([
                    'status' => PaymentStatus::Completed->value,
                    'paid_at' => now(),
                    'next_check_at' => null,
                    'updated_at' => now(),
                ]);

            if ($moved === 0) {
                return false;
            }

            $payment = $payment->fresh();
            Audit::log('payment.completed', $payment, [
                'amount' => (string) $payment->amount,
                'gateway' => 'kwikpay',
                'source' => $source,
            ] + $context);

            $this->workflow->onPaymentCompleted($payment);
            $this->catchDoublePayment($payment);

            return true;
        });
    }

    /**
     * "Pay a different way": set a pending payment aside so the owner can open
     * a new one — but only after asking KwikPay once what became of it.
     *
     * The docs never let us assume an open order is unpaid ("No answer is not
     * no payment"). So:
     *
     *   "5"            it WAS paid: completed here, and no new payment is needed
     *   "3"            it failed: marked failed, a new one is free to open
     *   "1"/"0"/none   set aside (`abandoned_at`), still `pending`: still
     *                  reconciled, its callback still accepted. If it later
     *                  turns out paid, catchDoublePayment() tells staff.
     */
    public function abandon(Payment $payment): Payment
    {
        $payment = $this->check($payment, 'owner_abandon');

        if ($payment->isPending() && $payment->abandoned_at === null) {
            $payment->update(['abandoned_at' => now()]);
            Audit::log('payment.abandoned', $payment, [
                'gateway' => 'kwikpay',
                'order_id' => $payment->gateway_order_id,
                'last_answer' => $payment->gateway_note,
            ]);
        }

        return $payment->fresh();
    }

    /**
     * Two payments went through for one application and one of them had been
     * set aside by the owner: the "paying again could charge you twice" the
     * confirmation warned about. Nothing is refunded automatically — that is a
     * cashier's decision — but the payment that settled second is marked for
     * refund review, the owner sees it in their payment history, and every
     * super admin and BPLO officer is told.
     */
    private function catchDoublePayment(Payment $payment): void
    {
        $others = Payment::query()
            ->where('application_id', $payment->application_id)
            ->whereKeyNot($payment->id)
            ->where('status', PaymentStatus::Completed->value)
            ->get();

        $involvesSetAside = $payment->abandoned_at !== null
            || $others->contains(fn (Payment $p) => $p->abandoned_at !== null);

        if ($others->isEmpty() || ! $involvesSetAside) {
            return;
        }

        $payment->update([
            'refund_review_at' => now(),
            'gateway_note' => 'Paid twice for one bill (also '.$others->pluck('reference_number')->implode(', ').'). Refund to be reviewed.',
        ]);
        Audit::log('payment.double_paid', $payment, [
            'other_payments' => $others->pluck('reference_number')->all(),
            'amount' => (string) $payment->amount,
        ]);

        $tracking = $payment->application?->tracking_id ?? '—';
        User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'bplo_staff']))
            ->get()
            ->each(fn (User $staff) => $this->notify->push(
                $staff,
                'payment_double_paid',
                'An application was paid twice',
                "Application {$tracking} was paid twice online: {$payment->reference_number} and "
                .$others->pluck('reference_number')->implode(', ')
                .'. The owner set one payment aside to pay another way, and both went through. A refund of '
                .$payment->amount.' needs to be reviewed.',
            ));
    }

    /** Mark it failed — once. Only on a FINAL "3"/3, never on silence. */
    public function fail(Payment $payment, string $source, string $note): bool
    {
        $moved = Payment::query()
            ->whereKey($payment->id)
            ->where('status', PaymentStatus::Pending->value)
            ->update([
                'status' => PaymentStatus::Failed->value,
                'next_check_at' => null,
                'gateway_note' => $note,
                'updated_at' => now(),
            ]);

        if ($moved === 0) {
            return false;
        }

        Audit::log('payment.failed', $payment, ['gateway' => 'kwikpay', 'source' => $source]);

        return true;
    }

    /**
     * Put a pending payment in front of staff: keep it pending, say why, and
     * tell every super admin once. Used after a day without an answer and on
     * any disagreement about the amount.
     */
    public function flag(Payment $payment, string $why): void
    {
        $first = $payment->flagged_at === null;
        $payment->update(['flagged_at' => $payment->flagged_at ?? now(), 'gateway_note' => Str::limit($why, 250)]);
        Audit::log('payment.flagged', $payment, ['reason' => $why]);

        if (! $first) {
            return;
        }

        $tracking = $payment->application?->tracking_id ?? '—';
        User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->get()
            ->each(fn (User $admin) => $this->notify->push(
                $admin,
                'payment_flagged',
                'An online payment needs checking',
                "Payment {$payment->reference_number} for application {$tracking} has not been confirmed. {$why}",
            ));
    }

    /**
     * Call /api/me and say, in plain words, whether KwikPay accepts us.
     *
     * The cheapest signed request there is, so it proves the three things a
     * real payment needs — the address is reachable, KwikPay knows our server's
     * IP (every request is IP-checked, docs FAQ), and our key signs correctly —
     * without opening an order. Works whatever the mode is, so it can be run
     * BEFORE switching on.
     *
     * @return array{ok: bool, message: string, merchant_display_name?: string, balance?: string, pending_balance?: string}
     */
    public function testConnection(): array
    {
        if (($missing = PaymentMode::missingCredentials()) !== []) {
            return ['ok' => false, 'message' => 'Missing on the server: '.implode(', ', $missing).'.'];
        }

        $result = $this->client->me();
        self::log('me', null, ['http' => $result->httpStatus, 'no_answer' => $result->noAnswer]);

        if ($result->noAnswer) {
            return ['ok' => false, 'message' => 'KwikPay did not answer. Check KWIKPAY_BASE_URL and that this server can reach it.'];
        }

        $merchant = (string) ($result->body['merchant'] ?? '');
        if ($result->httpStatus === 200 && $merchant === (string) config('payments.kwikpay.merchant')) {
            return [
                'ok' => true,
                'message' => 'Connected. KwikPay accepted this server and its signature.',
                'merchant_display_name' => (string) ($result->body['merchant_display_name'] ?? ''),
                'balance' => (string) ($result->body['balance'] ?? ''),
                'pending_balance' => (string) ($result->body['pending_balance'] ?? ''),
            ];
        }

        $hint = match ($result->httpStatus) {
            401 => ' The signature was refused: check KWIKPAY_KEY.',
            403 => ' This server\'s IP address is not on KwikPay\'s allowlist.',
            default => '',
        };

        return ['ok' => false, 'message' => 'KwikPay refused the request (HTTP '.$result->httpStatus.': '.$result->message().').'.$hint];
    }

    /** Whether a KwikPay amount ("100.000000", 100, "100.00") is this payment's. */
    public static function sameAmount(mixed $reported, Payment $payment): bool
    {
        if (! is_numeric($reported)) {
            return false;
        }

        return (int) round(((float) $reported) * 100) === (int) round(self::requestedAmount($payment) * 100);
    }

    /**
     * What KwikPay was asked to collect for this payment: the test amount if
     * the charge switch said `test` when the order was opened, otherwise the
     * bill itself. Never re-read from the switch, so flipping it does not
     * change what an order already open is checked against. Older payments,
     * opened before the column existed, fall back to `amount`.
     */
    public static function requestedAmount(Payment $payment): float
    {
        return (float) ($payment->gateway_amount ?? $payment->amount);
    }

    /**
     * The amount to ask KwikPay for: the test amount or the bill, as the
     * charge switch says now (PaymentMode::charge). It used to read
     * KWIKPAY_CHARGE_OVERRIDE here directly, which only an env edit and a
     * restart could change; that value is now the switch's default and the
     * test amount, not the decision.
     */
    public static function chargeFor(float $amount): float
    {
        return PaymentMode::chargeFor($amount);
    }

    /** 2, 4, 8, 16, 32, then every 60 minutes. */
    /**
     * What an /api/query answer says happened, under the "What marks a payment
     * paid" switch: 'paid', 'failed', or null for "nothing settled".
     *
     *   callback  nothing: only the signed callback settles a payment
     *   query     the status code, as KwikPay's merchant docs define it
     *   message   the message, as payment-gateway-kwgu.onrender.com reports
     *             it: status "5" only says the lookup worked, and the message
     *             says "Transaction completed successfully" or "Transaction
     *             failed" once the order is final
     */
    public static function queryOutcome(KwikPayResult $result): ?string
    {
        return match (PaymentMode::confirm()) {
            PaymentMode::CONFIRM_QUERY => match ($result->status()) {
                '5' => 'paid',
                '3' => 'failed',
                default => null,
            },
            PaymentMode::CONFIRM_MESSAGE => $result->status() !== '5' ? null : match (true) {
                str_contains(strtolower($result->message()), 'completed successfully') => 'paid',
                str_contains(strtolower($result->message()), 'transaction failed') => 'failed',
                default => null,
            },
            default => null,
        };
    }

    public static function backoffMinutes(int $attempts): int
    {
        return (int) min(60, 2 ** max(1, $attempts));
    }

    public static function callbackUrl(): string
    {
        $base = (string) (config('payments.kwikpay.callback_base_url') ?: config('app.url'));

        return rtrim($base, '/').'/api/v1/payments/kwikpay/callback';
    }

    /*
     * Back to the pay screen, with the payment it is about. `returned=1` is the
     * marker that tells the page the owner has just come back from paying, so
     * it asks once straight away instead of waiting for the next poll.
     */
    public static function returnUrl(Payment $payment): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return "{$base}/applications/{$payment->application_id}/pay?payment={$payment->id}&returned=1";
    }

    /**
     * The first non-empty of the three addresses, and whether to send the owner
     * there or show it as a QR code. Docs FAQ: "Read all three and use the first
     * non-empty value … Do not hardcode a single field."
     *
     * @param  array<string, mixed>  $body
     * @return array{0: ?string, 1: ?string}
     */
    public static function payUrl(array $body): array
    {
        foreach (['redirect_url' => 'link', 'qrcode_url' => 'qr', 'gcash_qr_url' => 'qr'] as $field => $kind) {
            $value = trim((string) ($body[$field] ?? ''));
            if ($value !== '') {
                return [$value, $kind];
            }
        }

        return [null, null];
    }

    /**
     * The KwikPay log. Bodies are logged as they came — KwikPay never sends the
     * key, and the requests' own bodies (which carry only a signature) are not
     * logged at all.
     */
    public static function log(string $event, ?Payment $payment, array $context): void
    {
        Log::channel('payments')->info("kwikpay.{$event}", [
            'payment_id' => $payment?->id,
            'order_id' => $payment?->gateway_order_id,
        ] + $context);
    }
}
