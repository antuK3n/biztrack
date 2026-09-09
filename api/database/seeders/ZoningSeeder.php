<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\ZoningClassification;
use App\Models\ZoningOverlay;
use Illuminate\Database\Seeder;

/**
 * CPDO's zoning legend, what each barangay's official sheet actually shows, and
 * the overlay zones the ordinance lays over them.
 *
 * ## Source
 *
 * Twenty-one sheets supplied by the client, one per barangay: "Brgy. <Name>
 * Proposed Zoning Map 2018 - 2027", City of Malabon, prepared by the City
 * Planning and Development Department, 2017. 1:3,000 on twenty of them; Santulan
 * arrived as a separate 1:6,000 sheet. Coordinate system Luzon 1911 / Philippine
 * Zone III. The images are shipped at `web/public/zoning-maps/<slug>.png`.
 *
 * ## firstOrCreate, not updateOrCreate — deliberately
 *
 * The rest of `ReferenceSeeder` owns its rows and uses updateOrCreate. Nothing
 * here is owned by the seeder once it has run, for the same reason
 * `office_signatories` is not: these are proposed maps for a plan period ending
 * 2027, an ordinance can revise a classification at any time, and CPDO has been
 * asked (`docs/questions-for-malabon.md` C2/C4) whether this proposal is even
 * the map in force. A re-seed must not quietly reinstate a classification an
 * admin removed after CPDO corrected us.
 *
 * The cost of that choice is that a value seeded wrongly cannot be fixed by
 * editing this file — the row has to be edited, or deleted and re-seeded. That
 * is the right way round: this file's numbers were read off pixels, and the row
 * is where a human's correction lives.
 *
 * ## How the per-barangay lists were derived, and what they are NOT
 *
 * Read off the sheets by exact palette match against the legend swatches, then
 * checked by eye where the match was ambiguous.
 *
 * Which colour is which is not a judgement call: nineteen swatch RGBs were
 * sampled from the legend block, and only the SUBJECT barangay is drawn in those
 * exact values — neighbouring barangays are washed toward white (a neighbour
 * pixel is round(0.2*c + 204)), so an exact match is by construction inside the
 * barangay whose sheet it is.
 *
 * What IS a judgement call is telling a fill from a stroke, because three
 * palette colours are reused as line work:
 *
 *  - (156,156,156) is the Utilities fill AND the 1px cadastral lot line, which
 *    covers every residential block on every sheet. Utilities is recorded only
 *    where the colour survives a 5x5 erosion and the surviving blob is at least
 *    15px across in both directions. That rule is what keeps Longos out: its
 *    only solid grey is an 8x80 strip, a road casing rather than a parcel.
 *  - (255,127,127) is C-1 AND the General Commercial Zone's polygon border. C-1
 *    within 4px of a GCZ polygon is subtracted, which is what keeps Catmon out:
 *    2,893 of its 3,035 C-1 pixels hug the GCZ boundary and the two bounding
 *    boxes coincide.
 *  - (255,255,0) is R-3 Max, AND the bars of the CMP hatch, AND the R-2 Max
 *    outline. Split three ways: touching the olive (168,168,0) hatch => CMP;
 *    surviving a 5x5 erosion => a solid R-3 Max polygon (only Acacia); a thin
 *    stroke hugging R-2 fill => R-2 Max.
 *
 * A list here means: **this classification is drawn somewhere on this barangay's
 * sheet.** It does not mean a given address carries it. The sheets are rasters
 * with no geometry; a per-location answer needs vector polygons that we have
 * asked CPDO for and do not have. Nothing downstream may turn these rows into a
 * conformity verdict.
 *
 * Known limits, stated rather than smoothed over:
 *
 *  - **R-2 Basic vs R-2 Max is the weakest reading on the sheets.** They share
 *    the fill (255,255,180) and differ only by a 1px pure-yellow outline that
 *    every lot line crossing it breaks, so the polygons cannot be closed and
 *    measured — only the stroke can be found. Both readings are recorded
 *    wherever that stroke wraps R-2 fill, which is every sheet except Acacia
 *    (which has almost no R-2) and Potrero. Two of them were confirmed by
 *    looking at the pixels — Niugan, where the strokes are unmistakable closed
 *    loops around whole blocks, and Longos at the other end of the range with
 *    208px. It is the first thing the vector data would settle.
 *  - **Santulan's sheet is different in kind** — a 960x720 JPEG at 1:6,000 with
 *    its own legend — so its list was read visually inside the barangay
 *    boundary rather than by palette match. At that resolution a 1px R-2 Max
 *    outline and a dotted CBD/GCZ fill are below what survives JPEG, so absence
 *    on Santulan is weaker evidence than absence elsewhere.
 *  - **Anything under about 250 pixels was left out.** That is a real floor, not
 *    a safe one: a small classified parcel exists at that size. Tinajeros has a
 *    199px patch of General Commercial Zone that did not make the cut.
 *  - A few entries sit just over the floor and are the ones to re-check first:
 *    Bayan-bayanan Institutional (291), Maysilo Cemetery (276), Niugan Parks
 *    (277), Tugatog Parks (424), Longos R-1 (497).
 *
 * ## The overlay zones come from the ordinance, not from the sheets
 *
 * The three overlays below were not read off pixels — they are not drawn in the
 * sheets' 19-entry legend at all. They are designated in City Ordinance No.
 * 24-2018 Art. IV §3 and assigned in Annex C's map index, with Art. IV §5's
 * right-hand column agreeing independently. That is why they are their own
 * table: see the migration 2026_09_01_000020.
 *
 * Heritage is the one assignment where our two readings of the ordinance do not
 * match, and we take Annex C. The text-layer extraction of the §5 table
 * (`docs/zoning-ordinance/zone-boundaries.json`) attributes Heritage to eight
 * barangays — the five below plus Bayan-Bayanan, Dampalit and Flores — but that
 * table's overlay column is misaligned in the Institutional Zone block, which
 * `docs/zoning-ordinance/README.md` records and which makes per-row attribution
 * from it unrecoverable. Annex C's map index gives five. The extraction's own
 * README says to confirm against Annex C, so five it is; a Heritage designation
 * we invented for three barangays would be a rule an applicant is told about
 * that the city never made.
 */
class ZoningSeeder extends Seeder
{
    /**
     * The legend, in CPDO's own order, with the swatch colour sampled off the
     * sheets. `code` is ours and stable; `name` is the sheet's wording verbatim.
     *
     * The sheets also carry NLEX, RAILROAD, Malabon Bound, Malabon_AdminBound,
     * ROADS, WATERWAYS_2 and MANILA BAY. Those are map furniture — a railway is
     * not a use a business can be zoned for — so they are not classifications
     * and are deliberately absent.
     */
    private const LEGEND = [
        ['R-1', 'R-1', '#ffffdb'],
        ['R-2-BASIC', 'R-2 Basic', '#ffffb4'],
        ['R-2-MAX', 'R-2 Max', '#ffffb4'],
        ['R-3-BASIC', 'R-3 Basic', '#ffff78'],
        ['R-3-MAX', 'R-3 Max', '#ffff00'],
        ['CMP', 'CMP', '#a8a800'],
        ['C-1', 'C-1', '#ff7f7f'],
        ['C-2', 'C-2', '#ff0000'],
        ['C-3', 'C-3', '#a80000'],
        ['CBD', 'CBD', '#e60000'],
        ['GENERAL-COMMERCIAL', 'General Commercial Zone', '#f24d57'],
        ['I-1', 'I-1', '#c500ff'],
        ['I-2', 'I-2', '#a900e6'],
        ['INSTITUTIONAL', 'Institutional', '#0000ff'],
        ['FISHPOND', 'Fishpond', '#006400'],
        ['PARKS', 'Parks and Recreation', '#38a800'],
        ['MANGROVE', 'Mangrove', '#55ff00'],
        ['UTILITIES', 'Utilities', '#9c9c9c'],
        ['CEMETERY', 'Cemetery', '#c89600'],
    ];

    /**
     * Barangay name (as seeded in `barangays`) => [map file, classifications on
     * that sheet].
     *
     * The file names are ASCII slugs of the barangay name, which is why Tañong's
     * sheet is `tanong.png`: the supplied file was `TAÑONG.png`, and a non-ASCII
     * character in a URL is a percent-encoding argument nobody needs to have.
     */
    private const SHEETS = [
        'Acacia' => ['acacia', ['R-3-MAX', 'C-2', 'I-2', 'INSTITUTIONAL']],
        'Baritan' => ['baritan', ['R-2-BASIC', 'R-2-MAX', 'CMP', 'C-1', 'C-2', 'INSTITUTIONAL']],
        'Bayan-bayanan' => ['bayan-bayanan', ['R-2-BASIC', 'R-2-MAX', 'C-1', 'INSTITUTIONAL']],
        'Catmon' => ['catmon', ['R-2-BASIC', 'R-2-MAX', 'CMP', 'C-2', 'CBD', 'GENERAL-COMMERCIAL', 'INSTITUTIONAL', 'PARKS', 'UTILITIES']],
        'Concepcion' => ['concepcion', ['R-2-BASIC', 'R-2-MAX', 'C-1', 'C-2', 'INSTITUTIONAL']],
        'Dampalit' => ['dampalit', ['R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'C-1', 'INSTITUTIONAL', 'FISHPOND', 'MANGROVE']],
        'Flores' => ['flores', ['R-2-BASIC', 'R-2-MAX', 'C-1', 'INSTITUTIONAL']],
        'Hulong Duhat' => ['hulong-duhat', ['R-2-BASIC', 'R-2-MAX', 'CMP', 'C-1', 'C-2', 'INSTITUTIONAL', 'PARKS']],
        'Ibaba' => ['ibaba', ['R-2-BASIC', 'R-2-MAX', 'CMP', 'C-1', 'INSTITUTIONAL']],
        'Longos' => ['longos', ['R-1', 'R-2-BASIC', 'R-2-MAX', 'CMP', 'C-1', 'C-2', 'CBD', 'INSTITUTIONAL']],
        'Maysilo' => ['maysilo', ['R-2-BASIC', 'R-2-MAX', 'CMP', 'C-1', 'INSTITUTIONAL', 'CEMETERY']],
        'Muzon' => ['muzon', ['R-2-BASIC', 'R-2-MAX', 'CMP', 'INSTITUTIONAL', 'FISHPOND', 'MANGROVE']],
        'Niugan' => ['niugan', ['R-2-BASIC', 'R-2-MAX', 'C-1', 'INSTITUTIONAL', 'PARKS']],
        'Panghulo' => ['panghulo', ['R-2-BASIC', 'R-2-MAX', 'CMP', 'C-1', 'C-2', 'INSTITUTIONAL']],
        'Potrero' => ['potrero', ['R-1', 'R-2-BASIC', 'C-2', 'C-3', 'I-2', 'INSTITUTIONAL', 'PARKS']],
        'San Agustin' => ['san-agustin', ['R-2-BASIC', 'R-2-MAX', 'CMP', 'C-1', 'C-2', 'I-1', 'INSTITUTIONAL', 'CEMETERY']],
        'Santulan' => ['santulan', ['R-2-BASIC', 'C-1', 'I-1', 'INSTITUTIONAL']],
        'Tañong' => ['tanong', ['R-2-BASIC', 'R-2-MAX', 'C-2', 'C-3', 'I-1', 'INSTITUTIONAL', 'PARKS']],
        'Tinajeros' => ['tinajeros', ['R-2-BASIC', 'R-2-MAX', 'C-1', 'C-2', 'CBD', 'I-2', 'INSTITUTIONAL']],
        'Tonsuya' => ['tonsuya', ['R-2-BASIC', 'R-2-MAX', 'CMP', 'C-1', 'C-2', 'INSTITUTIONAL', 'CEMETERY']],
        'Tugatog' => ['tugatog', ['R-2-BASIC', 'R-2-MAX', 'C-1', 'C-2', 'I-1', 'I-2', 'INSTITUTIONAL', 'PARKS', 'UTILITIES', 'CEMETERY']],
    ];

    /**
     * The three overlay zones, in the ordinance's own order (Art. IV §3), with
     * the barangays each is designated over and a one-line description of what
     * the overlay is.
     *
     * The codes are ours, because the ordinance gives two sets and they
     * disagree: Art. IV §3 writes LSD-OZ / HTG-OZ / ET-OZ, Art. V §4 writes
     * FLD-OZ / HTG-OZ / ETM-OZ. Only Heritage agrees. We use Art. V's, since
     * Art. V is where the overlay's regulations are actually written and FLD
     * reads as Flood where LSD reads as nothing. Recorded rather than smoothed
     * over: a reader holding Art. IV will not find FLD-OZ in it.
     *
     * The descriptions state what the overlay IS and what regulations it adds,
     * paraphrased from Art. V §4. They say nothing about any applicant's
     * property, and nothing downstream may turn them into one — which lots an
     * overlay covers inside a barangay is CPDO's to determine, exactly as with
     * the base classifications.
     */
    private const OVERLAYS = [
        [
            'FLD-OZ',
            'Flood Overlay Zone',
            // Says what the ordinance adds and stops. An earlier draft ended
            // "and changes no allowed use", which is true of Art. V §4.1 and is
            // still the card starting a sentence about what an applicant may
            // do — the one thing this screen does not get to discuss.
            'Areas the city identifies as prone to flooding. The ordinance adds construction requirements here, chiefly raising a building’s ground floor above the base flood elevation.',
            // All 21. Art. IV §5 and Annex C both put Flood over every barangay,
            // which is why this list is written out rather than derived from
            // SHEETS: the two happen to coincide today and mean different things.
            ['Acacia', 'Baritan', 'Bayan-bayanan', 'Catmon', 'Concepcion', 'Dampalit', 'Flores',
                'Hulong Duhat', 'Ibaba', 'Longos', 'Maysilo', 'Muzon', 'Niugan', 'Panghulo',
                'Potrero', 'San Agustin', 'Santulan', 'Tañong', 'Tinajeros', 'Tonsuya', 'Tugatog'],
        ],
        [
            'HTG-OZ',
            'Heritage Overlay Zone',
            'Areas with declared houses of ancestry. The ordinance limits what those structures may be used for, caps the height of new building near them, and asks new designs to match their period.',
            // Annex C, not the §5 extraction — see the class docblock.
            ['Baritan', 'Concepcion', 'Hulong Duhat', 'Ibaba', 'San Agustin'],
        ],
        [
            'ETM-OZ',
            'Eco-Tourism Overlay Zone',
            'The existing fishponds of Barangay Dampalit. The ordinance adds tourism-related uses to the base zone and caps how much of a lot may be built on.',
            ['Dampalit'],
        ],
    ];

    public function run(): void
    {
        $byCode = [];
        foreach (self::LEGEND as $i => [$code, $name, $color]) {
            $byCode[$code] = ZoningClassification::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'legend_color' => $color, 'sort_order' => $i],
            );
        }

        foreach (self::SHEETS as $barangayName => [$slug, $codes]) {
            $barangay = Barangay::where('name', $barangayName)->first();
            if (! $barangay) {
                // The barangay list is ReferenceSeeder's. If a name drifts, say
                // so by skipping rather than inventing a 22nd barangay here.
                continue;
            }

            if ($barangay->zoning_map_path === null) {
                $barangay->update(['zoning_map_path' => "/zoning-maps/{$slug}.png"]);
            }

            /*
             * First run wins, and only the first run.
             *
             * Not sync() — that deletes a pairing an admin added. Not
             * syncWithoutDetaching() either, which looks harmless and is not:
             * it re-attaches a classification an admin REMOVED, so a correction
             * from CPDO would be silently undone by the next `db:seed`. Both are
             * the office-signatory mistake in a different shape.
             *
             * The consequence, deliberately: once a barangay has any pairing,
             * this seeder never touches its list again. A barangay whose list
             * needs replacing is emptied first (or edited directly), which is a
             * decision somebody makes rather than one a deploy makes for them.
             */
            if ($barangay->zoningClassifications()->exists()) {
                continue;
            }

            $ids = [];
            foreach ($codes as $code) {
                $ids[] = $byCode[$code]->id;
            }
            $barangay->zoningClassifications()->attach($ids);
        }

        $this->seedOverlays();
    }

    /**
     * The overlay zones and their per-barangay designations.
     *
     * Same ownership rule as the classifications above — firstOrCreate for the
     * rows, and a barangay that already has any overlay is left alone — for the
     * same reason. An overlay removed by an admin because CPDO said we had it
     * wrong must not come back on the next `db:seed`.
     *
     * Guarded per barangay and not per table: Heritage covers five barangays and
     * Eco-Tourism one, so "the table already has rows" would mean the other
     * sixteen never got their Flood designation on a later run.
     *
     * The set of already-designated barangays is taken ONCE, before anything is
     * attached. Asking mid-loop would be wrong in a way that is easy to miss:
     * Flood is attached to all 21 first, so by the time Heritage came round
     * every barangay would look already-designated and Heritage would never be
     * attached at all on a first run.
     */
    private function seedOverlays(): void
    {
        $alreadyDesignated = Barangay::has('zoningOverlays')->pluck('id')->all();

        foreach (self::OVERLAYS as $i => [$code, $name, $description, $barangayNames]) {
            $overlay = ZoningOverlay::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'description' => $description, 'sort_order' => $i],
            );

            foreach ($barangayNames as $barangayName) {
                $barangay = Barangay::where('name', $barangayName)->first();
                if (! $barangay) {
                    // ReferenceSeeder owns the barangay list. A name that drifts
                    // is reported by the row simply not existing, rather than by
                    // this seeder inventing a barangay to hang an overlay on.
                    continue;
                }

                if (in_array($barangay->id, $alreadyDesignated, true)) {
                    continue;
                }

                $barangay->zoningOverlays()->attach($overlay->id);
            }
        }
    }
}
