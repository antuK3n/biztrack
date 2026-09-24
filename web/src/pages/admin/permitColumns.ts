import type { PermitRegisterRow } from '../../lib/types'
import type { PermitSort } from '../../lib/resources'
import { businessName, formatDate } from '../../lib/format'

/*
 * The register table's columns, as data.
 *
 * ── Why this is a file and not JSX ─────────────────────────────────────────
 *
 * The client asked for one long table carrying "lahat ng info about sa permit
 * na kailangan sa kada office pati mga finill outan kada permit" — every
 * detail each office needs, the office's own filled-in form included. That is
 * 18 columns shared by every permit plus up to 9 more per office, and written
 * as JSX it would be a <td> repeated forty times with the header list kept in
 * agreement by hand. A column that appears in the body and not the head shifts
 * every cell to its right by one, silently, and the table still renders.
 *
 * So a column is one object that knows its own heading, its own value and
 * whether the SERVER can sort it, and the head and the body are both drawn
 * from the same list. They cannot disagree.
 */

/** A value a cell can show. `null` prints as an em dash, never as blank. */
export type CellValue = string | null

export interface PermitColumn {
  /** Stable key — also the React key, so it must be unique across the table. */
  key: string
  label: string
  /**
   * The server's sort key, where the server can sort on this column.
   *
   * Absent means the column is NOT sortable, and the header renders as plain
   * text rather than a button. That is the honest thing: `PermitController`
   * orders through a whitelist of nine columns, and an office-sheet answer
   * lives inside a JSON blob that no index reaches. A header that looked
   * pressable and did nothing would be a control that appears to work.
   */
  sort?: PermitSort
  /** Which office's sheet this column comes from; absent on shared columns. */
  office?: OfficeCode
  value: (row: PermitRegisterRow) => CellValue
  /** Numeric-ish columns get tabular figures so digits line up down the page. */
  tnum?: boolean
}

/* ── The offices, named by the certificate each one issues ─────────────────
 *
 * A permit belongs to an office THROUGH the certificate it is: CENRO issues
 * the CEC, BFP the FSIC. `permits` carries a permit type, never a department,
 * so the code is the permit type's and the office name is what the letterhead
 * says — CPDD for zoning, though the register still seeds that office as CPDO.
 *
 * MARKET is not here. It was a permit type until 6 September 2026, when the
 * client confirmed with the LGU that neither it nor its office is needed.
 */
export const OFFICES = [
  { code: 'BUSINESS', office: 'BPLO', name: "Mayor's / Business Permit" },
  { code: 'ZONING', office: 'CPDD', name: 'Zoning / Locational Clearance' },
  { code: 'SANITARY', office: 'CHO', name: 'Sanitary Permit' },
  { code: 'FSIC', office: 'BFP', name: 'Fire Safety Inspection Certificate' },
  { code: 'OCCUPANCY', office: 'OBO', name: 'Occupancy Permit' },
  { code: 'CEC', office: 'CENRO', name: 'City Environmental Certificate' },
] as const

export type OfficeCode = (typeof OFFICES)[number]['code']

/** The office short name for a permit type code, for a column heading. */
export function officeOf(code: string): string {
  return OFFICES.find((o) => o.code === code)?.office ?? code
}

/**
 * The certificate an office issues, from the DEPARTMENT code on its officers.
 *
 * Not `OFFICES.find(o => o.office === code)`. The office short names above are
 * what the letterhead says, and one of them disagrees with the register:
 * zoning's department is seeded `CPDO` while the department itself signs as
 * CPDD. Matching on the display name would leave the zoning office as the one
 * that fell through to `null` and saw a picker it should not have.
 *
 * Keyed by what `users.department` actually carries, checked against the
 * register on 24 September 2026:
 *
 *   BPLO -> BUSINESS   CHO -> SANITARY   BFP  -> FSIC
 *   OBO  -> OCCUPANCY  CENRO -> CEC      CPDO -> ZONING
 *
 * Null for an account with no department, or one whose department issues
 * nothing — and null means "this reader is not a single-office reader", which
 * is the safe direction: they keep the picker.
 */
const DEPARTMENT_ISSUES: Record<string, OfficeCode> = {
  BPLO: 'BUSINESS',
  CHO: 'SANITARY',
  BFP: 'FSIC',
  OBO: 'OCCUPANCY',
  CENRO: 'CEC',
  CPDO: 'ZONING',
}

export function permitCodeForDepartment(departmentCode: string | null | undefined): OfficeCode | null {
  return departmentCode ? (DEPARTMENT_ISSUES[departmentCode] ?? null) : null
}

/**
 * Read one answer off an office sheet.
 *
 * The sheet is `Record<string, unknown>` because it is a JSON column the
 * applicant's form writes, so every read is guarded: a key that is not a
 * string is treated as unanswered rather than rendered as `[object Object]`.
 */
function answer(row: PermitRegisterRow, key: string): CellValue {
  const v = row.office_form?.[key]
  if (typeof v === 'string') return v.trim() === '' ? null : v
  if (typeof v === 'number') return String(v)
  return null
}

/** An office-sheet answer that is a date, formatted like every other date. */
function answerDate(row: PermitRegisterRow, key: string): CellValue {
  const raw = answer(row, key)
  return raw === null ? null : formatDate(raw)
}

/*
 * ── The shared columns ─────────────────────────────────────────────────────
 *
 * Every permit has these, whichever office issued it. The order is the
 * client's: "mauuna ang BAN then the rest of info na meron sa permit".
 *
 * The identifiers lead, then the certificate face, then the record of
 * issuance. The face is read off `row.face` — the snapshot taken when the
 * certificate was signed — and never off `row.business`, which is what the
 * register says today. A table showing today's address beside a permit issued
 * under the old one would be quietly wrong about a legal document.
 */
export const SHARED_COLUMNS: PermitColumn[] = [
  /*
   * -- Identifiers, in the order a permit is actually reached ------------
   *
   * The client named this order twice, the second time to correct the first:
   * "BIZ-2026-0000x tracking id sa pag aapply at pagbayad na ang application,
   * the next permit no. sa permit ng office na inapplyan nya ... then info
   * na."
   *
   * That is the sequence of the thing itself. An applicant files and pays
   * under a TRACKING ID; each office that filing was routed to then issues its
   * own certificate under its own PERMIT NO. — six filings, six different
   * numbers, one per office. So the filing leads and the certificate follows
   * it, and the rest of the record follows both.
   *
   * "Mauuna ang BAN" was the first phrasing and the BAN led the table for a
   * day. The clarification named BIZ-2026-0000x, which is the tracking ID —
   * the BAN is BP-YYYY-NNNN and belongs to the BUSINESS rather than to
   * anything on this row. It keeps a column, third, because a register is also
   * read by business; it simply is not what a reader arrives holding.
   */
  {
    key: 'tracking_id',
    label: 'Tracking ID',
    sort: 'tracking_id',
    tnum: true,
    /*
     * The FILING — what the applicant applied and paid under. It leads
     * because it is the number that exists first and the one every office on
     * the filing shares; the certificates below it are what each office then
     * issued against it.
     *
     * Null on a permit whose filing was removed, which the table prints as a
     * dash rather than a blank.
     */
    value: (r) => r.application?.tracking_id ?? null,
  },
  {
    key: 'permit_number',
    label: 'Permit No.',
    sort: 'permit_number',
    tnum: true,
    /*
     * The CERTIFICATE, and the column that differs per office on one filing:
     * MCB- for the Mayor's Permit, MCS- sanitary, MCF- fire, MCZ- zoning,
     * MCE- environmental, MCO- occupancy. Reading down a tracking ID's rows,
     * this is the column that says which office issued what.
     */
    value: (r) => r.permit_number,
  },
  {
    key: 'ban',
    label: 'BAN',
    sort: 'ban',
    tnum: true,
    /*
     * The BUSINESS account number — `BP-YYYY-NNNN`. One per business for its
     * whole life, where a tracking ID is minted per filing and a permit number
     * per certificate, so it is what groups every filing a shop has ever made.
     * Third rather than first: it is how a register is browsed, not how a
     * particular permit is looked up.
     */
    value: (r) => r.ban,
  },

  /* -- Which certificate, and whose ------------------------------------- */
  { key: 'permit_type', label: 'Permit / Certificate', sort: 'permit_type', value: (r) => r.permit_type?.name ?? null },
  { key: 'office', label: 'Office', value: (r) => (r.permit_type ? officeOf(r.permit_type.code) : null) },
  {
    key: 'business',
    label: 'Business',
    sort: 'business',
    /*
     * Through `businessName`, not `row.business.name`. The `Permit` type
     * claims that relation is non-nullable and it is not: a business
     * soft-deletes and its permits stay on the register, so the payload
     * answers null on an orphaned row and the helper prints "Business removed
     * from register" where a dereference would throw.
     */
    value: (r) => businessName(r.business),
  },
  { key: 'trade_name', label: 'Trade Name', value: (r) => r.face?.trade_name ?? null },
  { key: 'owner_name', label: 'Owner', value: (r) => r.face?.owner_name ?? null },
  { key: 'address', label: 'Address', value: (r) => r.face?.address ?? null },
  { key: 'barangay', label: 'Barangay', value: (r) => r.face?.barangay ?? null },
  { key: 'city', label: 'City', value: (r) => r.face?.city ?? null },
  { key: 'line_of_business', label: 'Line of Business', value: (r) => r.face?.line_of_business ?? null },

  /* -- The record -------------------------------------------------------- */
  // Status is drawn as a chip rather than as text, so it is handled by the
  // page and deliberately carries no `value` worth printing here.
  { key: 'status', label: 'Status', sort: 'status', value: (r) => r.status_label },
  { key: 'valid_from', label: 'Valid from', sort: 'valid_from', tnum: true, value: (r) => formatDate(r.valid_from) },
  { key: 'valid_until', label: 'Valid until', sort: 'valid_until', tnum: true, value: (r) => formatDate(r.valid_until) },
  {
    key: 'days',
    label: 'Days to expiry',
    tnum: true,
    /*
     * Not sortable on the server: `days_until_expiry` is computed per row, not
     * a column, so ordering by it would mean ordering by `valid_until` under
     * another name — and a header that sorted by a DIFFERENT column than the
     * one it sits on is worse than one that does not sort.
     */
    value: (r) => (typeof r.days_until_expiry === 'number' ? String(r.days_until_expiry) : null),
  },
  { key: 'issued_at', label: 'Issued on', sort: 'issued_at', tnum: true, value: (r) => formatDate(r.issued_at) },
  { key: 'issued_by', label: 'Issued by', value: (r) => r.issued_by ?? null },
  {
    key: 'prior_permit_number',
    label: 'Replaces',
    tnum: true,
    // The permit this one succeeded, by NUMBER rather than id: a number is
    // something a reader can look up, an id in a cell is a dead end.
    value: (r) => r.prior_permit_number ?? null,
  },
  { key: 'revoked_at', label: 'Revoked on', tnum: true, value: (r) => formatDate(r.revoked_at) },
  { key: 'revoked_reason', label: 'Revocation reason', value: (r) => r.revoked_reason ?? null },

  /* -- The sheet's own derived answer ------------------------------------ */
  {
    key: 'application_date',
    label: 'Date of Application',
    tnum: true,
    // On every office sheet and typed on none of them: the API derives it from
    // the filing's submitted_at. It sits with the shared columns rather than
    // being repeated under each office.
    value: (r) => answerDate(r, 'application_date'),
  },
]

/*
 * ── The office sheets ──────────────────────────────────────────────────────
 *
 * The part the client asked for by name: "mga finill outan kada offices
 * permit ... naka depende kung anong office ito".
 *
 * Every label here is the label the APPLICANT saw on the form, copied from
 * `OfficeFormStep.tsx` rather than reworded. An administrator checking a sheet
 * against the paper is comparing two documents, and a table that renamed the
 * boxes would make that comparison a translation exercise. The five sheets and
 * their field keys are that file's; if a sheet gains a question there it must
 * gain a column here, or the answer is saved and never shown.
 *
 * BUSINESS is absent on purpose. The Mayor's Permit is the one type of the six
 * with no office sheet (`PermitType::OFFICE_FORM_CODES`), and the API answers
 * `office_form: null` for it — which the table prints as "This office asks for
 * no form", not as a row of blanks.
 */
export const OFFICE_COLUMNS: Record<OfficeCode, PermitColumn[]> = {
  BUSINESS: [],

  ZONING: [
    { key: 'z_application_type', label: 'Nature of Application', value: (r) => answer(r, 'application_type') },
    { key: 'z_home_address', label: 'Home Address', value: (r) => answer(r, 'zoning_home_address') },
    { key: 'z_project', label: 'Project Description', value: (r) => answer(r, 'zoning_project_description') },
    {
      key: 'z_floor_area',
      label: 'Floor Area to be / being Utilized',
      tnum: true,
      value: (r) => answer(r, 'total_floor_area_sqm'),
    },
    { key: 'z_storeys', label: 'No. of Storey of Building', tnum: true, value: (r) => answer(r, 'building_storeys') },
    {
      key: 'z_rented',
      /*
       * The question the two lessor boxes hang off, and the only shortened
       * label on this sheet: the applicant was asked "Do you pay rent for
       * occupying a place of business?", which is a sentence rather than a
       * column heading. Derived from `businesses.is_rented` and stored as
       * 'yes'/'no', so it is printed as a word rather than passed through.
       *
       * It belongs beside the lessor fields because a blank Name of Lessor
       * means two different things depending on this answer — nobody to name,
       * or an answer the applicant owes.
       */
      label: 'Rented Premises',
      value: (r) => {
        const v = answer(r, 'site_is_rented')
        return v === null ? null : v === 'yes' ? 'Yes' : 'No'
      },
    },
    { key: 'z_lessor', label: 'Name of Lessor (if lessee)', value: (r) => answer(r, 'lessor_name') },
    { key: 'z_lessor_address', label: 'Address of Lessor (if lessee)', value: (r) => answer(r, 'lessor_address') },
    {
      key: 'z_industrial',
      label: 'Type of industrial project',
      value: (r) => answer(r, 'zoning_industrial_project_type'),
    },
    { key: 'z_rep', label: 'Authorized Representative', value: (r) => answer(r, 'authorized_representative') },
  ],

  SANITARY: [
    { key: 's_application_type', label: 'Nature of Application', value: (r) => answer(r, 'application_type') },
    { key: 's_classification', label: 'Sanitary Classification', value: (r) => answer(r, 'sanitary_classification') },
    {
      key: 's_workers',
      label: 'No. of Workers Requiring Health Certificates',
      tnum: true,
      value: (r) => answer(r, 'workers_requiring_health_certs'),
    },
    { key: 's_water', label: 'Water Source', value: (r) => answer(r, 'water_source') },
  ],

  FSIC: [
    { key: 'f_certificate', label: 'Certificate Applied For', value: (r) => answer(r, 'certificate_applied_for') },
    { key: 'f_rep', label: 'Authorized Representative', value: (r) => answer(r, 'authorized_representative') },
  ],

  OCCUPANCY: [
    { key: 'o_application_type', label: 'Application Type', value: (r) => answer(r, 'application_type') },
    { key: 'o_building_permit', label: 'Building Permit No.', tnum: true, value: (r) => answer(r, 'building_permit_no') },
    /*
     * The two issuance dates the OFFICE writes, never the applicant —
     * `OfficeFormController::OFFICER_KEYS`, filled on the review sheet
     * (`OFFICER_DATE_FIELDS` in ReviewPage). They are the clearest case of
     * "data the office itself needs on this permit" and the register table had
     * no column for either, so an administrator could see the numbers and not
     * when they were issued.
     *
     * Each sits directly after the number it dates, so the pair reads as one
     * fact rather than as two columns to line up by eye.
     */
    { key: 'o_building_permit_date', label: 'Building Permit Date Issued', tnum: true, value: (r) => answerDate(r, 'building_permit_date') },
    { key: 'o_fsec', label: 'FSEC No.', tnum: true, value: (r) => answer(r, 'fsec_no') },
    { key: 'o_fsec_date', label: 'FSEC Date Issued', tnum: true, value: (r) => answerDate(r, 'fsec_date') },
  ],

  CEC: [
    { key: 'c_application_type', label: 'Nature of Application', value: (r) => answer(r, 'application_type') },
    { key: 'c_owner_address', label: 'Owner’s Address', value: (r) => answer(r, 'owner_address') },
    { key: 'c_birthday', label: 'Birthday', tnum: true, value: (r) => answerDate(r, 'owner_birthday') },
    {
      key: 'c_certified',
      label: 'Certification',
      /*
       * A checkbox on the paper, stored as 'yes' or absent. Rendered as the
       * act it is rather than as the raw value: "Certified" is what the
       * applicant did, and a cell reading "yes" makes the reader work out what
       * question it answered.
       */
      value: (r) => (answer(r, 'certified') === 'yes' ? 'Certified' : null),
    },

    /*
     * ── The DENR block ────────────────────────────────────────────────────
     *
     * The half of the CEC sheet nobody types. `DenrRequirements::resolve`
     * works out, from the business's PSIC codes, which of the six DENR permits
     * the business needs and whether it must name a Pollution Control Officer
     * — and CENRO reviews the certificate against exactly this.
     *
     * It was missing from the table entirely: the sheet rendered it as a
     * narrative panel rather than as labelled boxes, so it did not turn up in
     * a sweep of the form's field labels. A CENRO administrator reading this
     * table could see the owner's address and not the permits the office is
     * there to check.
     *
     * `denr_reason` is deliberately NOT a column. Its values are 'scale' and
     * 'catch_all' — internal codes that steer a sentence on the applicant's
     * screen, and a cell reading "catch_all" says nothing to anybody.
     */
    { key: 'c_denr_basis', label: 'DENR Basis', value: (r) => answer(r, 'denr_basis') },
    { key: 'c_denr_certificate', label: 'DENR Certificate', value: (r) => answer(r, 'denr_certificate') },
    {
      key: 'c_denr_permits',
      label: 'DENR Permits Required',
      // Already a comma-joined list, or the string 'None' where the business
      // needs none — which is an answer, not a blank.
      value: (r) => answer(r, 'denr_permits'),
    },
    {
      key: 'c_denr_pco',
      label: 'Pollution Control Officer',
      // 'Required' / 'Not required', written that way by the resolver.
      value: (r) => answer(r, 'denr_pco'),
    },
    { key: 'c_denr_remarks', label: 'DENR Remarks', value: (r) => answer(r, 'denr_remarks') },
  ],
}

/**
 * The columns to draw, for the office in view.
 *
 * With no office chosen the table carries EVERY office's sheet — that is the
 * "one long table, left to right" the client asked for, and it is genuinely
 * long: the shared columns plus all five sheets. Choosing an office drops the
 * other four, which is the other half of the same sentence ("naka depende kung
 * anong office ito"). Both readings are in the ask and neither is a
 * compromise: the wide table is the register, the narrow one is the office.
 *
 * ── `withBan` ─────────────────────────────────────────────────────────────
 *
 * The BAN is dropped for a reader who sees one office [client, 24 September
 * 2026: "paki remove muna ang BAN sa permits page ng mga offices"].
 *
 * It is the number that groups every certificate a business has ever held,
 * across offices and across years, which is a register-wide question: BPLO and
 * the super admin read six offices at once and the BAN is what ties their rows
 * together. A clearance office reads only its own certificates, reaches them
 * by certificate number or by business name, and never has six offices on
 * screen to tie together — so for them it was a column that answered a
 * question they were not asking.
 */
export function columnsFor(office: OfficeCode | '', withBan = true): PermitColumn[] {
  const shared = withBan ? SHARED_COLUMNS : SHARED_COLUMNS.filter((c) => c.key !== 'ban')

  if (office !== '') {
    return [...shared, ...OFFICE_COLUMNS[office].map((c) => ({ ...c, office }))]
  }

  const sheets = OFFICES.flatMap(({ code }) =>
    OFFICE_COLUMNS[code].map((c) => ({ ...c, office: code })),
  )

  return [...shared, ...sheets]
}
