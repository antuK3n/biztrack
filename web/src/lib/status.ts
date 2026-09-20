import type { ComponentType, SVGProps } from 'react'
import {
  CheckCircleIcon,
  ClockIcon,
  DotIcon,
  DraftsIcon,
  InfoCircleIcon,
  PaymentsIcon,
  SearchIcon,
  ShieldCheckIcon,
  UploadIcon,
  XCircleIcon,
} from '../components/icons'
import type { ChipTone } from '../components/ui/Proto'
import type {
  ApplicationPermitType,
  ApplicationStatus,
  BusinessStatus,
  ClearanceMode,
  ClearanceState,
  ClearanceStatus,
  RequestStatus,
} from './types'

type IconType = ComponentType<SVGProps<SVGSVGElement> & { size?: number }>

/**
 * Tones map to token pairs. "Never color alone": every tone is always rendered
 * with an icon + text label by <StatusBadge>. Red (danger) is errors/denials only.
 *
 * `scheduled` exists because the design gives For Inspection its own yellow band,
 * distinct from the orange of Pending Payment and For Approval. Folding it into
 * `attention` would have been free — and wrong: the theme aliases amber-50 to the
 * yellow tint, so the two states the officer most needs to tell apart in a queue
 * would have rendered as the same swatch.
 */
/**
 * ── One colour per status, because they are read side by side ─────────────
 *
 * Six tones covered ten application statuses, so four of them — For Initial
 * Approval, Pending Payment, For Final Approval and Returned — came out in one
 * identical orange, and Draft and Cancelled in one identical grey. That is
 * tolerable on a row seen alone and useless on the status guide, where all
 * nine are stacked and the whole point is telling them apart. Client's
 * instruction, 17 September 2026.
 *
 * `review` and `verify` are the two "an office is reading it" states, near
 * each other in hue and distinct from each other, which is what they are.
 * `warning` is Returned — your turn, something is wrong — kept clear of
 * `danger`, because a refusal and a request for a correction are not the same
 * news. `muted` is Cancelled: grey like a draft, dashed rather than solid, so
 * the two greys differ without inventing a tenth hue nobody can name.
 *
 * Colour is never the only cue. Every badge carries its label, the rail
 * carries numbers, and the guide carries a sentence each.
 */
export type StatusTone =
  | 'neutral'
  | 'muted'
  | 'progress'
  | 'attention'
  | 'scheduled'
  | 'review'
  | 'verify'
  | 'warning'
  | 'success'
  | 'danger'

export const TONE_CLASSES: Record<StatusTone, string> = {
  neutral: 'bg-shell-deep text-ink-secondary border-line',
  progress: 'bg-blue-50 text-blue-800 border-blue-200',
  attention: 'bg-s-orange-tint text-s-orange-ink border-s-orange',
  /*
   * Project tokens, not Tailwind's defaults. `--color-*: initial` in
   * index.css wipes the default palette, so `violet-50` and the rest do not
   * exist in this build — a badge asking for one renders with no background
   * and no border, silently. These three were written that way first.
   */
  review: 'bg-s-purple-tint text-s-purple border-s-purple',
  verify: 'bg-s-teal-tint text-s-teal-ink border-s-teal',
  warning: 'bg-s-rose-tint text-s-rose-ink border-s-rose',
  muted: 'border-dashed bg-white text-ink-muted border-line',
  scheduled: 'bg-s-yellow-tint text-s-yellow-ink border-s-yellow',
  success: 'bg-green-50 text-green-700 border-green-200',
  danger: 'bg-red-50 text-red-700 border-red-200',
}

export const TONE_ICONS: Record<StatusTone, IconType> = {
  neutral: DotIcon,
  progress: ClockIcon,
  attention: InfoCircleIcon,
  // The two "an office is reading it" tones share the clock, because that is
  // what they share: somebody else is holding it and you are waiting.
  review: ClockIcon,
  verify: ClockIcon,
  warning: InfoCircleIcon,
  muted: DotIcon,
  scheduled: SearchIcon,
  success: CheckCircleIcon,
  danger: XCircleIcon,
}

interface StatusMeta {
  label: string
  tone: StatusTone
  icon: IconType
}

/*
 * MIRROR OF `App\Enums\ApplicationStatus::label()`. Not "roughly the same words"
 * — the same words, character for character.
 *
 * One state used to answer to three names: the API's "Awaiting payment", this
 * file's "For payment", and the design's "Pending Payment". "Under review" was
 * worse — it was the chip printed on rows inside a tab captioned "For Approval",
 * so a single screen disagreed with itself.
 *
 * Do not edit a label here alone. `api/tests/Feature/StatusLabelParityTest.php`
 * parses this object and fails the moment it stops matching the PHP enum, which
 * is the only thing that makes two copies safe to keep. The copies exist because
 * this side labels `issued` — a status the API never puts on an application —
 * and because a filter pill cannot wait for a round trip to know its own caption.
 */
const APPLICATION_STATUS: Record<ApplicationStatus, StatusMeta> = {
  draft: { label: 'Draft', tone: 'neutral', icon: DraftsIcon },
  /*
   * "For INITIAL Approval": BPLO approves a filing twice and this is the
   * first, before any bill exists. CLEARANCE_STATUS below keeps plain "For
   * Approval" — a single clearance is approved once, by its own office, and
   * calling that "initial" would promise a second pass that never comes.
   */
  /*
   * ── The tones, and why each is the one it is ──────────────────────────────
   *
   * These four were all `attention` and therefore all one orange, which the
   * client called out on 17 September 2026. Orange stays with the one status
   * that asks the applicant for money, since that is the convention and the
   * only one of the four they can act on.
   *
   * The two BPLO reads take `review` and `verify` — adjacent in feel,
   * distinct on screen, which is exactly their relationship. Returned takes
   * `warning` and NOT `danger`: being asked to correct something is not
   * being refused, and rendering both in red would have told an applicant
   * their filing was dead when it was waiting for them.
   */
  for_approval: { label: 'For Initial Approval', tone: 'review', icon: ClockIcon },
  returned: { label: 'Returned', tone: 'warning', icon: InfoCircleIcon },
  pending_payment: { label: 'Pending Payment', tone: 'attention', icon: PaymentsIcon },
  awaiting_other_permits: { label: 'Awaiting Other Permits', tone: 'progress', icon: UploadIcon },
  for_final_approval: { label: 'For Final Approval', tone: 'verify', icon: ClockIcon },
  approved: { label: 'Approved', tone: 'success', icon: CheckCircleIcon },
  issued: { label: 'Permit Issued', tone: 'success', icon: ShieldCheckIcon },
  rejected: { label: 'Rejected', tone: 'danger', icon: XCircleIcon },
  // Grey like a draft, because both are inert — but dashed, so the two are
  // not one swatch. A draft is yours to finish; a cancelled filing is over.
  cancelled: { label: 'Cancelled', tone: 'muted', icon: XCircleIcon },
}

/**
 * MIRROR OF `App\Enums\ClearanceStatus::label()`, under the same rule as above.
 *
 * `for_inspection` keeps the yellow `scheduled` band it had when it was an
 * application status — it is the same fact about the same premises, just now
 * attached to one permit rather than to the whole filing, and an officer who
 * learned that colour should not have to relearn it.
 *
 * `available` has no PHP counterpart and is not in the parity test's scope: the
 * API emits it for an optional permit with no pivot row, where there is no
 * status to label.
 */
const CLEARANCE_STATUS: Record<ClearanceStatus, StatusMeta> = {
  not_started: { label: 'Not Yet Submitted', tone: 'neutral', icon: DotIcon },
  /*
   * `review`, matching the application's own For Initial Approval. Same fact
   * in two vocabularies — an office is reading it — so the same colour, which
   * matters on the status guide where the clearance sub-flow is drawn inside
   * the application step it belongs to. It was `attention`, which now means
   * "you owe money" and would have put an orange badge in the middle of a
   * sub-flow about nothing of the kind.
   */
  for_approval: { label: 'For Approval', tone: 'review', icon: ClockIcon },
  for_inspection: { label: 'For Inspection', tone: 'scheduled', icon: SearchIcon },
  approved: { label: 'Approved', tone: 'success', icon: CheckCircleIcon },
  /*
   * No `rejected`. The PHP enum lost the case on 17 September 2026 and this
   * mirrors it — see `ClearanceStatus` in types.ts. A permit is Returned as
   * often as it needs to be; only the FILING can be rejected, and that row is
   * in APPLICATION_STATUS above.
   */
  returned: { label: 'Returned', tone: 'warning', icon: InfoCircleIcon },
  available: { label: 'Available', tone: 'neutral', icon: DotIcon },
}

/**
 * Last resort for a status neither table knows.
 *
 * Both lookups used to fall back to the raw value, and the officer review sheet
 * proved what that costs: the progress rail outlived the September flow change
 * still asking for `under_review` and `for_inspection`, and printed those two
 * strings, underscores and all, as the names of two of its four stages.
 *
 * The rail is fixed at its own end. This is the floor underneath it, because
 * the same thing will happen again the next time the enum moves ahead of a
 * screen, and a stale caption reading "Under Review" is a small wrong answer
 * where "under_review" is a visibly broken one. It is NOT a licence to skip the
 * table: an unmapped status still gets the neutral tone and the blank dot, so
 * it looks like the guess it is, and `StatusLabelParityTest` still fails the
 * build for anything the API can actually send.
 */
function humanizeStatus(status: string): string {
  return status
    .split('_')
    .filter(Boolean)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

export function clearanceStatusMeta(status: string): StatusMeta {
  return (
    CLEARANCE_STATUS[status as ClearanceStatus] ?? {
      label: humanizeStatus(status),
      tone: 'neutral',
      icon: DotIcon,
    }
  )
}

export function applicationStatusMeta(status: string, fallbackLabel?: string): StatusMeta {
  return (
    APPLICATION_STATUS[status as ApplicationStatus] ?? {
      label: fallbackLabel || humanizeStatus(status),
      tone: 'neutral',
      icon: DotIcon,
    }
  )
}

/**
 * Has the applicant started this clearance — applied, or handed in a copy?
 *
 * MIRROR OF `ClearanceService::isAppliedFor`. Both halves, both fields.
 *
 * `available` is a permit type with no pivot row (an optional one nobody asked
 * for); `not_started` is one attached but never acted on, which since
 * `submit()` began attaching all five required clearances is the state every
 * clearance opens in.
 *
 * ── `mode` is the half that was missing, and it cost the Zoning card ─────────
 *
 * This read the state alone, and that was the whole answer while Apply moved
 * the permit straight to For Approval. Splitting apply into two acts — choose
 * the route, then hand the sheet in — left a permit the applicant has
 * demonstrably applied for sitting at `not_started` with `mode = 'apply'`, and
 * the server's copy of this predicate was taught to read `mode` on 6 September
 * 2026 while this one was not.
 *
 * Two doors, two answers, and the applicant was standing in the gap. Pressing
 * Apply on the Zoning / Locational Clearance a second time — which is how you
 * reopen the sheet, by design — asked THIS predicate whether to POST, was told
 * "not started", posted, and was refused by the server's: *"You have already
 * applied for the Zoning / Locational Clearance on this application."* The
 * refusal returns early, so the sheet never opened either. The client reported
 * both symptoms in one sentence: told they had already applied for something
 * they had not submitted, on a card whose button did nothing.
 *
 * So it takes the ROW, not the state. A predicate that mirrors a server rule
 * has to be able to see everything the server rule sees, and a signature that
 * only admits half of it is how this drifts a third time.
 *
 * Named because three screens were spelling it three different ways against a
 * vocabulary the server had stopped using — see `ClearanceState`.
 */
export function clearanceStarted(row: {
  state: ClearanceState
  mode: ClearanceMode | null
}): boolean {
  if (row.state === 'available') return false

  return row.state !== 'not_started' || row.mode !== null
}

/**
 * Is an office HOLDING this clearance — as opposed to the applicant?
 *
 * The other question, and deliberately not the one above. `clearanceStarted`
 * asks whether the applicant has chosen a route; this asks whether they have
 * finished handing it over, which is what turns their controls off.
 *
 * State alone, on purpose: an applied-for clearance whose sheet is still blank
 * is at `not_started` and is still entirely the applicant's to fill in, edit
 * or swap for an uploaded copy. `returned` is excluded for the same reason —
 * an office handing a sheet back is asking for changes, so it is theirs again.
 *
 * Split out of `clearanceStarted` when that gained `mode`. Folding `mode` into
 * this one would have read "the office has it" about a form nobody has typed a
 * word into, and locked the applicant out of the sheet Apply had just opened.
 */
export function clearanceWithOffice(state: ClearanceState): boolean {
  return state !== 'available' && state !== 'not_started' && state !== 'returned'
}

/**
 * Has the applicant paid? MIRROR OF `App\Enums\ApplicationStatus::isPaid()`.
 *
 * Written as a list of PAID states rather than "not one of the unpaid ones",
 * exactly as the PHP is, so that a status added later reads as unpaid until
 * somebody says otherwise — the safe default for anything that makes a claim
 * about money.
 *
 * `issued` has no PHP counterpart: it is the web's own name for an approved
 * filing whose permits are out, and it is on the far side of the bill either
 * way.
 *
 * This exists because a screen was asserting the opposite. Permit Tracking read
 * `status === 'pending_payment'` and treated every other status as settled, so
 * a filing at `for_approval` — which is BEFORE the bill exists — was shown a
 * green "Paid" block. That was true while submission led straight to
 * `pending_payment` and there was nothing between Draft and the bill; the
 * September flow put BPLO's reading of the form in that gap, and the two-way
 * test started answering "Paid" for the one stage where nothing has been
 * charged at all.
 */
export function isPaidStatus(status: ApplicationStatus): boolean {
  return (
    status === 'awaiting_other_permits' ||
    status === 'for_final_approval' ||
    status === 'approved' ||
    status === 'issued'
  )
}

/**
 * How far along the SECOND state machine is — the other permits.
 *
 * `awaiting_other_permits` is one application status covering five permits that
 * each move on their own, and it is the longest stage of a filing. Both the
 * officer's progress rail and the applicant's status card have to say what is
 * still outstanding inside it, so the rule lives here once rather than being
 * counted twice and drifting — the failure `ApplicationVisibility` keeps
 * documenting on the API side, in its browser form.
 *
 * The set is exactly what `WorkflowService::refreshReadiness` gates on:
 * `is_required` permits whose status is anything but Approved. Two exclusions,
 * for two different reasons:
 *
 *  - BUSINESS is the OUTCOME of the process, not one of the permits being
 *    gathered. `PermitType::REQUIRED_CLEARANCE_CODES` leaves it out, so
 *    `is_required` is already false for it; the explicit test is belt and
 *    braces against that list ever changing under us, because counting it would
 *    make a finished filing read "5 of 6".
 *  - Optional permits are left out because they do not hold the filing up. An
 *    applicant who added one still sees it on the clearance stage; what this
 *    counts is what is standing between them and their Business Permit.
 *
 * A null status means no pivot row has been worked yet, which is outstanding.
 */
export interface OtherPermitProgress {
  /** Required clearances on this filing — five, on every filing seeded today. */
  total: number
  approved: number
  /** Permit-type codes still outstanding, in the filing's own order. */
  outstanding: string[]
}

export function otherPermitProgress(
  /*
   * Declared non-nullable and defaulted anyway. `permit_types` is only present
   * when the controller eager-loads the relation, and this session has already
   * paid once for trusting a declared-non-null field that the API had quietly
   * stopped sending — a blank page, not a missing count.
   */
  permitTypes: ApplicationPermitType[] = [],
): OtherPermitProgress {
  const required = (permitTypes ?? []).filter((pt) => pt.is_required && pt.code !== 'BUSINESS')
  const outstanding = required.filter((pt) => pt.status !== 'approved')

  return {
    total: required.length,
    approved: required.length - outstanding.length,
    outstanding: outstanding.map((pt) => pt.code),
  }
}

/** The applicant-facing "what happens next" line for a status. */
export const NEXT_ACTION: Partial<Record<ApplicationStatus, string>> = {
  draft: 'Finish and submit your application when you are ready.',
  for_approval: 'BPLO is reading your form — the first of its two approvals. No action needed yet.',
  returned: 'BPLO asked for changes. Review the remarks, then resubmit.',
  pending_payment: 'Your fees are assessed. Pay to continue processing.',
  awaiting_other_permits:
    'Apply for your other permits, or hand in copies of the ones you already hold. Each is approved and released on its own.',
  for_final_approval: 'Every other permit is in. BPLO is approving the application.',
  approved: 'Everything checks out. Your permit is being issued.',
  issued: 'Your permit is ready. Download it from your permit vault.',
  rejected: 'This application was rejected. See the reason below.',
  cancelled: 'You cancelled this application.',
}

/* Officer-side generic status (assignments, inspections) — tone by keyword. */
export function genericStatusTone(status: string): StatusTone {
  const s = status.toLowerCase()
  if (/(reject|fail|denied|overdue)/.test(s)) return 'danger'
  if (/(approv|pass|complete|issued|done)/.test(s)) return 'success'
  if (/(return|pending|await|schedul|conditional)/.test(s)) return 'attention'
  if (/(review|progress|assigned|inspect)/.test(s)) return 'progress'
  return 'neutral'
}

/*
 * Other Requirements — chip tone by status.
 *
 * Tone only; the WORDS come from the API's `status_label`. It lives here rather
 * than on the requirements page because two screens print these chips — the
 * page itself and the "Other Requirements" panel on the owner's home — and the
 * home page had its own hard-coded `orange` for every row, so a REFUSED
 * document wore the same amber as an ordinary Pending on one screen and red on
 * the other. A reader learns the colour on one page and is then contradicted by
 * the next one they open.
 *
 * Needs Resubmission is orange, the same as Pending, because to a business
 * owner those two are one situation: you owe us a document. Rejected is not —
 * it carries a refusal and a reason, and amber for a refusal reads as
 * "waiting", which is the opposite of what happened.
 */
/**
 * The four business statuses: their words, their chip tone, their timeline dot.
 *
 * ── Why this moved here, from two pages and three tables ──────────────────
 *
 * It was `STATUS_META` and `STATUS_DOT` in OwnersPage and `BUSINESS_TONES` in
 * RecordsPage, and the last of those carried the hazard in its own comment:
 * *"Copied rather than imported… If the palette moves there, move it here too —
 * a register that greys a blacklisting is worse than one that does not colour
 * it at all."* That is a correct description of a rule nothing enforces.
 *
 * The three happened to agree, which is the part that makes it worth fixing
 * rather than leaving: a duplicate that currently matches is invisible, so the
 * first edit to one of them is the one nobody notices. The client's instruction
 * of 17 September 2026 — *"match the colors for the same status. Do this for
 * ALL, even those that are not captured by the screenshot I sent"* — is exactly
 * about the ones you cannot see, and two screens that colour the same status
 * from two tables are two screens waiting to disagree.
 *
 * `ChipTone` and not `StatusTone`, deliberately. A business status is not a
 * filing status: it is not on the workflow, nothing transitions through it, and
 * these render as the admin tables' solid chips beside owners and users. One
 * table per vocabulary is the rule here — the fault was three tables for one
 * vocabulary, not the existence of more than one vocabulary.
 */
export const BUSINESS_STATUS: Record<
  BusinessStatus,
  { label: string; tone: ChipTone; dot: string }
> = {
  active: { label: 'Active', tone: 'tint-green', dot: 'bg-s-green' },
  flagged: { label: 'Flagged', tone: 'tint-yellow', dot: 'bg-s-yellow' },
  suspended: { label: 'Suspended', tone: 'tint-purple', dot: 'bg-s-purple' },
  blacklisted: { label: 'Blacklisted', tone: 'tint-red', dot: 'bg-s-red' },
}

export const REQUIREMENT_CHIP_TONE: Record<RequestStatus, ChipTone> = {
  pending: 'orange',
  needs_resubmission: 'orange',
  submitted: 'tint-purple',
  fulfilled: 'green',
  rejected: 'red',
}

/**
 * The applicant's status guide: the journey, in order, and what each step means.
 *
 * ── Why this is separate from NEXT_ACTION ─────────────────────────────────
 *
 * NEXT_ACTION speaks to somebody who is AT a status — "See the reason below",
 * "Review the remarks, then resubmit" — and points at things on the screen
 * around it. A guide is read by somebody who is at one status and reading about
 * nine others, so a line that says "below" is pointing at nothing. These are
 * descriptions rather than instructions, and short enough to scan ten of.
 *
 * `Record<ApplicationStatus, string>` on purpose: TypeScript refuses the file
 * if a status is added to the union without a line here, so the guide cannot
 * quietly fall a step behind the state machine.
 */
export const STATUS_GUIDE: Record<ApplicationStatus, string> = {
  draft: 'Not submitted yet. Submit when you are ready.',
  for_approval: 'BPLO is reading your form. Nothing to do yet.',
  pending_payment: 'Your fees are ready. Pay to carry on.',
  awaiting_other_permits:
    'Apply for your other permits. Each is released on its own, and the last one issues your Mayor’s Permit.',
  /*
   * No longer on a new application's path — see STATUS_FLOW. The line has to
   * describe the cases that still reach it without naming the machinery, since
   * a guide is read by people who do not know what a processing category is.
   */
  for_final_approval: 'BPLO makes a last check before your Mayor’s Permit is issued.',
  approved: 'Your Mayor’s Permit is issued — download it from your profile.',
  /*
   * Never reached by an application, and so not drawn in the guide — see
   * GUIDE_OMITTED. Kept because this table is exhaustive over the union, and
   * because a PERMIT genuinely wears this status.
   */
  issued: 'Your permit is ready. Download it from your profile.',
  returned: 'BPLO needs a correction. Read their note, fix it, submit again.',
  rejected: 'Turned down. The reason is on the application itself.',
  cancelled: 'You stopped this application.',
}

/**
 * The ordinary path of a NEW application, start to finish.
 *
 * ── Five steps since 18 September 2026, not six ────────────────────────────
 *
 * `for_final_approval` was the fifth, and the client asked what it was for:
 * *"what is the purpose of the BPLO checking if all other permits are legit,
 * when those permits are APPLIED DIRECTLY in BizTrack itself?"* Nothing, on
 * this path. Every clearance is applied for in BizTrack, approved by its own
 * office in BizTrack and inspected against a pivot row in BizTrack, so BPLO
 * re-reading them was the system checking its own records against itself —
 * while the RA 11032 clock ran. `refreshReadiness` now issues the business
 * permit the moment the last clearance is approved.
 *
 * This is the SECOND step removed for the same reason. `issued` was the
 * seventh until the client asked what separated it from Approved; see
 * GUIDE_OMITTED, which is worth reading next to this.
 *
 * The status still exists and is still reached — a renewal stops there so BPLO
 * can read the certificate copies the applicant uploaded, which is real
 * evidence rather than a re-reading of our own records.
 *
 * Dropping it from this array first moved it under "If something interrupts
 * it", because the detours were derived as "not in the flow" and there was only
 * one flow. The client caught that the same day — *"I thought we already
 * removed the For Final Approval?"* — and it was a fair complaint: it is not an
 * interruption, it is a renewal's fourth step. `FLOW_BY_TYPE` and
 * `OMITTED_BY_TYPE` below are the answer, so this array is now the NEW path
 * specifically rather than "the" path.
 *
 * Ends at `approved` rather than `issued` for the reason GUIDE_OMITTED gives:
 * on this path the permit is minted in the same transaction, so the two are one
 * instant and drawing them as consecutive steps promises a wait that does not
 * exist.
 */
export const STATUS_FLOW: ApplicationStatus[] = [
  'draft',
  'for_approval',
  'pending_payment',
  'awaiting_other_permits',
  'approved',
]

/**
 * Which guide the applicant is reading. Not `ApplicationType` — see below.
 */
export type GuideFlow = 'new' | 'renewal' | 'amendment'

/**
 * The three paths, because they are genuinely three.
 *
 * Client, 19 September 2026: *"I want you to add here a filtering or some sort
 * for New Permit, Renewal, and Amendment, because all have different
 * processes."* They do, and one guide drawing one rail was telling two thirds of
 * its readers about somebody else's filing.
 *
 * `amendment` has no rail here on purpose. It follows the new path today —
 * `attachRequiredPermitTypes` leaves it there deliberately — but the client has
 * that process still open (*"We will go back with this when we deal with the
 * amendment application process"*), and drawing the new-application rail under
 * an Amendment pill would assert a decision nobody has taken. The panel says so
 * instead of guessing; see AMENDMENT_PENDING_NOTE.
 */
const FLOW_BY_TYPE: Record<'new' | 'renewal' | 'amendment', ApplicationStatus[]> = {
  /*
   * ── Two steps, since 19 September 2026 ─────────────────────────────────
   *
   * An amendment carries the business permit alone and defers its fee to the
   * January renewal, so there is no Pending Payment and nothing to gather. With
   * no money to wait for there is nothing to put between BPLO reading the
   * affidavit and BPLO completing it, so its two acts are one: approving
   * applies the changes, reprints the permit and closes the filing.
   *
   * The pill said the process was still being settled until the API was built.
   * It is built for three of the LGU's seven boxes plus half of a fourth
   * (see App\Support\AmendableFields), and the SHAPE of the flow is settled
   * for all of them — which is what this rail describes.
   */
  amendment: ['draft', 'for_approval', 'approved'],
  new: STATUS_FLOW,
  /*
   * A renewal keeps For Final Approval, and it is a normal step rather than an
   * interruption: BPLO reads the certificate copies the applicant uploaded,
   * which is real evidence of outside provenance.
   *
   * This is the BUSINESS PERMIT renewal — the January one nearly every
   * applicant makes. Renewing one of the other permits alone is a different and
   * shorter process, and it gets its own rail rather than a footnote on this
   * one: see OTHER_PERMIT_FLOW.
   */
  renewal: ['draft', 'for_approval', 'pending_payment', 'for_final_approval', 'approved'],
}

/**
 * Statuses a given guide does not mention at all, with the reason.
 *
 * Needed because STATUS_DETOURS is derived as "everything not in the flow", and
 * that derivation is what keeps the guide exhaustive. Without this a status
 * missing from one type's rail would silently reappear under "If something
 * interrupts it" — which is exactly what happened to For Final Approval when it
 * left the new path, and what the client noticed: *"I thought we already
 * removed the For Final Approval?"*
 */
const OMITTED_BY_TYPE: Record<'new' | 'renewal' | 'amendment', ApplicationStatus[]> = {
  /*
   * An amendment reaches neither: it never gathers other permits and never
   * waits for a second BPLO act. Listed rather than left to fall into the
   * detours, where they would read as things that might happen to it.
   */
  amendment: ['awaiting_other_permits', 'for_final_approval'],
  /*
   * A NEW filing can still reach For Final Approval, but only when it becomes
   * ready with no confirmed RA 11032 processing category and falls back to BPLO
   * (`WorkflowService::refreshReadiness`). That is an internal data problem the
   * applicant cannot act on and did not cause, so naming it in their guide
   * explains nothing and worries everyone. The badge still has a label if they
   * ever see it — this only keeps it out of the list of things to expect.
   */
  new: ['for_final_approval'],
  renewal: [],
}

/** The rail for one kind of filing, or null where there is nothing to draw yet. */
export function statusFlowFor(flow: GuideFlow): ApplicationStatus[] {
  return FLOW_BY_TYPE[flow]
}

/**
 * Everything that is not this type's ordinary path — derived, never listed.
 *
 * Same argument as the single-flow version it replaces: written as "the rest" so
 * the two together are exhaustive by construction. A status added to the union
 * appears in the guide whether or not anybody remembered it — in the rail if it
 * was put there, here if it was not, and nowhere only if it is named in
 * GUIDE_OMITTED or OMITTED_BY_TYPE with a reason beside it.
 */
export function statusDetoursFor(flow: GuideFlow): ApplicationStatus[] {
  const rail = FLOW_BY_TYPE[flow]
  const omitted = OMITTED_BY_TYPE[flow]

  return (Object.keys(STATUS_GUIDE) as ApplicationStatus[]).filter(
    (status) =>
      !rail.includes(status) && !GUIDE_OMITTED.includes(status) && !omitted.includes(status),
  )
}

/**
 * What the Renewal rail is about, said before the reader starts counting steps.
 *
 * Client, 19 September 2026: *"How do you think can we clearly define in the
 * renewal process that other permits may be renewed on its own (unlike in new
 * application where other permits are really part of the application
 * process)?"*
 *
 * The honest diagnosis was that the guide said it by ABSENCE — the renewal rail
 * simply has no Awaiting Other Permits step — and nobody notices a step that is
 * not there. Worse, both rails showed five numbered steps, so the reader had to
 * spot that step 4 had changed in KIND rather than in wording.
 */
export const RENEWAL_LEAD =
  'Your business permit renewal. The other permits are separate filings — renewing this does not renew them, and they do not hold it up.'

/**
 * The other-permit renewal, as its own process.
 *
 * Three nodes, and its shortness is the message: no payment stage, no BPLO, no
 * waiting on anybody else. Drawn rather than described because a separate
 * process shown as a footnote reads as a caveat on the rail above it, which is
 * precisely the confusion this replaces.
 */
export const OTHER_PERMIT_FLOW: ApplicationStatus[] = ['draft', 'for_approval', 'approved']

/**
 * The other-permit rail's own words, overriding STATUS_GUIDE.
 *
 * Needed because the shared line for `for_approval` is "BPLO is reading your
 * form", and on this path that is FALSE — the issuing office reads it and BPLO
 * never sees the filing at all (docs/renewal-2026-09-17.md §6, which flags this
 * as "the part of this most likely to surprise a reader"). Printing the shared
 * line here would teach the applicant to wait for the wrong office.
 */
export const OTHER_PERMIT_GUIDE: Record<'draft' | 'for_approval' | 'approved', string> = {
  draft: 'Pick the permit and fill in that office’s form.',
  for_approval:
    'That permit’s own office reads it — CHO for Sanitary, BFP for Fire Safety. Never BPLO.',
  approved:
    'Issued by that office, ready to download. Nothing to pay now — the fee joins your January bill.',
}

/** Any time of year, and the case where no renewal is needed at all. */
export const OTHER_PERMIT_NOTE =
  'Any time of year, not just January. Still valid? Do nothing — BizTrack uses the copy already on file.'

/** What an amendment can and cannot change. */
export const AMENDMENT_NOTE =
  'Only your business permit’s own details — floor area, employees, delivery vehicles, trade name and street address. Nothing to pay now: the fee joins your next January renewal. A change of ownership, line of business, or a move to another barangay is still done at the BPLO window.'

/**
 * In the union, deliberately not in the guide.
 *
 * `issued` was the seventh step, and the client asked what separated it from
 * Approved. Nothing does — for an APPLICATION. `ApplicationStatus` has no
 * Issued case at all, and WorkflowService's final approval marks the clearance
 * approved, calls `issuePermitFor` and stamps the filing inside ONE
 * `DB::transaction`. The two are the same instant, so drawing them as
 * consecutive steps promised a wait that does not exist.
 *
 * It stays in the union because a PERMIT wears it, and the guide's own
 * exhaustiveness is what makes that safe: a status is either in the flow, in
 * the detours, or named here with a reason. There is no fourth outcome in
 * which one goes missing quietly.
 */
export const GUIDE_OMITTED: ApplicationStatus[] = ['issued']

/*
 * `STATUS_DETOURS` was here. It is `statusDetoursFor(flow)` now, because the
 * detours depend on which path the reader is on: For Final Approval is a STEP
 * for a renewal and not a detour, and for a new application it is neither.
 * The derivation — "the rest", so flow and detours are exhaustive together — is
 * unchanged and moved into that function.
 */

/**
 * What one of the OTHER permits goes through, inside `awaiting_other_permits`.
 *
 * The flow above is the APPLICATION's, and at that step it waits while five
 * clearances run their own course — each reviewed by its own office, each
 * inspected, each approved and released on its own. An applicant reading only
 * the main flow sees a stage they sit in with no idea what happens underneath
 * it, and it is the stage that takes the longest.
 *
 * These are ClearanceStatus values rather than ApplicationStatus: the same
 * words the per-permit chips on an expanded row already use, so the sub-flow
 * and the rows it describes cannot describe one thing two ways.
 *
 * `not_started` is left out — the absence of progress is not a step — and so
 * are the per-permit failures, which the main flow's own detours cover.
 */
export const CLEARANCE_FLOW: ClearanceStatus[] = ['for_approval', 'for_inspection', 'approved']
