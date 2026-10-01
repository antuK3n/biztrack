<?php

/*
 * Malformed report and dashboard requests are refused with a reason, never a
 * 500 and never a quietly substituted answer.
 */

it('refuses a list where one date is expected', function () {
    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/reports/permits-issued?from[]=2026-01-01')
        ->assertStatus(422);
});

it('refuses a list of offices instead of answering with the caller\'s own', function () {
    $this->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/analytics/reports/permits-issued?office[]=BFP')
        ->assertStatus(422);
});

it('gives an office the fee lines that name only its permit', function () {
    $application = \App\Models\Application::whereNull('deleted_at')->firstOrFail();
    $fee = \App\Models\FeeAssessment::create([
        'application_id' => $application->id,
        'line_items' => [
            ['label' => 'Sanitary permit fee', 'amount' => 300.0, 'group' => 'regulatory', 'permit_codes' => ['SANITARY']],
        ],
        'total_amount' => 300.0,
    ]);
    \App\Models\Payment::create([
        'application_id' => $application->id,
        'fee_assessment_id' => $fee->id,
        'reference_number' => 'PAY-TEST-LINE-1',
        'amount' => 300.0,
        'method' => 'gcash',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $day = \App\Support\ManilaCalendar::today()->toDateString();
    $body = $this->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/analytics/reports/collections?from={$day}&to={$day}")
        ->assertOk()
        ->json('data');

    expect(json_encode($body))->toContain('300');
});

it('writes money in the CSV with two decimals and the time in Manila', function () {
    $application = \App\Models\Application::whereNull('deleted_at')->firstOrFail();
    $fee = \App\Models\FeeAssessment::create([
        'application_id' => $application->id,
        'line_items' => [['label' => 'Fee', 'amount' => 503000.0, 'group' => 'regulatory', 'office' => 'BPLO']],
        'total_amount' => 503000.0,
    ]);
    \App\Models\Payment::create([
        'application_id' => $application->id, 'fee_assessment_id' => $fee->id,
        'reference_number' => 'PAY-TEST-CSV-1', 'amount' => 503000.0,
        'method' => 'gcash', 'status' => 'completed', 'paid_at' => now(),
    ]);

    $day = \App\Support\ManilaCalendar::today()->toDateString();
    $csv = $this->withHeaders(authAs('bplo@biztrack.local'))
        ->get("/api/v1/analytics/reports/collections/csv?from={$day}&to={$day}")
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('503000.00')
        ->and($csv)->toMatch('/Generated,\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+08:00/');
});

it('accepts only the dashboard windows the screen offers', function () {
    $this->withHeaders(authAs('bplo@biztrack.local'))->getJson('/api/v1/analytics/dashboard?months=6')->assertOk();
    $this->withHeaders(authAs('bplo@biztrack.local'))->getJson('/api/v1/analytics/dashboard?months=5')->assertStatus(422);
    $this->withHeaders(authAs('bplo@biztrack.local'))->getJson('/api/v1/analytics/dashboard?months[]=6')->assertStatus(422);
});
