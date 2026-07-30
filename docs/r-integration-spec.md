# R Integration — canonical spec

Source of truth: `R INTEGRATION (MIDTERM).pdf` (16pp, client paper) + the revised UI mockups
in `.playwright-mcp/updated-gui/`. The client called the paper **"the final flow"**.

## Architecture (decided 2026-07-30 — supersedes an earlier native-only decision)

R **stays a separate program** and remains the statistics engine, as the paper describes.
An earlier instruction ("no more separate R program") was corrected by the client: *"i mean
those things should be fetched from r. so like our original where r is kinda separate program
but its still fetched from there still stands."*

Three decisions the client made explicitly:

**1. Laravel pushes data to R; R never touches the database.**
`R/db.R`'s postgres readers stay stubs and `MODE` stays `"synthetic"` for direct DB access.
Laravel owns all SQL, which keeps RBAC and office-scoping in exactly one place, and keeps the
SQLite dev database working untouched. R is a pure compute service: rows in, statistics out.

**2. Batch with a refresh command — not per-request compute.**
So *the refresh command is the push*. There is no live R call on a page load:

    php artisan analytics:refresh
        │
        ├─ Laravel queries the register (one owner of SQL, scoping applied)
        ├─ POSTs the row sets to plumber
        ├─ R computes the statistics (analytics.R / spc.R)
        ├─ returns statistics JSON
        └─ Laravel persists the result

    page load ──> Laravel reads the persisted result   (no R involved, fast)

Consequences to honour, not paper over:
- Figures are as fresh as the last refresh. Every analytics screen must show **when the
  statistics were computed**. A tester's brand-new application legitimately will not appear
  until the next refresh, and the UI must not imply otherwise.
- R being down does not break analytics; it only stops them getting newer.

**3. The PHP port from PR #31 is kept as an automatic fallback.**
R is primary. If plumber is unreachable *and* no persisted result exists, fall back to the
native PHP statistics and **say so on screen** ("computed locally"). Never silently serve
fallback numbers as if they came from R.

The accepted risk here is drift between two implementations. Mitigation: the PHP port and the
R original must be tested against the same fixtures and asserted to agree within tolerance —
that test is the thing that keeps the fallback honest. PR #31's SPC port is already validated
against `qcc` 2.7 output, so extend that pattern rather than inventing a new one.

**Do not expose the plumber port publicly.** The live Cloudflare tunnel forwards only port
5173; plumber must stay bound to localhost. It has no authentication of its own, so anything
that can reach it can read register data.

**Build order the client asked for** (do not reorder):
1. Build every screen with **hardcoded fixture data** matching the mockups.
2. **Screenshot all of them.**
3. Replace the fixtures with real queries over seeded database rows.

### Plumber contract — needs rewriting

`r/plumber.R` today exposes **`GET`** endpoints that read precomputed `outputs/*.csv`
(`/health`, `/spc/flags`, `/des/scenarios`). That does not fit "Laravel pushes rows", so the
surface changes to **`POST`** endpoints that accept row sets as JSON and return statistics:

| Endpoint | Accepts | Returns |
|----------|---------|---------|
| `GET  /health` | — | `{status, r_version}` — keep, used by the fallback check |
| `POST /spc/processing-time` | weekly assignment completions per department | control limits, flagged weeks, EWMA trend |
| `POST /growth/lifecycle` | business registrations, closures, renewals, barangay, industry | growth rate, cohort survival, status summary, trends |
| `POST /dashboard` | applications, decisions, deadlines, inspections, permits | the §1 panel figures |
| `POST /renewal-risk` | per-business renewal history + expiry + compliance | risk score + band per business |

Rules for this layer:
- Every endpoint is **pure**: same input JSON ⇒ same output. No file reads, no `Sys.Date()`,
  no RNG without a fixed seed. This is what makes it testable and reproducible.
- Keep the existing `SEED <- 1103` and `ANCHOR_DATE` discipline from `config.R` — the client's
  prototype is deterministic by design and the integration must not lose that.
- The synthetic CSV path (`run_all.R`, `generate.R`) stays working. It is how the R side is
  developed and validated independently of Laravel, and it is the reference the PHP fallback
  is checked against.
- Laravel's client needs an explicit timeout and must treat any non-2xx or timeout as
  "R unavailable" → fallback path. A hung plumber must not hang a page load.

## Screen inventory

| # | Feature | Mockup | State |
|---|---------|--------|-------|
| 1 | Analytics Dashboard | `115.png`, p1–2 | not built |
| 2 | Renewal Risk Prediction | `118.png`, p6 | in progress |
| 3 | Notifications for Business Owners | `121.png`, p9 | partly exists |
| 4 | Business Growth Analysis / Lifecycle Monitoring | `122.png`, p10 | partly built (PR #31) |
| 5 | Business Location Insights | `124.png`/`125.png`, p13 | not built |
| 6 | Permit Processing Time Monitoring | p15 | **built** (PR #31) |

Not in the paper: **DES / staffing simulation**. `r/R/des.R` exists in the R source but no
screen and no section covers it. Treat as out of scope for the final flow.

---

## 1. Analytics Dashboard

Header: title + filter icon + **"Generate Report"** button (this button appears on every
analytics screen — reuse one component).

KPI cards: Active Businesses · Applications YTD · This Month · Compliance Rate.

### Application Volume
Counts by transaction type: New, Renewals, Amendments, **Total**.

### Decision Outcomes
Counts of Approved, Returned for Revision, Rejected, Pending, plus:

    Approval Rate (%) = (Approved Applications ÷ Total Decisioned Applications) × 100

"Decisioned" excludes Pending — do not divide by the grand total.

### Average Processing Time by RA 11032 Tier
Statutory tiers from Republic Act 11032 (Ease of Doing Business Act):

| Tier | Legal target | Mockup actual |
|------|--------------|---------------|
| Simple | 3 days | 3.7d |
| Complex | 7 days | 6.5d |
| Highly technical | 20 days | 23.8d |

Mean processing days per tier. The mockup shows Simple and Highly-technical **breaching**
their targets — the visual must make a breach legible, not hide it.

### Average Time-in-Stage by Department
Mean days per department (BPLO, City Health, Fire Protection, Zoning) and identification of
the **slowest** department. R equivalent: `department_workload_stats()`. Emit a bottleneck
summary derived from the computed values, not a hardcoded sentence.

### Compliance Monitoring — three indicators

    RA 11032 Processing (%)      = (Applications Processed Within Legal Deadline ÷ Total Decisioned) × 100
    Business Permit Compliance (%) = (Businesses with Complete Valid Permits ÷ Total Active Businesses) × 100
    Renewal Compliance (%)       = (On-Time Renewals ÷ Total Permits Due for Renewal) × 100

Mockup values: 71% / 82% / 68%.

### Permits Approaching Expiry
Rows = windows **Next 30d, Next 60d, Next 90d, Expired**. Columns = BPLO, Sanitary, Fire,
Total. Windows are cumulative in the mockup (30d ⊂ 60d ⊂ 90d) — 20/38/29/87 then 47/44/43/134
then 72/70/68/210.

### Top Barangays
Group businesses by barangay, count **active** ones, compute each barangay's percentage share,
rank descending. Mockup: Concepcion 306 / 12.6%, Longos 384 / 10.3%, Tonsuya 341 / 8.4%,
Bayan-bayanan 216 / 7.1%, San Agustin 197 / 6.6%.

### Top Lines of Business
Group by industry/category, rank by count. Mockup: Retail — general 782, Food & beverage 481,
Personal services 327, Repair & installation 218, Wholesale trade 194.

### Form of Organization
Counts by Sole Proprietorship, Corporation, Partnership, Cooperative.

### Inspections
Per type (Sanitary, Fire Safety, Zoning): scheduled, completed, passed, failed, conditional.

    Pass Rate (%) = (Passed Inspections ÷ Completed Inspections) × 100

Denominator is **completed**, not scheduled.

### Officer Activity
Average response time, fulfilled requests (count + %), meeting participation.

### GIS Mapping
Choropleth/point map of Malabon barangays with business locations.

---

## 2. Renewal Risk Prediction

KPI cards: High Risk · Moderate Risk · Low Risk · **Reminders Sent**.

"Businesses at Risk" table: Business, Barangay, risk score, Expires, Action.
"Recommended Actions" panel: Immediate Follow-up / Send Reminder / Monitor with counts.

Actions map from risk level: High → Immediate Follow-up, Moderate → Send Reminder,
Low → Monitor.

Permit expiration monitoring runs at **30, 15, 7 and 1 day** before expiry.

### ⚠ Honesty constraint (deliberate deviation from both paper and mockup)

The paper describes `predict_renewal_risk(business_id)` using "predictive statistical models"
and an **"Estimated Probability of Delayed Renewal"**; the mockup's column header reads
**"PROB. DELAYED"** with values 88% / 81% / 74% / 52% / 41% / 12%.

**No such model exists in the R source.** `r/R/*.R` contains no renewal-risk model — only
`generate.R` mentions renewal at all. There is nothing to port and nothing fitted on
historical outcomes.

Therefore: implement a **transparent weighted rule score** (days to expiry, past renewal
punctuality, open compliance findings, inspection history), document the rules and weights in
code, keep the numeric column and the High/Moderate/Low banding, but **do not label it a
probability or a prediction confidence**. Calling a heuristic a probability would claim
predictive validity the system cannot support, and a BPLO officer could reasonably act on
"88% probability" as if it were calibrated.

This deviation is flagged for the client to overrule knowingly. If they want a real
probability, that requires fitting on historical renewal outcomes and reporting calibration.

---

## 3. Notifications for Business Owners

Renewal reminders fire at **30, 15, 7 and 1 day** before permit expiry, only for permits not
yet renewed. Paper's copy: *"Reminder: Your business permit will expire in 30 days. Please
renew your permit before the expiration date to avoid penalties."*

Notification types visible in `121.png`: permit expiring (warning), inspection scheduled,
documents verified, application received — each with icon, title, body, timestamp, chevron.

**This is what makes "Reminders Sent" on screen 2 real.** A reminder must leave a persisted
record; the KPI counts those records. Do not invent a counter.

---

## 4. Business Growth Analysis / Business Lifecycle Monitoring

The paper titles this **Business Growth Analysis** (p10); mockup `122.png` titles it
**Business Lifecycle Monitoring** and renames the second KPI. Mockup is newer — follow it for
naming, follow the paper for formulas. Reshape the PR #31 screen rather than adding a new one.

KPI cards: Business Growth Rate (+4.2%) · Cohort Survival Rate / Business Renewal Performance
(76%) · Closures (Period) (34) · Top Growing Barangay (Longos).

    Growth Rate (%) = ((Current Period Businesses − Previous Period Businesses)
                        ÷ Previous Period Businesses) × 100

    Business Renewal Performance (%) = (Businesses that Continued Renewing on Time
                                        ÷ Total Businesses in the Group) × 100

The second is a **cohort survival** measure — R uses the `survival` package to follow a group
across multiple renewal periods. Guard the division: an empty prior period must render
"No prior period", never a divide-by-zero or a fabricated 0%.

Panels:
- **Business Status Summary** — Active / Expired / Inactive / Closed with count and share
  (2,847 / 83.6%, 312 / 9.2%, 158 / 4.6%, 88 / 2.6%).
- **Top Growing Barangays** — ranked by *increase* in newly registered businesses between
  periods, with deltas (Longos +28, Concepcion +22, Tonsuya +11, Potrero +4).
- **Business Closure Trend** — inactive + closed grouped by month.
- **Business Industry Growth Trend** — per industry over time with growing/declining labels
  (Retail 742 growing, Food & Bev. 481 declining, Personal Serv. 327 growing).

---

## 5. Business Location Insights

Lives **inside the apply wizard's zoning step**, in the modal shown after the applicant picks
a location on the GIS map. Not a standalone admin screen.

Modal header in the mockup reads **"CONGRATULATIONS!"** with body *"The new business for
{line} is conforming / within the allowed use for {AREA LOCATION}. You may now proceed with
the processing of your Business Permit Application."* then a **"Location insights:"** table,
`Back` / `Proceed to Application` actions, and the step counter `Part 1 of 8`.

Note: this supersedes an earlier judgment call of mine that used "Location recorded" instead of
"CONGRATULATIONS!" and omitted the insights panel. Restore the mockup's wording and add the panel.

| Insight | Computation |
|---------|-------------|
| Nearby Similar Businesses | count of same/related category within **500 m** |
| Business Concentration | all registered businesses in radius, banded **Low 0–5, Medium 6–10, High 11+** |
| Most Common Business Type | **mode** of nearby business categories |
| Average Distance to Similar Businesses | mean distance from selected point to each similar business, via GIS coords |

Mockup values: 4 cafés · Medium · Foods & Beverages · 320 m.

Zoning conformity check stays as-is; these insights are additive decision support.

### Built — and the two decisions that differ from the rest of the suite

**Spatial data exists, so the 500 m radius is real.** All 748 `business_addresses` rows carry
`latitude`/`longitude` (746 distinct pairs) inside Malabon's bounding box, and 691 registered
businesses have both coordinates and a PSIC-coded line. Distances are therefore measured, not
proxied, and the labels say "within 500 m" because that is what was computed. No PostGIS is
involved: the column pair is plain `decimal(10,7)` (the migration defers geometry to S7), the
prefilter is a bounding box that behaves the same on SQLite and PostgreSQL, and the metric is a
haversine in PHP.

The admin dashboard's "No mapped business locations yet" is unrelated to missing coordinates —
`AnalyticsPage.useBusinessMarkers` derives pins from the *inspections* feed rather than from
addresses, so it goes empty whenever that feed is empty. The data was there all along.

**Per-request, not batch.** This is the one analytics screen the snapshot architecture cannot
serve. A snapshot is keyed by a fixed variant list (`config/analytics.php`) because a window
cannot be sliced out of a wider one; here the key is a latitude/longitude the applicant chose
seconds ago — continuous and unbounded, so there is nothing to precompute. This is exactly the
case that config already calls the honest outcome: computed locally, and says so
(`meta.engine = "PHP"`).

**Computed in PHP, not R** — the client should know this is a deviation from the paper, which
attributes the spatial analysis to `sf`/`dplyr`. The statistics are a count, a banding, a mode
and an arithmetic mean over a haversine: no spatial predicate and no model fitting, so there is
little for `sf` to do, and `Rscript` per applicant click is ruled out. If the client wants R to
own it, the shape that fits is a plumber endpoint called from Laravel on the pin drop — which
would put a synchronous R call on an applicant's critical path, the thing the batch decision
was made to avoid.

Implementation notes:
- "Similar" is the **PSIC group** (first 3 digits) — the standard's own notion of a related
  trade, so 56301 coffee shop matches 56302 bar but not 56101 restaurant. `PsicTaxonomy`.
- The catch-all `00000` code relates to nothing; it would otherwise make every unlisted trade
  a neighbour of every other unlisted trade.
- "Registered" means a business with an application past `draft`, so a tester's abandoned draft
  never inflates the next applicant's neighbourhood.
- The zoning step is Part 1 and Line of Business comes later, so on a new filing the applicant
  usually has **no declared line** when the modal opens. The two category-dependent figures
  report as unavailable and name what would unlock them; the two that need no category still
  answer. Renewals and amendments have the line prefilled and show all four.
- Nothing identifying is returned — a count, a band, a category name, a mean distance. An
  exclusion id is honoured only if the caller owns that business, otherwise the count becomes
  an oracle for whether a given business sits on a block.
- The modal keeps one sentence of the cautious earlier copy: CPDO still makes the final
  determination. The headline and body are the mockup's.

---

## 6. Permit Processing Time Monitoring — BUILT (PR #31)

Kept here for completeness; validated against `qcc` 2.7 output.

- **Department Processing Time Chart** — weekly mean processing time per department with the
  normal operating range shaded; points outside marked.
- **Process Status Indicator** — "Within Normal Range" / "Outside Normal Range".
- **Flagged Weeks** — week-of date + deviation (Jan 12 +0.9 days, Jan 19 +1.4 days).
- **Gradual Slowdown Detector** — EWMA (λ=0.2, 3σ), weights recent weeks more heavily,
  reports a trend such as "Rising".

Implementation: X-bar individuals limits, tabulated d2 = 1.128, moving-range mean over n−1,
limits fitted on the first 24 weeks, minimum 3 completions per week for a week to count.

Naming: the paper's prose still says "Anomaly Detection"; commit `a3fa934` renamed it to
"Processing Time Monitoring", which the mockup title confirms. Keep the new name.
