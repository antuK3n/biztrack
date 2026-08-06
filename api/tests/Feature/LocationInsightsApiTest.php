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
        ->and($body['data']['similar']['reason'])->toBe('line_not_chosen')
        ->and($body['data']['similar']['count'])->toBeNull()
        ->and($body['data']['similar']['average_distance_m'])->toBeNull()
        ->and($body['data']['concentration']['count'])->toBe(1)
        ->and($body['data']['common_type']['available'])->toBeTrue();
});

/*
 * Checklist item 68 — "Location Insights does not work properly."
 *
 * Both of the next two answer `available: false`, and until now they said so
 * with the same silence, so the panel had to guess and guessed the same message
 * for both: "choose your Line of Business first". For an applicant who picked
 * "Other (not listed)" that is simply false — they did choose, and choosing
 * again cannot help, because 00000 classifies nothing and so has no related
 * trade to count. Being sent back for work already done is what a screen looks
 * like when it does not work properly.
 *
 * `reason` is what lets the two be told apart. Checklist item 67 lets an
 * applicant type their own trade under Other, so the second case stops being
 * rare.
 */
it('says the line was never chosen, not that it was unclassifiable', function () {
    businessAt(0.001, 0, '56301');

    expect(insightsFor()['data']['similar']['reason'])->toBe('line_not_chosen');
});

it('says the chosen line is unclassifiable rather than asking for it again', function () {
    businessAt(0.001, 0, '56301');

    $body = insightsFor(['psic_code_id' => PsicCode::where('code', '00000')->value('id')]);

    expect($body['data']['similar']['available'])->toBeFalse()
        ->and($body['data']['similar']['reason'])->toBe('line_unclassified')
        // The catch-all is a line, so it comes back on the payload; what it has
        // no answer for is the comparison, not the question.
        ->and($body['data']['similar']['psic_title'])->not->toBeNull()
        // The two figures that never needed a category still answer.
        ->and($body['data']['concentration']['count'])->toBe(1)
        ->and($body['data']['common_type']['available'])->toBeTrue();
});

it('carries no reason when the similar figures are real', function () {
    businessAt(0.001, 0, '56301');

    $body = insightsFor(['psic_code_id' => PsicCode::where('code', '56301')->value('id')]);

    expect($body['data']['similar']['available'])->toBeTrue()
        ->and($body['data']['similar']['reason'])->toBeNull()
        ->and($body['data']['similar']['count'])->toBe(1);
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

/*
 * ── The two rows a client compared, and how the mismatch was answered ───────
 *
 * Reported: "I set the line of business to 'Manufacture of dairy products' and
 * Most common line of business is 'Manufacturing' (it did not specify which
 * manufacturing) but the count of Similar businesses within 500 m did not
 * increase."
 *
 * The 0 was right and stays 0 here. What was wrong was that "Similar" counts the
 * 3-digit PSIC trade group while "Most common" names the mode of the 2-digit
 * category, and the panel showed them as adjacent rows of one table with nothing
 * saying they answer different questions. The reader did the arithmetic anyone
 * would do and the screen gave them no way to see why it did not hold.
 *
 * The first answer was a third figure, `your_line` — the applicant's own
 * division and how many neighbours were in it — which made the two rows resolve
 * into one ordinary sentence. **The client then decided against it and asked for
 * it removed**, in favour of the row titles carrying the distinction ("Nearby
 * Similar Businesses" against "Most Common Line of Business"). That is a
 * legitimate way to draw it and their call to make.
 *
 * So these tests now pin two things: the counts that were always correct, and
 * the absence of the third figure — because a payload key removed on a client
 * decision is exactly the kind of thing a later reader restores as a "fix".
 */
/** The client's neighbourhood, rebuilt: six manufacturers, none of them dairy. */
function dairyNeighbourhood(): void
{
    businessAt(0.001, 0, '31001');     // furniture
    businessAt(0.002, 0, '31001');     // furniture
    businessAt(0.003, 0, '23950');     // concrete / hollow blocks
    businessAt(0.001, 0.001, '22200'); // plastics
    businessAt(0.002, 0.001, '22200'); // plastics
    businessAt(0.003, 0.001, '22200'); // plastics
    businessAt(0.001, 0.002, '10711'); // bakeshop — division 10, same as dairy
    businessAt(0.002, 0.002, '10711'); // bakeshop
}

describe('the dairy applicant who read a correct 0 as a bug', function () {
    it('keeps reporting zero similar businesses, because zero is the true count', function () {
        /*
         * The one assertion in this file that must never be "fixed". Group 105
         * (dairy) has exactly one code in the whole reference table, and no
         * neighbour here carries it. Widening the match to make this number
         * agree with the row below would put a bakeshop in the same bucket as a
         * dairy plant — the exact confusion PsicTaxonomy exists to prevent.
         */
        dairyNeighbourhood();

        $body = insightsFor(['psic_code_id' => PsicCode::where('code', '10500')->value('id')]);

        expect($body['data']['concentration']['count'])->toBe(8)
            ->and($body['data']['similar']['available'])->toBeTrue()
            ->and($body['data']['similar']['psic_group'])->toBe('105')
            ->and($body['data']['similar']['count'])->toBe(0)
            ->and($body['data']['similar']['average_distance_m'])->toBeNull();
    });

    it('names a trade in the mode instead of the residual word Manufacturing', function () {
        /*
         * This used to answer "Manufacturing, 6 of 8" — sixteen unrelated
         * divisions collapsed into one word that reads as a superset of the
         * applicant's own trade while being a sibling bucket that excludes it.
         *
         * The mode now falls to the three plastics firms, which is both a
         * smaller number and a true statement about the block. A count that
         * dropped from 6 to 3 is the taxonomy fix working: the 6 were never one
         * kind of business.
         */
        dairyNeighbourhood();

        $body = insightsFor(['psic_code_id' => PsicCode::where('code', '10500')->value('id')]);

        expect($body['data']['common_type']['available'])->toBeTrue()
            ->and($body['data']['common_type']['category'])->toBe('Rubber & Plastics')
            ->and($body['data']['common_type']['count'])->toBe(3)
            ->and($body['data']['common_type']['of_total'])->toBe(8);
    });

    it('sends four figures and no fifth: the applicant own category stays off the payload', function () {
        /*
         * The regression guard on a removal.
         *
         * `your_line` — the applicant's own 2-digit division and the count of
         * neighbours in it — was on this payload for exactly the case this
         * describe block is built from. Here it would have reported "Food &
         * Beverage Manufacturing, 2" (the two bakeshops), a different category
         * from the mode, and the dairy applicant's two rows would have stopped
         * looking like a contradiction.
         *
         * The client decided against it. The width distinction is carried by the
         * row titles now, so the fifth figure is gone from the response as well
         * as from the screen — a payload key nothing renders is dead weight that
         * the next reader has to work out the status of.
         *
         * This asserts the exact key set rather than just `not->toHaveKey`, so a
         * SIXTH key cannot be added without someone reading this comment and the
         * decision behind it.
         */
        dairyNeighbourhood();

        $body = insightsFor(['psic_code_id' => PsicCode::where('code', '10500')->value('id')]);

        expect(array_keys($body['data']))
            ->toBe(['radius_m', 'concentration', 'similar', 'common_type']);
    });

    it('still separates the catch-all Other from a line never chosen', function () {
        /*
         * 00000 means "I could not find my trade in the list". It is a CHOICE,
         * and the panel must not answer it by telling the applicant to choose —
         * that is how this figure earned "Location Insights does not work
         * properly". `reason` is what lets the panel tell the two apart, so both
         * values are pinned here.
         *
         * This test used to also assert `your_line` was withheld for the
         * catch-all, on the same reasoning: counting the block's other
         * unclassifiable businesses as the applicant's own kind would build a
         * neighbourhood out of missing data. That figure is gone; the `similar`
         * half of the case is not, and is what actually reaches the screen.
         */
        dairyNeighbourhood();

        $body = insightsFor(['psic_code_id' => PsicCode::where('code', '00000')->value('id')]);

        expect($body['data']['similar']['available'])->toBeFalse()
            ->and($body['data']['similar']['reason'])->toBe('line_unclassified');

        // The zoning map is pinned BEFORE the Line of Business picker, so an
        // unanswered line is the normal state of a new filing, not an error.
        $unchosen = insightsFor();

        expect($unchosen['data']['similar']['available'])->toBeFalse()
            ->and($unchosen['data']['similar']['reason'])->toBe('line_not_chosen');
    });

    it('still reports the group it matched on, and the sub-class that produced it', function () {
        /*
         * These two say WHICH set the count above was taken over, and they are
         * the only place that is recorded. `psic_group` is the 3-digit trade
         * group the match ran on; `psic_title` is the applicant's own 5-digit
         * sub-class, which is narrower — 21 of the 135 reference codes sit in a
         * group with siblings, so the two routinely differ and conflating them
         * is what made an earlier version of the panel's note wrong.
         *
         * Neither is rendered at the moment: the client fixed the row's
         * description as "Similar businesses within {radius}". They stay
         * asserted because they are still on the wire and they are what makes a
         * disputed count auditable — an applicant asking "similar to WHAT?" is
         * answered from `psic_group`, and nothing else in the response can
         * answer it. There is no honest way to print the group's own name:
         * psic_codes holds id, code and title only, and no group title exists
         * anywhere in the register.
         */
        dairyNeighbourhood();

        $body = insightsFor(['psic_code_id' => PsicCode::where('code', '10500')->value('id')]);

        expect($body['data']['similar']['psic_group'])->toBe('105')
            ->and($body['data']['similar']['psic_title'])->toBe('Manufacture of dairy products');
    });
});
