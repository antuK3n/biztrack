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

export interface Barangay {
  id: number
  name: string
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
}

export interface BusinessLine {
  id: number
  psic_code: PsicCode
  capitalization: string | null
  /** Free text, set when the applicant picked "Other (not listed)". */
  line_of_business: string | null
  products_services: string | null
}

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
  address: {
    line1: string
    line2?: string
    barangay_id: number
    latitude?: number
    longitude?: number
  }
  lines: { psic_code_id: number; capitalization?: string }[]
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
 * over weekly review turnaround, computed server-side in App\Support\Spc — the
 * PHP port of the retired standalone R project. These shapes mirror it exactly.
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
 * R is the statistics engine and it runs as a separate program. The analytics
 * are computed in batch — `php artisan analytics:refresh` pushes register rows to
 * R and stores the result — so a screen is showing figures as of `computed_at`,
 * not as of now. That has to be on screen: a tester who files an application and
 * does not see it in the dashboard has found the designed behaviour, and the
 * timestamp is what says so.
 *
 * When R could not be reached and no stored result existed, the server computed
 * the figures with its own PHP port instead. `source` is then 'local' and
 * `notice` carries a sentence to show. Never present those as R's output: two
 * implementations of the same statistics can drift, and hiding which one ran is
 * what would make the drift invisible.
 */
export type AnalyticsSource = 'r' | 'local'

export type AnalyticsFallbackReason =
  /** R has no endpoint for this dataset yet, so refreshing would not help. */
  | 'no_r_endpoint'
  /** The refresh has not run yet, or its last run failed for this view. */
  | 'not_yet_refreshed'
  /** This window is not one of the precomputed ones (see config/analytics.php). */
  | 'window_not_precomputed'
  /** R is switched off for this environment. */
  | 'r_disabled'

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
  source: AnalyticsSource
  engine: string
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
 * `failed` can be non-zero on a 200: R may recompute some datasets and fail
 * others, leaving the screens showing a mix of fresh and stale figures. The
 * caller has to report that rather than treat any 200 as a clean refresh.
 */
export interface AnalyticsRefreshResult {
  message: string
  refreshed: number
  failed: number
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
 * Mirrors App\Support\DashboardAnalytics exactly, which is also what R's
 * POST /dashboard returns. Three conventions run through the whole shape:
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

export interface ExpiryColumn {
  code: string
  label: string
}

/** Windows are cumulative: 30d ⊂ 60d ⊂ 90d. `expired` is disjoint from all three. */
export interface ExpiryRow {
  window: string
  label: string
  days: number | null
  expired: boolean
  counts: Record<string, number>
  total: number
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

export interface OfficerActivity {
  responses: number
  mean_response_hours: number | null
  median_response_hours: number | null
  threads_awaiting_reply: number
  requests_total: number
  requests_fulfilled: number
  requests_fulfilled_rate: number | null
  meetings_scheduled: number
  meetings_attended: number
  meetings_attended_rate: number | null
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
  expiry: { columns: ExpiryColumn[]; rows: ExpiryRow[] }
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

/* Business Lifecycle Monitoring (spec §4; mockup 122 renames it from the
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
 * A Kaplan-Meier estimate, computed in R by `survival::survfit` and mirrored by
 * the PHP fallback. It is descriptive, not predictive: it reports what an observed
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
  reminders_sent: number
  /** Only the rules that cost points, heaviest first. */
  drivers: RiskDriver[]
}

export interface RenewalRiskReport {
  generated_at: string
  horizon_days: number
  lapsed_grace_days: number
  window_start: string
  window_end: string
  scored_permits: number
  counts: Record<RiskBand, number>
  /** Real sends from the expiry-notice ledger, not an estimate. */
  reminders_sent: number
  at_risk: RenewalRiskRow[]
  actions: { action: RiskAction; label: string; band: RiskBand; count: number }[]
  rulebook: RiskRule[]
  thresholds: { high: number; moderate: number }
  /** The honesty statement. Rendered verbatim; never paraphrased on screen. */
  methodology: string
}

/*
 * No StaffingSimulationReport type. App\Support\Des is a complete port of
 * r/R/des.R, but docs/r-integration-spec.md puts the discrete-event simulation
 * out of scope for the delivered flow, so it has no endpoint and no screen.
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
  attachments: MessageAttachment[]
  created_at: string
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
  created_by: { name: string; department: string | null }
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
  application: { id: number; tracking_id: string; business_name: string }
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
