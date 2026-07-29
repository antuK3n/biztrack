<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Removes everything AnalyticsHistorySeeder wrote, and nothing else.
 *
 *     php artisan db:seed --class=AnalyticsHistoryPurgeSeeder
 *
 * It lives as its own seeder so the demo history has a one-command undo that
 * needs no artisan command of its own. The work is in
 * AnalyticsHistorySeeder::purge(); this is the door.
 */
class AnalyticsHistoryPurgeSeeder extends Seeder
{
    public function run(): void
    {
        if (! AnalyticsHistorySeeder::isSeeded()) {
            $this->command?->warn('AnalyticsHistoryPurgeSeeder: no seeded analytics history found — nothing to remove.');

            return;
        }

        $removed = AnalyticsHistorySeeder::purge();

        if ($removed === []) {
            $this->command?->warn('AnalyticsHistoryPurgeSeeder: nothing removed.');

            return;
        }

        $this->command?->info('AnalyticsHistoryPurgeSeeder: removed');
        foreach ($removed as $table => $count) {
            $this->command?->line(sprintf('  %-30s %d', $table, $count));
        }
        $this->command?->line(sprintf('  %-30s %d', 'TOTAL', array_sum($removed)));
    }
}
