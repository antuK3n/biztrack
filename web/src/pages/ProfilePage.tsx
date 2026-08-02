import { useState } from 'react'
import { Link } from 'react-router-dom'
import type { ReactNode } from 'react'
import { roleLabel } from '../components/AppShell'
import {
  AlertCircleIcon,
  CheckCircleIcon,
  ChevronRightIcon,
  DownloadIcon,
  EyeIcon,
  ShieldCheckIcon,
} from '../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../components/ui/primitives'
import { PageTitle, ProtoCard } from '../components/ui/Proto'
import { toApiError } from '../lib/api'
import { businessName, formatDate } from '../lib/format'
import { permits as permitsApi } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import type { Permit, User } from '../lib/types'
import { useAuth } from '../stores/auth'

/*
 * Profile — the read-only account record behind the avatar menu (PDF p10).
 * Editing stays on Settings so there is exactly one place a field can change;
 * this page answers "who am I signed in as, and is anything missing?".
 *
 * It also carries the applicant's issued permits (checklist item 79). They live
 * here rather than only on /permits because that is where the client looked for
 * them, and because item 81 takes an approved filing off the Track page — with
 * nothing on Profile, an approved permit would have had nowhere to be.
 */

/** The API adds the join date to the auth payloads (AuthController::userPayload). */
type ProfileUser = User & { created_at?: string | null }

const GENDER_LABELS: Record<string, string> = { M: 'Male', F: 'Female' }

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

/*
 * One issued permit: what it is, whose it is, and the two things the client
 * asked for — View (the certificate face) and a PDF of the same.
 *
 * `permit.business` is typed non-nullable but comes back null whenever the
 * business was soft-deleted out of the register while its permit stayed on it.
 * The type checker never saw that, which is how the identical read took three
 * officer screens down; businessName() is the shared answer for it.
 */
function PermitRow({ permit }: { permit: Permit }) {
  const [downloading, setDownloading] = useState(false)
  const [downloadError, setDownloadError] = useState<string | null>(null)

  const business = permit.business as Permit['business'] | null
  const label = `${permit.permit_type?.name ?? 'Permit'} — ${businessName(business)}`
  /*
   * Two separate ways a permit stops being current, and the row has to say
   * either. `status` covers revoked and suspended, which an officer sets.
   * Expiry is only ever a date passing, so nothing writes it down — a permit
   * whose validity ran out last week still reads `active` in the register.
   */
  const expired = permit.days_until_expiry !== null && permit.days_until_expiry < 0
  const note = permit.status !== 'active' ? permit.status_label : expired ? 'Expired' : null

  async function download() {
    setDownloading(true)
    setDownloadError(null)
    try {
      await permitsApi.pdf(permit.id, `${permit.permit_number}.pdf`)
    } catch (err) {
      setDownloadError(toApiError(err).message)
    } finally {
      setDownloading(false)
    }
  }

  return (
    <li className="rounded-lg border border-line bg-white px-5 py-4 shadow-card">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-3">
        <div className="min-w-0 flex-1">
          <p className="truncate text-base font-bold text-ink">{permit.permit_type?.name ?? 'Permit'}</p>
          <p className="truncate text-sm text-ink-secondary">{businessName(business)}</p>
          <p className="mt-1 text-xs text-ink-muted">
            <span className="font-semibold">{permit.permit_number}</span>
            {' · '}Valid until {formatDate(permit.valid_until)}
            {/* Spelled out, not colour-coded: an expired permit that looks
                current is the one mistake a download makes permanent. */}
            {note && <span className="ml-1 font-semibold uppercase text-s-red">{note}</span>}
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <Link
            to={`/permits/${permit.id}`}
            aria-label={`View ${label}`}
            className="inline-flex items-center gap-1.5 rounded-md bg-royal px-3.5 py-2 text-sm font-semibold text-white transition-colors hover:bg-royal-hover"
          >
            <EyeIcon size={17} /> View
          </Link>
          <button
            type="button"
            onClick={download}
            disabled={downloading}
            aria-label={`Save ${label} as PDF`}
            className="inline-flex items-center gap-1.5 rounded-md border-2 border-royal px-3.5 py-2 text-sm font-semibold text-royal transition-colors hover:bg-royal-tint disabled:opacity-60"
          >
            <DownloadIcon size={17} /> {downloading ? 'Preparing…' : 'PDF'}
          </button>
        </div>
      </div>
      {downloadError && (
        <p role="alert" className="mt-2 text-sm font-medium text-s-red">
          {downloadError}
        </p>
      )}
    </li>
  )
}

/** The applicant's issued permits, newest first (the API already sorts them). */
function ApprovedPermits() {
  const { data, loading, error, reload } = useAsync(() => permitsApi.list(), [])
  const list = data ?? []

  return (
    <ProtoCard className="mt-6 overflow-hidden">
      <h2 className="border-b border-line px-6 py-3.5 text-sm font-bold text-ink">
        Approved permits{list.length > 0 && <span className="font-semibold text-ink-muted"> ({list.length})</span>}
      </h2>
      <div className="px-6 py-5">
        {loading ? (
          <SkeletonList rows={2} />
        ) : error ? (
          <ErrorState error={error} onRetry={reload} />
        ) : list.length === 0 ? (
          <EmptyState
            icon={ShieldCheckIcon}
            title="No permits issued yet"
            description="Once every office approves an application and the permit is issued, it appears here to view or save as PDF."
          />
        ) : (
          <ul className="space-y-3">
            {list.map((permit) => (
              <PermitRow key={permit.id} permit={permit} />
            ))}
          </ul>
        )}
      </div>
    </ProtoCard>
  )
}

export function ProfilePage() {
  const user = useAuth((s) => s.user) as ProfileUser | null
  if (!user) return null

  /*
   * Only applicants. An officer opening Profile would otherwise get the permits
   * their office is routed to, listed under a heading that calls them theirs —
   * this page is the account record, and the register-wide view already has its
   * own screens.
   */
  const isOwner = user.roles.includes('business_owner')

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
          {/* Shown because it is now editable on Settings. A field the account
              holds but no screen prints is the other half of item 74. */}
          <DetailRow label="Gender">{GENDER_LABELS[user.gender] ?? 'Not specified'}</DetailRow>
          <DetailRow label="Role">{roleLabel(user)}</DetailRow>
          {user.department && <DetailRow label="Department">{user.department.name}</DetailRow>}
          <DetailRow label="Member since">{formatDate(user.created_at)}</DetailRow>
          <DetailRow label="Account status">
            <StatusLine ok={user.is_active}>{user.is_active ? 'Active' : 'Deactivated'}</StatusLine>
          </DetailRow>
        </dl>
      </ProtoCard>

      {isOwner && <ApprovedPermits />}

      <Link
        to="/settings"
        className="mt-6 flex items-center justify-between gap-4 rounded-md bg-royal px-6 py-5 shadow-card transition-colors hover:bg-royal-hover"
      >
        <span className="text-base font-bold text-white">Edit your details</span>
        <ChevronRightIcon size={24} className="shrink-0 text-white" strokeWidth={2.25} />
      </Link>
      <p className="mt-2 text-xs text-ink-muted">
        Your full name, gender, mobile number, and password are changed on the Settings page. Your email
        address is your sign-in ID, so the City BPLO updates it for you.
      </p>
    </div>
  )
}
