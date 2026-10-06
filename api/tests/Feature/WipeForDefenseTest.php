<?php

use App\Console\Commands\WipeForDefense;
use App\Models\Application;
use App\Models\FeeAssessment;
use App\Models\User;
use App\Support\Numbering;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * biztrack:wipe-for-defense empties the register of owners and everything
 * they filed, and keeps the City's accounts and reference data. The register
 * here is the seeded one plus a full run of the owner generator, so every
 * table a real register fills has rows in it before the wipe.
 */

const WIPE_TEST_PASSWORD = 'Wipe-Check-2026!';

/** Row counts of the tables the wipe keeps whole. */
function keptCounts(): array
{
    return collect(WipeForDefense::KEPT)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
}

/** @return array<int, list<string>> each staff account's id => its roles */
function staffRoles(): array
{
    return User::with('roles')->get()
        ->filter(fn (User $u) => $u->roles->pluck('name')->diff(['business_owner'])->isNotEmpty())
        ->mapWithKeys(fn (User $u) => [$u->id => $u->roles->pluck('name')->sort()->values()->all()])
        ->all();
}

beforeEach(function () {
    Storage::fake('local');
    $this->artisan('biztrack:seed-defense-accounts', ['--password' => WIPE_TEST_PASSWORD, '--per-scenario' => 1])->assertSuccessful();
});

it('refuses to run without --confirm=WIPE, and changes nothing', function () {
    $before = [User::count(), Application::count()];

    $this->artisan('biztrack:wipe-for-defense')->assertFailed();
    $this->artisan('biztrack:wipe-for-defense', ['--confirm' => 'yes'])->assertFailed();

    expect([User::count(), Application::count()])->toBe($before);
});

it('counts on a dry run and deletes nothing', function () {
    $before = [User::count(), Application::count(), DB::table('audit_logs')->count()];

    $this->artisan('biztrack:wipe-for-defense', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run: nothing deleted.')
        ->assertSuccessful();

    expect([User::count(), Application::count(), DB::table('audit_logs')->count()])->toBe($before);
});

it('deletes every owner and everything filed, and keeps staff and reference data', function () {
    $staff = staffRoles();
    $staffHashes = User::whereIn('id', array_keys($staff))->pluck('password', 'id')->all();
    $kept = keptCounts();
    $owners = User::whereHas('roles', fn ($r) => $r->where('name', 'business_owner'))->pluck('id')->all();
    expect($owners)->not->toBeEmpty()
        ->and(DB::table('applications')->count())->toBeGreaterThan(0)
        ->and(DB::table('permits')->count())->toBeGreaterThan(0)
        ->and(DB::table('payments')->count())->toBeGreaterThan(0)
        ->and(DB::table('audit_logs')->count())->toBeGreaterThan(0);

    $files = DB::table('application_documents')->pluck('stored_path')->all();
    expect($files)->not->toBeEmpty();
    Storage::disk('local')->assertExists($files[0]);
    // A staff avatar stays; an owner's goes.
    Storage::disk('local')->put('private/avatars/'.array_key_first($staff).'/me.png', 'x');
    Storage::disk('local')->put("private/avatars/{$owners[0]}/me.png", 'x');
    // A token a staff member signed in with survives; an owner's does not.
    User::find(array_key_first($staff))->createToken('web:staff');
    User::find($owners[0])->createToken('web:public');

    $this->artisan('biztrack:wipe-for-defense', ['--confirm' => 'WIPE'])->assertSuccessful();

    foreach (WipeForDefense::WIPED as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} is empty");
    }
    expect(User::withTrashed()->whereIn('id', $owners)->exists())->toBeFalse()
        ->and(DB::table('user_roles')->whereIn('user_id', $owners)->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')->whereIn('tokenable_id', $owners)->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', array_key_first($staff))->exists())->toBeTrue()
        // Staff exactly as they were: same accounts, roles and password hashes.
        ->and(staffRoles())->toBe($staff)
        ->and(User::pluck('password', 'id')->all())->toBe($staffHashes)
        ->and(keptCounts())->toBe($kept);

    Storage::disk('local')->assertMissing($files[0]);
    Storage::disk('local')->assertMissing("private/avatars/{$owners[0]}/me.png");
    Storage::disk('local')->assertExists('private/avatars/'.array_key_first($staff).'/me.png');

    // The numbers start again: the next filing, permit, BAN and payment are
    // number 1, and so is the next Tax Order of Payment (the assessment id).
    $year = now()->year;
    expect(Numbering::trackingId())->toBe("BIZ-{$year}-00001")
        ->and(Numbering::permitNumber('MCB'))->toBe("MCB-{$year}-000001")
        ->and(Numbering::ban())->toBe("BP-{$year}-0001")
        ->and(Numbering::paymentReference())->toBe("PAY-{$year}-000001")
        ->and((int) (DB::table('sqlite_sequence')->where('name', 'fee_assessments')->value('seq') ?? 0))->toBe(0);

    // Staff still sign in.
    foreach (User::whereIn('id', array_keys($staff))->pluck('email') as $email) {
        expect(loginToken($email))->not->toBeEmpty();
    }
});

it('lets the generator build the register again after a wipe, numbered from one', function () {
    $this->artisan('biztrack:wipe-for-defense', ['--confirm' => 'WIPE'])->assertSuccessful();
    $this->artisan('biztrack:seed-defense-accounts', ['--password' => WIPE_TEST_PASSWORD, '--per-scenario' => 1])->assertSuccessful();

    $year = now()->year;
    expect(Application::whereNotNull('tracking_id')->orderBy('id')->value('tracking_id'))->toBe("BIZ-{$year}-00001")
        ->and(FeeAssessment::orderBy('id')->value('id'))->toBe(1);
});
