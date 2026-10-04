<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The City Mayor on every certificate, as a BPLO signatory row rather than a
 * constant in PermitFace (see PermitFace::mayorName for why).
 *
 * For registers that already exist: a fresh database has no departments yet
 * at this point, and ReferenceSeeder seeds the same row there. A row that is
 * already present is left alone, because a signatory name belongs to whoever
 * last corrected it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bplo = DB::table('departments')->where('code', 'BPLO')->value('id');
        if ($bplo === null) {
            return;
        }

        $present = DB::table('office_signatories')
            ->where('department_id', $bplo)
            ->where('role', 'City Mayor')
            ->exists();

        if (! $present) {
            DB::table('office_signatories')->insert([
                'department_id' => $bplo,
                'role' => 'City Mayor',
                'name' => 'Hon. Jeannie Sandoval',
                'sort_order' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Left in place: the name may have been corrected since, and removing
        // it would put a ruled line where the Mayor signs.
    }
};
