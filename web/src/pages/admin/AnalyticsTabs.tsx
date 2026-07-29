import { NavLink } from 'react-router-dom'

/*
 * The three admin analytics screens share one rail entry, so they need a way to
 * reach each other. A tab strip keeps the sidebar uncluttered and makes the
 * relationship between the overview, Feature 7, and growth analysis obvious.
 */

const TABS = [
  { to: '/analytics', label: 'Overview', end: true },
  { to: '/analytics/processing-time', label: 'Processing Time', end: false },
  { to: '/analytics/business-growth', label: 'Business Growth', end: false },
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
