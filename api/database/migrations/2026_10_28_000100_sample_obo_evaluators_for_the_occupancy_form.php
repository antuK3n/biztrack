<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sample evaluators on the Certificate of Occupancy's "Verified & complied"
 * lines [client, 28 October 2026: "pakilagyan yung ibang blank kahit sample
 * lang"].
 *
 * NBC Form B-13's left page has a ruled line per discipline — Line and
 * Grade, Architectural, Civil/Structural, Electrical and the rest — on which
 * the OBO's evaluator for that discipline signs off. Two of them this system
 * fills itself (Locational/Zoning with the Zoning Clearance number, Fire
 * Safety with the FSIC); the other ten are the OBO's own people.
 *
 * They are kept as `office_signatories` rows, role "Evaluator — <line>", so
 * the City replaces each placeholder on its own screen rather than in code,
 * and the certificate prints whatever the office has on file
 * (PermitController::certificateData, `occ_evaluators`).
 *
 * Only where the OBO exists and the row does not, so a fresh database (the
 * seeders run after migrate) and a second run both write nothing. Counts
 * reported (AGENTS.md §2.2).
 */
return new class extends Migration
{
    private const EVALUATORS = [
        'Line and Grade (Geodetic)' => 'Engr. Ramil T. Bautista',
        'Architectural' => 'Arch. Joanna P. Lim',
        'Civil/Structural' => 'Engr. Marco V. Dizon',
        'Electrical' => 'Engr. Arnel C. Santos',
        'Mechanical' => 'Engr. Jose B. Manalo',
        'Sanitary' => 'Engr. Liza M. Ocampo',
        'Plumbing' => 'MP Ronaldo S. Cruz',
        'Electronics' => 'Engr. Kevin D. Tan',
        'Interior Design' => 'ID Patricia G. Reyes',
        'Accessibility' => 'Arch. Daniel F. Garcia',
        // "Others (specify)" prints what was verified besides the twelve.
        'Others' => 'Fire Sprinkler System',
    ];

    public function up(): void
    {
        $obo = DB::table('departments')->where('code', 'OBO')->value('id');
        $added = 0;

        if ($obo !== null) {
            $order = 10;
            foreach (self::EVALUATORS as $line => $name) {
                $role = "Evaluator — {$line}";
                $order++;
                if (DB::table('office_signatories')->where('department_id', $obo)->where('role', $role)->exists()) {
                    continue;
                }
                DB::table('office_signatories')->insert([
                    'department_id' => $obo,
                    'role' => $role,
                    'name' => $name,
                    'sort_order' => $order,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $added++;
            }
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, "sample OBO evaluators: {$added} added".PHP_EOL);
        }
    }

    public function down(): void
    {
        // The names are the office's the moment they are edited; not removed.
    }
};
