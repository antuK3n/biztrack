<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApplicationAssignment;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Officer-in-Charge register: every office's caseload, in one list.
 *
 * ── Why this is not the officer queue with the department filter removed ────
 *
 * `AssignmentController::index` answers an officer's question — what is on MY
 * desk — and its payload is shaped for review: office forms, clearance state,
 * compliance checks, the remarks another office may not read. The super admin
 * is asking a different question, the client's §6: who holds what, since when,
 * and does that still make sense. Six columns, no review data.
 *
 * Serving that from the review endpoint would mean either handing the admin a
 * reviewer's payload for every filing in the register — a great deal of other
 * offices' working notes, for a screen that prints none of it — or teaching
 * that endpoint a second output shape. This is the smaller thing.
 *
 * ── Who may read it ─────────────────────────────────────────────────────────
 *
 * `oic.assign`, which is the super admin's alone today and is the same
 * permission guarding the act this screen exists to perform. An office reader
 * must not have it: the register lists every office's holder, and an office
 * knowing who in CENRO is handling what is precisely the cross-office read the
 * boundary refuses everywhere else.
 */
class OicAssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'department_id' => ['sometimes', 'integer', 'exists:departments,id'],
            /*
             * `unassigned` is the useful one and the reason this is not just a
             * sort: a filing nobody has taken is the thing the super admin is
             * looking for, and it is invisible in a list ordered by officer.
             */
            'holder' => ['sometimes', 'in:assigned,unassigned'],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = ApplicationAssignment::query()
            ->with([
                'department:id,code,name',
                'officer:id,name,email,department_id',
                'application:id,tracking_id,business_id,application_type,status',
                'application.business:id,name',
            ])
            // A filing removed from the register takes its assignments with it;
            // without this the list carries rows whose business column is blank
            // and whose Reassign button acts on nothing.
            ->whereHas('application');

        if (isset($data['department_id'])) {
            $query->where('department_id', $data['department_id']);
        }

        if (($data['holder'] ?? null) === 'assigned') {
            $query->whereNotNull('officer_user_id');
        } elseif (($data['holder'] ?? null) === 'unassigned') {
            $query->whereNull('officer_user_id');
        }

        if ($needle = trim($data['q'] ?? '')) {
            $query->whereHas('application', function ($a) use ($needle) {
                $a->where('tracking_id', 'like', "%{$needle}%")
                    ->orWhereHas('business', fn ($b) => $b->where('name', 'like', "%{$needle}%"));
            });
        }

        $rows = $query
            /*
             * Unheld first, then the longest-held.
             *
             * The screen exists to find work that has stalled, and both ends of
             * that are at the top of this order: a filing nobody took, and one
             * somebody took and has been sitting on. Newest-first would bury
             * both under whatever happened this morning.
             */
            ->orderByRaw('CASE WHEN officer_user_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('assigned_at')
            ->orderBy('id')
            ->paginate($data['per_page'] ?? 50);

        return response()->json([
            'data' => collect($rows->items())->map(fn (ApplicationAssignment $a) => $this->row($a))->all(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                // The filter's own options, off the register rather than retyped
                // in the browser — an office added later appears without a
                // frontend change, and one retired stops being offered.
                'departments' => Department::orderBy('name')
                    ->get(['id', 'code', 'name'])
                    ->map(fn ($d) => ['id' => $d->id, 'code' => $d->code, 'name' => $d->name])
                    ->all(),
            ],
        ]);
    }

    /**
     * Who this assignment may be moved to.
     *
     * The office's own active officers, because `AssignmentController::assign`
     * refuses anybody else with a 422 — offering a name the confirm step then
     * rejects is a worse screen than offering none.
     *
     * The current holder is included rather than filtered out. Leaving them in
     * the list is what lets the dialog show a selected value, and choosing them
     * again is a no-op the endpoint already treats as one.
     */
    public function candidates(ApplicationAssignment $assignment): JsonResponse
    {
        $officers = User::where('department_id', $assignment->department_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department_id']);

        return response()->json([
            'data' => $officers->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'department_id' => $u->department_id,
                'is_current' => $u->id === $assignment->officer_user_id,
            ])->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(ApplicationAssignment $assignment): array
    {
        $application = $assignment->application;

        return [
            'id' => $assignment->id,
            'application_id' => $application?->id,
            'tracking_id' => $application?->tracking_id,
            'application_type' => $application?->application_type,
            'business' => $application?->business ? [
                'id' => $application->business->id,
                'name' => $application->business->name,
            ] : null,
            'office' => $assignment->department ? [
                'id' => $assignment->department->id,
                'code' => $assignment->department->code,
                'name' => $assignment->department->name,
            ] : null,
            /*
             * Null means nobody has taken it — and on this screen that is not a
             * withheld value but the answer the reader came for. The officer
             * queue has to distinguish "not assigned" from "not yours to know";
             * the super admin reads every office, so there is nothing to
             * withhold and no ambiguity to resolve.
             */
            'officer' => $assignment->officer ? [
                'id' => $assignment->officer->id,
                'name' => $assignment->officer->name,
                'email' => $assignment->officer->email,
            ] : null,
            'assigned_at' => optional($assignment->assigned_at)->toISOString(),
            'completed_at' => optional($assignment->completed_at)->toISOString(),
            'status' => $assignment->status?->value,
            'status_label' => $assignment->status?->label(),
            'application_status' => $application?->status?->value,
            'application_status_label' => $application?->status?->label(),
        ];
    }
}
