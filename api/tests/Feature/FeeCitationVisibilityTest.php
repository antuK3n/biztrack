<?php

use App\Models\Application;
use App\Models\FeeAssessment;

/*
 * The LGU asked that revenue-code section numbers not be shown to applicants.
 * Officers still need them to defend an assessment, so the split is by
 * permission, and it is enforced in the payload rather than only in the UI.
 */

function seedCitedAssessment(): Application
{
    $app = Application::where('tracking_id', 'BIZ-2026-00002')->firstOrFail();

    FeeAssessment::updateOrCreate(['application_id' => $app->id], [
        'line_items' => [[
            'code' => 'permit.filing_fee',
            'label' => 'Application filing fee (business permit application)',
            'amount' => 100.0,
            'office' => 'BPLO',
            'section' => 'Sec. 3A.02',
            'source' => 'A10-2016',
        ]],
        'total_amount' => 100.0,
    ]);

    return $app;
}

it('hides revenue-code citations from the applicant who owns the application', function () {
    $app = seedCitedAssessment();

    $line = $this->withHeaders(authAs('juan@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->json('data.fee_assessment.line_items.0');

    expect($line)->not->toHaveKey('section')
        ->and($line)->not->toHaveKey('source')
        // The line itself must survive intact, minus the citation.
        ->and($line['label'])->toBe('Application filing fee (business permit application)')
        ->and((float) $line['amount'])->toBe(100.0);
});

it('shows revenue-code citations to a reviewing officer', function () {
    $app = seedCitedAssessment();

    $line = $this->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->json('data.fee_assessment.line_items.0');

    expect($line['section'])->toBe('Sec. 3A.02')
        ->and($line['source'])->toBe('A10-2016');
});

it('keeps the assessment total unchanged for applicants', function () {
    $app = seedCitedAssessment();

    $this->withHeaders(authAs('juan@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->assertJsonPath('data.fee_assessment.total_amount', '100.00');
});
