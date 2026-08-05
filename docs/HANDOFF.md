# BizTrack — Session Handoff

**Written:** 2026-08-05
**Repo:** `/Users/kenmondragon/Documents/GitHub/biztrack` (GitHub: `antuK3n/biztrack`)
**Branch:** `main` at `3502a2b`, pushed, working tree clean
**Deployed to testers:** `demo` branch at `b2d0942` — **two commits behind main**

---

## 0. How to read this document

This is written for a Claude instance starting with **no memory of the previous
sessions**. It assumes you can read code but knows you cannot read the last three
weeks of conversation.

Read in this order depending on what you have been asked to do:

| If the task is… | Read sections |
|---|---|
| Anything at all | §1 (state), §2 (safety rules) — **non-negotiable** |
| A bug fix | §2, §3 (architecture), §14 (traps), §15 (open bugs) |
| UI / copy work | §2, §8 (frontend), §9 (design), §14 |
| Analytics or R | §7, §14.6 |
| Apply-wizard work | §5, §6 — it is a 4,000-line file, do not go in blind |
| Deploying to testers | §11 — and read §2.3 first |
| Answering "what's left" | §15, §16 |

Sections marked **⚠ HARD RULE** describe things that have already gone wrong once.
They are not style preferences.

---

## 1. Current state

### 1.1 Git

```
main    3502a2b  chore(tools): keep the adviser's R-integration notes in the repo
        9f3b489  fix(errors): stop blaming the API for a gateway's error page
        b2d0942  fix(apply): one trade, the city's seal, and a Market card people can find   ← demo is here
```

- `git status` is clean. Everything is committed and pushed to `origin/main`.
- `git rev-list --left-right --count origin/main...HEAD` → `0  0`.
- There are **two worktrees**:
  - `/Users/kenmondragon/Documents/GitHub/biztrack` → branch `main` (you work here)
  - `/Users/kenmondragon/Documents/GitHub/biztrack-demo` → branch `demo` (what testers see)

The `demo` worktree is **pinned deliberately**. Testers complained that fields moved
under them mid-session because the tunnel used to serve the dev server off the working
tree. See §11.

### 1.2 What is running right now

```
node  [::1]:5173        Vite dev server      (main worktree, HMR)
node  [::1]:5199        Vite dev server      (isolated E2E stack)
node  127.0.0.1:5199    (same, IPv4)
node  127.0.0.1:5180    vite preview         (demo worktree, built bundle)
php   127.0.0.1:8082    Laravel              (demo worktree API)
R     127.0.0.1:8787    plumber              (analytics engine)
cloudflared             quick tunnel → 127.0.0.1:5180
```

Public tester URL at time of writing:
`https://dayton-invitation-queue-via.trycloudflare.com`

Quick tunnels are ephemeral. The URL changes every time `cloudflared` restarts.

### 1.3 Test suites — all green as of this handoff

| Suite | Command | Result |
|---|---|---|
| Pest (API) | `cd api && php artisan test --compact` | **593 passed, 4,887 assertions**, ~22 s |
| Playwright (web) | `cd web && npx playwright test` | **69 passed**, ~1 min |
| TypeScript | `cd web && npx tsc -b --force` | clean |

⚠ **HARD RULE (§14.1):** `tsc --noEmit` checks **nothing** in this repo. Use `tsc -b`.

### 1.4 Database

- SQLite. `api/database/database.sqlite`.
- **The demo API and the dev API share this one file.** `scripts/demo-up.sh` sets
  `DB_DATABASE="$MAIN/api/database/database.sqlite"` — i.e. the demo backend on :8082
  points at the *main* worktree's database.
- Consequence: **`:5173` (dev) and the public tunnel read and write the same rows.**
- Current volume: 83 users, 734 businesses, 1,701 applications, 4,125 permits,
  135 PSIC codes.
- Real tester data is in there. See §2.1.

---

## 2. Safety rules — read before touching anything

These come from explicit user instruction and from incidents. Treat them as
constraints, not advice.

### 2.1 ⚠ HARD RULE — the database has real tester data in it

- **Never run `migrate:fresh`, `db:wipe`, or `db:seed --force`** on
  `api/database/database.sqlite` during or near a live tester session.
- **Never delete rows you did not create.** Testers from Malabon City Hall have
  filed real applications against this database.
- `dev.sh` runs `php artisan migrate:fresh --seed` on line 18. **Do not run `dev.sh`**
  while testers are active. It will destroy their work. Start the servers by hand
  instead (§11.1).

### 2.2 ⚠ HARD RULE — secrets and env files

- `api/.env`, `web/.env`, `web/.env.development` are **permission-blocked**. You cannot
  edit them. If a change is needed there, tell the user exactly what line to change.
- `Revenue Code.pdf` is gitignored and **stays local**. Do not commit it, do not
  paste its contents into a public artifact.
- **Never ask for, accept, or echo a password.** All demo accounts share the password
  `biztrack1`; that is a demo-only credential and must never be reused anywhere real.
- `APP_DEBUG=false` must stay off while the tunnel is public. A stack trace on a public
  URL leaks filesystem paths and config. `scripts/demo-up.sh` enforces this.

### 2.3 ⚠ HARD RULE — the R plumber service has no authentication

`r/plumber.R` on :8787 will answer anyone who can reach it, and it can read the whole
business register. It **must** stay bound to `127.0.0.1`.

`scripts/demo-up.sh` has a guard that refuses to start the tunnel if plumber is bound
to `0.0.0.0`. Do not remove it.

### 2.4 ⚠ HARD RULE — Playwright must not run against :5173

`:5173` proxies to the shared live database (§1.4). Playwright creates and mutates
applications. Running the suite against :5173 corrupts tester data.

**Use the isolated stack on :5199.** `web/scripts/e2e-stack.sh` raises it, and
`playwright.config.ts` is wired to it. If you write a one-off script, point it at
`http://127.0.0.1:5199`.

*(This happened. I briefed subagents to test against :5173. One agent refused and used
:5199 instead. The agent was right and I was wrong.)*

### 2.5 Working-tree hygiene

- **Never `git stash`** on the shared working tree. Other agents and the user may have
  uncommitted work in it.
- Do not restart dev servers without a reason. The user may be mid-test in a browser.
- Do not `git reset --hard` `main` while subagents are working in it. *(I did this once
  and destroyed an agent's in-progress work.)*

### 2.6 Outward-facing actions

Deploying to the tunnel is outward-facing — testers are looking at it. Restarting the
demo backend drops every open tester session. Confirm before redeploying unless the
user has just asked for it.

---

## 3. Architecture

### 3.1 Shape

```
web/     React 18 + TypeScript + Vite v8 + Tailwind v4 + react-router
api/     Laravel 13 / PHP 8.4, Sanctum tokens, SQLite (Postgres in prod runbook)
r/       R 4.6.1 + plumber — the statistics engine, batch only
infra/   docker-compose for the MISD box
docs/    specs, runbooks, and the LGU question list
scripts/ demo-up.sh / demo-down.sh
tools/   annotate-r-integration.py — the adviser's 33 review notes
```

### 3.2 The R boundary — important and frequently misunderstood

**Laravel owns all SQL. R is a separate program. Nothing in the request path calls R.**

The architecture is **batch**:

1. `php artisan analytics:refresh` (`api/app/Console/Commands/RefreshAnalytics.php`)
   pushes register rows to plumber on :8787.
2. R computes.
3. Laravel persists the result into `analytics_snapshots`.
4. The analytics endpoints read that persisted snapshot — one indexed read.

Therefore: **an analytics page load cannot be slowed or broken by R being slow or
down.** If you find yourself theorising that a dashboard failure is R's fault, you are
wrong; check §14.6 instead.

When no snapshot exists, `AnalyticsResolver` falls back to a **PHP port** that computes
the same figures, and labels itself in `meta`. Both engines emit the same schema. The
`meta` block is the only way a screen can tell them apart, which is exactly why every
analytics response carries it and every screen displays it. Serving fallback numbers as
R output would make drift between two implementations invisible.

`api/app/Services/RAnalytics.php` collapses **every** failure mode to `null` — refused
connection, DNS, timeout, 500, malformed chunked body. Config lives in
`api/config/analytics.php`: `R_ANALYTICS_TIMEOUT=60`, `R_ANALYTICS_CONNECT_TIMEOUT=2`.

### 3.3 API controllers

`api/app/Http/Controllers/Api/`:

```
Admin/                      AnalyticsController        ApplicationController
AssignmentController        AuthController             BusinessController
ChatbotController           ClearanceController        DocumentController
InspectionController        LocationInsightsController MessageController
NotificationController      OfficeFormController       OfficerRequestController
PaymentController           PermitController           PriorPermitController
ReferenceController         VerifyController
```

### 3.4 Support classes — where the domain logic actually lives

`api/app/Support/` is the important directory. Controllers are thin.

```
AnalyticsDatasets        AnalyticsDefinitions      AnalyticsRefresher
AnalyticsResolver        ApplicationVisibility     Audit
BusinessGrowthAnalytics  DashboardAnalytics        Des
DesEventQueue            HeldPermits               LocationInsights
Numbering                OcrLite                   PdfFile
PermitFees               ProcessingTimeAnalytics   PsicTaxonomy
QrCode                   Ra11032                   RenewalRiskAnalytics
RenewalRiskScoring       Rounding                  Spc
StaffingSimulation
```

Notable:

- **`ApplicationVisibility`** — decides which filings an office may see. This is the
  security boundary for office separability (§4.3).
- **`Ra11032`** — the Anti-Red-Tape service standards. `Ra11032::TIERS` is `3 / 7 / 20`
  working days for simple / complex / highly technical. **Do not hard-code a number of
  days anywhere else**; the chatbot used to say "10 working days", which is not a tier
  that exists in the law.
- **`AnalyticsDefinitions`** — 27 definitions, each with `label` / `formula` / `covers`
  / `why`. These are what the analytics screens display to explain their own figures.
- **`RenewalRiskScoring`** — see §7.3, there is a wording constraint enforced by a test.
- **`Spc`** / **`Des`** — statistical process control and exponential smoothing, the
  R-side statistics mirrored in PHP.

### 3.5 Services

```
ChatbotResponder   ClearanceService   FeeCalculator
NotificationService  PaymentGateway   RAnalytics   WorkflowService   Sms/
```

**`FeeCalculator`** gates every rule on the permit types actually requested:

```php
array_intersect($rule->permit_types, $requested)
```

This matters — see the open question A3/A4 in §16, where it is not yet settled whether
the Fire Code fee and the sanitary inspection fee *should* be gated on their clearance
being requested, or whether they are owed annually regardless.

### 3.6 Models

```
AnalyticsSnapshot  Application  ApplicationAssignment  ApplicationDocument
ApplicationOfficeForm  ApplicationStatusHistory  AppNotification  AuditLog
Barangay  Business  BusinessAddress  BusinessLine  BusinessOwner
ChatbotConversation  ChatbotMessage  ComplianceCheck  Department
DocumentType  FeeAssessment  FeeRule  Inspection  Message
MessageAttachment  MessageThread  OfficerRequest  OfficerRequestResponse
OfficeSignatory  Payment  Permission  Permit  PermitExpiryNotice
PermitType  PsicCode  Role  User
```

⚠ `Business` and `User` are **soft-deleted**. This leaks `null` into payloads whose
TypeScript types claim non-null. See §14.2 — it has already caused one production
crash.

---

## 4. Domain model

### 4.1 Application statuses

`api/app/Enums/ApplicationStatus.php`:

```php
Draft          = 'draft'
Submitted      = 'submitted'
PendingPayment = 'pending_payment'
UnderReview    = 'under_review'
ForInspection  = 'for_inspection'
Approved       = 'approved'
Rejected       = 'rejected'
Returned       = 'returned'
Cancelled      = 'cancelled'
```

Other enums: `ApplicationType`, `AssignmentStatus`, `InspectionResult`,
`InspectionStatus`, `OfficerRequestStatus`, `PaymentMethod`, `PaymentStatus`,
`PermitStatus`.

The admin queue is expected to show, in order: **pending payment → paid → for approval
→ for inspection (only for permits that actually have one) → approved**. This was an
explicit user requirement.

### 4.2 Permit types (seeded)

| Code | Name |
|---|---|
| `BUSINESS` | Mayor's / Business Permit |
| `SANITARY` | Sanitary Permit / Health Certificate |
| `FSIC` | Fire Safety Inspection Certificate |
| `OCCUPANCY` | Occupancy Permit |
| `CEC` | City Environmental Certificate |
| `MARKET` | Market Clearance |
| `ZONING` | Zoning / Locational Clearance |

The six that are not `BUSINESS` are "the clearances". They are now chosen and submitted
**before** payment (§6).

### 4.3 Roles and office separability

Nine roles, with permission counts:

| Role | Perms |
|---|---|
| `admin` | 18 |
| `bplo_staff` | 12 |
| `business_owner` | 8 |
| `sanitary_officer` | 7 |
| `fire_inspector` | 7 |
| `zoning_officer` | 7 |
| `obo_staff` | 6 |
| `cenro_officer` | 6 |
| `market_admin` | 6 |

⚠ **This was a real data-leak bug (checklist item #111).** All eight staff roles used
to hold `application.view_all`. A sanitary officer could see 115 requests when only 38
were theirs.

Fixed and verified live: sanitary now sees 38 (all CHO), bplo sees 125 (all offices).

`analytics.view` is held by **`admin` and `bplo_staff` only**. The other six offices get
a 403 and the nav does not offer them the link. That is correct and deliberate: the
analytics aggregates read every office's assignments, so exposing them to an ordinary
office reviewer would hand them a summary of filings `ApplicationVisibility`
deliberately keeps out of their queue. BPLO is the exception because it already holds
`application.view_any_office`.

### 4.4 Demo accounts

All use password `biztrack1`. Staff portal at `/staff/login`, citizens at `/login`.

| Email | Role |
|---|---|
| `admin@biztrack.local` | admin |
| `bplo@biztrack.local` | bplo_staff |
| `sanitary@biztrack.local` | sanitary_officer |
| `fire@biztrack.local` | fire_inspector |
| `zoning@biztrack.local` | zoning_officer |
| `obo@biztrack.local` | obo_staff |
| `cenro@biztrack.local` | cenro_officer |
| `market@biztrack.local` | market_admin |
| `owner@biztrack.local` | business_owner |
| `juan@biztrack.local` | business_owner |
| `mjmakiling@biztrack.local` | business_owner |
| `inactive@biztrack.local` | business_owner (deliberately inactive) |

There is **no `treasury` account** — logging in as one returns 422. If you need a
treasury role it does not exist yet.

---

## 5. The apply wizard

`web/src/pages/applicant/ApplyWizard.tsx` — **~4,000 lines**. Do not open it blind and
do not regex it (§14.3).

Supporting files in the same directory:

- `FeeProfileStep.tsx` — capitalization / gross sales
- `OfficeFormStep.tsx` — the per-office forms
- `ClearanceStagePage.tsx` — the six clearances (§6)
- `LocationInsightsPanel.tsx` — the figures in the zoning-result modal

### 5.1 Step order

Data-privacy consent comes **first**, before any data is collected. This was an explicit
user instruction (RA 10173) and is enforced by `PrivacyNoticeDialog.tsx`.

Then: business details → location (map pin) → line of business (PSIC) → fee profile →
LGU section (office forms) → clearances → review → **submit** → **pay last**.

### 5.2 PSIC is single-select

⚠ Recently changed on user instruction: *"for our zoning that will fuck it up. for now
just make it so that its only one psic code that they can choose."*

```ts
function toggle(code: PsicCode) {
  const already = lines[0]?.psic_code_id === code.id
  if (already) return
  onChange([{ psic_code_id: code.id, line_of_business: '', products_services: '' }])
  setOpen(false)
}
```

Accessibility consequences, already implemented — **keep them if you touch this**:

- Each row is `role="radio"` with `aria-checked`, not a checkbox.
- The results container `#psic-results` is `role="radiogroup"` with
  `aria-label="Line of business"`.
- The dropdown closes on pick (it is single-select now, so staying open is wrong).

There are **135 PSIC codes** seeded; the picker shows 136 options (135 + the "Other"
affordance). The user once asked *"just me or theres so little psic businesses now"* —
the answer was that the list had been correctly filtered, and the full trade list was
restored in `816a1b6`.

### 5.3 Removed fields

- **Products/Services** — removed on user instruction. A comment marks where it was.
- **Capitalization was being asked twice.** Fixed in `97eb5ab` ("ask for capital once,
  and say what the category actually is"). If you see two capitalization inputs
  reappear, that is a regression.
- Zoning classification (PSIC) and the "category" asked elsewhere are **different
  things** — this confused the user and was restructured. Do not merge them again
  without re-reading `docs/rehaul-spec.md`.

### 5.4 TIN input

The TIN is **four boxes of three digits**, typing walks across them, paste into the
first box spreads across all four, and a TIN already on file reads back into the four
boxes. All four boxes are **one named question**, not four nameless ones — there are
four Playwright tests specifically covering this (`apply-wizard.spec.ts:632`, `:671`,
`:719`, `:747`).

### 5.5 Placeholders

⚠ A placeholder shows the **shape** of an answer, never an answer (`a525c05`).

Incident: I changed a phone placeholder from `09XX XXX XXXX` to `e.g. 0917 123 4567`.
The user flagged it as too specific — it reads like a real number. It is now
`11 digits, starting 09`.

---

## 6. The clearances restructure — payment moved to the end

This is the most recent structural change and the most likely source of regressions.

### 6.1 What changed and why

Originally the six clearances came **after** payment. The user's instruction:

> "the payment should be the very last. the submission of everything should now be the
> before payment."

So now: choose the six → submit everything → **one Tax Order of Payment covers the
lot** → pay once, at the end.

Specs:
- `docs/clearances-before-payment.md` — **current**, 140 lines
- `docs/clearances-after-payment.md` — **SUPERSEDED**, carries a header saying so.
  Do not follow it. It is kept because it records why the first attempt was wrong.

Commits: `537f648` (the original after-payment version), then `4fb2d54` and `13b2c5b`
(the reversal).

### 6.2 Market Clearance visibility — reversed twice, get this right

The derivation was **backwards**. The three revenue-code categories that were being used
to decide whether to show the Market card describe market **operators**, not stall
**holders** — so the card was hidden from exactly the people who need it.

The user's instruction:

> "The market clearance is soo unapparent, many people who actually need to submit their
> market might miss it. make it still appear with the other permits, but just say that
> hey u dont need to submit if lalala, and its optional" … "im talking about the ui btw,
> it should be this by default"

Current state in `ClearanceStagePage.tsx`:

```ts
const visibleRows = rows          // was: filtered on marketShown
```

```ts
MARKET:
  'Optional — only if you trade from a stall in a public or private market. Skip it if you do not.',
```

Removed entirely: the reveal control, `marketRevealed` state, `marketDecided`, and the
`marketApplies` prop threaded down from `ApplyWizard`. Also removed the
`marketClearanceApplies` import.

**Do not reintroduce conditional hiding of the Market card.** Show it, label it optional.

### 6.3 Open question behind this

`docs/questions-for-malabon.md` §A9b: `stall_count` is **never populated**, and the
Market sheet's count never reaches the fee engine. Nobody has confirmed whether the
Market Clearance is compulsory for a stall holder, or how the system would know the
premises is a stall. This is unresolved.

---

## 7. Analytics

### 7.1 Screens

`web/src/pages/admin/`:

```
AnalyticsPage.tsx        the shell + Overview tab
AnalyticsTabs.tsx        Overview | Renewal Risk | Lifecycle | Processing Time
RenewalRiskPage.tsx
ProcessingTimePage.tsx
BusinessGrowthPage.tsx
ComputedAt.tsx           renders meta.computed_at — "Computed 11 minutes ago by R 4.6.1"
GenerateReportButton.tsx
AuditLogsPage.tsx        built, routed, permissioned — and only recently linked
OwnersPage.tsx  UsersPage.tsx
```

### 7.2 Endpoints — all verified 200

```
GET  /api/v1/analytics/dashboard              + /report
GET  /api/v1/analytics/renewal-risk           + /report
GET  /api/v1/analytics/processing-time        + /report
GET  /api/v1/analytics/business-growth        + /report
GET  /api/v1/analytics/summary
GET  /api/v1/analytics/export
POST /api/v1/analytics/refresh
```

All ten GETs return 200 as admin. Local response time ~7 ms; through the tunnel
~0.35 s. 40 consecutive tunnel requests: 40× 200, zero non-JSON bodies.

### 7.3 ⚠ Renewal risk must not claim to be a probability

`api/tests/Feature/AnalyticsDefinitionsTest.php` contains a test that **fails the build**
if any renewal-risk definition contains:

> *probability, probable, likelihood, predict, forecast, confidence*

This caught two of my own drafts. `likely` is deliberately excluded from the ban as
ordinary English.

Why: the renewal risk figure is a **transparent additive score**, not a fitted
probability model. Calling it a probability would be a false claim about the statistics.
This is also open question D1 with the adviser (§16) — the adviser noted *"Wala akong
nakikita na forecasting. Puro dashboard lang 'to."* so there is pressure to make it
predictive. **Do not resolve that pressure by relabelling the score.** Either build a
real model or keep the honest label.

### 7.4 `meta.computed_at` is load-bearing

Figures are only as fresh as the last refresh. A tester's brand-new application
legitimately will not appear until the next one. The timestamp on screen is what stops
that reading as a bug. Do not remove it to tidy the UI.

---

## 8. Frontend conventions

### 8.1 The portal split

`ea2765c` split citizens and LGU staff into **two sites with two sessions**.

`web/src/lib/api.ts`:

```ts
export function tokenKeyFor(portal: Portal): string {
  return `biztrack.token.${portal}`
}
```

So the keys are `biztrack.token.public` and `biztrack.token.staff` — **not**
`biztrack.token`. There is legacy-migration code that moves an old `biztrack.token` into
the right slot once and then deletes it.

Staff routes live under `/staff/*`. `portalForPath(pathname)` decides which token to
send. A 401 clears only that portal's token and redirects to that portal's sign-in,
setting a `SESSION_EXPIRED_KEY` in `sessionStorage` so the login page can say why.

⚠ If you write a test or script that sets `localStorage.setItem('biztrack.token', …)`,
it will work only because of the legacy-migration path. Prefer the real keys.

### 8.2 Nav gating

`web/src/lib/nav.ts` filters items by permission:

```ts
(item) => !item.permission || user.permissions.includes(item.permission)
```

So `{ label: 'Analytics', to: '/analytics', permission: 'analytics.view' }` is simply
absent for the six offices. Verified: a sanitary officer navigating directly to
`/analytics` is redirected to `/staff/dashboard`.

### 8.3 Error handling — `toApiError`

`web/src/lib/api.ts`. Three outcomes:

1. **Response with a Laravel envelope** → use `data.message`.
2. **Response with a gateway status (502/503/504) and no `message`** →
   *"BizTrack did not answer that request. This is usually brief — please try again."*
   (added `9f3b489`, see §14.6)
3. **No response at all** → *"We couldn't reach BizTrack. Check your connection and try
   again."*
4. Anything else with a status but no `message` → *"Something went wrong on our end.
   Please try again."*

These strings are diagnostic. **Knowing which one appeared tells you which layer
failed.** Do not collapse them into one generic message.

### 8.4 ⚠ Blob error masking — a recurring bug family

`responseType: 'blob'` applied to **error** bodies too. So a 403's JSON message arrived
as a `Blob`, `data?.message` found `undefined` on it, and every download failure
rendered "Something went wrong on our end."

See the comments at `web/src/lib/resources.ts:130` and
`web/src/pages/applicant/uploads.ts:28`. There is a `rethrowBlobError` helper. **Any new
blob download must go through it.**

`e2e/document-actions.spec.ts:50` asserts the misleading string never appears.

### 8.5 Accessibility — WCAG 2.1 AA is the stated target

- ⚠ **Never `disabled`.** A closed field is `readOnly` + `aria-disabled`. Screen readers
  skip `disabled` entirely, so a disabled field is invisible to them. There is a
  Playwright test asserting this: `apply-wizard.spec.ts:567` — *"no field is closed with
  `disabled`, which screen readers skip"*.
- Every input carries a real accessible name (`apply-wizard.spec.ts:593`).
- Radio semantics on the PSIC picker (§5.2).
- `aria-describedby` for helper text.
- SC 1.4.13 for hover/focus content.

### 8.6 Design rules (from `DESIGN.md` / `PRODUCT.md`)

- Royal blue `#3242ca`; the civic-blue family `#0025cc`; errors `#bd0000`.
- **Red Means Stop** — nothing borrows the error red for a non-error. Analytics bands
  (low/medium/high) use neutral/amber/purple tints, never red, because a busy block is
  not an error.
- **Never Color Alone** — the band word is always rendered as text next to the tint, so
  the scale survives with colour off.
- Personality: approachable, modern, helpful. **Never** a dated PH gov portal, a generic
  SaaS dashboard, a playful consumer app, or sterile enterprise UI.
- `BizTrack Prototype Linked.pdf` is a **flow reference only**. Keep its screen flows and
  palette; do **not** reproduce its visual execution.

### 8.7 Copy rules learned the hard way

The user's repeated complaint: *"theres an absurd amount of text here"*, *"way too
confusing"*, *"remove descriptions that sound AI"*.

Commit `3743ca5` — "say it once and stop — cut the explanatory padding".

Concrete rules that emerged:

- **Say it once.** Stacked restatement is what reads as machine-written. If the row above
  already gives the radius, the row below must not repeat it.
- **Labels are noun phrases with no full stop** when they are the left column of a table.
- **Name both numbers.** "15 of 48" said nothing — fifteen of forty-eight *what*. It is
  now `${count} of the ${of_total} businesses near this pin`.
- **A dash, never a zero**, when a figure genuinely has no value (`Unavailable` component
  in `LocationInsightsPanel.tsx`).
- Do not write "not requested" or similar jargon the user has to decode.

---

## 9. The permit certificate

`web/src/pages/applicant/PermitDetailPage.tsx` and
`api/resources/views/pdf/permit.blade.php` render **the same data** (`certificateData`
in `PermitController`), so what is on screen is what downloads.

### 9.1 ⚠ No vendor logo on a government certificate

`b39357a` — the BizTrack logo used to sit in the header. It has no business on a permit:
the document is issued by the city, not by the software that printed it. A vendor mark
on a government certificate makes a real one look fake and a fake one look plausible.

The **City of Malabon seal** is there now (`/malabon-seal.png`, supplied by the user
from Wikimedia). It is `alt=""` + `aria-hidden="true"` deliberately — the two lines under
it already say *Republic of the Philippines* / *City of Malabon*, so announcing "Seal of
Malabon" would make a screen-reader user hear the same fact twice.

The PDF guards on `file_exists(public_path('malabon-seal.png'))`.

### 9.2 ⚠ Signatories are admin-edited data, never compiled in

Role captions with no name are used when the issuing office has no signatories
configured. **A name written in code would be a forgery that keeps printing after the
officeholder has moved on.**

Current fallback: `City Mayor` and `Officer-in-Charge`, both with blank name lines.

⚠ **`OfficeSignatoryController` exists but is wired to no route.** `office_signatories`
has 2 rows, both CENRO. So there is no way for an admin to edit signatories today. This
is open work (§15).

### 9.3 The owner name comes off the permit, not the session

It used to be the signed-in user's name — so a BPLO reviewer opening any permit saw
*their own name* printed as its holder. It is `cert.owner_name` now.

### 9.4 Fields wrap, never truncate

A business with three lines of business had the third and part of the second replaced by
an ellipsis — on the document that says what the permit covers. Taller box, shorter
truth. Keep `break-words`.

---

## 10. Testing

### 10.1 ⚠ HARD RULE — `tsc --noEmit` is a no-op here

`web/tsconfig.json` is `{ "files": [], "references": [...] }`. With `files: []` and no
`include`, `tsc --noEmit` type-checks **zero files** and exits 0. Every "typecheck
clean" claim made with `--noEmit` in this repo was vacuous.

**Always use:**

```bash
cd web && npx tsc -b --force
```

`npm run typecheck` → `tsc -b` (correct). `npm run build` → `tsc -b && vite build`
(correct).

### 10.2 Pest

```bash
cd api && php artisan test --compact
```

593 tests / 4,887 assertions. Note the suite prints an informational line about
`processing_time.departments.*` being "Unverified against an empty panel" — that is not
a failure.

### 10.3 Playwright

```bash
cd web && npx playwright test              # 69 tests, ~1 min
cd web && npm run e2e:stack                # raise the isolated :5199 stack
```

Specs:

```
analytics.spec.ts        apply-wizard.spec.ts     auth.setup.ts
auth.spec.ts             clearances.spec.ts       document-actions.spec.ts
helpers.ts               renewal-modal.spec.ts    requests.spec.ts
track-search.spec.ts
```

`auth.setup.ts` uses the **setup-project pattern** to produce a `storageState` once, so
the suite does not log in 69 times and trip the login rate limiter.

⚠ Known flake: `auth.spec.ts` — *"signing out of one portal leaves the other signed in"*
fails in a full run but passes in isolation. Order-dependent pollution. `f0b0084` fixed
one instance of this (the test was revoking the shared admin session) but the flake is
not fully gone.

### 10.4 Writing tests — two lessons

1. ⚠ **`UploadedFile::fake()->create()` writes an empty file.** A test using it to check
   download bytes asserts nothing — every assertion compares zero to zero. Checklist item
   55 "passed" for weeks this way. Use `->create(name, kilobytes)` with real content, or
   write bytes yourself.
2. ⚠ **Do not guess accessible names.** I wrote a locator for
   `apply for the market clearance`; the actual accessible name is just `Apply`. Query the
   DOM, don't imagine it.

⚠ There are **six identical "Apply" buttons** on the clearance grid with no distinguishing
accessible name. This is both an a11y defect and a test-fragility source. Open work (§15).

### 10.5 There is no unit-test runner for `web/`

No vitest, no jest. `package.json` scripts are `dev / build / typecheck / lint /
preview / e2e / e2e:ui / e2e:stack`. Lint is `oxlint`.

To prove a pure-function change in `web/src/lib`, drive it through Playwright with
`page.route(...).fulfill(...)` — that is how §14.6 was verified.

---

## 11. Operations

### 11.1 Local development

⚠ **`./dev.sh` runs `migrate:fresh --seed`.** It will wipe tester data. Only use it when
you are certain the database is disposable.

Safe manual start:

```bash
cd api && php artisan serve --host=127.0.0.1 --port=8080
cd web && npm run dev -- --port 5173
```

`web/vite.config.ts` supports `VITE_API_TARGET` to point the proxy elsewhere, and has a
`preview.proxy` + `allowedHosts` block so `vite preview` works behind the tunnel.

It also has a `stripLocalDevScripts()` plugin that removes a `localhost:8400/live.js`
block at build time — a local design-review tool that must never reach a tester build
(`c0aa460`).

### 11.2 The tester demo

```bash
./scripts/demo-up.sh      # build demo worktree, start API :8082, preview :5180, tunnel
./scripts/demo-down.sh
```

What `demo-up.sh` does, in order:

1. Requires `../biztrack-demo` to exist (`git worktree add ../biztrack-demo demo`).
2. `npm run build` in the demo worktree — **a built bundle, not the dev server**.
3. Starts Laravel on :8082 with `APP_DEBUG=false` and
   `DB_DATABASE=$MAIN/api/database/database.sqlite` (⚠ the shared DB, §1.4).
4. Starts `vite preview` on :5180 with `VITE_API_TARGET=http://localhost:8082`.
5. **Refuses to continue if plumber is bound to `0.0.0.0`** (§2.3).
6. Starts `cloudflared tunnel --url http://localhost:5180` and greps the URL out of the
   log.

Logs land in `${TMPDIR}/biztrack-demo/`.

### 11.3 Publishing an update to testers

```bash
git -C ../biztrack-demo merge --ff-only main   # or cherry-pick
./scripts/demo-down.sh && ./scripts/demo-up.sh
```

⚠ This drops every open tester session and issues a **new tunnel URL**.

### 11.4 ⚠ `vite preview` binds to `[::1]` only

`cloudflared` dials IPv4. Symptom: the tunnel 502s while the origin serves 200 locally.
Fix: `--host 127.0.0.1`. This cost an hour once.

### 11.5 Production runbook

`docs/runbook-deploy.md` covers the MISD box / defense laptop:
docker-compose, Postgres, `APP_ENV=production`, `APP_DEBUG=false`, `QUEUE_CONNECTION=database`,
`MAIL_MAILER=log`, `SMS_DRIVER=log`, `PAYMENT_DRIVER=simulated`, a restore drill, and
PHP upload limits. Jitsi is deferred.

---

## 12. Documentation index

`docs/`:

| File | What it is |
|---|---|
| `HANDOFF.md` | this file |
| `questions-for-malabon.md` | **935 lines**, 55+ questions in 5 sections — the handover list for City Hall |
| `misd-questions.md` | 4-line pointer to the above (was superseded) |
| `clearances-before-payment.md` | **current** clearance/payment order spec |
| `clearances-after-payment.md` | **SUPERSEDED**, kept for the record |
| `api-contract.md` | endpoint contract |
| `r-integration-spec.md` | the R boundary, Location Insights §5 |
| `r-integration-revisions.md` | revisions to the above |
| `rehaul-spec.md` | the intake restructure |
| `testing-checklist-aug2.md` | the August 2 tester checklist |
| `testing-checklist-status.md` | status against it |
| `checklist-round2-plan.md` | round-two plan |
| `db-solidification.md`, `schema-deltas.md` | schema work |
| `revenue-code-extract.md` | extracted figures (source PDF is gitignored) |
| `runbook-deploy.md` | production deployment |
| `demo-tunnel.md` | the tester tunnel, and why it is a pinned worktree |
| `traceability.md` | requirement traceability |
| `bruno/` | API client collection |

Root also holds `PRODUCT.md` (strategy, users, WCAG target) and `DESIGN.md` (visual
system, still a seed), plus `CLAUDE.md` (project instructions — **read it**).

`tools/annotate-r-integration.py` renders the adviser's **33 review notes** onto
`R INTEGRATION DRAFTS.pdf` as margin annotations. The notes are the valuable part; they
carry the adviser's wording verbatim.

### 12.1 graphify

⚠ `CLAUDE.md` mandates this and a hook enforces it:

```bash
graphify query "<question>"      # run this BEFORE grepping
graphify path "<A>" "<B>"
graphify explain "<concept>"
graphify update .                # after modifying code — AST only, no API cost
```

`graphify-out/wiki/index.md` for broad navigation; `graphify-out/GRAPH_REPORT.md` only
for architecture review. In practice `graphify query` returns a large BFS subgraph and is
best for *orientation*; you will still need targeted reads afterwards.

---

## 13. Recent commit history, annotated

Newest first. This is the narrative of the last stretch of work.

| Commit | What it did |
|---|---|
| `3502a2b` | Track `tools/annotate-r-integration.py`; ignore root `test-results/` |
| `9f3b489` | **Gateway errors stop blaming the API** (§14.6) |
| `b2d0942` | PSIC single-select; Malabon seal on the permit; Market card visible + optional; removed the zoning disclaimer; "N of M businesses near this pin" |
| `059e4a7` | Renewal: offer **every** business, not just the first page |
| `26b5e96` | Renewal: ask **which permit** before renewing anything |
| `3743ca5` | Cut explanatory padding — "say it once and stop" |
| `a525c05` | A placeholder shows the shape of an answer, never an answer |
| `b39357a` | Take our logo off a government certificate; drop an unasked-for field |
| `80b4421` | Drop `zz-mkt2.spec.ts`, committed by accident (§14.5) |
| `816a1b6` | Office separability, full trade list, TIN boxes, two missing forms |
| `fa3f107` | Match intake to the city's paper forms without asking twice |
| `97eb5ab` | Ask for capital **once**; say what the category actually is |
| `4e3e4a7` | Show an admin **where** a filing is, not just what it is |
| `be6f872` | Item 103 — make shapes and edges line up |
| `08cb9ab` | Stop `OfficerRequest` claiming three fields are never null |
| `f0b0084` | Stop the portal sign-out test revoking the shared admin session |
| `ea2765c` | **Split citizen and LGU portals** into two sites, two sessions |
| `d2f38ab` | Checklist items 98–102 from the tester round |
| `4fb2d54` | **Choose the six before submitting, pay once at the end** |
| `13b2c5b` | Docs: payment moves to the end |
| `59fdfa2` | Let an applicant open their own documents; ask the registrar before the number |
| `bf4b182` | Group approved permits by business; stop shipping two Profiles |
| `5af0650` | Stop the permit map overstating how much of the city has lapsed |
| `5de99b8` | Permit map readable — green valid, red lapsed |
| `88a95c8` | Layer the trade dropdown above the map; name the filing |
| `76aa6bb` | Trade picker back to a dropdown; drop "Other" |
| `537f648` | (superseded) clearances as a stage after payment |
| `79998bf` | **Let a clearance balance actually be paid** (§14.4) |
| `1c22456` | Chatbot quotes the RA 11032 limits the system enforces |
| `0ad4b8f` | Consolidate every open LGU question into one list |
| `91ce2e5` | One command to raise the tester demo, plus the runbook |
| `e9998f9` | Serve the tunnel from a **built bundle**, not the working tree |
| `c0aa460` | Keep the local design-review script out of tester builds |
| `b227a4f` | Docs: the zoning section — "the worst thing to have omitted" |
| `a63b1d1` | Ask what an amendment amends, and which permit a renewal renews |
| `f5f4428` | Stop asking the same thing twice; make the LGU step a decision |
| `d48e580` | Complete Edit Profile; put issued permits on Profile |
| `f766bc4` | Search both Track pages; make Sort/Filter real |
| `174781f` | **Stop the request composer crashing on a removed business** (§14.2) |
| `53eba12` | Send `grace_days` to R so both engines share one cut-off |
| `4d53f3d` | Make the closed Gross Sales field `readOnly`, not `disabled` |
| `d223167` | **Ask for data privacy consent before collecting any of it** |

---

## 14. Traps — incidents, with what actually went wrong

Every entry here is a real mistake that cost time. They are the highest-value part of
this document.

### 14.1 `tsc --noEmit` checks nothing

Covered in §10.1. Root `tsconfig.json` is `files: []` + project references. Use
`tsc -b --force`. **Every claim of "typecheck clean" made with `--noEmit` was false.**

### 14.2 Soft-deleted rows leak `null` into non-nullable types

`RequestsPage.tsx:396` — `Cannot read properties of null (reading 'name')`.

**139 applications** pointed at soft-deleted businesses. `ApplicationListItem.business`
was typed non-null, so the compiler could not help.

The fix that worked:

1. Make the type **honest**: `business: Business | null`.
2. The compiler then found **two more sites** that had the same latent bug.
3. Add `businessName(business: {name: string} | null): string` to
   `web/src/lib/format.ts`, returning `'Business removed from register'`.
4. Write a regression test and **prove it fails against the buggy line** before fixing.

Generalisation: `Business` and `User` are soft-deleted. **Any payload embedding either
one should be typed nullable.** `08cb9ab` did the same for `OfficerRequest`.

### 14.3 Greedy regex on a large file

I ran a regex over `ClearanceStagePage.tsx` and it silently removed
`ClearanceStageProps`, `feeAmount`, and `marketClearanceApplies`.

Recovery: `git checkout -- <file>`, then redo as **surgical single-line edits**.

⚠ On `ApplyWizard.tsx` (~4,000 lines) and `ClearanceStagePage.tsx`: use `Edit` with
unique anchors. Do not use `sed`, do not use multiline regex.

A related one: I inserted the seal `<img>` and left a **duplicated `<p>` opening tag** →
`TS17008`. `tsc -b` caught it. This is why §10.1 matters.

### 14.4 The payment dead-end

`PaymentController::pay` refused every status except `pending_payment`. So a clearance
balance could be **visible, owed, and blocking release — and unpayable**.

Fix (`79998bf`): accept any non-terminal filing that owes something, and charge
`balance_due`, not the total.

### 14.5 Scratch files getting committed

`zz-mkt2.spec.ts` was committed by accident. It hard-coded a
`/private/tmp/.../scratchpad` path and read `biztrack.token.public` — a portal-split key
that did not exist on main at the time. Deleted in `80b4421`.

⚠ Write scratch scripts to the **scratchpad directory**, and delete them in the same
command that runs them (`node ./x.mjs; rm -f ./x.mjs`). Note that scripts needing
`@playwright/test` must run from `web/` to resolve the import.

### 14.6 The Analytics Dashboard "failure" that was not analytics

**Symptom:** user screenshot of the Analytics Dashboard showing *"We couldn't load this —
Something went wrong on our end."*

**What I checked, all green:**

| Check | Result |
|---|---|
| All 10 analytics endpoints as admin | 200 |
| Response time | 7 ms local, 0.35 s tunnel |
| 40 consecutive tunnel requests | 40× 200, no non-JSON bodies |
| `/analytics` in a real browser (admin, bplo) | renders fully, 0 failed requests, 0 console errors |
| Six office roles | correctly 403'd, correctly bounced, no nav link |
| Stale token from before a redeploy | correctly redirected to sign-in |
| R service down | irrelevant — analytics is batch (§3.2) |
| No snapshot at all | PHP fallback computes and labels itself |

**What cracked it:** the exact wording. A network failure says *"We couldn't reach
BizTrack."* The screenshot said *"Something went wrong on our end."* That string appears
in exactly one place — when a response **has an HTTP status but its body has no
`message`**. The API answers every error with the Laravel envelope, so a missing
`message` means **the body was never ours**. It was `cloudflared` returning an HTML
502/503/504 while I restarted the demo backend under the user's open session.

**Fix (`9f3b489`):** gateway statuses with a non-envelope body now say so. A 5xx that
*does* carry an envelope is untouched — that one really is our end, and Laravel's own
message beats anything guessed.

Verified end-to-end with `page.route(...).fulfill({status: 502, contentType: 'text/html'})`
against :5199 → `gateway copy shown: true`, `old misleading copy: false`.

**The lesson worth keeping:** *the error string told me which layer failed.* Keep the
three messages distinct (§8.3).

### 14.7 Judging subagent liveness

I twice assumed "task still running" meant "agent still working". It does not.

**The reliable test is file mtimes:**

```bash
find web/src api/app -newermt '-30 minutes'
```

### 14.8 I accused an agent of hallucinating, and it was not

An agent reported work on items 98–103 that I had never assigned it. I called it
hallucinating. **The user had been directing it separately.** I then `git reset --hard`ed
main out from under it, destroying real work.

⚠ Before concluding a subagent is confused, consider that the user may have talked to it
directly. And never hard-reset a shared working tree while agents are live.

### 14.9 Wedged MCP browser

`ERR_NAME_NOT_RESOLVED` on the tunnel, while `curl` with the right `Host` header got 200.
The MCP browser could not reach `127.0.0.1:5180` either — it was **wedged**, not a
network fault. Confirmed by driving an independent Playwright script, which worked.

⚠ If the MCP browser fails on *everything including localhost*, suspect the browser, not
the app. Fall back to a standalone Playwright script (run it from `web/`).

### 14.10 Shadowing globals in scratch scripts

```js
const URL = process.argv[2]     // shadows the global URL constructor
new URL(page.url())             // TypeError, and the message points at the wrong line
```

Trivial, but it cost a debugging round. Name script variables `BASE`, not `URL`.

---

## 15. Open work

### 15.1 Blocked on the user

| Item | Detail |
|---|---|
| **`FRONTEND_URL`** | `api/.env` still says `localhost:5173`. Every printed permit carries an unreachable verify link and an unreachable QR code. The file is permission-blocked — **the user must change this line**. Set it to the current tunnel URL before any session that involves printing permits. |

### 15.2 Known defects

| Item | Detail |
|---|---|
| **Six identical "Apply" buttons** | The clearance grid renders six buttons whose accessible name is just `Apply`. Needs per-clearance names (`Apply for the Sanitary Permit`, …). Both an a11y defect and a test-fragility source. |
| **`auth.spec.ts` flake** | *"signing out of one portal leaves the other signed in"* fails in a full run, passes in isolation. Order-dependent pollution; `f0b0084` fixed one cause but not all. |
| **`OfficeSignatoryController` is unrouted** | Controller exists, no route points at it. `office_signatories` has 2 rows, both CENRO. So signatories cannot be edited by an admin today — and §9.2 says they must never be hard-coded. This is a real gap. |
| **`stall_count` never populated** | The Market sheet collects a stall count that never reaches the fee engine. Tied to open question A9b. |

### 15.3 Not yet deployed

`main` is **two commits ahead** of `demo`:

- `9f3b489` — gateway error copy
- `3502a2b` — tools + gitignore

Deploying means restarting the demo backend, which drops every open tester session and
issues a new tunnel URL. Neither commit is urgent. **Ask before redeploying.**

---

## 16. Open questions for Malabon City Hall

`docs/questions-for-malabon.md` — 935 lines, the single consolidated handover list. The
user asked explicitly for *"EVERY SINGLE UNSURE QUESTION, put in a single md"*.

**Sections:**

- **A. BPLO — process and policy** (A1–A22)
- **B. MISD — systems, data, hosting, accounts** (B1–B14)
- **C. CPDO — zoning** (C1–C10)
- **D. Adviser and panel — academic and reporting** (D1–D5)
- **E. Documents we need copies of** (E1–E9)

**The ones that block code:**

| # | Question | Why it blocks |
|---|---|---|
| A1 | Do applicants *choose* which clearances they need, or does BPLO decide? | The whole clearance stage is built on "applicant chooses" |
| A2 | When an office refuses, what happens to the whole application? | No defined behaviour today |
| **A3** | Is the Fire Code fee owed by every business annually, or only when BFP issues the certificate? | `FeeCalculator` currently gates it on the FSIC clearance being requested. If it is annual, that is **wrong**. |
| **A4** | Is the sanitary inspection fee an issuance fee or an annual one? | Same gating problem |
| A5 | What does BPLO charge when someone already holds a valid clearance? | |
| A6 | Are clearance fees collected at the BPLO cashier, or per office? | Determines whether one Tax Order of Payment is right |
| A7b | May an applicant add a clearance after a return-for-revision? | |
| **A9b** | Is Market Clearance compulsory for a stall holder, and how would the system know the premises is a stall? | `stall_count` is never populated (§15.2) |
| A9c | What does the City Market Administrator actually charge — is it a permit fee at all? | |
| A10 | Which transactions are simple / complex / highly technical under RA 11032? | Drives the 3/7/20-day tiers |
| A11 | The city's official list of non-working days | Working-day arithmetic is wrong without it |
| A13 | Does "Others" really exist on the line-of-business list? | `76aa6bb` dropped it |
| A22 | Is there a Semi-Annual mode of payment? | |
| B3 | Should an office see applications that are not its own? | We assumed **no** and built it that way (§4.3) |
| B6 | Who is the current signatory for each office, and who may change them? | Blocks §15.2's signatory gap |
| B14 | Payments are simulated — what is the real path? | |
| C1 | Should the system give a zoning verdict at all, or only record the location? | We currently show insights and defer the verdict to CPDO |
| C9 | **ANSWERED** — the zoning clearance card, name, and form | |
| C10 | Is ₱735 the current zoning/locational clearance charge? | |
| **D1** | Renewal risk: may it stay a transparent score, or must it be a probability? | §7.3 — do not resolve by relabelling |
| E4 | **ANSWERED** — the CPDD zoning clearance form | |

Two are already answered (C9, E4). The rest are live.

---

## 17. Conventions for working in this repo

### 17.1 Commit messages

The house style is distinctive and the user has not objected to it. Subject line is
lowercase, `type(scope): what changed, in plain words`. The body explains **why the old
behaviour was wrong**, not what the diff does.

Examples that set the tone:

- `fix(errors): stop blaming the API for a gateway's error page`
- `fix(apply): ask for capital once, and say what the category actually is`
- `fix(permit): take our logo off a government certificate, and drop a field nobody asked for`
- `fix(ui): a placeholder shows the shape of an answer, never an answer`

Every commit ends with:

```
Claude-Session: https://claude.ai/code/session_<id>
```

### 17.2 Code comments

The codebase carries **heavy explanatory comments** — unusually so — and this is
deliberate and wanted. They explain *why*, record what was tried and rejected, and name
the conditions under which a decision should be revisited.

Example from `LocationInsightsPanel.tsx`, after removing a disclaimer:

> *"If the CPDO line ever leaves that modal, this sentence has to come back — the screen
> would then be asserting conformity with nothing anywhere saying who decides it."*

**Match this density.** When you delete something, leave a comment saying what was
deleted and what would make it necessary again.

### 17.3 After changing code

```bash
graphify update .
```

AST-only, no API cost. `CLAUDE.md` requires it.

### 17.4 Working autonomously

The user has repeatedly asked for uninterrupted execution:

> *"Genuinely why tf are you stopping"* … *"EVERYTHING FINISH ALL TASKS"* … *"do all"*

The GSD context-monitor hook was removed for exactly this reason — it was injecting
"CONTEXT CRITICAL, ask the user how to proceed", which contradicts auto-compaction and
stalled the work.

**Do not stop to ask permission for in-scope work.** Do ask before outward-facing or
hard-to-reverse actions (§2.6).

The user also asks for parallelism on bug work:

> *"from now on it will all be bug fixes, and i want these to work in parallel. maybe
> create new agent per each bug"*

When you do this, brief every agent on §2.4 (use :5199) and §10.1 (`tsc -b`).

### 17.5 Reporting

Report outcomes faithfully. If tests fail, say so with the output. If you could not
reproduce something, say that rather than inventing a cause. If a claim you made earlier
turns out to be wrong, correct it explicitly — that has happened several times here and
it was always better than letting it stand.

---

## 18. Glossary

| Term | Meaning |
|---|---|
| **BPLO** | Business Permits and Licensing Office — the lead office |
| **MISD** | Management Information Systems Department — city IT |
| **CPDO / CPDD** | City Planning and Development Office — zoning authority |
| **CHO** | City Health Office — sanitary permits |
| **BFP** | Bureau of Fire Protection — FSIC |
| **OBO** | Office of the Building Official — occupancy |
| **CENRO** | City Environment and Natural Resources Office — CEC |
| **LGU** | Local Government Unit |
| **PSIC** | Philippine Standard Industrial Classification — line of business |
| **FSIC** | Fire Safety Inspection Certificate |
| **CEC** | City Environmental Certificate |
| **TOP** | Tax Order of Payment |
| **PIL** | Presumptive Income Level |
| **RA 11032** | Ease of Doing Business / Anti-Red Tape Act — the 3/7/20-day tiers |
| **RA 9514** | Fire Code — the Fire Code fee |
| **RA 10173** | Data Privacy Act — the consent gate |
| **eBPLS** | Electronic Business Permit and Licensing System — the national/BLGF system |
| **SPC** | Statistical Process Control — `Support/Spc.php` |
| **DES** | Double Exponential Smoothing — `Support/Des.php` |

---

## 19. First moves in a new session

1. **Read `CLAUDE.md`.** It mandates graphify and points at `PRODUCT.md` / `DESIGN.md`.
2. `git status` and `git log --oneline -5` — confirm you are on `main` and clean.
3. Check what is running: `lsof -nP -iTCP -sTCP:LISTEN`.
4. If the user reports a bug, **reproduce it before theorising**. §14.6 is the case study:
   eight hypotheses were wrong and the answer was in the error string.
5. Before claiming anything type-checks: `cd web && npx tsc -b --force`.
6. Before claiming anything passes: `cd api && php artisan test --compact` and
   `cd web && npx playwright test`.
7. After changing code: `graphify update .`.

### 19.1 If asked "what's left?"

Answer from §15 and §16:

- **Blocked on the user:** `FRONTEND_URL` in `api/.env`.
- **Real defects:** six nameless "Apply" buttons; the `auth.spec.ts` order flake; the
  unrouted `OfficeSignatoryController`; `stall_count` never populated.
- **Undeployed:** two commits (`9f3b489`, `3502a2b`) — ask before redeploying.
- **Blocked on City Hall:** the A3/A4 fee-gating question is the one most likely to make
  the fee engine wrong; A9b is the one most likely to make Market Clearance wrong.

### 19.2 What is genuinely finished

So you do not redo it:

- Data-privacy consent gate, first thing in the wizard.
- Office separability — verified live, sanitary 38 / bplo 125.
- Portal split, two sessions, two token keys.
- Clearances before payment; one Tax Order of Payment; pay last.
- PSIC single-select with radio semantics.
- Market Clearance visible by default and labelled optional.
- City seal on the permit, vendor logo removed, owner name off the permit.
- RA 11032 tiers quoted from `Ra11032::TIERS` in the chatbot.
- Analytics definitions — 27 of them, with the probability-wording ban enforced by test.
- Gateway vs server error messages now distinguishable.
- 593 Pest tests, 69 Playwright tests, `tsc -b` clean.

---

*End of handoff. If something in here contradicts the code, the code is right and this
file is stale — fix the file.*
