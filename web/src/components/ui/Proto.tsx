import type { ReactNode } from 'react'
import { ChevronDownIcon } from '../icons'

/*
 * Shared prototype-fidelity primitives (docs/rehaul-spec.md).
 * Every rebuilt screen composes these so the app reads as ONE product.
 */

/** Blue/red-header modal with the split Cancel/Confirm footer (PDF p18/p35/p47/p82). */
export function ProtoModal({
  title,
  tone = 'blue',
  children,
  cancelLabel = 'Cancel',
  confirmLabel = 'Proceed',
  onCancel,
  onConfirm,
  confirmDisabled,
  wide,
}: {
  title: string
  tone?: 'blue' | 'red' | 'green'
  children: ReactNode
  cancelLabel?: string
  confirmLabel?: string
  onCancel: () => void
  onConfirm?: () => void
  confirmDisabled?: boolean
  wide?: boolean
}) {
  const headerBg = tone === 'red' ? 'bg-s-red' : tone === 'green' ? 'bg-s-green' : 'bg-royal'
  const cancelBg = tone === 'red' ? 'bg-modal-cancel-red' : 'bg-modal-cancel'
  const confirmBg = tone === 'red' ? 'bg-modal-confirm-red' : 'bg-modal-confirm'
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="dialog"
      aria-modal="true"
      aria-label={title}
    >
      <div className={`w-full ${wide ? 'max-w-2xl' : 'max-w-lg'} overflow-hidden rounded-md bg-white shadow-overlay`}>
        <div className={`${headerBg} px-5 py-3 text-base font-bold tracking-wide text-white`}>{title}</div>
        <div className="max-h-[70vh] overflow-y-auto px-7 py-7 text-ink">{children}</div>
        <div className="grid grid-cols-2">
          <button
            type="button"
            onClick={onCancel}
            className={`${cancelBg} py-3.5 text-sm font-semibold text-ink underline underline-offset-2 hover:brightness-95`}
          >
            {cancelLabel}
          </button>
          {onConfirm ? (
            <button
              type="button"
              onClick={onConfirm}
              disabled={confirmDisabled}
              className={`${confirmBg} py-3.5 text-sm font-semibold text-ink underline underline-offset-2 hover:brightness-95 disabled:opacity-60`}
            >
              {confirmLabel}
            </button>
          ) : (
            <span className={`${confirmBg} opacity-40`} aria-hidden="true" />
          )}
        </div>
      </div>
    </div>
  )
}

/**
 * Account restricted modal (p006) — red header, informational body, bold
 * Reference ID, single dismiss block over a red-tint footer. Purely
 * informational: 422s already block filing. Variant flips the copy for
 * suspended vs. blacklisted accounts.
 */
export function AccountRestrictedModal({
  variant,
  referenceId,
  onClose,
}: {
  variant: 'blacklisted' | 'suspended'
  referenceId?: string | null
  onClose: () => void
}) {
  const title = variant === 'suspended' ? 'Account Suspended' : 'Account Blacklisted'
  const word = variant === 'suspended' ? 'suspended' : 'blacklisted'
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="alertdialog"
      aria-modal="true"
      aria-label={title}
    >
      <div className="w-full max-w-lg overflow-hidden rounded-md bg-white shadow-overlay">
        <div className="bg-s-red px-6 py-3.5 text-lg font-bold tracking-wide text-white">{title}</div>
        <div className="space-y-4 px-7 py-7 text-ink">
          <p className="text-base leading-relaxed">
            This account has been {word} and access to the platform has been restricted.
          </p>
          <p className="text-base leading-relaxed">
            If you believe this action was taken in error, please contact support for further review.
          </p>
          {referenceId && (
            <p className="text-base">
              <span className="font-bold">Reference ID:</span> {referenceId}
            </p>
          )}
        </div>
        <button
          type="button"
          onClick={onClose}
          className="w-full bg-modal-cancel-red py-3.5 text-sm font-semibold text-ink underline underline-offset-2 hover:brightness-95"
        >
          Understood
        </button>
      </div>
    </div>
  )
}

/** Round filter pills row (p20/p48/p61): active = white w/ royal outline text, inactive = royal. */
export function FilterPills<T extends string>({
  options,
  value,
  onChange,
}: {
  options: { value: T; label: string }[]
  value: T
  onChange: (v: T) => void
}) {
  return (
    <div className="flex flex-wrap gap-2.5">
      {options.map((o) => {
        const active = o.value === value
        return (
          <button
            key={o.value}
            type="button"
            aria-pressed={active}
            onClick={() => onChange(o.value)}
            className={`rounded-full px-5 py-1.5 text-sm font-semibold transition-colors ${
              active
                ? 'border-2 border-royal bg-white text-royal'
                : 'border-2 border-royal bg-royal text-white hover:bg-royal-hover'
            }`}
          >
            {o.label}
          </button>
        )
      })}
    </div>
  )
}

const CHIP_TONES = {
  orange: 'bg-s-orange text-white',
  green: 'bg-s-green text-white',
  yellow: 'bg-s-yellow text-ink',
  red: 'bg-s-red text-white',
  gray: 'bg-line text-ink-secondary',
  // soft tints for table chips (super-admin p93/p99)
  'tint-green': 'bg-s-green-tint text-s-green',
  'tint-yellow': 'bg-s-yellow-tint text-amber-800',
  'tint-red': 'bg-s-red-tint text-s-red',
  'tint-purple': 'bg-s-purple-tint text-s-purple',
  'tint-gray': 'bg-canvas text-ink-muted',
} as const
export type ChipTone = keyof typeof CHIP_TONES

/** Solid status block/chip (Pay Online, Paid, For Approval, Rejected…). */
export function StatusChip({
  tone,
  children,
  className = '',
}: {
  tone: ChipTone
  children: ReactNode
  className?: string
}) {
  return (
    <span
      className={`inline-flex items-center justify-center rounded-md px-3 py-1 text-center text-xs font-semibold leading-tight ${CHIP_TONES[tone]} ${className}`}
    >
      {children}
    </span>
  )
}

/** Page heading with the thin underline rule (p11/p19/p21). */
export function PageTitle({ children, right }: { children: ReactNode; right?: ReactNode }) {
  return (
    <div className="mb-6 border-b-2 border-ink/50 pb-2">
      <div className="flex items-end justify-between gap-4">
        <h1 className="text-2xl font-bold text-ink">{children}</h1>
        {right}
      </div>
    </div>
  )
}

/** `Sort ⇅  Filter ▽` affordance (p14). Non-functional per prototype. */
export function SortFilter() {
  return (
    <span className="flex items-center gap-4 text-sm text-ink-secondary">
      <span className="inline-flex items-center gap-1">
        Sort <ChevronDownIcon size={14} className="rotate-180" />
        <ChevronDownIcon size={14} className="-ml-2.5" />
      </span>
      <span className="inline-flex items-center gap-1">
        Filter
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M4 5h16l-6.5 8v5L10 20v-7L4 5Z" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
        </svg>
      </span>
    </span>
  )
}

/** White shadow card — the prototype's base surface. */
export function ProtoCard({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`rounded-2xl bg-white shadow-card ${className}`}>{children}</div>
}

/** Status card with the colored top bar (p50/p52/p54/p57/p58). */
export function StatusCard({
  tone,
  children,
}: {
  tone: 'orange' | 'green' | 'yellow' | 'red'
  children: ReactNode
}) {
  const bar =
    tone === 'green' ? 'bg-s-green' : tone === 'yellow' ? 'bg-s-yellow' : tone === 'red' ? 'bg-s-red' : 'bg-s-orange'
  return (
    <div className="overflow-hidden rounded-md bg-white shadow-card">
      <div className={`h-2.5 ${bar}`} />
      <div className="flex flex-col items-center gap-3 px-8 py-10">{children}</div>
    </div>
  )
}

/** Filled light-blue input classes — apply to input/select/textarea. */
export const inputCls =
  'w-full rounded-lg border border-input-border bg-input px-3.5 py-2.5 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal'

/** Field label above an input. */
export function FieldLabel({ children, required }: { children: ReactNode; required?: boolean }) {
  return (
    <span className="mb-1.5 block text-[13px] font-semibold text-ink">
      {children}
      {required && <span className="text-s-red"> *</span>}
    </span>
  )
}

/** Royal pill button (primary). */
export function PillButton({
  children,
  onClick,
  type = 'button',
  disabled,
  className = '',
}: {
  children: ReactNode
  onClick?: () => void
  type?: 'button' | 'submit'
  disabled?: boolean
  className?: string
}) {
  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      className={`inline-flex items-center justify-center rounded-full bg-royal px-7 py-2.5 text-sm font-semibold text-white shadow-card transition-colors hover:bg-royal-hover disabled:opacity-60 ${className}`}
    >
      {children}
    </button>
  )
}
