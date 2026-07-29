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
