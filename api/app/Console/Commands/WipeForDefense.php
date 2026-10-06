<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Empty the register of every owner and everything they filed, keeping the
 * City's accounts and reference data, so the owner accounts can be rebuilt
 * from nothing through the app's own rules (biztrack:seed-defense-accounts).
 *
 * ── What Ken asked for (6 October 2026) ─────────────────────────────────────
 *
 * "Clean the db of the data it has now and replace it with those." Every
 * business-owner account goes, with every business, filing, permit, payment,
 * message and notice hanging off it, the audit log, and the uploaded files
 * for those rows. The super admin and every staff account stay, with their
 * roles and passwords untouched, and so does every reference and
 * configuration table. Confirmed with Ken before it was written: all owners,
 * not only the testers'.
 *
 * ── Every table is named, and an unnamed one stops it ───────────────────────
 *
 * The two lists below cover every table the migrations create. A table in
 * neither — one a later migration adds — makes the command refuse rather than
 * guess, because guessing wrong either keeps a filing's child rows pointing
 * at nothing or empties something an admin configured. Add it to a list.
 *
 * ── The numbers start again ─────────────────────────────────────────────────
 *
 * Tracking IDs, permit numbers, BANs and PAY references are the highest one
 * already issued this year plus one (App\Support\Numbering::next), so they
 * restart once the rows are gone. A TOP number is the fee assessment's id,
 * so the identity sequences of the emptied tables are restarted too:
 * `TRUNCATE … RESTART IDENTITY` on PostgreSQL, `sqlite_sequence` on SQLite.
 * The users sequence is not reset — staff keep their ids, so a new owner's id
 * follows the highest one ever used.
 *
 * ── How it empties ──────────────────────────────────────────────────────────
 *
 * On PostgreSQL one TRUNCATE names every emptied table at once and WITHOUT
 * cascade: the server then refuses if any table outside the list still
 * points into it, which is the check that nothing kept is left holding a
 * dangling key. (Laravel's own truncate() would add CASCADE where configured,
 * and CASCADE empties whatever references the table, kept or not.) The owner
 * accounts and their role rows and tokens are then deleted by key. All of it
 * is one transaction; the files go only after it commits.
 */
class WipeForDefense extends Command
{
    protected $signature = 'biztrack:wipe-for-defense
        {--dry-run : Count what would go and what would stay; change nothing}
        {--confirm= : Type WIPE to run it for real}';

    protected $description = 'Delete every business-owner account and everything filed, keeping staff accounts and reference data.';

    /** Emptied outright: every row hangs off an owner, a business or a filing, or is a log, a queue or a cache. */
    public const WIPED = [
        'analytics_snapshots', 'app_notifications', 'application_amendments', 'application_assignments',
        'application_corrections', 'application_documents', 'application_office_forms', 'application_permit_types',
        'application_prior_permits', 'application_return_notes', 'application_status_history', 'applications',
        'audit_logs', 'business_addresses', 'business_lines', 'business_owners', 'businesses', 'cache', 'cache_locks',
        'chatbot_conversations', 'chatbot_messages', 'clearance_refusals', 'compliance_checks', 'email_codes',
        'failed_jobs', 'fee_assessments', 'inspections', 'job_batches', 'jobs', 'legacy_imports', 'legacy_owners',
        'message_attachments', 'message_threads', 'messages', 'officer_request_responses', 'officer_requests',
        'password_reset_tokens', 'payments', 'permit_expiry_notices', 'permits', 'sessions', 'unbilled_permit_fees',
        'wizard_drafts',
    ];

    /** Kept whole: reference and configuration data. */
    public const KEPT = [
        'barangay_zoning_classification', 'barangay_zoning_overlay', 'barangays', 'departments', 'document_types',
        'fee_rules', 'migrations', 'office_signatories', 'permissions', 'permit_type_requirements', 'permit_types',
        'psic_codes', 'role_permissions', 'roles', 'settings', 'zoning_classifications', 'zoning_overlays',
    ];

    /** Kept for staff, emptied of owners. */
    public const SPLIT = ['users', 'user_roles', 'personal_access_tokens'];

    /**
     * Where the emptied rows' files live on the local disk. All of each
     * directory goes: every row a file there belongs to is emptied. The
     * avatars are per user and go only for the owners.
     */
    private const FILE_DIRECTORIES = [
        'private/documents', 'private/messages', 'private/permits', 'private/receipts',
        'private/requirement-references', 'legacy-imports',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $dry && $this->option('confirm') !== 'WIPE') {
            $this->error('This deletes every owner account and everything filed. Run with --dry-run to see the counts, or --confirm=WIPE to do it.');

            return self::FAILURE;
        }

        $tables = $this->tables();
        $unknown = array_values(array_diff($tables, self::WIPED, self::KEPT, self::SPLIT));
        $missing = array_values(array_diff([...self::WIPED, ...self::KEPT, ...self::SPLIT], $tables));
        if ($unknown !== [] || $missing !== []) {
            $this->error('The table lists do not match this database. Not in either list: '.(implode(', ', $unknown) ?: 'none')
                .'. Listed but absent: '.(implode(', ', $missing) ?: 'none').'.');

            return self::FAILURE;
        }

        $owners = $this->ownerIds();
        $staff = User::withTrashed()->whereNotIn('id', $owners)->with('roles')->get();

        $this->table(['Table', 'Rows deleted', 'Rows kept'], [
            ...array_map(fn (string $t) => [$t, DB::table($t)->count(), 0], self::WIPED),
            ['users', count($owners), $staff->count()],
            ['user_roles', DB::table('user_roles')->whereIn('user_id', $owners)->count(), DB::table('user_roles')->whereNotIn('user_id', $owners)->count()],
            ['personal_access_tokens', $this->ownerTokens($owners)->count(), DB::table('personal_access_tokens')->count() - $this->ownerTokens($owners)->count()],
            ...array_map(fn (string $t) => [$t, 0, DB::table($t)->count()], self::KEPT),
        ]);

        $this->line('Accounts kept, by role: '.$staff->flatMap(fn (User $u) => $u->roles->pluck('name'))->countBy()->sortKeys()
            ->map(fn (int $n, string $role) => "{$role} {$n}")->implode(' · '));

        if ($dry) {
            $this->info('Dry run: nothing deleted.');

            return self::SUCCESS;
        }

        $avatars = User::withTrashed()->whereIn('id', $owners)->pluck('id')->map(fn ($id) => "private/avatars/{$id}")->all();
        $paths = $this->storedPaths();

        DB::transaction(function () use ($owners) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('TRUNCATE TABLE '.implode(', ', array_map(fn ($t) => '"'.$t.'"', self::WIPED)).' RESTART IDENTITY');
            } else {
                // SQLite cannot switch its key checks off inside a transaction,
                // so the rows go child first, in the order the keys imply.
                foreach ($this->childrenFirst() as $table) {
                    DB::table($table)->delete();
                }
                if (DB::getDriverName() === 'sqlite') {
                    DB::table('sqlite_sequence')->whereIn('name', self::WIPED)->delete();
                }
            }

            foreach (array_chunk($owners, 500) as $chunk) {
                $this->ownerTokens($chunk)->delete();
                DB::table('user_roles')->whereIn('user_id', $chunk)->delete();
                // users.blacklisted_by points at the admin who did it; a kept
                // account blacklisted by an owner cannot exist, but the key is
                // cleared rather than left dangling if it somehow does.
                DB::table('users')->whereIn('blacklisted_by', $chunk)->update(['blacklisted_by' => null]);
                DB::table('users')->whereIn('id', $chunk)->delete();
            }
        });

        $disk = Storage::disk('local');
        $files = 0;
        foreach ($paths as $path) {
            if ($disk->exists($path) && $disk->delete($path)) {
                $files++;
            }
        }
        foreach ([...self::FILE_DIRECTORIES, ...$avatars] as $directory) {
            $files += count($disk->allFiles($directory));
            $disk->deleteDirectory($directory);
        }

        $this->info(sprintf('Deleted %d owner accounts, emptied %d tables and removed %d files. %d accounts kept.',
            count($owners), count(self::WIPED), $files, $staff->count()));

        return self::SUCCESS;
    }

    /**
     * An owner is an account that holds no role but business_owner. One that
     * also holds an office role is staff and stays; so does a user with an
     * office role and no owner role. An account with no role at all was never
     * given an office, so it goes with the owners.
     *
     * @return list<int>
     */
    private function ownerIds(): array
    {
        $staffRoles = DB::table('roles')->where('name', '!=', 'business_owner')->pluck('id');

        return DB::table('users')
            ->whereNotExists(fn ($q) => $q->from('user_roles')->whereColumn('user_roles.user_id', 'users.id')->whereIn('user_roles.role_id', $staffRoles))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @param  list<int>  $owners */
    private function ownerTokens(array $owners): Builder
    {
        return DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $owners);
    }

    /**
     * Every file path the emptied rows name, read before they go, so a file
     * kept outside the usual directories still goes with its row.
     *
     * @return list<string>
     */
    private function storedPaths(): array
    {
        $paths = [];
        foreach ([
            'application_documents' => ['stored_path'], 'message_attachments' => ['stored_path'], 'messages' => ['attachment_path'],
            'officer_requests' => ['reference_path', 'file_path'], 'officer_request_responses' => ['file_path'],
            'permits' => ['pdf_path'], 'payments' => ['receipt_path'], 'legacy_imports' => ['stored_path'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                array_push($paths, ...DB::table($table)->whereNotNull($column)->pluck($column)->all());
            }
        }
        foreach (DB::table('inspections')->whereNotNull('photo_paths')->pluck('photo_paths') as $json) {
            array_push($paths, ...array_filter((array) json_decode((string) $json, true), 'is_string'));
        }

        return array_values(array_unique(array_filter($paths, fn ($p) => is_string($p) && $p !== '' && ! str_contains($p, '..'))));
    }

    /**
     * The emptied tables, each after every emptied table that points at it.
     *
     * @return list<string>
     */
    private function childrenFirst(): array
    {
        $parents = [];
        foreach (self::WIPED as $table) {
            $parents[$table] = array_values(array_intersect(
                array_diff(array_column(Schema::getForeignKeys($table), 'foreign_table'), [$table]),
                self::WIPED,
            ));
        }

        $order = [];
        while ($parents !== []) {
            $pointedAt = array_merge(...array_values($parents));
            $leaves = array_values(array_diff(array_keys($parents), $pointedAt));
            if ($leaves === []) {
                // A cycle of keys: take the rest as they come and let the
                // database say which row it is.
                return [...$order, ...array_keys($parents)];
            }
            foreach ($leaves as $leaf) {
                $order[] = $leaf;
                unset($parents[$leaf]);
            }
        }

        return $order;
    }

    /** @return list<string> */
    private function tables(): array
    {
        $tables = Schema::getTableListing(schemaQualified: false);
        if (DB::getDriverName() === 'pgsql') {
            $tables = array_map(fn (array $t) => $t['name'], array_filter(Schema::getTables(), fn (array $t) => $t['schema'] === 'public'));
        }

        return array_values(array_diff($tables, ['sqlite_sequence']));
    }
}
