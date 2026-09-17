<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\PsicCode;
use App\Support\LocationInsights;
use App\Support\ZoningConformance;
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
            // map is picked before the line further down the step, so absent is normal.
            'psic_code_id' => ['nullable', 'integer', 'exists:psic_codes,id'],
            'business_id' => ['nullable', 'integer'],
            /*
             * The barangay the applicant CHOSE, not one derived from the pin.
             *
             * The zoning answer below is keyed on a barangay because that is how
             * CPDO's sheets are drawn — one sheet per barangay, no geometry
             * inside it. Deriving it from the point instead would be deriving it
             * from `malabonGeo`, whose own docblock says an individual point
             * near an edge may be on the wrong side by ~100 m. The wizard already
             * refuses a pin that contradicts the chosen barangay, so by the time
             * this is asked the two agree and the chosen one is the honest key.
             */
            'barangay_id' => ['nullable', 'integer', 'exists:barangays,id'],
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

        /*
         * What the ordinance LISTS for this barangay's zones (checklist item 20).
         *
         * The conformity sentence used to appear only after Next was pressed,
         * and its verdict came from a `?zoning=deny` query parameter — a
         * presentational stand-in from the prototype. It is answered here so the
         * step can show it as the barangay and the line of business change, and
         * it is now anchored to the 695 uses read off City Ordinance 24-2018.
         *
         * Still a lookup, still not a determination: see ZoningConformance.
         */
        $insights['zoning'] = ZoningConformance::forBarangay(
            isset($data['barangay_id']) ? Barangay::with('zoningClassifications')->find($data['barangay_id']) : null,
            $psic,
        );

        return response()->json([
            'data' => $insights,
            /*
             * `engine` said 'PHP' and `engine_version` carried PHP_VERSION, both
             * of which only meant anything as "not R". R has been removed, so
             * this matches what AnalyticsResolver now puts on every other
             * analytics response: one named engine, no version. The keys stay
             * because the client reads this block unconditionally.
             *
             * `source` is always 'local' here and correctly so — location
             * insights are computed per point and are not in the precomputed
             * set, so there is no snapshot for them to have come from.
             */
            'meta' => [
                'source' => 'local',
                'engine' => 'BizTrack',
                'engine_version' => null,
                'computed_at' => CarbonImmutable::now()->toISOString(),
            ],
        ]);
    }
}
