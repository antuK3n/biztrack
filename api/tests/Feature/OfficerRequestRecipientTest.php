<?php

use App\Models\Application;
use App\Models\Department;
use App\Models\OfficerRequest;

/*
 * The applicant needs to know which office is asking. The requester's own
 * department is the sensible default, but the super admin has none, so an
 * explicit choice has to be possible or their requests arrive from nobody.
 */

function openApplicationForRequest(): Application
{
    return Application::whereIn('status', ['submitted', 'under_review', 'returned', 'pending_payment'])
        ->firstOrFail();
}

it('defaults the office to the requesting officer own department', function () {
    $app = openApplicationForRequest();

    $id = $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/requests", [
            'request_type' => 'document',
            'subject' => 'Barangay clearance',
            'body' => 'Please upload the current one.',
        ])->assertCreated()->json('data.id');

    $bplo = Department::where('code', 'BPLO')->value('id');
    expect(OfficerRequest::find($id)->department_id)->toBe($bplo);
});

it('lets the requester name the office the applicant sees', function () {
    $app = openApplicationForRequest();
    $cho = Department::where('code', 'CHO')->value('id');

    $id = $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/requests", [
            'request_type' => 'document',
            'subject' => 'Health certificates',
            'body' => 'For the food handlers.',
            'department_id' => $cho,
        ])->assertCreated()->json('data.id');

    expect(OfficerRequest::find($id)->department_id)->toBe($cho);
});

it('gives the super admin a way to attribute a request to an office', function () {
    // Regression guard: admin has no department_id, so before this the request
    // was created with a null office and reached the applicant from nobody.
    $app = openApplicationForRequest();
    $bplo = Department::where('code', 'BPLO')->value('id');

    $id = $this->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/requests", [
            'request_type' => 'message',
            'subject' => 'Clarification',
            'body' => 'Please confirm your floor area.',
            'department_id' => $bplo,
        ])->assertCreated()->json('data.id');

    expect(OfficerRequest::find($id)->department_id)->toBe($bplo);
});

it('rejects an office that does not exist', function () {
    $app = openApplicationForRequest();

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/requests", [
            'request_type' => 'document',
            'subject' => 'x',
            'body' => 'y',
            'department_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors(['department_id']);
});

/*
 * Item 89: "Requests for other requirements should have recipients. The admin
 * should choose who will receive this."
 *
 * There is exactly one recipient the model can express. A request is answered
 * through POST /requests/{id}/respond, gated on `request.respond` — a permission
 * only the business_owner role holds — and index() hands an owner the requests
 * on their own filings. So the honest control is a name rather than a picker,
 * and the payload has to carry that name or the screen is guessing at it.
 */
it('names the applicant as the recipient of the request', function () {
    $app = openApplicationForRequest();

    $recipient = $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/requests", [
            'request_type' => 'document',
            'subject' => 'Lease contract',
            'body' => 'Please upload the signed copy.',
        ])->assertCreated()->json('data.recipient');

    expect($recipient['id'])->toBe($app->applicant_user_id)
        ->and($recipient['kind'])->toBe('applicant')
        ->and($recipient['name'])->not->toBe('');
});

it('reads the chosen office back, not the requester’s own', function () {
    /*
     * Regression guard for the half of item 57 that was never finished. The
     * composer has stored `department_id` since that round, but the payload only
     * ever emitted `created_by.department` — the requester's own office — so
     * picking a different one moved a column no screen displayed.
     */
    $app = openApplicationForRequest();
    $cho = Department::where('code', 'CHO')->firstOrFail();

    $data = $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/requests", [
            'request_type' => 'document',
            'subject' => 'Health certificates',
            'body' => 'For the food handlers.',
            'department_id' => $cho->id,
        ])->assertCreated()->json('data');

    expect($data['from_office']['id'])->toBe($cho->id)
        ->and($data['from_office']['name'])->toBe($cho->name)
        // BPLO raised it on CHO's behalf; both facts stay true and separate.
        ->and($data['created_by']['department'])->not->toBe($cho->name);
});

it('names the recipient on the request list too', function () {
    // The list is what both inboxes render, so a recipient present only on the
    // create response would be a name that vanished on reload.
    $app = openApplicationForRequest();

    $id = $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/requests", [
            'request_type' => 'message',
            'subject' => 'Floor area',
            'body' => 'Please confirm it.',
        ])->assertCreated()->json('data.id');

    $row = collect($this->getJson('/api/v1/requests')->assertOk()->json('data'))
        ->firstWhere('id', $id);

    expect($row['recipient']['id'])->toBe($app->applicant_user_id)
        ->and($row['from_office'])->not->toBeNull();
});

it('names the applicant on the picker the composer reads', function () {
    // The composer names the recipient BEFORE the request exists, off
    // ApplicationListResource. Without this the field is blank at the one moment
    // the officer needs it.
    $app = openApplicationForRequest();

    $row = collect($this->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/applications')->assertOk()->json('data'))
        ->firstWhere('id', $app->id);

    expect($row)->not->toBeNull()
        ->and($row['applicant']['id'])->toBe($app->applicant_user_id);
});
