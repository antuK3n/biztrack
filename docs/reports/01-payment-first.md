# 1. Payment first: the ordering reversal

## What changed, and why

The six LGU clearances — Zoning, Sanitary, Fire, Environmental, Occupancy,
Market — used to be chosen *inside* the application wizard, before submission,
with one Tax Order of Payment covering the business permit and every clearance
together. That has been reversed.

The wizard is now the business permit alone. You submit it, you pay for it, and
only then does the clearance stage open. From that point each clearance is its
own transaction: pressing **Apply** adds that office's fee to a running balance
and routes the filing to that office at that moment, and no permit is released
while the balance is outstanding.

```
WIZARD — the business permit application
  1 Data Privacy Consent      4 Documentary Requirements
  2 Location & Zoning         5 Business & Tax Profile
  3 Business Information      6 Review & Submit
        |
  SUBMITTED  ->  Tax Order of Payment #1 (business permit)  ->  PAID
        |
LGU CLEARANCES — the stage opens here
  Apply  -> that office's form opens, its fee joins the balance,
            the filing is routed to that office now
  Submit -> upload the copy you already hold. No fee, no form.
        |
  BALANCE DUE must reach zero before any permit is released
```

The wizard's six steps are exactly that list — `ApplyWizard.tsx:69` and `:141`:

```ts
const BASE_PHASES: BasePhase[] = ['privacy', 'address', 'business', 'documents', 'fees', 'review']
```

There is no `permits` phase and no office-form sheets inside the wizard; both
left with the reversal.

### Why reverse a decision that was already made

Because the reason for the earlier reversal has not gone away, and neither has
the reason for this one. Both directions are defensible and both cost something:

- **Clearances chosen inside the wizard** is simpler to build. One assessment,
  one payment, one gate. But an applicant has to decide, at the counter, which
  of six clearances they need before they have spoken to any of the six offices.
- **Clearances after payment** matches what actually happens at City Hall: you
  get your business permit application in and paid for, and the clearances are
  separate errands with separate offices and separate fees. The cost is that it
  needs an accruing balance, a second payment, a release gate, and a stage that
  has to be locked and explained before it opens.

This is the arrangement the client asked for, so this is what is built. It is
worth saying plainly that this is the second reversal, and that the file
recording the design was written for the first one.

> **A documentation defect we did not fix.** `docs/clearances-after-payment.md`
> is the spec this work was built against, and roughly fifteen code comments
> written in this commit cite it as the live specification — two of its rules are
> quoted verbatim in `PermitFees` and `WorkflowService`. But the file still opens
> with `# SUPERSEDED — 4 August 2026` and points the reader at
> `docs/clearances-before-payment.md`, which describes the design we have just
> undone. Neither file was touched by this commit. A reader following the
> pointer would read the wrong spec and conclude the code was wrong. Both headers
> need correcting; we have left them as found rather than edit documentation
> while writing a report about it.

## The three bugs this design grows

The superseded spec predicted where this arrangement produces bugs. All three
appeared, and all three are fixed.

### 1. The unpayable balance, one status over

The payment endpoint was already open to filings past the first payment. What
was not right was its definition of "closed". It used
`ApplicationStatus::isTerminal()`, and that enum counts **Approved** as terminal
alongside Rejected and Cancelled.

Approved is exactly the state a filing is in when a business comes back in
October to add a food line and needs a sanitary clearance it did not need in
January. So that applicant could apply for a clearance, see the fee, owe the
fee, be blocked by the fee — and be refused when they tried to pay it.

Narrowed to the two statuses that really are closed —
`PaymentController.php:99-109`:

```php
$closed = in_array($application->status, [ApplicationStatus::Rejected, ApplicationStatus::Cancelled], true);
```

### 2. The release gate locking officers out of their own work

The rule "no permit while money is owed" was first put in one place:
`approveAndIssue()`. That method is called from the automatic paths too — when
the last office approves its review, and when the last inspection is recorded.
So the refusal was thrown *inside the transaction that had just marked that
office's own assignment completed*. An office could not finish its own review
because of somebody else's unpaid bill, and the transaction rolled back its
approval with it.

The fix is to split the two audiences for that rule:

- A **direct caller** — an officer pressing the button that issues the permit —
  gets a hard `ValidationException`, keyed on `balance_due`
  (`WorkflowService.php:1102-1110`): *"This application still owes ₱… on the
  clearances applied for after payment. Permits are released once the balance is
  settled."*
- The **automatic paths** get a quiet "not yet". `isFullyCleared()`
  (`WorkflowService.php:744-809`, balance branch at `:791-793`) simply returns
  false, so the office's approval is recorded, nothing is rolled back, and the
  filing waits.
- `releaseIfSettled()` (`WorkflowService.php:459-464`) picks it up when the
  money lands. Its one call site is `onPaymentCompleted()` at line 344.

`isFullyCleared()` has four call sites — the classifier, `releaseIfSettled`,
`afterReviewProgress` and `recordInspection` — which is the point: every route
to release asks the same question.

**A correction to how this was described to us.** Relative to the immediate
parent commit, the gate did not exist at all to be misplaced; the parent's
`approveAndIssue` docblock said in terms *"There is no balance check here, and
there should not be one"*, because under the one-payment design there was never
a balance. The misplaced-gate bug belongs to the pre-4-August build of this same
design. What this commit does is re-add the gate, already split. That is the
same lesson learned once rather than twice, but it is not a bug that was live in
the code a week ago, and the report should not imply it was.

### 3. Office sheets that could be billed for but never filled in

An office sheet is editable while the application is a draft or has been
returned. Under the previous ordering that was the whole story, so the second
edit window was deleted as dead code.

Under this ordering it is not dead: a clearance applied for after payment first
becomes reachable on a filing that is already under review, and the applicant
would have been billed for a form they could never open.

The window is restored, and it is now per-sheet rather than per-filing —
`OfficeFormController.php:198-214`. Draft or Returned, yes; Rejected or
Cancelled, no; otherwise the sheet is open while *that clearance's own
assignment* is not yet completed. The refusal explains itself:

> This form can no longer be edited. Office forms are open while the application
> is a draft or has been returned to you, and while a clearance you applied for
> is still waiting on its office.

Covered by `it('lets the applicant fill in the sheet for a clearance applied for
after payment')`, `ClearanceStageTest.php:880`.

## One design detail worth explaining

The stage unlocks on a fact from the **payments ledger**, not on the
application's status — `PermitFees::hasClearedPayment()`, `PermitFees.php:91-96`:

```php
return $application->payments()->where('status', PaymentStatus::Completed->value)->exists();
```

Only a `Completed` payment counts; pending, failed and refunded do not.

Status alone would get two ordinary cases wrong, in opposite directions:

- A filing **rejected while still at `pending_payment`** never paid. Asking the
  status would find a terminal state and might unlock or lock for the wrong
  reason; asking the ledger finds nothing settled, and the stage stays shut.
  (Belt and braces here: the closed-status list catches it too. Test at
  `ClearanceStageTest.php:337`.)
- A filing **returned to the applicant after payment** has a status that looks
  unfinished but a ledger that says paid. The stage stays open, which is right —
  the applicant is fixing something, not starting again. Test at
  `ClearanceStageTest.php:299`.

The ledger is the record of what actually happened. The status is a summary of
where the filing is. For "has this been paid for", only one of those is
authoritative.

## What we assumed, and whether the code enforces it

Seven decisions were taken to keep moving. Six are enforced by code; one is only
written down. The distinction matters, so here it is honestly:

| Assumption | Status |
|---|---|
| The stage unlocks on the **first payment clearing**, not on submission | Enforced — `ClearanceService::isUnlocked()` (`:288-295`); the controller returns 422 with the locked reason as its body |
| A clearance may be applied for **after the permit is released** | Enforced — `Approved` is deliberately absent from the closed list, and the payment fix above makes the resulting balance payable |
| A **rejected clearance** does not kill the business permit | **Stated only, and currently unreachable.** `AssignmentStatus` has no `Rejected` case at all — it is Pending, InProgress, Completed, Returned. The clearance API still lists `'rejected'` as a possible state while nothing in the system can produce one. This is checklist item 80 and question A2, still open |
| **Fee gating untouched** — the Fire Code fee and the sanitary inspection fee stay gated on their clearance | True: this commit changes neither `FeeCalculator.php` nor any revenue-code data file. 8 rules are gated on `FSIC`, 21 on `SANITARY` |
| **No refund** when a clearance is withdrawn | Enforced — `PermitFees::balance()` floors at zero. Test at `ClearanceStageTest.php:566` |
| The gate is **per filing**, not per permit | Enforced — the balance is tested once, then every permit type is minted. The payments ledger does not attribute a payment to a permit type, so a per-permit gate has nothing to compute from |
| **Re-assessment overwrites an officer-adjusted assessment** | Enforced as a consequence, not by intent: `assessFees()` uses `updateOrCreate` on `application_id`, and an officer's adjustment writes the same single row. No test asserts it |

Two of those deserve a footnote.

**"Applying after release is allowed by the data model but not surfaced on the
screen"** is what the code comment says, and it is no longer true. The link to
the clearance stage renders for every non-draft status including Approved
(`ApplicationDetailPage.tsx:670-685`), and the stage renders unlocked. The
behaviour is right and the comment is stale.

**The fee-gating decision is the one we most want overturned by an answer, not
by us.** Uploading a clearance you already hold escapes the Fire Code fee and
the sanitary inspection fee, because those fee rules are gated on the clearance
being applied for. That is arguably wrong under RA 9514. Changing what a citizen
is charged, on our own reading of a statute, is not a call a student project
should make without BPLO. It is written down rather than quietly fixed.

## The open risk, stated plainly

> **A clearance can be withdrawn after its fee has been paid, and the money has
> nowhere to go.**
>
> The withdraw path (`ClearanceController::unapply()`, lines 95-126) checks
> ownership, that the stage is unlocked, that the clearance is priceable, that it
> was applied for, that no permit has issued, and that the office has not yet
> acted. **It does not check whether the fee was paid.** So a clearance whose
> office is still `pending` can be withdrawn after payment.
>
> One correction to how this was described to us: the balance does **not** go
> negative. `PermitFees::balance()` floors at `0.0`, so the filing is left in
> silent credit rather than showing a negative figure. The test that locks this
> in (`ClearanceStageTest.php:566-581`) asserts exactly that — after apply, pay,
> withdraw, `total_paid − total_assessed` is ₱735.00 and `balance_due` is ₱0.00.
>
> On screen the applicant sees Paid greater than Assessed and "Balance due
> ₱0.00" rendered in green, as though nothing were owed in either direction. No
> wording anywhere names the credit or offers it back.
>
> This is newly reachable — under the one-payment design there was no moment
> between paying and withdrawing. It needs a BPLO answer: refund, credit against
> the next clearance, or forfeit. Refunds are not modelled at all, so whichever
> answer comes back is a piece of work, not a setting.

## On the screen

**The lock reason is printed verbatim.** `ClearanceStagePage.tsx:869-887` renders
`meta.locked_reason` and nothing else. The previous version put a fixed heading
above it — *"These can no longer be changed"* — and that heading was deleted
because the stage now has two opposite reasons for being locked. Not-yet-open
and closed-after-rejection are both locks, and a single heading is wrong about
one of them. The server writes six distinct sentences
(`ClearanceService::lockedReason()`, lines 307-328), including:

- Draft — *"Finish and submit this application first, then settle the Tax Order
  of Payment for your business permit. The six LGU clearances open here the
  moment that payment clears."*
- Rejected — *"This application was not approved, so no further clearances can be
  applied for under it. File a new application if you still need these
  clearances."*
- The catch-all — *"The LGU clearances open once the first payment on this
  application has cleared. Ours shows nothing settled yet — contact the BPLO if
  you have already paid."*

The same strings are the body of the 422 when a locked write is attempted, so the
screen and the API cannot disagree about why.

**The ledger shows while the stage is locked too** (lines 913-941, rendered
outside the locked branch). Assessed, paid, balance due. This looks like a
mistake and is not: when the stage is locked *because you have not paid*, the
amount you have to pay to unlock it is the single most useful thing on the page.

**`fee_preview` is back on each clearance card** (line 1085-1087). It had gone
dead — in the parent commit the `feeAmount` helper was exported and called from
nowhere in the entire frontend. Under the one-payment design that was survivable,
because there was a later screen showing the Tax Order of Payment before you
committed to it. There is no such screen now: Apply spends money immediately, so
the card has to say how much.

## Tests

The claim we were given was "ten tests renamed, seven new". The real numbers,
from pairing every test title before and after:

| File | Before | After | Renamed | New |
|---|---|---|---|---|
| `api/tests/Feature/ClearanceStageTest.php` | 35 | 42 | 12 | 7 |
| `web/e2e/clearances.spec.ts` | 13 | 14 | 4 | 1 |

So **16 renamed and 8 new**, not 10 and 7. (One PHP pairing is a judgement call;
counting it as a deletion plus an addition gives 11 renamed and 8 new in that
file.) "Seven new" is right for the PHP file taken alone, which is probably
where the figure came from.

The renames are the interesting part, because each one is a sentence that used to
state the opposite rule:

- `opens the stage while the application is still a draft`
  → `opens the stage the moment the first payment clears`
- `carries no ledger in meta, because a draft owes nothing`
  → `carries the ledger in meta, because applying raises a balance`
- `routes every chosen clearance to its own office when the payment clears`
  → `routes a clearance to its own office the moment it is applied for`
- `issues every permit once the offices sign off, with no balance to settle`
  → `releases no permit while the clearance balance is outstanding`

That last pair runs against the same fixture and asserts the opposite outcome,
which is what a genuine reversal of a rule looks like in a test suite.

The eight new cases cover the three bugs and the risk: the stage staying shut
while the Tax Order of Payment is unsettled, staying open on a returned filing,
the un-refunded fee, the sheet reachable after payment, release on the second
payment settling, release through the ordinary path when the balance clears
first, the outright refusal of `approveAndIssue` on a filing that owes money,
and — in the browser — `a locked Apply stays reachable, and refuses to do
anything`.
