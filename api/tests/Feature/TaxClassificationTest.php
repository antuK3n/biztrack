<?php

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Business;
use App\Models\FeeRule;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Services\FeeCalculator;
use App\Support\TaxClassification;

/*
 * The line of business classifies itself, and both keys reach their own group.
 *
 * ── The defect this file exists for ───────────────────────────────────────
 *
 * The wizard asked the applicant to classify their own business from a
 * type-ahead of 273 Revenue Code labels. That was mis-billing, not merely a
 * hard question: two fee groups key on two different vocabularies and the
 * screen offered one box.
 *
 *   `business_tax`   → the 22 broad classes of Sec. 2J.02
 *   `mayors_permit`  → the 117 fine categories of Sec. 3A.03
 *
 * Measured on a carinderia with ₱1,200,000 of gross sales and 45 sq. m.:
 * answering "Carinderia" billed ₱2,218.25, answering "Restaurant" billed
 * ₱11,707.00, and the correct figure needed both keys at once — which no
 * applicant could supply. The more accurate answer lost ₱9,750 of business
 * tax, 81% of the bill.
 *
 * So `psic_codes` carries both keys and nobody is asked. The tests below pin
 * the three properties that has to have: the mapping is complete and every
 * value in it is one a live rule actually matches; the derivation produces the
 * tax that used to go missing; and a filing an applicant classified themselves
 * keeps their answer.
 */

/** A realistic renewal profile for one line of business. */
function taxProfile(string $psic, array $line = []): array
{
    return [
        'business_structure' => 'sole_proprietorship',
        'lines' => [array_merge([
            'psic_code_id' => PsicCode::where('code', $psic)->value('id'),
            'gross_sales' => 1200000,
        ], $line)],
        'gross_sales' => 1200000,
        'floor_area_sqm' => 45,
        'employees' => 4,
        'flags' => [],
    ];
}

/** Assess a renewal against a profile without persisting anything. */
function assessProfile(array $profile): array
{
    $app = Application::with('permitTypes', 'business.lines')->firstOrFail();
    $clone = $app->replicate();
    $clone->id = $app->id;
    $clone->application_type = ApplicationType::Renewal;
    $clone->setRelations($app->getRelations());
    $clone->fee_profile = $profile;

    return app(FeeCalculator::class)->assess($clone);
}

/** The codes of the billed lines, for asserting what matched. */
function billedCodes(array $result): array
{
    return array_values(array_filter(array_map(fn ($i) => $i['code'] ?? null, $result['items'])));
}

it('maps every line of business, and only to classes a live rule matches', function () {
    $codes = PsicCode::pluck('code')->all();

    // Complete in both directions: an unmapped code silently falls back to
    // asking the old question, and a mapped code that no longer exists is a
    // row nobody will ever read.
    expect(array_diff($codes, array_keys(TaxClassification::FOR_PSIC)))->toBe([])
        ->and(array_diff(array_keys(TaxClassification::FOR_PSIC), $codes))->toBe([]);

    $classesFor = function (string $group) {
        $seen = [];
        foreach (FeeRule::where('group', $group)->where('active', true)->get() as $rule) {
            foreach (($rule->conditions['business_category'] ?? []) as $category) {
                $seen[$category] = true;
            }
        }

        return $seen;
    };
    $taxClasses = $classesFor('business_tax');
    $permitCategories = $classesFor('mayors_permit');

    /*
     * A class no rule keys on is worse than no class: it looks classified,
     * reads as deliberate, and bills nothing. Checked against the LIVE rules
     * rather than a hardcoded list, so that retiring a fee rule out from under
     * this mapping fails here instead of at a counter.
     */
    foreach (TaxClassification::FOR_PSIC as $code => [$class, $permit, $branch]) {
        if ($class !== null) {
            expect($taxClasses)->toHaveKey($class, "{$code} maps to an unmatched tax class {$class}");
        }
        if ($permit !== null) {
            expect($permitCategories)->toHaveKey($permit, "{$code} maps to an unmatched permit category {$permit}");
        }
        // The half-rate counterpart has to exist too, or answering Yes to the
        // essentials question would classify a business into nothing.
        if ($branch === TaxClassification::BRANCH_ESSENTIALS) {
            expect($taxClasses)->toHaveKey(TaxClassification::taxClass($code, true));
        }
    }
});

it('stores the classification against every seeded line of business', function () {
    // 134 of 135: only 00000 "Other (not listed)" has nothing to derive from,
    // because the applicant typed their own trade.
    expect(PsicCode::whereNotNull('category')->count())->toBe(134)
        ->and(PsicCode::whereNull('category')->pluck('code')->all())->toBe(['00000']);

    // The overwhelming majority ask the applicant nothing at all. If this
    // number falls, the wizard has started asking questions again.
    expect(PsicCode::whereNotNull('category')->whereNull('category_branch')->count())->toBe(117);
});

it('bills the business tax for a carinderia that classified nothing', function () {
    /*
     * The case that proved the defect. PSIC 56101 is "Restaurants and
     * carinderia" — one code the paper's own list lumps together — and under
     * the old question this filing came to ₱2,218.25 with no business tax at
     * all if its applicant answered accurately.
     */
    $result = assessProfile(taxProfile('56101'));

    expect(billedCodes($result))->toContain('biztax.restaurant');

    $tax = collect($result['items'])->firstWhere('code', 'biztax.restaurant');
    expect($tax['amount'])->toBeGreaterThan(9000.0);
});

it('halves the rate when the applicant declares essential commodities', function () {
    /*
     * Sec. 2J.02(c). A sari-sari store is the reason this question survives at
     * all: it sells rice and laundry soap beside cigarettes and soft drinks,
     * and no industrial classification can tell which dominates.
     */
    $ordinary = assessProfile(taxProfile('47111', ['essentials' => false]));
    $essential = assessProfile(taxProfile('47111', ['essentials' => true]));

    expect(billedCodes($ordinary))->toContain('biztax.retailer')
        ->and(billedCodes($essential))->toContain('biztax.essential_retailer');

    $full = collect($ordinary['items'])->firstWhere('code', 'biztax.retailer')['amount'];
    $half = collect($essential['items'])->firstWhere('code', 'biztax.essential_retailer')['amount'];

    // "Half of the Sec. 2J.02(d) rates", as the rule's own title puts it.
    expect($half)->toBe(round($full / 2, 2));
});

it('ignores the essentials answer where the trade does not branch on it', function () {
    /*
     * A jeweller has no half-rate form and 47730 is marked accordingly, so a
     * Yes here must change nothing. The question is not asked for that code —
     * this pins that the SERVER does not honour one anyway, since the browser
     * is not the only way in.
     */
    $plain = assessProfile(taxProfile('47730', ['essentials' => false]));
    $claimed = assessProfile(taxProfile('47730', ['essentials' => true]));

    expect($claimed['total'])->toBe($plain['total'])
        ->and(billedCodes($claimed))->toContain('biztax.retailer');
});

it('sends the broad class to the tax and the fine category to the permit fee', function () {
    /*
     * The two-key fix, in one assertion. A barbershop is a contractor for
     * Sec. 2J.02(e) and `barber_shop` for Sec. 3A.03, and under one field it
     * could only ever be one of them: answering "contractor" got the tax and
     * the floor-area catch-all, answering "barber_shop" got the ₱550 seat fee
     * and no tax whatsoever.
     */
    $codes = billedCodes(assessProfile(taxProfile('96110')));

    expect($codes)->toContain('biztax.contractor')
        ->and($codes)->toContain('permit.barber_shop_first_seat')
        // And the catch-all stands down once a specific permit line matched.
        ->and($codes)->not->toContain('permit.catchall_office_area');
});

it('falls back to the catch-all where the Code has no category for the trade', function () {
    /*
     * Not a gap. Sec. 3A.03 item 64 prices "all other businesses not
     * specifically mentioned" by office area, and retail stores are exactly
     * that — there is no sari-sari or grocery category to map to. 97 of the
     * 135 codes are in this position, deliberately.
     */
    $codes = billedCodes(assessProfile(taxProfile('47111')));

    expect($codes)->toContain('permit.catchall_office_area')
        ->and($codes)->toContain('biztax.retailer');
});

it('leaves a class the applicant chose for themselves alone', function () {
    /*
     * Drafts filled in while the wizard still asked. Their applicant gave an
     * answer and it is not ours to overwrite mid-filing, even though the
     * derivation would now produce a better one — which is why the fee comes
     * out at the old figure here rather than the new.
     */
    $result = assessProfile(taxProfile('56101', ['category' => 'carinderia']));
    $codes = billedCodes($result);

    expect($codes)->toContain('permit.carinderia')
        ->and($codes)->not->toContain('biztax.restaurant');
});

it('accepts a filing that sends no category at all', function () {
    $owner = authAs('owner@biztrack.local');
    $business = Business::where('owner_user_id', User::where('email', 'owner@biztrack.local')->value('id'))->firstOrFail();

    /*
     * The API REQUIRED a category on every line until 16 September 2026, which
     * would have rejected the very payload the wizard now sends. Asserted end
     * to end because a validation rule is exactly the kind of thing that keeps
     * a correct client from ever reaching the code under test.
     */
    $response = $this->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $business->id,
        'application_type' => 'renewal',
        'data_privacy_consent' => true,
        'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->value('id')],
        'fee_profile' => [
            'lines' => [[
                'psic_code_id' => PsicCode::where('code', '47111')->value('id'),
                'gross_sales' => 1200000,
                'essentials' => true,
            ]],
            'floor_area_sqm' => 45,
        ],
    ])->assertCreated();

    // Classified on the way in, so the filing records the basis it will be
    // assessed on rather than having it re-derived later.
    $stored = Application::find($response->json('data.id'))->fee_profile;
    expect($stored['lines'][0]['category'])->toBe('essential_retailer')
        ->and($stored['lines'][0]['permit_category'])->toBeNull();
});
