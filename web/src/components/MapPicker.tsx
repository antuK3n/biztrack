import { useEffect, useMemo } from 'react'
import { Circle, MapContainer, Marker, TileLayer, useMap, useMapEvents } from 'react-leaflet'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

/*
 * Leaflet + OpenStreetMap map-pin picker (PRODUCT.md GIS requirement).
 * Coordinates are stored on the business address; clicking drops/moves the pin.
 * We build the marker icon inline (a civic-blue SVG data URI) so bundler asset
 * resolution for Leaflet's default marker images is never an issue.
 *
 * The picker also draws the ring that Location Insights counts inside, when the
 * caller passes a radius. See `radiusM` on MapPickerProps for why that radius is
 * a prop and not a constant in here.
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
  onPick: (lat: number, lng: number) => void
}

export function MapPicker({ latitude, longitude, radiusM = null, onPick }: MapPickerProps) {
  const hasPin = latitude !== null && longitude !== null
  const center = useMemo<[number, number]>(
    () => (hasPin ? [latitude as number, longitude as number] : DEFAULT_CENTER),
    [hasPin, latitude, longitude],
  )

  return (
    <div className="overflow-hidden rounded-md border border-line-strong">
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
        <ContainerName label="Map of Malabon. Click to drop a pin at your business location." />
        <TileLayer
          attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
        />
        <ClickCapture onPick={onPick} />
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
        {hasPin && <Marker position={[latitude as number, longitude as number]} icon={pinIcon} />}
      </MapContainer>
    </div>
  )
}
