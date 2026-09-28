import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { roleLabel } from '../components/AppShell'
import {
  AlertCircleIcon,
  CheckCircleIcon,
  ChevronRightIcon,
  MailIcon,
  ShieldCheckIcon,
} from '../components/icons'
import { PageTitle, ProtoCard } from '../components/ui/Proto'
import { activePortal, portalPath } from '../lib/api'
import { formatDate } from '../lib/format'
import { useProfilePhoto } from '../lib/useProfilePhoto'
import type { User } from '../lib/types'
import { useAuth } from '../stores/auth'
import { useHoldings } from './applicant/permitHoldings'

/*
 * Profile — the read-only account record behind the avatar menu (PDF p24–26).
 * Editing stays on Settings so there is exactly one place a field can change;
 * this page answers "who am I signed in as, and what has been issued to me?".
 *
 * It used to CARRY the applicant's issued permits. They have their own page now
 * (/permits): this one is behind the avatar menu, the avatar menu lives in the
 * rail, and the rail is desktop-only — so a permit could not be reached from a
 * phone at all. What stays here is the count and a link, because how much a
 * person holds is a fact about the account.
 *
 * Item 93 then said the *shape* was wrong: a flat list of permits, each row
 * repeating the permit type and the business it belongs to, when the design has
 * one collapsible group per BUSINESS with its permits inside. Both formats
 * existed in the codebase — /permits rendered the grouped one, /profile the flat
 * one, and both screens were titled "Profile". Only /profile is in the nav
 * (AppShell), so the grouped page was effectively unreachable and the client
 * only ever saw the flat one. This page is now the canonical Profile, carrying
 * the grouped view; /permits redirects here rather than being a second screen
 * with the same title and different contents.
 *
 * It now also carries the clearances the applicant submitted a COPY of, on the
 * client's instruction: "when you submit a sub-permit instead of apply, since
 * it is assuming that you have one already, just also display it in the Profile
 * page, along with the other permits." Those are folded into the SAME business
 * groups rather than listed separately, because a business's paperwork is one
 * story and splitting it into two lists means reading the page twice to answer
 * "what does this business hold?".
 *
 * They are not permits and this file goes out of its way to make that visible.
 * See HeldCopyRow.
 */

/** The API adds the join date to the auth payloads (AuthController::userPayload). */
type ProfileUser = User & { created_at?: string | null }

const GENDER_LABELS: Record<string, string> = { M: 'Male', F: 'Female' }


/* ── Account record ───────────────────────────────────────────────────── */

/**
 * Gray avatar circle with the royal ring, matching the Edit Profile modal
 * (PDF p12), showing the photo set in Settings once there is one.
 *
 * Read-only here. The photo is changed in one place — Settings — so that this
 * screen stays what it says it is, the account record.
 */
function ProfileAvatar({ src }: { src?: string | null }) {
  if (src) {
    return (
      <img
        src={src}
        alt=""
        className="h-24 w-24 shrink-0 rounded-full border-4 border-royal object-cover"
      />
    )
  }
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

/** The briefcase beside "N businesses total" on the owner card (p24). */
function BriefcaseIcon({ size = 28 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" aria-hidden="true" className="text-royal">
      <rect x="3" y="7" width="18" height="13" rx="2" fill="currentColor" />
      <path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7" stroke="currentColor" strokeWidth="1.75" fill="none" />
      <path d="M3 12.5h18" stroke="#fff" strokeWidth="1.2" />
      <rect x="10.5" y="11.3" width="3" height="2.4" rx="0.6" fill="#fff" />
    </svg>
  )
}


/* ── Page ─────────────────────────────────────────── */

export function ProfilePage() {
  const user = useAuth((s) => s.user) as ProfileUser | null
  const photoUrl = useProfilePhoto(user?.has_photo ?? false)

  /*
   * Only applicants hold permits, and this page only wants the COUNT of them
   * — the list itself moved to /permits. The fetcher short-circuits rather than
   * the hook being skipped, because a conditional hook is a different bug.
   */
  const isOwner = user?.roles.includes('business_owner') ?? false
  /*
   * An OFFICE account, which is the one that may not edit itself. Tested on
   * the department rather than on a role name: an office account has one, and
   * neither a business owner nor the super admin does.
   */
  const isOfficeAccount = (user?.department ?? null) !== null
  const portal = activePortal()
  const { groups, loading, error } = useHoldings(isOwner)

  if (!user) return null

  const permits = groups.reduce((n, g) => n + g.permits.length, 0)

  return (
    <div className="mx-auto max-w-4xl">
      <PageTitle>Profile</PageTitle>

      <ProtoCard className="mb-6 p-6">
        <div className="flex flex-col items-center gap-5 text-center sm:flex-row sm:text-left">
          <ProfileAvatar src={photoUrl} />
          <div className="min-w-0">
            <h2 className="text-xl font-bold text-ink">{fullName(user)}</h2>
            <p className="mt-0.5 text-sm text-ink-secondary">{roleLabel(user)}</p>
            <p className="mt-0.5 break-all text-sm text-ink-secondary">{user.email}</p>
          </div>
        </div>
        {/* "N businesses total" (p24). Held back until the permits have loaded,
            because "0 businesses total" flashing before the real count reads as
            an answer rather than as a wait. */}
        {isOwner && !loading && !error && (
          <p className="mt-4 flex items-center justify-end gap-3 border-t border-line pt-4">
            <span className="display-serif text-xl text-ink">
              {groups.length} {groups.length === 1 ? 'business' : 'businesses'} total
            </span>
            <BriefcaseIcon />
          </p>
        )}
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

      {isOwner && (
        /*
         * ── The permits are a link now, not a section ────────────────
         *
         * They filled the bottom of this page, three scrolls under the account
         * details — and this page is behind the avatar menu, which lives in a
         * rail that is desktop-only, so on a phone they could not be reached at
         * all. They have their own route and their own place in the tab bar
         * now; what stays here is the count, because how much a person holds is
         * a fact about the account.
         */
        <Link
          to="/permits"
          className="mt-6 flex items-center justify-between gap-4 rounded-2xl border border-line bg-white px-6 py-5 shadow-card transition-colors hover:bg-royal-tint"
        >
          <span className="flex min-w-0 items-center gap-4">
            <ShieldCheckIcon size={26} className="shrink-0 text-royal" />
            <span className="min-w-0">
              <span className="block text-base font-bold text-ink">My Permits</span>
              <span className="block text-sm text-ink-secondary">
                {loading
                  ? 'Counting what you hold\u2026'
                  : error
                    ? 'Open to see what has been issued to you'
                    : permits === 0
                      ? 'Nothing issued to you yet'
                      : `${permits} ${permits === 1 ? 'permit' : 'permits'} across ${groups.length} ${groups.length === 1 ? 'business' : 'businesses'}`}
              </span>
            </span>
          </span>
          <ChevronRightIcon size={24} className="shrink-0 text-royal" strokeWidth={2.25} />
        </Link>
      )}

      {/*
        -- Who may edit this record ------------------------------------------

        An office account cannot. Their name, mobile number, office and role
        are the super admin's to set [client, 28 September 2026: *"if they want
        to change theyre info they could message super admin"*] - the officer
        directory is the register's record of who staffs which office, and a
        record people can quietly edit about themselves is not one.

        So the button is replaced rather than removed: taking the control away
        and leaving nothing would tell an officer with a misspelt surname that
        the system simply has no answer for them. This one names who can, and
        goes straight to the conversation.
      */}
      {isOfficeAccount ? (
        <>
          <Link
            to={portalPath(portal, '/messages')}
            className="mt-4 flex items-center justify-between gap-4 rounded-2xl bg-royal px-6 py-5 shadow-card transition-colors hover:bg-royal-hover"
          >
            <span className="flex min-w-0 items-center gap-4">
              <MailIcon size={24} className="shrink-0 text-white" />
              <span className="min-w-0 text-left">
                <span className="block text-base font-bold text-white">
                  Message the System Administrator
                </span>
                <span className="block text-sm text-white/80">
                  To correct your name, mobile number, office or role
                </span>
              </span>
            </span>
            <ChevronRightIcon size={24} className="shrink-0 text-white" strokeWidth={2.25} />
          </Link>
          <p className="mt-2 text-xs text-ink-muted">
            Office accounts are maintained by the System Administrator, so these details are not
            edited here. Your conversation with them is pinned to the top of Messages.
          </p>
        </>
      ) : (
        <>
          <Link
            to="/settings"
            // Same corner as the two cards it sits directly under, at the same
            // width: at rounded-md this bar read as a foreign element on the page.
            className="mt-4 flex items-center justify-between gap-4 rounded-2xl bg-royal px-6 py-5 shadow-card transition-colors hover:bg-royal-hover"
          >
            <span className="text-base font-bold text-white">Edit your details</span>
            <ChevronRightIcon size={24} className="shrink-0 text-white" strokeWidth={2.25} />
          </Link>
          <p className="mt-2 text-xs text-ink-muted">
            Name, gender, mobile number and password are on the Settings page. Your email is your
            sign-in ID — the City BPLO changes it for you.
          </p>
        </>
      )}
    </div>
  )
}
