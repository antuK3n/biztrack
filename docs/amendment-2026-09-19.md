# The amendment process, as decided 19 September 2026

Client: *"We have clarified with the LGU that only business permit details can
be amended."* Written against the LGU's own **BPLO Amendment Form**, which is
the source for every kind listed below.

---

## 1. An amendment carries the business permit alone

It used to carry all six types. `permitTypeIdsAtSubmission()` special-cased only
`Renewal`, so an amendment fell through to the new-filing branch and attached
the business permit **and five clearances** — then billed for five certificates
nobody asked for, routed to five offices with nothing to review, and walked
through Awaiting Other Permits waiting on permits that were never applied for.

A floor-area correction cannot require a fresh Fire Safety inspection. Fixed.

## 2. The applicant states the new value; approval applies it

The client asked whether an officer should change the business details by hand.
**No.** A hand-retyped register is two records of one fact kept in step by
somebody remembering to: the filing says 140 sqm, the business says 120, and
nothing on either row says which was meant or who last looked.

So an amendment carries structured rows — `application_amendments`, one per
field, with `new_value` — and `WorkflowService::approveAmendment()` writes them
in one transaction. The officer's act is a **decision**, not data entry: read
the affidavit and the supporting documents, then approve.

`old_value` is captured at **application** time, not at request time. What an
amendment overwrote is the value at the moment of writing; a business whose area
was corrected in between would otherwise carry a row claiming to have replaced a
figure that had already gone.

## 3. The statuses

    draft → for_approval → approved

**Two moves, and no payment stage at all.** Client, 19 September 2026: *"All
amendment payments will reflect when a business permit is renewed, just like the
payments for other permits."* So an amendment defers exactly the way §7 of
`docs/renewal-2026-09-17.md` made the other permits defer — the LGU collects at
the January counter, and a mid-year filing asking separately for money would be
a second trip nobody wanted.

With nothing to pay, there is nothing to wait for between BPLO reading the
affidavit and BPLO completing it, so the two acts this flow first had collapsed
into one. `approveMainForm` delegates to `approveAmendment`, which applies the
changes, reprints the permit and closes the filing in one transaction.

This also removed a mis-billing. `assessFees` prices the permits a filing
carries, and an amendment carries the business permit — so it quoted **₱6,425
for a floor-area correction** (measured), of which ₱6,050 was the Mayor's Permit
fee. No assessment is raised now: a Tax Order of Payment is a figure people
actually pay, so a confidently wrong number is worse than none.

No `awaiting_other_permits`: there are no other permits on the filing. Detours
are the usual three — `returned`, `rejected`, `cancelled` — and Return matters
more here than elsewhere, because "your affidavit does not name the amendment"
is the commonest refusal and is entirely fixable.

**No table change was needed** for the statuses.

## 3a. The permit is reprinted over the same term

Client's decision: reissue and supersede, inheriting the old expiry. The old
certificate's face is wrong the moment the amendment lands — that is the point
of amending — and two live permits with different details and nothing saying
which to believe is the state `issuePermitFor` already refuses to leave a
renewal in.

`issuePermitFor` is **not** reused, and the reason is the whole of it. That
method CONTINUES a term: a renewal issued while its predecessor is live starts
the day after the old one ends. Right for a renewal, and it would hand an
amendment a free extra year. The reissue copies both dates exactly — amending in
June buys no time.

Nothing is reprinted when there is no live permit to reprint. An amendment filed
against an expired or revoked permit changes the register, and minting a fresh
certificate off that would hand the business something it is not entitled to.

## 3b. A permit now says what was true when it was signed

Found while answering the client's question about whether an address change
should "reflect on the other permits". It already did, and that was the bug.

`PermitController` assembled the certificate face — owner, trade name, address,
barangay, lines of business — from the **live business record at download
time**. Nothing about the certificate was stored. So editing a business
retroactively rewrote every permit it had ever held:

- a permit downloaded last month and the same permit downloaded today could
  differ, with nothing able to say what it used to read;
- reprinting on amendment would have been pointless, because the old certificate
  was already showing the new details;
- and the serious one: a Sanitary Permit is CHO's statement that **these
  premises** were inspected. Change the address and its face asserts CHO
  inspected somewhere it has never been — a false statement over the LGU's
  signature, produced by a screen nobody thought of as writing anything.

`permits.issued_details` is now captured at issue by `App\Support\PermitFace`,
which is also the live fallback for the 8 permits issued before the column
existed. They are **not** back-filled: what their business looked like on the day
is not recoverable, and filling from today's record would manufacture provenance.

`permits.document_hash` has existed all along and nothing ever wrote it
(0 of 8 rows). Something was intended there, and live rendering is exactly what
would have made a hash meaningless.

## 3c. The offices are told, twice over

Client's decision: a derived flag **and** a notification, for changes that alter
what an office verified.

- **Durable** — `PermitFace::changedSince()` computes the drift between a
  certificate's frozen face and the register, for as long as the permit exists.
  "This was issued for a different address" still answers itself in two years.
- **Timely** — `tellOfficesAboutAmendment()` pushes a notice to every office
  that has **certified this business**, not all five: an office that never
  granted it anything holds no certificate this could have invalidated.

Only `AmendableFields::OFFICE_VISIBLE` fires it — the street address today,
line of business when built. A corrected employee count tells CHO nothing it
acted on, and notifying for that is how five accounts learn to ignore the
notices that matter.

## 4. What can be amended, and what cannot

`App\Support\AmendableFields` is a whitelist. A field absent from it is
**refused by name**, not dropped — an amendment that quietly discards one of
five requested changes is worse than one that says no, because the applicant
leaves believing they asked.

| Box on the LGU form              | Where it lives                  | Built |
| -------------------------------- | ------------------------------- | ----- |
| AMENDMENT OF AREA                | `businesses.business_area_sqm`  | ✅ |
| OTHERS (employees, vehicle, etc) | the employee / vehicle counts   | ✅ |
| III. CHANGE OF TRADE NAME        | `businesses.trade_name`         | ✅ |
| I. CHANGE OF ADDRESS             | `business_addresses.line1`      | part |
| II. CHANGE OF OWNERSHIP          | `owner_user_id` + owners        | ⛔ |
| CHANGE OF LINE OF BUSINESS       | the `lines` relation            | ⛔ |
| ADDITIONAL LINE OF BUSINESS      | a new `lines` row               | ⛔ |

The three built ones are scalar columns: one value in, one out, reversible by
reading `old_value` back.

**Address is half built.** The street line is amendable; the barangay and the
map pin are not. A renumbered street inside the same barangay is a detail; a
move to another barangay changes which zoning rules apply and which CPDO officer
covers it.

The rest are refused rather than half-applied, for two different reasons that
decide what building them would mean:

- **Lines are a relation the money depends on.** A line of business carries a
  PSIC code and a capitalisation that **the fee assessment is computed from**, so
  applying one correctly means deciding what happens to the fee already paid.
  That is a product question, not a mapping.
- **Ownership is legal.** The paper asks for a Deed of Transfer, an affidavit of
  self-adjudication, or an extra-judicial settlement of a deceased owner's
  estate. Transferring a business between people on the strength of an automated
  approval is not something the system should be able to do by itself.

---

## Open, and flagged rather than guessed

- **How much does an amendment cost?** The MECHANISM is built and the amount
  is not known. Client, 19 September 2026: *"every other permit renewal and
  every amendment done before business permit renewal on January will have
  their fee amounts stacked up until they are ready to be paid on the business
  permit renewal on January."* So an approved amendment now writes an
  `unbilled_permit_fees` row, swept by the same `sweepDeferredFees()` the
  deferred clearance fees use.

  The amount is `WorkflowService::AMENDMENT_FEE`, currently **₱0 — because
  nobody has told us, not because it is free**. A10-2016 as seeded carries no
  amendment fee (0 of its rules mention one), so any figure would be invented,
  and an invented number on a Tax Order of Payment is one somebody pays. The
  row is written at ₱0 anyway: the stacking is then real and visible, the
  January line already says what it is for, and the day BPLO names a price it
  is one constant and nothing else changes.
- **Can a business move BARANGAY?** Only the street line is amendable today. A
  move across barangays is still a counter visit.
- **Does a move need the clearances redone?** The client chose notification over
  re-inspection: the offices are told, and each decides. Worth revisiting if a
  business ever moves far enough that a certificate is plainly void rather than
  merely out of date.
- **Zoning clearance as a requirement.** For the address and line-of-business
  boxes the paper lists it among the documents the applicant brings. Modelled as
  an upload rather than a routed office.

## The screens

**Applicant** — a *New Details* block on the existing Changes Since Last Permit
step. Section A above it records WHAT changed; this records what it changed to,
which is what every box on the LGU paper has a blank for. Each amendable detail
shows the register's value beside an input, saved on blur; clearing a box
withdraws that request rather than asking to blank the register.

**Officer** — a *changes this amendment asks for* panel leading the review
sheet, since it is the whole of the work. Before → after, with the "before"
switching source after approval: the register as it stands until then,
`old_value` afterwards, because a business whose area was corrected in between
would otherwise show a before that had already gone.

**A third submit gate.** An amendment must now name at least one new value, not
only tick Section A. Without it `approveAmendment` applied an empty set and the
filing closed as approved having changed nothing — a success at doing nothing,
which is worse than a refusal because everybody downstream believes it worked.
Ordered after the prior-permit gate: which permit you are amending is the more
fundamental question.

The status guide's Amendment pill now draws the real two-step rail instead of
saying the process is being settled, with a note on what can and cannot be
amended — the commonest wrong turn is filing one to change something it cannot
touch.

Tests: `AmendmentFlowTest`, 17 cases.
