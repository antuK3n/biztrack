import { NavLink } from 'react-router-dom'

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
const TABS = [
  { to: '/staff/analytics', label: 'Overview', end: true },
  { to: '/staff/analytics/renewal-risk', label: 'Renewal Risk', end: false },
  // The paper's §4 is "Business Growth Analysis"; mockup 122 retitles it
  // "Business Lifecycle Monitoring" and the mockup is newer, so it wins on
  // naming (docs/r-integration-spec.md §4). Shortened here only because a tab
  // strip has no room for the full title.
  { to: '/staff/analytics/business-growth', label: 'Lifecycle', end: false },
  { to: '/staff/analytics/processing-time', label: 'Processing Time', end: false },
]

export function AnalyticsTabs() {
  return (
    <nav aria-label="Analytics sections" className="mb-5 flex flex-wrap gap-2">
      {TABS.map((tab) => (
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
