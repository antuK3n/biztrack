<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\BusinessAddress;
use App\Models\BusinessLine;
use App\Models\PsicCode;
use App\Models\User;

/*
 * Business Location Insights (spec §5) — the endpoint the apply wizard's zoning
 * step calls for the point the applicant just pinned.
 *
 * Every test works on an empty patch of ocean far from Malabon so the demo
 * seeder's businesses cannot drift into the 500 m radius and make the expected
 * counts depend on unrelated seed changes.
 */

/** A point with nothing seeded anywhere near it. */
const TEST_LAT = 10.500000;

const TEST_LNG = 123.500000;

/**
 * A registered business at an offset from the test point, in the given trade.
 *
 * `submitted` mirrors what LocationInsights counts as registered: a business
 * whose application has left `draft`. A draft-only business exists in the table
 * but is not in the register.
 */
function businessAt(float $latOffset, float $lngOffset, string $psicCode, bool $submitted = true): Business
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $business = Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'Neighbour '.fake()->unique()->numerify('####'),
        'registration_type' => 'DTI',
        'status' => 'active',
        'is_active' => true,
    ]);

    BusinessAddress::create([
        'business_id' => $business->id,
        'line1' => '1 Test St.',
        'barangay_id' => Barangay::first()->id,
        'latitude' => TEST_LAT + $latOffset,
        'longitude' => TEST_LNG + $lngOffset,
    ]);

    BusinessLine::create([
        'business_id' => $business->id,
        'psic_code_id' => PsicCode::where('code', $psicCode)->value('id'),
    ]);

    Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => $submitted ? 'approved' : 'draft',
        'tracking_number' => 'TEST-'.fake()->unique()->numerify('######'),
    ]);

    return $business;
}

function insightsFor(array $params = []): array
{
    return test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/location-insights?'.http_build_query([
            'latitude' => TEST_LAT,
            'longitude' => TEST_LNG,
            ...$params,
        ]))
        ->assertOk()
        ->json();
}

it('requires authentication', function () {
    $this->getJson('/api/v1/location-insights?latitude=10.5&longitude=123.5')
        ->assertUnauthorized();
});

it('refuses a caller who cannot file an application', function () {
    // Officers review filings; they do not get the applicant's decision support.
    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/location-insights?latitude=10.5&longitude=123.5')
        ->assertForbidden();
});

it('validates the pinned point', function () {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/location-insights')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['latitude', 'longitude']);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/location-insights?latitude=99&longitude=200')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['latitude', 'longitude']);
});

it('reports an empty neighbourhood honestly rather than as zeroes', function () {
    $body = insightsFor(['psic_code_id' => PsicCode::where('code', '56301')->value('id')]);

    expect($body['data']['radius_m'])->toBe(500)
        ->and($body['data']['concentration']['count'])->toBe(0)
        ->and($body['data']['concentration']['band'])->toBe('low')
        // No businesses means no mode. "Other" would be a fabricated answer.
        ->and($body['data']['common_type']['available'])->toBeFalse()
        // Zero similar businesses is a real count; their mean distance is not.
        ->and($body['data']['similar']['count'])->toBe(0)
        ->and($body['data']['similar']['average_distance_m'])->toBeNull();
});

it('counts registered businesses inside the radius and excludes those outside', function () {
    // ~111 m and ~222 m north: inside. ~1.1 km north: outside.
    businessAt(0.001, 0, '56301');
    businessAt(0.002, 0, '56301');
    businessAt(0.010, 0, '56301');

    $body = insightsFor();

    expect($body['data']['concentration']['count'])->toBe(2);
});

it('ignores draft-only businesses, which are not in the register', function () {
    businessAt(0.001, 0, '56301');
    businessAt(0.001, 0, '56301', submitted: false);

    expect(insightsFor()['data']['concentration']['count'])->toBe(1);
});

it('bands concentration on the spec boundaries', function () {
    foreach (range(1, 5) as $i) {
        businessAt(0.0001 * $i, 0, '47111');
    }
    expect(insightsFor()['data']['concentration']['band'])->toBe('low');

    businessAt(0.0007, 0, '47111');
    expect(insightsFor()['data']['concentration']['band'])->toBe('medium');

    foreach (range(8, 12) as $i) {
        businessAt(0.0001 * $i, 0, '47111');
    }
    expect(insightsFor()['data']['concentration']['band'])->toBe('high');
});

it('counts only the applicant own PSIC group as similar', function () {
    // Three coffee shops (563) and two restaurants (561) in range.
    businessAt(0.001, 0, '56301');
    businessAt(0.002, 0, '56302');
    businessAt(0.003, 0, '56301');
    businessAt(0.001, 0.001, '56101');
    businessAt(0.002, 0.001, '56103');

    $body = insightsFor(['psic_code_id' => PsicCode::where('code', '56301')->value('id')]);

    expect($body['data']['concentration']['count'])->toBe(5)
        ->and($body['data']['similar']['available'])->toBeTrue()
        ->and($body['data']['similar']['psic_group'])->toBe('563')
        ->and($body['data']['similar']['count'])->toBe(3)
        ->and($body['data']['similar']['psic_title'])->toContain('coffee shop');
});

it('averages the distance to similar businesses only', function () {
    // 0.001 deg lat ~111.2 m, so 1x + 3x averages to ~222 m.
    businessAt(0.001, 0, '56301');
    businessAt(0.003, 0, '56301');
    // A restaurant 444 m away must not drag the café average.
    businessAt(0.004, 0, '56101');

    $body = insightsFor(['psic_code_id' => PsicCode::where('code', '56301')->value('id')]);

    expect($body['data']['similar']['average_distance_m'])->toBeGreaterThan(218)
        ->and($body['data']['similar']['average_distance_m'])->toBeLessThan(226);
});

it('reports the similar figures as unavailable when no line of business is named', function () {
    /*
     * The zoning step is Part 1 and Line of Business comes later, so this is the
     * normal state of a new filing — not an error. The two category-dependent
     * figures say so; the two that do not need a category still answer.
     */
    businessAt(0.001, 0, '56301');

    $body = insightsFor();

    expect($body['data']['similar']['available'])->toBeFalse()
        ->and($body['data']['similar']['count'])->toBeNull()
        ->and($body['data']['similar']['average_distance_m'])->toBeNull()
        ->and($body['data']['concentration']['count'])->toBe(1)
        ->and($body['data']['common_type']['available'])->toBeTrue();
});

it('reports the mode of nearby categories', function () {
    businessAt(0.001, 0, '47111');
    businessAt(0.002, 0, '47521');
    businessAt(0.003, 0, '47721');
    businessAt(0.001, 0.001, '56301');

    $body = insightsFor();

    expect($body['data']['common_type']['available'])->toBeTrue()
        ->and($body['data']['common_type']['category'])->toBe('Retail Trade')
        ->and($body['data']['common_type']['count'])->toBe(3)
        ->and($body['data']['common_type']['of_total'])->toBe(4);
});

it('breaks a tie the same way every time', function () {
    businessAt(0.001, 0, '47111');
    businessAt(0.002, 0, '56301');

    // Alphabetical on the category name, so a reload never flips the answer.
    $first = insightsFor()['data']['common_type']['category'];
    $second = insightsFor()['data']['common_type']['category'];

    expect($first)->toBe('Foods & Beverages')->and($second)->toBe($first);
});

it('leaves the applicant out of their own neighbourhood', function () {
    $own = businessAt(0.001, 0, '56301');
    businessAt(0.002, 0, '56301');

    $body = insightsFor(['business_id' => $own->id]);

    expect($body['data']['concentration']['count'])->toBe(1);
});

it('ignores an exclusion the caller does not own', function () {
    /*
     * Otherwise the count becomes an oracle: diff the total with and without an
     * id and you learn whether that business sits on this block.
     */
    $someoneElse = businessAt(0.001, 0, '56301');
    $someoneElse->update([
        'owner_user_id' => User::where('email', '!=', 'owner@biztrack.local')->value('id'),
    ]);

    $body = insightsFor(['business_id' => $someoneElse->id]);

    expect($body['data']['concentration']['count'])->toBe(1);
});

it('says the figures were computed locally, right now', function () {
    /*
     * The rest of the analytics suite reads batch snapshots and shows when R
     * computed them. This one cannot: the key is a point the applicant picked
     * seconds ago. The meta block says PHP so no screen can claim otherwise.
     */
    $body = insightsFor();

    expect($body['meta']['source'])->toBe('local')
        ->and($body['meta']['engine'])->toBe('PHP')
        ->and($body['meta']['computed_at'])->not->toBeNull();
});

it('never leaks a neighbouring business identity', function () {
    $neighbour = businessAt(0.001, 0, '56301');

    $raw = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/location-insights?latitude='.TEST_LAT.'&longitude='.TEST_LNG)
        ->getContent();

    expect($raw)->not->toContain($neighbour->name)
        ->and($raw)->not->toContain('1 Test St.')
        ->and($raw)->not->toContain('latitude');
});
