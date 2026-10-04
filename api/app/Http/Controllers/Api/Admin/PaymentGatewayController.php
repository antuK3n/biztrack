<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\KwikPay\KwikPayGateway;
use App\Support\PaymentMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The payment gateway switches, for the super admin (docs/payment-gateway.md).
 *
 *   GET  admin/payment-gateway       mode, what KwikPay collects (test charge or
 *                                    the full bill), whether KwikPay is
 *                                    configured (names of missing settings,
 *                                    never values), and online payments still
 *                                    waiting or flagged
 *   PUT  admin/payment-gateway       {mode?: simulated|kwikpay,
 *                                     charge?: test|full}, at least one; 422
 *                                    when KwikPay is asked for without its
 *                                    credentials
 *   POST admin/payment-gateway/test  one signed /api/me call
 *
 * The Online Payments screen (web/src/pages/admin/OnlinePaymentsPage.tsx) is
 * built on these; `php artisan biztrack:payment-gateway` does the same from a
 * terminal, for when the screen cannot be reached.
 *
 * `charge` arrived after `mode`, so `mode` alone is still a whole request: the
 * callers written before the charge switch existed keep working unchanged.
 *
 * Every switch is audit-logged, including one that changes nothing, because
 * "who pressed it just before the presentation" is the question the log is for.
 */
class PaymentGatewayController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['data' => PaymentMode::status()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $data = $request->validate([
            'mode' => ['required_without:charge', Rule::in(PaymentMode::MODES)],
            'charge' => ['required_without:mode', Rule::in(PaymentMode::CHARGES)],
        ], [
            'mode.required_without' => 'Say which way owners pay, or what KwikPay collects.',
            'charge.required_without' => 'Say which way owners pay, or what KwikPay collects.',
        ]);

        /*
         * The mode first, because it is the one that can be refused. A request
         * carrying both and refused on the mode then changes neither: half of
         * a change the super admin asked for as one is a state nobody chose.
         */
        if (isset($data['mode'])) {
            try {
                PaymentMode::switchTo($data['mode'], 'api', $request->user()->id);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['mode' => [$e->getMessage()]]);
            }
        }

        if (isset($data['charge'])) {
            PaymentMode::switchCharge($data['charge'], 'api', $request->user()->id);
        }

        return response()->json(['data' => PaymentMode::status()]);
    }

    public function test(Request $request, KwikPayGateway $gateway): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['data' => $gateway->testConnection()]);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless(
            $request->user()?->hasRole('admin'),
            403,
            'Only the super admin can change how payments are taken.'
        );
    }
}
