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
 */
class PaymentMode
{
    public const SIMULATED = 'simulated';

    public const KWIKPAY = 'kwikpay';

    public const MODES = [self::SIMULATED, self::KWIKPAY];

    private const KEY = 'payment_gateway';

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
        Audit::log('payment_gateway.switched', null, ['from' => $before, 'to' => $mode, 'via' => $via], $actorId);

        return ['from' => $before, 'to' => $mode];
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

    /*
     * Null before the migration has run, rather than a crash: this is read on
     * the pay screen, and a server a migration behind should still take a
     * simulated payment the way it always has.
     */
    private static function stored(): ?string
    {
        try {
            return Setting::read(self::KEY);
        } catch (QueryException) {
            return null;
        }
    }
}
