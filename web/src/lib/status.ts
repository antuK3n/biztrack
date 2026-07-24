import type { ComponentType, SVGProps } from 'react'
import {
  CheckCircleIcon,
  ClockIcon,
  DotIcon,
  DraftsIcon,
  InfoCircleIcon,
  ShieldCheckIcon,
  UploadIcon,
  XCircleIcon,
} from '../components/icons'
import type { ApplicationStatus } from './types'

type IconType = ComponentType<SVGProps<SVGSVGElement> & { size?: number }>

/**
 * Tones map to token pairs. "Never color alone": every tone is always rendered
 * with an icon + text label by <StatusBadge>. Red (danger) is errors/denials only.
 */
export type StatusTone = 'neutral' | 'progress' | 'attention' | 'success' | 'danger'

export const TONE_CLASSES: Record<StatusTone, string> = {
  neutral: 'bg-shell-deep text-ink-secondary border-line',
  progress: 'bg-blue-50 text-blue-800 border-blue-200',
  attention: 'bg-amber-50 text-amber-800 border-amber-200',
  success: 'bg-green-50 text-green-700 border-green-200',
  danger: 'bg-red-50 text-red-700 border-red-200',
}

export const TONE_ICONS: Record<StatusTone, IconType> = {
  neutral: DotIcon,
  progress: ClockIcon,
  attention: InfoCircleIcon,
  success: CheckCircleIcon,
  danger: XCircleIcon,
}

interface StatusMeta {
  label: string
  tone: StatusTone
  icon: IconType
}

const APPLICATION_STATUS: Record<ApplicationStatus, StatusMeta> = {
  draft: { label: 'Draft', tone: 'neutral', icon: DraftsIcon },
  submitted: { label: 'Submitted', tone: 'progress', icon: UploadIcon },
  under_review: { label: 'Under review', tone: 'progress', icon: ClockIcon },
  returned: { label: 'Returned to you', tone: 'attention', icon: InfoCircleIcon },
  pending_payment: { label: 'For payment', tone: 'attention', icon: InfoCircleIcon },
  for_inspection: { label: 'For inspection', tone: 'progress', icon: ClockIcon },
  approved: { label: 'Approved', tone: 'success', icon: CheckCircleIcon },
  issued: { label: 'Permit issued', tone: 'success', icon: ShieldCheckIcon },
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
