<?php

namespace App\Jobs;

use App\Models\LegacyImport;
use App\Support\LegacyImport\ImportRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;

/**
 * Run a confirmed legacy import off the request.
 *
 * Only a large import is queued (LegacyImportController::QUEUE_ABOVE rows);
 * a small one runs while the super admin waits, because a queue worker is one
 * more thing that has to be running for the button to do anything. This needs
 * `php artisan queue:work` — the production compose file already runs one.
 *
 * One try and a generous timeout: an import is not something to retry blind.
 * Rows are keyed on their legacy ids, so a person re-running a failed import
 * is safe; a worker silently re-running it three times is just slower.
 */
class RunLegacyImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $importId) {}

    public function handle(ImportRunner $runner): void
    {
        $import = LegacyImport::find($this->importId);
        if ($import === null || $import->status !== LegacyImport::QUEUED) {
            return;
        }

        // The audit rows this writes belong to the person who confirmed it,
        // not to "nobody", which is what a worker's empty guard would say.
        if ($import->user !== null) {
            Auth::setUser($import->user);
        }

        $runner->run($import);
    }
}
