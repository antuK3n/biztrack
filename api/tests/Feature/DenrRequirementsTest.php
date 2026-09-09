<?php

use App\Support\DenrRequirements;
use Illuminate\Support\Facades\DB;

/*
 * MCG-CENRO-FO-001 v2.0's "REQUIRED DENR PERMITS FOR APPLICATION", as a rule
 * the applicant is told rather than a checklist an officer ticks.
 *
 * The client (2026-09-08): "our system will tell the applicant (in the CENRO
 * application form itself) the required DENR permits he/she needs to submit.
 * Just list them somewhere visible."
 *
 * Two things are worth a test and both are about DRIFT rather than about the
 * table being typed in correctly today.
 */

it('places every one of its categories on a CENRO fee rule too', function () {
    /*
     * The permit list and the fee are two answers read off ONE row of one
     * table, and they must not be able to disagree about which row an applicant
     * is on. The fee side is seeded (`env.*` rules, `conditions.
     * business_category`); the permit side is a PHP constant. Nothing but this
     * holds them together.
     *
     * A category here with no fee rule behind it means the paper's row exists
     * in one half of the system and not the other — the applicant would be told
     * they need a Hazardous Waste Permit and charged as though CENRO had never
     * heard of them.
     */
    $seeded = DB::table('fee_rules')
        ->where('code', 'like', 'env.%')
        ->pluck('conditions')
        ->flatMap(fn ($json) => (array) (json_decode((string) $json, true)['business_category'] ?? []))
        ->unique()
        ->all();

    expect($seeded)->not->toBeEmpty('no CENRO environmental fee rules are seeded, so this test proves nothing');

    foreach (DenrRequirements::knownCategories() as $category) {
        expect(in_array($category, $seeded, true))->toBeTrue(
            "DenrRequirements places '{$category}' on a paper row, but no seeded env.* fee rule "
            .'matches that category — the DENR list and the CENRO fee would be reading different tables.'
        );
    }
});

it('answers the paper’s row for a declared category', function () {
    // Row 26, and the low-risk end of the table: a CNC, one permit, no officer.
    $carWash = DenrRequirements::forCategories(['car_wash']);
    expect($carWash)->not->toBeNull()
        ->and($carWash['certificate'])->toBe('CNC')
        ->and($carWash['permits'])->toBe(['WDP'])
        ->and($carWash['pco'])->toBeFalse();

    // Row 1, the other end: an ECC, three permits and a Pollution Control Officer.
    $fuel = DenrRequirements::forCategories(['fuel_depot']);
    expect($fuel['certificate'])->toBe('ECC')
        ->and($fuel['permits'])->toBe(['WDP', 'HWP', 'PTO'])
        ->and($fuel['pco'])->toBeTrue();

    // Row 17 is four seeded categories on one paper row; any of them lands there.
    expect(DenrRequirements::forCategories(['furniture_sash_fabricator'])['permits'])
        ->toBe(['WDP', 'HWP']);

    // An alias: the picker says "Private hospital", the paper says row 4.
    expect(DenrRequirements::forCategories(['private_hospital'])['certificate'])->toBe('ECC');
});

it('refuses to guess when the declared category is not one of the paper’s', function () {
    /*
     * The heart of it. The category picker offers the Revenue Code's
     * business-tax vocabulary and CENRO's table uses its own, so plenty of real
     * filings match no row — and `manufacturer` is the case that must never be
     * resolved by guessing: the paper splits manufacturing into "All Big Scale"
     * (ECC, three permits, an officer) and "Small-Scale" (a CNC and one permit),
     * and nothing on a filing says which.
     *
     * Guessing big hands a backyard workshop permits it does not need. Guessing
     * small tells a factory it needs one permit when it needs four. Null is the
     * answer, and the sheet says CENRO will decide.
     */
    expect(DenrRequirements::forCategories(['manufacturer']))->toBeNull()
        ->and(DenrRequirements::forCategories(['retailer']))->toBeNull()
        ->and(DenrRequirements::forCategories([]))->toBeNull()
        ->and(DenrRequirements::forCategories(['']))->toBeNull();
});

it('takes the most consequential row when a filing declares several trades', function () {
    /*
     * A filing can declare several lines of business. The paper's order runs
     * from the most environmentally consequential downwards, so the first match
     * in that order is the answer that keeps the applicant legal: a business
     * that both stores fuel and retails LPG is told about the fuel depot.
     */
    $both = DenrRequirements::forCategories(['lpg_retailer', 'fuel_depot']);
    expect($both['certificate'])->toBe('ECC')
        ->and($both['permits'])->toBe(['WDP', 'HWP', 'PTO']);
});

it('reads the PSIC line of business when the declared category says nothing', function () {
    /*
     * The client's objection, in their words: "Don't you have sufficient data to
     * tell which DENR permit the applicant needs based on the line of business
     * he/she submitted in the BPLO application form?"
     *
     * They were right. The first cut read the fee profile's free-typed CATEGORY
     * and nothing else, so most filings fell through — while the register held a
     * PSIC code the applicant had picked from a list of 135. Both are consulted
     * now, and the PSIC code is often the better witness.
     */
    $filling = DenrRequirements::resolve([], ['47300']); // Retail sale of automotive fuel
    expect($filling['reason'])->toBe('')
        ->and($filling['row']['certificate'])->toBe('ECC')
        ->and($filling['row']['permits'])->toBe(['WDP', 'HWP', 'PTO']);

    $laundry = DenrRequirements::resolve([], ['96200']);
    expect($laundry['row']['permits'])->toBe(['WDP', 'HWP']);

    // The declared category still wins when it says something: it is the more
    // specific claim, and it is what the CENRO fee was priced from.
    $both = DenrRequirements::resolve(['junkshop'], ['47300']);
    expect($both['row']['permits'])->toBe(['HWP']);
});

it('falls back to the paper’s own catch-all for a trade CENRO does not list', function () {
    /*
     * A sari-sari store is not on CENRO's table, and that is not a gap — row 28
     * is "such other activities and projects as may be determined by CENRO
     * officers", which is exactly where the table puts it. Answering with row 28
     * is following the form; answering "we cannot say" was not.
     */
    $sariSari = DenrRequirements::resolve([], ['47111']);
    expect($sariSari['reason'])->toBe('catch_all')
        ->and($sariSari['row'])->not->toBeNull()
        ->and($sariSari['row']['certificate'])->toBe('CNC')
        ->and($sariSari['row']['permits'])->toBe([]);
});

it('still refuses to guess the SCALE of a manufacturer', function () {
    /*
     * The one case that must never reach the catch-all. Row 28 says a CNC and no
     * DENR permits; a big-scale factory needs an ECC, three permits and a
     * Pollution Control Officer. Telling it otherwise is the single wrong answer
     * here with consequences, so manufacturing whose scale is undeclared gets no
     * row at all and the sheet explains why.
     */
    $bakery = DenrRequirements::resolve([], ['10711']); // Manufacture of bakery products
    expect($bakery['reason'])->toBe('scale')
        ->and($bakery['row'])->toBeNull();

    // But manufacturing the paper DOES place by trade is placed.
    expect(DenrRequirements::resolve([], ['14100'])['row']['label'])
        ->toContain('Haberdashery');
    expect(DenrRequirements::resolve([], ['31001'])['row']['permits'])
        ->toBe(['WDP', 'HWP']);
});

it('maps every PSIC code onto a category the table actually has', function () {
    // A typo in the PSIC map would silently produce the catch-all instead of the
    // row it was written for — a wrong answer that looks like a working one.
    $known = DenrRequirements::knownCategories();
    foreach (DenrRequirements::psicMappedCategories() as $category) {
        expect(in_array($category, $known, true))->toBeTrue(
            "The PSIC map points at '{$category}', which is not a category on any row of the table."
        );
    }
});
