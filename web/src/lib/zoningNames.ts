/*
 * What each zone is FOR, in words a shop owner uses — the one place a zone
 * code becomes a name on screen.
 *
 * ── Why ──────────────────────────────────────────────────────────────────────
 *
 * The step used to print CPDO's legend codes as they are on the sheet: "R-2
 * Basic or R-2 Max", "C-1", "CMP". The client's lead, looking at the applicant's
 * zoning step (24 September 2026): "do the public know what R2 R1 is?" They do
 * not, and the step is for the public. So every surface an applicant reads —
 * the map key, the zone tooltip, the zone chips on the barangay card, the
 * ordinance note — names a zone by what the ordinance says it is for, through
 * `plainZoneName` and nothing else, so the four can never name one zone two
 * ways.
 *
 * Codes survive only where an officer reads them (`zoneLabel` with
 * `withCode`): the officer's review map, where "R-2" is the working vocabulary
 * and the plain name alone would make them translate back.
 *
 * ── Where each name comes from ──────────────────────────────────────────────
 *
 * City Ordinance No. 24-2018, Art. V §2, via docs/zoning-ordinance/zone-uses-
 * summary.md. Each name says what the zone's allowed-use list adds over the
 * zone below it, because that difference is the thing an applicant is asking
 * about:
 *
 *   R-1            §2.1   single homes; home businesses only inside the home
 *   R-2 Basic      §2.2   R-1 + apartments, boarding houses, dorms, schools
 *   R-2 Max        §2.3   R-1 + Basic R-2 + retail, eateries, service shops
 *   R-3 Basic      §2.4   R-1 + Basic R-2 + condominiums, pension houses, hotels
 *   R-3 Max        §2.5   R-1 + both R-2s (so the shops) + condos, hotels
 *   CMP            §2.6   Socialized Housing Zone (CMP = the Community
 *                         Mortgage Program projects the boundaries name)
 *   C-1            §2.7   "neighborhood or community scale trade, service"
 *   C-2            §2.8   C-1 + wholesale, markets, malls, bars, terminals,
 *                         workshops; "medium to high density commercial"
 *   C-3            §2.9   "regional shopping centers such as large malls"
 *   General Comm.  §2.10  "trading/services/business purposes", the widest list
 *   CBD            §2.11  "large-scale trading, services, business … financial"
 *   I-1            §2.12  "light manufacturing … non-pollutive"
 *   I-2            §2.13  "medium intensity manufacturing … pollutive"
 *   Easement       §2.14  riverbanks; "no building or any structure"
 *   Mangrove       §2.15  "no permanent buildings or structures"
 *   Fishpond       §2.16  (lists no uses at all)
 *   Parks          §2.17  parks, playgrounds, sports, resorts
 *   Cemetery       §2.18  cemeteries, memorial parks, columbaria
 *   Utilities      §2.19  terminals, depots, power, water, telecoms
 *   Institutional  §2.20  government offices, schools, hospitals, churches
 *
 * R-2 Basic and R-2 Max share one colour on the sheet, so the tracer cannot
 * split them and the map draws them as one area. That area gets one name that
 * is true of all of it — homes and apartments everywhere, small shops in parts
 * — rather than claiming shops for the Basic half.
 *
 * A code not in this table (the ordinance can add one; see the note on
 * `ZoningClassification`) falls back to the name the database holds for it.
 * A wrong plain name would be worse than an unexplained one.
 */

const PLAIN: Record<string, string> = {
  'R-1': 'Homes',
  'R-2-BASIC': 'Homes and apartments',
  'R-2-MAX': 'Homes and small shops',
  'R-3-BASIC': 'Homes, condos and hotels',
  'R-3-MAX': 'Homes, condos and small shops',
  CMP: 'Socialized housing',
  'C-1': 'Neighborhood shops and services',
  'C-2': 'Larger shops, markets and services',
  'C-3': 'Malls and large businesses',
  'GENERAL-COMMERCIAL': 'Shops, services and trade',
  CBD: 'Main business district',
  'I-1': 'Light industry',
  'I-2': 'Industry',
  EASEMENT: 'Riverbank strip, no building',
  MANGROVE: 'Mangroves',
  FISHPOND: 'Fishponds',
  PARKS: 'Parks and recreation',
  CEMETERY: 'Cemeteries',
  UTILITIES: 'Utilities and terminals',
  INSTITUTIONAL: 'Government, schools and churches',
}

/** The one traced area that is two classifications at once; see above. */
const R2_EITHER = 'Homes and apartments, some small shops'

/**
 * The plain name for a zone, from its code (or codes, for the traced R-2 pair).
 * `fallback` is the database's own name, used only for a code this table does
 * not know.
 */
export function plainZoneName(codes: string | readonly string[], fallback: string): string {
  const list = typeof codes === 'string' ? [codes] : codes
  if (list.length === 2 && list.includes('R-2-BASIC') && list.includes('R-2-MAX')) return R2_EITHER
  if (list.length === 1) return PLAIN[list[0]] ?? fallback
  return fallback
}

/**
 * The label a zone gets on screen. For the public, the plain name alone; for
 * an officer, the plain name with the sheet's code after it, "Homes and small
 * shops (R-2 Max)", so the code they work in is still there to read.
 */
export function zoneLabel(
  codes: string | readonly string[],
  sheetName: string,
  { withCode = false }: { withCode?: boolean } = {},
): string {
  const plain = plainZoneName(codes, sheetName)
  return withCode && plain !== sheetName ? `${plain} (${sheetName})` : plain
}

/*
 * The overlays, in the same spirit (Ordinance 24-2018 Art. V §4). An overlay
 * is a "transparent zone" laid over the base zones that adds rules; it is not
 * a zone of its own, and the card keeps it apart from the list above.
 *
 * Flood is the one to word carefully. It is a designation the ordinance makes
 * over areas "identified as prone to flooding" (§4.1), not a finding about any
 * applicant's lot, and it adds building rules and no use restrictions. So the
 * name is the ordinance's own description, "flood-prone areas", plural and
 * about areas; the meaning says what the rule asks of a building and stops.
 */
const OVERLAY_PLAIN: Record<string, { name: string; meaning: string }> = {
  'FLD-OZ': {
    name: 'Flood-prone areas',
    meaning:
      'Parts of the city the zoning rules mark as prone to flooding. New buildings there need the ground floor raised above the expected flood level. It does not change what a place may be used for.',
  },
  'HTG-OZ': {
    name: 'Heritage houses',
    meaning:
      'Areas with declared heritage houses. Those houses must keep their original look and size, and their uses are limited. New buildings near them must match their style and not stand taller.',
  },
  'ETM-OZ': {
    name: 'Eco-tourism fishponds',
    meaning:
      'The fishponds of Dampalit. Tourism businesses such as restaurants and souvenir shops are also allowed there, with a limit on how much of a lot can be built on.',
  },
}

/** An overlay's plain name and meaning; the database's wording for one this table does not know. */
export function plainOverlay(
  code: string,
  name: string,
  description: string | null,
): { name: string; meaning: string | null } {
  return OVERLAY_PLAIN[code] ?? { name, meaning: description }
}
