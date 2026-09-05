<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the Market Clearance, the CMO Market Office and its one account.
 *
 * Client instruction, 6 September 2026: confirmed with the LGU that neither the
 * clearance nor its administrator is needed. A business that genuinely needs one
 * is asked for it by hand, through Other Requirements.
 *
 * ── Why this is a delete and not a soft retirement ────────────────────────
 *
 * Because nothing ever used it. Counted immediately before writing this, on the
 * live register: 0 pivot rows, 0 permits issued, 0 office forms, 0 assignments,
 * 0 inspections. The only rows that exist are the ones the seeders wrote — the
 * permit type, its 2 requirement rows, the department, the `market_admin` role
 * and one never-logged-in officer account (market@biztrack.local, seeded
 * 2026-08-30).
 *
 * AGENTS.md §2.2 says never delete a row you did not create. Every row deleted
 * here was created by `ReferenceSeeder` or `RbacSeeder` and by nothing else, and
 * the guard below re-proves that at run time rather than trusting this comment:
 * if any application, permit, assignment, inspection or office form has come to
 * reference Market since, the migration refuses and leaves everything alone.
 * That is the case worth protecting — this runs on the tester register, and a
 * filing made between the survey and the deploy must not be quietly destroyed.
 *
 * To bring it back: `ReferenceSeeder` and `RbacSeeder` carry notes naming
 * everything that has to be restored, and this migration's `down()` recreates
 * the department, the permit type and the role. It cannot restore the officer
 * account — a password hash is not something to write into a migration — so
 * that one is recreated by seeding.
 */
return new class extends Migration
{
    public function up(): void
    {
        $dept = DB::table('departments')->where('code', 'CMO-MARKET')->first();
        $type = DB::table('permit_types')->where('code', 'MARKET')->first();

        if ($dept === null && $type === null) {
            echo PHP_EOL.'  Market Clearance is already absent; nothing to remove.'.PHP_EOL;

            return;
        }

        /*
         * Refuse rather than cascade. Each of these would mean a real filing had
         * asked for a Market Clearance after the survey that justified deleting
         * it, and the right answer then is a human decision, not a migration
         * quietly taking the filing's permit away.
         */
        $inUse = [];
        if ($type !== null) {
            $inUse['application_permit_types'] = DB::table('application_permit_types')->where('permit_type_id', $type->id)->count();
            $inUse['permits'] = DB::table('permits')->where('permit_type_id', $type->id)->count();
            $inUse['application_office_forms'] = DB::table('application_office_forms')->where('permit_type_id', $type->id)->count();
            $inUse['application_documents'] = DB::table('application_documents')->where('permit_type_id', $type->id)->count();
        }
        if ($dept !== null) {
            $inUse['application_assignments'] = DB::table('application_assignments')->where('department_id', $dept->id)->count();
            $inUse['inspections'] = DB::table('inspections')->where('department_id', $dept->id)->count();
        }

        $blocking = array_filter($inUse);
        if ($blocking !== []) {
            $detail = implode(', ', array_map(fn ($t, $n) => "{$t}={$n}", array_keys($blocking), $blocking));
            throw new RuntimeException(
                'Refusing to remove Market Clearance: live rows now reference it ('.$detail.'). '
                .'Somebody filed for one after this removal was planned. Decide what happens to those filings first.'
            );
        }

        $removed = [];

        if ($type !== null) {
            $removed['permit_type_requirements'] = DB::table('permit_type_requirements')
                ->where('permit_type_id', $type->id)->delete();
            $removed['permit_types'] = DB::table('permit_types')->where('id', $type->id)->delete();
        }

        /*
         * The officer account goes before the department, because
         * `users.department_id` is a foreign key and leaving the user behind
         * would either block the delete or orphan them into a null department
         * where they would show up in every office's staff list.
         *
         * Deleted, not soft-deleted: the account has never been logged into, and
         * a soft-deleted user still occupies its unique email. Its role
         * assignments go with it.
         */
        if ($dept !== null) {
            $userIds = DB::table('users')->where('department_id', $dept->id)->pluck('id');
            if ($userIds->isNotEmpty()) {
                foreach (['model_has_roles' => 'model_id', 'model_has_permissions' => 'model_id'] as $table => $col) {
                    if (DB::getSchemaBuilder()->hasTable($table)) {
                        DB::table($table)->whereIn($col, $userIds)->delete();
                    }
                }
                $removed['users'] = DB::table('users')->whereIn('id', $userIds)->delete();
            }
            $removed['departments'] = DB::table('departments')->where('id', $dept->id)->delete();
        }

        if (DB::getSchemaBuilder()->hasTable('roles')) {
            $roleIds = DB::table('roles')->where('name', 'market_admin')->pluck('id');
            if ($roleIds->isNotEmpty()) {
                foreach (['role_has_permissions', 'model_has_roles'] as $table) {
                    if (DB::getSchemaBuilder()->hasTable($table)) {
                        DB::table($table)->whereIn('role_id', $roleIds)->delete();
                    }
                }
                $removed['roles'] = DB::table('roles')->whereIn('id', $roleIds)->delete();
            }
        }

        echo PHP_EOL;
        foreach ($removed as $table => $n) {
            echo "  removed from {$table}: {$n}".PHP_EOL;
        }
    }

    /**
     * Recreate the department, permit type and role.
     *
     * The officer account is NOT recreated — a password hash does not belong in
     * a migration — so rolling back and wanting a working Market login means
     * re-running `RbacSeeder`, which will also need its `market_admin` block
     * restored. The note there says so.
     */
    public function down(): void
    {
        $deptId = DB::table('departments')->insertGetId([
            'code' => 'CMO-MARKET',
            'name' => 'Office of the City Market Administrator',
            'description' => 'Issues market clearance for market-based businesses.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permit_types')->insert([
            'code' => 'MARKET',
            'name' => 'Market Clearance',
            'permit_number_prefix' => 'MCM',
            'issuing_department_id' => $deptId,
            'validity_days' => 365,
            'description' => 'Clearance for market-based businesses.',
            'requires_inspection' => true,
            'base_fee' => 300,
            'per_line_surcharge' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (DB::getSchemaBuilder()->hasTable('roles')) {
            DB::table('roles')->insert([
                'name' => 'market_admin',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
