<?php

namespace App\Support;

use App\Enums\ApplicationType;
use App\Models\Application;
use Carbon\CarbonInterface;

/**
 * Republic Act 11032 processing tiers, and the deadline each one carries.
 *
 * The statute gives a government office three working days to decide a simple
 * transaction, seven for a complex one and twenty for a highly technical one.
 * Every filing submitted before this class existed was stamped with a flat ten
 * working days regardless of tier, under a comment reading "RA 11032 working
 * days" — a figure that appears nowhere in the law and is more than three times
 * what a simple transaction is allowed.
 *
 * Worse, nothing in the application ever set `applications.complexity` at all.
 * Only the seeder did. So every filing made through the real wizard carried a
 * null tier, which the Average Processing Time by RA 11032 Tier panel excludes
 * by definition: the headline compliance feature could not see a single genuine
 * application.
 *
 * The tier rule is deliberately the same one AnalyticsHistorySeeder applies, and
 * reads the same two applicant-declared facts, so seeded and real filings are
 * classified alike and the panel is not comparing two different definitions.
 *
 * ── What is statute here, and what is ours ────────────────────────────────────
 *
 * TIERS is STATUTE. Three tiers, three day counts, and nothing in this product
 * may add a fourth or edit a count — an LGU cannot legislate itself a longer
 * deadline, and a control that let it would be a compliance defect rather than
 * a feature.
 *
 * `tierFor()` is NOT statute. It is our guess at which tier a given filing
 * belongs to, and RA 11032 does not answer that question — it requires the LGU
 * to publish its own classification in its Citizen's Charter. Malabon has not
 * told us theirs (open question A10 in docs/questions-for-malabon.md), so the
 * reviewing office can now overrule this rule per filing; see
 * WorkflowService::classify(). What `tierFor()` does is SEED an unclassified
 * filing at submission, which is why it must not be deleted: a filing nobody
 * has looked at still needs a deadline, and a null tier is invisible to the
 * compliance panel entirely.
 */
final class Ra11032
{
    /**
     * Statutory working days per tier. The single source of truth — the
     * dashboard's tier panel reads these rather than keeping its own copy.
     *
     * @var array<string, array{label: string, statutory_working_days: int}>
     */
    public const TIERS = [
        'simple' => ['label' => 'Simple', 'statutory_working_days' => 3],
        'complex' => ['label' => 'Complex', 'statutory_working_days' => 7],
        'highly_technical' => ['label' => 'Highly technical', 'statutory_working_days' => 20],
    ];

    /**
     * Lines of business whose filings need technical evaluation.
     *
     * Kept identical to AnalyticsHistorySeeder::HIGH_TECH_CATEGORIES. If these
     * two ever diverge, the tier panel silently mixes two classifications.
     */
    public const HIGH_TECH_CATEGORIES = [
        'manufacturer', 'essential_manufacturer', 'contractor', 'amusement_place',
    ];

    /** Declared capital at or above which a high-risk line is highly technical. */
    public const HIGH_TECH_CAPITAL_FLOOR = 1_000_000;

    /**
     * The tier a filing belongs to.
     *
     * Renewals and amendments are simple: the office has already cleared this
     * business once and is checking that nothing material changed. A new
     * registration is complex, because several offices have to clear it — unless
     * its declared line and capital put it in the tier the law reserves for
     * filings that need technical evaluation.
     */
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

    /**
     * The tier keys, in statutory order — the only values anyone may set.
     *
     * Read off TIERS rather than written out again, so the validation rule on
     * the reclassification endpoint and the options on the review sheet cannot
     * drift from the statute they are supposed to be quoting. A fourth tier
     * cannot be invented without editing TIERS, and TIERS is the law.
     *
     * @return list<string>
     */
    public static function tierKeys(): array
    {
        return array_keys(self::TIERS);
    }

    /** Is this a tier the statute recognises? Anything else is not a tier. */
    public static function isTier(mixed $tier): bool
    {
        return is_string($tier) && array_key_exists($tier, self::TIERS);
    }

    /** The statute's own name for a tier ("Simple"), or null for an unknown one. */
    public static function label(?string $tier): ?string
    {
        return self::TIERS[$tier]['label'] ?? null;
    }

    /**
     * The three tiers as an ordered list, for a caller that has to OFFER them.
     *
     * Shaped for the wire: the review sheet draws exactly these options and
     * nothing else, so the browser never holds its own copy of the day counts
     * and cannot show an officer a deadline the statute does not give.
     *
     * @return list<array{value: string, label: string, statutory_working_days: int}>
     */
    public static function tierOptions(): array
    {
        return array_values(array_map(
            fn (string $key) => ['value' => $key] + self::TIERS[$key],
            self::tierKeys(),
        ));
    }

    /** Working days the statute allows a tier. Unknown tiers fall back to complex. */
    public static function statutoryWorkingDays(?string $tier): int
    {
        return self::TIERS[$tier]['statutory_working_days'] ?? self::TIERS['complex']['statutory_working_days'];
    }

    /**
     * The statutory deadline for a filing submitted at $from.
     *
     * Working days, matching how the statute counts and how the dashboard
     * measures compliance. Public holidays are not modelled on either side, which
     * makes the count slightly conservative — a real deadline falls a little
     * later than this, so a filing this class marks late is genuinely late.
     */
    public static function deadlineFor(CarbonInterface $from, ?string $tier): CarbonInterface
    {
        return $from->copy()->addWeekdays(self::statutoryWorkingDays($tier));
    }
}
