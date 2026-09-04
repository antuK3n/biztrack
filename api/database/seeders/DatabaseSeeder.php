<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ReferenceSeeder::class,
            // After ReferenceSeeder — it seeds the 21 barangays this hangs off.
            ZoningSeeder::class,
            RbacSeeder::class,
            FeeRuleSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
