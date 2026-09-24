import type { Barangay } from '../../lib/types'
import { ZoneSwatch } from '../../components/ZoningLayer'
import { plainOverlay, plainZoneName } from '../../lib/zoningNames'

/**
 * The applicant's barangay's zoning, in words: the zones CPDO's sheet draws
 * there, in plain names (lib/zoningNames.ts), and a link to the sheet itself.
 *
 * ── What this is allowed to say, and what it is not ──────────────────────────
 *
 * It lists what the barangay's sheet contains somewhere. That is the whole of it.
 *
 * The sheets CPDO supplied are raster images: "Brgy. <Name> Proposed Zoning Map
 * 2018 - 2027". Their coloured areas are now TRACED into approximate polygons
 * (scripts/trace-zoning-sheets.py) that the picker map beside this card draws,
 * with a key and a name on hover for each zone. That geometry is
 * approximate three times over: the sheet's placement is about ±10 m
 * (lib/zoningSheets.data.ts), the barangay polygons it is clipped to are
 * simplified to ~100 m (lib/malabonGeo.data.ts), and the trace itself rounds.
 * On a street of 6 m lots that straddles zone lines, so reading "your lot is
 * C-2" off it would tell an applicant their site conforms when the city may say
 * it does not. Nothing reads a zone back out for the pin, and CPDO decides.
 *
 * And even exact zone polygons would not make a verdict follow: the ordinance
 * itself cannot be resolved into one (`docs/zoning-ordinance/README.md` sets
 * out four independent reasons, from a Fishpond Zone that lists no uses at all
 * to inheritance chains that omit a zone). Better geometry moves this no
 * closer to a verdict.
 *
 * So: no verdict, no "your zone is", no conforming/non-conforming, no colour
 * that reads as a pass. CPDO confirms which zone covers a specific location,
 * and the step says so once, in ZoningConformanceNote.
 *
 * The sheet itself used to be shown here as a large image. It went when the
 * map started drawing the zones: the same picture twice, the second one too
 * small to read, cost most of the card. The link to the original stays, for
 * anyone who wants CPDO's own document.
 *
 * ── Overlay zones ────────────────────────────────────────────────────────────
 *
 * The same rule, and one extra trap. City Ordinance No. 24-2018 Art. IV §3
 * designates three overlay zones — Flood over all 21 barangays, Heritage over
 * five, Eco-Tourism over Dampalit. An overlay is a "transparent zone overlain on
 * a Base Zone" (Art. V §4): it lies over the base zones rather than being one of
 * them, so it gets its own heading and list below the zones and is never mixed
 * into them. On screen it is "Areas with extra rules", named in plain words
 * ("Flood-prone areas") by the same table as the zones.
 *
 * The trap is Flood. It is a designation the ordinance makes over an area, and
 * it must not be dressed as a warning about the applicant's property — not red,
 * not an alert role, no icon that reads as caution. We do not know where their
 * lot is (no geometry, same as above), so "your site floods" is not ours to say
 * and would be the same invented verdict in a more frightening register. The
 * block states what the ordinance designates and what regulations the overlay
 * carries, and stops there.
 *
 * When we hold no overlay rows for a barangay the block renders nothing at all,
 * rather than "no overlays". Absence in our data is not a finding, and printed
 * as one it would read as "no flood zone here", which is a claim about a
 * property that nobody has made.
 *
 * If CPDO's own vector data ever arrives (asked for in
 * `docs/questions-for-malabon.md` C2), the traced layer can be replaced by it;
 * a per-location answer would still need the ordinance problems above solved
 * first. Until then, adding one here would be inventing it.
 */
export default function BarangayZoningMap({ barangay }: { barangay: Barangay | null }) {
  if (barangay === null) return null

  const zones = barangay.zoning_classifications
  const overlays = barangay.zoning_overlays.map((o) => ({ code: o.code, ...plainOverlay(o.code, o.name, o.description) }))
  const mapPath = barangay.zoning_map_path

  return (
    <section
      className="rounded-2xl bg-white px-5 py-4 shadow-card"
      aria-labelledby="barangay-zoning-heading"
    >
      {/*
        * "Zones in <barangay>", which is what the card lists. It read "Zoning
        * map for Barangay <name>" from when the sheet itself was shown here;
        * the picture went to a link long ago and the heading kept promising it.
        */}
      <h3 id="barangay-zoning-heading" className="text-base font-semibold text-ink">
        Zones in {barangay.name}
      </h3>

      {/*
        * No caution line here any more. The step says "the zoning office
        * checks your exact spot" once, in ZoningConformanceNote, beside the
        * only sentence on the step that says what is allowed; see the note
        * there. This card only lists what the barangay's sheet draws, which
        * needs no caveat. It said "CPDO's proposed map for 2018–2027. CPDO
        * confirms what applies to your exact location." until the client's
        * lead (24 September 2026) found the step saying it three times over.
        */}
      {zones.length > 0 && (
        /*
         * Named by the card's heading. There are two lists on this card and
         * "repeated controls need distinct accessible names" applies to them
         * as much as to buttons: a screen-reader user landing on the second
         * list has to be told it is the extra rules and not more of the zones.
         */
        <ul aria-labelledby="barangay-zoning-heading" className="mt-3 flex flex-wrap gap-2">
          {zones.map((z) => (
            <li
              key={z.code}
              className="inline-flex items-center gap-2 rounded-full border border-line bg-canvas px-3 py-1 text-sm font-medium text-ink"
            >
              {/*
               * The same swatch the map's key draws, from the same function,
               * so a reader can find the zone on the map beside this card.
               * Decorative and marked so: the name beside it is the content
               * (Never Color Alone). The name is the plain one, from the same
               * table the key and the tooltip use (lib/zoningNames.ts).
               */}
              <ZoneSwatch color={z.legend_color} />
              {plainZoneName(z.code, z.name)}
            </li>
          ))}
        </ul>
      )}

      {zones.length === 0 && (
        <p className="mt-3 text-sm text-ink-secondary">
          We don&rsquo;t have the zones for this barangay yet.
        </p>
      )}

      {overlays.length > 0 && (
        /*
         * Set apart from the zone list, because an applicant mistaking an
         * overlay for a base zone is the failure this block exists to avoid:
         * its own heading, and dashed royal pills rather than solid neutral
         * ones. Never Color Alone — remove every colour here and the two lists
         * are still plainly different things, by heading and by outline.
         *
         * The heading says what an overlay IS in the ordinance's own terms, a
         * layer of "additional regulations" over the base zones (Art. V §4),
         * without the word "overlay", which nobody outside a planning office
         * uses. "Some areas" because that is what each one is designated over.
         *
         * Royal, not red. #bd0000 is for errors and destructive actions; a
         * designation made by ordinance is neither, and a flood designation
         * printed in red would read as a warning about this applicant's lot,
         * which is precisely the verdict we cannot make.
         */
        <div className="mt-4">
          <h4 id="barangay-overlay-list-heading" className="text-sm font-semibold text-ink">
            Areas with extra rules
          </h4>
          <ul aria-labelledby="barangay-overlay-list-heading" className="mt-2 flex flex-wrap gap-2">
            {overlays.map((o) => (
              <li
                key={o.code}
                className="inline-flex items-center gap-2 rounded-full border border-dashed border-royal/50 bg-royal-tint px-3 py-1 text-sm font-medium text-royal"
              >
                {o.name}
              </li>
            ))}
          </ul>
          {/*
            * What each one asks, folded away: most applicants will never open
            * it, and it is one click away for whoever wants to know what
            * "Heritage houses" means for them.
            *
            * <details> rather than a custom disclosure: it is keyboard operable,
            * announces its own expanded state, and works before React hydrates.
            *
            * Still not red, and still no alert role. The flood wording says what
            * the rule asks of a building and that it changes no uses, and stops
            * there; see lib/zoningNames.ts.
            */}
          {overlays.some((o) => o.meaning !== null) && (
            <details className="group mt-2">
              <summary className="cursor-pointer list-none text-sm font-medium text-royal underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal">
                What these mean
              </summary>
              <div className="mt-2 space-y-1.5 border-l-2 border-royal/25 pl-3">
                {overlays.map((o) =>
                  o.meaning === null ? null : (
                    <p key={o.code} className="text-sm leading-relaxed text-ink-secondary">
                      <span className="font-semibold text-ink">{o.name}.</span> {o.meaning}
                    </p>
                  ),
                )}
              </div>
            </details>
          )}
        </div>
      )}

      {/*
        * The source document, as a text link in a new tab so the form stays
        * put. It read "Open CPDO's original sheet": two words the public does
        * not use ("CPDO", "sheet") for one thing they understand, the City's
        * own zoning map.
        */}
      {mapPath !== null && (
        <p className="mt-4 text-sm">
          <a
            href={mapPath}
            target="_blank"
            rel="noreferrer"
            className="font-medium text-royal underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal"
          >
            See the City&rsquo;s official zoning map
            <span className="sr-only"> (opens in a new tab)</span>
          </a>
        </p>
      )}
    </section>
  )
}
