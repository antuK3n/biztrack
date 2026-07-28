import { useEffect, useMemo, useRef, useState } from 'react'
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
import { formatBytes } from '../../lib/format'
import { toApiError } from '../../lib/api'
import { applications, businesses, documents, officeForms, reference } from '../../lib/resources'
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
  EMPTY_FEE_PROFILE,
  FeeProfileStep,
  buildFeeProfile,
  feeProfileMissing,
  feeProfileToDraft,
  type FeeProfileDraft,
} from './FeeProfileStep'
import type {
  ApplicationType,
  Barangay,
  Business,
  BusinessPayload,
  DocumentType,
  OcrSuggestions,
  PsicCode,
} from '../../lib/types'

/*
 * Prototype-fidelity wizard (PDF p26–47): persistent draft chrome (clipboard +
 * title + saved cloud + Clear All), bottom bar with royal pill Next/Submit +
 * green "Part n of N" progress, zoning map step, white form sheets with
 * lettered sections, dashed upload bars + Data Privacy Consent, serif
 * "LGU Section" permit cards, CONFIRMATION modal before submit.
 *
 * Flow rework (user testing): the applicant picks permit types FIRST, and the
 * wizard then shows the COMPLETE map of every section that application will
 * require, upfront and navigable, like the paper form's fixed sections. No
 * section ever appears for the first time mid-flow.
 */

/*
 * Base wizard phases. Permits come first (the one choice that shapes the
 * form), then the fixed sections. Per-office form steps (SANITARY/CEC/FSIC/
 * OCCUPANCY) are inserted after Location & Zoning, so the full sequence is
 * known the moment permits are picked.
 */
type BasePhase = 'permits' | 'business' | 'lines' | 'address' | 'documents' | 'fees' | 'review'
const BASE_PHASES: BasePhase[] = ['permits', 'business', 'lines', 'address', 'documents', 'fees', 'review']

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
        <FieldLabel required>Search your line of business</FieldLabel>
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
              return (
                <div key={line.psic_code_id}>
                  <div className="flex items-end gap-3">
                    <div className="min-w-0 flex-1">
                      {isOther ? (
                        <>
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
                        </>
                      ) : (
                        <p className="truncate text-sm text-ink">{code?.title}</p>
                      )}
                    </div>
                    <div className="w-44">
                      <FieldLabel>Capital (₱)</FieldLabel>
                      <input
                        inputMode="numeric"
                        value={line.capitalization}
                        onChange={(e) =>
                          onChange(
                            lines.map((l) =>
                              l.psic_code_id === line.psic_code_id
                                ? { ...l, capitalization: e.target.value }
                                : l,
                            ),
                          )
                        }
                        className={inputCls}
                      />
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
  // Highest step index the applicant has reached; the map is clickable up to here.
  const [maxVisited, setMaxVisited] = useState(0)
  const [form, setForm] = useState<FormState>(EMPTY)
  // Per-office form payloads keyed by permit-type code (prototype Parts 4-7).
  const [officeData, setOfficeData] = useState<Record<string, OfficeFormData>>({})
  /* Bumped after a permit re-sync to refetch server-derived office-form answers. */
  const [officeFormsVersion, setOfficeFormsVersion] = useState(0)
  // Business & tax profile inputs (revenue-code fee_profile; persisted on the draft).
  const [feeDraft, setFeeDraft] = useState<FeeProfileDraft>(EMPTY_FEE_PROFILE)
  const [touched, setTouched] = useState<Record<string, boolean>>({})
  const [saving, setSaving] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)
  const [draftNote, setDraftNote] = useState<string | null>(null)

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

  // Persisted draft ids (business + application) once the draft exists.
  const [businessId, setBusinessId] = useState<number | null>(null)
  const [applicationId, setApplicationId] = useState<number | null>(null)
  const [uploaded, setUploaded] = useState<Record<number, { name: string; size: number }>>({})
  // "Other Requirements" allows multiple files (repeatable uploads).
  const [otherDocs, setOtherDocs] = useState<{ name: string; size: number }[]>([])
  const [uploadingType, setUploadingType] = useState<number | null>(null)
  const [tracking, setTracking] = useState<string | null>(null)

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
        latitude: b.address.latitude ?? null,
        longitude: b.address.longitude ?? null,
        lines: b.lines.map((l) => ({
          psic_code_id: l.psic_code.id,
          capitalization: l.capitalization ?? '',
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
   * Full step sequence, fixed from the moment permits are chosen (step 1).
   * Office forms slot in after Location & Zoning. Because permits are the
   * first choice, no section ever appears for the first time mid-flow.
   */
  const sequence: StepNode[] = useMemo(() => {
    const nodes: StepNode[] = []
    for (const p of BASE_PHASES) {
      nodes.push({ kind: 'base', phase: p })
      if (p === 'address') {
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

  /* Keep the visited pointer valid if the permit selection shrinks. */
  useEffect(() => {
    setMaxVisited((m) => Math.min(m, sequence.length - 1))
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
   * Required fields still missing on the CURRENT step. Next stays disabled
   * until this is empty; the list doubles as the "what's left" summary.
   */
  const stepMissing: string[] = useMemo(() => {
    if (node.kind === 'office') return officeFormMissing(node.code, officeData[node.code] ?? {})
    switch (node.phase) {
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
        if (!form.name.trim()) missing.push('Business Name')
        if (!form.registration_number.trim()) missing.push('DTI / SEC / CDA Registration Number')
        if (!form.tin.trim()) missing.push('Tax Identification Number (TIN)')
        else if (!tinValid(form.tin)) missing.push('A valid TIN (9 digits, plus branch code)')
        if (!form.registration_type) missing.push('Type of Registration')
        return missing
      }
      case 'lines': {
        if (form.lines.length === 0) return ['Select at least one line of business']
        const otherId = psic.find((c) => c.code === OTHER_PSIC_CODE)?.id
        const otherLine = form.lines.find((l) => l.psic_code_id === otherId)
        return otherLine && !otherLine.line_of_business.trim()
          ? ['Your line of business (typed in, for “Other”)']
          : []
      }
      case 'address': {
        const missing: string[] = []
        if (!form.line1.trim()) missing.push('House No. & Street Name')
        if (!form.barangay_id) missing.push('Barangay')
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
        return feeProfileMissing(feeDraft, { applicationType, lines: feeLines })
      case 'review':
        return []
    }
  }, [node, form, officeData, requiredDocs, uploaded, consent, feeDraft, applicationType, feeLines, psic])

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
  }

  function businessPayload(): BusinessPayload {
    return {
      name: form.name.trim(),
      trade_name: form.trade_name.trim() || undefined,
      registration_type: form.registration_type || undefined,
      registration_number: form.registration_number.trim() || undefined,
      tin: form.tin.trim() || undefined,
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
        capitalization: l.capitalization.trim() || undefined,
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
      } else if (phase === 'business' || phase === 'lines') {
        if (applicationId) {
          const bid = businessId ?? prefillBusinessId
          if (bid) await businesses.update(bid, businessPayload())
        }
      } else if (phase === 'address') {
        if (applicationId) {
          const bid = businessId ?? prefillBusinessId
          if (bid) await businesses.update(bid, businessPayload())
        } else {
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
      setSaving(false)
    }
  }

  async function advance() {
    const ok = await persistOnLeave()
    if (!ok) return
    const target = Math.min(stepIndex + 1, sequence.length - 1)
    setStep(target)
    setMaxVisited((m) => Math.max(m, target))
  }

  async function next() {
    if (stepMissing.length > 0) return
    // Zoning result (p30) — presentational congratulations before leaving the map step.
    if (phase === 'address') {
      setShowZoning(true)
      return
    }
    await advance()
  }

  function back() {
    setStep((s) => Math.max(s - 1, 0))
  }

  /** Jump to any already-visited section from the map (persisting first). */
  async function goTo(index: number) {
    if (saving || index === stepIndex || index > maxVisited) return
    // Moving forward re-checks the current step exactly like Next would.
    if (index > stepIndex && stepMissing.length > 0) return
    const ok = await persistOnLeave()
    if (!ok) return
    setStep(index)
  }

  const canCreateDraft =
    form.permit_type_ids.length > 0 &&
    form.name.trim() !== '' &&
    form.lines.length > 0 &&
    form.line1.trim() !== '' &&
    form.barangay_id !== ''

  /** Explicit "Save draft": pushes every section entered so far in one go. */
  async function saveDraft() {
    setDraftNote(null)
    setSubmitError(null)
    if (!applicationId && !canCreateDraft) {
      setDraftNote(
        'To save a draft, first complete Permits & Certificates, Business Information, Line of Business, and Location & Zoning.',
      )
      return
    }
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
          permit_type_ids: form.permit_type_ids,
          fee_profile: feeProfile,
        })
      } else {
        await applications.update(id, { fee_profile: feeProfile })
      }
      for (const code of selectedOfficeCodes) {
        const data = officeData[code]
        if (data && Object.keys(data).length > 0) await officeForms.save(id, code, data)
      }
      setDraftNote('Draft saved. You can pick it up anytime from your Drafts page.')
    } catch (err) {
      setSubmitError(toApiError(err).message)
    } finally {
      setSaving(false)
    }
  }

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
    setUploadingType(docTypeId)
    setSubmitError(null)
    try {
      const doc = await documents.upload(applicationId, docTypeId, file)
      setUploaded((u) => ({ ...u, [docTypeId]: { name: file.name, size: file.size } }))
      // OCR-lite: surface any suggestions from the upload response (v2).
      if (doc.ocr_suggestions && Object.keys(doc.ocr_suggestions).length > 0) {
        setOcr(doc.ocr_suggestions)
      }
    } catch (err) {
      setSubmitError(toApiError(err).message)
    } finally {
      setUploadingType(null)
    }
  }

  /** "Other Requirements": each upload APPENDS, so multiple files are kept. */
  async function handleOtherUpload(file: File) {
    if (!applicationId || !otherType) return
    setUploadingType(otherType.id)
    setSubmitError(null)
    try {
      await documents.upload(applicationId, otherType.id, file)
      setOtherDocs((d) => [...d, { name: file.name, size: file.size }])
    } catch (err) {
      setSubmitError(toApiError(err).message)
    } finally {
      setUploadingType(null)
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
          latitude: b.address?.latitude ?? null,
          longitude: b.address?.longitude ?? null,
          lines: (b.lines ?? []).map((l) => ({
            psic_code_id: l.psic_code.id,
            capitalization: l.capitalization ?? '',
            // Free text typed against "Other (not listed)". Restoring it is what
            // stops a reopened draft from making the applicant type it again.
            line_of_business: l.line_of_business ?? '',
          })),
          permit_type_ids: ids,
        })
        setFeeDraft(feeProfileToDraft(app.fee_profile, lineIds))
        // Restore uploaded documents by document-type code.
        const codeToId = new Map<string, number>()
        for (const dt of refData.documentTypes) codeToId.set(dt.code, dt.id)
        const restored: Record<number, { name: string; size: number }> = {}
        const others: { name: string; size: number }[] = []
        for (const doc of app.documents ?? []) {
          const code = doc.document_type?.code
          if (!code) continue
          if (code === OTHER_DOC_CODE) {
            others.push({ name: doc.original_filename, size: doc.size_bytes })
          } else {
            const dtId = codeToId.get(code)
            if (dtId != null) restored[dtId] = { name: doc.original_filename, size: doc.size_bytes }
          }
        }
        setUploaded(restored)
        setOtherDocs(others)
        // Everything was visited when the draft was saved: open the whole map.
        const officeCount = pts.filter((pt) => ids.includes(pt.id) && hasOfficeForm(pt.code)).length
        setMaxVisited(BASE_PHASES.length + officeCount - 1)
      } catch (err) {
        if (active) setSubmitError(toApiError(err).message)
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

  const part = stepIndex + 1

  return (
    <div className="mx-auto max-w-5xl pb-4">
      {/* ── Persistent wizard chrome (p32/p34) ─────────────────────────── */}
      <div className="mb-5 flex items-center gap-4">
        <ClipboardIcon size={34} className="shrink-0 text-royal" />
        {form.name.trim() ? (
          <span className="truncate text-xl font-bold text-ink">{form.name}</span>
        ) : (
          <span className="text-xl font-bold text-ink underline underline-offset-4">
            Title of Application
          </span>
        )}
        <span className="ml-3 flex items-center gap-2">
          {saving ? (
            <span className="text-xs italic text-ink-muted">Saving…</span>
          ) : applicationId ? (
            <>
              <CloudSavedIcon />
              <span className="text-xs italic text-ink-muted">Draft saved</span>
            </>
          ) : (
            <span className="text-xs italic text-ink-muted">Not saved yet</span>
          )}
        </span>
        <span className="flex-1" />
        <button
          type="button"
          onClick={() => setShowClear(true)}
          className="text-sm font-semibold text-royal underline underline-offset-2 hover:text-royal-hover"
        >
          Clear All
        </button>
      </div>

      {/* ── Full section map: every step this application requires ─────── */}
      <ol className="mb-3 flex flex-wrap gap-2" aria-label="Application sections">
        {sequence.map((n, i) => {
          const label = n.kind === 'base' ? BASE_LABELS[n.phase] : OFFICE_LABELS[n.code]
          const current = i === stepIndex
          const reachable = i <= maxVisited
          return (
            <li key={n.kind === 'base' ? n.phase : n.code}>
              <button
                type="button"
                onClick={() => void goTo(i)}
                disabled={!reachable || current}
                aria-current={current ? 'step' : undefined}
                className={`flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors ${
                  current
                    ? 'border-royal bg-royal text-white'
                    : reachable
                      ? 'border-royal/40 bg-white text-royal hover:bg-royal-tint'
                      : 'border-input-border bg-white text-ink-muted'
                }`}
              >
                <span className="tnum">{i + 1}</span>
                {label}
                {i < stepIndex && <CheckIcon size={12} />}
              </button>
            </li>
          )
        })}
      </ol>
      {phase === 'permits' && (
        <p className="mb-5 text-xs text-ink-secondary">
          This is the complete list of sections your application will go through, just like the
          paper form. Selecting a certificate below adds its office form to the list right away,
          never in the middle of the flow.
        </p>
      )}
      {phase !== 'permits' && <div className="mb-5" />}

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
            The Mayor's / Business Permit is stated, not offered: it is the
            outcome of the whole application, so it is always attached and
            BPLO always receives the file.
          */}
          <div className="mb-6 rounded-lg border border-royal/30 bg-royal-tint px-5 py-4">
            <p className="text-sm font-bold text-ink">
              You are applying for the Mayor’s / Business Permit
            </p>
            <p className="mt-1 text-sm text-ink-secondary">
              That permit is the result of this application, so it is always included and goes to
              the Business Permits and Licensing Office. Below, add the clearances your business
              needs to support it.
            </p>
          </div>
          <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            {clearanceTypes.map((pt) => {
              const selected = form.permit_type_ids.includes(pt.id)
              return (
                <div key={pt.id} className="flex flex-col rounded-2xl bg-white px-5 py-5 shadow-card">
                  <p className="text-lg font-bold leading-snug text-ink">{pt.name}</p>
                  <p className="display-serif mt-2 text-sm italic text-ink-secondary">
                    {pt.department.name}
                  </p>
                  {hasOfficeForm(pt.code) && (
                    <p className="mt-2 text-xs text-ink-muted">
                      Adds its own application form section above.
                    </p>
                  )}
                  <div className="mt-5 flex flex-1 items-end">
                    <button
                      type="button"
                      aria-pressed={selected}
                      onClick={() =>
                        update(
                          'permit_type_ids',
                          selected
                            ? form.permit_type_ids.filter((id) => id !== pt.id)
                            : [...form.permit_type_ids, pt.id],
                        )
                      }
                      className={`w-full rounded-sm px-3 py-2 text-sm font-semibold underline underline-offset-2 transition-colors ${
                        selected
                          ? 'border-2 border-royal bg-white text-royal'
                          : 'bg-royal text-white hover:bg-royal-hover'
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
            </div>
          )}
          <div className="mt-4 space-y-4">
            <div>
              <FieldLabel required>DTI / SEC / CDA Registration Number</FieldLabel>
              <input
                value={form.registration_number}
                onChange={(e) => update('registration_number', e.target.value)}
                onBlur={() => touch('registration_number')}
                placeholder="Enter registration number"
                className={inputCls}
                aria-invalid={Boolean(fieldErrors.registration_number)}
              />
              {fieldErrors.registration_number && (
                <p className="mt-1 text-xs font-medium text-s-red">
                  {fieldErrors.registration_number}
                </p>
              )}
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <FieldLabel required>Tax Identification Number (TIN)</FieldLabel>
                <input
                  inputMode="numeric"
                  maxLength={20}
                  pattern="[\d\s.-]{9,20}"
                  value={form.tin}
                  onChange={(e) => update('tin', e.target.value)}
                  onBlur={() => touch('tin')}
                  placeholder="123-456-789-000"
                  className={inputCls}
                  aria-invalid={Boolean(fieldErrors.tin)}
                />
                {fieldErrors.tin && (
                  <p className="mt-1 text-xs font-medium text-s-red">{fieldErrors.tin}</p>
                )}
              </div>
              <div>
                <FieldLabel required>Business Name</FieldLabel>
                <input
                  value={form.name}
                  onChange={(e) => update('name', e.target.value)}
                  onBlur={() => touch('name')}
                  placeholder="Registered business name"
                  className={inputCls}
                  aria-invalid={Boolean(fieldErrors.name)}
                />
                {fieldErrors.name && <p className="mt-1 text-xs font-medium text-s-red">{fieldErrors.name}</p>}
              </div>
            </div>
            <div>
              <FieldLabel>Trade Name / Franchise</FieldLabel>
              <input
                value={form.trade_name}
                onChange={(e) => update('trade_name', e.target.value)}
                placeholder="Trade name, if any"
                className={inputCls}
              />
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
              <p className="tnum bg-white px-4 py-2 text-xs text-ink-secondary">
                {form.latitude !== null
                  ? `Pinned at ${form.latitude}, ${form.longitude}`
                  : 'Click the map to drop a pin where your business is.'}
              </p>
            </div>
            <div className="space-y-4">
              <div>
                <FieldLabel required>Line of Business</FieldLabel>
                <select className={inputCls} disabled>
                  <option>
                    {form.lines.length > 0
                      ? psic.find((c) => c.id === form.lines[0].psic_code_id)?.title ?? 'Line of Business'
                      : 'Selected in the Line of Business section'}
                  </option>
                </select>
              </div>
              <div>
                <FieldLabel required>House No. &amp; Street Name</FieldLabel>
                <input
                  value={form.line1}
                  onChange={(e) => update('line1', e.target.value)}
                  onBlur={() => touch('line1')}
                  placeholder="House No. and Street Name"
                  className={inputCls}
                  aria-invalid={Boolean(fieldErrors.line1)}
                />
                {fieldErrors.line1 && <p className="mt-1 text-xs font-medium text-s-red">{fieldErrors.line1}</p>}
              </div>
              <div>
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
                {fieldErrors.barangay_id && (
                  <p className="mt-1 text-xs font-medium text-s-red">{fieldErrors.barangay_id}</p>
                )}
              </div>
              <div>
                <FieldLabel>Locational Group/Landmark</FieldLabel>
                <input
                  value={form.line2}
                  onChange={(e) => update('line2', e.target.value)}
                  placeholder="Locational Group/Landmark"
                  className={inputCls}
                />
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
                return (
                  <label
                    key={dt.id}
                    className={`flex cursor-pointer items-center gap-4 rounded-lg border-2 border-dashed border-input-border bg-input/50 px-5 py-3.5 transition-colors hover:bg-input ${
                      busy ? 'opacity-60' : ''
                    }`}
                  >
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
                    {done && (
                      <span className="inline-flex shrink-0 items-center gap-1.5 text-sm font-semibold text-s-green">
                        <CheckIcon size={16} /> Uploaded
                      </span>
                    )}
                    <input
                      type="file"
                      accept=".pdf,.jpg,.jpeg,.png"
                      className="sr-only"
                      disabled={busy}
                      onChange={(e) => {
                        const file = e.target.files?.[0]
                        if (file) void handleUpload(dt.id, file)
                        e.target.value = ''
                      }}
                    />
                  </label>
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
                  {otherDocs.map((f, i) => (
                    <li
                      key={`${f.name}-${i}`}
                      className="flex items-center gap-3 rounded-lg border border-input-border bg-input/50 px-4 py-2.5"
                    >
                      <span className="text-s-green">
                        <CheckIcon size={16} />
                      </span>
                      <span className="min-w-0 flex-1 truncate text-sm text-ink">{f.name}</span>
                      <span className="tnum shrink-0 text-xs text-ink-muted">{formatBytes(f.size)}</span>
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
            />
          </div>
        </FormSheet>
      )}

      {/* ── LGU Section — all clearances applied (p46) ─────────────────── */}
      {phase === 'review' && (
        <div>
          <h1 className="display-serif mb-1 text-2xl text-ink-secondary">LGU Section</h1>
          <div className="mb-6 h-px bg-ink/40" />
          <div className="flex min-h-64 flex-col items-center justify-center gap-2 py-10">
            <p className="text-lg font-medium text-royal">All clearances are applied for</p>
            <p className="text-sm text-ink-muted">
              {permitTypes
                .filter((pt) => form.permit_type_ids.includes(pt.id))
                .map((pt) => pt.name)
                .join(' · ')}
            </p>
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
            <PillButton
              onClick={() => void saveDraft()}
              disabled={saving}
              className="bg-white !text-royal border-2 border-royal hover:bg-royal-tint"
            >
              Save draft
            </PillButton>
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
          {draftNote && <p className="max-w-md text-xs font-medium text-royal">{draftNote}</p>}
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
            onCancel={() => setShowZoning(false)}
          >
            <p className="text-base leading-relaxed">
              The declared use for{' '}
              <span className="font-bold underline underline-offset-2">{form.name || 'your business'}</span>{' '}
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
             * Not "CONGRATULATIONS": nothing has been approved here. The system
             * records the pin, CPDO rules on conformance later. Announcing a
             * result we have not determined is how an applicant ends up
             * believing their zoning passed.
             */
            title="Location recorded"
            tone="green"
            cancelLabel="Back"
            confirmLabel="Proceed to Application"
            onCancel={() => setShowZoning(false)}
            onConfirm={() => {
              setShowZoning(false)
              void advance()
            }}
          >
            <p className="text-base leading-relaxed">
              The location for{' '}
              <span className="font-bold underline underline-offset-2">{form.name || 'your business'}</span>{' '}
              in{' '}
              <span className="font-bold uppercase underline underline-offset-2">
                {barangayName ?? 'Area Location'}
              </span>{' '}
              has been recorded for your zoning clearance. The Zoning Office (CPDO) evaluates
              conformance during processing. You may now proceed with your application.
            </p>
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
