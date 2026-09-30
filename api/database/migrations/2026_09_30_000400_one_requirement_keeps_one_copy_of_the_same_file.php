<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Backfill `file_hash`, and drop the duplicate copies already in the register.
 *
 * ── Why a migration and not only the guard ──────────────────────────────────
 *
 * `DocumentController::store` and `OfficeFormController::storeRequirement`
 * stopped accepting a second byte-identical copy of a requirement on
 * 30 September 2026. That prevents new ones and does nothing about the rows
 * already stored — the client re-read the officer's Section C straight after
 * and saw the same file still listed as both the current copy and the newest
 * "earlier copy", because it genuinely was two rows.
 *
 * ── What it removes, and what it will not ───────────────────────────────────
 *
 * Only a copy whose bytes are identical to the one immediately before it on
 * the same requirement — the exact case the runtime guard now refuses, and
 * the only case where the two rows carry no difference for anyone to act on.
 *
 * A-then-B-then-A survives whole. The applicant going back to an earlier
 * version is a real statement and an officer reading the history should see
 * it, so a repeat is only removed when it is consecutive.
 *
 * The NEWER row of each identical pair goes, which is what the runtime rule
 * produces: an upload matching the newest copy is answered with the copy
 * already held, so the surviving row is the older one.
 *
 * `file_hash` is computed from the stored file. A row whose file is missing
 * from disk is left alone and unhashed rather than guessed at — it cannot be
 * compared, so it cannot be a proven duplicate, and deleting on a hash of ''
 * would take real documents with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $disk = Storage::disk('local');

        /* ── Backfill the hash from what is actually on disk ────────────── */
        DB::table('application_documents')
            ->whereNull('file_hash')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($disk) {
                foreach ($rows as $row) {
                    if ($row->stored_path === null || ! $disk->exists($row->stored_path)) {
                        continue;
                    }

                    $full = $disk->path($row->stored_path);
                    $hash = is_readable($full) ? hash_file('sha256', $full) : null;
                    if ($hash === null || $hash === false) {
                        continue;
                    }

                    DB::table('application_documents')
                        ->where('id', $row->id)
                        ->update(['file_hash' => $hash]);
                }
            });

        /* ── Then the consecutive repeats ───────────────────────────────── */
        $groups = DB::table('application_documents')
            ->whereNotNull('file_hash')
            ->orderBy('application_id')
            ->orderBy('document_type_id')
            ->orderBy('id')
            ->get(['id', 'application_id', 'document_type_id', 'permit_type_id', 'file_hash', 'stored_path'])
            ->groupBy(fn ($r) => $r->application_id.'|'.$r->document_type_id.'|'.($r->permit_type_id ?? 'null'));

        foreach ($groups as $rows) {
            $previous = null;
            foreach ($rows as $row) {
                if ($previous !== null && $previous->file_hash === $row->file_hash) {
                    /*
                     * The newer of an identical pair. Its file goes too, but
                     * only when no surviving row points at the same path —
                     * two rows sharing one stored file would otherwise leave
                     * the keeper with nothing to open.
                     */
                    $shared = DB::table('application_documents')
                        ->where('stored_path', $row->stored_path)
                        ->where('id', '!=', $row->id)
                        ->exists();

                    DB::table('application_documents')->where('id', $row->id)->delete();

                    if (! $shared && $row->stored_path !== null) {
                        Storage::disk('local')->delete($row->stored_path);
                    }

                    /* `$previous` stays: a run of three identical copies
                     * collapses to the first, not to every other one. */
                    continue;
                }

                $previous = $row;
            }
        }
    }

    public function down(): void
    {
        /*
         * Not reversible, and saying so is more honest than a no-op that
         * looks like one. The deleted rows were byte-identical to a copy that
         * survives, so nothing they recorded is lost — but the rows
         * themselves cannot be conjured back, and the hashes are left in
         * place because they are simply true.
         */
    }
};
