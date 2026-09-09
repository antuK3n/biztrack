import { MALABON_OUTLINE } from './malabonGeo.data'
import { withinMalabon } from './malabonGeo'

/**
 * Turning a typed address into a pin, the way a ride-hailing app does.
 *
 * Checklist item 7 — "There should be automatic pinning of maps upon entering
 * the house number and street address, but still allow the user to manually
 * change the pin for more accuracy if he/she wishes to."
 *
 * ── What this can and cannot do ─────────────────────────────────────────────
 *
 * Nominatim reads OpenStreetMap, and OSM's coverage of Malabon below the main
 * roads is thin — the satellite layer exists on this very map because many
 * alleys carry no name at all. So a lookup will often return the centre of a
 * street rather than the premises, and frequently return nothing.
 *
 * That is why this only ever SUGGESTS. The pin it drops is the applicant's to
 * move, the map still takes a click anywhere, and a failed lookup is silent —
 * an applicant who was going to click the map anyway must not be handed an
 * error about a service they never asked for.
 *
 * ── Being a good citizen of a free service ──────────────────────────────────
 *
 * Nominatim's usage policy allows roughly one request a second and asks callers
 * to identify themselves. A browser cannot set User-Agent, so the Referer is
 * what identifies us; what we can control is volume, and this does three
 * things about it:
 *
 *   - the caller debounces, so a lookup follows a pause in typing, not a
 *     keystroke;
 *   - identical queries are answered from `cache` without a second request,
 *     which matters because correcting a barangay re-asks the same address;
 *   - the search is BOUNDED to Malabon's own bounding box, so the service does
 *     less work per call and the answers cannot wander into another city.
 *
 * `bounded=1` is not a substitute for checking the result: a bounding box is a
 * rectangle and Malabon is not, so a point inside the box can still be in
 * Navotas or Caloocan. Every hit is put through `withinMalabon` before it is
 * offered, which is the same polygon test the map's own click handler uses.
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

/** Answered queries, so re-asking the same address costs nothing. */
const cache = new Map<string, GeocodeHit | null>()

export interface GeocodeHit {
  latitude: number
  longitude: number
  /** What OSM thinks it matched, for showing the applicant what was found. */
  label: string
}

/**
 * Look up a street address inside Malabon.
 *
 * Returns null for "nothing usable", which covers every failure the caller
 * should treat the same way: no match, a match outside the city, a network
 * error, or an aborted request. None of them is worth interrupting somebody
 * over — the map is still there to be clicked.
 */
export async function geocodeInMalabon(
  line1: string,
  barangay: string | null,
  signal?: AbortSignal,
): Promise<GeocodeHit | null> {
  const street = line1.trim()
  if (street.length < 4) return null

  /*
   * The barangay is included when known, because it is what disambiguates a
   * street name that repeats across the city, and Malabon has several. It is
   * NOT required: the whole point of this feature is that it fires while the
   * applicant is still filling the form in, and the address usually arrives
   * before the dropdown is touched.
   */
  const query = [street, barangay, 'Malabon City', 'Metro Manila', 'Philippines']
    .filter(Boolean)
    .join(', ')

  if (cache.has(query)) return cache.get(query) ?? null

  const url =
    `${ENDPOINT}?format=jsonv2&limit=1&countrycodes=ph&bounded=1` +
    `&viewbox=${BOX.minLng},${BOX.maxLat},${BOX.maxLng},${BOX.minLat}` +
    `&q=${encodeURIComponent(query)}`

  try {
    const res = await fetch(url, { signal, headers: { Accept: 'application/json' } })
    if (!res.ok) return null

    const body: unknown = await res.json()
    if (!Array.isArray(body) || body.length === 0) {
      cache.set(query, null)
      return null
    }

    const first = body[0] as { lat?: string; lon?: string; display_name?: string }
    const latitude = Number.parseFloat(first.lat ?? '')
    const longitude = Number.parseFloat(first.lon ?? '')
    if (Number.isNaN(latitude) || Number.isNaN(longitude)) {
      cache.set(query, null)
      return null
    }

    // The box is a rectangle and the city is not. Same polygon test the click
    // handler runs, so a suggested pin can never be one the map would refuse.
    if (!withinMalabon(latitude, longitude)) {
      cache.set(query, null)
      return null
    }

    const hit: GeocodeHit = {
      latitude: Number(latitude.toFixed(6)),
      longitude: Number(longitude.toFixed(6)),
      label: first.display_name ?? query,
    }
    cache.set(query, hit)
    return hit
  } catch {
    // Includes AbortError, which is the ordinary case when typing resumes.
    // Deliberately not cached: a network failure is not an answer about the
    // address, and caching it would make one bad moment permanent.
    return null
  }
}
