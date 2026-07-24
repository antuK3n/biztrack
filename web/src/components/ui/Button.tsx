import { forwardRef } from 'react'
import type { ButtonHTMLAttributes } from 'react'
import { Spinner } from '../icons'

const base =
  'inline-flex h-11 items-center justify-center gap-2 rounded-full px-5 text-base font-semibold transition-colors duration-150 ease-out select-none disabled:cursor-not-allowed'

const variants = {
  primary:
    'bg-blue-600 text-white hover:bg-blue-700 active:bg-blue-800 disabled:bg-shell-deep disabled:text-ink-muted',
  secondary:
    'border border-line-strong bg-surface text-ink hover:bg-shell active:bg-shell-deep disabled:border-line disabled:bg-shell disabled:text-ink-muted',
  ghost: 'text-blue-600 hover:bg-blue-50 active:bg-blue-100 disabled:text-ink-muted',
}

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: keyof typeof variants
  /** Shows a spinner, disables the button, and announces busy state. Label stays visible. */
  loading?: boolean
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'primary', loading = false, disabled, className = '', children, type = 'button', ...props },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={`${base} ${variants[variant]} ${loading ? 'disabled:bg-blue-600 disabled:text-white' : ''} ${className}`}
      {...props}
    >
      {loading && <Spinner size={18} />}
      {children}
    </button>
  )
})
