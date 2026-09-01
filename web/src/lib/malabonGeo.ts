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
