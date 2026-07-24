<?php

namespace App\Http\Controllers\Api;

use App\Enums\PermitStatus;
use App\Http\Controllers\Controller;
use App\Models\Permit;
use Illuminate\Http\JsonResponse;

/**
 * PUBLIC permit verification (no auth). Exposes only non-PII fields — business
 * name + barangay/city, never owner details (guardrail).
 */
class VerifyController extends Controller
{
    public function show(string $permitNumber): JsonResponse
    {
        $permit = Permit::with(['permitType', 'business.address.barangay'])
            ->where('permit_number', $permitNumber)
            ->first();

        abort_if(! $permit, 404, 'Permit not found.');

        $address = $permit->business?->address;
        $isValid = $permit->status === PermitStatus::Active
            && $permit->valid_until
            && $permit->valid_until->endOfDay()->isFuture();

        return response()->json([
            'data' => [
                'permit_number' => $permit->permit_number,
                'status' => $permit->status?->value,
                'status_label' => $permit->status?->label(),
                'valid_from' => optional($permit->valid_from)->toDateString(),
                'valid_until' => optional($permit->valid_until)->toDateString(),
                'permit_type' => $permit->permitType ? ['name' => $permit->permitType->name] : null,
                'business' => $permit->business ? [
                    'name' => $permit->business->name,
                    'address' => $address ? [
                        'barangay' => $address->barangay ? ['name' => $address->barangay->name] : null,
                        'city' => $address->city,
                    ] : null,
                ] : null,
                'is_valid' => $isValid,
            ],
        ]);
    }
}
