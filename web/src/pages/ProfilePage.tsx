import { useId, useMemo, useState } from 'react'
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
import { PageTitle, ProtoCard, SortFilter, type SortFilterOption } from '../components/ui/Proto'
import { businessName, formatDate } from '../lib/format'
import { permits as permitsApi } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import type { Permit, User } from '../lib/types'
import { useAuth } from '../stores/auth'

/*
 * Profile — the read-only account record behind the avatar menu (PDF p24–26).
 * Editing stays on Settings so there is exactly one place a field can change;
 * this page answers "who am I signed in as, and what has been issued to me?".
 *
 * It also carries the applicant's issued permits (checklist item 79). They live
 * here rather than only on /permits because that is where the client looked for
 * them, and because item 81 takes an approved filing off the Track page — with
 * nothing on Profile, an approved permit would have had nowhere to be.
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
 */

/** The API adds the join date to the auth payloads (AuthController::userPayload). */
type ProfileUser = User & { created_at?: string | null }

const GENDER_LABELS: Record<string, string> = { M: 'Male', F: 'Female' }

/**
 * A permit is "nearing expiry" inside this window. Matches the renewal window
 * the register warns on elsewhere, so a business does not read as fine here and
 * as urgent on the dashboard.
 */
const NEARING_DAYS = 30

/*
 * `/permits` is paginated (default 50, hard cap 200) because unpaged it once
 * answered 4,122 rows. That bound is right for a list with paging controls and
 * wrong here: this screen groups the rows and then prints "N businesses total",
 * so a page-one-only read would quietly under-count a landlord with a dozen
 * businesses. Walk the pages instead. The ceiling stops a pathological account
 * from turning one screen into an unbounded fetch loop.
 */
const MAX_PERMIT_PAGES = 10

async function loadAllPermits(): Promise<Permit[]> {
  const all: Permit[] = []
  for (let page = 1; page <= MAX_PERMIT_PAGES; page++) {
    const { data, meta } = await permitsApi.page({ page, per_page: 200 })
    all.push(...data)
    if (meta.current_page >= meta.last_page) break
  }
  return all
}

/* ── Account record ───────────────────────────────────────────────────── */

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

/* ── Approved Businesses ──────────────────────────────────────────────── */

interface BusinessGroup {
  id: number
  name: string
  permits: Permit[]
  /** Latest expiry in the group — the date shown when nothing is wrong. */
  latestExpiry: string | null
  /** Soonest expiry — the date shown when a renewal is due or already late. */
  soonestExpiry: string | null
  /** Something in the group expires within NEARING_DAYS but has not yet. */
  nearing: boolean
  /** Something in the group is already past its validity. */
  expired: boolean
  /** Something in the group was suspended or revoked by an officer. */
  flagged: boolean
}

const SORTS: SortFilterOption[] = [
  { value: 'name', label: 'Business name (A–Z)' },
  { value: 'name_desc', label: 'Business name (Z–A)' },
  { value: 'expiry', label: 'Expiring soonest' },
  { value: 'expiry_desc', label: 'Expiring latest' },
  { value: 'permits', label: 'Most permits first' },
]

/** "All" stays first: SortFilter marks Filter active by comparing to `options[0]`. */
const FILTERS: SortFilterOption[] = [
  { value: 'all', label: 'All businesses' },
  { value: 'nearing', label: `Expiring within ${NEARING_DAYS} days` },
  { value: 'expired', label: 'Has an expired permit' },
  { value: 'flagged', label: 'Suspended or revoked' },
]

/** Sorts on a nullable date without letting "no date" jump to the front. */
const NEVER = '9999-12-31'

function Triangle({ open }: { open: boolean }) {
  return (
    <svg
      width="20"
      height="20"
      viewBox="0 0 24 24"
      aria-hidden="true"
      className={`shrink-0 text-ink-secondary transition-transform ${open ? 'rotate-180' : ''}`}
    >
      <path d="M5 8h14l-7 9L5 8Z" fill="currentColor" />
    </svg>
  )
}

/*
 * The download icon in the royal permit row used to be a second link to the
 * permit page — same destination as the eye beside it, under an icon that
 * promises a file. It downloads, so the two icons mean two things.
 *
 * `label` is the whole "Business Permit for CedarBloom Café" phrase, not just
 * the permit type: an icon-only control in a list of five identical icons has
 * to say which permit it acts on, or a screen-reader user hears "download"
 * twenty times with no way to tell them apart.
 */
function PermitDownloadButton({ permit, label }: { permit: Permit; label: string }) {
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState(false)

  async function download() {
    setBusy(true)
    setFailed(false)
    try {
      await permitsApi.pdf(permit.id, `${permit.permit_number}.pdf`)
    } catch {
      // The permit page carries the full message; here there is room for the
      // fact that it failed and an invitation to try the other route.
      setFailed(true)
    } finally {
      setBusy(false)
    }
  }

  return (
    <button
      type="button"
      onClick={download}
      disabled={busy}
      aria-label={failed ? `Download failed for ${label}. Try again` : `Download ${label} as PDF`}
      className="shrink-0 rounded text-white transition-opacity hover:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:opacity-50"
    >
      {failed ? <AlertCircleIcon size={22} /> : <DownloadIcon size={22} />}
    </button>
  )
}

/**
 * One business, collapsed to a heading row that opens into its permits (p25–26).
 *
 * The disclosure is a real `<button aria-expanded>` controlling a panel that
 * stays in the DOM and toggles `hidden`, so `aria-controls` always resolves and
 * assistive tech can be told what the triangle opens. The triangle itself is
 * decorative — the button's own text is the business name.
 */
function BusinessRow({ group }: { group: BusinessGroup }) {
  const [open, setOpen] = useState(false)
  const panelId = useId()
  const headingId = useId()

  /*
   * Three different things the date line can be saying, and the row has to say
   * which. `status` covers revoked and suspended, which an officer sets. Expiry
   * is only ever a date passing, so nothing writes it down — a permit whose
   * validity ran out last week still reads `active` in the register. Both are
   * spelled out rather than colour-coded: an expired permit that looks current
   * is the one mistake a download makes permanent.
   */
  const alarming = group.expired || group.nearing
  const expiryLabel = group.expired
    ? 'Permit expired: '
    : group.nearing
      ? 'Nearing Permit Expiration: '
      : 'Permit Expiration: '
  const expiryDate = formatDate(alarming ? group.soonestExpiry : group.latestExpiry)

  return (
    <li className="space-y-2.5">
      <h3>
        <button
          type="button"
          id={headingId}
          onClick={() => setOpen((o) => !o)}
          aria-expanded={open}
          aria-controls={panelId}
          className="flex w-full items-center gap-4 rounded-xl bg-white px-5 py-4 text-left shadow-card transition-colors hover:bg-royal-tint/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal sm:gap-5 sm:px-6 sm:py-5"
        >
          <Triangle open={open} />
          {/* Side by side from `sm` up as designed; stacked below it, because a
              phone-width row put the business name and the expiry in the same
              line and truncated the name to three characters. */}
          <span className="flex min-w-0 flex-1 flex-col gap-0.5 sm:flex-row sm:items-center sm:justify-between sm:gap-5">
            <span className="truncate text-lg font-bold text-ink">{group.name}</span>
            <span className={`shrink-0 text-sm italic ${alarming ? 'font-semibold text-s-red' : 'text-ink-muted'}`}>
              {expiryLabel}
              {expiryDate}
            </span>
          </span>
        </button>
      </h3>
      <ul id={panelId} aria-labelledby={headingId} hidden={!open} className="space-y-2">
        {group.permits.map((permit) => {
          const typeName = permit.permit_type?.name ?? 'Permit'
          /* "Sanitary Permit for CedarBloom Café (MCB-2026-000406)" — the eye
             and the arrow are the only labels a sighted user gets, and neither
             says which of the five rows it belongs to. The number is on the end
             because a renewal leaves two permits of the SAME type on the same
             business, and then the type and the business name together still do
             not tell them apart. */
          const label = `${typeName} for ${group.name} (${permit.permit_number})`
          const expired = permit.days_until_expiry !== null && permit.days_until_expiry < 0
          const note = permit.status !== 'active' ? permit.status_label : expired ? 'Expired' : null

          return (
            <li
              key={permit.id}
              className="flex items-center gap-3 rounded-lg bg-royal px-4 py-3 shadow-card sm:gap-4 sm:px-5"
            >
              <span className="min-w-0 flex-1 truncate text-base font-bold text-white">{typeName}</span>
              {note && (
                <span className="shrink-0 rounded border border-white/70 px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-white">
                  {note}
                </span>
              )}
              {/* The permit number is what an owner quotes at a counter, so it
                  survives the redesign — dropped only where there is no width
                  for it rather than dropped outright. */}
              <span className="hidden shrink-0 text-xs font-semibold text-white/75 md:inline">
                {permit.permit_number}
              </span>
              <Link
                to={`/permits/${permit.id}`}
                aria-label={`View ${label}`}
                className="shrink-0 rounded text-white transition-opacity hover:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
              >
                <EyeIcon size={22} />
              </Link>
              <PermitDownloadButton permit={permit} label={label} />
            </li>
          )
        })}
      </ul>
    </li>
  )
}

/* ── Page ─────────────────────────────────────────────────────────────── */

export function ProfilePage() {
  const user = useAuth((s) => s.user) as ProfileUser | null

  /*
   * Only applicants. An officer opening Profile would otherwise get the permits
   * their office is routed to, listed under a heading that calls them theirs —
   * this page is the account record, and the register-wide view already has its
   * own screens. The fetcher short-circuits rather than the hook being skipped,
   * because a conditional hook is a different bug.
   */
  const isOwner = user?.roles.includes('business_owner') ?? false
  const { data, loading, error, reload } = useAsync<Permit[]>(
    () => (isOwner ? loadAllPermits() : Promise.resolve([])),
    [isOwner],
  )
  const [sort, setSort] = useState('name')
  const [filter, setFilter] = useState('all')

  const groups = useMemo<BusinessGroup[]>(() => {
    const map = new Map<number, BusinessGroup>()
    for (const permit of data ?? []) {
      /*
       * `business` is typed non-nullable and is not. A soft-deleted business
       * leaves its issued permits on the register, and the default scope drops
       * it from the eager load, so this comes back null — the same shape that
       * took the officer queue, the inspection list and the review sheet down
       * by reading `.name` straight off it (RemovedBusinessRenderingTest).
       *
       * Key 0 collects every orphaned permit into one group. They have no
       * business id left to tell them apart by, and one row saying the register
       * no longer holds the business is more useful than several. The permits
       * stay reachable either way — that group opens like any other.
       */
      const business = permit.business as Permit['business'] | null
      const key = business?.id ?? 0
      const group = map.get(key) ?? {
        id: key,
        name: businessName(business),
        permits: [],
        latestExpiry: null,
        soonestExpiry: null,
        nearing: false,
        expired: false,
        flagged: false,
      }
      group.permits.push(permit)
      if (permit.valid_until) {
        if (!group.latestExpiry || permit.valid_until > group.latestExpiry) group.latestExpiry = permit.valid_until
        if (!group.soonestExpiry || permit.valid_until < group.soonestExpiry) group.soonestExpiry = permit.valid_until
      }
      const days = permit.days_until_expiry
      if (days !== null && days < 0) group.expired = true
      if (days !== null && days >= 0 && days <= NEARING_DAYS) group.nearing = true
      if (permit.status !== 'active') group.flagged = true
      map.set(key, group)
    }
    return [...map.values()]
  }, [data])

  /*
   * Sort and Filter are wired to real state rather than rendered bare. The
   * control is deliberately inert without props (its own doc says so), and two
   * pages shipped it that way — a menu that opens onto nothing was checklist
   * item 90. Every option below changes what the list shows.
   */
  const visible = useMemo(() => {
    const matches = (g: BusinessGroup) =>
      filter === 'nearing' ? g.nearing : filter === 'expired' ? g.expired : filter === 'flagged' ? g.flagged : true

    const key = (g: BusinessGroup) => g.soonestExpiry ?? g.latestExpiry ?? NEVER
    const sorted = groups.filter(matches)
    sorted.sort((a, b) => {
      switch (sort) {
        case 'name_desc':
          return b.name.localeCompare(a.name)
        case 'expiry':
          return key(a).localeCompare(key(b))
        case 'expiry_desc':
          return key(b).localeCompare(key(a))
        case 'permits':
          return b.permits.length - a.permits.length || a.name.localeCompare(b.name)
        default:
          return a.name.localeCompare(b.name)
      }
    })
    return sorted
  }, [groups, sort, filter])

  if (!user) return null

  return (
    <div className="mx-auto max-w-4xl">
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
        <section aria-labelledby="approved-businesses" className="mt-10">
          <div className="mb-5 flex flex-wrap items-end justify-between gap-x-4 gap-y-2 border-b border-ink/50 pb-2">
            <h2 id="approved-businesses" className="display-serif text-2xl text-ink-secondary">
              Approved Businesses
            </h2>
            {groups.length > 0 && (
              <SortFilter
                sort={{ value: sort, options: SORTS, onChange: setSort }}
                filter={{ value: filter, options: FILTERS, onChange: setFilter }}
              />
            )}
          </div>

          {loading ? (
            <SkeletonList rows={3} />
          ) : error ? (
            <ErrorState error={error} onRetry={reload} />
          ) : groups.length === 0 ? (
            <EmptyState
              icon={ShieldCheckIcon}
              title="No approved businesses yet"
              description="When an application is approved and the permit is issued, your business and its permits appear here to view or save as PDF."
            />
          ) : visible.length === 0 ? (
            <EmptyState
              icon={ShieldCheckIcon}
              title="No businesses match this filter"
              description={`None of your ${groups.length} approved ${groups.length === 1 ? 'business' : 'businesses'} matches “${FILTERS.find((f) => f.value === filter)?.label}”.`}
              action={
                <button
                  type="button"
                  onClick={() => setFilter('all')}
                  className="rounded-md bg-royal px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-royal-hover"
                >
                  Show all businesses
                </button>
              }
            />
          ) : (
            <ul className="space-y-4">
              {visible.map((group) => (
                <BusinessRow key={group.id} group={group} />
              ))}
            </ul>
          )}
        </section>
      )}

      <Link
        to="/settings"
        // Same corner as the two cards it sits directly under, at the same
        // width: at rounded-md this bar read as a foreign element on the page.
        className="mt-8 flex items-center justify-between gap-4 rounded-2xl bg-royal px-6 py-5 shadow-card transition-colors hover:bg-royal-hover"
      >
        <span className="text-base font-bold text-white">Edit your details</span>
        <ChevronRightIcon size={24} className="shrink-0 text-white" strokeWidth={2.25} />
      </Link>
      <p className="mt-2 text-xs text-ink-muted">
        Name, gender, mobile number and password are on the Settings page. Your email is your sign-in
        ID — the City BPLO changes it for you.
      </p>
    </div>
  )
}
