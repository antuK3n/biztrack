import { useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { api, toApiError } from '../lib/api'
import { navItemsFor } from '../lib/nav'
import type { User } from '../lib/types'
import { useAuth } from '../stores/auth'
import { BellIcon, CheckIcon, LogOutIcon, MailIcon, PlusIcon, XIcon } from './icons'

const ROLE_LABELS: Record<string, string> = {
  business_owner: 'Business owner',
  bplo_staff: 'BPLO staff',
  sanitary_officer: 'Sanitary officer',
  fire_inspector: 'Fire inspector',
  admin: 'Administrator',
}

export function roleLabel(user: User): string {
  return user.roles.map((r) => ROLE_LABELS[r] ?? r).join(', ')
}

/* Prototype rail (PDF p5/p61): royal column, icon + tiny label, active = white tile. */
function Rail({ user }: { user: User }) {
  const navigate = useNavigate()
  const logout = useAuth((s) => s.logout)
  const isOwner = user.permissions.includes('application.view_own')
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
    navigate('/login')
  }

  const initials = `${user.first_name[0] ?? ''}${user.last_name[0] ?? ''}`.toUpperCase()

  return (
    <aside className="fixed inset-y-0 left-0 z-30 hidden w-20 flex-col items-center bg-royal py-4 lg:flex">
      <nav aria-label="Main" className="flex flex-1 flex-col items-center gap-1 overflow-y-auto">
        {navItemsFor(user).map((item) =>
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
                  <span className="max-w-[72px] text-center text-[10px] leading-tight text-white underline-offset-2 group-hover:underline">
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
              <span className="max-w-[72px] text-center text-[10px] leading-tight text-white">{item.label}</span>
            </span>
          ),
        )}
      </nav>

      {/* Bottom: avatar flyout (owner, p10) or logout (staff, p61) */}
      <div ref={rootRef} className="relative mt-2">
        {isOwner ? (
          <>
            <button
              type="button"
              aria-haspopup="menu"
              aria-expanded={flyout}
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
                  { label: 'Settings', to: '/settings' },
                  { label: 'Profile', to: '/permits' },
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
          </>
        ) : (
          <button
            type="button"
            title="Log out"
            onClick={() => setConfirmOut(true)}
            className="flex h-11 w-11 items-center justify-center rounded-xl hover:bg-white/15"
          >
            <LogOutIcon size={24} className="text-white" />
          </button>
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

/* Notification bell, fixed top-right on the canvas (p5). */
function Bell() {
  return (
    <NavLink
      to="/notifications"
      title="Notifications"
      className="fixed right-5 top-4 z-20 flex h-10 w-10 items-center justify-center rounded-full text-royal hover:bg-white/60"
    >
      <BellIcon size={24} />
    </NavLink>
  )
}

/* Chatbot bubble + slide-in panel stub (owner screens, p7–p8). */
function ChatBubble() {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button
        type="button"
        aria-label={open ? 'Close BizTrack ChatBot' : 'Open BizTrack ChatBot'}
        onClick={() => setOpen((v) => !v)}
        className="fixed bottom-5 right-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-royal shadow-raised transition-transform hover:scale-105"
      >
        {open ? (
          <XIcon size={24} className="text-white" />
        ) : (
          <svg viewBox="0 0 24 24" width={26} height={26} fill="none" aria-hidden="true">
            <path
              d="M4 6a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H9l-4.2 3.36A.75.75 0 0 1 3.6 18.8V16A3 3 0 0 1 4 13V6Z"
              fill="#fff"
            />
          </svg>
        )}
      </button>
      {open && (
        <div className="fixed bottom-0 right-0 top-0 z-30 flex w-80 flex-col bg-chatbody shadow-overlay">
          <div className="flex items-center justify-between bg-royal px-4 py-3">
            <span className="flex items-center gap-2 text-sm font-bold text-white">
              <span className="flex h-6 w-6 items-center justify-center rounded bg-white/20 text-[10px]">🤖</span>
              BizTrack ChatBot
            </span>
            <button type="button" aria-label="Close" onClick={() => setOpen(false)}>
              <XIcon size={18} className="text-white" />
            </button>
          </div>
          <div className="flex-1 overflow-y-auto p-4">
            <div className="max-w-[85%] rounded-xl bg-white/85 px-3 py-2 text-sm text-ink shadow-card">
              Kumusta! I'm the BizTrack assistant. Ask me about requirements, fees, or where your
              application is. (Coming soon. For now, message your assigned office from any application.)
            </div>
          </div>
          <div className="flex items-center gap-2 p-3">
            <span className="flex h-8 w-8 items-center justify-center rounded-full bg-white/70 text-royal">
              <PlusIcon size={18} />
            </span>
            <input
              disabled
              placeholder="Type here…"
              className="h-9 flex-1 rounded-full bg-white/85 px-4 text-sm outline-none"
            />
          </div>
        </div>
      )}
    </>
  )
}

function MobileTabBar({ user }: { user: User }) {
  const items = navItemsFor(user)
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
                `flex h-14 flex-col items-center justify-center gap-0.5 text-[10px] font-medium ${
                  isActive ? 'text-white' : 'text-white/65'
                }`
              }
            >
              <item.icon size={21} />
              {item.label}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  )
}

function VerifyEmailBanner({ user }: { user: User }) {
  const [state, setState] = useState<'idle' | 'sending' | 'sent' | 'failed'>('idle')
  if (user.email_verified_at) return null

  async function resend() {
    setState('sending')
    try {
      await api.post('/auth/email/resend')
      setState('sent')
    } catch (error) {
      console.error(toApiError(error).message)
      setState('failed')
    }
  }

  return (
    <div className="mb-6 flex flex-col gap-3 rounded-xl bg-white px-4 py-3.5 text-sm text-ink shadow-card sm:flex-row sm:items-center sm:justify-between">
      <div className="flex items-start gap-2.5">
        <MailIcon size={20} className="mt-px shrink-0 text-royal" />
        <p>
          <span className="font-semibold">Verify your email.</span> We sent a link to{' '}
          <span className="font-semibold">{user.email}</span>. Verify it before you submit an
          application.
        </p>
      </div>
      {state === 'sent' ? (
        <p className="flex shrink-0 items-center gap-1.5 font-semibold text-s-green">
          <CheckIcon size={16} /> Sent. Check your inbox
        </p>
      ) : (
        <button
          type="button"
          onClick={resend}
          disabled={state === 'sending'}
          className="shrink-0 text-left font-semibold text-royal underline underline-offset-2 hover:no-underline disabled:opacity-60 sm:text-right"
        >
          {state === 'sending'
            ? 'Sending…'
            : state === 'failed'
              ? "Couldn't send. Try again"
              : 'Resend verification email'}
        </button>
      )}
    </div>
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
        <div className="mx-auto w-full max-w-6xl px-4 pb-28 pt-8 lg:px-10 lg:pb-16">
          <VerifyEmailBanner user={user} />
          <Outlet />
        </div>
      </main>

      <MobileTabBar user={user} />
    </div>
  )
}
