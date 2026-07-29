import { create } from 'zustand'
import { PORTAL_KEY, TOKEN_KEY, api, storedPortal } from '../lib/api'
import type { Portal } from '../lib/api'
import type { User } from '../lib/types'

interface AuthState {
  user: User | null
  /** Which sign-in page opened this session. Drives where a 401 sends you. */
  portal: Portal | null
  /** True once the stored token has been checked on app launch. */
  bootstrapped: boolean
  setSession: (token: string, user: User, portal: Portal) => void
  setUser: (user: User) => void
  bootstrap: () => Promise<void>
  logout: () => Promise<void>
}

export const useAuth = create<AuthState>((set) => ({
  user: null,
  portal: storedPortal(),
  bootstrapped: false,

  setSession(token, user, portal) {
    localStorage.setItem(TOKEN_KEY, token)
    localStorage.setItem(PORTAL_KEY, portal)
    set({ user, portal })
  },

  setUser(user) {
    set({ user })
  },

  async bootstrap() {
    const token = localStorage.getItem(TOKEN_KEY)
    if (!token) {
      set({ bootstrapped: true })
      return
    }
    try {
      const { data } = await api.get<{ data: User }>('/auth/me')
      set({ user: data.data, portal: storedPortal(), bootstrapped: true })
    } catch {
      localStorage.removeItem(TOKEN_KEY)
      localStorage.removeItem(PORTAL_KEY)
      set({ user: null, portal: null, bootstrapped: true })
    }
  },

  async logout() {
    try {
      await api.post('/auth/logout')
    } catch {
      // Token may already be revoked — clearing locally is what matters.
    }
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(PORTAL_KEY)
    set({ user: null, portal: null })
  },
}))
