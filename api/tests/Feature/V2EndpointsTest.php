<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\OfficerRequest;
use App\Models\PermitType;
use App\Models\PsicCode;

/** app2 (RxCare) is under review with owner juan@ and a BPLO assignment. */
function rxcareApp(): Application
{
    return Application::whereHas('business', fn ($q) => $q->where('name', 'RxCare Pharmacy'))->first();
}

it('round-trips a message between officer and owner', function () {
    $app = rxcareApp();

    // Officer posts.
    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/messages", ['body' => 'Please confirm your address.'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Please confirm your address.');

    // Owner reads the thread and sees the message.
    $this->withHeaders(authAs('juan@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}/messages")
        ->assertOk()
        ->assertJsonFragment(['body' => 'Please confirm your address.']);
});

it('creates, responds to, and closes a document request', function () {
    $app = rxcareApp();

    $reqId = $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/requests", [
            'request_type' => 'document',
            'subject' => 'Upload lease contract',   // legacy alias -> title
            'body' => 'We need your current lease.', // legacy alias -> description
        ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Upload lease contract')   // paper name emitted
        ->assertJsonPath('data.subject', 'Upload lease contract') // legacy name emitted
        ->json('data.id');

    // Owner responds.
    $this->withHeaders(authAs('juan@biztrack.local'))
        ->postJson("/api/v1/requests/{$reqId}/respond", ['body' => 'Here it is.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted');

    // Officer closes.
    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/requests/{$reqId}/close", ['outcome' => 'fulfilled'])
        ->assertOk()
        ->assertJsonPath('data.status', 'fulfilled');

    $req = OfficerRequest::find($reqId);
    expect($req->reviewed_by_user_id)->not->toBeNull();
    expect($req->applicant_response)->toBe('Here it is.');
});

it('creates a meeting-type request with officer-provided fields', function () {
    $app = rxcareApp();

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/requests", [
            'request_type' => 'meeting',
            'title' => 'Clarification call',
            'description' => 'Quick call to align on requirements.',
            'meeting_scheduled_at' => now()->addDays(2)->toIso8601String(),
            'meeting_duration_minutes' => 45,
            'meeting_link' => 'https://meet.google.com/abc-defg-hij',
        ])
        ->assertCreated()
        ->assertJsonPath('data.request_type', 'meeting')
        ->assertJsonPath('data.meeting_duration_minutes', 45)
        ->assertJsonPath('data.meeting_link', 'https://meet.google.com/abc-defg-hij');
});

it('lets an officer adjust the fee before payment', function () {
    // Build a fresh pending-payment application for owner@.
    $owner = authAs('owner@biztrack.local');
    $barangayId = Barangay::first()->id;
    $psicId = PsicCode::first()->id;
    $businessId = $this->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'FeeAdjust Co', 'address' => ['line1' => 'x', 'barangay_id' => $barangayId],
        'lines' => [['psic_code_id' => $psicId]],
    ])->json('data.id');
    $typeId = PermitType::where('code', 'BUSINESS')->value('id');
    $appId = $this->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId, 'application_type' => 'new', 'permit_type_ids' => [$typeId],
    ])->json('data.id');
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/fee/adjust", [
            'line_items' => [['label' => 'Adjusted permit fee', 'amount' => 1234.00]],
            'total_amount' => 1234.00,
        ])
        ->assertOk();
});

it('blocks a suspended business from filing a new application', function () {
    $business = Business::where('name', "Nena's Sari-Sari Store")->first();

    // Admin suspends it.
    $this->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$business->id}/status", [
            'status' => 'suspended', 'reason' => 'demo',
        ])->assertOk();

    $typeId = PermitType::where('code', 'BUSINESS')->value('id');
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/applications', [
            'business_id' => $business->id, 'application_type' => 'new', 'permit_type_ids' => [$typeId],
        ])
        ->assertStatus(422);
});

it('returns renewal prefill for an existing business', function () {
    $business = Business::where('name', "Nena's Sari-Sari Store")->first();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/businesses/{$business->id}/prefill?type=renewal")
        ->assertOk()
        ->assertJsonStructure(['data']);
});

it('upserts and reads a per-office application form', function () {
    $owner = authAs('owner@biztrack.local');
    $barangayId = Barangay::first()->id;
    $psicId = PsicCode::first()->id;
    $businessId = $this->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'FormCo', 'address' => ['line1' => 'x', 'barangay_id' => $barangayId],
        'lines' => [['psic_code_id' => $psicId]],
    ])->json('data.id');
    $typeId = PermitType::where('code', 'OCCUPANCY')->value('id');
    $appId = $this->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId, 'application_type' => 'new', 'permit_type_ids' => [$typeId],
    ])->json('data.id');

    $this->withHeaders($owner)
        ->putJson("/api/v1/applications/{$appId}/office-forms/OCCUPANCY", [
            'form_data' => ['floor_area' => 45, 'occupancy_type' => 'commercial'],
        ])
        ->assertOk()
        ->assertJsonPath('data.form_data.occupancy_type', 'commercial');

    $this->withHeaders($owner)
        ->getJson("/api/v1/applications/{$appId}/office-forms")
        ->assertOk()
        ->assertJsonFragment(['permit_type_code' => 'OCCUPANCY']);
});
