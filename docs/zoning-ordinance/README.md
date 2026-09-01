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
| `zone-boundaries.json` / `-summary.md` | IV §5 | 99 (zone, barangay) pairs: which base zones and overlays fall in which barangay, with the location prose |
| `zone-uses.json` / `-summary.md` | V §2.1–2.20, §3–5 | 695 allowed uses across all 20 base zones, plus special use areas, overlay zones, incentives |
| `definitions-and-standards.md` | III, VI, VII, VIII | 89 defined terms; performance standards with thresholds; general regulations; variance/exception procedure |
| `annex-a-glossary.json` / `-md` | Annex A | 89 further definitions, 39 of them declarable business types |

## The load-bearing conclusion

**This ordinance cannot be turned into an automated "is my business allowed at
this address" checker, and trying would produce confident wrong answers.**

Four independent reasons, each sufficient on its own:

1. **§2.16 Fishpond Zone enumerates no uses at all.** Heading, definition, then
   a page break. We assign the Fishpond classification to barangays (Dampalit
   among them). There is no textual basis to allow or refuse anything there.
2. **The inheritance chains are broken.** §2.5 (Max R-3) and §2.7 (C-1) both
   inherit from a list that omits Basic R-3. §2.8 (C-2) inherits from "R-1 and
   R-2 Zones" — and "R-2" alone is not a zone; Basic and Maximum R-2 are.
   Resolving that ambiguity is legislating, not implementing.
3. **The same activity carries different conditions in different zones,
   inconsistently.** C-1 attaches provisos to auto repair, car wash, gasoline
   stations and funeral parlors; General Commercial (§2.10) lists the same
   activities flat with no conditions. Filling stations get two conflicting
   rules: §2.7 says DOE standards and 1 km from an existing station, §3.C says
   Energy Regulatory Board and 200 m from schools, churches and hospitals.
4. **Only one use in the entire article is explicitly marked conditional**
   (§2.3). Everywhere else conditionality is embedded in "provided that"
   sub-bullets, so "permitted" and "permitted if" are not machine-separable
   without a human reading each of the 695 entries.

Add that Annex A entry 89 is a residual clause — anything not listed is
"referred to other appropriate national and local laws" — and the enumeration
is explicitly open, not closed. Absence from the list does not mean prohibited.

This vindicates the stance already taken in
`2026_09_01_000010_hold_the_cpdo_zoning_maps_as_data.php` and in
`BarangayZoningMap.tsx`: show the applicant what the map draws, name the zones,
and render no verdict. CPDO decides. The ordinance's own internal
inconsistency is the reason that was the right call, not merely a cautious one.

## What the ordinance *does* settle

- **Overlay zones exist and we model none of them.** Flood (all 21 barangays),
  Heritage (Baritan, Concepcion, Hulong Duhat, Ibaba, San Agustin),
  Eco-Tourism (Dampalit). Confirmed twice over: Art. IV §5's right-hand column
  and Annex C's map index agree.
- **Easement Zone is real, regulated (§2.14), and on no map.** It is designated
  in §2 but absent from §5's per-barangay table, because it is functional —
  determined by proximity to waterways. Our 19 seeded classifications correctly
  mirror the 19 that §5 and the sheets actually draw.
- **A change of activity, or expansion of area, requires a NEW Locational
  Clearance** (Art. IX §8). Bears directly on the amendment flow.
- **Renewal of an unchanged business is not addressed anywhere.** §9's one-year
  validity governs non-*use* of an issued clearance, not annual re-application.
  Any yearly-zoning-clearance requirement in our renewal flow is ours, not the
  ordinance's.
- **A construction/renovation clearance cannot be used for the business
  activity conducted inside it** (§10.2.C). Two distinct clearances.
- **Filing fee is payable before the application is accepted; processing fee
  before release** (§10.1.c.12–13). Independently corroborates payment-first
  ordering.
- **The Zoning Administrator decides Locational Clearances; the LZBA hears
  variances, exceptions and appeals** (§§13–17). Decisions within 30 days.

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
- Typos and dropped words are preserved verbatim throughout rather than
  silently corrected. See each file's anomaly notes.
