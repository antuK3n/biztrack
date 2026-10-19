import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { PageTitle } from '../components/ui/Proto'
import { activePortal, portalPath } from '../lib/api'
import { useAuth } from '../stores/auth'
import { AnalyticsPage } from './admin/AnalyticsPage'
import { OfficePerformancePage } from './admin/OfficePerformancePage'
import { PermitsPage } from './admin/PermitsPage'
import { ReportsPage } from './admin/ReportsPage'

type HomeTab = 'permits' | 'analytics'

/** The Analytics tab's own screens, in `?view=`. Absent = the dashboard. */
type AnalyticsView = 'dashboard' | 'reports' | 'offices'

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
 * "Permits" IS PermitsPage, and each Analytics sub-tab IS its page —
 * AnalyticsPage, ReportsPage, OfficePerformancePage — drawn with `embedded`:
 * each keeps its controls on a heading the tab already names, so the heading
 * itself is for screen readers only.
 *
 * ── Analytics lives here, and only here ───────────────────────────────────
 *
 * Client, 19 October 2026: "To avoid redundancy, we should only have one
 * existing among them" (the dashboard was on this tab AND on /analytics), then
 * "Why not implement the report too on the analytics?" (picker: "1. Yes, all
 * inside Analytics"). So the Analytics tab carries the whole analytics menu as
 * sub-tabs — Dashboard, Reports (the standard reports and the custom report
 * builder, merged the same day), and Office Performance for a holder of
 * `analytics.processing_time` — the rail has no Analytics entry for this
 * reader (lib/nav.ts), and App.tsx sends every /analytics address of theirs
 * to the matching sub-tab with its query. The super admin keeps /analytics.
 *
 * ── One URL, several readers ──────────────────────────────────────────────
 *
 * `?tab=` and `?view=` are this page's. Everything else belongs to whichever
 * screen is open, and only one is ever mounted: the Permits table reads
 * `?status=`, `?q=`, `?office=all` once on arrival; the dashboard keeps
 * `?office=`/`?months=`; Reports keeps `?report=`/`?from=`/`?to=`/`?office=`/
 * `?part=`. Two of those share `office`, which is safe only because every tab
 * and sub-tab link below is written CLEAN — no query carried from one screen
 * to the next. Carry one across and the Permits table would open on the
 * dashboard's office as if it had been asked. The one link that does carry an
 * office is the dashboard's Generate Report, into Reports, where it means the
 * same thing.
 *
 * A link with no `tab` opens on Permits, which is what the redirected Permits
 * address and the dashboard's Suspended links want.
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
  const canOffices = permissions.includes('analytics.processing_time')

  const home = portalPath(activePortal(), '/dashboard')

  const tabs: { value: HomeTab; label: string }[] = [
    ...(canPermits ? [{ value: 'permits' as const, label: 'Permits' }] : []),
    ...(canAnalytics ? [{ value: 'analytics' as const, label: 'Analytics' }] : []),
  ]
  const asked = params.get('tab')
  const tab: HomeTab = tabs.find((t) => t.value === asked)?.value ?? tabs[0]?.value ?? 'permits'

  /*
   * Each sub-tab carries the permission its old route demanded (App.tsx), so
   * nobody is offered a screen the server would refuse. Office Performance is
   * `analytics.processing_time`, which no account landing here holds today.
   * An unknown or refused `view` opens the dashboard.
   */
  const views: { value: AnalyticsView; label: string }[] = [
    { value: 'dashboard', label: 'Dashboard' },
    { value: 'reports', label: 'Reports' },
    ...(canOffices ? [{ value: 'offices' as const, label: 'Office Performance' }] : []),
  ]
  const askedView = params.get('view')
  const view: AnalyticsView = views.find((v) => v.value === askedView)?.value ?? 'dashboard'
  const analyticsHome = `${home}?tab=analytics`

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
        <nav
          aria-label="Home sections"
          className={`${tab === 'analytics' ? 'mb-4' : 'mb-5'} flex gap-6 overflow-x-auto border-b border-line`}
        >
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
                className={`-mb-px shrink-0 border-b-2 px-1 pb-2 text-[15px] font-semibold transition-colors ${
                  active ? 'border-royal text-royal' : 'border-transparent text-ink-secondary hover:text-royal'
                }`}
              >
                {t.label}
              </Link>
            )
          })}
        </nav>
      )}

      {tab === 'analytics' && canAnalytics && (
        /*
          The second level is a segmented control, not a second row of
          underlines: the top row is underlined and the Reports screen carries
          pill presets, and the same control twice over would read as one
          choice. It wraps on a phone rather than pushing the page sideways.
          Links again, so the sub-tab is in the URL and Back works; each is
          written clean (see the note at the top).
        */
        <nav aria-label="Analytics sections" className="mb-5 flex">
          <div className="flex max-w-full flex-wrap gap-1 rounded-lg border border-line bg-white p-1">
            {views.map((v) => {
              const active = v.value === view
              return (
                <Link
                  key={v.value}
                  to={v.value === 'dashboard' ? analyticsHome : `${analyticsHome}&view=${v.value}`}
                  aria-current={active ? 'page' : undefined}
                  // As above: the open sub-tab stays put, keeping a report the
                  // reader has set up.
                  onClick={(e) => {
                    if (active) e.preventDefault()
                  }}
                  className={`rounded-md px-3.5 py-1.5 text-[13px] font-semibold transition-colors ${
                    active ? 'bg-royal-tint text-royal shadow-card' : 'text-ink-secondary hover:text-royal'
                  }`}
                >
                  {v.label}
                </Link>
              )
            })}
          </div>
        </nav>
      )}

      {tab === 'analytics' && canAnalytics ? (
        view === 'reports' ? (
          <ReportsPage embedded />
        ) : view === 'offices' ? (
          <OfficePerformancePage embedded />
        ) : (
          <AnalyticsPage
            embedded
            permitsLink={canPermits ? (status) => `${home}?status=${status}` : undefined}
            reportsLink={`${analyticsHome}&view=reports`}
          />
        )
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
