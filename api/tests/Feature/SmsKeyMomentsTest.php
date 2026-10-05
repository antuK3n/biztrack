<?php

use App\Enums\ApplicationStatus;
use App\Enums\OfficerRequestStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\OfficerRequest;
use App\Models\Permit;
use App\Models\PermitType;
use App\Services\NotificationService;
use App\Services\Sms\SmsChannel;

/*
 * Only the key moments send a text [Ken, 5 October 2026]: the decisions,
 * the failed inspection and refused clearance, a suspension or revocation,
 * and the expiry notices. Every other notice stays in-app (and e-mail for an
 * owner) with no SMS.
 */

/** Swap the SMS driver for one that records what it was asked to send. */
function recordTexts(): ArrayObject
{
    $sent = new ArrayObject;
    app()->instance(SmsChannel::class, new class($sent) implements SmsChannel
    {
        public function __construct(private ArrayObject $sent) {}

        public function send(string $to, string $message): void
        {
            $this->sent[] = ['to' => $to, 'message' => $message];
        }
    });

    return $sent;
}

/** A filing, a permit and a business whose owners all have a mobile number. */
function smsFixtures(): object
{
    $app = Application::whereHas('applicant')->firstOrFail();
    $app->applicant->update(['mobile_number' => '09171234567']);
    $permit = Permit::whereHas('business.owner')->whereNotNull('valid_until')->firstOrFail();
    $permit->business->owner->update(['mobile_number' => '09171234567']);
    $request = (new OfficerRequest)
        ->forceFill(['title' => 'Lease contract', 'status' => OfficerRequestStatus::Fulfilled])
        ->setRelation('application', $app);

    return (object) [
        'app' => $app,
        'permit' => $permit,
        'business' => $permit->business,
        'type' => PermitType::firstOrFail(),
        'request' => $request,
    ];
}

function giveNotice(string $notice, object $f): void
{
    $n = app(NotificationService::class);
    $owner = $f->app->applicant;

    match ($notice) {
        'applicationApproved' => $n->applicationApproved($f->app),
        'applicationRejected' => $n->applicationRejected($f->app, 'The lease contract is expired.'),
        'permitsIssued' => $n->permitsIssued($f->app),
        'inspectionFailed' => $n->inspectionFailed($f->app, 'The kitchen has no grease trap.'),
        'clearanceRejected' => $n->clearanceRejected($f->app, $f->type, 'No grease trap.'),
        'outcomePermitSuspended' => $n->outcomePermitSuspended($f->app, $f->permit, $f->type, 'No grease trap.'),
        'permitRevoked' => $n->permitRevoked($f->permit, 'Operating without a fire clearance.'),
        'permitStatusChanged to Suspended' => $n->permitStatusChanged($f->permit, PermitStatus::Suspended, 'Pending review.'),
        'permitStatusChanged to Revoked' => $n->permitStatusChanged($f->permit, PermitStatus::Revoked, 'Closed by order.'),
        'businessStatusChanged to suspended' => $n->businessStatusChanged($f->business, 'active', 'suspended', 'Unpaid taxes.', 'Suspended'),
        'permitExpiring' => $n->permitExpiring($f->permit, 30, 22),
        'permitExpired' => $n->permitExpired($f->permit),
        'renewalDue' => $n->renewalDue($f->permit),

        'applicationStatus' => $n->applicationStatus($f->app, ApplicationStatus::PendingPayment, 'A site inspection will be scheduled.'),
        'applicationNote' => $n->applicationNote($f->app, 'The site visit is booked.'),
        'outcomePermitStillSuspended' => $n->outcomePermitStillSuspended($f->permit, 7),
        'outcomePermitReinstated' => $n->outcomePermitReinstated($f->app, $f->permit),
        'newMessage' => $n->newMessage($f->app, $owner),
        'requestCreated' => $n->requestCreated($f->request, $owner),
        'requestResponded' => $n->requestResponded($f->request, $owner),
        'filingResubmitted' => $n->filingResubmitted($f->app, $owner, 2),
        'requestClosed' => $n->requestClosed($f->request, $owner),
        'feeAdjusted' => $n->feeAdjusted($f->app),
        'permitIssuedUnbilled' => $n->permitIssuedUnbilled($f->permit, 500.0, 0.0),
        'permitStatusChanged to Retired' => $n->permitStatusChanged($f->permit, PermitStatus::Retired, 'Closed shop.'),
        'permitStatusChanged to Rejected' => $n->permitStatusChanged($f->permit, PermitStatus::Rejected, 'Withdrawn.'),
        'permitStatusChanged to Active' => $n->permitStatusChanged($f->permit, PermitStatus::Active, 'Settled.'),
        'businessStatusChanged to blacklisted' => $n->businessStatusChanged($f->business, 'active', 'blacklisted', 'Fraud.', 'Blacklisted'),
        'businessStatusChanged to active' => $n->businessStatusChanged($f->business, 'suspended', 'active', 'Settled.', 'Active'),
    };
}

it('texts the owner once for a key moment', function (string $notice) {
    $f = smsFixtures();
    $sent = recordTexts();

    giveNotice($notice, $f);

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['message'])->toStartWith('BizTrack: ');
})->with([
    'applicationApproved', 'applicationRejected', 'permitsIssued', 'inspectionFailed',
    'clearanceRejected', 'outcomePermitSuspended', 'permitRevoked',
    'permitStatusChanged to Suspended', 'permitStatusChanged to Revoked',
    'businessStatusChanged to suspended', 'permitExpiring', 'permitExpired', 'renewalDue',
]);

it('sends no text for any other notice', function (string $notice) {
    $f = smsFixtures();
    $sent = recordTexts();

    giveNotice($notice, $f);

    expect($sent)->toHaveCount(0);
})->with([
    'applicationStatus', 'applicationNote', 'outcomePermitStillSuspended', 'outcomePermitReinstated',
    'newMessage', 'requestCreated', 'requestResponded', 'filingResubmitted', 'requestClosed',
    'feeAdjusted', 'permitIssuedUnbilled', 'permitStatusChanged to Retired',
    'permitStatusChanged to Rejected', 'permitStatusChanged to Active',
    'businessStatusChanged to blacklisted', 'businessStatusChanged to active',
]);

it('texts the owner once when BPLO rejects their filing', function () {
    $app = Application::whereIn('status', ['for_approval', 'pending_payment', 'approved', 'for_final_approval', 'returned'])
        ->notDecided()->whereHas('applicant')->firstOrFail();
    $app->applicant->update(['mobile_number' => '09171234567']);
    $sent = recordTexts();

    authAs('bplo@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'The lease contract is expired.'])->assertOk();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['message'])->toBe("BizTrack: {$app->tracking_id} was rejected. Open BizTrack for the reason.");
});
