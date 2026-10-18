import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { PageTitle } from '../components/ui/Proto'
import { activePortal, portalPath } from '../lib/api'
import { useAuth } from '../stores/auth'
import { AnalyticsPage } from './admin/AnalyticsPage'
import { PermitsPage } from './admin/PermitsPage'

type HomeTab = 'permits' | 'analytics'

/**
 * An office admin's home: two tabs, Permits first, then Analytics.
 * DashboardPage decides who lands here (lib/officeHome.ts).
 *
 * Client, 19 October 2026 (picker answers): Home holds the office's permits
 * register and its analytics dashboard; the tile row is removed; Manage
 * Applications stays its own page with its own menu entry; the Permits menu
 * entry goes, and the old Permits address opens this page (App.tsx), carrying
 * its query so the table opens filtered.
 *
 * The two were first drawn one above the other, and the client: *"Permits
 * need to be scrolled very low before being seen."* So they are tabs —
 * "1. Tabs, Permits first" — and neither is buried under the other.
 *
 * ── The tabs are the real screens, not copies ─────────────────────────────
 *
 * "Permits" IS PermitsPage and "Analytics" IS AnalyticsPage, drawn with
 * `embedded`: each keeps its controls on a heading the tab already names, so
 * the heading itself is for screen readers only. /analytics and
 * /admin/permits are unchanged; the full analytics page keeps its Reports tab
 * and Generate Report, which the Analytics tab links to.
 *
 * ── One URL, one reader ───────────────────────────────────────────────────
 *
 * `?tab=` is this page's; everything else in the query belongs to the
 * Permits table (`?status=`, `?q=`, `?office=all`, read once on arrival). The
 * embedded dashboard keeps its office and window in state for that reason —
 * see AnalyticsPage. A link with no `tab` opens on Permits, which is what the
 * redirected Permits address and the dashboard's Suspended links want.
 *
 * An account holding only one of the two permissions gets that screen alone,
 * with no tab strip.
 */
export function OfficeHome() {
  const user = useAuth((s) => s.user)
  const location = useLocation()
  const [params] = useSearchParams()

  const permissions = user?.permissions ?? []
  const canAnalytics = permissions.includes('analytics.view')
  const canPermits = permissions.includes('permit.view_all')

  const home = portalPath(activePortal(), '/dashboard')

  const tabs: { value: HomeTab; label: string }[] = [
    ...(canPermits ? [{ value: 'permits' as const, label: 'Permits' }] : []),
    ...(canAnalytics ? [{ value: 'analytics' as const, label: 'Analytics' }] : []),
  ]
  const asked = params.get('tab')
  const tab: HomeTab = tabs.find((t) => t.value === asked)?.value ?? tabs[0]?.value ?? 'permits'

  return (
    <div>
      <PageTitle>{user?.department?.name ?? 'Home'}</PageTitle>

      {tabs.length > 1 && (
        /*
          Underlined tabs rather than pills: the Permits tab carries its own
          Table / Map pills, and two rows of the same control one above the
          other would read as one choice. Links, so the tab is in the URL and
          Back works; `aria-current` says which is open without relying on
          colour.
        */
        <nav aria-label="Home sections" className="mb-5 flex gap-6 border-b border-line">
          {tabs.map((t) => {
            const active = t.value === tab
            return (
              <Link
                key={t.value}
                to={t.value === 'permits' ? home : `${home}?tab=${t.value}`}
                aria-current={active ? 'page' : undefined}
                // The open tab stays put: re-navigating would reset its table
                // and throw away a search the reader had typed.
                onClick={(e) => {
                  if (active) e.preventDefault()
                }}
                className={`-mb-px border-b-2 px-1 pb-2 text-[15px] font-semibold transition-colors ${
                  active ? 'border-royal text-royal' : 'border-transparent text-ink-secondary hover:text-royal'
                }`}
              >
                {t.label}
              </Link>
            )
          })}
        </nav>
      )}

      {tab === 'analytics' && canAnalytics ? (
        <AnalyticsPage
          embedded
          permitsLink={canPermits ? (status) => `${home}?status=${status}` : undefined}
        />
      ) : canPermits ? (
        /*
          Keyed on the location: the table reads its opening filter once, as
          initial state, so a Suspended link pressed while already here must
          re-open it on what the link names.
        */
        <PermitsPage key={location.key} embedded />
      ) : null}
    </div>
  )
}
