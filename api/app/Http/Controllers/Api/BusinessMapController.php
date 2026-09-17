<?php

namespace App\Http\Controllers\Api;

use App\Enums\PermitStatus;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Every business in the register, at its pin, with the state of its Mayor's
 * Permit (issue #104: "GIS mapping showing all businesses and whether their
 * permit is still active").
 *
 * ── Why "their permit" is the Mayor's Permit and not all six ─────────────────
 *
 * A business here can hold up to six certificates — Mayor's, Sanitary, FSIC,
 * Occupancy, CEC and Zoning — so "is their permit active" has six answers, and
 * a marker cannot carry six. The Mayor's / Business Permit (`code = BUSINESS`)
 * is the one that says the shop may trade; the other five are clearances that
 * feed it. So the map answers for that one and the screen names it, rather than
 * rolling six certificates into a single word nobody could unpack.
 *
 * Looked up by `code`, not by the id 1 it currently has. The permit-type rows
 * are seeded, and the zoning type was added later as id 7 — a hard-coded id is
 * the kind of thing that survives until someone reseeds and then silently maps
 * the wrong certificate.
 *
 * ── Why this is not paginated ────────────────────────────────────────────────
 *
 * Every other register list on the admin side is paged, and this one must not
 * be: a map of page 1 of 15 is a map of nothing. Measured against the live
 * register on 17 September 2026 the whole set is 742 rows / 130 KB, built in
 * 108 ms from two queries — one ordinary response. If the register grows to
 * the tens of thousands of
 * businesses Malabon actually has, the answer is a bounding-box parameter
 * (only what the viewport covers), not a page number — a page is an arbitrary
 * slice of the city, a bounding box is a place.
 *
 * ── Gate ─────────────────────────────────────────────────────────────────────
 *
 * `user.manage`, held by the super admin alone. `permit.view_all` reads like
 * the better fit and is the wrong one: all seven office roles hold it, so this
 * would put a city-wide plot of every business on six offices' rails when the
 * whole point of `ApplicationVisibility` is that an office sees its own work.
 * This is the same stand-in Records makes (see the comment on its rail entry in
 * `web/src/lib/nav.ts`) — `user.manage` is standing in for a "this is the super
 * admin" check the permission table has no other way to express. An
 * `admin.console` permission, if one is ever added, belongs here too.
 */
class BusinessMapController extends Controller
{
    /**
     * A business permit is live when BOTH its status and its dates say so.
     *
     * Testing `status === active` alone is wrong against this register, and
     * measurably so: 2,182 permits carry status `active` but only 2,033 are
     * still inside `valid_until`. The 149-row gap is not corruption — flipping
     * a past-due permit to `expired` is `biztrack:scan-permits`' job, and that
     * command is not on a scheduler anywhere in this repo, so nothing has been
     * flipping them. Trusting the column would therefore paint 149 lapsed
     * businesses as trading legally, which is the single thing this screen
     * exists not to get wrong.
     *
     * Testing the DATE alone would be wrong in the other direction: a revoked
     * or superseded permit can still be inside its term. `PermitStatus::isLive`
     * is the one predicate for "can be acted on today"; the date is the other
     * half, and both have to hold.
     */
    private function isLive(Permit $permit, CarbonImmutable $today): bool
    {
        return $permit->status->isLive()
            && $permit->valid_until !== null
            && $permit->valid_until->greaterThanOrEqualTo($today);
    }

    public function index(): JsonResponse
    {
        $today = CarbonImmutable::today();

        $businessPermitTypeId = PermitType::where('code', 'BUSINESS')->value('id');

        /*
         * Addresses and barangay eager-loaded; `businesses` itself is a Model
         * with soft deletes, so the default scope already drops the 61 removed
         * rows. A deleted business is not on the map on purpose: it is not
         * trading, and plotting it would make the marker count disagree with
         * every other count of the register in the product.
         */
        $businesses = Business::query()
            ->with(['address.barangay:id,name'])
            ->orderBy('id')
            ->get(['id', 'name', 'trade_name', 'status']);

        /*
         * One query for every Mayor's Permit in the register rather than a
         * per-business lookup — 1,464 rows against 742 businesses, so the N+1
         * would be 742 round trips to save loading about 60 KB.
         *
         * Ordered by `valid_until` ascending so the LAST one written into the
         * per-business slot below is the furthest-reaching, which is the permit
         * that governs today. A business mid-renewal legitimately holds two
         * (the superseded one and its replacement) and the replacement is the
         * one a reader means.
         */
        $permitsByBusiness = $businessPermitTypeId === null
            ? collect()
            : Permit::query()
                ->where('permit_type_id', $businessPermitTypeId)
                ->orderBy('valid_until')
                ->get(['id', 'business_id', 'permit_number', 'status', 'valid_until'])
                ->groupBy('business_id');

        $unmapped = 0;
        $rows = [];

        foreach ($businesses as $business) {
            $address = $business->address;

            /*
             * No pin, no marker — and the page is told how many, rather than
             * the omission being silent. A map that quietly drops part of the
             * register is worse than a table, because it reads as complete.
             *
             * It is 2 businesses out of 744 today. That is small enough to be
             * worth stating plainly and far too small to justify geocoding the
             * text address, which would put a GUESSED point on a map whose
             * whole claim is that the pins were placed by the owner.
             */
            if ($address === null || $address->latitude === null || $address->longitude === null) {
                $unmapped++;

                continue;
            }

            $permits = $permitsByBusiness->get($business->id);
            $live = $permits?->last(fn (Permit $p) => $this->isLive($p, $today));
            $latest = $permits?->last();
            $governing = $live ?? $latest;

            /*
             * Three states, not two, because "no permit at all" is a real and
             * common answer here — 136 of 744 businesses have never held a
             * Mayor's Permit in this register — and folding it into "expired"
             * would tell a BPLO clerk that a certificate lapsed when none was
             * ever issued. They are different jobs: one is a renewal to chase,
             * the other is a first filing that never completed.
             */
            $state = match (true) {
                $live !== null => 'active',
                $latest !== null => 'lapsed',
                default => 'none',
            };

            $rows[] = [
                'id' => $business->id,
                'name' => $business->trade_name ?: $business->name,
                'latitude' => (float) $address->latitude,
                'longitude' => (float) $address->longitude,
                /*
                 * The barangay the OWNER declared on the form, which is not
                 * necessarily the barangay the pin lands in. The page compares
                 * the two with the polygon set it already ships; the API has no
                 * geometry and must not pretend to arbitrate.
                 */
                'barangay' => $address->barangay?->name,
                'state' => $state,
                'permit_number' => $governing?->permit_number,
                'valid_until' => $governing?->valid_until?->toDateString(),
            ];
        }

        $counts = collect($rows)->countBy('state');

        return response()->json([
            'data' => $rows,
            'meta' => [
                'plotted' => count($rows),
                /*
                 * Named so the screen can say "742 of the 744 businesses on
                 * file" rather than "742 businesses" — AGENTS.md §6.4, name
                 * both numbers. A bare count of what survived a filter is the
                 * one number that cannot be checked.
                 */
                'businesses_total' => $businesses->count(),
                'unmapped' => $unmapped,
                'counts' => [
                    'active' => $counts->get('active', 0),
                    'lapsed' => $counts->get('lapsed', 0),
                    'none' => $counts->get('none', 0),
                ],
                'as_of' => $today->toDateString(),
            ],
        ]);
    }
}
