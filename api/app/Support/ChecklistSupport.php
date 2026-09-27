<?php

namespace App\Support;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationOfficeForm;
use App\Models\DocumentType;

/**
 * The plumbing every office checklist needs, written once.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 *
 * `ZoningRequirements` grew five private helpers — which files are uploaded,
 * which BPLO attachments already answer a row, how to describe one, whether the
 * sheet has been handed in — and they are not about zoning. Adding the BFP and
 * OBO checklists on 27 September 2026 would have made three copies of each.
 *
 * This codebase has a standing note about exactly that failure, on
 * `OfficeFormAnswers`: *"a rule that lives on one of its two consumers is a rule
 * the other consumer cannot obey."* Three copies of "is this sheet submitted"
 * is three chances for one of them to keep saying yes after the rule changes.
 *
 * So the helpers live here, keyed by the calling sheet's permit code and its
 * document-code prefix, and the per-office classes hold only the thing that is
 * genuinely theirs: the rows their paper prints and the branches it takes.
 */
final class ChecklistSupport
{
    /**
     * The BPLO attachments a sheet may point at instead of asking again.
     *
     * One list, because it names what the BUSINESS PERMIT application collects
     * and that does not vary by office. A sheet says which of them answers one
     * of its rows through `carried_from`.
     *
     * @var list<string>
     */
    public const CARRYABLE = [
        'DTI_SEC_CDA',
        'LAND_TITLE',
        'LEASE_CONTRACT',
        'LOCATION_SKETCH',
        'SPA_AUTHORIZATION',
        'BIR_SALES_TAX_RETURN',
        'LIABILITY_INSURANCE',
        'REGULATORY_CERTIFICATION',
        'PRIOR_PERMIT',
        'TAX_INCENTIVE_CERT',
    ];

    /**
     * The files uploaded into one checklist's own slots, keyed by document code.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function uploads(Application $application, string $codePrefix): array
    {
        return ApplicationDocument::with('documentType:id,code')
            ->where('application_id', $application->id)
            ->whereHas('documentType', fn ($q) => $q->where('code', 'like', $codePrefix.'%'))
            ->latest('id')
            ->get()
            ->groupBy(fn (ApplicationDocument $d) => (string) $d->documentType?->code)
            ->map(fn ($group) => self::describe($group->first()))
            ->all();
    }

    /**
     * The BPLO attachments that already answer a checklist row.
     *
     * `permit_type_id` null, deliberately: a checklist upload leaves that
     * column empty and is found by its type, and the copy of a certificate an
     * applicant already HOLDS is keyed on the permit type instead. Two writers
     * of one column is how those two would delete each other's files.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function carried(Application $application): array
    {
        return ApplicationDocument::with('documentType:id,code')
            ->where('application_id', $application->id)
            ->whereNull('permit_type_id')
            ->whereHas('documentType', fn ($q) => $q->whereIn('code', self::CARRYABLE))
            ->latest('id')
            ->get()
            ->groupBy(fn (ApplicationDocument $d) => (string) $d->documentType?->code)
            ->map(fn ($group) => self::describe($group->first()))
            ->all();
    }

    /** @return array<string, mixed> */
    public static function describe(ApplicationDocument $document): array
    {
        return [
            'id' => $document->id,
            'filename' => $document->original_filename,
            'size_bytes' => $document->size_bytes,
            'uploaded_at' => $document->created_at?->toIso8601String(),
        ];
    }

    public static function sheet(Application $application, string $permitTypeCode): ?ApplicationOfficeForm
    {
        return ApplicationOfficeForm::where('application_id', $application->id)
            ->whereHas('permitType', fn ($q) => $q->where('code', $permitTypeCode))
            ->first();
    }

    /** Has the applicant handed this sheet in, rather than merely saved it? */
    public static function sheetSubmitted(Application $application, string $permitTypeCode): bool
    {
        return $application->permitTypes()
            ->where('code', $permitTypeCode)
            ->wherePivotNotNull('submitted_at')
            ->exists();
    }

    /**
     * Turn a class's ROWS into the checklist the two readers share.
     *
     * `$applies` decides the branch for one row and is the only thing an office
     * has to write itself — everything below it is the same on every sheet.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): bool  $applies
     * @return list<array<string, mixed>>
     */
    public static function build(
        Application $application,
        string $permitTypeCode,
        string $codePrefix,
        array $rows,
        callable $applies,
    ): array {
        $uploaded = self::uploads($application, $codePrefix);
        $carried = self::carried($application);
        $submitted = self::sheetSubmitted($application, $permitTypeCode);

        $out = [];
        foreach ($rows as $row) {
            if (! $applies($row)) {
                continue;
            }

            $code = $codePrefix.$row['key'];
            $source = match (true) {
                ($row['carried_from'] ?? null) === 'sheet' => 'sheet',
                ($row['carried_from'] ?? null) !== null => 'carried',
                default => 'upload',
            };

            $document = match ($source) {
                'upload' => $uploaded[$code] ?? null,
                'carried' => $carried[$row['carried_from']] ?? null,
                default => null,
            };

            $out[] = [
                'key' => $row['key'],
                'code' => $source === 'upload' ? $code : null,
                'label' => $row['label'],
                'note' => $row['note'],
                'source' => $source,
                'satisfied' => $source === 'sheet' ? $submitted : $document !== null,
                /*
                 * Whether an unsatisfied row stops the sheet being handed in.
                 * Emitted per row rather than kept as a list of keys in the
                 * browser, for the reason this whole layer exists: the
                 * applicant's sheet and the officer's review screen must read
                 * one rule, not two.
                 */
                'blocking' => $row['blocking'] ?? false,
                'document' => $document,
            ];
        }

        return $out;
    }

    /** Does this prefix + rows set accept a file under `$code`? */
    public static function accepts(array $rows, string $codePrefix, string $code): bool
    {
        foreach ($rows as $row) {
            if (($row['carried_from'] ?? null) === null && $codePrefix.$row['key'] === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * The document type behind one slot, created on demand.
     *
     * `firstOrCreate` rather than a seeded row: a checklist is data in these
     * classes, and a row added to one should not need a migration before
     * anybody can upload into it. The name and note come from the row, so the
     * officer reading the attachment list sees that office's own wording.
     */
    public static function documentType(array $rows, string $codePrefix, string $code): DocumentType
    {
        $key = substr($code, strlen($codePrefix));
        $row = collect($rows)->firstWhere('key', $key);

        return DocumentType::firstOrCreate(
            ['code' => $code],
            [
                'name' => $row['label'] ?? $code,
                'help_text' => $row['note'] ?? null,
            ],
        );
    }
}
