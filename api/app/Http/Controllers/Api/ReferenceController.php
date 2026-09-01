<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\PermitType;
use App\Models\PsicCode;
use Illuminate\Http\JsonResponse;

/**
 * Read-only lookups that power the application wizard. Auth required, no gate.
 */
class ReferenceController extends Controller
{
    /**
     * The 21 barangays, each with its official CPDO zoning sheet and the
     * classifications that sheet shows.
     *
     * The zoning payload rides along here rather than on an endpoint of its own
     * because the wizard already loads this list at mount and the applicant picks
     * a barangay from it — a second round trip would buy nothing. It is ~7 KB for
     * all 21.
     *
     * `zoning_classifications` is what the barangay's map DRAWS, not what any
     * address IS. The sheets are rasters and hold no geometry, so no per-location
     * answer exists to send and none is sent; CPDO determines the classification
     * for a specific site during processing. Any consumer that starts presenting
     * this as a verdict is reading it wrong.
     */
    public function barangays(): JsonResponse
    {
        $barangays = Barangay::with('zoningClassifications')->orderBy('name')->get();

        return response()->json([
            'data' => $barangays->map(fn (Barangay $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'zoning_map_path' => $b->zoning_map_path,
                'zoning_classifications' => $b->zoningClassifications->map(fn ($z) => [
                    'code' => $z->code,
                    'name' => $z->name,
                    'legend_color' => $z->legend_color,
                ])->values(),
            ]),
        ]);
    }

    public function psicCodes(): JsonResponse
    {
        return response()->json([
            'data' => PsicCode::orderBy('code')->get(['id', 'code', 'title']),
        ]);
    }

    public function departments(): JsonResponse
    {
        return response()->json([
            'data' => Department::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function documentTypes(): JsonResponse
    {
        return response()->json([
            'data' => DocumentType::orderBy('name')->get(['id', 'code', 'name', 'help_text']),
        ]);
    }

    public function permitTypes(): JsonResponse
    {
        $types = PermitType::with(['department:id,code,name', 'documentTypes'])
            ->orderBy('name')
            ->get();

        $data = $types->map(fn (PermitType $pt) => [
            'id' => $pt->id,
            'code' => $pt->code,
            'name' => $pt->name,
            'permit_number_prefix' => $pt->permit_number_prefix,
            'validity_days' => $pt->validity_days,
            'description' => $pt->description,
            'department' => $pt->department ? [
                'id' => $pt->department->id,
                'code' => $pt->department->code,
                'name' => $pt->department->name,
            ] : null,
            'requires_inspection' => (bool) $pt->requires_inspection,
            'base_fee' => $pt->base_fee,
            'per_line_surcharge' => $pt->per_line_surcharge,
            'document_types' => $pt->documentTypes->map(fn ($dt) => [
                'id' => $dt->id,
                'code' => $dt->code,
                'name' => $dt->name,
                'help_text' => $dt->help_text,
                'context' => $dt->pivot->context ?? 'all',
                'is_mandatory' => (bool) ($dt->pivot->is_mandatory ?? false),
                // Legacy alias the web already reads.
                'is_required' => (bool) ($dt->pivot->is_mandatory ?? false),
                'notes' => $dt->pivot->notes ?? null,
            ])->values(),
        ]);

        return response()->json(['data' => $data]);
    }
}
