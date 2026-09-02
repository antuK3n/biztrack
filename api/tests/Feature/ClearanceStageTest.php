<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationDocument;
use App\Models\ApplicationOfficeForm;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\FeeAssessment;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Services\ClearanceService;
use App\Services\WorkflowService;
use App\Support\PermitFees;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * The LGU clearance stage (docs/clearances-after-payment.md).
 *
 * PAYMENT FIRST, CLEARANCES AFTER. The wizard files the business permit alone;
 * its Tax Order of Payment is settled; the six clearances open. Applying for one
 * re-assesses the filing so that office's lines join a running balance and
 * routes that office an assignment there and then, and no permit is released
 * until the balance reaches zero.
 *
 * These tests used to assert the opposite ordering — the six were ticked inside
 * the wizard, one Tax Order of Payment covered the lot, the stage was open
 * exactly while the filing was a draft. The client reversed it. Every test that
 * stated the old rule has been rewritten to state the new one rather than
 * deleted, and the ones whose NAME stated it have been renamed, because a test
 * called "opens the stage while the application is still a draft" passing
 * against code that shuts the stage on a draft is worse than no test.
 *
 * Four mechanisms come back with the reversal and each grew a bug the last time
 * it was built, so each has its own case below: the unlock, the accrual, the
 * second payment, and the release gate.
 */

/**
 * A draft owned by `owner@biztrack.local`, asking for the business permit and
 * nothing else — which is now where the clearance stage is SHUT.
 *
 * The fee profile is deliberately full. An empty one prices several clearances
 * at zero (no employees means no health certificates, no floor area means no
 * sanitary inspection fee), and a test asserting "the balance went up" against a
 * profile that cannot produce a fee proves nothing.
 */
function draftClearanceApplication(string $name = 'Clearance Stage Cafe'): Application
{
    authAs('owner@biztrack.local');

    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => $name,
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-CLR-001',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '12 Clearance Ave.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 500000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
        'fee_profile' => [
            'gross_sales' => 2000000,
            'capitalization' => 500000,
            'employees' => 12,
            'employees_in_lgu' => 6,
            'floor_area_sqm' => 120,
            'storeys' => 2,
            'business_structure' => 'sole_proprietorship',
            'property_use' => 'non_residential',
        ],
    ])->assertCreated()->json('data.id');

    return Application::findOrFail($appId);
}

/** The same filing, submitted: assessed for the business permit, not yet paid. */
function submittedClearanceApplication(string $name = 'Submitted Stage Store'): Application
{
    $app = draftClearanceApplication($name);

    test()->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();

    return $app->fresh();
}

/**
 * The same filing, paid — which is where the clearance stage OPENS.
 *
 * Every test about applying, withdrawing or submitting a copy starts here now.
 * That is the reversal in one helper: none of those acts is reachable on a
 * draft, so a fixture that stopped short of the payment would be testing a
 * screen the applicant cannot get to.
 */
function paidClearanceApplication(string $name = 'Paid Stage Cafe'): Application
{
    $app = submittedClearanceApplication($name);

    test()->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    return $app->fresh();
}

/** Every fee line on a filing's Tax Order of Payment, lower-cased and joined. */
function topOrderLabels(Application $app): string
{
    $fee = FeeAssessment::where('application_id', $app->id)->firstOrFail();

    return strtolower(implode(' | ', array_column($fee->line_items, 'label')));
}

/**
 * `meta` with its three money fields normalised to floats.
 *
 * PHP encodes a whole float as a JSON integer — `json_encode(0.0)` is `0` — so
 * the ledger arrives as an int or a float depending on whether the amount
 * happens to have centavos. That is a JSON fact and not an API defect (the
 * browser reads both as `number`), but a test comparing pesos has to normalise
 * it somewhere, and doing it once here is better than scattering casts through
 * every assertion until one is forgotten and a case silently stops checking a
 * figure.
 */
function ledger(array $meta): array
{
    foreach (['total_assessed', 'total_paid', 'balance_due'] as $key) {
        if (array_key_exists($key, $meta)) {
            $meta[$key] = (float) $meta[$key];
        }
    }

    return $meta;
}

/** The ledger as the clearance screen reads it. */
function clearanceMeta(Application $app): array
{
    return ledger(
        test()->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json('meta')
    );
}

beforeEach(function () {
    // Keep uploads out of the developer's real storage directory.
    Storage::fake('local');
});

// --- the shape ---------------------------------------------------------------

it('lists the six clearances and never the permit the application is for', function () {
    $app = paidClearanceApplication();

    $rows = $this->getJson("/api/v1/applications/{$app->id}/clearances")
        ->assertOk()->json('data');

    $codes = collect($rows)->pluck('permit_type.code');

    expect($codes)->toHaveCount(6)
        ->and($codes->all())->toBe(['ZONING', 'SANITARY', 'FSIC', 'CEC', 'OCCUPANCY', 'MARKET'])
        // The mayor's permit is the outcome of the application, not a clearance
        // to pick, so it is not on the chooser at all.
        ->and($codes)->not->toContain(PermitType::OUTCOME_CODE);
});

it('carries the full contract shape on every row', function () {
    $app = paidClearanceApplication();

    $row = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json('data'))
        ->firstWhere('permit_type.code', 'SANITARY');

    expect($row)->toHaveKeys([
        'permit_type', 'state', 'has_office_form', 'office_form_complete',
        'held_document', 'assignment', 'fee_preview',
    ]);
    expect($row['permit_type'])->toHaveKeys(['id', 'code', 'name', 'department']);
    expect($row['permit_type']['department'])->toHaveKeys(['code', 'name']);
    expect($row['permit_type']['department']['code'])->toBe('CHO');
    expect($row['state'])->toBe('available');
    // SANITARY is one of the clearances with an applicant-facing sheet;
    // nothing is saved on it yet.
    expect($row['has_office_form'])->toBeTrue()
        ->and($row['office_form_complete'])->toBeFalse()
        ->and($row['held_document'])->toBeNull()
        ->and($row['assignment'])->toBeNull()
        ->and($row['fee_preview'])->toBeString();

    /*
     * All six open a form now, and Market was the last to.
     *
     * This assertion used to be a PAIR — zoning true, market false — recording
     * that the difference between the two was which paper the city had given
     * us. Zoning was settled when CPDD sent MCG-CPDD-FO-003 v1.2 and its sheet
     * was rebuilt against the real form. Market was settled the other way:
     * checklist item 109 says the city has no paper version and asks for one to
     * be created, so its sheet is written rather than transcribed (see the
     * header of web OfficeFormStep.tsx for the three questions and why each is
     * there).
     *
     * Asserted over the whole set rather than over two named codes, because the
     * claim is no longer about a pair. Every clearance the register seeds opens
     * a sheet, so a seventh one added without one has to come here and say so.
     */
    $rows = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->json('data'));
    expect($rows->pluck('has_office_form')->all())->each->toBeTrue()
        ->and($rows->firstWhere('permit_type.code', 'MARKET')['has_office_form'])->toBeTrue();
});

/*
 * RENAMED from "carries no ledger in meta, because a draft owes nothing".
 *
 * That name stated the old ordering as a fact: nothing was chargeable before
 * submission and the stage was shut after it, so a ledger could only ever read
 * zero. Under this ordering the stage is the only place a balance is ever
 * raised, and the screen cannot show the applicant what they now owe without
 * these three keys. The contract in docs/clearances-after-payment.md names
 * them.
 */
it('carries the ledger in meta, because applying raises a balance', function () {
    $app = paidClearanceApplication();

    $meta = clearanceMeta($app);

    expect(array_keys($meta))->toBe([
        'unlocked', 'locked_reason', 'total_assessed', 'total_paid', 'balance_due',
    ]);

    // Paid in full for the business permit, nothing applied for yet.
    expect($meta['balance_due'])->toBe(0.0)
        ->and($meta['total_paid'])->toBe($meta['total_assessed'])
        ->and($meta['total_assessed'])->toBeGreaterThan(0);
});

// --- the unlock rule ---------------------------------------------------------

/*
 * RENAMED from "opens the stage while the application is still a draft", which
 * is the exact rule that was reversed. A draft is now the furthest a filing can
 * be from the clearance stage, and the sentence has to say so — under the old
 * ordering `lockedReason` returned null for a draft, so a screen rendering this
 * state today would have had nothing at all to put on it.
 */
it('keeps the stage shut on a draft, and says how to open it', function () {
    $app = draftClearanceApplication();

    $meta = clearanceMeta($app);

    expect($meta['unlocked'])->toBeFalse()
        ->and($meta['locked_reason'])->toBeString()
        // Names the one step that opens it. It used to name settling the Tax
        // Order of Payment as a second step, and that stopped being true when
        // the gate moved off the money.
        ->and($meta['locked_reason'])->toContain('submit');
});

/*
 * REPLACES "keeps the stage shut while the Tax Order of Payment is unsettled".
 *
 * That rule is gone, and it is worth saying why rather than deleting quietly.
 * Payment in this build is a dummy, so `hasClearedPayment` was false forever:
 * the gate never opened, and testers twice reported the six clearances as
 * missing outright. The ordering the client asked for survives — the bill is
 * still raised and still assessed — but it no longer blocks.
 */
it('opens the stage on submission, with the bill still outstanding', function () {
    $app = submittedClearanceApplication();

    $meta = clearanceMeta($app);

    expect($meta['unlocked'])->toBeTrue()
        ->and($meta['locked_reason'])->toBeNull()
        // The point of the change: open AND unpaid at the same time. If this
        // balance were zero the test would prove nothing about the gate.
        ->and($meta['balance_due'])->toBeGreaterThan(0);
});

it('leaves the stage open once the first payment clears', function () {
    $app = submittedClearanceApplication();

    expect(clearanceMeta($app)['unlocked'])->toBeTrue();

    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    $meta = clearanceMeta($app);

    // Paying changes the balance and nothing about the gate.
    expect($meta['unlocked'])->toBeTrue()
        ->and($meta['locked_reason'])->toBeNull()
        ->and($meta['balance_due'])->toBe(0.0);
});

/*
 * The unlock asks the PAYMENTS LEDGER, not the status — and this is the case
 * that tells the two apart. A filing returned for revision has already paid, so
 * the stage stays open: being sent back is the one moment an office has told
 * the applicant something is missing, and "you also need a locational
 * clearance" is a thing offices say.
 */
it('keeps the stage open on a filing an office returned for revision', function () {
    $app = paidClearanceApplication();
    classifyAsOfficer($app);

    $assignment = ApplicationAssignment::where('application_id', $app->id)->firstOrFail();
    authAs('bplo@biztrack.local');
    $this->postJson("/api/v1/assignments/{$assignment->id}/return", ['remarks' => 'Send the lease contract.'])
        ->assertOk();

    authAs('owner@biztrack.local');
    expect(Application::findOrFail($app->id)->status)->toBe(ApplicationStatus::Returned)
        ->and(clearanceMeta($app)['unlocked'])->toBeTrue();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();
});

/*
 * RENAMED from "refuses every write once the filing has been submitted". The
 * refusal now runs the other way round: submission is not what closes the
 * stage, an unsettled Tax Order of Payment is what has not yet opened it.
 */
/*
 * RENAMED from "refuses every write until the first payment has cleared". The
 * gate moved off the money, so a submitted-but-unpaid filing now accepts these
 * writes — that is the whole point of the change. A DRAFT still refuses them,
 * and it is the case worth holding down: a clearance applied for against a
 * draft would raise a balance on a filing that may never be sent.
 */
it('refuses every write while the application is still a draft', function () {
    $app = draftClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertStatus(422);
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertStatus(422);
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/held", [
        'file' => UploadedFile::fake()->create('zoning.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);

    // Nothing leaked through: no permit type attached and no document stored.
    //
    // The assessment is NOT checked here the way it was when this test ran on a
    // submitted filing: a draft has no Tax Order of Payment to rewrite, so
    // `topOrderLabels` has no FeeAssessment to read and throws. Its absence is
    // the stronger statement anyway.
    expect($app->fresh()->permitTypes->pluck('code'))->not->toContain('ZONING')
        ->and(ApplicationDocument::where('application_id', $app->id)->count())->toBe(0);
});

it('accepts an application for a clearance on a submitted filing that is unpaid', function () {
    $app = submittedClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();

    // Applied for, and the fee joined the bill rather than being waived — the
    // gate moved, the money did not stop mattering.
    expect($app->fresh()->permitTypes->pluck('code'))->toContain('ZONING')
        ->and(clearanceMeta($app)['balance_due'])->toBeGreaterThan(0);
});

it('keeps the stage shut on a filing that was rejected', function () {
    $app = paidClearanceApplication();

    authAs('bplo@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Wrong zone.'])->assertOk();

    authAs('owner@biztrack.local');
    $meta = clearanceMeta($app);

    // Paid, so `hasClearedPayment` is true — and still shut. There is nothing to
    // apply for under a filing the LGU has closed.
    expect($meta['unlocked'])->toBeFalse()
        ->and($meta['locked_reason'])->toContain('was not approved');

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertStatus(422);
});

// --- applying: the fee joins the balance -------------------------------------

/*
 * RENAMED from "writes no fee assessment when a clearance is applied for on a
 * draft". That test pinned the accrual being ABSENT — the whole point of the
 * previous ordering. This is its inverse and the heart of this one: applying
 * rewrites the assessment on the spot, `total_paid` does not move, and the
 * difference is money the applicant now owes.
 */
it('adds the clearance’s fee lines to the balance the moment it is applied for', function () {
    $app = paidClearanceApplication();

    $before = clearanceMeta($app);
    expect($before['balance_due'])->toBe(0.0);

    $body = $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")
        ->assertOk()->json();

    $after = ledger($body['meta']);

    // Sec. 3.D.01: filing 45 + land use verification 345 + processing 345.
    expect($body['data']['state'])->toBe('applied')
        ->and(round($after['total_assessed'] - $before['total_assessed'], 2))->toBe(735.0)
        // Nothing was paid by applying, so the whole of it is outstanding.
        ->and($after['total_paid'])->toBe($before['total_paid'])
        ->and($after['balance_due'])->toBe(735.0)
        ->and(topOrderLabels($app))->toContain('locational clearance');
});

it('puts exactly that office’s fee lines on the assessment and no other office’s', function () {
    $app = paidClearanceApplication('Zoned Permit Cafe');

    $before = FeeAssessment::where('application_id', $app->id)->firstOrFail()->line_items;
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();
    $after = FeeAssessment::where('application_id', $app->id)->firstOrFail()->line_items;

    $added = array_values(array_diff(array_column($after, 'label'), array_column($before, 'label')));
    $removed = array_values(array_diff(array_column($before, 'label'), array_column($after, 'label')));

    // Every added line is the City Planning Office's and no other's.
    expect($added)->toHaveCount(3);
    foreach ($added as $label) {
        expect(strtolower($label))->toContain('locational clearance');
    }

    /*
     * Nothing came off, and that is the assertion the accrual lives or dies by.
     * Re-assessment rewrites the whole FeeAssessment row, so a rule that
     * stopped matching between the two runs would silently delete a line the
     * applicant has already paid for — and `balance_due` floors at zero, so the
     * loss would not even show up as a negative.
     */
    expect($removed)->toBe([]);
});

it('bills only the chosen offices, not every office', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    $labels = topOrderLabels($app);

    // The City Health Office's line is there...
    expect($labels)->toContain('sanitary inspection fee');
    // ...and no other office's clearance-gated line came with it.
    expect($labels)->not->toContain('locational clearance')
        ->and($labels)->not->toContain('fire safety inspection certificate fee');
});

/*
 * The whole point of the reorder, in one test.
 *
 * RENAMED from "bills the business permit and every clearance chosen on one Tax
 * Order of Payment", which was the previous ordering's headline claim. There are
 * two moments now, and the assertions run in that order: the first Tax Order of
 * Payment carries the business permit ALONE, the four clearances applied for
 * afterwards accrue onto the same row, and a second payment settles them.
 */
it('bills the business permit first, then each clearance onto the same balance', function () {
    $app = paidClearanceApplication('Four Office Cafe');

    /*
     * Moment one. What the applicant has already paid is the mayor's permit and
     * nothing else — no clearance was choosable when this assessment was
     * written, so none of the four offices' gated lines can be on it.
     */
    $businessOnly = topOrderLabels($app);
    expect($businessOnly)
        ->not->toContain('locational clearance')
        ->not->toContain('sanitary inspection fee')
        ->not->toContain('fire safety inspection certificate fee')
        ->not->toContain('certificate of use/occupancy');
    expect(clearanceMeta($app)['balance_due'])->toBe(0.0);

    // Moment two: four clearances, four re-assessments, one growing balance.
    $running = 0.0;
    foreach (['ZONING', 'SANITARY', 'FSIC', 'OCCUPANCY'] as $code) {
        $meta = $this->postJson("/api/v1/applications/{$app->id}/clearances/{$code}/apply")
            ->assertOk()->json('meta');
        $meta = ledger($meta);

        expect($meta['balance_due'])->toBeGreaterThan($running);
        $running = $meta['balance_due'];
    }

    expect(topOrderLabels($app))
        ->toContain('locational clearance')                      // CPDO
        ->toContain('sanitary inspection fee')                   // CHO
        ->toContain('fire safety inspection certificate fee')    // BFP
        ->toContain('certificate of use/occupancy');             // OBO

    /*
     * And the second payment charges the BALANCE, not the assessment total.
     * Charging the total here would bill the applicant for the mayor's permit a
     * second time — the failure `PermitFees::balance` exists to prevent.
     */
    $assessed = (float) FeeAssessment::where('application_id', $app->id)->firstOrFail()->total_amount;
    $paid = (float) $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertCreated()->json('data.amount');

    expect($paid)->toBe($running)
        ->and($paid)->toBeLessThan($assessed)
        ->and(PermitFees::balance(Application::findOrFail($app->id))['balance_due'])->toBe(0.0);
});

/*
 * RENAMED from "routes every chosen clearance to its own office when the
 * payment clears". Routing at payment is exactly what cannot work under this
 * ordering: `routeToDepartments` has already run by the time the stage opens,
 * so a clearance chosen afterwards would be billed and never worked.
 */
it('routes a clearance to its own office the moment it is applied for', function () {
    $app = paidClearanceApplication();
    $cho = Department::where('code', 'CHO')->firstOrFail();

    // The first payment routed BPLO and nobody else.
    expect(ApplicationAssignment::where('application_id', $app->id)->count())->toBe(1)
        ->and(ApplicationAssignment::where('application_id', $app->id)->where('department_id', $cho->id)->exists())
        ->toBeFalse();

    $row = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")
        ->assertOk()->json('data');

    $assignment = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', $cho->id)->first();

    /*
     * The objection to apply-time routing under the old ordering was that
     * `assigned_at` starts the service-time clock ProcessingTimeAnalytics,
     * StaffingSimulation and DashboardAnalytics measure an office by, and
     * stamping it inside somebody's unfinished draft charged CHO for the days
     * the applicant spent typing. There is no draft here — the stage does not
     * open until the filing is paid — so the clock starts when the office
     * genuinely has work.
     */
    expect($row['state'])->toBe('applied')
        ->and($assignment)->not->toBeNull()
        ->and($assignment->status->value)->toBe('pending')
        ->and($assignment->assigned_at)->not->toBeNull()
        ->and($row['assignment']['id'])->toBe($assignment->id);
});

it('previews what applying will add before it is applied for', function () {
    $app = paidClearanceApplication();

    $preview = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->json('data'))
        ->firstWhere('permit_type.code', 'ZONING')['fee_preview'];

    $meta = ledger($this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")
        ->assertOk()->json('meta'));

    // The preview is the promise; the balance is what it cost. Under this
    // ordering the applicant is agreeing to money owed on a filing they have
    // already paid for, so the two had better be the same number.
    expect($preview)->toBe('₱735.00')
        ->and($meta['balance_due'])->toBe(735.0);
});

// --- un-applying -------------------------------------------------------------

it('takes the clearance, its fee lines and its assignment back off when it is un-applied', function () {
    $app = paidClearanceApplication();
    $cpdo = Department::where('code', 'CPDO')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();
    expect(ApplicationAssignment::where('application_id', $app->id)->where('department_id', $cpdo->id)->exists())
        ->toBeTrue();

    $body = $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk()->json();

    expect($body['data']['state'])->toBe('available')
        ->and($body['data']['assignment'])->toBeNull()
        ->and($app->fresh()->permitTypes->pluck('code'))->not->toContain('ZONING')
        // The office stops being asked. Leaving the assignment behind would
        // park a live queue item on an office for work nobody wants any more,
        // and isFullyCleared() would then hold the permits until it was worked.
        ->and(ApplicationAssignment::where('application_id', $app->id)->where('department_id', $cpdo->id)->exists())
        ->toBeFalse()
        // The balance falls with it, and the fee lines come off.
        ->and(ledger($body['meta'])['balance_due'])->toBe(0.0)
        ->and(topOrderLabels($app))->not->toContain('locational clearance');
});

/*
 * ASSUMPTION, asserted so it is visible rather than merely commented: a
 * clearance fee already PAID is not refunded by withdrawing.
 *
 * `total_assessed` falls, `total_paid` stays where it is, and `balance_due`
 * floors at zero rather than reporting a credit the applicant could spend on
 * the next clearance. Refundability is an open question with BPLO
 * (docs/clearances-after-payment.md), and a system that quietly issued credit
 * would have answered it.
 */
it('does not refund a clearance fee that has already been paid', function () {
    $app = paidClearanceApplication('Withdrawn After Paying Co.');

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    $paidBefore = clearanceMeta($app)['total_paid'];

    $meta = ledger($this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")
        ->assertOk()->json('meta'));

    expect($meta['total_paid'])->toBe($paidBefore)
        ->and(round($paidBefore - $meta['total_assessed'], 2))->toBe(735.0)
        // Overpaid by ₱735 and the balance says zero, not minus 735.
        ->and($meta['balance_due'])->toBe(0.0);
});

/*
 * No longer defence in depth — this guard is on the live path now.
 *
 * Under the old ordering withdrawal was only possible on a draft and no office
 * held an assignment on a draft, so `officeHasActed` could not be reached
 * through the product at all. Applying routes the office immediately now, so an
 * office really can pick the work up between Apply and Withdraw, and this is
 * the rule that stops "in progress" being a state an applicant can escape by
 * pressing cancel.
 */
it('will not let the applicant withdraw a clearance the office has already acted on', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', Department::where('code', 'CHO')->firstOrFail()->id)
        ->update(['status' => 'in_progress']);

    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")
        ->assertStatus(422);

    expect($app->fresh()->permitTypes->pluck('code'))->toContain('SANITARY')
        // And the money stays owed, because the work is being done.
        ->and(clearanceMeta($app)['balance_due'])->toBeGreaterThan(0);
});

// --- the held copy: no fee ---------------------------------------------------

it('adds no fee and no permit type when a held copy is uploaded', function () {
    $app = paidClearanceApplication();

    $body = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated()->json();

    expect($body['data']['state'])->toBe('submitted')
        ->and($body['data']['held_document']['name'])->toBe('sanitary.pdf')
        ->and($body['data']['held_document']['size'])->toBeGreaterThan(0)
        // No permit type means no form, no assignment and no fee — the whole
        // reason submitting a copy is not the same act as applying.
        ->and($app->fresh()->permitTypes->pluck('code'))->not->toContain('SANITARY')
        ->and($body['data']['assignment'])->toBeNull()
        ->and(ledger($body['meta'])['balance_due'])->toBe(0.0);

    expect(topOrderLabels($app))->not->toContain('sanitary inspection fee');
});

it('records the held copy through the same mechanism the wizard uses', function () {
    $app = paidClearanceApplication();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    $doc = ApplicationDocument::where('application_id', $app->id)
        ->where('permit_type_id', $sanitary->id)
        ->with('documentType')
        ->firstOrFail();

    // Same document-type convention as DocumentController's path, so an officer
    // reading the attachment list cannot tell which screen it arrived through.
    expect($doc->documentType->code)->toBe('HELD_SANITARY')
        ->and($doc->documentType->name)->toContain('already held');
});

it('replaces an earlier held copy rather than stacking them', function () {
    $app = paidClearanceApplication();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('old.pdf', 20, 'application/pdf'),
    ])->assertCreated();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('new.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    $held = ApplicationDocument::where('application_id', $app->id)
        ->where('permit_type_id', $sanitary->id)->get();

    expect($held)->toHaveCount(1)
        ->and($held->first()->original_filename)->toBe('new.pdf');
});

it('removes the held copy and its file', function () {
    $app = paidClearanceApplication();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    $path = ApplicationDocument::where('application_id', $app->id)
        ->where('permit_type_id', $sanitary->id)->firstOrFail()->stored_path;
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $body = $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held")
        ->assertOk()->json();

    expect($body['data']['state'])->toBe('available')
        ->and($body['data']['held_document'])->toBeNull()
        ->and(ApplicationDocument::where('application_id', $app->id)->where('permit_type_id', $sanitary->id)->count())->toBe(0)
        // A "removed" document still sitting on disk is not removed: it stays
        // downloadable through /documents/{id}/download for as long as it is there.
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('keeps applying and submitting mutually exclusive', function () {
    $app = paidClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);

    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertStatus(422);
});

/*
 * The refusal above is right; for four days it was also unactionable — CLR-1.
 *
 * It told the applicant to withdraw, and 9e30b44 had removed every control that
 * could. The test directly above passed throughout, because it recovers by
 * calling DELETE /clearances/SANITARY/apply itself — an endpoint with no caller
 * on any screen. It proved the escape existed at the HTTP layer, which was true
 * and irrelevant to a person holding a mouse.
 *
 * So these are the same two endpoints in the same order, asserted as the SWITCH
 * the clearance card now performs rather than as a recovery step inside a test.
 * Both directions, because the asymmetry was the defect: held → applied has
 * always resolved itself in one click, and applied → held was impossible.
 */
it('lets an applicant switch from applying for a clearance to filing their own copy', function () {
    $app = paidClearanceApplication();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    // What the Submit dialog does on an applied card: withdraw, then upload.
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    $body = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated()->json();

    expect($body['data']['state'])->toBe('submitted')
        ->and($body['data']['held_document']['name'])->toBe('sanitary.pdf')
        ->and($app->fresh()->permitTypes->pluck('code'))->not->toContain('SANITARY')
        // Switching to the copy takes the charge back off. Leaving it on would
        // bill the applicant for a clearance the LGU is no longer performing.
        ->and(ledger($body['meta'])['balance_due'])->toBe(0.0);

    // And back again — what Apply does over an uploaded copy, once the
    // applicant has agreed to lose the file.
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held")->assertOk();
    $back = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")
        ->assertOk()->json();

    expect($back['data']['state'])->toBe('applied')
        ->and($back['data']['held_document'])->toBeNull()
        ->and(ApplicationDocument::where('application_id', $app->id)
            ->where('permit_type_id', $sanitary->id)->count())->toBe(0);

    // The filing is billed for exactly the leg it ended on.
    expect(topOrderLabels($app))->toContain('sanitary inspection fee')
        ->and(ledger($back['meta'])['balance_due'])->toBeGreaterThan(0);
});

/*
 * The invariant the switch must not spend, asserted as a count.
 *
 * No filing in the register has ever carried both an `application_permit_types`
 * row and an `application_documents.permit_type_id` row for the same clearance,
 * and everything downstream depends on that: FeeCalculator bills the permit-type
 * side, routeClearance raises an assignment from it, approveAndIssue turns
 * it into a Permit — a legal instrument that would then sit beside the
 * applicant's own copy of the same certificate on their profile.
 *
 * The switch is two guarded requests rather than one endpoint taught to write
 * both sides, so the state between them is "neither", never "both". This walks
 * the whole sequence and counts after every step, because a fix that made
 * storeHeld auto-withdraw internally would pass every test above and could
 * still leave both rows if its guards and unapply's ever drifted apart.
 */
it('never leaves a filing holding both an application and a copy of one clearance', function () {
    $app = paidClearanceApplication();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

    $both = function () use ($app, $sanitary) {
        $applied = $app->fresh()->permitTypes->contains(fn ($pt) => $pt->id === $sanitary->id);
        $held = ApplicationDocument::where('application_id', $app->id)
            ->where('permit_type_id', $sanitary->id)->exists();

        return $applied && $held;
    };

    expect($both())->toBeFalse();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    expect($both())->toBeFalse();

    // The forbidden order, refused: upload while still applied.
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);
    expect($both())->toBeFalse();

    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated();
    expect($both())->toBeFalse();

    // The other forbidden order, refused: apply while a copy is on file.
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertStatus(422);
    expect($both())->toBeFalse();
});

/*
 * The first leg of the switch can be refused, and then the second must not run.
 *
 * The card withdraws before it uploads for exactly this reason: `unapply` holds
 * three guards `storeHeld` does not — nothing issued for this type, the office
 * has not started, and the business record is still there to re-price against.
 * If an auto-withdraw were ever folded into `storeHeld`, this is the assertion
 * that would go red, because the copy would be filed on a clearance the
 * applicant is not allowed to take back.
 */
it('will not file a copy of a clearance whose office has already started work', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', Department::where('code', 'CHO')->firstOrFail()->id)
        ->update(['status' => 'in_progress']);

    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertStatus(422);
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);

    expect($app->fresh()->permitTypes->pluck('code'))->toContain('SANITARY');
});

/*
 * CLR-2 — withdrawing takes the office sheet off the filing, and keeps the words.
 *
 * Applying makes that clearance's sheet a thing the applicant must fill in, and
 * MARKET's sheet requires a market name and a stall number. With no way to
 * withdraw, five real filings carrying MARKET without a MARKET sheet were stuck
 * — the applicant had to invent a market they do not trade from, or cancel the
 * whole filing.
 *
 * Two halves, and the second is why withdrawing needs no confirmation: the
 * obligation goes (the row is no longer `applied`), and the saved answers do not.
 */
it('drops the office form obligation when a clearance is withdrawn, without discarding the answers', function () {
    $app = paidClearanceApplication();
    $market = PermitType::where('code', 'MARKET')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/MARKET/apply")->assertOk();
    $this->putJson("/api/v1/applications/{$app->id}/office-forms/MARKET", [
        'form_data' => ['market_name' => 'Malabon Central Market', 'stall_no' => 'B-14'],
    ])->assertOk();

    $body = $this->deleteJson("/api/v1/applications/{$app->id}/clearances/MARKET/apply")
        ->assertOk()->json();

    expect($body['data']['state'])->toBe('available')
        ->and($app->fresh()->permitTypes->pluck('code'))->not->toContain('MARKET');

    $saved = ApplicationOfficeForm::where('application_id', $app->id)
        ->where('permit_type_id', $market->id)->first();

    expect($saved)->not->toBeNull()
        ->and($saved->form_data['stall_no'])->toBe('B-14');

    // Re-applying finds them still there — one click back, nothing retyped.
    $this->postJson("/api/v1/applications/{$app->id}/clearances/MARKET/apply")->assertOk();
    expect(ApplicationOfficeForm::where('application_id', $app->id)
        ->where('permit_type_id', $market->id)->count())->toBe(1);
});

/*
 * The office sheet for a clearance applied for after payment MUST be writable.
 *
 * The window that allows it (OfficeFormController::ownerMayEdit) was deleted
 * when the ordering was reversed the other way, on the reasoning that a filing
 * carrying an office sheet is a draft by definition. It is not one now: every
 * clearance sheet first becomes reachable on a filing that is already under
 * review, so without the window the applicant would be billed for a clearance
 * whose form they could never fill in.
 */
it('lets the applicant fill in the sheet for a clearance applied for after payment', function () {
    $app = paidClearanceApplication();

    expect($app->status)->toBe(ApplicationStatus::UnderReview);

    $this->postJson("/api/v1/applications/{$app->id}/clearances/MARKET/apply")->assertOk();
    $this->putJson("/api/v1/applications/{$app->id}/office-forms/MARKET", [
        'form_data' => ['market_name' => 'Malabon Central Market', 'stall_no' => 'B-14'],
    ])->assertOk();

    $row = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->json('data'))
        ->firstWhere('permit_type.code', 'MARKET');

    expect($row['office_form_complete'])->toBeTrue();
});

// --- the release gate --------------------------------------------------------

/**
 * A paid filing carrying the business permit plus ZONING applied for
 * afterwards, taken as far as the offices can take it: every assignment
 * approved, nothing inspected yet, ₱735 still owed.
 *
 * The office accounts are picked by department because that is the only way a
 * sign-off happens — ApplicationVisibility keeps a reviewer to the filings
 * routed to their own office, so BPLO cannot close CPDO's assignment.
 */
function clearanceFilingAwaitingInspection(): Application
{
    $app = paidClearanceApplication();
    test()->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();

    // Confirmed on receipt, because no office may approve until a person has
    // put their name to the processing category. What is under test here is the
    // clearance stage, so that gate belongs to the fixture and not to the case.
    classifyAsOfficer($app);

    foreach (ApplicationAssignment::where('application_id', $app->id)->get() as $assignment) {
        authAs($assignment->department_id === Department::where('code', 'CPDO')->first()->id
            ? 'zoning@biztrack.local'
            : 'bplo@biztrack.local');
        test()->postJson("/api/v1/assignments/{$assignment->id}/approve")->assertOk();
    }

    return Application::findOrFail($app->id);
}

/**
 * Drive every visit booked against a filing to a pass, through the endpoint an
 * officer actually uses.
 *
 * THE RULE THIS ENCODES: all six supporting clearances — SANITARY, FSIC,
 * OCCUPANCY, CEC, ZONING, MARKET — carry `requires_inspection`, and only
 * BUSINESS does not. So a filing that asks for any clearance does NOT get its
 * permits when the last office signs off; WorkflowService::afterReviewProgress
 * books the visits and parks it in `for_inspection`, and recordInspection is
 * the only thing that can then issue, once EVERY visit has passed.
 *
 * The account is chosen by the inspecting office on purpose, and it is load
 * bearing twice over. `inspections/{id}/conduct` sits behind
 * `permission:inspection.manage`, and InspectionController scopes every read
 * and write to the caller's own department — so a visit booked for CPDO can
 * only be closed by a CPDO officer holding that permission. A 403 out of here
 * means an office was routed an inspection it cannot conduct, which is the
 * precise failure the client reported of OBO, CENRO, Market and Zoning before
 * RbacSeeder put `inspection.manage` on all six.
 */
function passEveryScheduledInspection(Application $app): void
{
    $officerFor = [
        'CHO' => 'sanitary@biztrack.local',
        'BFP' => 'fire@biztrack.local',
        'CPDO' => 'zoning@biztrack.local',
        'OBO' => 'obo@biztrack.local',
        'CENRO' => 'cenro@biztrack.local',
        'CMO-MARKET' => 'market@biztrack.local',
    ];

    $inspections = $app->inspections()->with('department')->get();

    // An empty loop would let this helper "pass" a filing that was never booked
    // an inspection at all, which is how these tests would silently stop
    // covering the inspected path if the flag were ever flipped back.
    expect($inspections)->not->toBeEmpty();

    foreach ($inspections as $inspection) {
        authAs($officerFor[$inspection->department->code]);
        test()->postJson("/api/v1/inspections/{$inspection->id}/conduct", [
            'result' => 'passed',
            'findings' => 'Premises inspected and found compliant.',
        ])->assertOk();
    }
}

/*
 * Rule 6, and the reason the balance is not decoration.
 *
 * Every office has signed off and every visit has passed. The only thing left
 * outstanding is ₱735 for the locational clearance the applicant applied for
 * after paying, and that alone withholds the permits — including the mayor's
 * permit, which was paid for in full at submission. The gate is on the FILING,
 * not per permit: the payments ledger does not attribute money to individual
 * permit types, so a per-permit gate would be a guess about which peso paid for
 * what.
 */
it('releases no permit while the clearance balance is outstanding', function () {
    $app = clearanceFilingAwaitingInspection();

    expect($app->status)->toBe(ApplicationStatus::ForInspection)
        ->and($app->permits()->count())->toBe(0);

    passEveryScheduledInspection($app);

    $held = Application::findOrFail($app->id);

    expect(PermitFees::balance($held)['balance_due'])->toBe(735.0)
        // Not approved, and not one permit issued. The work is done; the money
        // is not in.
        ->and($held->status)->toBe(ApplicationStatus::ForInspection)
        ->and($held->permits()->count())->toBe(0);
});

/*
 * The failure mode the spec records verbatim: "a balance the applicant could
 * see, could not pay, and which blocked the permit they were waiting for".
 *
 * PaymentController::pay once refused every status except `pending_payment`,
 * so the second payment — the only thing that can release the filing above —
 * was impossible to make. This walks that exact path: the balance is visible,
 * it is payable, and paying it issues the permits with no officer touching the
 * filing again.
 */
it('releases the permits when the second payment settles the balance', function () {
    $app = clearanceFilingAwaitingInspection();
    passEveryScheduledInspection($app);

    authAs('owner@biztrack.local');

    // Visible.
    expect(clearanceMeta($app)['balance_due'])->toBe(735.0);

    // Payable — the filing is `for_inspection`, not `pending_payment`.
    $paid = (float) $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertCreated()->json('data.amount');

    $settled = Application::findOrFail($app->id);

    expect($paid)->toBe(735.0)
        ->and(PermitFees::balance($settled)['balance_due'])->toBe(0.0)
        // Both permits: the business permit and the zoning clearance. Nobody
        // pressed Approve to make this happen — onPaymentCompleted retried the
        // issuance the gate had refused.
        ->and($settled->status)->toBe(ApplicationStatus::Approved)
        ->and($settled->permits()->count())->toBe(2);
});

/*
 * The other order the same two events can arrive in.
 *
 * The applicant settles the balance while an office is still reading. Nothing
 * is released at that moment — there is review left to do — and the LAST
 * review is what issues, through the ordinary path, with no money outstanding
 * to stop it. Asserted because `releaseIfSettled` firing on a filing that is
 * not otherwise clear would mint permits over an unread clearance application.
 */
it('issues through the ordinary path when the balance is settled before the offices finish', function () {
    $app = paidClearanceApplication('Paid Early Cafe');
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    // Settled, and nothing released: CPDO and BPLO have not read it yet.
    expect(PermitFees::balance(Application::findOrFail($app->id))['balance_due'])->toBe(0.0)
        ->and(Application::findOrFail($app->id)->permits()->count())->toBe(0);

    classifyAsOfficer($app);
    foreach (ApplicationAssignment::where('application_id', $app->id)->get() as $assignment) {
        authAs($assignment->department_id === Department::where('code', 'CPDO')->first()->id
            ? 'zoning@biztrack.local'
            : 'bplo@biztrack.local');
        $this->postJson("/api/v1/assignments/{$assignment->id}/approve")->assertOk();
    }

    passEveryScheduledInspection(Application::findOrFail($app->id));

    $settled = Application::findOrFail($app->id);

    expect($settled->status)->toBe(ApplicationStatus::Approved)
        ->and($settled->permits()->count())->toBe(2);
});

/*
 * The gate refuses a DIRECT caller outright rather than quietly declining.
 *
 * The automatic paths go through isFullyCleared(), which answers "not yet" and
 * parks the filing — an officer approving their own review has done nothing
 * wrong and must not be handed a 422 for somebody else's unpaid bill. That was
 * the bug the last build of this shipped: the refusal fired inside the
 * transaction that had just recorded the sign-off, and rolled it back.
 *
 * Asking approveAndIssue() for permits on a filing that owes money is a
 * different act, and it is refused.
 */
it('refuses approveAndIssue outright on a filing that still owes money', function () {
    $app = clearanceFilingAwaitingInspection();

    expect(fn () => app(WorkflowService::class)->approveAndIssue(Application::findOrFail($app->id)))
        ->toThrow(ValidationException::class);

    expect(Application::findOrFail($app->id)->permits()->count())->toBe(0);
});

it('reports a clearance as issued once its permit exists', function () {
    $app = clearanceFilingAwaitingInspection();

    /*
     * Before the visit the clearance is still only `applied` — the office has
     * agreed to it on paper, but a locational clearance is a statement about a
     * site and the site has not been looked at. `issued` is a claim that the
     * permit row exists, so it must not appear until the inspection releases it.
     */
    authAs('owner@biztrack.local');
    $beforeVisit = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json('data'))
        ->firstWhere('permit_type.code', 'ZONING');
    expect($beforeVisit['state'])->toBe('applied');

    passEveryScheduledInspection($app);

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    $row = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json('data'))
        ->firstWhere('permit_type.code', 'ZONING');

    expect($row['state'])->toBe('issued');
});

// --- authorization -----------------------------------------------------------

it('refuses a stranger the clearance list', function () {
    $app = paidClearanceApplication();

    // A different business owner, holding a perfectly valid session.
    authAs('juan@biztrack.local');
    $this->getJson("/api/v1/applications/{$app->id}/clearances")->assertForbidden();
});

it('refuses a stranger every clearance write', function () {
    $app = paidClearanceApplication();

    authAs('juan@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertForbidden();
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertForbidden();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/held", [
        'file' => UploadedFile::fake()->create('zoning.pdf', 20, 'application/pdf'),
    ])->assertForbidden();
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/held")->assertForbidden();

    // The 403 is the whole story: nothing was attached, charged or stored.
    expect($app->fresh()->permitTypes->pluck('code'))->not->toContain('ZONING')
        ->and(ApplicationDocument::where('application_id', $app->id)->count())->toBe(0)
        ->and(PermitFees::balance(Application::findOrFail($app->id))['balance_due'])->toBe(0.0);
});

it('refuses an officer the clearance chooser even on a filing its office reviews', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    // The sanitary officer is routed this filing and may read the application
    // itself — but which clearances a business asks for is the applicant's
    // decision, and the chooser is not part of the review.
    authAs('sanitary@biztrack.local');
    $this->getJson("/api/v1/applications/{$app->id}/clearances")->assertForbidden();
});

// --- guards ------------------------------------------------------------------

it('has no clearance endpoint for the permit the application is for', function () {
    $app = paidClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/BUSINESS/apply")->assertNotFound();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/NOT_A_CODE/apply")->assertNotFound();
});

it('survives a filing whose business has been removed from the register', function () {
    $app = paidClearanceApplication();
    $app->business->delete();

    $body = $this->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json();

    // The stage still renders; only the price it cannot compute is withheld.
    expect($body['data'])->toHaveCount(6)
        ->and($body['data'][0]['fee_preview'])->toBeNull()
        ->and($body['meta']['unlocked'])->toBeTrue();

    /*
     * And both writes that need a price say so. Two reasons now, either
     * sufficient: applying is agreeing to a charge nobody can quote, and
     * re-assessing a filing with no business record is a fatal inside
     * FeeCalculator rather than a missing figure.
     */
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")
        ->assertStatus(422);
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")
        ->assertStatus(422);
});

it('still lets a held copy be filed when the business record has gone', function () {
    $app = paidClearanceApplication();
    $app->business->delete();

    // Nothing here needs a price — the applicant can still hand in the
    // certificate they hold.
    $body = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated()->json();

    expect($body['data']['state'])->toBe('submitted');

    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held")->assertOk();
});

it('reports an office form as complete only once the applicant has saved it', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    $row = fn () => collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->json('data'))
        ->firstWhere('permit_type.code', 'SANITARY');

    expect($row()['office_form_complete'])->toBeFalse();

    $this->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", [
        'form_data' => ['owner_name' => 'Ana Dela Cruz'],
    ])->assertOk();

    expect($row()['office_form_complete'])->toBeTrue();
});

it('refuses a payment when the filing owes nothing', function () {
    $app = paidClearanceApplication('Nothing Owed Cafe');

    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'This application has nothing outstanding.');
});

/*
 * The service-level statement of the rule, walked through the whole lifecycle
 * in one test so the transition is visible rather than inferred: shut on a
 * draft with a sentence saying what to do, open from submission onward, and
 * paying changes nothing about the gate.
 */
it('opens the stage for the service at submission, and payment does not move it', function () {
    $service = app(ClearanceService::class);
    $app = draftClearanceApplication('Service Level Cafe');

    expect($service->isUnlocked($app))->toBeFalse()
        ->and($service->lockedReason($app))->toBeString();

    $this->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();

    // Open here — before a peso has moved. This is the assertion the tester's
    // "the permits are all gone" report comes down to.
    $submitted = $app->fresh();
    expect($service->isUnlocked($submitted))->toBeTrue()
        ->and($service->lockedReason($submitted))->toBeNull();

    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    $paid = $app->fresh();
    expect($service->isUnlocked($paid))->toBeTrue()
        ->and($service->lockedReason($paid))->toBeNull();
});
