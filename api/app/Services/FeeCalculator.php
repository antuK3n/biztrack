<?php

namespace App\Services;

use App\Models\Application;
use App\Models\FeeRule;
use Illuminate\Support\Collection;

/**
 * Computes the Tax Order of Payment from the seeded revenue-code rules
 * (Ordinance A10-2016; see database/data/revenue_code/SCHEMA.md for the rule
 * format and docs/revenue-code-extract.md for the legal source).
 *
 * Ordering rules the ordinance imposes (SCHEMA.md "Engine ordering rules"):
 *  1. Business tax resolves per line of business, then sums. Franchise and
 *     printing/publication categories route to their 0.75% rules instead of
 *     the graduated table (their rules match those categories exclusively).
 *  2. Environmental annual fee: highest matched bracket only (Sec. 3W.02
 *     same-nature rule, conservatively applied per application).
 *  3. Garbage: highest matched schedule; if more than one schedule matches,
 *     +25% uplift; never above P6,000/yr (Sec. 4F.02).
 *  4. FSIC: 10% of mayors_permit + regulatory lines, computed last
 *     (RA 9514 Sec. 12(b)) — never part of its own base.
 *  5. is_petroleum suppresses biztax.* lines (Sec. 2L.01). BMBE/cooperative
 *     flags emit P0 claim lines only; officers adjust via adjustFee.
 *  6. requires_officer rules emit P0 lines the officer completes.
 */
class FeeCalculator
{
    /** @return array{items: list<array<string,mixed>>, total: float} */
    public function assess(Application $app): array
    {
        $app->loadMissing('permitTypes', 'business.lines');

        $profile = $app->fee_profile ?? [];
        $requested = $app->permitTypes->pluck('code')->all();
        $profile['is_new_business'] = $app->application_type?->value === 'new';

        // Lines of business: explicit profile lines win; fall back to the
        // registered business lines with the profile's shared figures.
        $lines = collect($profile['lines'] ?? []);
        if ($lines->isEmpty()) {
            $lines = $app->business->lines->map(fn ($l) => [
                'category' => $l->category ?? null,
                'gross_sales' => $profile['gross_sales'] ?? null,
                'capitalization' => $profile['capitalization'] ?? null,
            ]);
        }
        $profile['categories'] = $lines->pluck('category')->filter()->values()->all();

        $rules = FeeRule::where('active', true)->get()
            ->filter(fn (FeeRule $r) => $r->group === 'penalty' ? false
                : array_intersect($r->permit_types, $requested) !== []);

        $items = [];

        // 1. Business tax: per line of business.
        $suppressBiztax = in_array('is_petroleum', $profile['flags'] ?? [], true);
        if (! $suppressBiztax) {
            foreach ($lines as $line) {
                foreach ($rules->where('group', 'business_tax') as $rule) {
                    if (! $this->matches($rule, $profile, $line)) {
                        continue;
                    }
                    $item = $this->compute($rule, $profile, $line);
                    if ($item !== null) {
                        $item['line_of_business'] = $line['category'] ?? null;
                        $items[] = $item;
                    }
                }
            }
        }

        // Application-scoped groups (not per line): mayor's permit, admin,
        // ctc, city_charge, exemption claims — plus regulatory, with the
        // environmental/garbage aggregation handled below.
        // Item 64 ("all other businesses not specifically mentioned") is a
        // fallback: it bills only when no category-specific mayor's-permit
        // line matched (Sec. 3A.03 IX(64)).
        $plain = ['mayors_permit', 'admin', 'ctc', 'city_charge', 'exemption_claim'];
        $catchAll = null;
        $specificPermitMatched = false;
        foreach ($rules->whereIn('group', $plain) as $rule) {
            if (! $this->matches($rule, $profile) || ($item = $this->compute($rule, $profile)) === null) {
                continue;
            }
            if ($rule->code === 'permit.catchall_office_area') {
                $catchAll = $item;

                continue;
            }
            if ($rule->group === 'mayors_permit' && ! empty($rule->conditions['business_category'])) {
                $specificPermitMatched = true;
            }
            $items[] = $item;
        }
        if ($catchAll !== null && ! $specificPermitMatched) {
            $items[] = $catchAll;
        }

        // 2+3. Regulatory: environmental, sanitary and garbage aggregate;
        // the rest add up.
        $envMatches = [];
        $garbageMatches = [];
        $sanitarySpecific = [];
        $sanitaryCatchAll = [];
        foreach ($rules->where('group', 'regulatory') as $rule) {
            if (! $this->matches($rule, $profile) || ($item = $this->compute($rule, $profile)) === null) {
                continue;
            }
            if (str_starts_with($rule->code, 'env.') && ! $rule->requires_officer) {
                $envMatches[] = $item;
            } elseif (str_starts_with($rule->code, 'garbage.')) {
                $garbageMatches[] = $item;
            } elseif (str_starts_with($rule->code, 'sanitary.')
                && ($rule->computation['type'] ?? null) !== 'per_unit') {
                // Sec. 4D.01: annual inspection fee — a business-type rate when
                // one applies, the floor-area catch-all otherwise; multiple
                // businesses in one place pay the highest rate only. Per-unit
                // sanitary lines (health certificates) stay additive.
                if (($rule->conditions ?? []) === []) {
                    $sanitaryCatchAll[] = $item;
                } else {
                    $sanitarySpecific[] = $item;
                }
            } else {
                $items[] = $item;
            }
        }
        $sanitaryPool = $sanitarySpecific !== [] ? $sanitarySpecific : $sanitaryCatchAll;
        if ($sanitaryPool !== []) {
            $items[] = collect($sanitaryPool)->sortByDesc('amount')->first();
        }
        if ($envMatches !== []) {
            $items[] = collect($envMatches)->sortByDesc('amount')->first();
        }
        if ($garbageMatches !== []) {
            $top = collect($garbageMatches)->sortByDesc('amount')->first();
            $amount = $top['amount'];
            if (count($garbageMatches) > 1) {
                $amount *= 1.25; // Sec. 4F.02 multi-schedule uplift
            }
            $top['amount'] = round(min($amount, 6000.0), 2);
            $items[] = $top;
        }

        // 4. FSIC last: 10% of mayors_permit + regulatory lines (RA 9514).
        foreach ($rules->where('group', 'fire_code') as $rule) {
            if (! $this->matches($rule, $profile)) {
                continue;
            }
            $base = collect($items)
                ->whereIn('group', ['mayors_permit', 'regulatory'])
                ->sum('amount');
            $rate = (float) ($rule->computation['rate'] ?? 0.1);
            $items[] = $this->item($rule, round($base * $rate, 2));
        }

        $items = array_values($items);
        $total = round(array_sum(array_column($items, 'amount')), 2);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Surcharge and interest on late payment (Secs. 8A.04/8A.05): 25% once,
     * plus 2%/month on tax + surcharge, interest capped at 36 months.
     */
    public function latePenalty(float $amountDue, int $monthsLate): array
    {
        $c = FeeRule::where('code', 'penalty.late_payment')->first()?->constants
            ?? ['surcharge_rate' => 0.25, 'interest_rate_monthly' => 0.02, 'interest_max_months' => 36];

        $surcharge = round($amountDue * $c['surcharge_rate'], 2);
        $months = min(max(0, $monthsLate), $c['interest_max_months']);
        $interest = round(($amountDue + $surcharge) * $c['interest_rate_monthly'] * $months, 2);

        return [
            'surcharge' => $surcharge,
            'interest' => $interest,
            'months_counted' => $months,
            'total' => round($amountDue + $surcharge + $interest, 2),
        ];
    }

    /** Does the rule's condition set hold for this profile (and line)? */
    private function matches(FeeRule $rule, array $profile, ?array $line = null): bool
    {
        foreach ($rule->conditions as $key => $want) {
            if ($want === null || $want === []) {
                continue;
            }
            $ok = match ($key) {
                'is_new_business' => ($profile['is_new_business'] ?? false) === $want,
                'min_capitalization' => (float) ($line['capitalization'] ?? $profile['capitalization'] ?? 0) >= (float) $want,
                'business_category' => array_intersect(
                    (array) $want,
                    $line !== null ? [(string) ($line['category'] ?? '')] : ($profile['categories'] ?? [])
                ) !== [],
                'flags' => array_intersect((array) $want, $profile['flags'] ?? []) !== [],
                default => is_array($want)
                    ? in_array($profile[$key] ?? null, $want, true)
                    : ($profile[$key] ?? null) === $want,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /** Evaluate the rule's computation; null when the basis value is absent. */
    private function compute(FeeRule $rule, array $profile, ?array $line = null): ?array
    {
        if ($rule->requires_officer) {
            return $this->item($rule, 0.0, requiresOfficer: true);
        }

        $c = $rule->computation;
        $basis = $this->basisValue($rule, $profile, $line);

        $amount = match ($c['type']) {
            'fixed' => (float) $c['amount'],
            'percentage' => $basis === null ? null : (float) $c['rate'] * $basis,
            'per_unit' => $basis === null ? null : (float) $c['unit_amount'] * $basis,
            'brackets' => $basis === null ? null : $this->bracket($c['brackets'], $basis),
            'brackets_excess' => $basis === null ? null : $this->bracketExcess($c, $basis, $profile),
            default => null,
        };
        if ($amount === null) {
            return null;
        }
        if (isset($rule->cap['max_amount'])) {
            $amount = min($amount, (float) $rule->cap['max_amount']);
        }

        return $this->item($rule, round($amount, 2));
    }

    private function basisValue(FeeRule $rule, array $profile, ?array $line): ?float
    {
        $key = $rule->basis;
        $raw = match ($key) {
            'fixed', 'none' => 0.0,
            'gross_sales' => $line['gross_sales'] ?? $profile['gross_sales'] ?? null,
            'capitalization' => $line['capitalization'] ?? $profile['capitalization'] ?? null,
            'units' => $profile[$rule->computation['unit_key'] ?? ''] ?? null,
            default => $profile[$key] ?? null,
        };

        return $raw === null ? null : (float) $raw;
    }

    /**
     * Rows are min-inclusive / max-exclusive; a row carries either a fixed
     * `amount` or a `rate` applied to the whole basis value (retailer 3%).
     *
     * @param list<array{min: float|int, max: float|int|null, amount?: float|int, rate?: float}> $rows
     */
    private function bracket(array $rows, float $value): ?float
    {
        foreach ($rows as $row) {
            if ($value >= $row['min'] && ($row['max'] === null || $value < $row['max'])) {
                return isset($row['rate']) ? (float) $row['rate'] * $value : (float) $row['amount'];
            }
        }

        return null;
    }

    /**
     * Above-threshold forms ("in excess of" rows). `>=` on the threshold so a
     * value landing exactly on the bracket/excess boundary resolves to the
     * excess base — arithmetically identical to the top bracket row.
     *
     *  - plain:    {threshold, base, rate}                 base + rate*(v-t)
     *  - tiers:    {tiers: [{threshold, max, base, rate}]} cascaded bases
     *  - step:     {threshold, base, step, step_amount}    "per P1,000 or fraction thereof"
     *  - variants: {variants: [{use, ...step form}]}       lessor residential split
     */
    private function bracketExcess(array $c, float $value, array $profile = []): ?float
    {
        $ex = $c['excess'] ?? null;

        if (isset($ex['variants'])) {
            $use = $profile['property_use'] ?? 'non_residential';
            $chosen = collect($ex['variants'])->firstWhere('use', $use) ?? $ex['variants'][0];
            $ex = $chosen;
        }

        if (isset($ex['tiers'])) {
            foreach ($ex['tiers'] as $tier) {
                if ($value >= $tier['threshold'] && ($tier['max'] === null || $value <= $tier['max'])) {
                    return (float) $tier['base'] + (float) $tier['rate'] * ($value - (float) $tier['threshold']);
                }
            }
            $ex = null; // below the first tier: fall through to the brackets
        } elseif ($ex !== null && $value >= ($ex['threshold'] ?? INF)) {
            if (isset($ex['step'])) {
                $steps = (int) ceil(($value - $ex['threshold']) / $ex['step']);

                return (float) $ex['base'] + (float) $ex['step_amount'] * $steps;
            }

            return (float) $ex['base'] + (float) ($ex['rate'] ?? 0) * ($value - (float) $ex['threshold']);
        }

        return $this->bracket($c['brackets'] ?? [], $value);
    }

    private function item(FeeRule $rule, float $amount, bool $requiresOfficer = false): array
    {
        return [
            'code' => $rule->code,
            'label' => $rule->title,
            'amount' => $amount,
            'office' => $rule->office,
            'group' => $rule->group,
            'section' => $rule->section,
            'source' => $rule->source,
            'requires_officer' => $requiresOfficer || $rule->requires_officer,
            'defects' => $rule->defects ?: null,
        ];
    }
}
