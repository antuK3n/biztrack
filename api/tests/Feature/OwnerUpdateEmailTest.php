<?php

use App\Enums\ApplicationStatus;
use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Jobs\SendOwnerUpdateEmail;
use App\Mail\OwnerUpdate;
use App\Models\Application;
use App\Models\AppNotification;
use App\Models\Department;
use App\Models\Inspection;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * Every update an owner is given in the app also reaches them by e-mail
 * (Ken's "Email/Text Integration" assignment, 24 September 2026), queued,
 * owners only, and never at the cost of the action that caused it.
 */

/**
 * An open filing whose owner still has an account.
 *
 * `notDecided()` beside the status list, and the list alone is not enough:
 * `approved` belongs on it — a filing gathering its other permits is live and
 * its owner is very much still being e-mailed — but since 4 October 2026 it is
 * also what a FINISHED filing wears. Without the scope this helper handed back
 * a decided filing and every test in the file failed on the first action with
 * "This application is already decided." See `Application::isDecided()`.
 */
function emailableApplication(): Application
{
    return Application::whereIn('status', [
        'for_approval', 'pending_payment', 'approved', 'for_final_approval', 'returned',
    ])->notDecided()->whereHas('applicant')->firstOrFail();
}

function rejectAsBplo(Application $app, string $reason = 'The lease contract is expired.')
{
    authAs('bplo@biztrack.local');

    return test()->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => $reason]);
}

it('queues exactly one e-mail to the owner for an owner-facing event', function () {
    Queue::fake();
    $app = emailableApplication();

    rejectAsBplo($app)->assertOk();

    Queue::assertPushed(SendOwnerUpdateEmail::class, 1);
    Queue::assertPushed(SendOwnerUpdateEmail::class, fn (SendOwnerUpdateEmail $job) => $job->owner->is($app->applicant)
        && $job->reference === $app->tracking_id
        && $job->isDisapproval === true);
});

it('queues one e-mail per in-app notice an owner is given, and none beyond', function () {
    Queue::fake();
    $app = emailableApplication();
    $owner = $app->applicant;
    $before = AppNotification::where('user_id', $owner->id)->count();

    /*
     * An explicit status rather than the filing's own, because this test is
     * about the PAIRING — one e-mail per in-app notice — and not about which
     * status raised it. `applicationStatus` deliberately stays silent on
     * Approved and Rejected, each having a dedicated notice of its own, and
     * every filing the demo seed leaves open is Approved since 4 October
     * 2026. Passing the filing's own status counted one notice where the
     * test means two, which is a fact about the fixture and not about the
     * rule being tested.
     */
    app(NotificationService::class)->applicationStatus($app, ApplicationStatus::PendingPayment, 'A site inspection will be scheduled.');
    app(NotificationService::class)->feeAdjusted($app);

    $given = AppNotification::where('user_id', $owner->id)->count() - $before;
    expect($given)->toBe(2);
    Queue::assertPushed(SendOwnerUpdateEmail::class, $given);
});

it('e-mails the notice’s title, the tracking ID, the business and a link into the owner’s site', function () {
    Mail::fake();
    config(['app.frontend_url' => 'https://biztrack.example.gov.ph/']);
    $app = emailableApplication();

    rejectAsBplo($app, 'Scan of the DTI certificate is unreadable & cut off.')->assertOk();

    Mail::assertSent(OwnerUpdate::class, 1);
    Mail::assertSent(OwnerUpdate::class, function (OwnerUpdate $mail) use ($app) {
        $link = "https://biztrack.example.gov.ph/applications/{$app->id}";

        $mail->assertSeeInHtml('Application rejected')
            ->assertSeeInHtml($app->tracking_id)
            ->assertSeeInHtml($app->business->name)
            ->assertSeeInHtml($link, false)
            // Red only on a disapproval's title.
            ->assertSeeInHtml('#bd0000', false)
            ->assertSeeInText($app->tracking_id)
            ->assertSeeInText($link)
            // Plain text part: the ampersand arrives as typed, not as &amp;.
            ->assertSeeInText('unreadable & cut off.');

        return $mail->hasTo($app->applicant->email)
            && str_contains($mail->envelope()->subject, $app->tracking_id);
    });
});

it('keeps red out of an ordinary update', function () {
    Mail::fake();
    $app = emailableApplication();

    app(NotificationService::class)->feeAdjusted($app);

    Mail::assertSent(OwnerUpdate::class, function (OwnerUpdate $mail) use ($app) {
        $mail->assertDontSeeInHtml('#bd0000', false)
            ->assertSeeInHtml("/applications/{$app->id}/pay", false);

        return ! $mail->isDisapproval;
    });
});

it('does not e-mail staff, and does not e-mail the owner about a staff notice', function () {
    Queue::fake();
    $officer = User::where('email', 'bplo@biztrack.local')->firstOrFail();
    $app = emailableApplication();

    // A notice written for an officer — the reassignment and amendment notices
    // go through exactly this call.
    app(NotificationService::class)->push($officer, 'assignment', 'Cases reassigned to you', 'Three open cases…', '/staff/queue');
    // A reply on a thread, told to the officer's side.
    app(NotificationService::class)->newMessage($app, $officer);

    Queue::assertNotPushed(SendOwnerUpdateEmail::class);
});

it('e-mails nothing about a change that was rolled back', function () {
    Queue::fake();
    $app = emailableApplication();

    try {
        DB::transaction(function () use ($app) {
            app(NotificationService::class)->feeAdjusted($app);
            throw new RuntimeException('the action failed after writing its notice');
        });
    } catch (RuntimeException) {
    }

    Queue::assertNotPushed(SendOwnerUpdateEmail::class);
});

it('keeps the decision when the mail relay refuses the message', function () {
    // A real SMTP failure, not a mock: nothing listens on port 1. Under the
    // test suite's `sync` queue the send happens inside the request, which is
    // the harshest case — a worker would only retry it.
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 1,
        'mail.mailers.smtp.timeout' => 2,
    ]);
    $app = emailableApplication();

    rejectAsBplo($app)->assertOk();

    expect($app->fresh()->status->value)->toBe('rejected')
        ->and(AppNotification::where('user_id', $app->applicant_user_id)
            ->where('title', 'Application rejected')->exists())->toBeTrue();
});

it('keeps the decision when the queue will not take the job', function () {
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('jobs table missing'));
    $app = emailableApplication();

    rejectAsBplo($app)->assertOk();

    expect($app->fresh()->status->value)->toBe('rejected');
});

it('tells the owner, in the app and by e-mail, when an inspection fails', function () {
    Queue::fake();
    /*
     * Undecided, or `recordInspection` refuses it: a filing the city has
     * finished with cannot have its permits acted on. `approved` alone stopped
     * meaning live on 4 October 2026 — see `Application::isDecided()`.
     */
    $app = Application::where('status', 'approved')->notDecided()->whereHas('applicant')->firstOrFail();
    $fire = Department::where('code', 'BFP')->firstOrFail();
    $visit = Inspection::create([
        'application_id' => $app->id,
        'department_id' => $fire->id,
        'status' => InspectionStatus::Scheduled,
        // Today: a result cannot be recorded before the booked day (5 October 2026).
        'scheduled_at' => now(),
    ]);

    app(WorkflowService::class)->recordInspection($visit, InspectionResult::Failed, 'No fire extinguisher on the ground floor.');

    // By title, not the latest row: a failure may now also suspend the
    // business permit, and that notice follows this one (5 October 2026).
    $notice = AppNotification::where('user_id', $app->applicant_user_id)
        ->where('title', 'Inspection did not pass')->latest('id')->first();
    expect($notice->body)->toContain('inspection did not pass')
        ->and($notice->body)->toContain('No fire extinguisher on the ground floor.');
    Queue::assertPushed(SendOwnerUpdateEmail::class, fn (SendOwnerUpdateEmail $job) => $job->title === $notice->title
        && $job->body === $notice->body);
});

it('sends a test e-mail through the configured mailer and says so', function () {
    Mail::fake();

    $this->artisan('biztrack:mail-test', ['to' => 'ken@example.com'])
        ->expectsOutputToContain('Sent to ken@example.com.')
        ->assertSuccessful();

    Mail::assertSent(OwnerUpdate::class, fn (OwnerUpdate $mail) => $mail->hasTo('ken@example.com'));
});

it('sends the test e-mail for real through the array mailer', function () {
    config(['mail.default' => 'array']);

    $this->artisan('biztrack:mail-test', ['to' => 'ken@example.com'])->assertSuccessful();

    $sent = app('mailer')->getSymfonyTransport()->messages();
    expect($sent)->toHaveCount(1)
        ->and($sent->first()->getOriginalMessage()->getSubject())->toContain('Test e-mail from BizTrack');
});

it('reports a failed test send instead of pretending', function () {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 1,
        'mail.mailers.smtp.timeout' => 2,
    ]);

    $this->artisan('biztrack:mail-test', ['to' => 'ken@example.com'])
        ->expectsOutputToContain('Not sent:')
        ->assertFailed();
});
