import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react'
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
import { DocumentActions } from '../../components/DocumentActions'
import { TinInput } from '../../components/TinInput'
import { Skeleton } from '../../components/ui/primitives'
import {
  FieldLabel,
  PillButton,
  ProtoModal,
  inputCls,
} from '../../components/ui/Proto'
import { formatBytes, formatDate } from '../../lib/format'
import { toApiError } from '../../lib/api'
import {
  applications,
  businesses,
  clearances,
  documents,
  officeForms,
  reference,
} from '../../lib/resources'
import type { AmendmentAnswers } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { ACCEPT_ATTR, fileRejection, uploadErrorMessage } from './uploads'
import { ClearanceStage, anyClearanceDecided, marketClearanceApplies } from './ClearanceStagePage'
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
  Clearance,
  DocumentType,
  OcrSuggestions,
  Permit,
  PsicCode,
} from '../../lib/types'

type BasePhase =
  | 'privacy'
  | 'address'
  | 'business'
  | 'documents'
  | 'fees'
  | 'clearances'
  | 'review'
/*
 * ── The order of this wizard, and why the clearances come last ────────────
 *
 * Settled with the client on 4 August 2026 (docs/clearances-before-payment.md).
 * Checklist item 76 asked for the LGU Section "at the last part before
 * submitting the application", and it is now exactly that: everything is
 * decided here, and payment is the only thing that happens afterwards.
 *
 * Two earlier arrangements were wrong, in opposite directions, and both are
 * worth naming so neither comes back.
 *
 *   The cards sat at step 4 of 8, and the argument for not moving them was
 *   real: `requiredDocs` was the union of the document types on the selected
 *   permit types, and the tax profile's questions varied by permit code, so
 *   both of the sections after the cards were COMPUTED from them. Moving the
 *   cards later would have asked the applicant to satisfy a list that did not
 *   exist yet.
 *
 *   The fix attempted for that was to move the clearances out of the wizard
 *   entirely, into a stage that opened after the first payment. It cost an
 *   accruing balance, a second payment, a gate holding the permit until the
 *   balance cleared, and a locked stage — four mechanisms, each of which grew
 *   its own bug.
 *
 * The dependency dissolved without either. Documentary Requirements describes
 * the business permit alone (each clearance's documents live on its own office
 * sheet) and the tax profile asks the business permit's questions alone, so
 * nothing between here and Review is computed from which clearances were
 * picked. The Tax Order of Payment is produced at submit, over exactly the
 * permit types the applicant finished with, so the clearances chosen on step 6
 * are billed with everything else on one bill.
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
 *   The LGU Clearances step comes after everything that describes the
 *   business, because its office form sheets are filled in *from* those
 *   answers. When the cards sat second, an applicant picked their clearances
 *   before the system knew the business name, the address or the trade — so
 *   every office sheet opened blank and asked for what the applicant had not
 *   yet been asked.
 *
 * Documentary Requirements precedes the Business & Tax Profile, which is the
 * order the client's diagram gives. Nothing forces it either way.
 */
const BASE_PHASES: BasePhase[] = [
  'privacy',
  'address',
  'business',
  'documents',
  'fees',
  'clearances',
  'review',
]

const BASE_LABELS: Record<BasePhase, string> = {
  privacy: 'Data Privacy Consent',
  business: 'Business Information',
  address: 'Location & Zoning',
  documents: 'Documentary Requirements',
  fees: 'Business & Tax Profile',
  clearances: 'LGU Clearances',
  review: 'Review & Submit',
}

const OFFICE_LABELS: Record<OfficeFormCode, string> = {
  ZONING: 'Locational Clearance Form',
  SANITARY: 'Sanitary Permit Form',
  CEC: 'Environmental Clearance Form',
  FSIC: 'Fire Safety (FSIC) Form',
  OCCUPANCY: 'Occupancy Permit Form',
  MARKET: 'Market Clearance Form',
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

/** A single running step: either a base phase or one office form sheet. */
type StepNode = { kind: 'base'; phase: BasePhase } | { kind: 'office'; code: OfficeFormCode }

/**
 * Stable identity for a step. Positions shift the moment a clearance is
 * applied for or withdrawn — its form sheet slots into the middle of the map —
 * so anything remembered about a step has to be remembered by name.
 * Remembering it by index is how a sheet nobody had opened inherited the state
 * of the one that used to sit at that number.
 */
function stepKey(n: StepNode): string {
  return n.kind === 'base' ? n.phase : `office:${n.code}`
}

/**
 * Document-type code prefix the API gives a clearance the applicant already
 * holds (DocumentController::heldPermitDocumentType).
 *
 * Kept only so a reopened draft can SKIP those attachments in Documentary
 * Requirements. A clearance copy is not a documentary requirement of the
 * business permit and never was: it belongs to its card on the LGU Clearances
 * step, which reads the filing's held copies for itself.
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
 * One selected line of business: a PSIC code and the free-text trade the
 * applicant types when they pick "Other (not listed)".
 *
 * No capitalization here. It used to be asked twice — once against each line in
 * this picker and again in Business & Tax Profile — and only the second answer
 * was ever assessed, so the two drifted the moment anyone edited the first.
 * Across 400 filed applications not one pair disagreed, which is what one
 * question asked twice looks like. It is now asked in Business & Tax Profile
 * alone, beside the two other per-line fee questions (category, gross sales);
 * `business_lines.capitalization` is filled from there by the API.
 */
interface LineDraft {
  psic_code_id: number
  line_of_business: string
  /*
   * What this line actually sells or does. The PSIC title names the TRADE
   * ("Retail sale in non-specialised stores"); this names the goods, and both
   * BPLO forms and CENRO's CEC application print it as its own column of the
   * line-of-business table. Per line rather than per business for the reason the
   * paper puts it in that table: a shop that both retails and repairs sells
   * different things under each of its two lines.
   */
  products_services: string
}

interface FormState {
  name: string
  trade_name: string
  registration_type: string
  registration_number: string
  tin: string
  /* BPLO items A6 and A9 — the main office's landline and website. */
  telephone: string
  website: string
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
  /* BPLO item B6 — see ECONOMIC_ORGANIZATIONS below for why it matters. */
  economic_organization: string
  economic_organization_others: string
  /* BPLO items A13/A14/A15, asked only of the structures that have a president. */
  president_officer_name: string
  citizenship: string
  capital_participation_filipino: string
  /* BPLO item B8 (new form) / B7 (renewal). */
  has_tax_incentives: boolean
}

const EMPTY: FormState = {
  name: '',
  trade_name: '',
  registration_type: '',
  registration_number: '',
  tin: '',
  telephone: '',
  website: '',
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
  economic_organization: '',
  economic_organization_others: '',
  president_officer_name: '',
  citizenship: '',
  capital_participation_filipino: '',
  has_tax_incentives: false,
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

/* ── Economic Organization (BPLO item B6) ───────────────────────────────── */

/**
 * The six answers the paper prints, in its own order.
 *
 * This is NOT the Form of Organization / Type of Registration question wearing a
 * second hat — that one asks what the business IS in law (sole proprietorship,
 * partnership, corporation, cooperative), and this asks what this PREMISES is to
 * the business. A corporation can file for a branch; a sole proprietor can file
 * for a single establishment that is also their main office.
 *
 * It is the structurally interesting one of the fields added here, because it is
 * the answer that says whether the two addresses the paper asks for are the same
 * place. The BPLO form has a Main Office Address (item A5) AND a Business
 * Location Address (item B5); BizTrack holds exactly one address per business
 * and prints it under "Main Office Address" on the officer's sheet. For a Single
 * Establishment that is correct and the duplication on paper is redundancy. For
 * a Branch or an Ancillary Unit it is wrong, and this field is what will
 * eventually tell us so — which is why it is worth collecting before the second
 * address exists to hang off it.
 */
const ECONOMIC_ORGANIZATIONS: { value: string; label: string; hint: string }[] = [
  {
    value: 'single_establishment',
    label: 'Single Establishment',
    hint: 'One place of business, and it is this one.',
  },
  {
    value: 'branch',
    label: 'Branch',
    hint: 'A branch of a business whose main office is somewhere else.',
  },
  {
    value: 'establishment_and_main_office',
    label: 'Establishment and Main Office',
    hint: 'You trade here and this is also your head office.',
  },
  {
    value: 'main_office_only',
    label: 'Main Office only',
    hint: 'Head office here; the trading happens elsewhere.',
  },
  {
    value: 'ancillary_unit',
    label: 'Ancillary Unit',
    hint: 'A warehouse, depot or similar that supports the business but does not sell.',
  },
  { value: 'others', label: 'Others', hint: 'None of the five above — say what it is.' },
]

/**
 * Whether items A13-A15 (President/OIC, their citizenship, and the Filipino
 * share of the capital) are asked at all.
 *
 * The paper routes item 11 (Sole Proprietorship) and item 12
 * (Corporation/Partnership/Cooperative) both onward to item 13, so on paper
 * everybody answers it. We do not, and the reason is the redundancy rule the
 * client set: for a sole proprietorship the account holder IS the proprietor and
 * IS the officer in charge, so item 13 asks a question the registration already
 * answered. Item 14 settles it — the paper labels it "Citizenship (of
 * President/OIC)", so all three fields describe one person, and where there is
 * no separate president there is nobody for them to be about.
 *
 * A blank structure returns false: nothing is asked until the applicant has said
 * which of the four they are, on the same reasoning as the registration-number
 * field directly above these.
 */
function hasPresidentOrOfficer(registrationType: string): boolean {
  return ['partnership', 'corporation', 'cooperative'].includes(registrationType)
}

/**
 * A saved capital participation, as the applicant would have typed it.
 *
 * The column is `decimal(5,2)` and cast to match, so "100" is stored and comes
 * back as "100.00". Re-hydrating that verbatim would show somebody a number they
 * did not type every time they reopened a draft, and would do it again on every
 * renewal. Only a meaningless trailing zero pair is dropped: 60.50 keeps its
 * decimals because those are the applicant's own precision.
 */
function percentToInput(raw: string | null | undefined): string {
  const trimmed = (raw ?? '').trim()

  return trimmed.endsWith('.00') ? trimmed.slice(0, -3) : trimmed
}

/** 0-100 with up to two decimals, or blank. Percentages are not money. */
function percentValid(raw: string): boolean {
  const trimmed = raw.trim()
  if (!trimmed) return true
  if (!/^\d{1,3}(\.\d{1,2})?$/.test(trimmed)) return false
  const n = Number(trimmed)

  return Number.isFinite(n) && n >= 0 && n <= 100
}

/* ── Type of registration, and the agency it decides (item 94) ──────────── */

type RegistrationAgency = 'DTI' | 'SEC' | 'CDA'

/**
 * The four structures, each with the agency that registers it.
 *
 * The mapping is many-to-one on purpose: the SEC registers partnerships AND
 * corporations, so the agency can always be read off the structure but never the
 * other way round. That asymmetry is the whole of checklist item 94 — the form
 * used to ask for a "DTI / SEC / CDA Registration Number" BEFORE asking which of
 * the three the applicant is registered with, so it was asking for a number
 * without knowing whose number it wanted, and nothing checked that the two
 * answers agreed. The structure is asked first now and the number field follows
 * it.
 *
 * The API stores the structure in `businesses.registration_type` and derives the
 * agency the same way (Business::REGISTRAR_BY_FORM).
 */
const REGISTRATION_TYPES: { value: string; label: string; agency: RegistrationAgency }[] = [
  { value: 'sole_proprietorship', label: 'Sole Proprietorship', agency: 'DTI' },
  { value: 'partnership', label: 'Partnership', agency: 'SEC' },
  { value: 'corporation', label: 'Corporation', agency: 'SEC' },
  { value: 'cooperative', label: 'Cooperative', agency: 'CDA' },
]

/**
 * How each agency's number is asked for. The label is what the input is called
 * once the structure is known — a screen reader must never be left announcing
 * "DTI / SEC / CDA Registration Number" at a field that now means one of them.
 *
 * `hint` is illustrative, never enforced. The SEC and CDA examples are real
 * shapes read off those agencies' own published registers. DTI deliberately has
 * none: DTI publishes no format anywhere — not in its Citizen's Charter, not in
 * the BNRS FAQ, not in the IRR — and no authoritative specimen could be
 * verified, so an invented example would teach applicants a shape nobody can
 * stand behind. It points at the certificate instead.
 */
const REGISTRATION_AGENCIES: Record<
  RegistrationAgency,
  { label: string; placeholder: string; hint: string }
> = {
  DTI: {
    label: 'DTI Business Name Registration Number',
    placeholder: 'as printed on your DTI certificate',
    hint: 'Issued by the Department of Trade and Industry. Copy it from your Certificate of Business Name Registration.',
  },
  SEC: {
    label: 'SEC Registration Number',
    placeholder: 'e.g. CS201912345',
    hint: 'Issued by the Securities and Exchange Commission. It looks like CS201912345, though older certificates use other prefixes.',
  },
  CDA: {
    label: 'CDA Registration Number',
    placeholder: 'e.g. 9520-15005879',
    hint: 'Issued by the Cooperative Development Authority. It looks like 9520-15005879, though the digits after the dash vary in length.',
  },
}

/** The agency that registers a structure, or null while none is chosen. */
function agencyFor(registrationType: string): RegistrationAgency | null {
  return REGISTRATION_TYPES.find((rt) => rt.value === registrationType)?.agency ?? null
}

/**
 * Read a stored `registration_type` as one of the four structures.
 *
 * A renewal, an amendment or a reopened draft prefills this field from the
 * business on record, and rows written before item 94 hold the registering
 * AGENCY there instead — "DTI", "SEC", "CDA". Left as-is, "DTI" is a truthy
 * string that satisfies the required check while matching none of the four
 * buttons: the question looks unanswered but the step lets you past, and the
 * value is then mirrored into fee_profile.business_structure, which accepts only
 * the four, so every autosave on that filing answers 422 in silence.
 *
 * DTI and CDA each register exactly one structure, so those translate. "SEC"
 * does not — it covers partnership and corporation — so it comes back blank and
 * the applicant is asked to confirm which they are. Blank is the honest answer;
 * guessing "corporation" would be inventing a fact about their company.
 */
function normalizeRegistrationType(raw: string | null | undefined): string {
  const value = (raw ?? '').trim()
  if (!value) return ''
  if (REGISTRATION_TYPES.some((rt) => rt.value === value)) return value
  if (value.toUpperCase() === 'DTI') return 'sole_proprietorship'
  if (value.toUpperCase() === 'CDA') return 'cooperative'
  return ''
}

/**
 * Is this plausibly a registration number at all?
 *
 * Deliberately loose, and the same rule for all three agencies — the looseness
 * is evidence-based, not lazy. SEC's own published registers carry more than
 * twenty distinct shapes (CS/A/AS/ASO/CEO prefixes with 7 to 11 digits, bare
 * numerics from 4 digits up, trailing letters like CS200729932-A, embedded
 * hyphens like ASO91-195123). CDA's current masterlist runs three formats at
 * once — "9520-" plus 8, 12 or 16 digits — plus a "10744-" series. DTI
 * publishes no format at all. So any regex tight enough to catch a wrong answer
 * would also refuse certificates real businesses are holding, and a refused
 * applicant cannot file at all, while a malformed number is caught by the
 * officer who opens the uploaded certificate.
 *
 * What differs per agency is the label, the example and the wording of the
 * error — not what is accepted.
 *
 * So this only asserts the value looks like a reference rather than a sentence:
 * the characters these numbers are printed with, at least one digit (every
 * specimen in every register has one), and at least four characters — the
 * shortest real reference found anywhere, SEC's "1074". BusinessController
 * applies the identical rule.
 */
function registrationNumberValid(raw: string): boolean {
  const trimmed = raw.trim()
  return trimmed.length >= 4 && /^(?=.*\d)[A-Za-z0-9][A-Za-z0-9 .\-/]*$/.test(trimmed)
}

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

/**
 * BPLO item A9. Loose on purpose: it accepts what somebody would type into a
 * browser, with or without a scheme and with or without www. What it refuses is
 * a sentence or an email address, which is the mistake this field actually
 * attracts — the point is to catch a wrong KIND of answer, not to police a URL.
 */
function websiteValid(raw: string): boolean {
  const trimmed = raw.trim()
  if (!trimmed) return true
  if (trimmed.includes('@') || /\s/.test(trimmed)) return false

  return /^(https?:\/\/)?[a-z0-9-]+(\.[a-z0-9-]+)+(\/\S*)?$/i.test(trimmed)
}

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
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)

  /*
   * "Other (not listed)" is deliberately not offered.
   *
   * It looked like a kindness and behaved like a hole. Picking it stored the
   * catch-all PSIC row (code 00000) with a NULL `category`, and 35 of the 36
   * business-tax rules match on `business_category` — so a line filed under
   * Other matched none of them and was assessed no business tax at all. It also
   * came back from Location Insights as unclassifiable, so that applicant got
   * no nearby-trade figures either.
   *
   * Every business has a line; 135 PSIC codes is enough to find it, and the
   * search is there to find it with. A trade genuinely missing from the list is
   * a gap in the reference data to fix at the source, not something to let an
   * applicant type into a free-text box that nothing downstream can read.
   *
   * Rows already filed under it still render below — see the Selected list.
   */
  /*
   * ── Item 104b · every trade is reachable, and nothing is cut in silence ──
   *
   * Two caps used to stand between an applicant and the list. With the box
   * empty they saw the eight-code COMMON_PSIC_CODES shortlist and nothing else,
   * so browsing simply could not reach trade nine of 135. With a query typed
   * the matches were sliced to 25 and the slice was never mentioned — "sale"
   * matches 48 titles, so twenty-three real trades were dropped off the bottom
   * with no sign they had ever existed. An applicant whose trade was among them
   * concluded it was not on the list.
   *
   * Both are gone. The empty box now opens on the common eight and then
   * continues into every remaining code, so the shortlist is a head start
   * rather than a gate; a query returns all of its matches. 135 rows in a
   * scrolling 16rem box is a list, not a page — the cap was solving a layout
   * problem the `overflow-y-auto` had already solved.
   *
   * `commonCount` is the boundary the "Most common" heading is drawn at, and it
   * is 0 while searching: relevance, not familiarity, orders a search result.
   */
  const { results, commonCount, total } = useMemo(() => {
    const q = query.trim().toLowerCase()
    const listed = codes.filter((c) => c.code !== OTHER_PSIC_CODE)
    if (q) {
      const matches = listed.filter(
        (c) => c.title.toLowerCase().includes(q) || c.code.includes(q),
      )

      return { results: matches, commonCount: 0, total: listed.length }
    }

    const common = COMMON_PSIC_CODES.map((code) => listed.find((c) => c.code === code)).filter(
      (c): c is PsicCode => c !== undefined,
    )
    const promoted = new Set(common.map((c) => c.id))

    return {
      results: [...common, ...listed.filter((c) => !promoted.has(c.id))],
      commonCount: common.length,
      total: listed.length,
    }
  }, [codes, query])

  /*
   * Closes on a click elsewhere and on Escape. The list used to be permanently
   * open, so ten trades and a Selected panel pushed the map and everything
   * under it off the screen — on a step whose whole job is picking a location.
   */
  useEffect(() => {
    if (!open) return
    function onPointerDown(e: PointerEvent) {
      if (!box.current?.contains(e.target as Node)) setOpen(false)
    }
    function onKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('pointerdown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('pointerdown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  function toggle(code: PsicCode) {
    const exists = lines.find((l) => l.psic_code_id === code.id)
    if (exists) onChange(lines.filter((l) => l.psic_code_id !== code.id))
    else onChange([...lines, { psic_code_id: code.id, line_of_business: '', products_services: '' }])
  }

  /*
   * Named, not counted. "Selected (2)" confirms that something happened; it
   * does not confirm that the RIGHT thing happened, and the two sari-sari rows
   * in this list differ only by the words in their brackets.
   */
  const selectedTitles = lines
    .map((l) => codes.find((c) => c.id === l.psic_code_id)?.title)
    .filter(Boolean)
    .join(', ')

  return (
    <div className="space-y-4">
      {/* relative: the results hang over what follows instead of shoving it down. */}
      <div ref={box} className="relative">
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
            role="combobox"
            aria-expanded={open}
            aria-controls="psic-results"
            aria-autocomplete="list"
            value={query}
            onFocus={() => setOpen(true)}
            onChange={(e) => {
              setQuery(e.target.value)
              setOpen(true)
            }}
            placeholder="e.g. retail, food, salon"
            className={`${inputCls} pl-10`}
          />
        </div>

        {open && (
          /*
           * z-[1100] rather than a small z-index because the map sits directly
           * below this and Leaflet builds its own stacking world: tile and
           * marker panes at 200-600, controls at 1000. At z-20 the list
           * rendered *under* the map — the results were sliced in half and the
           * panel's white showed through beneath it, which read as a layout bug
           * rather than a dropdown.
           */
          <div className="absolute z-[1100] mt-1 w-full overflow-hidden rounded-lg border border-input-border bg-white shadow-lg">
            {/*
              * ── Item 104a · the confirmation you can actually see ──────────
              *
              * "The selected line of business does not reflect after choosing."
              * It did reflect — in two places the applicant could not see. The
              * row's checkbox ticks inside a list they are still reading, and
              * the "Selected (N)" panel is directly BELOW this dropdown, which
              * is absolutely positioned and up to 16rem tall and therefore
              * sitting on top of it. `document.elementFromPoint` over the
              * "Selected (1)" heading returns a result row, not the heading: at
              * the moment of the click the only confirmation on screen was a
              * 20px tick inside a list of identical rows.
              *
              * So the confirmation is put where the eye already is — pinned to
              * the top of the open list, against the tinted background, naming
              * what is now selected. It cannot be covered by the dropdown
              * because it is part of it, and it needs no scrolling because it
              * is directly under the box being typed in.
              *
              * The panel below keeps its job (removing a line, describing its
              * products) and takes over the moment the list closes.
              */}
            {lines.length > 0 && (
              <p className="border-b border-line bg-royal-tint px-4 py-2.5 text-xs font-semibold text-royal">
                <span className="mr-1.5 inline-flex h-4 w-4 translate-y-0.5 items-center justify-center rounded-sm bg-royal text-white">
                  <CheckIcon size={11} />
                </span>
                Selected ({lines.length}):{' '}
                <span className="font-normal text-ink">{selectedTitles}</span>
              </p>
            )}

            <ul id="psic-results" className="max-h-64 divide-y divide-line overflow-y-auto">
              {results.length === 0 ? (
                <li className="px-4 py-4 text-sm text-ink-secondary">
                  No trade matches “{query.trim()}”. Try a plainer word — “food” rather than the
                  dish, “retail” rather than the goods.
                </li>
              ) : (
                results.map((code, index) => {
                  const selected = lines.some((l) => l.psic_code_id === code.id)
                  return (
                    <Fragment key={code.id}>
                      {/*
                        * The shortlist is a head start, not a fence, so it says
                        * which it is and where it ends. Without the second
                        * heading the ninth row looks like more of the same and
                        * an applicant who has read eight stops reading.
                        */}
                      {commonCount > 0 && index === 0 && (
                        <li className="bg-shell px-4 py-1.5 text-[11px] font-bold uppercase tracking-[0.1em] text-ink-secondary">
                          Most common
                        </li>
                      )}
                      {commonCount > 0 && index === commonCount && (
                        <li className="bg-shell px-4 py-1.5 text-[11px] font-bold uppercase tracking-[0.1em] text-ink-secondary">
                          All other trades ({total - commonCount})
                        </li>
                      )}
                      <li>
                        <button
                          type="button"
                          onClick={() => toggle(code)}
                          aria-pressed={selected}
                          className={`flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors ${
                            selected ? 'bg-input' : 'hover:bg-royal-tint'
                          }`}
                        >
                          <span
                            className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-sm border ${
                              selected
                                ? 'border-royal bg-royal text-white'
                                : 'border-input-border bg-white'
                            }`}
                          >
                            {selected && <CheckIcon size={13} />}
                          </span>
                          <span className="min-w-0">
                            <span className="block text-sm font-medium text-ink">{code.title}</span>
                            <span className="tnum block text-xs text-ink-secondary">
                              PSIC {code.code}
                            </span>
                          </span>
                        </button>
                      </li>
                    </Fragment>
                  )
                })
              )}
            </ul>

            {/*
              * Item 104b — the count, stated. Nothing is cut any more, and
              * saying so is the half of the fix that stops an applicant giving
              * up: "8 shown" out of 135 with no total was indistinguishable
              * from "your trade is not on the list".
              */}
            {results.length > 0 && (
              <p className="border-t border-line bg-white px-4 py-2 text-xs text-ink-secondary">
                {query.trim()
                  ? `Showing all ${results.length} of ${total} trades matching “${query.trim()}”.`
                  : `Showing all ${total} trades — the most common first. Scroll for the rest.`}
              </p>
            )}
          </div>
        )}
      </div>

      {/*
        * The same confirmation for somebody who cannot see the panel at all.
        * Always mounted, so the region exists before it has anything to say —
        * a live region created together with its text is frequently missed.
        */}
      <p aria-live="polite" className="sr-only">
        {lines.length > 0 ? `Selected ${lines.length}: ${selectedTitles}` : 'No line of business selected'}
      </p>

      {lines.length > 0 && (
        <div className="rounded-lg border border-input-border bg-royal-tint p-4">
          <p className="mb-3 text-sm font-bold text-ink">Selected ({lines.length})</p>
          <div className="space-y-3">
            {lines.map((line) => {
              const code = codes.find((c) => c.id === line.psic_code_id)
              /*
               * Other is no longer offered, but filings made before it was
               * withdrawn still carry it — a renewal or a reopened draft can
               * arrive holding one. Those keep their typed text, shown as the
               * line's name and no longer editable, because the answer cannot
               * be improved in place: what it needs is a real PSIC code, which
               * means removing the row and picking one.
               */
              const isOther = code?.code === OTHER_PSIC_CODE
              const needsText = isOther && !line.line_of_business.trim()
              /*
               * A "Capital (₱)" box used to sit here, one per line, and it is
               * deliberately gone — do not put it back.
               *
               * Business & Tax Profile asked the same thing on step 5 and only
               * that answer ever reached the fee engine, so editing this one
               * changed nothing and the two silently diverged. Capital is a fee
               * input: it belongs beside the category and the gross sales it is
               * assessed with. What is left here is place and trade, which is
               * what CPDO actually rules on — and this step was already the
               * heaviest in the wizard, carrying a map, an address, this picker,
               * the rent details and an emergency contact.
               */
              return (
                <div key={line.psic_code_id}>
                  <div className="flex items-start gap-3">
                    <div className="min-w-0 flex-1">
                      {isOther ? (
                        <div>
                          <p className="truncate text-sm text-ink">
                            {line.line_of_business.trim() || 'Unclassified line'}
                          </p>
                          <p className="mt-0.5 text-xs text-s-red">
                            Not on the PSIC list, so this line cannot be assessed. Remove it and
                            search for the closest trade.
                          </p>
                        </div>
                      ) : (
                        <p className="truncate text-sm text-ink">{code?.title}</p>
                      )}
                    </div>
                    <button
                      type="button"
                      onClick={() => onChange(lines.filter((l) => l.psic_code_id !== line.psic_code_id))}
                      className="shrink-0 text-sm font-semibold text-s-red underline underline-offset-2"
                    >
                      Remove
                    </button>
                  </div>
                  {needsText && (
                    <p className="mt-1 text-xs font-medium text-s-red">
                      Type the line of business you want registered.
                    </p>
                  )}
                  {/*
                    * Products / Services used to sit here, and no longer does.
                    *
                    * It is the second column of the paper's line-of-business
                    * table on both BPLO forms and on CENRO's CEC application,
                    * so it was added to match the paper. In use it read as
                    * clutter: a third row of chrome under every trade you pick,
                    * on the step that is already the heaviest in the wizard,
                    * for an answer no form marks required.
                    *
                    * The column, the API validation and the officer's review
                    * sheet all still handle it — nothing was torn out — so if
                    * BPLO says the counter needs it, it comes back somewhere
                    * quieter rather than being rebuilt. Recorded in
                    * docs/questions-for-malabon.md.
                    */}
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
   * Sections the applicant has actually opened, by step key. The map is
   * clickable for these. A TICK is a different question entirely: it means the
   * section is complete, and is computed fresh from the answers every render,
   * so it can never outlive the answers that earned it.
   */
  const [visited, setVisited] = useState<string[]>([BASE_PHASES[0]])
  const markVisited = (key: string) => setVisited((v) => (v.includes(key) ? v : [...v, key]))

  /*
   * ── The LGU Clearances step's state ───────────────────────────────────
   *
   * The six rows live on the server, not in this form. Applying for a
   * clearance attaches its permit type to the draft through
   * /clearances/{code}/apply, so it survives a reload and a reopened draft
   * without the wizard having to carry it — and the fee preview on each card
   * is priced by the same FeeCalculator that will produce the Tax Order of
   * Payment. `clearanceRows` is the copy this component reads to decide which
   * office sheets are steps and whether the step passes.
   *
   * `setClearanceRows` is handed to <ClearanceStage> directly rather than
   * wrapped in an arrow: the stage reloads whenever that callback's identity
   * changes, and a new arrow on every render would be an infinite fetch.
   */
  const [clearanceRows, setClearanceRows] = useState<Clearance[]>([])
  /* Answers on the per-office sheets, by permit-type code. */
  const [officeData, setOfficeData] = useState<Record<string, OfficeFormData>>({})
  /*
   * Parts of the sheets are derived server-side from the permits on the filing
   * (the FSIC "Certificate Applied For", the sanitary and CEC application
   * types), so applying for a clearance mid-session leaves those fields blank
   * until they are refetched. Bumped when a sheet is opened from a card.
   */
  const [officeFormsVersion, setOfficeFormsVersion] = useState(0)
  /*
   * Apply opens that office's sheet, but the sheet is only a step once the row
   * says the clearance is applied for — which is a render later, after the
   * stage has reloaded. `pendingOfficeJump` is the code we are on our way to;
   * `officeReturn` remembers that we arrived from a card, so the sheet's
   * forward button goes back to the cards instead of on down the wizard.
   */
  const [pendingOfficeJump, setPendingOfficeJump] = useState<OfficeFormCode | null>(null)
  const [officeReturn, setOfficeReturn] = useState(false)
  const [form, setForm] = useState<FormState>(EMPTY)
  /*
   * The applicant's own name for this filing. Blank is normal and means "call
   * it by the business name", which is what the header and the Drafts page do.
   */
  const [title, setTitle] = useState('')
  /*
   * Whether the applicant has named this filing themselves. Until they do, the
   * title is generated and kept in step with the business name — see
   * `suggestedTitle`. Once they type, we stop touching it: the whole point of
   * the field is that they can call it what they like.
   */
  const [titleEdited, setTitleEdited] = useState(false)

  /*
   * What to call this filing when nobody has said otherwise.
   *
   * It never returns empty. The first draft of this only generated once the
   * business name existed, which is a step later than the box appears — so on
   * the first two steps the header showed a grey truncated instruction reading
   * "Named automatically once you a…", which is worse than the blank box it
   * replaced. A box that holds a real name from the outset needs no caption
   * explaining itself.
   *
   * The year is in it because renewals and amendments repeat annually, and a
   * Drafts list holding three years of "Renewal — Nena's Sari-Sari Store" says
   * nothing about which is which. A new permit happens once per business, so it
   * is not dated.
   */
  const suggestedTitle = useMemo(() => {
    const business = form.name.trim()
    const year = new Date().getFullYear()
    const named = (base: string) => (business ? `${base} — ${business}` : base)

    switch (applicationType) {
      case 'renewal':
        return named(`${year} Renewal`)
      case 'amendment':
        return named(`${year} Amendment`)
      default:
        return named('New Business Permit')
    }
  }, [form.name, applicationType])

  useEffect(() => {
    if (titleEdited || suggestedTitle === '') return
    setTitle(suggestedTitle)
  }, [suggestedTitle, titleEdited])
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
        // Item 94: a business registered before the structure and the agency
        // were untangled can still hold "DTI"/"SEC"/"CDA" here. Read it as a
        // structure, or blank so the applicant is asked — never guessed.
        registration_type: normalizeRegistrationType(b.registration_type),
        registration_number: b.registration_number ?? '',
        tin: b.tin ?? '',
        telephone: b.address.telephone ?? '',
        website: b.address.website ?? '',
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
        economic_organization: b.economic_organization ?? '',
        economic_organization_others: b.economic_organization_others ?? '',
        president_officer_name: b.president_officer_name ?? '',
        citizenship: b.citizenship ?? '',
        capital_participation_filipino: percentToInput(b.capital_participation_filipino),
        has_tax_incentives: b.has_tax_incentives ?? false,
        latitude: b.address.latitude ?? null,
        longitude: b.address.longitude ?? null,
        /*
         * `l.capitalization` is deliberately not read. A renewal is assessed on
         * gross sales, not capital, so this wizard never asks the business's
         * figure again — and the API preserves the stored one through every
         * business update that omits it (BusinessController::syncAddressAndLines).
         */
        lines: b.lines.map((l) => ({
          psic_code_id: l.psic_code.id,
          // Carry over the free text for an "Other (not listed)" trade, or a
          // renewal would silently blank it and block Next.
          line_of_business: l.line_of_business ?? '',
          // Same reasoning: what the line sells rarely changes between years, so
          // a renewal starts from what is on record instead of blank. Dropping
          // it here would also let the next autosave write the blank back.
          products_services: l.products_services ?? '',
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
   * The business as every office sheet carries it. The LGU Clearances step
   * runs after Location & Zoning and Business Information precisely so this is
   * complete by the time a sheet opens — the sanitary, environmental, fire and
   * occupancy forms all begin by asking for the same name, address and trade,
   * and the applicant has answered all three already.
   *
   * A blank reads as "—" rather than an empty box: the sheets are reachable
   * from the section map, and an empty field looks like something the
   * applicant forgot to type here.
   */
  const carriedOverBusiness = useMemo(
    () => ({
      name: form.name.trim() || '—',
      tradeName: form.trade_name.trim(),
      address:
        [form.line1.trim(), form.line2.trim(), barangayName].filter(Boolean).join(', ') || '—',
      // The bracketed colloquial name is the half a shop owner recognises, as
      // in the zoning sentence. "Other" carries what they typed instead.
      lineOfBusiness:
        (declaredLine?.code === OTHER_PSIC_CODE
          ? form.lines[0]?.line_of_business.trim()
          : declaredLine?.title) || '—',
    }),
    [form.name, form.trade_name, form.line1, form.line2, form.lines, barangayName, declaredLine],
  )

  /*
   * Which office sheets this filing needs, in the canonical office order.
   *
   * Only the clearances APPLIED FOR. Submitting a copy already held is the
   * other half of the card and it skips the office's form entirely — that
   * asymmetry is the whole point of the two buttons, so a sheet for a
   * clearance the applicant already holds would be a step asking them to apply
   * for something they told us they have.
   */
  const selectedOfficeCodes: OfficeFormCode[] = useMemo(() => {
    const applied = new Set(
      clearanceRows
        .filter((r) => r.state === 'applied' || r.state === 'issued')
        .map((r) => r.permit_type.code),
    )
    return OFFICE_FORM_CODES.filter((c) => applied.has(c))
  }, [clearanceRows])

  /*
   * Full step sequence. Each office form sheet slots in directly behind the
   * LGU Clearances step that spawned it, so applying for a clearance and
   * filling in its sheet are one movement rather than two halves of the flow
   * with Review in between.
   */
  const sequence: StepNode[] = useMemo(() => {
    const nodes: StepNode[] = []
    for (const p of BASE_PHASES) {
      nodes.push({ kind: 'base', phase: p })
      if (p === 'clearances') {
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

  /* Withdrawing a clearance removes its sheet: don't leave `step` past the end. */
  useEffect(() => {
    setStep((s) => Math.min(s, sequence.length - 1))
  }, [sequence.length])

  /*
   * A sheet exists because its clearance was applied for, which is a
   * deliberate act — so the map offers it straight away rather than only after
   * the applicant has walked into it once. This is also what makes a reopened
   * draft's sheets reachable: they are read from the clearance rows, which
   * arrive after hydration has already marked the base phases.
   */
  useEffect(() => {
    if (selectedOfficeCodes.length === 0) return
    const keys = selectedOfficeCodes.map((code) => `office:${code}`)
    setVisited((v) => {
      const missing = keys.filter((k) => !v.includes(k))
      return missing.length === 0 ? v : [...v, ...missing]
    })
  }, [selectedOfficeCodes])

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
   * What Review & Submit says about the clearances: only the ones decided, and
   * which way. "Applied for" and "copy on file" are opposite instructions to
   * the office and cost different amounts, so a list that flattened them into
   * "six clearances included" would be the last screen before submission
   * quietly misdescribing the filing.
   */
  const clearanceDecisions = useMemo(
    () =>
      clearanceRows
        .filter((r) => r.state !== 'available' || r.held_document !== null)
        .map((r) => ({
          code: r.permit_type.code,
          text:
            r.held_document !== null
              ? `${r.permit_type.name} — your copy is on file, nothing charged`
              : `${r.permit_type.name} — applied for`,
        })),
    [clearanceRows],
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
        /*
         * Item 76's other half — a clearance must actually be DECIDED here.
         *
         * The cards are additive, so an applicant could walk past this section
         * without touching it and the step would still pass. The client's
         * point is that walking past it is not a decision: the sanitary, fire,
         * environmental and occupancy clearances are the substance of the
         * filing, and a file that reaches BPLO with none of them named is one
         * the counter has to send back.
         *
         * Either half of the card satisfies it — see anyClearanceDecided.
         *
         * ITEM 98 — "Market clearance should not be required. It is only
         * required for stall holders." It is not required, and never was by
         * this branch: the test is "at least one of the six", so it is
         * satisfied by Zoning alone and names no clearance at all. Nothing
         * elsewhere requires MARKET specifically either — the check is written
         * up on APPLICABILITY in ClearanceStagePage.
         *
         * Which is why the answer to item 98 is on the card and not here. The
         * complaint was that six identical cards read as six obligations, so
         * the Market Clearance card now says who it is for; this rule stays as
         * loose as it was. Do not turn this into a per-business set of required
         * codes without an answer to A1 in docs/questions-for-malabon.md — that
         * would be BPLO determining the clearances, which is the open question
         * the whole chooser is built on top of.
         *
         * The sentence below is what the "still needed on this part" summary
         * reads out, so it opens with the rule and not with a clearance's name.
         */
        case 'clearances':
          return anyClearanceDecided(clearanceRows)
            ? []
            : ['At least one clearance — Apply for it, or Submit the copy you already hold']
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
          /*
           * Item 94 — the structure is listed FIRST, and the number is named
           * after the agency that structure implies.
           *
           * Order matters here, not just on screen: this list is what the
           * "still missing" summary reads out, and telling somebody to enter a
           * registration number before telling them to say whose number it is
           * asks the two questions in the order that caused the item.
           *
           * `agencyFor` returning null is also the guard against the older bug:
           * a prefilled "DTI" is a truthy string, so `!form.registration_type`
           * called the question answered while none of the four buttons was lit.
           * Asking the mapping instead means only a real structure counts.
           */
          const agency = agencyFor(form.registration_type)
          if (agency === null) missing.push('Type of Registration')
          const numberLabel = agency
            ? REGISTRATION_AGENCIES[agency].label
            : 'Your registration number'
          if (!form.registration_number.trim()) missing.push(numberLabel)
          else if (!registrationNumberValid(form.registration_number)) {
            missing.push(`A valid ${numberLabel}`)
          }
          if (!form.tin.trim()) missing.push('Tax Identification Number (TIN)')
          else if (!tinValid(form.tin)) missing.push('A valid TIN (9 digits, plus branch code)')
          /*
           * The fields transcribed from the paper BPLO form are all optional —
           * none of the three paper forms marks any field required, and every
           * asterisk in this wizard is our own judgement. So nothing below is
           * listed for being blank; they are listed only when what is in them
           * cannot be stored, on the same pattern as the emergency contact
           * number on the Location & Zoning step.
           */
          if (form.telephone.trim() && !phoneValid(form.telephone)) {
            missing.push('A valid Telephone (Landline)')
          }
          if (form.website.trim() && !websiteValid(form.website)) {
            missing.push('A valid Website Address')
          }
          if (!percentValid(form.capital_participation_filipino)) {
            missing.push('A Capital Participation between 0 and 100 percent')
          }
          /*
           * The one exception, and it is conditional rather than new: on the
           * paper, "Others ____" is a blank you cannot tick without filling in.
           * Ticking it here and leaving the blank empty records less than
           * choosing nothing at all would have.
           */
          if (form.economic_organization === 'others' && !form.economic_organization_others.trim()) {
            missing.push('What “Others” means for your Economic Organization')
          }
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
          /*
           * Any line still filed under "Other" blocks the step, not just an
           * empty one. Other is no longer offered, but a renewal or a reopened
           * draft can arrive holding one, and it carries a NULL revenue-code
           * category — 35 of the 36 business-tax rules match on that category,
           * so the line would be assessed no business tax at all. Letting it
           * through would issue a permit that had not been charged for.
           */
          const otherId = psic.find((c) => c.code === OTHER_PSIC_CODE)?.id
          if (otherId !== undefined && form.lines.some((l) => l.psic_code_id === otherId)) {
            missing.push('A real PSIC trade in place of the unclassified line')
          }
          /*
           * "Capital for every line of business" was checked here and no longer
           * is. The question moved to Business & Tax Profile, where the `fees`
           * phase below requires it per line for a new filing (feeProfileIssues
           * → `line:<id>:capitalization`). Blocking on it twice would be the
           * duplicate wearing a different hat.
           */
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
      officeData,
      // Item 76: the step passes on a clearance being decided, so its tick has
      // to move when the cards do.
      clearanceRows,
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
  }, [missingFor, sequence])

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

  /*
   * Item 94 — the agency the chosen structure is registered with, and how that
   * agency's number is described. Null until a structure is chosen, which is
   * the state the whole item exists to make possible: the number cannot be
   * asked for before we know whose number it is.
   *
   * Everything the number field says about itself — its <label>, its
   * placeholder, its live description and its error — reads from this one
   * place, so the four can never disagree about which agency is being asked
   * about.
   */
  const registrationAgency = agencyFor(form.registration_type)
  const registrationAgencyInfo = registrationAgency ? REGISTRATION_AGENCIES[registrationAgency] : null
  const registrationNumberLabel = registrationAgencyInfo?.label ?? 'Registration Number'
  /*
   * Whether to put BPLO items A13-A15 at all. Gated on the Type of Registration
   * chosen a few fields above — see hasPresidentOrOfficer for why a sole
   * proprietor is not asked to name their own president.
   */
  const presidentAsked = hasPresidentOrOfficer(form.registration_type)

  /**
   * Item 94 — choosing a structure, and what that does to the number already
   * typed.
   *
   * If the AGENCY changes, the number underneath it is now answering a
   * different question: a DTI Business Name number is not the applicant's SEC
   * registration number, and leaving it in place would submit one agency's
   * reference under another agency's label — exactly the mismatch this item
   * exists to stop. So it is cleared, and the field below asks again with its
   * new label.
   *
   * If the agency does NOT change it is kept. Partnership and Corporation are
   * both registered with the SEC, so switching between them is a correction to
   * the structure and says nothing about the number; clearing it there would
   * punish the applicant for fixing an unrelated answer.
   */
  function chooseRegistrationType(next: string) {
    const from = agencyFor(form.registration_type)
    const to = agencyFor(next)
    setForm((f) => ({
      ...f,
      registration_type: next,
      registration_number: from !== null && from !== to ? '' : f.registration_number,
    }))
    if (from !== null && from !== to) setTouched((t) => ({ ...t, registration_number: false }))
  }

  const fieldErrors = {
    name: touched.name && !form.name.trim() ? 'Enter your business name.' : '',
    /*
     * Nothing to complain about before a structure is chosen: the field is not
     * being asked yet, and an error on a question that has not been put is just
     * noise. Once it is asked, the error names that one agency rather than
     * listing all three.
     */
    registration_number: !registrationAgencyInfo
      ? ''
      : form.registration_number.trim()
        ? registrationNumberValid(form.registration_number)
          ? ''
          : `Enter your ${registrationNumberLabel} as it is printed on your certificate — letters, numbers, spaces and dashes, and at least one digit.`
        : touched.registration_number
          ? `Enter your ${registrationNumberLabel}.`
          : '',
    /*
     * Silent until the applicant has left the question (item 105).
     *
     * The format error used to appear on the first keystroke, because one digit
     * is a non-empty value that is not a valid TIN. That was merely untidy in a
     * single box; across four it paints the whole group red for the eleven
     * digits it takes to get to a right answer, which teaches the applicant to
     * ignore the colour. What is still needed is never hidden — the step's
     * "still needed on this part" list names the TIN from the start.
     */
    tin: !touched.tin
      ? ''
      : form.tin.trim()
        ? tinValid(form.tin)
          ? ''
          : TIN_ERROR
        : 'Enter your Tax Identification Number.',
    /*
     * Both optional, so neither can complain about being empty — only about
     * being wrong. `phoneValid` already accepts a landline with or without its
     * area code, which is what item A6 asks for, so there is no second phone
     * rule to keep in step with the first.
     */
    telephone: form.telephone.trim() && !phoneValid(form.telephone) ? PHONE_ERROR : '',
    website:
      form.website.trim() && !websiteValid(form.website)
        ? 'Enter your website as it is typed into a browser, like malabon.gov.ph or https://malabon.gov.ph.'
        : '',
    capital_participation_filipino: !percentValid(form.capital_participation_filipino)
      ? 'Enter the Filipino share as a percentage between 0 and 100, like 100 or 60.'
      : '',
    economic_organization_others:
      touched.economic_organization_others &&
      form.economic_organization === 'others' &&
      !form.economic_organization_others.trim()
        ? 'Say what kind of establishment this is, or choose one of the five above.'
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
      /*
       * Held as a plain string in FormState (it is the value of a radiogroup,
       * and "" is "nothing chosen"), narrowed here at the one boundary where the
       * contract cares. Only values from ECONOMIC_ORGANIZATIONS can reach it —
       * the radios are the sole writer — and the API bands it again against
       * Business::ECONOMIC_ORGANIZATIONS regardless.
       */
      economic_organization:
        (form.economic_organization as BusinessPayload['economic_organization']) || undefined,
      // Only meaningful against "Others"; sending it with any of the other five
      // would leave a stale specify-blank attached to an answer that has one.
      economic_organization_others:
        form.economic_organization === 'others'
          ? form.economic_organization_others.trim() || undefined
          : undefined,
      /*
       * Items A13-A15 ride on the payload only for the structures that have a
       * president. A sole proprietor who typed one before switching their Type
       * of Registration keeps it on screen for the rest of the session, but it
       * is not sent — the API reads an omitted key as null, so the record does
       * not end up asserting that a one-person shop has an officer in charge.
       */
      president_officer_name: presidentAsked
        ? form.president_officer_name.trim() || undefined
        : undefined,
      citizenship: presidentAsked ? form.citizenship.trim() || undefined : undefined,
      capital_participation_filipino: presidentAsked
        ? form.capital_participation_filipino.trim() || undefined
        : undefined,
      has_tax_incentives: form.has_tax_incentives,
      address: {
        line1: form.line1.trim(),
        line2: form.line2.trim() || undefined,
        barangay_id: Number(form.barangay_id),
        latitude: form.latitude ?? undefined,
        longitude: form.longitude ?? undefined,
        /*
         * `postal_code` is deliberately absent. Malabon is one postal code —
         * 1470 — and the map pin is already refused if it falls outside the
         * city, so the answer is known before the question could be put. The
         * API fills it, the same way the schema already defaults `city` and
         * `province`.
         */
        telephone: form.telephone.trim() || undefined,
        website: form.website.trim() || undefined,
      },
      /*
       * The free-text line rides on the same payload; the API stores it on
       * business_lines.line_of_business (contract addition, hence the cast).
       *
       * `capitalization` is not sent, and its absence is load-bearing rather
       * than an omission. This wizard no longer asks for it here — the applicant
       * declares it once in Business & Tax Profile — and the API fills
       * `business_lines.capitalization` from the fee profile instead
       * (ApplicationController::syncLineCapitalization). Omitting the key tells
       * syncAddressAndLines to keep whatever is on record, which is what stops
       * an autosave from wiping the figure between the two writes, and what lets
       * a renewal (assessed on gross sales, never asked for capital) round-trip
       * without losing the number it was registered with.
       */
      lines: form.lines.map((l) => ({
        psic_code_id: l.psic_code_id,
        line_of_business: l.line_of_business.trim() || undefined,
        // `business_lines.products_services` was already validated and stored by
        // BusinessController::syncAddressAndLines and already serialised by
        // BusinessResource — the wizard was the only part that never asked.
        products_services: l.products_services.trim() || undefined,
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
   * (and every map jump) saves: the business fields, the office sheets and the
   * fee profile all round-trip through the API.
   *
   * The LGU Clearances step has no branch, and that is not an omission. Every
   * one of its controls writes as it is pressed — applying attaches a permit
   * type, uploading a held copy stores a document — so there is nothing left
   * in the browser to flush when the applicant moves on.
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
      } else if (phase === 'address' || phase === 'business') {
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
    const target = Math.min(stepIndex + 1, sequence.length - 1)
    setStep(target)
    markVisited(stepKey(sequence[target]))
  }

  /** Move to a step we have decided to open, saving the one being left. */
  async function jumpTo(target: number) {
    const ok = await persistOnLeave()
    if (!ok) return
    setStep(target)
    markVisited(stepKey(sequence[target]))
  }

  /**
   * Finish the Apply → office sheet jump.
   *
   * It cannot happen in the click handler: the sheet is only in `sequence`
   * once the clearance row says it is applied for, which arrives a render
   * later when the stage reloads. Leaving the cards here also refetches the
   * sheets, because their derived answers ("Certificate Applied For") are the
   * server's to compute from the permit list that has only just changed —
   * arriving before that is what used to leave them blank.
   */
  useEffect(() => {
    if (pendingOfficeJump === null) return
    const target = sequence.findIndex(
      (n) => n.kind === 'office' && n.code === pendingOfficeJump,
    )
    // Apply-then-withdraw races: the sheet is gone, so there is nowhere to go.
    if (target === -1) return
    setPendingOfficeJump(null)
    setOfficeReturn(true)
    void jumpTo(target)
    // jumpTo is recreated every render and would loop; the guard above is what
    // makes this run once per Apply.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pendingOfficeJump, sequence])

  /*
   * Leaving the sheet any other way — Back, or a jump from the section map —
   * ends the round trip too. Otherwise the promise to return to the cards would
   * outlive the visit that made it and reappear on the next sheet opened.
   */
  useEffect(() => {
    if (!officeCode) setOfficeReturn(false)
  }, [officeCode])

  /** Back to the clearance cards from a sheet that was opened from them. */
  async function returnToClearances() {
    const target = sequence.findIndex((n) => n.kind === 'base' && n.phase === 'clearances')
    if (target === -1) return
    setOfficeReturn(false)
    await jumpTo(target)
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
    /*
     * Item 94: `!== ''` was not enough. A renewal prefilled from a pre-item-94
     * business used to put "DTI" here — truthy, so the draft was created, and
     * the same value then went to fee_profile.business_structure, which accepts
     * only the four structures, so every autosave after that answered 422 and
     * nothing the applicant typed reached the server. Requiring a value the
     * mapping recognises is what makes that impossible rather than unlikely.
     */
    registrationAgency !== null &&
    registrationNumberValid(form.registration_number) &&
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
        /*
         * `permit_type_ids` is deliberately NOT sent here.
         *
         * ApplicationController::update does `sync()` on whatever it is given,
         * and this wizard's `form.permit_type_ids` holds the Mayor's /
         * Business Permit alone. Sending it would detach every clearance the
         * applicant had just applied for on the LGU Clearances step — a
         * debounced autosave, a second and a half after they pressed Apply,
         * silently undoing it. The permit list belongs to the clearance
         * endpoints now; it is set once, on creation, and only they change it.
         */
        await applications.update(id, {
          title: title.trim(),
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
      // Every office sheet the applicant has touched, saved with everything
      // else. Empty ones are skipped: a sheet nobody has opened has nothing to
      // say, and writing it would report it as filled in on the card.
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
        // Typing on an office sheet is an edit like any other, so it has to
        // make the draft dirty and be flushed by the same debounce.
        officeData,
        feeDraft,
        paymentMode,
        applicationType,
        priorPermitId,
        // Items 82/84: ticking a box is an edit, so autosave has to see it.
        amendment,
      }),
    [title, form, officeData, feeDraft, paymentMode, applicationType, priorPermitId, amendment],
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
      setForm((f) => ({
        ...f,
        name: '',
        trade_name: '',
        registration_type: '',
        registration_number: '',
        tin: '',
        // Everything transcribed from the paper's section A and items B6/B8 is
        // an input of THIS part, so "clear the inputs on this part" has to take
        // it. Leaving any of them standing would clear the fields around an
        // answer and leave the answer behind — the bug the privacy branch below
        // documents, arriving from the other direction.
        telephone: '',
        website: '',
        economic_organization: '',
        economic_organization_others: '',
        president_officer_name: '',
        citizenship: '',
        capital_participation_filipino: '',
        has_tax_incentives: false,
      }))
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
    } else if (officeCode) {
      setOfficeData((d) => ({ ...d, [officeCode]: {} }))
    }
    /*
     * The LGU Clearances step deliberately gets no branch. Its "inputs" are
     * applications to other offices and certificates already uploaded, each of
     * which has its own named, individually confirmable control on its card —
     * withdrawing all six behind one "Proceed" is not what "clear the inputs
     * on this part" means to anybody reading it.
     */
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
         * `form.permit_type_ids` holds the Mayor's / Business Permit alone,
         * whatever the draft actually carries.
         *
         * It is not the wizard's copy of the permit list any more — it is only
         * what `ensureDraftRaw` posts when it CREATES an application, and the
         * business permit is the only thing a new draft starts with. The
         * clearances a reopened draft already has are read straight off the
         * server by the LGU Clearances step, which is the one place that
         * changes them. Restoring them into this field would put their
         * documents back into Documentary Requirements and their questions
         * back into the tax profile, both of which describe the business
         * permit alone.
         */
        const pts = refData.permitTypes
        const bizId = pts.find((pt) => pt.code === BUSINESS_PERMIT_CODE)?.id
        const ids = bizId === undefined ? [] : [bizId]
        const b = app.business
        const lineIds = (b.lines ?? []).map((l) => l.psic_code.id)
        setApplicationType(app.application_type)
        setApplicationId(app.id)
        setTitle(app.title ?? '')
        // A draft that arrives already named was named by somebody. Treat that
        // as the applicant's own words and stop generating over it, even if the
        // text happens to match what we would have produced.
        setTitleEdited(Boolean(app.title?.trim()))
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
          // Same normalisation as prefill (item 94): a draft saved against a
          // pre-item-94 business can carry an agency code here.
          registration_type: normalizeRegistrationType(b.registration_type),
          registration_number: b.registration_number ?? '',
          tin: b.tin ?? '',
          telephone: b.address?.telephone ?? '',
          website: b.address?.website ?? '',
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
          /*
           * Every field added from the paper forms has to come back, or the
           * next autosave writes the blank over the answer — a field that does
           * not round-trip is worse than one that was never asked.
           */
          economic_organization: b.economic_organization ?? '',
          economic_organization_others: b.economic_organization_others ?? '',
          president_officer_name: b.president_officer_name ?? '',
          citizenship: b.citizenship ?? '',
          capital_participation_filipino: percentToInput(b.capital_participation_filipino),
          has_tax_incentives: b.has_tax_incentives ?? false,
          latitude: b.address?.latitude ?? null,
          longitude: b.address?.longitude ?? null,
          /*
           * The capital a reopened draft declared is restored from the fee
           * profile below (`feeProfileToDraft`), which is where it was asked and
           * where the calculator reads it. Reading `l.capitalization` back into
           * the form here would resurrect the second copy this step no longer
           * owns — and the stored one is safe regardless, because a business
           * update that omits the figure leaves it alone.
           */
          lines: (b.lines ?? []).map((l) => ({
            psic_code_id: l.psic_code.id,
            // Free text typed against "Other (not listed)". Restoring it is what
            // stops a reopened draft from making the applicant type it again.
            line_of_business: l.line_of_business ?? '',
            products_services: l.products_services ?? '',
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
   * Read the clearance rows as soon as there is a draft, not only when the
   * step is opened.
   *
   * Two things depend on them from anywhere in the wizard: whether the LGU
   * Clearances step is finished (its tick, and whether a jump to Review is
   * allowed to step over it) and which office sheets exist. A reopened draft
   * whose clearances were all chosen last week would otherwise show the step
   * unticked and refuse to jump past it until the applicant had visited a
   * section they had already finished. <ClearanceStage> reloads for itself on
   * mount, so this is a head start rather than the only read.
   */
  useEffect(() => {
    if (!applicationId) return
    let active = true
    clearances
      .list(applicationId)
      .then((result) => {
        if (active) setClearanceRows(result.data)
      })
      .catch(() => {
        /* Non-fatal: the step opens and fetches for itself. */
      })
    return () => {
      active = false
    }
  }, [applicationId])

  /*
   * Load any previously-saved office-form payloads once the draft exists, and
   * again whenever a sheet is opened from a card. Parts of these sheets are
   * derived server-side from the permits on the filing (the FSIC "Certificate
   * Applied For", the sanitary and CEC application types), so applying for a
   * clearance mid-session leaves those fields blank until they are refetched.
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
   * There used to be an effect here that copied each line's capitalization
   * across from Location & Zoning on first entering Business & Tax Profile. It
   * is gone with the field it copied from. It only ever ran for a line with no
   * fee-profile row yet, so a later edit on the zoning step never reached the
   * figure that was actually assessed — the seeding is what made two questions
   * look like one, and hid that they had drifted apart.
   *
   * Nothing replaces it: FeeProfileStep falls back to an empty row for a line it
   * has not been given one for, and writes a real one on the first keystroke.
   */

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
          not a list anyone can use.

          But it should not have been an empty box either. It sat on step one,
          before there was a business to name, asking the applicant to invent a
          filing reference — a chore with a blank answer, and the first thing
          they met. So it names itself from the type and the business as soon as
          there is a business, and stays editable because renaming a draft is
          its own requirement (checklist item 36).
        */}
        <label className="min-w-0 flex-1">
          <span className="sr-only">
            Application title — named automatically, edit it if you want your own
          </span>
          <input
            value={title}
            onChange={(e) => {
              setTitle(e.target.value)
              setTitleEdited(true)
            }}
            maxLength={120}
            // Only ever seen if someone clears the box themselves.
            placeholder={suggestedTitle}
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
          const key = stepKey(n)
          const label = n.kind === 'base' ? BASE_LABELS[n.phase] : OFFICE_LABELS[n.code]
          const current = i === stepIndex
          const opened = visited.includes(key)
          const blocked = jumpBlocked(i)
          // A tick says "this section is finished", not "you have walked past
          // it": it comes from the answers, so clearing a section takes its
          // tick with it and a section skipped over never gets one.
          const done = opened && stepComplete[i]
          return (
            <li key={key}>
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

      {/* ── LGU Clearances (item 76 · the last part before submitting) ─── */}
      {phase === 'clearances' && (
        <div>
          <h1 className="display-serif mb-1 text-2xl text-ink-secondary">LGU Clearances</h1>
          <div className="mb-6 h-px bg-ink/40" />
          {/*
            The Mayor's / Business Permit is neither offered nor explained
            here: it is the outcome of this whole application, so it is always
            attached (permit_type_ids, on creation) and BPLO always receives
            the file. Only the six supporting clearances are a choice.

            A choice, and individually optional — item 98's "market clearance
            should not be required" holds for all six. The step asks for one
            decision, not six, and the cards say which businesses each is
            addressed to.

            The cards themselves are <ClearanceStage>, shared with the
            standalone /applications/:id/clearances route, so the Apply/Submit
            semantics cannot drift into two subtly different copies.
          */}
          {applicationId === null ? (
            <p className="text-sm text-ink-secondary">
              Finish the sections before this one first — your clearances are attached to this
              application, and it does not exist yet.
            </p>
          ) : (
            <ClearanceStage
              applicationId={applicationId}
              business={carriedOverBusiness}
              onRowsChange={setClearanceRows}
              /*
               * ITEM 98 — the Market Clearance card is derived, not offered to
               * everyone. Read off the LIVE fee draft rather than the saved
               * fee_profile: Business & Tax Profile is the step immediately
               * before this one, so a category typed a moment ago may not have
               * survived the autosave debounce yet, and that is exactly the
               * applicant whose card must already be there.
               */
              marketApplies={marketClearanceApplies(
                Object.values(feeDraft.categories).map((c) => c.category),
                feeDraft.stall_count,
              )}
              /*
               * Apply opens that office's sheet, and here the sheet is a STEP
               * of its own sitting immediately behind this one — not a panel
               * swapped in over the cards. The jump is finished by an effect,
               * because the sheet only joins the sequence once the reloaded
               * row says the clearance is applied for.
               */
              onOpenOfficeForm={(code) => {
                setOfficeFormsVersion((v) => v + 1)
                setPendingOfficeJump(code)
              }}
            />
          )}
        </div>
      )}

      {/* ── Per-office application forms (p040-043) ────────────────────── */}
      {officeCode && (
        <OfficeFormSheet
          code={officeCode}
          data={officeData[officeCode] ?? {}}
          business={carriedOverBusiness}
          onChange={(d) => setOfficeData((prev) => ({ ...prev, [officeCode]: d }))}
        />
      )}

      {/* ── Business information (form sheet, p32) ─────────────────────── */}
      {phase === 'business' && (
        <FormSheet meta={typeMeta}>
          <SectionMarker letter="A" label="Business Information & Registration" />
          {isReuse && (
            <div className="mt-4 rounded-lg border border-royal/30 bg-royal-tint px-4 py-4">
              {/*
                * FieldLabel renders a span, so this select had no programmatic
                * label at all: a screen reader announced "combo box" and left
                * the applicant to guess which of their businesses it wanted.
                * Wrapping in a real <label> associates the two, the same way
                * every other field on this sheet does (WCAG 1.3.1 / 3.3.2).
                *
                * `readOnly` is not a thing on <select>, so while the list is
                * loading or a prefill is in flight this stays `disabled` — it
                * is genuinely momentary, unlike a field held shut by another
                * answer, and the status text below says which of the two is
                * happening.
                */}
              <label className="block">
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
              </label>
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
            {/*
              * ── Item 94 — structure first, then that agency's number ──────
              *
              * These two fields used to be the other way round: the form asked
              * for a "DTI / SEC / CDA Registration Number" and only four fields
              * later asked which of the three you were registered with. So it
              * wanted a number without knowing whose number it wanted, offered
              * an example from two different agencies in one placeholder, and
              * never checked that the number and the type agreed.
              *
              * The type is the question that decides the other, so it is asked
              * first, and the number field below takes its label, its example
              * and its error from whichever agency that answer implies —
              * DTI for a sole proprietorship, SEC for a partnership or a
              * corporation, CDA for a cooperative.
              */}
            <div>
              <FieldLabel required>Type of Registration</FieldLabel>
              <p className="mb-2 text-xs text-ink-secondary">
                Choose this first — it decides which agency’s registration number we ask for
                next.
              </p>
              {/*
                * A radiogroup, not four toggle buttons. These are four mutually
                * exclusive answers to one question, and `aria-pressed` announced
                * them as four independent switches — a screen-reader user was
                * told "Corporation, pressed" with no way to hear that it was one
                * of four or that picking it unpicked another. Same markup as the
                * "which permit are you renewing" picker above.
                */}
              <div role="radiogroup" aria-label="Type of Registration" className="flex flex-wrap gap-2.5">
                {REGISTRATION_TYPES.map((rt) => {
                  const selected = form.registration_type === rt.value
                  return (
                    <button
                      key={rt.value}
                      type="button"
                      role="radio"
                      aria-checked={selected}
                      onClick={() => chooseRegistrationType(selected ? '' : rt.value)}
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
            <div>
              <label className="block">
              {/*
                * The label TEXT changes with the chosen structure, so the
                * input's accessible name is re-rendered rather than left saying
                * "DTI / SEC / CDA" at a field that now means one of them.
                * Because this is a wrapping <label>, the name follows the text
                * automatically — there is no stale `aria-label` to forget.
                */}
              <FieldLabel required>{registrationNumberLabel}</FieldLabel>
              <input
                value={form.registration_number}
                onChange={(e) => update('registration_number', e.target.value)}
                onBlur={() => touch('registration_number')}
                placeholder={registrationAgencyInfo?.placeholder ?? ''}
                /*
                 * Inert until the question it depends on is answered — item
                 * 94's actual instruction, that the type is chosen before the
                 * number is asked.
                 *
                 * `readOnly`, never `disabled`, as everywhere else in this
                 * wizard: a disabled input drops out of the tab order and most
                 * screen readers skip it, so an applicant using one would tab
                 * from the type straight past to the TIN and never learn a
                 * registration number is wanted, let alone why it is closed.
                 * Read-only looks identical, stays announceable, and the
                 * description below says what to do about it.
                 */
                readOnly={!registrationAgencyInfo}
                aria-readonly={!registrationAgencyInfo || undefined}
                className={`${inputCls} ${!registrationAgencyInfo ? 'cursor-not-allowed bg-line/60 text-ink-secondary' : ''}`}
                /*
                 * The error joins the description when there is one, so a
                 * screen-reader user who tabs back to a field they got wrong
                 * hears WHY along with the field's name (WCAG 3.3.1). Without
                 * it the message is on screen but silent to them.
                 */
                aria-describedby={
                  fieldErrors.registration_number
                    ? 'registration-number-help registration-number-error'
                    : 'registration-number-help'
                }
                aria-invalid={Boolean(fieldErrors.registration_number)}
              />
              </label>
              {/*
                * The announcement. A label that changes under a screen-reader
                * user is silent — nothing re-reads it once focus has moved on —
                * so the same change is narrated here, politely, naming the
                * agency and giving its example. This element is always mounted
                * so the live region exists before the text it will announce.
                */}
              <p
                id="registration-number-help"
                aria-live="polite"
                className="mt-1 text-xs text-ink-secondary"
              >
                {registrationAgencyInfo
                  ? registrationAgencyInfo.hint
                  : 'Choose your type of registration above and we will ask for that agency’s number.'}
              </p>
              {fieldErrors.registration_number && (
                <p id="registration-number-error" className="mt-1 text-xs font-medium text-s-red">
                  {fieldErrors.registration_number}
                </p>
              )}
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              {/*
                * Item 105 — four boxes of three digits, not one box with
                * `000-000-000-000` greyed out inside it.
                *
                * The placeholder was doing the work the control should do: it
                * named a shape and then vanished the moment somebody typed, so
                * the dashes were the applicant's problem and a miscounted digit
                * was invisible. TinInput carries the shape itself and emits the
                * identical dash-joined string, so nothing downstream — the
                * autosave, BusinessController's normalisation, the stored value
                * — knows the difference.
                */}
              <div>
                <TinInput
                  value={form.tin}
                  onChange={(tin) => update('tin', tin)}
                  onBlur={() => touch('tin')}
                  error={fieldErrors.tin}
                  hintId="tin-hint"
                  errorId="tin-error"
                />
                <p id="tin-hint" className="mt-1 text-xs text-ink-secondary">
                  As printed on your BIR certificate, like 123-456-789-000. Leave the last box empty
                  if you have no branch code.
                </p>
                {fieldErrors.tin && (
                  <p id="tin-error" className="mt-1 text-xs font-medium text-s-red">
                    {fieldErrors.tin}
                  </p>
                )}
              </div>
              <div>
                <label className="block">
                <FieldLabel required>Business Name</FieldLabel>
                <input
                  value={form.name}
                  onChange={(e) => update('name', e.target.value)}
                  onBlur={() => touch('name')}
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
                className={inputCls}
              />
              </label>
            </div>

            {/*
              * ── Items A6 and A9 — the main office's landline and website ──
              *
              * Both columns (`business_addresses.telephone`, `.website`) have
              * existed since the schema was aligned to the paper and neither has
              * ever been written, because no screen asked. They sit here rather
              * than on Location & Zoning because the paper groups them with the
              * MAIN OFFICE address in section A, and because that step is about
              * where the premises is, not how to ring it.
              *
              * Item A5's Postal Code is not here, and its absence is the point:
              * Malabon is 1470, the map pin is already refused outside the city,
              * and a question with one possible answer is not worth a field. The
              * API fills it — see businessPayload above.
              *
              * Neither is required. A sari-sari store has no landline and no
              * website, and no paper form marks either with an asterisk.
              */}
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block">
                <FieldLabel>Telephone (Landline)</FieldLabel>
                <input
                  inputMode="tel"
                  value={form.telephone}
                  onChange={(e) => update('telephone', e.target.value)}
                  onBlur={() => touch('telephone')}
                  placeholder="Area code and number"
                  className={inputCls}
                  aria-invalid={Boolean(fieldErrors.telephone)}
                  aria-describedby={fieldErrors.telephone ? 'telephone-error' : undefined}
                />
                </label>
                {fieldErrors.telephone && (
                  <p id="telephone-error" role="alert" className="mt-1 text-xs font-medium text-s-red">
                    {fieldErrors.telephone}
                  </p>
                )}
              </div>
              <div>
                <label className="block">
                <FieldLabel>Website Address</FieldLabel>
                <input
                  inputMode="url"
                  value={form.website}
                  onChange={(e) => update('website', e.target.value)}
                  onBlur={() => touch('website')}
                  placeholder="yourbusiness.com.ph"
                  className={inputCls}
                  aria-invalid={Boolean(fieldErrors.website)}
                  aria-describedby={fieldErrors.website ? 'website-error' : undefined}
                />
                </label>
                {fieldErrors.website && (
                  <p id="website-error" role="alert" className="mt-1 text-xs font-medium text-s-red">
                    {fieldErrors.website}
                  </p>
                )}
              </div>
            </div>

            {/*
              * ── Items A13, A14, A15 — and only for the three structures that
              * have somebody to be about ────────────────────────────────────
              *
              * See hasPresidentOrOfficer. A sole proprietor IS the proprietor
              * and IS the officer in charge, and the account already knows their
              * name, so asking them to name their own president is the
              * duplicate-question failure the client called out — item A14 is
              * even labelled "Citizenship (of President/OIC)" on the paper,
              * which settles whose citizenship all three describe.
              *
              * The block appears and disappears with the Type of Registration
              * chosen above it. `aria-live` is on the surrounding region so a
              * screen-reader user who picks Corporation is told that three more
              * questions just arrived, rather than discovering them by tabbing.
              */}
            <div aria-live="polite">
              {presidentAsked && (
                <div className="space-y-4 rounded-xl border border-line p-4">
                  <p className="text-xs text-ink-secondary">
                    Because you are registered as a{' '}
                    {REGISTRATION_TYPES.find((rt) => rt.value === form.registration_type)?.label.toLowerCase() ??
                      'partnership, corporation or cooperative'}
                    , the city also asks who runs it. A sole proprietor is not asked these.
                  </p>
                  <div>
                    <label className="block">
                    <FieldLabel>Name of President / Officer in Charge</FieldLabel>
                    <input
                      value={form.president_officer_name}
                      onChange={(e) => update('president_officer_name', e.target.value)}
                      placeholder="Full name"
                      maxLength={255}
                      className={inputCls}
                    />
                    </label>
                  </div>
                  <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label className="block">
                      <FieldLabel>Citizenship</FieldLabel>
                      <input
                        value={form.citizenship}
                        onChange={(e) => update('citizenship', e.target.value)}
                        maxLength={100}
                        className={inputCls}
                      />
                      </label>
                      <p className="mt-1 text-xs text-ink-muted">
                        Of the president or officer in charge named above.
                      </p>
                    </div>
                    <div>
                      <label className="block">
                      <FieldLabel>Capital Participation (% Filipino)</FieldLabel>
                      <input
                        inputMode="decimal"
                        value={form.capital_participation_filipino}
                        onChange={(e) => update('capital_participation_filipino', e.target.value)}
                        onBlur={() => touch('capital_participation_filipino')}
                        placeholder="e.g. 100"
                        className={`${inputCls} tnum`}
                        aria-invalid={Boolean(fieldErrors.capital_participation_filipino)}
                        aria-describedby={
                          fieldErrors.capital_participation_filipino
                            ? 'capital-participation-error'
                            : undefined
                        }
                      />
                      </label>
                      {fieldErrors.capital_participation_filipino && (
                        <p
                          id="capital-participation-error"
                          role="alert"
                          className="mt-1 text-xs font-medium text-s-red"
                        >
                          {fieldErrors.capital_participation_filipino}
                        </p>
                      )}
                    </div>
                  </div>
                </div>
              )}
            </div>

            {/*
              * ── Item B6 — Economic Organization ───────────────────────────
              *
              * Six mutually exclusive answers, so a real radiogroup with an
              * accessible name, matching the Type of Registration picker above
              * and the "which permit are you renewing" list. `aria-pressed`
              * toggles would announce six independent switches and never say
              * that picking one unpicks another.
              *
              * See ECONOMIC_ORGANIZATIONS for why this is not the Type of
              * Registration question again: that one asks what the BUSINESS is,
              * this asks what this PREMISES is to it.
              */}
            <div>
              <FieldLabel>Economic Organization</FieldLabel>
              <p className="mb-2 text-xs text-ink-secondary">
                What this place of business is to your business — not how your business is
                registered, which you answered above.
              </p>
              <div
                role="radiogroup"
                aria-label="Economic Organization"
                className="grid gap-2.5 sm:grid-cols-2"
              >
                {ECONOMIC_ORGANIZATIONS.map((eo) => {
                  const selected = form.economic_organization === eo.value
                  return (
                    <button
                      key={eo.value}
                      type="button"
                      role="radio"
                      aria-checked={selected}
                      onClick={() => update('economic_organization', selected ? '' : eo.value)}
                      className={`flex items-start gap-2.5 rounded-md border px-4 py-2.5 text-left transition-colors ${
                        selected
                          ? 'border-royal bg-input text-ink'
                          : 'border-input-border bg-input/60 text-ink-secondary hover:bg-input'
                      }`}
                    >
                      <span
                        className={`mt-1 h-3.5 w-3.5 shrink-0 rounded-full border-2 ${
                          selected ? 'border-royal bg-royal' : 'border-input-border bg-white'
                        }`}
                      />
                      <span className="min-w-0">
                        <span className="block text-sm font-medium text-ink">{eo.label}</span>
                        <span className="block text-xs text-ink-secondary">{eo.hint}</span>
                      </span>
                    </button>
                  )
                })}
              </div>
              {/*
                * On the paper this is "Others ____" — a blank you cannot tick
                * without filling in. So it opens with the choice and is listed
                * in "Still needed on this part" while it is empty: recording
                * "Others" with no other named says less than choosing nothing.
                */}
              {form.economic_organization === 'others' && (
                <div className="mt-3">
                  <label className="block">
                  <FieldLabel required>Others — what kind of establishment is it?</FieldLabel>
                  <input
                    value={form.economic_organization_others}
                    onChange={(e) => update('economic_organization_others', e.target.value)}
                    onBlur={() => touch('economic_organization_others')}
                    placeholder="e.g. mobile stall operated from a vehicle"
                    maxLength={255}
                    className={inputCls}
                    aria-invalid={Boolean(fieldErrors.economic_organization_others)}
                    aria-describedby={
                      fieldErrors.economic_organization_others
                        ? 'economic-organization-others-error'
                        : undefined
                    }
                  />
                  </label>
                  {fieldErrors.economic_organization_others && (
                    <p
                      id="economic-organization-others-error"
                      role="alert"
                      className="mt-1 text-xs font-medium text-s-red"
                    >
                      {fieldErrors.economic_organization_others}
                    </p>
                  )}
                </div>
              )}
            </div>

            {/*
              * ── Item B8 (new form) / B7 (renewal) — tax incentives ────────
              *
              * A general Yes/No, and deliberately NOT read off the `is_bmbe` or
              * `is_cooperative` flags on the Business & Tax Profile. Those two
              * name particular statutory exemptions the fee calculator acts on;
              * this asks whether ANY government entity has granted an incentive,
              * which is true of a PEZA registrant or a Board of Investments
              * pioneer whose Revenue Code assessment is unchanged. Neither
              * answer can be derived from the other in either direction.
              *
              * Two radios rather than a lone checkbox, because "No" is a real
              * answer the officer needs to see given, not an unticked box that
              * could equally mean the applicant skipped the question.
              */}
            <div>
              <FieldLabel>Do you have tax incentives from any Government Entity?</FieldLabel>
              <div
                role="radiogroup"
                aria-label="Do you have tax incentives from any Government Entity?"
                className="flex flex-wrap gap-2"
              >
                {[
                  { value: false, label: 'No' },
                  { value: true, label: 'Yes' },
                ].map((opt) => {
                  const selected = form.has_tax_incentives === opt.value
                  return (
                    <button
                      key={opt.label}
                      type="button"
                      role="radio"
                      aria-checked={selected}
                      onClick={() => update('has_tax_incentives', opt.value)}
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
              {form.has_tax_incentives && (
                <p className="mt-2 text-xs text-ink-secondary">
                  The city asks for a copy of the certificate. Attach it under Other Requirements in
                  Documentary Requirements.
                </p>
              )}
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
            Pin your location and enter your address. The pin must fall inside Malabon. CPDO evaluates
            your zoning clearance from it during processing.
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
            {/*
              * self-start, because a grid item stretches to its row by default
              * and this card has nothing to stretch with. Its height is the map
              * (320px) plus two short captions — 378px — while the address
              * column beside it is 421px, or 719px once "Rented" opens the
              * lessor block. Stretching handed the card the difference (43px,
              * then 341px) as height it had no content for, and because the
              * card itself is transparent — the white comes from the captions'
              * own bg-white — the page showed through inside a rounded, shadowed
              * frame. That empty framed panel is what the client saw under the
              * map. Sizing the card to its content deletes the frame rather
              * than filling it; growing the map to match instead would make it
              * lurch 341px taller the moment somebody ticks "Rented".
              */}
            <div className="self-start overflow-hidden rounded-2xl shadow-card [&>div]:!rounded-none [&>div]:!border-0">
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
                  placeholder="House/building no. and street"
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
                  placeholder="Nearest landmark"
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
                      placeholder="Full name"
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
                      placeholder="House/building no., street, barangay"
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
                        placeholder="11 digits, starting 09"
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
                    placeholder="Full name"
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
                    placeholder="11 digits, starting 09"
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
                        {/*
                          Item 96. Until now the only thing an applicant could
                          do with a file they had sent was replace it or delete
                          it — there was no way to see what had actually
                          arrived. Uploading the wrong scan is the easiest
                          mistake on this screen and it was the one mistake the
                          screen would not let you check for, so the reasonable
                          move was to delete and re-upload on a hunch. View
                          opens the stored copy, not the local File object, so
                          what is shown is what the office will read.
                        */}
                        <DocumentActions id={done.id} filename={done.name} label={dt.name} />
                        <button
                          type="button"
                          onClick={() => void handleRemoveDocument(done, dt.id)}
                          disabled={Boolean(removing)}
                          aria-label={`Remove ${dt.name}`}
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
                      {/*
                        No label here on purpose: these rows are all the same
                        requirement ("Other"), so the filename is the only
                        thing that tells one from another — and it is what the
                        accessible name has to say.
                      */}
                      <DocumentActions id={f.id} filename={f.name} />
                      <button
                        type="button"
                        onClick={() => void handleRemoveDocument(f)}
                        disabled={removingDoc === f.id}
                        aria-label={`Remove ${f.name}`}
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
              What happens next, said here rather than discovered later, and
              what this filing already contains. Everything is decided by the
              time the applicant reaches this screen — payment is the only
              thing left — so the summary names the clearances rather than
              promising a stage that opens later.
            */}
            <p className="max-w-md text-sm text-ink-muted">
              Submitting produces one Tax Order of Payment covering your Business Permit and every
              clearance below. Nothing else is charged afterwards.
            </p>
            {clearanceDecisions.length > 0 && (
              <>
                <p className="mt-6 text-lg font-medium text-royal">LGU Clearances</p>
                <ul className="list-none space-y-0.5 p-0 text-sm text-ink-muted">
                  {clearanceDecisions.map((d) => (
                    <li key={d.code}>{d.text}</li>
                  ))}
                </ul>
              </>
            )}
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
      {/*
        * Equal `1fr` flanks, so the progress bar is actually centred under the
        * card. With `auto` maxima the left track grew to fit the "Still needed
        * on this part…" line and the empty right track stayed at its 9rem
        * floor — the bar and its "Part n of 7" caption sat 135px right of the
        * card's centre line, which is exactly where the eye checks alignment.
        */}
      <div className="mt-10 grid items-start gap-6 sm:grid-cols-[minmax(9rem,1fr)_minmax(0,28rem)_minmax(9rem,1fr)]">
        <div className="flex flex-col gap-2">
          <div className="flex items-center gap-4">
            {/*
              * A sheet opened from a clearance card finishes by going back to
              * the cards, not by carrying on down the wizard: the applicant is
              * mid-way through choosing clearances and has one form's worth of
              * business here. Only sheets reached that way — `officeReturn` —
              * change; reaching one from the section map still reads Next.
              */}
            {officeCode && officeReturn ? (
              <PillButton
                onClick={() => void returnToClearances()}
                disabled={saving || stepMissing.length > 0}
                className="min-w-28"
              >
                {saving ? 'Saving…' : 'Save & back to clearances'}
              </PillButton>
            ) : !isLast ? (
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
        The SUBMISSION dialog (p041, item 59) lives inside <ClearanceStage>,
        with the Submit button that opens it. It is not repeated here: the
        upload posts straight to /clearances/{code}/held, so unlike the old
        wizard there is no file waiting in the browser for a draft to exist.
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
