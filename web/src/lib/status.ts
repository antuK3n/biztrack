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
import type { ApplicationStatus, RequestStatus } from './types'

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
  submitted: { label: 'Submitted', tone: 'progress', icon: UploadIcon },
  under_review: { label: 'For Approval', tone: 'attention', icon: ClockIcon },
  returned: { label: 'Returned', tone: 'attention', icon: InfoCircleIcon },
  pending_payment: { label: 'Pending Payment', tone: 'attention', icon: PaymentsIcon },
  for_inspection: { label: 'For Inspection', tone: 'scheduled', icon: SearchIcon },
  approved: { label: 'Approved', tone: 'success', icon: CheckCircleIcon },
  issued: { label: 'Permit Issued', tone: 'success', icon: ShieldCheckIcon },
  rejected: { label: 'Rejected', tone: 'danger', icon: XCircleIcon },
  cancelled: { label: 'Cancelled', tone: 'neutral', icon: XCircleIcon },
}

export function applicationStatusMeta(status: string, fallbackLabel?: string): StatusMeta {
  return (
    APPLICATION_STATUS[status as ApplicationStatus] ?? {
      label: fallbackLabel ?? status,
      tone: 'neutral',
      icon: DotIcon,
    }
  )
}

/** The applicant-facing "what happens next" line for a status. */
export const NEXT_ACTION: Partial<Record<ApplicationStatus, string>> = {
  draft: 'Finish and submit your application when you are ready.',
  submitted: 'The office has received it and will begin review shortly.',
  under_review: 'Officers are reviewing your documents. No action needed yet.',
  returned: 'An office asked for changes. Review the remarks, then resubmit.',
  pending_payment: 'Your fees are assessed. Pay to continue processing.',
  for_inspection: 'An inspection is scheduled. Please prepare the premises.',
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
