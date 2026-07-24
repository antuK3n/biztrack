import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { AuthLayout } from '../../components/AuthLayout'
import { CheckCircleIcon, EyeIcon, EyeOffIcon } from '../../components/icons'
import { Alert } from '../../components/ui/Alert'
import { FieldLabel, PillButton, inputCls } from '../../components/ui/Proto'
import { api, toApiError } from '../../lib/api'
import { validatePassword, validatePasswordConfirmation } from '../../lib/validation'

/** Filled password input with the prototype's eye toggle. */
function PasswordInput({
  id,
  placeholder,
  autoComplete,
  value,
  onChange,
  onBlur,
  error,
  errorId,
}: {
  id: string
  placeholder: string
  autoComplete: string
  value: string
  onChange: (v: string) => void
  onBlur?: () => void
  error?: string
  errorId: string
}) {
  const [visible, setVisible] = useState(false)
  return (
    <div>
      <div className="relative">
        <input
          id={id}
          type={visible ? 'text' : 'password'}
          autoComplete={autoComplete}
          placeholder={placeholder}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          onBlur={onBlur}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? errorId : undefined}
          className={`${inputCls} pr-11`}
        />
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          aria-label={visible ? 'Hide password' : 'Show password'}
          aria-pressed={visible}
          className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-ink-secondary hover:text-ink"
        >
          {visible ? <EyeOffIcon size={20} /> : <EyeIcon size={20} />}
        </button>
      </div>
      {error && (
        <p id={errorId} className="mt-1.5 text-sm font-medium text-s-red">
          {error}
        </p>
      )}
    </div>
  )
}

export function ResetPasswordPage() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''

  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [errors, setErrors] = useState<{ password?: string; confirmation?: string }>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [done, setDone] = useState(false)

  // The email + token pair comes from the reset link; without both, the form can't work.
  if (!token || !email) {
    return (
      <AuthLayout title="This reset link isn't complete" variant="card">
        <div className="flex flex-col gap-5">
          <p className="text-sm leading-relaxed text-ink-secondary">
            The link you followed is missing some information. It may have been cut off in your email app.
            Request a fresh link and try again.
          </p>
          <PillButton onClick={() => navigate('/forgot-password')} className="w-full">
            Request a new link
          </PillButton>
        </div>
      </AuthLayout>
    )
  }

  if (done) {
    return (
      <AuthLayout title="Password reset" variant="card">
        <div className="flex flex-col gap-5">
          <div className="flex items-start gap-3.5">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-s-green-tint text-s-green">
              <CheckCircleIcon size={22} />
            </span>
            <p className="text-sm leading-relaxed text-ink-secondary">
              Your new password is saved. For your security, you've been signed out of all devices. Sign in again
              to continue.
            </p>
          </div>
          <PillButton onClick={() => navigate('/login')} className="w-full">
            Sign In
          </PillButton>
        </div>
      </AuthLayout>
    )
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    const next = {
      password: validatePassword(password),
      confirmation: validatePasswordConfirmation(password, confirmation),
    }
    setErrors(next)
    if (next.password || next.confirmation) return

    setLoading(true)
    setFormError(null)
    try {
      await api.post('/auth/reset-password', {
        token,
        email,
        password,
        password_confirmation: confirmation,
      })
      setDone(true)
    } catch (error) {
      const apiError = toApiError(error)
      setFormError(apiError.errors.email?.[0] ?? apiError.message)
      setLoading(false)
    }
  }

  return (
    <AuthLayout
      title="Choose a new password"
      variant="card"
      lede={
        <>
          You're resetting the password for <span className="font-semibold text-ink">{email}</span>.
        </>
      }
      footer={
        <Link to="/login" className="font-bold text-royal hover:underline">
          Back to Sign In
        </Link>
      }
    >
      <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-5">
        {formError && (
          <Alert variant="error" title="We couldn't reset your password">
            {formError}{' '}
            <Link to="/forgot-password" className="font-semibold underline">
              Request a new link
            </Link>
          </Alert>
        )}
        <div>
          <FieldLabel>Enter New Password</FieldLabel>
          <PasswordInput
            id="reset-password"
            placeholder="Password"
            autoComplete="new-password"
            value={password}
            onChange={(v) => {
              setPassword(v)
              if (errors.password) setErrors((prev) => ({ ...prev, password: validatePassword(v) }))
            }}
            onBlur={() =>
              setErrors((prev) =>
                password || prev.password ? { ...prev, password: validatePassword(password) } : prev,
              )
            }
            error={errors.password}
            errorId="reset-password-error"
          />
        </div>
        <div>
          <FieldLabel>Confirm New Password</FieldLabel>
          <PasswordInput
            id="reset-confirm"
            placeholder="Confirm Password"
            autoComplete="new-password"
            value={confirmation}
            onChange={(v) => {
              setConfirmation(v)
              if (errors.confirmation)
                setErrors((prev) => ({
                  ...prev,
                  confirmation: validatePasswordConfirmation(password, v),
                }))
            }}
            onBlur={() =>
              setErrors((prev) =>
                confirmation || prev.confirmation
                  ? { ...prev, confirmation: validatePasswordConfirmation(password, confirmation) }
                  : prev,
              )
            }
            error={errors.confirmation}
            errorId="reset-confirm-error"
          />
        </div>
        <PillButton type="submit" disabled={loading} className="w-full">
          {loading ? 'Resetting…' : 'Reset Password'}
        </PillButton>
      </form>
    </AuthLayout>
  )
}
