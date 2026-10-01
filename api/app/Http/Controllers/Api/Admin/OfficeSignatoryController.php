<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\OfficeSignatory;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Admin management of the names printed in office form signature blocks
 * (permission reference.manage, which the super admin alone holds — see the
 * routes in workflow.php).
 *
 * These are real public officials, and what is set here is what gets printed on
 * a document a business owner then relies on: the permit certificate prints an
 * office's current signatories in order (PermitController::certificateData),
 * and the office's reports print the last of them as "Noted by"
 * (ReportController::notedBy). Every write is audited: if a name on an issued
 * form is later questioned, the answer to "who put it there and when" has to
 * exist.
 *
 * This controller sat unrouted from the day it was written (#51 added only an
 * unused `use` line to workflow.php, which f85d818 tidied away) until the
 * Office Signatories screen gave it routes.
 */
class OfficeSignatoryController extends Controller
{
    /** The order a signature block can hold. Two digits is more posts than any form has. */
    private const MAX_ORDER = 99;

    /**
     * Every office with its signatories, including offices that have none, and
     * including retired signatories.
     *
     * Empty offices are listed rather than filtered out because the blank is the
     * actionable state — an admin needs to see that BPLO has nobody assigned to
     * find out that its forms will print without a name. Retired rows are sent
     * too, flagged, because they are the record of who signed before.
     */
    public function index(): JsonResponse
    {
        $departments = Department::with(['signatories' => fn ($q) => $q->orderBy('sort_order')->orderBy('role')])
            ->orderBy('code')
            ->get()
            ->map(fn (Department $d) => [
                'id' => $d->id,
                'code' => $d->code,
                'name' => $d->name,
                'signatories' => $d->signatories->map(fn (OfficeSignatory $s) => $this->present($s))->values(),
            ]);

        return response()->json(['data' => $departments]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $data['is_active'] = $request->boolean('is_active', true);
        /*
         * No order given: the end of the block. That makes a newcomer the last
         * name, which is the one printed as "Noted by" — and a default that
         * clashed with somebody already at 0 would refuse the very first save.
         */
        $data['sort_order'] ??= $this->nextOrder((int) $data['department_id']);

        $signatory = OfficeSignatory::create($data);

        Audit::log('office_signatory.created', $signatory, [
            'department_id' => $signatory->department_id,
            'role' => $signatory->role,
            'name' => $signatory->name,
            'sort_order' => $signatory->sort_order,
            'is_active' => $signatory->is_active,
        ]);

        return response()->json(['data' => $this->present($signatory->fresh())], 201);
    }

    public function update(Request $request, OfficeSignatory $officeSignatory): JsonResponse
    {
        $data = $this->validated($request, $officeSignatory);

        // Captured before the write: the audit trail is only useful if it says
        // what the name was replaced with, not just what it ended up as.
        $before = $this->auditable($officeSignatory);
        $wasActive = $officeSignatory->is_active;
        $snapshot = Audit::snapshot($officeSignatory);

        $officeSignatory->update($data);

        /*
         * Unticking "signs now" in the edit dialog takes the name off every
         * new form exactly as Retire does, so it keeps the same copy of the
         * row as it stood. Any other edit is a plain change.
         */
        Audit::log(
            'office_signatory.updated',
            $officeSignatory,
            ['from' => $before, 'to' => $this->auditable($officeSignatory)],
            $wasActive && ! $officeSignatory->is_active ? $snapshot : null,
        );

        return response()->json(['data' => $this->present($officeSignatory->fresh())]);
    }

    /**
     * Retire a signatory.
     *
     * Deactivates rather than deletes. A permit issued last year still carries
     * this name, and the row is the only record of who that was — removing it
     * would leave an issued document nobody can account for. Retiring is still
     * a removal from use, so it goes through Audit::removed and keeps a copy of
     * the row as it stood (Audit Log 1).
     *
     * Retiring twice is a no-op rather than an error: the outcome asked for is
     * already true, and a second audit row would claim a second retirement.
     */
    public function retire(OfficeSignatory $officeSignatory): JsonResponse
    {
        if ($officeSignatory->is_active) {
            Audit::removed('office_signatory.retired', $officeSignatory, [
                'department_id' => $officeSignatory->department_id,
                'role' => $officeSignatory->role,
                'name' => $officeSignatory->name,
            ]);

            $officeSignatory->update(['is_active' => false]);
        }

        return response()->json(['data' => $this->present($officeSignatory->fresh())]);
    }

    /**
     * Validate a create (no `$existing`) or an edit.
     *
     * An edit cannot move a signatory to another office. A person who changes
     * office is a retirement in one and an appointment in the other, and doing
     * it as two acts keeps each office's record whole.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?OfficeSignatory $existing = null): array
    {
        $creating = $existing === null;
        // An edit may send only what changed; a create must send both.
        $edit = $creating ? [] : ['sometimes'];

        $rules = [
            'role' => [...$edit, 'required', 'string', 'max:120'],
            'name' => [...$edit, 'required', 'string', 'max:160'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:'.self::MAX_ORDER],
            'is_active' => ['sometimes', 'boolean'],
        ];
        if ($creating) {
            $rules['department_id'] = ['required', 'integer', 'exists:departments,id'];
        }

        $validator = Validator::make($request->all(), $rules, [
            'department_id.required' => 'Choose the office this person signs for.',
            'department_id.integer' => 'That office is not in the register.',
            'department_id.exists' => 'That office is not in the register.',
            'role.required' => 'Enter the position as it is printed under the name.',
            'role.string' => 'Enter the position as it is printed under the name.',
            'role.max' => 'Keep the position to 120 characters or fewer.',
            'name.required' => 'Enter the name as it should be printed.',
            'name.string' => 'Enter the name as it should be printed.',
            'name.max' => 'Keep the name to 160 characters or fewer.',
            'sort_order.required' => 'Enter the order as a whole number from 0 to '.self::MAX_ORDER.'.',
            'sort_order.integer' => 'Enter the order as a whole number from 0 to '.self::MAX_ORDER.'.',
            'sort_order.min' => 'Enter the order as a whole number from 0 to '.self::MAX_ORDER.'.',
            'sort_order.max' => 'Enter the order as a whole number from 0 to '.self::MAX_ORDER.'.',
            'is_active.boolean' => 'Say whether this person signs now: yes or no.',
        ]);

        $validator->after(fn (ValidatorInstance $v) => $this->checkAgainstCurrent($v, $request, $existing));

        return $validator->validate();
    }

    /**
     * The two rules that are about the office's other CURRENT signatories, so
     * they can only be read off the row as it will stand after this save.
     *
     *  - One current holder per post. The partial unique index enforces this
     *    too; checking here turns a 500 on the constraint into a sentence. It
     *    compares without case, because "Chief-CENRO" and "chief-cenro" are the
     *    same post to a reader even where the index would let both in.
     *
     *  - No two current signatories at the same order. The report's "Noted by"
     *    is the highest order (ReportController::notedBy), so a tie at the top
     *    would print whichever row the database happened to return first.
     *
     * Retired rows are exempt from both: they print nowhere, and a retired
     * holder sharing a post with their successor is the normal case.
     */
    private function checkAgainstCurrent(ValidatorInstance $v, Request $request, ?OfficeSignatory $existing): void
    {
        if ($v->errors()->isNotEmpty()) {
            return;
        }

        $active = $request->has('is_active') ? $request->boolean('is_active') : ($existing->is_active ?? true);
        if (! $active) {
            return;
        }

        $departmentId = $existing->department_id ?? (int) $request->input('department_id');
        $role = trim((string) ($request->input('role') ?? $existing?->role));
        // A create with no order takes the next free one, exactly as store()
        // will, so that default is held to the same rule as a typed number.
        $order = $request->has('sort_order')
            ? (int) $request->input('sort_order')
            : ($existing->sort_order ?? $this->nextOrder($departmentId));

        $others = OfficeSignatory::query()
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
            ->get();
        $office = Department::whereKey($departmentId)->value('code') ?? 'This office';

        $holder = $others->first(fn (OfficeSignatory $s) => mb_strtolower($s->role) === mb_strtolower($role));
        if ($holder) {
            $v->errors()->add('role', "{$office} already has a current {$holder->role}: {$holder->name}. Retire them first, or edit their entry instead.");
        }

        $clash = $others->firstWhere('sort_order', $order);
        if ($clash) {
            $v->errors()->add('sort_order', "{$clash->name} is already at {$order} in {$office}. Give each current signatory a different number.");
        }
    }

    /** One past the office's highest current order, or 0 for an office with nobody. */
    private function nextOrder(int $departmentId): int
    {
        $highest = OfficeSignatory::query()
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->max('sort_order');

        return $highest === null ? 0 : min((int) $highest + 1, self::MAX_ORDER);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(OfficeSignatory $s): array
    {
        return [
            'role' => $s->role,
            'name' => $s->name,
            'sort_order' => $s->sort_order,
            'is_active' => $s->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OfficeSignatory $s): array
    {
        return [
            'id' => $s->id,
            'department_id' => $s->department_id,
            'role' => $s->role,
            'name' => $s->name,
            'sort_order' => $s->sort_order,
            'is_active' => $s->is_active,
        ];
    }
}
