/**
 * The fields BPLO can point at when it returns the main application form.
 *
 * ── Why this list exists ─────────────────────────────────────────────────────
 *
 * Client, 24 September 2026: *"allow me to choose a field that the business
 * owner will have to comply to. Then, I should also put a reason why."*
 *
 * An office returning ONE permit has been able to name its subject since the
 * office sheets were built — `remarks_target` on the clearance row, picked from
 * that sheet's own checklist rows and answer keys. BPLO returning the main form
 * had the prose and nothing else, so "please correct your trade name" was a
 * sentence the applicant had to match against fifty-odd questions across five
 * sections, in a wizard that reopens on the section it left off at.
 *
 * ── Why a list and not a parse of the prose ──────────────────────────────────
 *
 * The rule the office sheets already follow, and the client's own worry from
 * 17 September 2026: *"how can a free text match what is specifically asked.
 * There could be database matching issues for this."* Nothing matches the text.
 * The officer writes whatever they want and separately ticks a code, and every
 * reader does a key lookup — immune to synonyms, to Filipino, and to typos.
 *
 * ── Why the codes are namespaced ─────────────────────────────────────────────
 *
 * One column, `application_assignments.remarks_target`, now carries four kinds
 * of pointer: a `document_types.code`, an office-form answer key, a permit type
 * code (BPLO sending back one clearance at Final Approval) and these. The
 * `form:` prefix is what keeps a field called `email` from colliding with a
 * document or a permit that happens to share the word, and what lets a reader
 * tell at a glance which kind of thing it is looking at.
 *
 * ── Why the labels carry the numbers ─────────────────────────────────────────
 *
 * Because the applicant's form does. A returned filing that says "fix item 5"
 * over a form whose fifth question is headed "5. Trade Name / Franchise" is one
 * lookup; "fix the trade name" is a hunt. The numbers here must be kept in step
 * with the wizard's — they are the same questions, and the wizard's own
 * numbering has changed twice this month.
 */

/** One field an officer may send a filing back about. */
export type ReturnTarget = {
  /** The stored code. Namespaced `form:` — see the note above. */
  value: string
  /** What the officer picks and the applicant reads. */
  label: string
  /** The wizard section it belongs to, used to group the picker. */
  group: string
}

const A = 'A · Business Information'
const B = 'B · Business Operation'
const L = 'Location & Zoning'
const D = 'Documents & Declarations'

/**
 * In the order the applicant is asked, which is the order the picker offers.
 *
 * Grouped rather than flat: an officer scanning for "the barangay" should not
 * have to read past seventeen registration questions, and the group headings
 * are the section names the wizard's own step bar uses.
 */
export const MAIN_FORM_RETURN_TARGETS: ReturnTarget[] = [
  { value: 'form:registration_type', label: '1. Form of Organization', group: A },
  { value: 'form:registration_number', label: '2. Registration Number', group: A },
  { value: 'form:tin', label: '3. Tax Identification Number (TIN)', group: A },
  { value: 'form:name', label: '4. Business Name', group: A },
  { value: 'form:trade_name', label: '5. Trade Name / Franchise', group: A },
  { value: 'form:telephone', label: '6. Telephone (Landline)', group: A },
  { value: 'form:mobile_number', label: '7. Mobile Number', group: A },
  { value: 'form:email', label: '8. E-mail Address', group: A },
  { value: 'form:website', label: '9. Website Address', group: A },
  /*
   * One entry for items 10 to 13, because they are one answer in four boxes and
   * an officer who wants a corrected name means the name, not the middle
   * initial. Gender is separate because it is a separate question with a
   * separate way of being wrong.
   */
  { value: 'form:owner_name', label: '10–13. Owner / Representative', group: A },
  { value: 'form:owner_gender', label: '14. Gender', group: A },
  { value: 'form:president_officer_name', label: '15. Name of President / OIC', group: A },
  { value: 'form:citizenship', label: '16. Citizenship (of President/OIC)', group: A },
  { value: 'form:capital_participation', label: '17. Capital Participation', group: A },

  { value: 'form:floor_area_sqm', label: '1. Business Area (sq. m.)', group: B },
  { value: 'form:employees', label: '2. Total No. of Employees', group: B },
  { value: 'form:employees_in_lgu', label: '3. Employees Residing within Malabon', group: B },
  { value: 'form:delivery_units', label: '4. No. of Delivery Units', group: B },
  { value: 'form:economic_organization', label: '5. Economic Organization', group: B },
  { value: 'form:capital_investment', label: '6. Capital Investment', group: B },
  { value: 'form:has_tax_incentives', label: '7. Tax incentives from a Government Entity', group: B },
  { value: 'form:is_rented', label: '8. Do you pay rent for the premises', group: B },

  { value: 'form:address', label: 'Business address', group: L },
  { value: 'form:barangay', label: 'Barangay', group: L },
  { value: 'form:map_pin', label: 'Pin on the map', group: L },
  { value: 'form:lines', label: 'Line of business / Products / Services', group: L },
  { value: 'form:lessor', label: 'Lessor details', group: L },
  { value: 'form:emergency_contact', label: 'Emergency contact', group: L },

  { value: 'form:documents', label: 'Uploaded documents', group: D },
  { value: 'form:fee_profile', label: 'Tax classification answers', group: D },
]

const BY_VALUE = new Map(MAIN_FORM_RETURN_TARGETS.map((t) => [t.value, t]))

/**
 * The label for a stored pointer, or null when this is not one of ours.
 *
 * Null rather than the raw code, deliberately. The same column also holds
 * document codes, office-form keys and permit codes, and printing
 * `CHO_SANITARY_PERMIT` under a heading that says "What to fix" would read as a
 * field name to an applicant who has never seen one. A caller that gets null
 * shows the remarks alone, which is what every returned filing looked like
 * before this existed.
 */
export function mainFormTargetLabel(code: string | null | undefined): string | null {
  if (!code) return null

  return BY_VALUE.get(code)?.label ?? null
}
