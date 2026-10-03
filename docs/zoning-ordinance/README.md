# Malabon Zoning Ordinance — City Ordinance No. 24-2018

What this folder holds, where it came from, and — more important — what it is
not good enough to be used for.

## Provenance

Extracted from `Malabon Zoning Ordinance.pdf`, 110 pages, 6,662,037 bytes. The
PDF carries a real text layer, so nothing here is OCR guesswork about glyphs;
but it is a *layout* extraction, and the ordinance is laid out in three-column
tables that the text layer flattens. Where that flattening loses information,
it is recorded as an anomaly rather than papered over.

The source PDF is not committed. It is the city's document, not ours.

| File | Article | Contents |
| --- | --- | --- |
| **`rules.json` / `rules.md`** | **all, pp. 1–110** | **Every normative statement — 364 of them — with its verbatim text, article, section and printed page, what data it needs, and its status in BizTrack (implemented, shown, not applicable, question). The file the zoning check reads its wording from. `rules.md` is generated from it by `scripts/zoning-rules.py`.** |
| `zone-boundaries.json` / `-summary.md` | IV §5 | 99 (zone, barangay) pairs: which base zones and overlays fall in which barangay, with the location prose |
| `zone-uses.json` / `-summary.md` | V §2.1–2.20 | 695 allowed uses across all 20 base zones. The special uses, overlays and incentives (§3–5) are in `rules.json`, not here |
| `definitions-and-standards.md` | III, VI, VII, VIII | 89 defined terms; performance standards with thresholds; general regulations; variance/exception procedure |
| `annex-a-glossary.json` / `-md` | Annex A | 89 further definitions, 39 of them declarable business types |

## The load-bearing conclusion

**The ordinance cannot be turned into a machine that says "allowed" or
"refused" — but it can be applied rule by rule, and BizTrack now does that.**
`App\Support\Zoning\ZoningCheck` evaluates every rule of City Ordinance No.
24-2018 that a business filing can trip and reports each one as **met**, **not
met**, or **CPDO review**, with its article, section and printed page. The
applicant reads the list on Location & Zoning and again on Review, as an early
warning; the zoning officer reads the same list on CPDD's sheet as the cited
checklist they decide against. Nothing in it refuses a filing: Art. II §3(1)
makes every land use "a use by right" subject to review, and the review is the
Zoning Administrator's (Art. IX §14), with variances, exceptions and appeals at
the Local Zoning Board of Appeals (Art. VIII, Art. IX §16).

Why still no verdict — the four reasons this file used to give, as the full
reading leaves them:

1. **§2.16 Fishpond Zone enumerates no uses at all.** Still true. A business in
   Dampalit's fishponds is told the base zone is silent; the Eco-Tourism
   overlay's own uses and limits (30% of the lot, one storey) are checked.
   Question C17.
2. **The inheritance chains are mostly NOT broken.** This file said they were.
   Read clause by clause, C-2 takes in "All uses allowed in C1-Zone", the CBD
   takes in C-1, C-2, C-3 and General Commercial, and so on, and the check now
   follows them exactly as written (`Ordinance::INHERITS`). C-2's "R-1 and R-2
   Zones" is harmless: C-2 already reaches both R-2 zones through C-1. The one
   real gap is that Maximum R-3 and C-1 skip Basic R-3; a trade found only
   there goes to CPDO. Question C18.
3. **The same activity carries different conditions in different zones.**
   True, and handled rather than avoided: a condition is applied in the zones
   whose list carries it, sent to CPDO where a zone reaches the use both with
   and without it (the CBD takes in C-1's conditioned auto repair AND General
   Commercial's flat one), and where the ordinance contradicts itself outright
   — filling stations (§2.7 vs §3.C), home occupations (§2.1 vs Annex A 42),
   "one lot deep" (§6 vs Annex A 67), non-conforming uses (§11 vs §12.9) —
   both rules are evaluated and a finding names the conflict and its question
   (C13–C16). Nothing is resolved by picking a side. Where General Commercial
   lists a use with none of the conditions C-1 and C-2 attach, a lot that may
   be in General Commercial is not held to them — and is told so, with the
   question (C28), rather than left to assume the condition does not exist.
4. **Conditionality is buried in "provided that" prose.** It was, until every
   proviso was separated by hand into `rules.json`. The check now applies each
   one: the five people and 20% of a home occupation, the 200 m² warehouse cap,
   200 m from a lotto outlet to a school, 1 km between filling stations, the
   3 m (or 7.5 m) waterway easement, and the rest.

What makes a flat verdict impossible is the one thing BizTrack cannot know:
**which of a barangay's zones the lot is in.** The sheets are rasters, and a
barangay holds several zones. So a condition that binds in only some of them is
reported as CPDO review, naming the zone ("applies if your lot is in
Commercial-1"), never as a flat "not met" — until the zoning officer records the
lot's zone, which resolves every such finding at once.

The zones the check decides on are Art. IV §5's TEXT for the barangay, because
Art. IV §6 says "the textual description of the zone boundaries shall prevail
over that of the Official Zoning Maps". Every difference from the CPDO sheet is
named on the checklist (question C11).

Annex A item 89 still makes the lists open: a trade on no list is "not on the
list — CPDO decides", never "prohibited" (Art. III §2 reads the lists in favour
of the applicant and "and the like" as taking in similar uses).

**Which line a trade IS is read by hand, not by word overlap.** An independent
audit (3 October 2026) found the matcher accepting one shared word: a gasoline
station, an auto-repair shop and a rent-a-car on Maximum R-2's "Water refilling
Station, with parking space for delivery vehicles"; trucking on "Small scale
eatery" (the word "road"); a bar on the Institutional zone's "Places of
worship" — each reported as Met. Now `App\Support\Zoning\TradeUses` holds every
code on the register, read against the lists and the definitions (Art. III §1,
Annex A): the lines that ARE the trade (Met), the lines it MAY be — at some
scale, or as one of a "like:" list's examples (CPDO checks, never Met) — and
the definition that decided it. A code not in the table is never Met.
`ZoningTradeMatchingTest` pins each of the audit's false listings and walks the
whole register across every zone. Definitions that decide a filing are applied:
a hotel with in-room cooking is a hotel apartment (Annex A 44-45), dry cleaning
with flammable solvents is Industrial-2's plant (Annex A 28), a lessor of three
or more homes leases an apartment building (Art. III), and a parking building,
an office building and a cemetery chapel are held to what their definitions
exclude (Annex A 72, 64, 38).

Where one code covers uses the lists keep apart, the table does not choose:
a tailor and a garment factory (14100), tutorial services, tutorial centres,
driving, vocational and short-course schools (85490), leather, rubber and wooden footwear (15200), a
paint store with or without bulk handling (47522), an appliance repair shop at
neighbourhood scale or not (95220). Each is asked on the use finding, read from
the applicant's own description when unanswered, and only "possibly" listed
until one or the other says which. A pay parking lot, which has no code, is
reached from transport support (52290), from a lessor who leases parking
(68100) or from the description's words — but only a vehicle trade (transport,
transport support, vehicle rental, "Other", a lessor of parking) is read AS
what its vehicles are for. Every other trade is judged by its own code: a
gasoline station "with parking lot" is a gasoline station, and its parking is
a second use with its own finding and conditions that can never make the
station allowed where it is not listed. A sweep test holds every register code
to that in every zone, under every vehicle answer and description. Listed uses no register code reaches
— a medium junk shop, lechon stores, a chicharon factory, a car wash, event
planners, vocational, dance, self-defense and SPED schools — are reached from
"Other (not listed)" by the description or by picking one (`listed_use`), which
CPDO can also record on its sheet, its answer overriding the applicant's.

## What the ordinance *does* settle

- **Overlay zones exist, and we now model all three.** Flood (all 21 barangays),
  Heritage (Baritan, Concepcion, Hulong Duhat, Ibaba, San Agustin),
  Eco-Tourism (Dampalit). Flood and Eco-Tourism are confirmed twice over: Art.
  IV §5's right-hand column and Annex C's map index agree. They are held in `zoning_overlays` /
  `barangay_zoning_overlay` — deliberately not as rows in
  `zoning_classifications`, since an overlay lies *over* a base zone rather than
  being one (see the migration `2026_09_01_000020`). Codes keyed on Art. V's
  spellings, which contradict Art. IV's; the disagreement is recorded in
  `docs/questions-for-malabon.md` C7 rather than resolved by us. Heritage is
  the one that does not agree: Annex C maps five barangays, §5 names eight
  (correction 7 below). The seeded overlay follows Annex C; the zoning check
  asks "is the building a declared heritage house?" in all eight and tells the
  three §5-only barangays why. The heritage rules bind a declared house of
  ancestry and new construction beside one (Art. V §4.3), not a barangay.
- **Easement Zone is real, regulated (§2.14), and on no map.** It is designated
  in §2 but absent from §5's per-barangay table, because it is functional —
  determined by proximity to waterways. Our 19 seeded classifications correctly
  mirror the 19 that §5 and the sheets actually draw.
- **A change of activity, or expansion of area, requires a NEW Locational
  Clearance** (Art. IX §8, repeated in §9). Now wired in: an amendment that
  changes the line of business or enlarges the floor area carries a zoning
  clearance, as a move already did (`WorkflowService::
  amendmentNeedsLocationalClearance`). A change of owner does not (Annex A 63).
- **Renewal of an unchanged, conforming business is not addressed anywhere.**
  §9's one-year validity governs non-*use* of an issued clearance, not annual
  re-application. The one thing the ordinance renews yearly is a non-conforming
  use's Certificate of Non-Conformance (§10.2(d), §11). Any yearly zoning
  clearance for a conforming business is the City's practice, not the
  ordinance's (questions A28).
- **A construction/renovation clearance cannot be used for the business
  activity conducted inside it** (§10.2.C). Two distinct clearances.
- **The filing fee is paid before the application is accepted, and the
  zoning and land-use verification fee before the approved clearance is
  released** (§10.1(c) items 12–13, which name schedules (a) and (b); the
  processing fee's timing is §10.4's "before the permit is issued").
  Independently corroborates payment-first ordering.
- **The Zoning Administrator decides Locational Clearances; the LZBA hears
  variances, exceptions, complaints and appeals** (§§13–17), and its decisions
  go to HLURB. The 30-day deadline is the LZBA's, on a variance or exception
  (Art. VIII §2(8)); the ordinance sets none for a locational clearance.

## Fee discrepancy — unresolved, do not "fix" unilaterally

`ReferenceSeeder.php` cites Revenue Code Sec. 3.D.01 as 45 + 345 + 345 = ₱735.
The ordinance (Art. IX §10.1) gives:

| Component | Ordinance | Our seeder |
| --- | --- | --- |
| Filing fee | ₱45.00 | ₱45 ✓ |
| Verification, commercial/industrial | ₱300.00 | ₱345 ✗ |
| Processing | **₱18.00 per sq.m. of floor area** | ₱345 flat ✗ |

₱345 ÷ ₱18 = 19.2 sqm, so the flat figure is not a rounded instance of the
formula either. Either the Revenue Code supersedes the ordinance's schedule —
entirely plausible, a revenue code is the fee instrument — or ₱735 is stale.
Note the ordinance scales processing with floor area, which a flat fee cannot
express at all. This is a question for BPLO, not a bug to patch.

## Corrections to the earlier extraction (3 October 2026)

The PDF was read again page by page for `rules.json`, with the three-column
tables checked against the page images. What changed in what this folder said:

1. This README listed `zone-uses.json` as covering §3–5 too. It holds
   §2.1–2.20 only; §3–5 are in `rules.json`.
2. It said typos and dropped words are "preserved verbatim throughout". The
   provisos in `zone-uses.json` are normalised (C-1's "Proponents must be first
   secure" reads "proponents must first secure"; "subject to conditions:" is
   ours). `rules.json` is the verbatim source; `zone-uses.json` is kept as the
   matcher's input and every one of its 695 uses was confirmed to appear in the
   PDF text.
3. It said §10.1(c) 12–13 set the processing fee's timing. They set the filing
   fee's (schedule a) and the verification fee's (schedule b).
4. It said renewal is addressed nowhere. Non-conforming uses renew their
   certificate yearly (§10.2(d), §11).
5. It said C-2's inheritance is broken and resolving it would be legislating.
   It is not broken: C-2 inherits all of C-1, which reaches both R-2 zones.
6. It attached "decisions within 30 days" to locational clearances. That
   deadline is the LZBA's (Art. VIII §2(8)).
7. It said the §5 table's Heritage column cannot be read per row. Read from the
   page images it can, in the rows that matter: Dampalit's and Flores's C-1
   strips and Bayan-Bayanan's rows carry "Heritage Overlay Zone". §5 names eight
   barangays, Annex C maps five; the disagreement is real (questions C7).
8. The §5 table puts the same Potrero area (Tullahan River, NLEX, the listed
   lots) under both C-3 and the CBD — not noted before (questions C20).
9. Not noted before either: §3 has a tenth special use, J. Transport Terminals,
   which the table of contents omits; Art. VI §5's items run 1–4 then 6–8; and
   Annex A 26 defines "dominant land use" (70% within 1 km), which no rule ever
   uses. The ordinance was approved by the Sangguniang Panlungsod on 26 November
   2018 (pp. 2–5, scanned minutes with no text layer); its effectivity date,
   which the ten-year phase-out runs from, is not in the PDF (questions C16).

10. **Second reading (3 October 2026).** An independent reader inventoried the
    PDF separately (557 entries, 65 findings) and audited this one. What it
    found here, now corrected: Sanciangco St. had silently been given the 1 m
    road-widening setback, though the sentence reads either way (C39); the
    grease-and-oil limit had been restated as one rule, though the ordinance
    gives two figures (C36); "10% of 30%" in the eco-tourism overlay had been
    read one way (C38); the residential garage limits had one cap of two
    vehicles for every kind, where the text gives ride-hailing two, a taxi
    one, Maximum R-2 business parking two and Basic R-3 one; and `rules.json`
    called 42 entries implemented, or not applicable, that the code only showed.
    Each of those now says what it is.
11. `zone-uses.json` carries a comma the PDF does not: the C-2 machine-shop
    proviso reads "makeshift materials, with firewalls" where the ordinance has
    "makeshift materials with firewalls" (which, read literally, forbids
    firewalls). The check reads firewalls as required and says so (C40).

## Every finding of the second reader, and where it is handled

The second reader's inventory recorded 65 places where the ordinance
contradicts itself, points at something that does not exist, or reads
differently from what it means. Each is handled below. Those that change an
outcome for a filing are a question to the City **and** a "CPDO checks" finding
on the checklists where they apply; those that change nothing are recorded
here so none is silent.

| Id | What the second reader found | Where it is handled |
| --- | --- | --- |
| C-01 | Home occupation limits disagree: Art V §2.1 allows up to 5 persons incl. owner and up to 20% of the building; Annex A #42 allows no non-resident employee, no non-household mechanical equipment and up to 1/4 of floor area or one … | Question C14; both definitions checked (A-42 finding). |
| C-02 | Cottage-industry capital ceiling: Art V §2.1 says "capitalization as set by the DTI"; Annex A #23 fixes Php 100,000 at registration (PD 817 adopted). | Question C19; ₱100,000 met, above it CPDO (V-2.1-HI-3). |
| C-03 | "One lot deep" is defined three ways: parcellary subdivision existing at passage (Art IV §6 para 6), average lot depth of the vicinity with a 50% remainder rule (Art IV §6 para 11), and 30 m from road centre (Annex A #67). "One … | Question C15; both readings shown on strip streets (IV-5-STRIP). |
| C-04 | R-1 adjoining C-2: Art VII §1(1) caps the C-2 structure at 12 m/4 storeys (no street/open space over 6 m, adjacent front yards) while §1(2) caps it at 9 m/3 storeys (no street/open space over 4 m). A site with <=4 m separation … | Question C29; new construction in C-2/C-3 beside R-1/R-2 shows both caps (conflict finding). |
| C-05 | The same Potrero parcel description ("Area bounded by Tullahan river; North Luzon Expressway (NLEX); Lot 8-B –LRC Psd-324406; ...") is listed under both C-3 and CBD. | Question C20; Potrero finding to the officer (IV-5-POTRERO). |
| C-06 | Non-conforming duration: Art IX §11 and §12(10) let non-conforming uses continue until the establishment ceases operation; §12(9) requires the owner to program phase-out and relocation within 10 years of effectivity. | Question C16; both rules shown on a non-conforming renewal. |
| C-07 | Heritage Overlay: new construction is limited to "base R-1 Zone" uses and the rules "supersede those provided in the base R-1 zone", but the Art IV §5 table applies the Heritage Overlay only to MR-2, C-1 and C-2 areas (no R-1 … | Question C25; non-R-1 new construction in the overlay goes to CPDO (V-4.3-NEWUSES). |
| C-08 | Heritage Overlay coverage: the text table assigns it in 8 barangays (Baritan, Bayan-Bayanan, Concepcion, Dampalit, Flores, Hulong Duhat, Ibaba, San Agustin) to whole zone areas; Annex C has maps for only 5 and marks individual … | Question C7; the three §5-only barangays are told so (IV-5-HTG). |
| C-09 | Cockpits must be "located in parks and recreational zones" (§3.F), but the PR-Z use list omits cockpits and PR-Z has no mapped areas of its own (existing parks "follow base zone"). | Question C31; every cockpit filing shows it (conflict finding). |
| C-10 | Fuel stations: C-1 requires DOE standards, 1 km from existing gasoline/LPG stations and contemplates siting "within a residential zone" with HOA/Barangay/Fire consent (no residential zone lists the use); §3.C requires Energy … | Question C13 (extended); residential filing-station finding. |
| C-11 | Gaming outlets (lotto, off-fronton, online bingo, OTB) need >200 m from institutional establishments in C-1 but have no distance rule in GC; CBD inherits both. | Question C28; a General Commercial lot is shown the dropped 200 m rule (V-2.10-FLAT). |
| C-12 | Conditions attached in C-1/C-2 (restaurant/food-park parking, auto-repair and car-wash grease traps, machine/welding/junk-shop firewalls and barangay-agreed hours) are absent from the same uses in GC; CBD inherits both versions. | Question C28; a General Commercial lot is shown each dropped condition (V-2.10-FLAT). |
| C-13 | Hauling: C-2 allows garages for "trucks, tow trucks and buses" unconditionally; GC allows "trucks and tow trucks" only if the business is within Malabon. | Question C28; a C-2 lot is shown General Commercial’s hauling condition (V-2.10-FLAT). |
| C-14 | Same activity, different classes: wood/rattan furniture and box-bed/mattress manufacture are C-2/GC uses and also I-2 Pollutive/Hazardous; biscuit, doughnut/hopia and bakery n.e.c. factories are C-2/GC and I-1; insignia/badges … | Question C32; finding for furniture, bakery, ice-plant, insignia and dry-ice trades. |
| C-15 | I-2 Pollutive/Non-Hazardous list ends with "Warehouse/Storage Facility for non-pollutive/non-hazardous industries" (I-1 already has it); the pollutive/non-hazardous warehouse appears to be missing. | Question C33; finding for a warehouse that may be in I-2. |
| C-16 | Basic R-3 is ranked below Basic R-2: defined as "low to medium density" (BR-2 "medium"), height 11 m (BR-2 13 m), business-support parking 1 vehicle (MR-2 2), and it does not inherit MR-2 uses. | Question C18 (extended); each rule applied as written (one van in Basic R-3). |
| C-17 | Easement Zone is a base zone in Art IV §2 but calls itself an "overlay zone" in §2.14 and has no rows in the Art IV §5 table; the Mangrove Zone row ("areas along easement of Malabon-Navotas, Chungkang, Batasan, Muzon Rivers") … | Question C35; finding for a riverside lot where §5 places a Mangrove Zone. |
| C-18 | Base Flood Elevation source: Art III says DPWH regional office calculation; Art V §4.1 says CLUP 2018-2027 Part V assessment unless DRRMO updates. | Question C27; new construction is shown both sources (III-1-BFE). |
| C-19 | Flood susceptibility classes overlap at 0.50 m, 1.00 m and 2.0 m (each value belongs to two classes). | Question C27; the overlapping class edges are named on new construction. |
| C-20 | Billboards: §3.I.1 confines them to lots fronting the National Road while §3.I(m) bans signs "along ... Road Rights-Of-Way, whether it be National Road"; Art VII §13 allows billboards/business signs anywhere with an LC … | Question C21 (extended); billboard finding names all three rules. |
| C-21 | Sewerage grease & oil limit "in excess of 300 PPM or exceed daily average of 10 PPM" is internally inconsistent. | Question C36; the sewerage finding gives both figures. |
| C-22 | Geological-hazard table recommends 1-4 storeys in Potrero (Prensa clay loam) and 1-2 storeys in Longos (hydrosol), yet C-3/CBD there carry a 180 m height limit. | Question C30; new construction in C-3/CBD shows soil advice against 180 m. |
| C-23 | Fuel-station standards regulator: DOE (C-1) vs Energy Regulatory Board (§3.C). | Question C13; the conflict finding names DOE and ERB. |
| C-24 | Fishponds: MR-2 Muzon excludes "areas occupied by existing fishponds" but only Dampalit fishponds are zoned (FZ); Muzon fishponds have no zone. The Fishpond Zone itself has no allowable-use list. | Question C17 (extended); every Muzon filing names it. |
| C-25 | PR and UTS zones are "all areas occupied by existing ..." with the overlay column saying "Follow Base Zone", leaving it unclear whether those areas are PR/UTS or the surrounding base zone. | Question C34; finding wherever the sheet draws Parks or Utilities. |
| C-26 | Penal clause duplicated (§10.3(2) and §23) with different officer-liability wording (managers "criminally responsible" vs penalty "imposed upon the erring officers"). | No outcome on a filing: §10.3(2) and §23 penalties are a court’s. Recorded here. |
| C-27 | Possible overlaps: C-1 Panghulo "Area bounded by Rodriguez St.; Narra St., M.H. del Pilar St. and Panghulo Road" vs C-2 Panghulo Market "bounded by Rodriguez St., M.H. Del Pilar St., Narra St. and Rodriguez St."; C-1 Santulan … | Question C37; officer finding in Panghulo and Santulan. |
| C-28 | Coverage gaps in tables: telecom-tower fee covers "< 2 millions" and "> 2 millions" but not exactly Php 2M; sign-setback table skips ROW widths between 29-30, 24-25 and 19-20 m. | Questions C10 (₱2 million) and C21 (setback gaps); billboard finding names the gaps. |
| C-29 | Payment timing items 12-13 refer to schedules (a) and (b) but sit inside schedule (c); processing-fee (c) timing is only implied by §10.4. | README (fees section and correction 3). |
| C-30 | Certificate of non-conformance is "renewed yearly for a period specified under the amended Zoning Ordinance" but no period is specified anywhere. | Question C16 (extended); the non-conforming finding quotes it. |
| C-31 | Special use permit is required for ten uses but no issuing office, procedure, form or fee is given. | Question C24. |
| C-32 | Effectivity and amendments require Sangguniang Panlalawigan approval, but Malabon (a highly urbanized city in Metro Manila) has no province. | Question C16 (extended). |
| C-33 | Abbreviations disagree: Flood Overlay "LSD-OZ" (Art IV §3) vs "FLD-OZ" (Art III, Art V); Ecotourism "ET-OZ" vs "ETM-OZ"; Institutional "I-Z" vs "IZ"; Mangrove "M-Z" vs "Mn-Z"; UTS "UTS-SZ" vs "UTS-Z". | Question C7; codes keyed on Art. V (README, known limits). |
| X-01 | Art III §1 points to "Appendix 'A'"; the annex is titled "ANNEX A". | No outcome: "Appendix A" is Annex A. Recorded here. |
| X-02 | Art IV §1 "Refer to Annex 1 for appropriate color codes" - there is no Annex 1. | README, known limits (no Annex 1). |
| X-03 | Art IV §4 "refer to Annex 2 for Sample Zoning Maps" - there is no Annex 2 (maps are the p6 city map and Annexes B/C). | No outcome: "Annex 2" is the p. 6 map and Annexes B and C. Recorded here. |
| X-04 | Home occupation "customary accessory uses cited above" - the accessory-use list comes later (p38). | No outcome: the accessory-use rule is applied (V-2.1-HO-4). Recorded here. |
| X-05 | Billboard spacing "in determining compliance with this Section 4.2" - §4.2 is the Ecotourism Overlay (text copied from an MMDA regulation). | Question C21 (extended): the billboard spacing rule cites §4.2. |
| X-06 | MRF clause cites a "Revised Comprehensive Zoning Ordinance of the City of Malabon" and a "Local Zoning Committee", neither created or identified by this Ordinance. | Question C24 (extended); the MRF conditions row names it. |
| X-07 | "Implementing Guidelines" / "Implementing Rules and Regulations of this Zoning Ordinance" said to be part of the Ordinance are not attached. | Question C26; finding on industry and the MRF conditions row (VI-1-IRR). |
| X-08 | Art IX §11 "requirements set under Section 13" - §13 is Responsibility for Administration; §12 (non-conforming conditions) is presumably meant. | Question C16 (extended). |
| X-09 | Art IX §12(10) "LZEAC committee" - undefined body. | Question C16 (extended); the LZEAC finding says how it is read. |
| X-10 | Art VIII §1 is headed "Infrastructure Capacities" but contains the variance/exception tests (TOC repeats the wrong heading). | README, known limits. |
| X-11 | Annex A #15 "prior to the adoption of this Code". | No outcome: "this Code" in Annex A 15. Recorded here. |
| X-12 | Blank references: HLURB Resolution No. "_______" Series of 2014 (p7); SP/SB Resolution No. "________" dated "_________" approving the CLUP (Art II §3). | Question C16; PREAMBLE entry. |
| X-13 | Art VII §2 cites "PD 1076 or Water Code" (PD 1067, as Art II §1 has it) and "RA 100121" (RA 10121). | No outcome: VII-2 is not applicable (area regulations for permits). Recorded here. |
| X-14 | HLURB "Resolution No. 626, series of 998". | No outcome: read as HLURB Resolution 626 of 1998 (V-3-G). Recorded here. |
| X-15 | "certificate of conformance" (§10.3) is not defined or issued anywhere. | Question C10 (extended). |
| X-16 | TOC omits Art V §3.J Transport Terminals and labels §2.13 "INDUSTRIAL-2 (I-) ZONE". | README, correction 9. |
| X-17 | Art VI §5 numbering skips item 5. | README, correction 9. |
| T-01 | Fee class "constructed primarily for grain purposes" - "gain"; the commercial rate turns on it. | Question C10 (extended). |
| T-02 | "provided the business activity I within the City of Malabon" - "is". | No outcome: read as "is" (V-2.10-HAUL). Recorded here. |
| T-03 | "Araneta occupied by Victoneta North Village" - "Area occupied". | No outcome: the row is about Victoneta North Village. Recorded here. |
| T-04 | MR-2 rows with garbled bounds: Concepcion has two "South" bounds; Panghulo "East by North by Tahimik St." with two "East" bounds; Maysilo "West bi Industrial Zone". | Question C11 (extended); those lots are CPDO’s to place. |
| T-05 | C-1 Santulan "One lot deep commercial strip (right side from Aurora St. to Javier St." - street name missing; "Luis S." truncated. | Question C37; officer finding in Santulan. |
| T-06 | Ecotourism: "exiting fishpond" (existing), "cloches" (clothes), "with be allowed"; "10% of 30%" is ambiguous (3% of lot vs 10%). | Question C38; the eco-tourism finding gives both readings. |
| T-07 | "scale of 1:10,000 meters" - a ratio scale has no unit. | No outcome: the 1:10,000 scale is a ratio. Recorded here. |
| T-08 | "facade be not made of makeshift materials with firewalls" reads as forbidding firewalls; intended "...materials, with firewalls". | Question C40; the machine-shop finding says how it is read. |
| T-09 | "Shall be least 200 meters from ... places of assembly courts or public office" - "at least"; "assembly, courts". | No outcome: read as "at least 200 m" (V-3-E-1). Recorded here. |
| T-10 | Annex A #65 defines "Off-Street Parking" as parking "along any street, except at designated areas". | No outcome: Annex A 65 defines nothing a check reads. Recorded here. |
| T-11 | "Environmental Compliance Certificate (from DENR Pollution Control (from EPWMD)" - garbled agency name. | No outcome: the ECC finding names DENR. Recorded here. |
| T-12 | "within ten (10) years from the effectively of this Ordinance" - effectivity. | No outcome: "effectivity". Recorded here. |
| T-13 | "criminally responsible as provided for in this action" - section. | No outcome: "section". Recorded here. |
| T-14 | Road-widening sentence punctuation makes it unclear whether Sanciangco St. takes the 3 m or the 1 m setback. | Question C39; a Sanciangco St. filing is shown both setbacks (it was silently 1 m). |
| T-15 | "AN ODINANCE", "SANGGUNIANG PANLUNSOD". | No outcome: "ordinance", "Panlungsod". Recorded here. |

## Known limits of this extraction

- In the Institutional Zone block the overlay column is misaligned in the text
  layer. Per-*paragraph* overlay attribution is unrecoverable; only the
  per-barangay union is safe. Confirm overlays against Annex C's maps, not
  against the §5 table.
- One genuinely ambiguous cell at the p.11/p.12 break: an orphan "West by
  Commercial Zone along Gen. Luna" fragment with no barangay label, appended to
  Ibaba's Maximum R-2 row. It may belong to a row whose head was lost.
- Zone codes disagree between articles: Mangrove is M-Z in Art. IV and Mn-Z in
  Art. V; UTS-SZ vs UTS-Z; I-Z vs IZ; Flood is LSD-OZ in Art. IV and FLD-OZ in
  Art. V; Ecotourism ET-OZ vs ETM-OZ. Codes here are as printed, per article.
- Art. IV §2 cross-references "Annex 1 for appropriate color codes". There is
  no Annex 1 — the annexes are lettered A, B, C and none is a palette. Our
  `legend_color` values were sampled off the map images and the ordinance
  offers nothing to check them against.
- Art. VIII §1 is mis-captioned "Infrastructure Capacities" in both the table
  of contents and the body, while containing the variance and exception
  grounds. The real infrastructure section is Art. VI §6.
- Typos and dropped words are preserved verbatim in `rules.json` rather than
  silently corrected. `zone-uses.json` normalises the wording of its provisos
  (correction 2); quote `rules.json`, not it.
