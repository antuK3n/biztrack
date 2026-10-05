<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Enums\OfficerRequestStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Message;
use App\Models\OfficerRequest;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * biztrack:seed-defense-accounts builds every scenario through the API, so
 * these assertions are about the register it leaves: each owner is in the
 * state the scenario names, can sign in, and a second run adds nothing.
 */

const DEFENSE_PASSWORD = 'Test-Defense-2026!';

function defenseOwner(string $slug): User
{
    return User::where('email', "kenjohnvianneym+bt-{$slug}-1@gmail.com")->firstOrFail();
}

/** @return list<Application> oldest first */
function defenseFilings(string $slug): array
{
    return Application::where('applicant_user_id', defenseOwner($slug)->id)->orderBy('id')->get()->all();
}

function defenseBusinessPermit(string $slug): Permit
{
    return Permit::whereHas('business', fn ($b) => $b->where('owner_user_id', defenseOwner($slug)->id))
        ->whereHas('permitType', fn ($t) => $t->where('code', PermitType::OUTCOME_CODE))
        ->orderByDesc('id')
        ->firstOrFail();
}

beforeEach(function () {
    Storage::fake('local');
});

it('builds one owner per scenario, each in the state the scenario names', function () {
    $this->artisan('biztrack:seed-defense-accounts', ['--password' => DEFENSE_PASSWORD, '--per-scenario' => 1])
        ->assertSuccessful();

    expect(User::where('email', 'like', 'kenjohnvianneym+bt-%')->count())->toBe(16);

    // Every owner confirmed, consented, numbered in the recognisable range.
    foreach (User::where('email', 'like', 'kenjohnvianneym+bt-%')->get() as $owner) {
        expect($owner->email_verified_at)->not->toBeNull()
            ->and($owner->data_privacy_consent_at)->not->toBeNull()
            ->and($owner->mobile_number)->toStartWith('0999000')
            ->and($owner->hasRole('business_owner'))->toBeTrue();
    }

    [$draft] = defenseFilings('draft');
    expect($draft->status)->toBe(ApplicationStatus::Draft)->and($draft->tracking_id)->toBeNull()
        ->and($draft->documents()->count())->toBe(1);

    [$waiting] = defenseFilings('for-approval');
    expect($waiting->status)->toBe(ApplicationStatus::ForApproval)->and($waiting->tracking_id)->not->toBeNull();

    [$returned] = defenseFilings('returned');
    expect($returned->status)->toBe(ApplicationStatus::Returned);

    [$unpaid] = defenseFilings('awaiting-payment');
    expect($unpaid->status)->toBe(ApplicationStatus::PendingPayment)
        // BPLO ticked the other permits when it approved.
        ->and($unpaid->permitTypes()->whereIn('code', PermitType::REQUIRED_CLEARANCE_CODES)->count())->toBeGreaterThan(0);

    [$reviewing] = defenseFilings('offices-reviewing');
    $rows = $reviewing->permitTypes->mapWithKeys(fn ($t) => [$t->code => $t->pivot->status]);
    expect($reviewing->status)->toBe(ApplicationStatus::Approved)->and($reviewing->isDecided())->toBeFalse()
        ->and($reviewing->payments()->where('method', 'counter')->exists())->toBeTrue()
        ->and(collect($rows)->contains(ClearanceStatus::ForInspection))->toBeTrue()
        ->and($reviewing->inspections()->where('status', 'scheduled')->count())->toBe(1);

    [$approved] = defenseFilings('approved');
    expect($approved->isDecided())->toBeTrue()
        ->and($approved->permitTypes->every(fn ($t) => $t->pivot->status === ClearanceStatus::Approved))->toBeTrue()
        ->and(Permit::where('application_id', $approved->id)->where('status', PermitStatus::Active->value)->count())
        ->toBe($approved->permitTypes->count());

    [$rejected] = defenseFilings('rejected');
    expect($rejected->status)->toBe(ApplicationStatus::Rejected)->and($rejected->rejection_reason)->not->toBeEmpty();

    // A 2025 permit, lapsed on 31 December and flipped by the nightly scan.
    $expired = defenseBusinessPermit('expired');
    expect($expired->status)->toBe(PermitStatus::Expired)
        ->and($expired->valid_until->toDateString())->toBe('2025-12-31')
        ->and($expired->issued_at->year)->toBe(2025);

    [, $renewal] = defenseFilings('renewal');
    expect($renewal->application_type->value)->toBe('renewal')
        ->and($renewal->status)->toBe(ApplicationStatus::ForApproval)
        ->and(Permit::find($renewal->prior_permit_id)->status)->toBe(PermitStatus::Expired);

    [, $amendment] = defenseFilings('amendment-review');
    expect($amendment->application_type->value)->toBe('amendment')->and($amendment->status)->toBe(ApplicationStatus::ForApproval);

    [, $amended] = defenseFilings('amendment-approved');
    expect($amended->status)->toBe(ApplicationStatus::Approved)
        ->and($amended->business->trade_name)->toEndWith('General Merchandise')
        ->and(Permit::whereHas('business', fn ($b) => $b->where('owner_user_id', defenseOwner('amendment-approved')->id))
            ->where('status', PermitStatus::Superseded->value)->exists())->toBeTrue();

    [$suspended] = defenseFilings('suspended');
    expect(defenseBusinessPermit('suspended')->status)->toBe(PermitStatus::Suspended)
        ->and($suspended->permitTypes->contains(fn ($t) => $t->pivot->status === ClearanceStatus::Rejected))->toBeTrue();

    expect(defenseBusinessPermit('revoked')->status)->toBe(PermitStatus::Revoked)
        ->and(defenseBusinessPermit('revoked')->revoked_reason)->not->toBeEmpty();

    expect(defenseOwner('blacklisted')->blacklisted_at)->not->toBeNull()
        ->and(defenseOwner('blacklisted')->blacklist_reason)->not->toBeEmpty();

    [$asked] = defenseFilings('open-requirement');
    expect(OfficerRequest::where('application_id', $asked->id)->where('status', OfficerRequestStatus::Pending->value)
        ->whereNull('system_key')->count())->toBe(1);

    [$talking] = defenseFilings('messages');
    expect($talking->status)->toBe(ApplicationStatus::ForApproval)
        ->and($talking->assignments()->whereNotNull('officer_user_id')->exists())->toBeTrue()
        ->and(Message::whereHas('thread', fn ($t) => $t->where('application_id', $talking->id))->count())->toBe(3);

    // The run's sign-in tokens are gone, and the clock is the real one again.
    expect(PersonalAccessToken::count())->toBe(0)
        ->and(now()->diffInMinutes(Carbon\Carbon::createFromTimestamp(time()), true))->toBeLessThan(2);
});

it('leaves accounts an owner can sign in to with the shared password', function () {
    $this->artisan('biztrack:seed-defense-accounts', ['--password' => DEFENSE_PASSWORD, '--per-scenario' => 1])
        ->assertSuccessful();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'kenjohnvianneym+bt-approved-1@gmail.com',
        'password' => DEFENSE_PASSWORD,
        'portal' => 'public',
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);
});

it('skips an owner who is already registered rather than building them twice', function () {
    $this->artisan('biztrack:seed-defense-accounts', ['--password' => DEFENSE_PASSWORD, '--per-scenario' => 1])
        ->assertSuccessful();
    $counts = fn () => [User::count(), Application::count(), Permit::count()];
    $before = $counts();

    $this->artisan('biztrack:seed-defense-accounts', ['--password' => DEFENSE_PASSWORD, '--per-scenario' => 1])
        ->expectsOutputToContain('0 built, 16 skipped')
        ->assertSuccessful();

    expect($counts())->toBe($before);
});

it('writes nothing on a dry run', function () {
    $before = [User::count(), Application::count()];

    $this->artisan('biztrack:seed-defense-accounts', ['--password' => DEFENSE_PASSWORD, '--per-scenario' => 1, '--dry-run' => true])
        ->expectsOutputToContain('Dry run: nothing written.')
        ->assertSuccessful();

    expect([User::count(), Application::count()])->toBe($before);
});

it('refuses to run without the shared password', function () {
    $this->artisan('biztrack:seed-defense-accounts', ['--per-scenario' => 1])->assertFailed();

    expect(User::where('email', 'like', 'kenjohnvianneym+bt-%')->exists())->toBeFalse();
});
