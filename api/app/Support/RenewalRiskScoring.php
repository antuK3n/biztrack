<?php

namespace App\Support;

final class RenewalRiskScoring
{
    public const HIGH_THRESHOLD = 50;

    public const MODERATE_THRESHOLD = 25;

    public const WEIGHTS = [
        'expiry' => 30,
        'progress' => 25,
        'punctuality' => 20,
        'findings' => 15,
        'fees' => 10,
    ];

    private const EXPIRY_BANDS = [
        [1, 30],
        [7, 25],
        [15, 18],
        [30, 10],
        [60, 4],
        [90, 2],
    ];

    private const PROGRESS_POINTS = [
        'none' => 25,
        'rejected' => 25,
        'draft' => 20,
        'returned' => 18,
        'in_progress' => 5,
        'approved' => 0,
    ];

    public const RENEWAL_DUE_WITHIN_DAYS = 30;

    private const PUNCTUALITY_UNKNOWN = 10;

    private const FINDINGS_BANDS = [
        [0, 0],
        [2, 8],
    ];

    private const FEE_POINTS = [
        'settled' => 0,
        'pending' => 6,
        'unpaid' => 10,
    ];

    public static function score(array $facts): array
    {
        $drivers = [
            self::expiryDriver((int) $facts['days_to_expiry']),
            self::progressDriver((string) $facts['renewal_stage'], (int) $facts['days_to_expiry']),
            self::punctualityDriver((int) $facts['prior_renewals'], (int) $facts['late_renewals']),
            self::findingsDriver((int) $facts['open_findings']),
            self::feeDriver((string) $facts['fee_state']),
        ];

        $score = 0;
        foreach ($drivers as $driver) {
            $score += $driver['points'];
        }

        $band = match (true) {
            $score >= self::HIGH_THRESHOLD => 'high',
            $score >= self::MODERATE_THRESHOLD => 'moderate',
            default => 'low',
        };

        [$action, $actionLabel] = match ($band) {
            'high' => ['immediate_follow_up', 'Immediate follow-up'],
            'moderate' => ['send_reminder', 'Send reminder'],
            default => ['monitor', 'Monitor'],
        };

        return [
            'score' => $score,
            'band' => $band,
            'band_label' => match ($band) {
                'high' => 'High',
                'moderate' => 'Moderate',
                default => 'Low',
            },
            'action' => $action,
            'action_label' => $actionLabel,

            'drivers' => self::sortByPoints($drivers),
        ];
    }

    public static function parameters(): array
    {
        return [
            'weights' => self::WEIGHTS,
            'thresholds' => [
                'high' => self::HIGH_THRESHOLD,
                'moderate' => self::MODERATE_THRESHOLD,
            ],

            'expiry_bands' => self::EXPIRY_BANDS,
            'progress_points' => self::PROGRESS_POINTS,
            'renewal_due_within_days' => self::RENEWAL_DUE_WITHIN_DAYS,
            'punctuality_unknown_points' => self::PUNCTUALITY_UNKNOWN,

            'findings_bands' => self::FINDINGS_BANDS,
            'fee_points' => self::FEE_POINTS,
        ];
    }

    /**
     * The five rules, in plain words, at the client's request: "can't it be
     * simplified, the computation on how the index was computed?"
     *
     * The complaint was not that the rules were wrong but that reading all five
     * still left you unable to say how a permit got its number. So each
     * description now leads with the arithmetic — how many points, on what
     * condition — and the qualifications follow. The edge cases that used to
     * open each sentence ("stepped on the expiry-monitoring marks", "the share
     * of this business's earlier renewals") are true and are still here; they
     * are just no longer the first thing between a reader and the rule.
     *
     * What the descriptions deliberately do NOT do is state the totals or the
     * band cuts. Those come from WEIGHTS and the two thresholds and are printed
     * by the screen from those same constants, so the sum on screen cannot
     * drift from the sum the scorer actually uses — which is exactly what would
     * happen if a number like "100" were typed into a sentence here.
     */
    public static function rulebook(): array
    {
        return [
            [
                'rule' => 'expiry',
                'label' => 'Time to expiry',
                'max' => self::WEIGHTS['expiry'],
                'description' => 'The nearer the expiry date, the more points. Nothing at all beyond 90 days '
                    .'out, then steps up at 30, 15, 7 and 1 day, and the full '.self::WEIGHTS['expiry']
                    .' once the permit has lapsed.',
            ],
            [
                'rule' => 'progress',
                'label' => 'Renewal progress',
                'max' => self::WEIGHTS['progress'],
                'description' => 'All '.self::WEIGHTS['progress'].' points if the permit expires within '
                    .self::RENEWAL_DUE_WITHIN_DAYS.' days and no renewal has been filed — or if one was filed '
                    .'and rejected, so it still has to be done again. Nothing if the renewal is not due yet, '
                    .'and nothing once it has been approved.',
            ],
            [
                'rule' => 'punctuality',
                'label' => 'Past punctuality',
                'max' => self::WEIGHTS['punctuality'],
                'description' => 'How often this business has renewed late before. Every past renewal late '
                    .'scores the full '.self::WEIGHTS['punctuality'].'; none late scores 0; half of them late '
                    .'scores half. A business renewing for the first time has no record to go on and takes '
                    .self::PUNCTUALITY_UNKNOWN.'.',
            ],
            [
                'rule' => 'findings',
                'label' => 'Open compliance findings',
                'max' => self::WEIGHTS['findings'],
                'description' => 'Requirements still unticked, and inspections that came back failed or '
                    .'conditional. These would hold up the permit even if the renewal were filed on time.',
            ],
            [
                'rule' => 'fees',
                'label' => 'Unsettled fees',
                'max' => self::WEIGHTS['fees'],
                'description' => 'A fee has been assessed against the renewal and not yet paid in full.',
            ],
        ];
    }

    private static function expiryDriver(int $daysToExpiry): array
    {
        if ($daysToExpiry < 0) {
            return self::driver('expiry', 'Time to expiry', self::WEIGHTS['expiry'],
                'Lapsed '.abs($daysToExpiry).' '.self::plural(abs($daysToExpiry), 'day').' ago');
        }

        foreach (self::EXPIRY_BANDS as [$threshold, $points]) {
            if ($daysToExpiry <= $threshold) {
                return self::driver('expiry', 'Time to expiry', $points,
                    'Expires in '.$daysToExpiry.' '.self::plural($daysToExpiry, 'day'));
            }
        }

        return self::driver('expiry', 'Time to expiry', 0,
            'Expires in '.$daysToExpiry.' days — more than 90 out');
    }

    private static function progressDriver(string $stage, int $daysToExpiry): array
    {
        $known = array_key_exists($stage, self::PROGRESS_POINTS) ? $stage : 'none';
        $due = $daysToExpiry <= self::RENEWAL_DUE_WITHIN_DAYS;

        if ($known === 'none' && ! $due) {
            return self::driver('progress', 'Renewal progress', 0, 'Not yet due for renewal');
        }

        return self::driver('progress', 'Renewal progress', self::PROGRESS_POINTS[$known], match ($known) {
            'approved' => 'Renewal approved',
            'in_progress' => 'Renewal filed and in the queue',
            'draft' => 'Renewal started but never submitted',
            'returned' => 'Renewal returned to the applicant',
            'rejected' => 'Renewal rejected — must be refiled',
            default => 'No renewal filed yet',
        });
    }

    private static function punctualityDriver(int $priorRenewals, int $lateRenewals): array
    {
        if ($priorRenewals < 1) {
            return self::driver('punctuality', 'Past punctuality', self::PUNCTUALITY_UNKNOWN,
                'First renewal cycle — no punctuality record either way');
        }

        $late = max(0, min($priorRenewals, $lateRenewals));

        $points = (int) Rounding::statistic(($late / $priorRenewals) * self::WEIGHTS['punctuality'], 0);

        return self::driver('punctuality', 'Past punctuality', $points,
            $late === 0
                ? 'All '.$priorRenewals.' earlier '.self::plural($priorRenewals, 'renewal').' filed before expiry'
                : $late.' of '.$priorRenewals.' earlier '.self::plural($priorRenewals, 'renewal').' filed late');
    }

    private static function findingsDriver(int $openFindings): array
    {
        $points = self::WEIGHTS['findings'];
        foreach (self::FINDINGS_BANDS as [$threshold, $banded]) {
            if ($openFindings <= $threshold) {
                $points = $banded;
                break;
            }
        }

        return self::driver('findings', 'Open compliance findings', $points,
            $openFindings === 0
                ? 'Nothing outstanding'
                : $openFindings.' open '.self::plural($openFindings, 'finding'));
    }

    private static function feeDriver(string $state): array
    {
        $points = self::FEE_POINTS[$state] ?? 0;

        return self::driver('fees', 'Unsettled fees', $points, match ($state) {
            'unpaid' => 'Assessed fee with no payment recorded',
            'pending' => 'Payment recorded but not yet cleared',
            default => 'Fees settled',
        });
    }

    private static function driver(string $rule, string $label, int $points, string $detail): array
    {
        return [
            'rule' => $rule,
            'label' => $label,
            'points' => $points,
            'max' => self::WEIGHTS[$rule],
            'detail' => $detail,
        ];
    }

    private static function sortByPoints(array $drivers): array
    {

        usort($drivers, static fn (array $a, array $b) => [$b['points'], $b['max']] <=> [$a['points'], $a['max']]);

        return $drivers;
    }

    private static function plural(int $count, string $word): string
    {
        return $count === 1 ? $word : $word.'s';
    }
}
