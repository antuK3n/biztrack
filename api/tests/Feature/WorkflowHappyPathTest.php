<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Support\RenewalSeason;
use Carbon\CarbonImmutable;

/*
 * The whole lifecycle, walked once, over the 6 September 2026 flow
 * (docs/application-flow-2026-09.md).
 *
 * The shape of this file changed with the flow it describes. It used to read
 * submit → pay → three departments approve → inspections pass → done, which is
 * the machine that no longer exists: payment came first, every office was
 * routed at once, and `under_review` / `for_inspection` were statuses of the
 * APPLICATION. All three of those are now false. BPLO reads the form before the
 * applicant is asked for money, the other permits open only once that money has
 * cleared, and each of them carries its own status on the pivot row rather than
 * on the filing.
 *
 * So the walk below is: submit → BPLO approves the form → pay → the five other
 * permits open, each applied for, read, inspected and ISSUED by its own office
 * → BPLO's second act issues the Mayor's Permit on the strength of them.
 */

/** Which account speaks for the office behind each permit. */
const HAPPY_PATH_OFFICE = [
    'SANITARY' => ['CHO', 'sanitary@biztrack.local'],
    'FSIC' => ['BFP', 'fire@biztrack.local'],
    'ZONING' => ['CPDO', 'zoning@biztrack.local'],
    'OCCUPANCY' => ['OBO', 'obo@biztrack.local'],
    'CEC' => ['CENRO', 'cenro@biztrack.local'],
];

it('walks a filing from draft to an issued Mayor’s Permit, issuing each other permit as its own office finishes', function () {
    $owner = authAs('owner@biztrack.local');

    // 1. Create a business (owner).
    $barangayId = Barangay::first()->id;
    $psicId = PsicCode::first()->id;
    $bizRes = $this->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Test Diner',
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-77001',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '1 Test St.', 'barangay_id' => $barangayId],
        'lines' => [['psic_code_id' => $psicId, 'capitalization' => 100000]],
    ])->assertCreated();
    $businessId = $bizRes->json('data.id');

    /*
     * 2. A DRAFT application. Only the business permit is named, and that is
     * the point: which permits a filing must obtain is not the applicant's to
     * choose any more. `attachRequiredPermitTypes()` runs at submission and
     * attaches BUSINESS plus all five required clearances, so asking for a
     * short list here and getting the full six back is the rule, not a leak.
     */
    $appRes = $this->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated();
    $appId = $appRes->json('data.id');

    /*
     * 3. Submit -> for_approval, and BPLO is routed ALONE.
     *
     * Was `pending_payment`. The reversal is the whole point of the September
     * flow: BPLO reads the form before the applicant is asked for money,
     * because a filing with the wrong line of business should not be paid for.
     * The other five offices are deliberately not routed yet — five queue items
     * nobody can act on would start five service-time clocks over work that has
     * not been handed to them.
     */
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    expect(Application::find($appId)->status->value)->toBe('for_approval');
    expect(Application::find($appId)->permitTypes()->count())->toBe(6);
    expect(ApplicationAssignment::where('application_id', $appId)->count())->toBe(1);

    /*
     * 4. BPLO's FIRST act -> pending_payment.
     *
     * `bploApprovesForm()` also puts an officer's name to the RA 11032
     * processing category, which is a real step rather than scaffolding: BPLO
     * cannot approve a filing still carrying the tier the system guessed at
     * submission.
     */
    bploApprovesForm($appId);
    expect(Application::find($appId)->status->value)->toBe('pending_payment');

    // 5. Pay -> awaiting_other_permits. One bill, raised at submission, covering
    // the business permit and all five clearances (spec rule 4).
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();
    expect(Application::find($appId)->status->value)->toBe('awaiting_other_permits');

    /*
     * 6. The other permits are open now, and the applicant applies for each one
     * and then HANDS IT IN. Two acts, not one, since 9 September 2026.
     *
     * Apply records that the applicant chose to fill in the office's sheet and
     * opens it; it routes nobody and leaves the permit `not_started`. Saving the
     * sheet with `submit` is what gives the office something to read, and THAT
     * is what routes it — one at a time, as each permit is genuinely filed, so
     * the assignment count climbs with the loop rather than arriving in a
     * fan-out at payment.
     *
     * The walk goes through `PUT /office-forms/{code}` rather than the service
     * because this file is the lifecycle as the applicant actually travels it,
     * and that endpoint is the door they press. An empty `form_data` is
     * legitimate — `upsert` validates it `present`, not `required`, because the
     * FSIC sheet's every answer is derived — and the answers themselves are
     * OfficeFormTest's subject, not this one's.
     */
    $routed = 1; // BPLO alone, from the payment.
    foreach (array_keys(HAPPY_PATH_OFFICE) as $code) {
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->postJson("/api/v1/applications/{$appId}/clearances/{$code}/apply")
            ->assertOk();

        // Nobody new: applying opened a form, it did not file a permit.
        expect(ApplicationAssignment::where('application_id', $appId)->count())->toBe($routed);

        $this->withHeaders(authAs('owner@biztrack.local'))
            ->putJson("/api/v1/applications/{$appId}/office-forms/{$code}", [
                'form_data' => [],
                'submit' => true,
            ])->assertOk();

        $routed++;
        expect(ApplicationAssignment::where('application_id', $appId)->count())->toBe($routed);
    }
    expect(ApplicationAssignment::where('application_id', $appId)->count())->toBe(6);

    /*
     * 7-9. Each office reads its own permit, picks a date, and records the
     * visit — and that permit is ISSUED the moment its own office passes it.
     *
     * Rule 7, verified with the client: "no need to wait for each other to be
     * approved." The permit count rising inside this loop is that rule; the old
     * flow issued nothing until every office had finished.
     *
     * Approving the paperwork books NOTHING. The office chooses the date in a
     * separate act, because an automatic date is a promise made by a scheduler
     * that does not know whether anyone is free.
     */
    $issued = 0;
    foreach (HAPPY_PATH_OFFICE as $code => [$deptCode, $email]) {
        $assignmentId = ApplicationAssignment::where('application_id', $appId)
            ->whereHas('department', fn ($d) => $d->where('code', $deptCode))
            ->value('id');

        $officer = authAs($email);
        $this->withHeaders($officer)
            ->postJson("/api/v1/assignments/{$assignmentId}/approve", ['remarks' => 'ok'])
            ->assertOk();

        // Paperwork accepted, nobody sent out yet. Clearances only — the
        // business permit has been out since payment.
        expect(clearancePermitsIssued($appId))->toBe($issued);

        $visitId = $this->withHeaders($officer)
            ->postJson("/api/v1/applications/{$appId}/permits/{$code}/inspection", [
                'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            ])->assertCreated()->json('data.id');

        $this->withHeaders($officer)
            ->postJson("/api/v1/inspections/{$visitId}/conduct", ['result' => 'passed', 'findings' => 'clean'])
            ->assertOk();

        $issued++;
        /*
         * The LAST office's pass used to land TWO certificates — its own
         * clearance and the Mayor's Permit, minted in the same transaction
         * between 18 and 24 September 2026. The LGU then moved the release to
         * payment, so the Mayor's Permit is out long before this loop starts
         * and the `+ 1` is gone with the rule behind it.
         *
         * What the loop is about survives intact, and reads more plainly for
         * it: five offices release five certificates, one each, as each one
         * finishes.
         */
        expect(clearancePermitsIssued($appId))->toBe($issued);
    }

    /*
     * 10. There is no step 11 any more.
     *
     * BPLO used to have a SECOND act here: the filing reached
     * `for_final_approval` with five permits out, and BPLO pressed Approve to
     * mint the sixth. The client asked what that press was for, given that all
     * five clearances are applied for, approved and inspected inside BizTrack —
     * *"what is the purpose of the BPLO checking if all other permits are legit,
     * when those permits are APPLIED DIRECTLY in BizTrack itself?"* — and there
     * was no answer, so the fifth office's pass now issues the Mayor's Permit
     * itself.
     *
     * The press is not merely unnecessary now, it is REFUSED: the filing is
     * already Approved, and `approveAssignment` turns a second press away with
     * "this application has been decided". That is asserted below, because a
     * BPLO screen still offering the button would be the visible half of this
     * change going wrong.
     */
    $app = Application::find($appId);
    expect($app->status->value)->toBe('approved');

    $bploAssignmentId = ApplicationAssignment::where('application_id', $appId)
        ->whereHas('department', fn ($d) => $d->where('code', 'BPLO'))
        ->value('id');
    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$bploAssignmentId}/approve", ['remarks' => 'All requirements met.'])
        ->assertStatus(422);

    // Six permits: the Mayor's Permit and the five clearances behind it.
    $permits = Permit::where('application_id', $appId)->get();
    expect($permits)->toHaveCount(6);

    $businessPermit = $permits->firstWhere(
        'permit_type_id',
        PermitType::where('code', PermitType::OUTCOME_CODE)->value('id'),
    );
    expect($businessPermit)->not->toBeNull();

    /*
     * ── The business permit ends on 20 January, not 365 days from issue ──────
     *
     * This asserted 365 and was right until 17 September 2026, when the client
     * anchored the business permit to the renewal season: *"Business permits
     * always expire on January, regardless of application date."* A filing
     * approved in September now yields a term of about four months, so a
     * day-count assertion measures the calendar rather than the rule.
     *
     * Asserted as the DATE, which is the rule: 20 January of the year after the
     * one it was issued in (`RenewalSeason::endOfTermFor`). That holds whenever
     * the suite runs, where `toBe(365)` only held between January and the end
     * of the year and `toBe(126)` would hold on one day.
     *
     * The five clearances keep 365 days — they renew any time and continue an
     * unexpired term — and that is asserted where they are issued rather than
     * bundled in here.
     */
    /*
     * `->toDateString()` on both sides: `valid_until` is a DATE CAST on the
     * model, so it comes back a Carbon and comparing it to a string fails with
     * a twenty-line object dump that says nothing about permits.
     */
    expect($businessPermit->valid_until->toDateString())->toBe(
        RenewalSeason::endOfTermFor(
            CarbonImmutable::parse($businessPermit->valid_from),
        )->toDateString(),
    );
    expect($businessPermit->valid_until->format('m-d'))
        ->toBe('01-20', 'the business permit year no longer ends on 20 January');
});

it('routes one queue item per office, and only as that office’s permit is filed', function () {
    $owner = authAs('owner@biztrack.local');

    $barangayId = Barangay::first()->id;
    $psicId = PsicCode::first()->id;
    $businessId = $this->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Every Office Mart',
        // Item 94: `registration_type` is the organisation structure, not the
        // agency. "SEC" is refused on purpose — it registers both partnerships
        // and corporations, so it does not say which this shop is.
        'registration_type' => 'corporation',
        'registration_number' => 'SEC-77002',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '6 Office Rd.', 'barangay_id' => $barangayId],
        'lines' => [['psic_code_id' => $psicId]],
    ])->assertCreated()->json('data.id');

    /*
     * Six permit types exist, not seven. Market Clearance and the City Market
     * Office were removed on 6 September 2026, so what is left is the Mayor's
     * Permit and the five required clearances — see
     * PermitType::REQUIRED_CLEARANCE_CODES.
     */
    $allTypeIds = PermitType::pluck('id')->all();
    expect($allTypeIds)->toHaveCount(6);

    $appId = $this->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => $allTypeIds,
    ])->assertCreated()->json('data.id');

    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    /*
     * Paid, and still only BPLO has a queue item. This is the assertion the old
     * version of this test could not make: payment used to fan out to every
     * department at once, and the count here was the whole point. Routing now
     * follows the applicant, so a paid filing nobody has filed a clearance on
     * sits in exactly one queue.
     */
    expect(ApplicationAssignment::where('application_id', $appId)->count())->toBe(1);

    /*
     * And applying is still not filing. Every one of the five is applied for
     * first — which opens five office sheets and routes nobody — and the queues
     * stay at one until each sheet is handed in. "Filed" in this test's name
     * means submitted, which is the distinction the 9 September split drew.
     */
    foreach (array_keys(HAPPY_PATH_OFFICE) as $code) {
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->postJson("/api/v1/applications/{$appId}/clearances/{$code}/apply")
            ->assertOk();
    }
    expect(ApplicationAssignment::where('application_id', $appId)->count())->toBe(1);

    foreach (array_keys(HAPPY_PATH_OFFICE) as $code) {
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->putJson("/api/v1/applications/{$appId}/office-forms/{$code}", [
                'form_data' => [],
                'submit' => true,
            ])->assertOk();
    }

    // One assignment per issuing department => all six queues hit, once each.
    $offices = ApplicationAssignment::where('application_id', $appId)
        ->with('department')->get()->pluck('department.code')->sort()->values()->all();
    expect($offices)->toBe(['BFP', 'BPLO', 'CENRO', 'CHO', 'CPDO', 'OBO']);
});
