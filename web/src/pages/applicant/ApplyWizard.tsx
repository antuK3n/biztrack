import { useEffect, useMemo, useState } from 'react'
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
  type OfficeFormCode,
  type OfficeFormData,
} from './OfficeFormStep'
import type {
  ApplicationType,
  Barangay,
  Business,
  BusinessPayload,
  DocumentType,
  OcrSuggestions,
  PermitType,
  PsicCode,
} from '../../lib/types'

/*
 * Prototype-fidelity wizard (PDF p26–47): persistent draft chrome (clipboard +
 * title + "All Changes Saved" cloud + Clear All), bottom bar with royal pill
 * Next/Submit + green "Part n of 8" progress, zoning map step, white form
 * sheets with lettered sections, dashed upload bars + Data Privacy Consent,
 * serif "LGU Section" permit cards, CONFIRMATION modal before submit.
 * The working steps/data/submit flow is unchanged — only the framing is new.
 */

/*
 * Base wizard phases. Per-office form steps (SANITARY/CEC/FSIC/OCCUPANCY) are
 * inserted dynamically between Permits and Documents, so the running sequence
 * and the "Part n of N" count adjust to the selected inspection-office permits.
 */
type BasePhase = 'business' | 'lines' | 'address' | 'permits' | 'documents' | 'review'
const BASE_PHASES: BasePhase[] = ['business', 'lines', 'address', 'permits', 'documents', 'review']

/** A single running step: either a base phase or one office form sheet. */
type StepNode = { kind: 'base'; phase: BasePhase } | { kind: 'office'; code: OfficeFormCode }

const TYPE_META: Record<ApplicationType, { title: string; ref: string }> = {
  new: { title: 'Application for New Business Permit', ref: 'MCG-BPLO-FO-001 · v2.0' },
  renewal: { title: 'Application for Renewal of Business Permit', ref: 'MCG-BPLO-FO-002 · v2.0' },
  amendment: { title: 'Application for Amendment of Business Permit', ref: 'MCG-BPLO-FO-003 · v2.0' },
}

/* One selected line of business (PSIC code + optional capitalization). */
interface LineDraft {
  psic_code_id: number
  capitalization: string
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

/* ── PSIC picker (Part 2) ─────────────────────────────────────────────── */
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
  const results = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return codes.slice(0, 8)
    return codes
      .filter((c) => c.title.toLowerCase().includes(q) || c.code.includes(q))
      .slice(0, 12)
  }, [codes, query])

  function toggle(code: PsicCode) {
    const exists = lines.find((l) => l.psic_code_id === code.id)
    if (exists) onChange(lines.filter((l) => l.psic_code_id !== code.id))
    else onChange([...lines, { psic_code_id: code.id, capitalization: '' }])
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
      </div>

      <ul className="divide-y divide-line overflow-hidden rounded-lg border border-input-border">
        {results.length === 0 ? (
          <li className="px-4 py-4 text-sm text-ink-secondary">No matches. Try another word.</li>
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
      </ul>

      {lines.length > 0 && (
        <div className="rounded-lg border border-input-border bg-royal-tint p-4">
          <p className="mb-3 text-sm font-bold text-ink">Selected ({lines.length})</p>
          <div className="space-y-3">
            {lines.map((line) => {
              const code = codes.find((c) => c.id === line.psic_code_id)
              return (
                <div key={line.psic_code_id} className="flex items-end gap-3">
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm text-ink">{code?.title}</p>
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
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}

/* ── Requirements helper ──────────────────────────────────────────────── */
function requiredDocsFor(permitTypes: PermitType[], selectedIds: number[]): DocumentType[] {
  const map = new Map<number, DocumentType>()
  permitTypes
    .filter((pt) => selectedIds.includes(pt.id))
    .forEach((pt) => pt.document_types.forEach((dt) => map.set(dt.id, dt)))
  return [...map.values()]
}

export function ApplyWizard() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const rawType = searchParams.get('type')
  const applicationType: ApplicationType =
    rawType === 'renewal' || rawType === 'amendment' ? rawType : 'new'
  const typeMeta = TYPE_META[applicationType]

  const isReuse = applicationType === 'renewal' || applicationType === 'amendment'
  /*
   * Zoning outcome is presentational until real zoning data exists: the wizard
   * shows CONGRATULATIONS by default, and the red SORRY modal (p031) only when
   * a `?zoning=deny` debug query param is present.
   */
  const zoningDenied = searchParams.get('zoning') === 'deny'

  const [step, setStep] = useState(0)
  const [form, setForm] = useState<FormState>(EMPTY)
  // Per-office form payloads keyed by permit-type code (prototype Parts 4-7).
  const [officeData, setOfficeData] = useState<Record<string, OfficeFormData>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)

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

  // Created draft ids (populated once we reach the documents step).
  const [applicationId, setApplicationId] = useState<number | null>(null)
  const [uploaded, setUploaded] = useState<Record<number, { name: string; size: number }>>({})
  const [uploadingType, setUploadingType] = useState<number | null>(null)
  const [tracking, setTracking] = useState<string | null>(null)

  // Prototype presentational modals — none of these fabricate API calls.
  const [showClear, setShowClear] = useState(false)
  const [showZoning, setShowZoning] = useState(false)
  const [showConfirm, setShowConfirm] = useState(false)
  const [submissionFor, setSubmissionFor] = useState<PermitType | null>(null)
  const [submissionFile, setSubmissionFile] = useState<string | null>(null)
  const [consent, setConsent] = useState(false)

  const refs = useAsync(
    async () => ({
      barangays: await reference.barangays(),
      psic: await reference.psicCodes(),
      permitTypes: await reference.permitTypes(),
    }),
    [],
  )

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((f) => ({ ...f, [key]: value }))
    if (errors[key as string]) setErrors((e) => ({ ...e, [key as string]: '' }))
  }

  /** Renewal/amendment: pull the prior permit + prefill fields for a business. */
  async function selectBusinessForReuse(businessId: number | null) {
    setPrefillBusinessId(businessId)
    setPriorPermitId(null)
    setPrefillNote(null)
    if (!businessId) {
      setForm(EMPTY)
      return
    }
    setPrefilling(true)
    setSubmitError(null)
    try {
      const result = await businesses.prefill(businessId, applicationType as 'renewal' | 'amendment')
      const b = result.business
      setForm({
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
        })),
        permit_type_ids: result.suggested_permit_type_ids,
      })
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

  function validateStep(): boolean {
    const next: Record<string, string> = {}
    if (phase === 'business') {
      if (!form.name.trim()) next.name = 'Enter your business name.'
    }
    if (phase === 'lines' && form.lines.length === 0) next.lines = 'Pick at least one line of business.'
    if (phase === 'address') {
      if (!form.line1.trim()) next.line1 = 'Enter your street address.'
      if (!form.barangay_id) next.barangay_id = 'Choose your barangay.'
    }
    if (phase === 'permits' && form.permit_type_ids.length === 0)
      next.permit_type_ids = 'Apply for at least one permit.'
    setErrors(next)
    return Object.keys(next).length === 0
  }

  /* Persist business + application draft once, when leaving the permits step. */
  async function ensureDraft(): Promise<number | null> {
    if (applicationId) return applicationId
    setSaving(true)
    setSubmitError(null)
    try {
      const payload: BusinessPayload = {
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
        lines: form.lines.map((l) => ({
          psic_code_id: l.psic_code_id,
          capitalization: l.capitalization.trim() || undefined,
        })),
      }
      // Renewal/amendment reuse the chosen existing business; new applications
      // create one inline.
      const businessId = prefillBusinessId ?? (await businesses.create(payload)).id
      const app = await applications.create({
        business_id: businessId,
        application_type: applicationType,
        permit_type_ids: form.permit_type_ids,
        ...(priorPermitId ? { prior_permit_id: priorPermitId } : {}),
      })
      setApplicationId(app.id)
      return app.id
    } catch (err) {
      setSubmitError(toApiError(err).message)
      return null
    } finally {
      setSaving(false)
    }
  }

  /** Persist the current office-form step's opaque JSON (draft/returned only). */
  async function saveOfficeForm(id: number, code: OfficeFormCode): Promise<boolean> {
    setSaving(true)
    setSubmitError(null)
    try {
      await officeForms.save(id, code, officeData[code] ?? {})
      return true
    } catch (err) {
      setSubmitError(toApiError(err).message)
      return false
    } finally {
      setSaving(false)
    }
  }

  async function advance() {
    // Leaving Permits → first office form (or Documents): persist the draft first.
    if (phase === 'permits') {
      const id = await ensureDraft()
      if (!id) return
    }
    // Leaving an office-form step: PUT its payload before moving on.
    if (officeCode) {
      const id = applicationId ?? (await ensureDraft())
      if (!id) return
      const ok = await saveOfficeForm(id, officeCode)
      if (!ok) return
    }
    setStep((s) => Math.min(s + 1, sequence.length - 1))
  }

  async function next() {
    if (!validateStep()) return
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
    } else if (phase === 'permits' && !applicationId) {
      setForm((f) => ({ ...f, permit_type_ids: [] }))
    } else if (phase === 'documents') {
      setConsent(false)
    }
    setErrors({})
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

  const permitTypes = refs.data?.permitTypes ?? []
  const barangays: Barangay[] = refs.data?.barangays ?? []
  const psic: PsicCode[] = refs.data?.psic ?? []
  const requiredDocs = requiredDocsFor(permitTypes, form.permit_type_ids)
  const barangayName = barangays.find((b) => String(b.id) === form.barangay_id)?.name

  /*
   * Codes of the selected inspection-office permits that have a prototype form,
   * in the canonical office order (SANITARY, CEC, FSIC, OCCUPANCY). Drives one
   * office-form step each, inserted between Permits and Documents.
   */
  const selectedOfficeCodes: OfficeFormCode[] = useMemo(() => {
    const chosen = new Set(
      permitTypes.filter((pt) => form.permit_type_ids.includes(pt.id)).map((pt) => pt.code),
    )
    return OFFICE_FORM_CODES.filter((c) => chosen.has(c))
  }, [permitTypes, form.permit_type_ids])

  /* Running step sequence: office forms slot in after the Permits phase. */
  const sequence: StepNode[] = useMemo(() => {
    const nodes: StepNode[] = []
    for (const phase of BASE_PHASES) {
      nodes.push({ kind: 'base', phase })
      if (phase === 'permits') {
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

  /* Load any previously-saved office-form payloads once the draft exists. */
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
  }, [applicationId])

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

  if (refs.loading) {
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
      <div className="mb-6 flex items-center gap-4">
        <ClipboardIcon size={34} className="shrink-0 text-royal" />
        {form.name.trim() ? (
          <span className="truncate text-xl font-bold text-ink">{form.name}</span>
        ) : (
          <span className="text-xl font-bold text-ink underline underline-offset-4">
            Title of Application
          </span>
        )}
        <span className="ml-3 flex items-center gap-2">
          <CloudSavedIcon />
          <span className="text-xs italic text-ink-muted">All Changes Saved</span>
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

      {submitError && (
        <div className="mb-4">
          <Alert variant="error">{submitError}</Alert>
        </div>
      )}

      {/* ── Part 1 · Business information (form sheet, p32) ────────────── */}
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
              <FieldLabel>DTI / SEC / CDA Registration Number</FieldLabel>
              <input
                value={form.registration_number}
                onChange={(e) => update('registration_number', e.target.value)}
                placeholder="Enter registration number"
                className={inputCls}
              />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <FieldLabel>Tax Identification Number (TIN)</FieldLabel>
                <input
                  inputMode="numeric"
                  value={form.tin}
                  onChange={(e) => update('tin', e.target.value)}
                  placeholder="000-000-000-000"
                  className={inputCls}
                />
              </div>
              <div>
                <FieldLabel required>Business Name</FieldLabel>
                <input
                  value={form.name}
                  onChange={(e) => update('name', e.target.value)}
                  placeholder="Registered business name"
                  className={inputCls}
                  aria-invalid={Boolean(errors.name)}
                />
                {errors.name && <p className="mt-1 text-xs font-medium text-s-red">{errors.name}</p>}
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
              <FieldLabel>Type of Registration</FieldLabel>
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

      {/* ── Part 2 · Lines of business (form sheet) ────────────────────── */}
      {phase === 'lines' && (
        <FormSheet meta={typeMeta}>
          <SectionMarker letter="B" label="Line of Business" />
          <div className="mt-4">
            {errors.lines && <p className="mb-3 text-sm font-medium text-s-red">{errors.lines}</p>}
            <LinesStep codes={psic} lines={form.lines} onChange={(lines) => update('lines', lines)} />
          </div>
        </FormSheet>
      )}

      {/* ── Part 3 · Zoning — Selecting Business Location (p27) ────────── */}
      {phase === 'address' && (
        <div>
          <h1 className="mb-1 text-2xl font-bold text-ink">Zoning - Selecting Business Location</h1>
          <div className="mb-6 h-px bg-ink/40" />
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
                      : 'Selected in Part 2'}
                  </option>
                </select>
              </div>
              <div>
                <FieldLabel required>House No. &amp; Street Name</FieldLabel>
                <input
                  value={form.line1}
                  onChange={(e) => update('line1', e.target.value)}
                  placeholder="House No. and Street Name"
                  className={inputCls}
                  aria-invalid={Boolean(errors.line1)}
                />
                {errors.line1 && <p className="mt-1 text-xs font-medium text-s-red">{errors.line1}</p>}
              </div>
              <div>
                <FieldLabel required>Barangay Name</FieldLabel>
                <select
                  value={form.barangay_id}
                  onChange={(e) => update('barangay_id', e.target.value)}
                  className={inputCls}
                  aria-invalid={Boolean(errors.barangay_id)}
                >
                  <option value="">Barangay Name</option>
                  {barangays.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name}
                    </option>
                  ))}
                </select>
                {errors.barangay_id && (
                  <p className="mt-1 text-xs font-medium text-s-red">{errors.barangay_id}</p>
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

      {/* ── LGU Section — permit type cards (p37) ─────────────────────── */}
      {phase === 'permits' && (
        <div>
          <h1 className="display-serif mb-1 text-2xl text-ink-secondary">LGU Section</h1>
          <div className="mb-6 h-px bg-ink/40" />
          {errors.permit_type_ids && (
            <p className="mb-4 text-sm font-medium text-s-red">{errors.permit_type_ids}</p>
          )}
          <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            {permitTypes.map((pt) => {
              const selected = form.permit_type_ids.includes(pt.id)
              return (
                <div key={pt.id} className="flex flex-col rounded-2xl bg-white px-5 py-5 shadow-card">
                  <p className="text-lg font-bold leading-snug text-ink">{pt.name}</p>
                  <p className="display-serif mt-2 text-sm italic text-ink-secondary">
                    {pt.department.name}
                  </p>
                  <div className="mt-5 grid flex-1 grid-cols-2 items-end gap-3">
                    <button
                      type="button"
                      onClick={() => setSubmissionFor(pt)}
                      className="rounded-sm bg-royal px-3 py-2 text-sm font-semibold text-white underline underline-offset-2 hover:bg-royal-hover"
                    >
                      Submit
                    </button>
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
                      className={`rounded-sm px-3 py-2 text-sm font-semibold underline underline-offset-2 transition-colors ${
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

      {/* ── Per-office application forms (Parts 4-7, p040-043) ─────────── */}
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
            Upload each requirement as a PDF or image (max 10 MB). You can change files before submitting.
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
                        {dt.is_required === false && (
                          <span className="ml-1 font-normal text-ink-muted">(optional)</span>
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
            </label>
          </div>
        </div>
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

      {/* ── Bottom bar: pill button + green progress + Part n of 8 ─────── */}
      <div className="mt-10 grid items-center gap-6 sm:grid-cols-[minmax(9rem,auto)_1fr_minmax(9rem,auto)]">
        <div className="flex items-center gap-4">
          {!isLast ? (
            <PillButton onClick={next} disabled={saving || (phase === 'documents' && !consent)} className="min-w-28">
              {saving ? 'Saving…' : 'Next'}
            </PillButton>
          ) : (
            <PillButton onClick={() => setShowConfirm(true)} disabled={saving} className="min-w-28">
              Submit
            </PillButton>
          )}
          {step > 0 && (
            <button
              type="button"
              onClick={back}
              className="text-sm font-semibold text-ink-secondary underline underline-offset-2 hover:text-ink"
            >
              Back
            </button>
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
              The new business for{' '}
              <span className="font-bold underline underline-offset-2">{form.name || 'your business'}</span>{' '}
              is non-conforming / not within the allowed use for{' '}
              <span className="font-bold uppercase underline underline-offset-2">
                {barangayName ?? 'Area Location'}
              </span>
              .
            </p>
          </ProtoModal>
        ) : (
          <ProtoModal
            title="CONGRATULATIONS!"
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
              The new business for{' '}
              <span className="font-bold underline underline-offset-2">{form.name || 'your business'}</span>{' '}
              is conforming / within the allowed use for{' '}
              <span className="font-bold uppercase underline underline-offset-2">
                {barangayName ?? 'Area Location'}
              </span>
              . You may now proceed with the processing of your Business Permit Application.
            </p>
          </ProtoModal>
        ))}

      {/* ── SUBMISSION · per-permit upload (p39) — presentational ──────── */}
      {submissionFor && (
        <ProtoModal
          title="SUBMISSION"
          cancelLabel="Cancel"
          confirmLabel="Submit"
          onCancel={() => {
            setSubmissionFor(null)
            setSubmissionFile(null)
          }}
          onConfirm={() => {
            // Files are actually uploaded in the Documents part — this modal is
            // the prototype's per-clearance framing; no separate API exists.
            setSubmissionFor(null)
            setSubmissionFile(null)
          }}
        >
          <p className="text-xl font-medium text-ink">{submissionFor.name}</p>
          <p className="mt-1 text-sm italic text-ink-muted">file type: png, jpg, pdf only</p>
          <label className="mt-5 flex cursor-pointer items-stretch overflow-hidden rounded-lg border border-input-border">
            <span className="flex-1 bg-input px-4 py-3 text-sm text-ink-muted">
              {submissionFile ?? 'Upload file.'}
            </span>
            <span className="flex items-center bg-royal px-3.5 text-white">
              <UploadIcon size={18} />
            </span>
            <input
              type="file"
              accept=".pdf,.jpg,.jpeg,.png"
              className="sr-only"
              onChange={(e) => setSubmissionFile(e.target.files?.[0]?.name ?? null)}
            />
          </label>
          <p className="mt-3 text-xs text-ink-muted">
            You can also attach this document in the Documents part before submitting.
          </p>
        </ProtoModal>
      )}

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
