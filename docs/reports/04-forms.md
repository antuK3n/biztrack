# 4. Office forms: asking each question once

## What changed, and why

The rule the client gave us is simple to state: **each office sheet asks only
what its paper form needs, minus what the wizard already holds.** A field the
applicant has already answered must not be asked again. Where the office
genuinely needs it on their form, it should be carried over and shown — not
re-collected.

We audited all six office sheets against that rule. One real duplicate was
found, and it was the damaging kind. Ten other fields that look like duplicates
were checked and left alone, and this report lists them so nobody has to check
them twice.

## How the rule is enforced

Carry-over is not a convention that a developer has to remember. It is a
function — `OfficeFormController::withDerived()`, lines 220-386 — whose last
line is:

```php
return $derived + $formData;
```

That is a PHP array union, in which the **left** operand wins. A derived answer
overwrites whatever the browser sent for that key. And it runs on both paths:
when the sheet is read (line 67) and again on every write (line 156). So a
client cannot supply its own value for a carried-over field, even by trying.

The comment above it puts the rule in one sentence: *"Overlay the answers the
system already holds on top of a payload. These always win: the paper form still
carries them, but nobody types them."*

The fields currently derived are `application_date` (every sheet),
`application_type` (Zoning, Sanitary, CEC, Market), `total_floor_area_sqm`,
`building_storeys`, `site_tenure`, `authorized_representative` (Zoning),
`workers_requiring_health_certs` (Sanitary) and `certificate_applied_for` (Fire).

Two details of the implementation are worth defending, because they look like
extra work:

- **Derived answers are shown, not silently carried.** They render read-only,
  tagged *"(from your application)"*. The reason is in the code: the applicant
  signs a statutory declaration on these sheets, and *"carrying them silently is
  worse than re-asking: the applicant signs a statutory declaration without ever
  seeing what it says about them."*
- **The sheet is synthesised on read**, even if it has never been saved, so the
  carried answers are visible the first time the applicant opens the form rather
  than appearing after the first save.

## The precedent: capitalization

This is the fault we are guarding against, and it has already happened once.

Capitalization used to be asked twice — once against each business line in the
picker, and again in Business & Tax Profile — and only the second answer was
ever assessed. So the two drifted the moment anyone edited the first, and the
one the applicant could see on the earlier screen was not the one they were
billed on.

It was removed on 5 August (commit `97eb5ab`, *"fix(apply): ask for capital
once, and say what the category actually is"*), and the tombstone is still in
the wizard at `ApplyWizard.tsx:275-281`:

> Across 400 filed applications not one pair disagreed, which is what one
> question asked twice looks like.

**Where that figure comes from.** It was measured against the live register on 5
August and recorded in prose, in the commit message and the code comment. There
is no fixture, test or seeder that asserts it, so it cannot be re-derived from
the repository alone, and the register has grown since. We are repeating it as
what it is: a measurement taken once, not a standing claim.

## The one real duplicate, and why it was the bad kind

The **Sanitary** sheet asked *"No. of Workers Requiring Health Certificates"* in
a free-text box (`OfficeFormStep.tsx`, lines 765-769 of the previous version).

Nothing read it. Searching the whole repository at the parent commit for
`workers_requiring_health_certs` returns exactly two hits — the input's own
`value` and its `onChange`. Zero hits in the API, zero in the fee engine, zero
in the officer's review sheet, zero in the tests.

Meanwhile the health certificate fee bills a different number. It is
**₱50 per employee per year** under **Sec. 4D.02** of the Revenue Code, and its
basis is `fee_profile.employees` — the headcount from Business & Tax Profile.

> **A correction.** We were told this fee is computed in
> `api/app/Support/PermitFees.php`. It is not; that file contains no fee logic at
> all — it is 118 lines holding the payment ledger helpers (`balance()`,
> `hasClearedPayment()`, `hasOutstandingBalance()`). The fee is a seeded rule in
> `api/database/data/revenue_code/sanitary_garbage.json`, lines 579-603, with
> `"section": "Sec. 4D.02"`, `"unit_amount": 50`, `"unit_key": "employees"` and a
> condition on the `employees_need_health_certificates` flag. It is evaluated by
> `api/app/Services/FeeCalculator.php` at lines 219 and 242. The rate, the
> section and the basis are all as described; only the file was wrong.

So this was not a cosmetic duplicate. Capitalization's two answers merely
drifted. These two land **side by side on one filing**: a headcount printed on
the City Health Office's own sheet, and a Tax Order of Payment computed for a
different headcount, for that same fee. An officer reading "4 workers" beside a
bill for five is looking at a discrepancy the applicant never made and cannot
explain.

The box is now derived and read-only (`OfficeFormController.php:345-356`):

```php
$derived['workers_requiring_health_certs'] = match (true) {
    ! $needsCertificates => 'None',
    is_numeric($employees) => (string) (int) $employees,
    // Flagged but no headcount: the profile is half-filled, so say
    // nothing rather than print a zero the office would act on.
    default => '',
};
```

Three cases, and the third is the careful one. When the applicant has said no
employee needs a certificate, it prints **"None"** — an answer, not a blank that
looks like an omission. When the flag is set but no headcount exists, it prints
**blank rather than `0`**, because a zero on a City Health form is a statement
the applicant did not make and the office might act on.

The screen says where the number came from: *"From the employee count on your
Business & Tax Profile — the same number the health certificate fee is charged
on."*

Locked by three tests in `api/tests/Feature/OfficeFormTest.php` — line 118 (a
posted value is overwritten by the profile's), line 137 (`'None'`), line 149
(the empty string).

## Checked, and genuinely distinct

These ten look like duplicates and are not. Each is listed with the reason, so
that the next audit does not repeat the work.

| Sheet | Field | Why it stays |
|---|---|---|
| Zoning | `zoning_project_description` | A free description of the project as CPDO assesses it. The wizard holds a line of business (a PSIC code), which is not the same statement |
| Zoning | `zoning_industrial_project_type` | Pollutive / Non-Pollutive / Hazardous / Non-Hazardous / Other. Nothing in the wizard classifies a business this way |
| CEC | `owner_birthday` | **No birthdate exists anywhere in the schema.** An exhaustive search of every migration for `birthday`, `birthdate`, `birth_date`, `date_of_birth`, `dob` and `born` returns zero hits, and neither `User` nor `BusinessOwner` has such a field. It lives only inside this sheet's JSON |
| Occupancy | `building_permit_no` | A number issued by another office, on another document |
| Occupancy | `fsec_no` | Likewise |
| Occupancy | Full vs partial occupancy | See below — this one is explicitly protected |
| Market | `market_name` | Which public market. The wizard holds an address, not a market |
| Market | `stall_no` | Likewise |
| Sanitary | `water_source` | A premises fact the wizard never asks for |
| Sanitary | `sanitary_classification` | The CHO's own classification of the establishment |

Two of the key names differ from how they were described to us: the Zoning
fields are `zoning_project_description` and `zoning_industrial_project_type`,
not the bare `project_description` and `project_type`.

The Occupancy full-vs-partial choice deserves its own note, because it is not
merely "left alone" — it is actively defended. It is stored under the key
`application_type`, which is the *same key* that is derived from the filing
(new / renewal / amendment) on four other sheets. The controller excludes
Occupancy from that derivation by name, with the reason written beside it
(lines 382-383): *"OCCUPANCY's own `application_type` is Full vs Partial
occupancy — a real applicant decision, not the new/renewal the system already
knows."* A test at `OfficeFormTest.php:161` holds it there.

That is a collision that would have quietly overwritten an applicant's answer
with an unrelated one, and it is the strongest argument in this section for
doing the audit at all.

## The honest limitation

We do not have all the paper forms, and every assumption made where the paper is
unknown is written down. But the blanket statement *"we do not have the paper
forms"* is broader than the truth, and the report should be precise:

- **Four sheets were transcribed from the real document.** `OfficeFormStep.tsx`
  lines 20-26 records it: *"The CHO, CENRO, BFP and OBO forms were always copied
  from the document the counter hands out, field label for field label, so what
  BizTrack asks is what the counter asks, word for word."*
- **The zoning form has been received.** `docs/questions-for-malabon.md` §E4 is
  marked ANSWERED: *Application for Locational Clearance (Business Activities)*,
  document ID **MCG-CPDD-FO-003**, version **1.2**, effective 01-09-2026.
- **One sheet has no paper behind it at all** — Market. Its own metadata says so:
  `'Interim form — the office has no printed version of this application'`.

The three BPLO form references the wizard is built against are real and exact,
in `ApplyWizard.tsx:265-269`:

```ts
new:       { title: 'Application for New Business Permit',        ref: 'MCG-BPLO-FO-001 · v2.0' },
renewal:   { title: 'Application for Renewal of Business Permit', ref: 'MCG-BPLO-FO-002 · v2.0' },
amendment: { title: 'Application for Amendment of Business Permit', ref: 'MCG-BPLO-FO-003 · v2.0' },
```

and the BPLO item numbers recorded in field comments — A5, A6, A9,
A13/A14/A15, B6, and B8 (B7 on the renewal form) — are all present. All of them
are in `ApplyWizard.tsx`; none are in `OfficeFormStep.tsx`, which is consistent
with the item numbers belonging to the BPLO form rather than to the office
sheets.

> **A dangling citation we found and did not fix.**
> `OfficeFormController.php:336-337` supports the health-certificate assumption
> by citing *"docs/questions-for-malabon.md §E — the CHO paper form has been
> requested and not received"*. Section E lists nine documents (E1-E9) and the
> CHO form is not among them. The citation points at nothing. The question is
> real, but it lives at **A4b**, not in §E — and §E should probably gain an entry
> for the CHO form, since the sheet was transcribed from a counter handout rather
> than from a controlled copy.

## The three questions logged

Added to `docs/questions-for-malabon.md` by this commit, in the house style: the
question as a heading, the facts as they stand, **Why it matters**, and **What we
assumed meanwhile**.

**A4b. On the CHO sheet, is "No. of Workers Requiring Health Certificates" the
same headcount we bill?** — with a fourth beat, because a wrong assumption here
costs twice: if CHO means a narrower subset (the office staff of a food
establishment excluded, say) then it is a genuinely separate question we owe them
*and* the fee is billing the wrong headcount today.

**A20b. And when the business has no permit in BizTrack?** — the paper-permit
escape has to be ticked, not fallen into.

**A20c. Does an amendment also name the permit it amends?** — if the paper
amendment form has no permit-number box, this is one field too many and we take
it out.

Two small corrections about the logging itself. **A20b is not a peer entry** —
it is bold text inside the body of A20, so it does not appear in a heading index
the way A4b and A20c do. And the house style has **two** recurring labels, not
three: `**Why it matters.**` appears on 58 of the 66 question headings, and
`**What we assumed meanwhile.**` on 20 — it is used only where something has
actually been shipped on a guess, which is the correct use.
