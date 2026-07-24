import { create } from 'zustand'
import { api, TOKEN_KEY } from '../lib/api'
import type { User } from '../lib/types'

interface AuthState {
  user: User | null
  /** True once the stored token has been checked on app launch. */
  bootstrapped: boolean
  setSession: (token: string, user: User) => void
  setUser: (user: User) => void
  bootstrap: () => Promise<void>
  logout: () => Promise<void>
}

export const useAuth = create<AuthState>((set) => ({
  user: null,
  bootstrapped: false,

  setSession(token, user) {
    localStorage.setItem(TOKEN_KEY, token)
    set({ user })
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
      set({ user: data.data, bootstrapped: true })
    } catch {
      localStorage.removeItem(TOKEN_KEY)
      set({ user: null, bootstrapped: true })
    }
  },

  async logout() {
    try {
      await api.post('/auth/logout')
    } catch {
      // Token may already be revoked — clearing locally is what matters.
    }
    localStorage.removeItem(TOKEN_KEY)
    set({ user: null })
  },
}))
