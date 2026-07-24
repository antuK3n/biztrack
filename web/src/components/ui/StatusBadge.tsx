import type { ComponentType, SVGProps } from 'react'
import { TONE_CLASSES, TONE_ICONS } from '../../lib/status'
import type { StatusTone } from '../../lib/status'

type IconType = ComponentType<SVGProps<SVGSVGElement> & { size?: number }>

interface StatusBadgeProps {
  tone: StatusTone
  label: string
  /** Overrides the tone's default icon. */
  icon?: IconType
  size?: 'sm' | 'md'
}

/**
 * "Never color alone": always renders an icon + text label alongside the tone
 * color. 1px border keeps it legible on tinted surfaces (WCAG 3:1 UI target).
 */
export function StatusBadge({ tone, label, icon, size = 'md' }: StatusBadgeProps) {
  const Icon = icon ?? TONE_ICONS[tone]
  const sizing = size === 'sm' ? 'px-2 py-0.5 text-xs gap-1' : 'px-2.5 py-1 text-sm gap-1.5'
  return (
    <span
      className={`inline-flex items-center rounded-full border font-semibold ${sizing} ${TONE_CLASSES[tone]}`}
    >
      <Icon size={size === 'sm' ? 13 : 15} className="shrink-0" aria-hidden="true" />
      {label}
    </span>
  )
}
