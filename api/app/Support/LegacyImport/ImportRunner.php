<?php

namespace App\Support\LegacyImport;

use App\Models\LegacyImport;
use App\Support\Audit;
use App\Support\LegacyImport\Sources\CsvSource;
use App\Support\LegacyImport\Sources\OdbcSource;
use App\Support\LegacyImport\Sources\RowSource;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The bookkeeping around one import: its row in `legacy_imports`, its audit
 * entries, and the uploaded file's lifetime. Shared by the admin screen, the
 * queued job and the artisan command, so all three record an import the same
 * way.
 *
 * The uploaded CSV holds owners' names, emails and mobile numbers (RA 10173).
 * It is kept only between the dry run and the run, and deleted as soon as the
 * run finishes — completed or failed. The counts and the rejects list stay.
 */
class ImportRunner
{
    public function __construct(private LegacyImporter $importer) {}

    /** The row source a stored import reads from. */
    public function sourceFor(LegacyImport $import): RowSource
    {
        if ($import->source === 'odbc') {
            $q = $import->source_query ?? [];

            return new OdbcSource($q['dsn'] ?? '', $q['table'] ?? null, $q['query'] ?? null);
        }

        return new CsvSource(Storage::disk('local')->path((string) $import->stored_path), (string) $import->file_name);
    }

    /** The dry run: fill the import's counts and rejects, write nothing else. */
    public function preview(LegacyImport $import, ?RowSource $source = null): LegacyImport
    {
        $summary = $this->importer->preview($source ?? $this->sourceFor($import));

        $import->update([
            'status' => LegacyImport::PREVIEWED,
            'total_rows' => $summary['total_rows'],
            'will_create' => $summary['will_create'],
            'will_update' => $summary['will_update'],
            'rejected' => $summary['rejected'],
            'breakdown' => $summary['breakdown'],
            'rejects' => $summary['rejects'],
        ]);

        Audit::log('legacy_import.previewed', $import, $this->auditFacts($import));

        return $import;
    }

    /** The real run. Never throws: a failure is recorded on the import. */
    public function run(LegacyImport $import, ?RowSource $source = null): LegacyImport
    {
        $import->update(['status' => LegacyImport::RUNNING, 'started_at' => now(), 'error' => null]);

        try {
            $summary = $this->importer->run(
                $source ?? $this->sourceFor($import),
                fn (int $processed) => $import->update(['processed_rows' => $processed]),
            );

            $import->update([
                'status' => LegacyImport::COMPLETED,
                'finished_at' => now(),
                'total_rows' => $summary['total_rows'],
                'processed_rows' => $summary['total_rows'],
                'created_count' => $summary['will_create'],
                'updated_count' => $summary['will_update'],
                'rejected' => $summary['rejected'],
                'breakdown' => $summary['breakdown'],
                'rejects' => $summary['rejects'],
            ]);
            Audit::log('legacy_import.completed', $import, $this->auditFacts($import) + [
                'created' => $import->created_count,
                'updated' => $import->updated_count,
            ]);
        } catch (Throwable $e) {
            $import->update([
                'status' => LegacyImport::FAILED,
                'finished_at' => now(),
                'error' => $e instanceof SourceUnreadable
                    ? $e->getMessage()
                    : 'The import stopped after '.$import->processed_rows.' rows: '.$e->getMessage()
                        .' Rows already written are kept; running the same file again finishes it without duplicating them.',
            ]);
            Audit::log('legacy_import.failed', $import, $this->auditFacts($import) + ['error' => $import->error]);
            report($e);
        } finally {
            $this->forgetFile($import);
        }

        return $import->fresh();
    }

    public function forgetFile(LegacyImport $import): void
    {
        if ($import->stored_path && Storage::disk('local')->exists($import->stored_path)) {
            Storage::disk('local')->delete($import->stored_path);
        }
        if ($import->stored_path) {
            $import->update(['stored_path' => null]);
        }
    }

    /** @return array<string, mixed> who ran it is on the audit row itself */
    private function auditFacts(LegacyImport $import): array
    {
        return array_filter([
            // An import with no user was run from the command line, which has
            // no signed-in person to name; say so rather than leave it blank.
            'via' => $import->user_id === null ? 'artisan biztrack:import-legacy' : null,
        ]) + [
            'source' => $import->source,
            'file_name' => $import->file_name,
            'total_rows' => $import->total_rows,
            'will_create' => $import->will_create,
            'will_update' => $import->will_update,
            'rejected' => $import->rejected,
        ];
    }
}
