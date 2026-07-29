import { Link } from 'react-router-dom'
import type { ReactNode } from 'react'
import { roleLabel } from '../components/AppShell'
import { AlertCircleIcon, CheckCircleIcon, ChevronRightIcon } from '../components/icons'
import { PageTitle, ProtoCard } from '../components/ui/Proto'
import { formatDate } from '../lib/format'
import type { User } from '../lib/types'
import { useAuth } from '../stores/auth'

/*
 * Profile — the read-only account record behind the avatar menu (PDF p10).
 * Editing stays on Settings so there is exactly one place a field can change;
 * this page answers "who am I signed in as, and is anything missing?".
 */

/** The API adds the join date to the auth payloads (AuthController::userPayload). */
type ProfileUser = User & { created_at?: string | null }

/** Gray avatar circle with the royal ring, matching the Edit Profile modal (PDF p12). */
function ProfileAvatar() {
  return (
    <span
      aria-hidden="true"
      className="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-full border-4 border-royal bg-line"
    >
      <svg viewBox="0 0 24 24" className="mt-4 h-20 w-20 fill-ink-muted">
        <circle cx="12" cy="8" r="4" />
        <path d="M12 13.5c-4.4 0-7 2.6-7 6.5h14c0-3.9-2.6-6.5-7-6.5Z" />
      </svg>
    </span>
  )
}

function DetailRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid gap-1 border-b border-line px-6 py-4 last:border-b-0 sm:grid-cols-[13rem_1fr] sm:gap-4">
      <dt className="text-[13px] font-semibold text-ink-secondary">{label}</dt>
      <dd className="text-sm text-ink">{children}</dd>
    </div>
  )
}

/** Value plus icon, so status never rests on colour alone. */
function StatusLine({ ok, children }: { ok: boolean; children: ReactNode }) {
  return (
    <span className={`inline-flex items-center gap-1.5 font-semibold ${ok ? 'text-s-green' : 'text-s-red'}`}>
      {ok ? <CheckCircleIcon size={16} /> : <AlertCircleIcon size={16} />}
      {children}
    </span>
  )
}

function fullName(user: User): string {
  return [user.first_name, user.middle_name, user.last_name, user.suffix].filter(Boolean).join(' ')
}

export function ProfilePage() {
  const user = useAuth((s) => s.user) as ProfileUser | null
  if (!user) return null

  return (
    <div className="mx-auto max-w-3xl">
      <PageTitle>Profile</PageTitle>

      <ProtoCard className="mb-6 p-6">
        <div className="flex flex-col items-center gap-5 text-center sm:flex-row sm:text-left">
          <ProfileAvatar />
          <div className="min-w-0">
            <h2 className="text-xl font-bold text-ink">{fullName(user)}</h2>
            <p className="mt-0.5 text-sm text-ink-secondary">{roleLabel(user)}</p>
            <p className="mt-0.5 break-all text-sm text-ink-secondary">{user.email}</p>
          </div>
        </div>
      </ProtoCard>

      <ProtoCard className="overflow-hidden">
        <h2 className="border-b border-line px-6 py-3.5 text-sm font-bold text-ink">Account details</h2>
        <dl>
          <DetailRow label="Full name">{fullName(user)}</DetailRow>
          <DetailRow label="Email address">
            <span className="break-all">{user.email}</span>
            <span className="mt-1 block">
              {user.email_verified_at ? (
                <StatusLine ok>Verified {formatDate(user.email_verified_at)}</StatusLine>
              ) : (
                <StatusLine ok={false}>Not verified yet</StatusLine>
              )}
            </span>
          </DetailRow>
          <DetailRow label="Mobile number">{user.mobile_number || 'Not set'}</DetailRow>
          <DetailRow label="Role">{roleLabel(user)}</DetailRow>
          {user.department && <DetailRow label="Department">{user.department.name}</DetailRow>}
          <DetailRow label="Member since">{formatDate(user.created_at)}</DetailRow>
          <DetailRow label="Account status">
            <StatusLine ok={user.is_active}>{user.is_active ? 'Active' : 'Deactivated'}</StatusLine>
          </DetailRow>
        </dl>
      </ProtoCard>

      <Link
        to="/settings"
        className="mt-6 flex items-center justify-between gap-4 rounded-md bg-royal px-6 py-5 shadow-card transition-colors hover:bg-royal-hover"
      >
        <span className="text-base font-bold text-white">Edit your details</span>
        <ChevronRightIcon size={24} className="shrink-0 text-white" strokeWidth={2.25} />
      </Link>
      <p className="mt-2 text-xs text-ink-muted">
        Your name, mobile number, and password are changed on the Settings page. Your email address is your
        sign-in ID, so the City BPLO updates it for you.
      </p>
    </div>
  )
}
