# Analytics — what can move, and what cannot

**Audit date:** 6 August 2026 · branch `main` · read-only, nothing was changed.

**The question this answers:** across all six R-integration features, which numbers can we
change live in front of a panel, which need an operator with SSH, and which need a code edit
and a redeploy?

---

## Read this first

The short answer, for a panelist who asks "change that to 10":

| If they ask about… | The honest answer |
|---|---|
| A **time window** — last 3/6/12/24/36 months, 13/26/52/104 weeks, next 30/60/90/180/365 days | **Yes, right now.** There is a dropdown on the screen. |
| **Rows in the risk table** (25/50/100), **risk level**, **recommended action**, **barangay** | **Yes, right now.** Filter menu on Renewal Risk. |
| The **3 / 7 / 20 working-day limits** | **That is the statute.** RA 11032. We must not move it. |
| **Which filings are simple / complex / highly technical** | **That is ours, and it is unapproved.** Open question A10 with BPLO. Code change today. |
| **500 metres**, **six industries**, **five barangays**, **the risk weights**, **the 24-week SPC window**, **the Low/Medium/High bands** | **Code change and a redeploy.** None of these has a control or a config key. |

There is exactly **one** analytics config file — `api/config/analytics.php` — and it holds
five settings, none of which is a statistic. Every statistical constant in the product is a
PHP class constant. That is the finding.

**Nothing in this list is broken.** The constants are all deliberate and all documented in
the code. The question is only whether they can be *moved*, and for most of them the answer
is no without a deploy.

---

## Summary table — the client's answer

Bucket key: **A** = user can change it on screen · **B** = operator can change it, config/env,
no code · **C** = code edit + redeploy · **D** = fixed by law or an external standard, should
not move.

### A — Adjustable now, by a user

| What | Values offered | Where the control is | Feature |
|---|---|---|---|
| Dashboard trailing window | 3, 6, 12, 24, 36 months | `AnalyticsPage.tsx:164` PERIOD_OPTIONS — Filter menu, top right | 1 |
| Growth period | 3, 6, 12, 24, 36 months | `BusinessGrowthPage.tsx:76` PERIOD_OPTIONS — Filter menu | 4 |
| SPC chart window | 13, 26, 52, 104 weeks | `ProcessingTimePage.tsx:75` WINDOW_OPTIONS — Filter menu | 6 |
| Renewal horizon | 30, 60, 90 days; 6, 12 months | `RenewalRiskPage.tsx:80` HORIZON_OPTIONS — Filter menu | 2 |
| Rows in the watchlist | 25, 50, 100 | `RenewalRiskPage.tsx:105` ROW_OPTIONS — Filter menu | 2 |
| Risk level filter | All / High / Moderate / Low | `RenewalRiskPage.tsx:124` LEVEL_OPTIONS | 2 |
| Recommended action filter | All / Immediate follow-up / Send reminder / Monitor | `RenewalRiskPage.tsx:141` ACTION_OPTIONS | 2 |
| Barangay filter | Every barangay in scope, built from the register | `RenewalRiskPage.tsx:839`, options from `data.barangays` | 2 |
| Department shown on the SPC chart | The seven offices, as a button strip | `ProcessingTimePage.tsx:343` StatusStrip | 6 |
| Watchlist page | Previous / Next | `RenewalRiskPage.tsx:1039` | 2 |

### B — Adjustable now, but only by an operator

| Key | Current value | File | Effect | Restart? |
|---|---|---|---|---|
| `R_ANALYTICS_ENABLED` | `true` | `api/config/analytics.php:32` | Turn the R engine off; screens compute in PHP and say so | `config:clear`, no restart |
| `R_ANALYTICS_URL` | `http://127.0.0.1:8787` | `:33` | Point at a different R host | `config:clear` |
| `R_ANALYTICS_TIMEOUT` | `60` s | `:36` | How long a refresh waits on R | `config:clear` |
| `R_ANALYTICS_CONNECT_TIMEOUT` | `2` s | `:39` | How fast we notice R is down | `config:clear` |
| `ANALYTICS_STALE_AFTER_HOURS` | `25` | `:143` | When the UI calls a snapshot old | `config:clear` |
| `variants` — which windows get precomputed by R | 5 dashboard, 4 SPC, 5 growth, 5 risk | `:74–128` | Adding a window here is what makes it R-backed instead of PHP-fallback | `config:clear` + `analytics:refresh` |
| `MAIL_MAILER` | `log` (simulation) | `api/config/mail.php:17` | Turn real email on | `config:clear` |

**That is the whole of bucket B.** No statistic is in it.

### C — Hard-coded. Needs a code edit and a redeploy.

Ranked by how likely a panelist is to poke at it. Full detail per row is in the
per-feature sections below.

| # | Constant | Value | File:line | Feature | What it would take |
|---|---|---|---|---|---|
| 1 | `Ra11032::HIGH_TECH_CATEGORIES` | manufacturer, essential_manufacturer, contractor, amusement_place | `Ra11032.php:49` | 1, 6 | Config move + **the seeder holds a second copy** (`AnalyticsHistorySeeder.php:363`) |
| 2 | `Ra11032::HIGH_TECH_CAPITAL_FLOOR` | ₱1,000,000 | `Ra11032.php:54` | 1, 6 | Same — second copy at `AnalyticsHistorySeeder.php:367` |
| 3 | `LocationInsights::RADIUS_M` | 500 m | `LocationInsights.php:50` | 5 | **One-line config move.** Clean — the map ring and all copy already read it off the API |
| 4 | `BusinessGrowthAnalytics::TOP_N` | 6 | `BusinessGrowthAnalytics.php:50` | 4 | Config move, **plus** the chart palette only has 6 colours (`GrowthChartFrame.tsx:48`) |
| 5 | `DashboardAnalytics::TOP_N` | 5 | `DashboardAnalytics.php:81` | 1 | Config move, **plus** the headings say "Top Five" in words (`AnalyticsPage.tsx:1469, 1485`) |
| 6 | `RenewalRiskScoring::WEIGHTS` | 30 / 25 / 20 / 15 / 10 | `RenewalRiskScoring.php:11–17` | 2 | Config move is easy; a UI would be a real feature |
| 7 | `RenewalRiskScoring::HIGH_THRESHOLD` / `MODERATE_THRESHOLD` | 50 / 25 | `RenewalRiskScoring.php:7, 9` | 2 | Config move |
| 8 | `LocationInsights::BAND_MEDIUM_FROM` / `BAND_HIGH_FROM` | 6 / 11 | `LocationInsights.php:53, 55` | 5 | Config move. Server-side and correctly single-sourced — the panel reads them off the response |
| 9 | `Spc::CALIBRATION_WEEKS` | 24 | `Spc.php:37` | 6 | Config move; already travels to R in the payload |
| 10 | `Spc::MIN_COMPLETIONS_PER_WEEK` | 3 | `Spc.php:34` | 6 | Config move; already travels to R |
| 11 | Reminder ladder `THRESHOLDS` | `[30, 15, 7, 1]` days | `ScanPermits.php:61` | 3 | Config move; the badge colours on the risk table repeat 7/15/30 (`RenewalRiskPage.tsx:190–200`) |
| 12 | `RenewalRiskAnalytics::DEFAULT_HORIZON_DAYS` | 365 | `RenewalRiskAnalytics.php:92` | 2 | Config move; but there IS a dropdown (bucket A), so this is only the landing default |
| 13 | `RenewalRiskAnalytics::DEFAULT_LIMIT` | 25 | `RenewalRiskAnalytics.php:98` | 2 | Same — there is a 25/50/100 selector |
| 14 | `RenewalRiskAnalytics::LAPSED_GRACE_DAYS` | 60 | `RenewalRiskAnalytics.php:95` | 2 | Config move |
| 15 | `RenewalRiskAnalytics::FINDINGS_LOOKBACK_MONTHS` | 12 | `RenewalRiskAnalytics.php:127` | 2 | Config move |
| 16 | `RenewalRiskAnalytics::DRIVERS_PER_ROW` | 3 | `RenewalRiskAnalytics.php:124` | 2 | Config move |
| 17 | `RenewalRiskScoring::EXPIRY_BANDS` | 1→30, 7→25, 15→18, 30→10, 60→4, 90→2 | `RenewalRiskScoring.php:19–26` | 2 | Config move |
| 18 | `RenewalRiskScoring::PROGRESS_POINTS`, `FEE_POINTS`, `FINDINGS_BANDS`, `PUNCTUALITY_UNKNOWN` | see §2 | `RenewalRiskScoring.php:28–50` | 2 | Config move |
| 19 | `RenewalRiskScoring::RENEWAL_DUE_WITHIN_DAYS` | 30 | `RenewalRiskScoring.php:37` | 2 | Config move |
| 20 | `BusinessGrowthAnalytics::RENEWAL_GRACE_DAYS` | 30 | `BusinessGrowthAnalytics.php:61` | 4 | Config move; already travels to R |
| 21 | `BusinessGrowthAnalytics::COVERAGE_GAP_TOLERANCE_DAYS` | 1 | `BusinessGrowthAnalytics.php:70` | 4 | Config move |
| 22 | `BusinessGrowthAnalytics::CYCLE_PERMIT_TYPE` | `'BUSINESS'` | `BusinessGrowthAnalytics.php:80` | 4 | Config move |
| 23 | Industry ranking **sorts by size, not growth** | `usort` on `count` first | `BusinessGrowthAnalytics.php:691` **and** `service.R:1232` | 4 | **Two-engine change** + fixture regeneration. See §4 |
| 24 | `DashboardAnalytics::EXPIRY_WINDOWS` | `[30, 60, 90]` days | `DashboardAnalytics.php:104` | 1 | Config move; travels to R in the payload |
| 25 | `DashboardAnalytics::MAP_POINT_LIMIT` | 1000 | `DashboardAnalytics.php:90` | 1 | Config move |
| 26 | `DashboardAnalytics::INSPECTION_TYPE_BY_DEPARTMENT` | CHO→Sanitary, BFP→Fire Safety, CPDO→Zoning | `DashboardAnalytics.php:111` | 1 | Would properly be a schema fix — populate `inspections.inspection_type` |
| 27 | `DashboardAnalytics::ORGANIZATION_FORMS` | 4 forms | `DashboardAnalytics.php:122` | 1 | Code edit; these are the four forms the spec names |
| 28 | `PsicTaxonomy::DIVISIONS` | ~80 division→name entries | `PsicTaxonomy.php:61` | 5 | Code edit. The *names* are ours; the division numbers are PSIC's (bucket D) |
| 29 | `Spc` EWMA λ = 0.2 and 3-sigma | 0.2 / 3.0 | `Spc.php:43, 46` **and** `service.R:184` | 6 | **Two-engine change.** PHP does not send these to R. See §7 |
| 30 | SPC trend "rising/easing" cut-off | ±0.5 | `Spc.php:282, 284` **and** `service.R:261` | 6 | **Two-engine change.** Not sent to R |
| 31 | `ScanPermits::LAPSED_NOTICE_DAYS` | 30 | `ScanPermits.php:74` | 3 | Config move |
| 32 | `ScanPermits::RENEWAL_NUDGE_AFTER_DAYS` | 7 | `ScanPermits.php:84` | 3 | Config move |
| 33 | Nightly refresh time | `03:00`; permit scan `daily()` = 00:00 | `api/routes/console.php:11, 13` | 1–6 | Config move |
| 34 | Notification copy | inline strings | `NotificationService.php:201–232, 299` | 3 | Externalise to lang files |
| 35 | SMS channel | always logs to `storage/logs/sms.log` | `Sms/LogSmsChannel.php:15–18` | 3 | No driver abstraction — real SMS is a build, not a setting |
| 36 | Watchlist expiry badge steps | 7 / 15 / 30 days | `RenewalRiskPage.tsx:190, 197, 200` | 2 | Frontend-only copy of the reminder ladder |
| 37 | Growth chart palette size | 6 colours, then it wraps | `GrowthChartFrame.tsx:48` | 4 | Add colours that clear 4.5:1 contrast |
| 38 | Rounding precision | 1 dp for rates, 3 dp for SPC means, 6 dp for map coordinates | throughout | 1, 4, 6 | Code edit; both engines must match to 1e-6 |
| 39 | Map centre fallback | `[14.669, 120.957]` Malabon | `AnalyticsPage.tsx:1087` | 1 | Code edit |
| 40 | Notifications page ceiling | 200 rows max | `Concerns/PaginatesLists.php:34` | 3 | Config move |

### D — Fixed by law or an external standard. Should not move.

| What | Value | File:line | Authority |
|---|---|---|---|
| RA 11032 statutory limits | Simple **3**, Complex **7**, Highly technical **20** working days | `Ra11032.php:37–41` | Republic Act 11032, Ease of Doing Business Act |
| Working days per week | 5 (weekends excluded) | `Des.php:84`; `Ra11032::deadlineFor` uses `addWeekdays` | RA 11032 counts in working days |
| PSIC code structure — division = first 2 digits, group = first 3 | `PsicTaxonomy.php:159–180` | PSA's Philippine Standard Industrial Classification |
| Hartley's d2 for a moving range of 2 | **1.128** | `Spc.php:40` | The tabulated value R's `qcc` package uses. Changing it would break parity with qcc |
| Earth radius (haversine) | 6,371,000 m | `LocationInsights.php:63` | Mean Earth radius |
| Individuals chart (X-bar one), 3-sigma limits, calibration on the leading window | `Spc.php` throughout | Shewhart / standard SPC practice as implemented by `qcc` 2.7 |

**If a panelist asks to change 3/7/20, the answer is "that is the statute", not "we hard-coded it".**

---

## The one that matters most: who decides simple / complex / highly technical

This deserves its own heading because it is the only place where a bucket-D number sits on
top of a bucket-C rule, and the two get confused.

- The **3 / 7 / 20 working-day limits** are RA 11032. Bucket D. Not ours to move.
- **Which bucket a given filing falls into** is entirely our invention, and it is an open
  question with the LGU.

The rule, at `api/app/Support/Ra11032.php:65–91`:

- Renewals and amendments → **simple** (3 days)
- New registrations → **complex** (7 days)
- New registrations → **highly technical** (20 days) *only if* a declared line is
  `manufacturer`, `essential_manufacturer`, `contractor` or `amusement_place`
  **and** declared capital is **≥ ₱1,000,000**

`docs/questions-for-malabon.md` §A10 states this plainly: *"What we assumed meanwhile, and
this is our invention entirely."* It is also tracked as A10 in `docs/HANDOFF.md:1142`.
Malabon's Citizen's Charter should say which bucket a business permit falls into; we have
not been given it.

**Why it matters for the defence:** the entire processing-time compliance report is measured
against this mapping. Classify a filing wrongly and the office is reported as breaching a
deadline it never had.

**What it would take to move it:** the categories and the floor are two class constants, so
a config move is a small job — *except* that `AnalyticsHistorySeeder.php:363` and `:367`
hold a **second, private copy of both**. The code comment at `Ra11032.php:46` names the
hazard: *"If these two ever diverge, the tier panel silently mixes two classifications."*
They agree today. Changing one without the other would reclassify the demo history but not
new filings, or the reverse.

---

## Per feature

### Feature 1 — Analytics Dashboard

`api/app/Support/DashboardAnalytics.php` · screen `web/src/pages/admin/AnalyticsPage.tsx`

**Adjustable now (A):** the trailing window, 3/6/12/24/36 months. All five are precomputed
in `config/analytics.php:78–86`, so all five are R-backed.

**Hard-coded (C):**

- **`TOP_N = 5`** (`:81`) — "Top Five Barangays by Active Businesses" and "Top Five Business
  Categories". This one is a *two-file* change, not one: the value ships to R in the payload
  (`:158`, and `service.R:558` reads `payload$top_n`), so R follows PHP correctly — but the
  headings and the footnotes spell the number **as an English word**:
  `AnalyticsPage.tsx:1469` "Top Five Barangays", `:1474` "The five barangays with the most…",
  `:1478` "Five of {n} barangays", and the same three at `:1485–1494`. Changing `TOP_N` to 10
  would show ten rows under a heading that still said "Top Five". The same wording is also in
  the metric glossary at `AnalyticsDefinitions.php:205` and `:228`.
- `MAP_POINT_LIMIT = 1000` (`:90`) — points plotted on the register map before truncation.
- `EXPIRY_WINDOWS = [30, 60, 90]` (`:104`) — cumulative, and travels to R in the payload.
- `INSPECTION_TYPE_BY_DEPARTMENT` (`:111`) — CHO→Sanitary, BFP→Fire Safety, CPDO→Zoning.
  This is a workaround, and the code says so: `inspections.inspection_type` is null on every
  row, so the department is used as a proxy. The proper fix is a data fix, not a config key.
- `ORGANIZATION_FORMS` (`:122`) — the four forms in the spec's order.
- Map centre fallback `[14.669, 120.957]` (`AnalyticsPage.tsx:1087`) and map height 420 px
  (`:1222`).
- Rounding to 1 decimal place on every rate; 6 decimals on map coordinates.

**Statutory (D):** `TIERS = Ra11032::TIERS` (`:101`) — 3/7/20. The dashboard does not keep
its own copy, which is right.

---

### Feature 2 — Renewal Risk Prediction

`api/app/Support/RenewalRiskAnalytics.php`, `RenewalRiskScoring.php` · screen
`web/src/pages/admin/RenewalRiskPage.tsx`

This is the feature with the **most** on-screen control and the **most** hard-coded rule.

**Adjustable now (A):** horizon (30/60/90/180/365 days), rows (25/50/100), risk level,
recommended action, barangay, and page. Five real filters. The API clamps the horizon to
7–365 (`AnalyticsController.php:512`) and rows to 1–200 (`:520`), so a hand-typed query
string is bounded but a little wider than the menu.

Note for the defence: only the **five unfiltered horizons at 25 rows** are precomputed by R
(`config/analytics.php:114–120`). Any filter or any other page size falls to the PHP engine
and the screen says so. That is by design and documented at length in the config file — the
key space is horizons × page sizes × barangays × levels × actions × offsets, which is
thousands of R round trips.

**Hard-coded (C) — the scoring rule.** All of it lives in `RenewalRiskScoring.php`:

| Constant | Value | Line |
|---|---|---|
| `WEIGHTS` | expiry 30, progress 25, punctuality 20, findings 15, fees 10 — sums to 100 | 11–17 |
| `HIGH_THRESHOLD` / `MODERATE_THRESHOLD` | 50 / 25 | 7, 9 |
| `EXPIRY_BANDS` | ≤1 d → 30, ≤7 → 25, ≤15 → 18, ≤30 → 10, ≤60 → 4, ≤90 → 2, else 0 | 19–26 |
| `PROGRESS_POINTS` | none 25, rejected 25, draft 20, returned 18, in_progress 5, approved 0 | 28–35 |
| `RENEWAL_DUE_WITHIN_DAYS` | 30 | 37 |
| `PUNCTUALITY_UNKNOWN` | 10 (half weight for a first renewal cycle) | 39 |
| `FINDINGS_BANDS` | 0 findings → 0 pts, ≤2 → 8, else 15 | 41–44 |
| `FEE_POINTS` | settled 0, pending 6, unpaid 10 | 46–50 |

**Good news for this one:** the whole rule set travels to R in the payload
(`RenewalRiskAnalytics.php:176`, `service.R:308`), and `service.R` explicitly has *"no risk
numbers of its own to drift from PHP's"* (`service.R:302`). So moving these to config moves
both engines. It is a genuinely clean config move.

**Also hard-coded:** `DEFAULT_HORIZON_DAYS = 365` (`:92`), `LAPSED_GRACE_DAYS = 60` (`:95`),
`DEFAULT_LIMIT = 25` (`:98`), `DRIVERS_PER_ROW = 3` (`:124`),
`FINDINGS_LOOKBACK_MONTHS = 12` (`:127`).

**Frontend duplicate:** the expiry badge colour steps — red ≤7 days, orange ≤15, yellow ≤30
— are re-declared in the browser at `RenewalRiskPage.tsx:190, 197, 200`. They mirror the
notification ladder (feature 3) rather than the scoring bands, and nothing keeps them in
sync with either.

---

### Feature 3 — Notifications

`api/app/Console/Commands/ScanPermits.php`, `api/app/Services/NotificationService.php`

**Nothing here is adjustable by a user or an operator.** There is no notification config
file and no notification settings screen.

**Hard-coded (C):**

- **The reminder ladder** — `THRESHOLDS = [30, 15, 7, 1]` days before expiry
  (`ScanPermits.php:61`). This is the beating heart of the feature. It matches the R
  integration spec §2/§3.
- `LAPSED_NOTICE_DAYS = 30` (`:74`) — past this, an expired permit is corrected silently and
  no message is sent. This is what stops thousands of 2024 lapses backfilling into real
  inboxes.
- `RENEWAL_NUDGE_AFTER_DAYS = 7` (`:84`) — the gap between "your permit lapsed" and "renew
  now", so the two read as an escalation rather than a duplicate.
- **Schedule times** (`api/routes/console.php`): the permit scan is `->daily()` (midnight),
  the analytics refresh is `->dailyAt('03:00')`. Both are literals in the schedule file.
- **De-duplication grain** — a unique index on `(permit_id, notice_kind)` in
  `2026_07_24_000062_create_permit_expiry_notices_table.php:20`. One notice per permit per
  kind, forever. Officer-initiated follow-ups are per permit per **day**, because the kind
  carries the date (`RenewalRiskAnalytics.php:851`). Changing either grain is a schema
  question, not a config one.
- **Message copy** — every title and body is an inline string in `NotificationService.php`
  (`:201–220`, `:232`, `:299`). There are no lang files.
- **Channels** — email and SMS both fan out unconditionally if the user has an address or a
  mobile number. `MAIL_MAILER` defaults to `log` (simulation) and is env-backed, so email is
  bucket B; SMS writes to `storage/logs/sms.log` with **no driver abstraction at all**
  (`Sms/LogSmsChannel.php:15–18`), so real SMS is a build, not a setting.
- Notifications list: 50 per page by default, **200 maximum** (`PaginatesLists.php:31, 34`).

---

### Feature 4 — Business Growth Analysis

`api/app/Support/BusinessGrowthAnalytics.php` · screen `web/src/pages/admin/BusinessGrowthPage.tsx`

**Adjustable now (A):** period, 3/6/12/24/36 months.

**Hard-coded (C):**

- **`TOP_N = 6`** (`:50`) — how many barangays and how many industries the screen lists.
  Confirmed. It ships to R (`:128`) and `service.R:1049` reads it, so both engines follow
  PHP. **But** the industry-trend chart's colour palette is exactly six entries
  (`GrowthChartFrame.tsx:48–55`) and wraps with `i % GROWTH_SERIES.length`
  (`GrowthCharts.tsx:350`), so a seventh industry would silently repeat the first colour and
  its dash pattern. Raising `TOP_N` needs new colours that clear 4.5:1 contrast on white.

- **The ranking is by SIZE, not growth.** Confirmed, and worth being ready for.
  `computeIndustries` sorts on `count` first, then `delta`
  (`BusinessGrowthAnalytics.php:691`); `computeBarangays` sorts on `delta` first
  (`:641`, with the comment *"as the spec asks — not by how many they have"*). So the two
  ranked panels on the same screen answer two different questions, and the industry one sits
  on a panel about growth. R does the identical thing (`service.R:1232`,
  `order(-count, -delta, codes)`), so the engines agree — which means **changing it is a
  two-engine change plus a fixture regeneration**, not a one-line sort swap.

- `RENEWAL_GRACE_DAYS = 30` (`:61`) — days after expiry before a missing renewal counts as a
  lapse. Ships to R at `:139`; the comment there records that R used to fall back to its own
  literal 30 and the two *"agreed only by coincidence"*.
- `COVERAGE_GAP_TOLERANCE_DAYS = 1` (`:70`) — a permit ending 31 Dec replaced by one starting
  1 Jan is contiguous cover.
- `CYCLE_PERMIT_TYPE = 'BUSINESS'` (`:80`) — only the mayor's permit chain defines a renewal
  cycle.
- `SURVIVAL_METHODOLOGY` (`:97`) — the sentence that must travel with the survival figure.
  Server-side deliberately, so an export cannot ship the number without it.

---

### Feature 5 — Business Location Insights

`api/app/Support/LocationInsights.php` · panel `web/src/pages/applicant/LocationInsightsPanel.tsx`

**Nothing here is adjustable by a user.** The API takes latitude, longitude, PSIC code and
the applicant's own business id — and nothing else (`LocationInsightsController.php:34–41`).
There is no radius parameter.

**Hard-coded (C):**

- **`RADIUS_M = 500`** (`:50`). The spec asks for 500 m (`docs/r-integration-spec.md:266`,
  `:277`). **This is the cleanest one in the whole audit**: the number is single-sourced.
  The panel interpolates `insights.radius_m` off the API response
  (`LocationInsightsPanel.tsx:357`), and the map ring takes it as a prop
  (`MapPicker.tsx:113–124`, whose comment says *"a 500 baked into the client would keep
  drawing 500 on the day that constant changes"*). Moving it to config is genuinely one line
  and no frontend edit.
- **`BAND_MEDIUM_FROM = 6` / `BAND_HIGH_FROM = 11`** (`:53`, `:55`) — the Low 0–5 /
  Medium 6–10 / High 11+ scale. **To answer the specific question: yes, these are truly
  server-side, and nothing can move them without a deploy.** They are returned in the payload
  as `concentration.thresholds` (`:217–220`) and the panel derives the whole "Low 0–5 ·
  Medium 6–10 · High 11+" label by arithmetic on them
  (`LocationInsightsPanel.tsx:461–467`) — it does not hardcode 6 or 11. So this is also a
  clean one-line config move.
- `PsicTaxonomy::DIVISIONS` (`PsicTaxonomy.php:61–146`) — roughly eighty division→name
  entries. The *division numbers* are PSIC's and are bucket D. The *plain-language names* are
  ours, and the long docblock at `:26–50` explains why they were rewritten (the old
  `'Manufacturing'` bucket read as a superset of the buckets beside it and a client filed a
  bug against a correct count). Changing a name is a code edit.

**A note on engine provenance for this feature:** location insights is *always* computed in
PHP, never by R, and says so (`meta.engine = "PHP"`). That is a deliberate architectural
decision — a snapshot cannot be keyed by a point the applicant dropped seconds ago — and it
is documented at `LocationInsights.php:12–33`. If a panelist asks "is this the R feature?",
the honest answer is that the statistics are a count, a band, a mode and a mean over a
haversine distance, and the port is small enough to be obviously equivalent.

---

### Feature 6 — Permit Processing Time Monitoring

`api/app/Support/ProcessingTimeAnalytics.php`, `Spc.php` · screen
`web/src/pages/admin/ProcessingTimePage.tsx`

**Adjustable now (A):** window, 13/26/52/104 weeks. Department selection via the button strip.

**Hard-coded (C):**

- **`Spc::MIN_COMPLETIONS_PER_WEEK = 3`** (`Spc.php:34`) — a week with fewer than three
  completed reviews is dropped from the chart. This is why some offices show as "thin" with a
  reason instead of a chart.
- **`Spc::CALIBRATION_WEEKS = 24`** (`Spc.php:37`) — control limits are fitted on the *first*
  24 weeks only, so a recent slowdown cannot widen the limits meant to catch it. This is also
  why `DEFAULT_WINDOW_WEEKS = 52` (`ProcessingTimeAnalytics.php:43`) — a 26-week window would
  leave only two weeks under observation.

  Both of these travel to R in the payload (`ProcessingTimeAnalytics.php:97–98`;
  `service.R:80–81` reads them), with the code comment *"Sent rather than hardcoded on the R
  side so one change of policy cannot leave the two engines disagreeing."* Clean config move.

- **`Spc::SIGMA_MULTIPLIER = 3.0` and `Spc::EWMA_LAMBDA = 0.2`** (`Spc.php:43, 46`) — these
  are **NOT** sent to R. See §7 below.
- **Trend direction cut-off ±0.5** (`Spc.php:282, 284`) — whether the weighted-trend bar reads
  "rising", "easing" or "steady". Also not sent to R.
- Rounding: 3 decimal places on means and control limits, 4 on sigma, 2 on deviation.

**Statutory / standard (D):** `D2_MOVING_RANGE_2 = 1.128` (`Spc.php:40`). This is the
tabulated Hartley constant `qcc` ships, deliberately not `2/sqrt(pi) = 1.1283792`. The
docblock explains that the difference is visible in the third decimal of every limit. Moving
it would break agreement with R's `qcc` package.

---

## §7 — Where R keeps its own copy (the dangerous ones)

The architecture here is mostly **very good**, and the client should say so at the defence:
PHP owns all SQL, builds a payload, and **ships the rule constants inside the payload**. R
reads them out rather than keeping its own. `service.R:13` states the design; `service.R:302`
says the renewal-risk scorer *"has no risk numbers of its own to drift from PHP's."*

Confirmed as **correctly single-sourced — a PHP change reaches R**:

| Constant | PHP | Sent at | R reads it at |
|---|---|---|---|
| Dashboard `top_n` (5) | `DashboardAnalytics.php:81` | `:158` | `service.R:558` |
| Growth `top_n` (6) | `BusinessGrowthAnalytics.php:50` | `:128` | `service.R:1049` |
| Growth `grace_days` (30) | `BusinessGrowthAnalytics.php:61` | `:139` | `service.R:1068` |
| SPC min completions (3) | `Spc.php:34` | `ProcessingTimeAnalytics.php:97` | `service.R:80` |
| SPC calibration weeks (24) | `Spc.php:37` | `ProcessingTimeAnalytics.php:98` | `service.R:81` |
| All risk weights, bands, thresholds | `RenewalRiskScoring.php:11–50` | `RenewalRiskAnalytics.php:176` | `service.R:308, 313, 409, 427, 440, 486, 497` |
| RA 11032 tiers (3/7/20) | `Ra11032.php:37` | `DashboardAnalytics.php:160` | payload |
| Expiry windows `[30,60,90]` | `DashboardAnalytics.php:104` | `:159` | payload |
| `drivers_per_row` (3), `lapsed_grace_days` (60) | `RenewalRiskAnalytics.php:124, 95` | `:166, 169` | `service.R:311, 383` |

### The genuine forks — a PHP-only change would silently NOT apply

| Constant | PHP | R | Sent? |
|---|---|---|---|
| **EWMA lambda = 0.2** | `Spc.php:46` | `service.R:184` `lambda = 0.2` | **No** |
| **Sigma multiplier = 3** | `Spc.php:43` | `service.R:184` `nsigmas = 3` | **No** |
| **Trend cut-off ±0.5** | `Spc.php:282, 284` | `service.R:261` | **No** |
| **Industry sort by `count` first** | `BusinessGrowthAnalytics.php:691` | `service.R:1232` | **No** — logic, not data |
| **Hartley d2 = 1.128** | `Spc.php:40` | implicit inside `qcc` | **No** — but it is an external standard (bucket D) |

These are the ones to be careful with. Change `Spc::EWMA_LAMBDA` alone and the PHP fallback
would drift while the R-served snapshots kept the old value — and because
`AnalyticsParityTest` compares the two to a tolerance of `1e-6`
(`api/tests/Unit/AnalyticsParityTest.php:9`), the test would catch it, which is the saving
grace. **The parity test is the safety net, and it works.** Say that at the defence.

### And a third copy nobody expects

`AnalyticsHistorySeeder.php:363` and `:367` hold private duplicates of
`HIGH_TECH_CATEGORIES` and `HIGH_TECH_CAPITAL_FLOOR`. They agree with `Ra11032` today.
Changing one without the other reclassifies the demo history but not new filings.

### The academic R script is not the app

Worth knowing before somebody greps `r/R/analytics.R` and panics. `r/plumber.R:5–7` sources
only `config.R`, `R/spc.R` and `R/service.R`. `analytics.R`, `des.R` and `generate.R` are the
standalone academic pipeline (`app.R`, `run_all.R`) and **the live service never loads them**.
`analytics.R:141–142` carries an older expiry-band table (1→30, 7→24, 15→18, 30→12, 90→6)
that does **not** match `RenewalRiskScoring::EXPIRY_BANDS`. That is not a production bug — the
served product never runs it — but it is a paper-versus-product discrepancy a sharp panelist
could find, so be ready for it.

### What changing a shared constant actually costs

Any change that alters a computed figure invalidates the committed golden fixtures in
`api/tests/fixtures/analytics/*.r-output.json`. Regenerating them needs a running R service:
`php tests/fixtures/analytics/build-fixtures.php`, then `cd r && Rscript run_api.R` and curl
each endpoint (procedure at `api/tests/fixtures/analytics/build-fixtures.php:12–21`). So the
true cost of "change 500 to 1000" is: edit constant → run tests → regenerate fixtures →
`analytics:refresh` → deploy. Not one line.

---

## Ranked: what a panelist is most likely to poke at

1. **"Why 500 metres?"** — the most concrete, most visible number in the product. It is on
   the applicant's screen with a ring drawn round it.
2. **"Why is a manufacturer with ₱1M highly technical?"** — because the compliance report
   hangs off it and because we have no LGU sign-off. Have the A10 answer ready.
3. **"Why six industries?"** and **"why five barangays?"** — two panels on two screens using
   two different numbers for the same idea, and one of the two spells it as a word.
4. **"Why does the Growth Trend chart rank the biggest industries instead of the
   fastest-growing ones?"** — this is the one that is hardest to defend, because the panel
   title says growth and the sort says size.
5. **"Why 24 weeks?"** — defensible, and the docblock at `Spc.php:16–22` already answers it.
6. **"Why 30/25/20/15/10?"** — the risk weights. They sum to 100 and each has a written
   rationale in `rulebook()`, which reads well.
7. **"Why Low 0–5 / Medium 6–10 / High 11+?"** — straight from the spec, which helps.
8. **"Why does it only remind at 30/15/7/1 days?"** — from the spec §2/§3.

## Worst case — the single most embarrassing "we cannot"

**`LocationInsights::RADIUS_M = 500`.**

It is the number a panelist is most likely to name, because it is drawn on a map in front of
them and stated in the copy beside it. It is the number with the weakest independent
justification — the spec says 500 m, and the spec is ours. And a change is small enough that
"we cannot do that live" sounds worse than the change itself would be. A panelist asking for
250 m or 1 km is asking a reasonable planning question, and the answer today is a code edit
and a redeploy.

Runner-up: **`BusinessGrowthAnalytics::TOP_N = 6`** — because "show me the top 10" is the most
natural request in the world, and the true answer is "we would also need four more chart
colours."

---

## The three constants to move to config first

### 1. `LocationInsights::RADIUS_M` (and the two band thresholds with it)

`api/app/Support/LocationInsights.php:50, 53, 55` → `config/analytics.php`.

**Why first:** highest question-probability, lowest cost. The number is already
single-sourced — the map ring and every label read it off the API response, so there is no
frontend work at all. It never crosses the R boundary, so there is no parity risk and no
fixture to regenerate. Ship the two band thresholds in the same commit; they are already
returned in the payload for the same reason. This turns the single most likely "we cannot"
into "one moment" — a config edit and a `config:clear`.

### 2. `Ra11032::HIGH_TECH_CATEGORIES` and `HIGH_TECH_CAPITAL_FLOOR`

`api/app/Support/Ra11032.php:49, 54` → `config/analytics.php`, **and delete the seeder's
private copies at `AnalyticsHistorySeeder.php:363, 367`, pointing them at the same config.**

**Why second:** this is the only bucket-C constant that is also an **open question with the
client's own LGU** (A10). When BPLO finally answers, the change should be a config edit and a
reseed, not a code review. Consolidating the duplicate at the same time removes the one place
in the analytics suite where the demo history and live filings could be classified by two
different rules — a divergence the code itself warns about but nothing enforces.

### 3. `Spc::SIGMA_MULTIPLIER`, `Spc::EWMA_LAMBDA`, and the ±0.5 trend cut-off — **into the payload, not just into config**

`api/app/Support/Spc.php:43, 46, 282, 284`, added to
`ProcessingTimeAnalytics::dataset()` beside the two constants that are already sent, and read
in `r/R/service.R:184, 261` with the current values as fallbacks.

**Why third:** these are the last genuine forks between the two engines. Every other rule
constant already travels in the payload — the pattern exists, it is documented, and these
three were simply missed. Until they are sent, a change to any of them applies to the PHP
fallback and *not* to the R-served snapshots, which is the failure mode the project has
already been bitten by once (`BusinessGrowthAnalytics.php:131–138` records exactly that story
for `grace_days`). This one is not about answering a panelist; it is about the change looking
applied when it is not.

---

*Compiled read-only against `main` on 6 August 2026. No source file was modified.*
