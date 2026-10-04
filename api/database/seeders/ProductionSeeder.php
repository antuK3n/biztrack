<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * What a production database is seeded with: the reference data the system
 * cannot run without, and nothing else.
 *
 *     php artisan db:seed --class=ProductionSeeder --force
 *
 * DatabaseSeeder is the demo storyline — it ends in DemoSeeder, which creates
 * accounts sharing one known password and filings that never happened. Seeding
 * production with it would put both on the live register. The analytics
 * history seeders are left out for the same reason: their filings are
 * invented.
 *
 * Safe to run again after every deploy, and meant to be. Each seeder below
 * keys its rows on a natural code (updateOrCreate / firstOrCreate), so a second
 * run changes nothing it has already written, and ReferenceSeeder, ZoningSeeder
 * and the signatories deliberately never overwrite what an admin has edited.
 * ProductionSeederTest runs it twice and counts the rows.
 *
 * The order is DatabaseSeeder's, minus the demo: ZoningSeeder hangs off the
 * barangays ReferenceSeeder writes.
 *
 * No account is created. The first super admin is made by hand on the server —
 * docs/postgres.md says how — so no password ever sits in this repository.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ReferenceSeeder::class,
            ZoningSeeder::class,
            RbacSeeder::class,
            FeeRuleSeeder::class,
        ]);
    }
}
