import { activePortal } from './api'
import type { User } from './types'

/**
 * Whether this account's home is OfficeHome: the office's analytics dashboard
 * over its permits register, rather than the launcher.
 *
 * Client, 19 October 2026 (picker answers): Home is "Analytics, then Permits",
 * no tiles and no tabs. Manage Applications stays its own page with its own
 * menu entry; the Permits entry goes from the menu, and the old Permits
 * address opens Home instead.
 *
 * One rule for three readers: DashboardPage decides which home to draw, the
 * rail (lib/nav.ts) hides "Permits" from anyone whose home already holds the
 * register, and App.tsx sends /staff/admin/permits to that Home.
 *
 * An account lands here holding either half — `analytics.view` or
 * `permit.view_all` — and OfficeHome draws only the halves it holds. Business
 * owners never; the super admin never (their portal, or `user.manage`, which
 * only they hold); a staff account with neither never, so nobody lands on a
 * page with nothing on it.
 */
export function landsOnOfficeHome(user: User): boolean {
  if (user.permissions.includes('application.view_own')) return false
  if (activePortal() === 'admin' || user.permissions.includes('user.manage')) return false

  return user.permissions.includes('analytics.view') || user.permissions.includes('permit.view_all')
}
