<?php

namespace App\Support;

use App\Models\Application;
use App\Models\DocumentType;

/**
 * The documents an office sheet asks for, whichever sheet it is.
 *
 * ── Why a dispatcher and not two callers ──────────────────────────────────
 *
 * `ZoningRequirements` was written for the one paper that has a checklist, and
 * three places learned to call it BY NAME: the applicant's sheet, the officer's
 * review screen, and the upload endpoint. When CENRO's sheet turned out to ask
 * for something too — the previous year's CEC, its own FOR RENEWAL row — the
 * cheap move was a second `if ($code === 'CEC')` beside each of those three.
 * That is the shape this codebase keeps being repaired for: a rule spread over
 * its consumers, where adding the fourth consumer means finding all three.
 *
 * So the consumers ask one question — "what does THIS sheet want?" — and the
 * answer lives here. A sixth office that grows a requirement is one arm of one
 * match, not an edit to every screen that renders a form.
 *
 * `null` rather than `[]` for a sheet with no requirements, and the difference
 * matters at the far end: an empty list renders as "this office asks for
 * nothing", which is a claim, and it is not one CHO, BFP or OBO have made.
 */
final class SheetRequirements
{
    /**
     * The checklist for one sheet on one filing, or null if it has none.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function for(Application $application, string $permitTypeCode): ?array
    {
        return match ($permitTypeCode) {
            'ZONING' => ZoningRequirements::forApplication($application),
            'CEC' => CecRequirements::forApplication($application),
            default => null,
        };
    }

    /** Is `$code` a slot any sheet accepts a file into? */
    public static function accepts(string $permitTypeCode, string $code): bool
    {
        return match ($permitTypeCode) {
            'ZONING' => ZoningRequirements::accepts($code),
            'CEC' => CecRequirements::accepts($code),
            default => false,
        };
    }

    /** The document type behind one slot, created on demand. */
    public static function documentType(string $permitTypeCode, string $code): DocumentType
    {
        return match ($permitTypeCode) {
            'CEC' => CecRequirements::documentType($code),
            default => ZoningRequirements::documentType($code),
        };
    }
}
