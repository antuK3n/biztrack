import { useEffect, useMemo, useState } from 'react'
import {
  Circle,
  LayersControl,
  MapContainer,
  Marker,
  Polygon,
  TileLayer,
  useMap,
  useMapEvents,
} from 'react-leaflet'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { BARANGAY_POLYGONS, MALABON_OUTLINE } from '../lib/malabonGeo.data'
import { ZONING_SHEETS } from '../lib/zoningSheets.data'
import { ZoningSheetOverlay } from './ZoningSheetOverlay'

/*
 * Leaflet + OpenStreetMap map-pin picker (PRODUCT.md GIS requirement).
 * Coordinates are stored on the business address; clicking drops/moves the pin.
 * We build the marker icon inline (a civic-blue SVG data URI) so bundler asset
 * resolution for Leaflet's default marker images is never an issue.
 *
 * The picker also draws the ring that Location Insights counts inside, when the
 * caller passes a radius. See `radiusM` on MapPickerProps for why that radius is
 * a prop and not a constant in here.
 *
 * It draws the city border and the barangay divisions too. Those are not
 * decoration: the applicant is being asked for a pin that must land inside
 * Malabon, and inside the barangay they chose from a dropdown. Refusing a pin
 * against a boundary the applicant cannot see is a puzzle rather than a
 * validation, so the boundary is on the map before the refusal can happen.
 *
 * And under all of that, the chosen barangay's CPDO zoning sheet, when one is
 * on file (client: "overlay the colour coding on the actual map when picking
 * your zoning area"). It is a picture laid on the streets, placed as well as
 * the sheet allows and no better; `lib/zoningSheets.data.ts` records how, and
 * the map says on its face that CPDO confirms the zone. Nothing reads a colour
 * back out of it. The applicant can switch it off or fade it, because at full
 * strength it hides the street names they came to the map to read.
 */

const PIN_SVG = encodeURIComponent(
  `<svg xmlns="http://www.w3.org/2000/svg" width="30" height="42" viewBox="0 0 30 42">
    <path d="M15 0C6.7 0 0 6.7 0 15c0 10.5 15 27 15 27s15-16.5 15-27C30 6.7 23.3 0 15 0Z" fill="#0025cc"/>
    <circle cx="15" cy="15" r="6" fill="#fff"/>
  </svg>`,
)

const pinIcon = L.icon({
  iconUrl: `data:image/svg+xml,${PIN_SVG}`,
  iconSize: [30, 42],
  iconAnchor: [15, 42],
  popupAnchor: [0, -38],
})

/*
 * The START pin: where the map suggests the applicant begin when their address
 * could not be found — the centre of their barangay. Not their address, and it
 * must not look like one. Same silhouette, so it reads as "a pin", but hollow
 * with a dashed outline: the difference survives greyscale and colour
 * blindness (DESIGN.md, Never Color Alone), and the caption under the map says
 * it in words.
 */
const START_PIN_SVG = encodeURIComponent(
  `<svg xmlns="http://www.w3.org/2000/svg" width="30" height="42" viewBox="0 0 30 42">
    <path d="M15 1.5C7.5 1.5 1.5 7.5 1.5 15c0 9.8 13.5 25 13.5 25s13.5-15.2 13.5-25C28.5 7.5 22.5 1.5 15 1.5Z" fill="#fff" fill-opacity="0.9" stroke="#0025cc" stroke-width="2.5" stroke-dasharray="4 3"/>
    <circle cx="15" cy="15" r="4" fill="#0025cc"/>
  </svg>`,
)

const startPinIcon = L.icon({
  iconUrl: `data:image/svg+xml,${START_PIN_SVG}`,
  iconSize: [30, 42],
  iconAnchor: [15, 42],
  popupAnchor: [0, -38],
})

/** Malabon City Hall — a sensible default center for this LGU. */
const DEFAULT_CENTER: [number, number] = [14.6572, 120.9573]

/*
 * Names the map on the element that actually carries it.
 *
 * Leaflet gives its container tabindex="0", so it is a focus stop for every
 * keyboard user — but react-leaflet 4.2.1 destructures MapContainer's props and
 * forwards only className/id/placeholder/style to the div it renders. An
 * aria-label passed as a prop is therefore dropped in silence, and the focus
 * stop announces as nothing at all (WCAG 2.1 AA 4.1.2). Writing the attributes
 * onto map.getContainer() goes around that prop filter instead of trusting it.
 *
 * A role is required as well as the label: aria-label is ignored on a div with
 * no role. role="region" rather than role="application" because a pin is
 * dropped by clicking and by nothing else — claiming an application role would
 * put the screen reader into focus mode for a widget that has no keyboard way
 * to commit the one thing it exists to do, and would bury the zoom controls and
 * the OSM attribution link inside it. Keyboard pin-dropping is a real gap; it
 * needs a design answer (a coordinate entry, or a centred crosshair committed
 * with Enter), not an ARIA role that only makes the gap harder to notice.
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

/*
 * Brings a pin the APPLICATION placed into view, by panning only.
 *
 * The map's centre and zoom are mount-time (see the note on MapContainer), and
 * that is right for a pin the applicant clicked: it is under their pointer
 * already. A pin placed from the address, or the barangay-centre start pin, is
 * different — it can land 3 km from City Hall, off the top of a 320 px map at
 * zoom 13, and a suggestion the applicant cannot see is no suggestion. So when
 * a pin is outside the visible area the map pans to it; when it is already in
 * view nothing moves, which is always the case for a click or a drag, so this
 * never moves the map under the applicant's own hand. No zoom change, for the
 * reason the MapContainer note gives.
 */
function KeepInView({ latitude, longitude }: { latitude: number; longitude: number }) {
  const map = useMap()
  useEffect(() => {
    if (!map.getBounds().contains([latitude, longitude])) {
      map.panTo([latitude, longitude])
    }
  }, [map, latitude, longitude])
  return null
}

function ClickCapture({ onPick }: { onPick: (lat: number, lng: number) => void }) {
  useMapEvents({
    click(e) {
      onPick(Number(e.latlng.lat.toFixed(6)), Number(e.latlng.lng.toFixed(6)))
    },
  })
  return null
}

/*
 * The ring drawn around the pin, styled as context rather than as the subject.
 *
 * DESIGN.md: royal #3242ca, the primary. NOT the error red — a radius is not a
 * warning, and #bd0000 is reserved for things that went wrong. The fill is
 * deliberately faint (8%) because OpenStreetMap tiles carry their own colour:
 * road casings, park green, water blue and the building fills all have to stay
 * legible THROUGH this, or the applicant loses the streets they were using to
 * recognise their own block. The stroke is what makes the ring readable; the
 * fill only says which side of it is "near".
 */
const RADIUS_RING: L.PathOptions = {
  color: '#3242ca',
  weight: 2,
  opacity: 0.85,
  fillColor: '#3242ca',
  fillOpacity: 0.08,
}

/*
 * `interactive` and `className` are passed to <Circle> as top-level props, NOT
 * inside pathOptions, and that distinction is not cosmetic.
 *
 * react-leaflet applies pathOptions by calling Path.setStyle(), which merges
 * into layer.options and repaints stroke and fill. But Leaflet reads
 * options.interactive and options.className once, in Renderer._initPath(), when
 * the <path> element is first created — setStyle() never revisits either. Put
 * them in pathOptions and they are silently ignored: the ring keeps Leaflet's
 * default interactive:true, gets the leaflet-interactive class, and swallows
 * every click inside it.
 *
 * Which would break the one thing this component exists to do. The ring is
 * centred on the pin, so the area an applicant is most likely to click to
 * CORRECT a slightly-off pin is exactly the area the ring covers. Top-level,
 * these reach the Circle constructor and the ring is inert scenery.
 */
const RADIUS_RING_CLASS = 'biztrack-radius-ring'

/*
 * The city border. Royal #3242ca per DESIGN.md, and emphatically NOT the error
 * red — the border is a fact about Malabon, not a complaint about the pin. It
 * is drawn unfilled: a fill would tint every street in the city and fight the
 * tiles underneath, and on the satellite layer it would grey out the very
 * imagery the applicant switched to in order to recognise their roof.
 */
const CITY_BORDER: L.PathOptions = {
  color: '#3242ca',
  weight: 2.5,
  opacity: 0.9,
  fill: false,
}

/*
 * Barangay divisions, drawn faintly. Their job is to let the applicant see that
 * the city is subdivided and roughly where the seams run, not to be read
 * individually — labelling 21 polygons at this size would be unreadable mush.
 * Dashed so they are obviously a different KIND of line from the city border,
 * which survives being looked at in greyscale (DESIGN.md: Never Color Alone).
 */
const BARANGAY_LINE: L.PathOptions = {
  color: '#3242ca',
  weight: 1,
  opacity: 0.35,
  dashArray: '3 3',
  fill: false,
}

/*
 * The barangay the applicant picked from the dropdown, filled so it reads as
 * "this one". Faint enough (10%) that the streets inside it stay legible —
 * this is the area they now have to pin INSIDE, so obscuring it would defeat
 * the point of highlighting it.
 */
const BARANGAY_SELECTED: L.PathOptions = {
  color: '#3242ca',
  weight: 2,
  opacity: 0.9,
  /*
   * `fill: true` is stated, and leaving it out was a bug worth keeping a note
   * about.
   *
   * react-leaflet applies pathOptions with Path.setStyle(), which MERGES into
   * the layer's existing options rather than replacing them. Every barangay
   * starts life styled with BARANGAY_LINE, which sets `fill: false`. Switching
   * to these options without saying `fill: true` therefore left the old `false`
   * in place: fillColor and fillOpacity were both applied and both ignored, and
   * the chosen barangay never filled. Setting it explicitly is what makes the
   * two styles inverses of each other instead of one-way.
   */
  fill: true,
  fillColor: '#3242ca',
  fillOpacity: 0.1,
}

/** GeoJSON stores [lng, lat]; Leaflet wants [lat, lng]. Converted here only. */
function toLatLngs(ring: readonly (readonly [number, number])[]): [number, number][] {
  return ring.map(([lng, lat]) => [lat, lng])
}

interface MapPickerProps {
  latitude: number | null
  longitude: number | null
  /**
   * Radius of the "nearby" ring, in metres, or null to draw none.
   *
   * A prop rather than a constant here because the number is the API's to state
   * — LocationInsights::RADIUS_M is what the counts on screen were actually
   * measured over, and a 500 baked into the client would keep drawing 500 on the
   * day that constant changes, quietly showing a ring that no figure matches.
   * Null while nothing has been reported yet: an unlabelled guess is worse than
   * no ring.
   */
  radiusM?: number | null
  /**
   * Name of the barangay to highlight, or null to highlight none.
   *
   * A name and not an id because the polygon set is a shipped asset keyed by
   * name and PSGC code, while barangay ids are seeded database rows — joining
   * them here would tie a static asset to a table's primary keys.
   */
  highlightBarangay?: string | null
  /**
   * Why the map cannot be clicked yet, or null when it can be.
   *
   * A reason rather than a boolean, because a control that stops responding
   * without saying why is the failure this prop exists to prevent. The caller
   * owns the wording since only the caller knows what is missing.
   */
  lockedReason?: string | null
  /**
   * Show the pin, and take no input at all.
   *
   * Distinct from `lockedReason`, which is a control SET ASIDE until
   * something else is answered — it paints a scrim and a sentence over the
   * map, because an applicant whose map stopped responding needs to be told
   * why. Read-only is not that: nothing is missing and nothing is waiting, so
   * a scrim over the map would be covering the very thing the reader opened it
   * for. The officer's review found this the hard way, with the locked
   * sentence sitting across the middle of the pin it was describing.
   *
   * The lock is the same either way — ClickCapture is not mounted and the
   * marker is not draggable — it is only the explanation that differs.
   */
  readOnly?: boolean
  /** Omitted when `readOnly`: there is nothing that could call it. */
  onPick?: (lat: number, lng: number) => void
  /**
   * Where to suggest the applicant START, when there is no pin yet — the
   * centre of their barangay after their address could not be found.
   *
   * Drawn hollow and never counted as a pin: it is not passed as
   * latitude/longitude, so nothing downstream (the saved address, the
   * insights lookup, the step's "pin on the map" check) ever sees it. It
   * becomes a pin only through `onPick` — dragged, clicked, or chosen with
   * Enter while focused, which Leaflet's marker keyboard handling gives it.
   * Ignored while a real pin exists.
   */
  startAt?: { latitude: number; longitude: number } | null
}

export function MapPicker({
  latitude,
  longitude,
  radiusM = null,
  highlightBarangay = null,
  lockedReason = null,
  readOnly = false,
  onPick,
  startAt = null,
}: MapPickerProps) {
  const hasPin = latitude !== null && longitude !== null
  const locked = lockedReason !== null
  /*
   * One flag for "can this map be changed", so the click handler and the
   * marker's draggability cannot disagree. A missing `onPick` counts as no:
   * mounting ClickCapture without a handler would be a click that silently
   * does nothing, which is the failure mode `lockedReason` exists to avoid.
   */
  const editable = !locked && !readOnly && onPick !== undefined
  /*
   * Found by the barangay name the caller already passes for the highlight, so
   * the overlay needs no prop of its own and appears on every map that
   * highlights a barangay with a sheet on file.
   */
  const sheet = highlightBarangay ? (ZONING_SHEETS[highlightBarangay] ?? null) : null
  /*
   * On by default where a pin is being placed, off where one is only being
   * looked at: the officer reading a filing opened the map to see the pin, and
   * the sheet sits over it. Either can switch.
   */
  const [showZoning, setShowZoning] = useState(!readOnly)
  /*
   * Half strength to start. The sheets are opaque, pastel and full of their
   * own linework; any stronger and the OSM street names under them are
   * unreadable, any weaker and the pale R-1 yellow disappears.
   */
  const [zoningOpacity, setZoningOpacity] = useState(0.5)
  const center = useMemo<[number, number]>(
    () => (hasPin ? [latitude as number, longitude as number] : DEFAULT_CENTER),
    [hasPin, latitude, longitude],
  )

  return (
    <div className="overflow-hidden rounded-md border border-line-strong">
      <div className="relative">
        {/*
          * Zoom 15, not 16, when the map opens on an existing pin.
          *
          * Malabon sits at ~14.66°N, so zoom 16 is about 2.3 m per pixel: a 500 m
          * ring is then 430 px across inside a 320 px-tall map, and the applicant
          * sees an arc running off both edges instead of a circle. Zoom 15 doubles
          * the ground per pixel and the whole ring fits with room around it, which
          * is the entire point of drawing it — you cannot judge how far 500 m is
          * from a piece of one.
          *
          * Both numbers are mount-time only: react-leaflet 4 treats center/zoom as
          * initial state, so dropping a pin never yanks the map out from under the
          * click that dropped it. Only a remount — reopening a saved draft —
          * arrives here with a pin already set.
          *
          * Which means a FRESH pin gets its ring at zoom 13, where 500 m is about
          * 54 px across: clearly visible, but small. Auto-zooming to fit the ring
          * on first drop was considered and rejected. DESIGN.md is explicit that
          * the circle is context and not the subject, and refitting the viewport
          * would make it the subject — it would also move the map immediately
          * after a click, which is precisely the moment an applicant is least able
          * to afford losing their bearings, and it would strand anyone who pinned
          * the wrong barangay at street level with no view of where they are. The
          * ring is drawn in metres, so it grows as they zoom in to place the pin
          * exactly, which is when the scale reference is actually wanted. If this
          * is ever revisited, the thing to change is the OPENING zoom, not a jump
          * triggered by the applicant's own click.
          */}
        <MapContainer
          center={center}
          zoom={hasPin ? 15 : 13}
          scrollWheelZoom={false}
          style={{ height: 320, width: '100%' }}
        >
          <ContainerName
            label={
              locked
                ? `Map of Malabon, not yet clickable. ${lockedReason}`
                : 'Map of Malabon. Click to drop a pin at your business location.'
            }
          />
          {/*
            * Street map or satellite, applicant's choice.
            *
            * Both are needed and neither is sufficient. Street tiles carry the
            * road names someone uses to find their own block; satellite imagery
            * carries the roof, the corner lot and the river bend that someone who
            * does not read maps recognises instantly. Malabon's interior blocks
            * are dense and many alleys are unnamed on OSM, so for a lot of
            * applicants the imagery is the only way to place a pin precisely.
            *
            * Streets stay the default (`checked`): it is the lighter download and
            * the one that names things, so it is the better first impression. The
            * control remembers nothing between mounts — a per-user preference
            * would need somewhere to live, and this is not important enough to
            * put in the profile.
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
                * Esri World Imagery. Note the {z}/{y}/{x} order — Esri puts row
                * before column, the opposite of the OSM URL directly above, and
                * swapping them yields a map of the wrong hemisphere rather than
                * an error. Attribution is a licence condition, not decoration.
                */}
              <TileLayer
                attribution="Imagery &copy; Esri, Maxar, Earthstar Geographics, and the GIS User Community"
                url="https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}"
                maxZoom={19}
              />
            </LayersControl.BaseLayer>
          </LayersControl>
          {sheet && showZoning && <ZoningSheetOverlay sheet={sheet} opacity={zoningOpacity} />}
          {/*
            * The city border and the barangay seams, under the pin and the ring.
            *
            * `interactive={false}` on every one of them, and for the same reason
            * the radius ring is inert: these cover the entire clickable surface,
            * so an interactive polygon would swallow the click that drops the pin
            * — the one thing this component exists to do. See the note on
            * RADIUS_RING_CLASS for why this must be a top-level prop and not part
            * of pathOptions.
            */}
          <Polygon positions={toLatLngs(MALABON_OUTLINE)} interactive={false} pathOptions={CITY_BORDER} />
          {BARANGAY_POLYGONS.map((b) => {
            const isSelected = b.name === highlightBarangay
            return (
              <Polygon
                key={b.psgc}
                positions={b.rings.map(toLatLngs)}
                interactive={false}
                pathOptions={isSelected ? BARANGAY_SELECTED : BARANGAY_LINE}
              />
            )
          })}
          {editable && <ClickCapture onPick={onPick} />}
          {/*
            * Circle, not CircleMarker. CircleMarker's radius is in screen pixels,
            * so it would stay the same size as the map zooms and would therefore
            * mean a different distance at every zoom level — the opposite of a
            * scale reference. Circle's radius is in metres and is projected, so it
            * grows and shrinks with the streets underneath it.
            *
            * Drawn before the Marker so the pin stays on top of its own ring.
            */}
          {hasPin && radiusM !== null && radiusM > 0 && (
            <Circle
              center={[latitude as number, longitude as number]}
              radius={radiusM}
              interactive={false}
              className={RADIUS_RING_CLASS}
              pathOptions={RADIUS_RING}
            />
          )}
          {/*
            * Draggable, and the drag ends in the SAME handler a click does.
            *
            * Item 7 asks for the pin to be adjustable "similar to how ride
            * hailing apps work", and dragging is the gesture that phrase means.
            * Clicking elsewhere already moved the pin, but on a phone that is a
            * different intention to express: a click is "put it there", a drag is
            * "not quite, a bit further down this alley", which is exactly the
            * correction an auto-placed pin invites.
            *
            * `onPick` rather than a second setter, so a dragged pin is checked
            * against the city outline and the chosen barangay like any other. A
            * drag that ends somewhere the map would have refused a click is
            * refused too — and because the parent never stores it, Leaflet leaves
            * the marker where the refused drag dropped it, so `position` is
            * re-asserted from the props on the next render and the pin springs
            * back to where it legitimately is.
            */}
          {hasPin && (
            <Marker
              position={[latitude as number, longitude as number]}
              icon={pinIcon}
              draggable={editable}
              eventHandlers={{
                dragend: (e) => {
                  // Unreachable while `editable` is false — the marker is not
                  // draggable then — but the handler is registered either way,
                  // so it asks rather than assuming.
                  if (!onPick) return
                  const { lat, lng } = (e.target as { getLatLng: () => { lat: number; lng: number } })
                    .getLatLng()
                  onPick(Number(lat.toFixed(6)), Number(lng.toFixed(6)))
                },
                /*
                 * A click ON the pin does what a click beside it does. Leaflet
                 * does not pass a marker click through to the map, so without
                 * this a click on a suggested pin — "yes, there" — did nothing
                 * and left it labelled as a suggestion.
                 */
                click: () => {
                  if (!editable || !onPick) return
                  onPick(latitude as number, longitude as number)
                },
              }}
            />
          )}
          {hasPin && <KeepInView latitude={latitude as number} longitude={longitude as number} />}
          {/*
            * The start pin: only while there is no real one, and only where the
            * map can be changed — on a locked or read-only map it would be a
            * suggestion nobody can act on. Every way of touching it ends in
            * `onPick`, so it becomes an answer only by the applicant's hand.
            */}
          {!hasPin && startAt && editable && (
            <>
              <Marker
                position={[startAt.latitude, startAt.longitude]}
                icon={startPinIcon}
                draggable
                title="Start point, not your address. Drag it onto your business."
                alt="Start point, not your address"
                eventHandlers={{
                  dragend: (e) => {
                    const { lat, lng } = (e.target as { getLatLng: () => { lat: number; lng: number } })
                      .getLatLng()
                    onPick?.(Number(lat.toFixed(6)), Number(lng.toFixed(6)))
                  },
                  click: () => onPick?.(startAt.latitude, startAt.longitude),
                }}
              />
              <KeepInView latitude={startAt.latitude} longitude={startAt.longitude} />
            </>
          )}
        </MapContainer>
        {/*
          * The caveat, on the map itself and not in a paragraph beside it: a
          * screenshot of the map with the colours on must carry it too.
          *
          * `pointer-events-none` because it sits on clickable map. An applicant
          * whose lot is in that bottom-left corner still has to be able to pin it.
          * Solid white so it reads over both the tiles and the satellite imagery.
        * Raised above the bottom edge so it never covers the attribution line,
        * which is a licence condition of both tile sources.
          */}
        {sheet && showZoning && (
          <p className="pointer-events-none absolute bottom-6 left-1 z-[1000] max-w-[calc(100%-0.5rem)] rounded bg-white/95 px-2 py-1 text-[11px] leading-snug text-ink shadow-card">
            Zoning colours are approximate — CPDO confirms the zone for your exact lot.
          </p>
        )}
        {/*
          * The locked state: the map is visible but not clickable, and says so.
          *
          * Visible rather than hidden or greyed to nothing, because the map is
          * also the explanation — an applicant who can see Malabon and its
          * barangays understands what they are about to be asked for. Hiding it
          * until a trade is chosen would make the step look empty and broken.
          *
          * A scrim over the map rather than `disabled` anywhere: DESIGN.md
          * forbids `disabled` (it drops the element out of the tab order and
          * takes its accessible name with it), and there is no form control here
          * to disable in any case — Leaflet's container is a div. The scrim is
          * white at 60% so the map reads as set-aside rather than switched off,
          * and the sentence sits on solid white so it stays legible over both the
          * street tiles and the much darker satellite imagery.
          *
          * `pointer-events-none` on the wrapper is what makes the lock real for a
          * MOUSE, but it is not the lock. The lock is that ClickCapture is not
          * mounted at all, so no click handler exists to reach; the scrim only
          * stops the map panning and zooming under a click that will do nothing.
          * role="status" announces the reason when it appears, without stealing
          * focus from whatever the applicant is filling in.
          */}
        {locked && (
          <div
            className="pointer-events-none absolute inset-0 z-[1000] flex items-center justify-center bg-white/60 p-4"
            role="status"
          >
            <p className="max-w-xs rounded-lg bg-white px-4 py-3 text-center text-xs font-medium text-ink shadow-card">
              {lockedReason}
            </p>
          </div>
        )}
      </div>
      {/*
        * The overlay's controls, below the map rather than on it: on the map
        * they would cover streets, and Leaflet would need telling not to treat
        * a drag of the slider as a pan. Native checkbox and range, so both are
        * reachable and announced without any help from here. A barangay with
        * no sheet on file gets no row at all rather than a control that does
        * nothing.
        */}
      {sheet && (
        <div className="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-line-strong bg-white px-3 py-2 text-xs text-ink">
          <label className="flex items-center gap-2 font-medium">
            <input
              type="checkbox"
              className="h-4 w-4 accent-royal"
              checked={showZoning}
              onChange={(e) => setShowZoning(e.target.checked)}
            />
            Zoning map colours for {highlightBarangay}
          </label>
          {/*
            * Hidden with the colours rather than left inert: a strength for
            * something not shown answers no question. (Not `disabled` in any
            * case — DESIGN.md.)
            */}
          {showZoning && (
            <label className="flex items-center gap-2">
              <span className="text-ink-secondary">Strength</span>
              <input
                type="range"
                min={10}
                max={100}
                step={10}
                value={Math.round(zoningOpacity * 100)}
                aria-valuetext={`${Math.round(zoningOpacity * 100)}%`}
                className="w-28 accent-royal"
                onChange={(e) => setZoningOpacity(Number(e.target.value) / 100)}
              />
            </label>
          )}
          {/*
            * The figure sits outside the label so the slider is named
            * "Strength", not "Strength 50%": the value is already announced
            * through aria-valuetext, and in the name it would be said twice.
            */}
          {showZoning && (
            <span aria-hidden="true" className="-ml-3 w-8 tabular-nums text-ink-secondary">
              {Math.round(zoningOpacity * 100)}%
            </span>
          )}
        </div>
      )}
    </div>
  )
}
