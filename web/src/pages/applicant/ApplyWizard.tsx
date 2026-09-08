import { Fragment, useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { MapPicker } from '../../components/MapPicker'
import { checkPin, withinMalabon } from '../../lib/malabonGeo'
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
  documents,
  reference,
} from '../../lib/resources'
import type { AmendmentAnswers } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { useAuth } from '../../stores/auth'
import { ACCEPT_ATTR, fileRejection, uploadErrorMessage } from './uploads'
/*
 * No clearance or office-form imports here any more, and that absence is the
 * whole of this restructure on the import list.
 *
 * The six clearances and their office sheets are a STAGE that opens after the
 * first payment (docs/clearances-after-payment.md), not steps of this wizard.
 * <ClearanceStage> mounts them itself on /applications/:id/clearances, and it
 * owns the sheets when no `onOpenOfficeForm` is handed to it — which is now
 * every caller. Re-importing ClearanceStage or OfficeFormSheet here would be
 * the first move back to the arrangement this replaced.
 */
import BarangayZoningMap from './BarangayZoningMap'
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
  capitalInvestmentMissing,
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
  PrefillResult,
  PsicCode,
} from '../../lib/types'

type BasePhase =
  | 'privacy'
  /*
   * Section A of MCG-BPLO-FO-002 v2.0 — renewals only.
   *
   * "Do you have any changes or amendments in the previous business
   * registration?" is the first thing the paper asks after the instructions,
   * and everything printed after it is conditional on the answer. It is a STEP
   * and not a block on another step because that conditionality is the whole
   * point: a No ends the form, and a step that can end the form is not a
   * paragraph inside one.
   */
  | 'amendments'
  | 'address'
  | 'business'
  /*
   * Section B of the paper — "Business Operation".
   *
   * A STEP rather than a heading inside Business Information, because the paper
   * prints A and B as two sections and the client asked for the wizard to say
   * so: "Section 3 to be Business Information & Registration, Section 4 to be
   * Business Operation, Section 5 to be Documentary Requirements."
   *
   * A marker was tried first and was not enough. A heading part-way down a step
   * still reads as a subdivision of that step, and the section map along the top
   * — which is how an applicant navigates and how they check what is left —
   * only ever named the STEP. B did not exist in the one place somebody looks to
   * find it.
   */
  | 'operation'
  | 'documents'
  | 'fees'
  | 'review'

/*
 * `PAY_METHODS` was here — the same three PayPage offers, duplicated because
 * two screens took a payment. Only one does now. PayPage keeps its own list,
 * and the duplication that had to be justified no longer exists.
 */
/*
 * ── What this wizard is, and why the clearances are not in it ──────────────
 *
 * This wizard is the BUSINESS PERMIT APPLICATION, and nothing else. Decided
 * with the client on 28 August 2026: payment comes first, the clearances come
 * after it. The flow in full is docs/clearances-after-payment.md; the shape is
 *
 *     wizard → submit → Tax Order of Payment #1 → PAID
 *                          → LGU Clearance stage unlocks
 *                          → balance due must reach zero before release
 *
 * The six LGU clearances are therefore NOT a step here. Each one is a separate
 * transaction with a separate office, a separate fee and a separate outcome,
 * and it is applied for on /applications/:id/clearances once the first payment
 * has cleared.
 *
 * ── The dependency that used to argue against this, and why it is gone ─────
 *
 * The cards once sat at step 4 of 8 and could not be moved, for a real reason:
 * `requiredDocs` was the union of the document types on the SELECTED permit
 * types and the tax profile's questions varied by permit code, so both of the
 * sections after the cards were computed from them. Moving them later would
 * have asked the applicant to satisfy a list that did not exist yet.
 *
 * That dependency does not exist any more, and it is what makes this ordering
 * possible rather than merely preferred. Documentary Requirements now
 * describes the business permit ALONE — each clearance carries its own
 * documents on its own office sheet, mounted by <ClearanceStage> — and the tax
 * profile is passed `permitCodes: [BUSINESS_PERMIT_CODE]` at every call site,
 * so it asks the business permit's questions alone. Nothing in this file is
 * computed from a clearance any more. If either of those two ever starts
 * reading the clearance list again, this ordering breaks and the whole
 * restructure has to be reconsidered — that is the tripwire.
 *
 * The other half is the fee engine, and it needs no change either.
 * `FeeCalculator::assess` gates every rule on the permit types attached to the
 * application, so a clearance's lines appear if and only if that clearance has
 * been applied for. Re-running the assessment after an Apply produces exactly
 * the additional lines — which is what lets the balance accrue after the first
 * Tax Order of Payment instead of everything having to be priced at submit.
 *
 * ── What survives of the old ordering, and why ─────────────────────────────
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
 * Documentary Requirements precedes the Business & Tax Profile, which is the
 * order the client's diagram gives. Nothing forces it either way.
 *
 * ── Checklist item 76, restated honestly ───────────────────────────────────
 *
 * Item 76 asked for the LGU Section "at the last part before submitting the
 * application". It was read literally once — the cards as step 6 of 7 — and
 * that reading is what this replaces. The clearances are no longer part of the
 * submission at all, so there is nothing left for the item to order. If BPLO
 * comes back wanting them chosen before submission, that is not a reordering
 * of this array: it is the whole flow again, and the argument is in the doc.
 */
const BASE_PHASES: BasePhase[] = [
  'privacy',
  'address',
  'business',
  'operation',
  'documents',
  'fees',
  'review',
]

/*
 * `business` is captioned with the paper's full section title now — "Business
 * Information & Registration" — rather than the half of it that fitted while
 * the step also carried Section B. The two names have to be distinguishable at
 * a glance in the section map: "Business Information" next to "Business
 * Operation" is one word apart and easy to misread when you are looking for
 * where you left off.
 */
const BASE_LABELS: Record<BasePhase, string> = {
  privacy: 'Data Privacy Consent',
  amendments: 'Changes Since Last Permit',
  business: 'Business Information & Registration',
  operation: 'Business Operation',
  address: 'Location & Zoning',
  documents: 'Documentary Requirements',
  fees: 'Fees & Tax Computation',
  review: 'Review & Submit',
}

/*
 * OFFICE_LABELS is gone with the sheets it named. The per-office forms are
 * mounted by <ClearanceStage> (OfficeFormStep.tsx is unchanged — only where it
 * is mounted moved), because a sheet is the second half of applying for a
 * clearance and the clearance is not applied for here any more.
 */

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
 * This used to be a bounding box, and the box has been replaced by the real
 * city polygon — see `lib/malabonGeo.ts` for the check and
 * `lib/malabonGeo.data.ts` for where the boundaries came from and how they
 * were verified before being trusted.
 *
 * The box was wrong in both directions, which is why it went. A rectangle
 * around an irregular delta city admits its neighbours, and a tester duly
 * pinned Caloocan and Valenzuela and was accepted. It was also too tight: it
 * ran 120.930–120.985 E while Malabon actually reaches 120.921 and 121.001, so
 * genuine addresses near the east and west edges were being refused.
 *
 * What is checked now is containment in the city outline, and separately
 * whether the pin agrees with the barangay chosen from the dropdown. What is
 * still NOT checked, and still never claimed: this does not detect water.
 * Malabon is a river delta — the Tullahan, the Tenejeros-Tanza and the fishpond
 * belt run through it — and the boundary set carries no hydrography, so a pin
 * in the middle of a river is inside the city and passes. Nor does any of this
 * decide zoning: the ordinance itself cannot be automated into a conformance
 * answer (`docs/zoning-ordinance/README.md` sets out why), so CPDO evaluates
 * the actual location during processing, which is what the step has always
 * said.
 */

/*
 * There is no StepNode type any more, and no `stepKey`.
 *
 * A step used to be "either a base phase or one office form sheet", because
 * applying for a clearance slotted that office's sheet into the middle of the
 * running order — so positions moved under the applicant and everything
 * remembered about a step had to be remembered by NAME rather than by index.
 * (Remembering by index is how a sheet nobody had opened once inherited the
 * state of the one that used to sit at that number.)
 *
 * With the clearances out of the wizard the sequence is exactly BASE_PHASES,
 * fixed for the life of the filing, so a phase IS its own stable key. If a
 * conditional step is ever added back here, the name-not-index rule comes back
 * with it — it was not a workaround, it was the fix for a real bug.
 */

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
  /* BPLO items A7 and A8 — the business's own mobile and e-mail. */
  mobile_number: string
  email: string
  /* BPLO items 11 / 12 — the named person on the form. */
  owner_surname: string
  owner_given_name: string
  owner_middle_name: string
  owner_suffix: string
  owner_gender: string
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
  /** BPLO item B7 — one figure for the whole business, as the paper asks. */
  capital_investment: string
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
  mobile_number: '',
  email: '',
  owner_surname: '',
  owner_given_name: '',
  owner_middle_name: '',
  owner_suffix: '',
  owner_gender: '',
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
  capital_investment: '',
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
  /*
   * ── Section A1 of MCG-BPLO-FO-002 v2.0 ──────────────────────────────────
   *
   * "Do you have any changes or amendments in the previous business
   * registration?" — the first question the renewal form asks, and the one
   * that decides whether any of the rest of section A is asked at all.
   *
   * Three states, not two. `null` is "not answered yet", and it has to be
   * distinguishable from `false`: a renewal that reaches Confirm without the
   * question having been put is the same silent-null bug that
   * `prior_permit_declared_none` exists to prevent one field over. No is a
   * click, not a default fallen into.
   *
   * An AMENDMENT never shows A1 — a filing whose whole purpose is to amend
   * has already answered Yes by existing — so this stays null there and the
   * A2 ticks alone carry the answer, exactly as before.
   */
  hasChanges: boolean | null
  ownership: boolean
  location: boolean
  nature: boolean
  /** "Others (specify)" — the text is the tick; blank means not ticked. */
  other: string
  /*
   * Section A3: "Amendment: From ___ To ___". Blank means not chosen. Only
   * reachable under a Yes at A1, and the API writes both back to null when A1
   * is No, so a changed mind cannot leave a conversion behind it.
   */
  fromRegistrationType: string
  toRegistrationType: string
}

const EMPTY_AMENDMENT: AmendmentState = {
  hasChanges: null,
  ownership: false,
  location: false,
  nature: false,
  other: '',
  fromRegistrationType: '',
  toRegistrationType: '',
}

/**
 * The three checkbox amendments, in the order the paper form prints them.
 * "Others (specify)" is not here: it is a text field that ticks itself, so it
 * is rendered separately rather than pretending to be a fourth checkbox.
 */
const AMENDMENT_KINDS: {
  key: 'ownership' | 'location' | 'nature'
  label: string
  /**
   * Which section of the form this tick opens, in the applicant's words.
   *
   * On a renewal the A2 boxes decide how long the rest of the form is (see
   * `sequence`), so the box has to say what it costs to tick it. Without this
   * the applicant discovers the consequence one step later, which is the
   * wrong order to learn it in.
   */
  opens: string
}[] = [
  {
    key: 'ownership',
    label: 'Ownership',
    opens: 'Opens Business Information — registration, TIN, owner details.',
  },
  {
    key: 'location',
    // The paper's own wording: "Location or Address of Business".
    label: 'Location or Address of Business',
    opens: 'Opens Location & Zoning — the address and the map pin.',
  },
  {
    key: 'nature',
    label: 'Nature of Business',
    opens: 'Opens Business Information — your lines of business and PSIC codes.',
  },
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
  // The "Change" control below the box reopens the picker, so it needs to put
  // the caret where the applicant is about to type. Opening the list without
  // moving focus would leave a keyboard user staring at a list they are not in.
  const search = useRef<HTMLInputElement>(null)

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

  /*
   * One trade, not several.
   *
   * The picker was multi-select, and the zoning step it lives on only ever
   * read `lines[0]`: the conformity verdict, the carried-over business block
   * and the Location Insights lookup all take the first line and ignore the
   * rest. So a second trade was accepted, stored, and then quietly left out of
   * the decision it was supposed to inform — the worst of both, since the
   * applicant had every reason to think it counted.
   *
   * Barely used in practice either: of 733 registered businesses, 728 declare
   * one line and 5 declare two.
   *
   * ── The array is NOT the bug. Do not "fix" it. ──────────────────────────
   *
   * `lines` stays an array, and so do `business.lines` and the
   * `business_lines` table — one row per PSIC code — because the paper form
   * genuinely has a multi-line table and 5 of the 733 registered businesses
   * really do declare two trades. It is only THIS WIZARD that is held to one,
   * on the client's instruction ("for our zoning that will fuck it up"), and
   * only because every reader on this step — the conformity verdict, the
   * carried-over business block, the Location Insights lookup — takes
   * `lines[0]` and ignores the rest.
   *
   * So the array is the data model being honest about the domain, not a
   * leftover from the multi-select. Collapsing it to a single `psic_code_id`
   * would break the payload, the API and the officer's review sheet in order
   * to match a restriction that lives in one component's copy.
   *
   * Choosing a different trade replaces the current one rather than adding to
   * it, and the Change / Clear controls below are the only way out of a
   * choice — deliberately not called "Remove", which is the vocabulary of a
   * list you are pruning.
   */
  function toggle(code: PsicCode) {
    const already = lines[0]?.psic_code_id === code.id
    if (already) return
    onChange([{ psic_code_id: code.id, line_of_business: '', products_services: '' }])
    setOpen(false)
  }

  /*
   * Named, never counted. A count is the multi-select's vocabulary: "Selected
   * (1)" answers "how many?", which is a question this step does not ask and
   * must not appear to. It also confirms nothing useful — the two sari-sari
   * rows in this list differ only by the words in their brackets, so the only
   * confirmation worth showing is the trade's name.
   */
  const chosenCode = lines.length > 0 ? codes.find((c) => c.id === lines[0].psic_code_id) : undefined
  const selectedTitles = lines
    .map((l) => codes.find((c) => c.id === l.psic_code_id)?.title)
    .filter(Boolean)
    .join(', ')

  /** Reopen the picker on the applicant's own terms, caret already in the box. */
  function reopen() {
    setQuery('')
    setOpen(true)
    search.current?.focus()
  }

  return (
    <div className="space-y-4">
      {/* relative: the results hang over what follows instead of shoving it down. */}
      <div ref={box} className="relative">
        <label htmlFor="psic-search" className="block">
          {/*
            * "Search for the ONE line" — the instruction is in the field's own
            * name, where it cannot be scrolled past, rather than only in help
            * text above it. This is the label a screen reader announces when
            * the applicant arrives in the box, so it is the last chance to say
            * how many answers the question takes before they give one.
            */}
          <FieldLabel required>Search for the one line of business you are registering</FieldLabel>
        </label>
        <div className="relative">
          <SearchIcon
            size={18}
            className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-ink-secondary"
          />
          <input
            id="psic-search"
            ref={search}
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
              * The panel below takes over the moment the list closes.
              *
              * It reads "Your line of business is X" and NOT "Selected (1)":
              * a running count is what a shopping basket says, and the only
              * reason to print one is that the number can change. It cannot.
              * Reopening this list to pick again is a correction, and the
              * wording says so — "picking another replaces it" — so nobody
              * arrives at a second trade expecting it to be added.
              */}
            {chosenCode && (
              <p className="border-b border-line bg-royal-tint px-4 py-2.5 text-xs font-semibold text-royal">
                <span className="mr-1.5 inline-flex h-4 w-4 translate-y-0.5 items-center justify-center rounded-sm bg-royal text-white">
                  <CheckIcon size={11} />
                </span>
                Your line of business is{' '}
                <span className="font-normal text-ink">{chosenCode.title}</span>
                <span className="font-normal text-ink-secondary"> — picking another replaces it.</span>
              </p>
            )}

            <ul
              id="psic-results"
              // The rows are radios now, so the list that holds them has to say
              // so — otherwise a screen reader meets a radio with no group.
              role="radiogroup"
              aria-label="Line of business"
              className="max-h-64 divide-y divide-line overflow-y-auto"
            >
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
                          /*
                           * radio, not aria-pressed: these are exclusive
                           * answers to one question now, and `aria-pressed`
                           * would announce 134 independent switches.
                           */
                          role="radio"
                          aria-checked={selected}
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
        *
        * Announced as a sentence, not as "Selected 1: X". A count read aloud
        * is the strongest possible hint that a second answer is expected, and
        * it is the hint a screen-reader user has least chance of correcting
        * from the rest of the screen.
        */}
      <p aria-live="polite" className="sr-only">
        {lines.length > 0
          ? `Your line of business is ${selectedTitles}`
          : 'No line of business chosen yet'}
      </p>

      {lines.length > 0 && (
        <div className="rounded-lg border border-input-border bg-royal-tint p-4">
          {/*
            * One answer, presented as one answer.
            *
            * This was a "Selected (1)" panel with a Remove link, which is the
            * furniture of a list you are building: a count implies a number
            * that can go up, and "Remove" implies something left behind when
            * it does. The applicant reported exactly that reading — the step
            * looked like it wanted more than one — while the picker had
            * already been single-select for weeks.
            *
            * So it is headed like a field, not like a basket, and the way out
            * is "Change" (pick a different trade) beside "Clear" (answer it
            * later). Both stay: an applicant who has picked the wrong trade
            * and an applicant who wants the box empty again are different
            * people, and a step where the only escape is picking something
            * else is a trap.
            */}
          <p className="text-[11px] font-bold uppercase tracking-[0.1em] text-ink-secondary">
            Your line of business
          </p>
          <div className="mt-2 space-y-3">
            {lines.map((line) => {
              const code = codes.find((c) => c.id === line.psic_code_id)
              /*
               * Other is no longer offered, but filings made before it was
               * withdrawn still carry it — a renewal or a reopened draft can
               * arrive holding one. Those keep their typed text, shown as the
               * line's name and no longer editable, because the answer cannot
               * be improved in place: what it needs is a real PSIC code, which
               * means changing it for one off the list.
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
                            Not on the PSIC list, so this line cannot be assessed. Change it for the
                            closest trade on the list.
                          </p>
                        </div>
                      ) : (
                        <div>
                          <p className="truncate text-sm font-semibold text-ink">{code?.title}</p>
                          <p className="tnum mt-0.5 text-xs text-ink-secondary">
                            PSIC {code?.code}
                          </p>
                        </div>
                      )}
                    </div>
                    {/*
                      * Royal and ink, never #bd0000.
                      *
                      * "Remove" used to be in the error red, and the clearance
                      * card had to be un-reddened for the same reason
                      * (checklist item 107 — "This should not look like a
                      * warning message"). DESIGN.md keeps #bd0000 for errors
                      * and destructive actions; changing your mind about a
                      * trade before the filing is even submitted is neither.
                      * Painting it red tells an applicant they have done
                      * something wrong at the exact moment they are trying to
                      * put something right.
                      *
                      * Change leads, because correcting the trade is the far
                      * likelier intent and it keeps the step answered. The
                      * accessible names carry the noun the visible words leave
                      * to context, and both start with the visible word so the
                      * name still contains the label (WCAG 2.1 AA 2.5.3).
                      */}
                    <div className="flex shrink-0 items-center gap-3">
                      <button
                        type="button"
                        onClick={reopen}
                        aria-label="Change line of business"
                        className="text-sm font-semibold text-royal underline underline-offset-2 hover:text-royal-hover"
                      >
                        Change
                      </button>
                      <button
                        type="button"
                        onClick={() =>
                          onChange(lines.filter((l) => l.psic_code_id !== line.psic_code_id))
                        }
                        aria-label="Clear line of business"
                        className="text-sm text-ink-secondary underline underline-offset-2 hover:text-ink"
                      >
                        Clear
                      </button>
                    </div>
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
          {/*
            * A filing carried over from before the picker was held to one can
            * still arrive with two rows — the renewal path rebuilds `lines`
            * from the previous filing's `business.lines`, which is a real
            * multi-row table. The panel above is headed in the singular, so
            * two rows under it would read as a rendering fault rather than as
            * history. Named rather than hidden, and it says what will happen
            * to the extras the moment the applicant touches the picker.
            */}
          {lines.length > 1 && (
            <p className="mt-3 text-xs text-ink-secondary">
              Carried over from an earlier filing, which declared {lines.length} lines. A filing
              declares one now — picking a trade above replaces all of these with the one you pick.
            </p>
          )}
        </div>
      )}
    </div>
  )
}

/* ── Item 110 — identify the filing before the wizard opens ─────────────── */

/**
 * What the entry modal hands back once the applicant has said what they are
 * filing against. Nothing here is written until Confirm, which is the whole
 * point of holding it in one object rather than editing the wizard as we go.
 */
/**
 * A paper permit attached in the identify dialog, standing in for a form the
 * applicant did not tick.
 *
 * The permit type is what makes the file readable to an office: uploaded with
 * it, the API names the attachment after that clearance (`HELD_SANITARY` and
 * friends) instead of filing it as one more untitled PDF in Documentary
 * Requirements. Without it, the sanitary officer gets a document called
 * "scan_0012.pdf" and no reason to believe it is theirs.
 */
interface PaperCopy {
  permitTypeId: number
  permitTypeName: string
  file: File
}

interface FilingIdentity {
  businessId: number
  /**
   * `null` is a real answer, not an unanswered question — see the escape in
   * the modal body. A business whose permits were all issued on paper has no
   * BizTrack permit to point at, and in year one that is the common case.
   */
  permitId: number | null
  /**
   * Every permit this filing covers, primary first.
   *
   * `permitId` above is the primary and still keys the renewal chain; this is
   * the whole answer. A shop renewing its Mayor's Permit, Sanitary Permit and
   * FSIC makes one visit to the counter and files once, and a dialog that
   * could only take one of the three left the other two unrecorded — the
   * offices then had no filing to attach their review to.
   *
   * Empty when the escape is ticked, which is the same state `permitId: null`
   * describes; the two never disagree because one is derived from the other.
   */
  permitIds: number[]
  /**
   * Paper copies attached in the dialog for permit types NOT ticked above.
   *
   * Held as files rather than uploaded on the spot because the dialog runs
   * BEFORE the draft exists — on entry there is no application id to attach
   * anything to. The wizard flushes these to the draft the moment it has one,
   * through the same `documents.upload(..., permitTypeId)` path the LGU
   * Clearances stage uses for a certificate the applicant already holds, so
   * the reviewing office reads them off the list it already reads.
   */
  paperCopies: PaperCopy[]
  /**
   * Which kind of `null` this is: the applicant having TICKED "no BizTrack
   * permit", or the question simply never having been put.
   *
   * The distinction did not exist and that is the whole bug. Both states left
   * `prior_permit_id` null, so a renewal of a paper permit and a renewal
   * nobody had asked about were the same row — and seven filings in the
   * register are the second kind passing as the first. The escape is still
   * open; it now has to be taken rather than fallen into.
   */
  declaredNone: boolean
  amendment: AmendmentState
  /**
   * The prefill the modal already fetched for `businessId`. Handed back so
   * confirming does not GET the same thing a second time.
   */
  prefill: PrefillResult
}

/** "1 Jan 2025 – 31 Dec 2025", or whichever half of it the register holds. */
function permitValidity(p: Permit): string {
  if (p.valid_from && p.valid_until) return `${formatDate(p.valid_from)} – ${formatDate(p.valid_until)}`
  if (p.valid_until) return `Valid until ${formatDate(p.valid_until)}`
  if (p.valid_from) return `Issued ${formatDate(p.valid_from)}`
  return 'No validity dates on record'
}

/**
 * ITEM 110 — "For the renewal, it should ask first (in modal) the permit ID so
 * the system will know which specific permit to renew."
 *
 * This used to be a block inside Business Information, three steps in: the
 * applicant met the data-privacy notice, pinned a map, and only then was asked
 * which permit any of it was about. Two things were wrong with that. The paper
 * BPLO renewal form prints the permit number in its header — it is the first
 * thing the counter reads, not the fourteenth field — and a wizard that
 * prefills itself from a permit it has not been told about is prefilling from a
 * guess. So the question is asked before the wizard opens, and the answer is
 * what the wizard opens FROM.
 *
 * Every control here edits LOCAL state. The business select does not run the
 * real prefill (which rewrites the whole form) until Confirm, so Cancel from
 * the "change my mind" route is genuinely free — nothing the applicant typed
 * has been overwritten by a business they were only looking at.
 */
function IdentifyFilingModal({
  applicationType,
  ownedBusinesses,
  businessesLoading,
  permitTypes,
  initial,
  mode,
  confirming,
  confirmError,
  onCancel,
  onConfirm,
}: {
  applicationType: 'renewal' | 'amendment'
  ownedBusinesses: Business[]
  businessesLoading: boolean
  /**
   * The reference list, only so an untick`ed permit's upload can carry the
   * right `permit_type_id`. A permit embeds its type as `{ code, name }`; the
   * id lives here.
   */
  permitTypes: { id: number; code: string; name: string }[]
  initial: {
    businessId: number | null
    permitId: number | null
    permitIds: number[]
    declaredNone: boolean
    amendment: AmendmentState
  }
  /**
   * `entry` — the wizard has not opened yet, so Cancel leaves. `change` — the
   * applicant reopened this to correct an answer, so Cancel keeps what they
   * had and puts them back where they were.
   */
  mode: 'entry' | 'change'
  confirming: boolean
  confirmError: string | null
  onCancel: () => void
  onConfirm: (identity: FilingIdentity) => void
}) {
  const verb = applicationType === 'renewal' ? 'renewing' : 'amending'
  const [businessId, setBusinessId] = useState<number | null>(initial.businessId)
  /*
   * The ticked permits, primary first. Order is the answer's own: the first
   * tick is the permit the renewal chain keys on, so re-ticking to change your
   * mind about which is primary is just unticking and ticking again.
   */
  const [permitIds, setPermitIds] = useState<number[]>(
    initial.permitIds.length > 0
      ? initial.permitIds
      : initial.permitId !== null
        ? [initial.permitId]
        : [],
  )
  const [declaredNone, setDeclaredNone] = useState(initial.declaredNone)
  /** Paper copies attached for permit types not ticked, keyed by type id. */
  const [paperCopies, setPaperCopies] = useState<Record<number, PaperCopy>>({})
  /** Per-row rejection message from `fileRejection` — too big, wrong type. */
  const [uploadErrors, setUploadErrors] = useState<Record<number, string>>({})
  const [amendment, setAmendment] = useState<AmendmentState>(initial.amendment)
  const [prefill, setPrefill] = useState<PrefillResult | null>(null)
  const [loadingPermits, setLoadingPermits] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)
  /*
   * Whether Confirm has been pressed on an unanswered question. The reason is
   * always described on the button (aria-describedby, below); this only
   * decides whether it is also SHOWN, so the dialog does not open scolding
   * somebody who has not touched anything yet.
   */
  const [attempted, setAttempted] = useState(false)

  const businessRef = useRef<HTMLSelectElement | null>(null)
  const permitsRef = useRef<HTMLUListElement | null>(null)
  const amendmentRef = useRef<HTMLFieldSetElement | null>(null)
  const reasonId = useId()

  /*
   * The chosen business's renewable permits. This is the same GET the wizard's
   * prefill uses, and the response is kept whole so Confirm can hand it to
   * `selectBusinessForReuse` instead of asking for it again.
   */
  useEffect(() => {
    if (businessId === null) {
      setPrefill(null)
      return
    }
    let active = true
    setLoadingPermits(true)
    setLoadError(null)
    businesses
      .prefill(businessId, applicationType)
      .then((result) => {
        if (!active) return
        setPrefill(result)
        /*
         * A permit id from another business must not survive the switch — it
         * would name a permit this filing has nothing to do with. Keeping it
         * when the list still contains it is what makes reopening this dialog
         * to change something else non-destructive.
         */
        setPermitIds((current) =>
          current.filter((id) => (result.renewable_permits ?? []).some((p) => p.id === id)),
        )
      })
      .catch((err) => {
        if (active) setLoadError(toApiError(err).message)
      })
      .finally(() => {
        if (active) setLoadingPermits(false)
      })
    return () => {
      active = false
    }
  }, [businessId, applicationType])

  const permits = prefill?.renewable_permits ?? []
  const amendmentChosen =
    amendment.ownership || amendment.location || amendment.nature || amendment.other.trim() !== ''

  /*
   * ── Which forms are covered, and which need a file instead ──────────────
   *
   * A permit TYPE is covered when one of this business's permits of that type
   * is ticked. Anything left over is a form the applicant is renewing without
   * a BizTrack record to renew from — held on paper by the old counter
   * process, which in year one is the ordinary case rather than the exception.
   *
   * Keyed on the type and not on the permit because that is the question being
   * asked. Two expired Sanitary Permits from two different years are one
   * sanitary form; making the applicant upload a copy per row would ask for
   * the same certificate twice.
   */
  const coveredCodes = new Set(
    permits.filter((p) => permitIds.includes(p.id)).map((p) => p.permit_type?.code),
  )
  /*
   * Matched through the reference list rather than off the permit itself: a
   * permit embeds `permit_type` as `{ code, name }` with no id, and the upload
   * needs the id. The code is the stable join — it is what the seeder, the fee
   * rules and the clearance endpoints all key on.
   *
   * A type the reference list does not know is dropped rather than offered
   * with a guessed id, because an upload carrying the wrong permit type files
   * the certificate under another office.
   */
  const uncoveredTypes = Array.from(
    permits
      .reduce((acc, p) => {
        const t = p.permit_type
        if (!t || coveredCodes.has(t.code) || acc.has(t.code)) return acc
        const known = permitTypes.find((pt) => pt.code === t.code)
        if (known) acc.set(t.code, { id: known.id, name: known.name })
        return acc
      }, new Map<string, { id: number; name: string }>())
      .values(),
  )
  const coveredTypeIds = new Set(
    permits
      .filter((p) => permitIds.includes(p.id))
      .map((p) => permitTypes.find((pt) => pt.code === p.permit_type?.code)?.id)
      .filter((id): id is number => typeof id === 'number'),
  )

  /*
   * Why Confirm will not get you out of here yet — in the order the questions
   * are asked, so the sentence always names the topmost thing still blank.
   *
   * The question is now put to amendments as well as renewals. An amendment
   * alters one permit's record; "amend my business" tells the counter no more
   * than "renew my business" does when the shop holds three permits with three
   * expiry dates, and item 50 asked for the choice, not for the choice on
   * renewals only.
   *
   * The year-one escape survives, but as an OPTION in the list rather than as
   * the absence of a requirement. It used to be `permits.length > 0`: where a
   * business had no BizTrack permit the question simply was not asked, and the
   * draft went out carrying a null nobody had ever been shown. That null is
   * indistinguishable from a skipped question, and seven filings in the
   * register are exactly that. Ticking the escape is still one click; the
   * difference is that it is now a click.
   */
  const answeredPriorPermit = permitIds.length > 0 || declaredNone
  const blocked: { reason: string; focus: () => void } | null =
    businessId === null
      ? {
          reason: `Choose the business you are ${verb} first.`,
          focus: () => businessRef.current?.focus(),
        }
      : loadingPermits
        ? { reason: 'Still loading this business’s permits.', focus: () => {} }
        : !answeredPriorPermit
          ? {
              reason:
                permits.length > 0
                  ? `Tick every permit you are ${verb} — or say this business has none issued through BizTrack.`
                  : `Confirm this business has no permit issued through BizTrack, so we know what you are ${verb}.`,
              focus: () => permitsRef.current?.querySelector('input')?.focus(),
            }
          : /*
             * Section A is not asked here any more — it is the wizard's own
             * step, after Data Privacy, because a No there ends the form and a
             * question that can end the form does not belong in the dialog
             * that opens it. This dialog asks one thing: which permits, or a
             * paper copy instead.
             */
            applicationType === 'amendment' && !amendmentChosen
            ? {
                reason:
                  'Tick at least one thing you are amending. An amendment that amends nothing is not a filing the BPLO can act on.',
                focus: () => amendmentRef.current?.querySelector('input')?.focus(),
              }
            : null

  function confirm() {
    // Not `disabled` — see ProtoModal's confirmDescribedBy. A press on a
    // blocked dialog says why and puts the cursor on the question.
    if (confirming) return
    if (blocked) {
      setAttempted(true)
      blocked.focus()
      return
    }
    if (businessId === null || !prefill) return
    onConfirm({
      businessId,
      // Primary is the first tick; the set is the whole answer.
      permitId: permitIds[0] ?? null,
      permitIds,
      // Only the copies for types still untick`ed at Confirm. Attaching a file
      // and then ticking the permit itself is a change of mind, not two
      // answers, so the stale file does not ride along.
      paperCopies: Object.values(paperCopies).filter((c) => !coveredTypeIds.has(c.permitTypeId)),
      declaredNone: permitIds.length === 0 && declaredNone,
      amendment,
      prefill,
    })
  }

  return (
    <ProtoModal
      title={
        applicationType === 'renewal' ? 'WHICH PERMITS ARE YOU RENEWING?' : 'WHAT ARE YOU AMENDING?'
      }
      wide
      cancelLabel={mode === 'entry' ? 'Not now' : 'Keep what I had'}
      confirmLabel={confirming ? 'Opening…' : 'Continue'}
      confirmDescribedBy={blocked ? reasonId : undefined}
      onCancel={onCancel}
      onConfirm={confirm}
    >
      <p className="text-sm leading-relaxed text-ink-secondary">
        {applicationType === 'renewal'
          ? 'Two ways to do this: tick the permits you are renewing, or upload a permit you already hold outside BizTrack. Either way we fill the rest of the form in from it.'
          : 'Say which record you are amending and what about it is changing. We fill the rest of the form in from it.'}
      </p>

      {/* ── 1. Which business ────────────────────────────────────────────── */}
      <label className="mt-5 block">
        <FieldLabel required>Which business are you {verb}?</FieldLabel>
        <select
          ref={businessRef}
          className={inputCls}
          value={businessId ?? ''}
          onChange={(e) => {
            setBusinessId(e.target.value ? Number(e.target.value) : null)
            /*
             * The escape is dropped on any change of business, and here rather
             * than in the fetch effect above so that reopening this dialog to
             * correct something else restores the tick instead of clearing it.
             * "This business has no BizTrack permit" was said about the shop
             * selected at the time; carried across it would stand in for a shop
             * whose permits are sitting right there in the list.
             */
            setDeclaredNone(false)
          }}
          // Momentary, not a field held shut by another answer: there is
          // nothing to choose between until the list has arrived.
          disabled={businessesLoading}
        >
          <option value="">{businessesLoading ? 'Loading your businesses…' : 'Select a business…'}</option>
          {ownedBusinesses.map((b) => (
            <option key={b.id} value={b.id}>
              {b.name}
            </option>
          ))}
        </select>
      </label>
      {!businessesLoading && ownedBusinesses.length === 0 && (
        <p className="mt-2 text-xs text-ink-secondary">
          You have no registered businesses yet. Start a new application instead.
        </p>
      )}

      {/* ── 2. Which permit ──────────────────────────────────────────────── */}
      {businessId !== null && (
        <div className="mt-5">
          <FieldLabel required>Which permits are you {verb}?</FieldLabel>
          <p className="mb-2 text-xs text-ink-secondary">
            Tick every permit this filing covers. You can choose more than one.
          </p>
          {loadingPermits ? (
            <p className="text-xs text-ink-secondary">Loading this business’s permits…</p>
          ) : loadError ? (
            <p role="alert" className="text-xs font-medium text-s-red">
              {loadError} You can still carry on and upload your paper permit under Documentary
              Requirements.
            </p>
          ) : (
            <ul
              ref={permitsRef}
              aria-label={`Which permits are you ${verb}?`}
              className="divide-y divide-line overflow-hidden rounded-lg border border-input-border bg-white"
            >
              {permits.map((p) => {
                const chosen = permitIds.includes(p.id)
                const days = p.days_until_expiry
                // Never colour alone: the word says expired or not.
                const state =
                  days === null
                    ? null
                    : days < 0
                      ? { label: 'Expired', cls: 'text-s-red' }
                      : days <= 60
                        ? { label: 'Expires soon', cls: 'text-ink' }
                        : { label: 'Valid', cls: 'text-ink-secondary' }
                return (
                  // Presentational so the radios are the radiogroup's own
                  // children, not list items wrapping them.
                  <li key={p.id}>
                    <label
                      className={`flex w-full cursor-pointer items-center gap-3 px-4 py-3 text-left transition-colors ${
                        chosen ? 'bg-input' : 'hover:bg-royal-tint'
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={chosen}
                        onChange={() => {
                          /*
                           * Appended, never inserted: the first tick is the
                           * primary and the renewal chain is keyed on it, so
                           * the order the applicant ticked in IS the answer.
                           * Untick-and-retick is how you change which is
                           * primary, which is the only honest way to say it
                           * without a second control asking the same thing.
                           */
                          setPermitIds((current) =>
                            current.includes(p.id)
                              ? current.filter((id) => id !== p.id)
                              : [...current, p.id],
                          )
                          // Naming a permit and declaring there is none are
                          // contradictory, so one unsets the other rather than
                          // both being held at once. The server resolves it the
                          // same way, but the boxes have to LOOK consistent.
                          setDeclaredNone(false)
                        }}
                        className="h-4 w-4 shrink-0 accent-royal"
                      />
                      {/*
                       * Number, type and validity dates together, because one
                       * of them alone does not tell two permits apart: a shop
                       * renewing late can hold last year's Mayor's Permit and
                       * this year's, same type, different dates.
                       */}
                      <span className="min-w-0 flex-1">
                        <span className="tnum block text-sm font-semibold text-ink">
                          {p.permit_number}
                        </span>
                        <span className="block text-xs text-ink-secondary">
                          {p.permit_type?.name ?? 'Permit'} · {permitValidity(p)}
                        </span>
                      </span>
                      {state && (
                        <span className={`shrink-0 text-xs font-semibold ${state.cls}`}>
                          {state.label}
                        </span>
                      )}
                    </label>
                  </li>
                )
              })}
              {/*
               * THE ESCAPE, as an option rather than as the absence of one.
               *
               * Year one of BizTrack is almost entirely renewals of permits
               * issued on paper by the old counter process, so "no permit in
               * the register" is the ordinary case and not an error — trapping
               * those applicants behind a list they cannot appear in would
               * shut the renewal flow to most of the city. That much was
               * always right.
               *
               * What was wrong is that it was silent. Where the list came back
               * empty the question was not asked at all, and the filing went
               * out carrying a null nobody had been shown. A null that nobody
               * answered looks exactly like a null somebody chose, and the
               * register cannot tell the two apart afterwards: five of the
               * seven renewals-of-nothing are businesses that hold no permit
               * and were never made to say so.
               *
               * So it sits in the same radiogroup as the permits, last,
               * because it is the same question — and one click is not a
               * burden, it is the difference between an answer and a gap.
               */}
              <li>
                <label
                  className={`flex w-full cursor-pointer items-center gap-3 px-4 py-3 text-left transition-colors ${
                    declaredNone ? 'bg-input' : 'hover:bg-royal-tint'
                  }`}
                >
                  <input
                    type="checkbox"
                    checked={declaredNone}
                    onChange={() => {
                      setDeclaredNone((d) => !d)
                      // Still exclusive with the list above, even though the
                      // list is no longer exclusive within itself: "none of
                      // these" and "these three" cannot both be true.
                      setPermitIds([])
                    }}
                    className="h-4 w-4 shrink-0 accent-royal"
                  />
                  <span className="min-w-0 flex-1">
                    <span className="block text-sm font-semibold text-ink">
                      {permits.length > 0
                        ? 'None of these — my permit was issued on paper'
                        : 'This business has no permit issued through BizTrack'}
                    </span>
                    <span className="block text-xs text-ink-secondary">
                      Upload your paper permit under Documentary Requirements and we will carry on
                      from there.
                    </span>
                  </span>
                </label>
              </li>
            </ul>
          )}

          {/*
           * ── The other half of the question: a file where there is no tick ──
           *
           * Every permit type this business holds that is NOT ticked above is a
           * form being renewed without a BizTrack record behind it. The counter
           * asks for the paper copy in that case, so this does too — beside the
           * tick it stands in for, rather than three steps later under
           * Documentary Requirements where the connection to this question is
           * lost.
           *
           * Optional on purpose. A permit left unticked because the applicant
           * is simply not renewing it this year is an ordinary answer, and
           * demanding a file for it would make "no" impossible to say. What
           * this offers is a place to put the copy, not a gate.
           */}
          {!loadingPermits && uncoveredTypes.length > 0 && (
            <div className="mt-4 rounded-lg border border-input-border bg-royal-tint/40 p-4">
              <p className="text-[13px] font-semibold text-ink">
                Not renewing one through BizTrack? Attach the paper copy.
              </p>
              <p className="mt-1 text-xs leading-relaxed text-ink-secondary">
                {uncoveredTypes.length === 1
                  ? 'One permit type is not ticked above.'
                  : `${uncoveredTypes.length} permit types are not ticked above.`}{' '}
                Attaching the certificate you hold lets that office review it with this filing.
              </p>
              <ul className="mt-3 space-y-2">
                {uncoveredTypes.map((t) => {
                  const held = paperCopies[t.id]
                  const rejected = uploadErrors[t.id]
                  return (
                    <li
                      key={t.id}
                      className="flex flex-wrap items-center gap-3 rounded-lg border border-input-border bg-white px-4 py-2.5"
                    >
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm font-medium text-ink">{t.name}</span>
                        {held ? (
                          <span className="block truncate text-xs text-ink-secondary">
                            {held.file.name} · {formatBytes(held.file.size)}
                          </span>
                        ) : rejected ? (
                          <span role="alert" className="block text-xs font-medium text-s-red">
                            {rejected}
                          </span>
                        ) : (
                          <span className="block text-xs text-ink-secondary">
                            No copy attached — optional
                          </span>
                        )}
                      </span>
                      {/*
                       * A label wrapping a hidden input, not a button that
                       * clicks one: the label IS the control, so it is
                       * reachable and announced without the click-forwarding
                       * that leaves a screen reader with a button that says
                       * nothing about what it opens.
                       */}
                      <label className="shrink-0 cursor-pointer rounded-lg border border-royal px-3 py-1.5 text-xs font-semibold text-royal transition-colors hover:bg-royal-tint">
                        {held ? 'Replace' : 'Upload'}
                        <span className="sr-only"> paper copy of {t.name}</span>
                        <input
                          type="file"
                          accept={ACCEPT_ATTR}
                          className="sr-only"
                          onChange={(e) => {
                            const file = e.target.files?.[0]
                            // Let the same file be picked twice — after a
                            // rejection the input would otherwise be inert.
                            e.target.value = ''
                            if (!file) return
                            const why = fileRejection(file)
                            if (why) {
                              setUploadErrors((m) => ({ ...m, [t.id]: why }))
                              setPaperCopies((m) => {
                                const next = { ...m }
                                delete next[t.id]
                                return next
                              })
                              return
                            }
                            setUploadErrors((m) => {
                              const next = { ...m }
                              delete next[t.id]
                              return next
                            })
                            setPaperCopies((m) => ({
                              ...m,
                              [t.id]: { permitTypeId: t.id, permitTypeName: t.name, file },
                            }))
                          }}
                        />
                      </label>
                      {held && (
                        <button
                          type="button"
                          onClick={() =>
                            setPaperCopies((m) => {
                              const next = { ...m }
                              delete next[t.id]
                              return next
                            })
                          }
                          className="shrink-0 text-xs font-semibold text-ink-secondary underline"
                        >
                          Remove
                          <span className="sr-only"> paper copy of {t.name}</span>
                        </button>
                      )}
                    </li>
                  )
                })}
              </ul>
            </div>
          )}
        </div>
      )}


      {/*
       * ── 3. What is being amended (items 82/84) ───────────────────────────
       *
       * Asked here rather than left behind on the business step, because it is
       * the same decision this dialog exists to make: which record, and what
       * about it is changing. The paper BPLO form prints "Amendment from:" as a
       * header block above the business details for exactly that reason, and
       * the wizard's draft guard already refuses to write an amendment draft
       * until it is answered — so leaving it three steps in only meant the
       * applicant met the refusal before they met the question.
       */}
      {applicationType === 'amendment' && (
        <fieldset ref={amendmentRef} className="mt-5 border-0 p-0">
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
                  onChange={(e) => setAmendment((a) => ({ ...a, [kind.key]: e.target.checked }))}
                  className="h-4 w-4 shrink-0 accent-royal"
                />
                <span>{kind.label}</span>
              </label>
            ))}
            {/*
             * "Others (specify)" is one control, not a checkbox with a box
             * beside it: on the paper you cannot tick Others without writing
             * the other in, so typing IS ticking and a separate tick could
             * only ever contradict the text.
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

          {/*
           * ── A3 · "Amendment: From ___ To ___" ──────────────────────────
           *
           * Shown under Ownership because that is the tick it belongs to: the
           * paper's From/To row lists the four legal structures, and a change
           * of structure IS a change of ownership. Ticking Location does not
           * make a sole proprietorship into a corporation, so asking there
           * would be asking a question with no answer.
           *
           * Not required even when Ownership is ticked. Ownership also covers
           * a new co-owner or a transferred proprietorship, neither of which
           * changes the structure — forcing a From/To would make those
           * applicants invent a conversion that did not happen.
           */}
          {amendment.ownership && (
            <div className="mt-3 rounded-lg border border-input-border bg-white px-4 py-3">
              <p className="text-[13px] font-semibold text-ink">
                Did the business structure change?
              </p>
              <p className="mt-1 text-xs text-ink-secondary">
                Only if it converted from one legal form to another. Leave both blank otherwise.
              </p>
              <div className="mt-3 grid gap-3 sm:grid-cols-2">
                {(
                  [
                    { key: 'fromRegistrationType' as const, label: 'From' },
                    { key: 'toRegistrationType' as const, label: 'To' },
                  ]
                ).map((side) => (
                  <label key={side.key} className="block">
                    <FieldLabel>{side.label}</FieldLabel>
                    <select
                      className={inputCls}
                      value={amendment[side.key]}
                      onChange={(e) =>
                        setAmendment((a) => ({ ...a, [side.key]: e.target.value }))
                      }
                    >
                      <option value="">Not changing</option>
                      {REGISTRATION_TYPES.map((rt) => (
                        <option key={rt.value} value={rt.value}>
                          {rt.label}
                        </option>
                      ))}
                    </select>
                  </label>
                ))}
              </div>
              {/*
               * A conversion to the structure it already is is not a
               * conversion. Said rather than blocked: it is a slip worth
               * naming, not a filing worth refusing, and the BPLO can read
               * "from corporation to corporation" for the typo it is.
               */}
              {amendment.fromRegistrationType !== '' &&
                amendment.fromRegistrationType === amendment.toRegistrationType && (
                  <p className="mt-2 text-xs font-medium text-ink">
                    From and To are the same structure — check which one changed.
                  </p>
                )}
            </div>
          )}
        </fieldset>
      )}

      {/*
       * Tied to Confirm by aria-describedby, so pressing it — or simply
       * tabbing onto it — reads out what is still missing. Rendered
       * unconditionally once `attempted`, rather than as a live `role="alert"`,
       * so it is a stable description of the button and not a message that
       * fires again on every keystroke.
       */}
      {blocked && (
        <p
          id={reasonId}
          className={`mt-5 text-xs font-medium ${attempted ? 'text-s-red' : 'sr-only'}`}
        >
          {blocked.reason}
        </p>
      )}
      {confirmError && (
        <p role="alert" className="mt-3 text-xs font-medium text-s-red">
          {confirmError}
        </p>
      )}
    </ProtoModal>
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
   * Section A's two fieldsets, so a blocked Next can put the cursor on the
   * question it is complaining about rather than at the top of the step.
   * Separate refs because both can be on screen at once under a Yes.
   */
  const a1Ref = useRef<HTMLFieldSetElement | null>(null)
  const amendmentRef = useRef<HTMLFieldSetElement | null>(null)

  /*
   * ── No clearance state here, deliberately ─────────────────────────────
   *
   * `clearanceRows`, `officeData`, `officeFormsVersion`, `pendingOfficeJump`
   * and `officeReturn` all lived here to run the in-wizard LGU Clearances step
   * and the office sheets it spawned. Every one of them is gone: the wizard no
   * longer reads, writes or waits on a clearance.
   *
   * <ClearanceStage> holds the equivalent state for itself on
   * /applications/:id/clearances, which is where the six are decided now — and
   * it is the better home for it, because the stage does not open until the
   * first payment has cleared and the application unarguably exists. The four
   * pieces of choreography that are NOT coming back with it are the ones that
   * only existed because a sheet was a wizard step: the pending jump (a sheet
   * joined `sequence` a render after Apply), the return-to-cards flag, the
   * office-form version counter, and the sheet's own save branch.
   */
  const [form, setForm] = useState<FormState>(EMPTY)

  /**
   * The signed-in account, used to prefill the paper's contact and name fields.
   *
   * Items A7, A8 and 11 all ask for something the applicant already gave at
   * sign-up: `users` holds mobile_number, email, first_name, middle_name,
   * last_name, SUFFIX and GENDER. The fields were missing from this form
   * entirely, and the officer's sheet quietly showed the account's values in
   * their place — which is a different fact, and wrong the moment a corporation
   * files with a staff member's login.
   *
   * So the fields exist now, in the places the paper prints them, filled in
   * ahead. Editable, because the business's contact number is not necessarily
   * the filer's, and stored on the BUSINESS, so correcting one never edits a
   * profile.
   */
  const account = useAuth((s) => s.user)
  const accountPrefilledRef = useRef(false)
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
  /*
   * Every permit the filing covers, primary first. `priorPermitId` above stays
   * the primary because the renewal chain, analytics and the BPLO form header
   * all read it; this is the rest of the answer, and the two are written
   * together so they can never drift.
   */
  const [priorPermitIds, setPriorPermitIds] = useState<number[]>([])
  /*
   * Paper copies chosen in the identify dialog, waiting for a draft to attach
   * them to. The dialog runs before the application exists, so these sit here
   * until `ensureDraft` has an id and `flushPaperCopies` can upload them.
   */
  const [pendingPaperCopies, setPendingPaperCopies] = useState<PaperCopy[]>([])
  /*
   * The other half of the prior-permit answer: whether a null `priorPermitId`
   * is the applicant saying "this business has no BizTrack permit" or the
   * question never having been put. Held separately rather than folded into
   * `priorPermitId` because a tri-state number would make every reader that
   * only cares about the id carry the distinction too.
   */
  const [priorPermitDeclaredNone, setPriorPermitDeclaredNone] = useState(false)
  const priorPermitAnswered = priorPermitId !== null || priorPermitDeclaredNone
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

  /*
   * Owner's existing businesses (only needed to seed renewal/amendment).
   *
   * Deliberately NOT filtered to businesses holding a permit, though that
   * looks like the question this chooser is asking. A renewal may be filed for
   * a business with no permit in this system at all — its permit was issued on
   * paper, which in year one is the common case, and `prior_permit_declared_none`
   * exists so the applicant can say exactly that. Filtering them out traps the
   * people the escape was built for.
   *
   * The list is bounded (PICKER_PAGE_SIZE) and the API now orders permit
   * holders first, so the businesses that can be renewed surface at the top of
   * a long list instead of falling off the end of it. See
   * BusinessController::index for the defect that ordering fixes.
   */
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
  /*
   * Files per documentary requirement, keyed by document-type id.
   *
   * A LIST per requirement, not one file. It was `Record<number, UploadedFile>`
   * and every upload replaced the last, on the reasoning that "the officer never
   * sees two files for one line" — which reads as tidiness and cost applicants
   * real documents. A lease runs to several pages, a barangay clearance arrives
   * front-and-back, a sketch plan comes as two scans; there was no way to attach
   * the second without silently deleting the first, and the screen said "click
   * to replace" rather than warning that it would.
   *
   * "Other Requirements" already worked this way (`otherDocs`), which is what
   * made the restriction look deliberate rather than incidental. It was neither
   * enforced nor needed anywhere else: `application_documents` has no unique
   * index on `(application_id, document_type_id)`, `DocumentController::store`
   * refuses nothing, and the officer's review sheet maps `app.documents` flat —
   * so a second file for one requirement already rendered correctly everywhere
   * that reads one. The whole constraint lived in this map's type.
   */
  const [uploaded, setUploaded] = useState<Record<number, UploadedFile[]>>({})
  // "Other Requirements" allows multiple files (repeatable uploads).
  const [otherDocs, setOtherDocs] = useState<UploadedFile[]>([])
  const [uploadingType, setUploadingType] = useState<number | null>(null)
  const [removingDoc, setRemovingDoc] = useState<number | null>(null)
  const [tracking, setTracking] = useState<string | null>(null)
  /*
   * `payMethod`, `receipt` and `payError` were here and are gone with the
   * payment itself. The wizard takes no money: BPLO approves the form, and the
   * Tax Order of Payment is settled on PayPage afterwards.
   */

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
  /*
   * ── Item 110 — the entry dialog ────────────────────────────────────────
   *
   * `null` means the wizard is in charge of the screen. `'entry'` means it has
   * not properly opened yet — the applicant is being asked which permit this
   * filing is against before anything is prefilled from it. `'change'` means
   * they came back to correct that answer from the summary on Business
   * Information, which is the difference Cancel turns on: leaving, versus
   * putting back what they had.
   *
   * Opened straight away for a fresh /apply?type=renewal. NOT for a reopened
   * draft — that one already answered this, and the hydration effect below
   * reopens it only in the one case where the saved draft is missing the
   * answer AND there are permits it could have named.
   */
  const [identify, setIdentify] = useState<'entry' | 'change' | null>(
    isReuse && !draftIdParam ? 'entry' : null,
  )
  const [confirmingIdentity, setConfirmingIdentity] = useState(false)
  const [identifyError, setIdentifyError] = useState<string | null>(null)
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

  /**
   * Renewal/amendment: pull the prior permit + prefill fields for a business.
   *
   * `preloaded` is the response the entry modal (item 110) already fetched to
   * build its permit list. Re-requesting it would be a second GET of the same
   * thing between one click and the next, and the answer it returns is what
   * the applicant made their choice on — so the choice and the prefill are the
   * same response, not two reads that could disagree.
   *
   * Returns whether the prefill landed. A caller that is about to record which
   * permit is being renewed must not do so against a business whose prefill
   * failed: that is a filing pointing at a permit and holding a blank form.
   */
  async function selectBusinessForReuse(
    selectedId: number | null,
    preloaded?: PrefillResult,
  ): Promise<boolean> {
    setPrefillBusinessId(selectedId)
    setPriorPermitId(null)
    // Both halves of the answer belong to the business it was given about.
    setPriorPermitDeclaredNone(false)
    setPrefillNote(null)
    setRenewablePermits([])
    if (!selectedId) {
      // Keep the permit selection: the section map never changes mid-flow.
      setForm((f) => ({ ...EMPTY, permit_type_ids: f.permit_type_ids }))
      return true
    }
    setPrefilling(true)
    setSubmitError(null)
    try {
      const result =
        preloaded ??
        (await businesses.prefill(selectedId, applicationType as 'renewal' | 'amendment'))
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
        mobile_number: b.address.mobile_number || account?.mobile_number || '',
        email: b.address.email || account?.email || '',
        owner_surname: b.owner?.surname || account?.last_name || '',
        owner_given_name: b.owner?.given_name || account?.first_name || '',
        owner_middle_name: b.owner?.middle_name || account?.middle_name || '',
        owner_suffix: b.owner?.suffix || account?.suffix || '',
        owner_gender: b.owner?.gender || account?.gender || '',
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
        capital_investment: formatAmountInput(String(b.capital_investment ?? '')),
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
      setPriorPermitDeclaredNone(false)
      if (result.last_permit) {
        setPrefillNote(`Prefilled from your last permit ${result.last_permit.permit_number}.`)
      } else {
        setPrefillNote('Prefilled from your last application.')
      }
      return true
    } catch (err) {
      setSubmitError(toApiError(err).message)
      setPrefillBusinessId(null)
      return false
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
  async function loadRenewablePermits(
    bid: number,
    type: 'renewal' | 'amendment',
  ): Promise<Permit[]> {
    setLoadingPermits(true)
    try {
      const result = await businesses.prefill(bid, type)
      const list = result.renewable_permits ?? []
      setRenewablePermits(list)
      return list
    } catch {
      // Non-fatal: the summary says it has nothing to offer, and the applicant
      // can still carry on and upload the paper permit.
      setRenewablePermits([])
      return []
    } finally {
      setLoadingPermits(false)
    }
  }

  /**
   * Items 50/110 — commit what the entry dialog asked, and open the wizard.
   *
   * This is the ONLY writer of `priorPermitId` outside a draft reopen. Naming
   * the permit used to also tick its clearance in the LGU Section, on the
   * reasoning that renewing a sanitary permit nobody has asked the City Health
   * Office to look at is not a renewal of anything. That reasoning still holds
   * — it just is not this screen's to act on any more. Adding a SANITARY permit
   * type here would put the City Health Office's fees onto the business
   * permit's own Tax Order of Payment, which is the accrual this restructure
   * exists to separate. The renewal is asked for on the LGU Clearances stage,
   * with its fee stated before it is committed to.
   */
  async function confirmIdentity(identity: FilingIdentity) {
    setIdentifyError(null)
    setConfirmingIdentity(true)
    try {
      /*
       * Only re-prefill when the BUSINESS changed. Reopening the dialog to
       * correct the permit on a half-filled draft must not pull the registry's
       * copy of the business back over everything the applicant has since
       * typed — the permit is the only thing they came back to change.
       */
      if (identity.businessId !== prefillBusinessId) {
        const ok = await selectBusinessForReuse(identity.businessId, identity.prefill)
        if (!ok) {
          // Stay open. A filing that names a permit but whose form never
          // loaded is the one state worse than not having started.
          setIdentifyError('We could not open that business. Try again, or choose another.')
          return
        }
      }
      setPriorPermitId(identity.permitId)
      setPriorPermitIds(identity.permitIds)
      // Written after `selectBusinessForReuse`, which clears both halves: the
      // dialog's answer is about the business it just settled on, so it has to
      // land last or the clear would eat it.
      setPriorPermitDeclaredNone(identity.declaredNone)
      setAmendment(identity.amendment)
      /*
       * Queued, not uploaded. There may be no draft yet — on entry there
       * certainly is not — and a file uploaded to nothing is a file lost. The
       * flush happens where an id is guaranteed, in `saveDraft`.
       */
      setPendingPaperCopies(identity.paperCopies)
      setIdentify(null)
    } finally {
      setConfirmingIdentity(false)
    }
  }

  /**
   * Item 110 — backing out of the entry dialog.
   *
   * From `change` this is free: the dialog held its own copy of the answers
   * and wrote none of them, so closing it is genuinely "keep what I had".
   *
   * From `entry` there is nothing to go back TO — the wizard behind is blank
   * and unsaved, and leaving the dialog up over an empty form the applicant
   * cannot use would be a dead end. So it leaves, to wherever they can pick
   * this up again: drafts if there is a draft, the applications list if not.
   */
  function cancelIdentity() {
    if (identify === 'change') {
      setIdentifyError(null)
      setIdentify(null)
      return
    }
    navigate(draftIdParam ? '/drafts' : '/applications')
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
  /*
   * The whole row, not just the name: the zoning step now also needs the
   * barangay's CPDO map path and the classifications drawn on it, and both ride
   * along on the same reference payload. `barangayName` stays as the narrower
   * thing the zoning modal already reads, rather than making that dialog reach
   * into an object for one field.
   */
  const selectedBarangay = barangays.find((b) => String(b.id) === form.barangay_id) ?? null
  const barangayName = selectedBarangay?.name

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
   * ── Business Location Insights, for the pin as it stands ──────────────────
   *
   * This used to be frozen when the zoning modal opened, on the reasoning that
   * the point reported must be the point the applicant was told about. Correct
   * for a modal, and beside the point now: the panel has moved onto the map step
   * itself (see LocationInsightsPanel's docblock), so the point being reported
   * IS the pin, live, and the applicant is looking at both at once.
   *
   * `livePin` is what the pin currently says. It is not what gets fetched.
   *
   * The PSIC id is pulled out into its own binding so it can be the dependency.
   * Depending on `form.lines` would rebuild this object on every keystroke in a
   * line's capital field — none of which changes the question being asked — and
   * that identity churn would restart the debounce below each time.
   */
  const insightsPsicCodeId = form.lines[0]?.psic_code_id ?? null
  const livePin = useMemo<LocationInsightsQuery | null>(
    () =>
      form.latitude !== null && form.longitude !== null
        ? {
            latitude: form.latitude,
            longitude: form.longitude,
            psicCodeId: insightsPsicCodeId,
            businessId,
          }
        : null,
    [form.latitude, form.longitude, insightsPsicCodeId, businessId],
  )

  /*
   * The point actually looked up — `livePin` after it has held still.
   *
   * Every click on the map is a new coordinate, and an applicant hunting for
   * their own roof clicks repeatedly: a lookup fired on each one would put a
   * burst of requests at an endpoint that scans the register per call
   * (LocationInsights::nearby is a bounding box plus a haversine over every
   * business inside it), and the answers would land out of order. So the pin has
   * to stop moving for a moment before it becomes a question.
   *
   * 400 ms: longer than a double-click correction, short enough that a settled
   * pin does not feel ignored.
   *
   * Note the debounce is the SECOND guard, not the only one. useLocationInsights
   * depends on the query's scalar fields rather than its object identity, so a
   * re-render that produces an equal query refetches nothing at all. This one
   * exists for genuinely different coordinates arriving in quick succession.
   */
  const [insightsQuery, setInsightsQuery] = useState<LocationInsightsQuery | null>(null)
  useEffect(() => {
    if (livePin === null) {
      setInsightsQuery(null)
      return
    }
    const timer = window.setTimeout(() => setInsightsQuery(livePin), 400)
    return () => window.clearTimeout(timer)
  }, [livePin])

  const insights = useLocationInsights(insightsQuery)

  /*
   * True while the answer on screen belongs to a point that is no longer pinned.
   *
   * This is the trap the debounce sets. For those 400 ms — plus the round trip —
   * `insights.data` still holds the PREVIOUS point's figures while the marker is
   * already somewhere else, and `insights.loading` is false because that fetch
   * finished. Rendering it would put four confident numbers under a pin they
   * were never measured from, which is worse than any delay: a stale figure that
   * looks fresh is indistinguishable from a wrong one.
   *
   * Compared by value, since `livePin` is a fresh object on every render.
   */
  const insightsStale =
    livePin !== null &&
    (insightsQuery === null ||
      insightsQuery.latitude !== livePin.latitude ||
      insightsQuery.longitude !== livePin.longitude ||
      insightsQuery.psicCodeId !== livePin.psicCodeId ||
      insightsQuery.businessId !== livePin.businessId)

  /*
   * The radius the ring on the map is drawn at — the API's own `radius_m`, never
   * a 500 written here. Null until a lookup has answered, so before the first
   * response there is simply no ring rather than a guessed one.
   *
   * Deliberately NOT cleared while `insightsStale`. The radius is a property of
   * the lookup, not of the point, so the ring the applicant is looking at is
   * still the right size for the pin they just moved — dropping it would make
   * the ring blink out and back on every correction, which reads as the map
   * losing its place. The FIGURES go to loading; the ring does not.
   */
  const insightsRadiusM = insights.data?.radius_m ?? null

  /*
   * `carriedOverBusiness` is gone from this file.
   *
   * It was the business as every office sheet carries it — the name, address
   * and trade the sanitary, environmental, fire and occupancy forms all open by
   * asking for — assembled from the form the applicant was still typing.
   * Building it here was the whole argument for the clearances sitting late in
   * the wizard: a sheet opened before Location & Zoning and Business
   * Information would have nothing to prefill from.
   *
   * That argument is now moot in the strongest possible way. The sheets open
   * from a stage that runs after the application has been submitted AND paid
   * for, so the business is not merely typed, it is saved and on the record.
   * ClearanceStagePage builds the same object from `application.business`,
   * which is strictly better data than this ever was.
   */

  /*
   * The step sequence, which is now simply the phases.
   *
   * It used to be computed: office form sheets slotted in behind the LGU
   * Clearances step, appearing and disappearing as the applicant applied for
   * and withdrew clearances mid-flow. Three effects existed only to cope with
   * that — clamping `step` when a sheet vanished under it, marking newly-spawned
   * sheets as visited so the map would offer them, and the deferred jump that
   * waited for a sheet to join the sequence. All three are gone with it.
   *
   * `sequence` stays as a name rather than being replaced by BASE_PHASES at
   * every call site, because everything downstream (the map, `stepComplete`,
   * `jumpBlocked`, Part n of N) is written against "the running order" and
   * should not have to care that the running order is currently constant.
   */
  /*
   * ── The running order, which a renewal computes rather than inherits ─────
   *
   * A new application and an amendment still run the fixed BASE_PHASES. A
   * renewal does not, because MCG-BPLO-FO-002 does not: its section A1 decides
   * how much of the rest of the form exists.
   *
   *   A1 unanswered → the question, and nothing past it.
   *   A1 = No       → the question, then Review. Nothing on the paper after
   *                   section A applies to a business whose registration has
   *                   not changed; the details carry over from the permit
   *                   being renewed.
   *   A1 = Yes      → the sections covering what was TICKED in A2, then the
   *                   documentary requirements and the tax profile, then
   *                   Review.
   *
   * The A2 ticks choosing the steps is the "friendly" half of the request: a
   * renewal that only moved premises is asked about its address and nothing
   * else, rather than being walked through six sections to change one field.
   *
   * ── Which tick opens which section ──────────────────────────────────────
   *
   *   Location or Address of Business → `address`  (paper item B6)
   *   Ownership / Nature of Business  → `business` (B1 and the line-of-business
   *                                     table; the structure itself is A3, and
   *                                     that is asked on the step below)
   *   Others (specify)                → `business`, as the catch-all. An
   *                                     unnamed change has no section of its
   *                                     own, and the business details are where
   *                                     the most of it could live. The typed
   *                                     text rides along to the officer either
   *                                     way, so nothing is lost if the guess is
   *                                     wrong.
   *
   * `documents` and `fees` are NOT conditional under a Yes. The paper prints
   * its documentary requirements and its Gross Sales/Receipts column for every
   * renewal that fills in section B, and the fee engine is fed from `fees` —
   * gating them on a tick would price the filing off last year's figures
   * without anybody having said so.
   */
  const sequence: BasePhase[] = useMemo(() => {
    if (applicationType !== 'renewal') return BASE_PHASES

    if (amendment.hasChanges === null) return ['privacy', 'amendments']
    if (amendment.hasChanges === false) return ['privacy', 'amendments', 'review']

    const changed: BasePhase[] = []
    if (amendment.location) changed.push('address')
    if (amendment.ownership || amendment.nature || amendment.other.trim() !== '') {
      /*
       * Both halves of what used to be one step. Splitting Section B out must
       * not quietly take editable fields away from a renewal: before the split,
       * ticking Ownership or Nature opened economic organisation and tax
       * incentives along with everything else, because they lived on the same
       * step. Pushing only `business` here would have left a renewal no route to
       * them at all.
       */
      changed.push('business', 'operation')
    }

    return ['privacy', 'amendments', ...changed, 'documents', 'fees', 'review']
  }, [applicationType, amendment])

  const totalParts = sequence.length
  const stepIndex = Math.min(step, sequence.length - 1)
  const phase: BasePhase = sequence[stepIndex]
  const isLast = stepIndex === sequence.length - 1

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
   * Item 110 — the two lines the Business Information summary prints back.
   *
   * The REGISTERED name, not `form.name`: the summary answers "which record is
   * this filing against", and a renewal that is correcting a misspelt trading
   * name would otherwise show the correction as the thing it was chosen by.
   */
  const reuseBusinessName: string | null = useMemo(() => {
    if (prefillBusinessId === null) return null
    return (
      (ownedBusinesses.data ?? []).find((b) => b.id === prefillBusinessId)?.name ??
      form.name.trim() ??
      null
    )
  }, [ownedBusinesses.data, prefillBusinessId, form.name])

  const amendmentSummary: string | null = useMemo(() => {
    const parts = [
      ...AMENDMENT_KINDS.filter((k) => amendment[k.key]).map((k) => k.label),
      ...(amendment.other.trim() ? [amendment.other.trim()] : []),
    ]
    return parts.length === 0 ? null : parts.join(', ')
  }, [amendment])

  /*
   * `clearanceDecisions` is gone. Review & Submit used to list which of the six
   * had been applied for and which had a copy on file — a useful list when the
   * clearances were step 6 and the submission carried them.
   *
   * Under the new order there is nothing true for it to say. At the moment the
   * applicant reaches Review, no clearance has been decided and none can have
   * been: the stage that decides them does not open until the first payment
   * clears. A list here would always be empty, and an empty list on the last
   * screen before submission reads as "you chose no clearances" rather than
   * "you have not been asked yet". The Review copy says what actually happens
   * next instead.
   */

  /*
   * Required fields still missing on ANY step. This used to answer only for
   * the step being displayed, which meant the map had no way of knowing
   * whether a section was finished and fell back to "is it behind us?" — the
   * source of every tick that was showing on an empty section.
   */
  const missingFor = useCallback(
    (p: BasePhase): string[] => {
      switch (p) {
        /*
         * There is no 'clearances' branch, and its absence is a rule change
         * rather than a deletion.
         *
         * It used to require that at least one of the six had been DECIDED
         * before the wizard would let the applicant past — item 76's other
         * half — on the argument that a file reaching BPLO with no clearance
         * named is one the counter sends back. That argument does not survive
         * the reordering: the clearances are not part of this submission any
         * more, so a business permit application with none of them decided is
         * not incomplete, it is simply an application that has not reached the
         * clearance stage yet. Nobody can decide a clearance before paying.
         *
         * The corollary is that NOTHING in this wizard can require a
         * clearance, now or later. If a rule is ever needed that a permit is
         * not released without particular clearances, it belongs on the
         * release gate beside the balance-due check — the same place, and for
         * the same reason: both are conditions on the permit coming out, not
         * on the form going in.
         */
        case 'business': {
          const missing: string[] = []
          /*
           * Item 110 — these three are the entry dialog's questions, and the
           * dialog will not close until they are answered, so reaching this
           * step with any of them blank should be impossible. They are kept as
           * the backstop for the one route that could still get here — a draft
           * saved before the dialog existed — and they name the Change button
           * rather than a control on this step, because the controls that used
           * to answer them are no longer on it.
           */
          if (isReuse && prefillBusinessId === null) {
            missing.push(
              `The business you are ${applicationType === 'renewal' ? 'renewing' : 'amending'} — press Change above`,
            )
          }
          /*
           * Item 50/85: a business holds several permits with different expiry
           * dates, so "renew this business" names nothing an office can act on
           * — and neither does "amend this business".
           *
           * No longer waived when the list is empty. A business with nothing in
           * the register still has to say so, because the alternative is a null
           * that reads as an answer and was never given; that is precisely what
           * put seven renewals of nothing into the register, five of them on
           * businesses holding no permit at all.
           */
          if (isReuse && !priorPermitAnswered) {
            missing.push(
              `Which permit you are ${applicationType === 'renewal' ? 'renewing' : 'amending'} — press Change above`,
            )
          }
          /*
           * Items 82/84 — an amendment amending nothing is not a filing. The
           * counter would have to send it back to ask the question the form
           * was supposed to have asked, so it is asked before the wizard opens.
           */
          if (applicationType === 'amendment' && !amendmentChosen) {
            missing.push(
              'What is being amended (ownership, location, nature of business, or other) — press Change above',
            )
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
          return missing
        }
        /*
         * Section B. One check, and it is conditional rather than new: on the
         * paper, "Others ____" is a blank you cannot tick without filling in.
         * Ticking it here and leaving the blank empty records less than choosing
         * nothing at all would have.
         *
         * Nothing else on this step can be incomplete — economic organisation
         * and tax incentives are both optional, like every other field
         * transcribed from the paper (none of the three paper forms marks any
         * field required; every asterisk in this wizard is our own judgement).
         * So this step is passable empty, by design.
         */
        case 'operation': {
          const missing: string[] = []
          if (
            form.economic_organization === 'others' &&
            !form.economic_organization_others.trim()
          ) {
            missing.push('What “Others” means for your Economic Organization')
          }
          // Section B's own figures — B1 business area, B2 employees, B3 how
          // many of them live in the LGU. They write the fee draft like the rest
          // of the profile, so the check comes from the same place, scoped.
          missing.push(
            ...feeProfileMissing(
              feeDraft,
              { applicationType, permitCodes: [BUSINESS_PERMIT_CODE], lines: feeLines },
              'operation',
            ),
          )
          // BPLO item B7, which replaced the per-line capitalization on the fee
          // step. Same rules it had: required on a new filing, positive, bounded.
          missing.push(...capitalInvestmentMissing(form.capital_investment, applicationType))

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
          /*
           * The pin and the barangay are checked against each other HERE as
           * well as in the click handler, because the click handler only ever
           * sees one order of events: an applicant can drop a valid pin in
           * Acacia and then change the dropdown to Tonsuya, and nothing re-runs
           * onPick — the pin did not move.
           *
           * ── Why this is gated on `touched.barangay_id` ────────────────────
           *
           * Because the register is full of filings whose pin and barangay
           * already disagree, and blocking on those punishes the applicant for
           * our history. Nothing checked this until now, so pins were dropped
           * anywhere the old bounding box allowed: of 788 addresses on file
           * only 61 sit inside their own barangay, 543 are off by a median of
           * 1.4 km, and 184 are not in Malabon at all. A renewal prefills both
           * values from that record, so an unconditional check here would stop
           * essentially every renewal dead on section 2, with a message about a
           * pin the applicant never placed.
           *
           * So the rule is: we enforce what the applicant ENTERS, not what we
           * handed them. Touching the barangay means they have answered the
           * question and the answer must be consistent. Leaving the prefill
           * alone lets a legacy filing through — the map still draws their
           * barangay and their pin, so the disagreement is visible and fixable,
           * and CPDO checks the site regardless.
           *
           * A NEW filing is unaffected: its barangay starts empty, so it cannot
           * be submitted without being touched. Moving the pin is covered by
           * onPick, which refuses a mismatch at the moment of the click.
           */
          else if (barangayName !== undefined && touched.barangay_id) {
            const verdict = checkPin(form.latitude, form.longitude, barangayName)
            if (verdict.kind === 'wrong-barangay') {
              missing.push(`A pin inside ${barangayName} (it is currently in ${verdict.actual ?? 'neither'})`)
            }
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
        /*
         * Section A, and the two ways it can be incomplete.
         *
         * A1 unanswered blocks because the answer decides the length of the
         * form: letting Next through would have to pick No on the applicant's
         * behalf, and No is the answer that files "nothing changed" — a
         * statement about the business, not a blank field.
         *
         * A Yes that ticks nothing blocks for the reason the amendment gate
         * has always blocked: it claims a change and names none, so no office
         * can act on it and no section opens to describe it.
         */
        case 'amendments': {
          if (amendment.hasChanges === null) {
            return ['Whether anything has changed since your last permit']
          }
          if (
            amendment.hasChanges &&
            !amendment.ownership &&
            !amendment.location &&
            !amendment.nature &&
            amendment.other.trim() === ''
          ) {
            return ['Which details changed — tick at least one box']
          }
          return []
        }
        case 'documents':
          // One file satisfies a requirement; more are allowed and change
          // nothing here. `?.length` rather than presence because a requirement
          // whose last file was removed keeps no empty array (see the remove
          // handler) — but a future edit that left one must not read as done.
          return requiredDocs
            .filter((dt) => dt.is_required !== false && !uploaded[dt.id]?.length)
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
          return feeProfileMissing(
            feeDraft,
            { applicationType, permitCodes: [BUSINESS_PERMIT_CODE], lines: feeLines },
            // This step no longer owns the whole profile: paper section B's four
            // figures moved to Business Operation and are checked there.
            'fees',
          )
        case 'review':
          return []
      }
    },
    [
      form,
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
      /*
       * The whole object, because the `amendments` case reads five of its
       * fields and the step's gate is only correct if it recomputes when any
       * of them move. Memoised on `amendmentChosen` alone, answering No after
       * Yes left the old "tick at least one box" complaint standing over a
       * step that no longer asked the question.
       */
      amendment,
      priorPermitAnswered,
      /*
       * The barangay check reads this, so it belongs here.
       *
       * It happens to change in lockstep with `form.barangay_id`, which is
       * already a dependency, so leaving it out worked by luck rather than by
       * design — and only for as long as `barangayName` stays derived from the
       * form. If it ever comes from somewhere else the gate would go stale and
       * keep refusing a barangay the applicant has already corrected.
       */
      barangayName,
      /*
       * And the flag that decides whether that check runs at all. Unlike
       * `barangayName` this one does NOT move with anything else already
       * listed: it flips once, on first blur of the barangay field, while
       * `form.barangay_id` may not change at that moment at all. Omitted, the
       * gate would keep using the pre-blur answer and let a mismatch the
       * applicant just created walk straight through.
       */
      touched.barangay_id,
    ],
  )

  /** What is still missing on the step being displayed. */
  const stepMissing: string[] = useMemo(() => missingFor(phase), [missingFor, phase])

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
   * Where a reopened draft opens: the first section still wanting an answer.
   *
   * It opened on part 1 every time, which is the wrong place for all but the
   * applicant who abandoned the form immediately. Someone who left off at
   * Documentary Requirements came back to Data Privacy Consent and had to walk
   * forward through four finished sections to reach the one they were on — and
   * on a seven-part form that is how a draft stops being worth reopening.
   *
   * ── Computed, not remembered ──────────────────────────────────────────────
   *
   * The alternative is to store the last step the applicant was on. This is
   * better for two reasons. It needs no column and no write on every step
   * change; and it is right in the case a stored cursor gets wrong — an
   * applicant who filled parts 1-5, jumped BACK to part 2 to fix a typo and
   * closed the tab has a stored cursor of 2 and unfinished work at 6. "First
   * unfinished" answers "what do I still have to do", which is the actual
   * question. Where nothing is unfinished it lands on Review, which is the only
   * thing left to do.
   *
   * ── Once ──────────────────────────────────────────────────────────────────
   *
   * `landedRef` matters as much as the calculation. `stepComplete` recomputes on
   * every keystroke, so without the guard this would fire again the moment a
   * section became incomplete — dragging the applicant backwards out of the
   * part they were typing in, which is worse than the bug it fixes.
   *
   * Only for a REOPENED draft (`draftIdParam`). A new filing has nothing
   * answered and part 1 is already the first unfinished section, so running this
   * would land it exactly where it starts.
   */
  /*
   * Fill the account's answers in, once, and never over an answer.
   *
   * Each field is guarded with `||` so a value already in the form wins: a
   * renewal prefilled from an existing business, or a reopened draft, has real
   * answers and this must not overwrite them with the filer's own details. On a
   * blank new filing the guard passes and the account fills the gap.
   */
  useEffect(() => {
    if (accountPrefilledRef.current || !account) return
    /*
     * Wait for a reopened draft to finish loading.
     *
     * This effect fires as soon as the account is known, which on a draft is
     * long before the filing arrives — and hydration then replaces the WHOLE
     * form object, so every value put here was overwritten a second later by
     * the business's own (blank) ones. The fields rendered empty and the
     * prefill looked as though it had never run.
     *
     * Waiting is only half the fix: hydration itself now falls back to the
     * account for these fields, so a draft whose business has no owner row on
     * file still opens filled in. This guard is what stops the two racing.
     */
    if (hydrating) return
    accountPrefilledRef.current = true
    setForm((f) => ({
      ...f,
      mobile_number: f.mobile_number || (account.mobile_number ?? ''),
      email: f.email || account.email,
      owner_surname: f.owner_surname || account.last_name,
      owner_given_name: f.owner_given_name || account.first_name,
      owner_middle_name: f.owner_middle_name || (account.middle_name ?? ''),
      owner_suffix: f.owner_suffix || (account.suffix ?? ''),
      owner_gender: f.owner_gender || account.gender,
    }))
  }, [account, hydrating])

  const landedRef = useRef(false)
  useEffect(() => {
    if (landedRef.current || !draftIdParam) return
    /*
     * Wait for the answers AND for the reference data.
     *
     * `hydrating` alone was not enough. `missingFor` asks whether a line of
     * business is a real PSIC code and whether the permit types resolve — both
     * of which read `refs`, so while that request is still in flight a fully
     * answered step reports itself incomplete. This runs once, so it would have
     * settled on the wrong step and stayed there: an applicant reopening a
     * finished draft would land on Location & Zoning with nothing wrong on it.
     *
     * Computing it mid-hydration is the other half — that reads a blank form and
     * lands on part 1, which is the behaviour being removed.
     */
    if (hydrating || hydrateFailed || refs.loading) return

    landedRef.current = true
    const firstUnfinished = stepComplete.findIndex((done) => !done)
    setStep(firstUnfinished === -1 ? sequence.length - 1 : firstUnfinished)
  }, [draftIdParam, hydrating, hydrateFailed, refs.loading, stepComplete, sequence.length])

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
      // BPLO item B7. One figure for the whole business, which is what the paper
      // asks; the per-line `capitalization` on the fee profile is a different
      // thing and stays where the fee engine reads it.
      capital_investment: plainAmount(form.capital_investment) || undefined,
      /*
       * BPLO items 11 / 12. Sent as an object and always sent, so clearing a
       * prefilled name is stored as the blank it is — the controller treats an
       * ABSENT `owner` key as "this request is not about the owner" and leaves
       * the row alone, which is what a fee-profile-only save wants.
       */
      owner: {
        surname: form.owner_surname.trim() || undefined,
        given_name: form.owner_given_name.trim() || undefined,
        middle_name: form.owner_middle_name.trim() || undefined,
        suffix: form.owner_suffix.trim() || undefined,
        gender: form.owner_gender || undefined,
      },
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
        // BPLO items A7 and A8 — the business's own, prefilled from the account
        // but stored here, so editing one never edits a profile.
        mobile_number: form.mobile_number.trim() || undefined,
        email: form.email.trim() || undefined,
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
      data_privacy_consent: consent,
      permit_type_ids: form.permit_type_ids,
      ...(priorPermitIds.length > 0 ? { prior_permit_ids: priorPermitIds } : {}),
      ...(priorPermitId
        ? { prior_permit_id: priorPermitId }
        : /*
           * The escape rides on the create call rather than waiting for the
           * follow-up PUT, so a draft is never briefly on the server holding
           * the ambiguous null this whole change exists to abolish.
           */
          priorPermitDeclaredNone
          ? { prior_permit_declared_none: true }
          : {}),
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
    /*
     * Renewals send section A too now — MCG-BPLO-FO-002 asks A1/A2/A3 and the
     * API accepts them for both types. `new` still sends nothing: there is no
     * section A on MCG-BPLO-FO-001 to answer.
     *
     * A renewal that has not answered A1 sends nothing either. The dialog will
     * not let Confirm through without an answer, so an unanswered A1 here means
     * a draft reopened from before this question existed — and writing false
     * for it would turn "never asked" into "answered no" on a filing nobody
     * asked. That is the exact conversion `prior_permit_declared_none` was
     * added to stop happening one field over.
     */
    if (applicationType === 'new') return {}
    if (applicationType === 'renewal' && amendment.hasChanges === null) return {}

    return {
      amendment_ownership: amendment.ownership,
      amendment_location: amendment.location,
      amendment_nature: amendment.nature,
      amendment_other: amendment.other.trim() || null,
      // The API nulls both whenever A1 is No, so sending them unconditionally
      // cannot leave a stale conversion behind.
      amendment_from_registration_type: amendment.fromRegistrationType || null,
      amendment_to_registration_type: amendment.toRegistrationType || null,
    }
  }

  /**
   * Persist whatever the CURRENT step owns before leaving it, so every Next
   * (and every map jump) saves: the business fields and the fee profile both
   * round-trip through the API.
   *
   * The office-sheet branch is gone with the sheets. It saved the open sheet's
   * answers on the way out; <ClearanceStage> does that for itself now, from
   * the sheet it mounts over its own cards.
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
      if (phase === 'address' || phase === 'business' || phase === 'operation') {
        if (applicationId) {
          const bid = businessId ?? prefillBusinessId
          if (bid) await businesses.update(bid, businessPayload())
          // `operation` joins the two steps that describe the business, because
          // its two answers are columns on `businesses` like every other field
          // here. Leaving it out would have made economic organisation and tax
          // incentives the only answers in the wizard that never autosaved.
        } else if ((phase === 'business' || phase === 'operation') && canCreateDraft) {
          // The last section that describes the business, and so the earliest
          // point a draft can legally exist (item 69 folded Line of Business
          // into Location & Zoning, which now runs before this one). There has
          // to be something to attach documents to by the time uploads start,
          // even if the autosave debounce has not fired yet.
          await ensureDraftRaw()
        }
      } else if (phase === 'amendments') {
        /*
         * A renewal that answers No at A1 goes straight from here to Review,
         * so this step is the last chance to create the draft.
         *
         * The draft used to be created on leaving `business`, which was safe
         * while every filing passed through it. A No-path renewal never does —
         * its sequence is privacy → amendments → review — and without this it
         * would reach Review with no application id, no autosave having
         * anything to write to, and a Submit button with nothing to submit.
         *
         * Guarded on `canCreateDraft` for the same reason the `business`
         * branch is: a draft cannot be posted before the business it is for is
         * known, and on a renewal that is settled by the identify dialog long
         * before this step.
         */
        if (!applicationId && canCreateDraft) await ensureDraftRaw()
      } else if (phase === 'fees') {
        const id = await ensureDraftRaw()
        await applications.update(id, {
          fee_profile: buildFeeProfile(feeDraft, {
            applicationType,
            permitCodes: [BUSINESS_PERMIT_CODE],
            lineIds: form.lines.map((l) => l.psic_code_id),
            capitalInvestment: form.capital_investment,
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
    markVisited(sequence[target])
  }

  /*
   * The Apply → office sheet choreography lived here and is gone whole, taking
   * the general-purpose `jumpTo` with it.
   *
   * It was three pieces: a deferred jump (the sheet only joined `sequence` a
   * render after Apply, once the reloaded clearance row said so), a flag
   * remembering that the sheet had been reached from a card so its forward
   * button read "Save & back to clearances", and an effect clearing that flag
   * on any other exit. None of it has anywhere to run now — a sheet is not a
   * step of this wizard. <ClearanceStage> opens the sheet over its own cards
   * instead, which needs no jump at all.
   *
   * `advance` and `goTo` are the only two ways to move now, and both persist
   * the step being left for themselves. That is the invariant worth keeping:
   * every route out of a step saves it.
   */

  async function next() {
    if (stepMissing.length > 0) return
    /*
     * Zoning result (p30) — the conformity message, and only that.
     *
     * Location Insights used to be primed here, because the modal was where it
     * rendered. It is on the step itself now and follows the pin on its own, so
     * leaving the step is no longer an event the lookup cares about.
     */
    if (phase === 'address') {
      setShowZoning(true)
      return
    }
    await advance()
  }

  function closeZoning() {
    setShowZoning(false)
  }

  function back() {
    setStep((s) => Math.max(s - 1, 0))
  }

  /** Jump to any already-opened section from the map (persisting first). */
  async function goTo(index: number) {
    if (saving || index === stepIndex) return
    if (!visited.includes(sequence[index])) return
    // Moving forward re-checks every section being skipped, not just this one.
    if (jumpBlocked(index)) return
    const ok = await persistOnLeave()
    if (!ok) return
    setStep(index)
    markVisited(sequence[index])
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
     *
     * `priorPermitAnswered`, not `priorPermitId !== null`, and no waiver for a
     * business with an empty permit list. A draft written before the question
     * was put is a draft carrying a null nobody gave, and the register already
     * holds seven of those.
     */
    (!isReuse || priorPermitAnswered) &&
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
        capitalInvestment: form.capital_investment,
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
          /*
           * On every autosave, not only on creation. The tick can be given —
           * and taken back — at any point while the draft is open, and it is on
           * step 1 of seven, so a draft created later in the flow would
           * otherwise be created with `false` and never corrected.
           */
          data_privacy_consent: consent,
          // Items 82/84: what is being amended can change while the draft is
          // open, so it rides on every autosave, not only on creation.
          ...amendmentPayload(),
        })
        // Which permit is being renewed can change after the draft exists, and
        // it is not part of the general application update (item 50).
        if (isReuse) {
          await applications.setPriorPermit(
            id,
            priorPermitId,
            priorPermitDeclaredNone,
            priorPermitIds,
          )
        }
        /*
         * The paper copies the identify dialog took, now that there is
         * somewhere to put them. Uploaded through the held-clearance path
         * (`permitTypeId`, no document type) so each one arrives named after
         * the office that has to read it rather than as an untitled file.
         *
         * Cleared before the awaits, not after: a second autosave firing
         * mid-upload would otherwise find the same queue and attach every file
         * twice. A failure is swallowed on purpose — the applicant can still
         * attach the copy under Documentary Requirements, and a draft that
         * refuses to save because an optional attachment did not stick would
         * lose the answers that DID.
         */
        if (pendingPaperCopies.length > 0) {
          const queued = pendingPaperCopies
          setPendingPaperCopies([])
          for (const copy of queued) {
            try {
              await documents.upload(id, null, copy.file, copy.permitTypeId)
            } catch {
              /* Optional attachment; Documentary Requirements is the retry. */
            }
          }
        }
      } else {
        await applications.update(id, { fee_profile: feeProfile, payment_mode: paymentMode })
      }
      // The office sheets used to be flushed here alongside everything else.
      // They are not this wizard's to save any more — <ClearanceStage> saves
      // the sheet it has open, on the stage where it is filled in.
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
        // No `officeData`: typing on an office sheet is still an edit that has
        // to make something dirty, but the sheet and its debounce both belong
        // to the clearance stage now.
        feeDraft,
        paymentMode,
        applicationType,
        priorPermitId,
        // Ticking "no BizTrack permit" is an edit like any other. Left out, a
        // draft whose only change was taking the escape would never be written
        // — and the escape is the answer that lets it submit.
        priorPermitDeclaredNone,
        // Items 82/84: ticking a box is an edit, so autosave has to see it.
        amendment,
        /*
         * The Data Privacy tick, and leaving it out is what made the first
         * attempt at persisting consent do nothing at all.
         *
         * This object is the ONLY thing that decides whether a draft is dirty:
         * the effect below compares it against the last saved copy and returns
         * early when they match. `autosave()` was already sending
         * `data_privacy_consent` — correctly — and was never once called after
         * the box was ticked, because ticking it changed nothing here. The
         * header sat on "All Changes Saved" while the answer went nowhere,
         * which is the worst version of the bug: it reported success.
         *
         * Anything an applicant can change has to appear in this object. A field
         * that is saved but not watched is invisible to the only thing that
         * triggers a save.
         */
        consent,
      }),
    [
      title,
      form,
      feeDraft,
      paymentMode,
      applicationType,
      priorPermitId,
      priorPermitDeclaredNone,
      amendment,
      consent,
    ],
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
        /*
         * Everything transcribed from the paper's section A is an input of THIS
         * part, so "clear the inputs on this part" has to take it. Leaving any
         * of them standing would clear the fields around an answer and leave the
         * answer behind — the bug the privacy branch below documents, arriving
         * from the other direction.
         *
         * Items B6 and B8 were cleared here too and are not any more: they moved
         * to the Business Operation step and are cleared by its own branch. Clear
         * All is scoped to "this part", and a button that reached into the next
         * step would be the same bug it exists to avoid.
         */
        telephone: '',
        website: '',
        president_officer_name: '',
        citizenship: '',
        capital_participation_filipino: '',
      }))
      if (isReuse) {
        setPrefillBusinessId(null)
        setPriorPermitId(null)
        // Both halves, or Clear All would leave "no BizTrack permit" ticked
        // about a business it no longer has.
        setPriorPermitDeclaredNone(false)
        setPrefillNote(null)
        setRenewablePermits([])
        // Items 82/84: the amendment block is part of this section, so Clear
        // All has to take it too or it would clear the fields around an answer
        // and leave the answer standing.
        setAmendment(EMPTY_AMENDMENT)
      }
    } else if (phase === 'operation') {
      // Section B's three inputs. `has_tax_incentives` resets to false rather
      // than to null because the column is `boolean not null default false` —
      // there is no "unanswered" to return it to.
      setForm((f) => ({
        ...f,
        economic_organization: '',
        economic_organization_others: '',
        has_tax_incentives: false,
        capital_investment: '',
      }))
      /*
       * Section B's figures live in the fee draft, not in `form`, so Clear All
       * on this step has to reach into it as well — otherwise the button clears
       * the fields around them and leaves four answers standing, which is the
       * bug the privacy branch below documents.
       */
      setFeeDraft((d) => ({
        ...d,
        floor_area_sqm: '',
        employees: '',
        male_employees: '',
        female_employees: '',
        employees_in_lgu: '',
        delivery_vehicles_motorized: '',
        delivery_vehicles_other: '',
      }))
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
    }
    /*
     * The office-sheet branch went with the sheets. Clearing one is now the
     * clearance stage's problem, on the screen where it is filled in.
     */
    setTouched({})
    setShowClear(false)
  }

  /**
   * Attach one or more files to a documentary requirement. Each one ADDS.
   *
   * It used to replace: upload the new file, then delete the previous one, so a
   * requirement held exactly one attachment. That silently destroyed the earlier
   * file, and the only warning was the words "click to replace" on a control
   * most people read as "click to attach".
   *
   * Several files at once are accepted because the picker now allows it, and
   * they are uploaded ONE AT A TIME rather than in parallel. Two reasons, both
   * about what the applicant sees when something goes wrong: the size and type
   * check is per file, so a rejected third file must not take two good ones with
   * it; and each response can carry OCR suggestions, which are applied as they
   * arrive rather than raced.
   */
  async function handleUpload(docTypeId: number, files: File[]) {
    if (!applicationId || files.length === 0) return

    setUploadingType(docTypeId)
    setSubmitError(null)
    try {
      for (const file of files) {
        const rejection = fileRejection(file)
        if (rejection) {
          // Named, because "a file was rejected" on a multi-file drop leaves the
          // applicant checking all of them to find out which.
          setSubmitError(files.length > 1 ? `${file.name}: ${rejection}` : rejection)
          continue
        }

        const doc = await documents.upload(applicationId, docTypeId, file)
        setUploaded((u) => ({
          ...u,
          [docTypeId]: [...(u[docTypeId] ?? []), { id: doc.id, name: file.name, size: file.size }],
        }))

        // OCR-lite: surface any suggestions from the upload response (v2).
        if (doc.ocr_suggestions && Object.keys(doc.ocr_suggestions).length > 0) {
          setOcr(doc.ocr_suggestions)
        }
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
          // Drop the one file, keep the requirement's others. The key is deleted
          // only when it empties, so `!uploaded[id]?.length` stays the single
          // test for "this requirement is still outstanding".
          const rest = (u[docTypeId] ?? []).filter((f) => f.id !== doc.id)
          const next = { ...u }
          if (rest.length > 0) next[docTypeId] = rest
          else delete next[docTypeId]

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

  /*
   * Submit. One write, and no money.
   *
   * ── This used to submit AND pay, and that was wrong ───────────────────────
   *
   * The press called `applications.submit()` and then `payments.pay()` back to
   * back, on the reasoning that a separate Pay screen was a wasted step ("it
   * should be here the payment already"). Collapsing the walk was right; what
   * it collapsed was not, because it assumed submission bills the applicant.
   *
   * It no longer does. The verified counter procedure puts BPLO's reading of
   * the main form BEFORE the money: submit → For Approval → BPLO approves →
   * Pending Payment → pay. The client stated it twice, the second time plainly
   * — "after submission, the business owner will wait for the approval of BPLO
   * then the payment will go AFTER".
   *
   * The old code did not merely describe the wrong order, it performed it. The
   * `pay()` call landed at For Approval, where `PaymentController` had no
   * refusal for a filing that had not been billed yet, so the charge went
   * through; `WorkflowService::onPaymentCompleted` then returned early because
   * the status was not PendingPayment, leaving the money taken and the filing
   * unmoved. The API now refuses that outright (`ApplicationStatus::isBillable`),
   * so this is the honest half of a fix that has a guard behind it — the guard
   * is the part that matters, and it must not be relaxed to let this back in.
   *
   * Payment is `PayPage`'s again, reached from the filing once BPLO approves.
   */
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
                /*
                 * A1 restores from `has_amendments` for an amendment or a
                 * renewal saved since the question existed — and stays null for
                 * a renewal draft written before it, so reopening one asks
                 * rather than assuming. `has_amendments` false on such a draft
                 * means "the column defaulted", not "the applicant said no",
                 * and the two must not be confused.
                 */
                hasChanges:
                  app.application_type === 'renewal' && !app.amendments.has_amendments
                    ? null
                    : app.amendments.has_amendments,
                ownership: app.amendments.ownership,
                location: app.amendments.location,
                nature: app.amendments.nature,
                other: app.amendments.other ?? '',
                fromRegistrationType: app.amendments.from_registration_type ?? '',
                toRegistrationType: app.amendments.to_registration_type ?? '',
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
          mobile_number: b.address?.mobile_number || account?.mobile_number || '',
          email: b.address?.email || account?.email || '',
          owner_surname: b.owner?.surname || account?.last_name || '',
          owner_given_name: b.owner?.given_name || account?.first_name || '',
          owner_middle_name: b.owner?.middle_name || account?.middle_name || '',
          owner_suffix: b.owner?.suffix || account?.suffix || '',
          owner_gender: b.owner?.gender || account?.gender || '',
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
          capital_investment: formatAmountInput(String(b.capital_investment ?? '')),
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
        /*
         * A draft saved before Capital Investment was one field.
         *
         * Its capital sits per line on the fee profile, and nothing writes that
         * any more — so without this the figure would silently read as blank and
         * the applicant would be asked for it again, having already given it
         * (once per line). Summing is exactly right: the paper's B7 is the total,
         * and the lines are how the total used to be broken up.
         *
         * Only when the business record has no figure of its own, so a real
         * answer is never overwritten by a reconstruction of an old one.
         */
        if (!(app.business?.capital_investment ?? '')) {
          const perLine = (app.fee_profile?.lines ?? []).reduce(
            (sum, l) => sum + Number(l.capitalization ?? 0),
            0,
          )
          if (perLine > 0) {
            setForm((f) => ({ ...f, capital_investment: formatAmountInput(String(perLine)) }))
          }
        }
        /*
         * Put the Data Privacy tick back.
         *
         * The one line that fixes what the client reported: "why is data privacy
         * always asked whenever I reopen the draft?" Because `consent` was
         * `useState(false)` and nothing else — never sent, never stored, never
         * restored — so every reopen started it blank and asked again. Being
         * re-asked something you have already answered is what teaches people to
         * tick consent without reading it.
         *
         * `?? false` because the field is optional on the type: a payload built
         * before the API sent it back reads as not-yet-consented, which is the
         * safe direction to be wrong in.
         */
        setConsent(app.data_privacy_consent ?? false)
        setFeeDraft(feeProfileToDraft(app.fee_profile, lineIds))
        // Restore uploaded documents by document-type code.
        const codeToId = new Map<string, number>()
        for (const dt of refData.documentTypes) codeToId.set(dt.code, dt.id)
        // Grouped into a list per requirement, not assigned. Assigning kept the
        // LAST document of each type and dropped the rest, so reopening a draft
        // with two pages of a lease showed one — and removing it would have left
        // the other orphaned on the record, visible to the officer and to nobody
        // else.
        const restored: Record<number, UploadedFile[]> = {}
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
            if (dtId != null) (restored[dtId] ??= []).push(file)
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
        /*
         * A reopened draft has been everywhere, so every step is jumpable.
         * `amendments` is listed alongside BASE_PHASES rather than added to it:
         * the constant is the running order for a NEW filing, and a renewal's
         * order is computed. Naming an extra key here is harmless for the types
         * that never show it — `visited` is a list of keys, not a sequence.
         */
        setVisited([...BASE_PHASES, 'amendments'])
        /*
         * Item 50: which permit this renewal is for, chosen when the draft was
         * started. The list is loaded too (item 85) so Business Information can
         * name the permit rather than print its id, and so the entry dialog has
         * something to offer if it has to reopen.
         *
         * ITEM 110 — and this is where "do not ask a reopened draft again"
         * lives. A draft that already carries a prior_permit_id has answered
         * the question; putting the dialog in front of it would be the wizard
         * forgetting, every single time it was reopened, a decision the
         * applicant made once. The dialog comes back for exactly one case: the
         * question is UNANSWERED — no permit named and no declaration that
         * there is none to name. It used to also let a draft past when the
         * business's permit list happened to be empty, and that waiver is
         * gone: an empty list is why the question is asked, not a reason to
         * skip it. Six of the seven renewals of nothing are drafts that
         * reopened clean under the old rule.
         *
         * Awaited rather than fired and forgotten so the decision is made
         * before `hydrating` clears and the wizard paints; otherwise the
         * dialog would flash in over an already-drawn form. Both reads swallow
         * their own failures, so neither can trip `hydrateFailed`.
         */
        if (app.application_type !== 'new') {
          const [, prior] = await Promise.all([
            loadRenewablePermits(b.id, app.application_type),
            applications
              .priorPermit(app.id)
              .catch(() => null),
          ])
          if (!active) return
          setPriorPermitId(prior?.prior_permit_id ?? null)
          /*
           * The whole ticked set, falling back to the primary alone for a
           * draft saved before the dialog was multi-select. Without the
           * fallback such a draft would reopen with nothing ticked and the
           * next autosave would sync an empty pivot over a real answer.
           */
          setPriorPermitIds(
            prior?.prior_permit_ids?.length
              ? prior.prior_permit_ids
              : prior?.prior_permit_id
                ? [prior.prior_permit_id]
                : [],
          )
          setPriorPermitDeclaredNone(prior?.declared_none ?? false)
          const amendmentAnswered =
            app.application_type !== 'amendment' ||
            Boolean(
              app.amendments &&
                (app.amendments.ownership ||
                  app.amendments.location ||
                  app.amendments.nature ||
                  (app.amendments.other ?? '').trim() !== ''),
            )
          const permitAnswered = Boolean(prior?.prior_permit_id) || Boolean(prior?.declared_none)
          if (!permitAnswered || !amendmentAnswered) setIdentify('entry')
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
    /*
     * `account` is read for the item 11 / A7 / A8 fallbacks and listed whole
     * rather than field by field. The effect is one-shot behind `hydratedRef`,
     * so a new object identity cannot re-run it; naming seven properties would
     * be seven chances to forget one when the fallback list changes.
     */
  }, [draftIdParam, refs.data, navigate, account])

  /*
   * ── Two fetches that used to live here, and why neither does ──────────────
   *
   * The wizard read the clearance rows as soon as a draft existed, because two
   * things anywhere in it depended on them: whether the LGU Clearances step was
   * finished, and which office sheets were steps. It also read the saved
   * office-form payloads, re-fetching whenever a sheet was opened because parts
   * of those sheets are derived server-side from the permits on the filing.
   *
   * Nothing in this wizard depends on either any more. Both belong to
   * <ClearanceStage>, which does its own reads on mount — and does them at the
   * right moment, which the wizard never could: the stage does not open until
   * the first payment has cleared, so by the time a clearance row matters there
   * is unambiguously an application for it to be about.
   */

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

  /*
   * The one screen after Submit, and the last one the wizard owns.
   *
   * It carries the tracking ID — the only thing here the applicant cannot get
   * back any other way — and says what they are now waiting for.
   *
   * ── What it stopped promising ─────────────────────────────────────────────
   *
   * It used to say "Submitted and paid", print a receipt, and offer "Apply for
   * LGU Clearances" as the FIRST action, because this screen was where the
   * clearances opened. It is not any more: the clearances open on payment, and
   * payment now waits on BPLO. Leaving that button here would have sent every
   * applicant straight into a stage whose gate (`ClearanceService::isUnlocked`,
   * `status->isPaid()`) refuses them — a primary action that always fails.
   *
   * The receipt block and the payment-failure alert went with it. Both existed
   * because the press took money; nothing is charged here now, so a receipt
   * would have nothing to report and a payment error nothing to report about.
   */
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
        {/*
          * What they are waiting for, and that there is nothing to do while
          * they wait. "No action needed from you right now" is worth the line:
          * a filing that sits still with no explanation is the state testers
          * report as broken.
          */}
        <p className="mt-6 text-sm text-ink-secondary">
          BPLO is now reviewing your form. No action needed from you right now — we will tell you
          when your Tax Order of Payment is ready, and your five LGU clearances open once it is
          paid.
        </p>
        <div className="mt-4 flex flex-wrap justify-center gap-3">
          <PillButton onClick={() => navigate(`/applications/${applicationId}`)}>
            Track this application
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
        {sequence.map((p, i) => {
          // The phase IS the key: the running order is fixed now, so there is
          // no longer a synthetic `office:CODE` identity to construct.
          const key = p
          const label = BASE_LABELS[p]
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

      {/*
        ── No LGU Clearances step, and no per-office form sheets (p040-043) ──

        Both were rendered here. The six cards were the wizard's step 6 and each
        applied-for clearance's office sheet followed as a step of its own.

        They are one screen now, and it is not this one: <ClearanceStage> on
        /applications/:id/clearances, which mounts its own <OfficeFormSheet>
        over the cards when Apply is pressed. That screen is locked until the
        first payment clears, which is the whole point of the reordering — the
        applicant pays for the business permit, and only then are the clearances
        offered, each adding its fee to a balance that must reach zero before
        the permit is released (docs/clearances-after-payment.md).
      */}

      {/* ── Business information (form sheet, p32) ─────────────────────── */}
      {phase === 'business' && (
        <FormSheet meta={typeMeta}>
          <SectionMarker letter="A" label="Business Information & Registration" />
          {/*
            * ── ITEM 110 — what this block used to be ────────────────────────
            *
            * The business select, the "which permit are you renewing?"
            * radiogroup and the amendment checkboxes were all HERE, as live
            * controls on part 3 of the wizard. The client's item is that a
            * renewal must ask for the permit FIRST, in a modal, so the system
            * knows which permit it is renewing before it renews anything —
            * and they were right that asking here was too late: the wizard had
            * already prefilled itself from a business whose permit it had not
            * been told, and `canCreateDraft` refused to save a word of it
            * until the applicant scrolled back up to a question they had been
            * walked past.
            *
            * So the questions moved into <IdentifyFilingModal>, which opens
            * over the wizard before part 1. What is left here is the ANSWER,
            * stated plainly, with the way back to change it — this is the only
            * route to reopen the dialog, so it must not be removed with it.
            */}
          {isReuse && (
            <div className="mt-4 rounded-lg border border-royal/30 bg-royal-tint px-4 py-4">
              <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                  <p className="text-[13px] font-semibold text-ink">
                    {applicationType === 'renewal' ? 'Renewing' : 'Amending'}
                  </p>
                  <p className="mt-0.5 truncate text-sm font-bold text-ink">
                    {reuseBusinessName ?? 'No business chosen yet'}
                  </p>
                </div>
                {/*
                  * The way back. An applicant who picked the wrong permit —
                  * two Mayor's Permits a year apart look alike in a hurry —
                  * would otherwise have to abandon the draft and start again.
                  * Reopening in `change` mode means Cancel puts back what they
                  * had, so pressing this to LOOK is free.
                  */}
                <PillButton
                  className="shrink-0 border-2 border-royal bg-white !text-royal hover:bg-royal-tint"
                  onClick={() => {
                    setIdentifyError(null)
                    setIdentify('change')
                  }}
                >
                  Change
                </PillButton>
              </div>

              <dl className="mt-3 space-y-1 text-xs">
                <div className="flex gap-2">
                  <dt className="shrink-0 font-semibold text-ink-secondary">Permit</dt>
                  <dd className="min-w-0 text-ink">
                    {loadingPermits ? (
                      'Loading this business’s permits…'
                    ) : priorPermitChoice ? (
                      <>
                        <span className="tnum font-semibold">{priorPermitChoice.permit_number}</span>
                        {' · '}
                        {priorPermitChoice.permit_type?.name ?? 'Permit'}
                        {' · '}
                        {permitValidity(priorPermitChoice)}
                      </>
                    ) : priorPermitDeclaredNone ? (
                      /*
                       * THE ESCAPE, restated where the answer lives. A renewal
                       * of a permit issued on paper is the ordinary case in
                       * year one, and this is what it looks like once the
                       * dialog has been answered: no permit named, and the
                       * instruction that replaces it.
                       */
                      'No BizTrack permit — upload your paper permit under Documentary Requirements.'
                    ) : (
                      /*
                       * And this is the OTHER null, which this line could not
                       * previously tell apart and so described as the escape:
                       * the question simply not answered yet. Printing the
                       * escape's reassuring sentence over an open question is
                       * how a draft could sit here looking complete and still
                       * be a renewal of nothing.
                       */
                      'Not chosen yet — press Change.'
                    )}
                  </dd>
                </div>
                {applicationType === 'amendment' && (
                  <div className="flex gap-2">
                    <dt className="shrink-0 font-semibold text-ink-secondary">Amending</dt>
                    <dd className="min-w-0 text-ink">
                      {amendmentSummary ?? 'Nothing chosen yet — press Change.'}
                    </dd>
                  </div>
                )}
              </dl>

              {prefilling && <p className="mt-2 text-xs text-ink-secondary">Prefilling…</p>}
              {/*
                * The prefill note names `last_permit`, which is the NEWEST
                * issued permit and is rarely the one about to lapse — so once a
                * permit has been named above, printing it here put two
                * different permit numbers two lines apart and left the
                * applicant to work out which one this filing was against. That
                * is the exact confusion item 110 exists to remove, so where
                * there is an answer the note stops competing with it and says
                * only what it is actually for: the form below was filled in for
                * you. With no permit named there is no competition, and the
                * original sentence still earns its place.
                */}
              {prefillNote && (
                <p className="mt-2 text-xs font-medium text-royal">
                  {priorPermitChoice ? 'Your registered details are filled in below.' : prefillNote}
                </p>
              )}
              {/*
                Where the clearance renewal actually happens, which is not here.

                This line used to read "Its clearance is ticked for you in the
                LGU Section; add any others there." Both halves are false now:
                nothing is ticked (adding a SANITARY permit type at this point
                would put the City Health Office's fees on the business permit's
                own Tax Order of Payment, which is precisely the accrual this
                restructure separates — see confirmIdentity), and there is no
                LGU Section in this wizard to add anything to.

                It still earns its place, because a renewer's first question is
                "what about my sanitary permit?" and the honest answer is
                "shortly, and not yet". Saying WHEN is what stops them hunting
                for a step that no longer exists.
              */}
              {priorPermitChoice && (
                <p className="mt-2 text-xs text-ink-secondary">
                  Renewing its clearances comes after this — once you submit, the LGU clearances
                  open and you apply for the ones you need.
                </p>
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
              ── Items A7 and A8 — the business's mobile number and e-mail ────

              Both were absent from this form and present on the paper. The
              officer's sheet filled the gap with the ACCOUNT holder's details,
              which is a different fact and wrong the moment a staff member files
              for a corporation.

              Prefilled from the signed-in account, because for a sole proprietor
              they are the same and retyping a number you gave at sign-up is not
              a question worth asking. Editable, and stored on the business.
            */}
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block">
                  <FieldLabel>Mobile Number</FieldLabel>
                  <input
                    inputMode="tel"
                    value={form.mobile_number}
                    onChange={(e) => update('mobile_number', e.target.value)}
                    onBlur={() => touch('mobile_number')}
                    placeholder="09XX XXX XXXX"
                    className={inputCls}
                  />
                </label>
                <p className="mt-1 text-xs text-ink-secondary">
                  The number the city should ring about this business.
                </p>
              </div>
              <div>
                <label className="block">
                  <FieldLabel>E-mail Address</FieldLabel>
                  <input
                    inputMode="email"
                    value={form.email}
                    onChange={(e) => update('email', e.target.value)}
                    onBlur={() => touch('email')}
                    placeholder="business@example.com"
                    className={inputCls}
                  />
                </label>
              </div>
            </div>

            {/*
              ── Items 11 / 12 — the named person on the form ─────────────────

              Surname, given name, middle name, SUFFIX and GENDER. All five have
              had columns on `business_owners` since the schema was aligned with
              the manuscript, and until now only the seeders wrote them — so a
              paper that asks for a suffix and a gender had nowhere to put
              either.

              Item 11 is the sole proprietor; item 12 is the corporation's,
              partnership's or cooperative's named officers, and the paper prints
              TWO rows for it. One is written here (the primary); the relation is
              plural on both sides so the second needs no migration when it is
              asked for.
            */}
            <div>
              <p className="text-sm font-bold text-ink">
                {form.registration_type === 'sole_proprietorship'
                  ? 'Sole Proprietor'
                  : 'Name on the Registration'}
              </p>
              <p className="mb-2 text-xs text-ink-secondary">
                Filled in from your account. Change it if the business is registered in another
                name.
              </p>
              <div className="grid gap-4 sm:grid-cols-2">
                <label className="block">
                  <FieldLabel>Surname</FieldLabel>
                  <input
                    value={form.owner_surname}
                    onChange={(e) => update('owner_surname', e.target.value)}
                    className={inputCls}
                  />
                </label>
                <label className="block">
                  <FieldLabel>Given Name</FieldLabel>
                  <input
                    value={form.owner_given_name}
                    onChange={(e) => update('owner_given_name', e.target.value)}
                    className={inputCls}
                  />
                </label>
                <label className="block">
                  <FieldLabel>Middle Name</FieldLabel>
                  <input
                    value={form.owner_middle_name}
                    onChange={(e) => update('owner_middle_name', e.target.value)}
                    className={inputCls}
                  />
                </label>
                <label className="block">
                  <FieldLabel>Suffix</FieldLabel>
                  <input
                    value={form.owner_suffix}
                    onChange={(e) => update('owner_suffix', e.target.value)}
                    placeholder="Jr., III"
                    className={inputCls}
                  />
                </label>
              </div>
              <div className="mt-4">
                <FieldLabel>Gender</FieldLabel>
                {/*
                  Two options, as the paper's M / F boxes print. A radiogroup
                  rather than toggles, so a screen reader announces that picking
                  one unpicks the other — the same treatment Type of Registration
                  and Economic Organization get above.
                */}
                <div role="radiogroup" aria-label="Gender" className="flex flex-wrap gap-2">
                  {[
                    { value: 'M', label: 'Male' },
                    { value: 'F', label: 'Female' },
                  ].map((opt) => {
                    const selected = form.owner_gender === opt.value
                    return (
                      <button
                        key={opt.value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => update('owner_gender', selected ? '' : opt.value)}
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
                      {/*
                        The paper's own wording, item 14: "Citizenship (of
                        President/OIC)". It was a label plus an explanatory line
                        underneath, which said the same thing in more words and
                        in a place the eye reaches after the input. On the label
                        it is read before the field it qualifies.
                      */}
                      <FieldLabel>Citizenship (of President/OIC)</FieldLabel>
                      <input
                        value={form.citizenship}
                        onChange={(e) => update('citizenship', e.target.value)}
                        maxLength={100}
                        className={inputCls}
                      />
                      </label>
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
          </div>
        </FormSheet>
      )}

      {/*
        ── B. Business Operation (paper section B) ─────────────────────────

        Its own step, between Business Information & Registration and
        Documentary Requirements, because the client asked for the wizard to
        number the sections the way the paper does: "Section 3 to be Business
        Information & Registration, Section 4 to be Business Operation, Section
        5 to be Documentary Requirements."

        It was a heading part-way down step 3 first, and that was not enough.
        The section map along the top is how an applicant navigates and how they
        check what is left, and it names STEPS — so a heading inside one meant
        Section B did not appear in the only place somebody looks for it.

        ── What is here, and what is not ───────────────────────────────────

        Items B6 (Economic Organization) and B8 (Tax Incentives) — the two the
        codebase records paper item numbers for, so the two I can place without
        guessing.

        The paper's other B items are still elsewhere: the line-of-business
        table, the business location address, the lessor block and the emergency
        contact on step 2 ("Location & Zoning"), and employees and floor area on
        the fee profile step, where they double as inputs to the fee engine.
        Moving those is a separate decision — the line-of-business table in
        particular has a real reason to stay where it is, because the zoning
        conformity check on that step is a judgment about a NAMED TRADE and
        needs the trade beside it. Recorded rather than done.
      */}
      {phase === 'operation' && (
        <FormSheet meta={typeMeta}>
          <SectionMarker letter="B" label="Business Operation" />
          <p className="mt-3 text-sm text-ink-secondary">
            How this business operates. Both answers are optional — leave either blank if it does
            not apply to you.
          </p>
          <div className="mt-4 space-y-4">

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

          {/*
            Item B7 — Capital Investment (Php).
            ────────────────────────────────────────────────────────────────
            ONE figure for the whole business, which is what the paper asks for.
            It is deliberately not the fee profile's per-line `capitalization`:
            the Revenue Code prices each line of business separately, so the
            engine needs a breakdown the paper never collects. Both are kept,
            and `businesses.capital_investment` — a column that existed and was
            written by nothing — is finally where this one lands.
          */}
          <div className="mt-6 max-w-sm">
            <label className="block">
              <FieldLabel required={applicationType === 'new'}>Capital Investment (₱)</FieldLabel>
              <input
                inputMode="decimal"
                value={form.capital_investment}
                onChange={(e) => update('capital_investment', formatAmountInput(e.target.value))}
                onBlur={() => touch('capital_investment')}
                placeholder="e.g. 250,000.00"
                className={inputCls}
              />
            </label>
            <p className="mt-1 text-xs text-ink-secondary">
              {applicationType === 'new'
                ? 'The total capital you are putting into this business.'
                : 'The total capital in this business. A renewal is assessed on last year’s gross sales, so this is optional.'}
            </p>
          </div>

          {/*
            Section B items 1-4: business area, employees and their split, how
            many live in the LGU, and the delivery units. They were on the fee
            step because they price the permit; the paper asks them here, and
            the client's rule is that the wizard follows the paper.

            The same component the fee step mounts, scoped. Both write one
            `FeeProfileDraft`, so nothing about the calculation changed — only
            where the questions are put.
          */}
          <div className="mt-6">
            <FeeProfileStep
              scope="operation"
              applicationType={applicationType}
              registrationType={form.registration_type}
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

      {/* ── Zoning clearance — Selecting Business Location (p27) ───────── */}
      {/*
       * This step is location CAPTURE for the zoning / locational clearance,
       * not a zoning decision: the system has no city zone polygons, so
       * conformance is evaluated by the Zoning Office (CPDO) during
       * processing. The copy here says "zoning clearance", never "Mayor's
       * permit" (user-testing feedback).
       *
       * The two things it does decide: a pin outside Malabon is refused
       * outright, because no amount of CPDO review makes a business in another
       * city licensable here; and a pin that contradicts the barangay chosen
       * from the dropdown is refused, because one of the two is then wrong and
       * neither the applicant nor CPDO gains from storing both. Those are
       * geometry checks against the city and barangay polygons — see
       * `lib/malabonGeo.ts` — and nothing more. They say where the premises
       * are, never whether the trade is allowed there.
       */}
      {phase === 'address' && (
        <div>
          <h1 className="mb-1 text-2xl font-bold text-ink">Zoning Clearance - Selecting Business Location</h1>
          <div className="mb-2 h-px bg-ink/40" />
          <p className="mb-6 text-xs text-ink-secondary">
            Pin your location and enter your address. The pin must fall inside Malabon, and inside
            the barangay you select below. CPDO evaluates your zoning clearance from it during
            processing.
          </p>

          {/*
            * Says where the other five clearances went, on the step where they
            * are missed.
            *
            * A tester reported them "missing" and asked for them back. They
            * were not deleted — they moved out of this wizard and onto
            * /applications/:id/clearances when payment went first, which Review
            * & Submit does explain. But Review is the LAST step, and this is
            * the step whose heading says "Zoning Clearance", so this is where
            * somebody looking for the clearances looks and concludes they are
            * gone. Answering only at the end answers after the alarm.
            *
            * The six are named rather than counted, because "six LGU
            * clearances" does not let an applicant check whether the one THEY
            * need is among them. Not a link: there is no application to link to
            * until this filing is submitted.
            */}
          <div className="mb-6 rounded-xl border border-line-strong bg-white px-4 py-3">
            <p className="text-xs text-ink-secondary">
              <span className="font-semibold text-ink">The other permits come later.</span> Fire,
              Sanitary, Building/Occupancy, Environmental and this Zoning clearance are not part of
              this form. Once BPLO approves your application and you have paid, all five open under{' '}
              <span className="font-semibold text-ink">Other Permits</span> — you need every one of
              them, and for each you either fill in that office’s sheet or hand in the permit you
              already hold.
            </p>
          </div>

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
            * column beside it — the chosen trade needs its title, its PSIC
            * code and the Change / Clear controls on one row, and the picker's
            * own search results are the widest thing on the step.
            */}
          <div className="mb-7 rounded-2xl bg-white px-5 py-5 shadow-card sm:px-6">
            {/*
              * Singular, and the helper text says the quantity out loud.
              *
              * It read "Add every line you trade in — each one is assessed
              * separately", which was true of the multi-select and survived it
              * by months. The client sent a screenshot: the step still "kinda
              * say[s] hey you should be able to select more". Copy that
              * contradicts the control is worse than no copy — the applicant
              * believes the sentence and blames themselves for the control.
              *
              * The heading loses its plural too: "Line of Business", not
              * "Lines". Keep both singular if this is ever reworded.
              */}
            <FieldLabel required>Line of Business</FieldLabel>
            <p className="mb-3 text-xs text-ink-secondary">
              What this location will be used for. Choose one trade — the zoning verdict is given
              against a single line of business, so a filing declares one.
            </p>
            <LinesStep codes={psic} lines={form.lines} onChange={(lines) => update('lines', lines)} />
            {form.lines.length === 0 && (
              <p className="mt-2.5 text-xs font-medium text-s-red">
                {/* "at least one" was the multi-select's phrasing and implied a
                    minimum with no maximum. There is exactly one. */}
                Required: choose your line of business. The zoning verdict is about a trade, not a
                coordinate.
              </p>
            )}
          </div>

          <div className="grid gap-6 lg:grid-cols-[1.15fr_1fr]">
            {/*
              * The map column: the picker, then the figures for whatever it is
              * pointing at. One column, because they are one question — "is this
              * the right spot?" — and reading the numbers means glancing back up
              * at the pin they describe. Split across the grid they would be a
              * map and an unrelated table.
              *
              * self-start lives on this wrapper, because a grid item stretches to
              * its row by default and this column has nothing to stretch with.
              * Its height is the map (320px) plus a few short captions, while the
              * address column beside it is 421px, or 719px once "Rented" opens the
              * lessor block. Stretching handed the column the difference as height
              * it had no content for, and because the wrapper is transparent — the
              * white comes from the cards inside it — the page showed through
              * inside a rounded, shadowed frame. That empty framed panel is what
              * the client saw under the map. Sizing to content deletes the frame
              * rather than filling it; growing the map to match instead would make
              * it lurch 341px taller the moment somebody ticks "Rented".
              *
              * The insights card below the map does NOT close that gap on
              * purpose. It is only there once a pin exists, so relying on it for
              * height would bring the empty frame straight back on a fresh
              * filing, which is exactly the state the client was looking at.
              */}
            <div className="self-start space-y-6">
            <div className="overflow-hidden rounded-2xl shadow-card [&>div]:!rounded-none [&>div]:!border-0">
              <MapPicker
                latitude={form.latitude}
                longitude={form.longitude}
                /*
                 * The ring, at the radius the API says it measured over. Null
                 * until the first response, so nothing is drawn on a guess —
                 * and see `insightsRadiusM` for why it is deliberately kept
                 * while the next lookup is in flight.
                 */
                radiusM={insightsRadiusM}
                highlightBarangay={barangayName ?? null}
                /*
                 * The map does not open for business until a trade is chosen.
                 *
                 * The zoning verdict is given against a line of business, not a
                 * coordinate — the sentence directly above the picker says so —
                 * so a pin dropped first is an answer to half a question. It
                 * also lets Location Insights compare like with like from the
                 * very first lookup, instead of counting every business near the
                 * pin and then silently changing its mind once a PSIC group
                 * exists.
                 */
                lockedReason={
                  form.lines.length === 0
                    ? 'Choose your line of business above, then click the map to drop a pin.'
                    : null
                }
                onPick={(lat, lng) => {
                  /*
                   * Item 86 — a pin outside the city is refused rather than
                   * stored and argued with later. The wording names exactly what
                   * was checked and no more: this cannot tell land from water,
                   * so it never says it did.
                   *
                   * The barangay mismatch is refused here too, and phrased to
                   * leave both ways out open — the pin may be wrong, or the
                   * dropdown may be. Naming the barangay the pin actually fell
                   * in is what makes the message actionable; "that is the wrong
                   * barangay" alone would send someone hunting. See
                   * BARANGAY_TOLERANCE_M for why a near-miss is accepted
                   * silently rather than argued with.
                   */
                  const verdict = checkPin(lat, lng, barangayName ?? null)
                  if (verdict.kind === 'outside-city') {
                    setPinError(
                      `That point (${lat}, ${lng}) is outside Malabon, so we can’t use it. Zoom in on your street within the city and click there.`,
                    )
                    return
                  }
                  if (verdict.kind === 'wrong-barangay') {
                    setPinError(
                      verdict.actual !== null
                        ? `That pin is in ${verdict.actual}, but you selected ${barangayName}. Move the pin into ${barangayName} — the highlighted area — or change your barangay above.`
                        : `That pin is about ${verdict.metres} m outside ${barangayName}. Move it into the highlighted area, or change your barangay above.`,
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
                  {/*
                    * Says in words what the ring on the map means.
                    *
                    * DESIGN.md's Never Color Alone rule: the circle carries
                    * meaning — "everything counted below is inside here" — and a
                    * blue ring alone carries it in colour and shape only. This
                    * sentence is the same fact in text, so it survives with
                    * colour off, at low vision, and on a screen reader that
                    * cannot see an SVG path at all.
                    *
                    * It appears only alongside the ring, off the same radius, so
                    * the caption can never describe a circle that is not drawn.
                    */}
                  {insightsRadiusM !== null && (
                    <span className="mt-0.5 block text-ink-muted">
                      The circle around it covers {insightsRadiusM} m — the area the figures below
                      count.
                    </span>
                  )}
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
                * A disagreement the applicant did not cause, said out loud.
                *
                * A renewal prefills its pin and its barangay from the business
                * on record, and most records disagree — nothing checked this
                * until now, so only 61 of 788 addresses on file sit inside their
                * own barangay. The step deliberately does NOT block on a
                * prefill (see the gate in `missingFor`), because refusing to let
                * someone renew over a pin they never placed is our history
                * charged to them.
                *
                * But silence is the wrong other extreme: it leaves a known-wrong
                * location to be carried into a zoning clearance. So it is stated
                * and left to them. Deliberately NOT `role="alert"` and not the
                * error red — nothing has gone wrong here and the applicant is
                * not being stopped; #bd0000 is reserved for what actually blocks
                * (DESIGN.md, "Red Means Stop"). It reads as amber-free plain
                * text with a bold lead-in, so it survives greyscale.
                */}
              {form.latitude !== null &&
                form.longitude !== null &&
                barangayName !== undefined &&
                !touched.barangay_id &&
                pinError === null &&
                (() => {
                  const verdict = checkPin(form.latitude, form.longitude, barangayName)
                  if (verdict.kind !== 'wrong-barangay') return null
                  return (
                    <p className="bg-white px-4 pb-2.5 text-xs text-ink-secondary">
                      <span className="font-semibold text-ink">Check this location.</span> The saved
                      pin sits in {verdict.actual ?? 'no barangay we can identify'}, but this
                      application says {barangayName}. Click the map to move the pin, or change the
                      barangay below — whichever is wrong.
                    </p>
                  )
                })()}
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

            {/*
              * The figures for the pin, the moment there is a pin.
              *
              * Gated on `livePin` rather than on the response, so the card
              * appears with the pin and shows its own skeleton while the lookup
              * runs. Gating on `insights.data` instead would leave a beat where
              * the applicant has pinned a spot and nothing acknowledges it, then
              * a block of numbers drops in and pushes the page around.
              *
              * `insightsStale` is folded into `loading` here: while the pin has
              * moved and the answer has not caught up, this is a lookup in
              * progress as far as the reader is concerned, whatever the previous
              * fetch's state says. That is the whole reason the flag exists —
              * see where it is computed.
              */}
            {livePin !== null && (
              <LocationInsightsPanel
                insights={insights.data}
                loading={insights.loading || insightsStale}
                error={insights.error}
              />
            )}
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
                  /*
                   * Marked touched on CHANGE as well as on blur, and the change
                   * is the one that matters.
                   *
                   * Picking from a dropdown is answering the question — there
                   * is no half-typed state to be patient about, which is the
                   * only reason the other fields wait for blur. The pin/barangay
                   * check keys off this flag to tell an answer the applicant
                   * gave from a value we prefilled for them, so relying on blur
                   * alone let someone change the barangay to one their pin
                   * contradicts and walk on, provided they never focused
                   * anything else before pressing Next.
                   */
                  onChange={(e) => {
                    update('barangay_id', e.target.value)
                    touch('barangay_id')
                  }}
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

              {/*
                * CPDO's own sheet for whichever barangay was just picked.
                *
                * Directly under the picker, not beside the map: it answers the
                * question the applicant has at the moment they choose ("what is
                * my barangay zoned for?"), and it changes when the answer to
                * that question changes. Gated on a selection because there is no
                * sensible default sheet — twenty-one maps and no barangay chosen
                * is a gallery, not an answer.
                *
                * It shows and lists. It does not decide — see the component.
                */}
              {selectedBarangay !== null && <BarangayZoningMap barangay={selectedBarangay} />}

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
                const files = uploaded[dt.id] ?? []
                const busy = uploadingType === dt.id
                return (
                  <div key={dt.id}>
                    {/*
                      The dashed box is the ADD control, and stays one row tall
                      however many files a requirement holds. The files are
                      listed under it, which is the same shape "Other
                      Requirements" has always used — that section was the only
                      repeatable one, and copying its pattern means an applicant
                      does not meet two different ways of attaching a file on one
                      screen. That section is otherwise untouched [client,
                      6 September 2026] — it takes one file per press as it always
                      has, and only the numbered requirements above changed.
                    */}
                    <label
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
                          {busy
                            ? 'Uploading…'
                            : files.length > 0
                              ? /*
                                 * It said "click to replace", which was true and
                                 * is the behaviour that was wrong: the press
                                 * destroyed the file already there. It adds now,
                                 * and says so — the count is what tells an
                                 * applicant the earlier pages are still attached.
                                 */
                                `${files.length} file${files.length === 1 ? '' : 's'} attached · click to add another`
                              : dt.help_text || 'file type: png, jpg, pdf only'}
                        </span>
                      </span>
                      {files.length > 0 && (
                        <span className="inline-flex shrink-0 items-center gap-1.5 text-sm font-semibold text-s-green">
                          <CheckIcon size={16} /> Uploaded
                        </span>
                      )}
                      <input
                        type="file"
                        accept={ACCEPT_ATTR}
                        multiple
                        className="sr-only"
                        disabled={busy}
                        onChange={(e) => {
                          const chosen = Array.from(e.target.files ?? [])
                          if (chosen.length > 0) void handleUpload(dt.id, chosen)
                          e.target.value = ''
                        }}
                      />
                    </label>

                    {files.length > 0 && (
                      <ul className="mt-2 space-y-2 pl-4">
                        {files.map((f) => {
                          const removing = removingDoc === f.id
                          return (
                            <li
                              key={f.id}
                              className={`flex items-center gap-3 rounded-lg border border-input-border bg-input/50 px-4 py-2.5 ${
                                removing ? 'opacity-60' : ''
                              }`}
                            >
                              <span className="min-w-0 flex-1 truncate text-sm text-ink">{f.name}</span>
                              <span className="tnum shrink-0 text-xs text-ink-muted">
                                {formatBytes(f.size)}
                              </span>
                              {/*
                                Item 96. The only thing an applicant could once
                                do with a file they had sent was replace it or
                                delete it — there was no way to see what had
                                actually arrived. Uploading the wrong scan is the
                                easiest mistake on this screen and it was the one
                                mistake the screen would not let you check for.
                                View opens the STORED copy, not the local File
                                object, so what is shown is what the office reads.

                                The label is the filename rather than the
                                requirement's name: several rows can now share
                                one requirement, so "View Lease Contract" three
                                times over would name three different files
                                identically to a screen reader.
                              */}
                              <DocumentActions id={f.id} filename={f.name} />
                              <button
                                type="button"
                                onClick={() => void handleRemoveDocument(f, dt.id)}
                                disabled={removing}
                                aria-label={`Remove ${f.name} from ${dt.name}`}
                                className="shrink-0 text-sm font-semibold text-s-red underline underline-offset-2 disabled:opacity-60"
                              >
                                {removing ? 'Removing…' : 'Remove'}
                              </button>
                            </li>
                          )
                        })}
                      </ul>
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

      {/*
        ── Section A · MCG-BPLO-FO-002 v2.0 ────────────────────────────────

        "A. BUSINESS INFORMATION AND REGISTRATION", transcribed. Three
        questions in the order the paper prints them:

          A1  Do you have any changes or amendments in the previous business
              registration?                                        Yes / No
          A2  If yes, please check the appropriate box/es —
              Ownership · Location or Address of Business ·
              Nature of Business · Others ______
          A3  Amendment: From [structure] To [structure]

        A1 is asked HERE, immediately after Data Privacy, and not in the dialog
        that opened the filing. The dialog's job is to say which permits are
        being renewed; this is the form's first question, and a No means the
        form is finished — a decision the wizard can only act on by shortening
        itself, which it can only do from inside its own sequence.

        A2 does more work here than it does on paper. On the form it is a
        record of what changed; here it also decides which of the later
        sections the applicant is shown at all, so a renewal that only moved
        premises answers one section instead of six. `sequence` above is where
        that mapping lives.
      */}
      {phase === 'amendments' && (
        <FormSheet meta={typeMeta}>
          <h2 className="text-[13px] font-bold uppercase tracking-[0.12em] text-royal">
            A. Business Information and Registration
          </h2>
          <div className="mb-6 mt-2 h-px bg-royal/30" />

          {/* ── A1 ─────────────────────────────────────────────────────── */}
          <fieldset ref={a1Ref} className="border-0 p-0">
            <legend className="mb-1.5 block text-[13px] font-semibold text-ink">
              1. Do you have any changes or amendments in the previous business registration?
              <span className="text-s-red"> *</span>
            </legend>
            <p className="mb-3 max-w-2xl text-xs leading-relaxed text-ink-secondary">
              Answer No and the details on record carry over to this renewal unchanged.
            </p>
            <div className="flex gap-2">
              {/*
                Two buttons, not one checkbox. The paper prints two boxes, and
                an unticked checkbox cannot tell "No" from "not answered yet" —
                the distinction this whole step turns on, because one of those
                ends the form and the other must not.
              */}
              {[
                { value: true, label: 'Yes' },
                { value: false, label: 'No' },
              ].map((opt) => {
                const chosen = amendment.hasChanges === opt.value
                return (
                  <button
                    key={opt.label}
                    type="button"
                    aria-pressed={chosen}
                    onClick={() =>
                      setAmendment((a) =>
                        opt.value
                          ? { ...a, hasChanges: true }
                          : /*
                             * No clears section A. Leaving the ticks behind
                             * would file a renewal claiming nothing changed
                             * while still naming Location as changed, and the
                             * API would have to pick one of the two to believe.
                             */
                            {
                              ...a,
                              hasChanges: false,
                              ownership: false,
                              location: false,
                              nature: false,
                              other: '',
                              fromRegistrationType: '',
                              toRegistrationType: '',
                            },
                      )
                    }
                    className={
                      chosen
                        ? 'min-w-[6rem] rounded-lg border border-royal bg-royal px-5 py-2.5 text-sm font-semibold text-white'
                        : 'min-w-[6rem] rounded-lg border border-input-border bg-white px-5 py-2.5 text-sm font-semibold text-ink transition-colors hover:bg-royal-tint'
                    }
                  >
                    {opt.label}
                  </button>
                )
              })}
            </div>
          </fieldset>

          {/* ── A1 = No · the form is finished ─────────────────────────── */}
          {amendment.hasChanges === false && (
            <div className="mt-5 max-w-2xl rounded-lg border border-input-border bg-royal-tint px-5 py-4">
              <p className="text-sm font-semibold text-royal">Your renewal is ready to submit.</p>
              <p className="mt-1.5 text-xs leading-relaxed text-ink-secondary">
                Nothing has changed, so there is nothing further to fill in. Every detail carries
                over from the permit you are renewing. Press Next to check it over and file.
              </p>
            </div>
          )}

          {/* ── A2 ─────────────────────────────────────────────────────── */}
          {amendment.hasChanges === true && (
            <fieldset ref={amendmentRef} className="mt-6 border-0 p-0">
              <legend className="mb-1.5 block text-[13px] font-semibold text-ink">
                2. If yes, please check the appropriate box/es
                <span className="text-s-red"> *</span>
              </legend>
              <p className="mb-3 max-w-2xl text-xs leading-relaxed text-ink-secondary">
                Tick only what actually changed. We will ask you to fill in those sections and
                nothing else — the rest carries over from your last permit.
              </p>
              <div className="max-w-2xl space-y-2">
                {AMENDMENT_KINDS.map((kind) => (
                  <label
                    key={kind.key}
                    className="flex cursor-pointer items-start gap-3 rounded-lg border border-input-border bg-white px-4 py-3"
                  >
                    <input
                      type="checkbox"
                      checked={amendment[kind.key]}
                      onChange={(e) =>
                        setAmendment((a) => ({ ...a, [kind.key]: e.target.checked }))
                      }
                      className="mt-0.5 h-4 w-4 shrink-0 accent-royal"
                    />
                    <span className="min-w-0">
                      <span className="block text-sm font-medium text-ink">{kind.label}</span>
                      {/*
                        What ticking it will actually make them fill in. Said on
                        the box rather than discovered two steps later, because
                        the tick is now a choice about the length of the form
                        and not only a record of what changed.
                      */}
                      <span className="block text-xs text-ink-secondary">{kind.opens}</span>
                    </span>
                  </label>
                ))}
                {/*
                  "Others (specify)" is one control, not a checkbox with a box
                  beside it: on the paper you cannot tick Others without writing
                  the other in, so typing IS ticking and a separate tick could
                  only ever contradict the text.
                */}
                <label className="block rounded-lg border border-input-border bg-white px-4 py-3">
                  <span className="mb-1.5 block text-sm font-medium text-ink">Others (specify)</span>
                  <input
                    value={amendment.other}
                    onChange={(e) => setAmendment((a) => ({ ...a, other: e.target.value }))}
                    placeholder="what else changed"
                    maxLength={255}
                    className={inputCls}
                  />
                  <span className="mt-1.5 block text-xs text-ink-secondary">
                    Opens Business Information so you can edit the details.
                  </span>
                </label>
              </div>
            </fieldset>
          )}

          {/* ── A3 ─────────────────────────────────────────────────────── */}
          {amendment.hasChanges === true && amendment.ownership && (
            <fieldset className="mt-6 border-0 p-0">
              <legend className="mb-1.5 block text-[13px] font-semibold text-ink">
                3. Amendment
              </legend>
              <p className="mb-3 max-w-2xl text-xs leading-relaxed text-ink-secondary">
                Only if the business converted from one legal structure to another. Leave both as
                they are if ownership changed hands without the structure changing.
              </p>
              <div className="grid max-w-2xl gap-4 sm:grid-cols-2">
                {[
                  { key: 'fromRegistrationType' as const, label: 'From' },
                  { key: 'toRegistrationType' as const, label: 'To' },
                ].map((side) => (
                  <label key={side.key} className="block">
                    <FieldLabel>{side.label}</FieldLabel>
                    <select
                      className={inputCls}
                      value={amendment[side.key]}
                      onChange={(e) => setAmendment((a) => ({ ...a, [side.key]: e.target.value }))}
                    >
                      <option value="">Not changing</option>
                      {REGISTRATION_TYPES.map((rt) => (
                        <option key={rt.value} value={rt.value}>
                          {rt.label}
                        </option>
                      ))}
                    </select>
                  </label>
                ))}
              </div>
              {/*
                A conversion to the structure it already is is not a conversion.
                Said rather than blocked: it is a slip worth naming, not a
                filing worth refusing.
              */}
              {amendment.fromRegistrationType !== '' &&
                amendment.fromRegistrationType === amendment.toRegistrationType && (
                  <p className="mt-2 max-w-2xl text-xs font-medium text-ink">
                    From and To are the same structure — check which one changed.
                  </p>
                )}
            </fieldset>
          )}
        </FormSheet>
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
              What happens next, said here rather than discovered later.

              The whole "Pay with" fieldset stood below this paragraph and is
              gone, along with the sentence that promised the press would settle
              a bill. Submission does not bill anybody: BPLO reads the form
              first, and the Tax Order of Payment is raised only if they accept
              it (docs/application-flow-2026-09.md).

              Three stages named, in the order they happen, because each is a
              wait the applicant would otherwise experience as nothing
              happening. The last clause is the one that must not be dropped in
              a future trim — an applicant who thinks approval is the end, or
              that payment is the end, is the surprise this screen exists to
              prevent.
            */}
            <p className="max-w-md text-sm text-ink-muted">
              BPLO reviews this form first. If they accept it, we raise your Tax Order of Payment
              and you pay — and once that is settled, your five LGU clearances open. Your Business
              Permit is released after all of them are approved.
            </p>
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
              * Next or Submit, and nothing else. The third branch here read
              * "Save & back to clearances" and belonged to an office sheet
              * reached from a clearance card — a round trip that only existed
              * while the sheets were steps of this wizard. <ClearanceStage>
              * owns that button now, on its own screen.
              */}
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
                {/*
                  * "Submit", because that is now all it does. It read "Submit &
                  * Pay" while the press also charged the applicant; the charge
                  * has moved behind BPLO's approval, so the label goes back.
                  */}
                {saving ? 'Submitting…' : 'Submit'}
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
            {/*
              * This CPDO line is not decoration and must not be trimmed. It is
              * the only thing on this dialog that stops "CONGRATULATIONS!" from
              * reading as an issued clearance — and it is now also the standing
              * condition under which LocationInsightsPanel's removed disclaimer
              * would have to come back. See the comment at the foot of that
              * file before touching either.
              */}
            <p className="mt-2 text-xs leading-relaxed text-ink-secondary">
              The Zoning Office (CPDO) makes the final determination on your zoning clearance
              during processing.
            </p>

            {/*
              * Business Location Insights used to render here, and does not any
              * more (client instruction). Behind this modal the figures arrived
              * after the location was chosen, which is the wrong order for
              * decision support — they are on the map step now, visible from the
              * moment a pin is dropped and while it can still be moved.
              */}
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
          {/*
            * Back to naming one action, because the press takes one. It named a
            * payment method while it also charged; a confirmation that
            * over-describes what it confirms is as misleading as one that
            * under-describes it, and this one would have promised a debit that
            * the API now refuses at this stage.
            */}
          <p className="py-4 text-center text-lg">
            Submit this application to BPLO for approval?
          </p>
        </ProtoModal>
      )}

      {/*
        ── ITEM 110 · identify the filing (over the wizard, before part 1) ──

        Last in the tree so it paints over everything, and mounted only while
        it is being asked — ProtoModal moves focus in on mount and puts it back
        on unmount, so `identify && (...)` is what makes both happen. Pressing
        Change on Business Information remounts it, and focus returns to the
        Change button.

        Escape closes it, because there IS a legitimate way out of this
        question and it would be wrong to pretend otherwise: on entry it is
        "not now, I will do this later" and nothing has been written yet; from
        Change it is "keep what I had" and nothing is written either. A modal
        with no honest dismissal is the only case where trapping Escape would
        be right, and this is not that case.
      */}
      {identify && isReuse && (
        <IdentifyFilingModal
          applicationType={applicationType as 'renewal' | 'amendment'}
          ownedBusinesses={ownedBusinesses.data ?? []}
          businessesLoading={ownedBusinesses.loading}
          permitTypes={permitTypes}
          initial={{
            businessId: prefillBusinessId,
            permitId: priorPermitId,
            permitIds: priorPermitIds,
            declaredNone: priorPermitDeclaredNone,
            amendment,
          }}
          mode={identify}
          confirming={confirmingIdentity}
          confirmError={identifyError}
          onCancel={cancelIdentity}
          onConfirm={(identity) => void confirmIdentity(identity)}
        />
      )}
    </div>
  )
}
