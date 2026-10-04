<?php

namespace App\Support;

use App\Models\Application;
use App\Models\ApplicationOfficeForm;

/**
 * What each row of an office's checklist says right now.
 *
 * ── Why it exists ───────────────────────────────────────────────────────────
 *
 * An office returns a sheet naming some rows; the applicant fixes them and
 * sends it back; the office reopens it and sees the sheet it sent, unchanged
 * to the eye. Taking this before the return and again at the resubmission is
 * what lets the office be told which row moved and what it used to say —
 * the thing BPLO's sheet has had since 29 September 2026 and the offices
 * have not.
 *
 * ── Display text, deliberately ──────────────────────────────────────────────
 *
 * Every value here ends up as `old_value` / `new_value` on an
 * `ApplicationCorrection`, which a person reads. Nothing parses it back. A
 * document is named by its filename because that is what the officer is
 * looking at in the row; an answer is its own text.
 *
 * ── Two kinds of target, one map ────────────────────────────────────────────
 *
 * `remarks_target` on a clearance carries both: a checklist row's document
 * code (`ZONING_LEASE_TITLE`) and an office-sheet answer key (`site_area`).
 * They come from different places — `SheetRequirements` and the sheet's own
 * `form_data` — and the officer picks from one list, so they are read into
 * one map here rather than leaving each caller to know which is which.
 */
class ClearanceSnapshot
{
    /**
     * The readable value of every code this return names.
     *
     * Codes that match neither a checklist row nor an answer are left out
     * rather than stored null: absent means "there was nothing here to
     * compare", which is a different fact from "this was empty" and would
     * otherwise be reported to the officer as a change from nothing.
     *
     * @param  list<string>  $codes
     * @return array<string, string|null>
     */
    public static function capture(Application $application, string $permitTypeCode, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $rows = [];
        /* Null for a permit with no checklist — SANITARY has none. */
        foreach (SheetRequirements::for($application, $permitTypeCode) ?? [] as $row) {
            if (($row['code'] ?? null) !== null) {
                $rows[$row['code']] = $row;
            }
        }

        $answers = self::answers($application, $permitTypeCode);

        $out = [];
        foreach ($codes as $code) {
            if (isset($rows[$code])) {
                /*
                 * The filename, or null for a row with nothing attached. Null
                 * is a real answer here — "you never sent this" — unlike an
                 * unknown code, which is simply absent from the map.
                 */
                $document = $rows[$code]['document'] ?? null;
                $out[$code] = $document === null
                    ? null
                    : ($document['filename'] ?? $document['name'] ?? null);

                continue;
            }

            if (array_key_exists($code, $answers)) {
                $out[$code] = self::readable($answers[$code]);
            }
        }

        return $out;
    }

    /** This permit's sheet answers, or an empty map when no sheet exists yet. */
    private static function answers(Application $application, string $permitTypeCode): array
    {
        $sheet = ApplicationOfficeForm::where('application_id', $application->id)
            ->whereHas('permitType', fn ($q) => $q->where('code', $permitTypeCode))
            ->first();

        return is_array($sheet?->form_data) ? $sheet->form_data : [];
    }

    /**
     * One answer as a person would read it.
     *
     * An answer can be a list — the DENR permit checkboxes are an array — and
     * a boolean reads as a word rather than as 1 or an empty string, which is
     * what `(string)` would have made of it.
     */
    private static function readable(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            $parts = array_filter(array_map(
                fn ($v) => is_scalar($v) ? trim((string) $v) : null,
                $value,
            ));

            return $parts === [] ? null : implode(', ', $parts);
        }

        return is_scalar($value) ? trim((string) $value) : null;
    }
}
