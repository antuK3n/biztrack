import { useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
/*
 * `api` and `toApiError` used to be imported here too. Their only consumer was
 * the email-verification banner (checklist item 99), which resent the
 * verification mail; the banner is gone, so the client goes with it.
 */
import { loginPathFor, portalPath } from '../lib/api'
import { navItemsFor } from '../lib/nav'
import type { User } from '../lib/types'
import { useAuth } from '../stores/auth'
import { useNotifications } from '../stores/notifications'
import { ChatBubble } from './ChatBubble'
import { BellIcon } from './icons'

const ROLE_LABELS: Record<string, string> = {
  business_owner: 'Business owner',
  bplo_staff: 'BPLO staff',
  sanitary_officer: 'Sanitary officer',
  fire_inspector: 'Fire inspector',
  zoning_officer: 'Zoning officer',
  obo_staff: 'Building official staff',
  cenro_officer: 'Environment officer',
  admin: 'Administrator',
}

export function roleLabel(user: User): string {
  return user.roles.map((r) => ROLE_LABELS[r] ?? r).join(', ')
}

/* Prototype rail (PDF p5/p61): royal column, icon + tiny label, active = white tile. */
function Rail({ user }: { user: User }) {
  const navigate = useNavigate()
  const logout = useAuth((s) => s.logout)
  /*
   * Every destination in this shell stays inside the portal the tab is on.
   * The rail is shared by both sites, so a bare '/settings' would walk a
   * signed-in officer out of /staff and onto the citizen site — where their
   * token does not apply and they would look signed out.
   */
  const portal = useAuth((s) => s.portal)
  const [flyout, setFlyout] = useState(false)
  const [confirmOut, setConfirmOut] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!flyout) return
    function onPointerDown(e: PointerEvent) {
      if (!rootRef.current?.contains(e.target as Node)) setFlyout(false)
    }
    document.addEventListener('pointerdown', onPointerDown)
    return () => document.removeEventListener('pointerdown', onPointerDown)
  }, [flyout])

  async function signOut() {
    await logout()
    navigate(loginPathFor(portal))
  }

  const initials = `${user.first_name[0] ?? ''}${user.last_name[0] ?? ''}`.toUpperCase()

  return (
    <aside className="fixed inset-y-0 left-0 z-30 hidden w-20 flex-col items-center bg-royal py-4 lg:flex">
      <nav aria-label="Main" className="flex flex-1 flex-col items-center gap-1 overflow-y-auto">
        {navItemsFor(user, portal).map((item) =>
          item.to ? (
            <NavLink
              key={item.label}
              to={item.to}
              className="group flex w-20 flex-col items-center gap-0.5 py-1.5"
            >
              {({ isActive }) => (
                <>
                  <span
                    className={`flex h-11 w-11 items-center justify-center rounded-xl transition-colors ${
                      isActive ? 'bg-white shadow-card' : 'group-hover:bg-white/15'
                    }`}
                  >
                    <item.icon size={22} className={isActive ? 'text-royal' : 'text-white'} />
                  </span>
                  {/* Two lines' worth of box on every item, wrapped or not, so
                      the icons above them stay on one pitch down the rail.
                      "Other Requirements" wrapping used to push everything
                      below it 12px out of step. */}
                  <span className="min-h-6 max-w-[72px] text-center text-[10px] leading-3 text-white underline-offset-2 group-hover:underline">
                    {item.label}
                  </span>
                </>
              )}
            </NavLink>
          ) : (
            <span
              key={item.label}
              aria-disabled="true"
              title="Coming soon"
              className="flex w-20 flex-col items-center gap-0.5 py-1.5 opacity-55"
            >
              <span className="flex h-11 w-11 items-center justify-center rounded-xl">
                <item.icon size={22} className="text-white" />
              </span>
              <span className="min-h-6 max-w-[72px] text-center text-[10px] leading-3 text-white">{item.label}</span>
            </span>
          ),
        )}
      </nav>

      {/*
       * Bottom: the account flyout (p10). Staff used to get a bare logout icon,
       * which left them no route to Settings and so no way to change their own
       * password (tester item 74). Everyone gets the same menu now.
       */}
      <div ref={rootRef} className="relative mt-2">
        <button
          type="button"
          aria-haspopup="menu"
          aria-expanded={flyout}
          aria-label="Account menu"
          onClick={() => setFlyout((v) => !v)}
          className="flex h-11 w-11 items-center justify-center rounded-full bg-white/90 text-sm font-semibold text-royal shadow-card"
        >
          {initials}
        </button>
        {flyout && (
          <div
            role="menu"
            aria-label="Account"
            className="absolute bottom-0 left-16 z-40 w-44 rounded-r-xl bg-royal-deep py-3 shadow-overlay"
          >
            {[
              { label: 'Settings', to: portalPath(portal, '/settings') },
              // Profile reads the account record; Settings edits it.
              { label: 'Profile', to: portalPath(portal, '/profile') },
            ].map((l) => (
              <button
                key={l.label}
                type="button"
                role="menuitem"
                onClick={() => {
                  setFlyout(false)
                  navigate(l.to)
                }}
                className="block w-full px-5 py-2.5 text-left text-sm font-medium text-white underline underline-offset-2 hover:bg-white/10"
              >
                {l.label}
              </button>
            ))}
            <button
              type="button"
              role="menuitem"
              onClick={() => {
                setFlyout(false)
                setConfirmOut(true)
              }}
              className="block w-full px-5 py-2.5 text-left text-sm font-medium text-white underline underline-offset-2 hover:bg-white/10"
            >
              Log Out
            </button>
          </div>
        )}
      </div>

      {confirmOut && (
        <LogoutModal
          onCancel={() => setConfirmOut(false)}
          onConfirm={() => {
            setConfirmOut(false)
            void signOut()
          }}
        />
      )}
    </aside>
  )
}

/* Prototype WARNING modal (p18): blue header, centered text, split footer. */
function LogoutModal({ onCancel, onConfirm }: { onCancel: () => void; onConfirm: () => void }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
      <div className="w-full max-w-lg overflow-hidden rounded-md bg-white shadow-overlay">
        <div className="bg-royal px-5 py-3 text-base font-bold tracking-wide text-white">WARNING</div>
        <p className="px-8 py-10 text-center text-base text-ink">
          Are you sure you want to log out of this account?
        </p>
        <div className="grid grid-cols-2">
          <button
            type="button"
            onClick={onCancel}
            className="bg-modal-cancel py-3.5 text-sm font-semibold text-ink underline underline-offset-2 hover:brightness-95"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={onConfirm}
            className="bg-modal-confirm py-3.5 text-sm font-semibold text-ink underline underline-offset-2 hover:brightness-95"
          >
            Yes
          </button>
        </div>
      </div>
    </div>
  )
}

/**
 * How often to ask whether anything new has arrived.
 *
 * The same 30 seconds MessagesPanel polls on, and for the same reason: it is
 * short enough that an applicant refreshing a page has usually already been
 * told, and long enough that a session left open all afternoon is not two
 * requests a minute for nothing. One row of payload each time — see the note in
 * `stores/notifications.ts`.
 */
const NOTIFICATION_POLL_MS = 30_000

/**
 * Notification bell, fixed top-right on the canvas (p5) — now with the count.
 *
 * ── There was no indication at all, and notifications are silent ──────────
 *
 * `NotificationService` writes a row, sends to the log mailer and the SMS log,
 * and tells no browser anything: the plan is polling, not websockets. Nothing
 * polled. So this bell was a plain link, unchanged whether the reader had
 * nothing waiting or eleven things, and the only way to find out was to click
 * it on the off-chance. Every notification the system sent — a form returned,
 * fees adjusted, a permit issued, a permit about to expire — arrived where
 * nobody was looking.
 *
 * The count comes from a shared store rather than local state because the
 * notifications page lowers it too, and it is a route rather than a child of
 * this component.
 *
 * ── Accessibility, and why the badge is not only a dot ────────────────────
 *
 * DESIGN.md's Never Color Alone: a red dot alone encodes "you have something"
 * in colour and position and nothing else. The badge carries the NUMBER, so it
 * is legible without colour vision, and the link's accessible name says the
 * same thing in words — a screen reader announces "Notifications, 3 unread"
 * rather than reading a decorative circle. `aria-hidden` on the badge itself
 * stops it being read twice.
 *
 * Nine is the cap. Past that the exact figure is not what anybody is deciding
 * on, and a three-digit badge would burst a 40px control.
 */
function Bell() {
  const portal = useAuth((s) => s.portal)
  const unread = useNotifications((s) => s.unread)
  const refresh = useNotifications((s) => s.refresh)

  useEffect(() => {
    void refresh()
    const timer = setInterval(() => void refresh(), NOTIFICATION_POLL_MS)

    /*
     * A tab left in the background is not polled by most browsers on the
     * timer's schedule, and one brought back to the front is exactly when
     * somebody wants to know. Asking on focus costs one request and closes the
     * gap between "I came back" and the next tick.
     */
    const onFocus = () => void refresh()
    window.addEventListener('focus', onFocus)

    return () => {
      clearInterval(timer)
      window.removeEventListener('focus', onFocus)
    }
  }, [refresh])

  const label = unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'

  return (
    <NavLink
      to={portalPath(portal, '/notifications')}
      title={label}
      aria-label={label}
      className="fixed right-5 top-4 z-20 flex h-10 w-10 items-center justify-center rounded-full text-royal hover:bg-white/60"
    >
      <span className="relative flex items-center justify-center">
        <BellIcon size={24} />
        {unread > 0 && (
          <span
            aria-hidden="true"
            className="absolute -right-2 -top-1.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full border-2 border-canvas bg-s-red px-1 text-[10px] font-bold leading-none text-white tnum"
          >
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </span>
    </NavLink>
  )
}

function MobileTabBar({ user }: { user: User }) {
  const portal = useAuth((s) => s.portal)
  const items = navItemsFor(user, portal)
    .filter((i) => i.mobile && i.to)
    .slice(0, 5)
  return (
    <nav
      aria-label="Main"
      className="fixed inset-x-0 bottom-0 z-20 bg-royal pb-[env(safe-area-inset-bottom)] lg:hidden"
    >
      <ul className="grid" style={{ gridTemplateColumns: `repeat(${items.length}, 1fr)` }}>
        {items.map((item) => (
          <li key={item.label}>
            <NavLink
              to={item.to!}
              className={({ isActive }) =>
                `flex h-14 flex-col items-center justify-center gap-0.5 px-1 text-[10px] font-medium ${
                  isActive ? 'text-white' : 'text-white/65'
                }`
              }
            >
              <item.icon size={21} />
              {/* Same two-line box as the desktop rail. "Payment History" wraps,
                  and at the inherited 24px line-height that pushed the item past
                  the bar's 56px and clipped its icon off the top edge while the
                  other four sat 5px lower. */}
              <span className="min-h-6 text-center leading-3">{item.label}</span>
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  )
}

export function AppShell() {
  const user = useAuth((s) => s.user)
  if (!user) return null
  const isOwner = user.permissions.includes('application.view_own')

  return (
    <div className="min-h-dvh bg-canvas">
      <Rail user={user} />
      <Bell />
      {isOwner && <ChatBubble />}

      <main className="min-h-dvh lg:pl-20">
        {/*
          * No "verify your email" banner here (tester item 99). It nagged on every
          * screen and claimed verification was required before submitting, which no
          * route actually enforces. Verification itself is untouched: the emailed
          * link still lands on /verify-email, and Profile still prints whether the
          * address is verified. If it ever becomes a real gate, block the action
          * that needs it — don't put the nag back on top of every page.
          */}
        <div className="mx-auto w-full max-w-6xl px-4 pb-28 pt-8 lg:px-10 lg:pb-16">
          <Outlet />
        </div>
      </main>

      <MobileTabBar user={user} />
    </div>
  )
}
