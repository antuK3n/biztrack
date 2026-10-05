export interface Department {
  id: number
  code: string
  name: string
}

export interface User {
  id: number
  email: string
  mobile_number: string | null
  first_name: string
  middle_name: string | null
  last_name: string
  suffix: string | null
  gender: 'M' | 'F'
  /*
   * The owner's home address [checklist 2026-09-28, Register 2]; lib/homeAddress
   * has the rules. Null for staff, who are never asked, and for owners who
   * registered before it was.
   */
  home_street: string | null
  home_barangay: string | null
  home_city: string | null
  home_province: string | null
  home_postal_code: string | null
  department: Department | null
  /*
   * Whether to fetch the photo, not where it is. The file is served from
   * /auth/profile/photo for the signed-in account only, so there is no path or
   * URL here that a client could edit into someone else's.
   */
  has_photo: boolean
  is_active: boolean
  email_verified_at: string | null
  /*
   * True only while the API has a real mailer AND this is an owner whose
   * address is unconfirmed: filing is refused until they type the code
   * [checklist 2026-09-27, Register 1]. Sign-up asks for the code before
   * signing them in, so this is true only for an owner signed in before that;
   * Profile offers the code box. Optional because the admin user lists share
   * this type and never carry it.
   */
  email_verification_required?: boolean
  /*
   * True while the API has a real mailer: Settings must e-mail a code before
   * a password change goes through [checklist 2026-09-27, Edit Settings].
   * Optional for the same reason as the line above.
   */
  password_change_code_required?: boolean
  /*
   * An owner with no home address on file — anyone who registered before it
   * was asked. Profile and the home page prompt on it; filing is not held for
   * it (docs/questions-for-malabon.md, A27). Optional for the same reason as
   * the flag above: only the signed-in user's own payload carries it.
   */
  home_address_missing?: boolean
  /*
   * Whether the Debug page is open to this account: the super admin, while
   * the panel is opened from the server. The server's verdict, read by
   * pages/admin/debug/access.ts and nothing else. Optional for the same reason
   * as the flags above.
   */
  debug_panel?: boolean
  roles: string[]
  permissions: string[]
  /**
   * Why this account may reach nothing but its messages and its notices.
   *
   * Null for everybody who is not barred, which is almost everybody, and null
   * for every officer always — a blacklisting is a finding against a business
   * owner.
   *
   * It rides on the SESSION rather than being fetched by whichever screen
   * cares, because it governs the whole session: the shell raises the warning,
   * the navigation hides what is barred, and the router refuses the rest. The
   * server refuses it too — see `EnforceAccountRestriction` — because none of
   * those three is a lock.
   */
  restriction: AccountRestriction | null
}

/** A blacklisting, and where its owner takes it. */
export interface AccountRestriction {
  /**
   * A blacklisting is against the PERSON and reaches everything they hold. A
   * suspension barred the account too from 30 September until 5 October 2026,
   * when Ken took it off: *"business suspension should never affect the
   * entirety of the account."* It now holds only its own business, and the
   * server never sends it here (`App\Support\AccountRestriction`).
   */
  kind: 'blacklisted'
  /** Null: a blacklisting names no one business. */
  business_name: string | null
  /** Null, for the same reason. */
  reference_id: string | null
  /** How many businesses the reader holds, for copy that reaches all of them. */
  covers: number
  /**
   * The conversation to open. A blacklisting has no one business to point
   * at, so `application_id` is null and the reader goes to the general
   * enquiry with BPLO.
   */
  conversation: { application_id: number | null }
}

/** Laravel error envelope: HTTP status + message, plus field errors on 422. */
export interface ApiError {
  status: number
  message: string
  errors: Record<string, string[]>
  /**
   * A machine-readable cause on the few refusals a page must act on rather
   * than print: `code_expired` (sign-in code dead, go back to the password).
   * `email_unconfirmed` (filing refused until the address is confirmed) is
   * still sent, and now printed like any other refusal: the address is
   * confirmed at sign-up.
   */
  reason?: string
}

export interface RegisterPayload {
  first_name: string
  middle_name?: string
  last_name: string
  suffix?: string
  gender: 'M' | 'F'
  email: string
  mobile_number: string
  password: string
  password_confirmation: string
  data_privacy_consent: boolean
}

/* ── Reference lookups (wizard) ───────────────────────────────────────── */

export interface Barangay {
  id: number
  name: string
  /*
   * The API also sends `zoning_map_path`, `zoning_classifications` and
   * `zoning_overlays` (ReferenceController::barangays). They were typed here for the
   * "Zones in <barangay>" card on Location & Zoning, which Ken removed on
   * 6 October 2026; nothing on the web reads them now. The map's zone layer
   * comes from lib/zoningLayers.data, not from these.
   */
}

export interface PsicCode {
  id: number
  code: string
  title: string
  /**
   * The Sec. 2J.02 tax class this trade is taxed under, derived rather than
   * asked. Null only for 00000 "Other (not listed)", where the applicant
   * typed their own line of business and there is nothing to derive from.
   */
  category?: string | null
  /**
   * Names the one follow-up the Revenue Code still forces. "essentials" means
   * Sec. 2J.02(c) may halve the rate and no industrial code can tell whether
   * it does — see FeeProfileStep’s DerivedTaxClass.
   */
  category_branch?: string | null
}

export interface DocumentType {
  id: number
  code: string
  name: string
  help_text: string | null
  /** Present when nested under a permit type. */
  is_required?: boolean
  /**
   * When this requirement applies: 'all', or an application type / permit
   * context that has to match. A renewal-only document must never be asked
   * of a new business, which has no prior permit to produce.
   */
  context?: string
}

export interface PermitType {
  id: number
  code: string
  name: string
  permit_number_prefix: string
  department: { code: string; name: string }
  requires_inspection: boolean
  base_fee: string
  per_line_surcharge: string
  document_types: DocumentType[]
}

/* ── Businesses ───────────────────────────────────────────────────────── */

export interface Address {
  line1: string
  line2: string | null
  /** BPLO item 5, in the paper's own two boxes. `line1` is composed from them. */
  house_bldg_no?: string | null
  street?: string | null
  /** Block, Lot and the lot's area in sq. m. — optional, all three. */
  block?: string | null
  lot?: string | null
  lot_area_sqm?: number | null
  barangay: Barangay
  latitude: number | null
  longitude: number | null
  /** Public verify payload surfaces city. */
  city?: string
  /**
   * BPLO form item A5. Not asked: every location this system will license is
   * inside Malabon (the map pin is checked against the city polygon in
   * `lib/malabonGeo.ts`), and Malabon has exactly one postal code — 1470. A
   * question with one possible answer is not a question. The API defaults it the
   * same way the schema already defaults `city` and `province`.
   */
  postal_code?: string | null
  /** BPLO form item A6, the landline. Blank for most sole proprietors. */
  telephone?: string | null
  /** BPLO form item A9. */
  website?: string | null
  /** BPLO form item A7 — the business's mobile, not the account holder's. */
  mobile_number?: string | null
  /** BPLO form item A8. */
  email?: string | null
}

export interface BusinessLine {
  id: number
  psic_code: PsicCode
  capitalization: string | null
  /** Free text, set when the applicant picked "Other (not listed)". */
  line_of_business: string | null
  /**
   * BPLO items (the Line of Business table's second column, on both the new and
   * the renewal form) and CENRO's own Products/Services column. Per LINE, not
   * per business: a shop that both retails and repairs sells different things
   * under each, and the PSIC title names the trade, never the goods.
   */
  products_services: string | null
}

/**
 * BPLO form item B6, "Economic Organization". Structural rather than
 * descriptive: it is the answer that says whether the main-office address and
 * the business-location address are the same place, which is why the paper asks
 * for both addresses and we currently hold only one.
 */
export type EconomicOrganization =
  | 'single_establishment'
  | 'branch'
  | 'establishment_and_main_office'
  | 'main_office_only'
  | 'ancillary_unit'
  | 'others'

export interface Business {
  id: number
  name: string
  /**
   * The business permit this shop is trading on right now.
   *
   * Absent unless the endpoint eager-loaded it; `null` when the business has
   * none. Null is not a fault — a business row exists from the first draft, so
   * a shop still applying for its first permit has no permit yet.
   *
   * The amendment chooser names it in the option text, because a business has
   * exactly one current business permit and asking which one is a question
   * with a single possible answer.
   */
  current_business_permit?: {
    id: number
    permit_number: string
    valid_until: string | null
  } | null
  /** The amendment BPLO is still reviewing, if any; one at a time per business. */
  open_amendment?: { id: number; tracking_id: string | null } | null
  trade_name: string | null
  registration_type: string | null
  registration_number: string | null
  tin: string | null
  ban: string | null
  /**
   * What the last approved filing declared.
   *
   * Written by `WorkflowService::syncDeclaredFigures` at every approval, and
   * null on a business that has not had one since that started. The office
   * sheets read them — CENRO's and CHO's papers both print a floor area and a
   * headcount they expect carried rather than re-asked.
   */
  business_area_sqm?: number | null
  total_employees?: number | null
  male_employees?: number | null
  female_employees?: number | null
  employees_within_lgu?: number | null
  delivery_units?: number | null
  is_active: boolean
  is_rented?: boolean
  lessor_name?: string | null
  lessor_address?: string | null
  lessor_contact?: string | null
  monthly_rental?: string | null
  emergency_contact_name?: string | null
  emergency_contact_number?: string | null
  /**
   * Access status. `BusinessResource` emits it (`status ?? 'active'`), and the
   * owner dashboard reads it to raise AccountRestrictedModal — so the pop-up an
   * owner sees is driven by this field, not by a placeholder.
   *
   * Still optional: other endpoints emit a business without one, and a
   * `Business` assembled in a form has none until the register answers.
   */
  status?: BusinessStatus
  /** BPLO item B6. Null on every business filed before the wizard asked it. */
  economic_organization?: EconomicOrganization | null
  /** The "Others ____" blank; only meaningful with `economic_organization: 'others'`. */
  economic_organization_others?: string | null
  /**
   * BPLO items A13/A14/A15, and null for a sole proprietorship on purpose —
   * see the gate in ApplyWizard. Item A14 reads "Citizenship (of President/OIC)"
   * on the paper, so all three hang off the same person; where there is no
   * president there is nobody for them to describe.
   */
  president_officer_name?: string | null
  citizenship?: string | null
  capital_participation_filipino?: string | null
  /** BPLO item B7 — one capital-investment figure for the whole business. */
  capital_investment?: string | null
  /**
   * BPLO item B8 (new form) / B7 (renewal form): "Do you have tax incentives
   * from any Government Entity?".
   *
   * NOT the same fact as the `is_bmbe` / `is_cooperative` fee-profile flags.
   * Those two name specific statutory exemptions the calculator acts on; this
   * is the general declaration, which can be true for a PEZA registrant, a
   * Board of Investments pioneer, or a dozen other grants that change nothing
   * in the Revenue Code. Reading either one off the other would be wrong in
   * both directions.
   */
  has_tax_incentives?: boolean
  address: Address
  lines: BusinessLine[]
  /**
   * BPLO items 11 / 12 — the named person, as the API sends it back.
   *
   * The primary `business_owners` row. Optional because a business registered
   * before the wizard asked has none, and every reader has to cope with that
   * rather than assume.
   */
  owner?: {
    surname: string | null
    given_name: string | null
    middle_name: string | null
    suffix: string | null
    gender: string | null
  } | null
}

export interface BusinessPayload {
  name: string
  trade_name?: string
  registration_type?: string
  registration_number?: string
  tin?: string
  is_rented?: boolean
  lessor_name?: string
  lessor_address?: string
  lessor_contact?: string
  monthly_rental?: string
  emergency_contact_name?: string
  emergency_contact_number?: string
  economic_organization?: EconomicOrganization | null
  economic_organization_others?: string | null
  president_officer_name?: string | null
  citizenship?: string | null
  capital_participation_filipino?: string | null
  /**
   * BPLO item B7 — ONE capital-investment figure for the whole business.
   *
   * Not the same thing as the fee profile's per-line `capitalization`, which the
   * Revenue Code engine prices each line of business against. The paper asks for
   * a single declared figure; the engine needs a breakdown. Both are kept.
   */
  capital_investment?: string | null
  has_tax_incentives?: boolean
  address: {
    /**
     * BPLO item 5, sent as the paper's own two boxes.
     *
     * `line1` is no longer typed by anybody — the API composes it from these
     * two in syncAddressAndLines, so everything that reads an address as one
     * line keeps working. It stays optional here for an importer, or a draft
     * saved before the split, that has only the combined value.
     */
    house_bldg_no?: string
    street?: string
    block?: string
    lot?: string
    /** Plain decimal string, or null to clear. */
    lot_area_sqm?: string | null
    line1?: string
    line2?: string
    barangay_id: number
    latitude?: number
    longitude?: number
    /** Omitted by the wizard; the API fills Malabon's 1470. See Address above. */
    postal_code?: string
    telephone?: string
    website?: string
    /** BPLO items A7 and A8 — the business's own, not the account holder's. */
    mobile_number?: string
    email?: string
  }
  lines: {
    psic_code_id: number
    capitalization?: string
    products_services?: string
  }[]
  /**
   * BPLO items 11 / 12 — the named person on the form.
   *
   * Separate from the account that filed. The wizard prefills these from the
   * signed-in user, because for a sole proprietorship they are the same person
   * and retyping what you gave at sign-up is not a question worth asking — but
   * the answer is stored against the BUSINESS, so a corporation can name
   * somebody else without touching anyone's profile.
   */
  owner?: {
    surname?: string | null
    given_name?: string | null
    middle_name?: string | null
    suffix?: string | null
    gender?: string | null
  }
}

/* ── Applications ─────────────────────────────────────────────────────── */

export type ApplicationType = 'new' | 'renewal' | 'amendment'

export type ApplicationStatus =
  | 'draft'
  | 'for_approval'
  | 'returned'
  | 'pending_payment'
  | 'for_final_approval'
  | 'approved'
  | 'issued'
  | 'rejected'
  | 'cancelled'

/**
 * One other permit's own status — `App\Enums\ClearanceStatus`.
 *
 * The second machine. The application has a status and each of the five
 * required permits has its own, running at the same time
 * (docs/application-flow-2026-09.md). `available` is not a server value: it is
 * what the clearance stage shows for an OPTIONAL permit that is not on the
 * filing at all, which has no pivot row and therefore no status.
 */
export type ClearanceStatus =
  | 'not_started'
  | 'for_approval'
  | 'for_inspection'
  | 'approved'
  /*
   * 'rejected' is back, 24 September 2026, with the PHP case.
   *
   * It was removed on 17 September — *"I think Return is enough already"* —
   * and that was right while the business permit was withheld until every
   * clearance was in: an unapprovable permit was punished by the filing never
   * finishing, and there was no certificate to take away.
   *
   * The LGU moved the release to payment, so withholding is no longer the
   * sanction and *"can be suspended if the other permits applied to were
   * rejected"* is. That needs a refusal an office can record, and this is it.
   * Return still exists and still means the fixable kind — see
   * `App\Enums\ClearanceStatus`, which carries the argument in full.
   */
  | 'rejected'
  | 'returned'
  | 'available'

/**
 * The clearance statuses the SERVER can send — `App\Enums\ClearanceStatus` and
 * nothing else. `available` is excluded because no pivot row can carry it: it is
 * the clearance stage's own word for a permit that is not on the filing at all.
 * Anything read off a payload is this type; only UI state is the wider one.
 */
export type ServerClearanceStatus = Exclude<ClearanceStatus, 'available'>

/** How the applicant satisfied one permit: filled the form, or handed in a copy. */
export type ClearanceMode = 'apply' | 'upload'

/**
 * One permit as it stands ON a filing — `ApplicationResource`'s `permit_types`,
 * which is the `application_permit_types` pivot row joined onto the permit type.
 *
 * This is the second state machine made visible. The filing has one status and
 * each permit on it has another, running at the same time, and a screen that
 * shows only the first cannot tell an applicant that Zoning is done while Fire
 * is still out for inspection (docs/application-flow-2026-09.md).
 *
 * A superset of the shape `ApplicationListItem` carries, so the detail payload
 * satisfies the list contract it extends. Two fields are nullable for reasons
 * worth telling apart, because they look identical on the wire:
 *
 *  - `status` / `status_label` are null when there is NO PIVOT ROW — the permit
 *    is not on this filing at all. Every required permit is attached at
 *    submission, so on a submitted filing this should not happen.
 *  - `remarks` / `remarks_target` are null when the reader MAY NOT SEE THEM.
 *    An office reads its own words and not the ones beside it
 *    (`ApplicationVisibility::readsOfficeSheet`), so a fire officer's note comes
 *    back null to the sanitary officer and populated to the applicant, BPLO and
 *    the super admin. Never render "no remarks" off a null — you cannot tell it
 *    from "not yours to read".
 *
 * `returned_at` is in the first group, not the second: it is progress, shared
 * across offices like every status on this payload, and says nothing about what
 * anyone wrote.
 */
export interface ApplicationPermitType {
  id: number
  code: string
  name: string
  requires_inspection: boolean
  /** One of the five the applicant cannot decline. Optional permits are false. */
  is_required: boolean
  status: ServerClearanceStatus | null
  status_label: string | null
  mode: ClearanceMode | null
  /** What the office asked the applicant to fix, in the officer's own words. */
  remarks: string | null
  /**
   * WHICH document or answer those remarks are about, as a stable code.
   *
   * A `document_types.code` for a checklist row, or an office-form answer key
   * for a field. Null when the officer did not point at anything, which is a
   * perfectly ordinary return.
   *
   * The pointer exists so nothing ever has to read the prose to work out what
   * it means — matching text against field names fails on synonyms, on
   * Filipino, on "the second one", and fails silently. See the migration that
   * added `remarks_target`.
   */
  remarks_target: string | null
  /**
   * When the office last sent this permit back.
   *
   * Elapsed time only — "asked for changes 3 days ago" — never a due date. RA
   * 11032 fixes the OFFICE's deadlines, not the citizen's, and Malabon has not
   * given us a Citizen's Charter response window (open question A10).
   *
   * Survives the applicant resubmitting: it stops being "how long have they sat
   * on this" and becomes "this permit was sent back once", which is what an
   * officer re-reading it wants to know.
   */
  returned_at: string | null
  decided_at: string | null
  /**
   * Every status this permit has held, oldest first.
   *
   * Client, 26 September 2026: *"a tracking history PER PERMIT, which
   * contains date and time on when a permit changed status."*
   *
   * `note` follows the same rule as `remarks` above — null when the reader
   * may not see it, so never render "no note" off a null. `to_status` and
   * the timestamp are shared with every office on the filing, because
   * BPLO's final approval is gated on all five and an office cannot tell
   * whether the filing is moving without them.
   *
   * Empty on a permit nobody has touched, which is a real answer and not a
   * loading state: five of the six start that way.
   */
  history: PermitStatusChange[]
}

/** One recorded change to one permit's status. */
export interface PermitStatusChange {
  /** Null on the first entry — the permit had no status before it. */
  from_status: string | null
  to_status: string
  /** Null when nothing was written, OR when it is not this reader's to see. */
  note: string | null
  /** Null for a move the system made with no officer behind it. */
  changed_by: string | null
  created_at: string
}

export interface ApplicationListItem {
  id: number
  tracking_id: string
  application_type: ApplicationType
  /** The applicant's own name for the filing; null falls back to the business name. */
  title: string | null
  status: ApplicationStatus
  status_label: string
  /**
   * Has the city finished with this filing?
   *
   * Not derivable from `status` since 4 October 2026, when
   * `awaiting_other_permits` was removed: `approved` is now both the filing
   * gathering its other permits and the filing that has ended. The server
   * tells the two apart by `decided_at` and sends the answer.
   *
   * Optional, because a payload from before this shipped carries no such
   * key; `isGatheringOtherPermits` falls back to counting the permit rows.
   */
  decided?: boolean
  /**
   * Null when the business has been removed from the register — the same cause
   * documented on Assignment below, and missed here.
   *
   * `Business` soft-deletes and its filings stay: 139 applications currently
   * point at a deleted business. Declared non-nullable, this read as safe, and
   * the officer request composer dereferenced it straight into a crash that
   * blanked the page. It hides the same way it hid last time — the newest rows
   * are clean, so nothing shows until a list runs deep enough to reach one.
   */
  /** `ban`: the Business Account Number (BP-YYYY-NNNN), when loaded. */
  business: { id: number; name: string; ban?: string | null } | null
  /**
   * Who filed it. Null when the account has been removed — `User` soft-deletes
   * and its filings stay, the same way `business` above outlives its register
   * row — so every reader needs a fallback, not a dereference.
   */
  applicant: { id: number; name: string } | null
  submitted_at: string | null
  deadline_at: string | null
  /**
   * Each permit on the filing with its own status — the second state machine,
   * carried on the list so a row never has to infer it.
   *
   * `status` is null only when there is no pivot row, which on a submitted
   * filing should not happen: every required permit is attached at submission.
   */
  permit_types: {
    code: string
    name: string
    status: ServerClearanceStatus | null
    status_label: string | null
    /** The issued certificate's own status (active, suspended, …); null until issued. */
    permit_status?: string | null
    /** What a suspended certificate waits on: the permit and its office. */
    suspension?: { for: string; office: string | null } | null
  }[]
  created_at: string
  /**
   * Last WRITE, which on a draft is the last autosave.
   *
   * Not "last opened" — reading a draft does not move it. That question
   * has its own column now; see `last_opened_at`.
   */
  updated_at: string
  /**
   * When the applicant last OPENED this draft, or null if they never have.
   *
   * Stamped by `ApplicationController::show` for the owner of a draft and
   * by nothing else, so an autosave leaves it alone. Null rather than
   * backfilled from `updated_at`: a draft nobody has opened since this
   * shipped has genuinely never been observed being opened.
   */
  last_opened_at?: string | null
}

export interface AppDocument {
  id: number
  /** `id` is for re-uploading a returned document; `code` is what readers match on. */
  document_type: { id: number; code: string; name: string }
  original_filename: string
  size_bytes: number
  created_at: string
  download_url: string
}

/**
 * One Tax Order of Payment line. Legacy assessments carry only
 * { label, amount }; revenue-code assessments add the citation fields
 * (code/office/group/section/source), the requires_officer marker for
 * lines finalized during review, and any computed defects. Render
 * defensively — every field beyond label/amount may be absent.
 */
export interface FeeLineItem {
  label: string
  amount: string | number
  code?: string
  /** Collecting office: BPLO, CTO, CPDO, CHO, CENRO, OBO, BFP. */
  office?: string
  group?: string
  /** Revenue-code citation, e.g. "Sec. 2A.01". */
  section?: string
  /** Legal source, e.g. "Ord. A10-2016". */
  source?: string
  /** True when an officer must complete this line during review. */
  requires_officer?: boolean
  defects?: string[] | null
}

export interface FeeAssessment {
  /**
   * The Tax Order of Payment's own number, `TOP-YYYY-NNNNNN`
   * (Numbering::taxOrderReference on the API) — never the tracking ID, which
   * names the filing, not the bill. Absent on the fee preview, which is
   * computed and thrown away, so no bill exists to number.
   */
  reference_number?: string
  line_items: FeeLineItem[]
  total_amount: string
  /**
   * What the business's nature commits the filing to beyond the fees — the
   * Liquor Permit behind the liquor filing fee, the Health Certificates
   * behind the sanitary one. `OtherRequirementRules` on the API; present on
   * the fee preview since 5 October 2026, and the rows with `asks` are raised
   * under Other Requirements when the filing is submitted.
   */
  other_requirements?: OtherRequirementPreview[]
}

export interface OtherRequirementPreview {
  key: string
  title: string
  /** The Revenue Code article behind it, e.g. "Revenue Code Art. T, Sec. 3T.01". */
  article: string
  summary: string
  /** Whether something is asked of the applicant after submission. */
  asks: boolean
}

/* ── Fee profile (revenue-code inputs; draft applications only) ────────── */

export interface FeeProfileLine {
  /** Ties the line back to the Part 2 PSIC selection (draft restore). */
  psic_code_id?: number
  /**
   * Revenue-code tax class slug (e.g. retailer, restaurant).
   *
   * OPTIONAL since 16 September 2026, and normally absent: the class is
   * derived from psic_code_id server-side in FeeCalculator::classify, which
   * also supplies the Sec. 3A.03 permit category that this single field could
   * never carry at the same time. Sent only for 00000 "Other (not listed)",
   * where there is no code to derive from, and by drafts saved while the
   * wizard still asked — FeeCalculator leaves an existing class alone so an
   * applicant's own answer is not overwritten mid-filing.
   */
  category?: string
  /**
   * Sec. 2J.02(c): the applicant's declaration that they deal mainly in
   * essential commodities, which halves the rate. The server applies it only
   * where the line of business genuinely branches on it.
   */
  essentials?: boolean
  /** Preceding-calendar-year gross sales (renewals). */
  gross_sales?: number
  /** Initial capital (new businesses). */
  capitalization?: number
}

export type BusinessStructure =
  'sole_proprietorship' | 'partnership' | 'corporation' | 'cooperative'

/**
 * Applicant-declared inputs the API's FeeCalculator uses to compute the
 * itemized Tax Order of Payment from the Malabon Revenue Code. All fields
 * optional; sent on POST/PUT /applications while the draft is editable.
 */
export interface FeeProfile {
  lines?: FeeProfileLine[]
  gross_sales?: number
  capitalization?: number
  floor_area_sqm?: number
  construction_cost?: number
  employees?: number
  /** How many of those live in Malabon (unified form). */
  employees_in_lgu?: number
  /**
   * BPLO item B2 (new form) / B3 (renewal), and CENRO's "TOTAL NO. OF
   * EMPLOYEES — MALE: FEMALE:" box. Both papers print the split and the total
   * as ONE item in ONE box, and it is modelled that way here rather than on the
   * business record.
   *
   * `businesses.male_employees` / `female_employees` exist and are dead — but so
   * are `businesses.total_employees` and `businesses.employees_within_lgu`,
   * which nothing in the API, the seeders, the factories or the tests has ever
   * written. The live home for headcount is this object. Splitting one paper
   * item across two stores would put the sub-counts on the business and the
   * total on the application, and then no validator could hold them against each
   * other: "male + female can't exceed your total" is only checkable while the
   * three numbers are in the same request. The split also belongs to a moment
   * rather than to the register — a shop's headcount is redeclared at every
   * renewal, and the business record would carry the first year's figure
   * forever.
   */
  male_employees?: number
  female_employees?: number
  storeys?: number
  doors?: number
  rooms?: number
  beds?: number
  stall_count?: number
  delivery_vehicles_motorized?: number
  delivery_vehicles_other?: number
  business_structure?: BusinessStructure
  goods_class?: 'flammables' | 'chemicals' | 'dry_goods' | 'perishables'
  office_location?: 'within' | 'outside'
  warehouse_location?: 'within' | 'outside'
  factory_location?: 'within' | 'outside'
  property_use?: 'residential' | 'non_residential'
  /** Occupancy group slug: a1, a2, b, c, d, e, f, g, h, i, j1, j2. */
  occupancy_group?: string
  /** Feature flags, e.g. sells_liquor, has_signage, no_gross_sales_declared. */
  flags?: string[]
}

/**
 * `card` exists only while payments are simulated; `qrph` and `gotyme` only
 * through the online gateway. Which ones are offered comes from
 * `payments.options()`, never from a list in the page.
 *
 * `counter` is never offered — it is BPLO marking a bill paid over the
 * counter at City Hall (`payments.markPaidAtCounter`), not something an
 * owner picks.
 */
export type PaymentMethod = 'gcash' | 'maya' | 'card' | 'qrph' | 'gotyme' | 'counter'

export interface Payment {
  id: number
  reference_number: string
  amount: string
  /**
   * What the payment service was asked to collect for this payment ("50.00").
   * The same as `amount` unless the super admin's test charge was on when it
   * was opened; `amount` is still the bill. Null on a simulated payment.
   */
  gateway_amount?: string | null
  method: PaymentMethod
  status: 'pending' | 'completed' | 'failed' | 'refunded' | string
  paid_at: string | null
  /** 'simulated' (completed at once) or 'kwikpay' (confirmed by the gateway). */
  gateway?: 'simulated' | 'kwikpay'
  /**
   * Where to finish paying, only while an online payment is pending: a page to
   * go to (`link`) or a QR image to scan (`qr`).
   */
  pay_url?: string | null
  pay_url_kind?: 'link' | 'qr' | null
  created_at?: string | null
  /**
   * The owner chose "Pay a different way". Still pending at the payment
   * service (it may yet be paid), but no longer the payment being waited on.
   */
  set_aside?: boolean
  /** Marked for a refund (paid twice, or paid after the filing closed); staff are reviewing it. */
  refund_review?: boolean
  /** Present in the owner's cross-application payment history. */
  application?: { id: number; tracking_id: string }
}

export interface PaymentOptions {
  mode: 'simulated' | 'kwikpay'
  methods: { value: PaymentMethod; label: string }[]
  /** An online payment already waiting, which the screen resumes. */
  in_progress: Payment | null
  /**
   * What the payment service will collect for this bill while the super
   * admin's test charge is on ("50.00"). Null when it collects the full bill,
   * and in simulated mode, where nothing is collected.
   */
  test_charge: string | null
}

export type PaymentGatewayMode = 'simulated' | 'kwikpay'

/** `test`: the payment service collects `test_amount`. `full`: the bill. */
export type PaymentGatewayCharge = 'test' | 'full'

/** What may mark a KwikPay payment paid: its signed callback only, or its status answer too. */
export type PaymentGatewayConfirm = 'callback' | 'query' | 'message'

/**
 * The super admin's view of both switches (GET /admin/payment-gateway). Names
 * of missing settings, never values; the merchant key is never sent.
 */
export interface PaymentGatewayStatus {
  mode: PaymentGatewayMode
  /** What PAYMENT_GATEWAY says, used until somebody flips the switch. */
  default_mode: PaymentGatewayMode
  charge: PaymentGatewayCharge
  /** What the env says the charge is, used until somebody flips it. */
  default_charge: PaymentGatewayCharge
  /** What the test charge collects, "50.00". */
  test_amount: string
  confirm: PaymentGatewayConfirm
  kwikpay: {
    configured: boolean
    /** Env keys still needed, e.g. `KWIKPAY_KEY`. */
    missing: string[]
    base_url: string
    merchant: string
    payment_type: string
    callback_url: string
    callback_ips: string[]
    fake_available: boolean
  }
  /** Online payments not yet confirmed or failed. */
  pending: number
  flagged: {
    id: number
    reference_number: string
    order_id: string | null
    tracking_id: string | null
    amount: string
    created_at: string | null
    flagged_at: string | null
    note: string | null
  }[]
}

/** One signed call to the payment service, in plain words. */
export interface PaymentGatewayTestResult {
  ok: boolean
  message: string
  merchant_display_name?: string
  balance?: string
  pending_balance?: string
}

export interface Assignment {
  id: number
  status: string
  status_label: string
  remarks: string | null
  /**
   * WHICH field those remarks are about, when BPLO named one.
   *
   * A `form:` code from `returnTargets.ts` for a field on the main form, or
   * a permit type code when BPLO sent back one clearance at Final Approval.
   * Null on every other kind of remark, and null is a perfectly good return
   * — the prose is never parsed to derive one.
   *
   * Behind the same visibility gate as `remarks`: a pointer without its
   * reason names a field and says nothing about it.
   */
  remarks_target: string | null
  department: { code: string; name: string }
  officer: { id: number; name: string } | null
  /**
   * True when `officer` is null because this READER may not be told who, not
   * because nobody has claimed the review.
   *
   * The two look identical on the wire and one screen was guessing between
   * them: Office approvals printed "Not yet assigned to an officer" on every
   * null, so office separability — working exactly as designed — made another
   * office's completed review read as unstaffed. A withheld value must not
   * render as a claim about the thing withheld.
   */
  officer_withheld?: boolean
  /**
   * May this reader take the case, and may they work it? (client §3/§10)
   *
   * Answered by the server rather than worked out in the browser. The queue has
   * to render a colleague's case read-only and its own as workable, and the two
   * rows are identical apart from an id comparison — doing that comparison here
   * would put the OIC rule in two places, one of which no officer is obliged to
   * obey. `can_act` is true on an UNHELD case too: acting on one claims it.
   *
   * Optional so that a payload from before this shipped still type-checks; a
   * screen must treat `undefined` as "don't offer the button".
   */
  can_claim?: boolean
  can_act?: boolean
  assigned_at: string | null
  completed_at: string | null
  application: {
    id: number
    tracking_id: string
    /**
     * Null when the business has been removed from the register.
     *
     * `Business` soft-deletes, and its filings stay: 63 businesses are deleted,
     * which is 375 of 4,620 assignments. This was declared non-nullable, so the
     * type checker never questioned `.business.name` and three screens threw on
     * it — and with no error boundary in the app, each throw blanked the whole
     * page rather than the row. It hid because the newest 200 rows are clean;
     * the nulls start around page 5.
     */
    business: { name: string } | null
    application_type: ApplicationType
    /** See `ApplicationListItem.decided`. */
    decided?: boolean
    status_label?: string
    status: ApplicationStatus
  }
  /**
   * This office's own permit on the filing — the second state machine, as seen
   * from the one seat that owns it.
   *
   * The queue needs it because `status` above cannot answer "what is waiting on
   * me" any more: `approveClearance()` completes the assignment the moment the
   * paperwork is accepted, which is when the site visit has yet to happen. So a
   * `completed` assignment covers both "out for inspection" and "finished", and
   * only this tells them apart.
   *
   * Null when the office issues no permit on the filing, or when the caller did
   * not eager-load `application.permitTypes` — the resource returns null rather
   * than firing a query per row. BPLO is populated (it issues the Business
   * Permit) but its value is not a useful signal: that permit reads
   * `for_approval` from Pending Payment through Final Approval, so BPLO's two
   * acts are told apart by the APPLICATION's status instead.
   */
  /**
   * This office's current SITE VISIT on the filing, and the inspector's name
   * typed on it.
   *
   * `officer` above holds the visit as it holds the review — the officer in
   * charge books and decides it. The inspector is a name typed "just for the
   * record" (client, 5 October 2026), since an inspector may have no account.
   * Null when this office has no visit on the filing — which is most rows,
   * and also a permit whose visit is DUE but not yet booked.
   */
  inspection: {
    id: number
    status: string | null
    scheduled_at: string | null
    inspector_name: string | null
    /** Same office, `inspection.manage`, clearance still open. */
    can_name_inspector: boolean
  } | null
  clearance: {
    code: string
    name: string
    status: ServerClearanceStatus | null
    status_label: string | null
    mode: ClearanceMode | null
    requires_inspection: boolean
    /**
     * When this office last sent the permit back, or null.
     *
     * Drives "waiting on the applicant for 3 days" on the officer's queue row.
     * Elapsed time only — RA 11032 fixes the office's clock, not the citizen's,
     * and Malabon has given us no response window (open question A10).
     */
    returned_at: string | null
    /**
     * What this office last asked for, and what it said about each.
     *
     * The office reading its OWN open return, so amending it can open on the
     * fields already ticked. The pointer is replaced wholesale on every
     * write, so a blank composer would silently drop whatever it does not
     * re-tick.
     */
    return_target?: string | null
    return_remark?: string | null
    return_notes?: Record<string, string>
    /**
     * When this office REFUSED the permit, and what it said.
     *
     * Survives the re-application, unlike the return note: the applicant
     * answering an instruction clears the instruction, and this is the
     * historical fact that the office turned this permit down once. It is
     * what draws the banner on the re-read — without it an officer sees a
     * clean For Approval row and an unchanged form, because the sheet keeps
     * its answers between attempts.
     */
    rejected_at: string | null
    rejection_note: string | null
    rejection_remedy: string | null
  } | null
}

export type InspectionResult = 'passed' | 'failed' | 'conditional'

/**
 * A filing's particulars exactly as the applicant submitted them.
 *
 * Named after the permit certificate's field set
 * (PermitController::certificateData) rather than after this screen, because
 * these are the same facts about the same business and the certificate got
 * there first. If a name here stops matching one there, one of the two is
 * wrong — that is the point of sharing the vocabulary.
 *
 * Every string is nullable and means it: `Business` and `User` soft-delete, so
 * a live inspection can point at a business that is no longer in the register,
 * and a blank on this sheet has to read as "not on file" rather than as a bug.
 */
export interface InspectionParticulars {
  application_type: ApplicationType | null
  business_name: string | null
  trade_name: string | null
  registration_number: string | null
  tin: string | null
  owner_name: string | null
  /** The street line. `address_line2` carries the house / building number. */
  address: string | null
  address_line2: string | null
  barangay: string | null
  city: string | null
  province: string | null
  postal_code: string | null
  /** Every declared line joined with ", " — a business may hold more than one. */
  line_of_business: string | null
  permit_types: { code: string; name: string }[]
}

export interface Inspection {
  id: number
  status: string
  status_label: string
  result: InspectionResult | null
  result_label: string | null
  scheduled_at: string | null
  conducted_at: string | null
  findings: string | null
  /**
   * May a fresh visit be booked off the back of this one?
   *
   * Three-valued on purpose. `null` is NOT "no" — it means the response was
   * never asked to load the filing, so the API could not work the answer out
   * (InspectionResource is nested in every ApplicationResource without it, and
   * computing this per row would cost a query per row). Screens must read null
   * as "unknown" and fall back to what they can see, never as a refusal.
   *
   * A screen cannot derive this for itself: whether a LATER visit has already
   * superseded this one is nowhere in the payload, and guessing locally is what
   * left the button showing on a superseded failure the API then refused
   * with a 422.
   */
  can_reinspect: boolean | null
  /**
   * Null when the inspecting office is not loaded on the response.
   *
   * InspectionResource emits `department: null` for an unloaded relation rather
   * than omitting the key, so every consumer has to survive it. Typing it
   * non-nullable was how `data.inspector?.name ?? data.department.name` shipped
   * — the guarded half was guarded and the fallback was not.
   */
  department: { code: string; name: string } | null
  /** An account named on older visits only; nothing new writes it. */
  inspector: { id: number; name: string } | null
  /**
   * Who inspected, as the office typed it (client, 5 October 2026: "just for
   * the record"). Null when blank, and always null for the applicant.
   */
  inspector_name?: string | null
  /** May the reader type or change `inspector_name` now? */
  can_name_inspector?: boolean
  /**
   * The filing the visit belongs to, when the response carried it.
   *
   * Null on any response that did not eager-load the relation — InspectionResource
   * deliberately refuses to lazy-load it, because nested inside an
   * ApplicationResource that would be a query per inspection.
   */
  application: {
    id: number
    tracking_id: string
    /**
     * Null when the business is removed from the register, and also null on a
     * stub the server built without loading it (the applicant's filing detail
     * loads `inspections.application:id,tracking_id` and nothing deeper).
     *
     * Those two are not the same fact, and a screen that prints "removed from
     * the register" for the second one is lying about a live business. Reach for
     * the parent's own `business` when there is one; only the inspections list,
     * which loads the relation properly, may report removal from this field.
     */
    business: { name: string } | null
    /** Null with the business: the address hangs off it. Same caveat. */
    address: {
      line1: string
      /** Null when the barangay is not loaded on the response. */
      barangay: { name: string } | null
      latitude: number | null
      longitude: number | null
    } | null
  } | null
  /**
   * What the applicant actually filed, when the response went and looked.
   *
   * Null on the list, and null on the conduct/reschedule replies, because only
   * `GET /inspections/{id}` eager-loads the owner, the address and the declared
   * lines. Null therefore means "this response did not carry them", never "the
   * applicant left the form blank" — a detail screen that re-renders off a
   * conduct reply must hold on to the block it already has rather than treat
   * the missing key as an empty filing.
   */
  particulars: InspectionParticulars | null
}

/** The permit whose refusal or failed visit suspended a Business Permit. */
export interface SuspendedFor {
  code: string
  name: string
  office: string | null
}

export interface Permit {
  id: number
  permit_number: string
  status: string
  status_label: string
  valid_from: string | null
  valid_until: string | null
  days_until_expiry: number | null
  /** `department_id` and `office`: the office that issued it, the one an owner writes to about it. */
  permit_type: { code: string; name: string; department_id?: number | null; office?: string | null }
  /** `ban`: the Business Account Number, shown beside the name on My Permits. */
  business: { id: number; name: string; ban?: string | null }
  application: { id: number; tracking_id: string }
  verify_url: string
  /*
   * Why it is suspended (client, 5 October 2026: "Show WHY it is suspended
   * and WHICH office caused it"). All null unless `status` is suspended;
   * `suspended_for` is null too when the cause named no single permit, and
   * `suspended_at` when the date was never recorded.
   */
  suspended_at?: string | null
  suspended_days?: number | null
  suspension_reason?: string | null
  suspended_for?: SuspendedFor | null
  /** Requirements submitted for it, when the list was asked `with_requirements`. */
  requirements_count?: number
  /**
   * Why this permit cannot be renewed today, in the applicant's words, or
   * null if it can.
   *
   * The renewal picker listed every active and expired permit and had no way
   * to know the server would refuse one, so a clearance lapsed past the
   * window was ticked, the whole wizard filled in, and the refusal arrived as
   * a 422 on the last screen. The sentence rather than a flag, because "too
   * early, come back on this date" and "too late, file a New Application" are
   * different news and only the server knows which — see `RenewalWindow`.
   *
   * Absent on payloads that did not load the permit type, which are the ones
   * not drawing a picker.
   */
  renewal_blocked_reason?: string | null
  /**
   * A submitted renewal is already carrying this permit. Only on the owner's
   * own list (`GET /permits`), where the renewal chooser counts what is due.
   */
  renewal_in_progress?: boolean
}

/**
 * What was printed on the certificate, as it was when it was signed.
 *
 * Read off `permits.issued_details` through `PermitFace::forPrinting`, never
 * off the live business. A permit is a snapshot: showing today's address
 * beside a certificate issued under the old one would be quietly wrong about
 * a legal document.
 *
 * Every field is nullable, and the nulls mean different things — "the business
 * was removed from the register" and "this was never recorded" — so the table
 * prints a dash rather than inventing a value.
 */
export interface PermitFace {
  business_name: string | null
  trade_name: string | null
  owner_name: string | null
  address: string | null
  barangay: string | null
  city: string | null
  line_of_business: string | null
}

/**
 * One permit as the administrator's register table reads it.
 *
 * `GET /permits?detail=1` → `PermitRegisterResource`, which is `Permit` plus
 * the four groups the client asked to see in one wide table: the BAN that
 * leads it, the certificate face, the record of issuance, and the office sheet
 * the applicant filled in for this permit's office.
 */
export interface PermitRegisterRow extends Permit {
  /**
   * The business account number — `BP-YYYY-NNNN`, the number the City files a
   * business under across every permit it ever holds.
   *
   * It leads the table because it is the only one of the three identifiers
   * that is stable: a permit number names one certificate and a tracking ID
   * names one filing. Null when the business has been removed from the
   * register and the certificate outlived it.
   */
  ban: string | null
  face: PermitFace
  /** ISO 8601. Null on the permits issued before the column existed. */
  issued_at: string | null
  /** The officer who signed it, or null where the register never recorded one. */
  issued_by: string | null
  /** The permit this one replaced, by number. Null on an original issuance. */
  prior_permit_number: string | null
  revoked_at: string | null
  revoked_reason: string | null
  /**
   * The business was removed from the register — "retired" on the screen
   * (checklist item 21). The register loads retired businesses, so `business`
   * is present on these rows and this is what marks them.
   */
  business_retired: boolean
  /**
   * The office's own form for this permit, saved answers and derived ones
   * together.
   *
   * `null` means this office asks for NO sheet — only the Mayor's Permit, of
   * the six types. An empty object would mean it asks and was not answered,
   * and the table says each of those differently.
   */
  office_form: Record<string, unknown> | null
  /**
   * The requirements uploaded on the filing that belong to THIS permit's
   * office — see PermitRegisterResource::requirementDocuments. Null when not
   * loaded, [] when loaded and nothing was uploaded.
   */
  documents?: {
    id: number
    name: string
    filename: string
    status: string | null
    download_url: string
    /** Sent under Other Requirements, in answer to an office's request. */
    from_request?: boolean
  }[] | null
}

/**
 * One of the three tiers RA 11032 recognises, as the API offers it.
 *
 * The day count travels WITH the option and is never written down here. The
 * three tiers and their deadlines are statute — 3 working days simple, 7
 * complex, 20 highly technical — and a browser holding its own copy could
 * drift into captioning "Simple" with the wrong number, or into offering a
 * fourth tier that no LGU is entitled to grant itself. `Ra11032::TIERS` on the
 * API is the single source; this shape only carries it.
 */
export interface Ra11032Tier {
  value: string
  label: string
  statutory_working_days: number
}

/**
 * Where a filing stands under RA 11032, and — the point of this block — WHO
 * decided that.
 *
 * The statute fixes the deadlines and says nothing about which filing belongs
 * to which tier; that classification is the LGU's, published in its Citizen's
 * Charter, and Malabon has not given us theirs (open question A10). So every
 * tier in the register was assigned by a rule this project invented, and an
 * officer about to override one is entitled to know that is what they are
 * doing. `source` is the field that says it.
 */
export interface Ra11032Standing {
  /** null when the filing has never been classified at all. */
  tier: string | null
  /** The statute's own name for the tier ("Highly technical"), or null. */
  label: string | null
  statutory_working_days: number | null
  /**
   * `automatic` — our rule guessed it at submission and nobody has looked.
   * `officer`   — a named person decided it; `set_by` says who.
   * `null`      — never classified.
   */
  source: 'automatic' | 'officer' | null
  set_by: { id: number; name: string } | null
  set_at: string | null
  /** False on a decided filing: a closed case's statutory clock is not editable. */
  editable: boolean
  /** The only tiers anyone may choose between. */
  tiers: Ra11032Tier[]
}

/**
 * One field the applicant put right after BPLO returned the filing.
 *
 * Both halves, captured at the write — see the API migration for why the
 * "before" cannot be derived afterwards. The officer's sheet reads these to
 * check the fields it asked about instead of re-reading the whole form.
 *
 * `target` is a `form:` code, resolved through `mainFormTargetLabel`. A code
 * this build does not know resolves to null and is skipped rather than
 * printed raw.
 */
/** One row of an office's checklist, as the applicant left it and as it is now. */
export interface ClearanceCorrection {
  /** The checklist row's document code, or an office-sheet answer key. */
  target: string
  old_value: string | null
  new_value: string | null
  at: string | null
}

export interface ApplicationCorrection {
  target: string
  old_value: string | null
  new_value: string | null
  at: string | null
}

export interface Application extends ApplicationListItem {
  applicant: { id: number; name: string }
  /**
   * What was corrected after a return, oldest first.
   *
   * Optional for the reason every other late addition here is: a payload
   * from before this shipped carries no key at all, and `?? []` reads the
   * same as a filing that was never returned — which is the truth for all
   * but a handful of them.
   */
  corrections?: ApplicationCorrection[]
  /**
   * The `form:` codes this applicant actually answered.
   *
   * What an officer may return the filing about — a field left blank was
   * never their answer to correct, and a missing TIN has its own route. Sent
   * by the API because only it knows which record holds each field; see
   * `ReturnTargets::answeredBy`.
   *
   * Optional: a payload from before this shipped carries no key, and `?? []`
   * then offers nothing, which is the safe direction.
   */
  answered_targets?: string[]
  /**
   * BPLO's remark for each returned field, keyed by its `form:` code.
   *
   * A map because every reader wants it BY FIELD — the applicant drawing a
   * box, the officer reading back what they asked — and a list would make
   * each of them build the same index.
   *
   * Optional: a payload from before this shipped has no key, and a filing
   * returned with plain prose has no per-field notes either. Both read the
   * same through `?? {}`, which is correct — neither has a note to show.
   */
  return_notes?: Record<string, string>
  /**
   * Other Requirements still open on this filing — anything not Fulfilled.
   *
   * A filing does not reach BPLO's final approval while one is open
   * (WorkflowService::refreshReadiness), so the screens that show the stage
   * need this to explain the wait. A filing that stops moving with nothing on
   * it saying why is the defect that rule would otherwise introduce.
   *
   * Optional: a payload from before this shipped carries no key, and a screen
   * must read `undefined` as "no information" rather than as zero.
   */
  open_requirements?: number
  /** How the business tax is settled: in full by Jan 20, or in four quarters. */
  /**
   * Mode of Payment, as MCG-BPLO-FO-002 prints it.
   *
   * `semi_annual` has no equivalent in Revenue Code Sec. 2N, which provides
   * for annual and quarterly only. It is here because the paper offers it and
   * applicants tick it.
   *
   * Recorded, never acted on: the Tax Order of Payment bills the full year
   * whatever this says, and the form tells the applicant so.
   *
   * No longer asked (client, 5 October 2026: "Remove the question
   * entirely"); the API accepts `annual` alone. The other two stay in the
   * type because filings recorded before then still hold them.
   */
  payment_mode?: 'annual' | 'semi_annual' | 'quarterly'
  /**
   * RA 10173 consent, as given for THIS filing.
   *
   * Read when a draft is reopened so the tick can be put back. It used to live
   * only in the wizard's React state, so reopening a draft asked again — and the
   * register kept no record of a consent the submit gate refuses to proceed
   * without.
   */
  data_privacy_consent?: boolean
  /** What this filing amends; null unless `application_type` is `amendment`. */
  amendments?: AmendmentDetails | null
  /** Full resource embeds the complete business (address + lines). */
  business: Business
  documents: AppDocument[]
  /**
   * The Tax Order of Payment. A clearance office's payload carries only its
   * own permit's lines and their sum (6 October 2026); BPLO, the super admin
   * and the applicant get the whole bill.
   */
  fee_assessment: FeeAssessment | null
  /**
   * An amendment's fixed fee (₱200 since 5 October 2026), which is stacked on
   * the January renewal rather than billed, so `fee_assessment` is null on
   * one. Null on every other filing type, and for a clearance office.
   */
  amendment_fee?: number | null
  /** Applicant-declared revenue-code inputs (null when never filled). */
  fee_profile?: FeeProfile | null
  /** Submitted per-office form payloads (full application payload). */
  office_forms?: OfficeForm[]
  payments: Payment[]
  assignments: Assignment[]
  inspections: Inspection[]
  permits: Permit[]
  rejection_reason: string | null
  /**
   * The RA 11032 tier, its provenance, and the tiers an office may choose
   * between. Optional so a payload built before this existed still type-checks
   * — every reader has to cope with its absence rather than assume it.
   */
  ra11032?: Ra11032Standing
  /**
   * The permits on this filing, each with its own status and the pivot's full
   * detail — a superset of what `ApplicationListResource` sends, which is why
   * this narrows the inherited field rather than conflicting with it.
   *
   * The narrowing used to be about `requires_inspection`, which drove a For
   * Inspection step on the progression rail. That step is gone: inspection is no
   * longer a stage of the APPLICATION, it is a stage of each PERMIT, and the
   * flag is read per card rather than folded into one whole-filing guess.
   */
  permit_types: ApplicationPermitType[]
  /**
   * Every recorded transition, oldest first — the same rows and the same shape
   * as `GET /applications/{id}/timeline`.
   *
   * Empty is ambiguous by design on the API side: it means either "this filing
   * has never moved" or "this endpoint did not load them". Both leave a reader
   * with nothing to draw, so neither needs its own branch here.
   */
  status_history: TimelineEntry[]
  /**
   * Where the BUSINESS stands on all five required clearances — not this
   * filing's permits, the register's.
   *
   * The two are almost disjoint on a January renewal. A business permit renewed
   * alone carries one `permit_types` row, while the five certificates BPLO is
   * meant to be checking at Final Approval sit on last year's filings, because
   * a clearance still in date is not renewed and so not ticked.
   *
   * `null` rather than `[]` when the reader may not see it: this is a
   * cross-office array by construction, so the API gives it to BPLO and the
   * super admin only. Null says "not for you"; an empty array would say "this
   * business holds nothing", which is a different and alarming claim.
   */
  clearance_standing: ClearanceStanding[] | null
  /**
   * What an amendment asks to change, for the officer deciding it.
   *
   * `null` on anything that is not an amendment — and an amendment with an
   * empty array is a filing asking for nothing, which BPLO should refuse rather
   * than approve. The two states are worth telling apart, which is why this is
   * not `[]` when absent.
   */
  requested_changes: RequestedChange[] | null
}

/**
 * One amendable detail on the applicant's own form.
 *
 * Wider than `RequestedChange`: this row exists for every detail that CAN be
 * amended, whether or not this filing asks about it, so `requested` is what
 * separates "I want this changed" from "here is what it currently says".
 */
/**
 * One amendable detail, as the reference data describes it.
 *
 * The DEFINITION half of an `AmendmentRow`: the same for every business in
 * the city, so it travels with the barangays and the PSIC codes and is in
 * hand before the wizard paints. Only the values below it are per-filing.
 */
export interface AmendableField {
  field: string
  group: 'other' | 'address' | 'ownership' | 'trade_name'
  group_label: string
  group_paper: string | null
  label: string
  help: string | null
  type: 'text' | 'number' | 'integer' | 'psic' | 'barangay' | 'pin' | 'note'
}

export interface AmendmentRow {
  field: string
  /**
   * Which of MCG-BPLO-FO-003's four checkboxes this detail came off.
   *
   * The paper prints a requirements list per box, so the box is what the
   * document rules are indexed by as well as how the step is laid out —
   * `AmendableFields::GROUPS` is the one definition and this is it on the
   * wire. `group_paper` is the numeral the form itself prints (I, II, III),
   * null for the unnumbered box at the top.
   */
  group: 'other' | 'address' | 'ownership' | 'trade_name'
  group_label: string
  group_paper: string | null
  label: string
  help: string | null
  /**
   * What control the applicant needs. Half of these stopped being free text
   * when the paper's boxes were mapped properly: a line of business is a PSIC
   * code, a barangay is the list zoning is assessed against, a pin is a map.
   */
  type: 'text' | 'number' | 'integer' | 'psic' | 'barangay' | 'pin' | 'note'
  current_value: string | null
  /** An id resolved to something readable; null when the value reads as itself. */
  current_label: string | null
  new_value: string | null
  new_label: string | null
  requested: boolean
  old_value: string | null
  applied_at: string | null
}

/** One requested change to one business detail. */
export interface RequestedChange {
  field: string
  /** "Floor area (sqm)" — never the column name, which no applicant knows. */
  label: string
  /** The register as it stands, so old → new can be shown without a second copy. */
  current_value: string | null
  new_value: string | null
  /**
   * What the change actually replaced, captured when it was APPLIED rather than
   * when it was asked for. Null until BPLO approves.
   */
  old_value: string | null
  applied_at: string | null
  /**
   * The three values resolved to something readable, or null when the value
   * already reads as itself.
   *
   * A line of business and a barangay are ids since FO-003's boxes were mapped
   * properly, and this panel is where BPLO decides — unresolved it would read
   * "Change of line of business: 1 \u2192 47".
   */
  current_label: string | null
  new_label: string | null
  old_label: string | null
}

/** One required clearance, and what the business holds against it. */
export interface ClearanceStanding {
  permit_type_code: string
  permit_type_name: string
  department_code: string | null
  /**
   * `missing` is not `expired`. A business that never held a Fire Safety
   * certificate and one whose certificate lapsed in March are both gaps, but
   * the first has never been inspected by that office and the second has.
   * `unknown` is a certificate with no expiry date on record — rare, early
   * rows, and not to be read as cover.
   */
  state: 'valid' | 'expiring' | 'expired' | 'missing' | 'unknown'
  /** Negative once lapsed; null when there is no certificate or no date. */
  days_until_expiry: number | null
  /** True when this filing is renewing it, rather than relying on the one held. */
  on_this_filing: boolean
  permit_id: number | null
  permit_number: string | null
  valid_from: string | null
  valid_until: string | null
}

export interface TimelineEntry {
  from_status: ApplicationStatus | null
  to_status: ApplicationStatus
  note: string | null
  changed_by: { name: string } | null
  created_at: string
}

/* ── Notifications ────────────────────────────────────────────────────── */

/** GET /permits/{id}/status-options — what Change status may offer. */
export interface PermitStatusOptions {
  current: string
  current_label: string
  /** True only for the office that issued the permit. */
  can_change: boolean
  /** Expired, superseded, revoked or retired: nothing can change it now. */
  final: boolean
  options: { value: string; label: string }[]
  /** A Mayor's Permit held suspended by rejected permits: why, one line each. */
  locked: string[]
}

/** One entry of GET /permits/{id}/history, newest first. */
export interface PermitHistoryEntry {
  at: string | null
  action: string
  label: string
  from: string | null
  to: string | null
  to_label: string | null
  reason: string | null
  by: string | null
  by_office: string | null
}

export interface Notification {
  id: number
  type: string
  title: string
  body: string
  link: string | null
  read_at: string | null
  created_at: string
}

/* ── Analytics ────────────────────────────────────────────────────────── */

export interface AnalyticsSummary {
  applications_by_status: Record<string, number>
  applications_by_type: Record<string, number>
  applications_by_month: { month: string; count: number }[]
  approval_rate: number
  avg_processing_days: number
  active_permits: number
  expiring_permits: number
  simulated_revenue: number
}

/*
 * Permit Processing Time Monitoring (Feature 7). Statistical process control
 * over weekly review turnaround, computed server-side in App\Support\Spc. These
 * shapes mirror it exactly.
 */

export type SpcStatus = 'in_control' | 'out_of_control'
export type TrendDirection = 'rising' | 'steady' | 'easing'

export interface ProcessingTimePoint {
  week_start: string
  reviews: number
  mean_days: number
  /** Signed distance from the centre line, in days. */
  deviation_days: number
  /** Weighted (EWMA) trend value for the week. */
  ewma: number
  status: SpcStatus
  /** `beyond_limits`, `ewma_drift`, or both joined by `+`. */
  rule_hit: string | null
}

export interface FlaggedWeek {
  week_start: string
  mean_days: number
  deviation_days: number
  rule_hit: string | null
}

export interface ProcessingTimeDepartment {
  code: string
  name: string
  completed_reviews: number
  /** Centre line, and the normal operating range around it. */
  center: number
  lcl: number
  ucl: number
  sigma: number
  calibration_weeks: number
  /** Where the most recent week sits: the Process Status Indicator. */
  status: 'inside' | 'outside'
  latest_week: string
  latest_mean_days: number
  points: ProcessingTimePoint[]
  flagged: FlaggedWeek[]
  trend: {
    direction: TrendDirection
    /** 0 to 1: how far the weighted trend has walked towards its own limit. */
    magnitude: number
    ewma: number
    deviation_days: number
    drift_flagged: boolean
  }
}

/*
 * Where an analytics figure came from, and when.
 *
 * BizTrack computes its own statistics. There is one implementation, in PHP,
 * inside this application — no second program, no service to be unreachable, no
 * parity to hold between two codebases. `engine` is therefore always the string
 * 'BizTrack' and `engine_version` is always null. They are kept on the payload
 * rather than dropped because the exported PDFs attribute their figures, and a
 * document that gets forwarded and quoted has to carry that attribution with it;
 * a screen or a report that hard-codes the name instead would be the thing that
 * lies the day the product is renamed.
 *
 * `source` is the field that still does real work, and it is about FRESHNESS,
 * not about who computed anything:
 *
 *   'snapshot'  the figures were precomputed by `php artisan analytics:refresh`
 *               and stored, and this response read the stored result
 *   'local'     no stored result existed for this exact request, so the figures
 *               were computed during it, from the rows as they are right now
 *
 * That difference has to reach the screen. A snapshot is as fresh as the last
 * refresh and no fresher, so a tester who files an application and does not see
 * it on the dashboard has found the designed behaviour rather than a bug —
 * `computed_at` is what says so, and only a snapshot can be out of date at all.
 * The precompute layer is deliberate and is staying: the client kept it when the
 * external engine went, so do not "simplify" this to a single source and do not
 * assume `computed_at` means "now".
 *
 * The screens do not render `notice`; it is written for the PDF export, whose
 * reader cannot ask. See ComputedAt.tsx.
 */
export type AnalyticsSource = 'snapshot' | 'local'

/**
 * Why a request fell back to computing during the request instead of reading a
 * stored result.
 *
 * One member, and that is not an oversight waiting to be collapsed into a
 * boolean. The reasons that stood beside it — no endpoint on the other engine,
 * the other engine switched off, the other engine unreachable — described a
 * second statistics process that no longer exists. This stays a named union so
 * that a future reason arrives as a compile error at every screen that reads it,
 * rather than as an unexplained `true`.
 */
export type AnalyticsFallbackReason =
  /** The refresh has not run yet, or its last run failed for this view. */
  'not_yet_refreshed'

/**
 * What one figure on an analytics screen measures, and why it is on the screen.
 *
 * Written server-side in AnalyticsDefinitions.php, next to the queries these
 * sentences describe. It arrives in `meta` rather than `data` for the same
 * reason `engine` does: how a figure was derived is not one of the figures.
 */
export interface MetricDefinition {
  /** The name as printed on screen. */
  label: string
  /** How the number is produced, with the denominator named. */
  formula: string
  /** Which rows it is over — the window, and what is left out. */
  covers: string
  /** What decision it informs, and who makes it. */
  why: string
}

export interface AnalyticsProvenance {
  /** Precomputed and stored, or worked out during this request. */
  source: AnalyticsSource
  /** Always 'BizTrack'. Read it; never hard-code the name on a screen. */
  engine: string
  /**
   * Always null under the current contract — there is no external engine left to
   * version. Typed nullable rather than `null` so that a caller has to handle
   * the absent case, which is what stops a banner rendering "by BizTrack null".
   */
  engine_version: string | null
  computed_at: string
  stale: boolean
  stale_after_hours: number
  fallback_reason: AnalyticsFallbackReason | null
  notice: string | null
  /** Keyed by dot path into the payload, e.g. `decisions.approval_rate`. */
  definitions: Record<string, MetricDefinition>
}

/** Page meta returned by paginated list endpoints. */
export interface PageMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  /**
   * What this owner has been issued and not yet billed for.
   *
   * Only on `/permits`, and only for an owner — an officer reading the
   * register is looking at many businesses and "what you owe" means nothing
   * to them, so the API omits it rather than sending a zero they might print.
   *
   * A clearance renewed outside January is issued unbilled by rule, and until
   * 1 October 2026 the only screen that said so was the admin Owners page: the
   * applicant was handed a certificate, asked for no money, and met the fee
   * months later on a bill they had no reason to expect.
   *
   * `surcharge` is the late penalty (Secs. 8A.04/8A.05) folded from its
   * surcharge and interest halves — separate from `amount` because the
   * penalty is the figure a business disputes, and one merged number gives
   * them nothing to dispute.
   */
  unbilled_fees?: {
    total: number
    items: {
      permit_type: string | null
      amount: number
      surcharge: number
      months_late: number
      incurred_at: string | null
    }[]
  }
}

/** A page of results together with its meta. Both must reach the screen. */
export interface Paged<T> {
  data: T[]
  meta: PageMeta
}

/** Common paging query params every list endpoint accepts. */
export interface PageParams {
  page?: number
  per_page?: number
}

/**
 * The officer queue's page meta.
 *
 * `application_status_counts` is counted over the whole department-scoped set,
 * not the page. The queue tabs split on the *application's* status and used to
 * do it in the browser over an unpaged list; against a page, a count taken from
 * the rows in hand is always ≤ per_page and always looks plausible, which is the
 * worst kind of wrong number. Read the tab totals from here.
 */
export interface AssignmentPageMeta extends PageMeta {
  application_status_counts: Partial<Record<ApplicationStatus, number>>
}

/**
 * How much of a conversation one request returns.
 *
 * Message and chatbot transcripts come back as the most recent `window` turns in
 * ascending order, not as page one of an ascending list — a chat paged from the
 * top opens on the oldest thing anybody said. `total` says how many turns exist.
 */
export interface TranscriptMeta {
  total: number
  returned: number
  window: number
}

/**
 * A message transcript's meta: the window, plus who the conversation is with.
 *
 * `department_id` echoes back the office that was asked for, or null when the
 * caller asked for the whole filing and got every conversation it may read
 * merged in time order. `offices` is the picker — see MessageOffice.
 */
export interface MessageTranscriptMeta extends TranscriptMeta {
  department_id: number | null
  offices: MessageOffice[]
}

/** An analytics payload together with its provenance. Both must reach the screen. */
export interface Computed<T> {
  data: T
  meta: AnalyticsProvenance
}

/**
 * Whose figures an analytics response carries (checklist 2026-09-27, item 1).
 *
 * Decided on the server (App\Support\AnalyticsOffice): an office account is
 * answered with its own office whatever it asks for, so `office` here is the
 * truth about the figures and the screen must label them from it rather than
 * from its own state. `offices` is empty unless the reader may switch.
 */
export interface AnalyticsScope {
  /** Department code, or null for every office. */
  office: string | null
  office_name: string
  can_switch: boolean
  offices: { code: string; name: string }[]
}

export interface ScopedComputed<T> extends Computed<T> {
  scope: AnalyticsScope
}

/* ── Reports (checklist 2026-09-27, item 7) ───────────────────────────────
 *
 * One shape for all five LGU reports (App\Support\LguReports): sections of
 * typed columns and raw rows. The screen renders it and the CSV writes it, so
 * neither knows which report it holds. Values are raw; null is "no figure".
 */
export type ReportKey =
  | 'permits-issued'
  | 'collections'
  | 'businesses-by-area'
  | 'clearances'
  | 'pending-processing'

export interface ReportListItem {
  key: ReportKey
  title: string
  summary: string
}

export interface ReportColumn {
  key: string
  label: string
  format: 'text' | 'count' | 'money' | 'decimal' | 'percent'
}

export type ReportRow = Record<string, string | number | null>

export interface ReportSection {
  heading: string
  columns: ReportColumn[]
  rows: ReportRow[]
  total: ReportRow | null
  note: string | null
}

export interface LguReport {
  key: ReportKey
  title: string
  sections: ReportSection[]
  period: { from: string; to: string }
  scope: AnalyticsScope
  generated_at: string
}

/** One dataset variant's outcome from a manual refresh. */
export interface AnalyticsRefreshRow {
  key: string
  dataset: string
  ok: boolean
  rows: number
  duration_ms: number
  error: string | null
}

/**
 * What a manual refresh actually did.
 *
 * `failed` can be non-zero on a 200: a refresh walks eight dataset variants and
 * may store some and fail others, leaving the screens showing a mix of fresh and
 * stale figures. The caller has to report that rather than treat any 200 as a
 * clean refresh.
 */
export interface AnalyticsRefreshResult {
  message: string
  refreshed: number
  failed: number
  /** Always null — same reason as AnalyticsProvenance.engine_version. */
  engine_version: string | null
  results: AnalyticsRefreshRow[]
}

/** An office with reviews on record but no week that cleared the minimum. */
export interface ThinDepartment {
  code: string
  name: string
  completed_reviews: number
  reason: string
}

export interface ProcessingTimeReport {
  generated_at: string
  window_weeks: number
  window_start: string
  min_completions_per_week: number
  calibration_weeks_cap: number
  completed_reviews: number
  departments: ProcessingTimeDepartment[]
  thin: ThinDepartment[]
}

/*
 * Office Performance (issue #102) — the six offices on one set of axes.
 *
 * Mirrors App\Support\OfficePerformanceAnalytics. Read that class's docblock
 * before touching the nullable fields below; they are the load-bearing part of
 * this shape, not an accident of an optional column.
 */

/** One office's row in the comparison. */
export interface OfficePerformanceRow {
  code: string
  name: string

  /** Reviews finished inside the window. A count of rows, true of every office. */
  handled: number
  /** Assigned and unfinished, as of now — deliberately not cut to the window. */
  open: number
  /** The longest current wait, or null when nothing is open. */
  oldest_open_working_days: number | null

  /**
   * False for an office whose recorded time measures something other than its
   * own step. One office is in that position — BPLO, whose assignment row is
   * stamped again at final approval — and when it is, every figure below is
   * null and `not_comparable_reason` carries the sentence the screen prints.
   */
  turnaround_comparable: boolean
  not_comparable_reason: string | null

  /*
   * Null means NOT COMPARABLE or NOT MEASURABLE, never zero. Zero working days
   * is a real value here — an office that finished the same day it received —
   * so a screen that rendered a missing figure as 0 would print "instant"
   * where the truth is "we cannot say". Typed nullable so the compiler finds
   * every place that has to decide which it is.
   */
  mean_working_days: number | null
  median_working_days: number | null
  slowest_working_days: number | null
  /** Holds whose filing has a tier set — the denominator of `breach_rate`. */
  classified_holds: number | null
  /** Holds where this office alone outran the whole statutory allowance. */
  breached: number | null
  /** `breached` as a percentage of `classified_holds`. */
  breach_rate: number | null

  /** Of `handled`, how many came from a business that looks like test data. */
  test_holds: number
}

/** One RA 11032 tier and how the comparable offices' holds sat against it. */
export interface OfficePerformanceTier {
  key: string
  label: string
  statutory_working_days: number
  holds: number
  within: number
  over: number
}

export interface OfficePerformanceReport {
  generated_at: string
  window_weeks: number
  window_start: string
  offices: OfficePerformanceRow[]
  /** All three, always — a tier with no filings still exists in the statute. */
  tiers: OfficePerformanceTier[]
  /** Comparable holds on a filing nobody has classified into a tier. */
  unclassified_holds: number
  totals: {
    offices: number
    handled: number
    open: number
    /** Offices whose figures are in the comparison — fewer than `offices`. */
    compared_offices: number
  }
  /**
   * What the averages above are carrying. `businesses` has no provenance
   * column, so `test_businesses` is a guess from the name and `patterns` is the
   * guess itself, printed so a reader can judge it.
   */
  data_quality: {
    total_businesses: number
    test_businesses: number
    test_holds: number
    patterns: string[]
  }
}

/*
 * Analytics Dashboard (docs/r-integration-spec.md §1).
 *
 * Mirrors App\Support\DashboardAnalytics exactly — that class is what the
 * endpoint returns, whether from a snapshot or computed on the request. Three
 * conventions run through the whole shape:
 *
 *  - **A null rate means "no rate exists"**, never zero. An empty denominator is
 *    not 0%, and a screen that printed one would be asserting a finding nobody
 *    computed.
 *  - **Windows differ per panel** and each panel states which it used. Volume and
 *    decision outcomes are this month; tier times, stage times, compliance,
 *    inspections and officer activity run on a trailing window; the rest are as
 *    of today.
 *  - **Counts and their denominators both travel**, so a screen can show the
 *    fraction behind a percentage instead of asking the reader to trust it.
 */

export interface DashboardKpis {
  active_businesses: number
  applications_ytd: number
  applications_this_month: number
  /** The permit-validity indicator, read off the compliance panel — not a fifth figure. */
  compliance_rate: number | null
}

export interface ApplicationVolumeRow {
  type: string
  label: string
  count: number
}

export interface DecisionOutcomeRow {
  outcome: 'approved' | 'returned' | 'rejected' | 'pending' | 'cancelled'
  label: string
  count: number
  /** Whether this bucket belongs in the Approval Rate denominator. */
  decisioned: boolean
}

/**
 * A statutory RA 11032 tier against its legal limit.
 *
 * `statutory_working_days` is a legal threshold (3 / 7 / 20) from the Ease of
 * Doing Business Act, not an internal service target, and `mean_working_days` is
 * measured in working days because that is how the statute sets the limit.
 * `observations: 0` with null means is a tier the register holds no decided filing
 * for — that is not a compliant tier and must not render as one.
 */
export interface ProcessingTierRow {
  tier: 'simple' | 'complex' | 'highly_technical'
  label: string
  statutory_working_days: number
  observations: number
  mean_working_days: number | null
  mean_calendar_days: number | null
  /**
   * Filings that met RA 11032's limit for their own tier — the same yardstick as
   * `breaching`, so the two can never contradict each other.
   */
  within_statutory: number
  within_statutory_rate: number | null
  /**
   * Filings that met `applications.deadline_at`, which the workflow sets to a flat
   * ten working days for every tier. A DIFFERENT and more lenient yardstick than
   * the statute — for a simple transaction it is over three times what the law
   * allows. It must never be labelled as statutory compliance.
   */
  within_recorded_deadline: number
  /** The recorded deadline in working days, when uniform across the tier. */
  recorded_deadline_working_days: number | null
  /** Signed: how far the mean sits above (or below) the statutory limit. */
  overage_days: number | null
  breaching: boolean
}

export interface StageRow {
  code: string
  name: string
  reviews: number
  mean_days: number
}

export interface StageBottleneck {
  code: string
  name: string
  mean_days: number
  reviews: number
  above_average_days: number | null
  share_of_reviews: number
}

export interface ComplianceIndicator {
  indicator: 'ra11032_processing' | 'permit_validity' | 'renewal'
  label: string
  numerator: number
  denominator: number
  numerator_label: string
  denominator_label: string
  rate: number | null
  /**
   * Set when the register cannot establish the numerator at all — distinct from
   * an empty denominator. Both give a null rate; only this needs explaining, and
   * the screen shows this sentence rather than printing 0%, which would read as a
   * compliance failure instead of a missing link in the data.
   */
  unavailable_reason: string | null
}

export interface RankedShareRow {
  rank: number
  count: number
  /** Null only when the total is zero. */
  share: number | null
}

export type BarangayShareRow = RankedShareRow & { barangay: string }
export type LineOfBusinessRow = RankedShareRow & {
  industry: string
  psic_code: string
}

export interface OrganizationFormRow {
  form: string
  label: string
  count: number
  /** Share of businesses whose form IS recorded, so recorded rows sum to 100%. */
  share: number | null
}

export interface InspectionRow {
  type: string
  label: string
  scheduled: number
  completed: number
  passed: number
  failed: number
  conditional: number
  /** Passed ÷ COMPLETED × 100 — never ÷ scheduled. Null when nothing is completed. */
  pass_rate: number | null
}

/**
 * The two figures the Officer Activity panel reports.
 *
 * `meetings_scheduled`, `meetings_attended` and `meetings_attended_rate` are
 * deliberately NOT declared, and the payload still carries all three. BizTrack
 * has no meetings feature — `RequestType` below is 'document' | 'message', so
 * no officer can raise one — which made that figure a statistic about nothing.
 * The card was removed (see OfficerPanel in pages/admin/AnalyticsPage.tsx) and
 * the fields are left off this type so the compiler refuses any screen that
 * tries to read them back onto a page. They are still emitted server-side —
 * DashboardAnalytics::officerActivityFacts() computes them — and nothing pins
 * them there any more now that there is only one implementation of the
 * dashboard, so dropping them is an ordinary change against api/. Leaving them
 * off this type is what holds the line in the meantime, and would still be the
 * right shape even if the payload were trimmed tomorrow.
 */
export interface OfficerActivity {
  responses: number
  mean_response_hours: number | null
  median_response_hours: number | null
  threads_awaiting_reply: number
  requests_total: number
  requests_fulfilled: number
  requests_fulfilled_rate: number | null
}

export interface MapPoint {
  business_id: number
  business: string
  barangay: string | null
  latitude: number
  longitude: number
  permit_state: 'active' | 'lapsed'
}

export type RenewalOutcome = 'on_time' | 'late' | 'not_renewed'

export interface RenewalForecast {
  /** The 31 December the permits expire on, and the last penalty-free day after it. */
  expires_on: string
  on_time_until: string
  horizon_days: number
  /** Business Permits expiring on `expires_on`. */
  permits: number
  /** Past terms the estimate learned from. */
  history: number
  history_outcomes: Record<RenewalOutcome, number>
  /** Why there is no estimate, or null when there is one. */
  unavailable: 'no_permits' | 'thin_history' | null
  /** Expected permits per outcome, whole numbers that add up to `permits`. */
  expected: Record<RenewalOutcome, number> | null
  shares: Record<RenewalOutcome, number> | null
  barangays: { barangay: string; not_renewed: number; permits: number }[]
}

export interface DashboardReport {
  generated_at: string
  window_months: number
  window_start: string
  ytd_start: string
  month_start: string
  today: string
  kpis: DashboardKpis
  volume: { rows: ApplicationVolumeRow[]; total: number }
  decisions: {
    rows: DecisionOutcomeRow[]
    total: number
    decisioned: number
    approved: number
    /** Approved ÷ decisioned × 100. The denominator EXCLUDES pending. */
    approval_rate: number | null
  }
  processing_tiers: ProcessingTierRow[]
  stages: {
    rows: StageRow[]
    reviews: number
    mean_days: number | null
    bottleneck: StageBottleneck | null
  }
  compliance: ComplianceIndicator[]
  /*
   * Back on the dashboard since Renewal Risk Prediction was removed (checklist
   * 2026-09-27, item 6). Three cumulative forward windows (30 ⊂ 60 ⊂ 90) and a
   * separate Expired row, per permit type.
   */
  expiry: {
    columns: { code: string; label: string }[]
    rows: {
      window: string
      label: string
      days: number | null
      expired: boolean
      counts: Record<string, number>
      total: number
    }[]
  }
  /*
   * Moved from Business Growth Analysis's Closure Trend, with registrations
   * beside it. Optional because a snapshot stored before the move lacks it.
   */
  business_movement?: {
    rows: { month: string; registered: number; closed: number; net: number }[]
    registered: number
    closed: number
  }
  /*
   * The coming January's Business Permit renewals, estimated
   * (App\Support\RenewalForecast). Null for an office that does not issue the
   * Business Permit; absent on a snapshot stored before the panel existed.
   */
  renewal_forecast?: RenewalForecast | null
  top_barangays: { rows: BarangayShareRow[]; total: number; groups: number }
  top_lines_of_business: {
    rows: LineOfBusinessRow[]
    total: number
    groups: number
  }
  organization_forms: {
    rows: OrganizationFormRow[]
    recorded: number
    unrecorded: number
    total: number
  }
  inspections: { rows: InspectionRow[]; combined: InspectionRow }
  officer_activity: OfficerActivity
  map: {
    mapped: number
    plotted: number
    total_businesses: number
    points: MapPoint[]
    by_barangay: {
      barangay: string
      businesses: number
      active: number
      share: number | null
    }[]
  }
}

/*
 * No StaffingSimulationReport type. App\Support\Des is a complete discrete-event
 * simulation, but the spec puts it out of scope for the delivered flow, so it
 * has no endpoint and no screen. Nothing to type until one of those exists.
 */

/* ── Admin ────────────────────────────────────────────────────────────── */

export interface AdminUser extends User {
  /**
   * What this officer is CARRYING, when the caller asked for it.
   *
   * Optional because the server sends it only on the officer directory
   * (`whenCounted` in UserResource) — every other payload built from that
   * resource is unchanged, and a required field here would be a claim about
   * those too.
   *
   * On `AdminUser` rather than on `User`: the auth user, the message
   * participants and the assignment payloads all render `User`, and none of
   * them carries a caseload.
   *
   * OPEN work only, by the rule the caseload screen uses. It can legitimately
   * be smaller than the Officer in Charge register's count for the same
   * person — that screen lists every assignment a name is on, finished ones
   * included, and a finished review is not something anybody is still
   * carrying.
   */
  open_reviews?: number
  open_inspections?: number
}

/** A role an officer account can be given, as the API describes it. */
export interface AdminRole {
  name: string
  /** roles.display_name — "Building Official Staff", not `obo_staff`. */
  label: string
  description: string | null
  /** False for the super admin, who works across every office and belongs to none. */
  wants_department: boolean
  /**
   * The offices where somebody currently holds this role.
   *
   * Derived from the accounts that hold it, not from a column — nothing in the
   * register ties a role to an office, and nothing should: the office is what
   * scopes an officer's work, and a BPLO account holding the Fire Inspector
   * role is legal if unusual.
   *
   * It exists so the Role box can put the office's own roles first instead of
   * offering six, five of which belong elsewhere. Empty for a role nobody
   * holds yet — including one typed a moment ago — which is why unused roles
   * are still shown rather than hidden.
   */
  used_in_departments?: number[]
  /*
   * Whether this role can still be handed out. False only for the super admin
   * once the single seat is taken — every office role stays available however
   * many accounts already hold it, because offices are meant to have several.
   */
  available: boolean
}

export interface AdminUserPayload {
  first_name: string
  middle_name?: string
  last_name: string
  suffix?: string
  gender: 'M' | 'F'
  email: string
  /* Required by the API on create; optional here because an edit may omit it. */
  mobile_number?: string
  password?: string
  /*
   * `roles`, plural, because that is what the endpoint validates.
   *
   * This said `role: string` and the form sent it, so "Add Officer" 422'd on a
   * missing `roles` every time — and the error came back keyed `roles`, which
   * the modal was not rendering, so the failure was completely silent. The API
   * now accepts either spelling; this is the one it has always documented.
   */
  roles: string[]
  /**
   * A job title typed in because the office has no role by that name.
   *
   * Read only when `roles` is empty. The server slugs it, reuses an existing
   * role when one matches by slug or by label, and otherwise creates one
   * carrying the permissions every office role has — a role created with none
   * would sign its holder in to a blank app.
   *
   * It always belongs to an office: a typed name must never reach a
   * departmentless role, because `admin` holds `user.manage` and that would be
   * privilege escalation by spelling. The endpoint refuses it without one.
   */
  new_role?: string
  /** Null clears the office. Omit to leave it alone. */
  department_id?: number | null
}

/** Open work handed back to an office because its officer left or moved. */
export interface ReleasedCaseload {
  reviews: number
  inspections: number
}

/** What an officer is holding, and who could take it. */
export interface AdminCaseload {
  user: { id: number; name: string }
  department: { id: number; code: string; name: string } | null
  open_reviews: number
  open_inspections: number
  total: number
  /**
   * Reviews this officer is NAMED on that are already finished.
   *
   * Nothing here can move — a completed review keeps the name of the officer
   * who made it. It is here so this dialog can reconcile itself with the super
   * admin's OIC register, which lists every assignment a name is on: that
   * screen says "officer in charge of two filings" where a caseload counting
   * only open work says "holding nothing", and both are true.
   *
   * Optional so a payload from before this shipped still type-checks.
   */
  finished_reviews?: number
  /**
   * The open work itself, named — one row per filing the officer holds.
   *
   * Capped server-side, so `cases.length` can be smaller than `total`; the
   * dialog says so rather than quietly showing a short list. Optional for the
   * same reason as `finished_reviews`: an older payload still type-checks.
   */
  cases?: CaseloadCase[]
  /**
   * The officer's OFFICE queue — open work nobody holds.
   *
   * The other direction the Reassign dialog offers: a case nobody has picked
   * up, handed to the officer whose row was clicked. Kept apart from `cases`
   * because they are opposite acts, and a single list would need a flag on
   * every row to say which way it moves.
   */
  unassigned?: CaseloadCase[]
  candidates: { id: number; name: string; email: string; open_total: number }[]
}

export interface CaseloadCase {
  /** A review is an office's assignment; an inspection is a site visit. */
  kind: 'review' | 'inspection'
  id: number
  application_id: number | null
  tracking_id: string | null
  /** Null when the business has been removed and the filing outlived it. */
  business: string | null
  office: { code: string; name: string } | null
  /**
   * The office's own permit on the filing. Null on an inspection — a site
   * visit is about the premises rather than one permit — and null when the
   * office holds a filing carrying no permit it issues.
   */
  permit: string | null
  status_label: string | null
  /**
   * Where the FILING has got to — and the reason this row is on the officer's
   * desk at all.
   *
   * Distinct from `status_label`, which is the OFFICE's own review step. The
   * two carry the same words and mean different things: a step marked
   * "Completed" on a filing still at "Pending Payment" is finished work on an
   * unfinished application, and the officer is still in charge of it.
   *
   * Optional: a payload from before this shipped carries no such key, and the
   * column prints a dash rather than inventing a state.
   */
  application_status_label?: string | null
  /** Assigned-at for a review, scheduled-at for a visit. */
  at: string | null
}

export interface CaseloadMovePayload {
  /** Null releases the caseload to the office queue rather than naming a successor. */
  to_user_id: number | null
  /**
   * Two ways to say what moves, and the API requires exactly one.
   *
   * `cases` names the rows and is what the dialog sends: the admin ticks the
   * permits the officer is holding, which is the ordinary act — one filing to a
   * colleague because it is stuck, the rest staying put. `scope` remains for
   * "move everything", which should not need forty ids to state, and is what
   * the Deactivate path uses when it releases a whole caseload.
   */
  scope?: 'all' | 'reviews' | 'inspections'
  cases?: { kind: 'review' | 'inspection'; id: number }[]
  reason: string
}

export interface CaseloadMove {
  moved_reviews: number
  moved_inspections: number
  total: number
  to: { id: number; name: string } | null
}

export interface AuditLog {
  id: number
  action: string
  user: { name: string } | null
  /**
   * What the action was done TO, and null when it was done to nothing in the
   * register: a KwikPay callback refused for its signature, a payment gateway
   * switched. Typed as a string alone, `split` on the first such row threw and
   * blanked the whole Audit Logs screen.
   */
  auditable_type: string | null
  auditable_id: number | null
  changes: Record<string, unknown> | null
  /**
   * The record as it stood before a delete or retire (Audit Log 1). Null on
   * every row that removed nothing.
   */
  snapshot: Record<string, unknown> | null
  created_at: string
}

/* ── Importing the old register (Ken's checklist, 27 Sept 2026) ───────── */

/** Why a row was rejected. `kind` is one of LegacyImporter's fixed list. */
export type LegacyRejectKind =
  | 'bad_date'
  | 'unknown_barangay'
  | 'unknown_permit_type'
  | 'missing_owner'
  | 'duplicate'
  | 'missing'
  | 'invalid'

export interface LegacyImportReject {
  row: number
  legacy_business_id: string | null
  business_name: string | null
  reasons: { kind: LegacyRejectKind; message: string }[]
}

export type LegacyImportStatus = 'previewed' | 'queued' | 'running' | 'completed' | 'failed'

export interface LegacyImport {
  id: number
  source: 'csv' | 'odbc'
  file_name: string | null
  status: LegacyImportStatus
  total_rows: number
  will_create: number
  will_update: number
  rejected: number
  created_count: number
  updated_count: number
  processed_rows: number
  breakdown: {
    businesses_new: number
    businesses_updated: number
    permits_new: number
    permits_updated: number
    owners_linked: number
    owners_unclaimed: number
    reject_kinds: Record<LegacyRejectKind, number>
  } | null
  /** Null on the history list; the first 500 on a single import. */
  rejects: LegacyImportReject[] | null
  error: string | null
  user: { name: string } | null
  created_at: string | null
  finished_at: string | null
}

export interface LegacyImportGuide {
  columns: { column: string; required: 'always' | 'with a permit' | 'no'; description: string }[]
  barangays: string[]
  permit_types: { code: string; name: string }[]
  queue_above: number
}

/* ── Messaging (per-application thread; v2 CONTRACT) ──────────────────── */

export interface MessageAttachment {
  id: number
  original_filename: string
  download_url: string
}

export interface Message {
  id: number
  body: string
  sender: { id: number; name: string; is_officer: boolean }
  /**
   * The office this turn is with. A conversation is scoped to
   * `(application, office)`, so a message has an addressee; null only while the
   * API did not load the relation, never because the turn has no office.
   */
  department: { id: number; code: string | null; name: string } | null
  attachments: MessageAttachment[]
  created_at: string
}

/**
 * One office an applicant may hold a conversation with about a filing.
 *
 * The list is the offices ACTUALLY on the filing (its assignments) plus BPLO,
 * which coordinates every filing and is who you write to when you do not know
 * who else to ask — never a roster of every department in the city. The API
 * refuses a message to anything outside it; this list is what stops the
 * applicant being refused in the first place.
 *
 * `can_message` is false only for an office that has come OFF the filing since
 * writing: its correspondence stays readable, but the conversation is closed.
 */
export interface MessageOffice {
  department_id: number
  code: string | null
  name: string
  thread_id: number | null
  messages_count: number
  /** Turns this office wrote that the reader has not opened. Never your own. */
  unread_count: number
  last_message_at: string | null
  can_message: boolean
}

/**
 * A finding recorded against the person an office is talking to.
 *
 * `blacklisted` is against the PERSON and reaches everything they hold;
 * `suspended` and `flagged` are against the business this conversation is
 * about. The server decides which one applies — see counterpartyStanding().
 */
export interface CounterpartyStanding {
  kind: 'blacklisted' | 'suspended' | 'flagged'
  /** The finding in the API's own words, e.g. "Business suspended". */
  label: string
  /**
   * How many of this person's businesses are suspended in all.
   *
   * The scale behind the finding: an officer answering about one suspended
   * shopfront is better for knowing whether it is the only one or the third
   * [client, 1 October 2026].
   *
   * It does not decide WHICH note is shown — that stays specific to the
   * business this conversation is about. Zero on a blacklisting, where the
   * cascade has set every business to `blacklisted` and none is suspended.
   */
  suspended_count: number
}

/** One conversation row in the Messages inbox (GET /message-threads). */
export interface MessageThreadSummary {
  /*
   * Which of the two shapes this row is.
   *
   * 'general' is an enquiry with no filing behind it — the conversation a
   * business owner can have with BPLO before they have applied for anything.
   * 'admin' is an office account's standing line to the System Administrator,
   * pinned to the top of their inbox: office accounts cannot edit their own
   * details, so this is where they ask the account that can.
   *
   * Stated rather than inferred from a null application_id, so a reader
   * branches on a fact instead of on a missing value that reads like a bug.
   */
  kind: 'application' | 'general' | 'admin'
  /** Set on a general row only; null on a filing, whose threads are per office. */
  thread_id: number | null
  /**
   * WHICH office a general enquiry is with. Null on a filing, which carries
   * its offices in `offices` instead.
   *
   * A general row used to be one row - the owner's line to BPLO - so `kind`
   * alone told the rows apart. Every office has a front door now [client, 28
   * September 2026], so an inbox can hold several at once and this is what
   * separates them: in the React key, in the URL, and in what gets sent.
   */
  department_id: number | null
  /** Whose enquiry it is — what an officer opens a general conversation by. */
  user_id: number | null
  /** Null on a general enquiry: there is no filing to point at. */
  application_id: number | null
  tracking_id: string | null
  business_name: string | null
  status: string | null
  /** Whoever the reader is talking to: the applicant, or the officer/office. */
  counterparty: {
    name: string
    subtitle: string | null
    is_officer: boolean
    /**
     * Where the person writing to this office currently stands, when a finding
     * is recorded against them [client, 30 September 2026]. Null for anybody in
     * good standing, and null on every row an APPLICANT reads — they are told
     * about their own standing by the restriction notice, not by a chip on
     * their own conversation.
     */
    standing?: CounterpartyStanding | null
  }
  /**
   * The office answerable for this filing (checklist item 73) — one office, the
   * one this conversation belongs to, never the whole routing list. Null before
   * the filing has been routed to anybody, which is a real state: assignments
   * are only created once payment clears.
   *
   * `officer` stays null until somebody in that office picks the file up.
   */
  responsible_office: {
    code: string | null
    name: string
    officer: { id: number; name: string } | null
  } | null
  /**
   * The conversations this row covers, one per office, busiest first.
   *
   * The inbox pages by FILING and not by conversation, because a filing nobody
   * has written on yet still needs a row — that row IS the way in, and it
   * cannot come from a list of threads that do not exist. Which office each
   * conversation is with is said here instead.
   */
  offices: MessageOffice[]
  /** Every readable turn on the filing: for an office, its own conversation. */
  messages_count: number
  /**
   * How many of those the reader has not opened, summed over `offices`.
   *
   * The same definition the nav badge counts by — a turn somebody else sent,
   * still unread — so the inbox and the badge cannot disagree about what is
   * waiting.
   */
  unread_count: number
  last_message: {
    body: string
    sender_name: string | null
    mine: boolean
    created_at: string
  } | null
  updated_at: string | null
}

/* ── Officer requests (Other Requirements; v2 CONTRACT) ───────────────── */

export type RequestType = 'document' | 'message'

/**
 * `needs_resubmission` was missing here while the API has emitted it since the
 * resubmission round, so every screen narrowed it away and the one status that
 * asks something of the applicant was the one TypeScript said could not happen.
 */
export type RequestStatus =
  'pending' | 'submitted' | 'fulfilled' | 'needs_resubmission' | 'rejected'

/** One applicant submission; a requirement can collect several. */
export interface OfficerRequestResponse {
  id: number
  /** 1-based, so the reader sees "Submission #2" without counting rows. */
  number: number
  body: string | null
  author: { name: string | null }
  document: { id: number; filename: string | null } | null
  /**
   * What became of THIS submission. Null while it is still with the office —
   * and on rows that predate per-submission verdicts being recorded at all.
   * The parent's `remarks` is always the LATEST verdict, so a history rendered
   * from it alone attributes today's reason to every earlier attempt.
   */
  review_outcome: RequestStatus | null
  review_status_label: string | null
  review_remarks: string | null
  reviewed_at: string | null
  created_at: string
}

export interface OfficerRequest {
  id: number
  request_type: RequestType
  /** Which rule raised it (`business.tin`, `denr.WDP`, `rule.*`); null when an officer wrote it. */
  system_key: string | null
  /**
   * The one field that answers it, or null for the free reply.
   * Client, 5 October 2026: TIN and DENR show "a field instead of a 'Response' thingy".
   */
  answer_field: { kind: 'tin' | 'document'; label: string } | null
  subject: string
  body: string
  status: RequestStatus
  status_label: string
  /**
   * Null once the officer who raised it has been removed from the register.
   *
   * This was declared non-nullable while OfficerRequestResource has always
   * emitted null for it, so a guard against it was invisible to the compiler
   * and a later edit could drop one without a word. Reading `.name` straight
   * through white-screened the entire page — there is no error boundary above
   * this route — which is the same class of bug as checklist items 83 and 87.
   */
  created_by: { name: string; department: string | null } | null
  /**
   * The office that raised this — taken from the signed-in account server-side,
   * never chosen. It is what makes "from the City Health Office" and "visible
   * to the City Health Office" the same statement.
   */
  from_office: Department | null
  /** The office's verdict on the latest submission. */
  remarks: string | null
  /** Whether the applicant may still answer. Do not re-derive from `status`. */
  accepts_response: boolean
  /**
   * Whose move it is, decided by the API so two screens cannot disagree.
   * Pending and Needs Resubmission are one situation to an owner — you owe us a
   * document — and anything counting outstanding requirements counts both.
   */
  awaits_applicant: boolean
  awaits_office: boolean
  is_closed: boolean
  /** The note written when the requirement was raised, not the review verdict. */
  additional_remarks: string | null
  /** An optional file the OFFICE attached: a blank form, a template. */
  reference: { name: string | null; url: string } | null
  /** Deadline, when the office set one. */
  due_date: string | null
  reviewed_at: string | null
  /**
   * The recipient (checklist item 89). Always the applicant on the filing — a
   * request is answered through `request.respond`, which only business owners
   * hold — so this names who it went to rather than offering a choice the
   * schema cannot honour. Null when the account has since been removed.
   */
  recipient: { id: number; name: string; kind: 'applicant' } | null
  /**
   * The filing this was raised on. Null once that filing is soft-deleted, and
   * `business_name` null once the BUSINESS is — 139 filings in the register
   * point at a removed business, so the inner null is the common one. Use
   * `businessName()` from lib/format rather than rendering it raw.
   */
  application: {
    id: number
    /** The number shown beside the business name — the client's "Business Number". */
    tracking_id: string
    business_id: number | null
    business_name: string | null
  } | null
  /** Latest reply, mirrored for older clients; `responses` is the full thread. */
  response_body: string | null
  responses: OfficerRequestResponse[]
  created_at: string
  responded_at: string | null
}

/**
 * A status an office may set on a requirement, with the word to show for it.
 *
 * Served with the requirements list rather than hard-coded here: the labels are
 * already decided in PHP (OfficerRequestStatus::label), and a second copy in
 * TypeScript is how a screen ends up offering "Fulfilled" months after the
 * register started calling it "Approved".
 */
export interface OfficeStatusOption {
  value: RequestStatus
  label: string
}

/**
 * What the Create Other Requirement form sends.
 *
 * No `department_id` and no `request_type`: the office comes from the signed-in
 * account (the API ignores one sent anyway) and an Other Requirement is a
 * document request by definition. Both were fields the officer used to have to
 * fill in, and one of them let an office file work into another office's queue.
 */
export interface CreateRequirementPayload {
  /** Requirement / Document Name. */
  subject: string
  /** Description / Instructions. */
  body?: string
  /** Deadline, ISO date. */
  due_date?: string
  additional_remarks?: string
  /** Attachment / Reference File — a blank form or template for the applicant. */
  reference?: File | null
}

/* ── Renewal/amendment prefill (v2 CONTRACT) ──────────────────────────── */

export interface PrefillResult {
  business: Business
  last_permit: {
    id: number
    permit_number: string
    /** `{ code, name }`, or null when the permit type row is gone. */
    permit_type: { code: string; name: string } | null
    valid_until: string | null
  } | null
  /**
   * Item 85 — the permits of THIS business that may be renewed, soonest to
   * expire first. Expired ones are here on purpose (a lapsed permit is what
   * gets renewed); revoked and suspended ones are filtered out server-side,
   * because a revoked permit is appealed, not renewed.
   *
   * This replaces filtering the owner's whole paginated permit list in the
   * browser, which could hide the permit being renewed on page two.
   */
  renewable_permits: Permit[]
  /**
   * The permits above that a submitted renewal is already carrying. The
   * picker shows them greyed out, labelled `Renewal in progress`; the server
   * refuses a second renewal of them either way (`RenewablePermit`).
   */
  renewal_in_progress_permit_ids?: number[]
  last_application: { id: number; permit_type_ids: number[] } | null
  suggested_permit_type_ids: number[]
  /**
   * The fee profile of the last SUBMITTED filing, which is Section B as this
   * business last declared it.
   *
   * A renewal asks Section B and nothing else, and the client asked for it to
   * arrive answered. Read in preference to the business record because the
   * register stores delivery units as one total and the paper asks for two
   * counts — only the profile knows the split.
   *
   * Null for a business that has never filed.
   */
  last_fee_profile: FeeProfile | null
}

/**
 * The paper BPLO form's "Amendment from:" block (checklist items 82/84).
 * Null on new and renewal filings: that form never asks the question, which is
 * a different fact from asking it and being told no.
 */
export interface AmendmentDetails {
  /** True when at least one kind is ticked. Derived server-side. */
  has_amendments: boolean
  ownership: boolean
  location: boolean
  nature: boolean
  /** The "Others (specify)" text; non-empty text IS the tick. */
  other: string | null
  /**
   * Section A3 of the renewal/amendment form — the structure the business
   * changed FROM and TO. Null unless A1 was answered Yes, which is the same
   * "never asked" vs "asked and answered no" distinction the whole block draws.
   */
  from_registration_type: string | null
  to_registration_type: string | null
  /** Ready-to-render labels, e.g. `['Location', 'Others: new co-owner']`. */
  summary: string[]
}

/** OCR-lite suggestions returned on a PDF document upload (v2 CONTRACT). */
export interface OcrSuggestions {
  business_name?: string
  registration_number?: string
  valid_until?: string
}

export interface UploadedDocument extends AppDocument {
  ocr_suggestions?: OcrSuggestions
}

/* ── Admin businesses (Owner Status page; v2 CONTRACT) ────────────────── */

export type BusinessStatus = 'active' | 'flagged' | 'suspended' | 'blacklisted'

/** GET /admin/owners — one row per business owner [client, 5 October 2026]. */
export interface AdminOwner {
  id: number
  name: string
  email: string
  status: 'active' | 'blacklisted'
  status_label: string
  blacklisted_at: string | null
  reason: string | null
  blacklisted_by: string | null
  businesses: {
    id: number
    name: string
    ban: string | null
    status: BusinessStatus
    status_label: string
    tracking_id: string | null
    created_at: string | null
  }[]
}

/** One entry of GET /admin/owners/{id}/history, newest first. */
export interface OwnerHistoryEntry {
  at: string | null
  to: 'active' | 'blacklisted'
  label: string
  reason: string | null
  by: string | null
}

export interface AdminBusiness {
  id: number
  name: string
  /** The Business Account Number (BP-YYYY-NNNN). */
  ban?: string | null
  /**
   * The business number under the name on the table — a FILING's `BIZ-2026-…`.
   *
   * Minted per APPLICATION, so a business that renews or amends holds several;
   * this is the LATEST, and `applications_count` is what stops it reading as
   * the only one. Null when the business has never filed.
   */
  tracking_id?: string | null
  /** How many filings this business has. 0 is a real answer. */
  applications_count?: number
  owner: {
    id: number
    name: string
    /**
     * Whether the PERSON is barred, as opposed to this shopfront.
     *
     * After a blacklisting cascades, three rows of one owner all read
     * "Blacklisted" with nothing to say they are one sanction rather than
     * three. This is what lets the row say so.
     */
    blacklisted?: boolean
  } | null
  status: BusinessStatus
  status_label: string
  created_at: string
  /**
   * When the business was removed from the register ("retired"), or null.
   * Only the Retired filter lists such rows, and nothing can be done to one —
   * every action route binds the business, and binding skips removed rows.
   */
  retired_at?: string | null
  /**
   * Permit fees this business has been issued and not yet paid for.
   *
   * A clearance renewed outside January is issued unbilled — its fee is
   * collected on the next business permit renewal (client, 17 September 2026).
   * Until then it is a receivable against the business, and the decision was
   * that it *"waits indefinitely, and is visible"*: no penalty, no lapsing
   * permit, but nobody has to remember it either.
   *
   * `total` is what the LGU is owed; `items` is what it is owed for, which is
   * what turns a figure into something an officer can raise with the owner.
   * `on_a_bill` marks a fee already sitting on a renewal the applicant has been
   * shown and not yet settled — still outstanding, but a chase already in
   * flight.
   *
   * Optional so a payload built before this existed still type-checks; readers
   * must cope with its absence rather than assume zero, because zero and
   * "not sent" are different facts.
   */
  unbilled_fees?: {
    total: number
    items: {
      permit_type: string | null
      permit_code: string | null
      amount: number
      incurred_at: string | null
      on_a_bill: boolean
    }[]
  }
  /**
   * What the change actually did, beyond the row that was clicked.
   *
   * Blacklisting one business of three blacklists the owner and all three, so
   * a reply describing only the clicked row hides two thirds of what just
   * happened. These come back from POST /admin/businesses/{id}/status and are
   * absent from the roster payload - hence optional.
   */
  owner_blacklisted?: boolean
  others_blacklisted?: number
  others_restored?: number

}

/* ── Per-office application forms (UI prototype Parts 4-7, pages 040-043) ── */

/** Opaque free-form JSON keyed by permit type; stored verbatim by the API. */
export interface OfficeForm {
  permit_type_code: string
  /** Present on the full application payload (officer review). */
  permit_type_name?: string
  /** Issuing department code (BPLO, CHO, BFP, ...) on the full payload. */
  department_code?: string
  /**
   * Has the applicant actually saved anything against this sheet?
   *
   * The officer payload carries an entry for every form-bearing permit type on
   * the filing, saved or not, so that an office is never left inferring from an
   * absence whether the applicant skipped its form or the screen is broken. A
   * sheet of derived-only answers looks identical either way, so the server
   * says which.
   */
  form_saved?: boolean
  form_data: Record<string, unknown>
  /**
   * The CHECKLIST OF REQUIREMENTS, which only the zoning paper has.
   *
   * `null` on the other four sheets and NOT an empty array, because the two
   * mean different things: null is "this office's form has no checklist", an
   * empty array would be "this office asks for nothing", and only one of those
   * is true of CHO, BFP, OBO and CENRO.
   */
  requirements?: OfficeFormRequirement[] | null
  /**
   * The zone the traced map puts the filing's pin in — on CPDD's sheet only,
   * null on the other four and wherever the zone is unknown. `name` is the
   * sheet's own ("R-2 Basic or R-2 Max"); lib/zoningNames.ts says it plainly.
   */
  zone_at_pin?: { codes: string[]; name: string } | null
  /**
   * What the applicant changed on the rows this office last returned.
   *
   * Includes rows that did NOT change — the resubmit gate lets a file
   * the office called wrong come back identical, so this is how the
   * office finds out without opening it again.
   */
  corrections?: ClearanceCorrection[]
  /**
   * Last year's answers, OFFERED to a renewal — not applied.
   *
   * A renewal's office form is the same form as a new application's, so the
   * office wants the same facts about the premises every year. Nothing carried
   * them forward until 18 September 2026, so applicants re-typed the water
   * source and the sanitary classification annually.
   *
   * ── Why this is not merged into `form_data` ───────────────────────────────
   *
   * The client's decision was "filled in and flagged, per field": the applicant
   * has to be able to see which answers came from last year and confirm them.
   * Folded into `form_data` a carried answer would be indistinguishable from a
   * reviewed one the moment the sheet reloaded — and the client would autosave
   * it straight into the register as the applicant's own words, on a statutory
   * form they sign.
   *
   * So the sheet seeds empty fields from this, flags each one, and writes only
   * when the applicant saves. An answer already in `form_data` is never offered
   * here, which is what makes the flag clear itself.
   *
   * Never carries a DERIVED answer (it would describe last year's filing), an
   * OFFICER-written issuance date, or an ATTESTATION — a signature is an act,
   * not a fact. See `App\Support\RenewalPrefill`.
   */
  prefill?: Record<string, unknown>
  /**
   * Where each offered key came from: last year's sheet, or the applicant's
   * account (the owner's home address, which the business permit never asked).
   * The flag beside the field reads this. Absent means `previous`.
   */
  prefill_from?: Record<string, CarriedSource>
}

export type CarriedSource = 'previous' | 'account' | 'application' | 'business'

/**
 * One row of MCG-CPDD-FO-003 v1.2's checklist, answered for this filing.
 *
 * The server decides which rows apply — the owned/rented branch comes from
 * `businesses.is_rented` and the representative row from item IX — so the
 * screen renders what it is given rather than re-deriving the paper's rules in
 * a second place. See `App\Support\ZoningRequirements`.
 */
export interface OfficeFormRequirement {
  /** Stable identifier for the paper's row (TCT, SKETCH, DECLARATION, …). */
  key: string
  /**
   * The document-type code to upload under, or null when nothing is uploaded
   * here — a row already answered by a business-permit attachment, or by the
   * sheet itself.
   */
  code: string | null
  label: string
  note: string
  /**
   * Where the answer comes from. `upload` takes a file on this sheet;
   * `carried` is already on the filing from step 4 of the wizard; `sheet` is
   * the form itself ("completely filled-up application form").
   */
  source: 'upload' | 'carried' | 'sheet'
  satisfied: boolean
  /**
   * The business-permit attachment that answers this row, when one does.
   *
   * Null on an `upload` row, which is its own source, and on a `sheet` row,
   * which is the form itself. A `carried` row has no slot of its own —
   * `code` is null on it — so this is the only machine-readable statement
   * of WHICH document it is waiting for; the note says it in prose.
   */
  carried_from?: string | null
  /**
   * Which files on this row belong to the business permit rather than to this
   * sheet. Remove is not offered on them: it means "I attached the wrong page
   * to this checklist", never "take it off my business permit".
   */
  carried_document_ids?: number[]
  /**
   * Does an unsatisfied row stop the sheet being handed in?
   *
   * True for every documentary row since 30 September 2026, on the client's
   * instruction reading the Locational Clearance form — *"Are the
   * documentary fields here not required? Make sure they are required."*
   * Before that only the notarised Applicant Declaration blocked, on the
   * reading that CPDD's paper is a counter checklist a clerk ticks on
   * receipt; what settled it is that the office cannot act on a filing
   * missing the documents its decision rests on either way.
   *
   * A `sheet` row is the exception, because it IS the form and is satisfied
   * only by being submitted.
   *
   * The server owns the judgement — see `App\Support\ChecklistSupport` and
   * `WorkflowService::submitClearanceForm`, which refuses what this greys
   * out — so the applicant's gate and the officer's review screen cannot
   * disagree about it.
   *
   * Optional on the wire so an older API that does not send it reads as "does
   * not block", which is the behaviour this replaced.
   */
  blocking?: boolean
  /**
   * EVERY file under this row, newest first.
   *
   * A slot held one until 30 September 2026 — the office upload endpoint
   * deleted the previous file on each press — and the business permit
   * form has always taken many per requirement. `document` below is the
   * first of these and stays for the rows that are single by nature: a
   * `carried` file from the business permit, and the officer's one-line
   * read of the checklist.
   */
  documents?: {
    id: number
    filename: string
    size_bytes: number | null
    uploaded_at?: string | null
  }[]
  document: {
    id: number
    filename: string
    size_bytes: number | null
    uploaded_at: string | null
  } | null
  /**
   * What answers the row when the answer is not a file.
   *
   * CENRO's FOR RENEWAL row is satisfied by a CERTIFICATE the register issued —
   * a `permits` row, not an attachment — so there is nothing to open and the
   * permit number is what identifies it. Absent on every row whose answer is a
   * document.
   */
  reference?: string | null
}

/* ── LGU Clearances (the stage that opens after the first payment) ────── */

/*
 * The six supporting clearances (docs/clearances-after-payment.md). Each is a
 * separate transaction with a separate office, a separate fee and a separate
 * outcome, which is why it has a state of its own instead of being a tick on
 * the application.
 *
 * They are decided AFTER BPLO has approved the form and the bill has been
 * settled, not before. That one bill covers all five — there is no per-clearance
 * accrual any more — and the business permit is released when every clearance
 * has been approved, not when a balance reaches zero.
 */
/**
 * What `ClearanceService::state()` actually sends, which is not what this said.
 *
 * It was `'available' | 'applied' | 'submitted' | 'issued' | 'rejected'` — the
 * INFERRED states of the old model, where a clearance's standing had to be
 * guessed from what existed: a permit row meant issued, an attached pivot meant
 * applied, a held copy meant submitted, nothing meant available.
 *
 * That inference is gone. `application_permit_types.status` is the fact now, and
 * `state()` returns it straight through, falling back to `'available'` only for
 * a permit type with no pivot row at all. So the server has been sending
 * `not_started`, `for_approval`, `for_inspection`, `approved` and `returned` —
 * none of which this union contained, and three of the names it did contain
 * (`applied`, `submitted`, `issued`) the server can no longer produce.
 *
 * The cost was not theoretical. Every branch on this type in ClearanceStagePage
 * was comparing against strings that never arrive, so Apply stopped applying:
 * `submit()` attaches all five clearances at `not_started`, the Apply handler
 * ran its POST only for `'available' | 'submitted'`, and the applicant got the
 * office form opened for a clearance that had never been started — then a 422
 * on save, because no assignment existed for the office to hold it.
 *
 * Aliased rather than re-listed so it cannot drift from `ClearanceStatus` again.
 */
export type ClearanceState = ClearanceStatus

export interface Clearance {
  permit_type: {
    id: number
    code: string
    name: string
    /** Null when the permit type has no department row (ClearanceService::row). */
    department: { code: string; name: string } | null
  }
  state: ClearanceState
  /** True when that office has an applicant-facing form sheet to fill in. */
  has_office_form: boolean
  /**
   * "Saved at all", not "every field answered" — the FSIC sheet's answers are
   * all derived server-side, so it legitimately saves an empty object.
   */
  office_form_complete: boolean
  /**
   * Which route the applicant took, or null before they choose.
   *
   * `state` used to answer this, because Apply moved the permit straight to
   * For Approval. Submitting is its own act now — you hand a clearance in by
   * giving the office something to read — so a permit applied for but not yet
   * filled in sits at `not_started`, and only `mode` tells it apart from one
   * nobody has touched.
   */
  mode: ClearanceMode | null
  /**
   * The office's review of this clearance, once it has one.
   *
   * Null for the whole of the draft: assignments are raised at payment, by
   * WorkflowService::routeToDepartments, not when the card is ticked. Ticking a
   * card in a draft used to raise one, which started the office's service-time
   * clock (`assigned_at`) days before the office could have seen the filing.
   */
  assignment: {
    id: number
    status: string | null
    remarks: string | null
  } | null
  /**
   * What the office asked the applicant to fix, in the officer's own words.
   *
   * ── Not `assignment.remarks`, and that was a real defect ──────────────────
   *
   * The returned card read `assignment.remarks`, which is the wrong column.
   * `WorkflowService::returnClearance` writes the reason to the PIVOT's
   * `remarks`; nothing writes the assignment's on a return, and
   * `completeAssignment` writes it on an APPROVAL. So the card could show an
   * approval note under a heading about changes being requested, or say "no
   * reason was recorded" beside a reason that was.
   */
  return_note: string | null
  /**
   * The refusal, which outlives the re-application it causes.
   *
   * `return_note` above is the CURRENT instruction and goes null when the
   * applicant answers it. These three survive that on purpose: the sheet
   * reopens with every answer still in it, so the remedy has to stay in
   * front of the applicant while they fill it in again, and the office has
   * to be able to see it refused this once when the same form comes back.
   */
  rejected_at: string | null
  rejection_note: string | null
  rejection_remedy: string | null
  /**
   * WHICH document or answer the note is about, as a stable code — a
   * `document_types.code` or an office-form answer key. Null when the officer
   * pointed at nothing, which is an ordinary return.
   *
   * Exists so nothing has to read the prose to work out what it refers to.
   */
  return_target: string | null
  /**
   * The office's note for EACH returned row, keyed by the same code as
   * `return_target`.
   *
   * The officer's screen will not send an office return until every ticked
   * row has one. Nothing stored them until 30 September 2026, so the
   * applicant saw the notes only run together in `return_note` above, with
   * the rows they describe blank.
   */
  return_notes?: Record<string, string>
  /** When it was last sent back. Elapsed time only, never a due date. */
  returned_at: string | null
  /**
   * ALREADY FORMATTED — "₱735.00". `PermitFees::peso` puts the sign on
   * server-side, so this is display text, not an amount. Passing it through
   * formatMoney() yields "₱0.00" (Number("₱735.00") is NaN), which would quote
   * a free clearance on the button that spends the applicant's money.
   *
   * Its MEANING depends on `state`, which is easy to miss: when the clearance
   * is not applied for it is what applying WOULD add to the Tax Order of
   * Payment; once it is applied for the server flips the counterfactual, so it
   * is what that clearance IS costing on the filing as it stands.
   *
   * Null means the number cannot be computed — the market stall rental, which
   * the office sets case by case, or a filing whose business row is gone. Not
   * the same as free, and never to be rendered as ₱0.00.
   */
  fee_preview: string | null
}

/**
 * The gate and the ledger, alongside the six rows.
 *
 * Contract: docs/clearances-after-payment.md, "API contract for the rebuild".
 *
 * ── The gate ──────────────────────────────────────────────────────────────
 *
 * `unlocked` is false until the FIRST payment clears. Before that the stage is
 * visible but shut — the applicant can see which clearances exist and what
 * they would cost, and can press nothing.
 *
 * `locked_reason` is the sentence the screen shows instead, and it is rendered
 * VERBATIM. This is not a stylistic preference: the condition that closes this
 * stage is the server's to state, because only the server knows what has been
 * submitted, assessed and paid. A sentence written on the screen would be a
 * second, quieter copy of that rule, and it drifted out of step with the real
 * one the first time the flow moved. Null is the degenerate case (locked with
 * no reason given) and the screen falls back rather than inventing a cause.
 *
 * ── The ledger ────────────────────────────────────────────────────────────
 *
 * Three figures, because a clearance applied for after payment raises a
 * balance and the permit is not released until that balance reaches zero. The
 * gate is what makes the accrual real; without it the balance is decoration.
 *
 * ALL THREE ARE DISPLAY TEXT, not amounts — "₱10,801.00" — for the same reason
 * `fee_preview` is: `PermitFees::peso` puts the sign, the separators and the
 * two decimal places on server-side, and one formatter is how the peso sign
 * stays in one place. Do NOT hand these to formatMoney(): Number("₱10,801.00")
 * is NaN and formatMoney answers "₱0.00" for NaN, so a filing with ten thousand
 * pesos outstanding would render as fully paid. Use `pesoToNumber` (format.ts)
 * when an actual comparison is needed, which on this screen is exactly once —
 * "has the balance reached zero".
 */
export interface ClearanceMeta {
  unlocked: boolean
  locked_reason: string | null
  /** Everything assessed on this filing so far, business permit included. */
  total_assessed: string
  /** What has actually been received against it. */
  total_paid: string
  /** The gap. The permit is not released until this reads zero. */
  balance_due: string
}

/* ── Public verify ────────────────────────────────────────────────────── */

/**
 * What `/verify/{permit_number}` answers — the public page a permit's QR opens.
 *
 * The business details are the certificate's face as it was SIGNED, so they
 * match the paper the scanner is holding. Every one of them can be null: a
 * certificate issued before the snapshot existed, or a business removed from
 * the register, has gaps, and the page prints a dash rather than inventing.
 * No owner name, and no revocation reason — see VerifyController.
 */
export interface VerifyResult {
  permit_number: string
  /** active | expired | suspended | revoked | superseded — expired also when an active permit's term has passed. */
  status: string
  status_label: string
  valid_from: string | null
  valid_until: string | null
  /** The date a revoked permit was revoked; null otherwise. */
  revoked_at: string | null
  /** A suspended permit's date and the permit/office it waits on — never the reason. */
  suspended_at?: string | null
  suspended_for?: SuspendedFor | null
  permit_type: { name: string } | null
  business: {
    name: string | null
    trade_name: string | null
    address: { line: string | null; barangay: { name: string } | null; city: string | null }
  }
  is_valid: boolean
}

/**
 * One barred person, and everything registered to them.
 *
 * ── Why the register of blacklistings is a register of PEOPLE ─────────────
 *
 * A blacklisting is a finding about whoever is filing, not about a premises,
 * and it bars every business they hold — including any they register
 * afterwards. Listing it as businesses answers "which shopfronts are barred"
 * and leaves the question an admin actually has ("who is barred, and what does
 * it cover?") to be assembled by eye from rows that repeat one name.
 */
export interface BlacklistedOwner {
  id: number
  name: string
  email: string
  mobile_number: string | null
  blacklisted_at: string | null
  reason: string | null
  /** The officer who imposed it. Null on records predating the column. */
  blacklisted_by: string | null
  businesses: {
    id: number
    name: string
    status: BusinessStatus
    status_label: string
    /**
     * Registered AFTER the bar, so no cascade ever touched it: its own status
     * still reads Active while every filing attempt is refused. Shown, rather
     * than left for an admin to notice and doubt.
     */
    registered_after: boolean
  }[]
}

/**
 * One office account on the System Administrator's inbox.
 *
 * ── Why it is a list of ACCOUNTS and not of conversations ─────────────────
 *
 * An officer who has never written has no thread, and that row is the way in:
 * the administrator has to be able to start one too, which is how "your
 * details have been updated" reaches the person who asked. A list built from
 * threads would hide every officer who had not spoken yet.
 */
export interface StaffMessageRow {
  user: { id: number; name: string; email: string }
  office: { id: number; code: string | null; name: string } | null
  /** Null until somebody writes. The row exists either way. */
  thread_id: number | null
  messages_count: number
  /** Turns the reader has not opened. Never their own. */
  unread_count: number
  last_message_at: string | null
  preview: string | null
  last_sender: string | null
}
