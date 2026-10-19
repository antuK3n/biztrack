import { NavLink } from 'react-router-dom'
import { activePortal, portalPath } from '../../lib/api'
import { useAuth } from '../../stores/auth'

/*
 * The admin analytics screens share one rail entry, so they need a way to reach
 * each other. A tab strip keeps the sidebar uncluttered and makes the
 * relationship between the overview and the individual analyses obvious.
 *
 * Order follows docs/r-integration-spec.md's screen inventory, so the tabs read
 * in the same sequence as the spec and the client's paper.
 */

/*
 * These paths are PORTAL-RELATIVE and are prefixed at render time.
 *
 * They were `/staff/...` literals, and before that `/analytics/...` literals
 * that fell through the legacy shim in App.tsx to the Overview, which is how
 * all four tabs once showed the dashboard. Literal staff paths were right while
 * only staff read the dashboard. The super admin reads it too now (checklist
 * 2026-09-27, item 1) from the ADMIN site, where a `/staff/...` link would send
 * them to a portal they hold no session on. So each tab is `portalPath()` of the
 * site this tab is on, which is the same answer for staff and the right one for
 * the admin.
 */

/*
 * Each tab carries the permission its route demands (see App.tsx).
 *
 * `analytics.view` is every office admin's, BPLO's and the super admin's;
 * `analytics.processing_time` is the super admin's alone. The super admin
 * therefore has all four tabs.
 *
 * An office admin and BPLO never see this strip at all since 19 October 2026
 * (client: "To avoid redundancy, we should only have one existing among
 * them", then "Why not implement the report too on the analytics?"): their
 * analytics live on Home's Analytics tab, with its own sub-tabs (OfficeHome),
 * and App.tsx sends every /analytics address of theirs there.
 *
 * The permission is duplicated from App.tsx rather than derived from it. If a
 * route's guard is ever changed without changing the matching line here, the tab
 * goes back to being a dead end and nothing will fail — e2e/analytics.spec.ts is
 * what catches that.
 */
const TABS = [
  /*
   * "Analytics Dashboard", not "Overview" — the paper's §1 name, and the h1
   * this tab leads to.
   *
   * "Renewal Risk Prediction" and "Business Growth Analysis" followed it here
   * until both screens were removed (checklist 2026-09-27, item 6).
   */
  { to: '/analytics', label: 'Analytics Dashboard', end: true, permission: 'analytics.view' },
  /*
   * Report Generation (checklist 2026-09-27, item 7). Every dashboard reader
   * gets it, scoped the same way, which also means every office admin now sees
   * a two-tab strip.
   */
  { to: '/analytics/reports', label: 'Reports', end: false, permission: 'analytics.view' },
  /*
   * No Custom Report tab. It sat here from 19 October 2026 until, the same
   * day, the client asked "what's the difference between Report and Custom
   * Report. I do think it is best to just merge them." The builder is now the
   * second part of the Reports screen ("Build your own report", see
   * ReportsPage), and /analytics/custom-report redirects there (App.tsx).
   */
  /*
   * The super admin's two screens, in the order the question is asked.
   *
   * Office Performance comes first because it answers "which office" and
   * Processing Time answers "what has that office been doing" — a reader who
   * has not yet picked an office has nothing to do on a control chart.
   *
   * Note what this pair does to the strip: until issue #102 the super admin
   * held exactly one analytics screen, so `tabs.length < 2` below hid the strip
   * from them entirely. It now appears for the first time, which is why the
   * two-tab guard is load-bearing rather than defensive.
   */
  /*
   * There was a second copy of this entry, merged in twice with its comment,
   * whose path read '/staff/analytics/offices'. `portalPath()` prefixes it
   * again, so the super admin saw two "Office Performance" tabs and one of
   * them led to /staff/staff/… or /admin/staff/…, a 404. Removed 19 October
   * 2026.
   */
  {
    to: '/analytics/offices',
    // The dataset's own label, and the h1 on the screen it leads to. All three
    // are asserted equal in e2e/office-performance.spec.ts, because a
    // half-applied rename is how a screen and its payload drift apart.
    label: 'Office Performance',
    end: false,
    permission: 'analytics.processing_time',
  },
  {
    to: '/analytics/processing-time',
    label: 'Processing Time',
    end: false,
    permission: 'analytics.processing_time',
  },
]

export function AnalyticsTabs() {
  const permissions = useAuth((s) => s.user?.permissions)

  const tabs = TABS.filter((tab) => permissions?.includes(tab.permission))
  const portal = activePortal()

  /*
   * A tab strip offering one tab is a control with nothing to control: the
   * super admin's only analytics screen is the one they are already on, and a
   * lone highlighted pill above it reads as a promise of somewhere else to go.
   * Zero is the same story while the session is still bootstrapping.
   */
  if (tabs.length < 2) return null

  return (
    <nav aria-label="Analytics sections" className="mb-5 flex flex-wrap gap-2">
      {tabs.map((tab) => (
        <NavLink
          key={tab.to}
          to={portalPath(portal, tab.to)}
          end={tab.end}
          className={({ isActive }) =>
            `rounded-full border px-5 py-1.5 text-sm font-semibold transition-colors ${
              isActive
                ? 'border-royal bg-royal text-white'
                : 'border-line bg-white text-ink-secondary hover:border-royal hover:text-royal'
            }`
          }
        >
          {tab.label}
        </NavLink>
      ))}
    </nav>
  )
}
