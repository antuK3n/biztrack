import { createContext, useContext, type ReactNode } from 'react'
import { DocumentActions } from '../../components/DocumentActions'
import { CheckCircleFilledIcon, DownloadIcon, UploadIcon } from '../../components/icons'
import { FieldLabel, inputCls } from '../../components/ui/Proto'
import { formatBytes, formatDate } from '../../lib/format'
import type { OfficeFormRequirement } from '../../lib/types'
import { ACCEPT_ATTR } from './uploads'

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
 * ── Every sheet here is transcribed from paper ─────────────────────────────
 *
 * The CHO, CENRO, BFP and OBO forms are copied from the document the counter
 * hands out, field label for field label, so what BizTrack asks is what the
 * counter asks, word for word.
 *
 * That is now true of all five, which it was not before. MARKET was the
 * standing exception — checklist item 109, *"Application form for Market
 * Clearance is missing. Create something for this since we currently don't have
 * the paper version"* — so its three questions were written rather than copied
 * and the sheet said on its own face that it was interim. It is gone: Market
 * Clearance and the City Market Administrator were removed from the system on
 * 6 September 2026, the client having confirmed with the LGU that neither is
 * needed. Nothing on this screen is invented any more, and the next sheet that
 * would be should be argued for as hard as that one was.
 *
 * ZONING was the other exception, and it resolved itself. CPDD had never sent
 * its locational clearance form
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

/**
 * Is this sheet a RECORD of something already sent, rather than a form?
 *
 * The client, 9 September 2026: "We do not promote any editing of forms once
 * submitted... once submitted, please create the button which will allow them
 * to see what they have submitted."
 *
 * A context rather than a prop threaded through five field components and
 * eleven controls. The alternative was passing `readOnly` down every level of
 * every sheet, where the one that gets forgotten is a live input on a form the
 * office has already received — a silent hole, and exactly the kind this file
 * has had before.
 *
 * `readOnly` on the controls, never `disabled`, and the difference is the whole
 * accessibility of the thing. A disabled input leaves the tab order and screen
 * readers pass over it, so an applicant using one could not read back what they
 * had submitted — which is the entire purpose of the view. `readOnly` looks the
 * same, stays focusable and is announced. The two controls that cannot be
 * `readOnly` — the chip pickers and the one <select> — take `disabled` with an
 * `aria-disabled` beside it, because HTML gives them no other way.
 */
const ReadOnlyContext = createContext(false)

function useReadOnly(): boolean {
  return useContext(ReadOnlyContext)
}

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
  /*
   * ── The CENRO block ───────────────────────────────────────────────────────
   *
   * MCG-CENRO-FO-001 v2.0's "Ownership and Documentation" asks for eleven
   * things the BPLO form has already collected, and the CEC sheet was showing
   * none of them: an applicant filling it in saw four boxes where the paper has
   * eighteen, and the office received a sheet that did not look like its own
   * form.
   *
   * They are carried, never re-asked, for the reason `CarriedOverSection`
   * states above — two forms asking a similar-sounding question get two
   * answers. Rendered only on the CEC sheet rather than in the shared block,
   * because the other four offices' papers do not ask for them and a shared
   * block that grows to the union of every form is how a sheet stops looking
   * like the paper it is named after.
   *
   * Required rather than optional so the one caller has to supply them. An
   * optional field here would render blank on a payload that simply forgot to
   * pass it, which is indistinguishable from a business that genuinely has no
   * landline.
   */
  /** Sole Proprietorship / Partnership / Corporation / Cooperative. */
  registrationType: string
  /** Family Name, First Name, Middle Name — the person the filing is in. */
  ownerName: string
  ownerSex: string
  productsServices: string
  /** BPLO item A6 — the paper's LANDLINE. */
  landline: string
  /** BPLO item A7 — the paper's MOBILE NO. */
  mobile: string
  /** The paper's BUSINESS AREA (IN SQ. M.). */
  businessAreaSqm: string
  maleEmployees: string
  femaleEmployees: string
  /*
   * ── The CPDD block ────────────────────────────────────────────────────────
   *
   * MCG-CPDD-FO-003 v1.2's numbered items, carried for the same reason as
   * CENRO's: the applicant answered them on the BPLO form and a second asking
   * invites a second answer.
   *
   * `proprietorName` is item I — "Name of Proprietor/President/General
   * Manager". Three job titles for one box, and the register holds two of them:
   * `president_officer_name` for a corporation, partnership or cooperative, and
   * the owner for a sole proprietorship, who IS the proprietor. Whichever the
   * filing has.
   *
   * Items VIII.C and VIII.D, the lessor's name and address, are NOT here. They
   * were one derived sentence — "Leased from Acme Realty" — which read well and
   * answered neither box, and the paper wants them apart. They are now derived
   * server-side into `form_data` instead, so that the OFFICER sees them too:
   * the review sheet renders `form_data` and nothing else, and a lessor CPDD
   * cannot read is a lease contract CPDD cannot check the sheet against.
   */
  proprietorName: string
  /** Item I's CONTACT NO. and EMAIL ADD. — BPLO items A7 and A8. */
  proprietorContact: string
  proprietorEmail: string
  /** Item V, "Activity (please specify)" — the trade in the applicant's words. */
  activity: string
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
   * MARKET was here — the one sheet with no paper behind it. Every other entry
   * cites a real form code (MCG-CENRO-FO-001, BFP-QSF-FSED-002,
   * MCG-CPDD-FO-003); that one carried a plain-English ref line saying the
   * office had never printed the application, because inventing
   * "MCG-CMO-FO-001" would have dressed three questions we wrote ourselves as a
   * document the City Market Administrator issued.
   *
   * Market Clearance and that office were removed from the system on
   * 6 September 2026 — the client confirmed with the LGU that neither is
   * needed. A business that genuinely needs one is asked by hand through Other
   * Requirements.
   */
}

/** True if a permit-type code renders a prototype office form. */
export function hasOfficeForm(code: string): code is OfficeFormCode {
  return (OFFICE_FORM_CODES as readonly string[]).includes(code)
}

/**
 * The sheets with a required answer, and therefore the ones that say how to get
 * off them — CLR-2.
 *
 * ── What this used to be, and what it is now ──────────────────────────────
 *
 * Applying for a clearance once inserted its sheet as a mandatory STEP of the
 * wizard, directly behind LGU Clearances. Where the sheet had a required
 * answer, Next was disabled until it was given and the section map refused to
 * jump forward over it — so an accidental Apply on Market Clearance meant a
 * shopfront greengrocer had to invent a market name and a stall number or
 * cancel the whole filing.
 *
 * Five real drafts were in exactly that state — 4, 5, 7, 3376 and 3379, split
 * across two testers' accounts, none of them able to reach Review & Submit.
 * Withdrawing MARKET was verified to free all five against a copy of the
 * register; app 5 needed three (it also carried SANITARY and OCCUPANCY with no
 * saved sheet, which is what an accidental "apply for everything" looks like).
 *
 * The stranding itself is gone with the reordering: the sheets are not wizard
 * steps any more, they open over the clearance cards, and Back without saving
 * always works. What is left is milder and still worth a sentence — a sheet
 * that will not SAVE without answers the applicant does not have, on a
 * clearance they did not mean to apply for and are now being charged for.
 *
 * So this list is the sheets that have to POINT AT THE WAY OUT. The applicant
 * who needs it is not looking at the cards; they are looking at the form they
 * cannot finish.
 *
 * Derived from officeFormMissing below rather than typed out, so a sheet that
 * gains or loses a required answer cannot fall out of step with the sentence
 * that explains what to do about it.
 */
export function officeFormCanBlock(code: OfficeFormCode): boolean {
  return officeFormMissing(code, {}).length > 0
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
    /*
     * Two required answers, and CEC becomes a blocking sheet because of them —
     * `officeFormCanBlock('CEC')` was false and is now true.
     *
     * Both are on the paper above the REMARKS rule, and neither can be derived.
     * The address is the one field on MCG-CENRO-FO-001 the register does not
     * hold anywhere (there is no residential address on a business), and the
     * certification is an act rather than a fact — a sheet submitted without it
     * is one the office would hand back, so the applicant should be stopped
     * here rather than there.
     *
     * The birthday stays optional, as it was: the paper prints the box but the
     * counter accepts the form without it, and inventing a requirement the
     * counter does not enforce is the mistake this list already avoids for
     * ZONING.
     */
    if (!has('owner_address')) missing.push('Owner’s Address')
    if (data.certified !== 'yes') missing.push('The certification that the details are correct')
  }
  if (code === 'OCCUPANCY') {
    if (!has('application_type')) missing.push('Application Type')
  }
  // The MARKET branch was here (name of market, stall no., an optional stall
  // count the fee engine read). Removed with the Market Clearance on
  // 6 September 2026 — see OFFICE_FORM_META.
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
  /*
   * A chip cannot be `readOnly` — it is a <button role="radio">, and HTML has
   * no read-only for that. `aria-disabled` with the handler suppressed is the
   * closest honest equivalent: the chip stays in the tab order and is still
   * announced with its checked state, so somebody reading back a submitted
   * sheet can hear which option was chosen, but pressing it does nothing.
   * `disabled` would drop it out of the tree entirely and take the answer with
   * it.
   */
  const ro = useReadOnly()

  return (
    <button
      type="button"
      role="radio"
      aria-checked={selected}
      aria-disabled={ro || undefined}
      onClick={ro ? undefined : onClick}
      className={`flex items-center gap-2.5 rounded-md border px-4 py-2.5 text-sm font-medium transition-colors ${
        selected
          ? 'border-royal bg-input text-ink'
          : 'border-input-border bg-input/60 text-ink-secondary'
      } ${ro ? 'cursor-default' : 'hover:bg-input'}`}
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
        {/*
         * No placeholder. It read "Filled in from your application", which is
         * what `<FromApplicationTag />` in the label beside it already says —
         * so a field with nothing in it said the sentence twice and the value
         * nowhere, and a row of six of them looked like six answers rather
         * than six blanks (client, 2026-09-08).
         *
         * An empty box now reads as empty, which is the true state: these are
         * questions the applicant has not answered on the BPLO form, and the
         * office needs to see that they are unanswered rather than see a
         * reassuring sentence where the answer should be.
         */}
        <input
          value={value}
          readOnly
          aria-readonly="true"
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
        From your earlier answers. Change it on Business Information or Location &amp; Zoning and every
        office form follows.
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
/**
 * MCG-CPDD-FO-003 v1.2, "Application for Locational Clearance (Business
 * Activities)" — items I to IX, in the paper's own numbering.
 *
 * ── No invented sections ──────────────────────────────────────────────────
 *
 * This was carved into A/B/C — Application Details, Project Description,
 * Authorized Representative — and the paper has none of them. It has a numbered
 * run from I to IX and nothing above it, so the numbers ARE the structure and
 * printing them is both more faithful and more useful: an officer holding the
 * paper can find item VIII.C on the screen without reading a word.
 *
 * VIII.C and VIII.D were previously one derived sentence, "Leased from Acme
 * Realty", which read well and answered neither box. The paper wants the
 * lessor's name and the lessor's address separately, so they are separate.
 *
 * Item II, Home Address, is the one field on this form the register does not
 * hold anywhere — the same gap CENRO's "Owner's Address" hits, and the same
 * answer. It is asked here.
 */
function ZoningFields({
  data,
  set,
  business,
}: {
  data: OfficeFormData
  set: (key: string, value: string) => void
  business: CarriedOverBusiness
}) {
  const ro = useReadOnly()

  return (
    <div className="space-y-7">
      <section className="space-y-4">
        <h2 className="text-[15px] font-bold uppercase tracking-wide text-ink">
          Application for Locational Clearance (Business Activities)
        </h2>

        {/*
          The paper's masthead: the "Application No." box top right, which the
          counter fills in on receipt, and the date. Unnumbered on the form —
          they sit above item I — so they sit above item I here.

          MCZ is the prefix the register already mints zoning permits under, so
          the placeholder shows the shape of the number the applicant will
          eventually be given rather than a shape we made up for the box.
        */}
        <div className="grid gap-5 sm:grid-cols-2">
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

        {/* I. NAME OF PROPRIETOR/PRESIDENT/GENERAL MANAGER · CONTACT NO. · EMAIL ADD. */}
        <div className="grid gap-5 sm:grid-cols-2">
          <DerivedField
            label={
              <>
                Name of Proprietor / President / General Manager
                <FromApplicationTag />
              </>
            }
            value={business.proprietorName}
          />
          <DerivedField
            label={<>Contact No.<FromApplicationTag /></>}
            value={business.proprietorContact}
          />
          <DerivedField
            label={<>Email Address<FromApplicationTag /></>}
            value={business.proprietorEmail}
          />
        </div>

        {/*
          II. HOME ADDRESS — an input, because nothing on the filing answers it.
          `business_addresses` carries an `address_type` column that has only
          ever held `business_location`, so the proprietor's residence has no
          home on the BPLO form. CENRO's sheet asks the same question under a
          different name; if BPLO ever grows the field, both become carried.
        */}
        <label className="block">
          <FieldLabel required>Home Address</FieldLabel>
          <input
            value={get(data, 'zoning_home_address')}
            onChange={(e) => set('zoning_home_address', e.target.value)}
            readOnly={ro}
            placeholder="No. of Street, Barangay, Municipality/City, Province"
            className={inputCls}
          />
        </label>

        {/* III. NAME OF FIRM · CONTACT NO.  |  IV. ADDRESS OF FIRM */}
        <div className="grid gap-5 sm:grid-cols-2">
          <DerivedField
            label={<>Name of Firm<FromApplicationTag /></>}
            value={business.name}
          />
          <DerivedField
            label={<>Contact No.<FromApplicationTag /></>}
            value={business.proprietorContact}
          />
          <DerivedField
            label={<>Address of Firm<FromApplicationTag /></>}
            value={business.address}
          />
          <DerivedField
            label={<>Activity<FromApplicationTag /></>}
            value={business.activity}
          />
        </div>

        {/*
          VI. LOCATION/ADDRESS OF PROJECT/ACTIVITY.

          The same address as item IV on every filing this system can produce —
          BizTrack records ONE premises per business, and it is where the trade
          happens. Printed anyway rather than folded into IV, because the paper
          asks twice and an officer checking the sheet against the form should
          find both boxes answered.
        */}
        <DerivedField
          label={<>Location / Address of Project or Activity<FromApplicationTag /></>}
          value={business.address}
        />

        {/* VII. NATURE OF APPLICATION — New Business or Renewal, from the filing. */}
        <DerivedField
          label={<>Nature of Application<FromApplicationTag /></>}
          value={get(data, 'application_type')}
        />

        {/* VIII. PROJECT DESCRIPTION */}
        <label className="block">
          <FieldLabel>Project Description</FieldLabel>
          <textarea
            rows={3}
            value={get(data, 'zoning_project_description')}
            onChange={(e) => set('zoning_project_description', e.target.value)}
            readOnly={ro}
            placeholder="e.g. Two-storey coffee shop with a small roasting area at the rear"
            className={inputCls}
          />
          <p className="mt-1 text-xs text-ink-muted">
            In your own words, what will be built or operated at this address.
          </p>
        </label>

        <div className="grid gap-5 sm:grid-cols-2">
          <DerivedField
            label={<>Floor Area to be / being Utilized<FromApplicationTag /></>}
            value={get(data, 'total_floor_area_sqm')}
            hint="Square metres, from your Business Operation answers."
          />
          <DerivedField
            label={<>No. of Storey of Building<FromApplicationTag /></>}
            value={get(data, 'building_storeys')}
          />
          {/*
            VIII.C and VIII.D — "(if lessee)" on the paper, so they are blank on
            an owner-occupied site by design rather than by omission.
          */}
          <DerivedField
            label={<>Name of Lessor (if lessee)<FromApplicationTag /></>}
            value={get(data, 'lessor_name')}
          />
          <DerivedField
            label={<>Address of Lessor (if lessee)<FromApplicationTag /></>}
            value={get(data, 'lessor_address')}
          />
        </div>

        <div>
          <FieldLabel>Type of Industrial Project</FieldLabel>
          <ChipRow
            label="Type of industrial project"
            options={ZONING_INDUSTRIAL_PROJECT_TYPES}
            value={get(data, 'zoning_industrial_project_type')}
            onChange={(v) => set('zoning_industrial_project_type', v)}
          />
          <p className="mt-1 text-xs text-ink-muted">
            Only for industrial projects. Leave it alone if yours is not one.
          </p>
        </div>

        {/*
          IX. AUTHORIZED REPRESENTATIVE.

          Asked on the BFP sheet first, so when that sheet is on the filing this
          one carries the answer read-only and the applicant is not asked twice.
          When it is not, nobody has asked, and this sheet takes the input.
        */}
        {get(data, 'authorized_representative_source') === 'FSIC' ? (
          <DerivedField
            label={
              <>
                Authorized Representative
                <span className="font-normal text-ink-muted"> (from your FSIC form)</span>
              </>
            }
            value={get(data, 'authorized_representative')}
            hint="Change it on the Fire Safety Inspection Certificate form and it follows here."
          />
        ) : (
          <label className="block">
            <FieldLabel>Authorized Representative</FieldLabel>
            <input
              value={get(data, 'authorized_representative')}
              onChange={(e) => set('authorized_representative', e.target.value)}
              readOnly={ro}
              placeholder="Full name"
              className={inputCls}
            />
            <p className="mt-1 text-xs text-ink-muted">
              Leave blank if you are filing this yourself. If you name someone, CPDD asks for an
              authorization letter with your documents.
            </p>
          </label>
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
  const ro = useReadOnly()
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
          {/*
           * Asked once, on the Business & Tax Profile, and asked there because
           * that is where it is PRICED: the health certificate fee (Sec.
           * 4D.02) is ₱50 per employee per year against the headcount declared
           * on the profile, charged only when the applicant says their staff
           * need certificates.
           *
           * This box used to ask for the number a second time, in free text
           * that nothing read. Two answers to one question is the
           * capitalization case, and on this sheet it was the bad version of
           * it: the office would have read one number here and been handed a
           * Tax Order of Payment computed on another, for the same fee, on the
           * same filing.
           */}
          <DerivedField
            label={
              <>
                No. of Workers Requiring Health Certificates
                <FromApplicationTag />
              </>
            }
            value={get(data, 'workers_requiring_health_certs')}
            hint="From the employee count on your Business & Tax Profile — the same number the health certificate fee is charged on."
          />
          <div>
            <FieldLabel>Water Source</FieldLabel>
            <select
              value={get(data, 'water_source')}
              onChange={(e) => set('water_source', e.target.value)}
              disabled={ro}
              aria-disabled={ro}
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

/**
 * The paper's legend, in the wording the client settled on.
 *
 * CNC is "Certificate on Non-Coverage" by their instruction (2026-09-08). The
 * form itself prints "Certificate on Non-Compliance", which reads like a
 * transcription slip on the paper — the DENR instrument is a non-coverage
 * certificate, issued to a project the ECC system does not cover.
 *
 * PTO carries its condition in the legend because that is where the paper puts
 * it, and because the system cannot answer it: nothing on a filing says whether
 * the business runs a generator.
 */
const DENR_GLOSSARY: { code: string; meaning: string }[] = [
  { code: 'ECC', meaning: 'Environmental Compliance Certificate' },
  { code: 'CNC', meaning: 'Certificate on Non-Coverage' },
  { code: 'WDP', meaning: 'Waste Water Discharge Permit' },
  { code: 'HWP', meaning: 'Hazardous Waste Permit' },
  { code: 'PTO', meaning: 'Permit to Operate (Air Pollution) — required if you have a generator' },
  { code: 'PCO', meaning: 'Registered Pollution Control Officer' },
]

/**
 * The CHECKLIST OF REQUIREMENTS printed on MCG-CPDD-FO-003 v1.2.
 *
 * ── Why the rows are not decided here ─────────────────────────────────────
 *
 * The paper's checklist branches: title, tax declaration and RPT clearance if
 * the site is OWNED, lease and lot-owner consent if it is RENTED, an
 * authorization letter only when somebody else is filing. All three conditions
 * are answers the filing already holds, so `App\Support\ZoningRequirements`
 * takes the branch and this renders what it is given. A second copy of the rule
 * in the browser is the shape this codebase has been bitten by repeatedly — a
 * rule private to one of two consumers — and the second consumer here is CPDD's
 * own review screen, which reads the same list.
 *
 * ── Three kinds of row, and only one of them takes a file ─────────────────
 *
 * `carried` is already on the filing: the applicant attached it at step 4 of
 * the business permit wizard, and asking again would invite a second answer to
 * a question already answered. `sheet` is this form. `upload` is the rest.
 *
 * ── The controls are the wizard's, not new ones ───────────────────────────
 *
 * The client, 9 September 2026: *"just copy the format of the uploading of
 * documents in the BPLO form, where the applicant can remove, download, etc."*
 * So an upload row is the wizard's dashed dropzone, and an attached file is the
 * wizard's file line — filename, size, the shared <DocumentActions> (View and
 * Download), then Remove. The first draft of this panel had a bare Upload
 * button and no way to see what had arrived, which is exactly the defect
 * checklist item 96 raised against the wizard and which <DocumentActions>
 * exists to answer: uploading the wrong scan is the easiest mistake here, and
 * it was the one mistake the screen would not let you check for.
 *
 * ── Nothing here blocks the submit button ─────────────────────────────────
 *
 * The paper is a counter checklist a clerk ticks on receipt, not a gate, and
 * the notarised declaration in particular cannot be a precondition of an online
 * form — how notarisation is meant to work in this flow is still an open
 * question with the LGU (questions-for-malabon C9 item 2). So an incomplete
 * checklist is shown, said plainly, and submitted anyway.
 */
/**
 * The heading and the office's own name, per sheet.
 *
 * Two papers ask for documents and they call the box different things: CPDD
 * prints "CHECKLIST OF REQUIREMENTS", CENRO prints "REQUIREMENTS FOR
 * APPLICATION". Printing CPDD's title on CENRO's sheet would be the same fault
 * as inventing sections the paper does not have — and naming the wrong office
 * in the copy beneath it is worse, because the applicant would go and ask them.
 */
const REQUIREMENTS_META: Partial<Record<OfficeFormCode, { title: string; office: string }>> = {
  ZONING: { title: 'Checklist of Requirements', office: 'CPDD' },
  CEC: { title: 'Requirements for Application', office: 'CENRO' },
}

function RequirementsChecklist({
  code,
  rows,
  busy,
  error,
  onChange,
  onDeclarationTemplate,
}: {
  code: OfficeFormCode
  rows: OfficeFormRequirement[]
  busy: string | null
  error: string | null
  onChange?: (documentCode: string, file: File | null) => void
  onDeclarationTemplate?: () => void
}) {
  const ro = useReadOnly()
  const outstanding = rows.filter((r) => !r.satisfied).length
  const meta = REQUIREMENTS_META[code] ?? {
    title: 'Requirements',
    office: 'This office',
  }

  return (
    <section className="space-y-4 border-t border-line pt-7">
      <div>
        <h2 className="text-[15px] font-bold uppercase tracking-wide text-ink">{meta.title}</h2>
        <p className="mt-1 text-xs leading-relaxed text-ink-muted">
          {ro
            ? `What ${meta.office} received with this application.`
            : outstanding === 0
              ? `Everything on ${meta.office}’s list is here.`
              : `${meta.office} asks for ${rows.length === 1 ? 'this' : 'these'} with the application. ${outstanding} ${
                  outstanding === 1 ? 'is' : 'are'
                } still missing — you can submit the form now and add ${
                  outstanding === 1 ? 'it' : 'them'
                }, but the office will ask.`}
        </p>
      </div>

      {error !== null && (
        <p
          role="alert"
          className="rounded-md border border-s-red bg-s-red-tint px-3 py-2 text-sm text-ink"
        >
          {error}
        </p>
      )}

      <div className="space-y-5">
        {rows.map((row) => (
          <RequirementRow
            key={row.key}
            row={row}
            busy={busy === row.code}
            readOnly={ro}
            onChange={onChange}
            onDeclarationTemplate={
              row.key === 'DECLARATION' ? onDeclarationTemplate : undefined
            }
          />
        ))}
      </div>
    </section>
  )
}

/** One checklist row, in the wizard's document-upload shape. */
function RequirementRow({
  row,
  busy,
  readOnly,
  onChange,
  onDeclarationTemplate,
}: {
  row: OfficeFormRequirement
  busy: boolean
  readOnly: boolean
  onChange?: (documentCode: string, file: File | null) => void
  onDeclarationTemplate?: () => void
}) {
  const takesFile = row.source === 'upload' && row.code !== null && !readOnly && onChange

  return (
    <div>
      <p className="flex items-center gap-2 text-sm font-bold text-ink">
        {row.satisfied ? (
          <CheckCircleFilledIcon size={16} className="shrink-0 text-s-green" />
        ) : (
          <span
            aria-hidden
            className="h-3.5 w-3.5 shrink-0 rounded-[3px] border border-ink-muted"
          />
        )}
        <span className="min-w-0">{row.label}</span>
      </p>
      <p className="mt-1 pl-[22px] text-xs leading-relaxed text-ink-muted">{row.note}</p>

      {/*
        The declaration template, beside the row that asks for the scan of it.
        Section X is sworn before a notary and nothing in this flow can do that,
        so what the system CAN hand over is the exact page the notary expects —
        with the filing already named on it, so the scan that comes back is
        matchable to an application rather than to a business name.
      */}
      {onDeclarationTemplate !== undefined && (
        <button
          type="button"
          onClick={onDeclarationTemplate}
          className="ml-[22px] mt-2 inline-flex items-center gap-1.5 rounded-md border border-royal px-3 py-1.5 text-xs font-semibold text-royal transition-colors hover:bg-royal-tint"
        >
          <DownloadIcon size={14} />
          Download the template
        </button>
      )}

      {/* The file already on the filing, with the same controls the wizard gives it. */}
      {row.document !== null && (
        <div className="ml-[22px] mt-2 flex items-center gap-3 rounded-lg border border-input-border bg-input/50 px-4 py-2.5">
          <span className="min-w-0 flex-1 truncate text-sm text-ink">
            {row.document.filename}
          </span>
          {row.document.size_bytes !== null && (
            <span className="tnum shrink-0 text-xs text-ink-muted">
              {formatBytes(row.document.size_bytes)}
            </span>
          )}
          {/*
            Labelled by filename, not by the requirement: two rows can name the
            same document (an owner's title answers TCT and a lessee's lease
            answers Contract of Lease, both from LEASE_TITLE), so "View
            Transfer Certificate of Title" twice over would name one file two
            ways to a screen reader.
          */}
          <DocumentActions id={row.document.id} filename={row.document.filename} />
          {takesFile && (
            <button
              type="button"
              onClick={() => onChange!(row.code!, null)}
              disabled={busy}
              aria-label={`Remove ${row.document.filename}`}
              className="shrink-0 text-sm font-semibold text-s-red underline underline-offset-2 disabled:opacity-60"
            >
              {busy ? 'Removing…' : 'Remove'}
            </button>
          )}
        </div>
      )}

      {/*
        A `carried` row satisfied by something that is not a file — CENRO's
        previous-year CEC, which is a certificate the register issued. Its
        number is printed because there is nothing to open: a permit is not an
        attachment, and the office reads it off the filing.
      */}
      {row.reference != null && row.reference !== '' && (
        <p className="ml-[22px] mt-2 text-xs text-ink-secondary">
          On file: <span className="tnum font-medium text-ink">{row.reference}</span>
        </p>
      )}

      {/*
        A `carried` row has no dropzone: the document is attached with the
        business permit requirements and changing it there changes it here. A
        second upload box for one file is a second file the office has to choose
        between.
      */}
      {row.source === 'carried' && row.document === null && !row.satisfied && (
        <p className="ml-[22px] mt-2 text-xs font-medium text-s-orange">
          Not attached yet. Add it to your business permit documents and it appears here.
        </p>
      )}

      {takesFile && (
        <label
          className={`ml-[22px] mt-2 flex cursor-pointer items-center gap-3 rounded-lg border-2 border-dashed border-input-border bg-input/50 px-5 py-3 transition-colors hover:bg-input ${
            busy ? 'pointer-events-none opacity-60' : ''
          }`}
        >
          <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-input-border bg-white text-royal">
            <UploadIcon size={16} />
          </span>
          <span className="min-w-0">
            <span className="block text-sm font-semibold text-royal">
              {busy
                ? 'Uploading…'
                : row.document !== null
                  ? 'Upload a different file'
                  : 'Upload a file'}
            </span>
            <span className="block text-xs text-ink-muted">
              {row.document !== null
                ? 'This replaces the file above.'
                : 'file type: png, jpg, pdf only'}
            </span>
          </span>
          <span className="sr-only">{row.label}</span>
          <input
            type="file"
            accept={ACCEPT_ATTR}
            className="sr-only"
            disabled={busy}
            onChange={(e) => {
              const file = e.target.files?.[0]
              // Let the same file be picked twice — after a rejection the input
              // would otherwise be inert.
              e.target.value = ''
              if (file && row.code) onChange!(row.code, file)
            }}
          />
        </label>
      )}
    </div>
  )
}

/**
 * "REQUIRED DENR PERMITS FOR APPLICATION" — told, not collected.
 *
 * The client: "our system will tell the applicant (in the CENRO application
 * form itself) the required DENR permits he/she needs to submit. Just list them
 * somewhere visible (it is not there where the applicant will submit the said
 * DENR permits; just a list)."
 *
 * So this renders no control at all. The permits are DENR's to issue, not the
 * LGU's to receive, and the form's own footnote gives the applicant six months
 * from the CEC's issuance to comply — a screen that demanded them here would
 * withhold a clearance the LGU is willing to grant.
 *
 * Every value is derived server-side from the declared business category, off
 * the same table the CENRO environmental fee is priced from
 * (`App\Support\DenrRequirements`). The matched row is printed BY NAME, because
 * a derivation the applicant cannot check is one they have to take on trust —
 * and if CENRO has them on the wrong row, the row is the thing they need to
 * see to say so.
 *
 * `denr_basis` absent means no row matched. That is a real and common state,
 * not an error: the category picker offers the Revenue Code's business-tax
 * vocabulary and CENRO's table uses its own, so a filing declaring
 * "Manufacturer" sits between the paper's big-scale and small-scale rows with
 * nothing to say which. Saying so is the only safe answer — printing "none
 * required" would be a claim, and the wrong one.
 */
function DenrRequirementsPanel({ data }: { data: OfficeFormData }) {
  const basis = get(data, 'denr_basis')
  const certificate = get(data, 'denr_certificate')
  const permits = get(data, 'denr_permits')
  const pco = get(data, 'denr_pco')
  const remarks = get(data, 'denr_remarks')

  /*
   * Which of the six this business needs.
   *
   * All six are listed either way, ticked or dashed. "You do not need a
   * Hazardous Waste Permit" is as useful to an applicant as the reverse and
   * costs one line to say — and a list of six is easier to read than a table
   * that names three of them and a glossary that repeats two.
   */
  const required = new Set<string>()
  if (certificate !== '') required.add(certificate)
  permits.split(',').forEach((p) => {
    const code = p.trim()
    if (code !== '' && code !== 'None') required.add(code)
  })
  if (pco === 'Required') required.add('PCO')

  return (
    <section className="space-y-3" aria-labelledby="denr-heading">
      <div className="flex items-center gap-2">
        <span className="h-4 w-1 rounded-full bg-s-green" aria-hidden="true" />
        <h3 id="denr-heading" className="text-sm font-bold text-ink">
          Required DENR permits for this business
        </h3>
      </div>

      {basis === '' ? (
        <div className="rounded-lg border border-line bg-canvas px-4 py-4">
          <p className="text-sm text-ink">
            CENRO will confirm which DENR permits your business needs.
          </p>
          {/*
           * Only one reason reaches this branch now, and naming it is the whole
           * improvement. The panel used to say "does not match one of CENRO's
           * listed categories closely enough" on almost every filing, because it
           * read the free-typed fee category and nothing else. It reads the PSIC
           * line of business too, and falls back to the paper's own row 28 for
           * anything genuinely unlisted — so an unanswered panel is now a
           * specific, explicable state rather than a shrug.
           *
           * That state is manufacturing. CENRO's table has two rows for it,
           * "All Big Scale" and "Small-Scale", with very different consequences,
           * and it defines neither. Saying so is better than picking.
           */}
          <p className="mt-1 text-xs leading-relaxed text-ink-secondary">
            {get(data, 'denr_reason') === 'scale'
              ? 'CENRO lists big-scale and small-scale manufacturing separately, so the office decides which yours is.'
              : 'The office decides this when they review your form.'}
          </p>
        </div>
      ) : (
        <div className="rounded-lg border border-s-green/40 bg-s-green-tint px-4 py-4">
          {/*
            One sentence, in the client's own framing: "put a very short,
            user-friendly explanation stating that this is based on the line of
            business from the business permit application form."
          */}
          <p className="text-xs leading-relaxed text-ink-secondary">
            Based on the line of business in your business permit application. DENR issues these,
            not the City — you do not submit them here.
          </p>

          {/*
            The six, in the paper's legend order, each ticked or dashed. This
            replaced a three-column table, a labelled remarks block and a
            glossary that repeated two of the codes — four devices saying what
            fits in six lines. The client: "Do not overcomplicate this part.
            Just list what is required from the 6 DENR permits."
          */}
          <ul className="mt-3 space-y-1.5">
            {DENR_GLOSSARY.map((g) => {
              const need = required.has(g.code)
              return (
                <li key={g.code} className="flex items-baseline gap-2 text-sm leading-relaxed">
                  <span
                    aria-hidden="true"
                    className={`w-4 shrink-0 text-center font-bold ${
                      need ? 'text-s-green' : 'text-ink-muted'
                    }`}
                  >
                    {need ? '✓' : '—'}
                  </span>
                  <span className={need ? 'text-ink' : 'text-ink-muted'}>
                    <span className="font-semibold">{g.code}</span> — {g.meaning}
                    {/* The tick is decorative; the state has to be readable. */}
                    <span className="sr-only">{need ? ' — required' : ' — not required'}</span>
                  </span>
                </li>
              )
            })}
          </ul>

          {/*
            Kept, as one plain line without its label. It is the only thing here
            that can CHANGE the list above — "if you handle hazardous or toxic
            materials, an ECC, a Hazardous Waste Permit and a Pollution Control
            Officer are required" — and the system cannot answer it, so dropping
            it would understate the requirements rather than simplify them.
          */}
          {remarks !== '' && (
            <p className="mt-3 text-xs leading-relaxed text-ink-secondary">{remarks}</p>
          )}

          {/*
            ── The deadline, and what happens if it passes ────────────────────

            The paper's own footnote, and the only line on this panel with a
            consequence attached: "The following required DENR permit/s must be
            submitted/complied to this office within six (6) months upon
            issuance of the CEC, on or before ______, otherwise the CEC issued
            will be automatically revoked."

            The clock runs from ISSUANCE, not from this form — so at the moment
            an applicant reads this there is no date to print. Saying "six
            months from the day CENRO issues it" is the honest version of the
            paper's blank line; the actual date belongs on the issued
            certificate, where issuance has happened and the arithmetic is real.

            Set apart with a rule above it because it is not another item in the
            list: everything above says WHAT is required, this says by when and
            what it costs to miss it.
          */}
          {/*
            The paper's footnote, VERBATIM — the client's instruction, and the
            right call. This was paraphrased ("these must be submitted to CENRO
            within six months of your CEC being issued…"), which read more
            smoothly and was not the text the applicant is being held to. A
            deadline with a revocation attached is exactly the sentence to quote
            rather than improve.

            The blank line is the paper's own: the date is filled in at the
            counter, and at the moment this sheet is read the CEC has not been
            issued, so there is nothing to put in it.
          */}
          <p className="mt-3 border-t border-s-green/30 pt-3 text-xs italic leading-relaxed text-ink">
            * The following required DENR permit/s must be submitted/complied to this office within
            six (6) months upon issuance of the CEC, on or before ______________, otherwise the CEC
            issued will be automatically revoked.
          </p>
        </div>
      )}
    </section>
  )
}

/**
 * MCG-CENRO-FO-001 v2.0, "Ownership and Documentation".
 *
 * The sheet used to be four boxes — type of application, control no., filing
 * date, owner's birthday — against a paper that asks for eighteen. The other
 * fourteen were not missing from the SYSTEM, only from this screen: BPLO
 * collects all but two of them, so they are carried over read-only rather than
 * asked twice, under the same rule as `CarriedOverSection`.
 *
 * The two the system genuinely did not hold are asked here, and both are
 * particular to this office's paper:
 *
 *  - OWNER'S ADDRESS. The register keeps the BUSINESS address (there is a
 *    `business_addresses.address_type` column, and nothing has ever written a
 *    residential row to it), so the proprietor's own address has no home in the
 *    BPLO form yet. It is asked here, plainly, rather than left blank on a
 *    sheet that prints a box for it. If the BPLO form ever grows item A16, this
 *    becomes a carried-over field like the rest and the question comes off.
 *  - THE CERTIFICATION. "I hereby certify that all information contained herein
 *    is true and correct", over the owner's printed name. The printed name is
 *    BPLO's answer; the certifying is an act, so it is a real tick.
 *
 * Everything below the paper's REMARKS FINDINGS AND RECOMMENDATIONS rule is the
 * office's half — the evaluator, the chief, the DENR permit checklist — and is
 * deliberately not on the applicant's sheet.
 */
/**
 * MCG-CENRO-FO-001 v2.0, "OWNERSHIP AND DOCUMENTATION" — the whole sheet.
 *
 * ── One block, because the paper has one block ────────────────────────────
 *
 * This was carved into A/B/C/D — Application Details, Ownership, Business
 * Details, Certification — added as a reading aid when the sheet grew from four
 * fields to eighteen. The client removed them, and was right to: the paper has a
 * single band with everything under it, and inventing structure the source
 * document does not have is the same fault as leaving a field off it.
 *
 * The order below is the paper's own reading order, left column then right, row
 * by row. Not its LAYOUT — the paper is a two-column table sized for a
 * typewriter, and reproducing that on a phone would be unfaithful in a different
 * way. Same questions, same sequence.
 *
 * The shared Business Details block is suppressed for this sheet (see
 * OfficeFormSheet): this form asks for the business name, address and line of
 * business itself, so carrying them above as well printed all three twice.
 */
function CecFields({
  data,
  set,
  business,
}: {
  data: OfficeFormData
  set: (key: string, value: string) => void
  business: CarriedOverBusiness
}) {
  const ro = useReadOnly()
  const birthday = get(data, 'owner_birthday')
  const birthdayInFuture = birthday !== '' && birthday >= todayISO()

  return (
    <div className="space-y-7">
      <section className="space-y-4">
        <h2 className="text-[15px] font-bold uppercase tracking-wide text-ink">
          Ownership and Documentation
        </h2>

        {/* TYPE OF APPLICATION | TYPE OF BUSINESS */}
        <div className="grid gap-5 sm:grid-cols-2">
          <ApplicationDateField data={data} />
          <ControlNoField
            label={
              <>
                Control No.
                <AutoTag />
              </>
            }
            placeholder="CEC-2026-________"
          />
          <DerivedField
            label={
              <>
                Type of Application
                <FromApplicationTag />
              </>
            }
            value={get(data, 'application_type')}
          />
          <DerivedField
            label={
              <>
                Type of Business
                <FromApplicationTag />
              </>
            }
            value={business.registrationType}
          />
        </div>

        {/* BUSINESS AREA | TOTAL NO. OF EMPLOYEES (MALE / FEMALE) */}
        <div className="grid gap-5 sm:grid-cols-3">
          <DerivedField
            label={<>Business Area (in sq. m.)<FromApplicationTag /></>}
            value={business.businessAreaSqm}
          />
          <DerivedField
            label={<>Total No. of Employees — Male<FromApplicationTag /></>}
            value={business.maleEmployees}
          />
          <DerivedField
            label={<>Total No. of Employees — Female<FromApplicationTag /></>}
            value={business.femaleEmployees}
          />
        </div>

        {/* OWNER | BUSINESS NAME */}
        <div className="grid gap-5 sm:grid-cols-2">
          <DerivedField
            label={
              <>
                Owner (Family Name, First Name, Middle Name)
                <FromApplicationTag />
              </>
            }
            value={business.ownerName}
          />
          <DerivedField label={<>Business Name<FromApplicationTag /></>} value={business.name} />
        </div>

        {/* OWNER&rsquo;S ADDRESS | BUSINESS ADDRESS */}
        <div className="grid gap-5 sm:grid-cols-2">
          {/*
            The one address the register does not hold. `business_addresses` has
            an `address_type` column and nothing has ever written a residential
            row to it, so the proprietor's own address has no home on the BPLO
            form yet. Asked here as one line, matching the paper's single box and
            its own prompt. If BPLO ever grows item A16 this becomes a carried
            field like the rest and the question comes off.
          */}
          <label className="block">
            <FieldLabel required>Owner&rsquo;s Address</FieldLabel>
            <input
              value={get(data, 'owner_address')}
              onChange={(e) => set('owner_address', e.target.value)}
              readOnly={ro}
              placeholder="No. of Street, Barangay, Municipality/City, Province"
              className={inputCls}
            />
          </label>
          <DerivedField
            label={<>Business Address<FromApplicationTag /></>}
            value={business.address}
          />
        </div>

        {/* BIRTHDAY | SEX */}
        <div className="grid gap-5 sm:grid-cols-2">
          <div>
            <FieldLabel>Birthday</FieldLabel>
            {/* Birthdays can never be in the future: capped here, re-checked by the API. */}
            <input
              type="date"
              max={todayISO()}
              value={birthday}
              onChange={(e) => set('owner_birthday', e.target.value)}
              readOnly={ro}
              className={inputCls}
              aria-invalid={birthdayInFuture}
            />
            {birthdayInFuture && (
              <p className="mt-1 text-xs font-medium text-s-red">
                The birthday must be a date in the past.
              </p>
            )}
          </div>
          <DerivedField label={<>Sex<FromApplicationTag /></>} value={business.ownerSex} />
        </div>

        {/* LINE OF BUSINESS | PRODUCTS/SERVICES | CONTACT NUMBERS */}
        <div className="grid gap-5 sm:grid-cols-2">
          <DerivedField
            label={<>Line of Business<FromApplicationTag /></>}
            value={business.lineOfBusiness}
          />
          <DerivedField
            label={<>Products / Services<FromApplicationTag /></>}
            value={business.productsServices}
          />
          <DerivedField
            label={<>Contact Numbers — Landline<FromApplicationTag /></>}
            value={business.landline}
          />
          <DerivedField
            label={<>Contact Numbers — Mobile No.<FromApplicationTag /></>}
            value={business.mobile}
          />
        </div>

        {/*
          The paper's certification and signature block, as the one part of it
          that can be done in a browser. The printed name is the owner BPLO
          already knows; what the applicant supplies is the act of certifying, so
          that is the control.
        */}
        <div className="rounded-lg border border-line bg-canvas px-4 py-4">
          <label className="flex items-start gap-3">
            <input
              type="checkbox"
              checked={get(data, 'certified') === 'yes'}
              onChange={(e) => set('certified', e.target.checked ? 'yes' : '')}
              disabled={ro}
              aria-disabled={ro}
              className="mt-0.5 h-4 w-4 shrink-0 accent-royal"
            />
            <span className="text-sm leading-relaxed text-ink">
              I hereby certify that all information contained herein is true and correct.
            </span>
          </label>
          <div className="mt-4 sm:w-2/3">
            <DerivedField
              label={<>Printed Name of Owner<FromApplicationTag /></>}
              value={business.ownerName}
            />
          </div>
        </div>
      </section>

      {/*
        ── The paper's REQUIREMENTS FOR APPLICATION is deliberately absent ─────

        All four of its items are things CENRO can already see, so printing a
        checklist of them would be busywork on the applicant's screen:

         - "Application for Renewal / New Business" — the officer reads the BPLO
           form beside this sheet;
         - "Tax Order of Payment and Official Receipt" — BizTrack ISSUES both.
           Payment is in-system only and `PaymentController::receipt` renders the
           PDF, so an upload would ask the applicant to fetch a document this
           system produced and hand it straight back — and CENRO can open it
           regardless (client, 9 September 2026);
         - "Business permit" — a new business does not have one; it is what this
           filing is FOR;
         - "Certificate of Environmental Compliance (Previous Year)" — renewals
           only, and renewals are not built yet.

        If any of that stops being true — an offline payment method, an office
        that cannot reach the receipt — this is the block to bring back.
      */}

      <DenrRequirementsPanel data={data} />
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
  const ro = useReadOnly()
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
                readOnly={ro}
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
  const ro = useReadOnly()
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
              readOnly={ro}
              className={inputCls}
            />
          </div>
          <div>
            <FieldLabel>FSEC No.</FieldLabel>
            <input
              value={get(data, 'fsec_no')}
              onChange={(e) => set('fsec_no', e.target.value)}
              readOnly={ro}
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
  readOnly = false,
  requirements,
  requirementBusy = null,
  requirementError = null,
  onRequirementChange,
  onDeclarationTemplate,
}: {
  code: OfficeFormCode
  data: OfficeFormData
  business: CarriedOverBusiness
  onChange: (data: OfficeFormData) => void
  /**
   * The checklist of requirements, on the one sheet whose paper has one.
   *
   * Undefined means "not loaded, or this office has no checklist", and the
   * panel renders nothing rather than an empty list — four offices claiming
   * they ask for no documents would be four wrong claims.
   */
  requirements?: OfficeFormRequirement[]
  /** The document code with an upload in flight, so one row can say so. */
  requirementBusy?: string | null
  requirementError?: string | null
  onRequirementChange?: (documentCode: string, file: File | null) => void
  /** Fetch Section X of the CPDD paper, blank, for the applicant's notary. */
  onDeclarationTemplate?: () => void
  /**
   * Render what was submitted, not a form to fill in.
   *
   * True once the office holds this clearance. Defaulted to false so the
   * editing case — every existing caller — is unchanged, and so a caller that
   * has not thought about it gets the safe-to-use rather than the safe-to-lock
   * behaviour; the SERVER is what actually refuses a late write
   * (`OfficeFormController::ownerMayEdit`), and this is the screen agreeing
   * with it rather than the other way round.
   */
  readOnly?: boolean
}) {
  const meta = OFFICE_FORM_META[code]
  const set = (key: string, value: string) => onChange({ ...data, [key]: value })

  return (
    <ReadOnlyContext.Provider value={readOnly}>
    <div className="rounded-sm bg-white px-6 py-7 shadow-card sm:px-9 sm:py-8">
      {readOnly && (
        <div className="mb-6 rounded-lg border border-s-green/40 bg-s-green-tint px-4 py-3">
          <p className="text-sm font-semibold text-ink">
            This is what you submitted to this office.
          </p>
          <p className="mt-1 text-xs leading-relaxed text-ink-secondary">
            It cannot be changed now that the office has it. If something is wrong, message the
            office from the card you came from and they can send it back to you.
          </p>
        </div>
      )}
      <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-royal">{meta.kicker}</p>
      <h1 className="mt-1.5 text-2xl font-bold text-ink">{meta.title}</h1>
      <p className="mt-1 text-xs text-ink-muted">Form Ref: {meta.ref}</p>
      {/*
        CLR-2 — where the exit is, said on the screen the mistake leads to.

        This sheet exists because Apply was pressed on the clearance card, and
        on the three sheets with required answers it cannot be SAVED without
        them. An applicant who pressed Apply by mistake — the Market Clearance
        card is on the grid for every business in the city — has no reason to
        guess where the way out is. The audit of 2026-08-06 found five real
        drafts stuck at exactly this point.

        The trap is smaller than it was and the note matters more, which is why
        it survived the reordering rather than going with the mechanism. It used
        to be a WIZARD trap: the sheet was a step, Next stayed disabled and the
        section map refused to skip it, so an unfinished sheet stood between the
        filing and submission. The sheets are not steps now — this one opens
        over the clearance cards, and Back without saving always works — so
        nobody is stranded.

        What replaced the stranding is worse in the one way that counts: Apply
        now spends money the moment it is pressed, against a balance that holds
        the permit until it is settled. So the sentence names the fee, which the
        old one had no need to.

        One line of ordinary text under the form reference, not a banner. It is
        addressed to a minority (most people reading this sheet want it), it is
        not an error, and nothing here is wrong — a tinted panel would say
        otherwise to everyone else. Only on the sheets that can actually block a
        save; the rest can simply be left blank.
      */}
      {/*
        Rewritten twice over, and both corrections are the same shape: it was
        describing controls and consequences that no longer exist.
        `Withdraw` is gone — `ClearanceService::unapply` refuses every required
        clearance and all five are required — and nothing here touches a
        balance, because the bill was settled at submission. What IS still true
        is the way out, so that is all it says now.
      */}
      {officeFormCanBlock(code) && !readOnly && (
        <p className="mt-2 text-xs text-ink-muted">
          Opened this by mistake? Nothing here reaches the office until you press{' '}
          <span className="font-semibold">Submit to this office</span>, so you can leave it and come
          back. Your fees do not change either way.
        </p>
      )}
      <div className="mb-6 mt-3 h-px bg-royal" />
      <div className="space-y-7">
        {/* First, so the sheet opens by showing what it already knows rather
          * than by asking. */}
        {/*
          Not on the CEC or ZONING sheets. MCG-CENRO-FO-001 asks for the business
          name, address and line of business ITSELF, in its own order, so the
          shared block would print all three twice — which is what the client
          saw. MCG-CPDD-FO-003 does the same under its own numbering: III. Name
          of Firm, IV. Address of Firm, V. Activity. The other three papers open
          by asking for them, so they keep it.
        */}
        {code !== 'CEC' && code !== 'ZONING' && <CarriedOverSection business={business} />}
        {code === 'ZONING' && <ZoningFields data={data} set={set} business={business} />}
        {code === 'SANITARY' && <SanitaryFields data={data} set={set} />}
        {code === 'CEC' && <CecFields data={data} set={set} business={business} />}
        {code === 'FSIC' && <FsicFields data={data} set={set} />}
        {code === 'OCCUPANCY' && <OccupancyFields data={data} set={set} />}
        {requirements !== undefined && requirements.length > 0 && (
          <RequirementsChecklist
            code={code}
            rows={requirements}
            busy={requirementBusy}
            error={requirementError}
            onChange={onRequirementChange}
            onDeclarationTemplate={onDeclarationTemplate}
          />
        )}
      </div>
    </div>
    </ReadOnlyContext.Provider>
  )
}
