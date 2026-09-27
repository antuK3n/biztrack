import { useId, useState } from 'react'
import type { FormEvent } from 'react'
import { toApiError } from '../lib/api'
import { isSixDigits, useCooldown } from '../lib/emailCode'
import { emailCodes } from '../lib/resources'
import type { User } from '../lib/types'
import { useAuth } from '../stores/auth'
import { Alert } from './ui/Alert'
import { FieldLabel, PillButton, inputCls } from './ui/Proto'

/*
 * Six-digit e-mail codes, on screen [checklist 2026-09-27, Register 1 and
 * Login 5].
 *
 * Only ever reached while the API has a real mailer. With mail off the API
 * never asks for a code, `email_verification_required` is false for everyone,
 * and nothing in this file renders.
 */

/**
 * The code box. Numeric keypad on a phone, and `one-time-code` so iOS and
 * Android offer the code straight out of the message. Spaces are allowed in
 * what is typed because that is how people copy a code; the API drops them.
 */
export function CodeField({
  id,
  label,
  value,
  onChange,
  error,
}: {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  error?: string
}) {
  const errorId = `${id}-error`
  return (
    <div>
      <label htmlFor={id} className="block">
        <FieldLabel required>{label}</FieldLabel>
      </label>
      <input
        id={id}
        name="code"
        type="text"
        inputMode="numeric"
        autoComplete="one-time-code"
        maxLength={9}
        placeholder="6 digits"
        value={value}
        onChange={(e) => onChange(e.target.value.replace(/[^\d\s-]/g, ''))}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        className={`${inputCls} font-mono text-lg tracking-[0.3em]`}
      />
      {error && (
        <p id={errorId} className="mt-1.5 text-sm font-medium text-s-red">
          {error}
        </p>
      )}
    </div>
  )
}

/**
 * "Confirm your email address", for an owner who has not yet [Register 1].
 *
 * Shown where it is needed and nowhere else: on Profile beside the address,
 * and in the application wizard when Submit is refused for it. Not a banner
 * over every page — tester item 99 had that removed, and the reason still
 * holds (see AppShell).
 */
export function ConfirmEmailCard({ user, onConfirmed }: { user: User; onConfirmed?: (user: User) => void }) {
  const setUser = useAuth((s) => s.setUser)
  const fieldId = useId()
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | undefined>()
  const [note, setNote] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [sending, setSending] = useState(false)
  const [cooldown, setCooldown] = useCooldown(0)

  async function confirm(event: FormEvent) {
    event.preventDefault()
    if (busy) return
    if (!isSixDigits(code)) {
      setError('Enter the 6 digits from the e-mail.')
      return
    }
    setBusy(true)
    setError(undefined)
    try {
      const result = await emailCodes.confirmEmail(code)
      setUser(result.user)
      onConfirmed?.(result.user)
    } catch (err) {
      const e = toApiError(err)
      setError(e.errors.code?.[0] ?? e.message)
    } finally {
      setBusy(false)
    }
  }

  async function resend() {
    if (sending || cooldown > 0) return
    setSending(true)
    setNote(null)
    setError(undefined)
    try {
      setNote(await emailCodes.resendConfirm())
      setCode('')
      setCooldown(60)
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setSending(false)
    }
  }

  return (
    <section
      aria-labelledby={`${fieldId}-title`}
      className="rounded-xl border border-line bg-white px-5 py-5 shadow-card"
    >
      <h2 id={`${fieldId}-title`} className="text-base font-bold text-ink">
        Confirm your email address
      </h2>
      <p className="mt-1 text-sm text-ink-secondary">
        Enter the 6-digit code we sent to <span className="font-semibold break-all">{user.email}</span>. You
        need a confirmed address before you can file an application.
      </p>
      {note && (
        <div className="mt-3">
          <Alert variant="success">{note}</Alert>
        </div>
      )}
      <form onSubmit={confirm} noValidate className="mt-4 flex flex-col gap-4 sm:max-w-sm">
        <CodeField id={fieldId} label="Confirmation code" value={code} onChange={setCode} error={error} />
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
          <PillButton type="submit" aria-disabled={busy}>
            {busy ? 'Confirming…' : 'Confirm'}
          </PillButton>
          <button
            type="button"
            onClick={resend}
            aria-disabled={sending || cooldown > 0}
            className="text-sm font-semibold text-royal underline-offset-2 hover:underline aria-disabled:cursor-default aria-disabled:text-ink-muted aria-disabled:no-underline"
          >
            {sending ? 'Sending…' : cooldown > 0 ? `Send a new code in ${cooldown}s` : 'Send a new code'}
          </button>
        </div>
      </form>
    </section>
  )
}
