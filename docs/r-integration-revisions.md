# R Integration — Professor's Revisions

Consolidated list of every change requested during the midterm defense, arranged in the order
of the **original** document (`R INTEGRATION DRAFTS.pdf`), not the order of the submitted copy.

**Sources merged here**
1. `R INTEGRATION DRAFTS.pdf` — the original, canonical arrangement (16 pp., 6 features).
2. `Contents/` — 19 photos of the submitted copy carrying the professor's handwriting (pp. 1–19).
3. The meeting transcript.
4. A classmate's bulleted notes (incomplete — used to corroborate, never as sole source).

Each item is tagged:
- **[MARK]** written on the paper · **[SAID]** spoken in the meeting · **[NOTE]** classmate's notes
- **PAPER** = wording/structure change only · **SYSTEM** = the UI or computation must change too

---

## 0. Cross-cutting requirements

These were stated as general rules and apply to every feature. The professor said she will check
the next submission specifically for these, and wants **a written explanation for anything left
unchanged** — silence will be read as the note being ignored.

| # | Requirement | Source |
|---|---|---|
| 0.1 | **Every data element must be justified.** For each item on a dashboard or report, state *why it is there, who uses it, how they use it,* and *whether it is included in an official report.* "There should be a basic idea why it's there. It is the most usual question that is asked." | [SAID] |
| 0.2 | **Indicate whose side each feature is on** — admin/BPLO, business owner, or city resident/stakeholder. Put it in the paper, per feature. | [SAID] [NOTE] [MARK] |
| 0.3 | **Titles must match the data underneath them.** Raised on Top Barangays, Compliance Monitoring, Permits Approaching Expiry, and Department Processing Time Chart. "Tingnan mo kung may kinalaman yung title mo doon sa subtitle at sa data na binibigay mo." | [SAID] [NOTE] |
| 0.4 | **Do not lump unrelated indicators together.** Said three separate times ("pinagbigit-bigit niyo na naman"). If two numbers cannot be meaningfully added or averaged, they do not belong in one card. | [SAID] |
| 0.5 | **There is too much data.** "Parang masyadong maraming data." Cut anything that fails 0.1. | [SAID] |
| 0.6 | **The dashboard must be fast to read and fast to search.** Driving example: an owner phones to follow up a renewal and has lost the receipt — staff must be able to find it **by owner name**, not only by reference number. "Kung wala yung resibo, may alternate way para gawin niyo. So why not give it?" | [SAID] |
| 0.7 | **Where is the forecasting?** "Wala akong nakikita na forecasting. Puro dashboard lang 'to." Feature 1 is entirely descriptive; the predictive work sits in Features 2 and 5 and is not visible as prediction. Make the predictive element explicit. | [SAID] |

---

## 1. Analytics Dashboard — *side: Admin / BPLO*

Not annotated with a side on the paper; derive it from the text and state it explicitly (see 0.2).

### 1.1 Application Volume
No objection raised. Leave as is.

### 1.2 Decision Outcomes
No objection raised. Leave as is.

### 1.3 Average Processing Time by RA 11032 Tier — **PAPER + SYSTEM**

- **[MARK] p.4** — On the title `Average Processing Time by RA 11032 Tier`: parentheses were
  inserted around **(RA 11032)**, the word **"by"** was struck through, and a word was written
  beneath, reading as **"for"**.
  → Corrected title: **"Average Processing Time for (RA 11032) Tier"**.
  *Ambiguity, flagged honestly:* the inserted word overlaps the printed word "Tier", so it is
  possible she meant to delete "Tier" as well, giving "Average Processing Time (RA 11032)".
  The classmate's note only records "ilagay sa close in parenthesis yung RA 11032", which
  confirms the parentheses but not the rest. **Confirm the exact wording with her.**
- **[SAID]** — "Meron kayong Simple, Complex and everything. Saan 'yun nakasama sa 43%? Ang
  computation ninyo, kung Simple plus ganyan plus ganyan, overall, divided by ganoon?"
  → The paper must show **how the per-tier averages relate to the headline compliance
  percentage**. Right now the three tier averages and the single 43% figure sit next to each
  other with no stated relationship.

### 1.4 Average Time-in-Stage by Department — **PAPER**

- **[MARK] p.4** — The whole row label is **circled, with a question mark beside it**.
  → "Time-in-Stage" is internal jargon. Rename to something an LGU reader understands
  (e.g. *Average Time per Department*), and define what the clock starts and stops on.

### 1.5 Compliance Monitoring — **PAPER + SYSTEM** *(largest change in Feature 1)*

- **[MARK] p.5** — "**Rate**" appended to the row title; "**thru RA …**" written beneath;
  "**? change title**" written in the margin.
- **[SAID]** — "Kasi pangit 'yung *RA 11032 Processing*. Pangit 'yun." Then, rejecting
  "legal processing": "Hindi legal 'yan. Processing **rate compliance**. Pwede."
  → **Rename `RA 11032 Processing` → `Processing Rate Compliance to RA 11032`.**
  → **Rename the section `Compliance Monitoring` → `Compliance Monitoring Rate through RA 11032`.**
- **[SAID] [NOTE]** — **Split the three indicators.** "Ano naman 'yung Business Permit
  Compliance, at saka 'yung Renewal Compliance? Pag in-add ko together, divided by 2, hindi siya
  papasok kay Processing ng RA 11032." … "Iwalay kasi. You are talking with the same data that
  can be combined together — or what?"
  → The three indicators measure different populations and must not share one card or one
  heading. Present them separately, each with its own denominator stated.
- **[SAID]** — 43% is a *failing* figure and should be presented as such: "Ibig sabihin, hindi ka
  compliant." Do not present it neutrally.

### 1.6 Permits Approaching Expiry — **PAPER + SYSTEM**

- **[MARK] p.5** — pen stroke against the row.
- **[SAID]** — "Parang mali 'yung title. Pinapatay kayo talaga ng title."
- **[SAID] [NOTE]** — **Replace the 30/60/90-day windows with three states: Pending, Near
  (approaching), and Overdue/Expired.** "Kasi ano ba ang kaibahan ng 30, 60, at saka 90?"
  The three windows are not decision-relevant; the three states are.
- **[SAID]** — **It must be per clearance/permit type, not "business permit".** "Hindi Business
  Permit ang nakasulat, kasi ang output n'un *would be* the business permit. Ang kailangan ko ay
  Sanitary. Inspection. Application." The six office clearances are the prerequisites that expire
  and block renewal; the business permit is the result.
- **[SAID]** — Suggested running order for this block: *business renewal → renewal permit →
  monitoring*.

### 1.7 Top Barangays — **PAPER**

- **[SAID]** — Used as the example for rule 0.3: "Pag sinabi mong Top Barangay, Analytics
  Dashboard — mali pa rin. Tingnan mo kung may kinalaman 'yung title mo doon sa subtitle at sa
  data." Give the section a title that describes what is actually shown.

### 1.8 Top Lines of Business → **Top 5 Lines of Business** — **PAPER + SYSTEM**

- **[MARK] p.6** — pen stroke against the row.
- **[SAID] [NOTE]** — "Eh 'Top Lines' eh. Dapat **Top 5**." The shares shown (6.8 + 5.7 + 5.7 +
  5.5 + 5.3) do not sum to 100, which is only defensible if the title says *top 5*.
- **[SAID]** — **Use a pie chart, not a table.** "Ayan 'yung sinasabi kong pwedeng maging pie
  chart. Hindi puro table."

### 1.9 Form of Organization — **accepted, no change**

- **[SAID]** — "I'm sold. Partnership 556. Ayan, medyo malinaw 'yan. Okay?"
  This is the only item explicitly approved. Keep it as the reference for how the others should read.

### 1.10 Inspections — **PAPER + SYSTEM**

- **[MARK] p.6** — a question mark beside the row.
- **[SAID]** — She walked the numbers (scheduled 419, completed 485, passed, failed, conditional,
  rating 3.9%) and asked: "Do you think that is favorable doon sa tao — kung sino ang nagre-record
  ng sanitary, ng mga inspections?"
  → Present inspections from the point of view of **the person who has to act on them**, and tie
  it to the expiry scenario she raised: "Lalo na pag malapit nang mag-expire, hindi pa natin
  natatapos." Scheduled-vs-completed backlog against upcoming expiry is the useful cut.
- Denominator: pass rate is over *completed*, not *scheduled* — keep this, but state it on screen,
  because the figures otherwise look inconsistent.

### 1.11 Officer Activity — **PAPER + SYSTEM**

- **[SAID] [NOTE]** — **Split it, and rename it.** "Ano 'yung Officer Activity? Response,
  request, meetings? … If I were you, hindi *Officer Activity* ang ilalagay ko dito."
  → Three unrelated measures are being averaged into one label. Break them out and name each for
  what it answers: *how fast staff respond*, *how many requests*, *how many meetings*.
- **[SAID]** — "Sino ba si officer — si staff?" → Use the role name the system actually uses.
- **[SAID]** — "Ang ini-expect ko diyan, pag in-add mo, 100%." → Any percentage breakdown shown
  here must reconcile to 100%, or must not be shown as a breakdown.
- **[SAID]** — She checked that it is derivable from real data ("Paano mo malalaman? Sa system mo,
  meron nang malalaman 'yan?") and accepted the answer that timings are recorded. Keep that
  provenance sentence in the paper.

---

## 2. Renewal Risk Prediction — *side: Admin / BPLO*

### 2.1 Risk Score — **PAPER + SYSTEM**

- **[SAID]** — "Ano 'yung risk score? … Parang magulo 'yung risk for me."
  She then restated what she expected: "Pag nag-renew ako, bibigyan mo ako ng risk — mga 3% ang
  risk nito. Parang ganito. Hindi po siya open-ended?"
  → Express risk as a **probability attached to an individual renewal**, not an opaque 0–100
  composite. "Bawat renewal, meron siyang risk."
- The weighted formula (`w_expiry + w_progress + w_punctuality + w_findings + w_fees`, p.8–9)
  survives, but the paper must explain each weight in plain language and show the output as a
  probability.

### 2.2 Purpose must be stated — **PAPER**

- **[SAID]** — "**Bakit kailangan ng renewal risk?** … Dapat may reason bakit kayo maglalagay."
  This is rule 0.1 applied to the whole feature. Answer it in the opening paragraph.

### 2.3 Recommended Actions — **PAPER + SYSTEM**

- **[SAID]** — "Recommended action. … Kaya ako sinusunod, ng risk. So dapat meron ka diyan."
  → Keep and strengthen. Every risk row must carry the action it implies.

### 2.4 Businesses at Risk table — **SYSTEM**

- **[SAID] [NOTE]** — "Pag Business Success at Risk, meron pula, meron green. Dito mga pula 'to,
  every one of these — pag i-click mo, o doon ka lang mag-hover, ano-ano 'yung ano. Kaysa ganyan."
  → **Colour-code by risk (red / green)** and make each row **expandable on click or hover** to
  reveal why it is at risk. The flat table she saw was rejected.

---

## 3. Notifications for Business Owners — *side: Business Owners* ✅ *(side confirmed by her)*

### 3.1 Group notifications per application — **SYSTEM**

- **[SAID]** — "Lalabas po dito. Sa lahat? Isa-isa pa 'yan eh. … Pag lumabas natin, uurong lahat
  'to. Paano kung apat 'yung may application?" — answered "for each po", accepted.
  → The flat chronological stream does not scale. **Group by application**, so four live
  applications do not push each other off the screen.

### 3.2 "Application Updates" heading is too vague — **SYSTEM**

- **[SAID]** — "Application updates — ibig sabihin, numbers. … Pag kinlik mo 'yan, makikita ko
  dito ano-ano 'yung mga 'yun."
  → Show a **count** on the heading and make it **expand to the detail** on click.

### 3.3 State the side — **PAPER**

- **[SAID]** — "Iba po pala — for business owners." This exchange is the origin of rule 0.2.
  Feature 3 is the one feature whose side she confirmed out loud; the other five must be labelled
  the same way.

### 3.4 Demo readiness — **SYSTEM**

- **[SAID]** — She asked them to show it logged in as an actual business owner and it could not be
  demonstrated cleanly in the room ("Hindi nga kayo nag-login as user?").
  → Have a working business-owner session ready before the next defense.

---

## 4. Business Growth Analysis — *side: **(Admin, City)*** — **[MARK] p.12**

- **[MARK] p.12** — handwritten beside the heading: **"( Admin, City )"**.
- **[SAID]** — "Ito ay para kanino naman? Kay user o admin? … Ito ay admin." … "ng buong
  munisipyo ninyo?" → admin, and city-wide in scope. Put both in the paper.
- **[SAID]** — "Pakita ninyo sa akin na nagbabago-bago 'yan." → At the next defense, be able to
  **demonstrate the figures moving**, not just show a static screen.
- No objection was raised to the six analytical reports themselves (Growth Rate, Top Growing
  Barangays, Business Status Summary, Business Renewal Performance, Business Closure Trend,
  Business Industry Growth Trend). Rules 0.1 and 0.3 still apply to each.

---

## 5. Business Location Insights — *side: **(City residents, Stakeholders)*** — **[MARK] p.15**

- **[MARK] p.15** — handwritten beside the heading: **"( City residents, Stakeholders )"**.
  This is the answer to "Saan 'to? … 'Yung mga residents, city residents — tama? Or stakeholders?"
  It is also the counterweight to her complaint in Feature 6 that everything is admin-only.

### 5.1 The insight must be reachable from the user's actual goal — **SYSTEM**

- **[SAID]** — "So kung ako ang resident, bakit ko siya gagamitin? … Gusto kong magtayo ng coffee
  shop — ano ang gagawin ko ngayon? … Paano ko nga makikita?"
  → She never got a satisfying answer. The paper must trace the path end-to-end from the resident's
  question ("is this area saturated for a coffee shop?") to the number on screen.

### 5.2 It did not work in the demo — **SYSTEM** — *already addressed*

- **[SAID]** — "Sabi mo mag-choose ako ng line of business, di ba? … Later step po kasi." Then:
  "**Wala. Hindi siya gumagana.** A matter of yes or no — hindi siya gumagana."
  → Root cause: line of business was chosen at a *later* wizard step than the map, so the insight
  had nothing to compute against.
  → **Fixed** on branch `fix/location-line-of-business` (HEAD commit *"let the applicant name their
  line of business on the location step"*). Re-verify live before the next defense — she will
  retry this exact path.

### 5.3 Table content
No objection raised to Nearby Similar Businesses, Business Concentration, Most Common Business
Type, or Average Distance. Rule 0.1 still applies — say who reads each and why.

---

## 6. Permit Processing Time Monitoring — *side: **(Admin)*** — **[MARK] p.17**

- **[MARK] p.17** — handwritten beside the heading: **"( Admin )"**.
- **[SAID]** — "Sino naman ang gumagamit nito? Admin na naman. **Wala kayong nilagay para sa
  user**, kaya user, kaya sa resident."
  → Recorded here as a criticism of the *whole submission's balance*, not just this feature:
  five of six features serve admin. Feature 5 is the only resident-facing one. Address this
  explicitly in the paper.

### 6.1 Department Processing Time Chart — **PAPER + SYSTEM**

- **[SAID] [NOTE]** — "Anong 8.5? … Ito bang *processing* ay *processed*? O processing time is
  from the application until the process?"
  → **Define the metric precisely**: what event starts the clock, what event stops it.
- **[SAID]** — "So dapat bumaba ba? Hindi dapat tumataas — kasi processing time 'yan."
  → **State the desired direction on the chart** (lower is better). Without it the chart cannot
  be read as good or bad.

### 6.2 "Inside / Outside Normal Range" — **PAPER + SYSTEM**

- **[MARK] p.17** — a struck-through mark with an arrow pointing directly at the **"Inside"** status
  pill.
- **[SAID]** — "Ito, ano 'yung *inside*? … Ano 'yung *flag*?"
  → The control-chart vocabulary is opaque. Replace with plain language describing the situation,
  not the statistical state.

### 6.3 Flagged Weeks → **use government terminology** — **PAPER + SYSTEM**

- **[MARK] p.17** — written beside the row: **"— Government Terms"**.
- **[NOTE]** — "I-check sa office kung tama ba 'yung term ng *flagged weeks*."
  → Confirm the term the office actually uses for a period that breached its service standard,
  and adopt it.

### 6.4 Reporting period may not be weeks — **SYSTEM**

- **[SAID]** — "Baka naman hindi *weeks* ang tao — kasi meron pong *operational terms*."
  → Check how the office actually periodises its reports before defending the weekly bucket.

### 6.5 Gradual Slowdown Detector — **PAPER**

- **[SAID]** — "Ano 'to, *gradual slow-mo*? Ano na 'yan?" → The name and purpose did not land.
  Rename and re-explain, or drop it under rule 0.5.

### 6.6 Justify the entire feature — **PAPER**

- **[SAID]** — This feature is where rule 0.1 was stated most forcefully: "Kailangan malaman kung
  may need diyan. Otherwise, nilalagyan na natin lahat ng data kasi *need* to be. … Is it included
  in the report? It is the most usual question that is being asked."
  → Answer directly: does departmental processing-time monitoring appear in any report the admin
  actually submits? If not, justify it another way or cut it.

---

## Summary of counts

| Feature | Side (per her marking) | Items | Rename | Split | Chart/UI | Paper-only |
|---|---|---|---|---|---|---|
| 1. Analytics Dashboard | Admin / BPLO *(to state)* | 9 | 3 | 2 | 3 | 3 |
| 2. Renewal Risk Prediction | Admin / BPLO *(to state)* | 4 | – | – | 2 | 1 |
| 3. Notifications | **Business Owners** ✅ | 4 | 1 | – | 2 | 1 |
| 4. Business Growth Analysis | **(Admin, City)** ✅ | 2 | – | – | – | 2 |
| 5. Business Location Insights | **(City residents, Stakeholders)** ✅ | 3 | – | – | 1 *(done)* | 1 |
| 6. Permit Processing Time | **(Admin)** ✅ | 6 | 3 | – | 2 | 2 |
| 0. Cross-cutting | all | 7 | – | – | – | 7 |

Only **Form of Organization (1.9)** was explicitly approved.

## Open questions to confirm with her

1. Exact corrected wording of the Feature 1.3 title — the inserted word overlaps "Tier" (see 1.3).
2. The office's own term for a period that breached its service standard (6.3), and its reporting
   period (6.4).
3. Whether departmental processing-time monitoring appears in any report the admin submits (6.6).
