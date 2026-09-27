<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Checklist "Manage Approved Permits – Ken", item 1: one analytics dashboard for
 * all offices. Every office admin now holds `analytics.view` and reads the
 * dashboard scoped to their own office; the super admin holds it too and, like
 * BPLO, may switch office or view all (App\Support\AnalyticsOffice).
 *
 * RbacSeeder carries the same grant for a fresh database. This migration exists
 * because the seeder is not re-run against a register that already has roles,
 * and the live register must not be re-seeded (AGENTS.md §2.2).
 *
 * Insert-only. It adds (role, permission) rows that are missing and touches
 * nothing else; a role that does not exist on this register is skipped. down()
 * removes exactly the rows this adds, leaving BPLO's original grant alone.
 *
 * What would reverse it: the client deciding office admins should not see
 * analytics after all. Then roll this back and take the roles out of the
 * seeder's lists again.
 */
return new class extends Migration
{
    /** Roles that gain `analytics.view`. BPLO already holds it. */
    private const ROLES = [
        'sanitary_officer',
        'fire_inspector',
        'obo_staff',
        'cenro_officer',
        'zoning_officer',
        'admin',
    ];

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'analytics.view')->value('id');
        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => 'analytics.view',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (DB::table('roles')->whereIn('name', self::ROLES)->pluck('id') as $roleId) {
            $exists = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if (! $exists) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'analytics.view')->value('id');
        if ($permissionId === null) {
            return;
        }

        DB::table('role_permissions')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', DB::table('roles')->whereIn('name', self::ROLES)->select('id'))
            ->delete();
    }
};
