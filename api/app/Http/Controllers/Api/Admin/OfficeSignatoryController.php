<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\OfficeSignatory;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin management of the names printed in office form signature blocks
 * (permission reference.manage).
 *
 * These are real public officials, and what is set here is what gets printed on
 * a document a business owner then relies on. Every write is audited: if a name
 * on an issued form is later questioned, the answer to "who put it there and
 * when" has to exist.
 */
class OfficeSignatoryController extends Controller
{
    /**
     * Every office with its signatories, including offices that have none.
     *
     * Empty offices are listed rather than filtered out because the blank is the
     * actionable state — an admin needs to see that BPLO has nobody assigned to
     * find out that its forms will print without a name.
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
        $data = $request->validate($this->rules());

        $signatory = OfficeSignatory::create($data + ['is_active' => $request->boolean('is_active', true)]);

        Audit::log('office_signatory.created', $signatory, [
            'department_id' => $signatory->department_id,
            'role' => $signatory->role,
            'name' => $signatory->name,
        ]);

        return response()->json(['data' => $this->present($signatory)], 201);
    }

    public function update(Request $request, OfficeSignatory $officeSignatory): JsonResponse
    {
        $data = $request->validate($this->rules($officeSignatory));

        // Captured before the write: the audit trail is only useful if it says
        // what the name was replaced with, not just what it ended up as.
        $before = ['role' => $officeSignatory->role, 'name' => $officeSignatory->name];

        $officeSignatory->update($data + ['is_active' => $request->boolean('is_active', $officeSignatory->is_active)]);

        Audit::log('office_signatory.updated', $officeSignatory, [
            'from' => $before,
            'to' => ['role' => $officeSignatory->role, 'name' => $officeSignatory->name],
            'is_active' => $officeSignatory->is_active,
        ]);

        return response()->json(['data' => $this->present($officeSignatory->fresh())]);
    }

    /**
     * Retire a signatory.
     *
     * Deactivates rather than deletes. A permit issued last year still carries
     * this name, and the row is the only record of who that was — removing it
     * would leave an issued document nobody can account for.
     */
    public function destroy(OfficeSignatory $officeSignatory): JsonResponse
    {
        $officeSignatory->update(['is_active' => false]);

        Audit::log('office_signatory.retired', $officeSignatory, [
            'role' => $officeSignatory->role,
            'name' => $officeSignatory->name,
        ]);

        return response()->json(['data' => $this->present($officeSignatory->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?OfficeSignatory $existing = null): array
    {
        $departmentId = $existing?->department_id;

        return [
            'department_id' => [
                $existing ? 'sometimes' : 'required',
                'integer',
                'exists:departments,id',
            ],
            /*
             * One person per role per office, matching the table's unique key.
             * Without this the API 500s on the constraint instead of telling the
             * admin that the role is already filled.
             */
            'role' => [
                $existing ? 'sometimes' : 'required',
                'string',
                'max:120',
                Rule::unique('office_signatories', 'role')
                    ->where('department_id', request()->integer('department_id', $departmentId ?? 0))
                    ->ignore($existing?->id),
            ],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:160'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'is_active' => ['sometimes', 'boolean'],
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
