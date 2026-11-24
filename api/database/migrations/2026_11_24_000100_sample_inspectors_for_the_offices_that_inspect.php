<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sample inspectors in the pool of every office that books a site visit
 * [Rupert: "please populate the inspector pool with dummy data"].
 *
 * The pool (`an_office_keeps_a_pool_of_inspectors`) is what the booking on
 * Track's For Inspection reads to suggest who goes (InspectorSuggestion). On
 * 24 November 2026 live had two inspectors at CHO, one at CENRO and none at
 * BFP, OBO or CPDO, so three of the five offices that inspect were booked
 * with no suggestion at all.
 *
 * ── Which offices ───────────────────────────────────────────────────────────
 *
 * The ones whose permit type has `requires_inspection`, read from the table
 * rather than listed: CHO, BFP, CPDO, OBO and CENRO. BPLO's Business Permit
 * is the one type without it — BPLO coordinates the clearances and visits
 * nobody — so it gets no pool.
 *
 * ── How many ────────────────────────────────────────────────────────────────
 *
 * Sized against what live already had, so each office ends at three or four:
 * CHO 2 + 2, CENRO 1 + 3, BFP 4 (every business needs an FSIC, so it carries
 * the most visits), CPDO 3, OBO 3. The office's own inspectors are left
 * exactly as they are.
 *
 * ── Which barangays ─────────────────────────────────────────────────────────
 *
 * Each office's sample inspectors split all 21 barangays among THEMSELVES, so
 * every barangay is covered for every office whatever the office's own
 * inspectors cover; where they overlap, InspectorSuggestion gives the visit to
 * whoever has the lighter day. The splits are what "Divide the barangays"
 * (BarangayAreas::divide) proposed for two, three and four inspectors on
 * 24 November 2026 — neighbours together, evenly — written out here rather
 * than computed, so this migration does the same thing whenever it runs. An
 * office that wants its own people in the division presses that button again.
 *
 * ── The rest ────────────────────────────────────────────────────────────────
 *
 * Days and visits a day are stored as the Inspectors screen saves them:
 * `works_days` an ISO weekday list (1 = Monday … 6 = Saturday) and
 * `max_visits_per_day` a whole number in the 1–20 the screen accepts. Everyone
 * works the office week, so every barangay has its inspector on every
 * weekday; a few also go out on Saturdays.
 *
 * The names are invented. No mobile numbers: a made-up number is somebody's
 * real phone, and the screen offers it to an officer to call.
 *
 * Only offices that exist, and only names the office does not already have,
 * so a fresh database (the seeders run after migrate) and a second run both
 * write nothing. Counts reported (AGENTS.md §2.2).
 */
return new class extends Migration
{
    /** BarangayAreas::divide() for two inspectors. */
    private const HALVES = [
        ['Baritan', 'Bayan-bayanan', 'Concepcion', 'Dampalit', 'Flores', 'Hulong Duhat', 'Ibaba', 'Maysilo', 'Muzon', 'Panghulo', 'Santulan'],
        ['Acacia', 'Catmon', 'Longos', 'Niugan', 'Potrero', 'San Agustin', 'Tañong', 'Tinajeros', 'Tonsuya', 'Tugatog'],
    ];

    /** … for three. */
    private const THIRDS = [
        ['Bayan-bayanan', 'Dampalit', 'Flores', 'Hulong Duhat', 'Muzon', 'Panghulo', 'Santulan'],
        ['Acacia', 'Catmon', 'Maysilo', 'Potrero', 'Tinajeros', 'Tonsuya', 'Tugatog'],
        ['Baritan', 'Concepcion', 'Ibaba', 'Longos', 'Niugan', 'San Agustin', 'Tañong'],
    ];

    /** … for four. */
    private const QUARTERS = [
        ['Baritan', 'Bayan-bayanan', 'Dampalit', 'Flores', 'Hulong Duhat'],
        ['Catmon', 'Concepcion', 'Maysilo', 'Muzon', 'Panghulo', 'Santulan'],
        ['Acacia', 'Potrero', 'Tinajeros', 'Tonsuya', 'Tugatog'],
        ['Ibaba', 'Longos', 'Niugan', 'San Agustin', 'Tañong'],
    ];

    private const WEEK = [1, 2, 3, 4, 5];

    private const WEEK_AND_SATURDAY = [1, 2, 3, 4, 5, 6];

    /** Office code => [name, barangays, works_days, max_visits_per_day]. */
    private const SAMPLES = [
        'CHO' => [
            ['Rowena D. Magbanua', self::HALVES[0], self::WEEK, 5],
            ['Jerome P. Ancheta', self::HALVES[1], self::WEEK_AND_SATURDAY, 4],
        ],
        'BFP' => [
            ['Arnold V. Quiambao', self::QUARTERS[0], self::WEEK_AND_SATURDAY, 5],
            ['Cristina L. Abella', self::QUARTERS[1], self::WEEK, 6],
            ['Ramon T. Velasco', self::QUARTERS[2], self::WEEK, 6],
            ['Glenda M. Pacheco', self::QUARTERS[3], self::WEEK_AND_SATURDAY, 5],
        ],
        'CPDO' => [
            ['Eduardo R. Navales', self::THIRDS[0], self::WEEK, 4],
            ['Marissa C. Dimaculangan', self::THIRDS[1], self::WEEK, 4],
            ['Noel A. Agbayani', self::THIRDS[2], self::WEEK, 3],
        ],
        'OBO' => [
            ['Rogelio S. Catacutan', self::THIRDS[0], self::WEEK, 3],
            ['Leonora B. Samonte', self::THIRDS[1], self::WEEK, 3],
            ['Dante F. Ilagan', self::THIRDS[2], self::WEEK, 4],
        ],
        'CENRO' => [
            ['Herminio G. Maglaya', self::THIRDS[0], self::WEEK, 4],
            ['Analyn P. Torralba', self::THIRDS[1], self::WEEK, 4],
            ['Vicente M. Dumlao', self::THIRDS[2], self::WEEK_AND_SATURDAY, 3],
        ],
    ];

    public function up(): void
    {
        $inspectorsBefore = DB::table('inspectors')->count();
        $areasBefore = DB::table('inspector_barangay')->count();

        // The offices that book visits, by code: whoever issues a type that requires one.
        $inspecting = DB::table('permit_types')
            ->join('departments', 'departments.id', '=', 'permit_types.issuing_department_id')
            ->where('permit_types.requires_inspection', true)
            ->pluck('departments.id', 'departments.code');
        $barangays = DB::table('barangays')->pluck('id', 'name');

        $added = 0;
        foreach (self::SAMPLES as $code => $people) {
            $departmentId = $inspecting[$code] ?? null;
            if ($departmentId === null) {
                continue;
            }
            foreach ($people as [$name, $area, $days, $perDay]) {
                $exists = DB::table('inspectors')
                    ->where('department_id', $departmentId)
                    ->where('name', $name)
                    ->exists();
                if ($exists) {
                    continue;
                }
                $id = DB::table('inspectors')->insertGetId([
                    'department_id' => $departmentId,
                    'name' => $name,
                    'is_active' => true,
                    'works_days' => json_encode($days),
                    'max_visits_per_day' => $perDay,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $rows = [];
                foreach ($area as $barangay) {
                    if (isset($barangays[$barangay])) {
                        $rows[] = ['inspector_id' => $id, 'barangay_id' => $barangays[$barangay]];
                    }
                }
                DB::table('inspector_barangay')->insert($rows);
                $added++;
            }
        }

        if (PHP_SAPI === 'cli') {
            $inspectorsAfter = DB::table('inspectors')->count();
            $areasAfter = DB::table('inspector_barangay')->count();
            fwrite(STDOUT, "sample inspectors: {$added} added; inspectors {$inspectorsBefore} -> {$inspectorsAfter}, "
                ."inspector_barangay {$areasBefore} -> {$areasAfter}".PHP_EOL);
        }
    }

    public function down(): void
    {
        // The pool is the office's the moment it is edited; not removed.
    }
};
