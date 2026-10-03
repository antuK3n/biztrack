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
| **`rules.json` / `rules.md`** | **all, pp. 1–110** | **Every normative statement — 341 of them — with its verbatim text, article, section and printed page, what data it needs, and its status in BizTrack (implemented, shown, not applicable, question). The file the zoning check reads its wording from. `rules.md` is generated from it by `scripts/zoning-rules.py`.** |
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
   (C13–C16). Nothing is resolved by picking a side.
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
