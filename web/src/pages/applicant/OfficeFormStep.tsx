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
 * ── Five sheets are transcribed from paper. One is not, and admits it ──────
 *
 * The CHO, CENRO, BFP and OBO forms were always copied from the document the
 * counter hands out, field label for field label, so what BizTrack asks is what
 * the counter asks, word for word.
 *
 * MARKET is the exception, and unlike ZONING below it is not an exception that
 * will resolve itself. Checklist item 109: *"Application form for Market
 * Clearance is missing. Create something for this since we currently don't have
 * the paper version."* There is no document to transcribe and the client has
 * said so directly, so this sheet is written rather than copied — which makes
 * restraint the only defence available. It asks three questions, every one of
 * them either the basis of a fee the engine already knows how to charge or the
 * identity of the stall being cleared, and it says on its own face that it is
 * interim. Each invented field carries a comment saying why it is there, so the
 * list can be held up to the City Market Administrator and checked line by
 * line. See docs/questions-for-malabon.md.
 *
 * ZONING was the exception. CPDD had never sent its locational clearance form
 * (`docs/questions-for-malabon.md` E4/C9 had been chasing it since the first
 * round of testing), so the sheet was assembled from the Revenue Code's zoning
 * article and every field carried a marker — TRANSCRIBED where the wording came
 * from the ordinance, INFERRED where the office plainly needed an answer but
 * nobody had confirmed the question. The instruction was to throw the INFERRED
 * ones away when the form turned up.
 *
 * It turned up: MCG-CPDD-FO-003 v1.2, effective 01-09-2026. It settled the
 * guesses mostly by contradicting them. CPDD does not ask for a proposed land
 * use category — those five options were the fee schedule's vocabulary, not the
 * form's — and it does not ask whether the business is already trading at the
 * address. Both are deleted. What it asks in their place is a free-text project
 * description and, for industrial projects, whether they are pollutive or
 * hazardous.
 *
 * Most of its numbered fields are answers the filing already holds, so the
 * sheet carries them read-only rather than asking twice. Two things on the
 * paper are deliberately *not* built here, and are recorded in
 * questions-for-malabon C9 instead: the hand-drawn Sketch of the Location,
 * which needs an upload or a canvas rather than a text box (the map pin is a
 * coordinate, not a sketch), and the declaration's "MUST BE NOTARIZED PRIOR TO
 * SUBMISSION", which the online flow has no step for at all. One field it asks
 * has nowhere to come from: II. Home Address, which the system does not record
 * anywhere — an account-level gap, not a gap on this sheet.
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
export const OFFICE_FORM_CODES = [
  'ZONING',
  'SANITARY',
  'CEC',
  'FSIC',
  'OCCUPANCY',
  'MARKET',
] as const
export type OfficeFormCode = (typeof OFFICE_FORM_CODES)[number]

/** Kicker + h1 + form-ref for each office form sheet (verbatim from prototype). */
export const OFFICE_FORM_META: Record<
  OfficeFormCode,
  { kicker: string; title: string; ref: string }
> = {
  /*
   * This line used to cite the Zoning Ordinance rather than a form code, because
   * there was no form to cite and inventing a code would have dressed a sheet we
   * assembled ourselves as one CPDD issued. There is a form now, and its own
   * control block names it. The department reads as CPDD because that is what
   * the letterhead says; the register still seeds the office as CPDO.
   *
   * The Revenue Code section behind the fee stays deliberately absent —
   * checklist item 18 keeps those citations off applicant screens
   * (questions-for-malabon A12).
   */
  ZONING: {
    kicker: 'City Planning and Development Department',
    title: 'Application for Locational Clearance (Business Activities)',
    ref: 'MCG-CPDD-FO-003 · v1.2',
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
  /*
   * The one sheet with no paper behind it, and the ref line says so out loud.
   *
   * Every other entry here cites a form code because a real document exists to
   * cite — MCG-CENRO-FO-001, BFP-QSF-FSED-002, and MCG-CPDD-FO-003 once CPDD
   * finally sent it. Inventing "MCG-CMO-FO-001" would dress three questions we
   * wrote ourselves as a form the City Market Administrator issued, and the
   * next person to read this screen would have no way to tell the difference.
   *
   * The line is written for the applicant, not for us: it tells a stall holder
   * why this sheet is shorter than the others, and it is the sentence that has
   * to be deleted the day the office hands over its real form.
   */
  MARKET: {
    kicker: 'Office of the City Market Administrator',
    title: 'Application for Market Clearance (Stall Holders)',
    ref: 'Interim form — the office has no printed version of this application',
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
  //
  // ZONING requires nothing. It used to require Proposed Land Use, which the
  // real CPDD form (MCG-CPDD-FO-003) turns out not to ask; of the two questions
  // it does ask, the paper marks neither mandatory, and inventing a requirement
  // the counter does not enforce is the same mistake in the other direction.
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
  /*
   * MARKET requires the two answers that identify the thing being cleared.
   *
   * Every requirement on this sheet is one we invented, so the bar is higher
   * than on the transcribed sheets, not lower: a rule the counter does not
   * enforce is a door this system closes that the office leaves open. The name
   * of the market and the stall number survive that test because a market
   * clearance that names neither is not a clearance of anything — the office
   * would have nothing to write the certificate against.
   *
   * The stall count is deliberately NOT required, even though it is the one
   * field the fee engine reads (see the comment on the input). A blank there
   * costs the applicant nothing and simply leaves the stall-count rules
   * unmatched, whereas requiring it would make an invented question a hard
   * block on submitting the whole filing.
   */
  if (code === 'MARKET') {
    if (!has('market_name')) missing.push('Name of Market')
    if (!has('stall_no')) missing.push('Stall No.')
    // Optional, but a typed answer still has to be a countable number of
    // stalls: the fee rules multiply it by a peso rate.
    if (has('stall_count') && !/^[1-9]\d*$/.test((data.stall_count as string).trim())) {
      missing.push('Number of stalls must be a whole number of 1 or more')
    }
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
  // Wrapped in a real <label>: FieldLabel is a styled <span>, so on its own it
  // names nothing and a screen reader reaches the box with no idea what it is.
  return (
    <label className="block">
      <FieldLabel>{label}</FieldLabel>
      <input
        value=""
        readOnly
        aria-readonly="true"
        placeholder={placeholder}
        className={`${inputCls} tnum cursor-not-allowed bg-line/60 text-ink-secondary`}
      />
    </label>
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
  // The <label> stops at the input: the hint sits outside it so that a long
  // explanatory sentence is not read out as part of the field's name.
  return (
    <div>
      <label className="block">
        <FieldLabel>{label}</FieldLabel>
        <input
          value={value}
          readOnly
          aria-readonly="true"
          placeholder="Filled in from your application"
          className={`${inputCls} cursor-not-allowed bg-line/60 text-ink-secondary`}
        />
      </label>
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
 * sanitary classification, the occupancy split, the zoning project description
 * — stays a real question on that sheet, because two forms asking a
 * similar-sounding question are not always asking the same one.
 *
 * This block is also where four of the CPDD locational clearance's numbered
 * fields land: I. Name of Proprietor, III. Name of Firm, IV/VI. Address, and
 * V. Activity.
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
 * MCG-CPDD-FO-003 VIII.E, "Type of Industrial Project".
 *
 * The paper prints these as five tick boxes in three columns — POLLUTIVE over
 * NON-POLLUTIVE, HAZARDOUS over NON-HAZARDOUS, then OTHER — which reads as two
 * independent yes/no judgements rather than one choice of five. A project can
 * plainly be both pollutive and hazardous. We ask it as a single choice because
 * that is what one row of boxes on a form usually means, and because guessing
 * wrong on a multi-select loses an answer the applicant thought they gave.
 * questions-for-malabon C9 asks CPDD which reading is right.
 */
export const ZONING_INDUSTRIAL_PROJECT_TYPES = [
  'Pollutive',
  'Non-Pollutive',
  'Hazardous',
  'Non-Hazardous',
  'Other',
]

/**
 * The CPDD locational clearance sheet, MCG-CPDD-FO-003 v1.2.
 *
 * Read the block at the top of this file for how this sheet came to be rebuilt.
 * The paper numbers its fields I to X, and most of them are answers the filing
 * already holds — so section A asks nothing at all, and B and C between them
 * ask three questions. Where a field is carried rather than asked, the comment
 * names the roman numeral it is standing in for, so a future reader can hold
 * this beside the form and see that nothing was dropped.
 *
 * I (proprietor), III (firm), IV/VI (addresses) and V (activity) are all in the
 * Business Details block that opens every sheet, above this component.
 */
function ZoningFields({
  data,
  set,
}: {
  data: OfficeFormData
  set: (key: string, value: string) => void
}) {
  /*
   * IX. Authorized Representative is one answer about the applicant, not about
   * an office, and the BFP sheet already asks it. When that sheet is part of
   * the filing it owns the question and this one carries the answer; when it is
   * not, nobody has asked, so the input lands here instead. Either way the
   * applicant types it once. The API sets this marker — see
   * OfficeFormController::withDerived().
   */
  const repAskedOnFsic = get(data, 'authorized_representative_source') === 'FSIC'

  return (
    <div className="space-y-7">
      <section className="space-y-4">
        <SectionMarker letter="A" label="Application Details" />
        <div className="grid gap-5 sm:grid-cols-2">
          {/* VII. Nature of Application — New Business vs Renewal is the
            * application's own type, so it is never re-asked. */}
          <DerivedField
            label={
              <>
                Nature of Application
                <FromApplicationTag />
              </>
            }
            value={get(data, 'application_type')}
          />
          {/*
           * The paper's "Application No." box, top right, which the counter
           * fills in on receipt. MCZ is the prefix the register already mints
           * zoning permits under, so the placeholder shows the shape of the
           * number the applicant will eventually be given rather than a shape
           * we made up for the box.
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
        <SectionMarker letter="B" label="Project Description" />
        <div>
          {/*
           * VIII. Project Description. A question the filing cannot answer for
           * itself: section V already carries the line of business, so what
           * this adds is the applicant's own account of what is actually being
           * put on the site.
           */}
          <label className="block">
            <FieldLabel>Project Description</FieldLabel>
            <textarea
              rows={3}
              value={get(data, 'zoning_project_description')}
              onChange={(e) => set('zoning_project_description', e.target.value)}
              placeholder="e.g. Two-storey coffee shop with a small roasting area at the rear"
              className={inputCls}
            />
          </label>
          <p className="mt-1 text-xs text-ink-muted">
            In your own words, what will be built or operated at this address.
          </p>
        </div>
        <div className="grid gap-5 sm:grid-cols-2">
          {/*
           * VIII.A. The zoning processing fee is charged per square metre of
           * total floor area, so the office cannot assess the clearance without
           * this number — and the applicant already gave it on the Business &
           * Tax Profile.
           */}
          <DerivedField
            label={
              <>
                Floor Area to be / being Utilized (sq. m.)
                <FromApplicationTag />
              </>
            }
            value={get(data, 'total_floor_area_sqm')}
            hint="From the floor area you entered on Business & Tax Profile."
          />
          {/* VIII.B. Also answered on Business & Tax Profile. */}
          <DerivedField
            label={
              <>
                No. of Storey of Building
                <FromApplicationTag />
              </>
            }
            value={get(data, 'building_storeys')}
            hint="From the number of storeys you entered on Business & Tax Profile."
          />
          {/*
           * VIII.C and VIII.D — the lessor's name and address, which the paper
           * asks only "if lessee". Whether the premises are rented and from
           * whom is answered on Location & Zoning, and the ZONING clearance
           * already demands the matching Lease Contract or Land Title, so this
           * one line stands in for both boxes.
           *
           * Labelled for what it says rather than for the box it fills: an
           * owner-occupier reading "Name of Lessor" above "Owned or occupied by
           * the applicant" would reasonably think the sheet had gone wrong.
           */}
          <DerivedField
            label={
              <>
                Right Over the Site
                <FromApplicationTag />
              </>
            }
            value={get(data, 'site_tenure')}
            hint="Names your lessor when the premises are rented. Attach the matching land title or lease contract with your documents."
          />
        </div>
        <div>
          {/*
           * VIII.E. Not the same question as the goods class on the Business &
           * Tax Profile (flammables, chemicals, dry goods, perishables): that
           * one prices the fire fee against what is stored, this one tells the
           * planner what the operation emits. Left optional because most
           * filings are not industrial and the paper offers no "not applicable".
           */}
          <FieldLabel>Type of Industrial Project</FieldLabel>
          <ChipRow
            label="Type of industrial project"
            options={ZONING_INDUSTRIAL_PROJECT_TYPES}
            value={get(data, 'zoning_industrial_project_type')}
            onChange={(v) => set('zoning_industrial_project_type', v)}
          />
          <p className="mt-2 text-xs text-ink-muted">
            Only if the project is industrial. Leave blank otherwise.
          </p>
        </div>
      </section>

      <section className="space-y-4">
        <SectionMarker letter="C" label="Authorized Representative" />
        {repAskedOnFsic ? (
          <div className="sm:w-1/2 sm:pr-2.5">
            <DerivedField
              label={
                <>
                  Authorized Representative
                  <FromApplicationTag />
                </>
              }
              value={get(data, 'authorized_representative')}
              hint="Asked once, on the Fire Safety (FSIC) sheet later in this application. Whatever you put there appears here."
            />
          </div>
        ) : (
          <div className="sm:w-1/2 sm:pr-2.5">
            <label className="block">
              <FieldLabel>Authorized Representative</FieldLabel>
              <input
                value={get(data, 'authorized_representative')}
                onChange={(e) => set('authorized_representative', e.target.value)}
                placeholder="Full name"
                className={inputCls}
              />
            </label>
            <p className="mt-1 text-xs text-ink-muted">
              Leave blank to use the name on the Business Permit application. If you name someone,
              CPDD asks for an authorization letter with your documents.
            </p>
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
          <label className="block">
            <FieldLabel>Authorized Representative</FieldLabel>
            {/*
             * Item 70 — the placeholder used to carry the rule ("Auto-filled
             * from Business Permit if blank"), which is the one thing about this
             * field that must survive the first keystroke and is exactly what a
             * placeholder does not. It is a hint below the box now, and the
             * placeholder is an example, as every other one on these sheets is.
             *
             * This is also where the CPDD locational clearance's IX. Authorized
             * Representative is answered whenever both sheets are on the filing:
             * one question, asked on the form that asked it first, carried onto
             * the zoning sheet read-only.
             */}
            <input
              value={get(data, 'authorized_representative')}
              onChange={(e) => set('authorized_representative', e.target.value)}
              placeholder="Full name"
              className={inputCls}
            />
            <p className="mt-1 text-xs text-ink-muted">
              Leave blank to use the name on the Business Permit application.
            </p>
          </label>
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

/**
 * The Market Clearance sheet — checklist item 109, and the only one of the six
 * with nothing behind it but this comment.
 *
 * *"Create something for this since we currently don't have the paper
 * version."* So every question below was chosen here, not copied, and the test
 * each one had to pass was: **would the office be unable to do its job without
 * it?** Three passed. Everything else a market clearance might plausibly ask —
 * what is sold at the stall, the size of it, how long it has been held, the
 * name on the previous clearance — was left out, because a long invented form
 * is a long list of things to un-invent when the office finally sends its own.
 *
 * The two that are asked for identity, and are required:
 *
 *   market_name  Which market. `CMO-MARKET` is the Office of the City Market
 *                Administrator, singular, and it administers more than one
 *                market — so "a stall" with no market named is not an address
 *                the office can act on. Nothing else in the filing carries it:
 *                the business address is the stall's street address, which is
 *                the market's address for every stall in it and therefore
 *                cannot distinguish the market from the stall.
 *   stall_no     Which stall. The clearance is issued against one stall, and
 *                this is the only field in the entire application that says
 *                which. INVENTED, but not really a judgement call: without it
 *                the certificate has no subject.
 *
 * The one that is asked for money, and is not required:
 *
 *   stall_count  How many stalls are held. This is the ONLY field on this sheet
 *                that is not a guess about what the office wants, because the
 *                revenue code already prices market lines per stall and the
 *                engine already reads the number: `FeeRule.basis = 'stall_count'`
 *                resolves to `fee_profile.stall_count` (FeeCalculator::basisValue),
 *                and four seeded rules use it — garbage Schedule J at ₱44/₱55 a
 *                stall, the privately-owned market bracket, and the fish broker
 *                market bracket.
 *
 *                It is asked HERE and not on the Business & Tax Profile because
 *                the profile's stall question is gated on `has('MARKET')` over a
 *                permit-code list every call site fills with BUSINESS alone —
 *                the clearances are chosen a step LATER than the profile now, so
 *                that question can never fire. This sheet is the first screen in
 *                the flow that knows the applicant holds a stall at all.
 *
 *                What it does NOT yet do is reach `fee_profile`. Office sheets
 *                are opaque JSON on `application_office_forms`, and the fee
 *                engine reads `applications.fee_profile`; nothing carries a
 *                value from one to the other. So the number is collected and
 *                shown to the office, and the per-stall rules stay unmatched
 *                until someone wires it across. That is a fee-assessment change
 *                with its own consequences and it is not made here — it is
 *                written up rather than half-done.
 */
function MarketFields({
  data,
  set,
}: {
  data: OfficeFormData
  set: (key: string, value: string) => void
}) {
  const stallCount = get(data, 'stall_count')
  const stallCountBad = stallCount !== '' && !/^[1-9]\d*$/.test(stallCount.trim())

  return (
    <div className="space-y-7">
      <section className="space-y-4">
        <SectionMarker letter="A" label="Application Details" />
        <div className="grid gap-5 sm:grid-cols-2">
          {/* New vs Renewal is the application's own type, as on every other
            * sheet — the one thing this form does not have to invent. */}
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
           * MCM is the prefix the register already mints market clearances
           * under (`permit_types.permit_number_prefix`), so the placeholder
           * shows the shape of the number the applicant will be given rather
           * than one invented to fill the box.
           */}
          <ControlNoField
            label={
              <>
                Market Clearance No.
                <AutoTag />
              </>
            }
            placeholder="MCM-2026-________"
          />
          <ApplicationDateField data={data} />
        </div>
      </section>

      <section className="space-y-4">
        <SectionMarker letter="B" label="Stall Details" required />
        <p className="text-xs text-ink-muted">
          The business name, address and trade above are carried from your application. What this
          office needs on top of them is which stall it is clearing.
        </p>
        <div className="grid gap-5 sm:grid-cols-2">
          <label className="block">
            <FieldLabel required>Name of Market</FieldLabel>
            <input
              value={get(data, 'market_name')}
              onChange={(e) => set('market_name', e.target.value)}
              className={inputCls}
            />
          </label>
          <label className="block">
            <FieldLabel required>Stall No.</FieldLabel>
            <input
              value={get(data, 'stall_no')}
              onChange={(e) => set('stall_no', e.target.value)}
              placeholder="e.g. B-14"
              className={inputCls}
            />
          </label>
          <div>
            <label className="block">
              <FieldLabel>Number of Stalls Held</FieldLabel>
              <input
                inputMode="numeric"
                value={stallCount}
                onChange={(e) => set('stall_count', e.target.value)}
                placeholder="1"
                className={inputCls}
                aria-invalid={stallCountBad}
              />
            </label>
            {stallCountBad ? (
              /* role="alert" — a typed answer the sheet is refusing has to
                 reach a screen reader without being gone looking for. */
              <p role="alert" className="mt-1 text-xs font-medium text-s-red">
                Enter a whole number of stalls, 1 or more.
              </p>
            ) : (
              <p className="mt-1 text-xs text-ink-muted">
                Leave blank if you hold one stall only. Several market fees are charged per stall.
              </p>
            )}
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
        {code === 'MARKET' && <MarketFields data={data} set={set} />}
      </div>
    </div>
  )
}
