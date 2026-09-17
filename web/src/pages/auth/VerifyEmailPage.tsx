import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { AuthLayout } from '../../components/AuthLayout'
import { AlertCircleIcon, CheckCircleIcon } from '../../components/icons'
import { PillButton } from '../../components/ui/Proto'
import { api, toApiError } from '../../lib/api'
import { useAuth } from '../../stores/auth'

/*
 * Where the link in the verification email lands [checklist item #61].
 *
 * ── What this page used to do, and why it stopped ───────────────────────────
 *
 * It read `id` and `hash` out of the query string and POSTed them to
 * `/auth/email/verify` — an unauthenticated endpoint that marked the account
 * confirmed when the hash matched sha1 of its own email address. Both are
 * public knowledge, so anyone could confirm anybody's address. That endpoint is
 * gone; the link in the email is now a SIGNED route on the API, which verifies
 * the account itself and then sends the reader here with the outcome.
 *
 * So this screen no longer performs the verification. It reports it. There is
 * nothing to wait for, which is why the "Verifying your email…" spinner state
 * has gone with the request that justified it — a spinner that resolves
 * instantly is a flicker, not reassurance.
 *
 * No link written before this change can be in circulation: registration mailed
 * nothing and resend was a stub that sent nothing, so there has never been a
 * verification email in anybody's inbox. That is the whole reason there is no
 * compatibility branch here for the old `?id=&hash=` shape.
 */

type Status = 'verified' | 'already' | 'expired' | 'invalid'

function statusFrom(raw: string | null): Status {
  switch (raw) {
    case 'verified':
    case 'already':
    case 'expired':
      return raw
    default:
      // Includes arriving with no query string at all — someone who typed or
      // bookmarked the address. "This link is not valid" is the honest answer
      // to a page reached without a link.
      return 'invalid'
  }
}

export function VerifyEmailPage() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const { user } = useAuth()
  const status = statusFrom(params.get('status'))

  const [resend, setResend] = useState<{ state: 'idle' | 'sending' | 'sent' | 'failed'; message?: string }>({
    state: 'idle',
  })

  /*
   * The way out of a dead link, on the page that reports the dead link.
   *
   * This used to read "Sign in and use Resend verification email from your
   * dashboard", which named a control that does not exist — the dashboard
   * banner was removed as tester item 99 and nothing replaced it. Telling
   * somebody to press a button nobody can find is worse than telling them
   * nothing, so the button is here instead.
   *
   * Only for a signed-in reader: the endpoint resends to the authenticated
   * account, and it has to. An unauthenticated "resend to this address" form
   * would confirm to a stranger which addresses are registered, which is the
   * disclosure the sign-in page has already been hardened against (item #63).
   */
  async function requestNewLink() {
    setResend({ state: 'sending' })
    try {
      const { data } = await api.post<{ message: string }>('/auth/email/resend')
      setResend({ state: 'sent', message: data.message })
    } catch (error) {
      setResend({ state: 'failed', message: toApiError(error).message })
    }
  }

  const ok = status === 'verified' || status === 'already'

  const copy: Record<Status, { title: string; body: string }> = {
    verified: {
      title: 'Email confirmed',
      body: "Your email address is confirmed. You're all set to work on your business permits.",
    },
    already: {
      title: 'Already confirmed',
      body: 'This address was confirmed earlier, so there was nothing left to do.',
    },
    expired: {
      title: 'This link has expired',
      body: 'Confirmation links last an hour, and this one is past it. Ask for a fresh one and it will arrive in the same inbox.',
    },
    invalid: {
      title: "We couldn't confirm your email",
      body: 'This link is not one we sent, or the address it was issued for has changed since.',
    },
  }

  return (
    <AuthLayout title={copy[status].title} variant="card">
      <div className="flex flex-col gap-5">
        <div className="flex items-start gap-3.5">
          {/*
            Colour is never the only signal (DESIGN.md): the heading above says
            which of the four outcomes this is, in words, before the icon does.
          */}
          <span
            className={
              ok
                ? 'flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-s-green-tint text-s-green'
                : 'flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-s-red-tint text-s-red'
            }
          >
            {ok ? <CheckCircleIcon size={22} /> : <AlertCircleIcon size={22} />}
          </span>
          <p className="text-sm leading-relaxed text-ink-secondary">{copy[status].body}</p>
        </div>

        {!ok && user && (
          <div className="flex flex-col gap-2">
            <PillButton
              onClick={() => void requestNewLink()}
              disabled={resend.state === 'sending'}
              className="w-full"
            >
              {resend.state === 'sending' ? 'Sending…' : 'Send a new link'}
            </PillButton>
            {resend.message && (
              <p
                role="status"
                className={
                  resend.state === 'failed'
                    ? 'text-sm font-medium text-s-red'
                    : 'text-sm text-ink-secondary'
                }
              >
                {resend.message}
              </p>
            )}
          </div>
        )}

        {!ok && !user && (
          // Nothing to resend to: the endpoint mails the signed-in account, so
          // there is no address to act on until they sign in.
          <p className="text-sm leading-relaxed text-ink-secondary">
            Sign in and open this page again to ask for a new link.
          </p>
        )}

        {/*
          Demoted to a text action when "Send a new link" is on screen above it.
          Two identical pills stacked read as two equal choices, and they are
          not: the reader on a dead link came here to get a working one.
        */}
        {!ok && user ? (
          <button
            type="button"
            onClick={() => navigate('/dashboard')}
            className="text-sm font-semibold text-ink-secondary hover:underline"
          >
            Go to your dashboard
          </button>
        ) : (
          <PillButton onClick={() => navigate(user ? '/dashboard' : '/login')} className="w-full">
            {user ? 'Go to your dashboard' : 'Sign In'}
          </PillButton>
        )}
      </div>
    </AuthLayout>
  )
}
