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
import { applications, businesses, documents, officeForms, permits, reference } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import {
  OFFICE_FORM_CODES,
  OfficeFormSheet,
  hasOfficeForm,
  officeFormMissing,
  type OfficeFormCode,
  type OfficeFormData,
} from './OfficeFormStep'
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

type BasePhase = 'permits' | 'business' | 'lines' | 'address' | 'documents' | 'fees' | 'review'
/*
 * Location & Zoning comes first (revised GUI, screens 28-33: "Zoning -
 * Selecting Business Location" is Part 1 of 8, the LGU Section cards are Part
 * 3). Where the business is decides what it may be, so asking for the address
 * before the paperwork matches how the counter actually works.
 */
const BASE_PHASES: BasePhase[] = ['address', 'permits', 'business', 'lines', 'documents', 'fees', 'review']

const BASE_LABELS: Record<BasePhase, string> = {
  permits: 'Permits & Certificates',
  business: 'Business Information',
  lines: 'Line of Business',
  address: 'Location & Zoning',
  documents: 'Documentary Requirements',
  fees: 'Business & Tax Profile',
  review: 'Review & Submit',
}

const OFFICE_LABELS: Record<OfficeFormCode, string> = {
  SANITARY: 'Sanitary Permit Form',
  CEC: 'Environmental Clearance Form',
  FSIC: 'Fire Safety (FSIC) Form',
  OCCUPANCY: 'Occupancy Permit Form',
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

/** A single running step: either a base phase or one office form sheet. */
type StepNode = { kind: 'base'; phase: BasePhase } | { kind: 'office'; code: OfficeFormCode }

/**
 * Stable identity for a step. Positions shift the moment a certificate is
 * ticked or unticked — its form sheet slots into the middle of the map — so
 * anything remembered about a step has to be remembered by name. Remembering
 * it by index is how a sheet nobody had opened inherited the state of the one
 * that used to sit at that number.
 */
function stepKey(n: StepNode): string {
  return n.kind === 'base' ? n.phase : `office:${n.code}`
}

/**
 * Document-type code prefix the API gives a clearance the applicant already
 * holds (DocumentController::heldPermitDocumentType). The suffix is the
 * permit-type code, which is how a reopened draft knows which card to mark.
 */
const HELD_DOC_PREFIX = 'HELD_'

/** An attachment already on the draft: the id is what a removal needs. */
interface UploadedFile {
  id: number
  name: string
  size: number
}

/**
 * A clearance the applicant already holds, submitted instead of applied for.
 * `id` is null while the file waits for a draft to attach itself to: the LGU
 * Section comes long before there is enough on the form to create one.
 */
interface HeldPermitFile {
  id: number | null
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

/** What the API accepts (DocumentController: mimes:pdf,jpg,jpeg,png, max:10240). */
const ACCEPTED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png']
const MAX_UPLOAD_BYTES = 10 * 1024 * 1024

/**
 * Why this file cannot be sent, checked before it leaves the browser.
 *
 * Everything here was previously discovered only by uploading and reading
 * whatever the server said back, and what it said back was not usable: an
 * empty PDF came back as "Upload a PDF, JPG, or PNG file." (it is one), and a
 * file over the request limit came back as a raw PHP notice that the client
 * rendered as "Something went wrong on our end" — blaming the server for the
 * applicant's 12 MB scan and inviting them to retry it forever. Naming the
 * actual defect, before the upload, is both faster and the only version that
 * tells the applicant what to do next.
 */
function fileRejection(file: File): string | null {
  const ext = file.name.includes('.') ? file.name.split('.').pop()!.toLowerCase() : ''
  if (!ACCEPTED_EXTENSIONS.includes(ext)) {
    return `“${file.name}” is not a file we can read. Upload a PDF, JPG, or PNG.`
  }
  if (file.size === 0) {
    return `“${file.name}” is empty. Check the file opens on your device, then upload it again.`
  }
  if (file.size > MAX_UPLOAD_BYTES) {
    return `“${file.name}” is ${formatBytes(file.size)}. The limit is 10 MB — try a smaller scan or photo.`
  }
  return null
}

/**
 * An upload error the applicant can act on. The API's own messages are used
 * as-is; the two failures that arrive without a usable message are the ones
 * worth translating, because both mean "your file is too big" and neither says
 * so. (413 is the web server refusing the request before Laravel sees it;
 * "failed to upload" is PHP truncating a file past upload_max_filesize.)
 */
function uploadErrorMessage(err: unknown): string {
  const apiError = toApiError(err)
  if (apiError.status === 413) {
    return 'That file is too large to upload. Try a smaller scan or photo, under 10 MB.'
  }
  if (/failed to upload/i.test(apiError.message)) {
    return 'That file did not finish uploading — it may be too large. Try a smaller scan or photo.'
  }
  return apiError.message
}

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
                            placeholder="e.g. bamboo furniture weaving"
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
   * Sections the applicant has actually opened, by name (see stepKey). The map
   * is clickable for these. A TICK is a different question entirely: it means
   * the section is complete, and is computed fresh from the answers every
   * render, so it can never outlive the answers that earned it.
   */
  const [visited, setVisited] = useState<string[]>([BASE_PHASES[0]])
  const markVisited = (key: string) =>
    setVisited((v) => (v.includes(key) ? v : [...v, key]))
  const [form, setForm] = useState<FormState>(EMPTY)
  /*
   * The applicant's own name for this filing. Blank is normal and means "call
   * it by the business name", which is what the header and the Drafts page do.
   */
  const [title, setTitle] = useState('')
  // Per-office form payloads keyed by permit-type code (prototype Parts 4-7).
  const [officeData, setOfficeData] = useState<Record<string, OfficeFormData>>({})
  /* Bumped after a permit re-sync to refetch server-derived office-form answers. */
  const [officeFormsVersion, setOfficeFormsVersion] = useState(0)
  // Business & tax profile inputs (revenue-code fee_profile; persisted on the draft).
  const [feeDraft, setFeeDraft] = useState<FeeProfileDraft>(EMPTY_FEE_PROFILE)
  const [touched, setTouched] = useState<Record<string, boolean>>({})
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

  // OCR-lite suggestion banner (v2) — dismissible; suggestions only.
  const [ocr, setOcr] = useState<OcrSuggestions | null>(null)

  // Owner's existing businesses (only needed to seed renewal/amendment).
  const ownedBusinesses = useAsync<Business[]>(
    () => (isReuse ? businesses.list() : Promise.resolve([])),
    [isReuse],
  )
  /*
   * Item 50 — the permits the owner already holds, so a renewal can name the
   * one it is for instead of "this business, and whatever it happens to have".
   * A shop with a Mayor's Permit expiring in January and a sanitary permit
   * expiring in June is renewing one of them, not both.
   */
  const ownedPermits = useAsync<Permit[]>(
    () => (isReuse ? permits.list() : Promise.resolve([])),
    [isReuse],
  )

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
   * Item 59 — clearances the applicant already holds, keyed by permit-type
   * code. Submitting the certificate is the alternative to applying: the
   * office gets the copy, the applicant is not walked through that office's
   * form, and no assignment or fee is raised for a clearance nobody is being
   * asked to issue.
   */
  const [held, setHeld] = useState<Record<string, HeldPermitFile>>({})
  /* Files chosen before a draft existed; flushed by the effect below. */
  const pendingHeldRef = useRef<Record<string, File>>({})
  const heldInFlightRef = useRef<Set<string>>(new Set())
  /* The permit card whose SUBMISSION dialog is open (p041). */
  const [heldPrompt, setHeldPrompt] = useState<{ id: number; code: string; name: string } | null>(null)
  const [heldPromptFile, setHeldPromptFile] = useState<File | null>(null)
  /** Why the certificate just chosen in the SUBMISSION dialog can't be used. */
  const [heldPromptError, setHeldPromptError] = useState<string | null>(null)
  const [heldBusy, setHeldBusy] = useState<string | null>(null)

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
        // The permits picked in Part 1 win; suggestions only fill a blank choice.
        permit_type_ids:
          f.permit_type_ids.length > 0 ? f.permit_type_ids : result.suggested_permit_type_ids,
      }))
      setPriorPermitId(result.last_permit?.id ?? null)
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
   * Item 50 — name the permit this filing is for. Choosing one ticks its
   * clearance in the LGU Section: renewing a sanitary permit nobody has asked
   * the City Health Office to look at is not a renewal of anything.
   */
  function choosePriorPermit(permit: Permit | null) {
    setPriorPermitId(permit?.id ?? null)
    if (!permit) return
    const pt = permitTypes.find((t) => t.code === permit.permit_type?.code)
    if (pt && !form.permit_type_ids.includes(pt.id)) {
      update('permit_type_ids', [...form.permit_type_ids, pt.id])
    }
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
  const clearanceTypes = permitTypes.filter((pt) => pt.code !== BUSINESS_PERMIT_CODE)
  const barangays: Barangay[] = refs.data?.barangays ?? []
  const psic: PsicCode[] = refs.data?.psic ?? []
  /*
   * Alphabetical for the location step's dropdown. Section B searches, so its
   * order does not matter; a plain <select> is only scannable if the 135 trades
   * read alphabetically, and "Other (not listed)" sits last where it belongs
   * rather than sorted under O.
   */
  const psicByTitle: PsicCode[] = useMemo(
    () =>
      [...psic].sort((a, b) => {
        if (a.code === OTHER_PSIC_CODE) return 1
        if (b.code === OTHER_PSIC_CODE) return -1
        return a.title.localeCompare(b.title)
      }),
    [psic],
  )
  const otherType: DocumentType | undefined = (refs.data?.documentTypes ?? []).find(
    (dt) => dt.code === OTHER_DOC_CODE,
  )
  /*
   * Documents the selected permits ask for, minus the ones whose `context`
   * does not apply. A new business has no previous mayor's permit to upload,
   * so demanding one is an unclearable block, not a requirement.
   */
  const requiredDocs = useMemo(() => {
    const selected = permitTypes.filter((pt) => form.permit_type_ids.includes(pt.id))
    const selectedCodes = new Set(selected.map((pt) => pt.code))
    const appliesNow = (context?: string) =>
      !context ||
      context === 'all' ||
      context === applicationType ||
      selectedCodes.has(context.toUpperCase())

    const map = new Map<number, DocumentType>()
    for (const pt of selected) {
      for (const dt of pt.document_types) {
        if (appliesNow(dt.context)) map.set(dt.id, dt)
      }
    }
    return [...map.values()]
  }, [permitTypes, form.permit_type_ids, applicationType])
  const barangayName = barangays.find((b) => String(b.id) === form.barangay_id)?.name

  /*
   * The line of business the applicant declared, when they have one. The zoning
   * step is Part 1 and the Line of Business step comes later, so on a new filing
   * this is usually undefined until they come back to the map — renewals and
   * amendments have it from the start because the prior filing is prefilled.
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
   * Frozen when the modal opens rather than tracked live off the pin: the point
   * being reported has to be the point the applicant was told about, and moving
   * the pin behind an open modal would silently change the figures underneath
   * the numbers they are reading. Nulled on close so reopening refetches.
   */
  const [insightsQuery, setInsightsQuery] = useState<LocationInsightsQuery | null>(null)
  const insights = useLocationInsights(insightsQuery)

  /*
   * Codes of the selected inspection-office permits that have a prototype form,
   * in the canonical office order (SANITARY, CEC, FSIC, OCCUPANCY). Each gets
   * its own step, and all of them show in the map the moment they are picked.
   */
  const selectedOfficeCodes: OfficeFormCode[] = useMemo(() => {
    const chosen = new Set(
      permitTypes.filter((pt) => form.permit_type_ids.includes(pt.id)).map((pt) => pt.code),
    )
    return OFFICE_FORM_CODES.filter((c) => chosen.has(c))
  }, [permitTypes, form.permit_type_ids])

  /*
   * Full step sequence, fixed from the moment permits are chosen (step 2).
   * Each office form sheet slots in after the business is described, so a
   * certificate ticked in Part 2 shows its sheet the moment it is ticked and
   * never appears for the first time mid-flow.
   */
  const sequence: StepNode[] = useMemo(() => {
    const nodes: StepNode[] = []
    for (const p of BASE_PHASES) {
      nodes.push({ kind: 'base', phase: p })
      if (p === 'lines') {
        for (const code of selectedOfficeCodes) nodes.push({ kind: 'office', code })
      }
    }
    return nodes
  }, [selectedOfficeCodes])

  const totalParts = sequence.length
  const stepIndex = Math.min(step, sequence.length - 1)
  const node = sequence[stepIndex]
  const phase: BasePhase | null = node.kind === 'base' ? node.phase : null
  const officeCode: OfficeFormCode | null = node.kind === 'office' ? node.code : null
  const isLast = stepIndex === sequence.length - 1

  /* Unticking a certificate removes its sheet: don't leave `step` past the end. */
  useEffect(() => {
    setStep((s) => Math.min(s, sequence.length - 1))
  }, [sequence.length])

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

  /*
   * Item 50 — the permits the chosen business currently holds, soonest to
   * expire first: that is the one somebody opening a renewal came here about.
   * A business with nothing issued yet has no list to show.
   */
  const renewablePermits: Permit[] = useMemo(() => {
    if (!isReuse || prefillBusinessId === null) return []
    return (ownedPermits.data ?? [])
      .filter((p) => p.business?.id === prefillBusinessId)
      .slice()
      .sort((a, b) => (a.valid_until ?? '').localeCompare(b.valid_until ?? ''))
  }, [isReuse, prefillBusinessId, ownedPermits.data])

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
    (n: StepNode): string[] => {
      if (n.kind === 'office') return officeFormMissing(n.code, officeData[n.code] ?? {})
      switch (n.phase) {
        case 'permits':
          /*
           * The clearance cards are additive, not a required pick: the Mayor's /
           * Business Permit is attached implicitly, and applying for it alone is
           * a real case (the seeded renewal storyline is exactly that). So the
           * only thing that can block here is reference data that arrived
           * without the BUSINESS permit type.
           */
          return form.permit_type_ids.length > 0
            ? []
            : ['Permit types could not be loaded. Reload the page and try again.']
        case 'business': {
          const missing: string[] = []
          if (isReuse && prefillBusinessId === null) {
            missing.push(applicationType === 'renewal' ? 'The business you are renewing' : 'The business you are amending')
          }
          // Item 50: a business holds several permits with different expiry
          // dates, so "renew this business" names nothing an office can act on.
          if (applicationType === 'renewal' && renewablePermits.length > 0 && priorPermitId === null) {
            missing.push('Which permit you are renewing')
          }
          if (!form.name.trim()) missing.push('Business Name')
          if (!form.registration_number.trim()) missing.push('DTI / SEC / CDA Registration Number')
          if (!form.tin.trim()) missing.push('Tax Identification Number (TIN)')
          else if (!tinValid(form.tin)) missing.push('A valid TIN (9 digits, plus branch code)')
          if (!form.registration_type) missing.push('Type of Registration')
          return missing
        }
        case 'lines': {
          if (form.lines.length === 0) return ['Select at least one line of business']
          const missing: string[] = []
          const otherId = psic.find((c) => c.code === OTHER_PSIC_CODE)?.id
          const otherLine = form.lines.find((l) => l.psic_code_id === otherId)
          if (otherLine && !otherLine.line_of_business.trim()) {
            missing.push('Your line of business (typed in, for “Other”)')
          }
          if (form.lines.some((l) => !l.capitalization.trim())) {
            missing.push('Capital for every line of business')
          }
          return missing
        }
        case 'address': {
          const missing: string[] = []
          /*
           * Required here, as the mockup marks it, and not merely because the
           * insights want it: the zoning modal this step opens into announces
           * conformity *for a named trade*, and CPDO's locational clearance is a
           * judgment about a use, not about a coordinate. Section B still owns
           * capital and any further lines.
           */
          if (!form.lines[0]?.psic_code_id) missing.push('Line of Business')
          if (!form.line1.trim()) missing.push('House No. & Street Name')
          if (!form.barangay_id) missing.push('Barangay')
          // CPDO rules on the zoning clearance from where the business actually
          // is, so the pin is part of the answer, not a nicety.
          if (form.latitude === null || form.longitude === null) missing.push('A pin on the map')
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
        case 'documents': {
          const missing = requiredDocs
            .filter((dt) => dt.is_required !== false && !uploaded[dt.id])
            .map((dt) => dt.name)
          if (!consent) missing.push('Data Privacy Consent')
          return missing
        }
        case 'fees':
          return feeProfileMissing(feeDraft, {
            applicationType,
            permitCodes: permitTypes
              .filter((pt) => form.permit_type_ids.includes(pt.id))
              .map((pt) => pt.code),
            lines: feeLines,
          })
        case 'review':
          return []
      }
    },
    [
      form,
      officeData,
      requiredDocs,
      uploaded,
      consent,
      feeDraft,
      applicationType,
      feeLines,
      psic,
      permitTypes,
      isReuse,
      prefillBusinessId,
      priorPermitId,
      renewablePermits,
    ],
  )

  /** What is still missing on the step being displayed. */
  const stepMissing: string[] = useMemo(() => missingFor(node), [missingFor, node])

  /*
   * Which sections are finished, asked of every section rather than inferred
   * from where the applicant happens to be standing. Review is the one section
   * with nothing of its own to fill in, so it counts as done exactly when
   * everything it reviews is.
   */
  const stepComplete: boolean[] = useMemo(() => {
    const flags = sequence.map((n) => missingFor(n).length === 0)
    const last = flags.length - 1
    if (last >= 0) flags[last] = flags.slice(0, last).every(Boolean)
    return flags
  }, [sequence, missingFor])

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
    })
    setApplicationId(app.id)
    return app.id
  }

  /**
   * Persist whatever the CURRENT step owns before leaving it, so every Next
   * (and every map jump) saves: permit selection, business fields, office
   * forms, and the fee profile all round-trip through the API.
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
      if (officeCode) {
        const id = await ensureDraftRaw()
        await officeForms.save(id, officeCode, officeData[officeCode] ?? {})
      } else if (phase === 'permits') {
        // Re-sync permits on an existing draft; otherwise the office forms
        // 422 with "not part of this application" (the bug behind "no form
        // is presented once a certificate is clicked").
        if (applicationId) {
          await applications.update(applicationId, { permit_type_ids: form.permit_type_ids })
          // The sheets for any newly-added certificate carry derived answers
          // the server only knows now that it has the new permit list.
          setOfficeFormsVersion((v) => v + 1)
        }
      } else if (phase === 'address' || phase === 'business' || phase === 'lines') {
        if (applicationId) {
          const bid = businessId ?? prefillBusinessId
          if (bid) await businesses.update(bid, businessPayload())
        } else if (phase === 'lines' && canCreateDraft) {
          // Last step before uploads: there must be something to attach
          // documents to, even if the autosave debounce has not fired yet.
          await ensureDraftRaw()
        }
      } else if (phase === 'fees') {
        const id = await ensureDraftRaw()
        await applications.update(id, {
          fee_profile: buildFeeProfile(feeDraft, {
            applicationType,
            permitCodes: permitTypes
              .filter((pt) => form.permit_type_ids.includes(pt.id))
              .map((pt) => pt.code),
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
    const target = Math.min(stepIndex + 1, sequence.length - 1)
    setStep(target)
    markVisited(stepKey(sequence[target]))
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

  /**
   * Set or clear the primary line of business from the location step.
   *
   * Location Insights compares the pin against businesses in the same PSIC
   * group, so two of its four figures need a declared line. Line of Business is
   * Section B, three steps after the map, which meant every first-time filing
   * opened the zoning modal with nothing to compare and the panel reported half
   * its answers as unavailable. Asking here is the smallest fix that keeps
   * zoning as Part 1, which the mockup fixes.
   *
   * This writes into the same `form.lines` Section B edits — not a parallel
   * field — so the answer carries forward prefilled instead of being asked
   * twice. Capital is deliberately left blank: it does not affect insights, the
   * API takes it as nullable, and Section B still refuses to advance without it.
   */
  function setPrimaryLine(psicCodeId: number | null) {
    setForm((f) => {
      const [first, ...rest] = f.lines
      if (psicCodeId === null) {
        /*
         * Only withdraw the bare stub this control created. Once the applicant
         * has given the line capital or a typed trade name it is their work,
         * and clearing an optional dropdown should not delete it.
         */
        return first && !first.capitalization.trim() && !first.line_of_business.trim()
          ? { ...f, lines: rest }
          : f
      }
      if (!first) {
        return {
          ...f,
          lines: [{ psic_code_id: psicCodeId, capitalization: '', line_of_business: '' }],
        }
      }
      return { ...f, lines: [{ ...first, psic_code_id: psicCodeId }, ...rest] }
    })
  }

  function back() {
    setStep((s) => Math.max(s - 1, 0))
  }

  /** Jump to any already-opened section from the map (persisting first). */
  async function goTo(index: number) {
    if (saving || index === stepIndex) return
    if (!visited.includes(stepKey(sequence[index]))) return
    // Moving forward re-checks every section being skipped, not just this one.
    if (jumpBlocked(index)) return
    const ok = await persistOnLeave()
    if (!ok) return
    setStep(index)
    markVisited(stepKey(sequence[index]))
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
    (applicationType !== 'renewal' || renewablePermits.length === 0 || priorPermitId !== null)

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
        permitCodes: permitTypes
          .filter((pt) => form.permit_type_ids.includes(pt.id))
          .map((pt) => pt.code),
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
        })
        // Which permit is being renewed can change after the draft exists, and
        // it is not part of the general application update (item 50).
        if (isReuse) await applications.setPriorPermit(id, priorPermitId)
      } else {
        await applications.update(id, { fee_profile: feeProfile, payment_mode: paymentMode })
      }
      for (const code of selectedOfficeCodes) {
        const data = officeData[code]
        if (data && Object.keys(data).length > 0) await officeForms.save(id, code, data)
      }
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
        officeData,
        feeDraft,
        paymentMode,
        applicationType,
        priorPermitId,
      }),
    [title, form, officeData, feeDraft, paymentMode, applicationType, priorPermitId],
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
    if (officeCode) {
      setOfficeData((d) => ({ ...d, [officeCode]: {} }))
    } else if (phase === 'business') {
      setForm((f) => ({ ...f, name: '', trade_name: '', registration_type: '', registration_number: '', tin: '' }))
      if (isReuse) {
        setPrefillBusinessId(null)
        setPriorPermitId(null)
        setPrefillNote(null)
      }
    } else if (phase === 'lines') {
      setForm((f) => ({ ...f, lines: [] }))
    } else if (phase === 'address') {
      setForm((f) => ({ ...f, line1: '', line2: '', barangay_id: '', latitude: null, longitude: null }))
    } else if (phase === 'permits') {
      // Clearances only: the Mayor's / Business Permit is never cleared.
      setForm((f) => ({ ...f, permit_type_ids: businessTypeId === null ? [] : [businessTypeId] }))
      // Certificates submitted as already-held are inputs of this part too, so
      // "clear all inputs for this part" has to take them with it.
      for (const code of Object.keys(held)) void removeHeldPermit(code)
    } else if (phase === 'documents') {
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
   * "Submit" on a permit card means "I hold this one already, here is the
   * certificate"; "Apply" means "issue me one". They are opposites, so
   * choosing either clears the other. Submitting attaches the copy to the
   * application against its permit type — BPLO reads it with the rest of the
   * file — and takes the clearance out of what is being applied for, which is
   * what spares the applicant that office's form, its assignment, and its fee.
   *
   * The LGU Section runs long before there is enough on the form to create a
   * draft, so the file waits in `pendingHeldRef` and is attached by the effect
   * below the moment there is an application to attach it to.
   */
  function submitHeldPermit(pt: { id: number; code: string; name: string }, file: File) {
    pendingHeldRef.current = { ...pendingHeldRef.current, [pt.code]: file }
    setHeld((h) => ({ ...h, [pt.code]: { id: null, name: file.name, size: file.size } }))
    setForm((f) => ({ ...f, permit_type_ids: f.permit_type_ids.filter((id) => id !== pt.id) }))
    setHeldPrompt(null)
  }

  /** Take a submitted certificate back off (and stop claiming to hold it). */
  async function removeHeldPermit(code: string) {
    const entry = held[code]
    if (!entry) return
    setHeldBusy(code)
    setSubmitError(null)
    try {
      if (entry.id !== null && applicationId) await documents.remove(applicationId, entry.id)
      const { [code]: _dropped, ...restFiles } = pendingHeldRef.current
      pendingHeldRef.current = restFiles
      setHeld((h) => {
        const next = { ...h }
        delete next[code]
        return next
      })
    } catch (err) {
      setSubmitError(toApiError(err).message)
    } finally {
      setHeldBusy((b) => (b === code ? null : b))
    }
  }

  /** Attach any certificate chosen before the draft existed. */
  useEffect(() => {
    if (!applicationId) return
    const waiting = Object.keys(pendingHeldRef.current).filter(
      (code) => !heldInFlightRef.current.has(code),
    )
    if (waiting.length === 0) return
    for (const code of waiting) {
      const file = pendingHeldRef.current[code]
      const pt = permitTypes.find((p) => p.code === code)
      if (!file || !pt) continue
      heldInFlightRef.current.add(code)
      setHeldBusy(code)
      documents
        .upload(applicationId, null, file, pt.id)
        .then((doc) => {
          setHeld((h) => (h[code] ? { ...h, [code]: { ...h[code], id: doc.id } } : h))
        })
        .catch((err) => {
          // Never leave a card claiming a certificate the API refused.
          setHeld((h) => {
            const next = { ...h }
            delete next[code]
            return next
          })
          setSubmitError(uploadErrorMessage(err))
        })
        .finally(() => {
          const { [code]: _done, ...rest } = pendingHeldRef.current
          pendingHeldRef.current = rest
          heldInFlightRef.current.delete(code)
          setHeldBusy((b) => (b === code ? null : b))
        })
    }
  }, [applicationId, held, permitTypes])

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
        const pts = refData.permitTypes
        const ids = pts
          .filter((pt) => app.permit_types.some((p) => p.code === pt.code))
          .map((pt) => pt.id)
        // Older drafts may predate the implicit business permit; re-attach it.
        const bizId = pts.find((pt) => pt.code === BUSINESS_PERMIT_CODE)?.id
        if (bizId !== undefined && !ids.includes(bizId)) ids.unshift(bizId)
        const b = app.business
        const lineIds = (b.lines ?? []).map((l) => l.psic_code.id)
        setApplicationType(app.application_type)
        setApplicationId(app.id)
        setTitle(app.title ?? '')
        setBusinessId(b.id)
        if (app.application_type !== 'new') setPrefillBusinessId(b.id)
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
        const restoredHeld: Record<string, HeldPermitFile> = {}
        for (const doc of app.documents ?? []) {
          const code = doc.document_type?.code
          if (!code) continue
          const file = { id: doc.id, name: doc.original_filename, size: doc.size_bytes }
          if (code.startsWith(HELD_DOC_PREFIX)) {
            // "HELD_SANITARY" is the sanitary clearance the applicant already
            // holds; the suffix is the permit-type code the card is keyed by.
            restoredHeld[code.slice(HELD_DOC_PREFIX.length)] = file
          } else if (code === OTHER_DOC_CODE) {
            others.push(file)
          } else {
            const dtId = codeToId.get(code)
            if (dtId != null) restored[dtId] = file
          }
        }
        setUploaded(restored)
        setOtherDocs(others)
        setHeld(restoredHeld)
        /*
         * Every section of a saved draft has been opened, so the whole map is
         * clickable. Which of them count as DONE is a separate question, asked
         * of the answers themselves each render — a draft saved with no
         * documents uploaded shows Documentary Requirements unticked, which is
         * the truth about it.
         */
        const officeKeys = pts
          .filter((pt) => ids.includes(pt.id) && hasOfficeForm(pt.code))
          .map((pt) => `office:${pt.code}`)
        setVisited([...BASE_PHASES, ...officeKeys])
        // Item 50: which permit this renewal is for, chosen when the draft was
        // started and re-choosable now.
        if (app.application_type !== 'new') {
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
   * Load any previously-saved office-form payloads once the draft exists, and
   * again whenever the permit selection has been synced. Parts of these sheets
   * are derived server-side from the permits chosen (the FSIC "Certificate
   * Applied For", the sanitary and CEC application types), so adding a
   * certificate mid-session leaves those fields blank until we refetch.
   */
  useEffect(() => {
    if (!applicationId) return
    let active = true
    officeForms
      .list(applicationId)
      .then((forms) => {
        if (!active || forms.length === 0) return
        setOfficeData((prev) => {
          const nextData = { ...prev }
          for (const f of forms) {
            // Don't clobber unsaved edits already in the current session.
            if (!(f.permit_type_code in nextData) && hasOfficeForm(f.permit_type_code)) {
              nextData[f.permit_type_code] = f.form_data
            }
          }
          return nextData
        })
      })
      .catch(() => {
        /* Non-fatal: office forms are optional free-form JSON. */
      })
    return () => {
      active = false
    }
  }, [applicationId, officeFormsVersion])

  /*
   * Entering the Business & Tax Profile step: seed structure from the business
   * section's registration type and per-line capitalization from the Line of
   * Business step (once, without clobbering anything the user already typed).
   */
  useEffect(() => {
    if (phase !== 'fees') return
    setFeeDraft((d) => {
      let nextDraft = d
      if (!d.business_structure && form.registration_type) {
        nextDraft = { ...nextDraft, business_structure: form.registration_type }
      }
      const categories = { ...nextDraft.categories }
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
      return seeded ? { ...nextDraft, categories } : nextDraft
    })
  }, [phase, form.registration_type, form.lines])

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
        */}
        <label className="min-w-0 flex-1">
          <span className="sr-only">Application title</span>
          <input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            maxLength={120}
            placeholder={form.name.trim() || 'Title of Application'}
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
        {sequence.map((n, i) => {
          const label = n.kind === 'base' ? BASE_LABELS[n.phase] : OFFICE_LABELS[n.code]
          const current = i === stepIndex
          const opened = visited.includes(stepKey(n))
          const blocked = jumpBlocked(i)
          // A tick says "this section is finished", not "you have walked past
          // it": it comes from the answers, so clearing a section takes its
          // tick with it and a section skipped over never gets one.
          const done = opened && stepComplete[i]
          return (
            <li key={stepKey(n)}>
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

      {/* ── Part 1 · LGU Section — permit type cards (p37) ─────────────── */}
      {phase === 'permits' && (
        <div>
          <h1 className="display-serif mb-1 text-2xl text-ink-secondary">LGU Section</h1>
          <div className="mb-6 h-px bg-ink/40" />
          {/*
            The Mayor's / Business Permit is neither offered nor explained: it
            is the outcome of the whole application, so it is always attached
            (permit_type_ids) and BPLO always receives the file. Only the
            supporting clearances are cards.
          */}
          <p className="mb-5 max-w-2xl text-sm text-ink-secondary">
            <span className="font-semibold text-ink">Apply</span> to have the office issue this
            clearance. Already hold a valid one?{' '}
            <span className="font-semibold text-ink">Submit</span> a copy instead and skip that
            office’s form.
          </p>
          <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            {clearanceTypes.map((pt) => {
              const selected = form.permit_type_ids.includes(pt.id)
              const onFile = held[pt.code]
              const busy = heldBusy === pt.code
              return (
                <div key={pt.id} className="flex flex-col rounded-2xl bg-white px-5 py-5 shadow-card">
                  <p className="text-lg font-bold leading-snug text-ink">{pt.name}</p>
                  <p className="display-serif mt-2 text-sm italic text-ink-secondary">
                    {pt.department.name}
                  </p>
                  {hasOfficeForm(pt.code) && !onFile && (
                    <p className="mt-2 text-xs text-ink-muted">
                      Adds its own application form section above.
                    </p>
                  )}
                  {onFile && (
                    <div className="mt-3 rounded-md border border-s-green/40 bg-s-green/10 px-3 py-2">
                      <p className="flex items-center gap-1.5 text-xs font-bold text-s-green">
                        <CheckIcon size={13} /> On file, not applied for
                      </p>
                      <p className="mt-1 truncate text-xs text-ink-secondary" title={onFile.name}>
                        {onFile.name} · {formatBytes(onFile.size)}
                        {onFile.id === null && ' · saving'}
                      </p>
                      <button
                        type="button"
                        onClick={() => void removeHeldPermit(pt.code)}
                        disabled={busy}
                        className="mt-1 text-xs font-semibold text-s-red underline underline-offset-2 disabled:opacity-60"
                      >
                        {busy ? 'Removing…' : 'Remove'}
                      </button>
                    </div>
                  )}
                  <div className="mt-5 flex flex-1 items-end gap-2.5">
                    <button
                      type="button"
                      aria-pressed={Boolean(onFile)}
                      disabled={busy}
                      onClick={() => {
                        if (onFile) void removeHeldPermit(pt.code)
                        else setHeldPrompt({ id: pt.id, code: pt.code, name: pt.name })
                      }}
                      className={`flex-1 rounded-sm px-3 py-2 text-sm font-semibold underline underline-offset-2 transition-colors disabled:opacity-60 ${
                        onFile
                          ? 'border-2 border-royal bg-white text-royal'
                          : 'border-2 border-royal-deep bg-royal-deep text-white hover:bg-royal'
                      }`}
                    >
                      {onFile ? 'Submitted ✓' : 'Submit'}
                    </button>
                    <button
                      type="button"
                      aria-pressed={selected}
                      disabled={busy}
                      onClick={() => {
                        // Applying for it and already holding it are opposites.
                        if (!selected && onFile) void removeHeldPermit(pt.code)
                        update(
                          'permit_type_ids',
                          selected
                            ? form.permit_type_ids.filter((id) => id !== pt.id)
                            : [...form.permit_type_ids, pt.id],
                        )
                      }}
                      className={`flex-1 rounded-sm px-3 py-2 text-sm font-semibold underline underline-offset-2 transition-colors disabled:opacity-60 ${
                        selected
                          ? 'border-2 border-royal bg-white text-royal'
                          : 'border-2 border-royal bg-royal text-white hover:bg-royal-hover'
                      }`}
                    >
                      {selected ? 'Applied ✓' : 'Apply'}
                    </button>
                  </div>
                </div>
              )
            })}
          </div>
        </div>
      )}

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
                  {ownedPermits.loading ? (
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
                placeholder="Enter registration number"
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
                  placeholder="Registered business name"
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
                placeholder="Trade name, if any"
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

      {/* ── Lines of business (form sheet) ─────────────────────────────── */}
      {phase === 'lines' && (
        <FormSheet meta={typeMeta}>
          <SectionMarker letter="B" label="Line of Business" />
          <div className="mt-4">
            <LinesStep codes={psic} lines={form.lines} onChange={(lines) => update('lines', lines)} />
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
       */}
      {phase === 'address' && (
        <div>
          <h1 className="mb-1 text-2xl font-bold text-ink">Zoning Clearance - Selecting Business Location</h1>
          <div className="mb-2 h-px bg-ink/40" />
          <p className="mb-6 text-xs text-ink-secondary">
            Pin your exact location and enter your address. These details go to the City Planning
            and Development Office (CPDO), which evaluates your zoning / locational clearance
            during processing.
          </p>
          <div className="grid gap-6 lg:grid-cols-[1.15fr_1fr]">
            <div className="overflow-hidden rounded-2xl shadow-card [&>div]:!rounded-none [&>div]:!border-0">
              <MapPicker
                latitude={form.latitude}
                longitude={form.longitude}
                onPick={(lat, lng) => setForm((f) => ({ ...f, latitude: lat, longitude: lng }))}
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
            </div>
            <div className="space-y-4">
              <div>
                {/*
                  * Live, and first on the screen, as the mockup has it.
                  *
                  * This was a disabled echo reading "You choose this later, in
                  * the Line of Business section" — which made the mockup's own
                  * Location Insights unanswerable. Two of its four figures
                  * compare the pin against businesses in the same PSIC group,
                  * and Section B is three steps further on, so every new filing
                  * opened the zoning modal with nothing to compare against and
                  * the panel reported half its answers unavailable.
                  *
                  * Section B still owns capital and any additional lines; this
                  * sets the primary line only, and Section B shows it prefilled.
                  */}
                <label className="block">
                <FieldLabel required>Line of Business</FieldLabel>
                <select
                  value={form.lines[0]?.psic_code_id ?? ''}
                  onChange={(e) => setPrimaryLine(e.target.value ? Number(e.target.value) : null)}
                  onBlur={() => touch('lines')}
                  className={inputCls}
                >
                  <option value="">Select your line of business</option>
                  {psicByTitle.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.code === OTHER_PSIC_CODE ? c.title : `${c.code} — ${c.title}`}
                    </option>
                  ))}
                </select>
                </label>
                {fieldErrors.lines && (
                  <p className="mt-1 text-xs font-medium text-s-red">{fieldErrors.lines}</p>
                )}
              </div>
              <div>
                <label className="block">
                <FieldLabel required>House No. &amp; Street Name</FieldLabel>
                <input
                  value={form.line1}
                  onChange={(e) => update('line1', e.target.value)}
                  onBlur={() => touch('line1')}
                  placeholder="House No. and Street Name"
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
                  <option value="">Barangay Name</option>
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
                  placeholder="Locational Group/Landmark"
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
                      placeholder="Who you pay rent to"
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
                    <input
                      value={form.lessor_address}
                      onChange={(e) => update('lessor_address', e.target.value)}
                      onBlur={() => touch('lessor_address')}
                      placeholder="Lessor's address"
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
                        placeholder="09XX XXX XXXX"
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
                    placeholder="Who to reach in an emergency"
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
                    placeholder="09XX XXX XXXX"
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

      {/* ── Per-office application forms (p040-043) ────────────────────── */}
      {officeCode && (
        <OfficeFormSheet
          code={officeCode}
          data={officeData[officeCode] ?? {}}
          onChange={(d) => setOfficeData((prev) => ({ ...prev, [officeCode]: d }))}
        />
      )}

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
                        accept=".pdf,.jpg,.jpeg,.png"
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
                  accept=".pdf,.jpg,.jpeg,.png"
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

          {/* Data Privacy Consent box — gates the wizard from moving on. */}
          <div className="mt-7 rounded-md border border-input-border bg-white px-5 py-4">
            <p className="text-sm font-bold uppercase tracking-wide text-royal">Data Privacy Consent</p>
            <p className="mt-2 text-justify text-xs leading-relaxed text-ink-secondary">
              I have read and understood the Data Privacy Policy and hereby give my consent to the City
              Government of Malabon, and any person acting on its behalf, to collect, store, record,
              process and update my personal data as part of its database and to share said data with the
              national government, its agencies and instrumentalities, and other local government units,
              pursuant to the Data Privacy Act of 2012 (RA 10173).
            </p>
            <label className="mt-3 flex cursor-pointer items-center gap-2.5 text-[13px] font-semibold text-royal">
              <input
                type="checkbox"
                checked={consent}
                onChange={(e) => setConsent(e.target.checked)}
                className="h-4 w-4 accent-royal"
              />
              I have read and agree to the Data Privacy Consent above.
              <span className="text-s-red">*</span>
            </label>
          </div>
        </div>
      )}

      {/* ── Business & Tax Profile (revenue-code fee inputs) ───────────── */}
      {phase === 'fees' && (
        <FormSheet meta={typeMeta}>
          <div className="mt-1">
            <FeeProfileStep
              applicationType={applicationType}
              permitCodes={permitTypes
                .filter((pt) => form.permit_type_ids.includes(pt.id))
                .map((pt) => pt.code)}
              lines={feeLines}
              value={feeDraft}
              onChange={setFeeDraft}
              paymentMode={paymentMode}
              onPaymentModeChange={setPaymentMode}
            />
          </div>
        </FormSheet>
      )}

      {/* ── LGU Section — all clearances applied (p46) ─────────────────── */}
      {phase === 'review' && (
        <div>
          <h1 className="display-serif mb-1 text-2xl text-ink-secondary">LGU Section</h1>
          <div className="mb-6 h-px bg-ink/40" />
          <div className="flex min-h-64 flex-col items-center justify-center gap-2 py-10 text-center">
            <p className="text-lg font-medium text-royal">All clearances are applied for</p>
            <p className="text-sm text-ink-muted">
              {permitTypes
                .filter((pt) => form.permit_type_ids.includes(pt.id))
                .map((pt) => pt.name)
                .join(' · ')}
            </p>
            {Object.keys(held).length > 0 && (
              <>
                <p className="mt-6 text-lg font-medium text-royal">Already held, copies submitted</p>
                <p className="text-sm text-ink-muted">
                  {permitTypes
                    .filter((pt) => held[pt.code])
                    .map((pt) => pt.name)
                    .join(' · ')}
                </p>
              </>
            )}
            {priorPermitChoice && (
              <p className="tnum mt-6 text-sm text-ink-secondary">
                {applicationType === 'renewal' ? 'Renewing' : 'Amending'}{' '}
                {priorPermitChoice.permit_number}
              </p>
            )}
          </div>
        </div>
      )}

      {/* ── Bottom bar: pill buttons + green progress + Part n of N ────── */}
      <div className="mt-10 grid items-start gap-6 sm:grid-cols-[minmax(9rem,auto)_1fr_minmax(9rem,auto)]">
        <div className="flex flex-col gap-2">
          <div className="flex items-center gap-4">
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
              Tick the Data Privacy Consent in Documentary Requirements before submitting.
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

      {/* ── SUBMISSION · a permit already held (p041, item 59) ─────────── */}
      {heldPrompt && (
        <ProtoModal
          title="SUBMISSION"
          cancelLabel="Cancel"
          confirmLabel="Submit"
          confirmDisabled={!heldPromptFile}
          onCancel={() => {
            setHeldPrompt(null)
            setHeldPromptFile(null)
            setHeldPromptError(null)
          }}
          onConfirm={() => {
            if (heldPromptFile) submitHeldPermit(heldPrompt, heldPromptFile)
            setHeldPromptFile(null)
            setHeldPromptError(null)
          }}
        >
          <p className="text-xl font-bold text-ink">{heldPrompt.name}</p>
          <p className="display-serif mt-1 text-sm italic text-ink-secondary">
            file type: png, jpg, pdf only
          </p>
          <label className="mt-5 flex cursor-pointer items-center gap-3 rounded-lg border-2 border-dashed border-input-border bg-input/50 px-5 py-3.5 transition-colors hover:bg-input">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-input-border bg-white text-royal">
              <UploadIcon size={18} />
            </span>
            <span className="min-w-0 flex-1">
              <span className="block truncate text-sm font-semibold text-ink">
                {heldPromptFile ? heldPromptFile.name : 'Choose your certificate'}
              </span>
              <span className="block text-xs text-ink-secondary">
                {heldPromptFile
                  ? formatBytes(heldPromptFile.size)
                  : 'The copy you already hold, up to 10 MB.'}
              </span>
            </span>
            <input
              type="file"
              accept=".pdf,.jpg,.jpeg,.png"
              className="sr-only"
              onChange={(e) => {
                const picked = e.target.files?.[0] ?? null
                e.target.value = ''
                // Reject here, not three screens later: this file is queued and
                // only uploaded once a draft exists, so a bad one chosen now
                // failed silently long after the applicant had moved on.
                const rejection = picked ? fileRejection(picked) : null
                setHeldPromptError(rejection)
                setHeldPromptFile(rejection ? null : picked)
              }}
            />
          </label>
          {heldPromptError && (
            <p role="alert" className="mt-2 text-xs font-medium text-s-red">
              {heldPromptError}
            </p>
          )}
          <p className="mt-4 text-xs leading-relaxed text-ink-secondary">
            Submitting a certificate you already hold is not an application: you skip this office’s
            form, nothing is charged for it, and your copy goes to the reviewers with the rest of
            your file.
          </p>
        </ProtoModal>
      )}

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
