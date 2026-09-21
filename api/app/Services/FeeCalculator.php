<?php

namespace App\Services;

use App\Models\Application;
use App\Models\FeeRule;
use App\Models\PsicCode;
use App\Support\TaxClassification;
use Illuminate\Support\Collection;

class FeeCalculator
{
    /** @return array{items: list<array<string,mixed>>, total: float} */
    public function assess(Application $app): array
    {
        $app->loadMissing('permitTypes', 'business.lines');

        $profile = $app->fee_profile ?? [];
        $requested = $app->permitTypes->pluck('code')->all();
        $profile['is_new_business'] = $app->application_type?->value === 'new';

        // lines of business: explicit profile lines win; fall back to the
        // registered business lines with the profile's shared figures.
        $lines = collect($profile['lines'] ?? []);
        if ($lines->isEmpty()) {
            $lines = $app->business->lines->map(fn ($l) => [
                'psic_code_id' => $l->psic_code_id,
                'gross_sales' => $profile['gross_sales'] ?? null,
                'capitalization' => $profile['capitalization'] ?? null,
            ]);
        }

        $lines = $this->classify($lines);

        /*
         * TWO lists, not one, and this is the fix for a real mis-billing.
         *
         * `business_tax` matches the 22 broad classes of Sec. 2J.02;
         * `mayors_permit` matches the 117 fine categories of Sec. 3A.03. They
         * are separate vocabularies, and while one field held one answer the
         * applicant could only ever satisfy one of them — a carinderia that
         * classified itself accurately as "Carinderia" got its ₱550 permit fee
         * and NO business tax at all, losing ₱9,750 on ₱1,200,000 of gross.
         *
         * So the fine categories join the broad ones here. The per-line
         * matching below is unaffected: `matches()` reads the LINE's own
         * `category` when a line is given, so a fine category in this list
         * cannot reach a business-tax rule and vice versa.
         */
        $categories = $lines
            ->pluck('category')
            ->merge($lines->pluck('permit_category'));

        /*
         * A THIRD key, from the liquor answer.
         *
         * The nine Sec. 3A.03 liquor rules key on how the liquor is sold —
         * `liquor_retailer`, `liquor_wholesaler`, `liquor_serving`,
         * `liquor_manufacturer`, `amusement_place` — and no line of business
         * maps to one, because nothing about a trade says whether the shop
         * happens to stock beer. While the wizard offered its 273-label
         * picker an applicant could type the category themselves; deriving the
         * class instead took that route away and left the flag inert for
         * everyone but an amusement place. See TaxClassification::LIQUOR_OF.
         */
        if (in_array('sells_liquor', $profile['flags'] ?? [], true)) {
            $categories = $categories->merge(
                $lines->pluck('category')
                    ->map(fn (?string $class) => TaxClassification::LIQUOR_OF[$class] ?? null)
            );
        }

        $profile['categories'] = $categories->filter()->unique()->values()->all();

        $rules = FeeRule::where('active', true)->get()
            ->filter(fn (FeeRule $r) => $r->group === 'penalty' ? false
                : array_intersect($r->permit_types, $requested) !== []);

        $items = [];

        // re: business tax: per line of business.
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
        /*
         * `zoning` sits here rather than in `regulatory` on purpose: RA 9514
         * Sec. 12(b) pegs the FSIC to the fees charged for building permits and
         * business/mayor's permits, and a locational clearance is neither. Filing
         * it under regulatory would quietly inflate every FSIC by 10% of it.
         */
        $plain = ['mayors_permit', 'admin', 'ctc', 'city_charge', 'exemption_claim', 'zoning'];
        // Ambulants and peddlers are exempt from zoning clearance (Sec. 3X.05):
        // the exemption line still prints, the chargeable zoning lines do not.
        $ambulant = in_array('is_ambulant_vendor', $profile['flags'] ?? [], true);
        $catchAll = null;
        $specificPermitMatched = false;
        foreach ($rules->whereIn('group', $plain) as $rule) {
            if ($rule->group === 'zoning' && $ambulant && $rule->code !== 'zoning.ambulant_exempt') {
                continue;
            }
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

    /**
     * Give every line the two Revenue Code keys that price it.
     *
     * The line of business decides both, so neither is asked of the applicant
     * any more: `psic_codes.category` is the Sec. 2J.02 tax class and
     * `psic_codes.permit_category` the Sec. 3A.03 fine category. See
     * App\Support\TaxClassification for the mapping and the reasoning, and
     * migration 2026_09_16_000100 for why one column could not do it.
     *
     * A `category` the filing ALREADY carries is left alone. That is not
     * deference to the browser — it is for the drafts filled in while the
     * wizard still asked the question, whose applicant gave an answer that is
     * theirs and not ours to overwrite mid-filing. New filings send none, so
     * they are classified here, where the browser cannot get it wrong.
     *
     * `essentials` is the one answer still taken from the applicant: Sec.
     * 2J.02(c) halves the rate for dealers in essential commodities, and no
     * industrial classification can tell rice from radios.
     *
     * `classifyProfile` is the same work done at WRITE time, so the filing
     * RECORDS what it was classified as instead of the classification being
     * re-derived every time somebody looks. Two reasons that matters: the
     * officer's review sheet shows the basis of the assessment, which has to
     * be the basis that was actually used; and the mapping is reference data
     * that can be corrected, so a filing assessed under the old mapping must
     * not silently re-price itself when the table changes underneath it.
     */
    public function classifyProfile(array $profile): array
    {
        if (($profile['lines'] ?? []) === []) {
            return $profile;
        }

        $profile['lines'] = $this->classify(collect($profile['lines']))->values()->all();

        return $profile;
    }

    private function classify(Collection $lines): Collection
    {
        $ids = $lines->pluck('psic_code_id')->filter()->unique();
        if ($ids->isEmpty()) {
            return $lines;
        }

        $codes = PsicCode::whereIn('id', $ids)
            ->get(['id', 'category', 'permit_category', 'category_branch'])
            ->keyBy('id');

        return $lines->map(function (array $line) use ($codes) {
            $psic = $codes->get($line['psic_code_id'] ?? null);
            if ($psic === null) {
                return $line;
            }

            if (($line['category'] ?? null) === null && $psic->category !== null) {
                $essential = ($line['essentials'] ?? false)
                    && $psic->category_branch === TaxClassification::BRANCH_ESSENTIALS;

                $line['category'] = $essential
                    ? (TaxClassification::ESSENTIAL_OF[$psic->category] ?? $psic->category)
                    : $psic->category;
            }

            $line['permit_category'] ??= $psic->permit_category;

            return $line;
        });
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
     * @param  list<array{min: float|int, max: float|int|null, amount?: float|int, rate?: float}>  $rows
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
            /*
             * WHICH PERMITS this line pays for, from the rule itself.
             *
             * Without it a bill was a flat list of money with no way back to
             * the permits it covered, and two things downstream had to guess:
             * the deferred-fee row, which recomputed the amount from a
             * different schedule and so disagreed with the bill it came from,
             * and the flat fallback, which could only ask "did ANY rule match"
             * rather than "did any rule match THIS permit".
             *
             * Null for a line that belongs to the filing rather than to a
             * permit — the application filing fee, the business tax — which is
             * a real distinction and not a missing value.
             */
            'permit_codes' => $rule->permit_types ?: null,
            'office' => $rule->office,
            'group' => $rule->group,
            'section' => $rule->section,
            'source' => $rule->source,
            'requires_officer' => $requiresOfficer || $rule->requires_officer,
            'defects' => $rule->defects ?: null,
        ];
    }
}
