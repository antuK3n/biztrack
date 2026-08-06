import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  ArrowLeftIcon,
  CheckIcon,
  ChevronDownIcon,
  ClipboardIcon,
  EyeIcon,
} from '../../components/icons'
import { ApplicationProgress } from '../../components/ApplicationProgress'
import { DocumentActions } from '../../components/DocumentActions'
import { InspectionDecisionPanel } from '../../components/InspectionDecision'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { MessagesPanel } from '../../components/MessagesPanel'
import { TaxOrderBreakdown } from '../../components/TaxOrderBreakdown'
import { FieldLabel, FilterPills, PageTitle, ProtoModal, inputCls } from '../../components/ui/Proto'
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
 *
 * The applicant's filed sheet — sections A–E — is behind a disclosure and
 * starts CLOSED. That is the THIRD position this file has held on that sheet;
 * the reasoning is written out in full at the disclosure itself (search
 * `application-as-filed`). Read it before moving the sheet a fourth time.
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
    postal_code?: string | null
    telephone?: string | null
    website?: string | null
    barangay?: { name?: string } | null
  } | null
  /* BPLO items B6, A13-A15 and B8/B7. Optional throughout: every business filed
   * before the wizard asked these carries null, and a sole proprietorship
   * carries null for the president block by design. */
  economic_organization?: string | null
  economic_organization_others?: string | null
  president_officer_name?: string | null
  citizenship?: string | null
  capital_participation_filipino?: string | null
  has_tax_incentives?: boolean | null
  lines?: {
    id: number
    psic_code: { code: string; title: string } | null
    capitalization: string | null
    products_services?: string | null
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
  initialText,
  submitting,
  error,
  onCancel,
  onConfirm,
}: {
  action: 'reject' | 'return'
  officer: string
  /**
   * Evaluator Remarks, carried in rather than discarded (SEP-6).
   *
   * Seeded into the textarea as a starting point the officer can edit, never
   * sent on its own: the confirm button still reads what is in the box at the
   * moment it is pressed, and an empty box still refuses. Safe as a `useState`
   * initialiser because this component is mounted fresh each time `popup` is
   * set and unmounted when it clears, so there is no second render in which
   * the prop could go stale against typed text.
   */
  initialText: string
  submitting: boolean
  error: string | null
  onCancel: () => void
  onConfirm: (text: string) => void
}) {
  const [text, setText] = useState(initialText)
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

/**
 * ["a", "b", "c"] → "a, b and c". Used by the Edit-mode banner, which has to
 * NAME the controls it is talking about rather than gesture at "the office
 * fields" — for most offices that plural resolves to exactly one control.
 */
function listPhrase(items: string[]): string {
  if (items.length === 0) return ''
  if (items.length === 1) return items[0]
  return `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`
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
  /*
   * The male/female split, printed beside the total it divides (BPLO item B2 on
   * the new form, B3 on the renewal, and CENRO's own MALE/FEMALE box). `count`
   * keeps a declared zero — "0 female employees" is an answer, and `put` would
   * drop the string "0" as falsy if this were formatted any other way.
   */
  put('Employees (Male)', count(profile.male_employees))
  put('Employees (Female)', count(profile.female_employees))
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

  /*
   * Is the applicant's filed sheet open?
   *
   * Closed on arrival, every time, for every status that renders the sheet.
   * Not persisted and not remembered across reloads: the client's whole
   * complaint is about what greets an officer when the page opens, and a
   * sticky "last time you left it open" would reproduce that on the next
   * visit for the officer who opened it once.
   */
  const [sheetOpen, setSheetOpen] = useState(false)

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

  /*
   * Submitted per-office form answers, split by whose sheet each one is.
   *
   * ── Read what arrives; do not filter again here ───────────────────────────
   *
   * The server filters `office_forms` on the assignment payload down to the
   * sheets this reader may see — ApplicationResource applying the same rule
   * OfficeFormController::readableCode has always applied to
   * `/applications/{id}/office-forms`: the applicant sees all,
   * `application.view_any_office` (BPLO, admin) sees all, and every other
   * reviewer sees only the permit types its own department issues. Repeating
   * that test in the browser would be a second copy of a confidentiality rule
   * that can drift from the first, and the browser is the wrong place to
   * enforce one regardless. Everything below therefore only SORTS and GROUPS.
   * Whatever is absent is absent on purpose.
   *
   * ── So the array is short, and sometimes empty ────────────────────────────
   *
   * A sanitary officer on a seven-office filing receives ONE sheet, not seven.
   * BPLO's own BUSINESS permit type carries no office form at all, so BPLO
   * receives every sheet and none of them is its own — `ownOfficeForms` is
   * legitimately empty there. Nothing below may assume a one-to-one with
   * `app.permit_types`, which is the filing's list and is shared by every
   * office on it.
   */
  const ownOfficeForms = (app.office_forms ?? []).filter(
    (f) => f.department_code === data.department.code,
  )
  const otherOfficeForms = (app.office_forms ?? []).filter(
    (f) => f.department_code !== data.department.code,
  )
  /** Every sheet this reader holds, own office first. */
  const officeForms = [...ownOfficeForms, ...otherOfficeForms]
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

  /*
   * Does THIS OFFICE still owe a paperwork review on this filing?
   *
   * The one predicate this screen and the queue tabs both branch on, named
   * once so it cannot be spelled two different ways in two places. It is
   * `decided` read from the other end: this office's assignment is completed
   * (or the filing was rejected out from under it) versus pending, in_progress
   * or returned, which are the three AssignmentStatus cases that still want a
   * decision from the officer sitting here.
   *
   * Deliberately says nothing about the FILING's status. That is the whole
   * lesson of INS-1 below.
   */
  const owesReview = !decided

  /*
   * ── The For Inspection screen ─────────────────────────────────────────────
   *
   * A filing waiting on a site visit gets its own, much smaller page, and
   * returns before any of the review sheet below is built — but only for an
   * office that has nothing left to review on it.
   *
   * ── The premise this rested on, and why it is gone (INS-1) ────────────────
   *
   * This branch used to read `app.status === 'for_inspection'` and nothing
   * else, on the strength of a docblock asserting that a filing only REACHES
   * `for_inspection` because every review assignment completed, so `decided`
   * was always true by the time control got here. Commit 5da4daa made that
   * false: WorkflowService::afterReviewProgress now books the approving
   * office's visit and flips the filing to `for_inspection` on the FIRST
   * office's approval, leaving every other office's assignment `pending`.
   *
   * What followed was a deadlock, not a cosmetic slip. An office whose own
   * review was still pending landed on this page — a panel of somebody else's
   * visits, which `canAct` correctly refuses it — and so had no Approve and no
   * Return anywhere in the product. No approval means scheduleInspectionFor
   * never fires for that office, which means isFullyCleared never passes, which
   * means the seven permits are never issued. BIZ-2026-00958 sat with five
   * offices in exactly that state. The API never carried the block —
   * AssignmentController::approve has no status guard at all — which is why
   * every backend test passed straight over it.
   *
   * ── The rule now ──────────────────────────────────────────────────────────
   *
   * The review form appears if and only if THIS OFFICE still owes a review on
   * this filing. Both states live on one filing at the same time, and that is
   * the point rather than an edge case: on BIZ-2026-00958, BFP (completed) gets
   * the compact box and CHO (pending) gets its review form, on the same
   * `for_inspection` filing, in the same minute.
   *
   * That is also what keeps the client's two rejections honoured rather than
   * reverted — "why is the entire application form showing it should just be
   * like the other ones where its just a box", and, on a first pass that merely
   * folded the form behind a disclosure, "I can still see the application
   * details. Please remove this." Both were said from an office that HAD
   * finished its review, and that is precisely the seat that still gets the box
   * and nothing else. The form is not coming back for them; it is being
   * returned to the offices that were never allowed to do the work.
   *
   * The layout is updated-gui/82.png: page title, the business so the officer
   * can confirm they opened the right row, a centred serif "Application Status"
   * and the card. Messages stays because the mock's chat bubble has to mean
   * something — it is how an officer asks the owner about a finding, and
   * deleting the sheet must not delete that too.
   *
   * ── What would make this wrong later ──────────────────────────────────────
   *
   * Keying on `app.status` alone again, in either direction. And QueuePage's
   * tab partition must stay this same predicate: if the queue decides "this
   * office still owes a review" one way and this line decides it another, an
   * officer clicks a row under For Approval and lands on a screen with no
   * controls — which is the bug that was reported, restated.
   *
   * The `app.status` half of the test is read off the status rather than off
   * the presence of inspections: a visit can exist on a filing that has already
   * moved past inspection (a failed one stays on the record for good), and a
   * filing can sit in `for_inspection` before anything is scheduled.
   *
   * Every other status falls straight through to the sheet, unchanged.
   *
   * Safe as an early return: every hook on this component runs above the
   * `loading` guard, so nothing below here is a hook and no render path can
   * skip one.
   */
  if (app.status === 'for_inspection' && !owesReview) {
    return (
      <div>
        {backLink}
        <PageTitle>Business Permit</PageTitle>

        <div className="mx-auto max-w-3xl">
          <p className={`text-center text-xl font-bold ${businessRemoved ? 'italic text-ink-muted' : 'text-ink'}`}>
            {businessRemoved ? 'Business removed from the register' : business.name}
          </p>
          <p className="mt-1 text-center text-sm font-semibold uppercase tracking-wide text-ink-muted">
            {app.tracking_id}
          </p>

          <h2 className="display-serif mb-6 mt-4 text-center text-3xl text-ink">Application Status</h2>

          {/*
           * `reload`, not a local patch of the card. Recording the last
           * outstanding visit as passed issues the permit and moves
           * `app.status` off `for_inspection` altogether — at which point this
           * whole branch stops applying and the officer should be looking at
           * the approved filing, not at a stale card.
           */}
          {/*
           * `filingStatus` is not a formality. The panel needs the FILING's
           * status to decide whether a failed visit may be re-inspected — one
           * of the three conditions the API checks — and the copy of that
           * status nested inside each inspection on this payload does not carry
           * it (AssignmentController::show selects the stub without the
           * column). This screen has the real one, so it hands it over.
           */}
          <InspectionDecisionPanel
            inspections={app.inspections ?? []}
            filingStatus={app.status}
            onChanged={reload}
          />

          {/* The rail the client asked to keep: "but the progress thingy is cool". */}
          <div className="mt-6">
            <ApplicationProgress app={app} />
          </div>

          <MessagesPanel applicationId={app.id} />
        </div>
      </div>
    )
  }

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
   * One group per issuance-date-bearing sheet THIS READER ACTUALLY HOLDS.
   *
   * This used to read `app.permit_types` — the filing's permit types, which
   * every office on the filing shares — so a sanitary officer opening a filing
   * that happens to carry an occupancy permit was shown OBO's "Building Permit
   * Date Issued" and "FSEC Date Issued" inputs with a live Save dates button
   * (SEP-3). They could type a real date and press it, and the API answered
   * "This application is not yours." The filing WAS theirs; the sheet was not.
   * A dead end rather than an auth hole — the server holds — but a dead end
   * whose error message pointed at the wrong thing.
   *
   * Driving the panel off the office forms that ARRIVED puts it exactly where
   * the Save will be accepted, because the two are the same rule read from two
   * ends: `readableCode` decides both which sheets are serialised onto this
   * payload and whether the PUT is allowed. BPLO holds
   * `application.view_any_office`, so it keeps the panel on every sheet — that
   * is coordination, and the server agrees with it rather than 403ing.
   *
   * It also fixes SEP-3's other half in passing. Once the payload is filtered,
   * a foreign office reading `app.permit_types` would have found no saved sheet
   * to prefill from and been handed EMPTY date inputs over a live Save — worse
   * than before. There is now no group to render for them at all.
   *
   * `permit_type_name` is the sheet's own name; `app.permit_types` is consulted
   * only as a fallback, and only ever for a code this reader already holds.
   */
  const issuedGroups = officeForms
    .filter((form) => OFFICER_DATE_FIELDS[form.permit_type_code])
    .map((form) => {
      const saved = form.form_data ?? {}
      const code = form.permit_type_code
      return {
        code,
        name:
          form.permit_type_name ??
          app.permit_types.find((pt) => pt.code === code)?.name ??
          code,
        fields: OFFICER_DATE_FIELDS[code].map((field) => {
          const stored = saved[field.key]
          return {
            ...field,
            value: issued[`${code}.${field.key}`] ?? (typeof stored === 'string' ? stored : ''),
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

  /**
   * The text behind Reject AND Return — one function, two endpoints.
   *
   * `popup` is the only thing that decides which: 'reject' ends the whole
   * application (BPLO and admin only, `application.reject`), 'return' sends
   * this office's assignment back for revision. Read that before changing
   * either; a mistake here fires the strongest action in the system down the
   * path meant for the recoverable one.
   *
   * `text` is the composer's textarea, which is now SEEDED from the Evaluator
   * Remarks box rather than starting blank (SEP-6). It is still the composer's
   * text that is sent, not the box's — the officer sees it, can edit it, and
   * confirms it — so nothing is dispatched that was not on screen at the moment
   * the button was pressed.
   */
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

  /*
   * ── What Edit mode actually turns on, for THIS reader, on THIS filing ─────
   *
   * The banner used to say "fill in the office fields at the bottom of the
   * sheet", and the client asked what it meant (SEP-5). Three things were wrong
   * with it at once:
   *
   *  - the plural. For a sanitary officer on a filing with no occupancy permit
   *    the entire editable surface of Edit mode is ONE text input, Evaluator
   *    Remarks. "The office fields" promised a panel of work and delivered a
   *    single box.
   *  - the location instead of the name. "At the bottom of the sheet" is about
   *    1,200 lines below the banner with no anchor (SEP-7), so the instruction
   *    was a scavenger hunt.
   *  - it never said WHY the applicant's answers are locked, which is the
   *    question actually asked ("Can't I edit the form itself since I am on
   *    edit mode?"), nor what to do instead.
   *
   * So the list is built from the same gates the controls themselves are drawn
   * behind — one source, so a control that appears or disappears cannot leave
   * the banner describing a screen that is not there. Read against the JSX
   * below: Assessed Fee is `editing && canAdjustFee`, Evaluator Remarks is
   * `editing` alone, an issuance-date group exists per sheet in `issuedGroups`,
   * and Assign officer-in-charge is `canAssign && editing` with at least one
   * officer in the department to pick.
   *
   * Evaluator Remarks is unconditional because Edit mode always draws it. If
   * that ever stops being true, this list has to stop asserting it.
   */
  const liveFields: string[] = []
  if (canAdjustFee) liveFields.push('Assessed Fee')
  liveFields.push('Evaluator Remarks')
  for (const group of issuedGroups) liveFields.push(`the ${group.name} issuance dates`)
  if (canAssign && canListUsers && deptOfficers.length > 0) {
    liveFields.push('Assign officer-in-charge')
  }

  /*
   * Why the rest is locked, and what to do about it — the two sentences the
   * banner was missing.
   *
   * The reason is not arbitrary and is worth stating: the sheet is the
   * applicant's sworn declaration. They signed it (rendered further down) and
   * consented to it under RA 10173, and the API enforces the same split —
   * OfficeFormController lets the owner write the answers and the reviewing
   * officer write only the issuance dates, with `array_diff_key` /
   * `array_intersect_key` making it impossible for either to reach the other's
   * keys. The remedy is Return: the applicant fixes their own answer.
   */
  const lockedNote =
    'The applicant’s answers stay locked because the sheet is their signed declaration, consented to under RA 10173 — if one of them is wrong, return the filing and the applicant corrects it themselves.'

  /*
   * The one genuinely good sentence in the old copy, kept: an office's own
   * refusal is not the same act as ending the filing for everybody. Six of the
   * eight staff roles cannot reject at all, and they should learn that here
   * rather than by hunting for a button that was never drawn for them.
   */
  const decisionNote = canReject
    ? 'Rejecting ends the application for every office; returning sends it back to the applicant for revision.'
    : 'Returning is how your office refuses this filing — ending the application outright is the BPLO’s decision.'

  /*
   * Named, and no claim about WHERE beyond what is true: most of these live in
   * For Office Use Only, but Assign officer-in-charge is its own panel below
   * it. The anchor after this sentence is what answers "where", so the sentence
   * does not have to guess.
   */
  const fieldsNote =
    liveFields.length === 1
      ? `Edit mode. On this filing your office fills in one field — ${liveFields[0]} — and the decision buttons are at the top of the page.`
      : `Edit mode. On this filing your office fills in ${liveFields.length} fields — ${listPhrase(liveFields)} — and the decision buttons are at the top of the page.`

  const modeNote = decided
    ? 'This review is closed. The page is a record of the application and the decision made on it.'
    : editing
      ? `${fieldsNote} ${lockedNote} ${decisionNote}`
      : /*
         * This used to open "Everything below is the application exactly as
         * the applicant submitted it", which stopped being true when that
         * sheet went behind a closed disclosure — what is below now is this
         * office's clearance and the panel it fills in.
         */
        `View mode. Below are your office’s clearance and the panel it records into; the applicant’s filed sheet is folded away until you ask for it. Switch to Edit to fill in ${
          liveFields.length === 1 ? liveFields[0] : `your office’s ${liveFields.length} fields`
        } and record a decision.`

  /*
   * What is behind the disclosure, named rather than implied.
   *
   * A collapsed region labelled "Show more" is a mystery box: the officer who
   * needs the barangay, or the floor area, or the uploaded requirements has
   * nothing telling them that THIS is where those live, so they either never
   * open it or they open every collapsed thing on the page hunting. The
   * summary is the fix, and it is built from the payload rather than written
   * as a fixed sentence so it cannot describe a sheet that is not there.
   *
   * Counted where a count exists. "8 uploaded requirements" is a claim the
   * officer can check against Section C the moment it opens; "documents" is
   * not, and a filing with none of them would be described as having some.
   */
  const filedSheetParts = [
    app.application_type === 'amendment' ? 'what is being amended' : null,
    'business registration and address',
    'line of business',
    app.documents.length === 0
      ? 'no uploaded requirements'
      : app.documents.length === 1
        ? '1 uploaded requirement'
        : `${app.documents.length} uploaded requirements`,
    /*
     * Only when the payload actually carried somebody else's sheet. For a
     * clearance office the server now filters Section D down to nothing
     * (the `owner_birthday` fix), so promising "other offices' answers" to a
     * sanitary officer would advertise a section that opens empty — and read
     * as a leak to a client who has already reported one here.
     */
    otherOfficeForms.length > 0 ? 'the other offices’ form answers' : null,
    'the fee declaration',
    'the signed data-privacy consent',
  ].filter((part): part is string => part !== null)
  const filedSheetSummary = listPhrase(filedSheetParts)

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
          {modeNote}
          {/*
           * The way there, not a description of where it is (SEP-7).
           *
           * A plain in-page anchor rather than a scroll handler: it works
           * without JavaScript, it is in the tab order for free, and the
           * browser moves focus to the target as well as the viewport, which a
           * `scrollIntoView` call does not. The target carries `tabIndex={-1}`
           * so it can receive that focus.
           *
           * Inside the live region on purpose — an `aria-live` announcement
           * reads the region's text content, so keeping the link here keeps the
           * whole banner one announceable string rather than splitting it.
           */}
          {!decided && (
            <>
              {' '}
              <a
                href="#for-office-use"
                className="font-semibold text-royal underline underline-offset-2 hover:no-underline"
              >
                Go to For Office Use Only
              </a>
              .
            </>
          )}
        </span>
      </p>

      {actionError && (
        <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">{actionError}</p>
      )}

      {/*
       * Above the form, not below it. "Where is this in the process" is the
       * question the sheet is opened with — the form is what you read once you
       * have decided this is the filing you meant. It also puts the outstanding
       * offices in front of a reviewer before they approve, so the one holding
       * it up is visible rather than discovered afterwards.
       */}
      <ApplicationProgress app={app} />

      <div className="flex items-start gap-6">
        {/* ── The form sheet ── */}
        <div className="min-w-0 flex-1 rounded-sm bg-white px-7 py-8 shadow-card sm:px-10">
          {/*
           * ── Whose review this is, at the top of the sheet (SEP-4) ─────────
           *
           * This read "Business Permit & Licensing Office · Admin Review" for
           * every one of the seven offices, so a sanitary officer opened a
           * sheet announcing itself as somebody else's, lettered A–E after
           * somebody else's paper form, with their own four questions in
           * Section D. That is most of why the client believed there was a leak
           * on parts of this page where there is none: "I should only see the
           * SANITARY PERMIT".
           *
           * The office name is the reader's own now. The BPLO form identity is
           * not deleted, because it is TRUE and it is what the applicant
           * actually filled in — it moves down to the form-reference line where
           * it belongs, phrased as the source of the record rather than as the
           * owner of the screen.
           */}
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-royal">
                {data.department.name} · Application Review
              </p>
              <h2 className="mt-1 text-xl font-bold text-ink">
                Application for {TYPE_TITLES[app.application_type] ?? app.application_type} Business Permit
              </h2>
              <p className="mt-1 text-xs text-ink-muted">
                Filed on the BPLO business permit form · Form Ref: MCG-BPLO-FO-001 · v2.0
              </p>
            </div>
            <p className="text-sm text-ink">
              <span className="font-bold">Application No.</span> <span className="tnum">{app.tracking_id}</span>
            </p>
          </div>
          <div className="mt-4 border-b-2 border-royal" />

          {/*
           * ── The reader's own clearance, before anyone else's paperwork ────
           *
           * The office's own questionnaire used to sit in Section D, after two
           * sections of BPLO registration data and one of documents — roughly
           * 1,200 lines of another office's form before the four answers that
           * ARE this officer's clearance. Sorting it first inside Section D was
           * the right instinct at the wrong altitude, so it is hoisted out
           * whole.
           *
           * Sections A, B, C and E stay exactly where they are and are NOT
           * hidden. They are the applicant's own particulars and every office
           * on the filing needs them: the address and barangay or the inspector
           * cannot find the premises, the PSIC line which is precisely what
           * CENRO reviews, the uploaded requirements, and the floor area CPDO's
           * fee is charged per square metre of. "SANITARY PERMIT ONLY" taken
           * literally deletes all of that; the workable reading is "lead with
           * my office, stop showing me other offices' files", which is this
           * block plus the server-side filter on `office_forms`.
           *
           * Absent for BPLO and admin, and correctly so: the BUSINESS permit
           * type carries no office form, so BPLO has no sheet of its own to
           * lead with and goes straight to the record it coordinates.
           */}
          {ownOfficeForms.map((form) => {
            const entries = Object.entries(form.form_data ?? {})
            return (
              <section
                key={form.permit_type_code}
                className="mt-6 rounded-lg border border-royal/30 bg-royal-tint px-5 py-4"
                aria-label={`Your office’s form — ${form.permit_type_name ?? form.permit_type_code}`}
              >
                <p className="text-[11px] font-bold uppercase tracking-wide text-royal">
                  Your office · {data.department.name}
                </p>
                <h2 className="mt-1 text-[15px] font-bold text-ink">
                  {form.permit_type_name ?? form.permit_type_code} — the clearance you are deciding
                </h2>
                {entries.length === 0 ? (
                  <p className="mt-3 text-sm text-ink-secondary">
                    The applicant recorded no answers on your office’s form.
                  </p>
                ) : (
                  <div className="mt-3 grid gap-4 sm:grid-cols-2">
                    {entries.map(([key, value]) => (
                      <Field key={key} label={humanizeKey(key)} value={formValueText(value)} />
                    ))}
                  </div>
                )}
                {/*
                  * Says where the rest went, now that it is folded away. The
                  * old wording — "the rest of this sheet is..." — described a
                  * sheet that ran on down the page, which stopped being true
                  * the moment the disclosure below went in.
                  */}
                <p className="mt-3 text-xs text-ink-muted">
                  The applicant’s own filing — address, line of business, uploaded requirements and
                  fee declaration — is folded away below, under{' '}
                  <span className="font-semibold">Show the application as filed</span>. Open it when
                  you need it to decide this clearance.
                </p>
              </section>
            )
          })}

          {/*
           * ── The applicant's filed sheet, closed on arrival ────────────────
           *
           * Everything from here to the signature block is the application AS
           * FILED: Amendment From, sections A–E, the consent note and the two
           * signatures. It is present, it is reachable in one click, and it is
           * not what greets the officer.
           *
           * ── This is the THIRD position on this sheet. Read all three ──────
           *
           * 1. It rendered flat, for every status, at full height. The client:
           *    "why is the entire application form showing it should just be
           *    like the other ones where its just a box (see others)".
           * 2. It was folded behind a disclosure. The client, from an office
           *    that had ALREADY finished its review: "In reviewing the
           *    inspections (admin side), I can still see the application
           *    details. Please remove this." So it was deleted outright.
           * 3. That deletion was keyed on the FILING's status rather than on
           *    the reading office's own assignment, and it deadlocked five
           *    offices on BIZ-2026-00958 — no Approve control anywhere in the
           *    product. The INS-1 block above re-keyed it on `owesReview`.
           *
           * Collapsed-by-default is what reconciles all three rather than
           * being a fourth swing at it, and the distinction that makes it work
           * is one the earlier passes did not draw:
           *
           *   - An office that has FINISHED its review never gets here at all.
           *     The INS-1 early return hands it the compact status box and
           *     returns before this sheet is built. That is the seat the
           *     client was sitting in for complaints 1 and 2, and it is
           *     untouched — "Please remove this" is still honoured literally
           *     for the only office that said it.
           *   - An office that still OWES a review gets the sheet, because
           *     without it there is no page to decide on. What complaint 1
           *     actually objected to was the sheet's PROMINENCE — "they dont
           *     need this form exactly" — not its existence, and a closed
           *     disclosure answers prominence exactly.
           *
           * ── Why it collapses on every status, not just when deciding ──────
           *
           * The complaint is about the sheet being the thing on screen, and it
           * is the thing on screen in every status that renders it — a closed
           * record reads the same as an open review from two feet away. Gating
           * the collapse on `owesReview` would also mean the sheet's shape
           * changed under an officer at the exact moment they approved, which
           * is the one moment they are least likely to want the page to move.
           *
           * ── What stays OUT of this region, deliberately ───────────────────
           *
           * The sheet header, the office's own clearance panel above, FOR
           * OFFICE USE ONLY (Assessed Fee, Evaluator Remarks, issuance dates),
           * Assign officer-in-charge, the Tax Order of Payment and Messages.
           * Those are why the officer is on this page; the decision buttons
           * are in the header. Nothing an officer has to TYPE or PRESS is
           * behind this button — only what they may need to READ.
           *
           * ── Implementation notes ──────────────────────────────────────────
           *
           * A <button> with aria-expanded/aria-controls rather than <details>.
           * <details> was the shape of pass 2 and a passing test asserts there
           * is none on this page; a button is also the only one of the two
           * whose open state React actually controls.
           *
           * `hidden` rather than unmounting. `aria-controls` has to point at
           * an element that exists, the region keeps its DOM order so the
           * "own office form leads the sheet" test still measures something
           * real, and `hidden` takes the content out of the accessibility tree
           * and out of find-in-page, so a closed sheet is genuinely closed and
           * not merely off-screen.
           *
           * NEVER `disabled` on this button. There is no state in which it
           * should be unreachable, and a disabled control drops out of the tab
           * order entirely.
           */}
          <div className="mt-7 border-t border-line pt-5">
            <button
              type="button"
              onClick={() => setSheetOpen((open) => !open)}
              aria-expanded={sheetOpen}
              aria-controls="application-as-filed"
              className="flex w-full items-start gap-3 rounded-lg border border-line bg-canvas px-4 py-3 text-left hover:border-royal/40 hover:bg-royal-tint focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
            >
              <span
                className={`mt-0.5 shrink-0 text-royal transition-transform ${sheetOpen ? 'rotate-180' : ''}`}
                aria-hidden="true"
              >
                <ChevronDownIcon size={18} />
              </span>
              <span className="min-w-0">
                {/*
                 * The accessible name says WHAT opens, not "Show more". A
                 * screen-reader user tabbing this page hears one control per
                 * section, and "Show more" is indistinguishable from every
                 * other one.
                 */}
                <span className="block text-sm font-bold text-ink">
                  {sheetOpen
                    ? 'Hide the application as filed'
                    : 'Show the application as filed'}
                </span>
                {/*
                 * Inside the button on purpose: it becomes part of the
                 * accessible name, so the summary is announced with the
                 * control rather than being visual-only detail beside it.
                 */}
                <span className="mt-0.5 block text-xs text-ink-secondary">
                  Sections A–E exactly as the applicant submitted them — {filedSheetSummary}. Nothing
                  in here is editable.
                </span>
              </span>
            </button>
          </div>

          <div id="application-as-filed" hidden={!sheetOpen}>

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
              {/*
                * Items A6 and A9. Both had columns and no input until the paper
                * forms were transcribed, so on filings made before that they
                * read "—" — which is the truth: nobody was asked.
                */}
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Telephone (Landline)" value={business.address?.telephone ?? ''} />
                <Field label="Website Address" value={business.address?.website ?? ''} />
              </div>
              {/*
                * Items A13-A15. Rendered for every filing, blank for a sole
                * proprietorship — where the wizard does not ask, because the
                * proprietor IS the officer in charge and is already named as the
                * applicant. An officer reading a blank here should read it as
                * "not applicable to this structure", which is why the three sit
                * together under one sub-heading rather than scattered.
                */}
              <div className="grid gap-4 sm:grid-cols-3">
                <Field
                  label="President / Officer in Charge"
                  value={business.president_officer_name ?? ''}
                />
                <Field label="Citizenship" value={business.citizenship ?? ''} />
                <Field
                  label="Capital Participation (% Filipino)"
                  value={
                    business.capital_participation_filipino == null
                      ? ''
                      : `${business.capital_participation_filipino}%`
                  }
                />
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                {/* Item B6. */}
                <Field
                  label="Economic Organization"
                  value={
                    business.economic_organization
                      ? business.economic_organization === 'others'
                        ? `Others — ${business.economic_organization_others || 'unspecified'}`
                        : humanizeKey(business.economic_organization)
                      : ''
                  }
                />
                {/*
                  * Item B8 (new form) / B7 (renewal).
                  *
                  * KNOWN LIMIT, and it is worth stating rather than papering
                  * over: `businesses.has_tax_incentives` is `boolean default
                  * false` and NOT NULL, so a business registered before the
                  * wizard asked this question reads "No" here — not because the
                  * applicant declared no incentives, but because nobody put the
                  * question. Making the column nullable would not fix it either:
                  * the rows already on disk are `false`, and every row written
                  * from now on is a real answer. So there is nothing to migrate,
                  * only something to know. If an officer is about to act on a
                  * "No" from an older filing, ask through Messages — the same
                  * remedy the Amendment From block above prescribes for the same
                  * class of gap.
                  *
                  * The null branch is kept for the case the resource omits the
                  * field entirely (a business that has been removed from the
                  * register renders an empty ReviewBusiness).
                  */}
                <Field
                  label="Tax Incentives from a Government Entity"
                  value={
                    business.has_tax_incentives == null
                      ? ''
                      : business.has_tax_incentives
                        ? 'Yes — certificate required'
                        : 'No'
                  }
                />
              </div>
            </div>

            <SubHeading>Main Office Address</SubHeading>
            <div className="grid gap-4 sm:grid-cols-3">
              <Field label="House / Bldg No." value={address?.line2 || house} />
              <Field label="Street" value={street} className="sm:col-span-2" />
              <Field label="Barangay" value={address?.barangay?.name ?? ''} />
              <Field label="City / Municipality" value={address?.city ?? 'Malabon City'} />
              <Field label="Province" value={address?.province ?? 'Metro Manila'} />
              <Field label="Postal Code" value={address?.postal_code ?? ''} />
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
                    {/*
                      * Products / Services — the paper's own second column of
                      * this table, on both BPLO forms and on CENRO's CEC
                      * application. Kept inside the per-line row because that is
                      * where it belongs: the trade above names what this line
                      * IS, this names what it handles, and CENRO reviews the
                      * second. Spans the row so a long list of goods is readable
                      * rather than crushed into a third of the width.
                      */}
                    <Field
                      label="Products / Services"
                      value={line.products_services ?? ''}
                      className="sm:col-span-3"
                    />
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

          {/*
           * D — office-form answers other than the reader's own.
           *
           * Still lettered D so the sheet keeps matching the paper BPLO form it
           * is a rendering of; renumbering A–E to close a gap would make every
           * section reference in the office wrong.
           *
           * The reader's own sheet is NOT repeated here — it is the lead panel
           * above. What is left is whatever else the payload carried, and for
           * most readers that is now nothing at all: the server serialises only
           * the sheets they may read, so a sanitary officer sees an empty
           * Section D where they used to read CENRO's `owner_birthday` off
           * another office's file, eight sections above a notice about RA 10173.
           *
           * Empty is therefore the ordinary case, not a fault, and the copy has
           * to say which of the two it is — "the applicant did not fill any
           * forms" would be a flat untruth on a six-clearance filing. BPLO and
           * admin, who hold `application.view_any_office`, still get every
           * sheet here, which is the coordination they need.
           */}
          <section className="mt-9">
            <SectionHeading letter="D">Other Offices’ Form Answers</SectionHeading>
            {otherOfficeForms.length === 0 ? (
              <p className="rounded-lg border border-line px-4 py-5 text-center text-sm text-ink-muted">
                {ownOfficeForms.length > 0
                  ? 'Nothing here. Your office’s form is at the top of this sheet, and the other offices’ forms on this filing are theirs to read.'
                  : 'No per-office form on this application is yours to read.'}
              </p>
            ) : (
              otherOfficeForms.map((form, formIndex) => {
                const entries = Object.entries(form.form_data ?? {})
                return (
                  <div key={form.permit_type_code ?? formIndex}>
                    <div className={`mb-3 flex items-center gap-2 ${formIndex === 0 ? 'mt-1' : 'mt-6'}`}>
                      <span className="h-4 w-1 rounded-full bg-royal" aria-hidden="true" />
                      <h3 className="text-sm font-bold text-ink">
                        {form.permit_type_name ?? form.permit_type_code}
                      </h3>
                      {form.department_code && (
                        <span className="rounded-md bg-canvas px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
                          {form.department_code}
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

          {/* ── End of the applicant's filed sheet (#application-as-filed) ──── */}
          </div>

          {/*
           * FOR OFFICE USE ONLY (p72/p76).
           *
           * `id` + `tabIndex` are the destination of the Edit-mode banner's
           * anchor (SEP-7). `tabIndex={-1}` is what lets the browser move
           * FOCUS here and not merely the viewport — without it a keyboard user
           * following the link keeps their focus 1,200 lines up and tabs from
           * there. `scroll-mt-6` keeps the heading off the very top edge.
           */}
          <div
            id="for-office-use"
            tabIndex={-1}
            className="mt-8 scroll-mt-6 rounded-lg border border-officeuse-border bg-officeuse px-5 py-5 focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
          >
            <p className="text-[11px] font-bold uppercase tracking-wide text-amber-800">
              ✎ For Office Use Only {editing ? '· You are editing this panel' : '· Read only'}
            </p>
            {/*
             * This used to promise that "each field saves with its own button".
             * Evaluator Remarks has no button and never did (SEP-6), so for a
             * sanitary officer with no `fee.adjust` and no occupancy sheet the
             * sentence described a panel containing ZERO save buttons while
             * pointing at the only field in it.
             */}
            <p className="mt-1 text-xs text-ink-secondary">
              {decided
                ? 'What this office recorded during its review.'
                : editing
                  ? canAdjustFee || issuedGroups.length > 0
                    ? 'This panel is the only part of the sheet you can change. Evaluator Remarks travels with the decision you make at the top of the page; the other fields here each save with their own button.'
                    : 'This panel is the only part of the sheet you can change. Evaluator Remarks is the only field in it, and it travels with the decision you make at the top of the page.'
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
                  {/*
                   * SEP-6, decided rather than left ambiguous: the remark is
                   * CARRIED THROUGH, not dropped.
                   *
                   * It used to promise it was "sent with the application when
                   * you approve or return it", and only approve was true.
                   * Return and Reject both go through `sendRemark`, which sends
                   * the RemarkPopup's own textarea and never looked at this
                   * state at all — so an officer who typed "Water potability
                   * certificate is expired" here and pressed Return had it
                   * silently discarded and was then asked to write the reason
                   * again from scratch.
                   *
                   * Carrying it through beat withdrawing the field, because the
                   * withdrawal loses real work: this is where an officer writes
                   * the finding while reading the sheet, and the popup is
                   * opened afterwards from the header. Both endpoints take
                   * exactly one remarks slot, so the honest shape is to seed
                   * the popup from this box and let the officer edit it before
                   * confirming — nothing is sent behind their back, and nothing
                   * they typed is thrown away.
                   */}
                  <span className="mt-1.5 block text-xs text-ink-secondary">
                    Sent with the application when you approve. On Return or Reject it fills in the
                    reason box for you to check before it goes.
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
                      {/*
                       * Named after the sheet it saves. `issuedGroups` is one
                       * entry per issuance-date-bearing sheet the reader holds,
                       * so BPLO — which holds all of them — can have several of
                       * these on one filing, and a column of buttons all called
                       * "Save dates" is a list a screen-reader user cannot
                       * navigate. Same rule as the inspection cards and the
                       * document rows.
                       */}
                      <button
                        type="button"
                        aria-label={`Save the ${group.name} issuance dates`}
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
              initialText={remarks}
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

      {/*
       * Small-screen remark composer (the aside is hidden below lg).
       *
       * A second, independent instance rather than one moved by CSS, so it
       * carries its own textarea state — which is why `initialText` has to be
       * passed to both. Seeding only one of them would make the carried-through
       * remark (SEP-6) appear on a desktop and vanish on a phone.
       */}
      {popup && (
        <div className="fixed inset-x-4 bottom-6 z-40 lg:hidden">
          <RemarkPopup
            action={popup}
            officer={officerName}
            initialText={remarks}
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
