<?php

namespace App\Services\KwikPay;

use App\Models\Payment;
use App\Support\Audit;

/**
 * A deposit callback from KwikPay: decide whether to believe it, and act.
 *
 * Merchant docs FAQ, "When is it safe to credit a deposit": all five of
 *   1. the callback carries our `merchant` id
 *   2. `order_id` is an order we opened and have not already credited
 *   3. `amount` matches what we expected — NUMERICALLY ("100.000000" is 100)
 *   4. `sign` recomputes with our key, over every field received
 *   5. `status` is 5
 * If any one fails, nothing is credited.
 *
 * Order matters in one place: the signature is checked over the RAW fields
 * before anything reads `amount` as a number (FAQ: "Verify the signature over
 * the raw 100.000000 string first, then parse it").
 *
 * Accepted whatever the payment mode is now. A payment opened while KwikPay was
 * on is still KwikPay's to settle after the switch is turned off.
 */
class KwikPayCallback
{
    public const SETTLED = 'settled';

    public const FAILED = 'failed';

    public const DUPLICATE = 'duplicate';

    public const BAD_SIGNATURE = 'bad_signature';

    public const IP_REFUSED = 'ip_refused';

    public const WRONG_MERCHANT = 'wrong_merchant';

    public const UNKNOWN_ORDER = 'unknown_order';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public const IGNORED = 'ignored';

    public function __construct(private KwikPayGateway $gateway) {}

    /**
     * @param  array<string, mixed>  $fields  exactly as received
     * @return string one of the constants above
     */
    public function handle(array $fields, ?string $ip): string
    {
        $allowed = (array) config('payments.kwikpay.callback_ips', []);
        if ($allowed !== [] && ! in_array($ip, $allowed, true)) {
            return self::IP_REFUSED;
        }

        if (! Signature::verify($fields, (string) config('payments.kwikpay.key'))) {
            return self::BAD_SIGNATURE;
        }

        if ((string) ($fields['merchant'] ?? '') !== (string) config('payments.kwikpay.merchant')) {
            return self::WRONG_MERCHANT;
        }

        $orderId = (string) ($fields['order_id'] ?? '');
        $payment = $orderId === '' ? null : Payment::query()
            ->where('gateway', Payment::GATEWAY_KWIKPAY)
            ->where('gateway_order_id', $orderId)
            ->first();

        if (! $payment) {
            return self::UNKNOWN_ORDER;
        }

        $status = (string) ($fields['status'] ?? '');

        if (! $payment->isPending()) {
            /*
             * Already settled — by an earlier copy of this callback, or by the
             * reconciliation job getting there first. The FAQ warns the same
             * callback "can arrive more than once"; this is that, and doing
             * nothing is the correct response to it.
             */
            return self::DUPLICATE;
        }

        if (! KwikPayGateway::sameAmount($fields['amount'] ?? null, $payment)) {
            $this->gateway->flag(
                $payment,
                'KwikPay sent '.($fields['amount'] ?? 'no amount').' for a payment of '.KwikPayGateway::requestedAmount($payment).'. Not credited.'
            );

            return self::AMOUNT_MISMATCH;
        }

        if ($status === '5') {
            return $this->gateway->complete($payment, 'callback', ['order_id' => $orderId])
                ? self::SETTLED
                : self::DUPLICATE;
        }

        if ($status === '3') {
            return $this->gateway->fail($payment, 'callback', 'KwikPay reports the payment failed.')
                ? self::FAILED
                : self::DUPLICATE;
        }

        // Callbacks only ever carry 5 or 3 (FAQ). Anything else is logged and left.
        Audit::log('payment.callback_ignored', $payment, ['status' => $status]);

        return self::IGNORED;
    }
}
