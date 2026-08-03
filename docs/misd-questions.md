# Questions for Malabon City Hall — MISD

Every question below is tied to something currently blocked, guessed, or
simulated in BizTrack. The "why it matters" line is what breaks if the answer
never arrives — bring that, because it turns a vague ask into a decision they
can make on the spot.

MISD is the IT department. Some of what we need is process, not systems, and
they will route it to BPLO or CPDO. That split is marked so no one's time is
spent on the wrong desk.

---

## 1. Bring away with paper — the highest-value asks

These unblock more work than anything else in the list. Ask for **copies**, not
descriptions; every attempt to work from a verbal description has produced a
form that does not match.

| Ask for | Why it matters |
|---|---|
| The current **application form** (new business permit), as issued | Checklist item 2 has been "blocked on the LGU" since the first round. Our wizard is assembled from the manuscript and a prototype, not from the form the counter actually hands out. |
| The **amendment form** | Items 82/84. We inferred the amendment section from `amendment_ownership / location / nature / other` columns the manuscript alignment added. Those are almost certainly the paper's checkboxes, but nobody has confirmed it against the sheet. |
| The **renewal form** | Item 85. We made renewal ask which permit is being renewed, by permit number. The client said "refer to the BPLS" — we have never seen it. |
| A **filled sample** of each, with dummy data | Field order and wording are guessable. What is not guessable is which fields staff actually leave blank in practice. |
| A blank **Mayor's / Business Permit certificate** | Item 79 renders a certificate. Ours is a reasonable facsimile; the real one has a specific layout, seal placement and legal footer. |
| The **Tax Order of Payment** as printed today | Item 18 and 58 — we strip Revenue Code section citations for non-officers, but "must not be mentioned" was never confirmed as final. |

**Question to pair with these:** *is any of this already available as a fillable
PDF or a form template MISD maintains?* If MISD holds the master, we should
match their field names rather than inventing our own.

---

## 2. eBPLS — the system already in place

This is the single most important area, and it is squarely MISD's.

1. **Is eBPLS (or any BPLS) currently live in Malabon, and which vendor/version?**
   Item 62 says "align with the real eBPLS". We have been aligning to a
   description. If it exists, its screens settle a dozen open UI arguments.
2. **Can we see it, even read-only, even for fifteen minutes?**
   Ask for a walkthrough rather than credentials — far easier to grant.
3. **Is BizTrack meant to replace it, sit beside it, or feed it?**
   This changes everything about the handover and about who owns the register
   of record. We have assumed BizTrack is the system of record. Nobody
   confirmed that.
4. **If it must interoperate — is there an API, an export, or a shared
   database?** And what identifier joins a business across the two systems?
5. **What does eBPLS call the things we have had to name ourselves?**
   Specifically: a filing that breached its service standard (we say "flagged
   week"), and the reporting period the office uses (we assumed weeks).
   *(These are the r-integration open questions 6.3 and 6.4.)*

---

## 3. Data — what we currently invent

Everything in our database is seeded or simulated. Ask what can be replaced
with real reference data.

1. **Barangay list** — we have 21. Is that the authoritative list, with the
   official spellings? (We already caught "Poblacion" being used as a Malabon
   barangay in a placeholder; it is not one.)
2. **PSIC codes** — we expanded 15 → 135 and added an "Other (not listed)"
   free-text escape. **Checklist item 67 asks directly: does the real system
   allow "Others", or is the list closed?** If closed, we should drop the
   escape hatch rather than collect text nobody can process.
3. **Revenue Code** — we extracted fees from the 2016 code. Are there later
   amendments or an ordinance that supersedes it? Our fee engine is only as
   correct as that document.
4. **Office signatories** — the permit certificate prints signatory names from
   an admin-editable table (deliberately never hardcoded). Who are the current
   signatories for each office, and who is allowed to change them?
5. **Department/office list and their real names** — we use BPLO, CHO, BFP,
   CENRO, OBO, CPDO, City Market. Are those the correct names and abbreviations
   as the city writes them?
6. **Is there historical permit data we could load?** Our analytics
   (processing time, renewal risk, growth) currently describe seeded data. On
   real history they would say something true. This is also the honest answer
   to "where is the forecasting?" — no model can be fitted without outcomes.

---

## 4. Accounts, roles and who may see what

1. **What are the real office roles, and who approves what?**
   We modelled eight staff roles. Item 56 asked whether offices should be
   restricted to their own applications — still unanswered, and it is a policy
   question with a security consequence.
2. **When one office refuses a clearance, does the whole application die, or
   does it wait?** *(Checklist item 80 — genuinely blocked on this.)* Our
   workflow only advances when every office completes, so a per-office
   rejection would stall a filing forever with no defined exit. We have shipped
   "Return with remarks" for every office instead. **This needs a decision from
   BPLO, but MISD should hear it — it is a workflow rule, not a screen.**
3. **Who is the super admin in practice — MISD or BPLO?** We just moved the
   analytics dashboard to BPLO on the client's instruction. Is that right?
4. **How are staff accounts created and deprovisioned today?** Is there an
   existing directory (AD/LDAP/Google Workspace) accounts should come from
   rather than being created in our app?

---

## 5. Notifications — currently simulated

Checklist items 28 and 29. Right now email goes to a log file and SMS goes to
`sms.log`. Nothing is delivered.

1. **Does the city have an SMS provider or gateway already?** Many LGUs do,
   for disaster alerts. If so, can permit reminders use it?
2. **Is there an official city email domain/SMTP** we should send from? A
   permit notice from a gmail address will be read as a scam.
3. **Who owns the sending reputation** if a renewal reminder goes to several
   thousand businesses?
4. **Is SMS consent handled anywhere today?** Item 29 asked what breaks if the
   applicant declines email consent — we need to know the city's own rule
   before we answer it in the UI.

---

## 6. Hosting and handover — MISD's decision entirely

1. **Where will this run?** City-hall server, cloud, or vendor-hosted? This is
   the question that determines whether the project is deployable at all.
2. **Is there an existing domain** (e.g. `malabon.gov.ph` subdomain) it would
   live under? Right now permits print a verify link pointing at
   `localhost:5173`, which is a placeholder until a real hostname exists.
3. **Who maintains it after handover,** and in what language/stack is MISD
   comfortable? (Ours is PHP/Laravel + React, with an R service for analytics.)
4. **Backup and retention policy** — business permit records are legal
   documents. How long must they be kept, and who backs them up?
5. **Is there a city IT security review** we should pass before go-live?

---

## 7. Data privacy — RA 10173

1. **Who is the city's Data Protection Officer?** Our consent screen cites RA
   10173 and names "the City Government of Malabon" as controller. The DPO
   should approve that wording.
2. **Is there an existing privacy notice** for permit applications we should
   reuse verbatim rather than paraphrase?
3. **What is the retention period** for application data, and what happens to a
   rejected or abandoned draft?
4. **May we store applicant-uploaded IDs and documents**, and under what
   conditions?

---

## 8. One question that is not about systems, but should be asked

The adviser asked for renewal risk to be shown as **a probability per renewal**
("mga 3% ang risk nito"). We deliberately did not do that, and the reason is in
the spec: nothing is fitted, and the register records no outcome to have fitted
against, so a percentage would claim a precision the data cannot support. An
officer who reads "88%" as calibrated will act on it as calibrated.

We ship a transparent weighted rule score instead, with every rule and weight
printed on the same screen.

**Ask MISD (and route to the adviser): is that acceptable, or does the paper
require the probability wording?** If it requires it, the honest path is to
gather historical renewal outcomes first — which loops back to §3.6 above.

---

## Route to BPLO, not MISD

These will come up; they are process, not systems. Ask if MISD can arrange the
introduction rather than answering themselves.

- What is wrong with the current amendment form (item 52 — never specified)
- Whether processing-time monitoring appears in any report BPLO submits upward
  (r-integration open question 6.6)
- The exact corrected wording of the "Average Processing Time for (RA 11032)
  Tier" title (open question 1.3)
- Whether a business may hold a Mayor's Permit with no other clearance
  (we now require at least one — item 76 — which changed existing behaviour)
- Actual counter workflow: who receives, who assesses, who signs, in what order
