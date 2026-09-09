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
export type StatusTone = 'neutral' | 'progress' | 'attention' | 'scheduled' | 'success' | 'danger'

export const TONE_CLASSES: Record<StatusTone, string> = {
  neutral: 'bg-shell-deep text-ink-secondary border-line',
  progress: 'bg-blue-50 text-blue-800 border-blue-200',
  attention: 'bg-s-orange-tint text-s-orange-ink border-s-orange',
  scheduled: 'bg-s-yellow-tint text-s-yellow-ink border-s-yellow',
  success: 'bg-green-50 text-green-700 border-green-200',
  danger: 'bg-red-50 text-red-700 border-red-200',
}

export const TONE_ICONS: Record<StatusTone, IconType> = {
  neutral: DotIcon,
  progress: ClockIcon,
  attention: InfoCircleIcon,
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
  for_approval: { label: 'For Approval', tone: 'attention', icon: ClockIcon },
  returned: { label: 'Returned', tone: 'attention', icon: InfoCircleIcon },
  pending_payment: { label: 'Pending Payment', tone: 'attention', icon: PaymentsIcon },
  awaiting_other_permits: { label: 'Awaiting Other Permits', tone: 'progress', icon: UploadIcon },
  for_final_approval: { label: 'For Final Approval', tone: 'attention', icon: ClockIcon },
  approved: { label: 'Approved', tone: 'success', icon: CheckCircleIcon },
  issued: { label: 'Permit Issued', tone: 'success', icon: ShieldCheckIcon },
  rejected: { label: 'Rejected', tone: 'danger', icon: XCircleIcon },
  cancelled: { label: 'Cancelled', tone: 'neutral', icon: XCircleIcon },
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
  for_approval: { label: 'For Approval', tone: 'attention', icon: ClockIcon },
  for_inspection: { label: 'For Inspection', tone: 'scheduled', icon: SearchIcon },
  approved: { label: 'Approved', tone: 'success', icon: CheckCircleIcon },
  rejected: { label: 'Rejected', tone: 'danger', icon: XCircleIcon },
  returned: { label: 'Returned', tone: 'attention', icon: InfoCircleIcon },
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
 * `available` is a permit type with no pivot row (an optional one nobody asked
 * for); `not_started` is one attached but never acted on, which since
 * `submit()` began attaching all five required clearances is the state every
 * clearance opens in. Everything else means an office is holding it.
 *
 * Named because three screens were spelling it three different ways against a
 * vocabulary the server had stopped using — see `ClearanceState`.
 */
export function clearanceStarted(state: ClearanceState): boolean {
  return state !== 'available' && state !== 'not_started'
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
  for_approval: 'BPLO is reading your form. No action needed yet.',
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
export const REQUIREMENT_CHIP_TONE: Record<RequestStatus, ChipTone> = {
  pending: 'orange',
  needs_resubmission: 'orange',
  submitted: 'tint-purple',
  fulfilled: 'green',
  rejected: 'red',
}
