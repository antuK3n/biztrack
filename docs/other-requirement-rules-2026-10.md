# Other Requirements by nature of business — rules (2026-10-05)

**Status:** shipped on `rupert-6`. Source of the rules: `api/app/Support/OtherRequirementRules.php`.
**Client instruction [C]:** *"I am planning to have rules that will tell which Other Requirements are
required to have for a business. Does the revenue code or any other files you have state something
about this?"* — picker answered **2 — tell him before AND after**, and **1 — add yes/no questions**
for the facts the wizard did not ask. (Those yes/no questions went again the same day with the
quantity-priced rows — see *Not built*.)

## What the Revenue Code says

The Code names permits a business must hold on top of the Mayor's Permit, by what it does. Until
this change the system knew them only as **fees** (`fee_rules`). It now also knows them as
**requirements**.

| Nature | Code | Rule key | Flag / category | After submit |
|---|---|---|---|---|
| Sells / serves liquor | Art. T, Sec. 3T.01, 3T.04 | `liquor_permit` | `sells_liquor` | **message** — nearest school/church/hospital and distance (3T.04: 50 m bars, 200 m night clubs) |
| Sells tobacco | Art. U, Sec. 3U.01 | `tobacco_permit` | `sells_tobacco_retail` / `_wholesale` | told only — fee already assessed |
| Staff handle food / personal care | Art. 4D | `health_certificates` | `employees_need_health_certificates` | **document** — Health Certificates from CHO, to CHO |
| Peddler / ambulant | Sec. 3X.03, 3X.05 | `ambulant_vendor` | `is_ambulant_vendor` | told only — zoning-exempt; Mayor's EO sets where |

Why most rows **ask** rather than demand an upload: these permits are issued by the City itself,
through BPLO, on this very filing. "Upload your Liquor Permit" would ask for a paper the City has not
yet issued. The Code gives the office something it genuinely needs from the applicant only where a
row asks for it.

## How it works

- **Conditions** use `fee_rules.conditions` semantics (`flags`, `business_category`; keys ANDed, any
  value within a key) and are read against the **same normalised profile** the fee assessment uses
  (`FeeCalculator::facts`). A fee charged for X and a requirement to hold X read one answer.
- **Before submit:** `POST applications/{id}/fee-preview` returns `other_requirements` beside the
  estimate. The Review step shows a "Your business will also need" panel; the confirmation modal
  shows the names in one line.
- **After submit:** `WorkflowService::submit` → `raiseOtherRequirements`. Each asking row becomes a
  system `OfficerRequest` (`requested_by_user_id = null`, `system_key = rule.<key>`, the serving
  department) via `firstOrCreate` on (application, system_key). Audit `request.raised_by_system`
  carries the article. One `applicationNote` tells the applicant.
- **Tests:** `api/tests/Feature/OtherRequirementRulesTest.php`.

## Not built (deliberately)

- **Quantity-priced permits — dropped 5 October 2026.** Storage of flammables (Art. O, Sec. 3O.01),
  engines and machinery (Art. N, Sec. 3N.01) and lumberyards (Art. AC, Sec. 3AC.01) were rows for a
  day, with three new wizard ticks (`stores_flammables`, `operates_machinery`, `is_lumberyard`).
  Client: *"safe to not include this for less complexity."* The rows and the ticks are gone; bringing
  one back means the row in `OtherRequirementRules` and its tick in `FeeProfileStep` together.

- **Admin-editable rules** (picker option 3). The table is code; a change is a commit.
- **Amusement Special Permit** as its own row. Sec. 3T.02's ₱5,000 special permit is a *liquor*
  permit for amusement places; it is covered by `liquor_permit` when `amusement_place` businesses
  tick `sells_liquor`, and the fee engine already prices it (`permit.liquor_filing_amusement`).

## Open questions for City Hall

1. Does BPLO want **documents** for the Liquor Permit (e.g. barangay clearance, police clearance)
   beyond the Sec. 3T.04 distance check? The Code lists none.
2. Should **Health Certificates** gate the Sanitary Permit's release, or only be collected?
