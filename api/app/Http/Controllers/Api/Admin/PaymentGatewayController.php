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
 * The payment gateway switch, for the super admin (docs/payment-gateway.md).
 *
 *   GET  admin/payment-gateway       mode, whether KwikPay is configured (names
 *                                    of missing settings, never values), and
 *                                    online payments still waiting or flagged
 *   PUT  admin/payment-gateway       {mode: simulated|kwikpay}; 422 when KwikPay
 *                                    is asked for without its credentials
 *   POST admin/payment-gateway/test  one signed /api/me call
 *
 * A debug screen is to be built on these later [Ken, 2026-09-29]; until then
 * the same three things are `php artisan biztrack:payment-gateway`.
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
            'mode' => ['required', Rule::in(PaymentMode::MODES)],
        ]);

        try {
            PaymentMode::switchTo($data['mode'], 'api', $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['mode' => [$e->getMessage()]]);
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
