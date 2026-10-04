<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationReturnNote;
use App\Services\WorkflowService;
use Illuminate\Validation\ValidationException;

/*
 * An office changes what it asked for, without returning twice.
 *
 * ── The gap this fills ───────────────────────────────────────────────────────
 *
 * `ApplicationStatus::Returned => [ForApproval, Cancelled, Rejected]`, and
 * `ClearanceStatus::Returned => [ForApproval]`. Once a filing is returned it
 * cannot be returned again — which is right, because it is on the applicant's
 * desk and an office should not be able to send back something it is not
 * holding.
 *
 * What that left was an officer who returns, then notices a second problem or
 * reads their own remark back and finds it unclear, with nothing to do but
 * wait for the resubmission and return it again. The applicant pays for that
 * omission with an entire extra round trip — which is the failure
 * `returnMainForm`'s own note already warns about: *"the applicant fixes the
 * wrong thing and is returned twice."*
 *
 * The client asked on 30 September 2026 whether a second return was possible
 * and chose amending the open one over allowing one.
 *
 * ── What these cases pin ─────────────────────────────────────────────────────
 *
 * That it does NOT transition. That is the whole design: the filing stays
 * Returned, no history entry claims a second return, and an applicant halfway
 * through a repair is not bounced. A later change that implements this as
 * "return again, quietly" would pass a test asserting only the new remark.
 */

/** A filing BPLO has returned, pointed at one field. */
function returnedFiling(string $targets = 'form:trade_name'): Application
{
    $app = filingReturnedAbout($targets);

    expect($app->status)->toBe(ApplicationStatus::Returned);

    return $app;
}

/** BPLO's assignment on it. */
function bploAssignment(Application $app): ApplicationAssignment
{
    return ApplicationAssignment::where('application_id', $app->id)
        ->whereHas('department', fn ($d) => $d->where('code', 'BPLO'))
        ->firstOrFail();
}

it('replaces what the office asked for, and moves nothing', function () {
    $app = returnedFiling('form:trade_name');
    $before = $app->fresh()->status;

    app(WorkflowService::class)->amendReturn(
        bploAssignment($app),
        'Also the registration number — the scan is cut off.',
        'form:trade_name,form:registration_number',
        [
            'form:trade_name' => 'Spell it as on the certificate.',
            'form:registration_number' => 'The scan is cut off.',
        ],
    );

    $fresh = $app->fresh();
    /*
     * The status is the assertion that matters. Everything else here could be
     * achieved by returning again; only this says it did not happen.
     */
    expect($fresh->status)->toBe($before)
        ->and($fresh->status)->toBe(ApplicationStatus::Returned);

    $assignment = bploAssignment($app)->fresh();
    expect($assignment->remarks)->toBe('Also the registration number — the scan is cut off.')
        ->and($assignment->remarks_target)->toBe('form:trade_name,form:registration_number');
});

it('writes one note per field, replacing the previous round', function () {
    $app = returnedFiling('form:trade_name');

    app(WorkflowService::class)->amendReturn(
        bploAssignment($app),
        'Two things now.',
        'form:trade_name,form:registration_number',
        [
            'form:trade_name' => 'Spell it as on the certificate.',
            'form:registration_number' => 'The scan is cut off.',
        ],
    );

    $notes = ApplicationReturnNote::where('application_id', $app->id)
        ->whereNull('permit_type_id')
        ->pluck('note', 'target')
        ->all();

    expect($notes)->toHaveCount(2)
        ->and($notes['form:registration_number'])->toBe('The scan is cut off.');

    /*
     * And a field dropped from the list loses its note. A stale note on a row
     * this round is not about is the defect `returnMainForm` replaces the
     * whole pointer to avoid — the applicant fixes the wrong thing.
     */
    app(WorkflowService::class)->amendReturn(
        bploAssignment($app),
        'Only the registration number after all.',
        'form:registration_number',
        ['form:registration_number' => 'The scan is cut off.'],
    );

    $after = ApplicationReturnNote::where('application_id', $app->id)
        ->whereNull('permit_type_id')
        ->pluck('note', 'target')
        ->all();

    expect($after)->toHaveCount(1)
        ->and($after)->toHaveKey('form:registration_number')
        ->and($after)->not->toHaveKey('form:trade_name');
});

it('re-snapshots against the new pointer, so a newly named field can be compared', function () {
    $app = returnedFiling('form:trade_name');

    app(WorkflowService::class)->amendReturn(
        bploAssignment($app),
        'The registration number too.',
        'form:trade_name,form:registration_number',
        [],
    );

    /*
     * The snapshot is what the resubmission is diffed against to report what
     * the applicant changed. Left as it was, a field named for the first time
     * here would have nothing to compare with and would come back reading
     * "unchanged" however the applicant edited it.
     */
    $values = $app->fresh()->returned_values['values'] ?? [];
    expect(array_keys($values))->toContain('form:registration_number');
});

it('refuses to amend a filing that is not with the applicant', function () {
    /*
     * The guard, and the reason there is one: on anything but a returned
     * filing the office should be pressing Return. Silently rewriting the
     * pointer on an approved filing would be a different bug wearing this
     * method's name.
     */
    // Already past BPLO's first approval; scopedAssignmentFiling does it.
    $app = Application::findOrFail(scopedAssignmentFiling('Amend Guard Co'));

    expect($app->fresh()->status)->not->toBe(ApplicationStatus::Returned);

    expect(fn () => app(WorkflowService::class)->amendReturn(
        bploAssignment($app->fresh()),
        'Nothing to amend.',
        'form:trade_name',
        [],
    ))->toThrow(ValidationException::class);
});

it('is reachable over the wire, by the officer holding the case', function () {
    $app = returnedFiling('form:trade_name');
    $assignment = bploAssignment($app);

    $bplo = authAs('bplo@biztrack.local');
    test()->withHeaders($bplo)
        ->postJson("/api/v1/assignments/{$assignment->id}/amend-return", [
            'remarks' => 'One more thing.',
            'remarks_target' => 'form:trade_name,form:registration_number',
            'remarks_notes' => ['form:registration_number' => 'Cut off.'],
        ])
        ->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::Returned)
        ->and(bploAssignment($app)->fresh()->remarks_target)
        ->toBe('form:trade_name,form:registration_number');
});

it('hands the officer back what they asked, so amending starts from it', function () {
    /*
     * The composer opens with the fields ticked AND each remark written,
     * because the pointer is replaced wholesale on every write: an officer
     * who opened a blank list, ticked the one field they had just noticed
     * and pressed send would silently drop the others.
     *
     * This pins the payload rather than the screen, because the payload is
     * where it broke. `ApplicationResource` sends an empty object when the
     * relation is not loaded, and the officer's sheet was not loading it —
     * so the boxes came up ticked and every remark blank, which reads
     * exactly like an office that wrote no notes.
     */
    $app = returnedFiling('form:trade_name');
    app(WorkflowService::class)->amendReturn(
        bploAssignment($app),
        'Two things.',
        'form:trade_name',
        ['form:trade_name' => 'Spell it as on the certificate.'],
    );

    $assignment = bploAssignment($app);
    $bplo = authAs('bplo@biztrack.local');
    $body = test()->withHeaders($bplo)
        ->getJson("/api/v1/assignments/{$assignment->id}")
        ->assertOk()
        ->json('data');

    expect($body['remarks_target'])->toBe('form:trade_name')
        ->and($body['application']['return_notes']['form:trade_name'])
        ->toBe('Spell it as on the certificate.');
});
