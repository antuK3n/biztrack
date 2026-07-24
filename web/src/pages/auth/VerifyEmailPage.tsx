import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { AuthLayout } from '../../components/AuthLayout'
import { AlertCircleIcon, CheckCircleIcon, Spinner } from '../../components/icons'
import { PillButton } from '../../components/ui/Proto'
import { api } from '../../lib/api'
import { useAuth } from '../../stores/auth'

type Status = 'verifying' | 'verified' | 'failed'

export function VerifyEmailPage() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const { user, setUser } = useAuth()
  const [status, setStatus] = useState<Status>('verifying')
  const requested = useRef(false)

  const id = params.get('id')
  const hash = params.get('hash')

  useEffect(() => {
    if (requested.current) return // StrictMode re-runs effects; verify once
    requested.current = true
    if (!id || !hash) {
      setStatus('failed')
      return
    }
    api
      .post('/auth/email/verify', { id: Number(id), hash })
      .then(() => {
        setStatus('verified')
        if (user) setUser({ ...user, email_verified_at: new Date().toISOString() })
      })
      .catch(() => setStatus('failed'))
  }, [id, hash, user, setUser])

  if (status === 'verifying') {
    return (
      <AuthLayout title="Verifying your email" variant="card">
        <div className="flex items-center gap-3.5 text-sm text-ink-secondary">
          <Spinner size={22} className="shrink-0 text-royal" />
          Hold on a moment. This usually takes a few seconds.
        </div>
      </AuthLayout>
    )
  }

  if (status === 'verified') {
    return (
      <AuthLayout title="Email verified" variant="card">
        <div className="flex flex-col gap-5">
          <div className="flex items-start gap-3.5">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-s-green-tint text-s-green">
              <CheckCircleIcon size={22} />
            </span>
            <p className="text-sm leading-relaxed text-ink-secondary">
              Thanks! Your email address is confirmed. You're all set to work on your business permits.
            </p>
          </div>
          <PillButton onClick={() => navigate(user ? '/dashboard' : '/login')} className="w-full">
            {user ? 'Go to your dashboard' : 'Sign In'}
          </PillButton>
        </div>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title="We couldn't verify your email" variant="card">
      <div className="flex flex-col gap-5">
        <div className="flex items-start gap-3.5">
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-s-red-tint text-s-red">
            <AlertCircleIcon size={22} />
          </span>
          <p className="text-sm leading-relaxed text-ink-secondary">
            This verification link is invalid or has expired. Sign in and use{' '}
            <span className="font-semibold text-ink">Resend verification email</span> from your dashboard to get a
            fresh one.
          </p>
        </div>
        <PillButton onClick={() => navigate(user ? '/dashboard' : '/login')} className="w-full">
          {user ? 'Go to your dashboard' : 'Sign In'}
        </PillButton>
      </div>
    </AuthLayout>
  )
}
