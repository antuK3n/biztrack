import { BARANGAY_POLYGONS, MALABON_OUTLINE } from './malabonGeo.data'

/*
 * Where a pin may be dropped, and which barangay it is in.
 *
 * This replaces a bounding box. The box was honest about being a box — its
 * comment said so — but a rectangle around an irregular delta city admits
 * slivers of Navotas, Caloocan and Valenzuela, which is exactly what a tester
 * found: pins landed in Caloocan and were accepted. It was also too SMALL in
 * places. The box ran 120.930–120.985 E; the city actually reaches 120.921 and
 * 121.001, so real Malabon addresses near the east and west edges were being
 * refused while foreign ones near the corners were let through.
 *
 * See `malabonGeo.data.ts` for where the polygons come from and how they were
 * checked. The short version: OSM has no barangay boundaries for Malabon at
 * all, GADM's are wrong by up to 931 m, and the PSA/OCHA set used here matches
 * PSA's official city area to 0.06%.
 */

/** Metres per degree at Malabon's latitude (~14.66 N). */
const M_PER_DEG_LAT = 110574
const M_PER_DEG_LNG = 111320 * Math.cos((14.66 * Math.PI) / 180)

/*
 * How far outside its barangay a pin may sit before we call it a mismatch.
 *
 * Not a round number picked for comfort — it is sized to the error in the data.
 * The polygons are simplified: their edges average 230 m and the 90th
 * percentile is 444 m, so a boundary that really follows a river bend is stored
 * as a chord across it. A 444 m chord across a 45-degree bend puts the stored
 * line about 92 m from the true one. 150 m clears that worst realistic case
 * with margin.
 *
 * The asymmetry is deliberate. A false ACCEPT costs a CPDO reviewer a second
 * look at a pin they were going to look at anyway. A false REJECT tells someone
 * standing in their own shop that their address is not where they say it is,
 * and leaves them no way forward — there is no override in this form. So the
 * tolerance is generous, and it still catches every real mismatch: picking the
 * wrong barangay from a dropdown puts the pin 500 m to 3 km out, not 150.
 */
export const BARANGAY_TOLERANCE_M = 150

type Ring = readonly (readonly [number, number])[]

/** Even-odd ray cast. `point` and `ring` are both [lng, lat]. */
function inRing(lng: number, lat: number, ring: Ring): boolean {
  let inside = false
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const [xi, yi] = ring[i]
    const [xj, yj] = ring[j]
    if (yi > lat !== yj > lat && lng < ((xj - xi) * (lat - yi)) / (yj - yi) + xi) {
      inside = !inside
    }
  }
  return inside
}

/** Outer ring minus holes. */
function inPolygon(lng: number, lat: number, rings: readonly Ring[]): boolean {
  if (rings.length === 0 || !inRing(lng, lat, rings[0])) return false
  return !rings.slice(1).some((hole) => inRing(lng, lat, hole))
}

/** Metres from a point to a line segment, in a local flat projection. */
function distanceToSegment(
  lng: number,
  lat: number,
  a: readonly [number, number],
  b: readonly [number, number],
): number {
  const px = lng * M_PER_DEG_LNG
  const py = lat * M_PER_DEG_LAT
  const ax = a[0] * M_PER_DEG_LNG
  const ay = a[1] * M_PER_DEG_LAT
  const bx = b[0] * M_PER_DEG_LNG
  const by = b[1] * M_PER_DEG_LAT
  const dx = bx - ax
  const dy = by - ay
  if (dx === 0 && dy === 0) return Math.hypot(px - ax, py - ay)
  const t = Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / (dx * dx + dy * dy)))
  return Math.hypot(px - (ax + t * dx), py - (ay + t * dy))
}

function distanceToRings(lng: number, lat: number, rings: readonly Ring[]): number {
  let best = Infinity
  for (const ring of rings) {
    for (let i = 0; i < ring.length - 1; i++) {
      const d = distanceToSegment(lng, lat, ring[i], ring[i + 1])
      if (d < best) best = d
    }
  }
  return best
}

/** True when the pin is inside the city border. */
export function withinMalabon(latitude: number, longitude: number): boolean {
  return inRing(longitude, latitude, MALABON_OUTLINE)
}

/**
 * The barangay a pin falls in, or null if it falls in none.
 *
 * Null does NOT mean "outside Malabon" on its own — a pin can sit in a sliver
 * the simplified polygons leave uncovered. Ask `withinMalabon` for that.
 */
export function barangayContaining(latitude: number, longitude: number): string | null {
  for (const b of BARANGAY_POLYGONS) {
    if (inPolygon(longitude, latitude, b.rings)) return b.name
  }
  return null
}

/** Metres from a pin to the named barangay; 0 when the pin is inside it. */
export function metresFromBarangay(
  latitude: number,
  longitude: number,
  barangay: string,
): number | null {
  const b = BARANGAY_POLYGONS.find((p) => p.name === barangay)
  if (!b) return null
  if (inPolygon(longitude, latitude, b.rings)) return 0
  return distanceToRings(longitude, latitude, b.rings)
}

export type PinVerdict =
  | { kind: 'ok' }
  /** Pin is outside the city border entirely. */
  | { kind: 'outside-city' }
  /**
   * Pin is in Malabon but further than the tolerance from the chosen barangay.
   * `actual` is the barangay it does fall in, or null if it fell in none
   * (a boundary sliver, or water the polygons do not cover).
   */
  | { kind: 'wrong-barangay'; actual: string | null; metres: number }

/*
 * The one place that decides whether a pin and a chosen barangay agree.
 *
 * Returns 'ok' when the barangay is unknown to us rather than refusing: the
 * barangay list is seeded data and this polygon set is a shipped asset, so a
 * barangay could be added to one and not the other. Blocking an applicant over
 * OUR bookkeeping gap is the wrong failure — the pin still gets reviewed.
 */
export function checkPin(
  latitude: number,
  longitude: number,
  selectedBarangay: string | null,
): PinVerdict {
  if (!withinMalabon(latitude, longitude)) return { kind: 'outside-city' }
  if (!selectedBarangay) return { kind: 'ok' }

  const metres = metresFromBarangay(latitude, longitude, selectedBarangay)
  if (metres === null || metres <= BARANGAY_TOLERANCE_M) return { kind: 'ok' }

  return {
    kind: 'wrong-barangay',
    actual: barangayContaining(latitude, longitude),
    metres: Math.round(metres),
  }
}

/*
 * A point well inside the named barangay, for the pin to START at when the
 * address could not be found (see the fallback in ApplyWizard's item 7 effect).
 *
 * Only ever a starting place. It is not where anybody's business is, and the
 * caller must never present it as the address or let it stand as the answer —
 * it is not stored and it does not satisfy the step until the applicant moves
 * or confirms it themselves.
 *
 * The area-weighted centroid of the outer ring, which for all 21 barangays
 * falls inside its own polygon (checked 24 September 2026). A C-shaped
 * barangay's centroid could fall outside, so that case is still answered: the
 * middle of the widest stretch of the barangay along the centroid's latitude.
 */
const centres = new Map<string, readonly [number, number] | null>()

export function barangayCentre(barangay: string): readonly [number, number] | null {
  if (centres.has(barangay)) return centres.get(barangay) ?? null
  const b = BARANGAY_POLYGONS.find((p) => p.name === barangay)
  let centre: readonly [number, number] | null = null
  if (b && b.rings.length > 0) {
    const ring = b.rings[0]
    let area = 0
    let cx = 0
    let cy = 0
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      const f = ring[j][0] * ring[i][1] - ring[i][0] * ring[j][1]
      area += f
      cx += (ring[j][0] + ring[i][0]) * f
      cy += (ring[j][1] + ring[i][1]) * f
    }
    const lng = cx / (3 * area)
    const lat = cy / (3 * area)
    if (area !== 0 && inPolygon(lng, lat, b.rings)) {
      centre = [Number(lat.toFixed(6)), Number(lng.toFixed(6))]
    } else {
      // Where the centroid's latitude crosses the outer ring, in order; the
      // widest inside stretch is between an odd crossing and the next.
      const xs: number[] = []
      for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const [xi, yi] = ring[i]
        const [xj, yj] = ring[j]
        if (yi > lat !== yj > lat) xs.push(((xj - xi) * (lat - yi)) / (yj - yi) + xi)
      }
      xs.sort((p, q) => p - q)
      let best = -1
      for (let k = 0; k + 1 < xs.length; k += 2) {
        if (xs[k + 1] - xs[k] > best) {
          best = xs[k + 1] - xs[k]
          centre = [Number(lat.toFixed(6)), Number(((xs[k] + xs[k + 1]) / 2).toFixed(6))]
        }
      }
    }
  }
  centres.set(barangay, centre)
  return centre
}
