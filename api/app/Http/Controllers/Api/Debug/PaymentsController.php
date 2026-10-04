<?php

namespace App\Http\Controllers\Api\Debug;

use App\Http\Controllers\Controller;
use App\Services\KwikPay\KwikPayGateway;
use App\Support\DebugPanel;
use App\Support\PaymentMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Debug page's Payments section (docs/payment-gateway.md §2).
 *
 *   GET  debug/payments       PaymentMode::status(): mode, charge, test amount,
 *                             KwikPay's configuration (names, never values),
 *                             online payments waiting and flagged
 *   PUT  debug/payments       {mode?, charge?}, at least one
 *   POST debug/payments/test  one signed /api/me call
 *
 * The same switches as PUT /admin/payment-gateway and
 * `php artisan biztrack:payment-gateway`, through the panel's door: behind
 * `debug.panel`, so nothing here checks a role, and audited as
 * `debug.payments` with both switches before and after, so the defense's
 * changes read as one trail with the rest of the panel's.
 */
class PaymentsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => PaymentMode::status()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required_without_all:charge,confirm', Rule::in(PaymentMode::MODES)],
            'charge' => ['required_without_all:mode,confirm', Rule::in(PaymentMode::CHARGES)],
            'confirm' => ['required_without_all:mode,charge', Rule::in(PaymentMode::CONFIRMS)],
        ], [
            'mode.required_without_all' => 'Say which way owners pay, what KwikPay collects, or what marks a payment paid.',
            'charge.required_without_all' => 'Say which way owners pay, what KwikPay collects, or what marks a payment paid.',
            'confirm.required_without_all' => 'Say which way owners pay, what KwikPay collects, or what marks a payment paid.',
        ]);

        $before = self::switches();

        // The mode first: it is the one that can be refused, and a refusal
        // must leave the charge as it was too.
        if (isset($data['mode'])) {
            try {
                PaymentMode::set($data['mode']);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['mode' => [$e->getMessage()]]);
            }
        }
        if (isset($data['charge'])) {
            PaymentMode::setCharge($data['charge']);
        }
        if (isset($data['confirm'])) {
            PaymentMode::setConfirm($data['confirm']);
        }

        DebugPanel::audit('payments', $before, self::switches(), actorId: $request->user()->id);

        return response()->json(['data' => PaymentMode::status()]);
    }

    public function test(KwikPayGateway $gateway): JsonResponse
    {
        return response()->json(['data' => $gateway->testConnection()]);
    }

    /** @return array{mode: string, charge: string, test_amount: string, confirm: string} */
    private static function switches(): array
    {
        return [
            'mode' => PaymentMode::current(),
            'charge' => PaymentMode::charge(),
            'test_amount' => number_format(PaymentMode::testAmount(), 2, '.', ''),
            'confirm' => PaymentMode::confirm(),
        ];
    }
}
