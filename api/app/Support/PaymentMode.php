<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\Setting;
use App\Services\KwikPay\FakeKwikPay;
use App\Services\KwikPay\KwikPayGateway;
use Illuminate\Database\QueryException;

/**
 * Which way NEW payments are made: 'simulated' or 'kwikpay'.
 *
 * ── Where the value lives ───────────────────────────────────────────────────
 *
 * The `payment_gateway` row in `settings`, because it has to change on a
 * running server — off before a presentation, on after — and env config cannot.
 * Until somebody sets the row, `config('payments.default')` (PAYMENT_GATEWAY)
 * decides. [Ken, 2026-09-29: "ON/OFF SWITCH stored in the DATABASE … default
 * from config/env".]
 *
 * ── What it does NOT decide ─────────────────────────────────────────────────
 *
 * Anything about a payment already made. A KwikPay payment opened before the
 * switch went off is still reconciled against KwikPay and its callback is still
 * accepted; `payments.gateway` records which path made each row, and every
 * settle path reads that, never this. Turning the switch off must not strand
 * somebody's money in `pending`.
 *
 * ── Refusing to switch on ──────────────────────────────────────────────────
 *
 * `set('kwikpay')` refuses while the merchant credentials are missing: every
 * payment made in that state would fail at KwikPay, and the owner would be the
 * one to find out. Callers turn the refusal into a 422 (API) or a non-zero exit
 * (artisan).
 *
 * ── What KwikPay collects: a test charge or the full bill ───────────────────
 *
 * A second switch, beside the first and stored the same way (`kwikpay_charge`
 * in `settings`). `test` asks KwikPay for a token amount; `full` asks for the
 * bill. Either way the bill, the payment record and the receipt keep the real
 * assessed amount: only the figure sent to /api/transfer changes, and it is
 * kept in `payments.gateway_amount` so the confirmation is checked against it.
 *
 * It has to be switchable on a running server for the same reason the mode
 * is. The thesis defense takes real payments through KwikPay at ₱1; if a
 * panelist asks to see the actual amount, the super admin flips it to the
 * full bill from the Online Payments screen, and flips it back after [Ken,
 * 2026-10-04]. KWIKPAY_CHARGE_OVERRIDE could only do that with an env edit
 * and a restart.
 *
 * Until somebody sets the row, the env decides, exactly as it did before the
 * row existed: a positive KWIKPAY_CHARGE_OVERRIDE means `test`, anything else
 * `full`. The test amount is that override when it is a positive number, and
 * ₱1.00 when it is not — so switching to `test` on a server with no override
 * still collects a token amount rather than nothing.
 *
 * Like the mode, it decides only what NEW payments ask for. A payment opened
 * before a switch keeps its `gateway_amount`, and its confirmation is checked
 * against that (KwikPayGateway::requestedAmount), in either direction.
 */
class PaymentMode
{
    public const SIMULATED = 'simulated';

    public const KWIKPAY = 'kwikpay';

    public const MODES = [self::SIMULATED, self::KWIKPAY];

    private const KEY = 'payment_gateway';

    /** KwikPay collects the test amount; the records keep the bill. */
    public const CHARGE_TEST = 'test';

    /** KwikPay collects the bill. */
    public const CHARGE_FULL = 'full';

    public const CHARGES = [self::CHARGE_TEST, self::CHARGE_FULL];

    /** What `test` collects when KWIKPAY_CHARGE_OVERRIDE names no amount. */
    public const DEFAULT_TEST_AMOUNT = 1.00;

    private const CHARGE_KEY = 'kwikpay_charge';

    public static function current(): string
    {
        $stored = self::stored();
        if ($stored !== null && in_array($stored, self::MODES, true)) {
            return $stored;
        }

        $default = (string) config('payments.default', self::SIMULATED);

        return in_array($default, self::MODES, true) ? $default : self::SIMULATED;
    }

    public static function isKwikPay(): bool
    {
        return self::current() === self::KWIKPAY;
    }

    /**
     * Change the mode. Returns the mode it was before.
     *
     * @throws \InvalidArgumentException when the mode is unknown, or KwikPay is
     *                                   asked for without its credentials
     */
    public static function set(string $mode): string
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("Unknown payment mode: {$mode}.");
        }

        if ($mode === self::KWIKPAY && ($missing = self::missingCredentials()) !== []) {
            throw new \InvalidArgumentException(
                'Online payment cannot be turned on yet. These settings are missing on the server: '
                .implode(', ', $missing).'.'
            );
        }

        $before = self::current();
        Setting::write(self::KEY, $mode);

        return $before;
    }

    /**
     * The env keys KwikPay still needs, by NAME — never by value.
     *
     * payment_type is required by /api/transfer (merchant docs §4) and is
     * KwikPay's to assign, so a server without it cannot open a single payment.
     *
     * @return list<string>
     */
    public static function missingCredentials(): array
    {
        $needed = [
            'KWIKPAY_BASE_URL' => config('payments.kwikpay.base_url'),
            'KWIKPAY_MERCHANT' => config('payments.kwikpay.merchant'),
            'KWIKPAY_KEY' => config('payments.kwikpay.key'),
            'KWIKPAY_PAYMENT_TYPE' => config('payments.kwikpay.payment_type'),
        ];

        return array_keys(array_filter($needed, fn ($v) => trim((string) $v) === ''));
    }

    public static function kwikPayConfigured(): bool
    {
        return self::missingCredentials() === [];
    }

    /**
     * Change the mode and write it to the audit log. `$via` says which door
     * (api / artisan) so the trail shows how, not only who.
     *
     * @return array{from: string, to: string}
     *
     * @throws \InvalidArgumentException see set()
     */
    public static function switchTo(string $mode, string $via, ?int $actorId = null): array
    {
        $before = self::set($mode);
        Audit::log('payment_gateway.switched', null, ['from' => $before, 'to' => $mode, 'via' => $via], actorId: $actorId);

        return ['from' => $before, 'to' => $mode];
    }

    /** `test` or `full`: what KwikPay is asked to collect for a NEW payment. */
    public static function charge(): string
    {
        $stored = self::stored(self::CHARGE_KEY);
        if ($stored !== null && in_array($stored, self::CHARGES, true)) {
            return $stored;
        }

        return self::defaultCharge();
    }

    /**
     * What the charge is until somebody switches it: the env's behaviour from
     * before the switch existed. A positive KWIKPAY_CHARGE_OVERRIDE was a test
     * charge; no override, or a nonsense one, was the full bill.
     */
    public static function defaultCharge(): string
    {
        return self::envTestAmount() !== null ? self::CHARGE_TEST : self::CHARGE_FULL;
    }

    public static function isTestCharge(): bool
    {
        return self::charge() === self::CHARGE_TEST;
    }

    /** What `test` collects: KWIKPAY_CHARGE_OVERRIDE when it is a positive number, else ₱1.00. */
    public static function testAmount(): float
    {
        return self::envTestAmount() ?? self::DEFAULT_TEST_AMOUNT;
    }

    /**
     * The amount to ask KwikPay for, for a bill of `$amount`. Read once, when
     * the payment is opened; the answer is stored on the payment and never
     * recomputed.
     */
    public static function chargeFor(float $amount): float
    {
        return self::isTestCharge() ? self::testAmount() : $amount;
    }

    /**
     * Change what KwikPay collects, without an audit row — the caller writes
     * its own (switchCharge below; the Debug page writes `debug.payments`).
     * Returns the charge it was before.
     *
     * @throws \InvalidArgumentException when the charge is unknown
     */
    public static function setCharge(string $charge): string
    {
        if (! in_array($charge, self::CHARGES, true)) {
            throw new \InvalidArgumentException("Unknown charge: {$charge}. Use test or full.");
        }

        $before = self::charge();
        Setting::write(self::CHARGE_KEY, $charge);

        return $before;
    }

    /**
     * Change what KwikPay collects and write it to the audit log, the same way
     * switchTo() does for the mode. The test amount is recorded with it, so the
     * trail says "₱1.00", not merely "test".
     *
     * @return array{from: string, to: string}
     *
     * @throws \InvalidArgumentException when the charge is unknown
     */
    public static function switchCharge(string $charge, string $via, ?int $actorId = null): array
    {
        $before = self::setCharge($charge);
        Audit::log('payment_gateway.charge_switched', null, [
            'from' => $before,
            'to' => $charge,
            'via' => $via,
            'test_amount' => self::money(self::testAmount()),
        ], actorId: $actorId);

        return ['from' => $before, 'to' => $charge];
    }

    /**
     * What the owner's pay screen is told about the charge: the amount KwikPay
     * will collect when that is a test charge on online payments, and null
     * otherwise. Null in simulated mode as well, because nothing is collected
     * there and a "₱1.00" line would be a claim about money that never moves.
     */
    public static function ownerTestCharge(): ?string
    {
        return self::isKwikPay() && self::isTestCharge() ? self::money(self::testAmount()) : null;
    }

    /**
     * Everything the switch's screen and `status` command show. Names of
     * missing settings, never values; the merchant id is shown because it is
     * not a secret (it travels in every request body) and is how a person tells
     * test from live credentials. The key is never included.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $awaiting = Payment::query()->awaitingKwikPay();

        return [
            'mode' => self::current(),
            'default_mode' => (string) config('payments.default', self::SIMULATED),
            // What KwikPay collects for a new payment, what it would be with no
            // switch set (the env's say), and the amount `test` means.
            'charge' => self::charge(),
            'default_charge' => self::defaultCharge(),
            'test_amount' => self::money(self::testAmount()),
            'kwikpay' => [
                'configured' => self::kwikPayConfigured(),
                'missing' => self::missingCredentials(),
                'base_url' => (string) config('payments.kwikpay.base_url'),
                'merchant' => (string) config('payments.kwikpay.merchant'),
                'payment_type' => (string) config('payments.kwikpay.payment_type'),
                'callback_url' => KwikPayGateway::callbackUrl(),
                'callback_ips' => (array) config('payments.kwikpay.callback_ips', []),
                'fake_available' => FakeKwikPay::available(),
            ],
            'pending' => (clone $awaiting)->count(),
            'flagged' => (clone $awaiting)->whereNotNull('flagged_at')
                ->with('application:id,tracking_id')
                ->orderBy('flagged_at')
                ->limit(50)
                ->get()
                ->map(fn (Payment $p) => [
                    'id' => $p->id,
                    'reference_number' => $p->reference_number,
                    'order_id' => $p->gateway_order_id,
                    'tracking_id' => $p->application?->tracking_id,
                    'amount' => (string) $p->amount,
                    'created_at' => optional($p->created_at)->toISOString(),
                    'flagged_at' => optional($p->flagged_at)->toISOString(),
                    'note' => $p->gateway_note,
                ])->all(),
        ];
    }

    /** KWIKPAY_CHARGE_OVERRIDE as an amount, or null when it is not a positive number. */
    private static function envTestAmount(): ?float
    {
        $override = config('payments.kwikpay.charge_override');

        return is_numeric($override) && (float) $override > 0 ? round((float) $override, 2) : null;
    }

    /** "1.00": two decimals, no separator, the way amounts travel in this API. */
    private static function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /*
     * Null before the migration has run, rather than a crash: this is read on
     * the pay screen, and a server a migration behind should still take a
     * simulated payment the way it always has.
     */
    private static function stored(string $key = self::KEY): ?string
    {
        try {
            return Setting::read($key);
        } catch (QueryException) {
            return null;
        }
    }
}
