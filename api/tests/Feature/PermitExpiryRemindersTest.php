<?php

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\AppNotification;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitExpiryNotice;
use App\Models\PermitType;
use Carbon\CarbonImmutable;

/*
 * Renewal reminder notifications (docs/r-integration-spec.md §3).
 *
 * The reminder ledger `permit_expiry_notices` is what "Reminders Sent" on the
 * Renewal Risk screen counts, so these tests are as much about what the scan
 * does NOT write as about what it does. The load-bearing one is
 * "re-running ... is a no-op": a nightly job that re-sends the 30-day reminder
 * every night for a month is worse than no reminder at all.
 */

/** A permit expiring in `$days` days (negative = already lapsed), owned by the demo owner. */
function reminderPermit(int $days, ?Business $business = null): Permit
{
    $business ??= Business::firstOrFail();
    $validUntil = CarbonImmutable::now()->startOfDay()->addDays($days);

    return Permit::create([
        'permit_number' => 'REM-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'application_id' => Application::firstOrFail()->id,
        'business_id' => $business->id,
        'permit_type_id' => PermitType::firstOrFail()->id,
        // Deliberately active even when past due: section 2 of the scan is what
        // flips it, and a test that pre-flips it would never exercise that.
        'status' => PermitStatus::Active->value,
        'valid_from' => $validUntil->subYear()->toDateString(),
        'valid_until' => $validUntil->toDateString(),
        'issued_at' => $validUntil->subYear(),
    ]);
}

/** File a renewal against `$permit` in the given state. */
function fileRenewalAgainst(Permit $permit, ApplicationStatus $status, bool $submitted = true): Application
{
    return Application::create([
        'tracking_id' => 'RNW-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'business_id' => $permit->business_id,
        'applicant_user_id' => Business::findOrFail($permit->business_id)->owner_user_id,
        'application_type' => ApplicationType::Renewal->value,
        'status' => $status->value,
        'prior_permit_id' => $permit->id,
        'submitted_at' => $submitted ? CarbonImmutable::now() : null,
    ]);
}

/** Notice kinds on the ledger for one permit. */
function noticeKinds(Permit $permit): array
{
    return PermitExpiryNotice::where('permit_id', $permit->id)
        ->orderBy('notice_kind')
        ->pluck('notice_kind')
        ->all();
}

beforeEach(function () {
    // Isolate the scan from whatever the demo seeder issued.
    Permit::query()->delete();
    PermitExpiryNotice::query()->delete();
    AppNotification::query()->delete();
});

it('fires a reminder at each of the 30, 15, 7 and 1-day thresholds', function () {
    $permits = [];
    foreach ([30, 15, 7, 1] as $days) {
        $permits[$days] = reminderPermit($days);
    }

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    foreach ([30, 15, 7, 1] as $days) {
        expect(noticeKinds($permits[$days]))->toBe(["threshold_{$days}"]);
    }

    expect(AppNotification::where('type', 'expiry')->count())->toBe(4);
});

it('carries the paper\'s reminder wording and the mockup\'s title', function () {
    reminderPermit(30);

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    $notice = AppNotification::where('type', 'expiry')->firstOrFail();

    expect($notice->title)->toBe('Business Permit expiring in 30 days');
    expect($notice->body)->toStartWith(
        'Reminder: Your business permit will expire in 30 days. Please renew your permit '
        .'before the expiration date to avoid penalties.'
    );
    // The reminder has to open the thing it is about.
    expect($notice->link)->toBe('/permits');
});

it('says "1 day", not "1 days", on the final reminder', function () {
    reminderPermit(1);

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    expect(AppNotification::where('type', 'expiry')->firstOrFail()->title)
        ->toBe('Business Permit expiring in 1 day');
});

it('re-running the scan the same night writes nothing and sends nothing', function () {
    foreach ([30, 15, 7, 1, -3] as $days) {
        reminderPermit($days);
    }

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    $ledger = PermitExpiryNotice::count();
    $sent = AppNotification::count();
    expect($ledger)->toBeGreaterThan(0);

    // Three more nights with nothing else changing.
    $this->artisan('biztrack:scan-permits')->assertSuccessful();
    $this->artisan('biztrack:scan-permits')->assertSuccessful();
    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    expect(PermitExpiryNotice::count())->toBe($ledger);
    expect(AppNotification::count())->toBe($sent);
});

it('does not re-send the 30-day reminder every night while the permit sits in that bucket', function () {
    // 30 days out. Over the next fortnight it stays inside the 30-day bucket
    // and must be told once, not fourteen times.
    $permit = reminderPermit(30);
    $start = CarbonImmutable::now();

    for ($night = 0; $night <= 14; $night++) {
        $this->travelTo($start->addDays($night));
        $this->artisan('biztrack:scan-permits')->assertSuccessful();
    }

    expect(noticeKinds($permit))->toBe(['threshold_30']);
    expect(AppNotification::where('type', 'expiry')->count())->toBe(1);

    // Cross into the 15-day bucket: exactly one more reminder.
    $this->travelTo($start->addDays(16));
    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    expect(noticeKinds($permit))->toBe(['threshold_15', 'threshold_30']);
    expect(AppNotification::where('type', 'expiry')->count())->toBe(2);

    $this->travelBack();
});

it('buckets a permit the scan has never seen into the tightest threshold it has reached', function () {
    // 22 days left: past the 30-day mark, not yet at 15. It gets the 30-day
    // bucket, once — the reminder that is still true.
    $permit = reminderPermit(22);

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    expect(noticeKinds($permit))->toBe(['threshold_30']);
});

it('never claims to have sent a threshold it skipped past', function () {
    // First sight of this permit is at 3 days. It is told at 7 and then at 1;
    // it is never told "expires in 30 days", because that is false.
    $permit = reminderPermit(3);

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    expect(noticeKinds($permit))->toBe(['threshold_7']);
    expect(noticeKinds($permit))->not->toContain('threshold_30');
    expect(noticeKinds($permit))->not->toContain('threshold_15');
});

it('leaves permits beyond the widest threshold alone', function () {
    $permit = reminderPermit(45);

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    expect(noticeKinds($permit))->toBe([]);
    expect(AppNotification::count())->toBe(0);
});

it('does not chase a permit whose renewal is already filed', function () {
    $permit = reminderPermit(15);
    fileRenewalAgainst($permit, ApplicationStatus::UnderReview);

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    expect(noticeKinds($permit))->toBe([]);
    expect(AppNotification::where('type', 'expiry')->count())->toBe(0);
});

it('still chases a permit whose renewal was only drafted, never filed', function () {
    $permit = reminderPermit(15);
    fileRenewalAgainst($permit, ApplicationStatus::Draft, submitted: false);

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    // A draft is an intention, not a filing. Missing this is how a business
    // that half-started a renewal ends up lapsing in silence.
    expect(noticeKinds($permit))->toBe(['threshold_15']);
});

it('still chases a permit whose renewal was rejected', function () {
    $permit = reminderPermit(7);
    fileRenewalAgainst($permit, ApplicationStatus::Rejected);

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    expect(noticeKinds($permit))->toBe(['threshold_7']);
});

it('expires a past-due permit and tells the owner once', function () {
    $permit = reminderPermit(-3);

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    expect($permit->fresh()->status)->toBe(PermitStatus::Expired);
    // Just the lapse notice: the renewal nudge is an escalation a week later,
    // not a second message on the same night saying the same thing.
    expect(noticeKinds($permit))->toBe(['expired']);
});

it('escalates to a renewal nudge only once the permit has been lapsed a week', function () {
    $permit = reminderPermit(-1);
    $start = CarbonImmutable::now();

    $this->artisan('biztrack:scan-permits')->assertSuccessful();
    expect(noticeKinds($permit))->toBe(['expired']);

    // Day 6 of being lapsed: still just the lapse notice.
    $this->travelTo($start->addDays(5));
    $this->artisan('biztrack:scan-permits')->assertSuccessful();
    expect(noticeKinds($permit))->toBe(['expired']);

    // Day 8: still unrenewed, so now the nudge — once, however many nights run.
    $this->travelTo($start->addDays(7));
    $this->artisan('biztrack:scan-permits')->assertSuccessful();
    $this->artisan('biztrack:scan-permits')->assertSuccessful();
    expect(noticeKinds($permit))->toBe(['expired', 'renewal_due']);

    $this->travelBack();
});

it('flips a past-due permit silently when its renewal is already filed', function () {
    $permit = reminderPermit(-3);
    fileRenewalAgainst($permit, ApplicationStatus::UnderReview);

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    // The status flip is a fact and happens either way; "please file a renewal"
    // would be false, so neither the message nor its ledger row is written.
    expect($permit->fresh()->status)->toBe(PermitStatus::Expired);
    expect(noticeKinds($permit))->toBe([]);
    expect(AppNotification::count())->toBe(0);
});

it('leaves long-lapsed historical permits out of the reminder ledger entirely', function () {
    // The seeded register holds thousands of permits that lapsed years ago.
    // Reminding anyone about them is untrue and would inflate "Reminders Sent"
    // with messages nobody could act on.
    $old = reminderPermit(-400);

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    expect($old->fresh()->status)->toBe(PermitStatus::Expired);
    expect(noticeKinds($old))->toBe([]);
    expect(AppNotification::count())->toBe(0);
});

it('does not chase the permit of a closed business', function () {
    $business = Business::orderByDesc('id')->firstOrFail();
    $permit = reminderPermit(15, $business);
    $business->delete();

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    // Nobody is left to renew it, and the officer's watchlist excludes closed
    // businesses too — a reminder here would be about a permit that screen does
    // not even show.
    expect(noticeKinds($permit))->toBe([]);
    expect(AppNotification::count())->toBe(0);
});

it('writes a ledger row only when a notification actually went out', function () {
    // The first live run of this scan wrote 15 rows for permits whose business
    // was closed, so `$permit->business` resolved to null through the soft-delete
    // scope, the push silently did nothing, and "Reminders Sent" over-counted by
    // 15. The ledger is a record of sends; one row must mean one message.
    $closed = Business::orderByDesc('id')->firstOrFail();
    reminderPermit(30, $closed);
    $closed->delete();

    $live = reminderPermit(7);

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    expect(PermitExpiryNotice::count())->toBe(AppNotification::where('type', 'expiry')->count());
    expect(noticeKinds($live))->toBe(['threshold_7']);
});

it('makes the Reminders Sent KPI read a real number once the scan has run', function () {
    reminderPermit(30);
    reminderPermit(7);

    /*
     * Read as BPLO. Renewal Risk is spec §2 "(Admin - BPLO)" and sits on
     * `analytics.view`, which the super admin does not hold — it holds
     * `analytics.processing_time` and nothing else in analytics. BPLO is also the
     * office the KPI is for: it issues the permits these reminders are about.
     *
     * assertOk() on both reads, because the assertion underneath is on a figure
     * dug out of the body. Before it was here, the 403 that followed the
     * permission split surfaced as "null is identical to 0" from a line about
     * reminder counting, which named neither the status nor the route.
     */
    expect(
        $this->withHeaders(authAs('bplo@biztrack.local'))
            ->getJson('/api/v1/analytics/renewal-risk')
            ->assertOk()
            ->json('data.reminders_sent')
    )->toBe(0);

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    // Not a counter the command increments — the KPI counts ledger rows, so this
    // is the same two reminders seen from the analytics side.
    expect(
        $this->withHeaders(authAs('bplo@biztrack.local'))
            ->getJson('/api/v1/analytics/renewal-risk')
            ->assertOk()
            ->json('data.reminders_sent')
    )->toBe(2);
});
