import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { MapPicker } from '../../components/MapPicker'
import {
  CheckCircleFilledIcon,
  CheckIcon,
  ClipboardIcon,
  SearchIcon,
  UploadIcon,
} from '../../components/icons'
import { Alert } from '../../components/ui/Alert'
import { Skeleton } from '../../components/ui/primitives'
import {
  FieldLabel,
  PillButton,
  ProtoModal,
  inputCls,
} from '../../components/ui/Proto'
import { formatBytes, formatDate } from '../../lib/format'
import { toApiError } from '../../lib/api'
import { applications, businesses, documents, reference } from '../../lib/resources'
import type { AmendmentAnswers } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { ACCEPT_ATTR, fileRejection, uploadErrorMessage } from './uploads'
import {
  LocationInsightsPanel,
  useLocationInsights,
  type LocationInsightsQuery,
} from './LocationInsightsPanel'
import {
  EMPTY_FEE_PROFILE,
  FeeProfileStep,
  buildFeeProfile,
  feeProfileMissing,
  feeProfileToDraft,
  formatAmountInput,
  type FeeProfileDraft,
} from './FeeProfileStep'
import type {
  ApplicationType,
  Barangay,
  Business,
  BusinessPayload,
  DocumentType,
  OcrSuggestions,
  Permit,
  PsicCode,
} from '../../lib/types'

type BasePhase = 'privacy' | 'address' | 'business' | 'documents' | 'fees' | 'review'
/*
 * ── This wizard is the BUSINESS PERMIT application, and nothing else ──────
 *
 * Decided with the client on 3 August 2026 (docs/clearances-after-payment.md).
 * The six supporting clearances used to be step 4 of 8 here, and this comment
 * used to be an explanation of why they could not be moved any later.
 *
 * The argument was: the two sections after the cards are COMPUTED from which
 * clearances were picked — `requiredDocs` was the union of the document types
 * on the selected permit types, and the tax profile's questions varied by
 * permit code — so putting the cards after either one would ask the applicant
 * to satisfy a list that did not exist yet. That was true, and it is why item
 * 76 ("place this at the last part before submitting") was recorded as a
 * deviation rather than fixed.
 *
 * The dependency only existed because the clearances were being treated as
 * part of this filing. They are not. Each is a separate transaction with a
 * separate office, a separate fee and a separate outcome — and each is applied
 * for once the first payment clears, on the LGU Clearances stage
 * (ClearanceStagePage). With them gone the dependency dissolves: the documents
 * and the fees below describe the business permit alone, so nothing here is
 * computed from an answer given later, and item 76 is satisfied in the sense
 * the client meant it.
 *
 * What survives of the old ordering, and why:
 *
 *   Consent comes before collection. Everything after the first step asks the
 *   applicant for personal data, so the Data Privacy Act notice is the one
 *   thing that cannot sit behind any of it.
 *
 *   Location & Zoning is then the first thing asked (revised GUI, screens
 *   28-33: "Zoning - Selecting Business Location" is Part 1). Where the
 *   business is decides what it may be, so asking for the address before the
 *   paperwork matches how the counter actually works.
 *
 *   Item 69 — Line of Business used to have a step of its own, three sections
 *   further on, while Location & Zoning asked the same question in a plain
 *   dropdown so the zoning verdict had a trade to be about. Two asks, one
 *   answer. The picker (search, multi-select, "Other (not listed)" free text,
 *   capital per line) lives in Location & Zoning and the separate section is
 *   gone: the fuller control survives, the duplicate does not.
 *
 * Documentary Requirements now precedes the Business & Tax Profile, which is
 * the order the client's diagram gives. Nothing forces it either way any more
 * — that was the whole point of the dependency dissolving — so the two run in
 * the order the paper does.
 */
const BASE_PHASES: BasePhase[] = [
  'privacy',
  'address',
  'business',
  'documents',
  'fees',
  'review',
]

const BASE_LABELS: Record<BasePhase, string> = {
  privacy: 'Data Privacy Consent',
  business: 'Business Information',
  address: 'Location & Zoning',
  documents: 'Documentary Requirements',
  fees: 'Business & Tax Profile',
  review: 'Review & Submit',
}

/** Document-type code for the repeatable "Other Requirements" uploads. */
const OTHER_DOC_CODE = 'OTHER'

/*
 * How long typing settles before the draft is written. Long enough that a
 * sentence is one save, short enough that stepping away from the keyboard
 * always leaves the work saved.
 */
const AUTOSAVE_DELAY_MS = 1200

/*
 * The Mayor's / Business Permit is the OUTCOME of the application, not a
 * clearance you tick, so it is never rendered as a card: the wizard attaches
 * it to every application so BPLO always gets the assignment.
 */
const BUSINESS_PERMIT_CODE = 'BUSINESS'

/** Catch-all PSIC row (ReferenceSeeder::OTHER_PSIC_CODE) for trades not listed. */
const OTHER_PSIC_CODE = '00000'

/*
 * What the BPLO counter sees most, shown before the applicant types anything.
 * The full list is long enough that "the first eight by code" would open on
 * food manufacturing instead of the sari-sari store.
 */
const COMMON_PSIC_CODES = ['47111', '56101', '47112', '10711', '96110', '96120', '96200', '36000']

/*
 * ── Item 86 · where a pin may be dropped ──────────────────────────────────
 *
 * A bounding box around Malabon City, and deliberately nothing more.
 *
 * The system holds no zone polygons and no coastline (the comments on the
 * zoning step and the zoning modal have said so since the step was built), so
 * the only thing that can be checked here honestly is whether the point is
 * anywhere near the city at all. The box is drawn from the coordinates the repo
 * already uses for Malabon — the map's default centre at Malabon City Hall
 * (14.6572, 120.9573), the seeded demo businesses (14.6690/120.9560,
 * 14.6712/120.9605), the analytics heat-map centre (14.669, 120.957) and the
 * analytics history seeder's 14.655–14.685 spread — widened to the city's
 * roughly 6 km × 6 km extent so that a real address near a boundary is not
 * refused. Every coordinate already in the repo falls inside it.
 *
 * What this does NOT do, and what the applicant is therefore never told it
 * does: it does not prove the pin is inside the city limits (a bounding box
 * around an irregular city necessarily includes slivers of Navotas, Caloocan
 * and Valenzuela), and it does not detect water. Malabon is a river delta —
 * the Tullahan, the Tenejeros-Tanza and the fishpond belt run through it — so
 * "not on water" cannot be answered without a coastline or hydrography layer,
 * which would mean an external service (an OSM/Overpass water query, or a
 * shipped GeoJSON of the city). Neither exists here, and a water check that
 * silently passed everything would be worse than none: it would put the city's
 * name behind a guarantee nobody made. CPDO still evaluates the actual location
 * during processing, which is what the step has always said.
 */
const MALABON_BOUNDS = {
  minLat: 14.645,
  maxLat: 14.7,
  minLng: 120.93,
  maxLng: 120.985,
}

/** True when a pin is inside the Malabon bounding box described above. */
function withinMalabon(latitude: number, longitude: number): boolean {
  return (
    latitude >= MALABON_BOUNDS.minLat &&
    latitude <= MALABON_BOUNDS.maxLat &&
    longitude >= MALABON_BOUNDS.minLng &&
    longitude <= MALABON_BOUNDS.maxLng
  )
}

/*
 * There is no `StepNode` any more.
 *
 * A step used to be either a base phase or one office form sheet, and the
 * sheets slotted into the middle of the sequence the moment a clearance was
 * ticked — which is why steps needed a stable identity (`stepKey`) separate
 * from their position, and why unticking one had to drag `step` back inside
 * the array. The office sheets belong to the LGU Clearances stage now, so the
 * sequence is exactly BASE_PHASES, in order, always. A phase name IS its key.
 */

/**
 * Document-type code prefix the API gives a clearance the applicant already
 * holds (DocumentController::heldPermitDocumentType).
 *
 * Kept only so a reopened draft can SKIP those attachments. A clearance copy
 * is not a documentary requirement of the business permit and never was —
 * before, the wizard restored them onto the clearance cards; now the cards are
 * a stage of their own, and the wizard's job is simply not to mistake one for
 * an uploaded requirement.
 */
const HELD_DOC_PREFIX = 'HELD_'

/** An attachment already on the draft: the id is what a removal needs. */
interface UploadedFile {
  id: number
  name: string
  size: number
}

const TYPE_META: Record<ApplicationType, { title: string; ref: string }> = {
  new: { title: 'Application for New Business Permit', ref: 'MCG-BPLO-FO-001 · v2.0' },
  renewal: { title: 'Application for Renewal of Business Permit', ref: 'MCG-BPLO-FO-002 · v2.0' },
  amendment: { title: 'Application for Amendment of Business Permit', ref: 'MCG-BPLO-FO-003 · v2.0' },
}

/*
 * One selected line of business: a PSIC code, optional capitalization, and the
 * free-text trade the applicant types when they pick "Other (not listed)".
 */
interface LineDraft {
  psic_code_id: number
  capitalization: string
  line_of_business: string
}

interface FormState {
  name: string
  trade_name: string
  registration_type: string
  registration_number: string
  tin: string
  line1: string
  line2: string
  barangay_id: string
  latitude: number | null
  longitude: number | null
  lines: LineDraft[]
  permit_type_ids: number[]
  /* Unified form: premises and emergency contact. */
  is_rented: boolean
  lessor_name: string
  lessor_address: string
  lessor_contact: string
  monthly_rental: string
  emergency_contact_name: string
  emergency_contact_number: string
}

const EMPTY: FormState = {
  name: '',
  trade_name: '',
  registration_type: '',
  registration_number: '',
  tin: '',
  line1: '',
  line2: '',
  barangay_id: '',
  is_rented: false,
  lessor_name: '',
  lessor_address: '',
  lessor_contact: '',
  monthly_rental: '',
  emergency_contact_name: '',
  emergency_contact_number: '',
  latitude: null,
  longitude: null,
  lines: [],
  permit_type_ids: [],
}

/**
 * The paper form's "Amendment from:" block (checklist items 82/84). Held apart
 * from FormState because it belongs to the APPLICATION, not to the business —
 * the same shop can file one amendment for a change of location and another
 * for a change of ownership, and only the filing knows which is which.
 */
interface AmendmentState {
  ownership: boolean
  location: boolean
  nature: boolean
  /** "Others (specify)" — the text is the tick; blank means not ticked. */
  other: string
}

const EMPTY_AMENDMENT: AmendmentState = {
  ownership: false,
  location: false,
  nature: false,
  other: '',
}

/**
 * The three checkbox amendments, in the order the paper form prints them.
 * "Others (specify)" is not here: it is a text field that ticks itself, so it
 * is rendered separately rather than pretending to be a fourth checkbox.
 */
const AMENDMENT_KINDS: { key: 'ownership' | 'location' | 'nature'; label: string }[] = [
  { key: 'ownership', label: 'Ownership' },
  { key: 'location', label: 'Location' },
  { key: 'nature', label: 'Nature of Business' },
]

const REGISTRATION_TYPES = [
  { value: 'sole_proprietorship', label: 'Sole Proprietorship' },
  { value: 'partnership', label: 'Partnership' },
  { value: 'corporation', label: 'Corporation' },
  { value: 'cooperative', label: 'Cooperative' },
]

/**
 * Philippine TIN: 9 digits, plus a 3 to 5 digit branch code where the taxpayer
 * has one, written with any of the usual separators (123-456-789-000,
 * 123 456 789, 123456789). The API normalises and re-checks the same shape.
 */
function tinValid(raw: string): boolean {
  const trimmed = raw.trim()
  if (!/^[\d\s.-]+$/.test(trimmed)) return false
  const digits = trimmed.replace(/\D/g, '').length
  return digits === 9 || (digits >= 12 && digits <= 14)
}

const TIN_ERROR =
  'Enter a valid TIN: 9 digits, plus a branch code if you have one, like 123-456-789-000.'

/**
 * Philippine contact number: an 11-digit mobile (09XX XXX XXXX), the same
 * number written +63, or a landline with or without its area code. Deliberately
 * lenient about separators — the point is to catch a typo, not a format.
 */
function phoneValid(raw: string): boolean {
  const trimmed = raw.trim()
  if (!/^[+\d\s().-]+$/.test(trimmed)) return false
  const digits = trimmed.replace(/\D/g, '').length

  return digits >= 7 && digits <= 13
}

const PHONE_ERROR =
  'Enter a Philippine mobile or landline number, like 09171234567 or 8123 4567.'

/** Strip the display separators before an amount goes to the API. */
function plainAmount(raw: string): string {
  return raw.replace(/,/g, '').trim()
}

/* ── Attachments ──────────────────────────────────────────────────────── */

/*
 * `fileRejection`, `uploadErrorMessage` and the size/extension limits moved to
 * ./uploads. The LGU Clearances stage takes a file from the applicant too now,
 * and two screens with two ideas of "10 MB" is how one of them starts accepting
 * a file the API then refuses.
 */

/* ── Small prototype glyphs ───────────────────────────────────────────── */

function CloudSavedIcon({ size = 26 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M7 18a4.5 4.5 0 0 1-.6-8.96A5.5 5.5 0 0 1 17 8.6 4 4 0 0 1 17.5 18H7Z"
        fill="#3242ca"
      />
      <path d="M9 13.3l2 2 3.6-3.8" stroke="#fff" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )
}

/** Royal square with white section letter (form sheet "A"/"B" markers). */
function SectionMarker({ letter, label }: { letter: string; label: string }) {
  return (
    <div className="flex items-center gap-2.5">
      <span className="flex h-6 w-6 items-center justify-center rounded-sm bg-royal text-[13px] font-bold text-white">
        {letter}
      </span>
      <h2 className="text-[15px] font-bold text-ink">{label}</h2>
    </div>
  )
}

/** White prototype form sheet with kicker + h1 + form ref + royal rule. */
function FormSheet({
  meta,
  children,
}: {
  meta: { title: string; ref: string }
  children: React.ReactNode
}) {
  return (
    <div className="rounded-sm bg-white px-6 py-7 shadow-card sm:px-9 sm:py-8">
      <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-royal">
        Business Permit &amp; Licensing Office · Phase 1
      </p>
      <h1 className="mt-1.5 text-2xl font-bold text-ink">{meta.title}</h1>
      <p className="mt-1 text-xs text-ink-muted">Form Ref: {meta.ref}</p>
      <div className="mb-6 mt-3 h-px bg-royal" />
      {children}
    </div>
  )
}

/* ── PSIC picker (Line of Business) ───────────────────────────────────── */
/*
 * "Does Line of Business really have fixed choices?" It is a searchable
 * picklist of the seeded PSIC codes (reference/psic-codes), and the last row
 * is always "Other (not listed)": pick it and you type your own trade, which
 * the API stores on business_lines.line_of_business. The separate fee-profile
 * category field stays a datalist by design.
 *
 * Item 69 — this is now the only place the question is asked. It is rendered
 * inside Location & Zoning, which used to ask it a second time with a plain
 * dropdown; the dropdown is gone and this control moved, rather than the other
 * way round, because search, several lines at once, and a free-text escape for
 * a trade the PSIC list has never heard of are all things a shop owner needs
 * and none of them survive in a <select>.
 */
function LinesStep({
  codes,
  lines,
  onChange,
}: {
  codes: PsicCode[]
  lines: LineDraft[]
  onChange: (lines: LineDraft[]) => void
}) {
  const [query, setQuery] = useState('')
  const otherCode = useMemo(() => codes.find((c) => c.code === OTHER_PSIC_CODE), [codes])
  /*
   * Search the real trades only; "Other (not listed)" is pinned underneath the
   * results so it is reachable however the search went.
   */
  const results = useMemo(() => {
    const q = query.trim().toLowerCase()
    const listed = codes.filter((c) => c.code !== OTHER_PSIC_CODE)
    if (!q) {
      const common = COMMON_PSIC_CODES.map((code) => listed.find((c) => c.code === code)).filter(
        (c): c is PsicCode => c !== undefined,
      )
      return common.length > 0 ? common : listed.slice(0, 8)
    }
    return listed
      .filter((c) => c.title.toLowerCase().includes(q) || c.code.includes(q))
      .slice(0, 12)
  }, [codes, query])

  function toggle(code: PsicCode) {
    const exists = lines.find((l) => l.psic_code_id === code.id)
    if (exists) onChange(lines.filter((l) => l.psic_code_id !== code.id))
    else onChange([...lines, { psic_code_id: code.id, capitalization: '', line_of_business: '' }])
  }

  return (
    <div className="space-y-4">
      <div>
        <label htmlFor="psic-search" className="block">
          <FieldLabel required>Search your line of business</FieldLabel>
        </label>
        <div className="relative">
          <SearchIcon
            size={18}
            className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-ink-secondary"
          />
          <input
            id="psic-search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="e.g. retail, food, salon"
            className={`${inputCls} pl-10`}
          />
        </div>
        <p className="mt-1.5 text-xs text-ink-secondary">
          Can’t find your trade? Pick “Other (not listed)” at the bottom and type it yourself.
        </p>
      </div>

      <ul className="divide-y divide-line overflow-hidden rounded-lg border border-input-border">
        {results.length === 0 ? (
          <li className="px-4 py-4 text-sm text-ink-secondary">
            No matches. Try another word, or pick “Other (not listed)” below.
          </li>
        ) : (
          results.map((code) => {
            const selected = lines.some((l) => l.psic_code_id === code.id)
            return (
              <li key={code.id}>
                <button
                  type="button"
                  onClick={() => toggle(code)}
                  aria-pressed={selected}
                  className={`flex w-full items-center gap-3 px-4 py-3 text-left transition-colors ${
                    selected ? 'bg-input' : 'hover:bg-royal-tint'
                  }`}
                >
                  <span
                    className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-sm border ${
                      selected ? 'border-royal bg-royal text-white' : 'border-input-border bg-white'
                    }`}
                  >
                    {selected && <CheckIcon size={13} />}
                  </span>
                  <span className="min-w-0">
                    <span className="block text-sm font-medium text-ink">{code.title}</span>
                    <span className="tnum block text-xs text-ink-secondary">PSIC {code.code}</span>
                  </span>
                </button>
              </li>
            )
          })
        )}
        {otherCode && (
          <li>
            <button
              type="button"
              onClick={() => toggle(otherCode)}
              aria-pressed={lines.some((l) => l.psic_code_id === otherCode.id)}
              className={`flex w-full items-center gap-3 px-4 py-3 text-left transition-colors ${
                lines.some((l) => l.psic_code_id === otherCode.id) ? 'bg-input' : 'hover:bg-royal-tint'
              }`}
            >
              <span
                className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-sm border ${
                  lines.some((l) => l.psic_code_id === otherCode.id)
                    ? 'border-royal bg-royal text-white'
                    : 'border-input-border bg-white'
                }`}
              >
                {lines.some((l) => l.psic_code_id === otherCode.id) && <CheckIcon size={13} />}
              </span>
              <span className="min-w-0">
                <span className="block text-sm font-medium text-ink">{otherCode.title}</span>
                <span className="block text-xs text-ink-secondary">
                  Type your own line of business
                </span>
              </span>
            </button>
          </li>
        )}
      </ul>

      {lines.length > 0 && (
        <div className="rounded-lg border border-input-border bg-royal-tint p-4">
          <p className="mb-3 text-sm font-bold text-ink">Selected ({lines.length})</p>
          <div className="space-y-3">
            {lines.map((line) => {
              const code = codes.find((c) => c.id === line.psic_code_id)
              // "Other (not listed)" trades the fixed title for a required
              // free-text box; everything else keeps the PSIC title.
              const isOther = code?.code === OTHER_PSIC_CODE
              const needsText = isOther && !line.line_of_business.trim()
              // Capital is what the business tax and the capitalization-based
              // fees are computed from, so a blank one is not a detail.
              const needsCapital = !line.capitalization.trim()
              return (
                <div key={line.psic_code_id}>
                  <div className="flex items-end gap-3">
                    <div className="min-w-0 flex-1">
                      {isOther ? (
                        <>
                          <label className="block">
                          <FieldLabel required>Your line of business</FieldLabel>
                          <input
                            value={line.line_of_business}
                            onChange={(e) =>
                              onChange(
                                lines.map((l) =>
                                  l.psic_code_id === line.psic_code_id
                                    ? { ...l, line_of_business: e.target.value }
                                    : l,
                                ),
                              )
                            }
                            placeholder="e.g. mobile phone repair"
                            className={inputCls}
                            aria-invalid={needsText}
                          />
                          </label>
                        </>
                      ) : (
                        <p className="truncate text-sm text-ink">{code?.title}</p>
                      )}
                    </div>
                    <div className="w-44">
                      <label className="block">
                      <FieldLabel required>Capital (₱)</FieldLabel>
                      <input
                        inputMode="decimal"
                        value={line.capitalization}
                        onChange={(e) =>
                          onChange(
                            lines.map((l) =>
                              l.psic_code_id === line.psic_code_id
                                ? // Grouped as it is typed (1,000,000); the API
                                  // gets the plain number back.
                                  { ...l, capitalization: formatAmountInput(e.target.value) }
                                : l,
                            ),
                          )
                        }
                        placeholder="0.00"
                        className={`${inputCls} tnum`}
                        aria-invalid={needsCapital}
                      />
                      </label>
                    </div>
                    <button
                      type="button"
                      onClick={() => onChange(lines.filter((l) => l.psic_code_id !== line.psic_code_id))}
                      className="mb-2 text-sm font-semibold text-s-red underline underline-offset-2"
                    >
                      Remove
                    </button>
                  </div>
                  {needsText && (
                    <p className="mt-1 text-xs font-medium text-s-red">
                      Type the line of business you want registered.
                    </p>
                  )}
                  {needsCapital && (
                    <p className="mt-1 text-xs font-medium text-s-red">
                      Enter the capital you are putting into this line, in pesos.
                    </p>
                  )}
                </div>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}

export function ApplyWizard() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const rawType = searchParams.get('type')
  // Draft reopening (?draft=ID) may override the type once the draft loads.
  // Ordinance Sec. 2N: pay the business tax in full by January 20, or in four
  // quarterly instalments. The ordinance offers no semi-annual option.
  const [paymentMode, setPaymentMode] = useState<'annual' | 'quarterly'>('annual')
  const [applicationType, setApplicationType] = useState<ApplicationType>(
    rawType === 'renewal' || rawType === 'amendment' ? rawType : 'new',
  )
  const typeMeta = TYPE_META[applicationType]

  const isReuse = applicationType === 'renewal' || applicationType === 'amendment'
  /*
   * The wizard does not evaluate zoning; CPDO does, during processing. The
   * default modal only confirms the pin was recorded. The red non-conforming
   * modal (p031) is reachable with a `?zoning=deny` debug query param.
   */
  const zoningDenied = searchParams.get('zoning') === 'deny'

  const [step, setStep] = useState(0)
  /*
   * Sections the applicant has actually opened, by phase name. The map is
   * clickable for these. A TICK is a different question entirely: it means the
   * section is complete, and is computed fresh from the answers every render,
   * so it can never outlive the answers that earned it.
   */
  const [visited, setVisited] = useState<BasePhase[]>([BASE_PHASES[0]])
  const markVisited = (key: BasePhase) =>
    setVisited((v) => (v.includes(key) ? v : [...v, key]))
  const [form, setForm] = useState<FormState>(EMPTY)
  /*
   * The applicant's own name for this filing. Blank is normal and means "call
   * it by the business name", which is what the header and the Drafts page do.
   */
  const [title, setTitle] = useState('')
  // Business & tax profile inputs (revenue-code fee_profile; persisted on the draft).
  const [feeDraft, setFeeDraft] = useState<FeeProfileDraft>(EMPTY_FEE_PROFILE)
  const [touched, setTouched] = useState<Record<string, boolean>>({})
  /*
   * Item 86 — why the last click on the map was not accepted. Held separately
   * from the form because a refused pin must not become the answer: the
   * coordinates on the form stay whatever they were, and this says what
   * happened instead of the pin silently not moving.
   */
  const [pinError, setPinError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)
  /* Autosave bookkeeping — see the autosave effect below. */
  const [dirty, setDirty] = useState(false)
  const [autosaveNonce, setAutosaveNonce] = useState(0)
  const savedSnapshotRef = useRef<string | null>(null)
  const inFlightRef = useRef(false)

  // Renewal/amendment prefill (v2): reuse an existing business + link prior permit.
  const [prefillBusinessId, setPrefillBusinessId] = useState<number | null>(null)
  const [priorPermitId, setPriorPermitId] = useState<number | null>(null)
  const [prefillNote, setPrefillNote] = useState<string | null>(null)
  const [prefilling, setPrefilling] = useState(false)
  /*
   * Items 82/84 — what this amendment amends.
   *
   * The paper BPLO form's "Amendment from:" block is four checkboxes:
   * Ownership, Location, Nature of Business, Others (specify). Nothing in the
   * wizard asked any of them, so /apply?type=amendment was the new-application
   * form with a different heading — the one question that makes a filing an
   * amendment was the one question it never put.
   *
   * `other` carries its own tick: on the paper you cannot check Others without
   * writing the other in, so typed text IS the answer and there is no fifth
   * boolean to drift out of step with it.
   */
  const [amendment, setAmendment] = useState<AmendmentState>(EMPTY_AMENDMENT)
  const amendmentChosen =
    amendment.ownership ||
    amendment.location ||
    amendment.nature ||
    amendment.other.trim() !== ''

  // OCR-lite suggestion banner (v2) — dismissible; suggestions only.
  const [ocr, setOcr] = useState<OcrSuggestions | null>(null)

  // Owner's existing businesses (only needed to seed renewal/amendment).
  const ownedBusinesses = useAsync<Business[]>(
    () => (isReuse ? businesses.list() : Promise.resolve([])),
    [isReuse],
  )
  /*
   * Items 50/85 — the permits the CHOSEN business holds, so a renewal can name
   * the one it is for instead of "this business, and whatever it happens to
   * have". A shop with a Mayor's Permit expiring in January and a sanitary
   * permit expiring in June is renewing one of them, not both.
   *
   * These come from the prefill now, not from `GET /permits`. That endpoint is
   * the owner's whole portfolio and it is paginated: an applicant with more
   * permits than one page could open a renewal and find the permit they came
   * to renew simply absent from the list. The prefill answers for one business
   * and drops the revoked and suspended ones, which are not renewable at all.
   */
  const [renewablePermits, setRenewablePermits] = useState<Permit[]>([])
  const [loadingPermits, setLoadingPermits] = useState(false)

  // Persisted draft ids (business + application) once the draft exists.
  const [businessId, setBusinessId] = useState<number | null>(null)
  const [applicationId, setApplicationId] = useState<number | null>(null)
  // Keyed by document type; the document id is what a removal needs.
  const [uploaded, setUploaded] = useState<Record<number, UploadedFile>>({})
  // "Other Requirements" allows multiple files (repeatable uploads).
  const [otherDocs, setOtherDocs] = useState<UploadedFile[]>([])
  const [uploadingType, setUploadingType] = useState<number | null>(null)
  const [removingDoc, setRemovingDoc] = useState<number | null>(null)
  const [tracking, setTracking] = useState<string | null>(null)

  /*
   * Item 59 — "I already hold this clearance, here is the copy" — moved out
   * with the cards. It is an action on a clearance, and clearances are now a
   * stage of their own that opens once the first payment clears
   * (ClearanceStagePage). None of the machinery that used to live here — the
   * queue of files chosen before a draft existed, the in-flight guard, the
   * SUBMISSION dialog — has an equivalent there, because by then the
   * application exists and a file can simply be posted.
   */

  // Prototype presentational modals — none of these fabricate API calls.
  const [showClear, setShowClear] = useState(false)
  const [showZoning, setShowZoning] = useState(false)
  const [showConfirm, setShowConfirm] = useState(false)
  const [consent, setConsent] = useState(false)

  const refs = useAsync(
    async () => ({
      barangays: await reference.barangays(),
      psic: await reference.psicCodes(),
      permitTypes: await reference.permitTypes(),
      documentTypes: await reference.documentTypes(),
    }),
    [],
  )

  const draftIdParam = searchParams.get('draft')
  const [hydrating, setHydrating] = useState<boolean>(Boolean(draftIdParam))
  const hydratedRef = useRef(false)
  /*
   * A reopen that did not finish. The wizard has the draft's ids by then but
   * not its answers, so every field reads blank — and autosave, which cannot
   * tell "the applicant cleared this" from "we never loaded it", would write
   * that blank over the saved draft. Nothing about a failed read may be
   * written back: hold the writes and say so, rather than showing an empty
   * form that looks like the work is gone.
   */
  const [hydrateFailed, setHydrateFailed] = useState<string | null>(null)

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((f) => ({ ...f, [key]: value }))
  }

  const touch = (key: string) => setTouched((t) => ({ ...t, [key]: true }))

  /** Renewal/amendment: pull the prior permit + prefill fields for a business. */
  async function selectBusinessForReuse(selectedId: number | null) {
    setPrefillBusinessId(selectedId)
    setPriorPermitId(null)
    setPrefillNote(null)
    setRenewablePermits([])
    if (!selectedId) {
      // Keep the permit selection: the section map never changes mid-flow.
      setForm((f) => ({ ...EMPTY, permit_type_ids: f.permit_type_ids }))
      return
    }
    setPrefilling(true)
    setSubmitError(null)
    try {
      const result = await businesses.prefill(selectedId, applicationType as 'renewal' | 'amendment')
      const b = result.business
      setForm((f) => ({
        name: b.name,
        trade_name: b.trade_name ?? '',
        registration_type: b.registration_type ?? '',
        registration_number: b.registration_number ?? '',
        tin: b.tin ?? '',
        line1: b.address.line1 ?? '',
        line2: b.address.line2 ?? '',
        barangay_id: b.address.barangay ? String(b.address.barangay.id) : '',
        is_rented: b.is_rented ?? false,
        lessor_name: b.lessor_name ?? '',
        lessor_address: b.lessor_address ?? '',
        lessor_contact: b.lessor_contact ?? '',
        monthly_rental: formatAmountInput(b.monthly_rental ?? ''),
        emergency_contact_name: b.emergency_contact_name ?? '',
        emergency_contact_number: b.emergency_contact_number ?? '',
        latitude: b.address.latitude ?? null,
        longitude: b.address.longitude ?? null,
        lines: b.lines.map((l) => ({
          psic_code_id: l.psic_code.id,
          capitalization: formatAmountInput(l.capitalization ?? ''),
          // Carry over the free text for an "Other (not listed)" trade, or a
          // renewal would silently blank it and block Next.
          line_of_business: l.line_of_business ?? '',
        })),
        /*
         * The prefill's `suggested_permit_type_ids` is ignored. It suggests
         * which CLEARANCES a renewal probably wants, and this filing is the
         * business permit alone — accepting the suggestion would quietly put
         * four offices' fees on it. The suggestion is not wrong, it is just for
         * the LGU Clearances stage, which the applicant reaches with the
         * cost of each one stated on its card.
         */
        permit_type_ids: f.permit_type_ids,
      }))
      // Item 85: the choice of permit is the applicant's to make, so the list
      // arrives unticked. `last_permit` only suggests where to look — it is
      // the newest issued, which is rarely the one about to lapse.
      setRenewablePermits(result.renewable_permits ?? [])
      setPriorPermitId(null)
      if (result.last_permit) {
        setPrefillNote(`Prefilled from your last permit ${result.last_permit.permit_number}.`)
      } else {
        setPrefillNote('Prefilled from your last application.')
      }
    } catch (err) {
      setSubmitError(toApiError(err).message)
      setPrefillBusinessId(null)
    } finally {
      setPrefilling(false)
    }
  }

  /**
   * Item 85 — the renewable permits of a business we did not just pick.
   *
   * A reopened draft already has its business; re-running the full prefill
   * would overwrite the applicant's edits with the registry's copy of them, so
   * this takes the permit list from the same response and nothing else.
   */
  async function loadRenewablePermits(bid: number, type: 'renewal' | 'amendment') {
    setLoadingPermits(true)
    try {
      const result = await businesses.prefill(bid, type)
      setRenewablePermits(result.renewable_permits ?? [])
    } catch {
      // Non-fatal: the picker says it has nothing to offer, and the applicant
      // can still carry on and upload the paper permit.
      setRenewablePermits([])
    } finally {
      setLoadingPermits(false)
    }
  }

  /**
   * Item 50 — name the permit this filing is for.
   *
   * Choosing one used to also tick its clearance in the LGU Section, on the
   * reasoning that renewing a sanitary permit nobody has asked the City Health
   * Office to look at is not a renewal of anything. That reasoning still holds
   * — it just is not this screen's to act on any more. Adding a SANITARY permit
   * type here would put the City Health Office's fees onto the business
   * permit's own Tax Order of Payment, which is the accrual this restructure
   * exists to separate. The renewal is asked for on the LGU Clearances stage,
   * with its fee stated before it is committed to.
   */
  function choosePriorPermit(permit: Permit | null) {
    setPriorPermitId(permit?.id ?? null)
  }

  /** Apply an OCR suggestion into the matching form fields (suggestions only). */
  function applyOcr(s: OcrSuggestions) {
    setForm((f) => ({
      ...f,
      name: s.business_name ?? f.name,
      registration_number: s.registration_number ?? f.registration_number,
    }))
    setOcr(null)
  }

  const permitTypes = refs.data?.permitTypes ?? []
  /*
   * The Mayor's / Business Permit rides along on every application (it is what
   * the application is for), so BPLO always ends up in the routing. The picker
   * below only offers the supporting clearances.
   */
  const businessTypeId = permitTypes.find((pt) => pt.code === BUSINESS_PERMIT_CODE)?.id ?? null
  const barangays: Barangay[] = refs.data?.barangays ?? []
  const psic: PsicCode[] = refs.data?.psic ?? []
  const otherType: DocumentType | undefined = (refs.data?.documentTypes ?? []).find(
    (dt) => dt.code === OTHER_DOC_CODE,
  )
  /*
   * The documents the BUSINESS PERMIT asks for — and only those.
   *
   * This was the union of the document types on every selected permit type,
   * which is precisely why the clearance cards could not be moved later: the
   * list did not exist until they had been picked. The clearances are their
   * own stage now, so the only permit type this filing carries is the Mayor's
   * / Business Permit, and its document types are the whole requirement.
   *
   * Read from the BUSINESS permit type by code rather than from
   * `form.permit_type_ids`, so a draft reopened from before this change — one
   * that still has four clearances attached server-side — shows the business
   * permit's requirements and not a list inherited from a stage it no longer
   * belongs to.
   *
   * The `context` filter stays: a new business has no previous mayor's permit
   * to upload, so demanding one is an unclearable block, not a requirement.
   */
  const requiredDocs = useMemo(() => {
    const businessType = permitTypes.find((pt) => pt.code === BUSINESS_PERMIT_CODE)
    if (!businessType) return []
    const appliesNow = (context?: string) =>
      !context ||
      context === 'all' ||
      context === applicationType ||
      context.toUpperCase() === BUSINESS_PERMIT_CODE

    const map = new Map<number, DocumentType>()
    for (const dt of businessType.document_types) {
      if (appliesNow(dt.context)) map.set(dt.id, dt)
    }
    return [...map.values()]
  }, [permitTypes, applicationType])
  const barangayName = barangays.find((b) => String(b.id) === form.barangay_id)?.name

  /*
   * The first line of business the applicant declared, when they have one. It
   * is chosen on the zoning step itself now (item 69), so by the time the
   * zoning modal opens there is always one to name.
   */
  const declaredLine = psic.find((c) => c.id === form.lines[0]?.psic_code_id)

  /*
   * What the zoning modal says the verdict is ABOUT. The mockup underlines the
   * line of business ("The new business for Cafe"), which is what a zoning
   * decision actually turns on — a use, not a trade name. The business name is
   * the fallback, and on a fresh filing neither exists yet at Part 1, so the
   * sentence still has to read as English with no subject at all.
   *
   * PSIC titles carry the colloquial name in brackets, and that is the half a
   * shop owner recognises: "sari-sari store", not "Retail sale in
   * non-specialized stores (sari-sari store)". Prefer the bracketed name so the
   * sentence reads like the mockup's "Cafe" instead of a statistical class.
   */
  const zoningSubject: string | null =
    declaredLine?.title.match(/\(([^)]+)\)\s*$/)?.[1] ?? declaredLine?.title ?? (form.name || null)

  /*
   * The business as every office sheet carries it is built by the LGU
   * Clearances stage now, from the SAVED application rather than from these
   * form fields. That is strictly better than what stood here: the sheets used
   * to be reachable from the section map before the sections behind them were
   * finished, so the carried-over name and address could legitimately be blank
   * and had to render as "—". By the time that stage opens, the filing has been
   * submitted and paid — there is nothing left to be half-answered.
   */

  /*
   * Frozen when the modal opens rather than tracked live off the pin: the point
   * being reported has to be the point the applicant was told about, and moving
   * the pin behind an open modal would silently change the figures underneath
   * the numbers they are reading. Nulled on close so reopening refetches.
   */
  const [insightsQuery, setInsightsQuery] = useState<LocationInsightsQuery | null>(null)
  const insights = useLocationInsights(insightsQuery)

  /*
   * The step sequence is BASE_PHASES, always, in that order.
   *
   * It used to be computed: the office form sheets slotted in behind the LGU
   * Section that spawned them, so the sequence grew and shrank as clearances
   * were ticked, `step` had to be dragged back inside the array whenever one
   * was removed, and every step needed a name-based key because its index was
   * not stable. All of that machinery existed to serve the clearances, and the
   * clearances are their own stage now.
   */
  const totalParts = BASE_PHASES.length
  const stepIndex = Math.min(step, totalParts - 1)
  const phase: BasePhase = BASE_PHASES[stepIndex]
  const isLast = stepIndex === totalParts - 1

  /* Attach the implicit Mayor's / Business Permit as soon as reference data lands. */
  useEffect(() => {
    if (businessTypeId === null) return
    setForm((f) =>
      f.permit_type_ids.includes(businessTypeId)
        ? f
        : { ...f, permit_type_ids: [businessTypeId, ...f.permit_type_ids] },
    )
  }, [businessTypeId])

  const feeLines = useMemo(
    () =>
      form.lines.map((l) => ({
        id: l.psic_code_id,
        title: psic.find((c) => c.id === l.psic_code_id)?.title ?? `PSIC #${l.psic_code_id}`,
      })),
    [form.lines, psic],
  )

  const priorPermitChoice: Permit | null = useMemo(
    () => renewablePermits.find((p) => p.id === priorPermitId) ?? null,
    [renewablePermits, priorPermitId],
  )

  /*
   * Required fields still missing on ANY step. This used to answer only for
   * the step being displayed, which meant the map had no way of knowing
   * whether a section was finished and fell back to "is it behind us?" — the
   * source of every tick that was showing on an empty section.
   */
  const missingFor = useCallback(
    (p: BasePhase): string[] => {
      switch (p) {
        case 'business': {
          const missing: string[] = []
          if (isReuse && prefillBusinessId === null) {
            missing.push(applicationType === 'renewal' ? 'The business you are renewing' : 'The business you are amending')
          }
          // Item 50/85: a business holds several permits with different expiry
          // dates, so "renew this business" names nothing an office can act on.
          if (applicationType === 'renewal' && renewablePermits.length > 0 && priorPermitId === null) {
            missing.push('Which permit you are renewing')
          }
          /*
           * Items 82/84 — an amendment amending nothing is not a filing. The
           * counter would have to send it back to ask the question the form
           * was supposed to have asked, so it is asked here instead.
           */
          if (applicationType === 'amendment' && !amendmentChosen) {
            missing.push('What is being amended (ownership, location, nature of business, or other)')
          }
          if (!form.name.trim()) missing.push('Business Name')
          if (!form.registration_number.trim()) missing.push('DTI / SEC / CDA Registration Number')
          if (!form.tin.trim()) missing.push('Tax Identification Number (TIN)')
          else if (!tinValid(form.tin)) missing.push('A valid TIN (9 digits, plus branch code)')
          if (!form.registration_type) missing.push('Type of Registration')
          return missing
        }
        case 'address': {
          const missing: string[] = []
          /*
           * Item 69 — the whole Line of Business question is answered here now,
           * and these three checks are the ones the deleted `lines` step used to
           * make. Required on this step, as the mockup marks it, and not merely
           * because Location Insights wants it: the zoning modal this step opens
           * into announces conformity *for a named trade*, and CPDO's locational
           * clearance is a judgment about a use, not about a coordinate.
           */
          if (form.lines.length === 0) missing.push('Line of Business')
          const otherId = psic.find((c) => c.code === OTHER_PSIC_CODE)?.id
          const otherLine = form.lines.find((l) => l.psic_code_id === otherId)
          if (otherLine && !otherLine.line_of_business.trim()) {
            missing.push('Your line of business (typed in, for “Other”)')
          }
          // Capital is what the business tax and the capitalization-based fees
          // are computed from, so a blank one is not a detail.
          if (form.lines.some((l) => !l.capitalization.trim())) {
            missing.push('Capital for every line of business')
          }
          if (!form.line1.trim()) missing.push('House No. & Street Name')
          if (!form.barangay_id) missing.push('Barangay')
          // CPDO rules on the zoning clearance from where the business actually
          // is, so the pin is part of the answer, not a nicety.
          if (form.latitude === null || form.longitude === null) missing.push('A pin on the map')
          /*
           * Item 86 — re-checked here and not only in the click handler. A
           * renewal prefills its coordinates from the business on record and a
           * reopened draft restores whatever was saved, so a pin that never
           * passed through the map's own check can still be sitting on the form.
           */
          else if (!withinMalabon(form.latitude, form.longitude)) {
            missing.push('A pin within Malabon')
          }
          // Only when renting: the API enforces the same three with required_if.
          if (form.is_rented) {
            if (!form.lessor_name.trim()) missing.push("Lessor's Name")
            if (!form.lessor_address.trim()) missing.push("Lessor's Address")
            if (!form.monthly_rental.trim()) missing.push('Monthly Rental')
            else if (!Number.isFinite(Number(plainAmount(form.monthly_rental)))) {
              missing.push('A monthly rental in pesos')
            }
            if (form.lessor_contact.trim() && !phoneValid(form.lessor_contact)) {
              missing.push("A valid Lessor's Contact Number")
            }
          }
          // Inspectors turn up unannounced; somebody has to be reachable.
          if (!form.emergency_contact_name.trim()) missing.push('Emergency Contact Person')
          if (!form.emergency_contact_number.trim()) missing.push('Emergency Contact Number')
          else if (!phoneValid(form.emergency_contact_number)) {
            missing.push('A valid Emergency Contact Number')
          }
          return missing
        }
        // Nothing may be collected until this is ticked, so it blocks step one
        // rather than the submit button seven steps later.
        case 'privacy':
          return consent ? [] : ['Your agreement to the Data Privacy Consent']
        case 'documents':
          return requiredDocs
            .filter((dt) => dt.is_required !== false && !uploaded[dt.id])
            .map((dt) => dt.name)
        case 'fees':
          /*
           * The business permit's questions, and only those. This used to pass
           * whichever clearances had been ticked, which is what made the tax
           * profile grow occupancy and market sections mid-flow — and what
           * stopped the cards from being moved after it. WIRING is what the
           * clearances now carry their own fees through: applying re-runs
           * `FeeCalculator::assess`, which gates every rule on the permit types
           * on the application, so a clearance's lines appear when and only
           * when it is applied for on its own stage.
           */
          return feeProfileMissing(feeDraft, {
            applicationType,
            permitCodes: [BUSINESS_PERMIT_CODE],
            lines: feeLines,
          })
        case 'review':
          return []
      }
    },
    [
      form,
      requiredDocs,
      uploaded,
      consent,
      feeDraft,
      applicationType,
      feeLines,
      psic,
      isReuse,
      prefillBusinessId,
      priorPermitId,
      renewablePermits,
      amendmentChosen,
    ],
  )

  /** What is still missing on the step being displayed. */
  const stepMissing: string[] = useMemo(() => missingFor(phase), [missingFor, phase])

  /*
   * Which sections are finished, asked of every section rather than inferred
   * from where the applicant happens to be standing. Review is the one section
   * with nothing of its own to fill in, so it counts as done exactly when
   * everything it reviews is.
   */
  const stepComplete: boolean[] = useMemo(() => {
    const flags = BASE_PHASES.map((p) => missingFor(p).length === 0)
    const last = flags.length - 1
    if (last >= 0) flags[last] = flags.slice(0, last).every(Boolean)
    return flags
  }, [missingFor])

  /**
   * True when jumping forward to `index` would step over an unfinished
   * section. Next has always refused to leave an incomplete step; the map used
   * to check only the step you were on, so a hop back and a hop forward walked
   * straight past everything in between — and every section skipped that way
   * came out the other side wearing a tick.
   */
  function jumpBlocked(index: number): boolean {
    if (index <= stepIndex) return false
    for (let i = stepIndex; i < index; i++) if (!stepComplete[i]) return true
    return false
  }

  const fieldErrors = {
    name: touched.name && !form.name.trim() ? 'Enter your business name.' : '',
    registration_number:
      touched.registration_number && !form.registration_number.trim()
        ? 'Enter your DTI, SEC, or CDA registration number.'
        : '',
    tin: form.tin.trim()
      ? tinValid(form.tin)
        ? ''
        : TIN_ERROR
      : touched.tin
        ? 'Enter your Tax Identification Number.'
        : '',
    line1: touched.line1 && !form.line1.trim() ? 'Enter your street address.' : '',
    barangay_id: touched.barangay_id && !form.barangay_id ? 'Choose your barangay.' : '',
    lessor_name:
      touched.lessor_name && form.is_rented && !form.lessor_name.trim()
        ? "Enter your lessor's name, or set the premises to owner-occupied."
        : '',
    lessor_address:
      touched.lessor_address && form.is_rented && !form.lessor_address.trim()
        ? "Enter your lessor's address, or set the premises to owner-occupied."
        : '',
    lessor_contact:
      form.lessor_contact.trim() && !phoneValid(form.lessor_contact) ? PHONE_ERROR : '',
    monthly_rental: form.monthly_rental.trim()
      ? Number.isFinite(Number(plainAmount(form.monthly_rental)))
        ? ''
        : 'Enter the monthly rental as an amount in pesos.'
      : touched.monthly_rental && form.is_rented
        ? 'Enter the monthly rental, or set the premises to owner-occupied.'
        : '',
    emergency_contact_name:
      touched.emergency_contact_name && !form.emergency_contact_name.trim()
        ? 'Enter someone we can reach if an inspector cannot reach you.'
        : '',
    emergency_contact_number: form.emergency_contact_number.trim()
      ? phoneValid(form.emergency_contact_number)
        ? ''
        : PHONE_ERROR
      : touched.emergency_contact_number
        ? 'Enter a contact number for that person.'
        : '',
  }

  function businessPayload(): BusinessPayload {
    return {
      name: form.name.trim(),
      trade_name: form.trade_name.trim() || undefined,
      registration_type: form.registration_type || undefined,
      registration_number: form.registration_number.trim() || undefined,
      tin: form.tin.trim() || undefined,
      is_rented: form.is_rented,
      // Only sent when renting: an owner-occupied shop has no lessor, and the
      // API requires these precisely and only when is_rented is true.
      lessor_name: form.is_rented ? form.lessor_name.trim() || undefined : undefined,
      lessor_address: form.is_rented ? form.lessor_address.trim() || undefined : undefined,
      lessor_contact: form.is_rented ? form.lessor_contact.trim() || undefined : undefined,
      monthly_rental: form.is_rented ? plainAmount(form.monthly_rental) || undefined : undefined,
      emergency_contact_name: form.emergency_contact_name.trim() || undefined,
      emergency_contact_number: form.emergency_contact_number.trim() || undefined,
      address: {
        line1: form.line1.trim(),
        line2: form.line2.trim() || undefined,
        barangay_id: Number(form.barangay_id),
        latitude: form.latitude ?? undefined,
        longitude: form.longitude ?? undefined,
      },
      // The free-text line rides on the same payload; the API stores it on
      // business_lines.line_of_business (contract addition, hence the cast).
      lines: form.lines.map((l) => ({
        psic_code_id: l.psic_code_id,
        // Typed as "1,000,000", stored as 1000000.
        capitalization: plainAmount(l.capitalization) || undefined,
        line_of_business: l.line_of_business.trim() || undefined,
      })) as BusinessPayload['lines'],
    }
  }

  /**
   * Create the business + application draft if it does not exist yet (throws
   * on API errors so callers surface one message). Reused businesses get their
   * edited fields pushed at the same time.
   */
  async function ensureDraftRaw(): Promise<number> {
    if (applicationId) return applicationId
    let bid = businessId ?? prefillBusinessId
    if (!bid) {
      bid = (await businesses.create(businessPayload())).id
    } else {
      await businesses.update(bid, businessPayload())
    }
    setBusinessId(bid)
    const app = await applications.create({
      business_id: bid,
      application_type: applicationType,
      title: title.trim() || undefined,
      payment_mode: paymentMode,
      permit_type_ids: form.permit_type_ids,
      ...(priorPermitId ? { prior_permit_id: priorPermitId } : {}),
      ...amendmentPayload(),
    })
    setApplicationId(app.id)
    return app.id
  }

  /**
   * The amendment answers on the wire, or nothing at all.
   *
   * Sent only for an amendment: the API zeroes these columns for any other
   * type, and a `new` filing posting `amendment_ownership: false` would be
   * saying no to a question its form never asked.
   */
  function amendmentPayload(): AmendmentAnswers {
    if (applicationType !== 'amendment') return {}

    return {
      amendment_ownership: amendment.ownership,
      amendment_location: amendment.location,
      amendment_nature: amendment.nature,
      amendment_other: amendment.other.trim() || null,
    }
  }

  /**
   * Persist whatever the CURRENT step owns before leaving it, so every Next
   * (and every map jump) saves: the business fields and the fee profile both
   * round-trip through the API.
   *
   * The permit re-sync branch is gone with the cards. It existed because the
   * office form sheets 422 with "not part of this application" until the new
   * permit list has been pushed, and there is no permit list to push any more:
   * the Mayor's / Business Permit is attached once, on creation, and never
   * changes while the wizard is open.
   */
  async function persistOnLeave(): Promise<boolean> {
    // Same rule as autosave: a draft we failed to read is a draft we must not
    // write. Stepping through the wizard cannot be allowed to launder a blank
    // form into a save.
    if (hydrateFailed) return false
    inFlightRef.current = true
    setSaving(true)
    setSubmitError(null)
    try {
      if (phase === 'address' || phase === 'business') {
        if (applicationId) {
          const bid = businessId ?? prefillBusinessId
          if (bid) await businesses.update(bid, businessPayload())
        } else if (phase === 'business' && canCreateDraft) {
          // The last section that describes the business, and so the earliest
          // point a draft can legally exist (item 69 folded Line of Business
          // into Location & Zoning, which now runs before this one). There has
          // to be something to attach documents to by the time uploads start,
          // even if the autosave debounce has not fired yet.
          await ensureDraftRaw()
        }
      } else if (phase === 'fees') {
        const id = await ensureDraftRaw()
        await applications.update(id, {
          fee_profile: buildFeeProfile(feeDraft, {
            applicationType,
            permitCodes: [BUSINESS_PERMIT_CODE],
            lineIds: form.lines.map((l) => l.psic_code_id),
          }),
        })
      }
      return true
    } catch (err) {
      setSubmitError(toApiError(err).message)
      return false
    } finally {
      inFlightRef.current = false
      setSaving(false)
    }
  }

  async function advance() {
    const ok = await persistOnLeave()
    if (!ok) return
    const target = Math.min(stepIndex + 1, totalParts - 1)
    setStep(target)
    markVisited(BASE_PHASES[target])
  }

  async function next() {
    if (stepMissing.length > 0) return
    // Zoning result (p30) — the conformity message plus Location Insights (§5).
    if (phase === 'address') {
      // stepMissing already guarantees a pin, so the coordinates are present.
      if (form.latitude !== null && form.longitude !== null) {
        setInsightsQuery({
          latitude: form.latitude,
          longitude: form.longitude,
          psicCodeId: form.lines[0]?.psic_code_id ?? null,
          businessId,
        })
      }
      setShowZoning(true)
      return
    }
    await advance()
  }

  /** Both modal exits go through here so the frozen query never outlives the modal. */
  function closeZoning() {
    setShowZoning(false)
    setInsightsQuery(null)
  }

  function back() {
    setStep((s) => Math.max(s - 1, 0))
  }

  /** Jump to any already-opened section from the map (persisting first). */
  async function goTo(index: number) {
    if (saving || index === stepIndex) return
    if (!visited.includes(BASE_PHASES[index])) return
    // Moving forward re-checks every section being skipped, not just this one.
    if (jumpBlocked(index)) return
    const ok = await persistOnLeave()
    if (!ok) return
    setStep(index)
    markVisited(BASE_PHASES[index])
  }

  /*
   * A draft is an application against a business, so the API cannot hold one
   * until the business itself is valid: name, registration, TIN, a line of
   * business and an address. Everything typed before that lives in this
   * component and is pushed by the first autosave that can run, so nothing
   * the applicant typed is dropped — it is just not on the server yet, which
   * is exactly what the header says while it waits.
   */
  const canCreateDraft =
    form.permit_type_ids.length > 0 &&
    form.name.trim() !== '' &&
    form.registration_type !== '' &&
    form.registration_number.trim() !== '' &&
    tinValid(form.tin) &&
    form.lines.length > 0 &&
    form.line1.trim() !== '' &&
    form.barangay_id !== '' &&
    (!form.is_rented ||
      (form.lessor_name.trim() !== '' &&
        form.lessor_address.trim() !== '' &&
        form.monthly_rental.trim() !== '')) &&
    /*
     * Item 50: prefill fills a renewal's whole business section in one go, so
     * without this the draft would be created (and its prior permit fixed)
     * a second after the business is picked — before the applicant has said
     * which of its permits they are renewing.
     */
    (applicationType !== 'renewal' || renewablePermits.length === 0 || priorPermitId !== null) &&
    /*
     * Items 82/84: same reasoning for an amendment. Prefill fills the business
     * section in one go, so without this a draft — and its amendment columns,
     * all false — would be written a second after the business is picked and
     * before the applicant has said what they are amending.
     */
    (applicationType !== 'amendment' || amendmentChosen)

  /** Push every section entered so far in one go. */
  async function autosave(target: string) {
    // A step change is already writing; come back once it has finished.
    if (inFlightRef.current) {
      setAutosaveNonce((n) => n + 1)
      return
    }
    inFlightRef.current = true
    setSaving(true)
    try {
      const hadDraft = applicationId !== null
      const id = await ensureDraftRaw()
      const feeProfile = buildFeeProfile(feeDraft, {
        applicationType,
        permitCodes: [BUSINESS_PERMIT_CODE],
        lineIds: form.lines.map((l) => l.psic_code_id),
      })
      if (hadDraft) {
        const bid = businessId ?? prefillBusinessId
        if (bid) await businesses.update(bid, businessPayload())
        await applications.update(id, {
          title: title.trim(),
          permit_type_ids: form.permit_type_ids,
          fee_profile: feeProfile,
          payment_mode: paymentMode,
          // Items 82/84: what is being amended can change while the draft is
          // open, so it rides on every autosave, not only on creation.
          ...amendmentPayload(),
        })
        // Which permit is being renewed can change after the draft exists, and
        // it is not part of the general application update (item 50).
        if (isReuse) await applications.setPriorPermit(id, priorPermitId)
      } else {
        await applications.update(id, { fee_profile: feeProfile, payment_mode: paymentMode })
      }
      // The office-form save loop went with the cards: no sheet is filled in
      // here any more, so there is nothing of that shape left to push.
      savedSnapshotRef.current = target
      setDirty(false)
      setSubmitError(null)
    } catch (err) {
      // Leave the draft dirty: the indicator keeps saying so, and the next
      // edit tries again.
      setSubmitError(toApiError(err).message)
    } finally {
      inFlightRef.current = false
      setSaving(false)
    }
  }

  /*
   * Everything a draft owns, as one comparable string: when this changes, the
   * applicant has typed something the server does not have yet.
   */
  const snapshot = useMemo(
    () =>
      JSON.stringify({
        title,
        form,
        feeDraft,
        paymentMode,
        applicationType,
        priorPermitId,
        // Items 82/84: ticking a box is an edit, so autosave has to see it.
        amendment,
      }),
    [title, form, feeDraft, paymentMode, applicationType, priorPermitId, amendment],
  )
  const syncedRef = useRef(false)

  /*
   * Autosave (there is no Save draft button): every edit is debounced, then
   * written as soon as a draft may legally exist. `dirty` is what the header
   * reads, so the saved indicator can never claim more than actually happened.
   */
  useEffect(() => {
    if (hydrating || refs.loading || tracking || hydrateFailed) return
    if (!syncedRef.current) {
      syncedRef.current = true
      // A reopened draft opens in sync with what the server already holds.
      if (applicationId) {
        savedSnapshotRef.current = snapshot
        return
      }
    }
    if (savedSnapshotRef.current === snapshot) return
    setDirty(true)
    if (!applicationId && !canCreateDraft) return
    const timer = setTimeout(() => void autosave(snapshot), AUTOSAVE_DELAY_MS)
    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [snapshot, autosaveNonce, applicationId, canCreateDraft, hydrating, refs.loading, tracking, hydrateFailed])

  /* Closing the tab mid-form should not silently take the answers with it. */
  useEffect(() => {
    if (!dirty) return
    const warn = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  /** "Clear All" (p35) — clears the inputs for the current part only. */
  function clearCurrentPart() {
    if (phase === 'business') {
      setForm((f) => ({ ...f, name: '', trade_name: '', registration_type: '', registration_number: '', tin: '' }))
      if (isReuse) {
        setPrefillBusinessId(null)
        setPriorPermitId(null)
        setPrefillNote(null)
        setRenewablePermits([])
        // Items 82/84: the amendment block is part of this section, so Clear
        // All has to take it too or it would clear the fields around an answer
        // and leave the answer standing.
        setAmendment(EMPTY_AMENDMENT)
      }
    } else if (phase === 'address') {
      // The lines of business are inputs of this part now (item 69), so
      // "clear all inputs for this part" has to take them with it.
      setForm((f) => ({
        ...f,
        lines: [],
        line1: '',
        line2: '',
        barangay_id: '',
        latitude: null,
        longitude: null,
      }))
      setPinError(null)
    } else if (phase === 'privacy') {
      /*
       * Consent is the input of THIS part, and this branch used to say
       * `documents`. It was correct when the consent tick sat at the foot of
       * Documentary Requirements; it was left behind when consent moved to the
       * first step so that it precedes collection, and the reorder here is what
       * made it visible. The effect was that Clear All on the documents step
       * silently untick a consent given five steps earlier — the applicant is
       * told "inputs for this part" and loses an answer from another one — and
       * Clear All on the consent step itself did nothing at all.
       *
       * The uploaded documents deliberately get no branch: each has its own
       * Remove control, and deleting files off the server is not something
       * "clear the inputs on this part" should do behind one confirm.
       */
      setConsent(false)
    } else if (phase === 'fees') {
      setFeeDraft(EMPTY_FEE_PROFILE)
    }
    setTouched({})
    setShowClear(false)
  }

  async function handleUpload(docTypeId: number, file: File) {
    if (!applicationId) return
    const rejection = fileRejection(file)
    if (rejection) {
      setSubmitError(rejection)
      return
    }
    setUploadingType(docTypeId)
    setSubmitError(null)
    try {
      // Replacing a requirement: the old attachment goes, so the officer never
      // sees two files for one line.
      const previous = uploaded[docTypeId]
      const doc = await documents.upload(applicationId, docTypeId, file)
      if (previous) {
        await documents.remove(applicationId, previous.id).catch(() => {
          /* The new file is already attached; a stale one is not worth a stop. */
        })
      }
      setUploaded((u) => ({ ...u, [docTypeId]: { id: doc.id, name: file.name, size: file.size } }))
      // OCR-lite: surface any suggestions from the upload response (v2).
      if (doc.ocr_suggestions && Object.keys(doc.ocr_suggestions).length > 0) {
        setOcr(doc.ocr_suggestions)
      }
    } catch (err) {
      setSubmitError(uploadErrorMessage(err))
    } finally {
      setUploadingType(null)
    }
  }

  /*
   * ── Item 59 · a clearance the applicant already holds ─────────────────
   *
   * `submitHeldPermit`, `removeHeldPermit` and the effect that flushed files
   * chosen before a draft existed all moved to the LGU Clearances stage. Two
   * of the three only existed because the LGU Section ran long before there
   * was enough on the form to create a draft, so a file had to be queued in the
   * browser and attached later. That stage opens after the first payment, by
   * which point the application unarguably exists, and a file can simply be
   * posted to it.
   */

  /** "Other Requirements": each upload APPENDS, so multiple files are kept. */
  async function handleOtherUpload(file: File) {
    if (!applicationId || !otherType) return
    const rejection = fileRejection(file)
    if (rejection) {
      setSubmitError(rejection)
      return
    }
    setUploadingType(otherType.id)
    setSubmitError(null)
    try {
      const doc = await documents.upload(applicationId, otherType.id, file)
      setOtherDocs((d) => [...d, { id: doc.id, name: file.name, size: file.size }])
    } catch (err) {
      setSubmitError(uploadErrorMessage(err))
    } finally {
      setUploadingType(null)
    }
  }

  /**
   * Take an attachment back off the draft (tester checklist item 47). The
   * stored file is deleted server-side first: a file that disappears from the
   * screen but stays in the record is not removed, it is hidden.
   */
  async function handleRemoveDocument(doc: UploadedFile, docTypeId?: number) {
    if (!applicationId) return
    setRemovingDoc(doc.id)
    setSubmitError(null)
    try {
      await documents.remove(applicationId, doc.id)
      if (docTypeId !== undefined) {
        setUploaded((u) => {
          const next = { ...u }
          delete next[docTypeId]
          return next
        })
      } else {
        setOtherDocs((d) => d.filter((f) => f.id !== doc.id))
      }
    } catch (err) {
      setSubmitError(toApiError(err).message)
    } finally {
      setRemovingDoc(null)
    }
  }

  async function submit() {
    if (!applicationId) return
    setSaving(true)
    setSubmitError(null)
    try {
      const app = await applications.submit(applicationId)
      setTracking(app.tracking_id)
    } catch (err) {
      setSubmitError(toApiError(err).message)
    } finally {
      setSaving(false)
    }
  }

  /*
   * Reopen a saved draft (?draft=ID): restore the business fields, permit
   * selection, fee profile, uploaded documents, and make the whole section
   * map navigable. Office-form payloads load in the effect below.
   */
  useEffect(() => {
    const draftId = Number(draftIdParam)
    const refData = refs.data
    if (!draftIdParam || Number.isNaN(draftId) || !refData || hydratedRef.current) return
    hydratedRef.current = true
    let active = true
    ;(async () => {
      try {
        const app = await applications.get(draftId)
        if (!active) return
        if (app.status !== 'draft') {
          navigate(`/applications/${app.id}`, { replace: true })
          return
        }
        /*
         * A draft carries the Mayor's / Business Permit and nothing else.
         *
         * This used to restore whichever permit types the draft had, which is
         * right while the clearances are part of the filing and wrong now that
         * they are not. A draft started before this change can have four
         * clearances attached server-side; restoring them here would put their
         * documents back into Documentary Requirements, their questions back
         * into the tax profile, and their fees back onto a Tax Order of Payment
         * that is supposed to describe the business permit alone — and the next
         * autosave would write the lot straight back. Those clearances are not
         * lost: they belong to the LGU Clearances stage, which reads the
         * application's permit types for itself.
         */
        const pts = refData.permitTypes
        const bizId = pts.find((pt) => pt.code === BUSINESS_PERMIT_CODE)?.id
        const ids = bizId === undefined ? [] : [bizId]
        const b = app.business
        const lineIds = (b.lines ?? []).map((l) => l.psic_code.id)
        setApplicationType(app.application_type)
        setApplicationId(app.id)
        setTitle(app.title ?? '')
        setBusinessId(b.id)
        if (app.application_type !== 'new') setPrefillBusinessId(b.id)
        /*
         * Items 82/84 — restore what the applicant said they were amending.
         * Without this the boxes reopen blank and the next autosave writes
         * that blank over the answer, which is the draft losing it silently.
         */
        setAmendment(
          app.amendments
            ? {
                ownership: app.amendments.ownership,
                location: app.amendments.location,
                nature: app.amendments.nature,
                other: app.amendments.other ?? '',
              }
            : EMPTY_AMENDMENT,
        )
        setForm({
          name: b.name ?? '',
          trade_name: b.trade_name ?? '',
          registration_type: b.registration_type ?? '',
          registration_number: b.registration_number ?? '',
          tin: b.tin ?? '',
          line1: b.address?.line1 ?? '',
          line2: b.address?.line2 ?? '',
          barangay_id: b.address?.barangay ? String(b.address.barangay.id) : '',
          is_rented: b.is_rented ?? false,
          lessor_name: b.lessor_name ?? '',
          lessor_address: b.lessor_address ?? '',
          lessor_contact: b.lessor_contact ?? '',
          monthly_rental: formatAmountInput(b.monthly_rental ?? ''),
          emergency_contact_name: b.emergency_contact_name ?? '',
          emergency_contact_number: b.emergency_contact_number ?? '',
          latitude: b.address?.latitude ?? null,
          longitude: b.address?.longitude ?? null,
          lines: (b.lines ?? []).map((l) => ({
            psic_code_id: l.psic_code.id,
            capitalization: formatAmountInput(l.capitalization ?? ''),
            // Free text typed against "Other (not listed)". Restoring it is what
            // stops a reopened draft from making the applicant type it again.
            line_of_business: l.line_of_business ?? '',
          })),
          permit_type_ids: ids,
        })
        setPaymentMode(app.payment_mode === 'quarterly' ? 'quarterly' : 'annual')
        setFeeDraft(feeProfileToDraft(app.fee_profile, lineIds))
        // Restore uploaded documents by document-type code.
        const codeToId = new Map<string, number>()
        for (const dt of refData.documentTypes) codeToId.set(dt.code, dt.id)
        const restored: Record<number, UploadedFile> = {}
        const others: UploadedFile[] = []
        for (const doc of app.documents ?? []) {
          const code = doc.document_type?.code
          if (!code) continue
          const file = { id: doc.id, name: doc.original_filename, size: doc.size_bytes }
          if (code.startsWith(HELD_DOC_PREFIX)) {
            // "HELD_SANITARY" is a clearance copy, not a documentary
            // requirement of the business permit. It belongs to the LGU
            // Clearances stage; skipped here so it is not mistaken for one.
            continue
          }
          if (code === OTHER_DOC_CODE) {
            others.push(file)
          } else {
            const dtId = codeToId.get(code)
            if (dtId != null) restored[dtId] = file
          }
        }
        setUploaded(restored)
        setOtherDocs(others)
        /*
         * Every section of a saved draft has been opened, so the whole map is
         * clickable. Which of them count as DONE is a separate question, asked
         * of the answers themselves each render — a draft saved with no
         * documents uploaded shows Documentary Requirements unticked, which is
         * the truth about it.
         */
        setVisited([...BASE_PHASES])
        // Item 50: which permit this renewal is for, chosen when the draft was
        // started and re-choosable now — which is why the list has to be here
        // too (item 85), or reopening a draft would offer nothing to change it
        // to and the choice would look like it had been lost.
        if (app.application_type !== 'new') {
          void loadRenewablePermits(b.id, app.application_type)
          void applications
            .priorPermit(app.id)
            .then((r) => {
              if (active) setPriorPermitId(r.prior_permit_id)
            })
            .catch(() => {
              /* Non-fatal: the picker just opens with nothing chosen. */
            })
        }
      } catch (err) {
        // Includes anything thrown while unpacking the response, not just the
        // request: a half-restored wizard is the dangerous case, because it
        // holds the draft's ids and none of its answers.
        if (active) setHydrateFailed(toApiError(err).message)
      } finally {
        if (active) setHydrating(false)
      }
    })()
    return () => {
      active = false
    }
  }, [draftIdParam, refs.data, navigate])

  /*
   * The office-form payloads are loaded by the LGU Clearances stage now, which
   * is where the sheets are filled in. Nothing in this wizard reads them.
   */

  /*
   * Item 72 — Business Structure is not a second question.
   *
   * "Type of Registration" on the business section and "Business Structure" on
   * the tax profile are the same fact under two names: a sole proprietorship is
   * registered with DTI and taxed as one, and no applicant has ever answered
   * them differently on purpose. So the registration type IS the structure, and
   * the tax profile shows it read-only instead of asking again.
   *
   * It has to be mirrored on every change, not seeded once into a blank: the
   * field is read-only on that step now, so a structure left behind by an
   * edited registration type would be wrong and unfixable from where it is
   * shown. Ungated by `phase` for the same reason — the applicant can jump back
   * to Business Information from the section map, change it, and jump forward
   * past the fee step to Review without ever landing on 'fees'.
   */
  useEffect(() => {
    if (!form.registration_type) return
    /*
     * Only the four structures get mirrored.
     *
     * A renewal or amendment prefills `registration_type` from the business on
     * record, and on real rows that column holds the REGISTERING AGENCY —
     * "DTI", "SEC", "CDA" — rather than a structure. BusinessController's
     * formOfOrganization documents the same mismatch from the other side.
     *
     * Mirrored blindly, "DTI" lands in fee_profile.business_structure, which
     * accepts only the four, and from then on EVERY autosave on the filing
     * answers 422. The failure is silent in the worst way: the applicant is
     * told the draft is unsaved, keeps typing, and nothing they enter after
     * picking their business ever reaches the server. Renewals and amendments
     * were the only filings affected, because they are the only ones that
     * prefill this field instead of asking for it.
     *
     * Skipping leaves the structure blank, which is the honest state — the
     * applicant is asked for their Type of Registration on this same step, and
     * that picker offers exactly the four, so answering it fills this in.
     */
    if (!REGISTRATION_TYPES.some((rt) => rt.value === form.registration_type)) return
    setFeeDraft((d) =>
      d.business_structure === form.registration_type
        ? d
        : { ...d, business_structure: form.registration_type },
    )
  }, [form.registration_type])

  /*
   * Entering the Business & Tax Profile step: seed each line's capitalization
   * from what was declared against that line in Location & Zoning (once,
   * without clobbering anything the user already typed here).
   */
  useEffect(() => {
    if (phase !== 'fees') return
    setFeeDraft((d) => {
      const categories = { ...d.categories }
      let seeded = false
      for (const line of form.lines) {
        if (!categories[line.psic_code_id]) {
          categories[line.psic_code_id] = {
            category: '',
            gross_sales: '',
            capitalization: line.capitalization ?? '',
          }
          seeded = true
        }
      }
      return seeded ? { ...d, categories } : d
    })
  }, [phase, form.lines])

  /* Success screen after submit (kept). */
  if (tracking) {
    return (
      <div className="mx-auto max-w-lg py-10 text-center">
        <span className="mx-auto flex h-16 w-16 items-center justify-center text-s-green">
          <CheckCircleFilledIcon size={64} />
        </span>
        <h1 className="mt-4 text-2xl font-bold text-ink">Application submitted</h1>
        <p className="mt-2 text-sm text-ink-secondary">
          Keep this tracking ID. You can follow every step of processing on your Track page.
        </p>
        <p className="display-serif mt-6 rounded-2xl bg-white px-4 py-4 text-xl text-ink shadow-card">
          {tracking}
        </p>
        <div className="mt-7 flex justify-center gap-3">
          <PillButton onClick={() => navigate(`/applications/${applicationId}`)}>
            Track this application
          </PillButton>
          <PillButton className="bg-white !text-royal border-2 border-royal hover:bg-royal-tint" onClick={() => navigate('/applications')}>
            All applications
          </PillButton>
        </div>
      </div>
    )
  }

  if (refs.loading || hydrating) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-6 w-64" />
        <Skeleton className="h-80 w-full rounded-lg" />
      </div>
    )
  }
  if (refs.error) {
    return <Alert variant="error" title="We couldn’t start a new application">{toApiError(refs.error).message}</Alert>
  }
  /*
   * The reopen failed. Every field would read blank, which is the one thing
   * this screen must never imply, so say what happened instead and offer the
   * way back in. The saved draft is untouched — nothing was written.
   */
  if (hydrateFailed) {
    return (
      <div className="mx-auto max-w-lg py-10">
        <Alert variant="error" title="We couldn’t open this draft">
          {hydrateFailed} Your saved draft has not been changed.
        </Alert>
        <div className="mt-6 flex justify-center gap-3">
          <PillButton onClick={() => window.location.reload()}>Try again</PillButton>
          <PillButton
            className="border-2 border-royal bg-white !text-royal hover:bg-royal-tint"
            onClick={() => navigate('/drafts')}
          >
            Back to drafts
          </PillButton>
        </div>
      </div>
    )
  }

  const part = stepIndex + 1

  return (
    <div className="mx-auto max-w-5xl pb-4">
      {/* ── Persistent wizard chrome (p32/p34) ─────────────────────────── */}
      <div className="mb-5 flex items-center gap-4">
        <ClipboardIcon size={34} className="shrink-0 text-royal" />
        {/*
          Name the filing, not the business: one business can have three
          applications open, and "Nena's Sari-Sari Store" three times over is
          not a list anyone can use. Blank keeps the business name.

          Item 70 — the fallback placeholder read "Title of Application", which
          is the label back again in grey and tells a first-time applicant
          nothing about what to type. An example of a filing name does.
        */}
        <label className="min-w-0 flex-1">
          <span className="sr-only">Application title</span>
          <input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            maxLength={120}
            placeholder={form.name.trim() || 'e.g. 2026 renewal — Catmon branch'}
            className="w-full max-w-md truncate rounded-md border border-input-border bg-white px-3 py-1.5 text-xl font-bold text-ink placeholder:font-bold placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
          />
        </label>
        <span className="flex shrink-0 items-center gap-2">
          {saving ? (
            <span className="text-xs italic text-ink-muted">Saving…</span>
          ) : applicationId && !dirty ? (
            <>
              <CloudSavedIcon />
              <span className="text-xs italic text-ink-muted">All Changes Saved</span>
            </>
          ) : (
            <span className="text-xs italic text-ink-muted">
              {dirty && (applicationId || canCreateDraft) ? 'Unsaved changes' : 'Not saved yet'}
            </span>
          )}
        </span>
        <button
          type="button"
          onClick={() => setShowClear(true)}
          className="shrink-0 text-sm font-semibold text-royal underline underline-offset-2 hover:text-royal-hover"
        >
          Clear All
        </button>
      </div>

      {/* ── Full section map: every step this application requires ─────── */}
      <ol className="mb-8 flex flex-wrap gap-2" aria-label="Application sections">
        {BASE_PHASES.map((p, i) => {
          const label = BASE_LABELS[p]
          const current = i === stepIndex
          const opened = visited.includes(p)
          const blocked = jumpBlocked(i)
          // A tick says "this section is finished", not "you have walked past
          // it": it comes from the answers, so clearing a section takes its
          // tick with it and a section skipped over never gets one.
          const done = opened && stepComplete[i]
          return (
            <li key={p}>
              <button
                type="button"
                onClick={() => void goTo(i)}
                disabled={!opened || blocked || current}
                aria-current={current ? 'step' : undefined}
                title={
                  blocked && opened ? 'Finish the sections before this one first.' : undefined
                }
                className={`flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors ${
                  current
                    ? 'border-royal bg-royal text-white'
                    : opened && !blocked
                      ? 'border-royal/40 bg-white text-royal hover:bg-royal-tint'
                      : 'border-input-border bg-white text-ink-muted'
                }`}
              >
                <span className="tnum">{i + 1}</span>
                {label}
                {done && (
                  <>
                    <CheckIcon size={12} />
                    <span className="sr-only">(complete)</span>
                  </>
                )}
              </button>
            </li>
          )
        })}
      </ol>
      {submitError && (
        <div className="mb-4">
          <Alert variant="error">{submitError}</Alert>
        </div>
      )}

      {/*
        The LGU Section's six clearance cards used to sit here. They are a
        stage of their own now, reached after the first payment clears
        (ClearanceStagePage) — see docs/clearances-after-payment.md. The
        Mayor's / Business Permit is still neither offered nor explained: it is
        the outcome of this whole application, so it is always attached
        (permit_type_ids, by the effect above) and BPLO always receives the file.
      */}

      {/* ── Business information (form sheet, p32) ─────────────────────── */}
      {phase === 'business' && (
        <FormSheet meta={typeMeta}>
          <SectionMarker letter="A" label="Business Information & Registration" />
          {isReuse && (
            <div className="mt-4 rounded-lg border border-royal/30 bg-royal-tint px-4 py-4">
              <FieldLabel required>Which business are you {applicationType === 'renewal' ? 'renewing' : 'amending'}?</FieldLabel>
              <select
                className={inputCls}
                value={prefillBusinessId ?? ''}
                onChange={(e) => void selectBusinessForReuse(e.target.value ? Number(e.target.value) : null)}
                disabled={prefilling || ownedBusinesses.loading}
              >
                <option value="">
                  {ownedBusinesses.loading ? 'Loading your businesses…' : 'Select a business…'}
                </option>
                {(ownedBusinesses.data ?? []).map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.name}
                  </option>
                ))}
              </select>
              {prefilling && <p className="mt-2 text-xs text-ink-secondary">Prefilling…</p>}
              {prefillNote && (
                <p className="mt-2 text-xs font-medium text-royal">{prefillNote}</p>
              )}
              {!prefilling && !ownedBusinesses.loading && (ownedBusinesses.data ?? []).length === 0 && (
                <p className="mt-2 text-xs text-ink-secondary">
                  You have no registered businesses yet. Start a new application instead.
                </p>
              )}

              {/* Item 50 — which permit, not just which business. */}
              {prefillBusinessId !== null && !prefilling && (
                <div className="mt-5">
                  <FieldLabel required={applicationType === 'renewal' && renewablePermits.length > 0}>
                    Which permit are you {applicationType === 'renewal' ? 'renewing' : 'amending'}?
                  </FieldLabel>
                  {loadingPermits ? (
                    <p className="text-xs text-ink-secondary">Loading this business’s permits…</p>
                  ) : renewablePermits.length === 0 ? (
                    <p className="text-xs text-ink-secondary">
                      This business has no permit issued through BizTrack yet. Carry on, and upload
                      your paper permit under Documentary Requirements.
                    </p>
                  ) : (
                    <ul
                      role="radiogroup"
                      aria-label={`Which permit are you ${applicationType === 'renewal' ? 'renewing' : 'amending'}?`}
                      className="divide-y divide-line overflow-hidden rounded-lg border border-input-border bg-white"
                    >
                      {renewablePermits.map((p) => {
                        const chosen = priorPermitId === p.id
                        const days = p.days_until_expiry
                        // Never colour alone: the word says expired or not.
                        const state =
                          days === null
                            ? null
                            : days < 0
                              ? { label: `Expired ${formatDate(p.valid_until)}`, cls: 'text-s-red' }
                              : days <= 60
                                ? { label: `Expires soon · ${formatDate(p.valid_until)}`, cls: 'text-ink' }
                                : { label: `Valid to ${formatDate(p.valid_until)}`, cls: 'text-ink-secondary' }
                        return (
                          // Presentational so the radios are the radiogroup's
                          // own children, not list items wrapping them.
                          <li key={p.id} role="presentation">
                            <button
                              type="button"
                              role="radio"
                              aria-checked={chosen}
                              onClick={() => choosePriorPermit(chosen ? null : p)}
                              className={`flex w-full items-center gap-3 px-4 py-3 text-left transition-colors ${
                                chosen ? 'bg-input' : 'hover:bg-royal-tint'
                              }`}
                            >
                              <span
                                className={`h-4 w-4 shrink-0 rounded-full border-2 ${
                                  chosen ? 'border-royal bg-royal' : 'border-input-border bg-white'
                                }`}
                              />
                              <span className="min-w-0 flex-1">
                                <span className="block text-sm font-medium text-ink">
                                  {p.permit_type?.name ?? 'Permit'}
                                </span>
                                <span className="tnum block text-xs text-ink-secondary">
                                  {p.permit_number}
                                </span>
                              </span>
                              {state && (
                                <span className={`shrink-0 text-xs font-semibold ${state.cls}`}>
                                  {state.label}
                                </span>
                              )}
                            </button>
                          </li>
                        )
                      })}
                    </ul>
                  )}
                  {priorPermitChoice && (
                    <p className="mt-2 text-xs text-ink-secondary">
                      This application {applicationType === 'renewal' ? 'renews' : 'amends'}{' '}
                      {priorPermitChoice.permit_number}. Its clearance is ticked for you in the LGU
                      Section; add any others there.
                    </p>
                  )}
                </div>
              )}

              {/*
                * Items 82/84 — the paper form's "Amendment from:" block, which
                * the wizard has never asked. It sits with the business and
                * permit selection because it is the same decision: which
                * record, and what about it is changing.
                */}
              {applicationType === 'amendment' && (
                <fieldset
                  className="mt-5 border-0 p-0"
                  // React's onBlur is focusout, so one handler on the group
                  // covers all four controls: the group is the question, and
                  // leaving any part of it is having been asked.
                  onBlur={() => touch('amendment')}
                >
                  <legend className="mb-1.5 block text-[13px] font-semibold text-ink">
                    What are you amending?
                    <span className="text-s-red"> *</span>
                  </legend>
                  <p className="mb-2 text-xs text-ink-secondary">
                    Tick everything that is changing. You can choose more than one.
                  </p>
                  <div className="space-y-2">
                    {AMENDMENT_KINDS.map((kind) => (
                      <label
                        key={kind.key}
                        className="flex cursor-pointer items-center gap-3 rounded-lg border border-input-border bg-white px-4 py-2.5 text-sm font-medium text-ink"
                      >
                        <input
                          type="checkbox"
                          checked={amendment[kind.key]}
                          onChange={(e) =>
                            setAmendment((a) => ({ ...a, [kind.key]: e.target.checked }))
                          }
                          className="h-4 w-4 shrink-0 accent-royal"
                        />
                        <span>{kind.label}</span>
                      </label>
                    ))}
                    {/*
                      * "Others (specify)" is one control, not a checkbox with a
                      * box beside it: on the paper you cannot tick Others
                      * without writing the other in, so typing IS ticking and a
                      * separate tick could only ever contradict the text.
                      */}
                    <label className="block rounded-lg border border-input-border bg-white px-4 py-2.5">
                      <span className="mb-1.5 block text-[13px] font-semibold text-ink">
                        Others (specify)
                      </span>
                      <input
                        value={amendment.other}
                        onChange={(e) => setAmendment((a) => ({ ...a, other: e.target.value }))}
                        placeholder="e.g. change of business name"
                        maxLength={255}
                        className={inputCls}
                      />
                    </label>
                  </div>
                  {touched.amendment && !amendmentChosen && (
                    <p role="alert" className="mt-2 text-xs font-medium text-s-red">
                      Choose at least one. An amendment that amends nothing is not a filing the
                      BPLO can act on.
                    </p>
                  )}
                </fieldset>
              )}
            </div>
          )}
          <div className="mt-4 space-y-4">
            <div>
              <label className="block">
              <FieldLabel required>DTI / SEC / CDA Registration Number</FieldLabel>
              <input
                value={form.registration_number}
                onChange={(e) => update('registration_number', e.target.value)}
                onBlur={() => touch('registration_number')}
                placeholder="e.g. 3298765 (DTI) or CS201912345 (SEC)"
                className={inputCls}
                aria-invalid={Boolean(fieldErrors.registration_number)}
              />
              </label>
              {fieldErrors.registration_number && (
                <p className="mt-1 text-xs font-medium text-s-red">
                  {fieldErrors.registration_number}
                </p>
              )}
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block">
                <FieldLabel required>Tax Identification Number (TIN)</FieldLabel>
                <input
                  inputMode="numeric"
                  maxLength={20}
                  pattern="[\d\s.-]{9,20}"
                  value={form.tin}
                  onChange={(e) => update('tin', e.target.value)}
                  onBlur={() => touch('tin')}
                  placeholder="000-000-000-000"
                  className={inputCls}
                  aria-invalid={Boolean(fieldErrors.tin)}
                />
                </label>
                {fieldErrors.tin && (
                  <p className="mt-1 text-xs font-medium text-s-red">{fieldErrors.tin}</p>
                )}
              </div>
              <div>
                <label className="block">
                <FieldLabel required>Business Name</FieldLabel>
                <input
                  value={form.name}
                  onChange={(e) => update('name', e.target.value)}
                  onBlur={() => touch('name')}
                  placeholder="e.g. Dela Cruz Trading"
                  className={inputCls}
                  aria-invalid={Boolean(fieldErrors.name)}
                />
                </label>
                {fieldErrors.name && <p className="mt-1 text-xs font-medium text-s-red">{fieldErrors.name}</p>}
              </div>
            </div>
            <div>
              <label className="block">
              <FieldLabel>Trade Name / Franchise</FieldLabel>
              <input
                value={form.trade_name}
                onChange={(e) => update('trade_name', e.target.value)}
                placeholder="e.g. Aling Nena's Eatery"
                className={inputCls}
              />
              </label>
            </div>
            <div>
              <FieldLabel required>Type of Registration</FieldLabel>
              <div className="flex flex-wrap gap-2.5">
                {REGISTRATION_TYPES.map((rt) => {
                  const selected = form.registration_type === rt.value
                  return (
                    <button
                      key={rt.value}
                      type="button"
                      aria-pressed={selected}
                      onClick={() => update('registration_type', selected ? '' : rt.value)}
                      className={`flex items-center gap-2 rounded-md border px-4 py-2 text-sm font-medium transition-colors ${
                        selected
                          ? 'border-royal bg-input text-ink'
                          : 'border-input-border bg-input/60 text-ink-secondary hover:bg-input'
                      }`}
                    >
                      <span
                        className={`h-3.5 w-3.5 rounded-full border-2 ${
                          selected ? 'border-royal bg-royal' : 'border-input-border bg-white'
                        }`}
                      />
                      {rt.label}
                    </button>
                  )
                })}
              </div>
            </div>
          </div>
        </FormSheet>
      )}

      {/* ── Zoning clearance — Selecting Business Location (p27) ───────── */}
      {/*
       * This step is location CAPTURE for the zoning / locational clearance,
       * not a zoning decision: the system has no city zone polygons, so
       * conformance is evaluated by the Zoning Office (CPDO) during
       * processing. The copy here says "zoning clearance", never "Mayor's
       * permit" (user-testing feedback).
       *
       * The one thing it does decide is item 86: a pin nowhere near Malabon is
       * refused outright, because no amount of CPDO review makes a business in
       * another city licensable here. That is a bounding-box check and nothing
       * more — see MALABON_BOUNDS.
       */}
      {phase === 'address' && (
        <div>
          <h1 className="mb-1 text-2xl font-bold text-ink">Zoning Clearance - Selecting Business Location</h1>
          <div className="mb-2 h-px bg-ink/40" />
          <p className="mb-6 text-xs text-ink-secondary">
            Pin your exact location and enter your address. The pin has to fall within Malabon —
            BPLO can only license a business inside the city. These details go to the City Planning
            and Development Office (CPDO), which evaluates your zoning / locational clearance
            during processing.
          </p>

          {/*
            * Item 69 — the one and only Line of Business question.
            *
            * It belongs on this screen because the zoning verdict is about a
            * trade rather than a coordinate, and because Location Insights
            * compares the pin against businesses in the same PSIC group. It used
            * to be a plain dropdown here AND a full picker three sections later,
            * which asked the same thing twice and made the second ask look like
            * a different question. The picker is the one that survived: it
            * searches all 135 trades, takes more than one line, carries the
            * capital each line needs for the business tax, and has a free-text
            * escape for a trade the PSIC list has never heard of. None of that
            * survives in a <select>.
            *
            * Full width above the map rather than squeezed into the address
            * column beside it — each selected line needs a title, a capital box
            * and a Remove control on one row, and the picker's own search
            * results are the widest thing on the step.
            */}
          <div className="mb-7 rounded-2xl bg-white px-5 py-5 shadow-card sm:px-6">
            <FieldLabel required>Line of Business</FieldLabel>
            <p className="mb-3 text-xs text-ink-secondary">
              What this location will be used for. Add every line you trade in — each one is
              assessed separately.
            </p>
            <LinesStep codes={psic} lines={form.lines} onChange={(lines) => update('lines', lines)} />
            {form.lines.length === 0 && (
              <p className="mt-2.5 text-xs font-medium text-s-red">
                Required: choose at least one line of business. The zoning verdict is about a trade,
                not a coordinate.
              </p>
            )}
          </div>

          <div className="grid gap-6 lg:grid-cols-[1.15fr_1fr]">
            <div className="overflow-hidden rounded-2xl shadow-card [&>div]:!rounded-none [&>div]:!border-0">
              <MapPicker
                latitude={form.latitude}
                longitude={form.longitude}
                onPick={(lat, lng) => {
                  /*
                   * Item 86 — a pin outside the city is refused rather than
                   * stored and argued with later. The wording names exactly what
                   * was checked (is it near Malabon) and no more: this cannot
                   * tell land from water, so it never says it did. See
                   * MALABON_BOUNDS for what the check is and is not.
                   */
                  if (!withinMalabon(lat, lng)) {
                    setPinError(
                      `That point (${lat}, ${lng}) is outside Malabon, so we can’t use it. Zoom in on your street within the city and click there.`,
                    )
                    return
                  }
                  setPinError(null)
                  setForm((f) => ({ ...f, latitude: lat, longitude: lng }))
                }}
              />
              {form.latitude !== null ? (
                <p className="tnum bg-white px-4 py-2 text-xs text-ink-secondary">
                  Pinned at {form.latitude}, {form.longitude}
                </p>
              ) : (
                <p className="bg-white px-4 py-2 text-xs font-medium text-s-red">
                  Required: click the map to drop a pin where your business is.
                </p>
              )}
              {pinError && (
                <p role="alert" className="bg-white px-4 pb-2 text-xs font-medium text-s-red">
                  {pinError}
                </p>
              )}
              {/*
                * The pin locates the premises; it does not clear them. Said
                * plainly so the boundary check above is not mistaken for a
                * verdict on the site itself — there are no zone polygons and no
                * water layer here, and CPDO looks at the actual location.
                */}
              <p className="bg-white px-4 pb-2.5 text-xs text-ink-muted">
                CPDO checks the actual site during processing.
              </p>
            </div>
            <div className="space-y-4">
              <div>
                <label className="block">
                <FieldLabel required>House No. &amp; Street Name</FieldLabel>
                <input
                  value={form.line1}
                  onChange={(e) => update('line1', e.target.value)}
                  onBlur={() => touch('line1')}
                  placeholder="e.g. 24 Rizal Street"
                  className={inputCls}
                  aria-invalid={Boolean(fieldErrors.line1)}
                />
                </label>
                {fieldErrors.line1 && <p className="mt-1 text-xs font-medium text-s-red">{fieldErrors.line1}</p>}
              </div>
              <div>
                <label className="block">
                <FieldLabel required>Barangay Name</FieldLabel>
                <select
                  value={form.barangay_id}
                  onChange={(e) => update('barangay_id', e.target.value)}
                  onBlur={() => touch('barangay_id')}
                  className={inputCls}
                  aria-invalid={Boolean(fieldErrors.barangay_id)}
                >
                  <option value="">Select your barangay</option>
                  {barangays.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name}
                    </option>
                  ))}
                </select>
                </label>
                {fieldErrors.barangay_id && (
                  <p className="mt-1 text-xs font-medium text-s-red">{fieldErrors.barangay_id}</p>
                )}
              </div>
              <div>
                <label className="block">
                <FieldLabel>Locational Group/Landmark</FieldLabel>
                <input
                  value={form.line2}
                  onChange={(e) => update('line2', e.target.value)}
                  placeholder="e.g. beside Sto. Niño Chapel"
                  className={inputCls}
                />
                </label>
              </div>

              {/*
                * Unified form asks who owns the premises. Only a renter has a
                * lessor, so the block stays closed until they say so rather
                * than showing four fields most applicants must leave blank.
                */}
              <div>
                <FieldLabel>Are the premises rented?</FieldLabel>
                <div className="flex flex-wrap gap-2">
                  {[
                    { rented: false, label: 'Owned or occupied by me' },
                    { rented: true, label: 'Rented' },
                  ].map((opt) => {
                    const selected = form.is_rented === opt.rented
                    return (
                      <button
                        key={opt.label}
                        type="button"
                        aria-pressed={selected}
                        onClick={() => update('is_rented', opt.rented)}
                        className={`flex items-center gap-2 rounded-md border px-4 py-2 text-sm font-medium transition-colors ${
                          selected
                            ? 'border-royal bg-input text-ink'
                            : 'border-input-border bg-input/60 text-ink-secondary hover:bg-input'
                        }`}
                      >
                        <span
                          className={`h-3.5 w-3.5 rounded-full border-2 ${
                            selected ? 'border-royal bg-royal' : 'border-input-border bg-white'
                          }`}
                        />
                        {opt.label}
                      </button>
                    )
                  })}
                </div>
              </div>

              {form.is_rented && (
                <div className="flex flex-col gap-4 rounded-xl border border-line p-4">
                  <div>
                    <label className="block">
                    <FieldLabel required>Lessor's Name</FieldLabel>
                    <input
                      value={form.lessor_name}
                      onChange={(e) => update('lessor_name', e.target.value)}
                      onBlur={() => touch('lessor_name')}
                      placeholder="e.g. Maria Santos"
                      className={inputCls}
                      aria-invalid={Boolean(fieldErrors.lessor_name)}
                    />
                    </label>
                    {fieldErrors.lessor_name && (
                      <p className="mt-1 text-xs font-medium text-s-red">{fieldErrors.lessor_name}</p>
                    )}
                  </div>
                  <div>
                    <label className="block">
                    <FieldLabel required>Lessor's Address</FieldLabel>
                    {/*
                      * Item 70 — this example named "Poblacion", which is not a
                      * Malabon barangay at all. Catmon is one of the 21 the
                      * reference data actually seeds, so the example now reads
                      * like an address somebody here could really be typing.
                      */}
                    <input
                      value={form.lessor_address}
                      onChange={(e) => update('lessor_address', e.target.value)}
                      onBlur={() => touch('lessor_address')}
                      placeholder="e.g. 12 Mabini Street, Catmon"
                      className={inputCls}
                      aria-invalid={Boolean(fieldErrors.lessor_address)}
                    />
                    </label>
                    {fieldErrors.lessor_address && (
                      <p className="mt-1 text-xs font-medium text-s-red">
                        {fieldErrors.lessor_address}
                      </p>
                    )}
                  </div>
                  <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label className="block">
                      <FieldLabel>Lessor's Contact Number</FieldLabel>
                      <input
                        inputMode="tel"
                        value={form.lessor_contact}
                        onChange={(e) => update('lessor_contact', e.target.value)}
                        onBlur={() => touch('lessor_contact')}
                        placeholder="e.g. 0917 123 4567"
                        className={inputCls}
                        aria-invalid={Boolean(fieldErrors.lessor_contact)}
                      />
                      </label>
                      {fieldErrors.lessor_contact && (
                        <p className="mt-1 text-xs font-medium text-s-red">
                          {fieldErrors.lessor_contact}
                        </p>
                      )}
                    </div>
                    <div>
                      <label className="block">
                      <FieldLabel required>Monthly Rental (₱)</FieldLabel>
                      <input
                        inputMode="decimal"
                        value={form.monthly_rental}
                        onChange={(e) => update('monthly_rental', formatAmountInput(e.target.value))}
                        onBlur={() => touch('monthly_rental')}
                        placeholder="0.00"
                        className={`${inputCls} tnum`}
                        aria-invalid={Boolean(fieldErrors.monthly_rental)}
                      />
                      </label>
                      {fieldErrors.monthly_rental && (
                        <p className="mt-1 text-xs font-medium text-s-red">
                          {fieldErrors.monthly_rental}
                        </p>
                      )}
                    </div>
                  </div>
                </div>
              )}

              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <label className="block">
                  <FieldLabel required>Emergency Contact Person</FieldLabel>
                  <input
                    value={form.emergency_contact_name}
                    onChange={(e) => update('emergency_contact_name', e.target.value)}
                    onBlur={() => touch('emergency_contact_name')}
                    placeholder="e.g. Juan Dela Cruz"
                    className={inputCls}
                    aria-invalid={Boolean(fieldErrors.emergency_contact_name)}
                  />
                  </label>
                  {fieldErrors.emergency_contact_name && (
                    <p className="mt-1 text-xs font-medium text-s-red">
                      {fieldErrors.emergency_contact_name}
                    </p>
                  )}
                </div>
                <div>
                  <label className="block">
                  <FieldLabel required>Emergency Contact Number</FieldLabel>
                  <input
                    inputMode="tel"
                    value={form.emergency_contact_number}
                    onChange={(e) => update('emergency_contact_number', e.target.value)}
                    onBlur={() => touch('emergency_contact_number')}
                    placeholder="e.g. 0917 123 4567"
                    className={inputCls}
                    aria-invalid={Boolean(fieldErrors.emergency_contact_number)}
                  />
                  </label>
                  {fieldErrors.emergency_contact_number && (
                    <p className="mt-1 text-xs font-medium text-s-red">
                      {fieldErrors.emergency_contact_number}
                    </p>
                  )}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/*
        The per-office form sheets (p040-043) are mounted by the LGU Clearances
        stage now. OfficeFormStep.tsx is unchanged — only where it is mounted
        moved, because a sheet is the second half of applying for a clearance
        and the clearance is no longer applied for here.
      */}

      {/* ── Documents + Data Privacy Consent (p36) ─────────────────────── */}
      {phase === 'documents' && (
        <div className="rounded-sm bg-white px-6 py-7 shadow-card sm:px-9 sm:py-8">
          <SectionMarker letter="C" label="Documentary Requirements" />
          <p className="mt-2 text-xs text-ink-muted">
            Upload each requirement as a PDF or image (max 10 MB). Items marked with{' '}
            <span className="font-semibold text-s-red">*</span> are required. You can change files
            before submitting.
          </p>

          {/* OCR-lite suggestion banner (v2) — dismissible, suggestions only. */}
          {ocr && (
            <div className="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
              <span className="min-w-0 flex-1">
                We read your document:{' '}
                {ocr.business_name && (
                  <>Business name “<span className="font-semibold">{ocr.business_name}</span>”</>
                )}
                {ocr.registration_number && (
                  <> · Registration no. “<span className="font-semibold">{ocr.registration_number}</span>”</>
                )}
                {' '}· Use it?
              </span>
              <button
                type="button"
                onClick={() => applyOcr(ocr)}
                className="rounded-md bg-royal px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-royal-hover"
              >
                Apply
              </button>
              <button
                type="button"
                onClick={() => setOcr(null)}
                className="text-xs font-semibold text-blue-800 underline"
              >
                Dismiss
              </button>
            </div>
          )}
          <div className="mt-5 space-y-3.5">
            {requiredDocs.length === 0 ? (
              <p className="text-sm text-ink-secondary">No documents required for the selected permits.</p>
            ) : (
              requiredDocs.map((dt) => {
                const done = uploaded[dt.id]
                const busy = uploadingType === dt.id
                const removing = done && removingDoc === done.id
                return (
                  <div
                    key={dt.id}
                    className={`flex items-center gap-4 rounded-lg border-2 border-dashed border-input-border bg-input/50 px-5 py-3.5 ${
                      busy || removing ? 'opacity-60' : ''
                    }`}
                  >
                    <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-4 transition-colors">
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-input-border bg-white text-royal">
                        <UploadIcon size={18} />
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm font-bold text-ink">
                          {dt.name}
                          {dt.is_required === false ? (
                            <span className="ml-1 font-normal text-ink-muted">(optional)</span>
                          ) : (
                            <span className="text-s-red"> *</span>
                          )}
                        </span>
                        <span className="block truncate text-xs text-ink-muted">
                          {done
                            ? `${done.name} · ${formatBytes(done.size)} · click to replace`
                            : busy
                              ? 'Uploading…'
                              : dt.help_text || 'file type: png, jpg, pdf only'}
                        </span>
                      </span>
                      <input
                        type="file"
                        accept={ACCEPT_ATTR}
                        className="sr-only"
                        disabled={busy || Boolean(removing)}
                        onChange={(e) => {
                          const file = e.target.files?.[0]
                          if (file) void handleUpload(dt.id, file)
                          e.target.value = ''
                        }}
                      />
                    </label>
                    {done && (
                      <>
                        <span className="inline-flex shrink-0 items-center gap-1.5 text-sm font-semibold text-s-green">
                          <CheckIcon size={16} /> Uploaded
                        </span>
                        <button
                          type="button"
                          onClick={() => void handleRemoveDocument(done, dt.id)}
                          disabled={Boolean(removing)}
                          className="shrink-0 text-sm font-semibold text-s-red underline underline-offset-2 disabled:opacity-60"
                        >
                          {removing ? 'Removing…' : 'Remove'}
                        </button>
                      </>
                    )}
                  </div>
                )
              })
            )}
          </div>

          {/* Other Requirements — repeatable: add as many files as needed. */}
          {otherType && (
            <div className="mt-7">
              <p className="text-sm font-bold text-ink">
                Other Requirements <span className="font-normal text-ink-muted">(optional)</span>
              </p>
              <p className="mt-1 text-xs text-ink-muted">
                Attach any other supporting documents. You can add more than one file.
              </p>
              {otherDocs.length > 0 && (
                <ul className="mt-3 space-y-2">
                  {otherDocs.map((f) => (
                    <li
                      key={f.id}
                      className="flex items-center gap-3 rounded-lg border border-input-border bg-input/50 px-4 py-2.5"
                    >
                      <span className="text-s-green">
                        <CheckIcon size={16} />
                      </span>
                      <span className="min-w-0 flex-1 truncate text-sm text-ink">{f.name}</span>
                      <span className="tnum shrink-0 text-xs text-ink-muted">{formatBytes(f.size)}</span>
                      <button
                        type="button"
                        onClick={() => void handleRemoveDocument(f)}
                        disabled={removingDoc === f.id}
                        className="shrink-0 text-sm font-semibold text-s-red underline underline-offset-2 disabled:opacity-60"
                      >
                        {removingDoc === f.id ? 'Removing…' : 'Remove'}
                      </button>
                    </li>
                  ))}
                </ul>
              )}
              <label
                className={`mt-3 flex cursor-pointer items-center gap-3 rounded-lg border-2 border-dashed border-input-border bg-input/50 px-5 py-3 transition-colors hover:bg-input ${
                  uploadingType === otherType.id ? 'opacity-60' : ''
                }`}
              >
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-input-border bg-white text-royal">
                  <UploadIcon size={16} />
                </span>
                <span className="text-sm font-semibold text-royal">
                  {uploadingType === otherType.id
                    ? 'Uploading…'
                    : otherDocs.length > 0
                      ? 'Add another file'
                      : 'Add a file'}
                </span>
                <input
                  type="file"
                  accept={ACCEPT_ATTR}
                  className="sr-only"
                  disabled={uploadingType === otherType.id}
                  onChange={(e) => {
                    const file = e.target.files?.[0]
                    if (file) void handleOtherUpload(file)
                    e.target.value = ''
                  }}
                />
              </label>
            </div>
          )}

        </div>
      )}

      {/* ── Data Privacy Consent — asked before anything is collected ───── */}
      {phase === 'privacy' && (
        <div className="rounded-sm bg-white px-6 py-7 shadow-card sm:px-9 sm:py-8">
          <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-royal">
            City Government of Malabon · Business Permits &amp; Licensing Office
          </p>
          <h1 className="mt-1.5 text-2xl font-bold text-ink">Data Privacy Consent</h1>
          <p className="mt-1 text-xs text-ink-muted">Data Privacy Act of 2012 (RA 10173)</p>
          <div className="mb-6 mt-3 h-px bg-royal" />

          {/*
            * First, before a single answer is collected.
            *
            * This used to sit at the foot of Documentary Requirements, six
            * sections in — by which point the applicant had already handed over
            * their name, TIN, home address, emergency contact and the location
            * of their business. Asking afterwards inverts what consent is: it
            * turns a decision into a formality, because refusing would mean
            * abandoning work already done. Under RA 10173 consent is meant to
            * be freely given and informed *before* collection, so it is the
            * first thing on the form and nothing is asked until it is given.
            */}
          <p className="max-w-2xl text-justify text-sm leading-relaxed text-ink-secondary">
            I have read and understood the Data Privacy Policy and hereby give my consent to the City
            Government of Malabon, and any person acting on its behalf, to collect, store, record,
            process and update my personal data as part of its database and to share said data with
            the national government, its agencies and instrumentalities, and other local government
            units, pursuant to the Data Privacy Act of 2012 (RA 10173).
          </p>

          <label className="mt-6 flex max-w-2xl cursor-pointer items-start gap-3 rounded-lg border border-input-border bg-royal-tint px-4 py-3.5 text-sm font-semibold text-royal">
            <input
              type="checkbox"
              checked={consent}
              onChange={(e) => setConsent(e.target.checked)}
              className="mt-0.5 h-4 w-4 shrink-0 accent-royal"
            />
            <span>
              I have read and agree to the Data Privacy Consent above.
              <span className="text-s-red"> *</span>
            </span>
          </label>

          <p className="mt-4 max-w-2xl text-xs text-ink-muted">
            You can withdraw this consent by contacting the BPLO, though doing so means the office
            can no longer process an application in your name.
          </p>
        </div>
      )}

      {/* ── Business & Tax Profile (revenue-code fee inputs) ───────────── */}
      {phase === 'fees' && (
        <FormSheet meta={typeMeta}>
          <div className="mt-1">
            <FeeProfileStep
              applicationType={applicationType}
              /*
               * Item 72 — where the Business Structure answer came from, so the
               * step can show it rather than ask it a second time. Blank only
               * if reference data or a draft arrived without one, and the step
               * falls back to asking in that case.
               */
              registrationType={form.registration_type}
              /*
               * The business permit's questions, and only those. This used to
               * be whichever clearances had been ticked three steps back, which
               * is what grew this step an occupancy section and a market
               * section mid-flow — and what stopped the cards from being moved
               * after it. Each clearance carries its own fees on its own stage.
               */
              permitCodes={[BUSINESS_PERMIT_CODE]}
              lines={feeLines}
              value={feeDraft}
              onChange={setFeeDraft}
              paymentMode={paymentMode}
              onPaymentModeChange={setPaymentMode}
            />
          </div>
        </FormSheet>
      )}

      {/* ── Review & Submit (p46) ──────────────────────────────────────── */}
      {phase === 'review' && (
        <div>
          <h1 className="display-serif mb-1 text-2xl text-ink-secondary">Review & Submit</h1>
          <div className="mb-6 h-px bg-ink/40" />
          <div className="flex min-h-64 flex-col items-center justify-center gap-2 py-10 text-center">
            <p className="text-lg font-medium text-royal">
              Your Business Permit application is ready to submit
            </p>
            {/*
              What happens next, said here rather than discovered later. The six
              LGU clearances used to be summarised on this screen because they
              were picked three steps back; they are now a stage that opens once
              the first payment clears, and an applicant who is not told that
              would reasonably assume this filing was the whole of it.
            */}
            <p className="max-w-md text-sm text-ink-muted">
              Once it is submitted and your first payment clears, the LGU
              Clearances stage opens — sanitary, fire, zoning, environmental,
              occupancy and market — and you choose there which ones to apply
              for.
            </p>
            {priorPermitChoice && (
              <p className="tnum mt-6 text-sm text-ink-secondary">
                {applicationType === 'renewal' ? 'Renewing' : 'Amending'}{' '}
                {priorPermitChoice.permit_number}
              </p>
            )}
            {/* Items 82/84 — last chance to see what this filing changes. */}
            {applicationType === 'amendment' && amendmentChosen && (
              <>
                <p className="mt-6 text-lg font-medium text-royal">Amending</p>
                <p className="text-sm text-ink-muted">
                  {[
                    ...AMENDMENT_KINDS.filter((k) => amendment[k.key]).map((k) => k.label),
                    ...(amendment.other.trim() ? [`Others: ${amendment.other.trim()}`] : []),
                  ].join(' · ')}
                </p>
              </>
            )}
          </div>
        </div>
      )}

      {/* ── Bottom bar: pill buttons + green progress + Part n of N ────── */}
      <div className="mt-10 grid items-start gap-6 sm:grid-cols-[minmax(9rem,auto)_1fr_minmax(9rem,auto)]">
        <div className="flex flex-col gap-2">
          <div className="flex items-center gap-4">
            {/*
              * "Save & back to LGU Section" went with the office sheets. It
              * existed because a sheet opened from a clearance card had to
              * finish by returning to the cards rather than carrying on down
              * the wizard; no step here opens another one any more, so every
              * step's forward button is simply Next.
              */}
            {!isLast ? (
              <PillButton
                onClick={() => void next()}
                disabled={saving || stepMissing.length > 0}
                className="min-w-28"
              >
                {saving ? 'Saving…' : 'Next'}
              </PillButton>
            ) : (
              <PillButton
                onClick={() => setShowConfirm(true)}
                disabled={saving || !consent}
                className="min-w-28"
              >
                Submit
              </PillButton>
            )}
            {stepIndex > 0 && (
              <button
                type="button"
                onClick={back}
                className="text-sm font-semibold text-ink-secondary underline underline-offset-2 hover:text-ink"
              >
                Back
              </button>
            )}
          </div>
          {!isLast && stepMissing.length > 0 && (
            <p className="max-w-md text-xs text-ink-muted">
              Still needed on this part: {stepMissing.join(', ')}
            </p>
          )}
          {isLast && !consent && (
            <p className="max-w-md text-xs text-ink-muted">
              Tick the Data Privacy Consent on the first part before submitting.
            </p>
          )}
        </div>
        <div className="mx-auto w-full max-w-md">
          <div className="h-2.5 overflow-hidden rounded-full bg-ink-secondary/80">
            <div
              className="h-full rounded-full bg-s-green transition-all"
              style={{ width: `${(part / totalParts) * 100}%` }}
            />
          </div>
          <p className="mt-1.5 text-center text-sm font-medium text-ink">
            Part {part} of {totalParts}
          </p>
        </div>
        <span aria-hidden="true" />
      </div>

      {/*
        The SUBMISSION dialog (p041, item 59) moved to the LGU Clearances stage
        with the cards that opened it. Nothing in this wizard submits a
        certificate the applicant already holds.
      */}

      {/* ── WARNING · Clear All (p35) ──────────────────────────────────── */}
      {showClear && (
        <ProtoModal
          title="WARNING"
          onCancel={() => setShowClear(false)}
          onConfirm={clearCurrentPart}
          confirmLabel="Proceed"
        >
          <p className="text-center text-base">Are you sure you want to clear all inputs for this part?</p>
        </ProtoModal>
      )}

      {/* ── Zoning result (p30/p31) — presentational, ?zoning=deny flips it ── */}
      {showZoning &&
        (zoningDenied ? (
          <ProtoModal
            title="SORRY."
            tone="red"
            cancelLabel="Back"
            onCancel={closeZoning}
          >
            <p className="text-base leading-relaxed">
              The declared use for{' '}
              <span className="font-bold underline underline-offset-2">
                {zoningSubject ?? 'your new business'}
              </span>{' '}
              appears non-conforming for{' '}
              <span className="font-bold uppercase underline underline-offset-2">
                {barangayName ?? 'Area Location'}
              </span>
              . The Zoning Office (CPDO) makes the final determination on your zoning clearance.
            </p>
          </ProtoModal>
        ) : (
          <ProtoModal
            /*
             * The mockup's wording (spec §5, screens 124/125). An earlier build
             * said "Location recorded" instead, on the grounds that the system
             * holds no zone polygons and therefore determines nothing — the
             * client's paper overruled that, so the headline is restored.
             *
             * The one line kept from the cautious version is CPDO's final say.
             * The applicant is told the use is conforming AND told who actually
             * decides, which is the part that stops "CONGRATULATIONS!" reading
             * as an issued clearance.
             */
            title="CONGRATULATIONS!"
            tone="green"
            cancelLabel="Back"
            confirmLabel="Proceed to Application"
            wide
            onCancel={closeZoning}
            onConfirm={() => {
              closeZoning()
              void advance()
            }}
          >
            <p className="text-base leading-relaxed">
              {/*
               * "The new business for X" is the mockup's sentence and it is right
               * for a new filing. A renewal is not a new business, so the word
               * drops out rather than telling someone renewing a ten-year-old
               * carinderia that it is new.
               */}
              {zoningSubject ? (
                <>
                  {isReuse ? 'The business for' : 'The new business for'}{' '}
                  <span className="font-bold underline underline-offset-2">{zoningSubject}</span>{' '}
                  is
                </>
              ) : (
                `Your ${isReuse ? 'business' : 'new business'} is`
              )}{' '}
              conforming / within the allowed use for{' '}
              <span className="font-bold uppercase underline underline-offset-2">
                {barangayName ?? 'Area Location'}
              </span>
              . You may now proceed with the processing of your Business Permit Application.
            </p>
            <p className="mt-2 text-xs leading-relaxed text-ink-secondary">
              The Zoning Office (CPDO) makes the final determination on your zoning clearance
              during processing.
            </p>

            <LocationInsightsPanel
              insights={insights.data}
              loading={insights.loading}
              error={insights.error}
            />
          </ProtoModal>
        ))}

      {/* ── CONFIRMATION · final submit (p47) ──────────────────────────── */}
      {showConfirm && (
        <ProtoModal
          title="CONFIRMATION"
          cancelLabel="Cancel"
          confirmLabel="Proceed"
          confirmDisabled={saving}
          onCancel={() => setShowConfirm(false)}
          onConfirm={() => {
            setShowConfirm(false)
            void submit()
          }}
        >
          <p className="py-4 text-center text-lg">Are you sure you want to submit this application?</p>
        </ProtoModal>
      )}
    </div>
  )
}
