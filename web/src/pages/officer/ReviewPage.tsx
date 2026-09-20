import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  ArrowLeftIcon,
  CheckCircleFilledIcon,
  CheckIcon,
  ChevronDownIcon,
  ClipboardIcon,
  EyeIcon,
} from '../../components/icons'
import { ApplicationProgress } from '../../components/ApplicationProgress'
import { DocumentActions } from '../../components/DocumentActions'
import { InspectionDecisionPanel } from '../../components/InspectionDecision'
import { MapPicker } from '../../components/MapPicker'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { MessagesPanel } from '../../components/MessagesPanel'
import { TaxOrderBreakdown } from '../../components/TaxOrderBreakdown'
import { FieldLabel, FilterPills, PageTitle, ProtoModal, inputCls } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { formatBytes, formatDate, formatDateTime, formatMoney } from '../../lib/format'
import {
  admin,
  applications,
  assignments,
  officeForms as officeFormsApi,
  permits,
  reference,
} from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { useAuth } from '../../stores/auth'
import type {
  AdminUser,
  AppDocument,
  Application,
  FeeProfile,
  OfficeFormRequirement,
  Permit,
} from '../../lib/types'

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
  /*
   * ── Five facts the API already sent and this page never drew ─────────────
   *
   * `registration_type` (which of DTI/SEC/CDA the number belongs to), the
   * named owner, their gender, and the business's own mobile and e-mail. All
   * five are on BusinessResource and all five were absent from the sheet, so
   * an officer giving the initial approval could not see whose business it
   * was, or reach them, without leaving the page.
   */
  registration_type?: string | null
  owner?: {
    surname?: string | null
    given_name?: string | null
    middle_name?: string | null
    suffix?: string | null
    gender?: string | null
  } | null
  registration_number?: string | null
  tin?: string | null
  ban?: string | null
  /*
   * BPLO item B6. Served by BusinessResource and drawn nowhere: the sheet
   * showed the per-line "Capitalization" the wizard stopped asking for, and
   * the one figure the paper does ask for was missing.
   */
  capital_investment?: string | number | null
  address?: {
    line1?: string | null
    line2?: string | null
    /*
     * BPLO item 5's two boxes, which this page reads in preference to
     * splitting `line1` apart with a regex. Optional because filings made
     * before 16 September 2026 carry only the combined value — see the note
     * where they are rendered.
     */
    house_bldg_no?: string | null
    street?: string | null
    city?: string | null
    province?: string | null
    postal_code?: string | null
    telephone?: string | null
    website?: string | null
    /* BPLO items A7 and A8 — the BUSINESS's own, not the account holder's. */
    mobile_number?: string | null
    email?: string | null
    /* The pin CPDD rules the locational clearance from. */
    latitude?: number | null
    longitude?: number | null
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
  /*
   * The premises and the emergency contact — Section B on the paper.
   *
   * `BusinessResource` has sent all seven of these since it was written; this
   * type simply never declared them, so nothing on the sheet could print them
   * and the type checker agreed there was nothing to print. Every field is
   * optional for the same reason as the block above: a business filed before
   * the wizard asked carries null, and an owned premises carries null for the
   * whole lessor group by design.
   */
  is_rented?: boolean | null
  lessor_name?: string | null
  lessor_address?: string | null
  lessor_contact?: string | null
  monthly_rental?: string | null
  emergency_contact_name?: string | null
  emergency_contact_number?: string | null
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
const recordValue =
  'w-full rounded-lg border border-input-border bg-input px-3.5 py-2.5 text-sm text-ink'
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
      <path
        d="m9.5 10.5 2.4 2.4 4.6-4.6"
        stroke="#fff"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
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
    <svg
      width="18"
      height="18"
      viewBox="0 0 24 24"
      fill="none"
      className={className}
      aria-hidden="true"
    >
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
function Field({
  label,
  value,
  className = '',
}: {
  label: string
  value: string
  className?: string
}) {
  return (
    <dl className={`block ${className}`}>
      <dt className="mb-1.5 block text-[13px] font-semibold text-ink">{label}</dt>
      <dd className={recordValue}>{value || '—'}</dd>
    </dl>
  )
}

/**
 * The zoning sheet's CHECKLIST OF REQUIREMENTS, as the deciding office reads it.
 *
 * The applicant sees this list on their own sheet and CPDD is the office that
 * acts on it — a locational clearance is decided against a title deed, a tax
 * declaration and a sketch of the site, none of which the form answers carry. A
 * checklist visible to only one of the two seats would be half a feature, and
 * the same "two doors, two answers" shape this file has been repaired for
 * repeatedly, so `ApplicationResource` builds it from the same
 * `App\Support\ZoningRequirements` the applicant's screen reads.
 *
 * It names the files rather than offering them: every one of them is an
 * ordinary `ApplicationDocument` and is already listed, with its own view and
 * download controls, under Uploaded Requirements below. A second download path
 * to the same file is a second thing to keep working.
 *
 * Read-only, and not because of a permission — which rows apply is derived from
 * the filing, and what satisfies each is a file the applicant attached. The
 * office acts on this by approving or returning the clearance.
 */
function RequirementsRead({ code, rows }: { code?: string; rows: OfficeFormRequirement[] }) {
  const outstanding = rows.filter((r) => !r.satisfied).length

  return (
    <div className="mt-4 rounded-lg border border-line bg-white px-4 py-3">
      {/*
        The two papers that ask for documents call the box different things —
        CPDD's "CHECKLIST OF REQUIREMENTS" and CENRO's "REQUIREMENTS FOR
        APPLICATION" — and an officer reading their own form against the screen
        should see their own heading.
      */}
      <p className="text-[11px] font-bold uppercase tracking-wide text-ink-secondary">
        {code === 'CEC' ? 'Requirements for Application' : 'Checklist of Requirements'}
      </p>
      <p className="mt-1 text-xs text-ink-muted">
        {outstanding === 0
          ? 'Everything on this list is on the filing.'
          : `${outstanding} of ${rows.length} not on the filing. The files are under Uploaded Requirements below.`}
      </p>
      <ul className="mt-3 space-y-2">
        {rows.map((row) => (
          <li key={row.key} className="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
            <span
              aria-hidden
              className={`shrink-0 font-bold ${row.satisfied ? 'text-s-green' : 'text-ink-muted'}`}
            >
              {row.satisfied ? '✓' : '—'}
            </span>
            <span className="font-medium text-ink">{row.label}</span>
            {row.document !== null ? (
              <span className="break-all text-xs text-ink-secondary">{row.document.filename}</span>
            ) : row.reference ? (
              <span className="tnum text-xs text-ink-secondary">{row.reference}</span>
            ) : (
              <span className="text-xs text-ink-muted">
                {row.source === 'sheet' ? 'not submitted yet' : 'not on file'}
              </span>
            )}
          </li>
        ))}
      </ul>
    </div>
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
  targets,
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
  /**
   * The things this return could be ABOUT — the sheet's own checklist rows and
   * its answers, as { value, label } pairs.
   *
   * ── Why a list and not a parse of the prose ──────────────────────────────
   *
   * The client's worry, 17 September 2026: *"how can a free text match what is
   * specifically asked. There could be database matching issues for this."*
   * Right — so nothing matches the text. The officer writes whatever they want
   * AND optionally ticks the subject here, and the tick is a code the system
   * already owns. Highlighting the row on the applicant's sheet is then a key
   * lookup rather than a search, immune to synonyms, Filipino and typos.
   *
   * Empty on the reject composer and on sheets with nothing to point at, in
   * which case the control is not rendered at all.
   */
  targets: { value: string; label: string }[]
  submitting: boolean
  error: string | null
  onCancel: () => void
  onConfirm: (text: string, target: string | null) => void
}) {
  const [text, setText] = useState(initialText)
  const [target, setTarget] = useState('')
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
      {/*
        Above the box, because it is the smaller decision and answering it first
        makes the prose easier to write — "what is this about" then "what is
        wrong with it". Optional, and labelled so: a required picker would turn
        free text into a form, which is the opposite of what was asked for.
      */}
      {targets.length > 0 && (
        <label className="mt-3 block">
          <span className="text-xs font-bold text-ink">
            What is this about? <span className="font-normal text-ink-muted">(optional)</span>
          </span>
          <select
            value={target}
            onChange={(e) => setTarget(e.target.value)}
            className="mt-1.5 w-full rounded-lg border border-input-border bg-input px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
          >
            <option value="">Nothing in particular</option>
            {targets.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </select>
          <span className="mt-1 block text-xs text-ink-secondary">
            Picking one marks it on the applicant's sheet so they can see exactly what to fix. It
            does not stop them resubmitting.
          </span>
        </label>
      )}
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
          onClick={() => onConfirm(text.trim(), target === '' ? null : target)}
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
  /*
   * Item B6, and the label matters because there were two of these. The wizard
   * used to ask for capitalization PER LINE of business as well, and both fed
   * the same fee rules; the per-line question went on 16 September 2026
   * because the paper has one box. This is that box.
   */
  put('6. Capital Investment', money(profile.capitalization))
  put('Construction Cost', money(profile.construction_cost))
  put(
    '1. Business Area (sq. m.)',
    profile.floor_area_sqm == null ? null : `${profile.floor_area_sqm} sqm`,
  )
  put('2. Total Number of Employees', count(profile.employees))
  /*
   * The male/female split, printed beside the total it divides (BPLO item B2 on
   * the new form, B3 on the renewal, and CENRO's own MALE/FEMALE box). `count`
   * keeps a declared zero — "0 female employees" is an answer, and `put` would
   * drop the string "0" as falsy if this were formatted any other way.
   */
  put('2. Number of Male Employees', count(profile.male_employees))
  put('2. Number of Female Employees', count(profile.female_employees))
  /*
   * Item B3, and it was missing outright. The column has been filled since the
   * wizard started asking, and the figure is not decoration: the Revenue Code
   * reads it, and it is the one employee count an officer could plausibly
   * query against the barangay.
   */
  put('3. Number of Employees Residing in Malabon', count(profile.employees_in_lgu))
  put('Storeys', count(profile.storeys))
  put('Doors', count(profile.doors))
  put('Rooms', count(profile.rooms))
  put('Beds', count(profile.beds))
  put('Market Stalls', count(profile.stall_count))
  put('4. Motorized Delivery Units', count(profile.delivery_vehicles_motorized))
  put('4. Other Delivery Units', count(profile.delivery_vehicles_other))
  put(
    'Business Structure',
    profile.business_structure ? humanizeKey(profile.business_structure) : null,
  )
  put('Goods Class', profile.goods_class ? humanizeKey(profile.goods_class) : null)
  put('Office Location', profile.office_location ? LOCATION_LABELS[profile.office_location] : null)
  put(
    'Warehouse Location',
    profile.warehouse_location ? LOCATION_LABELS[profile.warehouse_location] : null,
  )
  put(
    'Factory Location',
    profile.factory_location ? LOCATION_LABELS[profile.factory_location] : null,
  )
  put('Property Use', profile.property_use ? humanizeKey(profile.property_use) : null)
  put('Occupancy Group', profile.occupancy_group ? profile.occupancy_group.toUpperCase() : null)
  return facts
}

/** "24 Mabini Street" → { house: "24", street: "Mabini Street" }. */
function splitLine1(line1: string | null | undefined): {
  house: string
  street: string
} {
  const raw = (line1 ?? '').trim()
  const match = raw.match(/^(\d+\S*)\s+(.+)$/)
  if (match) return { house: match[1], street: match[2] }
  return { house: '—', street: raw }
}

/**
 * ── Why the approval confirmation is up here and the sheet is a child ───────
 *
 * Approving sets a flag and calls `reload()`, and the reload is the whole
 * difficulty: it changes the filing out from under the sheet, and the sheet
 * legitimately returns early on some of what can come back. Two of those early
 * returns used to swallow the confirmation, because the modal was the last
 * thing in the sheet's own JSX:
 *
 *  - A clearance office's approval flips the filing to `for_inspection` AND
 *    completes that office's assignment — exactly the pair the For Inspection
 *    branch keys on — so the office's own success returned before the modal was
 *    ever reached. Its confirmation was unmounted by the thing it was
 *    confirming.
 *  - `reload()` also sets `loading` back to true, so the skeleton branch above
 *    that one dropped the modal for the length of the refetch, for EVERY office
 *    including BPLO.
 *
 * BPLO only ever looked correct because its approval leaves the filing at
 * `under_review`, so once the refetch settled it landed back on the one path
 * that still drew the modal. The dialog was never surviving the state change;
 * it was being re-created after it.
 *
 * So the confirmation does not live on a branch at all. It is a sibling of the
 * whole sheet, rendered from the one return statement that every branch inside
 * `ReviewSheet` sits beneath, and `showVerification` lives beside it because
 * state is no use on a component whose render is what drops the dialog.
 *
 * This is deliberately NOT "have the For Inspection branch render it too". Two
 * copies of one dialog are two things to keep in step, and the next early
 * return added to that sheet would silently be a third place that forgets it —
 * which is precisely how this bug was written the first time. One JSX site
 * cannot drift from itself, and no `return` inside a child can escape a
 * sibling. Nothing here touches the branch conditions, so the INS-1 rule below
 * — that branch keys on whether THIS OFFICE still owes a review, never on the
 * filing's status alone — is untouched.
 */
export function ReviewPage() {
  const navigate = useNavigate()
  const [showVerification, setShowVerification] = useState(false)

  return (
    <>
      <ReviewSheet onApproved={() => setShowVerification(true)} />

      {showVerification && (
        <ProtoModal
          title="VERIFICATION"
          cancelLabel="Home Page"
          confirmLabel="Tracking Page"
          onCancel={() => navigate('/dashboard')}
          onConfirm={() => navigate('/staff/queue')}
        >
          {/*
           * The dialog is the only word an officer gets that the decision
           * landed, so it says that before it asks anything. It used to open on
           * "Where would you like to go?" alone — a question about navigation,
           * not a confirmation — while behind it the screen had usually just
           * changed shape under them.
           *
           * ProtoModal supplies the rest of what a dialog owes a screen reader:
           * role="dialog", aria-modal, an accessible name taken from the title,
           * and a focus move onto the first footer button (useDialogKeyboard).
           * This copy is what is read out after that name.
           */}
          <p className="text-center text-base font-semibold text-ink">
            Approval recorded. This application has moved on from your office.
          </p>
          <p className="py-4 text-center text-base">Where would you like to go?</p>
        </ProtoModal>
      )}
    </>
  )
}

/**
 * The review sheet itself. Every early return in this function is beneath the
 * confirmation dialog rendered by `ReviewPage` above; keep it that way.
 *
 * `onApproved` is called once the API has accepted the approval, before the
 * reload that will change this component's render out from under it.
 */
function ReviewSheet({ onApproved }: { onApproved: () => void }) {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const assignmentId = Number(id)
  const { data, loading, error, reload } = useAsync(
    () => assignments.get(assignmentId),
    [assignmentId],
  )
  /*
   * ── The business permit's requirement list, for order and numbering ──────
   *
   * Fetched rather than inferred, and fetched from the same endpoint the
   * wizard reads. `documentTypes()` orders by `display_order`, so the array
   * order IS the order the applicant saw — which makes "the fourth
   * requirement" mean one thing across both screens and on the phone between
   * them.
   *
   * It also answers a second question the client asked on 16 September 2026:
   * the sheet was listing uploads against document types that had been taken
   * OFF the requirement list — Lease Contract or Land Title, Barangay
   * Business Clearance, the Cedula, a standalone Valid Government ID, the
   * Occupancy Permit — as though they were still being asked for. A code
   * absent from this list is a file the applicant really did send, against a
   * requirement that no longer exists, and the two are not the same thing.
   */
  const permitTypesRef = useAsync(() => reference.permitTypes(), [])

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

  /**
   * Is the applicant's filed application folded away, and for whom?
   *
   * ── It went away for everyone, and should only have gone for BPLO ─────────
   *
   * The disclosure opened closed, "every time, for every status", and the
   * client had it removed on 16 September 2026 with the reason attached:
   * *"Since we are using BPLO admin, the application must always be shown."*
   * BPLO's review IS reading the application, so the one thing the reviewer
   * came to do was behind a press.
   *
   * That reason does not carry to the five clearance offices, and on
   * 17 September the client read the absence from the sanitary seat: *"Where is
   * the hide and show thingy that we did before, which will show or hide the
   * business permit application? If this is missing too for the other offices
   * (except BPLO and super admin), please put them too."*
   *
   * A sanitary officer came to decide ONE clearance. Their own sheet and the
   * panel they record into are the work; the business permit application under
   * it is context they may or may not need. For them the fold is the whole
   * point — and the note on their clearance panel has been promising it in
   * writing the entire time it did not exist, which is how it was found.
   *
   * Keyed on `application.view_any_office`, the same flag the queue keys every
   * seat difference on: BPLO and the super admin hold it, CHO, BFP, CPDO, OBO
   * and CENRO do not. So this is one condition for all five offices rather
   * than a list of departments to keep in step.
   */
  /*
   * The SEAT alone. Whether the application is actually folded is
   * `foldsApplication` below, which adds BPLO's final-approval stage — it
   * cannot be decided here because `app` is not in scope yet, and putting a
   * status test in a constant named for a seat is how the two get confused.
   */
  const foldsFiledSheet = !user?.permissions.includes('application.view_any_office')
  /*
   * Closed on arrival, and only meaningful when it folds at all. BPLO's copy of
   * this page never reads it — `hidden` is gated on `foldsFiledSheet` — so a
   * stale `true` here could not leave BPLO's application hidden.
   */
  const [sheetOpen, setSheetOpen] = useState(false)
  /**
   * The second disclosure, for the Tax Order of Payment. Office seats only.
   *
   * Its own state and not `sheetOpen`: the client asked for *"2 hide/show bars
   * stacked"*, and two bars driven by one boolean would be one bar wearing two
   * labels — opening the application would silently open the assessment under
   * it. They are separate questions. An officer checking a fee does not want
   * 1,200 lines of registration data first, and vice versa.
   */
  const [taxOpen, setTaxOpen] = useState(false)
  const [permitPdfBusy, setPermitPdfBusy] = useState<number | null>(null)
  const [permitPdfError, setPermitPdfError] = useState<string | null>(null)

  const [popup, setPopup] = useState<'reject' | 'return' | null>(null)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  /*
   * The only two FOR OFFICE USE ONLY boxes that go anywhere: the assessment
   * (fee.adjust) and the remarks that ride along with Approve or Return. The
   * rest of that panel is read back from the record, so it is shown, not typed.
   */
  const [feeInput, setFeeInput] = useState<string | null>(null)
  const [remarks, setRemarks] = useState('')

  /*
   * The RA 11032 processing category, while the officer is choosing it.
   *
   * Null means "show whatever the record says" — the same shape as `feeInput`
   * above, and for the same reason: a reload has to be able to overtake a
   * stale local value, and a select seeded once from the payload would go on
   * showing the officer their old choice after the save that changed it.
   */
  const [tierInput, setTierInput] = useState<string | null>(null)
  const [tierSaving, setTierSaving] = useState(false)
  const [tierNote, setTierNote] = useState<string | null>(null)

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
    <Link
      to="/staff/queue"
      className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-royal hover:underline"
    >
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
  /**
   * The other offices' sheets — and only the ones actually filled in.
   *
   * ── The bug this `form_saved` check fixes ────────────────────────────────
   *
   * Reported 16 September 2026 against a filing at For Initial Approval:
   * section D showed CHO, BFP, OBO and CENRO "form answers" for clearances the
   * applicant had not applied for, let alone answered. Application Date
   * 2026-09-16, Application Type New, Workers Requiring Health Certs None.
   *
   * None of that was the applicant's. `office_forms` carries an entry for
   * every form-bearing permit type on the filing whether or not a sheet has
   * been saved, with the DERIVED answers filled in — deliberately, because a
   * routed office that opened its filing and saw only the BPLO form could not
   * tell a gap in the paperwork from a bug (the CENRO report of 9 September).
   * That fix shipped `form_saved` alongside it for exactly this reason: a
   * sheet of derived-only answers looks identical to one somebody filled in,
   * and the screen has to be able to say which.
   *
   * The reviewer's OWN sheet uses it already and says "Not filled in yet" —
   * see ownOfficeForms below. Section D never asked, so it presented seeds as
   * answers, and at For Initial Approval every one of them is a seed: the
   * clearance stage does not open until the first payment clears.
   *
   * An unfilled sheet belonging to ANOTHER office is not context, it is noise.
   * Its own reviewer needs to see it blank; BPLO reading the application does
   * not, and showing it invites a decision on answers nobody gave.
   */

  /**
   * The reader's OWN permit on this filing — the one their office issues.
   *
   * Derived from `ownOfficeForms`, which the SERVER filtered to the sheets this
   * reader may see, so the permit named here is the one the confidentiality
   * boundary says is theirs. Deriving it from the department instead would be a
   * second copy of that rule in the browser.
   *
   * `undefined` for BPLO and the super admin: they hold
   * `application.view_any_office`, receive every office's sheet and none of
   * them is their own (BPLO's BUSINESS permit type carries no office form at
   * all), so `ownOfficeForms` is empty and the progress rail stays the filing's.
   * That is the right rail for them — their review IS the filing.
   */
  const ownPermit = (app.permit_types ?? []).find(
    (pt) => pt.code === ownOfficeForms[0]?.permit_type_code,
  )

  /**
   * What a return on THIS sheet could be about.
   *
   * Two sources, both already on the page, and both keyed the way the applicant
   * side keys them:
   *
   *  - the office's own CHECKLIST rows, by `document_types.code` — the codes
   *    `ZoningRequirements` and `CecRequirements` mint and that
   *    `RequirementsChecklist` uploads into. Only the rows that take a file:
   *    pointing at a `carried` row would send the applicant to the business
   *    permit documents, which this return cannot reopen, and at the `sheet`
   *    row would mean "the form itself", which is what a return already means.
   *  - the office's own ANSWERS, by their form key, labelled with
   *    `humanizeKey` — the same words the read-only sheet prints above each
   *    value, so the officer picks what they are looking at.
   *
   * BPLO gets an empty list and no control. Its return reopens the whole
   * application rather than one office's sheet, so there is no row on the
   * applicant's clearance card to mark — see the note in `returnAssignment`
   * about the gap that leaves.
   */

  /** Hand over one clearance certificate as a PDF. */
  async function downloadCertificate(certificate: Permit) {
    setPermitPdfBusy(certificate.id)
    setPermitPdfError(null)
    try {
      await permits.pdf(certificate.id, `${certificate.permit_number}.pdf`)
    } catch (err) {
      /*
       * Said on screen rather than swallowed. This is a Bearer blob download,
       * so a failure is silent in the UI — no navigation, no broken tab — and
       * an officer who pressed Download and got nothing would press it again.
       */
      setPermitPdfError(toApiError(err).message)
    } finally {
      setPermitPdfBusy(null)
    }
  }

  /**
   * Open one clearance certificate in a tab — reading it, not filing it.
   *
   * Download alone was the wrong reduction. The client: *"Why only Download?
   * Should have View too."* Right: an officer about to issue a permit wants to
   * LOOK at the five certificates, and making them save five PDFs to a
   * downloads folder to do that is a filing cabinet where a window was needed.
   *
   * The reason it was Download-only is that `/permits/{id}/pdf` needs the
   * Bearer token, so an ordinary link or `window.open` on the URL answers 401.
   * `permits.view` fetches the blob and points a tab at it, which is what
   * documents, message attachments and payment receipts already do.
   *
   * The tab is opened BEFORE the await, on purpose: by the time the fetch
   * resolves the click gesture has expired and the popup blocker eats a
   * `window.open`. Closed again on failure, or the officer is left staring at
   * a blank window with the error on the page behind it.
   */
  async function viewCertificate(certificate: Permit) {
    const tab = window.open('', '_blank')
    setPermitPdfBusy(certificate.id)
    setPermitPdfError(null)
    try {
      await permits.view(certificate.id, tab)
    } catch (err) {
      tab?.close()
      setPermitPdfError(toApiError(err).message)
    } finally {
      setPermitPdfBusy(null)
    }
  }

  /**
   * Is this BPLO looking at its SECOND act — the one that issues the permit?
   *
   * ── Why final approval is a different screen from the first read ──────────
   *
   * BPLO approves twice and the two acts want different things in front of
   * them. The first is reading the form: the application IS the work, which is
   * why the client had the disclosure removed for BPLO — *"Since we are using
   * BPLO admin, the application must always be shown."*
   *
   * The second is issuing the Business Permit on the strength of five other
   * offices' certificates. The client, 17 September 2026: *"the only purpose of
   * Final Approval was to check if all clearance permits are done."* Nearly —
   * and the part that matters is that the check is already GUARANTEED:
   * `refreshReadiness` moves a filing into For Final Approval only when no
   * required clearance is outstanding and walks it back out if one stops being
   * approved, and `approveOverall` re-checks it before issuing anything. So
   * BPLO is never looking at a row where the five are not done, and a screen
   * built to verify that would be verifying something that cannot be false.
   *
   * What BPLO cannot do today is READ those certificates. `app.permits` was
   * never rendered on this sheet, though the payload has carried them all along
   * (visibility-filtered, so BPLO sees every one) and `/permits/{id}/pdf` has
   * existed since v2. The evidence BPLO signs against was the one thing the
   * signing screen did not show.
   *
   * So at this stage, and only at this stage, BPLO gets the clearances leading
   * and the application folded — the shape the offices already have, for the
   * same reason: it is context, not the work.
   */
  const bploFinalApproval = !foldsFiledSheet && app.status === 'for_final_approval'

  /**
   * May BPLO READ the clearance certificates on this sheet?
   *
   * Deliberately wider than `bploFinalApproval`, and separate from it, because
   * the two answer different questions. That flag drives the LAYOUT — clearances
   * leading, application folded, return targets aimed at permits — and belongs
   * to the moment BPLO is being asked to act.
   *
   * This one is about evidence, and evidence outlives the act. Since
   * 18 September 2026 a new filing never stands at For Final Approval: the fifth
   * clearance issues the Mayor's Permit outright. Gated on that status alone,
   * the certificates block — the thing built precisely because "the evidence
   * BPLO signs against was the one thing the signing screen did not show" —
   * disappeared from every new filing in the register, including the approved
   * ones an officer opens to audit exactly that evidence.
   *
   * So `approved` is included and the flags are kept apart. Widening
   * `bploFinalApproval` instead would have folded the application away and
   * re-aimed the Return control on finished filings, which is a layout decision
   * dressed up as a reading permission.
   */
  const bploReadsClearances =
    !foldsFiledSheet && (app.status === 'for_final_approval' || app.status === 'approved')

  /**
   * Is the applicant's filed application folded on THIS sheet?
   *
   * Two instructions, both honoured, neither overriding the other:
   *
   *  - An office's review folds it. Their work is one clearance; the business
   *    permit application is context.
   *  - BPLO's FIRST approval does not. *"Since we are using BPLO admin, the
   *    application must always be shown"* — reading the form IS that act.
   *  - BPLO's SECOND approval does. Issuing the permit rests on five
   *    certificates, not on re-reading a form BPLO already approved, and the
   *    client's own framing of the stage is that it is about the clearances.
   *
   * The Tax Order of Payment follows the same line, which is why it is one
   * constant: where the application is folded, the assessment is a second bar
   * beside it; where it is open, the assessment sits in FOR OFFICE USE ONLY
   * where the paper puts it.
   */
  const foldsApplication = foldsFiledSheet || bploFinalApproval

  /**
   * The clearances this permit rests on, as rows the officer can act on.
   *
   * Assembled from three places because no one of them has the whole story:
   *
   *  - `permit_types` — the pivot, which is the authoritative "is it
   *    approved" and carries `decided_at`.
   *  - `permits` — the issued certificate, which is what View and Download
   *    open. A clearance can be approved with no certificate row on an old
   *    filing, so the buttons are conditional rather than assumed.
   *  - `inspections` — the visit's result, matched by department, which is
   *    the other half of what an office's approval means on a permit that
   *    requires one.
   *
   * ── Whatever the filing carries, not "the five" ───────────────────────────
   *
   * `isRequiredClearance` is the server's own predicate and it is not always
   * five rows: a renewal carries exactly the permits the applicant ticked — a
   * shop renewing its Sanitary Permit alone carries one — and
   * `approveOverall`'s own note says such a filing may have no business-permit
   * row to issue at all. So the block counts what is there and is titled from
   * the data. Hardcoding "5 clearance permits" would misdescribe every renewal.
   *
   * The outcome permit is excluded: the Business Permit is what this approval
   * ISSUES, not something it rests on, and listing it as evidence for itself
   * would be circular.
   */
  /* Permit code → issuing office code, from the reference list already loaded. */
  const officeOf = new Map((permitTypesRef.data ?? []).map((t) => [t.code, t.department?.code]))

  const restsOn = (app.permit_types ?? [])
    .filter((pt) => pt.code !== 'BUSINESS' && pt.is_required)
    .map((pt) => ({
      permit: pt,
      certificate: (app.permits ?? []).find((c) => c.permit_type.code === pt.code) ?? null,
      /*
       * Matched through the permit type's ISSUING OFFICE, because an inspection
       * names its department and not the permit it is for. The mapping comes
       * from the reference list this page already loads.
       *
       * Highest id wins, mirroring Inspection::scopeCurrentPerDepartment: a
       * failed visit stays on the record and a re-inspection is a new row, so
       * the office's standing is the latest of them and not the first.
       */
      inspection:
        (app.inspections ?? [])
          .filter(
            (i) => i.department?.code !== undefined && i.department.code === officeOf.get(pt.code),
          )
          .sort((x, y) => y.id - x.id)[0] ?? null,
    }))

  /*
   * The clearances BPLO is RELYING on rather than reading off this filing.
   *
   * `restsOn` above can only see what the filing carries, and on a January
   * renewal of the business permit alone that is nothing: a clearance still in
   * date is not renewed, so it is not ticked and never attached. These come off
   * the business instead — see `ClearanceStanding` on the API side.
   *
   * Anything already on the filing is dropped, because `restsOn` shows it in
   * full. Null means the reader is not BPLO or the super admin, and gets
   * nothing here at all.
   */
  const carriedClearances = (app.clearance_standing ?? []).filter((row) => !row.on_this_filing)
  /*
   * Only `missing` and `expired` count. An `expiring` certificate is valid
   * today and the Business Permit will outlive it — that is worth a line, not
   * an alarm — and calling it a gap would put a warning on nearly every renewal
   * filed in the two months before a clearance comes round.
   */
  const carriedGaps = carriedClearances.filter(
    (row) => row.state === 'missing' || row.state === 'expired',
  ).length

  const returnTargets = [
    ...ownOfficeForms.flatMap((form) => [
      ...(form.requirements ?? [])
        .filter((row) => row.source === 'upload' && row.code !== null)
        .map((row) => ({ value: row.code as string, label: row.label })),
      ...Object.keys(form.form_data ?? {}).map((key) => ({
        value: key,
        label: humanizeKey(key),
      })),
    ]),
    /*
     * ── BPLO's targets at Final Approval are the CLEARANCES ─────────────────
     *
     * BPLO has no office form of its own, so `ownOfficeForms` is empty and the
     * dropdown above gives it nothing. At Final Approval what it is reading is
     * five uploaded certificates, and the client asked for the Return feature
     * to work there: *"This is subject to Return by the admin. Apply here what
     * you did with our Return feature in the new application part of our
     * system."*
     *
     * So the target carries a PERMIT TYPE CODE rather than a document code, and
     * `WorkflowService::returnAssignment` reads it to send back that one
     * clearance instead of the whole filing. Both kinds of pointer share one
     * column because both answer one question — which thing is this about — and
     * a second column would need every reader to know which to look in.
     *
     * Only on a renewal, and only at this stage. On a new filing the five
     * clearances are worked by their own offices, each of which can return its
     * own; BPLO pointing at one there would be reaching across a boundary
     * `ApplicationVisibility` exists to hold.
     */
    ...(bploFinalApproval && app.application_type === 'renewal'
      ? restsOn
          .filter(({ permit }) => permit.mode === 'upload')
          .map(({ permit }) => ({ value: permit.code, label: permit.name }))
      : []),
  ]

  /**
   * The itemized assessment, written once and placed in one of two positions.
   *
   * ── Why the position depends on the seat ──────────────────────────────────
   *
   * BPLO gets it inside FOR OFFICE USE ONLY, where the paper puts it and where
   * the office that RAISES the assessment expects to find it, beside the
   * assessed-fee box and the issuance dates it sits with on the form.
   *
   * A clearance office gets it as a second collapsible block directly under
   * the business permit application, on the client's instruction of
   * 17 September 2026: *"add a Hide or Show too for the Tax Order of Payment,
   * similar to the view of application form for business permit. Rearrange the
   * tax order of payment too, put it below the BP appl. form. This means 2
   * hide/show bars stacked."*
   *
   * That is the same reasoning as the application's own fold. The assessment
   * covers all six permits and is BPLO's to raise; a sanitary officer needs it
   * occasionally — to see what their clearance was charged — and never as the
   * first thing on the page. Two collapsed bars is the shape of "here is the
   * context, ask for it when you want it".
   *
   * One JSX value rather than two copies of the markup: the block has a total
   * row whose formatting has to match the breakdown above it, and two copies is
   * how the peso sign ends up on one of them.
   */
  const taxOrderBlock =
    (app.fee_assessment?.line_items?.length ?? 0) > 0 ? (
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
    ) : null

  const otherOfficeForms = (app.office_forms ?? []).filter(
    (f) => f.department_code !== data.department.code && f.form_saved === true,
  )

  /**
   * What is behind the disclosure, named rather than implied.
   *
   * A collapsed region labelled "Show more" is a mystery box: the officer who
   * needs the barangay, or the floor area, or the uploaded requirements has
   * nothing telling them that THIS is where those live, so they either never
   * open it or they open every collapsed thing on the page hunting.
   *
   * Built from the payload rather than written as a fixed sentence, so it
   * cannot describe a sheet that is not there. Counted where a count exists:
   * "8 uploaded requirements" is a claim the officer can check against Section
   * C the moment it opens, "documents" is not, and a filing with none of them
   * would otherwise be described as having some.
   *
   * ── Two entries are gone since this was first written ─────────────────────
   *
   * "The fee declaration" went with Section E, which the client removed on
   * 17 September 2026 — *"Why did you invent a section? This DOES NOT EXIST in
   * the application form itself."* A summary promising a section that no longer
   * renders would send an officer looking for it.
   *
   * "The other offices' form answers" stays conditional. The server filters
   * Section D down to nothing for a clearance office, so promising it to a
   * sanitary officer would advertise a section that opens empty — and read as
   * a leak to a client who has already reported one here.
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
    otherOfficeForms.length > 0 ? 'the other offices’ form answers' : null,
    'the signed data-privacy consent',
  ].filter((part): part is string => part !== null)
  const filedSheetSummary = listPhrase(filedSheetParts)
  /**
   * Every sheet this reader holds, own office first.
   *
   * Unfiltered on purpose: this feeds the reviewer's own-office block, which
   * SHOULD draw an unsaved sheet — blank, and labelled as such.
   */
  const officeForms = [
    ...ownOfficeForms,
    ...(app.office_forms ?? []).filter((f) => f.department_code !== data.department.code),
  ]
  /**
   * The uploads, split by whether the requirement still exists.
   *
   * `rank` is the position the applicant saw — the index within the business
   * permit's `document_types`, which the API orders by `display_order`. A
   * code that is not in that list is a file sent against a requirement since
   * removed; it stays visible, because the applicant really did send it and
   * hiding a submitted document would be the worse error, but it is shown
   * apart rather than numbered in among the live ones.
   *
   * While the reference request is in flight the map is empty, so everything
   * lands in `retired` for a moment. That is why the retired block says what
   * it is rather than asserting anything about the filing — and why the
   * numbered list simply appears once the order is known, instead of
   * renumbering under the reader.
   */
  const requirementRank = new Map<string, number>(
    (permitTypesRef.data ?? [])
      .find((t) => t.code === 'BUSINESS')
      ?.document_types?.map((dt, index) => [dt.code, index]) ?? [],
  )
  const askedFor = app.documents
    .filter((d) => requirementRank.has(d.document_type.code))
    .sort(
      (a, b) =>
        (requirementRank.get(a.document_type.code) ?? 0) -
        (requirementRank.get(b.document_type.code) ?? 0),
    )

  const feeProfile = app.fee_profile ?? null
  const feeFacts = feeProfile ? feeProfileFacts(feeProfile) : []
  const feeLines = feeProfile?.lines ?? []
  const feeFlags = feeProfile?.flags ?? []
  /*
   * `hasFeeDeclaration` went with the section it gated. There is no "Fee
   * Declaration" on MCG-BPLO-FO-001, so its contents are placed where the
   * paper puts them and each block asks whether it has anything of its own.
   */

  const rejected = app.status === 'rejected'
  const approvedHere = ['approved', 'completed'].includes(data.status.toLowerCase())
  /*
   * BPLO acts TWICE on one assignment row, and this read the first act as the
   * end of both.
   *
   * The flow gives BPLO the form before payment and the final signature after
   * every permit is approved. Both go through the same assignment, and
   * `completeAssignment` stamps `status = completed` and `completed_at` on the
   * first — so by the time a filing reached For Final Approval, every clause
   * below was already true. The Mode control was replaced by a static
   * "Approved" and no Approve button was drawn: the Final Approval tab served
   * an openable row leading to a screen that could not act on it, and no filing
   * could ever reach `approved`. The API was willing throughout —
   * `approveAssignment` maps `for_final_approval` onto `approveOverall`.
   *
   * So a filing standing at For Final Approval is never "decided", whatever the
   * row says. Keyed on the APPLICATION's status rather than the row, because
   * the row cannot tell BPLO's two acts apart — the same root cause as BPLO's
   * recorded turnaround covering the whole filing's lifetime. Giving the second
   * act its own assignment row would fix both at once, and is a larger change
   * than this screen.
   */
  const owesFinalApproval = app.status === 'for_final_approval'
  const decided = !owesFinalApproval && (rejected || approvedHere || Boolean(data.completed_at))
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
   * ── The RA 11032 processing category ──────────────────────────────────────
   *
   * The client: "In the average processing time, since it categorizes
   * applications into simple, complex, and highly technical, allow all office
   * admins to set the application category during their edit mode when trying
   * to approve the application." And then: "On the admin side, choosing the
   * Application category must be required. The admin must not approve the
   * application unless an Application category is chosen."
   *
   * ── Why this is computed HERE, above the For Inspection early return ───────
   *
   * Because both branches of this screen need it now. It used to live beside
   * the office-use panel, several hundred lines below the `for_inspection`
   * return, which was fine while the category was optional. It is not fine
   * once approveAndIssue() refuses an uncategorised filing: a filing whose
   * reviews are all in gets the compact inspection box and nothing else, so if
   * the picker only existed on the full sheet, an uncategorised filing in that
   * state would have no control anywhere in the product and no way to ever
   * issue. `const` bindings are in the temporal dead zone above their
   * declaration, so "needed by both branches" means "declared above both".
   *
   * ── What is and is not editable, which is the older half of this ──────────
   *
   * The DEADLINES are statute. RA 11032 gives an office three working days for
   * a simple transaction, seven for a complex one, twenty for a highly
   * technical one, and no LGU may grant itself a fourth tier or a longer count.
   * That is why the options come off the payload (`ra.tiers`) rather than being
   * written down in this file: the browser cannot offer what the API did not,
   * and the API reads `Ra11032::TIERS`, which is the law.
   *
   * WHICH TIER a given filing belongs to is not statute — the statute requires
   * the LGU to publish that in its Citizen's Charter, and Malabon has not told
   * us theirs (open question A10). Every tier in the register was therefore
   * assigned by a rule this project invented, which is precisely why the
   * provenance line below is not decoration: an officer has to be able to see
   * they are overruling a guess rather than filling in a blank.
   */
  const ra = app.ra11032 ?? null
  const tierOptions = ra?.tiers ?? []
  /*
   * The server's word on whether this filing may be reclassified at all, not
   * a second opinion computed here. A decided filing is refused by
   * WorkflowService::classify (`ApplicationStatus::isTerminal`), and a screen
   * that disagreed with it would draw a control the API answers 422 to.
   * `tierOptions.length` covers the other case — a payload from before this
   * field existed, where there is nothing to choose between.
   */
  const canSetTier = Boolean(ra?.editable) && tierOptions.length > 0
  const tierValue = tierInput ?? ra?.tier ?? ''

  /*
   * Saveable when the value CHANGED, or when nobody has claimed it yet.
   *
   * The second half is what keeps the approval gate from being a trap. The tier
   * arrives pre-filled with Ra11032::tierFor's guess, so an officer who reads
   * the filing and agrees with it has nothing to change — and with the old
   * `changed`-only rule the Save button stayed shut, the guess stayed
   * unclaimed, and Approve stayed refused. The only way out was to pick a tier
   * they believed was wrong, save, and pick the right one back.
   *
   * Agreeing is a decision, and this is where the officer records it. The
   * server takes the same view: WorkflowService::classify no longer treats an
   * unchanged tier as a no-op while complexity_set_by_user_id is null.
   */
  const tierUnclaimed = ra !== null && ra.source !== 'officer'
  const tierChanged = tierValue !== '' && (tierValue !== (ra?.tier ?? '') || tierUnclaimed)

  /**
   * The filing has no category, so it may not be approved — the client's rule,
   * mirrored from WorkflowService::requireProcessingCategory.
   *
   * `ra !== null` is doing real work and is not defensive noise. `ra11032` is
   * optional on the Application type precisely because a payload built before
   * that block existed does not carry it, and reading "no category" off a
   * payload that never mentions categories would shut Approve on every filing
   * with no control anywhere to reopen it. Absent means UNKNOWN, and unknown
   * defers to the server, which refuses for real. Present-and-null is the only
   * thing this screen is entitled to call missing.
   */
  /*
   * `source !== 'officer'`, not `tier === null`.
   *
   * A filing made through the product never has a null tier — submit() seeds a
   * guess — so keying on null meant this never shut and the server's refusal
   * was unreachable. What the rule is actually about is whether a person chose,
   * and `source` is the server's own word for that: null when the payload
   * predates the block, 'automatic' when Ra11032::tierFor guessed, 'officer'
   * when somebody put their name to it.
   */
  const categoryMissing = ra !== null && ra.source !== 'officer'

  /**
   * Who set the tier this filing currently carries — the sentence that makes
   * the control safe to hand an officer.
   *
   * "Set automatically" is deliberately not phrased as a reassurance. It is
   * our own rule, unapproved by BPLO, and the officer reading this sheet is
   * usually better placed than it is.
   */
  const tierProvenance =
    ra?.source === 'officer'
      ? `Category set by ${ra.set_by?.name ?? 'a reviewing officer'}${
          ra.set_at ? ` on ${formatDate(ra.set_at)}` : ''
        }.`
      : ra?.source === 'automatic'
        ? 'Category assigned automatically from the filing type and the declared capital. No one has checked it against the Citizen’s Charter, so it cannot be approved until you confirm it — save the category below, whether or not you change it.'
        : 'This filing has not been categorised yet, so it has no RA 11032 deadline and cannot be approved until one is chosen.'

  async function saveTier() {
    // Guarded here as well as on the button, because the button is shut with
    // `aria-disabled` and an aria-disabled control is still clickable.
    if (!tierChanged || tierSaving || !canSetTier) return
    setTierSaving(true)
    setTierNote(null)
    setActionError(null)
    try {
      const updated = await assignments.classify(assignmentId, tierValue)
      const days = updated.ra11032?.statutory_working_days
      /*
       * The note states the DEADLINE, not just the category, because the
       * deadline is the thing that actually moved. It is recomputed from the
       * filing date rather than from today — RA 11032's clock runs from when
       * the applicant filed, not from when an office got round to categorising
       * — so reclassifying can leave a filing immediately overdue, and an
       * officer must not learn that from the queue tomorrow.
       */
      setTierNote(
        updated.deadline_at
          ? `Category saved${days ? ` at ${days} working days` : ''}. The RA 11032 deadline is now ${formatDate(updated.deadline_at)}, counted from the date this was filed.`
          : 'Category saved.',
      )
      // Local choice dropped so the reloaded record is what the select shows.
      setTierInput(null)
      reload()
    } catch (err) {
      setActionError(toApiError(err).message)
    } finally {
      setTierSaving(false)
    }
  }

  /**
   * The picker itself, in one place because two screens draw it.
   *
   * A plain function rather than a component: it closes over the same state
   * both call sites already share, and a nested component declaration would be
   * remounted on every render, which is how a `<select>` loses focus mid-choice.
   */
  function tierPicker() {
    return (
      <div className="grid gap-4 sm:grid-cols-[minmax(0,22rem)_auto] sm:items-end">
        <div className="block">
          {/*
           * `htmlFor` rather than a <label> WRAPPING the select, which
           * is how every other field on the office panel is written.
           *
           * The difference is not cosmetic for a select. An implicit
           * label's accessible name is computed from its whole
           * subtree, and the subtree includes the control — so a
           * wrapping label makes this field announce itself as
           * "Application category Complex — 7 working days", the
           * label and the current value run together, changing every
           * time the officer moves the select. Explicit association
           * leaves the name as the question and the value as the
           * answer, which is what a screen reader expects to read
           * back. (Same trap as the Evaluator Remarks label further
           * up, where the explanatory sentence lands in the name.)
           */}
          <label htmlFor="ra11032-tier">
            <FieldLabel required>Application category</FieldLabel>
          </label>
          <select
            id="ra11032-tier"
            className={officeInput}
            aria-describedby="ra11032-note"
            value={tierValue}
            onChange={(e) => setTierInput(e.target.value)}
          >
            {/*
             * Offered only while the filing genuinely has no
             * category. Once one is set there is no way back to
             * "uncategorised" — un-setting it would delete the
             * filing's deadline, and a filing with no RA 11032
             * clock is invisible to the compliance panel and, since
             * the client's rule, unapprovable.
             */}
            {!ra?.tier && <option value="">Not yet categorised</option>}
            {tierOptions.map((tier) => (
              <option key={tier.value} value={tier.value}>
                {tier.label} — {tier.statutory_working_days} working days
              </option>
            ))}
          </select>
        </div>
        <span className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={saveTier}
            aria-disabled={!tierChanged || tierSaving}
            aria-describedby={tierChanged ? undefined : 'ra11032-save-why'}
            className={`rounded-md px-3 py-2 text-xs font-semibold text-white ${
              tierChanged && !tierSaving ? 'bg-royal hover:bg-royal-hover' : 'bg-royal/50'
            }`}
          >
            {tierSaving ? 'Saving…' : 'Save category'}
          </button>
          {/*
           * It says why instead of vanishing. Same rule as the
           * renewal modal's Confirm and the inspection decision
           * buttons: a shut control that explains itself is the
           * only kind a keyboard user can make sense of.
           */}
          {!tierChanged && !tierSaving && (
            <span id="ra11032-save-why" className="text-xs text-ink-muted">
              {ra?.tier
                ? 'Pick a different category to save a change.'
                : 'Pick a category to save.'}
            </span>
          )}
        </span>
      </div>
    )
  }

  /*
   * ── May THIS office book the first visit on its own clearance? ────────────
   *
   * The step that had no screen. `approveClearance` moves a permit to
   * `for_inspection` and books nothing — the automatic scheduler was removed on
   * purpose, because "an automatic date is a promise made to the applicant by a
   * scheduler that does not know whether anyone is free" — so the office picks
   * the date in a second, separate act. Until this, no client called
   * `POST /applications/{id}/permits/{code}/inspection`, and a permit that
   * reached `for_inspection` stayed there: no visit, so nothing to pass, so the
   * filing never reached For Final Approval.
   *
   * ── The office boundary, taken from the payload rather than guessed ────────
   *
   * `data.clearance` is `AssignmentResource::clearanceRow()` — the permit this
   * office issues on this filing, matched on
   * `issuing_department_id === assignment.department_id`. That is the SAME
   * column `InspectionController::schedule` checks the caller against before it
   * answers 403, so the control is drawn exactly where the request will be
   * accepted and nowhere else. Null when this office issues no permit here, so
   * an office reading the filing without owning a clearance gets no control.
   *
   * The three other candidates were all worse. `app.permit_types` is the
   * filing's list, shared by every office — driving off it is how a sanitary
   * officer was once handed OBO's date inputs over a live Save (SEP-3). Office
   * forms carry a `department_code`, but only for a permit the applicant APPLIED
   * for; hand in a copy you already hold and there is no sheet, while the permit
   * still needs its inspection. And a permit-type lookup by code would be this
   * rule written down a second time, in the browser, where it can drift.
   *
   * ── The other three conditions ────────────────────────────────────────────
   *
   *  - `requires_inspection`, or there is no visit to book: a desk-only permit
   *    is granted by `approveClearance` itself and never sits here. BPLO's
   *    Business Permit is the one in the register today.
   *  - `status === 'for_inspection'` — the pivot state
   *    `scheduleClearanceInspection` demands, and the only one it accepts.
   *  - this office has NO visit on the filing yet. Not "no OPEN visit": after a
   *    failure the permit STAYS at `for_inspection` (recordInspection keeps the
   *    failed row), and the way on from there is Schedule re-inspection on the
   *    failed card, which the panel already draws. Two controls booking the same
   *    office's next visit, one of them silently discarding the failure from
   *    view, is the confusion `reinspect` was separated from `reschedule` to
   *    avoid.
   *
   * A courtesy, not the control: the API is still what decides, and a mismatch
   * surfaces as the panel's error line rather than as an unauthorised write.
   */
  const myClearance = data.clearance
  const myVisits = (app.inspections ?? []).filter(
    (visit) => visit.department?.code === data.department.code,
  )
  const bookFirstInspection =
    myClearance &&
    myClearance.requires_inspection &&
    myClearance.status === 'for_inspection' &&
    myVisits.length === 0
      ? {
          applicationId: app.id,
          code: myClearance.code,
          permit: myClearance.name,
        }
      : undefined

  /*
   * ── The "nothing left for this office" screen ─────────────────────────────
   *
   * A filing still being worked gets its own, much smaller page, and returns
   * before any of the review sheet below is built — but only for an office that
   * has nothing left to do on it.
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
   * filing can sit at this stage before anything is scheduled.
   *
   * Every other status falls straight through to the sheet, unchanged.
   *
   * ── Re-keyed for the September flow (8 September 2026) ────────────────────
   *
   * This tested `app.status === 'for_inspection'`, and that status no longer
   * exists on an application — inspection belongs to one permit now. The branch
   * had therefore stopped firing altogether, silently: every office that had
   * finished its review was handed the whole application form back, which is
   * the exact thing the client twice asked to have removed.
   *
   * The stage it was describing is now `awaiting_other_permits`, and the shape
   * is unchanged underneath. `approveClearance` completes an office's
   * assignment at the moment it accepts the paperwork and leaves the permit at
   * `for_inspection`, so an office in the old "reviewed, now waiting on the
   * visit" seat reads exactly as it always did: `!owesReview`, on a filing that
   * has not finished.
   *
   * `for_final_approval` is included, because five offices sitting finished
   * while BPLO signs off are in the same position — with ONE exception, and it
   * is load-bearing. BPLO's own assignment was completed by `approveMainForm`
   * at the very start, so `owesReview` is false for BPLO here too, and BPLO's
   * Approve at this status is what calls `approveOverall()` and mints the
   * Mayor's Permit. Hand BPLO the compact box and that button is nowhere in the
   * product — the INS-1 deadlock above, rebuilt at the other end of the
   * process. The exception is keyed on the ASSIGNMENT's department, not on the
   * reader's permissions, because that is what `approveAssignment` itself
   * branches on.
   */
  const bploSignsOffHere = data.department.code === 'BPLO' && app.status === 'for_final_approval'
  const nothingLeftForThisOffice =
    (app.status === 'awaiting_other_permits' || app.status === 'for_final_approval') &&
    !owesReview &&
    !bploSignsOffHere

  if (nothingLeftForThisOffice) {
    return (
      <div>
        {backLink}
        <PageTitle>Business Permit</PageTitle>

        <div className="mx-auto max-w-3xl">
          <p
            className={`text-center text-xl font-bold ${businessRemoved ? 'italic text-ink-muted' : 'text-ink'}`}
          >
            {businessRemoved ? 'Business removed from the register' : business.name}
          </p>
          <p className="mt-1 text-center text-sm font-semibold uppercase tracking-wide text-ink-muted">
            {app.tracking_id}
          </p>

          <h2 className="display-serif mb-6 mt-4 text-center text-3xl text-ink">
            Application Status
          </h2>

          {/*
           * ── The one control this box carries beyond the visits ────────────
           *
           * Drawn only when the filing has no processing category, which on a
           * filing this far along means it predates the category being set at
           * submission. It is here because the alternative is a dead filing:
           * approveAndIssue() refuses an uncategorised application, so the last
           * inspector's Pass cannot release the permits, and this office has
           * already completed its review — the full sheet, and with it the
           * usual picker in For Office Use Only, is gone from this screen by
           * design ("I can still see the application details. Please remove
           * this"). No category, no control, no way out.
           *
           * Saving it here does not merely record a tier. WorkflowService::
           * classify() re-tests whether the filing is complete, so on a filing
           * whose reviews are all in and whose visits have all passed, this is
           * the press that issues the permits — which is why the copy says so
           * rather than letting an approval arrive unannounced.
           *
           * It disappears for good once set, and never appears at all on a
           * filing created since the gate.
           */}
          {categoryMissing && canSetTier && (
            <div className="mb-6 rounded-lg border-l-4 border-s-orange bg-s-orange-tint px-4 py-4">
              <p className="text-[11px] font-bold uppercase tracking-wide text-amber-800">
                RA 11032 · Processing Category
              </p>
              <p id="ra11032-note" className="mt-1 max-w-prose text-xs text-ink-secondary">
                This filing has never been categorised, so it has no RA 11032 deadline and cannot be
                approved. Any reviewing office may set it. The three categories and their day counts
                are fixed by RA 11032; the deadline is counted from the date the application was
                filed, not from today. If every review and inspection on this filing is already
                complete, saving a category is what issues the permits.
              </p>
              <div className="mt-3">{tierPicker()}</div>
              {tierNote && <p className="mt-2 text-xs font-medium text-s-green">{tierNote}</p>}
              {/*
               * saveTier() writes failures to `actionError`, and this branch
               * renders none of the review sheet that normally displays it —
               * without this line a refused save is completely silent on the
               * only screen from which this filing can be rescued.
               */}
              {actionError && <p className="mt-2 text-xs font-medium text-s-red">{actionError}</p>}
            </div>
          )}

          {/*
           * `reload`, not a local patch of the card. Recording the last
           * outstanding visit as passed issues this office's permit, and once
           * the last required permit lands `refreshReadiness` moves the filing
           * on — at which point this whole branch stops applying and the
           * officer should be looking at the filing as it now is, not at a
           * stale card.
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
            book={bookFirstInspection}
          />

          {/* The rail the client asked to keep: "but the progress thingy is cool". */}
          <div className="mt-6">
            <ApplicationProgress app={app} ownPermit={ownPermit} />
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
    { label: 'Business Account No.', value: business.ban ?? '' },
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
          form.permit_type_name ?? app.permit_types.find((pt) => pt.code === code)?.name ?? code,
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
    /*
     * The same refusal the API makes, made before the request rather than
     * after it. Guarded here as well as on the button because Approve is shut
     * with `aria-disabled`, and an aria-disabled control is still clickable —
     * the whole reason it is written that way is that a control removed from
     * the tab order takes the sentence explaining itself with it.
     *
     * Not an early `return` into silence: an officer who got here has already
     * pressed the button, so say why. `#for-office-use` is where the fix is
     * and the banner above the sheet links to it.
     */
    if (categoryMissing) {
      setActionError(
        'Choose this application’s processing category under For Office Use Only before approving it.',
      )

      return
    }
    setBusy(true)
    setActionError(null)
    try {
      await assignments.approve(assignmentId, remarks.trim() || undefined)
      /*
       * Raised before the reload, and deliberately not by this component: the
       * reload is what changes this screen out from under the officer, and the
       * confirmation has to outlive that. It is owned by ReviewPage, one level
       * up, so no early return below can take it down. See the note there.
       */
      onApproved()
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
  async function sendRemark(text: string, target: string | null = null) {
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
      /*
       * The pointer goes only with a RETURN. Rejecting is the whole filing —
       * `applications.reject` has no permit to hang a target on, and the
       * composer does not offer the control there either.
       */
      if (popup === 'reject') await applications.reject(app.id, text)
      else await assignments.return(assignmentId, text, target)
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
  /*
   * Named in the banner because it is the one field on this panel that changes
   * a STATUTORY deadline, and an officer who never scrolls to For Office Use
   * Only would otherwise never learn they were allowed to touch it. Gated on
   * the same `canSetTier` the control is drawn behind, so the banner cannot
   * promise a field that is not there — a decided filing has neither.
   */
  if (canSetTier) liveFields.push('the RA 11032 category')
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
        `View mode. Below are your office’s clearance and the panel it records into; the applicant’s filed sheet is below them. Switch to Edit to fill in ${
          liveFields.length === 1 ? liveFields[0] : `your office’s ${liveFields.length} fields`
        } and record a decision.`

  /*
   * ── A summary of the filed sheet used to live here ──────────────────────
   *
   * The filed application sat behind a disclosure, and a collapsed region
   * labelled "Show more" is a mystery box — the officer who needs the
   * barangay, or the floor area, or the uploaded requirements has nothing
   * telling them THIS is where those live. So the control carried a sentence
   * built from the payload: "business registration and address, line of
   * business, 8 uploaded requirements, the fee declaration and the signed
   * data-privacy consent".
   *
   * Both are gone. The disclosure went on 16 September 2026 — BPLO's review IS
   * reading the application, so hiding it behind a press made the reviewer's
   * one job a step — and a sentence listing what is directly beneath it is
   * just a second heading.
   *
   * One rule it enforced is worth keeping in words, because it came out of a
   * reported leak: the summary named the other offices' answers ONLY when the
   * payload actually carried somebody else's sheet, so a sanitary officer was
   * never promised a section that would open empty. That rule now lives where
   * it belongs — `otherOfficeForms` filters Section D itself, on department
   * and on `form_saved` — rather than in a caption describing it.
   */

  const existingRemarks = [
    ...app.assignments
      .filter((a) => a.remarks)
      .map((a) => ({
        key: `a-${a.id}`,
        author: a.officer?.name ?? a.department.name,
        remark: a.remarks as string,
      })),
    ...(app.rejection_reason
      ? [
          {
            key: 'rejection',
            author: officerName,
            remark: app.rejection_reason,
          },
        ]
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
                {/*
                 * ── Approve is shut until the filing has a category ────────
                 *
                 * The client: "The admin must not approve the application
                 * unless an Application category is chosen." The server refuses
                 * it for real (WorkflowService::requireProcessingCategory);
                 * this is so the officer learns it before pressing rather than
                 * from a red bar afterwards.
                 *
                 * `aria-disabled`, not `disabled`, and the distinction is the
                 * accessibility of the rule rather than a style choice. A
                 * `disabled` button leaves the tab order, so a keyboard or
                 * screen-reader user meets a control that is simply not there
                 * and no explanation of why — `aria-describedby` on a control
                 * nobody can reach announces nothing. Shut-but-focusable keeps
                 * the button, its state and its reason together, which is the
                 * same pattern as Save category below and the inspection
                 * decision buttons. `disabled={busy}` stays: an in-flight
                 * request is a transient the officer must not double-fire, not
                 * a rule they need read to them.
                 *
                 * Only Approve. Return with remarks and Reject are untouched on
                 * purpose — a filing that is being sent back or refused never
                 * enters a processing clock, so demanding a tier first would
                 * block an officer for a field nothing will ever measure.
                 */}
                <button
                  type="button"
                  onClick={approve}
                  disabled={busy}
                  aria-disabled={categoryMissing}
                  aria-describedby={categoryMissing ? 'approve-blocked-why' : undefined}
                  className={`rounded-md px-7 py-2.5 text-sm font-semibold text-white underline underline-offset-2 shadow-card disabled:opacity-60 ${
                    categoryMissing ? 'bg-s-green/50' : 'bg-s-green hover:brightness-110'
                  }`}
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

      {/*
       * Why Approve is shut, said in the page rather than only in the button's
       * accessible description.
       *
       * `id` is what the Approve button points `aria-describedby` at, so the
       * sentence is announced with the control for a screen reader and read
       * beside it by everyone else — one string, two audiences, no duplicate
       * copy to drift apart (WCAG 2.1 AA is the product's target and 3.3.2 is
       * the clause: identify what is required, not merely that something is
       * wrong).
       *
       * Rendered only when Approve is actually on screen and actually shut,
       * i.e. `editing`. In view mode there is no Approve to explain, and the
       * For Office Use panel is showing a read-only category readout the
       * officer cannot act on — pointing them at it would be a dead end.
       *
       * Amber, not red: nothing has failed. This is a precondition of an action
       * not yet taken, which is the same register as Return with remarks.
       */}
      {editing && categoryMissing && (
        <p
          id="approve-blocked-why"
          className="mb-4 rounded-lg border-l-4 border-s-orange bg-s-orange-tint px-4 py-3 text-sm text-ink"
        >
          This application has no processing category, so it cannot be approved yet. Choose Simple,
          Complex or Highly technical under{' '}
          <a
            href="#for-office-use"
            className="font-semibold text-royal underline underline-offset-2 hover:no-underline"
          >
            For Office Use Only
          </a>{' '}
          and save it — that is what sets the RA 11032 deadline this filing is measured against.
          Return with remarks and Reject do not need one.
        </p>
      )}

      {actionError && (
        <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
          {actionError}
        </p>
      )}

      {/*
       * Above the form, not below it. "Where is this in the process" is the
       * question the sheet is opened with — the form is what you read once you
       * have decided this is the filing you meant. It also puts the outstanding
       * offices in front of a reviewer before they approve, so the one holding
       * it up is visible rather than discovered afterwards.
       */}
      <ApplicationProgress app={app} ownPermit={ownPermit} />

      <div className="flex items-start gap-8">
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
                Application for {TYPE_TITLES[app.application_type] ?? app.application_type} Business
                Permit
              </h2>
              <p className="mt-1 text-xs text-ink-muted">
                Filed on the BPLO business permit form · Form Ref: MCG-BPLO-FO-001 · v2.0
              </p>
            </div>
            <p className="text-sm text-ink">
              <span className="font-bold">Application No.</span>{' '}
              <span className="tnum">{app.tracking_id}</span>
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
          {/*
            ── What this approval rests on, for the office that signs it ───────

            First on the sheet at For Final Approval, because it is the whole of
            what BPLO is relying on. See `restsOn` for how it is assembled and
            why it is not hardcoded to five rows.
          */}
          {bploReadsClearances && restsOn.length > 0 && (
            <section className="mt-7">
              <h2 className="text-[15px] font-bold uppercase tracking-wide text-ink">
                The clearances this permit rests on
              </h2>
              <p className="mt-1 text-xs leading-relaxed text-ink-muted">
                {/*
                  Counted, and stated as already-true rather than as something
                  to check: readiness is what produced this state, so every row
                  below is approved by construction. Telling an officer to
                  "verify all clearances are complete" would be asking them to
                  re-check a precondition.

                  Two tenses, because this block outlived the stage it was built
                  for. On a renewal BPLO is still about to sign, so the sentence
                  points forward. On an approved filing — which is now every new
                  application, the permit having been issued the moment the last
                  clearance landed — it is a record, and "before you issue"
                  would be instructing somebody to do a thing already done.
                */}
                All {restsOn.length} {restsOn.length === 1 ? 'clearance is' : 'clearances are'}{' '}
                approved.{' '}
                {app.status === 'approved'
                  ? 'The Business Permit was issued on the strength of them — open any to read what was granted.'
                  : 'Open any of them before you issue the Business Permit.'}
              </p>

              {permitPdfError !== null && (
                <p
                  role="alert"
                  className="mt-3 rounded-md border border-s-red bg-s-red-tint px-3 py-2 text-sm text-ink"
                >
                  {permitPdfError}
                </p>
              )}

              <ul className="mt-4 space-y-3">
                {restsOn.map(({ permit, certificate, inspection }) => (
                  <li
                    key={permit.code}
                    className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-line bg-white px-4 py-3"
                  >
                    <span className="shrink-0 text-s-green" aria-hidden="true">
                      <CheckCircleFilledIcon size={18} />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block text-sm font-bold text-ink">{permit.name}</span>
                      <span className="mt-0.5 block text-xs text-ink-secondary">
                        {/*
                          The office, the date it decided, and the visit's
                          result — the three facts that make the approval
                          checkable rather than merely asserted. Each is
                                                    omitted when absent rather than
                          printed as a dash: an old filing may have no
                          inspection row, and "Inspection: —" reads as a visit
                          that produced nothing.
                        */}
                        {officeOf.get(permit.code) ?? 'Issuing office'}
                        {permit.decided_at !== null &&
                          ` · approved ${formatDate(permit.decided_at)}`}
                        {inspection?.result_label != null &&
                          ` · inspection ${inspection.result_label.toLowerCase()}`}
                      </span>
                    </span>
                    {certificate !== null ? (
                      <span className="flex shrink-0 items-center gap-3">
                        <span className="tnum text-xs text-ink-muted">
                          {certificate.permit_number}
                        </span>
                        {/*
                          ── Both acts, and neither is a link ─────────────────

                          View opens the certificate in a tab; Download saves
                          it. They are genuinely different jobs — reading the
                          five before signing, versus keeping a copy — and the
                          reading one is the common case here.

                          Neither is an <a href>, because `/permits/{id}/pdf`
                          is Bearer-authenticated and a plain link answers 401.
                          An earlier pass had View as a <Link> to
                          `/staff/permits/{id}`, which is worse than a 401: no
                          such route exists. The permit detail page is
                          registered at `/permits/:id` inside the APPLICANT
                          shell, so the link 404'd, and pointing an officer into
                          the applicant shell would hand them citizen
                          navigation on a staff task. A staff-side permit page
                          is still worth having; it needs a route, a shell and a
                          decision about what an officer sees that an owner does
                          not, and it is not needed to read a PDF.
                        */}
                        <button
                          type="button"
                          onClick={() => void viewCertificate(certificate)}
                          disabled={permitPdfBusy === certificate.id}
                          aria-label={`View the ${permit.name} certificate`}
                          className="text-sm font-semibold text-royal underline underline-offset-2 hover:text-royal-hover disabled:opacity-60"
                        >
                          View
                        </button>
                        <button
                          type="button"
                          onClick={() => void downloadCertificate(certificate)}
                          disabled={permitPdfBusy === certificate.id}
                          aria-label={`Download the ${permit.name} certificate`}
                          className="text-sm font-semibold text-royal underline underline-offset-2 hover:text-royal-hover disabled:opacity-60"
                        >
                          {permitPdfBusy === certificate.id ? 'Preparing…' : 'Download'}
                        </button>
                      </span>
                    ) : (
                      /*
                       * Approved with no certificate row. Real on filings that
                       * predate certificate issuance, and worth saying plainly
                       * rather than showing two buttons that 404.
                       */
                      <span className="shrink-0 text-xs text-ink-muted">
                        Approved · no certificate on file
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            </section>
          )}

          {/*
            ── What this amendment asks to change ──────────────────────────────

            The whole of the work on an amendment, so it leads. BPLO is deciding
            one question — should the register say this instead — and the answer
            needs the two values side by side and the affidavit that asks for it.

            Drawn for any reader of the filing, not just BPLO: an amendment only
            ever reaches BPLO, so a permission gate here would guard a door
            nobody else can reach while making the block look optional.

            `requested_changes` is null on anything that is not an amendment,
            and an EMPTY array on an amendment asking for nothing — which is a
            filing to refuse, not approve, so it gets its own sentence rather
            than rendering as an absent block.
          */}
          {app.requested_changes !== null && (
            <section className="mt-7">
              <h2 className="text-[15px] font-bold uppercase tracking-wide text-ink">
                The changes this amendment asks for
              </h2>

              {app.requested_changes.length === 0 ? (
                <p className="mt-1 rounded-lg border border-s-red bg-s-red-tint px-4 py-3 text-sm text-ink">
                  This amendment names no change at all. There is nothing to
                  apply, so it should be returned rather than approved.
                </p>
              ) : (
                <>
                  <p className="mt-1 text-xs leading-relaxed text-ink-muted">
                    {app.status === 'approved'
                      ? 'Applied to the business record. What each detail replaced is kept beside it.'
                      : 'Approving this filing writes these values to the business record — you are not asked to retype anything. Read the affidavit and the supporting documents first.'}
                  </p>

                  <ul className="mt-4 space-y-3">
                    {app.requested_changes.map((row) => {
                      /*
                        Before → after, and WHICH before depends on when you are
                        reading. Until approval it is the register as it stands;
                        afterwards it is `old_value`, captured at the moment the
                        change was written. A business whose area was corrected
                        in between would otherwise show a "before" that was
                        already gone by the time the amendment landed.
                      */
                      const applied = row.applied_at !== null
                      const before = applied ? row.old_value : row.current_value

                      return (
                        <li
                          key={row.field}
                          className="rounded-lg border border-line bg-white px-4 py-3"
                        >
                          <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <span className="text-sm font-bold text-ink">{row.label}</span>
                            {applied && (
                              <span className="text-xs font-semibold text-s-green">
                                Applied {formatDate(row.applied_at as string)}
                              </span>
                            )}
                          </div>
                          {/*
                            The arrow is decoration; the two labelled values
                            carry the meaning on their own, so a reader who
                            cannot see it is not guessing at the direction.
                          */}
                          <div className="mt-1.5 flex flex-wrap items-baseline gap-x-3 gap-y-1 text-[13px]">
                            <span className="text-ink-secondary">
                              <span className="text-ink-muted">
                                {applied ? 'Was: ' : 'Now: '}
                              </span>
                              {before ?? <span className="italic text-ink-muted">not recorded</span>}
                            </span>
                            <span aria-hidden="true" className="text-ink-muted">
                              →
                            </span>
                            <span className="font-semibold text-ink">
                              <span className="font-normal text-ink-muted">
                                {applied ? 'Now: ' : 'Asked for: '}
                              </span>
                              {row.new_value ?? (
                                <span className="italic font-normal text-ink-muted">cleared</span>
                              )}
                            </span>
                          </div>
                        </li>
                      )
                    })}
                  </ul>
                </>
              )}
            </section>
          )}

          {/*
            ── The clearances this filing is NOT renewing ──────────────────────

            Client, 18 September 2026: *"an other permit can be reused for a
            business permit renewal as long as this other permit is still
            valid/not expired."* So on a January renewal the applicant ticks the
            Business Permit and nothing else, `restsOn` above is EMPTY, and the
            block that is supposed to show BPLO the five certificates showed a
            filing with nothing on it at all.

            These rows come off the BUSINESS instead (`clearance_standing`), so
            the check the client says this stage exists for can actually be made:
            every required clearance, the certificate held against it, and when
            it runs out.

            Warned, not blocked — §5 of docs/renewal-2026-09-17.md. A gap is
            drawn in red and Approve stays enabled, because the counter may have
            a reason to pass a filing whose FSIC lapsed last week, and an LGU
            that cannot do that here does it on paper instead.

            Rows already on the filing are skipped: `restsOn` covers those in
            full, with their inspection and decision dates, and showing a permit
            twice under two headings invites the reader to treat the thinner
            entry as the whole story.
          */}
          {bploReadsClearances && carriedClearances.length > 0 && (
            <section className="mt-7">
              <h2 className="text-[15px] font-bold uppercase tracking-wide text-ink">
                Clearances already on file
              </h2>
              <p className="mt-1 text-xs leading-relaxed text-ink-muted">
                Not part of this filing — these are certificates the business already holds and is
                not renewing.{' '}
                {carriedGaps > 0 ? (
                  <span className="font-semibold text-s-red">
                    {carriedGaps} {carriedGaps === 1 ? 'needs' : 'need'} your attention before you
                    issue the Business Permit.
                  </span>
                ) : (
                  'All of them are still in date.'
                )}
              </p>

              <ul className="mt-4 space-y-3">
                {carriedClearances.map((row) => {
                  /*
                    Word first, colour second — the same rule the applicant's
                    permit picker follows. A reader who cannot see the red still
                    gets "Expired 14 Aug 2026".
                  */
                  const note =
                    row.state === 'missing'
                      ? {
                          text: 'No certificate on record for this office',
                          cls: 'text-s-red',
                        }
                      : row.state === 'expired'
                        ? {
                            text: `Expired${row.valid_until ? ` ${formatDate(row.valid_until)}` : ''}`,
                            cls: 'text-s-red',
                          }
                        : row.state === 'unknown'
                          ? {
                              text: 'No expiry date on record',
                              cls: 'text-ink',
                            }
                          : row.state === 'expiring'
                            ? {
                                text: `Expires ${row.valid_until ? formatDate(row.valid_until) : 'soon'}${
                                  row.days_until_expiry !== null
                                    ? ` — ${row.days_until_expiry} ${row.days_until_expiry === 1 ? 'day' : 'days'} left`
                                    : ''
                                }`,
                                cls: 'text-ink',
                              }
                            : {
                                text: `Valid to ${row.valid_until ? formatDate(row.valid_until) : 'an unrecorded date'}`,
                                cls: 'text-ink-secondary',
                              }
                  const gap = row.state === 'missing' || row.state === 'expired'

                  return (
                    <li
                      key={row.permit_type_code}
                      className={`flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border px-4 py-3 ${
                        gap ? 'border-s-red bg-s-red-tint' : 'border-line bg-white'
                      }`}
                    >
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm font-bold text-ink">
                          {row.permit_type_name}
                        </span>
                        <span className={`mt-0.5 block text-xs ${note.cls}`}>
                          {row.department_code ?? 'Issuing office'} · {note.text}
                        </span>
                      </span>
                      {row.permit_number !== null && (
                        <span className="tnum shrink-0 text-xs text-ink-muted">
                          {row.permit_number}
                        </span>
                      )}
                    </li>
                  )
                })}
              </ul>
            </section>
          )}

          {ownOfficeForms.map((form) => {
            /*
             * `form_saved` is the server saying whether the applicant has
             * actually answered anything here.
             *
             * This block used to render only sheets that had been SAVED, so a
             * filing whose applicant had applied but not yet opened the form
             * showed nothing at all — the CENRO report of 9 September 2026,
             * "why do I only see the BPLO application form only". Every
             * form-bearing sheet on the filing now arrives whether or not it
             * has been filled in, which fixes the absence but creates a new way
             * to be misread: a sheet carrying only derived answers looks
             * identical to one the applicant completed. So it says which.
             */
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
                {form.form_saved === false && (
                  <p className="mt-3 rounded-md border border-s-orange bg-s-orange-tint px-3 py-2 text-sm leading-relaxed text-ink">
                    <span className="font-semibold">Not filled in yet.</span> The applicant has
                    applied for this clearance but has not saved any answers on your form. What is
                    below is what the system already knows about the filing.
                  </p>
                )}
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
                {form.requirements && form.requirements.length > 0 && (
                  <RequirementsRead code={form.permit_type_code} rows={form.requirements} />
                )}
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
          {/*
            ── Always open for BPLO; a disclosure for the five offices ──────

            This was a disclosure for everyone, and the file's own header warns
            that the sheet has moved three times and to read the reasoning
            before moving it a fourth. The fourth move removed the control
            outright, on the client's instruction of 16 September 2026, with
            the reason stated: BPLO's review IS reading the application, so the
            one thing the reviewer came to do was behind a button they had to
            press every time — and a control whose only sensible state is
            "open" is not a choice, it is a step.

            The fifth move is this one, and it does not undo the fourth. That
            reason is BPLO's and does not carry: a clearance officer came to
            decide ONE permit, and the business permit application under their
            sheet is context. On 17 September the client read its absence from
            the sanitary seat and asked for it back "for the other offices
            (except BPLO and super admin)". So the control exists exactly where
            its reason holds. See `foldsApplication`.

            The summary line comes back with it, for the same reason it was
            written: a collapsed region labelled "Show more" is a mystery box,
            and the officer who needs the barangay or the uploaded requirements
            has nothing telling them THIS is where those live. It was dropped
            when nothing was collapsed, because describing content already on
            screen is just a second heading — which is still why BPLO does not
            get it.
          */}
          {foldsApplication && (
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
                    The accessible name says WHAT opens, not "Show more". A
                    screen-reader user tabbing this page hears one control per
                    section, and "Show more" is indistinguishable from every
                    other one.
                  */}
                  <span className="block text-sm font-bold text-ink">
                    {sheetOpen
                      ? 'Hide the business permit application'
                      : 'Show the business permit application'}
                  </span>
                  {/*
                    Inside the button on purpose: it becomes part of the
                    accessible name, so the summary is announced with the
                    control rather than being visual-only detail beside it.
                  */}
                  <span className="mt-0.5 block text-xs text-ink-secondary">
                    The applicant’s own filing, exactly as submitted — {filedSheetSummary}. Nothing
                    in here is editable.
                  </span>
                </span>
              </button>
            </div>
          )}

          {/*
            `hidden` rather than unmounting, and gated so BPLO is never folded.

            `aria-controls` has to point at an element that exists; the region
            keeps its DOM order so the "own office form leads the sheet" test
            still measures something real; and `hidden` takes the content out
            of the accessibility tree and out of find-in-page, so a closed
            sheet is genuinely closed rather than merely off-screen.
          */}
          <div id="application-as-filed" hidden={foldsApplication && !sheetOpen}>
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
              <div className="space-y-5">
                {/*
                ── Ordered and worded as the applicant was asked ──────────────

                The sheet promises "sections A-E exactly as the applicant
                submitted them", and it was not keeping that promise: five
                facts the API already sends were never drawn — the type of
                registration, the named owner, their gender, and the business's
                own mobile and e-mail — so a reviewer could not see whose
                business this was or reach them without leaving the page.

                Three columns rather than two, and wider gaps. The sheet has
                the room now that the remarks column only takes space when it
                has remarks in it; before, it was squeezed into 760px of a
                1072px page with a permanently blank 288px beside it.
              */}
                {/*
                ── The paper's own item numbers, in the paper's own order ──────

                MCG-BPLO-FO-001 section A runs 1 to 16; thirteen of them are
                asked and two are deliberately not, so the numbering skips and
                the skips are the record of that:

                  5   Main Office Address  — asked on Location & Zoning
                  16  Residential Address  — not collected

                Reordered to match. A numbered list that does not ascend is
                worse than an unnumbered one — the reader stops trusting the
                numbers and starts reading every label instead, which is the
                work the numbers were there to save.
              */}
                <div className="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                  <Field
                    label="1. DTI / SEC / CDA Registration Number"
                    value={business.registration_number ?? ''}
                  />
                  <Field label="2. Tax Identification Number (TIN)" value={business.tin ?? ''} />
                  <Field label="3. Business Name" value={business.name ?? ''} />
                  <Field label="4. Trade Name / Franchise" value={business.trade_name ?? ''} />
                  {/*
                  Items 11 and 12 — the person the filing is in the name of,
                  assembled the way the wizard assembles it so the two read the
                  same. Blank parts drop out rather than leaving double spaces.
                */}
                  {/*
                  Items 6 to 9. All four had columns and no input until the
                  paper forms were transcribed, so on filings made before that
                  they read "—" — which is the truth: nobody was asked.
                */}
                  <Field
                    label="6. Telephone (Landline)"
                    value={business.address?.telephone ?? ''}
                  />
                  <Field label="7. Mobile Number" value={business.address?.mobile_number ?? ''} />
                  <Field label="8. E-mail Address" value={business.address?.email ?? ''} />
                  <Field label="9. Website Address" value={business.address?.website ?? ''} />
                  {/*
                  Item 10 is "Form of Organization" on the paper, offering
                  exactly these four. The wizard has always called the question
                  "Type of Registration" and still does; only the number is
                  added here, because renaming a question the applicant answers
                  is a separate decision from numbering it.
                */}
                  <Field
                    label="10. Type of Registration"
                    value={
                      business.registration_type ? humanizeKey(business.registration_type) : ''
                    }
                  />
                  {/*
                  Items 11 / 12 — one question either way. The paper routes a
                  sole proprietor to 11 and a corporation, partnership or
                  cooperative to 12, and prints Surname, Given Name, Middle
                  Name, Suffix and Gender across one row. Assembled the way the
                  wizard assembles it so the two read the same, with blank
                  parts dropping out rather than leaving double spaces.
                */}
                  <Field
                    label="11 / 12. Owner / Representative"
                    value={[
                      business.owner?.given_name,
                      business.owner?.middle_name,
                      business.owner?.surname,
                      business.owner?.suffix,
                    ]
                      .map((part) => (part ?? '').trim())
                      .filter(Boolean)
                      .join(' ')}
                  />
                  <Field
                    label="11 / 12. Gender"
                    value={business.owner?.gender ? humanizeKey(business.owner.gender) : ''}
                  />
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
                    label="13. Name of President / Officer in Charge"
                    value={business.president_officer_name ?? ''}
                  />
                  <Field
                    label="14. Citizenship (of President/OIC)"
                    value={business.citizenship ?? ''}
                  />
                  <Field
                    label="15. Capital Participation (% Filipino)"
                    value={
                      business.capital_participation_filipino == null
                        ? ''
                        : `${business.capital_participation_filipino}%`
                    }
                  />
                </div>
                {/*
                 * Items B6 and B8 were printed here and have moved to Section B.
                 * They are Business OPERATION questions — what kind of
                 * establishment this is, and whether it holds tax incentives —
                 * and printing them under "Business Information & Registration"
                 * put two of the paper's B items under its A heading on a sheet
                 * whose whole purpose is to be a faithful rendering of it.
                 */}
              </div>

              <SubHeading>Main Office Address</SubHeading>
              <div className="grid gap-4 sm:grid-cols-3">
                {/*
                The real columns first, `splitLine1` only as a fallback.
                `house_bldg_no` and `street` are what the wizard sends since
                16 September 2026; before that it asked one combined question
                and this page guessed the split out of `line1` with a regex,
                which reversed the two on any filing whose entire street
                address was a number ("17" → Street "17", House "—"). The
                fallback stays for the filings made that way.
              */}
                <Field label="House / Bldg No." value={address?.house_bldg_no || house} />
                <Field label="Street" value={address?.street || street} className="sm:col-span-2" />
                <Field label="Barangay" value={address?.barangay?.name ?? ''} />
                <Field label="City / Municipality" value={address?.city ?? 'Malabon City'} />
                <Field label="Province" value={address?.province ?? 'Metro Manila'} />
                <Field label="Postal Code" value={address?.postal_code ?? ''} />
              </div>

              {/*
              ── The pin, and the map it sits on ─────────────────────────────

              Neither was on this page. The coordinates were not even printed
              as text, so the one fact that decides the locational clearance
              was invisible to the office giving the initial approval.

              It matters more than the lines above it. A barangay name and a
              street cannot be verified by reading them; a pin can. And this
              pin is already CHECKED — the wizard refuses one outside Malabon
              and refuses one that contradicts the barangay chosen from the
              dropdown — so it carries signal the typed address does not.
              CPDD rules the locational clearance from exactly this point, and
              until now nobody looked at it before the money was taken.

              `readOnly`, not `lockedReason`. Both stop the map being changed —
              neither mounts the click handler, neither lets the marker be
              dragged — but `lockedReason` also paints a scrim and a sentence
              ACROSS the map, because it means "set aside until you answer
              something else" and an applicant whose map stopped responding
              needs telling why. Nothing is missing here, so that sentence sat
              over the middle of the pin it was describing. The explanation
              belongs beside the map, where it is.
            */}
              {address?.latitude != null && address?.longitude != null ? (
                <div className="mt-5 grid gap-5 lg:grid-cols-[1fr_minmax(0,22rem)]">
                  <MapPicker
                    latitude={address.latitude}
                    longitude={address.longitude}
                    highlightBarangay={address.barangay?.name ?? null}
                    readOnly
                  />
                  <div className="space-y-4">
                    <Field label="Latitude" value={address.latitude.toFixed(6)} />
                    <Field label="Longitude" value={address.longitude.toFixed(6)} />
                    <p className="text-xs leading-relaxed text-ink-secondary">
                      The applicant placed this pin, and the wizard checked it against the city
                      boundary and the barangay above before accepting it. CPDD decides the
                      locational clearance from this point.
                    </p>
                  </div>
                </div>
              ) : (
                /*
                Said rather than left blank. A filing with no pin is one made
                before the map was required, and an empty space here reads as a
                map that failed to load — which sends a reviewer looking for a
                fault instead of telling them the answer.
              */
                <p className="mt-4 rounded-lg border border-line bg-shell px-4 py-3 text-sm text-ink-secondary">
                  No map pin was recorded on this filing.
                </p>
              )}
            </section>

            {/*
            B — Business Operation.
            ─────────────────────────────────────────────────────────────────
            It was headed "Line of Business" and held only that table, while
            items B6 and B8 were printed up in Section A and the premises and
            emergency-contact answers were printed NOWHERE. So the sheet had an
            A that carried B's questions, a B that carried one of them, and a
            handful the applicant typed that no officer could read.

            The paper's B is "Business Operation": what the business does, out
            of what premises, on what terms, and who to ring. That is the
            grouping now, and the letter finally means the same thing on both
            sides of the desk.
          */}
            <section className="mt-9">
              <SectionHeading letter="B">Business Operation</SectionHeading>

              {/*
              ── Items 1 to 4, back in the section that asks them ────────────

              These were drawn under a heading called "Fee Declaration",
              lettered E, and the client's objection on 16 September 2026 is
              the right one: MCG-BPLO-FO-001 has no such section. It was ours,
              and it held section B's own items 1, 2, 3 and 6 a second time —
              so the sheet asked "what is the business area" twice, under two
              different letters, and answered it once.

              They are figures the fee engine reads, which is why they were
              filed under fees. But the PAPER asks them here, as items 1 to 4
              of Business Operation, and the sheet is a rendering of the paper.
              `feeProfileFacts` supplies them, already labelled with the
              paper's numbers.
            */}
              {feeFacts.length > 0 && (
                <div className="mb-6 grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                  {feeFacts.map((fact) => (
                    <Field key={fact.label} label={fact.label} value={fact.value} />
                  ))}
                </div>
              )}

              <div className="mb-6 grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                <Field
                  label="5. Economic Organization"
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
                 * remedy the Amendment From block prescribes for the same class
                 * of gap.
                 *
                 * The null branch is kept for the case the resource omits the
                 * field entirely (a business that has been removed from the
                 * register renders an empty ReviewBusiness).
                 */}
                {/*
                Item B6 — one figure for the whole business, as the paper asks.
                Shown for a new filing only: a renewal is assessed on last
                year's gross sales rather than on capital, which is why the
                wizard does not ask a renewal for it either.
              */}
                <Field
                  label="6. Capital Investment"
                  value={
                    business.capital_investment == null || business.capital_investment === ''
                      ? ''
                      : formatMoney(Number(business.capital_investment))
                  }
                />
                <Field
                  label="7. Tax Incentives from a Government Entity"
                  value={
                    business.has_tax_incentives == null
                      ? ''
                      : business.has_tax_incentives
                        ? 'Yes — certificate required'
                        : 'No'
                  }
                />
              </div>

              <SubHeading>Line of Business</SubHeading>
              {business.lines && business.lines.length > 0 ? (
                <div className="space-y-4">
                  {business.lines.map((line, i) => (
                    <div key={line.id ?? i} className="grid gap-4 sm:grid-cols-2">
                      {/*
                      A per-line "Capitalization" stood beside this and is
                      gone. It is the same quantity as item 6, Capital
                      Investment, shown a few rows above — the wizard asked it
                      per line AND per business until 16 September 2026, when
                      the per-line question went because the paper has one box
                      and two boxes for one figure can disagree.
                      `business_lines.capitalization` is still filled by the
                      API from that single figure, so this column was the same
                      number twice on a good filing and a dash on this one.
                    */}
                      <Field
                        label={`Line of Business ${business.lines!.length > 1 ? i + 1 : ''}`.trim()}
                        value={
                          line.psic_code ? `${line.psic_code.title} (${line.psic_code.code})` : ''
                        }
                      />
                      {/*
                       * Products / Services — the paper's own second column of
                       * this table, on both BPLO forms and on CENRO's CEC
                       * application. Kept inside the per-line row because that is
                       * where it belongs: the trade above names what this line
                       * IS, this names what it handles, and CENRO reviews the
                       * second. Spans the row so a long list of goods is readable
                       * rather than crushed into half the width — the row was
                       * three columns until the duplicate per-line
                       * capitalization came out of it.
                       */}
                      <Field
                        label="Products / Services"
                        value={line.products_services ?? ''}
                        className="sm:col-span-2"
                      />
                    </div>
                  ))}
                </div>
              ) : (
                <Field
                  label="Line of Business"
                  value={app.permit_types.map((p) => p.name).join(', ')}
                />
              )}

              {/*
               * The premises, and who to ring — asked of every applicant and
               * shown to no officer until now.
               *
               * `BusinessResource` has emitted all seven of these fields the
               * whole time; no section printed them. The lessor block is the
               * costlier omission: whether a business rents, from whom, and for
               * how much is exactly what an officer checks a lease against, and
               * the lease is sitting in Section C two headings below. The
               * emergency contact is the number an inspector rings when nobody
               * answers at the premises.
               *
               * The lessor block is drawn only when the premises are rented,
               * because four empty fields under "Lessor" read as missing answers
               * rather than as an owned building. The one-line statement is
               * printed either way, so the sheet always says which it is.
               */}
              <SubHeading>Premises &amp; Contact</SubHeading>
              <div className="grid gap-4 sm:grid-cols-3">
                <Field
                  // Item 8 asks "Do you pay rent for occupying a place of
                  // business?", so the answer is Yes or No — "Rented"/"Owned"
                  // answered a question the paper does not put, and the wizard
                  // stopped putting it on 16 September 2026.
                  label="8. Do you pay rent for occupying a place of business?"
                  value={business.is_rented == null ? '' : business.is_rented ? 'Yes' : 'No'}
                />
                <Field
                  label="Emergency Contact Person"
                  value={business.emergency_contact_name ?? ''}
                />
                <Field
                  label="Emergency Contact Number"
                  value={business.emergency_contact_number ?? ''}
                />
              </div>
              {/*
              ── Four rows removed, because nothing fills them any more ────────
              *
              * Lessor's Name, Lessor's Address, Lessor's Contact Number and
              * Monthly Rental were rendered here whenever the premises were
              * rented. The applicant's wizard stopped collecting all four on
              * 16 September 2026, when the client removed the lessor block as
              * absent from MCG-BPLO-FO-001 — so from that day every new rented
              * filing showed the reviewing officer four labelled rows reading
              * "—", and an officer cannot tell a question the applicant
              * skipped from one the system never asked.
              *
              * This is what a SECOND, hand-written rendering of the same
              * answers costs: the wizard changed and nothing connected the two,
              * so the drift shipped silently into the screen BPLO works from.
              * Worth remembering the next time a read-only view of the form
              * looks cheaper to write than to derive.
              *
              * The columns stay on `businesses`: filings made before that date
              * recorded real values and deleting them would destroy a record
              * the city took. MCG-CPDD-FO-003 is the paper that still asks, and
              * its own sheet takes the answer — see the lessor fields in
              * OfficeFormStep.
              */}

              {/*
              ── What the paper does not ask, after everything it does ────────

              The tax class the Revenue Code prices the trade under, the gross
              sales a renewal is assessed on, and the activities that carry a
              fee of their own. None is on MCG-BPLO-FO-001, and all three are
              needed to work out what is owed — the counter's clerk determines
              them by hand and writes the amount into the "Assessed Fee" box.

              Placed here, after the paper's item 8, and marked as ours. That
              is the same shape the applicant's Business Operation step uses,
              which is the point: the two screens now divide the section the
              same way, so a reviewer comparing them is reading one layout
              twice rather than two layouts once.

              They were under a heading called "Fee Declaration", lettered E as
              though the paper had such a section. It does not.
            */}
              {(feeLines.length > 0 || feeFlags.length > 0) && (
                <div className="mt-6 border-t border-line pt-5">
                  <h3 className="text-xs font-bold uppercase tracking-wide text-ink-secondary">
                    Not on the paper form — worked out for the assessment
                  </h3>
                  {feeLines.length > 0 && (
                    <div className="mt-3 space-y-4">
                      {feeLines.map((line, i) => (
                        <div key={i} className="grid gap-x-6 gap-y-5 sm:grid-cols-2">
                          <Field
                            label={`Taxed as ${feeLines.length > 1 ? i + 1 : ''}`.trim()}
                            value={humanizeKey(line.category ?? '')}
                          />
                          {/*
                          Gross sales is genuinely per line — the Revenue Code
                          taxes each trade on its own turnover, and Sec.
                          2J.02(c) reports two rates separately. Capital
                          investment is not: one business, one figure, item 6,
                          shown once with the paper's own items above.
                        */}
                          <Field
                            label="Gross Sales (Preceding Year)"
                            value={line.gross_sales == null ? '' : formatMoney(line.gross_sales)}
                          />
                        </div>
                      ))}
                    </div>
                  )}
                  {feeFlags.length > 0 && (
                    <div className="mt-4">
                      <FieldLabel>Which of these apply to the business</FieldLabel>
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
                </div>
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
                /*
                Only what the requirement list still asks for, and in the order
                the applicant was asked.

                An "Also uploaded" block listed the rest — files sent against
                requirements since removed — and the client had it taken out on
                16 September 2026. The files are not deleted and the API still
                serves them; this sheet is the reviewer's checklist, and a
                requirement nobody is asked for has no place on a checklist.

                Drawn only once the requirement list has arrived. Rendering
                first and filtering after would show the retired rows and then
                take them away, which is the thing being removed, briefly.
              */
                <ul className="space-y-2.5">
                  {permitTypesRef.loading
                    ? [0, 1, 2].map((i) => <Skeleton key={i} className="h-16 rounded-lg" />)
                    : askedFor.map((doc) => <DocumentRow key={doc.id} doc={doc} />)}
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
            {/*
             * ── Section D is DRAWN ONLY WHEN IT HAS SOMETHING IN IT ────────────
             *
             * The client, seeing its empty state: "can you remove this part since
             * this is highly unnecessary."
             *
             * They are right, and the reason is structural rather than a matter
             * of taste. Office separability means a clearance office can never
             * have anything here — every other office's sheet is withheld from it
             * by design — so for five of the six seats this section was a
             * permanent heading over a permanent apology. A section that can only
             * ever be empty is not information; it is a promise the screen cannot
             * keep, and it pushed the officer's own work further down the page to
             * make room for it.
             *
             * It still renders, populated, for BPLO and the super admin, who hold
             * `application.view_any_office` and coordinate across offices. That is
             * the one seat where "other offices' answers" is a real category with
             * real contents, and it is why this is a conditional rather than a
             * deletion.
             */}
            {otherOfficeForms.length > 0 && (
              <section className="mt-9">
                <SectionHeading letter="D">Other Offices’ Form Answers</SectionHeading>
                {otherOfficeForms.map((form, formIndex) => {
                  const entries = Object.entries(form.form_data ?? {})
                  return (
                    <div key={form.permit_type_code ?? formIndex}>
                      <div
                        className={`mb-3 flex items-center gap-2 ${formIndex === 0 ? 'mt-1' : 'mt-6'}`}
                      >
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
                        <p className="text-sm text-ink-muted">
                          No answers were recorded on this form.
                        </p>
                      ) : (
                        <div className="grid gap-4 sm:grid-cols-2">
                          {entries.map(([key, value]) => (
                            <Field
                              key={key}
                              label={humanizeKey(key)}
                              value={formValueText(value)}
                            />
                          ))}
                        </div>
                      )}
                      {form.requirements && form.requirements.length > 0 && (
                        <RequirementsRead code={form.permit_type_code} rows={form.requirements} />
                      )}
                    </div>
                  )
                })}
              </section>
            )}

            {/*
            ── Section E is gone: the paper has no "Fee Declaration" ──────────

            It was ours, lettered E as though it were part of the form, and it
            held section B's own items 1, 2, 3 and 6 over again plus the
            business structure from section A item 10. One sheet asking the
            same questions under two letters, and the client caught it on
            16 September 2026.

            Its contents are not lost, they are placed:

              items 1-4          → the head of section B, where the paper asks
              item 6             → section B beside items 5, 7 and 8
              business structure → section A item 10, where it was already
              tax class, gross
              sales, flags       → the block below, marked as ours

            Sections C and D keep their letters. Renumbering A-E to close the
            gap would make every section reference in the offices wrong, and
            the letters are the paper's, not a count of what we draw.
          */}

            {/* Consent note (p72) */}
            <div className="mt-6 rounded-md border border-s-green bg-s-green-tint px-4 py-3">
              <p className="flex items-center gap-2 text-sm font-bold text-s-green">
                <CheckIcon size={16} />
                Data Privacy Consent: agreed by applicant
              </p>
              <p className="mt-1 text-xs text-ink-muted">
                Consent recorded {formatDateTime(app.submitted_at)} · under RA 10173 (Data Privacy
                Act of 2012).
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
            ── The second bar: the Tax Order of Payment ────────────────────────

            Directly under the first, so the two read as one pair of "context
            you can ask for" rather than as a control and an unrelated block.
            Office seats only — BPLO's copy is in FOR OFFICE USE ONLY below,
            where the paper puts it. See `taxOrderBlock`.

            Rendered only when there IS an assessment. An empty disclosure
            promising a Tax Order of Payment on a filing that has none — every
            filing before BPLO's first approval — is a control that opens onto
            nothing, which is worse than no control.
          */}
          {foldsApplication && taxOrderBlock && (
            <>
              <div className="mt-4">
                <button
                  type="button"
                  onClick={() => setTaxOpen((open) => !open)}
                  aria-expanded={taxOpen}
                  aria-controls="tax-order-of-payment"
                  className="flex w-full items-start gap-3 rounded-lg border border-line bg-canvas px-4 py-3 text-left hover:border-royal/40 hover:bg-royal-tint focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
                >
                  <span
                    className={`mt-0.5 shrink-0 text-royal transition-transform ${taxOpen ? 'rotate-180' : ''}`}
                    aria-hidden="true"
                  >
                    <ChevronDownIcon size={18} />
                  </span>
                  <span className="min-w-0">
                    <span className="block text-sm font-bold text-ink">
                      {taxOpen ? 'Hide the Tax Order of Payment' : 'Show the Tax Order of Payment'}
                    </span>
                    {/*
                      The total names what is inside, the way the application's
                      summary does — and it is the one number an officer opens
                      this for. Inside the button, so a screen reader hears it
                      with the control rather than after it.
                    */}
                    <span className="mt-0.5 block text-xs text-ink-secondary">
                      Every office's fees on this filing, itemised against the Revenue Code —{' '}
                      {formatMoney(app.fee_assessment?.total_amount)} in total. Nothing in here is
                      editable.
                    </span>
                  </span>
                </button>
              </div>
              <div id="tax-order-of-payment" hidden={!taxOpen}>
                {taxOrderBlock}
              </div>
            </>
          )}

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
                  ? canAdjustFee || issuedGroups.length > 0 || canSetTier
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

            {/*
             * ── RA 11032 processing category ──────────────────────────────
             *
             * The client: "In the average processing time, since it
             * categorizes applications into simple, complex, and highly
             * technical, allow all office admins to set the application
             * category during their edit mode when trying to approve the
             * application."
             *
             * It sits in FOR OFFICE USE ONLY, and it belongs here rather than
             * anywhere else on this 2,000-line sheet for one reason: this
             * panel is the boundary between what the applicant declared and
             * what the office decides. Sections A–E above are the applicant's
             * sworn declaration and are locked in both modes; the tier is not
             * theirs to state and never was — it is the LGU's classification
             * of their filing, so it goes on the office's side of that line,
             * beside the assessed fee and the issuance dates.
             *
             * Every reviewing office gets it, not just BPLO, exactly as asked.
             * The API agrees by construction: the route sits behind
             * `application.review`, which all seven offices hold and no
             * applicant does, and which office may set it on which filing is
             * AssignmentController::authorizeDepartment's usual decision.
             *
             * ── Three things here are load-bearing ────────────────────────
             *
             * 1. The OPTIONS come off the payload. The three tiers and their
             *    day counts are RA 11032 itself; a hard-coded list here could
             *    drift into offering a fourth tier or mislabelling a deadline,
             *    which would be a compliance defect wearing a typo's clothes.
             * 2. The PROVENANCE is stated before the control, not after it.
             *    Every tier in the register was assigned by a rule this
             *    project invented and BPLO never approved, so "Simple" on
             *    screen is a guess until a person says otherwise. An officer
             *    must be able to see which of the two they are looking at.
             * 3. The DEADLINE consequence is stated in the same breath.
             *    Changing the tier re-counts `deadline_at` from the filing
             *    date, which can put a filing immediately past its deadline —
             *    that is the honest arithmetic, and it must not be a surprise
             *    discovered in the queue the next morning.
             *
             * `aria-disabled` on Save, never `disabled`: a control dropped out
             * of the tab order takes the sentence explaining WHY it is shut
             * with it, and that sentence is the whole point.
             */}
            <div className="mt-5 border-t border-officeuse-border pt-4">
              <p className="text-[11px] font-bold uppercase tracking-wide text-amber-800">
                RA 11032 · Processing Category
              </p>
              <p id="ra11032-note" className="mt-1 max-w-prose text-xs text-ink-secondary">
                {tierProvenance}
                {editing && canSetTier && (
                  <>
                    {' '}
                    The three categories and their day counts are set by RA 11032 and cannot be
                    edited. Changing which one this filing is re-counts its deadline from the date
                    it was filed, not from today.
                  </>
                )}
              </p>
              <div className="mt-3">
                {editing && canSetTier ? (
                  tierPicker()
                ) : (
                  <OfficeReadout
                    label="Application category"
                    value={
                      ra?.label ? `${ra.label} — ${ra.statutory_working_days} working days` : ''
                    }
                  />
                )}
              </div>
              {tierNote && <p className="mt-2 text-xs font-medium text-s-green">{tierNote}</p>}
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
                            setIssued((v) => ({
                              ...v,
                              [`${group.code}.${field.key}`]: e.target.value,
                            }))
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

          {/*
            Itemized Tax Order of Payment (revenue-code assessment).

            BPLO's copy stays HERE, inside FOR OFFICE USE ONLY, because that is
            where the paper puts the assessment and BPLO is the office that
            raises it. A clearance office gets the same block as a second
            disclosure above — see `taxOrderBlock` for why the two seats differ.
          */}
          {!foldsApplication && taxOrderBlock}

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
        {/*
          Rendered only when it has something to show. It was `hidden lg:block`
          unconditionally, so it reserved its 288px on every wide screen
          including the common case — a filing just submitted, with no remarks
          and no popup — and the form sheet was squeezed to 760px of a 1072px
          page to make room for nothing.

          Empty, the column is simply not there and `flex-1` gives the sheet
          the whole width. The moment a remark exists or the reviewer opens the
          popup, it appears where it always did.
        */}
        {(popup || existingRemarks.length > 0) && (
          <aside
            className="sticky top-8 hidden w-72 shrink-0 space-y-4 lg:block"
            aria-label="Remarks"
          >
            {popup && (
              <RemarkPopup
                action={popup}
                officer={officerName}
                initialText={remarks}
                /* Only a return points at something; see returnTargets. */
                targets={popup === 'return' ? returnTargets : []}
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
        )}
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
            /*
             * The phone instance gets the same list. It is a second,
             * independent RemarkPopup rather than one moved by CSS — see the
             * note above about `initialText` having to be passed twice — so a
             * prop given to only one of them is a control that exists on a
             * desktop and not on a phone.
             */
            targets={popup === 'return' ? returnTargets : []}
            submitting={busy}
            error={actionError}
            onCancel={() => setPopup(null)}
            onConfirm={sendRemark}
          />
        </div>
      )}
    </div>
  )
}
