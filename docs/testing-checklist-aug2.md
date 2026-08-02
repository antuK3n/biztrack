# System Testing Checklist — August 2, 2026

Transcribed verbatim from `SYSTEM TESTING CHECKLIST (1).pdf` (client/adviser
testing). These are the items the client marked on August 2 and the ones being
worked now. Items 1–65 are from July 24/28/29 and are tracked separately.

`docs/testing-checklist-status.md` covers the earlier round and is not
superseded by this file — that one is the July 24 list of 25, this one is the
August 2 list. Read both when tracing an item back.

The PDF's own status column is stale: several items it marks "In Progress"
were finished in later rounds and never re-marked. Ours reflects what was
checked in a browser against the running app.

---

## August 2 — 1st checking

| # | Item (verbatim) | Client note |
|---|---|---|
| 66 | Should add a different placeholder instead when nothing is chosen among the choices. | Barangay select shows "Barangay Name" as the placeholder |
| 67 | In the line of business, allow the user to enter something when Other is chosen. Allow searching too. | "This is to be verified with the LGU if others do really exist." |
| 68 | Location Insights does not work properly. Remove unnecessary descriptions too that sound AI. | |
| 69 | Remove Line of Business in the sections since it is already asked in the Location & Zoning. | |
| 70 | Double check all default placeholders; should not provide uncommon examples. | e.g. "bamboo furniture weaving" |
| 71 | Data Privacy Consent should be placed either on the first or last part of the application. | |
| 72 | Remove redundant questions in the whole application process (e.g., Business Structure). | |
| 73 | Messages should have something that will determine which admin is responsible for handling your certain applications. | |
| 74 | Edit Profile's field information is incomplete. | |
| 75 | Remove the background watermark in the downloaded receipt. | |

## August 2 — 2nd checking

| # | Item (verbatim) | Client note |
|---|---|---|
| 76 | Either Submit or Apply must be required for the user not to skip this. Also, this should be placed at the last part before submitting the application. | |
| 77 | Admin should have different login page. | |
| 78 | The dashboard should be transferred to BPLO admin, not super admin. | |
| 79 | Profile page in the business owner side should display all approved permits. Also, the business owner can press the View button to view the permit (the look of this will be the same as the physical counterpart, now filled with all the fields entered in the application form). Also, allow saving the permit as PDF. | |
| 80 | No reject button in some offices. After rejection, allow the admin to put some remarks (refer to the GUI for this) and this will be reflected in the Track page of the business owners since they will see that their permit was rejected. | |
| 81 | After approval of all permits, this should be gone in the Track page. Instead, it should go to the profile page. | |
| 82 | Fix the amendment page. Refer to the amendment form. | |
| 83 | In the other requirements of the admin side, upon clicking Request, the system crashes. | |
| 84 | Fix the amendment form. Refer to the physical/paper application. | |
| 85 | Renewal of permit should not be the same as the application form. Refer to the BPLS for this since it needs to ask for ID or some sort to know which certain permit to renew. | |
| 86 | In the zoning part (the Google Map), put some validations of some sort. For example, the business owner should not pin coordinates on the water. | |
| 87 | Errors in passing data for the inspections in both business owner and admin. | |
| 88 | Put a search button on the Track page of both business owner side and admin side. | |
| 89 | Requests for other requirements should have recipients. The admin should choose who will receive this. | |
| 90 | Sort/Filter should be fixed. | |

---

## Earlier items that overlap and must not regress

- **#33 / #34** — zoning should be the first step; the flow order was wrong.
  Now: Data Privacy → Location & Zoning → Business Information → Line of
  Business → Permits & Certificates → … This is why 71 and 33 are both
  satisfied at once.
- **#5** — the Mayor's/Business Permit card must not appear in the LGU
  Section; zoning clearance belongs there instead.
- **#6** — the office form must open when a clearance is applied for, rather
  than every section expanding at once.
- **#62** — the zoning step must not tell the applicant they choose the line
  of business later; it is chosen there.


---

## Findings that settle the "refer to the form" items

**#82 / #84 — what the amendment form is missing.**
The database already models the paper form's amendment section, from the
manuscript alignment migration
(`2026_07_24_000073_align_tables_with_manuscript.php:45-48`):

    has_amendments, amendment_ownership, amendment_location,
    amendment_nature, amendment_other

Those are the checkboxes on the physical BPLO application — *Amendment from:
Ownership / Location / Nature of Business / Others (specify)*. A grep across
`web/src` and `api/app` returns **no reads and no writes of any of them**. So
the amendment flow is a re-labelled new-application wizard that never asks the
one question the paper form exists to ask.

That is the fix, and it needs no outside document: collect the four, persist
them to the columns already waiting, and show them to the reviewing officer.

**#85 — what renewal is missing.**
`GET /businesses/{id}/prefill?type=renewal` returns `last_permit` — a single
permit — and the wizard prefills from the owner's business rather than from a
chosen permit. The client wants renewal to identify *which* permit is being
renewed. A business commonly holds several (business, sanitary, fire,
occupancy) with different expiry dates, so "the last one" is not an answer.

**Assumption recorded:** we do not have the BPLS renewal form or the physical
amendment form as reference documents. Both fixes above are derived from the
schema the manuscript alignment already established, not from the paper. If
the LGU's form asks for more, this is the floor rather than the finish.

---

## Status after the August 3 pass

`tsc -b --force` clean · **485 Pest / 4,264 assertions** · **40 Playwright** ·
live tester database untouched (max application id 3375, unchanged).

Note: `tsc --noEmit` checks **nothing** in this repo — the root `tsconfig.json`
is `files: []` with only project references. Use `tsc -b`. Several earlier
"type-check clean" claims were made with `--noEmit` and were worthless.

| # | Status | Evidence / what was actually done |
|---|--------|-----------------------------------|
| 66 | Done (was already) | Barangay select reads "Select your barangay" |
| 67 | Done (was already) | "Other (not listed)" + free text + searchable picker |
| 68 | Done, premise corrected | PSIC matching was **not** broken — verified in SQL across all 706 registered points. The real bug: picking "Other (not listed)" told the applicant to "choose your Line of Business first". Now distinguishes `line_not_chosen` from `line_unclassified`. Copy tightened; the zoning disclaimer kept |
| 69 | Done | The standalone step is gone and the *searchable multi-select* moved onto Location & Zoning — the weaker `<select>` is not what survived |
| 70 | Done | 5 placeholders fixed; ~25 audited and left. "Poblacion" corrected — not a Malabon barangay |
| 71 | Done (earlier today) | Consent is step 1 of 8 and blocks advance |
| 72 | Done | Business Structure is derived read-only from Type of Registration, with a stale-value bug fixed |
| 73 | Done | "Handled by <Office> · <Officer>" on thread header and cards; one office per thread |
| 74 | Done | Middle name, suffix, gender editable. Fixed a bug where a saved middle name could never be cleared |
| 75 | Done | Watermark removed; "(Simulated)" title and "no real funds were collected" footer kept |
| 76 | Partly | At least one clearance now required. **Step not moved** — `documents` and `fees` are computed from `permits`, so it cannot go later. Deviation documented in code |
| 77 | Done (was already) | `/staff/login` separate from `/login` |
| 78 | Done | `analytics.view` granted to `bplo_staff` only. Verified live: bplo 200, sanitary 403, owner 403 |
| 79 | Done | Approved permits on Profile with View + PDF; certificate layout; signatories read from `office_signatories`, never hardcoded |
| 80 | Partly | Rejection reasons now reach the applicant. Reject promoted for every office as **"Return with remarks"**. Per-office *rejection* not built — see Open questions |
| 81 | Done (was already) | Approved/issued filtered out of Track, with a pointer to Profile |
| 82 | Done | Amendment kinds collected, persisted, shown to the officer |
| 83 | Done (earlier today) | Crash from `business: null` on a soft-deleted business |
| 84 | Done | The four paper-form checkboxes — Ownership / Location / Nature / Others (specify) |
| 85 | Partly | Renewal now lists the business's renewable permits from the server, excludes revoked/suspended, links `prior_permit_id`. Interpreted "ID" as the permit number |
| 86 | Partly | Malabon boundary check enforced in two places. **Water detection not done** — no hydrography data; nothing claims it was checked |
| 87 | Done, root cause differed | Not the soft-delete class. `inspections.application:id,tracking_id` never loaded the business, so the payload said "removed from register" when it meant "not loaded" |
| 88 | Done | Search on both Track pages, with an announced result count |
| 89 | Done | Explicit recipient shown. Found the From-office picker wrote a column **no screen read** |
| 90 | Partly | Applicant fully client-side. Officer: filter is a real server query; search/sort run over loaded rows and the page says so |
