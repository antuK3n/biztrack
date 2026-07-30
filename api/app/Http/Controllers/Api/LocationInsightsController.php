<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\PsicCode;
use App\Support\LocationInsights;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business Location Insights for the apply wizard's zoning step (spec §5).
 *
 * Decision support only. It does not decide zoning conformity and returns
 * nothing that could be mistaken for a verdict — CPDO still rules on the
 * locational clearance during processing.
 *
 * Gated on `application.create` (the wizard's own permission) so the audience is
 * exactly the people filing. The payload is aggregate — a count, a band, a
 * category name, a mean distance — and never names another business, so one
 * applicant learning it about a block does not learn anything about a neighbour.
 *
 * `meta` follows AnalyticsResolver's shape so the UI can label provenance the
 * same way everywhere, but the source is always PHP here and always current:
 * see LocationInsights for why a batch snapshot cannot answer a question keyed
 * by a point the applicant picked seconds ago.
 */
class LocationInsightsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // The applicant's own line, when they have picked one. The zoning
            // step runs before the Line of Business step, so absent is normal.
            'psic_code_id' => ['nullable', 'integer', 'exists:psic_codes,id'],
            'business_id' => ['nullable', 'integer'],
        ]);

        $psic = isset($data['psic_code_id'])
            ? PsicCode::find($data['psic_code_id'])
            : null;

        /*
         * Only the caller's OWN business may be excluded. Accepting an arbitrary
         * id would turn the count into an oracle: diff the total with and
         * without an id and you learn whether that business sits on this block.
         */
        $excludeId = null;
        if (isset($data['business_id'])) {
            $owned = Business::where('id', $data['business_id'])
                ->where('owner_user_id', $request->user()->id)
                ->exists();
            $excludeId = $owned ? (int) $data['business_id'] : null;
        }

        $insights = LocationInsights::forPoint(
            (float) $data['latitude'],
            (float) $data['longitude'],
            $psic?->code,
            $excludeId,
        );

        $insights['similar']['psic_title'] = $psic?->title;

        return response()->json([
            'data' => $insights,
            'meta' => [
                'source' => 'local',
                'engine' => 'PHP',
                'engine_version' => PHP_VERSION,
                'computed_at' => CarbonImmutable::now()->toISOString(),
            ],
        ]);
    }
}
