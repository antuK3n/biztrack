<?php

use App\Models\Application;
use App\Models\ApplicationPermitType;
use App\Models\PermitType;
use App\Services\WorkflowService;
use App\Support\AnalyticsOffice;
use App\Support\LguReports;
use App\Support\ManilaCalendar;
use Illuminate\Support\Facades\DB;

/*
 * The Refused column of "Clearances Issued per Office" counts every refusal.
 *
 *  - A permit refused, re-applied for and refused again was one refusal: the
 *    second overwrote the first's date on the permit row.
 *  - BPLO rejecting a filing refuses the business permit, and was not counted
 *    at all: it lives on the filing, not on a permit row.
 */

function refusalsReport(?string $office): array
{
    $today = ManilaCalendar::today();

    return collect(LguReports::build('clearances', $today->subDays(30), $today, AnalyticsOffice::scope($office))['sections'][0]['rows'])
        ->keyBy('label')
        ->all();
}

it('counts a permit refused twice as two refusals', function () {
    $appId = scopedAssignmentFiling('Refused Twice');
    $workflow = app(WorkflowService::class);
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();
    $row = fn (): ApplicationPermitType => $workflow->pivotFor(Application::findOrFail($appId), 'SANITARY');

    $workflow->approveClearance($row());
    $workflow->rejectClearance($row(), 'No potable water connection.');

    $this->travel(2)->days();
    // The applicant applies again; CHO reads it and refuses again.
    $workflow->submitClearanceForm(Application::findOrFail($appId), $sanitary);
    $workflow->approveClearance($row());
    $workflow->rejectClearance($row(), 'Still no potable water connection.');

    expect(DB::table('clearance_refusals')->where('application_permit_type_id', $row()->id)->count())->toBe(2)
        ->and(refusalsReport('CHO')[$sanitary->name]['refused'])->toBe(2);
});

it('counts BPLO rejecting a filing as a refused business permit', function () {
    $business = PermitType::where('code', PermitType::OUTCOME_CODE)->value('name');
    $before = refusalsReport(null)[$business]['refused'];

    $appId = scopedAssignmentFiling('Rejected By BPLO');
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/reject", ['reason' => 'Not a lawful use of the premises.'])
        ->assertOk();

    expect(refusalsReport(null)[$business]['refused'] - $before)->toBe(1)
        ->and(refusalsReport('BPLO')[$business]['refused'])->toBeGreaterThanOrEqual(1);
});

it('recovers the earlier refusals the permit row no longer dates, once', function () {
    $business = anaBusiness();
    $filing = anaFiling($business, ['status' => 'awaiting_other_permits', 'submitted_at' => now()->subDays(20)]);
    $sanitary = anaPermitTypeId('SANITARY');
    $pivot = DB::table('application_permit_types')->insertGetId([
        'application_id' => $filing,
        'permit_type_id' => $sanitary,
        'status' => 'rejected',
        // The second refusal; the first survives only in the audit log.
        'rejected_at' => '2026-09-25 03:00:00',
        'rejection_note' => 'Still no water.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach (['2026-09-20 03:00:00', '2026-09-25 03:00:00'] as $at) {
        DB::table('audit_logs')->insert([
            'action' => 'clearance.status_changed',
            'auditable_type' => ApplicationPermitType::class,
            'auditable_id' => $pivot,
            'changes' => json_encode(['application_id' => $filing, 'permit_type_id' => $sanitary, 'from' => 'for_inspection', 'to' => 'rejected', 'note' => 'No water.']),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    $migration = require database_path('migrations/2026_10_01_000300_keep_every_refusal_of_a_permit.php');
    $migration->up();
    $migration->up();

    expect(DB::table('clearance_refusals')->where('application_permit_type_id', $pivot)->orderBy('refused_at')->pluck('refused_at')->all())
        ->toBe(['2026-09-20 03:00:00', '2026-09-25 03:00:00']);
});
