import { useEffect, useId, useState } from 'react'
import { GeoJSON, LayerGroup, LayersControl, Pane, useMapEvents } from 'react-leaflet'
import type { Feature, FeatureCollection, MultiPolygon } from 'geojson'
import type { Layer, Path, PathOptions } from 'leaflet'
import { ZONE_FILL_OPACITY, zoneFill, zoneStroke, zoneSwatch } from '../lib/zoningPalette'
import { zoneLabel } from '../lib/zoningNames'

/*
 * The chosen barangay's zones, drawn as a layer on the picker map, and the key
 * that names them.
 *
 * ── What is drawn ────────────────────────────────────────────────────────────
 *
 * Polygons traced offline from CPDO's raster sheet (scripts/trace-zoning-
 * sheets.py → web/public/zoning/<slug>.geojson). This replaced laying the
 * sheet itself over the map at half strength: the picture carried the
 * neighbours' washed colours, printed street names and barangay lettering over
 * the roofs, and read as mud. A traced layer carries only this barangay's
 * zones, so it can be quiet — pale fills, thin edges, no text on the map.
 *
 * ── What it must not become ─────────────────────────────────────────────────
 *
 * A verdict. The tracing is approximate three times over (sheet placement
 * ±10 m, coarse barangay polygons, the trace itself), so nothing reads a zone
 * back out for the pin: no "your lot is C-2", no colour on the pin, no blocked
 * step. CPDO decides, and the barangay card beside the map says so once. See
 * BarangayZoningMap.tsx for why a better geometry would not change that.
 *
 * Each zone used to say "approximate" on hover, and the key carried "Traced
 * from CPDO's sheet, so approximate." Both went on the client's lead's
 * reading (24 September 2026: "do the public care if it's traced?"). How the
 * layer was made is our business; the applicant needs one thing from it,
 * that the zoning office confirms the zone of their exact spot, and that
 * sentence lives on the card, not three times over the map.
 *
 * ── Names ───────────────────────────────────────────────────────────────────
 *
 * Plain names, from lib/zoningNames.ts, never the sheet's codes: the public
 * does not read "R-2" or "CMP". `withCodes` puts the code back after the name
 * for the officer's read-only map.
 *
 * ── Why the zones are interactive when the ring is not ──────────────────────
 *
 * The ring and the barangay outline are inert so they cannot swallow the click
 * that drops a pin. The zones need hover and tap for their tooltip, and they
 * do not swallow it: Leaflet 1.9 walks a DOM event up from the path to the map
 * container and fires it on every listener on the way (Map._findEventTargets),
 * unless a layer sets `bubblingMouseEvents: false`. These leave it true, so a
 * click on a zone opens the tooltip AND drops the pin. The e2e suite drops pins
 * on zoned ground, which is what proves it.
 *
 * Fetched on demand, one barangay at a time, and never bundled: the 21 files
 * are ~0.5 MB together and an applicant looks at one.
 */

export interface ZoneProperties {
  /** Classification codes. Two only where R-2 Basic and R-2 Max cannot be told apart on the sheet. */
  codes: string[]
  /** The sheet's name: "C-1", or "R-2 Basic or R-2 Max". Shown only to officers; see zoningNames.ts. */
  name: string
  /** The classification's legend colour from the database. */
  color: string | null
}

type ZoneCollection = FeatureCollection<MultiPolygon, ZoneProperties>

const PANE = 'biztrack-zoning'
/** The overlay's name in Leaflet's layers control; also how its toggle is found. */
export const ZONING_OVERLAY_NAME = 'Zoning'

const cache = new Map<string, Promise<ZoneCollection | null>>()

/*
 * A failed or odd response is "no layer", never an error on screen: the map
 * is still the thing the applicant came for. The shape check matters in dev
 * too — Vite answers an unknown path with index.html and a 200.
 */
function load(url: string): Promise<ZoneCollection | null> {
  let p = cache.get(url)
  if (!p) {
    p = fetch(url)
      .then((r) => (r.ok ? r.json() : null))
      .then((j: unknown) =>
        j !== null && typeof j === 'object' && (j as { type?: unknown }).type === 'FeatureCollection'
          ? (j as ZoneCollection)
          : null,
      )
      .catch(() => null)
    p.then((v) => {
      if (v === null) cache.delete(url)
    })
    cache.set(url, p)
  }
  return p
}

function styleFor(feature: Feature<MultiPolygon, ZoneProperties> | undefined): PathOptions {
  const c = feature?.properties.color ?? null
  return {
    color: zoneStroke(c),
    weight: 1,
    opacity: 0.9,
    fillColor: zoneFill(c),
    fillOpacity: ZONE_FILL_OPACITY,
  }
}

/** One legend entry per zone present, in the file's (CPDO legend) order. */
function zonesIn(data: ZoneCollection): ZoneProperties[] {
  return data.features.map((f) => f.properties)
}

/**
 * The overlay itself, for inside <LayersControl>. `checked` is its starting
 * state; after that the applicant owns it through the control.
 */
export function ZoningOverlay({
  url,
  checked,
  onData,
  onToggle,
  withCodes = false,
}: {
  url: string
  checked: boolean
  onData: (zones: ZoneProperties[] | null) => void
  onToggle: (on: boolean) => void
  /** Add the sheet's code after each plain name, for an officer's map. */
  withCodes?: boolean
}) {
  const [data, setData] = useState<ZoneCollection | null>(null)

  useEffect(() => {
    let live = true
    setData(null)
    onData(null)
    load(url).then((d) => {
      if (!live) return
      setData(d)
      onData(d === null ? null : zonesIn(d))
    })
    return () => {
      live = false
    }
    // onData is a state setter's wrapper from the parent; the fetch is keyed on the file.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [url])

  useMapEvents({
    overlayadd: (e) => {
      if (e.name === ZONING_OVERLAY_NAME) onToggle(true)
    },
    overlayremove: (e) => {
      if (e.name === ZONING_OVERLAY_NAME) onToggle(false)
    },
  })

  return (
    <>
      {/*
        * Its own pane between the tiles (200) and the vector overlays (400),
        * so the barangay outline, the ring and the pin all draw ON TOP of the
        * zones and stay readable.
        */}
      <Pane name={PANE} style={{ zIndex: 350 }} />
      <LayersControl.Overlay checked={checked} name={ZONING_OVERLAY_NAME}>
        <LayerGroup>
          {data !== null && (
            <GeoJSON
              // react-leaflet reads `data` once; a new barangay (or audience) needs a new layer.
              key={`${url}|${withCodes}`}
              data={data}
              pane={PANE}
              style={styleFor as (f?: Feature) => PathOptions}
              onEachFeature={(feature: Feature, layer: Layer) => {
                const props = feature.properties as ZoneProperties
                /*
                 * Built as a text node, not an HTML string: the name comes from
                 * a file, and nothing from a file is ever parsed as markup.
                 */
                const tip = document.createElement('span')
                tip.textContent = zoneLabel(props.codes, props.name, { withCode: withCodes })
                layer.bindTooltip(tip, { sticky: true, direction: 'top', offset: [0, -6], opacity: 1 })
                layer.on({
                  mouseover: () => (layer as Path).setStyle({ weight: 2, fillOpacity: ZONE_FILL_OPACITY + 0.15 }),
                  mouseout: () => (layer as Path).setStyle({ weight: 1, fillOpacity: ZONE_FILL_OPACITY }),
                })
              }}
            />
          )}
        </LayerGroup>
      </LayersControl.Overlay>
    </>
  )
}

/**
 * The key: bottom-left, a small "Zones" chip that opens to the zones present
 * in this barangay, in words beside each swatch (DESIGN.md, Never Color
 * Alone).
 *
 * Folded at every width. It used to start open on a desktop, where it still
 * covered a corner of the streets an applicant was using to find their block;
 * the colours on the map already say "these are zones", and the names are one
 * tap away for whoever wants them.
 *
 * Rendered by the map's wrapper, beside the Leaflet container rather than
 * inside it, so a click on the key is never also a click on the map.
 */
export function ZoningKey({ zones, withCodes = false }: { zones: ZoneProperties[]; withCodes?: boolean }) {
  const [open, setOpen] = useState(false)
  const listId = `zoning-key-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`

  if (zones.length === 0) return null

  return (
    <div className="absolute bottom-6 left-2 z-[1000] max-w-[calc(100%-1rem)] rounded-md border border-line-strong bg-white text-ink sm:max-w-[15rem]">
      <button
        type="button"
        aria-expanded={open}
        aria-controls={listId}
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between gap-2 px-2 py-1 text-left text-xs font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal"
      >
        Zones
        <svg
          aria-hidden="true"
          viewBox="0 0 12 12"
          className={`h-3 w-3 shrink-0 text-ink-secondary transition-transform duration-150 ${open ? 'rotate-180' : ''}`}
        >
          <path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" strokeWidth="1.5" />
        </svg>
      </button>
      {open && (
        <div id={listId} className="border-t border-line px-2 pb-1.5 pt-1">
          {/*
            * Named, because the card beside the map has a list of the same
            * classifications; two unnamed lists of zone names are two stops a
            * screen reader cannot tell apart.
            */}
          <ul aria-label="Zones drawn on the map" className="space-y-1">
            {zones.map((z) => (
              <li key={z.codes.join('|')} className="flex items-center gap-1.5 text-xs leading-snug">
                <ZoneSwatch color={z.color} />
                {zoneLabel(z.codes, z.name, { withCode: withCodes })}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

/**
 * The swatch the map key and the classification list share: the zone's fill
 * at the map's own strength, over white, with its edge colour — what the zone
 * actually looks like on the map, not the raw legend colour. Decorative; the
 * name beside it is the content.
 */
export function ZoneSwatch({ color }: { color: string | null }) {
  return (
    <span
      aria-hidden="true"
      className="inline-block h-3 w-3 shrink-0 rounded-sm border"
      style={{ backgroundColor: zoneSwatch(color), borderColor: zoneStroke(color) }}
    />
  )
}
