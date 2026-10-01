<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per import of the old register, and the permission to run one.
 *
 * ── Why a table and not only the audit log ─────────────────────────────────
 *
 * An import is two steps with a person between them: a dry run the super admin
 * reads, then a confirm. Something has to hold the dry run's verdict and the
 * uploaded file across that gap, and a large file is processed by a queued job
 * that the screen polls — both need a row with a status. The audit log still
 * gets its own entry for each run (who, when, which file, what it did); this
 * table is the working state, that one is the record.
 *
 * `rejects` keeps the first few hundred rejected rows with their reasons, not
 * all of them. A 40,000-row export with a wrong date format would otherwise
 * write 40,000 reasons into one JSON cell; `rejected` holds the true count.
 *
 * `source_query` holds the ODBC DSN name and SQL for an ODBC import, never a
 * username or password — those come from the server's environment
 * (LEGACY_ODBC_USERNAME / LEGACY_ODBC_PASSWORD), so a credential is never typed
 * into a browser or stored in the register.
 *
 * ── The permission ─────────────────────────────────────────────────────────
 *
 * `data.import` goes to the super admin (`admin`) alone. It is inserted here as
 * well as in RbacSeeder because the live register is never re-seeded
 * (AGENTS.md §2.2) — a permission that only the seeder knows about would exist
 * on a fresh database and nowhere a tester could reach it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 10); // csv | odbc
            $table->string('file_name')->nullable();
            $table->string('stored_path')->nullable();
            $table->text('source_query')->nullable();
            // previewed → queued → running → completed | failed
            $table->string('status', 20)->default('previewed');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('will_create')->default(0);
            $table->unsignedInteger('will_update')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->json('breakdown')->nullable();
            $table->json('rejects')->nullable();
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        if (! DB::table('permissions')->where('name', 'data.import')->exists()) {
            DB::table('permissions')->insert(['name' => 'data.import', 'created_at' => $now, 'updated_at' => $now]);
        }
        $permissionId = DB::table('permissions')->where('name', 'data.import')->value('id');
        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');

        // A fresh database has no roles yet at this point — RbacSeeder grants it
        // there. On a populated register the admin role exists and gets it now.
        if ($adminRoleId !== null && ! DB::table('role_permissions')
            ->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->exists()) {
            DB::table('role_permissions')->insert([
                'role_id' => $adminRoleId, 'permission_id' => $permissionId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'data.import')->value('id');
        if ($permissionId !== null) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        Schema::dropIfExists('legacy_imports');
    }
};
