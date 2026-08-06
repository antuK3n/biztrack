# 01 — Inspection / approval scoping

Investigation of the inspection-and-approval cluster, 2026-08-06. Read-only: no source was
changed, no database was written, no state transition was performed on live data. Everything
below is either observed in the register, proved by running the existing test suite, or reasoned
from code with the reasoning shown. The dev server on `127.0.0.1:5199` was **not running** during
this investigation (`curl` → connection refused), so no browser observation was possible; every
UI claim is read off the source and is marked as such.

---

## 0. What the evidence actually says

`BIZ-2026-00958` = application **5003**, business "ABCD Manufacturing" (id 2290). The client's
"ABCD Trading" is this filing — it is the only `ABCD%` business in the register.

```
applications.status = for_inspection
permit types        = BUSINESS, SANITARY, FSIC, OCCUPANCY, CEC, MARKET, ZONING  (all seven)
assignments         = BPLO completed (09:27:56) · BFP completed (09:30:39)
                      CHO pending · OBO pending · CENRO pending · CMO-MARKET pending · CPDO pending
inspections         = exactly one row: id 10163, department BFP, inspector 4 (fire@),
                      status completed, result passed, conducted 09:30:58
```

`permit_types.requires_inspection` is `1` for all six clearances and `0` for `BUSINESS` (BPLO).
Confirmed by query.

Staff accounts and their roles, confirmed by query — **none of the office "admin" accounts holds
the role `admin`**:

| account | department | role | has `application.review` | has `inspection.manage` |
|---|---|---|---|---|
| `bplo@` | BPLO (1) | `bplo_staff` | yes | **no** |
| `sanitary@` | CHO (2) | `sanitary_officer` | yes | yes |
| `fire@` | BFP (3) | `fire_inspector` | yes | yes |
| `zoning@` | CPDO (4) | `zoning_officer` | yes | yes |
| `obo@` | OBO (5) | `obo_staff` | yes | yes |
| `cenro@` | CENRO (6) | `cenro_officer` | yes | yes |
| `market@` | CMO-MARKET (7) | `market_admin` | yes | yes |
| `admin@` | *(none)* | `admin` | **no** | **no** |

So when the client says "Sanitary Office's admin account", that is `sanitary@`, a departmental
officer — not the super admin. This matters for every authorisation question below.

**Answers to the three questions the reports pose, up front:**

- The API does *not* rely on the UI hiding buttons. `InspectionController::authorizeDepartment`
  (`api/app/Http/Controllers/Api/InspectionController.php:203-212`) independently 403s a conduct,
  reschedule, reinspect or show from another department. Sanitary could not have conducted Fire's
  visit 10163. **This is not a security finding.** See INS-6 for the one latent hole.
- `WorkflowService::recordInspection` (`api/app/Services/WorkflowService.php:491-543`) writes to
  exactly one `Inspection` row — the one passed in. One call cannot mark two offices' visits done.
  Report 2 is **not** a cross-office write; it is the single shared filing status plus a single
  shared panel (INS-4).
- An office **can** complete its review from `for_inspection` through the API — there is no status
  guard anywhere on that path — but it **cannot reach the control in the UI**. That is INS-1, and
  it is the most serious finding here.

---

## INS-1 — An office whose review is still pending on a `for_inspection` filing has no way to approve it. The filing is permanently deadlocked.

**Maps to:** client report 3 ("Market Office … cannot approve … but it is listed as For inspection
in the business owner side"). Also the mechanism behind reports 1 and 4.

**Severity: CRITICAL. This is a workflow / data-integrity defect, not UX.** A filing that enters
this state can never be issued by any action available in the product. `BIZ-2026-00958` is in it
now.

### Root cause

`web/src/pages/officer/ReviewPage.tsx:689`

```tsx
if (app.status === 'for_inspection') {
  return ( … <InspectionDecisionPanel … /> … )   // early return; the whole review sheet is gone
}
```

The branch keys off the **application's** status and nothing else. Its own docblock states the
premise it depends on, at `ReviewPage.tsx:652-654`:

> *"A filing only REACHES `for_inspection` because every review assignment completed
> (WorkflowService::afterReviewProgress), so `completed_at` is always set by then and `decided`
> above is always true."*

Commit `5da4daa` (today) made that premise false. `WorkflowService::afterReviewProgress`
(`api/app/Services/WorkflowService.php:239-297`) now books the approving office's visit and
transitions the filing to `for_inspection` on the **first** office's approval:

- `WorkflowService.php:241` — `$visit = $this->scheduleInspectionFor($app, $approved->department_id);`
- `WorkflowService.php:296` — `$this->transition($app, ApplicationStatus::ForInspection, $note);`

The old `$allDone` gate that guaranteed the premise was deleted in the same commit. `ReviewPage`
was not updated, so it now hides the review form from offices that have not reviewed anything.

The API side is wide open — there is no status check at all:

- `AssignmentController::approve` (`api/app/Http/Controllers/Api/AssignmentController.php:291-302`)
  checks only `authorizeDepartment`.
- `WorkflowService::approveAssignment` (`api/app/Services/WorkflowService.php:177-186`) checks
  nothing.

So the block is **purely client-side**, which is why every backend test passes (see "Tests" below).

### Reproduction

1. Sign in as `sanitary@` (or `market@`, `obo@`, `cenro@`, `zoning@`), password `biztrack1`.
2. Open the officer queue → **For Inspection** tab → search `abcd` → open `BIZ-2026-00958`.
3. The screen renders "Application Status", one card ("Bureau of Fire Protection", green bar,
   **Approved**), the progress rail and Messages.

**Observed:** no Approve, no Return with remarks, no office form, no fee field. The office's own
review — genuinely `pending` in `application_assignments` — has no control anywhere in the product.
`InspectionDecisionPanel` also offers this office nothing, because `canAct` correctly refuses
another department's visit (`web/src/components/InspectionDecision.tsx:624-629`).

**Expected:** the office's outstanding *review* is what is outstanding for that office; it should
be able to approve or return it, which is what would book its own visit
(`WorkflowService::scheduleInspectionFor`, `WorkflowService.php:355-386`).

**Consequence chain:** CHO/OBO/CENRO/CMO-MARKET/CPDO can never complete their reviews →
`scheduleInspectionFor` is never called for them → `isFullyCleared`
(`WorkflowService.php:317-339`) can never return true, because its first half requires *every*
assignment `completed` → `approveAndIssue` is never reached from either guard → **the seven permits
on this filing can never be issued**. The applicant sits on "For Inspection" forever.

Current exposure: **1 filing** (`5003`) — `for_inspection` with at least one non-completed
assignment. That number is 1 only because the parallel-booking behaviour shipped hours ago. Every
future multi-office filing reaches this state as soon as its first inspecting office approves.

### Blast radius

- `ReviewPage.tsx:689` is the single entry to the For Inspection screen; changing its condition
  changes what every office sees on every `for_inspection` filing.
- The e2e spec `web/e2e/inspection-review.spec.ts:173` **asserts the current behaviour**
  (`expect(page.getByText(ADMIN_REVIEW_SHEET)).toHaveCount(0)` and `expect(page.locator('details'))
  .toHaveCount(0)`) unconditionally, without regard to whether the reading office's assignment is
  pending. Any fix that restores the form for a pending office breaks that test as written.
- The client explicitly rejected showing the form here, twice, by name (quoted at
  `ReviewPage.tsx:658-663`: *"why is the entire application form showing"*, *"I can still see the
  application details. Please remove this."*). A fix must not simply revert that.

### Constraint any fix must satisfy

The screen must branch on **this office's assignment state**, not on the filing's status. An office
whose assignment is `pending`/`in_progress`/`returned` needs its review controls; an office whose
assignment is `completed` must keep the compact inspection box the client asked for. Whatever gate
is chosen must be the same one the queue tabs use (INS-2), or the two screens will disagree about
which state a filing is in for a given office.

---

## INS-2 — The queue tabs partition by filing status, so an office's outstanding *review* on a `for_inspection` filing is missing from For Approval and misfiled under For Inspection.

**Maps to:** client report 4 (empty search) and report 1 (approved but "not listed on the For
inspection"). This **confirms** the flag raised by the agent that did the parallel-inspection fold.

**Severity: HIGH.** Work is invisible in the tab that is supposed to list it. Not a data-integrity
problem on its own, but it is what makes INS-1 undiscoverable.

### Root cause

`web/src/pages/officer/QueuePage.tsx:82-90`

```ts
const APPROVAL_STATUSES   = ['under_review', 'returned'] as const
const INSPECTION_STATUSES = ['for_inspection', 'approved', 'issued'] as const
const TAB_STATUSES = { payment: PAYMENT_STATUSES, approval: APPROVAL_STATUSES, inspection: INSPECTION_STATUSES }
```

`QueuePage.tsx:489`

```ts
const assignmentStatuses = tab === 'approval' ? OPEN_ASSIGNMENT_STATUSES : undefined
```

with `OPEN_ASSIGNMENT_STATUSES = 'pending,in_progress,returned'` (`QueuePage.tsx:118`). Both
filters go to the server: `AssignmentController::index` applies the assignment-status filter at
`AssignmentController.php:76-79` and the application-status filter at `:81-84`.

The two filters were designed against the invariant `5da4daa` removed — that an assignment can only
be pending while the filing is `under_review` or `returned`. The comment at `QueuePage.tsx:98-117`
and the one at `AssignmentController.php:151-162` both state that invariant explicitly.

### Observed, verified against the live register

I replayed both tabs' exact queries for every staff account (read-only, `php artisan tinker`,
SELECT only), searching `abcd`:

```
bplo     approval rows=0      bplo     inspection rows=1
sanitary approval rows=0      sanitary inspection rows=1
fire     approval rows=0      fire     inspection rows=1
obo      approval rows=0      obo      inspection rows=1
cenro    approval rows=0      cenro    inspection rows=1
market   approval rows=0      market   inspection rows=1
zoning   approval rows=0      zoning   inspection rows=1
admin    approval rows=0      admin    inspection rows=7   ← seven rows, one filing (INS-8)
```

So, precisely:

- **An office with a PENDING review on a `for_inspection` filing lands in the For Inspection tab,
  not For Approval.** Its outstanding work is filed under the wrong heading, and clicking it lands
  on the screen that has no controls (INS-1).
- **Search does reach it** — on the For Inspection tab. On For Approval it returns nothing,
  correctly per the filter and wrongly per the officer's intent.

### On report 4's screenshot specifically

I could not reproduce "Nothing matches" on the For Inspection tab with today's code. The `q`
parameter on `/api/v1/assignments` was **added today**, in commit `b1bc7bc`
(`AssignmentController.php:108-113`); before that the browser filtered only the rows it had already
loaded and the screen printed exactly the "Nothing matches" copy (`QueuePage.tsx:649`) whenever the
match sat past the loaded page. The screenshot is almost certainly from before `b1bc7bc`.

**But the report is not stale in substance.** The client's underlying complaint — "I searched for a
filing I know is For Inspection and the queue said no" — now reproduces on the **For Approval** tab
for the five offices that still owe a review, and that is the tab an officer looking for review work
would use. Treat report 4 as live, in the form above.

### Blast radius

- `TAB_STATUSES` also drives the status Filter dropdown (`QueuePage.tsx:602-605`) and the tab totals
  (`QueuePage.tsx:594-597` reading `meta.application_status_counts` from
  `AssignmentController::statusCounts`). Changing the partition changes all three.
- `AssignmentController::index`'s `application_status` filter is also used by
  `web/e2e/inspection-review.spec.ts` and `PendingPaymentQueueTest`.
- `PAYMENT_STATUSES` must not adopt an assignment-status filter — `QueuePage.tsx:113-117` records
  that eight unpaid filings were previously filtered out of that tab for exactly this reason.

### Constraint any fix must satisfy

For Approval must be answerable from the **assignment** alone ("what is waiting on me"), and For
Inspection from **this office's inspection** ("what visit do I owe"), with no filing whose work is
outstanding for this office appearing in neither. Whatever predicate is chosen must be the same one
`ReviewPage.tsx:689` branches on (INS-1).

---

## INS-3 — BPLO's approval books nothing and the filing then disappears from both of BPLO's tabs.

**Maps to:** client report 1, in full.

**Severity: MEDIUM** (UX / discoverability). No data is wrong; the filing is simply unfindable from
the office that just acted on it.

### Root cause

Two facts compose:

1. `permit_types.BUSINESS.requires_inspection = 0` (verified by query; the other six are `1`).
   `scheduleInspectionFor` (`WorkflowService.php:357-364`) returns `null` when the approving office
   does not inspect **on this filing**, so BPLO's approval books no visit. `afterReviewProgress`
   then hits `WorkflowService.php:265` — `if (! $app->inspections()->currentPerDepartment()->exists()) return;` — and the filing stays `under_review`.
2. The filing therefore matches neither tab for BPLO: For Approval excludes it because BPLO's
   assignment is now `completed` (`OPEN_ASSIGNMENT_STATUSES`, `QueuePage.tsx:118`); For Inspection
   excludes it because the filing is still `under_review` (`INSPECTION_STATUSES`,
   `QueuePage.tsx:84`).

**So: yes, `requires_inspection = false` on BUSINESS fully explains why BPLO's approval books
nothing** — but it does *not* on its own explain the client's experience. The disappearance from
both tabs is INS-2's partition, not the flag.

### The client's actual question, answered

> *"Does this mean all offices must approve it first before being For inspection?"*

No — and that is worth telling them plainly, because the opposite is now true. Since `5da4daa`,
**the first** inspecting office to approve moves the whole filing to `for_inspection`. BPLO never
gets an inspection at all: it issues the Mayor's Permit on the strength of the six clearances
(`WorkflowService.php:369-373`). Once some other office approves and the filing flips, BPLO's
completed row *does* reappear — under For Inspection (verified: `bplo inspection rows=1`), where it
opens on a panel of other offices' visits that BPLO cannot act on.

### Blast radius / constraint

Anything that gives BPLO a visit of its own would stall issuance behind a visit nobody performs —
the code says so at `WorkflowService.php:369-373`, and it is correct. The fix belongs in the queue
partition (INS-2), not in `requires_inspection`.

---

## INS-4 — One filing-level status stands in for seven offices, so every office and the applicant are told "For Inspection" the moment any one office books a visit.

**Maps to:** client report 2 ("ABCD Trading's For inspection for other offices too got approved as
well") and the second half of report 3 ("but it is listed as For inspection in the business owner
side").

**Severity: HIGH — correctness of the record shown to the applicant**, not merely cosmetic. The
system asserts to a business owner that its paperwork review is finished when five of seven offices
have not opened the file.

### Root cause

`applications.status` is a single column and the only thing every screen reads.

- `WorkflowService::afterReviewProgress` writes `ForInspection` on the first booking
  (`WorkflowService.php:296`).
- Officer queue rows and tabs read it (`AssignmentController.php:81-84`, `QueuePage.tsx:86-90`).
- `ReviewPage` branches on it (`ReviewPage.tsx:689`).
- The applicant's rail reads it: `web/src/components/ApplicationProgress.tsx:75-80` builds a single
  filing-level rail `under_review → for_inspection → approved`, so `under_review` is drawn as a
  **completed** step as soon as the status flips.
- `InspectionDecisionPanel` is handed **every office's** visits — `ReviewPage.tsx:721`
  (`inspections={app.inspections ?? []}`), loaded by `AssignmentController::show` at
  `AssignmentController.php:245-246` (`application.inspections.department`,
  `application.inspections.inspector`).
- A conducted, passed visit renders a green bar labelled literally **"Approved"** —
  `web/src/components/InspectionDecision.tsx:239-244` (`STATE.passed = { bar: 'bg-s-green', label:
  'Approved' }`) — under the office's name (`InspectionDecision.tsx:234-237`).

### Why this produces report 2 exactly

There is **one** inspection row on filing 5003 and it belongs to BFP. `recordInspection`
(`WorkflowService.php:491-500`) updates that one row and nothing else; I verified the row's
`department_id = 3`, `inspector_user_id = 4`. Sanitary could not have written to it —
`InspectionController::authorizeDepartment` (`InspectionController.php:203-212`) would have
answered 403, and `canAct` would not have drawn the button
(`InspectionDecision.tsx:624-629`: department code match, or named inspector, or role `admin`;
`sanitary_officer` is none of those against a BFP visit).

What the Sanitary officer actually saw is the shared surface: the filing's status said "For
Inspection", the panel showed a card labelled **Approved**, and the panel has no per-office framing
telling them whose card it is beyond the office name in the heading. From Sanitary's seat that
reads as "the For Inspection got approved for everybody". The same surface is what Market read as
"it is said to have been approved by Fire Office's admin account" (report 3) — that sentence is a
literal description of the card at `InspectionDecision.tsx:325-334`.

**So report 2 is explained entirely by (a) the single shared filing status and (b) a single
undivided panel showing all offices' visits — not by any cross-office write.**

### Blast radius

- `ApplicationProgress.tsx` is shared by the applicant detail page and the officer review sheet
  (`ReviewPage.tsx:728`). It is also asserted by `api/tests/Feature/ApplicationProgressPayloadTest.php`
  and `StatusLabelParityTest.php`.
- `ApplicationStatus` (`api/app/Enums/ApplicationStatus.php`, mirrored at `web/src/lib/types.ts:224`)
  is read by the chatbot, analytics (`ProcessingTimeAnalytics`, `DashboardAnalytics`,
  `BusinessGrowthAnalytics`), notifications and the permit-issuance guard. Adding or splitting a
  status is a very wide change.
- `EndStateNotifications` pins that the approval end state is announced exactly once
  (`WorkflowService.php:275-281` records the rule); any new per-office message must not sound like
  a decision on the filing.

### Constraint any fix must satisfy

Whatever the applicant is shown must not claim a stage the offices have not reached. The cheapest
honest shape is per-office state rendered beside the filing status (the rail already knows the
offices — `AssignmentController::show` loads `application.assignments.department`), **not** a new
`ApplicationStatus` value, which would ripple into analytics and the chatbot.

---

## INS-5 — Any office's approval can drag a `returned`, `rejected` or `cancelled` filing into `for_inspection`.

**Maps to:** unreported. Found while tracing `afterReviewProgress`.

**Severity: CRITICAL, and it is a data-integrity and authorisation problem, not UX.** A terminal
rejection can be undone by an office that has no power to reverse it, and a filing returned to the
applicant for revision can have that return silently cancelled.

### Root cause

`WorkflowService::approveAssignment` calls `afterReviewProgress` unconditionally
(`WorkflowService.php:185`), and `afterReviewProgress` transitions to `ForInspection`
(`WorkflowService.php:296`) with no check on the filing's current status.

`WorkflowService::transition` (`WorkflowService.php:34-50`) has **no legality table** — its only
guard is `if ($from === $to) return;` at `:37-39`. Any status can become any status.

Neither `AssignmentController::approve` (`AssignmentController.php:291-302`) nor the route
(`api/routes/workflow.php:141-147`, `permission:application.review` only) refuses on filing status.

Before `5da4daa` this was unreachable: the old `afterReviewProgress` opened with *"every assignment
is completed, or return"* (shown in the commit diff), and a `returned` assignment is not
`completed`, so a returned filing could never transition. The guard was deleted with the rest.

### Reproduction (reasoned from code; **not** executed — I did not write to live data)

*Returned — reachable through the UI:*

1. Office A opens a filing under review and presses "Return with remarks". `returnAssignment`
   (`WorkflowService.php:189-194`) sets A's assignment `returned` and the filing to `returned`.
2. Office B opens the same filing. `ReviewPage.tsx:640-642` computes
   `decided = rejected || approvedHere || Boolean(data.completed_at)` — all false for B's pending
   assignment — and `app.status === 'returned'` does not hit the early return at `:689`, so B gets
   the full sheet with **Approve** enabled (`ReviewPage.tsx:904-962`).
3. B presses Approve → `afterReviewProgress` books B's visit and transitions `returned →
   for_inspection`.

**Observed:** the applicant's revision request is gone. `resubmit` (`WorkflowService.php:205-213`)
is the only thing that restores a `returned` assignment to `pending`, and the applicant's Resubmit
button (`web/src/pages/applicant/ApplicationDetailPage.tsx:636`) is only offered on a returned
filing. Office A's assignment stays `returned` forever, so `isFullyCleared` can never pass either —
this compounds into INS-1's deadlock. Current exposure: **2 filings**.

*Rejected — API-only, UI blocks it:*

`rejectApplication` (`WorkflowService.php:197-202`) sets the filing `rejected` and does **not**
touch assignments. `ReviewPage` refuses to draw Approve (`rejected → decided → :904`), but
`POST /api/v1/assignments/{id}/approve` from any office holding `application.review` and owning a
still-pending assignment on that filing resurrects it to `for_inspection` and books a visit.
Current exposure: **101 rejected filings** carry at least one `pending`/`in_progress` assignment.
`cancelled`: 0 today, same hole.

### Blast radius

- `transition()` is the single write path for `applications.status`; adding a legality table
  affects submit, pay, review, return, reject, resubmit, inspect and issue.
- `ApplicationStatusHistory` rows are what `ProcessingTimeAnalytics` and `AnalyticsHistorySeeder`
  measure; an illegal transition already recorded is already in that history.
- `RejectAuthorizationTest.php` covers *who* may reject, not what may follow a rejection.

### Constraint any fix must satisfy

The refusal has to live where every caller passes — `transition()` or `approveAssignment()` — not in
`ReviewPage`, because the UI is already correct for `rejected` and the hole is on the API. It must
not block the legitimate `for_inspection → for_inspection` no-op path that `afterReviewProgress`
deliberately routes around at `WorkflowService.php:283-294`.

---

## INS-6 — `canAct` grants the role `admin` decision controls on every office's visit, while the route denies that same account the permission to use them.

**Maps to:** unreported.

**Severity: MEDIUM.** Today it is a dead-button defect, not an authorisation breach — but the
authorisation shape underneath it is one permission grant away from being one, and it directly
contradicts the intent stated in commit `b1bc7bc` ("make an inspection something an admin can
actually act on").

### The facts

`web/src/components/InspectionDecision.tsx:624-629`

```tsx
function canAct(item: Inspection): boolean {
  if (!user) return false
  if (user.roles.includes('admin')) return true                                   // ← line 626
  if (user.department && item.department && user.department.code === item.department.code) return true
  return item.inspector?.id === user.id
}
```

Read literally, and to answer the question asked: **an officer of one department cannot reach
another department's Approve/Reject** — the department-code comparison at `:627` is a genuine equality
test on the code that `AssignmentController::show` eager-loads (`AssignmentController.php:245`), and
`user.department` really is on the payload (`UserResource.php:22-26`, `AuthController.php:39`,
typed at `web/src/lib/types.ts:16`). The mirror of the server rule is faithful. Three caveats:

1. **The `admin` bypass at `:626` is not faithful any more.** `admin@` holds neither
   `inspection.manage` nor `application.review` (verified by query), and
   `EnsurePermission` (`api/app/Http/Middleware/EnsurePermission.php:14-29`) has **no** superuser
   bypass — it checks the permission list and nothing else. So every inspection route
   (`api/routes/workflow.php:161-176`, `permission:inspection.manage`) answers `admin@` 403 before
   the controller runs. The screen would draw Approve and Reject and every press would fail.
   In practice this is currently **unreachable**: `QueuePage` and the review sheet are both behind
   `<RequirePermission permission="application.review">` (`web/src/App.tsx:363, 371`), which
   `admin@` also lacks. It is a live contradiction waiting for the first permission grant.
2. **`InspectionController::authorizeDepartment` has the same `admin` exemption by name**
   (`InspectionController.php:206-208`), exactly like `AssignmentController::authorizeDepartment`
   (`AssignmentController.php:398-409`). Today it is dead code for the same reason — the route gate
   fires first. But it means the *only* thing preventing a role literally named `admin` from
   conducting, rescheduling and re-inspecting any office's visit is the absence of one permission
   row. The client's stated model is that each office owns its own visit; that model is not
   expressed in `authorizeDepartment`.
3. **The `inspector_user_id` disjunct survives a department transfer.** `authorizeDepartment`
   accepts `$inspection->inspector_user_id === $user->id` regardless of department
   (`InspectionController.php:209-211`). `leastLoadedInspector` only ever picks a user from the
   booking department (`WorkflowService.php:481-488`), so this is safe at booking time — but an
   officer moved between departments via the admin user editor keeps write access to their old
   office's open visits. Low likelihood, real hole.

### Blast radius

- `canAct` gates the Approve, Reject, Reschedule and Schedule-re-inspection controls
  (`InspectionDecision.tsx:789, 797, 821`). Narrowing it removes controls; widening it advertises
  403s.
- `WorkflowReinspectionTest.php:258` asserts the super admin is refused a re-inspection — and the
  test's own comment (`:268-270`) says the 403 comes from the **route gate**, not from
  `authorizeDepartment`. The test therefore passes without ever exercising the department rule it
  appears to be about.

### Constraint any fix must satisfy

The UI predicate and `InspectionController::authorizeDepartment` must stay one rule. If the client's
"each office approves its own" is to be enforced rather than incidentally true, the `admin`
exemption has to be decided deliberately in both places at once — removing it from one alone
guarantees the drift the docblock at `InspectionDecision.tsx:618-623` warns about.

---

## INS-7 — Under a search, the queue's "Showing N of M" total is the whole tab, not the matches.

**Maps to:** unreported; same family as report 4.

**Severity: LOW** (misleading copy), but it is exactly the kind of confidently-wrong number the
codebase already paid for once (`AssignmentController.php:115-120`).

### Root cause

`AssignmentController::statusCounts` (`AssignmentController.php:208-233`) applies
`scopeToDepartment` and the **assignment**-status filter, and deliberately ignores
`application_status`. It also ignores `q` — the search added today at `:108-113` was not carried
into the count. `QueuePage.tsx:594-597` sums those counts across the tab's statuses and prints them
as the total at `:651-653`.

**Observed (computed against the register, read-only):** as `sanitary@`, For Inspection tab,
searching `abcd` → the screen reads *"Showing 1 of 1,133 matching “abcd”"*. There is one match.
1,133 is CHO's lifetime count of `for_inspection` + `approved` + `issued` assignments.

The docblock at `QueuePage.tsx:636-640` states the intended contract — *"`meta.total` counts every
filing matching the term … so 'Showing 1 of 1' is exactly true"* — which is what the code would do
if it used `data.meta.total`; it uses `counts` instead whenever `counts` is present.

### Blast radius / constraint

`statusCounts` also feeds the tab badges, which must stay unfiltered by `q` (a badge that shrinks as
you type is worse). The fix is on the summary line's choice of number, not on `statusCounts`.
`OfficerQueueFilterTest.php:105` ("counts each tab over the whole scoped set, not the page in hand")
pins the current count semantics and must keep passing.

---

## INS-8 — The super admin's queue would list one filing once per assignment.

**Maps to:** unreported. Latent.

**Severity: LOW today** — `admin@` cannot open the queue at all (no `application.review`;
`web/src/App.tsx:363`). Recorded because the moment that permission is granted the queue becomes
unusable.

`AssignmentController::index` returns assignment rows, and `scopeToDepartment`
(`AssignmentController.php:387-396`) applies no department filter for `admin`. Verified: searching
`abcd` as `admin@` returns **7** rows for the one filing `5003`, one per office. `QueuePage` de-dupes
only by row key (`QueuePage.tsx:504-508`), which is per assignment, so all seven would render as
seven cards with the same business name.

---

## Tests that pass while every one of these bugs is live

I ran the four relevant suites (in-memory SQLite per `api/phpunit.xml:35-36`; no live data touched):

```
tests/Feature/WorkflowParallelInspectionTest.php
tests/Feature/SixInspectingOfficesTest.php
tests/Feature/OfficerQueueFilterTest.php
tests/Feature/WorkflowReinspectionTest.php
→ 21 passed, 279 assertions
```

| Test | Why it misses the bug |
|---|---|
| `WorkflowParallelInspectionTest.php:102-238` (6 tests, all of `5da4daa`'s new coverage) | Every case drives `POST /assignments/{id}/approve` **directly**. That endpoint has no status guard (INS-1's block is entirely in `ReviewPage.tsx`), so the suite proves the service does the right thing on a call the officer can never make. It never asks whether the officer can reach the button. |
| `SixInspectingOfficesTest.php:130` "lets each of the six offices see and close its own visit, and issues once all six pass" | Same shape — it approves all six assignments over HTTP before conducting anything, so the filing is never observed in the mixed state (some reviews pending, status `for_inspection`) that INS-1 and INS-5 live in. |
| `OfficerQueueFilterTest.php:27-133` (5 tests) | Fixtures are filings whose assignment status and application status are consistent (`under_review` + pending). No case constructs a `for_inspection` filing with a *pending* assignment, which is the only input that shows INS-2. `:105` asserts the counts are computed over the whole scoped set — i.e. it pins the behaviour INS-7 complains about — and no case passes `q` and a count together. |
| `WorkflowReinspectionTest.php:258` "refuses the super admin a re-inspection" | Passes for the wrong reason. Its own comment at `:268-270` says the 403 comes from `permission:inspection.manage` on the route, before the controller runs — so `InspectionController::authorizeDepartment`'s `admin` exemption (INS-6) is never exercised and would not fail this test if it were removed *or* if the permission were granted. |
| `WorkflowHappyPathTest.php:16, 98` | Single-office and all-offices-approve-then-inspect happy paths. Neither interleaves an approval with an existing `for_inspection` status. |
| `OfficeActionScopingTest.php:41-101` | Covers fee adjust, reject and OIC assign across departments. **There is no test anywhere that attempts `POST /inspections/{id}/conduct` from a foreign department.** The guard at `InspectionController.php:203-212` is correct and untested. `grep -rn conduct api/tests` returns only same-department calls. |
| `web/e2e/inspection-review.spec.ts:173` "a For Inspection filing opens on the decision box, not the application form" | **Actively locks INS-1 in.** It asserts `getByText(ADMIN_REVIEW_SHEET)` has count 0 and `locator('details')` has count 0 for any `for_inspection` filing the office can open, with no regard for whether that office's own assignment is pending. Its helper (`:140-171`) prefers a filing with an outstanding visit but falls back to `anyWithVisits`, so on the current register it can land on `5003` as `sanitary@` — an office with a pending review — and still assert the review form must be absent. |
| `ApplicationProgressPayloadTest.php`, `StatusLabelParityTest.php` | Assert the rail's shape and label parity, both derived from the single filing status. They cannot detect INS-4, because INS-4 is that the single status is the wrong unit. |

**The gap in one line:** every guarantee about per-office scoping is asserted at the service or HTTP
layer, where it holds; every defect the client reported is at the layer where an officer decides
what they are allowed to press, which nothing tests.

---

## Interference with the other three investigations

Named explicitly, because the client's instruction is that fixing one must not break another.

**(a) Office-form separability + officer-facing copy — HIGH collision risk, on INS-1.**
The fix for INS-1 has to put the review form back in front of an office whose assignment is pending
on a `for_inspection` filing. That is the same form investigator (a) is separating per office, and
it is the form the client twice demanded be removed from the For Inspection screen
(`ReviewPage.tsx:658-663`). The two changes touch the same early return at `ReviewPage.tsx:689` and
the same e2e assertion at `web/e2e/inspection-review.spec.ts:173`. **These two fixes must be
sequenced, not merged blind.** The reconciliation that satisfies both is "the form appears iff *this
office* still owes a review", which is a condition (a)'s per-office form work already needs anyway.

**(c) Clearance withdraw dead-end — MEDIUM collision risk, on INS-1 and INS-5.**
`routeClearance()` / `withdrawClearanceRouting()` were deleted (`WorkflowService.php:164-174`) on
the premise that all clearances are chosen before submission, so the assignment set is fixed at
`routeToDepartments` (`:152-162`). Both `isFullyCleared` (`:317-339`) and `scheduleInspectionFor`
(`:355-386`) now depend on that fixity. If (c)'s fix reintroduces adding or removing an assignment
after routing, the deadlock in INS-1 changes shape (an office could be removed *out* of the deadlock,
which is a different bug) and `isFullyCleared` could pass with a clearance's review never done.
Whoever fixes (c) should be told that `isFullyCleared` is now the sole issuance predicate.

**(b) Location insights + over-technical warnings — LOW collision risk.**
The only shared surface is `AssignmentController::show`'s eager-load block
(`AssignmentController.php:239-274`) and `ApplicationResource`, which both clusters read.
`EagerLoadingTest.php` guards the query counts there; a fix on either side that adds a relation will
need that test updated, and two independent additions will conflict textually.

**Within this cluster:** INS-1 and INS-2 must be fixed with the *same* predicate. If the queue
decides "this office still owes a review" one way and `ReviewPage` another, an officer will click a
row on For Approval and land on a screen with no controls — which is the bug reported, restated.

---

## What I could not determine

- **No browser observation was possible.** `http://127.0.0.1:5199` refused the connection
  throughout. Every UI claim above is read off source at the cited line; none was watched on screen.
  INS-1's screen contents in particular are inferred from `ReviewPage.tsx:689-735` plus the payload
  `AssignmentController::show` returns, and should be confirmed visually before the fix is designed.
- **No state transition was performed.** INS-5's `returned → for_inspection` and `rejected →
  for_inspection` paths are reasoned from `transition()`'s missing legality check
  (`WorkflowService.php:34-50`), `afterReviewProgress`'s unconditional call site (`:185`) and the
  absence of any status guard in `AssignmentController::approve`. I did not execute either, on live
  data or otherwise. The exposure counts (2 returned, 101 rejected) are SELECTs.
- **Report 4's original screenshot could not be dated.** I established that `/assignments?q=` did
  not exist before commit `b1bc7bc` (today) and that with it the For Inspection search now returns
  the row for every office. The client's complaint reproduces today on the **For Approval** tab
  instead; whether that is what they photographed, I cannot say.
- **Report 2's exact click could not be recovered.** There is one inspection row on filing 5003, it
  belongs to BFP, and the API would have refused Sanitary. The audit log would settle what Sanitary
  actually pressed; I did not read `audits` for this filing, and that is the first thing to check if
  the explanation in INS-4 is disputed.
- **Whether the client's "office admin accounts" are the seeded `*@biztrack.local` users** is
  assumed, not confirmed. If they are running additional accounts that carry the role `admin`
  alongside a `department_id`, INS-6 stops being latent and becomes a live cross-office write
  capability. Worth asking.
