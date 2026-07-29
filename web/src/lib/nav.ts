import type { ComponentType, SVGProps } from 'react'
import {
  ChartIcon,
  DraftsIcon,
  FolderIcon,
  HistoryIcon,
  HomeIcon,
  InboxIcon,
  MailIcon,
  SearchIcon,
  ShieldCheckIcon,
  TrackIcon,
  UsersIcon,
} from '../components/icons'
import type { User } from './types'

export interface NavItem {
  label: string
  icon: ComponentType<SVGProps<SVGSVGElement> & { size?: number }>
  /** Route path. Absent = the destination isn't built yet (renders as "Soon"). */
  to?: string
  /** Show only when the user holds this permission. Absent = everyone. */
  permission?: string
  /** Include in the mobile bottom tab bar (max 5 survive the filter). */
  mobile?: boolean
}

/*
 * Prototype rail registry (docs/rehaul-spec.md §2).
 * Owner rail (PDF p5): Home · Track · Drafts · Payment History.
 * Staff rail (p61): Home · Track (verification) · Inspections · Other Requirements.
 * Super-admin rail (p93): Officer Assignment · Business Owner Status (+ Analytics).
 * Notifications live behind the bell; Profile/Settings behind the avatar flyout.
 */
const NAV_ITEMS: NavItem[] = [
  { label: 'Home', icon: HomeIcon, to: '/dashboard', mobile: true },
  // Business owner
  { label: 'Track', icon: TrackIcon, to: '/applications', permission: 'application.view_own', mobile: true },
  { label: 'Messages', icon: MailIcon, to: '/messages', permission: 'message.participate', mobile: true },
  { label: 'Drafts', icon: DraftsIcon, to: '/drafts', permission: 'application.create', mobile: true },
  { label: 'Payment History', icon: HistoryIcon, to: '/payments', permission: 'payment.make', mobile: true },
  // Officer / staff
  { label: 'Track', icon: InboxIcon, to: '/queue', permission: 'application.review', mobile: true },
  { label: 'Inspections', icon: SearchIcon, to: '/inspections', permission: 'inspection.manage', mobile: true },
  { label: 'Other Requirements', icon: FolderIcon, to: '/requests', permission: 'request.create' },
  // Admin
  { label: 'Analytics', icon: ChartIcon, to: '/analytics', permission: 'analytics.view' },
  { label: 'Officer Assignment', icon: UsersIcon, to: '/admin/users', permission: 'user.manage' },
  { label: 'Owner Status', icon: ShieldCheckIcon, to: '/admin/owners', permission: 'owner.manage_status' },
]

export function navItemsFor(user: User): NavItem[] {
  return NAV_ITEMS.filter((item) => !item.permission || user.permissions.includes(item.permission))
}
