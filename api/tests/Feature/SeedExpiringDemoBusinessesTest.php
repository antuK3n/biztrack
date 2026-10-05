<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;

/*
 * `biztrack:seed-expiring-demo` — the five businesses at the five renewal
 * states the client walks by hand.
 *
 * Until 5 October 2026 they were named "[DEMO] …", addressed "12 Demo
 * Street" and numbered "DEMO-…", all of which shows on screen. Ken asked for
 * every "demo" on screen to go; the command still has to find its own rows
 * again, including the "[DEMO] " ones it wrote before.
 */

const EXPIRING_NAMES = [
    'Dampalit Sari-Sari Store', 'Catmon Bakeshop', 'Longos Hardware',
    'Tinajeros Water Refilling', 'Maysilo Printing Press', 'Potrero Carinderia',
    'Hulong Duhat Eatery', 'Panghulo Auto Supply',
];

function expiringOwner(): User
{
    return User::where('email', 'owner@biztrack.local')->firstOrFail();
}

/** A business as the command wrote them before 5 October 2026. */
function legacyExpiringBusiness(string $name): Business
{
    $owner = expiringOwner();
    $business = Business::create([
        'owner_user_id' => $owner->id,
        'name' => '[DEMO] '.$name,
        'registration_type' => 'sole_proprietorship',
        'tin' => '123-456-789-000',
        'barangay_id' => Barangay::value('id'),
        'address_line' => 'Demo address, Malabon',
        'status' => 'active',
    ]);
    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);
    Permit::create([
        'application_id' => $app->id,
        'business_id' => $business->id,
        'permit_type_id' => PermitType::where('code', 'SANITARY')->value('id'),
        'permit_number' => 'DEMO-SANITARY-'.$business->id,
        'issued_at' => now()->subYear(),
        'valid_from' => now()->subYear(),
        'valid_until' => now()->addMonth(),
        'status' => 'active',
    ]);

    return $business;
}

it('names the businesses, their addresses and their permits without "demo"', function () {
    $this->artisan('biztrack:seed-expiring-demo')->assertSuccessful();

    $made = Business::with(['address', 'permits'])->where('owner_user_id', expiringOwner()->id)
        ->whereIn('name', EXPIRING_NAMES)->get();

    expect($made)->toHaveCount(count(EXPIRING_NAMES));
    foreach ($made as $business) {
        $shown = [
            $business->name,
            $business->address_line,
            $business->address?->street,
            $business->address?->line1,
            ...$business->permits->pluck('permit_number')->all(),
        ];
        expect($business->permits)->toHaveCount(6)
            ->and(strtolower(implode(' | ', $shown)))->not->toContain('demo');
    }
});

it('skips a business it already made, and --fresh rebuilds it without touching a namesake it did not make', function () {
    $this->artisan('biztrack:seed-expiring-demo')->assertSuccessful();
    $first = Business::where('name', 'Catmon Bakeshop')->sole();

    // Someone else's shop with the same name is not the command's.
    $namesake = Business::create([
        'owner_user_id' => User::where('email', '!=', 'owner@biztrack.local')->value('id'),
        'name' => 'Catmon Bakeshop',
        'registration_type' => 'sole_proprietorship',
        'tin' => '123-456-789-000',
        'barangay_id' => Barangay::value('id'),
        'address_line' => '3 Real Street',
        'status' => 'active',
    ]);

    $this->artisan('biztrack:seed-expiring-demo')
        ->expectsOutputToContain('skip   Catmon Bakeshop (already present')
        ->assertSuccessful();
    expect(Business::where('owner_user_id', expiringOwner()->id)->where('name', 'Catmon Bakeshop')->count())->toBe(1);

    $this->artisan('biztrack:seed-expiring-demo', ['--fresh' => true])->assertSuccessful();

    expect(Business::find($first->id))->toBeNull()
        ->and(Business::find($namesake->id))->not->toBeNull()
        ->and(Business::where('owner_user_id', expiringOwner()->id)->where('name', 'Catmon Bakeshop')->count())->toBe(1);
});

it('treats the "[DEMO] " businesses written before as its own: it skips their names, and --fresh replaces them', function () {
    $legacy = legacyExpiringBusiness('Longos Hardware');
    $permitIds = $legacy->permits()->pluck('id');

    $this->artisan('biztrack:seed-expiring-demo')
        ->expectsOutputToContain('skip   Longos Hardware (already present')
        ->assertSuccessful();

    $this->artisan('biztrack:seed-expiring-demo', ['--fresh' => true])->assertSuccessful();

    expect(Business::find($legacy->id))->toBeNull()
        ->and(Permit::whereIn('id', $permitIds)->count())->toBe(0)
        ->and(Business::where('name', 'like', '[DEMO]%')->count())->toBe(0)
        ->and(Business::where('owner_user_id', expiringOwner()->id)->where('name', 'Longos Hardware')->count())->toBe(1);
});
