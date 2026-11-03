<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\Inspector;
use App\Models\InspectorLeave;
use App\Models\User;
use App\Support\Audit;
use App\Support\BarangayAreas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * An office's pool of inspectors [client, 27 October 2026]: who goes out for
 * this office, which barangays each covers, which days, how many visits a
 * day, and when they are away. InspectorSuggestion reads it at booking.
 *
 * ── Whose pool ──────────────────────────────────────────────────────────────
 *
 * An office keeps its own: every route here is on `inspection.manage`, the
 * permission the five clearance offices hold for running their visits, and
 * every read and write is pinned to the caller's own department — who
 * inspects for the health office is the health office's call, and nobody
 * outside it holds the permission to look.
 */
class InspectorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $department = $this->departmentFor($request);

        $inspectors = Inspector::query()
            ->where('department_id', $department->id)
            ->with(['barangays:id,name', 'leaves' => fn ($q) => $q->orderBy('starts_on'), 'user:id,name,first_name,last_name'])
            ->withCount(['inspections as open_visits_count' => fn ($q) => $q->whereIn('status', ['scheduled', 'rescheduled', 'in_progress'])])
            ->orderByDesc('is_active')->orderBy('name')
            ->get();

        $covered = $inspectors->where('is_active', true)->flatMap(fn (Inspector $i) => $i->barangays->pluck('id'))->unique();
        $barangays = Barangay::query()->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'data' => $inspectors->map(fn (Inspector $i) => $this->row($i))->values(),
            'meta' => [
                'department' => ['id' => $department->id, 'code' => $department->code, 'name' => $department->name],
                'can_edit' => $request->user()->department_id === $department->id,
                'barangays' => $barangays->map(fn (Barangay $b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    // An active inspector covers it; the screen marks the gaps.
                    'covered' => $covered->contains($b->id),
                ])->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $department = $this->ownDepartment($request);
        $data = $this->validated($request);

        $inspector = Inspector::create($data + ['department_id' => $department->id]);
        $inspector->barangays()->sync($request->input('barangay_ids', []));
        Audit::log('inspector.added', $inspector, ['name' => $inspector->name]);

        return response()->json(['data' => $this->row($this->reload($inspector))], 201);
    }

    public function update(Request $request, Inspector $inspector): JsonResponse
    {
        $this->authorizeOwn($request, $inspector);
        $data = $this->validated($request, $inspector);

        $before = $inspector->only(['name', 'is_active', 'works_days', 'max_visits_per_day']);
        $inspector->fill($data)->save();
        if ($request->has('barangay_ids')) {
            $inspector->barangays()->sync($request->input('barangay_ids', []));
        }
        Audit::log('inspector.updated', $inspector, ['from' => $before, 'to' => $inspector->only(array_keys($before))]);

        return response()->json(['data' => $this->row($this->reload($inspector))]);
    }

    /**
     * Removing an inspector from the pool. Their past visits keep the name
     * written on them (`inspections.inspector_name`), and an open visit keeps
     * its link until the office moves it.
     */
    public function destroy(Request $request, Inspector $inspector): JsonResponse
    {
        $this->authorizeOwn($request, $inspector);
        Audit::log('inspector.removed', $inspector, ['name' => $inspector->name]);
        $inspector->delete();

        return response()->json(['data' => ['id' => $inspector->id]]);
    }

    public function storeLeave(Request $request, Inspector $inspector): JsonResponse
    {
        $this->authorizeOwn($request, $inspector);
        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:200'],
        ], [
            'ends_on.after_or_equal' => 'The leave cannot end before it starts.',
        ]);

        $inspector->leaves()->create($data);

        return response()->json(['data' => $this->row($this->reload($inspector))], 201);
    }

    public function destroyLeave(Request $request, Inspector $inspector, InspectorLeave $leave): JsonResponse
    {
        $this->authorizeOwn($request, $inspector);
        abort_unless($leave->inspector_id === $inspector->id, 404);
        $leave->delete();

        return response()->json(['data' => $this->row($this->reload($inspector))]);
    }

    /**
     * Propose how to divide the barangays among the office's active
     * inspectors — neighbours together, evenly (BarangayAreas). Nothing is
     * written until the office applies it.
     */
    public function suggestAreas(Request $request): JsonResponse
    {
        $department = $this->ownDepartment($request);
        $inspectors = Inspector::query()
            ->where('department_id', $department->id)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name']);

        abort_if($inspectors->isEmpty(), 422, 'Add an inspector first, then divide the barangays among them.');

        $areas = BarangayAreas::divide($inspectors->pluck('id')->all());
        $names = Barangay::query()->pluck('name', 'id');

        return response()->json(['data' => $inspectors->map(fn (Inspector $i) => [
            'id' => $i->id,
            'name' => $i->name,
            'barangay_ids' => $areas[$i->id] ?? [],
            'barangays' => collect($areas[$i->id] ?? [])->map(fn (int $id) => $names[$id] ?? '')->sort()->values()->all(),
        ])->values()]);
    }

    /** Apply a division: each inspector's barangays replaced by the ones given. */
    public function applyAreas(Request $request): JsonResponse
    {
        $department = $this->ownDepartment($request);
        $data = $request->validate([
            'areas' => ['required', 'array'],
            'areas.*.id' => ['required', 'integer', Rule::exists('inspectors', 'id')->where('department_id', $department->id)],
            'areas.*.barangay_ids' => ['present', 'array'],
            'areas.*.barangay_ids.*' => ['integer', 'exists:barangays,id'],
        ]);

        foreach ($data['areas'] as $area) {
            Inspector::whereKey($area['id'])->first()?->barangays()->sync($area['barangay_ids']);
        }
        Audit::log('inspector.areas_applied', null, ['department_id' => $department->id, 'inspectors' => count($data['areas'])]);

        return $this->index($request);
    }

    /**
     * The office's own staff with accounts, for linking an inspector to one.
     * The pool is the office's; so is this list.
     */
    public function accounts(Request $request): JsonResponse
    {
        $department = $this->ownDepartment($request);

        return response()->json(['data' => User::query()
            ->where('department_id', $department->id)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'first_name', 'last_name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->fullName() ?: $u->name])
            ->values()]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Inspector $editing = null): array
    {
        $department = $this->ownDepartment($request);

        // `name` is required to add; an edit may send only what changed (a
        // Deactivate sends `is_active` alone), so it is `sometimes` there.
        return $request->validate([
            'name' => [$editing ? 'sometimes' : 'required', 'string', 'max:120'],
            'mobile_number' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'works_days' => ['nullable', 'array'],
            'works_days.*' => ['integer', 'between:1,7'],
            'max_visits_per_day' => ['sometimes', 'integer', 'between:1,20'],
            'notes' => ['nullable', 'string', 'max:500'],
            'barangay_ids' => ['sometimes', 'array'],
            'barangay_ids.*' => ['integer', 'exists:barangays,id'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('department_id', $department->id)],
        ], [
            'name.required' => 'Give the inspector a name.',
            'max_visits_per_day.between' => 'Visits a day: 1 to 20.',
            'user_id.exists' => 'That account is not in your office.',
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Inspector $i): array
    {
        return [
            'id' => $i->id,
            'name' => $i->name,
            'mobile_number' => $i->mobile_number,
            'is_active' => $i->is_active,
            'works_days' => $i->worksDays(),
            'max_visits_per_day' => $i->max_visits_per_day,
            'notes' => $i->notes,
            'user' => $i->user ? ['id' => $i->user->id, 'name' => $i->user->fullName() ?: $i->user->name] : null,
            'barangays' => $i->barangays->sortBy('name')->map(fn (Barangay $b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'leaves' => $i->leaves->map(fn (InspectorLeave $l) => [
                'id' => $l->id,
                'starts_on' => $l->starts_on->toDateString(),
                'ends_on' => $l->ends_on->toDateString(),
                'reason' => $l->reason,
            ])->values(),
            'open_visits_count' => (int) ($i->open_visits_count ?? 0),
        ];
    }

    private function reload(Inspector $inspector): Inspector
    {
        return $inspector->fresh()
            ->load(['barangays:id,name', 'leaves' => fn ($q) => $q->orderBy('starts_on'), 'user:id,name,first_name,last_name'])
            ->loadCount(['inspections as open_visits_count' => fn ($q) => $q->whereIn('status', ['scheduled', 'rescheduled', 'in_progress'])]);
    }

    /**
     * The office whose pool is being read: the caller's own. `inspection.manage`
     * is the five clearance offices' alone — neither BPLO nor the super admin
     * holds it — so there is no other reader to allow for.
     */
    private function departmentFor(Request $request): Department
    {
        return $this->ownDepartment($request);
    }

    /** The office whose pool is being written: the caller's own, nobody else's. */
    private function ownDepartment(Request $request): Department
    {
        $user = $request->user();
        abort_if($user->department_id === null, 403, 'Only an office edits its own inspectors.');

        return $user->department;
    }

    private function authorizeOwn(Request $request, Inspector $inspector): void
    {
        abort_unless($inspector->department_id === $this->ownDepartment($request)->id, 403, 'This inspector is in another office’s pool.');
    }
}
