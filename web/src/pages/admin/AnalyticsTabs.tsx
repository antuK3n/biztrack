import { NavLink } from 'react-router-dom'

/*
 * The admin analytics screens share one rail entry, so they need a way to reach
 * each other. A tab strip keeps the sidebar uncluttered and makes the
 * relationship between the overview and the individual analyses obvious.
 *
 * Order follows docs/r-integration-spec.md's screen inventory, so the tabs read
 * in the same sequence as the spec and the client's paper.
 */

const TABS = [
  { to: '/analytics', label: 'Overview', end: true },
  { to: '/analytics/renewal-risk', label: 'Renewal Risk', end: false },
  // The paper's §4 is "Business Growth Analysis"; mockup 122 retitles it
  // "Business Lifecycle Monitoring" and the mockup is newer, so it wins on
  // naming (docs/r-integration-spec.md §4). Shortened here only because a tab
  // strip has no room for the full title.
  { to: '/analytics/business-growth', label: 'Lifecycle', end: false },
  { to: '/analytics/processing-time', label: 'Processing Time', end: false },
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
