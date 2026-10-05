<?php

use App\Models\Barangay;
use App\Models\PermitType;
use App\Models\PsicCode;
use Illuminate\Support\Facades\DB;

/*
 * The rule-by-rule zoning checklist is gone (Ken, 5 October 2026).
 *
 * It answered the applicant's wizard on Location & Zoning and on Review, and
 * CPDO's review sheet, from App\Support\Zoning\ZoningCheck, and it asked the
 * applicant and the zoning officer questions whose answers were saved with the
 * filing. All of it went: the three endpoints, the questions' answers, and the
 * checklist inside CPDD's sheet. What it read about the trade and the zones
 * (ZoningConformance, TradeUses) stays, for the note under the map.
 *
 * Self-contained (no helpers from other files): Pest's parallel runner loads
 * files separately.
 */

function zcrFiling(): int
{
    authAs('owner@biztrack.local');
    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => 'Checklist Gone Store '.random_int(10000, 99999),
        'registration_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-'.random_int(100000, 999999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '4 Zone St.', 'barangay_id' => Barangay::where('name', 'Muzon')->value('id')],
        'lines' => [['psic_code_id' => PsicCode::where('code', '47111')->value('id')]],
    ])->assertCreated()->json('data.id');

    return test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'data_privacy_consent' => true,
        'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->value('id')],
        'zoning_facts' => ['home_based' => true, 'parking_on_street' => true],
    ])->assertCreated()->json('data.id');
}

it('answers none of the checklist’s three endpoints', function () {
    $id = zcrFiling();

    $this->postJson('/api/v1/zoning-check', ['barangay_id' => Barangay::where('name', 'Muzon')->value('id')])
        ->assertNotFound();
    $this->getJson("/api/v1/applications/{$id}/zoning-check")->assertNotFound();

    authAs('zoning@biztrack.local');
    $this->putJson("/api/v1/applications/{$id}/zoning-facts", ['facts' => ['lot_zone' => 'R-2-MAX']])
        ->assertNotFound();
});

it('keeps no zoning answers with a draft, and sends no checklist with the filing', function () {
    $id = zcrFiling();
    $this->putJson("/api/v1/applications/{$id}", ['zoning_facts' => ['persons_engaged' => 3]])->assertOk();
    expect(DB::table('applications')->where('id', $id)->value('zoning_facts'))->toBeNull();

    attachRequiredDocuments($id);
    $this->postJson("/api/v1/applications/{$id}/submit")->assertOk();
    $payload = json_encode($this->getJson("/api/v1/applications/{$id}")->assertOk()->json('data'));
    expect($payload)->not->toContain('"zoning_facts"')
        ->and($payload)->not->toContain('"zoning_check"')
        ->and($payload)->toContain('"ZONING"');
});
