<?php

namespace App\Support;

use App\Enums\ApplicationType;
use App\Models\Application;
use Carbon\CarbonInterface;

final class Ra11032
{
    public const TIERS = [
        'simple' => ['label' => 'Simple', 'statutory_working_days' => 3],
        'complex' => ['label' => 'Complex', 'statutory_working_days' => 7],
        'highly_technical' => ['label' => 'Highly technical', 'statutory_working_days' => 20],
    ];

    public const HIGH_TECH_CATEGORIES = [
        'manufacturer', 'essential_manufacturer', 'contractor', 'amusement_place',
    ];

    public const HIGH_TECH_CAPITAL_FLOOR = 1_000_000;

    public static function tierFor(Application $application): string
    {
        if ($application->application_type !== ApplicationType::New) {
            return 'simple';
        }

        $profile = $application->fee_profile;
        $profile = is_array($profile) ? $profile : [];
        $lines = is_array($profile['lines'] ?? null) ? $profile['lines'] : [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $category = $line['category'] ?? null;
            $capital = (float) ($line['capitalization'] ?? 0);

            if (is_string($category)
                && in_array($category, self::HIGH_TECH_CATEGORIES, true)
                && $capital >= self::HIGH_TECH_CAPITAL_FLOOR) {
                return 'highly_technical';
            }
        }

        return 'complex';
    }

    public static function statutoryWorkingDays(?string $tier): int
    {
        return self::TIERS[$tier]['statutory_working_days'] ?? self::TIERS['complex']['statutory_working_days'];
    }

    public static function deadlineFor(CarbonInterface $from, ?string $tier): CarbonInterface
    {
        return $from->copy()->addWeekdays(self::statutoryWorkingDays($tier));
    }
}
