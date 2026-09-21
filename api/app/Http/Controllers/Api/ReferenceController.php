<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\PermitType;
use App\Support\AmendableFields;
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
     *
     * `zoning_overlays` is a separate key and not more entries in that list,
     * because an overlay is not one of the base classifications — it lies over
     * them (City Ordinance No. 24-2018 Art. V §4). Merging the two would let a
     * consumer print "Flood Overlay Zone" in a list of the zones drawn on the
     * sheet, which is neither what the sheet draws nor what the overlay means.
     * The same limit applies to both: designated somewhere in this barangay, not
     * a finding about a location.
     */
    public function barangays(): JsonResponse
    {
        $barangays = Barangay::with(['zoningClassifications', 'zoningOverlays'])->orderBy('name')->get();

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
                'zoning_overlays' => $b->zoningOverlays->map(fn ($o) => [
                    'code' => $o->code,
                    'name' => $o->name,
                    'description' => $o->description,
                ])->values(),
            ]),
        ]);
    }

    /**
     * Lines of business, each carrying what the Revenue Code does with it.
     *
     * `category` is the Sec. 2J.02 tax class and `category_branch` names the
     * one follow-up the Code still forces — both are here so the wizard can
     * show an applicant how their trade is classified and ask nothing for the
     * 117 codes that classify themselves.
     *
     * `permit_category` is deliberately NOT exposed. It selects a Sec. 3A.03
     * fine category and nothing on the applicant's screen needs it: the fee is
     * derived server-side in FeeCalculator::classify, which is where it has to
     * happen anyway, since the browser is not the only way in.
     */
    public function psicCodes(): JsonResponse
    {
        return response()->json([
            'data' => PsicCode::orderBy('code')->get(['id', 'code', 'title', 'category', 'category_branch']),
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

    /**
     * The details an amendment may change — the DEFINITIONS, not the values.
     *
     * ── Why this is reference data ────────────────────────────────────────
     *
     * `AmendableFields::kinds()` is a constant: the same four boxes, the same
     * labels, the same control per field, for every business in the city.
     * Only `current_value` and what has been REQUESTED differ per filing, and
     * those come from `AmendmentController::index`.
     *
     * They used to arrive together, which meant the step could not draw a
     * single box until a request returned — and on first arrival not until a
     * draft had been POSTed first. Client, 21 September 2026: *"Why it still
     * loads? Can't you make it appear instantly, just like in the other
     * forms?"* The other forms are instant because their fields are markup.
     * These now are too: the wizard fetches this with the barangays and the
     * PSIC codes, before it paints, and the per-filing values fill in after.
     *
     * `writes`, `column`, `cast` and `validation` are deliberately NOT sent.
     * They are how the server applies a change, and nothing on the client may
     * act on them.
     */
    public function amendableFields(): JsonResponse
    {
        $data = [];

        foreach (AmendableFields::kinds() as $field => $spec) {
            $group = AmendableFields::GROUPS[$spec['group']];

            $data[] = [
                'field' => $field,
                'group' => $spec['group'],
                'group_label' => $group['label'],
                'group_paper' => $group['paper'],
                'label' => $spec['label'],
                'help' => $spec['help'],
                'type' => $spec['type'],
            ];
        }

        return response()->json(['data' => $data]);
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
