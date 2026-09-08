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
import type { ApplicationStatus, ClearanceStatus } from './types'

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
  not_started: { label: 'Not Started', tone: 'neutral', icon: DotIcon },
  for_approval: { label: 'For Approval', tone: 'attention', icon: ClockIcon },
  for_inspection: { label: 'For Inspection', tone: 'scheduled', icon: SearchIcon },
  approved: { label: 'Approved', tone: 'success', icon: CheckCircleIcon },
  rejected: { label: 'Rejected', tone: 'danger', icon: XCircleIcon },
  returned: { label: 'Returned', tone: 'attention', icon: InfoCircleIcon },
  available: { label: 'Available', tone: 'neutral', icon: DotIcon },
}

export function clearanceStatusMeta(status: string): StatusMeta {
  return (
    CLEARANCE_STATUS[status as ClearanceStatus] ?? {
      label: status,
      tone: 'neutral',
      icon: DotIcon,
    }
  )
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
