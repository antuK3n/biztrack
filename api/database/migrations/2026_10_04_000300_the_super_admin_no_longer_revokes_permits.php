<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `permit.revoke` comes off the super admin.
 *
 * [Client, 4 October 2026: "paki tanggal ang revoke sa super admin, at bplo,
 * dapat sa bplo ayon lang kaya nyang i revoke".] The super admin oversees the
 * register; taking a permit away is the issuing office's act. BPLO keeps the
 * permission, and PermitController::revoke narrows it to the Mayor's Permit.
 *
 * A migration as well as the RbacSeeder line, for the reason the grant was one
 * (2026_09_27_000100): the seeder is not run against the tester register.
 *
 * To undo: `down()` puts the grant back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'permit.revoke')->value('id');
        $roleId = DB::table('roles')->where('name', 'admin')->value('id');

        if ($permissionId !== null && $roleId !== null) {
            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->delete();
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'permit.revoke')->value('id');
        $roleId = DB::table('roles')->where('name', 'admin')->value('id');

        if ($permissionId === null || $roleId === null) {
            return;
        }

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
};
