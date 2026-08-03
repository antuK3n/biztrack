import { useEffect, useMemo } from 'react'
import { MapContainer, Marker, TileLayer, useMap, useMapEvents } from 'react-leaflet'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

/*
 * Leaflet + OpenStreetMap map-pin picker (PRODUCT.md GIS requirement).
 * Coordinates are stored on the business address; clicking drops/moves the pin.
 * We build the marker icon inline (a civic-blue SVG data URI) so bundler asset
 * resolution for Leaflet's default marker images is never an issue.
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

interface MapPickerProps {
  latitude: number | null
  longitude: number | null
  onPick: (lat: number, lng: number) => void
}

export function MapPicker({ latitude, longitude, onPick }: MapPickerProps) {
  const hasPin = latitude !== null && longitude !== null
  const center = useMemo<[number, number]>(
    () => (hasPin ? [latitude as number, longitude as number] : DEFAULT_CENTER),
    [hasPin, latitude, longitude],
  )

  return (
    <div className="overflow-hidden rounded-md border border-line-strong">
      <MapContainer
        center={center}
        zoom={hasPin ? 16 : 13}
        scrollWheelZoom={false}
        style={{ height: 320, width: '100%' }}
      >
        <ContainerName label="Map of Malabon. Click to drop a pin at your business location." />
        <TileLayer
          attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
        />
        <ClickCapture onPick={onPick} />
        {hasPin && <Marker position={[latitude as number, longitude as number]} icon={pinIcon} />}
      </MapContainer>
    </div>
  )
}
