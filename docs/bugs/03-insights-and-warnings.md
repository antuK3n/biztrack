# 03 — Business Location Insights, and over-technical warning copy

Read-only investigation. No source, database or git state was changed.
All figures below were reproduced against `api/database/database.sqlite` via
`php artisan tinker` SELECTs and pure-function calls (`LocationInsights::haversine`,
`PsicTaxonomy::group`, `PsicTaxonomy::category`, `LocationInsights::forPoint`).

Two client reports in scope:

1. *"Remove unnecessary warnings that are too technical (e.g. 'Computed locally, not by R…')"*
2. *"I tried setting the line of business to 'Manufacture of dairy products' and the Most common
   line of business is 'Manufacturing' (it did not specify which manufacturing) but the count of
   Similar businesses within 500 m did not increase."*

---

## Verdict up front

**The number 0 is correct.** There are no dairy manufacturers within 500 m of
`14.655921, 120.963814`. Do not "fix" it.

What is broken is that the panel puts two figures under adjacent headings that a reader will
assume share a definition, when they are computed off two different levels of the PSIC hierarchy —
and then labels the second one with a word (`Manufacturing`) that is a residual catch-all bucket
which specifically **excludes** the applicant's own trade. The client did the arithmetic a
reasonable reader would do, and the panel gave them no way to see why it did not hold.

**The ring and the count agree.** Investigated because it would be the worse bug; it is not
present. See "Not a defect" below.

---

## Reproduction baseline

Pin: `14.655921, 120.963814`. Applicant line: PSIC `10500` *Manufacture of dairy products*
(`psic_codes.id = 17`).

```
GET /api/v1/location-insights?latitude=14.655921&longitude=120.963814&psic_code_id=17
```

Register slice actually inside 500 m (33 businesses, matches the client's screenshot exactly):

| Category (`PsicTaxonomy::category`, 2-digit division) | Count |
|---|---|
| **Manufacturing** | **6** |
| Retail Trade | 4 |
| Construction | 4 |
| Business Support Services | 3 |
| Wholesale Trade | 3 |
| Foods & Beverages | 2 |
| Personal Services | 2 |
| **Food & Beverage Manufacturing** | **2** |
| Garments & Footwear | 2 |
| Wood, Paper & Printing | 2 |
| Water, Waste & Utilities | 2 |
| Motor Vehicles & Motorcycles | 1 |

The six businesses reported as "Manufacturing":

```
144m 31001 Manufacture of furniture              [grp 310]
362m 23950 Manufacture of concrete (hollow blocks)[grp 239]
402m 31001 Manufacture of furniture              [grp 310]
411m 22200 Manufacture of plastic products       [grp 222]
483m 22200 Manufacture of plastic products       [grp 222]
496m 22200 Manufacture of plastic products       [grp 222]
```

PSIC groups present within 500 m: `829×3, 222×3, 461×3, 410×3, 107×2, 310×2, 141×2, 181×2,
360×2, 562, 961, 477, 475, 432, 563, 962, 479, 239, 452, 471`.
**Group `105` (dairy) appears zero times.** Group `105` contains exactly one code in the
reference table (`10500`), so there is no wider reading of "same group" that would return more.

Nor would matching on the broader *category* have helped: `10500` is division `10` →
**Food & Beverage Manufacturing** (2 nearby: two bakeshops, `10711`), not **Manufacturing**
(6 nearby). The panel's two headings could not agree at either level of the hierarchy for this
applicant.

---

# Insights defects

## INS-1 — "Similar" and "Most common line of business" are computed off different levels of PSIC, under headings that imply one definition

*Client report 2.* **Severity: high.** **Class: misleading presentation** (the underlying numbers
are correct).

### Root cause

Two different keys, side by side in the same payload builder:

- `api/app/Support/LocationInsights.php:237` — `similar` filters on
  `PsicTaxonomy::group($row['psic_code']) === $group`.
  `api/app/Support/PsicTaxonomy.php:120-129` — `group()` returns the **first 3 digits**
  (`10500` → `105`).
- `api/app/Support/LocationInsights.php:269` — `commonType` groups by
  `PsicTaxonomy::category($row['psic_code'])`.
  `api/app/Support/PsicTaxonomy.php:132-141` — `category()` takes the **first 2 digits** and looks
  them up in a hand-written division→label map (`PsicTaxonomy.php:30-107`).

Both are dispatched from the same method a dozen lines apart
(`LocationInsights.php:88` and `:89`), and are rendered as consecutive rows of one table:
`web/src/pages/applicant/LocationInsightsPanel.tsx:263-278` (similar) and `:294-312`
(most common). Nothing on screen says the two rows count on different keys.

The divergence is a deviation from the spec's own table, which uses the same word for both:

> `docs/r-integration-spec.md:266` — "Nearby Similar Businesses | count of same/related
> **category** within 500 m"
> `docs/r-integration-spec.md:268` — "Most Common Business Type | **mode** of nearby business
> **categories**"

The narrowing to PSIC group appears only in the implementation notes below the table
(`docs/r-integration-spec.md:305-306`), and the reasoning there is sound (56301 coffee shop
should match 56302 bar, not 56101 restaurant). The defect is not the choice of key — it is that
the choice was never surfaced in the panel's headings.

### Observed vs expected

- Observed: `Similar businesses within 500 m = 0` immediately above
  `Most common line of business = Manufacturing (6 of 33)`, with the applicant's line reading
  *Manufacture of dairy products*.
- A reader's expected reading: "6 manufacturers are here, at least some of them should be
  similar to me."
- Actual semantics: 0 businesses share group `105`; the 6 share nothing with `105` at either
  the 3-digit or the 2-digit level.

### What must survive any fix

The group-level definition of "similar" is deliberate and load-bearing — widening it to the
2-digit division would make a coffee shop "similar" to a canteen and, worse, would put a bakeshop
(`10711`, division 10) in the same bucket as a dairy plant, which is the exact confusion
`PsicTaxonomy`'s own docblock (`PsicTaxonomy.php:21-25`) was written to prevent. Any fix has to
change the *labels*, not the keys.

**Binding data constraint:** `psic_codes` has only `id`, `code`, `title` (135 rows). There is no
group name and no division name in the register. A "similar" row that wants to print the
plain-language name of group `105` has nowhere to read it from today.

---

## INS-2 — `Manufacturing` is a residual bucket label, and it is not the bucket the applicant is in

*Client report 2, second half: "it did not specify which manufacturing".* **Severity: medium.**
**Class: misleading presentation.**

### Root cause

`api/app/Support/PsicTaxonomy.php:33, 40-53` maps sixteen unrelated PSIC divisions
(`12, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32`) to the single string
`'Manufacturing'`. It is the leftover bucket for everything the author did not give a specific
name to. In the seeded reference table it covers:

```
20230 soap and detergents        22200 plastic products
23950 concrete / hollow blocks   25920 machine shop
31001 furniture                  32110 jewellery
```

Meanwhile the applicant's own division `10` has its **own, more specific** label at
`PsicTaxonomy.php:31` — `'Food & Beverage Manufacturing'`. So the word the panel prints reads to
the applicant like a superset that contains them, and is in fact a sibling bucket that excludes
them by construction.

The client's complaint is exactly right and the code confirms it: the label carries no
information about *which* manufacturing, because it was never meant to — it is the fallback for
divisions nobody named.

### Observed vs expected

- Observed: `Most common line of business — Manufacturing`, note `6 of the 33 businesses near
  this pin`.
- The 6 are furniture, concrete, plastic ×3, furniture. A useful answer would name at least the
  dominant trade among them (`22200 Manufacture of plastic products`, 3 of 6) or say the bucket
  is mixed.
- More specific data **is** available: every one of the 33 rows already carries a 5-digit code
  and the register has its `title`. `LocationInsights::nearby` reads `psicCode:id,code`
  (`LocationInsights.php:123`) and drops the title on the floor.

---

## INS-3 — The "similar" note names the applicant's 5-digit sub-class as if that were the matching set

**Unreported.** **Severity: medium.** **Class: misleading presentation.**

### Root cause

`api/app/Http/Controllers/Api/LocationInsightsController.php:67` attaches
`$psic?->title` (the applicant's own **sub-class** title) to the `similar` block.
`web/src/pages/applicant/LocationInsightsPanel.tsx:266-270` renders it as:

> `Same PSIC group as your line: {psic_title}`

That sentence names the group and then prints a sub-class. For `10500` the two coincide (group
`105` has exactly one code) so the client's screenshot is harmless. For most codes it is not:
21 of the 135 reference codes sit in groups with siblings.

`similar.psic_group` (`"105"`) is declared in the frontend type at
`LocationInsightsPanel.tsx:70` and asserted by the API test at
`api/tests/Feature/LocationInsightsApiTest.php:162` — but is **never rendered**. That is the
right call in itself (`"105"` means nothing to an applicant); the consequence is that no
plain-language name for the matched set exists anywhere in the payload.

### Reproduction (understatement case, from live data)

Pin `14.659907, 120.953425` (business id 1569, line `56101` *Restaurants and carinderia*,
excluded as own):

```
similar: { psic_group: "561", count: 4, average_distance_m: 353 }
```

The 4 include `56102` *Fast-food and quick-service restaurants* at 110 m — correctly, per the
group rule — but the note tells the applicant the set is "Restaurants and carinderia". A
restaurateur reading it would conclude the 4 are all carinderias.

Seven such same-group / different-code neighbour pairs exist within 500 m across the current
register (`471` sari-sari ↔ grocery ×3, `561` carinderia ↔ fast-food ×4).

---

## INS-4 — The applicant's own category is never shown, so the mismatch is undiagnosable from the screen

**Unreported; the mechanism behind report 2.** **Severity: medium.** **Class: misleading
presentation.**

`LocationInsightsPanel.tsx:294-312` prints the modal *category* of the neighbourhood
(`Manufacturing`). The payload never carries the applicant's own category, and the panel never
prints it. There is therefore no element on screen from which a reader could learn that
"Manufacture of dairy products" is **not** in the bucket called "Manufacturing".

Had the panel shown "your line is in *Food & Beverage Manufacturing* (2 nearby)", the client
would have had their answer without filing a report. This is the cheapest of the four insights
findings to close and the one that most directly answers the reported confusion.

---

## INS-5 — Only the first declared line of business is ever considered, on both sides of the comparison

**Unreported.** **Severity: low.** **Class: wrong data (undercount).**

- Neighbour side: `api/app/Support/LocationInsights.php:149` —
  `$business->lines->first()?->psicCode?->code`. A business whose *second* declared line is dairy
  is invisible to a dairy applicant, and is counted under its first line in the mode.
- Applicant side: `web/src/pages/applicant/ApplyWizard.tsx:2191` —
  `const insightsPsicCodeId = form.lines[0]?.psic_code_id ?? null`. An applicant declaring dairy
  as their second line gets the first line's neighbourhood.

The choice is documented and defensible (`LocationInsights.php:146-148`: the first line is "the
one the register treats as its principal trade"). Blast radius today is genuinely small —
**5 of 709 businesses** in the register have more than one line. Recording it because it is a
silent undercount that grows with multi-line filings, not because it explains the client's report.

---

## Not a defect — checked because it would be worse than the reported bug

### The 500 m ring and the 500 m count are the same number from the same source

Confirmed. There is no second `500` anywhere in the client.

- `api/app/Support/LocationInsights.php:50` — `const RADIUS_M = 500`, the only definition.
- `LocationInsights.php:86` puts it on the response as `radius_m`; `:140` is the same constant
  deciding membership.
- `web/src/pages/applicant/ApplyWizard.tsx:2266` — `insightsRadiusM = insights.data?.radius_m ?? null`.
- `ApplyWizard.tsx:4711` passes it to `MapPicker`; `web/src/components/MapPicker.tsx:185-192`
  draws `<Circle radius={radiusM}>` in **metres** (Circle, not CircleMarker — correct, projected).
- Same value again feeds the caption at `ApplyWizard.tsx:4747-4751`
  ("The circle around it covers {n} m — the area the figures below count") and every row label in
  `LocationInsightsPanel.tsx:204, 260, 264, 282, 310`.
- Null until the first response, so no ring is ever drawn on a guessed radius
  (`MapPicker.tsx:113-122`).

### `0` is arithmetically right

Established above by direct count. Presenting a correct 0 as if it were a bug would be the wrong
outcome here; the panel's failure is that it invites a comparison it cannot support, not that it
miscounts.

### The API is not withholding precision from the "similar" row

`psic_title` (the 5-digit title) is the most specific thing the API sends and the panel *does*
show it (`LocationInsightsPanel.tsx:268`). `psic_group` is sent and correctly suppressed. The
precision that is missing is on the **other** row — the mode collapses a 5-digit code to a
2-digit label and the 5-digit titles are discarded before they reach the response
(`LocationInsights.php:123, 149`).

---

## Insights — tests that pass while these bugs exist

| Test | Why it misses |
|---|---|
| `api/tests/Feature/LocationInsightsApiTest.php:150-165` "counts only the applicant own PSIC group as similar" | Constructs a neighbourhood where the group key and the category key happen to agree (all five businesses are division 56 → `Foods & Beverages`). It asserts `similar.count = 3` and never looks at `common_type` in the same assertion, so the two keys are never compared. |
| `LocationInsightsApiTest.php:244-256` "reports the mode of nearby categories" | Asserts `category = 'Retail Trade'` from three division-47 codes. Never sets `psic_code_id`, so `similar` is `available: false` and the inconsistency cannot appear. |
| `LocationInsightsApiTest.php:167-178` "averages the distance to similar businesses only" | Same 561/563 setup; correct, and orthogonal. |
| `api/tests/Unit/LocationInsightsTest.php:12-32` (`group`) and `:34-61` (`category`) | Both pass — they are the *specification* of the two different keys. They assert `category('10711') === 'Food & Beverage Manufacturing'` (line 51) and that food manufacturing stays out of `Foods & Beverages`. Correct behaviour, tested in isolation; no test ever asserts that the two keys are *presented* consistently. |
| `LocationInsightsTest.php:35-42` "names divisions the way an applicant would" | Samples five specific labels (`Foods & Beverages`, `Retail Trade`, `Wholesale Trade`, `Construction`, `Personal Services`) — all of them genuinely specific. It never samples the residual `'Manufacturing'` bucket, so nothing fails when sixteen divisions collapse into one word. |
| `web/e2e/apply-wizard.spec.ts:709-960` | Asserts the panel renders on the map step, that a failed lookup does not block the filing, and that the loading/stale rule holds. Asserts on the row *label* `registered businesses in total`; never on the relationship between `similar` and `common_type`. |

There is no test anywhere that asserts a relationship between `similar.count` and
`common_type.count`. Adding one is how INS-1 gets locked shut.

---

# Over-technical warning copy

## The string the client quoted is assembled from three places

```
Computed locally, not by R. This window is not one of the precomputed windows, so the R service
has no result for it. These figures were computed this minute to answer this request.
```

| Fragment | Source |
|---|---|
| `Computed locally, not by R.` (bold) | `web/src/pages/admin/ComputedAt.tsx:108` |
| `This window is not one of the precomputed windows, so the R service has no result for it.` | `api/app/Support/AnalyticsResolver.php:136`, via `meta.notice` |
| `These figures were computed {relative time} to answer this request.` | `web/src/pages/admin/ComputedAt.tsx:110`, `{when}` from `:94-98` |

Rendered as an orange-tinted `role="status"` panel at `ComputedAt.tsx:102-119`.

---

## WARN-1 — The fallback banner is technical, and it is asking a BPLO officer to reason about a system boundary they cannot act on

*Client report 1, verbatim.* **Severity: high (it is the reported one).** **Class: noise.**

### Root cause

`web/src/pages/admin/ComputedAt.tsx:102-119`, fed by
`api/app/Support/AnalyticsResolver.php:127-138` (`noticeFor`).

### Who sees it

`<ComputedAt>` is mounted on all four analytics screens:

| Screen | File:line | Role |
|---|---|---|
| Analytics Dashboard | `web/src/pages/admin/AnalyticsPage.tsx:1356` | BPLO |
| Renewal Risk Prediction | `web/src/pages/admin/RenewalRiskPage.tsx:890` | BPLO |
| Business Growth Analysis | `web/src/pages/admin/BusinessGrowthPage.tsx:268` | BPLO |
| Processing Time Monitoring | `web/src/pages/admin/ProcessingTimePage.tsx:687` | Super Admin |

Applicants never see it — `LocationInsightsController.php:71-76` emits the same `meta` shape
but `LocationInsightsPanel.tsx` renders none of it. Deliberate, per that controller's docblock,
and correct.

### What it is actually warning about

That the figures on screen came from `App\Support\*Analytics` (PHP) rather than from a stored R
snapshot, and that the two are separate implementations which could disagree.

### Must the information survive?

**Partly. Split it in two.**

The honesty guarantee is documented in three places and is real:

> `api/app/Support/AnalyticsResolver.php:25-30` — "Presenting fallback output as R output would
> make the two implementations' drift invisible, which is precisely the risk the fallback
> introduces."
> `web/src/pages/admin/ComputedAt.tsx:27-30` — "The fallback is a second implementation of the
> same statistics, and two implementations can drift; a screen that presented fallback output as
> R's would make that drift invisible. AnalyticsParityTest keeps the two honest, this line keeps
> them distinguishable."
> `api/resources/views/pdf/partials/local-notice.blade.php:4-8` — same argument for the printed
> report.

So the *provenance fact* must remain recoverable. But "recoverable" and "printed in an orange
banner at the top of the screen every time" are different requirements, and the second is what
the client is objecting to.

- **The fact that PHP computed it: keep, in machine-readable form.** `meta.source`, `meta.engine`,
  `meta.fallback_reason` are already on the payload and are what `AnalyticsParityTest` and the
  PDF partial hang off. Nothing about removing the banner requires removing those.
- **The explanation of the R/PHP split: remove from the officer's screen.** A BPLO officer cannot
  act on "the R service has no result for this window". They did not choose the R architecture,
  they cannot add a variant to `config/analytics.php`, and clicking *Refresh now* will not help —
  `window_not_precomputed` is a configuration answer, not an outage, and `AnalyticsResolver.php:117`
  says so in a comment the officer cannot read. **A reader who cannot act on a fact should not be
  shown it.**
- **The one thing the reader genuinely needs, and would lose:** the difference between "these
  figures are current" and "these figures are as old as the last refresh". That is not an
  R-vs-PHP fact, it is a freshness fact, and it survives fine as plain copy
  (`Computed just now` vs `Computed 6 hours ago`) with no mention of an engine.

**This is "should not be shown to this reader at all", not merely "too technical".**

---

## WARN-2 — The fallback banner is the *designed* outcome for most of the window options the UI itself offers

**Unreported, and it is the reason the client keeps meeting WARN-1.** **Severity: high.**
**Class: misleading presentation.**

`analytics:refresh` only precomputes the variants listed in `api/config/analytics.php:58-82`.
Anything else misses the snapshot and drops to PHP — `AnalyticsResolver.php:40-41, 69`. But the
screens offer window selectors far wider than that list:

| Screen | Options the UI offers | Precomputed (`config/analytics.php`) | Always shows the orange banner |
|---|---|---|---|
| Analytics Dashboard | 3, 6, 12, 24, 36 months (`AnalyticsPage.tsx:165-169`) | `months: 12` only (`config/analytics.php:60-64`) | **4 of 5** |
| Business Growth | 3, 6, 12, 24, 36 months (`BusinessGrowthPage.tsx:77-81`) | 12, 24, 36 (`config/analytics.php:73-77`) | 2 of 5 |
| Processing Time | 13, 26, 52, 104 weeks (`ProcessingTimePage.tsx:75-80`) | 26, 52 (`config/analytics.php:67-71`) | 2 of 4 |
| Renewal Risk | 30, 60, 90, 180, 365 days (`RenewalRiskPage.tsx:80-86`); 25/50/100 rows (`:105-109`); barangay / level / action filters; pagination | `days 90 limit 25`, `days 365 limit 25` (`config/analytics.php:79-81`) | 3 of 5 horizons, **plus every filtered request, every page after the first, and every row count ≠ 25** — the filters and offset ride in the snapshot key (`api/app/Http/Controllers/Api/AnalyticsController.php:135-144`, documented at `:112-123`) |

So on the BPLO's main Analytics Dashboard, four out of five choices in the Window dropdown
produce an orange "something is degraded" panel — and nothing is degraded. The banner is
labelling normal, intended, correct operation as an exception. That is why the client reads it as
noise: it *is* noise, at the rate it currently fires.

This also means WARN-1 cannot be fixed purely by rewording. Either the notice stops being an
alert-shaped panel for the `window_not_precomputed` case, or `config/analytics.php:58-82` grows to
cover the options the UI offers. The `r_disabled` / `not_yet_refreshed` cases are genuine
anomalies and are a different class from this one — see WARN-3.

---

## WARN-3 — The other three fallback notices, all from the same `match`

**Unreported (same class as report 1).** **Severity: medium.** **Class: mixed — see per-row
judgement.**

`api/app/Support/AnalyticsResolver.php:127-138`:

| Line | Exact text | Trigger | Seen by | Judgement |
|---|---|---|---|---|
| `:134` | `This view is not computed in R yet, so these figures come from the local implementation.` | `AnalyticsDatasets::get($dataset)['endpoint'] === null` — R has no endpoint at all for this dataset | BPLO / admin, all four analytics screens | **Remove entirely.** It describes an unfinished piece of the build. Nobody on the LGU side can act on it and nothing will change until a developer ships an endpoint. Pure implementation status leaked to a user. |
| `:135` | `The R statistics service is switched off for this environment.` | `RAnalytics::enabled()` false | same | **Remove the wording, keep a signal for admin only.** This is a deployment state (`ANALYTICS_R_*` env), not something a BPLO officer configures. If it is worth surfacing at all it belongs on a health/ops surface, not above the figures. |
| `:136` | `This window is not one of the precomputed windows, so the R service has no result for it.` | window outside `config/analytics.php` `variants` | same | **The client-quoted one.** See WARN-1 and WARN-2 — it fires on normal use and is not an error. |
| `:137` (default) | `The R statistics service has no result for this view yet — run the analytics refresh.` | `not_yet_refreshed` — the variant *is* precomputed but the refresh has not run or failed | same | **Keep the information, drop the vocabulary.** This is the one case in the four where the reader *can* act: the *Refresh now* button next to it does exactly what the sentence asks. Reword without naming R (e.g. "These figures have not been recomputed yet"). |

The reason-codes themselves (`no_r_endpoint`, `r_disabled`, `window_not_precomputed`,
`not_yet_refreshed`) are correctly separated at `AnalyticsResolver.php:96-114` and typed at
`web/src/lib/types.ts:734-742`. Nothing here argues for collapsing them — the API may keep all
four; the question is only which of them earns screen space.

---

## WARN-4 — The normal-state provenance line names R and prints the R version number

**Unreported.** **Severity: low.** **Class: noise.**

`web/src/pages/admin/ComputedAt.tsx:122-126`:

```
Computed {relative time} by R{ engine_version } · updates when the analytics refresh runs, not on page load
```

Renders on every analytics screen in the *non*-fallback case, e.g. `Computed 6 hours ago by
R 4.2.1 · updates when the analytics refresh runs, not on page load`.

**Judgement: split.**

- `Computed 6 hours ago` — **must survive verbatim in meaning.** `ComputedAt.tsx:16-19` records
  the exact case it exists for: a tester files an application, opens the dashboard, does not see
  it, and reads a correct design as a bug. That is a real, recurring misread and the timestamp is
  the cheapest possible fix for it.
- `by R 4.2.1` — **can go from the screen.** An R patch version tells a BPLO officer nothing they
  can use. It is genuinely useful on the printed report (see WARN-6) where the document outlives
  the screen; it is not useful in a header.
- `updates when the analytics refresh runs, not on page load` — **keep the meaning, drop the
  jargon.** "the analytics refresh" is an internal artisan command name showing through. The fact
  it conveys (this page does not recompute when you open it) is the same fact the timestamp
  already implies; if it stays it should be phrased as a fact about the figures, not about a job.

---

## WARN-5 — Staleness badge blames "the scheduled refresh"

**Unreported.** **Severity: low.** **Class: noise, for this reader.**

`web/src/pages/admin/ComputedAt.tsx:130-132`:

```
Over {stale_after_hours} hours old — the scheduled refresh may not be running.
```

Threshold from `api/config/analytics.php:96` (`ANALYTICS_STALE_AFTER_HOURS`, default 25).

**Judgement: keep the age, drop the diagnosis.** "Over 25 hours old" is a fact about the figures
in front of the reader and is exactly the kind of qualification an oversight screen should carry —
`config/analytics.php:90-94` argues, correctly, that "a stale figure with an honest timestamp
beats no figure at all". But "*the scheduled refresh may not be running*" is a hypothesis about
server-side cron addressed to someone with no access to it. It names an internal job and offers a
BPLO officer a fault they cannot investigate. That half is for a monitoring channel, not a
dashboard.

---

## WARN-6 — The Refresh control and its result messages name R

**Unreported.** **Severity: low.** **Class: noise.**

| File:line | Text | Notes |
|---|---|---|
| `web/src/pages/admin/ComputedAt.tsx:82` | `Recomputing…` / `Refresh now` | Fine as-is; the verb matches what happens. |
| `api/app/Http/Controllers/Api/AnalyticsController.php:387` | `R analytics is switched off, so there is nothing to refresh. The screens are computing locally.` (HTTP 409) | Surfaced through `ComputedAt.tsx:84-88` when the button is pressed. |
| `AnalyticsController.php:394` | `The R statistics service did not answer. The screens keep serving the last figures and say how old they are.` (HTTP 503) | same |
| `AnalyticsController.php:429-434` | `{n} figure set(s) recomputed by R {version}.` | success |
| `AnalyticsController.php:438` | `R {version} could not compute any figures. The screens keep the last ones.` | total failure |
| `AnalyticsController.php:441-445` | `{n} recomputed by R {version}, {m} failed. Those screens keep their previous figures.` | partial — shown via `ComputedAt.tsx:64` |

**Judgement: keep the outcome, drop the engine.** These fire only in response to a deliberate
click, so unlike WARN-1 they are not unsolicited — that alone puts them in a much lower tier. But
every one of them names R when the actionable content is "did it work / are the figures newer
now". `AnalyticsController.php:378-379` argues the 409 and 503 must stay distinguishable because
"the fix differs" — true, and both fixes are a developer's, not the clicking officer's.

Related, **CLI only, out of scope** (`php artisan` console output, never on screen):
`api/app/Console/Commands/RefreshAnalytics.php:149, 155, 160`.

---

## WARN-7 — The printed PDF reports carry the same R/PHP copy

**Unreported.** **Severity: low.** **Class: noise — but this is the one place the information is
genuinely load-bearing.**

| File:line | Text |
|---|---|
| `api/resources/views/pdf/partials/computed-by.blade.php:17-22` | `Computed {date}` + `by R {version}` **or** `locally, not by R` |
| `api/resources/views/pdf/partials/local-notice.blade.php:14-17` | `These figures were computed locally, not by the R statistics service. {meta.notice} The R implementation is the reference; a local computation should agree with it, but this report was not produced by it.` |

Included by all four report templates:
`analytics-dashboard-report.blade.php:35, 41`, `processing-time-report.blade.php:34, 39`,
`business-growth-report.blade.php:33, 39`, `renewal-risk-report.blade.php:34, 39`.
Note `local-notice` embeds `{{ $meta['notice'] }}` — so **the client-quoted sentence is also
printed into the exported PDFs**, not just shown on screen.

**Judgement: keep, and keep it more literally than the screen version.** The argument at
`computed-by.blade.php:11-13` is the strongest in this whole inventory and it does not apply to
the screen: a PDF is forwarded, filed and quoted months later, and the reader holding it cannot
ask which engine produced it. Provenance that would be noise in a header is evidence in a
document.

The reasonable outcome is asymmetry: strip the engine vocabulary from the screens, keep it on the
paper. If a single change is made to `local-notice.blade.php` it should be to plain-language the
sentence, not to delete it. Deleting it is the one edit in this document that would break a
documented honesty guarantee outright.

---

## Warnings — tests that pass while these exist

| Test | Why it misses |
|---|---|
| `api/tests/Feature/RenewalRiskFollowUpTest.php:444` | `expect($filtered->json('meta.notice'))->not->toBeNull();` — asserts a filtered request *produces* a notice. It pins the behaviour WARN-2 identifies as over-firing, without asserting any wording. It would keep passing through a full rewrite of the copy, and would **fail** if the notice were made conditional on the request being a genuine anomaly. This is the one test a WARN-2 fix has to negotiate with. |
| `api/tests/Feature/AnalyticsInsightsTest.php:497` | `toHaveKeys(['source', 'engine', 'computed_at', 'fallback_reason'])` — shape only, no strings. Green through any copy change. Also the test that would catch removal of the provenance *fields*, so it is the guard that lets the copy change safely. |
| `web/e2e/analytics.spec.ts:377-379` and `:516` | `page.getByText(/computed|updated|as of/i).first()` — matches any of three words. Passes whether the banner is the orange fallback panel or the quiet line, and passes after almost any rewording that keeps the word "computed". It does not distinguish the three states `ComputedAt.tsx:21-25` says are deliberately distinguished. |

**No test anywhere asserts the exact text of any of the four `noticeFor` strings**
(`AnalyticsResolver.php:134-137`), and no test asserts that `ComputedAt`'s fallback branch renders
at all. Copy can be changed freely; the risk is the opposite one — the honesty guarantee is
enforced by `api/tests/Unit/AnalyticsParityTest.php` (R-vs-PHP agreement on shared fixtures) and by
the shape assertion above, not by any test that the user is *told*. Removing the banner will not
turn any test red. That is exactly why the reasoning in WARN-1/WARN-7 has to be written down
rather than left to the test suite.

---

# Blast radius and interference

Three other investigations are running: **(A)** inspection / approval flow, **(B)** office
separability on the review sheet, **(C)** the clearance withdraw dead-end.

## Files this investigation would touch

| File | Overlap |
|---|---|
| `api/app/Support/LocationInsights.php` | None. Sole consumer is `LocationInsightsController`. |
| `api/app/Support/PsicTaxonomy.php` | None with A/B/C, **but** it is consumed by `LocationInsights` only — verified. Changing `DIVISIONS` labels (INS-2) changes `common_type.category` strings, which are asserted in `tests/Unit/LocationInsightsTest.php:35-58`. |
| `web/src/pages/applicant/LocationInsightsPanel.tsx` | None. One consumer. |
| `api/app/Http/Controllers/Api/LocationInsightsController.php` | None. |
| **`web/src/pages/applicant/ApplyWizard.tsx`** | **Overlaps C.** The LGU Clearances step lives in this file, and the clearance withdraw dead-end investigation will very likely edit it. My INS work needs at most lines 2191 (INS-5) and 4790 (panel props); C's work is elsewhere in a ~5,500-line file, but it is a real merge surface. Coordinate before either lands. |
| `web/src/components/MapPicker.tsx` | None, and nothing to change — the ring is correct. |
| `api/app/Support/AnalyticsResolver.php` | None with A/B/C. Analytics-only. |
| `web/src/pages/admin/ComputedAt.tsx` | None. Mounted only by the four analytics screens. |
| `api/app/Http/Controllers/Api/AnalyticsController.php` | None. |
| `api/config/analytics.php` | None. |
| **`web/src/lib/types.ts`** | **Overlaps all three.** `AnalyticsProvenance` and `AnalyticsFallbackReason` (`:734-773`) sit in the same file as `ApplicationStatus` (`:224`) and every other shared type. A/B/C will all plausibly edit this file. My changes there are additive and confined to the analytics block; still a merge point. |
| **`api/resources/views/pdf/partials/`** | **Possible overlap with B.** `computed-by.blade.php` and `local-notice.blade.php` are analytics-only, but the office-separability work is on review-sheet PDFs and may add or edit partials in the same directory. Different files; same directory. |

## Explicit non-overlap

The four analytics screens (`AnalyticsPage`, `RenewalRiskPage`, `BusinessGrowthPage`,
`ProcessingTimePage`) and the applicant apply-wizard map step are **not** shared surface with any
of A, B or C. The inspection/approval flow, the review sheet and the clearance withdraw path
touch none of them.

The one genuine cross-cutting risk is that `meta.notice` handling and `ComputedAt` share
`web/src/lib/types.ts` with everything else in the app.

---

# What I could not determine

- **I did not drive the browser.** All reproduction was against `api/database/database.sqlite`
  via tinker, which reproduced the client's screenshot figures exactly (33 total, Manufacturing 6,
  similar 0). I did not confirm that the dev server on `:5199` reads that same file, nor did I hit
  `GET /api/v1/location-insights` over HTTP — it needs a session and the tinker path exercises the
  identical code with no intermediary. If the client's environment is the tunnelled `:5173`
  database, the counts there may differ; the *structural* findings (INS-1 through INS-5) do not
  depend on the data.
- **Whether the R service is actually running anywhere.** Every fallback reason enumerated in
  WARN-3 is derived from `missReason` (`AnalyticsResolver.php:96-114`); I did not check
  `RAnalytics::enabled()` in any live environment, so I cannot say which of the four the client
  most often meets in practice — only that `window_not_precomputed` is the one they quoted and
  that WARN-2 shows it fires on the majority of the UI's own options.
- **Whether "Manufacturing" as a label was a deliberate lumping or an oversight.**
  `PsicTaxonomy.php:21-25` explains the *narrowing* decisions (why bakeshops are not
  "Foods & Beverages") but says nothing about why fifteen divisions were left to share one word. I
  read it as the residual case rather than a design choice, but that is inference.
- **What the client would accept as "which manufacturing."** INS-2 establishes that more specific
  data exists (5-digit titles are on every row and are discarded at `LocationInsights.php:123`);
  it does not establish what granularity is wanted. That is a question for the client, not for the
  code.
- **Whether a group-level plain-language name can be sourced.** `psic_codes` has only
  `id, code, title`. Whether the PSIC 2009 group titles can be obtained and seeded is outside what
  I could check read-only. Every fix to INS-1 and INS-3 that prints a group *name* depends on it.

---

# Constraints any fix must respect

1. **`meta.source` / `meta.engine` / `meta.fallback_reason` stay on the payload.** They are what
   `AnalyticsParityTest` and the PDF partials read. Removing screen copy must not remove the
   fields. (`AnalyticsResolver.php:25-30`.)
2. **The printed reports keep naming the engine.** `computed-by.blade.php:11-13` — a PDF outlives
   its exporter and the provenance has to travel with the document.
3. **`meta.computed_at` keeps being displayed on every analytics screen.** Not for R's sake — for
   the documented misread at `ComputedAt.tsx:16-19`. Any rewrite that drops the timestamp trades
   one support ticket for another.
4. **"Similar" keeps matching on the 3-digit PSIC group.** Widening it to the division would
   re-introduce exactly the confusion `PsicTaxonomy.php:21-25` was written to prevent — and, for
   this client, would put a bakeshop in the same bucket as a dairy plant.
5. **`00000` keeps relating to nothing.** `PsicTaxonomy.php:124` and the two `reason` codes at
   `LocationInsights.php:58, 61` exist because checklist item 67 lets applicants type their own
   trade under "Other". Any change to `group()` must preserve the null.
6. **The insights panel stays advisory and non-blocking.** `LocationInsightsPanel.tsx:237-254` and
   `ApplyWizard.tsx` `stepMissing` must never consult `insights`.
7. **The 500 in the ring and the 500 in the count remain one constant, stated by the server.**
   `LocationInsights.php:50` → `radius_m` → `MapPicker.radiusM`. Do not add a client-side default.
