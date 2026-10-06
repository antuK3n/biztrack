<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Department;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Support\DefenseAccounts\AppClient;
use App\Support\DefenseAccounts\Clock;
use App\Support\DefenseAccounts\Playbook;
use App\Support\DefenseAccounts\Roster;
use App\Support\DefenseAccounts\StepRefused;
use App\Support\PdfFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The defense accounts: five real owner accounts for each of sixteen states.
 *
 * ── What Ken asked for ──────────────────────────────────────────────────────
 *
 * 5 October 2026: "NONE OF THESE IS TO BE DUMMY DATA. ALL DATA AND ALL
 * ACCOUNTS HERE SHOULD ACTUALLY BE ACCESSIBLE, maybe 5 for each scenario. We
 * need a PDF file of all the accounts you will make."
 *
 * So every account signs in with one shared password, and every filing got
 * where it is the way a real one would: the owner signed up and confirmed the
 * address, the wizard saved the business and the draft, the documents were
 * uploaded, BPLO approved and ticked the other permits, BPLO marked the bill
 * paid at the counter, each office approved, booked its visit and recorded
 * it. Each step is a request through the API as that person — see AppClient
 * for why, and Playbook for the steps. The office work is done AS the office
 * accounts already in the database (no staff account is created), and the
 * blacklisting as the super admin.
 *
 * ── Safe to run twice ───────────────────────────────────────────────────────
 *
 * An owner whose e-mail is already registered is skipped and reported, never
 * touched. Each owner is built inside one transaction, so a step the rules
 * refuse leaves nothing of that owner behind — not an account half-made that
 * the next run would then skip as "done". The refusal is reported with the
 * app's own sentence; a scenario the rules will not produce is not forced.
 *
 * ── Nothing leaves the machine while it runs ────────────────────────────────
 *
 * Every owner here is an alias of one inbox (kenjohnvianneym+bt-…@gmail.com)
 * and every mobile is in 0999 000 0001…, numbers nobody was asked about. A
 * full run writes several hundred owner notices, and each is also an e-mail
 * and often an SMS. Sent for real that is hundreds of mails into one inbox —
 * enough to spend a free relay's daily quota, after which the sign-in codes
 * real testers need stop arriving — and texts to whoever holds those numbers.
 * So for this process only the mailer and the SMS driver are `log` and the
 * queue is `sync` (so a queued copy is written here, to the log, rather than
 * handed to the server's worker with the real settings). The in-app notices
 * are written as usual.
 *
 * That includes the one step that acts on the whole register: the expired
 * scenarios run `biztrack:scan-permits`, the scheduler's midnight job, to
 * flip their 2025 permits to Expired the way it flips anyone's. It runs
 * quiet too, and that costs the rest of the register nothing: the scheduler
 * already ran it at midnight today, so every reminder it would send another
 * owner today is already marked sent (`permit_expiry_notices`), and the only
 * rows it changes are these owners' permits — whose lapse is months past
 * the 30 days in which it notifies anyone. (Its notices would in any case be
 * queued after the owner's transaction commits, by which time the real
 * settings could not be put back around them.)
 */
class SeedDefenseAccounts extends Command
{
    protected $signature = 'biztrack:seed-defense-accounts
        {--password= : The one password every generated owner signs in with (required)}
        {--email-base=kenjohnvianneym@gmail.com : The inbox each owner address is an alias of}
        {--per-scenario=5 : Owners per scenario}
        {--dry-run : Print the plan and write nothing}
        {--pdf= : Write the accounts PDF to this path}';

    protected $description = 'Build the owner accounts: real, sign-in-able owners in sixteen states, through the app’s own rules.';

    /** The role each office's account holds, by department code. */
    public const OFFICE_ROLES = [
        'BPLO' => 'bplo_staff',
        'CHO' => 'sanitary_officer',
        'BFP' => 'fire_inspector',
        'CPDO' => 'zoning_officer',
        'OBO' => 'obo_staff',
        'CENRO' => 'cenro_officer',
    ];

    public function handle(): int
    {
        $started = microtime(true);
        $password = (string) $this->option('password');
        $per = (int) $this->option('per-scenario');
        $base = (string) $this->option('email-base');
        $dry = (bool) $this->option('dry-run');

        if ($password === '' || strlen($password) < 8) {
            $this->error('Give the shared owner password with --password= (8 characters or more).');

            return self::FAILURE;
        }
        if ($per < 1 || ! filter_var($base, FILTER_VALIDATE_EMAIL)) {
            $this->error('--per-scenario must be 1 or more and --email-base an e-mail address.');

            return self::FAILURE;
        }

        $offices = self::offices();
        $admin = User::where('is_active', true)->whereHas('roles', fn ($r) => $r->where('name', 'admin'))->orderBy('id')->first();
        if (count($offices) < count(self::OFFICE_ROLES) || $admin === null) {
            $this->error('Each of the six offices needs an active account, and there must be an active super admin. Missing: '
                .implode(', ', array_diff(array_keys(self::OFFICE_ROLES), array_keys($offices))).($admin === null ? ', super admin' : '').'.');

            return self::FAILURE;
        }

        $this->line('Office accounts: '.collect($offices)->map(fn (User $u, $d) => "{$d} {$u->email}")->implode(' · ')." · super admin {$admin->email}");

        $owners = (new Roster)->owners($base, $per);
        $existing = User::withTrashed()->whereIn('email', array_column($owners, 'email'))->pluck('email')->all();

        if ($dry) {
            $this->table(['#', 'Scenario', 'E-mail', 'Owner', 'Business', 'Barangay', 'Pin', 'Ticks', 'Plan'], array_map(fn (array $o) => [
                $o['index'], $o['scenario'], $o['email'], "{$o['first_name']} {$o['last_name']}", $o['business']['name'],
                $o['business']['barangay'], $o['business']['pin'] ? implode(',', $o['business']['pin']) : 'none green',
                implode(' ', $o['business']['ticks']), in_array($o['email'], $existing, true) ? 'exists: skip' : 'build',
            ], $owners));
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
        $playbook = new Playbook($api, $clock, $offices, $admin, $password, fn () => Artisan::call('biztrack:scan-permits'));

        $built = $skipped = [];
        $failed = [];
        try {
            foreach ($owners as $plan) {
                if (in_array($plan['email'], $existing, true)) {
                    $skipped[] = $plan['email'];
                    $this->line("  skip   {$plan['email']} (already registered)");

                    continue;
                }
                if ($plan['business']['pin'] === null) {
                    $failed[] = [$plan['scenario'], $plan['email'], 'no green zoning pin for any trade in '.$plan['business']['barangay']];

                    continue;
                }

                try {
                    DB::transaction(fn () => $playbook->play($plan));
                    $built[] = $plan['email'];
                    $this->line("  built  {$plan['email']}  {$plan['business']['name']}");
                } catch (Throwable $e) {
                    $api->dropCachedTokens();
                    foreach ($playbook->applicationIds as $id) {
                        Storage::disk('local')->deleteDirectory("private/documents/{$id}");
                    }
                    $kind = $e instanceof StepRefused && $e->byRule() ? 'refused' : 'failed';
                    $failed[] = [$plan['scenario'], $plan['email'], "{$kind}: ".$e->getMessage()];
                    $this->warn("  {$kind} {$plan['email']}: ".$e->getMessage());
                }
            }
        } finally {
            $clock->release();
            $tokens = $api->forgetTokens();
            config($realDelivery);
        }

        $this->newLine();
        $this->table(['Scenario', 'Built', 'Skipped', 'Not built'], collect(array_keys(Roster::SCENARIOS))->map(fn (string $slug) => [
            $slug,
            count(array_filter($built, fn ($e) => str_contains($e, "+bt-{$slug}-"))),
            count(array_filter($skipped, fn ($e) => str_contains($e, "+bt-{$slug}-"))),
            count(array_filter($failed, fn ($f) => $f[0] === $slug)),
        ])->all());
        foreach ($failed as [$slug, $email, $why]) {
            $this->error("{$slug} {$email}: {$why}");
        }
        $this->line(sprintf('%d built, %d skipped, %d not built; %d sign-in tokens used by the run deleted; %.1f s, %d MB.',
            count($built), count($skipped), count($failed), $tokens, microtime(true) - $started, memory_get_peak_usage(true) >> 20));

        if ($pdf = $this->option('pdf')) {
            $this->writePdf((string) $pdf, $owners, $offices, $password);
            $this->info("PDF written to {$pdf}");
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The six office accounts, found by what they are rather than by address:
     * the oldest active account of each department holding that office's
     * role. On the live server those are bplo@ … cpdo@biztrack.page; on a
     * seeded copy, the demo office accounts.
     *
     * @return array<string, User>
     */
    public static function offices(): array
    {
        $out = [];
        foreach (self::OFFICE_ROLES as $code => $role) {
            $user = User::where('is_active', true)
                ->where('department_id', Department::where('code', $code)->value('id'))
                ->whereHas('roles', fn ($r) => $r->where('name', $role))
                ->orderBy('id')
                ->first();
            if ($user !== null) {
                $out[$code] = $user;
            }
        }

        return $out;
    }

    /**
     * The roster PDF, read back from the register so a second run lists the
     * same accounts the first one made.
     *
     * @param  list<array<string, mixed>>  $owners
     * @param  array<string, User>  $offices
     */
    private function writePdf(string $path, array $owners, array $offices, string $password): void
    {
        $sections = [];
        foreach (Roster::SCENARIOS as $slug => [$title, $state]) {
            $rows = [];
            foreach (array_filter($owners, fn ($o) => $o['scenario'] === $slug) as $plan) {
                $user = User::where('email', $plan['email'])->first();
                if ($user === null) {
                    continue;
                }
                $application = Application::with('business')->where('applicant_user_id', $user->id)->orderByDesc('id')->first();
                $permit = Permit::whereHas('business', fn ($b) => $b->where('owner_user_id', $user->id))
                    ->whereHas('permitType', fn ($t) => $t->where('code', PermitType::OUTCOME_CODE))
                    ->orderByDesc('id')->value('permit_number');
                $rows[] = [
                    'n' => $plan['n'],
                    'name' => trim("{$user->first_name} {$user->last_name}"),
                    'email' => $user->email,
                    'business' => $application?->business?->name ?? '—',
                    'reference' => collect([$application?->tracking_id, $permit])->filter()->implode(' / ') ?: '—',
                    'state' => $state,
                ];
            }
            $sections[] = ['title' => $title, 'rows' => $rows];
        }

        $pdf = Pdf::loadView('pdf.defense-accounts', [
            'password' => $password,
            'offices' => collect($offices)->map(fn (User $u, string $code) => ['office' => $code, 'email' => $u->email])->values()->all(),
            'sections' => $sections,
            'generated' => now('Asia/Manila')->format('j F Y, g:i A'),
        ])->setPaper('a4');
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $canvas->page_text(500, 815, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.4, 0.4, 0.4]);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, PdfFile::render($pdf)->content);
    }
}
