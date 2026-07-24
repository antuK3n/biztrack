import axios from 'axios'
import type { ApiError } from './types'

export const TOKEN_KEY = 'biztrack.token'

/*
 * Single API client for /api/v1 (master plan §5.1, sprint 1 §G).
 * Bearer token in localStorage is an accepted capstone tradeoff — see README.
 */
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8080/api/v1',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(undefined, (error) => {
  if (axios.isAxiosError(error) && error.response?.status === 401) {
    localStorage.removeItem(TOKEN_KEY)
    if (window.location.pathname !== '/login') window.location.assign('/login')
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
