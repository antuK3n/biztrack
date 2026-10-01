<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `permit.revoke`, held by BPLO and the super admin.
 *
 * Question A26 in docs/questions-for-malabon.md listed what had to exist before
 * a permit could be taken away, and named this permission first. Ken settled the
 * "who" half for the checklist ("Manage Approved Permits", item 23): BPLO and the
 * super admin, and nobody else. The five clearance offices do not get it, even
 * for their own certificates — see the A26 entry for what is still open.
 *
 * A migration and not only a line in RbacSeeder, because the seeder is not run
 * against the tester register: without this the live BPLO and admin accounts
 * would be refused by the route until somebody re-seeded, and a re-seed is not
 * something to do to that database casually (AGENTS.md §2.2).
 *
 * Idempotent, and it writes only rows it can name. On a fresh database the
 * migration runs before any seeder, so the roles do not exist yet; it then
 * creates the permission alone and RbacSeeder attaches it as usual.
 *
 * To undo: `down()` removes the permission, and the cascade on
 * `role_permissions` takes its two grants with it. Nothing else references it.
 */
return new class extends Migration
{
    private const PERMISSION = 'permit.revoke';

    private const ROLES = ['bplo_staff', 'admin'];

    public function up(): void
    {
        $now = now();

        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => self::PERMISSION,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roleIds = DB::table('roles')->whereIn('name', self::ROLES)->pluck('id');

        foreach ($roleIds as $roleId) {
            $held = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if (! $held) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if ($permissionId !== null) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
