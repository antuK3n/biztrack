<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Permit;
use App\Models\PermitType;

/**
 * What an amendment may name, and how many may be open at once.
 *
 * ── Only the Business Permit is amended ─────────────────────────────────────
 *
 * Client, 19 September 2026: *"We have clarified with the LGU that only
 * business permit details can be amended."* `WorkflowService::
 * permitTypeIdsAtSubmission` has carried that since, by ADDING the business
 * permit (and Zoning, for a move) at submit — but it adds with
 * `syncWithoutDetaching`, and nothing refused what a caller sent. Browser
 * testing on 5 October 2026 created an amendment through the API naming a
 * Sanitary Permit as the prior permit and SANITARY as its type: 201, it
 * submitted carrying BUSINESS and SANITARY, and BPLO's approve then 422'd
 * citing the zoning ordinance — a filing nobody could finish.
 *
 * So the prior permit must be a Business Permit, and the types may be the
 * business permit and Zoning (which the move rule attaches) and nothing else.
 *
 * ── One open amendment per business ────────────────────────────────────────
 *
 * The same testing submitted a second amendment while the first was still
 * with BPLO. Two filings each rewriting the same register fields, approved in
 * either order, leave whichever lands second as the truth and the first's
 * reprinted permit already wrong. A submitted, undecided amendment blocks the
 * next; a draft does not, because drafts are abandoned all the time.
 */
final class AmendmentScope
{
    /** The permit types an amendment may carry. */
    public const ALLOWED_CODES = [PermitType::OUTCOME_CODE, 'ZONING'];

    /**
     * Why this amendment may not name these permits or types, or null.
     *
     * @param  array<int, int>  $priorPermitIds
     * @param  array<int, int>|null  $permitTypeIds  null when the caller sent none
     */
    public static function refusal(array $priorPermitIds, ?array $permitTypeIds): ?string
    {
        $priorIds = array_values(array_unique(array_filter(array_map('intval', $priorPermitIds))));

        if ($priorIds !== []) {
            $notBusiness = Permit::query()
                ->whereKey($priorIds)
                ->whereDoesntHave('permitType', fn ($q) => $q->where('code', PermitType::OUTCOME_CODE))
                ->exists();
            if ($notBusiness || count($priorIds) > 1) {
                return 'Only the Business Permit can be amended.';
            }
        }

        if ($permitTypeIds !== null && $permitTypeIds !== []) {
            $stray = PermitType::query()
                ->whereKey(array_map('intval', $permitTypeIds))
                ->whereNotIn('code', self::ALLOWED_CODES)
                ->exists();
            if ($stray) {
                return 'Only the Business Permit can be amended.';
            }
        }

        return null;
    }

    /**
     * Why the permit this amendment names is not one that can be amended, or
     * null. An amendment alters a live permit: one that has expired is renewed
     * first, and one that was revoked is gone (Ken, 5 October 2026 — the
     * scenario run approved both, rewriting the register and reprinting
     * nothing). Under the 31 December rule every Business Permit is Expired
     * from 1 January until renewed, so January amendments land here.
     *
     * @param  array<int, int|null>  $priorPermitIds
     */
    public static function standingRefusal(array $priorPermitIds): ?string
    {
        $statuses = Permit::query()
            ->whereKey(array_values(array_filter(array_map('intval', $priorPermitIds))))
            ->pluck('status');

        if ($statuses->contains(PermitStatus::Revoked)) {
            return 'This permit has been revoked.';
        }
        if ($statuses->contains(PermitStatus::Expired)) {
            return 'Renew this permit before amending it.';
        }

        return null;
    }

    /**
     * Why another amendment of this business may not be filed now, or null.
     * `$except` is the filing asking, never counted against itself.
     */
    public static function openRefusal(int $businessId, ?Application $except = null): ?string
    {
        $open = Application::query()
            ->where('business_id', $businessId)
            ->where('application_type', ApplicationType::Amendment->value)
            ->where('status', '!=', ApplicationStatus::Draft->value)
            ->notDecided()
            ->when($except?->id !== null, fn ($q) => $q->whereKeyNot($except->id))
            ->first(['id', 'tracking_id']);

        if ($open === null) {
            return null;
        }

        return sprintf('This business already has an amendment under review (%s).', $open->tracking_id ?? '#'.$open->id);
    }
}
