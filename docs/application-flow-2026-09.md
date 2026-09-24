# The application flow — 6 September 2026

**This is the live spec.** It supersedes both `clearances-before-payment.md` and
`clearances-after-payment.md`, which contradicted each other and the code (see
the note at the end).

Verified with the client against the actual counter procedure on 6 September
2026. The general workflow below is theirs, in their notation: **B** business
owner, **BPLO** the BPLO office admin, **OP** any of the other permit offices,
**S** the system.

```
Answer and Submit Whole Application Form            (B)
  > Status: For Approval          — BPLO form only  (S)
  > Approve the BPLO application form               (BPLO)
  > Status: Pending Payment                         (S)
  > Pay                                             (B)
  > Status: Awaiting Other Permits                  (S)
  > Submit and complete the other permits           (B)
  > Status: For Approval          — that permit     (S)
  > Approve that permit's application               (OP)
  > Status: For Inspection        — that permit     (S)
  > Select inspection date, then approve inspection (OP)
  > Approve the overall application, once every
    other permit is approved                        (BPLO)
```

In prose: the owner applies, BPLO approves the form, the owner pays, and only
then do the other permits open. Each of those runs its own review and its own
inspection, and **each permit is released the moment its own office approves
it** — no permit waits for another. When they are all approved, and only then,
BPLO approves the overall application and the business permit is released.

## What changed, and why the old flow was wrong

The old flow took payment first and routed every office at once, so BPLO was
just another assignment row and the business permit came out of the same
mechanism as the six clearances. That is not how the counter works. BPLO reads
the form *before* the applicant is asked for money — a filing with the wrong
line of business should not be paid for — and BPLO signs the whole thing off at
the end, because the Mayor's Permit is what the other six are evidence *for*.

Three specific reversals:

1. **Review now precedes payment.** Was `pending_payment → under_review`; is
   now `for_approval → pending_payment`.
2. **The clearances open on payment**, not on submission. This is what the
   after-payment doc originally specified; the gate was moved to submission on
   2 September because payment was a dummy that never cleared. Payment is real
   enough for this purpose now — it is the applicant's own action and it
   completes synchronously — so the gate goes back where the procedure puts it.
3. **BPLO acts twice**, and the second act is a new thing the system did not
   have: an overall approval, gated on every other permit being approved.

## The two state machines

Status stopped being one column. It has to: the application has a status, and
each of the other permits has its own, and they run at the same time.

### Application — `applications.status`

```
draft ──────────────> for_approval ──────> pending_payment
                        │    ▲                    │
                        │    │ resubmit           │ pay
                   return    │                    v
                        v    │            awaiting_other_permits
                      returned                    │
                                                  │ every required permit
                                                  │ approved → issue the
                                                  │ Mayor's Permit
                                                  v
                                              approved
```

*(`for_final_approval` was the fifth stop until 18 September 2026 — see below.)*

`rejected` is reachable from every non-terminal status; `cancelled` only before
BPLO has started reading. `approved`, `rejected` and `cancelled` are terminal.

**`under_review` and `for_inspection` are gone from this machine.** They
described work that now belongs to an individual permit, and leaving them here
would mean an application "For Inspection" while four of its six permits are
being read and a fifth has already been issued — a status that answers a
question nobody asked.

**`for_final_approval` is off this path since 18 September 2026.** It was never
in the client's list — it was added here deliberately, because their step reads
"approve the overall application once other permits are all approved" and a
status was how that filing reached BPLO's queue.

The client then asked what BPLO was reading when it got there:

> What is the purpose of the BPLO checking if all other permits are legit, when
> those permits are APPLIED DIRECTLY in BizTrack itself?

> **Superseded 24 September 2026.** The passage below describes where the
> Mayor's Permit was issued between 18 and 24 September. The LGU has since
> moved the release to PAYMENT — see *The permit is released at payment* at
> the foot of this file. The reasoning here still stands for why BPLO's
> final approval is not on the new-application path; only the moment of
> issuance moved.

Nothing, on this path. Every clearance is applied for in BizTrack, approved by
its own office in BizTrack, and inspected against a pivot row in BizTrack. BPLO
re-reading them was the system checking its own records against itself — and
because `deadline_at` is `submitted_at + statutory working days`
(`Ra11032::deadlineFor`), the RA 11032 clock ran the whole time it waited. So
`refreshReadiness()` now issues the Mayor's Permit as the last clearance is
approved, in the same transaction.

Two arguments for keeping it were considered and withdrawn:

- **the walk-back edge** (`for_final_approval → awaiting_other_permits`, for a
  permit that stops qualifying) only ever protected the window *between*
  readiness and BPLO's press. Auto-issuing makes that window zero, so the
  protection existed because the wait did.
- **the last chance to refuse** is real but thin: `rejectApplication` has no
  status guard and `PermitStatus::Revoked` exists, so refusal after issuance is
  possible. "Never issued" is tidier than "revoked", not more capable.

What remained was a governance question — does a Mayor's Permit exist because an
officer released it or because the system concluded the conditions were met? The
client chose the system.

**The status still exists and is still reached, twice:**

1. **A renewal** stops there. On that path BPLO reads certificate copies the
   applicant uploaded, which is real evidence of outside provenance rather than
   a re-reading of our own records. Explicitly out of scope of this change.
2. **A filing with no confirmed RA 11032 processing category** falls back to it,
   because `approveOverall` requires one and auto-issue must not throw inside a
   clearance office's own Approve press. There BPLO has real work: confirm the
   category against the Citizen's Charter, then approve. `approveMainForm`
   already guards this, so it should be unreachable — one pre-guard filing in
   the register is at that status today, which is why the branch is live.

### Each other permit — `application_permit_types.status`

One row per (application, permit type) already exists as the M:N pivot and
carries nothing but the link. The per-permit status lives there.

```
not_started ──apply or upload──> for_approval ──OP approves──> for_inspection
                                   │      ▲                          │
                              return      │ resubmit         date, then pass
                                   v      │                          v
                                 returned─┘                       approved
                                                                (permit issued)
```

`rejected` from `for_approval` or `for_inspection`. **A rejected permit does not
kill the application** — it blocks `for_final_approval` until the applicant
re-files that one permit. The other five are untouched, and any already issued
stay issued.

The `BUSINESS` pivot row is the exception: it tracks the main form, so it goes
`for_approval → approved` and is issued at BPLO's overall approval.

## Rules

1. **Five permits are required**: Sanitary (CHO), Fire Safety (BFP), Zoning
   (CPDO), Occupancy (OBO), Environmental (CENRO). All five must reach
   `approved` before the application can reach `for_final_approval`.

2. **Market Clearance is optional.** It is for stall owners in the public
   market, which most businesses are not, so it never blocks the final
   approval. If an owner needed one and did not know, BPLO asks for it through
   Other Requirements — a mechanism that is *not built yet* and is out of scope
   here.

3. **Apply or upload, and both are reviewed.** For each required permit the
   owner either fills that office's form or uploads the permit they already
   hold. An upload shows the OP admin the image and nothing else — there is no
   form to read. **Both paths still get a site inspection**, because the LGU
   inspects the premises, not the paperwork.

4. **One bill, raised at submission, covering everything.** The Tax Order of
   Payment covers the business permit and every required permit (plus Market if
   chosen). **It charges for a permit whether the owner applies or uploads** —
   the fee covers the inspection, and an uploaded permit is inspected too.

5. **The fee is system-computed and BPLO cannot adjust it.** It comes from the
   revenue-code rules in `FeeCalculator`. The applicant sees one figure and it
   is the one they pay.

6. **The inspection is two steps.** The OP admin picks a date — it is no longer
   auto-scheduled two working days out — and afterwards records the result. A
   pass approves the permit and issues it. A failure is **kept**, and a
   re-inspection is booked against it; the client asked for a record that shows
   a business failed once and passed later, and overwriting the failure is the
   one thing that would destroy it.

7. **Each permit releases on its own approval.** The moment an OP office passes
   its inspection, that permit is issued. Nothing waits.

8. **The business permit releases on BPLO's overall approval**, and that
   approval is refused while any required permit is not `approved`.

## Assumptions — not decided by the client, recorded per AGENTS.md §8

Each of these is a guess made to keep building. If an answer contradicts one,
the system changes; none of them are defended.

- **Market Clearance is opted into on the main application form.** The bill is
  raised at submission and covers everything, so a Market Clearance chosen
  later would have no way to be paid for. *If BPLO wants it choosable after
  payment, the single-bill rule breaks and a second payment comes back.*

- **The RA 11032 processing category is set by BPLO at the first approval**, and
  required there. The client's standing rule is that an application cannot be
  approved without one; BPLO's first approval is the earliest point a human
  reads the filing, and it is the one office that sees every filing. OP offices
  no longer set it. *If the category is meant to be per-permit, this is wrong.*

- **A rejected permit can be re-filed by the owner**, returning that row to
  `not_started`. The alternative — a rejection is final and the whole
  application dies with it — contradicts rule 8's "only that permit dies".

- **Cancellation stays the applicant's, and only before BPLO starts reading.**
  Unchanged from the old flow; nobody asked for it to move.

## Why the two old docs both had to go

`clearances-after-payment.md` is headed `SUPERSEDED` and points at
`clearances-before-payment.md`. `AGENTS.md` §11 says the opposite — that the
after-payment doc is live and the headers need swapping. **Both were wrong about
the code**: `ClearanceService::isUnlocked` gated on submission, not payment and
not choice-before-submission, following a client instruction on 2 September that
neither doc records.

That is three sources disagreeing about the one thing a reader most needs. Both
old docs are now headed SUPERSEDED and point here, and this file is the only one
describing the flow.


## The permit is released at payment — 24 September 2026

The client, having clarified the new-application flow with the LGU:

> After payment, business permit is already released, but can be suspended if
> the other permits applied to were rejected.

This inverts the dependency the rest of this file was written around. The
business permit used to be the LAST thing to happen — withheld until all five
clearances were approved, first by BPLO's press and then, from 18 September, by
`refreshReadiness()` in the same transaction as the fifth approval. It is now
the FIRST thing that happens after the money lands, and the clearances are
conditions attached to a certificate the applicant is already holding.

**What moved:** `WorkflowService::releaseOutcomePermit()`, called from
`onPaymentCompleted()` on the same `$backToBplo` test that routes the filing —
so a NEW filing releases and a renewal or amendment does not, both of which have
a real BPLO act left. `approveOverall()` still mints the permit if it is somehow
absent, and its existing `status !== Approved` guard means it now does nothing
on the ordinary path.

**What the sanction became.** Withholding is no longer available, because there
is nothing left to withhold. So `ClearanceStatus::Rejected` is back — deleted on
17 September on the reasoning that Return was enough, which was correct while a
refusal cost the applicant only time. A refusal now costs them their business
permit, and `Returned` cannot carry that: a return is fixable and repeats, and
suspending a permit every time an office asked for a clearer scan would be
absurd.

**The three states, and who sets them:**

| act | who | effect on the business permit |
| --- | --- | --- |
| Return | any office | none — fixable, repeats |
| Reject | the five clearance offices | **Suspended**, automatically |
| Reject application | BPLO | the filing ends; this is unchanged |

BPLO cannot reach the office Reject (`rejectAssignment` refuses it). The row it
holds is the BUSINESS one — the row that issued the certificate — so refusing it
would suspend the permit for the absence of itself.

**Suspension is automatic and the lift is human.** The client chose this over a
BPLO decision queue: a business whose fire clearance has been refused should not
keep trading because nobody opened a queue that morning. BPLO lifts it through
`POST /permits/{permit}/lift-suspension`, behind `permit.issue`, with a recorded
reason. A lift does NOT clear the refusal — the permit that was refused is still
the issuing office's decision.

**Reinstatement is automatic and mirrors the suspension.**
`reconsiderSuspension()` asks "is anything on this filing still refused" rather
than "was this the one that caused it", so two offices refusing produces one
suspension that survives until both are settled.

**The way back needed three guards opened**, all of which were written for a
world with no clearance-level refusal and all of which silently blocked the
route the client chose:

- `ClearanceService::isAppliedFor` counted a refused permit as applied-for, so
  `apply` answered *"You have already applied for the …"*;
- `OfficeFormController::ownerMayEdit` kept the sheet locked, so applying again
  would have opened a form nobody could type in;
- `WorkflowService::submitClearanceForm` returned silently for any status but
  NotStarted and Returned, so pressing Submit moved nothing and the office's
  Approve then failed with *"A Rejected permit cannot become For Inspection."*

Only the third was reachable in a test that stopped at the first, which is why
`PermitReleasedAtPaymentTest` walks the whole loop — apply, fill, submit,
approve, inspect — rather than asserting on the pivot.

**What did not change, and was checked:** the certificate PDF already stamps its
status across the face for anything but Active, and `/verify/{number}` already
answers invalid for a non-Active permit. Both were built for `Revoked` and cover
`Suspended` without alteration.

**The stage was renamed.** `awaiting_other_permits` keeps its value and is now
labelled **Permit Released** — the filing is still waiting on five offices, but
the person reading the tracker is not waiting for anything, and "Awaiting" over
a certificate they have already downloaded is the tracker contradicting the
vault. BPLO's queue tab keeps the old words, because from that seat the filing
genuinely is out with the other offices.
