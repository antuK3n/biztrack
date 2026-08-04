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
import { businesses } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import { useAuth } from '../stores/auth'

type IconType = ComponentType<SVGProps<SVGSVGElement> & { size?: number }>

/** White shadow tile + royal label beneath, per the prototype home (PDF p5). */
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
        <HomeCard to="/requests" icon={ShieldCheckIcon} label="Other Requirements" />
      </div>
    </div>
  )
}

/* ── Staff / admin landing ────────────────────────────────────────────── */
function StaffHome({ permissions }: { permissions: string[] }) {
  // Absolute /staff paths: this landing only ever renders on the LGU site, and
  // spelling them out keeps them greppable against the route table in App.tsx.
  const cards: { to: string; icon: IconType; label: string; permission: string }[] = [
    { to: '/staff/queue', icon: InboxIcon, label: 'Application Verification', permission: 'application.review' },
    { to: '/staff/analytics', icon: ChartIcon, label: 'Analytics', permission: 'analytics.view' },
    { to: '/staff/admin/users', icon: UsersIcon, label: 'Officer Assignment', permission: 'user.manage' },
  ]
  const visible = cards.filter((c) => permissions.includes(c.permission))

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
