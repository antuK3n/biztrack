import type { ComponentType, ReactNode, SVGProps } from 'react'
import { Link } from 'react-router-dom'
import { Alert } from './Alert'
import { Button } from './Button'
import { toApiError } from '../../lib/api'

type IconType = ComponentType<SVGProps<SVGSVGElement> & { size?: number }>

/* ── Surface / card ───────────────────────────────────────────────────── */

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`rounded-lg border border-line bg-surface ${className}`}>{children}</div>
}

export function CardSection({
  title,
  action,
  children,
  className = '',
}: {
  title?: ReactNode
  action?: ReactNode
  children: ReactNode
  className?: string
}) {
  return (
    <section className={`rounded-lg border border-line bg-surface ${className}`}>
      {(title || action) && (
        <header className="flex items-center justify-between gap-3 border-b border-line px-4 py-3 sm:px-5">
          {typeof title === 'string' ? <h2 className="text-base font-semibold text-ink">{title}</h2> : title}
          {action}
        </header>
      )}
      {children}
    </section>
  )
}

/* ── Page header ──────────────────────────────────────────────────────── */

export function PageHeader({
  title,
  description,
  action,
}: {
  title: string
  description?: ReactNode
  action?: ReactNode
}) {
  return (
    <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{title}</h1>
        {description && <p className="mt-1 text-sm text-ink-secondary">{description}</p>}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  )
}

/* ── Skeletons (preferred over spinners) ──────────────────────────────── */

export function Skeleton({ className = '' }: { className?: string }) {
  return <div className={`animate-pulse rounded bg-shell-deep ${className}`} aria-hidden="true" />
}

export function SkeletonList({ rows = 4 }: { rows?: number }) {
  return (
    <div className="rounded-lg border border-line bg-surface" role="status" aria-label="Loading">
      <ul className="divide-y divide-line">
        {Array.from({ length: rows }).map((_, i) => (
          <li key={i} className="flex items-center justify-between gap-4 px-4 py-4">
            <div className="min-w-0 flex-1 space-y-2">
              <Skeleton className="h-4 w-40" />
              <Skeleton className="h-3 w-56" />
            </div>
            <Skeleton className="h-6 w-24 rounded-full" />
          </li>
        ))}
      </ul>
    </div>
  )
}

export function SkeletonCards({ count = 3 }: { count?: number }) {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" role="status" aria-label="Loading">
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="rounded-lg border border-line bg-surface p-5">
          <Skeleton className="h-3 w-20" />
          <Skeleton className="mt-3 h-8 w-24" />
        </div>
      ))}
    </div>
  )
}

/* ── Empty state (teaches the interface) ──────────────────────────────── */

export function EmptyState({
  icon: Icon,
  title,
  description,
  action,
}: {
  icon?: IconType
  title: string
  description?: ReactNode
  action?: ReactNode
}) {
  return (
    <div className="rounded-lg border border-dashed border-line-strong bg-surface px-6 py-12 text-center">
      {Icon && (
        <span className="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-shell text-ink-muted">
          <Icon size={22} />
        </span>
      )}
      <p className="text-base font-semibold text-ink">{title}</p>
      {description && <p className="mx-auto mt-1.5 max-w-md text-sm text-ink-secondary">{description}</p>}
      {action && <div className="mt-5 flex justify-center">{action}</div>}
    </div>
  )
}

/* ── Error state ──────────────────────────────────────────────────────── */

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const message = toApiError(error).message
  return (
    <div className="space-y-4">
      <Alert variant="error" title="We couldn't load this">
        {message}
      </Alert>
      {onRetry && (
        <Button variant="secondary" onClick={onRetry}>
          Try again
        </Button>
      )}
    </div>
  )
}

/* ── Link-styled button (for "Apply" CTAs) ────────────────────────────── */

export function LinkButton({
  to,
  children,
  variant = 'primary',
  className = '',
}: {
  to: string
  children: ReactNode
  variant?: 'primary' | 'secondary' | 'ghost'
  className?: string
}) {
  const base =
    'inline-flex h-11 items-center justify-center gap-2 rounded-full px-5 text-base font-semibold transition-colors duration-150 ease-out'
  const variants = {
    primary: 'bg-blue-600 text-white hover:bg-blue-700 active:bg-blue-800',
    secondary: 'border border-line-strong bg-surface text-ink hover:bg-shell',
    ghost: 'text-blue-600 hover:bg-blue-50',
  }
  return (
    <Link to={to} className={`${base} ${variants[variant]} ${className}`}>
      {children}
    </Link>
  )
}

/* ── Definition row (labelled value) ──────────────────────────────────── */

export function DetailRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 py-2.5 sm:flex-row sm:justify-between sm:gap-4">
      <dt className="text-sm text-ink-secondary">{label}</dt>
      <dd className="text-sm font-medium text-ink sm:text-right">{children}</dd>
    </div>
  )
}
