<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OfficeSignatory;
use App\Support\Audit;
use App\Support\CertificateSignatories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The names an office prints on its certificate [client, 3 November 2026]:
 * read and edited by that office, from its Permits page.
 *
 * Every write is audited row by row — who changed which caption from what
 * to what — because a name on a certificate is a signature, and a signature
 * nobody can trace is the one thing a register must not hold. The change
 * reaches only permits issued afterwards (CertificateSignatories).
 */
class OfficeSignatoryController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $department = $request->user()->department;
        abort_if($department === null, 403, 'Only an office sets the names on its certificate.');

        return response()->json(['data' => [
            'office' => ['id' => $department->id, 'code' => $department->code, 'name' => $department->name],
            'certificate' => CertificateSignatories::CERTIFICATE[$department->code] ?? null,
            'rows' => CertificateSignatories::currentFor($department),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $department = $request->user()->department;
        abort_if($department === null, 403, 'Only an office sets the names on its certificate.');
        $catalogue = CertificateSignatories::CATALOGUE[$department->code] ?? [];
        abort_if($catalogue === [], 422, 'This office’s certificate names are filled by the system.');

        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.role' => ['required', 'string', 'in:'.implode(',', array_map(fn ($r) => str_replace(',', '\\,', $r), $catalogue))],
            'rows.*.name' => ['present', 'nullable', 'string', 'max:120'],
        ], [
            'rows.*.name.max' => 'Keep each name under 120 characters.',
        ]);

        $changed = 0;
        foreach ($data['rows'] as $i => $row) {
            $name = trim((string) ($row['name'] ?? '')) ?: null;
            $existing = OfficeSignatory::query()
                ->where('department_id', $department->id)
                ->where('role', $row['role'])
                ->first();
            $from = $existing?->name;
            if ($existing !== null && $existing->is_active && $from === $name) {
                continue;
            }

            $signatory = OfficeSignatory::updateOrCreate(
                ['department_id' => $department->id, 'role' => $row['role']],
                ['name' => $name, 'is_active' => true, 'sort_order' => $existing?->sort_order ?? array_search($row['role'], $catalogue, true)],
            );
            Audit::log('office_signatory.updated', $signatory, [
                'office' => $department->code,
                'role' => $row['role'],
                'from' => $from,
                'to' => $name,
            ]);
            $changed++;
        }

        return response()->json(['data' => [
            'office' => ['id' => $department->id, 'code' => $department->code, 'name' => $department->name],
            'certificate' => CertificateSignatories::CERTIFICATE[$department->code] ?? null,
            'rows' => CertificateSignatories::currentFor($department),
            'changed' => $changed,
        ]]);
    }
}
