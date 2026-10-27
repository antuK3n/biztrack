<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sample names on the four clearance forms that signed as a blank ruled line
 * [client, 27 October 2026: "paki lagyan muna yung permit sa part na to as
 * sample lang … lagyan din ng samples name yung ibang blank"].
 *
 * The captions are the forms' own — the Zoning Clearance's administrator,
 * the Sanitary Permit's City Health Officer, the FSIC's Chief of the Fire
 * Safety Enforcement Section and City Fire Marshal, the Certificate of
 * Occupancy's Building Official (PermitController::certificateData) — and
 * the NAMES are placeholders the City replaces in `office_signatories`
 * before the forms go out. A sample is better than a blank on a sheet being
 * shown round; a blank is better than a sample on one being issued.
 *
 * The Sanitary Inspector is not seeded: that line prints whoever the office
 * assigned to the visit that passed (the inspector pool), not a post.
 *
 * Only where the office exists and no row with that caption does, so a run
 * on a fresh database (the seeders run after migrate) writes nothing and a
 * second run changes nothing. Row counts are reported (AGENTS.md §2.2).
 */
return new class extends Migration
{
    private const SAMPLES = [
        'CPDO' => [["City Planning & Dev't Coordinator / Zoning Administrator", 'Engr. Ma. Theresa B. Castillo']],
        'CHO' => [['City Health Officer', 'Dr. Ramona S. Villegas, MD']],
        'BFP' => [
            ['Chief, Fire Safety Enforcement Section', 'FSINSP Dennis R. Pascual'],
            ['City Fire Marshal', 'FCINSP Leonardo G. Ramos'],
        ],
        'OBO' => [['Building Official', 'Engr. Carlos M. Dela Paz']],
    ];

    public function up(): void
    {
        $added = 0;
        foreach (self::SAMPLES as $code => $rows) {
            $departmentId = DB::table('departments')->where('code', $code)->value('id');
            if ($departmentId === null) {
                continue;
            }
            foreach ($rows as $i => [$role, $name]) {
                $exists = DB::table('office_signatories')
                    ->where('department_id', $departmentId)
                    ->where('role', $role)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('office_signatories')->insert([
                    'department_id' => $departmentId,
                    'role' => $role,
                    'name' => $name,
                    'sort_order' => $i,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $added++;
            }
        }

        if (PHP_SAPI === 'cli') {
            $total = DB::table('office_signatories')->count();
            fwrite(STDOUT, "sample signatories: {$added} added; {$total} office_signatories rows in all".PHP_EOL);
        }
    }

    public function down(): void
    {
        // The names are the office's the moment they are edited; not removed.
    }
};
