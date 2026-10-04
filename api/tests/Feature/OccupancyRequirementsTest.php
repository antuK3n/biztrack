<?php

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Business;
use App\Models\PermitType;
use App\Models\User;
use App\Support\OccupancyRequirements;

/*
 * The OBO checklist's two wrong rows, reported by the tester on
 * 5 October 2026: an affidavit that applies only when the professional
 * changed was demanded of every filing, and "Local / Zoning Clearance —
 * From your Business Permit application" showed the location sketch.
 */

function occupancyChecklistRows(): array
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => ApplicationType::New,
        'status' => ApplicationStatus::Draft,
    ]);
    $app->permitTypes()->sync(PermitType::whereIn('code', ['BUSINESS', 'OCCUPANCY'])->pluck('id'));

    return collect(OccupancyRequirements::forApplication($app))->keyBy('key')->all();
}

it('does not block on the change-of-professional affidavit, which the paper makes conditional', function () {
    $row = occupancyChecklistRows()['CHANGE_OF_PROFESSIONAL'];

    expect($row['blocking'])->toBeFalse()
        ->and($row['label'])->toContain('(if ');
});

it('does not pass the location sketch off as the zoning clearance', function () {
    $row = occupancyChecklistRows()['ZONING_CLEARANCE'];

    expect($row['source'])->toBe('upload')
        ->and($row['carried_from'])->toBeNull()
        ->and($row['carried_document_ids'])->toBe([])
        // CPDO issues it on this filing; the sheet does not wait on a copy.
        ->and($row['blocking'])->toBeFalse()
        ->and($row['note'])->toContain('Zoning Clearance issued on this filing');
});

it('still blocks on the sealed Certificate of Completion and the owner ID', function () {
    $rows = occupancyChecklistRows();

    expect($rows['COMPLETION']['blocking'])->toBeTrue()
        ->and($rows['OWNER_ID']['blocking'])->toBeTrue()
        ->and($rows['PLANS']['blocking'])->toBeTrue();
});
