# 02 — Office separability on the review sheet, and the Edit-mode copy

Investigation only. Nothing in this document has been changed in the tree. All paths are
repo-relative to `/Users/kenmondragon/Documents/GitHub/biztrack`.

**Method note.** Orientation via `graphify explain "OfficeFormController"` / `graphify query`, then
source reading, then read-only verification with `php artisan tinker --execute=` against the local
database (SELECTs and in-process resource/controller invocation only — no writes, no HTTP POSTs, no
login tokens created). No browser tool was available in this session, so every "observed" claim below
is verified at the API/resource layer rather than through a screenshot; where a claim is a *render*
claim it is derived from the JSX and marked as such.

---

## Executive answer to the client's two reports

**Report 1 ("I can still see the other application fields for other offices").** There is a real
read leak, and it is a regression of the fix the client already paid for. Checklist item 111 added a
per-office filter (`OfficeFormController::readableCode`) to `GET /applications/{id}/office-forms`.
The officer review sheet **does not use that endpoint.** It reads office forms out of the assignment
payload (`GET /assignments/{id}`), which serialises **every** office's form with no filter at all.
Same user, same filing, two endpoints, two answers — verified below. That is `SEP-1`.

The rest of what the client sees on that sheet (Sections A, B, C, E, the header, the fee declaration)
is the **applicant's own filing**, not another office's work. It must not be stripped: a sanitary
officer inspecting premises needs the address, barangay, line of business, water source, floor area
and the uploaded requirements. Stripping the sheet down to "SANITARY PERMIT ONLY" in the literal
sense the client asked for would make the sheet useless. What is legitimately wrong there is framing,
not exposure — `SEP-4`.

**Report 2 ("what is the meaning of this description? Can't I edit the form itself?").** The banner
is accurate but tells the officer three things they cannot act on and omits the one thing they can.
For a sanitary officer the phrase "the office fields at the bottom of the sheet" resolves to exactly
**one** editable control — *Evaluator Remarks* — and that control's own help text is wrong about what
happens to it. That is `SEP-5`, `SEP-6`, `SEP-7`.

**Write leak: none found on office forms.** `OfficeFormController::upsert` is correctly bounded. One
office cannot write another office's sheet. Verified by direct probe of `readableCode` (see `SEP-3`).
The frontend nevertheless *offers* a write it will be refused — that is `SEP-3`, and it is a UX/dead-end
defect, not an authorisation hole.

---

## What a sanitary officer actually sees on the review sheet

Reproduction filing used: **assignment 9176 / application 3385 / `BIZ-2026-00471`**, status
`under_review`, routed to all seven offices, permit types `BUSINESS, SANITARY, FSIC, OCCUPANCY, CEC,
MARKET, ZONING`. (The filing named in the brief, `BIZ-2026-00958` / app 5003, is `for_inspection`, so
`ReviewPage.tsx:689` early-returns the inspection box and the sheet never renders at all. It cannot
reproduce report 1. Use 9176.)

Section-by-section, with the verdict on each:

| Section | `ReviewPage.tsx` | Contents | Whose data | Verdict |
|---|---|---|---|---|
| Header "Business Permit & Licensing Office · Admin Review" | 1010–1024 | BPLO form title, form ref MCG-BPLO-FO-001, tracking id | BPLO's form identity | **Not a leak. Wrong framing** — `SEP-4` |
| Amendment From | 1041–1069 | what the filing amends | applicant | Shared by definition |
| A — Business Information & Registration | 1071–1167 | registration no., TIN, trade name, telephone, website, president/OIC, citizenship, % Filipino capital, economic organisation, tax incentives, full address + barangay | applicant | **Shared by definition. Must NOT be hidden** — the inspector needs the premises |
| B — Line of Business | 1169–1202 | PSIC line(s), capitalization, products/services | applicant | Shared by definition |
| C — Documentary Requirements | 1204–1218 | **every** uploaded document, ungrouped — incl. `FIRE_REQ`, `OCCUPANCY`, `SANITARY_REQ`, `LEASE_TITLE` | applicant's uploads | Not a leak (applicant's own files). **Noise** — `SEP-2` |
| D — Office Form Answers | 1220–1257 | **every office's per-office questionnaire**, incl. other offices' sheets and any issuance dates their officers recorded | applicant's answers, but on **another office's file** | **Leak** — `SEP-1` |
| E — Fee Declaration | 1259–1312 | gross sales, capitalization, employees + M/F split, storeys, doors, rooms, beds, stalls, vehicles, occupancy group | applicant | Shared by definition (drives every office's fee) |
| Consent + signatures | 1314–1343 | DPA consent, applicant name | applicant | Shared |
| FOR OFFICE USE ONLY | 1346–1466 | Date of receipt, Received by, BAN, PSIC (read-back); Assessed Fee; Evaluator Remarks; **OCCUPANCY issuance dates** | mixed | Issuance-date block is **OBO's** — `SEP-3` |
| Tax Order of Payment | 1468–1482 | BPLO's itemised assessment | BPLO | Coordination; all offices need the amount. Not flagged |
| Assign officer-in-charge | 1484–1532 | select + Assign | own department only | Correctly bounded (see "Buttons" below) |
| Remarks column | 1546–1561 | every assignment's `remarks` + rejection reason | applicant-visible already | **Not confidential** — proven below |
| Messages | 1543 | thread | already scoped by item 111 | Out of scope here |

**Why the remarks column is not a leak.** `existingRemarks` (`ReviewPage.tsx:868–879`) shows every
office's assignment remarks. Those same rows are rendered to the *applicant* at
`web/src/pages/applicant/ApplicationDetailPage.tsx:409–415`. A field the applicant already reads
cannot be an inter-office confidence. Leave it.

---

## SEP-1 — The review sheet serves every office's form answers to every office

**Summary.** `GET /assignments/{id}` returns all `office_forms` unfiltered; the item-111 per-office
filter exists only on `GET /applications/{id}/office-forms`, which the review sheet never calls.

**Maps to:** client report 1, verbatim.

**Class:** **Confidentiality (read).** Severity **high** — it is the exact defect checklist item 111
was signed off as fixed, still open on the one screen the client uses to approve.

**Root cause.**

- `api/app/Http/Resources/ApplicationResource.php:78–85` — serialises `office_forms` from the loaded
  relation with no reader-dependent filter. There is no `$request->user()` consultation anywhere in
  that block.
- `api/app/Http/Controllers/Api/AssignmentController.php:244` — eager-loads
  `'application.officeForms.permitType.department'`.
- `api/app/Http/Controllers/Api/AssignmentController.php:279` — resolves the full `ApplicationResource`
  into the assignment payload.
- The filter that *should* apply lives at
  `api/app/Http/Controllers/Api/OfficeFormController.php:354–368` (`readableCode`) and is wired only
  into `index()` at line 62 and `upsert()` at line 98. Its own docblock (lines 330–353) states the
  policy in the client's words: *"A six-clearance filing is routed to six offices, so all six passed
  the coarse check and each was handed all six sheets. The sanitary officer could read the fire
  office's FSIC answers, which is one office reading another's file on the same applicant."* That
  policy is simply not applied on this route.
- Consumer: `web/src/pages/officer/ReviewPage.tsx:629–633` reads `app.office_forms` off the assignment
  payload and only *sorts* it (own office first) and *badges* it ("Your office", line 1238–1242). The
  UI already knows which sheets are foreign and displays them anyway.

**Reproduction (read-only, no writes).**

```
php artisan tinker --execute="
\$u = App\Models\User::where('email','sanitary@biztrack.local')->first();
\$a = App\Models\ApplicationAssignment::find(10);
\$a->load(['application.officeForms.permitType.department']);
\$req = Illuminate\Http\Request::create('/api/v1/assignments/10','GET');
\$req->setUserResolver(fn()=>\$u);
print_r((new App\Http\Resources\ApplicationResource(\$a->application))->resolve(\$req)['office_forms']);
"
```

**Observed** (assignment 10 / app 19 / `BIZ-2026-00006`, routed to CHO + BFP), as the CHO officer:

```
SANITARY [CHO] {"application_date":"2026-07-28","application_type":"New","establishment_name":"Central Perk"}
FSIC     [BFP] {"application_date":"2026-07-28","certificate_applied_for":"FSIC for Business Permit (New Business)"}
```

The same user against the endpoint that *does* filter:

```
GET /applications/19/office-forms as sanitary@ ->
{"data":[{"permit_type_code":"SANITARY","form_data":{...}}]}
```

One form, not two. **The two endpoints disagree about the same reader on the same filing.**

On the seven-office filing (assignment 9176 / app 3385) the CHO officer's payload carries four sheets:

```
SANITARY  [CHO]     {"application_date":..., "application_type":"New", "sanitary_classification":"Food Establishment", "water_source":"Level III (Waterworks)"}
FSIC      [BFP]     {"application_date":..., "certificate_applied_for":"FSIC for Certificate of Occupancy"}
OCCUPANCY [OBO]     {"application_date":..., "application_type":"Full", "fsec_no":null, "building_permit_no":null}
CEC       [CENRO]   {"application_date":..., "application_type":"Initial Application", "owner_birthday":"2026-04-05"}
```

Note `owner_birthday` on CENRO's sheet. Section D renders every key verbatim through `humanizeKey`
(`ReviewPage.tsx:1248–1250`), so the sanitary officer reads **"Owner Birthday: April 5, 2026"** off
another office's file. This is a date of birth on a screen that, eight sections earlier, prints
"Data Privacy Consent … under RA 10173" (`ReviewPage.tsx:1315–1322`).

**Expected.** The office-form set on the assignment payload should answer the same question
`readableCode` answers: the applicant sees all; `application.view_any_office` (BPLO, admin) sees all;
every other reviewer sees only the sheets whose `permit_types.issuing_department_id` is their own
department.

**Blast radius.**

- Exactly one API route serialises this: `GET /assignments/{id}` (`AssignmentController::show`).
  `grep -rn "officeForms" api/app/` returns only the model relation, that one eager-load, and the one
  resource block. `ApplicationController::show` does **not** load the relation, so the applicant-facing
  and officer application endpoints are clean.
- Exactly one screen consumes it: `ReviewPage.tsx` Section D (1220–1257) and the issuance-date prefill
  (`ReviewPage.tsx:754`).
- Because `ApplicationResource` is shared by the list/create/show paths, any *fix inside the resource*
  changes those too — but they never load the relation, so the practical blast radius of a
  resource-level fix is nil. A fix that filters at the eager-load in `AssignmentController` is narrower
  still. Either satisfies the constraint; the resource-level one is safer against a future caller that
  eager-loads it and forgets.
- Sheets affected: all six form-bearing permit types (`SANITARY, FSIC, OCCUPANCY, CEC, MARKET, ZONING`),
  across every multi-office filing in the register.

**Interference.** `ReviewPage.tsx` is shared with the inspection/approval investigator. Their territory
on this file is the `for_inspection` early return at **lines 646–735** (the `InspectionDecisionPanel`
mount is 720–724). My territory is everything **below line 737** plus the banner at **971–992**. The
only true overlap is that both branches read the same `app` object; a fix that filters `office_forms`
server-side touches neither branch's JSX.

**Tests that pass while this is broken.**

- `api/tests/Feature/OfficeScopingTest.php:390–406` — *"shows an office only its own form sheet on a
  filing both offices share"*. This is the item-111 regression test and it asserts the right thing
  about the wrong endpoint: it only ever calls `GET /applications/{id}/office-forms` (line 395). It
  never touches `/assignments/{id}`, which is what the officer's browser actually loads. It passes.
- `api/tests/Feature/OfficeScopingTest.php:107–129` — *"refuses an outside office on every surface that
  carries the application"* — uses an office that is **not on the filing**, so it is answered by
  `ApplicationVisibility::canView` long before any per-sheet question is asked. It cannot see this bug
  by construction; the file's own comment at lines 320–340 says as much about item 56's cases.
- `grep -rn "office_forms" api/tests/` returns **nothing**. No test anywhere asserts on the serialised
  `office_forms` key.
- `web/e2e/inspection-review.spec.ts:405–433` — *"a filing that is not for inspection still opens on the
  full review sheet"* — opens `/staff/queue/{id}` as an office account and asserts only that the BPLO
  header string is visible. It renders the leak and asserts nothing about it.

---

## SEP-2 — Documentary Requirements is one undifferentiated pile

**Summary.** Section C lists every uploaded document with no indication which clearance required it,
so an officer must know the requirement matrix by heart to tell their own evidence from another
office's.

**Maps to:** client report 1, partially — this is plausibly part of what "other application fields for
other offices" means, but it is **not** a confidentiality problem.

**Class:** **UX.** Severity **low-medium**.

**Root cause.** `web/src/pages/officer/ReviewPage.tsx:1204–1218` maps `app.documents` flat.
`api/app/Http/Resources/DocumentResource.php` does not emit `permit_type` at all (`grep -n
"permit_type"` on that file returns nothing), even though `application_documents` gained a
`permit_type_id` column in `api/database/migrations/2026_07_29_000020_add_permit_type_to_application_documents.php`.

**Observed.** App 19 renders eight rows in one list: `VALID_ID, FIRE_REQ, DTI_SEC_CDA, LEASE_TITLE,
BRGY_CLEARANCE, CEDULA, OCCUPANCY, SANITARY_REQ`. Every one has `permit_type_id = NULL` on disk, so
even if the resource exposed the column there is nothing in it for existing filings.

**Expected.** Grouped, or at minimum labelled, by the clearance that requires it.

**Constraint for whoever fixes it.** These are the **applicant's own uploads**, not another office's
work product. They must stay readable to every office on the filing — a sanitary inspector who cannot
open the Lease Contract cannot confirm the premises. The grouping data already exists and does not
depend on the null column: `permit_type_requirements` maps each permit type to its document types —

```
BUSINESS:  DTI_SEC_CDA, LEASE_TITLE, BRGY_CLEARANCE, CEDULA, VALID_ID, PRIOR_PERMIT, OCCUPANCY
SANITARY:  VALID_ID, SANITARY_REQ
FSIC:      VALID_ID, FIRE_REQ
OCCUPANCY: VALID_ID, OCCUPANCY
CEC:       VALID_ID, LOCATIONAL
MARKET:    BRGY_CLEARANCE, VALID_ID
ZONING:    LEASE_TITLE, BRGY_CLEARANCE, VALID_ID
```

Note `VALID_ID` belongs to all seven and `BRGY_CLEARANCE` to three, so grouping must be
many-to-many, not a partition.

**Blast radius.** Section C of the review sheet only. `DocumentActions` (view/download) is a shared
component and is not implicated.

**Interference.** None. No other investigator's area touches Section C.

**Tests.** None assert on Section C composition. `web/e2e/document-actions.spec.ts` exercises the
view/download buttons, not the grouping.

---

## SEP-3 — Every office is shown OBO's issuance-date panel, with a Save button that 403s

**Summary.** The FOR OFFICE USE ONLY panel renders the OCCUPANCY issuance-date inputs and a "Save
dates" button to *whichever* office opens the sheet, keyed off the filing's permit types rather than
off the reader's department. The API correctly refuses the write — with a message that says the wrong
thing.

**Maps to:** unreported, but it is the "buttons and fields, not just text" question in the brief, and
it is the acid test for report 1 on the write side.

**Class:** **UX / dead-end.** It is **not** an authorisation hole — the server holds. Severity
**medium** (an officer can type a real date, press Save, and be told the filing is not theirs).

**Root cause.**

- `web/src/pages/officer/ReviewPage.tsx:751–766` — `issuedGroups` filters `app.permit_types` by
  `OFFICER_DATE_FIELDS` (declared at lines 82–87, containing only `OCCUPANCY`). The reader's
  department (`data.department.code`) is never consulted. It is consulted twelve lines earlier for the
  Section D sort (629–633), so the information is right there.
- `web/src/pages/officer/ReviewPage.tsx:1417–1464` — renders the group, and 1450–1461 renders the
  enabled "Save dates" button for anyone in Edit mode.
- `web/src/pages/officer/ReviewPage.tsx:768–782` — `saveIssuedDates` calls
  `officeFormsApi.save(app.id, group.code, payload)` → `PUT /applications/{id}/office-forms/OCCUPANCY`.
- Server refusal: `api/app/Http/Controllers/Api/OfficeFormController.php:96–100`. `$isReviewer` is
  `application.review` **AND** `ApplicationVisibility::canView` **AND** `readableCode($code)`. The CHO
  officer fails the third clause, so `abort_unless($isOwner || $isReviewer, 403, 'This application is
  not yours.')` fires.

**Verification of the write boundary** (reflection probe on the private method — pure read, no writes):

```
sanitary@biztrack.local: SANITARY=Y FSIC=n OCCUPANCY=n ZONING=n MARKET=n CEC=n
fire@biztrack.local:     SANITARY=n FSIC=Y OCCUPANCY=n ZONING=n MARKET=n CEC=n
bplo@biztrack.local:     SANITARY=Y FSIC=Y OCCUPANCY=Y ZONING=Y MARKET=Y CEC=Y
owner@biztrack.local:    SANITARY=Y FSIC=Y BOGUS=Y   (applicant branch, line 362)
```

**The write boundary is correct.** One office cannot set another office's issuance date. Reported here
so it is on the record as *checked*, not assumed.

**Reproduction.** Sign in as `sanitary@`, open `/staff/queue/9176` (app 3385 carries OCCUPANCY),
switch Mode to Edit, scroll to FOR OFFICE USE ONLY. **Observed** (from the JSX; not browser-verified
this session): a group headed *"Occupancy Permit · Issuance Dates"* with *Building Permit Date Issued*
and *FSEC Date Issued* date inputs, help text reading "Enter the dates the issuing office released
these documents", and an active **Save dates** button. Pressing it would produce the red banner
*"This application is not yours."* — a message that is flatly wrong (the filing **is** theirs; the
*sheet* is not).

**Expected.** The group should not render for a department that does not issue that permit type. If it
renders read-only for coordination, it must not carry a Save button. Independently, the 403 message on
`OfficeFormController.php:100` conflates "not your filing" with "not your sheet" and should
distinguish them — the controller already knows which clause failed.

**Blast radius.** Today `OFFICER_DATE_FIELDS` contains only `OCCUPANCY`, so this misfires on every
filing that includes an occupancy permit and is opened by any of CHO / BFP / CENRO / CMO-MARKET /
CPDO. The `date_issued` and `fsec_date` keys are in the server's `OFFICER_KEYS`
(`OfficeFormController.php:39`) but have no UI (`grep` for them in
`web/src/pages/applicant/OfficeFormStep.tsx` returns nothing), so adding a second entry to
`OFFICER_DATE_FIELDS` would widen this immediately.

**Interference.** Note for whoever fixes `SEP-1`: the issuance-date **prefill** at `ReviewPage.tsx:754`
reads from the same leaky `officeForms` array. Filtering `office_forms` server-side will make OBO's
recorded dates disappear from this panel for other offices — which is correct, but it means the panel
would then render *empty* inputs for a foreign office rather than populated ones. Fix `SEP-1` and
`SEP-3` together or the panel gets worse before it gets better.

**Tests.** `api/tests/Feature/OfficeScopingTest.php:408–420` — *"will not let an office record an
issuance date on another office's sheet"* — passes, and correctly: it tests the **server**. Nothing
tests that the client does not offer the control. There is no frontend test of the FOR OFFICE USE ONLY
panel at all.

---

## SEP-4 — Every office is shown a sheet titled "BPLO Admin Review", with its own form buried fourth

**Summary.** The review sheet has one shape for seven offices. It announces itself as the BPLO form,
orders itself by the BPLO form's lettering, and puts the reviewing office's own questionnaire in
Section D as generic `Key: value` pairs.

**Maps to:** client report 1 — this is the part of "I should only see the SANITARY PERMIT" that is a
presentation problem rather than a leak.

**Class:** **UX.** Severity **medium** — it is why the client believes there is a leak even where there
is none.

**Root cause.** `web/src/pages/officer/ReviewPage.tsx:1010–1024` hardcodes *"Business Permit &
Licensing Office · Admin Review"*, *"Application for … Business Permit"* and *"Form Ref:
MCG-BPLO-FO-001 · v2.0"* for every reader. Sections are lettered A–E after that paper form
(`SectionHeading`, 156–166; used at 1073, 1171, 1206, 1222, 1261). The reviewing office's own sheet is
`SectionHeading letter="D"` — after two sections of BPLO registration data and one of documents. Within
Section D the office's own form is sorted first and badged "Your office" (629–633, 1238–1242), which is
the right instinct applied at the wrong altitude.

**Observed.** A sanitary officer reads roughly 1,200 lines of BPLO business-permit form before reaching
four labelled answers that are their actual clearance: *Application Date, Application Type, Sanitary
Classification, Water Source* — rendered by `humanizeKey`/`formValueText` (376–396) as flat key-value
Fields, losing the sectioned layout (A Application Details / B Establishment Sanitation Profile) the
applicant actually filled at `web/src/pages/applicant/OfficeFormStep.tsx:724–761`.

**Expected.** The sheet should lead with the office it belongs to. Whether that means retitling the
header per department, hoisting the office's own form above Section A, or both, is a design call.

**Constraint for the fix — what must NOT be hidden.** Sections A, B, C and E are the applicant's own
particulars and every office on the filing needs them:

- **A** — full address, barangay, telephone: the inspector cannot find the premises without it.
- **B** — PSIC line of business and products/services: CENRO reviews exactly this (see the comment at
  `ReviewPage.tsx:1182–1190`).
- **C** — the uploaded requirements, incl. `SANITARY_REQ` for CHO and `LEASE_TITLE` for CPDO.
- **E** — floor area and storeys (CPDO's fee is per square metre — `OfficeFormController.php:212–236`),
  employee counts and the M/F split (CENRO's own box — `ReviewPage.tsx:416–423`), stall count, beds,
  occupancy group.

"SANITARY PERMIT ONLY" taken literally deletes all of that. The right reading of the client's request
is *"lead with my office and stop showing me other offices' files"* — which is `SEP-1` plus this.

**Blast radius.** `ReviewPage.tsx` only; it is the sole consumer of this layout.

**Interference.** Reordering or retitling sections touches the same file as the inspection
investigator's `for_inspection` branch (646–735), but not the same lines — that branch returns before
any of this renders. A section reorder must not disturb the early return's position, because
`ReviewPage.tsx:685–687` warns that every hook runs above the `loading` guard and nothing below is a
hook.

---

## SEP-5 — The Edit-mode banner names three things and misses the one that matters

**Summary.** The banner the client screenshotted is literally true and practically useless: it says
"fill in the office fields" without saying that, for most offices, there is exactly one.

**Maps to:** client report 2, verbatim.

**Class:** **UX (copy).** Severity **medium**.

**Root cause.** `web/src/pages/officer/ReviewPage.tsx:971–992`. Three variants:

- decided (line 980)
- `editing && canReject` (line 983) — **this is the client's screenshot**
- `editing && !canReject` (line 989)
- view (line 990)

**Which account produced the screenshot.** `canReject` is `application.reject`
(`ReviewPage.tsx:480`), held only by `bplo_staff` and `admin`
(`api/database/seeders/RbacSeeder.php:96–99`, `181–182`). The sanitary, fire, zoning, OBO, CENRO and
market roles do **not** have it (RbacSeeder 61–62, 103–124, 225–231). So the banner in report 2 —
which mentions rejecting — was captured on **bplo@ or admin@, not on the sanitary account** of report
1. Worth confirming with the client before anyone tries to reproduce it as sanitary; as sanitary the
banner reads the *other* variant (line 989), which says "Returning is how your office refuses this
filing".

**What Edit mode actually turns on** (exhaustive, from the JSX):

| Control | Line | Gate |
|---|---|---|
| Reject | 925–934 | `editing && canReject` (`application.reject`) |
| Return with remarks | 942–955 | `editing` |
| Approve | 956–963 | `editing` |
| Assessed Fee input + **Save assessment** | 1371–1391 | `editing && canAdjustFee` (`fee.adjust`) |
| Evaluator Remarks input | 1398–1410 | `editing` — **no save button** |
| Issuance dates + **Save dates** | 1417–1464 | `editing` && filing carries OCCUPANCY (see `SEP-3`) |
| Assign officer-in-charge | 1485–1532 | `editing && canAssign && canListUsers` (`oic.assign`, admin only) |

For a **sanitary officer on a filing without an occupancy permit, the entire editable surface of
"Edit mode" is one text input: Evaluator Remarks.** For BPLO it is three. The banner's plural "the
office fields" is an overpromise in the common case.

**What Edit mode locks, and why.** Everything in Sections A–E, including the office's *own* Section D
answers. The reason is stated in the file's own header comment,
`web/src/pages/officer/ReviewPage.tsx:24–35` — quoting it in full because it is the answer to the
client's question:

> *"The screen has two modes (tester checklist item 54). It opens in View: a record of what the
> applicant filed, with nothing on it that can be typed into. Edit turns on the handful of fields the
> office actually owns and the decision buttons. The applicant's own answers are never editable in
> either mode, which is what the API enforces too: OfficeFormController lets the owner write the
> answers and the reviewer write only the issuance dates."*

The API side says the same thing in its own words,
`api/app/Http/Controllers/Api/OfficeFormController.php:20–25`:

> *"Two writers share one payload: the applicant answers the questions only they can answer, and the
> reviewing officer records the issuance dates only the office can know."*

and again at line 147: *"The applicant owns the answers; the office dates stay as recorded."* The
mechanism is `OfficeFormController.php:146–153`: the owner's write is `array_diff_key($submitted,
OFFICER_KEYS) + array_intersect_key($current, OFFICER_KEYS)`; the officer's is
`array_intersect_key($submitted, OFFICER_KEYS) + $current`. Neither can reach the other's keys. There
is also a design reason recorded at `ReviewPage.tsx:104–109`: *"read-only inputs looked exactly like
the boxes the office fills in, so nothing on the page said which half was a record and which half was
work."*

So the honest answer to the client is: **no, you cannot edit the applicant's form, and that is
deliberate** — it is the applicant's sworn declaration, they signed it (the sheet renders their
signature at 1326–1334) and they consented to it under RA 10173 (1315–1322). An officer who disagrees
with an answer returns the filing so the applicant corrects it themselves. That is what "returning
sends it back for revision" is for, and the banner says it without connecting it to the lock.

**What the banner must convey (not a rewrite — requirements).**

1. **Name the fields, don't gesture at them.** For this reader, in this mode, on this filing, say
   which controls are live. "The office fields at the bottom" is a scavenger hunt on a 1,200-line page.
2. **Say *why* the applicant's answers are locked**, in one clause — they are the applicant's signed
   declaration.
3. **Say what to do when an answer is wrong** — return the filing; the applicant edits it, you do not.
   The banner currently mentions returning only as a decision, never as the remedy for the lock it just
   announced.
4. **Do not describe controls this reader does not have.** The `canReject` split already does this
   correctly for Reject; nothing does it for Assessed Fee or the issuance dates.
5. **Distinguish "your office's decision" from "the filing's decision".** The existing sentence
   "Rejecting ends the application for every office; returning sends it back for revision" is the one
   genuinely good thing in the current copy. Keep that distinction.

**Blast radius.** One `<p>` block. `aria-live="polite"` is on it (line 972), so any rewrite must stay a
single announceable string — do not split it into a list without re-checking the live-region semantics.

**Interference.** The banner does not render on the `for_inspection` path (that branch returns at
689–735 before reaching line 971), so the inspection investigator is unaffected. The other two
investigators (location insights / over-technical warnings; clearance withdraw dead-end) do not touch
this file.

**Tests.** None. No test asserts on any banner string. `web/e2e/inspection-review.spec.ts:431` asserts
only the sheet header constant.

---

## SEP-6 — "Evaluator Remarks" is silently discarded when you Return or Reject

**Summary.** The Evaluator Remarks box says it rides along with approve *or return*. It rides along
with approve only. On Return and on Reject its contents are thrown away and the popup's text is sent
instead.

**Maps to:** unreported. Found while establishing what Edit mode actually permits, and it is the direct
cause of the client's "can't I edit the form" confusion — the one field they *can* type into does not
reliably do anything.

**Class:** **UX / silent data loss.** Severity **medium**.

**Root cause.**

- `web/src/pages/officer/ReviewPage.tsx:1407–1409` — help text: *"Sent with the application when you
  approve or return it."*
- `web/src/pages/officer/ReviewPage.tsx:788` — `approve()` sends `remarks.trim() || undefined`. True.
- `web/src/pages/officer/ReviewPage.tsx:798–820` — `sendRemark(text)` sends **`text`**, the
  `RemarkPopup`'s own textarea (declared at 296), to either `applications.reject(app.id, text)` (811)
  or `assignments.return(assignmentId, text)` (812). The `remarks` state is not referenced on either
  path.
- API client confirms there is only one slot: `web/src/lib/resources.ts:565–566`
  `return: (id, remarks: string) => api.post('/assignments/${id}/return', { remarks })`.

**Reproduction.** Edit mode → type "Water potability certificate is expired" into Evaluator Remarks →
click **Return with remarks** → the popup opens empty and demands its own text → confirm.
**Observed:** the assignment's `remarks` column holds the popup text; the Evaluator Remarks text is
gone, with no warning, no prompt, and no indication it was ever going anywhere.
**Expected:** either the popup pre-fills from the box, or the box is not offered when a popup will
override it, or the help text stops claiming a destination it does not have.

**Secondary contradiction in the same panel.** `ReviewPage.tsx:1354` tells the officer *"This panel is
the only part of the sheet you can change, and each field saves with its own button."* Evaluator
Remarks has no button (1398–1410). For a sanitary officer with no `fee.adjust` and no occupancy permit
on the filing, that sentence describes a panel containing **zero** save buttons while pointing at the
only field there is.

**Blast radius.** `ReviewPage.tsx` only. The API is not implicated — it has exactly one remarks slot
per assignment and both endpoints use it correctly.

**Interference.** `sendRemark` (798–820) is shared with the Reject flow, which the inspection/approval
investigator may also be looking at from the decision side. My claim here is scoped to the *source of
the text*, not to what Reject does downstream. Coordinate before editing 798–820.

**Tests.** `api/tests/Feature/*` cover the endpoints, which behave correctly. There is no test that
types into Evaluator Remarks and asserts where the text lands, because there is no frontend test of
this panel.

---

## SEP-7 — The banner points "at the bottom of the sheet" past 1,200 lines of locked form

**Summary.** Minor, but it is half of why the client asked the question. The banner names a location,
not a control, and the location is roughly 1,200 lines below the banner.

**Maps to:** client report 2, secondary.

**Class:** **UX.** Severity **low**.

**Root cause.** `ReviewPage.tsx:983` / `989` say "at the bottom of the sheet"; the panel is at 1346.
There is no anchor, no jump link, and no count. The decision buttons are in the header (925–963) —
deliberately, per the comment at 935–941 — so an officer following the banner's instruction scrolls
down to fill one field and back up to act on it.

**Expected.** Either an in-page anchor from the banner to the office-use panel, or the office fields
moved adjacent to the decision they feed. The comment at 1534–1540 records that a control was
previously moved *out* of the bottom of the sheet for exactly this reason ("two controls firing the
same decision from opposite ends of a 1,200-line sheet is how it came to be missed in the first
place") — the same argument applies to the fields.

**Blast radius / Interference / Tests.** As `SEP-5`.

---

## Checked and found correct (recorded so it is not re-investigated)

- **Write boundary on office forms.** `OfficeFormController::upsert` gates the reviewer on
  `application.review` **AND** `ApplicationVisibility::canView` **AND** `readableCode($permitTypeCode)`
  (lines 96–100), and the docblock at 85–95 states the intent exactly. Probe matrix above confirms
  CHO↛FSIC, BFP↛SANITARY. The route comment at `api/routes/workflow.php:65–67` matches the code.
- **Key partition.** Owner cannot write `building_permit_date` / `fsec_date` / `date_issued`; officer
  cannot write anything else (`OfficeFormController.php:39`, `146–153`). Derived values are re-applied
  after every write (line 155), so a client-supplied derived value is never trusted.
- **Cross-department assignment access.** `AssignmentController::authorizeDepartment` (398–409) refuses
  any assignment outside the caller's department. Note it uses `hasRole('admin')` rather than a
  permission — currently dead code on these routes, because the whole review group sits behind
  `permission:application.review` (`api/routes/workflow.php:141–147`) and the `admin` role no longer
  holds it (`RbacSeeder.php:178–184`). Flagged as latent, not a defect.
- **Assign officer-in-charge.** `AssignmentController::assign` (364–378) rejects an officer from
  another department with a 422; the frontend also filters to `data.department.code`
  (`ReviewPage.tsx:861–866`). Correctly bounded on both ends.
- **Reject.** Behind `permission:application.reject` at the route (`api/routes/workflow.php:155–156`)
  and behind `canReject` in the UI (`ReviewPage.tsx:480`, `925`). Six of eight staff roles have neither.
  Deliberate and documented at `ReviewPage.tsx:459–479` and `api/routes/workflow.php:148–154`.
- **Assessed Fee.** Behind `fee.adjust` (`ReviewPage.tsx:456`, `1371`) at the route
  (`api/routes/workflow.php:137–138`). Only BPLO and admin.
- **Assignment remarks in the right-hand column.** Not confidential — the applicant reads the same rows
  (`web/src/pages/applicant/ApplicationDetailPage.tsx:409–415`).

---

## One item I am deliberately handing over rather than claiming

**Other offices' inspection findings are visible on the `for_inspection` screen.**
`web/src/components/InspectionDecision.tsx:360–363` renders `item.findings` for **every** inspection on
the filing, not just the reader's department's, and `ReviewPage.tsx:720–724` hands the panel
`app.inspections ?? []` unfiltered. On the brief's own example — "seeing Fire's internal findings might
be [a leak]" — this is where it would be.

Two reasons I am not filing it as a `SEP-`:

1. **It is bounded on the action side.** `canAct` (`InspectionDecision.tsx:624–629`) mirrors the API
   rule — admin, the inspecting department, or the named inspector — and the card prints
   *"{office} records this result. Your office cannot approve or reject it."* (line 406). So an office
   cannot act on another's visit, and the separability is stated on screen rather than merely enforced.
2. **It reads as deliberate coordination**, and it is squarely inside the inspection/approval
   investigator's file and feature.

**Handover:** the read question — *should CHO see BFP's findings text at all?* — is unanswered and
belongs in that investigator's report or in a joint decision. I could not determine the intent from
the comments; there is no docblock stating a policy on cross-office findings visibility the way
`readableCode` states one for form sheets.

---

## Things I could not determine

- **Whether the client's report-2 screenshot came from bplo@ or admin@.** The `canReject` variant of
  the banner (line 983) is only reachable with `application.reject`, which the sanitary role does not
  have. Report 1 says "Sanitary Office's admin account". Either the two reports are from different
  accounts, or the account they call "Sanitary Office's admin" holds more than `sanitary_officer`.
  Worth one question to the client before anyone reproduces.
- **Whether the client expects the office-form questionnaire to become officer-writable.** "Can't I
  edit the form itself since I am on edit mode?" may be a complaint about the copy (my reading, and the
  reading `SEP-5` is written against) *or* a feature request — e.g. the sanitary officer wanting to
  correct `sanitary_classification` in place instead of returning the filing. The second would overturn
  the two-writer model at `OfficeFormController.php:20–25` and the signed-declaration argument, so it
  should be asked, not assumed.
- **Browser-level confirmation.** No browser automation tool was available in this session. `SEP-1` and
  `SEP-3` are proven at the API/resource layer; their rendered form is derived from the JSX. If a
  screenshot is wanted for the client, `sanitary@` → `/staff/queue/9176` → Mode: Edit → Sections D and
  FOR OFFICE USE ONLY will show both at once. Do not press Approve / Return / Reject.
