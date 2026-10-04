<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `permit.revoke` for the five clearance offices.
 *
 * [Client, 4 October 2026: "yung mga kanya kanya nilang permit pwede nilang
 * irevoke syempre … yung cert na nirerelease ng office na yon sya lang pwede
 * mag revoke".] Each office may now take back the certificate it issued, and
 * PermitController::revoke refuses any certificate another office issued — so
 * BPLO, which already held the permission, is held to the Mayor's Permit by the
 * same rule.
 *
 * A migration as well as the RbacSeeder lines, because the seeder is not run
 * against the tester register (see 2026_09_27_000100). Idempotent.
 *
 * To undo: `down()` removes the five grants and leaves BPLO's.
 */
return new class extends Migration
{
    private const ROLES = ['sanitary_officer', 'fire_inspector', 'obo_staff', 'cenro_officer', 'zoning_officer'];

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'permit.revoke')->value('id');

        if ($permissionId === null) {
            return;
        }

        foreach (DB::table('roles')->whereIn('name', self::ROLES)->pluck('id') as $roleId) {
            $held = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if (! $held) {
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
        $permissionId = DB::table('permissions')->where('name', 'permit.revoke')->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('role_permissions')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', DB::table('roles')->whereIn('name', self::ROLES)->pluck('id'))
            ->delete();
    }
};
