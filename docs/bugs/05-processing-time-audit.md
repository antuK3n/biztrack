# 05 — Permit Processing Time Monitoring: audit

**Date:** 2026-08-06 · **Branch:** main · **Scope:** spec §6, `/staff/analytics/processing-time`
**Method:** source read; live API (`GET /api/v1/analytics/processing-time` at `weeks` = 1, 3, 5, 13, 26,
52, 104, 200, `abc`) signed in as `admin@biztrack.local`; read-only SQLite queries against
`api/database/database.sqlite`; `pest tests/Feature/AnalyticsDefinitionsTest.php`.
**No source was modified. No writes were made to any database.**

Files in scope:

| File | Role |
| --- | --- |
| `web/src/pages/admin/ProcessingTimePage.tsx` | the screen |
| `web/src/components/charts/SpcControlChart.tsx` | the chart + its text equivalent |
| `api/app/Support/ProcessingTimeAnalytics.php` | SQL + payload shaping |
| `api/app/Support/Spc.php` | the statistics |
| `api/app/Support/AnalyticsDefinitions.php` L292–330 | the info-hover text |
| `api/config/analytics.php` L89–95 | precomputed windows |
| `api/resources/views/pdf/processing-time-report.blade.php` | the "Generate Report" output |
| `api/app/Http/Controllers/Api/AnalyticsController.php` L59–76, L523–529 | route + `weeks` clamp |

---

## Verdict up front

The four things the spec asks for are all present, and three of them are well built. The
**statistics are not currently honest**, but not for the reason that was expected. The control
limits, the EWMA and the flagging arithmetic are all sound and match `qcc`. What is wrong is
everything wrapped around them:

- the **most recent week on every chart is an in-progress week**, judged as if it were complete
  (PT-1). This is what the Process Status Indicator reads. Right now it makes 5 of 7 offices red;
  3 of those 5 flip to "Within Normal Range" the moment the partial week is removed;
- the chart **inverts the direction** of every EWMA-drift week, printing "Faster than normal" on the
  weeks of a genuine, ramping slowdown (PT-2), and contradicts the Noted Delays panel sitting
  beside it on the same screen for the same week;
- "Outside Normal Range" **carries no direction at all**, so an office that got *faster* is painted
  in error red (PT-4). Every red card on the screen at 52 weeks right now is a speed-up.

RBAC is correct: the admin account holds `analytics.processing_time` and not `analytics.view`;
`web/e2e/analytics.spec.ts` L569 holds BPLO out of this screen. Accessibility is genuinely good —
the text equivalent exists, is reachable by sighted and non-sighted readers alike, `aria-disabled`
is used correctly throughout, and nothing is carried by hue alone. It is however **accurate only
where the chart is accurate**, so PT-2 and PT-3 land on it too.

Two of the three items flagged in the brief check out as stated. The third does not:
`Spc::MIN_COMPLETIONS_PER_WEEK = 3` and `CALIBRATION_WEEKS = 24` are defensible; the boundary case
is real but small (PT-7). The empirical small-sample bias I was asked to look for **is not present
in this data**, and the reason is worth reading (see "On small samples and wide limits").

---

# Part 1 — Defects

## PT-1 · The in-progress week is charted as a complete week, and it is the week the status indicator reads

**Severity: Critical · Wrong data**

**Root cause.** `ProcessingTimeAnalytics::dataset()` (`api/app/Support/ProcessingTimeAnalytics.php`
L73–83) filters `completed_at >= $windowStart` with **no upper bound**. So today's partially-elapsed
ISO week is bucketed and averaged exactly like the 51 complete weeks before it. `shape()` then reads
`$rows[count($rows) - 1]` (L253) and publishes that week as `status` (L266) — the Process Status
Indicator.

A week in progress is right-censored: only the reviews that have *already finished* are in it, which
is a sample biased towards the fastest. Today is Thursday 2026-08-06; the week of 2026-08-03 is
three days old.

**Reproduction.** `GET /api/v1/analytics/processing-time?weeks=52`:

| Office | in-progress week (2026-08-03) | verdict | last **complete** week (2026-07-27) | verdict |
| --- | --- | --- | --- | --- |
| BPLO | n=6, mean **0.001 d** | outside | n=12, mean 1.354 d | outside |
| BFP | n=3, mean **0.004 d** | **outside** | n=8, mean 3.267 d | **inside** |
| OBO | n=3, mean **0.004 d** | **outside** | n=5, mean 3.486 d | **inside** |
| CENRO | n=4, mean **0.373 d** | **outside** | n=6, mean 2.952 d | **inside** |
| CPDO | n=4, mean 1.119 d | inside | n=5, mean 2.646 d | inside |
| CMO-MARKET | n=3, mean 0.719 d | inside | n=9, mean 2.138 d | inside |

**BFP, OBO and CENRO are shown "Outside Normal Range" in error red purely because the week is not
over yet.** Sample size in the in-progress week is half to a third of each office's median week
(BFP 3 vs 10; BPLO 6 vs 12).

This is not a seeding artifact that goes away in production. In production, every Monday and Tuesday
morning the current week will contain only same-day reviews, and offices will be flagged. The screen
is at its most wrong on the mornings an oversight reader is most likely to open it.

**Compounding it (PT-15):** the reviews in that week are not real work. Direct query of
`application_assignments` for `completed_at >= '2026-08-03'` returns 34 rows, of which ~26 have a
turnaround under 15 minutes and 7 under 2 minutes — QA/demo clicking on 2026-08-05 and 2026-08-06.
Example: `BPLO 2026-08-06 08:16:27 -> 2026-08-06 08:17:22` = 55 seconds. Nothing in the schema or
the query distinguishes a demo transaction from a real one.

**Blast radius.** The Process Status Indicator (7 cards), the chart's last point, the a11y summary's
"weeks went beyond that range" count, Noted Delays, the trend/EWMA (the last week is weighted most
heavily by construction), and the PDF report. Also `StaffingSimulation` and `DashboardAnalytics`,
which the code comment at `WorkflowService.php` L164–168 says read the same clock.

**Constraint any fix must satisfy.** The chart may draw the in-progress week, but the week that the
**Process Status Indicator reads must be a complete one**, and a drawn partial week must be visibly
marked as partial. Truncating the window at the last complete Monday is the smaller change; it costs
up to 6 days of latency on a screen whose data is refreshed daily anyway (`stale_after_hours = 25`).

---

## PT-2 · The chart labels every EWMA-drift week "Faster than normal" — including the worst weeks of a real slowdown

**Severity: High · Wrong data**

**Root cause.** `web/src/components/charts/SpcControlChart.tsx` L92–98:

```ts
export function spcWeekSide(point, department): 'normal' | 'slower' | 'faster' {
  if (point.status !== 'out_of_control') return 'normal'
  return point.mean_days > department.ucl ? 'slower' : 'faster'
}
```

A week is `out_of_control` under **either** of two rules (`Spc::analyse`, `api/app/Support/Spc.php`
L227–236): `beyond_limits`, or `ewma_drift`. An `ewma_drift`-only week has a mean that is **inside**
`[lcl, ucl]` by definition. The ternary therefore falls to `'faster'` for every one of them,
regardless of which side of the centre line it sat on.

Consequence at three call sites in the same file:
- L109 — the dot is filled `#14171d`, which the legend at L237–239 names "Faster than normal";
- L292 — the text-equivalent table's "Against the normal range" column reads **"Faster"**;
- L192–195 — the tooltip reads "Faster than the normal range".

**Reproduction.** CHO at `weeks=52` (band 1.399–4.108, centre 2.753). The seeder plants a deliberate
8-week ramping slowdown in CHO (`AnalyticsHistorySeeder.php` L184–190, ×1.35 → ×1.95) and the EWMA
correctly catches it:

| week | n | mean | deviation | rule_hit | chart draws it as | Noted Delays says |
| --- | --- | --- | --- | --- | --- | --- |
| 2026-06-22 | 6 | 4.232 | +1.479 | `beyond_limits+ewma_drift` | Slower ✓ | +1.48 slower ✓ |
| **2026-06-29** | 13 | **3.959** | **+1.205** | `ewma_drift` | **Faster ✗** | +1.21 **slower** |
| 2026-07-13 | 14 | 5.506 | +2.753 | `beyond_limits+ewma_drift` | Slower ✓ | +2.75 slower ✓ |
| **2026-07-27** | 7 | **2.809** | **+0.056** | `ewma_drift` | **Faster ✗** | +0.06 **slower** |

2026-06-29 is the fourth week of a documented, ramping slowdown. The chart calls it *faster than
normal*. **Noted Delays and the chart, side by side on the same screen, give opposite readings of
the same week.** BPLO shows the same defect on 4 weeks at `weeks=52`; CENRO on 2 weeks at
`weeks=104`.

**Blast radius.** Every `ewma_drift`-only week on every office at every window — 6 weeks visible at
52 weeks, 4 at 104 today. It is worst precisely on the offices the EWMA rule exists to catch, since
those are the only ones that produce drift-only weeks.

**Constraint any fix must satisfy.** `mean_days > ucl` is the wrong discriminator because it answers
a question about the Shewhart limits and is being asked about a week the Shewhart limits did not
flag. The side of a drift-only week is `mean_days` vs `center`, and the marker must not claim the
week left a range it did not leave. A third visual state is likely needed; two colours cannot carry
three facts.

---

## PT-3 · The chart's accessible summary asserts weeks "went beyond that range" that never left it

**Severity: High · Misleading presentation (and an accessibility defect)**

**Root cause.** `SpcControlChart.tsx` L144 counts `data.filter(point => point.side !== 'normal')`,
i.e. every `out_of_control` week including drift-only ones, then L155–157 renders that count as:

```ts
`${outside} week${outside === 1 ? '' : 's'} went beyond that range.`
```

**Reproduction.** CHO at `weeks=52` has 7 flagged weeks, of which **5** actually breached the limits.
A screen-reader user hears *"7 weeks went beyond that range"*. Two of those weeks sat inside it.

This is the one place a non-sighted reader gets the finding, so the error is not cosmetic: it is the
whole content of the alternative. The rest of the a11y work on this screen is careful and correct —
`role="img"` with a real summary, a `details` table with `scope`d headers, `aria-hidden` on the
decorative bars, `aria-disabled` rather than `disabled` on shut controls — which makes this the one
weak link in an otherwise good chain.

**Blast radius.** Every office whose flagged set includes a drift-only week; assistive tech only.
The summary is otherwise accurate — the range, the centre and the week count all check out.

**Constraint.** The summary must distinguish the two rules, because they are two different findings.
A single count cannot be honest here.

---

## PT-4 · "Outside Normal Range" does not say which direction, so an office getting faster is painted red

**Severity: High · Misleading presentation**

**Root cause.** `ProcessingTimeAnalytics::shape()` L266 collapses the latest week to
`'outside' | 'inside'` and discards the sign. `ProcessingTimePage.tsx` L106–110 prints
`outside → "Outside Normal Range"` and L119–123 tones it `text-s-red` — the error red that
`DESIGN.md` reserves for something having gone wrong.

**Reproduction.** At `weeks=52`, five offices read "Outside Normal Range". Every single one of them
is out on the **fast** side (`mean_days < lcl`, or drift downward): BPLO 0.001 d, BFP 0.004 d,
OBO 0.004 d, CENRO 0.373 d — all against centres of 2.2–3.3 days. **There is not one genuine delay
among the red cards on this screen today.**

The screen already knows this distinction and applies it correctly two panels away: the chart
reserves red for slow and uses ink-black for fast (`SpcControlChart.tsx` L50–60), and Noted Delays
prints "slower than usual" / "faster than usual" per row (`ProcessingTimePage.tsx` L477–479). The
Process Status Indicator — the panel the spec names, and the first thing on the screen — is the only
one that throws the direction away.

Compounding: the card's own detail line (`ProcessingTimePage.tsx` L352–356) prints the latest week's
mean. CHO's card at 52 weeks reads **"Outside Normal Range · week of 27 Jul 2026 · 2.8 days"** —
against a band of 1.4–4.1 days. A reader who checks the chart sees the dot sitting in the middle of
the band and concludes the screen is broken. Only Noted Delays explains it ("caught by the slowdown
watch"), and only if that office happens to be selected.

**Blast radius.** The panel the spec names by title, all seven cards, every window.

**Constraint.** The status indicator must not use error red for a favourable deviation, and the card
must give the reader enough to reconcile its verdict with the chart directly beneath it. The spec's
literal words "Within Normal Range" / "Outside Normal Range" should be kept — this is a tone and
qualifier problem, not a wording one.

---

## PT-5 · The "fitted on the first 24 weeks" claim is false at the two shortest windows, and the screen states it unconditionally

**Severity: Medium-High · Misleading presentation**

**Root cause.** `Spc::controlLimits()` (`api/app/Support/Spc.php` L114) computes
`min(CALIBRATION_WEEKS, count($values))`. The 24-week cap is a *ceiling*, not a guarantee. But the
info hover asserts it as fact:

- `AnalyticsDefinitions.php` L297: *"The centre line and the normal range are fixed from the first 24
  weeks, so every later week is measured against the same yardstick."*
- L299: *"The range comes from the earliest weeks so a recent slowdown cannot widen the range meant
  to catch it."*
- `ProcessingTimePage.tsx` L612–614 and `Spc.php` L17–19 repeat the same reasoning.

**Reproduction.** `calibration_weeks` per office, live:

| window | BPLO | CHO | BFP | CPDO | OBO | CENRO | CMO-MARKET | weeks plotted |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 13 | 14 | 13 | 14 | 11 | 12 | 14 | 10 | **all of them** |
| 26 | 24 | 24 | 24 | **22** | 24 | 24 | **21** | 21–27 |
| 52 | 24 | 24 | 24 | 24 | 24 | 24 | 24 | 37–53 |

**At `weeks=13` the calibration window is the entire series.** Every week helped set the limits it is
then judged against, which is exactly the self-referential fit the design note says the 24-week cap
exists to prevent. At `weeks=26` only 2–3 weeks are genuinely under observation, and two offices are
still fully self-calibrated. The screen prints identical "Outside Normal Range" verdicts with
identical confidence at all four windows.

The PDF makes the same claim harder (`processing-time-report.blade.php` L43–45, printing
`calibration_weeks_cap` = 24 unconditionally) and then **contradicts itself on the same page** at
L69, which prints the real per-department `calibration_weeks`.

**Blast radius.** Both short windows in the dropdown; the info hover; the PDF's standing note.

**Constraint.** Either the copy states the *actual* calibration count (the payload already carries
`calibration_weeks` per department, so nothing needs computing), or the short windows stop offering
verdicts they cannot support. The claim and the arithmetic must agree in every window the selector
offers.

---

## PT-6 · The spec's run rule is not implemented — confirmed

**Severity: Medium · Missing feature**

The spec asks R to check *"whether several consecutive weeks in a row have been drifting in one
direction"*. Confirmed absent:

- `Spc::analyse()` (`api/app/Support/Spc.php` L230–236) builds `$hits` from exactly two predicates,
  `beyond_limits` and `ewma_drift`. There is no third.
- `grep -rn "run_length|runLength|run_rule|consecutive|nelson|western_electric"` over
  `api/app/Support/`, `api/app/Http/` and `r/R/` returns **one** hit, and it is about permit coverage
  gaps in `BusinessGrowthAnalytics.php` L64. Nothing in either engine.
- `web/src/lib/types.ts` L679–680 documents `rule_hit` as "`beyond_limits`, `ewma_drift`, or both
  joined by `+`", which is accurate — the type is honest about the gap.
- Live payload across all four windows: every non-null `rule_hit` is one of `beyond_limits`,
  `ewma_drift`, `beyond_limits+ewma_drift`. No third value occurs.

**What it would take.** The rule is Nelson rule 3 (a run of N points all increasing or all
decreasing) or rule 2 (N consecutive points on one side of the centre line); qcc ships both. The
inputs are already in `Spc::analyse()` — it iterates the ordered weeks and already has
`deviation_days` per week — so the arithmetic is a few lines over the existing loop.

The cost is not the arithmetic, it is everything downstream. Any fix must satisfy:
1. **The gaps break the runs.** See PT-7 — dropped weeks are not represented in `$weeks`, so
   "consecutive" is ambiguous. CMO-MARKET has 16 missing weeks inside its 37 plotted ones. A run
   rule must decide whether a gap breaks a run, and say which it chose.
2. **`rule_hit` is a `+`-joined string parsed by substring on the client**
   (`ProcessingTimePage.tsx` L454–455). Adding a third token means a third branch in the
   three-way label at L463–468, or the label goes stale silently.
3. **It must not double-count.** A drifting run and the EWMA are detecting the same thing by
   different means; firing both on the same week would inflate the flagged list without adding
   information. Noted Delays is already the noisiest panel (PT-14).
4. **The R engine and the PHP port must both grow it in the same commit**, or `AnalyticsParityTest`
   fails and the fallback silently disagrees with the primary.

---

## PT-7 · Dropped weeks are drawn as adjacent, and the sigma estimate spans the gaps

**Severity: Medium · Misleading presentation + wrong data**

**Root cause.** `Spc::weeklyTurnaround()` L86–88 `continue`s past any week under
`MIN_COMPLETIONS_PER_WEEK`, so the week is **absent from the series** rather than present-and-null.
Two consequences:

1. The chart's X axis is categorical (`dataKey="tick"`, `SpcControlChart.tsx` L172), so a 5-week gap
   is drawn as one step. A reader sees a continuous weekly series that is not one.
2. `controlLimits()` L125–127 computes moving ranges as `abs($cal[$i] - $cal[$i-1])` over the array
   **as stored**, so a range that actually spans 6 weeks is treated as a 1-week range. That inflates
   the mean moving range, inflates sigma, and widens the band — in the direction of *under*-detecting.

**Reproduction.** `weeks=52`:

| Office | weeks plotted | gaps | weeks missing inside the series |
| --- | --- | --- | --- |
| BPLO | 52 | 1 | 1 |
| CHO | 52 | 0 | 0 |
| BFP | 53 | 0 | 0 |
| CENRO | 46 | 6 | 7 |
| CPDO | 44 | 7 | 9 |
| OBO | 43 | 7 | 10 |
| **CMO-MARKET** | **37** | **9** | **16** (one of them 5 consecutive weeks: 2025-08-04 → 2025-09-15) |

CMO-MARKET's chart draws 37 dots spanning 53 calendar weeks with no indication that 16 are absent.

**This is the `MIN_COMPLETIONS_PER_WEEK` boundary case from the brief.** An office with exactly 3 one
week and 2 the next produces one plotted point and one silent hole; the hole is invisible on the
chart, invisible in the text-equivalent table, and folded into the neighbouring moving range. The
threshold of 3 is itself **defensible** — the mean of two reviews is a fact about those two reviews —
and the drop is documented honestly in the info hover (`AnalyticsDefinitions.php` L298, L312). It is
the *silence* about the drop that is the defect, not the drop.

`CALIBRATION_WEEKS = 24` is likewise defensible: it is ~46% of the default 52-week window, which
leaves a real monitoring period, and `ProcessingTimeAnalytics.php` L36–42 reasons about the trade
explicitly. It only misfires at short windows (PT-5).

**Constraint.** A gap must be visible where the series is drawn and where it is tabulated, and the
moving-range calculation must know it is not a 1-week range. Interpolating the missing week would be
worse than the current behaviour — it would invent data.

---

## PT-8 · The three info hovers on this screen are exactly the three the definitions test cannot check, and `MetricInfo`'s own comment says the opposite

**Severity: Medium · Real (narrow) coverage hole**

**Root cause.** `api/tests/Feature/AnalyticsDefinitionsTest.php` L123–127:

```php
if ($segments !== [] && $payload[$panel] === []) {
    $skipped[] = "{$dataset}.{$key}";
    continue;
}
```

The test verifies every definition key still resolves against a real payload. When the panel is an
empty list there is nothing to resolve against, so the check is skipped and reported to STDERR at
L137–139. Under the feature-test database, `ProcessingTimeAnalytics::build()` returns
`departments => []`, so all three nested keys are skipped:

```
Unverified against an empty panel: processing_time.departments.status,
processing_time.departments.flagged, processing_time.departments.trend
```

It is `fwrite(STDERR, ...)` — informational only. The test reports `passed, 6 tests, 812 assertions`.
There is no allowlist and no suppression; it has simply never failed anything.

**Why it is not just noise.** Those three keys are precisely the `metric` props of the three section
headings on this screen (`ProcessingTimePage.tsx` L703, L723, L729) — the Process Status Indicator,
Noted Delays and Gradual Slowdown Warnings info buttons. And `MetricInfo.tsx` L119 renders `null` for
an unresolved key, so a rename would remove all three info buttons **silently**, with no error
anywhere.

Worse, `MetricInfo.tsx` L70–73 states the guarantee that does not hold:

> *"A key that no longer exists renders nothing rather than an empty panel, and
> AnalyticsDefinitionsTest fails the build when a definition stops matching the payload — so a silent
> gap here means the definition was never written, not that it drifted."*

For these three keys the build does **not** fail, so a silent gap here means exactly what that
comment promises it cannot mean. The comment is load-bearing — it is the reason the null-render at
L119 is considered safe.

The four other processing-time keys (`departments`, `completed_reviews`) *are* verified, because they
are panel-level and only need the key to exist.

**Blast radius.** All three info hovers on this screen. Note this is a hole in the *definitions
contract only* — the maths itself is well covered: `api/tests/Unit/SpcTest.php` carries 14 tests
including qcc-verified limits, EWMA smoothing, the 24-week cap, the 3-completion drop and a
drift-injection case; `api/tests/Unit/AnalyticsParityTest.php` L80–89 covers charted, thin,
unchartable and flagged departments against an R fixture.

**Constraint.** The fix is to give the feature-test subject a non-empty `departments` panel, not to
weaken the check. It is a fixture gap, not a test-design flaw.

---

## PT-9 · The thin state and the whole-screen empty state are now unreachable, and nothing exercises them in the browser

**Severity: Medium · Untested code path**

**Confirmed:** `thin` is `[]` on **every** window — 13, 26, 52, 104, and the out-of-range values 1,
3, 5 and 200. All seven offices chart at every window. Completed reviews per office at 52 weeks:
BPLO 679, CHO 492, BFP 496, CPDO 263, OBO 248, CENRO 281, CMO-MARKET 230.

The seeder change did its job — `AnalyticsHistorySeeder.php` L629 now seeds all seven office codes,
and the reasoning at L232–285 is sound. But the consequence is that three UI branches now have no
reachable input:

| Branch | File:line | Reachable? |
| --- | --- | --- |
| Thin office card ("Not enough reviews to judge") | `ProcessingTimePage.tsx` L363–388 | no |
| `min_completions_per_week` detail line | `ProcessingTimePage.tsx` L383 | no |
| Whole-screen `EmptyState` + thin-only strip | `ProcessingTimePage.tsx` L745–767 | no |
| PDF "Offices without enough history" table | `processing-time-report.blade.php` L108–124 | no |
| PDF `@empty` branch | `processing-time-report.blade.php` L101–106 | no |

The *server* side is still covered — `AnalyticsParityTest.php` L80–89 asserts both thin reasons
(`BFP` for too few completions, `ZON` for no variation) against a fixture. So the payload shape is
tested. **The rendering is not**, by anything, at any level: `web/e2e/analytics.spec.ts` has no
assertion touching thin offices or the empty state.

Notably the thin-card branch contains detail work that was clearly hard-won — the `CMO-MARKET`
`whitespace-nowrap` fix at L291, the "no week reached 3" phrasing at L383 that exists to prevent a
"3 reviews, needs 3" bug report — and none of it can be seen any more. That work will rot.

**Blast radius.** No user-facing impact today; a latent regression surface. A production LGU in its
first months, or any office that genuinely goes quiet, lands straight in untested code.

**Constraint.** These paths need a fixture-driven render test, or the seeder needs an option that
leaves one office thin. Deleting the branches is not an option — an LGU starting fresh will hit them
on day one.

---

## PT-10 · "Not yet classified" survived in code but can never render

**Severity: Low-Medium · Untested code path**

The brief asked whether the third verdict survived. It did, and the reasoning at
`ProcessingTimePage.tsx` L82–96 is exactly right. But it is unreachable:

- `ProcessingTimeAnalytics::shape()` L266 always sets `status` to `'outside'` or `'inside'`; there is
  no branch that omits it. `shape()` returns `null` instead (L232–234), and a `null` department goes
  to `thin`, not to the strip.
- `web/src/lib/types.ts` L701 declares `status: 'inside' | 'outside'` — **not optional**. So
  `verdictOf`'s fall-through at L102 is unreachable in TypeScript's own model, and TS will narrow it
  away.
- Live: all 7 departments carry a status at all 8 window values tested.

Same for `trend`: `types.ts` L706 declares it required, but `SlowdownWarnings` defends with
`department.trend?.direction` (L510) and `?? 0` (L553). The `reported.length === 0` empty state at
L512–520 is likewise unreachable.

I want to be clear this is **not** an argument for deleting the defensive code — `unreported` is the
right design and the reasoning behind it is the best thing on this screen. The finding is that
**the type and the runtime disagree about whether it can happen**, so nobody can tell which of the
two is the intended contract, and neither is checked. If `status` really is guaranteed, the comment
at L84–87 citing the Pest message as evidence it is not (see PT-8 — the message means something
else entirely) is resting on a misreading.

**Constraint.** Pick one: make `status` optional in `types.ts` and keep the guard, or make it
required and delete the guard. The current pair says both.

---

## PT-11 · The window clamp uses "minimum reviews per week" as "minimum weeks", and a garbage `weeks` value yields a 3-week chart that still gives verdicts

**Severity: Medium · Wrong data**

**Root cause.** `api/app/Http/Controllers/Api/AnalyticsController.php` L524–529:

```php
private function weeks(Request $request): int
{
    $weeks = (int) $request->query('weeks', (string) ProcessingTimeAnalytics::DEFAULT_WINDOW_WEEKS);
    return max(Spc::MIN_COMPLETIONS_PER_WEEK, min(104, $weeks));
}
```

The lower bound of a window **measured in weeks** is `MIN_COMPLETIONS_PER_WEEK`, a constant
**measured in reviews**. They are unrelated quantities that happen to both be small integers. Raising
`MIN_COMPLETIONS_PER_WEEK` to 5 would silently change the minimum window to 5 weeks.

**Reproduction.**

| request | resolved `window_weeks` | source | calibration_weeks per office |
| --- | --- | --- | --- |
| `?weeks=abc` | 3 | local (fallback notice) | 4, 3, 4, 4, 4, 4, 3 |
| `?weeks=1` | 3 | local | 4, 3, 4, 4, 4, 4, 3 |
| `?weeks=5` | 5 | local | 6, 5, 6, 6, 6, 6, 5 |
| `?weeks=200` | 104 | r | 24 × 7 |

At `weeks=3` the control limits are fitted on **3 or 4 weekly points**, i.e. sigma from 2–3 moving
ranges, and the screen still returns per-office `status` verdicts and a full `flagged` list. The
upper clamp at 104 is correct and matches the dropdown; the lower one is not defensible.

Mitigating: the UI only offers 13/26/52/104 (`ProcessingTimePage.tsx` L75–80), which
`config/analytics.php` L89–95 mirrors correctly — that rule is being followed. But `weeks` is a query
parameter on an endpoint the role can reach directly, and `processingTimeReport()` (L64–76) uses the
same clamp, so the PDF is reachable at a 3-week window too.

**Constraint.** The floor must be a window-length constant in its own right, and it must be large
enough that a fitted limit means something — 13 (the shortest window the UI offers) is the obvious
value. A non-numeric `weeks` should be rejected or defaulted to 52, not floored to 3.

---

## PT-12 · The PDF report is written in exactly the vocabulary the screen was rewritten to remove

**Severity: Medium · Terminology drift**

The brief asked whether terminology drifted. **The screen is clean; the PDF it generates is not.**
`ProcessingTimePage.tsx` L61–66 and `SpcControlChart.tsx` L29–35 both record the client's rejection
of control-chart jargon ("Ito, ano 'yung *inside*? … Ano 'yung *flag*?",
`docs/r-integration-revisions.md` §6.2), and the screen honours it completely — UCL, LCL, sigma and
"out of control" appear nowhere on the face. I checked.

The **Generate Report** button on that same screen (`ProcessingTimePage.tsx` L670–678) produces
`processing-time-report.blade.php`, which prints:

| line | printed text | the word the screen uses |
| --- | --- | --- |
| L56 | "Outside control limits" / "Inside control limits" | Outside / Within Normal Range |
| L45 | "A week is **out of control** when…" | went beyond the normal range |
| L62 | "Centre line" | usual |
| L64 | "Normal operating range" | the normal range |
| L46 | "the weighted trend (**EWMA, lambda 0.2**) drifts past its own limit" | (not shown) |
| L82 | "Weighted trend" column, printing the raw EWMA value | — |
| **L95** | **`Outside (beyond_limits+ewma_drift)`** | past the edge, and drifting |

L95 prints the **raw machine token** into an official LGU document under the City of Malabon
letterhead. `rule_hit` is an internal enum joined with `+`; it was never meant to be read by a human.

The PDF also carries PT-4's defect in a sharper form: L56 prints "Outside control limits" for BPLO,
whose latest week is 0.001 days — i.e. far *below* the lower limit. And L92's "Deviation" column
prints `%+.2f` with no unit and no direction word.

**Blast radius.** Every PDF generated from this screen. This is the artefact that leaves the building
and gets attached to a memo.

**Constraint.** The PDF is the same finding for the same reader; the two must not use two
vocabularies. If the statistical terms are wanted somewhere for the academic record, that is an
appendix, not the status line.

---

## PT-13 · `Spc.php`'s class docblock states the site does not run R. It does.

**Severity: Low · Wrong documentation**

`api/app/Support/Spc.php` L9–11:

> *"The R project stays on disk as the team's academic artefact; the site does not call it. No
> Rscript, no plumber, no R runtime."*

All three clauses are false as of today:

- `curl http://127.0.0.1:8787/health` → **200**. Plumber is running.
- Live `meta` on every precomputed window: `{"source": "r", "engine": "R", "engine_version": "4.6.1"}`.
  R computed the numbers currently on the screen.
- `api/config/analytics.php` L31–40 configures the plumber service, its base URL and two timeouts.
- `ProcessingTimeAnalytics.php` L14–23 — the *sibling* file — says the opposite and is correct:
  *"R is the primary engine and this is its fallback"*, `R_ENDPOINT = '/spc/processing-time'`.

This was introduced by the most recent commit, `ce40643 docs(support): the class docblocks these
files never had in git`. It is only a comment, but it is a comment that would lead a maintainer to
delete the fallback's reason for existing — and `AnalyticsParityTest` exists precisely to keep the
two engines honest against each other.

---

## PT-14 · Noted Delays lists +0.1-day "delays" under a heading that says "Beyond the range"

**Severity: Medium · Misleading presentation**

For an `ewma_drift`-only row, `ProcessingTimePage.tsx` L470–475 prints the **week's own**
`deviation_days` under the column heading **"Beyond the range"** (L440–442). Two problems:

1. The week is not beyond the range — that is what drift-only means. The heading is false for
   exactly the rows PT-2 also mishandles.
2. The number printed is not the number that caused the flag. CHO's 2026-07-27 row reads
   **"+0.1 days · slower than usual"**. The week's own deviation is +0.056 days — 80 minutes. What
   actually tripped is the EWMA, at +1.16 days (`trend.deviation_days`, already in the payload and
   already displayed in the panel below). A super admin reading a delay table sees an 80-minute
   entry and reasonably concludes the panel is noise.

The panel's design is otherwise good — the full written-out dates (L459–461, with the stated reason
at L397–400), the three-way rule label, keeping speed-ups in the list but not calling them delays
(L403–405). The row-level number is the weak part.

**Constraint.** A row must print the figure that caused its own flag. Two rule types need two
figures, or the drift rows need to say what the drift value is rather than borrow the week's.

---

## PT-15 · Sub-minute QA transactions are indistinguishable from real reviews

**Severity: Medium · Wrong data**

Covered under PT-1, restated because it has an independent fix. `application_assignments` carries no
flag distinguishing a seeded/demo/QA transaction from a live one, and
`ProcessingTimeAnalytics::dataset()` L73–83 has no floor on turnaround. Across the whole register,
3 completed assignments have a turnaround under 60 seconds and ~26 of the current week's 34 rows are
under 15 minutes. Full distribution over 6,518 completed assignments: min 0.0005 d, p10 1.454 d,
median 2.339 d, p90 4.579 d, max 12.076 d, **no negative durations** (good — that check passes).

A one-minute review is not a review. It is a click. Any demo session immediately before a client
walkthrough will move the numbers on the screen being walked through.

**Constraint.** Either a lower plausibility bound on turnaround (with the excluded count reported, not
hidden), or a way to mark non-production transactions. Silently dropping them would be worse than
including them.

---

## PT-16 · A returned-and-resubmitted review charges the applicant's revision time to the office

**Severity: Medium · Wrong data**

**Root cause.** `assigned_at` is written exactly once, at `WorkflowService.php` L177, when the filing
transitions to `under_review` after payment. It is **never** reset — no write site anywhere in
`api/app/` updates it. On a return, `returnAssignment()` (L231–236) sets status `Returned` and writes
**no timestamp**; on resubmission, `resubmit()` (L247–255) resets the **same row** to `Pending`,
leaving `assigned_at` pointing at the original payment date. `completed_at` is then written on
approval (L221–225).

So for any filing that went round the return loop, the measured turnaround spans the office's review
time **plus every day the applicant spent revising**. The design note at L164–168 is careful to keep
draft-typing time out of the clock; the revision loop puts it back in.

The seeder puts the frequency at `RETURN_LOOP_RATE = 0.10` (`AnalyticsHistorySeeder.php` L214), and
models it consistently with production (L1499 places the return at 55% of the total span, so the loop
is inside the measured duration). So roughly **1 in 10 charted reviews carries applicant time the
office cannot control**, and the office has no way to see which.

**Constraint.** Any fix must not restart the clock on resubmission either — that would let an office
game its numbers by returning a filing. The honest measure is elapsed-minus-waiting-on-applicant, and
the timestamps to compute it are not currently recorded (`returnAssignment` writes none).

---

## PT-17 · The chart discards the weekly sample size and then judges every week equally

**Severity: Medium · Statistical honesty**

`Spc::weeklyTurnaround()` L89–94 emits `n` alongside `mean_days`, and `n` is carried all the way to
the screen (`points[].reviews`, shown in the table at `SpcControlChart.tsx` L282 and the tooltip at
L190). **Nothing in the arithmetic ever uses it.** `controlLimits()` (L111–141) and `ewma()`
(L174–198) see only the means. An individuals chart treats each weekly mean as one observation of
equal weight, so a week averaging 3 reviews is judged by the same band as a week averaging 33
(BPLO's range this window) despite having roughly 3× the standard error.

**But the expected consequence does not show up in this data, and it is worth saying why.** Flagging
rate by weekly sample size at `weeks=52`:

| | weeks | flagged | rate |
| --- | --- | --- | --- |
| n ≤ 4 | 67 | 3 | 4.5% |
| n ≥ 8 | 148 | 9 | 6.1% |

Small weeks are flagged *less* often, not more. The reason is that the limits are estimated from the
same mixture of week sizes, so the extra sampling noise of the small weeks is already baked into the
mean moving range and therefore into sigma. The chart is internally self-consistent.

**The cost lands on the other side of the question the brief asked** — "an office will look stable
when it is not". Absorbing small-week noise into sigma makes every band wider than the office's true
process variation, and the low-volume offices pay most:

| Office (52w) | centre | band | a week must reach… to be called Outside |
| --- | --- | --- | --- |
| BPLO | 2.23 d | 1.66–2.79 | 2.79 d = **1.25×** usual |
| CHO | 2.75 d | 1.40–4.11 | 4.11 d = 1.49× usual |
| OBO | 3.35 d | 1.63–5.07 | 5.07 d = 1.51× usual |
| BFP | 3.25 d | 1.39–5.11 | 5.11 d = 1.57× usual |
| CENRO | 2.83 d | 0.91–4.76 | 4.76 d = 1.68× usual |
| CPDO | 2.66 d | 0.56–4.75 | 4.75 d = **1.79×** usual |
| CMO-MARKET | 2.07 d | 0.40–3.75 | 3.75 d = **1.81×** usual |

At 13 weeks it is worse — CMO-MARKET's half-width is **97.9% of its own centre line**, meaning the
office would have to nearly double its turnaround for a single week before the screen said anything.

**So: is "Outside Normal Range" a defensible claim on this data?** Where the Shewhart rule fires on a
complete week, **yes** — the limits are conservative, `SpcTest.php` verifies them against qcc, and a
breach of a band that wide is a real signal. The claim is *under*-sensitive, not over-sensitive.
Where it fires on the in-progress week (PT-1) it is not defensible at all. And the EWMA is doing the
real work: it caught the CHO slowdown two weeks before the Shewhart rule did (2026-06-22 vs the
drift already rising from 2026-06-15), which is exactly what it is for.

One thing the client should know: **the CHO slowdown is planted.** `AnalyticsHistorySeeder.php`
L184–190 injects an 8-week ramp of ×1.35 → ×1.95 into CHO specifically. It is the only genuine
"Gradual Slowdown Warning" on the screen, and it is fiction. That the detector finds it is a good
sign about the detector; it is not a finding about CHO.

**Constraint.** If `n` is to be used, it changes the chart type (X-bar with variable subgroup size,
limits that breathe per week) and every band on screen moves. That is a real decision, not a bug fix.
The minimum honest step is that the screen should not present a 1.81×-threshold office and a
1.25×-threshold office as equally monitored.

---

# Part 2 — Judgement (things I would change; disagree freely)

These are opinions, not defects. Each says what I would do and why, so the reasoning can be rejected
on its own terms.

## PT-J1 · Is this screen overwhelming? No — it is the calmest analytics screen in the app. Do not cut it further.

The client has said the analytics screens feel overwhelming. **I do not think this one does**, and I
would push back on cutting it. It is four sections, one screen-height at 1440, no scrolling to reach
the finding, one sentence of prose per panel. The revision described at `ProcessingTimePage.tsx`
L41–73 worked.

The one place I would spend space rather than save it is the status strip (see PT-J2). Seven cards
is not too many — it is the number of offices, and the reasoning at L183–207 for why all seven must
appear is correct and should not be revisited.

## PT-J2 · Put the direction on the status card, and the number that justifies it

This is PT-4's fix, stated as a design preference. Today a card reads:

> **CHO** City Health Office
> **Outside Normal Range**  *(red)*
> week of 27 Jul 2026 · 2.8 days

The reader's next question is always "outside which way, and by how much" — and the card sends them
to two other panels to find out. I would make it:

> **CHO** City Health Office
> **Outside Normal Range** — slower  *(red)*
> 2.8 days · usually 1.4–4.1 · drifting up

An office on the fast side gets the same words in ink, not red. This costs one line per card and
removes the reason to look anywhere else for the verdict, which is what a status indicator is for.

## PT-J3 · Gradual Slowdown Warnings should be second, not last

Order today: Status → Chart + Noted Delays → Gradual Slowdown. The last panel is the only one on the
screen that is **forward-looking**, and it is the one an oversight reader most needs first: "which
office is heading somewhere bad" beats "which office had a bad week last March". It is also the only
panel that shows all seven offices at once, so it answers the department-comparison question the
chart cannot.

Noted Delays is inherently retrospective and, on a 104-week window, is the least urgent thing on the
page. I would swap them: Status → Gradual Slowdown → Chart + Noted Delays.

Reason to disagree: the spec lists them in the current order, and `ProcessingTimePage.tsx` L34–39
treats that ordering as a contract. If the spec's order is being read as normative, keep it.

## PT-J4 · The chart shows one office; an oversight reader wants all seven

Selecting an office to see its chart is a fine interaction, but it means the reader must click seven
times to answer "how does the city look". A small-multiple — seven sparkline-height charts, each with
its own band, in the same grid the status strip already uses — would answer it in one look, with the
full-size chart appearing on selection.

Reason to disagree: seven charts is more ink, and the client has asked for less. My counter is that
the status strip is *already* seven cards and reads fine; replacing the card's detail line with a
40px sparkline adds information without adding a row.

## PT-J5 · What is behind the info icon is right; what is on the face is one sentence short

The `distil()` approach (`ProcessingTimePage.tsx` L126–165) — server-authored text, shortened to
leading sentences at the point of display, never rewritten — is the best pattern in this codebase and
I would not touch it. The full text stays one API call away for anyone auditing.

The one thing I would promote from the hover to the face is **how many weeks the range was fitted on
and how many are being watched**. It is the single fact that tells a reader how much to trust the
verdict, it changes per office and per window (PT-5), and it currently exists nowhere on screen — only
in the hover, as a claim that is sometimes false. One line under the chart: *"Range fitted on the
first 24 of 52 weeks; 28 weeks watched against it."*

## PT-J6 · Default to 26 weeks, not 52

52 is the right *statistical* default and the reasoning at `ProcessingTimeAnalytics.php` L36–42 is
sound. But an oversight reader opening this screen is asking "how are we doing", not "how was last
August", and at 52 weeks the interesting recent movement is 8 dots at the right edge of 52.

I would keep 52 as the calibration source and default the *view* to the last 26, which is what the
underlying design already implies — 24 calibration weeks then watch. Do not default to 13: PT-5 shows
13 is self-calibrating and its verdicts are not trustworthy.

## PT-J7 · The `details` table should be open by default on narrow screens

`SpcControlChart.tsx` L247–299 collapses the weekly figures behind a `details`. That is the right call
for "remove large spaces" on a desktop. On a phone the chart is 224px of unreadable dots and the
table is the *better* representation, not the fallback. I would open it below `sm`.

## PT-J8 · Say on the face that the last week is in progress

Related to PT-1 but worth separating, because even after PT-1 is fixed the reader needs it. Whatever
the fix, the screen should name the period it is actually reporting on — *"through week of 27 Jul; the
current week is still open"* — next to the `ComputedAt` line. Right now the screen shows a `computed_at`
timestamp but never says what the data's own horizon is.

---

# Part 3 — What I could not determine

1. **Whether PT-1 has already shown a wrong verdict to the client.** The snapshot is precomputed
   (`meta.computed_at = 2026-08-06T13:36:04Z`) and I have no history of prior snapshots. I can only
   confirm the screen is wrong *now* and that the mechanism makes it wrong most Mondays.

2. **Whether R and the PHP port agree on PT-2/PT-3.** Both defects are client-side, so they apply
   regardless of engine. But I did not verify that R's `/spc/processing-time` emits `rule_hit` with
   the identical `+`-joined grammar in every case — I only observed the three values that occurred
   live. If R can emit a token PHP cannot, the client's substring parsing at
   `ProcessingTimePage.tsx` L454–455 would degrade silently. `AnalyticsParityTest` presumably covers
   this; I did not read it closely enough to confirm it asserts on `rule_hit` strings specifically.

3. **The real-world return-loop rate (PT-16).** I have the seeder's assumption (10%) and 607 audit
   rows mentioning a return, but I could not join those to specific completed assignments to measure
   how much applicant time is actually inside the charted turnarounds. The query needs status-history
   data I did not find a clean table for.

4. **Whether today's per-office scheduling change moved any department's numbers.** It did **not**
   move the clock — I verified with `git log -S "'completed_at' => now()"` that the per-office
   `completed_at` write at `WorkflowService.php` L221–225 dates to the original backend commit
   `55c1a70` and was untouched by today's `5da4daa`. An office's clock has always stopped when *that*
   office approved, never when the last one did. (A subagent report I commissioned claimed otherwise
   in one section; it contradicted itself and the git history says the endpoints are unchanged.
   Recorded here in case that claim resurfaces.) What I could **not** determine is whether the new
   `isFullyCleared()` guard changes the *mix* of filings that reach approval, which would move the
   numbers without moving the clock. That needs a before/after comparison I have no baseline for.

5. **Whether any historical rejected filing has a stray `completed_at`.** `rejectApplication()`
   (L239–244) touches only the `applications` table, so assignments on rejected filings should have
   `completed_at IS NULL` and be excluded. I did not run the confirming join.

6. **Print/PDF rendering fidelity.** I read `processing-time-report.blade.php` but did not generate a
   PDF, so PT-12's findings are from the template. Page-break behaviour with seven offices × up to
   104 rows each is unverified and could be its own problem.

7. **Real-screen visual verification.** I audited the payload and the components against the running
   API but did not drive the browser UI itself, so layout findings (PT-J1, PT-J7) are reasoned from
   the markup rather than observed at each breakpoint.
