import { useState } from 'react'
import { EyeIcon, EyeOffIcon } from '../icons'
import { inputCls } from './Proto'

/**
 * Filled password input with the prototype's eye toggle (PDF p13).
 *
 * The toggle is ours, always rendered, and never keyed to focus. Browsers ship
 * their own reveal control (Edge's `::-ms-reveal`) that only appears while the
 * field is focused and vanishes on blur, which reads as the button
 * disappearing; we hide it so there is exactly one, permanent affordance.
 */
export function PasswordInput({
  id,
  name,
  placeholder,
  autoComplete = 'new-password',
  value,
  onChange,
  onBlur,
  invalid,
  describedBy,
  required,
  iconSize = 20,
  className = '',
}: {
  id?: string
  name?: string
  placeholder?: string
  autoComplete?: string
  value: string
  onChange: (value: string) => void
  onBlur?: () => void
  invalid?: boolean
  describedBy?: string
  /*
   * Announces the requirement that the label's asterisk shows. Optional so the
   * auth screens, which mark nothing, are unchanged; aria-required rather than
   * the `required` attribute because these inputs sit in modals and forms that
   * save through a button, where native validation would never fire.
   */
  required?: boolean
  /** 20 on the auth screens, 18 inside the settings modals. */
  iconSize?: number
  className?: string
}) {
  const [visible, setVisible] = useState(false)
  return (
    <div className="relative">
      <input
        id={id}
        name={name}
        type={visible ? 'text' : 'password'}
        autoComplete={autoComplete}
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onBlur={onBlur}
        aria-invalid={invalid ? true : undefined}
        aria-required={required ? true : undefined}
        aria-describedby={describedBy}
        className={`${inputCls} pr-11 [&::-ms-reveal]:hidden ${className}`}
      />
      <button
        type="button"
        onClick={() => setVisible((v) => !v)}
        aria-label={visible ? 'Hide password' : 'Show password'}
        aria-pressed={visible}
        className="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-ink-secondary hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
      >
        {visible ? <EyeOffIcon size={iconSize} /> : <EyeIcon size={iconSize} />}
      </button>
    </div>
  )
}
