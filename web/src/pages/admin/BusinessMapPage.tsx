import { useEffect, useMemo, useState } from 'react'
import { LayersControl, MapContainer, Marker, Polygon, Popup, TileLayer, useMap } from 'react-leaflet'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

import { api } from '../../lib/api'
import { useAsync } from '../../lib/useAsync'
import { formatDate } from '../../lib/format'
import { BARANGAY_POLYGONS, MALABON_OUTLINE } from '../../lib/malabonGeo.data'
import { BARANGAY_TOLERANCE_M, metresFromBarangay, withinMalabon } from '../../lib/malabonGeo'
import { ErrorState, SkeletonList } from '../../components/ui/primitives'
import { FilterPills, PageTitle, ProtoCard } from '../../components/ui/Proto'
import { DENSE_PAGE, dInput, dTable, dTh, dTheadRow } from './dense'

/*
 * THE BUSINESS MAP — issue #104, "GIS mapping showing all businesses and
 * whether their permit is still active".
 *
 * One marker per business, at the pin its owner dropped in the apply wizard,
 * shaped and coloured by the state of its Mayor's Permit today.
 *
 * ── What was measured before any of this was designed ────────────────────────
 *
 * Against the live register on 17 September 2026:
 *
 *   744  businesses on file (soft-deleted ones excluded)
 *   742  of them carry latitude/longitude — 99.7%
 *     2  do not, and are named as a shortfall rather than dropped in silence
 *   464  hold a Mayor's Permit that is live today
 *   144  held one that has lapsed
 *   134  have never held one
 *
 * The brief warned that ~2,182 active permits would mean thousands of markers.
 * It does not: a permit is per business PER TYPE, and there are six types, so
 * 2,182 active certificates belong to a few hundred businesses. The map plots
 * BUSINESSES, and the ceiling is 742. That is the number the rendering decision
 * below was taken against, and it is why there is no clustering here.
 *
 * ── Why DOM markers and not a canvas ─────────────────────────────────────────
 *
 * The obvious performance answer at "thousands of markers" is Leaflet's canvas
 * renderer with CircleMarkers, which draws every point into one <canvas>. It
 * was rejected at this volume, and the reasons are worth keeping:
 *
 *   - Canvas can only draw circles here. CircleMarker is the only canvas-backed
 *     marker Leaflet has, so the three states could differ by fill and radius
 *     and by nothing else. DESIGN.md's Never Color Alone wants a difference
 *     that survives greyscale, and three silhouettes (disc / diamond / open
 *     ring) is a stronger answer than three circles.
 *   - A canvas has no DOM, so nothing on it can be asserted by a test or
 *     reached by assistive tech. The markers below are real elements.
 *   - Panning is cheap either way: Leaflet moves the whole marker pane with one
 *     CSS transform rather than repositioning each marker, so marker count does
 *     not cost anything per pan. Zoom is the operation that touches all of
 *     them, and 742 is well inside what that handles — see the figures below.
 *
 * Measured in Chromium at 1440x900 against a copy of the register holding 756
 * businesses, warm dev server, twice with agreeing results:
 *
 *   ~370 ms  from the API response to all 756 markers in the DOM
 *    ~30 ms  a zoom step — the operation that repositions every marker
 *    ~25 ms  a filter pill, which re-renders the whole marker layer
 *
 * A zoom at 30 ms is inside one frame's budget twice over, so canvas would be
 * buying nothing here. If this register ever reaches the tens of
 * thousands of businesses Malabon really has, the fix is NOT clustering (which
 * hides the one thing the screen is for — you cannot see whether a block is
 * lapsed if the block is one circle reading "148") but a bounding-box parameter
 * on the endpoint, so the browser only ever holds what the viewport covers.
 *
 * ── The pins do not agree with the barangays, and that is the data ───────────
 *
 * Running the shipped polygon set over all 742 pins:
 *
 *    70  sit inside their own declared barangay, or within the 150 m tolerance
 *   672  do not
 *   171  fall outside the city border altogether
 *
 * So roughly nine pins in ten disagree with the barangay their owner picked
 * from the dropdown. This predates the pin/barangay check in the wizard, which
 * now refuses a mismatch at entry — these rows were written before it existed.
 *
 * Three things could be done with that and only one of them is useful:
 *
 *   FILTER them out — no. It would hide 90% of the register behind a
 *   correctness rule the register never had to meet, and a map that silently
 *   omits most of the city is worse than a table.
 *
 *   FLAG each one — no, and this is the interesting one. A flag is only
 *   information when it marks a minority. Painting 672 of 742 markers as
 *   suspect makes "suspect" the default state of the map, which tells a reader
 *   nothing and buries the permit state the screen exists to show underneath a
 *   second warning colour.
 *
 *   SHOW them, state the number once, and let someone ASK for the subset — yes.
 *   That is what the Pin check control does. The default view is every pin,
 *   undecorated; the note under the map states how many agree; and a clerk
 *   working the backlog can narrow to the pins outside the city in one click.
 *
 * Which means some markers on this map are visibly in the wrong barangay, and
 * 171 are not in Malabon at all. That is a true picture of what is on file.
 */

/** One business, as the map endpoint returns it. */
interface MapBusiness {
  id: number
  name: string
  latitude: number
  longitude: number
  /** The barangay the owner DECLARED, which the pin may or may not be in. */
  barangay: string | null
  state: PermitState
  permit_number: string | null
  valid_until: string | null
}

interface MapMeta {
  plotted: number
  businesses_total: number
  unmapped: number
  counts: Record<PermitState, number>
  as_of: string
}

type PermitState = 'active' | 'lapsed' | 'none'

/*
 * The client asked for one thing — is the permit still active — so that
 * question owns the strongest visual channel on the screen, and it is answered
 * three ways rather than two. "Never held one" is 134 businesses here, and
 * folding it into "lapsed" would tell a clerk a certificate ran out when none
 * was ever issued. Those are different jobs: one is a renewal to chase, the
 * other is a first filing that never finished.
 *
 * ── Never Color Alone (DESIGN.md) ────────────────────────────────────────────
 *
 * Each state is a different SILHOUETTE, not a different hue of the same dot:
 *
 *   active  a filled disc          — solid, round
 *   lapsed  a filled diamond       — solid, pointed
 *   none    an open ring           — hollow
 *
 * Print the map in greyscale, or hand it to the ~8% of men with a red/green
 * deficiency, and the three are still distinguishable: round-solid,
 * pointed-solid, empty. Colour repeats the message; it does not carry it. The
 * legend states the same three in words with their counts, and each marker's
 * popup says the state in words too.
 *
 * ── The colours ──────────────────────────────────────────────────────────────
 *
 * Royal #3242ca for active: DESIGN.md's primary, the same blue the city border
 * and the wizard's pin already use. Amber for lapsed, NOT #bd0000 — Red Means
 * Stop, and a permit that ran out is a normal state of a register, not an
 * error. Grey for never-held, because it is an absence and should recede.
 *
 * Every glyph carries a white outer stroke. It is not decoration: the map has a
 * satellite base layer, and a dark amber diamond on a dark roof is invisible
 * without one.
 */
const STATES: Record<PermitState, { label: string; description: string; svg: string }> = {
  active: {
    label: 'Permit active',
    description: 'Mayor’s Permit is inside its term today.',
    svg: '<circle cx="9" cy="9" r="5.5" fill="#3242ca" stroke="#fff" stroke-width="2.5" />',
  },
  lapsed: {
    label: 'Permit lapsed',
    description: 'Held a Mayor’s Permit; its term has run out.',
    svg: '<path d="M9 2.5 15.5 9 9 15.5 2.5 9Z" fill="#f2a33c" stroke="#8a4a06" stroke-width="1.75" stroke-linejoin="round" />',
  },
  none: {
    label: 'No permit on file',
    description: 'No Mayor’s Permit has ever been issued to this business.',
    svg: '<circle cx="9" cy="9" r="5" fill="#fff" stroke="#5b6472" stroke-width="2.25" />',
  },
}

const STATE_ORDER: PermitState[] = ['active', 'lapsed', 'none']

/*
 * One glyph definition, drawn in two places.
 *
 * The legend is React and the marker is a Leaflet divIcon built from an HTML
 * string, so neither can render the other's markup directly. Keeping the SVG
 * *children* as the single string above and wrapping it here is what stops the
 * two from drifting — a legend that disagrees with the map is worse than no
 * legend, because it is believed.
 */
function glyphHtml(state: PermitState, size: number): string {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 18 18" aria-hidden="true">${STATES[state].svg}</svg>`
}

const MARKER_PX = 18

/*
 * Icons are built once per state, not once per business.
 *
 * L.divIcon is a description of an icon, not an element — Leaflet clones its
 * html for every marker that uses it — so three instances cover 742 markers.
 * Building one per row instead was the first draft and it allocated 742 icon
 * objects on every re-render of the filter pills.
 */
const MARKER_ICONS: Record<PermitState, L.DivIcon> = {
  active: divIconFor('active'),
  lapsed: divIconFor('lapsed'),
  none: divIconFor('none'),
}

function divIconFor(state: PermitState): L.DivIcon {
  return L.divIcon({
    html: glyphHtml(state, MARKER_PX),
    /*
     * `className` is emptied deliberately. Leaflet's default is
     * 'leaflet-div-icon', which paints a white box with a blue border behind
     * the html — fine for its own demo pin, a square around every one of our
     * glyphs here.
     *
     * The state is in the class name so a test can count markers of one kind,
     * and `biztrack-map-pin` is the handle for all of them.
     */
    className: `biztrack-map-pin biztrack-map-pin--${state}`,
    iconSize: [MARKER_PX, MARKER_PX],
    iconAnchor: [MARKER_PX / 2, MARKER_PX / 2],
    popupAnchor: [0, -MARKER_PX / 2],
  })
}

/*
 * The map opens fitted to the city, not at a fixed centre and zoom.
 *
 * The wizard's picker centres on City Hall at zoom 13 because it is asking for
 * ONE pin and the applicant needs street detail. That is the wrong opening for
 * this screen: at zoom 13 on a 1440px page, Malabon occupies the top-left
 * quarter of the frame and the rest is Manila Bay, Caloocan and Quezon City.
 * Two thirds of the pixels a reader is looking at would be places this map has
 * nothing to say about.
 *
 * Derived from MALABON_OUTLINE rather than written as four numbers, so it stays
 * correct if the boundary asset is ever refined. Computed once at module load —
 * the outline is a constant.
 */
const CITY_BOUNDS: [[number, number], [number, number]] = (() => {
  const lats = MALABON_OUTLINE.map(([, lat]) => lat)
  const lngs = MALABON_OUTLINE.map(([lng]) => lng)
  return [
    [Math.min(...lats), Math.min(...lngs)],
    [Math.max(...lats), Math.max(...lngs)],
  ]
})()

/*
 * The city border and the barangay seams, copied in intent from MapPicker.
 *
 * Both unfilled and both non-interactive: a fill would tint every street and
 * fight the tiles, and an interactive polygon covering the whole map would
 * swallow the clicks that open marker popups. The barangay line is dashed so it
 * reads as a different KIND of line from the city border without depending on
 * weight alone.
 */
const CITY_BORDER: L.PathOptions = { color: '#3242ca', weight: 2.5, opacity: 0.9, fill: false }
const BARANGAY_LINE: L.PathOptions = {
  color: '#3242ca',
  weight: 1,
  opacity: 0.3,
  dashArray: '3 3',
  fill: false,
}

/** GeoJSON stores [lng, lat]; Leaflet wants [lat, lng]. Converted here only. */
function toLatLngs(ring: readonly (readonly [number, number])[]): [number, number][] {
  return ring.map(([lng, lat]) => [lat, lng])
}

/*
 * Names the map on the element that carries it.
 *
 * Same reasoning as MapPicker's ContainerName, and the same workaround:
 * react-leaflet 4.2.1 forwards only className/id/placeholder/style from
 * MapContainer's props to the div it renders, so an aria-label passed as a prop
 * is dropped in silence and Leaflet's tabindex="0" container announces as
 * nothing (WCAG 2.1 AA 4.1.2). Writing the attributes onto map.getContainer()
 * goes around the prop filter rather than trusting it.
 *
 * role="region" and not "application": there is no keyboard way to operate the
 * markers (see the note on `keyboard: false` below), so claiming an application
 * role would put a screen reader into forms mode for a widget it cannot drive.
 * The label carries the counts because for a non-sighted reader that summary IS
 * the map; the table below repeats it as real markup.
 */
function ContainerName({ label }: { label: string }) {
  const map = useMap()
  useEffect(() => {
    const el = map.getContainer()
    el.setAttribute('role', 'region')
    el.setAttribute('aria-label', label)
  }, [map, label])
  return null
}

/** Whether a pin agrees with the barangay its owner declared. */
type PinCheck = 'all' | 'agrees' | 'disagrees' | 'off-city'

const PIN_CHECKS: { value: PinCheck; label: string }[] = [
  { value: 'all', label: 'All pins' },
  { value: 'agrees', label: 'Pin matches its barangay' },
  { value: 'disagrees', label: 'Pin in a different barangay' },
  { value: 'off-city', label: 'Pin outside Malabon' },
]

/*
 * The verdict for one pin, using the same polygons and the same 150 m tolerance
 * the apply wizard refuses a new pin against.
 *
 * `checkPin` in malabonGeo.ts returns exactly this judgement but collapses
 * "outside the city" and "wrong barangay" into one verdict union; here they are
 * separate filters, because they are separate problems. A pin in the wrong
 * barangay is a data-entry slip worth correcting at leisure; a pin in Caloocan
 * is a business whose location this system does not actually know.
 *
 * `metresFromBarangay` returns null when the declared barangay is not in the
 * polygon set. That counts as agreeing, for the reason checkPin gives: a
 * bookkeeping gap between a seeded table and a shipped asset is OURS, and
 * reporting it as the business's problem would be the wrong failure.
 */
function pinVerdict(row: MapBusiness): Exclude<PinCheck, 'all'> {
  if (!withinMalabon(row.latitude, row.longitude)) return 'off-city'
  if (row.barangay === null) return 'agrees'
  const metres = metresFromBarangay(row.latitude, row.longitude, row.barangay)
  if (metres === null || metres <= BARANGAY_TOLERANCE_M) return 'agrees'
  return 'disagrees'
}

export function BusinessMapPage() {
  const { data, loading, error, reload } = useAsync(
    () => api.get<{ data: MapBusiness[]; meta: MapMeta }>('/admin/business-map').then((r) => r.data),
    [],
  )

  const [state, setState] = useState<PermitState | 'all'>('all')
  const [pinCheck, setPinCheck] = useState<PinCheck>('all')

  const rows = useMemo(() => data?.data ?? [], [data])
  const meta = data?.meta ?? null

  /*
   * The pin verdict for all 742 rows, computed once and reused by both the
   * filter and its counts.
   *
   * It is 742 point-in-polygon tests against 21 barangays plus a
   * distance-to-ring pass for the ones that miss — about 15 ms in the browser,
   * and it must not run on every keystroke of the filter pills. Keyed on `rows`
   * so it recomputes only when the fetch returns.
   */
  const verdicts = useMemo(() => {
    const map = new Map<number, Exclude<PinCheck, 'all'>>()
    for (const row of rows) map.set(row.id, pinVerdict(row))
    return map
  }, [rows])

  const pinCounts = useMemo(() => {
    const counts = { agrees: 0, disagrees: 0, 'off-city': 0 }
    for (const verdict of verdicts.values()) counts[verdict] += 1
    return counts
  }, [verdicts])

  const visible = useMemo(
    () =>
      rows.filter(
        (row) =>
          (state === 'all' || row.state === state) &&
          (pinCheck === 'all' || verdicts.get(row.id) === pinCheck),
      ),
    [rows, state, pinCheck, verdicts],
  )

  if (loading) return <SkeletonList rows={6} />
  if (error || meta === null) return <ErrorState error={error} onRetry={reload} />

  const statePills = [
    { value: 'all' as const, label: `All ${meta.plotted}` },
    ...STATE_ORDER.map((s) => ({ value: s, label: `${STATES[s].label} ${meta.counts[s]}` })),
  ]

  return (
    <div {...DENSE_PAGE}>
      {/*
        * Compact layout (client, 2026-09: "every detail fits without
        * scrolling"). The filters sit in the title row, and at desktop width
        * the legend table and the pin note sit in a column beside the map
        * instead of below it, with the map taking the rest of the viewport's
        * height. Below lg the column stacks under the map as before.
        */}
      <PageTitle
        compact
        right={
          <span className="flex flex-wrap items-center gap-x-2 gap-y-2">
            <FilterPills value={state} onChange={setState} options={statePills} />

            {/*
              * A select rather than a second row of pills. The permit state is the
              * question this screen answers and owns the pills; the pin check is a
              * data-quality lens over it, and giving them the same control would
              * say they matter equally.
              */}
            <label className="flex items-center gap-1.5 text-xs font-semibold text-ink-muted">
              <span>Pin check</span>
              <select
                value={pinCheck}
                onChange={(e) => setPinCheck(e.target.value as PinCheck)}
                className={`${dInput} w-52`}
              >
                {PIN_CHECKS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.value === 'all'
                      ? `${option.label} ${meta.plotted}`
                      : `${option.label} ${pinCounts[option.value]}`}
                  </option>
                ))}
              </select>
            </label>
          </span>
        }
      >
        Business Map
      </PageTitle>

      {/*
        * One line, and it names both numbers (AGENTS.md §6.4). "742 businesses"
        * on its own is the one figure a reader cannot check — it hides whether
        * anything was left out. The shortfall is second because it is the
        * caveat, and it is stated even at 2 rows: the day a wizard change stops
        * writing coordinates, this line is where it shows up.
        */}
      <p className="mb-2 text-[13px] text-ink-secondary">
        {meta.plotted} of the {meta.businesses_total} businesses on file carry a map pin, shown here
        by the state of their Mayor&rsquo;s Permit on {formatDate(meta.as_of)}.
        {meta.unmapped > 0 && (
          <>
            {' '}
            {meta.unmapped === 1 ? 'One has' : `${meta.unmapped} have`} no pin and{' '}
            {meta.unmapped === 1 ? 'is' : 'are'} not on the map.
          </>
        )}
      </p>

      <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_26rem]">
        {/*
          * 8.5rem is the shell's top and bottom margin plus the title row and
          * the one-line summary above; what is left of a 900px screen goes to
          * the map. The floor keeps it usable on a short laptop screen, where
          * the page then scrolls rather than the map shrinking to a strip.
          */}
        <ProtoCard className="overflow-hidden rounded-xl p-0">
          <div className="h-[480px] overflow-hidden lg:h-[max(28rem,calc(100dvh-8.5rem))]">
            <MapContainer
              bounds={CITY_BOUNDS}
              boundsOptions={{ padding: [16, 16] }}
              /*
               * Scroll wheel off, same as the wizard's picker. Below lg this
               * screen stacks and scrolls, and a map that swallows the wheel
               * traps a reader who was trying to reach the table under it. The
               * zoom control and pinch both still work.
               */
              scrollWheelZoom={false}
              style={{ height: '100%', width: '100%' }}
            >
              <ContainerName
                label={`Map of Malabon showing ${visible.length} businesses: ${STATE_ORDER.map(
                  (s) => `${meta.counts[s]} ${STATES[s].label.toLowerCase()}`,
                ).join(', ')}. The same figures are in the table beside the map.`}
              />
              {/*
                * Street and satellite, exactly as the wizard's picker offers
                * them. Streets name things and are the lighter download, so they
                * stay the default; the imagery is what lets someone recognise a
                * block of unnamed alleys, which is most of interior Malabon.
                */}
              <LayersControl position="topright">
                <LayersControl.BaseLayer checked name="Street map">
                  <TileLayer
                    attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                    url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                  />
                </LayersControl.BaseLayer>
                <LayersControl.BaseLayer name="Satellite">
                  {/*
                    * Esri World Imagery. Note {z}/{y}/{x} — Esri puts row before
                    * column, the opposite of the OSM line above, and swapping
                    * them yields a map of the wrong hemisphere rather than an
                    * error. Attribution is a licence condition.
                    */}
                  <TileLayer
                    attribution="Imagery &copy; Esri, Maxar, Earthstar Geographics, and the GIS User Community"
                    url="https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}"
                    maxZoom={19}
                  />
                </LayersControl.BaseLayer>
              </LayersControl>

              <Polygon positions={toLatLngs(MALABON_OUTLINE)} interactive={false} pathOptions={CITY_BORDER} />
              {BARANGAY_POLYGONS.map((b) => (
                <Polygon
                  key={b.psgc}
                  positions={b.rings.map(toLatLngs)}
                  interactive={false}
                  pathOptions={BARANGAY_LINE}
                />
              ))}

              {visible.map((row) => (
                <Marker
                  key={row.id}
                  position={[row.latitude, row.longitude]}
                  icon={MARKER_ICONS[row.state]}
                  /*
                   * NOT keyboard-focusable, and that is a considered trade rather
                   * than an oversight.
                   *
                   * Leaflet's default puts every marker in the tab order. At 742
                   * markers that is 742 stops between the filter and the table
                   * below — a keyboard user would have to hold Tab for a minute
                   * to get past the map, and each stop would announce a business
                   * name with no way to tell where it is. The region label and
                   * the table carry the content instead.
                   *
                   * The gap this leaves is real and worth naming: there is no
                   * keyboard route to an individual business from this screen.
                   * The answer when one is wanted is a searchable list beside the
                   * map that drives the same selection, not switching this flag
                   * back on.
                   */
                  keyboard={false}
                  title={`${row.name} — ${STATES[row.state].label}`}
                >
                  <Popup>
                    <span className="block text-sm font-semibold text-ink">{row.name}</span>
                    {/*
                      * The state in words, next to the same glyph the marker
                      * uses. The popup is where a reader confirms what the shape
                      * they just clicked actually meant, so repeating the glyph
                      * here is what teaches the legend.
                      */}
                    <span className="mt-1 flex items-center gap-1.5 text-sm text-ink-secondary">
                      <span
                        className="inline-flex"
                        aria-hidden="true"
                        dangerouslySetInnerHTML={{ __html: glyphHtml(row.state, 14) }}
                      />
                      {STATES[row.state].label}
                    </span>
                    {/*
                      * A dash, never a zero or an invented date, where a figure
                      * genuinely has no value (AGENTS.md §6.4). A business that
                      * never held a permit has no permit number and no expiry,
                      * and printing "—" says so where "n/a" would read as a
                      * lookup that failed.
                      */}
                    <span className="mt-1 block text-sm text-ink-secondary tnum">
                      {row.permit_number ?? '—'}
                      {row.valid_until !== null && (
                        <>
                          {' · '}
                          {row.state === 'active' ? 'valid to' : 'lapsed'} {formatDate(row.valid_until)}
                        </>
                      )}
                    </span>
                    <span className="mt-1 block text-sm text-ink-muted">
                      Barangay {row.barangay ?? '—'}
                      {verdicts.get(row.id) === 'off-city' && ' · pin is outside Malabon'}
                      {verdicts.get(row.id) === 'disagrees' && ' · pin is in a different barangay'}
                    </span>
                  </Popup>
                </Marker>
              ))}
            </MapContainer>
          </div>
        </ProtoCard>

        {/*
          * The map's numbers as a real table.
          *
          * Same rule the charts follow (AGENTS.md §6.2, components/charts/
          * ChartFrame.tsx): an SVG or a map is nothing to a screen reader, so
          * whatever it shows is also rendered as markup that can be read. It is
          * not a duplicate for its own sake — it is the only form of this screen
          * that exists for a non-sighted reader, and it doubles as the legend for
          * everyone else, which is why the glyphs sit in it rather than floating
          * over a corner of the map.
          */}
        <div className="flex flex-col gap-3">
          <ProtoCard className="overflow-hidden rounded-xl">
            <table className={dTable}>
              <caption className="px-3 pt-2 pb-1 text-left text-[13px] font-semibold text-ink">
                What the markers mean
              </caption>
              <thead>
                <tr className={dTheadRow}>
                  <th scope="col" className={dTh}>
                    Marker
                  </th>
                  <th scope="col" className={dTh}>
                    Meaning
                  </th>
                  <th scope="col" className={`${dTh} text-right`}>
                    Businesses
                  </th>
                </tr>
              </thead>
              <tbody>
                {STATE_ORDER.map((s) => (
                  <tr key={s} className="border-t border-line align-top">
                    <th scope="row" className="whitespace-nowrap py-1.5 pl-3 pr-2 font-medium text-ink">
                      <span className="flex items-center gap-2">
                        <span
                          className="inline-flex"
                          aria-hidden="true"
                          dangerouslySetInnerHTML={{ __html: glyphHtml(s, 18) }}
                        />
                        {STATES[s].label}
                      </span>
                    </th>
                    <td className="px-2 py-1.5 text-ink-secondary">{STATES[s].description}</td>
                    <td className="px-3 py-1.5 text-right text-ink tnum">{meta.counts[s]}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </ProtoCard>

          {/*
            * The pin/barangay shortfall, stated once, in plain words, with both
            * numbers. See the long note at the top of this file for why it is a
            * sentence here rather than a warning painted on 672 markers.
            *
            * Deliberately not styled as an alert: no red, no icon, no tinted panel.
            * It is a fact about historical data, not something that just went
            * wrong, and dressing it as an error would put a permanent alarm on a
            * screen somebody has to look at every day.
            */}
          <p className="text-[13px] text-ink-secondary">
            {pinCounts.agrees} of the {meta.plotted} pins sit in the barangay their owner declared, and{' '}
            {pinCounts['off-city']} fall outside Malabon altogether. Filings made before the apply
            wizard began checking the pin against the barangay were never held to it, so those markers
            are drawn where the register says they are. Use Pin check above to work through them.
          </p>
        </div>
      </div>
    </div>
  )
}
