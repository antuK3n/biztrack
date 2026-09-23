<?php

use App\Jobs\SendDisapprovalNotice;
use App\Mail\ApplicationDisapproved;
use App\Models\Application;
use App\Models\AppNotification;
use App\Services\Sms\SmsChannel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * A disapproval reaches the owner by e-mail and SMS as well as in the app
 * (client instruction, 23 September 2026), and neither channel can take the
 * decision down with it when it fails.
 */

/** An open filing whose owner still has an account. */
function disapprovableApplication(): Application
{
    return Application::whereIn('status', [
        'for_approval', 'pending_payment', 'awaiting_other_permits', 'for_final_approval', 'returned',
    ])->whereHas('applicant')->firstOrFail();
}

/** An SmsChannel that remembers what it was asked to send. */
function recordingSms(): object
{
    $sms = new class implements SmsChannel
    {
        /** @var list<array{to:string,message:string}> */
        public array $sent = [];

        public function send(string $to, string $message): void
        {
            $this->sent[] = ['to' => $to, 'message' => $message];
        }
    };
    app()->instance(SmsChannel::class, $sms);

    return $sms;
}

function disapprove(Application $app, string $reason = 'The lease contract is expired.')
{
    return test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => $reason]);
}

it('queues the e-mail and the SMS rather than sending them inside the decision', function () {
    Queue::fake();
    $app = disapprovableApplication();

    disapprove($app)->assertOk();

    Queue::assertPushed(SendDisapprovalNotice::class, 2);
    Queue::assertPushed(SendDisapprovalNotice::class, fn ($job) => $job->channel === 'mail'
        && $job->application->is($app) && $job->reason === 'The lease contract is expired.');
    Queue::assertPushed(SendDisapprovalNotice::class, fn ($job) => $job->channel === 'sms');
});

it('e-mails the owner the officer’s reason', function () {
    Mail::fake();
    recordingSms();
    $app = disapprovableApplication();

    disapprove($app, 'Scan of the DTI certificate is unreadable & cut off.')->assertOk();

    Mail::assertSent(ApplicationDisapproved::class, function (ApplicationDisapproved $mail) use ($app) {
        return $mail->hasTo($app->applicant->email)
            && $mail->application->is($app)
            && str_contains($mail->envelope()->subject, 'disapproved')
            // Plain text: the ampersand arrives as typed, not as &amp;.
            && str_contains($mail->render(), 'Scan of the DTI certificate is unreadable & cut off.');
    });
});

it('texts the owner’s account mobile, with the reason', function () {
    Mail::fake();
    $sms = recordingSms();
    $app = disapprovableApplication();
    $app->applicant->forceFill(['mobile_number' => '09171234567'])->save();

    disapprove($app)->assertOk();

    expect($sms->sent)->toHaveCount(1)
        ->and($sms->sent[0]['to'])->toBe('09171234567')
        ->and($sms->sent[0]['message'])->toContain($app->tracking_id)
        ->and($sms->sent[0]['message'])->toContain('disapproved')
        ->and($sms->sent[0]['message'])->toContain('The lease contract is expired.');
});

it('falls back to the business’s own mobile when the account has none', function () {
    Mail::fake();
    $sms = recordingSms();
    $app = disapprovableApplication();
    $app->applicant->forceFill(['mobile_number' => null])->save();
    $address = $app->business?->address;
    expect($address)->not->toBeNull('the fixture filing has no business address to carry a mobile');
    $address->forceFill(['mobile_number' => '09998887777'])->save();

    disapprove($app)->assertOk();

    expect(collect($sms->sent)->pluck('to')->all())->toBe(['09998887777']);
});

it('keeps the decision when the SMS gateway fails', function () {
    Mail::fake();
    app()->instance(SmsChannel::class, new class implements SmsChannel
    {
        public function send(string $to, string $message): void
        {
            throw new RuntimeException('gateway down');
        }
    });
    $app = disapprovableApplication();
    $app->applicant->forceFill(['mobile_number' => '09171234567'])->save();

    disapprove($app)->assertOk();

    expect($app->fresh()->status->value)->toBe('rejected')
        ->and(AppNotification::where('user_id', $app->applicant_user_id)
            ->where('title', 'Application disapproved')->exists())->toBeTrue();
    // The other channel is not taken down with it.
    Mail::assertSent(ApplicationDisapproved::class);
});

it('keeps the decision when the queue will not take the job', function () {
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('jobs table missing'));
    $app = disapprovableApplication();

    disapprove($app)->assertOk();

    expect($app->fresh()->status->value)->toBe('rejected');
});
