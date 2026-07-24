import { forwardRef, useId, useState } from 'react'
import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react'
import { AlertCircleIcon, ChevronDownIcon, EyeIcon, EyeOffIcon } from '../icons'

/*
 * Form control vocabulary. Labels are always visible (placeholder-as-label is
 * prohibited); hints render above the control; errors below, wired through
 * aria-describedby. Controls are 44px tall — the touch-target floor.
 */

/* Filled controls, per the prototype: periwinkle tint at rest, border only on focus/error. */
const controlBase =
  'h-11 w-full rounded-md border border-transparent bg-blue-100 px-3.5 text-base text-ink transition-colors duration-150 ' +
  'focus:outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-200 focus:bg-blue-50 ' +
  'disabled:bg-shell disabled:text-ink-muted disabled:cursor-not-allowed'

const controlBorder = (invalid: boolean) =>
  invalid ? 'border-red-600 bg-red-50 focus:border-red-600 focus:ring-red-200' : ''

interface FieldShellProps {
  id: string
  label: string
  optional?: boolean
  hint?: string
  hintId?: string
  error?: string
  errorId?: string
  children: ReactNode
}

function FieldShell({ id, label, optional, hint, hintId, error, errorId, children }: FieldShellProps) {
  return (
    <div>
      <label htmlFor={id} className="mb-1.5 block text-sm font-semibold text-ink">
        {label}
        {optional && <span className="ml-1 font-normal text-ink-muted">{' (optional)'}</span>}
      </label>
      {hint && (
        <p id={hintId} className="-mt-0.5 mb-1.5 text-sm text-ink-secondary">
          {hint}
        </p>
      )}
      {children}
      {error && (
        <p id={errorId} className="mt-1.5 flex items-start gap-1.5 text-sm font-medium text-red-600">
          <AlertCircleIcon size={16} className="mt-0.5 shrink-0" />
          {error}
        </p>
      )}
    </div>
  )
}

function useFieldIds(explicitId: string | undefined, hint?: string, error?: string) {
  const generated = useId()
  const id = explicitId ?? generated
  const hintId = hint ? `${id}-hint` : undefined
  const errorId = error ? `${id}-error` : undefined
  const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined
  return { id, hintId, errorId, describedBy }
}

export interface TextFieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  optional?: boolean
  hint?: string
  error?: string
}

export const TextField = forwardRef<HTMLInputElement, TextFieldProps>(function TextField(
  { label, optional, hint, error, id: explicitId, className = '', ...props },
  ref,
) {
  const { id, hintId, errorId, describedBy } = useFieldIds(explicitId, hint, error)
  return (
    <FieldShell id={id} label={label} optional={optional} hint={hint} hintId={hintId} error={error} errorId={errorId}>
      <input
        ref={ref}
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        className={`${controlBase} ${controlBorder(!!error)} ${className}`}
        {...props}
      />
    </FieldShell>
  )
})

export interface PasswordFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label: string
  hint?: string
  error?: string
}

export const PasswordField = forwardRef<HTMLInputElement, PasswordFieldProps>(function PasswordField(
  { label, hint, error, id: explicitId, className = '', ...props },
  ref,
) {
  const { id, hintId, errorId, describedBy } = useFieldIds(explicitId, hint, error)
  const [visible, setVisible] = useState(false)
  return (
    <FieldShell id={id} label={label} hint={hint} hintId={hintId} error={error} errorId={errorId}>
      <div className="relative">
        <input
          ref={ref}
          id={id}
          type={visible ? 'text' : 'password'}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          className={`${controlBase} ${controlBorder(!!error)} pr-11 ${className}`}
          {...props}
        />
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          aria-label={visible ? 'Hide password' : 'Show password'}
          aria-pressed={visible}
          className="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-md text-ink-secondary transition-colors duration-150 hover:text-ink"
        >
          {visible ? <EyeOffIcon size={20} /> : <EyeIcon size={20} />}
        </button>
      </div>
    </FieldShell>
  )
})

export interface SelectFieldProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label: string
  optional?: boolean
  hint?: string
  error?: string
  /** Shown as the disabled first option until a choice is made. */
  placeholder?: string
}

export const SelectField = forwardRef<HTMLSelectElement, SelectFieldProps>(function SelectField(
  { label, optional, hint, error, placeholder, id: explicitId, className = '', children, value, ...props },
  ref,
) {
  const { id, hintId, errorId, describedBy } = useFieldIds(explicitId, hint, error)
  const empty = value === ''
  return (
    <FieldShell id={id} label={label} optional={optional} hint={hint} hintId={hintId} error={error} errorId={errorId}>
      <div className="relative">
        <select
          ref={ref}
          id={id}
          value={value}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          className={`${controlBase} ${controlBorder(!!error)} appearance-none pr-10 ${empty ? 'text-ink-muted' : ''} ${className}`}
          {...props}
        >
          {placeholder !== undefined && (
            <option value="" disabled>
              {placeholder}
            </option>
          )}
          {children}
        </select>
        <ChevronDownIcon
          size={18}
          className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-ink-secondary"
        />
      </div>
    </FieldShell>
  )
})

export interface CheckboxFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'children'> {
  /** Rich label content — may contain links. */
  children: ReactNode
  error?: string
}

export const CheckboxField = forwardRef<HTMLInputElement, CheckboxFieldProps>(function CheckboxField(
  { children, error, id: explicitId, className = '', ...props },
  ref,
) {
  const { id, errorId, describedBy } = useFieldIds(explicitId, undefined, error)
  return (
    <div>
      <div className="flex gap-3">
        <span className="relative mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center">
          <input
            ref={ref}
            id={id}
            type="checkbox"
            aria-invalid={error ? true : undefined}
            aria-describedby={describedBy}
            className={`peer h-5 w-5 appearance-none rounded-sm border bg-surface transition-colors duration-150 checked:border-blue-600 checked:bg-blue-600 ${
              error ? 'border-red-600' : 'border-line-strong'
            } ${className}`}
            {...props}
          />
          <svg
            viewBox="0 0 24 24"
            className="pointer-events-none absolute h-3.5 w-3.5 text-white opacity-0 transition-opacity duration-150 peer-checked:opacity-100"
            fill="none"
            stroke="currentColor"
            strokeWidth={3.5}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
          >
            <path d="M4.5 12.5l5 5L19.5 7" />
          </svg>
        </span>
        <label htmlFor={id} className="text-sm text-ink">
          {children}
        </label>
      </div>
      {error && (
        <p id={errorId} className="mt-1.5 flex items-start gap-1.5 text-sm font-medium text-red-600">
          <AlertCircleIcon size={16} className="mt-0.5 shrink-0" />
          {error}
        </p>
      )}
    </div>
  )
})
