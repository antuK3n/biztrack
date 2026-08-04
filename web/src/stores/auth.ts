import { create } from 'zustand'
import { activePortal, api, storedToken, tokenKeyFor } from '../lib/api'
import type { Portal } from '../lib/api'
import type { User } from '../lib/types'

/*
 * One store per tab, holding one portal's session.
 *
 * `portal` is no longer remembered anywhere — it is read from the address bar
 * (see the note in lib/api.ts). That is what lets an administrator and a
 * business owner be signed in at the same time in the same browser: the two
 * tabs are on different paths, so they resolve different token keys and each
 * bootstraps its own user. It used to be a single stored value, which made the
 * second sign-in evict the first.
 */
interface AuthState {
  user: User | null
  /** The site this tab is on. Derived from the URL, never stored. */
  portal: Portal
  /** True once the stored token has been checked on app launch. */
  bootstrapped: boolean
  setSession: (token: string, user: User, portal: Portal) => void
  setUser: (user: User) => void
  bootstrap: () => Promise<void>
  logout: () => Promise<void>
}

export const useAuth = create<AuthState>((set, get) => ({
  user: null,
  portal: activePortal(),
  bootstrapped: false,

  setSession(token, user, portal) {
    localStorage.setItem(tokenKeyFor(portal), token)
    set({ user, portal })
  },

  setUser(user) {
    set({ user })
  },

  async bootstrap() {
    const portal = activePortal()
    if (storedToken(portal) === null) {
      set({ portal, bootstrapped: true })
      return
    }
    try {
      const { data } = await api.get<{ data: User }>('/auth/me')
      set({ user: data.data, portal, bootstrapped: true })
    } catch {
      // Only this portal's key: the other site's session is not ours to end.
      localStorage.removeItem(tokenKeyFor(portal))
      set({ user: null, portal, bootstrapped: true })
    }
  },

  async logout() {
    const portal = get().portal
    try {
      await api.post('/auth/logout')
    } catch {
      // Token may already be revoked — clearing locally is what matters.
    }
    /*
     * Signing out of one portal leaves the other alone. An officer ending their
     * session should not also sign out the owner account someone has open in
     * the next tab: they are different people as far as this browser is
     * concerned, and the API revokes only the token we just sent.
     */
    localStorage.removeItem(tokenKeyFor(portal))
    set({ user: null })
  },
}))
