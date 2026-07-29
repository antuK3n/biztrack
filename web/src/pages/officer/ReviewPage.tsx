import { useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  ArrowLeftIcon,
  CheckIcon,
  ClipboardIcon,
  DownloadIcon,
  EyeIcon,
} from '../../components/icons'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { MessagesPanel } from '../../components/MessagesPanel'
import { TaxOrderBreakdown } from '../../components/TaxOrderBreakdown'
import { FieldLabel, ProtoModal, inputCls } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { formatBytes, formatDate, formatDateTime, formatMoney } from '../../lib/format'
import { admin, applications, assignments, documents } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { useAuth } from '../../stores/auth'
import type { AdminUser, AppDocument, Application, FeeProfile } from '../../lib/types'

/*
 * Admin Review sheet (PDF p56, p67–p76): the officer reads the application as
 * the submitted BPLO form — filled read-only inputs, documents, consent note,
 * tan FOR OFFICE USE ONLY box — with Reject/Approve in the top-right header
 * zone and remark bubbles floating on the right.
 */

/** The assignment detail embeds the FULL business (address, lines) — the list types understate it. */
interface ReviewBusiness {
  name?: string
  trade_name?: string | null
  registration_number?: string | null
  tin?: string | null
  ban?: string | null
  address?: {
    line1?: string | null
    line2?: string | null
    city?: string | null
    province?: string | null
    barangay?: { name?: string } | null
  } | null
  lines?: {
    id: number
    psic_code: { code: string; title: string } | null
    capitalization: string | null
  }[]
}

const TYPE_TITLES: Record<string, string> = {
  new: 'New',
  renewal: 'Renewal of',
  amendment: 'Amendment of',
}

const readOnlyInput = `${inputCls} read-only:cursor-default`
const officeInput =
  'w-full rounded-md border border-dashed border-officeuse-border bg-white/70 px-3 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-officeuse-border'

function CloudIcon() {
  return (
    <svg width="26" height="18" viewBox="0 0 26 18" fill="none" aria-hidden="true">
      <path
        d="M20.8 7.1A7 7 0 0 0 7.2 5.6 5.5 5.5 0 0 0 6 16.5h14a4.8 4.8 0 0 0 .8-9.4Z"
        fill="#2b4fd8"
      />
      <path d="m9.5 10.5 2.4 2.4 4.6-4.6" stroke="#fff" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )
}

function FileGlyph({ className = 'text-royal' }: { className?: string }) {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" className={className} aria-hidden="true">
      <path
        d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinejoin="round"
      />
      <path d="M14 3v5h5" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
    </svg>
  )
}

/** Royal square section letter + bold label (p67 "A · Business Information & Registration"). */
function SectionHeading({ letter, children }: { letter: string; children: ReactNode }) {
  return (
    <div className="mb-4 flex items-center gap-2.5">
      <span className="flex h-6 w-6 items-center justify-center rounded bg-royal text-xs font-bold text-white">
        {letter}
      </span>
      <h2 className="text-[15px] font-bold text-ink">{children}</h2>
    </div>
  )
}

/** Royal tick sub-section label (p67 "Main Office Address"). */
function SubHeading({ children }: { children: ReactNode }) {
  return (
    <div className="mb-3 mt-6 flex items-center gap-2">
      <span className="h-4 w-1 rounded-full bg-royal" aria-hidden="true" />
      <h3 className="text-sm font-bold text-ink">{children}</h3>
    </div>
  )
}

function Field({ label, value, className = '' }: { label: string; value: string; className?: string }) {
  return (
    <label className={`block ${className}`}>
      <FieldLabel>{label}</FieldLabel>
      <input className={readOnlyInput} value={value || '—'} readOnly />
    </label>
  )
}

function DocumentRow({ doc }: { doc: AppDocument }) {
  const url = documents.downloadUrl(doc.id)
  return (
    <li className="flex items-center justify-between gap-3 rounded-lg border border-line bg-white px-4 py-3">
      <div className="flex min-w-0 items-center gap-3">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-line bg-royal-tint">
          <FileGlyph />
        </span>
        <div className="min-w-0">
          <p className="truncate text-sm font-bold text-ink">{doc.document_type.name}</p>
          <p className="truncate text-xs text-ink-muted">
            {doc.original_filename} · {formatBytes(doc.size_bytes)}
          </p>
        </div>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        <a
          href={url}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-1.5 rounded-md border border-line px-3 py-1.5 text-xs font-semibold text-ink-secondary hover:bg-canvas"
        >
          <EyeIcon size={14} />
          View
        </a>
        <a
          href={url}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-1.5 rounded-md bg-royal px-3 py-1.5 text-xs font-semibold text-white hover:bg-royal-hover"
        >
          <DownloadIcon size={14} />
          Download
        </a>
      </div>
    </li>
  )
}

/** Floating white remark bubble (p56/p71). */
function RemarkBubble({ author, remark }: { author: string; remark: string }) {
  return (
    <div className="rounded-xl bg-white p-4 shadow-card">
      <div className="flex items-center gap-2.5">
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-royal text-white">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14Z" />
          </svg>
        </span>
        <p className="text-sm font-bold text-ink">{author}</p>
      </div>
      <p className="mt-2.5 rounded-lg bg-input px-3.5 py-2 text-sm text-ink">{remark}</p>
    </div>
  )
}

/** Floating remark composer popup (p70) — used for Reject and Return. */
function RemarkPopup({
  officer,
  submitting,
  error,
  onCancel,
  onConfirm,
}: {
  officer: string
  submitting: boolean
  error: string | null
  onCancel: () => void
  onConfirm: (text: string) => void
}) {
  const [text, setText] = useState('')
  return (
    <div className="rounded-xl bg-white p-4 shadow-overlay">
      <div className="flex items-center gap-2.5">
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-royal text-white">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14Z" />
          </svg>
        </span>
        <p className="text-sm font-bold text-ink">{officer}</p>
      </div>
      <textarea
        value={text}
        onChange={(e) => setText(e.target.value)}
        placeholder="Type here…"
        rows={3}
        className="mt-3 w-full rounded-lg border border-input-border bg-input px-3.5 py-2.5 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
      />
      {error && <p className="mt-1.5 text-xs font-medium text-s-red">{error}</p>}
      <div className="mt-3 flex justify-end gap-2">
        <button
          type="button"
          onClick={onCancel}
          disabled={submitting}
          className="rounded-md bg-modal-cancel px-4 py-1.5 text-sm font-semibold text-ink underline underline-offset-2 hover:brightness-95"
        >
          Cancel
        </button>
        <button
          type="button"
          onClick={() => onConfirm(text.trim())}
          disabled={submitting || !text.trim()}
          className="rounded-md bg-royal px-4 py-1.5 text-sm font-semibold text-white underline underline-offset-2 hover:bg-royal-hover disabled:opacity-60"
        >
          Confirm
        </button>
      </div>
    </div>
  )
}

function ReviewSkeleton() {
  return (
    <div className="space-y-4">
      <Skeleton className="h-8 w-64" />
      <div className="rounded-sm bg-white p-8 shadow-card">
        <Skeleton className="h-4 w-80" />
        <Skeleton className="mt-3 h-7 w-96" />
        <Skeleton className="mt-6 h-11 w-full" />
        <Skeleton className="mt-3 h-11 w-full" />
        <Skeleton className="mt-3 h-11 w-full" />
      </div>
    </div>
  )
}

/** "floor_area_sqm" / "floorAreaSqm" → "Floor Area Sqm". */
function humanizeKey(key: string): string {
  return key
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    .replace(/[_-]+/g, ' ')
    .trim()
    .replace(/\b\w/g, (c) => c.toUpperCase())
}

/** Render an opaque office-form answer as display text. */
function formValueText(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (Array.isArray(value)) return value.map((v) => formValueText(v)).join(', ')
  if (typeof value === 'object') {
    return Object.entries(value as Record<string, unknown>)
      .map(([k, v]) => `${humanizeKey(k)}: ${formValueText(v)}`)
      .join(' · ')
  }
  return String(value)
}

const LOCATION_LABELS: Record<string, string> = {
  within: 'Within the city',
  outside: 'Outside the city',
}

/** Present fee-profile facts as labeled read-only values (absent fields skipped). */
function feeProfileFacts(profile: FeeProfile): { label: string; value: string }[] {
  const facts: { label: string; value: string }[] = []
  const put = (label: string, value: string | null | undefined) => {
    if (value) facts.push({ label, value })
  }
  const money = (n?: number) => (n == null ? null : formatMoney(n))
  const count = (n?: number) => (n == null ? null : String(n))
  put('Gross Sales (Preceding Year)', money(profile.gross_sales))
  put('Capitalization', money(profile.capitalization))
  put('Construction Cost', money(profile.construction_cost))
  put('Floor Area', profile.floor_area_sqm == null ? null : `${profile.floor_area_sqm} sqm`)
  put('Employees', count(profile.employees))
  put('Storeys', count(profile.storeys))
  put('Doors', count(profile.doors))
  put('Rooms', count(profile.rooms))
  put('Beds', count(profile.beds))
  put('Market Stalls', count(profile.stall_count))
  put('Delivery Vehicles (Motorized)', count(profile.delivery_vehicles_motorized))
  put('Delivery Vehicles (Other)', count(profile.delivery_vehicles_other))
  put('Business Structure', profile.business_structure ? humanizeKey(profile.business_structure) : null)
  put('Goods Class', profile.goods_class ? humanizeKey(profile.goods_class) : null)
  put('Office Location', profile.office_location ? LOCATION_LABELS[profile.office_location] : null)
  put('Warehouse Location', profile.warehouse_location ? LOCATION_LABELS[profile.warehouse_location] : null)
  put('Factory Location', profile.factory_location ? LOCATION_LABELS[profile.factory_location] : null)
  put('Property Use', profile.property_use ? humanizeKey(profile.property_use) : null)
  put('Occupancy Group', profile.occupancy_group ? profile.occupancy_group.toUpperCase() : null)
  return facts
}

/** "24 Mabini Street" → { house: "24", street: "Mabini Street" }. */
function splitLine1(line1: string | null | undefined): { house: string; street: string } {
  const raw = (line1 ?? '').trim()
  const match = raw.match(/^(\d+\S*)\s+(.+)$/)
  if (match) return { house: match[1], street: match[2] }
  return { house: '—', street: raw }
}

export function ReviewPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const assignmentId = Number(id)
  const { data, loading, error, reload } = useAsync(() => assignments.get(assignmentId), [assignmentId])

  const user = useAuth((s) => s.user)
  const canAdjustFee = Boolean(user?.permissions.includes('fee.adjust'))
  const canAssign = Boolean(user?.permissions.includes('oic.assign'))
  const canListUsers = Boolean(user?.permissions.includes('user.manage'))

  const [popup, setPopup] = useState<'reject' | 'return' | null>(null)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [showVerification, setShowVerification] = useState(false)

  // FOR OFFICE USE ONLY inputs (editable during review; remarks feed approve).
  const [officeUse, setOfficeUse] = useState({ receipt: '', receivedBy: '', ban: '', psic: '', fee: '', remarks: '' })
  const [officeTouched, setOfficeTouched] = useState<Record<string, boolean>>({})

  // Fee adjustment (fee.adjust) + officer assignment (oic.assign) — v2.
  const [feeSaving, setFeeSaving] = useState(false)
  const [feeNote, setFeeNote] = useState<string | null>(null)
  const [assignTarget, setAssignTarget] = useState('')
  const [assignReason, setAssignReason] = useState('')
  const [assignBusy, setAssignBusy] = useState(false)
  const [assignNote, setAssignNote] = useState<string | null>(null)

  // Dept officers for the assign control (only fetched when both permitted).
  const { data: allUsers } = useAsync<AdminUser[]>(
    () => (canAssign && canListUsers ? admin.users() : Promise.resolve([])),
    [canAssign, canListUsers],
  )

  const backLink = (
    <Link to="/queue" className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-royal hover:underline">
      <ArrowLeftIcon size={16} />
      Back to Application Verification
    </Link>
  )

  if (loading)
    return (
      <div>
        {backLink}
        <ReviewSkeleton />
      </div>
    )
  if (error)
    return (
      <div>
        {backLink}
        <ErrorState error={error} onRetry={reload} />
      </div>
    )
  if (!data)
    return (
      <div>
        {backLink}
        <p className="rounded-lg bg-white px-5 py-6 text-sm text-ink-secondary shadow-card">
          This assignment may have been reassigned or completed. Return to your queue.
        </p>
      </div>
    )

  const app: Application = data.application
  const business = app.business as unknown as ReviewBusiness
  const address = business.address ?? null
  const { house, street } = splitLine1(address?.line1)
  const officerName = data.officer?.name ?? data.department.name

  // Submitted per-office form answers: the reviewing office's form(s) first.
  const officeForms = [...(app.office_forms ?? [])].sort(
    (a, b) =>
      Number(b.department_code === data.department.code) -
      Number(a.department_code === data.department.code),
  )
  const feeProfile = app.fee_profile ?? null
  const feeFacts = feeProfile ? feeProfileFacts(feeProfile) : []
  const feeLines = feeProfile?.lines ?? []
  const feeFlags = feeProfile?.flags ?? []
  const hasFeeDeclaration = feeFacts.length > 0 || feeLines.length > 0 || feeFlags.length > 0

  const rejected = app.status === 'rejected'
  const approvedHere = ['approved', 'completed'].includes(data.status.toLowerCase())
  const decided = rejected || approvedHere || Boolean(data.completed_at)

  // Office-use prefills from the real record; user edits override.
  const office = {
    receipt: officeTouched.receipt ? officeUse.receipt : formatDate(app.submitted_at),
    receivedBy: officeTouched.receivedBy ? officeUse.receivedBy : (data.officer?.name ?? ''),
    ban: officeTouched.ban ? officeUse.ban : (business.ban ?? ''),
    psic: officeTouched.psic ? officeUse.psic : (business.lines?.[0]?.psic_code?.code ?? ''),
    fee: officeTouched.fee ? officeUse.fee : (app.fee_assessment?.total_amount ?? ''),
    remarks: officeUse.remarks,
  }
  function setOffice(key: keyof typeof officeUse, value: string) {
    setOfficeTouched((t) => ({ ...t, [key]: true }))
    setOfficeUse((v) => ({ ...v, [key]: value }))
  }

  async function approve() {
    setBusy(true)
    setActionError(null)
    try {
      await assignments.approve(assignmentId, office.remarks || undefined)
      setShowVerification(true)
      reload()
    } catch (err) {
      setActionError(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  async function sendRemark(text: string) {
    setBusy(true)
    setActionError(null)
    try {
      if (popup === 'reject') await applications.reject(app.id, text)
      else await assignments.return(assignmentId, text)
      setPopup(null)
      reload()
    } catch (err) {
      setActionError(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  async function saveAssessment() {
    const amount = office.fee.trim()
    if (!amount) return
    setFeeSaving(true)
    setFeeNote(null)
    setActionError(null)
    try {
      await applications.feeAdjust(app.id, [{ label: 'Adjusted assessment', amount }], amount)
      setFeeNote(`Assessment saved at ${formatMoney(amount)}. The owner was notified.`)
      reload()
    } catch (err) {
      setActionError(toApiError(err).message)
    } finally {
      setFeeSaving(false)
    }
  }

  async function assignOfficer() {
    if (!assignTarget) return
    setAssignBusy(true)
    setAssignNote(null)
    setActionError(null)
    try {
      const assigned = await assignments.assign(
        assignmentId,
        Number(assignTarget),
        assignReason.trim() || undefined,
      )
      setAssignNote(`Assigned to ${assigned.officer?.name ?? 'the selected officer'}.`)
      setAssignReason('')
      reload()
    } catch (err) {
      setActionError(toApiError(err).message)
    } finally {
      setAssignBusy(false)
    }
  }

  // Officers in this assignment's department (assign target options).
  const deptOfficers = (allUsers ?? []).filter(
    (u) =>
      !u.roles.includes('business_owner') &&
      u.is_active &&
      u.department?.code === data.department.code,
  )

  const existingRemarks = [
    ...app.assignments
      .filter((a) => a.remarks)
      .map((a) => ({
        key: `a-${a.id}`,
        author: a.officer?.name ?? a.department.name,
        remark: a.remarks as string,
      })),
    ...(app.rejection_reason
      ? [{ key: 'rejection', author: officerName, remark: app.rejection_reason }]
      : []),
  ]

  return (
    <div>
      {backLink}

      {/* Header zone (p68): clipboard + business · saved cloud · Reject/Approve or decision */}
      <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div className="flex min-w-0 items-center gap-3">
          <ClipboardIcon size={30} className="shrink-0 text-royal" />
          <h1 className="truncate text-2xl font-bold text-ink">{business.name ?? '—'}</h1>
        </div>
        <span className="hidden items-center gap-2 text-xs italic text-ink-muted sm:flex">
          <CloudIcon />
          All Changes Saved
        </span>
        {decided ? (
          <span
            className={`text-2xl font-bold underline underline-offset-4 ${rejected ? 'text-s-red' : 'text-s-green'}`}
          >
            {rejected ? 'Rejected' : 'Approved'}
          </span>
        ) : (
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => setPopup('reject')}
              disabled={busy}
              className="rounded-md bg-s-red px-7 py-2.5 text-sm font-semibold text-white underline underline-offset-2 shadow-card hover:brightness-110 disabled:opacity-60"
            >
              Reject
            </button>
            <button
              type="button"
              onClick={approve}
              disabled={busy}
              className="rounded-md bg-s-green px-7 py-2.5 text-sm font-semibold text-white underline underline-offset-2 shadow-card hover:brightness-110 disabled:opacity-60"
            >
              Approve
            </button>
          </div>
        )}
      </div>

      {actionError && (
        <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">{actionError}</p>
      )}

      <div className="flex items-start gap-6">
        {/* ── The form sheet ── */}
        <div className="min-w-0 flex-1 rounded-sm bg-white px-7 py-8 shadow-card sm:px-10">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-royal">
                Business Permit &amp; Licensing Office · Admin Review
              </p>
              <h2 className="mt-1 text-xl font-bold text-ink">
                Application for {TYPE_TITLES[app.application_type] ?? app.application_type} Business Permit
              </h2>
              <p className="mt-1 text-xs text-ink-muted">Form Ref: MCG-BPLO-FO-001 · v2.0</p>
            </div>
            <p className="text-sm text-ink">
              <span className="font-bold">Application No.</span> <span className="tnum">{app.tracking_id}</span>
            </p>
          </div>
          <div className="mt-4 border-b-2 border-royal" />

          {/* A — Business Information & Registration */}
          <section className="mt-7">
            <SectionHeading letter="A">Business Information &amp; Registration</SectionHeading>
            <div className="space-y-4">
              <Field label="DTI / SEC / CDA Registration Number" value={business.registration_number ?? ''} />
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Tax Identification Number (TIN)" value={business.tin ?? ''} />
                <Field label="Business Name" value={business.name ?? ''} />
              </div>
              <Field label="Trade Name / Franchise" value={business.trade_name ?? ''} />
            </div>

            <SubHeading>Main Office Address</SubHeading>
            <div className="grid gap-4 sm:grid-cols-3">
              <Field label="House / Bldg No." value={address?.line2 || house} />
              <Field label="Street" value={street} className="sm:col-span-2" />
              <Field label="Barangay" value={address?.barangay?.name ?? ''} />
              <Field label="City / Municipality" value={address?.city ?? 'Malabon City'} />
              <Field label="Province" value={address?.province ?? 'Metro Manila'} />
            </div>
          </section>

          {/* B — Lines of business */}
          <section className="mt-9">
            <SectionHeading letter="B">Line of Business</SectionHeading>
            {business.lines && business.lines.length > 0 ? (
              <div className="space-y-4">
                {business.lines.map((line, i) => (
                  <div key={line.id ?? i} className="grid gap-4 sm:grid-cols-3">
                    <Field
                      label={`Line of Business ${business.lines!.length > 1 ? i + 1 : ''}`.trim()}
                      value={line.psic_code ? `${line.psic_code.title} (${line.psic_code.code})` : ''}
                      className="sm:col-span-2"
                    />
                    <Field label="Capitalization" value={line.capitalization ?? ''} />
                  </div>
                ))}
              </div>
            ) : (
              <Field label="Line of Business" value={app.permit_types.map((p) => p.name).join(', ')} />
            )}
          </section>

          {/* C — Documentary requirements */}
          <section className="mt-9">
            <SectionHeading letter="C">Documentary Requirements</SectionHeading>
            {app.documents.length === 0 ? (
              <p className="rounded-lg border border-line px-4 py-5 text-center text-sm text-ink-muted">
                No documents were uploaded with this application.
              </p>
            ) : (
              <ul className="space-y-2.5">
                {app.documents.map((doc) => (
                  <DocumentRow key={doc.id} doc={doc} />
                ))}
              </ul>
            )}
          </section>

          {/* D — Submitted office-form answers (per permit type) */}
          <section className="mt-9">
            <SectionHeading letter="D">Office Form Answers</SectionHeading>
            {officeForms.length === 0 ? (
              <p className="rounded-lg border border-line px-4 py-5 text-center text-sm text-ink-muted">
                The applicant did not fill any per-office forms for this application.
              </p>
            ) : (
              officeForms.map((form, formIndex) => {
                const entries = Object.entries(form.form_data ?? {})
                const reviewingHere = form.department_code === data.department.code
                return (
                  <div key={form.permit_type_code ?? formIndex}>
                    <div className={`mb-3 flex items-center gap-2 ${formIndex === 0 ? 'mt-1' : 'mt-6'}`}>
                      <span className="h-4 w-1 rounded-full bg-royal" aria-hidden="true" />
                      <h3 className="text-sm font-bold text-ink">
                        {form.permit_type_name ?? form.permit_type_code}
                      </h3>
                      {reviewingHere && (
                        <span className="rounded-md bg-royal-tint px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-royal">
                          Your office
                        </span>
                      )}
                    </div>
                    {entries.length === 0 ? (
                      <p className="text-sm text-ink-muted">No answers were recorded on this form.</p>
                    ) : (
                      <div className="grid gap-4 sm:grid-cols-2">
                        {entries.map(([key, value]) => (
                          <Field key={key} label={humanizeKey(key)} value={formValueText(value)} />
                        ))}
                      </div>
                    )}
                  </div>
                )
              })
            )}
          </section>

          {/* E — Applicant-declared fee inputs (revenue-code profile) */}
          <section className="mt-9">
            <SectionHeading letter="E">Fee Declaration</SectionHeading>
            {!hasFeeDeclaration ? (
              <p className="rounded-lg border border-line px-4 py-5 text-center text-sm text-ink-muted">
                No fee declaration was submitted with this application.
              </p>
            ) : (
              <>
                {feeLines.length > 0 && (
                  <div className="space-y-4">
                    {feeLines.map((line, i) => (
                      <div key={i} className="grid gap-4 sm:grid-cols-3">
                        <Field
                          label={`Business Category ${feeLines.length > 1 ? i + 1 : ''}`.trim()}
                          value={humanizeKey(line.category)}
                        />
                        <Field
                          label="Gross Sales (Preceding Year)"
                          value={line.gross_sales == null ? '' : formatMoney(line.gross_sales)}
                        />
                        <Field
                          label="Capitalization"
                          value={line.capitalization == null ? '' : formatMoney(line.capitalization)}
                        />
                      </div>
                    ))}
                  </div>
                )}
                {feeFacts.length > 0 && (
                  <div className={`grid gap-4 sm:grid-cols-3 ${feeLines.length > 0 ? 'mt-4' : ''}`}>
                    {feeFacts.map((fact) => (
                      <Field key={fact.label} label={fact.label} value={fact.value} />
                    ))}
                  </div>
                )}
                {feeFlags.length > 0 && (
                  <div className="mt-4">
                    <FieldLabel>Declared Flags</FieldLabel>
                    <div className="flex flex-wrap gap-2">
                      {feeFlags.map((flag) => (
                        <span
                          key={flag}
                          className="rounded-md bg-canvas px-2.5 py-1 text-xs font-semibold text-ink-secondary"
                        >
                          {humanizeKey(flag)}
                        </span>
                      ))}
                    </div>
                  </div>
                )}
              </>
            )}
          </section>

          {/* Consent note (p72) */}
          <div className="mt-6 rounded-md border border-s-green bg-s-green-tint px-4 py-3">
            <p className="flex items-center gap-2 text-sm font-bold text-s-green">
              <CheckIcon size={16} />
              Data Privacy Consent: agreed by applicant
            </p>
            <p className="mt-1 text-xs text-ink-muted">
              Consent recorded {formatDateTime(app.submitted_at)} · under RA 10173 (Data Privacy Act of 2012).
            </p>
          </div>

          {/* Signatures (p72) */}
          <div className="mt-6 grid gap-6 sm:grid-cols-2">
            <div>
              <div className="flex h-16 items-center justify-center rounded-md border border-line bg-white shadow-card">
                <span className="display-serif italic text-royal">{app.applicant.name}</span>
              </div>
              <p className="mt-2 border-t border-ink/40 pt-1.5 text-center text-[11px] text-ink-secondary">
                Signature of Applicant / Owner over Printed Name · Sole Proprietor
              </p>
            </div>
            <div>
              <div className="flex h-16 items-center justify-center rounded-md border border-line bg-white shadow-card">
                <span className="text-xs text-ink-muted">No representative</span>
              </div>
              <p className="mt-2 border-t border-ink/40 pt-1.5 text-center text-[11px] text-ink-secondary">
                Signature of Representative over Printed Name
              </p>
            </div>
          </div>

          {/* FOR OFFICE USE ONLY (p72/p76) */}
          <div className="mt-8 rounded-lg border border-officeuse-border bg-officeuse px-5 py-5">
            <p className="text-[11px] font-bold uppercase tracking-wide text-amber-800">
              ✎ For Office Use Only — Complete During Review
            </p>
            <div className="mt-4 grid gap-4 sm:grid-cols-3">
              <label className="block">
                <FieldLabel>Date of Receipt</FieldLabel>
                <input
                  className={officeInput}
                  value={office.receipt}
                  placeholder="dd/mm/yyyy"
                  onChange={(e) => setOffice('receipt', e.target.value)}
                />
              </label>
              <label className="block">
                <FieldLabel>Received by</FieldLabel>
                <input
                  className={officeInput}
                  value={office.receivedBy}
                  placeholder="Officer name"
                  onChange={(e) => setOffice('receivedBy', e.target.value)}
                />
              </label>
              <label className="block">
                <FieldLabel>Business Account No. (BAN)</FieldLabel>
                <input
                  className={officeInput}
                  value={office.ban}
                  placeholder="Assign BAN"
                  onChange={(e) => setOffice('ban', e.target.value)}
                />
              </label>
              <label className="block">
                <span className="mb-1.5 flex items-center gap-1.5 text-[13px] font-semibold text-ink">
                  PSIC Code
                  <span className="rounded bg-officeuse-border px-1.5 py-px text-[9px] font-bold uppercase tracking-wide text-ink">
                    BPLO/CTO
                  </span>
                </span>
                <input
                  className={officeInput}
                  value={office.psic}
                  placeholder="e.g. 5610"
                  onChange={(e) => setOffice('psic', e.target.value)}
                />
              </label>
              <label className="block">
                <FieldLabel>Assessed Fee (Php)</FieldLabel>
                <input
                  className={`${officeInput} tnum`}
                  value={office.fee}
                  placeholder="0.00"
                  readOnly={!canAdjustFee}
                  onChange={(e) => setOffice('fee', e.target.value)}
                />
                {canAdjustFee && (
                  <span className="mt-1.5 flex items-center gap-2">
                    <button
                      type="button"
                      onClick={saveAssessment}
                      disabled={feeSaving || !office.fee.trim()}
                      className="rounded-md bg-royal px-3 py-1 text-xs font-semibold text-white hover:bg-royal-hover disabled:opacity-60"
                    >
                      {feeSaving ? 'Saving…' : 'Save assessment'}
                    </button>
                    {feeNote && <span className="text-xs font-medium text-s-green">{feeNote}</span>}
                  </span>
                )}
              </label>
              <label className="block">
                <FieldLabel>Evaluator Remarks</FieldLabel>
                <input
                  className={officeInput}
                  value={office.remarks}
                  placeholder="Notes for this application"
                  onChange={(e) => setOffice('remarks', e.target.value)}
                />
              </label>
            </div>
          </div>

          {/* Itemized Tax Order of Payment (revenue-code assessment) */}
          {(app.fee_assessment?.line_items?.length ?? 0) > 0 && (
            <div className="mt-6 rounded-lg border border-line bg-white px-5 py-5">
              <p className="text-[11px] font-bold uppercase tracking-wide text-royal">
                Tax Order of Payment
              </p>
              <div className="mt-4">
                <TaxOrderBreakdown fee={app.fee_assessment} showCitations />
              </div>
              <div className="mt-4 flex items-baseline justify-between border-t border-ink/40 pt-3 text-base font-bold text-ink">
                <span>Total Amount</span>
                <span className="tnum">{formatMoney(app.fee_assessment?.total_amount)}</span>
              </div>
            </div>
          )}

          {/* Assign officer (oic.assign) — v2 */}
          {canAssign && !decided && (
            <div className="mt-6 rounded-lg border border-royal/30 bg-royal-tint px-5 py-5">
              <p className="text-[11px] font-bold uppercase tracking-wide text-royal">
                Assign officer-in-charge
              </p>
              {deptOfficers.length === 0 ? (
                <p className="mt-2 text-sm text-ink-secondary">
                  No active officers found for {data.department.name}.
                </p>
              ) : (
                <div className="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                  <label className="block">
                    <FieldLabel>Officer</FieldLabel>
                    <select
                      className={inputCls}
                      value={assignTarget}
                      onChange={(e) => setAssignTarget(e.target.value)}
                    >
                      <option value="">Select officer…</option>
                      {deptOfficers.map((o) => (
                        <option key={o.id} value={o.id}>
                          {[o.first_name, o.last_name].filter(Boolean).join(' ')}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="block">
                    <FieldLabel>Reason (optional)</FieldLabel>
                    <input
                      className={inputCls}
                      value={assignReason}
                      placeholder="e.g. load balancing"
                      onChange={(e) => setAssignReason(e.target.value)}
                    />
                  </label>
                  <button
                    type="button"
                    onClick={assignOfficer}
                    disabled={assignBusy || !assignTarget}
                    className="h-10 rounded-md bg-royal px-5 text-sm font-semibold text-white hover:bg-royal-hover disabled:opacity-60"
                  >
                    {assignBusy ? 'Assigning…' : 'Assign'}
                  </button>
                </div>
              )}
              {assignNote && <p className="mt-2 text-xs font-medium text-s-green">{assignNote}</p>}
            </div>
          )}

          {!decided && (
            <div className="mt-6 text-right">
              <button
                type="button"
                onClick={() => setPopup('return')}
                className="text-sm font-semibold text-royal underline underline-offset-2 hover:no-underline"
              >
                Return with remarks
              </button>
            </div>
          )}

          {/* Messages thread (v2) */}
          <MessagesPanel applicationId={app.id} />
        </div>

        {/* ── Floating remarks column (p56/p70) ── */}
        <aside className="sticky top-8 hidden w-72 shrink-0 space-y-4 lg:block" aria-label="Remarks">
          {popup && (
            <RemarkPopup
              officer={officerName}
              submitting={busy}
              error={actionError}
              onCancel={() => setPopup(null)}
              onConfirm={sendRemark}
            />
          )}
          {existingRemarks.map((r) => (
            <RemarkBubble key={r.key} author={r.author} remark={r.remark} />
          ))}
        </aside>
      </div>

      {/* Small-screen remark composer (the aside is hidden below lg) */}
      {popup && (
        <div className="fixed inset-x-4 bottom-6 z-40 lg:hidden">
          <RemarkPopup
            officer={officerName}
            submitting={busy}
            error={actionError}
            onCancel={() => setPopup(null)}
            onConfirm={sendRemark}
          />
        </div>
      )}

      {showVerification && (
        <ProtoModal
          title="VERIFICATION"
          cancelLabel="Home Page"
          confirmLabel="Tracking Page"
          onCancel={() => navigate('/dashboard')}
          onConfirm={() => navigate('/queue')}
        >
          <p className="py-4 text-center text-base">Where would you like to go?</p>
        </ProtoModal>
      )}
    </div>
  )
}
