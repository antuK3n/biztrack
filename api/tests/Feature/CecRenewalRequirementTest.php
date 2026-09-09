<?php

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Support\CecRequirements;
use App\Support\SheetRequirements;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * MCG-CENRO-FO-001 v2.0's one FOR RENEWAL row.
 *
 * The paper's requirements box lists four things and three of them were
 * deliberately left off the screen, at the client's own instruction: CENRO can
 * open the application form, the official receipt and the business permit from
 * the filing itself, so uploading them is work with no reader.
 *
 * The fourth is different because it may not be in BizTrack at all. In year one
 * almost every renewal is of a CEC issued on paper, and there is nothing for
 * CENRO to open — which is exactly the row worth asking for, and only on a
 * renewal, because an initial application has no previous year.
 */

function cecFiling(string $type, bool $withPriorCertificate): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    if ($withPriorCertificate) {
        $cec = PermitType::where('code', 'CEC')->firstOrFail();
        $priorApp = Application::create([
            'business_id' => $business->id,
            'applicant_user_id' => $owner->id,
            'application_type' => 'new',
            'status' => 'approved',
        ]);
        Permit::create([
            'application_id' => $priorApp->id,
            'business_id' => $business->id,
            'permit_type_id' => $cec->id,
            'permit_number' => 'MCE-2025-000042',
            'issued_at' => now()->subYear(),
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addDays(20),
            'status' => 'active',
        ]);
    }

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => $type,
        'status' => 'draft',
    ]);
    $app->permitTypes()->sync(PermitType::whereIn('code', ['CEC'])->pluck('id')->all());

    return $app->fresh();
}

it('asks a new CEC application for nothing', function () {
    // "FOR RENEWAL", in the paper's own words. There is no previous year to
    // produce, so the box is not merely unticked — it is not asked.
    expect(SheetRequirements::for(cecFiling('new', false), 'CEC'))->toBe([]);
});

it('asks a renewal for last year’s certificate', function () {
    $rows = SheetRequirements::for(cecFiling('renewal', false), 'CEC');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['label'])->toBe('Certificate of Environmental Compliance (Previous Year)')
        ->and($rows[0]['source'])->toBe('upload')
        ->and($rows[0]['satisfied'])->toBeFalse()
        ->and($rows[0]['code'])->toBe(CecRequirements::PREVIOUS_CEC);
});

it('does not ask when the register issued the certificate itself', function () {
    /*
     * The same rule the zoning checklist's carried rows follow: never ask for
     * something the office can already open. A business renewing a CEC BizTrack
     * issued has it sitting in `permits`, and asking them to scan it would be
     * asking for a copy of a row in the same database.
     */
    $rows = SheetRequirements::for(cecFiling('renewal', true), 'CEC');

    expect($rows[0]['source'])->toBe('carried')
        ->and($rows[0]['satisfied'])->toBeTrue()
        ->and($rows[0]['code'])->toBeNull()
        // A permit is not an attachment, so the number identifies it rather
        // than a file the screen could open.
        ->and($rows[0]['reference'])->toBe('MCE-2025-000042')
        ->and($rows[0]['document'])->toBeNull();
});

it('takes the scan when there is no certificate on file', function () {
    Storage::fake('local');
    $app = cecFiling('renewal', false);
    $owner = authAs('owner@biztrack.local');

    $response = $this->withHeaders($owner)->post(
        "/api/v1/applications/{$app->id}/office-forms/CEC/requirements/".CecRequirements::PREVIOUS_CEC,
        ['file' => UploadedFile::fake()->create('cec-2025.pdf', 40, 'application/pdf')],
    )->assertCreated();

    $rows = $response->json('data.requirements');
    expect($rows[0]['satisfied'])->toBeTrue()
        ->and($rows[0]['document']['filename'])->toBe('cec-2025.pdf');

    // On the filing as an ordinary document, so CENRO's attachment list carries
    // it without knowing this row exists.
    expect(
        ApplicationDocument::where('application_id', $app->id)
            ->whereHas('documentType', fn ($q) => $q->where('code', CecRequirements::PREVIOUS_CEC))
            ->exists()
    )->toBeTrue();
});

it('does not accept the zoning checklist’s slots on the CENRO sheet', function () {
    /*
     * The dispatcher is what keeps these apart. Before it, `accepts()` was
     * ZoningRequirements' alone and the endpoint was hard-gated to ZONING; a
     * second sheet with requirements had to be able to join without the CENRO
     * form quietly inheriting CPDD's ten slots.
     */
    $app = cecFiling('renewal', false);
    $owner = authAs('owner@biztrack.local');

    $this->withHeaders($owner)->post(
        "/api/v1/applications/{$app->id}/office-forms/CEC/requirements/ZONING_REQ_SKETCH",
        ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')],
    )->assertNotFound();
});

it('offers nothing on the three sheets whose papers ask for nothing', function () {
    /*
     * The client, 9 September 2026: *"For the CHO, BFP, and OBO, just copy
     * what's in the application form currently."* Null rather than an empty
     * array, and the difference reaches the screen: an empty list renders as
     * "this office asks for nothing", which is a claim none of them has made.
     */
    $app = cecFiling('renewal', false);

    foreach (['SANITARY', 'FSIC', 'OCCUPANCY'] as $code) {
        expect(SheetRequirements::for($app, $code))->toBeNull();
    }
});
