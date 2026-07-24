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
  status: ApplicationStatus
  status_label: string
  business: { id: number; name: string }
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

export interface FeeLineItem {
  label: string
  amount: string
}

export interface FeeAssessment {
  line_items: FeeLineItem[]
  total_amount: string
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
    business: { name: string }
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
  department: { code: string; name: string }
  inspector: { id: number; name: string } | null
  application: {
    id: number
    tracking_id: string
    business: { name: string }
    address: {
      line1: string
      barangay: { name: string }
      latitude: number | null
      longitude: number | null
    }
  }
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
  documents: AppDocument[]
  fee_assessment: FeeAssessment | null
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

/* ── Officer requests (Other Requirements; v2 CONTRACT) ───────────────── */

export type RequestType = 'document' | 'message'

export type RequestStatus = 'pending' | 'submitted' | 'fulfilled' | 'rejected'

export interface OfficerRequest {
  id: number
  request_type: RequestType
  subject: string
  body: string
  status: RequestStatus
  status_label: string
  created_by: { name: string; department: string | null }
  application: { id: number; tracking_id: string; business_name: string }
  response_body: string | null
  created_at: string
  responded_at: string | null
}

/* ── Renewal/amendment prefill (v2 CONTRACT) ──────────────────────────── */

export interface PrefillResult {
  business: Business
  last_permit: {
    id: number
    permit_number: string
    permit_type: string
    valid_until: string | null
  } | null
  last_application: { id: number; permit_type_ids: number[] } | null
  suggested_permit_type_ids: number[]
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
