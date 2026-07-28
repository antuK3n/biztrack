# Fee rule data schema

Every file in this directory except this one is a JSON array of fee rules,
one file per ordinance article. `FeeRuleSeeder` loads them into the
`fee_rules` table; `FeeCalculator` evaluates them against an application's
fee profile. Rates live here as data so an ordinance amendment is a data
change, not a code change.

Source of truth: `docs/revenue-code-extract.md` (New Revenue Code of the
City of Malabon 2016, Ordinance A10-2016). A rule never invents a number;
every `amount`/`rate` traces to a cited section, and printed defects carry
the extract's Appendix A treatment.

## Rule object

```json
{
  "code": "biztax.manufacturer",
  "title": "Graduated tax on manufacturers",
  "section": "Sec. 2J.02(a)",
  "source": "A10-2016",
  "office": "BPLO",
  "group": "business_tax",
  "permit_types": ["BUSINESS"],
  "conditions": {
    "business_category": ["manufacturer"],
    "is_new_business": false,
    "min_capitalization": null,
    "flags": []
  },
  "basis": "gross_sales",
  "computation": { "type": "brackets_excess", "brackets": [], "excess": {} },
  "cap": null,
  "notes": "",
  "defects": []
}
```

## Field reference

| Field | Values / meaning |
|---|---|
| `code` | Unique dotted id: `{group-prefix}.{slug}`. Prefixes: `biztax`, `permit` (mayor's permit), `sanitary`, `garbage`, `env`, `occupancy`, `fire`, `market`, `admin`, `ctc`. |
| `section` | Ordinance citation as printed (`Sec. 3W.02`), or the national citation when `source` is not the ordinance. |
| `source` | `A10-2016` (the ordinance), `RA 9514` (Fire Code, FSIC 10% line), `DO 155` (DPWH reference figures), `RA 9178` (BMBE exemption), `RA 7160` (LGC). |
| `office` | `BPLO`, `CHO`, `BFP`, `OBO`, `CENRO`, `CMO-MARKET`, `CTO` (Treasurer: business tax, CTC). |
| `group` | `business_tax`, `mayors_permit`, `regulatory`, `service`, `admin`, `ctc`, `fire_code`, `city_charge`, `penalty`, `exemption_claim`. FSIC computes as 10% of `mayors_permit` + `regulatory` lines, so group assignment is load-bearing. |
| `permit_types` | Which requested permit types make the rule a candidate (`BUSINESS`, `SANITARY`, `FSIC`, `OCCUPANCY`, `CEC`, `MARKET`). |
| `conditions` | All must hold. Keys map to fee-profile facts (below). Omitted/null keys don't constrain. `business_category` and `flags` are any-of lists. |
| `basis` | What the computation consumes: `gross_sales`, `capitalization`, `floor_area_sqm`, `employees`, `units` (with `unit_key` naming the profile field), `construction_cost`, `stall_count`, `fixed`, `regulatory_subtotal` (FSIC only). |
| `computation.type` | `fixed`, `percentage`, `per_unit`, `brackets`, `brackets_excess`. |
| `cap` | `{"max_amount": n}` or `{"max_amount": n, "uplift_rate": 0.25}` for the garbage highest-rate+25% ≤ 6000 rule. |
| `defects` | Human notes referencing Appendix A of the extract when the printed source is anomalous. |

## Computation types

- `fixed`: `amount`.
- `percentage`: `rate` × basis value.
- `per_unit`: `unit_amount` × unit count (`unit_key` names the profile field).
- `brackets`: rows `{min, max, amount}`; `max: null` = open top; basis value
  selects the row (min inclusive, max exclusive).
- `brackets_excess`: `brackets` rows for the fixed range plus
  `excess: {threshold, base, rate}` — above `threshold`, fee =
  `base + rate × (basis − threshold)`.

Matrix schedules (e.g. manufacturers by goods class × office location) are
flattened into one rule per cell with the distinguishing facts as
`conditions` — there is no matrix type.

## Fee-profile facts (captured on the application)

`is_new_business`, `business_category[]` (per line of business),
`gross_sales` (preceding year, renewal), `capitalization` (new),
`floor_area_sqm`, `employees`, `storeys`, `doors`, `rooms`, `beds`,
`stall_count`, `delivery_vehicles_motorized`, `delivery_vehicles_other`,
`goods_class` (flammables|chemicals|dry_goods|perishables),
`office_location` / `warehouse_location` / `factory_location`
(within|outside), `property_use` (residential|non_residential — lessor
excess variant; defaults to non_residential), `building_type` (CENRO
construction-clearance taxonomy), `construction_cost`,
`occupancy_group`, `flags[]` (`sells_liquor`, `sells_tobacco_wholesale`,
`sells_tobacco_retail`, `has_signage`, `stores_flammables`, `is_franchise`,
`is_printing_publication`, `is_bmbe`, `is_cooperative`).

## Engine ordering rules (implemented in FeeCalculator, documented here)

1. Business tax resolves per line of business, then sums; franchise and
   printing/publication categories route to their 0.75% rules **instead of**
   the graduated table.
2. Environmental same-nature/same-barangay dedup: highest rate only.
3. Garbage: highest applicable schedule, then cap rule.
4. FSIC (RA 9514 Sec. 12(b)): 10% of the sum of `mayors_permit` +
   `regulatory` lines — computed last, never part of its own base.
5. Exemptions: `is_petroleum` is automatic (Sec. 2L.01 — engine suppresses
   `biztax.*` lines and emits the ₱0 citation line). `is_bmbe` and
   `is_cooperative` are claims, not automatic: tax computes normally, a ₱0
   `requires_officer` claim line records the flag, and the officer applies
   any reduction via `adjustFee` (RA 9178 only encourages LGU exemption;
   RA 9520 exemption depends on CDA registration terms).
6. Officer-discretion lines (PIL when no gross sales declared; market stall
   rental pending the separate Market Code) are emitted as `requires_officer`
   items with amount 0, adjusted via the existing `adjustFee` flow.
