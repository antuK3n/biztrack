<?php

namespace App\Support;

use App\Models\PermitType;
use App\Models\User;

/**
 * The lines of a Tax Order of Payment that are one clearance office's own.
 *
 * Ken, 6 October 2026: a clearance office (CHO, BFP, OBO, CENRO, CPDO) sees
 * only its OWN share of the Assessed Fee, while BPLO, the super admin and the
 * applicant see the whole bill. A line is the office's when it prices a
 * permit the office issues (`permit_codes`), or, carrying no permit, when the
 * revenue code books it to that office (`office`).
 *
 * One home for the rule, because two screens apply it: the review sheet
 * (ApplicationResource::feeAssessmentFor) and the owner profile
 * (OwnerProfileController). Two copies would let them disagree about what an
 * office was charged.
 */
class OfficeFeeShare
{
    /**
     * @param  array<int, mixed>  $items  the assessment's `line_items`
     * @return list<array<string, mixed>>
     */
    public static function linesFor(array $items, User $user): array
    {
        $ownCodes = PermitType::query()
            ->where('issuing_department_id', $user->department_id)
            ->pluck('code')
            ->all();
        $officeCode = $user->department?->code;

        return array_values(array_filter($items, function ($item) use ($ownCodes, $officeCode) {
            if (! is_array($item)) {
                return false;
            }
            $codes = $item['permit_codes'] ?? [];

            return $codes !== []
                ? array_intersect($codes, $ownCodes) !== []
                : $officeCode !== null && ($item['office'] ?? null) === $officeCode;
        }));
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    public static function total(array $lines): float
    {
        return round(array_sum(array_map(fn (array $item) => (float) ($item['amount'] ?? 0), $lines)), 2);
    }
}
