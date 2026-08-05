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
  /**
   * Show when the user holds ANY of these. For an entry whose destinations are
   * split across roles that share no single permission.
   *
   * Analytics is the case that forced this: `analytics.view` (the three BPLO
   * dashboards) and `analytics.processing_time` (the super admin's one screen)
   * are disjoint — neither role holds both — so gating the rail entry on either
   * one alone hides Analytics entirely from the other role. A single
   * `permission` cannot express "either of these people".
   *
   * Ignored when `permission` is set; `permission` is the narrower claim and
   * every other entry still uses it.
   */
  anyPermission?: string[]
  /**
   * Where the entry goes for a user who holds `permission`/`anyPermission[n]`.
   *
   * Only needed when one rail entry has to land different roles on different
   * screens — `to` is the fallback for anyone the map does not cover. Analytics
   * again: the super admin is FORBIDDEN from /staff/analytics (the dashboard is
   * `analytics.view`), so sending them to the shared `to` would bounce them
   * straight back to their home screen via RequirePermission.
   *
   * First match wins, in the order the keys are listed here.
   */
  toByPermission?: Record<string, string>
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
  /*
   * One rail entry, two different audiences behind it.
   *
   * BPLO holds `analytics.view` and gets the three dashboards (Analytics
   * Dashboard, Renewal Risk, Business Growth Analysis). The super admin holds
   * `analytics.processing_time` and gets exactly one screen. The permissions are
   * disjoint by design, so this entry needs `anyPermission` to appear for both
   * and `toByPermission` to send each of them somewhere they are allowed to be.
   *
   * `to` was '/analytics' — a pre-portal-split path that only resolved because
   * the legacy shim in App.tsx redirects it. The rail is inside the staff site
   * and has no business needing a bookmark shim to work, so it now addresses
   * /staff/analytics directly. The shim stays for links already sent out.
   */
  {
    label: 'Analytics',
    icon: ChartIcon,
    to: '/analytics',
    anyPermission: ['analytics.view', 'analytics.processing_time'],
    toByPermission: {
      'analytics.view': '/analytics',
      'analytics.processing_time': '/analytics/processing-time',
    },
  },
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
  return NAV_ITEMS.filter((item) => visibleTo(user, item)).map((item) => {
    const to = destinationFor(user, item)
    return to ? { ...item, to: portalPath(portal, to) } : item
  })
}

/** No permission stated = everyone. Otherwise the single claim, else any of them. */
function visibleTo(user: User, item: NavItem): boolean {
  if (item.permission) return user.permissions.includes(item.permission)
  if (item.anyPermission) return item.anyPermission.some((p) => user.permissions.includes(p))
  return true
}

/**
 * The portal-relative path this user should land on for this entry.
 *
 * `toByPermission` exists so a shared entry does not point one of its audiences
 * at a screen their own route guard will bounce them off. If a new entry ever
 * needs it, list the narrower permission first — the first held key wins.
 */
function destinationFor(user: User, item: NavItem): string | undefined {
  if (item.toByPermission) {
    for (const [permission, to] of Object.entries(item.toByPermission)) {
      if (user.permissions.includes(permission)) return to
    }
  }
  return item.to
}
