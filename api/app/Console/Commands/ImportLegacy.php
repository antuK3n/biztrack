<?php

namespace App\Console\Commands;

use App\Models\LegacyImport;
use App\Support\LegacyImport\ImportRunner;
use App\Support\LegacyImport\Sources\CsvSource;
use App\Support\LegacyImport\Sources\RowSource;
use App\Support\LegacyImport\SourceUnreadable;
use Illuminate\Console\Command;

/**
 * The import screen from the command line — for MISD, for a file too large to
 * upload, or for a scripted cut-over.
 *
 *   php artisan biztrack:import-legacy businesses.csv --dry-run
 *   php artisan biztrack:import-legacy businesses.csv
 *
 * Same pipeline, same `legacy_imports` row and the same audit entries as the
 * screen; the audit rows name the command in place of a user. Runs in the
 * foreground whatever the size — nobody is waiting on a request timeout here.
 * Without --dry-run it previews first and imports only after a confirmation,
 * unless --force is given.
 */
class ImportLegacy extends Command
{
    protected $signature = 'biztrack:import-legacy
        {file? : A CSV in BizTrack\'s import template}
        {--dry-run : Validate and report; write nothing}
        {--force : Import without asking to confirm the dry run}';

    protected $description = 'Import businesses and permits from the old register (CSV), with a dry run.';

    public function handle(ImportRunner $runner): int
    {
        try {
            [$source, $import] = $this->source();
        } catch (SourceUnreadable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $runner->preview($import, $source);
        } catch (SourceUnreadable $e) {
            $import->delete();
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $import->refresh();
        $this->report($import);

        if ($this->option('dry-run')) {
            $this->info('Dry run only — nothing was written.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Import these rows now?', false)) {
            $this->info('Not imported.');

            return self::SUCCESS;
        }

        $import->update(['status' => LegacyImport::QUEUED]);
        $import = $runner->run($import, $source);

        if ($import->status !== LegacyImport::COMPLETED) {
            $this->error((string) $import->error);

            return self::FAILURE;
        }

        $this->info("Imported: {$import->created_count} rows created something new, {$import->updated_count} updated, {$import->rejected} rejected.");

        return self::SUCCESS;
    }

    /** @return array{0: RowSource, 1: LegacyImport} */
    private function source(): array
    {
        $file = $this->argument('file');
        if (! $file || ! is_file($file)) {
            throw new SourceUnreadable($file ? "No file at {$file}." : 'Name a CSV file.');
        }

        return [new CsvSource($file, basename($file)), LegacyImport::create([
            'source' => 'csv',
            'file_name' => basename($file),
        ])];
    }

    private function report(LegacyImport $import): void
    {
        $b = $import->breakdown ?? [];
        $this->table(['', 'Rows'], [
            ['Rows read', $import->total_rows],
            ['Will create', $import->will_create],
            ['Will update', $import->will_update],
            ['Rejected', $import->rejected],
        ]);
        $this->line(sprintf(
            'Businesses: %d new, %d updated. Permits: %d new, %d updated. Owners: %d linked to an account, %d left to claim.',
            $b['businesses_new'] ?? 0, $b['businesses_updated'] ?? 0,
            $b['permits_new'] ?? 0, $b['permits_updated'] ?? 0,
            $b['owners_linked'] ?? 0, $b['owners_unclaimed'] ?? 0,
        ));

        $rejects = $import->rejects ?? [];
        if ($rejects !== []) {
            $this->warn('Rejected rows'.($import->rejected > count($rejects) ? ' (first '.count($rejects).')' : '').':');
            $this->table(['Row', 'Business', 'Why'], array_map(fn ($r) => [
                $r['row'],
                $r['legacy_business_id'] ?? '—',
                implode(' ', array_column($r['reasons'], 'message')),
            ], $rejects));
        }
    }
}
