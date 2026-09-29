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
     * EVERY file under each code, newest first.
     *
     * `uploads()` above answers "the one file for this slot", which was
     * the whole model until 30 September 2026: the office upload endpoint
     * deleted the previous file on every press. The business permit form
     * has always taken many per requirement, and the client asked for
     * these to match it.
     *
     * Kept separate rather than changing `uploads()`, which four
     * producers call expecting one row each.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function uploadsAll(Application $application, string $codePrefix): array
    {
        return ApplicationDocument::with('documentType:id,code')
            ->where('application_id', $application->id)
            ->whereHas('documentType', fn ($q) => $q->where('code', 'like', $codePrefix.'%'))
            ->latest('id')
            ->get()
            ->groupBy(fn (ApplicationDocument $d) => (string) $d->documentType?->code)
            ->map(fn ($group) => $group->map(fn ($d) => self::describe($d))->values()->all())
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

    /**
     * EVERY business-permit attachment under each carryable code.
     *
     * `carried()` above answers "the one file", and that is what a row
     * showed until 30 September 2026 — so a lease attached to the
     * business permit as two pages appeared on an office checklist as
     * one, with the second page nowhere on the screen and no way to tell
     * it was there. The business permit form has always taken many per
     * requirement; this is the checklist catching up with it.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function carriedAll(Application $application): array
    {
        return ApplicationDocument::with('documentType:id,code')
            ->where('application_id', $application->id)
            ->whereNull('permit_type_id')
            ->whereHas('documentType', fn ($q) => $q->whereIn('code', self::CARRYABLE))
            ->latest('id')
            ->get()
            ->groupBy(fn (ApplicationDocument $d) => (string) $d->documentType?->code)
            ->map(fn ($group) => $group->map(fn ($d) => self::describe($d))->values()->all())
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
        $everyUpload = self::uploadsAll($application, $codePrefix);
        $carried = self::carried($application);
        $carriedAll = self::carriedAll($application);
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
                /*
                 * The slot this row uploads into. Null only on a `sheet`
                 * row, which is the form itself.
                 *
                 * A carried row had none until 30 September 2026 — the
                 * business permit answered it, so asking again was asking
                 * twice. What that missed is the applicant with a
                 * two-page lease and one page attached, and, once every
                 * documentary row began blocking the submit, the
                 * applicant with an empty carried row and no way on earth
                 * to fill it: `DocumentController::store` allows Draft and
                 * Returned, and this stage is reached only once the filing
                 * is paid.
                 */
                'code' => $source === 'sheet' ? null : $code,
                'label' => $row['label'],
                'note' => $row['note'],
                'source' => $source,
                /*
                 * The business-permit attachment that answers this row,
                 * when one does. `code` above is null on a carried row —
                 * there is no slot to upload into — so without this the
                 * payload said which document is missing only in the
                 * row's prose, which is not something a caller can act
                 * on. Null on the rows where the question does not
                 * arise: an upload row IS its own source, and a sheet
                 * row is this form.
                 */
                'carried_from' => $source === 'carried' ? $row['carried_from'] : null,
                /*
                 * A carried row is answered by the business permit's copy OR
                 * by one added here — the second half is what keeps a
                 * blocking carried row from being a dead end.
                 */
                'satisfied' => match ($source) {
                    'sheet' => $submitted,
                    'carried' => $document !== null || ($everyUpload[$code] ?? []) !== [],
                    default => $document !== null,
                },
                /*
                 * Whether an unsatisfied row stops the sheet being handed
                 * in. Everything the office asks for does.
                 *
                 * This defaulted to FALSE until 30 September 2026, with
                 * the declaration the one exception, on the reading that
                 * the paper is a counter checklist a clerk ticks on
                 * receipt rather than a gate. The client reversed it from
                 * the Locational Clearance screen — *"Are the documentary
                 * fields here not required? Make sure they are required"*
                 * — and the reversal is the LGU's own logic: the office
                 * cannot act on a filing missing the documents its
                 * decision rests on, so accepting one only buys the
                 * applicant a return trip and a second wait.
                 *
                 * Not a flat `true`. A `sheet` row IS this form, and its
                 * `satisfied` above is $submitted — false at the moment of
                 * submitting. Blocking on it would mean the sheet could
                 * never be handed in, because what it waits for is the act
                 * it is refusing. A row may still opt out by saying so.
                 *
                 * Emitted per row rather than kept as a list of keys in
                 * the browser, for the reason this layer exists: the
                 * applicant's sheet, the server's refusal and the
                 * officer's review screen read one rule, not three.
                 */
                'blocking' => $row['blocking'] ?? (
                    $source !== 'sheet' && ! self::statedAsConditional($row['label'])
                ),
                'document' => $document,
                /*
                 * All of them. An upload slot takes as many files as the
                 * applicant sends, matching the business permit form; a
                 * carried row is one file by nature and a sheet row is
                 * none, so both are expressed as the list they are.
                 */
                /*
                 * Every file answering this row. A carried row lists what
                 * the business permit holds FIRST — that is the copy the
                 * office already has — then anything added here.
                 */
                'documents' => match ($source) {
                    'upload' => $everyUpload[$code] ?? [],
                    'carried' => array_merge(
                        $carriedAll[$row['carried_from']] ?? [],
                        $everyUpload[$code] ?? [],
                    ),
                    default => [],
                },
                /*
                 * Which of those belong to the business permit. Remove on
                 * this sheet means "I attached the wrong page to this
                 * checklist", never "take it off my business permit", so
                 * the screen offers it on nothing in this list.
                 */
                'carried_document_ids' => $source === 'carried'
                    ? array_column($carriedAll[$row['carried_from']] ?? [], 'id')
                    : [],
            ];
        }

        return $out;
    }

    /**
     * Does the paper's own wording make this row conditional?
     *
     * FSIC's list carries "(if necessary)" and "(if required)"; OBO's carries
     * "(if the building differs from the approved plan)". Those are the LGU
     * saying the row may not apply, and refusing a submit over one would be a
     * rule BizTrack invented — an unclearable one, because an applicant it
     * does not apply to has nothing to attach.
     *
     * Matched on the label because the label IS the paper's wording, which
     * keeps the asterisk on screen, the gate and the paper in agreement
     * without anybody having to remember to flag a new row.
     */
    public static function statedAsConditional(string $label): bool
    {
        return str_contains($label, '(if ');
    }

    /** Does this prefix + rows set accept a file under `$code`? */
    public static function accepts(array $rows, string $codePrefix, string $code): bool
    {
        foreach ($rows as $row) {
            /*
             * A `sheet` row is the form itself and takes nothing. Every
             * other row has a slot, INCLUDING a carried one — see the
             * note on `carried_document_ids` in `build()`.
             */
            if (($row['carried_from'] ?? null) !== 'sheet' && $codePrefix.$row['key'] === $code) {
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
