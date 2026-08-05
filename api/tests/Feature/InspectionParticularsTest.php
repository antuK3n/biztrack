<?php

use App\Models\Application;
use App\Models\Business;
use App\Models\Department;
use App\Models\Inspection;

/**
 * The inspection detail has to carry the filing's own particulars.
 *
 * The client's words: "the inspection details should be the same as what was
 * submitted in the application form". Before this, an officer opening a visit
 * got a status, a date, a business name and their own name — nothing about the
 * premises they had been sent to. These tests pin the block down at the three
 * points it can go wrong: present on the detail, absent (and honestly absent)
 * everywhere it was not fetched, and survivable when the business behind it has
 * left the register.
 */
/**
 * A visit against a seeded filing that is complete enough to have particulars.
 *
 * Built rather than found: the demo seed carries applications, businesses,
 * addresses and declared lines, but no inspections at all, so there is nothing
 * to look up. Scheduling one against a real seeded filing is what makes the
 * owner / address / line-of-business assertions below mean anything — a
 * hand-built business with hand-built relations would only prove the resource
 * can read what the test just wrote.
 *
 * Booked to CHO specifically, and read below as CHO's officer. It used to be
 * `Department::firstOrFail()` — whichever office the reference seeder happened
 * to write first, which is BPLO, an office that conducts no visits at all — and
 * it was read as the super admin, who could open any of them. Neither is true
 * now: the client took Inspections off the super admin ("it is not his role to
 * do those things"), and InspectionController scopes every read to the caller's
 * own department, so the visit and the reader have to name the same office.
 */
function inspectionWithFiling(): Inspection
{
    $application = Application::whereHas('business.address')
        ->whereHas('business.lines')
        ->whereHas('permitTypes')
        ->firstOrFail();

    return Inspection::create([
        'application_id' => $application->id,
        'department_id' => Department::where('code', 'CHO')->firstOrFail()->id,
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay(),
    ]);
}

it('returns the applicant\'s submitted particulars on the inspection detail', function () {
    $inspection = inspectionWithFiling();
    $business = $inspection->application->business->load(['owner', 'address.barangay', 'lines.psicCode']);

    $res = $this->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/inspections/{$inspection->id}")
        ->assertOk();

    $p = $res->json('data.particulars');

    expect($p)->not->toBeNull();

    // The certificate's vocabulary, verbatim. If a key here drifts from
    // PermitController::certificateData, two screens are naming the same fact
    // two ways and one of them is going to be fixed without the other.
    expect(array_keys($p))->toEqualCanonicalizing([
        'application_type', 'business_name', 'trade_name', 'registration_number',
        'tin', 'owner_name', 'address', 'address_line2', 'barangay', 'city',
        'province', 'postal_code', 'line_of_business', 'permit_types',
    ]);

    expect($p['business_name'])->toBe($business->name);
    expect($p['trade_name'])->toBe($business->trade_name);
    expect($p['tin'])->toBe($business->tin);
    expect($p['application_type'])->toBe($inspection->application->application_type->value);

    // The address is the whole point of the change: an officer standing at the
    // premises could not previously read where they were.
    expect($p['address'])->toBe($business->address->line1);
    expect($p['city'])->toBe($business->address->city);
    expect($p['barangay'])->toBe($business->address->barangay?->name);

    // Owner and line of business need relations the list never loads, so these
    // two assertions are what prove show() reaches deeper than index().
    expect($p['owner_name'])->toBe($business->owner->fullName() ?: $business->owner->name);
    expect($p['line_of_business'])->not->toBeNull();

    // Which permits the visit is for. A filing can carry several.
    expect($p['permit_types'])->toHaveCount($inspection->application->permitTypes->count());
    expect(collect($p['permit_types'])->pluck('code')->all())
        ->toEqualCanonicalizing($inspection->application->permitTypes->pluck('code')->all());
});

it('leaves the particulars null on the inspection list rather than sending a block of nulls', function () {
    inspectionWithFiling();

    $res = $this->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/inspections')
        ->assertOk();

    // Guard the guard: an empty list would make the assertion below pass for
    // the wrong reason.
    expect($res->json('data'))->toHaveCount(1);

    // Null means "this response did not go and look", which is the truth: the
    // list eager-loads neither the owner nor the declared lines, and an object
    // of nulls here would read as "the applicant filed nothing".
    expect($res->json('data.0.particulars'))->toBeNull();
});

it('still answers when the business behind the visit has left the register', function () {
    $inspection = inspectionWithFiling();
    $businessId = $inspection->application->business_id;

    // `Business` soft-deletes and its inspections stay behind. This is the
    // shape that white-screened officer screens before, so the detail has to
    // answer 200 with honest nulls rather than 500 or a half-built block.
    Business::findOrFail($businessId)->delete();

    $res = $this->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/inspections/{$inspection->id}")
        ->assertOk();

    $p = $res->json('data.particulars');

    expect($p)->not->toBeNull();
    expect($p['business_name'])->toBeNull();
    expect($p['owner_name'])->toBeNull();
    expect($p['address'])->toBeNull();
    // The filing's own facts outlive the business record, so these still stand.
    expect($p['permit_types'])->not->toBeEmpty();
});
