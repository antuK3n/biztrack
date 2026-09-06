<?php

use App\Models\Application;
use App\Models\Department;
use App\Models\OfficerRequest;

/*
 * The applicant needs to know which office is asking, and the answer is always
 * the office of whoever asked.
 *
 * Three tests in this file used to assert the opposite — that a requester could
 * NAME a different office on the payload. That was a real feature, added so the
 * super admin (who has no department) could attribute a request to somebody.
 * The client has since reversed both halves of it: `request.create` came off
 * `admin`, and the office is now taken from the authenticated account and
 * nothing else — "Do not allow an Admin to manually change the office assigned
 * to the request ... enforced by the backend, not only by hiding the field in
 * the frontend."
 *
 * So those three are inverted here rather than deleted. The behaviour they
 * guarded is gone on purpose, and an inverted test says that out loud where a
 * missing one would just look like coverage that got dropped. What they were
 * really protecting — that `from_office` is emitted at all, rather than the
 * screen falling back to `created_by.department` — is kept and still asserted.
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

it('ignores an office named by the requester', function () {
    $app = openApplicationForRequest();
    $cho = Department::where('code', 'CHO')->value('id');
    $bplo = Department::where('code', 'BPLO')->value('id');

    /*
     * Inverted deliberately — this used to assert that CHO came back.
     *
     * A BPLO officer posting City Health's id still raises a BPLO requirement.
     * The old behaviour let one office file work into another office's queue,
     * which is the same boundary the rest of this system spends its time
     * defending; the client closed it.
     */
    $id = $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/requests", [
            'subject' => 'Health certificates',
            'body' => 'For the food handlers.',
            'department_id' => $cho,
        ])->assertCreated()->json('data.id');

    expect(OfficerRequest::find($id)->department_id)->toBe($bplo);
});

/*
 * The super admin does not ask an applicant for anything.
 *
 * This was written the other way round — "gives the super admin a way to
 * attribute a request to an office" — because the admin holds no
 * `department_id`, so a request it raised reached the applicant from nobody
 * until an explicit `department_id` was allowed on the payload.
 *
 * The client has since taken the whole activity off the role: "In the super
 * admin's account (admin@), remove Messages, Track, Inspections, and Other
 * Requirements. It is not his role to do those things." Asking an applicant for
 * a further requirement is an office's work — the office that will read the
 * answer, and the office whose name the applicant sees on it. So `request.create`
 * came off `admin`, and this endpoint answers 403.
 *
 * The `department_id` override the old test was guarding did NOT go away with
 * it, and that is why this is an inversion rather than a deletion: it is still
 * how one office attributes a request to another (the test above this one,
 * BPLO naming CHO). Only the department-less caller it was originally added for
 * is gone.
 */
it('refuses the super admin a request: asking the applicant is an office’s work', function () {
    $app = openApplicationForRequest();
    $bplo = Department::where('code', 'BPLO')->value('id');

    $this->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/requests", [
            'request_type' => 'message',
            'subject' => 'Clarification',
            'body' => 'Please confirm your floor area.',
            'department_id' => $bplo,
        ])->assertStatus(403);

    // Nothing was written on the way to the 403.
    expect(OfficerRequest::where('application_id', $app->id)->where('subject', 'Clarification')->exists())
        ->toBeFalse();
});

it('is unmoved by a nonsense office on the payload', function () {
    $app = openApplicationForRequest();
    $bplo = Department::where('code', 'BPLO')->value('id');

    /*
     * This used to 422 on `exists:departments,id`. The field is no longer
     * validated because it is no longer READ — an ignored input needs no rule,
     * and keeping one would imply the value still decides something. A caller
     * sending rubbish gets a perfectly ordinary requirement from their own
     * office, which is the correct outcome and the safe one.
     */
    $id = $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/requests", [
            'subject' => 'x',
            'body' => 'y',
            'department_id' => 999999,
        ])->assertCreated()->json('data.id');

    expect(OfficerRequest::find($id)->department_id)->toBe($bplo);
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

it('reads the requesting office back on the payload', function () {
    /*
     * The surviving half of item 57, and the reason this test is kept rather
     * than dropped with the override it used to exercise: `from_office` has to
     * be EMITTED. The composer stored `department_id` for a whole round while
     * the payload emitted only `created_by.department`, so the office an
     * applicant saw was a different fact from the office the row belonged to,
     * and no screen could tell them apart.
     *
     * Now that the two are always the same office, this asserts they agree —
     * which is the shape the client asked for and the shape that makes "from
     * the City Health Office" and "visible to the City Health Office" one
     * statement.
     */
    $app = openApplicationForRequest();
    $bplo = Department::where('code', 'BPLO')->firstOrFail();

    $data = $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/requests", [
            'subject' => 'Health certificates',
            'body' => 'For the food handlers.',
        ])->assertCreated()->json('data');

    expect($data['from_office']['id'])->toBe($bplo->id)
        ->and($data['from_office']['name'])->toBe($bplo->name)
        ->and($data['created_by']['department'])->toBe($bplo->name);
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
