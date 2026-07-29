<?php

namespace Database\Seeders;

use App\Models\FeeRule;
use Illuminate\Database\Seeder;

/**
 * Loads the revenue-code fee rules from database/data/revenue_code/*.json.
 * Idempotent: upserts by code, deactivates rules that disappear from the data
 * files so stale rates never survive a reseed.
 */
class FeeRuleSeeder extends Seeder
{
    public function run(): void
    {
        $dir = database_path('data/revenue_code');
        $seen = [];

        foreach (glob($dir.'/*.json') as $file) {
            $rules = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($rules as $rule) {
                if (($rule['seed'] ?? true) === false) {
                    continue;
                }
                $seen[] = $rule['code'];
                FeeRule::updateOrCreate(
                    ['code' => $rule['code']],
                    [
                        'title' => $rule['title'],
                        'section' => $rule['section'],
                        'source' => $rule['source'],
                        'office' => $rule['office'],
                        'group' => $rule['group'],
                        'permit_types' => $rule['permit_types'],
                        'conditions' => $rule['conditions'] ?? [],
                        'basis' => $rule['basis'],
                        'computation' => $rule['computation'],
                        'cap' => $rule['cap'] ?? null,
                        'notes' => $rule['notes'] ?? null,
                        'defects' => $rule['defects'] ?? [],
                        'constants' => $rule['constants'] ?? null,
                        'requires_officer' => $rule['requires_officer'] ?? false,
                        'active' => true,
                    ]
                );
            }
        }

        FeeRule::whereNotIn('code', $seen)->update(['active' => false]);
        $this->command?->info('Fee rules seeded: '.count($seen));
    }
}
