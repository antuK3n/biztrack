<?php

use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;

/*
 * The register's two new narrowings, and the public verify payload.
 *
 *  - `exclude_permit_type` — "other permits in a separate view" (checklist
 *    item 18): BPLO's own table is the Mayor's Permit, and every other
 *    office's certificates are a second view.
 *  - `retired` — businesses removed from the register (checklist item 21),
 *    hidden unless asked for.
 *  - `/verify/{number}` — what a scanned QR shows (checklist item 22).
 */

/** Register rows as BPLO reads them, every page's worth for these small fixtures. */
function viewRows(array $query = [], string $as = 'bplo@biztrack.local'): array
{
    $qs = http_build_query(array_merge(['detail' => 1, 'per_page' => 100], $query));

    return test()->withHeaders(authAs($as))
        ->getJson('/api/v1/permits?'.$qs)
        ->assertOk()
        ->json('data');
}

/**
 * A certificate of one type on an existing business and filing. The seeded
 * register is small and mostly Mayor's Permits, so the tests that need a mix
 * of types or a second business mint what they need.
 */
function viewPermit(string $code, ?Business $business = null): Permit
{
    $business ??= Business::firstOrFail();

    return Permit::create([
        'permit_number' => 'VW-'.random_int(100000, 999999),
        'application_id' => Application::firstOrFail()->id,
        'business_id' => $business->id,
        'permit_type_id' => PermitType::where('code', $code)->value('id'),
        'status' => PermitStatus::Active,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now(),
    ]);
}

it('lists every office but the one it is told to leave out', function () {
    viewPermit('SANITARY');
    viewPermit('FSIC');
    viewPermit('BUSINESS');

    $rows = viewRows(['exclude_permit_type' => 'BUSINESS']);

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row['permit_type']['code'])->not->toBe('BUSINESS');
    }

    // And it is a narrowing, not a different register: the two halves add up.
    $all = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/permits?per_page=1')->json('meta.total');
    $business = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/permits?per_page=1&permit_type=BUSINESS')->json('meta.total');
    $others = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/permits?per_page=1&exclude_permit_type=BUSINESS')->json('meta.total');

    expect($business + $others)->toBe($all);
});

it('refuses to leave out an office that is not a permit type', function () {
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/permits?exclude_permit_type=MARKET')
        ->assertStatus(422);
});

it('will not let leaving an office out widen what a clearance office sees', function () {
    // The fire office excluding BUSINESS must still see FSICs only.
    foreach (viewRows(['exclude_permit_type' => 'BUSINESS'], 'fire@biztrack.local') as $row) {
        expect($row['permit_type']['code'])->toBe('FSIC');
    }
});

it('hides a retired business’s permits when asked, and lists only those when asked', function () {
    // Two businesses, so retiring one leaves the other's permits to list.
    viewPermit('BUSINESS', Business::firstOrFail());
    $retiring = Business::query()->skip(1)->firstOrFail();
    $permit = viewPermit('SANITARY', $retiring);
    $retiring->delete();   // soft delete: the business is retired

    $hidden = collect(viewRows(['retired' => 'hide']))->pluck('id');
    expect($hidden)->not->toContain($permit->id);

    $only = collect(viewRows(['retired' => 'only']));
    expect($only->pluck('id'))->toContain($permit->id);
    foreach ($only as $row) {
        expect($row['business_retired'])->toBeTrue();
    }

    /*
     * And the row NAMES the retired business rather than printing "removed
     * from register" — a reader who asked for retired businesses wants to know
     * which ones they are.
     */
    $row = $only->firstWhere('id', $permit->id);
    expect($row['business']['name'])->toBe(Business::withTrashed()->find($permit->business_id)->name);

    $included = collect(viewRows(['retired' => 'include']));
    expect($included->pluck('id'))->toContain($permit->id)
        ->and($included->where('business_retired', false))->not->toBeEmpty();
});

it('refuses a retired filter it does not know', function () {
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/permits?retired=sometimes')
        ->assertStatus(422);
});

it('verifies with the business and trade name, the address and the permit type — and no owner', function () {
    $permit = Permit::with('business.owner')
        ->whereHas('business', fn ($b) => $b->whereNotNull('owner_user_id'))
        ->where('status', PermitStatus::Active)
        ->whereDate('valid_until', '>=', now())
        ->firstOrFail();

    $permit->update(['issued_details' => [
        'business_name' => 'Snapshot Store',
        'trade_name' => 'Snapshot Trade',
        'owner_name' => 'Private Person',
        'address' => '1 Snapshot Street',
        'barangay' => 'Longos',
        'city' => 'Malabon',
        'line_of_business' => 'Retail',
    ]]);

    $response = test()->getJson("/api/v1/verify/{$permit->permit_number}")
        ->assertOk()
        ->assertJsonPath('data.is_valid', true)
        ->assertJsonPath('data.status_label', 'Active')
        // Read from what was printed on the certificate, not the register today.
        ->assertJsonPath('data.business.name', 'Snapshot Store')
        ->assertJsonPath('data.business.trade_name', 'Snapshot Trade')
        ->assertJsonPath('data.business.address.line', '1 Snapshot Street')
        ->assertJsonPath('data.business.address.barangay.name', 'Longos')
        ->assertJsonPath('data.business.address.city', 'Malabon');

    expect($response->getContent())->not->toContain('Private Person');
});

it('says Expired on a permit whose term has passed even while its status still reads active', function () {
    /*
     * biztrack:scan-permits flips a lapsed permit a day late at best, so the
     * status column alone would tell a scanner "Active" beside a date that has
     * passed.
     */
    $permit = Permit::where('status', PermitStatus::Active)->firstOrFail();
    $permit->update(['valid_until' => now()->subDays(3)->toDateString()]);

    test()->getJson("/api/v1/verify/{$permit->permit_number}")
        ->assertOk()
        ->assertJsonPath('data.is_valid', false)
        ->assertJsonPath('data.status', 'expired')
        ->assertJsonPath('data.status_label', 'Expired');
});
