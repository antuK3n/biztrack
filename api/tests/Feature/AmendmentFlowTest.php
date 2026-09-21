<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationAmendment;
use App\Models\ApplicationAssignment;
use App\Models\AppNotification;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Department;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\UnbilledPermitFee;
use App\Models\User;
use App\Support\AmendableFields;
use App\Support\PermitFace;

/*
 * The amendment process.
 *
 * Client, 19 September 2026: *"We have clarified with the LGU that only business
 * permit details can be amended."* They also asked the question this file
 * mostly answers — whether an officer should retype the business details by
 * hand when an amendment arrives. They should not: the applicant states the new
 * value, BPLO decides, and approval writes it in one transaction.
 *
 * Three things are worth pinning, in the order they can go wrong:
 *
 *  1. the SHAPE of the filing — an amendment carries the business permit alone,
 *     not the five clearances it used to drag along;
 *  2. the BOUNDARY — a detail outside the whitelist is refused by name rather
 *     than dropped, and requested changes freeze once the filing is priced;
 *  3. the APPLICATION of it — the register changes on approval, keeps what it
 *     replaced, and cannot be made to do it twice.
 */

/** A submitted amendment on a business the owner holds, priced and ready for BPLO. */
function amendmentFiling(array $changes = ['business_area_sqm' => '250']): array
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Amendment Test Store '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '7 Amend Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 200000]],
    ])->assertCreated()->json('data.id');

    // A prior permit to amend: the submit gate refuses an amendment naming none.
    $priorApp = Application::create([
        'business_id' => $businessId,
        'applicant_user_id' => User::where('email', 'owner@biztrack.local')->value('id'),
        'application_type' => 'new',
        'status' => 'approved',
    ]);
    $prior = Permit::create([
        'application_id' => $priorApp->id,
        'business_id' => $businessId,
        'permit_type_id' => PermitType::where('code', PermitType::OUTCOME_CODE)->value('id'),
        'permit_number' => 'AMEND-PRIOR-'.random_int(10000, 99999),
        'issued_at' => now()->subMonths(2),
        'valid_from' => now()->subMonths(2),
        'valid_until' => now()->addMonths(4),
        'status' => 'active',
    ]);

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'amendment',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
        'prior_permit_id' => $prior->id,
        'amendment_other' => 'Floor area corrected after re-measurement.',
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/amendments", [
        'changes' => collect($changes)
            ->map(fn ($v, $field) => ['field' => $field, 'new_value' => $v])
            ->values()
            ->all(),
    ])->assertOk();

    return [$appId, $businessId];
}

it('carries the business permit alone, not the five clearances', function () {
    /*
     * The defect this replaces: `permitTypeIdsAtSubmission` special-cased only
     * Renewal, so an amendment fell through to the new-filing branch and
     * attached all six types. It was then billed for five certificates it never
     * asked for and routed to five offices with nothing to review — a floor-area
     * correction requiring a fresh Fire Safety inspection.
     */
    [$appId] = amendmentFiling();
    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    $codes = Application::findOrFail($appId)->permitTypes()->pluck('code')->all();
    expect($codes)->toBe([PermitType::OUTCOME_CODE]);
});

it('refuses a detail that is not the business permit’s to change', function () {
    /*
     * Refused BY NAME and as a whole. The message has to name what cannot be
     * amended and where to go instead, because "only business permit details"
     * is the LGU's distinction and not one an applicant can be expected to draw
     * from a validation error.
     */
    [$appId] = amendmentFiling();
    $owner = authAs('owner@biztrack.local');

    $message = test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/amendments", [
            'changes' => [
                ['field' => 'business_area_sqm', 'new_value' => '300'],
                ['field' => 'owner_user_id', 'new_value' => '2'],
            ],
        ])
        ->assertStatus(422)
        ->json('errors.changes.0');

    /*
     * The refusal names what IS on the form, by box. It used to point at "the
     * BPLO window", which was true when address, ownership and line of
     * business were all refused — and became a lie the day FO-003 was mapped
     * properly and all three were built. An error that sends somebody to a
     * counter for something the screen behind them offers is worse than no
     * error.
     */
    expect($message)->toContain('owner_user_id')
        ->and($message)->toContain('change of ownership')
        ->and($message)->toContain('change of address');

    // And the GOOD field in that request was not written either: a partly
    // applied request leaves the applicant to work out which part went missing.
    expect(
        ApplicationAmendment::where('application_id', $appId)
            ->where('field', 'business_area_sqm')
            ->value('new_value')
    )->toBe('250');
});

it('validates each detail with its own rule', function () {
    // A floor area is a decimal and an employee count is not. One shared
    // "numeric" rule would accept 12.5 employees.
    [$appId] = amendmentFiling();
    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/amendments", [
            'changes' => [['field' => 'total_employees', 'new_value' => '12.5']],
        ])
        ->assertStatus(422);

    test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/amendments", [
            'changes' => [['field' => 'business_area_sqm', 'new_value' => '12.5']],
        ])
        ->assertOk();
});

it('shows what the register holds beside what was asked', function () {
    [$appId, $businessId] = amendmentFiling(['trade_name' => 'The New Sign']);
    $owner = authAs('owner@biztrack.local');

    $rows = collect(
        test()->withHeaders($owner)
            ->getJson("/api/v1/applications/{$appId}/amendments")->assertOk()->json('data')
    )->keyBy('field');

    // Every amendable detail is listed, not only the ones asked for: the screen
    // is "what it is now, what you want it to be".
    expect($rows)->toHaveCount(count(AmendableFields::keys()));

    expect($rows['trade_name']['requested'])->toBeTrue()
        ->and($rows['trade_name']['new_value'])->toBe('The New Sign')
        ->and($rows['trade_name']['current_value'])
        ->toBe(Business::findOrFail($businessId)->trade_name)
        ->and($rows['trade_name']['applied_at'])->toBeNull();

    expect($rows['total_employees']['requested'])->toBeFalse();
});

it('lets the applicant withdraw one requested change', function () {
    [$appId] = amendmentFiling(['business_area_sqm' => '250', 'delivery_units' => '3']);
    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)
        ->deleteJson("/api/v1/applications/{$appId}/amendments/delivery_units")
        ->assertOk();

    expect(ApplicationAmendment::where('application_id', $appId)->pluck('field')->all())
        ->toBe(['business_area_sqm']);
});

it('freezes the requested changes once the amendment is submitted', function () {
    /*
     * The bill is assessed at submission over what the filing asks for. A change
     * edited afterwards would bill for one thing and apply another.
     */
    [$appId] = amendmentFiling();
    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/amendments", [
            'changes' => [['field' => 'business_area_sqm', 'new_value' => '999']],
        ])
        ->assertStatus(422);

    expect(
        ApplicationAmendment::where('application_id', $appId)->value('new_value')
    )->toBe('250');
});

it('applies the change to the business on BPLO’s approval, keeping what it replaced', function () {
    /*
     * The client's question answered: nobody retypes anything. The value the
     * applicant stated is written here, and `old_value` records what it
     * displaced — captured at APPLICATION time, so it is what was actually
     * overwritten rather than what the register held when the form was filed.
     */
    [$appId, $businessId] = amendmentFiling(['business_area_sqm' => '250']);
    $owner = authAs('owner@biztrack.local');
    $before = Business::findOrFail($businessId)->business_area_sqm;

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    /*
     * ONE BPLO act, and no payment stage at all.
     *
     * The client settled both on 19 September 2026: *"All amendment payments
     * will reflect when a business permit is renewed, just like the payments
     * for other permits."* With nothing to pay there is nothing to wait for
     * between BPLO reading the affidavit and BPLO completing it, so the two
     * acts this flow used to have collapsed into one.
     */
    bploApprovesForm($appId);

    expect(Application::find($appId)->status)->toBe(ApplicationStatus::Approved);

    // No bill was ever raised: an amendment is not priced by the permit
    // schedule, which quoted the whole annual permit for a floor-area change.
    expect(Application::find($appId)->feeAssessment)->toBeNull();

    // The register changed.
    expect((float) Business::findOrFail($businessId)->business_area_sqm)->toBe(250.0);

    // And it remembers what it changed from.
    $row = ApplicationAmendment::where('application_id', $appId)->firstOrFail();
    expect($row->applied_at)->not->toBeNull()
        ->and($row->old_value)->toBe($before === null ? null : (string) $before);
});

it('reprints the business permit over the same term, superseding the old one', function () {
    /*
     * Client's decision, 19 September 2026: reissue and supersede, inheriting
     * the old expiry.
     *
     * The inherited dates are the load-bearing half. `issuePermitFor` CONTINUES
     * a term — a renewal issued while its predecessor is live starts the day
     * after the old one ends — which is right for a renewal and would hand an
     * amendment a free extra year. So the reissue copies both dates exactly:
     * amending in June buys no time.
     */
    [$appId, $businessId] = amendmentFiling(['trade_name' => 'The New Sign']);
    $owner = authAs('owner@biztrack.local');

    $old = Permit::where('business_id', $businessId)->firstOrFail();

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);

    $fresh = Permit::where('business_id', $businessId)
        ->where('id', '!=', $old->id)
        ->firstOrFail();

    // Same term, to the day.
    expect($fresh->valid_from->toDateString())->toBe($old->valid_from->toDateString())
        ->and($fresh->valid_until->toDateString())->toBe($old->valid_until->toDateString());

    // One live permit, not two, and the chain records which replaced which.
    expect($fresh->status->value)->toBe('active')
        ->and($old->fresh()->status->value)->toBe('superseded')
        ->and($fresh->prior_permit_id)->toBe($old->id);

    // And its face carries the AMENDED detail, which is the point of reprinting.
    expect($fresh->issued_details['trade_name'])->toBe('The New Sign');
});

it('freezes a permit face at issue, so an amendment cannot rewrite old certificates', function () {
    /*
     * The defect behind the client's address question. The PDF used to be
     * assembled from the live business record at download time, so editing a
     * business rewrote every certificate it had ever held — and after an
     * address amendment, five clearances would have described premises their
     * offices had never seen.
     */
    [$appId, $businessId] = amendmentFiling(['trade_name' => 'Renamed After Issue']);
    $owner = authAs('owner@biztrack.local');

    $old = Permit::where('business_id', $businessId)->firstOrFail();
    // Minted by the fixture rather than by the workflow, so give it the
    // snapshot the workflow would have written at issue.
    $old->update(['issued_details' => PermitFace::capture(
        Business::findOrFail($businessId)->load(['address.barangay', 'owner', 'lines.psicCode'])
    )]);
    $faceBefore = $old->fresh()->issued_details['trade_name'];

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);

    // The register moved on; the superseded certificate still says what it said.
    expect(Business::findOrFail($businessId)->trade_name)->toBe('Renamed After Issue')
        ->and($old->fresh()->issued_details['trade_name'])->toBe($faceBefore);

    // And the drift is computable, which is the durable half of telling an
    // office that its certificate is out of date.
    $drift = PermitFace::changedSince($old->fresh());
    expect($drift)->toHaveKey('trade_name')
        ->and($drift['trade_name']['now'])->toBe('Renamed After Issue');
});

it('refuses a second approval, so a change cannot be applied twice', function () {
    /*
     * The dangerous replay: applying twice would stamp `old_value` with the
     * value the FIRST pass installed, destroying the record of what the
     * amendment actually replaced. Two guards stand in the way and this asserts
     * the outer one — a decided filing cannot be approved again.
     */
    [$appId, $businessId] = amendmentFiling(['business_area_sqm' => '250']);
    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);

    $bplo = ApplicationAssignment::where('application_id', $appId)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->firstOrFail();
    $headers = authAs('bplo@biztrack.local');

    $applied = ApplicationAmendment::where('application_id', $appId)->firstOrFail();

    test()->withHeaders($headers)
        ->postJson("/api/v1/assignments/{$bplo->id}/approve")->assertStatus(422);

    // Unchanged by the refused second press — same old_value, same stamp.
    $after = ApplicationAmendment::where('application_id', $appId)->firstOrFail();
    expect($after->old_value)->toBe($applied->old_value)
        ->and($after->applied_at->toISOString())->toBe($applied->applied_at->toISOString())
        ->and((float) Business::findOrFail($businessId)->business_area_sqm)->toBe(250.0);
});

it('refuses requested changes on a filing that is not an amendment', function () {
    // The rows exist only for amendments. A renewal carrying them would be a
    // filing quietly able to rewrite the register through a side door.
    $owner = authAs('owner@biztrack.local');
    $business = Business::where('owner_user_id', User::where('email', 'owner@biztrack.local')->value('id'))
        ->firstOrFail();

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $business->id,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/amendments", [
            'changes' => [['field' => 'business_area_sqm', 'new_value' => '300']],
        ])
        ->assertStatus(422);
});

it('refuses somebody else’s amendment', function () {
    [$appId] = amendmentFiling();

    test()->withHeaders(authAs('juan@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/amendments")
        ->assertStatus(403);

    test()->withHeaders(authAs('juan@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/amendments", [
            'changes' => [['field' => 'business_area_sqm', 'new_value' => '1']],
        ])
        ->assertStatus(403);
});

it('tells the offices that certified this business when its address changes', function () {
    /*
     * The TIMELY half of the client's decision, 19 September 2026: notify, and
     * only for changes that alter what an office actually verified.
     *
     * A Sanitary Permit is CHO's statement about premises CHO visited, so
     * moving the premises makes its face describe somewhere it has not been.
     * The durable half is `PermitFace::changedSince()`, asserted in the freeze
     * test above — a notification is read once and then gone.
     */
    [$appId, $businessId] = amendmentFiling(['address_street' => 'Moved Avenue']);
    $owner = authAs('owner@biztrack.local');

    // A Sanitary Permit this business holds, so CHO has something to be told about.
    $cho = Department::where('code', 'CHO')->firstOrFail();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();
    Permit::create([
        'application_id' => Application::where('business_id', $businessId)->value('id'),
        'business_id' => $businessId,
        'permit_type_id' => $sanitary->id,
        'permit_number' => 'AMEND-SAN-'.random_int(10000, 99999),
        'issued_at' => now()->subMonth(),
        'valid_from' => now()->subMonth(),
        'valid_until' => now()->addMonths(6),
        'status' => 'active',
    ]);

    $officer = User::where('department_id', $cho->id)->firstOrFail();
    $before = AppNotification::where('user_id', $officer->id)->count();

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    /*
     * An address amendment now carries the zoning clearance and cannot be
     * approved until CPDO issues it. Set straight on the pivot rather than
     * driven through CPDO's whole review: this test is about who gets told
     * afterwards, and walking the clearance would only add a second subject.
     */
    $app = Application::findOrFail($appId);
    $app->permitTypes()->updateExistingPivot(
        PermitType::where('code', 'ZONING')->value('id'),
        ['status' => 'approved'],
    );

    bploApprovesForm($appId);

    /*
     * The street actually moved, and `line1` was RECOMPOSED from it.
     *
     * 'Moved Avenue' and not '99 Moved Avenue': the fixture's business was
     * created with a bare `line1` and no `house_bldg_no`, so there is no house
     * number to compose back in. That is the point of the change — `line1` is
     * derived from the parts, exactly as BusinessController does it, so the
     * next business write cannot quietly undo the amendment by recomposing
     * from parts that were never updated.
     */
    $moved = Business::findOrFail($businessId)->address;
    expect($moved->street)->toBe('Moved Avenue')
        ->and($moved->line1)->toBe('Moved Avenue');

    $notice = AppNotification::where('user_id', $officer->id)
        ->where('type', 'amendment')
        ->latest('id')
        ->first();

    expect(AppNotification::where('user_id', $officer->id)->count())
        ->toBeGreaterThan($before)
        ->and($notice)->not->toBeNull()
        ->and($notice->body)->toContain('Street');
});

it('does not pester the offices about a detail they never verified', function () {
    /*
     * A corrected employee count tells CHO nothing it acted on. Notifying for
     * it is how five accounts learn to ignore the notices that matter, which
     * makes the channel worse than not having one.
     */
    [$appId, $businessId] = amendmentFiling(['total_employees' => '14']);
    $owner = authAs('owner@biztrack.local');

    $cho = Department::where('code', 'CHO')->firstOrFail();
    Permit::create([
        'application_id' => Application::where('business_id', $businessId)->value('id'),
        'business_id' => $businessId,
        'permit_type_id' => PermitType::where('code', 'SANITARY')->value('id'),
        'permit_number' => 'AMEND-QUIET-'.random_int(10000, 99999),
        'issued_at' => now()->subMonth(),
        'valid_from' => now()->subMonth(),
        'valid_until' => now()->addMonths(6),
        'status' => 'active',
    ]);

    $officer = User::where('department_id', $cho->id)->firstOrFail();
    $before = AppNotification::where('user_id', $officer->id)
        ->where('type', 'amendment')->count();

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);

    expect((int) Business::findOrFail($businessId)->total_employees)->toBe(14);
    expect(
        AppNotification::where('user_id', $officer->id)->where('type', 'amendment')->count()
    )->toBe($before);
});

it('refuses to submit an amendment that says only THAT something changed', function () {
    /*
     * Section A says what changed; this is the gate on what it changed TO.
     *
     * Without it an amendment reaches BPLO carrying a tick and nothing else,
     * `approveAmendment` applies an empty set, and the filing closes as
     * approved having changed nothing — a success at doing nothing, which is
     * worse than a refusal because everybody downstream believes it worked.
     */
    [$appId] = amendmentFiling(['business_area_sqm' => '250']);
    $owner = authAs('owner@biztrack.local');

    // Withdraw the only requested change, leaving the Section A tick behind.
    test()->withHeaders($owner)
        ->deleteJson("/api/v1/applications/{$appId}/amendments/business_area_sqm")
        ->assertOk();

    $message = test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/submit")
        ->assertStatus(422)
        ->json('errors.requested_changes.0');

    expect($message)->toContain('New Details')
        ->and(Application::find($appId)->status->value)->toBe('draft');
});

it('shows BPLO the requested changes, old beside new', function () {
    // The officer's door. `AmendmentController::index` is owner-only, so the
    // review sheet reads these off ApplicationResource instead — and a rule
    // wired into one of two doors is the failure this codebase keeps relearning.
    [$appId, $businessId] = amendmentFiling(['trade_name' => 'The New Sign']);
    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    $rows = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}")
        ->assertOk()
        ->json('data.requested_changes');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['label'])->toBe('Trade name')
        ->and($rows[0]['new_value'])->toBe('The New Sign')
        ->and($rows[0]['current_value'])->toBe(Business::findOrFail($businessId)->trade_name)
        // Not yet applied, so there is nothing to report as replaced.
        ->and($rows[0]['old_value'])->toBeNull()
        ->and($rows[0]['applied_at'])->toBeNull();
});

it('sends no requested_changes on a filing that is not an amendment', function () {
    // Null rather than [], so a reader can tell "not an amendment" from "an
    // amendment asking for nothing" — the second is a filing to refuse.
    $owner = authAs('owner@biztrack.local');
    $business = Business::where('owner_user_id', User::where('email', 'owner@biztrack.local')->value('id'))
        ->firstOrFail();

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $business->id,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    expect(
        test()->withHeaders($owner)->getJson("/api/v1/applications/{$appId}")
            ->assertOk()->json('data.requested_changes')
    )->toBeNull();
});

it('stacks the amendment fee for the January renewal to collect', function () {
    /*
     * Client, 19 September 2026: *"every other permit renewal and every
     * amendment done before business permit renewal on January will have their
     * fee amounts stacked up until they are ready to be paid on the business
     * permit renewal on January."*
     *
     * The same pile the deferred clearance fees go into. The AMOUNT is ₱0 and
     * deliberately so — A10-2016 as seeded carries no amendment fee — but the
     * row is written anyway, so the stacking is real and the line is visible
     * the day a figure is given.
     */
    [$appId, $businessId] = amendmentFiling(['business_area_sqm' => '250']);
    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);

    $fee = UnbilledPermitFee::where('application_id', $appId)->first();

    expect($fee)->not->toBeNull()
        ->and($fee->business_id)->toBe($businessId)
        // Unclaimed: no bill has swept it yet.
        ->and($fee->billed_on_application_id)->toBeNull()
        ->and($fee->billed_at)->toBeNull()
        // It says what it is for, so the January line does not read as a
        // second business-permit fee beside the real one.
        ->and($fee->description)->toContain('Amendment')
        ->and($fee->description)->toContain('Floor area');
});

it('does not stack the same amendment twice', function () {
    // A replayed approval must not double the pile, the same way it must not
    // re-apply the change or overwrite what it replaced.
    [$appId] = amendmentFiling(['trade_name' => 'Twice Over']);
    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);

    $bplo = ApplicationAssignment::where('application_id', $appId)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->firstOrFail();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$bplo->id}/approve")->assertStatus(422);

    expect(UnbilledPermitFee::where('application_id', $appId)->count())->toBe(1);
});

it('prices the amendment from the LGU setting, not from the ordinance', function () {
    /*
     * The amount is a SETTING because the ordinance does not carry one:
     * verified 19 September 2026 against all 423 seeded fee rules and the
     * extract of A10-2016 itself. `fee_rules` is the ordinance and every row
     * there names the section it came from, so inventing one for this fee
     * would manufacture a provenance it does not have.
     *
     * Asserted by SETTING it, which is the only way to prove the wiring is
     * live rather than that zero happens to equal zero.
     */
    config(['biztrack.amendment_fee' => 350]);

    [$appId, $businessId] = amendmentFiling(['trade_name' => 'Priced Signage']);
    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);

    $fee = UnbilledPermitFee::where('application_id', $appId)->firstOrFail();

    expect((float) $fee->amount)->toBe(350.0)
        ->and($fee->business_id)->toBe($businessId)
        // Still unclaimed, so January will sweep it with the clearance fees.
        ->and($fee->billed_on_application_id)->toBeNull();
});

it('defaults the amendment fee to zero while the LGU has not set one', function () {
    // Zero because it is unknown, not because it is free — and the row is
    // written regardless, so the January line exists and says what it is for.
    expect(config('biztrack.amendment_fee'))->toBe(0.0);
});

it('carries the zoning clearance when the premises move, and only then', function () {
    /*
     * Client's decision, 19 September 2026: a move must not be approved until
     * CPDO has cleared the new address, and CPDO must get a real filing.
     *
     * Those two are circular if the zoning filing is raised BY the approval
     * that waits for it, so the clearance rides on the amendment itself. It
     * also solves the second knot: CPDO has to assess where the business is
     * MOVING TO, and the register still holds the old address until approval —
     * so the proposed value has to be on the filing CPDO is looking at.
     */
    /*
     * ── The PIN is the trigger, not the barangay ─────────────────────────
     *
     * Until 21 September 2026 this keyed on the barangay changing, which
     * tests the wrong thing: zoning belongs to a LOCATION, and a residential
     * street and a commercial street sit in one barangay all the time. A
     * business could move to a street that forbids its trade and never trip
     * a barangay test. Client: *"what if you changed your street and that
     * street now prohibits this type of business, but also what if you are
     * just correcting any typo."*
     *
     * BizTrack cannot answer the first question — the zoning sheets are
     * raster images with no geometry (see `ZoningClassification`) — so it
     * decides only whether to ASK CPDO, off the one signal it holds.
     */
    [$moveId] = amendmentFiling([
        'address_street' => 'Rizal Avenue',
        'address_pin' => '14.6600,120.9500',
    ]);
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$moveId}/submit")->assertOk();

    expect(Application::findOrFail($moveId)->permitTypes()->pluck('code')->sort()->values()->all())
        ->toBe(['BUSINESS', 'ZONING']);

    /*
     * And correcting how the address is WRITTEN does not. The premises have
     * not moved, the pin is untouched, and there is nothing new for CPDO to
     * look at — charging a fresh clearance for a misspelt street name bills
     * somebody for fixing a typo.
     */
    [$streetId] = amendmentFiling(['address_street' => 'Moved Avenue']);
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$streetId}/submit")->assertOk();

    expect(Application::findOrFail($streetId)->permitTypes()->pluck('code')->all())
        ->toBe([PermitType::OUTCOME_CODE]);

    /*
     * A barangay change on its own does not either — not because it is
     * harmless, but because the applicant's step will not let one through
     * without a pin inside the new barangay, so in practice it always
     * arrives WITH a moved pin. Asserted so that the two rules are visibly
     * separate: the barangay decides where the pin must land, the pin
     * decides whether CPDO is asked.
     */
    $elsewhere = Barangay::where('id', '!=', Barangay::first()->id)->firstOrFail();
    [$brgyOnlyId] = amendmentFiling(['address_barangay_id' => (string) $elsewhere->id]);
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$brgyOnlyId}/submit")->assertOk();

    expect(Application::findOrFail($brgyOnlyId)->permitTypes()->pluck('code')->all())
        ->toBe([PermitType::OUTCOME_CODE]);

    // And a floor area tells CPDO nothing it assessed either.
    [$areaId] = amendmentFiling(['business_area_sqm' => '250']);
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$areaId}/submit")->assertOk();

    expect(Application::findOrFail($areaId)->permitTypes()->pluck('code')->all())
        ->toBe([PermitType::OUTCOME_CODE]);
});

it('refuses to approve a move until the new address is cleared', function () {
    // The register must never show a business at premises CPDO has not
    // assessed — and the refusal names what is missing rather than just
    // saying no, because BPLO cannot clear it themselves.
    $elsewhere = Barangay::where('id', '!=', Barangay::first()->id)->firstOrFail();
    // WITH a pin, because that is what carries the zoning clearance now.
    [$appId, $businessId] = amendmentFiling([
        'address_barangay_id' => (string) $elsewhere->id,
        'address_pin' => '14.6600,120.9500',
    ]);
    $owner = authAs('owner@biztrack.local');
    $before = Business::findOrFail($businessId)->address->barangay_id;

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    /*
     * The RA 11032 category is confirmed first, because that guard runs ahead
     * of this one and would otherwise be the refusal under test. Set on the
     * row rather than through the endpoint: which officer picked the tier is
     * not what this is about.
     */
    Application::findOrFail($appId)->update([
        'complexity' => 'simple',
        'complexity_set_by_user_id' => User::where('email', 'bplo@biztrack.local')->value('id'),
    ]);

    $bplo = ApplicationAssignment::where('application_id', $appId)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->firstOrFail();

    $message = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$bplo->id}/approve")
        ->assertStatus(422)
        ->json('message');

    expect($message)->toContain('new address');

    // Nothing moved: not the register, not the filing.
    expect(Business::findOrFail($businessId)->address->barangay_id)->toBe($before)
        ->and(Application::findOrFail($appId)->status->value)->not->toBe('approved');
});

/*
 * ── The paper, box by box ─────────────────────────────────────────────────
 *
 * Client, 21 September 2026: *"the fields in paper and in system DOES NOT
 * REALLY MATCH. Make sure everything matches up and APPLY RULES WHERE
 * NECESSARY."* Three of MCG-BPLO-FO-003's four checkboxes were refused
 * outright and the fourth wrote a composed column. These pin the mapping so
 * the next edit to `AmendableFields` has to argue with the paper, not just
 * with a column name.
 */

it('lays the amendable details out as FO-003’s four boxes', function () {
    [$appId] = amendmentFiling();

    $rows = collect(
        test()->withHeaders(authAs('owner@biztrack.local'))
            ->getJson("/api/v1/applications/{$appId}/amendments")->assertOk()->json('data')
    );

    /*
     * I, II, III, then the catch-all — the SCREEN's order, which is not the
     * paper's. FO-003 prints the unnumbered box first; a collapsed section
     * list read top to bottom should not lead with the box defined by not
     * being any of the others. Client's decision, 21 September 2026.
     */
    expect($rows->pluck('group')->unique()->values()->all())
        ->toBe(['address', 'ownership', 'trade_name', 'other']);

    // The numerals are the form's own, so screen and paper read alike.
    expect($rows->firstWhere('group', 'address')['group_paper'])->toBe('I')
        ->and($rows->firstWhere('group', 'ownership')['group_paper'])->toBe('II')
        ->and($rows->firstWhere('group', 'trade_name')['group_paper'])->toBe('III')
        ->and($rows->firstWhere('group', 'other')['group_paper'])->toBeNull();

    /*
     * Every box's blanks are present. Before this, "change of line of
     * business", "additional line of business", the new owner and the paper's
     * three "AMENDMENT OF … DETAILS" lines existed nowhere in the system.
     */
    foreach ([
        'line_of_business', 'other_amendment',
        'address_house_bldg_no', 'address_street', 'address_barangay_id',
        'address_pin', 'address_details',
        'owner_name', 'ownership_details',
        'trade_name', 'trade_name_details',
    ] as $field) {
        expect($rows->firstWhere('field', $field))->not->toBeNull("missing: {$field}");
    }

    // A line of business is a PSIC code and a barangay is a list, not free text.
    expect($rows->firstWhere('field', 'line_of_business')['type'])->toBe('psic')
        ->and($rows->firstWhere('field', 'address_barangay_id')['type'])->toBe('barangay')
        ->and($rows->firstWhere('field', 'address_pin')['type'])->toBe('pin')
        ->and($rows->firstWhere('field', 'address_details')['type'])->toBe('note');
});

it('resolves an id to something a person can read', function () {
    // The row carries the PSIC title beside the code, because an id is not a
    // thing to show anybody and the client would need the whole table to
    // print one row.
    $trade = PsicCode::skip(1)->first();
    [$appId] = amendmentFiling(['line_of_business' => (string) $trade->id]);

    $row = collect(
        test()->withHeaders(authAs('owner@biztrack.local'))
            ->getJson("/api/v1/applications/{$appId}/amendments")->assertOk()->json('data')
    )->firstWhere('field', 'line_of_business');

    expect($row['new_value'])->toBe((string) $trade->id)
        ->and($row['new_label'])->toBe($trade->title);
});

it('replaces the one line of business rather than adding to it', function () {
    /*
     * ── One business, one line ────────────────────────────────────────
     *
     * The paper has both "CHANGE OF LINE OF BUSINESS" and "ADDITIONAL LINE OF
     * BUSINESS", and both were built on 21 September 2026 — which was reading
     * the form without checking the system. Client, the same day: *"Is it
     * possible for a business to have two lines of business? Currently, in our
     * system, it only has one."*
     *
     * It is, and the apply wizard enforces it: picking a trade REPLACES what
     * is there. So an added line would be a state no other path can produce
     * and the next filing would undo. The count is asserted, not just the
     * code, because that is the invariant that was briefly broken.
     */
    $replacement = PsicCode::skip(1)->first();

    [$appId, $businessId] = amendmentFiling([
        'line_of_business' => (string) $replacement->id,
    ]);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);

    $lines = Business::findOrFail($businessId)->lines()->orderBy('id')->get();

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->psic_code_id)->toBe($replacement->id);
});

it('refuses an additional line of business by name', function () {
    // Not silently ignored. The applicant asked for something the register
    // cannot hold, and a request that vanishes is worse than one refused.
    [$appId] = amendmentFiling();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/amendments", [
            'changes' => [[
                'field' => 'additional_line_of_business',
                'new_value' => (string) PsicCode::skip(2)->first()->id,
            ]],
        ])
        ->assertStatus(422);
});

it('does not re-rate a change of trade, and says which detail the fee is for', function () {
    /*
     * The per-line surcharge was charged here for an added line, and went out
     * with that field: one business holds one line, so nothing an amendment
     * can do changes the count the surcharge is charged per.
     *
     * A line REPLACED is not re-rated either — the flat schedule does not vary
     * by trade, and whether the ordinance's own rates do is the open question
     * about which of the two pricing systems is authoritative. The January
     * renewal reassesses the business from scratch, as it does every year.
     */
    $replacement = PsicCode::skip(1)->first();
    [$appId] = amendmentFiling(['line_of_business' => (string) $replacement->id]);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);

    $fee = UnbilledPermitFee::where('application_id', $appId)->firstOrFail();

    expect((float) $fee->amount)->toBe((float) config('biztrack.amendment_fee', 0))
        ->and($fee->description)->toContain('Change of line of business');
});

it('records a change of ownership and tells BPLO to move the account', function () {
    /*
     * The permit prints `business->owner->fullName()` — the ACCOUNT's name —
     * and the new owner may hold no BizTrack account at all. Client's
     * decision, 21 September 2026: *"Write the owner's name, BPLO moves the
     * account."* So approval must NOT reassign the business on its own, and
     * must not let the outstanding half go unsaid.
     */
    [$appId, $businessId] = amendmentFiling([
        'owner_name' => 'Maria Reyes',
        'ownership_details' => 'Sold to the buyer named on the attached deed.',
    ]);

    $ownerBefore = Business::findOrFail($businessId)->owner_user_id;
    $bplo = User::where('email', 'bplo@biztrack.local')->firstOrFail();
    $noticesBefore = AppNotification::where('user_id', $bplo->id)->count();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);

    // The account did NOT move on its own.
    expect(Business::findOrFail($businessId)->owner_user_id)->toBe($ownerBefore);

    // The request is on the record, so the counter can act on it.
    expect(
        ApplicationAmendment::where('application_id', $appId)
            ->where('field', 'owner_name')
            ->value('new_value')
    )->toBe('Maria Reyes');

    // And BPLO was told, by name, that the transfer is theirs to finish.
    $notice = AppNotification::where('user_id', $bplo->id)
        ->where('type', 'amendment')
        ->latest('id')
        ->first();

    expect(AppNotification::where('user_id', $bplo->id)->count())
        ->toBeGreaterThan($noticesBefore)
        ->and($notice->body)->toContain('Maria Reyes');
});

it('recomposes line1, so a later business edit cannot undo the move', function () {
    /*
     * ── The regression that started the rewrite ──────────────────────────
     *
     * `line1` is COMPOSED from `house_bldg_no` and `street` by
     * `BusinessController::syncAddressAndLines`, and the old amendment wrote
     * `line1` directly. So an approved address amendment stood only until the
     * next business write recomposed the column from parts nobody had
     * updated — and silently put the old address back.
     *
     * Two failure modes in one test, because they were one bug: the parts
     * must be written, and the composite must be derived from them.
     */
    [$appId, $businessId] = amendmentFiling([
        'address_house_bldg_no' => '99',
        'address_street' => 'Moved Avenue',
    ]);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);

    $address = Business::findOrFail($businessId)->address;
    expect($address->house_bldg_no)->toBe('99')
        ->and($address->street)->toBe('Moved Avenue')
        ->and($address->line1)->toBe('99 Moved Avenue');

    /*
     * Now the write that used to revert it. The owner edits the business and
     * sends the address back unchanged, which is what every autosave does.
     */
    $business = Business::findOrFail($businessId);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/businesses/{$businessId}", [
            'name' => $business->name,
            'registration_type' => $business->registration_type,
            'registration_number' => $business->registration_number,
            'tin' => $business->tin,
            'address' => [
                'house_bldg_no' => '99',
                'street' => 'Moved Avenue',
                'barangay_id' => $address->barangay_id,
            ],
            'lines' => $business->lines->map(fn ($l) => [
                'psic_code_id' => $l->psic_code_id,
                'capitalization' => $l->capitalization,
            ])->all(),
        ])->assertOk();

    expect(Business::findOrFail($businessId)->address->line1)->toBe('99 Moved Avenue');
});

it('asks for the documents each box asks for, and no others', function () {
    /*
     * FO-003 prints a requirements list PER BOX, and the register now says the
     * same. These are the four rules that were wrong when measured against the
     * paper — see the migration's note for the two the paper asks for that
     * BizTrack deliberately does not.
     */
    $business = PermitType::where('code', PermitType::OUTCOME_CODE)->firstOrFail();
    $rules = $business->documentTypes()->get()->keyBy('code');

    // "Contract of Lease AND/OR Proof of Ownership" — decided by tenure, so
    // the two rows cannot share one token or a renting shop is told to
    // produce a land title.
    expect($rules['LEASE_CONTRACT']->pivot->context)->toContain('amend_address_rented')
        ->and($rules['LAND_TITLE']->pivot->context)->toContain('amend_address_owned');

    // "For Single Proprietor – DTI Registration", not for everybody.
    expect($rules['DTI_SEC_CDA']->pivot->context)->toContain('amend_sole')
        ->and($rules['DTI_SEC_CDA']->pivot->context)->not->toContain('amend_trade_name');

    // II's "Deed of Transfer", which had no document type at all before.
    expect($rules)->toHaveKey('AMEND_DEED_TRANSFER')
        ->and($rules['AMEND_DEED_TRANSFER']->pivot->context)->toBe('amend_owner')
        ->and((bool) $rules['AMEND_DEED_TRANSFER']->pivot->is_mandatory)->toBeTrue();

    // "SPA/Authorization … IF representative processes application" and
    // "Other documents that may be required" are conditions, never demands.
    expect((bool) $rules['SPA_AUTHORIZATION']->pivot->is_mandatory)->toBeFalse()
        ->and((bool) $rules['OTHER']->pivot->is_mandatory)->toBeFalse();
});
it('carries what a filing declared onto the business, and lets an amendment override it', function () {
    /*
     * ── "Is it human error or system error?" ─────────────────────────────
     *
     * System. Floor area and headcount are asked on the Fee Profile step and
     * stored per filing in `applications.fee_profile`; the matching columns on
     * `businesses` existed and had never been written by anything in the API,
     * the seeders, the factories or the tests. So the amendment form read
     * "Currently: not recorded" against a business whose own application had
     * declared a floor area of 100, and approving one wrote to a column
     * nothing reads.
     *
     * Client's decision, 21 September 2026: make the business record the live
     * copy, written at EVERY approval — which answers the original objection
     * that a copy would otherwise carry the first year's figure forever.
     *
     * Driven through a real approval rather than by reaching for the private
     * method: what matters is that an approval carries the figures, not that a
     * function can be called.
     */
    [$appId, $businessId] = amendmentFiling(['business_area_sqm' => '250']);

    // Dead until something carries them, which is the state the client saw.
    expect(Business::findOrFail($businessId)->total_employees)->toBeNull();

    Application::findOrFail($appId)->update([
        'fee_profile' => [
            'floor_area_sqm' => 100,
            'employees' => 4,
            'male_employees' => 2,
            'female_employees' => 2,
            'employees_in_lgu' => 3,
            'delivery_vehicles_motorized' => 1,
            'delivery_vehicles_other' => 2,
        ],
    ]);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);

    $business = Business::findOrFail($businessId);

    expect($business->total_employees)->toBe(4)
        ->and($business->male_employees)->toBe(2)
        ->and($business->female_employees)->toBe(2)
        ->and($business->employees_within_lgu)->toBe(3)
        // One column, two paper counts — "delivery vehicles" means all of them.
        ->and($business->delivery_units)->toBe(3);

    /*
     * And the ORDER holds: the carried figure said 100, the applicant asked
     * for 250, and the request wins. Reversed, an amendment of the floor area
     * would be silently overwritten by the figures it was filed to correct —
     * which is the whole reason the two calls are sequenced rather than
     * merged.
     */
    expect((float) $business->business_area_sqm)->toBe(250.0);
});

it('does not blank a figure a later filing simply left out', function () {
    // A renewal that leaves the delivery-vehicle box empty is not declaring
    // zero vans, and clearing the register on its say-so loses a figure
    // nobody asked to lose.
    [$firstId, $businessId] = amendmentFiling(['trade_name' => 'First Sign']);

    Application::findOrFail($firstId)->update([
        'fee_profile' => ['employees' => 9, 'delivery_vehicles_motorized' => 2],
    ]);
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$firstId}/submit")->assertOk();
    bploApprovesForm($firstId);

    expect(Business::findOrFail($businessId)->total_employees)->toBe(9);

    // A second filing on the same business, declaring only the floor area.
    $owner = authAs('owner@biztrack.local');
    $prior = Permit::where('business_id', $businessId)->latest('id')->firstOrFail();

    $secondId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'amendment',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
        'prior_permit_id' => $prior->id,
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$secondId}/amendments", [
        'changes' => [['field' => 'trade_name', 'new_value' => 'Second Sign']],
    ])->assertOk();

    Application::findOrFail($secondId)->update(['fee_profile' => ['floor_area_sqm' => 55]]);

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$secondId}/submit")->assertOk();
    bploApprovesForm($secondId);

    $business = Business::findOrFail($businessId);

    expect((float) $business->business_area_sqm)->toBe(55.0)
        // Untouched by a filing that never mentioned them.
        ->and($business->total_employees)->toBe(9)
        ->and($business->delivery_units)->toBe(2);
});
it('serves the field list as reference data, without a business', function () {
    /*
     * ── Why the definitions travel separately ────────────────────────────
     *
     * The four boxes are the same for every business in the city, so they go
     * out with the barangays and the PSIC codes and are in hand before the
     * wizard paints. Only the register's values and what a draft has asked
     * for need an application — and on a first visit, a draft that does not
     * exist yet and has to be POSTed first.
     *
     * Sent together, the step could draw nothing until two round trips had
     * finished, and a slow answer was indistinguishable from a broken form.
     * Client, 21 September 2026: *"Why it still loads? Can't you make it
     * appear instantly, just like in the other forms?"*
     */
    $rows = collect(
        test()->withHeaders(authAs('owner@biztrack.local'))
            ->getJson('/api/v1/reference/amendable-fields')->assertOk()->json('data')
    );

    expect($rows)->toHaveCount(count(AmendableFields::keys()));

    // The screen's order, and each box's own numeral off the paper.
    expect($rows->pluck('group')->unique()->values()->all())
        ->toBe(['address', 'ownership', 'trade_name', 'other']);
    expect($rows->firstWhere('group', 'address')['group_paper'])->toBe('I');

    // Enough to draw the control before any value exists for it.
    $trade = $rows->firstWhere('field', 'line_of_business');
    expect($trade['type'])->toBe('psic')
        ->and($trade['label'])->toBe('Change of line of business');

    /*
     * And NOTHING about how a change is applied. `writes`, `column`, `cast`
     * and `validation` are the server's business; a client that could read
     * them is a client that could be tempted to act on them.
     */
    foreach (['writes', 'column', 'cast', 'validation'] as $secret) {
        expect($rows->first())->not->toHaveKey($secret);
    }
});
