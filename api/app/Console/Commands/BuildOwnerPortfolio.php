<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use App\Support\DefenseAccounts\AppClient;
use App\Support\DefenseAccounts\Clock;
use App\Support\DefenseAccounts\Portfolio;
use App\Support\DefenseAccounts\StepRefused;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Businesses for an owner account that already exists, each in the state
 * and with the history the spec file names.
 *
 * ── What Ken asked for ──────────────────────────────────────────────────────
 *
 * 6 October 2026: nine businesses on his own owner account, one in each state
 * a panel would ask to see — fully approved, offices working, for approval,
 * awaiting payment, returned, expired and not renewed, an amendment under
 * review, an open DENR requirement, suspended — every one of them got there
 * the way a real filing does, on believable office-hours dates. The account
 * itself (name, password, e-mail) is not touched; it is the owner on every
 * form, read from the account.
 *
 * The steps are SeedDefenseAccounts' machinery: requests through the API as
 * the person who would make them (AppClient), the office work as the office
 * accounts already in the register, on a moved clock (Clock). Portfolio says
 * how each step is clicked; `database/data/owner-portfolio.json` says when.
 *
 * ── Safe to run twice ───────────────────────────────────────────────────────
 *
 * A business the owner already has, by name, is skipped. Each business is
 * built in its own transaction, so a step the rules refuse leaves nothing of
 * that business behind, and the refusal is reported in the app's own words.
 *
 * ── Nothing leaves the machine while it runs ────────────────────────────────
 *
 * Mail and SMS go to the log and the queue runs here, as in
 * SeedDefenseAccounts and for the same reason: a history of several dozen
 * steps writes a notice at most of them, and the owner should not wake up to
 * forty e-mails about September. The in-app notices are written as usual.
 * The sign-in tokens the run mints are deleted at the end.
 */
class BuildOwnerPortfolio extends Command
{
    protected $signature = 'biztrack:build-owner-portfolio
        {--owner= : The e-mail of the existing owner account (required)}
        {--spec=database/data/owner-portfolio.json : The businesses and their steps}
        {--dry-run : Check the plan and print it; write nothing}';

    protected $description = 'Give an existing owner the businesses in a spec file, each played through the app’s own routes on its dates.';

    public function handle(): int
    {
        $started = microtime(true);
        $email = strtolower(trim((string) $this->option('owner')));
        $owner = User::where('email', $email)->first();
        if ($owner === null || ! $owner->hasRole('business_owner') || $owner->email_verified_at === null || ! $owner->is_active) {
            $this->error('--owner must be the e-mail of an active, verified business owner account.');

            return self::FAILURE;
        }

        $path = (string) $this->option('spec');
        $path = str_starts_with($path, '/') ? $path : base_path($path);
        $spec = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($spec) || ! isset($spec['businesses'], $spec['tin'])) {
            $this->error("Cannot read a spec with a tin and businesses from {$path}.");

            return self::FAILURE;
        }

        $offices = SeedDefenseAccounts::offices();
        if (count($offices) < count(SeedDefenseAccounts::OFFICE_ROLES)) {
            $this->error('Each of the six offices needs an active account. Missing: '
                .implode(', ', array_diff(array_keys(SeedDefenseAccounts::OFFICE_ROLES), array_keys($offices))).'.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::instance(new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')));
        $problems = [];
        foreach ($spec['businesses'] as $biz) {
            array_push($problems, ...Portfolio::problems($biz, $now));
        }

        $exists = fn (array $biz) => Business::where('owner_user_id', $owner->id)->where('name', $biz['name'])->exists();

        $this->line("Owner: {$owner->email} (user {$owner->id}) · offices: ".collect($offices)->map(fn (User $u, $d) => "{$d} {$u->email}")->implode(' · '));
        $this->table(['', 'Business', 'State', 'Barangay', 'Pin', 'Zone', 'Steps', 'From', 'To', 'Plan'], array_map(function (array $b) use ($exists) {
            $dated = array_values(array_filter($b['events'], fn ($e) => isset($e['at'])));

            return [
                $b['key'], $b['name'], $b['state'], $b['barangay'], implode(',', $b['pin']),
                Portfolio::zoneAt($b['barangay'], $b['pin']), count($b['events']),
                $dated[0]['at'] ?? '', end($dated)['at'] ?? '', $exists($b) ? 'exists: skip' : 'build',
            ];
        }, $spec['businesses']));

        foreach ($problems as $problem) {
            $this->error($problem);
        }
        if ($problems !== []) {
            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing written.');

            return self::SUCCESS;
        }

        $realDelivery = [
            'mail.default' => config('mail.default'),
            'services.sms.driver' => config('services.sms.driver'),
            'queue.default' => config('queue.default'),
        ];
        config(['mail.default' => 'log', 'services.sms.driver' => 'log', 'queue.default' => 'sync']);

        $clock = new Clock;
        $api = new AppClient($clock);
        $portfolio = new Portfolio($api, $clock, $offices, fn () => Artisan::call('biztrack:scan-permits'));

        $built = $skipped = $failed = 0;
        try {
            foreach ($spec['businesses'] as $biz) {
                if ($exists($biz)) {
                    $skipped++;
                    $this->line("  skip   {$biz['key']} {$biz['name']} (already on the account)");

                    continue;
                }
                try {
                    DB::transaction(fn () => $portfolio->play($owner, (string) $spec['tin'], $biz));
                    $built++;
                    $this->line("  built  {$biz['key']} {$biz['name']}");
                } catch (Throwable $e) {
                    $failed++;
                    $api->dropCachedTokens();
                    foreach ($portfolio->applicationIds as $id) {
                        Storage::disk('local')->deleteDirectory("private/documents/{$id}");
                    }
                    $kind = $e instanceof StepRefused && $e->byRule() ? 'refused' : 'failed';
                    $this->error("  {$kind} {$biz['key']} {$biz['name']}: ".$e->getMessage());
                } finally {
                    $clock->release();
                }
            }
        } finally {
            $clock->release();
            $tokens = $api->forgetTokens();
            config($realDelivery);
        }

        $this->newLine();
        $this->table(['Business', 'Newest filing', 'Tracking ID', 'Permits', 'First filed'],
            array_values(array_filter(array_map(fn (array $b) => Portfolio::summaryRow($owner, $b), $spec['businesses']))));
        $this->line(sprintf('%d built, %d skipped, %d not built; %d sign-in tokens used by the run deleted; %.1f s.',
            $built, $skipped, $failed, $tokens, microtime(true) - $started));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
