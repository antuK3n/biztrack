# Restructure: the six LGU clearances move after payment

Decided with the client on 3 August 2026. This supersedes the ordering
rationale currently written above `BASE_PHASES` in `ApplyWizard.tsx`, and it
finally satisfies checklist item 76 ("this should be placed at the last part
before submitting the application") in the sense the client meant it.

## Why the old arrangement was wrong

The wizard asked which clearances you wanted at step 4 of 8, and the two
sections after it were computed from that answer — `requiredDocs` is the union
of the document types on the selected permit types, and the tax profile's
questions vary by permit code. That data dependency is why the cards could not
simply be moved later, and it was documented as a deviation rather than fixed.

The dependency only exists because the clearances were being treated as part of
the same filing. They are not. Each is a separate transaction with a separate
office, a separate fee, and a separate outcome. Once they are their own stage,
the dependency dissolves: the wizard's documents and fees describe the business
permit alone, and each clearance carries its own.

## The shape

```
WIZARD — the business permit application
  1 Data Privacy Consent
  2 Location & Zoning        (+ line of business)
  3 Business Information
  4 Documentary Requirements   business permit documents only
  5 Business & Tax Profile     business permit fees only
  6 Review & Submit
        |
        v
  SUBMITTED  ->  TOP #1 (business permit)  ->  PAID
        |
        v
LGU CLEARANCES — opens once the first payment clears
  [ Zoning ] [ Sanitary ] [ Fire ] [ Environmental ] [ Occupancy ] [ Market ]

  Apply   -> that office's form opens. Its fee is added to the balance.
  Submit  -> upload the copy you already hold. No fee, no form.
        |
        v
  BALANCE DUE must reach zero before the permit is released
```

## Rules

1. **The wizard no longer has a `permits` phase**, and no office form sheets.
   `BASE_PHASES` becomes `privacy, address, business, documents, fees, review`.
2. **The Mayor's / Business Permit stays implicit**, as it already is — it is
   the outcome of the application, not a clearance to pick.
3. **The clearance stage opens on payment**, not on submission. Before that it
   is visible but locked, with the reason stated.
4. **Apply always opens that office's form. Submit always opens the upload
   box.** Neither toggles. Un-applying and removing an upload are their own
   labelled controls. (Fixed already in `aabbf21` — carry the semantics over,
   do not reintroduce the toggle.)
5. **Fees accrue, and the engine already supports it.** Checked rather than
   assumed, because "do the clearances even cost anything?" is a fair question
   and `permit_types.base_fee` is a red herring — `WorkflowService::assessFees`
   treats it as a legacy fallback used only when the revenue-code rules match
   nothing.

   The real Tax Order of Payment comes from `FeeCalculator`, and it already
   itemises per office. A live assessment (application 3377, ₱10,801) contains:

   | Office | Example lines |
   |---|---|
   | CPDO | locational clearance filing ₱45, land use verification ₱345, processing ₱345 |
   | CHO | health certificate per employee ₱100, sanitary inspection ₱660, garbage fee ₱1,650 |
   | BFP | Fire Safety Inspection Certificate fee ₱881 (10% of the mayor's permit + regulatory fees, RA 9514 — derived, not flat) |
   | OBO | Certificate of Use/Occupancy ₱200, certified true copy ₱50 |
   | CMO-MARKET | stall rental, officer-set |

   Note ₱45 + ₱345 + ₱345 = ₱735, which is exactly the `base_fee` seeded on the
   ZONING permit type — the seed was copied from the code, then superseded.

   Crucially, `FeeCalculator::assess` gates every rule on the selected permit
   types (`array_intersect($r->permit_types, $requested)`), so a clearance's
   lines appear **only if that clearance was picked**. Re-running the assessment
   after a clearance is applied for therefore produces exactly the additional
   lines and nothing else. The accrual is a re-assessment plus a record of what
   has already been paid, not a new pricing model.

   Submitting a copy you already hold adds nothing, because that permit type is
   not added to the application.
6. **The permit is not released while a balance is outstanding.** This is the
   gate that makes the accrual real; without it the balance is decoration.
7. **Each clearance routes to its own office** as an assignment when applied
   for, not when the application is submitted.

## Open, and deliberately not guessed

- **Can a clearance be applied for after the permit is released?** A business
  that adds a food line mid-year needs a sanitary permit it did not need in
  January. Assumed yes, but not built.
- **What happens to a rejected clearance** — does it block the business permit,
  or stand alone? This is the same unanswered question as checklist item 80
  (`AssignmentStatus` has no `Rejected` case), and it should be answered once
  for both.
- **Are clearance fees refundable** if the applicant withdraws before the
  office acts? Not modelled.

---

## API contract for the rebuild

Fixed here so the backend and the screen can be built against the same thing.

```
GET   /api/v1/applications/{id}/clearances
      -> { data: [ {
             permit_type: {id, code, name, department:{code,name}},
             state: 'available' | 'applied' | 'submitted' | 'issued' | 'rejected',
             has_office_form: bool,
             office_form_complete: bool,
             held_document: {id, name, size} | null,
             assignment: {id, status, remarks} | null,
             fee_preview: string | null      // what applying would add
           } ],
           meta: { unlocked: bool, locked_reason: string|null,
                   total_assessed, total_paid, balance_due } }

POST  /api/v1/applications/{id}/clearances/{code}/apply
      -> re-assesses, returns the clearance row + new balance
DELETE/api/v1/applications/{id}/clearances/{code}/apply      (un-apply)
POST  /api/v1/applications/{id}/clearances/{code}/held       (multipart: file)
DELETE/api/v1/applications/{id}/clearances/{code}/held
```

`unlocked` is false until the first payment clears; `locked_reason` is the
sentence the screen shows instead of guessing one.

## Decisions taken to keep moving, to be confirmed later

These are assumptions, not answers. Every one is in
`docs/questions-for-malabon.md`.

1. **The applicant chooses.** The six are presented as a chooser, because that
   is what was asked for. If BPLO in fact determines which clearances a
   business needs from its line and location, this becomes a computed checklist
   and the screen changes shape.
2. **Fee gating is left exactly as it is.** The Fire Code fee and the sanitary
   inspection fee stay gated on their clearance, so uploading a held copy still
   escapes them. That is arguably wrong under RA 9514, but changing what a
   citizen is charged on our own reading of a statute is not a call to make
   without BPLO. It is written down rather than quietly fixed.
3. **One ledger, two moments.** The business permit is paid to submit; each
   clearance applied for adds to a balance settled before release.
4. **Applying for a clearance after release is allowed** by the data model but
   is not built into the screen.
5. **A rejected clearance does not kill the application.** It stays as its own
   failed item — the same open question as checklist item 80.
