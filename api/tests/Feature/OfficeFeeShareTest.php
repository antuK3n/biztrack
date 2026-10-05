<?php

use App\Models\Application;

/*
 * A clearance office is sent only its own share of the bill.
 *
 * Ken, 6 October 2026: on the review sheet CHO, BFP, OBO, CENRO and CPDO see
 * the Assessed Fee for their own permit — its lines and their sum — while
 * BPLO and the super admin see the whole Tax Order of Payment. Enforced in
 * ApplicationResource, so the other offices' figures never reach the browser.
 */

beforeEach(fn () => duringOfficeHours());

/** Put a known three-office bill on the filing, so the shares are checkable. */
function mixedBill(int $appId): void
{
    Application::findOrFail($appId)->feeAssessment->update([
        'line_items' => [
            ['code' => 'permit.filing_fee', 'label' => 'Filing fee', 'amount' => 100, 'permit_codes' => ['BUSINESS'], 'office' => 'BPLO'],
            ['code' => 'sanitary.permit_fee', 'label' => 'Sanitary permit fee', 'amount' => 300, 'permit_codes' => ['SANITARY'], 'office' => 'CHO'],
            ['code' => 'sanitary.health_cert', 'label' => 'Health certificates', 'amount' => 150, 'permit_codes' => ['SANITARY'], 'office' => 'CHO'],
            ['code' => 'fire.fsic_fee', 'label' => 'FSIC fee', 'amount' => 500, 'permit_codes' => ['FSIC'], 'office' => 'BFP'],
        ],
        'total_amount' => 1050,
    ]);
}

function feeOf(string $email, string $url): array
{
    return test()->withHeaders(authAs($email))->getJson($url)->assertOk()->json();
}

it('sends a clearance office only the lines for its own permit, and their sum', function () {
    // Held by CHO's officer: since 6 October 2026 an office other than BPLO
    // opens its review only once somebody holds it (CaseHolder).
    $appId = scopedFilingHeldByCho('Fee Share Eatery');
    mixedBill($appId);
    $whole = Application::findOrFail($appId)->feeAssessment;

    $fee = feeOf('sanitary@biztrack.local', "/api/v1/applications/{$appId}")['data']['fee_assessment'];

    expect(collect($fee['line_items'])->pluck('code')->all())
        ->toBe(['sanitary.permit_fee', 'sanitary.health_cert'])
        ->and($fee['total_amount'])->toBe('450.00')
        ->and((float) $whole->total_amount)->toBe(1050.0);

    // The review sheet's own door — the assignment — carries the same share.
    $viaAssignment = json_encode(feeOf('sanitary@biztrack.local', '/api/v1/assignments/'.choAssignmentId($appId)));
    expect($viaAssignment)->toContain('sanitary.permit_fee')
        ->and($viaAssignment)->not->toContain('fire.fsic_fee')
        ->and($viaAssignment)->not->toContain('permit.filing_fee');

});

it('still sends BPLO and the super admin the whole bill', function (string $email) {
    $appId = scopedAssignmentFiling('Fee Share Bakery');
    mixedBill($appId);
    $whole = Application::findOrFail($appId)->feeAssessment;

    $fee = feeOf($email, "/api/v1/applications/{$appId}")['data']['fee_assessment'];

    expect((float) $fee['total_amount'])->toBe((float) $whole->total_amount)
        ->and(count($fee['line_items']))->toBe(count($whole->line_items))
        ->and(collect($fee['line_items'])->pluck('permit_codes')->flatten()->all())->toContain('BUSINESS');
})->with(['bplo@biztrack.local', 'admin@biztrack.local']);
