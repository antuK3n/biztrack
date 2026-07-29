import axios from 'axios'
import type { ApiError } from './types'

export const TOKEN_KEY = 'biztrack.token'
/** Set when a 401 kills an existing session so the login page can explain why. */
export const SESSION_EXPIRED_KEY = 'biztrack.session_expired'
/** Which door the session was opened through: 'public' (owners) or 'staff'. */
export const PORTAL_KEY = 'biztrack.portal'

export type Portal = 'public' | 'staff'

/** Sign-in page for a portal, used for redirects after a session ends. */
export function loginPathFor(portal: Portal | null): string {
  return portal === 'staff' ? '/staff/login' : '/login'
}

export function storedPortal(): Portal | null {
  const value = localStorage.getItem(PORTAL_KEY)
  return value === 'staff' || value === 'public' ? value : null
}

/*
 * Single API client for /api/v1 (master plan §5.1, sprint 1 §G).
 * Bearer token in localStorage is an accepted capstone tradeoff — see README.
 */
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(undefined, (error) => {
  if (axios.isAxiosError(error) && error.response?.status === 401) {
    // Tokens expire after 12h (api/config/sanctum.php) and are revoked on
    // logout/password change. A 401 while we hold a token means the session
    // ended — tell the login page so it can say so instead of a generic error.
    const hadSession = localStorage.getItem(TOKEN_KEY) !== null
    // Send staff back to the staff door, not the citizen one.
    const signIn = loginPathFor(storedPortal())
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(PORTAL_KEY)
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
    return {
      status: error.response.status,
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
