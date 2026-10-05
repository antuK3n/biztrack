<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `admin` role's label reads "Super Administrator".
 *
 * The client asked for every "System Administrator" / "Administrator" on
 * screen to say "Super Administrator" (checklist, Manage Officer-in-Charge 3).
 * `roles.display_name` is what the officer screens print for a role — the
 * role filter on Officer Assignment and the role picker among them — so the
 * label is changed where it is stored. The role's `name`, `admin`, is the
 * identifier every permission check reads and is NOT touched.
 *
 * A migration and not only a line in RbacSeeder, because the seeder is not run
 * against the tester register (same reasoning as
 * 2026_09_27_000100_give_bplo_and_the_super_admin_permit_revoke). It rewrites
 * only the label it expects, so a register where somebody already renamed the
 * role keeps their wording. On a fresh database the role does not exist yet;
 * RbacSeeder then creates it with the new label.
 *
 * To undo: `down()` puts "Administrator" back on the same row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')
            ->where('name', 'admin')
            ->where('display_name', 'Administrator')
            ->update(['display_name' => 'Super Administrator', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('name', 'admin')
            ->where('display_name', 'Super Administrator')
            ->update(['display_name' => 'Administrator', 'updated_at' => now()]);
    }
};
