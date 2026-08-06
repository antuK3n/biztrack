import axios from 'axios'
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
  AssignmentPageMeta,
  AuditLog,
  Barangay,
  Business,
  AnalyticsProvenance,
  AnalyticsRefreshResult,
  BusinessGrowthReport,
  Computed,
  BusinessStatus,
  BusinessPayload,
  Clearance,
  ClearanceMeta,
  DashboardReport,
  Department,
  DocumentType,
  FeeAssessment,
  FeeLineItem,
  FeeProfile,
  HeldClearance,
  Inspection,
  InspectionResult,
  Message,
  MessageThreadSummary,
  Notification,
  OfficeForm,
  OfficerRequest,
  PageMeta,
  PageParams,
  Payment,
  PaymentMethod,
  Permit,
  PermitType,
  PrefillResult,
  ProcessingTimeReport,
  PsicCode,
  RenewalReminderResult,
  RenewalModelReport,
  RenewalRiskReport,
  RequestType,
  RiskAction,
  RiskBand,
  TimelineEntry,
  TranscriptMeta,
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
 * Like unwrap, but keeps the provenance meta.
 *
 * The analytics endpoints are the one place `meta` is not optional detail: it
 * says when the figures were computed and whether R or the PHP fallback computed
 * them, and every analytics screen is required to show that. Keeping it in the
 * return type is what stops a caller quietly dropping it — see AnalyticsProvenance.
 */
async function unwrapComputed<T>(
  promise: Promise<{ data: { data: T; meta: AnalyticsProvenance } }>,
): Promise<Computed<T>> {
  const res = await promise
  return { data: res.data.data, meta: res.data.meta }
}

/**
 * Like unwrap, but keeps a `meta` that is part of the answer rather than paging.
 *
 * The clearances endpoint is the case this exists for: its `meta` carries the
 * lock, the reason for it, and the running balance. None of that is decoration
 * around a list — it is half of what the screen is for — so dropping it the way
 * `unwrap` does would leave the caller to invent a lock rule of its own.
 */
async function unwrapMeta<T, M>(
  promise: Promise<{ data: { data: T; meta: M } }>,
): Promise<{ data: T; meta: M }> {
  const res = await promise
  return { data: res.data.data, meta: res.data.meta }
}

/**
 * Like unwrap, but keeps the page meta a paginated list carries.
 *
 * The meta is not optional detail on a list that can run to thousands of rows:
 * without it a screen shows the first page and silently implies it is the whole
 * set. Callers are expected to render the total.
 */
async function unwrapPaged<T, M extends PageMeta = PageMeta>(
  promise: Promise<{ data: { data: T[]; meta: M } }>,
): Promise<{ data: T[]; meta: M }> {
  const res = await promise
  return { data: res.data.data, meta: res.data.meta }
}

/**
 * The page size a list wrapper asks for when its caller is a picker, not a page.
 *
 * Every list endpoint is now bounded server-side, default 50 and hard-capped at
 * 200. That is right for a screen with paging controls and wrong for the two
 * places a list is loaded to be *chosen from* — the officer picker on the review
 * screen and the application select on the compose form both filter the whole
 * list in the browser, so a 50-row page would silently drop the officer or the
 * filing you were looking for. Those wrappers ask for the ceiling.
 *
 * This is a stopgap, not the answer: both controls should move to a server-side
 * search. It is here so that bounding the endpoints does not break them in the
 * meantime.
 */
const PICKER_PAGE_SIZE = 200

/**
 * Turn a failed blob request back into the JSON error envelope (item 111).
 *
 * `responseType: 'blob'` applies to the ERROR body too, so when the API refuses
 * a download the 403 envelope arrives as a Blob rather than an object. Every
 * reader of that failure — toApiError() above all — looks for `data.message`,
 * finds undefined on a Blob, and falls back to "Something went wrong on our end.
 * Please try again."
 *
 * That is why "view/download file uploaded not working" was reported as its own
 * defect. It is largely the office-scoping refusal wearing a mask: the server
 * was saying "this application belongs to another office", the browser had that
 * sentence in hand, and threw it away to show a shrug that reads like a crash.
 * A tester cannot tell a permission boundary from a broken button, so it was
 * filed as the latter.
 *
 * Reading the blob costs one await on the failure path only. The response is
 * rebuilt in place so the interceptors and toApiError() see exactly the shape
 * they would have seen from an ordinary JSON request.
 */
async function rethrowBlobError(error: unknown): Promise<never> {
  const res = axios.isAxiosError(error) ? error.response : undefined
  if (res?.data instanceof Blob) {
    try {
      const text = await res.data.text()
      // A non-JSON body (an HTML error page, a truncated stream) is not worth
      // guessing at: leave the blob alone and let the generic message stand.
      res.data = JSON.parse(text)
    } catch {
      /* keep the original response */
    }
  }
  throw error
}

/**
 * Authenticated file download: fetch the endpoint as a blob (Bearer header is
 * injected by the api interceptor), then trigger a browser save as `filename`.
 * Used for permit PDFs, receipts, and the analytics CSV export.
 */
async function downloadBlob(url: string, filename: string): Promise<void> {
  const res = await api.get(url, { responseType: 'blob' }).catch(rethrowBlobError)
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
  const res = await api.get(url, { responseType: 'blob' }).catch(rethrowBlobError)
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
  /**
   * The caller's own businesses, newest first.
   *
   * Asks for the picker ceiling, not the default page. Item 110 made this list
   * the ONLY way to say which business a renewal is against — the dialog owns
   * that decision now, and there is no second control further down the wizard
   * to fall back on. A 50-row page would mean an owner with fifty-one
   * businesses simply could not renew the fifty-first: it would not be missing
   * from a list they could scroll, it would not be offered at all.
   *
   * Callers that want the page meta still have `page()` below.
   */
  list: (params: PageParams = {}) =>
    unwrap<Business[]>(api.get('/businesses', { params: { per_page: PICKER_PAGE_SIZE, ...params } })),
  /** Same list, keeping the page meta. */
  page: (params: PageParams = {}) => unwrapPaged<Business>(api.get('/businesses', { params })),
  get: (id: number) => unwrap<Business>(api.get(`/businesses/${id}`)),
  create: (body: BusinessPayload) => unwrap<Business>(api.post('/businesses', body)),
  update: (id: number, body: BusinessPayload) => unwrap<Business>(api.put(`/businesses/${id}`, body)),
  /** Renewal/amendment prefill: prior permit + suggested permit types (v2). */
  prefill: (id: number, type: 'renewal' | 'amendment') =>
    unwrap<PrefillResult>(api.get(`/businesses/${id}/prefill`, { params: { type } })),
}

/* ── Applications ─────────────────────────────────────────────────────── */

export interface ApplicationFilters extends PageParams {
  status?: string
  type?: string
  q?: string
}

/**
 * The paper form's "Amendment from:" checkboxes, on the wire (items 82/84).
 *
 * All optional: drafts autosave half-answered, and the server derives
 * `has_amendments` from these rather than trusting a client to send it.
 * "Others (specify)" has no boolean of its own — the text is the tick, exactly
 * as on the paper, where you cannot tick Others without naming the other.
 */
export interface AmendmentAnswers {
  amendment_ownership?: boolean
  amendment_location?: boolean
  amendment_nature?: boolean
  amendment_other?: string | null
}

export const applications = {
  /**
   * Filings visible to the caller, newest first.
   *
   * Paged server-side — this returned 1,668 rows and 832 KB to the super admin.
   * `list()` still resolves to a plain array so existing callers keep working;
   * it asks for the 200-row ceiling because its remaining callers filter in the
   * browser. Screens that show a list should use `page()` and render the total.
   */
  list: (filters: ApplicationFilters = {}) =>
    unwrap<ApplicationListItem[]>(
      api.get('/applications', { params: { per_page: PICKER_PAGE_SIZE, ...filters } }),
    ),
  /** Same list, keeping the page meta. Prefer this on any screen with a list. */
  page: (filters: ApplicationFilters = {}) =>
    unwrapPaged<ApplicationListItem>(api.get('/applications', { params: filters })),
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
  } & AmendmentAnswers) => unwrap<Application>(api.post('/applications', body)),
  update: (
    id: number,
    body: {
      business_id?: number
      title?: string
      permit_type_ids?: number[]
      fee_profile?: FeeProfile | null
      payment_mode?: 'annual' | 'quarterly'
    } & Partial<AmendmentAnswers>,
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

/* ── LGU Clearances (the last wizard step before Review & Submit) ─────── */

/**
 * The six supporting clearances for one application.
 *
 * Contract: docs/clearances-before-payment.md. Every mutation here resolves to
 * the WHOLE list rather than the row it touched, even though the API also
 * returns the row on its own. That is deliberate and it is about money: each
 * card quotes what applying will add to the Tax Order of Payment, computed
 * through `FeeCalculator::assess`, and the Fire Code fee is 10% of the mayor's
 * permit plus regulatory fees (RA 9514) — so applying for one clearance can
 * move ANOTHER clearance's `fee_preview`. Patching the single returned row
 * into local state would leave the other five quoting prices the assessment no
 * longer agrees with, and a wrong price on a button that spends the
 * applicant's money is the worst kind of stale.
 */
export const clearances = {
  /** The six rows plus `meta` (whether the stage is still open to change). */
  list: (applicationId: number) =>
    unwrapMeta<Clearance[], ClearanceMeta>(api.get(`/applications/${applicationId}/clearances`)),
  /** Ask this office to issue the clearance. Its fee joins the assessment. */
  apply: async (applicationId: number, code: string) => {
    await api.post(`/applications/${applicationId}/clearances/${code}/apply`)
    return clearances.list(applicationId)
  },
  /** Withdraw the request. Its own labelled control, never a second Apply. */
  unapply: async (applicationId: number, code: string) => {
    await api.delete(`/applications/${applicationId}/clearances/${code}/apply`)
    return clearances.list(applicationId)
  },
  /** Submit the copy already held. Adds no fee: nothing is being issued. */
  submitHeld: async (applicationId: number, code: string, file: File) => {
    const form = new FormData()
    form.append('file', file)
    await api.post(`/applications/${applicationId}/clearances/${code}/held`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return clearances.list(applicationId)
  },
  /** Take the uploaded copy back off. Its own labelled control, never Submit. */
  removeHeld: async (applicationId: number, code: string) => {
    await api.delete(`/applications/${applicationId}/clearances/${code}/held`)
    return clearances.list(applicationId)
  },
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
  /** Inbox for the Messages page: one row per conversation, newest first. Paged. */
  threads: (params: PageParams = {}) =>
    unwrap<MessageThreadSummary[]>(api.get('/message-threads', { params })),
  /** Same inbox, keeping the page meta. */
  threadsPage: (params: PageParams = {}) =>
    unwrapPaged<MessageThreadSummary>(api.get('/message-threads', { params })),
  /**
   * One conversation, oldest message first.
   *
   * Bounded to the most recent `meta.window` turns rather than page one of an
   * ascending list, so the transcript always ends on the latest message.
   */
  list: (applicationId: number) =>
    unwrap<Message[]>(api.get(`/applications/${applicationId}/messages`)),
  /** Same conversation, keeping the transcript meta (total vs returned). */
  listWithMeta: async (applicationId: number): Promise<{ data: Message[]; meta: TranscriptMeta }> => {
    const res = await api.get<{ data: Message[]; meta: TranscriptMeta }>(
      `/applications/${applicationId}/messages`,
    )
    return { data: res.data.data, meta: res.data.meta }
  },
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

export interface RequestFilters extends PageParams {
  status?: string
}

export const requests = {
  /** Requests visible to the caller, newest first. Paged (default 50). */
  list: (filters: RequestFilters = {}) =>
    unwrap<OfficerRequest[]>(api.get('/requests', { params: filters })),
  /** Same list, keeping the page meta. */
  page: (filters: RequestFilters = {}) =>
    unwrapPaged<OfficerRequest>(api.get('/requests', { params: filters })),
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
  history: (params: PageParams = {}) => unwrap<Payment[]>(api.get('/payments', { params })),
  /** Same history, keeping the page meta. */
  historyPage: (params: PageParams = {}) => unwrapPaged<Payment>(api.get('/payments', { params })),
  /** Download the simulated payment receipt PDF (Bearer blob; v2). */
  receipt: (id: number, filename: string) => downloadBlob(`/payments/${id}/receipt`, filename),
}

/* ── Officer queues + review ──────────────────────────────────────────── */

export interface AssignmentWithApplication extends Assignment {
  application: Assignment['application'] & { documents?: Application['documents'] }
}

export interface AssignmentFilters extends PageParams {
  /**
   * The assignment's own state: pending | in_progress | completed | returned.
   * Comma-separated, like `application_status` below.
   *
   * This is THIS OFFICE'S state, not the filing's, and the two are routinely
   * different: a filing stays `under_review` until every office has signed off,
   * so an office that has already approved is still looking at an application
   * in review. The For Approval tab passes the not-yet-done states here so it
   * stops asking an office to approve what it has already approved.
   */
  status?: string
  /**
   * The *application's* status, which is what the queue tabs split on.
   * Comma-separated, e.g. 'submitted,pending_payment,under_review,returned'.
   * Filter here rather than in the browser — see AssignmentPageMeta.
   */
  application_status?: string
}

export const assignments = {
  /**
   * The officer queue, newest assignment first.
   *
   * Paged server-side — this returned 4,620 rows and 2.2 MB to the super admin
   * and 1,293 to a single office. `list()` still resolves to a plain array so
   * existing callers keep working, but a screen that splits this list by
   * application status MUST pass `application_status` and read the tab totals
   * from `page().meta.application_status_counts`; filtering a page in the
   * browser silently shows one page's worth and calls it the queue.
   */
  list: (filters: AssignmentFilters = {}) =>
    unwrap<Assignment[]>(api.get('/assignments', { params: filters })),
  /** Same queue, keeping the page meta and the whole-set status counts. */
  page: (filters: AssignmentFilters = {}) =>
    unwrapPaged<Assignment, AssignmentPageMeta>(api.get('/assignments', { params: filters })),
  get: (id: number) =>
    unwrap<Assignment & { application: Application }>(api.get(`/assignments/${id}`)),
  approve: (id: number, remarks?: string) =>
    unwrap<Assignment>(api.post(`/assignments/${id}/approve`, { remarks })),
  return: (id: number, remarks: string) =>
    unwrap<Assignment>(api.post(`/assignments/${id}/return`, { remarks })),
  /** Assign a specific officer to this assignment (permission oic.assign; v2). */
  assign: (id: number, officer_user_id: number, reason?: string) =>
    unwrap<Assignment>(api.post(`/assignments/${id}/assign`, { officer_user_id, reason })),
  /**
   * Set which RA 11032 tier this filing belongs to (simple / complex /
   * highly_technical). Answers with the whole APPLICATION, not the assignment.
   *
   * That return type is the interesting part and it is deliberate: changing the
   * tier changes `deadline_at`, recomputed from the original submission date
   * because the statute's clock runs from filing rather than from
   * reclassification. The caller therefore needs the filing back, not this
   * office's row on it, or the screen would show a new category over the old
   * deadline. `tier` must be one of the values the payload offered in
   * `application.ra11032.tiers` — the API refuses anything else, because the
   * three tiers and their day counts are statute.
   */
  classify: (id: number, tier: string) =>
    unwrap<Application>(api.post(`/assignments/${id}/classification`, { tier })),
}

/* ── Inspections ──────────────────────────────────────────────────────── */

export const inspections = {
  /**
   * Paged. The unpaged version returned every inspection on record — 2,850 rows
   * and 1.8 MB once the register held three years of history — and the page took
   * the browser down trying to render them. Newest first.
   */
  list: (filters: { status?: string; page?: number; per_page?: number } = {}) =>
    unwrapPaged<Inspection>(api.get('/inspections', { params: filters })),
  get: (id: number) => unwrap<Inspection>(api.get(`/inspections/${id}`)),
  conduct: (id: number, body: { result: InspectionResult; findings?: string }) =>
    unwrap<Inspection>(api.post(`/inspections/${id}/conduct`, body)),
  reschedule: (id: number, scheduled_at: string) =>
    unwrap<Inspection>(api.post(`/inspections/${id}/reschedule`, { scheduled_at })),
  /**
   * Book a fresh visit after a failed one. Answers with the NEW inspection.
   *
   * Not `reschedule`. That one moves a visit that has not happened yet and
   * overwrites `scheduled_at` on the row; this one leaves the failed visit
   * exactly where it is and adds a second row, so the record keeps saying the
   * premises failed once and passed later. The returned id is a different
   * inspection, and the caller has to go to it to record the result.
   */
  reinspect: (id: number, scheduled_at: string) =>
    unwrap<Inspection>(api.post(`/inspections/${id}/reinspect`, { scheduled_at })),
}

/* ── Permits ──────────────────────────────────────────────────────────── */

export const permits = {
  /**
   * Permits the caller may see, newest issuance first.
   *
   * Paged, and office-scoped: this used to return all 4,122 permits to every
   * office reviewer, not just BPLO. An owner's own list is unaffected.
   */
  list: (params: PageParams = {}) => unwrap<Permit[]>(api.get('/permits', { params })),
  /** Same list, keeping the page meta. */
  page: (params: PageParams = {}) => unwrapPaged<Permit>(api.get('/permits', { params })),
  get: (id: number) => unwrap<Permit>(api.get(`/permits/${id}`)),
  /**
   * The clearances this applicant submitted a COPY of, across every filing.
   *
   * Unpaged on purpose, unlike `list` above: this is bounded at six clearances
   * per filing and only ever holds the caller's own uploads, so there is no
   * 4,122-row case to defend against. Self-scoped server-side — an officer
   * calling it gets their own uploads, which is nothing.
   *
   * The rows are NOT permits. See `HeldClearance` for why they carry no number,
   * no validity and no verify link, and why rendering them like the issued list
   * would be a claim the City never made.
   */
  held: () => unwrap<HeldClearance[]>(api.get('/permits/held')),
  verify: (permitNumber: string) =>
    unwrap<import('./types').VerifyResult>(api.get(`/verify/${permitNumber}`)),
  /** Download the rendered permit certificate PDF (Bearer blob; v2). */
  pdf: (id: number, filename: string) => downloadBlob(`/permits/${id}/pdf`, filename),
}

/* ── Notifications ────────────────────────────────────────────────────── */

export const notifications = {
  /**
   * The notification centre, newest first. Paged (default 50).
   *
   * `unread` is the count across every notification, not the page — the badge
   * would otherwise under-report as soon as the unread ones fall past page one.
   */
  // `meta` is optional in the return type on purpose: callers build this shape
  // by hand for optimistic read/read-all updates, and requiring the page meta
  // there would make a local state update a paging concern.
  list: async (
    params: PageParams = {},
  ): Promise<{ data: Notification[]; unread: number; meta?: PageMeta }> => {
    const res = await api.get<{
      data: Notification[]
      meta: PageMeta & { unread: number }
    }>('/notifications', { params })
    return { data: res.data.data, unread: res.data.meta?.unread ?? 0, meta: res.data.meta }
  },
  read: (id: number) => api.post(`/notifications/${id}/read`),
  readAll: () => api.post('/notifications/read-all'),
}

/* ── Analytics ────────────────────────────────────────────────────────── */

/**
 * The Renewal Risk table's server-side filter and page.
 *
 * Every field is optional and omitted when unset, which is load-bearing rather
 * than tidy: the analytics snapshots are keyed on the parameters, so an
 * unfiltered request has to send exactly `days` and `limit` or it stops
 * matching the snapshot R precomputes and quietly drops the default screen onto
 * the PHP engine. axios omits `undefined` params, so leaving a field out is how
 * that is expressed.
 */
export interface RenewalRiskQuery {
  /** Barangay name, exactly as the payload's `barangays` list spells it. */
  barangay?: string
  band?: RiskBand
  action?: RiskAction
  /** First row of the page, counted over the filtered set. */
  offset?: number
}

export const analytics = {
  summary: () => unwrap<AnalyticsSummary>(api.get('/analytics/summary')),
  /** Download the summary as a CSV report (Bearer blob; v2). */
  export: (filename = 'biztrack-analytics.csv') => downloadBlob('/analytics/export', filename),

  /*
   * The three R-backed screens. Each resolves to { data, meta }: the statistics
   * plus when and by which engine they were computed. Read the meta onto the
   * screen — these are batch figures, as fresh as the last `analytics:refresh`,
   * and the PHP fallback stands in when R is unreachable.
   */

  /**
   * Screen 1: every Analytics Dashboard panel on one payload.
   *
   * One request, not one per panel: the KPI cards, application volume and
   * decision outcomes all describe the same month and have to reconcile, which
   * they cannot be relied on to do if each arrives from a different refresh.
   */
  dashboard: (months: number) =>
    unwrapComputed<DashboardReport>(api.get('/analytics/dashboard', { params: { months } })),
  dashboardReport: (months: number) =>
    downloadBlob(`/analytics/dashboard/report?months=${months}`, 'analytics-dashboard.pdf'),

  /** Feature 7: per-office control charts over weekly review turnaround. */
  processingTime: (weeks: number) =>
    unwrapComputed<ProcessingTimeReport>(
      api.get('/analytics/processing-time', { params: { weeks } }),
    ),
  processingTimeReport: (weeks: number) =>
    downloadBlob(`/analytics/processing-time/report?weeks=${weeks}`, 'processing-time-monitoring.pdf'),

  businessGrowth: (months: number) =>
    unwrapComputed<BusinessGrowthReport>(
      api.get('/analytics/business-growth', { params: { months } }),
    ),
  businessGrowthReport: (months: number) =>
    downloadBlob(`/analytics/business-growth/report?months=${months}`, 'business-growth-analysis.pdf'),

  /**
   * Renewal Risk: permits near expiry ranked by a weighted rule score.
   * `score` is out of 100 and is not a probability — see RenewalRiskReport.
   *
   * The filters go to the server rather than being applied to the rows that
   * come back, and here that is not a preference. The payload is the leading
   * `limit` rows BY SCORE; on this register the leading twenty-five are all
   * High, so filtering them in the browser for "Low risk" would return nothing
   * and report that the city has no low-risk businesses. It has thousands. The
   * same reasoning as the officer queue — see the note in QueuePage.
   */
  renewalRisk: (days: number, limit?: number, view?: RenewalRiskQuery) =>
    unwrapComputed<RenewalRiskReport>(
      api.get('/analytics/renewal-risk', { params: { days, limit, ...view } }),
    ),
  renewalRiskReport: (days: number) =>
    downloadBlob(`/analytics/renewal-risk/report?days=${days}`, 'renewal-risk.pdf'),

  /**
   * The fitted model shown beside that watchlist.
   *
   * Takes no arguments, and that is deliberate rather than an omission. The
   * horizon and the filters narrow which permits a reader is looking at; they do
   * not refit a regression, and the training set is the whole of permit history
   * either way. Passing them through would key to snapshots that can never exist
   * and serve the "no model" fallback for every filtered view, which a reader
   * would correctly read as an outage. See AnalyticsController::renewalModel().
   *
   * Resolves to `available: false` with a reason when R is down or the register
   * holds too little settled history to fit on. The screen renders that state
   * rather than a number, because there is no honest number to render.
   */
  renewalModel: () => unwrapComputed<RenewalModelReport>(api.get('/analytics/renewal-model')),

  /**
   * Send one renewal follow-up to a business owner, now.
   *
   * Keyed on the permit and not the business: a business commonly holds three
   * permits expiring on three dates and the watchlist has a row per permit, so
   * the row the officer pressed is the fact that has to travel.
   *
   * The server refuses a second send on the same permit the same day and says
   * so through `already_sent` — the guard is a unique index rather than a flag
   * in this tab, so it survives a reload, a second officer, and a replayed
   * request. Callers must still keep the button from firing twice while one is
   * in flight; that is about not making two requests, not about not sending two
   * messages.
   */
  sendRenewalReminder: (permitId: number) =>
    unwrap<RenewalReminderResult>(api.post(`/analytics/renewal-risk/${permitId}/remind`)),

  /**
   * Recompute every figure set from R now, rather than waiting for 03:00.
   *
   * Slow by nature — it pushes the register to R and waits for eight dataset
   * variants, a few seconds in total — so the caller has to show a pending state
   * rather than assume this returns promptly. Resolves to what happened per
   * dataset, because a refresh can partly succeed and the screens would then
   * mix fresh and stale figures.
   */
  refresh: () =>
    unwrap<AnalyticsRefreshResult>(api.post('/analytics/refresh')),
}

/* ── Admin ────────────────────────────────────────────────────────────── */

export interface AdminUserFilters extends PageParams {
  q?: string
  role?: string
  /** Narrow to one office — what the review screen's officer picker wants. */
  department_id?: number
}

export interface AdminBusinessFilters extends PageParams {
  q?: string
  status?: BusinessStatus
}

export const admin = {
  /**
   * The staff directory, alphabetical.
   *
   * Paged server-side. Asks for the ceiling because the review screen's officer
   * picker loads this and narrows to one department in the browser — pass
   * `department_id` instead and this can go back to a normal page.
   */
  users: (filters: AdminUserFilters = {}) =>
    unwrap<AdminUser[]>(
      api.get('/admin/users', { params: { per_page: PICKER_PAGE_SIZE, ...filters } }),
    ),
  /** Same directory, keeping the page meta. Prefer this on the Users screen. */
  usersPage: (filters: AdminUserFilters = {}) =>
    unwrapPaged<AdminUser>(api.get('/admin/users', { params: filters })),
  /** Real business roster for the Owner Status table (v2). Paged (default 50). */
  businesses: (filters: AdminBusinessFilters = {}) =>
    unwrap<AdminBusiness[]>(api.get('/admin/businesses', { params: filters })),
  /** Same roster, keeping the page meta. */
  businessesPage: (filters: AdminBusinessFilters = {}) =>
    unwrapPaged<AdminBusiness>(api.get('/admin/businesses', { params: filters })),
  /** Change a business's status with a reason (permission owner.manage_status; v2). */
  setBusinessStatus: (id: number, status: BusinessStatus, reason: string) =>
    unwrap<AdminBusiness>(api.post(`/admin/businesses/${id}/status`, { status, reason })),
  createUser: (body: AdminUserPayload) => unwrap<AdminUser>(api.post('/admin/users', body)),
  updateUser: (id: number, body: Partial<AdminUserPayload>) =>
    unwrap<AdminUser>(api.put(`/admin/users/${id}`, body)),
  toggleActive: (id: number) => unwrap<AdminUser>(api.post(`/admin/users/${id}/toggle-active`)),
  auditLogs: async (page = 1): Promise<{ data: AuditLog[]; lastPage: number; total: number }> => {
    const res = await api.get<{ data: AuditLog[]; meta?: Partial<PageMeta> }>('/admin/audit-logs', {
      params: { page },
    })
    return {
      data: res.data.data,
      lastPage: res.data.meta?.last_page ?? 1,
      total: res.data.meta?.total ?? res.data.data.length,
    }
  },
}
