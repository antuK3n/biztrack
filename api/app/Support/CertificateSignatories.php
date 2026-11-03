<?php

namespace App\Support;

use App\Models\Department;
use App\Models\OfficeSignatory;
use App\Models\Permit;
use App\Models\PermitType;

/**
 * The names an office prints on its own certificate, and when they are read.
 *
 * ── Each office edits its own [client, 3 November 2026] ────────────────────
 *
 * "Kada office admin nababago nila yung mga name na nasa permit sa office na
 * yon." The catalogue below lists, per office, the captions its certificate
 * prints a name under — the Mayor and OIC-BPLO on the Mayor's Permit, the
 * Zoning Administrator on the Zoning Clearance, the two BFP signatures, the
 * Building Official and the evaluators on the Certificate of Occupancy, the
 * Chief on CENRO's — and the Permits page offers that office a dialog to set
 * the names. They live in `office_signatories`, one row per caption.
 *
 * The Sanitary Permit is NOT here: both of its names are facts of the
 * filing — who inspected, and who approved — and the system fills them
 * itself (PermitController::certificateData).
 *
 * ── Frozen at issue ────────────────────────────────────────────────────────
 *
 * "Once nabago na ang dating permit, ayon na ang mga nakalagay, hindi na
 * mababago; mababago lang ang mga bagong permit." A certificate names who
 * held the post WHEN IT WAS SIGNED. So the office's names are copied into
 * `permits.issued_details['office_signatories']` the moment a permit is
 * created (WorkflowService::issuePermitFor), and the certificate reads that
 * copy; a later edit reaches only permits issued after it. A permit with no
 * copy — issued before this existed — reads the live rows, which is the only
 * truthful answer for it, and the migration that shipped with this froze
 * those too.
 */
class CertificateSignatories
{
    /**
     * Office code => the captions its certificate prints, in the order the
     * form shows them. These strings are the `office_signatories.role` keys.
     */
    public const CATALOGUE = [
        'BPLO' => ['City Mayor', 'OIC-BPLO'],
        'CPDO' => ["City Planning & Dev't Coordinator / Zoning Administrator"],
        'BFP' => ['Chief, Fire Safety Enforcement Section', 'City Fire Marshal'],
        'OBO' => [
            'Building Official',
            'Evaluator — Line and Grade (Geodetic)', 'Evaluator — Architectural', 'Evaluator — Civil/Structural',
            'Evaluator — Electrical', 'Evaluator — Mechanical', 'Evaluator — Sanitary', 'Evaluator — Plumbing',
            'Evaluator — Electronics', 'Evaluator — Interior Design', 'Evaluator — Accessibility',
        ],
        'CENRO' => ['Chief-CENRO'],
    ];

    /** Which certificate each office's names print on, for the dialog's heading. */
    public const CERTIFICATE = [
        'BPLO' => "Mayor's / Business Permit",
        'CPDO' => 'Zoning Clearance',
        'BFP' => 'Fire Safety Inspection Certificate',
        'OBO' => 'Certificate of Occupancy',
        'CENRO' => 'Certificate of Environment Clearance',
    ];

    /**
     * The office's current rows for the certificate, caption by caption, with
     * null where no name is set yet.
     *
     * @return list<array{role: string, name: string|null}>
     */
    public static function currentFor(Department $department): array
    {
        $names = OfficeSignatory::query()
            ->where('department_id', $department->id)
            ->where('is_active', true)
            ->pluck('name', 'role');

        return array_map(
            fn (string $role) => ['role' => $role, 'name' => ($n = trim((string) ($names[$role] ?? ''))) !== '' ? $n : null],
            self::CATALOGUE[$department->code] ?? [],
        );
    }

    /**
     * Every active row of the issuing office, as the certificate wants them
     * — not only the catalogue's, so an office that keeps extra rows (CENRO's
     * Evaluator on its application form) still prints them where its sheet
     * asks. This is what gets frozen.
     *
     * @return list<array{role: string, name: string|null}>
     */
    public static function liveRowsFor(PermitType $type): array
    {
        return OfficeSignatory::query()
            ->where('department_id', $type->issuing_department_id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('role')
            ->get()
            ->map(fn (OfficeSignatory $s) => ['role' => $s->role, 'name' => $s->name])
            ->values()
            ->all();
    }

    /**
     * The office's rows for THIS permit: the copy frozen at issue, else the
     * live rows for a permit issued before freezing existed.
     *
     * @return list<array{role: string, name: string|null}>
     */
    public static function rowsFor(Permit $permit): array
    {
        $frozen = $permit->issued_details['office_signatories'] ?? null;
        if (is_array($frozen)) {
            return array_values(array_filter($frozen, fn ($r) => is_array($r) && isset($r['role'])));
        }

        return $permit->permitType ? self::liveRowsFor($permit->permitType) : [];
    }

    /** The key the frozen copy is stored under in `issued_details`. */
    public const FROZEN_KEY = 'office_signatories';
}
