<?php

use App\Enums\ApplicationStatus;
use App\Enums\PermitStatus;
use App\Models\Permit;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;

/*
 * BPLO's "not renewed" list: GET /permits?not_renewed_over_days=30.
 *
 * After a Business Permit expires the owner is sent notices for 30 days and
 * then nothing (ScanPermits), so nobody at BPLO was prompted to chase a
 * business that may still be trading without one [Ken, 5 October 2026]. The
 * list is every Business Permit expired more than N days with nothing after
 * it — no renewal issued, no later permit, no renewal filed — for a business
 * still on the register.
 */

function notRenewedBusiness(string $name): int
{
    return (int) DB::table('businesses')->insertGetId([
        'name' => $name.' '.random_int(1000, 9999),
        'owner_user_id' => DB::table('users')->where('email', 'owner@biztrack.local')->value('id'),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** A permit that expired `$daysAgo` days ago (negative: still running). */
function notRenewedPermit(int $businessId, int $daysAgo, string $code = 'BUSINESS', string $status = 'expired', ?int $prior = null): int
{
    $until = BusinessDate::today()->subDays($daysAgo);

    return (int) DB::table('permits')->insertGetId([
        'permit_number' => 'NR-'.random_int(100000, 999999),
        'business_id' => $businessId,
        'permit_type_id' => DB::table('permit_types')->where('code', $code)->value('id'),
        'status' => $status,
        'valid_from' => $until->subYear()->addDay()->toDateString(),
        'valid_until' => $until->toDateString(),
        'issued_at' => $until->subYear()->addDay(),
        'prior_permit_id' => $prior,
    ]);
}

function notRenewedFiling(int $businessId, int $priorPermitId, string $status, bool $submitted = true): int
{
    return (int) DB::table('applications')->insertGetId([
        'business_id' => $businessId,
        'applicant_user_id' => DB::table('users')->where('email', 'owner@biztrack.local')->value('id'),
        'application_type' => 'renewal',
        'status' => $status,
        'prior_permit_id' => $priorPermitId,
        'submitted_at' => $submitted ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** The ids on the list, as BPLO reads it. */
function notRenewedListed(string $email = 'bplo@biztrack.local'): array
{
    return collect(test()->withHeaders(authAs($email))
        ->getJson('/api/v1/permits?detail=1&not_renewed_over_days=30&per_page=200')
        ->assertOk()
        ->json('data'))->pluck('id')->all();
}

it('lists a Business Permit expired more than 30 days with nothing after it, and not one expired 30 days', function () {
    $longGone = notRenewedPermit(notRenewedBusiness('Thirty-one days'), 31);
    $justNow = notRenewedPermit(notRenewedBusiness('Thirty days'), 30);

    $listed = notRenewedListed();

    expect($listed)->toContain($longGone)
        ->and($listed)->not->toContain($justNow);

    $row = collect(test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/permits?detail=1&not_renewed_over_days=30&per_page=200')
        ->json('data'))->firstWhere('id', $longGone);

    // The row carries its own count, which the list's "days since" reads.
    expect($row['days_until_expiry'])->toBe(-31)
        ->and($row['status'])->toBe('expired');
});

it('leaves off a permit that was renewed, replaced by a later permit, or has a renewal filed', function () {
    $renewed = notRenewedBusiness('Renewed');
    $renewedPermit = notRenewedPermit($renewed, 60);
    notRenewedPermit($renewed, -300, status: 'active', prior: $renewedPermit);

    // Came back on a New Application: no prior_permit_id, but a later permit.
    $reapplied = notRenewedBusiness('Reapplied');
    $reappliedPermit = notRenewedPermit($reapplied, 60);
    notRenewedPermit($reapplied, -200, status: 'active');

    $filed = notRenewedBusiness('Filed, in review');
    $filedPermit = notRenewedPermit($filed, 60);
    notRenewedFiling($filed, $filedPermit, ApplicationStatus::ForApproval->value);

    $listed = notRenewedListed();

    expect($listed)->not->toContain($renewedPermit)
        ->and($listed)->not->toContain($reappliedPermit)
        ->and($listed)->not->toContain($filedPermit);
});

it('still lists a permit whose renewal is only a draft, or was refused or cancelled', function () {
    $ids = [];
    foreach ([ApplicationStatus::Draft, ApplicationStatus::Rejected, ApplicationStatus::Cancelled] as $status) {
        $business = notRenewedBusiness('Filing '.$status->value);
        $ids[] = $permit = notRenewedPermit($business, 45);
        notRenewedFiling($business, $permit, $status->value, submitted: $status !== ApplicationStatus::Draft);
    }

    expect(notRenewedListed())->toContain(...$ids);
});

it('leaves off a retired business, a permit already closed, and every other office\'s certificates', function () {
    $retired = notRenewedBusiness('Removed from the register');
    $retiredPermit = notRenewedPermit($retired, 90);
    DB::table('businesses')->where('id', $retired)->update(['deleted_at' => now()]);

    $closed = notRenewedPermit(notRenewedBusiness('Closed by BPLO'), 90, status: PermitStatus::Retired->value);
    $revoked = notRenewedPermit(notRenewedBusiness('Revoked'), 90, status: PermitStatus::Revoked->value);
    $sanitary = notRenewedPermit(notRenewedBusiness('Sanitary only'), 90, code: 'SANITARY');

    $listed = notRenewedListed();

    foreach ([$retiredPermit, $closed, $revoked, $sanitary] as $id) {
        expect($listed)->not->toContain($id);
    }

    expect(Permit::whereKey($listed)->with('permitType')->get()->pluck('permitType.code')->unique()->all())
        ->toBe($listed === [] ? [] : ['BUSINESS']);
});

it('keeps the office boundary: a clearance office is not shown BPLO\'s list', function () {
    $permit = notRenewedPermit(notRenewedBusiness('Not CENRO\'s'), 45);

    expect(notRenewedListed('bplo@biztrack.local'))->toContain($permit)
        ->and(notRenewedListed('cenro@biztrack.local'))->toBe([]);
});
