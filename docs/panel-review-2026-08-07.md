# BizTrack — every analytics panel, one at a time

**Prepared 7 August 2026.** Read-only review against the live register. Nothing was changed.

Every panel on every analytics screen is below, judged three ways:

- **Is it right?** — is the number correct, and does its label say what the number actually is?
  These are two different faults. A figure can be arithmetically perfect and still be
  mislabelled, and where that happens it is said plainly.
- **Is it good?** — can the person reading that screen do anything with it?
- **Can it be changed?** — *on screen* (a control exists), *by settings* (no new software),
  *needs a code change and a redeploy*, or *fixed by law*.

Each panel ends with one of four verdicts:

| Verdict | Meaning |
|---|---|
| **SOLID** | Correct, honestly labelled, useful. Defend it as it stands. |
| **WORKS, WITH A CAVEAT** | The number is right. There is one thing to say before someone else says it. |
| **WEAK — expect a question** | A reasonable person will query this. Have the answer ready. |
| **WRONG** | It is telling the reader something untrue. Say so first, do not be caught. |

All figures quoted were read from the live system on 6 August 2026 and re-checked by querying
the register directly.

---

## Summary — read this page alone if you read nothing else

**38 panels reviewed: 16 SOLID · 13 WORKS, WITH A CAVEAT · 7 WEAK · 2 WRONG.**

| # | Panel | Screen | Verdict | In one line |
|---|---|---|---|---|
| 1 | Active Businesses | Dashboard | WORKS, WITH A CAVEAT | 470 is right, but four of them hold a permit that has not started yet. |
| 2 | Applications (all time) | Dashboard | SOLID | 1,670 — every filing ever. The old "YTD" wording has already been corrected. |
| 3 | This Month | Dashboard | WEAK | Reads 48. Only 21 were actually submitted; 27 are unfinished drafts. |
| 4 | Compliance Rate | Dashboard | WORKS, WITH A CAVEAT | 39.7% is correct but uses a strict all-permits-current test. Be ready to explain why it is low. |
| 5 | Application Volume | Dashboard | WEAK | Same draft inflation as This Month, and the period filter above it does not move this panel. |
| 6 | Decision Outcomes | Dashboard | WEAK | 72.7% approval is correct — off eleven decisions. "Pending 37" is mostly unsubmitted drafts. |
| 7 | Average Processing Time (RA 11032) | Dashboard | WORKS, WITH A CAVEAT | Arithmetic exact. It reports the city breaching all three legal limits — and our tier rule is not yet LGU-approved. |
| 8 | Average Processing Time by Department | Dashboard | WORKS, WITH A CAVEAT | All seven offices sit between 2.0 and 3.2 days. Naming a "slowest" office off that spread over-reads it. |
| 9 | Compliance card — RA 11032 processing | Dashboard | SOLID | 27.6%, and it agrees exactly with the tier panel above. |
| 10 | Compliance card — Business permit | Dashboard | SOLID | 39.7%, counts and definition both shown on the card. |
| 11 | Compliance card — Renewal | Dashboard | SOLID | 61.5%, and it refuses to compute rather than print a false 0%. |
| 12 | Permits Approaching Expiry | Dashboard | WORKS, WITH A CAVEAT | Forward counts correct. The 3,143 already-expired figure dominates and no reminder goes to most of them. |
| 13 | Inspections | Dashboard | SOLID | 87.1% pass rate, divided by completed inspections and it says so. |
| 14 | Top Five Barangays | Dashboard | SOLID | Totals to 470, matching Active Businesses exactly. |
| 15 | Top Five Business Categories | Dashboard | WORKS, WITH A CAVEAT | Totals to 472 against 470 businesses. Two businesses with two trades are counted twice. |
| 16 | Form of Organization | Dashboard | SOLID | 710 of 711 recorded, and the one blank is shown rather than hidden. |
| 17 | Officer Activity | Dashboard | WORKS, WITH A CAVEAT | Now two cards, not the paper's three. The meetings figure was removed on purpose. |
| 18 | GIS Mapping | Dashboard | SOLID | All 711 businesses plotted; nothing is being hidden by the cap. |
| 19 | Risk-band summary cards | Renewal Risk | SOLID | 308 + 203 + 2,078 = 2,589 exactly. |
| 20 | Reminders Sent | Renewal Risk | WEAK | Reads 1. That is literally true and it looks like the reminder system does nothing. |
| 21 | Businesses Requiring Review | Renewal Risk | WORKS, WITH A CAVEAT | Scores and actions are sound. The window says "next 12 months" but includes permits already lapsed. |
| 22 | The rule book / weights | Renewal Risk | SOLID | Five rules, 100 points, every one explained on screen. The strongest panel in the product. |
| 23 | The filters | Renewal Risk | SOLID | Five working filters. The most controllable screen we have. |
| 24 | Business Growth Rate | Growth | SOLID | +47.1% — 275 new against 187 the year before. Verified to the last digit. |
| 25 | Top Growing Barangays | Growth | WORKS, WITH A CAVEAT | Ranked by change, correctly. But one row prints "+1500%" off a base of one business. |
| 26 | Business Status Summary | Growth | SOLID | 470 / 132 / 109 / 61 = 772, and Active agrees with the dashboard. |
| 27 | Business Renewal Performance | Growth | WORKS, WITH A CAVEAT | Method is correct and the caveat sentence travels with it. The number describes demo history. |
| 28 | Business Closure Trend | Growth | WEAK | The last point on the line is this month — six days old — drawn as a finished month next to July's 22. |
| 29 | Business Industry Growth Trend | Growth | WEAK | Ranked by size, not growth. Two of the six industries listed shrank. |
| 30 | Process Status Indicator | Processing Time | **WRONG** | Five of seven offices are red today, and every one of them is red for being *fast*. |
| 31 | Department Processing Time Chart | Processing Time | **WRONG** | Six weeks are labelled "Faster than normal" that the panel beside them calls slower. |
| 32 | Noted Delays | Processing Time | SOLID | This is the panel that gets the direction right. When the two disagree, this one is correct. |
| 33 | Gradual Slowdown Warnings | Processing Time | WEAK | Only one office is flagged, and that office's slowdown was planted by the demo data. |
| 34 | Nearby Similar Businesses | Location Insights | WORKS, WITH A CAVEAT | A zero here is correct. It sits above a row that counts a different thing. |
| 35 | Business Concentration | Location Insights | SOLID | 34 businesses, High band, and the band boundaries are printed. |
| 36 | Most Common Line of Business | Location Insights | WORKS, WITH A CAVEAT | Now names a real trade rather than a catch-all. But 4 of 34 is a thin majority. |
| 37 | Average Distance to Similar Businesses | Location Insights | SOLID | Correct, and it says nothing rather than zero when there is nothing to average. |
| 38 | Notifications | Notifications | WORKS, WITH A CAVEAT | 10,561 notices raised and delivered in-app. Email and text are simulated, not sent. |

### The four things to say before you are asked

1. **The Process Status Indicator is showing this week, and this week is not over.** Every one of
   the seven offices is being judged on a three-day-old week. That is why five are red and why all
   five are red for finishing work too quickly. Panels 30 and 31.
2. **The Industry Growth Trend ranks the largest industries, not the fastest-growing ones.** Two of
   the six listed actually shrank. Each row is honestly labelled "declining", but the panel title
   says growth. Panel 29.
3. **Our rule for which filings are simple, complex or highly technical is our own and the LGU has
   not signed it off.** The 3, 7 and 20 working-day limits are the law and are not ours to move.
   The question of which bucket a business permit falls into is an open question with Malabon.
   Panel 7.
4. **The slowdown visible at the City Health Office is planted by the demo data**, not observed. It
   is an eight-week ramp written into the sample history so the detector had something to catch.
   If someone points at it, say so. Panel 33.

---

# Analytics Dashboard — BPLO

The screen carries one period control, top right: **Last 3 / 6 / 12 / 24 / 36 months**, default 12.
It moves some panels and not others, and each panel states its own window in small type: *Last 12
months to 6 Aug 2026*, *As of 6 Aug 2026*, or the name of the current month. That labelling is
careful and it is worth pointing out — it is the thing that stops the mixed windows from being a
defect.

A "Computed" stamp sits at the top of the screen. These figures are recomputed nightly at 3am;
they do not change when the page is reloaded, and the screen says so.

---

## 1. Active Businesses — 470

**Is it right?** The number is right for what the register knows. It counts businesses holding at
least one permit marked active, each business once however many permits it holds. It excludes
businesses removed from the register. Counting the same thing by hand against the register gives
470.

The label under it reads *"holding a permit valid today"*, and that is very slightly stronger than
the arithmetic. The count follows the permit's status flag, not its dates. Twenty-five active
permits have a start date in the future, and four businesses are in the 470 only because of one of
those. One permit is still flagged active a day past its end date. So the true "valid at this
moment" figure is 466. A four-business gap on 470 is under one per cent, but if someone audits it
line by line they will find it.

**Is it good?** Yes — this is the denominator most of the screen hangs off, and it is the first
question any city officer asks. It is also the figure that agrees across three separate screens,
which is worth demonstrating: the Business Status Summary on the Growth screen and the Top Five
Barangays panel both independently arrive at 470.

**Can it be changed?** The number itself, no — it is a count of the register. The definition of
"active" would need a code change and a redeploy. The period filter does not affect this card by
design, and the card says *As of today*.

**Verdict: WORKS, WITH A CAVEAT.**

---

## 2. Applications (all time) — 1,670

**Is it right?** Yes, exactly. Every filing on record from creation, less the nine removed from the
register. The register holds 1,679 rows; 1,670 remain.

This card used to be titled "Applications YTD" and the number was never year-to-date — it has
always been the full-term total. **That has already been corrected**: the card now reads
*"Applications (all time)"* with the note *"every filing on record"*. If anyone arrives with a
printout saying YTD, the honest answer is that the label was wrong, we found it, and we fixed it
rather than changing the number. The true year-to-date figure, if anyone wants it, is 541.

Thirty-seven of the 1,670 are drafts nobody submitted. The card's own explanation says so.

**Is it good?** Moderately. It is a workload-to-date figure and it sits beside This Month so the
total and the current load read together. Nobody acts on it, but it frames everything else.

**Can it be changed?** No control. Switching it to a genuine year-to-date figure would be a code
change and a redeploy.

**Verdict: SOLID.**

---

## 3. This Month — 48

**Is it right?** The number is right. The label is not doing enough work.

Forty-eight filings were created since 1 August. But **only 21 of them were actually submitted**.
The other 27 are drafts sitting half-finished in applicants' accounts — and they are almost
certainly demo and testing activity, because the whole register only holds 37 drafts in total and
27 of them were created in the last six days.

So a reader looking at this card believes 48 filings arrived at the counter this month. Twenty-one
did. The card is counting things nobody has asked the office to do yet.

The card is also a part month and admits it — the explanation behind it says *"On the 3rd this is
three days of filings, not a monthly rate"*, which is good and honest.

**Is it good?** In its current state, not really. It is meant to answer "how busy are we this
week", and it answers it with a number more than twice the truth. Once the demo drafts are cleared
it will behave.

**Can it be changed?** The window, no — it is always the current month. Excluding drafts, or showing
submitted and draft counts side by side, is a code change and a redeploy.

**Verdict: WEAK — expect a question.**

---

## 4. Compliance Rate — 39.7%

**Is it right?** Yes. 239 businesses of the 602 that have ever been issued any permit currently
hold a valid permit for *every* type they have been issued. Verified: 602 is exactly the number of
businesses with permit history, once removed businesses are excluded.

The strictness is the thing to explain. A business with a current mayor's permit but a lapsed fire
certificate counts as non-compliant. That is a deliberate choice and it is what makes the number
39.7% rather than something flattering. The definition is printed on the screen.

**Is it good?** Yes, and it is the one number leadership will ask for. But it is low, and a panelist
will read 39.7% as "the city has failed". The correct framing is that it measures full clearance
coverage across all seven permit types, not mayor's-permit compliance alone — and that most of the
shortfall is expired supporting clearances, which is exactly what the Permits Approaching Expiry
panel exists to work through.

**Can it be changed?** The number, no. The per-type strictness would be a code change and a
redeploy. Note that the same figure appears again lower down the screen as the *Business permit
compliance* card, with its counts shown — that is deliberate, so the headline can be traced.

**Verdict: WORKS, WITH A CAVEAT.**

---

## 5. Application Volume — 48 this month (New 40, Renewals 7, Amendments 1)

**Is it right?** The split is exactly right — checked against the register, 40 new, 7 renewals, 1
amendment. The panel is labelled with the current month, not the period filter, and that is correct
and stated.

Two things to be ready for. First, the same draft inflation as the card above: 27 of these 48 are
unsubmitted. Second, **the period control at the top of the screen does not move this panel.** Change
it to 36 months and Application Volume still shows August. That is intentional and the panel note
says "August 2026" — but a panelist who changes the filter and watches half the screen not move
will ask why, and it is better to volunteer it than to be asked.

**Is it good?** The idea is right — renewal season and new-registration season staff differently, so
knowing the mix matters more than knowing the count. All three types are shown even at zero, so an
empty row means none were filed rather than a missing row.

**Can it be changed?** No control today. Making this panel follow the period filter would be a code
change and a redeploy.

**Verdict: WEAK — expect a question.**

---

## 6. Decision Outcomes — 72.7% approval

**Is it right?** The arithmetic is exact: 8 approved, 1 returned for revision, 2 rejected — 8 of 11
decided filings, 72.7%. Pending and cancelled are excluded, and the footer says so: *"pending
filings are excluded"*.

Two caveats, both real. **A rate off eleven decisions is a fragile headline.** One more rejection
moves it six points. The panel gives the counts underneath, which rescues it, but the big number
is the one people read.

And the "Pending 37" slice is misleading in a way the panel does not disclose. Twenty-seven of
those 37 are drafts nobody submitted. Only ten filings are genuinely sitting on an officer's desk
awaiting a decision. A reader sees a backlog of 37; the real backlog is 10.

**Is it good?** The concept is sound — it measures how the office decides, not how fast, and
excluding pending filings is why a growing backlog does not flatter it. But with this month's
numbers it is too thin to act on.

**Can it be changed?** No control; it is always the current month. Separating drafts from genuinely
pending filings would be a code change and a redeploy.

**Verdict: WEAK — expect a question.**

---

## 7. Average Processing Time (RA 11032)

| Tier | Legal limit | Filings | Average | Inside the limit |
|---|---|---|---|---|
| Simple | 3 working days | 466 | 7.1 days | 101 (21.7%) |
| Complex | 7 working days | 192 | 8.5 days | 89 (46.4%) |
| Highly technical | 20 working days | 44 | 26.1 days | 4 (9.1%) |

**Is it right?** The arithmetic is exact and it is internally consistent: the three tiers total 702
filings and 194 inside their limits, which is precisely what the RA 11032 compliance card further
down the screen reports as 194 of 702. Two panels computed separately agreeing to the digit is
worth demonstrating.

Working days skip weekends. Public holidays are *not* allowed for, and the screen says so — which
means a real turnaround is never slower than shown, only faster. That is the safe direction to be
wrong in.

**The caveat is not the arithmetic, it is the classification.** The 3, 7 and 20 working-day limits
are Republic Act 11032 and are not ours to move. But **which bucket a given filing falls into is
entirely our rule and the LGU has not approved it.** Today we treat renewals and amendments as
simple, new registrations as complex, and new registrations as highly technical only where the
declared trade is manufacturing, contracting or an amusement place *and* declared capital is at
least one million pesos. Malabon's Citizen's Charter should say which bucket a business permit
falls into; we have asked and not been given it. This is a live open question with the client's own
office.

That matters because this panel reports the city as breaching all three statutory limits. If our
classification is wrong, an office is being reported as missing a deadline it never had.

**Is it good?** It is the most consequential panel on the screen and it is well built. The bars are
drawn as a percentage of each tier's own legal limit with a line at 100%, so three different
deadlines can be compared on one axis. The screen also warns, correctly, that the internal deadline
stamped on every filing is a flat ten working days and is *not* the legal one — so on-time figures
elsewhere in the product are more forgiving than this panel.

**Can it be changed?** The 3/7/20 limits: **no, fixed by law.** The classification rule: a code
change and a redeploy, and it also needs the demo history reseeded, because the sample data holds a
second copy of the same rule.

**Verdict: WORKS, WITH A CAVEAT.** The caveat is the classification, and it is the single most
likely hard question of the day.

---

## 8. Average Processing Time by Department

2,700 finished reviews across seven offices in the last twelve months, averaging 2.7 days.

| Office | Reviews | Average |
|---|---|---|
| Fire Protection | 497 | 3.2 days |
| Building Official | 248 | 3.2 days |
| City Health | 494 | 3.1 days |
| Environment | 284 | 2.7 days |
| Zoning | 265 | 2.5 days |
| BPLO | 680 | 2.2 days |
| Market | 232 | 2.0 days |

**Is it right?** Yes. The counts sum to 2,700 exactly and the clock is honestly defined: it starts
when a review reaches an office and stops when that office finishes it. Reviews still open have no
finish time and are excluded — which the panel discloses, along with the warning that an office
finishing nothing therefore looks fast. That is the right disclosure to make.

The one thing to hold back on is the footer, which names Fire Protection as the bottleneck: *"the
slowest at 3.2 days, 0.5 days over the 2.7 day average, handling 18.4% of reviews"*. All seven
offices sit inside a 1.2-day spread. Calling one of them the bottleneck on a half-day margin is
reading more into the spread than it will carry. To the panel's credit it prints the share of
reviews right beside it, precisely so slowest-because-hardest can be told from
slowest-because-busiest before anyone is reassigned.

**Is it good?** Yes. A permit waits on six offices in turn, so knowing where to put people is a real
decision and this is the panel that informs it. The honest reading today is that no office is a
bottleneck.

**Can it be changed?** The period filter moves it: 3, 6, 12, 24 or 36 months. Nothing else.

**Verdict: WORKS, WITH A CAVEAT.**

---

## 9. Compliance card — Processing Rate Compliance to RA 11032 — 27.6%

**Is it right?** Yes. 194 filings of the 702 decided filings that record a tier were decided inside
the legal deadline for their own tier. Each filing is judged against its own limit, so a 20-day
highly-technical decision passes where a 20-day simple one fails. Both numbers match the tier panel
above exactly.

**Is it good?** Yes. This is the pass rate against the law, expressed in filings. The card carries a
note that it counts filings and so cannot be averaged with the two cards beside it — that is a
sharp piece of work and it prevents the most common misreading of a row of three percentages.

**Can it be changed?** The period filter moves the window. The underlying legal limits are fixed by
law. The tier classification carries the same open question as panel 7.

**Verdict: SOLID.**

---

## 10. Compliance card — Business Permit Compliance — 39.7% (239 of 602)

**Is it right?** Yes, verified. This is the same figure as the Compliance Rate headline at the top of
the screen, shown here with its counts so the headline can be traced. The definition is printed:
*businesses holding a valid permit of every type they have been issued, over businesses ever issued
a permit*.

**Is it good?** Yes, and the duplication is a feature. It means nobody has to trust the headline —
they can read the two numbers underneath it.

**Can it be changed?** No — it is always as of today, and the period filter deliberately does not
touch it. The per-type strictness would need a code change and a redeploy.

**Verdict: SOLID.**

---

## 11. Compliance card — Renewal Compliance — 61.5% (271 of 441)

**Is it right?** Yes. 271 of the 441 permits that fell due in the window had a renewal filed before
they expired. A draft does not count — it has to be submitted.

The best thing about this card is what it does when it cannot compute. If too few renewal filings
record which permit they replace, it says so in words — *"this is a gap in the register, not a
compliance finding"* — rather than printing 0%. That distinction, between "we measured zero" and
"we cannot measure", is the one most dashboards get wrong.

**Is it good?** Yes. It answers the question the Renewal Risk screen exists to act on, and the two
agree in direction.

**Can it be changed?** The period filter moves the window.

**Verdict: SOLID.**

---

## 12. Permits Approaching Expiry

| Window | Business | Sanitary | Fire | Occupancy | Environmental | Market | Zoning | Total |
|---|---|---|---|---|---|---|---|---|
| Next 30 days | 41 | 31 | 31 | 10 | 15 | 14 | 12 | **154** |
| Next 60 days | 72 | 55 | 55 | 19 | 29 | 24 | 25 | **279** |
| Next 90 days | 105 | 82 | 82 | 33 | 37 | 35 | 37 | **411** |
| Expired | 752 | 604 | 604 | 333 | 311 | 243 | 296 | **3,143** |

**Is it right?** The counts are right and the one genuinely confusing thing about the table is
disclosed twice: **the forward columns overlap.** A permit expiring in 20 days is counted in all
three of the 30, 60 and 90-day rows. The caption says so in plain words. Revoked and suspended
permits are excluded, correctly — neither is waiting to be renewed.

The caveat is the 3,143. It is the largest number on the screen by a factor of eight and it is a
backlog of lapses stretching back years in the demo history. The panel's own explanation describes
itself as *"the forward workload, and the list reminders are sent from"* — and that is
over-claiming. Reminders stop thirty days after a permit lapses, deliberately, so that thousands of
old lapses do not backfill into real inboxes. The large majority of that 3,143 gets nothing and is
meant to get nothing.

**Is it good?** The forward half is genuinely useful — 154 permits to chase in the next month, split
by which clearance is expiring, is an actionable work list. The expired row is a data-quality
picture, not a work list, and it would read better if the panel said which of the two it was.

**Can it be changed?** The 30/60/90 boundaries: a code change and a redeploy. Nothing on screen moves
this panel; it is always as of today.

**Verdict: WORKS, WITH A CAVEAT.**

---

## 13. Inspections

| Office | Scheduled | Completed | Passed | Conditional | Failed | Pass rate |
|---|---|---|---|---|---|---|
| Sanitary (City Health) | 492 | 486 | 425 | 40 | 21 | 87.4% |
| Fire Safety | 500 | 494 | 435 | 32 | 27 | 88.1% |
| Occupancy (Building Official) | 248 | 245 | 212 | 20 | 13 | 86.5% |
| Environmental | 270 | 266 | 223 | 28 | 15 | 83.8% |
| Market | 226 | 222 | 196 | 16 | 10 | 88.3% |
| Zoning | 258 | 253 | 221 | 16 | 16 | 87.4% |
| **Combined** | **1,994** | **1,966** | **1,712** | **152** | **102** | **87.1%** |

**Is it right?** Yes, and the denominator is the part worth defending. The pass rate divides by
*completed* inspections, never by scheduled ones — the footer says exactly that. An inspection that
has been booked but not carried out has no result, and counting it as a failure would punish an
office for its own backlog. The gap between scheduled and completed, 28 inspections, is the
backlog, and it is visible.

BPLO does not appear, correctly — the mayor's permit does not require an inspection. Which offices
appear at all is read from the register rather than typed into the software, so if a permit type
starts requiring an inspection the panel picks it up.

**Is it good?** Yes. Passed, conditional and failed will never add up to scheduled, and the panel
pre-empts that objection rather than waiting for it.

**Can it be changed?** The period filter moves the window. Which office maps to which kind of
inspection is currently inferred from the department, because the inspection records do not carry
their own type — that is a data fix rather than a setting.

**Verdict: SOLID.**

---

## 14. Top Five Barangays by Active Businesses

Longos 65 (13.8%) · Tañong 51 (10.9%) · Concepcion 40 (8.5%) · Potrero 40 (8.5%) · Tinajeros 36
(7.7%).

**Is it right?** Yes, and cleanly. The shares are of 470 — exactly the Active Businesses figure at
the top of the screen. Every one of the 470 active businesses has a barangay on record, so nothing
is quietly dropped. The footer says *"Five of 21 barangays"*, so nobody mistakes the five shares for
adding to 100%.

**Is it good?** Yes — it is where the city puts inspectors, and it feeds the location insight an
applicant sees when picking an address.

**Can it be changed?** Showing ten instead of five is a code change and a redeploy, and the heading
spells the number out as a word, so it is a two-part change.

**Verdict: SOLID.**

---

## 15. Top Five Business Categories

Restaurants and carinderia 27 · bakeshops 26 · plastic products 26 · water refilling 26 · auto
repair 25.

**Is it right?** The five counts are right. The total under them is not quite what it says.

The footer reads *"Shares are of the 472 active businesses with a category on record"* — but there
are only **470** active businesses. Two of them have registered two lines of business each, and the
panel counts trades rather than businesses. Its written definition says *"each business counts under
its main category only"*, and that is not what the arithmetic does.

The size of the error is two businesses in 470 — under half a per cent, and it moves no share by a
visible amount. But the sentence on the screen is false, and if a panelist compares this total with
the Top Five Barangays total on the same screen they will find a two-business discrepancy between
two panels that should share a denominator.

**Is it good?** Yes in principle — it says what kind of city this is in the register's own
classification. The panel labels its bars by rank and lists the full category names beneath,
because industry titles are too long to fit on an axis; that is a sensible piece of design.

**Can it be changed?** Showing ten instead of five is a code change and a redeploy. Correcting the
counted-twice total is a small code change.

**Verdict: WORKS, WITH A CAVEAT.**

---

## 16. Form of Organization

Sole Proprietorship 565 (79.6%) · Corporation 100 (14.1%) · Partnership 39 (5.5%) · Cooperative 6
(0.8%).

**Is it right?** Yes. 710 businesses have a legal form on file, one does not, and the total is 711 —
the whole register. The shares are of the 710 recorded, so they add to 100%, and the single
unrecorded business is stated beside them rather than swallowed. That is the correct handling: a
near-empty field must not be allowed to read as a real split.

Note this panel counts all registered businesses, not just active ones — so 711, not 470. The panel
is headed *As of today* and its definition says *registered businesses*, so it is consistent, but it
is a different denominator from the two ranked panels beside it.

**Is it good?** Yes, and more useful than it looks: legal form decides which documents a filing needs,
so an 80% sole-proprietorship register tells the counter what paperwork to expect.

**Can it be changed?** The four forms are the four the specification names. Adding a fifth is a code
change and a redeploy.

**Verdict: SOLID.**

---

## 17. Officer Activity — two cards

**Response time** — 5.1 hours average, middle wait 1.5 hours, over 423 replies, 3 conversations still
waiting.
**Requests fulfilled** — 41 of 52 raised (78.8%).

**Is it right?** Yes, both, and the response-time card is carefully built. Only the first unanswered
message starts the clock, so three follow-up messages from an anxious applicant count as one wait
rather than three. The average, the middle wait and the number still waiting are all shown together,
because an average on its own hides both the long waits and the questions nobody answered — and
here the average of 5.1 hours against a middle wait of 1.5 hours tells you immediately that a few
long waits are dragging the mean.

**The thing to say first:** this panel had a third card, "Meeting participation", and **it was
removed on 6 August**. That is a deliberate deviation from the paper, which lists three. The reason
is that BizTrack has no meetings feature — no scheduler, no calendar, no attendance record — so the
figure could only ever have been a number with nothing behind it. It was removed rather than left
as a plausible-looking fiction. Say that plainly if asked; it is a stronger answer than the card
would have been.

**Is it good?** Yes. "How long does an applicant wait to be spoken to" is a genuine service question
and 5.1 hours is a genuine answer. "41 of 52 requests fulfilled" says whether asking an applicant
for a document actually closes, which is the difference between filings stalling on paperwork and
stalling on review.

**Can it be changed?** The period filter moves both cards. Restoring a meetings figure would need the
feature built first, not a setting changed.

**Verdict: WORKS, WITH A CAVEAT.**

---

## 18. GIS Mapping

**Is it right?** Yes. 711 of 711 businesses plotted from recorded coordinates. Every business in the
register has a location, so nothing is missing, and the map's cap of 1,000 points has not been
reached — meaning nothing is being silently left off.

Businesses with a valid permit today are drawn as small hollow rings, those without as larger solid
red discs, with the lapsed ones drawn last so red is never hidden behind green. The caption states
the plotted count against the register total.

**Is it good?** Yes — it turns the barangay ranking into something an inspector can walk. Drawing
lapsed permits rather than hiding them is the right call, because a *cluster* of red is the pattern
worth seeing and a filtered map would never show it.

**Can it be changed?** The 1,000-point cap and the fallback map centre are code changes and a
redeploy. Nothing on screen moves it.

**Verdict: SOLID.**

---

# Renewal Risk Prediction — BPLO

2,589 permits scored across the next twelve months plus a sixty-day lapsed grace period.

---

## 19. The risk-band summary cards

**High risk 308 · Moderate risk 203 · Low risk 2,078.**

**Is it right?** Yes, and it balances exactly: 308 + 203 + 2,078 = 2,589, the number of permits
scored. Each band is a plain score cut — 50 or more is high, 25 to 49 moderate, under 25 low — and
the thresholds are printed on the screen next to the rule book.

The cards describe the whole scored population, not just the rows visible in the table. The barangay
filter changes them; the risk-level and action filters do not, because those only change what the
table shows. That is the right behaviour and it is worth knowing if someone filters and watches the
cards stay still.

**Is it good?** Yes. 308 businesses needing follow-up is a work queue with a size, and the action
column turns each one into an instruction.

**Can it be changed?** The window is on screen: next 30 / 60 / 90 days, 6 months or 12 months. The
50 and 25 thresholds are a code change and a redeploy.

**Verdict: SOLID.**

---

## 20. Reminders Sent — 1

**Is it right?** Arithmetically, yes. Exactly one automatic expiry notice has been recorded against
the permits in this window. We checked the ledger directly: it holds two entries in total, one
automatic and one an officer's manual follow-up sent today, and the card counts only the automatic
one.

**But the card reads "1" next to a card that reads "308 high risk", and that is a bad look.** The
honest explanation is this: the reminder ladder fires 30, 15, 7 and 1 day before a permit expires,
and it deliberately stops thirty days after a permit has lapsed. The demo register is mostly
historical — thousands of permits that expired long ago — and the suppression rule exists precisely
so those do not generate thousands of messages. The reminder engine has therefore had almost nothing
legitimate to send.

That is a correct design decision producing an unfortunate number. The card is not broken; it is
reporting that the nightly job has correctly declined to send mail about 2024.

**Is it good?** As a measure of whether the reminder system is working, no — it cannot distinguish
"nothing to send" from "not sending". A count of reminders *due* beside reminders *sent* would
answer the real question.

**Can it be changed?** The 30/15/7/1-day ladder and the thirty-day lapse cut-off are code changes and
a redeploy. Nothing on screen moves them.

**Verdict: WEAK — expect a question.** This is the card most likely to be read as "the feature does
not work".

---

## 21. Businesses Requiring Review — the table

Sorted worst first, 25 rows a page. The top row today is a Longos business scoring 83 out of 100
whose mayor's permit lapsed 27 days ago and whose renewal filing was rejected.

**Is it right?** Column by column:

- **Index / 100** — right, and the header states the denominator so nobody reads 83 as a
  percentage. It is a weighted sum of five rules and each row can be opened to show which rules
  fired and by how much. The example above scores 30 for expiry, 25 for renewal progress, 20 for
  past punctuality, and the rest from findings and fees — and each has a sentence of plain English
  beside it.
- **Level** — right, a direct read of the score against 50 and 25. No second judgement is applied.
- **Expires** — right, a signed count of days, negative for permits already lapsed, with a colour
  step at 7, 15 and 30 days.
- **Action** — right, and correctly mechanical: high means immediate follow-up, moderate means send
  a reminder, low means monitor. It is a restatement of the band, not a separate opinion, which is
  the honest way to do it.

**One label to be ready on.** The window control offers *"Next 12 months"*, but the table also
includes permits that expired in the previous sixty days — which is why the top row is 27 days past
expiry. That inclusion is right, because a permit that lapsed last month is exactly what needs
chasing. The word "Next" is what does not fit.

**Is it good?** This is the most actionable screen in the product. Every row is a named business, a
named barangay, a reason, and a button. The reminder button puts a notice in the owner's BizTrack
inbox immediately, and it is limited to one per business per day.

**Can it be changed?** Yes, extensively, on screen: window, barangay, risk level, recommended action,
25/50/100 rows, and paging. The scoring rules behind it need a code change and a redeploy.

**Verdict: WORKS, WITH A CAVEAT.**

---

## 22. The rule book — what drives the index

Five rules, printed on the screen, summing to 100:

| Rule | Weight | What it means |
|---|---|---|
| Time to expiry | 30 | Stepped at 30, 15, 7 and 1 day out. Full weight once lapsed. Nothing beyond 90 days. |
| Renewal progress | 25 | Full weight when a renewal is due within 30 days and none is filed, or one was rejected. |
| Past punctuality | 20 | The share of this business's earlier renewals filed after the old permit expired. A first cycle takes half weight. |
| Open compliance findings | 15 | Unticked requirements and failed or conditional inspections that would block issuance. |
| Unsettled fees | 10 | An assessed fee with no completed payment against it. |

**Is it right?** Yes. The weights sum to 100 exactly, each rule has a written rationale that is shown
to the user rather than buried, and the panel carries an explicit disclaimer: *"A higher score means
more warning signs — it is not a prediction, and it does not say how likely a renewal is to be
late."* Calling it a warning score rather than a prediction, on a screen titled Renewal Risk
Prediction, is the correct and more defensible claim.

**Is it good?** This is the strongest panel in the product. An officer challenged on why a business
was chased can point at the row, open it, and read the five reasons. That is a defensible decision
trail, which matters more here than statistical sophistication would.

**Can it be changed?** The weights are a code change and a redeploy. The one piece of good news is
that the whole rule set travels to the statistics engine rather than being duplicated there, so
changing them changes both halves of the system at once — a class of bug this project has been
bitten by before and has since designed out.

**Verdict: SOLID.**

---

## 23. The filters

Window (30 / 60 / 90 days, 6 or 12 months) · Barangay (all 21, built from the register) · Risk level
· Recommended action · Rows per page (25 / 50 / 100) · Previous and Next.

**Is it right?** Yes, and the behaviour is correctly separated: barangay narrows the population and
so changes the summary cards, while risk level and recommended action only change which rows are
shown. That is the right split, though nothing on screen explains it.

**Is it good?** Yes — this is the screen to demonstrate if anyone asks "can we change anything
without you". Five working controls and a page selector.

**Can it be changed?** This *is* the answer to "can it be changed". One thing to know: only the five
unfiltered windows are precomputed overnight. Apply a barangay or level filter and the answer is
computed on the spot instead. Both routes are checked against each other automatically, so they
cannot drift.

**Verdict: SOLID.**

---

# Business Growth Analysis — BPLO

One control: **Last 3 / 6 / 12 / 24 / 36 months**, default 12. Figures below are at 12 months —
6 August 2025 to 6 August 2026, compared with the twelve months before it.

---

## 24. Business Growth Rate — +47.1%

**Is it right?** Yes, to the digit. 275 businesses registered in the last twelve months against 187
in the twelve before: (275 − 187) ÷ 187 = 47.1%. We counted both independently against the register
and got 275 and 187 exactly.

The panel prints the two raw counts beside the percentage — *"+88 new vs 187 before"* — so the
percentage can be checked without trusting it, and it says "no prior period" rather than dividing
by zero when there is nothing to compare against.

**Is it good?** Yes. It is the headline the screen is named for and it is the one number on this
screen a mayor would quote.

**Can it be changed?** The comparison period is on screen: 3, 6, 12, 24 or 36 months, and the prior
period always matches the length chosen.

**Verdict: SOLID.**

---

## 25. Top Growing Barangays

| Barangay | This period | Prior period | Change | Growth |
|---|---|---|---|---|
| Catmon | 23 | 3 | +20 | +666.7% |
| Acacia | 16 | 1 | +15 | +1500% |
| Dampalit | 15 | 7 | +8 | +114.3% |
| Tañong | 27 | 20 | +7 | +35% |
| Tinajeros | 20 | 14 | +6 | +42.9% |
| Panghulo | 10 | 4 | +6 | +150% |

**Is it right?** The ranking is right and — importantly — it is ranked by **change**, which is what
the title promises. The subtitle says so: *"Change in new registrations against the previous
period"*. Note that this is a different sort order from the industry panel further down the same
screen, and that difference is deliberate and correct here.

The caveat is the growth column. **Acacia shows +1500% off a prior base of one business.** Catmon
shows +667% off three. Those percentages are arithmetically correct and practically meaningless —
one extra registration in a quiet barangay produces a headline growth rate. The panel does print the
raw counts beside them, which is what saves it, but the percentage is the thing the eye goes to.

**Is it good?** Yes, once read as counts. "Catmon went from 3 to 23" is a real planning fact worth
acting on. "Catmon grew 667%" is the same fact stated in a way that invites ridicule.

**Can it be changed?** The period is on screen. Showing six barangays rather than ten is a code
change and a redeploy — and it needs more chart colours, because the palette holds exactly six.

**Verdict: WORKS, WITH A CAVEAT.**

---

## 26. Business Status Summary

Active 470 (60.9%) · Expired 132 (17.1%) · Inactive 109 (14.1%) · Closed 61 (7.9%). Total 772.

**Is it right?** Yes, and it is a good consistency demonstration. The four counts total 772, which is
every business the register has ever held including those removed. And **Active is 470 — exactly the
Active Businesses card on the dashboard**, computed on a different screen by a different route.
Being able to show two screens agreeing is worth more than either number alone.

The four statuses are derived from permits rather than from an administrative flag, which is the
honest way round: Active means holding a permit valid today, Expired means every permit has lapsed,
Inactive means registered but never issued a permit, Closed means removed from the register.

**Is it good?** Yes. "109 businesses registered and never issued a permit" is an actionable finding
in itself — that is 14% of the register sitting in an unfinished state.

**Can it be changed?** No control. The status definitions are a code change and a redeploy.

**Verdict: SOLID.**

---

## 27. Business Renewal Performance

Of businesses reaching each renewal, the share that had renewed every earlier one with no gap:
**cycle 1 — 76.9%** (433 at risk, 100 lapsed) · **cycle 2 — 58.8%** (251 at risk, 59 lapsed) ·
**cycle 3 — 25.8%** (121 at risk, 68 lapsed).

**Is it right?** The method is right and properly applied. It follows only the mayor's permit chain,
counts a permit starting within one day of the previous one ending as continuous cover, allows a
thirty-day grace before calling a gap a lapse, and — this is the part that matters — sets aside
businesses still inside their current permit rather than counting them as failures. A business that
simply has not reached its next renewal yet is not a lapse, and treating it as one is the standard
way this kind of chart goes wrong.

The methodology sentence travels with the number on the screen, and it includes the sentence that
matters most: *"It describes what this group of businesses did. It is not a forecast of what any
business will do next."*

**The caveat is what it is describing.** 25.8% surviving to a third renewal is a severe finding, and
it is a finding about the sample history in the demonstration register, not about Malabon. Do not
let it be quoted as a measurement of the city.

**Is it good?** Yes, and it is the most statistically serious thing in the product. It also breaks
down by registration cohort, so the 2023 and 2024 intakes can be compared.

**Can it be changed?** The period is on screen. The thirty-day grace and the one-day continuity
tolerance are code changes and a redeploy.

**Verdict: WORKS, WITH A CAVEAT.**

---

## 28. Business Closure Trend

Registrations removed each month: Aug 1, Sep 1, Oct 1, Nov 2, Dec 0, Jan 1, Feb 1, Mar 2, Apr 3,
**May 14, Jun 8, Jul 22, Aug 0**.

**Is it right?** Every monthly figure is correct — we counted them against the register and they
match exactly, including the zero.

**But the last point on the line is the current month, and the current month is six days old.** The
chart draws August at zero, immediately after July's 22, with nothing marking it as unfinished. The
line falls off a cliff. A reader sees closures collapsing to nothing; what actually happened is that
the month has barely started.

This is the same fault the Processing Time screen has with its in-progress week, on a screen where
nobody had looked for it. It will recur every month, and it is worst in the first week — which is
when a monthly review meeting happens.

There is a second thing to be ready for: 44 of the 56 closures in the window fall in May, June and
July. That shape is written into the demonstration data, not observed.

**Is it good?** The idea is right and closures are the counterweight the growth rate needs. As drawn,
the most recent point — the one the eye lands on — is the one that cannot be read.

**Can it be changed?** No control. Marking the current month as incomplete, or ending the line at the
last finished month, is a code change and a redeploy.

**Verdict: WEAK — expect a question.**

---

## 29. Business Industry Growth Trend

| Industry | Businesses today | This period | Prior period | Change | Panel says |
|---|---|---|---|---|---|
| Sari-sari stores | 48 | 30 | 6 | +24 | growing |
| Restaurants and carinderia | 44 | 20 | 4 | +16 | growing |
| Water refilling | 41 | 16 | 7 | +9 | growing |
| **Printing services** | **38** | **11** | **13** | **−2** | **declining** |
| Bakeshops | 37 | 13 | 8 | +5 | growing |
| **Plastic products** | **36** | **10** | **11** | **−1** | **declining** |

**Is it right?** Every number in that table is correct. **The ranking is not what the title says.**

The six industries are chosen and ordered by how many businesses carry that trade *today* — 48, 44,
41, 38, 37, 36, straight down. Growth is only used to break ties. So the panel titled *Business
Industry Growth Trend* is a list of the city's **six largest** industries, and today **two of the six
listed are shrinking**. Printing services and plastic products are on a growth panel because they are
big, not because they grew.

To be fair to the panel: it labels each row honestly as growing or declining, and it prints both
periods' counts, so nothing on the screen is a lie. But a reader looking at a growth chart and
finding "declining" on it will conclude that either the chart or the label is broken, and they will
be half right.

Note also that the panel directly above it on the *same screen*, Top Growing Barangays, does rank by
growth. Two ranked panels, one screen, two different questions, one shared word.

**Is it good?** As a picture of what the city's biggest trades are doing, yes. As an answer to "what
is growing fastest", no — and that is what it is called.

**Can it be changed?** A code change and a redeploy, and a larger one than it sounds: both halves of
the statistics engine sort the same way and both would have to change together, plus the stored
comparison files that check them against each other would have to be regenerated. It is not a
one-line sort swap.

**Verdict: WEAK — expect a question.** This is the most likely question on this screen, and the
answer is "it ranks by size, we know, and here is what changing it costs."

---

# Permit Processing Time Monitoring — Super Admin

Window control: **13 / 26 / 52 / 104 weeks**, default 52. The seven office cards double as the
selector for the chart below.

**Before any panel on this screen:** today is Thursday. The week beginning 3 August is three days
old, and **every one of the seven offices is being judged on it.** That single fact drives the two
WRONG verdicts below.

---

## 30. Process Status Indicator

Seven cards, one per office, each reading *Within Normal Range*, *Outside Normal Range* or *Not
enough reviews to judge*, with a detail line giving the latest week and its average.

Today, at 52 weeks:

| Office | Card reads | Latest week average | Its normal range |
|---|---|---|---|
| BPLO | **Outside Normal Range** | 0.16 days | 1.66 – 2.80 |
| City Health | **Outside Normal Range** | 0.36 days | 1.40 – 4.11 |
| Fire Protection | **Outside Normal Range** | 0.25 days | 1.39 – 5.11 |
| Building Official | **Outside Normal Range** | 0.29 days | 1.63 – 5.07 |
| Environment | **Outside Normal Range** | 0.49 days | 0.91 – 4.77 |
| Zoning | Within Normal Range | 0.85 days | 0.56 – 4.75 |
| Market | Within Normal Range | 0.72 days | 0.40 – 3.75 |

**Is it right? No.** Two separate faults, both visible in that table.

**First, the week it reads is not over.** Every card is reporting the week beginning 3 August. Only
the reviews that have already finished are in it, which is a sample biased entirely towards the
quickest — which is why the averages read a fifth of a day against centres of two to three days.
This is not a demonstration artefact. In live use, every Monday and Tuesday morning this screen will
flag offices for the same reason, and those are the mornings an oversight reader is most likely to
open it.

**Second, "Outside Normal Range" does not say which direction, and the card is coloured red.** Every
one of the five red cards above is out on the **fast** side. There is not one genuine delay among
the red cards on this screen today. An office that finished its work quickly is being painted in
error red on the panel the specification names by title.

Three of those five would flip to *Within Normal Range* the moment the unfinished week is removed.

**Is it good?** As it stands it actively misleads. It is the first thing on the screen and it is the
panel a senior reader will look at and nothing else.

**Can it be changed?** No control. Ending the series at the last complete week, and separating fast
from slow in the wording and the colour, are code changes and a redeploy. The specification's own
words *Within Normal Range* and *Outside Normal Range* can and should be kept — this is a direction
and a colour problem, not a wording one.

**Verdict: WRONG.** Say this before you are asked. The fix is understood and small; being caught by
it is worse than the fault.

---

## 31. Department Processing Time Chart

A weekly average line with a shaded normal range, a centre line, and flagged weeks drawn as filled
dots. The key beneath reads *"Normal range … Slower than normal … Faster than normal"*, and the
caption says *"Lower is better. The clock starts when a review reaches the office and stops when
that office finishes it."*

**Is it right?** The statistics are right. The control limits, the centre line and the drift
detection all check out against the standard method, and the limits are fitted on the earliest 24
weeks so that a recent slowdown cannot widen the range meant to catch it.

**What is wrong is the labelling of the flagged weeks.** A week is flagged for either of two
reasons: it went outside the range, or the slowdown watch caught a gradual drift. The chart decides
which colour to use by asking whether the week was above the upper limit — but a drift week is
*inside* the limits by definition, so it always falls through to "faster". Today that produces six
weeks across two offices labelled *Faster than normal* that are in fact slower than usual:

| Office | Week | Average | Against normal | Chart says | Noted Delays says |
|---|---|---|---|---|---|
| BPLO | 30 Mar | 2.78 | +0.55 | **Faster** | slower |
| BPLO | 6 Apr | 2.45 | +0.22 | **Faster** | slower |
| BPLO | 13 Apr | 2.66 | +0.43 | **Faster** | slower |
| BPLO | 4 May | 2.67 | +0.45 | **Faster** | slower |
| City Health | 29 Jun | 3.96 | +1.21 | **Faster** | slower |
| City Health | 27 Jul | 2.81 | +0.06 | **Faster** | slower |

**The chart and the panel immediately beneath it give opposite readings of the same week, on the
same screen, at the same time.** The 29 June week at City Health is the fourth week of a documented
ramping slowdown, and the chart calls it faster than normal.

The same error reaches the spoken description read out to screen-reader users: it counts every
flagged week as having *"gone beyond that range"*, including the ones that never left it. City
Health reads as seven weeks beyond the range when five actually breached it. For a non-sighted
reader that sentence is the entire content of the chart, so the error is not cosmetic.

**Is it good?** The chart itself is good work — the range, the centre, the tooltip, and a full text
alternative for readers who cannot see it. The accessibility work on this screen is genuinely
careful. It is let down by one wrong test in the colouring.

**Can it be changed?** The window is on screen (13/26/52/104 weeks) and the office is selected by
clicking a card. The colouring fault is a code change and a redeploy. Two colours cannot carry three
facts — inside, slower, drifting — so a third state is needed.

**Verdict: WRONG.**

---

## 32. Noted Delays

A table of flagged weeks for the selected office: the week, the reason (*past the edge of the
range*, *caught by the slowdown watch*, or *past the edge, and drifting*), the deviation in days,
and the direction in words — *slower than usual* or *faster than usual*.

**Is it right?** Yes. **This is the panel that gets it right.** It reads the sign of the deviation
rather than guessing from the limits, so it correctly calls the 29 June week at City Health +1.21
days slower where the chart above it says faster. When the two disagree, this one is correct — that
is a useful thing to be able to say out loud rather than being asked.

It also distinguishes the two reasons a week is flagged in plain English, which is exactly the
distinction the chart above it loses.

Today, at 52 weeks, City Health shows eight flagged weeks and BPLO six; four offices show one each;
Zoning shows none. Every one of them includes the unfinished current week, so the last row of every
table is not yet real.

**Is it good?** Yes. It is a specific, dated, signed list of the weeks that went wrong, which is what
an oversight reader actually needs. It is also the noisiest panel on the screen — eight rows for one
office over a year is a lot of flags to read.

**Can it be changed?** The window is on screen; the office is selected by clicking a card.

**Verdict: SOLID.** With the note that it contradicts the chart directly above it, and it is the
chart that is wrong.

---

## 33. Gradual Slowdown Warnings

One row per office, reading *Slowing down*, *Holding steady* or *Speeding up*, with a bar and a
signed deviation. The summary line at the top today reads that City Health is drifting slower week
on week.

**Is it right?** The arithmetic is right, but there are two things to say before someone else does.

**The one office flagged as slowing is slowing because we made it slow.** The eight-week ramp at the
City Health Office is written into the demonstration data on purpose, so that the detector had
something real to catch. If a panelist points at it and asks what is happening at City Health, the
honest answer is that it is synthetic and it is there to prove the detector works. It does work — it
caught the ramp from the fourth week — and that is the actual finding worth claiming.

**Second, six of the seven offices read "Speeding up" today**, and they read that way partly because
the trailing average is being pulled down by the same unfinished week that breaks the two panels
above. The direction is being computed off a week that is three days old.

**Is it good?** The concept is the right one — a gradual drift that never crosses a limit is exactly
what a simple threshold misses, and this is the panel that catches it. What it currently
demonstrates is our own test case.

One thing the specification asked for and this screen does not do: it does not check for a run of
several consecutive weeks drifting one way. That is a genuine gap, and it is not a small addition —
weeks with too few reviews are dropped from the series entirely, so "consecutive" would first have
to be defined.

**Can it be changed?** The window is on screen. The sensitivity of the drift detector is a code
change and a redeploy, and it is one of the few settings that would have to be changed in two places
at once.

**Verdict: WEAK — expect a question.**

---

# Business Location Insights — applicant-facing

Shown to a business owner while they are placing their pin on the map. Four rows. Always computed
on the spot rather than from an overnight snapshot — a snapshot cannot be prepared for a point
somebody dropped ten seconds ago.

Figures below are for a real test pin with the applicant's trade set to dairy manufacturing.

---

## 34. Nearby Similar Businesses — 0

**Is it right? Yes, and this is the important one to get straight.** There are genuinely no dairy
manufacturers within 500 metres of that pin. The zero is correct and must not be "fixed".

This row was queried by the client, and the reason is worth understanding. "Similar" means sharing
the applicant's industry group — the narrow classification, so a coffee shop matches a bar but not a
restaurant. The row beneath it reports the most common *broad* category nearby. Two adjacent rows,
two different levels of the same national classification, and nothing on screen says so. A reader
sees "0 similar" above "most common: Construction, 4 of 34" and does the arithmetic a reasonable
person would do.

The narrow definition is the right one and should not be widened — widening it would make a bakeshop
"similar" to a dairy plant, which is the confusion the classification was rewritten to prevent. The
fix is to the wording, not the arithmetic.

**Is it good?** The figure is genuinely useful to an applicant choosing a site: "nobody in your trade
is within 500 metres" is worth knowing. It is the presentation beside it that undermines it.

**Can it be changed?** No. There is no radius control — the API takes a point and a trade and nothing
else. The 500 metres is a code change and a redeploy, and **this is the single most likely
"we cannot do that live" of the day**, because it is the most concrete number in the product, it is
drawn as a ring on a map in front of the reader, and asking for 250 metres or a kilometre is a
perfectly reasonable planning question. The one consolation is that the change is genuinely one line
— the ring on the map and every label already read the radius from the server rather than repeating
it.

**Verdict: WORKS, WITH A CAVEAT.**

---

## 35. Business Concentration — High (34)

**Is it right?** Yes. 34 registered businesses within 500 metres, which lands in the High band. The
band boundaries are shown to the reader — Low 0–5, Medium 6–10, High 11 and above — and the screen
derives that sentence from the server rather than repeating the numbers, so the label can never
disagree with the band.

**Is it good?** Yes. It is the clearest of the four rows, it answers a question an applicant actually
has, and it does not pretend to more precision than a count deserves.

**Can it be changed?** The 500-metre radius and the 6 and 11 boundaries are code changes and a
redeploy — though clean ones, since nothing in the browser holds a second copy.

**Verdict: SOLID.**

---

## 36. Most Common Line of Business — Construction (4 of 34)

**Is it right?** Yes, and it is better than it was. This row previously answered "Manufacturing",
which was a catch-all bucket covering sixteen unrelated industries — and, worse, one that excluded
the applicant's own dairy trade rather than containing it. The classification has since been
rewritten into named trades, and the same pin now answers "Construction", which is a real thing a
reader can picture.

The caveat is that **4 of 34 is a thin mode.** The most common trade near that pin is common to
twelve per cent of it. The row does print "4 of 34" beside the answer, which is what rescues it —
but "most common" invites a reader to think of a dominant character the neighbourhood does not have.

**Is it good?** Reasonably. "The nearest thing to a dominant trade here is construction, and only
just" is a fair planning signal. It would be better if it said when there is no real mode.

**Can it be changed?** No control. The category names are ours and are a code change; the underlying
national classification numbers are not ours to change.

**Verdict: WORKS, WITH A CAVEAT.**

---

## 37. Average Distance to Similar Businesses

**Is it right?** Yes. For the test pin it correctly shows nothing rather than zero, because there are
no similar businesses to average — the row reads *"— none in range"*. That distinction between "the
average is zero" and "there is nothing to average" is the same one the Renewal Compliance card gets
right, and it is the one most products get wrong.

Where it does have a value it is a straight-line distance, and the row says *"straight-line"* rather
than implying a walking or driving distance. Straight-line is the honest claim: we have coordinates,
not streets.

**Is it good?** Modestly. It is the least useful of the four rows — knowing that your nearest
competitors average 320 metres away is interesting rather than decisive — but it costs the reader
nothing and it is honestly stated.

**Can it be changed?** No control; it follows the same 500-metre radius as the first row.

**Verdict: SOLID.**

---

# Notifications

## 38. The notifications list

**Is it right?** Yes. 10,561 notices have been raised across 65 recipients, of which 10,553 are
unread. Nine kinds are supported — status changes, decisions, permit issuance, messages, officer
requests, fee adjustments and permit expiry — each with its own icon, colour and wording, and each
raised by a real event in the workflow rather than on a schedule.

**Two things to say before you are asked.**

**Email and text messages are simulated, not sent.** Both write to a log file rather than to a mail
server or a telephone network. In-app notifications are entirely real — an applicant signing in sees
them. This is a deliberate demonstration setting for email, which can be switched on by
configuration without new software. Text messaging is different: there is no messaging provider
connected at all, so real SMS is a piece of work to build, not a setting to flip.

**Only two of the 10,561 are expiry reminders**, for the reason given under the Reminders Sent card
above: the nightly scan correctly declines to send messages about permits that lapsed years ago in
the sample history.

**Is it good?** Yes, in structure. Every notification carries a link back to the filing it concerns
and marks itself read when followed, there is a "Mark all as read" control, and the wording is plain
and specific — *"Application BIZ-2026-00601 was rejected"*, not "you have an update". The
notification for an approval even tells the applicant where to find the permit.

The weakness is volume: 10,553 unread across 65 people is roughly 160 unread notices each, and most
of them are routine status changes. Nobody reads an inbox in that state. That is a consequence of
seeding thousands of historical filings, but it points at a real design question — whether every
intermediate status change deserves its own notice.

**Can it be changed?** The reminder ladder (30, 15, 7 and 1 day) and every piece of message wording
are code changes and a redeploy — there is no notifications settings screen and no separate wording
file. Turning real email on is a configuration change with no new software. Real text messaging is
new work.

**Verdict: WORKS, WITH A CAVEAT.**

---

# Closing note

**38 panels: 16 SOLID · 13 WORKS, WITH A CAVEAT · 7 WEAK · 2 WRONG.**

The two WRONG verdicts are both on the Permit Processing Time screen and both come from the same
root: a week that has not finished being judged as though it had, and a colour test that asks the
wrong question about a flagged week. Neither is a fault in the statistics — the control limits and
the drift detection are correct and match the standard method. Both faults are in the presentation
wrapped around them, and both are understood.

The seven WEAK verdicts divide into three kinds. Two are about unfinished periods being drawn as
finished — the current month on the Closure Trend, and the current month behind the This Month and
Application Volume figures. Two are about a panel ranking by one thing under a title promising
another. Two are about demonstration data producing a number that reads badly — one reminder sent,
eleven decisions this month. One is about a slowdown we planted ourselves.

What holds up under examination is worth stating too. The arithmetic checks out almost everywhere it
was tested: the growth rate to the digit, the risk bands to the unit, the tier panel and the
compliance card agreeing at 194 of 702, three separate screens independently arriving at 470 active
businesses. The definitions written behind each panel are unusually candid — several of them
volunteer their own limitations before a reader can find them. And the product distinguishes "we
measured zero" from "we cannot measure", in at least three places, which is the mark of numbers that
were built to be defended rather than to look good.

---

*Compiled read-only on 7 August 2026. Figures read from the live system on 6 August 2026 and
re-checked against the register. No source file, database record or setting was changed.*
