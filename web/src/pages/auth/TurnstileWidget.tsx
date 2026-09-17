import { useEffect, useRef } from 'react'
import { TURNSTILE_SITE_KEY, captchaEnabled } from './turnstile'

/*
 * Cloudflare Turnstile on the sign-in form [checklist item #62].
 *
 * ── Why Turnstile ───────────────────────────────────────────────────────────
 *
 * It is free at any volume, sets no advertising cookie, and — the reason that
 * decided it for a government service — presents no image puzzle. WCAG 2.1 AA
 * is the stated target in PRODUCT.md, and "select every square with a traffic
 * light" fails it outright for anyone who cannot see the squares. Most visitors
 * see nothing here at all; the challenge runs against the browser, not against
 * the person.
 *
 * ── No key, no widget ───────────────────────────────────────────────────────
 *
 * `VITE_TURNSTILE_SITE_KEY` absent means this renders nothing and reports no
 * token, and the API's `App\Support\Turnstile::enabled()` is false for the same
 * reason on its side. That is what keeps local development, the mock API and
 * the Playwright suite signing in without credentials — a captcha that breaks
 * every test is a captcha whose default is wrong.
 *
 * ── Where this fails CLOSED, deliberately ───────────────────────────────────
 *
 * Two different outages, two different answers, and the split is the design.
 *
 * If the API cannot reach Cloudflare to CHECK a token, it lets the sign-in
 * through (see the note on Turnstile::passes) — an outage there must not stop
 * the city using its own permit system.
 *
 * If this script cannot load in the visitor's browser, there is no token, and
 * the API refuses a missing token whenever a secret is configured. So a visitor
 * whose network blocks challenges.cloudflare.com cannot sign in. That is the
 * one case a captcha exists to catch — a client that produces no token is
 * indistinguishable from the script posting straight at the endpoint — and no
 * amount of client-side politeness can tell the two apart. It is worth knowing
 * before the keys are turned on: the moment `TURNSTILE_SECRET_KEY` is set, a
 * dependency on one CDN being reachable becomes a dependency of signing in.
 */

const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'

interface TurnstileApi {
  render: (
    el: HTMLElement,
    options: {
      sitekey: string
      callback: (token: string) => void
      'error-callback'?: () => void
      'expired-callback'?: () => void
      'timeout-callback'?: () => void
      theme?: 'light' | 'dark' | 'auto'
      action?: string
    },
  ) => string
  remove: (widgetId: string) => void
}

declare global {
  interface Window {
    turnstile?: TurnstileApi
  }
}

let scriptPromise: Promise<void> | null = null

/**
 * One <script> per page, however many widgets ask for it.
 *
 * The promise is cached rather than the boolean "did we append it": React
 * StrictMode mounts effects twice in development, and a boolean would let the
 * second mount run `turnstile.render` against an API that had not finished
 * loading yet.
 */
function loadTurnstile(): Promise<void> {
  if (window.turnstile) return Promise.resolve()
  if (scriptPromise) return scriptPromise

  scriptPromise = new Promise<void>((resolve, reject) => {
    const script = document.createElement('script')
    script.src = SCRIPT_SRC
    script.async = true
    script.defer = true
    script.onload = () => resolve()
    script.onerror = () => {
      // Let a later mount try again rather than caching the failure forever —
      // this is the case where a visitor's connection dropped for a moment.
      scriptPromise = null
      reject(new Error('Turnstile failed to load'))
    }
    document.head.appendChild(script)
  })

  return scriptPromise
}

/**
 * @param onToken   Called with the solved token, or '' when it expires or errs.
 *                  The form holds the token; this component holds no state the
 *                  submit handler needs to reach into.
 * @param resetKey  Bump to draw a fresh challenge. A token is single-use, so a
 *                  refused sign-in must not be retried with the same one — the
 *                  second attempt would fail on the captcha rather than on
 *                  whatever was actually wrong, which is a confusing place to
 *                  leave someone who mistyped a password.
 */
export function TurnstileWidget({
  onToken,
  resetKey = 0,
}: {
  onToken: (token: string) => void
  resetKey?: number
}) {
  const container = useRef<HTMLDivElement>(null)
  // The callback changes identity on every render of the form above; keeping it
  // in a ref means the widget is drawn once per `resetKey` rather than on every
  // keystroke in the password field.
  const latestOnToken = useRef(onToken)
  latestOnToken.current = onToken

  useEffect(() => {
    if (!captchaEnabled()) return
    const el = container.current
    if (!el) return

    let widgetId: string | null = null
    let cancelled = false

    void loadTurnstile()
      .then(() => {
        if (cancelled || !window.turnstile) return
        widgetId = window.turnstile.render(el, {
          sitekey: TURNSTILE_SITE_KEY,
          action: 'login',
          theme: 'light',
          callback: (token) => latestOnToken.current(token),
          // A token that expired or failed is worse than none: it would be sent
          // and rejected. Clearing it makes the form ask for a fresh one.
          'expired-callback': () => latestOnToken.current(''),
          'error-callback': () => latestOnToken.current(''),
          'timeout-callback': () => latestOnToken.current(''),
        })
      })
      .catch(() => {
        // Script blocked or unreachable. Nothing to draw and no token to give;
        // the sign-in page says so rather than failing silently on submit.
        if (!cancelled) latestOnToken.current('')
      })

    return () => {
      cancelled = true
      if (widgetId !== null) window.turnstile?.remove(widgetId)
    }
  }, [resetKey])

  if (!captchaEnabled()) return null

  return (
    <div>
      {/*
        The widget draws its own iframe with its own accessible name. The
        wrapper carries the group label so a screen reader meets the control
        with a name before Cloudflare's frame loads into it, and so the space
        it occupies is not an unexplained gap while it does.
      */}
      <div ref={container} aria-label="Security check" className="min-h-[65px]" />
    </div>
  )
}
