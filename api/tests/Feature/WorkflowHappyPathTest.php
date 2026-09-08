<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use Illuminate\Support\Carbon;

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
     * 6. The other permits are open now, and the applicant starts each one.
     *
     * THIS is what routes its office — one at a time, when the permit is
     * actually filed. So the assignment count climbs with the loop rather than
     * arriving in a fan-out at payment.
     */
    foreach (array_keys(HAPPY_PATH_OFFICE) as $code) {
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->postJson("/api/v1/applications/{$appId}/clearances/{$code}/apply")
            ->assertOk();
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

        // Paperwork accepted, nobody sent out yet.
        expect(Permit::where('application_id', $appId)->count())->toBe($issued);

        $visitId = $this->withHeaders($officer)
            ->postJson("/api/v1/applications/{$appId}/permits/{$code}/inspection", [
                'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            ])->assertCreated()->json('data.id');

        $this->withHeaders($officer)
            ->postJson("/api/v1/inspections/{$visitId}/conduct", ['result' => 'passed', 'findings' => 'clean'])
            ->assertOk();

        $issued++;
        expect(Permit::where('application_id', $appId)->count())->toBe($issued);
    }

    /*
     * 10. Five permits in, so the filing is in BPLO's queue for its second act
     * — and the Mayor's Permit is NOT among the five. `for_final_approval` is
     * the status that carries the filing there; without it BPLO would have to
     * know by other means that the filing had become ready.
     */
    expect(Application::find($appId)->status->value)->toBe('for_final_approval');
    expect(Permit::where('application_id', $appId)->count())->toBe(5);

    // 11. BPLO's SECOND act. The only place an application becomes Approved and
    // the only place the Mayor's Permit is minted.
    $bploAssignmentId = ApplicationAssignment::where('application_id', $appId)
        ->whereHas('department', fn ($d) => $d->where('code', 'BPLO'))
        ->value('id');
    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$bploAssignmentId}/approve", ['remarks' => 'All requirements met.'])
        ->assertOk();

    $app = Application::find($appId);
    expect($app->status->value)->toBe('approved');

    // Six permits: the Mayor's Permit and the five clearances behind it.
    $permits = Permit::where('application_id', $appId)->get();
    expect($permits)->toHaveCount(6);

    $businessPermit = $permits->firstWhere(
        'permit_type_id',
        PermitType::where('code', PermitType::OUTCOME_CODE)->value('id'),
    );
    expect($businessPermit)->not->toBeNull();

    $days = (int) Carbon::parse($businessPermit->valid_from)
        ->diffInDays(Carbon::parse($businessPermit->valid_until));
    expect($days)->toBe(365);
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

    foreach (array_keys(HAPPY_PATH_OFFICE) as $code) {
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->postJson("/api/v1/applications/{$appId}/clearances/{$code}/apply")
            ->assertOk();
    }

    // One assignment per issuing department => all six queues hit, once each.
    $offices = ApplicationAssignment::where('application_id', $appId)
        ->with('department')->get()->pluck('department.code')->sort()->values()->all();
    expect($offices)->toBe(['BFP', 'BPLO', 'CENRO', 'CHO', 'CPDO', 'OBO']);
});
