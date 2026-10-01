<?php

use App\Models\Barangay;
use App\Models\Business;
use App\Models\PsicCode;
use App\Support\MalabonGeo;

/*
 * Checklist Zoning 3 — the API refuses a business whose map pin is outside
 * the barangay it names. The browser refused it already; these prove the
 * server does too, with the same polygons and the same tolerance.
 *
 * Points used, all [lat, lng]:
 *   14.668, 120.985   well inside Potrero (its wide eastern half)
 *   14.665, 120.9725  Tugatog, ~54 m west of Potrero's straight western edge
 *   14.665, 120.970   Tugatog, ~320 m west of that edge
 *   14.5995, 120.9842 Manila — nowhere near Malabon
 */

function pinPayload(string $barangay, ?float $lat, ?float $lng, array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Pin Check Trading',
        'registration_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-55123',
        'address' => [
            'street' => 'Test Street',
            'barangay_id' => Barangay::where('name', $barangay)->value('id'),
            'latitude' => $lat,
            'longitude' => $lng,
        ],
        'lines' => [['psic_code_id' => PsicCode::where('code', '47111')->value('id')]],
    ], $overrides);
}

it('accepts a pin inside the barangay the business names', function () {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', pinPayload('Potrero', 14.668, 120.985))
        ->assertCreated();
});

it('refuses a pin that sits in a different barangay, naming both', function () {
    $res = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', pinPayload('Potrero', 14.665, 120.970))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['address.latitude', 'address.longitude']);

    expect($res->json('errors')['address.latitude'][0])
        ->toBe('The pin is in Tugatog, but the barangay chosen is Potrero. Move the pin into Potrero, or change the barangay.');
});

it('refuses a pin outside Malabon altogether', function () {
    $res = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', pinPayload('Potrero', 14.5995, 120.9842))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['address.latitude', 'address.longitude']);

    expect($res->json('errors')['address.latitude'][0])
        ->toBe('The pin is outside Potrero. Move the pin into Potrero, or change the barangay.');
});

/*
 * The stored boundaries are simplified, so a shop on the real border can land
 * a few dozen metres on the wrong side of the stored line. The tolerance is
 * the browser's own, so the server never refuses a pin the map accepted.
 */
it('accepts a pin just across the border, inside the shared tolerance', function () {
    expect(MalabonGeo::metresFromBarangay(14.665, 120.9725, 'Potrero'))
        ->toBeGreaterThan(30.0)
        ->toBeLessThan(MalabonGeo::TOLERANCE_M);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', pinPayload('Potrero', 14.665, 120.9725))
        ->assertCreated();
});

it('accepts a business with no pin, which the wizard gates on its own', function () {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', pinPayload('Potrero', null, null))
        ->assertCreated();
});

/*
 * Most of the register predates the check, and a renewal re-saves the address
 * it was handed. Re-sending what is on file is not a new answer and is let
 * through; moving the pin is, and has to agree with the barangay.
 */
it('lets an update re-send a location already on file, and checks one that moved', function () {
    $headers = authAs('owner@biztrack.local');
    $id = $this->withHeaders($headers)
        ->postJson('/api/v1/businesses', pinPayload('Potrero', 14.668, 120.985))
        ->assertCreated()
        ->json('data.id');

    // A legacy row: pin in Tugatog, barangay Potrero — written before the check.
    Business::findOrFail($id)->address->update(['latitude' => 14.665, 'longitude' => 120.970]);

    $this->withHeaders($headers)
        ->putJson("/api/v1/businesses/{$id}", pinPayload('Potrero', 14.665, 120.970))
        ->assertOk();

    $this->withHeaders($headers)
        ->putJson("/api/v1/businesses/{$id}", pinPayload('Potrero', 14.665, 120.9695))
        ->assertStatus(422)
        ->assertJsonValidationErrors('address.latitude');

    // Changing the barangay is a new answer too, even with the pin unchanged.
    $this->withHeaders($headers)
        ->putJson("/api/v1/businesses/{$id}", pinPayload('Acacia', 14.665, 120.970))
        ->assertStatus(422)
        ->assertJsonValidationErrors('address.latitude');
});

it('lets a barangay with no polygon on file through, as the browser does', function () {
    $extra = Barangay::create(['name' => 'Not On The Map']);

    $payload = pinPayload('Potrero', 14.665, 120.970);
    $payload['address']['barangay_id'] = $extra->id;

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', $payload)
        ->assertCreated();
});

/*
 * The server's polygons are a copy of the browser's. If one is regenerated
 * and the other is not, the map accepts pins the API refuses (or the reverse)
 * — so a drift fails here. Skipped only where the web tree is absent.
 */
it('holds the same barangay polygons as the web map', function () {
    $ts = base_path('../web/src/lib/malabonGeo.data.ts');
    if (! is_file($ts)) {
        $this->markTestSkipped('web/ is not beside api/ here');
    }
    $src = (string) file_get_contents($ts);

    preg_match_all('/\{ name: "([^"]+)", psgc: "([^"]+)", rings: (\[.*\]) \},?\s*\n/', $src, $m, PREG_SET_ORDER);
    $web = array_map(fn ($row) => [
        'name' => $row[1],
        'psgc' => $row[2],
        'rings' => json_decode($row[3], true),
    ], $m);

    preg_match('/MALABON_OUTLINE: \[number, number\]\[\] = (\[.*\])\s*\n/', $src, $outline);

    expect($web)->toHaveCount(21)
        ->and(MalabonGeo::data()['barangays'])->toEqual($web)
        ->and(MalabonGeo::data()['outline'])->toEqual(json_decode($outline[1], true));
});

it('names every seeded barangay in the polygon set', function () {
    $names = array_column(MalabonGeo::data()['barangays'], 'name');

    expect(Barangay::pluck('name')->diff($names)->values()->all())->toBe([]);
});
