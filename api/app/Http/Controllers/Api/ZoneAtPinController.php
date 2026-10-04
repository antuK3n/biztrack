<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\PsicCode;
use App\Support\Zoning\PinZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The zone under the owner's pin, and the sentence that stops them when their
 * line of business is clearly not allowed there — asked by the wizard's
 * Location & Zoning step as the pin and the line change. The rule is
 * App\Support\Zoning\PinZone's; submit asks the same one.
 */
class ZoneAtPinController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'barangay_id' => ['required', 'integer', 'exists:barangays,id'],
            'psic_code_ids' => ['nullable', 'array', 'max:50'],
            'psic_code_ids.*' => ['integer', 'exists:psic_codes,id'],
        ]);

        $lat = (float) $data['latitude'];
        $lng = (float) $data['longitude'];
        $barangay = Barangay::find($data['barangay_id']);
        // In the order the wizard lists them, so the line named is its first.
        $byId = PsicCode::whereIn('id', $data['psic_code_ids'] ?? [])->get()->keyBy('id');
        $lines = array_map(fn ($id) => $byId->get((int) $id), $data['psic_code_ids'] ?? []);

        return response()->json(['data' => [
            'zone' => PinZone::at($lat, $lng, $barangay),
            'refusal' => PinZone::refusal($lat, $lng, $barangay, $lines),
        ]]);
    }
}
