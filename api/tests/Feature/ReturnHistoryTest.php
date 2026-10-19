<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\ApplicationReturn;
use App\Models\PermitType;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * ── A complied return keeps its remark ──────────────────────────────────────
 *
 * Client, 19 October 2026: *"When a returned application was complied, the
 * remark should not be removed."* The live `remarks` columns are cleared when
 * the owner answers — every screen reads them as "what to fix now" — so the
 * round is also written to `application_returns`, which keeps it: what was
 * asked, by whom, and what the owner changed in answer. "History on both
 * sides": the owner and the office both read it.
 */

/** A paid filing whose FSIC sheet is with BFP. Named apart from ApplicantRepairsTest's. */
function historyFsicAtOffice(string $name): Application
{
    $appId = scopedAssignmentFiling($name);
    $app = Application::findOrFail($appId);
    $workflow = app(WorkflowService::class);
    $type = PermitType::where('code', 'FSIC')->firstOrFail();

    $workflow->startClearance($app->fresh(), $type, 'apply');
    satisfyChecklist($app->fresh(), $type);
    $workflow->submitClearanceForm($app->fresh(), $type);

    return $app->fresh();
}

/** The owner uploads a new file for one FSIC row and hands the sheet back. */
function ownerAnswersFsic(Application $app, string $row, string $filename): void
{
    $owner = authAs('owner@biztrack.local');
    test()->withHeaders($owner)
        ->post(
            "/api/v1/applications/{$app->id}/office-forms/FSIC/requirements/{$row}",
            ['file' => UploadedFile::fake()->create($filename, 40, 'application/pdf')],
        )
        ->assertSuccessful();
    test()->withHeaders($owner)
        ->putJson("/api/v1/applications/{$app->id}/office-forms/FSIC", ['form_data' => [], 'submit' => true])
        ->assertSuccessful();
}

it('keeps BPLO\'s return after the owner complies, with what each field was and became', function () {
    $app = filingReturnedAbout('form:trade_name');
    // Notes the officer wrote, through the same service a second time as BPLO.
    authAs('bplo@biztrack.local');
    app(WorkflowService::class)->returnMainForm(
        $app->fresh(),
        'Please correct the trade name.',
        'form:trade_name',
        ['form:trade_name' => 'Wrong spelling.'],
    );

    $open = ApplicationReturn::where('application_id', $app->id)->where('status', 'open')->sole();
    expect($open->permit_type_id)->toBeNull()
        ->and($open->returned_by_user_id)->toBe(User::where('email', 'bplo@biztrack.local')->value('id'))
        ->and($open->items)->toHaveCount(1)
        ->and($open->items[0])->toMatchArray([
            'code' => 'form:trade_name',
            'note' => 'Wrong spelling.',
            'value' => 'Old Trade Name',
            'kind' => 'field',
        ]);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/corrections", [
            'fields' => ['form:trade_name' => 'New Trade Name'],
        ])->assertOk();

    $done = $open->fresh();
    expect($done->status)->toBe('complied')
        ->and($done->complied_at)->not->toBeNull()
        ->and($done->complied_by_user_id)->toBe(User::where('email', 'owner@biztrack.local')->value('id'))
        ->and($done->remarks)->toBe('Please correct the trade name.')
        ->and($done->changes[0])->toMatchArray([
            'code' => 'form:trade_name',
            'old' => 'Old Trade Name',
            'new' => 'New Trade Name',
            'changed' => true,
        ]);

    /* The live columns still clear: they are the CURRENT round, and there is none. */
    expect($app->fresh()->assignments()->whereNotNull('remarks')->count())->toBe(0);

    /*
     * The earlier return in this test (filingReturnedAbout's) was replaced by
     * the second before the owner answered, so it reads as superseded.
     */
    expect(ApplicationReturn::where('application_id', $app->id)->pluck('status')->sort()->values()->all())
        ->toBe(['complied', 'superseded']);
});

it('keeps every round, newest first, through the endpoint the owner reads', function () {
    $app = filingReturnedAbout('form:trade_name');
    $workflow = app(WorkflowService::class);
    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$app->id}/corrections", [
        'fields' => ['form:trade_name' => 'Round One Name'],
    ])->assertOk();

    authAs('bplo@biztrack.local');
    $workflow->returnMainForm($app->fresh(), 'Now the email.', 'form:email', ['form:email' => 'Typo in the domain.']);

    $owner = authAs('owner@biztrack.local');
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$app->id}/corrections", [
        'fields' => ['form:email' => 'fixed@example.com'],
    ])->assertOk();

    $rows = test()->withHeaders($owner)
        ->getJson("/api/v1/applications/{$app->id}/returns")
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['remarks'])->toBe('Now the email.')
        ->and($rows[0]['status'])->toBe('complied')
        ->and($rows[0]['items'][0])->toMatchArray(['code' => 'form:email', 'note' => 'Typo in the domain.'])
        ->and($rows[0]['changes'][0])->toMatchArray(['new' => 'fixed@example.com', 'changed' => true])
        ->and($rows[1]['remarks'])->toBe('Please correct these.')
        ->and($rows[1]['changes'][0])->toMatchArray(['old' => 'Old Trade Name', 'new' => 'Round One Name'])
        /* The applicant's screens name offices, never clerks. */
        ->and($rows[0]['returned_by'])->toBeNull()
        ->and($rows[0]['office']['name'])->not->toBeEmpty();

    $staff = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}/returns")
        ->assertOk()
        ->json('data');
    expect($staff[0]['returned_by']['name'])->toBe(User::where('email', 'bplo@biztrack.local')->value('name'));
});

it('rewrites an open return in place when it is amended, keeping the value from the original return', function () {
    $app = filingReturnedAbout('form:trade_name');
    authAs('bplo@biztrack.local');

    app(WorkflowService::class)->amendMainFormReturn(
        $app->fresh(),
        'Trade name and email.',
        'form:trade_name,form:email',
        ['form:trade_name' => 'Spelling.', 'form:email' => 'Bounces.'],
    );

    $row = ApplicationReturn::where('application_id', $app->id)->sole();
    expect($row->status)->toBe('open')
        ->and($row->amended_at)->not->toBeNull()
        ->and($row->remarks)->toBe('Trade name and email.')
        ->and(collect($row->items)->pluck('note', 'code')->all())
        ->toBe(['form:trade_name' => 'Spelling.', 'form:email' => 'Bounces.'])
        ->and(collect($row->items)->firstWhere('code', 'form:trade_name')['value'])->toBe('Old Trade Name');
});

it('marks an unanswered return superseded when the filing is cancelled', function () {
    $app = filingReturnedAbout('form:trade_name');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/cancel")
        ->assertSuccessful();

    expect($app->fresh()->status)->toBe(ApplicationStatus::Cancelled)
        ->and(ApplicationReturn::where('application_id', $app->id)->sole()->status)->toBe('superseded');
});

it('keeps an office\'s return and records the replaced document when the sheet comes back', function () {
    Storage::fake('local');
    $app = historyFsicAtOffice('History Return Co');
    $workflow = app(WorkflowService::class);
    authAs('fire@biztrack.local');

    $workflow->returnClearance(
        $workflow->pivotFor($app->fresh(), 'FSIC'),
        'The occupancy certificate copy is not certified.',
        'FSIC_REQ_VALID_COO',
        ['FSIC_REQ_VALID_COO' => 'Not a certified true copy.'],
    );

    $open = ApplicationReturn::where('application_id', $app->id)->sole();
    $fsic = PermitType::where('code', 'FSIC')->value('id');
    expect($open->permit_type_id)->toBe($fsic)
        ->and($open->kind)->toBe('return')
        ->and($open->department_id)->toBe(PermitType::find($fsic)->issuing_department_id)
        ->and($open->items[0])->toMatchArray([
            'code' => 'FSIC_REQ_VALID_COO',
            'kind' => 'document',
            'note' => 'Not a certified true copy.',
        ])
        ->and($open->items[0]['label'])->not->toBeNull();

    ownerAnswersFsic($app, 'FSIC_REQ_VALID_COO', 'coo-certified.pdf');

    $done = $open->fresh();
    expect($workflow->pivotFor($app->fresh(), 'FSIC')->status)->toBe(ClearanceStatus::ForApproval)
        ->and($done->status)->toBe('complied')
        ->and($done->changes[0]['changed'])->toBeTrue()
        ->and(collect($done->changes[0]['replaced'])->pluck('filename')->all())->toContain('coo-certified.pdf');
});

it('keeps an office\'s refusal as a round the owner answers by re-applying', function () {
    Storage::fake('local');
    $app = historyFsicAtOffice('History Refusal Co');
    $workflow = app(WorkflowService::class);
    authAs('fire@biztrack.local');

    $workflow->approveClearance($workflow->pivotFor($app->fresh(), 'FSIC'), 'Paperwork fine.');
    $workflow->rejectClearance(
        $workflow->pivotFor($app->fresh(), 'FSIC'),
        'The premises fail the fire code.',
        'Install the sprinklers, then apply again.',
        'FSIC_REQ_VALID_COO',
        ['FSIC_REQ_VALID_COO' => 'Not a certified true copy.'],
    );

    $row = ApplicationReturn::where('application_id', $app->id)->sole();
    expect($row->kind)->toBe('refusal')->and($row->status)->toBe('open')
        ->and($row->remarks)->toBe('The premises fail the fire code.');

    ownerAnswersFsic($app, 'FSIC_REQ_VALID_COO', 'coo-after-refusal.pdf');

    expect($row->fresh()->status)->toBe('complied');
});

it('shows an office only its own returns, and BPLO and the owner every one', function () {
    Storage::fake('local');
    $app = historyFsicAtOffice('History Scope Co');
    $workflow = app(WorkflowService::class);

    authAs('fire@biztrack.local');
    $workflow->returnClearance(
        $workflow->pivotFor($app->fresh(), 'FSIC'),
        'Fire office remark.',
        'FSIC_REQ_VALID_COO',
        ['FSIC_REQ_VALID_COO' => 'Fire note.'],
    );

    $codes = fn (string $email) => collect(
        test()->withHeaders(authAs($email))
            ->getJson("/api/v1/applications/{$app->id}/returns")
            ->assertOk()
            ->json('data'),
    )->map(fn ($r) => $r['permit_type']['code'] ?? 'MAIN')->all();

    expect($codes('owner@biztrack.local'))->toBe(['FSIC'])
        ->and($codes('bplo@biztrack.local'))->toBe(['FSIC'])
        ->and($codes('fire@biztrack.local'))->toBe(['FSIC'])
        /* CHO holds SANITARY on this filing and may open it — not BFP's remarks. */
        ->and($codes('sanitary@biztrack.local'))->toBe([]);

    /* An office with no part in the filing cannot open it at all. */
    test()->withHeaders(authAs('zoning@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}/returns")
        ->assertForbidden();
});

it('backfills complied and open rounds from the status history, notes and corrections', function () {
    Storage::fake('local');
    $app = historyFsicAtOffice('History Backfill Co');
    $workflow = app(WorkflowService::class);

    /* An office return that is still open… */
    authAs('fire@biztrack.local');
    $workflow->returnClearance(
        $workflow->pivotFor($app->fresh(), 'FSIC'),
        'Fire office remark.',
        'FSIC_REQ_VALID_COO',
        ['FSIC_REQ_VALID_COO' => 'Fire note.'],
    );

    /* …and a main-form round on another filing, answered. */
    $other = filingReturnedAbout('form:trade_name');
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$other->id}/corrections", [
            'fields' => ['form:trade_name' => 'Backfilled Name'],
        ])->assertOk();

    /* The register as it stood before this table: nothing recorded. */
    DB::table('application_returns')->delete();

    $migration = require database_path('migrations/2026_10_19_130000_a_complied_return_keeps_its_remarks.php');
    $migration->backfill();

    $fire = ApplicationReturn::where('application_id', $app->id)->sole();
    expect($fire->source)->toBe('backfill')
        ->and($fire->status)->toBe('open')
        ->and($fire->kind)->toBe('return')
        ->and($fire->remarks)->toBe('Fire office remark.')
        ->and($fire->returned_by_user_id)->toBe(User::where('email', 'fire@biztrack.local')->value('id'))
        ->and(collect($fire->items)->pluck('note', 'code')->all())->toBe(['FSIC_REQ_VALID_COO' => 'Fire note.']);

    $main = ApplicationReturn::where('application_id', $other->id)->sole();
    expect($main->status)->toBe('complied')
        ->and($main->permit_type_id)->toBeNull()
        ->and($main->remarks)->toBe('Please correct these.')
        ->and($main->complied_at)->not->toBeNull()
        ->and($main->changes[0])->toMatchArray([
            'code' => 'form:trade_name',
            'old' => 'Old Trade Name',
            'new' => 'Backfilled Name',
            'changed' => true,
        ]);
});
