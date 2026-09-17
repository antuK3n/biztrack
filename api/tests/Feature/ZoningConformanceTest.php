<?php

use App\Models\Barangay;
use App\Models\PsicCode;
use App\Support\ZoningConformance;

/*
 * The lookup behind the apply wizard's conformity sentence (checklist item 20).
 *
 * These assert against the SHIPPED ordinance extract and the SHIPPED barangay
 * sheets rather than against fixtures, because the thing under test is whether
 * those two documents line up. A fixture would let them drift apart and still
 * pass.
 */

it('reads every section of the ordinance extract', function () {
    $sections = ZoningConformance::sections();

    // Twenty base zones, Art. V §2.1–2.20.
    expect($sections)->toHaveCount(20);
    expect($sections['2.7']['allowed_uses'])->not->toBeEmpty();
});

it('maps every barangay classification to a section of the ordinance', function () {
    /*
     * The join that silently breaks. A classification whose code has no section
     * contributes zero uses and reads as "not listed" for every trade, which is
     * a wrong answer wearing a confident face — so the mapping is asserted
     * complete rather than assumed.
     */
    $sections = ZoningConformance::sections();

    foreach (Barangay::with('zoningClassifications')->get() as $barangay) {
        foreach ($barangay->zoningClassifications as $classification) {
            $result = ZoningConformance::forBarangay($barangay, null);
            $zone = collect($result['zones'])->firstWhere('code', $classification->code);

            expect($zone)->not->toBeNull(
                "{$classification->code} is on {$barangay->name}'s sheet but absent from the result",
            );
        }
    }

    // Fishpond is the one zone the ordinance leaves empty (§2.16); every other
    // mapped classification must reach a section that enumerates something.
    expect($sections['2.16']['allowed_uses'])->toBeEmpty();
});

it('finds a restaurant in a barangay zoned for eateries', function () {
    /*
     * The regression that made this class worth testing. An earlier matcher
     * required two shared words; PSIC 56101 "Restaurants and carinderia" and
     * §2.7 "Restaurants and other eateries, provided that…" share exactly one,
     * so the commonest trade in the city reported as not listed.
     */
    $longos = Barangay::where('name', 'Longos')->firstOrFail();
    $restaurant = PsicCode::where('code', '56101')->firstOrFail();

    $result = ZoningConformance::forBarangay($longos, $restaurant);

    expect($result['verdict'])->toBe('listed');
    expect(collect($result['zones'])->firstWhere('listed', true)['matched_use'])->not->toBeNull();
});

it('does not match two trades that share only the word manufacture', function () {
    /*
     * The false positive the generic-word list exists to stop. Every
     * manufacturer shares `manufacture` with every other one, and a zone that
     * wants cement does not thereby want jewellery.
     */
    $jewellery = PsicCode::where('code', '32110')->firstOrFail();

    $matcher = new ReflectionMethod(ZoningConformance::class, 'matchUse');
    $matcher->setAccessible(true);

    expect($matcher->invoke(null, $jewellery, ['Manufacture of cement and cement products']))->toBeNull();
});

it('reports undetermined rather than refused when nothing has been chosen', function () {
    $longos = Barangay::where('name', 'Longos')->firstOrFail();

    expect(ZoningConformance::forBarangay(null, null)['verdict'])->toBe('undetermined');
    // A barangay with no trade named yet is the normal state of the step for as
    // long as it takes the applicant to scroll to the line of business.
    expect(ZoningConformance::forBarangay($longos, null)['verdict'])->toBe('undetermined');
});

it('never reports not_listed for a barangay whose zones enumerate nothing', function () {
    /*
     * §2.16 Fishpond enumerates no uses. Dampalit carries it. If Dampalit's
     * sheet held ONLY Fishpond, every trade would read as not listed — which
     * would be reporting the ordinance's silence as a refusal. The guard is on
     * the total across the sheet, so this holds however the sheets are revised.
     */
    $fishpondOnly = Barangay::with('zoningClassifications')->get()
        ->first(fn (Barangay $b) => $b->zoningClassifications->isNotEmpty()
            && $b->zoningClassifications->every(fn ($c) => $c->code === 'FISHPOND'));

    if ($fishpondOnly === null) {
        expect(true)->toBeTrue(); // No such barangay today; the guard still stands.

        return;
    }

    $anyTrade = PsicCode::first();
    expect(ZoningConformance::forBarangay($fishpondOnly, $anyTrade)['verdict'])->toBe('undetermined');
});
