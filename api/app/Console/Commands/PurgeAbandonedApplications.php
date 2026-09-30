<?php

namespace App\Console\Commands;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Remove filings the applicant has stopped working on.
 *
 * ── The decision behind this ────────────────────────────────────────────────
 *
 * The client, 30 September 2026: an applicant who submits and then walks away
 * leaves a filing nobody can close, and they asked for the record to go after
 * a set number of days.
 *
 * I advised against it and was overruled, which is their call — but the shape
 * matters, so this is written to do the least destructive version of what was
 * asked. Two things make it survivable:
 *
 *  1. `Application` uses SoftDeletes. "Deleted" here means the row leaves
 *     every list and every query, and stays in the table with its payments,
 *     its audit trail and its officer decisions intact. Nothing is shredded;
 *     `restore()` brings back a filing removed by mistake, and a dispute six
 *     months later can still be answered.
 *
 *  2. It only touches filings waiting on the APPLICANT — see
 *     `ApplicationStatus::awaitsApplicant()`. A filing sitting at For Approval
 *     is waiting on BPLO, and removing it would punish a citizen for the
 *     city's backlog on the one clock RA 11032 runs against the city.
 *
 * ── Why it is a command and not a scheduled job ─────────────────────────────
 *
 * Nothing schedules it yet, deliberately. A rule that removes records should
 * be run deliberately and watched before it is left to a cron, and `--dry-run`
 * exists so the first several runs can be exactly that. Registering it in the
 * scheduler is one line in `routes/console.php` whenever the LGU has agreed
 * the window.
 */
class PurgeAbandonedApplications extends Command
{
    /**
     * `--days` has no default in the signature so the constant below is the
     * single answer, rather than a number written twice that drifts.
     */
    protected $signature = 'applications:purge-abandoned
        {--days= : Days of no movement before a filing is removed}
        {--dry-run : List what would go, and remove nothing}';

    protected $description = 'Soft-delete filings left untouched by the applicant';

    /**
     * How long is abandoned.
     *
     * 30 days, matching `RETURN_ABANDONED_DAYS` on the officer's sheet, which
     * is the only other place this system calls a filing abandoned. Two
     * different answers to "how long is too long" on one product would be a
     * question nobody could answer.
     */
    public const DAYS = 30;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: self::DAYS);
        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);
        $dry = (bool) $this->option('dry-run');

        /*
         * `updated_at`, not `created_at`. A filing edited yesterday is not
         * abandoned however long ago it was started, and every write the
         * applicant makes — an autosave, an upload, a correction — moves it.
         */
        $stale = Application::query()
            ->where('updated_at', '<', $cutoff)
            ->get()
            ->filter(fn (Application $a) => $a->status?->awaitsApplicant() === true);

        if ($stale->isEmpty()) {
            $this->info("Nothing untouched by its applicant for {$days} days.");

            return self::SUCCESS;
        }

        $this->line(($dry ? 'Would remove' : 'Removing').' '.$stale->count().' filing(s):');

        foreach ($stale as $app) {
            $this->line(sprintf(
                '  %s  %-22s last touched %s',
                $app->tracking_id ?? "#{$app->id}",
                $app->status?->value ?? 'unknown',
                $app->updated_at?->toDateString() ?? 'never',
            ));

            if ($dry) {
                continue;
            }

            /*
             * Logged BEFORE the delete, while the row is still readable, and
             * carrying the reason: an audit entry that says only "deleted"
             * leaves whoever reads it next unable to tell this sweep from a
             * person.
             */
            Audit::log('application.abandoned', $app, [
                'days_untouched' => $days,
                'status' => $app->status?->value,
                'last_touched_at' => $app->updated_at?->toIso8601String(),
            ]);

            $app->delete();
        }

        if ($dry) {
            $this->comment('Dry run — nothing was removed.');
        }

        return self::SUCCESS;
    }
}
