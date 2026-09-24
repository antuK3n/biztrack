<?php

use App\Enums\PermitStatus;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationOfficeForm;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;

/*
 * The administrator's permit register — one wide table, every detail an office
 * needs, per office.
 *
 * The client's ask, in their words: "make it ilagay lahat sa isang mahabang
 * table pahaba left to right ... make sure na mailagay ang lahat ng info about
 * sa permit na kailangan sa kada office pati mga finill outan kada permit ...
 * at mauuna ang BAN".
 *
 * PermitRegisterFilterTest already proves `q` and `status` cannot reach around
 * the office boundary. This file is about what the row CARRIES and how it can
 * be ordered, and it asks the same question of every new capability: does
 * widening the payload, the search or the sort ever widen who may see it?
 */

/** The reader every assertion about payload shape uses. */
function registerRows(array $query = [], string $as = 'admin@biztrack.local'): array
{
    $qs = http_build_query(array_merge(['detail' => 1, 'per_page' => 50], $query));

    return test()->withHeaders(authAs($as))
        ->getJson('/api/v1/permits?'.$qs)
        ->assertOk()
        ->json('data');
}

it('leads the row with the BAN, the number the business is filed under', function () {
    /*
     * BAN first is the client's explicit ordering, and it is the only one of
     * the three identifiers that is stable: a permit number names one
     * certificate and a tracking ID names one filing, while the BAN names the
     * business behind all of them.
     */
    $permit = Permit::with('business')->whereHas('business')->firstOrFail();

    $row = collect(registerRows())->firstWhere('id', $permit->id);

    expect($row)->not->toBeNull()
        ->and($row['ban'])->toBe($permit->business->ban);

    // And it is FIRST in the payload, not merely present — the table reads the
    // keys in order and the client asked for this one to lead.
    expect(array_key_first($row))->toBe('ban');
});

it('carries the certificate face as it was signed, not as the register reads today', function () {
    /*
     * A permit is a snapshot. Printing today's address beside a certificate
     * issued under the old one would be quietly wrong about a legal document,
     * which is the whole reason `permits.issued_details` exists.
     */
    $permit = Permit::whereNotNull('issued_details')->first()
        ?? Permit::firstOrFail();

    $row = collect(registerRows())->firstWhere('id', $permit->id);

    expect($row)->toHaveKey('face');
    foreach (['business_name', 'trade_name', 'owner_name', 'address', 'barangay', 'city', 'line_of_business'] as $key) {
        expect($row['face'])->toHaveKey($key);
    }

    $frozen = $permit->issued_details;
    if (is_array($frozen) && ($frozen['business_name'] ?? null) !== null) {
        expect($row['face']['business_name'])->toBe($frozen['business_name']);
    }
});

it('carries the record: who signed it, when, and what it replaced', function () {
    $permit = Permit::firstOrFail();

    $row = collect(registerRows())->firstWhere('id', $permit->id);

    foreach (['valid_from', 'valid_until', 'issued_at', 'issued_by', 'prior_permit_number', 'revoked_at', 'revoked_reason'] as $key) {
        expect($row)->toHaveKey($key);
    }
});

it('carries the office sheet for a permit whose office asks for one', function () {
    /*
     * The part the client asked for by name — "pati mga finill outan kada
     * permit". Five of the six permit types have a sheet; this proves the
     * answers reach the table.
     */
    $type = PermitType::whereIn('code', PermitType::OFFICE_FORM_CODES)->firstOrFail();
    $permit = Permit::where('permit_type_id', $type->id)->with('application')->first();

    if ($permit === null) {
        // The live fixtures do not always carry one of every type. Make the
        // case rather than skip it: a test that quietly passes on absent data
        // is a test that stops covering the thing it names.
        $permit = Permit::with('application')->firstOrFail();
        $permit->forceFill(['permit_type_id' => $type->id])->save();
    }

    ApplicationOfficeForm::updateOrCreate(
        ['application_id' => $permit->application_id, 'permit_type_id' => $type->id],
        ['form_data' => ['sanitary_classification' => 'Food Establishment', 'water_source' => 'Deep Well']],
    );

    $row = collect(registerRows())->firstWhere('id', $permit->id);

    expect($row['office_form'])->toBeArray();
    // The SAVED answers are there…
    expect($row['office_form']['sanitary_classification'] ?? null)->toBe('Food Establishment');
    /*
     * …and so are the DERIVED ones. OfficeFormAnswers fills in what nobody
     * types — the filing date above all — and a table reading the raw column
     * would show those boxes blank on a sheet the paper prints filled.
     */
    expect($row['office_form'])->toHaveKey('application_date');
});

it('says null, not an empty sheet, for an office that asks for no form', function () {
    /*
     * "This office asks nothing" and "this office asked and was not answered"
     * are different facts, and the table prints each differently. The Mayor's
     * Permit is the one type with no sheet.
     */
    $business = PermitType::where('code', 'BUSINESS')->firstOrFail();
    $permit = Permit::where('permit_type_id', $business->id)->first();

    if ($permit === null) {
        $permit = Permit::firstOrFail();
        $permit->forceFill(['permit_type_id' => $business->id])->save();
    }

    $row = collect(registerRows())->firstWhere('id', $permit->id);

    expect($row['office_form'])->toBeNull();
});

it('leaves the contracted payload alone when detail is not asked for', function () {
    /*
     * docs/api-contract.md names PermitResource's eleven keys and the owner's
     * Profile reads them. The register's extra must not arrive by default, or
     * every caller pays for four eager loads and an office sheet they did not
     * ask for.
     */
    $rows = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/permits?per_page=5')
        ->assertOk()
        ->json('data');

    expect($rows)->not->toBeEmpty();
    foreach (['ban', 'face', 'office_form', 'issued_by'] as $key) {
        expect($rows[0])->not->toHaveKey($key);
    }
    // The contracted keys are all still there.
    foreach (['id', 'permit_number', 'status', 'status_label', 'valid_until', 'verify_url'] as $key) {
        expect($rows[0])->toHaveKey($key);
    }
});

it('narrows to one office by permit type', function () {
    $type = PermitType::whereKey(Permit::query()->value('permit_type_id'))->firstOrFail();

    $rows = registerRows(['permit_type' => $type->code]);

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row['permit_type']['code'])->toBe($type->code);
    }
});

it('refuses an office that is not a permit type', function () {
    /*
     * MARKET was a permit type until 6 September 2026. A hand-written `in:`
     * list would still accept it and answer an empty table; `exists` says the
     * office is gone.
     */
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/permits?detail=1&permit_type=MARKET')
        ->assertStatus(422);
});

it('orders by a column the caller names, in the direction they ask for', function () {
    $asc = collect(registerRows(['sort' => 'permit_number', 'dir' => 'asc']))->pluck('permit_number')->all();
    $desc = collect(registerRows(['sort' => 'permit_number', 'dir' => 'desc']))->pluck('permit_number')->all();

    expect($asc)->not->toBeEmpty();

    $sorted = $asc;
    sort($sorted);
    expect($asc)->toBe($sorted);
    expect($desc)->toBe(array_reverse($sorted));
});

it('orders by a column that lives on another table', function () {
    /*
     * The BAN and the business name are on `businesses`, and the sort is a
     * correlated subquery rather than a join — a join would multiply rows
     * against the hasMany relations the detail payload also loads.
     */
    $rows = registerRows(['sort' => 'ban', 'dir' => 'asc']);

    $bans = collect($rows)->pluck('ban')->filter()->values()->all();
    $sorted = $bans;
    sort($sorted);
    expect($bans)->toBe($sorted);
});

it('refuses a sort key it does not own', function () {
    /*
     * The whitelist is the point. `orderBy($request->query('sort'))` would put
     * a column name from the query string into SQL; a rejected key says so
     * rather than being dropped in silence, which is a control that looks like
     * it works.
     */
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/permits?detail=1&sort=(select+1)')
        ->assertStatus(422);

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/permits?detail=1&sort=permit_number&dir=sideways')
        ->assertStatus(422);
});

it('keeps the default order when no sort is named', function () {
    // Newest issuance first, exactly as this endpoint has always answered.
    $rows = registerRows();

    $issued = collect($rows)->pluck('issued_at')->filter()->values()->all();
    $sorted = $issued;
    rsort($sorted);
    expect($issued)->toBe($sorted);
});

it('finds a permit by its BAN and by its owner name', function () {
    $permit = Permit::with('business.owner')
        ->whereHas('business', fn ($b) => $b->whereNotNull('ban')->whereHas('owner'))
        ->firstOrFail();

    $byBan = collect(registerRows(['q' => $permit->business->ban]))->pluck('id')->all();
    expect($byBan)->toContain($permit->id);

    $byOwner = collect(registerRows(['q' => $permit->business->owner->last_name]))->pluck('id')->all();
    expect($byOwner)->toContain($permit->id);
});

/**
 * One permit of each named type, on the same filing.
 *
 * Built rather than found. The seeded register carries two permits and BOTH
 * are BPLO's, so a boundary test written against it reads zero rows for every
 * other office and its `foreach` asserts nothing at all — it passes because
 * there is nothing to check, which is the worst way for a security test to
 * look green. A probe printed `cenro -> 0` and that is how this was caught.
 *
 * @param  array<int, string>  $codes
 * @return array<string, Permit>
 */
function permitPerOffice(array $codes): array
{
    $seed = Permit::with('application')->firstOrFail();
    $made = [];

    foreach ($codes as $code) {
        $type = PermitType::where('code', $code)->firstOrFail();

        /*
         * The filing has to be ROUTED to the issuing office as well as the
         * permit belonging to it. `scopeToReader` asks both questions — the
         * office must be able to read the application (ApplicationVisibility
         * matches on an assignment) AND must be the permit type's issuer — so
         * a permit created without the assignment is invisible to everyone
         * and the test would go green having proved nothing.
         */
        ApplicationAssignment::firstOrCreate([
            'application_id' => $seed->application_id,
            'department_id' => $type->issuing_department_id,
        ]);

        $made[$code] = Permit::create([
            'permit_number' => 'TEST-'.$code.'-0001',
            'application_id' => $seed->application_id,
            'business_id' => $seed->business_id,
            'permit_type_id' => $type->id,
            'status' => $seed->status,
            'valid_from' => $seed->valid_from,
            'valid_until' => $seed->valid_until,
            'issued_at' => $seed->issued_at,
        ]);
    }

    return $made;
}

it('will not let the wider payload reach a permit the office may not read', function () {
    /*
     * The question this whole file keeps asking. `detail=1` adds the office
     * sheet, the owner's name and the certificate face — every one of them
     * more of the filing than the list carried before — so the boundary is
     * worth proving again at the wider payload rather than assuming it rides
     * along.
     */
    $made = permitPerOffice(['CEC', 'FSIC']);

    $mine = registerRows([], 'cenro@biztrack.local');
    $ids = collect($mine)->pluck('id')->all();

    // The office reads its OWN certificate…
    expect($ids)->toContain($made['CEC']->id);
    // …and not the fire office's, on the very same filing.
    expect($ids)->not->toContain($made['FSIC']->id);

    // Every row it did get is its own, and there was something to check.
    $cenro = User::where('email', 'cenro@biztrack.local')->firstOrFail();
    expect($mine)->not->toBeEmpty();
    foreach ($mine as $row) {
        $permit = Permit::with('permitType')->findOrFail($row['id']);
        expect($permit->permitType?->issuing_department_id)->toBe($cenro->department_id);
    }

    expect(count($mine))->toBeLessThan(Permit::count());
});

it('will not let a search reach a permit the office may not read', function () {
    /*
     * `q` grew to match the BAN, the owner and the permit type. All four of
     * those are shared by every permit on a filing, so a CENRO search on the
     * business's own BAN is the shortest path to the fire office's
     * certificate — if the filter were applied instead of the scope rather
     * than after it.
     */
    $made = permitPerOffice(['CEC', 'FSIC']);
    $ban = Permit::with('business')->findOrFail($made['CEC']->id)->business?->ban;

    expect($ban)->not->toBeNull();

    $ids = collect(registerRows(['q' => $ban], 'cenro@biztrack.local'))->pluck('id')->all();

    expect($ids)->toContain($made['CEC']->id);
    expect($ids)->not->toContain($made['FSIC']->id);
});

it('will not let an office filter hand one office another office’s permits', function () {
    /*
     * The new `permit_type` filter NAMES an office. Asking for the fire
     * office's certificates as CENRO must answer an empty table, never the
     * fire office's rows.
     */
    $made = permitPerOffice(['CEC', 'FSIC']);

    $rows = registerRows(['permit_type' => 'FSIC'], 'cenro@biztrack.local');

    expect(collect($rows)->pluck('id')->all())->not->toContain($made['FSIC']->id);
    expect($rows)->toBeEmpty();
});

/*
 * ── Every office, not two of them ──────────────────────────────────────────
 *
 * The client: "make sure kung anong permit lang ang pinasa sa office na yon
 * inapply ayon lalabas page ng permit nila sa admin offices ng bawat offices."
 *
 * The rule was already covered for CENRO here and for CENRO and CHO in
 * PermitOfficeScopingTest — two of the six. "Bawat offices" is six, and the
 * four that were never asserted are exactly where a scoping defect would sit
 * unnoticed. This sweeps all five clearance offices in one pass, against a
 * filing carrying one certificate of every type, so each office is asked the
 * question with four foreign certificates sitting beside its own.
 */
it('shows each office only the certificate its own office issued', function () {
    $made = permitPerOffice(['SANITARY', 'FSIC', 'ZONING', 'CEC', 'OCCUPANCY']);

    $offices = [
        'sanitary@biztrack.local' => 'SANITARY',
        'fire@biztrack.local' => 'FSIC',
        'zoning@biztrack.local' => 'ZONING',
        'cenro@biztrack.local' => 'CEC',
        'obo@biztrack.local' => 'OCCUPANCY',
    ];

    foreach ($offices as $email => $ownCode) {
        $rows = registerRows([], $email);

        /*
         * It reads its own certificate on this filing.
         *
         * `toContain` is VARIADIC in Pest — every argument is another value
         * the array must hold — so a message passed as a second argument is
         * asserted as a value and the failure reads as if the sentence itself
         * were missing from the register. The context goes in a variable the
         * assertion below can carry instead.
         */
        expect(collect($rows)->pluck('id')->all())->toContain($made[$ownCode]->id);

        // …and NOTHING of any other type, here or anywhere else in the register.
        $types = collect($rows)->pluck('permit_type.code')->unique()->values()->all();
        expect($types)->toBe([$ownCode]);
    }
});

it('keeps every office inside its own certificates through the filters too', function () {
    /*
     * The narrowing controls are the shortest path around a boundary: a filter
     * applied INSTEAD of the scope rather than after it turns the office wall
     * into a query string. Each office is asked for another office's
     * certificates by name, sorted, and searched on a value every permit on
     * the filing shares.
     */
    $made = permitPerOffice(['SANITARY', 'FSIC', 'ZONING', 'CEC', 'OCCUPANCY']);
    $ban = Permit::with('business')->findOrFail($made['CEC']->id)->business?->ban;

    $offices = [
        'sanitary@biztrack.local' => 'SANITARY',
        'fire@biztrack.local' => 'FSIC',
        'zoning@biztrack.local' => 'ZONING',
        'cenro@biztrack.local' => 'CEC',
        'obo@biztrack.local' => 'OCCUPANCY',
    ];

    foreach ($offices as $email => $ownCode) {
        $probes = [
            // Ask for a type that is not yours, by name.
            ['permit_type' => $ownCode === 'CEC' ? 'FSIC' : 'CEC'],
            // Reorder the whole register.
            ['sort' => 'ban', 'dir' => 'asc'],
            // Search a value every certificate on the filing shares.
            ['q' => $ban],
        ];

        foreach ($probes as $probe) {
            $types = collect(registerRows($probe, $email))
                ->pluck('permit_type.code')
                ->unique()
                ->values()
                ->all();

            expect(array_diff($types, [$ownCode]))->toBe([]);
        }
    }
});

it('keeps BPLO reading the whole register, which is deliberate', function () {
    /*
     * The one documented exception, asserted so it stays a decision rather
     * than becoming an accident.
     *
     * BPLO issues the Mayor's Permit and coordinates every other office's
     * clearance — its final approval is gated on all five — so it carries
     * `application.view_any_office` and reads every certificate. See
     * ApplicationVisibility's header. Confirmed as intended on 24 September
     * 2026 when the register table was audited office by office.
     *
     * If this ever needs to change, it is a change to that permission and to
     * every screen reading through it, not a filter added here.
     */
    permitPerOffice(['SANITARY', 'FSIC', 'ZONING', 'CEC', 'OCCUPANCY']);

    $types = collect(registerRows([], 'bplo@biztrack.local'))
        ->pluck('permit_type.code')
        ->unique();

    expect($types->count())->toBeGreaterThan(1);
    expect($types->all())->toContain('SANITARY');
    expect($types->all())->toContain('FSIC');
});

/*
 * ── Expiring soon, and the issuance window ────────────────────────────────
 *
 * The two questions an office asks of its own certificates that neither the
 * status nor the search answers: what lapses this month, and what was issued
 * in a given period.
 */

it('lists only what lapses inside the window, and only what is still in force', function () {
    $seed = Permit::with('application')->firstOrFail();

    /* One of each case, so every branch of the filter is exercised. */
    $soon = Permit::create([
        'permit_number' => 'TEST-SOON-1',
        'application_id' => $seed->application_id,
        'business_id' => $seed->business_id,
        'permit_type_id' => $seed->permit_type_id,
        'status' => PermitStatus::Active,
        'valid_from' => now()->subYear(),
        'valid_until' => now()->addDays(10),
        'issued_at' => now()->subYear(),
    ]);

    $later = Permit::create([
        'permit_number' => 'TEST-LATER-1',
        'application_id' => $seed->application_id,
        'business_id' => $seed->business_id,
        'permit_type_id' => $seed->permit_type_id,
        'status' => PermitStatus::Active,
        'valid_from' => now()->subYear(),
        'valid_until' => now()->addDays(120),
        'issued_at' => now()->subYear(),
    ]);

    $gone = Permit::create([
        'permit_number' => 'TEST-GONE-1',
        'application_id' => $seed->application_id,
        'business_id' => $seed->business_id,
        'permit_type_id' => $seed->permit_type_id,
        'status' => PermitStatus::Expired,
        'valid_from' => now()->subYears(2),
        'valid_until' => now()->subDays(5),
        'issued_at' => now()->subYears(2),
    ]);

    /*
     * A superseded certificate INSIDE its own term. This is the case the
     * status condition exists for: by date alone it is expiring in ten days,
     * and listing it would send an office chasing a renewal that has already
     * happened.
     */
    $replaced = Permit::create([
        'permit_number' => 'TEST-SUPER-1',
        'application_id' => $seed->application_id,
        'business_id' => $seed->business_id,
        'permit_type_id' => $seed->permit_type_id,
        'status' => PermitStatus::Superseded,
        'valid_from' => now()->subYear(),
        'valid_until' => now()->addDays(10),
        'issued_at' => now()->subYear(),
    ]);

    $ids = collect(registerRows(['expiring_within' => 30]))->pluck('id')->all();

    expect($ids)->toContain($soon->id);
    expect($ids)->not->toContain($later->id);
    // Already lapsed is not "expiring" — that is what the status filter asks.
    expect($ids)->not->toContain($gone->id);
    // Replaced early is not the certificate in force.
    expect($ids)->not->toContain($replaced->id);
});

it('counts a certificate lapsing today as expiring', function () {
    /*
     * `valid_until` is a DATE column. Comparing it against `now()` — a
     * datetime partway through the day — drops everything expiring today,
     * which is the one day an office most needs to see.
     */
    $seed = Permit::firstOrFail();

    $today = Permit::create([
        'permit_number' => 'TEST-TODAY-1',
        'application_id' => $seed->application_id,
        'business_id' => $seed->business_id,
        'permit_type_id' => $seed->permit_type_id,
        'status' => PermitStatus::Active,
        'valid_from' => now()->subYear(),
        'valid_until' => now(),
        'issued_at' => now()->subYear(),
    ]);

    expect(collect(registerRows(['expiring_within' => 1]))->pluck('id')->all())
        ->toContain($today->id);
});

it('refuses a window that selects nothing or everything', function () {
    $admin = authAs('admin@biztrack.local');

    // Zero days is not a window.
    test()->withHeaders($admin)->getJson('/api/v1/permits?detail=1&expiring_within=0')->assertStatus(422);
    // Beyond a year it selects the register and reads as a filter that did nothing.
    test()->withHeaders($admin)->getJson('/api/v1/permits?detail=1&expiring_within=400')->assertStatus(422);
});

it('lists what was issued inside the dates asked for, both ends inclusive', function () {
    $seed = Permit::firstOrFail();

    $inside = Permit::create([
        'permit_number' => 'TEST-IN-1',
        'application_id' => $seed->application_id,
        'business_id' => $seed->business_id,
        'permit_type_id' => $seed->permit_type_id,
        'status' => PermitStatus::Active,
        'valid_from' => '2026-03-15',
        'valid_until' => '2027-03-15',
        /*
         * Late in the day on the LAST date of the range. `issued_at` is a
         * datetime and the bound is a date, so a bare comparison would put
         * this outside a range that names its own day — the defect the
         * endOfDay() in the controller exists to prevent.
         */
        'issued_at' => '2026-03-31 22:45:00',
    ]);

    $before = Permit::create([
        'permit_number' => 'TEST-BEFORE-1',
        'application_id' => $seed->application_id,
        'business_id' => $seed->business_id,
        'permit_type_id' => $seed->permit_type_id,
        'status' => PermitStatus::Active,
        'valid_from' => '2026-02-01',
        'valid_until' => '2027-02-01',
        'issued_at' => '2026-02-28 09:00:00',
    ]);

    $ids = collect(registerRows(['issued_from' => '2026-03-01', 'issued_to' => '2026-03-31']))
        ->pluck('id')->all();

    expect($ids)->toContain($inside->id);
    expect($ids)->not->toContain($before->id);
});

it('refuses an issuance window that ends before it begins', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/permits?detail=1&issued_from=2026-09-30&issued_to=2026-09-01')
        ->assertStatus(422);
});

it('will not let the new filters reach another office’s certificate', function () {
    /*
     * The question this file keeps asking of every control it adds. Both
     * filters are applied AFTER the reader's scope; asked instead of it, a
     * CENRO session could reach the fire office's certificate by naming a date
     * they share.
     */
    $made = permitPerOffice(['CEC', 'FSIC']);

    foreach ([['expiring_within' => 365], ['issued_from' => '2000-01-01'], ['issued_to' => '2099-12-31']] as $probe) {
        $types = collect(registerRows($probe, 'cenro@biztrack.local'))
            ->pluck('permit_type.code')
            ->unique()
            ->values()
            ->all();

        expect(array_diff($types, ['CEC']))->toBe([]);
    }

    expect(collect(registerRows(['issued_to' => '2099-12-31'], 'cenro@biztrack.local'))->pluck('id')->all())
        ->not->toContain($made['FSIC']->id);
});

it('will not let a sort widen an office past its own permits', function () {
    permitPerOffice(['CEC', 'FSIC']);

    $sorted = registerRows(['sort' => 'ban', 'dir' => 'asc'], 'cenro@biztrack.local');
    $plain = registerRows([], 'cenro@biztrack.local');

    expect($plain)->not->toBeEmpty();
    expect(collect($sorted)->pluck('id')->sort()->values()->all())
        ->toBe(collect($plain)->pluck('id')->sort()->values()->all());
});
