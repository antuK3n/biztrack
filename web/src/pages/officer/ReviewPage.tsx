import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  ArrowLeftIcon,
  CheckIcon,
  ClipboardIcon,
  EyeIcon,
} from '../../components/icons'
import { DocumentActions } from '../../components/DocumentActions'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { MessagesPanel } from '../../components/MessagesPanel'
import { TaxOrderBreakdown } from '../../components/TaxOrderBreakdown'
import { FieldLabel, FilterPills, ProtoModal, inputCls } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { formatBytes, formatDate, formatDateTime, formatMoney } from '../../lib/format'
import { admin, applications, assignments, officeForms as officeFormsApi } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { useAuth } from '../../stores/auth'
import type { AdminUser, AppDocument, Application, FeeProfile } from '../../lib/types'

/*
 * Admin Review sheet (PDF p56, p67–p76): the officer reads the application as
 * the submitted BPLO form — documents, consent note, tan FOR OFFICE USE ONLY
 * box — with remark bubbles floating on the right.
 *
 * The screen has two modes (tester checklist item 54). It opens in View: a
 * record of what the applicant filed, with nothing on it that can be typed
 * into. Edit turns on the handful of fields the office actually owns and the
 * decision buttons. The applicant's own answers are never editable in either
 * mode, which is what the API enforces too: OfficeFormController lets the owner
 * write the answers and the reviewer write only the issuance dates.
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

/*
 * Issuance dates the applicant can never know: the office that issued the
 * document records them here during review, and they save straight back into
 * that permit type's office form.
 */
const OFFICER_DATE_FIELDS: Record<string, { key: string; label: string }[]> = {
  OCCUPANCY: [
    { key: 'building_permit_date', label: 'Building Permit Date Issued' },
    { key: 'fsec_date', label: 'FSEC Date Issued' },
  ],
}

/** Today as a local-timezone YYYY-MM-DD string (input[type=date] max). */
function todayISO(): string {
  const d = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/** View / Edit, the two ways of being on this screen (checklist item 54). */
type ReviewMode = 'view' | 'edit'

const MODE_OPTIONS: { value: ReviewMode; label: string }[] = [
  { value: 'view', label: 'View' },
  { value: 'edit', label: 'Edit' },
]

/*
 * A submitted answer. It keeps the prototype's filled-blue surface but is no
 * longer an input: read-only inputs looked exactly like the boxes the office
 * fills in, so nothing on the page said which half was a record and which half
 * was work.
 */
const recordValue = 'w-full rounded-lg border border-input-border bg-input px-3.5 py-2.5 text-sm text-ink'
const officeInput =
  'w-full rounded-md border border-dashed border-officeuse-border bg-white/70 px-3 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-officeuse-border'
/** An office value in View mode, or one nobody types here: same footprint, no affordance. */
const officeValue =
  'w-full rounded-md border border-dashed border-officeuse-border bg-white/70 px-3 py-2 text-sm text-ink'

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

function PencilIcon({ size = 16 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16v4Z"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinejoin="round"
      />
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

/** One answer the applicant submitted, presented as a record, never a control. */
function Field({ label, value, className = '' }: { label: string; value: string; className?: string }) {
  return (
    <dl className={`block ${className}`}>
      <dt className="mb-1.5 block text-[13px] font-semibold text-ink">{label}</dt>
      <dd className={recordValue}>{value || '—'}</dd>
    </dl>
  )
}

/** A FOR OFFICE USE ONLY value the officer is not filling in right now. */
function OfficeReadout({ label, value }: { label: string; value: string }) {
  // Column-stretched so a label that wraps to two lines does not push its
  // value out of line with its neighbours.
  return (
    <dl className="flex h-full flex-col">
      <dt className="mb-1.5 block text-[13px] font-semibold text-ink">{label}</dt>
      <dd className={`mt-auto ${officeValue}`}>{value || '—'}</dd>
    </dl>
  )
}

/*
 * Uploaded requirement. The two actions moved to <DocumentActions> (item 96):
 * they were written here for item 55 and stayed here, so the applicant's own
 * screens never got them and the bug read as unfixed from the other seat. The
 * shared control also carries the accessible names — a column of buttons all
 * called "View" does not say which of nine documents it opens.
 */
function DocumentRow({ doc }: { doc: AppDocument }) {
  return (
    <li className="rounded-lg border border-line bg-white px-4 py-3">
      <div className="flex items-center justify-between gap-3">
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
        <DocumentActions
          id={doc.id}
          filename={doc.original_filename}
          label={doc.document_type.name}
        />
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

/** What each decision does, said where it is being made rather than after. */
const REMARK_COPY = {
  reject: {
    heading: 'Reject this application',
    label: 'Reason for rejection',
    help: 'This ends the application for every office. The applicant sees this reason on their Track page, so say what was wrong.',
    confirm: 'Reject application',
    confirmCls: 'bg-s-red hover:brightness-110',
  },
  return: {
    heading: 'Return to the applicant',
    label: 'What the applicant must fix',
    help: 'Your office sends the filing back for revision. The applicant sees these remarks on their Track page and can resubmit.',
    confirm: 'Return application',
    confirmCls: 'bg-royal hover:bg-royal-hover',
  },
} as const

/**
 * Floating remark composer popup (p70) — used for Reject and Return.
 *
 * The remark is required for both, and not only because the button is disabled
 * without one: a rejection with no reason gives the applicant a dead filing and
 * nothing to do about it, which is the half of checklist item 80 that is not
 * about buttons. The API agrees — `reason` on POST /applications/{id}/reject
 * and `remarks` on POST /assignments/{id}/return are both `required`.
 *
 * The popup used to say only the officer's name, so Reject and Return opened
 * the identical box and the only thing distinguishing the strongest action in
 * the system from a recoverable one was which control you had clicked a moment
 * earlier.
 */
function RemarkPopup({
  action,
  officer,
  submitting,
  error,
  onCancel,
  onConfirm,
}: {
  action: 'reject' | 'return'
  officer: string
  submitting: boolean
  error: string | null
  onCancel: () => void
  onConfirm: (text: string) => void
}) {
  const [text, setText] = useState('')
  const copy = REMARK_COPY[action]
  const empty = !text.trim()
  return (
    <div className="rounded-xl bg-white p-4 shadow-overlay">
      <div className="flex items-center gap-2.5">
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-royal text-white">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14Z" />
          </svg>
        </span>
        <div className="min-w-0">
          <p className="text-sm font-bold text-ink">{copy.heading}</p>
          <p className="truncate text-xs text-ink-muted">{officer}</p>
        </div>
      </div>
      <label className="mt-3 block">
        <span className="text-xs font-bold text-ink">
          {copy.label} <span className="text-s-red">*</span>
        </span>
        <textarea
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder="Type here…"
          rows={3}
          required
          aria-describedby={`remark-help-${action}`}
          className="mt-1.5 w-full rounded-lg border border-input-border bg-input px-3.5 py-2.5 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
        />
      </label>
      <p id={`remark-help-${action}`} className="mt-1 text-xs text-ink-secondary">
        {copy.help}
      </p>
      {error && <p className="mt-1.5 text-xs font-medium text-s-red">{error}</p>}
      {/*
       * Why the button is off, said out loud. A disabled control with no reason
       * beside it is the officer's problem to solve by guessing.
       */}
      {empty && (
        <p aria-live="polite" className="mt-1.5 text-xs font-medium text-ink-muted">
          Write the reason to continue.
        </p>
      )}
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
          disabled={submitting || empty}
          className={`rounded-md px-4 py-1.5 text-sm font-semibold text-white underline underline-offset-2 disabled:opacity-60 ${copy.confirmCls}`}
        >
          {submitting ? 'Working…' : copy.confirm}
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
  /*
   * Rejecting kills the whole application across every office, so it sits behind
   * its own permission that BPLO and admin hold and the sanitary and fire
   * reviewers do not. The button was shown to all of them: a CHO officer could
   * open the composer, type a reason, confirm, and get "You do not have
   * permission to perform this action" — having already written the thing.
   *
   * Checklist item 80 reports the other side of that fix: "no reject button in
   * some offices". Six of the eight staff roles have none — sanitary, fire,
   * zoning, OBO, CENRO and market (see api RbacSeeder). That is deliberate and
   * stays: one office cannot end another office's filing.
   *
   * What was wrong is that those six were left looking like they could only ever
   * approve. Their negative decision — Return with remarks, which is per-office,
   * requires a reason, and is recoverable — was a bare text link at the far
   * bottom of a very long sheet while Approve sat alone in the header. Both
   * decisions now sit together where the decision is made. See the report note:
   * a per-office REJECTION (as opposed to a return) has no state to live in —
   * AssignmentStatus has no such case and afterReviewProgress would stall on one
   * forever — so it is not invented here.
   */
  const canReject = Boolean(user?.permissions.includes('application.reject'))

  // Opens as a record of the filing; Edit turns on the office's own fields.
  const [mode, setMode] = useState<ReviewMode>('view')

  const [popup, setPopup] = useState<'reject' | 'return' | null>(null)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [showVerification, setShowVerification] = useState(false)

  /*
   * The only two FOR OFFICE USE ONLY boxes that go anywhere: the assessment
   * (fee.adjust) and the remarks that ride along with Approve or Return. The
   * rest of that panel is read back from the record, so it is shown, not typed.
   */
  const [feeInput, setFeeInput] = useState<string | null>(null)
  const [remarks, setRemarks] = useState('')

  // Office-recorded issuance dates, keyed "PERMIT_CODE.field_key".
  const [issued, setIssued] = useState<Record<string, string>>({})
  const [issuedSavingCode, setIssuedSavingCode] = useState<string | null>(null)
  const [issuedNote, setIssuedNote] = useState<string | null>(null)

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

  /*
   * /queue/:id is an ASSIGNMENT id, but application ids are what officers have
   * in hand everywhere else (notification deep links, a pasted URL, a row that
   * has since been reassigned). Rather than dying on the raw binding error, ask
   * the queue whether this number is one of our applications and bounce to its
   * real assignment; only give up when nothing matches.
   *
   * This runs only after a 404, and it is deliberately the last thing tried: the
   * queue feed is the office's entire assignment history — 2.1 MB for an admin —
   * so a mistyped URL should not be quietly pulling that down. Once /assignments
   * takes an `application_id` filter this becomes one small request instead.
   */
  const [strayId, setStrayId] = useState<'checking' | 'unresolved' | null>(null)
  const missing = Boolean(error) && toApiError(error).status === 404

  useEffect(() => {
    if (!missing) {
      setStrayId(null)
      return
    }
    let cancelled = false
    setStrayId('checking')
    assignments
      .list()
      .then((queue) => {
        if (cancelled) return
        const match = queue.find((a) => a.application.id === assignmentId)
        if (match) navigate(`/staff/queue/${match.id}`, { replace: true })
        else setStrayId('unresolved')
      })
      .catch(() => {
        if (!cancelled) setStrayId('unresolved')
      })
    return () => {
      cancelled = true
    }
  }, [missing, assignmentId, navigate])

  const backLink = (
    <Link to="/staff/queue" className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-royal hover:underline">
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
  if (missing)
    return (
      <div>
        {backLink}
        <div className="rounded-lg bg-white px-5 py-6 shadow-card">
          <h1 className="text-base font-bold text-ink">
            {strayId === 'unresolved'
              ? 'This application is not in your queue'
              : 'Opening this application…'}
          </h1>
          <p className="mt-1.5 max-w-prose text-sm text-ink-secondary">
            {strayId === 'unresolved'
              ? 'The link points at a review that has been completed, reassigned, or removed. Open it again from Application Verification.'
              : 'Checking your queue for the matching review.'}
          </p>
          {strayId === 'unresolved' && (
            <Link
              to="/staff/queue"
              className="mt-4 inline-flex rounded-md bg-royal px-5 py-2 text-sm font-semibold text-white hover:bg-royal-hover"
            >
              Go to Application Verification
            </Link>
          )}
        </div>
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
  /*
   * A business can be removed from the register once its filings are decided,
   * and the API sends `business: null` for those — 375 of the assignments on
   * this system. The sheet is a record of what was filed and still has to open;
   * an empty ReviewBusiness leaves every field rendering its own "—" rather
   * than taking the page down. (`Application['business']` is typed
   * non-nullable, which is why the type checker never saw this.)
   */
  const rawBusiness = app.business as unknown as ReviewBusiness | null
  const businessRemoved = !rawBusiness
  const business: ReviewBusiness = rawBusiness ?? {}
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
  // A decided review is a record for good: there is nothing left to change.
  const editing = mode === 'edit' && !decided

  // Read back from the record, not typed here: the paper form still carries
  // these boxes, but the system already knows every one of them.
  const officeRecord = [
    { label: 'Date of Receipt', value: formatDate(app.submitted_at) },
    { label: 'Received by', value: data.officer?.name ?? '' },
    { label: 'Business Account No. (BAN)', value: business.ban ?? '' },
    { label: 'PSIC Code', value: business.lines?.[0]?.psic_code?.code ?? '' },
  ]
  const feeValue = feeInput ?? String(app.fee_assessment?.total_amount ?? '')

  /*
   * One group per permit type on this application that carries issuance dates.
   * Prefilled from whatever the office already recorded; edits override.
   */
  const issuedGroups = app.permit_types
    .filter((pt) => OFFICER_DATE_FIELDS[pt.code])
    .map((pt) => {
      const saved = officeForms.find((f) => f.permit_type_code === pt.code)?.form_data ?? {}
      return {
        code: pt.code,
        name: pt.name,
        fields: OFFICER_DATE_FIELDS[pt.code].map((field) => {
          const stored = saved[field.key]
          return {
            ...field,
            value: issued[`${pt.code}.${field.key}`] ?? (typeof stored === 'string' ? stored : ''),
          }
        }),
      }
    })

  async function saveIssuedDates(group: (typeof issuedGroups)[number]) {
    setIssuedSavingCode(group.code)
    setIssuedNote(null)
    setActionError(null)
    try {
      const payload = Object.fromEntries(group.fields.map((f) => [f.key, f.value || null]))
      await officeFormsApi.save(app.id, group.code, payload)
      setIssuedNote(`${group.name} issuance dates saved.`)
      reload()
    } catch (err) {
      setActionError(toApiError(err).message)
    } finally {
      setIssuedSavingCode(null)
    }
  }

  async function approve() {
    setBusy(true)
    setActionError(null)
    try {
      await assignments.approve(assignmentId, remarks.trim() || undefined)
      setShowVerification(true)
      reload()
    } catch (err) {
      setActionError(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  async function sendRemark(text: string) {
    /*
     * The composer disables Confirm on an empty box, but the guard is here as
     * well as there: both endpoints require the text, and a rejection or return
     * with no reason leaves the applicant a decision they cannot act on.
     */
    if (!text.trim()) {
      setActionError('Write the reason before sending this decision.')
      return
    }
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
    const amount = feeValue.trim()
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
          <div className="min-w-0">
            <h1 className="truncate text-2xl font-bold text-ink">
              {business.name ?? app.tracking_id}
            </h1>
            {businessRemoved && (
              <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                Business removed from the register
              </p>
            )}
          </div>
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
          <div className="flex flex-wrap items-center gap-3">
            <div className="flex items-center gap-2.5">
              <span
                id="review-mode-label"
                className="text-[11px] font-bold uppercase tracking-wide text-ink-muted"
              >
                Mode
              </span>
              <div role="group" aria-labelledby="review-mode-label">
                <FilterPills options={MODE_OPTIONS} value={mode} onChange={setMode} />
              </div>
            </div>
            {editing && (
              <>
                {canReject && (
                  <button
                    type="button"
                    onClick={() => setPopup('reject')}
                    disabled={busy}
                    className="rounded-md bg-s-red px-7 py-2.5 text-sm font-semibold text-white underline underline-offset-2 shadow-card hover:brightness-110 disabled:opacity-60"
                  >
                    Reject
                  </button>
                )}
                {/*
                 * Every office's own negative decision, in the header beside
                 * Approve rather than buried at the foot of the sheet. For the
                 * six offices that cannot reject, this IS their reject button —
                 * item 80's complaint was that the screen appeared to offer them
                 * no way to say no.
                 */}
                <button
                  type="button"
                  onClick={() => setPopup('return')}
                  disabled={busy}
                  /*
                   * Tinted fill with ink text, not `text-s-orange` on white:
                   * #f2a33c against white is about 2:1 and fails WCAG 2.1 AA at
                   * this size. The border carries the caution hue; the label
                   * stays readable.
                   */
                  className="rounded-md border-2 border-s-orange bg-s-orange-tint px-7 py-2.5 text-sm font-semibold text-ink underline underline-offset-2 shadow-card hover:brightness-95 disabled:opacity-60"
                >
                  Return with remarks
                </button>
                <button
                  type="button"
                  onClick={approve}
                  disabled={busy}
                  className="rounded-md bg-s-green px-7 py-2.5 text-sm font-semibold text-white underline underline-offset-2 shadow-card hover:brightness-110 disabled:opacity-60"
                >
                  Approve
                </button>
              </>
            )}
          </div>
        )}
      </div>

      {/* What each mode means, said plainly so nobody has to infer it (item 54). */}
      <p
        aria-live="polite"
        className="mb-4 flex items-start gap-2.5 rounded-lg bg-white px-4 py-3 text-sm text-ink-secondary shadow-card"
      >
        <span className={`mt-0.5 shrink-0 ${editing ? 'text-royal' : 'text-ink-muted'}`}>
          {editing ? <PencilIcon /> : <EyeIcon size={16} />}
        </span>
        <span>
          {decided
            ? 'This review is closed. The page is a record of the application and the decision made on it.'
            : editing
              ? canReject
                ? 'Edit mode. The applicant’s answers stay locked. Fill in the office fields at the bottom of the sheet, then approve, return with remarks, or reject. Rejecting ends the application for every office; returning sends it back for revision.'
                : /*
                   * Said plainly rather than left to be discovered as a missing
                   * button (item 80). Return IS this office's way of refusing,
                   * and the reviewer should know that before hunting for Reject.
                   */
                  'Edit mode. The applicant’s answers stay locked. Fill in the office fields at the bottom of the sheet, then approve or return with remarks. Returning is how your office refuses this filing — ending the application outright is the BPLO’s decision.'
              : 'View mode. Everything below is the application exactly as the applicant submitted it. Switch to Edit to fill in your office’s fields and record a decision.'}
        </span>
      </p>

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

          {/*
            * Amendment from: — checklist items 82/84.
            *
            * Amendment filings only, and unlettered on purpose: on the paper
            * BPLO form this block sits in the header beside the application
            * type, not among the lettered sections, and renumbering A–E for
            * one of three filing types would make the sheet stop matching its
            * paper counterpart for the other two.
            *
            * An officer cannot review an amendment without it. Before this
            * existed the sheet said "Application for Amendment" and then
            * showed the business exactly as a new filing does, leaving the
            * reviewer to work out what had changed by comparing it to the
            * register themselves.
            */}
          {app.application_type === 'amendment' && (
            <section className="mt-7 rounded-lg border border-royal/30 bg-royal-tint px-5 py-4">
              <h2 className="text-[15px] font-bold text-ink">Amendment From</h2>
              {app.amendments && app.amendments.summary.length > 0 ? (
                <ul className="mt-2 space-y-1">
                  {app.amendments.summary.map((kind) => (
                    <li key={kind} className="flex items-start gap-2 text-sm text-ink">
                      <span className="mt-0.5 font-bold text-royal" aria-hidden="true">
                        ✓
                      </span>
                      <span>{kind}</span>
                    </li>
                  ))}
                </ul>
              ) : (
                /*
                 * Filings made before the wizard asked the question. Saying so
                 * is the honest reading: the applicant did not decline to
                 * answer, they were never asked, and an officer who treats a
                 * blank as "nothing is being amended" would reject a filing
                 * for the system's omission.
                 */
                <p className="mt-2 text-sm text-ink-muted">
                  This filing predates the amendment question and does not record what is being
                  amended. Ask the applicant through Messages before deciding.
                </p>
              )}
            </section>
          )}

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
              ✎ For Office Use Only {editing ? '· You are editing this panel' : '· Read only'}
            </p>
            <p className="mt-1 text-xs text-ink-secondary">
              {decided
                ? 'What this office recorded during its review.'
                : editing
                  ? 'This panel is the only part of the sheet you can change, and each field saves with its own button.'
                  : 'Switch the mode at the top of the page to Edit to fill these in.'}
            </p>

            <p className="mt-4 text-[11px] font-bold uppercase tracking-wide text-amber-800">
              Taken from the record
            </p>
            <div className="mt-2 grid gap-4 sm:grid-cols-4">
              {officeRecord.map((entry) => (
                <OfficeReadout key={entry.label} label={entry.label} value={entry.value} />
              ))}
            </div>

            <p className="mt-5 text-[11px] font-bold uppercase tracking-wide text-amber-800">
              Yours to fill in
            </p>
            <div className="mt-2 grid gap-4 sm:grid-cols-2">
              {editing && canAdjustFee ? (
                <label className="block">
                  <FieldLabel>Assessed Fee (Php)</FieldLabel>
                  <input
                    className={`${officeInput} tnum`}
                    value={feeValue}
                    placeholder="0.00"
                    onChange={(e) => setFeeInput(e.target.value)}
                  />
                  <span className="mt-1.5 flex items-center gap-2">
                    <button
                      type="button"
                      onClick={saveAssessment}
                      disabled={feeSaving || !feeValue.trim()}
                      className="rounded-md bg-royal px-3 py-1 text-xs font-semibold text-white hover:bg-royal-hover disabled:opacity-60"
                    >
                      {feeSaving ? 'Saving…' : 'Save assessment'}
                    </button>
                    {feeNote && <span className="text-xs font-medium text-s-green">{feeNote}</span>}
                  </span>
                </label>
              ) : (
                <OfficeReadout
                  label="Assessed Fee (Php)"
                  value={feeValue ? formatMoney(feeValue) : ''}
                />
              )}
              {editing ? (
                <label className="block">
                  <FieldLabel>Evaluator Remarks</FieldLabel>
                  <input
                    className={officeInput}
                    value={remarks}
                    placeholder="Notes for this application"
                    onChange={(e) => setRemarks(e.target.value)}
                  />
                  <span className="mt-1.5 block text-xs text-ink-secondary">
                    Sent with the application when you approve or return it.
                  </span>
                </label>
              ) : (
                <OfficeReadout label="Evaluator Remarks" value={data.remarks ?? ''} />
              )}
            </div>

            {/* Issuance dates: recorded here, never asked of the applicant. */}
            {issuedGroups.map((group) => (
              <div key={group.code} className="mt-5 border-t border-officeuse-border pt-4">
                <p className="text-[11px] font-bold uppercase tracking-wide text-amber-800">
                  {group.name} · Issuance Dates
                </p>
                <p className="mt-1 text-xs text-ink-secondary">
                  {editing
                    ? 'Enter the dates the issuing office released these documents. Applicants are not asked for them.'
                    : 'The dates the issuing office released these documents.'}
                </p>
                <div className="mt-3 grid gap-4 sm:grid-cols-3 sm:items-end">
                  {group.fields.map((field) =>
                    editing ? (
                      <label key={field.key} className="block">
                        <FieldLabel>{field.label}</FieldLabel>
                        <input
                          type="date"
                          max={todayISO()}
                          className={officeInput}
                          value={field.value}
                          onChange={(e) =>
                            setIssued((v) => ({ ...v, [`${group.code}.${field.key}`]: e.target.value }))
                          }
                        />
                      </label>
                    ) : (
                      <OfficeReadout
                        key={field.key}
                        label={field.label}
                        value={field.value ? formatDate(field.value) : ''}
                      />
                    ),
                  )}
                  {editing && (
                    <span className="flex items-center gap-2">
                      <button
                        type="button"
                        onClick={() => saveIssuedDates(group)}
                        disabled={issuedSavingCode !== null}
                        className="rounded-md bg-royal px-3 py-2 text-xs font-semibold text-white hover:bg-royal-hover disabled:opacity-60"
                      >
                        {issuedSavingCode === group.code ? 'Saving…' : 'Save dates'}
                      </button>
                    </span>
                  )}
                </div>
              </div>
            ))}
            {issuedNote && <p className="mt-2 text-xs font-medium text-s-green">{issuedNote}</p>}
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

          {/* Assign officer (oic.assign) — v2. Editing only: it changes the file. */}
          {canAssign && editing && (
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

          {/*
           * "Return with remarks" used to live here, as a text link under the
           * office-use panel and nowhere else. It has moved into the header
           * beside Approve (item 80) rather than being repeated: two controls
           * firing the same decision from opposite ends of a 1,200-line sheet
           * is how it came to be missed in the first place.
           */}

          {/* Messages thread (v2) */}
          <MessagesPanel applicationId={app.id} />
        </div>

        {/* ── Floating remarks column (p56/p70) ── */}
        <aside className="sticky top-8 hidden w-72 shrink-0 space-y-4 lg:block" aria-label="Remarks">
          {popup && (
            <RemarkPopup
              action={popup}
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
            action={popup}
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
          onConfirm={() => navigate('/staff/queue')}
        >
          <p className="py-4 text-center text-base">Where would you like to go?</p>
        </ProtoModal>
      )}
    </div>
  )
}
