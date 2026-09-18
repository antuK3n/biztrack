import { useState, type ComponentType, type SVGProps } from 'react'
import { Link } from 'react-router-dom'
import {
  AmendIcon,
  ChartIcon,
  FilePlusIcon,
  FolderIcon,
  InboxIcon,
  RenewIcon,
  ShieldCheckIcon,
  UsersIcon,
} from '../components/icons'
import { Logo } from '../components/Logo'
import { AccountRestrictedModal, StatusChip } from '../components/ui/Proto'
import { businessName } from '../lib/format'
import { businesses, requests } from '../lib/resources'
import { REQUIREMENT_CHIP_TONE } from '../lib/status'
import type { OfficerRequest } from '../lib/types'
import { useAsync } from '../lib/useAsync'
import { useAuth } from '../stores/auth'

type IconType = ComponentType<SVGProps<SVGSVGElement> & { size?: number }>

/**
 * White shadow tile + royal label beneath, per the prototype home (PDF p5).
 *
 * `count` draws the same badge the notification bell draws — the red disc with
 * the number in it, capped at 99+, from `UnreadBadge` in components/AppShell.
 * Deliberately copied rather than imported: `UnreadBadge` is private to
 * AppShell, and exporting it would mean editing a file two other people were
 * writing to at the time. If this is touched again, lift ONE badge into
 * components/ui and let both call it — two copies of a badge drift, and a home
 * tile whose count looks unlike the bell's reads as a different kind of thing.
 *
 * `countLabel` is what the badge means in words. The bell says "Notifications,
 * 3 unread" for the same reason: a screen reader announcing a link called
 * "Other Requirements" followed by a bare "3" sounds like a position in a list,
 * not a number of documents owed.
 */
function HomeCard({
  to,
  icon: Icon,
  label,
  count = 0,
  countLabel,
}: {
  to: string
  icon: IconType
  label: string
  count?: number
  countLabel?: (count: number) => string
}) {
  const badged = count > 0
  return (
    <Link
      to={to}
      aria-label={badged && countLabel ? countLabel(count) : undefined}
      className="group flex w-40 flex-col items-center gap-4 sm:w-48"
    >
      {/* relative: the badge pins itself to this tile's corner. */}
      <span className="relative flex aspect-square w-full items-center justify-center rounded-2xl bg-white text-royal shadow-card transition-shadow group-hover:shadow-raised">
        <Icon size={64} strokeWidth={1.5} />
        {/*
          No badge at zero. Nothing is owed, and a "0" on a tile is a thing to
          read and dismiss every single visit.
        */}
        {badged && (
          <span
            aria-hidden="true"
            className="absolute -right-2 -top-2 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-s-red px-1 text-[10px] font-bold leading-none text-white ring-2 ring-canvas"
          >
            {count > 99 ? '99+' : count}
          </span>
        )}
      </span>
      <span className="text-center text-lg font-semibold leading-snug text-royal-deep">{label}</span>
    </Link>
  )
}

/* ── Owner home (PDF p5) ──────────────────────────────────────────────── */
function OwnerHome() {
  const [dismissed, setDismissed] = useState(false)
  // Confirm status exposure at runtime: BusinessResource does not currently
  // include `status`, so `b.status` may be undefined — the modal only fires
  // when a restricted status is actually present. Purely informational.
  const { data } = useAsync(() => businesses.list(), [])
  const restricted = (data ?? []).find(
    (b) => b.status === 'blacklisted' || b.status === 'suspended',
  )
  const showModal = !dismissed && Boolean(restricted)

  /*
   * One fetch, two readers: the count on the tile and the list below it.
   *
   * The panel used to fetch this itself. The tile now carries the same number,
   * and a second `requests.list` would have loaded the page with two identical
   * calls in flight — and, worse, two answers that can disagree, so the tile
   * could read 3 above a list of 2. `awaits_applicant` is the API's own
   * judgement of whose move it is (see the panel's note below); it is not
   * re-derived here, and it does not fall to zero until the document is
   * actually submitted, which is what the count promises.
   */
  const { data: requestData, loading: requestsLoading } = useAsync(
    () => requests.list({ per_page: 100 }),
    [],
  )
  const waiting = (requestData ?? []).filter((r) => r.awaits_applicant)

  return (
    <div className="flex flex-col items-center pt-6 sm:pt-10">
      {showModal && restricted && (
        <AccountRestrictedModal
          variant={restricted.status === 'suspended' ? 'suspended' : 'blacklisted'}
          referenceId={restricted.ban}
          onClose={() => setDismissed(true)}
        />
      )}
      <h1 className="text-center text-[34px] font-bold leading-tight text-ink">Track your businesses with</h1>
      <div className="mt-6">
        <Logo height={72} />
      </div>
      <div className="mt-14 flex flex-wrap items-start justify-center gap-8 lg:gap-12">
        <HomeCard to="/apply?type=new" icon={FilePlusIcon} label="New Business Permit" />
        <HomeCard to="/apply?type=renewal" icon={RenewIcon} label="Renew Business Permit" />
        <HomeCard to="/apply?type=amendment" icon={AmendIcon} label="Amendment Form" />
        <HomeCard
          to="/requests"
          icon={ShieldCheckIcon}
          label="Other Requirements"
          count={requestsLoading ? 0 : waiting.length}
          countLabel={(n) =>
            n === 1
              ? 'Other Requirements, one document waiting on you'
              : `Other Requirements, ${n} documents waiting on you`
          }
        />
      </div>

      <OtherRequirementsPanel waiting={waiting} loading={requestsLoading} />
    </div>
  )
}

/**
 * "Other Requirements" on the owner's home page.
 *
 * The tile above has always LINKED to the requirements page; nothing said there
 * was anything waiting behind it. An office asking for a health certificate had
 * no way of reaching the owner except a notification they might have already
 * dismissed, so the document sat unasked-for and the filing sat blocked.
 *
 * Only what is waiting on the OWNER is listed — `awaits_applicant`, which is
 * Pending and Needs Resubmission together, because to the person who owes a
 * document those are one situation. Anything with the office is deliberately
 * absent: a home page that lists work you cannot act on teaches people to
 * ignore it.
 *
 * The rows arrive from OwnerHome rather than being fetched here, because the
 * tile above now shows the same count and the two must not be able to disagree.
 */
function OtherRequirementsPanel({
  waiting,
  loading,
}: {
  waiting: OfficerRequest[]
  loading: boolean
}) {
  if (loading || waiting.length === 0) return null

  return (
    <section className="mt-14 w-full max-w-3xl" aria-labelledby="other-requirements-heading">
      <div className="mb-3 flex items-baseline justify-between gap-3 border-b-2 border-ink/40 pb-2">
        <h2 id="other-requirements-heading" className="text-xl font-bold text-ink">
          Other Requirements
        </h2>
        <Link to="/requests" className="text-sm font-semibold text-royal underline hover:text-royal-hover">
          See all
        </Link>
      </div>
      <p className="mb-4 text-sm text-ink-secondary">
        {waiting.length === 1
          ? 'One document is waiting on you.'
          : `${waiting.length} documents are waiting on you.`}
      </p>

      <ul className="flex flex-col gap-3">
        {waiting.map((r) => (
          <li key={r.id} className="rounded-xl bg-white px-5 py-4 shadow-card">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="text-base font-bold text-ink">{r.subject}</p>
                {/*
                  Business name AND number. One owner can hold two shops, and a
                  "Health Certificate" with neither on it is a request they
                  cannot act on without opening every one to find out which.
                */}
                <p className="text-sm text-ink-secondary">
                  {businessName(r.application?.business_name ? { name: r.application.business_name } : null)}
                </p>
                <p className="tnum text-xs text-ink-muted">
                  {r.application?.tracking_id || 'Draft — not yet filed'}
                </p>
                <p className="mt-1 text-xs text-ink-secondary">
                  Requested by:{' '}
                  <span className="font-semibold text-ink">
                    {r.from_office?.name ?? r.created_by?.department ?? 'the LGU'}
                  </span>
                </p>
              </div>
              <div className="flex shrink-0 flex-col items-end gap-2">
                {/*
                  The same tone map the Other Requirements page uses, not a
                  hard-coded orange. Every row here is waiting on the owner, so
                  amber was nearly always right — but a REJECTED document is
                  also waiting on them, and drawing a refusal in the waiting
                  colour told them to expect a form to fill rather than a
                  decision to answer. The page one click away said red.

                  The fallback stays orange rather than the requirements page's
                  grey: everything on THIS panel is waiting on the owner by
                  construction, so a status the API adds later is still
                  something they owe, and grey would read as "nothing to do".
                */}
                <StatusChip tone={REQUIREMENT_CHIP_TONE[r.status] ?? 'orange'}>
                  {r.status_label}
                </StatusChip>
                <Link
                  to="/requests"
                  className="rounded-full border border-transparent bg-royal px-4 py-1.5 text-xs font-semibold text-white hover:bg-royal-hover"
                >
                  View requirement
                </Link>
              </div>
            </div>
            {/* The reason it came back, where the owner decides what to do next. */}
            {r.status === 'needs_resubmission' && r.remarks && (
              <p className="mt-3 rounded-lg bg-s-red-tint px-3.5 py-2.5 text-xs font-medium text-s-red">
                {r.remarks}
              </p>
            )}
          </li>
        ))}
      </ul>
    </section>
  )
}

/* ── Staff / admin landing ────────────────────────────────────────────── */
function StaffHome({ permissions }: { permissions: string[] }) {
  // Absolute /staff paths: this landing only ever renders on the LGU site, and
  // spelling them out keeps them greppable against the route table in App.tsx.
  /*
   * `permission` is a single string on every card but Analytics, which carries a
   * list plus a destination per permission.
   *
   * Analytics is no longer one screen behind one permission. It was split along
   * the line the client drew — "BPLO side should only have the 3 dashboards
   * (Processing Time should not exist here) — Super admin side should only have
   * Processing Time dashboard" — so `analytics.view` opens the dashboard and
   * `analytics.processing_time` opens the monitor, and NOBODY holds both.
   *
   * A single `permission: 'analytics.view'` therefore hid this card from the
   * super admin entirely: the one role whose whole job on this screen is
   * oversight arrived at a landing page with no way into the only analytics
   * screen they are allowed to read. Sending them to /staff/analytics instead
   * would have been worse — that route now answers them with a 403.
   *
   * So the card asks which of the two the reader holds and points at the screen
   * that permission actually opens. Same shape as the left rail in lib/nav.ts;
   * if a third analytics permission ever appears, both need the new entry.
   */
  type Card = {
    to: string
    icon: IconType
    label: string
    permission?: string
    anyPermission?: { permission: string; to: string }[]
  }

  const cards: Card[] = [
    { to: '/staff/queue', icon: InboxIcon, label: 'Application Verification', permission: 'application.review' },
    {
      to: '/staff/analytics',
      icon: ChartIcon,
      label: 'Analytics',
      anyPermission: [
        { permission: 'analytics.view', to: '/staff/analytics' },
        { permission: 'analytics.processing_time', to: '/staff/analytics/processing-time' },
      ],
    },
    { to: '/staff/admin/users', icon: UsersIcon, label: 'Officer Assignment', permission: 'user.manage' },
    /*
     * Records was in the rail and not here, and the gap is the bug.
     *
     * This screen is the super admin's landing page — where the account arrives
     * and what it reads as the list of things it may do. Records is the console
     * over the whole register and the largest screen the role has, and it was
     * reachable only from the side.
     *
     * Officer in Charge, Owner Status and Audit Logs are also rail-only and are
     * deliberately NOT tiles: this page is a launcher for the few things the
     * account does often, not a mirror of the rail. If that judgement changes,
     * they belong here in the same shape as the line below.
     *
     * Each carries the same permission its rail entry and its route carry, so
     * the three cannot disagree: nav.ts decides who sees the entry, App.tsx
     * decides who may reach the address, and this decides what the home screen
     * offers. A card gated more loosely than its route is a tile that bounces
     * the reader back to where they started.
     */
    { to: '/staff/admin/records', icon: FolderIcon, label: 'Records', permission: 'user.manage' },
  ]

  const visible = cards.flatMap((c) => {
    if (c.anyPermission) {
      const held = c.anyPermission.find((p) => permissions.includes(p.permission))
      return held ? [{ ...c, to: held.to }] : []
    }
    return c.permission && permissions.includes(c.permission) ? [c] : []
  })

  return (
    <div className="flex flex-col items-center pt-6 sm:pt-10">
      <h1 className="text-center text-[34px] font-bold leading-tight text-ink">Application Verification</h1>
      <div className="mt-4">
        <Logo height={56} />
      </div>
      <div className="mt-14 flex flex-wrap items-start justify-center gap-8 lg:gap-12">
        {visible.map((c) => (
          <HomeCard key={c.to} to={c.to} icon={c.icon} label={c.label} />
        ))}
      </div>
    </div>
  )
}

export function DashboardPage() {
  const user = useAuth((s) => s.user)
  if (!user) return null

  // Business owners hold application.view_own; officers/admins get the verification landing.
  if (user.permissions.includes('application.view_own')) return <OwnerHome />
  return <StaffHome permissions={user.permissions} />
}
