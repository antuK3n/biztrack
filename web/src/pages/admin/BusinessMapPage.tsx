import { useEffect, useMemo, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { LayersControl, MapContainer, Marker, Polygon, Popup, TileLayer, useMap } from 'react-leaflet'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

import { api, toApiError } from '../../lib/api'
import { permits } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { formatDate } from '../../lib/format'
import { BARANGAY_POLYGONS, MALABON_OUTLINE } from '../../lib/malabonGeo.data'
import { BARANGAY_TOLERANCE_M, metresFromBarangay, withinMalabon } from '../../lib/malabonGeo'
import { ErrorState, SkeletonList } from '../../components/ui/primitives'
import { FilterPills, PageTitle, ProtoCard } from '../../components/ui/Proto'

/*
 * THE BUSINESS MAP — issue #104, "GIS mapping showing all businesses and
 * whether their permit is still active".
 *
 * One marker per business, at the pin its owner dropped in the apply wizard,
 * shaped and coloured by the state of its Mayor's Permit today.
 *
 * Two homes, one component. It is the Map view on the Permits page, for BPLO
 * and the super admin (checklist item 16), and it is still the super admin's
 * own Business Map screen. `embedded` drops the page title when it sits under
 * the Permits heading.
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
 *     marker Leaflet has, so the states could differ by fill and radius
 *     and by nothing else. DESIGN.md's Never Color Alone wants a difference
 *     that survives greyscale, and five silhouettes (disc, diamond, square,
 *     cross, ring) are a stronger answer than five circles.
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
  /** The governing permit's id, for opening its certificate; null with no permit. */
  permit_id: number | null
  permit_number: string | null
  valid_until: string | null
}

interface MapMeta {
  plotted: number
  businesses_total: number
  unmapped: number
  counts: Record<PermitState, number>
  as_of: string
  /** The server's ceiling bit — see BusinessMapController::MAX_POINTS. */
  truncated: boolean
  max_points: number
  /**
   * The certificate the colours describe: the Mayor's Permit for BPLO and the
   * super admin, the office's own certificate for a clearance office.
   */
  permit_type?: { code: string; name: string } | null
}

type PermitState = 'active' | 'expired' | 'suspended' | 'revoked' | 'retired' | 'rejected' | 'none'

/*
 * The client asked "is the permit still active", and checklist item 16 asks
 * for the answer in five parts rather than two, because each is a different
 * job: active needs nothing, expired is a renewal to chase, suspended is a
 * refused clearance to settle or a suspension to lift, revoked is enforcement,
 * and "never held one" is a first filing that never finished. It was three
 * states — active / lapsed / none — until a permit could be revoked; "lapsed"
 * then folded three different desks into one word.
 *
 * ── Never Color Alone (DESIGN.md) ────────────────────────────────────────────
 *
 * Each state is a different SILHOUETTE, not a different hue of the same dot:
 *
 *   active     a filled disc              — solid, round
 *   expired    a filled diamond           — solid, pointed
 *   suspended  a square with pause bars   — solid, square, marked
 *   revoked    a cross                    — no body at all, two strokes
 *   none       an open ring               — hollow
 *
 * Print the map in greyscale, or hand it to the ~8% of men with a red/green
 * deficiency, and the five are still distinguishable. Colour repeats the
 * message; it does not carry it. The legend states the same five in words with
 * their counts, and each marker's popup says the state in words too.
 *
 * ── The colours ──────────────────────────────────────────────────────────────
 *
 * Royal #3242ca for active: DESIGN.md's primary, the same blue the city border
 * and the wizard's pin already use. Amber for expired and the register's own
 * suspended purple (--color-s-purple, the tone its status chip uses).
 *
 * Revoked is near-black ink, NOT red. It is the one state here that is an
 * enforcement act, and red would be the obvious reach — but Red Means Stop
 * keeps #bd0000 off data values, and the one documented exception is the
 * Analytics compliance map, which DESIGN.md says is not a precedent. The cross
 * is the strongest shape on the map, which is what earns it attention instead.
 * Grey for never-held, because it is an absence and should recede.
 *
 * Every glyph carries a white outer stroke. It is not decoration: the map has a
 * satellite base layer, and a dark glyph on a dark roof is invisible without
 * one.
 */
/*
 * The descriptions name the certificate the map is coloured by — the Mayor's
 * Permit, or an office's own certificate on that office's map [client, 4
 * October 2026: "maglagay din ng maps tulad sa bplo"].
 */
const STATES: Record<PermitState, { label: string; description: (permit: string, mayors: boolean) => string; svg: string }> = {
  active: {
    label: 'Permit active',
    description: (p) => `${p} is inside its term today.`,
    svg: '<circle cx="9" cy="9" r="5.5" fill="#3242ca" stroke="#fff" stroke-width="2.5" />',
  },
  expired: {
    label: 'Permit expired',
    description: (p) => `Held a ${p}; its term has run out.`,
    svg: '<path d="M9 2.5 15.5 9 9 15.5 2.5 9Z" fill="#f2a33c" stroke="#8a4a06" stroke-width="1.75" stroke-linejoin="round" />',
  },
  suspended: {
    label: 'Permit suspended',
    description: (_p, mayors) =>
      mayors ? 'Inside its term, but suspended because another office refused a permit.' : 'Inside its term, but suspended.',
    svg: '<rect x="3" y="3" width="12" height="12" rx="1.5" fill="#7a4bd0" stroke="#fff" stroke-width="2" /><path d="M7.25 6.5v5M10.75 6.5v5" stroke="#fff" stroke-width="1.75" stroke-linecap="round" />',
  },
  revoked: {
    label: 'Permit revoked',
    description: (p) => `The issuing office revoked the ${p}. The business may not rely on it.`,
    svg: '<path d="M4.5 4.5 13.5 13.5M13.5 4.5 4.5 13.5" stroke="#fff" stroke-width="6" stroke-linecap="round" /><path d="M4.5 4.5 13.5 13.5M13.5 4.5 4.5 13.5" stroke="#14171d" stroke-width="3" stroke-linecap="round" />',
  },
  // Added with Change status [client, 5 October 2026].
  retired: {
    label: 'Permit retired',
    description: (p) => `The ${p} was retired — the business no longer operates under it.`,
    svg: '<rect x="3.5" y="3.5" width="11" height="11" rx="2" fill="#9aa1ad" stroke="#fff" stroke-width="2" /><path d="M6 9h6" stroke="#fff" stroke-width="2" stroke-linecap="round" />',
  },
  rejected: {
    label: 'Permit rejected',
    description: (p) => `The issuing office rejected the ${p}. It is not valid.`,
    svg: '<path d="M9 2.5 16 15.5H2Z" fill="#c11212" stroke="#fff" stroke-width="2" stroke-linejoin="round" /><path d="M9 7v4" stroke="#fff" stroke-width="1.75" stroke-linecap="round" /><circle cx="9" cy="13" r="1" fill="#fff" />',
  },
  none: {
    label: 'No permit on file',
    description: (p) => `No ${p} has ever been issued to this business.`,
    svg: '<circle cx="9" cy="9" r="5" fill="#fff" stroke="#5b6472" stroke-width="2.25" />',
  },
}

const STATE_ORDER: PermitState[] = ['active', 'expired', 'suspended', 'revoked', 'retired', 'rejected', 'none']

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
 * html for every marker that uses it — so five instances cover 742 markers.
 * Building one per row instead was the first draft and it allocated 742 icon
 * objects on every re-render of the filter pills.
 */
const MARKER_ICONS: Record<PermitState, L.DivIcon> = {
  active: divIconFor('active'),
  expired: divIconFor('expired'),
  suspended: divIconFor('suspended'),
  revoked: divIconFor('revoked'),
  retired: divIconFor('retired'),
  rejected: divIconFor('rejected'),
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

/** The value of the barangay select that means "every barangay". */
const ALL_BARANGAYS = ''

export function BusinessMapPage({
  embedded = false,
  onFindInRegister,
}: {
  /** Drawn inside the Permits page, under its heading, rather than as a page of its own. */
  embedded?: boolean
  /**
   * Where "Find in register" goes. The Permits page passes a handler that
   * switches its own view; the standalone screen links to the Permits page.
   */
  onFindInRegister?: (permitNumber: string) => void
} = {}) {
  const { data, loading, error, reload } = useAsync(
    () => api.get<{ data: MapBusiness[]; meta: MapMeta }>('/admin/business-map').then((r) => r.data),
    [],
  )

  const [state, setState] = useState<PermitState | 'all'>('all')
  const [pinCheck, setPinCheck] = useState<PinCheck>('all')
  const [barangay, setBarangay] = useState(ALL_BARANGAYS)
  const [viewError, setViewError] = useState<string | null>(null)

  /*
   * The Permits page for this site. The SPA is two sites on one origin — the
   * LGU one under /staff and the admin one at the root — and a link into the
   * other one lands where this session's token is not sent.
   */
  const { pathname } = useLocation()
  const registerPath = pathname.startsWith('/staff') ? '/staff/admin/permits' : '/admin/permits'

  /** Open the certificate itself. The tab opens inside the click (popup blocker). */
  async function viewCertificate(permitId: number) {
    const tab = window.open('', '_blank')
    setViewError(null)
    try {
      await permits.viewPdf(permitId, tab)
    } catch (err) {
      tab?.close()
      setViewError(toApiError(err).message)
    }
  }

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

  /*
   * The barangays to offer, from the rows themselves — the barangay each owner
   * DECLARED. Read off the data rather than the polygon set, so a barangay the
   * register names and the shipped asset spells differently still appears, and
   * one with no business on file is not offered as an empty choice.
   */
  const barangays = useMemo(
    () =>
      [...new Set(rows.map((r) => r.barangay).filter((b): b is string => b !== null))].sort((a, b) =>
        a.localeCompare(b),
      ),
    [rows],
  )

  const visible = useMemo(
    () =>
      rows.filter(
        (row) =>
          (state === 'all' || row.state === state) &&
          (barangay === ALL_BARANGAYS || row.barangay === barangay) &&
          (pinCheck === 'all' || verdicts.get(row.id) === pinCheck),
      ),
    [rows, state, barangay, pinCheck, verdicts],
  )

  if (loading) return <SkeletonList rows={6} />
  if (error || meta === null) return <ErrorState error={error} onRetry={reload} />

  const permitName = meta.permit_type?.name ?? 'Mayor’s Permit'
  const mayors = (meta.permit_type?.code ?? 'BUSINESS') === 'BUSINESS'
  // An office maps only businesses holding its certificate, so "never held one" cannot occur there.
  /*
   * Only the states this map can hold. An office maps only businesses holding
   * its certificate, so "never held one" cannot occur there; and a Mayor's
   * Permit is never Rejected, nor a clearance Retired.
   */
  const order = STATE_ORDER.filter((s) =>
    mayors ? s !== 'rejected' : s !== 'none' && s !== 'retired',
  )

  const statePills = [
    { value: 'all' as const, label: `All ${meta.plotted}` },
    ...order.map((s) => ({ value: s, label: `${STATES[s].label} ${meta.counts[s]}` })),
  ]

  return (
    <div>
      {!embedded && <PageTitle>Business Map</PageTitle>}

      {/*
        * One line, and it names both numbers (AGENTS.md §6.4). "742 businesses"
        * on its own is the one figure a reader cannot check — it hides whether
        * anything was left out. The shortfall is second because it is the
        * caveat, and it is stated even at 2 rows: the day a wizard change stops
        * writing coordinates, this line is where it shows up.
        */}
      <p className="mt-1 max-w-3xl text-sm text-ink-secondary">
        {meta.plotted} of the {meta.businesses_total} businesses {mayors ? 'on file' : `holding a ${permitName}`} carry a
        map pin, shown here by the state of their {permitName} on {formatDate(meta.as_of)}.
        {meta.unmapped > 0 && (
          <>
            {' '}
            {meta.unmapped === 1 ? 'One has' : `${meta.unmapped} have`} no pin and{' '}
            {meta.unmapped === 1 ? 'is' : 'are'} not on the map.
          </>
        )}
        {meta.truncated && (
          <>
            {' '}
            Only the first {meta.max_points.toLocaleString()} are drawn; the counts below cover
            them all.
          </>
        )}
      </p>

      <div className="mt-4 flex flex-wrap items-center gap-3">
        <FilterPills value={state} onChange={setState} options={statePills} />

        {/*
          * Barangay — checklist item 16's other filter. A select, because there
          * are 21 of them and pills would wrap into a wall.
          *
          * It filters on the barangay the owner DECLARED, the one the popup
          * names. The Pin check beside it is what finds the pins that sit
          * somewhere else.
          */}
        <label className="flex items-center gap-2 text-sm text-ink-secondary">
          <span>Barangay</span>
          <select
            value={barangay}
            onChange={(e) => setBarangay(e.target.value)}
            className="rounded-md border border-line-strong bg-white px-2.5 py-1.5 text-sm text-ink"
          >
            <option value={ALL_BARANGAYS}>All barangays</option>
            {barangays.map((name) => (
              <option key={name} value={name}>
                {name}
              </option>
            ))}
          </select>
        </label>

        {/*
          * A select rather than a second row of pills. The permit state is the
          * question this screen answers and owns the pills; the pin check is a
          * data-quality lens over it, and giving them the same control would
          * say they matter equally.
          */}
        <label className="flex items-center gap-2 text-sm text-ink-secondary">
          <span>Pin check</span>
          <select
            value={pinCheck}
            onChange={(e) => setPinCheck(e.target.value as PinCheck)}
            className="rounded-md border border-line-strong bg-white px-2.5 py-1.5 text-sm text-ink"
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
      </div>

      {viewError && (
        <p role="alert" className="mt-4 rounded-lg border border-s-red/30 bg-s-red-tint px-4 py-3 text-sm text-s-red">
          {viewError}
        </p>
      )}

      {/*
        * How many markers the filters left, both numbers named (AGENTS.md
        * §6.4) — with three filters set, "12 markers" says nothing about how
        * many were narrowed away.
        */}
      {(state !== 'all' || barangay !== ALL_BARANGAYS || pinCheck !== 'all') && (
        <p role="status" className="mt-3 text-sm text-ink-secondary">
          Showing {visible.length} of the {meta.plotted} businesses on the map
          {barangay !== ALL_BARANGAYS && ` in ${barangay}`}.
        </p>
      )}

      <ProtoCard className="mt-4 overflow-hidden rounded-xl p-0">
        <div className="overflow-hidden">
          <MapContainer
            bounds={CITY_BOUNDS}
            boundsOptions={{ padding: [16, 16] }}
            /*
             * Scroll wheel off, same as the wizard's picker. This screen sits
             * in a page that scrolls, and a map that swallows the wheel traps
             * a reader who was trying to reach the table underneath it. The
             * zoom control and pinch both still work.
             */
            scrollWheelZoom={false}
            style={{ height: 560, width: '100%' }}
          >
            <ContainerName
              label={`Map of Malabon showing ${visible.length} businesses: ${order.map(
                (s) => `${meta.counts[s]} ${STATES[s].label.toLowerCase()}`,
              ).join(', ')}. The same figures are in the table below the map.`}
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
                        {row.state === 'active'
                          ? 'valid to'
                          : row.state === 'expired'
                            ? 'expired'
                            : 'term to'}{' '}
                        {formatDate(row.valid_until)}
                      </>
                    )}
                  </span>
                  <span className="mt-1 block text-sm text-ink-muted">
                    Barangay {row.barangay ?? '—'}
                    {verdicts.get(row.id) === 'off-city' && ' · pin is outside Malabon'}
                    {verdicts.get(row.id) === 'disagrees' && ' · pin is in a different barangay'}
                  </span>
                  {/*
                    * The way to the details. Two, because they answer two
                    * questions: the certificate is what was issued, the
                    * register row is everything the office holds on it, with
                    * the actions (Revoke, Lift) beside it. Neither exists for a
                    * business that never held a permit, and nothing is drawn
                    * rather than a link to an empty search.
                    */}
                  {row.permit_id !== null && row.permit_number !== null && (
                    <span className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sm">
                      <button
                        type="button"
                        onClick={() => viewCertificate(row.permit_id as number)}
                        aria-label={`View certificate ${row.permit_number}`}
                        className="font-semibold text-royal underline-offset-2 hover:underline"
                      >
                        View certificate
                      </button>
                      {onFindInRegister ? (
                        <button
                          type="button"
                          onClick={() => onFindInRegister(row.permit_number as string)}
                          aria-label={`Find ${row.permit_number} in the register`}
                          className="font-semibold text-royal underline-offset-2 hover:underline"
                        >
                          Find in register
                        </button>
                      ) : (
                        <Link
                          to={`${registerPath}?q=${encodeURIComponent(row.permit_number)}&office=all`}
                          aria-label={`Find ${row.permit_number} in the register`}
                          className="font-semibold text-royal underline-offset-2 hover:underline"
                        >
                          Find in register
                        </Link>
                      )}
                    </span>
                  )}
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
      <ProtoCard className="mt-4 overflow-hidden rounded-xl">
        <table className="w-full text-left text-sm">
          <caption className="px-5 pt-4 text-left text-sm font-semibold text-ink">
            What the markers mean
          </caption>
          <thead>
            <tr className="text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
              <th scope="col" className="px-5 py-3">
                Marker
              </th>
              <th scope="col" className="px-5 py-3">
                Meaning
              </th>
              <th scope="col" className="px-5 py-3 text-right">
                Businesses
              </th>
            </tr>
          </thead>
          <tbody>
            {order.map((s) => (
              <tr key={s} className="border-t border-line align-top">
                <th scope="row" className="whitespace-nowrap px-5 py-3.5 font-medium text-ink">
                  <span className="flex items-center gap-2">
                    <span
                      className="inline-flex"
                      aria-hidden="true"
                      dangerouslySetInnerHTML={{ __html: glyphHtml(s, 18) }}
                    />
                    {STATES[s].label}
                  </span>
                </th>
                <td className="px-5 py-3.5 text-ink-secondary">{STATES[s].description(permitName, mayors)}</td>
                <td className="px-5 py-3.5 text-right text-ink tnum">{meta.counts[s]}</td>
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
      <p className="mt-4 max-w-3xl text-sm text-ink-secondary">
        {pinCounts.agrees} of the {meta.plotted} pins sit in the barangay their owner declared, and{' '}
        {pinCounts['off-city']} fall outside Malabon altogether. Filings made before the apply
        wizard began checking the pin against the barangay were never held to it, so those markers
        are drawn where the register says they are. Use Pin check above to work through them.
      </p>
    </div>
  )
}
