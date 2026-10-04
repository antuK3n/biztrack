import { OfficerZoningChecklist } from '../../components/ZoningRuleChecklist'
import { createContext, useContext, useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  ArrowLeftIcon,
  CheckCircleFilledIcon,
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
import {
  formatBytes,
  formatDate,
  formatDateTime,
  formatMoney,
  formatVersionDate,
  lineOfBusinessText,
} from '../../lib/format'
/*
 * The wizard's own function for item 2's heading, so the sheet reads back
 * the question that was actually put — a cooperative is asked for its CDA
 * number, not for "DTI / SEC / CDA".
 */
import {
  GENDERS,
  ORGANIZATION_FORMS,
  genderLabel,
  registrationNumberLabel,
  scalarFieldRule,
} from '../../lib/fieldRules'
import {
  OFFICE_FORM_INTERNAL_KEYS,
  officeFormFieldLabel,
  officeFormFieldRank,
  officeFormMeta,
  CorrectionAnswer,
  type OfficeFormCode,
} from '../applicant/OfficeFormStep'
import { MAIN_FORM_RETURN_TARGETS, mainFormTargetLabel } from '../../lib/returnTargets'
import { isGatheringOtherPermits, otherPermitProgress } from '../../lib/status'
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
  ApplicationCorrection,
  ClearanceCorrection,
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
    /*
     * Block, Lot and the lot's area. Optional on the form and optional
     * here, but they were MISSING here rather than optional: the API has
     * sent all three the whole time and `lib/types` Address declares them,
     * so the sheet could not draw three answers every applicant is asked
     * for, and nothing failed to say so.
     */
    block?: string | null
    lot?: string | null
    lot_area_sqm?: number | string | null
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
    /* The id, not only the name: a save has to send the barangay back. */
    barangay?: { id?: number; name?: string } | null
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
    psic_code: { id?: number; code: string; title: string } | null
    capitalization: string | null
    /* Carried through a save untouched — see `saveFields`. */
    line_of_business?: string | null
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
/**
 * A filed answer, as a RECORD rather than as a form control.
 *
 * ── Why this is not the applicant's input box ────────────────────────────
 *
 * It was one: the same rounded border, fill and padding the wizard uses. That
 * is the applicant's styling doing the applicant's job — inviting a value into
 * an empty space — on a sheet where nothing is typed and everything is
 * already answered.
 *
 * Three costs, and the client named the third. A box that looks editable and
 * refuses is the confusion reported on the locked Capital Participation
 * field. It spends about 42 pixels of height on a fact that is often ten
 * characters, and there are thirty of them. And the border and fill are ink
 * around facts, which is what the client meant on 27 September 2026 asking
 * whether the officer's side should be "a more compacted view for easy
 * checking of fields".
 *
 * ── The boxes stay; the GRID was the problem ────────────────────────────
 *
 * Replacing them with bare ruled lines was tried on 27 September 2026 and
 * rejected immediately — *"This looks MUCH WORSE"* — and the client was
 * right: the box is what makes a value read as a value rather than as loose
 * text under a heading. What wasted the space was `lg:grid-cols-3` handing
 * every field an identical third of the row, so a one-letter Gender held as
 * much of the page as an e-mail address. That is fixed on the containers,
 * not here.
 *
 * The padding is `inputCls`'s, not the looser `px-3.5 py-2.5` this carried:
 * the applicant's form was deliberately tightened and this sheet, which
 * holds more fields and exists to be read fast, had missed it.
 *
 * What does NOT change is the content or its order: the officer reads this
 * beside MCG-BPLO-FO-001, so the paper's item numbers and sequence are
 * load-bearing and stay exactly as they are.
 */
const recordValue = 'w-full rounded-lg border border-input-border bg-input px-3 py-2 text-sm text-ink'
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

/**
 * Every correction on this filing, by target code, newest first.
 *
 * A context because `Field` is mounted about forty times on this sheet and
 * only eighteen of those are correctable — passing the list to all of them to
 * serve eighteen would put a prop on twenty-two boxes that can never use it.
 *
 * Empty by default, so a `Field` outside the sheet (or on a filing that was
 * never returned) renders exactly as it always has.
 */
const FieldCorrections = createContext<Map<string, ApplicationCorrection[]>>(new Map())

/**
 * What a field was before the applicant corrected it.
 *
 * ── Uniform with Section C, and the one place it differs ────────────────────
 *
 * Client, 29 September 2026: *"Do you think it is good showing it too, just
 * like with the documentary requirements? Please be consistent and uniform
 * with the other fields as well."*
 *
 * Same badge, same wording, same fold as a re-uploaded document. The
 * difference is that the immediately previous VALUE is shown inline rather
 * than hidden: an earlier copy of a document is a file with two buttons and
 * earns a fold, while "was 111111" fits on the line, and charging a click for
 * it would be hiding the answer to the question the badge just raised.
 *
 * Only a third value onwards folds — the case a filing returned twice about
 * one field produces, which is exactly what the client's own filing did.
 */
function FieldHistory({ history, label }: { history: ApplicationCorrection[]; label: string }) {
  const [showOlder, setShowOlder] = useState(false)

  /* Newest first, so [0] is the value this one replaced. */
  const previous = history[0]
  const older = history.slice(1)
  const olderId = `field-history-${history[0]?.target ?? ''}`.replace(/[^\w-]/g, '-')

  /* An emptied field is an answer too, and "was" with nothing after it is not. */
  const shown = (text: string | null) => {
    const trimmed = (text ?? '').trim()

    return trimmed === '' ? 'blank' : trimmed
  }

  return (
    <div className="mt-1 text-xs text-ink-muted">
      {/*
        One line: the value it replaced, when, and the way to the rest.
        It read `was 11111 · corrected September 29, 2026` over a second
        line, with the fold repeating the field's whole name on a third
        and fourth — for one change to one box.
      */}
      <span>
        was <span className="line-through">{shown(previous.old_value)}</span>
        {previous.at && <> · {formatVersionDate(previous.at)}</>}
      </span>
      {older.length > 0 && (
        <>
          {' · '}
          {/*
            The long name lives in `aria-label`, not on screen. A screen
            reader hearing "1 earlier" on six fields cannot tell them
            apart, which is why the name was in the visible text; it only
            ever needed to be in the accessible one.

            A button with aria-expanded, never <details>:
            web/e2e/inspection-review.spec.ts asserts this page has none.
          */}
          <button
            type="button"
            onClick={() => setShowOlder((open) => !open)}
            aria-expanded={showOlder}
            aria-controls={olderId}
            aria-label={`${showOlder ? 'Hide' : 'Show'} the ${older.length} earlier ${
              older.length === 1 ? 'value' : 'values'
            } of ${label}`}
            className="rounded font-semibold text-royal hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
          >
            {older.length} earlier
          </button>
        </>
      )}
      <ul id={olderId} hidden={!showOlder} className="mt-0.5 space-y-0.5">
        {older.map((c, i) => (
          <li key={i}>
            was <span className="line-through">{shown(c.old_value)}</span>
            {c.at && <> · {formatVersionDate(c.at)}</>}
          </li>
        ))}
      </ul>
    </div>
  )
}

/**
 * Ends a row inside a `flex-wrap` group, so the next field starts a new one.
 *
 * ── Why the sheet needs it and the form barely does ─────────────────────────
 *
 * Copied from ApplyWizard, which uses exactly this — `-my-1.5 basis-full` —
 * once, before item 14. The form gets its other row breaks for free, because
 * its controls are naturally the right widths: four radio chips for the form
 * of organization, four small boxes for a TIN, segmented boxes for a phone
 * number.
 *
 * This sheet renders the ANSWERS to those questions in uniform record boxes,
 * so the widths that shaped the form's rows do not exist here. Client,
 * 29 September 2026: *"Compare both layouts. Huge difference, right?"* —
 * the sheet's seventeen boxes wrapped wherever they landed and grouped nothing
 * like the form. Pinning the breaks is what makes the two read the same, and
 * it holds at every window width rather than only at the one I tested.
 *
 * `basis-full` takes a whole line; `-my-1.5` pulls back the row gap either
 * side so the break costs no vertical space. `aria-hidden`, because it is a
 * layout device and a screen reader should hear the fields, not the geometry.
 */
function RowBreak() {
  return <div className="-my-1.5 basis-full" aria-hidden="true" />
}
/**
 * Section B items 1 to 4, grouped the way the form groups them.
 *
 * The paper asks four questions and the form draws six boxes: item 2 is
 * Total / Male / Female inside one bordered fieldset, and item 4 is
 * Motorized / Other inside another. One paper item, one border.
 *
 * Partitioned on the number the label already carries — the same number
 * `orderedFeeFacts` sorts on — rather than on a list of which items are
 * grouped. An item with one box stays a plain field; if the fee profile
 * ever splits another in two, it boxes itself with no edit here.
 */
function FeeFactRow({ facts }: { facts: { label: string; value: string }[] }) {
  const groups: { number: string; facts: { label: string; value: string }[] }[] = []
  for (const fact of facts) {
    const number = /^(\d+)\./.exec(fact.label)?.[1] ?? fact.label
    const last = groups[groups.length - 1]
    if (last && last.number === number) {
      last.facts.push(fact)
    } else {
      groups.push({ number, facts: [fact] })
    }
  }

  return (
    <>
      {groups.map((group) =>
        group.facts.length === 1 ? (
          <Field
            key={group.number}
            label={group.facts[0].label}
            value={group.facts[0].value}
            className="grow basis-[13rem] max-w-full"
          />
        ) : (
          /* The form's own box for a multi-box item: same radius, same tint. */
          <div
            key={group.number}
            className="grow basis-[21rem] max-w-full rounded-lg border border-line bg-canvas px-3 py-2"
          >
            <div className="flex flex-wrap items-start gap-x-3 gap-y-2">
              {group.facts.map((fact) => (
                <Field
                  key={fact.label}
                  label={fact.label}
                  value={fact.value}
                  className="grow basis-[8rem] max-w-full"
                />
              ))}
            </div>
          </div>
        ),
      )}
    </>
  )
}

/**
 * The answers the API writes over whatever the sheet holds, per sheet.
 *
 * Mirrors `OfficeFormAnswers::derive`, whose result is `$derived + $formData`:
 * these keys always win, so a value typed into one is thrown away on the next
 * read. The officer's review grid offered every key as "Correct …" in Edit
 * mode, derived ones included — CENRO was handed "Denr Basis" and "Denr Pco"
 * to correct, and a correction would have saved and silently reverted
 * (tester, 5 October 2026). Shown, never offered for correction or return.
 *
 * Only the keys derive() writes UNCONDITIONALLY. ZONING's floor area and
 * storeys are derived only when the fee profile holds a number, so they stay
 * correctable; listing them would lock an answer the applicant typed. Change
 * this with derive(). Kept here rather than beside OFFICE_FORM_INTERNAL_KEYS
 * because this screen is its only reader.
 */
const OFFICE_FORM_DERIVED_KEYS: Record<OfficeFormCode, readonly string[]> = {
  ZONING: ['application_date', 'application_type', 'site_is_rented'],
  SANITARY: [
    'application_date',
    'application_type',
    'workers_requiring_health_certs',
    'employees_male',
    'employees_female',
    'employees_total',
    'total_floor_area_sqm',
  ],
  CEC: [
    'application_date',
    'application_type',
    'denr_reason',
    'denr_basis',
    'denr_certificate',
    'denr_permits',
    'denr_pco',
    'denr_remarks',
  ],
  FSIC: ['application_date', 'certificate_applied_for'],
  OCCUPANCY: ['application_date'],
}

/** Does the API write this answer itself, so nobody may correct it? */
function officeFormKeyIsDerived(code: string, key: string): boolean {
  return OFFICE_FORM_DERIVED_KEYS[code as OfficeFormCode]?.includes(key) ?? false
}

/** One answer the applicant submitted, presented as a record, never a control. */
/**
 * The officer's unsaved edits, and the rules they are held to.
 *
 * A context rather than props, for the same reason `FieldCorrections` is
 * one: the boxes are sixty deep in a tree that would otherwise carry a
 * setter through every container between here and them.
 *
 * ── Keyed by the payload path, not by the return target ─────────────────
 *
 * The obvious key was the `form:` code each box already declares for the
 * Return picker — nothing new to thread through sixty call sites. It is
 * wrong twice. SIX boxes share `form:address` (House/Bldg No., Street,
 * Block, Lot, Lot Area, Landmark), because a return points at "the
 * address" as one thing — so typing in Street would have written Block.
 * And a box DISPLAYS a formatted answer: item 1 shows "Sole
 * Proprietorship", item 14 "Male", item 17 "100%", none of which is what
 * the column holds.
 *
 * So the key is the path the API takes — `tin`, `address.street`,
 * `owner.gender` — and the buffer is the shape of the request, which
 * makes Save a fold rather than a translation. Null means the sheet is
 * not in edit mode and every box renders as a record.
 */
const FieldEdits = createContext<{
  values: Record<string, string>
  errors: Record<string, string>
  set: (key: string, value: string) => void
} | null>(null)

/** What a box offers when the sheet is being edited. */
type FieldControl =
  | { kind: 'text' }
  | { kind: 'radio'; options: { value: string; label: string }[] }
  | { kind: 'select'; options: { value: string; label: string }[] }

/** What a box hands `Field` to become editable. */
interface FieldEdit {
  /** The path the API takes, e.g. `tin` or `address.street`. */
  key: string
  /** The RAW answer, where the box displays a formatted one. */
  value?: string
  control?: FieldControl
}

/** Yes and No, as a pair of chips — the shape the form asks them in. */
const YES_NO: { value: string; label: string }[] = [
  { value: '1', label: 'Yes' },
  { value: '0', label: 'No' },
]

/** BPLO item B6's six, worded as the wizard words them. */
const ECONOMIC_ORGANIZATIONS: { value: string; label: string }[] = [
  { value: 'single_establishment', label: 'Single Establishment' },
  { value: 'branch', label: 'Branch' },
  { value: 'establishment_and_main_office', label: 'Establishment and Main Office' },
  { value: 'main_office_only', label: 'Main Office only' },
  { value: 'ancillary_unit', label: 'Ancillary Unit' },
  { value: 'others', label: 'Others' },
]

/**
 * Every answer an officer may correct, and the rule it is held to.
 *
 * A registry rather than a prop on each box, because the Save button has
 * to know whether the WHOLE buffer is valid while the boxes are scattered
 * through two thousand lines of sheet. One place to read, and one place a
 * reviewer can check against the API's rules.
 *
 * `rule` names the wizard's own check. That is the client's instruction of
 * 30 September 2026 — that these carry the validation of their original
 * counterparts — meant the only way it cannot drift: by calling the
 * function the form calls, not by copying what it does today.
 *
 * The rest carry `optional` and a length, which is what `validateBusiness`
 * asks of them. A key ABSENT from here is not editable at all.
 */
const EDIT_FIELDS: Record<
  string,
  { rule?: string; optional?: boolean; maxLength?: number; max?: number }
> = {
  /* Section A — business and registration. */
  registration_type: {},
  registration_number: { rule: 'form:registration_number' },
  tin: { rule: 'form:tin' },
  name: { rule: 'form:name' },
  trade_name: { rule: 'form:trade_name' },
  'address.telephone': { rule: 'form:telephone' },
  'address.mobile_number': { rule: 'form:mobile_number' },
  'address.email': { rule: 'form:email' },
  'address.website': { rule: 'form:website' },
  /*
   * Items 10 to 14. Optional at the API and optional here: the paper
   * marks none of them required, and a blank is the applicant having
   * cleared a prefill rather than an answer missing.
   */
  'owner.surname': { optional: true, maxLength: 100 },
  'owner.given_name': { optional: true, maxLength: 100 },
  'owner.middle_name': { optional: true, maxLength: 100 },
  'owner.suffix': { optional: true, maxLength: 20 },
  'owner.gender': { optional: true },
  president_officer_name: { rule: 'form:president_officer_name' },
  citizenship: { rule: 'form:citizenship' },
  capital_participation_filipino: { rule: 'form:capital_participation' },

  /* Section B — operation. */
  economic_organization: { optional: true },
  economic_organization_others: { optional: true, maxLength: 255 },
  capital_investment: { rule: 'form:capital_investment' },
  has_tax_incentives: {},
  is_rented: {},

  /* The premises. */
  'address.house_bldg_no': { optional: true, maxLength: 120 },
  'address.street': { maxLength: 255 },
  'address.block': { optional: true, maxLength: 40 },
  'address.lot': { optional: true, maxLength: 40 },
  'address.lot_area_sqm': { optional: true, max: 10000000 },
  'address.line2': { optional: true, maxLength: 255 },
  'address.barangay_id': {},
  emergency_contact_name: { optional: true, maxLength: 255 },
  emergency_contact_number: { optional: true, maxLength: 40 },
}

/**
 * What is wrong with this answer, in the words the applicant would see.
 *
 * Runs the wizard's rule where the field has one. The fallback is not a
 * pass: a field with no named rule is still held to required-ness and to
 * the column's length, which is what the API would refuse it for anyway —
 * better said at the box than as a 422 after the confirmation dialog.
 */
function editFieldError(key: string, value: string): string | undefined {
  const spec = EDIT_FIELDS[key]
  if (spec === undefined) return undefined
  if (spec.rule !== undefined) return scalarFieldRule(spec.rule).validate(value)

  const trimmed = value.trim()
  if (spec.optional !== true && trimmed === '') return 'This field is required.'
  if (spec.maxLength !== undefined && trimmed.length > spec.maxLength) {
    return `Keep this to ${spec.maxLength} characters or fewer.`
  }
  if (spec.max !== undefined && trimmed !== '') {
    const parsed = Number(trimmed)
    if (!Number.isFinite(parsed) || parsed < 0 || parsed > spec.max) {
      return 'Enter a number, and no more than the field allows.'
    }
  }

  return undefined
}

/**
 * What the form calls this field, for the confirmation dialog.
 *
 * Numbered as the paper numbers it. An officer about to overwrite a
 * citizen's declaration should read the list in the words the citizen was
 * asked in — `address.house_bldg_no` is a path, not a question.
 */
const EDIT_FIELD_LABELS: Record<string, string> = {
  registration_type: '1. Form of Organization',
  registration_number: '2. Registration Number',
  tin: '3. Tax Identification Number (TIN)',
  name: '4. Business Name',
  trade_name: '5. Trade Name / Franchise',
  'address.telephone': '6. Telephone (Landline)',
  'address.mobile_number': '7. Mobile Number',
  'address.email': '8. E-mail Address',
  'address.website': '9. Website Address',
  'owner.surname': '10. Surname',
  'owner.given_name': '11. Given Name',
  'owner.middle_name': '12. Middle Name',
  'owner.suffix': '13. Suffix',
  'owner.gender': '14. Gender',
  president_officer_name: '15. Name of President / Officer in Charge',
  citizenship: '16. Citizenship (of President/OIC)',
  capital_participation_filipino: '17. Capital Participation (% Filipino)',
  economic_organization: 'B5. Economic Organization',
  economic_organization_others: 'B5. Economic Organization — others',
  capital_investment: 'B6. Capital Investment',
  has_tax_incentives: 'B7. Tax Incentives from a Government Entity',
  is_rented: 'B8. Do you pay rent for occupying a place of business?',
  'address.house_bldg_no': 'House / Bldg No.',
  'address.street': 'Street',
  'address.block': 'Block',
  'address.lot': 'Lot',
  'address.lot_area_sqm': 'Lot Area (sq. m.)',
  'address.line2': 'Locational Group / Landmark',
  'address.barangay_id': 'Barangay',
  emergency_contact_name: 'Emergency Contact Person',
  emergency_contact_number: 'Emergency Contact Number',
}

/** The label, or the path itself rather than a blank if one is missed. */
function editFieldLabel(key: string): string {
  return EDIT_FIELD_LABELS[key] ?? key
}

/**
 * A buffered value as the officer should read it back.
 *
 * The buffer holds what the COLUMN holds — `sole_proprietorship`, `M`,
 * `1` — because that is what gets posted. A confirmation dialog that
 * printed those would be asking the officer to approve a change written
 * in the database's words rather than the form's.
 *
 * The barangay is the exception: its value is an id, and the name lives
 * in a list the page fetches, so the caller passes the lookup in.
 */
function editFieldDisplay(
  key: string,
  value: string,
  barangays: { id: number; name: string }[],
): string {
  if (value.trim() === '') return '(blank)'

  const from = (options: { value: string; label: string }[]) =>
    options.find((o) => o.value === value)?.label ?? value

  if (key === 'registration_type') return from(ORGANIZATION_FORMS)
  if (key === 'owner.gender') return from(GENDERS)
  if (key === 'economic_organization') return from(ECONOMIC_ORGANIZATIONS)
  if (key === 'has_tax_incentives' || key === 'is_rented') return from(YES_NO)
  if (key === 'address.barangay_id') {
    return barangays.find((b) => String(b.id) === value)?.name ?? value
  }

  return value
}

/**
 * One editable answer, in the shape the applicant answered it.
 *
 * The client, 30 September 2026: *"if radio buttons were used in the original,
 * the admin view should also see radio buttons."* A record sheet that turns
 * every question into a text box asks a different question from the form —
 * "Sole Proprietorship" typed into a free field is not the choice of four the
 * applicant was given, and it can be spelled wrong.
 *
 * The error comes from `scalarFieldRule`, which is the wizard's own rule for
 * this code, so a TIN is checked here exactly as it was checked when it was
 * first asked for. Shown under the control, as the form shows it.
 */
function FieldEditor({
  fieldKey,
  label,
  control,
  value,
  error,
  onChange,
}: {
  fieldKey: string
  label: string
  control: FieldControl
  value: string
  error?: string
  onChange: (value: string) => void
}) {
  const invalid = error !== undefined && error !== ''
  /*
   * The keyboard and the length the wizard gives this field, taken from
   * the same rule object the validation comes from. A TIN box that brings
   * up a letter keypad on a tablet is a different field from the one the
   * applicant filled in, whatever it validates to.
   */
  const rule = EDIT_FIELDS[fieldKey]?.rule
  const hints = rule === undefined ? undefined : scalarFieldRule(rule)

  if (control.kind === 'radio') {
    return (
      <>
        {/*
          The form's own chips, not a dropdown. A choice of four the applicant
          could see at once should not become a menu the officer has to open.
        */}
        <div role="radiogroup" aria-label={label} className="flex flex-wrap gap-2">
          {control.options.map((o) => {
            const selected = o.value === value

            return (
              <button
                key={o.value}
                type="button"
                role="radio"
                aria-checked={selected}
                onClick={() => onChange(o.value)}
                className={`inline-flex items-center gap-2 rounded-full border-2 px-3.5 py-1.5 text-sm font-semibold transition-colors ${
                  selected
                    ? 'border-royal bg-input text-ink'
                    : 'border-input-border bg-input/60 text-ink-secondary hover:bg-input'
                }`}
              >
                <span
                  aria-hidden="true"
                  className={`h-3 w-3 rounded-full border-2 ${
                    selected ? 'border-royal bg-royal' : 'border-input-border bg-white'
                  }`}
                />
                {o.label}
              </button>
            )
          })}
        </div>
        {invalid && <FieldEditorError id={fieldKey}>{error}</FieldEditorError>}
      </>
    )
  }

  if (control.kind === 'select') {
    return (
      <>
        <select
          value={value}
          aria-label={label}
          aria-invalid={invalid || undefined}
          aria-describedby={invalid ? `edit-error-${fieldKey}` : undefined}
          onChange={(e) => onChange(e.target.value)}
          className={`w-full rounded-lg border bg-input px-3.5 py-2 text-sm text-ink focus:outline-none ${
            invalid ? 'border-s-red' : 'border-input-border focus:border-royal'
          }`}
        >
          {/*
            A named empty row, and not for tidiness. The barangay list is
            fetched when Edit is switched on, so for a moment the select has
            no option matching the filing's own barangay — without a row to
            land on, the browser shows the FIRST barangay in the city as
            though it were the answer on the form.
          */}
          <option value="">— not selected —</option>
          {control.options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
        {invalid && <FieldEditorError id={fieldKey}>{error}</FieldEditorError>}
      </>
    )
  }

  return (
    <>
      <input
        value={value}
        aria-label={label}
        aria-invalid={invalid || undefined}
        aria-describedby={invalid ? `edit-error-${fieldKey}` : undefined}
        inputMode={hints?.inputMode}
        maxLength={hints?.maxLength ?? EDIT_FIELDS[fieldKey]?.maxLength}
        placeholder={hints?.placeholder}
        onChange={(e) => onChange(e.target.value)}
        className={`w-full rounded-lg border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none ${
          invalid ? 'border-s-red' : 'border-input-border focus:border-royal'
        }`}
      />
      {invalid && <FieldEditorError id={fieldKey}>{error}</FieldEditorError>}
    </>
  )
}

/** The rule's own words, under the control that broke it. */
function FieldEditorError({ id, children }: { id: string; children: ReactNode }) {
  return (
    <p id={`edit-error-${id}`} role="alert" className="mt-1 text-xs font-medium text-s-red">
      {children}
    </p>
  )
}
function Field({
  label,
  value,
  /*
   * The return-target code(s) this box shows, for fields an officer can send
   * back. Several where one box holds several answers — the owner's name is
   * four targets in one line — so a correction to any of them is reported
   * against the box the reader is actually looking at.
   *
   * Absent on the boxes that are not correctable, which is most of them.
   */
  targets,
  /*
   * Defaults to a PACKING rule, not a plain block.
   *
   * `grow basis-[15rem]` starts the box at the width a typical answer needs
   * and lets it expand into whatever the row has left, so three short
   * answers share a line and a long one takes the space it deserves. The
   * grid this replaced gave all of them an identical third.
   *
   * Overridden per field where the answer has a known shape — a gender or a
   * postal code should not be invited to grow to a third of the sheet.
   */
  className = 'grow basis-[15rem] max-w-full',
  /*
   * What this box becomes in Edit mode, and nothing when it is left out.
   *
   * Opt-in rather than derived from `targets`, because six boxes share
   * `form:address` and several display a formatted answer — see the note
   * on `FieldEdits`. The boxes that stay records are the ones the API has
   * no column for: the City, the Province, the Mode of Payment, the
   * amendment's own reference. Offering to edit those would be a promise
   * the Save cannot keep.
   */
  edit,
}: {
  label: string
  value: string
  targets?: string[]
  className?: string
  edit?: FieldEdit
}) {
  const corrections = useContext(FieldCorrections)
  const edits = useContext(FieldEdits)
  const editable = edits !== null && edit !== undefined

  /*
   * Flattened across the box's targets and re-sorted, so a name box corrected
   * at the surname and then at the suffix reads in one sequence rather than
   * in two blocks by target.
   */
  const history = (targets ?? [])
    .flatMap((t) => corrections.get(t) ?? [])
    .sort((a, b) => Date.parse(b.at ?? '') - Date.parse(a.at ?? ''))

  return (
    <dl className={`block ${className}`}>
      {/*
        A BLOCK with the badge inline, not a flex row. As a flex sibling the
        badge could not be flowed around, so "1. DTI / SEC / CDA
        Registration Number" broke across two lines to make room beside it.
        Inline, it simply follows the last word and wraps with it.

        Rose, because the palette already spends that hue on Returned —
        see index.css — and a correction is what answers a return. Royal is
        this app's ordinary interface blue and would have said nothing.
      */}
      <dt className="mb-1.5 block text-[13px] font-semibold text-ink">
        {label}
        {history.length > 0 && (
          <span
            title="The applicant changed this after your office returned the filing."
            className="ml-2 inline-block whitespace-nowrap rounded-full bg-s-rose px-2 py-0.5 align-middle text-[10px] font-bold uppercase tracking-wide text-white"
          >
            Corrected
          </span>
        )}
      </dt>
      {editable && edits !== null && edit !== undefined ? (
        <dd>
          <FieldEditor
            fieldKey={edit.key}
            label={label}
            control={edit.control ?? { kind: 'text' }}
            /*
             * The buffer first, then the RAW answer where the box was
             * given one, and only then the printed value. An input shown
             * "Sole Proprietorship" would post the label back.
             */
            value={edits.values[edit.key] ?? edit.value ?? value}
            error={edits.errors[edit.key]}
            onChange={(v) => edits.set(edit.key, v)}
          />
        </dd>
      ) : (
        <dd className={recordValue}>{value || '—'}</dd>
      )}
      {history.length > 0 && <FieldHistory history={history} label={label} />}
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
function RequirementsRead({
  code,
  rows,
  corrections = [],
}: {
  code?: string
  rows: OfficeFormRequirement[]
  /** What the applicant changed on the rows this office last returned. */
  corrections?: ClearanceCorrection[]
}) {
  const outstanding = rows.filter((r) => !r.satisfied).length

  /*
   * The newest change per row. The payload is newest-first, so the first
   * one seen for a code is the one that answers this office's last return;
   * anything older belongs to a round already settled.
   */
  const changed = new Map<string, ClearanceCorrection>()
  for (const c of corrections) {
    if (!changed.has(c.target)) changed.set(c.target, c)
  }

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
      {/*
        BPLO's Section C row, the component itself — not a copy of it.

        The client, 30 September 2026, comparing the two screens: *"Layout
        seems to be very different again with the BPLO admin. FIX THIS."*
        The previous attempt rewrote the markup by eye and drifted on the
        first edit. There is one row component now, so there is nothing left
        to drift.
      */}
      <ul className="mt-3 space-y-2.5">
        {rows.map((row) => {
          /*
            Every file on the row. `documents` is the list the API sends;
            `document` is its first, and the fallback keeps the sheet
            rendering against a payload from before 30 September 2026.
          */
          const files = row.documents ?? (row.document === null ? [] : [row.document])

          /*
            Whether this row moved since the office returned it.

            UNCHANGED is shown rather than hidden, and it is the case worth
            showing: the resubmit gate lets a file the office called wrong
            come back identical — refusing would trap an applicant whose
            document was right, since a returned clearance can only go back
            to For Approval and no office can wave one through — so this is
            how the office learns it, instead of opening the file again to
            find out.
          */
          const moved = row.code === null ? undefined : changed.get(row.code)
          const was = (moved?.old_value ?? '').trim()
          const now = (moved?.new_value ?? '').trim()
          const footer =
            moved === undefined ? undefined : was === now ? (
              <p className="text-xs font-semibold text-s-orange">
                Unchanged since you returned it
              </p>
            ) : (
              <p className="text-xs text-ink-secondary">
                <span className="font-semibold text-s-green">Changed</span> — was{' '}
                <span className="line-through">{was === '' ? 'nothing attached' : was}</span>
              </p>
            )

          /*
            Nothing attached. <DocumentRow> needs a document, and a checklist
            row without one still has to appear — that is what a checklist is
            for — so it gets the same shell with the meta line saying what is
            missing instead of a filename.
          */
          if (files.length === 0) {
            return (
              <li key={row.key} className="rounded-lg border border-line bg-white px-4 py-3">
                <div className="flex items-center gap-3">
                  <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-line bg-royal-tint">
                    <FileGlyph />
                  </span>
                  <div className="min-w-0">
                    <p className="truncate text-sm font-bold text-ink">{row.label}</p>
                    <p className="truncate text-xs text-ink-muted">
                      {row.reference
                        ? row.reference
                        : row.source === 'sheet'
                          ? 'This sheet. It counts as complete once the applicant submits it.'
                          : 'Not on file.'}
                    </p>
                  </div>
                </div>
                {footer !== undefined && (
                  <div className="mt-2.5 border-t border-line pt-2.5">{footer}</div>
                )}
              </li>
            )
          }

          /*
            Adapted into the shape <DocumentRow> reads. A checklist file
            carries `filename` and `uploaded_at` where an application document
            carries `original_filename` and `created_at`; the requirement's
            own label stands in for the document type's name, which is what
            the row is called on this sheet.

            Newest first, as the API sends them, so the head of the list is
            the current copy and the rest fold away behind it — the same
            treatment Section C gives a requirement answered more than once.
          */
          const [current, ...earlier] = files
          const asDocument = (file: (typeof files)[number]): AppDocument => ({
            id: file.id,
            document_type: { id: 0, code: row.code ?? row.key, name: row.label },
            original_filename: file.filename,
            size_bytes: file.size_bytes ?? 0,
            created_at: file.uploaded_at ?? '',
            download_url: '',
          })

          return (
            <DocumentRow
              key={row.key}
              group={{
                code: row.code ?? row.key,
                current: asDocument(current),
                earlier: earlier.map(asDocument),
                /*
                  Section C badges a copy that arrived after the filing was
                  returned. The office sheet says the same thing in its own
                  footer, with the before-and-after the correction records —
                  so badging it here as well would say it twice.
                */
                resubmitted: false,
              }}
              footer={footer}
            />
          )
        })}
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
/** One requirement: the copy that counts, and whatever came before it. */
type RequirementGroup = {
  code: string
  current: AppDocument
  /** Newest first. Kept on the filing, folded away in the sheet. */
  earlier: AppDocument[]
  /** Did the current copy arrive after this filing was last handed back? */
  resubmitted: boolean
}

/**
 * One row of Section C — a requirement, not a file.
 *
 * ── Why one row and not one per upload ──────────────────────────────────────
 *
 * `documents.upload` APPENDS, so a requirement the applicant answered twice
 * has two files against it. Listing both put three rows reading "Proof of
 * Business Registration" on the client's sheet, and Section C is a checklist:
 * an officer should be able to count it. The current copy is the row; the rest
 * are history and sit behind a fold.
 *
 * Keeping them is deliberate — see the note at the head of this patch. The
 * refused copy is the evidence of what was refused, and a remark that points
 * at a deleted file cannot be checked by anybody.
 */
function DocumentRow({
  group,
  footer,
}: {
  group: RequirementGroup
  /*
   * An extra line under the row. The office sheet puts "Changed — was…"
   * here on a requirement it asked about; BPLO passes nothing and the row
   * renders exactly as it did before.
   */
  footer?: ReactNode
}) {
  const { current, earlier, resubmitted } = group
  const [showEarlier, setShowEarlier] = useState(false)

  /*
   * A button with aria-expanded/aria-controls, never <details>. Same reason
   * the "show the application as filed" disclosure gives further down: a
   * passing test asserts this page has no <details>, and a button is the only
   * one of the two whose open state React controls.
   */
  const earlierId = `requirement-history-${current.id}`

  return (
    <li className="rounded-lg border border-line bg-white px-4 py-3">
      <div className="flex items-center justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-line bg-royal-tint">
            <FileGlyph />
          </span>
          <div className="min-w-0">
            <p className="flex items-center gap-2">
              <span className="truncate text-sm font-bold text-ink">
                {current.document_type.name}
              </span>
              {/*
                The one thing the officer came back to look at, so it is the
                one thing badged. It is also said on a requirement whose ONLY
                copy arrived after the return — an applicant can answer a
                return about something they had never uploaded before.
              */}
              {resubmitted && (
                <span
                  title="Sent after this filing was returned — this is the copy answering it."
                  className="shrink-0 rounded-full bg-s-rose px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white"
                >
                  Re-uploaded
                </span>
              )}
            </p>
            <p className="truncate text-xs text-ink-muted">
              {current.original_filename} · {formatBytes(current.size_bytes)}
              {/* The date earns its place once there is more than one copy. */}
              {(earlier.length > 0 || resubmitted) && (
                <> · {formatVersionDate(current.created_at)}</>
              )}
            </p>
          </div>
        </div>
        <DocumentActions
          id={current.id}
          filename={current.original_filename}
          label={current.document_type.name}
        />
      </div>

      {earlier.length > 0 && (
        <div className="mt-2.5 border-t border-line pt-2.5">
          <button
            type="button"
            onClick={() => setShowEarlier((open) => !open)}
            aria-expanded={showEarlier}
            aria-controls={earlierId}
            aria-label={`${showEarlier ? 'Hide' : 'Show'} the ${earlier.length} earlier ${
              earlier.length === 1 ? 'copy' : 'copies'
            } of ${current.document_type.name}`}
            className="flex items-center gap-1.5 rounded text-xs font-semibold text-royal hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
          >
            <span
              className={`shrink-0 transition-transform ${showEarlier ? 'rotate-180' : ''}`}
              aria-hidden="true"
            >
              <ChevronDownIcon size={14} />
            </span>
            {/*
              The requirement's name is in `aria-label`, not on screen. A
              screen reader hearing "2 earlier copies" on six rows cannot
              tell them apart; a sighted reader has the row's own heading
              directly above and does not need it said twice.
            */}
            {earlier.length} earlier {earlier.length === 1 ? 'copy' : 'copies'}
          </button>
          {/*
            `hidden` rather than unmounted: `aria-controls` must point at an
            element that exists, and `hidden` takes it out of the
            accessibility tree and out of find-in-page, so a closed fold is
            genuinely closed and not merely out of sight.
          */}
          <ul id={earlierId} hidden={!showEarlier} className="mt-2 space-y-1.5">
            {earlier.map((doc) => (
              <li
                key={doc.id}
                className="flex items-center justify-between gap-3 rounded-md bg-canvas px-3 py-2"
              >
                <p className="min-w-0 truncate text-xs text-ink-secondary">
                  {doc.original_filename} · {formatBytes(doc.size_bytes)} ·{' '}
                  {formatVersionDate(doc.created_at)}
                </p>
                <DocumentActions
                  id={doc.id}
                  filename={doc.original_filename}
                  label={`${doc.document_type.name} (earlier copy)`}
                />
              </li>
            ))}
          </ul>
        </div>
      )}

      {footer !== undefined && (
        <div className="mt-2.5 border-t border-line pt-2.5">{footer}</div>
      )}
    </li>
  )
}

/** Floating white remark bubble (p56/p71). */
function RemarkBubble({
  author,
  remark,
  items = [],
}: {
  author: string
  remark: string
  /**
   * The named rows and what was said about each, when the return had
   * them. Empty for a return written as plain prose, which falls back to
   * `remark` — the composed sentence built for the places that carry one.
   */
  items?: { label: string; note: string }[]
}) {
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
      {/*
        One box per field, because three returned rows are three things to
        deal with. Run together as "A: …; B: …; C: …" they read as one
        paragraph and the officer has to parse the semicolons to count
        what they asked for.
      */}
      {items.length > 0 ? (
        <ul className="mt-2.5 space-y-2">
          {items.map((it) => (
            <li
              key={it.label}
              className="rounded-lg border-l-4 border-s-rose bg-input px-3.5 py-2"
            >
              <p className="text-sm font-semibold text-ink">{it.label}</p>
              <p className="mt-0.5 text-sm italic text-ink-secondary">“{it.note}”</p>
            </li>
          ))}
        </ul>
      ) : (
        <p className="mt-2.5 rounded-lg bg-input px-3.5 py-2 text-sm text-ink">{remark}</p>
      )}
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
  /*
   * The office's own refusal — between the other two in severity, and the
   * help text is where that is said. It names the consequence the officer is
   * about to cause to a certificate the applicant is already holding, which
   * is not something to discover from the notification they receive.
   */
  reject_permit: {
    heading: 'Reject this permit',
    label: 'Why this permit cannot be granted',
    help: 'Final for this permit — use Return instead if the applicant can fix it. This suspends their '
      +'Business Permit until they apply again and your office approves it. They see this reason.',
    confirm: 'Reject permit',
    confirmCls: 'bg-s-red hover:brightness-110',
  },
  return: {
    heading: 'Return to the applicant',
    label: 'What the applicant must fix',
    /*
     * This help only ever renders under the WHOLE-FILING box — the
     * per-field boxes carry their own placeholder — so it describes that
     * case rather than returns in general, which is what made it read as
     * advice about the fields listed above it.
     */
    help: 'No field is ticked, so the applicant reopens the whole form and sees this on their Track page.',
    confirm: 'Return application',
    confirmCls: 'bg-royal hover:bg-royal-hover',
  },
  /*
   * Changing an instruction already given, which is not a return: the
   * filing is with the applicant and stays there, nothing transitions,
   * and the history does not record a second round. Reusing Return's
   * words put "Return application" on a button that returns nothing.
   */
  amend: {
    heading: 'Change what you asked for',
    label: 'What the applicant must fix',
    help: 'No field is ticked, so the applicant reopens the whole form and sees this on their Track page.',
    confirm: 'Save changes',
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
  initialPicked = [],
  initialNotes = {},
  submitting,
  error,
  onCancel,
  onConfirm,
  chrome = 'panel',
}: {
  action: 'reject' | 'reject_permit' | 'return' | 'amend'
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
  targets: { value: string; label: string; group?: string }[]
  /**
   * The codes to open with ticked, and what was said about each.
   *
   * Empty for a fresh Return. Filled when AMENDING, because the officer is
   * editing an instruction rather than writing one: the pointer is
   * replaced wholesale on every write, so an officer who opened a blank
   * list to add one field would silently drop the other two.
   */
  initialPicked?: string[]
  initialNotes?: Record<string, string>
  submitting: boolean
  error: string | null
  onCancel: () => void
  /*
   * `remedy` is only ever non-empty for a permit refusal. It is a third
   * argument rather than a second popup because it is the same act: the
   * officer is writing one decision, in two halves, and splitting the
   * screen would let them send the verdict without the route out of it.
   */
  onConfirm: (
    text: string,
    target: string | null,
    remedy: string,
    /** One remark per ticked field, keyed by its `form:` code. */
    notes: Record<string, string>,
  ) => void
  /**
   * Where this is drawn.
   *
   * `panel` is the floating composer in the aside — the original, still used
   * by Reject and Reject this permit. `modal` puts the same body inside
   * `ProtoModal`, which traps focus and closes on Escape, and lets that
   * component own the heading and the two buttons instead of drawing a
   * second set inside its own frame.
   */
  chrome?: 'panel' | 'modal'
}) {
  const [text, setText] = useState(initialText)
  /*
   * The ticked codes, in the order the list offers them rather than the
   * order they were clicked — so the stored pointer reads down the form the
   * way the applicant will meet it, and two officers ticking the same three
   * fields store the same string.
   */
  /*
   * Seeded once, from the return being amended. `useState`'s initialiser
   * rather than an effect: the composer is mounted fresh each time it
   * opens, so there is nothing to re-sync and an effect would only add a
   * way for the officer's own edits to be overwritten under them.
   *
   * Filtered against `targets`, so a pointer naming a field this sheet no
   * longer offers — a requirement retired since the return — does not tick
   * a box that is not there and cannot be unticked.
   */
  const [picked, setPicked] = useState<string[]>(() =>
    targets.map((t) => t.value).filter((v) => initialPicked.includes(v)),
  )
  const togglePicked = (value: string) =>
    setPicked((prev) =>
      prev.includes(value)
        ? prev.filter((v) => v !== value)
        : targets.map((t) => t.value).filter((v) => v === value || prev.includes(v)),
    )
  /*
   * What is wrong with each ticked field, keyed by its code. Kept for a
   * field that is later UNTICKED rather than deleted, so an officer who
   * unticks by accident and ticks again gets their sentence back; only the
   * currently picked codes are ever read or sent.
   */
  const [notes, setNotes] = useState<Record<string, string>>(initialNotes)
  const [remedy, setRemedy] = useState('')
  /*
   * A refusal costs the applicant their business permit, so it may not be
   * sent without saying what would settle it. Every other decision on this
   * screen leaves this empty and is unaffected.
   */
  const needsRemedy = action === 'reject_permit'
  const copy = REMARK_COPY[action]
  /*
   * A return that names fields is made of its per-field notes, so EVERY
   * ticked field needs one — a field ticked and left blank tells the
   * applicant to fix something without saying what is wrong with it, which
   * is the state this whole feature exists to remove.
   */
  const perField = picked.length > 0
  const missingNote = perField && picked.some((code) => (notes[code] ?? '').trim() === '')
  const empty = perField ? missingNote : !text.trim()
  const blocked = empty || (needsRemedy && remedy.trim() === '')
  /*
   * Comma-joined into the one `remarks_target` column. `ReturnTargets` on
   * the API reads it back the same way, and a single code — every return
   * written before today — is a valid list of one.
   */
  const send = () => {
    /*
     * The composed sentence, for the places that carry ONE remark: the
     * assignment row, the status-history note and the applicant's
     * notification. The per-field notes travel separately and are what the
     * applicant actually reads beside each box.
     */
    const composed = picked
      .map((code) => {
        const label = targets.find((t) => t.value === code)?.label ?? code

        return `${label}: ${(notes[code] ?? '').trim()}`
      })
      .join('; ')

    onConfirm(
      perField ? composed : text.trim(),
      perField ? picked.join(',') : null,
      remedy.trim(),
      perField ? Object.fromEntries(picked.map((c) => [c, (notes[c] ?? '').trim()])) : {},
    )
  }
  /*
   * The heading and the officer's name, drawn only in the panel. In a modal
   * `ProtoModal` prints the heading in its own bar, and repeating it here
   * would give the dialog two titles.
   */
  const body = (
    <>
      {chrome === 'panel' && (
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
      )}
      {/*
        Above the box, because it is the smaller decision and answering it first
        makes the prose easier to write — "what is this about" then "what is
        wrong with it". Optional, and labelled so: a required picker would turn
        free text into a form, which is the opposite of what was asked for.
      */}
      {targets.length > 0 && (
        <fieldset className="mt-3 block">
          <legend className="text-xs font-bold text-ink">
            Which fields must they correct?{' '}
            {/*
              "(optional)" alone did not say what skipping it DOES, so an
              officer met two boxes and had to work out which one the
              filing would travel on. The alternative is named instead.
            */}
            <span className="font-normal text-ink-muted">
              — or leave empty to send the whole form back
            </span>
          </legend>
          {/*
            A scrolling box of checkboxes rather than `<select multiple>`, which
            drops the whole selection on a stray plain click — see the note at
            the top of this patch. Capped in height because thirty options would
            otherwise push the remark box and the buttons off the screen.
          */}
          <div className="mt-1.5 max-h-56 overflow-y-auto rounded-lg border border-input-border bg-input px-3 py-2">
            {Object.entries(
              targets.reduce<Record<string, typeof targets>>((acc, t) => {
                const key = t.group ?? ''
                ;(acc[key] ??= []).push(t)

                return acc
              }, {}),
            ).map(([group, rows]) => (
              <div key={group} className="mb-2 last:mb-0">
                {/*
                  The wizard's own section names. An officer looking for "the
                  barangay" should not read past seventeen registration
                  questions to find out whether it is in the list.
                */}
                {group !== '' && (
                  <p className="mb-1 text-[11px] font-bold uppercase tracking-wide text-ink-muted">
                    {group}
                  </p>
                )}
                {rows.map((t) => (
                  <div key={t.value}>
                    <label className="flex cursor-pointer items-start gap-2 rounded px-1 py-1 text-sm text-ink hover:bg-royal-tint">
                      <input
                        type="checkbox"
                        checked={picked.includes(t.value)}
                        onChange={() => togglePicked(t.value)}
                        className="mt-0.5 shrink-0"
                      />
                      <span>{t.label}</span>
                    </label>
                    {/*
                      Its own instruction, indented under the field it is
                      about. Only once ticked — thirty always-visible boxes
                      would bury the list they belong to.
                    */}
                    {picked.includes(t.value) && (
                      <textarea
                        value={notes[t.value] ?? ''}
                        onChange={(e) =>
                          setNotes((prev) => ({ ...prev, [t.value]: e.target.value }))
                        }
                        placeholder="What is wrong with this field?"
                        rows={2}
                        maxLength={1000}
                        aria-label={`What is wrong with ${t.label}`}
                        className="mb-1 ml-6 w-[calc(100%-1.5rem)] rounded-lg border border-input-border bg-white px-2.5 py-1.5 text-xs text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
                      />
                    )}
                  </div>
                ))}
              </div>
            ))}
          </div>
          <span className="mt-1 block text-xs text-ink-secondary">
            {picked.length === 0
              ? 'Tick nothing and the applicant reopens the whole form. Tick fields and they are sent back just those, each with its own remark.'
              : `${picked.length} field${picked.length === 1 ? '' : 's'} selected — write what is wrong with each.`}
          </span>
        </fieldset>
      )}
      {/*
        The single box, for a return that names NO fields — the plain prose
        return that existed before the picker, and still the right shape for
        "the whole thing needs another look".

        Hidden once fields are ticked. Two boxes meaning almost the same
        thing would give the officer a choice nobody can make correctly and
        the applicant two places to read one instruction from — see the note
        on `send`, which composes the assignment's single remark out of the
        per-field ones so the timeline and the notification still read.
      */}
      {!perField && (
        <>
          <label className="mt-3 block">
            <span className="text-xs font-bold text-ink">
              {/*
                Named for the shape it belongs to. On a RETURN this box only
                draws when no field is ticked, so it is the whole-filing
                remark — and calling it "What the applicant must fix" beside
                a list of fields made it read as the fields' own box.

                Reject and Reject this permit keep their own wording: they
                have no picker above them and nothing to be confused with.
              */}
              {action === 'return' ? 'What is wrong with the whole filing' : copy.label}{' '}
              <span className="text-s-red">*</span>
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
        </>
      )}
      {/*
        ── What would settle it, asked separately from what is wrong ────────

        Two boxes rather than one, because they are two different sentences
        and an officer given one box writes only the first. "No potable water
        connection" is a verdict; "connect to mains or file a deep-well
        permit, then apply again" is a route, and the applicant is holding a
        suspended business permit until they can follow one.

        It also does work on the OTHER side of the loop. The sheet reopens
        with every answer still in it, so an applicant can resubmit unchanged
        — this is the line that tells them what has to be different.
      */}
      {needsRemedy && (
        <label className="mt-3 block">
          <span className="text-xs font-bold uppercase tracking-wide text-ink-muted">
            What would settle it
          </span>
          <textarea
            value={remedy}
            onChange={(e) => setRemedy(e.target.value)}
            placeholder="e.g. Connect to the mains supply, or file a deep-well permit, then apply again."
            rows={2}
            required
            aria-describedby="remark-remedy-help"
            className="mt-1.5 w-full rounded-lg border border-input-border bg-input px-3.5 py-2.5 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
          />
          <p id="remark-remedy-help" className="mt-1 text-xs text-ink-secondary">
            The applicant sees this on the form when they apply again, and their Business
            Permit stays suspended until your office approves it.
          </p>
        </label>
      )}
      {error && <p className="mt-1.5 text-xs font-medium text-s-red">{error}</p>}
      {/*
       * Why the button is off, said out loud. A disabled control with no reason
       * beside it is the officer's problem to solve by guessing.
       */}
      {blocked && (
        <p
          id={`remark-blocked-${action}`}
          aria-live="polite"
          className="mt-1.5 text-xs font-medium text-ink-muted"
        >
          {empty
            ? 'Write the reason to continue.'
            : 'Say what would settle it to continue.'}
        </p>
      )}
      {chrome === 'panel' && (
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
            onClick={send}
            disabled={submitting || blocked}
            className={`rounded-md px-4 py-1.5 text-sm font-semibold text-white underline underline-offset-2 disabled:opacity-60 ${copy.confirmCls}`}
          >
            {submitting ? 'Working…' : copy.confirm}
          </button>
        </div>
      )}
    </>
  )

  if (chrome === 'modal') {
    return (
      <ProtoModal
        title={copy.heading}
        tone={action === 'return' ? 'blue' : 'red'}
        confirmLabel={submitting ? 'Working…' : copy.confirm}
        onCancel={onCancel}
        onConfirm={send}
        confirmDisabled={submitting || blocked}
        /*
         * The line that says WHY Confirm will not let them out yet. The body
         * already prints it for the panel; pointing the dialog's own button at
         * it means a screen-reader user hears the reason from the control they
         * are stuck on rather than having to go looking for it.
         */
        confirmDescribedBy={blocked ? `remark-blocked-${action}` : undefined}
      >
        {body}
      </ProtoModal>
    )
  }

  return <div className="rounded-xl bg-white p-4 shadow-overlay">{body}</div>
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

/**
 * One office-form answer as display text, with the key whose stored value is
 * not what an officer should read.
 *
 * `certified` holds the string 'yes', which under a label reading
 * "Certification" told the officer nothing about what had been certified.
 * The applicant ticked a sentence; this is that sentence's outcome.
 */
function officeFormValueText(key: string, value: unknown): string {
  if (key === 'certified') {
    return value === 'yes' ? 'Certified correct by the applicant' : 'Not certified'
  }

  return formValueText(value)
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
   * Item B6 is NOT put here, and that is the fix for a duplicate the client
   * found on 27 September 2026.
   *
   * `profile.capitalization` is the fee engine's working copy of the same
   * fact the business record holds as `capital_investment`, and the sheet
   * rendered BOTH under "6. Capital Investment" — two boxes, one fact,
   * inviting an officer to reconcile a figure with itself. The business
   * column is the paper's box and is drawn in the Business Operation block;
   * see the Field there.
   *
   * An earlier note here warned about a DIFFERENT duplicate — the per-line
   * capitalization the wizard dropped on 16 September 2026 — which is why
   * this one survived: the comment read as though the problem was already
   * handled.
   */
  put('Construction Cost', money(profile.construction_cost))
  put(
    '1. Business Area (sq. m.)',
    profile.floor_area_sqm == null ? null : `${profile.floor_area_sqm} sqm`,
  )
  put('2. Total No. of Employees', count(profile.employees))
  /*
   * The male/female split, printed beside the total it divides (BPLO item B2 on
   * the new form, B3 on the renewal, and CENRO's own MALE/FEMALE box). `count`
   * keeps a declared zero — "0 female employees" is an answer, and `put` would
   * drop the string "0" as falsy if this were formatted any other way.
   */
  put('2. No. of Employees — Male', count(profile.male_employees))
  put('2. No. of Employees — Female', count(profile.female_employees))
  /*
   * Item B3, and it was missing outright. The column has been filled since the
   * wizard started asking, and the figure is not decoration: the Revenue Code
   * reads it, and it is the one employee count an officer could plausibly
   * query against the barangay.
   */
  put('3. No. of Employees Residing within Malabon', count(profile.employees_in_lgu))
  put('Storeys', count(profile.storeys))
  put('Doors', count(profile.doors))
  put('Rooms', count(profile.rooms))
  put('Beds', count(profile.beds))
  put('Market Stalls', count(profile.stall_count))
  put('4. No. of Delivery Units — Motorized', count(profile.delivery_vehicles_motorized))
  put('4. No. of Delivery Units — Other', count(profile.delivery_vehicles_other))
  /*
   * Business Structure is NOT put here — it is item 10 wearing another name.
   *
   * Client, 27 September 2026: *"Why is Business Structure stated here again.
   * Check ALL FIELDS and AVOID REPETITION OF RECORDS."* `fee_profile
   * .business_structure` is MIRRORED from `businesses.registration_type` —
   * the wizard copies one into the other and compares them for equality
   * (ApplyWizard: `d.business_structure === form.registration_type`) — so the
   * sheet printed "Sole Proprietorship" under Business Operation and again
   * under "10. Form of Organization" in Business Information.
   *
   * Item 10 is the paper's box and keeps it. The mirror exists for the fee
   * engine, which needs the structure without loading the business; that is a
   * reason for the COLUMN to exist, not for the sheet to show it twice.
   */
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

  /*
   * ── The officer's unsaved edits ──────────────────────────────────────
   *
   * Held here and written nowhere until Save, which is the client's
   * instruction and the right shape for the act: the applicant's wizard
   * autosaves because losing a draft keystroke costs nothing, while an
   * officer rewriting a submitted declaration is making a record, and a
   * record is made on purpose.
   *
   * Keyed by the path the API takes — `tin`, `address.street`,
   * `owner.gender` — see the note on `FieldEdits` for why not by the
   * return target each box already declares.
   *
   * Up here with `mode` rather than down beside `editing`, which reads
   * better and is illegal: that line is past this component's loading
   * and error returns, so these hooks would be skipped on the render
   * where the filing has not arrived yet.
   */
  const [fieldEdits, setFieldEdits] = useState<Record<string, string>>({})
  /*
   * ── The office's own sheet, corrected in Edit mode ──────────────────────
   *
   * Client, 4 October 2026: *"edit mode for the admin side still does not
   * work. I can't edit fields."* It worked exactly as designed — Edit mode
   * turned on the For Office Use fields and nothing the applicant had
   * written — and the design was the complaint. The decision taken: an
   * office may correct the answers on the ONE sheet it issues the permit
   * for, and the server records each change by key, before and after
   * (`OfficeFormController::upsert`, `office_form.corrected_by_office`).
   *
   * Its own buffer and its own Save, not folded into `fieldEdits`: that one
   * writes the business record through `applications.updateFields`, this
   * writes a sheet through `officeForms.save`, and one button that did two
   * different writes could half-succeed. Keyed by sheet, because BPLO's
   * sheet may be beside the office's own on the same page.
   */
  const [sheetEdits, setSheetEdits] = useState<Record<string, Record<string, string>>>({})
  const [sheetSaving, setSheetSaving] = useState<string | null>(null)
  const [sheetSaveError, setSheetSaveError] = useState<string | null>(null)
  const editSheet = (code: string, key: string, value: string) =>
    setSheetEdits((prev) => ({ ...prev, [code]: { ...(prev[code] ?? {}), [key]: value } }))
  async function saveSheet(code: string, saved: Record<string, unknown>) {
    const edits = sheetEdits[code]
    if (!edits || sheetSaving !== null) return
    setSheetSaving(code)
    setSheetSaveError(null)
    try {
      await officeFormsApi.save(app.id, code, { ...saved, ...edits })
      setSheetEdits((prev) => {
        const next = { ...prev }
        delete next[code]
        return next
      })
      reload()
    } catch (err) {
      setSheetSaveError(toApiError(err).message)
    } finally {
      setSheetSaving(null)
    }
  }
  const [savingFields, setSavingFields] = useState(false)
  const [fieldSaveError, setFieldSaveError] = useState<string | null>(null)
  const [confirmFieldSave, setConfirmFieldSave] = useState(false)

  /*
   * The barangay list, for the one answer that is a choice from the
   * city's own table rather than a value typed in. Fetched only once the
   * officer switches to Edit: every other reviewer opening this page
   * would otherwise pay for a reference call nothing draws.
   */
  const editMode = mode === 'edit'
  const barangaysRef = useAsync(
    () => (editMode ? reference.barangays() : Promise.resolve([])),
    [editMode],
  )

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

  /*
   * Three decisions now, not two. `reject` ends the whole FILING and is
   * BPLO's; `reject_permit` refuses THIS OFFICE'S permit and is every other
   * office's; `return` asks for a correction and is everybody's. See
   * `sendRemark`, which dispatches on this and nothing else.
   */
  const [popup, setPopup] = useState<
    'reject' | 'reject_permit' | 'return' | 'amend' | null
  >(null)
  /*
   * Separate from `popup`, which selects between the two REMARK composers
   * and carries a textarea with it. Approve asks a yes/no question and
   * collects nothing, so folding it into that union would give the
   * composer a third mode that renders none of its own fields.
   */
  const [confirmingApprove, setConfirmingApprove] = useState(false)
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
      Back to Manage Applications
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
              ? 'The link points at a review that has been completed, reassigned, or removed. Open it again from Manage Applications.'
              : 'Checking your queue for the matching review.'}
          </p>
          {strayId === 'unresolved' && (
            <Link
              to="/staff/queue"
              className="mt-4 inline-flex rounded-md bg-royal px-5 py-2 text-sm font-semibold text-white hover:bg-royal-hover"
            >
              Go to Manage Applications
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
   * Reported 16 September 2026 against a filing at For Approval:
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
   * answers, and at For Approval every one of them is a seed: the
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
   * `permits.viewPdf` fetches the blob and points a tab at it, which is what
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
      await permits.viewPdf(certificate.id, tab)
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
   *  - An AMENDMENT does, since 28 September 2026. It is BPLO's own review,
   *    so the second rule above would have kept it open — but the premise of
   *    that rule is that reading the form IS the act, and here it is not.
   *    The act is reading three or four "now X, asked for Y" rows against
   *    the affidavit and the supporting documents. The unchanged fifty
   *    answers are context exactly as they are for a clearance office, and
   *    printing them above the decision buries the rows the decision is
   *    about. Client: *"is it still good to show ALL BUSINESS DETAILS even
   *    though this is just for amendment?"*
   *
   * The Tax Order of Payment follows the same line, which is why it is one
   * constant: where the application is folded, the assessment is a second bar
   * beside it; where it is open, the assessment sits in FOR OFFICE USE ONLY
   * where the paper puts it.
   */
  const foldsApplication =
    foldsFiledSheet || bploFinalApproval || app.application_type === 'amendment'

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

  /**
   * Section C, as things BPLO can send the filing back about.
   *
   * From what the applicant ACTUALLY UPLOADED — `app.documents` — not from
   * the permit type's requirement list. Client, 29 September 2026: *"only put
   * there what is submitted by the applicant ... if the applicant did not
   * submit their TIN, how come can the admin return the TIN?"*
   *
   * The first attempt listed every requirement on the permit type, which
   * offered a new filing's officer a renewal's VAT returns. Filtering that
   * list by its conditional `context` tokens would have worked and would have
   * meant a second copy of the wizard's evaluator; reading the uploads needs
   * no rules at all, and answers the stricter question the client asked.
   *
   * Bare `document_types.code`, not a `form:` code: `remarks_target` already
   * carries document codes for the office sheets, and `targetsInclude`
   * already matches on them.
   *
   * Deduplicated by code: Other Requirements is repeatable, so one document
   * type can hold several files and must appear once.
   */
  const documentTargets = [
    ...new Map(
      app.documents.map((d) => [
        d.document_type.code,
        {
          value: d.document_type.code,
          label: d.document_type.name,
          group: 'C · Documentary Requirements',
        },
      ]),
    ).values(),
  ]

  const returnTargets = [
    ...ownOfficeForms.flatMap((form) => {
      const meta = officeFormMeta(form.permit_type_code)
      // The paper's own two halves, named as the paper names them.
      const answerGroup = meta?.title ?? 'This office’s form'
      const documentGroup =
        form.permit_type_code === 'CEC'
          ? 'Requirements for Application'
          : 'Checklist of Requirements'

      return [
        /*
         * The answers first, because that is the order the paper asks —
         * the questions, then the checklist stapled behind them.
         */
        ...Object.keys(form.form_data ?? {})
          /*
           * Not every key is a question. `authorized_representative_source`
           * records which control the name came from, and the client read
           * it straight off this list as "Authorized Representative
           * Source" — an office being offered the chance to return a
           * filing about a field the applicant has never seen.
           */
          .filter((key) => !OFFICE_FORM_INTERNAL_KEYS.includes(key))
          /*
           * Nor is an answer the API writes itself (`OfficeFormAnswers::derive`).
           * The applicant cannot change it, so returning the filing over one
           * asks for a fix nobody can make. 5 October 2026.
           */
          .filter((key) => !officeFormKeyIsDerived(form.permit_type_code, key))
          .sort(
            (a, b) =>
              officeFormFieldRank(form.permit_type_code, a) -
              officeFormFieldRank(form.permit_type_code, b),
          )
          .map((key) => ({
            value: key,
            /*
             * The paper's wording, not the key's. `humanizeKey` gave
             * "Total Floor Area Sqm" for a box CPDD prints as "Floor Area
             * to be Utilized (sq. m.)", so an officer reading down the
             * form could not find the row they wanted to tick.
             */
            label: officeFormFieldLabel(form.permit_type_code, key),
            group: answerGroup,
          })),
        /*
         * Every documentary row, carried ones included.
         *
         * This asked for `source === 'upload'`, which left the TCT, the
         * DTI/SEC certificate and the location sketch off the list —
         * correctly at the time, since a carried row had no slot and
         * nothing to send back to. They have one as of 30 September 2026,
         * so an office can ask for a better copy of any of them.
         *
         * The `sheet` row is still excluded: it IS the form, and "return
         * the form" is what ticking nothing already means.
         */
        ...(form.requirements ?? [])
          .filter((row) => row.source !== 'sheet' && row.code !== null)
          .map((row) => ({
            value: row.code as string,
            label: row.label,
            group: documentGroup,
          })),
      ]
    }),
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
    /*
     * ── BPLO's targets before payment are the FORM's own fields ────────────
     *
     * Client, 24 September 2026: *"allow me to choose a field that the
     * business owner will have to comply to. Then, I should also put a reason
     * why."*
     *
     * Until now this whole list was empty in BPLO's seat on a new filing:
     * `ownOfficeForms` is empty because BPLO has no sheet of its own, and the
     * clearance branch above only fires on a renewal at Final Approval. So the
     * one office that returns the MAIN FORM — the fifty-question one — was the
     * only one that could not say which part of it was wrong.
     *
     * Offered whenever BPLO's return would go to `returnMainForm`, which is
     * every BPLO return except the renewal-clearance case above. The two lists
     * can appear together at Final Approval on a renewal, and should: BPLO is
     * reading five certificates AND the form behind them, and either can be
     * the thing that is wrong.
     */
    /*
     * Only the fields the applicant ANSWERED — `answered_targets` from the
     * API. A field they left blank was never their answer to correct, and a
     * missing TIN is chased by its own requirement at approval rather than by
     * returning the whole filing. Client, 29 September 2026: *"if the
     * applicant did not submit their TIN, how come can the admin return the
     * TIN?"*
     *
     * Sections are kept whatever the payload says: they are steps rather than
     * single values, and `answered_targets` only speaks for scalars.
     */
    ...(canReject
      ? MAIN_FORM_RETURN_TARGETS.filter(
          (t) => t.kind === 'section' || (app.answered_targets ?? []).includes(t.value),
        )
      : []),
    /*
     * Section C, one row per requirement THIS filing was asked for — see
     * `documentTargets`. Offered alongside the form fields and on the same
     * condition: it is BPLO reading Section C, and the five offices read
     * their own sheets' checklists instead (the first block above).
     */
    ...(canReject ? documentTargets : []),
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
  /*
   * ── A bill with no business tax on it, said out loud ────────────────────
   *
   * Found on 1 October 2026 by filing a renewal through the API and watching
   * it to the end: with no Section B answers the engine had no gross receipts
   * to assess, so the Tax Order of Payment was three fixed charges — filing
   * fee, plates, sticker, ₱375 — and no business tax at all. It was paid and
   * a permit was issued. The same filing with Section B answered is ₱19,125.
   *
   * A SUBMIT GATE was tried first and reverted the same day: requiring the
   * profile before submission broke 277 tests across twelve files, because
   * fixtures that exercise office scoping, assignments and messages submit
   * filings without ever walking the tax step, and they are right not to.
   *
   * So it is told to the person who can act on it instead. BPLO reads this
   * sheet before the first approval, which is the moment the bill becomes
   * payable, and Return is already the remedy. The wizard always sends
   * Section B, so a filing without it arrived another way and is worth a
   * human look rather than an automatic refusal.
   *
   * Keyed on the ABSENT TAX LINE, not on the absent profile: what matters is
   * the bill that resulted, and a profile that produced no tax for some other
   * reason is just as worth seeing.
   */
  const hasBusinessTax = (app.fee_assessment?.line_items ?? []).some((item) =>
    /tax/i.test(item.label ?? ''),
  )

  const taxOrderBlock =
    (app.fee_assessment?.line_items?.length ?? 0) > 0 ? (
      <div className="mt-6 rounded-lg border border-line bg-white px-5 py-5">
        <p className="text-[11px] font-bold uppercase tracking-wide text-royal">
          Tax Order of Payment
        </p>
        {!hasBusinessTax && (
          <p
            role="alert"
            className="mt-3 rounded-md border border-s-orange bg-s-orange-tint px-4 py-2.5 text-sm font-semibold text-s-orange-ink"
          >
            No business tax on this assessment. The filing carries no Business &amp; Tax
            Profile, so only the fixed charges were computed — return it for Section B
            before approving.
          </p>
        )}
        <div className="mt-4">
          <TaxOrderBreakdown fee={app.fee_assessment} showCitations />
        </div>
        <div className="mt-4 flex items-baseline justify-between border-t border-ink/40 pt-3 text-base font-bold text-ink">
          <span>Total Amount</span>
          <span className="tnum">{formatMoney(app.fee_assessment?.total_amount)}</span>
        </div>
      </div>
    ) : null

  /**
   * The Hide/Show bar for the Tax Order of Payment, for whichever seat.
   *
   * A function rather than two copies of the markup: the offices got this
   * on 17 September 2026 and BPLO on the 27th, and the one thing that must
   * not happen is the two drifting apart again.
   *
   * `buttonCls` is the only difference between them, and it is cosmetic —
   * BPLO's copy sits inside FOR OFFICE USE ONLY and takes that panel's
   * border so it does not read as a foreign card dropped into it.
   */
  const taxOrderFold = (buttonCls: string) =>
    taxOrderBlock && (
      <>
        <div className="mt-4">
          <button
            type="button"
            onClick={() => setTaxOpen((open) => !open)}
            aria-expanded={taxOpen}
            aria-controls="tax-order-of-payment"
            className={buttonCls}
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
   * "The other offices' form answers" went with Section D on 17 September
   * 2026 (issue #95). It was conditional for a while, because the server had
   * already filtered that section down to nothing for a clearance office and
   * promising it to a sanitary officer would have advertised a section that
   * opens empty. Nothing carries it now, for any reader, and a summary naming
   * a section the sheet no longer has would read as a leak to a client who
   * has already reported one here twice.
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
  /**
   * When this filing was last handed back to the applicant, in epoch ms.
   *
   * The LATER of two, because two different offices hand it back and each
   * records it somewhere else: BPLO returns the FILING, which moves the
   * application's status and lands in `status_history`; one of the five
   * offices returns its own PERMIT, which never touches that status and is
   * stamped on the pivot as `clearance.returned_at`. Reading one alone
   * would leave the other office's sheet unable to mark anything.
   *
   * Null on a filing that has never been back, which is most of them — and
   * then nothing is a re-upload, which is correct rather than unknown.
   */
  const lastHandback = (() => {
    const moments = [
      ...(app.status_history ?? [])
        .filter((h) => h.to_status === 'returned' && h.created_at)
        .map((h) => Date.parse(h.created_at)),
      ...(data.clearance?.returned_at ? [Date.parse(data.clearance.returned_at)] : []),
    ].filter((t) => !Number.isNaN(t))

    return moments.length > 0 ? Math.max(...moments) : null
  })()

  /*
   * ── Newest first WITHIN each requirement, and marked ─────────────────
   *
   * `documents.upload` appends rather than replaces, so a requirement the
   * applicant answered twice has two rows with the same name. This sorted
   * on `requirementRank` alone until 29 September 2026, which orders the
   * requirements against each other and says nothing about copies of one:
   * they came out in payload order, and the officer opening the first of
   * three found whichever the database happened to return — quite possibly
   * the copy their own office had just refused.
   *
   * `id` breaks a tie on `created_at`, which two uploads in the same second
   * will give. Ids ascend, so the higher one is the later.
   */
  const askedFor = app.documents
    .filter((d) => requirementRank.has(d.document_type.code))
    .sort((a, b) => {
      const byRequirement =
        (requirementRank.get(a.document_type.code) ?? 0) -
        (requirementRank.get(b.document_type.code) ?? 0)
      if (byRequirement !== 0) return byRequirement

      const byDate = Date.parse(b.created_at) - Date.parse(a.created_at)

      return Number.isNaN(byDate) || byDate === 0 ? b.id - a.id : byDate
    })

  /*
   * ── One group per requirement, newest copy first ─────────────────────────
   *
   * `askedFor` is already sorted requirement-then-newest, so the first file
   * seen for a code IS its current copy and the rest are its history, in
   * order. Built as groups rather than marked rows because Section C is a
   * checklist: three rows named "Proof of Business Registration" cannot be
   * counted, however they are badged.
   */
  const requirementGroups = askedFor.reduce<RequirementGroup[]>((groups, doc) => {
    const code = doc.document_type.code
    const existing = groups.find((g) => g.code === code)

    if (existing) {
      existing.earlier.push(doc)

      return groups
    }

    groups.push({
      code,
      current: doc,
      earlier: [],
      /*
       * Said of the CURRENT copy only. An earlier copy that also postdates
       * the return is still an earlier copy — the officer is being pointed
       * at the one answer they have to read, not at everything recent.
       */
      resubmitted: lastHandback !== null && Date.parse(doc.created_at) > lastHandback,
    })

    return groups
  }, [])

  const feeProfile = app.fee_profile ?? null
  const feeFacts = feeProfile ? feeProfileFacts(feeProfile) : []

  /**
   * Section B's facts in the order MCG-BPLO-FO-001 prints them.
   *
   * `feeProfileFacts` builds in the order the FEE ENGINE cares about, which
   * is not the paper's — so the sheet read 6, 1, 2, 2, 3 before this, and
   * the client reported it. Sorted on the number the label already carries
   * rather than on a second list of positions, which would be one more thing
   * to keep in step with the labels.
   *
   * Unnumbered facts — Gross Sales, Business Structure, Storeys, the three
   * location questions — keep their original order and follow the numbered
   * ones. They are not part of B1-B8, and slotting them between items would
   * break the ascent the numbers exist to provide.
   */
  const orderedFeeFacts = [...feeFacts].sort((a, b) => {
    const numberOf = (label: string) => {
      const m = /^(\d+)\./.exec(label)

      return m ? Number(m[1]) : Number.POSITIVE_INFINITY
    }

    return numberOf(a.label) - numberOf(b.label)
  })
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

  /*
   * ── Somebody else's case is read-only, and the sheet has to say so ────────
   *
   * The Officer-in-Charge rule is enforced on the server: approve, return,
   * checks and classify are all refused to anyone but the holder. This screen
   * did not know about it. An officer opening a colleague's filing was handed
   * the full sheet — Approve, Return to applicant, Reject, the compliance
   * checklist, the RA 11032 category picker — and every one of them answered
   * 403 on press. A control that exists only to refuse is worse than no
   * control: it reads as the product being broken rather than as the case
   * being somebody else's.
   *
   * `can_act` is the server's own answer, not an id comparison repeated here,
   * and it is true on an UNHELD case too — acting on one claims it, which is
   * the rule AssignmentController::authorizeHolder applies. So the sheet stays
   * fully workable for a case nobody has taken, exactly as before.
   *
   * Undefined means an older payload; treated as allowed, because refusing
   * every officer on a stale response would be a worse failure than the one
   * this prevents.
   */
  const heldByAnother = data.can_act === false
  const holderName = data.officer?.name ?? null

  /*
   * What is still outstanding on a paid filing that has not qualified for final
   * approval, as a phrase, or null when nothing is.
   *
   * Both halves come from the payload — the clearances from `permit_types`, the
   * requirements from the count the API now sends — so the sentence cannot
   * drift from the rule `refreshReadiness` applies. `open_requirements` is
   * optional on the wire; an older payload omits the clause rather than
   * claiming zero.
   *
   * ── Who is told, and about which filings ──────────────────────────────
   *
   * Narrowed twice on 30 September 2026, both times because the banner was
   * describing an act that was not going to happen.
   *
   * It was gated on the filing's status alone, so the five clearance
   * offices were shown it as well — told that a decision which is not
   * theirs to make is not ready, on a sheet where they have work of their
   * own still open. The client reported it from the zoning seat.
   *
   * And on a NEW application nobody signs at the end at all: the Business
   * Permit is released the moment the last clearance lands. The clearances
   * block further down says the same thing in its own note. So the banner
   * was naming a step that does not exist and then reporting that it had
   * not been reached.
   *
   * What is left is the case it was written for: BPLO, on a renewal,
   * looking at a filing that has stopped moving and wanting to know why
   * there is no Approve button.
   */
  const notReadyToSign = (() => {
    if (! isGatheringOtherPermits(app)) return null
    // An office's sheet folds the filed application away; BPLO's does not.
    // That is the nearest thing this screen has to "am I BPLO", and it is
    // already the flag the rest of the sheet branches on.
    if (foldsFiledSheet) return null
    if (app.application_type !== 'renewal') return null

    const permits = otherPermitProgress(app.permit_types)
    const openPermits = permits.total - permits.approved
    const openRequirements = app.open_requirements ?? 0

    const parts: string[] = []
    if (openPermits > 0) {
      parts.push(`${openPermits} of ${permits.total} other permit${permits.total === 1 ? '' : 's'} still open`)
    }
    if (openRequirements > 0) {
      parts.push(`${openRequirements} Other Requirement${openRequirements === 1 ? '' : 's'} still open`)
    }

    // A filing at this status with nothing outstanding is a state readiness
    // would have moved on; saying "waiting on nothing" would be worse than
    // saying nothing, so the banner stays away.
    return parts.length > 0 ? parts.join(' and ') : null
  })()

  // A decided review is a record for good: there is nothing left to change.
  const editing = mode === 'edit' && !decided && !heldByAnother

  /*
   * The applicant's own rule for each edited field, run as it is typed.
   * `editFieldError` reaches the wizard's `scalarFieldRule` where the
   * field has one, so a TIN is refused here exactly as it was refused
   * when it was first asked for — the client's instruction that these
   * carry the rules of their counterparts.
   */
  const fieldEditErrors: Record<string, string> = {}
  for (const [key, value] of Object.entries(fieldEdits)) {
    const failed = editFieldError(key, value)
    if (failed !== undefined) fieldEditErrors[key] = failed
  }
  const fieldEditsDirty = Object.keys(fieldEdits).length > 0
  /** Anything typed in Edit mode and not yet written, in either buffer. */
  const unsavedEdits = fieldEditsDirty || Object.keys(sheetEdits).length > 0
  const fieldEditsValid = Object.keys(fieldEditErrors).length === 0

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
  /*
   * ── The officer no longer picks the RA 11032 category ────────────────
   *
   * Client, 27 September 2026: *"It is either we remove the selection or
   * not."* Removed. Malabon publishes the classification in its Citizen's
   * Charter — new and renewal business permits are Simple — so there is one
   * right answer per transaction type and `Ra11032::tierFor()` now returns
   * it. A per-filing picker could only ever let two officers put different
   * statutory deadlines on identical applications.
   *
   * A constant rather than deleting the six blocks behind it. The classify
   * ENDPOINT is deliberately still there and still tested: if BPLO comes
   * back and says a later edition of the charter reclassifies something, or
   * that they want an override after all, this is one word. Ripping a
   * hundred lines of JSX out of a twelve-thousand-line file on my own
   * reading of a PDF is the less reversible choice.
   */
  const canSetTier = false
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
  /*
   * Never missing now: the tier is read from the charter at submission and
   * nobody has to confirm it, so the amber banner and the disabled Approve
   * it drove are both gone. See `canSetTier` directly above.
   */
  const categoryMissing = false

  /**
   * Who set the tier this filing currently carries — the sentence that makes
   * the control safe to hand an officer.
   *
   * Null unless an officer set it, since 24 September 2026. The two branches
   * that stood here for an automatic or an absent category both said the
   * filing could not be approved until somebody confirmed one — which is
   * true, and is already said twice in amber above the fold whenever it
   * applies: `#approve-blocked-why`, which the Approve button itself points
   * at, and the `categoryMissing && canSetTier` block that carries a picker.
   * A third copy at the foot of the sheet was the client's example of a
   * description doing no work.
   *
   * What is left is not a description. WHO chose the tier and WHEN appears
   * nowhere else on the sheet, and it is what makes a category somebody else
   * set safe to rely on.
   */
  const tierProvenance =
    ra?.source === 'officer'
      ? `Category set by ${ra.set_by?.name ?? 'a reviewing officer'}${
          ra.set_at ? ` on ${formatDate(ra.set_at)}` : ''
        }.`
      : null

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
   * The stage it was describing is now an undecided `approved`, and the shape
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
   *
   * ── The exception is now BPLO at EVERY stage, not only the last (#99) ─────
   *
   * "The whole initial-approval form should stay visible to BPLO; hide it only
   * from the other five offices." That sentence is this branch, read as an
   * asymmetry: the five are the ones the box was built for — they asked for it
   * twice, "why is the entire application form showing it should just be like
   * the other ones where its just a box" and then "I can still see the
   * application details. Please remove this" — and BPLO is the one seat that
   * never asked, because coordinating is reading.
   *
   * It was keyed on `for_final_approval` alone, which left BPLO a hole exactly
   * one stage wide. `approveMainForm` completes BPLO's assignment at initial
   * approval, so from the moment BPLO approves until the last clearance lands,
   * the filing sits at an undecided `approved` with `owesReview` false — and
   * BPLO, the office that signed the form and is fielding the applicant's
   * questions about it, could not open the form it had signed. That is
   * checklist item 8 as the office admin experiences it, "the application
   * disappears from the admin's view", and it is the half of #99 that was
   * missing rather than the half that was working.
   *
   * Nothing is handed back except the READING. `decided` is still true for BPLO
   * while the filing gathers, so the sheet opens in view mode with its
   * decision already recorded and no Approve — the controls are settled by
   * `decided` and `canAct`, which have not moved, and the API is unchanged
   * either way.
   */
  const bploCoordinatesThroughout = data.department.code === 'BPLO'

  /*
   * ── This office's own permit is accepted but not yet granted ────────────
   *
   * The third way in, and the one a clearance-only renewal needed. Client,
   * 4 October 2026, on a Sanitary renewal sitting at its site visit: *"This
   * should NOT BE APPROVED. IT IS STILL FOR INSPECTION"* — and then the
   * remedy, which is the right one: *"why not just make it similar to the
   * New Permit filing view where the admin can Approve or Reject, and even
   * Set Schedule For Inspection."*
   *
   * The two filings were reaching different screens from the same situation,
   * and the reason was that the test above asks the FILING's status. A new
   * filing is `approved` and gathering by the time its office accepts the
   * paperwork, so it took this branch and got the inspection panel — the only
   * place in the product that draws Set Schedule for Inspection. A renewal
   * carrying one clearance never leaves `for_approval`: its office's work IS
   * the filing, so there is no gathering stage for it to be in. It fell
   * through to the full review sheet, which has no inspection panel at all
   * and stamps a green "Approved" the moment the assignment closes — over a
   * progress rail reading For Inspection, two inches below.
   *
   * So the question is asked of the PERMIT instead, which is what both cases
   * actually have in common: this office has accepted the paperwork
   * (`!owesReview`) and its permit has not been granted or refused yet.
   * `data.clearance` is the office's own permit on this filing, matched
   * server-side on `issuing_department_id` — see the note on
   * `bookFirstInspection` for why nothing else here may be used for it.
   */
  const myPermitInFlight =
    data.clearance !== null &&
    data.clearance.status !== null &&
    !['approved', 'rejected'].includes(data.clearance.status)

  const nothingLeftForThisOffice =
    (isGatheringOtherPermits(app) || app.status === 'for_final_approval' || myPermitInFlight) &&
    !owesReview &&
    !bploCoordinatesThroughout

  if (nothingLeftForThisOffice) {
    return (
      <div>
        {backLink}
        {/*
          Named, because this screen is not only the Business Permit's any
          more. It reads "Business Permit" on BPLO's seat and whenever the
          office's own permit cannot be named, and the permit's own name
          everywhere else — a Sanitary officer sent here by a clearance-only
          renewal was being shown a heading about a permit their office does
          not issue.
        */}
        <PageTitle>{data.clearance?.name ?? 'Business Permit'}</PageTitle>

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
   * payload and whether the PUT is allowed.
   *
   * Since issue #95 that list is the reader's OWN sheets, which costs one thing
   * and it is named here rather than discovered: BPLO no longer gets this panel
   * on OCCUPANCY, so it cannot type "Building Permit Date Issued" on OBO's
   * behalf. The server would still take the write — `view_any_office` has not
   * moved — so this is the screen declining to offer one office another
   * office's paperwork, not a refusal. OBO gets the panel on its own sheet,
   * which is whose date it is. Put it back by restoring the sheet, above.
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
  async function sendRemark(
    text: string,
    target: string | null = null,
    remedy = '',
    /** One remark per ticked field, keyed by its `form:` code. */
    notes: Record<string, string> = {},
  ) {
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
      /*
       * The office's own refusal, and the middle of the three in severity:
       * it does not end the filing, and it does suspend the business permit
       * the applicant is already holding. No `target` — see the client.
       */
      /*
       * Refusing names its rows too, since 30 September 2026. The applicant
       * reads a refusal in the same dialog they read a return in, so the
       * more serious decision stops being the vaguer one.
       */
      else if (popup === 'reject_permit')
        await assignments.reject(assignmentId, text, remedy, target, notes)
      /*
       * Amending is the same composer against a different endpoint. The
       * filing is already with the applicant, so this replaces what was
       * asked for instead of sending it back a second time — which is not
       * legal and should not be, since a repair may be under way.
       */
      else if (popup === 'amend')
        await assignments.amendReturn(assignmentId, text, target, notes)
      else await assignments.return(assignmentId, text, target, notes)
      setPopup(null)
      reload()
    } catch (err) {
      setActionError(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  /*
   * Send the whole business, not a patch.
   *
   * The endpoint runs the APPLICANT's validator, which asks for the
   * required fields together — a name without an address is not a valid
   * business however few boxes the officer touched. So the current record
   * goes up with the edits laid over it.
   */
  /*
   * Send the WHOLE business, not a patch.
   *
   * The endpoint runs the applicant's own validator, which asks for the
   * required fields together — a name with no barangay is not a valid
   * business however few boxes the officer touched. So the record as it
   * stands goes up with the buffer laid over it.
   *
   * That cuts both ways and the second edge is the dangerous one: the
   * writer treats an ABSENT key as a cleared answer for most columns, so
   * anything omitted here is destroyed rather than left alone. The map pin
   * is the sharpest case — `syncAddressAndLines` defaults latitude and
   * longitude to null — and CPDD rules the locational clearance off it. So
   * the fields nobody edits are restated too, explicitly, below.
   */
  async function saveFields() {
    if (!fieldEditsValid || !fieldEditsDirty) return
    setSavingFields(true)
    setFieldSaveError(null)
    try {
      /** The buffer if the officer touched it, else the record. */
      const at = (key: string, current: string | number | null | undefined): string =>
        fieldEdits[key] ?? (current == null ? '' : String(current))
      /** A blank box is a cleared answer, and the column is nullable. */
      const blank = (value: string): string | null =>
        value.trim() === '' ? null : value.trim()
      /** Yes/No chips hold '1' and '0'; the column holds a boolean. */
      const flag = (key: string, current: boolean | null | undefined): boolean =>
        key in fieldEdits ? fieldEdits[key] === '1' : current === true

      const addr = business.address
      const organization = at('economic_organization', business.economic_organization)

      await applications.updateFields(app.id, {
        name: at('name', business.name),
        trade_name: blank(at('trade_name', business.trade_name)),
        registration_type: blank(at('registration_type', business.registration_type)),
        registration_number: blank(at('registration_number', business.registration_number)),
        tin: blank(at('tin', business.tin)),
        president_officer_name: blank(
          at('president_officer_name', business.president_officer_name),
        ),
        citizenship: blank(at('citizenship', business.citizenship)),
        capital_participation_filipino: blank(
          at('capital_participation_filipino', business.capital_participation_filipino),
        ),
        economic_organization: blank(organization),
        /* Only meaningful under "Others"; cleared with the choice. */
        economic_organization_others:
          organization === 'others'
            ? blank(
                at('economic_organization_others', business.economic_organization_others),
              )
            : null,
        capital_investment: blank(at('capital_investment', business.capital_investment)),
        has_tax_incentives: flag('has_tax_incentives', business.has_tax_incentives),
        is_rented: flag('is_rented', business.is_rented),
        emergency_contact_name: blank(
          at('emergency_contact_name', business.emergency_contact_name),
        ),
        emergency_contact_number: blank(
          at('emergency_contact_number', business.emergency_contact_number),
        ),
        /*
         * Restated, not edited. The wizard stopped asking for the lessor
         * on 16 September 2026 and the zoning sheet still collects it, so
         * omitting these would blank an answer another office wrote.
         */
        lessor_name: business.lessor_name ?? null,
        lessor_address: business.lessor_address ?? null,
        lessor_contact: business.lessor_contact ?? null,
        monthly_rental: business.monthly_rental ?? null,
        owner: {
          surname: blank(at('owner.surname', business.owner?.surname)),
          given_name: blank(at('owner.given_name', business.owner?.given_name)),
          middle_name: blank(at('owner.middle_name', business.owner?.middle_name)),
          suffix: blank(at('owner.suffix', business.owner?.suffix)),
          gender: blank(at('owner.gender', business.owner?.gender)),
        },
        address: {
          house_bldg_no: blank(at('address.house_bldg_no', addr?.house_bldg_no)),
          /*
           * `street` is `sometimes|required`, so a filing made before the
           * House/Street split — which carries the whole address in
           * `line1` and nothing in `street` — must not send the key at
           * all, or the validator refuses a filing for a box the officer
           * never saw. When it is sent, `line1` is recomposed from it.
           */
          ...(blank(at('address.street', addr?.street)) === null
            ? { line1: addr?.line1 ?? null }
            : { street: at('address.street', addr?.street).trim() }),
          line2: blank(at('address.line2', addr?.line2)),
          block: blank(at('address.block', addr?.block)),
          lot: blank(at('address.lot', addr?.lot)),
          lot_area_sqm: blank(at('address.lot_area_sqm', addr?.lot_area_sqm)),
          barangay_id: Number(at('address.barangay_id', addr?.barangay?.id)) || null,
          telephone: blank(at('address.telephone', addr?.telephone)),
          mobile_number: blank(at('address.mobile_number', addr?.mobile_number)),
          email: blank(at('address.email', addr?.email)),
          website: blank(at('address.website', addr?.website)),
          postal_code: addr?.postal_code ?? null,
          /* The pin CPDD rules the clearance from. Dropping it wipes it. */
          latitude: addr?.latitude ?? null,
          longitude: addr?.longitude ?? null,
        },
        /*
         * Restated unchanged. The lines are a table with its own editor on
         * the applicant's side, and the writer REPLACES them wholesale —
         * so they have to go up even though no box here touches them.
         */
        lines: (business.lines ?? []).map((l) => ({
          psic_code_id: l.psic_code?.id ?? null,
          capitalization: l.capitalization,
          line_of_business: l.line_of_business ?? null,
          products_services: l.products_services ?? null,
        })),
      })

      setFieldEdits({})
      setConfirmFieldSave(false)
      reload()
    } catch (err) {
      setFieldSaveError(toApiError(err).message)
    } finally {
      setSavingFields(false)
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
  /*
   * Nine words, because that is all the officer has to DO something with:
   * they cannot edit, and Return is the way. The reasoning — RA 10173, the
   * signature on the sheet, the API-level split — is in the comment above
   * this, where somebody questioning the lock will look for it, rather
   * than on screen above every filing.
   */
  /*
   * Rewritten 5 October 2026. "The applicant's answers are locked" stopped
   * being true on 1 October, when Edit mode began correcting the filing's
   * own answers (a968ca5), and on 4 October the office's own sheet joined
   * them. A tester typed into Street under that banner, saw no Save near the
   * box, and reloaded to find it gone. Neither path autosaves — the client's
   * instruction — so what the officer needs to know is where the Save is.
   */
  const lockedNote = 'Changed answers are kept only when you press Save.'

  /*
   * The one genuinely good sentence in the old copy, kept: an office's own
   * refusal is not the same act as ending the filing for everybody. Six of the
   * eight staff roles cannot reject at all, and they should learn that here
   * rather than by hunting for a button that was never drawn for them.
   */
  /*
   * Three sentences were two until 24 September 2026, when an office gained
   * a refusal of its own. The old line told a clearance office that
   * "returning is how your office refuses this filing", which is now the
   * wrong advice for the case that matters — a return asks for a fix, and an
   * office that cannot grant the permit at all needs the other button.
   *
   * What each costs is stated, because that is the whole basis for choosing
   * between them and an officer should not have to learn it by pressing one.
   */
  /*
   * ── A refusal is only available after the visit ─────────────────────────
   *
   * `ClearanceStatus::allowedNext` permits Rejected from ForInspection and
   * nowhere else, and `rejectClearance` refuses it again with a message. This
   * is the third and friendliest guard: the button is simply not drawn where
   * pressing it could not work.
   *
   * It is also what answers the client's question about Reject and Return
   * looking alike. They are never on screen at the same time now — Return
   * belongs to the stage where an officer is reading paperwork, Reject to the
   * stage after somebody has been to look — so there is no moment where an
   * officer picks between two similar red buttons.
   */
  /**
   * Is this sitting with the APPLICANT rather than with an office?
   *
   * A return hands the work back. Until they resubmit there is nothing for
   * any office to decide, and every decision control is withheld — see the
   * note at the head of this patch for the three that were not.
   *
   * BPLO reads the FILING's status and a clearance office reads its own
   * CLEARANCE's, because that is the object each seat decides about: an
   * office whose clearance went back is waiting even while the filing
   * itself carries on.
   */
  /**
   * Days a returned filing has sat since the office handed it back.
   *
   * Read from the status history rather than `updated_at`, which moves
   * whenever anything touches the row — an analytics refresh would reset the
   * clock and the filing would never look abandoned. The last transition INTO
   * `returned` is the moment the applicant was handed the work, which is the
   * only date this question is about.
   *
   * Null when the filing is not returned, or when the history does not carry
   * it. Null withholds Reject, which is the safe direction: the cost of
   * withholding is queue clutter, the cost of offering it wrongly is someone's
   * application.
   */
  const daysSinceReturned = (() => {
    if (app.status !== 'returned') return null

    const last = [...(app.status_history ?? [])]
      .filter((h) => h.to_status === 'returned' && h.created_at)
      .pop()
    if (!last?.created_at) return null

    return (Date.now() - Date.parse(last.created_at)) / 86_400_000
  })()

  /**
   * Untouched long enough to treat as abandoned.
   *
   * Thirty days, and deliberately NOT the RA 11032 deadline — that clock
   * measures the office and is three working days under Malabon's charter.
   * Borrowing it would tie the applicant's patience to a figure that exists to
   * limit the city's.
   *
   * A wait rather than an automatic close, because of who pays when the rule
   * is wrong: a lingering filing costs the office some clutter it can see, an
   * auto-close costs the applicant their application and they may not find out
   * until they are at the counter.
   */
  const RETURN_ABANDONED_DAYS = 30
  const returnAbandoned =
    daysSinceReturned !== null && daysSinceReturned >= RETURN_ABANDONED_DAYS

  const withApplicant = canReject
    ? app.status === 'returned'
    : data.clearance?.status === 'returned'

  /**
   * May this seat end the whole filing?
   *
   * Not while BPLO is merely READING it (`for_approval`) — the client's
   * rule of 27 September — and not while it is with the applicant. The
   * first version of this said `status !== 'for_approval'` alone, which
   * excluded one status where it meant to describe a stage, and so put a
   * Reject button on a filing the applicant was still correcting.
   *
   * The API is deliberately unchanged. `rejectApplication` still accepts a
   * For Approval filing, because this is a rule about what BPLO is OFFERED
   * while reading, not a new invariant — and tightening that service without
   * cause broke a dozen legitimate callers once already.
   */
  const mayRejectFiling = canReject && !withApplicant && app.status !== 'for_approval'

  const mayRefusePermit = !canReject && data.clearance?.status === 'for_inspection'
  /**
   * May this seat send the permit back?
   *
   * The reading stage, and only it. `ClearanceStatus::allowedNext` permits
   * Returned from ForApproval and nowhere else, so once a visit is booked
   * the office's two answers are approve or refuse — and drawing a third
   * that the service refuses is the fault this fixes.
   *
   * BPLO always may. Its Return sends back the whole form, or one uploaded
   * clearance at Final Approval; neither is a `ClearanceStatus` move.
   */
  const mayReturn = !withApplicant && (canReject || data.clearance?.status === 'for_approval')
  /*
   * May this office change what it already asked for?
   *
   * Only while the thing it returned is still returned — which is exactly
   * when Return itself is withheld. `amendReturn` refuses anything else
   * server-side, so this is the screen agreeing with the rule rather than
   * inventing one: offering a button that answers 422 is the shape this
   * page has been bitten by before.
   */
  /*
   * What this office last asked for, for the amend composer to open on.
   *
   * BPLO's pointer is on its assignment and its notes on the filing; an
   * office's are on its own permit row, because one filing carries six
   * permits and each office's question is about its own.
   */
  const openReturnTargets = (
    canReject ? (data.remarks_target ?? '') : (data.clearance?.return_target ?? '')
  )
    .split(',')
    .map((t) => t.trim())
    .filter((t) => t !== '')
  /* The whole-filing sentence this office last wrote, for the same reason. */
  const openReturnRemark = canReject ? data.remarks : (data.clearance?.return_remark ?? null)
  const openReturnNotes = canReject
    ? (app.return_notes ?? {})
    : (data.clearance?.return_notes ?? {})

  const mayAmendReturn = canReject
    ? app.status === 'returned'
    /*
     * Returned OR refused. A refusal is the one that most needs correcting:
     * it suspends the Business Permit while it stands, so an officer who
     * ticked the wrong row is holding a trading business shut over a
     * mistake. `amendClearanceReturn` accepts both, so this agrees with it
     * rather than offering a button that answers 422.
     */
    : data.clearance?.status === 'returned' || data.clearance?.status === 'rejected'

  /**
   * The one thing this seat's buttons cannot say about themselves.
   *
   * Four seats, one short sentence each, and an empty string where the
   * controls already speak for themselves. Every branch used to carry two
   * or three sentences; what is kept from each is its CONSEQUENCE, because
   * that is the part an officer cannot read off a button.
   *
   * BPLO and the five offices share this page and do NOT share these
   * sentences — see `canReject` for the split.
   */
  const decisionNote = withApplicant
    ? /*
       * Two situations wearing one status. An empty button row needs a
       * reason or it reads as the page failing to load its controls — and a
       * row that has just grown a Reject button needs one more, because the
       * officer last saw this filing without it.
       */
      returnAbandoned
      ? `Returned ${Math.floor(daysSinceReturned ?? 0)} days ago and not resubmitted. `
        + 'Reject is available again so an abandoned filing can be closed.'
      : 'This filing is with the applicant until they resubmit, so there is nothing to decide yet.'
    : canReject
    ? // BPLO reading a filing: Reject is not drawn, and Return now explains
      // itself in the composer. Nothing left worth a banner.
      (mayRejectFiling ? 'Rejecting ends the filing for every office.' : '')
    : mayRefusePermit
      ? 'Rejecting this permit suspends their Business Permit until they apply again.'
      : 'A permit can only be refused after its inspection.'

  /*
   * Named, and no claim about WHERE beyond what is true: most of these live in
   * For Office Use Only, but Assign officer-in-charge is its own panel below
   * it. The anchor after this sentence is what answers "where", so the sentence
   * does not have to guess.
   */
  /*
   * A count, not a list. The fields are on the page under their own
   * heading, and the link at the end of the banner goes straight to them —
   * so naming all four here was a table of contents for one section.
   */
  const fieldsNote =
    liveFields.length === 1
      ? 'Edit mode. Your office fills in one field.'
      : `Edit mode. Your office fills in ${liveFields.length} fields.`

  const modeNote = decided
    ? 'This review is closed. The page is a record of the application and the decision made on it.'
    : editing
      /*
       * Joined on a filter, so the one seat whose `decisionNote` is empty —
       * BPLO reading a filing — does not get a trailing space inside the
       * banner.
       */
      ? [fieldsNote, lockedNote, decisionNote].filter(Boolean).join(' ')
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
   * it belongs — the summary no longer names other offices' answers at all,
   * Section D having been removed outright (#95) — rather than in a caption
   * describing a rule the screen keeps somewhere else.
   */

  /*
   * What each named row was told, keyed by its code.
   *
   * Both sources, because this sheet shows both kinds of return: BPLO's
   * notes hang off the filing, an office's off its own permit row.
   */
  const remarkNotes: Record<string, string> = {
    ...(app.return_notes ?? {}),
    ...(data.clearance?.return_notes ?? {}),
  }

  /*
   * The composed sentence broken back into its parts.
   *
   * Read from the pointer rather than split on "; " — an officer writing
   * a semicolon inside a note is ordinary, and splitting would quietly
   * turn one remark into two. Returns nothing unless EVERY code resolves,
   * so a partial list never replaces a complete sentence.
   */
  const remarkItems = (target: string | null): { label: string; note: string }[] => {
    const codes = (target ?? '')
      .split(',')
      .map((c) => c.trim())
      .filter((c) => c !== '')
    if (codes.length === 0) return []

    const items = codes
      .filter((c) => (remarkNotes[c] ?? '').trim() !== '')
      .map((c) => ({
        label: returnTargets.find((t) => t.value === c)?.label ?? c,
        note: remarkNotes[c],
      }))

    return items.length === codes.length ? items : []
  }

  const existingRemarks = [
    ...app.assignments
      .filter((a) => a.remarks)
      .map((a) => ({
        key: `a-${a.id}`,
        author: a.officer?.name ?? a.department.name,
        remark: a.remarks as string,
        items: remarkItems(a.remarks_target ?? null),
      })),
    ...(app.rejection_reason
      ? [
          {
            key: 'rejection',
            author: officerName,
            remark: app.rejection_reason,
            /* A whole-filing refusal names no rows. */
            items: [],
          },
        ]
      : []),
  ]

  /*
   * ── Corrections by field, newest first ──────────────────────────────
   *
   * Feeds the CORRECTED badge and the "was …" line on every correctable
   * box in Sections A and B. Client, 29 September 2026: *"I just Returned
   * -> Resubmitted this specific field and it did not show the previous
   * record … Please be consistent and uniform with the other fields as
   * well."* The sheet already carried these facts at the top under
   * "Corrected after your return"; this puts them where the officer is
   * actually reading, which is what Section C had just been given.
   *
   * Reversed as it groups, because the API sends them oldest-first and
   * every reader here wants the most recent change at [0].
   */
  const correctionsByTarget = (app.corrections ?? []).reduce((byTarget, correction) => {
    const existing = byTarget.get(correction.target)
    if (existing) {
      existing.unshift(correction)
    } else {
      byTarget.set(correction.target, [correction])
    }

    return byTarget
  }, new Map<string, ApplicationCorrection[]>())

  return (
    <FieldEdits.Provider
      /*
       * Null outside Edit mode, which is what every box reads to decide
       * whether it is a record or a control. One switch, so "View" cannot
       * mean read-only in one section and editable in another.
       */
      value={
        editing
          ? {
              values: fieldEdits,
              errors: fieldEditErrors,
              set: (key, value) => setFieldEdits((prev) => ({ ...prev, [key]: value })),
            }
          : null
      }
    >
    <FieldCorrections.Provider value={correctionsByTarget}>
    {/* Not re-indented: see the note in this patch — two spaces across
        2,200 lines would rewrite the sheet's blame to move nothing. */}
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
        {/*
          Says so when it is not true. It read "All Changes Saved" with
          typed corrections sitting unsaved in either buffer, and nothing
          autosaves here (tester, 5 October 2026).
        */}
        <span
          role="status"
          className={`hidden items-center gap-2 text-xs italic sm:flex ${
            unsavedEdits ? 'font-semibold text-s-orange-ink' : 'text-ink-muted'
          }`}
        >
          <CloudIcon />
          {unsavedEdits ? 'Unsaved changes' : 'All Changes Saved'}
        </span>
        {decided ? (
          <span
            className={`text-2xl font-bold underline underline-offset-4 ${rejected ? 'text-s-red' : 'text-s-green'}`}
          >
            {rejected ? 'Rejected' : 'Approved'}
          </span>
        ) : heldByAnother ? (
          /*
           * Named, not merely disabled.
           *
           * "Read only" on its own tells an officer the screen is broken. The
           * one fact that makes it make sense is WHOSE case it is, and the one
           * thing they can do about it — ask the super admin — belongs in the
           * same sentence. The Mode control goes with the buttons: offering
           * "Edit" on a sheet that cannot be edited is the same false promise
           * one level up.
           */
          <p className="rounded-lg bg-s-orange-tint px-4 py-2.5 text-sm font-medium text-s-orange-ink">
            {holderName ? (
              <>
                Officer in charge: <span className="font-bold">{holderName}</span>. Read-only for
                you — the system administrator can reassign it.
              </>
            ) : (
              <>This filing is with another officer, so it is read-only for you.</>
            )}
          </p>
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
            {/*
              ── Save, and only while there is something to save ──────────

              The client asked for it here, beside View/Edit, and for the
              edits NOT to autosave. Both are the same point: an applicant
              editing their own draft loses nothing by an early save, while
              an officer rewriting a submitted declaration is making a
              record, and a record is made on purpose.

              Hidden rather than disabled when the buffer is empty. A
              permanently greyed button beside two live pills reads as a
              broken screen; its appearing the moment a box changes is
              also the plainest way to say the change is not saved yet.

              Pressable while invalid, pointing at the count. A disabled
              button is skipped by the tab order, so the one control that
              would explain the situation is the one a screen-reader user
              never reaches (WCAG 3.3.1) — the same reasoning as
              `confirmDescribedBy` on ProtoModal.
            */}
            {editing && fieldEditsDirty && (
              <div className="flex flex-wrap items-center gap-2.5">
                <button
                  type="button"
                  onClick={() => fieldEditsValid && setConfirmFieldSave(true)}
                  aria-describedby={fieldEditsValid ? undefined : 'field-edit-invalid'}
                  className={`rounded-md px-7 py-2.5 text-sm font-semibold text-white shadow-card ${
                    fieldEditsValid ? 'bg-s-green hover:brightness-110' : 'bg-ink-muted'
                  }`}
                >
                  Save changes
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setFieldEdits({})
                    setFieldSaveError(null)
                  }}
                  className="rounded-md border border-line px-4 py-2.5 text-sm font-semibold text-ink-secondary hover:bg-input"
                >
                  Discard
                </button>
                {fieldEditsValid ? (
                  <span className="text-xs font-medium text-ink-muted">
                    {Object.keys(fieldEdits).length} unsaved
                    {Object.keys(fieldEdits).length === 1 ? ' change' : ' changes'}
                  </span>
                ) : (
                  <span
                    id="field-edit-invalid"
                    role="alert"
                    className="text-xs font-semibold text-s-red"
                  >
                    {Object.keys(fieldEditErrors).length} field
                    {Object.keys(fieldEditErrors).length === 1 ? '' : 's'} need fixing before
                    this can be saved.
                  </span>
                )}
                {fieldSaveError !== null && (
                  <span role="alert" className="text-xs font-semibold text-s-red">
                    {fieldSaveError}
                  </span>
                )}
              </div>
            )}
            {editing && (
              <>
                {mayRejectFiling && (
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
                  ── An office's own refusal, added 24 September 2026 ─────────

                  Shown to the five clearance offices and NOT to BPLO, which is
                  what `!canReject` selects for: BPLO's refusal is the button
                  above, which ends the filing, and the API refuses this one
                  from BPLO's seat anyway (`WorkflowService::rejectAssignment`)
                  because refusing the BUSINESS row would suspend the permit
                  that row issued.

                  Named "Reject this permit" and not "Reject". It sits three
                  inches from a button that ends the whole application, in a
                  product where both words appear on the same screen, and the
                  two words that differ are the ones that say which is which.

                  Red like BPLO's, because both are refusals and an officer
                  should not have to work out that theirs is the gentler red.
                  What differs is stated in `decisionNote` under the row.
                */}
                {mayRefusePermit && (
                  <button
                    type="button"
                    onClick={() => setPopup('reject_permit')}
                    disabled={busy}
                    className="rounded-md bg-s-red px-7 py-2.5 text-sm font-semibold text-white underline underline-offset-2 shadow-card hover:brightness-110 disabled:opacity-60"
                  >
                    Reject this permit
                  </button>
                )}
                {/*
                 * Every office's own negative decision, in the header beside
                 * Approve rather than buried at the foot of the sheet. For the
                 * six offices that cannot reject, this IS their reject button —
                 * item 80's complaint was that the screen appeared to offer them
                 * no way to say no.
                 */}
                {mayReturn && (
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
                )}
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
                {/*
                  The one thing an office CAN do while the applicant holds
                  the filing: change what it asked for.

                  A second Return is not offered and should not be — the
                  filing is not the office's to send back, and bouncing it
                  would interrupt a repair already under way. But an officer
                  who spots a second problem, or reads their own remark back
                  and finds it unclear, had nothing at all until now: they
                  waited for the resubmission and returned it again, and the
                  applicant paid for the omission with a whole extra round
                  trip. See WorkflowService::amendMainFormReturn.
                */}
                {withApplicant && mayAmendReturn && (
                  <button
                    type="button"
                    onClick={() => setPopup('amend')}
                    disabled={busy}
                    className="rounded-md border border-royal px-5 py-2.5 text-sm font-semibold text-royal transition-colors hover:bg-royal-tint disabled:opacity-60"
                  >
                    Change what you asked for
                  </button>
                )}
                {/*
                  Withheld while the applicant holds it. `approveMainForm`
                  refuses anything that is not For Approval, so before this
                  the officer met a 422 for pressing a button the page had
                  offered them — the worst shape for a rule, since the
                  screen and the server disagreed in front of them.
                */}
                {!withApplicant && (
                <button
                  type="button"
                  /*
                   * Opens the confirmation; `approve` runs from the dialog.
                   * The `categoryMissing` guard stays inside `approve` where
                   * it was — it is the API's rule restated, not part of
                   * asking the officer whether they are sure.
                   */
                  onClick={() => setConfirmingApprove(true)}
                  disabled={busy}
                  aria-disabled={categoryMissing}
                  aria-describedby={categoryMissing ? 'approve-blocked-why' : undefined}
                  className={`rounded-md px-7 py-2.5 text-sm font-semibold text-white underline underline-offset-2 shadow-card disabled:opacity-60 ${
                    categoryMissing ? 'bg-s-green/50' : 'bg-s-green hover:brightness-110'
                  }`}
                >
                  Approve
                </button>
                )}
              </>
            )}
          </div>
        )}
      </div>

      {/*
        * ── Why this one cannot be signed yet ────────────────────────────────
        *
        * A paid filing sits at an undecided `approved` until every clearance
        * is approved AND every Other Requirement is closed, and it now appears
        * in BPLO's Final Approval tab for that whole stretch — which is the
        * point: somebody has to be able to notice a filing that has stopped
        * moving. What they need on opening it is the reason, itemised. Without
        * this the sheet shows a filing with no Approve button and no
        * explanation, which reads as the product being broken.
        *
        * Counted from the payload rather than described in prose: "two permits
        * and one document" is actionable, "not ready" is not.
        */}
      {notReadyToSign && (
        <p className="mb-4 rounded-lg bg-s-orange-tint px-4 py-3 text-sm font-medium text-s-orange-ink">
          Not ready for final approval — {notReadyToSign}. It moves here on its own once the last
          one is settled.
        </p>
      )}

      {/*
        ── What the applicant corrected ────────────────────────────────────

        Only on a filing that has been round at least once. See the note at the
        head of this patch for why it sits above everything else.
      */}
      {(app.corrections ?? []).length > 0 && (
        <section className="mb-4 rounded-lg bg-white px-5 py-4 shadow-card">
          <p className="text-[11px] font-bold uppercase tracking-wide text-royal">
            Corrected after your return
          </p>
          <ul className="mt-3 space-y-2.5">
            {(app.corrections ?? []).map((c, i) => {
              const label = mainFormTargetLabel(c.target)
              /*
                A code this build does not recognise is skipped rather than
                printed raw — `form:something_new` under a heading that says
                "Corrected" would read as a field name to an officer who has
                never seen one. Same rule `mainFormTargetLabel` states.
              */
              if (label === null) return null

              const was = (c.old_value ?? '').trim()
              const now = (c.new_value ?? '').trim()

              return (
                <li key={i} className="flex flex-wrap items-baseline gap-x-2 text-sm">
                  <span className="font-semibold text-ink">{label}</span>
                  <span className="text-ink-secondary">
                    {was === now ? (
                      // Left as it was, deliberately. Not a silent no-op.
                      <>
                        unchanged — <span className="text-ink">{now === '' ? 'still blank' : now}</span>
                      </>
                    ) : (
                      <>
                        <span className="line-through">{was === '' ? 'blank' : was}</span>
                        <span className="mx-1.5">→</span>
                        <span className="font-semibold text-ink">{now === '' ? 'blank' : now}</span>
                      </>
                    )}
                  </span>
                  {/*
                    WHEN, because this list is the order things happened in and
                    nothing else on the row says so. The client's own filing has
                    two corrections to one field a minute apart, which read as
                    two identical rows.

                    `formatDateTime` — the house date-and-time, as on the
                    timeline and the payment rows. The short date under each
                    field answers "when was this last changed" in a box too
                    narrow for a time; this answers "in what order", with a
                    full row to do it in.

                    `ml-auto` pushes it to the end on a wide row and lets it
                    wrap under on a narrow one, rather than being clamped
                    against the value it is not part of.
                  */}
                  {c.at && (
                    <span className="ml-auto whitespace-nowrap text-xs text-ink-muted">
                      {formatDateTime(c.at)}
                    </span>
                  )}
                </li>
              )
            })}
          </ul>
        </section>
      )}
      {/* What each mode means, said plainly so nobody has to infer it (item 54). */}
      <p
        aria-live="polite"
        className="mb-4 flex items-start gap-2.5 rounded-lg bg-white px-4 py-3 text-sm text-ink-secondary shadow-card"
      >
        <span className={`mt-0.5 shrink-0 ${editing ? 'text-royal' : 'text-ink-muted'}`}>
          {editing ? <PencilIcon /> : <EyeIcon size={16} />}
        </span>
        <span>
          {/*
            The "Go to For Office Use Only" anchor was here until 27 September
            2026. Client: *"I don't think this link is needed. Remove this
            too."* It was added when the banner ENUMERATED the office's four
            fields and needed to answer "where are they"; the banner no longer
            names them, the panel is one screen down under its own heading, and
            a link to something already visible is one more thing to read.
          */}
          {modeNote}
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
          {/*
            The instruction and where to do it. What it was ALSO saying —
            that the category sets the RA 11032 deadline, and that Return
            and Reject do not need one — is true and is not what an officer
            blocked from approving needs in the sentence telling them they
            are blocked. The picker itself names the deadline it sets.
          */}
          Choose a processing category under{' '}
          <a
            href="#for-office-use"
            className="font-semibold text-royal underline underline-offset-2 hover:no-underline"
          >
            For Office Use Only
          </a>{' '}
          before you can approve.
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
                      /*
                        Resolved first, raw second. `*_label` is null for
                        anything that already reads as itself — a floor area, a
                        street — so the fallback is the normal case and the
                        lookup is the exception.
                      */
                      const before = applied
                        ? (row.old_label ?? row.old_value)
                        : (row.current_label ?? row.current_value)
                      const after = row.new_label ?? row.new_value

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
                              {after ?? (
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

          {/*
            ── This office refused this permit once ──────────────────────────

            Drawn whenever the permit carries a `rejected_at`, INCLUDING after
            the applicant has re-applied and it reads For Approval again —
            which is the case it exists for.

            When the sheet is handed back in, `submitClearanceForm` clears the
            remarks, because the instruction has been answered. The row then
            returns to this queue looking exactly like a first submission, and
            the form itself is unchanged unless the applicant changed it — one
            office form row per permit per filing, never one per attempt. So
            an officer could approve, in good faith, the identical sheet their
            own office turned down the week before.

            The remedy is shown beside the reason because it is what the
            officer asked for. Re-reading their own instruction is how they
            judge whether it was met.
          */}
          {data.clearance?.rejected_at && (
            <section className="mt-6 rounded-lg border border-s-red bg-s-red-tint px-5 py-4">
              <p className="text-[11px] font-bold uppercase tracking-wide text-s-red">
                Your office refused this permit on {formatDate(data.clearance.rejected_at)}
              </p>
              {data.clearance.rejection_note && (
                <p className="mt-1.5 text-sm italic leading-relaxed text-ink">
                  “{data.clearance.rejection_note}”
                </p>
              )}
              {data.clearance.rejection_remedy && (
                <p className="mt-1.5 text-sm leading-relaxed text-ink-secondary">
                  You asked for: “{data.clearance.rejection_remedy}”
                </p>
              )}
              {/*
                The one thing the banner cannot show them, said plainly: the
                form carries its previous answers between attempts, so "it
                looks the same" is not evidence either way.
              */}
              <p className="mt-2 text-xs text-ink-muted">
                Their answers carry over when they apply again, so check what changed rather
                than whether the form looks new.
              </p>
            </section>
          )}

          {/*
            Issue #99 — "the whole initial-approval form should stay visible to
            BPLO; hide it only from the other five offices" — is not an
            instruction to delete A, B, C and E for a clearance office while it
            is still working. WHEN the five stop seeing the form is settled
            above, at `nothingLeftForThisOffice`, and it is the moment their own
            review is in: an office cannot review a filing it is not allowed to
            read.
          */}
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
            /*
             * Filtered and labelled rather than dumped.
             *
             * `OFFICE_FORM_INTERNAL_KEYS` carries the markers that say which
             * OTHER sheet owns a shared question — machinery the applicant
             * never sees, which was printed here as though they had answered
             * "OCCUPANCY" to a field called Occupancy Shared Source.
             *
             * `officeFormFieldLabel` gives each office its own paper's
             * wording: humanising the key produced "Fsec No" for FSEC No., and
             * one label for a box BFP calls "Type of Occupancy / Business
             * Nature" and OBO calls "Use / Character of Occupancy".
             */
            const entries = Object.entries(form.form_data ?? {})
              .filter(([key]) => !OFFICE_FORM_INTERNAL_KEYS.includes(key))
              /*
               * In the order the office's own paper asks, not the order
               * the applicant happened to type. `form_data` is JSON and
               * its key order is an accident of filling-in, so an officer
               * reconciling this against the printed form was reading
               * down one and hunting in the other — the fault the client
               * had fixed on BPLO's sheet in September, which the five
               * clearance offices never got.
               */
              .sort(
                ([a], [b]) =>
                  officeFormFieldRank(form.permit_type_code, a) -
                  officeFormFieldRank(form.permit_type_code, b),
              )
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
                {/*
                  The paper this sheet IS, named the way the applicant's
                  own screen names it. An officer reconciling the two
                  should be able to see at a glance that they are looking
                  at the same form, and the form code is how that is said
                  in the office.
                */}
                {officeFormMeta(form.permit_type_code) !== undefined && (
                  <p className="mt-0.5 text-xs text-ink-secondary">
                    {officeFormMeta(form.permit_type_code)?.title} ·{' '}
                    <span className="tnum">{officeFormMeta(form.permit_type_code)?.ref}</span>
                  </p>
                )}
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
                  <div className="mt-3 flex flex-wrap items-start gap-x-4 gap-y-3">
                    {entries.map(([key, value]) =>
                      /*
                       * Not on a derived answer. The API writes those over
                       * whatever the sheet holds (`$derived + $formData`), so
                       * CENRO was offered "Correct Denr Basis" for a value
                       * that would save and silently come back (tester,
                       * 5 October 2026). They show as the record they are.
                       */
                      editing && !officeFormKeyIsDerived(form.permit_type_code, key) ? (
                        /*
                         * The same control the applicant answers a returned
                         * field with — chips where the sheet offers chips, a
                         * date where it asks a date — so a correction cannot
                         * write a value the sheet itself would never produce.
                         */
                        <div key={key} className="min-w-[12rem] grow basis-[14rem]">
                          <p className="text-[11px] font-bold uppercase tracking-wide text-ink-muted">
                            {officeFormFieldLabel(form.permit_type_code, key)}
                          </p>
                          <CorrectionAnswer
                            code={form.permit_type_code as OfficeFormCode}
                            field={key}
                            label={officeFormFieldLabel(form.permit_type_code, key)}
                            value={
                              sheetEdits[form.permit_type_code]?.[key] ??
                              (value == null ? '' : String(value))
                            }
                            onChange={(v) => editSheet(form.permit_type_code, key, v)}
                          />
                        </div>
                      ) : (
                        <Field
                          key={key}
                          label={officeFormFieldLabel(form.permit_type_code, key)}
                          value={officeFormValueText(key, value)}
                        />
                      ),
                    )}
                  </div>
                )}
                {editing && sheetEdits[form.permit_type_code] && (
                  <div className="mt-3 flex flex-wrap items-center gap-3">
                    <button
                      type="button"
                      onClick={() => void saveSheet(form.permit_type_code, form.form_data ?? {})}
                      aria-disabled={sheetSaving !== null || undefined}
                      className="rounded-full bg-royal px-4 py-1.5 text-xs font-semibold text-white hover:bg-royal-hover aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
                    >
                      {sheetSaving === form.permit_type_code ? 'Saving…' : 'Save corrections to this sheet'}
                    </button>
                    <span className="text-xs text-ink-muted">
                      {Object.keys(sheetEdits[form.permit_type_code]).length}
                      {Object.keys(sheetEdits[form.permit_type_code]).length === 1 ? ' answer' : ' answers'}{' '}
                      changed · recorded under your name
                    </span>
                    {sheetSaveError && (
                      <span className="text-xs font-semibold text-s-red">{sheetSaveError}</span>
                    )}
                  </div>
                )}
                {/*
                 * Says where the rest went, now that it is folded away. The
                 * old wording — "the rest of this sheet is..." — described a
                 * sheet that ran on down the page, which stopped being true
                 * the moment the disclosure below went in.
                 */}
                {form.requirements && form.requirements.length > 0 && (
                  <RequirementsRead
                    code={form.permit_type_code}
                    rows={form.requirements}
                    corrections={form.corrections ?? []}
                  />
                )}
                {/*
                  City Ordinance No. 24-2018 rule by rule — the cited checklist
                  CPDO decides against, from the same evaluator the applicant's
                  early warning came from. CPDO records what only it can (the
                  lot's zone first) and may correct any answer with a measured
                  one; read-only once this office's review is in.
                */}
                {form.permit_type_code === 'ZONING' && form.zoning_check && (
                  <div className="mt-4">
                    <OfficerZoningChecklist
                      applicationId={app.id}
                      initial={form.zoning_check}
                      readOnly={!owesReview}
                    />
                  </div>
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

            {/*
              Location & Zoning — FIRST, and its own section.

              Client, 27 September 2026: *"arrange the whole layout by
              ordering them by section. Zoning details should go first."*

              These were a sub-heading inside Business Information &
              Registration, which read as though they belonged to section A.
              They do not: the wizard asks them on their own step and asks it
              BEFORE section A, because where the business sits decides
              whether it may trade at all. This sheet now reads in the order
              the applicant answered.

              No letter. The paper runs A to C and has no zoning section, so a
              D would be a label the paper cannot back.
            */}
            <section className="mt-7">
              <h3 className="mb-4 text-lg font-bold text-ink">Location &amp; Zoning</h3>
              {/*
                ── The trade first, as the form asks it ──────────────────

                ApplyWizard puts this table at the head of Location & Zoning,
                before the address, and says why: the zoning conformity check
                on that step "is a judgment about a NAMED TRADE and needs the
                trade beside it."

                It was at the FOOT of Section B until 29 September 2026 —
                four headings below the barangay and the pin it is judged
                against, so the officer deciding conformity read the place in
                one section and the trade in another.
              */}
              <SubHeading>Line of Business</SubHeading>
              {business.lines && business.lines.length > 0 ? (
                <div className="space-y-4">
                  {business.lines.map((line, i) => (
                    <div key={line.id ?? i} className="flex flex-wrap items-start gap-x-4 gap-y-3">
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
                        // The applicant's own words first, then the class they
                        // were sorted into: a line under the catch-all 00000 no
                        // longer reaches the reviewer as "Other (not listed)".
                        value={lineOfBusinessText(line)}
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
                        className="grow basis-[32rem] max-w-full"
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

              <SubHeading>Main Office Address</SubHeading>
              {/*
                ── The form's own grids ────────────────────────────────────

                ApplyWizard asks these in three groups: House and Street as
                `grid sm:grid-cols-3` with Street spanning two, then Block /
                Lot / Lot Area as another `sm:grid-cols-3`, then the barangay
                on its own. Copied, because a single wrapping row put Street
                beside the barangay and scattered Block and Lot wherever they
                fitted.
              */}
              <div className="grid gap-3 sm:grid-cols-3">
                {/*
                  The real columns first, `splitLine1` only as a fallback.
                  `house_bldg_no` and `street` are what the wizard sends since
                  16 September 2026; before that it asked one combined question
                  and this page guessed the split out of `line1` with a regex,
                  which reversed the two on any filing whose entire street
                  address was a number ("17" → Street "17", House "—"). The
                  fallback stays for the filings made that way.
                */}
                <Field
                  label="House / Bldg No."
                  targets={['form:address']}
                  value={address?.house_bldg_no || house}
                  className="block"
                  /*
                   * The stored column, never the regex's guess. `house`
                   * is `splitLine1`'s reading of a pre-split filing, and
                   * saving it back would write a guess into the record as
                   * though the applicant had typed it.
                   */
                  edit={{ key: 'address.house_bldg_no', value: address?.house_bldg_no ?? '' }}
                />
                <Field
                  label="Street"
                  targets={['form:address']}
                  value={address?.street || street}
                  className="block sm:col-span-2"
                  edit={{ key: 'address.street', value: address?.street ?? '' }}
                />
              </div>

              {/*
                Block, Lot and Lot Area — asked of every applicant, on the
                payload since the premises block was transcribed, and drawn by
                no section of this sheet until 29 September 2026. Three
                submitted answers the reviewing office could not see.

                Optional on the form, so a blank is a question skipped rather
                than an answer missing, and reads as the em dash every other
                unanswered box uses.
              */}
              <div className="mt-3 grid gap-4 sm:grid-cols-3">
                <Field
                  label="Block"
                  targets={['form:address']}
                  value={address?.block ?? ''}
                  className="block"
                  edit={{ key: 'address.block' }}
                />
                <Field
                  label="Lot"
                  targets={['form:address']}
                  value={address?.lot ?? ''}
                  className="block"
                  edit={{ key: 'address.lot' }}
                />
                <Field
                  label="Lot Area (sq. m.)"
                  targets={['form:address']}
                  value={address?.lot_area_sqm == null ? '' : String(address.lot_area_sqm)}
                  className="block"
                  edit={{ key: 'address.lot_area_sqm' }}
                />
              </div>

              {/* Its own block on the form, and the answer the zoning turns on. */}
              <div className="mt-3">
                <Field
                  label="Barangay"
                  targets={['form:barangay']}
                  value={address?.barangay?.name ?? ''}
                  className="block sm:max-w-[22rem]"
                  /*
                   * A choice from the city's own table, so a select and
                   * not a text box — the wizard asks it the same way, and
                   * a typed barangay is a zoning decision made against a
                   * spelling. Empty while the list is still in flight,
                   * which is a moment and not a state worth drawing.
                   */
                  edit={{
                    key: 'address.barangay_id',
                    value: address?.barangay?.id == null ? '' : String(address.barangay.id),
                    control: {
                      kind: 'select',
                      /*
                        The filing's own barangay while the list is still in
                        flight, so the control never shows an empty row where
                        the record has an answer. Replaced by the city's list
                        the moment it lands.
                      */
                      options:
                        (barangaysRef.data ?? []).length > 0
                          ? (barangaysRef.data ?? []).map((b) => ({
                              value: String(b.id),
                              label: b.name,
                            }))
                          : address?.barangay?.id == null
                            ? []
                            : [
                                {
                                  value: String(address.barangay.id),
                                  label: address.barangay.name ?? '',
                                },
                              ],
                    },
                  }}
                />
              </div>

              {/*
                Not the form's questions. The applicant is asked for none of
                these — every address this system licenses is in Malabon, which
                has one postal code — so they are record fields the API fills
                and cannot take a place in the form's grid above. Kept, because
                an officer transcribing onto paper still needs them.
              */}
              <div className="mt-3 flex flex-wrap items-start gap-x-4 gap-y-3">
                <Field label="City / Municipality" value={address?.city ?? 'Malabon City'} className="grow basis-[12rem] max-w-full" />
                <Field label="Province" value={address?.province ?? 'Metro Manila'} className="grow basis-[12rem] max-w-full" />
                <Field label="Postal Code" value={address?.postal_code ?? ''} className="grow basis-[8rem] max-w-full" />
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
                    <Field label="Latitude" value={address.latitude.toFixed(6)} className="grow basis-[10rem] max-w-full" />
                    <Field label="Longitude" value={address.longitude.toFixed(6)} className="grow basis-[10rem] max-w-full" />
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

              {/*
                ── After the map, as the form asks them ──────────────────

                The wizard puts the landmark immediately below the barangay
                zoning map and the two contacts under it, and the sheet now
                does the same.

                The landmark is `address.line2`. It has been on the payload
                the whole time and no section drew it — an answer the
                applicant gave about how to FIND the premises, withheld from
                the inspector who has to. The contacts came from "Premises &
                Contact" in Section B; the form asks them here, beside the
                address they are the fallback for.
              */}
              {/* Its own block below the map on the form, as here. */}
              <div className="mt-5">
                <Field
                  label="Locational Group / Landmark"
                  targets={['form:address']}
                  value={address?.line2 ?? ''}
                  className="block"
                  edit={{ key: 'address.line2' }}
                />
              </div>

              {/* `grid sm:grid-cols-2`, the pair's own shape on the form. */}
              <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <Field
                  label="Emergency Contact Person"
                  targets={['form:emergency_contact_name']}
                  value={business.emergency_contact_name ?? ''}
                  className="block"
                  edit={{ key: 'emergency_contact_name' }}
                />
                <Field
                  label="Emergency Contact Number"
                  targets={['form:emergency_contact_number']}
                  value={business.emergency_contact_number ?? ''}
                  className="block"
                  edit={{ key: 'emergency_contact_number' }}
                />
              </div>
            </section>

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
                ── The paper's numbering was here, and is gone ────────────────

                This section followed MCG-BPLO-FO-001's own numbers, skips and
                all — 1, 2, 3, 4, 6 … with Form of Organization at 10 — against
                a wizard that renumbers sequentially. The argument for it was
                that an officer holding the paper wants the paper's numbers.

                The client decided the other way on 29 September 2026, and the
                reason outweighs it: the officer's counterpart is not the blank
                paper, it is the filing the applicant made, and every other
                screen in the Return loop already speaks the wizard's
                numbering. "Fix item 2" has to mean one field across the
                picker, the applicant's correction card and this sheet — and it
                meant the registration number on two of them and the TIN here.

                If the paper's numbers are ever wanted back, they belong
                BESIDE these rather than instead of them.
              */}
                {/*
                  ── The APPLICATION FORM's order, 1 to 17 with no gaps ───────

                  Checked against ApplyWizard's Section A label by label; the
                  list is in this patch's note. It ran 1, 2, 3, 4, 6 … 10, 11/12,
                  13, 14, 15 before — the paper's numbering, with Form of
                  Organization tenth, which is the client's report of
                  29 September 2026.

                  One row, so the numbers can ascend across the whole section
                  rather than restarting in each container.
                */}
                {/*
                  ── The form's five rows, pinned ─────────────────────────────

                  `RowBreak` is ApplyWizard's own `-my-1.5 basis-full` spacer,
                  which it uses once, before item 14. Used at each of the form's
                  breaks here, because the sheet has to reproduce a grouping it
                  cannot reproduce by width alone: the form's rows are shaped by
                  radio chips and segmented number boxes, and a record box
                  holding "Sole Proprietorship" is not that shape.

                  Pinning also means the rows survive a narrow window, where
                  widths alone would re-wrap into an order the form never had.
                */}
                <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
                  <Field
                    label="1. Form of Organization"
                    className="grow basis-[26rem] max-w-full"
                    targets={['form:registration_type']}
                    value={
                      business.registration_type ? humanizeKey(business.registration_type) : ''
                    }
                    /*
                      Radio chips, because that is what the form gives the
                      applicant — the client's instruction of 30 September
                      2026. The raw value goes to the control: the box
                      prints "Sole Proprietorship" and the column holds
                      `sole_proprietorship`, and an input handed the label
                      would post the label back.
                    */
                    edit={{
                      key: 'registration_type',
                      value: business.registration_type ?? '',
                      control: { kind: 'radio', options: ORGANIZATION_FORMS },
                    }}
                  />
                  {/*
                    The agency, not a slash-list. The wizard asks a cooperative
                    for its CDA number and a corporation for its SEC number, and
                    `registrationNumberLabel` is the function it asks with — so
                    the officer reads back the question that was actually put,
                    and falls back to the generic heading when the structure is
                    not known.
                  */}
                  <Field
                    /*
                      Named off the BUFFER, not the record. An officer who
                      switches item 1 to Cooperative is then asked for a CDA
                      number, exactly as the applicant would be — a heading
                      still saying DTI over a box the officer has just
                      repurposed is how a wrong number gets typed in.
                    */
                    label={`2. ${registrationNumberLabel(
                      fieldEdits.registration_type ?? business.registration_type ?? '',
                    )}`}
                    className="grow basis-[15rem] max-w-full"
                    targets={['form:registration_number']}
                    value={business.registration_number ?? ''}
                    edit={{ key: 'registration_number' }}
                  />

                  <RowBreak />
                  <Field
                    label="3. Tax Identification Number (TIN)"
                    targets={['form:tin']}
                    value={business.tin ?? ''}
                    className="grow basis-[14rem] max-w-full"
                    edit={{ key: 'tin' }}
                  />
                  <Field
                    label="4. Business Name"
                    targets={['form:name']}
                    value={business.name ?? ''}
                    className="grow basis-[20rem] max-w-full"
                    edit={{ key: 'name' }}
                  />
                  <Field
                    label="5. Trade Name / Franchise"
                    targets={['form:trade_name']}
                    value={business.trade_name ?? ''}
                    className="grow basis-[14rem] max-w-full"
                    edit={{ key: 'trade_name' }}
                  />

                  <RowBreak />
                  {/*
                    Items 6 to 9. All four had columns and no input until the
                    paper forms were transcribed, so on filings made before that
                    they read "—" — which is the truth: nobody was asked.
                  */}
                  <Field
                    label="6. Telephone (Landline)"
                    className="grow basis-[11rem] max-w-full"
                    targets={['form:telephone']}
                    value={business.address?.telephone ?? ''}
                    edit={{ key: 'address.telephone' }}
                  />
                  <Field
                    label="7. Mobile Number"
                    targets={['form:mobile_number']}
                    value={business.address?.mobile_number ?? ''}
                    className="grow basis-[11rem] max-w-full"
                    edit={{ key: 'address.mobile_number' }}
                  />
                  <Field
                    label="8. E-mail Address"
                    targets={['form:email']}
                    value={business.address?.email ?? ''}
                    className="grow basis-[14rem] max-w-full"
                    edit={{ key: 'address.email' }}
                  />
                  <Field
                    label="9. Website Address"
                    targets={['form:website']}
                    value={business.address?.website ?? ''}
                    className="grow basis-[12rem] max-w-full"
                    edit={{ key: 'address.website' }}
                  />

                  <RowBreak />
                  {/*
                    Items 10 to 13 — four boxes, because the form asks four, and
                    at the form's own widths: 11rem, 11rem, 11rem, 7rem. They
                    were one assembled "Owner / Representative" line, which
                    presented as a single answer what the applicant gave as four.
                  */}
                  <Field
                    label="10. Surname"
                    className="grow basis-[11rem] max-w-full"
                    targets={['form:owner_surname']}
                    value={business.owner?.surname ?? ''}
                    edit={{ key: 'owner.surname' }}
                  />
                  <Field
                    label="11. Given Name"
                    className="grow basis-[11rem] max-w-full"
                    targets={['form:owner_given_name']}
                    value={business.owner?.given_name ?? ''}
                    edit={{ key: 'owner.given_name' }}
                  />
                  <Field
                    label="12. Middle Name"
                    className="grow basis-[11rem] max-w-full"
                    targets={['form:owner_middle_name']}
                    value={business.owner?.middle_name ?? ''}
                    edit={{ key: 'owner.middle_name' }}
                  />
                  <Field
                    label="13. Suffix"
                    className="grow basis-[7rem] max-w-full"
                    targets={['form:owner_suffix']}
                    value={business.owner?.suffix ?? ''}
                    edit={{ key: 'owner.suffix' }}
                  />

                  {/* The form's own break, in the form's own place. */}
                  <RowBreak />
                  {/*
                    The word, not the code. `humanizeKey` has no idea 'M' is
                    short for anything and returned it unchanged.
                  */}
                  <Field
                    label="14. Gender"
                    className="shrink-0 basis-[8rem] max-w-full"
                    targets={['form:owner_gender']}
                    value={genderLabel(business.owner?.gender)}
                    /* Two chips, as the paper prints two boxes. The box
                       reads "Male"; the column holds 'M'. */
                    edit={{
                      key: 'owner.gender',
                      value: business.owner?.gender ?? '',
                      control: { kind: 'radio', options: GENDERS },
                    }}
                  />
                  {/*
                    ── The officer box ──────────────────────────────────────

                    `rounded-xl border border-line p-3`, copied from the form,
                    and the border is not decoration: 15 to 17 are about the
                    PRESIDENT OR OFFICER IN CHARGE, who is a different person
                    from the owner named in 10 to 13 immediately above. Run
                    together, seven name-ish boxes read as one person's details.
                  */}
                  <div className="grow basis-[34rem] max-w-full rounded-xl border border-line p-3">
                    <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
                      <Field
                        label="15. Name of President / Officer in Charge"
                        className="grow basis-[15rem] max-w-full"
                        targets={['form:president_officer_name']}
                        value={business.president_officer_name ?? ''}
                        edit={{ key: 'president_officer_name' }}
                      />
                      <Field
                        label="16. Citizenship (of President/OIC)"
                        className="grow basis-[11rem] max-w-full"
                        targets={['form:citizenship']}
                        value={business.citizenship ?? ''}
                        edit={{ key: 'citizenship' }}
                      />
                      <Field
                        label="17. Capital Participation (% Filipino)"
                        className="grow basis-[11rem] max-w-full"
                        targets={['form:capital_participation']}
                        value={
                          business.capital_participation_filipino == null
                            ? ''
                            : `${business.capital_participation_filipino}%`
                        }
                        /* The number, not "60%" — the per cent sign is the
                           sheet's, and the column would refuse it. */
                        edit={{
                          key: 'capital_participation_filipino',
                          value:
                            business.capital_participation_filipino == null
                              ? ''
                              : String(business.capital_participation_filipino),
                        }}
                      />
                    </div>
                  </div>
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
              {/*
                ── One row, so the numbers can ascend ───────────────────

                The fee-profile facts used to sit in a container of their
                own, above the business-column ones. Two containers cannot
                interleave, so the sheet read 6, 1, 2, 2, 3 … 5, 6, 7 — the
                arrangement the client reported. `feeFacts` are emitted
                here, inside the same row as the rest, and `orderedFeeFacts`
                sorts the whole of Section B on the paper's own numbering.
              */}
              <div className="mb-6 flex flex-wrap items-start gap-x-4 gap-y-3">
                {/*
                  Row 1 — items 1 to 4, with 2 and 4 in the form's own boxes.
                */}
                <FeeFactRow facts={orderedFeeFacts} />

                {/*
                  Row 2 — item 5 alone, as the form gives it: a six-option
                  radiogroup across the width, not a box in a row of boxes.
                */}
                <RowBreak />
                <Field
                  className="basis-full max-w-full"
                  label="5. Economic Organization"
                  value={
                    business.economic_organization
                      ? business.economic_organization === 'others'
                        ? `Others — ${business.economic_organization_others || 'unspecified'}`
                        : humanizeKey(business.economic_organization)
                      : ''
                  }
                  /* Six chips across the width, the shape the form gives
                     item B5 — and the reason this box is `basis-full`. */
                  edit={{
                    key: 'economic_organization',
                    value: business.economic_organization ?? '',
                    control: { kind: 'radio', options: ECONOMIC_ORGANIZATIONS },
                  }}
                />
                {/*
                  "Others" is a choice that asks a second question, and the
                  form asks it in a box that appears with the choice. Read
                  off the BUFFER, not the record, so it appears the moment
                  the officer picks Others rather than after a save.
                */}
                {editing
                  && (fieldEdits.economic_organization
                    ?? business.economic_organization) === 'others' && (
                  <Field
                    className="basis-full max-w-full"
                    label="Others — say what it is"
                    value={business.economic_organization_others ?? ''}
                    edit={{ key: 'economic_organization_others' }}
                  />
                )}
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
                {/* Row 3 — items 6, 7 and 8, as the form's last row. */}
                <RowBreak />
                <Field
                  label="6. Capital Investment"
                  className="grow basis-[13rem] max-w-full"
                  targets={['form:capital_investment']}
                  value={
                    business.capital_investment == null || business.capital_investment === ''
                      ? ''
                      : formatMoney(Number(business.capital_investment))
                  }
                  /* The amount, not "₱250,000.00": the column takes a
                     number and the peso sign is this sheet's doing. */
                  edit={{
                    key: 'capital_investment',
                    value:
                      business.capital_investment == null
                        ? ''
                        : String(business.capital_investment),
                  }}
                />
                <Field
                  label="7. Tax Incentives from a Government Entity"
                  targets={['form:has_tax_incentives']}
                  value={
                    business.has_tax_incentives == null
                      ? ''
                      : business.has_tax_incentives
                        ? 'Yes — certificate required'
                        : 'No'
                  }
                  edit={{
                    key: 'has_tax_incentives',
                    /*
                      Blank when the record is blank, so no chip is lit. A
                      box reading — must not become a control reading "No":
                      that turns "nobody was asked" into a declaration.
                    */
                    value:
                      business.has_tax_incentives == null
                        ? ''
                        : business.has_tax_incentives
                          ? '1'
                          : '0',
                    control: { kind: 'radio', options: YES_NO },
                  }}
                />
                {/*
                  Item 8, in the row with the rest. It was under a "Premises &
                  Contact" heading at the foot of the section, so Section B's
                  numbers ran 1 to 7 and then jumped a heading to reach 8.
                */}
                <Field
                  label="8. Do you pay rent for occupying a place of business?"
                  targets={['form:is_rented']}
                  value={business.is_rented == null ? '' : business.is_rented ? 'Yes' : 'No'}
                  className="grow basis-[20rem] max-w-full"
                  edit={{
                    key: 'is_rented',
                    value:
                      business.is_rented == null ? '' : business.is_rented ? '1' : '0',
                    control: { kind: 'radio', options: YES_NO },
                  }}
                />
                {/*
                  After the numbered run, not through it. It was between items 6
                  and 7, so the row read 5, 6, <unnumbered>, 7, 8.

                  MCG-BPLO-FO-002's own box, at the foot of its page 1, and a
                  renewal's alone — FO-001 does not print one, so on a new
                  filing this would report a default nobody chose.

                  Recorded, not acted on. The Tax Order of Payment bills the
                  full year whatever this says, the applicant is told so on the
                  form, and the instalment is arranged at the Treasurer's
                  window — so the officer reads an election, not a schedule.
                */}
                {app.application_type === 'renewal' && (
                  <Field
                    label="Mode of Payment"
                    value={
                      {
                        annual: 'Annually',
                        semi_annual: 'Semi-Annually',
                        quarterly: 'Quarterly',
                      }[app.payment_mode ?? 'annual'] ?? ''
                    }
                  />
                )}
              </div>


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
              {/*
                Item 8 asks "Do you pay rent for occupying a place of
                business?", so the answer is Yes or No — "Rented"/"Owned"
                answered a question the paper does not put, and the wizard
                stopped putting it on 16 September 2026.

                It sits in the numbered Section B row above now, where the
                form puts it. The emergency contacts that shared this
                "Premises & Contact" heading have gone to Location & Zoning,
                which is the step that asks them. The two were together only
                because they had been drawn together.
              */}
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
                        <div key={i} className="flex flex-wrap items-start gap-x-4 gap-y-3">
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
                            /*
                             * Named for its SCOPE. Section B carries a Gross
                             * Sales box for the whole business, and an
                             * identically-labelled figure inside a line row
                             * read as the same number printed twice. It is
                             * not — the Revenue Code taxes each trade on its
                             * own turnover — so the label says which one this
                             * is rather than leaving the reader to infer it
                             * from the heading above.
                             */
                            label="Gross Sales — this line"
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
                    : requirementGroups.map((group) => (
                        <DocumentRow key={group.code} group={group} />
                      ))}
                </ul>
              )}
            </section>

            {/*
             * Section D is GONE, and the sheet still runs A, B, C, E.
             *
             * It rendered "Other Offices' Form Answers" — every questionnaire on
             * the filing that was not the reader's own. For the five clearance
             * offices the server had already emptied it (checklist item 111, the
             * `owner_birthday` leak), which left a permanent heading over a
             * permanent apology, and the client asked for that much back then:
             * "can you remove this part since this is highly unnecessary." It
             * survived as a conditional because BPLO and the super admin were
             * still sent every sheet and that was called coordination.
             *
             * The client has now ruled on the remaining seat too — "The
             * initial-approval view already shows answers for the other offices'
             * forms. Remove them" (issue #95) — so there is no reader left for
             * whom this section has contents, and a section with no reader is
             * deletion rather than another conditional.
             *
             * The letter is not reused and A–E are NOT renumbered: the screen is
             * a rendering of the paper BPLO form (MCG-BPLO-FO-001) and every
             * section reference spoken aloud in the office is to that paper. A
             * gap is cheaper than four wrong letters.
             *
             * What would bring it back: the client asking BPLO to read another
             * office's questionnaire again. It is `otherOfficeForms` in the
             * history — the same filter as the lead panel with `!==` for `===` —
             * and the payload still carries the rows to fill it.
             */}

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

            {/*
              The consent note is not drawn here any more (client, 1 October
              2026). It reported a fact the officer cannot act on: consent is
              required to submit, so EVERY filing that reaches this sheet has
              it, and a green panel that is always green on every filing tells
              a reader nothing and costs a block of the screen they scroll
              past.

              Only the DISPLAY goes. `data_privacy_consent` and the submission
              timestamp stay on the record — see the picker in that
              conversation: the tick is the lawful basis for processing the
              applicant's personal data under RA 10173, and a controller that
              cannot show consent was given has no answer if it is ever asked.
            */}

            {/*
              ── The signature block is GONE, and that is the point ─────────

              The paper (p72) prints two signature boxes, and this drew them:
              the applicant's ACCOUNT NAME set in italic serif, in royal, in a
              bordered box captioned "Signature of Applicant / Owner over
              Printed Name". Nobody ever signed anything. BizTrack does not
              collect a signature, and the client confirmed on 1 October 2026
              that it will not — identity is established from the uploaded
              documents instead.

              So the officer deciding the filing was shown a typeset name
              dressed as handwriting, under a caption asserting it was a
              signature. This codebase already has the rule, two screens
              away: PermitDetailPage leaves the Mayor's and the OIC's lines
              EMPTY because "a name written here in code would be a forgery
              that keeps printing after the officeholder has moved on". The
              same objection applies to the applicant's.

              Removed rather than blanked. Blank lines are right on the
              PERMIT, which is a document someone signs in ink; this is a
              screen for reading a filing, and an empty box captioned
              "Signature" on a system that collects none reads as something
              broken or not yet done.

              What the applicant actually did is recorded directly above and
              stays: the Data Privacy Consent, with the timestamp it was
              given at. That is the real act, and it is the one worth showing.
            */}

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
          {foldsApplication &&
            taxOrderFold(
              'flex w-full items-start gap-3 rounded-lg border border-line bg-canvas px-4 py-3 text-left hover:border-royal/40 hover:bg-royal-tint focus:outline-none focus-visible:ring-2 focus-visible:ring-royal',
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
            {/*
              Nothing at all in read-only mode. It said "Switch the mode at the
              top of the page to Edit to fill these in" — under a heading
              reading FOR OFFICE USE ONLY · READ ONLY, about a Mode control at
              the top of this page whose two options are Read only and Edit.

              The editing branches stay. They say which fields save with their
              own button and which ride along with the decision taken at the
              top, which is written nowhere else and is the thing an officer
              gets wrong.
            */}
            {(decided || editing) && (
              <p className="mt-1 text-xs text-ink-secondary">
                {decided
                  ? 'What this office recorded during its review.'
                  : canAdjustFee || issuedGroups.length > 0 || canSetTier
                    ? 'This panel is the only part of the sheet you can change. Evaluator Remarks travels with the decision you make at the top of the page; the other fields here each save with their own button.'
                    : 'This panel is the only part of the sheet you can change. Evaluator Remarks is the only field in it, and it travels with the decision you make at the top of the page.'}
              </p>
            )}

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
                RA 11032 · Deadline
              </p>
              {(tierProvenance || (editing && canSetTier)) && (
                <p id="ra11032-note" className="mt-1 max-w-prose text-xs text-ink-secondary">
                  {tierProvenance}
                  {editing && canSetTier && (
                    <>
                      {' '}
                      The three categories and their day counts are set by RA 11032 and cannot be
                      edited. Changing which one this filing is re-counts its deadline from the
                      date it was filed, not from today.
                    </>
                  )}
                </p>
              )}
              <div className="mt-3">
                {editing && canSetTier ? (
                  tierPicker()
                ) : (
                  /*
                    The date, not the category that produced it — see the note
                    at the head of this patch. Blank rather than "—" when the
                    filing has no deadline yet, which is every draft: an
                    officer never opens one, and inventing a dash would imply
                    a clock that is not running.
                  */
                  <OfficeReadout
                    label="Decide by"
                    value={app.deadline_at ? formatDate(app.deadline_at) : ''}
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
                {/* Only while editing: read-only, the heading above says it. */}
                {editing && (
                  <p className="mt-1 text-xs text-ink-secondary">
                    Enter the dates the issuing office released these documents. Applicants are
                    not asked for them.
                  </p>
                )}
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

            BPLO's copy stays HERE, inside FOR OFFICE USE ONLY, because that
            is where the paper puts the assessment and BPLO is the office
            that raises it. WHERE it sits is the only difference between the
            seats now — it folds away for both, on the client's instruction
            of 27 September 2026. See `taxOrderFold`.
          */}
          {!foldsApplication &&
            taxOrderFold(
              'flex w-full items-start gap-3 rounded-lg border border-officeuse-border bg-white/70 px-4 py-3 text-left hover:border-royal/40 hover:bg-royal-tint focus:outline-none focus-visible:ring-2 focus-visible:ring-royal',
            )}

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
            {/* Return and Amend are both dialogs; only the refusals sit here. */}
            {popup && popup !== 'return' && popup !== 'amend' && (
              <RemarkPopup
                action={popup}
                officer={officerName}
                initialText={remarks}
                /*
                  A refusal points at rows too, since 30 September 2026. A
                  plain Reject (BPLO ending the filing) still names nothing —
                  it is about the application, not about a field.
                */
                targets={popup === 'reject_permit' ? returnTargets : []}
                submitting={busy}
                error={actionError}
                onCancel={() => setPopup(null)}
                onConfirm={sendRemark}
              />
            )}
            {existingRemarks.map((r) => (
              <RemarkBubble key={r.key} author={r.author} remark={r.remark} items={r.items} />
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
      {popup && popup !== 'return' && popup !== 'amend' && (
        <div className="fixed inset-x-4 bottom-6 z-40 lg:hidden">
          <RemarkPopup
            action={popup}
            officer={officerName}
            initialText={remarks}
            targets={popup === 'reject_permit' ? returnTargets : []}
            submitting={busy}
            error={actionError}
            onCancel={() => setPopup(null)}
            onConfirm={sendRemark}
          />
        </div>
      )}

      {/*
        Return, as a dialog — and as ONE instance rather than the two the
        panel needs.

        The aside is hidden below `lg`, so the panel had to be rendered twice
        and every prop handed to both; a prop given to one of them was a
        control that existed on a desktop and not on a phone, which is a bug
        the note on `initialText` records having already happened. A dialog is
        the same overlay at every width, so there is one of it and nothing to
        keep in step.
      */}
      {/*
        Approve's confirmation. One sentence: a dialog nobody reads is a click
        with a step in front of it, and length is what stops it being read.

        `ProtoModal` owns the focus trap, the Escape key and the two footer
        buttons, the same as the remark composers — so this behaves like every
        other dialog on the page rather than being a third pattern.
      */}
      {confirmingApprove && (
        <ProtoModal
          title="Approve this application?"
          tone="green"
          onCancel={() => setConfirmingApprove(false)}
          confirmLabel={busy ? 'Approving…' : 'Yes, approve'}
          confirmDisabled={busy}
          onConfirm={() => {
            setConfirmingApprove(false)
            void approve()
          }}
        >
          <p className="text-sm text-ink-secondary">
            Confirm that you have reviewed all the details on this application. This cannot be
            undone.
          </p>
        </ProtoModal>
      )}
      {/*
        One composer for both acts. Return sends the filing back; Amend
        corrects what was asked of an applicant already holding it. Same
        question, same field picker, same notes — so the same control, with
        the endpoint chosen in `sendRemark`.
      */}
      {(popup === 'return' || popup === 'amend') && (
        <RemarkPopup
          chrome="modal"
          /* Its own heading and button; the two acts are not the same. */
          action={popup === 'amend' ? 'amend' : 'return'}
          officer={officerName}
          /*
            Amending opens on the remark already given, so an officer
            adding a field does not have to retype the sentence — and
            cannot accidentally replace it with a blank one, since the
            whole instruction is rewritten on every save.
          */
          initialText={popup === 'amend' ? (openReturnRemark ?? remarks) : remarks}
          targets={returnTargets}
          /*
            Amending opens on what was asked for; a fresh Return opens
            blank. The pointer survives a resubmission on purpose — so the
            officer can see what the last round was about — and seeding a
            NEW return from it would re-ask last round's questions.
          */
          initialPicked={popup === 'amend' ? openReturnTargets : []}
          initialNotes={popup === 'amend' ? openReturnNotes : {}}
          submitting={busy}
          error={actionError}
          onCancel={() => setPopup(null)}
          onConfirm={sendRemark}
        />
      )}
      {/*
        ── The confirmation the client asked for ─────────────────────────

        Not a formality. This writes over answers a citizen DECLARED, and
        the ordinary way to change one is to return the filing so the
        declarant changes it themselves. The dialog names each field it is
        about to overwrite and says the change is recorded against the
        officer, because both are true and an officer should be told the
        second one before they press it rather than after.
      */}
      {confirmFieldSave && (
        <ProtoModal
          title="Save changes to this filing?"
          confirmLabel={savingFields ? 'Saving…' : 'Save changes'}
          confirmDisabled={savingFields || !fieldEditsValid}
          onCancel={() => setConfirmFieldSave(false)}
          onConfirm={() => void saveFields()}
        >
          <p className="text-sm text-ink-secondary">
            You are changing {Object.keys(fieldEdits).length} answer
            {Object.keys(fieldEdits).length === 1 ? '' : 's'} the applicant submitted. The
            change is recorded against your account.
          </p>
          <ul className="mt-3 space-y-1.5">
            {Object.entries(fieldEdits).map(([key, value]) => (
              <li key={key} className="text-sm">
                <span className="font-semibold text-ink">{editFieldLabel(key)}</span>
                <span className="text-ink-muted"> → </span>
                <span className="text-ink">
                  {editFieldDisplay(key, value, barangaysRef.data ?? [])}
                </span>
              </li>
            ))}
          </ul>
          {fieldSaveError !== null && (
            <p role="alert" className="mt-3 text-sm font-semibold text-s-red">
              {fieldSaveError}
            </p>
          )}
        </ProtoModal>
      )}
    </div>
    </FieldCorrections.Provider>
    </FieldEdits.Provider>
  )
}
