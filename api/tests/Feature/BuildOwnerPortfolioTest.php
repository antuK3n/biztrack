<?php

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\ClearanceStatus;
use App\Enums\OfficerRequestStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Business;
use App\Models\Inspection;
use App\Models\OfficerRequest;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * biztrack:build-owner-portfolio gives an EXISTING owner the nine businesses
 * in database/data/owner-portfolio.json, every step through the API on the
 * spec's dates. These assertions are about the register it leaves: each
 * business in the state its spec row names, the account itself untouched,
 * every step in office hours, and a second run adding nothing.
 */

function portfolioOwner(): User
{
    $owner = User::create([
        'name' => 'Lorna Bautista',
        'first_name' => 'Lorna',
        'last_name' => 'Bautista',
        'gender' => 'F',
        'email' => 'lorna.bautista@biztrack.local',
        'mobile_number' => '09990001234',
        'password' => Hash::make('biztrack1'),
        'is_active' => true,
        'home_street' => '12 Gov. Pascual Ave.',
        'home_barangay' => 'Longos',
        'home_postal_code' => '1472',
        'data_privacy_consent_at' => now(),
        'email_verified_at' => now(),
    ]);
    $owner->roles()->attach(Role::where('name', 'business_owner')->value('id'));

    return $owner;
}

function portfolioBusiness(User $owner, string $name): Business
{
    return Business::where('owner_user_id', $owner->id)->where('name', $name)->firstOrFail();
}

/** @return list<Application> oldest first */
function portfolioFilings(User $owner, string $name): array
{
    return Application::where('business_id', portfolioBusiness($owner, $name)->id)->orderBy('id')->get()->all();
}

/** @return array<string, string> permit code => status, newest per code */
function portfolioPermits(User $owner, string $name): array
{
    return Permit::with('permitType')->where('business_id', portfolioBusiness($owner, $name)->id)->orderBy('id')->get()
        ->mapWithKeys(fn (Permit $p) => [$p->permitType->code => $p->status->value])->all();
}

beforeEach(function () {
    Storage::fake('local');
});

it('builds the nine businesses, each in the state the spec names', function () {
    $owner = portfolioOwner();
    $before = $owner->only(['first_name', 'last_name', 'email', 'password', 'mobile_number']);

    $this->artisan('biztrack:build-owner-portfolio', ['--owner' => $owner->email])->assertSuccessful();

    expect(Business::where('owner_user_id', $owner->id)->count())->toBe(9)
        ->and($owner->fresh()->only(array_keys($before)))->toBe($before)
        // The account is the owner on every form.
        ->and(DB::table('business_owners')
            ->whereIn('business_id', Business::where('owner_user_id', $owner->id)->pluck('id'))
            ->get(['surname', 'given_name'])->unique()->map(fn ($o) => "{$o->given_name} {$o->surname}")->values()->all())
        ->toBe(['Lorna Bautista']);

    // A: every permit issued, the filing closed, the DENR paper accepted.
    [$a] = portfolioFilings($owner, 'Mondragon Sari-Sari Store');
    expect($a->status)->toBe(ApplicationStatus::Approved)->and($a->isDecided())->toBeTrue()
        ->and(portfolioPermits($owner, 'Mondragon Sari-Sari Store'))->toEqual([
            'BUSINESS' => 'active', 'FSIC' => 'active', 'SANITARY' => 'active',
            'ZONING' => 'active', 'CEC' => 'active', 'OCCUPANCY' => 'active',
        ])
        ->and($a->payments()->where('method', 'counter')->exists())->toBeTrue()
        ->and(OfficerRequest::where('application_id', $a->id)->where('status', '!=', OfficerRequestStatus::Fulfilled->value)->exists())->toBeFalse();

    // B: paid; FSIC issued, Sanitary visit booked ahead, three offices untouched.
    [$b] = portfolioFilings($owner, 'KM Lutong Bahay Carinderia');
    $rows = $b->permitTypes->mapWithKeys(fn ($t) => [$t->code => $t->pivot->status]);
    $visit = Inspection::where('application_id', $b->id)->whereNull('conducted_at')->sole();
    expect($b->isDecided())->toBeFalse()
        ->and($rows['FSIC'])->toBe(ClearanceStatus::Approved)
        ->and($rows['SANITARY'])->toBe(ClearanceStatus::ForInspection)
        ->and([$rows['ZONING'], $rows['OCCUPANCY'], $rows['CEC']])->each->toBe(ClearanceStatus::ForApproval)
        ->and($visit->scheduled_at->format('Y-m-d H:i'))->toBe('2026-10-08 10:00')
        ->and($visit->created_at->format('Y-m-d'))->toBe('2026-10-05')
        ->and($visit->result)->toBeNull();

    // C: submitted at 2:15 PM, nothing since.
    [$c] = portfolioFilings($owner, 'Mondragon Water Refilling Station');
    expect($c->status)->toBe(ApplicationStatus::ForApproval)
        ->and($c->submitted_at->format('Y-m-d H:i'))->toBe('2026-10-05 14:15');

    // D: approved with the other permits ticked; the Tax Order unpaid.
    [$d] = portfolioFilings($owner, 'KJV Laundry Hub');
    expect($d->status)->toBe(ApplicationStatus::PendingPayment)
        ->and($d->permitTypes()->pluck('code')->sort()->values()->all())->toBe(['BUSINESS', 'CEC', 'FSIC', 'ZONING'])
        ->and($d->feeAssessment)->not->toBeNull()
        ->and($d->payments()->exists())->toBeFalse();

    // E: returned on the TIN field.
    [$e] = portfolioFilings($owner, 'Mondragon Bakeshop');
    expect($e->status)->toBe(ApplicationStatus::Returned)
        ->and(DB::table('application_return_notes')->where('application_id', $e->id)->value('target'))->toBe('form:tin');

    // F: a 2025 permit, lapsed and not renewed.
    $f = portfolioFilings($owner, "Ken's Hardware & Construction Supply");
    $fPermit = Permit::where('application_id', $f[0]->id)->whereHas('permitType', fn ($t) => $t->where('code', PermitType::OUTCOME_CODE))->sole();
    expect($f)->toHaveCount(1)
        ->and($fPermit->status)->toBe(PermitStatus::Expired)
        ->and($fPermit->valid_until->format('Y-m-d'))->toBe('2025-12-31')
        ->and($fPermit->issued_at->format('Y'))->toBe('2025');

    // H: new 2025, renewed on time in January 2026, then a move with BPLO.
    [$hNew, $hRenewal, $hMove] = portfolioFilings($owner, 'Mondragon Auto Repair');
    $current = Permit::where('application_id', $hRenewal->id)->sole();
    expect($hRenewal->application_type)->toBe(ApplicationType::Renewal)
        ->and($hRenewal->submitted_at->format('Y-m'))->toBe('2026-01')
        ->and(collect($hRenewal->feeAssessment->line_items)->pluck('label')->filter(fn ($l) => str_contains($l, 'late renewal'))->all())->toBe([])
        ->and($current->status)->toBe(PermitStatus::Active)
        ->and($current->valid_until->format('Y-m-d'))->toBe('2026-12-31')
        ->and($hMove->application_type)->toBe(ApplicationType::Amendment)
        ->and($hMove->status)->toBe(ApplicationStatus::ForApproval)
        ->and($hMove->permitTypes()->pluck('code')->sort()->values()->all())->toBe(['BUSINESS', 'ZONING'])
        ->and($hMove->requestedChanges()->pluck('field')->all())->toContain('address_pin', 'address_barangay_id');

    // I: paid; CEC issued, its DENR paper waiting on the owner.
    [$i] = portfolioFilings($owner, 'KM Fish Dealer');
    $open = OfficerRequest::where('application_id', $i->id)->sole();
    expect($i->payments()->where('method', 'counter')->value('paid_at')->format('Y-m-d'))->toBe('2026-09-22')
        ->and($open->status)->toBe(OfficerRequestStatus::Pending)
        ->and($open->system_key)->toStartWith('denr.')
        ->and($open->created_at->format('Y-m-d'))->toBe('2026-10-02');

    // J: the failed Sanitary visit suspended the Business Permit that day.
    [$j] = portfolioFilings($owner, 'Mondragon Food Kiosk');
    $jPermit = Permit::where('application_id', $j->id)->sole();
    expect($jPermit->status)->toBe(PermitStatus::Suspended)
        ->and($jPermit->suspended_at->format('Y-m-d'))->toBe('2026-09-24')
        ->and($jPermit->suspension_reason)->toContain('handwashing');

    // Every step in office hours; nothing after today but B's booked visit.
    $now = CarbonImmutable::now('Asia/Manila');
    $ids = Application::whereIn('business_id', Business::where('owner_user_id', $owner->id)->pluck('id'))->pluck('id');
    $moments = DB::table('application_status_history')->whereIn('application_id', $ids)->pluck('created_at')
        ->merge(DB::table('application_assignments')->whereIn('application_id', $ids)->whereNotNull('completed_at')->pluck('completed_at'))
        ->merge(DB::table('payments')->whereIn('application_id', $ids)->pluck('paid_at'))
        ->merge(DB::table('inspections')->whereIn('application_id', $ids)->pluck('conducted_at')->filter());
    foreach ($moments as $at) {
        $t = CarbonImmutable::parse($at, 'Asia/Manila');
        expect($t->isWeekend())->toBeFalse("{$at} is a weekend")
            ->and($t->hour * 60 + $t->minute)->toBeBetween(8 * 60, 17 * 60)
            ->and($t->lessThanOrEqualTo($now))->toBeTrue("{$at} is in the future");
    }
    expect(Inspection::whereIn('application_id', $ids)->where('scheduled_at', '>', $now)->pluck('application_id')->unique()->all())
        ->toBeIn([[], [$b->id]]);

    // The sign-in tokens the run used are gone.
    expect(PersonalAccessToken::where('tokenable_id', $owner->id)->exists())->toBeFalse();
});

it('skips a business the owner already has, so a second run builds nothing', function () {
    $owner = portfolioOwner();
    $this->artisan('biztrack:build-owner-portfolio', ['--owner' => $owner->email])->assertSuccessful();
    $counts = [Business::count(), Application::count(), Permit::count()];

    $this->artisan('biztrack:build-owner-portfolio', ['--owner' => $owner->email])
        ->expectsOutputToContain('0 built, 9 skipped')
        ->assertSuccessful();

    expect([Business::count(), Application::count(), Permit::count()])->toBe($counts);
});

it('writes nothing on a dry run', function () {
    $owner = portfolioOwner();
    $counts = [Business::count(), Application::count()];

    $this->artisan('biztrack:build-owner-portfolio', ['--owner' => $owner->email, '--dry-run' => true])
        ->expectsOutputToContain('Dry run: nothing written.')
        ->assertSuccessful();

    expect([Business::count(), Application::count()])->toBe($counts);
});

it('refuses an address that is not a verified owner account', function () {
    $this->artisan('biztrack:build-owner-portfolio', ['--owner' => 'nobody@biztrack.local'])->assertFailed();
});
