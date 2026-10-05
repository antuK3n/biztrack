<?php

namespace App\Support;

use App\Models\Payment;
use App\Services\KwikPay\KwikPayGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Debug page's Health section: is everything this server needs actually
 * running? Read-only; nothing here changes anything.
 *
 * Each check answers `ok`, `warn` or `fail`, one plain sentence, and
 * `seen_at`: when the evidence is from. For a heartbeat that is when the
 * process last ran, which is the point — "the scheduler is fine" is only true
 * as of its last beat. For a live check it is now.
 *
 * A check that throws is reported as `fail` with its message rather than
 * taking the page down: the moment somebody opens Health is exactly the
 * moment something may be broken, and one broken thing must not hide the
 * others.
 *
 * Never a secret. The mail check names the host and the sender, never the
 * username or password; the gateway check is KwikPayGateway::testConnection,
 * which never returns the key.
 */
class SystemHealth
{
    /** A heartbeat this fresh is fine; one between this and STALE is a warning. */
    private const FRESH_MINUTES = 3;

    /** Older than this and the process is treated as stopped. */
    private const STALE_MINUTES = 10;

    /** Pending online payments with no reconcile run for this long is a failure. */
    private const RECONCILE_STALE_MINUTES = 5;

    public function __construct(private KwikPayGateway $gateway) {}

    /** @return list<array{key: string, label: string, status: string, summary: string, seen_at: ?string}> */
    public function checks(): array
    {
        $checks = [
            'database' => ['Database', fn () => $this->database()],
            'migrations' => ['Migrations', fn () => $this->migrations()],
            'scheduler' => ['Scheduler', fn () => $this->scheduler()],
            'queue' => ['Queue worker', fn () => $this->queue()],
            'failed_jobs' => ['Failed jobs', fn () => $this->failedJobs()],
            'mail' => ['E-mail', fn () => $this->mail()],
            'gateway' => ['KwikPay connection', fn () => $this->gatewayConnection()],
            'reconcile' => ['Payment checks', fn () => $this->reconcile()],
            'version' => ['Version', fn () => $this->version()],
            'disk' => ['Disk space', fn () => $this->disk()],
        ];

        $out = [];
        foreach ($checks as $key => [$label, $check]) {
            try {
                [$status, $summary, $seenAt] = $check() + [2 => now()];
            } catch (\Throwable $e) {
                [$status, $summary, $seenAt] = ['fail', 'Could not check: '.$e->getMessage(), now()];
            }
            $out[] = [
                'key' => $key,
                'label' => $label,
                'status' => $status,
                'summary' => $summary,
                'seen_at' => $seenAt?->toIso8601String(),
            ];
        }

        return $out;
    }

    /** @return array{0: string, 1: string} */
    private function database(): array
    {
        $started = microtime(true);
        DB::select('select 1');
        $ms = (int) round((microtime(true) - $started) * 1000);

        return ['ok', 'Connected to '.DB::connection()->getDriverName()." in {$ms} ms."];
    }

    /** @return array{0: string, 1: string} */
    private function migrations(): array
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return ['fail', 'The migrations table is missing. Run php artisan migrate.'];
        }

        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        $pending = array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));

        if ($pending === []) {
            return ['ok', 'All '.count($files).' migrations have run.'];
        }

        return ['warn', count($pending).' not run yet: '.implode(', ', array_slice($pending, 0, 3)).(count($pending) > 3 ? ', …' : '').'. Run them one at a time (AGENTS.md §2.2).'];
    }

    /** @return array{0: string, 1: string, 2: ?CarbonImmutable} */
    private function scheduler(): array
    {
        $beat = Heartbeat::last(Heartbeat::SCHEDULER);
        if ($beat === null) {
            return ['fail', 'Never seen. Is php artisan schedule:work (or a cron entry for schedule:run) running?', null];
        }

        return [self::age($beat['at']), 'Last ran '.$beat['at']->diffForHumans().'. It runs every minute; payment checks depend on it.', $beat['at']];
    }

    /** @return array{0: string, 1: string, 2?: ?CarbonImmutable} */
    private function queue(): array
    {
        $connection = (string) config('queue.default');
        if ($connection === 'sync') {
            return ['ok', 'No worker needed: jobs run the moment they are dispatched (QUEUE_CONNECTION=sync).'];
        }

        $waiting = $connection === 'database' && Schema::hasTable('jobs')
            ? ' '.DB::table('jobs')->count().' job(s) waiting.'
            : '';
        $beat = Heartbeat::last(Heartbeat::QUEUE);
        if ($beat === null) {
            return ['fail', 'No worker has answered yet. Is php artisan queue:work running?'.$waiting, null];
        }

        return [
            self::age($beat['at']),
            'A worker last answered '.$beat['at']->diffForHumans().'. The scheduler sends it a heartbeat every minute, so this also goes stale if the scheduler stops.'.$waiting,
            $beat['at'],
        ];
    }

    /** @return array{0: string, 1: string} */
    private function failedJobs(): array
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');
        if (! Schema::hasTable($table)) {
            return ['warn', "There is no {$table} table, so a failed job would leave no trace."];
        }

        $count = DB::table($table)->count();

        return $count === 0
            ? ['ok', 'None.']
            : ['warn', "{$count} failed job(s). php artisan queue:failed lists them."];
    }

    /** @return array{0: string, 1: string} */
    private function mail(): array
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        if (! EmailSwitch::on()) {
            return ['warn', "Not sent anywhere: the mail driver is {$mailer}. Sign-in codes and address confirmation stay off until a real mailer is set (docs/email-setup.md)."];
        }

        if ($from === '' || $from === 'hello@example.com') {
            return ['fail', "The mail driver is {$mailer}, but MAIL_FROM_ADDRESS is not set to a real sender."];
        }

        if ((string) config("mail.mailers.{$mailer}.transport") === 'smtp') {
            $host = (string) config("mail.mailers.{$mailer}.host");
            $port = (string) config("mail.mailers.{$mailer}.port");
            if (trim($host) === '' || in_array($host, ['127.0.0.1', 'localhost'], true)) {
                return ['warn', "SMTP points at {$host}:{$port}, which is this machine. Is a relay running here?"];
            }

            return ['ok', "SMTP through {$host}:{$port}, from {$from}. Use the button to prove it delivers."];
        }

        return ['ok', "Sent with {$mailer}, from {$from}. Use the button to prove it delivers."];
    }

    /** @return array{0: string, 1: string} */
    private function gatewayConnection(): array
    {
        $online = PaymentMode::isKwikPay();

        if (! PaymentMode::kwikPayConfigured()) {
            return $online
                ? ['fail', 'Owners pay through KwikPay, but these are missing on the server: '.implode(', ', PaymentMode::missingCredentials()).'.']
                : ['ok', 'Not set up, and not needed.'];
        }

        $result = $this->gateway->testConnection();
        if ($result['ok']) {
            return ['ok', $result['message']];
        }

        // Broken but unused is a warning; broken while owners are paying is not.
        return [$online ? 'fail' : 'warn', $result['message']];
    }

    /** @return array{0: string, 1: string, 2: ?CarbonImmutable} */
    private function reconcile(): array
    {
        $awaiting = Payment::query()->awaitingKwikPay();
        $pending = (clone $awaiting)->count();
        $flagged = (clone $awaiting)->whereNotNull('flagged_at')->count();
        $waiting = "{$pending} online payment(s) waiting, {$flagged} of them flagged for staff.";

        $beat = Heartbeat::last(Heartbeat::RECONCILE);
        if ($beat === null) {
            return [$pending > 0 ? 'fail' : 'ok', 'Never run. '.$waiting, null];
        }

        $ran = 'Last ran '.$beat['at']->diffForHumans().' (asked about '.($beat['asked'] ?? 0).', settled '.($beat['settled'] ?? 0).'). ';
        $stale = $beat['at']->lt(now()->subMinutes(self::RECONCILE_STALE_MINUTES));

        $status = match (true) {
            $pending > 0 && $stale => 'fail',
            $flagged > 0 => 'warn',
            default => 'ok',
        };

        return [$status, $ran.$waiting, $beat['at']];
    }

    /** @return array{0: string, 1: string} */
    private function version(): array
    {
        $stamp = trim((string) config('app.version', ''));
        if ($stamp !== '') {
            return ['ok', "Build {$stamp} (APP_VERSION)."];
        }

        if (is_file($file = base_path('REVISION')) && ($revision = trim((string) file_get_contents($file))) !== '') {
            return ['ok', "Build {$revision} (REVISION file)."];
        }

        if (($git = self::gitHead(dirname(base_path()))) !== null) {
            return ['ok', 'Commit '.substr($git['sha'], 0, 7).($git['branch'] ? " on {$git['branch']}" : '').'.'];
        }

        return ['warn', 'Unknown: no APP_VERSION, no REVISION file and no git checkout to read.'];
    }

    /** @return array{0: string, 1: string} */
    private function disk(): array
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return ['warn', 'This server does not report its disk space.'];
        }

        $percent = $free / $total * 100;
        $summary = self::bytes($free).' free of '.self::bytes($total).' ('.number_format($percent, 0).'%).';

        $status = match (true) {
            $percent < 2 || $free < 200 * 1024 ** 2 => 'fail',
            $percent < 10 || $free < 1024 ** 3 => 'warn',
            default => 'ok',
        };

        return [$status, $summary];
    }

    private static function age(CarbonImmutable $at): string
    {
        return match (true) {
            $at->gte(now()->subMinutes(self::FRESH_MINUTES)) => 'ok',
            $at->gte(now()->subMinutes(self::STALE_MINUTES)) => 'warn',
            default => 'fail',
        };
    }

    private static function bytes(float $bytes): string
    {
        foreach (['TB' => 1024 ** 4, 'GB' => 1024 ** 3, 'MB' => 1024 ** 2] as $unit => $size) {
            if ($bytes >= $size) {
                return number_format($bytes / $size, 1).' '.$unit;
            }
        }

        return number_format($bytes / 1024, 0).' KB';
    }

    /**
     * The checked-out commit, read from the files rather than by running git:
     * a server may not have git installed, or may forbid exec. Handles a
     * worktree, whose `.git` is a file pointing at its real directory.
     *
     * @return array{sha: string, branch: ?string}|null
     */
    private static function gitHead(string $root): ?array
    {
        $dotGit = $root.'/.git';
        if (is_file($dotGit) && preg_match('/^gitdir:\s*(.+)$/m', (string) file_get_contents($dotGit), $m)) {
            $gitDir = trim($m[1]);
            $gitDir = str_starts_with($gitDir, '/') ? $gitDir : $root.'/'.$gitDir;
        } elseif (is_dir($dotGit)) {
            $gitDir = $dotGit;
        } else {
            return null;
        }

        $head = @file_get_contents($gitDir.'/HEAD');
        if ($head === false) {
            return null;
        }
        $head = trim($head);

        if (! str_starts_with($head, 'ref: ')) {
            return preg_match('/^[0-9a-f]{40}$/', $head) ? ['sha' => $head, 'branch' => null] : null;
        }

        $ref = substr($head, 5);
        $branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : $ref;
        $common = is_file($gitDir.'/commondir')
            ? realpath($gitDir.'/'.trim((string) file_get_contents($gitDir.'/commondir'))) ?: $gitDir
            : $gitDir;

        foreach ([$gitDir, $common] as $dir) {
            if (is_file($dir.'/'.$ref)) {
                return ['sha' => trim((string) file_get_contents($dir.'/'.$ref)), 'branch' => $branch];
            }
        }
        if (is_file($common.'/packed-refs')) {
            foreach (file($common.'/packed-refs') ?: [] as $line) {
                if (str_ends_with(trim($line), ' '.$ref)) {
                    return ['sha' => (string) strtok($line, ' '), 'branch' => $branch];
                }
            }
        }

        return null;
    }
}
