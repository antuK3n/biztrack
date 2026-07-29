import { api } from './api'
import type {
  AdminBusiness,
  AdminUser,
  AdminUserPayload,
  AnalyticsSummary,
  Application,
  ApplicationListItem,
  ApplicationType,
  Assignment,
  AuditLog,
  Barangay,
  Business,
  BusinessGrowthReport,
  BusinessStatus,
  BusinessPayload,
  Department,
  DocumentType,
  FeeAssessment,
  FeeLineItem,
  FeeProfile,
  Inspection,
  InspectionResult,
  Message,
  MessageThreadSummary,
  Notification,
  OfficeForm,
  OfficerRequest,
  Payment,
  PaymentMethod,
  Permit,
  PermitType,
  PrefillResult,
  ProcessingTimeReport,
  PsicCode,
  RenewalRiskReport,
  RequestType,
  TimelineEntry,
} from './types'

/*
 * Typed wrappers over the BizTrack REST surface (docs/api-contract.md).
 * Every list endpoint returns { data }; a few add { meta }. We unwrap `data`
 * here so callers work with plain resources. Errors bubble as axios errors —
 * pages normalize them through toApiError.
 */

async function unwrap<T>(promise: Promise<{ data: { data: T } }>): Promise<T> {
  const res = await promise
  return res.data.data
}

/**
 * Authenticated file download: fetch the endpoint as a blob (Bearer header is
 * injected by the api interceptor), then trigger a browser save as `filename`.
 * Used for permit PDFs, receipts, and the analytics CSV export.
 */
async function downloadBlob(url: string, filename: string): Promise<void> {
  const res = await api.get(url, { responseType: 'blob' })
  const objectUrl = URL.createObjectURL(res.data as Blob)
  const anchor = document.createElement('a')
  anchor.href = objectUrl
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(objectUrl)
}

/**
 * Authenticated inline view: fetch the endpoint as a blob, then point a tab at
 * the object URL so PDFs and images render instead of downloading. Pass a tab
 * opened synchronously from the click handler so popup blockers stay quiet.
 */
async function viewBlob(url: string, target?: Window | null): Promise<void> {
  const res = await api.get(url, { responseType: 'blob' })
  const objectUrl = URL.createObjectURL(res.data as Blob)
  if (target && !target.closed) target.location.replace(objectUrl)
  else window.open(objectUrl, '_blank', 'noopener')
  // The tab needs the URL to survive the handoff; reclaim it a minute later.
  window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60_000)
}

/* ── Reference ────────────────────────────────────────────────────────── */

export const reference = {
  barangays: () => unwrap<Barangay[]>(api.get('/reference/barangays')),
  psicCodes: () => unwrap<PsicCode[]>(api.get('/reference/psic-codes')),
  departments: () => unwrap<Department[]>(api.get('/reference/departments')),
  documentTypes: () => unwrap<DocumentType[]>(api.get('/reference/document-types')),
  permitTypes: () => unwrap<PermitType[]>(api.get('/reference/permit-types')),
}

/* ── Businesses ───────────────────────────────────────────────────────── */

export const businesses = {
  list: () => unwrap<Business[]>(api.get('/businesses')),
  get: (id: number) => unwrap<Business>(api.get(`/businesses/${id}`)),
  create: (body: BusinessPayload) => unwrap<Business>(api.post('/businesses', body)),
  update: (id: number, body: BusinessPayload) => unwrap<Business>(api.put(`/businesses/${id}`, body)),
  /** Renewal/amendment prefill: prior permit + suggested permit types (v2). */
  prefill: (id: number, type: 'renewal' | 'amendment') =>
    unwrap<PrefillResult>(api.get(`/businesses/${id}/prefill`, { params: { type } })),
}

/* ── Applications ─────────────────────────────────────────────────────── */

export interface ApplicationFilters {
  status?: string
  type?: string
  q?: string
}

export const applications = {
  list: (filters: ApplicationFilters = {}) =>
    unwrap<ApplicationListItem[]>(api.get('/applications', { params: filters })),
  get: (id: number) => unwrap<Application>(api.get(`/applications/${id}`)),
  create: (body: {
    business_id: number
    application_type: ApplicationType
    /** Applicant's own name for the filing (blank keeps the business name). */
    title?: string
    permit_type_ids: number[]
    /** Set on renewal/amendment to link the prior permit (v2). */
    prior_permit_id?: number
    /** Revenue-code fee inputs (drives the itemized Tax Order of Payment). */
    fee_profile?: FeeProfile
    /** Business tax in full by Jan 20, or in four quarters (Ord. Sec. 2N). */
    payment_mode?: 'annual' | 'quarterly'
  }) => unwrap<Application>(api.post('/applications', body)),
  update: (
    id: number,
    body: {
      business_id?: number
      title?: string
      permit_type_ids?: number[]
      fee_profile?: FeeProfile | null
      payment_mode?: 'annual' | 'quarterly'
    },
  ) => unwrap<Application>(api.put(`/applications/${id}`, body)),
  submit: (id: number) => unwrap<Application>(api.post(`/applications/${id}/submit`)),
  resubmit: (id: number) => unwrap<Application>(api.post(`/applications/${id}/resubmit`)),
  cancel: (id: number) => unwrap<Application>(api.post(`/applications/${id}/cancel`)),
  timeline: (id: number) => unwrap<TimelineEntry[]>(api.get(`/applications/${id}/timeline`)),
  /**
   * Which permit a renewal/amendment is for. Separate from update() because a
   * business holds several permits with different expiry dates, so the choice
   * outlives the moment the draft was created and has to be re-readable.
   */
  priorPermit: (id: number) =>
    unwrap<{ prior_permit_id: number | null; prior_permit: Permit | null }>(
      api.get(`/applications/${id}/prior-permit`),
    ),
  setPriorPermit: (id: number, priorPermitId: number | null) =>
    unwrap<{ prior_permit_id: number | null; prior_permit: Permit | null }>(
      api.put(`/applications/${id}/prior-permit`, { prior_permit_id: priorPermitId }),
    ),
  reject: (id: number, reason: string) =>
    unwrap<Application>(api.post(`/applications/${id}/reject`, { reason })),
  /** Adjust the fee assessment (permission fee.adjust; v2). */
  feeAdjust: (id: number, line_items: FeeLineItem[], total_amount: string) =>
    unwrap<FeeAssessment>(api.post(`/applications/${id}/fee/adjust`, { line_items, total_amount })),
}

/* ── Per-office application forms (UI prototype Parts 4-7) ─────────────── */

export const officeForms = {
  /** All saved per-office form payloads for an application. */
  list: (applicationId: number) =>
    unwrap<OfficeForm[]>(api.get(`/applications/${applicationId}/office-forms`)),
  /** Upsert one office form's opaque JSON (draft/returned only, owner). */
  save: (applicationId: number, permitTypeCode: string, formData: Record<string, unknown>) =>
    unwrap<OfficeForm>(
      api.put(`/applications/${applicationId}/office-forms/${permitTypeCode}`, {
        form_data: formData,
      }),
    ),
}

/* ── Documents ────────────────────────────────────────────────────────── */

export const documents = {
  /**
   * Attach a file to a draft. Pass `permitTypeId` instead of a document type
   * for a clearance the applicant already holds and is submitting rather than
   * applying for (checklist item 59); the API names the attachment after that
   * permit so the reviewing office can read the list without decoding it.
   */
  upload: (
    applicationId: number,
    documentTypeId: number | null,
    file: File,
    permitTypeId?: number,
  ) => {
    const form = new FormData()
    if (documentTypeId !== null) form.append('document_type_id', String(documentTypeId))
    if (permitTypeId !== undefined) form.append('permit_type_id', String(permitTypeId))
    form.append('file', file)
    // Response may carry ocr_suggestions for PDFs (v2 OCR-lite).
    return unwrap<import('./types').UploadedDocument>(
      api.post(`/applications/${applicationId}/documents`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      }),
    )
  },
  /** Take an attachment back off a draft; deletes the stored file too. */
  remove: (applicationId: number, documentId: number) =>
    unwrap<{ id: number }>(api.delete(`/applications/${applicationId}/documents/${documentId}`)),
  downloadUrl: (id: number) => `${api.defaults.baseURL}/documents/${id}/download`,
  /** Authenticated save-to-disk (the plain downloadUrl has no bearer header). */
  download: (id: number, filename: string) =>
    downloadBlob(`/documents/${id}/download`, filename),
  /** Authenticated open-in-a-tab: PDFs and images render, nothing is saved. */
  view: (id: number, target?: Window | null) => viewBlob(`/documents/${id}/download`, target),
}

/* ── Messaging (per-application thread; v2) ───────────────────────────── */

export const messages = {
  /** Inbox for the Messages page: one row per conversation, newest first. */
  threads: () => unwrap<MessageThreadSummary[]>(api.get('/message-threads')),
  list: (applicationId: number) =>
    unwrap<Message[]>(api.get(`/applications/${applicationId}/messages`)),
  /** Send a message; optional attachment is posted as multipart. */
  send: (applicationId: number, body: string, attachment?: File | null) => {
    if (attachment) {
      const form = new FormData()
      form.append('body', body)
      form.append('attachment', attachment)
      return unwrap<Message>(
        api.post(`/applications/${applicationId}/messages`, form, {
          headers: { 'Content-Type': 'multipart/form-data' },
        }),
      )
    }
    return unwrap<Message>(api.post(`/applications/${applicationId}/messages`, { body }))
  },
  /** Attachment save-to-disk (the resource's download_url carries no bearer). */
  attachmentDownload: (id: number, filename: string) =>
    downloadBlob(`/message-attachments/${id}/download`, filename),
  /** Attachment open-in-a-tab, same bearer-fetch trick as documents.view. */
  attachmentView: (id: number, target?: Window | null) =>
    viewBlob(`/message-attachments/${id}/download`, target),
}

/* ── Officer requests / Other Requirements (v2) ───────────────────────── */

export const requests = {
  list: () => unwrap<OfficerRequest[]>(api.get('/requests')),
  /** Officer creates a request against an application. */
  create: (
    applicationId: number,
    body: {
      request_type: RequestType
      subject: string
      body: string
      /** Office the applicant sees this from; defaults to the requester's own. */
      department_id?: number
    },
  ) => unwrap<OfficerRequest>(api.post(`/applications/${applicationId}/requests`, body)),
  /** Owner responds; optional document is posted as multipart. */
  respond: (id: number, body: string, document?: File | null, documentTypeId?: number) => {
    if (document) {
      const form = new FormData()
      form.append('body', body)
      form.append('document', document)
      if (documentTypeId != null) form.append('document_type_id', String(documentTypeId))
      return unwrap<OfficerRequest>(
        api.post(`/requests/${id}/respond`, form, {
          headers: { 'Content-Type': 'multipart/form-data' },
        }),
      )
    }
    return unwrap<OfficerRequest>(api.post(`/requests/${id}/respond`, { body }))
  },
  /** Officer closes a submitted request. */
  close: (id: number, outcome: 'fulfilled' | 'rejected') =>
    unwrap<OfficerRequest>(api.post(`/requests/${id}/close`, { outcome })),
}

/* ── Payments ─────────────────────────────────────────────────────────── */

export const payments = {
  fee: (applicationId: number) => unwrap<FeeAssessment>(api.get(`/applications/${applicationId}/fee`)),
  pay: (applicationId: number, method: PaymentMethod) =>
    unwrap<Payment>(api.post(`/applications/${applicationId}/pay`, { method })),
  history: () => unwrap<Payment[]>(api.get('/payments')),
  /** Download the simulated payment receipt PDF (Bearer blob; v2). */
  receipt: (id: number, filename: string) => downloadBlob(`/payments/${id}/receipt`, filename),
}

/* ── Officer queues + review ──────────────────────────────────────────── */

export interface AssignmentWithApplication extends Assignment {
  application: Assignment['application'] & { documents?: Application['documents'] }
}

export const assignments = {
  list: (filters: { status?: string } = {}) =>
    unwrap<Assignment[]>(api.get('/assignments', { params: filters })),
  get: (id: number) =>
    unwrap<Assignment & { application: Application }>(api.get(`/assignments/${id}`)),
  approve: (id: number, remarks?: string) =>
    unwrap<Assignment>(api.post(`/assignments/${id}/approve`, { remarks })),
  return: (id: number, remarks: string) =>
    unwrap<Assignment>(api.post(`/assignments/${id}/return`, { remarks })),
  /** Assign a specific officer to this assignment (permission oic.assign; v2). */
  assign: (id: number, officer_user_id: number, reason?: string) =>
    unwrap<Assignment>(api.post(`/assignments/${id}/assign`, { officer_user_id, reason })),
}

/* ── Inspections ──────────────────────────────────────────────────────── */

export const inspections = {
  list: (filters: { status?: string } = {}) =>
    unwrap<Inspection[]>(api.get('/inspections', { params: filters })),
  get: (id: number) => unwrap<Inspection>(api.get(`/inspections/${id}`)),
  conduct: (id: number, body: { result: InspectionResult; findings?: string }) =>
    unwrap<Inspection>(api.post(`/inspections/${id}/conduct`, body)),
  reschedule: (id: number, scheduled_at: string) =>
    unwrap<Inspection>(api.post(`/inspections/${id}/reschedule`, { scheduled_at })),
}

/* ── Permits ──────────────────────────────────────────────────────────── */

export const permits = {
  list: () => unwrap<Permit[]>(api.get('/permits')),
  get: (id: number) => unwrap<Permit>(api.get(`/permits/${id}`)),
  verify: (permitNumber: string) =>
    unwrap<import('./types').VerifyResult>(api.get(`/verify/${permitNumber}`)),
  /** Download the rendered permit certificate PDF (Bearer blob; v2). */
  pdf: (id: number, filename: string) => downloadBlob(`/permits/${id}/pdf`, filename),
}

/* ── Notifications ────────────────────────────────────────────────────── */

export const notifications = {
  list: async (): Promise<{ data: Notification[]; unread: number }> => {
    const res = await api.get<{ data: Notification[]; meta: { unread: number } }>('/notifications')
    return { data: res.data.data, unread: res.data.meta?.unread ?? 0 }
  },
  read: (id: number) => api.post(`/notifications/${id}/read`),
  readAll: () => api.post('/notifications/read-all'),
}

/* ── Analytics ────────────────────────────────────────────────────────── */

export const analytics = {
  summary: () => unwrap<AnalyticsSummary>(api.get('/analytics/summary')),
  /** Download the summary as a CSV report (Bearer blob; v2). */
  export: (filename = 'biztrack-analytics.csv') => downloadBlob('/analytics/export', filename),

  /**
   * Feature 7: per-office control charts over weekly review turnaround. The
   * statistics run in PHP (App\Support\Spc); nothing calls the old R project.
   */
  processingTime: (weeks: number) =>
    unwrap<ProcessingTimeReport>(api.get('/analytics/processing-time', { params: { weeks } })),
  processingTimeReport: (weeks: number) =>
    downloadBlob(`/analytics/processing-time/report?weeks=${weeks}`, 'processing-time-monitoring.pdf'),

  businessGrowth: (months: number) =>
    unwrap<BusinessGrowthReport>(api.get('/analytics/business-growth', { params: { months } })),
  businessGrowthReport: (months: number) =>
    downloadBlob(`/analytics/business-growth/report?months=${months}`, 'business-growth-analysis.pdf'),

  /**
   * Renewal Risk: permits near expiry ranked by a weighted rule score.
   * `score` is out of 100 and is not a probability — see RenewalRiskReport.
   */
  renewalRisk: (days: number, limit?: number) =>
    unwrap<RenewalRiskReport>(api.get('/analytics/renewal-risk', { params: { days, limit } })),
  renewalRiskReport: (days: number) =>
    downloadBlob(`/analytics/renewal-risk/report?days=${days}`, 'renewal-risk.pdf'),
}

/* ── Admin ────────────────────────────────────────────────────────────── */

export const admin = {
  users: () => unwrap<AdminUser[]>(api.get('/admin/users')),
  /** Real business roster for the Owner Status table (v2). */
  businesses: () => unwrap<AdminBusiness[]>(api.get('/admin/businesses')),
  /** Change a business's status with a reason (permission owner.manage_status; v2). */
  setBusinessStatus: (id: number, status: BusinessStatus, reason: string) =>
    unwrap<AdminBusiness>(api.post(`/admin/businesses/${id}/status`, { status, reason })),
  createUser: (body: AdminUserPayload) => unwrap<AdminUser>(api.post('/admin/users', body)),
  updateUser: (id: number, body: Partial<AdminUserPayload>) =>
    unwrap<AdminUser>(api.put(`/admin/users/${id}`, body)),
  toggleActive: (id: number) => unwrap<AdminUser>(api.post(`/admin/users/${id}/toggle-active`)),
  auditLogs: async (page = 1): Promise<{ data: AuditLog[]; lastPage: number }> => {
    const res = await api.get<{ data: AuditLog[]; meta?: { last_page?: number } }>('/admin/audit-logs', {
      params: { page },
    })
    return { data: res.data.data, lastPage: res.data.meta?.last_page ?? 1 }
  },
}
