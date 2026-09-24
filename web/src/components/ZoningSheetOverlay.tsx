import { useId, useMemo } from 'react'
import { Pane, SVGOverlay } from 'react-leaflet'
import type { LatLngBoundsExpression } from 'leaflet'
import type { ZoningSheetPlacement } from '../lib/zoningSheets.data'

/*
 * One CPDO zoning sheet, drawn in place on a Leaflet map.
 *
 * Only the sheet's map frame is shown. The title block, legend and location map
 * are clipped away: pinned to the ground they would sit over real streets in
 * the next barangay and look like part of the city.
 *
 * ── Why an SVGOverlay and not an ImageOverlay ────────────────────────────────
 *
 * ImageOverlay stretches a picture to a latitude/longitude box, and a box
 * cannot rotate. The sheets are drawn in UTM zone 51N, whose grid north sits
 * about 0.5° off true north at Malabon, so a box misplaces the frame's corners
 * by 7 to 28 m depending on the sheet's scale. That is on top of the sheet's
 * own error, and it is avoidable. Rotating the image element with CSS was considered
 * and rejected: Leaflet positions the image with a `transform` of its own, and
 * a CSS rotation composes about the wrong point, drifting further the further
 * the map has been panned.
 *
 * So the overlay is an <svg> Leaflet stretches to the frame's bounding box,
 * and inside it the image carries the exact affine that the three stored
 * corners fix. The viewBox is in Web Mercator units, the same projection
 * Leaflet stretches in, so the affine is exact rather than approximately so.
 *
 * ── What it must not do ─────────────────────────────────────────────────────
 *
 * Be clicked. Leaflet overlays are non-interactive by default and this one is
 * left that way: it covers the very streets an applicant clicks to drop a pin.
 * And nothing is ever read back out of it — no colour under the pin becomes a
 * zone. See `lib/zoningSheets.data.ts`.
 */

const PANE = 'biztrack-zoning-sheet'

/** Latitude to Web Mercator y, in degree-like units so it scales like longitude. */
function mercY(lat: number): number {
  return (Math.log(Math.tan(Math.PI / 4 + (lat * Math.PI) / 360)) * 180) / Math.PI
}
function unMercY(y: number): number {
  return (Math.atan(Math.exp((y * Math.PI) / 180)) * 360) / Math.PI - 90
}

/** viewBox units per degree. Any constant works; this keeps numbers readable. */
const K = 1e5

function placement(sheet: ZoningSheetPlacement) {
  const [tl, tr, bl] = sheet.corners.map(([lat, lng]) => ({ x: lng, y: mercY(lat) }))
  // The frame is a parallelogram in Mercator, so the fourth corner follows.
  const br = { x: tr.x + bl.x - tl.x, y: tr.y + bl.y - tl.y }
  const xs = [tl.x, tr.x, bl.x, br.x]
  const ys = [tl.y, tr.y, bl.y, br.y]
  const west = Math.min(...xs)
  const east = Math.max(...xs)
  const north = Math.max(...ys)
  const south = Math.min(...ys)
  const v = (p: { x: number; y: number }) => ({ x: (p.x - west) * K, y: (north - p.y) * K })
  const [vtl, vtr, vbl] = [v(tl), v(tr), v(bl)]

  const [fx0, fy0, fx1, fy1] = sheet.frame
  const a = (vtr.x - vtl.x) / (fx1 - fx0)
  const b = (vtr.y - vtl.y) / (fx1 - fx0)
  const c = (vbl.x - vtl.x) / (fy1 - fy0)
  const d = (vbl.y - vtl.y) / (fy1 - fy0)
  const e = vtl.x - a * fx0 - c * fy0
  const f = vtl.y - b * fx0 - d * fy0

  const bounds: LatLngBoundsExpression = [
    [unMercY(south), west],
    [unMercY(north), east],
  ]
  return {
    bounds,
    viewBox: `0 0 ${(east - west) * K} ${(north - south) * K}`,
    matrix: `matrix(${a} ${b} ${c} ${d} ${e} ${f})`,
  }
}

export function ZoningSheetOverlay({
  sheet,
  opacity,
}: {
  sheet: ZoningSheetPlacement
  opacity: number
}) {
  const { bounds, viewBox, matrix } = useMemo(() => placement(sheet), [sheet])
  // useId yields ":r1:"-style ids, and a colon inside url(#…) is not a safe
  // fragment reference, so it is reduced to characters an id can always hold.
  const clipId = `zoning-clip-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`
  const [fx0, fy0, fx1, fy1] = sheet.frame

  return (
    /*
     * Its own pane, between the tiles (200) and the vector overlays (400), so
     * the barangay seams and the pin's ring stay drawn ON TOP of the colours.
     * In the shared overlay pane the sheet would land above the barangay
     * outline it is meant to be read against.
     */
    <Pane name={PANE} style={{ zIndex: 350 }}>
      <SVGOverlay
        /*
         * Keyed on the file: react-leaflet applies `attributes` once, when the
         * <svg> is created, so a new sheet needs a new element or it would be
         * drawn through the previous sheet's viewBox.
         */
        key={sheet.url}
        pane={PANE}
        bounds={bounds}
        opacity={opacity}
        attributes={{ viewBox, preserveAspectRatio: 'none' }}
      >
        <defs>
          <clipPath id={clipId} clipPathUnits="userSpaceOnUse">
            <rect x={fx0} y={fy0} width={fx1 - fx0} height={fy1 - fy0} />
          </clipPath>
        </defs>
        <g transform={matrix}>
          <image
            href={sheet.url}
            x={0}
            y={0}
            width={sheet.size[0]}
            height={sheet.size[1]}
            preserveAspectRatio="none"
            clipPath={`url(#${clipId})`}
          />
        </g>
      </SVGOverlay>
    </Pane>
  )
}
