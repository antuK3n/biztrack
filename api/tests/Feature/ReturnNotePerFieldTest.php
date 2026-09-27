<?php

use App\Models\Application;
use App\Models\ApplicationReturnNote;
use App\Services\WorkflowService;

/*
 * ── One remark per returned field ────────────────────────────────────────────
 *
 * Client, 27 September 2026: *"Allow to put 1 comment/remark per field
 * selected, not just 1 remark for all fields."*
 *
 * The single box got worse the moment the picker became a checklist: an officer
 * ticking three fields wrote one paragraph covering all three, and the
 * applicant then had to work out which clause belonged to which of the three
 * boxes in front of them. The pointer answered "which field" and the prose put
 * the matching exercise straight back.
 *
 * `filingReturnedAbout` is defined in ReturnedFieldsAreCorrectedTest — Pest
 * helpers share one global namespace across the suite, so it is reused rather
 * than redeclared, which would be a fatal redeclaration error.
 */

it('keeps a separate remark for each returned field', function () {
    $app = filingReturnedAbout('form:tin,form:trade_name');

    app(WorkflowService::class)->returnMainForm(
        $app->fresh(),
        'Two things to fix.',
        'form:tin,form:trade_name',
        [
            'form:tin' => 'This does not match your BIR certificate.',
            'form:trade_name' => 'Leave this blank if you trade under your own name.',
        ],
    );

    $notes = ApplicationReturnNote::where('application_id', $app->id)
        ->pluck('note', 'target')
        ->all();

    expect($notes)->toBe([
        'form:tin' => 'This does not match your BIR certificate.',
        'form:trade_name' => 'Leave this blank if you trade under your own name.',
    ]);
});

it('replaces the previous round rather than piling notes up', function () {
    $app = filingReturnedAbout('form:tin');
    $wf = app(WorkflowService::class);

    $wf->returnMainForm($app->fresh(), 'First round.', 'form:tin', [
        'form:tin' => 'Missing entirely.',
    ]);
    $wf->resubmit($app->fresh());
    $wf->returnMainForm($app->fresh(), 'Second round.', 'form:trade_name', [
        'form:trade_name' => 'Now this one is wrong.',
    ]);

    /*
     * The pointer is replaced on every return for this exact reason, and the
     * notes follow it: a note left over from round one sits under a field
     * round two is not about and tells the applicant to fix something nobody
     * asked about.
     */
    expect(ApplicationReturnNote::where('application_id', $app->id)->pluck('note', 'target')->all())
        ->toBe(['form:trade_name' => 'Now this one is wrong.']);
});

it('drops a blank note instead of storing an empty instruction', function () {
    $app = filingReturnedAbout('form:tin');

    app(WorkflowService::class)->returnMainForm($app->fresh(), 'Fix it.', 'form:tin,form:email', [
        'form:tin' => 'Wrong number.',
        // The composer will not send this, but the service is the guarantee:
        // a field named with nothing said about it is worse than not naming it.
        'form:email' => '   ',
    ]);

    expect(ApplicationReturnNote::where('application_id', $app->id)->pluck('target')->all())
        ->toBe(['form:tin']);
});

it('still accepts a return that names no fields at all', function () {
    $app = filingReturnedAbout('form:tin');

    // The plain prose return, which is what every return was before the picker
    // and is still right for "the whole thing needs another look".
    app(WorkflowService::class)->returnMainForm($app->fresh(), 'Please review the whole form.');

    expect(ApplicationReturnNote::where('application_id', $app->id)->count())->toBe(0)
        ->and($app->fresh()->status->value)->toBe('returned');
});

it('publishes the notes to the applicant keyed by field', function () {
    $app = filingReturnedAbout('form:tin');

    app(WorkflowService::class)->returnMainForm($app->fresh(), 'Fix it.', 'form:tin', [
        'form:tin' => 'This does not match your BIR certificate.',
    ]);

    $res = $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk();

    /*
     * Keyed by code, because that is how every reader wants it: the applicant's
     * correction box looks its own note up, and a list would make each screen
     * build the same index.
     */
    expect($res->json('data.return_notes'))
        ->toBe(['form:tin' => 'This does not match your BIR certificate.']);
});

it('sends the applicant a word when the system raises the TIN requirement', function () {
    /*
     * The requirement was raised, stored and tested end to end on
     * 27 September 2026 — and notified nobody, so it sat in the table waiting
     * for an applicant who had no way to know it was there. The officer-raised
     * path has always sent this; a system-raised one owes the same.
     */
    $app = filingWithoutTin();
    $before = $app->applicant->notifications()->count();

    bploApprovesForm($app);

    expect($app->fresh()->applicant->notifications()->count())->toBeGreaterThan($before);
    expect(
        $app->fresh()->applicant->notifications()
            ->where('type', 'request')
            ->exists()
    )->toBeTrue();
});
