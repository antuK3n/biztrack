/**
 * What KIND of trade a PSIC code is, for grouping the line-of-business picker.
 *
 * Checklist item 6 — "Lines of businesses in zoning should be categorized so
 * users won't have to scroll too much." The picker already lifted eight common
 * trades to the top, which helps the applicant whose trade is one of the eight
 * and nobody else: the remaining 127 sat in one unbroken scroll, so finding
 * "Repair of motorcycles" meant reading past bakeries, clinics and funeral
 * parlours with no signpost that the subject had changed.
 *
 * ── Why this is derived and not stored ──────────────────────────────────────
 *
 * `psic_codes` HAS a `category` column. It is empty for all 135 rows and no
 * endpoint exposes it, so using it would mean populating it by hand, shipping a
 * migration against the live register, and widening the reference payload —
 * three moving parts to express something the code already states.
 *
 * The PSIC numbering is not arbitrary. Its first two digits are the division,
 * and divisions group into sections by fixed ranges published with the
 * standard; 47111 is division 47, which is retail, in every edition. Reading
 * the section off the code is therefore not a heuristic about our data, it is
 * the classification's own structure.
 *
 * The stored column is still the right long-term home — an LGU that wants to
 * re-file a trade under a different heading should not need a deploy — so it is
 * left in place rather than dropped. See docs/questions-for-malabon.md.
 *
 * ── Labels ──────────────────────────────────────────────────────────────────
 *
 * Plain language, not the standard's own titles. "Wholesale and retail trade;
 * repair of motor vehicles and motorcycles" is the official section A name and
 * is nobody's idea of a heading to skim. The applicant is looking for their
 * shop, not for a statistical classification.
 */

/** Section headings in the order they are shown. */
export const PSIC_SECTION_ORDER = [
  'Shops & Retail',
  'Food & Drink',
  'Personal & Repair Services',
  'Manufacturing & Production',
  'Construction',
  'Transport & Storage',
  'Professional Services',
  'Administrative & Support',
  'Health & Social Care',
  'Education',
  'Arts, Recreation & Entertainment',
  'Information & Communication',
  'Finance & Insurance',
  'Real Estate',
  'Agriculture & Fishing',
  'Mining & Quarrying',
  'Utilities & Waste',
  'Other Trades',
] as const

export type PsicSection = (typeof PSIC_SECTION_ORDER)[number]

/**
 * The division ranges, straight from the PSIC. Inclusive on both ends.
 *
 * Ordered most-likely-first for a city business permit rather than by division
 * number: a Malabon counter sees far more sari-sari stores and carinderias than
 * mining concerns, and the first ranges here are the ones the list opens on.
 */
const RANGES: { from: number; to: number; section: PsicSection }[] = [
  { from: 45, to: 47, section: 'Shops & Retail' },
  { from: 55, to: 56, section: 'Food & Drink' },
  { from: 94, to: 96, section: 'Personal & Repair Services' },
  { from: 10, to: 33, section: 'Manufacturing & Production' },
  { from: 41, to: 43, section: 'Construction' },
  { from: 49, to: 53, section: 'Transport & Storage' },
  { from: 69, to: 75, section: 'Professional Services' },
  { from: 77, to: 82, section: 'Administrative & Support' },
  { from: 86, to: 88, section: 'Health & Social Care' },
  { from: 85, to: 85, section: 'Education' },
  { from: 90, to: 93, section: 'Arts, Recreation & Entertainment' },
  { from: 58, to: 63, section: 'Information & Communication' },
  { from: 64, to: 66, section: 'Finance & Insurance' },
  { from: 68, to: 68, section: 'Real Estate' },
  { from: 1, to: 3, section: 'Agriculture & Fishing' },
  { from: 5, to: 9, section: 'Mining & Quarrying' },
  { from: 35, to: 39, section: 'Utilities & Waste' },
]

/**
 * The section a PSIC code belongs to.
 *
 * Falls back to "Other Trades" rather than throwing or guessing: a code outside
 * every published range means the reference data has something we did not
 * expect in it, and burying that trade is worse than heading it honestly. The
 * catch-all 00000 lands here too, though the picker filters it out before this
 * is ever asked.
 */
export function psicSection(code: string): PsicSection {
  const division = Number.parseInt(code.slice(0, 2), 10)
  if (Number.isNaN(division)) return 'Other Trades'
  return RANGES.find((r) => division >= r.from && division <= r.to)?.section ?? 'Other Trades'
}

/** Where a section sits in the list, for sorting. Unknown sections sort last. */
export function psicSectionRank(section: PsicSection): number {
  const i = PSIC_SECTION_ORDER.indexOf(section)
  return i === -1 ? PSIC_SECTION_ORDER.length : i
}
