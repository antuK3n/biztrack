<?php

use App\Models\Barangay;
use App\Models\ZoningClassification;
use App\Models\ZoningOverlay;
use Database\Seeders\ZoningSeeder;

/*
 * The zoning reference data the apply wizard's Location & Zoning step reads.
 *
 * The load-bearing rule these tests exist to hold is the last one: the payload
 * describes a MAP, never a location. CPDO's sheets are raster images with no
 * geometry, so nothing here can answer "is this address conforming" — and the
 * moment a field appears that looks like it does, this file should go red.
 */

it('serves the 19 classifications from CPDO’s legend', function () {
    // Nineteen, because that is what the legend block prints on every sheet.
    // NLEX, RAILROAD, ROADS, WATERWAYS_2, MANILA BAY and the two boundary
    // layers also appear on the sheets and are deliberately NOT here: a railway
    // is map furniture, not a use a business can be zoned for.
    expect(ZoningClassification::count())->toBe(19);

    expect(ZoningClassification::orderBy('sort_order')->pluck('code')->all())->toBe([
        'R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'R-3-MAX', 'CMP',
        'C-1', 'C-2', 'C-3', 'CBD', 'GENERAL-COMMERCIAL',
        'I-1', 'I-2', 'INSTITUTIONAL',
        'FISHPOND', 'PARKS', 'MANGROVE', 'UTILITIES', 'CEMETERY',
    ]);
});

it('gives every one of the 21 barangays a map and at least one classification', function () {
    $barangays = Barangay::with('zoningClassifications')->get();

    expect($barangays)->toHaveCount(21);

    foreach ($barangays as $b) {
        expect($b->zoning_map_path)
            ->toStartWith('/zoning-maps/')
            ->and($b->zoning_map_path)->toEndWith('.png');

        // A barangay with an empty list would render a map and no legend, which
        // reads as "nothing is allowed here". Every sheet draws something.
        expect($b->zoningClassifications)->not->toBeEmpty();
    }
});

it('points Tañong at an ASCII map filename', function () {
    // The supplied sheet was `TAÑONG.png`. A non-ASCII character in a URL is a
    // percent-encoding argument that will be lost by somebody, some day.
    expect(Barangay::where('name', 'Tañong')->value('zoning_map_path'))
        ->toBe('/zoning-maps/tanong.png');
});

it('returns each barangay’s map and zone list on the reference endpoint', function () {
    $body = $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/reference/barangays')
        ->assertOk()
        ->json('data');

    expect($body)->toHaveCount(21);

    $acacia = collect($body)->firstWhere('name', 'Acacia');
    expect($acacia['zoning_map_path'])->toBe('/zoning-maps/acacia.png');

    // Read off Acacia's own sheet: it is the one barangay drawn with a solid
    // R-3 Max block, and it carries no R-2 at all.
    expect(collect($acacia['zoning_classifications'])->pluck('code')->all())
        ->toBe(['R-3-MAX', 'C-2', 'I-2', 'INSTITUTIONAL']);

    // The swatch travels with the row so the frontend never keeps a second
    // palette that can drift out of step with the image it labels.
    expect(collect($acacia['zoning_classifications'])->firstWhere('code', 'C-2')['legend_color'])
        ->toBe('#ff0000');
});

it('describes the map and never the applicant’s location', function () {
    $acacia = collect(
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->getJson('/api/v1/reference/barangays')
            ->assertOk()
            ->json('data')
    )->firstWhere('name', 'Acacia');

    /*
     * The rule this file exists for. A raster map supports "these zones are
     * drawn in this barangay" and nothing narrower — no zone for a pin, no
     * conforming/non-conforming, no confidence in either. Adding any of these
     * keys would mean somebody had computed a verdict from pixels, which is the
     * mistake `docs/questions-for-malabon.md` C2 asks CPDO for the vector data
     * to make unnecessary.
     */
    foreach (['zoning_classification', 'zone', 'conforming', 'is_conforming', 'verdict'] as $forbidden) {
        expect($acacia)->not->toHaveKey($forbidden);
    }

    /*
     * The overlays bring their own version of the same temptation, and a worse
     * one: Flood covers all 21 barangays, so a key like `flood_risk` would be
     * trivially fillable and would read as a finding about the applicant's lot.
     * `zoning_overlays` says the ordinance designates an overlay over parts of
     * the barangay, which is the whole of what the ordinance says.
     */
    foreach (['overlay', 'flood_risk', 'in_flood_zone', 'flood_susceptibility'] as $forbidden) {
        expect($acacia)->not->toHaveKey($forbidden);
    }
});

it('leaves an admin’s correction alone when the seeder runs again', function () {
    /*
     * The office-signatory failure, applied to zoning: these lists were read off
     * pixels and CPDO will correct some of them. A re-seed that reinstated a
     * classification an admin had removed would quietly undo the correction and
     * nobody would see it happen.
     */
    $acacia = Barangay::where('name', 'Acacia')->firstOrFail();
    $c2 = ZoningClassification::where('code', 'C-2')->firstOrFail();

    $acacia->zoningClassifications()->detach($c2->id);
    $acacia->update(['zoning_map_path' => '/zoning-maps/acacia-2027-revision.png']);

    $this->seed(ZoningSeeder::class);

    expect($acacia->fresh()->zoningClassifications->pluck('code'))->not->toContain('C-2')
        ->and($acacia->fresh()->zoning_map_path)->toBe('/zoning-maps/acacia-2027-revision.png');
});

it('keeps the three overlay zones out of the base classification legend', function () {
    /*
     * The rule the separate table exists to hold. An overlay is not one of the
     * nineteen classifications CPDO prints on the sheets — Art. V §4 calls it "a
     * transparent zone overlain on a Base Zone" — so the legend must still count
     * nineteen after the overlays are seeded, and the overlays must be reachable
     * only through their own relation.
     */
    expect(ZoningClassification::count())->toBe(19)
        ->and(ZoningClassification::whereIn('code', ['FLD-OZ', 'HTG-OZ', 'ETM-OZ'])->count())->toBe(0);

    expect(ZoningOverlay::orderBy('sort_order')->pluck('code')->all())
        ->toBe(['FLD-OZ', 'HTG-OZ', 'ETM-OZ']);
});

it('designates Flood over all 21 barangays, Heritage over five and Eco-Tourism over Dampalit', function () {
    // City Ordinance No. 24-2018, Annex C's map index, agreeing with Art. IV §5's
    // right-hand column. Heritage is the one to watch: the text-layer extraction
    // of the §5 table reads eight barangays because its overlay column is
    // misaligned, and Annex C is the one we follow.
    $named = fn (string $code) => ZoningOverlay::where('code', $code)
        ->firstOrFail()->barangays()->orderBy('name')->pluck('name')->all();

    expect($named('FLD-OZ'))->toHaveCount(21)
        ->and($named('HTG-OZ'))->toBe(['Baritan', 'Concepcion', 'Hulong Duhat', 'Ibaba', 'San Agustin'])
        ->and($named('ETM-OZ'))->toBe(['Dampalit']);
});

it('gives a barangay its base zones and its overlays at the same time', function () {
    // An overlay does not replace a base zone, it lies over it. Dampalit is the
    // one barangay carrying two overlays, so it is where a merged list would show.
    $dampalit = Barangay::where('name', 'Dampalit')->with(['zoningClassifications', 'zoningOverlays'])->firstOrFail();

    expect($dampalit->zoningClassifications->pluck('code')->all())
        ->toBe(['R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'C-1', 'INSTITUTIONAL', 'FISHPOND', 'MANGROVE'])
        ->and($dampalit->zoningOverlays->pluck('code')->all())->toBe(['FLD-OZ', 'ETM-OZ']);
});

it('sends overlays as their own key on the reference endpoint', function () {
    $body = $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/reference/barangays')
        ->assertOk()
        ->json('data');

    $baritan = collect($body)->firstWhere('name', 'Baritan');

    // Its own key, not extra entries in zoning_classifications: a consumer that
    // renders the classification list must not be able to print an overlay in it.
    expect(collect($baritan['zoning_classifications'])->pluck('code')->all())
        ->not->toContain('FLD-OZ')
        ->and(collect($baritan['zoning_overlays'])->pluck('code')->all())
        ->toBe(['FLD-OZ', 'HTG-OZ']);

    // The description travels with the row so the frontend never keeps its own
    // copy of what an ordinance says, which an amending ordinance would strand.
    expect(collect($baritan['zoning_overlays'])->firstWhere('code', 'FLD-OZ')['description'])
        ->toContain('prone to flooding');
});

it('leaves an admin’s overlay correction alone when the seeder runs again', function () {
    /*
     * Same rule as the classifications: these designations were read out of an
     * ordinance whose own two articles disagree about the codes, so CPDO will
     * correct some of them, and a re-seed that reinstated a removed overlay
     * would undo the correction with nobody watching.
     */
    $baritan = Barangay::where('name', 'Baritan')->firstOrFail();
    $heritage = ZoningOverlay::where('code', 'HTG-OZ')->firstOrFail();

    $baritan->zoningOverlays()->detach($heritage->id);

    $this->seed(ZoningSeeder::class);

    expect($baritan->fresh()->zoningOverlays->pluck('code')->all())->toBe(['FLD-OZ']);
});

it('ships every map file the rows point at', function () {
    /*
     * The rows live in the API and the images live in the web app's public
     * folder, so nothing but this test notices when the two get separated — a
     * broken sheet would only show up as a missing image on the applicant's
     * screen, mid-filing.
     */
    $publicDir = base_path('../web/public');

    foreach (Barangay::whereNotNull('zoning_map_path')->get() as $b) {
        expect(file_exists($publicDir.$b->zoning_map_path))
            ->toBeTrue("missing map file for {$b->name}: {$b->zoning_map_path}");
    }
});
