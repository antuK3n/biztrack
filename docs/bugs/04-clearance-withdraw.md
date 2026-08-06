# 04 — The clearance Apply/Submit dead-end

**Investigated** 2026-08-06 · read-only · branch `main` @ `ce40643` (working tree dirty, but nothing
in this report's blast radius is modified).

**Client's report, verbatim:**
> "I cannot remove my application on the Zoning/Locational Clearance once I changed my mind to Submit
> instead of Apply."

Screenshot: the clearance grid, a red banner reading *"You have applied for the Zoning / Locational
Clearance on this application. Withdraw that request first if you already hold one."*, and the Zoning
card showing **Submit** beside **Applied ✓**.

**Verdict up front: the client is right, the chain is exactly as you suspected, and yes — you broke
it.** `9e30b44` (2026-08-05 22:35:44 +0800) removed the only control that could leave the state, and
removed the e2e test that asserted it existed, replacing it with a test that asserts its *absence*.
The server rule it collides with predates you by two days and is correct. Three further defects fall
out of the same removal, and two are worse than the reported one because they block submission
outright.

---

## Environment note — what could and could not be verified live

- Nothing was running. `127.0.0.1:5199`, `:5173`, `:8000`, `:8001` all returned connection refused.
  **No browser reproduction was performed and nothing was created anywhere.** The reproduction steps
  below are derived from source and are corroborated by the existing API test
  (`ClearanceStageTest.php:511`), which asserts the 422 as intended behaviour.
- All counts are read-only `SELECT`s against `api/database/database.sqlite` opened
  `mode=ro&immutable=1`. No `tinker`, no seeder, no migration, no writes of any kind.
- That database has 1,677 applications; newest activity `2026-08-06 09:30:39`. `api/database/e2e.sqlite`
  is a near-identical copy (same 15-draft figure). `api/e2e.sqlite` is a 0-byte stub.

---

## The exact server rule

`api/app/Http/Controllers/Api/ClearanceController.php:124-147` — `storeHeld`, the endpoint behind the
Submit dialog's confirm button.

```
138  abort_if(
139      $this->clearances->isAppliedFor($application, $type),
140      422,
141      'You have applied for the '.$type->name.' on this application. Withdraw that request first if you already hold one.'
142  );
```

`isAppliedFor` is `ClearanceService.php:197-200` — a pure containment test on
`$application->permitTypes`, i.e. a row in `application_permit_types`. The precondition is therefore
**"the permit type is not attached to the filing"**, and the only thing in the system that detaches it
is `ClearanceService::unapply` (`ClearanceService.php:317-325`), reachable only through
`DELETE /applications/{id}/clearances/{code}/apply` (`api/routes/workflow.php:97`).

The message reaches the screen verbatim: `ClearanceStagePage.tsx:518` → `uploads.ts:54-63`
(`uploadErrorMessage` passes any non-413, non-"failed to upload" message straight through) →
rendered in the red `Alert` at `ClearanceStagePage.tsx:677-683`. That is the banner in the screenshot.

### Is the exclusion genuinely necessary?

**Yes, and the reason is money, not tidiness.** `App\Support\HeldPermits` (`HeldPermits.php:13-29`)
records a held copy as an ordinary `ApplicationDocument` carrying `permit_type_id`, and states the
invariant explicitly: *"What makes it 'held' rather than 'applied for' is the absence of the permit
type from `application_permit_types` — that absence is what spares the applicant the office form, the
assignment, and (because `FeeCalculator::assess` gates every rule on the selected permit types) the
fee."*

So the two records mean opposite things about the same clearance, and everything downstream reads the
permit-type side:

| Consumer | Reads | What "both exist" would mean |
|---|---|---|
| Tax Order of Payment | `FeeCalculator::assess` gated on permit types (`ClearanceService.php:398-409`) | The office's fee is billed **even though the applicant told us they already hold the certificate**. Not a double-charge — a wrong charge. |
| Routing | `WorkflowService::routeToDepartments` (`WorkflowService.php:152-162`), one assignment per `permitTypes.issuing_department_id` | The office is asked to review an application for a clearance the applicant simultaneously submitted a copy of. |
| Issuance | `WorkflowService::approveAndIssue` (`WorkflowService.php:590-616`) loops `$app->permitTypes` and `Permit::create`s one row each | The City issues a fresh Zoning clearance **and** the register carries the applicant's own copy. |
| Profile | `PermitController::held` (`PermitController.php:104-145`), `whereNotNull('permit_type_id')`, no exclusion for filings that also carry the type | The same clearance appears twice for one business: once as a solid royal issued-permit row, once as the dashed "Your own copy" row (`ProfilePage.tsx:293-345`). |

**Nothing double-charges and nothing double-issues today, and nothing would if both records existed
— but the register would assert two contradictory things about one clearance, one of which
(`Permit`) is a legal instrument.** The constraint section at the end states precisely what a fix
must not do.

**Live data confirms the invariant currently holds.** Zero filings in the whole register carry both an
`application_permit_types` row and an `application_documents.permit_type_id` row for the same permit
type. Zero held copies on `BUSINESS`. The server rule has never been violated.

---

## Every route out of the state — enumerated

An applicant on a **draft** who has pressed Apply on Zoning and now wants Submit instead:

| Route | Result | Evidence |
|---|---|---|
| Press **Apply** again | Opens the CPDD office sheet. Never un-applies. The `if (row.state === 'available' \|\| row.state === 'submitted')` guard skips the POST, and the handler falls through to `hasOfficeForm(code)`. | `ClearanceStagePage.tsx:461-487` |
| Press **Submit** | 422 with the banner. This is the reported bug. | `ClearanceController.php:138-142` |
| Press **Remove** (the held-copy control) | Not rendered — it only exists inside `{held && …}` and there is no held copy. | `ClearanceStagePage.tsx:858-892` |
| Any **withdraw / "Don't apply"** control | **Does not exist.** `grep -rn "unapply" web/src` returns exactly two hits: the definition at `resources.ts:344` and a comment at `ClearanceStagePage.tsx:491`. **`clearances.unapply` has zero callers in the entire front end.** | `resources.ts:344-347` |
| Go back through the **wizard** | The wizard renders the same `<ClearanceStage>` component (`ApplyWizard.tsx:34`, `:2467`). Same two buttons, same absence. | — |
| Edit the draft so the autosave drops it | Impossible by design. `ApplicationController::update` does `permitTypes()->sync()` (`ApplicationController.php:245-247`), but the wizard **deliberately never sends `permit_type_ids` on update** — see the eight-line comment at `ApplyWizard.tsx:3176-3186` explaining that doing so would silently undo the applicant's clearances. On hydration `form.permit_type_ids` is reset to `[BUSINESS]` alone (`ApplyWizard.tsx:3494-3496, 3571`). | — |
| Documentary Requirements step | Held copies are explicitly skipped on restore (`ApplyWizard.tsx:3584-3589`), and that step never posts `permit_type_id` — `documents.upload` is called with three arguments at both call sites (`ApplyWizard.tsx:3370, 3411`). | — |
| **Cancel the whole application** | **Works.** `ApplicationController::cancel` allows Draft (`:370-388`), and the button is on the filing's detail page (`ApplicationDetailPage.tsx:672-676`). | — |

**So: the only route out is to destroy the entire filing and start over.** Cancel is a one-way
transition — `ApplicationStatus::Cancelled` has no reopen path, and `ClearanceService::lockedReason`
says as much (`ClearanceService.php:268`: *"File a new application if you still need these
clearances."*). Every field typed, every uploaded requirement and every saved office sheet goes with
it.

### After submission

`ClearanceService::isUnlocked` is `$application->status === ApplicationStatus::Draft` and nothing else
(`ClearanceService.php:241-244`). Every clearance write calls `assertUnlocked`
(`ClearanceController.php:56, 83, 128, 154`). So the moment the filing is submitted:

- the dead-end becomes **permanent for the life of the filing**;
- the applicant is charged the office's fee on the one Tax Order of Payment;
- the office is routed an assignment at payment and does the work;
- a `Permit` is issued for it at `approveAndIssue`.

There is no correction path in the product. `lockedReason` tells the applicant to *"message the
BPLO"* (`ClearanceService.php:265`), and there is no officer-side control to detach a permit type
either — no controller anywhere writes `permitTypes()` except `ApplicationController` (owner-only,
draft-only) and `ClearanceService` (owner-only, draft-only). **A clearance applied for by accident and
submitted is billed, worked and issued, and no role in the system can undo it.**

---

## The asymmetry — and it is in the client, not the server

The server is symmetric. Both endpoints refuse the other state:

- `apply` refuses when a held copy exists — `ClearanceController.php:68-72`, *"You have already
  submitted a … you hold. Remove that copy first if you want to apply for a new one."*
- `storeHeld` refuses when applied — `ClearanceController.php:138-142`.

**The front end is not.** `onApply` silently resolves the conflict for the held → applied direction:

```
466  // Applying for it and already holding it are opposites (same as the wizard).
467  if (row.held_document) {
468    const ok = await runAction(
469      code,
470      `The copy of your ${row.permit_type.name} was removed, because you are applying for one instead.`,
471      () => clearances.removeHeld(applicationId, code),
472    )
```
— `ClearanceStagePage.tsx:466-473`

`onSubmitHeld` (`:507-522`) has no counterpart. So **held → applied is one click; applied → held is
impossible.** That asymmetry is itself the defect: the client was told at the card that the two
buttons are alternatives, and one alternative is one-way.

(It is also worth noting that the auto-removal at `:467` is destructive — see `CLR-3`.)

---

## Is the message accurate?

Partly, and the inaccurate half is the actionable half.

| Clause | True? |
|---|---|
| "You have applied for the Zoning / Locational Clearance on this application." | Accurate. `isAppliedFor` is exactly that. |
| "Withdraw that request first" | Accurate about **what** the server needs (detach the permit type) and inaccurate about **whether it can be done** — no control exists. |
| the word "**Withdraw**" | Named no control even before `9e30b44`. The removed button read *"Don't apply for the ‹clearance›"* (`git show 9e30b44` line 240 of the diff). So the sentence has never matched a label on screen. Contrast the mirror message at `:71` — "Remove that copy first" — which does name the real control (`ClearanceStagePage.tsx:888`, "Remove"). |
| "if you already hold one" | A dangling conditional. The applicant is on the upload dialog *because* they hold one; the clause reads as a doubt about the thing they just asserted. |

---

## Defects

### CLR-1 — An applicant who applies for a clearance can never change to Submit, and can never withdraw at all

**Maps to:** the client's report, verbatim.
**Severity:** High. **Permanently stuck** — on a draft, only by destroying the whole filing; after
submission, absolutely, for the life of the filing (billed, routed, issued).

**Root cause.** Two halves that are individually defensible and fatal together:

1. `api/app/Http/Controllers/Api/ClearanceController.php:138-142` — `storeHeld` refuses while the
   permit type is attached, and directs the applicant to withdraw.
2. `web/src/pages/applicant/ClearanceStagePage.tsx:489-495` — the withdraw handler and its control are
   gone. `clearances.unapply` (`web/src/lib/resources.ts:344-347`) has **no caller anywhere in the
   front end**.

**Caused by today's removal? YES.** `9e30b44` "fix(clearances): give the card back its old, readable
shape", 2026-08-05 22:35:44 +0800, deleted `onUnapply` (diff lines 103-108) and the
`Don't apply for the …` button (diff line 240). The server rule at `ClearanceController.php:138-142`
predates it — it was last touched by `79998bf` on 2026-08-03. Before `9e30b44` the client's exact
sequence worked: press "Don't apply for the Zoning / Locational Clearance", then Submit.

The forensic corroboration is in the audit log. `clearance.unapplied` last fired **2026-08-05
08:56:36**; `clearance.applied` has fired as recently as **2026-08-06 09:24:39**. Not one withdrawal
has occurred since the control was removed at 22:35 on the 5th, while applies have continued.

**Reproduction (draft, applicant `owner@biztrack.local`):**
1. Open a draft's LGU Clearances step (or `/applications/{id}/clearances`).
2. Press **Apply** on Zoning / Locational Clearance. The button becomes **Applied ✓**; the CPDD sheet
   opens; go back.
3. Change your mind. Press **Submit** on the same card, choose a PDF, press Submit in the dialog.

**Observed:** red banner, *"You have applied for the Zoning / Locational Clearance on this
application. Withdraw that request first if you already hold one."* No control on the page, the
wizard, or the filing's detail page can satisfy it.
**Expected:** either the switch is offered (as the reverse direction already is, `:466-473`), or the
refusal names something the applicant can actually press.

**Live count.** In `api/database/database.sqlite`:

- **15 real draft filings** currently carry at least one applied-for clearance and cannot withdraw it.
  **0 seeded** — no filing matched `businesses.registration_number LIKE 'AHS-%'` or
  `users.email LIKE '%analytics-seed.biztrack.invalid'`. Total drafts in the register: 46.
- Per clearance across those 15: SANITARY 11, OCCUPANCY 7, CEC 6, FSIC 5, MARKET 5, **ZONING 4**.
- Owners: 11 belong to `owner@biztrack.local` (the client's own account — including app **3386**,
  a draft carrying **ZONING alone**, last touched 2026-08-05 07:10:50, which is very likely the filing
  in the screenshot), 3 to `juan@biztrack.local`, 1 to `mjmakiling@biztrack.local`, 1 to
  `test@test.com`.
- **0** of the 15 point at a soft-deleted business, so `assertPriceable` would not block a restored
  withdraw control on any of them.
- **0** filings anywhere hold both an `apply` and a held copy for the same permit type — the
  invariant is intact; nobody has got past the guard.

---

### CLR-2 — Applying for MARKET, SANITARY or OCCUPANCY by accident hard-blocks the wizard, with no way back

**Maps to:** unreported. Strictly worse than CLR-1 and produced by the same removal.
**Severity:** High. **Permanently stuck** on that draft — the filing cannot be submitted at all unless
the applicant invents answers to a form for a clearance they do not want.

**Root cause.** Applying spawns a mandatory wizard step:

- `ApplyWizard.tsx:2304-2311` — `selectedOfficeCodes` is derived from rows whose `state` is `applied`
  or `issued`; a held copy deliberately spawns no sheet.
- `ApplyWizard.tsx:2317-2328` — each spawned sheet is inserted as a step directly behind LGU
  Clearances.
- `ApplyWizard.tsx:2435` — `missingFor` for an office node is `officeFormMissing(code, data)`.
- `OfficeFormStep.tsx:191-221` — `SANITARY` requires *Sanitary Classification*; `OCCUPANCY` requires
  *Application Type*; **`MARKET` requires *Name of Market* and *Stall No.***. (ZONING, FSIC and CEC
  require nothing, which is why the client's Zoning case is "only" CLR-1.)
- `ApplyWizard.tsx:5368, 5376` — Next/Submit are `disabled={saving || stepMissing.length > 0}`.
- `ApplyWizard.tsx:2692-2696` — `jumpBlocked` refuses to hop forward over an unfinished step, so the
  section map cannot be used to skip it either.

The wizard's own comment at `ApplyWizard.tsx:2337` still reads *"Withdrawing a clearance removes its
sheet: don't leave `step` past the end"* — the escape it assumes exists is the one `9e30b44` deleted.

**Caused by today's removal? YES.** The gating is older and correct; what changed is that the sheet
can no longer be made to go away.

**Reproduction:** on a draft, press **Apply** on Market Clearance (the card the client already
complained is shown to businesses it does not apply to — item 98). A "Market Clearance" step appears.
Next is disabled with *"Still needed on this part: Name of Market, Stall No."*. Nothing on the
clearance card, the map, or the API surface the UI calls can remove that step.
**Observed:** a shopfront greengrocer must type a market name and a stall number they do not have, or
cancel the filing.
**Expected:** withdrawing the clearance removes its sheet — which is exactly what the code comment at
`:2337` says should happen.

**Live count.** Of the 15 stuck drafts, **13 carry SANITARY, OCCUPANCY or MARKET**. Cross-referencing
`application_office_forms`: **5 drafts carry MARKET and not one of them has a saved MARKET form**
(apps 4, 5, 7, 3376, 3379). Those five cannot reach Review & Submit today. App 5 has MARKET, SANITARY,
FSIC, OCCUPANCY and CEC applied and **no** saved office form at all.

---

### CLR-3 — Apply silently deletes an uploaded held copy, file and all, with no confirmation

**Maps to:** unreported. Adjacent, found in the same handler.
**Severity:** Medium. Not permanently stuck (the applicant can re-upload) but **irreversible data
loss** on a single click of a button that is not named for deletion.

**Root cause.** `web/src/pages/applicant/ClearanceStagePage.tsx:466-473` — `onApply` calls
`clearances.removeHeld` before applying, whenever `row.held_document` is set. That resolves to
`DELETE /clearances/{code}/held` → `HeldPermits::forget` → `forgetAllExcept(…, null)`
(`HeldPermits.php:107-133`), which **deletes the stored file from disk** and the row. There is no
confirmation dialog; the only signal is the after-the-fact live-region sentence at `:469`.

This directly contradicts the rule written three times on this very card:

> *"destroying something must never be the alternate meaning of the button that created it"* —
> `ClearanceStagePage.tsx:855-856`
> *"Removing must stay its own named control."* — `:854`

Removing is its own named control for the Submit button. It is not for Apply.

**Caused by today's removal? NO — predates it.** The auto-removal is present in `9e30b44`'s parent.
But `9e30b44` made it the *only* asymmetry left on the card, and reading it next to CLR-1 is what
makes the shape obvious: the front end will happily destroy an uploaded certificate to satisfy the
mutual exclusion in one direction, and refuses to detach a free, undoable permit-type row to satisfy
it in the other.

**Reproduction:** Submit a copy of the Sanitary Permit. Press **Apply** on the same card. The file is
gone from disk with no prompt.

---

### CLR-4 — The Cancel button is offered on filings the API refuses to cancel

**Maps to:** unreported. Adjacent — it is the escape hatch CLR-1 forces people toward.
**Severity:** Low. Merely inconvenient (a dead button and a raw 422), but it degrades the one route
out of CLR-1.

**Root cause.** `web/src/pages/applicant/ApplicationDetailPage.tsx:672` renders the control for
`['draft', 'submitted', 'under_review', 'returned']`. `ApplicationController::cancel`
(`ApplicationController.php:374-381`) allows only `Draft`, `Submitted`, `PendingPayment`. So:

- `under_review` and `returned` → button visible, 422 *"This application can no longer be cancelled."*
- `pending_payment` → API allows it, button not offered.

**Caused by today's removal? NO — predates it**, and predates the clearance work entirely.

---

### CLR-5 — A second, unguarded path can create the forbidden apply+held state (latent)

**Maps to:** unreported. Latent, not reachable from the UI, but it is the thing a fix must not trip
over.

**Root cause.** `api/app/Http/Controllers/Api/DocumentController.php:34-104` accepts `permit_type_id`
on `POST /applications/{id}/documents` and creates a held copy through `HeldPermits::documentType`.
**It performs no `isAppliedFor` check.** Its own docblock explains why it does not need one:

> *"This is the wizard's path… The post-payment clearance stage takes the same upload through
> ClearanceController, which has its own gate (the stage opens on payment) — so the two windows do not
> overlap and neither has to know about the other."* — `DocumentController.php:60-66`

**That reasoning is stale.** The stage no longer opens on payment; `ClearanceService::isUnlocked` is
now `status === Draft` (`ClearanceService.php:241-244`), and `DocumentController` allows
`Draft | Returned` (`:67-71`). The two windows **fully overlap on a draft**. Any authenticated owner
can `POST /applications/{id}/documents` with `permit_type_id` while the same permit type is attached
and produce the state `storeHeld` exists to prevent.

Two related sharp edges in the same area:

- `HeldPermits::find` (`HeldPermits.php:36-42`) matches **any** `ApplicationDocument` with that
  `permit_type_id` — it does not filter on the `HELD_` document-type prefix. Today that is safe
  because only these two paths set the column, but the "held" predicate is weaker than the docblock
  implies.
- `DocumentController` accepts `Returned`; `ClearanceController::destroyHeld` requires `Draft`
  (`:154`). A held copy created on a returned filing is removable only via
  `DELETE /applications/{id}/documents/{doc}` (`ApplicationController::destroyDocument:440-458`, which
  does allow `Returned`) — a different endpoint the clearance UI never calls.

**Caused by today's removal? NO.** It is a consequence of the stage moving from after-payment to
before-submission (`4fb2d54`), and the docblock was simply not updated. It is **not reachable from the
front end today**: `documents.upload`'s optional `permitTypeId` (`resources.ts:388-404`) is passed by
nobody — both call sites (`ApplyWizard.tsx:3370, 3411`) pass three arguments.

**Live count:** 12 held documents exist, on 2 filings (6 on one `approved`, 6 on one `under_review`).
None collides with an applied permit type. None on a draft.

---

## Blast radius — what a fix must not break

Establishing this precisely, because the obvious fix (make `storeHeld` auto-withdraw, mirroring
`onApply` at `:466-473`) is safe **only** under conditions that are currently true and easy to lose.

**Why auto-withdrawing is safe today:**

- **No assessment exists yet.** `ClearanceService::apply` writes no `FeeAssessment` — the docblock at
  `:283-288` says so and `ClearanceStageTest.php:228` asserts it. The Tax Order of Payment is produced
  once, by `assessFees` at submit, from whatever permit types are attached at that moment
  (`ClearanceStageTest.php:243, 297`). Detaching on a draft therefore removes a line from a bill that
  has not been written.
- **No assignment exists yet.** `routeToDepartments` runs at `onPaymentCompleted`
  (`WorkflowService.php:125-162`); `apply` deliberately does not route (`:145-150`), because
  `assigned_at` starts the office's service-time clock that `ProcessingTimeAnalytics`,
  `StaffingSimulation` and `DashboardAnalytics` all measure. Nothing to unwind.
- **No permit exists yet.** `approveAndIssue` runs only after full clearance
  (`WorkflowService.php:321-330, 590-616`).

**Why it stops being safe the moment the stage is unlocked past Draft:**

- Relaxing `isUnlocked` to include `Returned` or `PendingPayment` and *then* auto-withdrawing would
  detach a permit type that the already-issued Tax Order of Payment has been priced from. The
  applicant would have paid an office line for a clearance no longer on the filing — with no refund
  mechanism anywhere in the codebase. `ClearanceService.php:230-239` argues at length against
  unlocking `Returned` for exactly this reason. **Do not touch `isUnlocked` as part of this fix.**
- Past payment, `routeToDepartments` has already created the assignment. A detach would leave an
  assignment on an office whose permit type is gone; `ClearanceService::assignmentFor` keys on
  `issuing_department_id`, so the card would still show that office's assignment while reporting
  `state: 'submitted'`.
- Past approval, `approveAndIssue` has already created the `Permit`. `ClearanceService::state`
  (`:184-195`) returns `issued` before it checks anything else, so a held copy would sit invisibly
  behind an issued permit — and `PermitController::held` would list it anyway (no exclusion), putting
  two rows for one clearance on Profile: the solid royal issued row and the dashed "Your own copy" row
  (`ProfilePage.tsx:293-345, 477-495`).

**Guards a fix must route through, not around.** If `storeHeld` gains an auto-withdraw, it must go
through the same three checks `unapply` applies (`ClearanceController.php:89-107`), not straight to
`ClearanceService::unapply`:

1. `isAppliedFor` — the thing being withdrawn is actually attached;
2. no `Permit` already issued for that type (`:95-99`);
3. `officeHasActed` — `InProgress | Completed | Returned` on that office's assignment
   (`ClearanceService.php:335-344`). Its own test calls it *"defence in depth"* and notes it is
   currently unreachable (`ClearanceStageTest.php:402-410`) — unreachable is not the same as
   unnecessary, and an auto-withdraw is a new way in.

Also note `assertPriceable` (`ClearanceController.php:87, 207-214`): `unapply` requires a live
business record, `storeHeld` does not (deliberately — `ClearanceStageTest.php:726`). A fix that makes
`storeHeld` withdraw would inherit that requirement and start refusing held copies on the 139 filings
whose business is soft-deleted. None of the 15 currently-stuck drafts is one of those, but the rule
would change.

**Orphaned office forms.** `unapply` deliberately keeps saved `ApplicationOfficeForm` rows
(`ClearanceService.php:309-316`), and this is visible in live data: app 3381 has only `CEC` applied
but carries saved forms for `SANITARY, FSIC, OCCUPANCY, CEC` — leftovers from clearances withdrawn
back when the control existed. `ClearanceService::row` computes `office_form_complete` for all six
cards regardless of state (`:155`), so an `available` card can report a complete sheet. Cosmetic
today; relevant to whoever is looking at the officer's review sheet.

---

## Interference with the other three investigations

- **Inspection/approval flow.** Overlaps at `WorkflowService::approveAndIssue`
  (`WorkflowService.php:590-616`) and `isFullyCleared` (`:321-330`). I only read them, to establish
  that a permit type on the filing becomes a `Permit`. If their fix changes what `approveAndIssue`
  loops over, CLR-1's permanence argument changes with it — coordinate.
- **Office separability on the review sheet.** Overlaps at `ApplicationOfficeForm` orphans (above) and
  at `ClearanceService::assignmentFor` (`:211-215`), which keys assignments on `issuing_department_id`
  and would collide if an LGU ever routed two clearances to one office. Also `PermitController::held`
  and the `HELD_<CODE>` document types (`HeldPermits.php:88-98`), which surface in the officer's
  attachment list.
- **Location insights + over-technical warnings.** Overlaps only on the ZONING card's identity — no
  shared code. Note that `ClearanceStagePage.tsx` still carries a large dead comment block
  (`:555-593`, plus the orphaned `marketClearanceApplies` / `MARKET_CATEGORIES` exports at `:152-203`)
  describing an item-98 derivation that no longer gates anything; if they are editing that file,
  that's theirs to trip over.
- No file in this report is currently modified in the working tree.

---

## Tests that pass while all of this is broken

**`api/tests/Feature/ClearanceStageTest.php:511-525` — "keeps applying and submitting mutually
exclusive". Passes, and it is the reason the bug felt safe.** It asserts the exact 422 the client hit
as *correct*, then recovers by calling `DELETE /clearances/SANITARY/apply` directly. That DELETE is
the endpoint no UI offers. The test encodes the dead-end as the specification and proves the escape
exists at the HTTP layer — which is true, and irrelevant to a person holding a mouse.

**`api/tests/Feature/ClearanceStageTest.php:386-400` — "takes the clearance and its fee lines back off
when it is un-applied". Passes.** Same blind spot: it exercises an endpoint with no caller.

**`web/e2e/clearances.spec.ts` — extensive, nine tests, and none of them can catch this. Here is
exactly why:**

1. **The test that would have caught it was deleted in the same commit.** `clearances.spec.ts:439-449`
   is now a comment where `'un-applying has its own labelled control, apart from Apply'` used to be.
   It even names the gap honestly: *"The half that is genuinely unenforced now: there is no way to
   withdraw a clearance you applied for."* A comment is not a failing test.
2. **Its replacement asserts the absence.** `clearances.spec.ts:608-647` contains
   `await expect(card.getByRole('button', { name: /don't apply/i })).toHaveCount(0)` — the suite now
   actively enforces that no withdraw control exists. A future fix that restores one will fail this
   test, which is precisely backwards.
3. **No e2e test ever uploads a file.** `'Submit always opens the upload box'` (`:410-437`) opens the
   dialog, checks its copy, and cancels — twice. It never picks a file, so it never reaches
   `onSubmitHeld` and never sends the request that 422s.
4. **No e2e test ever presses Submit on an applied card.** `'Apply always opens that office's form'`
   (`:368-408`) and `'applying is reported on the button'` (`:608`) both apply first, but only ever
   press Apply again. The one sequence in the product that fails — Apply, then Submit with a real file
   — appears nowhere.
5. **Nothing covers the wizard hard-block (CLR-2).** `'the wizard puts the clearances last'` (`:650`)
   walks a happy path. No test applies for MARKET and then tries to advance.
6. **Nothing covers the destructive Apply (CLR-3).** No test uploads a held copy and then presses
   Apply, so the silent file deletion at `:467-472` is unasserted in either direction.

`docs/HANDOFF.md:1099` records the gap accurately — *"No way to withdraw a clearance… the API
endpoint `clearances.unapply` is intact and working, it simply has no UI."* What the entry
understates is the consequence: it is filed under "Known defects" as missing convenience, and it is in
fact a state 15 real drafts are in, that 5 of them cannot submit from, and that no filing can ever
leave after submission.

---

## Summary

| ID | Summary | Reported? | Severity | Stuck? | Yours? |
|---|---|---|---|---|---|
| CLR-1 | Applied → Submit is refused, and no UI can withdraw | **Yes, verbatim** | High | Permanently (cancel the filing, or forever after submit) | **Yes — `9e30b44`** |
| CLR-2 | Applying for MARKET/SANITARY/OCCUPANCY adds a mandatory sheet that cannot be removed, blocking submission | No | High | Permanently, on that draft | **Yes — `9e30b44`** |
| CLR-3 | Apply silently deletes an uploaded held certificate from disk | No | Medium | No — re-uploadable | No — predates |
| CLR-4 | Cancel offered on `under_review`/`returned`, which the API refuses | No | Low | No | No — predates |
| CLR-5 | `DocumentController` can create the forbidden apply+held state (latent, API-only) | No | Low today | n/a | No — stale docblock from `4fb2d54` |

**Live, real filings affected right now: 15 drafts (0 seeded), 5 of which cannot be submitted at all.
0 filings have reached the forbidden apply+held state.**
