import { useState } from 'react'

import type { Barangay } from '../../lib/types'

/**
 * The applicant's barangay's official zoning sheet, and the classifications it
 * draws.
 *
 * ── What this is allowed to say, and what it is not ──────────────────────────
 *
 * It shows a picture and lists what is on it. That is the whole of it.
 *
 * The sheets CPDO supplied are raster images: "Brgy. <Name> Proposed Zoning Map
 * 2018 - 2027", 1:3,000, Luzon 1911 / Philippine Zone III. They carry no vector
 * geometry and no georeference we can compute against, so there is no honest way
 * to turn a pin or a street address into "your lot is C-2". Tracing polygons off
 * pixels would produce an answer, and a wrong one would tell an applicant their
 * site conforms when the city says it does not — which is the failure the
 * comment on MALABON_BOUNDS in ApplyWizard refuses for the city boundary, for
 * the same reason.
 *
 * So: no verdict, no "your zone is", no conforming/non-conforming, no colour
 * that reads as a pass. The list is a list of what the barangay contains
 * somewhere. CPDO confirms which one covers a specific location.
 *
 * ── Overlay zones ────────────────────────────────────────────────────────────
 *
 * The same rule, and one extra trap. City Ordinance No. 24-2018 Art. IV §3
 * designates three overlay zones — Flood over all 21 barangays, Heritage over
 * five, Eco-Tourism over Dampalit. An overlay is a "transparent zone overlain on
 * a Base Zone" (Art. V §4): it lies over the base zones rather than being one of
 * them, so it gets its own block below the classification list and is never
 * mixed into it.
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
 * If the vector data ever arrives (asked for in `docs/questions-for-malabon.md`
 * C2), a real per-location check becomes a contained change and this panel can
 * gain an answer. Until then, adding one here would be inventing it.
 */
export default function BarangayZoningMap({ barangay }: { barangay: Barangay | null }) {
  const [broken, setBroken] = useState(false)

  if (barangay === null) return null

  const zones = barangay.zoning_classifications
  const overlays = barangay.zoning_overlays
  const mapPath = barangay.zoning_map_path

  return (
    <section
      className="rounded-2xl bg-white px-5 py-4 shadow-card"
      aria-labelledby="barangay-zoning-heading"
    >
      <h3 id="barangay-zoning-heading" className="text-base font-semibold text-ink">
        Zoning map for Barangay {barangay.name}
      </h3>

      {/*
        * One sentence, and it does two jobs: it dates the map the applicant is
        * looking at, and it says who decides. "Say it once" — the classification
        * list below adds no second disclaimer, because this line already covers
        * it and stacked restatement is what reads as machine-written.
        */}
      <p className="mt-1 text-sm leading-relaxed text-ink-secondary">
        {/*
          * Amended to say "classifications and overlays" rather than gaining a
          * second CPDO sentence beside the overlay block: one line covers both,
          * and stacked restatement is what reads as machine-written.
          */}
        The City Planning and Development Office&rsquo;s proposed zoning map for 2018&ndash;2027.
        CPDO confirms the classifications and overlays that apply to your exact location when
        it reviews your zoning clearance.
      </p>

      {mapPath !== null && !broken && (
        <a
          href={mapPath}
          target="_blank"
          rel="noreferrer"
          className="mt-3 block overflow-hidden rounded-xl border border-line focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal"
        >
          {/*
            * Cropped to the map panel, not the whole sheet.
            *
            * The supplied sheet is 1825x1243 and spends its right-hand quarter
            * on the city seal, the scale bar and a legend printed at about 8pt.
            * Shown whole in a card this wide, the map itself lands around 300px
            * across and the legend is unreadable anyway — so the width goes to
            * the part the applicant can actually use. `object-cover` +
            * `object-left` against the map panel's own aspect ratio does it
            * without a second cropped copy of every file to keep in step.
            *
            * Nothing is hidden by this: the link opens the full sheet, legend
            * and all, and the readable version of that legend is the list below
            * — which is the same 19 classifications, from the same source.
            */}
          <span className="block aspect-[1305/1220]">
            <img
              src={mapPath}
              /*
               * A real alt, not aria-hidden: for a screen-reader user this image
               * is the only thing on the card they cannot get any other way, and
               * the alt has to say what it is AND that the list below carries the
               * readable version. Never Color Alone applies to a whole map as
               * much as to a chart series.
               */
              alt={`Official zoning map of Barangay ${barangay.name}. The classifications it shows are listed below.`}
              width={1825}
              height={1243}
              loading="lazy"
              onError={() => setBroken(true)}
              className="h-full w-full object-cover object-left"
            />
          </span>
        </a>
      )}
      {mapPath !== null && !broken && (
        <p className="mt-1.5 text-xs text-ink-secondary">
          Opens the full sheet, with CPDO&rsquo;s own legend and scale, in a new tab.
        </p>
      )}

      {(mapPath === null || broken) && (
        /*
         * A missing sheet must not read as "your barangay has no zoning". It
         * says the image is missing and leaves the classification list standing,
         * because the list is the part the applicant can act on.
         */
        <p className="mt-3 rounded-xl border border-line bg-canvas px-4 py-3 text-sm text-ink-secondary">
          The map image for this barangay isn&rsquo;t available right now.
        </p>
      )}

      {zones.length > 0 && (
        <div className="mt-4">
          <h4 id="barangay-zone-list-heading" className="text-[13px] font-semibold text-ink">
            {/*
             * "Name both numbers." "Zones on this map" would be vague about
             * whose map; naming the barangay ties the count to the sheet above.
             */}
            {zones.length === 1
              ? `The one classification drawn on Barangay ${barangay.name}`
              : `The ${zones.length} classifications drawn on Barangay ${barangay.name}`}
          </h4>
          {/*
            * Named by its own heading. There are two lists on this card now and
            * "repeated controls need distinct accessible names" applies to them
            * as much as to buttons: a screen-reader user landing on the second
            * list has to be told it is the overlays and not more of the zones.
            */}
          <ul aria-labelledby="barangay-zone-list-heading" className="mt-2 flex flex-wrap gap-2">
            {zones.map((z) => (
              <li
                key={z.code}
                className="inline-flex items-center gap-2 rounded-full border border-line bg-canvas px-3 py-1 text-xs font-medium text-ink"
              >
                {/*
                 * The swatch matches the sheet so a reader can find the zone on
                 * the image. It is decoration and is marked as such: the name
                 * beside it is the content, which is Never Color Alone — and it
                 * is why no zone is ever distinguished by colour alone here.
                 */}
                <span
                  aria-hidden="true"
                  className="h-3 w-3 shrink-0 rounded-sm border border-line"
                  style={z.legend_color !== null ? { backgroundColor: z.legend_color } : undefined}
                />
                {z.name}
              </li>
            ))}
          </ul>
        </div>
      )}

      {zones.length === 0 && (
        <p className="mt-4 text-sm text-ink-secondary">
          We haven&rsquo;t recorded the classifications for this barangay yet.
        </p>
      )}

      {overlays.length > 0 && (
        /*
         * Set apart from the classification list on four counts at once, because
         * an applicant mistaking an overlay for a base zone is the failure this
         * block exists to avoid: it sits in its own bordered panel, under its
         * own heading, as full-width rows rather than pills, each row carrying
         * the word "Overlay" as text. Never Color Alone — remove every colour
         * here and the two lists are still plainly different things.
         *
         * Royal, not red. #bd0000 is for errors and destructive actions; a
         * designation made by ordinance is neither, and a Flood overlay printed
         * in red would read as a warning about this applicant's lot, which is
         * precisely the verdict we cannot make.
         */
        <div className="mt-5 rounded-xl border border-royal/30 bg-royal-tint px-4 py-3">
          <h4 id="barangay-overlay-list-heading" className="text-[13px] font-semibold text-ink">
            {overlays.length === 1
              ? `The one overlay zone over Barangay ${barangay.name}`
              : `The ${overlays.length} overlay zones over Barangay ${barangay.name}`}
          </h4>
          <p className="mt-1 text-xs leading-relaxed text-ink-secondary">
            City Ordinance No. 24-2018 lays these over parts of the barangay, adding a layer
            of rules on top of the zone beneath.
          </p>
          <ul aria-labelledby="barangay-overlay-list-heading" className="mt-2.5 space-y-2">
            {overlays.map((o) => (
              <li
                key={o.code}
                className="rounded-lg border border-dashed border-royal/40 bg-white px-3 py-2"
              >
                <p className="text-xs font-semibold text-royal">{o.name}</p>
                {o.description !== null && (
                  <p className="mt-0.5 text-xs leading-relaxed text-ink-secondary">
                    {o.description}
                  </p>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}
