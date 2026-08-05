import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { AuthLayout } from '../../components/AuthLayout'
import { MailIcon } from '../../components/icons'
import { Alert } from '../../components/ui/Alert'
import { FieldLabel, PillButton, inputCls } from '../../components/ui/Proto'
import { api, toApiError } from '../../lib/api'
import { validateEmail } from '../../lib/validation'

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [error, setError] = useState<string | undefined>()
  const [formError, setFormError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [sent, setSent] = useState(false)

  async function send() {
    setLoading(true)
    setFormError(null)
    try {
      await api.post('/auth/forgot-password', { email: email.trim() })
      setSent(true)
    } catch (err) {
      setFormError(toApiError(err).message)
    } finally {
      setLoading(false)
    }
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    const next = validateEmail(email)
    setError(next)
    if (next) return
    await send()
  }

  if (sent) {
    return (
      <AuthLayout
        title="Check your email"
        variant="card"
        footer={
          <Link to="/login" className="font-bold text-royal hover:underline">
            Back to Sign In
          </Link>
        }
      >
        <div className="flex flex-col gap-5">
          <div className="flex items-start gap-3.5">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-input text-royal">
              <MailIcon size={22} />
            </span>
            <p className="text-sm leading-relaxed text-ink-secondary">
              If <span className="font-semibold text-ink">{email.trim()}</span> is registered with BizTrack, a
              password reset link is on its way. The link works for 60 minutes.
            </p>
          </div>
          <p className="text-sm text-ink-secondary">
            Nothing after a few minutes? Check your spam folder, or send it again.
          </p>
          <PillButton onClick={send} disabled={loading} className="w-full">
            {loading ? 'Sending…' : 'Send the link again'}
          </PillButton>
        </div>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout
      title="Reset your password"
      variant="card"
      lede="Enter the email you registered with and we'll send you a link to choose a new password."
      footer={
        <Link to="/login" className="font-bold text-royal hover:underline">
          Back to Sign In
        </Link>
      }
    >
      <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-5">
        {formError && (
          <Alert variant="error" title="Something went wrong">
            {formError}
          </Alert>
        )}
        <div>
          {/* Wrapped so the visible label actually names the input; it was a
              span, leaving the placeholder as the only accessible name. */}
          <label className="block">
          <FieldLabel>Email Address</FieldLabel>
          <input
            type="email"
            name="email"
            autoComplete="email"
            inputMode="email"
            value={email}
            onChange={(e) => {
              setEmail(e.target.value)
              if (error) setError(validateEmail(e.target.value))
            }}
            onBlur={() => setError(email ? validateEmail(email) : error)}
            aria-invalid={error ? true : undefined}
            aria-describedby={error ? 'forgot-email-error' : undefined}
            className={inputCls}
          />
          </label>
          {error && (
            <p id="forgot-email-error" className="mt-1.5 text-sm font-medium text-s-red">
              {error}
            </p>
          )}
        </div>
        <PillButton type="submit" disabled={loading} className="w-full">
          {loading ? 'Sending…' : 'Email me a reset link'}
        </PillButton>
      </form>
    </AuthLayout>
  )
}
