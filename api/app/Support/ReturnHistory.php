<?php

namespace App\Support;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationPermitType;
use App\Models\ApplicationReturn;
use App\Models\DocumentType;
use App\Models\PermitType;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The return history: every "please fix this", kept after it is answered.
 *
 * Client, 19 October 2026: *"When a returned application was complied, the
 * remark should not be removed."* The live columns (`remarks`,
 * `remarks_target`, `application_return_notes`) are ONE open round and are
 * cleared when the owner answers — correctly, since every screen reads them as
 * "what to fix now". This writes the same round into `application_returns` and
 * keeps it, so both sides can still see what was asked and what changed.
 *
 * Additive by design: nothing here clears or reads back into a live column,
 * and a failure to capture a value records null rather than stopping a return.
 *
 * Called from WorkflowService:
 *  - `open*()` from `returnMainForm`, `returnClearance`, `rejectClearance`;
 *  - `amend()` from `amendMainFormReturn`, `amendClearanceReturn`;
 *  - `comply()` from the two status writers, on Returned/Rejected → For Approval;
 *  - `supersedeAll()` from `transition()`, on the filing being rejected or cancelled.
 */
final class ReturnHistory
{
    /**
     * BPLO returned the business permit's main form.
     *
     * @param  array<string, string>  $notes
     */
    public static function openMainForm(Application $app, ?string $remarks, ?string $target, array $notes, ?int $bploDepartmentId): ApplicationReturn
    {
        self::supersedeOpen($app->id, null);

        return ApplicationReturn::create([
            'application_id' => $app->id,
            'permit_type_id' => null,
            'department_id' => $bploDepartmentId,
            'kind' => 'return',
            ...self::returner(),
            'remarks' => $remarks,
            'items' => self::capture(self::fresh($app), null, self::codes($target, $notes), $notes),
            'returned_at' => now(),
            'status' => ApplicationReturn::OPEN,
        ]);
    }

    /**
     * An office (or BPLO, at Final Approval) returned or refused one clearance.
     *
     * @param  'return'|'refusal'  $kind
     * @param  array<string, string>  $notes
     */
    public static function openClearance(ApplicationPermitType $row, ?string $remarks, ?string $target, array $notes, string $kind = 'return'): ApplicationReturn
    {
        self::supersedeOpen($row->application_id, $row->permit_type_id);

        return ApplicationReturn::create([
            'application_id' => $row->application_id,
            'permit_type_id' => $row->permit_type_id,
            'department_id' => $row->permitType?->issuing_department_id,
            'kind' => $kind,
            ...self::returner(),
            'remarks' => $remarks,
            'items' => self::capture(self::fresh($row->application), $row, self::codes($target, $notes), $notes),
            'returned_at' => now(),
            'status' => ApplicationReturn::OPEN,
        ]);
    }

    /**
     * The officer changed what an open return asks for.
     *
     * Rewritten in place, like the live columns — it is the same round. A
     * target already on the row keeps the value captured at the ORIGINAL
     * return: the owner may have started fixing it since, and recapturing now
     * would report their own edit as the "before".
     *
     * @param  array<string, string>  $notes
     */
    public static function amend(Application $app, ?ApplicationPermitType $row, ?string $remarks, ?string $target, array $notes): void
    {
        $open = self::openRow($app->id, $row?->permit_type_id);
        $codes = self::codes($target, $notes);
        $fresh = self::fresh($app);

        if ($open === null) {
            /* A return made before this table existed and never backfilled. */
            ApplicationReturn::create([
                'application_id' => $app->id,
                'permit_type_id' => $row?->permit_type_id,
                'department_id' => $row?->permitType?->issuing_department_id ?? $app->assignments()
                    ->where('status', 'returned')->value('department_id'),
                'kind' => $row?->status?->value === 'rejected' ? 'refusal' : 'return',
                ...self::returner(),
                'remarks' => $remarks,
                'items' => self::capture($fresh, $row, $codes, $notes),
                'returned_at' => now(),
                'status' => ApplicationReturn::OPEN,
            ]);

            return;
        }

        $before = collect($open->items ?? [])->keyBy('code');
        $captured = self::capture($fresh, $row, array_values(array_filter(
            $codes,
            fn ($c) => ! $before->has($c),
        )), $notes);
        $capturedByCode = collect($captured)->keyBy('code');

        $items = array_map(function (string $code) use ($before, $capturedByCode, $notes) {
            $item = $before->get($code) ?? $capturedByCode->get($code);
            $item['note'] = isset($notes[$code]) && trim((string) $notes[$code]) !== '' ? trim((string) $notes[$code]) : null;

            return $item;
        }, $codes);

        $open->update([
            'remarks' => $remarks,
            'items' => $items,
            'amended_at' => now(),
        ]);
    }

    /**
     * The owner answered: record what each named target says now beside what
     * it said when it was returned.
     *
     * `$permitTypeId` null is the main form.
     */
    public static function comply(Application $app, ?int $permitTypeId): void
    {
        $open = self::openRow($app->id, $permitTypeId);
        if ($open === null) {
            return;
        }

        $fresh = self::fresh($app);
        $row = $permitTypeId === null
            ? null
            : ApplicationPermitType::with('permitType')
                ->where('application_id', $app->id)
                ->where('permit_type_id', $permitTypeId)
                ->first();

        $items = $open->items ?? [];
        $now = collect(self::capture($fresh, $row, array_column($items, 'code'), []))->keyBy('code');

        $changes = array_map(function (array $was) use ($now) {
            $is = $now->get($was['code']) ?? [];
            $oldIds = $was['document_ids'] ?? null;
            $newIds = $is['document_ids'] ?? null;
            $replaced = [];
            if (is_array($newIds)) {
                $fresh = array_values(array_diff($newIds, is_array($oldIds) ? $oldIds : []));
                $replaced = $fresh === [] ? [] : ApplicationDocument::whereIn('id', $fresh)
                    ->orderBy('id')
                    ->get(['id', 'original_filename'])
                    ->map(fn ($d) => ['id' => $d->id, 'filename' => $d->original_filename])
                    ->all();
            }
            $comparable = ($was['comparable'] ?? false) && ($is['comparable'] ?? false);

            return [
                'code' => $was['code'],
                'label' => $was['label'] ?? null,
                'kind' => $was['kind'] ?? 'other',
                'old' => $was['value'] ?? null,
                'new' => $is['value'] ?? null,
                'changed' => $comparable && (
                    ($was['value'] ?? null) !== ($is['value'] ?? null) || $replaced !== []
                ),
                'replaced' => $replaced,
                'comparable' => $comparable,
            ];
        }, $items);

        $open->update([
            'status' => ApplicationReturn::COMPLIED,
            'complied_at' => now(),
            'complied_by_user_id' => Auth::id(),
            'changes' => $changes,
        ]);
    }

    /** The filing ended while something was still being asked of it. */
    public static function supersedeAll(Application $app): void
    {
        ApplicationReturn::where('application_id', $app->id)
            ->where('status', ApplicationReturn::OPEN)
            ->update(['status' => ApplicationReturn::SUPERSEDED, 'closed_at' => now()]);
    }

    /**
     * The history as one reader may see it, newest first.
     *
     * Scoped by the same predicate as the live `remarks` columns
     * (`ApplicationVisibility::readsOfficeSheet` on the office whose sheet it
     * is), so an office reads its own returns and nobody else's; BPLO, the
     * super admin and the owner read every one. Officer names are for staff
     * only — the applicant's screens name offices, never clerks.
     *
     * @return list<array<string, mixed>>
     */
    public static function forReader(Application $app, ?User $user): array
    {
        $staff = $user !== null && (
            ApplicationVisibility::readsEveryOffice($user)
            || $user->hasPermission(ApplicationVisibility::VIEW_ALL)
        );

        return ApplicationReturn::with([
            'permitType:id,code,name',
            'department:id,code,name',
            'returnedByDepartment:id,code,name',
            'returnedBy:id,name',
            'compliedBy:id,name',
        ])
            ->where('application_id', $app->id)
            ->orderByDesc('returned_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (ApplicationReturn $r) => ApplicationVisibility::readsOfficeSheet($user, $r->department_id))
            ->map(fn (ApplicationReturn $r) => [
                'id' => $r->id,
                'scope' => $r->permit_type_id === null ? 'main_form' : 'permit',
                'permit_type' => $r->permitType ? [
                    'id' => $r->permitType->id,
                    'code' => $r->permitType->code,
                    'name' => $r->permitType->name,
                ] : null,
                'office' => $r->department ? [
                    'code' => $r->department->code,
                    'name' => $r->department->name,
                ] : null,
                /* Only when someone other than the sheet's own office pressed Return. */
                'returned_by_office' => $r->returnedByDepartment
                    && $r->returned_by_department_id !== $r->department_id
                    ? ['code' => $r->returnedByDepartment->code, 'name' => $r->returnedByDepartment->name]
                    : null,
                'kind' => $r->kind,
                'status' => $r->status,
                'remarks' => $r->remarks,
                'returned_at' => $r->returned_at?->toISOString(),
                'amended_at' => $r->amended_at?->toISOString(),
                'complied_at' => $r->complied_at?->toISOString(),
                'closed_at' => $r->closed_at?->toISOString(),
                'returned_by' => $staff && $r->returnedBy ? ['id' => $r->returnedBy->id, 'name' => $r->returnedBy->name] : null,
                'complied_by' => $staff && $r->compliedBy ? ['id' => $r->compliedBy->id, 'name' => $r->compliedBy->name] : null,
                'items' => array_map(fn ($i) => [
                    'code' => $i['code'],
                    'label' => $i['label'] ?? null,
                    'kind' => $i['kind'] ?? 'other',
                    'note' => $i['note'] ?? null,
                    'value' => $i['value'] ?? null,
                ], $r->items ?? []),
                'changes' => $r->changes === null ? null : array_map(fn ($c) => [
                    'code' => $c['code'],
                    'label' => $c['label'] ?? null,
                    'kind' => $c['kind'] ?? 'other',
                    'old' => $c['old'] ?? null,
                    'new' => $c['new'] ?? null,
                    'changed' => (bool) ($c['changed'] ?? false),
                    'comparable' => (bool) ($c['comparable'] ?? false),
                    'replaced' => array_values(array_map(
                        fn ($d) => ['id' => $d['id'] ?? null, 'filename' => $d['filename'] ?? null],
                        $c['replaced'] ?? [],
                    )),
                ], $r->changes),
                'source' => $r->source,
            ])
            ->values()
            ->all();
    }

    // ── internals ───────────────────────────────────────────────────────────

    private static function openRow(int $appId, ?int $permitTypeId): ?ApplicationReturn
    {
        return ApplicationReturn::where('application_id', $appId)
            ->where('status', ApplicationReturn::OPEN)
            ->when(
                $permitTypeId === null,
                fn ($q) => $q->whereNull('permit_type_id'),
                fn ($q) => $q->where('permit_type_id', $permitTypeId),
            )
            ->latest('id')
            ->first();
    }

    /** A second return on the same sheet before the first was answered replaces it. */
    private static function supersedeOpen(int $appId, ?int $permitTypeId): void
    {
        ApplicationReturn::where('application_id', $appId)
            ->where('status', ApplicationReturn::OPEN)
            ->when(
                $permitTypeId === null,
                fn ($q) => $q->whereNull('permit_type_id'),
                fn ($q) => $q->where('permit_type_id', $permitTypeId),
            )
            ->update(['status' => ApplicationReturn::SUPERSEDED, 'closed_at' => now()]);
    }

    /** @return array{returned_by_user_id: int|null, returned_by_department_id: int|null} */
    private static function returner(): array
    {
        $user = Auth::user();

        return [
            'returned_by_user_id' => $user?->getKey(),
            'returned_by_department_id' => $user?->department_id,
        ];
    }

    /**
     * The pointer's codes, then any code that only carries a note, in order.
     *
     * @param  array<string, string>  $notes
     * @return list<string>
     */
    private static function codes(?string $target, array $notes): array
    {
        $codes = ReturnTargets::parse($target);
        foreach (array_keys($notes) as $code) {
            if (! in_array($code, $codes, true)) {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }

    /** Read from the database, not from whatever relations the caller had loaded. */
    private static function fresh(Application $app): Application
    {
        return Application::with([
            'business.address.barangay',
            'business.owners',
            'business.lines',
        ])->findOrFail($app->id);
    }

    /**
     * What each code names and says right now.
     *
     * - `form:` codes are the main form's fields; their labels live in
     *   web/src/lib/returnTargets.ts (see ReturnTargets for why), so `label`
     *   is null and the screen resolves it.
     * - a `document_types.code` is an upload — its files, by name and id;
     * - a permit code is that permit's uploaded certificate (BPLO's return at
     *   Final Approval);
     * - on a clearance, a checklist row or a sheet answer, via the same
     *   readers the office's own snapshot uses.
     *
     * `comparable` false means there is no single value to set beside the new
     * one; the screen then says what was asked and nothing about a change.
     *
     * @param  list<string>  $codes
     * @param  array<string, string>  $notes
     * @return list<array<string, mixed>>
     */
    private static function capture(Application $app, ?ApplicationPermitType $row, array $codes, array $notes): array
    {
        if ($codes === []) {
            return [];
        }

        $checklist = [];
        $answers = [];
        $permitCode = $row?->permitType?->code;
        if ($permitCode !== null) {
            foreach (SheetRequirements::for($app, $permitCode) ?? [] as $item) {
                if (($item['code'] ?? null) !== null) {
                    $checklist[$item['code']] = $item;
                }
            }
            $answers = ClearanceSnapshot::capture($app, $permitCode, $codes);
        }

        $docTypes = DocumentType::whereIn('code', $codes)->get(['id', 'code', 'name'])->keyBy('code');
        $permits = PermitType::whereIn('code', $codes)->get(['id', 'code', 'name'])->keyBy('code');
        $mainForm = ReturnTargets::snapshot($app, $codes);

        $out = [];
        foreach ($codes as $code) {
            $note = isset($notes[$code]) && trim((string) $notes[$code]) !== '' ? trim((string) $notes[$code]) : null;
            $item = [
                'code' => $code,
                'label' => null,
                'kind' => 'other',
                'note' => $note,
                'value' => null,
                'document_ids' => null,
                'comparable' => false,
            ];

            if (isset($checklist[$code])) {
                $docs = $checklist[$code]['documents'] ?? [];
                if ($docs === [] && ($checklist[$code]['document'] ?? null) !== null) {
                    $docs = [$checklist[$code]['document']];
                }
                $item = [...$item, ...self::documents(collect($docs)->map(fn ($d) => [
                    'id' => $d['id'] ?? null,
                    'filename' => $d['filename'] ?? $d['name'] ?? null,
                ])->all()), 'label' => $checklist[$code]['label'] ?? null];
            } elseif ($row !== null && array_key_exists($code, $answers) && ! isset($docTypes[$code])) {
                $item = [...$item, 'kind' => 'answer', 'value' => $answers[$code], 'comparable' => true];
            } elseif (array_key_exists($code, $mainForm)) {
                $item = [...$item, 'kind' => 'field', 'value' => $mainForm[$code], 'comparable' => true];
            } elseif (isset($docTypes[$code])) {
                $docs = ApplicationDocument::where('application_id', $app->id)
                    ->where('document_type_id', $docTypes[$code]->id)
                    ->orderBy('id')
                    ->get(['id', 'original_filename'])
                    ->map(fn ($d) => ['id' => $d->id, 'filename' => $d->original_filename])
                    ->all();
                $item = [...$item, ...self::documents($docs), 'label' => $docTypes[$code]->name];
            } elseif (isset($permits[$code])) {
                $docs = ApplicationDocument::where('application_id', $app->id)
                    ->where('permit_type_id', $permits[$code]->id)
                    ->orderBy('id')
                    ->get(['id', 'original_filename'])
                    ->map(fn ($d) => ['id' => $d->id, 'filename' => $d->original_filename])
                    ->all();
                $item = [...$item, ...self::documents($docs), 'label' => $permits[$code]->name];
            } elseif (str_starts_with($code, 'form:')) {
                $item['kind'] = 'field';
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  list<array{id: int|null, filename: string|null}>  $docs
     * @return array{kind: string, value: string|null, document_ids: list<int>, comparable: bool}
     */
    private static function documents(array $docs): array
    {
        $names = array_values(array_filter(array_map(fn ($d) => $d['filename'] ?? null, $docs)));

        return [
            'kind' => 'document',
            'value' => $names === [] ? null : implode(', ', $names),
            'document_ids' => array_values(array_filter(array_map(fn ($d) => $d['id'] ?? null, $docs))),
            'comparable' => true,
        ];
    }
}
