# Questions for Malabon City Hall

**What this is.** Every open question, unresolved ambiguity and unconfirmed
assumption in BizTrack, in one place. Nothing here is a complaint about the
city; each item is something the system currently guesses, simulates, or
refuses to do, and each one is waiting on an answer only City Hall can give.

**How to use it.** The sections are arranged by **who can answer**, not by
where the question came from, so a section can be handed to one desk without
the rest. Inside each section the items are ordered by how much work is blocked
on them — the first few are worth chasing, the last few can be answered by
email later.

Every item gives three things:

- **the question**, in plain language;
- **why it matters** — what breaks, or what we guessed;
- **what we assumed meanwhile**, where we have already shipped something on a
  guess. If an answer contradicts an assumption, we change the system; nothing
  here is defended.

**Sections.** A — BPLO (process, fees, policy) · B — MISD (systems, data,
hosting, accounts) · C — CPDO (zoning) · D — adviser and panel (academic and
reporting) · E — documents we need copies of.

This file replaces the older list in `docs/misd-questions.md`. If a question is
not here, it is not tracked.

---

# A. For BPLO — process and policy

## A1. Do applicants *choose* which clearances they need, or does BPLO decide?

BizTrack shows the six supporting clearances — Zoning, Sanitary, Fire,
Environmental, Occupancy, Market — as six cards the applicant picks from. Is
that right? Or does BPLO work out which ones a business needs from its line of
business and its location, and tell the applicant?

**Why it matters.** It changes the shape of the screen, and it changes who is
responsible if a clearance is missed. If BPLO determines it, a free choice lets
an applicant leave out a clearance they are legally required to hold, and the
system will happily process the filing without it.

**What we assumed meanwhile.** The applicant chooses. The system additionally
requires at least one clearance before submission, which is our own rule and
not something anyone at the city asked for. No individual clearance is ever
required — see **A9b**, which is this same question arriving from the other
direction: the Market Clearance is for stall holders, and to require it of them
we would first have to be told the premises is a stall.

## A2. When an office refuses to issue its clearance, what happens to the whole application?

Does the entire business permit application die? Does it wait until the
applicant fixes whatever is wrong? Or does that one clearance simply fail and
stand alone while the rest proceeds?

**Why it matters.** This is the single largest hole in the workflow. Today the
application only moves forward when **every** assigned office has marked its
part complete. There is no "this office said no" state at all — an office can
only send the whole application back to the applicant for correction, or the
application can be rejected outright in its entirety. So a genuine per-office
refusal would leave the filing stuck with no way out, forever.

**What we assumed meanwhile.** We gave every office a **"Return with remarks"**
action, which sends the whole application back to the applicant with the
reason. We did **not** build a standalone rejection. A related decision was
taken on the same guess: a rejected clearance is assumed not to kill the
application.

## A3. Is the Fire Code fee owed by every business every year, or only when BFP issues the certificate?

The Fire Safety Inspection Certificate fee (about ₱881 on a typical filing,
computed as 10% of the mayor's permit plus regulatory fees) is charged under
national law, RA 9514, and remitted to the Bureau of Fire Protection.

**Why it matters.** In BizTrack this fee is attached to the *Fire clearance*.
An applicant who says "I already hold a valid FSIC, here is a copy" is
therefore charged **nothing** for it. If the fee is in fact an annual charge on
an operating establishment rather than the price of issuing the certificate,
the system is letting people avoid it by uploading a copy — and the restructure
that moved the clearances after payment makes "upload the copy I already hold"
a prominent, easy path.

**The wording in the code we hold.** RA 9514 Sec. 12(b), recorded on the rule
itself, makes it *10% of all fees charged by the LGU in grant of the permit*.
Read plainly that attaches the fee to the permit grant, not to whether the
applicant asked us for an FSIC — which is why gating it on the clearance looks
wrong to us. We would rather be told than assume.

**What we assumed meanwhile.** We changed nothing. Deciding what a citizen is
charged on our own reading of a statute is not our call, so the behaviour was
written down rather than quietly fixed.

## A4. Is the sanitary inspection fee an issuance fee or an annual one?

The sanitary inspection fee (about ₱660, assessed on floor area, Revenue Code
Sec. 4D.01(m)) is attached to the Sanitary clearance. Is it charged because the
establishment is inspected every year, or only when the City Health Office
first issues the permit?

**Why it matters.** The Revenue Code draws exactly this distinction for
occupancy: there is a fee for issuing the Certificate of Occupancy, and a
separate *annual inspection* fee that our rules correctly mark as applying to
renewals only. Sanitary has no such split — one fee, charged on new filings and
renewals alike. Either the code genuinely does not distinguish them for
sanitary, or we have merged two fees that the counter treats separately.

**Why the difference bites.** "I already hold this one, here is a copy" is in
practice a renewal path — a brand-new business has no prior sanitary permit to
upload. So the fee that gets escaped is escaped by exactly the businesses that
owe it annually, if annual is what it is.

**What we assumed meanwhile.** One fee, charged whenever the sanitary clearance
is applied for, and nothing charged when a held copy is uploaded.

## A5. When someone already holds a valid clearance, what does BPLO charge?

Nothing at all? The inspection fee only? The full clearance fee again?

**Why it matters.** It decides what "Submit a copy I already hold" should cost.
Today it costs zero.

## A6. Are the clearance fees collected at the BPLO cashier, or does each office collect its own?

BPLO's guidance was "payment first, then they apply for the others". We need to
know whether that **first** payment already includes the clearance fees.

**Why it matters.** If BPLO collects everything, applying for a clearance in
BizTrack should add to one running balance the applicant settles at one
counter. If each office collects its own, applying should send the applicant to
that office, and our single balance is wrong.

**What we assumed meanwhile.** One ledger, two moments: the business permit is
paid to submit the application, and each clearance applied for afterwards adds
to a balance that must reach zero before the permit is released.

## A7. Can a business apply for a clearance after its permit has already been released?

A business that adds a food line in June needs a sanitary permit it did not
need in January.

**Why it matters.** If yes, that path has to exist on screen. **What we assumed
meanwhile:** yes, it is allowed — but it is not built. The clearances are now
chosen inside the application, before it is submitted, so there is currently no
door at all: a business in that position files again.

## A7b. When an office sends a filing back for revision, may the applicant add a clearance they now realise they need?

A returned filing is otherwise fully editable — documents, office sheets, the
business details — but it has already been assessed and paid for.

**Why it matters.** Adding a chargeable clearance to a paid filing means a
second bill, and a second bill is the whole mechanism this design deleted (see
`docs/clearances-before-payment.md`). **What we assumed meanwhile:** no. The
six close at submission and stay closed; the returned filing's message says to
contact the office.

## A8. Are clearance fees refundable if the applicant withdraws before the office acts?

**Why it matters.** Not modelled at all. Withdrawing before submission simply
takes the clearance off the assessment, because nothing has been charged yet —
so this only arises for a filing already paid, which is A7b.

## A9. May a business hold a Mayor's Permit with no supporting clearance at all?

**Why it matters.** We now require at least one, which changed how the system
behaved before. If a small retailer legitimately needs none, we are blocking a
valid filing.

## A9b. Is the Market Clearance compulsory for a stall holder — and how would the system know the premises is a stall?

Testing checklist item 98 reads: *"Market clearance should not be required. It
is only required for stall holders."* The first half is already true — the six
clearances are individually optional and the step only asks that **one** of them
is decided — so what we changed was the card, which now says the Market
Clearance is for a business trading from a stall in a public or private market.

Three things in that sentence are ours, not the city's, and we would rather be
corrected than keep guessing:

1. **"Only for stall holders" — is that the whole test?** Is it the premises
   being a stall, or is it the business renting from the city, or trading in a
   market building at all? Our wording says "a stall in a public or private
   market"; the tester said "stall holders" and did not say whether a privately
   owned market counts.
2. **Is it compulsory for those who do fall under it?** "Only required for stall
   holders" reads as *required* for them. We do not enforce that, and cannot:
   nothing in the application asks whether the premises is a market stall, so
   there is no fact to enforce against. If it is compulsory, we need the
   question — and then this stops being a card the applicant picks, which is
   **A1** all over again.
3. **Nobody is ever asked how many stalls.** The Business & Tax Profile step has
   a "Number of Stalls" question that only appears when MARKET is among the
   permit types, and the wizard passes it the business permit alone — so it
   never appears. The Revenue Code's markets-by-stall-count bracket therefore
   has no count to work from, which is part of why the Market Clearance's fee
   preview comes back as ₱0.00 (the other part is A9c below). Whether to ask
   depends on the answer to 2: a required clearance would ask before the
   assessment, an optional one after it is chosen.

**What we assumed meanwhile.** Optional, like the other five. The card names who
it is for and nothing compels it.

**Updated 5 August 2026 — the card is now derived, and point 3 is answered.**
The client came back on item 98 and asked for the Market Clearance to be
*hidden* from applicants it does not concern rather than shown to everyone with
a sentence explaining itself, and specifically not to be turned into a yes/no
put to every applicant in the city. So:

- The card appears when the filing's own declared revenue-code category is one
  of `public_market_100_plus_stalls`, `public_market_under_100_stalls`,
  `private_market` or `fish_broker_market` — the four the fee engine already
  prices per stall — or when a stall count greater than zero has been declared.
  Both are answered on Business & Tax Profile, the step before the clearances.
- **The derivation is known to be aimed slightly wrong, and this is the part we
  need corrected.** Those four categories describe a market **operator**. The
  "stall holder" of item 98 is that operator's **tenant**, and a fishmonger
  renting one stall will not have declared themselves a privately-owned market.
  So a pure filter would show the card to the landlord and hide it from exactly
  the group the complaint was about.
- Because of that it only ever *adds* the card. A plain control under the cards
  — "Trading from a stall inside a public or private market? Show the Market
  Clearance" — reveals it, and a clearance already applied for stays on screen
  whatever the derivation says.

**What we still need from you.** Is "stall holder" the right population at all,
or does the market operator need this clearance too — or instead? If there is a
fact the city already holds that says a premises is a stall (a stall register, a
lease with the City Market Administrator, a barangay/market address range), that
is the honest trigger and we would replace ours with it.

Point 3 above is now partly answered by us: the Market Clearance's own form
sheet asks for the number of stalls, because that sheet is the first screen in
the flow that knows the applicant holds a stall at all (item 109). **It is
collected but not yet priced** — office form answers are stored separately from
the fee profile the assessment reads, so the markets-by-stall-count bracket
still has no count to work from. Wiring it across is a fee-assessment change and
we would rather make it once, after A9c settles what the office charges.

## A9c. What does the City Market Administrator actually charge, and is it a permit fee at all?

The 2016 Revenue Code on file contains no public-market stall fee schedule —
Chapter V covers only city-owned commercial space leases at ₱200/sqm/month — so
the assessment has no rule to price a Market Clearance with and the line comes
out at ₱0.00, officer-set.

**Why it matters.** A stall rental is rent, not a permit fee, and it is probably
collected monthly by a different office on a different cycle from the annual Tax
Order of Payment this system issues. If so, putting it on the Tax Order of
Payment at all is wrong, however the number is arrived at.

**What we assumed meanwhile.** The line exists, reads ₱0.00, and an officer sets
it by fee adjustment. The card says applying "adds nothing to your assessment"
and that the office sets any charge separately, which is true but is not an
answer.

## A10. Which transactions are "simple", "complex" and "highly technical" under RA 11032?

The Ease of Doing Business Act gives an office 3 working days for a simple
transaction, 7 for a complex one and 20 for a highly technical one. Malabon's
own Citizen's Charter should say which bucket a business permit application
falls into.

**Why it matters.** The whole processing-time compliance report is measured
against this. Classify a filing wrongly and the office is reported as breaching
a deadline it never had, or passing a test it never took.

**What we assumed meanwhile,** and this is our invention entirely: renewals and
amendments are *simple*; a new registration is *complex*; a new registration is
*highly technical* only if a declared line is manufacturing, essential
manufacturing, contracting or an amusement place **and** the declared capital
is ₱1,000,000 or more.

## A11. What is the city's official list of non-working days?

**Why it matters.** RA 11032 counts in working days. Our calculation excludes
Saturdays and Sundays and nothing else, because we have no holiday calendar.
Around a long weekend it therefore counts more working days than really
elapsed, which makes the office look slower than it was.

## A12. May the Revenue Code section numbers be shown to applicants?

Item 18 of the testing checklist said "revenue code sections must not be
mentioned". Confirm that is final.

**Why it matters.** Officers still see the citations, applicants do not, and it
is one line of code either way. But an applicant who is told "₱660" with no
authority behind it has no way to check the charge.

**What we assumed meanwhile.** Hidden from applicants, both on screen and in
the data the browser receives; kept for staff.

## A13. Does "Others" really exist on the line-of-business list?

The tester's note on checklist item 67 reads: *"This is to be verified with the
LGU if others do really exist."*

**Why it matters.** We expanded the line-of-business list from 15 entries to
135 and added an **"Other (not listed)"** option that takes free text. If the
real list is closed, we should delete the escape hatch rather than collect text
that nobody downstream can process, tax or classify.

## A14. Has the 2016 Revenue Code been amended since?

Our entire fee engine — 423 active fee rules — is built from the **New Revenue
Code of the City of Malabon 2016, City Ordinance No. A10-2016, enacted 5
December 2016**. Is that still the operative instrument? Is there a later
ordinance, an indexation, or a separate schedule the counter actually uses?

**Why it matters.** Every peso the system quotes is only as current as that
document.

## A15. Where do we get the Presumptive Income Level schedule?

Section 2O.24 of the Revenue Code prints the heading "SCHEDULE OF MINIMUM GROSS
SALES" **with no table under it**, and a footnote delegating the schedule to
the City Treasury / Local Finance Committee.

**Why it matters.** It is genuinely absent from the ordinance, not lost in our
scan. Without it, presumptive assessments cannot be computed at all.

## A16. Is the garbage fee annual or quarterly?

Section 4F.01 calls it an "annual garbage generation fee", but Schedule A on
the same page is headed "PER QUARTER".

**Why it matters.** The garbage fee is one of the larger lines on a typical
assessment (about ₱1,650 on the sample we checked). A factor-of-four error on
it is a factor-of-four error on the bill. The contradiction is in the printed
ordinance itself; we transcribed it as printed and did not choose.

## A17. Can we have a certified copy of the ordinance, and can someone confirm a short list of printed figures?

We worked from a 223-page scan and recorded 98 places where the printed text is
garbled, a row is missing, or a figure breaks the pattern of the schedule
around it. We corrected none of them silently. The ones that touch business
permit fees are the ones we would like confirmed — the full list is in
`docs/revenue-code-extract.md`, Appendix A, and can be reduced to a one-page
sheet on request.

**Why it matters.** Examples of what we mean: the Mayor's Permit schedule (Sec.
3A.03) has an internet-café line with no base fee printed at all; a duplicate
permit is written "One Hundred Pesos (P 150.00)"; the transformer and generator
capacity fee table (Sec. 3V.01 item 4.b) is missing its rows entirely. Each is
a charge we cannot compute.

## A18. What does the counter actually do, in order?

Who receives the application, who assesses the fees, who inspects, who signs,
and in what sequence?

**Why it matters.** Our workflow was assembled from the manuscript and the
prototype. If the real order differs, the status the applicant sees will not
match what is happening at City Hall.

## A19. What exactly is wrong with our amendment form?

Checklist item 52 said the amendment form is incorrect, with no image and no
detail; items 82 and 84 said "refer to the physical/paper application".

**Why it matters.** We now collect four amendment kinds — **Ownership,
Location, Nature of Business, Others (specify)** — because those are the
checkboxes the database was built to hold. We have never seen the paper form.
If it asks for more, what we built is the floor, not the finish.

## A20. How does the counter identify which permit is being renewed?

**What we assumed meanwhile.** The permit number. Renewal now lists the
business's renewable permits and the applicant picks one. The tester said "it
needs to ask for ID or some sort" and pointed at the BPLS; we interpreted "ID"
as the permit number.

## A21. Do our reference numbers need to match a City Hall format?

We invented all of them: applications are `BIZ-2026-00001`, permits are
`MCB-2026-000001` (with `MCS` for sanitary, `MCF` for fire, `MCZ` for zoning,
`MCO` for occupancy, `MCE` for environmental, `MCM` for market), payments are
`PAY-2026-000001`, and each business gets a Business Account Number
`BAN-2026-0001`.

**Why it matters.** If BPLO already issues a business account number or a
permit number in a set format, ours will collide with the city's records and
every printout will carry the wrong reference.

## A22. Is there a Semi-Annual mode of payment, and if so what is it?

The renewal form (**MCG-BPLO-FO-002 v2.0**) prints three payment modes at the
foot of page 1 — **Annually · Semi-Annually · Quarterly**. BizTrack offers two,
Annually and Quarterly, and cites Malabon Revenue Code Sec. 2N for the
quarterly option. Either the form is ahead of the ordinance we transcribed, or
our reading of Sec. 2N is incomplete.

Three things we would like settled:

1. **Does the city actually accept a semi-annual payment today?** A tick box on
   a form is not proof that the cashier will take the money.
2. **If it does, under what authority?** We could not find a semi-annual
   instalment in the Revenue Code text we worked from. If it is in an amending
   ordinance, that is also the answer to **A14**.
3. **When would the two instalments fall due?** Quarterly is "within the first
   twenty days of January, April, July and October" and we say so on screen. A
   semi-annual option needs the same two dates before it can be offered, or the
   applicant is choosing a schedule nobody has told them.

**Why it matters.** It is a due date, not a label. The mode of payment decides
when the business tax falls due and therefore when a surcharge starts running,
so offering a third option we cannot date would put applicants on a schedule
the system cannot then hold them to — and the surcharge is the applicant's
money.

**What we assumed meanwhile.** Nothing has changed. `payment_mode` is still the
two values the ordinance we can read supports, and the wizard still offers
Annually and Quarterly only. We would rather offer two modes we can date than
three we cannot. If the answer is that Semi-Annually is real, this is a small
change — one option, one pair of dates, one line of help text — and we will
make it.

---

# B. For MISD — systems, data, hosting, accounts

## B1. Is eBPLS (or any business permit system) live in Malabon today, and is BizTrack meant to replace it, sit beside it, or feed it?

Which vendor, which version? Could we see it, even read-only, even for fifteen
minutes — a walkthrough rather than credentials?

**Why it matters.** This is the largest single unknown. It decides who owns the
register of record, what happens at handover, and a dozen smaller interface
arguments that we have been settling by guessing. If it must interoperate: is
there an export, an API, or a shared database, and what identifier joins one
business across the two systems?

**What we assumed meanwhile.** That BizTrack is the system of record. Nobody
has confirmed that.

## B2. Where will this run, and under what address?

City-hall server, cloud, or vendor-hosted? Is there a `malabon.gov.ph`
subdomain it would live under?

**Why it matters.** It determines whether the project is deployable at all.
Right now every issued permit prints a public verification link pointing at
`http://localhost:5173` — a developer placeholder that works on nobody's phone.
It becomes correct the moment a real hostname exists.

## B3. Should an office be able to see applications that are not its own?

Today every staff role — BPLO, health, fire, environment, building, zoning,
market and the administrator — can view every application in the system.

**Why it matters.** This is a policy question with a privacy consequence, and
it is one configuration change either way. Checklist item 56 raised it and it
has never been answered.

**What we assumed meanwhile.** Nothing changed; everyone still sees everything.

## B4. Is there an SMS gateway and an official email sender we may use?

Nothing is delivered today. Email is written to a log file and SMS to a file
called `sms.log`. No applicant has ever received a message from this system.

**Why it matters.** Renewal reminders, approval notices and requests for
documents are all built and all silent. Specifically we need: whether the city
already has an SMS provider (many LGUs do, for disaster alerts) that permit
reminders could use; an official city SMTP/domain to send from, because a
permit notice from a Gmail address will be read as a scam; and who owns the
sending reputation if a renewal reminder goes to several thousand businesses at
once.

## B5. Who is the city's Data Protection Officer, and what is the retention rule?

Our consent screen cites RA 10173 and names "the City Government of Malabon" as
the data controller. That wording should be approved by whoever owns it.

**Why it matters.** Four things hang on this: (a) is there an existing privacy
notice for permit applications we should reuse verbatim rather than paraphrase;
(b) how long must application data be kept, and what happens to a rejected or
abandoned draft; (c) may we store applicant-uploaded IDs and documents, and
under what conditions; (d) business permit records are legal documents — how
long must they be retained and who backs them up. We have documented none of
this, and it is the one requirement in our own traceability audit marked as an
outright miss.

## B6. Who is the current signatory for each office, and who may change them?

**Why it matters.** The permit certificate prints signatory names from a table
an administrator edits — deliberately never written into the template, because
signatories change. Only two names are loaded today, both for CENRO. Every
other office prints a blank signature line, which is honest but not usable. We
will not invent names.

## B7. Is our barangay list right?

We have 21: Acacia, Baritan, Bayan-bayanan, Catmon, Concepcion, Dampalit,
Flores, Hulong Duhat, Ibaba, Longos, Maysilo, Muzon, Niugan, Panghulo, Potrero,
San Agustin, Santulan, Tañong, Tinajeros, Tonsuya, Tugatog.

**Why it matters.** Is that the authoritative list, with the official
spellings? We have not checked it against the PSGC. We already caught
"Poblacion" being used as a Malabon barangay in a placeholder — it is not one —
which is exactly the kind of error that ends up printed on a certificate.

## B8. Are our office names and abbreviations the ones the city uses?

We write: **BPLO** Business Permits and Licensing Office · **CHO** City Health
Office · **BFP** Bureau of Fire Protection · **CPDO** City Planning and
Development Office (Zoning) · **OBO** Office of the Building Official ·
**CENRO** City Environment and Natural Resources Office · **CMO-MARKET** Office
of the City Market Administrator.

**Why it matters.** These appear on screens, on the Tax Order of Payment and on
the certificate.

## B9. Is our line-of-business list the right one?

We hold 135 PSIC codes, taken from the 2009 Philippine Standard Industrial
Classification, plus the "Other (not listed)" entry. We have not verified them
against the PSA, and we do not know whether BPLO codes businesses by PSIC at
all.

**Why it matters.** Fees, statistics and the zoning conformance question all
key off this code. See also A13 on whether "Others" exists.

## B10. Is there historical permit data we could load?

Past applications, decision dates, renewals, closures.

**Why it matters.** Every number in our analytics currently describes invented
data. On real history the same screens would say something true. It is also the
honest answer to the adviser's "where is the forecasting?" — no model can be
fitted without real outcomes to fit it to (see D1).

## B11. How are staff accounts created and removed today?

Is there an existing directory — Active Directory, LDAP, Google Workspace —
that accounts should come from, rather than being created inside our app?

**Why it matters.** It decides whether we build user management or connect to
what exists. It also decides what happens when a staff member leaves.

## B12. Who is the super administrator in practice — MISD or BPLO?

**What we assumed meanwhile.** We moved the analytics dashboard to BPLO on the
client's instruction, so BPLO staff can see it and the system administrator
role cannot. Confirm that is right.

## B13. Who maintains BizTrack after handover, and is there a security review to pass?

Our stack is PHP/Laravel and React, with a separate R service for the
statistics. Is MISD comfortable with that? Is there a city IT security review
we should pass before go-live?

## B14. Payments are simulated — what is the real path?

Every payment in BizTrack completes instantly against a simulated gateway; no
money moves.

**Why it matters.** Before this is used for real we need to know whether the
city accepts online payment at all, through which provider, and how a
BizTrack-issued reference reconciles against the City Treasurer's receipt.

---

# C. For CPDO — zoning

Zoning is the one place BizTrack currently tells an applicant something it
cannot support. Please read C1 first.

## C1. Should the system give a zoning verdict at all, or only record the location?

When an applicant pins their location and names their line of business,
BizTrack shows a **green "CONGRATULATIONS!"** panel saying the business "is
conforming / within the allowed use" for that barangay. Beneath it, in small
type, it says CPDO makes the final determination during processing.

**Why it matters.** **The system holds no zoning data whatsoever.** There are
no zone boundaries, no land-use classifications, and no rules. That headline is
not computed from anything — it is what the screen always says. (There is a red
"non-conforming" version, but nothing in the system ever triggers it; it can
only be reached by hand for demonstration.) An applicant who signs a lease on
the strength of that screen has been misled by us.

**What we assumed meanwhile.** An earlier, cautious build said "Location
recorded" instead. The client's paper overruled that and the celebratory
wording was restored, with CPDO's final say kept as the one honest line. **We
need this decided either way** — it determines whether we build a real
conformance check or remove the claim.

## C2. Does the city have a zoning map in digital form?

A shapefile, GeoJSON, KML, a CAD drawing, or a QGIS/ArcGIS project.

**Why it matters.** This is the single ask that would turn C1's screen honest.
With boundaries, the system can answer the question it is currently pretending
to answer.

## C3. If not digital — is there an authoritative printed zoning map, and the Zoning Ordinance / Comprehensive Land Use Plan behind it?

**Why it matters.** Even a scan plus the ordinance text would let us classify by
barangay, which is a real answer, instead of by coordinate, which today is no
answer at all.

## C4. What zone classifications does Malabon use, and which business activities are allowed in each?

C-1, C-2, R-1, I-1, institutional, and so on.

**Why it matters.** That table is the other half of a real conformance check.
We already collect the applicant's line of business on the same screen, so the
moment we have the table the check becomes genuine.

## C5. Is a per-location conformance answer appropriate to automate at all, or must CPDO always rule case by case?

And who at CPDO owns that determination? Would they accept a system-generated
preliminary result even as an indication?

**Why it matters.** If the answer is "case by case, always", then C1 is
resolved by changing the wording, and C2–C4 stop being blockers.

## C6. Is there a coastline, waterway or no-build layer we may use?

Malabon is a river delta — the Tullahan and the Tenejeros-Tanza run through it,
and there is a fishpond belt.

**Why it matters.** Checklist item 86 asked us to stop applicants pinning a
business on water. We deliberately did **not** build it and the screen never
claims water was checked, because there is no hydrography data here and a check
that silently passed everything would be worse than none. Under the Water Code
there is a legal easement along waterways; if that layer exists the check
becomes real.

## C7. Is there a flood-hazard or no-build overlay the city already uses?

**Why it matters.** If a site cannot be permitted, saying so before the
applicant pays is the whole value of putting the map first in the flow.

## C8. Can we have the city's actual boundary?

**Why it matters.** Today we refuse any pin outside a rectangle drawn around
Malabon (roughly 14.645–14.700 N, 120.930–120.985 E). A rectangle around an
irregular city necessarily includes slivers of Navotas, Caloocan and
Valenzuela, so an address just outside the city can be accepted. The applicant
is never told the boundary was verified, because it was not.

## C9. Is the Zoning / Locational Clearance the right card, with the right name, and does it have its own form? — ANSWERED

**Answered by the form itself.** CPDD supplied a blank copy of *Application for
Locational Clearance (Business Activities)* — Document ID **MCG-CPDD-FO-003**,
Version **1.2**, Effectivity **01-09-2026**, page 1 of 1. It is issued by the
**City Planning and Development Department**, which is CPDD on the letterhead
and CPDO in our register.

So yes: the clearance is applied for on its own paper form, the card belongs in
the LGU section, and "Zoning / Locational Clearance" is the right name for it.

**What changed in BizTrack.** The sheet was rebuilt field-for-field against the
paper. Two of the questions we had been asking are gone, because CPDD does not
ask them:

- **Proposed Land Use** (Commercial · Industrial · Residential · Social,
  Educational or Institutional · Ancillary) — deleted. Those were the fee
  schedule's categories, not the form's. It was also the only *required* field
  on the sheet, so the sheet now requires nothing, which is what the paper does.
  CPDD asks **V. Activity (please specify)** and **VIII.E Type of Industrial
  Project** instead, and we already hold the first.
- **Is the business already operating at this address? / Operating since** —
  deleted. This was the field we flagged as the one we were least sure of, and
  the answer is that it does not exist. It is gone from the screen and from the
  API's validation.

Two questions were added, because the form asks them and we did not hold them:
**VIII. Project Description** (free text) and **VIII.E Type of Industrial
Project**.

Everything else the form asks, the filing already answers, and the sheet carries
it read-only rather than asking twice:

| On the form | Who answers it in BizTrack |
|---|---|
| I. Name of Proprietor / President / General Manager, Contact No., Email Add. | the system — the signed-in account |
| **II. Home Address** | **nobody — see the gap below** |
| III. Name of Firm, Contact No. | the system — the business record |
| IV. Address of Firm · VI. Location / Address of Project | the system — the business address |
| V. Activity (please specify) | the system — the line(s) of business (PSIC code and description) |
| VII. Nature of Application — New Business / Renewal | the system — the application's own type |
| VIII. Project Description | **the applicant** (new) |
| VIII.A Floor Area to be / being utilized | the system — Business & Tax Profile |
| VIII.B No. of Storey of Building | the system — Business & Tax Profile |
| VIII.C / D Name and Address of Lessor (if lessee) | the system — the rental answer on Location & Zoning |
| VIII.E Type of Industrial Project | **the applicant** (new) |
| IX. Authorized Representative | **the applicant**, but asked once — on the Fire Safety (FSIC) sheet when that clearance is part of the filing, and on this sheet when it is not |
| Application No. / Date / Time (top right) | the system — filled on receipt |

**The one field we cannot fill: II. Home Address.** The form asks the
proprietor's home address, and BizTrack records no such thing anywhere — not on
the account, not on the business. We have deliberately **not** invented a box
for it on the zoning sheet, because it is an answer about the person and it
would then be asked again by the next form that wants it. It belongs on the
account. Flagged here so it is not mistaken for an oversight.

### What the form does not settle

1. **The Sketch of the Location.** Section between IX and X asks for a
   hand-drawn map "including street names and nearby recognizable landmarks",
   filling roughly a third of the page. BizTrack has a map pin, and a pin is a
   coordinate, not a sketch — it does not carry street names or landmarks and it
   is not what CPDD is looking at. Doing this properly means either an **image
   upload** (the applicant draws it, photographs it, attaches it) or a **drawing
   canvas** in the browser. **Would CPDD accept a generated map extract with the
   pin and surrounding street names on it instead of a drawing?** If yes, we can
   produce that from what we already hold. If no, we need to know which of the
   two we should build. **Nothing is built for this today.**
2. **"MUST BE NOTARIZED PRIOR TO SUBMISSION OF APPLICATION."** Section X, the
   applicant declaration, is sworn before a notary — Doc. No., Page No., Book
   No., Series of. BizTrack's flow has no notarisation step at all: an
   application is filled in, submitted and paid for without ever leaving the
   browser. **How is this meant to work online?** The options we can see are (a)
   the applicant notarises a printed copy and uploads the scan as a document,
   (b) the city accepts an electronic declaration for the online channel, or (c)
   notarisation happens at the counter when the applicant collects the
   clearance. This is a policy answer, not a build decision, and we are not
   guessing at it. **Nothing is built for this today.**
3. **Is VIII.E one choice or two?** The form prints five tick boxes in three
   columns — POLLUTIVE above NON-POLLUTIVE, HAZARDOUS above NON-HAZARDOUS, then
   OTHER — which reads as two independent judgements rather than one choice of
   five. A project could plainly be both pollutive and hazardous. We ask it as a
   single choice. **May an applicant tick more than one?**
4. **Does the form's checklist of requirements match ours?** The paper lists:
   TCT, Tax Declaration and Real Property Tax Clearance *if the property is
   owned*; Contract of Lease and Consent from the Lot Owner *if it is rented*;
   DTI / SEC Articles; an Authorization Letter for representatives; and the
   completed form. BizTrack attaches a different list to the zoning clearance.
   **Consent from the Lot Owner in particular is a document we do not ask for at
   all.** This overlaps E9; answering it there answers it here.

**MARKET still has no sheet**, for exactly the reason ZONING had none until the
form arrived: nobody has shown us what the City Market Administrator asks for.
If that office has a form, we need it too — see E4.

## C10. Is ₱735 the current charge for a zoning / locational clearance?

We compute it from Revenue Code Sec. 3.D.01 as ₱45 filing + ₱345 land use
verification + ₱345 processing.

**Why it matters.** It is the only clearance fee we derive entirely from the
2016 ordinance with no counter confirmation at all.

---

# D. For the adviser and panel — academic and reporting

## D1. Renewal risk: may it stay a transparent score, or must it be a probability?

The adviser asked for renewal risk to be shown as a probability attached to
each renewal — *"mga 3% ang risk nito"* — and the mockup's column header reads
"PROB. DELAYED" with values like 88% and 81%.

**Why it matters.** No model exists and nothing has been fitted. The register
records no historical renewal outcomes, so there is nothing to fit against.
Printing "88% probability" would claim a precision the data cannot support, and
a BPLO officer would reasonably act on it as if it were calibrated. This is a
direct conflict between what the adviser asked for and the honesty constraint
written into `docs/r-integration-spec.md`, and it needs to be resolved out
loud rather than quietly resolved in code.

**What we assumed meanwhile.** We ship a transparent weighted rule score — days
to expiry, past renewal punctuality, open compliance findings, inspection
history — with every rule and weight printed on the same screen, banded
High/Moderate/Low, and never labelled a probability.

**If the paper requires the probability**, the honest route is to obtain
historical renewal outcomes first and report the calibration. That loops back
to B10.

## D2. The exact corrected wording of the processing-time chart title

On the printed page, parentheses were added around **(RA 11032)**, the word
"by" was struck out, and a replacement word was written underneath, reading as
"for". That gives **"Average Processing Time for (RA 11032) Tier"**, which is
what we shipped. But the inserted word overlaps the printed word "Tier", so it
is possible "Tier" was meant to be deleted too, giving **"Average Processing
Time (RA 11032)"**.

**Why it matters.** Two readings, one pen stroke. We are not going to guess at
a title she will read back to us.

## D3. What does the office call a period that missed its service standard?

We call it a "flagged week". The note on the paper reads "— Government Terms",
and the meeting note was *"i-check sa office kung tama ba 'yung term ng flagged
weeks"*.

**Why it matters.** It is BPLO's own vocabulary, and it belongs to whoever
files the report. Ask BPLO, report to the adviser.

## D4. Does the office report in weeks?

*"Baka naman hindi weeks ang tao — kasi meron pong operational terms."*

**Why it matters.** Every processing-time chart is bucketed by week. If BPLO
reports monthly or by quarter, the buckets are wrong and the charts cannot be
compared with anything the office already submits.

## D5. Does departmental processing-time monitoring appear in any report BPLO actually submits?

The adviser's most forceful question was whether each data element is in an
official report — *"Is it included in the report? It is the most usual question
that is being asked."*

**Why it matters.** If the answer is no, the feature has to be justified some
other way or cut. Ask BPLO, report to the adviser.

---

# E. Documents we need copies of

Please ask for **copies**, not descriptions. Every attempt to work from a verbal
description has produced a form that does not match the real one.

A single question to pair with all of these: **does MISD already hold any of
them as a fillable PDF or a form template?** If MISD holds the master, we
should match their field names rather than invent our own.

## E1. The current business permit application form, as issued at the counter

**Why it matters.** This has been blocked since the first round of testing
(item 2). Our application wizard is assembled from the project manuscript and a
prototype, not from the form the counter hands out. Comparing what we collect
against the standard national unified form, the fields we most suspect are
missing are: **lessor's name, address and monthly rental** where the premises
are rented; an **emergency contact**; the **number of employees residing in
Malabon**; and any **tax incentives enjoyed**. We would rather see the form
than build to that guess.

## E2. The amendment form

**Why it matters.** See A19. We collect four amendment kinds because the
database was built to hold them; nobody has checked them against the sheet.

## E3. The renewal form, or the BPLS renewal screen

**Why it matters.** See A20. We have never seen it.

## E4. The zoning / locational clearance application form (CPDD) — ANSWERED

**Received.** *Application for Locational Clearance (Business Activities)*,
Document ID **MCG-CPDD-FO-003**, Version **1.2**, Effectivity **01-09-2026**.
This was the most urgent item in section E — BizTrack was showing applicants a
zoning sheet assembled from the Revenue Code, containing fields CPDD had never
seen. The sheet has been rebuilt against the paper; **C9** lists what was
deleted, what was added, and the four things the form still leaves open (the
sketch of the location, the notarisation requirement, whether the industrial
project type is one choice or two, and the checklist of requirements).

**One thing on this form is worth flagging to whoever holds the master copy:**
it is marked a controlled document, Version 1.2, and says obsolete copies must
be returned on revision. If CPDD reissues it, BizTrack's sheet goes stale
silently. **Who should tell us when the version number changes?**

**Still needed, for the same reason E4 existed:** the **Market Clearance**
application form, if the City Market Administrator issues one. It is now the
last of the six clearances with no sheet at all.

## E5. A blank Mayor's / Business Permit certificate

**Why it matters.** BizTrack now prints a certificate the business owner can
download. Ours is a reasonable facsimile; the real one has a specific layout,
seal placement and legal footer that we cannot invent.

## E6. The Tax Order of Payment as it is printed today

**Why it matters.** It is the document our fee breakdown is meant to reproduce,
and it settles A12 — whether the Revenue Code section numbers appear on the
copy the applicant receives.

## E7. A filled sample of each of the above, with dummy data

**Why it matters.** Field order and wording can be guessed from a blank form.
What cannot be guessed is which fields staff actually leave blank in practice —
and those are the ones we should not be making mandatory.

## E8. The Presumptive Income Level schedule from the City Treasury

**Why it matters.** See A15. It is missing from the printed ordinance.

## E9. Which of these seven documents does the paper form actually ask for?

This one can be answered without sending anything — it is a list to tick, and
it unblocks tester checklist item 96 ("the documentary requirements should
match what is asked in the paper application form (should be 6)").

BizTrack attaches seven documentary requirements to the Mayor's / Business
Permit. Please mark each **required**, **required only sometimes** (and say
when), or **not asked for**:

| # | What we ask for | When we ask | Required? |
|---|---|---|---|
| 1 | Business Registration (DTI / SEC / CDA certificate) | every filing | |
| 2 | Lease Contract or Land Title | every filing | |
| 3 | Barangay Business Clearance | every filing | |
| 4 | Community Tax Certificate (Cedula) | every filing | |
| 5 | Valid Government ID | every filing | |
| 6 | Occupancy Permit | every filing, optional | |
| 7 | Previous Mayor's Permit | renewals only | |

Two follow-ups:

- **Is anything missing from this list?** A document the counter asks for that
  is not one of the seven is worse than an extra one, because the applicant
  arrives at City Hall without it.
- **Does the counter ask for the Occupancy Permit at application time, or only
  once the Office of the Building Official has issued it?** See below.

**Why it matters.** The tester counted seven and expected six, and we cannot
settle it from here. A new filing already shows six: the previous Mayor's
Permit is asked for on renewals only, since a business being registered for the
first time has none. A renewal shows seven, and the seventh is the permit being
renewed. So the number depends on which form you are holding — and whether
either number is right depends on E1, the form itself, which we have still
never seen.

**What we assumed meanwhile.** We removed nothing from the list, because
deleting a requirement on a guess is the one mistake here that reaches the
counter: an applicant told they need six documents, who turns up without the
seventh, is turned away.

The single change we did make is that the **Occupancy Permit no longer blocks
submission** — it is still asked for, now marked optional. Two things pointed
the same way: our own help text has always said "where applicable", and since
the restructure in `docs/clearances-after-payment.md` the Occupancy Permit is
one of the six LGU clearances the applicant applies for in a separate stage
*after* the first payment. Requiring the certificate at step 4 asks a new
business in new premises to attach a document the system is about to walk them
through obtaining. If the answer to the table above is that BPLO does require
it of everyone up front, this reverses with one migration.
