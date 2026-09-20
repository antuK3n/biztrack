<?php

use App\Models\FeeRule;
use App\Models\PsicCode;
use App\Support\TaxClassification as TC;

$titles = PsicCode::pluck('title', 'code');

/** Rule title + amount for a category, so a reviewer sees what it costs. */
$describe = function (string $group, string $category): string {
    $candidates = FeeRule::where('group', $group)->where('active', true)->get()
        // The graduated schedule, not a per-unit side rule: 'retailer' also keys
        // the PHP750-per-delivery-vehicle line, which is not what it is taxed on.
        ->sortBy(fn ($r) => ($r->computation['type'] ?? '') === 'per_unit' ? 1 : 0);
    foreach ($candidates as $rule) {
        if (! in_array($category, $rule->conditions['business_category'] ?? [], true)) {
            continue;
        }
        $c = $rule->computation ?? [];
        $amount = match ($c['type'] ?? '') {
            'fixed' => '₱'.number_format((float) $c['amount'], 0),
            'brackets', 'brackets_excess' => 'graduated',
            'per_unit' => 'per unit',
            default => $c['type'] ?? '?',
        };

        return $amount;
    }

    return '';
};

$rows = [];
$open = [];
$expected = [];
foreach (TC::FOR_PSIC as $code => [$class, $permit, $branch]) {
    $derived = TC::taxClass($code);
    $rows[] = sprintf(
        '| `%s` | %s | `%s` | %s | `%s` | %s | %s |',
        $code,
        $titles[$code] ?? '?',
        $derived ?? '—',
        $derived ? $describe('business_tax', $derived) : '',
        $permit ?? 'item 64 catch-all',
        $permit ? $describe('mayors_permit', $permit) : 'by office area',
        $branch === TC::BRANCH_ESSENTIALS ? 'asks essentials' : '',
    );

    /*
     * Falling to item 64 is EXPECTED for shops — Sec. 3A.03 has no sari-sari
     * or grocery category, so 'all other businesses not specifically
     * mentioned' is the right answer and not a question. Everywhere else it
     * means the Code does have a category and we could not pick it.
     */
    if ($permit === null && $class !== null) {
        $shop = in_array($class, ['retailer', 'wholesaler', 'essential_retailer', 'essential_wholesaler'], true);
        $row = sprintf('| `%s` | %s | `%s` |', $code, $titles[$code] ?? '?', $class);
        if ($shop) {
            $expected[] = $row;
        } else {
            $open[] = $row;
        }
    }
}

$asked = count(array_filter(TC::FOR_PSIC, fn ($r) => $r[2] !== null));
$silent = count(array_filter(TC::FOR_PSIC, fn ($r) => $r[0] !== null && $r[2] === null));
$unmappable = count(array_filter(TC::FOR_PSIC, fn ($r) => $r[0] === null));
$withPermit = count(array_filter(TC::FOR_PSIC, fn ($r) => $r[1] !== null));

$doc = <<<MD
# Line of business → Revenue Code class

**For BPLO's review.** Generated from `api/app/Support/TaxClassification.php`, which is
the single source of truth — this file is regenerated from it, never edited by hand.

## Why BizTrack needs this

The paper form has an **Assessed Fee** box that a clerk fills in by hand from the
Revenue Code. BizTrack has nobody in that seat, so it has to work out what the
clerk would have determined. Until 16 September 2026 it asked the *applicant* to
classify their own business, from a type-ahead of 273 Revenue Code labels.

That was **mis-billing**, not merely a hard question. Two fee groups key on two
different vocabularies, and the screen offered one box:

- `business_tax` matches the **22 broad classes** of Sec. 2J.02 — retailer,
  wholesaler, manufacturer, contractor, restaurant, bank …
- `mayors_permit` matches the **117 fine categories** of Sec. 3A.03 — carinderia,
  barber shop, cellphone dealer, movie house …

Measured on a carinderia with ₱1,200,000 of gross sales in 45 sq. m.:

| the applicant answered | billed | what happened |
|---|---|---|
| "Carinderia" | **₱2,218.25** | ₱550 permit fee, **no business tax at all** |
| "Restaurant" | **₱11,707.00** | ₱9,750 business tax, ₱450 catch-all permit fee |
| both | ₱11,968.25 | correct — and unreachable through one box |

The **more accurate** answer was the one that lost ₱9,750 — 81% of the bill.

A line of business determines both keys, so both are derived and the applicant is
asked neither.

## What the applicant is asked now

- **{$silent} of 135 codes** — nothing at all.
- **{$asked} codes** — one Yes/No. Sec. 2J.02(c) halves the rate for dealers in
  **essential commodities** and no industrial classification can tell rice from
  radios; a sari-sari store sells both. The essential list is the LGC Sec. 143(c)
  enumeration: rice and corn; flour, meat, dairy, locally manufactured/processed/
  preserved food, sugar, salt and other agricultural, marine and fresh-water
  products; cooking oil and cooking gas; laundry soap, detergents and medicine;
  agricultural implements and post-harvest facilities, fertilisers, pesticides and
  other farm inputs; poultry and other animal feeds; school supplies; cement.
- **{$unmappable} code** — `00000` "Other (not listed)", where the applicant typed their own
  trade and there is nothing to derive from. The old question is asked for that
  line alone.

> **One approximation to confirm.** A mixed trader is charged ONE rate on the whole
> of their gross. Apportioning essential from non-essential turnover is what the
> Code contemplates, and "mainly" is the approximation — the applicant's own
> declaration, on the record, rather than our guess.

## Open questions — please confirm or correct

A permit category of *item 64 catch-all* is **not an oversight**. Sec. 3A.03 item 64
prices "all other businesses not specifically mentioned" by office area, and that is
correct for retail stores, which have no category of their own. It is also what we
fall back to wherever the Code's fine category cannot be determined from the line of
business alone — because the spreads are wide and a wrong guess bills a real amount
confidently. A hotel is ₱2,200 to ₱11,000 by accredited class; a bar ₱4,400 to
₱16,500 by whether it has VIP rooms and live entertainment; a general building
contractor ₱1,100 to ₱6,600 by CAB class; PSIC 56101 covers both a ₱550 carinderia
and a ₱5,500 restaurant with multiple meal offerings.

**{$withPermit} of 135** codes carry a fine category today. These are the ones where the
Code has a category and we could not tell which — **please confirm or correct each**:

| PSIC | Line of business | Tax class |
|---|---|---|
OPEN_ROWS

These also fall to item 64, and that is **expected** rather than a question: Sec. 3A.03
has no category for a sari-sari store, a grocery or a general wholesaler, so "all other
businesses not specifically mentioned" is the right answer for them.

| PSIC | Line of business | Tax class |
|---|---|---|
EXPECTED_ROWS

## Two things this mapping cannot reach

**40 of the 165 mayor's-permit rules are unreachable** whatever is mapped here,
because they key on facts BizTrack never collects: `office_location` (35 rules),
`goods_class` (32), `warehouse_location` (24), `factory_location` (4). That is why a
manufacturer can only ever match the ₱6,050 "multiple products" rule and never the
₱4,400–₱8,800 ones that name what they handle. Collecting those four is a separate
decision — it would add four questions back to the wizard.

**Gross sales cannot be derived.** It is a fact only the business knows. The Code's
own fallback where nobody can produce it is the Presumptive Income Level applied by
the City Treasurer (Sec. 2O), not something BizTrack can compute.

## The full mapping

| PSIC | Line of business | Tax class | Tax | Permit category | Permit fee | Asks |
|---|---|---|---|---|---|---|
ALL_ROWS
MD;

// str_replace, not sprintf: the prose has literal per-cent signs in it.
$doc = str_replace(
    ['OPEN_ROWS', 'EXPECTED_ROWS', 'ALL_ROWS'],
    [implode("\n", $open), implode("\n", $expected), implode("\n", $rows)],
    $doc,
);

file_put_contents(base_path('../docs/psic-to-revenue-code-class.md'), $doc."\n");
echo 'wrote docs/psic-to-revenue-code-class.md — ', count($rows), ' rows, ',
    count($open), ' open questions, ', count($expected), " expected catch-alls\n";
