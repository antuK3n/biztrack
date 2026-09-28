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

/**
 * How much of the form a target covers, which decides where the applicant
 * fixes it.
 *
 * `scalar` — one box. Drawn inline on the status page, under the remark
 *   that asked for it, with a Resubmit button. No wizard.
 * `section` — a repeating table, a checklist or a cluster of boxes that is
 *   only nameable as a whole. Opens the wizard at that step, with every
 *   other step locked.
 */
export type ReturnTargetKind = 'scalar' | 'section'

/** One field an officer may send a filing back about. */
export type ReturnTarget = {
  /** The stored code. Namespaced `form:` — see the note above. */
  value: string
  /** What the officer picks and the applicant reads. */
  label: string
  /** The wizard section it belongs to, used to group the picker. */
  group: string
  /** One box, or a whole step. See ReturnTargetKind. */
  kind: ReturnTargetKind
  /**
   * The wizard step that owns this target, for `section` targets only.
   *
   * A returned filing opens on these steps and no others, which is how
   * "comply to those selected fields only" is enforced for the targets too
   * big to draw inline. The names are `BasePhase` values from ApplyWizard.
   */
  phase?: string
  /*
   * There is no `field` here, and there was until 27 September 2026.
   *
   * It named the column a correction writes, and NOTHING READ IT: the
   * correction form posts its answers keyed by CODE and the API resolves
   * the column, which is the right way round — a request body must not
   * choose a column. So this was a second copy of a mapping the browser
   * never used, and the parity test was faithfully comparing it against
   * the first copy while eight entries in BOTH named columns that do not
   * exist. Agreeing is not the same as being right.
   *
   * The columns live in App\Support\ReturnTargets, where the write
   * happens, and are checked against the real schema by
   * ReturnTargetSchemaTest.
   */
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
  { value: 'form:registration_type', label: '1. Form of Organization', group: A, kind: 'section', phase: 'business' },
  { value: 'form:registration_number', label: '2. Registration Number', group: A, kind: 'scalar' },
  { value: 'form:tin', label: '3. Tax Identification Number (TIN)', group: A, kind: 'scalar' },
  { value: 'form:name', label: '4. Business Name', group: A, kind: 'scalar' },
  { value: 'form:trade_name', label: '5. Trade Name / Franchise', group: A, kind: 'scalar' },
  { value: 'form:telephone', label: '6. Telephone (Landline)', group: A, kind: 'scalar' },
  { value: 'form:mobile_number', label: '7. Mobile Number', group: A, kind: 'scalar' },
  { value: 'form:email', label: '8. E-mail Address', group: A, kind: 'scalar' },
  { value: 'form:website', label: '9. Website Address', group: A, kind: 'scalar' },
  /*
   * One entry for items 10 to 13, because they are one answer in four boxes and
   * an officer who wants a corrected name means the name, not the middle
   * initial. Gender is separate because it is a separate question with a
   * separate way of being wrong.
   */
  { value: 'form:owner_surname', label: '10. Surname', group: A, kind: 'scalar' },
  { value: 'form:owner_given_name', label: '11. Given Name', group: A, kind: 'scalar' },
  { value: 'form:owner_middle_name', label: '12. Middle Name', group: A, kind: 'scalar' },
  { value: 'form:owner_suffix', label: '13. Suffix', group: A, kind: 'scalar' },
  { value: 'form:owner_gender', label: '14. Gender', group: A, kind: 'section', phase: 'business' },
  { value: 'form:president_officer_name', label: '15. Name of President / Officer in Charge', group: A, kind: 'scalar' },
  { value: 'form:citizenship', label: '16. Citizenship (of President/OIC)', group: A, kind: 'scalar' },
  { value: 'form:capital_participation', label: '17. Capital Participation (% Filipino)', group: A, kind: 'scalar' },

  { value: 'form:floor_area_sqm', label: '1. Business Area (sq. m.)', group: B, kind: 'scalar' },
  { value: 'form:employees', label: '2. Total No. of Employees', group: B, kind: 'section', phase: 'operation' },
  { value: 'form:employees_in_lgu', label: '3. Employees Residing in Malabon', group: B, kind: 'scalar' },
  { value: 'form:delivery_units', label: '4. Delivery Units', group: B, kind: 'scalar' },
  { value: 'form:economic_organization', label: '5. Economic Organization', group: B, kind: 'section', phase: 'operation' },
  { value: 'form:capital_investment', label: '6. Capital Investment (₱)', group: B, kind: 'scalar' },
  { value: 'form:has_tax_incentives', label: '7. Tax incentives from a Government Entity?', group: B, kind: 'section', phase: 'operation' },
  { value: 'form:is_rented', label: '8. Do you pay rent for the premises?', group: B, kind: 'section', phase: 'address' },

  { value: 'form:address', label: 'House / Bldg. No. and Street', group: L, kind: 'section', phase: 'address' },
  { value: 'form:barangay', label: 'Barangay Name', group: L, kind: 'section', phase: 'address' },
  { value: 'form:map_pin', label: 'Pin on the map', group: L, kind: 'section', phase: 'address' },
  { value: 'form:lines', label: 'Line of Business', group: L, kind: 'section', phase: 'address' },
  /*
   * `form:lessor` was here and is gone — the wizard STOPPED COLLECTING the
   * lessor block, and not one of its inputs renders any more. Offering it
   * let an officer return a filing about four boxes the applicant could
   * never find. The same staleness hit the officer's review sheet, which
   * printed those four rows as dashes for months; see ApplyWizard's note on
   * `ReviewRow`. If the block comes back, so does this line.
   */
  { value: 'form:emergency_contact_name', label: 'Emergency Contact Person', group: L, kind: 'scalar' },
  { value: 'form:emergency_contact_number', label: 'Emergency Contact Number', group: L, kind: 'scalar' },

  { value: 'form:documents', label: 'Uploaded documents', group: D, kind: 'section', phase: 'documents' },
  { value: 'form:fee_profile', label: 'Tax classification answers', group: D, kind: 'section', phase: 'operation' },
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

/**
 * The whole target for a stored pointer, or null when it is not one of ours.
 *
 * `mainFormTargetLabel` answers "what do I call this" and is what the
 * read-only screens want. This answers "what do I draw for this", which the
 * correction form needs: its `kind` decides inline box versus wizard step,
 * and its `field` names the column to write.
 */
export function mainFormTarget(code: string | null | undefined): ReturnTarget | null {
  if (!code) return null

  return BY_VALUE.get(code) ?? null
}

/**
 * Read the stored pointer column, which may now name SEVERAL fields.
 *
 * One return used to mean one field. Client, 27 September 2026: *"the admin
 * can choose which field is wrong ... comply to those SELECTED FIELDS
 * only"* — plural, and a single code cannot say that.
 *
 * Comma-separated in the existing column rather than a new table. Every
 * value is a short code the system owns, there is no ordering or history to
 * keep per field, and a row already in the database is a valid list of one —
 * so nothing has to be migrated and every old return keeps working.
 */
/**
 * The raw codes in a stored pointer, whatever namespace they belong to.
 *
 * `mainFormTargets` below resolves against the wizard's own fields and
 * DROPS everything else, which is right for the applicant's status page and
 * wrong for any other reader: the same column also carries document type
 * codes, office-form answer keys and permit codes. An office sheet asking
 * "was this row named" needs the split without the filter.
 */
export function targetCodes(stored: string | null | undefined): string[] {
  if (!stored) return []

  return stored
    .split(',')
    .map((one) => one.trim())
    .filter((one) => one !== '')
}

/** Did this return name that code? Replaces `stored === code`, which a list breaks. */
export function targetsInclude(stored: string | null | undefined, code: string): boolean {
  return targetCodes(stored).includes(code)
}

export function mainFormTargets(code: string | null | undefined): ReturnTarget[] {
  if (!code) return []

  return code
    .split(',')
    .map((one) => BY_VALUE.get(one.trim()))
    .filter((t): t is ReturnTarget => t !== undefined)
}
