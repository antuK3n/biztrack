import axios from 'axios'
import type { ApiError } from './types'

/**
 * Set when a 401 kills an existing session so the login page can explain why.
 *
 * sessionStorage rather than localStorage, and that is load-bearing now that
 * two portals can be open at once: it is per-tab, so a staff session expiring
 * in one tab cannot put "your session ended" on the citizen login in another.
 */
export const SESSION_EXPIRED_KEY = 'biztrack.session_expired'

export type Portal = 'public' | 'staff'

/** Everything under here is the LGU site. Everything else is the citizen one. */
export const STAFF_PREFIX = '/staff'

/*
 * ── Two sites, two sessions, one browser ──────────────────────────────────
 *
 * The citizen portal and the LGU portal are separate sites that happen to be
 * served from one origin: `/…` belongs to the business owner, `/staff/…` to
 * the officer and the administrator, each with its own sign-in page, its own
 * rail and its own session.
 *
 * There used to be ONE token, at `biztrack.token`, with a companion
 * `biztrack.portal` recording which door it came through. localStorage is
 * shared by every tab on an origin, so signing in as an administrator in one
 * tab silently overwrote the business owner's session in another — the two
 * could never be open at the same time, which is precisely what anyone
 * demonstrating or testing this system has to do.
 *
 * So the token is keyed by portal, and there is deliberately no stored
 * "current portal" any more. A stored value is global to the browser and would
 * therefore be wrong for one of the two tabs; the ADDRESS BAR is the only
 * thing that differs between them, so the address bar is the source of truth.
 * `activePortal()` reads it per request, and each tab consequently signs its
 * requests with its own token.
 *
 * The consequence worth remembering: crossing between the two sites must be a
 * real navigation (`<a href>`), never a client-side `<Link>`. A router push
 * changes the path without remounting, so the store would still be holding the
 * other portal's user. Every cross-portal link in this app is an anchor.
 */
export function tokenKeyFor(portal: Portal): string {
  return `biztrack.token.${portal}`
}

/** Which site a path belongs to. */
export function portalForPath(pathname: string): Portal {
  return pathname === STAFF_PREFIX || pathname.startsWith(`${STAFF_PREFIX}/`) ? 'staff' : 'public'
}

/** Which site THIS TAB is on. Read fresh: it is the only per-tab signal. */
export function activePortal(): Portal {
  return portalForPath(window.location.pathname)
}

export function storedToken(portal: Portal = activePortal()): string | null {
  return localStorage.getItem(tokenKeyFor(portal))
}

/** A path inside a portal: '/queue' is '/staff/queue' on the LGU site. */
export function portalPath(portal: Portal, path: string): string {
  return portal === 'staff' ? `${STAFF_PREFIX}${path}` : path
}

/** Sign-in page for a portal, used for redirects after a session ends. */
export function loginPathFor(portal: Portal): string {
  return portalPath(portal, '/login')
}

/** Where a portal drops you once you are signed in. */
export function homePathFor(portal: Portal): string {
  return portalPath(portal, '/dashboard')
}

/*
 * Carry a session over from before the split rather than signing everyone out.
 *
 * Testers are mid-filing on the stack behind the tunnel, and a silent logout
 * looks like lost work to someone who cannot see the difference. One pass, on
 * the first load after the upgrade; the old keys never come back.
 */
function migrateLegacySession(): void {
  const legacyToken = localStorage.getItem('biztrack.token')
  if (legacyToken === null) return
  const portal: Portal = localStorage.getItem('biztrack.portal') === 'staff' ? 'staff' : 'public'
  if (localStorage.getItem(tokenKeyFor(portal)) === null) {
    localStorage.setItem(tokenKeyFor(portal), legacyToken)
  }
  localStorage.removeItem('biztrack.token')
  localStorage.removeItem('biztrack.portal')
}

migrateLegacySession()

/*
 * Single API client for /api/v1 (master plan §5.1, sprint 1 §G).
 * Bearer token in localStorage is an accepted capstone tradeoff — see README.
 */
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  // Read per request, not once at module load: a tab's portal is its URL, and
  // this module is a singleton shared by everything running in that tab.
  const token = storedToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(undefined, (error) => {
  if (axios.isAxiosError(error) && error.response?.status === 401) {
    // Tokens expire after 12h (api/config/sanctum.php) and are revoked on
    // logout/password change. A 401 while we hold a token means the session
    // ended — tell the login page so it can say so instead of a generic error.
    const portal = activePortal()
    const hadSession = storedToken(portal) !== null
    const signIn = loginPathFor(portal)
    // Only this portal's key. The other site may have a perfectly good session
    // open in another tab, and it has nothing to do with this 401.
    localStorage.removeItem(tokenKeyFor(portal))
    if (window.location.pathname !== signIn) {
      if (hadSession) sessionStorage.setItem(SESSION_EXPIRED_KEY, '1')
      window.location.assign(signIn)
    }
  }
  return Promise.reject(error)
})

/** Normalize any thrown value into the Laravel error envelope. */
export function toApiError(error: unknown): ApiError {
  if (axios.isAxiosError(error) && error.response) {
    const data = error.response.data as Partial<ApiError> | undefined
    const status = error.response.status
    /*
     * A reply carrying a status but no `message` did not come from Laravel.
     * The API answers every error with the envelope, so when `message` is
     * missing what arrived was somebody else's error page — the tunnel, proxy
     * or load balancer in front of the API returning 502/503/504 because the
     * backend was restarting or the hop between them dropped.
     *
     * That distinction is the whole point of this branch. "Something went
     * wrong on our end" sent a reader hunting for an application bug on a
     * screen whose every endpoint was answering 200, because the failure was
     * one layer out and the copy could not say so. A gateway status is also
     * the one kind of failure where "try again" is genuinely good advice, so
     * it is worth telling people apart from a real server fault.
     */
    if (typeof data?.message !== 'string' && status >= 502 && status <= 504) {
      return {
        status,
        message: 'BizTrack did not answer that request. This is usually brief — please try again.',
        errors: {},
      }
    }
    return {
      status,
      message: data?.message ?? 'Something went wrong on our end. Please try again.',
      errors: data?.errors ?? {},
    }
  }
  return {
    status: 0,
    message: "We couldn't reach BizTrack. Check your connection and try again.",
    errors: {},
  }
}

if (import.meta.env.VITE_USE_MOCK_API === 'true') {
  const { installMockAdapter } = await import('./mock')
  installMockAdapter(api)
}
