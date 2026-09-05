# SUPERSEDED — 6 September 2026

The live spec is `docs/application-flow-2026-09.md`, verified with the client
against the actual counter procedure. Review now precedes payment, the other
permits open on payment, each is inspected and released on its own, and BPLO
approves twice. Nothing below describes the system as it is.

---

# Everything is submitted, then paid for once

Decided 4 August 2026, replacing `docs/clearances-after-payment.md`.

## The flow

```
WIZARD — one application, everything decided inside it
  1 Data Privacy Consent
  2 Location & Zoning        (+ line of business)
  3 Business Information
  4 Documentary Requirements   business permit documents
  5 Business & Tax Profile
  6 LGU Clearances             the six — Apply, or Submit a copy you hold
      + one office sheet per clearance applied for
  7 Review & Submit
        |
        v
  SUBMITTED
        |
        v
  ONE Tax Order of Payment — business permit + every clearance chosen
        |
        v
  PAID  ->  UNDER REVIEW  ->  ...  ->  permit released
```

Payment is the last thing the applicant does before the office takes over, and
it is charged once.

## Why this is simpler than what it replaces

The previous design put the clearances after payment, which forced four
mechanisms into existence:

- a fee assessment that accrued as clearances were added
- a second payment to settle the difference
- a gate holding the permit until that balance cleared
- a stage that had to be locked, and a sentence explaining why

Every one of those grew a bug, and one of them shipped a dead end: the balance
was payable only through an endpoint that refused every status the application
could actually be in, so an applicant could run up a bill they could see, could
not pay, and which blocked the permit they were waiting for.

None of it is needed here. The assessment runs once, at submit, when every
choice has already been made.

## The dependency that started all this is gone

The original objection to putting the clearances last was that
`requiredDocs` was the union of the document types on the selected permit
types, and the tax profile's questions varied by permit code — so both steps
were computed from an answer given later.

That is no longer true, and not because of ordering:

- the wizard's Documentary Requirements step describes the **business permit
  alone**; each clearance's own documents live on its office sheet
- the Tax Order of Payment is produced at **submit**, after the clearances are
  chosen, so nothing is computed from a blank list

## What changes

1. **`ClearanceService::isUnlocked`** stops asking whether a payment has
   cleared. The clearances are available while the application is still being
   filled in, and close when it is submitted.
2. **`lockedReason`** changes with it — before submission there is nothing to
   explain, and after submission the reason is that the filing is with the
   office.
3. **The clearance stage becomes the last step before Review & Submit**, which
   is what checklist item 76 asked for in the first place.
4. **The balance gate on issuance is removed.** With one payment before review,
   a balance cannot be outstanding when the offices finish.
5. **The accrual disappears.** `assessFees` at submit already covers every
   selected permit type; `FeeCalculator` gates each rule on those types, so the
   clearances chosen are exactly the office lines billed.

## Two things this cost that were not in the original list

Both were found while building it, and both are the opposite of what the
"what is kept" list below originally said.

**Routing moved back to payment.** The plan was to keep raising an assignment
at the moment a clearance is applied for. That is wrong once applying happens
in a draft: `application_assignments.assigned_at` is the start of the office's
service-time clock, and `ProcessingTimeAnalytics`, `StaffingSimulation` and
`DashboardAnalytics` all measure `assigned_at → completed_at`. An applicant who
left a draft open for a week would have added a week to that office's measured
turnaround. `WorkflowService::routeToDepartments` already raises one assignment
per department owning a requested permit type and runs when the payment clears,
by which point every clearance is on the filing — so `routeClearance()` and
`withdrawClearanceRouting()` were deleted rather than re-pointed.

**Returned filings are not reopened to the six.** A returned filing is editable
everywhere else (documents, office sheets), but it has already been assessed
and paid. Letting it gain a chargeable clearance would raise exactly the
difference this change exists to delete — with no gate left to make it real.
Draft only, and the locked reason for a returned filing says to message the
office. Whether BPLO wants a returned filing to be able to add a clearance is
a question for them, not something to leave a door open for.

## What is kept

- The clearance cards, and the Apply / Submit semantics: **Apply always opens
  that office's form, Submit always opens the upload box.** Neither toggles.
- Per-office routing — one assignment per issuing office — now raised at
  payment rather than at the moment the card is ticked.
- The requirement that at least one clearance is decided before the step
  passes — checklist item 76's other half.
- `PaymentController` charging the outstanding balance rather than the
  assessment total. With one payment those are the same number, and the code is
  correct either way.

## Verified, not assumed

A filing carrying all six clearances, submitted through the wizard and read
back from `/applications/{id}/fee` (application 3404 on the throwaway stack):

| Office | Lines |
|---|---|
| BPLO | application filing fee ₱100, business plates ₱220, sticker ₱55, business tax ₱675 |
| OBO | certified true copy of Certificate of Use/Occupancy ₱50, other OBO certifications ₱50 |
| CPDO | locational clearance filing ₱45, land use verification ₱345, processing ₱345 |
| CHO | sanitary inspection ₱660, garbage fee ₱825 |
| BFP | Fire Safety Inspection Certificate fee ₱226 |
| CMO-MARKET | stall rental ₱0 (officer-set) |

**₱3,596.00**, charged once. The filing moved to `under_review`, and a second
payment was refused with "This application has nothing outstanding."

## Still open, unchanged by this

- Whether the applicant **chooses** the six or BPLO determines them from the
  line of business and location. If BPLO determines them, the stage becomes a
  computed checklist rather than a chooser. See
  `docs/questions-for-malabon.md` A1.
- Whether the Fire Code fee and the sanitary inspection fee are annual charges
  on the business, wrongly gated on their clearance so that uploading a held
  copy escapes them. A3 and A4.
