<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * OR Records (thesis panel revisions, matrix item 5): "The OR number, date
 * paid, amount, and linked application are stored, and the records can be
 * searched and viewed."
 *
 * Ken's decisions: BPLO and the super admin only; newest first; paginated;
 * searchable by OR no., tracking ID or business; a View link where a photo of
 * the receipt exists.
 */

beforeEach(function () {
    Storage::fake('local');
});

/**
 * A business of the demo owner's, its filing billed, its receipt sent by the
 * owner, taken by the demo BPLO officer and marked paid at the counter with
 * this OR. Answers the filing.
 */
function orRecordFiling(string $businessName, string $or, string $paidOn, ?UploadedFile $photo = null, ?UploadedFile $ownersPhoto = null): Application
{
    authAs('owner@biztrack.local');
    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => $businessName,
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(100000, 999999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Receipt Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->value('id')],
    ])->assertCreated()->json('data.id');
    attachRequiredDocuments($appId);
    test()->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);

    /*
     * The owner's receipt, which Mark as paid waits for (Ken, 3 November
     * 2026): their photo when the test gives one, else the OR and the date
     * paid, which carry no photo for the record to point at.
     */
    test()->post(
        "/api/v1/applications/{$appId}/payment-proofs",
        $ownersPhoto !== null ? ['photo' => $ownersPhoto] : ['or_number' => $or, 'or_date' => $paidOn],
        ['Accept' => 'application/json'],
    )->assertCreated();

    authAs('bplo@biztrack.local');
    $assignment = ApplicationAssignment::where('application_id', $appId)
        ->whereHas('department', fn ($d) => $d->where('code', 'BPLO'))
        ->value('id');
    test()->postJson("/api/v1/assignments/{$assignment}/claim")->assertOk();
    test()->post("/api/v1/applications/{$appId}/counter-payment", array_filter([
        'or_number' => $or,
        'or_date' => $paidOn,
        'photo' => $photo,
    ]), ['Accept' => 'application/json'])->assertCreated();

    return Application::findOrFail($appId);
}

function orRecords(array $query = []): array
{
    return test()->getJson('/api/v1/or-records?'.http_build_query($query))->assertOk()->json();
}

it('lists each OR with its date paid, amount, filing, business and who recorded it, newest first', function () {
    $older = orRecordFiling('Receipt Bakery', '4300001', now()->subDays(3)->toDateString());
    $newer = orRecordFiling('Receipt Hardware', '4300002', now()->subDay()->toDateString());
    $bplo = User::where('email', 'bplo@biztrack.local')->firstOrFail();

    authAs('bplo@biztrack.local');
    $body = orRecords();

    expect(collect($body['data'])->pluck('or_number')->all())->toBe(['4300002', '4300001'])
        ->and($body['meta']['total'])->toBe(2);

    $row = $body['data'][0];
    expect($row['or_date'])->toBe(now()->subDay()->toDateString())
        ->and((float) $row['amount'])->toBe((float) $newer->payments()->sole()->amount)
        ->and($row['application'])->toBe([
            'id' => $newer->id,
            'tracking_id' => $newer->tracking_id,
            'review_id' => $newer->bploAssignment->id,
        ])
        ->and($row['business']['name'])->toBe('Receipt Hardware')
        ->and($row['recorded_by'])->toBe(['id' => $bplo->id, 'name' => $bplo->name])
        ->and($row['proof'])->toBeNull();

    expect($body['data'][1]['application']['tracking_id'])->toBe($older->tracking_id);
});

it('leaves out payments with no OR — online ones', function () {
    orRecordFiling('Receipt Pharmacy', '4300003', now()->toDateString());

    // Paid online: no OR, so not a record.
    authAs('owner@biztrack.local');
    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => 'Online Paid Store',
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(100000, 999999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '6 Online Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ])->assertCreated()->json('data.id');
    $appId = test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->value('id')],
    ])->assertCreated()->json('data.id');
    attachRequiredDocuments($appId);
    test()->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);
    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    authAs('admin@biztrack.local');
    expect(collect(orRecords()['data'])->pluck('or_number')->all())->toBe(['4300003']);
});

it('searches by OR no., tracking ID or business', function () {
    $bakery = orRecordFiling('Receipt Bakery', '4300011', now()->subDays(2)->toDateString());
    orRecordFiling('Receipt Hardware', '4300022', now()->subDay()->toDateString());

    authAs('bplo@biztrack.local');
    expect(collect(orRecords(['q' => '4300022'])['data'])->pluck('or_number')->all())->toBe(['4300022'])
        ->and(collect(orRecords(['q' => $bakery->tracking_id])['data'])->pluck('or_number')->all())->toBe(['4300011'])
        ->and(collect(orRecords(['q' => 'hardware'])['data'])->pluck('or_number')->all())->toBe(['4300022'])
        ->and(orRecords(['q' => 'no such receipt'])['data'])->toBe([]);
});

it('pages the records', function () {
    orRecordFiling('Receipt One', '4300031', now()->subDays(3)->toDateString());
    orRecordFiling('Receipt Two', '4300032', now()->subDays(2)->toDateString());
    orRecordFiling('Receipt Three', '4300033', now()->subDay()->toDateString());

    authAs('bplo@biztrack.local');
    $first = orRecords(['per_page' => 2]);
    $second = orRecords(['per_page' => 2, 'page' => 2]);

    expect($first['meta'])->toMatchArray(['total' => 3, 'last_page' => 2, 'current_page' => 1])
        ->and(collect($first['data'])->pluck('or_number')->all())->toBe(['4300033', '4300032'])
        ->and(collect($second['data'])->pluck('or_number')->all())->toBe(['4300031']);
});

it('points the record at its photo, the officer’s before the owner’s, and the photo opens for BPLO', function () {
    $both = orRecordFiling(
        'Receipt Photo Shop',
        '4300041',
        now()->subDay()->toDateString(),
        photo: UploadedFile::fake()->createWithContent('officers-4300041.pdf', "%PDF-1.4\n%%EOF\n"),
        ownersPhoto: UploadedFile::fake()->image('owners-4300041.jpg'),
    );
    orRecordFiling('Receipt Owner Photo', '4300042', now()->toDateString(), ownersPhoto: UploadedFile::fake()->image('owners-4300042.png'));

    authAs('bplo@biztrack.local');
    [$ownersOnly, $officers] = orRecords()['data'];
    expect($officers['or_number'])->toBe('4300041')
        ->and($officers['proof']['name'])->toBe('officers-4300041.pdf')
        ->and($ownersOnly['proof']['name'])->toBe('owners-4300042.png')
        // Both of the first filing's photos are kept and linked; the link opens one.
        ->and($both->payments()->sole()->proofs()->count())->toBe(2);

    test()->get("/api/v1/payment-proofs/{$officers['proof']['id']}/file")->assertOk();
});

it('is BPLO’s and the super admin’s alone', function () {
    orRecordFiling('Receipt Guarded', '4300051', now()->toDateString());

    authAs('admin@biztrack.local');
    test()->getJson('/api/v1/or-records')->assertOk();

    foreach (['sanitary@biztrack.local', 'fire@biztrack.local', 'owner@biztrack.local'] as $refused) {
        authAs($refused);
        test()->getJson('/api/v1/or-records')->assertForbidden();
    }
});

it('gives BPLO each filing’s review to open from the tracking ID, and the super admin none', function () {
    $filing = orRecordFiling('Receipt Linked Store', '4300061', now()->toDateString());

    // BPLO opens its own review of the filing, the screen the staff site has for it.
    authAs('bplo@biztrack.local');
    $row = collect(orRecords()['data'])->firstWhere('or_number', '4300061');
    expect($row['application']['review_id'])->toBe($filing->bploAssignment->id);
    test()->getJson("/api/v1/assignments/{$row['application']['review_id']}")
        ->assertOk()
        ->assertJsonPath('data.application.tracking_id', $filing->tracking_id);

    // The super admin's site has no screen for a filing, so no link.
    authAs('admin@biztrack.local');
    $row = collect(orRecords()['data'])->firstWhere('or_number', '4300061');
    expect($row['application'])->toHaveKey('review_id')
        ->and($row['application']['review_id'])->toBeNull();
});
