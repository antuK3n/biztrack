import { create } from 'zustand'
import { notifications } from '../lib/resources'

/*
 * How many notifications the signed-in user has not read — the number behind
 * the bell.
 *
 * ── Why this is a store and not state inside the bell ─────────────────────
 *
 * Two places change it and they are nowhere near each other in the tree. The
 * POLLER raises it when the API reports something new; the NOTIFICATIONS PAGE
 * lowers it the moment the reader opens one, and that page is a route, not a
 * child of the bell. Without somewhere shared to put it, marking everything read
 * would leave the badge sitting there until the next poll — a stale count on the
 * one screen that just proved it wrong.
 *
 * ── Why it polls, and why that is not a placeholder ───────────────────────
 *
 * There is no push channel: the plan is polling, no websockets, and
 * `NotificationService` writes a row and fans out to the log mailer and the SMS
 * log without telling any browser. So the only way a badge can appear without a
 * page reload is to ask, and the only question worth asking on a timer is the
 * count.
 *
 * `per_page: 1` is what makes that cheap. `NotificationController::index`
 * computes `unread` with its own COUNT query rather than off the loaded rows —
 * deliberately, so the number does not silently become "unread ones on page one"
 * — which means one row of payload carries the same answer as fifty.
 */

interface NotificationState {
  /** Unread count across every notification, not the loaded page. */
  unread: number
  /** True once a poll has answered, so the badge never flashes a stale zero. */
  loaded: boolean
  /** Ask the API. Silent on failure — a badge must not raise an error banner. */
  refresh: () => Promise<void>
  /** Set it directly, for a screen that has just changed it and knows better. */
  setUnread: (n: number) => void
}

export const useNotifications = create<NotificationState>((set) => ({
  unread: 0,
  loaded: false,

  async refresh() {
    try {
      const res = await notifications.list({ per_page: 1 })
      set({ unread: res.unread, loaded: true })
    } catch {
      /*
       * Swallowed on purpose, and this is the one place in the app where that
       * is right. This runs on a timer that nobody asked for, so its failures
       * are not answers to anything the reader did. A 401 on a token that has
       * just expired would otherwise put an error in front of someone who is
       * about to be redirected to sign in anyway, every thirty seconds.
       *
       * `loaded` deliberately stays false on a failure: it means "the count
       * below is real", and after a failed poll it is not.
       */
    }
  },

  setUnread(n) {
    set({ unread: Math.max(0, n), loaded: true })
  },
}))
