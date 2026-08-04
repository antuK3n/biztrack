import type { ComponentType, SVGProps } from 'react'
import {
  AuditIcon,
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
import { portalPath } from './api'
import type { Portal } from './api'
import type { User } from './types'

export interface NavItem {
  label: string
  icon: ComponentType<SVGProps<SVGSVGElement> & { size?: number }>
  /**
   * Route path WITHIN the portal, without the `/staff` prefix. Absent = the
   * destination isn't built yet (renders as "Soon").
   *
   * Portal-relative rather than absolute because the two sites mirror each
   * other: '/dashboard' is the citizen home and '/staff/dashboard' the
   * officer's, and writing both out would be two lists to keep in step.
   * `navItemsFor` applies the prefix.
   */
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
  // Officer / staff — these resolve under /staff, because only a staff session
  // holds the permissions that reveal them.
  { label: 'Track', icon: InboxIcon, to: '/queue', permission: 'application.review', mobile: true },
  { label: 'Inspections', icon: SearchIcon, to: '/inspections', permission: 'inspection.manage', mobile: true },
  { label: 'Other Requirements', icon: FolderIcon, to: '/requests', permission: 'request.create' },
  // Admin
  { label: 'Analytics', icon: ChartIcon, to: '/analytics', permission: 'analytics.view' },
  { label: 'Officer Assignment', icon: UsersIcon, to: '/admin/users', permission: 'user.manage' },
  { label: 'Owner Status', icon: ShieldCheckIcon, to: '/admin/owners', permission: 'owner.manage_status' },
  /*
   * Audit Logs was built, routed and permissioned, and then never linked: the
   * only way to it was to type the address. Transparency is the thing this
   * product claims over eBOSS (PRODUCT.md §4), so the trail belongs in the rail.
   */
  { label: 'Audit Logs', icon: AuditIcon, to: '/admin/audit-logs', permission: 'audit.view' },
]

/**
 * The rail for one user on one site.
 *
 * Permission decides WHICH entries appear, the portal decides WHERE they
 * point. The two filters are independent on purpose: a citizen never holds
 * `application.review`, so the officer entries cannot leak onto the public
 * rail even though both sites are built from this one list.
 */
export function navItemsFor(user: User, portal: Portal): NavItem[] {
  return NAV_ITEMS.filter(
    (item) => !item.permission || user.permissions.includes(item.permission),
  ).map((item) => (item.to ? { ...item, to: portalPath(portal, item.to) } : item))
}
