<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationDocument;
use App\Models\ApplicationOfficeForm;
use App\Models\ApplicationPermitType;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\FeeAssessment;
use App\Models\Inspection;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Services\ClearanceService;
use App\Services\WorkflowService;
use App\Support\PermitFees;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * The other-permits stage (docs/application-flow-2026-09.md).
 *
 * FORM APPROVED, THEN PAID, THEN THE OTHER PERMITS. The wizard files the whole
 * application form; BPLO reads it and accepts it; the one Tax Order of Payment
 * — raised at submission over the business permit AND all five other permits —
 * is settled; and only then does this stage open. Each of the five is then
 * applied for or satisfied with a copy the business already holds, reviewed by
 * its own office, inspected, and RELEASED ON ITS OWN. Nothing waits for
 * anything else, and the business permit is issued last, by BPLO, on the
 * strength of the other five.
 *
 * ── What these tests used to say, and why every one of them was wrong ───────
 *
 * This file previously encoded an ordering where the six clearances were
 * chosen after payment and each one ACCRUED a fee onto a running balance, which
 * a second payment then settled, and no permit at all was released until that
 * balance reached zero. Four mechanisms carried it: the unlock, the accrual,
 * the second payment, and a whole-filing release gate.
 *
 * All four are gone, and they were removed together because they were one
 * mechanism seen from four sides:
 *
 *   1. the unlock moved from submission to PAYMENT (`ClearanceService::
 *      isUnlocked` is `status?->isPaid()`);
 *   2. the accrual is gone — `ClearanceService::reassess()` was deleted, and
 *      rule 4 raises ONE bill at submission covering everything;
 *   3. the second payment therefore has nothing to settle;
 *   4. the release gate is per-permit — a passed inspection issues that permit
 *      then and there (rule 7), and `approveAndIssue`/`isFullyCleared` no
 *      longer exist.
 *
 * There are FIVE other permits, not six. Market Clearance and the City Market
 * Office were removed from the system entirely [client, 2026-09-06], so every
 * test naming MARKET has gone with them rather than being pointed at a code
 * that no longer seeds.
 *
 * Tests whose NAME stated the old rule are renamed, not just re-asserted. A
 * test called "adds the clearance's fee lines to the balance" passing against
 * code that raises no balance is worse than no test at all.
 */

/**
 * A draft owned by `owner@biztrack.local`, asking for the business permit —
 * which is where this stage is SHUT.
 *
 * `permit_type_ids` carries BUSINESS alone deliberately. The applicant does not
 * pick the other five: `WorkflowService::attachRequiredPermitTypes` attaches
 * them at submission, because all five are required and the single bill has to
 * be raised over a permit set that is already final.
 *
 * The fee profile is deliberately full. An empty one prices several permits at
 * zero (no employees means no health certificates, no floor area means no
 * sanitary inspection fee), and a test asserting anything about money against a
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
        'data_privacy_consent' => true,
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

/**
 * The same filing, submitted: `for_approval`, assessed in full, nothing paid.
 *
 * RENAMED IN MEANING, not in name. This used to be the state in which the stage
 * was open; it is now the state that most clearly proves it is shut, because
 * the whole bill exists here and none of it has been paid.
 */
function submittedClearanceApplication(string $name = 'Submitted Stage Store'): Application
{
    $app = draftClearanceApplication($name);

    test()->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();

    return $app->fresh();
}

/**
 * The same filing, approved by BPLO and paid — which is where this stage OPENS.
 *
 * Three acts, not two, and the middle one is the 6 September change: BPLO reads
 * the main form BEFORE the applicant is asked for money.
 * `ApplicationStatus::isBillable()` enforces it, so a fixture that submitted and
 * paid on two consecutive lines now 422s on the payment.
 *
 * `bploApprovesForm` is the shared helper in tests/Pest.php rather than a local
 * copy: the sequence is one fact about the process, and the last time it was
 * spread by hand it had to be corrected in every file at once.
 */
function paidClearanceApplication(string $name = 'Paid Stage Cafe'): Application
{
    $app = submittedClearanceApplication($name);

    bploApprovesForm($app);

    test()->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    authAs('owner@biztrack.local');

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

/** The ledger as the other-permits screen reads it. */
function clearanceMeta(Application $app): array
{
    return ledger(
        test()->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json('meta')
    );
}

/** Which office issues each of the five, and who signs for it. */
function clearanceOffice(string $code): array
{
    return [
        'ZONING' => ['CPDO', 'zoning@biztrack.local'],
        'SANITARY' => ['CHO', 'sanitary@biztrack.local'],
        'FSIC' => ['BFP', 'fire@biztrack.local'],
        'CEC' => ['CENRO', 'cenro@biztrack.local'],
        'OCCUPANCY' => ['OBO', 'obo@biztrack.local'],
    ][$code];
}

/** One clearance card, read as the applicant. */
function clearanceRow(Application $app, string $code): array
{
    authAs('owner@biztrack.local');

    return collect(test()->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json('data'))
        ->firstWhere('permit_type.code', $code);
}

/**
 * Drive one other permit the whole way: applied for, read by its office, a date
 * picked, the visit passed — which issues it.
 *
 * Four acts, and the shape of the fixture is itself the rule under test
 * elsewhere in this file. The office boundary is load-bearing at every step:
 * `ApplicationVisibility` keeps a reviewer to filings routed to their own
 * office, `inspections/{id}/conduct` sits behind `permission:inspection.manage`,
 * and `permits/{code}/inspection` refuses an office that does not issue that
 * permit type. A 403 out of here means an office was routed work it cannot do.
 *
 * The inspection is TWO steps now — the office picks the date and afterwards
 * records the result (rule 6). It is no longer auto-scheduled by an approval,
 * so a fixture that only approved and conducted would find no visit to conduct.
 */
function driveClearanceToApproved(Application $app, string $code): void
{
    [$deptCode, $officer] = clearanceOffice($code);
    $departmentId = Department::where('code', $deptCode)->firstOrFail()->id;

    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/clearances/{$code}/apply")->assertOk();

    authAs($officer);
    $assignment = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', $departmentId)->firstOrFail();
    test()->postJson("/api/v1/assignments/{$assignment->id}/approve")->assertOk();

    test()->postJson("/api/v1/applications/{$app->id}/permits/{$code}/inspection", [
        'scheduled_at' => now()->addDays(2)->toDateTimeString(),
    ])->assertCreated();

    $visit = Inspection::where('application_id', $app->id)
        ->where('department_id', $departmentId)
        ->latest('id')->firstOrFail();

    test()->postJson("/api/v1/inspections/{$visit->id}/conduct", [
        'result' => 'passed',
        'findings' => 'Premises inspected and found compliant.',
    ])->assertOk();

    authAs('owner@biztrack.local');
}

beforeEach(function () {
    // Keep uploads out of the developer's real storage directory.
    Storage::fake('local');
});

// --- the shape ---------------------------------------------------------------

/*
 * RENAMED from "lists the six clearances and never the permit the application
 * is for". There are five.
 *
 * The old name and its `toHaveCount(6)` were left behind when Market Clearance
 * was removed, sitting immediately above a `toBe([...])` listing five codes —
 * a test that could not pass whatever the code did.
 */
it('lists the five other permits and never the permit the application is for', function () {
    $app = paidClearanceApplication();

    $rows = $this->getJson("/api/v1/applications/{$app->id}/clearances")
        ->assertOk()->json('data');

    $codes = collect($rows)->pluck('permit_type.code');

    expect($codes)->toHaveCount(5)
        ->and($codes->all())->toBe(PermitType::CLEARANCE_ORDER)
        // The mayor's permit is the outcome of the application, not one of the
        // permits supporting it, so it is not on this screen at all.
        ->and($codes)->not->toContain(PermitType::OUTCOME_CODE);
});

it('carries the full contract shape on every row', function () {
    $app = paidClearanceApplication();

    $row = clearanceRow($app, 'SANITARY');

    expect($row)->toHaveKeys([
        'permit_type', 'state', 'has_office_form', 'office_form_complete',
        'held_document', 'assignment', 'fee_preview',
    ]);
    expect($row['permit_type'])->toHaveKeys(['id', 'code', 'name', 'department']);
    expect($row['permit_type']['department'])->toHaveKeys(['code', 'name']);
    expect($row['permit_type']['department']['code'])->toBe('CHO');

    /*
     * `not_started`, not `available` — and the difference is the whole of the
     * second state machine.
     *
     * `available` was an inference from the permit type NOT being attached,
     * which was the old ordering's way of saying "not chosen". All five are
     * attached from submission now, because all five are required and the one
     * bill was raised over them; what says whether the applicant has acted is
     * `application_permit_types.status`, and it reads `not_started` until they
     * do. `ClearanceService::state()` returns that column straight through.
     */
    expect($row['state'])->toBe(ClearanceStatus::NotStarted->value);

    expect($row['has_office_form'])->toBeTrue()
        ->and($row['office_form_complete'])->toBeFalse()
        ->and($row['held_document'])->toBeNull()
        ->and($row['assignment'])->toBeNull()
        ->and($row['fee_preview'])->toBeString();

    /*
     * All five open a form.
     *
     * This assertion used to be a PAIR — zoning true, market false — recording
     * that the difference between the two was which paper the city had given
     * us. Zoning was settled when CPDD sent MCG-CPDD-FO-003 v1.2 and its sheet
     * was rebuilt against the real form. Market was settled the other way: the
     * city has no paper version and the LGU has since confirmed neither the
     * clearance nor its office is needed, so it left the system entirely.
     *
     * Asserted over the whole set rather than over named codes: a sixth permit
     * added without a sheet has to come here and say so.
     */
    $rows = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->json('data'));
    expect($rows->pluck('has_office_form')->all())->each->toBeTrue();
});

/*
 * RENAMED from "carries the ledger in meta, because applying raises a balance".
 *
 * The keys are unchanged and the reason for them is not. Applying raises
 * nothing: rule 4 puts the business permit and all five other permits on one
 * Tax Order of Payment at submission, and the applicant settles it before this
 * stage opens at all. The ledger stays in the contract because the screen still
 * has to show what the filing cost and that it is clear — but a test claiming
 * these three keys exist "because applying raises a balance" states a mechanism
 * that was deleted with `ClearanceService::reassess()`.
 */
it('carries the ledger in meta, already settled in full before the stage opens', function () {
    $app = paidClearanceApplication();

    $meta = clearanceMeta($app);

    expect(array_keys($meta))->toBe([
        'unlocked', 'locked_reason', 'total_assessed', 'total_paid', 'balance_due',
    ]);

    // Paid in full — for everything, not just the business permit.
    expect($meta['balance_due'])->toBe(0.0)
        ->and($meta['total_paid'])->toBe($meta['total_assessed'])
        ->and($meta['total_assessed'])->toBeGreaterThan(0);
});

// --- the unlock rule ---------------------------------------------------------

it('keeps the stage shut on a draft, and says how to open it', function () {
    $app = draftClearanceApplication();

    $meta = clearanceMeta($app);

    expect($meta['unlocked'])->toBeFalse()
        ->and($meta['locked_reason'])->toBeString()
        // Names the next step the applicant can actually take. Submitting is
        // not what opens the stage any more, but it is what they do next.
        ->and($meta['locked_reason'])->toContain('submit');
});

/*
 * REPLACES "opens the stage on submission, with the bill still outstanding".
 *
 * That rule was a workaround with a recorded reason: the gate was moved off
 * payment on 2 September because payment was a dummy that never cleared, so
 * `hasClearedPayment` was false forever and testers twice reported the
 * clearances as missing outright. Payment is the applicant's own action and
 * completes synchronously now, so the gate went back where the counter
 * procedure puts it — `isUnlocked` is `status?->isPaid()`.
 *
 * This is the state that proves it: the entire bill exists and not a peso of it
 * has been paid. If the balance here were zero the test would prove nothing.
 */
it('keeps the stage shut on a submitted filing that has not been paid for', function () {
    $app = submittedClearanceApplication();

    $meta = clearanceMeta($app);

    expect($app->status)->toBe(ApplicationStatus::ForApproval)
        ->and($meta['unlocked'])->toBeFalse()
        ->and($meta['locked_reason'])->toBeString()
        ->and($meta['balance_due'])->toBeGreaterThan(0);
});

/*
 * RENAMED from "leaves the stage open once the first payment clears", which
 * assumed it was already open. Payment is the event, not a non-event.
 */
it('opens the stage the moment the payment clears', function () {
    $app = submittedClearanceApplication();

    expect(clearanceMeta($app)['unlocked'])->toBeFalse();

    bploApprovesForm($app);
    // Still shut between BPLO's approval and the money: `pending_payment` is
    // not a paid status, and this is the window the whole gate is about.
    authAs('owner@biztrack.local');
    expect(clearanceMeta($app)['unlocked'])->toBeFalse();

    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    $meta = clearanceMeta($app);

    expect(Application::findOrFail($app->id)->status)->toBe(ApplicationStatus::AwaitingOtherPermits)
        ->and($meta['unlocked'])->toBeTrue()
        ->and($meta['locked_reason'])->toBeNull()
        ->and($meta['balance_due'])->toBe(0.0);
});

/*
 * REPLACES "keeps the stage open on a filing an office returned for revision",
 * which asked the wrong machine.
 *
 * "Returned" is now two different events on two different state machines, and
 * the old test conflated them. BPLO returning the MAIN form happens before the
 * money — `ApplicationStatus::Returned` is not a paid status, so that filing's
 * stage is shut, and it cannot be otherwise: the point of returning a form is
 * that BPLO has not accepted it. An OP office returning ITS permit happens
 * after the money, moves only `application_permit_types.status`, and the
 * application stays `awaiting_other_permits` with the stage open — which is
 * what lets the applicant fix what the office asked for.
 *
 * Both halves are asserted here because it is the distinction that is easy to
 * lose, not either fact on its own.
 */
it('keeps the stage open when an office returns its own permit, and shut when BPLO returns the form', function () {
    // The application's machine: BPLO returns the form, before payment.
    $unpaid = submittedClearanceApplication('Returned Form Store');
    $bploAssignment = ApplicationAssignment::where('application_id', $unpaid->id)->firstOrFail();

    authAs('bplo@biztrack.local');
    $this->postJson("/api/v1/assignments/{$bploAssignment->id}/return", ['remarks' => 'Send the lease contract.'])
        ->assertOk();

    authAs('owner@biztrack.local');
    expect(Application::findOrFail($unpaid->id)->status)->toBe(ApplicationStatus::Returned)
        ->and(clearanceMeta($unpaid)['unlocked'])->toBeFalse();

    // The permit's machine: CPDO returns the zoning permit, after payment.
    $paid = paidClearanceApplication('Returned Permit Cafe');
    $this->postJson("/api/v1/applications/{$paid->id}/clearances/ZONING/apply")->assertOk();

    $cpdo = ApplicationAssignment::where('application_id', $paid->id)
        ->where('department_id', Department::where('code', 'CPDO')->firstOrFail()->id)
        ->firstOrFail();

    authAs('zoning@biztrack.local');
    $this->postJson("/api/v1/assignments/{$cpdo->id}/return", ['remarks' => 'The site plan is unreadable.'])
        ->assertOk();

    authAs('owner@biztrack.local');
    $settled = Application::findOrFail($paid->id);

    expect($settled->status)->toBe(ApplicationStatus::AwaitingOtherPermits)
        ->and(clearanceMeta($paid)['unlocked'])->toBeTrue()
        ->and(clearanceRow($paid, 'ZONING')['state'])->toBe(ClearanceStatus::Returned->value);
});

it('refuses every write while the application is still a draft', function () {
    $app = draftClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertStatus(422);
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertStatus(422);
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/held", [
        'file' => UploadedFile::fake()->create('zoning.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);

    /*
     * Nothing leaked through. ZONING is genuinely absent here and this is the
     * last state in which that assertion means anything: `attachRequiredPermitTypes`
     * runs at submission, so from `for_approval` onward the permit type is on
     * every filing whether the applicant has touched it or not.
     */
    expect($app->fresh()->permitTypes->pluck('code'))->not->toContain('ZONING')
        ->and(ApplicationDocument::where('application_id', $app->id)->count())->toBe(0);
});

/*
 * RENAMED from "accepts an application for a clearance on a submitted filing
 * that is unpaid", which is the exact rule that was reversed on 6 September.
 *
 * `WorkflowService::startClearance` refuses outright — "The other permits open
 * once this application is paid" — and it refuses at the service, not only at
 * the controller, so a second caller cannot route an office work on a filing
 * nobody has paid for.
 */
it('refuses to start a permit on a submitted filing that has not been paid for', function () {
    $app = submittedClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertStatus(422);
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/held", [
        'file' => UploadedFile::fake()->create('zoning.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);

    // Attached and billed since submission, but not STARTED — and no office has
    // been given work.
    $row = ApplicationPermitType::where('application_id', $app->id)
        ->where('permit_type_id', PermitType::where('code', 'ZONING')->value('id'))
        ->firstOrFail();

    expect($row->status)->toBe(ClearanceStatus::NotStarted)
        ->and(ApplicationAssignment::where('application_id', $app->id)
            ->where('department_id', Department::where('code', 'CPDO')->value('id'))->exists())
        ->toBeFalse();
});

it('keeps the stage shut on a filing that was rejected', function () {
    $app = paidClearanceApplication();

    authAs('bplo@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Wrong zone.'])->assertOk();

    authAs('owner@biztrack.local');
    $meta = clearanceMeta($app);

    // Paid, so `isPaid()` was true a moment ago — and still shut. There is
    // nothing to apply for under a filing the LGU has closed.
    expect($meta['unlocked'])->toBeFalse()
        ->and($meta['locked_reason'])->toContain('was not approved');

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertStatus(422);
});

// --- one bill, raised at submission ------------------------------------------

/*
 * RENAMED from "adds the clearance's fee lines to the balance the moment it is
 * applied for". That was the accrual, and the accrual is gone.
 *
 * Rule 4: one Tax Order of Payment, raised at submission, covering the business
 * permit and every required permit — and it charges for a permit whether the
 * owner applies or uploads, because the fee covers the inspection and an
 * uploaded permit is inspected too. So the zoning lines are on the bill the
 * applicant already paid, and pressing Apply moves no money at all.
 */
it('adds nothing to the bill when a permit is started, because it was billed at submission', function () {
    $app = paidClearanceApplication();

    $before = clearanceMeta($app);

    // Sec. 3.D.01 was charged at submission, not now.
    expect($before['balance_due'])->toBe(0.0)
        ->and(topOrderLabels($app))->toContain('locational clearance');

    $body = $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")
        ->assertOk()->json();

    $after = ledger($body['meta']);

    expect($body['data']['state'])->toBe(ClearanceStatus::ForApproval->value)
        ->and($after['total_assessed'])->toBe($before['total_assessed'])
        ->and($after['total_paid'])->toBe($before['total_paid'])
        ->and($after['balance_due'])->toBe(0.0);
});

/*
 * RENAMED from "puts exactly that office's fee lines on the assessment and no
 * other office's", which described the re-assessment that no longer happens.
 *
 * The assertion that survives intact is the one the old test said the accrual
 * lived or died by — that nothing comes OFF. It is worth more now, not less: the
 * applicant has already paid this assessment in full, so a rule that quietly
 * rewrote a line would be editing a receipt.
 */
it('leaves the assessment untouched when a permit is started', function () {
    $app = paidClearanceApplication('Zoned Permit Cafe');

    $before = FeeAssessment::where('application_id', $app->id)->firstOrFail()->line_items;
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();
    $after = FeeAssessment::where('application_id', $app->id)->firstOrFail()->line_items;

    expect($after)->toBe($before);
});

/*
 * RENAMED from "bills only the chosen offices, not every office" — the inverse
 * of what rule 4 now says.
 *
 * There are no chosen offices. All five permits are required, so all five are
 * attached at submission and all five are on the one bill, and this is what
 * makes a second payment unnecessary rather than merely absent.
 */
it('bills every required permit at submission, whether or not the applicant has started it', function () {
    $app = paidClearanceApplication('One Bill Cafe');

    $labels = topOrderLabels($app);

    expect($labels)
        ->toContain('locational clearance')                      // CPDO
        ->toContain('sanitary inspection fee')                   // CHO
        ->toContain('fire safety inspection certificate fee')    // BFP
        ->toContain('certificate of use/occupancy');             // OBO

    // And not one of the five has been started.
    $states = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->json('data'))
        ->pluck('state')->unique()->all();

    expect($states)->toBe([ClearanceStatus::NotStarted->value]);
});

/*
 * RENAMED from "bills the business permit first, then each clearance onto the
 * same balance" — the previous ordering's headline claim, and the one the
 * client reversed.
 *
 * There is one moment, not two. Starting all five permits moves the ledger
 * nowhere, and the endpoint that would have taken a second payment refuses,
 * because there is nothing outstanding to take.
 */
it('raises one bill covering everything, and takes no second payment for the other permits', function () {
    $app = paidClearanceApplication('Four Office Cafe');

    $assessed = clearanceMeta($app)['total_assessed'];

    foreach (PermitType::CLEARANCE_ORDER as $code) {
        $meta = ledger($this->postJson("/api/v1/applications/{$app->id}/clearances/{$code}/apply")
            ->assertOk()->json('meta'));

        expect($meta['total_assessed'])->toBe($assessed)
            ->and($meta['balance_due'])->toBe(0.0);
    }

    // The whole filing, priced once and settled once.
    expect((float) FeeAssessment::where('application_id', $app->id)->firstOrFail()->total_amount)
        ->toBe($assessed)
        ->and(PermitFees::balance(Application::findOrFail($app->id))['balance_due'])->toBe(0.0);

    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'This application has nothing outstanding.');
});

/*
 * RENAMED from "routes every chosen clearance to its own office when the
 * payment clears". Routing at payment is what cannot happen now: the applicant
 * has not said how they will satisfy each permit at the moment they pay, and
 * routing all five would put five filings in five queues nobody can act on and
 * start five service-time clocks against work that has not been handed over.
 */
it('routes a permit to its own office the moment it is started', function () {
    $app = paidClearanceApplication();
    $cho = Department::where('code', 'CHO')->firstOrFail();

    // Payment routed BPLO and nobody else.
    expect(ApplicationAssignment::where('application_id', $app->id)->count())->toBe(1)
        ->and(ApplicationAssignment::where('application_id', $app->id)->where('department_id', $cho->id)->exists())
        ->toBeFalse();

    $row = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")
        ->assertOk()->json('data');

    $assignment = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', $cho->id)->first();

    /*
     * `assigned_at` starts the service-time clock ProcessingTimeAnalytics,
     * StaffingSimulation and DashboardAnalytics measure an office by, so it must
     * be stamped when the office genuinely has work — which is here, and only
     * here.
     */
    expect($row['state'])->toBe(ClearanceStatus::ForApproval->value)
        ->and($assignment)->not->toBeNull()
        ->and($assignment->status->value)->toBe('pending')
        ->and($assignment->assigned_at)->not->toBeNull()
        ->and($row['assignment']['id'])->toBe($assignment->id);
});

/*
 * RENAMED from "previews what applying will add before it is applied for".
 *
 * `fee_preview` still quotes what starting this permit would ADD to the
 * balance, and under one bill that is honestly nothing — the answer changed,
 * not the question. Once the permit is started the same field flips to what it
 * DID cost, which is the ₱735 already paid; both readings are computed by
 * `ClearanceService::feePreview` as a difference of two whole assessments.
 */
it('quotes nothing to start a permit, and what it cost once it is started', function () {
    $app = paidClearanceApplication();

    expect(clearanceRow($app, 'ZONING')['fee_preview'])->toBe('₱0.00');

    $meta = ledger($this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")
        ->assertOk()->json('meta'));

    expect($meta['balance_due'])->toBe(0.0)
        ->and(clearanceRow($app, 'ZONING')['fee_preview'])->toBe('₱735.00');
});

// --- withdrawing --------------------------------------------------------------

/*
 * REPLACES the three "un-applying" tests: the fee lines coming back off, the
 * un-refunded fee, and the office-has-acted guard.
 *
 * All five permits are required (`PermitType::REQUIRED_CLEARANCE_CODES`), and
 * `ClearanceService::unapply` refuses a required permit outright. Market
 * Clearance was the one that could be withdrawn and it no longer exists, so
 * withdrawal is refused for every code this endpoint accepts. That is the rule
 * the client's flow implies — the application cannot be approved without all
 * five, so detaching one would leave a filing that has been paid for and can
 * never complete — and it is asserted over the whole set rather than one code,
 * because "which of these can come off" is exactly the thing that changed.
 */
it('refuses to withdraw any of the five, because every one of them is required', function () {
    $app = paidClearanceApplication();

    foreach (PermitType::CLEARANCE_ORDER as $code) {
        $this->postJson("/api/v1/applications/{$app->id}/clearances/{$code}/apply")->assertOk();
        $this->deleteJson("/api/v1/applications/{$app->id}/clearances/{$code}/apply")->assertStatus(422);

        // Still attached, still started, still owed to the applicant.
        expect(clearanceRow($app, $code)['state'])->toBe(ClearanceStatus::ForApproval->value);
    }

    expect($app->fresh()->permitTypes->pluck('code')->sort()->values()->all())
        ->toBe(collect(PermitType::CLEARANCE_ORDER)->push(PermitType::OUTCOME_CODE)->sort()->values()->all());
});

// --- the copy the applicant already holds -------------------------------------

/*
 * RENAMED from "adds no fee and no permit type when a held copy is uploaded",
 * which stated the asymmetry the 6 September flow deleted.
 *
 * Uploading used to escape the permit type, the office, the form and the fee —
 * the absence of a pivot row WAS the "held" state. It cannot be: all five are
 * required, so every one of them is in the pivot from submission regardless.
 * What tells an application from a copy is `application_permit_types.mode`, and
 * rule 3 says both are reviewed and both are inspected, because the LGU
 * inspects the premises rather than the paperwork.
 */
it('reviews a copy the applicant holds exactly as it reviews an application', function () {
    $app = paidClearanceApplication();
    $cho = Department::where('code', 'CHO')->firstOrFail();

    $assessedBefore = clearanceMeta($app)['total_assessed'];

    $body = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated()->json();

    expect($body['data']['state'])->toBe(ClearanceStatus::ForApproval->value)
        ->and($body['data']['held_document']['name'])->toBe('sanitary.pdf')
        ->and($body['data']['held_document']['size'])->toBeGreaterThan(0)
        // Routed to CHO like any other. The office reads an image instead of a
        // form; that is the only difference the mode records.
        ->and($body['data']['assignment'])->not->toBeNull()
        ->and(ApplicationAssignment::where('application_id', $app->id)
            ->where('department_id', $cho->id)->exists())->toBeTrue();

    $row = ApplicationPermitType::where('application_id', $app->id)
        ->where('permit_type_id', PermitType::where('code', 'SANITARY')->value('id'))
        ->firstOrFail();

    expect($row->mode)->toBe(ApplicationPermitType::MODE_UPLOAD);

    // And it was charged for at submission either way — rule 4, because the fee
    // covers the inspection an uploaded permit still gets.
    expect(ledger($body['meta'])['total_assessed'])->toBe($assessedBefore)
        ->and(ledger($body['meta'])['balance_due'])->toBe(0.0)
        ->and(topOrderLabels($app))->toContain('sanitary inspection fee');
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

/*
 * RENAMED from "removes the held copy and its file", whose assertion that the
 * card falls back to `available` described the inferred state that is gone.
 *
 * The file goes and the permit does NOT: `destroyHeld` forgets the document,
 * and the pivot stays `for_approval` with `mode = upload`, still in CHO's
 * queue. That is what the code does and the test says so rather than asserting
 * a tidier answer — see the note in the file's report about what an office is
 * then looking at.
 */
it('removes the held copy and its file, and leaves the permit standing with its office', function () {
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

    expect($body['data']['held_document'])->toBeNull()
        ->and($body['data']['state'])->toBe(ClearanceStatus::ForApproval->value)
        ->and(ApplicationDocument::where('application_id', $app->id)->where('permit_type_id', $sanitary->id)->count())->toBe(0)
        // A "removed" document still sitting on disk is not removed: it stays
        // downloadable through /documents/{id}/download for as long as it is there.
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

/*
 * RENAMED from "keeps applying and submitting mutually exclusive", which was
 * INVERTED on 6 September and says so in `ClearanceController::storeHeld`.
 *
 * The old exclusion existed because a held copy meant no pivot row, and holding
 * both records would have put two contradictory claims about one permit in the
 * register. There is one record now — the pivot, with a mode — so replacing an
 * application with a copy is an edit rather than a contradiction, and it is
 * allowed for exactly as long as the office has not acted.
 *
 * The narrower rule that replaces it: once the permit is past `for_approval`
 * the office has accepted the paperwork and booked a visit against it, and
 * swapping the evidence underneath that is not a correction but a different
 * application.
 */
it('lets a copy replace an application, and does not let an application replace a copy', function () {
    $app = paidClearanceApplication();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    // No withdrawal step: the copy is filed straight over the application, which
    // matters because withdrawal is refused on all five (see above).
    $body = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated()->json();

    $row = fn () => ApplicationPermitType::where('application_id', $app->id)
        ->where('permit_type_id', $sanitary->id)->firstOrFail();

    expect($body['data']['state'])->toBe(ClearanceStatus::ForApproval->value)
        ->and($row()->mode)->toBe(ApplicationPermitType::MODE_UPLOAD);

    /*
     * The other direction is CLOSED, and asserted rather than assumed because
     * the old file claimed the switch worked both ways.
     *
     * `ClearanceController::apply` aborts on `isAppliedFor`, which reads any
     * pivot status other than `not_started` as started — mode is not consulted.
     * So a permit sitting at `for_approval` in upload mode is "already applied
     * for", and removing the copy does not reopen the door: `destroyHeld` only
     * forgets the document and leaves the status where it was.
     *
     * That predicate is deliberate for the states it was written about — a
     * REJECTED or RETURNED permit must go back through `refileClearance()` so
     * the office's remarks are not lost to a second start. Upload mode simply
     * falls under the same rule, so there is one way in and no way back.
     */
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertStatus(422);

    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertStatus(422);

    expect($row()->mode)->toBe(ApplicationPermitType::MODE_UPLOAD)
        ->and(ApplicationDocument::where('application_id', $app->id)
            ->where('permit_type_id', $sanitary->id)->count())->toBe(0);
});

/*
 * RENAMED from "will not file a copy of a clearance whose office has already
 * started work", which drove the refusal by writing an assignment status by
 * hand. The gate moved onto the permit's own status, so the fixture has to
 * move with it: the office really approves, which sends the permit to
 * `for_inspection`, and that is what closes the door.
 */
it('will not file a copy of a permit its office has already accepted', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    $assignment = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', Department::where('code', 'CHO')->firstOrFail()->id)
        ->firstOrFail();

    authAs('sanitary@biztrack.local');
    $this->postJson("/api/v1/assignments/{$assignment->id}/approve")->assertOk();

    authAs('owner@biztrack.local');
    expect(clearanceRow($app, 'SANITARY')['state'])->toBe(ClearanceStatus::ForInspection->value);

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);

    expect(ApplicationDocument::where('application_id', $app->id)->count())->toBe(0);
});

/*
 * REPLACES "drops the office form obligation when a clearance is withdrawn,
 * without discarding the answers" and "lets the applicant fill in the sheet for
 * a clearance applied for after payment".
 *
 * The first is gone with withdrawal itself. The second survives as the rule
 * that matters: every office sheet first becomes reachable on a filing that is
 * already paid for and under way, so `OfficeFormController::ownerMayEdit` has
 * to allow it. Without that window the applicant would have been billed at
 * submission for five permits whose forms they could never fill in.
 */
it('lets the applicant fill in the sheet for a permit started after payment', function () {
    $app = paidClearanceApplication();
    $zoning = PermitType::where('code', 'ZONING')->firstOrFail();

    expect($app->status)->toBe(ApplicationStatus::AwaitingOtherPermits);

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();
    $this->putJson("/api/v1/applications/{$app->id}/office-forms/ZONING", [
        'form_data' => ['owner_name' => 'Ana Dela Cruz'],
    ])->assertOk();

    expect(clearanceRow($app, 'ZONING')['office_form_complete'])->toBeTrue()
        ->and(ApplicationOfficeForm::where('application_id', $app->id)
            ->where('permit_type_id', $zoning->id)->count())->toBe(1);
});

// --- release: each permit on its own ------------------------------------------

/*
 * REPLACES the whole "release gate" section, which asserted that no permit at
 * all came out until the filing's balance reached zero.
 *
 * There is no balance and there is no whole-filing gate. Rule 7, in the
 * client's words: "the other 6 permits are automatically released once they are
 * approved by their respective admins; no need to wait for each other to be
 * approved." `WorkflowService::recordInspection` grants and mints on the spot,
 * with no check on the rest of the filing, and `approveAndIssue` /
 * `isFullyCleared` were deleted with the gate they enforced.
 */
it('releases each permit the moment its own office passes the inspection', function () {
    $app = paidClearanceApplication('Own Release Cafe');

    driveClearanceToApproved($app, 'ZONING');

    $issued = Application::findOrFail($app->id);

    expect(clearanceRow($app, 'ZONING')['state'])->toBe(ClearanceStatus::Approved->value)
        ->and($issued->permits()->count())->toBe(1)
        ->and($issued->permits()->first()->permit_type_id)
        ->toBe(PermitType::where('code', 'ZONING')->value('id'))
        // Nothing else moved. The other four are untouched and the application
        // is still waiting on them, which is the whole point of rule 7.
        ->and($issued->status)->toBe(ApplicationStatus::AwaitingOtherPermits)
        ->and(clearanceRow($app, 'SANITARY')['state'])->toBe(ClearanceStatus::NotStarted->value);
});

/*
 * REPLACES "releases the permits when the second payment settles the balance".
 *
 * The second payment was the thing that released everything at once, and the
 * spec records why it existed: without it the applicant faced "a balance they
 * could see, could not pay, and which blocked the permit they were waiting
 * for". Nothing blocks now, so what is left to assert is the other end — the
 * business permit is the ONLY one that waits, and it waits for BPLO rather than
 * for money.
 */
it('issues the business permit last, at BPLO’s final approval, once all five are approved', function () {
    $app = paidClearanceApplication('Final Approval Cafe');

    foreach (PermitType::CLEARANCE_ORDER as $code) {
        driveClearanceToApproved($app, $code);
    }

    $ready = Application::findOrFail($app->id);

    // Five permits out, and the filing has walked itself into BPLO's queue.
    expect($ready->permits()->count())->toBe(5)
        ->and($ready->status)->toBe(ApplicationStatus::ForFinalApproval);

    $bplo = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', Department::where('code', 'BPLO')->firstOrFail()->id)
        ->firstOrFail();

    authAs('bplo@biztrack.local');
    $this->postJson("/api/v1/assignments/{$bplo->id}/approve")->assertOk();

    $approved = Application::findOrFail($app->id);

    expect($approved->status)->toBe(ApplicationStatus::Approved)
        ->and($approved->permits()->count())->toBe(6)
        ->and($approved->permits()->pluck('permit_type_id'))
        ->toContain(PermitType::where('code', PermitType::OUTCOME_CODE)->value('id'));
});

/*
 * RENAMED from "refuses approveAndIssue outright on a filing that still owes
 * money". `approveAndIssue` does not exist and money is not what is owed.
 *
 * `approveOverall` is the only place an application becomes Approved and the
 * only place the Mayor's Permit is minted, and it restates its gate rather than
 * trusting the status it normally arrives with — minting a legal instrument
 * must not be able to skip the check by coming through a different door, and a
 * direct caller is exactly that door.
 */
it('refuses BPLO’s final approval outright while a required permit is outstanding', function () {
    $app = paidClearanceApplication('Outstanding Permit Cafe');

    driveClearanceToApproved($app, 'ZONING');

    expect(fn () => app(WorkflowService::class)->approveOverall(Application::findOrFail($app->id)))
        ->toThrow(ValidationException::class);

    $held = Application::findOrFail($app->id);

    // The zoning permit its own office issued stays issued; the business permit
    // was not minted and the filing was not decided.
    expect($held->permits()->count())->toBe(1)
        ->and($held->status)->toBe(ApplicationStatus::AwaitingOtherPermits);
});

/*
 * RENAMED from "reports a clearance as issued once its permit exists".
 *
 * `issued` was an inferred badge — a Permit row exists, therefore issued — and
 * the card now renders `application_permit_types.status` straight through, so
 * the word is `approved`. The rule behind it is unchanged and is the reason the
 * badge is worth a test: approval of the paperwork is not the grant. A
 * locational clearance is a statement about a site, so the card must not claim
 * anything final until the site has been looked at.
 */
it('reports a permit as approved only once its inspection has passed', function () {
    $app = paidClearanceApplication('Badge Cafe');
    $cpdo = Department::where('code', 'CPDO')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();
    expect(clearanceRow($app, 'ZONING')['state'])->toBe(ClearanceStatus::ForApproval->value);

    $assignment = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', $cpdo->id)->firstOrFail();

    authAs('zoning@biztrack.local');
    $this->postJson("/api/v1/assignments/{$assignment->id}/approve")->assertOk();

    // The office has read it and booked nothing yet: still not granted.
    expect(clearanceRow($app, 'ZONING')['state'])->toBe(ClearanceStatus::ForInspection->value)
        ->and(Application::findOrFail($app->id)->permits()->count())->toBe(0);

    authAs('zoning@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/permits/ZONING/inspection", [
        'scheduled_at' => now()->addDays(2)->toDateTimeString(),
    ])->assertCreated();

    $visit = Inspection::where('application_id', $app->id)->where('department_id', $cpdo->id)->firstOrFail();
    $this->postJson("/api/v1/inspections/{$visit->id}/conduct", [
        'result' => 'passed',
        'findings' => 'Premises inspected and found compliant.',
    ])->assertOk();

    expect(clearanceRow($app, 'ZONING')['state'])->toBe(ClearanceStatus::Approved->value)
        ->and(Application::findOrFail($app->id)->permits()->count())->toBe(1);
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

    /*
     * The 403 is the whole story: nothing was started, stored or routed.
     *
     * ZONING being ATTACHED is no longer evidence of anything — it has been on
     * the filing since submission — so the assertion moved to the two facts a
     * stranger's write would actually have changed: the permit's own status and
     * the office queue.
     */
    $row = ApplicationPermitType::where('application_id', $app->id)
        ->where('permit_type_id', PermitType::where('code', 'ZONING')->value('id'))
        ->firstOrFail();

    expect($row->status)->toBe(ClearanceStatus::NotStarted)
        ->and(ApplicationDocument::where('application_id', $app->id)->count())->toBe(0)
        ->and(ApplicationAssignment::where('application_id', $app->id)
            ->where('department_id', Department::where('code', 'CPDO')->value('id'))->exists())
        ->toBeFalse();
});

it('refuses an officer the clearance chooser even on a filing its office reviews', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    // The sanitary officer is routed this filing and may read the application
    // itself — but how a business satisfies its permits is the applicant's
    // decision, and this screen is not part of the review.
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
    expect($body['data'])->toHaveCount(5)
        ->and($body['data'][0]['fee_preview'])->toBeNull()
        ->and($body['meta']['unlocked'])->toBeTrue();

    /*
     * Both writes that need a price say so. `assertPriceable` stops a 500 as
     * much as an unquoted charge: 139 filings in the register point at a
     * soft-deleted business and FeeCalculator dereferences `business->lines`
     * unguarded.
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

    expect($body['data']['state'])->toBe(ClearanceStatus::ForApproval->value);

    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held")->assertOk();
});

it('reports an office form as complete only once the applicant has saved it', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    expect(clearanceRow($app, 'SANITARY')['office_form_complete'])->toBeFalse();

    $this->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", [
        'form_data' => ['owner_name' => 'Ana Dela Cruz'],
    ])->assertOk();

    expect(clearanceRow($app, 'SANITARY')['office_form_complete'])->toBeTrue();
});

it('refuses a payment when the filing owes nothing', function () {
    $app = paidClearanceApplication('Nothing Owed Cafe');

    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'This application has nothing outstanding.');
});

/*
 * RENAMED from "opens the stage for the service at submission, and payment does
 * not move it" — the sentence that named the reversed rule most directly.
 *
 * The service-level statement of the gate, walked through the lifecycle in one
 * test so the transition is visible rather than inferred: shut on a draft with
 * a sentence saying what to do, still shut once submitted and once BPLO has
 * accepted the form, and opened by the payment.
 */
it('opens the stage for the service at payment, and not one step before', function () {
    $service = app(ClearanceService::class);
    $app = draftClearanceApplication('Service Level Cafe');

    expect($service->isUnlocked($app))->toBeFalse()
        ->and($service->lockedReason($app))->toBeString();

    $this->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();

    $submitted = $app->fresh();
    expect($submitted->status)->toBe(ApplicationStatus::ForApproval)
        ->and($service->isUnlocked($submitted))->toBeFalse();

    bploApprovesForm($app);

    $billed = $app->fresh();
    expect($billed->status)->toBe(ApplicationStatus::PendingPayment)
        ->and($service->isUnlocked($billed))->toBeFalse()
        ->and($service->lockedReason($billed))->toBeString();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    $paid = $app->fresh();
    expect($paid->status)->toBe(ApplicationStatus::AwaitingOtherPermits)
        ->and($service->isUnlocked($paid))->toBeTrue()
        ->and($service->lockedReason($paid))->toBeNull();
});
