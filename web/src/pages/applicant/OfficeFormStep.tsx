import type { ReactNode } from 'react'
import { FieldLabel, inputCls } from '../../components/ui/Proto'
import { formatDate } from '../../lib/format'

/*
 * Per-office application form sheets (UI prototype Parts 4-7, pages 040-043).
 * One step per selected inspection-office permit type that has a prototype form:
 * SANITARY, CEC, FSIC, OCCUPANCY. Fields are free-form and persisted verbatim
 * as opaque JSON via PUT office-forms/{code}. The auto-generated control numbers
 * (SP-2026-…, CEC-2026-…, FSIC-…) are read-only placeholders per the prototype —
 * the real number is minted server-side on issuance.
 *
 * The form never asks for anything the system already holds. Whether this is a
 * new or renewal application, the date it was filed, and which certificate the
 * FSIC is for all come from the application record: the API derives them on
 * every read and write, and they are shown here read-only so the sheet still
 * carries what the paper form needs. Issuance dates ("Date Issued") belong to
 * the office that issued the document and are filled in during officer review.
 */

export type OfficeFormData = Record<string, unknown>

/** Which permit-type codes have a prototype form, in wizard order. */
export const OFFICE_FORM_CODES = ['SANITARY', 'CEC', 'FSIC', 'OCCUPANCY'] as const
export type OfficeFormCode = (typeof OFFICE_FORM_CODES)[number]

/** Kicker + h1 + form-ref for each office form sheet (verbatim from prototype). */
export const OFFICE_FORM_META: Record<
  OfficeFormCode,
  { kicker: string; title: string; ref: string }
> = {
  SANITARY: {
    kicker: 'City Health Office · Sanitation Division',
    title: 'Application for Sanitary Permit to Operate',
    ref: 'Pursuant to PD 856, Code on Sanitation of the Philippines',
  },
  CEC: {
    kicker: 'City Environmental & Natural Resources Office',
    title: 'Application for Certificate of Environmental Clearance (CEC)',
    ref: 'MCG-CENRO-FO-001 · v2.0',
  },
  FSIC: {
    kicker: 'Bureau of Fire Protection · Malabon City Fire Station',
    title: 'Fire Safety Inspection Certificate (FSIC) Application',
    ref: 'BFP-QSF-FSED-002 · Rev. 02 (08.24.20)',
  },
  OCCUPANCY: {
    kicker: 'Office of the Building Official',
    title: 'Certificate of Occupancy & Fire Safety Inspection Certificate',
    ref: 'Unified Application Form',
  },
}

/** True if a permit-type code renders a prototype office form. */
export function hasOfficeForm(code: string): code is OfficeFormCode {
  return (OFFICE_FORM_CODES as readonly string[]).includes(code)
}

/** Today as a local-timezone YYYY-MM-DD string (input[type=date] max). */
export function todayISO(): string {
  const d = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/**
 * Required-field check per office form. Returns the labels still missing (or
 * invalid) so the wizard can disable Next and list what is left. Counts and
 * reference numbers stay optional; only the core decisions are required.
 */
export function officeFormMissing(code: OfficeFormCode, data: OfficeFormData): string[] {
  const has = (key: string) => typeof data[key] === 'string' && (data[key] as string).trim() !== ''
  const missing: string[] = []
  // Derived answers (type of application, filing date, certificate applied for)
  // are never required here: the API fills them in, so they cannot be missing.
  if (code === 'SANITARY') {
    if (!has('sanitary_classification')) missing.push('Sanitary Classification')
  }
  if (code === 'CEC') {
    if (has('owner_birthday') && (data.owner_birthday as string) >= todayISO()) {
      missing.push('Owner birthday must be a past date')
    }
  }
  if (code === 'OCCUPANCY') {
    if (!has('application_type')) missing.push('Application Type')
  }
  return missing
}

/* ── Shared field primitives (prototype chip pills, read-only control no.) ── */

/** Royal square with white section letter, matching the wizard's SectionMarker. */
function SectionMarker({ letter, label, required }: { letter: string; label: string; required?: boolean }) {
  return (
    <div className="flex items-center gap-2.5">
      <span className="flex h-6 w-6 items-center justify-center rounded-sm bg-royal text-[13px] font-bold text-white">
        {letter}
      </span>
      <h2 className="text-[15px] font-bold text-ink">
        {label}
        {required && <span className="text-s-red"> *</span>}
      </h2>
    </div>
  )
}

/** Radio/checkbox chip pill (sanitary classification, Full/Partial occupancy). */
function ChipOption({
  label,
  selected,
  onClick,
}: {
  label: string
  selected: boolean
  onClick: () => void
}) {
  return (
    <button
      type="button"
      role="radio"
      aria-checked={selected}
      onClick={onClick}
      className={`flex items-center gap-2.5 rounded-md border px-4 py-2.5 text-sm font-medium transition-colors ${
        selected
          ? 'border-royal bg-input text-ink'
          : 'border-input-border bg-input/60 text-ink-secondary hover:bg-input'
      }`}
    >
      <span
        className={`h-3.5 w-3.5 shrink-0 rounded-full border-2 ${
          selected ? 'border-royal bg-royal' : 'border-input-border bg-white'
        }`}
      />
      {label}
    </button>
  )
}

/** A row of chip options bound to a single string value. */
function ChipRow({
  options,
  value,
  onChange,
}: {
  options: string[]
  value: string
  onChange: (v: string) => void
}) {
  return (
    <div className="flex flex-wrap gap-2.5">
      {options.map((o) => (
        <ChipOption key={o} label={o} selected={value === o} onClick={() => onChange(o)} />
      ))}
    </div>
  )
}

/** Read-only auto-number field (SP-2026-…, CEC-2026-…, FSIC-…). */
function ControlNoField({
  label,
  placeholder,
}: {
  label: ReactNode
  placeholder: string
}) {
  return (
    <div>
      <FieldLabel>{label}</FieldLabel>
      <input
        value=""
        readOnly
        aria-readonly="true"
        placeholder={placeholder}
        className={`${inputCls} tnum cursor-not-allowed bg-line/60 text-ink-secondary`}
      />
    </div>
  )
}

const AutoTag = () => <span className="font-normal text-ink-muted"> (auto-generated)</span>

const FromApplicationTag = () => (
  <span className="font-normal text-ink-muted"> (from your application)</span>
)

/**
 * An answer the system already holds, shown read-only. The API derives it from
 * the application record on every read and write, so the applicant confirms it
 * instead of re-typing what they already told us.
 */
function DerivedField({
  label,
  value,
  hint,
}: {
  label: ReactNode
  value: string
  hint?: string
}) {
  return (
    <div>
      <FieldLabel>{label}</FieldLabel>
      <input
        value={value}
        readOnly
        aria-readonly="true"
        placeholder="Filled in from your application"
        className={`${inputCls} cursor-not-allowed bg-line/60 text-ink-secondary`}
      />
      {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
    </div>
  )
}

/* ── Per-office field bodies ──────────────────────────────────────────── */

function get(data: OfficeFormData, key: string): string {
  const v = data[key]
  return typeof v === 'string' ? v : ''
}

/** The filing date, formatted for display; blank until the API supplies it. */
function applicationDate(data: OfficeFormData): string {
  const raw = get(data, 'application_date')
  return raw === '' ? '' : formatDate(raw)
}

/** "Date of Application" reads from submitted_at and is never typed (item 11). */
function ApplicationDateField({ data }: { data: OfficeFormData }) {
  return (
    <DerivedField
      label={
        <>
          Date of Application
          <AutoTag />
        </>
      }
      value={applicationDate(data)}
      hint="Recorded by the system when you submit."
    />
  )
}

/*
 * The option sets are exported so demoOfficeForm() can pick from them by index
 * rather than repeating the strings. A chip row silently accepts a value that
 * is not one of its options — it just renders nothing as selected — so a typo
 * in a duplicated literal would look like an empty required field.
 */
export const WATER_SOURCES = ['Level III (Waterworks)', 'Deep Well', 'Bottled / Refill', 'Other']

export const SANITARY_CLASSIFICATIONS = [
  'Food Establishment',
  'Non-Food Establishment',
  'Personal / Public Service',
  'Industrial',
]

export const OCCUPANCY_SCOPES = ['Full', 'Partial']

function SanitaryFields({
  data,
  set,
}: {
  data: OfficeFormData
  set: (key: string, value: string) => void
}) {
  return (
    <div className="space-y-7">
      <section className="space-y-4">
        <SectionMarker letter="A" label="Application Details" />
        <div className="grid gap-5 sm:grid-cols-2">
          {/* New vs Renewal is the application's own type — never re-asked. */}
          <DerivedField
            label={
              <>
                Type of Application
                <FromApplicationTag />
              </>
            }
            value={get(data, 'application_type')}
          />
          <ControlNoField
            label={
              <>
                Sanitary Permit No.
                <AutoTag />
              </>
            }
            placeholder="SP-2026-________"
          />
          <ApplicationDateField data={data} />
        </div>
      </section>

      <section className="space-y-4">
        <SectionMarker letter="B" label="Establishment Sanitation Profile" />
        <div>
          <FieldLabel required>Sanitary Classification</FieldLabel>
          <ChipRow
            options={SANITARY_CLASSIFICATIONS}
            value={get(data, 'sanitary_classification')}
            onChange={(v) => set('sanitary_classification', v)}
          />
        </div>
        <div className="grid gap-5 sm:grid-cols-2">
          <div>
            <FieldLabel>No. of Workers Requiring Health Certificates</FieldLabel>
            <input
              inputMode="numeric"
              value={get(data, 'workers_requiring_health_certs')}
              onChange={(e) => set('workers_requiring_health_certs', e.target.value)}
              placeholder="0"
              className={inputCls}
            />
          </div>
          <div>
            <FieldLabel>Water Source</FieldLabel>
            <select
              value={get(data, 'water_source')}
              onChange={(e) => set('water_source', e.target.value)}
              className={inputCls}
            >
              <option value="">Select…</option>
              {WATER_SOURCES.map((w) => (
                <option key={w} value={w}>
                  {w}
                </option>
              ))}
            </select>
          </div>
        </div>
      </section>
    </div>
  )
}

function CecFields({
  data,
  set,
}: {
  data: OfficeFormData
  set: (key: string, value: string) => void
}) {
  return (
    <div className="space-y-7">
      <section className="space-y-4">
        <SectionMarker letter="A" label="Application Details" />
        <div className="grid gap-5 sm:grid-cols-2">
          {/* Initial vs renewal follows the application type on record. */}
          <DerivedField
            label={
              <>
                Type of Application
                <FromApplicationTag />
              </>
            }
            value={get(data, 'application_type')}
          />
          <ControlNoField
            label={
              <>
                Control No.
                <AutoTag />
              </>
            }
            placeholder="CEC-2026-________"
          />
          <ApplicationDateField data={data} />
        </div>
        <div className="sm:w-1/2 sm:pr-2.5">
          <FieldLabel>Birthday of Owner</FieldLabel>
          {/* Birthdays can never be in the future: capped here and re-checked by the API. */}
          <input
            type="date"
            max={todayISO()}
            value={get(data, 'owner_birthday')}
            onChange={(e) => set('owner_birthday', e.target.value)}
            className={inputCls}
            aria-invalid={get(data, 'owner_birthday') !== '' && get(data, 'owner_birthday') >= todayISO()}
          />
          {get(data, 'owner_birthday') !== '' && get(data, 'owner_birthday') >= todayISO() && (
            <p className="mt-1 text-xs font-medium text-s-red">
              The birthday must be a date in the past.
            </p>
          )}
        </div>
      </section>
    </div>
  )
}

function FsicFields({
  data,
  set,
}: {
  data: OfficeFormData
  set: (key: string, value: string) => void
}) {
  return (
    <div className="space-y-7">
      <section className="space-y-4">
        <SectionMarker letter="A" label="Application Details" />
        <div className="grid gap-5 sm:grid-cols-2">
          <ControlNoField
            label={
              <>
                FSIC Application Number
                <AutoTag />
              </>
            }
            placeholder="FSIC-________"
          />
          <div>
            <FieldLabel>Authorized Representative</FieldLabel>
            <input
              value={get(data, 'authorized_representative')}
              onChange={(e) => set('authorized_representative', e.target.value)}
              placeholder="Auto-filled from Business Permit if blank"
              className={inputCls}
            />
          </div>
          <ApplicationDateField data={data} />
        </div>
      </section>

      <section className="space-y-4">
        <SectionMarker letter="B" label="Certificate Applied For" />
        {/*
         * The permits you picked and the application type already decide this,
         * so the BFP sheet carries it without asking the applicant to repeat it.
         */}
        <DerivedField
          label={
            <>
              Certificate Applied For
              <FromApplicationTag />
            </>
          }
          value={get(data, 'certificate_applied_for')}
          hint="Set from the permits and application type you chose in step 1. To change it, go back to Permit Selection."
        />
      </section>
    </div>
  )
}

function OccupancyFields({
  data,
  set,
}: {
  data: OfficeFormData
  set: (key: string, value: string) => void
}) {
  return (
    <div className="space-y-7">
      <section className="space-y-4">
        <SectionMarker letter="A" label="Application & Permit Details" />
        <div className="grid gap-5 sm:grid-cols-2">
          {/*
           * Full vs Partial is how much of the building will be occupied — a
           * real applicant decision, not the new/renewal the system knows.
           */}
          <div>
            <FieldLabel required>Application Type</FieldLabel>
            <ChipRow
              options={OCCUPANCY_SCOPES}
              value={get(data, 'application_type')}
              onChange={(v) => set('application_type', v)}
            />
          </div>
          <ApplicationDateField data={data} />
          <div>
            <FieldLabel>Building Permit No.</FieldLabel>
            <input
              value={get(data, 'building_permit_no')}
              onChange={(e) => set('building_permit_no', e.target.value)}
              className={inputCls}
            />
          </div>
          <div>
            <FieldLabel>FSEC No.</FieldLabel>
            <input
              value={get(data, 'fsec_no')}
              onChange={(e) => set('fsec_no', e.target.value)}
              className={inputCls}
            />
          </div>
        </div>
        {/*
         * The dates these documents were issued are recorded by the office that
         * issued them, during review (ReviewPage "For Office Use Only").
         */}
        <p className="text-xs text-ink-muted">
          The dates these documents were issued are filled in by the reviewing office. Just give the
          numbers here.
        </p>
      </section>
    </div>
  )
}

/* ── Office form sheet (composed step) ────────────────────────────────── */

export function OfficeFormSheet({
  code,
  data,
  onChange,
}: {
  code: OfficeFormCode
  data: OfficeFormData
  onChange: (data: OfficeFormData) => void
}) {
  const meta = OFFICE_FORM_META[code]
  const set = (key: string, value: string) => onChange({ ...data, [key]: value })

  return (
    <div className="rounded-sm bg-white px-6 py-7 shadow-card sm:px-9 sm:py-8">
      <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-royal">{meta.kicker}</p>
      <h1 className="mt-1.5 text-2xl font-bold text-ink">{meta.title}</h1>
      <p className="mt-1 text-xs text-ink-muted">Form Ref: {meta.ref}</p>
      <div className="mb-6 mt-3 h-px bg-royal" />
      {code === 'SANITARY' && <SanitaryFields data={data} set={set} />}
      {code === 'CEC' && <CecFields data={data} set={set} />}
      {code === 'FSIC' && <FsicFields data={data} set={set} />}
      {code === 'OCCUPANCY' && <OccupancyFields data={data} set={set} />}
    </div>
  )
}
