import { useState, type ComponentType, type SVGProps } from 'react'
import { Link } from 'react-router-dom'
import {
  AmendIcon,
  ChartIcon,
  FilePlusIcon,
  FolderIcon,
  InboxIcon,
  RenewIcon,
  UsersIcon,
} from '../components/icons'
import { Logo } from '../components/Logo'
import { AccountRestrictedModal } from '../components/ui/Proto'
import { businesses } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import { activePortal, portalPath } from '../lib/api'
import { useAuth } from '../stores/auth'

type IconType = ComponentType<SVGProps<SVGSVGElement> & { size?: number }>

/**
 * White shadow tile + royal label beneath, per the prototype home (PDF p5).
 *
 * It carried an optional count badge for a while — an orange corner count on
 * the owner's Other Requirements tile, with the same fact in the link's name
 * and as a line under the label. That tile is gone (see OwnerHome), and the
 * waiting count moved with the requirements to Messages: the rail badge on
 * Messages and the "waiting on you" count on its Requirements tab. If a home
 * tile ever needs a count again, that version is in the history under
 * "the Other Requirements tile carries a count"; keep the number in the
 * accessible name, not only in the badge.
 */
function HomeCard({ to, icon: Icon, label }: { to: string; icon: IconType; label: string }) {
  return (
    <Link to={to} className="group flex w-40 flex-col items-center gap-4 sm:w-48">
      <span className="flex aspect-square w-full items-center justify-center rounded-2xl bg-white text-royal shadow-card transition-shadow group-hover:shadow-raised">
        <Icon size={64} strokeWidth={1.5} />
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
   * ── No Other Requirements tile ───────────────────────────────────────────
   *
   * The home page once ended with a panel listing every waiting requirement,
   * then with a fourth tile carrying their count. The client's latest word:
   * "Other Requirements — remove it from the homepage; merge it into
   * Messages." Requirements are now the Requirements tab of Messages, and the
   * count of what is waiting on the owner rides on the Messages rail entry.
   * The home screen is back to the three things an owner starts: a new permit,
   * a renewal, an amendment.
   */
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
      </div>
    </div>
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

  /*
   * Paths are PORTAL-RELATIVE, exactly as nav.ts writes its rail entries, and
   * `portalPath` adds the prefix for whichever site this home screen is on.
   *
   * They were absolute `/staff/...` strings, written when the staff site was
   * the only place a super admin could stand. Once /admin became its own portal
   * (item #107) every one of these tiles kept pointing at the staff tree — and
   * the admin session holds no staff token, so pressing Records, Analytics or
   * Officer Assignment from /admin/dashboard bounced the administrator to
   * /staff/login. Reported as "Records crashes". The rail beside these tiles
   * never had the bug, because it has always gone through portalPath.
   *
   * The super admin's Analytics destination matches the rail's: Office
   * Performance, not Processing Time (issue #102).
   */
  const portal = activePortal()
  const cards: Card[] = [
    { to: '/queue', icon: InboxIcon, label: 'Application Verification', permission: 'application.review' },
    {
      to: '/analytics',
      icon: ChartIcon,
      label: 'Analytics',
      anyPermission: [
        { permission: 'analytics.view', to: '/analytics' },
        { permission: 'analytics.processing_time', to: '/analytics/offices' },
      ],
    },
    { to: '/admin/users', icon: UsersIcon, label: 'Officer Assignment', permission: 'user.manage' },
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
    { to: '/admin/records', icon: FolderIcon, label: 'Records', permission: 'user.manage' },
  ].map((c) => ({
    ...c,
    to: portalPath(portal, c.to),
    anyPermission: c.anyPermission?.map((a) => ({ ...a, to: portalPath(portal, a.to) })),
  }))

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
