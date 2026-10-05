<?php

use App\Jobs\SendOwnerUpdateEmail;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

/*
 * Who is told that a message about a filing has arrived.
 *
 * The rule [Ken, 5 October 2026]: the sender is never told about their own
 * message. An owner's message reaches the office it was addressed to - the
 * officer holding the case there if that account is active, otherwise every
 * active account of THAT office. Never another office's officer, who cannot
 * open the conversation, and never a deactivated account.
 */

/** A submitted filing of owner@, routed to BPLO at submit and to CHO here. */
function recipientsFiling(): int
{
    static $n = 0;
    $n++;
    authAs('owner@biztrack.local');

    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => "Recipients Store {$n}",
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-8100'.$n,
        'tin' => '123-456-789-000',
        'address' => ['line1' => "{$n} Recipients St.", 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    test()->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    assignOffice($appId, 'CHO');

    return $appId;
}

function recipientsDept(string $code): int
{
    return Department::where('code', $code)->value('id');
}

function recipientsHold(int $appId, string $code, User $officer): void
{
    ApplicationAssignment::where('application_id', $appId)
        ->where('department_id', recipientsDept($code))
        ->update(['officer_user_id' => $officer->id, 'assigned_at' => now()]);
}

function recipientsSeat(string $code, string $email, bool $active = true): User
{
    $template = User::where('email', 'bplo@biztrack.local')->firstOrFail();
    $seat = User::create([
        'name' => 'Seat '.$email, 'first_name' => 'Seat', 'last_name' => 'Account', 'gender' => 'F',
        'email' => $email, 'password' => 'biztrack1', 'mobile_number' => '0917'.random_int(1000000, 9999999),
        'department_id' => recipientsDept($code), 'is_active' => $active, 'email_verified_at' => now(),
    ]);
    $seat->roles()->sync($template->roles()->pluck('roles.id'));

    return $seat;
}

function recipientsWrite(int $appId, string $body, ?string $office = null): void
{
    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$appId}/messages", array_filter([
        'body' => $body,
        'department_id' => $office ? recipientsDept($office) : null,
    ]))->assertCreated();
}

it('never tells the owner about the message they just sent', function () {
    Bus::fake([SendOwnerUpdateEmail::class]);
    $appId = recipientsFiling();
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $mailToOwner = fn () => Bus::dispatched(SendOwnerUpdateEmail::class, fn ($job) => $job->owner->is($owner))->count();
    $before = $owner->notifications()->count();
    $mailBefore = $mailToOwner();

    // Nobody holds BPLO or CHO: the old fallback named the applicant.
    recipientsWrite($appId, 'To BPLO with nobody holding it');
    recipientsWrite($appId, 'To CHO with nobody holding it', 'CHO');

    expect($owner->notifications()->count())->toBe($before)
        ->and($mailToOwner())->toBe($mailBefore);
});

it('tells the active accounts of the office written to when nobody there holds the case', function () {
    $appId = recipientsFiling();
    $bplo = User::where('email', 'bplo@biztrack.local')->firstOrFail();
    $sanitary = User::where('email', 'sanitary@biztrack.local')->firstOrFail();
    $otherCho = recipientsSeat('CHO', 'recipients.cho2@biztrack.local');
    $retiredCho = recipientsSeat('CHO', 'recipients.cho3@biztrack.local', active: false);

    // BPLO holds ITS case; CHO's is unclaimed.
    recipientsHold($appId, 'BPLO', $bplo);
    $bploBefore = $bplo->notifications()->count();
    $sanitaryBefore = $sanitary->notifications()->count();

    recipientsWrite($appId, 'A question for the health office', 'CHO');

    expect([
        'bplo' => $bplo->notifications()->count() - $bploBefore,
        'sanitary' => $sanitary->notifications()->count() - $sanitaryBefore,
        'other_cho' => $otherCho->notifications()->count(),
        'retired_cho' => $retiredCho->notifications()->count(),
    ])->toBe(['bplo' => 0, 'sanitary' => 1, 'other_cho' => 1, 'retired_cho' => 0]);
});

it('tells only the officer holding the case while that account is active', function () {
    $appId = recipientsFiling();
    $sanitary = User::where('email', 'sanitary@biztrack.local')->firstOrFail();
    $otherCho = recipientsSeat('CHO', 'recipients.cho4@biztrack.local');
    recipientsHold($appId, 'CHO', $sanitary);
    $before = $sanitary->notifications()->count();

    recipientsWrite($appId, 'For the officer handling it', 'CHO');

    expect($sanitary->notifications()->count() - $before)->toBe(1)
        ->and($otherCho->notifications()->count())->toBe(0);
});

it('tells the office\'s active accounts when the holder has been deactivated', function () {
    $appId = recipientsFiling();
    $holder = User::where('email', 'bplo@biztrack.local')->firstOrFail();
    recipientsHold($appId, 'BPLO', $holder);
    $holder->forceFill(['is_active' => false])->save();
    $active = recipientsSeat('BPLO', 'recipients.bplo2@biztrack.local');
    $holderBefore = $holder->notifications()->count();

    recipientsWrite($appId, 'Is anyone there?');

    expect($holder->notifications()->count() - $holderBefore)->toBe(0)
        ->and($active->notifications()->count())->toBe(1);
});

it('still tells the owner when an office writes to them', function () {
    $appId = recipientsFiling();
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $before = $owner->notifications()->count();

    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/applications/{$appId}/messages", [
        'body' => 'Bring the water test.',
        'department_id' => recipientsDept('CHO'),
    ])->assertCreated();

    expect($owner->notifications()->count() - $before)->toBe(1);
});
