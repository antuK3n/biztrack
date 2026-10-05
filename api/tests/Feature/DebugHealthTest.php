<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Jobs\QueueHeartbeat;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\User;
use App\Support\DebugPanel;
use App\Support\Heartbeat;
use App\Support\PaymentMode;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * The Debug page's Health section (App\Support\SystemHealth): every check
 * says ok, warn or fail, and when its evidence is from. Behind the same gate
 * as the rest of the panel.
 */

beforeEach(function () {
    Http::preventStrayRequests();
});

/** The checks as the page gets them, keyed by check. */
function healthChecks(): array
{
    DebugPanel::open(1);
    authAs('admin@biztrack.local');

    return collect(test()->getJson('/api/v1/debug/health')->assertOk()->json('data.checks'))
        ->keyBy('key')
        ->all();
}

it('is behind the panel: 404 while it is closed, and to anyone but the super admin', function () {
    authAs('admin@biztrack.local');
    $this->getJson('/api/v1/debug/health')->assertNotFound();
    $this->postJson('/api/v1/debug/health/test-mail')->assertNotFound();

    DebugPanel::open(1);
    authAs('bplo@biztrack.local');
    $this->getJson('/api/v1/debug/health')->assertNotFound();
    $this->postJson('/api/v1/debug/health/test-mail')->assertNotFound();
});

it('answers every check with ok, warn or fail and when it was seen', function () {
    $checks = healthChecks();

    expect(array_keys($checks))->toBe([
        'database', 'migrations', 'scheduler', 'queue', 'failed_jobs',
        'mail', 'gateway', 'reconcile', 'version', 'disk',
    ]);
    foreach ($checks as $check) {
        expect($check['status'])->toBeIn(['ok', 'warn', 'fail'])
            ->and($check['summary'])->not->toBe('')
            ->and($check['label'])->not->toBe('');
    }
    expect($checks['database']['status'])->toBe('ok')
        ->and($checks['database']['summary'])->toContain('sqlite')
        ->and($checks['migrations']['status'])->toBe('ok')
        ->and($checks['migrations']['seen_at'])->not->toBeNull();
});

it('fails the scheduler until it beats, and lets a beat age from ok to warn to fail', function () {
    expect(healthChecks()['scheduler'])->toMatchArray(['status' => 'fail', 'seen_at' => null]);

    Heartbeat::beat(Heartbeat::SCHEDULER);
    expect(healthChecks()['scheduler']['status'])->toBe('ok');

    $this->travel(5)->minutes();
    expect(healthChecks()['scheduler']['status'])->toBe('warn');

    $this->travel(10)->minutes();
    expect(healthChecks()['scheduler']['status'])->toBe('fail');
});

it('gets its heartbeats from the real schedule: the scheduler, a queued job, and the payment checks', function () {
    config(['queue.default' => 'sync']);
    $events = collect(app(Schedule::class)->events());

    /*
     * The two in-process events are run here as the scheduler would run them.
     * Not `schedule:run`: that starts every command event as a separate
     * `php artisan` process, which would not see this test's database.
     */
    $beat = $events->first(fn ($e) => $e->description === 'health:scheduler-heartbeat');
    $queued = $events->first(fn ($e) => $e->description === QueueHeartbeat::class);
    $reconcile = $events->first(fn ($e) => str_contains((string) $e->command, 'biztrack:reconcile-payments'));
    expect($beat)->not->toBeNull()
        ->and($queued)->not->toBeNull()
        ->and($reconcile?->expression)->toBe('* * * * *')
        ->and($beat->expression)->toBe('* * * * *')
        ->and($queued->expression)->toBe('* * * * *');

    $beat->run(app());
    $queued->run(app());
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();

    expect(Heartbeat::last(Heartbeat::SCHEDULER))->not->toBeNull()
        ->and(Heartbeat::last(Heartbeat::QUEUE))->not->toBeNull()
        ->and(Heartbeat::last(Heartbeat::RECONCILE))->toMatchArray(['asked' => 0, 'settled' => 0]);
});

it('needs no worker on a sync queue, and otherwise wants one to have answered lately', function () {
    config(['queue.default' => 'sync']);
    expect(healthChecks()['queue']['status'])->toBe('ok');

    config(['queue.default' => 'database']);
    $queue = healthChecks()['queue'];
    expect($queue['status'])->toBe('fail')
        ->and($queue['summary'])->toContain('queue:work');

    // What a worker does when it takes the scheduler's heartbeat off the queue.
    (new QueueHeartbeat)->handle();
    expect(healthChecks()['queue']['status'])->toBe('ok');

    $this->travel(11)->minutes();
    expect(healthChecks()['queue']['status'])->toBe('fail');
});

it('counts failed jobs', function () {
    expect(healthChecks()['failed_jobs']['status'])->toBe('ok');

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'Boom', 'failed_at' => now(),
    ]);

    expect(healthChecks()['failed_jobs'])->toMatchArray(['status' => 'warn'])
        ->and(healthChecks()['failed_jobs']['summary'])->toContain('1 failed job');
});

it('warns that mail goes nowhere on log or array, and never shows the mail password', function () {
    expect(healthChecks()['mail']['status'])->toBe('warn');

    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp-relay.brevo.com',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.username' => 'relay-user-not-real',
        'mail.mailers.smtp.password' => 'relay-password-not-real',
        'mail.from.address' => 'bplo@malabon.gov.ph',
    ]);
    $mail = healthChecks()['mail'];
    expect($mail['status'])->toBe('ok')
        ->and($mail['summary'])->toContain('smtp-relay.brevo.com:587')
        ->and(json_encode($mail))->not->toContain('relay-password-not-real')
        ->not->toContain('relay-user-not-real');

    config(['mail.from.address' => 'hello@example.com']);
    expect(healthChecks()['mail']['status'])->toBe('fail');
});

it('sends the test e-mail to the super admin only when mail really goes somewhere, and audits it', function () {
    DebugPanel::open(1);
    authAs('admin@biztrack.local');
    $admin = User::where('email', 'admin@biztrack.local')->firstOrFail();

    // The test suite's mailer is `array`: accepted, delivered to nobody, and said so.
    $nowhere = $this->postJson('/api/v1/debug/health/test-mail')->assertOk();
    expect($nowhere->json('data.ok'))->toBeFalse()
        ->and($nowhere->json('data.message'))->toContain('the mail driver is array');

    // A mailer that counts as real, captured in memory instead of sent.
    config([
        'mail.mailers.capture' => ['transport' => 'array'],
        'mail.default' => 'capture',
        'mail.from.address' => 'bplo@malabon.gov.ph',
    ]);
    $sent = $this->postJson('/api/v1/debug/health/test-mail')->assertOk();
    expect($sent->json('data.ok'))->toBeTrue()
        ->and($sent->json('data.message'))->toContain($admin->email);

    $messages = app('mail.manager')->mailer('capture')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getEnvelope()->getRecipients()[0]->getAddress())->toBe($admin->email);

    $rows = AuditLog::where('action', 'debug.health.test_mail')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[1]->user_id)->toBe($admin->id)
        ->and($rows[1]->changes['after'])->toMatchArray(['to' => $admin->email, 'mailer' => 'capture', 'ok' => true]);
});

it('checks the KwikPay connection only when there is one to check, and fails it only while owners use it', function () {
    config(['payments.kwikpay.key' => null]);
    // Without "payments are simulated" after it (Ken, 5 October 2026).
    expect(healthChecks()['gateway'])->toMatchArray(['status' => 'ok', 'summary' => 'Not set up, and not needed.']);

    config([
        'payments.kwikpay.base_url' => 'https://kwikpay.test',
        'payments.kwikpay.merchant' => 'M100001',
        'payments.kwikpay.key' => 'test-merchant-key-not-real',
        'payments.kwikpay.payment_type' => '1',
    ]);
    $allowlisted = true;
    Http::fake(['https://kwikpay.test/api/me' => function (HttpRequest $r) use (&$allowlisted) {
        return $allowlisted
            ? Http::response(['merchant' => $r['merchant'], 'merchant_display_name' => 'Malabon BPLO'])
            : Http::response(['status' => '1', 'message' => 'IP not found in allowed IPs'], 403);
    }]);

    expect(healthChecks()['gateway']['status'])->toBe('ok');

    $allowlisted = false;
    expect(healthChecks()['gateway']['status'])->toBe('warn');
    PaymentMode::set(PaymentMode::KWIKPAY);
    $gateway = healthChecks()['gateway'];
    expect($gateway['status'])->toBe('fail')
        ->and(json_encode($gateway))->not->toContain('test-merchant-key-not-real');
});

it('fails the payment checks when online payments wait and nothing has asked about them lately', function () {
    $application = Application::whereHas('feeAssessment')->firstOrFail();
    Payment::create([
        'application_id' => $application->id,
        'fee_assessment_id' => $application->feeAssessment->id,
        'reference_number' => 'PAY-HEALTH-1',
        'amount' => 100,
        'gateway_amount' => 50,
        'method' => PaymentMethod::Gcash,
        'status' => PaymentStatus::Pending,
        'gateway' => Payment::GATEWAY_KWIKPAY,
        'gateway_order_id' => 'PAY-HEALTH-1-ABCDEF',
        'next_check_at' => now()->addHour(),
    ]);

    $never = healthChecks()['reconcile'];
    expect($never['status'])->toBe('fail')->and($never['summary'])->toContain('1 online payment(s) waiting');

    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();
    $ran = healthChecks()['reconcile'];
    expect($ran['status'])->toBe('ok')->and($ran['seen_at'])->not->toBeNull();

    $this->travel(6)->minutes();
    expect(healthChecks()['reconcile']['status'])->toBe('fail');
});

it('names the build when one is stamped, and says how much disk is free', function () {
    config(['app.version' => 'v2026.10.04']);
    $checks = healthChecks();

    expect($checks['version'])->toMatchArray(['status' => 'ok', 'summary' => 'Build v2026.10.04 (APP_VERSION).'])
        ->and($checks['disk']['summary'])->toContain(' free of ');
});

it('keeps the test e-mail to three a minute without counting the page loads against it', function () {
    DebugPanel::open(1);
    authAs('admin@biztrack.local');

    foreach (range(1, 5) as $_) {
        $this->getJson('/api/v1/debug/health')->assertOk();
    }
    foreach (range(1, 3) as $_) {
        $this->postJson('/api/v1/debug/health/test-mail')->assertOk();
    }
    $this->postJson('/api/v1/debug/health/test-mail')->assertStatus(429);
});
