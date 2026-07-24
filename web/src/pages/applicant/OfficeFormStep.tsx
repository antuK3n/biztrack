import type { ReactNode } from 'react'
import { FieldLabel, inputCls } from '../../components/ui/Proto'

/*
 * Per-office application form sheets (UI prototype Parts 4-7, pages 040-043).
 * One step per selected inspection-office permit type that has a prototype form:
 * SANITARY, CEC, FSIC, OCCUPANCY. Fields are free-form and persisted verbatim
 * as opaque JSON via PUT office-forms/{code}. The auto-generated control numbers
 * (SP-2026-…, CEC-2026-…, FSIC-…) are read-only placeholders per the prototype —
 * the real number is minted server-side on issuance.
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
    ref: 'Pursuant to PD 856 — Code on Sanitation of the Philippines',
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

/* ── Shared field primitives (prototype chip pills, read-only control no.) ── */

/** Royal square with white section letter, matching the wizard's SectionMarker. */
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

/** Radio/checkbox chip pill (New/Renewal, classification, Full/Partial). */
function ChipOption({
  label,
  selected,
  onClick,
  wide,
}: {
  label: string
  selected: boolean
  onClick: () => void
  wide?: boolean
}) {
  return (
    <button
      type="button"
      role="radio"
      aria-checked={selected}
      onClick={onClick}
      className={`flex items-center gap-2.5 rounded-md border px-4 py-2.5 text-sm font-medium transition-colors ${
        wide ? 'w-full text-left' : ''
      } ${
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

/** Full-width stacked radio list (FSIC "Certificate Applied For"). */
function ChipList({
  options,
  value,
  onChange,
}: {
  options: string[]
  value: string
  onChange: (v: string) => void
}) {
  return (
    <div className="space-y-2.5">
      {options.map((o) => (
        <ChipOption key={o} label={o} selected={value === o} onClick={() => onChange(o)} wide />
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

/* ── Per-office field bodies ──────────────────────────────────────────── */

function get(data: OfficeFormData, key: string): string {
  const v = data[key]
  return typeof v === 'string' ? v : ''
}

const WATER_SOURCES = ['Level III (Waterworks)', 'Deep Well', 'Bottled / Refill', 'Other']

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
          <div>
            <FieldLabel required>Type of Application</FieldLabel>
            <ChipRow
              options={['New', 'Renewal']}
              value={get(data, 'application_type')}
              onChange={(v) => set('application_type', v)}
            />
          </div>
          <ControlNoField
            label={
              <>
                Sanitary Permit No.
                <AutoTag />
              </>
            }
            placeholder="SP-2026-________"
          />
        </div>
      </section>

      <section className="space-y-4">
        <SectionMarker letter="B" label="Establishment Sanitation Profile" />
        <div>
          <FieldLabel required>Sanitary Classification</FieldLabel>
          <ChipRow
            options={[
              'Food Establishment',
              'Non-Food Establishment',
              'Personal / Public Service',
              'Industrial',
            ]}
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
          <div>
            <FieldLabel required>Type of Application</FieldLabel>
            <ChipRow
              options={['Initial Application', 'Renewal of CEC']}
              value={get(data, 'application_type')}
              onChange={(v) => set('application_type', v)}
            />
          </div>
          <ControlNoField
            label={
              <>
                Control No.
                <AutoTag />
              </>
            }
            placeholder="CEC-2026-________"
          />
        </div>
        <div className="sm:w-1/2 sm:pr-2.5">
          <FieldLabel>Birthday of Owner</FieldLabel>
          <input
            type="date"
            value={get(data, 'owner_birthday')}
            onChange={(e) => set('owner_birthday', e.target.value)}
            className={inputCls}
          />
        </div>
      </section>
    </div>
  )
}

const FSIC_CERTIFICATES = [
  'FSIC for Certificate of Occupancy',
  'FSIC for Business Permit — For New Business',
  'FSIC for Business Permit — For Renewal of Business',
]

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
        </div>
      </section>

      <section className="space-y-4">
        <SectionMarker letter="B" label="Certificate Applied For" />
        <p className="-mt-2 text-xs text-ink-muted">Select one.</p>
        <ChipList
          options={FSIC_CERTIFICATES}
          value={get(data, 'certificate_applied_for')}
          onChange={(v) => set('certificate_applied_for', v)}
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
          <div>
            <FieldLabel required>Application Type</FieldLabel>
            <ChipRow
              options={['Full', 'Partial']}
              value={get(data, 'application_type')}
              onChange={(v) => set('application_type', v)}
            />
          </div>
          <div>
            <FieldLabel>Application Date</FieldLabel>
            <input
              type="date"
              value={get(data, 'application_date')}
              onChange={(e) => set('application_date', e.target.value)}
              className={inputCls}
            />
          </div>
          <div>
            <FieldLabel>Building Permit No.</FieldLabel>
            <input
              value={get(data, 'building_permit_no')}
              onChange={(e) => set('building_permit_no', e.target.value)}
              className={inputCls}
            />
          </div>
          <div>
            <FieldLabel>Building Permit — Date Issued</FieldLabel>
            <input
              type="date"
              value={get(data, 'building_permit_date')}
              onChange={(e) => set('building_permit_date', e.target.value)}
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
          <div>
            <FieldLabel>FSEC — Date Issued</FieldLabel>
            <input
              type="date"
              value={get(data, 'fsec_date')}
              onChange={(e) => set('fsec_date', e.target.value)}
              className={inputCls}
            />
          </div>
        </div>
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
