<?php

use App\Models\Barangay;
use App\Models\Department;
use App\Models\FeeRule;
use App\Models\OfficeSignatory;
use App\Models\PermitType;
use App\Models\Role;
use App\Models\ZoningClassification;
use Database\Seeders\AnalyticsHistoryPurgeSeeder;
use Database\Seeders\AnalyticsHistorySeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ProductionSeeder is what a production database is seeded with, after every
 * deploy: the reference data and nothing invented. Two rules, each of which
 * would be expensive to learn from production.
 */

/** Row count of every table, so "nothing changed" is a measurement. */
function everyTableCount(): array
{
    return collect(Schema::getTableListing(schemaQualified: false))->sort()
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

it('seeds the reference data and nothing from the demo storyline', function () {
    // Any demo or history seeder it reached would throw here.
    foreach ([DemoSeeder::class, AnalyticsHistorySeeder::class, AnalyticsHistoryPurgeSeeder::class] as $demo) {
        app()->bind($demo, fn () => new class extends Seeder
        {
            public function run(): void
            {
                throw new RuntimeException('ProductionSeeder reached a demo seeder.');
            }
        });
    }

    $this->seed(ProductionSeeder::class);

    expect(Department::count())->toBeGreaterThan(0)
        ->and(Barangay::count())->toBe(21)
        ->and(PermitType::where('code', 'BUSINESS')->exists())->toBeTrue()
        ->and(Role::where('name', 'admin')->exists())->toBeTrue()
        ->and(FeeRule::where('active', true)->count())->toBeGreaterThan(0)
        ->and(ZoningClassification::count())->toBeGreaterThan(0);
});

it('changes nothing when it runs again, and keeps what an admin edited', function () {
    $this->seed(ProductionSeeder::class);
    $signatory = OfficeSignatory::firstOrFail();
    $signatory->update(['name' => 'Edited By The Admin']);
    $before = everyTableCount();

    $this->seed(ProductionSeeder::class);
    $this->seed(ProductionSeeder::class);

    expect(everyTableCount())->toBe($before)
        ->and($signatory->fresh()->name)->toBe('Edited By The Admin');
});
