import { MALABON_OUTLINE } from './malabonGeo.data'
import { barangayContaining, checkPin, withinMalabon } from './malabonGeo'

/**
 * Turning a typed address into a pin, the way a ride-hailing app does.
 *
 * Checklist item 7 — "There should be automatic pinning of maps upon entering
 * the house number and street address, but still allow the user to manually
 * change the pin for more accuracy if he/she wishes to."
 *
 * ── What this can and cannot do ─────────────────────────────────────────────
 *
 * Nominatim reads OpenStreetMap, and OSM holds Malabon's STREETS but almost
 * none of its house numbers, and no barangay boundaries at all (see
 * `malabonGeo.data.ts`). So the best it can ever say is "somewhere on this
 * street", and the pin it drops is the applicant's to move. It only ever
 * SUGGESTS: the map still takes a click anywhere, and a failed lookup is quiet.
 *
 * ── What was measured, and what it changed (24 September 2026) ──────────────
 *
 * 41 addresses from the register (32 seeded, 9 typed by testers) were run
 * through Nominatim with the query this file used to build — the whole line
 * plus the barangay plus the city, first result only:
 *
 *   old query:   any result for 6 of 41; accepted by the pin checks 5 of 41 (12%)
 *   this query:  any result for 38 of 41; accepted 10 of 41 (24%)
 *
 * Three things made the difference, each measured on its own:
 *
 *   - The BARANGAY IN THE QUERY TEXT was the main cause of misses. OSM has no
 *     barangay areas for Malabon, so "Sanciangco Street, Tañong, Malabon" asks
 *     for something OSM cannot relate and usually gets nothing. Dropping it
 *     took results from 8 to 38 of 41. The barangay is still used — as a
 *     FILTER on the answers, with the same polygons the map's click handler
 *     uses, which is where it was always going to be checked anyway.
 *   - The HOUSE NUMBER and the abbreviations ("St.", "Ave. St.") cost matches
 *     and bought no precision: every hit that did come back was a street, never
 *     a building. So the number is stripped and "St." spelt out.
 *   - Long roads cross barangays. Asking for five results and the road's
 *     geometry lets the pin go on the part of the street that is inside the
 *     chosen barangay, instead of wherever OSM put the road's middle.
 *
 * Block and Lot are deliberately NOT sent. With "Blk 5 Lot 12" in front of the
 * same 41 addresses the hit rate was 0 of 41: OSM has no subdivision lots here,
 * and the words poison the whole query. Block/Lot is for CPDO, not for OSM.
 *
 * Most of the 31 that still miss found the street in Malabon, but in a
 * different barangay from the one declared. On this register that is largely
 * the seed data (it assigns streets to barangays at random), and for a real
 * applicant it is the correct outcome: a suggestion that contradicts the
 * dropdown is not offered. What happens instead is the caller's business — see
 * the barangay-centre fallback in ApplyWizard.
 *
 * ── Being a good citizen of a free service ──────────────────────────────────
 *
 * Nominatim's usage policy: at most one request a second, an identifying
 * Referer or User-Agent, and no heavy use. What we do about each:
 *
 *   - Volume. The caller debounces (a lookup follows a pause in typing, not a
 *     keystroke); `throttle` below spaces requests a full second apart even
 *     when two pauses come close together, which the debounce alone did not
 *     guarantee; and answers are cached by STREET, so choosing or correcting
 *     the barangay afterwards re-filters the cached answer instead of asking
 *     again.
 *   - Identification. A browser cannot set User-Agent. The Referer is what
 *     identifies us, so the request pins `referrerPolicy` to send the origin —
 *     never the path, which carries draft ids — even if the page's own policy
 *     is later tightened to no-referrer, as the API's already is.
 *   - The search is bounded to Malabon's box, so the service does less work and
 *     the answers cannot wander into another city.
 *
 * The policy also says Nominatim must not back a client-side autocomplete. A
 * lookup on a pause in typing, at most once a second, cached, and only once a
 * trade is chosen and the street is four characters long, is kept well short
 * of that; but it is close enough to the line that anything which makes it
 * fire more often (a shorter debounce, per-keystroke lookups) needs its own
 * geocoder, not this one.
 *
 * `bounded=1` is not a substitute for checking the result: a bounding box is a
 * rectangle and Malabon is not. Every answer is put through `withinMalabon`,
 * and then `checkPin` against the chosen barangay, before it is offered.
 */

/** Malabon's bounding box, read off the outline so the two cannot drift apart. */
const BOX = MALABON_OUTLINE.reduce(
  (box, [lng, lat]) => ({
    minLng: Math.min(box.minLng, lng),
    minLat: Math.min(box.minLat, lat),
    maxLng: Math.max(box.maxLng, lng),
    maxLat: Math.max(box.maxLat, lat),
  }),
  { minLng: Infinity, minLat: Infinity, maxLng: -Infinity, maxLat: -Infinity },
)

const ENDPOINT = 'https://nominatim.openstreetmap.org/search'

/** Nominatim's policy ceiling is one request a second; this is that, plus slack. */
const MIN_GAP_MS = 1100

export interface GeocodeHit {
  latitude: number
  longitude: number
  /** The street OSM matched, for telling the applicant where the pin went. */
  label: string
}

/** One answer from Nominatim, reduced to what is used. */
interface Candidate {
  latitude: number
  longitude: number
  name: string
  /** The road's shape, as [lng, lat] lines, when OSM sent one. */
  lines: [number, number][][]
}

/** Answers by street query, so re-asking — or re-filtering — costs nothing. */
const cache = new Map<string, Candidate[]>()

/**
 * What goes to Nominatim: the street, and nothing it cannot match.
 *
 * Exported for the measurement's sake — the rules here are the ones the numbers
 * above were taken with, and a change to them should be re-measured.
 */
export function streetQuery(line1: string): string {
  let s = line1.trim()
  // "Blk 5 Lot 12", "Block 5, Lot 12", "B5 L12" typed into the street box.
  // Digits required, so "Bonifacio Lane" is not read as a block and a lot.
  s = s.replace(/^(?:blk\.?|block|b)\s*\d+[\w-]*[,\s]+(?:lot|l)\.?\s*\d+[\w-]*[,\s]*/i, '')
  // A leading house number: "24", "24-B", "#24", "No. 24", "Unit 3" — but
  // not the number of a numbered street ("5th Street" keeps its "5th").
  const unnumbered = s.replace(/^(?:no\.?\s*|#\s*|unit\s+)?\d+[\w-]*[,\s]+/i, '')
  if (!/^(street|st\.?|avenue|ave\.?|road)$/i.test(unnumbered.trim())) s = unnumbered
  // "Rizal Ave. St." is how the seed data writes an avenue; OSM has "Rizal Avenue".
  s = s.replace(/\bAve\.?\s+St\.?$/i, 'Avenue')
  s = s.replace(/\bSt\.?(?=$|,)/i, 'Street').replace(/\bAve\.?(?=$|,)/i, 'Avenue')
  return s.trim()
}

let lastRequestAt = 0

/**
 * Wait until a request would be at least `MIN_GAP_MS` after the previous one.
 * Resolves false if the caller gave up while waiting, so nothing is sent for a
 * lookup nobody wants any more.
 */
async function throttle(signal?: AbortSignal): Promise<boolean> {
  const wait = lastRequestAt + MIN_GAP_MS - Date.now()
  if (wait > 0) {
    await new Promise((resolve) => setTimeout(resolve, wait))
  }
  if (signal?.aborted) return false
  lastRequestAt = Date.now()
  return true
}

async function search(query: string, signal?: AbortSignal): Promise<Candidate[] | null> {
  const cached = cache.get(query)
  if (cached) return cached
  if (!(await throttle(signal))) return null

  const url =
    `${ENDPOINT}?format=jsonv2&limit=5&countrycodes=ph&bounded=1` +
    // The road's shape, simplified to ~10 m: enough to find the stretch inside
    // one barangay, and a few kilobytes at most.
    `&polygon_geojson=1&polygon_threshold=0.0001` +
    `&viewbox=${BOX.minLng},${BOX.maxLat},${BOX.maxLng},${BOX.minLat}` +
    `&q=${encodeURIComponent(`${query}, Malabon City, Metro Manila, Philippines`)}`

  try {
    const res = await fetch(url, {
      signal,
      headers: { Accept: 'application/json' },
      referrerPolicy: 'strict-origin-when-cross-origin',
    })
    if (!res.ok) return null

    const body: unknown = await res.json()
    const candidates: Candidate[] = []
    for (const raw of Array.isArray(body) ? body : []) {
      const r = raw as {
        lat?: string
        lon?: string
        name?: string
        display_name?: string
        category?: string
        geojson?: { type?: string; coordinates?: unknown }
      }
      const latitude = Number.parseFloat(r.lat ?? '')
      const longitude = Number.parseFloat(r.lon ?? '')
      if (Number.isNaN(latitude) || Number.isNaN(longitude)) continue
      /*
       * Roads only. Asked for "San Bartolome Street", OSM's best answer in
       * Tañong was the San Bartolome parish church: a real place, on or near
       * that street, and not what was typed. A pin on a church labelled as the
       * applicant's street is a wrong answer said confidently, which is worse
       * than the centre-of-barangay start the caller falls back to.
       */
      if (r.category !== 'highway') continue
      const g = r.geojson
      const lines =
        g?.type === 'LineString'
          ? [g.coordinates as [number, number][]]
          : g?.type === 'MultiLineString'
            ? (g.coordinates as [number, number][][])
            : []
      candidates.push({
        latitude,
        longitude,
        name: r.name || r.display_name?.split(',')[0] || query,
        lines,
      })
    }
    cache.set(query, candidates)
    return candidates
  } catch {
    // Includes AbortError, which is the ordinary case when typing resumes.
    // Deliberately not cached: a network failure is not an answer about the
    // address, and caching it would make one bad moment permanent.
    return null
  }
}

const ROAD_WORD = /\b(street|avenue|road|highway|boulevard|drive|lane|extension|st|ave|rd)\b/i
const GENERIC = new Set(['street', 'avenue', 'road', 'extension', 'the'])

/**
 * Whether OSM's road is plausibly the one typed.
 *
 * Nominatim ranks loosely, and "San Bartolome Street" came back as a service
 * road named "San Bartolome Cemetery". So a candidate must share a real word
 * of the name with what was typed ("Sevilla" in "F. Sevilla Street"), and, if
 * the applicant said Street or Avenue, be named as a road itself. Initials and
 * abbreviations are not compared, because OSM and the applicant rarely write
 * them the same way ("J. P. Rizal Street" for "Rizal Street").
 */
function namesMatch(query: string, name: string): boolean {
  const words = query
    .toLowerCase()
    .split(/[^a-zñ]+/)
    .filter((w) => w.length >= 3 && !GENERIC.has(w))
  const target = name.toLowerCase()
  if (words.length > 0 && !words.some((w) => target.includes(w))) return false
  return !ROAD_WORD.test(query) || ROAD_WORD.test(name)
}

/** Metres per degree at Malabon's latitude, for stepping along a road. */
const M_PER_DEG_LAT = 110574
const M_PER_DEG_LNG = 111320 * Math.cos((14.66 * Math.PI) / 180)

/**
 * A point on the road that lies inside `barangay`, or null.
 *
 * Walks each segment in ~20 m steps — a road's stored vertices can be 400 m
 * apart, and the stretch inside a small barangay may fall between two of them.
 * Prefers points strictly inside the barangay over points merely within the
 * boundary tolerance, and takes the middle one, so the pin sits well inside
 * rather than on the seam.
 */
function pointOnRoadIn(lines: [number, number][][], barangay: string): [number, number] | null {
  const inside: [number, number][] = []
  const near: [number, number][] = []
  for (const line of lines) {
    for (let i = 0; i < line.length - 1; i++) {
      const [lng1, lat1] = line[i]
      const [lng2, lat2] = line[i + 1]
      const metres = Math.hypot((lat2 - lat1) * M_PER_DEG_LAT, (lng2 - lng1) * M_PER_DEG_LNG)
      const steps = Math.max(1, Math.ceil(metres / 20))
      for (let k = 0; k <= steps; k++) {
        const lat = lat1 + ((lat2 - lat1) * k) / steps
        const lng = lng1 + ((lng2 - lng1) * k) / steps
        if (!withinMalabon(lat, lng)) continue
        if (barangayContaining(lat, lng) === barangay) inside.push([lat, lng])
        else if (checkPin(lat, lng, barangay).kind === 'ok') near.push([lat, lng])
      }
    }
  }
  const pool = inside.length > 0 ? inside : near
  return pool.length > 0 ? pool[Math.floor(pool.length / 2)] : null
}

/**
 * Look up a street address inside Malabon, and inside the chosen barangay when
 * there is one.
 *
 * Returns null for "nothing usable", which covers every failure the caller
 * treats the same way: no match, no match in that barangay, a network error,
 * or an aborted request.
 */
export async function geocodeInMalabon(
  line1: string,
  barangay: string | null,
  signal?: AbortSignal,
): Promise<GeocodeHit | null> {
  const query = streetQuery(line1)
  if (query.length < 4) return null

  const found = await search(query, signal)
  if (found === null || signal?.aborted) return null
  const candidates = found.filter((c) => namesMatch(query, c.name))

  const hit = (latitude: number, longitude: number, name: string): GeocodeHit => ({
    latitude: Number(latitude.toFixed(6)),
    longitude: Number(longitude.toFixed(6)),
    label: name,
  })

  // OSM's own point for the road, when it already agrees with the dropdown.
  for (const c of candidates) {
    if (checkPin(c.latitude, c.longitude, barangay).kind === 'ok') {
      return hit(c.latitude, c.longitude, c.name)
    }
  }
  // Otherwise the stretch of the same road that runs through that barangay.
  if (barangay) {
    for (const c of candidates) {
      const p = pointOnRoadIn(c.lines, barangay)
      if (p) return hit(p[0], p[1], c.name)
    }
  }
  return null
}
