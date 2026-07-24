import type { ReactNode } from 'react'
import { AlertCircleIcon, CheckCircleIcon, ClockIcon, InfoCircleIcon } from '../icons'

const variants = {
  error: {
    container: 'border-red-200 bg-red-50 text-red-700',
    icon: AlertCircleIcon,
    role: 'alert' as const,
  },
  success: {
    container: 'border-green-200 bg-green-50 text-green-700',
    icon: CheckCircleIcon,
    role: 'status' as const,
  },
  info: {
    container: 'border-blue-200 bg-blue-50 text-blue-800',
    icon: InfoCircleIcon,
    role: 'status' as const,
  },
  warning: {
    container: 'border-amber-200 bg-amber-50 text-amber-800',
    icon: ClockIcon,
    role: 'status' as const,
  },
}

interface AlertProps {
  variant: keyof typeof variants
  title?: string
  children: ReactNode
}

export function Alert({ variant, title, children }: AlertProps) {
  const v = variants[variant]
  const IconComponent = v.icon
  return (
    <div role={v.role} className={`flex gap-2.5 rounded-md border px-3.5 py-3 text-sm ${v.container}`}>
      <IconComponent size={20} className="mt-px shrink-0" />
      <div className="min-w-0">
        {title && <p className="font-semibold">{title}</p>}
        <div className={title ? 'mt-0.5' : ''}>{children}</div>
      </div>
    </div>
  )
}
