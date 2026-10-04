import { Fragment, useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { MapPicker } from '../../components/MapPicker'
import {
  GENDERS,
  ORGANIZATION_FORMS,
  TIN_ERROR,
  emailValid,
  genderLabel,
  lotAreaValid,
  percentValid,
  phoneValid,
  plainAmount,
  registrationNumberValid,
  tinValid,
  websiteValid,
} from '../../lib/fieldRules'
/* Answers saved before the API will accept a business — see its docblock. */
import { wizardDrafts } from '../../lib/resources'
import { mainFormTargets } from '../../lib/returnTargets'
import { PsicPicker, type PsicPickerHandle } from '../../components/PsicPicker'
import {
  OFFICE_FORM_CODES,
  OFFICE_FORM_META,
  OfficeFormSheet,
  hasOfficeForm,
  officeFormMissing,
  type CarriedOverBusiness,
  type OfficeFormCode,
  type OfficeFormData,
} from './OfficeFormStep'
import { barangayCentre, checkPin, withinMalabon } from '../../lib/malabonGeo'
import { OTHER_PSIC_CODE } from '../../lib/psic'
import { geocodeInMalabon, reverseGeocode, streetQuery } from '../../lib/geocode'
import {
  CheckCircleFilledIcon,
  CheckIcon,
  ClipboardIcon,
  UploadIcon,
} from '../../components/icons'
import { ConfirmEmailCard } from '../../components/EmailCode'
import { Alert } from '../../components/ui/Alert'
import { DocumentActions } from '../../components/DocumentActions'
import { TinInput } from '../../components/TinInput'
import { LandlineInput, MobileNumberInput } from '../../components/ContactNumberInput'
import { MOBILE_ERROR, canonicalMobile, mobileValid } from '../../lib/phone'
import { Skeleton } from '../../components/ui/primitives'
import {
  FieldError,
  FieldLabel,
  OriginalsNotice,
  PillButton,
  ProtoModal,
  inputCls,
} from '../../components/ui/Proto'
import { formatBytes, formatDate, formatMoney, tradeName } from '../../lib/format'
import { toApiError } from '../../lib/api'
import {
  applications,
  businesses,
  documents,
  officeForms,
  payments,
  permits as permitsApi,
  reference,
} from '../../lib/resources'
import type { AmendmentAnswers } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { useAuth } from '../../stores/auth'
import { ACCEPT_ATTR, fileRejection, uploadErrorMessage } from './uploads'
/*
 * ── One office sheet is imported here, and only one ──────────────────────
 *
 * The six clearances and their sheets are a STAGE that opens after the first
 * payment (docs/clearances-after-payment.md), not steps of this wizard.
 * <ClearanceStage> mounts them on /applications/:id/clearances and owns them.
 * Importing ClearanceStage here, or mounting the other five sheets, would be
 * the first move back to the arrangement that replaced.
 *
 * `OfficeFormSheet` is the exception, for ZONING on an AMENDMENT. An
 * amendment that moves the premises applies for a fresh Locational Clearance
 * as part of the filing rather than after it — there is no payment stage on
 * an amendment to open one after — so CPDD's sheet is a step of this wizard,
 * uploads and notarised declaration included. Client, 21 September 2026:
 * *"it should be really the same as the application form for zoning
 * clearance, so it means it is there where you should upload that too."*
 *
 * It is the same component the stage renders, not a copy, and it is mounted
 * for that one code. Widening this to the other five would be the move the
 * paragraph above warns about.
 */
import BarangayZoningMap from './BarangayZoningMap'
import { ZoningRuleChecklist } from '../../components/ZoningRuleChecklist'
import { RequestedChanges } from '../../components/RequestedChanges'
import {
  fetchZoningCheck,
  useZoningCheck,
  type ZoningCheckQuery,
  type ZoningFactValues,
} from '../../lib/zoningCheck'
import {
  LocationInsightsPanel,
  ZoningConformanceNote,
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
  padAmountInput,
  type FeeProfileDraft,
} from './FeeProfileStep'
import type {
  AmendmentRow,
  OfficeFormRequirement,
  ApplicationType,
  Barangay,
  Business,
  BusinessPayload,
  DocumentType,
  FeeAssessment,
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
  /*
   * CPDD's Locational Clearance, on an amendment that moves the premises.
   *
   * A STEP rather than a section at the foot of the amendment form. It is a
   * different office's form with its own reference number, and burying it
   * under the last field of somebody else's made it read as an appendix to
   * the address boxes instead of the second filing it actually is. Client,
   * 21 September 2026: *"Don't put this here. Instead, there will be a new
   * added section here"* — pointing at the step bar.
   *
   * Conditional, and the only conditional step in the wizard: `sequence`
   * includes it exactly when the pin has moved, which is exactly when the
   * filing starts carrying a ZONING clearance.
   */
  | 'zoning'
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
/**
 * One office's own application form, as a step of this wizard.
 *
 * ── Why a renewal can have steps the business permit does not ──────────
 *
 * A renewal covers whichever permits the applicant ticked, and the business
 * permit need not be among them: a shop whose Sanitary Permit expires in
 * September renews that alone. `WorkflowService` has always known this — "such
 * a filing has no business-permit row to issue" — and the wizard did not. It
 * showed every renewal the whole BPLO form, so somebody renewing their Fire
 * and Sanitary permits was walked through Location & Zoning, Business
 * Information and Business Operation. Client, 21 September 2026: *"why does
 * Location & Zoning, Business Information & Registration, etc. are still here
 * if those sections are part of the business permit application form?"*
 *
 * The LGU's answer to what those applicants should see instead: *"we are told
 * that their renewal form is the same as their application form"* — so each
 * ticked office contributes its own sheet, the same `OfficeFormSheet` the
 * clearance stage renders.
 */
type OfficeStep = `office:${OfficeFormCode}`

type Phase = BasePhase | OfficeStep

/** The office code inside an office step, or null for an ordinary phase. */
function officeStepCode(phase: Phase): OfficeFormCode | null {
  return phase.startsWith('office:') ? (phase.slice('office:'.length) as OfficeFormCode) : null
}

const BASE_PHASES: BasePhase[] = [
  'privacy',
  'address',
  'business',
  'operation',
  'documents',
  'review',
]

/**
 * The amendment form's steps: the base list with its own question in front.
 *
 * Only `application_type === 'amendment'` uses this. A renewal ran a variant of
 * it until 9 September 2026 — see the note on `sequence` below for why it
 * stopped, and why this stayed.
 */
/**
 * The amendment form's steps — the LGU's own form, not the new one.
 *
 * ── Four steps, since 19 September 2026 ────────────────────────────────────
 *
 * This was the whole new-application sequence with an extra question in front,
 * and the client asked why: *"why does it have the same sections as the new
 * application form? The amendment form has clearly different fields/sections
 * as compared to the new application form."*
 *
 * It does. MCG-BPLO-FO-003 is ONE PAGE: a header block naming the taxpayer,
 * the business and its address; four groups of amendment, each with a blank
 * for the new value and its own list of requirements; and a Remarks box. There
 * is no Business Information section, no Location & Zoning, no Business
 * Operation — because an amendment does not re-declare the business, it
 * changes one thing about it. Walking somebody through five screens of their
 * own unchanged details to correct a floor area is asking them to re-file a
 * new application under another name.
 *
 * `address`, `business` and `operation` are therefore gone. What remains:
 *
 *  - `privacy` — NOT on the paper, and kept deliberately. A paper filing
 *    consents by signature; an online one has to be asked, and dropping it
 *    only here would make the one filing type that rewrites the register the
 *    one nobody agreed to terms on.
 *  - `amendments` — the form itself: the header block as context, and the new
 *    values.
 *  - `documents` — the requirement lists, which the paper prints PER GROUP.
 *  - `review` — before and after, then file.
 *
 * `zoning` is NOT in this array and joins it conditionally — see `sequence`.
 * An amendment that moves the pin applies for a fresh Zoning Clearance, so
 * CPDD's sheet becomes a step between the changes and the documents; one that
 * only corrects how an address is written does not, and never sees it.
 */
/**
 * Mode of Payment, as MCG-BPLO-FO-002 prints it.
 *
 * Three boxes on the paper. Revenue Code Sec. 2N provides for two — annual,
 * in the first twenty days of January, and quarterly, in the first twenty of
 * January, April, July and October. There is no semi-annual instalment in the
 * Code, and it is offered here because the form the city hands out at the
 * counter offers it: an applicant who ticked it on paper has to be able to
 * file the same answer online.
 */
const PAYMENT_MODES = [
  { value: 'annual', label: 'Annually' },
  { value: 'semi_annual', label: 'Semi-Annually' },
  { value: 'quarterly', label: 'Quarterly' },
] as const

type PaymentMode = (typeof PAYMENT_MODES)[number]['value']

const AMENDMENT_PHASES: BasePhase[] = ['privacy', 'amendments', 'documents', 'review']

/**
 * A renewal of the business permit: MCG-BPLO-FO-002, and only what it asks.
 *
 * The paper has two sections. A is the amendment question, which came out of
 * this wizard on 9 September 2026 when amendments became their own filing
 * type. B is Business Operation. That leaves Section B, its documents and the
 * review — which is this array.
 *
 * `address` and `business` are NOT here, on the client's instruction of
 * 24 September 2026. They asked the applicant to retype a registration
 * number, a TIN, an owner's name and a pin on a map that the city has held
 * since the business first filed and that a renewal, by definition, is not
 * changing. A renewal that does need them changed files an amendment.
 *
 * The FORM STATE for those sections is untouched — see the note on
 * `sequence`. What goes is the asking.
 */
const RENEWAL_PHASES: BasePhase[] = ['privacy', 'operation', 'documents', 'review']

/*
 * `business` is captioned with the paper's full section title now — "Business
 * Information & Registration" — rather than the half of it that fitted while
 * the step also carried Section B. The two names have to be distinguishable at
 * a glance in the section map: "Business Information" next to "Business
 * Operation" is one word apart and easy to misread when you are looking for
 * where you left off.
 */
/**
 * What a step is called in the bar and the section map.
 *
 * ── An office step is named after the PERMIT, not its paperwork ─────────
 *
 * The office sheets carry their papers' own titles, and those titles do not
 * agree with each other: "Application for Sanitary Permit to Operate", "Fire
 * Safety Inspection Certificate (FSIC) Application", "Application for
 * Certificate of Environmental Clearance (CEC)" — two lead with the word
 * Application and one trails it. Side by side in a step bar that reads as
 * carelessness, which is what the client saw: *"make the naming consistent
 * for the sections of the other permits."*
 *
 * `permit_types.name` is the fix and not merely a tidier string. It is what
 * the applicant ticked in the dialog two screens ago, what the register
 * calls the thing, and what will be printed on the certificate they get —
 * so the bar agrees with every other surface by construction rather than by
 * a table somebody has to remember to update.
 *
 * "Renewal" is deliberately NOT prefixed. The client offered "Renewal for
 * Sanitary Permit"; the page is already titled "2026 Renewal — Pedro's Snack
 * Bar", so the word would repeat down every pill to say something said once
 * above them, and these labels stay correct if the same steps are ever
 * reached from a filing that is not a renewal.
 *
 * Falls back to the paper's title while the reference data is still loading,
 * which is a blank bar's worth of milliseconds and better than an empty pill.
 */
function phaseLabel(phase: Phase, permitName: (code: OfficeFormCode) => string): string {
  const code = officeStepCode(phase)
  if (code !== null) return permitName(code)

  /*
   * Narrowing on `code` does not narrow `phase`, because the two are only
   * related through the template literal. The cast is safe by construction:
   * `officeStepCode` returns null for exactly the phases BASE_LABELS covers.
   */
  return BASE_LABELS[phase as BasePhase]
}

const BASE_LABELS: Record<BasePhase, string> = {
  privacy: 'Data Privacy Consent',
  amendments: 'Changes Since Last Permit',
  zoning: 'Zoning Clearance',
  business: 'Business Information & Registration',
  operation: 'Business Operation',
  address: 'Location & Zoning',
  documents: 'Documentary Requirements',
  /*
   * 'Tax Classification & Fees' stood here, and before that 'Fees & Tax
   * Computation' — renamed on 16 September 2026 because the old name read as a
   * step that COMPUTED something for the applicant while computing nothing
   * visible, which is what prompted the client's "why does this part exist?".
   *
   * The answer, in the end, was that it should not. The paper form has no such
   * section, and the step was removed the same day. Most of what it held went
   * with it — the mode of payment (stored and read by nothing), the occupancy
   * boxes (never once rendered), the storey count (priced nothing) and the
   * 273-label classification picker (derived from the line of business now,
   * and mis-billing while it was asked). What survived moved to Business
   * Operation, after the paper's own item 8, and the estimate moved to Review.
   */
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
 * Where step 1 lives until the API will accept it.
 *
 * Per tab, and gone when the tab is. The reasoning for sessionStorage over
 * localStorage is on the effects that use this, and it is about a shared
 * terminal at City Hall rather than about storage.
 */
/*
 * The tab's own copy, one slot PER FILING TYPE.
 *
 * It was a single key while only a new permit was backed up. With renewals
 * and amendments backed up too (3 October 2026) one key would mean a renewal
 * started in one tab overwriting the new permit half-typed in another, and
 * whichever was reopened first would silently take the other's answers.
 */
const draftBackupKey = (type: ApplicationType) => `biztrack:apply:pre-draft:${type}`
/*
 * Bumped whenever `FormState` or `FeeProfileDraft` changes shape. A backup
 * from an older build would restore fields that have moved or gone, which is
 * a worse outcome than starting blank — so a mismatch is discarded in
 * silence.
 */
const DRAFT_BACKUP_VERSION = 1

type DraftBackup = {
  v: number
  at: string
  applicationType: ApplicationType
  title: string
  form: FormState
  feeDraft: FeeProfileDraft
  consent: boolean
  /**
   * What the entry dialog was told, on a renewal or an amendment.
   *
   * A new permit has no such answer — the form IS the filing. A renewal
   * begins by naming a business and the permits it carries forward, and an
   * amendment by naming the record it changes, and none of that lives in
   * `form`. Without it here a resumed renewal would come back with every
   * field filled in and no idea what it was a renewal OF, and the dialog
   * would open over the restored answers asking again.
   *
   * Optional, because a backup written before 3 October 2026 has none and
   * a new permit never will. Absent means "ask", which is the old
   * behaviour and the safe one.
   */
  identity?: {
    businessId: number | null
    priorPermitId: number | null
    priorPermitIds: number[]
    amendment: AmendmentState
  }
  /**
   * Whether the applicant named this filing themselves.
   *
   * Carried because restoring `title` alone is not enough to keep it. The
   * suggestion effect replaces the title with the business name whenever
   * `titleEdited` is false — so a resumed draft came back with the applicant's
   * own name in the box for an instant, and then had it overwritten the moment
   * the business loaded. Observed as "Nena's Sari-Sari Store" where
   * "E2E Renewal Draft …" had been saved.
   *
   * Optional, because a backup written before 4 October 2026 has none. Absent
   * reads as false, which is the old behaviour and the safe direction: a title
   * nobody chose goes on tracking the business name.
   */
  titleEdited?: boolean
}

/*
 * The Mayor's / Business Permit is the OUTCOME of the application, not a
 * clearance you tick, so it is never rendered as a card: the wizard attaches
 * it to every application so BPLO always gets the assignment.
 */
const BUSINESS_PERMIT_CODE = 'BUSINESS'


/*
 * What the BPLO counter sees most, shown before the applicant types anything.
 * The full list is long enough that "the first eight by code" would open on
 * food manufacturing instead of the sari-sari store.
 */

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

/**
 * The sections Review draws in full, in the order the wizard walks them.
 *
 * Privacy is left out because it is a consent, not a section of the form — it
 * has its own tick on its own step and re-presenting it among the answers
 * would invite an applicant to think the consent is part of what they are
 * reviewing rather than the thing that let the form be collected at all.
 * Amendments is left out because only an amendment filing has it, and it
 * describes the filing rather than the business.
 */
const REVIEW_SECTIONS = ['address', 'business', 'operation', 'documents'] as const

type ReviewSectionName = (typeof REVIEW_SECTIONS)[number]

const TYPE_META: Record<ApplicationType, { title: string; ref: string }> = {
  new: {
    title: 'Application for New Business Permit',
    ref: 'MCG-BPLO-FO-001 · v2.0',
  },
  renewal: {
    title: 'Application for Renewal of Business Permit',
    ref: 'MCG-BPLO-FO-002 · v2.0',
  },
  amendment: {
    title: 'Application for Amendment of Business Permit',
    ref: 'MCG-BPLO-FO-003 · v2.0',
  },
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
  /**
   * BPLO item 5, in the paper's own two boxes.
   *
   * It was one field, "House No. & Street Name", written to `line1`. The
   * paper asks twice and the schema has carried `house_bldg_no` and
   * `street` all along — and the combined question produced addresses the
   * officer's own review had to guess apart with a regex, getting it backwards
   * on any filing whose applicant read the label as asking for the number
   * (there are rows whose entire street address is "17").
   *
   * `line1` stays on the state because a reopened draft still arrives with
   * one, and the geocoder takes a single line. The API composes it from these
   * two on save.
   */
  house_bldg_no: string
  street: string
  /*
   * Block, Lot and the lot's area (client, 23 September 2026). Optional —
   * plenty of premises have no block or lot. The area is the LOT; the floor
   * area the fee engine assesses is Business Operation's item 1.
   */
  block: string
  lot: string
  lot_area_sqm: string
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
  house_bldg_no: '',
  street: '',
  block: '',
  lot: '',
  lot_area_sqm: '',
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
   * question having been put is the same silent-null bug the prior-permit field
   * suffered one field over, where an unanswered question and a real answer
   * both arrived as null and seven filings went out the wrong one. No is a
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
  {
    value: 'others',
    label: 'Others',
    hint: 'None of the five above — say what it is.',
  },
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
/**
 * Is there anybody to ask items 13 to 15 about? Yes — for every structure.
 *
 * ── The paper settles this, and we had it wrong ───────────────────────────
 *
 * This returned false for a sole proprietorship, on the reasoning that a sole
 * proprietor IS their own officer in charge and item 11 already names them, so
 * asking again was the duplicate-question failure the client had called out.
 * Sound reasoning, wrong conclusion: MCG-BPLO-FO-001 prints
 *
 *   11. Sole Proprietorship (go to 13)
 *   12. Corporation/Partnership Cooperative (go to 13)
 *
 * Both arrows point at 13. The form asks every structure for the president or
 * officer in charge, their citizenship and the Filipino capital share, and the
 * skip was ours — never confirmed with BPLO, and visible on the officer's
 * sheet as three unexplained dashes on a filing whose applicant had answered
 * everything they were shown. Client's decision to follow the paper,
 * 16 September 2026.
 *
 * Kept as a function rather than deleted. It is the one place that answers
 * "who is asked items 13 to 15", and if the city ever narrows it again that is
 * a one-line change here instead of a hunt through four call sites.
 */
function hasPresidentOrOfficer(_registrationType: string): boolean {
  return true
}

/** A lot area in sq. m.: a positive number, commas allowed. Blank is not checked here. */

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

/* ── Form of organization, and the agency it decides (item 94) ──────────── */

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
/*
 * The list moved to `lib/fieldRules` on 30 September 2026, when the
 * officer's review sheet began offering the same four as a correction
 * control. Kept under its own name here because eight call sites below
 * read it, and renaming them would bury the one-line change that matters.
 */
const REGISTRATION_TYPES: {
  value: string
  label: string
  agency: RegistrationAgency
}[] = ORGANIZATION_FORMS

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
  { label: string; placeholder: string; hint: string; shape: RegExp | null }
> = {
  DTI: {
    /*
     * "DTI Registration Number", shortened 24 September 2026.
     *
     * It read "DTI Business Name Registration Number" — the certificate's
     * full title, and the longest field name on the form by half again. Once
     * the fields were packed to their own widths it was the only label that
     * wrapped, which dropped its input a line below everything beside it.
     *
     * It also said "Business Name" in a field that is not the business name
     * — item 3 is — so the shorter version is the clearer one as well. The
     * hint underneath still names the certificate in full.
     */
    label: 'DTI Registration Number',
    placeholder: 'as printed on your DTI certificate',
    hint: 'Issued by the Department of Trade and Industry. Copy it from your Certificate of Business Name Registration.',
    /*
     * No shape, so nothing is ever called unusual. DTI publishes no format —
     * not in its Citizen's Charter, not in the BNRS FAQ, not in the IRR — so
     * there is no typical to compare against, and inventing one would flag
     * correct certificates as odd. Silence is the honest answer here.
     */
    shape: null,
  },
  SEC: {
    label: 'SEC Registration Number',
    placeholder: 'e.g. CS201912345',
    hint: 'Issued by the Securities and Exchange Commission. It looks like CS201912345, though older certificates use other prefixes.',
    /*
     * The shapes actually present in SEC's published registers: an optional
     * letter prefix (CS, A, AS, ASO, CEO, CN, PP), 4 to 11 digits, and an
     * optional trailing suffix like CS200729932-A or the embedded hyphen in
     * ASO91-195123. Bare numerics are real too — "1074" is the shortest
     * specimen found anywhere.
     */
    shape: /^(?:CS|CN|CEO|ASO|AS|A|PP)?\d{4,11}(?:-[A-Za-z0-9]{1,6})?$/i,
  },
  CDA: {
    label: 'CDA Registration Number',
    placeholder: 'e.g. 9520-15005879',
    hint: 'Issued by the Cooperative Development Authority. It looks like 9520-15005879, though the digits after the dash vary in length.',
    /*
     * CDA's masterlist runs a 4-to-5 digit series prefix — the 9520- and
     * 10744- series — then 8, 12 or 16 digits after the dash.
     */
    shape: /^\d{4,5}-\d{8,16}$/,
  },
}

/**
 * Does this number depart from the agency's usual printed shape?
 *
 * ── Why this WARNS and never refuses (checklist items 21 and 26) ───────────
 *
 * The client asked twice for validation rules per agency, and the reason there
 * were none is in `registrationNumberValid` below: SEC's own registers carry
 * more than twenty distinct shapes, CDA runs four at once, and DTI publishes
 * none. A regex tight enough to catch a wrong answer also refuses certificates
 * real businesses are holding — and a refused applicant cannot file at all,
 * while a mistyped number is caught by the officer who opens the uploaded
 * certificate a few days later. That asymmetry is the whole argument.
 *
 * So there are rules now, and they are advisory. An unusual value gets a note
 * asking the applicant to check it against their certificate, and the form
 * still accepts it. This catches the case the client is actually worried about
 * — a transposed digit or the wrong number copied — without inventing an
 * authority over the shape of a document three national agencies issue.
 *
 * Returns false while the value is empty or already failing the hard rule:
 * there is no point telling somebody their number is unusual underneath a
 * message telling them it is not a number.
 */
/*
 * `registrationNumberUnusual()` stood here and is gone with the advisory
 * note it fed — 30 September 2026, at the client's request.
 *
 * The `shape` regexes in REGISTRATION_AGENCIES above are deliberately KEPT.
 * They are the only record anyone here has of what these three numbers
 * actually look like, gathered from the agencies' own registers, and the
 * next person asked for per-agency validation should read them first. On
 * 30 September I did not: I researched SEC off a web summary, required one
 * of five prefixes, and would have refused the four other prefixes those
 * regexes record — CEO, ASO, AS, PP — along with every bare-numeric
 * registration SEC has issued. The asymmetry that argues against enforcing
 * any of it is worth repeating: a refused applicant cannot file at all,
 * while a mistyped number is caught by the officer who opens the uploaded
 * certificate a few days later.
 */

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

/*
 * `normalizeRegistrationNumber` was here, mirroring
 * `Business::normalizeRegistrationNumber` so the browser and the API agreed
 * about when two numbers are the same one.
 *
 * Its only caller was the "already registered under this number" notice,
 * removed on 24 September 2026 — see the note where that notice was drawn.
 * `tsc` found this the moment the notice went, which is the argument for
 * taking a whole chain out rather than just the markup: a helper still
 * computing an answer nobody reads is how the next person concludes the
 * feature is still live.
 *
 * The API's own comparison is untouched. Nothing in the browser needs to
 * agree with it any more, and if something does again, copy it back from
 * the PHP rather than from memory — the two drifting is the failure the
 * original note was warning about.
 */

/**
 * Philippine TIN: 9 digits, plus a 3 to 5 digit branch code where the taxpayer
 * has one, written with any of the usual separators (123-456-789-000,
 * 123 456 789, 123456789). The API normalises and re-checks the same shape.
 */


/**
 * Philippine contact number: an 11-digit mobile (09XX XXX XXXX), the same
 * number written +63, or a landline with or without its area code. Deliberately
 * lenient about separators — the point is to catch a typo, not a format.
 */

const PHONE_ERROR = 'Enter a valid mobile or landline number.'

/**
 * BPLO item A9. Loose on purpose: it accepts what somebody would type into a
 * browser, with or without a scheme and with or without www. What it refuses is
 * a sentence or an email address, which is the mistake this field actually
 * attracts — the point is to catch a wrong KIND of answer, not to police a URL.
 */

/**
 * BPLO item A8 — the BUSINESS's own e-mail, which became required on
 * 9 September 2026.
 *
 * As lenient as `websiteValid` beside it and for the same reason: the mistake
 * this field attracts is a wrong KIND of answer — a phone number, a name, a
 * sentence — not a subtly malformed address. Anything with one @ between two
 * non-empty parts, a dot in the domain and no whitespace is somebody's real
 * mailbox as far as this form is concerned, and refusing a valid unusual
 * address is a worse failure than accepting a typo an officer will notice.
 */

/** Strip the display separators before an amount goes to the API. */

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
      <path
        d="M9 13.3l2 2 3.6-3.8"
        stroke="#fff"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
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
/**
 * One section of the form, drawn on its own step and again on Review.
 *
 * ── Why a wrapper and not a second copy ───────────────────────────────────
 *
 * Review shows the whole application — Location & Zoning through Documentary
 * Requirements — editable, so an applicant can check and correct everything
 * before they commit. The client's requirement was that the fields there match
 * the section pages, and the only way to guarantee that PERMANENTLY is for
 * there to be one definition of each field. A second, read-only or re-typed
 * rendering would match on the day it was written and drift on the next edit,
 * silently, in the direction of showing an applicant something they did not
 * type.
 *
 * So this decides whether a section is drawn; the section's own JSX is
 * untouched. `phase === name` draws it as its own step, exactly as before.
 * `reviewing` draws all of them at once, each behind a heading it can be
 * collapsed by.
 *
 * ── Collapsible, and open by default ──────────────────────────────────────
 *
 * Four sections of form fields is thousands of pixels, and this wizard has no
 * sticky footer — Submit is at the bottom of the page. Collapsed by default
 * the review would add nothing over the step nav the applicant already has,
 * and most would never open it; expanded with no way to fold it, finishing
 * means scrolling past everything again. So: open, with a heading that closes
 * it, and an Expand-all / Collapse-all above the lot.
 *
 * `hidden` rather than unmounting a collapsed section, deliberately. Unmounting
 * would drop uncontrolled DOM state — a half-typed value the applicant has not
 * blurred yet, the map's own view, a file input — and collapsing a section to
 * get it out of the way must not be a way to lose work.
 */
function WizardSection({
  name,
  phase,
  reviewing,
  inSequence,
  label,
  open,
  onToggle,
  answers,
  editing,
  onEdit,
  missing,
  children,
}: {
  name: BasePhase
  phase: Phase
  reviewing: boolean
  /**
   * Is this section part of the filing's own sequence?
   *
   * False for a section another filing type has and this one does not. Such a
   * section must not appear on Review either — see the note in the body.
   */
  inSequence: boolean
  label: string
  open: boolean
  onToggle: () => void
  /** The section's answers, for the summary this draws instead of the form. */
  answers: ReviewAnswer[]
  editing: boolean
  /** Open the real form; a field id focuses that field once it is on screen. */
  onEdit: (focusId?: string) => void
  /** What this section still needs, so the heading can say so. */
  missing: string[]
  children: React.ReactNode
}) {
  const asStep = phase === name

  /*
   * ── A section not in this filing's sequence is not on its Review ─────────
   *
   * `reviewing` alone used to be enough, because every filing type walked
   * every section. An amendment no longer does — it is four steps, not seven
   * (see AMENDMENT_PHASES) — and without this its Review drew Business
   * Information, Location & Zoning and Business Operation: three sections the
   * applicant was never shown, summarising answers they never gave, each with
   * an Edit button onto a step that is not in their form.
   *
   * Checked against the SEQUENCE rather than the filing type, so the next
   * form to drop a section gets this for free.
   */
  if (!inSequence) return null
  if (!asStep && !reviewing) return null

  // On its own step it renders exactly as it always did: no heading of ours, no
  // summary, no controls — nothing between the applicant and the fields.
  if (asStep) return <>{children}</>

  const incomplete = missing.length > 0

  return (
    <section aria-labelledby={`review-${name}`} className="scroll-mt-3">
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-t-sm border-b-2 border-royal bg-royal-tint px-5 py-3">
        <h2 id={`review-${name}`} className="text-[15px] font-bold text-ink">
          {label}
          {/*
            Complete or not, on the heading, because a collapsed section that
            says only its name destroys the one thing worth knowing about it.
            The count rather than the field names: the names are in the summary
            a line below, and the nav already lists them before Submit.
          */}
          <span
            className={`ml-2.5 align-middle text-[11px] font-bold uppercase tracking-wide ${
              incomplete ? 'text-s-red' : 'text-s-green'
            }`}
          >
            {incomplete ? `${missing.length} still needed` : 'Complete'}
          </span>
        </h2>
        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={() => onEdit()}
            aria-pressed={editing}
            className="text-xs font-semibold text-royal underline underline-offset-2 hover:text-royal/80"
          >
            {editing ? 'Done editing' : 'Edit this section'}
          </button>
          <button
            type="button"
            onClick={onToggle}
            aria-expanded={open}
            aria-controls={`review-body-${name}`}
            className="text-xs font-semibold uppercase tracking-wide text-ink-secondary hover:text-ink"
          >
            {open ? 'Hide' : 'Show'}
          </button>
        </div>
      </div>
      {/*
        `hidden` rather than unmounting, both here and for the form below.
        Unmounting would drop uncontrolled DOM state — a half-typed value not
        yet blurred, the map's own view, a file input — and folding something
        away to get it out of the way must never be a way to lose work.
      */}
      <div
        id={`review-body-${name}`}
        hidden={!open}
        className="rounded-b-sm bg-white px-5 py-3 shadow-card"
      >
        <dl hidden={editing}>
          {answers.length === 0 ? (
            <p className="py-2 text-sm text-ink-muted">
              Nothing to show here yet. Press{' '}
              <span className="font-semibold">Edit this section</span> to fill it in.
            </p>
          ) : (
            answers.map((answer) => (
              <ReviewRow
                key={answer.label}
                answer={answer}
                onChange={() => onEdit(answer.focusId)}
              />
            ))
          )}
        </dl>
        <div hidden={!editing}>{children}</div>
      </div>
    </section>
  )
}

/** One answer on the review summary: what was asked, what was said, and Change. */
type ReviewAnswer = {
  label: string
  value: string
  /** DOM id to focus once the section opens for editing, where one exists. */
  focusId?: string
}

/**
 * The review summary: one row per answer, read rather than filled in.
 *
 * ── Why the review does not simply show the form ──────────────────────────
 *
 * It did, and that was the wrong tool. A form is built for INPUT — big
 * targets, placeholders, helper text, a slot under every field for a
 * validation message — and a review is for READING. Checking sixty answers
 * rendered as sixty input boxes is slow, and the noise is where a mistake
 * hides. This is the "check your answers" shape: label, answer, and a way to
 * change it.
 *
 * ── And why the inputs are still the originals ────────────────────────────
 *
 * Only the SUMMARY is written twice; the fields are not. Press Change and the
 * section swaps to the real form from its own step — the same JSX, not a
 * re-implementation — with the field focused. So the thing that can drift is
 * a label and a formatted value, not the behaviour of an input.
 *
 * That the summary can drift at all is the known cost of this shape, and it is
 * not hypothetical: the OFFICER's review page hand-writes its own summary and
 * was still rendering Lessor's Name, Lessor's Address, Lessor's Contact Number
 * and Monthly Rental months after the wizard stopped collecting them, showing
 * BPLO four rows of "—" on every rented filing. So `reviewAnswersMatchTheForm`
 * in the e2e suite counts the inputs a section renders against the rows this
 * summary claims for it, and fails when they part company. Drift becomes a red
 * build instead of a blank on somebody's screen.
 */
function ReviewRow({ answer, onChange }: { answer: ReviewAnswer; onChange: () => void }) {
  return (
    <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1 border-b border-line py-2.5 last:border-b-0 sm:flex-nowrap">
      <dt className="w-full text-[13px] font-semibold text-ink-secondary sm:w-56 sm:shrink-0">
        {answer.label}
      </dt>
      {/*
        An unanswered question shows an em dash rather than nothing. A blank
        looks like the row failed to load; "—" says the question was reached
        and left empty, which is what the applicant needs to notice.
      */}
      <dd className={`flex-1 text-sm ${answer.value ? 'text-ink' : 'text-ink-muted'}`}>
        {answer.value || '—'}
      </dd>
      <button
        type="button"
        onClick={onChange}
        className="shrink-0 text-xs font-semibold text-royal underline underline-offset-2 hover:text-royal/80"
      >
        Change
        {/*
          The label alone reads as "Change" sixty times over to anybody using a
          screen reader's list of links. The question it changes goes in the
          accessible name, and stays out of the visible one so the column of
          links remains a column.
        */}
        <span className="sr-only"> {answer.label}</span>
      </button>
    </div>
  )
}

function FormSheet({
  meta,
  filing,
  compact = false,
  children,
}: {
  meta: { title: string; ref: string }
  /**
   * Which record this filing is about, shown on every step.
   *
   * ── The paper's own header block ─────────────────────────────────────────
   *
   * Client, 19 September 2026: *"I think it is good if there is a display
   * somewhere in the page the business that the user is renewing for the user
   * to clearly see."*
   *
   * MCG-BPLO-FO-003 opens with exactly this — Taxpayer's Name, Business Name,
   * Account Number, Address — and it is not input. It is the record being
   * amended, printed at the top so the counter and the applicant are looking
   * at the same shop. BizTrack already knows all of it, so it is shown rather
   * than asked.
   *
   * It matters most to the applicant who owns several businesses: until now
   * the only place the answer appeared was inside the dialog that set it, so
   * five steps later there was nothing on screen saying which shop this was.
   */
  filing?: {
    business: string
    permit?: string | null
    /**
     * The rest of MCG-BPLO-FO-003's header block, when the form has one.
     *
     * The paper opens with five lines — Taxpayer's Name, Business Name,
     * Account Number, Address, Date of Application — and every one of them is
     * something BizTrack already holds. Client, 21 September 2026: *"This part
     * in the paper does not exist in the amendment form application in our
     * system. Make sure this is auto-filled."*
     *
     * Optional, because the renewal form's header is its own and the one-line
     * banner is right there. Present, the block replaces the banner.
     */
    taxpayer?: string | null
    accountNumber?: string | null
    address?: string | null
    dated?: string | null
  } | null

  /**
   * Drop the form's identity block — the office line, the title and the form
   * reference.
   *
   * Set on Review, where all four sections are drawn at once. The identity is
   * a fact about the WHOLE form and two of the sections use this sheet, so
   * repeating it would state twice, in the middle of the page, what the
   * heading at the top already said once — and an applicant scanning for their
   * answers would meet the form's title again where they expected a section.
   */
  compact?: boolean
  children: React.ReactNode
}) {
  return (
    <div className="rounded-sm bg-white px-6 py-7 shadow-card sm:px-9 sm:py-8">
      {!compact && (
        <>
          <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-royal">
            Business Permit &amp; Licensing Office · Phase 1
          </p>
          <h1 className="mt-1.5 text-2xl font-bold text-ink">{meta.title}</h1>
          <p className="mt-1 text-xs text-ink-muted">Form Ref: {meta.ref}</p>
          <div className="mb-4 mt-3 h-px bg-royal" />
        </>
      )}
      {/*
        Above the section, and outside the `compact` branch on purpose: Review
        drops the form's identity because it repeats itself across four
        sections, but WHICH BUSINESS is the one fact worth stating there too.
      */}
      {filing && (
        /*
          Two lines, not one, and the name at heading weight.

          It was a single tinted row with the business set in 14px beside its
          own caption, which read as a hint about the form rather than the
          record the form is about — client, 21 September 2026: *"Make the
          'This Filing is for...' much more apparent."* The name now carries
          the size, the caption shrinks to a label above it, and the left rule
          marks the whole block as stated fact.

          Still two lines tall, because this sits on every step of a seven-part
          form and a panel that has to be scrolled past six times is a panel
          that stops being read.
        */
        <div className="mb-4 rounded-lg border border-royal/30 border-l-4 border-l-royal bg-royal-tint/60 px-4 py-3">
          <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-royal">
            This filing is for
          </p>
          <p className="mt-1 flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5 text-lg font-bold leading-tight text-ink">
            {filing.business}
            {filing.permit && (
              <span className="tnum text-sm font-semibold text-royal">{filing.permit}</span>
            )}
          </p>

          {/*
            ── The paper's header block, filled in ─────────────────────────

            MCG-BPLO-FO-003 opens with Taxpayer's Name, Business Name, Account
            Number, Address and Date of Application, and it is not INPUT: it
            is the record being amended, printed at the top so the counter and
            the applicant are looking at the same shop. BizTrack holds all
            five, so they are shown rather than asked — the whole point of the
            client's *"make sure this is auto-filled"*.

            A definition list, because that is what it is: five labels and
            five facts, read as pairs by a screen reader instead of as ten
            loose strings.

            Business Name is not repeated here — it is the heading directly
            above, at four times the size, and printing it twice in four
            centimetres reads as a rendering fault.
          */}
          {(filing.taxpayer ?? filing.accountNumber ?? filing.address ?? filing.dated) && (
            <dl className="mt-3 grid gap-x-4 gap-y-1 border-t border-royal/20 pt-3 text-xs sm:grid-cols-[auto_1fr]">
              {[
                ['Taxpayer’s Name', filing.taxpayer],
                ['Account Number', filing.accountNumber],
                ['Address', filing.address],
                ['Date of Application', filing.dated],
              ].map(([label, value]) =>
                value == null || value === '' ? null : (
                  <Fragment key={label}>
                    <dt className="font-semibold text-ink-secondary sm:text-right">{label}</dt>
                    <dd className="min-w-0 text-ink">{value}</dd>
                  </Fragment>
                ),
              )}
            </dl>
          )}
        </div>
      )}
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
/**
 * One numbered question on the Location & Zoning step (Ken's layout, 27
 * September 2026: one column, in the order people answer them).
 *
 * The number is always shown, done or not, because the map's lock names the
 * steps by number ("once steps 1 and 2 are answered"). Done is said in words
 * beside the title, with a tick, and the number's circle fills: the fill
 * alone would be colour carrying meaning (DESIGN.md, Never Color Alone).
 *
 * A list item holding a section: the steps are an ordered list because their
 * order is the instruction, and each is a labelled region so a screen reader
 * can jump between them by heading.
 */
function LocationStep({
  n,
  title,
  required = false,
  done,
  hint,
  children,
}: {
  n: number
  title: string
  required?: boolean
  done: boolean
  hint?: string
  children: React.ReactNode
}) {
  const headingId = `location-step-${n}`
  return (
    <li className="list-none">
      <section
        aria-labelledby={headingId}
        className="rounded-2xl bg-white px-4 py-5 shadow-card sm:px-6"
        data-testid={`location-step-${n}`}
      >
        <div className="mb-4 flex items-start gap-3">
          <span
            aria-hidden="true"
            className={`tnum grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm font-bold ${
              done ? 'bg-royal text-white' : 'border-2 border-royal/50 bg-white text-royal'
            }`}
          >
            {n}
          </span>
          <div className="min-w-0 flex-1">
            <h2
              id={headingId}
              className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-lg font-bold leading-8 text-ink"
            >
              <span className="sr-only">Step {n}: </span>
              <span>
                {title}
                {required && (
                  <span className="text-s-red" aria-hidden="true">
                    {' '}
                    *
                  </span>
                )}
              </span>
              {done && (
                <span className="inline-flex items-center gap-1 text-sm font-semibold text-[#12724a]">
                  <CheckIcon size={16} aria-hidden="true" />
                  Done
                </span>
              )}
            </h2>
            {hint && <p className="text-sm text-ink-secondary">{hint}</p>}
          </div>
        </div>
        {children}
      </section>
    </li>
  )
}

function LinesStep({
  codes,
  lines,
  onChange,
}: {
  codes: PsicCode[]
  lines: LineDraft[]
  onChange: (lines: LineDraft[]) => void
}) {
  const pickerRef = useRef<PsicPickerHandle>(null)

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
  /*
   * Named, never counted. A count is the multi-select's vocabulary: "Selected
   * (1)" answers "how many?", which is a question this step does not ask and
   * must not appear to. It also confirms nothing useful — the two sari-sari
   * rows in this list differ only by the words in their brackets, so the only
   * confirmation worth showing is the trade's name.
   */
  const selectedTitles = lines
    .map((l) => codes.find((c) => c.id === l.psic_code_id)?.title)
    .filter(Boolean)
    .join(', ')

  /** Reopen the picker on the applicant's own terms, caret already in the box. */
  function reopen() {
    pickerRef.current?.reopen()
  }

  return (
    <div className="space-y-3">
      <PsicPicker
        ref={pickerRef}
        codes={codes}
        chosenId={lines[0]?.psic_code_id ?? null}
        /*
         * Replaces rather than appends, which is the whole of this step's
         * one-trade rule. See the long note above on why `lines` stays an
         * array even though this wizard writes exactly one.
         */
        onPick={(code) =>
          onChange([{ psic_code_id: code.id, line_of_business: '', products_services: '' }])
        }
        label="Search for the one line of business you are registering"
        required
      />

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
        /*
         * Zoning 11 (Ken: "the products and services aren't apparent"). The
         * chosen trade was one small semibold line in a pale box, and
         * Products / Services under it was a muted 14px label over a thin
         * white input: easy to read as a caption and walk past, though it is
         * required and the step will not advance without it.
         *
         * So the answer is shown as an answer: a 2px royal border, a tick,
         * the trade's name at 17px bold. Products / Services gets a label at
         * the weight of every other question on the step and a full-size
         * input, so it reads as a question rather than a footnote. Royal, not green: it is a selection, not a verdict.
         */
        <div className="rounded-xl border-2 border-royal bg-royal-tint p-4">
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
          <p className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-[0.1em] text-royal">
            <CheckIcon size={14} aria-hidden="true" />
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
                  <div className="relative flex items-start gap-3">
                    <div className="min-w-0 flex-1">
                      {isOther ? (
                        <div>
                          <p className="truncate text-sm text-ink">
                            {line.line_of_business.trim() || 'Unclassified line'}
                          </p>
                          <p className="mt-0.5 text-sm text-s-red">
                            Not on the PSIC list, so this line cannot be assessed. Change it for the
                            closest trade on the list.
                          </p>
                        </div>
                      ) : (
                        <div>
                          <p className="text-[17px] font-bold leading-snug text-ink" data-testid="chosen-line">
                            {code?.title}
                          </p>
                          <p className="tnum mt-0.5 text-sm text-ink-secondary">
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
                    <FieldError>
                      Type the line of business you want registered.
                    </FieldError>
                  )}
                  {/*
                   * Products / Services, back — because the step now REFUSES
                   * to advance without it.
                   *
                   * It was taken out as "a field nobody asked for": a third
                   * row of chrome under every trade, on the heaviest step in
                   * the wizard, for an answer no form marked required. That
                   * reasoning was sound when nothing depended on it. It has
                   * since been made required per line, on the strength of a
                   * filing that reached CENRO with the PRODUCTS/SERVICES box
                   * on its CEC application empty — the PSIC title says which
                   * category a trade falls in, and an inspector cannot read
                   * "Retail sale in non-specialized stores" and learn whether
                   * there is food on the premises.
                   *
                   * The two changes met in the merge and the applicant paid
                   * for it: the gate demanded a value, no control existed to
                   * supply one, and Next could not be enabled on part 2 by
                   * anybody. A required field with no input is not a tidier
                   * form, it is a wall.
                   *
                   * It was kept deliberately small, one quiet line under the
                   * trade, as the "somewhere quieter" the removal asked for.
                   * Too quiet: Ken's Zoning 11 found people not seeing it at
                   * all, so it now carries a full-weight label and a full-size
                   * input (see the note on the panel above). Still
                   * under the trade it belongs to, still no card of its own.
                   */}
                  <label className="mt-4 block">
                    <span className="mb-1.5 block text-base font-bold text-ink">
                      Products / Services <span className="text-s-red" aria-hidden="true">*</span>
                    </span>
                    <input
                      type="text"
                      value={line.products_services ?? ''}
                      onChange={(e) =>
                        onChange(
                          lines.map((l) =>
                            l.psic_code_id === line.psic_code_id
                              ? { ...l, products_services: e.target.value }
                              : l,
                          ),
                        )
                      }
                      // Shape, not an answer (AGENTS.md §6.4): "e.g. milk tea, fried
                      // snacks" was an answer, and a sari-sari store owner is not
                      // selling milk tea.
                      placeholder="What customers buy from you, in a few words"
                      className="w-full rounded-lg border-2 border-input-border bg-white px-3 py-2.5 text-base text-ink placeholder:text-ink-muted focus:border-royal focus:outline-none focus:ring-2 focus:ring-royal/30"
                    />
                    {/*
                     * No red line under an empty box.
                     *
                     * It read "Required: three offices print this beside your
                     * line of business" — which was both wrong (it is five,
                     * not three) and beside the point. Why the field exists is
                     * our reason for asking, not something the applicant has
                     * to carry; naming the offices that will read an answer
                     * they have not given yet explains a filing cabinet to
                     * somebody trying to describe their shop.
                     *
                     * The asterisk says required, the same as every other
                     * required field on the step, and the "still needed on
                     * this part" list names it if they reach for Next without
                     * it. That is the moment the fact is useful.
                     */}
                  </label>
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
            <p className="mt-3 text-sm text-ink-secondary">
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
interface FilingIdentity {
  businessId: number
  /**
   * `null` means the question has not been answered yet — and since
   * 18 September 2026 that is all it can mean.
   *
   * It used to be two states wearing one value: a business with no BizTrack
   * permit that had TICKED to say so, and a question nobody had put. The
   * client has since ruled the first one out — no permit is ever renewed on a
   * manual system — so a renewal must name the permit it carries forward, and
   * a business holding none is filing a New Application, not a renewal.
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
   * Empty only while the question is unanswered; `permitId` is its first
   * element, so the two never disagree because one is derived from the other.
   */
  permitIds: number[]
  amendment: AmendmentState
  /**
   * The prefill the modal already fetched for `businessId`. Handed back so
   * confirming does not GET the same thing a second time.
   */
  prefill: PrefillResult
}

/** "1 Jan 2025 – 31 Dec 2025", or whichever half of it the register holds. */
function permitValidity(p: Permit): string {
  if (p.valid_from && p.valid_until)
    return `${formatDate(p.valid_from)} – ${formatDate(p.valid_until)}`
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
  initial: {
    businessId: number | null
    permitId: number | null
    permitIds: number[]
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
  /*
   * ── An amendment offers only what can be amended ────────────────────────
   *
   * A business has exactly one current business permit — verified against the
   * register — so asking WHICH permit was a question with one possible answer.
   * The client's decision, 19 September 2026: fold it into the business
   * option, "Pedro's Snack Bar — MCB-2026-000003", and drop the second control
   * entirely.
   *
   * Which means a business with no current permit cannot be chosen at all,
   * because there is nothing for the option to name and nothing to amend. Not
   * an error state: three of the five businesses on the register are exactly
   * this, each with an application still in flight.
   */
  const choosableBusinesses =
    applicationType === 'amendment'
      ? ownedBusinesses.filter((b) => b.current_business_permit)
      : ownedBusinesses
  const withheldBusinesses = ownedBusinesses.length - choosableBusinesses.length

  /**
   * How many permits each business could renew today.
   *
   * ── The dropdown knew nothing until something was picked ────────
   *
   * Permits were loaded only AFTER a business was chosen, so an owner with
   * fourteen shops and four things due had to open each one to find them.
   * Client, 3 October 2026, having just been told by the Home badge that
   * four were due: *"it is NOT APPARENT which to renew."*
   *
   * One call answers it for every business at once — `/permits` already
   * returns the owner's own rows with the business attached, which is the
   * same list the Home tile counts. Loaded once here rather than per
   * business, and it is the badge's own source, so the tile and this list
   * cannot disagree about what is due.
   *
   * `renewal_blocked_reason === null` is the server's answer to "may this
   * be renewed now", not a date this screen works out: a Mayor's Permit six
   * weeks from expiry is NOT due (it waits for January) and one that lapsed
   * in March IS (late, with a surcharge). `status === 'active'` drops the
   * superseded certificates the list also carries.
   */
  const { data: ownPermits } = useAsync(
    () =>
      applicationType === 'renewal'
        ? permitsApi.list({ per_page: 100 })
        : Promise.resolve([] as Permit[]),
    [applicationType],
  )
  const dueByBusiness = useMemo(() => {
    const counts = new Map<number, number>()
    for (const p of ownPermits ?? []) {
      if (p.status !== 'active' || (p.renewal_blocked_reason ?? null) !== null) continue
      const id = p.business?.id
      if (id === undefined) continue
      counts.set(id, (counts.get(id) ?? 0) + 1)
    }

    return counts
  }, [ownPermits])

  /*
   * Split, not sorted. Two headed groups say "these are the ones" in a way
   * an ordering cannot — a list that merely puts four at the top still
   * looks like one list.
   *
   * Both halves are offered. A business with nothing due is still a legal
   * filing: the window opens 30 days out, and an applicant who means to
   * check or who is early should not find their own shop missing — the
   * objection that keeps this chooser unfiltered in the first place.
   *
   * Only on a RENEWAL. An amendment has nothing to be due, and heading a
   * group "Nothing due" there would answer a question it never asked.
   */
  const groupByDue = applicationType === 'renewal' && dueByBusiness.size > 0
  const businessesDue = choosableBusinesses.filter((b) => (dueByBusiness.get(b.id) ?? 0) > 0)
  const businessesNotDue = choosableBusinesses.filter(
    (b) => (dueByBusiness.get(b.id) ?? 0) === 0,
  )
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

  /*
   * ── An amendment's permit follows its business, in the same render ──────
   *
   * One business holds one current business permit, and an amendment amends
   * that one, so there is nothing here for the applicant to choose and no
   * permit list is rendered for them.
   *
   * DERIVED, not state. Two earlier shapes both leaked:
   *
   *  - the dropdown's `onChange`, which fires only when somebody picks, so a
   *    REOPENED draft arrived with its business already set, nothing to fire,
   *    and an empty `permitIds` that made Confirm refuse with "Tick every
   *    permit you are amending" — pointing at a list an amendment does not
   *    render;
   *  - an effect, which fixed the reopened draft and bought a frame: the
   *    render between picking a business and the effect running had the
   *    business set and the permit not, so the dialog's refusal flashed up
   *    and vanished. Client, 21 September 2026: *"why does [it] appear before
   *    disappearing when I select a business."*
   *
   * A value computed during render cannot disagree with the business it is
   * computed from, and there is no frame in between for it to disagree in.
   */
  const amendmentPermit =
    applicationType === 'amendment'
      ? (ownedBusinesses.find((b) => b.id === businessId)?.current_business_permit ?? null)
      : null

  const effectivePermitIds =
    applicationType === 'amendment'
      ? amendmentPermit === null
        ? []
        : [amendmentPermit.id]
      : permitIds
  /*
   * Held and handed back untouched, never edited here any more.
   *
   * The dialog used to collect the amendment categories; it does not, so there
   * is no setter. It still has to CARRY the value, because `onConfirm` returns
   * a whole `FilingIdentity` and dropping the field would blank a renewal's
   * Section A answers every time the dialog is reopened to change something
   * else.
   */
  const [amendment] = useState<AmendmentState>(initial.amendment)
  const [prefill, setPrefill] = useState<PrefillResult | null>(null)
  const [loadingPermits, setLoadingPermits] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)

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

  /*
   * ── What this filing may be about ───────────────────────────────────────
   *
   * A renewal may cover any permit the business holds. An AMENDMENT may cover
   * exactly one — the business permit — because that is the only one whose
   * details the LGU lets anybody amend (client, 19 September 2026).
   *
   * Offering the five clearances here was the renewal picker showing verbatim
   * on the wrong filing: it listed certificates that cannot be amended, told
   * the applicant "no renewal needed" on a form that is not a renewal, and
   * invited them to tick three things the server refuses at submission.
   */
  const allPermits = prefill?.renewable_permits ?? []
  const permits =
    applicationType === 'amendment'
      ? allPermits.filter((p) => p.permit_type?.code === BUSINESS_PERMIT_CODE)
      : allPermits

  /*
   * ── One permit per filing, always ─────────────────────────────────────
   *
   * Client, 5 October 2026, on Zoning and the Mayor's Permit ticked
   * together: *"The applicant should not be allowed to renew multiple
   * permits at the same time."* Until then the Mayor's Permit opened the
   * list to several. It does not any more; `RenewalScope` on the API
   * refuses two of anything, and a clearance renewed separately while the
   * business permit bill is still open joins that bill
   * (`WorkflowService::foldIntoOpenBusinessPermitBill`).
   */

  /*
   * The permits this filing may actually carry, and the rest.
   *
   * Split on the SERVER's own refusal (`renewal_blocked_reason`) rather
   * than on a date computed here, so the list cannot offer a row the API
   * would reject — the window lives in `App\Support\RenewalWindow` and
   * this is the half of it the applicant can see.
   *
   * A row already TICKED stays in the top list whatever its reason says.
   * A draft left over a month-end can hold a permit whose window closed
   * under it, and moving that tick into a section headed "Not due yet"
   * would hide the one row blocking submission in the one place the
   * applicant is not looking.
   */
  const renewableNow = permits.filter(
    (p) => (p.renewal_blocked_reason ?? null) === null || permitIds.includes(p.id),
  )
  /*
   * The blocked permits, split by WHY — and they are opposite states.
   *
   * Both were drawn under one heading reading "Still valid — nothing to
   * do", which filed a certificate that lapsed in 2023 among the ones in
   * force and told the applicant it was fine. Caught by the client on a
   * screenshot, 3 October 2026.
   *
   * `renewal_blocked_reason` says a permit cannot be renewed TODAY and
   * says nothing about which side of its term that is — too early and too
   * late are one field, which is exactly why `RenewalWindow` returns the
   * sentence rather than a flag. The expiry date is what tells them
   * apart, so the split is on that and not on the wording.
   */
  const blockedRows = permits.filter(
    (p) => (p.renewal_blocked_reason ?? null) !== null && !permitIds.includes(p.id),
  )
  const notDueYet = blockedRows.filter((p) => (p.days_until_expiry ?? 0) >= 0)
  const noLongerRenewable = blockedRows.filter((p) => (p.days_until_expiry ?? 0) < 0)

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
   * The year-one paper escape is GONE (client, 18 September 2026 — no permit is
   * ever renewed on a manual system). What it used to let through was a filing
   * naming no permit at all, and there is now no such thing as a renewal of
   * nothing: a business with no BizTrack permit is told so above and pointed at
   * New Application, which is the filing that issues a first permit.
   *
   * So the gate is one condition again, and a stricter one than either version
   * before it: at least one permit ticked, always.
   */
  const answeredPriorPermit = effectivePermitIds.length > 0
  /*
   * A SENTENCE, not a sentence and a focus closure.
   *
   * Each reason used to carry a `focus()` that a press on the blocked dialog
   * would call. Continue is greyed now (`confirmDisabled`), so ProtoModal
   * never calls the handler and nothing could ever call those closures — dead
   * weight that reads as live behaviour.
   *
   * Non-null is what greys the button, and the string is what the button's
   * `aria-describedby` reads out. One value, two jobs, no way for them to
   * disagree about whether the dialog can go on.
   */
  const blocked: string | null =
    businessId === null
      ? `Choose the business you are ${verb} first.`
      : loadingPermits
        ? 'Still loading this business’s permits.'
        : !answeredPriorPermit
          ? applicationType === 'amendment'
            ? /*
               * An amendment has no permit list to point at, so it must not
               * borrow the renewal's wording. Only reachable if a business
               * that passed `choosableBusinesses` has since lost its current
               * permit; the sentence says that rather than asking for a tick
               * on a control the applicant cannot see.
               */
              'This business has no current business permit to amend.'
            : permits.length > 0
              ? /*
                 * A radio group since one permit is renewed per filing, so
                 * "tick every permit" asked for something the control cannot
                 * do. And when the server has refused every row, choosing is
                 * not the problem — say so (tester, 5 October 2026).
                 */
                permits.every((p) => (p.renewal_blocked_reason ?? null) !== null)
                ? 'Nothing here can be renewed today.'
                : `Choose the permit you are ${verb}.`
              : `This business has no permit to renew. File a New Application instead.`
          : /*
             * ── Nothing else is asked HERE ──────────────────────────────────
             *
             * Section A is not asked in this dialog any more — it is the
             * wizard's own step, after Data Privacy, because a No there ends
             * the form and a question that can end the form does not belong in
             * the dialog that opens it. This dialog asks one thing: which
             * permits, and on an amendment even that rides on the business.
             *
             * A gate demanding "tick at least one thing you are amending"
             * outlived the ticks themselves. It refused Confirm on every
             * amendment and put the cursor on a fieldset that was not on
             * screen, so the dialog could not be got past at all.
             *
             * What it protected is still asked, where the answer now lives:
             * the New Details step will not advance without one, and `submit`
             * refuses on an empty `requested_changes`.
             */
            null

  function confirm() {
    /*
     * ── The second door, not the first ────────────────────────────────
     *
     * Continue is `confirmDisabled` while `blocked`, and ProtoModal already
     * refuses to call this then — so in practice a blocked press never
     * arrives here. The guard stays anyway: the two are computed from the
     * same `blocked` and a future edit that loosens one must not be able to
     * let a half-answered filing through the other.
     *
     * What is GONE is the scolding. It set a flag that turned the reason
     * below red, and with the button greyed nothing can set it — a mechanism
     * no press can reach is worse than no mechanism, because the next reader
     * has to work out that it is dead. The reason is still on the button's
     * `aria-describedby`, which is how the greyed control explains itself.
     */
    if (confirming || blocked) return
    if (businessId === null || !prefill) return
    onConfirm({
      businessId,
      // Primary is the first tick; the set is the whole answer.
      permitId: effectivePermitIds[0] ?? null,
      permitIds: effectivePermitIds,
      amendment,
      prefill,
    })
  }

  return (
    <ProtoModal
      title={
        applicationType === 'renewal'
          ? 'WHICH PERMIT ARE YOU RENEWING?'
          : 'WHICH BUSINESS ARE YOU AMENDING?'
      }
      wide
      cancelLabel={mode === 'entry' ? 'Not now' : 'Keep what I had'}
      confirmLabel={confirming ? 'Opening…' : 'Continue'}
      confirmDescribedBy={blocked ? reasonId : undefined}
      /*
       * ── Greyed until the dialog can actually go on ────────────────────
       *
       * Client, 21 September 2026: *"Make the Continue not clickable until a
       * business is selected… grayed out or something. Refer to other modals
       * for same consistency with colors."*
       *
       * `confirmDisabled` is that, and it is the prop every other dialog in
       * the app already passes — Change Status, Transfer Ownership, Officer
       * Assignment, Add officer. ProtoModal renders it as `aria-disabled`
       * with `opacity-60` and a not-allowed cursor, so this dialog now looks
       * and behaves like the rest of them instead of offering a live button
       * that silently refuses.
       *
       * NOT the native `disabled` attribute — AGENTS.md §6.2. A disabled
       * button leaves the tab order, taking the one control whose
       * `aria-describedby` states the reason with it: a screen-reader user
       * would meet a dialog that had nothing to say about why it was stuck.
       * ProtoModal moves the guard into the click handler instead.
       *
       * Gated on `blocked` and not on `businessId` alone, so the button and
       * the sentence beneath it can never disagree. All three of its reasons
       * are real: no business, the prefill still in flight (which `confirm`
       * genuinely needs), or a business whose current permit has gone.
       */
      confirmDisabled={blocked !== null}
      onCancel={onCancel}
      onConfirm={confirm}
    >
      <p className="text-sm leading-relaxed text-ink-secondary">
        {applicationType === 'renewal'
          ? 'We fill in the rest of the form from it.'
          : 'We fill in the rest of the form from it.'}
      </p>

      {/* ── 1. Which business ────────────────────────────────────────────── */}
      <label className="mt-3 block">
        <FieldLabel required>Business</FieldLabel>
        <select
          aria-label={`Which business are you ${verb}?`}
          className={inputCls}
          value={businessId ?? ''}
          onChange={(e) => setBusinessId(e.target.value ? Number(e.target.value) : null)}
          // Momentary, not a field held shut by another answer: there is
          // nothing to choose between until the list has arrived.
          disabled={businessesLoading}
        >
          <option value="">
            {businessesLoading ? 'Loading your businesses…' : 'Select a business…'}
          </option>
          {groupByDue ? (
            <>
              {/*
                `optgroup`, not a styled list. It is native, so it survives
                a phone's own select UI and reads as a group to a screen
                reader — neither of which a div pretending to be a dropdown
                does without a great deal of work.
              */}
              {businessesDue.length > 0 && (
                <optgroup label="Due for renewal">
                  {businessesDue.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name} ({dueByBusiness.get(b.id)} due)
                    </option>
                  ))}
                </optgroup>
              )}
              {businessesNotDue.length > 0 && (
                <optgroup label="Nothing due">
                  {businessesNotDue.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name}
                    </option>
                  ))}
                </optgroup>
              )}
            </>
          ) : (
            choosableBusinesses.map((b) => (
              <option key={b.id} value={b.id}>
                {applicationType === 'amendment' && b.current_business_permit
                  ? `${b.name} — ${b.current_business_permit.permit_number}`
                  : b.name}
              </option>
            ))
          )}
        </select>
      </label>
      {!businessesLoading && ownedBusinesses.length === 0 && (
        <p className="mt-2 text-xs text-ink-secondary">
          You have no registered businesses yet. Start a new application instead.
        </p>
      )}
      {/*
        ── Say what was left out, rather than leaving it out quietly ──────────

        A business still applying for its first permit has nothing to amend, so
        it is not offered. But a business simply MISSING from a chooser tells
        the applicant nothing — the same objection that keeps the renewal
        chooser unfiltered — so the count and the reason are stated.

        This is not a data fault, and the wording avoids implying one: BizTrack
        creates the business record when somebody starts filing, so having no
        permit yet is what an in-progress application looks like.
      */}
      {!businessesLoading && applicationType === 'amendment' && withheldBusinesses > 0 && (
        <p className="mt-2 text-xs text-ink-secondary">
          {withheldBusinesses === 1
            ? 'One business is not listed — its permit is still being applied for.'
            : `${withheldBusinesses} businesses are not listed — their permits are still being applied for.`}
        </p>
      )}

      {/*
        ── 2. Which permit ────────────────────────────────────────────────────

        Renewals only. An amendment's permit is folded into the business option
        above — see `choosableBusinesses` — because a business has exactly one
        current business permit and a picker with one option in it is a
        question nobody needs to be asked.
      */}
      {businessId !== null && applicationType !== 'amendment' && (
        <div className="mt-3">
          {/* The list below carries the full question as its own name. */}
          <FieldLabel required>Permit</FieldLabel>
          {/*
            One bill, said where the ticking happens.

            Item #79: nothing in this picker said what a second tick costs, so
            the applicant had no way to know whether renewing three permits
            meant three trips to the cashier. It does not, and that is worth
            stating here rather than three screens later on PayPage, because
            the fear of a second bill is what makes someone untick a permit
            that is genuinely due.

            Why it is true, so a later reader can re-check it rather than
            trust this line: a tick lands in `priorPermitIds`, the effect above
            derives `form.permit_type_ids` from it, and
            `WorkflowService::assessFees()` then loops `$app->permitTypes`
            into a SINGLE `FeeAssessment` — one `total_amount`, written by an
            `updateOrCreate` keyed on `application_id` so a filing can only
            ever hold one assessment row. `PermitFees::balance()` reads that
            one row. There is no per-permit accrual anywhere in the path.

            It deliberately does not say "on your business permit renewal",
            which is how #79 phrased it. A renewal is of whichever permits are
            actually due (see the effect that derives `permit_type_ids`), so a
            shop renewing only its Sanitary Permit has no business permit on
            the filing at all and that sentence would be false for them. "This
            filing" is true in every case, including the paper-permit escape.

            It names no amount and no date on purpose — both belong to the Tax
            Order of Payment, which BPLO raises after reading the form, and
            neither is knowable here.

            If a second `FeeAssessment` row per permit is ever introduced, or
            an accrual returns the way `ClearanceService::reassess()` once
            worked, this sentence becomes a lie about money and must go with
            it.
          */}
          {/*
            Two sentences, and which one shows depends on the answer so far.

            The fee line earns its place for the reason item #79 gave: nothing
            in this picker said what a second tick costs, and the fear of a
            second bill is what makes someone untick a permit that is due.

            The other line is new on 3 October 2026 and has to come FIRST in
            the reader's day, not after: ticking a second clearance will drop
            the first, and a control that rearranges itself without warning is
            read as a bug. It is also why the rule is phrased as what to do —
            file them separately — rather than as what is forbidden.
          */}
          <p className="mb-2 text-xs text-ink-secondary">
            One permit per application. Renew the others separately.
          </p>
          {loadingPermits ? (
            <p className="text-xs text-ink-secondary">Loading this business’s permits…</p>
          ) : loadError ? (
            <p role="alert" className="text-xs font-medium text-s-red">
              {loadError} Try again — a renewal has to name the permit it renews.
            </p>
          ) : (
            // Guarded on the count, not just on `loadError`: without it a
            // business holding nothing drew an empty bordered box above the
            // note explaining that it holds nothing, which reads as a list that
            // failed to load rather than one with no rows to show.
            renewableNow.length > 0 && (
              <ul
                aria-label={`Which permit are you ${verb}?`}
                /*
                 * A radiogroup while one answer is allowed, a plain list
                 * once the Mayor's Permit has opened it to several. The
                 * inputs below switch with it.
                 */
                role="radiogroup"
                className="divide-y divide-line overflow-hidden rounded-lg border border-input-border bg-white"
              >
                {renewableNow.map((p) => {
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
                  /*
                   * ── What leaving this one UNTICKED means ──────────────────────
                   *
                   * Client's correction, 18 September 2026: *"there are cases that
                   * an other permit was not needing renewal even though the
                   * business permit is being renewed. This is because a business
                   * permit is renewed every January and other permits have 1 year
                   * validity, so an other permit can be reused for a business
                   * permit renewal as long as this other permit is still
                   * valid/not expired."*
                   *
                   * So an unticked permit is not a gap in the answer — it is the
                   * ORDINARY answer, and the commonest one. A January renewal of
                   * the business permit leaves a Sanitary Permit good until June
                   * untouched, and BizTrack already holds that certificate.
                   *
                   * This replaces the "None of these — my permit was issued on
                   * paper" checkbox that used to sit under this list. That escape
                   * was built for a case the client has since ruled out — no
                   * permit is ever renewed on a manual system — so it asked for a
                   * click to declare something that cannot happen, while the thing
                   * that DOES happen went unsaid. Saying it per row costs no click
                   * at all and names the actual permit it is about.
                   *
                   * Only on the unticked rows. A ticked permit is being renewed
                   * and needs no explanation; repeating the validity there would
                   * be noise on the answer the applicant just gave.
                   */
                  /*
                   * The server's own refusal, and it outranks everything
                   * below — including the "Expired — tick it to renew"
                   * line, which for a permit past the window is an
                   * invitation to fill in a form that will be rejected on
                   * its last screen.
                   *
                   * Shown on a ticked row too, unlike the notes below. A
                   * permit can only be ticked here if it was ticked before
                   * the window closed under it — a draft left over a
                   * month-end — and hiding the reason on exactly the rows
                   * that block submission is the worst place to hide it.
                   */
                  const blockedReason = p.renewal_blocked_reason ?? null

                  const reason =
                    blockedReason !== null
                      ? { text: blockedReason, cls: 'text-s-red font-semibold' }
                      : chosen || days === null || days >= 0
                        ? null
                        : // Lapsed and still renewable, so it says both (5 October 2026).
                          { text: 'Expired — tick it to renew.', cls: 'text-s-red' }
                  return (
                    // Presentational so the radios are the radiogroup's own
                    // children, not list items wrapping them.
                    <li key={p.id}>
                      <label
                        className={`flex w-full items-center gap-3 px-4 py-3 text-left transition-colors ${
                          blockedReason !== null
                            ? 'cursor-not-allowed opacity-60'
                            : chosen
                              ? 'cursor-pointer bg-input'
                              : 'cursor-pointer hover:bg-royal-tint'
                        }`}
                      >
                        <input
                          /*
                           * One answer or several — see the note on the
                           * list above. A radio in a group named by the
                           * same `aria-label`, so the two never disagree.
                           */
                          type="radio"
                          name="renewal-permit"
                          /*
                           * Disabled, not hidden. A permit the business
                           * holds and cannot renew is a fact the applicant
                           * needs — dropping the row would leave them
                           * hunting for a certificate they can see in their
                           * own permit list, with no explanation anywhere.
                           */
                          disabled={blockedReason !== null}
                          checked={chosen && blockedReason === null}
                          onChange={() => {
                            /*
                             * Appended, never inserted: the first tick is the
                             * primary and the renewal chain is keyed on it, so
                             * the order the applicant ticked in IS the answer.
                             * Untick-and-retick is how you change which is
                             * primary, which is the only honest way to say it
                             * without a second control asking the same thing.
                             *
                             * ── One filing, or one other permit ───────────
                             *
                             * Client, 3 October 2026, on the other permits
                             * being independent of each other. Ticking a
                             * second one with no Mayor's Permit in the set
                             * REPLACES the first rather than refusing it:
                             * the applicant is telling us which permit they
                             * mean, and answering a changed mind with an
                             * error is answering it with an obstacle. The
                             * note under the list says it will happen before
                             * it does.
                             *
                             * `RenewalScope` on the API refuses the set this
                             * cannot produce, on both endpoints that write
                             * it — the UI keeping a shape off the screen is
                             * not the same as the server refusing it.
                             */
                            setPermitIds([p.id])
                          }}
                          className="h-4 w-4 shrink-0 accent-royal"
                        />
                        {/*
                         * ── The NAME leads, the number identifies ───────────
                         *
                         * Name, number and validity dates all stay, because
                         * one of them alone does not tell two permits apart:
                         * a shop renewing late can hold last year's Mayor's
                         * Permit and this year's — same type, different
                         * dates, different numbers.
                         *
                         * But the number led, and that is the wrong way round
                         * for the question being asked. "MCS-2026-000001" in
                         * bold above "Sanitary Permit" in grey makes the
                         * applicant read a reference code to find out which
                         * permit it is, on a list where the whole task is
                         * picking the ones they mean. Client, 21 September
                         * 2026: *"Make sure the title of the permit is at the
                         * top to make it more prominent, not the ID."*
                         *
                         * The number keeps its tabular figures — it is what
                         * distinguishes two permits of the same type, so it
                         * has to stay scannable down the column.
                         */}
                        <span className="min-w-0 flex-1">
                          <span className="block text-sm font-semibold text-ink">
                            {p.permit_type?.name ?? 'Permit'}
                          </span>
                          <span className="block text-xs text-ink-secondary">
                            <span className="tnum">{p.permit_number}</span> · {permitValidity(p)}
                          </span>
                          {reason && (
                            <span className={`mt-1 block text-xs ${reason.cls}`}>
                              {reason.text}
                            </span>
                          )}
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
              </ul>
            )
          )}

          {/*
            ── The permits this filing may NOT carry yet ───────────

            Shown, and not as rows. Client, 3 October 2026, on five of
            these drawn as blocked checkboxes above the one that was
            pickable: *"so much texts appear."* They each carried a
            paragraph naming the permit and restating its dates, both of
            which the row above already printed.

            Kept rather than filtered away, because a permit the applicant
            can see in My Permits and cannot find here reads as lost — the
            same objection the amendment chooser answers with a count and a
            reason instead of a silent filter.

            But not as part of the QUESTION. "Which permits are you
            renewing" takes its answer from the permits that are due; one
            that is not is context, and one line of it is enough: the name,
            and the date it can be renewed from, which is the only fact the
            applicant cannot work out for themselves.
          */}
          {/*
            ── Stacked, not two columns ────────────────────────

            The name sat left and the dates were pushed right, so every row
            was a short label against a long run of text with a gap between
            them, and the one row whose sentence was longer wrapped while
            the rest did not. Client, 3 October 2026: *"Text is too
            compacted. Adjust the layout."*

            The name leads its own line and the dates sit under it. Both
            read left to right from the same edge, a longer sentence makes
            the row taller instead of crushing the column beside it, and it
            is the shape the pickable rows above already use.
          */}
          {notDueYet.length > 0 && (
            <div className="mt-3">
              {/*
                ── The heading says the GOOD news first ──────────────

                It read "Not due yet", which states the one thing these
                permits cannot do and never says they are fine. Client,
                3 October 2026: *"Can you put something in them that
                explains clearly that they are still valid."*

                A list of six certificates under a negative heading, each
                line naming a future date, reads as six problems. Every one
                of them is a permit in force that the applicant need do
                nothing about, and that is the message.
              */}
              <h4 className="text-xs font-bold uppercase tracking-wide text-ink-secondary">
                Still valid — nothing to do
              </h4>
              <ul className="mt-1 divide-y divide-line rounded-lg border border-line bg-line/20">
                {notDueYet.map((p) => (
                  <li key={p.id} className="px-3 py-2">
                    <span className="block text-xs font-semibold text-ink-secondary">
                      {p.permit_type?.name ?? 'Permit'}
                    </span>
                    {/*
                      Two facts, in the order they are wanted: how long the
                      permit covers them, then when they may renew it.

                      The validity was dropped when these rows were
                      compacted, on the reasoning that the renewal date was
                      the only thing the applicant could not work out. That
                      was wrong in one direction: they could not work out
                      the expiry either, because this group is the one place
                      the dates are no longer printed.
                    */}
                    <span className="mt-0.5 block text-xs text-ink-muted">
                      {p.valid_until && (
                        <span className="font-medium text-ink-secondary">
                          Valid to {formatDate(p.valid_until)}
                        </span>
                      )}
                      {p.valid_until ? ' · ' : ''}
                      {/* The server's own sentence, already short — see RenewalWindow. */}
                      {p.renewal_blocked_reason}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/*
            ── Lapsed past the cap: a different answer, under its own heading ─

            These were drawn above, among the permits in force, under a
            heading promising there was nothing to do. There is: the term
            ran out more than 36 months ago (`RenewalWindow`), renewal no
            longer deters anything past Sec. 8A.05's interest cap, and what
            the business needs is a New Application with its own inspection.

            Red, and said in the row rather than only in the sentence: a
            certificate that lapsed years ago is the one thing on this
            screen the applicant most needs to notice, and it had been
            filed under reassurance.
          */}
          {noLongerRenewable.length > 0 && (
            <div className="mt-3">
              <h4 className="text-xs font-bold uppercase tracking-wide text-s-red">
                Lapsed — cannot be renewed
              </h4>
              <ul className="mt-1 divide-y divide-s-red/20 rounded-lg border border-s-red/40 bg-s-red/5">
                {noLongerRenewable.map((p) => (
                  <li key={p.id} className="px-3 py-2">
                    <span className="block text-xs font-semibold text-ink">
                      {p.permit_type?.name ?? 'Permit'}
                    </span>
                    <span className="mt-0.5 block text-xs text-s-red">
                      {p.renewal_blocked_reason}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/*
           * ── A business with nothing to renew ──────────────────────────────
           *
           * Not an escape hatch, which is what used to sit here. With the paper
           * case ruled out (client, 18 September 2026) a business holding no
           * BizTrack permit has nothing to carry forward, and a "renewal" of
           * nothing is a NEW application that took a wrong turn at the front
           * door. One of those is in the register already.
           *
           * So it is told, plainly, with the way out — rather than offered a
           * tick that files a renewal referring to no permit and leaves BPLO to
           * work out what happened.
           */}
          {!loadingPermits && !loadError && permits.length === 0 && (
            <div className="rounded-lg border border-input-border bg-royal-tint/40 p-3">
              <p className="text-[13px] font-semibold text-ink">
                This business has no permit to renew yet.
              </p>
              <p className="mt-1 text-xs leading-relaxed text-ink-secondary">
                A renewal carries forward a permit BizTrack issued, and there is none on this
                business. If it is trading for the first time, file a New Application instead — that
                is the one that issues the first permit.
              </p>
            </div>
          )}
        </div>
      )}

      {/*
        ── What is being amended is asked on the FORM, not here ─────────────

        This dialog carried the four category ticks (Ownership, Location,
        Nature of Business, Others) and the structure-conversion pair. They are
        gone — the client, 19 September 2026: *"Wouldn't it be better if these
        choices are part of the form itself, not placed in this modal?"*

        They were the THIRD copy of one question. Section A on the amendment
        step was the second, and both were made redundant by the New Details
        block, where filling in a value IS saying what you are amending — in
        the LGU's own vocabulary rather than four categories that never lined
        up with it.

        So for an amendment this dialog now asks exactly one thing: which
        business. Its current permit rides on the option, because there is only
        ever one. Everything else belongs to the form.
      */}

      {/*
       * Tied to Confirm by aria-describedby, so tabbing onto the greyed
       * button reads out what is still missing. A stable description rather
       * than a live `role="alert"`, so it is not re-announced on every
       * keystroke.
       */}
      {/*
       * The greyed button's explanation, for the reader who cannot see that
       * it is greyed.
       *
       * `sr-only` always, never red. A disabled-looking control with no
       * stated reason is WCAG 3.3.1/3.3.3, and `aria-describedby` on the
       * button is what answers it — this paragraph exists to be that
       * description. Sighted users get the required marker on the field, the
       * empty select and the dimmed button, which is what every other dialog
       * in the app gives them.
       */}
      {blocked && (
        <p id={reasonId} className="sr-only">
          {blocked}
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
  const [applicationType, setApplicationType] = useState<ApplicationType>(
    rawType === 'renewal' || rawType === 'amendment' ? rawType : 'new',
  )
  const typeMeta = TYPE_META[applicationType]

  const isReuse = applicationType === 'renewal' || applicationType === 'amendment'

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

    /*
     * ── The business name alone, once there is one ───────────────────
     *
     * It read "2026 Renewal — Pedro's Snack Bar" until 29 September 2026.
     * The prefix named the KIND of filing, which earned its place while a
     * drafts card showed nothing else — three cards saying "Pedro's Snack
     * Bar" gave no clue which was the renewal.
     *
     * The card carries a type label of its own now, so the prefix is the
     * same fact twice; and it was the half that survived truncation,
     * pushing the words that actually tell two drafts apart off the end.
     *
     * A suggestion, not a rule: `titleEdited` stops this replacing a name
     * the applicant has typed, exactly as before.
     */
    if (business !== '') {
      return business
    }

    /* Nothing to name it after yet — say what kind of thing it is. */
    const year = new Date().getFullYear()
    switch (applicationType) {
      case 'renewal':
        return `${year} Renewal`
      case 'amendment':
        return `${year} Amendment`
      default:
        return 'New Business Permit'
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
  /*
   * Item 7 — what the address suggested, and whether the applicant has since
   * overruled it.
   *
   * `autoPinned` is the whole of the "still allow the user to manually change
   * the pin" half of the item. A suggestion may replace an earlier SUGGESTION
   * as the address is corrected, and must never replace a pin the applicant
   * placed themselves: somebody who dropped a pin on their actual gate and then
   * fixed a typo in the street name would otherwise watch it jump back to the
   * middle of the road. Any click or drag clears the flag, and nothing puts it
   * back except another lookup.
   *
   * Kept as state rather than a ref because the caption under the map reads it.
   */
  const [autoPinned, setAutoPinned] = useState<string | null>(null)
  /*
   * Where the map suggests the applicant START when their address could not be
   * found: the centre of the barangay they chose. Never a pin — it is held here
   * and not in `form`, so it is never saved, never sent to Location Insights,
   * and never satisfies "A pin on the map". It becomes a pin only when the
   * applicant drags it, clicks it, or clicks the map (MapPicker's `startAt`).
   */
  const [startPoint, setStartPoint] = useState<{
    latitude: number
    longitude: number
    barangay: string
  } | null>(null)
  /*
   * ── The pin suggests the address text (the reverse of item 7) ─────────────
   *
   * When the applicant places or moves the pin by hand, OSM is asked what is
   * there, and the answer is written into House/Bldg. No. and Street as a
   * starting point. Context, not truth: OSM holds Malabon's roads and almost
   * none of its house numbers, so usually only the street arrives.
   *
   * `autoFilled` is what we last WROTE into each box (null where we wrote
   * nothing). A box is filled only if it is empty or still holds that value,
   * so anything the applicant typed is never overwritten. The barangay select
   * is deliberately not touched: the barangay is chosen first and the pin
   * checked against it, not the other way round, and the mismatch message
   * already handles the two disagreeing.
   *
   * No loop with the address-to-pin lookup above: that effect stands down once
   * a pin exists that it did not place (`autoPinned === null`), which is
   * exactly the state a hand-placed pin leaves behind, so a street written in
   * here never re-pins anything.
   */
  const [autoFilled, setAutoFilled] = useState<{
    house_bldg_no: string | null
    street: string | null
  } | null>(null)
  const reverseAbortRef = useRef<AbortController | null>(null)
  // The latest committed form, for a lookup that resolves after it was asked.
  const formRef = useRef(form)
  const autoFilledRef = useRef(autoFilled)
  useEffect(() => {
    formRef.current = form
    autoFilledRef.current = autoFilled
  })
  useEffect(() => () => reverseAbortRef.current?.abort(), [])

  function fillAddressFromPin(latitude: number, longitude: number) {
    // A newer pin supersedes an older question, answered or not.
    reverseAbortRef.current?.abort()
    const controller = new AbortController()
    reverseAbortRef.current = controller
    void reverseGeocode(latitude, longitude, controller.signal).then((hit) => {
      // Silent on every failure: the boxes simply stay as they were.
      if (hit === null || controller.signal.aborted) return
      const current = formRef.current
      const prev = autoFilledRef.current
      const ours = (key: 'house_bldg_no' | 'street') =>
        current[key].trim() === '' || (prev?.[key] != null && current[key] === prev[key])
      const next = {
        house_bldg_no: ours('house_bldg_no') ? hit.houseNumber : null,
        street: ours('street') ? hit.street : null,
      }
      /*
       * A box we filled earlier is emptied when the new point has nothing for
       * it, so an old pin's house number does not ride along to a new street.
       * A box the applicant typed in is left alone either way.
       */
      const writes: Partial<Pick<FormState, 'house_bldg_no' | 'street'>> = {}
      for (const key of ['house_bldg_no', 'street'] as const) {
        const value = hit[key === 'street' ? 'street' : 'houseNumber'] ?? ''
        if (ours(key) && current[key] !== value) writes[key] = value
      }
      if (Object.keys(writes).length > 0) setForm((f) => ({ ...f, ...writes }))
      setAutoFilled(next.house_bldg_no === null && next.street === null ? null : next)
    })
  }
  /*
   * The "filled in from your pin" note shows while every box we filled still
   * holds what we put there, and goes the moment the applicant edits one.
   */
  const autoFillNoteShown =
    autoFilled !== null &&
    (autoFilled.house_bldg_no === null || form.house_bldg_no === autoFilled.house_bldg_no) &&
    (autoFilled.street === null || form.street === autoFilled.street)

  const [saving, setSaving] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)
  /*
   * Submit was refused because the owner's address is not confirmed yet
   * [checklist 2026-09-27, Register 1]. Only possible while the API has a real
   * mailer; the code box replaces the error, and confirming files straight
   * away, because "Yes, submit" was already pressed.
   */
  const [needsEmailCode, setNeedsEmailCode] = useState(false)
  /* Autosave bookkeeping — see the autosave effect below. */
  const [dirty, setDirty] = useState(false)
  /**
   * The snapshot the SCRATCH draft on the server currently holds.
   *
   * `dirty` above only ever knew about the real `applications` draft, so the
   * indicator spoke only for that one. Before a real draft exists the wizard
   * still saves everything to a `wizardDrafts` row — it is how the filing
   * reaches the Drafts list — and the applicant was told "Not saved yet"
   * while their answers sat safely on the server and visibly in that list
   * (client, 4 October 2026: *"this part seems misleading"*).
   *
   * Null until the first scratch write lands. Compared against `snapshot`
   * rather than kept as a boolean, because "there is a row" and "that row has
   * what is on screen" are different claims and only the second one may say
   * All Changes Saved.
   */
  const [scratchSavedSnapshot, setScratchSavedSnapshot] = useState<string | null>(null)
  const [autosaveNonce, setAutosaveNonce] = useState(0)
  const savedSnapshotRef = useRef<string | null>(null)

  /*
   * ── The pre-draft backup ──────────────────────────────────────────
   *
   * Everything typed before a server draft can legally exist. See the
   * note on `canCreateDraft`: until street, barangay and a line of
   * business are answered on step 2, the API will not take the business,
   * so step 1 lives nowhere else.
   *
   * Per tab and gone with it. Every read and write is wrapped, because
   * sessionStorage THROWS rather than returning null in a locked-down
   * browser, and a form that will not open is a worse failure than a
   * form that does not remember.
   */
  const backupRestoredRef = useRef(false)
  /*
   * The form as it was OPENED, so an untouched one saves nothing.
   *
   * There is one scratch row per applicant per form, so without this,
   * merely visiting a blank New Business Permit would write the empty form
   * over the answers already saved there — opening a page would destroy
   * work. Null until the first run of the save effect records it.
   */
  const openedSnapshotRef = useRef<string | null>(null)
  /*
   * Has the restore below had its turn AND landed?
   *
   * State rather than a ref, and that is the whole point of it. Effects run in
   * declaration order within one commit, so a ref set by the restore effect is
   * already true when the backup effect runs in that same commit — with the
   * form still EMPTY, because the restore's setState has not rendered yet.
   * Gating on state defers the first write to the commit AFTER the restored
   * answers are actually in the form.
   */
  const [restoreSettled, setRestoreSettled] = useState(false)

  function readBackup(): DraftBackup | null {
    try {
      const raw = sessionStorage.getItem(draftBackupKey(applicationType))
      if (!raw) return null
      const parsed = JSON.parse(raw) as DraftBackup
      // A backup written by an older build describes a different form.
      if (parsed.v !== DRAFT_BACKUP_VERSION) return null

      /*
       * And one written for a different KIND of filing describes a
       * different set of questions. The key is per type now, so this can
       * only fire on a slot left by the single-key build — but restoring a
       * new permit's answers into a renewal would put a form on screen
       * that no dialog had ever been asked about.
       */
      return parsed.applicationType === applicationType ? parsed : null
    } catch {
      return null
    }
  }

  function clearBackup(): void {
    try {
      sessionStorage.removeItem(draftBackupKey(applicationType))
    } catch {
      /* Nothing to do, and nothing worth telling the applicant. */
    }
  }
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
   * A prior permit is now the ONLY answer, so "answered" is just "named".
   *
   * This used to be `priorPermitId !== null || priorPermitDeclaredNone`, the
   * second half being the applicant ticking "this business has no BizTrack
   * permit". The client retired that case on 18 September 2026 — no permit is
   * ever renewed on a manual system — so a renewal that names nothing is not an
   * escape any more, it is an unanswered question, and the dialog will not
   * confirm until a permit is ticked.
   *
   * Kept as a named boolean rather than inlined at its four call sites: it is
   * the same question in all four, and the last time it was two conditions they
   * did not agree.
   */
  const priorPermitAnswered = priorPermitId !== null
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

  // OCR-lite suggestion banner (v2) — dismissible; suggestions only.
  const [ocr, setOcr] = useState<OcrSuggestions | null>(null)

  /*
   * Owner's existing businesses (only needed to seed renewal/amendment).
   *
   * Deliberately NOT filtered to businesses holding a permit, though since
   * 18 September 2026 only those can actually be renewed (the paper escape is
   * gone — a business with no BizTrack permit files a New Application).
   *
   * Still unfiltered, because the reason changed rather than disappearing. A
   * business missing from this list tells the applicant nothing; a business
   * they CAN pick, which then says "no permit to renew — file a New
   * Application instead", tells them what went wrong and where to go. Filtering
   * moves the dead end earlier and makes it silent.
   *
   * The list is bounded (PICKER_PAGE_SIZE) and the API now orders permit
   * holders first, so the businesses that can be renewed surface at the top of
   * a long list instead of falling off the end of it. See
   * BusinessController::index for the defect that ordering fixes.
   */
  /*
   * Loaded on EVERY filing type, not only a renewal.
   *
   * It was `isReuse ? businesses.list() : []`, because the only reader was the
   * entry dialog's business picker and a new filing has no business to pick.
   * The registration-number notice below is the second reader and it needs the
   * list precisely when the old condition withheld it: a NEW filing is when an
   * applicant most easily registers a second business against a certificate
   * they already hold, having forgotten the first.
   *
   * One request for the caller's own businesses — the list they are allowed to
   * see and the screen already renders elsewhere.
   */
  const ownedBusinesses = useAsync<Business[]>(() => businesses.list(), [])
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
  /*
   * The same value, readable from inside an awaited callback.
   *
   * The debounced scratch save closes over `applicationId` as it stood when
   * the timer was set, and a real draft can appear during the await — see the
   * discard in that save for what that cost. A ref is the only way to ask
   * "has one arrived since?" from in there.
   */
  const applicationIdRef = useRef<number | null>(null)
  /**
   * Set the id and the ref together, synchronously.
   *
   * The ref was kept in step by a `useEffect`, which runs AFTER the render
   * that set the state — so between `setApplicationId` and that effect the
   * ref still read null. A scratch create resolving inside that window read
   * the stale null, concluded no real draft existed, and stored its id; the
   * discard effect had already run and found nothing. The duplicate card
   * this was written to stop came straight back, on amendments.
   *
   * A ref exists precisely so a value can be read without waiting for a
   * render. Updating it through an effect gave away the one property that
   * made it the right tool.
   */
  const rememberApplicationId = (id: number) => {
    applicationIdRef.current = id
    setApplicationId(id)
  }
  /**
   * The wizard steps a returned filing is allowed to show.
   *
   * Empty for every filing that was not returned about a section, which is
   * almost all of them — and empty therefore means "no restriction", not
   * "no steps". See the `sequence` memo.
   */
  /**
   * The wizard steps a returned filing is allowed to show.
   *
   * Empty for a draft and for a return that named only scalar fields — those
   * are corrected on the status page and never open this wizard. Empty
   * therefore means NO RESTRICTION, not "no steps"; see the `sequence` memo.
   */
  const [returnedPhases, setReturnedPhases] = useState<string[]>([])
  /**
   * The filing's own date, for FO-003's "Date of Application" line.
   *
   * ── Why not `new Date()` ──────────────────────────────────────────────
   *
   * Because that is not a date, it is a clock. The header printed
   * `formatDate(new Date())`, recomputed on every render, so a draft started
   * today and reopened next week would have said next week — and the line it
   * fills in is the one the LGU dates the filing by. Client, 21 September
   * 2026: *"is this dynamically changing? If I continue my draft tomorrow,
   * would the date be adjusted to Sept. 22?"* It would have.
   *
   * `submitted_at` once the filing is in, `created_at` before that — the day
   * it was begun. Both come off the row, so neither moves when the page is
   * reopened, and the one that is shown is the one the office would use.
   *
   * Null only before the draft exists at all, where there is no filing to
   * date and today is the honest answer: nothing can reopen it to see the
   * answer drift.
   */
  const [filedAt, setFiledAt] = useState<string | null>(null)
  /*
   * ── The amendable details, and what this filing asks of them ─────────────
   *
   * Every amendable detail comes back, not just the ones asked about, because
   * the form is "what it is now, what you want it to be" — showing only the new
   * values asks somebody to remember what they are replacing.
   *
   * `amendRows` is the server's answer and `amendTyped` is what is in the
   * boxes. Two pieces of state rather than one, so a field mid-edit is never
   * overwritten by a reload, and so saving can tell an untouched field from one
   * deliberately cleared.
   */
  /**
   * The per-filing half of the amendment rows, keyed by field.
   *
   * ── Why this is separate from the definitions ─────────────────────────
   *
   * The four boxes, their labels and their controls are the same for every
   * business, so they arrive with the reference data before the wizard paints
   * (`refs.data.amendableFields`). What is NOT the same is the register's
   * current value and what this draft has already asked for, and those need
   * an application id — which on a first visit does not exist until a draft
   * has been POSTed.
   *
   * Held apart so the second never blocks the first. Sent together, the step
   * could not draw a single box until two round trips had finished, and a
   * slow answer looked like a broken form. Client, 21 September 2026: *"Why
   * it still loads? Can't you make it appear instantly, just like in the
   * other forms?"*
   */
  const [amendValues, setAmendValues] = useState<Record<string, AmendmentRow>>({})
  const [amendTyped, setAmendTyped] = useState<Record<string, string>>({})
  const [amendBusy, setAmendBusy] = useState(false)
  /*
   * Why a pin was refused, if it was.
   *
   * Separate from `amendError`, which is the server talking. This one never
   * reaches the server: a pin outside Malabon is not a request that failed,
   * it is a request that was never made.
   */
  const [amendPinError, setAmendPinError] = useState<string | null>(null)

  /*
   * ── CPDD's sheet, as the clearance stage keeps it ─────────────────────
   *
   * The amendment's Zoning step is the real MCG-CPDD-FO-003, not a reading
   * of it: the applicant fills it in and attaches its documents here, the
   * notarised Applicant Declaration included. That needs the same four
   * pieces of state ClearanceStagePage holds — the answers, the checklist,
   * which row is busy, and what went wrong — because it is the same sheet
   * talking to the same endpoints.
   */
  const [officeData, setOfficeData] = useState<Record<string, OfficeFormData>>({})
  const [officeReqs, setOfficeReqs] = useState<Record<string, OfficeFormRequirement[]>>({})
  const [officeReqBusy, setOfficeReqBusy] = useState<string | null>(null)
  const [officeError, setOfficeError] = useState<string | null>(null)
  const [amendError, setAmendError] = useState<string | null>(null)
  /**
   * Whether the amendable details are still on their way.
   *
   * ── Why an empty step is not an honest empty step ─────────────────────
   *
   * These rows are fetched, and until they land `amendRows` is `[]` — which
   * renders as nothing at all: no boxes, no headings, no message. A slow
   * response is then indistinguishable from a broken form.
   *
   * It happened. On 21 September 2026 the API took 43 seconds to answer
   * (single-threaded dev server, a test suite running against it), and the
   * client's reaction to the screen was *"What in the world is happening?
   * Where are the fields?"* — which is the correct reaction to a form with no
   * fields and nothing to say for itself.
   *
   * Starts TRUE, because the first paint happens before the effect has run
   * and "loading" is the truth at that moment. Starting false would flash the
   * empty state for one frame on every arrival.
   */
  const [amendLoading, setAmendLoading] = useState(true)
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

  /*
   * ── The whole form, on Review ─────────────────────────────────────────────
   *
   * Review draws Location & Zoning through Documentary Requirements at once,
   * editable, so the applicant can check and correct everything before they
   * commit. `reviewAll` is what tells each <WizardSection> to draw itself out
   * of turn; `sectionOpen` is which of them are expanded.
   *
   * All four open to begin with. Collapsed by default the review would add
   * nothing over the step nav the applicant already has and most would never
   * open it — and being able to review before submitting is the entire point
   * of the screen.
   */
  const [sectionOpen, setSectionOpen] = useState<Record<ReviewSectionName, boolean>>({
    address: true,
    business: true,
    operation: true,
    documents: true,
  })
  const toggleSection = (name: ReviewSectionName) =>
    setSectionOpen((s) => ({ ...s, [name]: !s[name] }))
  /*
   * `allSectionsOpen` stood here and asked all four, which is the wrong
   * question for a filing that draws fewer — see `allReviewSectionsOpen`,
   * declared once `sequence` is known.
   */
  const setAllSections = (open: boolean) =>
    setSectionOpen({
      address: open,
      business: open,
      operation: open,
      documents: open,
    })

  /*
   * Which sections have been opened for editing. A summary is what the review
   * shows; the real form is the exception, one section at a time, so the page
   * does not quietly become the form again the moment somebody fixes a typo.
   */
  const [sectionEditing, setSectionEditing] = useState<Record<ReviewSectionName, boolean>>({
    address: false,
    business: false,
    operation: false,
    documents: false,
  })

  /**
   * Open a section for editing, and put the applicant where they pressed.
   *
   * Focus has to wait for the field to EXIST: the form sits behind `hidden`
   * until this state lands, and `focus()` on a hidden element does nothing at
   * all — silently, which is the kind of dead control nobody reports. One
   * frame after paint is enough.
   *
   * ── Why the row's own field is not focused yet ────────────────────────────
   *
   * `ReviewAnswer.focusId` exists and nothing sets it. Every id the summary
   * first named — `line1`, `barangay_id`, `floor_area_sqm` and the rest — was
   * one I assumed rather than checked, and not one of them is in the DOM;
   * `NumberField` does not even take an `id`. Eight Change buttons that opened
   * a section and focused nothing would have been exactly the silent dead
   * control this comment warns about, so they were removed rather than
   * shipped.
   *
   * The fallback is the section body, which is real: keyboard focus lands
   * inside the form the applicant asked to edit, at its first field, rather
   * than staying on a Change button that has just been hidden. Giving the
   * individual fields stable ids is the follow-up that makes per-row focus
   * work; the plumbing is here waiting for it.
   */
  const editSection = (name: ReviewSectionName, focusId?: string) => {
    const collapsing = sectionEditing[name] && focusId === undefined
    setSectionEditing((s) => ({ ...s, [name]: !collapsing }))
    if (collapsing) return

    requestAnimationFrame(() => {
      const target =
        (focusId ? document.getElementById(focusId) : null) ??
        document.getElementById(`review-body-${name}`)
      if (!(target instanceof HTMLElement)) return

      // The body is a div, so move focus to the first thing in it that takes
      // focus; falling back to the body itself would trap a keyboard user.
      const focusable = target.querySelector<HTMLElement>(
        'input:not([type=hidden]):not([disabled]), select, textarea, button',
      )
      ;(focusable ?? target).focus()
      target.scrollIntoView({ block: 'start', behavior: 'smooth' })
    })
  }
  const [showConfirm, setShowConfirm] = useState(false)
  const [consent, setConsent] = useState(false)
  /*
   * The answers City Ordinance No. 24-2018 needs that nothing else on the
   * form asks: whether the business is run from a home, how far it is from
   * the nearest school, whether the lot is beside a creek. Asked inside the
   * zoning checklist, under the rule that needs each one, never as a block of
   * their own. Saved with the draft like the consent tick, for the same reason
   * that one is: an answer that is not watched by `snapshot` is never saved.
   */
  const [zoningFacts, setZoningFacts] = useState<ZoningFactValues>({})
  /*
   * Defaults to annual, which is both the Code's ordinary case and what the
   * server has always written when the key is absent. A renewal that never
   * reaches the picker therefore records what it would have recorded before
   * this existed.
   */
  const [paymentMode, setPaymentMode] = useState<PaymentMode>('annual')

  /*
   * ── All five at once, not one after another ────────────────────
   *
   * This was an object literal of five `await`s, which JavaScript
   * evaluates in order: every call waited for the one before it, so the
   * wizard's skeleton stayed on screen for the SUM of five round trips
   * when none of them depends on another.
   *
   * Found on 4 October 2026 while chasing something else. Against the
   * E2E stack — `php artisan serve`, one request at a time — the five
   * took over a minute end to end, and `renewal-modal.spec.ts` had been
   * reported as a BROKEN renewal path on that evidence. It was not
   * broken; it was slow, and the specs' 30-second wait expired before
   * the dialog could paint. A wrong diagnosis is the second thing this
   * cost, and the reason the note is this long.
   *
   * `Promise.all` is the whole fix. It helps a real deployment most,
   * where the five are served concurrently and the wait becomes the
   * slowest one rather than their total — and it still helps against a
   * single-process server, which no longer sits idle between them.
   *
   * `Promise.all` and not `allSettled`: the wizard cannot be drawn
   * without any one of these, so a partial answer is not a lesser
   * success. The first rejection is the error `useAsync` reports, which
   * is what the sequential version did too.
   */
  const refs = useAsync(async () => {
    const [barangays, psic, permitTypes, documentTypes, amendableFields] = await Promise.all([
      reference.barangays(),
      reference.psicCodes(),
      reference.permitTypes(),
      reference.documentTypes(),
      /*
       * The amendment form's four boxes. Here rather than on the step so
       * it is in hand before the wizard paints — see the endpoint's note
       * on why the definitions stopped travelling with the values.
       */
      reference.amendableFields(),
    ])

    return { barangays, psic, permitTypes, documentTypes, amendableFields }
  }, [])

  const draftIdParam = searchParams.get('draft')
  /*
   * Did the applicant ask to pick an unfinished filing back up?
   *
   * Only a Drafts card sets this. The dashboard's New Business Permit
   * card links to `/apply?type=new`, and that has to mean a blank form —
   * it said "new" and reopened the last set of answers, which is what
   * the client reported on 29 September 2026.
   *
   * A flag on the URL rather than the Drafts page clearing the row on
   * its way out: a link is something people bookmark, share and reload,
   * and it should mean the same thing every time it is opened.
   */
  const resumeParam = Number(searchParams.get('resume')) || null
  /*
   * The unfinished filing this form is writing to, once it has one.
   *
   * A ref, not state: it is read inside the debounced save, which has to
   * see the id created by the save before it. A state update would not
   * have rendered by then, so the second save would create a SECOND row
   * and the applicant would watch one filing split in two.
   */
  const scratchIdRef = useRef<number | null>(null)
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
   *
   * Nor for a RESUMED scratch draft (`?resume=`), since 3 October 2026 —
   * same reasoning, different kind of draft. Its answer arrives with the
   * restore a moment later, and opening on the way would put the dialog
   * over the form for as long as that read takes and then snatch it away.
   * The restore opens it itself when the saved copy names no business,
   * which is what a filing abandoned ON the dialog looks like.
   */
  const [identify, setIdentify] = useState<'entry' | 'change' | null>(
    isReuse && !draftIdParam && resumeParam === null ? 'entry' : null,
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
      /*
       * ── The address may not be there, and a blank page is not the answer ─
       *
       * `business.address` is a `hasOne`, so it is null for any business
       * whose address row was never written ─ and twelve fields below read
       * straight through it. The first, `b.address.telephone`, threw inside
       * this very updater, which React runs while reducing the queued state:
       * the whole wizard unmounted and the applicant was left on a blank
       * screen with no message, no nav and nothing to go back to (client's
       * screenshot, 4 October 2026, pressing Continue in the renewal dialog).
       *
       * A missing address is a thin record, not a broken one. Every field
       * below already falls back to '' for a null VALUE; this makes a null
       * ADDRESS mean the same thing, so the applicant gets the form with
       * those boxes empty and fills them in — which is what the wizard is
       * for.
       *
       * Read once into a local rather than sprinkling `?.` twelve times:
       * one name says the thing is optional, and a thirteenth field added
       * later cannot forget the question mark.
       */
      const addr = b.address ?? null
      setForm((f) => ({
        name: b.name,
        trade_name: b.trade_name ?? '',
        // Item 94: a business registered before the structure and the agency
        // were untangled can still hold "DTI"/"SEC"/"CDA" here. Read it as a
        // structure, or blank so the applicant is asked — never guessed.
        registration_type: normalizeRegistrationType(b.registration_type),
        registration_number: b.registration_number ?? '',
        tin: b.tin ?? '',
        telephone: addr?.telephone ?? '',
        website: addr?.website ?? '',
        mobile_number: addr?.mobile_number || account?.mobile_number || '',
        email: addr?.email || account?.email || '',
        owner_surname: b.owner?.surname || account?.last_name || '',
        owner_given_name: b.owner?.given_name || account?.first_name || '',
        owner_middle_name: b.owner?.middle_name || account?.middle_name || '',
        owner_suffix: b.owner?.suffix || account?.suffix || '',
        owner_gender: b.owner?.gender || account?.gender || '',
        house_bldg_no: addr?.house_bldg_no ?? '',
        // Falls back to the whole line for a business saved before the split,
        // so its street is editable rather than silently empty.
        street: addr?.street ?? addr?.line1 ?? '',
        block: addr?.block ?? '',
        lot: addr?.lot ?? '',
        lot_area_sqm: addr?.lot_area_sqm != null ? String(addr.lot_area_sqm) : '',
        line1: addr?.line1 ?? '',
        line2: addr?.line2 ?? '',
        barangay_id: addr?.barangay ? String(addr.barangay.id) : '',
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
        latitude: addr?.latitude ?? null,
        longitude: addr?.longitude ?? null,
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
      /*
        ── Section B, answered before the applicant reaches it ──────────────

        Client, 24 September 2026: *"make sure the fields already have answers
        (auto-filled from the information submitted in the new permit
        application)."* A renewal asks Section B and nothing else now, so an
        applicant meeting it blank would be retyping figures the city has held
        since they first filed.

        `feeProfileToDraft` is the same reader the reopened-draft path uses, so
        there is one mapping from the wire shape to this form's state.

        GROSS SALES IS CLEARED, and that is the point of doing this here rather
        than on the server. Everything else in Section B is a standing fact
        about the business that changes rarely and is worth starting from. Last
        year's receipts are not: they are THIS renewal's declaration, they are
        what the assessment is computed from, and a prefilled figure is one an
        applicant can accept by pressing Next. So the boxes arrive empty and
        the question gets asked.
      */
      if (result.last_fee_profile) {
        const carried = feeProfileToDraft(
          result.last_fee_profile,
          (b.lines ?? []).map((l) => l.psic_code.id),
        )
        setFeeDraft({
          ...carried,
          categories: Object.fromEntries(
            Object.entries(carried.categories).map(([id, c]) => [id, { ...c, gross_sales: '' }]),
          ),
          /*
           * And the "no gross sales to declare" tick with it. It is an answer
           * about the year being declared, so carrying last year's would have
           * the form assert something on the applicant's behalf.
           */
          no_gross_sales: false,
        })
      }
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
      /*
       * Non-fatal HERE, and only here. This feeds the wizard body, where an
       * empty list reads as "not chosen yet" and the applicant is told to press
       * Change — which is true and actionable.
       *
       * It used to say the applicant could carry on and upload the paper permit
       * instead. That escape went on 18 September 2026, so a renewal now cannot
       * proceed without naming a permit from this list. The DIALOG is where
       * that matters, and it does not use this path: it keeps its own
       * `loadError` and says the list failed rather than showing its
       * "no permit to renew" note, which would blame the business for a request
       * that broke.
       */
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
      setAmendment(identity.amendment)
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
   * cannot use would be a dead end. So it leaves; the question is where to.
   *
   * ── Home, not Track ───────────────────────────────
   *
   * It was the applications list, on the reasoning that it is "wherever
   * they can pick this up again". Half right: a DRAFT can be picked up,
   * and that arm is kept. A filing that was never started cannot, and
   * Track is a list of filings the applicant already has — which answers a
   * different question from the one they just asked by pressing Not now.
   *
   * Client, 3 October 2026: *"If I click 'Not now' in the renewal modal,
   * why does it transport me to the Track page? Would it be better if it
   * is Home page instead."* Yes — Home is where the New, Renew and Amend
   * actions are, so it is both where they came from and where they would
   * start again if they change their mind back.
   *
   * ── `resumeParam`, which the old line missed ─────────────────
   *
   * There are two kinds of draft. `draftIdParam` is a real filing the API
   * holds; `resumeParam` is a scratch row in `wizardDrafts`, and since
   * 3 October a renewal has those too — before that date only a new permit
   * did, which is why this line never had to think about them. Both come
   * from a card on the Drafts page, and sending the second to Home would
   * drop the applicant somewhere other than the list they clicked from,
   * with their draft still sitting in it.
   */
  function cancelIdentity() {
    if (identify === 'change') {
      setIdentifyError(null)
      setIdentify(null)
      return
    }

    const cameFromDrafts = draftIdParam !== null || resumeParam !== null
    navigate(cameFromDrafts ? '/drafts' : '/dashboard')
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

  /**
   * Definition plus value, one row per amendable detail.
   *
   * The definitions are authoritative for WHAT is asked and in what order;
   * the values are authoritative for what the register holds and what has
   * been requested. A field with no value yet is a real row with nothing in
   * it, which is exactly what the applicant should see while the second
   * request is in flight — the box, ready to type in, rather than a gap.
   */
  const amendRows: AmendmentRow[] = useMemo(
    () =>
      (refs.data?.amendableFields ?? []).map((def) => {
        const value = amendValues[def.field]

        return {
          ...def,
          current_value: value?.current_value ?? null,
          current_label: value?.current_label ?? null,
          new_value: value?.new_value ?? null,
          new_label: value?.new_label ?? null,
          requested: value?.requested ?? false,
          old_value: value?.old_value ?? null,
          applied_at: value?.applied_at ?? null,
        }
      }),
    [refs.data?.amendableFields, amendValues],
  )

  /**
   * Whether the PREMISES are moving, which is what costs a Zoning Clearance.
   *
   * The same question `WorkflowService::amendmentMovesPremises` answers, and
   * deliberately the same shape: the server decides whether the filing carries
   * the clearance and this decides whether the applicant is warned that it
   * will. Two callers, one rule — a warning that disagrees with the billing is
   * worse than no warning.
   *
   * The PIN, not the barangay. Zoning belongs to a location and two streets in
   * one barangay can be zoned differently, so a barangay test would let a
   * business move to a street that forbids its trade without anyone looking.
   * BizTrack cannot read the maps — they are images — so it cannot judge that
   * itself; it can only decide whether to ask CPDO, and a moved pin is the one
   * honest sign that there is something new to look at. Correcting how an
   * address is spelled leaves the pin alone.
   */
  const amendMovesPremises = useMemo(
    () => amendRows.some((r) => r.field === 'address_pin' && r.requested),
    [amendRows],
  )
  /**
   * Whether this amendment needs a NEW locational clearance — a move, or
   * either of the two changes City Ordinance No. 24-2018 Art. IX §8 names:
   * "any change in the activity or expansion of the area". The same rule as
   * `WorkflowService::amendmentNeedsLocationalClearance`, which decides
   * whether the filing carries ZONING; this decides whether the zoning step
   * and the warning appear. A floor area with no earlier figure to compare is
   * not counted, there as here.
   */
  const amendNeedsZoning = useMemo(() => {
    if (amendMovesPremises) return true
    return amendRows.some((r) => {
      if (!r.requested) return false
      if (r.field === 'line_of_business') return true
      if (r.field !== 'business_area_sqm') return false
      const before = Number(r.current_value)
      const after = Number(r.new_value)
      return r.current_value !== null && r.current_value !== '' && Number.isFinite(before)
        && Number.isFinite(after) && after > before
    })
  }, [amendMovesPremises, amendRows])
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
    /*
     * ── Three kinds of context, not one ──────────────────────────────────
     *
     * `context` used to say something about the FILING — 'all', or an
     * application type, so a new business is not asked for a previous mayor's
     * permit it cannot have. Section B adds a third kind: something about the
     * applicant's ANSWERS.
     *
     * Items 7 and 8 of the paper both read "Yes (Please attach a copy of your
     * …)", and until now neither copy had a requirement of its own. The
     * certificate had no document type at all, so the applicant was told to
     * put it under Other Requirements — a bin, which means no office can tell
     * a missing certificate from a filing that never needed one. The lease was
     * folded into "Lease Contract or Land Title", demanded of everyone, so
     * every applicant read a label half of which did not apply to them.
     *
     * The default is FALSE for a context this build does not recognise, which
     * is what makes the column safe to extend: a requirement gated on an
     * answer a future release asks for is simply not shown here, rather than
     * shown to everybody.
     *
     * NOT re-checked on the server, and that is worth knowing rather than
     * assuming. `ApplicationController::submit` enforces no documentary
     * requirement at all — it never has — so this list is what asks for a
     * document and BPLO's review is what catches a missing one. The gating
     * above therefore decides what the applicant is SHOWN, not what the API
     * will accept. If these copies ever need to block submission, the gate
     * belongs in `submit` beside the amendment and prior-permit checks, and it
     * would be a new refusal on a path that currently accepts everything.
     */
    /*
     * ── An AMENDMENT has its own requirement list ─────────────────────────
     *
     * MCG-BPLO-FO-003 prints a list per group, and it is not the new
     * application's. Measured before this: a filing of type `amendment` was
     * shown Proof of Business Registration and the Sketch and photos of
     * location — both on context `all`, both mandatory — so correcting a floor
     * area required a DTI registration and a sketch map of premises that had
     * not moved.
     *
     * So the amendment path answers from its own tokens ALONE. Anything not
     * named for an amendment is not asked for, rather than inherited from a
     * form the applicant is not filling in.
     */
    /*
     * Which of FO-003's four checkboxes this filing ticks.
     *
     * The paper prints a requirements list PER BOX, so the box is the unit the
     * rules are written against — `AmendableFields::GROUPS` server-side, the
     * `group` on every row here. Asking "is any field of this group being
     * changed" is asking "is this box ticked", which is the question the
     * paper's own lists are indexed by.
     */
    const ticked = (group: string) =>
      amendRows.some((r) => r.group === group && r.requested)

    /*
     * The two conditional lines the paper states as conditions rather than as
     * boxes: "For Single Proprietor – DTI Registration" and "For Corporation –
     * Amended Articles of Incorporation…".
     *
     * Read through `agencyFor`, not off `registration_type` directly. This
     * compared the raw value to the string 'DTI' — but the column holds a
     * STRUCTURE ('sole_proprietorship', 'partnership', …) and the agency is
     * derived from it, so every sole proprietor in the register came out
     * "incorporated" and was asked for amended Articles of Incorporation they
     * do not have. Pedro's Snack Bar is `sole_proprietorship`, which is
     * exactly the case that was wrong.
     *
     * Partnerships and cooperatives count as incorporated: all three file
     * articles, and the paper's "For Corporation" line is the one that covers
     * them. Only the sole proprietor is the other branch.
     */
    const isSoleProprietor = agencyFor(form.registration_type) === 'DTI'
    const isIncorporated = form.registration_type !== '' && !isSoleProprietor

    /*
     * The paper asks for DTI registration and corporate papers on boxes I, II
     * and III, and not on the unnumbered one — where the corporate line reads
     * "(if required)" and is covered by "Other documents that may be
     * required". So a corporation correcting its floor area is not asked for
     * amended Articles, which is what the previous rule did.
     */
    const namedBox = ticked('address') || ticked('ownership') || ticked('trade_name')

    const amendmentApplies = (token: string) => {
      if (token === 'amendment') return true
      if (token === 'amend_address') return ticked('address')
      if (token === 'amend_owner') return ticked('ownership')
      if (token === 'amend_trade_name') return ticked('trade_name')
      /*
       * "Contract of Lease AND/OR Proof of Ownership" — the paper's and/or,
       * decided by tenure. Both were demanded of every move before this,
       * because the two rows shared `amend_address` and an amendment matched
       * it either way: a shop that rents was told to produce a land title.
       */
      if (token === 'amend_address_rented') return ticked('address') && form.is_rented
      if (token === 'amend_address_owned') return ticked('address') && !form.is_rented
      if (token === 'amend_sole') return namedBox && isSoleProprietor
      if (token === 'amend_corporate') return namedBox && isIncorporated

      /*
       * SPA/authorisation is `all` and genuinely is: the paper asks for it in
       * every group. Everything else on `all` belongs to the new application
       * and is deliberately NOT inherited.
       */
      return false
    }

    const appliesNow = (context?: string) => {
      /*
       * Comma-separated since 19 September 2026, because the pivot holds one
       * row per (permit type, document type) and the contract of lease is
       * wanted by a renting NEW applicant AND by anybody changing address —
       * two different questions that could not both be named.
       */
      const tokens = (context ?? '')
        .split(',')
        .map((t) => t.trim())
        .filter(Boolean)

      if (applicationType === 'amendment') {
        // SPA stays optional-but-offered on every filing, including this one.
        if (tokens.includes('all')) return true

        return tokens.some(amendmentApplies)
      }

      if (tokens.length === 0 || tokens.includes('all')) return true
      if (tokens.includes(applicationType)) return true
      if (tokens.some((t) => t.toUpperCase() === BUSINESS_PERMIT_CODE)) return true
      if (tokens.includes('tax_incentives') && form.has_tax_incentives) return true
      if (tokens.includes('rented') && form.is_rented) return true
      if (tokens.includes('owned') && !form.is_rented) return true

      return false
    }

    const map = new Map<number, DocumentType>()
    for (const dt of businessType.document_types) {
      if (appliesNow(dt.context)) map.set(dt.id, dt)
    }
    return [...map.values()]
  }, [
    permitTypes,
    applicationType,
    form.has_tax_incentives,
    form.is_rented,
    form.registration_type,
    amendRows,
  ])
  /*
   * The whole row, not just the name: the zoning step now also needs the
   * barangay's CPDO map path and the classifications drawn on it, and both ride
   * along on the same reference payload. `barangayName` stays as the narrower
   * thing the pin checks and the zoning note read.
   */
  const selectedBarangay = barangays.find((b) => String(b.id) === form.barangay_id) ?? null
  const barangayName = selectedBarangay?.name

  /*
   * ── Why the map is not taking a pin yet, or null when it is ──────────────
   *
   * The step is one column of numbered questions in the order people answer
   * them (Ken's layout, checklist 27 September 2026): ① the line of business
   * and what is sold, ② the barangay, ③ the pin. The map is ③, so it waits
   * for ① AND ② — both, which is a change.
   *
   * It used to wait for the trade alone, and the barangay was deliberately
   * NOT a gate: the client had asked for pin-then-barangay to work, and the
   * dropdown's change handler kept a pin that agreed with the barangay named
   * after it. The checklist's Zoning 7 reverses that — the map zooms to the
   * chosen barangay when it opens, and changing the barangay clears the pin
   * outright — and both only make sense if the barangay comes first. A map
   * that opens on the right barangay also removes most wrong-barangay pins
   * before they are placed, instead of refusing them after.
   *
   * ① counts as done only with Products / Services filled in, because it is
   * part of ①, required, and the one answer on the step Ken found people
   * walking past ("the products and services aren't apparent"). A
   * pre-picker "Other" line with no typed trade holds it too.
   *
   * The sentence names exactly what is still missing, in the words of the
   * steps above, because a map that will not take a click has to say why.
   */
  const lineDone =
    form.lines.length > 0 && form.lines.every((l) => (l.products_services ?? '').trim() !== '')
  const mapLockReason: string | null = (() => {
    const noLine = form.lines.length === 0
    const noProducts = !noLine && !lineDone
    const noBarangay = !form.barangay_id
    if (noLine && noBarangay) return 'Choose your line of business and barangay first.'
    if (noLine) return 'Choose your line of business first.'
    if (noProducts && noBarangay) return 'Fill in Products / Services and choose your barangay first.'
    if (noProducts) return 'Fill in Products / Services first.'
    if (noBarangay) return 'Choose your barangay first.'
    return null
  })()
  const mapLocked = mapLockReason !== null

  /*
   * Item 5's two boxes as the one line a geocoder takes.
   *
   * The API composes `line1` from the same two parts on save — see
   * syncAddressAndLines — and this is the same composition done locally,
   * because the map has to search before anything is saved. Kept as one
   * expression so the two cannot describe the address differently.
   */
  /*
   * `?? ''` because a null here took the entire wizard to a blank page —
   * this runs at component level, so one bad value in the pair is not a
   * bad field, it is no form at all. The prefill path writes the API's
   * nulls into `form` even though `EMPTY` says these are strings, which is
   * how the client reached it; the restore now rebuilds on `EMPTY` too, so
   * this is the second of two doors rather than the only one.
   */
  const streetAddress = [form.house_bldg_no ?? '', form.street ?? '']
    .map((part) => part.trim())
    .filter(Boolean)
    .join(' ')

  /*
   * ── Item 7 · the address suggests a pin ───────────────────────────────────
   *
   * Fires on a PAUSE in typing, not on a keystroke. 800ms is long enough that
   * "24 Rizal" does not cost a lookup on its way to "24 Rizal Street", which
   * matters more than usual here: Nominatim is a free service on a roughly
   * one-request-a-second policy, and the debounce plus the cache in
   * `geocodeInMalabon` are how we stay inside it.
   *
   * Three conditions, and each is load-bearing:
   *
   *  - the map must be unlocked (steps ① and ② answered — see
   *    `mapLockReason`). Dropping a pin onto a locked map would hand the
   *    applicant a marker they cannot move and no way to understand why.
   *  - there must be no pin, OR the pin must be one WE suggested. A pin the
   *    applicant placed is an answer; overwriting it because they corrected a
   *    typo would be the form arguing with them.
   *  - the lookup must come back inside Malabon, which `geocodeInMalabon`
   *    already enforces with the same polygon test the click handler uses.
   *
   * A miss is not an error — measured on 41 addresses from the register, the
   * lookup finds a street in the chosen barangay for about one in four (see
   * `lib/geocode.ts`) — so it is never announced as a failure. But it is no
   * longer silent either. With a barangay chosen, a miss puts a hollow START
   * pin at that barangay's centre and says plainly that it is not their
   * address: an applicant in Dampalit should not have to find Dampalit on a map
   * that opened on City Hall before they can begin looking for their street.
   * The start pin is not a pin (see `startPoint`); the step still wants one.
   */
  useEffect(() => {
    if (mapLocked) return
    if (form.latitude !== null && autoPinned === null) return
    // Nothing looked up yet, so nothing has failed: no start pin either.
    if (streetQuery(streetAddress).length < 4) {
      setStartPoint(null)
      return
    }

    const controller = new AbortController()
    const timer = setTimeout(() => {
      void geocodeInMalabon(streetAddress, barangayName ?? null, controller.signal).then((hit) => {
        if (controller.signal.aborted) return
        if (hit === null) {
          const centre = barangayName ? barangayCentre(barangayName) : null
          setStartPoint(
            centre && barangayName
              ? { latitude: centre[0], longitude: centre[1], barangay: barangayName }
              : null,
          )
          return
        }
        /*
         * Re-checked against the barangay, because the suggestion is only as
         * good as the street name and OSM will happily return the same street
         * in the wrong barangay. A suggestion that contradicts the dropdown is
         * dropped in silence rather than shown as a refusal — the applicant has
         * not done anything yet to be refused.
         */
        if (checkPin(hit.latitude, hit.longitude, barangayName ?? null).kind !== 'ok') return
        setForm((f) => ({
          ...f,
          latitude: hit.latitude,
          longitude: hit.longitude,
        }))
        setAutoPinned(hit.label)
        setStartPoint(null)
        setPinError(null)
      })
    }, 800)

    return () => {
      clearTimeout(timer)
      controller.abort()
    }
    // `form.latitude` is read but deliberately not depended on: it is what this
    // effect WRITES, and listing it would re-run the lookup on its own result.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [streetAddress, mapLocked, barangayName, autoPinned])


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
            /*
             * The chosen barangay, not one derived from the pin — the zoning
             * answer is keyed on CPDO's per-barangay sheet. Included as a
             * dependency so changing the dropdown re-asks, which is the other
             * half of item 20: the verdict follows the barangay as well as the
             * trade, live, instead of waiting for Next.
             */
            barangayId: form.barangay_id ? Number(form.barangay_id) : null,
          }
        : null,
    [form.latitude, form.longitude, insightsPsicCodeId, businessId, form.barangay_id],
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
      insightsQuery.businessId !== livePin.businessId ||
      // The zoning note reads this response too, and it is keyed on the
      // barangay — a changed dropdown is a question not yet answered.
      insightsQuery.barangayId !== livePin.barangayId)

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
   * ── City Ordinance No. 24-2018, rule by rule, for what is on screen ───────
   *
   * Asked from the answers as they are typed — barangay, trade, street, floor
   * and lot area, headcount, capital — plus the zoning questions the checklist
   * itself asks. Keyed on the barangay rather than the pin, as the zoning note
   * above is, because the ordinance's zones are written per barangay and the
   * pin cannot say which of a barangay's zones the lot is in (that is CPDO's
   * to record, and the checklist says where it matters).
   *
   * An amendment is asked from the SAVED filing instead (`fetchZoningCheck`):
   * what CPDO assesses there is the requested change — the new trade, the new
   * address — which only the server holds, and the answer refreshes each time
   * the draft finishes saving.
   */
  const numberOrNull = (raw: string) => {
    const n = Number(plainAmount(raw))
    return raw.trim() !== '' && Number.isFinite(n) ? n : null
  }
  const zoningQuery = useMemo<ZoningCheckQuery | null>(
    () =>
      form.barangay_id && applicationType !== 'amendment'
        ? {
            barangay_id: Number(form.barangay_id),
            application_type: applicationType,
            lines: form.lines
              .filter((l) => l.psic_code_id)
              .map((l) => ({
                psic_code_id: l.psic_code_id,
                description: [l.line_of_business, l.products_services].filter(Boolean).join(' '),
              })),
            floor_area_sqm: numberOrNull(feeDraft.floor_area_sqm),
            lot_area_sqm: numberOrNull(form.lot_area_sqm),
            employees: numberOrNull(feeDraft.employees),
            capitalization: numberOrNull(form.capital_investment),
            street: form.street.trim() || null,
            is_rented: form.is_rented,
            storeys: numberOrNull(feeDraft.storeys),
            zoning_facts: zoningFacts,
          }
        : null,
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [form.barangay_id, applicationType, form.lines, feeDraft.floor_area_sqm, form.lot_area_sqm,
      feeDraft.employees, form.capital_investment, form.street, form.is_rented, feeDraft.storeys, zoningFacts],
  )
  const zoningPreview = useZoningCheck(zoningQuery)
  const zoningStored = useAsync(
    async () =>
      applicationType === 'amendment' && applicationId !== null && !dirty
        ? fetchZoningCheck(applicationId)
        : null,
    [applicationType, applicationId, dirty],
  )
  const zoningCheck = applicationType === 'amendment' ? zoningStored : zoningPreview
  const zoningLoading = applicationType === 'amendment' ? zoningStored.loading : zoningPreview.stale
  const answerZoning = useCallback(
    (key: string, value: ZoningFactValues[string]) =>
      setZoningFacts((facts) => ({ ...facts, [key]: value })),
    [],
  )

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
   * ── A renewal runs the same steps as a new application ─────────────────
   *
   * It did not, and the shape it had was built around one question. MCG-BPLO-FO-002
   * section A1 — "any changes or amendments in the previous business
   * registration?" — used to decide how much of the rest of the form existed:
   * unanswered stopped the wizard dead, No skipped to Review, and Yes opened
   * only the sections matching what was ticked in A2.
   *
   * The client removed that question on 9 September 2026: *"REMOVE THE
   * AMENDMENT PART on the BPLO renewal part. We don't need that anymore."*
   * Their model of a renewal is *"ALMOST the same as the application process.
   * With the only difference is that you will choose what to renew at the
   * start"* — the applicant walks the same steps and edits whatever has changed
   * as they go, rather than declaring up front what they intend to change and
   * being shown a form cut down to match.
   *
   * That is the better shape for a reason the conditional sequence could not
   * fix: A2's four ticks were a lossy index of the form. A renewal that changed
   * its telephone number, its employee count or its capitalisation ticked
   * nothing — none of the four names those — and was then shown a wizard with
   * no step it could change them on. The applicant's only route was to tick
   * "Others (specify)" and describe a phone number in prose.
   *
   * So the sequence is now `BASE_PHASES` for every filing type, and the one
   * renewal-specific question — which permits — is asked before the wizard
   * opens, in the entry dialog, where it always was.
   *
   * The `amendments` phase itself is NOT deleted: `application_type ===
   * 'amendment'` is a separate filing type the client is dealing with
   * separately, and it still asks A1/A2/A3. Deleting the step to tidy up the
   * renewal would take the amendment form's only question with it.
   */
  /**
   * The steps this filing actually has.
   *
   * ── The one step that comes and goes ──────────────────────────────────
   *
   * An amendment that moves the premises, changes the line of business or
   * enlarges the floor area applies for a fresh Zoning Clearance (City
   * Ordinance No. 24-2018, Art. IX §8), so CPDD's sheet joins the wizard as
   * its own step — between the changes and the documents, which is where it
   * falls in the filing. Withdraw the change and the step leaves again.
   *
   * Keyed on `amendNeedsZoning`, the same rule the server's
   * `WorkflowService::amendmentNeedsLocationalClearance` applies, so a step
   * cannot appear for a clearance the filing will not carry.
   *
   * `stepIndex` is clamped against this array's length on every render, so a
   * step disappearing from under the applicant cannot strand them past the
   * end — they land on Review rather than on nothing.
   */
  /**
   * The offices whose own form this filing is, when it is not the business
   * permit's.
   *
   * A renewal carries whichever permits were ticked. Tick the business permit
   * and this is the BPLO form, with the other clearances opening later at the
   * clearance stage after payment. Tick only the others and there is no BPLO
   * form to fill: the filing IS those offices' applications, so their sheets
   * are the steps.
   *
   * Ordered by `OFFICE_FORM_CODES` rather than by the order they were ticked,
   * so two applicants renewing the same pair meet them in the same order and
   * a reopened draft does not reshuffle.
   *
   * MARKET has no sheet and is filtered out by `hasOfficeForm`; a renewal of
   * that alone is privacy → review, which is honest — there is nothing to
   * fill in, only a fee.
   */
  /** A permit's own name, as the register and the tick dialog give it. */
  const officePermitName = useCallback(
    (code: OfficeFormCode) =>
      permitTypes.find((pt) => pt.code === code)?.name ?? OFFICE_FORM_META[code].title,
    [permitTypes],
  )

  const officeSteps: OfficeStep[] = useMemo(() => {
    if (applicationType !== 'renewal') return []

    const codes = permitTypes
      .filter((pt) => form.permit_type_ids.includes(pt.id))
      .map((pt) => pt.code)

    if (codes.length === 0 || codes.includes(BUSINESS_PERMIT_CODE)) return []

    return OFFICE_FORM_CODES.filter(
      (code) => codes.includes(code) && hasOfficeForm(code),
    ).map((code): OfficeStep => `office:${code}`)
  }, [applicationType, permitTypes, form.permit_type_ids])

  /**
   * Is this the BPLO renewal — the one FO-002 is the paper for?
   *
   * Distinct from `officeSteps.length === 0`, which is also true of a renewal
   * with nothing ticked yet: the entry dialog is still open then, and giving
   * that transient state the cut-down sequence would move the step bar under
   * the applicant as they answer it.
   */
  const renewsBusinessPermit = useMemo(
    () =>
      applicationType === 'renewal' &&
      permitTypes.some(
        (pt) => pt.code === BUSINESS_PERMIT_CODE && form.permit_type_ids.includes(pt.id),
      ),
    [applicationType, permitTypes, form.permit_type_ids],
  )

  /**
   * A renewal of the other permits alone — no Mayor's Permit on it.
   *
   * Its Review screen was written for a NEW business permit application and
   * said so in every line: BPLO reviewing the form, a Tax Order of Payment
   * to settle, five clearances opening after it, a Business Permit released
   * at the end. None of that happens here — BPLO is never routed
   * (`WorkflowService::submit`), nothing is billed because the fee joins the
   * next January renewal (`Application::defersPayment`), and the one permit
   * on the filing is issued by its own office.
   *
   * The client read the whole panel on a Sanitary renewal, 4 October 2026.
   * The only true line on it was the permit number at the foot.
   *
   * Keyed on carrying no business permit rather than on `officeSteps`,
   * which is also empty for a permit that happens to have no office sheet.
   */
  const clearanceOnlyRenewal = applicationType === 'renewal' && !renewsBusinessPermit

  const sequence: Phase[] = useMemo(() => {
    /*
     * ── A returned filing shows only what it was returned about ───────
     *
     * Before every other branch, because it outranks all of them: a
     * renewal or an amendment sent back about its documents is still a
     * filing whose applicant was asked for one thing.
     *
     * Review is appended so the filing can be sent; Privacy is not, since
     * the consent is already on the record and asking again would imply it
     * had lapsed.
     */
    if (returnedPhases.length > 0) {
      const ordered = BASE_PHASES.filter((p) => returnedPhases.includes(p))

      return [...ordered, 'review'] as Phase[]
    }

    if (applicationType === 'amendment') {
      if (!amendNeedsZoning) return AMENDMENT_PHASES

      return AMENDMENT_PHASES.flatMap((p) => (p === 'documents' ? ['zoning', p] : [p]))
    }

    /*
     * No Documentary Requirements step, deliberately. That step is BPLO's
     * list, and BPLO is not part of this filing — showing it would be the
     * same mistake as showing Business Information. Each office's documents
     * are asked for inside its own sheet, where its paper prints them.
     */
    if (officeSteps.length > 0) return ['privacy', ...officeSteps, 'review']

    /*
     * Section B, its documents, and the review. See RENEWAL_PHASES for why
     * the other two steps are not asked and why their answers survive anyway.
     */
    if (renewsBusinessPermit) return RENEWAL_PHASES

    return BASE_PHASES
  }, [applicationType, amendNeedsZoning, officeSteps, renewsBusinessPermit, returnedPhases])

  const totalParts = sequence.length

  /**
   * The sections this filing's Review actually draws.
   *
   * `REVIEW_SECTIONS` is the full set a NEW application has. A section that is
   * not in this filing's sequence is not drawn on its Review either — see
   * `inSequence` on `WizardSection` — so for a clearance-only renewal, whose
   * sequence is Privacy, the office sheet and Review, the answer is none of
   * them.
   *
   * Which is how "Collapse all" came to do nothing at all: it toggled four
   * sections that were not on the screen (client, 4 October 2026). The office
   * sheet is NOT among them — it renders only while it is the current step,
   * never on Review — so there was genuinely nothing to collapse.
   */
  const reviewSections = REVIEW_SECTIONS.filter((name) => sequence.includes(name))
  const reviewHasSections = reviewSections.length > 0
  /* Asked of the sections on screen, not of all four. */
  const allReviewSectionsOpen = reviewSections.every((name) => sectionOpen[name])
  const stepIndex = Math.min(step, sequence.length - 1)
  const phase: Phase = sequence[stepIndex]

  /*
   * Review draws every section at once — see the block above where
   * `sectionOpen` is declared, and <WizardSection>.
   */
  const reviewAll = phase === 'review'
  const isLast = stepIndex === sequence.length - 1

  /*
   * ── What this filing is FOR ─────────────────────────────────────────────
   *
   * On a NEW application or an amendment: the Mayor's / Business Permit,
   * implicitly and always. It is what the whole filing is for, the wizard never
   * offers it as a choice, and the API attaches the five required clearances
   * alongside it at submission.
   *
   * On a RENEWAL: exactly the permits the applicant ticked in the entry dialog,
   * and NOTHING else — the Mayor's Permit included only if they ticked it.
   *
   * The client's rule, 9 September 2026: the six permits expire on six
   * different dates, so a renewal is of whichever ones are actually due. A shop
   * whose Sanitary Permit runs out in September and whose FSIC runs to November
   * renews the one. Forcing the business permit on here would put a renewal of
   * it — and its fee — onto a filing that never asked for one, which is the
   * same defect the API had until `attachRequiredPermitTypes` learned to leave
   * a renewal alone: measured before the fix, a two-permit renewal came out of
   * submit carrying six.
   *
   * Derived from `priorPermitIds` rather than accumulated, so unticking a
   * permit in the dialog removes it here too. A renewal that names no permit at
   * all is the paper-permit escape ("this business has no BizTrack permit"),
   * and that one DOES take the business permit: there is nothing in the
   * register to renew, so what they are filing is a business permit renewal
   * against a certificate we never issued.
   */
  useEffect(() => {
    if (businessTypeId === null) return

    if (applicationType !== 'renewal') {
      /*
       * ── An amendment that moves the premises carries ZONING too ─────────
       *
       * Not only at submission. `permitTypeIdsAtSubmission` attaches it there
       * as well and the two agree, but the clearance has to exist on the
       * DRAFT for the applicant to fill CPDD's sheet in: a file needs a
       * clearance row to attach to, and `OfficeFormController::upsert`
       * refuses a permit the filing does not carry.
       *
       * Client, 21 September 2026: *"it should be really the same as the
       * application form for zoning clearance, so it means it is there where
       * you should upload that too."* This is what makes that possible.
       *
       * It leaves again if the pin is withdrawn, because the filing would no
       * longer carry the clearance at submission and a pivot row nothing
       * submits is a clearance CPDO would be routed for no reason.
       */
      const zoningTypeId =
        applicationType === 'amendment' && amendNeedsZoning
          ? (permitTypes.find((pt) => pt.code === 'ZONING')?.id ?? null)
          : null

      const wanted = [businessTypeId, ...(zoningTypeId === null ? [] : [zoningTypeId])]

      setForm((f) =>
        f.permit_type_ids.length === wanted.length &&
        wanted.every((id) => f.permit_type_ids.includes(id))
          ? f
          : { ...f, permit_type_ids: wanted },
      )

      return
    }

    const ticked = priorPermitIds
      .map((id) => renewablePermits.find((p) => p.id === id)?.permit_type?.code)
      .filter((code): code is string => typeof code === 'string')
    const ids = permitTypes.filter((pt) => ticked.includes(pt.code)).map((pt) => pt.id)
    const next = ids.length > 0 ? ids : [businessTypeId]

    setForm((f) =>
      f.permit_type_ids.length === next.length && next.every((id) => f.permit_type_ids.includes(id))
        ? f
        : { ...f, permit_type_ids: next },
    )
  }, [businessTypeId, applicationType, priorPermitIds, renewablePermits, permitTypes, amendNeedsZoning])

  /**
   * The answers each section contributed, for the review summary.
   *
   * ── The one thing here that is written twice ───────────────────────────
   *
   * The fields are not duplicated — pressing Change opens the section's own
   * form, the same JSX its step renders. What IS written twice is this: a
   * label and a formatted value per answer. That is the cost of a summary
   * that reads like a summary instead of like a form, and it is a real cost:
   * the officer's review page hand-writes its own and was still showing BPLO
   * four lessor rows months after the wizard stopped collecting them.
   *
   * So it is tested rather than trusted. See `the review summary covers every
   * field a section asks` in the e2e suite, which counts the inputs a section
   * renders against the rows claimed for it here.
   *
   * Rows are omitted, not blanked, when the question does not apply — a
   * president for a sole proprietorship, a lease for owner-occupied premises.
   * An "—" against a question nobody was asked reads as an answer they failed
   * to give.
   */
  const reviewAnswers: Record<ReviewSectionName, ReviewAnswer[]> = useMemo(() => {
    const barangay = barangays.find((b) => String(b.id) === form.barangay_id)?.name ?? ''
    const structure = REGISTRATION_TYPES.find((t) => t.value === form.registration_type)
    const organization = ECONOMIC_ORGANIZATIONS.find((o) => o.value === form.economic_organization)
    const yesNo = (v: boolean) => (v ? 'Yes' : 'No')
    const owner = [
      form.owner_given_name,
      form.owner_middle_name,
      form.owner_surname,
      form.owner_suffix,
    ]
      .map((part) => part.trim())
      .filter(Boolean)
      .join(' ')

    /* The trades declared, each with the class the Revenue Code taxes it under. */
    const trades = form.lines.map((line, i) => {
      const code = psic.find((c) => c.id === line.psic_code_id)
      /*
       * The same precedence the rest of the system now reads by — see
       * `tradeName`. It was "the typed text, but only on the catch-all code",
       * which agreed with the shared rule on the line that matters and
       * disagreed with it on a classified line carrying a description.
       */
      const name =
        tradeName({
          line_of_business: line.line_of_business,
          psic_code: code ? { title: code.title } : null,
        }) ?? ''

      return {
        label: form.lines.length > 1 ? `Line of Business ${i + 1}` : 'Line of Business',
        value: name,
      }
    })

    const documents = requiredDocs.map((dt) => {
      const files = uploaded[dt.id] ?? []

      return {
        label: dt.name,
        value:
          files.length === 0
            ? ''
            : files.length === 1
              ? files[0].name
              : `${files.length} files attached`,
      }
    })

    /*
     * Renewal only, because the picker is — MCG-BPLO-FO-002 prints the box and
     * MCG-BPLO-FO-001 does not. Appended after the uploads so it reads in the
     * order the paper does: the requirements list, then the payment line at
     * the foot of it.
     */
    if (applicationType === 'renewal') {
      documents.push({
        label: 'Mode of Payment',
        value: PAYMENT_MODES.find((m) => m.value === paymentMode)?.label ?? '',
      })
    }

    return {
      address: [
        { label: 'House / Bldg. No.', value: form.house_bldg_no },
        { label: 'Street', value: form.street },
        { label: 'Block', value: form.block },
        { label: 'Lot', value: form.lot },
        { label: 'Lot Area (sq. m.)', value: form.lot_area_sqm },
        { label: 'Barangay', value: barangay },
        {
          label: 'Map pin',
          value:
            form.latitude != null && form.longitude != null
              ? `${form.latitude.toFixed(5)}, ${form.longitude.toFixed(5)}`
              : '',
        },
        ...trades,
        {
          label: 'Products / Services',
          value: form.lines
            .map((l) => l.products_services.trim())
            .filter(Boolean)
            .join('; '),
        },
      ],
      business: [
        {
          label: `2. ${structure ? `${structure.agency} Registration Number` : 'Registration Number'}`,
          value: form.registration_number,
        },
        {
          label: '3. Tax Identification Number (TIN)',
          /*
           * An em dash like every other blank, since 30 September 2026.
           *
           * This row carried the consequence of a blank TIN — first "an
           * officer will ask for it under Other Requirements" (24 September,
           * withdrawn on the 27th as a thing nothing in the system did), then
           * "you will be asked for it once BPLO approves this form". The
           * client moved it to a modal on the press that skips the question,
           * which is where the choice is actually made; by the Confirm step
           * the applicant has filled in eleven more sections and a
           * consequence arriving here is news rather than a decision.
           *
           * See TIN_SKIPPED_NOTICE and `next()`.
           */
          value: form.tin,
        },
        { label: '4. Business Name', value: form.name },
        { label: '5. Trade Name / Franchise', value: form.trade_name },
        /*
         * Numbered in the order the step ASKS, 1 to 13, matching the labels on
         * the form itself. These were the paper's item numbers and carried its
         * gaps — 5 and 16 are asked elsewhere or not at all — until the client
         * ruled against them on 24 September 2026. The reasoning is on the
         * form's own labels; what matters here is only that the two agree,
         * because this list is read back to the applicant beside them.
         */
        { label: '6. Telephone (Landline)', value: form.telephone },
        { label: '7. Mobile Number', value: form.mobile_number },
        { label: '8. E-mail Address', value: form.email },
        { label: '9. Website Address', value: form.website },
        { label: '1. Form of Organization', value: structure?.label ?? '' },
        /*
         * ── 10 to 13, one range for four boxes ───────────────────────────
         *
         * The form numbers surname, given name, middle name and suffix
         * separately; the summary reads them back as the one name they
         * compose, so it carries the range rather than repeating the four
         * labels over four near-empty rows.
         *
         * MCG-BPLO-FO-001 numbers this block 11 or 12 depending on the
         * structure, and skips one either way. This form has not followed
         * the paper's numbering since the client asked for it to run
         * sequentially on 24 September 2026 — what matters is that the page
         * and this summary agree with each other, which is why they are
         * always renumbered in the same edit.
         */
        { label: '10–13. Owner / Representative', value: owner },
        /*
         * The WORD, not the code. This summary showed "M" for an answer
         * the applicant gave by pressing a button marked Male — on the
         * last screen before they commit, which is the worst place to
         * make somebody wonder whether the form understood them.
         */
        { label: '14. Gender', value: genderLabel(form.owner_gender) },
        /*
         * 15 to 17 are asked of EVERY structure — both arrows on the paper
         * point at its item 13 — so they are always read back, blank or not.
         * The
         * comment here used to say they were "asked only of the structures
         * that have a president", which stopped being true on 16 September
         * 2026, and the condition below it hid the rows whenever all three
         * were empty: a summary that silently drops the questions somebody
         * left unanswered is the one place that should not.
         */
        {
          label: '15. Name of President / Officer in Charge',
          value: form.president_officer_name,
        },
        {
          label: '16. Citizenship (of President/OIC)',
          value: form.citizenship,
        },
        {
          label: '17. Capital Participation (% Filipino)',
          value: form.capital_participation_filipino,
        },
        {
          label: 'Emergency Contact Person',
          value: form.emergency_contact_name,
        },
        {
          label: 'Emergency Contact Number',
          value: form.emergency_contact_number,
        },
      ],
      operation: [
        {
          label: '1. Business Area (sq. m.)',
          value: feeDraft.floor_area_sqm,
        },
        { label: '2. Total Number of Employees', value: feeDraft.employees },
        {
          label: '2. Number of Male Employees',
          value: feeDraft.male_employees,
        },
        {
          label: '2. Number of Female Employees',
          value: feeDraft.female_employees,
        },
        {
          label: '3. Number of Employees Residing in Malabon',
          value: feeDraft.employees_in_lgu,
        },
        {
          label: '4. Motorized Delivery Units',
          value: feeDraft.delivery_vehicles_motorized,
        },
        {
          label: '4. Other Delivery Units',
          value: feeDraft.delivery_vehicles_other,
        },
        {
          label: '5. Economic Organization',
          value: organization?.label ?? '',
        },
        ...(form.economic_organization === 'others'
          ? [
              {
                label: '5. Others, specified',
                value: form.economic_organization_others,
              },
            ]
          : []),
        ...(applicationType === 'new'
          ? [
              {
                label: '6. Capital Investment',
                value: form.capital_investment,
              },
            ]
          : []),
        {
          label: '7. Tax incentives from a government entity',
          value: yesNo(form.has_tax_incentives),
        },
        {
          label: '8. Do you pay rent for the premises',
          value: yesNo(form.is_rented),
        },
        ...(applicationType === 'renewal'
          ? [
              {
                label: 'Gross Sales, Preceding Year',
                value: form.lines
                  .map((l) => feeDraft.categories[l.psic_code_id]?.gross_sales ?? '')
                  .filter(Boolean)
                  .join('; '),
              },
            ]
          : []),
        {
          label: 'Which of these apply to your business',
          value: feeDraft.flags.length === 0 ? 'None' : `${feeDraft.flags.length} selected`,
        },
      ],
      documents,
    }
  }, [form, feeDraft, barangays, psic, requiredDocs, uploaded, applicationType])

  const feeLines = useMemo(
    () =>
      form.lines.map((l) => {
        const code = psic.find((c) => c.id === l.psic_code_id)

        return {
          id: l.psic_code_id,
          title: code?.title ?? `PSIC #${l.psic_code_id}`,
          /*
           * What the Revenue Code does with this trade, carried through so the
           * fee step can state the classification instead of asking for it.
           * Both come from the reference table; see FeeProfileStep’s
           * DerivedTaxClass for why the question went.
           */
          category: code?.category ?? null,
          categoryBranch: code?.category_branch ?? null,
        }
      }),
    [form.lines, psic],
  )

  const priorPermitChoice: Permit | null = useMemo(
    () => renewablePermits.find((p) => p.id === priorPermitId) ?? null,
    [renewablePermits, priorPermitId],
  )

  /**
   * The office that issues the permit being renewed, named from the register.
   *
   * Read off `permit_types.department` rather than from a code-to-office table
   * written here: the pairing is a fact the API already holds, and a second
   * copy of it in the browser is one that can fall out of step with the first.
   *
   * Null until the permit is known, and the sentence that uses it falls back
   * to "The issuing office" — true, and better than naming the wrong one.
   */
  const renewingOffice: string | null = useMemo(() => {
    const code = priorPermitChoice?.permit_type?.code
    if (code === undefined) return null

    return permitTypes.find((pt) => pt.code === code)?.department?.name ?? null
  }, [priorPermitChoice, permitTypes])

  /**
   * Who receives this filing when Submit is pressed.
   *
   * BPLO for a new application and an amendment, both of which go to its
   * counter first. For a clearance-only renewal it is the permit's OWN
   * office: BPLO is never routed one of those at all
   * (`WorkflowService::submit`), so naming it in the confirmation told the
   * applicant their papers were going somewhere they were not — at the one
   * moment they are committing.
   *
   * `renewingOffice` reads `permit_types.department`, so CHO, BFP, OBO,
   * CENRO and CPDD each name themselves without a table in the browser that
   * could fall out of step with the register.
   */
  const submitOffice = (clearanceOnlyRenewal ? renewingOffice : null) ?? 'BPLO'

  /*
   * ── The permits being renewed that have already lapsed ──────────────────
   *
   * Renewing after expiry is charged for: Revenue Code Sec. 8A.04 adds 25%
   * once and Sec. 8A.05 adds 2% for every month or part of a month since,
   * capped at 36. `WorkflowService::latePenaltyFor` has applied it on both
   * paths since 1 October 2026 — as line items on a business permit's
   * assessment, and on the deferred fee a clearance carries to January — so
   * the money is real and the applicant was meeting it for the first time on
   * the bill. The client asked for it at the point of commitment instead
   * [4 October 2026].
   *
   * Read off the prior permits the applicant TICKED, not off the business:
   * renewing a lapsed Sanitary Permit is late whatever the state of the four
   * beside it, and naming the wrong certificate in a warning about money is
   * worse than not warning at all.
   */
  const lapsedBeingRenewed = useMemo(
    () =>
      applicationType !== 'renewal'
        ? []
        : priorPermitIds
            .map((id) => renewablePermits.find((p) => p.id === id))
            .filter((p): p is Permit => p !== undefined)
            .filter((p) => p.days_until_expiry !== null && p.days_until_expiry < 0),
    [applicationType, priorPermitIds, renewablePermits],
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

  /**
   * The record this filing is about, for the header on every step.
   *
   * Only on a renewal or an amendment: a NEW application has no existing
   * record to name, and the business it will create is the thing being typed
   * on the very steps this would sit above.
   *
   * The permit rides along on an amendment because the paper's header block
   * prints the account it is amending, and because the applicant chose the
   * business BY its permit number in the dialog — so the number they picked
   * should still be on screen five steps later.
   */
  const filingIdentity = useMemo(() => {
    if (!isReuse || reuseBusinessName === null) return null

    const chosen = (ownedBusinesses.data ?? []).find((b) => b.id === prefillBusinessId)

    if (applicationType !== 'amendment') {
      return { business: reuseBusinessName, permit: null }
    }

    /*
     * FO-003's header, off the register rather than out of the form.
     *
     * The business the applicant picked, not the fields they may be part-way
     * through editing: this block states what is ON RECORD, which is the
     * thing the amendment is about to change. Reading `form` would show the
     * new address beside the heading "Address" on a form whose whole purpose
     * is to ask for a new address.
     */
    const owner = chosen?.owner
    const taxpayer =
      owner == null
        ? null
        : [owner.given_name, owner.middle_name, owner.surname, owner.suffix]
            .filter((part) => (part ?? '').trim() !== '')
            .join(' ')

    const address = chosen?.address
    const onRecord = [address?.line1, address?.line2, address?.barangay?.name, address?.city]
      .filter((part) => (part ?? '').trim() !== '')
      .join(', ')

    return {
      business: reuseBusinessName,
      permit: chosen?.current_business_permit?.permit_number ?? null,
      taxpayer: taxpayer === '' ? null : taxpayer,
      accountNumber: chosen?.ban ?? null,
      address: onRecord === '' ? null : onRecord,
      /*
       * The filing's own date once there is a filing — see `filedAt`. Today
       * only while the draft has not been created, which is the one moment
       * nobody can reopen to watch it change.
       */
      dated: formatDate(filedAt ?? new Date().toISOString()),
    }
  }, [
    isReuse,
    reuseBusinessName,
    ownedBusinesses.data,
    prefillBusinessId,
    applicationType,
    filedAt,
  ])

  /**
   * One of the applicant's OWN businesses already citing this certificate.
   *
   * Not a refusal — the server allows it, deliberately. SEC and CDA register an
   * entity, and one entity lawfully runs several establishments, so a
   * corporation filing for its second branch cites the same number twice and
   * must not be stopped (see the rule in `BusinessController::validateBusiness`
   * for why a global unique index would refuse a real filing).
   *
   * What it IS for is the other reading of the same keystrokes: an applicant
   * who meant to renew, opened a new application by mistake, and is about to
   * create a second business record for a shop they already have. Naming the
   * business they already hold is the one thing that tells those two apart, and
   * only they can say which it is.
   *
   * Their own businesses only. The cross-account case is the server's, and its
   * message deliberately does not name the other party.
   */
  /*
   * ── What the answers on the fee step actually produce ────────────────────
   *
   * The step asked for a tax bracket, gross sales, a storey count and a set of
   * flags, and showed NOTHING back: the amount appeared only after BPLO
   * approved the form and raised the Tax Order of Payment. The client's
   * question was the fair one — why does this step exist? — and data entry with
   * no visible output is what invites it.
   *
   * An ESTIMATE, labelled as one. It is computed by the same
   * `FeeCalculator` that raises the real bill, over the same permit set
   * submission will attach (`WorkflowService::permitTypeIdsAtSubmission`), so
   * the two cannot quietly price different things. It is still not a bill:
   * BPLO assesses, the ordinance has brackets this build may read differently
   * from the counter, and the panel says so rather than implying a figure the
   * applicant can hold the city to.
   *
   * Debounced, and only while the fee step is open. Nothing is persisted by the
   * endpoint — see the note on PaymentController::feePreview for why `GET /fee`
   * could not be reused: it writes a FeeAssessment, and polling that while
   * somebody types would leave a draft carrying a Tax Order of Payment nobody
   * raised.
   */
  const [feeEstimate, setFeeEstimate] = useState<FeeAssessment | null>(null)
  const [feeEstimateBusy, setFeeEstimateBusy] = useState(false)
  const [feeEstimateFailed, setFeeEstimateFailed] = useState(false)

  useEffect(() => {
    /*
     * Never for an amendment: no Tax Order of Payment is raised on one, and
     * the estimate priced the Mayor's Permit and five clearances over a
     * trade-name correction — "₱6,425 … including the five other permits"
     * (tester, 5 October 2026; docs/amendment-2026-09-19.md §3).
     */
    if (phase !== 'review' || applicationId === null || applicationType === 'amendment') return

    let live = true
    const timer = setTimeout(() => {
      setFeeEstimateBusy(true)
      setFeeEstimateFailed(false)
      payments
        .feePreview(
          applicationId,
          buildFeeProfile(feeDraft, {
            applicationType,
            permitCodes: [BUSINESS_PERMIT_CODE],
            lineIds: form.lines.map((l) => l.psic_code_id),
            capitalInvestment: form.capital_investment,
          }),
        )
        .then((result) => {
          if (live) setFeeEstimate(result)
        })
        .catch(() => {
          /*
           * Non-fatal, and it must stay that way: an estimate is a courtesy and
           * the filing has to be submittable without one. The panel says the
           * figure is unavailable rather than showing a stale total that no
           * longer matches what is typed above it.
           */
          if (live) {
            setFeeEstimate(null)
            setFeeEstimateFailed(true)
          }
        })
        .finally(() => {
          if (live) setFeeEstimateBusy(false)
        })
    }, 700)

    return () => {
      live = false
      clearTimeout(timer)
    }
  }, [phase, applicationId, feeDraft, applicationType, form.lines, form.capital_investment])



  /**
   * The amendable details under FO-003's four checkboxes, in the paper's order.
   *
   * The server sends one flat list ordered by group, because the group is a
   * fact about the field and not about the screen — `AmendableFields::GROUPS`
   * is the single definition and the document rules read the same key. This
   * only folds the list, so the step and the requirements can never disagree
   * about which box a detail belongs to.
   */
  const amendGroups = useMemo(() => {
    const out: {
      key: string
      label: string
      paper: string | null
      rows: AmendmentRow[]
    }[] = []

    for (const row of amendRows) {
      const seen = out.find((g) => g.key === row.group)
      if (seen) {
        seen.rows.push(row)
        continue
      }
      out.push({
        key: row.group,
        label: row.group_label,
        paper: row.group_paper,
        rows: [row],
      })
    }

    return out
  }, [amendRows])

  /**
   * Which of FO-003's boxes the applicant has ticked.
   *
   * ── Why the sections collapse ────────────────────────────────────────
   *
   * Because the paper's boxes are CHECKBOXES. FO-003 asks you to tick the one
   * you are filing for and leave the rest alone, and a screen that shows all
   * four open at once is not the same form — it is eighteen blanks and a map,
   * and an applicant correcting a floor area meets seventeen questions that
   * are not theirs. Client's standing note, 19 September 2026: *"Always
   * consider user-friendliness in the UI."*
   *
   * `null` until the rows arrive, so the answer can be seeded from what is
   * ALREADY requested — a reopened draft must open on the boxes it filled in,
   * not close them and look empty.
   */
  const [openGroups, setOpenGroups] = useState<string[] | null>(null)
  const seededOpenGroups = useRef(false)

  /*
   * Seeded once the VALUES have landed, not once the rows exist.
   *
   * The rows exist immediately now — they are drawn from reference data — but
   * every one of them reads `requested: false` until the per-filing fetch
   * answers. Seeding off the rows therefore opened nothing, and because it
   * only seeds once, a reopened draft would have shown all four boxes shut
   * over changes it had already been asked for.
   *
   * `amendLoading` going false is the moment the answer is known, whether it
   * came back with requests or without. The ref makes it once and for all:
   * after that the boxes are the applicant's to open and close, and a later
   * save must not reopen one they just shut.
   */
  useEffect(() => {
    if (amendLoading || seededOpenGroups.current) return

    seededOpenGroups.current = true
    setOpenGroups(amendRows.filter((r) => r.requested).map((r) => r.group))
  }, [amendLoading, amendRows])

  /**
   * The barangay the map outlines while the pin is being placed.
   *
   * The one being MOVED TO if the applicant has chosen one, otherwise the one
   * on record — so the outline follows the answer rather than the register.
   * Placing a pin against a boundary the applicant cannot see is a puzzle
   * rather than a validation, which is the whole reason MapPicker draws it.
   */
  const amendBarangay = useMemo(() => {
    const row = amendRows.find((r) => r.field === 'address_barangay_id')
    const asked = amendTyped.address_barangay_id ?? ''
    const id = asked || (row?.current_value ?? '')

    return {
      name: barangays.find((b) => String(b.id) === id)?.name ?? null,
      /*
       * Whether the applicant NAMED this barangay or the register did.
       *
       * The pin is checked against it either way — a house-number correction
       * must stay inside the barangay it is in — but the two cases have to be
       * SAID differently. The refusal read "your new address is in Acacia"
       * over a dropdown still saying "Leave unchanged": Acacia is where the
       * business already is, and calling the register's own value the
       * applicant's new address is simply untrue. Client, 21 September 2026:
       * *"Is it fine that it shows Acacia already in the map, even though I
       * am trying to change address?"*
       */
      chosen: asked !== '',
    }
  }, [amendRows, amendTyped, barangays])

  const amendBarangayName = amendBarangay.name

  /**
   * Whether a DIFFERENT barangay is being asked for.
   *
   * Only decides that the pin has to be dropped again and has to land inside
   * the new barangay — you cannot be in another barangay at the same point.
   * It is NOT what sends the filing to CPDO; see `amendMovesPremises`.
   *
   * Compared against the row's own `current_value`, so picking the barangay
   * the business is already in is correctly not a change.
   */
  const amendChangesBarangay = useMemo(() => {
    const row = amendRows.find((r) => r.field === 'address_barangay_id')
    if (row === undefined || !row.requested || row.new_value === null) return false

    return row.new_value !== (row.current_value ?? '')
  }, [amendRows])

  /**
   * What CPDD's sheet should say, if this amendment reaches them.
   *
   * ── The NEW values, not the register's ───────────────────────────────
   *
   * The clearance stage builds this same object from the business record,
   * which is right there and wrong here: the register still holds the OLD
   * address until BPLO approves, so a sheet built from it would show the
   * zoning officer the premises the business is LEAVING. CPDD has to assess
   * where it is going. Every amendable field the sheet prints is therefore
   * overlaid with the requested value where one exists.
   *
   * Null until the business is known, which is also when there is nothing to
   * preview.
   */
  const officeSheetBusiness = useMemo<CarriedOverBusiness | null>(() => {
    const chosen = (ownedBusinesses.data ?? []).find((b) => b.id === prefillBusinessId)
    if (chosen === undefined) return null

    /** The requested value for a field, or null if this filing leaves it alone. */
    const asked = (field: string) => {
      const row = amendRows.find((r) => r.field === field)

      return row?.requested === true ? (row.new_label ?? row.new_value) : null
    }

    const address = chosen.address
    const line = chosen.lines?.[0]
    const owner = chosen.owner
    /*
     * `??` down this chain let an EMPTY typed line win over the PSIC title and
     * print nothing. `tradeName` falls through on blank, which is what the
     * fallback was there for.
     */
    const trade = asked('line_of_business') ?? tradeName(line) ?? '—'

    /*
     * Composed the way the register composes it, so the preview and the
     * approved record read alike: house and street together, then the
     * barangay. Each part falls back to what is on file, because an
     * amendment changing only the barangay still has a street.
     */
    const house = asked('address_house_bldg_no') ?? address?.house_bldg_no ?? ''
    const street = asked('address_street') ?? address?.street ?? address?.line1 ?? ''
    const barangay = asked('address_barangay_id') ?? address?.barangay?.name ?? ''

    return {
      name: chosen.name,
      tradeName: asked('trade_name') ?? chosen.trade_name ?? '',
      address: [`${house} ${street}`.trim(), barangay].filter((p) => p !== '').join(', ') || '—',
      lineOfBusiness: trade,
      registrationType: chosen.registration_type ?? '',
      ownerName:
        [owner?.surname, owner?.given_name, owner?.middle_name, owner?.suffix]
          .map((part) => (part ?? '').trim())
          .filter(Boolean)
          .join(', ') || '',
      ownerSex: owner?.gender ?? '',
      productsServices: line?.products_services ?? '',
      landline: address?.telephone ?? '',
      mobile: address?.mobile_number ?? '',
      /*
       * The amendment's requested figure if there is one, else the
       * register's. The business record carries the latest declared figures
       * since `WorkflowService::syncDeclaredFigures`, so a renewal's sheet
       * shows what the last approved filing said rather than a blank where
       * the clearance stage would have shown a number.
       */
      businessAreaSqm: asked('business_area_sqm') ?? numberOrBlank(chosen.business_area_sqm),
      maleEmployees: asked('male_employees') ?? numberOrBlank(chosen.male_employees),
      femaleEmployees: asked('female_employees') ?? numberOrBlank(chosen.female_employees),
      proprietorName:
        (chosen.president_officer_name ?? '').trim() ||
        [owner?.given_name, owner?.middle_name, owner?.surname, owner?.suffix]
          .map((part) => (part ?? '').trim())
          .filter(Boolean)
          .join(' '),
      proprietorContact: address?.mobile_number ?? address?.telephone ?? '',
      proprietorEmail: address?.email ?? '',
      activity: trade,
    }
  }, [ownedBusinesses.data, prefillBusinessId, amendRows])

  /** A figure as the office sheets want it: the digits, or nothing at all. */
  function numberOrBlank(value: number | null | undefined): string {
    return value === null || value === undefined ? '' : String(value)
  }


  /**
   * What this filing changes, in one line.
   *
   * Two sources, because the two forms ask in different words. A RENEWAL ticks
   * Section A's categories; an AMENDMENT names the details it is correcting,
   * and reading its categories — which it never asks — printed "Nothing chosen
   * yet" over a form that had been filled in. "Floor area (sqm), Trade name"
   * is also the more useful sentence, and it is the one the officer's summary
   * chips show, off the same rows.
   */
  const amendmentSummary: string | null = useMemo(() => {
    if (applicationType === 'amendment') {
      const asked = amendRows.filter((r) => r.requested).map((r) => r.label)

      return asked.length === 0 ? null : asked.join(', ')
    }

    const parts = [
      ...AMENDMENT_KINDS.filter((k) => amendment[k.key]).map((k) => k.label),
      ...(amendment.other.trim() ? [amendment.other.trim()] : []),
    ]
    return parts.length === 0 ? null : parts.join(', ')
  }, [amendment, amendRows, applicationType])

  /** The rows asked about, with their values, for Review and the confirmation dialog. */
  const amendRequested = useMemo(() => amendRows.filter((r) => r.requested), [amendRows])

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
    (p: Phase): string[] => {
      /*
       * ── An office step answers from its own sheet ────────────────────
       *
       * `officeFormMissing` is the same rule the clearance stage applies,
       * called on the same data, so a renewal of the other permits alone is
       * gated exactly as those sheets are gated when they are reached the
       * usual way. Nothing about being a wizard step changes what the sheet
       * requires.
       */
      const officeCode = officeStepCode(p)
      if (officeCode !== null) {
        /*
         * ── The office's DOCUMENTS gate too, not just its answers ────────
         *
         * `officeFormMissing` reads answers and has no business fetching a
         * document list, so the clearance stage adds the blocking rows to
         * it separately. This step did not, and so let the applicant walk
         * past an office sheet with a required attachment missing.
         *
         * What that cost: Next was enabled, Review was reached, Submit was
         * pressed — and `submitClearanceForm` refused the sheet on the
         * server ("Attach Business permit fee / tax assessment bill from
         * BPLO before submitting this form"), inside the transaction that
         * submits the filing. The whole submission rolled back, so the
         * filing reached no office at all: the same ending as the routing
         * bug this was found beside, by a different road.
         *
         * Which rows gate is the SERVER's call — `blocking` on
         * `OfficeFormRequirement`, and `WorkflowService::submitClearanceForm`
         * is what enforces it. This adds no rule, it stops ignoring one.
         */
        return [
          ...officeFormMissing(officeCode, officeData[officeCode] ?? {}),
          ...(officeReqs[officeCode] ?? [])
            .filter((row) => row.blocking === true && !row.satisfied)
            .map((row) => row.label),
        ]
      }

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
           * Items 82/84 — an amendment amending nothing is still not a filing,
           * but the question belongs to the `amendments` case above, which
           * asks it of the new details the form actually collects. Asked here
           * too, in the language of the removed category ticks, it sent the
           * applicant to press a Change button for a control that had gone.
           */
          if (!form.name.trim()) missing.push('Business Name')
          /*
           * Items 11/12 — the named person the filing is in.
           *
           * Prefilled from the signed-in account, so for a sole proprietor
           * these are already answered before the step is opened and the check
           * costs them nothing. It bites on the case it is for: a corporation
           * naming somebody other than the account holder, who clears the boxes
           * and does not refill them.
           *
           * Middle name and suffix stay optional — plenty of people have
           * neither, and a form that insists otherwise is asking them to invent
           * one. Gender is required because CENRO's paper prints a SEX box and
           * nothing else on the filing answers it.
           */
          if (!form.owner_surname.trim()) missing.push('Owner’s Family Name')
          if (!form.owner_given_name.trim()) missing.push('Owner’s First Name')
          if (!form.owner_gender.trim()) missing.push('Owner’s Sex')
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
          if (agency === null) missing.push('1. Form of Organization')
          const numberLabel = agency
            ? REGISTRATION_AGENCIES[agency].label
            : 'Your registration number'
          if (!form.registration_number.trim()) missing.push(numberLabel)
          else if (!registrationNumberValid(form.registration_number)) {
            missing.push(`A valid ${numberLabel}`)
          }
          /*
           * Optional since 24 September 2026, so a blank one does not hold
           * the step back. A TYPED one still has to be a TIN: somebody who
           * meant to give it and mistyped it wants to hear about it, and the
           * server rejects the same value either way.
           */
          if (form.tin.trim() && !tinValid(form.tin)) {
            missing.push('A valid TIN (9 digits, plus branch code)')
          }
          /*
           * ── The blanket "paper fields are optional" rule ended here ────────
           *
           * It read: none of the three paper forms marks any field required,
           * every asterisk in this wizard is our own judgement, so nothing
           * transcribed from paper is listed for being blank.
           *
           * The client reversed it on 9 September 2026 after seeing the cost.
           * A filing reached CENRO with no products or services, no employee
           * split, no landline and no mobile — five of the six boxes on that
           * office's Business Details block were empty, and the office had
           * nothing to work from. "Can you make ALL non-optional fields
           * required now so that we won't have the same problem again."
           *
           * So the rule is now per field rather than blanket, and the test is
           * whether an office can do its job without the answer:
           *
           *  REQUIRED — Mobile Number and E-mail Address: how the office
           *    reaches the applicant, and every business has both.
           *  OPTIONAL — Telephone (Landline): most sari-sari stores,
           *    carinderias and market stalls genuinely have none, so requiring
           *    one buys a false answer rather than a real one (client's
           *    decision, 9 September 2026).
           *  REQUIRED — Trade Name: optional under the 9 September reading
          *    ("Website and Trade Name: the same, more so"), and reversed by
          *    the client on 24 September 2026. A business that trades under
          *    its registered name answers with that name; the field asks
          *    what is over the door, which every business has.
          *  OPTIONAL — Website: most of these businesses have none, so
          *    requiring one buys a false answer rather than a real one.
           *
           * Anything still optional is validated when filled and never demanded
           * when blank, which is what these three checks were doing for
           * everything.
           */
          if (!form.mobile_number.trim()) missing.push('Mobile Number')
          else if (!phoneValid(form.mobile_number)) missing.push('A valid Mobile Number')
          if (!form.email.trim()) missing.push('E-mail Address')
          else if (!emailValid(form.email)) missing.push('A valid E-mail Address')
          /*
           * Item 5 is OPTIONAL as of 30 September 2026, on the client's
           * decision. The server always accepted a blank one and the permit
           * already omits the row when there is none; what this check
           * demanded of most filers was their own business name, typed a
           * second time. A business that trades under its registered name
           * has no second name to give.
           */
          if (form.telephone.trim() && !phoneValid(form.telephone)) {
            missing.push('A valid Telephone (Landline)')
          }
          /*
           * Item 10 — the mobile is checked on the same terms as the landline
           * beside it: optional, so never listed for being blank, listed only
           * when what is in it is not a number that can be rung. `mobileValid`
           * and not `phoneValid`, because this field means one specific thing —
           * ten digits after +63 starting with 9 — where phoneValid is the loose
           * "mobile or landline" rule the other four contact fields share.
           */
          if (form.mobile_number.trim() && !mobileValid(form.mobile_number)) {
            missing.push('A valid Mobile Number')
          }
          if (form.website.trim() && !websiteValid(form.website)) {
            missing.push('A valid Website Address')
          }
          /*
           * Items 13-15, now asked of every structure — the paper routes both
           * 11 and 12 to 13. Still gated on `hasPresidentOrOfficer` rather
           * than unconditional, so that narrowing it again is one line there
           * and not four here.
           */
          if (hasPresidentOrOfficer(form.registration_type)) {
            if (!form.president_officer_name.trim()) {
              missing.push('Name of President / OIC')
            }
            if (!form.citizenship.trim()) missing.push('Citizenship of the President / OIC')
            /*
             * Not for a sole proprietor: item 17 is derived from item 16 and
             * read-only, so a blank here means item 16 is blank — already
             * reported on the line above. Listing both would name two things
             * to go and fix when there is one, and point at a box that does
             * not take typing.
             */
            if (
              form.registration_type !== 'sole_proprietorship'
              && !form.capital_participation_filipino.trim()
            ) {
              missing.push('Capital Participation (Filipino)')
            }
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
          /*
           * B6 became required on 9 September 2026 with the rest of the paper
           * fields. It is a closed list of establishment types the applicant
           * picks from, not free text — there is no business it fails to
           * describe, and "Others" with its own blank is there for the ones the
           * list does not name. A blank here is an unanswered question rather
           * than an inapplicable one, which is the test the whole reversal turns
           * on.
           */
          if (!form.economic_organization) missing.push('5. Economic Organization')
          if (
            form.economic_organization === 'others' &&
            !form.economic_organization_others.trim()
          ) {
            missing.push('5. What “Others” means for your Economic Organization')
          }
          /*
           * The WHOLE fee profile, unscoped.
           *
           * It used to be scoped to `operation` — section B's own figures, B1
           * business area, B2 employees, B3 how many live in the LGU — because
           * the rest was checked on a Tax Classification & Fees step of its
           * own. That step is gone, so its half is asked here too and has to
           * be checked here, or a filing reaches Review with an unanswered
           * question and no step left that admits to owning it.
           */
          missing.push(
            ...feeProfileMissing(feeDraft, {
              applicationType,
              permitCodes: [BUSINESS_PERMIT_CODE],
              lines: feeLines,
            }),
          )
          // BPLO item B7, which replaced the per-line capitalization on the fee
          // step. Same rules it had: required on a new filing, positive, bounded.
          missing.push(...capitalInvestmentMissing(form.capital_investment, applicationType))

          /*
           * ── The lessor gate is gone, with the fields ─────────────────────
           *
           * MCG-BPLO-FO-001 item 9 asks one thing — "Do you pay rent for
           * occupying a place of business?" — and nothing about the lessor. The
           * four lessor fields came from the national BPLS unified form, not
           * this one, and the client removed them: "this one is also not
           * present in the paper".
           *
           * Deleting the fields and LEAVING this gate produced exactly the
           * failure that moving the block was supposed to avoid: "Enter the
           * lessor's name, or set the premises to owner-occupied" on a step
           * with no such box and no way to clear it. A gate and the control
           * that satisfies it are one thing; they are removed together or not
           * at all.
           *
           * The lessor's name and address ARE still asked — on the one paper
           * that wants them, MCG-CPDD-FO-003 items VIII.C and VIII.D, on the
           * zoning sheet, which now collects them instead of deriving them
           * from a business record nothing fills.
           */

          return missing
        }
        case 'address': {
          const missing: string[] = []
          /*
           * Item 69 — the whole Line of Business question is answered here now,
           * and these three checks are the ones the deleted `lines` step used to
           * make. Required on this step, as the mockup marks it, and not merely
           * because Location Insights wants it: the zoning note on this step
           * reads the ordinance *for a named trade*, and CPDO's locational
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
           * Products / Services, required per line from 9 September 2026.
           *
           * The PSIC code says what CATEGORY the trade falls in; this says what
           * the business actually sells, and three offices print it on their
           * paper — CENRO's form has a PRODUCTS/SERVICES box beside LINE OF
           * BUSINESS, and it reached them empty on the filing that prompted
           * this change. "Retail sale in non-specialized stores" tells a
           * sanitary inspector nothing about whether there is food on the
           * premises; "milk tea, fried snacks" tells them everything.
           *
           * Named per line, because a filing can declare several and only one
           * of them may be blank.
           */
          form.lines.forEach((line, index) => {
            if (!(line.products_services ?? '').trim()) {
              const label = psic.find((c) => c.id === line.psic_code_id)?.title
              missing.push(`Products / Services for ${label ?? `line of business ${index + 1}`}`)
            }
          })
          /*
           * "Capital for every line of business" was checked here and no longer
           * is. The question moved to Business & Tax Profile, where the `fees`
           * phase below requires it per line for a new filing (feeProfileIssues
           * → `line:<id>:capitalization`). Blocking on it twice would be the
           * duplicate wearing a different hat.
           */
          /*
           * The STREET is required; the house or building number is not.
           * Premises exist with no number of their own — a stall inside a
           * public market, a unit known only by its building's name — and the
           * paper prints a line for it without marking it required. Demanding
           * one would be our rule, not the city's.
           */
          if (!form.street.trim()) missing.push('Street')
          // Optional, so listed only when what is in it is not an area.
          if (form.lot_area_sqm.trim() && !lotAreaValid(form.lot_area_sqm)) {
            missing.push('A valid Lot Area')
          }
          // The field's own label, so the list names something on the screen.
          if (!form.barangay_id) missing.push('Barangay Name')
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
           * well as in the click handler — a safety net now rather than the
           * main defence, and it is worth saying which changed.
           *
           * This used to be the only thing catching "drop a valid pin in
           * Acacia, then change the dropdown to Tonsuya": the pin did not move,
           * so nothing re-ran onPick, and the disagreement was caught on the
           * way out of the step. Item 8 replaced that with prevention — the map
           * takes no pin until a barangay is named, and changing the barangay
           * clears the pin (see the <select>'s onChange) — so an applicant can
           * no longer reach this check by that route at all.
           *
           * It stays because a pin can still arrive without passing through
           * onPick: a renewal prefills one, and a reopened draft restores one.
           * Deleting it would leave those two paths unchecked, which is exactly
           * the hole it was written to close.
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
              missing.push(
                `A pin inside ${barangayName} (it is currently in ${verdict.actual ?? 'neither'})`,
              )
            }
          }
          /*
           * The lessor checks used to be here. They moved to the `operation`
           * case with the fields themselves on 16 September 2026 — the paper
           * asks rent in section B, so the question and its validation live on
           * the same step the applicant answers it on. Leaving the gate behind
           * would have blocked Location & Zoning on four fields that are no
           * longer on it, with nothing on screen to fix.
           */
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
          /*
           * ── An AMENDMENT is answered by its NEW DETAILS ──────────────────
           *
           * Section A does not exist on this form any more, so asking for a
           * tick would block every amendment ever filed. Worse, it briefly
           * did: the New Details block was added above a gate still demanding
           * a tick, so an applicant could fill in a floor area and be told
           * "tick at least one box" about the thing they had just typed.
           *
           * What the step needs is what the submit gate needs — at least one
           * detail with a new value — so the two ask the same question and
           * cannot disagree about whether the form is done.
           */
          if (applicationType === 'amendment') {
            if (!amendRows.some((r) => r.requested)) {
              return ['At least one new detail — say what the record should say now']
            }

            /*
             * ── A move is not stated until the pin states it ────────────────
             *
             * The application form will not leave Location & Zoning without a
             * pin that falls inside Malabon and inside the barangay named, and
             * an amendment that moves the premises is the same question asked
             * again — CPDO assesses conformity FROM the pin, and a
             * cross-barangay move re-applies for the clearance on the strength
             * of it.
             *
             * Client, 21 September 2026: *"apply the zoning validations that we
             * also have with the zoning in the application form. Make sure they
             * are equally the same."* They were not: the map was lifted without
             * any of the rules around it, so a move could be filed with the old
             * pin, no pin, or one in another city.
             *
             * Only when the BARANGAY changes. That is a different question
             * from whether the premises moved (see `amendMovesPremises`, and
             * the zoning trigger it drives): this one is simply that a pin
             * cannot stay where it was if the barangay under it is being
             * changed. Correcting how a street is spelled demands no new pin,
             * and demanding one would drag a typo into a re-clearance.
             */
            const missingMove: string[] = []

            if (amendChangesBarangay) {
              const pin = amendRows.find((r) => r.field === 'address_pin')
              const placed = (pin?.new_value ?? '').split(',')

              if (pin === undefined || !pin.requested || placed.length !== 2) {
                missingMove.push(
                  'A map pin for the new address — conformity is assessed from it',
                )
              } else if (
                checkPin(Number(placed[0]), Number(placed[1]), amendBarangayName).kind !== 'ok'
              ) {
                missingMove.push(
                  `A map pin inside ${amendBarangayName ?? 'the barangay you chose'}`,
                )
              }
            }

            return missingMove
          }

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
        /*
         * CPDD's sheet asks the applicant nothing.
         *
         * MCG-CPDD-FO-003 is almost entirely derived from the BPLO form, and
         * `officeFormMissing` says the same of it — of the two questions the
         * paper does ask, it marks neither mandatory. So the step is a
         * reading, and a gate here would block a filing on a form that
         * completes itself.
         */
        case 'zoning':
          return []
        case 'documents':
          // One file satisfies a requirement; more are allowed and change
          // nothing here. `?.length` rather than presence because a requirement
          // whose last file was removed keeps no empty array (see the remove
          // handler) — but a future edit that left one must not read as done.
          return requiredDocs
            .filter((dt) => dt.is_required !== false && !uploaded[dt.id]?.length)
            .map((dt) => dt.name)
        /*
         * Review has no fields of its OWN, and used to return nothing on that
         * basis. It draws every other section now — editable, so an applicant
         * can fix what they find — which makes it the last screen that can
         * report a gap, and the only one where the gap is fixable on the spot.
         *
         * Aggregated from the other sections rather than restated, for the
         * same reason the sections themselves are drawn rather than copied: a
         * second list of what is required would agree on the day it was
         * written and drift afterwards.
         */
        case 'review':
          return sequence.filter((step) => step !== 'review').flatMap((step) => missingFor(step))
      }

      /*
       * `p` is a Phase and the switch covers every BasePhase, but the office
       * steps are handled above and TypeScript cannot see that the two sets
       * are disjoint. Unreachable, and an empty list is the safe answer if it
       * ever is not: a step with no rule does not block a filing.
       */
      return []
    },
    [
      form,
      requiredDocs,
      uploaded,
      consent,
      feeDraft,
      /*
       * ── The office sheets' own answers ────────────────────────────────
       *
       * Read at the top of this callback (`officeFormMissing(officeCode,
       * officeData[officeCode] …)`) and missing from here, so the gate kept
       * a closure over whatever `officeData` held when it was last built.
       *
       * What that looked like: on a clearance-only renewal the applicant
       * picked a Sanitary Classification, the chip filled in — the sheet
       * reads `officeData` straight from render, so it showed the answer —
       * and the footer went on saying "Still needed on this part: Sanitary
       * Classification" with Next greyed behind it. An answer visibly given
       * and visibly not counted (client, 4 October 2026).
       *
       * The comments further down this array make the same argument twice
       * for `barangayName` and `touched.barangay_id`: anything this callback
       * READS belongs here, whether or not it happens to move in step with
       * something already listed. `officeData` moves on its own, on every
       * keystroke of every office sheet, and was the one reader left out.
       */
      officeData,
      /*
       * And the documents beside them, for the reason `officeData` is here:
       * this callback reads it, so it must recompute when it moves. An
       * attachment that satisfies a blocking row has to reopen the gate it
       * closed, and uploads land here long after the answers do.
       */
      officeReqs,
      applicationType,
      feeLines,
      psic,
      isReuse,
      prefillBusinessId,
      priorPermitId,
      renewablePermits,
      /*
       * What the `amendments` case reads on an AMENDMENT. It was missing, so
       * the gate could not see a new detail being typed: the step went on
       * reporting "At least one new detail" until some other dependency
       * happened to move and let the memo recompute.
       */
      amendRows,
      /*
       * The two the move check reads. `amendChangesBarangay` is itself memoised
       * on `amendRows`, but naming it here is what stops a later edit that
       * changes how it is derived from silently going stale.
       */
      amendChangesBarangay,
      amendBarangayName,
      /*
       * And the whole Section A object on a RENEWAL, because that case reads
       * five of its fields and the step's gate is only correct if it
       * recomputes when any of them move. Memoised on one derived boolean,
       * answering No after Yes left the old "tick at least one box" complaint
       * standing over a step that no longer asked the question.
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
    /*
     * ── BOTH kinds of draft land here, which is the whole fix ───────────
     *
     * This read `!draftIdParam` and returned, so it only ever ran for a
     * draft that already has an Application row behind it — the cards that
     * link to `/apply?draft=N`.
     *
     * An unfinished filing with no row yet is a `wizardDrafts` scratch copy
     * and its card links to `/apply?type=X&resume=N`. `draftIdParam` is
     * null for those, so the effect bailed on the first line and the
     * applicant was put back on Data Privacy Consent every single time —
     * for a NEW filing, a RENEWAL and an AMENDMENT alike, because that one
     * link shape carries all three (`type=${d.application_type}`).
     *
     * Reported 2 October 2026: "it transports me by default to the Data
     * Privacy Consent section… it should transport me to the farthest
     * section I did." The landing logic was right; it was reachable from
     * only one of the two doors.
     */
    if (landedRef.current || (!draftIdParam && resumeParam === null)) return
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

    /*
     * The scratch copy has its own load, and its own signal.
     *
     * `hydrating` only ever describes the Application-row path — it is
     * initialised `Boolean(draftIdParam)` — so for a `resume` draft it is
     * false from the first render and guards nothing. Landing then would
     * read a blank form, find part 1 unfinished, and settle there for
     * good: `landedRef` makes this a one-shot, which is the same trap the
     * comment above describes for reference data.
     */
    if (resumeParam !== null && !restoreSettled) return

    landedRef.current = true
    const firstUnfinished = stepComplete.findIndex((done) => !done)
    setStep(firstUnfinished === -1 ? sequence.length - 1 : firstUnfinished)
  }, [
    draftIdParam,
    resumeParam,
    restoreSettled,
    hydrating,
    hydrateFailed,
    refs.loading,
    stepComplete,
    sequence.length,
  ])

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
  const registrationAgencyInfo = registrationAgency
    ? REGISTRATION_AGENCIES[registrationAgency]
    : null
  /**
   * Item 1's label: the paper's own wording until the type narrows it.
   *
   * It fell back to a bare "Registration Number", which was fine while Type of
   * Registration was asked FIRST — the fallback was unreachable in practice.
   * Item 10 is asked at 10 now, as the paper asks it, so this label is what an
   * applicant sees before they have chosen, and the paper answers it exactly:
   * item 1 prints "DTI / SEC / CDA Registration Number". All three agencies
   * named, so the question is answerable in either order, and it still refines
   * to the one that applies once the type is given.
   */
  const registrationNumberLabel =
    registrationAgencyInfo?.label ?? 'DTI / SEC / CDA Registration Number'
  /*
   * Whether to put BPLO items A13-A15 at all. Gated on the Form of
   * Organization chosen at the top of the step — see hasPresidentOrOfficer,
   * which has answered "yes, for every structure" since 16 September 2026.
   * A sole proprietor IS asked, and their answer arrives prefilled from the
   * name they gave in items 10 to 13.
   */
  const presidentAsked = hasPresidentOrOfficer(form.registration_type)
  /**
   * Item 15 is an answer rather than a question.
   *
   * Client, 24 September 2026: *"In the sole proprietorship, make the name of
   * president/OIC uneditable."* A sole proprietor IS their own officer in
   * charge, and the box has filled itself from items 10 to 13 since the
   * prefill went in — it just used to invite them to change it afterwards.
   */
  const oicIsProprietor = form.registration_type === 'sole_proprietorship'

  /*
   * ── Item 13 fills itself for a sole proprietor ─────────────────────────
   *
   * The note under this box has said "this is filled in from your name
   * above" since 16 September 2026, and nothing filled it. The field
   * rendered `form.president_officer_name` raw, so a sole proprietor met an
   * empty box under a sentence claiming it was already answered — which is
   * what made item 13 read as the same question as item 11, and what the
   * client reported on 24 September.
   *
   * A WRITE, not a placeholder. The paper wants a name in box 13 and the
   * officer's sheet prints one, so a grey hint that submits nothing would
   * have fixed the appearance and left the dash — the exact failure the
   * 16 September decision was made to remove.
   *
   * ── It stops the moment the applicant types ────────────────────────────
   *
   * `oicTouched` is the whole of the care here. Without it the effect would
   * re-impose the proprietor's name over whatever they typed on the next
   * keystroke in item 11 — and the note explicitly invites them to change
   * it, because a sole proprietorship may be run day to day by somebody
   * else. A prefill that fights the person filling it in is worse than none.
   *
   * Only for a sole proprietorship. A corporation's president is a different
   * person from the account holder by definition, and seeding their name
   * there would be inventing an answer.
   */
  const proprietorFullName = useMemo(
    () =>
      [form.owner_given_name, form.owner_middle_name, form.owner_surname, form.owner_suffix]
        .map((part) => part.trim())
        .filter((part) => part !== '')
        .join(' '),
    [
      form.owner_given_name,
      form.owner_middle_name,
      form.owner_surname,
      form.owner_suffix,
    ],
  )
  useEffect(() => {
    if (!oicIsProprietor) return
    if (form.president_officer_name === proprietorFullName) return

    /*
     * Mirrored, not seeded — including back to blank while items 10 to 13
     * are still being typed. The box is read-only for a sole proprietor, so
     * there is nothing of the applicant's to overwrite and nothing to defer
     * to; a value left standing here after they cleared their surname would
     * be a name the form invented and nobody could delete.
     *
     * `oicTouched` used to guard this. It recorded "the applicant has taken
     * this box over", which stopped being a thing that can happen.
     */
    update('president_officer_name', proprietorFullName)
    // `update` is stable and `form.president_officer_name` is read only to
    // avoid a redundant write; depending on it would not change the outcome.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [oicIsProprietor, proprietorFullName])

  /**
   * Item 16 answered as Other, while the box for WHICH is still empty.
   *
   * The select has no value of its own — `form.citizenship` holds the
   * nationality and the mode is read back off it, so a reopened draft lands
   * on the right option without a second field to keep in step. That works
   * for every state but one: the instant Other is chosen the nationality is
   * cleared so it can be typed, and a blank string is indistinguishable from
   * nothing chosen. This remembers that one transition and nothing else.
   */
  const [citizenshipOther, setCitizenshipOther] = useState(false)

  /** Which option item 16's select is sitting on. */
  const citizenshipMode: '' | 'filipino' | 'other' =
    form.citizenship.trim() === '' && !citizenshipOther
      ? ''
      : !citizenshipOther && form.citizenship.trim().toLowerCase() === 'filipino'
        ? 'filipino'
        : 'other'

  /**
   * Item 17 for a sole proprietor, which is item 16 restated as a number.
   *
   * One owner, no separate juridical personality, so the capital is theirs
   * and the Filipino share can only be all of it or none of it. See the
   * reasoning on the select below.
   *
   * Blank until item 16 is actually answered — including while Other is
   * chosen and the nationality box is still empty. Writing 0 there would
   * show a foreign-owned figure to somebody who has not yet said they are
   * foreign, and 0 is a real answer, not a placeholder.
   */
  const derivedFilipinoShare =
    citizenshipMode === 'filipino'
      ? '100'
      : citizenshipMode === 'other' && form.citizenship.trim() !== ''
        ? '0'
        : ''

  useEffect(() => {
    if (!oicIsProprietor) return
    if (form.capital_participation_filipino === derivedFilipinoShare) return

    /*
     * Mirrored rather than seeded, on item 15's reasoning exactly: the box
     * is read-only for a sole proprietor, so there is nothing of the
     * applicant's to overwrite, and a figure left standing after they
     * changed item 16 would be one the form invented and nobody could
     * delete.
     *
     * Not for a corporation, partnership or cooperative — their capital is
     * pooled and the share is a real number the applicant knows and we do
     * not. That is where the 60/40 rules actually bite.
     */
    update('capital_participation_filipino', derivedFilipinoShare)
    // `update` is stable; the current value is read only to skip a
    // redundant write.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [oicIsProprietor, derivedFilipinoShare])

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
    const structureChanged = next !== form.registration_type
    setForm((f) => ({
      ...f,
      registration_type: next,
      registration_number: from !== null && from !== to ? '' : f.registration_number,
      /*
       * Item 15 is emptied on any change of structure, and the effect
       * above refills it from items 10 to 13 when the new answer is a sole
       * proprietorship. Client, 24 September 2026: *"when sole
       * proprietorship is used, the name of Pres/OIC will be autofilled by
       * the input Surname to Suffix. Otherwise, the name of Pres/OIC will
       * be blank."*
       *
       * Without this, a sole proprietor who corrected item 1 to Corporation
       * left their OWN name standing in the president's box — an answer
       * they never gave about a company officer, and one that looks
       * deliberate rather than left over.
       */
      president_officer_name: structureChanged ? '' : f.president_officer_name,
    }))
    if (from !== null && from !== to) setTouched((t) => ({ ...t, registration_number: false }))
  }

  const fieldErrors = {
    name: touched.name && !form.name.trim() ? 'Enter your business name.' : '',
    /*
     * Required since 24 September 2026, on the client's instruction. It had
     * been optional under this file's standing rule that none of the paper
     * forms marks anything required — the same rule the employee split was
     * taken out of on 9 September.
     *
     * Silent until they leave the box, like every other blank-value message
     * on this step: complaining about an empty field the moment focus lands
     * in it is telling somebody off for not having typed yet.
     */
    /*
     * Nothing is said about a blank one. It is optional, and a business
     * trading under its registered name has no second name to give — the
     * old message asked them to type the first one again.
     */
    trade_name: '',
    /*
     * Before a structure is chosen this field is read-only, and reaching it
     * says why — the client, 30 September 2026, having clicked a closed box
     * that did nothing at all. Still silent until they reach it: an error on
     * a question nobody has approached is noise.
     *
     * Once the field IS being asked, the error names that one agency rather
     * than listing all three.
     */
    registration_number: !registrationAgencyInfo
      ? touched.registration_number
        ? 'Select a Form of Organization first.'
        : ''
      : form.registration_number.trim()
        ? registrationNumberValid(form.registration_number)
          ? ''
          : /*
               Shortened twice, for the same reason each time. It began by
               naming the field and citing the certificate; on 30 September
               2026 the client cut that, leaving the four character classes
               and the digit minimum; on 3 October they cut those too. The
               label sits directly above the box and the agency's own format
               is in the placeholder — a message repeating either spends a
               line saying what is already on the screen.
            */
            'Enter a valid registration number.'
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
     * ignore the colour.
     *
     * An EMPTY TIN is no longer an error. Client, 24 September 2026: *"remove
     * TIN as required. This is optional field."* A wrong TIN is still worth
     * saying so about — a transposed digit is a different failure from a
     * deliberate blank, and only one of the two is the applicant's choice.
     */
    tin:
      touched.tin && form.tin.trim() && !tinValid(form.tin) ? TIN_ERROR : '',
    lot_area_sqm:
      form.lot_area_sqm.trim() && !lotAreaValid(form.lot_area_sqm)
        ? 'Enter the area in sq. m.'
        : '',
    /*
     * Both optional, so neither can complain about being empty — only about
     * being wrong. `phoneValid` already accepts a landline with or without its
     * area code, which is what item A6 asks for, so there is no second phone
     * rule to keep in step with the first.
     */
    telephone: form.telephone.trim() && !phoneValid(form.telephone) ? PHONE_ERROR : '',
    /*
     * Item 10 — wired, where it was not. The mobile field has existed since the
     * business gained its own contact details, but nothing here ever produced
     * an error for it, so `fieldErrors.mobile_number` was undefined and the
     * input rendered no message, no aria-invalid and no description: a field
     * that could be filled in wrongly and never said so.
     *
     * Silent until the applicant leaves the group, for TIN's reason — the boxes
     * pass through nine invalid lengths on the way to a valid one, and painting
     * them red for all nine teaches the applicant to ignore the colour.
     */
    mobile_number:
      touched.mobile_number && form.mobile_number.trim() && !mobileValid(form.mobile_number)
        ? MOBILE_ERROR
        : '',
    website:
      form.website.trim() && !websiteValid(form.website)
        ? 'Enter a valid website address.'
        : '',
    capital_participation_filipino: !percentValid(form.capital_participation_filipino)
      ? 'Enter a percentage from 0 to 100.'
      : '',
    economic_organization_others:
      touched.economic_organization_others &&
      form.economic_organization === 'others' &&
      !form.economic_organization_others.trim()
        ? 'Say what kind of establishment this is, or choose one of the five above.'
        : '',
    street: touched.street && !form.street.trim() ? 'Enter the street name.' : '',
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
        : 'Enter an amount in pesos.'
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
        // The API composes `line1` from these two — see syncAddressAndLines.
        house_bldg_no: form.house_bldg_no.trim(),
        street: form.street.trim(),
        // Sent even when blank, so clearing one is stored as the blank it is.
        block: form.block.trim(),
        lot: form.lot.trim(),
        lot_area_sqm: plainAmount(form.lot_area_sqm) || null,
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
        /*
         * BPLO items A7 and A8 — the business's own, prefilled from the account
         * but stored here, so editing one never edits a profile.
         *
         * Canonicalised to +63 on the way out, and this is the single choke
         * point for it (item 10). The control emits +63 for anything the
         * applicant types, but a renewal that never touches the field carries
         * the 09 form it was prefilled with straight from `users.mobile_number`
         * — so normalising only in the control would store two spellings of the
         * same number depending on whether anyone looked at the box.
         *
         * `business_addresses.mobile_number` is `nullable|string|max:40`
         * (BusinessController), which is a different rule from the profile
         * field's `regex:/^09\d{9}$/` in AuthController. They are different
         * facts — who owns the account, versus how the city rings the business
         * — and the profile's rule is deliberately not touched here.
         */
        mobile_number: canonicalMobile(form.mobile_number) || undefined,
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
  /**
   * The draft POST, once, however many callers reach for it at once.
   *
   * ── Why a ref and not just `applicationId` ─────────────────────────────
   *
   * `applicationId` is state. Two callers in the same tick both read null and
   * both POST, and the register holds the proof: amendment drafts #9 and #10,
   * a minute apart, both empty. The New Details effect reaches for a draft
   * because its rows are keyed to an application id, and `persistOnLeave`
   * reaches for one on the way out of the step — neither can see the other's
   * `setApplicationId` until React commits.
   *
   * The promise IS the lock, and it is kept after it resolves rather than
   * cleared: there is exactly one draft per wizard session, so a later caller
   * should get that same id even in the window before the state lands. Only a
   * FAILED create clears it, so a retry can still happen.
   */
  const draftInFlight = useRef<Promise<number> | null>(null)

  async function ensureDraftRaw(): Promise<number> {
    if (applicationId) return applicationId

    draftInFlight.current ??= createDraft().catch((err: unknown) => {
      draftInFlight.current = null
      throw err
    })

    return draftInFlight.current
  }

  async function createDraft(): Promise<number> {
    let bid = businessId ?? prefillBusinessId
    if (!bid) {
      bid = (await businesses.create(businessPayload())).id
    } else if (!clearanceOnlyRenewal && applicationType !== 'amendment') {
      await businesses.update(bid, businessPayload())
    }
    /*
     * ── A clearance-only renewal does not rewrite the business ────────────
     *
     * It never asks about it. That filing's sequence is Privacy, the office
     * sheet and Review — no Business Information, no Location & Zoning, no
     * Business & Tax Profile — so `businessPayload()` here is built entirely
     * from a prefill, and pushing it back can only ever overwrite the
     * register with a copy of itself or with something thinner.
     *
     * Thinner is what happened. The update answered 422 (the endpoint
     * requires a complete `address`, among other things), `createDraft`
     * threw, no application draft was ever created, and Submit then had
     * nothing to send — which is the "clicking Submit does not work" the
     * client reported. The press is honest about it now, but the save
     * should not have been failing in the first place.
     *
     * A NEW filing still updates: it is where the business is described.
     *
     * An AMENDMENT stopped on 5 October 2026. It was kept here because "it
     * exists to change the business", but it never changes it this way: what
     * it asks is written as `requested_changes` and applied by BPLO's
     * approval. Its sequence shows none of the business steps either, so the
     * payload was a prefill copy, and on a thin record the 422 it drew
     * stopped the draft being created at all — the same failure as above.
     */
    setBusinessId(bid)
    const app = await applications.create({
      business_id: bid,
      application_type: applicationType,
      title: title.trim() || undefined,
      data_privacy_consent: consent,
      payment_mode: paymentMode,
      permit_type_ids: form.permit_type_ids,
      ...(priorPermitIds.length > 0 ? { prior_permit_ids: priorPermitIds } : {}),
      ...(priorPermitId ? { prior_permit_id: priorPermitId } : {}),
      ...amendmentPayload(),
    })
    rememberApplicationId(app.id)
    setFiledAt(app.submitted_at ?? app.created_at)

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
     * asked. That is the exact conversion the prior-permit field was caught
     * making one field over.
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
      }
      return true
    } catch (err) {
      /*
       * A 422 here is the step being incomplete, which the form already says
       * field by field and again in "Still needed on this part". Bannering it
       * repeats one of those in the API's wording, at the top of the page,
       * about a question the applicant may not have reached — see the autosave
       * catch for the full reasoning and for which failures still surface.
       *
       * `false` is still returned either way: an unsaved step must not be
       * left, silently or otherwise, so the navigation is refused as before.
       */
      const failure = toApiError(err)
      if (failure.status !== 422) {
        setSubmitError(failure.message)
      }
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

  /*
   * Shown once per visit to the business step, not once per press.
   *
   * A warning that reappears every time is a gate wearing a warning's
   * clothes, and the TIN has been optional since BPLO said so on 24
   * September 2026.
   */
  const [tinNoticeOpen, setTinNoticeOpen] = useState(false)
  const tinNoticeSeen = useRef(false)

  async function next() {
    /*
     * A blank TIN is said once, here, on the press that skips it.
     *
     * Not a refusal — Continue advances — so this reads the gate the same
     * way the button does and only speaks after the step is otherwise
     * clear. Warning about the TIN while three other fields are still
     * empty would put a consequence in front of somebody who is not
     * leaving the step anyway.
     */
    if (
      phase === 'business' &&
      stepMissing.length === 0 &&
      !form.tin.trim() &&
      !tinNoticeSeen.current
    ) {
      tinNoticeSeen.current = true
      setTinNoticeOpen(true)

      return
    }

    /*
     * The amendment step gates on its requested changes, and the box the
     * applicant was typing in has only just blurred — so flush first and read
     * the gate off the response. Same question `missingFor('amendments')`
     * asks; asked of rows that include the save this press triggered.
     */
    if (applicationType === 'amendment' && phase === 'amendments') {
      const rows = await flushAmendments()
      if (!rows.some((r) => r.requested)) return
    } else if (stepMissing.length > 0) {
      return
    }
    // Location & Zoning used to stop here for the zoning dialog; the answer is
    // inline on the step now, so every step leaves the same way.
    await advance()
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
  /*
   * Load the amendable details when the step opens.
   *
   * Creates the draft first if there is not one yet. This step used to create it
   * on the way OUT — fine while it carried only tick boxes, and useless here:
   * these rows are keyed to an application id, so without one the applicant
   * would meet an empty panel and be told to come back later.
   */
  useEffect(() => {
    if (applicationType !== 'amendment' || phase !== 'amendments') return

    let alive = true
    void (async () => {
      setAmendError(null)
      setAmendLoading(true)
      try {
        const id = applicationId ?? (canCreateDraft ? await ensureDraftRaw() : null)
        /*
         * `id === null` is not a load in progress — it is a draft that cannot
         * be created yet, which the step has to stop claiming to be fetching.
         * `!alive` is this effect being superseded, and the run that replaced
         * it owns the flag from here.
         */
        if (id === null) {
          if (alive) setAmendLoading(false)

          return
        }
        if (!alive) return

        const rows = await applications.amendments(id)
        if (!alive) return

        setAmendValues(Object.fromEntries(rows.map((r) => [r.field, r])))
        /*
         * Seeded from what was already REQUESTED, never from the current value.
         * Prefilling each box with today's figure would have the applicant
         * asking to change every detail to what it already is, and every row
         * would read as a requested change.
         */
        setAmendTyped(Object.fromEntries(rows.map((r) => [r.field, r.new_value ?? ''])))
      } catch (err) {
        if (alive) setAmendError(toApiError(err).message)
      } finally {
        if (alive) setAmendLoading(false)
      }
    })()

    return () => {
      alive = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [applicationType, phase, applicationId])

  /**
   * Load CPDD's sheet when the Zoning step opens.
   *
   * The clearance has to exist first — the pivot row is what an upload
   * attaches to — and it does: the draft carries ZONING from the moment the
   * pin moves (see the permit-type effect), and the wizard's own autosave
   * pushes `permit_type_ids` to the server.
   *
   * Empty answers are a fine starting point. MCG-CPDD-FO-003 derives most of
   * itself from the BPLO form, so a blank sheet renders complete.
   */
  useEffect(() => {
    const needed = phase === 'zoning' ? 'ZONING' : officeStepCode(phase)
    if (needed === null || applicationId === null) return

    let alive = true
    void (async () => {
      setOfficeError(null)
      try {
        const sheets = await officeForms.list(applicationId)
        if (!alive) return

        /*
         * Every sheet on the filing, not only the one being opened. The list
         * is one request either way, and a renewal of three permits walks
         * three steps — fetching per step would make the same call three
         * times and flash an empty form at each one.
         */
        setOfficeData(
          Object.fromEntries(sheets.map((sheet) => [sheet.permit_type_code, sheet.form_data ?? {}])),
        )
        setOfficeReqs(
          Object.fromEntries(
            sheets.map((sheet) => [sheet.permit_type_code, sheet.requirements ?? []]),
          ),
        )
      } catch (err) {
        if (alive) setOfficeError(toApiError(err).message)
      }
    })()

    return () => {
      alive = false
    }
  }, [phase, applicationId])

  /**
   * Save the sheet's answers.
   *
   * Written on every change rather than on a debounce, because CPDD's sheet
   * is almost all derived: the applicant touches two controls on it, so
   * "every change" is a handful of requests across the whole step and not a
   * keystroke storm. `submit: false` — leaving the step is not filing the
   * clearance, and the API's default is the safe one.
   */
  async function saveOfficeForm(code: OfficeFormCode, next: OfficeFormData) {
    setOfficeData((all) => ({ ...all, [code]: next }))
    if (applicationId === null) return

    setOfficeError(null)
    try {
      await officeForms.save(applicationId, code, next)
    } catch (err) {
      setOfficeError(toApiError(err).message)
    }
  }

  /**
   * Attach or remove one of CPDD's required documents.
   *
   * The browser-side check runs first, for the reason `uploads.ts` gives: the
   * API's refusal of an empty PDF is "Upload a PDF, JPG, or PNG file", which
   * is true of the file and useless to the person holding it.
   */
  async function changeOfficeRequirement(
    code: OfficeFormCode,
    documentCode: string,
    file: File | null,
    /* Which file to remove; a row holds several since 30 September 2026. */
    documentId?: number,
  ) {
    if (applicationId === null) return

    setOfficeReqBusy(documentCode)
    setOfficeError(null)
    try {
      if (file !== null) {
        const rejection = fileRejection(file)
        if (rejection !== null) {
          setOfficeError(rejection)

          return
        }
      }

      const result =
        file !== null
          ? await officeForms.uploadRequirement(applicationId, code, documentCode, file)
          : await officeForms.removeRequirement(applicationId, code, documentCode, documentId)

      setOfficeReqs((all) => ({ ...all, [code]: result.requirements }))
    } catch (err) {
      setOfficeError(file !== null ? uploadErrorMessage(err) : toApiError(err).message)
    } finally {
      setOfficeReqBusy((busy) => (busy === documentCode ? null : busy))
    }
  }

  /** Section X of the CPDD paper, blank, for the applicant to have notarised. */
  async function downloadZoningDeclaration() {
    if (applicationId === null) return

    setOfficeError(null)
    try {
      await officeForms.declaration(
        applicationId,
        'ZONING',
        'locational-clearance-declaration.pdf',
      )
    } catch (err) {
      setOfficeError(toApiError(err).message)
    }
  }
  /**
   * Send one detail's new value, or withdraw it when the box is emptied.
   *
   * Per field rather than the whole set, matching the endpoint's
   * `updateOrCreate`: asking twice about one detail is a change of mind, not two
   * requests. An emptied box is a WITHDRAWAL and not a request to blank the
   * register — "I no longer want this changed" is what clearing an input means
   * to the person doing it.
   */
  /**
   * The control one amendable detail needs.
   *
   * Driven by `row.type`, which the server sends, because half of FO-003's
   * blanks stopped being free text the moment they were mapped honestly: a
   * line of business is a PSIC code the fee is computed from, a barangay is
   * the list zoning is assessed against, a pin is a point on a map. A text box
   * for any of those collects something nobody can act on.
   *
   * ── When it saves ─────────────────────────────────────────────────────
   *
   * A typed box saves on blur; a CHOSEN value saves the moment it is chosen.
   * Waiting for blur on a select or a map pin means the answer sits unsent
   * while the applicant looks at it, and the commonest next move after
   * choosing the last one is to press Continue.
   */
  function renderAmendControl(row: AmendmentRow) {
    const value = amendTyped[row.field] ?? ''
    const id = `amend-${row.field}`

    const type = (next: string) => setAmendTyped((t) => ({ ...t, [row.field]: next }))
    const choose = (next: string) => {
      type(next)
      /*
       * Sent with the value in hand rather than read back from state, which
       * this tick cannot see — the same reason `flushAmendments` returns rows.
       */
      void saveAmendment(row.field, next)
    }

    switch (row.type) {
      case 'psic': {
        /*
         * ── The application form's picker, not a dropdown ─────────────────
         *
         * This was a plain `<select>` of all 135 trades: no search, no "most
         * common" head start, no section headings, in a list where "sale"
         * alone matches 48 titles. The same question is asked on Location &
         * Zoning with a real picker, and the two must not differ — client,
         * 21 September 2026: *"if you will have to copy something, make sure
         * you do the copy properly."*
         *
         * `<PsicPicker>` is now the one control, used by both.
         */
        const chosenId = value === '' ? null : Number(value)
        const chosen = psic.find((c) => c.id === chosenId)

        return (
          <>
            <PsicPicker
              codes={psic}
              chosenId={Number.isFinite(chosenId) ? chosenId : null}
              onPick={(code) => choose(String(code.id))}
              label="Search for the trade you are changing to"
              /*
               * Distinct from Location & Zoning's picker, which is on another
               * step but in the same document: two controls sharing an id
               * would give one of them a label that points at the other.
               */
              inputId={`amend-psic-${row.field}`}
            />

            {/*
              The choice, confirmed below the box it was made in — the same
              shape Location & Zoning uses, because a picker that closes on
              selection has to say what it closed on somewhere.
            */}
            {chosen !== undefined && (
              <div className="mt-2 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 rounded-lg border border-input-border bg-royal-tint px-4 py-3">
                <span className="min-w-0">
                  <span className="block text-sm font-medium text-ink">{chosen.title}</span>
                  <span className="tnum block text-xs text-ink-secondary">{chosen.code}</span>
                </span>
                {/*
                  "Clear" and not "Remove": an empty box withdraws the request,
                  which is what the server's DELETE means. Nothing is being
                  pruned from a list.
                */}
                <button
                  type="button"
                  onClick={() => choose('')}
                  className="text-xs font-semibold text-ink-secondary underline underline-offset-2 hover:text-ink"
                >
                  Clear
                </button>
              </div>
            )}
          </>
        )
      }

      case 'barangay':
        return (
          <select
            id={id}
            value={value}
            /*
             * ── Item 8, again: a new barangay drops a CONTRADICTING pin ────
             *
             * The application form's rule, and for the application form's
             * reason: a pin that survives the change is a pin checked against
             * a question that has since been answered differently, and
             * leaving it is how a mismatch gets created after the click
             * handler has stopped looking.
             *
             * Dropped only if it DISAGREES — not unconditionally. Somebody who
             * placed the pin first and then named the barangay it is already
             * sitting in should not watch it vanish for agreeing, and that
             * order is the common one here because the map never waits for
             * the dropdown.
             */
            onChange={(e) => {
              const next = e.target.value
              choose(next)

              const pin = amendTyped.address_pin ?? ''
              if (pin === '') return

              const [pinLat, pinLng] = pin.split(',')
              const nextName = barangays.find((b) => String(b.id) === next)?.name ?? null

              if (checkPin(Number(pinLat), Number(pinLng), nextName).kind === 'ok') return

              setAmendPinError(
                `Your pin is not in ${nextName ?? 'that barangay'}, so it has been cleared. Drop a new one inside the highlighted area.`,
              )
              // Withdrawn, not just blanked on screen: an empty value IS the
              // withdrawal, which is what the server's DELETE means.
              void saveAmendment('address_pin', '')
            }}
            disabled={amendBusy}
            className={inputCls}
          >
            <option value="">Leave unchanged</option>
            {barangays.map((b) => (
              <option key={b.id} value={String(b.id)}>
                {b.name}
              </option>
            ))}
          </select>
        )

      case 'pin': {
        const [lat, lng] = value.split(',')
        const latitude = lat === undefined || lat === '' ? null : Number(lat)
        const longitude = lng === undefined || lng === '' ? null : Number(lng)

        return (
          <>
            {/*
              Which barangay the pin is being held to, and on whose say-so.

              The check was only ever announced by refusing a pin, which puts
              the rule after the mistake and makes the ORDER of this step a
              trap: the natural move is to drop the pin and then notice the
              barangay dropdown, and that order cannot work. A sentence costs
              nothing and removes the trap entirely.
            */}
            {amendBarangayName !== null && (
              <p className="mb-2 text-xs leading-relaxed text-ink-secondary">
                {amendBarangay.chosen ? (
                  <>
                    Pin inside <span className="font-semibold text-ink">{amendBarangayName}</span>,
                    the barangay you are moving to — the highlighted area.
                  </>
                ) : (
                  <>
                    Pinning inside <span className="font-semibold text-ink">{amendBarangayName}</span>
                    , where your business is now. Moving to another barangay? Choose it above
                    first, then drop the pin.
                  </>
                )}
              </p>
            )}

            <MapPicker
              latitude={Number.isFinite(latitude) ? latitude : null}
              longitude={Number.isFinite(longitude) ? longitude : null}
              highlightBarangay={amendBarangayName}
              /*
               * ── The same guards the application form applies ─────────────
               *
               * `checkPin` and nothing of its own, because there is one set of
               * rules about where a pin in Malabon may go and two screens that
               * drop one. Client, 21 September 2026: *"apply the zoning
               * validations that we also have with the zoning in the
               * application form. Make sure they are equally the same."*
               *
               * They were not. This map was lifted from Location & Zoning
               * without the two refusals around it, so an amendment could pin
               * its new address in Caloocan, save it, and reach BPLO — on the
               * one filing whose whole point is moving the premises, and whose
               * approval re-applies for a zoning clearance against the pin.
               *
               * The wording is the application form's, word for word, so the
               * same mistake reads the same way wherever it is made.
               */
              onPick={(pickedLat, pickedLng) => {
                const verdict = checkPin(pickedLat, pickedLng, amendBarangayName)

                if (verdict.kind === 'outside-city') {
                  setAmendPinError(
                    `That point (${pickedLat}, ${pickedLng}) is outside Malabon, so we can’t use it. Zoom in on your street within the city and click there.`,
                  )

                  return
                }

                if (verdict.kind === 'wrong-barangay') {
                  /*
                   * "your new address is in X" is only true when they picked
                   * X. Otherwise X is the register's, and the honest sentence
                   * names it as such and points at the dropdown that changes
                   * it — which is the thing they actually wanted.
                   */
                  const whose = amendBarangay.chosen
                    ? `the barangay you are moving to (${amendBarangayName})`
                    : `${amendBarangayName}, where your business is recorded`

                  setAmendPinError(
                    verdict.actual !== null
                      ? `That pin is in ${verdict.actual}, but it needs to be in ${whose}. Move it into the highlighted area — or, if you are moving to ${verdict.actual}, choose that barangay above first.`
                      : `That pin is about ${verdict.metres} m outside ${whose}. Move it into the highlighted area, or choose a different barangay above.`,
                  )

                  return
                }

                setAmendPinError(null)
                choose(`${pickedLat},${pickedLng}`)
              }}
            />
            {amendPinError !== null && (
              <p
                role="alert"
                className="mt-2 rounded-md border border-s-red bg-s-red-tint px-3 py-2 text-sm text-ink"
              >
                {amendPinError}
              </p>
            )}
            {value !== '' && (
              <button
                type="button"
                onClick={() => {
                  setAmendPinError(null)
                  choose('')
                }}
                className="mt-2 text-xs font-semibold text-ink-secondary underline underline-offset-2 hover:text-ink"
              >
                Clear the pin
              </button>
            )}
          </>
        )
      }

      case 'note':
        return (
          <textarea
            id={id}
            value={value}
            onChange={(e) => type(e.target.value)}
            onBlur={() => void saveAmendment(row.field)}
            placeholder="leave blank if there is nothing to add"
            maxLength={1000}
            rows={3}
            disabled={amendBusy}
            className={inputCls}
          />
        )

      default:
        return (
          <input
            id={id}
            value={value}
            onChange={(e) => type(e.target.value)}
            onBlur={() => void saveAmendment(row.field)}
            placeholder="leave blank if unchanged"
            /*
             * `inputMode` and not `type="number"`: a number input drops what
             * it cannot parse mid-edit, so a half-typed decimal disappears as
             * the applicant types it. The server validates the value.
             */
            inputMode={
              row.type === 'integer' ? 'numeric' : row.type === 'number' ? 'decimal' : undefined
            }
            maxLength={255}
            disabled={amendBusy}
            className={inputCls}
          />
        )
    }
  }

  async function saveAmendment(field: string, chosen?: string): Promise<void> {
    await saveAmendmentRaw(field, chosen)
  }

  /**
   * As `saveAmendment`, but hands back the rows the server answered with.
   *
   * The caller needs them because React state is not readable within the tick
   * that set it, and the step's gate is computed FROM these rows — see
   * `flushAmendments`. Null means nothing was sent.
   */
  async function saveAmendmentRaw(
    field: string,
    /*
     * The value, when the caller already has it. A select and a map pin set
     * state and save in the same handler, and state is not readable in the
     * tick that set it — without this the save would send the value BEFORE
     * the one just chosen, which is the classic off-by-one-keystroke bug and
     * silently sends the wrong answer rather than failing.
     */
    chosen?: string,
  ): Promise<AmendmentRow[] | null> {
    const typed = (chosen ?? amendTyped[field] ?? '').trim()
    /*
     * No draft to write to. This returned null without a word, and the
     * header went on claiming the answer was saved (tester, 5 October 2026).
     * Said only when there is something to lose: `flushAmendments` walks
     * every row, typed or not.
     */
    if (applicationId === null) {
      if (typed !== '') setAmendError('Not saved: the draft could not be created.')

      return null
    }

    const row = amendRows.find((r) => r.field === field)
    // Untouched and never requested: nothing to send.
    if (row !== undefined && !row.requested && typed === '') return null
    // Unchanged since the last save.
    if (row !== undefined && (row.new_value ?? '') === typed) return null

    setAmendBusy(true)
    setAmendError(null)
    try {
      const rows =
        typed === ''
          ? await applications.removeAmendment(applicationId, field)
          : await applications.setAmendments(applicationId, [{ field, new_value: typed }])
      /*
       * No per-field "Saved" line any more. It appeared under whichever box
       * had just blurred and said the same thing every time — client,
       * 21 September 2026: *"Remove this text. No need for this to appear."*
       *
       * Nothing is lost by it going: the value stays in the box, the section
       * summary lists what has been asked for, and Review names every
       * requested change before submission. A confirmation that repeats what
       * the screen already shows is noise on a form with seventeen fields.
       *
       * `amendSaved` went with it rather than being left as a state nothing
       * reads.
       */
      setAmendValues(Object.fromEntries(rows.map((r) => [r.field, r])))

      return rows
    } catch (err) {
      setAmendError(toApiError(err).message)

      return null
    } finally {
      setAmendBusy(false)
    }
  }

  /**
   * Send every box whose contents differ from what the server holds.
   *
   * ── Why Continue cannot just read the gate ─────────────────────────────
   *
   * Each detail saves on blur, and clicking Continue IS the blur — so the POST
   * is still in flight when the click handler runs, and `stepMissing` still
   * says nothing has been asked for. The press did nothing, visibly, with no
   * message to explain it; a second press would have worked, which is not a
   * thing an applicant can be expected to discover.
   *
   * Returns the rows to gate on, so the answer comes from the response rather
   * than from state this tick cannot see.
   */
  async function flushAmendments(): Promise<AmendmentRow[]> {
    let rows = amendRows

    for (const row of amendRows) {
      const sent = await saveAmendmentRaw(row.field)
      if (sent !== null) rows = sent
    }

    return rows
  }

  /*
   * ── A clearance-only renewal is gated on what it actually asks ────────
   *
   * The chain below is the NEW application's bar: a registration number, a
   * TIN that parses, at least one line of business, a street, a barangay,
   * lessor details. Every one of those is collected on Business Information,
   * Location & Zoning or Business & Tax Profile — and a clearance-only
   * renewal's sequence is Privacy, the office sheet, Review. It shows none
   * of those steps.
   *
   * So for any business whose registry record is thin in one of those
   * fields, the gate never opened, no draft was ever created, and
   * `submit()` returned on `!applicationId` without a word. The applicant
   * pressed "Yes, submit" and nothing happened, with no step they could
   * visit to supply what was missing — the form never asks (client,
   * 4 October 2026).
   *
   * What this filing really needs is the business it is against and the
   * permit it renews, both settled in the entry dialog before the wizard
   * opens. The API agrees: `fee_profile` is nullable throughout and
   * `ApplicationController::store` asks only for the business and the
   * permits, so nothing below was ever a server requirement.
   */
  /*
   * ── An amendment is gated the same way, and only once the dialog is done ──
   *
   * It ran the new-filing chain below, lessor clause and all. A rented
   * business whose record holds no lessor failed it, no draft was ever
   * written, and New Details read "not recorded" under every CURRENT value
   * while the header said All Changes Saved (tester, 5 October 2026). The
   * amendment never asks any of those questions — a thin business record is
   * the record's problem, not this filing's — so it needs what the
   * clearance-only renewal needs: the business and its permit.
   *
   * `identify === null` is the applicant having pressed Continue. Opening the
   * dialog and backing out left blank "2026 Amendment" drafts behind; nothing
   * is written until the dialog has been answered.
   */
  const canCreateDraft = clearanceOnlyRenewal || applicationType === 'amendment'
    ? prefillBusinessId !== null && form.permit_type_ids.length > 0 && priorPermitAnswered
      && (applicationType !== 'amendment' || identify === null)
    : form.permit_type_ids.length > 0 &&
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
    /*
     * Blank is allowed through; wrong is not. `tinValid('')` is false — the
     * pattern needs at least one character — so an optional field would have
     * silently blocked every draft without the first clause.
     */
    (form.tin.trim() === '' || tinValid(form.tin)) &&
    form.lines.length > 0 &&
    form.street.trim() !== '' &&
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
     * ── An amendment's draft is written as soon as a business is chosen ────
     *
     * This required the Section A ticks, and went on requiring them after the
     * ticks were removed, so the condition was permanently false and no
     * amendment draft was ever created. That was the worse half of the bug:
     * New Details loads its rows against an application id, so with no draft
     * the step stayed empty and could never be completed — the dialog's own
     * refusal was merely the first door.
     *
     * Nothing replaces it. The business is settled in the dialog before the
     * wizard opens, which is all a draft needs, and what is being amended is
     * asked on the step that writes it. An amendment no longer reaches this
     * chain at all since 5 October 2026 — see the note above the ternary.
     */
    true

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
        /*
         * Not on a clearance-only renewal, for the reason `createDraft`
         * gives: that filing never asks about the business, so this would
         * push a prefill-derived copy back over the register on every save.
         * It answered 422 there and failed the autosave with it.
         */
        // Nor on an amendment, for the reason `createDraft` gives.
        const bid = clearanceOnlyRenewal || applicationType === 'amendment'
          ? null
          : businessId ?? prefillBusinessId
        /*
         * ── A refused business write no longer takes the filing's answers down ──
         *
         * This was a bare await ahead of the application update, so a 422 on
         * the business (a thin record, a renewal's prefill) threw before the
         * consent tick was ever sent, and the 422 is swallowed below — the
         * draft reopened on Part 1, unticked (tester, 5 October 2026). Still
         * written first, because `syncLineCapitalization` reads the business's
         * lines, and its failure is still the save's failure; it just no
         * longer stops the half that would have succeeded.
         */
        let businessFailure: unknown = null
        if (bid) {
          try {
            await businesses.update(bid, businessPayload())
          } catch (err) {
            businessFailure = err
          }
        }
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
          /*
           * On every autosave, not only on creation. The tick can be given —
           * and taken back — at any point while the draft is open, and it is on
           * step 1 of seven, so a draft created later in the flow would
           * otherwise be created with `false` and never corrected.
           */
          data_privacy_consent: consent,
          /*
           * On every autosave, for the reason the consent tick is: the picker
           * is on the documents step, and a draft created before the applicant
           * reaches it would otherwise keep the default for good.
           */
          payment_mode: paymentMode,
          zoning_facts: zoningFacts,
          // Items 82/84: what is being amended can change while the draft is
          // open, so it rides on every autosave, not only on creation.
          ...amendmentPayload(),
        })
        // Which permit is being renewed can change after the draft exists, and
        // it is not part of the general application update (item 50).
        if (isReuse) {
          await applications.setPriorPermit(id, priorPermitId, priorPermitIds)
        }
        if (businessFailure !== null) throw businessFailure
      } else {
        /*
         * The tick rides here too. `ensureDraftRaw` may hand back a create
         * another caller started before the box was ticked, carrying the
         * `false` of that moment — and this save then marks the tick as sent.
         */
        await applications.update(id, {
          fee_profile: feeProfile,
          zoning_facts: zoningFacts,
          data_privacy_consent: consent,
        })
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
      const failure = toApiError(err)

      /*
        ── A 422 from AUTOSAVE is not news ─────────────────────────────────

        This banner printed every validation error the API raised while the
        applicant was still typing. Opening the form and reaching the second
        field was enough: autosave fires, the API refuses a business with no
        registration number, and a red bar appears at the top of the page
        saying "Enter your DTI Business Name registration number." — above a
        field already saying the same thing in its own words, about a
        question they had not reached yet.

        Client, 24 September 2026: *"These warnings are redundant. Remove the
        one at the top."*

        Dropped rather than reworded, because a 422 here carries nothing the
        screen is not already saying better: `fieldErrors` names each box
        under the box itself, and "Still needed on this part" lists them all
        by number above Continue — which is disabled until they are answered,
        so an incomplete step cannot be submitted regardless.

        Only 422. Everything else still surfaces, and has to: a 401 means
        their session went, a 403 that the business was suspended under them,
        a 5xx or a network failure that their typing is not being saved at
        all. Those are invisible without this banner, and the draft stays
        dirty either way so the next edit retries.
      */
      if (failure.status !== 422) {
        setSubmitError(failure.message)
      }
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
        applicationType,
        priorPermitId,
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
        zoningFacts,
      }),
    [title, form, feeDraft, applicationType, priorPermitId, amendment, consent, zoningFacts],
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
  }, [
    snapshot,
    autosaveNonce,
    applicationId,
    canCreateDraft,
    hydrating,
    refs.loading,
    tracking,
    hydrateFailed,
  ])

  /*
   * ── Give back what was typed before the draft could exist ─────────
   *
   * Once, on a fresh filing that has nothing in it yet. Not for a
   * reopened draft — that one has a server copy, which outranks this —
   * and not while the hydration of one is still running.
   *
   * ── Every filing type, since 3 October 2026 ──────────────────────
   *
   * This and its saving twin were both gated on `applicationType ===
   * 'new'`, which is the whole of the client's report: a renewal or an
   * amendment was never written to `wizardDrafts` at all, so it never
   * reached the Drafts list, ticking Data Privacy saved nothing, and
   * there was no row to reopen at the furthest section. Every rule the
   * new permit has about drafts was a rule only the new permit had.
   *
   * Nothing here was specific to a new filing; the gate was the scope
   * the feature was built in. What a renewal needs ON TOP is the entry
   * dialog's answer, which `DraftBackup.identity` now carries.
   */
  useEffect(() => {
    if (backupRestoredRef.current) return
    /*
     * `!resumeParam` — a fresh start reads nothing back. Without it the
     * only button labelled "new" could not produce a new form.
     */
    if (!resumeParam) {
      /*
       * Still open the save gate. It exists to stop an empty form being
       * written over answers that are mid-restore, and on a fresh start
       * there are none — so leaving it shut would mean a blank form
       * never saves anything, which is the deadlock all over again.
       */
      setRestoreSettled(true)

      return
    }
    if (draftIdParam || applicationId || hydrating) return
    backupRestoredRef.current = true

    /*
     * ── Rebuilt on EMPTY, never applied wholesale ────────────────────
     *
     * A saved payload is data: it has been through a database, it may have
     * been written by an older build of this form, and the one the client
     * hit held `null` for twenty-four of forty keys. `EMPTY` gives every
     * one of those `''`, and dozens of reads — `form.house_bldg_no.trim()`
     * among them — rely on that. Applying the payload straight put a null
     * where a string belonged and took the whole wizard to a blank page.
     *
     * So: start from the empty form and lay the payload's real answers
     * over it. A key that is null, undefined or absent keeps the empty
     * value; a key this build has never heard of is dropped, because
     * `EMPTY` decides the shape rather than the stored copy.
     */
    const rebuild = <T extends object>(empty: T, saved: unknown): T => {
      if (saved === null || typeof saved !== 'object') return empty
      const from = saved as Record<string, unknown>
      const out = { ...empty } as Record<string, unknown>
      for (const key of Object.keys(empty)) {
        const value = from[key]
        if (value !== null && value !== undefined) out[key] = value
      }

      return out as T
    }

    const apply = (backup: DraftBackup) => {
      setTitle(typeof backup.title === 'string' ? backup.title : '')
      /*
       * And whether that title was the applicant's own choice.
       *
       * Without this the name came back and was then taken away again: the
       * suggestion effect overwrites the title while `titleEdited` is false,
       * and the business name arrives a moment after the restore. The box
       * showed the saved name, then the shop's.
       */
      setTitleEdited(backup.titleEdited === true)
      setForm(rebuild(EMPTY, backup.form))
      setFeeDraft(rebuild(EMPTY_FEE_PROFILE, backup.feeDraft))
      setConsent(backup.consent === true)

      /*
       * ── And what the filing is a renewal OF ────────────────────────
       *
       * Only when the saved copy names a business. A renewal resumed
       * without one has not answered the entry dialog yet — it was
       * abandoned on the dialog itself, or written by a build before
       * this field existed — and `identify` below keeps the dialog up
       * for exactly that case. Writing a null here instead would close
       * the dialog over a renewal that names no permit, which is the
       * one state the entry dialog exists to prevent.
       */
      const saved = backup.identity
      if (saved && saved.businessId !== null) {
        setPrefillBusinessId(saved.businessId)
        setPriorPermitId(saved.priorPermitId ?? null)
        setPriorPermitIds(Array.isArray(saved.priorPermitIds) ? saved.priorPermitIds : [])
        setAmendment(rebuild(EMPTY_AMENDMENT, saved.amendment))
        setIdentify(null)

        /*
         * The permit list, which is not in the payload and is not derived
         * from it. The summary on Business Information names the permits
         * this filing carries forward by reading `renewablePermits`, and
         * the Change dialog lists them from the same place — so without
         * this a resumed renewal shows its ticked ids against nothing and
         * reads as a filing that has lost its permits.
         *
         * `loadRenewablePermits` and not the full prefill: that one would
         * pull the registry's copy of the business back over the answers
         * being restored in the lines above. It is the same call a
         * reopened server draft makes, for the same reason.
         *
         * Not awaited and its failure is swallowed there: the list feeds
         * the body of the form, where empty reads as "not chosen yet" and
         * the applicant is told to press Change.
         */
        void loadRenewablePermits(saved.businessId, applicationType as 'renewal' | 'amendment')
      } else if (isReuse) {
        /*
         * Resumed, but the saved copy names no business — abandoned on the
         * dialog itself, or written before `identity` existed. Ask again,
         * because a renewal that names no permit is renewing nothing.
         */
        setIdentify('entry')
      }
    }

    let cancelled = false
    /*
     * Did this run get as far as opening the gate?
     *
     * Not the same question as `cancelled`. That one asks whether this run
     * was abandoned; this asks whether the work it was responsible for
     * actually happened, which is what decides whether the ref may stay
     * claimed.
     */
    let done = false
    void (async () => {
      let from: 'server' | 'tab' | null = null

      /*
       * The SERVER copy outranks the tab's. Where the two differ the
       * server's is the newer, because the tab copy only ever existed in
       * the tab that wrote it — a laptop closed on step two and reopened
       * anywhere else has nothing else to come back to.
       */
      try {
        const saved = resumeParam === null ? null : await wizardDrafts.get(resumeParam)
        const payload = saved?.payload as DraftBackup | undefined
        /*
         * The id comes off the URL, so `?type=renewal&resume=<a new
         * permit's id>` is a link anyone can type or an old bookmark can
         * hold. Reading the row's own type rather than trusting the query
         * keeps a new permit's answers out of a renewal form.
         */
        const sameKind = saved?.application_type === applicationType
        if (!cancelled && saved && sameKind && payload && payload.v === DRAFT_BACKUP_VERSION) {
          apply(payload)
          /*
           * Adopted, so every later save goes back to the row this form was
           * opened from rather than starting another one beside it.
           */
          scratchIdRef.current = saved.id
          from = 'server'
        }
      } catch {
        /*
         * Offline, or the session has gone. The tab copy below is exactly
         * the case this fallback exists for, so this is not worth a
         * message — and an error toast on opening a blank form would be
         * alarming about nothing.
         */
      }

      if (!cancelled && from === null) {
        /* `readBackup` already refuses a slot of another type. */
        const backup = readBackup()
        if (backup) {
          apply(backup)
          from = 'tab'
        }
      }

      if (cancelled) return

      /*
       * Nothing came back at all — a `resume` id that is gone, or a row
       * belonging to somebody else. On a renewal or an amendment that
       * leaves a form with no business behind it and, since the dialog no
       * longer opens on the way in, nothing on screen to fix that with.
       * So it opens here, which is the state a fresh /apply?type=renewal
       * would have been in.
       */
      if (isReuse && from === null) setIdentify('entry')

      done = true
      /*
       * Nothing is said about `from`. Which copy the answers came back
       * from is this component's business, not the applicant's — see the
       * note where the recovery banner used to be.
       */
      /*
       * Set whether or not anything came back, and after the writes above
       * so they land first. This is the signal the save effect waits for —
       * until it flips, that effect must not write, because the form it
       * would be reading is the empty one still on screen.
       */
      setRestoreSettled(true)
    })()

    return () => {
      cancelled = true
      /*
       * Hand the claim back if this run never opened the gate, or the next
       * mount sees a ref that says "already restored" and returns without
       * ever setting `restoreSettled` — which leaves the save effect
       * refusing to write for the life of the page. StrictMode does
       * exactly this on every mount in development, and it is how the
       * client found that ticking Data Privacy saved nothing at all.
       */
      if (!done) backupRestoredRef.current = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [draftIdParam, applicationId, hydrating, applicationType, resumeParam])

  /*
   * Written on every edit while the server still has nothing, and dropped
   * the moment it has something — from then on the draft row is the copy
   * that matters, and a stale duplicate in the tab could only ever be
   * restored OVER newer work.
   */
  useEffect(() => {
    if (hydrating || hydrateFailed) return
    if (applicationId) {
      /*
       * There is a real draft now, so both scratch copies are not just
       * redundant but dangerous: restored later they would go OVER newer
       * work. The server delete is idempotent and its failure is ignored —
       * a scratch row that outlives its draft is never read, because the
       * restore only runs when there is no draft to open.
       */
      clearBackup()
      const scratchId = scratchIdRef.current
      if (scratchId !== null) {
        scratchIdRef.current = null
        void wizardDrafts.discard(scratchId).catch(() => {})
      }

      return
    }
    /*
     * Never before the restore above has landed.
     *
     * Effects run in declaration order, so on the very first commit this one
     * fires with the form still EMPTY — after the restore has READ the backup
     * but before its state update has rendered. Writing then would put that
     * empty form straight over the backup, and although the next render puts
     * the restored answers back, a tab killed inside that window loses the
     * very thing this exists to keep.
     *
     * `restoreSettled` and not `backupRestoredRef` for exactly this: the ref
     * is already true by the time this runs in that first commit.
     */
    if (!restoreSettled) return

    /*
     * Nothing is written until something changes. The first run records
     * the form as opened; every later one compares against it, so a blank
     * form that stays blank never touches the saved row, and a resumed one
     * does not rewrite itself with what it has just read.
     */
    if (openedSnapshotRef.current === null) {
      openedSnapshotRef.current = snapshot

      return
    }
    if (openedSnapshotRef.current === snapshot) return
    /*
     * Not while the amendment's entry dialog is still up. The title names
     * itself ("2026 Amendment") the moment the form opens, which counted as a
     * change, so opening the dialog and backing out left a blank draft in the
     * list every time (tester, 5 October 2026). Nothing is worth keeping
     * until Continue — and after it the real draft is created at once
     * (`canCreateDraft`), so a scratch row would only race it and could be
     * left behind as a second card for the same filing.
     */
    if (applicationType === 'amendment' && (identify !== null || canCreateDraft)) return

    /*
     * What the entry dialog was told. Undefined on a new permit, which has
     * no such dialog and no such answer — an empty object there would be a
     * claim that one was asked and answered with nothing.
     */
    const savedIdentity: DraftBackup['identity'] = isReuse
      ? {
          businessId: prefillBusinessId,
          priorPermitId,
          priorPermitIds,
          amendment,
        }
      : undefined

    try {
      sessionStorage.setItem(
        draftBackupKey(applicationType),
        JSON.stringify({
          v: DRAFT_BACKUP_VERSION,
          at: new Date().toISOString(),
          applicationType,
          title,
          form,
          feeDraft,
          consent,
          identity: savedIdentity,
          titleEdited,
        } satisfies DraftBackup),
      )
    } catch {
      /*
       * Private mode, a full quota, or storage switched off. The form
       * keeps working exactly as it did before this existed; there is
       * nothing to warn about that the "Not saved yet" indicator is not
       * already saying.
       */
    }

    /*
     * ── And to the server, debounced ──────────────────────────────────
     *
     * The copy that actually answers the client's complaint: it survives
     * the tab, reaches another device, and puts the filing in the drafts
     * list before the API would accept a business.
     *
     * Debounced because `snapshot` changes on every keystroke batch and
     * this is a write, not a read. The timer is cleared on the next change,
     * so a burst of typing costs one request at the end of it.
     *
     * Failures are swallowed on purpose. The tab copy above has already
     * landed, the "Not saved yet" indicator is already telling the truth,
     * and a toast on every keystroke of a flaky connection would be worse
     * than the silence.
     */
    const timer = window.setTimeout(() => {
      const body = {
        title: title.trim() || null,
        payload: {
          v: DRAFT_BACKUP_VERSION,
          at: new Date().toISOString(),
          applicationType,
          title,
          form,
          feeDraft,
          consent,
          identity: savedIdentity,
          titleEdited,
        } satisfies DraftBackup as unknown as Record<string, unknown>,
      }

      /*
       * The first change creates the row; everything after writes to it.
       * Writing to "this user's unfinished filing of this type" is what
       * made three starts collapse into one.
       */
      void (async () => {
        try {
          const id = scratchIdRef.current
          if (id === null) {
            const created = await wizardDrafts.create({
              application_type: applicationType,
              ...body,
            })

            /*
             * ── The real draft may have arrived while this was in flight ──
             *
             * The effect above throws the scratch row away the moment
             * `applicationId` appears. If that happened DURING this create,
             * it read `scratchIdRef.current` as null, found nothing to
             * discard, and returned — and the line below would then store
             * the id of a row nobody will ever clean up.
             *
             * It is not hypothetical and it is not rare on a renewal, where
             * the business already exists so the real draft is created early
             * while this sits out an 800ms debounce. Both rows then show on
             * the Drafts page under one name, and the page numbers them
             * "(1)" having noticed the clash: one filing, drawn twice, and
             * the applicant cannot tell which is theirs. Caught by
             * `renewal-drafts.spec.ts`, whose locator matched two cards.
             *
             * `applicationIdRef` and not `applicationId`: this closure was
             * built before the await and holds whatever the state was then,
             * which is exactly the value that is out of date.
             */
            if (applicationIdRef.current !== null) {
              void wizardDrafts.discard(created.id).catch(() => {})

              return
            }

            scratchIdRef.current = created.id
          } else {
            await wizardDrafts.save(id, body)
          }

          /*
           * The server now holds exactly what `snapshot` described when this
           * timer was set. That is what lets the indicator stop saying "Not
           * saved yet" over answers that are demonstrably saved — and why it
           * records the snapshot rather than a flag: by the time this
           * resolves the applicant may have typed more, and those keystrokes
           * are genuinely not saved.
           */
          setScratchSavedSnapshot(snapshot)
        } catch {
          /*
           * Swallowed on purpose. The tab copy has already landed, the
           * "Not saved yet" indicator is telling the truth, and a toast on
           * every keystroke of a flaky connection is worse than silence.
           */
        }
      })()
    }, 800)

    return () => window.clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    snapshot,
    applicationId,
    applicationType,
    hydrating,
    hydrateFailed,
    restoreSettled,
    /*
     * The entry dialog's answer is not in `snapshot`, so without these a
     * renewal whose ticked permits changed and whose form did not would
     * save the old set — and resume against the wrong permits.
     */
    isReuse,
    prefillBusinessId,
    priorPermitId,
    priorPermitIds,
    amendment,
    // The amendment's write waits on the dialog closing; see the early return.
    identify,
    canCreateDraft,
    /*
     * Nor is this. It moves with `title` today, which is in `snapshot`, so
     * leaving it out would work by luck — the same luck the note on
     * `barangayName` in `missingFor` declines to rely on, and for the same
     * reason: a reader who later sets the flag from somewhere else would
     * have no way to know this depended on it.
     */
    titleEdited,
  ])

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
       * The fee draft lives outside `form`, so Clear All on this step has to
       * reach into it as well — otherwise the button clears the fields around
       * them and leaves answers standing, which is the bug the privacy branch
       * below documents.
       *
       * All of it now, not just section B's four figures. The Tax
       * Classification & Fees step used to clear the rest, and this step is
       * where the rest is asked since that step was removed on 16 September
       * 2026 — so leaving the per-line classification, the gross sales and the
       * business flags behind would be the same half-clear, one field further
       * down the page.
       */
      setFeeDraft(EMPTY_FEE_PROFILE)
    } else if (phase === 'address') {
      // The lines of business are inputs of this part now (item 69), so
      // "clear all inputs for this part" has to take them with it.
      setForm((f) => ({
        ...f,
        lines: [],
        house_bldg_no: '',
        street: '',
        block: '',
        lot: '',
        lot_area_sqm: '',
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
    /*
     * ── Never a silent no-op ──────────────────────────────────────────
     *
     * This was a bare `return`. With no draft on the server there is
     * nothing to submit, which is true — but the applicant had pressed
     * "Yes, submit" on a confirmation dialog and got no page change, no
     * error and no explanation (client, 4 October 2026). A button that
     * does nothing and says nothing is indistinguishable from a broken one,
     * and they reported it as exactly that.
     *
     * The draft is created by `autosave`, which only runs once
     * `canCreateDraft` is satisfied. So reaching here means a save has not
     * landed — a failed request, or answers the gate still wants — and
     * either way the applicant needs telling rather than ignoring.
     */
    if (!applicationId) {
      setSubmitError(
        'This application has not been saved yet, so there is nothing to submit. ' +
          'Check your connection and try again — if it keeps happening, tell BPLO.',
      )

      return
    }
    setSaving(true)
    setSubmitError(null)
    setNeedsEmailCode(false)
    try {
      /*
       * A returned filing RESUBMITS. `submit` is draft-only on the API and
       * would answer 422 here; `resubmit` is the transition that exists for
       * this — returned → for_approval — and is what puts the filing back
       * in front of BPLO for another Approve or Return.
       */
      const app = returnedPhases.length > 0
        ? await applications.resubmit(applicationId)
        : await applications.submit(applicationId)
      setTracking(app.tracking_id)
    } catch (err) {
      const apiError = toApiError(err)
      if (apiError.reason === 'email_unconfirmed') setNeedsEmailCode(true)
      else setSubmitError(apiError.message)
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
        /*
         * Drafts and RETURNED filings only. Everything else is with an
         * office and is read-only to the applicant, so it goes to the
         * status page rather than opening an editor over it.
         *
         * `returned` was excluded until 28 September 2026, which made the
         * section half of the targeted return unreachable — see the note at
         * the head of this patch, and the matching guard in
         * ApplicationController::update.
         */
        if (app.status !== 'draft' && app.status !== 'returned') {
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
        rememberApplicationId(app.id)
        setFiledAt(app.submitted_at ?? app.created_at)
        /*
         * ── Which sections BPLO ticked, when it returned this ───────────
         *
         * Read once at hydration rather than kept live: the applicant is
         * mid-correction from here on, and a list that changed under them
         * would move the steps out from under the one they are on.
         *
         * Empty for a draft, and empty for a return that named only scalar
         * fields — those are corrected on the status page and never open
         * this wizard at all. Empty means no restriction, so a draft is
         * unaffected.
         */
        setReturnedPhases(
          app.status === 'returned'
            ? Array.from(
                new Set(
                  app.assignments
                    .flatMap((a) => mainFormTargets(a.remarks_target))
                    .filter((t) => t.kind === 'section')
                    .map((t) => t.phase)
                    .filter((p): p is string => p !== undefined),
                ),
              )
            : [],
        )
        /*
         * ── Historical note, kept ───────────────────────────────────────
         *
         * This read the sections BPLO ticked and restricted the wizard to
         * them. It was dead code from the moment it was written: the guard
         * twenty lines up redirects anything that is not a DRAFT to the
         * status page, and `PUT /applications/{id}` refuses the same
         * ("Only draft applications can be edited"). TypeScript said so —
         * comparing the narrowed `'draft'` against `'returned'` has no
         * overlap — and the error went unseen because the typecheck being
         * run pointed at a solution file that compiles nothing.
         *
         * So section targets currently have NO route for the applicant: the
         * status page can only correct scalar fields, and the link it offers
         * to this wizard bounces straight back. Opening `returned` for
         * editing is the fix and it needs the API rule relaxed with it, which
         * is a decision rather than a patch. `returnedPhases` and the
         * `sequence` branch that reads it are left in place, correct and
         * unreachable, so that fix is a guard change rather than a rewrite.
         */
        setTitle(app.title ?? '')
        // A draft that arrives already named was named by somebody. Treat that
        // as the applicant's own words and stop generating over it, even if the
        // text happens to match what we would have produced.
        setTitleEdited(Boolean(app.title?.trim()))
        /*
         * Reopened as it was left. Without this the picker resets to annual and
         * the next autosave writes that over a quarterly election the applicant
         * made — the draft losing an answer silently, which is the failure the
         * amendment ticks above were restored to avoid.
         */
        if (app.payment_mode) setPaymentMode(app.payment_mode)
        setBusinessId(b.id)
        if (app.application_type !== 'new') setPrefillBusinessId(b.id)
        /*
         * Items 82/84 — restore what the applicant said they were amending.
         * Without this the boxes reopen blank and the next autosave writes
         * that blank over the answer, which is the draft losing it silently.
         */
        setAmendment(
          /*
           * Amendments only, since 9 September 2026. A renewal no longer asks
           * section A at all, so restoring its old answers would put state
           * behind a step that is not in its sequence — and the API writes
           * those columns back to false on every renewal save, so what came
           * back here would be a stale copy of something already cleared.
           *
           * Renewal drafts saved BEFORE that change may still carry ticks. They
           * are deliberately not restored and not re-shown: the applicant edits
           * whatever changed on the ordinary steps now, which is where BPLO
           * reads it from.
           */
          app.amendments && app.application_type === 'amendment'
            ? {
                hasChanges: app.amendments.has_amendments,
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
          house_bldg_no: b.address?.house_bldg_no ?? '',
          street: b.address?.street ?? b.address?.line1 ?? '',
          block: b.address?.block ?? '',
          lot: b.address?.lot ?? '',
          lot_area_sqm: b.address?.lot_area_sqm != null ? String(b.address.lot_area_sqm) : '',
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
            setForm((f) => ({
              ...f,
              capital_investment: formatAmountInput(String(perLine)),
            }))
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
        setZoningFacts(app.zoning_facts ?? {})
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
          const file = {
            id: doc.id,
            name: doc.original_filename,
            size: doc.size_bytes,
          }
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
            applications.priorPermit(app.id).catch(() => null),
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
          const amendmentAnswered =
            app.application_type !== 'amendment' ||
            Boolean(
              app.amendments &&
              (app.amendments.ownership ||
                app.amendments.location ||
                app.amendments.nature ||
                (app.amendments.other ?? '').trim() !== ''),
            )
          const permitAnswered = Boolean(prior?.prior_permit_id)
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
   * "Form of Organization" on the business section and "Business Structure" on
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
   * past the fee step to Review. That step no longer exists — it was removed
   * on 16 September 2026 — so nothing can be skipped past any more.
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
     * applicant is asked for their Form of Organization on this same step, and
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
        <h1 className="mt-3 text-2xl font-bold text-ink">Application submitted</h1>
        <p className="mt-2 text-sm text-ink-secondary">
          Keep this tracking ID. You can follow every step of processing on your Track page.
        </p>
        <p className="display-serif mt-4 rounded-2xl bg-white px-4 py-3 text-xl text-ink shadow-card">
          {tracking}
        </p>
        {/*
         * What they are waiting for, and that there is nothing to do while
         * they wait. "No action needed from you right now" is worth the line:
         * a filing that sits still with no explanation is the state testers
         * report as broken.
         */}
        {/*
          Same correction as the submit confirmation above it: this named BPLO,
          a Tax Order of Payment and five clearances on every filing, and a
          clearance-only renewal has none of the three. It goes to the permit's
          own office, is never billed at submission, and carries one permit.
        */}
        {/*
          And an amendment has neither a Tax Order of Payment nor five
          clearances (docs/amendment-2026-09-19.md §3). The one thing it may
          ask next is the new Zoning Clearance, which opens on submission.
        */}
        <p className="mt-4 text-sm text-ink-secondary">
          {applicationType === 'amendment'
            ? amendNeedsZoning
              ? 'BPLO is now reviewing your amendment. Next, apply for your new Zoning ' +
                'Clearance — it is open under Permit Tracking now.'
              : 'BPLO is now reviewing your amendment. No action needed from you right now — ' +
                'the changes apply once it is approved.'
            : clearanceOnlyRenewal
            ? `${submitOffice} is now reviewing your application and will arrange an ` +
              'inspection. No action needed from you right now — nothing is due today, and ' +
              'the fee joins your next business permit renewal in January.'
            : 'BPLO is now reviewing your form. No action needed from you right now — we will ' +
              'tell you when your Tax Order of Payment is ready, and your five LGU clearances ' +
              'open once it is paid.'}
        </p>
        <div className="mt-3 flex flex-wrap justify-center gap-3">
          <PillButton onClick={() => navigate(`/applications/${applicationId}`)}>
            Track this application
          </PillButton>
        </div>
      </div>
    )
  }

  if (refs.loading || hydrating) {
    return (
      <div className="space-y-3">
        <Skeleton className="h-6 w-64" />
        <Skeleton className="h-80 w-full rounded-lg" />
      </div>
    )
  }
  if (refs.error) {
    return (
      <Alert variant="error" title="We couldn’t start a new application">
        {toApiError(refs.error).message}
      </Alert>
    )
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
        <div className="mt-4 flex justify-center gap-3">
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

  /*
   * What the save indicator is allowed to claim.
   *
   * Two places hold this filing: the real `applications` draft once one
   * exists, and the `wizardDrafts` scratch row before that. The indicator
   * knew only about the first, so it spoke for half the filing's life.
   *
   * The scratch row is only consulted while there is no real draft — after
   * that it is discarded, and `dirty` is the one true answer.
   */
  /*
   * Never on an amendment. Its answers are `requested_changes` rows, which
   * only a real draft can hold and the scratch row never carries — so a
   * scratch copy being current said All Changes Saved over details that had
   * gone nowhere (tester, 5 October 2026).
   */
  const scratchUpToDate =
    applicationId === null && scratchSavedSnapshot === snapshot && applicationType !== 'amendment'
  const savedOnServer = (applicationId !== null && !dirty) || scratchUpToDate
  /* Only before anything at all has reached the server. */
  const neverSaved = applicationId === null && scratchSavedSnapshot === null

  return (
    <div className="mx-auto max-w-5xl pb-4">
      {/* ── Persistent wizard chrome (p32/p34) ─────────────────────────── */}
      <div className="mb-3 flex items-center gap-3">
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
          {/*
            ── Three states, and "Not saved yet" is the rarest ───────────────
            *
            * It used to be the default, and was wrong for most of the time it
            * showed. The condition only ever asked about the real
            * `applications` draft, so everything saved to the scratch row —
            * which is every keystroke before a business exists, and the whole
            * of a filing that reaches the Drafts list — was reported as not
            * saved. The applicant could see the draft in their own list while
            * this called it unsaved.
            *
            * `scratchUpToDate` is ignored once there is a real draft: from
            * that point the scratch row is discarded and `dirty` is the only
            * honest answer. Without that guard a stale scratch snapshot could
            * claim All Changes Saved over an application draft that is behind.
            */}
          {saving ? (
            <span className="text-xs italic text-ink-muted">Saving…</span>
          ) : savedOnServer ? (
            <>
              <CloudSavedIcon />
              <span className="text-xs italic text-ink-muted">All Changes Saved</span>
            </>
          ) : (
            <span className="text-xs italic text-ink-muted">
              {neverSaved ? 'Not saved yet' : 'Unsaved changes'}
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

      {/*
        A recovery notice stood here until 29 September 2026, saying the
        answers had been found in this tab and had not reached the server.
        It was true when the only copy was in sessionStorage, and it is not
        now: the answers are saved from the first change and the filing is
        in the drafts list. Reopening a form and finding your work in it is
        what a draft does, not an event to announce.

        Abandoning one is the trash on its card in Drafts — the same
        control that deletes every other draft. Clear All above is not that
        control: it clears the inputs of the current part only.
      */}

      {/* ── Full section map: every step this application requires ─────── */}
      <ol className="mb-8 flex flex-wrap gap-2" aria-label="Application sections">
        {sequence.map((p, i) => {
          /*
           * The phase IS the key, `office:CODE` included. That synthetic
           * identity went when the clearances left the wizard and came back
           * on 21 September 2026 for the one filing that needs it: a renewal
           * of the other permits alone, whose steps ARE those offices' forms.
           * It is still a key and still unique, so nothing here changes but
           * the label lookup, which now has two sources.
           */
          const key = p
          const label = phaseLabel(p, officePermitName)
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
                title={blocked && opened ? 'Finish the sections before this one first.' : undefined}
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
      {needsEmailCode && account && (
        <div className="mb-4">
          <ConfirmEmailCard
            user={account}
            onConfirmed={() => {
              setNeedsEmailCode(false)
              void submit()
            }}
          />
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

      {/*
        ── Review & Submit: the heading, above the form it introduces ────────

        Split out of the Review block further down because JSX order is DOM
        order, and the four sections are drawn by the blocks BELOW this one. A
        heading that came after them would arrive at the foot of a very long
        page, under the answers it was meant to introduce.

        What stayed behind: the estimate and the prior-permit note, which are
        conclusions and belong after the form rather than before it.
      */}
      {reviewAll && (
        <div className="rounded-sm bg-white px-6 py-7 shadow-card sm:px-9 sm:py-8">
          <h1 className="display-serif mb-1 text-2xl text-ink-secondary">Review &amp; Submit</h1>
          <div className="mb-3 h-px bg-ink/40" />
          {/*
            Only when there is something to read over.
            *
            * On a clearance-only renewal this promised "everything you
            * entered" above an empty screen: that filing's sequence carries
            * none of the four review sections, and the office sheet draws
            * only while it is the current step. The sentence named a thing
            * the page did not contain.
            */}
          {reviewHasSections ? (
            <p className="text-sm text-ink">
              Here is everything you entered. Read it over, and press{' '}
              <span className="font-semibold">Change</span> on any answer you need to correct.
            </p>
          ) : (
            <p className="text-sm text-ink">
              Check the details below, then submit. Press{' '}
              <span className="font-semibold">Back</span> to change any answer.
            </p>
          )}
          {/*
            ── One control for all four, and what it is NOT ──────────────────

            It folds the summaries away, for somebody who has finished checking
            and wants Submit — the page has no sticky footer, so Submit is at
            the very bottom. It is not an edit control: each section's own
            heading has "Edit this section", and Change on a row does the same
            for one answer.

            The line beside it used to read "Your answers are still saved —
            hiding a section changes nothing", which was reassurance nobody had
            asked for and planted the idea that something might have been lost.
            It says what the button does instead.
          */}
          {/*
            Hidden when this Review draws no sections, because a control that
            cannot do anything is worse than no control: it is read as broken,
            which is exactly how it was reported.
          */}
          {reviewHasSections && (
            <div className="mt-3 flex flex-wrap items-center gap-3">
              <button
                type="button"
                onClick={() => setAllSections(!allReviewSectionsOpen)}
                aria-expanded={allReviewSectionsOpen}
                className="rounded-md border border-royal bg-royal-tint px-4 py-2 text-sm font-semibold text-royal transition-colors hover:bg-royal/10"
              >
                {allReviewSectionsOpen ? 'Collapse all' : 'Expand all'}
              </button>
              <p className="text-xs text-ink-muted">
                {allReviewSectionsOpen
                  ? 'Collapse them to bring the Submit button within reach.'
                  : 'Expand them to read your answers again.'}
              </p>
            </div>
          )}
        </div>
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
      <WizardSection
        name="address"
        inSequence={sequence.includes('address')}
        phase={phase}
        reviewing={reviewAll}
        label={BASE_LABELS.address}
        open={sectionOpen.address}
        onToggle={() => toggleSection('address')}
        answers={reviewAnswers.address}
        editing={sectionEditing.address}
        onEdit={(focusId) => editSection('address', focusId)}
        missing={missingFor('address')}
      >
        <div>
          <h1 className="mb-1 text-2xl font-bold text-ink">
            Zoning Clearance - Selecting Business Location
          </h1>
          <div className="mb-6 h-px bg-ink/40" />

          {/*
           * No introduction and no "the other permits come later" box.
           *
           * Both stood here: a paragraph restating the pin rules the map
           * already enforces, and a note naming the five clearances that open
           * after payment. The client, 23 September 2026: "no need to mention
           * once BPLO is approved, clearance sanitary etc on the zoning tab. So
           * much clutter, user-unfriendly." This step keeps only what is needed
           * to answer it. The other permits are named where they open, on
           * the clearance stage after payment.
           */}

          {/*
           * ── One column of numbered steps, in the order people answer them ──
           *
           * Ken's layout (checklist, 27 September 2026): 1 trade, 2 barangay,
           * 3 pin, 4 address, 5 emergency contact, top to bottom. It replaced
           * a two-column grid (map on the left; the address, barangay,
           * landmark and emergency contact stacked on the right) in which the
           * barangay sat below the street and the map had to be read before the
           * question that decides where it may be pinned. Numbered because the
           * order is the point: the map (3) is locked until 1 and 2 are done,
           * and a number is how the lock's caption can say "steps 1 and 2".
           *
           * The address comes after the pin because the pin fills the street
           * in (`fillAddressFromPin`); asking for the street first and then
           * overwriting it would be the form arguing with the applicant.
           */}
          <ol className="space-y-5">
            {/*
             * Step 1. Item 69: the one and only Line of Business question, and
             * Products / Services with it.
             *
             * It belongs on this screen because the zoning verdict is about a
             * trade rather than a coordinate, and because Location Insights
             * compares the pin against businesses in the same PSIC group. It
             * used to be a plain dropdown here AND a full picker three sections
             * later; the picker is the one that survived.
             *
             * Singular, and the hint says the quantity out loud. It read "Add
             * every line you trade in — each one is assessed separately", which
             * was true of the multi-select and survived it by months. The client
             * sent a screenshot: the step still "kinda say[s] hey you should be
             * able to select more". Keep it singular if this is reworded.
             */}
            <LocationStep
              n={1}
              title="Line of Business"
              required
              done={lineDone}
              hint="What this location will be used for. Choose one."
            >
              <LinesStep
                codes={psic}
                lines={form.lines}
                onChange={(lines) => update('lines', lines)}
              />
              {form.lines.length === 0 && (
                <p className="mt-2.5 text-sm font-medium text-s-red">
                  {/* "at least one" was the multi-select's phrasing and implied a
                      minimum with no maximum. There is exactly one. */}
                  Required: choose your line of business.
                </p>
              )}
            </LocationStep>

            <LocationStep
              n={2}
              title="Barangay"
              required
              done={form.barangay_id !== ''}
            >
              <div className="relative">
                <label className="block">
                  {/* The step's heading names this field on screen; said once. The
                      full name stays for the "still needed" list and the review. */}
                  <span className="sr-only">Barangay Name</span>
                  <select
                    id="address-barangay"
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
                      const next = e.target.value
                      /*
                       * ── Zoning 7 — a new barangay clears the pin ─────────────
                       *
                       * Unconditionally now. It used to keep a pin that agreed
                       * with the barangay named after it, because the map took a
                       * pin before any barangay was chosen and clearing then
                       * would have punished the applicant for answering in that
                       * order. That order no longer exists: the map is locked
                       * until the barangay is chosen (`mapLockReason`) and opens
                       * zoomed to it, so every pin was placed inside a barangay
                       * already named. Changing the barangay is answering step 2
                       * again, and step 3 is answered again after it: the
                       * checklist's wording, "changing the barangay clears the
                       * pin".
                       *
                       * ── Why this lives in the CHANGE HANDLER, not an effect ──
                       *
                       * Because an effect watching `form.barangay_id` cannot tell
                       * a person from a prefill. A renewal and a reopened draft
                       * both arrive with a barangay AND coordinates, written in by
                       * a single `setForm` some time after mount, so to an effect
                       * that is a change, and it would wipe a pin the applicant
                       * never placed the instant the form hydrated. That would
                       * break every renewal, which is a bug this repo has already
                       * shipped once.
                       *
                       * `touched.barangay_id` is set HERE, on change rather than
                       * on blur, so the mismatch gate can tell an answer the
                       * applicant gave from a value we handed them (see the long
                       * note in `missingFor`).
                       *
                       * Guarded on the value actually differing, so re-picking the
                       * barangay already selected is not a change and costs nobody
                       * their pin: `selectOption` fires change on an unchanged
                       * value.
                       */
                      if (next !== form.barangay_id) {
                        setForm((f) => ({ ...f, barangay_id: next, latitude: null, longitude: null }))
                        setAutoPinned(null)
                        setStartPoint(null)
                        setPinError(null)
                      }
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
                  <FieldError>
                    {fieldErrors.barangay_id}
                  </FieldError>
                )}
              </div>

            </LocationStep>

            <LocationStep n={3} title="Pin your business on the map" required done={form.latitude !== null}>
              {/*
               * Why the pin has to be right, said before it is placed (client,
               * 23 September 2026). CPDO inspects the spot the pin names; a
               * wrong one can get the filing disapproved, and fees already
               * paid are not returned.
               *
               * Amber, not red: nothing is wrong yet, and #bd0000 is for errors
               * (DESIGN.md, Red Means Stop). The bold lead-in carries it in
               * words, so it survives with colour off.
               *
               * "Choose your barangay first" stays even though the map is now
               * locked until one is chosen: it is the reason for the lock, said
               * where the applicant reads before touching the map, and the next
               * sentence is the rule both the map and the API enforce (Zoning 3).
               */}
              <p
                id="pin-accuracy-note"
                className="mb-4 rounded-xl border border-s-yellow bg-s-yellow-tint px-4 py-3 text-sm leading-relaxed text-amber-900"
              >
                <span className="font-bold">Place the pin exactly on your business.</span> Choose
                your barangay first. Your pin has to be inside it. A wrong location can get your
                application disapproved, and any fees you paid will be forfeited.
              </p>
              {/*
               * The map with what it answers directly under it, and what the
               * barangay is zoned for beside it.
               *
               * Left: the map, its pin line, then the zoning note (Zoning 8
               * wants that note where the eye already is after placing the
               * pin). Right on a wide screen, below on a phone: the "Zones in
               * <barangay>" card and Location Insights. Both describe the place
               * the map shows, so they sit beside it rather than further down
               * among the address fields.
               *
               * `self-start` on both columns: a grid item stretches to its row
               * by default, and a transparent column then showed the page
               * through a rounded frame with nothing in it, which is the empty
               * panel the client once saw under the map.
               */}
              <div className="grid gap-4 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
                <div className="min-w-0 space-y-4 self-start">
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
                  // Only for the barangay it was worked out for — a stale one
                  // would start the applicant in the wrong place.
                  startAt={startPoint !== null && startPoint.barangay === barangayName ? startPoint : null}
                  /*
                   * Locked until steps 1 and 2 are both answered: the trade
                   * (with what is sold) and the barangay. See `mapLockReason`
                   * for why the barangay became a gate again, and for the
                   * sentence the scrim shows.
                   *
                   * History worth keeping: checklist items 4 and 8 asked for
                   * different locks (the trade; what the barangay does to a
                   * pin), and hanging the lock on one kept dropping the other.
                   * Both hold now: the trade and the barangay gate the map, and
                   * a new barangay clears the pin.
                   *
                   * Both guards in `onPick` survive regardless: a pin outside
                   * Malabon is refused, and so is one that contradicts the
                   * barangay chosen. The API refuses it too, since Zoning 3.
                   */
                  lockedReason={mapLockReason}
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
                      // No coordinates in the message: they mean nothing to an
                      // applicant, and the map already shows where they clicked.
                      setPinError(
                        'That point is outside Malabon, so we can’t use it. Zoom in on your street within the city and click there.',
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
                    /*
                     * The applicant has now answered for themselves, so the
                     * address stops suggesting. Cleared for a click AND a drag,
                     * because both arrive here — see the Marker's dragend.
                     */
                    setAutoPinned(null)
                    setForm((f) => ({ ...f, latitude: lat, longitude: lng }))
                    // And the pin, now the applicant's, suggests the address
                    // text — see `fillAddressFromPin`.
                    fillAddressFromPin(lat, lng)
                  }}
                />
                {form.latitude !== null ? (
                  /*
                   * One quiet line that the pin is down, and how to move it.
                   *
                   * It read "Pinned at 14.675351, 120.945833 · the ring is the
                   * 500 m counted below". The client's lead (24 September
                   * 2026): coordinates mean nothing to the public, and they
                   * don't. The ring's meaning moved to the insights card, the
                   * only place that counts inside it ("Within 500 m of your
                   * pin, the ring on the map"). What is left is the
                   * confirmation — the partner of the red "Required: click the
                   * map" line it replaces — and the one thing an applicant may
                   * not know, that the pin can be moved.
                   *
                   * The coordinates ride on data attributes, not on screen, for
                   * the e2e checks that a pin survived or moved.
                   */
                  <p
                    className="bg-white px-4 py-2 text-xs text-ink-muted"
                    data-latitude={form.latitude}
                    data-longitude={form.longitude ?? undefined}
                    data-testid="pin-status"
                  >
                    {autoPinned === null && 'Pin placed. Drag it or click the map to move it.'}
                    {/*
                     * Item 7 — a suggested pin says it is a suggestion.
                     *
                     * An applicant who did not place this pin needs to know two
                     * things before they walk past it: that the form guessed
                     * from their address, and that the guess is theirs to
                     * overrule. Saying only "Pin placed" would let a
                     * street-centroid guess be mistaken for a confirmed
                     * location, which on a zoning clearance is the wrong thing
                     * to be relaxed about. Body size, because this one asks the
                     * applicant to do something.
                     *
                     * Disappears the moment they click or drag, because from
                     * then on the pin is not a guess.
                     */}
                    {autoPinned !== null && (
                      <span className="block text-sm text-ink-secondary">
                        Pin placed from your address, on {autoPinned} — a point on the street, not
                        your door. Drag it onto your exact spot.
                      </span>
                    )}
                  </p>
                ) : !mapLocked &&
                  startPoint !== null &&
                  startPoint.barangay === barangayName ? (
                  /*
                   * The start pin's caption. Not red: nothing has gone wrong —
                   * OSM simply does not know most of Malabon's streets — and
                   * #bd0000 is for errors. It says what the hollow pin is NOT
                   * before what to do, because the one misreading that matters
                   * is taking it for the address.
                   */
                  <p className="bg-white px-4 py-2 text-sm text-ink">
                    <span className="font-semibold">Not pinned yet.</span> We could not find your
                    street on the map, so the hollow pin starts at the centre of{' '}
                    {startPoint.barangay}. It is not your address — drag it onto your business, or
                    click the map there.
                  </p>
                ) : (
                  <p className="bg-white px-4 py-2 text-sm font-medium text-s-red">
                    {/* Two states, because telling somebody to click a map that is
                      not taking clicks yet sends them to a control that will not
                      answer. The lock's own sentence says what to do about it;
                      this one says the pin is required either way. */}
                    {mapLocked
                      ? 'Required: a pin. The map opens once steps 1 and 2 are answered.'
                      : 'Required: click the map to drop a pin where your business is.'}
                  </p>
                )}
                {pinError && (
                  <p role="alert" className="bg-white px-4 pb-2 text-sm font-medium text-s-red">
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
                      <p className="bg-white px-4 pb-2.5 text-sm text-ink-secondary">
                        <span className="font-semibold text-ink">Check this location.</span> The
                        saved pin sits in {verdict.actual ?? 'no barangay we can identify'}, but
                        this application says {barangayName}. Click the map to move the pin, or
                        change the barangay above — whichever is wrong.
                      </p>
                    )
                  })()}
                {/*
                 * "CPDO checks the actual site during processing." stood here.
                 * It went with the rest of the step's explanatory copy
                 * (client, 23 September 2026); CPDO's final say is now stated
                 * once, as the last line of the zoning note under the map.
                 */}
              </div>
                  {/*
                   * The zoning answer, inline and live (client, 23 September
                   * 2026: "Zoning must not be a popup"). It reads the ordinance
                   * lookup that rides on the insights response, so it follows
                   * the pin, the barangay and the trade as they change. It
                   * renders nothing while the lookup is undetermined; see
                   * ZoningConformanceNote.
                   */}
                  {livePin !== null && !insights.loading && !insightsStale && (
                    <ZoningConformanceNote
                      zoning={insights.data?.zoning ?? null}
                      barangayName={barangayName ?? null}
                    />
                  )}
                  {/*
                    Every other rule the ordinance applies to this filing,
                    each with its article and page, and the few questions those
                    rules need answered — asked here, under the rule, rather
                    than as a block of their own. The note above stays the
                    headline; this is what it rests on.
                  */}
                  {form.barangay_id && (
                    <ZoningRuleChecklist
                      variant="applicant"
                      result={zoningCheck.data}
                      loading={zoningLoading}
                      values={zoningFacts}
                      onAnswer={answerZoning}
                    />
                  )}
                </div>
                <div className="min-w-0 space-y-4 self-start">
                  {/*
                   * The zones on CPDO's sheet for the barangay chosen in step 2,
                   * in plain names, and a link to the sheet. Gated on a
                   * selection: twenty-one maps and no barangay chosen is a
                   * gallery, not an answer. It shows and lists; it does not
                   * decide.
                   */}
                  {selectedBarangay !== null && <BarangayZoningMap barangay={selectedBarangay} />}
                  {/*
                   * The figures for the pin, the moment there is a pin. Gated on
                   * `livePin` rather than on the response, so the card appears
                   * with the pin and shows its own skeleton while the lookup
                   * runs; `insightsStale` is folded into `loading` for the same
                   * reason (see where it is computed).
                   */}
                  {livePin !== null && (
                    <LocationInsightsPanel
                      insights={insights.data}
                      loading={insights.loading || insightsStale}
                      error={insights.error}
                    />
                  )}
                </div>
              </div>
            </LocationStep>

            <LocationStep n={4} title="Address" done={form.street.trim() !== ''}>
              <div className="space-y-3">
              <div>
                {/*
                  ── Item 5's two boxes, as the paper prints them ─────────────

                  This was ONE field, "House No. & Street Name", and the
                  combined question did real damage. `business_addresses` has
                  carried `house_bldg_no` and `street` since the schema was
                  aligned to the paper, both empty on every row, so the
                  officer's review had to guess the split back out of `line1`
                  with a regex — and filings exist whose entire street address
                  is "17", because the applicant read the label as asking for
                  the number. The regex cannot parse that, so BPLO was shown
                  Street "17" and House "—": the two answers reversed.

                  The House/Bldg. No. is NOT required. Premises exist with no
                  number of their own — a stall inside a public market, a unit
                  known only by its building's name — and the paper prints a
                  line for it without marking it required.
                */}
                <div className="grid gap-3 sm:grid-cols-3">
                  <div>
                    <label className="block">
                      <FieldLabel>House / Bldg. No.</FieldLabel>
                      <input
                        value={form.house_bldg_no}
                        onChange={(e) => update('house_bldg_no', e.target.value)}
                        onBlur={() => touch('house_bldg_no')}
                        /*
                         * The shape of the answer, not an answer (AGENTS.md
                         * §6.4). "e.g. 17" read as a real number, and filings
                         * exist whose whole street address is "17". Saying a
                         * building name also counts is the thing an applicant
                         * in a market stall or a named building needs to hear.
                         * Short, because the box is narrow on a desktop.
                         */
                        placeholder="No. or building"
                        className={inputCls}
                      />
                    </label>
                  </div>
                  <div className="relative sm:col-span-2">
                    <label className="block">
                      <FieldLabel required>Street</FieldLabel>
                      <input
                        value={form.street}
                        onChange={(e) => update('street', e.target.value)}
                        onBlur={() => touch('street')}
                        // Shape, not an answer: "e.g. Gen. Luna Street" is a real
                        // Malabon street and read as a prefilled one. The barangay
                        // has its own field below, so it is the one thing to leave out.
                        placeholder="Street name only, no barangay"
                        className={inputCls}
                        aria-invalid={Boolean(fieldErrors.street)}
                      />
                    </label>
                    {fieldErrors.street && (
                      <FieldError>
                        {fieldErrors.street}
                      </FieldError>
                    )}
                  </div>
                </div>
                {/*
                 * Says the boxes were written by us, from the pin, and are
                 * theirs to correct. Only while every filled box still holds
                 * what we put there (`autoFillNoteShown`): once the applicant
                 * edits, the text is theirs and the note would be describing
                 * the wrong thing. The live region is always mounted so the
                 * sentence is announced when it appears.
                 */}
                <div role="status" aria-live="polite">
                  {autoFillNoteShown && (
                    <p className="mt-2 text-sm text-ink-secondary" data-testid="address-autofill-note">
                      Filled in from your pin. Check it and fix anything that’s wrong.
                    </p>
                  )}
                </div>
              </div>
              {/*
               * Block, Lot and the lot's area (client, 23 September 2026).
               * Optional: a market stall or a unit on a numbered street has no
               * block or lot. "Lot Area" rather than "Area" because Business
               * Operation asks the FLOOR area the fee engine assesses, and one
               * word for two quantities is how the same question gets asked
               * twice by mistake.
               */}
              <div className="grid gap-4 sm:grid-cols-3">
                <label className="block">
                  <FieldLabel>Block</FieldLabel>
                  <input
                    value={form.block}
                    onChange={(e) => update('block', e.target.value)}
                    maxLength={40}
                    className={inputCls}
                  />
                </label>
                <label className="block">
                  <FieldLabel>Lot</FieldLabel>
                  <input
                    value={form.lot}
                    onChange={(e) => update('lot', e.target.value)}
                    maxLength={40}
                    className={inputCls}
                  />
                </label>
                <div className="relative">
                  <label className="block">
                    <FieldLabel>Lot Area (sq. m.)</FieldLabel>
                    <input
                      inputMode="decimal"
                      placeholder="120"
                      value={form.lot_area_sqm}
                      onChange={(e) => update('lot_area_sqm', e.target.value)}
                      onBlur={() => touch('lot_area_sqm')}
                      className={`${inputCls} tnum`}
                      aria-invalid={Boolean(fieldErrors.lot_area_sqm)}
                      aria-describedby={fieldErrors.lot_area_sqm ? 'lot-area-error' : undefined}
                    />
                  </label>
                  {fieldErrors.lot_area_sqm && (
                    <FieldError id="lot-area-error">{fieldErrors.lot_area_sqm}</FieldError>
                  )}
                </div>
              </div>
              <div>
                <label className="block">
                  <FieldLabel>Locational Group/Landmark</FieldLabel>
                  <input
                    value={form.line2}
                    onChange={(e) => update('line2', e.target.value)}
                    // "Nearest landmark" restated the label. This says what kind.
                    placeholder="A place nearby that people know"
                    className={inputCls}
                  />
                </label>
              </div>

              </div>
            </LocationStep>

            <LocationStep
              n={5}
              title="In case of emergency"
              done={
                form.emergency_contact_name.trim() !== '' &&
                phoneValid(form.emergency_contact_number)
              }
            >
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="relative">
                  <label className="block">
                    <FieldLabel required>Contact Person</FieldLabel>
                    <input
                      aria-label="Emergency Contact Person"
                      value={form.emergency_contact_name}
                      onChange={(e) => update('emergency_contact_name', e.target.value)}
                      onBlur={() => touch('emergency_contact_name')}
                      placeholder="Full name"
                      className={inputCls}
                      aria-invalid={Boolean(fieldErrors.emergency_contact_name)}
                    />
                  </label>
                  {fieldErrors.emergency_contact_name && (
                    <FieldError>
                      {fieldErrors.emergency_contact_name}
                    </FieldError>
                  )}
                </div>
                <div className="relative">
                  <label className="block">
                    <FieldLabel required>Contact Number</FieldLabel>
                    <input
                      inputMode="tel"
                      aria-label="Emergency Contact Number"
                      value={form.emergency_contact_number}
                      onChange={(e) => update('emergency_contact_number', e.target.value)}
                      onBlur={() => touch('emergency_contact_number')}
                      placeholder="11 digits, starting 09"
                      className={inputCls}
                      aria-invalid={Boolean(fieldErrors.emergency_contact_number)}
                    />
                  </label>
                  {fieldErrors.emergency_contact_number && (
                    <FieldError>
                      {fieldErrors.emergency_contact_number}
                    </FieldError>
                  )}
                </div>
              </div>
            </LocationStep>
          </ol>
        </div>
      </WizardSection>

      {/* ── Business information (form sheet, p32) ─────────────────────── */}
      <WizardSection
        name="business"
        inSequence={sequence.includes('business')}
        phase={phase}
        reviewing={reviewAll}
        label={BASE_LABELS.business}
        open={sectionOpen.business}
        onToggle={() => toggleSection('business')}
        answers={reviewAnswers.business}
        editing={sectionEditing.business}
        onEdit={(focusId) => editSection('business', focusId)}
        missing={missingFor('business')}
      >
        <FormSheet meta={typeMeta} filing={filingIdentity} compact={reviewAll}>
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
            <div className="mt-3 rounded-lg border border-royal/30 bg-royal-tint px-4 py-3">
              <div className="flex items-start justify-between gap-3">
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
                        {/*
                         * Name first here too — same reasoning as the picker
                         * this summarises. A summary that leads with a
                         * reference code makes the reader decode it to learn
                         * what they picked.
                         */}
                        <span className="font-semibold">
                          {priorPermitChoice.permit_type?.name ?? 'Permit'}
                        </span>
                        {' · '}
                        <span className="tnum">{priorPermitChoice.permit_number}</span>
                        {' · '}
                        {permitValidity(priorPermitChoice)}
                      </>
                    ) : (
                      /*
                       * One null left, and one sentence for it.
                       *
                       * There were two, and this line could not tell them apart
                       * — so it printed the paper escape's reassuring sentence
                       * over an open question, and a draft could sit here
                       * looking complete while being a renewal of nothing. The
                       * escape went on 18 September 2026 (no permit is ever
                       * renewed on a manual system), which leaves only the
                       * honest reading of a missing permit: nobody has answered.
                       */
                      'Not chosen yet — press Change.'
                    )}
                  </dd>
                </div>
                {applicationType === 'amendment' && (
                  <div className="flex gap-2">
                    <dt className="shrink-0 font-semibold text-ink-secondary">Amending</dt>
                    <dd className="min-w-0 text-ink">
                      {amendmentSummary ?? 'Nothing yet — set the new values on New Details.'}
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
          {/*
            ── One wrapping row, 24 September 2026 ──────────────────────

            Every field is a flex item the width of its own answer. They
            pack along the line and wrap when it is full, so items 1, 2 and
            3 share the first line instead of each taking a half. Nothing
            carries `flex-1`, because stretching is what recreated the equal
            halves the last two attempts were trying to remove.
          */}
          <div className="mt-3 flex flex-wrap items-start gap-x-4 gap-y-3">
            {/* `contents`: these children join the wrap rather than form a row. */}
            <div className="contents">
            {/*
              Shares its row with item 2, the number it governs.

              This was `w-full` after an attempt to float it up beside two
              18rem text boxes wrapped the pills onto two lines and left a
              hole in the page. The field beside it now is 13rem, so four
              pills and a number fit on one line — and the pair reads as the
              one question it is: which register, and the number it issued.

              `shrink-0` rather than a basis, on the client's instruction of
              24 September 2026: *"Form of Org. should be one row only."* At a
              basis the cell was sized as a SHARE of the row, which at some
              widths came out under the four pills and dropped Cooperative
              onto a second line. Unshrinkable, the cell is exactly as wide as
              the pills need and item 2 takes what is left.

              `max-w-full` is the escape hatch: on a phone the four pills are
              wider than the page, and without a ceiling `shrink-0` would push
              the whole form into a horizontal scroll rather than wrap.
            */}
            <div className="max-w-full shrink-0">
              <FieldLabel required>1. Form of Organization</FieldLabel>
              {/*
               * A radiogroup, not four toggle buttons. These are four mutually
               * exclusive answers to one question, and `aria-pressed` announced
               * them as four independent switches — a screen-reader user was
               * told "Corporation, pressed" with no way to hear that it was one
               * of four or that picking it unpicked another. Same markup as the
               * "which permit are you renewing" picker above.
               */}
              <div
                role="radiogroup"
                aria-label="Form of Organization"
                className="flex flex-wrap gap-2.5"
              >
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
            <div className="relative grow basis-[13rem] max-w-full">
              <label className="block">
                {/*
                 * The label TEXT changes with the chosen structure, so the
                 * input's accessible name is re-rendered rather than left saying
                 * "DTI / SEC / CDA" at a field that now means one of them.
                 * Because this is a wrapping <label>, the name follows the text
                 * automatically — there is no stale `aria-label` to forget.
                 */}
                {/*
                Item 1, numbered here and not on `registrationNumberLabel` —
                that string also builds the validation messages, which would
                have read "Enter your 1. DTI Business Name registration
                number."
              */}
                <FieldLabel required>2. {registrationNumberLabel}</FieldLabel>
                <input
                  value={form.registration_number}
                  onChange={(e) => update('registration_number', e.target.value)}
                  onBlur={() => touch('registration_number')}
                  /*
                    Reaching a closed field is enough to be told why it is
                    closed. Focus and not click, so tabbing to it answers
                    too — and `readOnly` rather than `disabled` is what
                    makes either possible; a disabled input takes neither.
                  */
                  onFocus={() => {
                    if (!registrationAgencyInfo) touch('registration_number')
                  }}
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
              {/*
                `sr-only` since 24 September 2026, not deleted. On the page it
                restated its own label — "Issued by the Department of Trade and
                Industry" under a box called DTI Registration Number — and the
                client asked for it gone. It is still the live region that
                announces the label CHANGING when the structure is picked, and
                still the target of `aria-describedby`, both of which break if
                the element goes.
              */}
              <p
                id="registration-number-help"
                aria-live="polite"
                className="sr-only"
              >
                {registrationAgencyInfo
                  ? registrationAgencyInfo.hint
                  : 'Enter the number from your DTI, SEC or CDA certificate. Item 1 beside this asks which of the three, and this label will narrow to it.'}
              </p>
              {/*
                ── The "already registered under this number" notice was here ──

                An orange box listing the applicant's other businesses on the
                same DTI number, ending "That is fine if you are opening
                another branch. If you meant to renew instead, go back and
                start a renewal."

                Removed 24 September 2026, asked directly: *"Does this
                description matter? Remove it if not."* It does not, and the
                clearest evidence is its own last sentence — a warning that
                tells you the situation is fine is not a warning. A DTI
                Business Name Registration Number legitimately covers several
                branches, so almost everyone who saw this box was doing
                nothing wrong, and it was three orange lines on the FIRST
                field of the form.

                The mistake it guarded — filing new when you meant to renew —
                is guarded earlier and better. The applicant chose New Permit
                at the entry dialog, where Renewal sits beside it and lists
                the permits they already hold; advising them to go back, two
                steps into the form they chose, is late.

                It had been questioned once before, for naming a business
                without saying whose it was — it reads `businesses.list()`,
                which is scoped to `owner_user_id`, so it could only ever name
                the caller's own, but the seeded demo login is shared between
                testers and the business it named had been filed by somebody
                else. That was fixed by leading with the possessive. Being
                asked about twice is the part worth recording.

                `registrationNumberOwnedElsewhere` went with it rather than
                being left computing an answer nobody reads.
              */}
              {fieldErrors.registration_number && (
                <FieldError id="registration-number-error">
                  {fieldErrors.registration_number}
                </FieldError>
              )}
              {/*
                * The advisory shape note stood here and is gone —
                * 30 September 2026, at the client's request.
                *
                * It read "that does not look like the usual CDA format
                * (9520-15005879). Check it against your certificate — we will
                * accept it either way", which tells somebody their number may
                * be wrong and then says it does not matter. A reader either
                * ignores it or re-checks a certificate that is very likely
                * fine: `9520-` is one of CDA's four series and belongs to
                * cooperatives registered under the 2008 Code, so the note fired
                * on the oldest co-ops in the city and was wrong about all of
                * them.
                *
                * The `shape` regexes themselves stay. They are the best record
                * anyone here has of what these numbers look like, and the next
                * person to be asked for per-agency validation should read them
                * before reaching for a web summary — see the note on
                * `registrationNumberUnusual` for what that cost.
                */}
            </div>
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
              {/* Fixed boxes: growing the cell would only pad it. */}
              <div className="relative shrink-0">
                <TinInput
                  /*
                   * It showed no number, alone among the questions on this
                   * step — TinInput has taken one since the numbering went in
                   * and this call site never passed it, so the sequence read
                   * 1, (nothing), 3.
                   */
                  number={3}
                  required={false}
                  value={form.tin}
                  onChange={(tin) => update('tin', tin)}
                  onBlur={() => touch('tin')}
                  error={fieldErrors.tin}
                  hintId="tin-hint"
                  errorId="tin-error"
                />
                {/*
                  The note that used to float here is gone — 27 September 2026.

                  It said the same two things the label and the Confirm step now
                  say between them, and it said them from a bubble anchored
                  `bottom-full`, which put it over item 1. Client: *"this
                  message should only appear once Next is clicked for it not to
                  block the other fields."*

                  Waiting for Next would not have fixed it. The overlap is the
                  same whenever it draws, and an applicant with no TIN never
                  clears the condition, so the bubble would simply have arrived
                  later and then stayed — in the instant the red ones appear,
                  reporting that a valid choice was validly taken.

                  The fact that it is optional is on the label, where it is read
                  BEFORE the boxes and where every other question on this step
                  states the same thing. The consequence is on Confirm, which
                  prints it in place of the em dash on the last screen before
                  the filing goes in. See TinInput's legend.
                */}
                {/*
                  Off the page, still announced. The shape is visible in the
                  four boxes themselves, but "leave the last box empty if you
                  have no branch code" is not — and `aria-describedby` above
                  points here, so deleting it would aim a screen reader at
                  nothing.
                */}
                <p id="tin-hint" className="sr-only">
                  As printed on your BIR certificate, like 123-456-789-000. Leave the last box empty
                  if you have no branch code.
                </p>
                {fieldErrors.tin && (
                  <FieldError id="tin-error">
                    {fieldErrors.tin}
                  </FieldError>
                )}
              </div>
            </div>
            <div className="contents">
              <div className="relative grow basis-[24rem] max-w-full">
                <label className="block">
                  <FieldLabel required>4. Business Name</FieldLabel>
                  <input
                    value={form.name}
                    onChange={(e) => update('name', e.target.value)}
                    onBlur={() => touch('name')}
                    className={inputCls}
                    aria-invalid={Boolean(fieldErrors.name)}
                  />
                </label>
                {fieldErrors.name && (
                  <FieldError>
                    {fieldErrors.name}
                  </FieldError>
                )}
              </div>
            <div className="grow basis-[12rem] max-w-full">
              <label className="block">
                <FieldLabel>5. Trade Name / Franchise</FieldLabel>
                <input
                  value={form.trade_name}
                  onChange={(e) => update('trade_name', e.target.value)}
                  onBlur={() => touch('trade_name')}
                  className={inputCls}
                  aria-invalid={Boolean(fieldErrors.trade_name)}
                />
              </label>
              {fieldErrors.trade_name && <FieldError>{fieldErrors.trade_name}</FieldError>}
            </div>
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
            <div className="contents">
              <div className="relative shrink-0">
                {/*
                 * Item 10 — the area code is its own group, not a convention
                 * the applicant has to remember. A single box with "Area code
                 * and number" under it asked for a shape and then accepted any
                 * shape at all, so `02 8123 4567`, `0281234567` and
                 * `(02)8123-4567` all went into the same column and nothing
                 * downstream could tell where the area code stopped.
                 *
                 * No <label>/<FieldLabel> here: the control is two boxes with
                 * their own accessible names inside a fieldset, and its legend
                 * is the question. A label wrapping both would make the first
                 * box answer to two names at once.
                 */}
                <LandlineInput
                  legend="6. Telephone (Landline)"
                  value={form.telephone}
                  onChange={(v) => update('telephone', v)}
                  onBlur={() => touch('telephone')}
                  error={fieldErrors.telephone || undefined}
                  errorId="telephone-error"
                />
                {fieldErrors.telephone && (
                  <FieldError id="telephone-error">
                    {fieldErrors.telephone}
                  </FieldError>
                )}
              </div>
              <div className="relative shrink-0">
                {/*
                 * Item 10 — +63 and ten digits, and the 09 form does not
                 * appear here at all.
                 *
                 * It was a plain text box whose placeholder read "09XX XXX
                 * XXXX", which taught the one shape the client says this field
                 * must not use. The prefix is now part of the control rather
                 * than something to type, so the question cannot be answered
                 * in the wrong notation; a prefilled 09 number from the
                 * account still reads back correctly, as its ten digits.
                 *
                 * This is the BUSINESS's number. The account's own field keeps
                 * its 09 rule in AuthController and is untouched — see
                 * businessPayload for why the two are validated apart.
                 */}
                <MobileNumberInput
                  legend="7. Mobile Number"
                  value={form.mobile_number}
                  onChange={(v) => update('mobile_number', v)}
                  onBlur={() => touch('mobile_number')}
                  error={fieldErrors.mobile_number || undefined}
                  hintId="mobile-number-hint"
                  errorId="mobile-number-error"
                />
                {/* Says what "Mobile Number" on a business form already says. */}
                <p id="mobile-number-hint" className="sr-only">
                  The number the city should ring about this business.
                </p>
                {fieldErrors.mobile_number && (
                  <FieldError id="mobile-number-error">
                    {fieldErrors.mobile_number}
                  </FieldError>
                )}
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
                <div className="grow basis-[14rem] max-w-full">
                  <label className="block">
                    <FieldLabel required>8. E-mail Address</FieldLabel>
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
              <div className="relative grow basis-[13rem] max-w-full">
                <label className="block">
                  <FieldLabel>9. Website Address</FieldLabel>
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
                  <FieldError id="website-error">
                    {fieldErrors.website}
                  </FieldError>
                )}
              </div>
            </div>
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
             * The type decides which agency's number item 1 wants, so item 1
             * used to be asked AFTER this — which made section A read 10, 1,
             * 2, 3, 4 and the client rightly called it chaotic on 16
             * September 2026. The paper does not have the problem, because
             * its item 1 names all three agencies in the label; so item 1 is
             * asked at 1 with the paper's wording, and this question narrows
             * that label afterwards. It still supplies the example, the
             * agency-specific error and the format check —
             * DTI for a sole proprietorship, SEC for a partnership or a
             * corporation, CDA for a cooperative.
             */}

            {/*
              ── Items 10 to 14 — the named person on the form ────────────────

              Surname, given name, middle name, SUFFIX and GENDER. All five have
              had columns on `business_owners` since the schema was aligned with
              the manuscript, and until now only the seeders wrote them — so a
              paper that asks for a suffix and a gender had nowhere to put
              either.

              The paper prints TWO rows here for a corporation, partnership or
              cooperative. One is written (the primary); the relation is plural
              on both sides, so the second needs no migration when it is asked
              for.
            */}
            <div className="contents">
              {/*
                Numbered one by one, 10 to 14, on the client's instruction of
                24 September 2026: *"remove Name on the Registration, and
                include the Surname to Gender in the numbering instead."*

                The heading that stood here carried the paper's numbering,
                where these five boxes are one item on one printed row. Ours
                has not followed the paper's numbering since it was made
                sequential earlier the same day — it counts what is ASKED —
                so the heading was a row of page spent on a grouping the
                numbers no longer expressed.

                `contents` so the five join section A's single wrap and pack
                with the fields either side of them.
              */}
              <div className="contents">
                <label className="block grow basis-[11rem]">
                  <FieldLabel required>10. Surname</FieldLabel>
                  <input
                    value={form.owner_surname}
                    onChange={(e) => update('owner_surname', e.target.value)}
                    className={inputCls}
                  />
                </label>
                <label className="block grow basis-[11rem]">
                  <FieldLabel required>11. Given Name</FieldLabel>
                  <input
                    value={form.owner_given_name}
                    onChange={(e) => update('owner_given_name', e.target.value)}
                    className={inputCls}
                  />
                </label>
                <label className="block grow basis-[11rem]">
                  <FieldLabel>12. Middle Name</FieldLabel>
                  <input
                    value={form.owner_middle_name}
                    onChange={(e) => update('owner_middle_name', e.target.value)}
                    className={inputCls}
                  />
                </label>
                <label className="block grow basis-[7rem]">
                  <FieldLabel>13. Suffix</FieldLabel>
                  <input
                    value={form.owner_suffix}
                    onChange={(e) => update('owner_suffix', e.target.value)}
                    placeholder="Jr., III"
                    className={inputCls}
                  />
                </label>
              </div>
              {/*
                Ends the line so items 14 to 17 begin one of their own —
                see the note on this section's wrap. Zero height, and the
                negative margin cancels the row gap it would double.
              */}
              <div className="-my-1.5 basis-full" aria-hidden="true" />
              <div className="shrink-0">
                <FieldLabel required>14. Gender</FieldLabel>
                {/*
                  Two options, as the paper's M / F boxes print. A radiogroup
                  rather than toggles, so a screen reader announces that picking
                  one unpicks the other — the same treatment Form of Organization
                  and Economic Organization get above.
                */}
                <div role="radiogroup" aria-label="Gender" className="flex flex-wrap gap-2">
                  {/* The shared list — see `GENDERS`. Four screens kept a copy. */}
                  {GENDERS.map((opt) => {
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
             * ── Items 13, 14, 15 — asked of every structure ────────────────
             *
             * They were shown only to a partnership, corporation or
             * cooperative, on the reasoning that a sole proprietor is their
             * own officer in charge and item 11 already names them. The paper
             * disagrees: item 11 prints "(go to 13)" exactly as item 12 does,
             * so both routes arrive here. See hasPresidentOrOfficer.
             *
             * For a sole proprietor item 13 is prefilled from item 11, which
             * is the honest way to respect BOTH facts — the form asks the
             * question, and the answer is already known. They can edit it, for
             * the proprietor who has put somebody else in charge of the shop.
             *
             * `aria-live` stays. The prefill changes as the name above is
             * typed, and a screen-reader user should hear that rather than
             * discover it by tabbing back.
             */}
            {/*
              One cell on Gender's row, holding a box rather than three loose
              fields. Client, 24 September 2026: *"You may group 15 to 17 in a
              bigger box to group them."*

              All three are about ONE person, which is the reason items 16 and
              17 carry "(of President/OIC)" inside their own labels — the
              wording was compensating for a grouping the page did not show.
              The border shows it, and the labels can stay as the paper prints
              them.

              The live region stays on the element: `presidentAsked` is true
              for every structure since 16 September, so nothing toggles here
              and there is no announcement to lose.
            */}
            <div aria-live="polite" className="grow basis-[34rem] max-w-full">
              {presidentAsked && (
                <div className="rounded-xl border border-line p-3">
                  {/*
                    All three describe ONE person — the paper labels item 12
                    "Citizenship (of President/OIC)" — so they read as a row
                    rather than as three questions that happen to follow each
                    other. It also takes two rows out of the form.
                  */}
                  {/* The box packs its own three, the way the section packs its own. */}
                  <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
                    <div className="grow basis-[15rem]">
                      <label className="block">
                        <FieldLabel required={hasPresidentOrOfficer(form.registration_type)}>
                          15. Name of President / Officer in Charge
                        </FieldLabel>
                        <input
                          value={form.president_officer_name}
                          onChange={(e) => update('president_officer_name', e.target.value)}
                          placeholder={oicIsProprietor ? '' : 'Full name'}
                          maxLength={255}
                          readOnly={oicIsProprietor}
                          aria-readonly={oicIsProprietor || undefined}
                          aria-describedby={oicIsProprietor ? 'oic-prefilled' : undefined}
                          /*
                           * The same grey as the carried-over fields on the office
                           * sheets and the locked counts on the fee step. Read-only
                           * has no appearance of its own, so without this the box
                           * looks editable and refuses to be edited.
                           */
                          className={`${inputCls} ${
                            oicIsProprietor ? 'cursor-not-allowed bg-line/60 text-ink-secondary' : ''
                          }`}
                          title={
                            oicIsProprietor
                              ? 'A sole proprietor is their own officer in charge. This is taken from items 10 to 13.'
                              : undefined
                          }
                        />
                      </label>
                      {/*
                        Why the box will not take a keystroke, for the reader who
                        cannot see that it is grey. `sr-only` rather than on the
                        page: this form has spent the week having explanations
                        taken out of it, and a sighted applicant has the grey box,
                        the name already in it and the title on hover.
                      */}
                      {oicIsProprietor && (
                        <p id="oic-prefilled" className="sr-only">
                          A sole proprietor is their own officer in charge, so this is filled in from
                          items 10 to 13 and cannot be edited here.
                        </p>
                      )}
                    </div>
                    <div className="grow basis-[11rem]">
                      <label className="block">
                        {/*
                        The paper's own wording, item 14: "Citizenship (of
                        President/OIC)". It was a label plus an explanatory line
                        underneath, which said the same thing in more words and
                        in a place the eye reaches after the input. On the label
                        it is read before the field it qualifies.
                      */}
                        <FieldLabel required={hasPresidentOrOfficer(form.registration_type)}>
                          16. Citizenship (of President/OIC)
                        </FieldLabel>
                        {/*
                          A choice, not a sentence. It was free text, which put
                          "Filipino", "filipino" and "Pilipino" into the city's
                          business register as three different answers to one
                          question — and the register is the only reason this
                          field is asked, since nothing in BizTrack reads it to
                          decide anything.

                          It also makes item 17 computable for a sole
                          proprietor, which is what the client asked about on
                          27 September 2026.

                          Two options and not a country list. The paper asks
                          citizenship because the register counts Filipino and
                          non-Filipino ownership; which foreign nationality is
                          the applicant's to write, and a dropdown of two
                          hundred would be a worse way to say "Chinese" than a
                          box.
                        */}
                        <select
                          value={citizenshipMode}
                          onChange={(e) => {
                            const mode = e.target.value
                            setCitizenshipOther(mode === 'other')
                            // Filipino writes itself; the other two clear the
                            // box so nothing typed under one option is
                            // submitted under another.
                            update('citizenship', mode === 'filipino' ? 'Filipino' : '')
                          }}
                          className={inputCls}
                        >
                          <option value="">Select</option>
                          <option value="filipino">Filipino</option>
                          <option value="other">Other</option>
                        </select>
                      </label>
                      {/*
                        Only for the rare filing, so only the rare filing pays
                        the extra line in a row the client packed deliberately.
                      */}
                      {citizenshipMode === 'other' && (
                        <input
                          value={form.citizenship}
                          onChange={(e) => update('citizenship', e.target.value)}
                          placeholder="Which nationality"
                          maxLength={100}
                          aria-label="Citizenship of the President or Officer in Charge"
                          className={`${inputCls} mt-2`}
                        />
                      )}
                    </div>
                    <div className="relative grow basis-[11rem]">
                      <label className="block">
                        <FieldLabel required={hasPresidentOrOfficer(form.registration_type)}>
                          17. Capital Participation (% Filipino)
                        </FieldLabel>
                        {/*
                          Locked for a sole proprietor, the way item 15 is, and
                          for the same reason: it is not a second question, it
                          is item 16 restated. Typed for every other structure,
                          where the share is pooled and genuinely varies.

                          `readOnly`, never `disabled` — a disabled control is
                          skipped by the tab order and its value is dropped from
                          a form submission (AGENTS 6.2). The grey is the same
                          one item 15 uses, because read-only has no appearance
                          of its own and without it the box looks editable and
                          refuses to be edited.
                        */}
                        <input
                          inputMode="decimal"
                          value={form.capital_participation_filipino}
                          onChange={(e) => update('capital_participation_filipino', e.target.value)}
                          onBlur={() => touch('capital_participation_filipino')}
                          /*
                           * No placeholder while the box is derived. "Follows
                           * item 16" went in on 27 September 2026 so a locked,
                           * empty box with a red asterisk would not read as
                           * broken; the client had it removed on 30 September.
                           * The hint below the field already says where the
                           * figure comes from, so this was saying it twice.
                           */
                          placeholder={oicIsProprietor ? '' : 'e.g. 100'}
                          readOnly={oicIsProprietor}
                          aria-readonly={oicIsProprietor || undefined}
                          aria-describedby={
                            fieldErrors.capital_participation_filipino
                              ? 'capital-participation-error'
                              : oicIsProprietor
                                ? 'capital-participation-derived'
                                : undefined
                          }
                          title={
                            oicIsProprietor
                              ? 'A sole proprietor owns all of the capital, so this follows item 16.'
                              : undefined
                          }
                          className={`${inputCls} tnum ${
                            oicIsProprietor ? 'cursor-not-allowed bg-line/60 text-ink-secondary' : ''
                          }`}
                          aria-invalid={Boolean(fieldErrors.capital_participation_filipino)}
                        />
                      </label>
                      {/*
                        Why the box will not take a keystroke, for the reader who
                        cannot see that it is grey. `sr-only` on item 15's
                        reasoning: a sighted applicant has the grey box, the
                        figure already in it and the title on hover, and this
                        form has spent the week having explanations taken out.
                      */}
                      {oicIsProprietor && (
                        <p id="capital-participation-derived" className="sr-only">
                          A sole proprietor owns all of the capital, so this is 100 percent when
                          item 16 is Filipino and 0 percent otherwise. It cannot be edited here.
                        </p>
                      )}
                      {fieldErrors.capital_participation_filipino && (
                        <FieldError id="capital-participation-error">
                          {fieldErrors.capital_participation_filipino}
                        </FieldError>
                      )}
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>
        </FormSheet>
      </WizardSection>

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
      <WizardSection
        name="operation"
        inSequence={sequence.includes('operation')}
        phase={phase}
        reviewing={reviewAll}
        label={BASE_LABELS.operation}
        open={sectionOpen.operation}
        onToggle={() => toggleSection('operation')}
        answers={reviewAnswers.operation}
        editing={sectionEditing.operation}
        onEdit={(focusId) => editSection('operation', focusId)}
        missing={missingFor('operation')}
      >
        <FormSheet meta={typeMeta} filing={filingIdentity} compact={reviewAll}>
          <SectionMarker letter="B" label="Business Operation" />
          {/*
            ── The paper's order, numbered 1-8 ───────────────────────────────

            This step rendered in SOURCE order, which put Economic Organization
            — item 6 on MCG-BPLO-FO-001 — first, and buried items 1 to 4 at the
            bottom inside the fee-profile component. The client asked for the
            paper's sequence and for visible numbers.

            Renumbered 1-8 with no gaps, at the client's choice over keeping the
            paper's own numbers with holes in them. Two of the paper's boxes are
            deliberately not here and so are not numbered:

              item 5, Business Location Address — asked on Location & Zoning,
                and the client's rule is that it is not asked twice
              the Line of Business / Products / PSIC table — same, asked on
                Location & Zoning where the trade is declared

            So the mapping from screen to paper is: 1-4 unchanged, 5 is the
            paper's 6, 6 is the paper's 7, 7 is the paper's 8, 8 is the paper's
            9. Anybody reconciling the two needs to know that, which is why it
            is written down here rather than left to be worked out.
          */}
          <div className="mt-3 space-y-4">
            {/* Items 1-4 — business area, the employee counts, delivery units. */}
            {/*
            Section B items 1-4: business area, employees and their split, how
            many live in the LGU, and the delivery units. They were on the fee
            step because they price the permit; the paper asks them here, and
            the client's rule is that the wizard follows the paper.

            The same component the fee step mounts, scoped. Both write one
            `FeeProfileDraft`, so nothing about the calculation changed — only
            where the questions are put.
          */}
            <div className="mt-4">
              <FeeProfileStep
                scope="paper"
                applicationType={applicationType}
                registrationType={form.registration_type}
                permitCodes={[BUSINESS_PERMIT_CODE]}
                lines={feeLines}
                value={feeDraft}
                onChange={setFeeDraft}
              />
            </div>

            {/*
             * ── Item B6 — Economic Organization ───────────────────────────
             *
             * Six mutually exclusive answers, so a real radiogroup with an
             * accessible name, matching the Form of Organization picker above
             * and the "which permit are you renewing" list. `aria-pressed`
             * toggles would announce six independent switches and never say
             * that picking one unpicks another.
             *
             * See ECONOMIC_ORGANIZATIONS for why this is not the Type of
             * Registration question again: that one asks what the BUSINESS is,
             * this asks what this PREMISES is to it.
             */}
            <div>
              <FieldLabel required>5. Economic Organization</FieldLabel>
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
                <div className="relative mt-3">
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
                    <FieldError id="economic-organization-others-error">
                      {fieldErrors.economic_organization_others}
                    </FieldError>
                  )}
                </div>
              )}
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
            {/*
              Items 6, 7 and 8 on one row, at the client's ask. A money box and
              four Yes/No pills had three bands of page to themselves because
              each sat in its own block-level div — nothing about the controls
              needed the height.
            */}
            <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
              <div className="grow basis-[13rem] max-w-full">
                <label className="block">
                  <FieldLabel required={applicationType === 'new'}>
                    6. Capital Investment (₱)
                  </FieldLabel>
                  <input
                    inputMode="decimal"
                    value={form.capital_investment}
                    onChange={(e) => update('capital_investment', formatAmountInput(e.target.value))}
                    /*
                     * Padded to centavos on blur, not on change.
                     *
                     * `formatAmountInput` groups thousands as you type, so "1000"
                     * showed as "1,000" and stayed there — a peso amount printed
                     * without its centavos. Padding on every keystroke instead
                     * would fight the caret: typing "1000.5" would become
                     * "1,000.50" mid-entry and put the cursor behind the digit
                     * still being typed.
                     *
                     * `padAmountInput` leaves a digit-free string alone, so a blank
                     * field stays blank rather than becoming "0.00" — a
                     * capitalization of zero is a declaration nobody made, and on a
                     * new filing this field is required precisely so it cannot be
                     * skipped silently.
                     */
                    onBlur={() => {
                      update('capital_investment', padAmountInput(form.capital_investment))
                      touch('capital_investment')
                    }}
                    placeholder="e.g. 250,000.00"
                    className={inputCls}
                  />
                </label>
                {applicationType !== 'new' && (
                  <p className="mt-1 text-xs text-ink-secondary">
                    A renewal is assessed on last year’s gross sales, so this is optional.
                  </p>
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
              <div className="shrink-0">
                <FieldLabel>7. Tax incentives from a Government Entity?</FieldLabel>
                <div
                  role="radiogroup"
                  aria-label="Tax incentives from a Government Entity?"
                  className="flex flex-wrap gap-2"
                >
                  {/*
                    Yes first, which is the paper's own order: item 8 on the
                    printed form reads "Yes (Please attach a copy of your
                    certificate)" and then "No". Asking in the order the form
                    asks is one less thing for a clerk reconciling the two to
                    trip over.
                  */}
                  {[
                    { value: true, label: 'Yes' },
                    { value: false, label: 'No' },
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
                {/*
                  It used to say "attach it under Other Requirements", which is
                  the instruction the client objected to: Other Requirements is a
                  bin, so a certificate put there is indistinguishable from a
                  filing that never needed one, and no office can tell which it is
                  looking at. The certificate has its own requirement now
                  (TAX_INCENTIVE_CERT, gated on this answer), so the note points
                  at the slot instead of at the bin.
                */}
                {form.has_tax_incentives && (
                  <p className="mt-2 text-xs text-ink-secondary">
                    The paper asks for a copy of the certificate. A slot for it appears under
                    Documentary Requirements.
                  </p>
                )}
              </div>

              {/*
                ── Item 8 — rent, MOVED HERE from Location & Zoning ─────────────

                The paper asks it in section B and BizTrack asked it on the
                address step, alongside the lessor fields. The client's decision
                of 16 September 2026 was to move the whole block — question and
                the four lessor fields — rather than carry the answer read-only,
                so this step matches the paper and the question is asked exactly
                once.

                Nothing about the DATA moved with it: is_rented, lessor_name,
                lessor_address, lessor_contact and monthly_rental are the
                same fields on the same business record, and every reader of them
                — the zoning sheet's items VIII.C/D, CPDD's owned-or-rented
                checklist branch, the lease-contract documentary requirement — is
                unchanged. Only the step that asks moved.
              */}
              {/*
               * Unified form asks who owns the premises. Only a renter has a
               * lessor, so the block stays closed until they say so rather
               * than showing four fields most applicants must leave blank.
               */}
              <div className="shrink-0">
                <FieldLabel>8. Do you pay rent for the premises?</FieldLabel>
                {/*
                    ── "Yes" and "No", in the paper's order ────────────────────

                    These read "Owned or occupied by me" and "Rented", which
                    answered a question the form does not put. The paper asks a
                    yes-or-no — "Do you pay rent for occupying a place of
                    business? [ ] Yes (Please attach a copy of your lease
                    contract) [ ] No" — so the buttons say Yes and No, Yes first,
                    as printed.

                    A real radiogroup, not two aria-pressed toggles. The two
                    answers are mutually exclusive and `aria-pressed` announces
                    them as independent switches, never saying that picking one
                    unpicks the other — the same reasoning the Economic
                    Organization picker above already carries, and item 7 beside
                    this one was already a radiogroup while this was not.
                  */}
                <div
                  role="radiogroup"
                  aria-label="Do you pay rent for the premises?"
                  className="flex flex-wrap gap-2"
                >
                  {[
                    { rented: true, label: 'Yes' },
                    { rented: false, label: 'No' },
                  ].map((opt) => {
                    const selected = form.is_rented === opt.rented
                    return (
                      <button
                        key={opt.label}
                        type="button"
                        role="radio"
                        aria-checked={selected}
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
                {/*
                    The paper's parenthesis, moved out of the label.
                    MCG-BPLO-FO-001 item 9 reads "Yes (Please attach a copy of
                    your lease contract)", and the instruction was lost when the
                    buttons became a bare Yes/No — item 7 beside it kept its note
                    and this one had none at all, so an applicant answering Yes
                    here was told nothing about the document it commits them to.
                    Shown only on Yes, because on No there is nothing to attach.
                  */}
                {form.is_rented && (
                  <p className="mt-2 max-w-[22rem] text-xs text-ink-secondary">
                    The paper asks for a copy of your lease contract and your lessor&rsquo;s business
                    permit. Slots for both appear under Documentary Requirements.
                  </p>
                )}
              </div>
            </div>
          </div>

          {/*
            ── What the paper does not ask, after everything it does ─────────

            The second half of the fee inputs: how the trade is taxed, gross
            sales on a renewal, and what the business does that carries a fee
            of its own. These had a step to themselves, "Tax Classification &
            Fees", until the client removed it on 16 September 2026 as absent
            from MCG-BPLO-FO-001 — which it is.

            Here, and specifically AFTER item 8, because the paper's items run
            1 to 8 in sequence and items 5-8 are drawn above by this file. A
            single mount would wedge these between items 4 and 5, putting our
            own questions in the middle of the city's.
          */}
          <div className="mt-8 border-t border-line pt-6">
            <FeeProfileStep
              scope="extras"
              applicationType={applicationType}
              registrationType={form.registration_type}
              /*
               * The business permit's questions, and only those. This used to
               * be whichever clearances had been ticked three steps back,
               * which is what grew the step an occupancy section and a market
               * section mid-flow. Each clearance carries its own fees on its
               * own stage.
               */
              permitCodes={[BUSINESS_PERMIT_CODE]}
              lines={feeLines}
              value={feeDraft}
              onChange={setFeeDraft}
            />
          </div>
        </FormSheet>
      </WizardSection>

      {/*
        The per-office form sheets (p040-043) are mounted by the LGU Clearances
        stage now. OfficeFormStep.tsx is unchanged — only where it is mounted
        moved, because a sheet is the second half of applying for a clearance
        and the clearance is no longer applied for here.
      */}

      {/* ── Documents + Data Privacy Consent (p36) ─────────────────────── */}
      <WizardSection
        name="documents"
        inSequence={sequence.includes('documents')}
        phase={phase}
        reviewing={reviewAll}
        label={BASE_LABELS.documents}
        open={sectionOpen.documents}
        onToggle={() => toggleSection('documents')}
        answers={reviewAnswers.documents}
        editing={sectionEditing.documents}
        onEdit={(focusId) => editSection('documents', focusId)}
        missing={missingFor('documents')}
      >
        <div className="rounded-sm bg-white px-6 py-7 shadow-card sm:px-9 sm:py-8">
          <SectionMarker letter="C" label="Documentary Requirements" />
          <OriginalsNotice />

          {/*
            ── Which boxes produced this list ──────────────────────────────

            The rules were right and looked ignored. FO-003 prints a separate
            requirements list against each of its four boxes, and the step
            already resolved them — but nothing on the screen said so, and the
            intro still described the new application's rules ("whether you
            rent, whether you hold a tax incentive"). Client, 21 September
            2026: *"I also told you that there should be necessary
            rules/pathing as to what is asked in the documentary requirements
            DEPENDING ON WHAT IS BEING AMENDED."*

            It already did. Naming the boxes is what makes that checkable
            without reading the pivot table — and it is the same source the
            rules read, so the sentence cannot claim a box the list did not
            actually use.
          */}
          {applicationType === 'amendment' && amendGroups.some((g) => g.rows.some((r) => r.requested)) && (
            <p className="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1 text-xs text-ink-secondary">
              <span className="font-semibold text-ink">Because you are amending:</span>
              {amendGroups
                .filter((g) => g.rows.some((r) => r.requested))
                .map((g) => (
                  <span
                    key={g.key}
                    className="rounded-md border border-line bg-shell px-2 py-0.5 text-[12px] font-semibold text-ink"
                  >
                    {g.paper === null ? g.label : `${g.paper}. ${g.label}`}
                  </span>
                ))}
            </p>
          )}

          {/* OCR-lite suggestion banner (v2) — dismissible, suggestions only. */}
          {ocr && (
            <div className="mt-3 flex flex-wrap items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
              <span className="min-w-0 flex-1">
                We read your document:{' '}
                {ocr.business_name && (
                  <>
                    Business name “<span className="font-semibold">{ocr.business_name}</span>”
                  </>
                )}
                {ocr.registration_number && (
                  <>
                    {' '}
                    · Registration no. “
                    <span className="font-semibold">{ocr.registration_number}</span>”
                  </>
                )}{' '}
                · Use it?
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
          <div className="mt-3 space-y-3.5">
            {requiredDocs.length === 0 ? (
              <p className="text-sm text-ink-secondary">
                No documents required for the selected permits.
              </p>
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
                      className={`flex cursor-pointer items-center gap-3 rounded-lg border-2 border-dashed border-input-border bg-input/50 px-5 py-3.5 transition-colors hover:bg-input ${
                        busy ? 'opacity-60' : ''
                      }`}
                    >
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-input-border bg-white text-royal">
                        <UploadIcon size={18} />
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm font-bold text-ink">
                          {/*
                            Not numbered, deliberately. The numbers elsewhere on
                            this wizard are the PAPER's own item numbers —
                            section B's 1 to 8 — and they mean something: a
                            clerk holding the printed form finds item 5 in both
                            places. A count of the documentary requirements
                            would look like the same kind of number and be a
                            different kind entirely, since two of the paper's
                            six are absent (BizTrack issues them) and the rest
                            come and go with the applicant's own answers.
                            Removed at the client's instruction, 16 September
                            2026, having been added a few minutes earlier.
                          */}
                          {dt.name}
                          {dt.is_required === false ? (
                            <span className="ml-1 font-normal text-ink-muted">(optional)</span>
                          ) : (
                            <span>
                              <span className="text-s-red" aria-hidden="true">
                                {' '}
                                *
                              </span>
                              <span className="sr-only"> (required)</span>
                            </span>
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
                              <span className="min-w-0 flex-1 truncate text-sm text-ink">
                                {f.name}
                              </span>
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
              <p className="mb-1.5 block text-[13px] font-semibold text-ink">
                Other Requirements <span className="font-normal text-ink-muted">(optional)</span>
              </p>
              <p className="mt-1 text-xs text-ink-muted">
                Attach any other supporting documents. You can add more than one file.
              </p>
              {/*
                No TIN notice here — 27 September 2026.

                One stood here for part of a day, saying an officer would ask
                for the TIN under Other Requirements. Both halves were wrong.
                Nothing in the system puts a TIN here: there is no TIN document
                type, and no code that would make one. And a TIN is a FIELD, so
                a blank one is not a missing attachment — asking for it in the
                document bin would make the requirement change KIND depending on
                whether it was answered, which is the inconsistency the client
                named.

                A blank TIN is chased by returning the form pointed at item 3
                (`form:tin` in returnTargets.ts), which reopens that box for the
                applicant to fill in. The Confirm step says so.
              */}
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
                      <span className="tnum shrink-0 text-xs text-ink-muted">
                        {formatBytes(f.size)}
                      </span>
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

          {/*
            ── Mode of Payment (MCG-BPLO-FO-002, foot of page 1) ─────────────

            Renewal only, because only the renewal paper asks it. FO-001 has no
            such box, and putting one on a new application would be BizTrack
            inventing a question.

            ── The sentence under it is the whole point of the design ────────

            This picker existed before and the client had it removed on
            16 September 2026, because nothing read the answer: the fee engine,
            the Tax Order of Payment and the payment stage all bill the full
            year regardless. An applicant could elect quarterly and be handed
            an annual bill with no explanation.

            None of that has been built since, so the honest way to put the
            field back is to record the answer AND say what it does. The client
            chose that over a silent field when asked, 24 September 2026.
          */}
          {applicationType === 'renewal' && (
            <div className="mt-8 border-t border-line pt-6">
              <FieldLabel>Mode of Payment</FieldLabel>
              <div
                role="radiogroup"
                aria-label="Mode of Payment"
                aria-describedby="payment-mode-note"
                className="flex flex-wrap gap-2"
              >
                {PAYMENT_MODES.map((opt) => {
                  const selected = paymentMode === opt.value

                  return (
                    <button
                      key={opt.value}
                      type="button"
                      role="radio"
                      aria-checked={selected}
                      onClick={() => setPaymentMode(opt.value)}
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
              <p id="payment-mode-note" className="mt-2 max-w-prose text-xs text-ink-secondary">
                Recorded on your filing for BPLO. Your Tax Order of Payment is issued for the
                full year either way — arrange an instalment with the City Treasurer when you
                pay.
              </p>
            </div>
          )}
        </div>
      </WizardSection>

      {/* ── Data Privacy Consent — asked before anything is collected ───── */}
      {phase === 'privacy' && (
        <div className="rounded-sm bg-white px-6 py-7 shadow-card sm:px-9 sm:py-8">
          <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-royal">
            City Government of Malabon · Business Permits &amp; Licensing Office
          </p>
          <h1 className="mt-1.5 text-2xl font-bold text-ink">Data Privacy Consent</h1>
          <p className="mt-1 text-xs text-ink-muted">Data Privacy Act of 2012 (RA 10173)</p>
          <div className="mb-4 mt-3 h-px bg-royal" />

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
          {/*
            Full width and justified, not capped at `2xl`. The card is already
            narrower than the page, so a reading-width cap inside it measured
            the text twice and left a third of the step empty.
          */}
          <p className="text-justify text-sm leading-relaxed text-ink-secondary">
            I have read and understood the Data Privacy Policy and hereby give my consent to the
            City Government of Malabon, and any person acting on its behalf, to collect, store,
            record, process and update my personal data as part of its database and to share said
            data with the national government, its agencies and instrumentalities, and other local
            government units, pursuant to the Data Privacy Act of 2012 (RA 10173).
          </p>

          {/* A control, so it meets the edges of the surface it sits on. */}
          <label className="mt-4 flex cursor-pointer items-start gap-3 rounded-lg border border-input-border bg-royal-tint px-4 py-3.5 text-sm font-semibold text-royal">
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
        <FormSheet meta={typeMeta} filing={filingIdentity}>
          {/*
            ── Section A is the RENEWAL form's, and only the renewal's ─────────

            A1 ("has anything changed since last year"), A2's four category
            ticks and A3's structure conversion are MCG-BPLO-FO-002 — the
            renewal form. They were shown on the amendment form too until the
            client asked why: *"Wouldn't it be good if this part is part of the
            form itself?"*

            It would, and the duplication was the smaller half. On an
            amendment, filling in a new value IS saying what you are amending,
            so the ticks asked the same question a second time — in a
            vocabulary that did not line up with the answer. Two of the four
            categories (Ownership, Nature of Business) name things the LGU does
            not let anybody amend, so the first thing on the screen invited a
            request the server refuses; and three details that ARE amendable —
            floor area, employees, trade name — had no category and hid under
            "Others".

            A1 is incoherent here besides: an amendment exists BECAUSE something
            changed, so asking is asking somebody to confirm why they came.
          */}
          {applicationType !== 'amendment' && (
            <>
              <h2 className="text-[13px] font-bold uppercase tracking-[0.12em] text-royal">
                A. Business Information and Registration
              </h2>
              <div className="mb-4 mt-2 h-px bg-royal/30" />
            </>
          )}

          {/* ── A1 ─── renewal only; see the note above ─────────────────── */}
          {applicationType !== 'amendment' && (
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
          )}

          {/* ── A1 = No · the form is finished ─────────────────────────── */}
          {applicationType !== 'amendment' && amendment.hasChanges === false && (
            <div className="mt-3 max-w-2xl rounded-lg border border-input-border bg-royal-tint px-5 py-3">
              <p className="text-sm font-semibold text-royal">Your renewal is ready to submit.</p>
              <p className="mt-1.5 text-xs leading-relaxed text-ink-secondary">
                Nothing has changed, so there is nothing further to fill in. Every detail carries
                over from the permit you are renewing. Press Next to check it over and file.
              </p>
            </div>
          )}

          {/* ── A2 ─── renewal only ─────────────────────────────────────── */}
          {applicationType !== 'amendment' && amendment.hasChanges === true && (
            <fieldset ref={amendmentRef} className="mt-4 border-0 p-0">
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
                        setAmendment((a) => ({
                          ...a,
                          [kind.key]: e.target.checked,
                        }))
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
                  <span className="mb-1.5 block text-sm font-medium text-ink">
                    Others (specify)
                  </span>
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

          {/* ── A3 ─── renewal only ─────────────────────────────────────── */}
          {applicationType !== 'amendment' &&
            amendment.hasChanges === true &&
            amendment.ownership && (
              <fieldset className="mt-4 border-0 p-0">
                <legend className="mb-1.5 block text-[13px] font-semibold text-ink">
                  3. Amendment
                </legend>
                <p className="mb-3 max-w-2xl text-xs leading-relaxed text-ink-secondary">
                  Only if the business converted from one legal structure to another. Leave both as
                  they are if ownership changed hands without the structure changing.
                </p>
                <div className="grid max-w-2xl gap-3 sm:grid-cols-2">
                  {[
                    { key: 'fromRegistrationType' as const, label: 'From' },
                    { key: 'toRegistrationType' as const, label: 'To' },
                  ].map((side) => (
                    <label key={side.key} className="block">
                      <FieldLabel>{side.label}</FieldLabel>
                      <select
                        className={inputCls}
                        value={amendment[side.key]}
                        onChange={(e) =>
                          setAmendment((a) => ({
                            ...a,
                            [side.key]: e.target.value,
                          }))
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

          {/*
            ── B. The details themselves ───────────────────────────────────────

            Section A above records WHAT changed. This records what it changed
            TO, which is the whole of what the LGU's Amendment Form asks for —
            every box on that paper has a blank beside it ("CHANGE OF ADDRESS:
            (New Address) ____"), and without somewhere to put the new value an
            amendment could only ever be a note asking an officer to retype the
            register by hand.

            Only the details the LGU has agreed can be amended appear here; the
            server refuses anything else by name — see `AmendableFields`, which
            is laid out as the paper's four checkboxes and is the one place
            that mapping lives.
          */}
          {applicationType === 'amendment' && (
            <>
              {/*
                No letter. Client, 21 September 2026: *"why does 'B. New
                Details' exist? There is no such section named in the amendment
                form paper."* — and there is not. The letters on these headings
                are transcribed from the paper they came off, and this heading
                came off no paper: it was lettered B to sit under the renewal
                form's Section A, which an amendment does not render at all. So
                it was a B with no A above it, claiming to be a section of a
                form that has no such section.

                The BLOCK stays — every box on FO-003 has a blank beside it
                ("CHANGE OF ADDRESS: (New Address) ____") and the new values
                have to be typed somewhere. Only the false provenance goes.
                Awaiting the paper to name it as FO-003 names it.
              */}
              <p className="mb-3 max-w-3xl text-xs leading-relaxed text-ink-secondary">
                The paper form has four boxes and so does this. Tick the ones you are amending and
                fill in the new values — BPLO writes them to your business record when they
                approve, so you do not need to change anything yourself. Clearing a value withdraws
                that request; unticking a box leaves what you typed alone.
              </p>

              {amendError !== null && (
                <p
                  role="alert"
                  className="mb-4 max-w-2xl rounded-md border border-s-red bg-s-red-tint px-3 py-2 text-sm text-ink"
                >
                  {amendError}
                </p>
              )}

              {/*
                ── One section per checkbox on FO-003 ────────────────────────

                Client, 21 September 2026: *"the fields in paper and in system
                DOES NOT REALLY MATCH."* It did not — this was a flat list of
                eight details under a heading ("B. New Details") that is on no
                paper at all, and three of the form's four boxes were missing
                from it entirely.

                The numeral is the paper's own, so somebody holding the form
                beside the screen is reading the same document twice. The
                unnumbered box at the top of FO-003 has no numeral here either.
              */}
              {/*
                The boxes are already on screen; this is about what goes IN
                the "Currently:" line beside each one.

                It used to say "Loading the details you can amend", which was
                true when the whole list was fetched and is now a lie — the
                list is reference data and arrives with the page. What still
                takes a round trip is the register's own values, and on a
                first visit a draft has to be created before they can even be
                asked for.

                `role="status"`, so a screen reader is told the figures are
                still coming rather than reading "Currently:" and stopping.
              */}
              {amendLoading && (
                <p role="status" className="max-w-2xl text-xs text-ink-secondary">
                  Fetching what your record says now — you can start typing.
                </p>
              )}

              {/*
                ── The step uses the width of the sheet ────────────────────

                This was `max-w-2xl` — 42rem — inside a card two-thirds wider
                again, so seventeen one-per-row fields ran down the left and
                the right third of every section was empty. Client,
                21 September 2026: *"please maximize the spacing. You leave so
                much space at the right side. You may even have two fields
                side by side if needed."*

                PROSE stays narrow, because a 42rem measure is a readability
                rule and not wasted space. It is the FIELDS that widen.
              */}
              <div className="space-y-8">
                {amendGroups.map((group) => {
                  const asked = group.rows.filter((r) => r.requested)
                  const open = (openGroups ?? []).includes(group.key)

                  return (
                  <section key={group.key}>
                    {/*
                      A real checkbox, because it is one on the paper and
                      because the thing it controls is a region of the form —
                      not a button that happens to reveal something.
                    */}
                    <label className="flex cursor-pointer items-start gap-3">
                      <input
                        type="checkbox"
                        checked={open}
                        onChange={(e) =>
                          setOpenGroups((current) => {
                            const now = current ?? []

                            return e.target.checked
                              ? [...now, group.key]
                              : now.filter((k) => k !== group.key)
                          })
                        }
                        className="mt-0.5 h-4 w-4 shrink-0 accent-royal"
                      />
                      <span className="min-w-0">
                        <span className="block text-[13px] font-bold uppercase tracking-[0.12em] text-royal">
                          {group.paper === null ? group.label : `${group.paper}. ${group.label}`}
                        </span>
                        {/*
                          What is inside, for a box that is shut. A heading
                          alone gives an applicant no reason to open one, and
                          "Floor area (sqm), Trade name" tells somebody who
                          came back to a draft what they already asked for
                          without making them open all four.
                        */}
                        <span className="mt-0.5 block text-xs text-ink-secondary">
                          {asked.length > 0
                            ? asked.map((r) => r.label).join(', ')
                            : group.rows
                                .filter((r) => r.type !== 'note')
                                .map((r) => r.label)
                                .join(' · ')}
                        </span>
                      </span>
                    </label>
                    <div className="mb-4 mt-2 h-px bg-royal/30" />

                    {/*
                      Unticked boxes keep whatever was typed in them. Closing
                      one is not withdrawing a request — the withdrawal is
                      clearing the box, which is what the server's DELETE
                      means — so a mis-click cannot lose an answer.
                    */}
                    {open && (
                    <>

                    {/*
                      What ticking this box will cost, said before it is ticked.

                      A cross-barangay move re-applies for the Zoning Clearance
                      — `WorkflowService::amendmentCrossesBarangay` is the rule
                      and this is the warning — and an added line of business
                      carries a surcharge to the January renewal. Both are
                      consequences an applicant should meet on the way in, not
                      on the bill.
                    */}
                    {/*
                      The paper's box that BizTrack does not offer, named
                      where somebody would look for it. FO-003 has an
                      "ADDITIONAL LINE OF BUSINESS" line; a business here
                      holds exactly one trade, and the apply wizard replaces
                      rather than appends, so a second one is a state no other
                      path can produce. An absence explains nothing.
                    */}
                    {group.key === 'other' && (
                      <p className="mb-4 text-xs leading-relaxed text-ink-secondary">
                        Changing your line of business replaces the one on record. BizTrack holds
                        one trade per business, so taking on a{' '}
                        <span className="font-semibold text-ink">second</span> trade alongside it
                        is still done at the BPLO window.
                      </p>
                    )}

                    {/*
                      The other two changes that cost a Zoning Clearance (Art.
                      IX §8). Said where the change is made, as the move's
                      warning is under the address — not discovered when a
                      step appears in the bar.
                    */}
                    {group.key === 'other' && amendNeedsZoning && !amendMovesPremises && (
                      <p className="mb-4 rounded-lg border border-input-border bg-royal-tint/40 px-4 py-3 text-xs leading-relaxed text-ink-secondary">
                        <span className="font-semibold text-ink">
                          This needs a new Zoning Clearance.
                        </span>{' '}
                        The zoning rules ask for one whenever the line of business changes or the
                        floor area grows, so it is applied for as part of this amendment and the
                        City Planning Office checks it.
                      </p>
                    )}

                    {group.key === 'address' && amendMovesPremises && (
                      <p className="mb-4 rounded-lg border border-input-border bg-royal-tint/40 px-4 py-3 text-xs leading-relaxed text-ink-secondary">
                        <span className="font-semibold text-ink">
                          You have moved the pin, so this counts as a move.
                        </span>{' '}
                        Your Zoning Clearance is re-applied for as part of this amendment: the City
                        Planning Office checks the new location against the zoning map, and only
                        they can say whether your trade is allowed there. Correcting how your
                        address is written — without moving the pin — does not need one.
                      </p>
                    )}

                    {/*
                      Two to a row on anything wider than a phone. A floor
                      area, an employee count and a house number are each a
                      few characters wide; giving them a full row apiece is
                      what made this step a column of mostly-empty boxes.

                      `items-start`, so a field whose help text runs to two
                      lines does not stretch the box beside it.
                    */}
                    <div className="grid items-start gap-3 sm:grid-cols-2">
                      {group.rows.map((row) => (
                        <div
                          key={row.field}
                          /*
                           * The three that cannot share a row: a map, a PSIC
                           * picker whose results drop down over what follows,
                           * and a note whose whole point is room to write.
                           */
                          className={`rounded-lg border border-input-border bg-white px-4 py-3 ${
                            row.type === 'pin' || row.type === 'psic' || row.type === 'note'
                              ? 'sm:col-span-2'
                              : ''
                          }`}
                        >
                          {/*
                            ── The detail, and the comparison it is asking ────

                            The field's name, then Current over New.

                            Four shapes, and the first three are worth keeping
                            a record of because each failed in a way the next
                            one fixed:

                            1. small grey text at the right-hand edge of a
                               half-width box, which made the one fact the
                               applicant is comparing against the quietest
                               thing in it;
                            2. a chip on its own line — legible, and a whole
                               line per field;
                            3. that chip beside the title, which was compact
                               and still read as two unrelated facts: a value
                               on record, and an empty box that never said it
                               was where the replacement goes;
                            4. labelled rows. Client, 21 September 2026: *"put
                               it something like 'Current:' then 'New:' so that
                               the comparison is much more visible."*
                          */}
                          <label
                            htmlFor={`amend-${row.field}`}
                            className="text-sm font-medium text-ink"
                          >
                            {row.label}
                          </label>

                          {row.help !== null && (
                            <p className="mt-1 text-xs leading-relaxed text-ink-secondary">
                              {row.help}
                            </p>
                          )}

                          {/*
                            ── Current above New, on the same rail ───────────

                            The two halves of the question the form is asking,
                            labelled and stacked so the box reads as one
                            comparison rather than as a fact and an unrelated
                            empty input. Client, 21 September 2026: *"what if
                            we put it something like 'Current:' then 'New:' so
                            that the comparison is much more visible."*

                            Before this the record sat in a chip beside the
                            field's name and the input below carried no label
                            at all, so nothing on screen said the one was what
                            the other would replace.

                            A fixed rail for the two words, so the values line
                            up under each other — the whole point is reading
                            down the pair, and ragged labels put the two
                            things being compared at different indents.
                          */}
                          {/*
                            Every field gets the pair except the NOTES.

                            There was an exception list beside this test, and
                            it emptied out: an ADDITIONAL line of business went
                            when the register turned out to hold one trade per
                            business, and the NEW OWNER went when it became
                            clear the register plainly knows who owns the
                            business today — "Current: Nena Dela Cruz" is the
                            single most useful comparison on a change of
                            ownership, and it was the one field suppressing it.

                            What is left is the paper's "AMENDMENT OF …
                            DETAILS" blanks, which were never fields and have
                            no current value to compare against. A list with no
                            entries is a test that always passes, so the type
                            is the whole condition now.
                          */}
                          {row.type !== 'note' ? (
                            <div className="mt-2 space-y-1.5">
                              <div className="flex items-baseline gap-2.5">
                                <span className="w-[3.75rem] shrink-0 text-[10px] font-bold uppercase tracking-[0.08em] text-ink-muted">
                                  Current
                                </span>
                                {(row.current_label ?? row.current_value) !== null ? (
                                  <span className="min-w-0 break-words text-[13px] font-semibold text-ink">
                                    {row.current_label ?? row.current_value}
                                  </span>
                                ) : (
                                  <span className="text-[13px] italic text-ink-muted">
                                    not recorded
                                  </span>
                                )}
                              </div>

                              <div className="flex items-start gap-2.5">
                                {/*
                                  Nudged down so the word sits on the input's
                                  own text rather than on the top of its
                                  border — `items-start` is right for a map,
                                  which is 300px tall and must not drag the
                                  label to its middle.
                                */}
                                <span className="mt-2.5 w-[3.75rem] shrink-0 text-[10px] font-bold uppercase tracking-[0.08em] text-royal">
                                  New
                                </span>
                                <div className="min-w-0 flex-1">{renderAmendControl(row)}</div>
                              </div>
                            </div>
                          ) : (
                            /*
                              A note has no "current" to sit above it, so
                              labelling its box "New" against a blank
                              "Current" would invent a comparison that is not
                              being made.
                            */
                            <div className="mt-2">{renderAmendControl(row)}</div>
                          )}

                        </div>
                      ))}
                    </div>
                    </>
                    )}
                  </section>
                  )
                })}
              </div>

              {/*
                An amendment that asks for nothing is a filing BPLO has to
                return, so it is worth saying here rather than at submission.
                Not a block: the applicant may be filling this in over two
                sittings, and refusing to let them leave would lose what they
                have typed.
              */}

              {amendRows.length > 0 && !amendRows.some((r) => r.requested) && (
                <p className="mt-3 max-w-2xl text-xs font-medium text-ink">
                  Nothing is being changed yet. Tick a box above and fill in at least one new
                  value, or this amendment has nothing for BPLO to act on.
                </p>
              )}

              {/*
                ── What happens after BPLO approves ──────────────────────────

                This panel used to read "Not on this form: changing who owns
                the business, your line of business, or moving to another
                barangay is done at the BPLO window." All three are on the
                paper and all three are built as of 21 September 2026, so the
                sentence had become the exact wrong turn it was written to
                prevent: it would have sent somebody to a counter for a change
                the boxes above them offer.

                What is genuinely worth saying is what the applicant cannot see
                from the form — that one of these four boxes finishes at the
                window even after it is approved, and why.
              */}
              <p className="mt-8 max-w-3xl rounded-lg border border-input-border bg-royal-tint/40 px-4 py-3 text-xs leading-relaxed text-ink-secondary">
                <span className="font-semibold text-ink">After BPLO approves.</span> Most of these
                are written to your business record straight away and your Business Permit is
                reprinted with them. A <span className="font-semibold text-ink">change of owner</span>{' '}
                is the exception: your permit prints the name on the BizTrack account it belongs to,
                so BPLO moves the business to the new owner’s account by hand once they have seen
                the Deed of Transfer. The new owner needs a BizTrack account of their own before
                that can happen.
              </p>
            </>
          )}
        </FormSheet>
      )}

      {/*
        ── The Zoning Clearance this amendment applies for ──────────────────

        Its own step, and the only conditional one in the wizard. It joins
        `sequence` exactly when the pin has moved, which is exactly when the
        filing starts carrying a ZONING clearance — the same value the server
        reads (`WorkflowService::amendmentMovesPremises`), so a step cannot
        appear for a clearance the filing will not carry.

        It was a section at the foot of the amendment form, under the last
        address field. That made a second office's form with its own reference
        number read as an appendix to the boxes above it. Client,
        21 September 2026: *"Don't put this here. Instead, there will be a new
        added section here"* — the step bar.

        CPDD's own sheet, not a copy: the same `OfficeFormSheet` the
        clearance stage renders, editable, with its checklist and its
        notarised Applicant Declaration. It began as a read-only preview of
        the questions alone, which was half a form — client: *"I can't see the
        other things like the Applicant Declaration part."*

        Fed the values this amendment is ASKING for, because the register
        still holds the old address until BPLO approves and a sheet built from
        the register would show the officer the premises being left behind.

        The uploads work because the draft already carries the ZONING
        clearance — see the permit-type effect — so there is a pivot row for a
        file to attach to.
      */}
      {phase === 'zoning' && officeSheetBusiness !== null && (
        <>
          {/*
            No masthead here. `OfficeFormSheet` draws its own card, kicker,
            title and form reference from OFFICE_FORM_META — a second one
            above it printed the office's name twice, three centimetres
            apart.
          */}
          <p className="mb-4 max-w-3xl text-xs leading-relaxed text-ink-secondary">
            You have moved the pin, so this amendment also applies for a fresh Zoning Clearance.
            Most of it is filled in from the details you gave on the last step — what it needs
            from you is at the bottom:{' '}
            <span className="font-semibold text-ink">
              the documents CPDD asks for, including the notarised Applicant Declaration
            </span>
            .
          </p>

          {officeError !== null && (
            <p
              role="alert"
              className="mb-4 max-w-3xl rounded-md border border-s-red bg-s-red-tint px-3 py-2 text-sm text-ink"
            >
              {officeError}
            </p>
          )}

          <OfficeFormSheet
            code="ZONING"
            data={officeData.ZONING ?? {}}
            business={officeSheetBusiness}
            onChange={(next) => void saveOfficeForm('ZONING', next)}
            requirements={officeReqs.ZONING ?? []}
            requirementBusy={officeReqBusy}
            onRequirementChange={(documentCode, file, documentId) =>
              changeOfficeRequirement('ZONING', documentCode, file, documentId)
            }
            onDeclarationTemplate={() => void downloadZoningDeclaration()}
          />
        </>
      )}

      {/*
        ── One office's own application form, as a step ─────────────────────

        A renewal of the OTHER permits alone is those offices' applications,
        so their sheets ARE the steps — the LGU's own words, that *"their
        renewal form is the same as their application form"*. The same
        `OfficeFormSheet` the clearance stage renders, not a copy of it, and
        the same endpoints behind it.

        Only reached when `officeSteps` put this phase in the sequence, which
        happens only when the business permit is NOT among the ticked permits.
        Tick it and this is the BPLO form again, with the clearances opening
        after payment as they always have.
      */}
      {officeStepCode(phase) !== null && officeSheetBusiness !== null && (
        <>
          {officeError !== null && (
            <p
              role="alert"
              className="mb-4 max-w-3xl rounded-md border border-s-red bg-s-red-tint px-3 py-2 text-sm text-ink"
            >
              {officeError}
            </p>
          )}

          <OfficeFormSheet
            code={officeStepCode(phase) as OfficeFormCode}
            data={officeData[officeStepCode(phase) as string] ?? {}}
            business={officeSheetBusiness}
            onChange={(next) =>
              void saveOfficeForm(officeStepCode(phase) as OfficeFormCode, next)
            }
            requirements={officeReqs[officeStepCode(phase) as string] ?? []}
            requirementBusy={officeReqBusy}
            onRequirementChange={(documentCode, file, documentId) =>
              changeOfficeRequirement(
                officeStepCode(phase) as OfficeFormCode,
                documentCode,
                file,
                documentId,
              )
            }
            onDeclarationTemplate={
              officeStepCode(phase) === 'ZONING'
                ? () => void downloadZoningDeclaration()
                : undefined
            }
          />
        </>
      )}

      {/*
        ── Review & Submit, part two (p46) ──────────────────────────────────

        The heading and the collapse control are in a block ABOVE the four
        sections, because JSX order is DOM order and they introduce the form
        rather than follow it. What is left here is what belongs after the
        answers: what happens next, the prior-permit note, and the estimate.
      */}
      {phase === 'review' && (
        <div className="rounded-sm bg-white px-6 py-7 shadow-card sm:px-9 sm:py-8">
          <div className="flex flex-col items-center justify-center gap-2 py-6 text-center">
            <p className="text-lg font-medium text-royal">
              {applicationType === 'amendment'
                ? 'Your amendment is ready to submit'
                : clearanceOnlyRenewal
                ? `Your ${priorPermitChoice?.permit_type?.name ?? 'permit'} renewal is ready to submit`
                : 'Your Business Permit application is ready to submit'}
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

              It said the Business Permit was "released after all of them are
              approved". It is released AT PAYMENT (docs/application-flow-
              2026-09.md, `WorkflowService::releaseOutcomePermit`); the five
              clearances follow it, each on its own (tester, 5 October 2026).

              An amendment gets its own sentence: no Tax Order of Payment, no
              five clearances — BPLO reads it and approval applies it
              (docs/amendment-2026-09-19.md §3). The one thing it may still ask
              of the applicant is a new Zoning Clearance, on the same rule that
              puts ZONING on the filing (`amendNeedsZoning`).
            */}
            <p className="max-w-md text-sm text-ink-muted">
              {applicationType === 'amendment'
                ? amendNeedsZoning
                  ? amendMovesPremises
                    ? 'BPLO reviews it. Because the address changes, apply for a new Zoning '
                      + 'Clearance under Permit Tracking right after you submit.'
                    : 'BPLO reviews it. Because of this change, apply for a new Zoning '
                      + 'Clearance under Permit Tracking right after you submit.'
                  : 'BPLO reviews it. The changes apply once it is approved.'
                : clearanceOnlyRenewal
                ? `${renewingOffice ?? 'The issuing office'} reviews this and inspects your ` +
                  'premises. Nothing to pay now — the fee joins your next business permit ' +
                  'renewal in January.'
                : 'BPLO reviews this form first. If they accept it, we raise your Tax Order of '
                  + 'Payment and you pay. Your Business Permit is released as soon as you pay; '
                  + 'the five clearances are applied for after that, each approved on its own.'}
            </p>
            {/*
              ── The summary of payment, on the step that asks for a decision ──

              This panel stood at the foot of a Tax Classification & Fees step,
              below the inputs it answered. That step is gone, and the client
              asked for the summary of payment to live here instead — which is
              also where it does the most work: this is the screen where an
              applicant commits, and the one place a figure is worth reading
              twice.

              Still an ESTIMATE, and still said so in the panel. BPLO assesses
              the real amount and raises the Tax Order of Payment only if it
              accepts the form, so a total here that read as a bill would
              promise a debt nobody has incurred.
            */}
            {/*
              ── A clearance-only renewal is not billed, so it gets no estimate ──

              The panel below quotes a figure, explains that BPLO will assess
              the real one, and tells the applicant to fill in a tax
              classification and gross sales "above". None of the three is true
              here: this filing is never billed at submission — its fee joins
              the next January business permit renewal
              (`Application::defersPayment`) — BPLO never sees it, and the step
              that asks for gross sales is not in this sequence at all.

              So it is replaced rather than left to print an estimate of
              nothing. The one fact worth carrying over is WHEN the money is
              due, which is the half an applicant would otherwise be surprised
              by in January.
            */}
            {/*
              An amendment is not billed either: its fee is an unbilled row
              collected with the next renewal (`recordAmendmentFee`, client,
              19 September 2026). One line, and "if any" because the amount is
              set by `BIZTRACK_AMENDMENT_FEE`, which the browser is not told and
              which stands at ₱0 until the LGU names a figure.
            */}
            {applicationType === 'amendment' ? (
              <>
                {amendRequested.length > 0 && (
                  <div className="mt-6 w-full max-w-lg rounded-lg border border-line bg-shell px-5 py-3">
                    <h2 className="text-left text-[13px] font-bold uppercase tracking-wide text-ink">
                      Changes requested
                    </h2>
                    <div className="mt-1">
                      <RequestedChanges amendRows={amendRequested} />
                    </div>
                  </div>
                )}
                <p className="mt-4 max-w-md text-sm text-ink-secondary">
                  Nothing to pay now. The amendment fee, if any, is added to your next Business
                  Permit renewal.
                </p>
              </>
            ) : clearanceOnlyRenewal ? (
              <div className="mt-8 rounded-lg border border-royal/30 bg-royal-tint px-5 py-3">
                <h2 className="text-[13px] font-bold uppercase tracking-wide text-royal">
                  Nothing to pay now
                </h2>
                <p className="mt-2 text-sm text-ink-secondary">
                  This permit&rsquo;s fee is added to your next business permit renewal in
                  January. You will see it on that Tax Order of Payment.
                </p>
              </div>
            ) : (
            <div className="mt-8 rounded-lg border border-royal/30 bg-royal-tint px-5 py-3">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-[13px] font-bold uppercase tracking-wide text-royal">
                  Estimated fees so far
                </h2>
                {feeEstimateBusy && (
                  <span aria-live="polite" className="text-xs text-ink-secondary">
                    Working it out&hellip;
                  </span>
                )}
              </div>

              {feeEstimateFailed ? (
                <p className="mt-3 text-sm text-ink-secondary">
                  We could not work out an estimate just now. It does not affect your application
                  &mdash; BPLO assesses the actual amount either way.
                </p>
              ) : feeEstimate === null ? (
                <p className="mt-3 text-sm text-ink-secondary">
                  Fill in your tax classification and gross sales above and the estimate appears
                  here.
                </p>
              ) : (
                <>
                  <dl className="mt-3 space-y-1.5">
                    {(feeEstimate.line_items ?? []).map((item, i) => (
                      <div
                        key={i}
                        className="flex flex-wrap items-baseline justify-between gap-x-4"
                      >
                        <dt className="min-w-0 text-sm text-ink">{item.label}</dt>
                        <dd className="tnum shrink-0 text-sm text-ink">
                          {formatMoney(item.amount)}
                        </dd>
                      </div>
                    ))}
                  </dl>
                  <div className="mt-3 flex flex-wrap items-baseline justify-between gap-x-4 border-t border-royal/30 pt-3">
                    <span className="text-sm font-bold text-ink">Estimate</span>
                    <span className="tnum text-base font-bold text-ink">
                      {formatMoney(feeEstimate.total_amount)}
                    </span>
                  </div>
                </>
              )}

              {/*
                Said plainly, and not in small print. The figure is computed by
                the same engine that raises the real bill, but over answers that
                are still being typed and brackets a clerk may read differently —
                so it is an estimate, and the applicant is told who decides.
              */}
              <p className="mt-3 text-xs leading-relaxed text-ink-secondary">
                An estimate from your own answers, including the five other permits every
                application needs. BPLO assesses the actual amount and issues your Tax Order of
                Payment after it approves this form.
              </p>
            </div>
            )}
            {/*
              ── What the business's nature commits them to ──────────────────

              Client, 5 October 2026: rules that tell which Other Requirements
              a business must hold. The list comes with the estimate
              (`other_requirements` on the fee preview) so it describes the same
              business the figure does. Rows that ask for something say so,
              because those are the ones that reappear under Other Requirements
              once this is submitted, and an applicant who was told should not
              be surprised.
            */}
            {(feeEstimate?.other_requirements?.length ?? 0) > 0 && (
              <div className="mt-4 w-full rounded-lg border border-line bg-shell px-5 py-3 text-left">
                <h2 className="text-[13px] font-bold uppercase tracking-wide text-ink">
                  Your business will also need
                </h2>
                <ul className="mt-2 space-y-2.5">
                  {feeEstimate!.other_requirements!.map((r) => (
                    <li key={r.key} className="text-sm">
                      <p className="font-semibold text-ink">
                        {r.title}
                        <span className="ml-2 text-xs font-normal text-ink-muted">{r.article}</span>
                      </p>
                      <p className="mt-0.5 text-xs leading-relaxed text-ink-secondary">{r.summary}</p>
                      {r.asks && (
                        <p className="mt-0.5 text-xs font-semibold text-royal">
                          Asked under Other Requirements after you submit.
                        </p>
                      )}
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {/*
              The zoning rules again, at the point of committing — and the only
              place a renewal or an amendment meets them, since neither walks
              through Location & Zoning. Answerable here too: a question
              skipped on the map step is not a reason to go back.
            */}
            {form.barangay_id && (
              <div className="mt-6 w-full">
                <ZoningRuleChecklist
                  variant="applicant"
                  result={zoningCheck.data}
                  loading={zoningLoading}
                  values={zoningFacts}
                  onAnswer={answerZoning}
                />
              </div>
            )}
            {/*
              Named as well as numbered. This said "Renewing MCB-2026-000003"
              and nothing else — the one line on the confirmation page that
              says what was just filed, and it said it in a reference code.
            */}
            {priorPermitChoice && (
              <p className="mt-4 text-sm text-ink-secondary">
                {applicationType === 'renewal' ? 'Renewing' : 'Amending'}{' '}
                <span className="font-semibold text-ink">
                  {priorPermitChoice.permit_type?.name ?? 'your permit'}
                </span>{' '}
                <span className="tnum">{priorPermitChoice.permit_number}</span>
              </p>
            )}
            {/*
             * Items 82/84 — last chance to see what this filing changes.
             *
             * Off the requested changes, not the Section A ticks. An
             * amendment does not ask those, so the condition here was
             * permanently false and the confirmation page stayed silent about
             * what had just been filed.
             *
             * With the values since 5 October 2026, and moved up under the
             * heading: it named the fields alone ("Trade name, Total
             * employees") at the foot of the page, which told the applicant
             * what they had touched and not what they had asked for. See the
             * "Changes requested" block above the fee line.
             */}
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
      <div className="mt-10 grid items-start gap-4 sm:grid-cols-[minmax(9rem,1fr)_minmax(0,28rem)_minmax(9rem,1fr)]">
        <div className="flex flex-col gap-2">
          <div className="flex items-center gap-3">
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
                /*
                 * Gated on the whole form now, not only on consent.
                 * `missingFor('review')` aggregates every section, and Review
                 * draws every section editable — so a gap here is one the
                 * applicant can close on the screen they are already looking
                 * at, which is the first time that has been true. Submitting
                 * into a refusal from the API, or into a return from BPLO days
                 * later, was the alternative.
                 */
                disabled={saving || !consent || stepMissing.length > 0}
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
          {/*
            On Review the same list covers the WHOLE form, because that is what
            the screen now shows and what `missingFor('review')` aggregates.
            Worded accordingly: "on this part" would send an applicant looking
            for a part, and every part is on the page in front of them.
          */}
          {isLast && stepMissing.length > 0 && (
            <p className="max-w-md text-xs text-ink-muted">
              Still needed before you can submit: {stepMissing.join(', ')}
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

      {/* ── WARNING · a TIN left blank (30 September 2026) ─────────────── */}
      {tinNoticeOpen && (
        <ProtoModal
          title="WARNING"
          cancelLabel="Enter it now"
          confirmLabel="Continue"
          onCancel={() => setTinNoticeOpen(false)}
          /*
            Continue advances. The TIN has been optional since BPLO said so
            on 24 September 2026, so this states a consequence rather than
            refusing — and stating it here, on the press that skips the
            question, is the point: it used to sit on the Confirm step,
            eleven sections later, where it arrived as news.
          */
          onConfirm={() => {
            setTinNoticeOpen(false)
            void next()
          }}
        >
          <p className="text-center text-base">
            Without a TIN, it becomes an Other Requirement you must submit before
            your next business renewal.
          </p>
        </ProtoModal>
      )}

      {/* ── WARNING · Clear All (p35) ──────────────────────────────────── */}
      {showClear && (
        <ProtoModal
          title="WARNING"
          onCancel={() => setShowClear(false)}
          onConfirm={clearCurrentPart}
          confirmLabel="Proceed"
        >
          <p className="text-center text-base">
            Are you sure you want to clear all inputs for this part?
          </p>
        </ProtoModal>
      )}

      {/*
        ── The zoning result dialog (p30/p31) is gone ──────────────────────

        It opened on Next from Location & Zoning: CONGRATULATIONS, or SORRY
        under the `?zoning=deny` debug parameter, and it said the same thing
        whatever the ordinance said. The client asked for it inline instead
        (23 September 2026: "Zoning must not be a popup"), so the live
        ZoningConformanceNote under the map carries the answer and Next just
        moves on. CPDO's final say — the line this dialog existed to keep — is
        in that note.
      */}

      {/* ── CONFIRMATION · final submit (p47) ──────────────────────────── */}
      {showConfirm && (
        <ProtoModal
          title="CONFIRMATION"
          /*
           * The two answers are the two things the applicant can actually do,
           * named. "Cancel" and "Proceed" describe the dialog; these describe
           * the filing — and the cancel side is the one that needed it, because
           * on a question about reviewing, "Cancel" reads as "cancel my
           * application" to somebody who has just spent an hour on it.
           */
          cancelLabel="Keep reviewing"
          confirmLabel="Yes, submit"
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
          {/*
            ── Named, because BPLO is not always who receives it ─────────────
            *
            * This said BPLO on every filing. On a clearance-only renewal BPLO
            * is never routed it at all (`WorkflowService::submit`) — the
            * permit's own office reads it and inspects the premises — so the
            * confirmation named the wrong recipient at the moment the
            * applicant commits, which is the worst moment to be wrong about
            * who is receiving their papers.
            *
            * Reported on a Sanitary renewal, 4 October 2026. The same was true
            * of every other clearance: CHO, BFP, OBO, CENRO and CPDD each read
            * their own.
            *
            * `submitOffice` falls back to BPLO, which is right for the filings
            * BPLO really does read — a new application and an amendment both
            * go to its counter first.
            */}
          <p className="pt-4 text-center text-lg">
            Submit this {applicationType === 'amendment' ? 'amendment' : 'application'} to{' '}
            {submitOffice} for approval?
          </p>
          {/*
            ── What the amendment changes, at the press that commits it ──────

            The dialog was generic, so the applicant confirmed an amendment
            without being shown what it amends (tester, 5 October 2026). The
            values while the list is short enough to read in a dialog; past
            six rows the names alone, because the full list would push the
            warning below off the screen and Review has just shown it.
          */}
          {applicationType === 'amendment' && amendRequested.length > 0 && (
            <div className="mb-2 rounded-lg border border-line bg-shell px-4 py-3 text-sm text-ink-secondary">
              {amendRequested.length <= 6 ? (
                <>
                  <p className="font-bold text-ink">Changes:</p>
                  <RequestedChanges amendRows={amendRequested} />
                </>
              ) : (
                <p>
                  <span className="font-bold text-ink">Changes:</span>{' '}
                  {amendRequested.map((r) => r.label).join(', ')}
                </p>
              )}
            </div>
          )}
          {/*
            ── Late, and it costs something ────────────────────────────────

            Named per permit with its expiry date, because "a permit" is not
            something an applicant can check and "your Sanitary Permit expired
            on 23 October" is. The rate is quoted rather than the peso amount:
            the interest runs per month to the filing date, so a figure shown
            here would be the one thing on the dialog that could be wrong by
            the time they press the button.
          */}
          {lapsedBeingRenewed.length > 0 && (
            <div className="mb-2 rounded-lg border border-s-red bg-s-red-tint px-4 py-3 text-sm text-red-900">
              <p className="font-bold">This renewal is late, so a charge is added.</p>
              <ul className="mt-1 list-disc pl-5">
                {lapsedBeingRenewed.map((p) => (
                  <li key={p.id}>
                    {p.permit_type?.name ?? 'This permit'} expired on {formatDate(p.valid_until)}.
                  </li>
                ))}
              </ul>
              <p className="mt-1">
                A 25% surcharge plus 2% interest for each month late is added to the fee
                (Revenue Code 8A.04 and 8A.05).
              </p>
            </div>
          )}
          {/*
            ── What else this press commits them to ───────────────────────

            The names only. The review step above explains each; this is the
            last line before the button, and the client's standing rule for it
            is *"SIMPLIFY AND SHORTEN"*.
          */}
          {(feeEstimate?.other_requirements?.length ?? 0) > 0 && (
            <div className="mb-2 rounded-lg border border-line bg-shell px-4 py-3 text-sm text-ink-secondary">
              <p className="font-bold text-ink">Your business will also need:</p>
              <p className="mt-1">
                {feeEstimate!.other_requirements!.map((r) => r.title).join(' · ')}
              </p>
            </div>
          )}
          {/*
            ── Nothing to pay today ────────────────────────────────────────

            A clearance renewed on its own is issued unbilled and collected
            with the next business permit renewal — `Application::defersPayment`,
            the client's rule of 17 September 2026. The applicant had no way to
            know that from this screen, and a filing that asks for no payment
            reads as one that has gone wrong. Said here because this is the
            press where they expect to be charged [client, 4 October 2026].
          */}
          {clearanceOnlyRenewal && (
            <div className="mb-2 rounded-lg border border-line bg-shell px-4 py-3 text-sm text-ink-secondary">
              <p className="font-bold text-ink">Nothing to pay now.</p>
              <p className="mt-1">
                You pay for this permit when you renew your Business Permit.
              </p>
            </div>
          )}
          {/*
            ── What the press costs, said before it is pressed ────────────────
            *
            * Requested by the client, 16 September 2026, alongside the editable
            * review: the modal confirmed an action without saying that the
            * action is one-way.
            *
            * Written for somebody filing their own permit, not for a lawyer.
            * Short sentences, no "hereby" and no "irrevocable"; "you will not
            * be able to change your answers yourself" rather than "submission
            * is final", because final is vague about WHAT ends — and what ends
            * is self-service editing, not the application. BPLO can still
            * return it, and saying so is what keeps the warning honest instead
            * of frightening.
            *
            * It names the way out, too. A warning that only closes a door
            * makes people abandon the form; this one points at the door that
            * stays open.
            */}
          <div className="mb-2 rounded-lg border border-s-yellow bg-s-yellow-tint px-4 py-3 text-sm text-amber-900">
            <p className="font-bold">Please check your answers first.</p>
            <p className="mt-1">
              Once you submit, you will not be able to change your answers yourself. If{' '}
              {submitOffice} needs a correction, they will return the application to you with a
              note saying what to fix.
            </p>
            <p className="mt-2">
              {/* The button has read "Keep reviewing" since it was renamed; this still said Cancel. */}
              Press <span className="font-semibold">Keep reviewing</span> if you would like to look over
              your application again.
            </p>
          </div>
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
          initial={{
            businessId: prefillBusinessId,
            permitId: priorPermitId,
            permitIds: priorPermitIds,
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
