import { NavLink } from 'react-router-dom'
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
 * These are `/staff/...` paths, and every one of them has to stay that way.
 *
 * They were `/analytics/...` — where these screens lived before the portal
 * split moved the staff site under /staff. Those old paths still resolve, but
 * only to the legacy shim in App.tsx, which is a catch-all:
 *
 *     <Route path="/analytics/*" element={<Navigate to="/staff/analytics" />} />
 *
 * It exists for bookmarks and already-sent notifications, and it throws the
 * subpath away. So all four tabs pointed at URLs that redirected to the
 * Overview, and the strip LOOKED like it worked — the pill moved, the page
 * re-rendered — while Renewal Risk, Lifecycle and Processing Time each showed
 * the dashboard. Reported as "why is overview renewal risk lifecycle and
 * processing time all the same", which is precisely what it was doing.
 *
 * A link from inside the staff site must address the staff site directly.
 */

/*
 * Each tab carries the permission its route demands (see App.tsx), because the
 * four screens are no longer one audience.
 *
 * `analytics.view` is BPLO's and covers three screens; `analytics.processing_time`
 * is the super admin's and covers one. The two are disjoint — neither role holds
 * both — so a strip that renders all four hands every reader at least one tab
 * that RequirePermission will bounce them off. That is a link to a dead end
 * dressed up as navigation, and it is exactly what the client meant by
 * "Processing Time should not exist here".
 *
 * The permission is duplicated from App.tsx rather than derived from it. If a
 * route's guard is ever changed without changing the matching line here, the tab
 * goes back to being a dead end and nothing will fail — e2e/analytics.spec.ts is
 * what catches that.
 */
const TABS = [
  /*
   * "Analytics Dashboard", not "Overview" — the paper's §1 name, and the h1
   * this tab leads to. It was the last short label on the strip: the other two
   * became "Renewal Risk Prediction" and "Business Growth Analysis" when the
   * client asked for the paper's terms, and leaving this one as "Overview"
   * meant the strip named three screens in two vocabularies, one of which
   * appears nowhere in the spec.
   */
  { to: '/staff/analytics', label: 'Analytics Dashboard', end: true, permission: 'analytics.view' },
  {
    to: '/staff/analytics/renewal-risk',
    // The paper's §2 name in full, matching the screen's own h1 and the label
    // AnalyticsDatasets sends back for this dataset.
    label: 'Renewal Risk Prediction',
    end: false,
    permission: 'analytics.view',
  },
  /*
   * Was labelled "Lifecycle" — a shortening of mockup 122's "Business Lifecycle
   * Monitoring", which won on naming over the paper's §4 because it was newer.
   * The client asked for the spec's own term back, so the tab now reads
   * "Business Growth Analysis". The route is unchanged, and the page heading
   * still renders the dataset's own name; only this label moved.
   */
  {
    to: '/staff/analytics/business-growth',
    label: 'Business Growth Analysis',
    end: false,
    permission: 'analytics.view',
  },
  {
    to: '/staff/analytics/processing-time',
    label: 'Processing Time',
    end: false,
    permission: 'analytics.processing_time',
  },
]

export function AnalyticsTabs() {
  const permissions = useAuth((s) => s.user?.permissions)

  const tabs = TABS.filter((tab) => permissions?.includes(tab.permission))

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
          to={tab.to}
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
