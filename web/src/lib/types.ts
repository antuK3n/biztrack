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
  department: Department | null
  is_active: boolean
  email_verified_at: string | null
  roles: string[]
  permissions: string[]
}

/** Laravel error envelope: HTTP status + message, plus field errors on 422. */
export interface ApiError {
  status: number
  message: string
  errors: Record<string, string[]>
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

/**
 * One entry from the classification legend printed on CPDO's zoning sheets.
 *
 * Held on the server (`zoning_classifications`) rather than as a union type here
 * on purpose: the maps are *proposed* for a plan period ending 2027, so an
 * ordinance can add or rename one, and a TypeScript union would make that a
 * deploy. Treat these strings as data, not as a closed set to switch on.
 */
export interface ZoningClassification {
  code: string
  name: string
  /** The legend swatch as #rrggbb, or null for a classification with no swatch. */
  legend_color: string | null
}

export interface Barangay {
  id: number
  name: string
  /**
   * Path to the barangay's official CPDO sheet under the web root, e.g.
   * `/zoning-maps/acacia.png`. Null where no sheet is on file — a barangay
   * without one must render as "no map", not as a broken image.
   */
  zoning_map_path: string | null
  /**
   * What that sheet DRAWS, in the legend's order. Not what any given address
   * is: the maps are rasters with no geometry, so nothing here answers "is my
   * site conforming". CPDO decides that. See the comment on MALABON_BOUNDS in
   * ApplyWizard for the same refusal about the city boundary.
   */
  zoning_classifications: ZoningClassification[]
}

export interface PsicCode {
  id: number
  code: string
  title: string
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
  barangay: Barangay
  latitude: number | null
  longitude: number | null
  /** Public verify payload surfaces city. */
  city?: string
  /**
   * BPLO form item A5. Not asked: every location this system will license is
   * inside Malabon (the map pin is bounds-checked against MALABON_BOUNDS), and
   * Malabon has exactly one postal code — 1470. A question with one possible
   * answer is not a question. The API defaults it the same way the schema
   * already defaults `city` and `province`.
   */
  postal_code?: string | null
  /** BPLO form item A6, the landline. Blank for most sole proprietors. */
  telephone?: string | null
  /** BPLO form item A9. */
  website?: string | null
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
  trade_name: string | null
  registration_type: string | null
  registration_number: string | null
  tin: string | null
  ban: string | null
  is_active: boolean
  is_rented?: boolean
  lessor_name?: string | null
  lessor_address?: string | null
  lessor_contact?: string | null
  monthly_rental?: string | null
  emergency_contact_name?: string | null
  emergency_contact_number?: string | null
  /**
   * Access status. Optional because the owner `/businesses` list resource
   * (BusinessResource) does not currently expose `status` — only `ban` and
   * `is_active`. When the backend adds it, the blacklist modal (p006) reads it.
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
  has_tax_incentives?: boolean
  address: {
    line1: string
    line2?: string
    barangay_id: number
    latitude?: number
    longitude?: number
    /** Omitted by the wizard; the API fills Malabon's 1470. See Address above. */
    postal_code?: string
    telephone?: string
    website?: string
  }
  lines: { psic_code_id: number; capitalization?: string; products_services?: string }[]
}

/* ── Applications ─────────────────────────────────────────────────────── */

export type ApplicationType = 'new' | 'renewal' | 'amendment'

export type ApplicationStatus =
  | 'draft'
  | 'submitted'
  | 'under_review'
  | 'returned'
  | 'pending_payment'
  | 'for_inspection'
  | 'approved'
  | 'issued'
  | 'rejected'
  | 'cancelled'

export interface ApplicationListItem {
  id: number
  tracking_id: string
  application_type: ApplicationType
  /** The applicant's own name for the filing; null falls back to the business name. */
  title: string | null
  status: ApplicationStatus
  status_label: string
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
  business: { id: number; name: string } | null
  /**
   * Who filed it. Null when the account has been removed — `User` soft-deletes
   * and its filings stay, the same way `business` above outlives its register
   * row — so every reader needs a fallback, not a dereference.
   */
  applicant: { id: number; name: string } | null
  submitted_at: string | null
  deadline_at: string | null
  permit_types: { code: string; name: string }[]
  created_at: string
}

export interface AppDocument {
  id: number
  document_type: { code: string; name: string }
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
  /** Collecting office: BPLO, CTO, CHO, CENRO, OBO, BFP, CMO-MARKET. */
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
  line_items: FeeLineItem[]
  total_amount: string
}

/* ── Fee profile (revenue-code inputs; draft applications only) ────────── */

export interface FeeProfileLine {
  /** Ties the line back to the Part 2 PSIC selection (draft restore). */
  psic_code_id?: number
  /** Revenue-code business category slug (e.g. retailer, carinderia). */
  category: string
  /** Preceding-calendar-year gross sales (renewals). */
  gross_sales?: number
  /** Initial capital (new businesses). */
  capitalization?: number
}

export type BusinessStructure =
  | 'sole_proprietorship'
  | 'partnership'
  | 'corporation'
  | 'cooperative'

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

export type PaymentMethod = 'gcash' | 'maya' | 'card'

export interface Payment {
  id: number
  reference_number: string
  amount: string
  method: PaymentMethod
  status: string
  paid_at: string | null
  /** Present in the owner's cross-application payment history. */
  application?: { id: number; tracking_id: string }
}

export interface Assignment {
  id: number
  status: string
  status_label: string
  remarks: string | null
  department: { code: string; name: string }
  officer: { id: number; name: string } | null
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
    status: ApplicationStatus
  }
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
  inspector: { id: number; name: string } | null
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

export interface Permit {
  id: number
  permit_number: string
  status: string
  status_label: string
  valid_from: string | null
  valid_until: string | null
  days_until_expiry: number | null
  permit_type: { code: string; name: string }
  business: { id: number; name: string }
  application: { id: number; tracking_id: string }
  verify_url: string
}

/**
 * A clearance the applicant already held and submitted a COPY of, instead of
 * asking the office to issue it (`GET /permits/held`).
 *
 * This is NOT a Permit and must never be rendered as one. Nothing in the
 * register issued it: it is a file the applicant uploaded, stored as an
 * ordinary application document carrying a `permit_type_id`
 * (App\Support\HeldPermits), and no Permit row is ever written for it —
 * WorkflowService::approveAndIssue only issues the permit types on the filing,
 * and submitting a copy is precisely the act of leaving one off.
 *
 * That is why this shape has no `permit_number`, no `valid_from` / `valid_until`
 * and no `verify_url`. The absence is the point. The City recorded no number,
 * no validity and no verification for a document it did not issue, so a screen
 * has nothing honest to print in those slots and must not invent one.
 *
 * `id` is the document id. It keys /documents/{id}/download, NOT /permits/{id}.
 */
export interface HeldClearance {
  id: number
  /** Which of the six this is a copy of. Null only if the type row is gone. */
  permit_type: { code: string; name: string } | null
  /** The applicant's own filename, as uploaded. */
  filename: string
  size_bytes: number
  /** When the applicant uploaded it — not an issue date. */
  submitted_at: string | null
  download_url: string
  /**
   * Null when the business has been removed from the register. `Business`
   * soft-deletes while its filings stay, so this is a real state and readers
   * must run it through `businessName()` rather than reading `.name`.
   */
  business: { id: number; name: string } | null
  /**
   * The filing the copy was uploaded to.
   *
   * `tracking_id` is nullable and means it: a copy can only be uploaded while
   * the filing is a draft (ClearanceService::isUnlocked), and a draft has not
   * been given a tracking ID yet. So the common case for a freshly submitted
   * copy is null, not a string.
   */
  application: { id: number; tracking_id: string | null; status: ApplicationStatus } | null
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

export interface Application extends ApplicationListItem {
  applicant: { id: number; name: string }
  /** How the business tax is settled: in full by Jan 20, or in four quarters. */
  payment_mode?: 'annual' | 'quarterly'
  /** What this filing amends; null unless `application_type` is `amendment`. */
  amendments?: AmendmentDetails | null
  /** Full resource embeds the complete business (address + lines). */
  business: Business
  documents: AppDocument[]
  fee_assessment: FeeAssessment | null
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
   * The full record knows which permit types will actually be inspected; the
   * list resource does not send the flag, hence the narrowing override.
   *
   * `requires_inspection` is the whole basis of the For Inspection step on the
   * progression rail. WorkflowService::afterReviewProgress reads the same flag
   * and, when none of them is set, jumps the last office approval straight to
   * issuance — so a rail that guessed would draw a stage half these filings
   * never enter.
   */
  permit_types: { id: number; code: string; name: string; requires_inspection: boolean }[]
  /**
   * Every recorded transition, oldest first — the same rows and the same shape
   * as `GET /applications/{id}/timeline`.
   *
   * Empty is ambiguous by design on the API side: it means either "this filing
   * has never moved" or "this endpoint did not load them". Both leave a reader
   * with nothing to draw, so neither needs its own branch here.
   */
  status_history: TimelineEntry[]
}

export interface TimelineEntry {
  from_status: ApplicationStatus | null
  to_status: ApplicationStatus
  note: string | null
  changed_by: { name: string } | null
  created_at: string
}

/* ── Notifications ────────────────────────────────────────────────────── */

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
export type LineOfBusinessRow = RankedShareRow & { industry: string; psic_code: string }

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
   * No `expiry`, and the payload still carries one — same reasoning as the three
   * meetings fields on OfficerActivity above.
   *
   * "Permits Approaching Expiry" moved to Renewal Risk Prediction and its first
   * column became four named states (see PermitLifecycle below). The dashboard
   * key stayed behind: it was once pinned by a parity check against a second
   * implementation, and now it is simply an unused key on a payload nobody has
   * got round to trimming. Leaving it off this type is what stops a screen
   * reading a panel that is no longer anywhere in the design — the compiler
   * refuses it, which is the guarantee that matters whether or not the key ever
   * goes.
   */
  top_barangays: { rows: BarangayShareRow[]; total: number; groups: number }
  top_lines_of_business: { rows: LineOfBusinessRow[]; total: number; groups: number }
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
    by_barangay: { barangay: string; businesses: number; active: number; share: number | null }[]
  }
}

/* Business Growth Analysis (spec §4; mockup 122 renames it from the
 * paper's "Business Growth Analysis" and the mockup wins on naming). */

export interface BusinessStatusRow {
  status: 'active' | 'expired' | 'inactive' | 'closed'
  label: string
  count: number
  /** Null only when the register holds no businesses at all. */
  share: number | null
}

export interface BarangayGrowthRow {
  barangay: string
  registrations: number
  prior: number
  delta: number
  /** Null when the prior period was empty: a change from zero is not a rate. */
  growth_rate: number | null
}

export interface IndustryGrowthRow {
  industry: string
  psic_code: string
  count: number
  registrations: number
  prior: number
  delta: number
  direction: 'growing' | 'declining' | 'steady'
}

/**
 * One of the three questions the Business Industry Growth Trend can answer.
 *
 * Six slots, three rankings. The register holds 135 PSIC codes and the chart's
 * palette keeps six series apart without relying on colour, so six is a ceiling
 * rather than a shortlist — which makes "which six" the whole question, and the
 * reason the reader is given the choice instead of being handed one answer.
 */
export interface IndustryLens {
  key: 'largest' | 'growing' | 'declining'
  label: string
  /**
   * Whether `min_businesses` was applied. False only for `largest`, which ranks
   * by the very count a floor would test.
   */
  floored: boolean
  /**
   * How many lines this lens COULD have drawn, before the six slots cut it.
   * Under six means fewer lines are drawn, and the screen must say so — the
   * server does not pad, because a steady line has not declined.
   */
  qualifying: number
  rows: IndustryGrowthRow[]
}

export interface IndustryLenses {
  slots: number
  /** Minimum businesses on record before a line may be ranked by change. */
  min_businesses: number
  lines_on_record: number
  /** Lines at or above `min_businesses`: the pool the change lenses rank. */
  above_floor: number
  lenses: IndustryLens[]
}

/** One point on a Kaplan-Meier curve, at one renewal cycle. */
export interface SurvivalPoint {
  cycle: number
  /** Businesses that reached this cycle. Small values mean a thin estimate. */
  at_risk: number
  lapses: number
  /** Survival through this cycle, as a percentage. */
  survival: number | null
}

export interface SurvivalCurve {
  businesses: number
  /** Total renewal cycles the group lived through: the sample behind the curve. */
  renewals_observed: number
  lapses: number
  max_cycle: number
  /**
   * Survival through `max_cycle`. Null when no business in the group has reached
   * a first renewal — a cohort too new to have survived anything has no rate, and
   * rendering 0% or 100% there would both be inventions.
   */
  survival: number | null
  points: SurvivalPoint[]
}

/**
 * Cohort survival over renewal cycles.
 *
 * A Kaplan-Meier estimate, computed server-side in
 * App\Support\BusinessGrowthAnalytics. It is descriptive, not predictive: it
 * reports what an observed
 * cohort did, and businesses still inside their current permit are censored rather
 * than counted as failures. It is not a probability that any given business will
 * renew, and `methodology` is the sentence that has to travel with it.
 */
export type CohortSurvival = SurvivalCurve & {
  methodology: string
  grace_days: number
  cohorts: (SurvivalCurve & { cohort: string })[]
}

export interface BusinessGrowthReport {
  generated_at: string
  period_months: number
  period_start: string
  period_end: string
  prior_period_start: string
  registrations: number
  registrations_prior: number
  growth_rate: number | null
  closures: number
  cohort_survival: CohortSurvival
  status_summary: BusinessStatusRow[]
  top_barangays: BarangayGrowthRow[]
  closure_trend: { month: string; closures: number }[]
  industry_growth: IndustryGrowthRow[]
  /**
   * The lens toggle's three rankings, spliced on by AnalyticsController at serve
   * time rather than computed into the dataset. See the note on that controller
   * method; the short of it is that the rankings are a presentation of
   * `industry_growth` rather than a new measurement, so they are derived where
   * the response is assembled and never stored in a snapshot.
   *
   * Optional because `industry_growth` is what the dataset actually carries, and
   * a snapshot stored before the splice existed must still draw the panel.
   * The page falls back to it, which is exactly the Largest lens.
   */
  industry_lenses?: IndustryLenses
}

/*
 * Renewal Risk.
 *
 * A weighted rule score over the register, computed in
 * App\Support\RenewalRiskScoring. Deliberately NOT a probability: there is no
 * fitted model behind it, so nothing in the UI may render `score` as a
 * percentage or call it a prediction, a likelihood, or a confidence. The revised
 * mockup labelled this column "PROB. DELAY RISK" with percentages; that wording
 * is not used. `score` is out of 100 and `drivers` says what produced it.
 */

export type RiskBand = 'high' | 'moderate' | 'low'
export type RiskAction = 'immediate_follow_up' | 'send_reminder' | 'monitor'

/** One rule's contribution to a permit's score, with its reason in plain words. */
export interface RiskDriver {
  rule: string
  label: string
  points: number
  max: number
  detail: string
}

/** The published rule book, rendered on screen so the weights cannot drift. */
export interface RiskRule {
  rule: string
  label: string
  max: number
  description: string
}

export interface RenewalRiskRow {
  permit_id: number
  permit_number: string
  business_id: number
  business: string
  barangay: string | null
  permit_type: string
  valid_until: string
  /** Negative when the permit has already lapsed. */
  days_to_expiry: number
  /** Out of 100. Not a percentage of anything. */
  score: number
  band: RiskBand
  band_label: string
  action: RiskAction
  action_label: string
  renewal_stage: string
  renewal_tracking_id: string | null
  /**
   * Scheduled expiry notices only — the nightly scan's reminders and the
   * renewal-due nudge. Officer-initiated follow-ups are deliberately not pooled
   * in; see `manual_reminders`.
   */
  reminders_sent: number
  /** Follow-ups an officer sent from this screen. At most one per day. */
  manual_reminders: number
  /** When the last one went, ISO-8601. Null when none has. */
  manual_reminder_at: string | null
  /** Only the rules that cost points, heaviest first. */
  drivers: RiskDriver[]
}

/**
 * What the server actually filtered on — its answer, not the request.
 *
 * Rendered rather than the state the selects hold, because the two can differ:
 * an unknown band is dropped server-side rather than rejected, and a screen
 * that labelled an unfiltered table with the filter it failed to apply would be
 * worse than one that had 500'd.
 */
export interface RenewalRiskFilters {
  barangay: string | null
  band: RiskBand | null
  action: RiskAction | null
  /**
   * The term the server matched on, in the casing the officer typed it. Folded
   * to lower case to compare against rows, never to echo — a box that answered
   * "Mercado" with "mercado" reads as having corrected the reader.
   */
  search: string | null
}

/**
 * The four states a watchlisted permit can be in, in reading order.
 *
 * These are NOT risk bands and the screen has to keep saying so. `RiskBand`
 * above ranks how much is wrong with a permit; this says where the permit
 * stands. A permit can be `low` risk and `near_expiry`, or `high` risk and
 * `pending_renewal` — two axes over one population.
 */
export type PermitLifecycleState = 'active' | 'near_expiry' | 'pending_renewal' | 'overdue'

export interface PermitLifecycleRow {
  state: PermitLifecycleState
  label: string
  /** Keyed by permit type code, one key per column. */
  counts: Record<string, number>
  total: number
}

/**
 * Permits Approaching Expiry, as the client asked for it: four named states in
 * the first column instead of three overlapping 30/60/90 day windows.
 *
 * The states partition the watchlist — every permit is in exactly one, and
 * `total` equals the report's `scored_permits`, which is what lets this table
 * and the risk-level cards above it be read against each other.
 *
 * Added by the server at serve time rather than stored in the snapshot: it is a
 * re-cut of permits the report already scored, so it has to answer to whatever
 * filter the request carried. See RenewalRiskAnalytics::lifecycle().
 */
export interface PermitLifecycle {
  columns: { code: string; label: string }[]
  rows: PermitLifecycleRow[]
  /** Equals `scored_permits` for the same filter. */
  total: number
  /** Days before expiry at which a permit becomes Near Expiry. */
  near_expiry_days: number
  /** How far back Overdue reaches before a permit leaves the watchlist. */
  lapsed_grace_days: number
}

export interface RenewalRiskReport {
  generated_at: string
  horizon_days: number
  lapsed_grace_days: number
  window_start: string
  window_end: string
  /**
   * Every permit scored in the window. The denominator the three band counts
   * are out of — NOT the number of rows the current filter has, which is
   * `matching`. Conflating the two is how a table footer starts lying.
   */
  scored_permits: number
  counts: Record<RiskBand, number>
  /**
   * Real sends from the expiry-notice ledger, not an estimate. Scheduled
   * notices only — see `RenewalRiskRow.reminders_sent`.
   */
  reminders_sent: number
  /** Rows the current filter has, of which `at_risk` is one page. */
  matching: number
  /** Where that page starts. */
  offset: number
  filters: RenewalRiskFilters
  /** The barangays the filter may offer: those with a permit in the window. */
  barangays: string[]
  at_risk: RenewalRiskRow[]
  /** The same permits as `scored_permits`, split four ways by state. */
  lifecycle: PermitLifecycle
  actions: { action: RiskAction; label: string; band: RiskBand; count: number }[]
  rulebook: RiskRule[]
  thresholds: { high: number; moderate: number }
  /** The honesty statement. Rendered verbatim; never paraphrased on screen. */
  methodology: string
}

/**
 * What came back from pressing Send reminder.
 *
 * `already_sent` is a success, not a failure: the officer's intent — this owner
 * should have been told — is satisfied either way, and what they need to know
 * is when it happened rather than that their press did nothing.
 */
export interface RenewalReminderResult {
  permit_id: number
  already_sent: boolean
  sent_at: string | null
  message: string
}

/* ── The fitted model that sits beside the rule score ──────────────────────── */

/**
 * One signal's fitted effect. `odds_ratio` above 1 raises the chance of a late
 * renewal, below 1 lowers it; `interpretation` is that sentence written out by
 * the engine, because the wording depends on the sign and a template here would
 * be wrong for half the rows.
 */
export interface RenewalModelCoefficient {
  term: string
  label: string
  estimate: number
  std_error: number
  z_value: number
  p_value: number
  odds_ratio: number
  significant: boolean
  interpretation: string
}

/** A signal that could not be estimated, and why. Shown, never swallowed. */
export interface RenewalModelDropped {
  term: string
  label: string
  reason: string
}

/**
 * Why a permit has no figure. Only `open` gets one — a lapsed permit's renewal
 * IS late (a fact, not an estimate) and an approved renewal has nothing left to
 * wait for.
 */
export type RenewalModelState = 'open' | 'lapsed' | 'renewed'

export interface RenewalModelEstimate {
  permit_id: number
  business: string
  permit_type: string
  barangay: string | null
  valid_until: string
  days_to_expiry: number
  renewal_stage: string
  /** Null wherever `state` is not 'open'. */
  probability: number | null
  state: RenewalModelState
  state_label: string
  /**
   * The rule score for the same permit, from the same facts at the same moment.
   * Carried on this payload rather than joined in the browser so the two numbers
   * shown side by side cannot end up describing different permits or days.
   */
  rule_score: number
  rule_band: RiskBand
  rule_band_label: string
}

export interface RenewalModelMetrics {
  /** Ordering quality on the held-out period. Pooled, so read `horizon_auc` too. */
  auc: number | null
  /** Mean squared error of the figures themselves. Lower is better. */
  brier: number | null
  /** The same, for always guessing the training period's own late rate. */
  baseline_brier: number | null
  skill_score: number | null
  calibration_intercept: number | null
  /** 1.00 is ideal. Below it the figures are spread too wide. */
  calibration_slope: number | null
  /**
   * Whether the figures can currently be read as rates. When false the screen
   * stops calling them probabilities and calls them a ranking, which is what an
   * uncalibrated score is.
   */
  calibrated: boolean
  observations: number
  unfitted_levels: number
}

/** Discrimination with the clock held still — see `horizon_auc` on the screen. */
export interface RenewalModelHorizon {
  days_to_expiry: number
  observations: number
  late: number
  late_rate: number
  /** Null where every cycle at that distance went the same way. */
  auc: number | null
}

export interface RenewalModelCalibrationBin {
  bin: number
  observations: number
  predicted: number
  observed: number
  lower: number
  upper: number
}

export interface RenewalModelPeriod {
  cycles: number
  observations: number
  late: number
  late_rate: number | null
}

export interface RenewalModelReport {
  /** False when no model could be fitted — see `unavailable_reason` for which. */
  available: boolean
  unavailable_reason: string | null
  generated_at: string
  engine: string

  label: {
    definition: string
    grace_days: number
    settle_days: number
    lead_days: number[]
  }
  split: {
    cutoff: string | null
    basis: string
    train_from: string | null
    train_to: string | null
    test_from: string | null
    test_to: string | null
    /** Always false. A random split would let the future explain the past. */
    random: boolean
  }
  training: RenewalModelPeriod
  evaluation: RenewalModelPeriod
  counts: {
    businesses: number
    cycles_found: number
    cycles_unsettled: number
    cycles_labelled: number
    late: number
    late_rate: number
    observations: number
    train_observations: number
    test_observations: number
  }

  coefficients: RenewalModelCoefficient[]
  dropped: RenewalModelDropped[]
  metrics: RenewalModelMetrics
  horizon_auc: RenewalModelHorizon[]
  calibration: RenewalModelCalibrationBin[]
  /** The calibration finding in a sentence. Rendered verbatim. */
  calibration_statement: string
  estimates: RenewalModelEstimate[]
  estimate_note: string

  /**
   * The sentence that outranks every figure on the panel. Rendered above them,
   * in plain sight, never in a tooltip.
   */
  training_data: { synthetic: boolean; notice: string }
  methodology: string
}

/*
 * No StaffingSimulationReport type. App\Support\Des is a complete discrete-event
 * simulation, but the spec puts it out of scope for the delivered flow, so it
 * has no endpoint and no screen. Nothing to type until one of those exists.
 */

/* ── Admin ────────────────────────────────────────────────────────────── */

export interface AdminUser extends User {}

export interface AdminUserPayload {
  first_name: string
  middle_name?: string
  last_name: string
  suffix?: string
  gender: 'M' | 'F'
  email: string
  mobile_number?: string
  password?: string
  role: string
  department_id?: number
}

export interface AuditLog {
  id: number
  action: string
  user: { name: string } | null
  auditable_type: string
  auditable_id: number
  changes: Record<string, unknown> | null
  created_at: string
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
  last_message_at: string | null
  can_message: boolean
}

/** One conversation row in the Messages inbox (GET /message-threads). */
export interface MessageThreadSummary {
  application_id: number
  tracking_id: string | null
  business_name: string | null
  status: string | null
  /** Whoever the reader is talking to: the applicant, or the officer/office. */
  counterparty: { name: string; subtitle: string | null; is_officer: boolean }
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

export type RequestStatus = 'pending' | 'submitted' | 'fulfilled' | 'rejected'

/** One applicant reply; a request can collect several. */
export interface OfficerRequestResponse {
  id: number
  body: string | null
  author: { name: string | null }
  document: { id: number; filename: string | null } | null
  created_at: string
}

export interface OfficerRequest {
  id: number
  request_type: RequestType
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
   * The office the applicant sees this coming FROM, as picked in the composer.
   * Distinct from `created_by.department`, which is the requester's own office:
   * the super admin belongs to none and has to choose, and an officer may raise
   * a requirement on another office's behalf.
   */
  from_office: Department | null
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
  application: { id: number; tracking_id: string; business_name: string | null } | null
  /** Latest reply, mirrored for older clients; `responses` is the full thread. */
  response_body: string | null
  responses: OfficerRequestResponse[]
  created_at: string
  responded_at: string | null
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
  last_application: { id: number; permit_type_ids: number[] } | null
  suggested_permit_type_ids: number[]
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

export interface AdminBusiness {
  id: number
  name: string
  owner: { id: number; name: string } | null
  status: BusinessStatus
  status_label: string
  created_at: string
}

/* ── Per-office application forms (UI prototype Parts 4-7, pages 040-043) ── */

/** Opaque free-form JSON keyed by permit type; stored verbatim by the API. */
export interface OfficeForm {
  permit_type_code: string
  /** Present on the full application payload (officer review). */
  permit_type_name?: string
  /** Issuing department code (BPLO, CHO, BFP, ...) on the full payload. */
  department_code?: string
  form_data: Record<string, unknown>
}

/* ── LGU Clearances (the stage that opens after the first payment) ────── */

/*
 * The six supporting clearances (docs/clearances-after-payment.md). Each is a
 * separate transaction with a separate office, a separate fee and a separate
 * outcome, which is why it has a state of its own instead of being a tick on
 * the application.
 *
 * They are decided AFTER the business permit has been submitted and paid for,
 * not before. Each one applied for is re-assessed onto a running balance, and
 * the permit is not released until that balance reaches zero — which is why
 * `ClearanceMeta` below carries money and this is not merely a list of states.
 */
export type ClearanceState = 'available' | 'applied' | 'submitted' | 'issued' | 'rejected'

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
  /** The copy the applicant already holds, when they submitted one instead. */
  held_document: {
    id: number
    name: string
    size: number
    download_url?: string
  } | null
  /**
   * The office's review of this clearance, once it has one.
   *
   * Null for the whole of the draft: assignments are raised at payment, by
   * WorkflowService::routeToDepartments, not when the card is ticked. Ticking a
   * card in a draft used to raise one, which started the office's service-time
   * clock (`assigned_at`) days before the office could have seen the filing.
   */
  assignment: { id: number; status: string | null; remarks: string | null } | null
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

export interface VerifyResult {
  permit_number: string
  status: string
  status_label: string
  valid_from: string | null
  valid_until: string | null
  permit_type: { name: string }
  business: {
    name: string
    address: { barangay: { name: string }; city: string | null }
  }
  is_valid: boolean
}
