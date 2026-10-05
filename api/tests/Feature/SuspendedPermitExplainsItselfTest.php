<?php

use App\Enums\ClearanceStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\ApplicationPermitType;
use App\Models\AppNotification;
use App\Models\Barangay;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Services\WorkflowService;
use Smalot\PdfParser\Parser;

/*
 * A suspended Business Permit says why, chases the owner, and surfaces to BPLO.
 *
 * Client, 5 October 2026, choosing "Explain it and chase it": *"Show WHY it is
 * suspended and WHICH office caused it — on the permit page, the Track row and
 * the public QR page; the PDF prints SUSPENDED; remind the owner every 7 days;
 * after 30 days unresolved BPLO sees it in a list and may revoke it with a
 * reason."*
 *
 * Built on its own fixture rather than PermitReleasedAtPaymentTest's, because
 * Pest helpers do not load across files when one file runs alone. The permit is
 * written directly and the Sanitary row moved to For Inspection directly: the
 * subject here is what the suspension RECORDS and SHOWS, and the real refusal
 * call (`rejectClearance`) is what suspends it.
 */
const SUSPENSION_REASON = 'No potable water connection at the premises.';

/** A filing for the Business Permit and the Sanitary Permit, its Business Permit Active. */
function suspendableFiling(): array
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Explained Store '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(100000, 999999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Reason Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 250000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::whereIn('code', [PermitType::OUTCOME_CODE, 'SANITARY'])->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    $permit = Permit::create([
        'permit_number' => 'TEST-'.random_int(100000, 999999),
        'application_id' => $appId,
        'business_id' => $businessId,
        'permit_type_id' => PermitType::where('code', PermitType::OUTCOME_CODE)->value('id'),
        'status' => PermitStatus::Active,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now(),
    ]);

    return [Application::findOrFail($appId), $permit];
}

/** The Sanitary office refuses after its visit, through the real refusal. */
function sanitaryRefuses(Application $app): void
{
    $row = ApplicationPermitType::where('application_id', $app->id)
        ->where('permit_type_id', PermitType::where('code', 'SANITARY')->value('id'))
        ->firstOrFail();
    $row->update(['status' => ClearanceStatus::ForInspection]);

    authAs('sanitary@biztrack.local');
    app(WorkflowService::class)->rejectClearance($row->fresh(), SUSPENSION_REASON);
}

function sanitaryOffice(): string
{
    return PermitType::where('code', 'SANITARY')->firstOrFail()->department->name;
}

function stillSuspendedNotices(): int
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    return AppNotification::where('user_id', $owner->id)
        ->where('title', 'Business Permit still suspended')
        ->count();
}

it('records when, why and for which permit a suspension happened', function () {
    [$app, $permit] = suspendableFiling();
    sanitaryRefuses($app);

    $permit->refresh();
    expect($permit->status)->toBe(PermitStatus::Suspended)
        ->and($permit->suspended_at)->not->toBeNull()
        ->and($permit->suspension_reason)->toBe(SUSPENSION_REASON)
        ->and($permit->suspendedFor->code)->toBe('SANITARY')
        ->and($permit->suspension_reminded_at)->toBeNull();
});

it('gives the owner the reason and the office, and the public only the office and date', function () {
    [$app, $permit] = suspendableFiling();
    sanitaryRefuses($app);

    authAs('owner@biztrack.local');
    $own = test()->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data');

    expect($own['suspended_at'])->toBe(now()->toDateString())
        ->and($own['suspended_days'])->toBe(0)
        ->and($own['suspension_reason'])->toBe(SUSPENSION_REASON)
        ->and($own['suspended_for'])->toBe([
            'code' => 'SANITARY',
            'name' => PermitType::where('code', 'SANITARY')->value('name'),
            'office' => sanitaryOffice(),
        ]);

    // The QR page: which permit, which office, since when — never the words.
    app('auth')->forgetGuards();
    $public = test()->getJson("/api/v1/verify/{$permit->permit_number}")->assertOk();
    expect($public->json('data.status'))->toBe('suspended')
        ->and($public->json('data.suspended_at'))->toBe(now()->toDateString())
        ->and($public->json('data.suspended_for.office'))->toBe(sanitaryOffice())
        ->and($public->json('data'))->not->toHaveKey('suspension_reason')
        ->and($public->getContent())->not->toContain('potable water');

    // The Track row names the permit and office under the Suspended chip.
    authAs('owner@biztrack.local');
    $row = collect(test()->getJson('/api/v1/applications?q='.$app->tracking_id)->assertOk()->json('data'))
        ->firstWhere('id', $app->id);
    $mayors = collect($row['permit_types'])->firstWhere('code', PermitType::OUTCOME_CODE);
    expect($mayors['permit_status'])->toBe('suspended')
        ->and($mayors['suspension'])->toBe([
            'for' => PermitType::where('code', 'SANITARY')->value('name'),
            'office' => sanitaryOffice(),
        ]);
});

it('says nothing about a suspension on a permit that is not suspended', function () {
    [, $permit] = suspendableFiling();

    authAs('owner@biztrack.local');
    $own = test()->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data');

    expect($own['suspended_at'])->toBeNull()
        ->and($own['suspension_reason'])->toBeNull()
        ->and($own['suspended_for'])->toBeNull();
});

it('clears the cause when the permit is reinstated, and when BPLO lifts it', function () {
    [$app, $permit] = suspendableFiling();
    sanitaryRefuses($app);

    // The office grants it after all: nothing is refused, so it comes back.
    ApplicationPermitType::where('application_id', $app->id)
        ->where('permit_type_id', $permit->fresh()->suspended_for_permit_type_id)
        ->update(['status' => ClearanceStatus::Approved->value]);
    app(WorkflowService::class)->reconsiderSuspension($app->fresh());

    $permit->refresh();
    expect($permit->status)->toBe(PermitStatus::Active)
        ->and($permit->suspended_at)->toBeNull()
        ->and($permit->suspension_reason)->toBeNull()
        ->and($permit->suspended_for_permit_type_id)->toBeNull();

    [$other, $second] = suspendableFiling();
    sanitaryRefuses($other);
    app(WorkflowService::class)->liftOutcomeSuspension($second->fresh(), 'Refused in error.');

    expect($second->fresh()->suspended_at)->toBeNull()
        ->and($second->fresh()->suspended_for_permit_type_id)->toBeNull();
});

it('reminds the owner at 7 and 14 days, and not at 8', function () {
    [$app, $permit] = suspendableFiling();
    sanitaryRefuses($app);
    $before = stillSuspendedNotices();

    $this->travel(6)->days();
    $this->artisan('biztrack:scan-permits')->assertSuccessful();
    expect(stillSuspendedNotices())->toBe($before);

    $this->travel(1)->days(); // day 7
    $this->artisan('biztrack:scan-permits')->assertSuccessful();
    expect(stillSuspendedNotices())->toBe($before + 1);

    $notice = AppNotification::where('title', 'Business Permit still suspended')->latest('id')->firstOrFail();
    expect($notice->body)->toContain('suspended for 7 days')
        ->and($notice->body)->toContain(PermitType::where('code', 'SANITARY')->value('name').' is still pending with '.sanitaryOffice());

    // A second run the same day, and the day after: the window is not up.
    $this->artisan('biztrack:scan-permits')->assertSuccessful();
    $this->travel(1)->days(); // day 8
    $this->artisan('biztrack:scan-permits')->assertSuccessful();
    expect(stillSuspendedNotices())->toBe($before + 1);

    $this->travel(6)->days(); // day 14
    $this->artisan('biztrack:scan-permits')->assertSuccessful();
    expect(stillSuspendedNotices())->toBe($before + 2)
        ->and($permit->fresh()->suspension_reminded_at)->not->toBeNull();
});

it('lists a permit suspended 30 days or more for BPLO, and not one suspended 29', function () {
    [$app, $permit] = suspendableFiling();
    sanitaryRefuses($app);

    $listed = fn () => collect(
        test()->withHeaders(authAs('bplo@biztrack.local'))
            ->getJson('/api/v1/permits?detail=1&suspended_over_days=30&per_page=100')
            ->assertOk()->json('data'),
    )->firstWhere('id', $permit->id);

    $this->travel(29)->days();
    expect($listed())->toBeNull();

    $this->travel(1)->days();
    $row = $listed();
    expect($row)->not->toBeNull()
        ->and($row['suspended_days'])->toBe(30)
        ->and($row['suspended_for']['office'])->toBe(sanitaryOffice());
});

it('prints SUSPENDED and the date on the certificate', function () {
    [$app, $permit] = suspendableFiling();
    sanitaryRefuses($app);

    authAs('owner@biztrack.local');
    $text = (new Parser)
        ->parseContent(test()->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent())
        ->getText();

    expect($text)->toContain('SUSPENDED')
        ->and($text)->toContain(strtoupper(now()->format('F j, Y')));
});
