<?php

use App\Enums\PermitStatus;
use App\Jobs\RunLegacyImport;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\LegacyImport;
use App\Models\LegacyOwner;
use App\Models\Permit;
use App\Models\User;
use App\Support\DashboardAnalytics;
use App\Support\LegacyImport\Template;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

/*
 * Migration 1 — importing the old register from a CSV (Ken's checklist,
 * 27 September 2026). Upload → dry run → confirm → import, super admin only,
 * keyed on the old system's ids so a re-import updates rather than duplicates,
 * owners without an account left UNCLAIMED for them to claim at sign-up.
 */

/** One template row, with the given columns overridden. */
function legacyRow(array $overrides = []): array
{
    return array_merge(array_fill_keys(Template::headers(), ''), [
        'legacy_business_id' => 'B-1',
        'business_name' => 'Sari-sari ng Bayan',
        'owner_first_name' => 'Maria',
        'owner_last_name' => 'Dimaculangan',
        'address_line' => '5 Gov. Pascual Ave.',
        'barangay' => 'Acacia',
        'legacy_permit_id' => 'P-1',
        'permit_type' => 'BUSINESS',
        'permit_number' => 'OLD-MP-2025-0001',
        'valid_from' => '2025-01-15',
        'valid_until' => '2099-12-31',
    ], $overrides);
}

/** A CSV upload made of template rows. */
function legacyCsv(array $rows, string $name = 'old-register.csv'): UploadedFile
{
    $out = fopen('php://temp', 'r+');
    fputcsv($out, Template::headers(), escape: '');
    foreach ($rows as $row) {
        fputcsv($out, array_values(array_merge(array_fill_keys(Template::headers(), ''), $row)), escape: '');
    }
    rewind($out);
    $csv = stream_get_contents($out);

    return UploadedFile::fake()->createWithContent($name, $csv);
}

function previewAsAdmin(UploadedFile $file): array
{
    return test()->withHeaders(authAs('admin@biztrack.local'))
        ->post('/api/v1/admin/legacy-imports/csv', ['file' => $file], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('data');
}

function runImport(int $id): array
{
    return test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/legacy-imports/{$id}/run")
        ->assertOk()
        ->json('data');
}

it('offers a template with a header row, one example row and a guide to every column', function () {
    $headers = authAs('admin@biztrack.local');

    $csv = test()->withHeaders($headers)->get('/api/v1/admin/legacy-imports/template')->assertOk();
    expect($csv->headers->get('content-disposition'))->toContain('biztrack-legacy-import-template.csv');

    $lines = array_values(array_filter(explode("\n", $csv->getContent())));
    expect($lines)->toHaveCount(2)
        ->and(str_getcsv($lines[0], escape: ''))->toBe(Template::headers())
        ->and(str_getcsv($lines[1], escape: '')[1])->toBe(Template::EXAMPLE['business_name']);

    $guide = test()->getJson('/api/v1/admin/legacy-imports/guide')->assertOk()->json('data');
    expect(collect($guide['columns'])->pluck('column')->all())->toBe(Template::headers())
        ->and($guide['barangays'])->toContain('Acacia');
});

it('reads the old register from a CSV only — there is no ODBC source to name', function () {
    $headers = authAs('admin@biztrack.local');

    $odbc = test()->withHeaders($headers)->postJson('/api/v1/admin/legacy-imports/odbc', ['dsn' => 'OLDBPLS']);
    expect($odbc->status())->toBeIn([404, 405])
        ->and(test()->withHeaders($headers)->getJson('/api/v1/admin/legacy-imports/guide')->json('data'))
        ->not->toHaveKey('odbc');
});

it('promises no sign-up claim in the column guide or in a refused row', function () {
    $headers = authAs('admin@biztrack.local');

    $guide = test()->withHeaders($headers)->getJson('/api/v1/admin/legacy-imports/guide')->json('data');
    expect(collect($guide['columns'])->firstWhere('column', 'owner_last_name')['description'])->toBe('Owner’s surname.');

    $preview = previewAsAdmin(legacyCsv([legacyRow(['owner_last_name' => ''])]));
    expect($preview['rejects'][0]['reasons'][0]['message'])->toBe('The owner’s surname is empty.');
});

it('refuses the import screen to anyone but the super admin', function () {
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/admin/legacy-imports/guide')->assertForbidden();
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->post('/api/v1/admin/legacy-imports/csv', ['file' => legacyCsv([legacyRow()])], ['Accept' => 'application/json'])
        ->assertForbidden();
});

it('dry-runs a file into will-create, will-update and rejected, with a reason for every reject, and writes nothing', function () {
    $businessesBefore = Business::withTrashed()->count();
    $permitsBefore = Permit::count();

    $preview = previewAsAdmin(legacyCsv([
        legacyRow(),                                                                  // row 2: new
        legacyRow(['legacy_permit_id' => 'P-2', 'permit_type' => 'SANITARY', 'permit_number' => 'OLD-HC-1']), // row 3: new permit, same business
        legacyRow(['legacy_business_id' => 'B-2', 'legacy_permit_id' => 'P-3', 'permit_number' => 'X-3', 'valid_until' => '31/12/2025']), // bad date
        legacyRow(['legacy_business_id' => 'B-3', 'legacy_permit_id' => 'P-4', 'permit_number' => 'X-4', 'barangay' => 'Atlantis']),     // unknown barangay
        legacyRow(['legacy_business_id' => 'B-4', 'legacy_permit_id' => 'P-5', 'permit_number' => 'X-5', 'permit_type' => 'LIQUOR']),    // unknown permit type
        legacyRow(['legacy_business_id' => 'B-5', 'legacy_permit_id' => 'P-6', 'permit_number' => 'X-6', 'owner_last_name' => '']),      // missing owner
        legacyRow(['legacy_permit_id' => 'P-1', 'permit_number' => 'OLD-MP-2025-0001']),                  // duplicate of row 2
    ]));

    expect($preview['status'])->toBe('previewed')
        ->and($preview['total_rows'])->toBe(7)
        ->and($preview['will_create'])->toBe(2)
        ->and($preview['will_update'])->toBe(0)
        ->and($preview['rejected'])->toBe(5)
        ->and($preview['breakdown']['businesses_new'])->toBe(1)
        ->and($preview['breakdown']['permits_new'])->toBe(2)
        ->and($preview['breakdown']['owners_unclaimed'])->toBe(1);

    $kinds = collect($preview['rejects'])->mapWithKeys(fn ($r) => [$r['row'] => collect($r['reasons'])->pluck('kind')->all()]);
    expect($kinds[4])->toContain('bad_date')
        ->and($kinds[5])->toContain('unknown_barangay')
        ->and($kinds[6])->toContain('unknown_permit_type')
        ->and($kinds[7])->toContain('missing_owner')
        ->and($kinds[8])->toContain('duplicate');

    // A dry run is a dry run.
    expect(Business::withTrashed()->count())->toBe($businessesBefore)
        ->and(Permit::count())->toBe($permitsBefore);
});

it('imports businesses unclaimed, with paper permits that have no filing, and audit-logs who, what file and how many', function () {
    $preview = previewAsAdmin(legacyCsv([
        legacyRow(),
        legacyRow(['legacy_permit_id' => 'P-2', 'permit_type' => 'Sanitary Permit', 'permit_number' => 'OLD-HC-1', 'valid_from' => '01/02/2020', 'valid_until' => '12/31/2020']),
    ], 'bplo-2025.csv'));

    $done = runImport($preview['id']);
    expect($done['status'])->toBe('completed')
        ->and($done['created_count'])->toBe(2);

    $business = Business::where('legacy_id', 'B-1')->firstOrFail();
    expect($business->owner_user_id)->toBeNull()
        ->and($business->legacyOwner->last_name)->toBe('Dimaculangan')
        ->and($business->ban)->toStartWith('BP-')
        ->and($business->address->barangay->name)->toBe('Acacia');

    $permits = Permit::where('business_id', $business->id)->orderBy('legacy_id')->get();
    expect($permits)->toHaveCount(2)
        ->and($permits[0]->application_id)->toBeNull()
        ->and($permits[0]->status)->toBe(PermitStatus::Active)
        // No status column given, and the validity ended in 2020.
        ->and($permits[1]->status)->toBe(PermitStatus::Expired)
        // A slashed date is read month first.
        ->and($permits[1]->valid_from->toDateString())->toBe('2020-01-02');

    $audit = AuditLog::where('action', 'legacy_import.completed')->latest('id')->firstOrFail();
    expect($audit->user_id)->toBe(User::where('email', 'admin@biztrack.local')->value('id'))
        ->and($audit->changes['file_name'])->toBe('bplo-2025.csv')
        ->and($audit->changes['will_create'])->toBe(2)
        ->and($audit->changes['rejected'])->toBe(0);

    // The upload held owners' personal data; it goes once the run is done.
    expect(LegacyImport::find($preview['id'])->stored_path)->toBeNull();
});

it('dates an imported business by its earliest permit on the old register, not by the day of the import', function () {
    $monthBefore = collect(DashboardAnalytics::build()['business_movement']['rows'])->last()['registered'];

    runImport(previewAsAdmin(legacyCsv([
        legacyRow(),
        legacyRow(['legacy_permit_id' => 'P-2', 'permit_type' => 'SANITARY', 'permit_number' => 'OLD-HC-1', 'valid_from' => '01/02/2020', 'valid_until' => '12/31/2020']),
        // No permit on the row: nothing older than the import to go on.
        legacyRow(['legacy_business_id' => 'B-2', 'business_name' => 'Walang Permit', 'legacy_permit_id' => '', 'permit_type' => '', 'permit_number' => '', 'valid_from' => '', 'valid_until' => '']),
    ]))['id']);

    expect(Business::where('legacy_id', 'B-1')->firstOrFail()->created_at->toDateString())->toBe('2020-01-02')
        ->and(Business::where('legacy_id', 'B-2')->firstOrFail()->created_at->isToday())->toBeTrue();

    // A re-import carrying only a later permit does not make it younger.
    runImport(previewAsAdmin(legacyCsv([legacyRow(['legacy_permit_id' => 'P-3', 'permit_number' => 'OLD-MP-2026-0001', 'valid_from' => '2026-01-10'])]))['id']);
    expect(Business::where('legacy_id', 'B-1')->firstOrFail()->created_at->toDateString())->toBe('2020-01-02');

    // So the import is one new business this month (B-2), not a spike of three.
    expect(collect(DashboardAnalytics::build()['business_movement']['rows'])->last()['registered'] - $monthBefore)->toBe(1);
});

it('updates on re-import instead of adding a second copy', function () {
    runImport(previewAsAdmin(legacyCsv([legacyRow()]))['id']);

    $again = previewAsAdmin(legacyCsv([legacyRow(['business_name' => 'Sari-sari ng Bayan (Renamed)', 'valid_until' => '2098-06-30'])]));
    expect($again['will_create'])->toBe(0)
        ->and($again['will_update'])->toBe(1);

    runImport($again['id']);

    expect(Business::where('legacy_id', 'B-1')->count())->toBe(1)
        ->and(Business::where('legacy_id', 'B-1')->value('name'))->toBe('Sari-sari ng Bayan (Renamed)')
        ->and(Permit::where('legacy_id', 'P-1')->count())->toBe(1)
        ->and(Permit::where('legacy_id', 'P-1')->first()->valid_until->toDateString())->toBe('2098-06-30');
});

/*
 * A re-import refreshes the old register's record; it does not overrule
 * BizTrack's (admin-audit-import row 41). The status column was written on
 * every update, so a permit the super admin had since revoked went back to
 * active the next time the same export was loaded, and /verify called it
 * valid again — as did a permit a renewal had superseded.
 */
it('never brings a revoked or superseded permit back into force on re-import', function () {
    $rows = [
        legacyRow(),
        legacyRow(['legacy_business_id' => 'B-2', 'business_name' => 'Second Store', 'legacy_permit_id' => 'P-2', 'permit_number' => 'OLD-MP-2025-0002']),
    ];
    runImport(previewAsAdmin(legacyCsv($rows))['id']);

    $revoked = Permit::where('legacy_id', 'P-1')->firstOrFail();
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/permits/{$revoked->id}/revoke", ['reason' => 'Closed after inspection.'])
        ->assertOk();
    $superseded = Permit::where('legacy_id', 'P-2')->firstOrFail();
    $superseded->update(['status' => PermitStatus::Superseded]); // as a renewal leaves it

    // The same export, loaded again with a later expiry — the documented way to update.
    $rows[0]['valid_until'] = '2098-06-30';
    runImport(previewAsAdmin(legacyCsv($rows))['id']);

    expect($revoked->fresh()->status)->toBe(PermitStatus::Revoked)
        ->and($revoked->fresh()->valid_until->toDateString())->toBe('2098-06-30')
        ->and($superseded->fresh()->status)->toBe(PermitStatus::Superseded)
        ->and(test()->getJson('/api/v1/verify/OLD-MP-2025-0001')->json('data.is_valid'))->toBeFalse();
});

// Nor does an old file saying "active" lift a suspension BizTrack recorded.
it('never lifts a suspension on re-import', function () {
    $rows = [legacyRow()];
    runImport(previewAsAdmin(legacyCsv($rows))['id']);

    $permit = Permit::where('legacy_id', 'P-1')->firstOrFail();
    $permit->update(['status' => PermitStatus::Suspended]); // as a business suspension leaves it

    $rows[0]['valid_until'] = '2098-06-30';
    runImport(previewAsAdmin(legacyCsv($rows))['id']);

    expect($permit->fresh()->status)->toBe(PermitStatus::Suspended)
        ->and($permit->fresh()->valid_until->toDateString())->toBe('2098-06-30');
});

it('rejects a permit number BizTrack already issued, and a business account number already taken', function () {
    $taken = Permit::whereNull('legacy_id')->firstOrFail();
    $takenBan = Business::whereNull('legacy_id')->whereNotNull('ban')->value('ban');

    $preview = previewAsAdmin(legacyCsv([
        legacyRow(['permit_number' => $taken->permit_number]),
        legacyRow(['legacy_business_id' => 'B-9', 'legacy_permit_id' => 'P-9', 'permit_number' => 'X-9', 'business_account_no' => $takenBan]),
    ]));

    expect($preview['rejected'])->toBe(2)
        ->and(collect($preview['rejects'])->every(fn ($r) => collect($r['reasons'])->contains('kind', 'duplicate')))->toBeTrue();
});

it('links the business straight to an existing owner account when the email is theirs', function () {
    $owner = User::where('email', 'juan@biztrack.local')->firstOrFail();

    $preview = previewAsAdmin(legacyCsv([legacyRow(['owner_email' => 'JUAN@biztrack.local', 'owner_last_name' => 'Ramos'])]));
    expect($preview['breakdown']['owners_linked'])->toBe(1);

    runImport($preview['id']);

    expect(Business::where('legacy_id', 'B-1')->value('owner_user_id'))->toBe($owner->id);
});

it('keeps an owner-held business’s details when the old export is re-imported, but still updates its permits', function () {
    runImport(previewAsAdmin(legacyCsv([legacyRow()]))['id']);
    Business::where('legacy_id', 'B-1')->update(['owner_user_id' => User::where('email', 'juan@biztrack.local')->value('id')]);

    runImport(previewAsAdmin(legacyCsv([legacyRow(['business_name' => 'Stale name', 'valid_until' => '2097-01-01'])]))['id']);

    expect(Business::where('legacy_id', 'B-1')->value('name'))->toBe('Sari-sari ng Bayan')
        ->and(Permit::where('legacy_id', 'P-1')->first()->valid_until->toDateString())->toBe('2097-01-01');
});

it('writes in chunks, so a file longer than one chunk imports whole', function () {
    $rows = [];
    for ($i = 1; $i <= 450; $i++) {
        $rows[] = legacyRow(['legacy_business_id' => "C-{$i}", 'legacy_permit_id' => "CP-{$i}", 'permit_number' => "CHUNK-{$i}"]);
    }

    $done = runImport(previewAsAdmin(legacyCsv($rows))['id']);

    expect($done['created_count'])->toBe(450)
        ->and(Business::where('legacy_id', 'like', 'C-%')->count())->toBe(450);
});

it('queues a large import instead of running it in the request', function () {
    Queue::fake();
    $rows = [];
    for ($i = 1; $i <= 1001; $i++) {
        $rows[] = legacyRow(['legacy_business_id' => "Q-{$i}", 'legacy_permit_id' => "QP-{$i}", 'permit_number' => "QUEUE-{$i}"]);
    }

    $done = runImport(previewAsAdmin(legacyCsv($rows))['id']);

    expect($done['status'])->toBe('queued');
    Queue::assertPushed(RunLegacyImport::class);
});

it('will not start the same import twice', function () {
    $id = previewAsAdmin(legacyCsv([legacyRow()]))['id'];
    runImport($id);

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/legacy-imports/{$id}/run")
        ->assertStatus(409);
});

it('refuses a file missing a column the import needs, and says which', function () {
    $file = UploadedFile::fake()->createWithContent('bad.csv', "legacy_business_id,business_name\nB-1,Shop\n");

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->post('/api/v1/admin/legacy-imports/csv', ['file' => $file], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'owner_last_name') && str_contains($m, 'barangay'));
});

it('rejects the template’s own example row', function () {
    $preview = previewAsAdmin(legacyCsv([Template::EXAMPLE]));

    expect($preview['rejected'])->toBe(1);
});

// ── Claiming at sign-up ──────────────────────────────────────────────────

// Not `registration()`: Pest helpers are global, and EmailCodeTest owns that name.
function claimRegistration(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Maria',
        'last_name' => 'Dimaculangan',
        'gender' => 'F',
        'email' => 'maria.d@example.test',
        'mobile_number' => '09171234567',
        'password' => 'long-enough-1',
        'password_confirmation' => 'long-enough-1',
        'data_privacy_consent' => true,
        ...homeAddress(),
    ], $overrides);
}

it('lets the owner claim every business the old register holds under them, at sign-up, by permit number and surname', function () {
    runImport(previewAsAdmin(legacyCsv([
        legacyRow(['owner_legacy_id' => 'O-1']),
        legacyRow(['legacy_business_id' => 'B-2', 'business_name' => 'Second Shop', 'owner_legacy_id' => 'O-1', 'legacy_permit_id' => 'P-2', 'permit_number' => 'OLD-2']),
    ]))['id']);
    app('auth')->forgetGuards();

    test()->postJson('/api/v1/auth/register', claimRegistration(['claim_number' => 'old-mp-2025-0001']))->assertCreated();

    $user = User::where('email', 'maria.d@example.test')->firstOrFail();
    expect(Business::whereIn('legacy_id', ['B-1', 'B-2'])->pluck('owner_user_id')->unique()->all())->toBe([$user->id])
        ->and(LegacyOwner::where('legacy_id', 'O-1')->value('claimed_by_user_id'))->toBe($user->id)
        ->and(AuditLog::where('action', 'business.claimed')->count())->toBe(2);
});

it('refuses a claim whose surname does not match, before creating the account', function () {
    runImport(previewAsAdmin(legacyCsv([legacyRow()]))['id']);
    app('auth')->forgetGuards();

    test()->postJson('/api/v1/auth/register', claimRegistration(['last_name' => 'Santos', 'claim_number' => 'OLD-MP-2025-0001']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('claim_number');

    expect(User::where('email', 'maria.d@example.test')->exists())->toBeFalse()
        ->and(Business::where('legacy_id', 'B-1')->value('owner_user_id'))->toBeNull();
});

it('lets a signed-in owner claim by business account number, against their own surname', function () {
    runImport(previewAsAdmin(legacyCsv([legacyRow(['owner_last_name' => 'Ramos', 'business_account_no' => 'OLD-ACC-77'])]))['id']);

    test()->withHeaders(authAs('juan@biztrack.local'))
        ->postJson('/api/v1/businesses/claim', ['claim_number' => 'OLD-ACC-77'])
        ->assertOk()
        ->assertJsonPath('data.claimed', 1);

    expect(Business::where('legacy_id', 'B-1')->value('owner_user_id'))->toBe(User::where('email', 'juan@biztrack.local')->value('id'));
});

// ── The artisan equivalent ───────────────────────────────────────────────

it('dry-runs from the command line without writing, then imports with --force', function () {
    $path = tempnam(sys_get_temp_dir(), 'legacy').'.csv';
    file_put_contents($path, legacyCsv([legacyRow()])->getContent());

    expect(Artisan::call('biztrack:import-legacy', ['file' => $path, '--dry-run' => true]))->toBe(0);
    expect(Business::where('legacy_id', 'B-1')->exists())->toBeFalse();

    expect(Artisan::call('biztrack:import-legacy', ['file' => $path, '--force' => true]))->toBe(0);
    expect(Business::where('legacy_id', 'B-1')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'legacy_import.completed')->latest('id')->first()->changes['via'])->toBe('artisan biztrack:import-legacy');

    // The command never deletes the operator's own file.
    expect(is_file($path))->toBeTrue();
    unlink($path);
});
