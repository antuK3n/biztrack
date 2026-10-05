<?php

use App\Models\Application;
use App\Models\FeeAssessment;
use App\Support\Numbering;

/*
 * The Tax Order of Payment printed the filing's tracking ID as its
 * "Reference No:", so the bill and the filing read as one record. Ken: the
 * Reference No. is not the tracking ID. The bill now carries its own number,
 * derived from its fee_assessments row (Numbering::taxOrderReference).
 */

function billedFiling(): Application
{
    $app = Application::where('tracking_id', 'BIZ-2026-00002')->firstOrFail();

    FeeAssessment::updateOrCreate(['application_id' => $app->id], [
        'line_items' => [['label' => 'Application filing fee', 'amount' => 100.0]],
        'total_amount' => 100.0,
    ]);

    return $app->fresh();
}

it('numbers a tax order from the year it was assessed and its own row id', function () {
    $fee = billedFiling()->feeAssessment;
    $fee->forceFill(['assessed_at' => '2025-11-30 09:00:00', 'created_at' => '2026-01-05 09:00:00'])->save();

    expect(Numbering::taxOrderReference($fee->fresh()))
        ->toBe(sprintf('TOP-2025-%06d', $fee->id));
});

it('falls back to when the assessment row was written when it has no assessed_at', function () {
    $fee = billedFiling()->feeAssessment;
    $fee->forceFill(['assessed_at' => null, 'created_at' => '2024-03-01 09:00:00'])->save();

    expect(Numbering::taxOrderReference($fee->fresh()))
        ->toBe(sprintf('TOP-2024-%06d', $fee->id));
});

it('gives the owner a tax order reference that is not the tracking id', function () {
    $app = billedFiling();
    $expected = Numbering::taxOrderReference($app->feeAssessment);

    $reference = $this->withHeaders(authAs('juan@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->json('data.fee_assessment.reference_number');

    expect($reference)->toBe($expected)
        ->and($reference)->toStartWith('TOP-')
        ->and($reference)->not->toBe($app->tracking_id);
});

it('sends the same tax order reference on the pay page bill', function () {
    $app = billedFiling();

    $this->withHeaders(authAs('juan@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}/fee")
        ->assertOk()
        ->assertJsonPath('data.reference_number', Numbering::taxOrderReference($app->feeAssessment));
});

it('keeps the number when the bill is re-priced in place', function () {
    // assessFees re-prices the filing's one row (updateOrCreate), so a
    // resubmitted filing's bill keeps the number already on the owner's screen.
    $app = billedFiling();
    $before = Numbering::taxOrderReference($app->feeAssessment);

    FeeAssessment::updateOrCreate(['application_id' => $app->id], ['total_amount' => 120.0]);

    $this->withHeaders(authAs('juan@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->assertJsonPath('data.fee_assessment.reference_number', $before);
});

it('follows the current assessment when a filing is re-assessed onto a new row', function () {
    // A paid bill is held by its payment row, so only an unpaid one is replaced.
    $app = billedFiling();
    $app->payments()->delete();
    $before = Numbering::taxOrderReference($app->feeAssessment);

    $app->feeAssessment->delete();
    FeeAssessment::create([
        'application_id' => $app->id,
        'line_items' => [['label' => 'Application filing fee', 'amount' => 120.0]],
        'total_amount' => 120.0,
    ]);

    $after = $this->withHeaders(authAs('juan@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->json('data.fee_assessment.reference_number');

    expect($after)->toBe(Numbering::taxOrderReference($app->fresh()->feeAssessment))
        ->and($after)->not->toBe($before);
});
