import { useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { AuthLayout } from '../../components/AuthLayout'
import { InfoCircleIcon } from '../../components/icons'
import { Alert } from '../../components/ui/Alert'
import { FieldLabel, PillButton, inputCls } from '../../components/ui/Proto'
import { api, toApiError } from '../../lib/api'
import type { User } from '../../lib/types'
import { validateEmail } from '../../lib/validation'
import { useAuth } from '../../stores/auth'

interface FormErrors {
  email?: string
  password?: string
}

export function LoginPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const setSession = useAuth((s) => s.setSession)

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [errors, setErrors] = useState<FormErrors>({})
  const [formError, setFormError] = useState<{ variant: 'error' | 'warning'; title: string; body: string } | null>(null)
  const [loading, setLoading] = useState(false)
  const formRef = useRef<HTMLFormElement>(null)

  function validate(): FormErrors {
    return {
      email: validateEmail(email),
      password: password ? undefined : 'Enter your password.',
    }
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    const next = validate()
    setErrors(next)
    if (next.email || next.password) {
      requestAnimationFrame(() => {
        formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
      })
      return
    }

    setLoading(true)
    setFormError(null)
    try {
      const { data } = await api.post<{ data: { token: string; user: User } }>('/auth/login', {
        email: email.trim(),
        password,
      })
      setSession(data.data.token, data.data.user)
      const from = (location.state as { from?: string } | null)?.from
      navigate(from ?? '/dashboard', { replace: true })
    } catch (error) {
      const apiError = toApiError(error)
      if (apiError.status === 429) {
        setFormError({
          variant: 'warning',
          title: 'Too many attempts',
          body: apiError.message + ' You can also reset your password below.',
        })
      } else if (apiError.status === 403) {
        setFormError({
          variant: 'error',
          title: 'This account is deactivated',
          body: 'Contact the Business Permits and Licensing Office if you think this is a mistake.',
        })
      } else if (apiError.status === 422) {
        setFormError({
          variant: 'error',
          title: "We couldn't sign you in",
          body: 'Check your email and password, then try again.',
        })
      } else {
        setFormError({ variant: 'error', title: 'Something went wrong', body: apiError.message })
      }
      setLoading(false)
    }
  }

  return (
    <AuthLayout
      title="Sign in to BizTrack"
      titleHidden
      footer={
        <>
          Don't have an account?{' '}
          <Link to="/register" className="font-bold text-royal hover:underline">
            Sign Up.
          </Link>
        </>
      }
    >
      <form ref={formRef} onSubmit={handleSubmit} noValidate className="flex flex-col gap-5">
        {formError && (
          <Alert variant={formError.variant} title={formError.title}>
            {formError.body}
          </Alert>
        )}

        <div>
          <FieldLabel>Email or number</FieldLabel>
          <input
            type="email"
            name="email"
            autoComplete="email"
            inputMode="email"
            placeholder="Email or number"
            value={email}
            onChange={(e) => {
              setEmail(e.target.value)
              if (errors.email) setErrors((prev) => ({ ...prev, email: validateEmail(e.target.value) }))
            }}
            onBlur={() => setErrors((prev) => ({ ...prev, email: email ? validateEmail(email) : prev.email }))}
            aria-invalid={errors.email ? true : undefined}
            aria-describedby={errors.email ? 'login-email-error' : undefined}
            className={inputCls}
          />
          {errors.email && (
            <p id="login-email-error" className="mt-1.5 text-sm font-medium text-s-red">
              {errors.email}
            </p>
          )}
        </div>

        <div>
          <FieldLabel>Password</FieldLabel>
          <input
            type="password"
            name="password"
            autoComplete="current-password"
            placeholder="Password"
            value={password}
            onChange={(e) => {
              setPassword(e.target.value)
              if (errors.password && e.target.value) setErrors((prev) => ({ ...prev, password: undefined }))
            }}
            aria-invalid={errors.password ? true : undefined}
            aria-describedby={errors.password ? 'login-password-error' : undefined}
            className={inputCls}
          />
          {errors.password && (
            <p id="login-password-error" className="mt-1.5 text-sm font-medium text-s-red">
              {errors.password}
            </p>
          )}
          <div className="mt-2">
            <Link to="/forgot-password" className="text-xs font-semibold text-ink-secondary hover:underline">
              Forgot Password?
            </Link>
          </div>
        </div>

        <PillButton type="submit" disabled={loading} className="w-full">
          {loading ? 'Signing In…' : 'Sign In'}
        </PillButton>

        {import.meta.env.VITE_USE_MOCK_API === 'true' && (
          <div
            role="status"
            className="flex items-start gap-2 rounded-md border border-line px-3 py-2 text-sm text-ink-secondary"
          >
            <InfoCircleIcon size={16} className="mt-0.5 shrink-0" />
            <p>
              <span className="font-semibold">Demo mode</span>. The API is simulated. Sign in with{' '}
              <span className="font-semibold">owner@biztrack.local</span> and password{' '}
              <span className="font-semibold">biztrack1</span>.
            </p>
          </div>
        )}
      </form>
    </AuthLayout>
  )
}
