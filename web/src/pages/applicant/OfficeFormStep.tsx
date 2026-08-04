import type { ReactNode } from 'react'
import { FieldLabel, inputCls } from '../../components/ui/Proto'
import { formatDate } from '../../lib/format'

/*
 * Per-office application form sheets (UI prototype Parts 4-7, pages 040-043).
 * One step per selected clearance that has a form sheet: ZONING, SANITARY, CEC,
 * FSIC, OCCUPANCY. Fields are free-form and persisted verbatim as opaque JSON
 * via PUT office-forms/{code}. The auto-generated control numbers (SP-2026-…,
 * CEC-2026-…, FSIC-…) are read-only placeholders per the prototype — the real
 * number is minted server-side on issuance.
 *
 * The form never asks for anything the system already holds. Whether this is a
 * new or renewal application, the date it was filed, and which certificate the
 * FSIC is for all come from the application record: the API derives them on
 * every read and write, and they are shown here read-only so the sheet still
 * carries what the paper form needs. Issuance dates ("Date Issued") belong to
 * the office that issued the document and are filled in during officer review.
 *
 * ── One of these five is not like the others ──────────────────────────────
 *
 * Four sheets were transcribed from paper: the prototype carries the CHO, CENRO,
 * BFP and OBO forms with their own field labels and form reference numbers, so
 * what BizTrack asks is what the counter asks, word for word.
 *
 * ZONING has no such source. `docs/questions-for-malabon.md` E4 has asked CPDO
 * for their locational clearance form since the first round of testing and it
 * has never arrived; the prototype omits the sheet entirely, which is why
 * checklist item 101 exists. So the sheet below is built from the only
 * authoritative CPDO material in the repo — the Revenue Code's zoning article,
 * transcribed in `docs/revenue-code-extract.md` — plus the documents the ZONING
 * permit type already requires. Every field carries a marker saying which it is:
 *
 *   TRANSCRIBED — the wording comes from the ordinance itself.
 *   INFERRED    — the office demonstrably needs the answer, but nobody at CPDO
 *                 has confirmed that they ask for it in these words.
 *
 * Nothing here is presented to the applicant as an official CPDO field name.
 * When the real form arrives, the INFERRED fields are the ones to throw away.
 */

export type OfficeFormData = Record<string, unknown>

/**
 * What the applicant has already told us about the business, as every office
 * sheet needs it printed at the top.
 *
 * The paper versions of these forms each open by asking for the name, the
 * address and the trade — which the applicant has answered three sections
 * earlier. Carrying the answers instead of re-asking is the whole point of
 * filing online, but carrying them silently is worse than re-asking: the
 * applicant signs a statutory declaration without ever seeing what it says
 * about them. So they are shown, read-only, on every sheet.
 */
export interface CarriedOverBusiness {
  name: string
  tradeName: string
  address: string
  lineOfBusiness: string
}

/**
 * Which permit-type codes open a form sheet, in wizard order.
 *
 * Deliberately duplicated: `PermitType::OFFICE_FORM_CODES` in the API holds the
 * same list, because the clearance stage has to answer "does Apply open a
 * form?" from a database row and this file cannot be imported from PHP. The two
 * must be changed together or a card offers a sheet that does not exist.
 */
export const OFFICE_FORM_CODES = ['ZONING', 'SANITARY', 'CEC', 'FSIC', 'OCCUPANCY'] as const
export type OfficeFormCode = (typeof OFFICE_FORM_CODES)[number]

/** Kicker + h1 + form-ref for each office form sheet (verbatim from prototype). */
export const OFFICE_FORM_META: Record<
  OfficeFormCode,
  { kicker: string; title: string; ref: string }
> = {
  /*
   * No form reference number, because there is no form. The other four cite the
   * document they were copied from; inventing a code here would dress a sheet we
   * assembled ourselves as one CPDO issued. The legal basis is real and is what
   * the line says instead. The Revenue Code section behind the fee is
   * deliberately absent — checklist item 18 keeps those citations off applicant
   * screens (questions-for-malabon A12).
   */
  ZONING: {
    kicker: 'City Planning & Development Office · Office of the Zoning Administrator',
    title: 'Application for Locational Clearance',
    ref: 'Malabon Zoning Ordinance · business locational clearance',
  },
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
  if (code === 'ZONING') {
    // The land use category is the one answer CPDO cannot get from anywhere
    // else on the filing, and the verification it charges for is a check
    // against it — so it is the only required field on the sheet.
    if (!has('zoning_land_use_category')) missing.push('Proposed Land Use')
    if (
      has('zoning_operating_since') &&
      (data.zoning_operating_since as string) > todayISO()
    ) {
      missing.push('The date operations began cannot be in the future')
    }
  }
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

/**
 * A row of chip options bound to a single string value.
 *
 * `label` names the group for a screen reader. The chips announce themselves as
 * radio buttons, and a radio with no group around it is read as one loose
 * control — the listener hears "Commercial, radio button" with nothing saying
 * what is being chosen. Optional only because the four older sheets predate it.
 */
function ChipRow({
  options,
  value,
  onChange,
  label,
}: {
  options: string[]
  value: string
  onChange: (v: string) => void
  label?: string
}) {
  return (
    <div
      className="flex flex-wrap gap-2.5"
      role={label ? 'radiogroup' : undefined}
      aria-label={label}
    >
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

/**
 * The business, as this sheet will carry it to the office.
 *
 * Read-only rather than editable, and `readOnly` rather than `disabled`: a
 * disabled input leaves the tab order and most screen readers skip it, so the
 * one group that most needs to hear what the form says about them would be the
 * group that could not reach it. `readOnly` looks the same and stays
 * announceable.
 *
 * Every value here is a single answer shared by every sheet, which is why
 * locking it is safe. Anything an individual office asks in its own words — the
 * sanitary classification, the occupancy split, the authorised representative,
 * the proposed land use — stays a real question on that sheet, because two
 * forms asking a similar-sounding question are not always asking the same one.
 */
function CarriedOverSection({ business }: { business: CarriedOverBusiness }) {
  return (
    <section className="space-y-4">
      <SectionMarker letter="✓" label="Business Details" />
      <p className="text-xs text-ink-muted">
        Carried over from what you have already filled in. To change any of it, go back to Business
        Information or Location &amp; Zoning — editing it there updates every office form at once.
      </p>
      <div className="grid gap-5 sm:grid-cols-2">
        <DerivedField label={<>Business Name<FromApplicationTag /></>} value={business.name} />
        {business.tradeName !== '' && (
          <DerivedField
            label={<>Trade Name / Franchise<FromApplicationTag /></>}
            value={business.tradeName}
          />
        )}
        <DerivedField
          label={<>Business Address<FromApplicationTag /></>}
          value={business.address}
        />
        <DerivedField
          label={<>Line of Business<FromApplicationTag /></>}
          value={business.lineOfBusiness}
        />
      </div>
    </section>
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
 * The chip option sets live here rather than inline at each ChipRow, and are
 * exported so anything that needs to name one of these answers can reference
 * the list instead of retyping a string.
 *
 * ChipRow has no way to complain: given a value that is not one of its options
 * it simply renders nothing as selected. So a typo in a duplicated literal does
 * not fail loudly — it looks exactly like a required field the applicant never
 * answered, which is close to the worst way for a mistake of that kind to
 * present itself.
 */
export const WATER_SOURCES = ['Level III (Waterworks)', 'Deep Well', 'Bottled / Refill', 'Other']

export const SANITARY_CLASSIFICATIONS = [
  'Food Establishment',
  'Non-Food Establishment',
  'Personal / Public Service',
  'Industrial',
]

export const OCCUPANCY_SCOPES = ['Full', 'Partial']

/**
 * TRANSCRIBED — the land use categories CPDO verifies against, taken from the
 * Zoning and Land Use Verification Fee schedule in the Revenue Code's zoning
 * article (`docs/revenue-code-extract.md`, Article D).
 *
 * One liberty was taken, and it is worth naming: the verification schedule
 * prints "Commercial and industrial" as a single line because both are charged
 * the same. The processing schedule on the facing page lists them separately,
 * and they are not the same land use — so they are two options here. Commercial
 * leads because it is what nearly every business permit filing will be.
 *
 * The last entry is the ordinance's "Ancillary", whose fee is charged
 * "according to the category of principal building/structure" — which is why it
 * is phrased as a relationship to another building rather than a use of its own.
 */
export const ZONING_LAND_USE_CATEGORIES = [
  'Commercial',
  'Industrial',
  'Residential',
  'Social, Educational or Institutional',
  'Ancillary to a principal building',
]

/**
 * INFERRED — nobody at CPDO has confirmed this question, but the office plainly
 * has to answer it: the ordinance gives an already-operating non-conforming use
 * a *certificate of non-conformance* rather than a locational clearance, and it
 * surcharges an operation running without either. A sheet that never asks
 * whether the business is already trading at the address leaves the zoning
 * officer to work it out from the filing date, which does not say.
 */
export const ZONING_OPERATING_STATUS = [
  'Not yet operating at this address',
  'Already operating at this address',
]

/**
 * The CPDO locational clearance sheet — the one assembled rather than copied.
 *
 * Read the block at the top of this file first: there is no CPDO paper form in
 * the repo, so each section below says where its questions came from. Two of the
 * three sections ask the applicant nothing at all, which is the point — the
 * office needs the answers, and the filing already carries them.
 */
function ZoningFields({
  data,
  set,
}: {
  data: OfficeFormData
  set: (key: string, value: string) => void
}) {
  const alreadyOperating = get(data, 'zoning_operating_status') === ZONING_OPERATING_STATUS[1]
  const since = get(data, 'zoning_operating_since')
  const sinceInFuture = since !== '' && since > todayISO()

  return (
    <div className="space-y-7">
      <section className="space-y-4">
        <SectionMarker letter="A" label="Application Details" />
        <div className="grid gap-5 sm:grid-cols-2">
          {/* New vs renewal is the application's own type — never re-asked. */}
          <DerivedField
            label={
              <>
                Type of Application
                <FromApplicationTag />
              </>
            }
            value={get(data, 'application_type')}
          />
          {/*
           * MCZ is the prefix the register already mints zoning permits under,
           * so the placeholder shows the shape of the number the applicant will
           * eventually be given rather than a shape we made up for the box.
           */}
          <ControlNoField
            label={
              <>
                Locational Clearance No.
                <AutoTag />
              </>
            }
            placeholder="MCZ-2026-________"
          />
          <ApplicationDateField data={data} />
        </div>
      </section>

      <section className="space-y-4">
        <SectionMarker letter="B" label="Site & Proposed Use" />
        <div>
          {/*
           * TRANSCRIBED. This is the whole transaction: the office charges a
           * land use verification fee, and what it verifies is the use declared
           * here against the zone the address falls in. It is the only question
           * on the sheet the filing cannot answer for itself, and the only one
           * marked required.
           */}
          <FieldLabel required>Proposed Land Use</FieldLabel>
          <ChipRow
            label="Proposed land use"
            options={ZONING_LAND_USE_CATEGORIES}
            value={get(data, 'zoning_land_use_category')}
            onChange={(v) => set('zoning_land_use_category', v)}
          />
          <p className="mt-2 text-xs text-ink-muted">
            What the premises will be used for. CPDO checks this against the zone your address falls
            in and makes the final determination during processing.
          </p>
        </div>
        <div className="grid gap-5 sm:grid-cols-2">
          {/*
           * TRANSCRIBED as a requirement, derived as an answer: the zoning
           * processing fee is charged per square metre of total floor area, so
           * the office cannot assess the clearance without this number. The
           * applicant gave it two steps ago on the Business & Tax Profile.
           */}
          <DerivedField
            label={
              <>
                Total Floor Area (sq. m.)
                <FromApplicationTag />
              </>
            }
            value={get(data, 'total_floor_area_sqm')}
            hint="From the floor area you entered on Business & Tax Profile."
          />
          {/*
           * TRANSCRIBED as a requirement — the ZONING clearance already demands
           * a Lease Contract or Land Title, so the office is asking what right
           * the applicant holds over the site. Whether the premises are rented,
           * and from whom, is answered on Location & Zoning.
           */}
          <DerivedField
            label={
              <>
                Right Over the Site
                <FromApplicationTag />
              </>
            }
            value={get(data, 'site_tenure')}
            hint="Attach the matching land title or lease contract with your documents."
          />
        </div>
      </section>

      <section className="space-y-4">
        <SectionMarker letter="C" label="Status of the Activity" />
        {/*
         * INFERRED — see ZONING_OPERATING_STATUS. An establishment already
         * trading at the address is a different transaction from a proposed
         * one, and only the applicant knows which this is.
         */}
        <div>
          <FieldLabel>Is the business already operating at this address?</FieldLabel>
          <ChipRow
            label="Is the business already operating at this address?"
            options={ZONING_OPERATING_STATUS}
            value={get(data, 'zoning_operating_status')}
            onChange={(v) => set('zoning_operating_status', v)}
          />
          <p className="mt-2 text-xs text-ink-muted">
            Answer honestly. An establishment already trading is assessed differently from one that
            has not opened, and saying so here is not an admission of anything.
          </p>
        </div>
        {/*
         * The date only exists for one of the two answers, so it stays closed
         * until that answer is given rather than sitting there inapplicable.
         */}
        {alreadyOperating && (
          <div className="sm:w-1/2 sm:pr-2.5">
            <label className="block">
              <FieldLabel>Operating at this address since</FieldLabel>
              {/* A business cannot have started trading tomorrow. */}
              <input
                type="date"
                max={todayISO()}
                value={since}
                onChange={(e) => set('zoning_operating_since', e.target.value)}
                className={inputCls}
                aria-invalid={sinceInFuture}
                aria-describedby={sinceInFuture ? 'zoning-operating-since-error' : undefined}
              />
            </label>
            {sinceInFuture && (
              <p
                id="zoning-operating-since-error"
                role="alert"
                className="mt-1 text-xs font-medium text-s-red"
              >
                The date operations began must be today or earlier.
              </p>
            )}
          </div>
        )}
      </section>
    </div>
  )
}

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
            {/*
             * Item 70 — the placeholder used to carry the rule ("Auto-filled
             * from Business Permit if blank"), which is the one thing about this
             * field that must survive the first keystroke and is exactly what a
             * placeholder does not. It is a hint below the box now, and the
             * placeholder is an example, as every other one on these sheets is.
             */}
            <input
              value={get(data, 'authorized_representative')}
              onChange={(e) => set('authorized_representative', e.target.value)}
              placeholder="e.g. Juan Dela Cruz"
              className={inputCls}
            />
            <p className="mt-1 text-xs text-ink-muted">
              Leave blank to use the name on the Business Permit application.
            </p>
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
  business,
  onChange,
}: {
  code: OfficeFormCode
  data: OfficeFormData
  business: CarriedOverBusiness
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
      <div className="space-y-7">
        {/* First, so the sheet opens by showing what it already knows rather
          * than by asking. */}
        <CarriedOverSection business={business} />
        {code === 'ZONING' && <ZoningFields data={data} set={set} />}
        {code === 'SANITARY' && <SanitaryFields data={data} set={set} />}
        {code === 'CEC' && <CecFields data={data} set={set} />}
        {code === 'FSIC' && <FsicFields data={data} set={set} />}
        {code === 'OCCUPANCY' && <OccupancyFields data={data} set={set} />}
      </div>
    </div>
  )
}
