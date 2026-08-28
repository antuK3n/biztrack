<?php

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\PermitStatus;
use App\Models\AnalyticsSnapshot;
use App\Models\Application;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\BusinessAddress;
use App\Models\Permit;
use App\Models\PermitExpiryNotice;
use App\Models\PermitType;
use App\Support\RenewalRiskAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/*
 * The two things the client asked the Renewal Risk screen to grow: a follow-up
 * an officer can actually send, and a table that can be pointed at something
 * other than its own worst rows.
 *
 * AnalyticsForecastTest covers the scoring's trip through the register. This
 * file covers what was added on top of it, and the properties here are the ones
 * that are expensive to get wrong:
 *
 *  - a button that reports a send must have sent something, to the right person;
 *  - pressing it twice must not be two messages to a real business owner;
 *  - the summary cards and the filtered table must not be able to disagree;
 *  - and the officer-initiated sends must not quietly inflate a KPI whose
 *    published definition says it counts the nightly scan's notices.
 *
 * Read as BPLO throughout, for the reason given at the top of
 * AnalyticsForecastTest: `analytics.view` is BPLO's alone.
 */

/**
 * Give `$business` a permit expiring in `$days` days (negative = already lapsed).
 *
 * Named apart from AnalyticsForecastTest's `permitExpiringIn` on purpose: Pest
 * shares helper functions across the whole suite, so two files declaring the
 * same name is a fatal error the day the second one loads.
 */
function riskPermit(int $days, ?Business $business = null): Permit
{
    $business ??= Business::whereNotNull('owner_user_id')->firstOrFail();
    $validUntil = CarbonImmutable::now()->startOfDay()->addDays($days);

    return Permit::create([
        'permit_number' => 'FUP-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'application_id' => Application::firstOrFail()->id,
        'business_id' => $business->id,
        'permit_type_id' => PermitType::firstOrFail()->id,
        'status' => $days < 0 ? PermitStatus::Expired->value : PermitStatus::Active->value,
        'valid_from' => $validUntil->subYear()->toDateString(),
        'valid_until' => $validUntil->toDateString(),
        'issued_at' => $validUntil->subYear(),
    ]);
}

/** @return array<string, mixed> */
function riskFeed(string $query = ''): array
{
    return test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/renewal-risk'.$query)
        ->assertOk()
        ->json('data');
}

function remind(Permit $permit, string $email = 'bplo@biztrack.local'): TestResponse
{
    return test()->withHeaders(authAs($email))
        ->postJson("/api/v1/analytics/renewal-risk/{$permit->id}/remind");
}

/* ── the follow-up actually reaches the business owner ─────────────────── */

it('puts a real notification in the business owner’s list', function () {
    Permit::query()->delete();
    $permit = riskPermit(-5);                       // lapsed, nothing filed → high
    $owner = Business::findOrFail($permit->business_id)->owner;

    AppNotification::where('user_id', $owner->id)->delete();

    $response = remind($permit)->assertOk();

    expect($response->json('data.already_sent'))->toBeFalse();
    expect($response->json('data.sent_at'))->not->toBeNull();

    /*
     * The whole point of the button. Asserted on the OWNER's row rather than on
     * a count of notifications, because a message that went to the officer who
     * pressed it, or to nobody, would satisfy a count.
     */
    $note = AppNotification::where('user_id', $owner->id)->latest('id')->first();

    expect($note)->not->toBeNull();
    expect($note->type)->toBe('expiry');
    expect($note->body)->toContain($permit->permit_number);
    // A real route in web/src/App.tsx — see the note on link targets in
    // NotificationService. A notification that lands on the sign-in redirect is
    // a notification that was not delivered.
    expect($note->link)->toBe('/permits');
});

it('records who sent it and when', function () {
    Permit::query()->delete();
    $permit = riskPermit(-5);

    remind($permit)->assertOk();

    $log = AuditLog::where('action', 'permit.renewal_followup_sent')
        ->where('auditable_id', $permit->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    // "The system sent it" must not be an available answer to "who contacted
    // this business".
    expect($log->user_id)->not->toBeNull();
    expect($log->changes['notified_user_id'])->toBe(Business::findOrFail($permit->business_id)->owner_user_id);
    expect($log->changes['action'])->toBe('immediate_follow_up');
});

it('sends the urgent wording only where the row is badged High', function () {
    Permit::query()->delete();

    // 25 days out with no renewal filed lands in the moderate band; lapsed is
    // high. Both notify — only the tone differs.
    $moderate = riskPermit(25);
    remind($moderate)->assertOk();

    $log = AuditLog::where('action', 'permit.renewal_followup_sent')
        ->where('auditable_id', $moderate->id)->latest('id')->first();

    expect($log->changes['action'])->toBe('send_reminder');

    $note = AppNotification::where('user_id', Business::findOrFail($moderate->business_id)->owner_user_id)
        ->latest('id')->first();
    expect($note->title)->toContain('reminder');
});

/* ── it cannot be sent twice ───────────────────────────────────────────── */

it('refuses a second send on the same day rather than messaging twice', function () {
    Permit::query()->delete();
    $permit = riskPermit(-5);
    $owner = Business::findOrFail($permit->business_id)->owner;

    AppNotification::where('user_id', $owner->id)->delete();

    remind($permit)->assertOk()->assertJsonPath('data.already_sent', false);

    /*
     * A double-click, a replayed request, or a second officer on the same row.
     * The guard is the unique index on (permit_id, notice_kind), so it holds
     * across processes rather than only within one browser tab.
     */
    $second = remind($permit)->assertOk();
    expect($second->json('data.already_sent'))->toBeTrue();
    expect($second->json('data.sent_at'))->not->toBeNull();

    // One press, one message. This is the assertion the endpoint exists to keep.
    expect(AppNotification::where('user_id', $owner->id)->count())->toBe(1);
    expect(AuditLog::where('action', 'permit.renewal_followup_sent')
        ->where('auditable_id', $permit->id)->count())->toBe(1);
});

it('lets the same permit be chased again on another day', function () {
    Permit::query()->delete();
    $permit = riskPermit(-5);

    // Yesterday's follow-up must not silence today's: a business ignored for a
    // month has to be chaseable again.
    PermitExpiryNotice::create([
        'permit_id' => $permit->id,
        'notice_kind' => RenewalRiskAnalytics::manualNoticeKind(CarbonImmutable::now()->subDay()),
    ]);

    remind($permit)->assertOk()->assertJsonPath('data.already_sent', false);
});

/* ── what it refuses ───────────────────────────────────────────────────── */

it('will not send on a low-risk permit, because Monitor is not a message', function () {
    Permit::query()->delete();
    // Well inside the horizon and not yet due: the progress rule is off, so
    // this is the Monitor end of the scale.
    $permit = riskPermit(300);

    expect(RenewalRiskAnalytics::bandForPermit($permit->id))->toBe('low');

    remind($permit)->assertStatus(422);

    // Nothing claimed, so tomorrow's genuine follow-up is still possible.
    expect(PermitExpiryNotice::where('permit_id', $permit->id)->count())->toBe(0);
});

it('will not send about a permit the watchlist does not show', function () {
    Permit::query()->delete();
    // Lapsed years ago: outside the grace window, so it is not on the screen and
    // there is no row anyone could have pressed.
    $permit = riskPermit(-400);

    remind($permit)->assertStatus(422);
    expect(PermitExpiryNotice::where('permit_id', $permit->id)->count())->toBe(0);
});

it('will not send to a business that has been closed', function () {
    Permit::query()->delete();
    $permit = riskPermit(-5);
    Business::findOrFail($permit->business_id)->delete();   // soft delete

    // Business soft-deletes, so `$permit->business` resolves to null and the
    // notification would go nowhere while the ledger claimed it had gone out.
    remind($permit)->assertStatus(422);
    expect(PermitExpiryNotice::where('permit_id', $permit->id)->count())->toBe(0);
});

it('is reachable only by the office that holds the watchlist', function () {
    Permit::query()->delete();
    $permit = riskPermit(-5);

    // The super admin included: it lost analytics.view when the screens were
    // split, and a POST route that stayed open would be the way round the split
    // rather than an exception to it.
    foreach (['admin@biztrack.local', 'sanitary@biztrack.local', 'owner@biztrack.local'] as $email) {
        remind($permit, $email)->assertForbidden();
    }

    expect(PermitExpiryNotice::where('permit_id', $permit->id)->count())->toBe(0);
});

it('refuses a caller with no session at all', function () {
    $permit = Permit::firstOrFail();

    // No authAs() anywhere: Sanctum::actingAs would outlive it.
    test()->postJson("/api/v1/analytics/renewal-risk/{$permit->id}/remind")->assertUnauthorized();
});

/* ── the KPI stays what its definition says it is ──────────────────────── */

it('keeps officer follow-ups out of the Reminders Sent count and beside it instead', function () {
    Permit::query()->delete();
    PermitExpiryNotice::query()->delete();
    $permit = riskPermit(-5);

    // What the nightly scan wrote.
    foreach (['threshold_30', 'renewal_due'] as $kind) {
        PermitExpiryNotice::create(['permit_id' => $permit->id, 'notice_kind' => $kind]);
    }

    remind($permit)->assertOk();

    $body = riskFeed();
    $row = collect($body['at_risk'])->firstWhere('permit_id', $permit->id);

    /*
     * `reminders_sent` is the scheduled-notice figure, and AnalyticsDefinitions
     * tells the reader it "reads zero until the nightly permit scan has run".
     * Pooling an officer's follow-up into it would make that sentence false
     * while leaving it on screen, so the two are reported separately and this
     * is where that stays true.
     */
    expect($body['reminders_sent'])->toBe(2);
    expect($row['reminders_sent'])->toBe(2);
    expect($row['manual_reminders'])->toBe(1);
    expect($row['manual_reminder_at'])->not->toBeNull();
});

/* ── the table can be pointed somewhere other than its worst rows ──────── */

it('reaches low-risk businesses, which ranking alone never could', function () {
    Permit::query()->delete();
    riskPermit(-5);         // high
    riskPermit(25);         // moderate
    riskPermit(300);        // low

    $unfiltered = riskFeed('?limit=1');
    // The default is still worst-first, which is the point of the screen.
    expect($unfiltered['at_risk'][0]['band'])->toBe('high');

    $low = riskFeed('?limit=1&band=low');
    expect($low['at_risk'])->toHaveCount(1);
    expect($low['at_risk'][0]['band'])->toBe('low');

    // And the bands stay a description of the whole scored window, not of the
    // one row that came back.
    expect($low['scored_permits'])->toBe(3);
    expect(array_sum($low['counts']))->toBe(3);
});

it('cannot let the band cards and the filtered table disagree', function () {
    Permit::query()->delete();
    foreach ([-5, -2, 25, 28, 300, 320, 340] as $days) {
        riskPermit($days);
    }

    /*
     * The invariant the summary cards live or die by: the count on a card IS
     * the number of rows that filter has. The cards are the legend for the
     * control, so a card reading 2,060 above a filter that yields 25 rows would
     * make both figures worthless.
     */
    foreach (['high', 'moderate', 'low'] as $band) {
        $body = riskFeed("?limit=1&band={$band}");
        expect($body['matching'])->toBe($body['counts'][$band]);
        expect($body['filters']['band'])->toBe($band);
    }

    // The action filter is the same set reached by its other name — the action
    // is a function of the band, not a second judgement.
    foreach (['immediate_follow_up' => 'high', 'send_reminder' => 'moderate', 'monitor' => 'low'] as $action => $band) {
        expect(riskFeed("?limit=1&action={$action}")['matching'])->toBe(riskFeed("?limit=1&band={$band}")['counts'][$band]);
    }
});

it('narrows every figure on the screen when a barangay is chosen', function () {
    Permit::query()->delete();

    /*
     * Barangay is a POPULATION filter, unlike band and action: pick one and the
     * cards describe that barangay too. A screen where the table said Tonsuya
     * and the cards said Malabon would be inviting an officer to read a
     * city-wide figure as a barangay one.
     */
    $target = Barangay::firstOrFail();
    $business = Business::whereNotNull('owner_user_id')->firstOrFail();
    BusinessAddress::where('business_id', $business->id)
        ->where('address_type', 'business_location')
        ->update(['barangay_id' => $target->id]);

    $mine = riskPermit(-5, $business);
    $elsewhere = Business::whereNotNull('owner_user_id')->where('id', '!=', $business->id)->firstOrFail();
    riskPermit(-5, $elsewhere);

    $body = riskFeed('?barangay='.urlencode($target->name));

    expect($body['filters']['barangay'])->toBe($target->name);
    expect(collect($body['at_risk'])->pluck('permit_id'))->toContain($mine->id);
    foreach ($body['at_risk'] as $row) {
        expect($row['barangay'])->toBe($target->name);
    }
    expect(array_sum($body['counts']))->toBe($body['scored_permits']);
    expect($body['scored_permits'])->toBeLessThan(riskFeed()['scored_permits']);

    // And the menu still offers every barangay, so the filter can be backed out
    // of rather than only backed into.
    expect($body['barangays'])->toContain($target->name);
    expect(count($body['barangays']))->toBeGreaterThan(1);
});

it('pages through a filtered set instead of cutting it off', function () {
    Permit::query()->delete();
    foreach ([300, 310, 320, 330] as $days) {
        riskPermit($days);
    }

    $first = riskFeed('?limit=2&band=low');
    $second = riskFeed('?limit=2&band=low&offset=2');

    expect($first['matching'])->toBe(4);
    expect($first['at_risk'])->toHaveCount(2);
    expect($second['offset'])->toBe(2);
    expect($second['at_risk'])->toHaveCount(2);

    // Two pages, four distinct permits — not the same page twice.
    $seen = collect($first['at_risk'])->pluck('permit_id')
        ->merge(collect($second['at_risk'])->pluck('permit_id'));
    expect($seen->unique())->toHaveCount(4);

    /*
     * An offset past the end lands on the last populated page rather than on
     * nothing. Returning an empty table would read as "there are no low-risk
     * permits", which is false and is exactly the misreading this screen was
     * changed to stop.
     */
    $past = riskFeed('?limit=2&band=low&offset=999');
    expect($past['at_risk'])->not->toBeEmpty();
    expect($past['offset'])->toBe(2);
});

/* ── and it can be pointed at one named business ───────────────────────── */

it('finds a business by name from anywhere in the ranking, not just the page on screen', function () {
    Permit::query()->delete();

    /*
     * The whole reason this search is server-side. The wanted business is the
     * LOWEST ranked row here, so a search applied to the rows already in the
     * browser — one high-risk permit — would match nothing and report that
     * Malabon has no such business. On the real register the officer is looking
     * at 25 rows of several thousand and the odds are worse.
     */
    $wanted = Business::whereNotNull('owner_user_id')->firstOrFail();
    $wanted->update(['name' => 'Aling Mercado Sari-Sari Store']);
    $other = Business::whereNotNull('owner_user_id')->where('id', '!=', $wanted->id)->firstOrFail();

    riskPermit(-5, $other);    // high — the only row an unsearched first page shows
    riskPermit(300, $wanted);  // low — far enough down that paging is the alternative

    expect(riskFeed('?limit=1')['at_risk'][0]['business'])->toBe($other->name);

    // Substring and case-insensitive: the distinguishing word in a Malabon
    // business name is very often not its first one.
    $found = riskFeed('?limit=1&search=mercado');
    expect($found['matching'])->toBe(1);
    expect($found['at_risk'][0]['business'])->toBe('Aling Mercado Sari-Sari Store');
});

it('finds a permit by its number, which is what an officer holding the permit has', function () {
    Permit::query()->delete();
    $permit = riskPermit(-5);
    riskPermit(300);

    // Lowercased on the way in, to prove the officer is not expected to guess
    // the case the register happens to store the number in.
    $found = riskFeed('?search='.urlencode(mb_strtolower($permit->permit_number)));

    expect($found['matching'])->toBe(1);
    expect($found['at_risk'][0]['permit_id'])->toBe($permit->id);
});

it('narrows the table without touching the cards, because a search is not a smaller city', function () {
    Permit::query()->delete();

    $wanted = Business::whereNotNull('owner_user_id')->firstOrFail();
    $wanted->update(['name' => 'Solitaire Hardware']);
    $other = Business::whereNotNull('owner_user_id')->where('id', '!=', $wanted->id)->firstOrFail();

    riskPermit(-5, $wanted);
    riskPermit(25, $other);
    riskPermit(300, $other);

    $all = riskFeed();
    $found = riskFeed('?search=solitaire');

    // The table narrows...
    expect($found['matching'])->toBe(1);
    expect($found['at_risk'])->toHaveCount(1);

    /*
     * ...and nothing else does. Search is a VIEW filter, beside band and
     * action rather than beside barangay. Move it above the counting and
     * Recommended Actions would announce that Malabon has one permit needing
     * follow-up because an officer went looking for one business.
     */
    expect($found['scored_permits'])->toBe($all['scored_permits']);
    expect($found['counts'])->toBe($all['counts']);
    expect($found['actions'])->toBe($all['actions']);
});

it('intersects with the band filter and echoes the term as it was typed', function () {
    Permit::query()->delete();

    $wanted = Business::whereNotNull('owner_user_id')->firstOrFail();
    $wanted->update(['name' => 'Solitaire Hardware']);

    riskPermit(-5, $wanted);   // high
    riskPermit(300, $wanted);  // low

    expect(riskFeed('?search=Solitaire')['matching'])->toBe(2);

    $narrowed = riskFeed('?search=Solitaire&band=low');
    expect($narrowed['matching'])->toBe(1);
    expect($narrowed['at_risk'][0]['band'])->toBe('low');

    /*
     * Echoed in the officer's own casing. The term is folded to compare
     * against, and handing back "solitaire" under a box they typed "Solitaire"
     * into would read as the screen having quietly corrected them.
     */
    expect($narrowed['filters']['search'])->toBe('Solitaire');
});

it('treats a blank search as no search, and the word "all" as a real one', function () {
    Permit::query()->delete();

    $wanted = Business::whereNotNull('owner_user_id')->firstOrFail();
    $wanted->update(['name' => 'All Star Trading']);
    riskPermit(-5, $wanted);

    // Whitespace is not a term. A cleared box has to go back to being the
    // unfiltered screen, not to a filter matching everything by accident.
    $blank = riskFeed('?search=%20%20');
    expect($blank['filters']['search'])->toBeNull();
    expect($blank['matching'])->toBe($blank['scored_permits']);

    /*
     * "all" is the sentinel for the SELECTS, where it is the only way a
     * `<select>` can say "unset". A text box says that by being empty, so the
     * search deliberately does not share that cleaner — otherwise this officer
     * gets the unfiltered city back with nothing on screen to say their term
     * was thrown away, which is the one failure mode a search must not have.
     */
    $literal = riskFeed('?search=all');
    expect($literal['filters']['search'])->toBe('all');
    expect($literal['at_risk'][0]['business'])->toBe('All Star Trading');
});

it('ignores a filter value the scorer cannot produce, and says that it did', function () {
    Permit::query()->delete();
    riskPermit(-5);

    // A stray query string narrows nothing rather than 500-ing a dashboard —
    // and the response states what was applied, so the screen cannot label an
    // unfiltered table "Critical risk".
    $body = riskFeed('?band=critical&action=call_the_mayor');

    expect($body['filters']['band'])->toBeNull();
    expect($body['filters']['action'])->toBeNull();
    expect($body['at_risk'])->toHaveCount(1);
});

it('leaves the default request keyed to the snapshot the refresh already writes', function () {
    /*
     * The filters ride in the snapshot key, so an unfiltered request has to
     * produce the string it always did — `renewal_risk:days=365,limit=25` — or
     * the existing snapshots stop matching and the default screen quietly starts
     * recomputing on every load.
     *
     * That string is also why the rows already in the live register survived R's
     * removal: the key format did not change, so every snapshot R had computed
     * kept being found and served.
     */
    $key = AnalyticsSnapshot::keyFor('renewal_risk', ['days' => 365, 'limit' => 25]);

    expect($key)->toBe('renewal_risk:days=365,limit=25');

    AnalyticsSnapshot::create([
        'key' => $key,
        'dataset' => 'renewal_risk',
        'params' => ['days' => 365, 'limit' => 25],
        'payload' => ['at_risk' => [], 'scored_permits' => 7, 'counts' => ['high' => 7, 'moderate' => 0, 'low' => 0]],
        'engine_version' => '4.4.1',
        'computed_at' => CarbonImmutable::now(),
    ]);

    $response = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/renewal-risk')
        ->assertOk();

    expect($response->json('meta.source'))->toBe('snapshot');
    expect($response->json('data.scored_permits'))->toBe(7);

    /*
     * A stored snapshot knows nothing about filters or paging, and the screen
     * still has to render. The serving layer fills the gap with the answers that
     * are true of an unfiltered payload by definition.
     */
    expect($response->json('data.matching'))->toBe(7);
    expect($response->json('data.offset'))->toBe(0);
    expect($response->json('data.filters.band'))->toBeNull();
    expect($response->json('data.barangays'))->not->toBeEmpty();

    // Ask for one band and the snapshot no longer answers, so the figures are
    // computed for the request — and say so, rather than serving the stored
    // unfiltered payload under a filtered heading.
    $filtered = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/renewal-risk?band=low')
        ->assertOk();

    expect($filtered->json('meta.source'))->toBe('local');
    expect($filtered->json('meta.notice'))->not->toBeNull();

    /*
     * And it must say so QUIETLY. A band filter is one of this screen's own
     * supported options and can never be precomputed (the key space is the
     * product of every filter and offset), so this is designed behaviour, not a
     * degradation. `window_not_precomputed` is what routes it to the quiet line
     * instead of the staleness panel with the Refresh button — which would be
     * offering a button that cannot help. See AnalyticsPrecomputedWindowsTest.
     */
    expect($filtered->json('meta.fallback_reason'))->toBe('window_not_precomputed');
});

it('does not blank the table when a listed business has since been closed', function () {
    Permit::query()->delete();
    $permit = riskPermit(-5);

    remind($permit)->assertOk();
    Business::findOrFail($permit->business_id)->delete();

    // Business soft-deletes, so the row simply leaves the watchlist rather than
    // arriving with a null name the screen has to render.
    $body = riskFeed();
    expect(collect($body['at_risk'])->firstWhere('permit_id', $permit->id))->toBeNull();
    expect($body['scored_permits'])->toBe(0);
});

it('keeps a renewal filed against the permit out of the reminder queue’s way', function () {
    Permit::query()->delete();
    $permit = riskPermit(20);
    $business = Business::findOrFail($permit->business_id);

    Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $business->owner_user_id,
        'application_type' => ApplicationType::Renewal->value,
        'status' => ApplicationStatus::Approved->value,
        'prior_permit_id' => $permit->id,
        'submitted_at' => CarbonImmutable::now()->subDays(5),
    ]);

    // An approved renewal drops the score into the Monitor band, so the button
    // is gone from the screen and the endpoint refuses too. Nobody is chased
    // about a renewal they have already been granted.
    expect(RenewalRiskAnalytics::bandForPermit($permit->id))->toBe('low');
    remind($permit)->assertStatus(422);
});
