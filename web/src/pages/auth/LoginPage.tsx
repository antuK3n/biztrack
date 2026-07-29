import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { AuthLayout } from '../../components/AuthLayout'
import { InfoCircleIcon } from '../../components/icons'
import { Alert } from '../../components/ui/Alert'
import { FieldLabel, PillButton, inputCls } from '../../components/ui/Proto'
import { SESSION_EXPIRED_KEY, api, loginPathFor, toApiError } from '../../lib/api'
import type { Portal } from '../../lib/api'
import type { User } from '../../lib/types'
import { validateEmail } from '../../lib/validation'
import { useAuth } from '../../stores/auth'

interface FormErrors {
  email?: string
  password?: string
}

/**
 * One form, two doors. Business owners sign in at /login; LGU officers and the
 * super admin sign in at /staff/login. The API enforces the split (a staff
 * credential is rejected at the public door and vice versa), so this is not
 * merely cosmetic — see AuthController::login.
 */
export function LoginPage({ portal = 'public' }: { portal?: Portal } = {}) {
  const navigate = useNavigate()
  const location = useLocation()
  const setSession = useAuth((s) => s.setSession)
  const staff = portal === 'staff'

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [errors, setErrors] = useState<FormErrors>({})
  const [formError, setFormError] = useState<{ variant: 'error' | 'warning'; title: string; body: string } | null>(null)
  const [loading, setLoading] = useState(false)
  const [sessionExpired, setSessionExpired] = useState(false)
  const [wrongPortal, setWrongPortal] = useState(false)
  const formRef = useRef<HTMLFormElement>(null)

  useEffect(() => {
    if (sessionStorage.getItem(SESSION_EXPIRED_KEY) === '1') {
      sessionStorage.removeItem(SESSION_EXPIRED_KEY)
      setSessionExpired(true)
    }
  }, [])

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
    setSessionExpired(false)
    setWrongPortal(false)
    try {
      const { data } = await api.post<{ data: { token: string; user: User } }>('/auth/login', {
        email: email.trim(),
        password,
        portal,
      })
      setSession(data.data.token, data.data.user, portal)
      const from = (location.state as { from?: string } | null)?.from
      navigate(from ?? '/dashboard', { replace: true })
    } catch (error) {
      const apiError = toApiError(error)
      if (apiError.status === 409) {
        // Right credentials, wrong door. Send them to the other one.
        setFormError({
          variant: 'warning',
          title: 'Use the other sign-in page',
          body: apiError.message,
        })
        setWrongPortal(true)
      } else if (apiError.status === 429) {
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
      title={staff ? 'LGU staff sign-in' : 'Sign in to BizTrack'}
      lede={staff ? 'For City of Malabon permit officers and administrators.' : undefined}
      titleHidden={!staff}
      footer={
        staff ? (
          <>
            Are you a business owner?{' '}
            <Link to="/login" className="font-bold text-royal hover:underline">
              Sign in here.
            </Link>
          </>
        ) : (
          <>
            Don't have an account?{' '}
            <Link to="/register" className="font-bold text-royal hover:underline">
              Sign Up.
            </Link>
          </>
        )
      }
    >
      <form ref={formRef} onSubmit={handleSubmit} noValidate className="flex flex-col gap-5">
        {sessionExpired && !formError && (
          <Alert variant="warning" title="Your session has expired">
            For your security, sessions end after 12 hours. Sign in again to continue.
          </Alert>
        )}
        {formError && (
          <Alert variant={formError.variant} title={formError.title}>
            {formError.body}
            {wrongPortal && (
              <>
                {' '}
                <Link to={loginPathFor(staff ? 'public' : 'staff')} className="font-bold text-royal hover:underline">
                  Go there now.
                </Link>
              </>
            )}
          </Alert>
        )}

        <div>
          <FieldLabel>{staff ? 'Work email' : 'Email or number'}</FieldLabel>
          <input
            type="email"
            name="email"
            autoComplete="email"
            inputMode="email"
            placeholder={staff ? 'Work email' : 'Email or number'}
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
