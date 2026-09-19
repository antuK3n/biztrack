import { useState, type ComponentType, type SVGProps } from 'react'
import { Link } from 'react-router-dom'
import {
  AmendIcon,
  ChartIcon,
  FilePlusIcon,
  InboxIcon,
  RenewIcon,
  ShieldCheckIcon,
  UsersIcon,
} from '../components/icons'
import { Logo } from '../components/Logo'
import { AccountRestrictedModal } from '../components/ui/Proto'
import { businesses, requests } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import { useAuth } from '../stores/auth'

type IconType = ComponentType<SVGProps<SVGSVGElement> & { size?: number }>

/**
 * White shadow tile + royal label beneath, per the prototype home (PDF p5).
 *
 * `waiting` puts a warning on the tile: a count, and a line under the label
 * saying what it is. It replaces the list that used to sit at the foot of this
 * page — see OwnerHome — and it has to carry both, because a bare dot on an
 * icon is a decoration until something says what it means.
 */
function HomeCard({
  to,
  icon: Icon,
  label,
  waiting = 0,
}: {
  to: string
  icon: IconType
  label: string
  /** How many things behind this tile are waiting on the owner. 0 = nothing. */
  waiting?: number
}) {
  const note =
    waiting === 1 ? 'One document waiting on you' : `${waiting} documents waiting on you`

  return (
    <Link
      to={to}
      className="group flex w-40 flex-col items-center gap-4 sm:w-48"
      /*
       * The count belongs in the link's own name, not only in a badge beside
       * it. A screen reader announcing "Other Requirements" over a tile that
       * visually shouts would be the same failure as colour-only meaning.
       */
      aria-label={waiting > 0 ? `${label} — ${note}` : undefined}
    >
      <span
        className={`relative flex aspect-square w-full items-center justify-center rounded-2xl bg-white text-royal shadow-card transition-shadow group-hover:shadow-raised ${
          waiting > 0 ? 'ring-2 ring-s-orange' : ''
        }`}
      >
        <Icon size={64} strokeWidth={1.5} />
        {waiting > 0 && (
          <span
            aria-hidden="true"
            className="tnum absolute -right-2 -top-2 flex h-8 min-w-8 items-center justify-center rounded-full bg-s-orange px-2 text-sm font-bold text-white shadow-card"
          >
            {waiting > 99 ? '99+' : waiting}
          </span>
        )}
      </span>
      <span className="text-center text-lg font-semibold leading-snug text-royal-deep">{label}</span>
      {waiting > 0 && (
        <span aria-hidden="true" className="-mt-2 text-center text-sm font-semibold text-s-orange-ink">
          {note}
        </span>
      )}
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
   * ── The list moved onto the tile ─────────────────────────────────────────
   *
   * This page used to end with a panel repeating every waiting requirement in
   * full — subject, business, tracking number, the office that asked, a status
   * chip and a View link. The client asked for it gone and for the tile to
   * carry a warning instead, beside the Amendment Form tile.
   *
   * It is the right trade. The home page is four large buttons and one thing
   * to decide — where am I going — and the panel answered a question the
   * requirements page answers better, in the place the tile already points at.
   * What the home page owes the reader is that there IS something waiting, and
   * a count says that in a glance.
   *
   * Only what is waiting on the OWNER counts: `awaits_applicant` is Pending and
   * Needs Resubmission together, because to the person who owes a document
   * those are one situation. Anything with the office is deliberately excluded
   * — a badge counting work you cannot act on teaches people to ignore it.
   */
  const { data: openRequests } = useAsync(() => requests.list({ per_page: 100 }), [])
  const waiting = (openRequests ?? []).filter((r) => r.awaits_applicant).length

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
          waiting={waiting}
        />
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
