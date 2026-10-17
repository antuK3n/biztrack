import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { PageTitle } from '../components/ui/Proto'
import { activePortal, portalPath } from '../lib/api'
import { analytics, permits } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import { useAuth } from '../stores/auth'
import { permitCodeForDepartment } from './admin/permitColumns'
import { PermitsPage } from './admin/PermitsPage'
import { QueuePage } from './officer/QueuePage'
import { countQueueFocus, type QueueFocus } from './officer/queueFocus'

/**
 * An office admin's home: four figures over the two tables they work from.
 *
 * Client suggestion, approved 18 October 2026, replacing the launcher of big
 * tiles (Manage Applications / Analytics / Officer Assignment) for BPLO and the
 * five clearance offices. The super admin keeps the launcher — their home is a
 * set of consoles, not a desk — and owners keep theirs. DashboardPage decides
 * who lands here.
 *
 * ── The tables are the real screens, not copies ───────────────────────────
 *
 * The Queue tab IS QueuePage and the Permits tab IS PermitsPage, drawn with
 * `embedded` so the page heading is not said twice. A copy would be a second
 * queue to keep in step with the first, which is the drift every comment on
 * QueuePage is written against. /queue and /admin/permits are unchanged.
 *
 * Which tab is open is in the URL (`?tab=queue|permits`), so a link can open
 * either and Back returns to the one the reader left.
 *
 * ── Every tile is the count of the list it opens ──────────────────────────
 *
 * A tile that says 12 over a list of 9 teaches the reader to trust neither,
 * so each figure is fetched with the same request its destination sends:
 *
 *   Waiting on you   /assignments, the queue's own "waiting" narrowing
 *                    (QueueFocus) — `meta.total` of one row.
 *   Overdue          the same, past the RA 11032 deadline (`overdue=1`).
 *   Suspended        /permits?status=suspended, on the office the Permits tab
 *                    opens on — PermitController's own office boundary.
 *   Fees this month  analytics/fees-month — the dashboard's Fees Collected
 *                    figure under the dashboard's office rule. Opens the
 *                    dashboard, where the same number is broken down.
 *
 * Suspended is the one place this differs from the dashboard's own panel. The
 * dashboard counts, for an office, the Mayor's Permits its refusal is holding
 * suspended; the permits register shows an office only the certificates it
 * issues, so those Mayor's Permits are not rows it can open. Counting the
 * dashboard's set over a list that cannot show it would be the 12-over-9 tile.
 *
 * Each figure is gated on the permission its destination is gated on, and a
 * tile the reader could not open is not drawn. Each loads on its own, so one
 * failing leaves a dash in its place and the other three standing.
 */

type HomeTab = 'queue' | 'permits'

/** Whole pesos: a tile is a glance, and the dashboard carries the centavos. */
const pesoWhole = new Intl.NumberFormat('en-PH', {
  style: 'currency',
  currency: 'PHP',
  maximumFractionDigits: 0,
})

const pesoExact = new Intl.NumberFormat('en-PH', {
  style: 'currency',
  currency: 'PHP',
  minimumFractionDigits: 2,
})

/** A figure, or why there is none. */
type Figure = { value: number | null; loading: boolean; failed: boolean }

function figureOf(state: { data: number | null; loading: boolean; error: unknown }): Figure {
  return { value: state.data, loading: state.loading, failed: Boolean(state.error) }
}

function Tile({
  to,
  label,
  figure,
  format = (n) => n.toLocaleString('en-PH'),
  describe,
  warn = false,
}: {
  to: string
  label: string
  figure: Figure
  format?: (n: number) => string
  /** The link's accessible name once the figure is in. */
  describe: (n: number) => string
  /** Draw a non-zero figure in the warning colour. The label still says it. */
  warn?: boolean
}) {
  const { value, loading, failed } = figure
  // A dash, never a zero, where the figure could not be had (AGENTS.md §6.4).
  const shown = value === null ? (loading ? '…' : '—') : format(value)
  const name =
    value !== null ? describe(value) : failed ? `${label}: could not load` : `${label}: loading`

  return (
    <Link
      to={to}
      aria-label={name}
      title={failed ? 'Could not load this figure' : undefined}
      className="group flex min-w-0 flex-col rounded-2xl bg-white px-4 py-3 shadow-card transition-shadow hover:shadow-raised focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
    >
      <span className="truncate text-[12px] font-semibold text-ink-muted">{label}</span>
      <span
        aria-hidden="true"
        className={`tnum mt-1 truncate text-[22px] font-bold sm:text-[24px] leading-tight ${
          warn && value ? 'text-s-orange-ink' : 'text-royal'
        } ${loading && value === null ? 'opacity-40' : ''}`}
      >
        {shown}
      </span>
    </Link>
  )
}

/* Literal class names, so Tailwind sees every one of them. */
const TILE_COLUMNS: Record<number, string> = {
  1: 'sm:grid-cols-1',
  2: 'sm:grid-cols-2',
  3: 'sm:grid-cols-3',
  4: 'sm:grid-cols-4',
}

export function OfficeHome() {
  const user = useAuth((s) => s.user)
  const location = useLocation()
  const [params] = useSearchParams()

  const permissions = user?.permissions ?? []
  const canQueue = permissions.includes('application.review')
  const canPermits = permissions.includes('permit.view_all')
  const canAnalytics = permissions.includes('analytics.view')

  /*
   * The office the Permits tab opens on, so the Suspended count asks the same
   * question: PermitsPage opens every reader on their own office's
   * certificates (BPLO on the Mayor's Permit), or on all of them when the
   * account's office issues none.
   */
  const ownPermit = permitCodeForDepartment(user?.department?.code)

  const portal = activePortal()
  const home = portalPath(portal, '/dashboard')
  const analyticsPath = portalPath(portal, '/analytics')

  const tabs: { value: HomeTab; label: string }[] = [
    ...(canQueue ? [{ value: 'queue' as const, label: 'Queue' }] : []),
    ...(canPermits ? [{ value: 'permits' as const, label: 'Permits' }] : []),
  ]
  const asked = params.get('tab')
  const tab: HomeTab = tabs.find((t) => t.value === asked)?.value ?? tabs[0]?.value ?? 'queue'

  const askedFocus = params.get('focus')
  const focus: QueueFocus | undefined =
    askedFocus === 'waiting' || askedFocus === 'overdue' ? askedFocus : undefined

  const waiting = useAsync(() => (canQueue ? countQueueFocus('waiting') : Promise.resolve(null)), [canQueue])
  const overdue = useAsync(() => (canQueue ? countQueueFocus('overdue') : Promise.resolve(null)), [canQueue])
  const suspended = useAsync(
    () =>
      canPermits
        ? permits
            .page({ status: 'suspended', permit_type: ownPermit ?? undefined, retired: 'hide', per_page: 1 })
            .then((res) => res.meta.total)
        : Promise.resolve(null),
    [canPermits, ownPermit],
  )
  const fees = useAsync(
    () => (canAnalytics ? analytics.feesMonth().then((res) => res.data.month_total) : Promise.resolve(null)),
    [canAnalytics],
  )

  const tiles = [
    canQueue && (
      <Tile
        key="waiting"
        to={`${home}?tab=queue&focus=waiting`}
        label="Waiting on you"
        figure={figureOf(waiting)}
        describe={(n) => `Waiting on you: ${n} ${n === 1 ? 'filing' : 'filings'}. Open in the queue.`}
      />
    ),
    canQueue && (
      <Tile
        key="overdue"
        to={`${home}?tab=queue&focus=overdue`}
        label="Overdue"
        figure={figureOf(overdue)}
        warn
        describe={(n) => `Overdue: ${n} ${n === 1 ? 'filing' : 'filings'} past the RA 11032 deadline. Open in the queue.`}
      />
    ),
    canPermits && (
      <Tile
        key="suspended"
        to={`${home}?tab=permits&status=suspended`}
        label="Suspended"
        figure={figureOf(suspended)}
        describe={(n) => `Suspended: ${n} ${n === 1 ? 'permit' : 'permits'}. Open in permits.`}
      />
    ),
    canAnalytics && (
      <Tile
        key="fees"
        to={analyticsPath}
        label="Fees this month"
        figure={figureOf(fees)}
        format={(n) => pesoWhole.format(n)}
        describe={(n) => `Fees this month: ${pesoExact.format(n)}. Open analytics.`}
      />
    ),
  ].filter(Boolean)

  return (
    <div>
      <PageTitle
        right={
          canAnalytics ? (
            <Link to={analyticsPath} className="pb-1 text-sm font-semibold text-royal hover:underline">
              View full analytics
            </Link>
          ) : undefined
        }
      >
        {user?.department?.name ?? 'Home'}
      </PageTitle>

      <section
        aria-label="At a glance"
        className={`mb-6 grid grid-cols-2 gap-3 ${TILE_COLUMNS[tiles.length] ?? 'sm:grid-cols-4'}`}
      >
        {tiles}
      </section>

      {/*
        Underlined tabs rather than pills: the Permits tab carries its own
        Table / Map pills, and two rows of the same control one above the other
        would read as one choice. Links, so the tab is in the URL and Back
        works; `aria-current` says which is open without relying on colour.
      */}
      <nav aria-label="Tables" className="mb-5 flex gap-6 border-b border-line">
        {tabs.map((t) => {
          const active = t.value === tab
          return (
            <Link
              key={t.value}
              to={`${home}?tab=${t.value}`}
              aria-current={active ? 'page' : undefined}
              // The open tab stays put: re-navigating would reset its list and
              // throw away a search the reader had typed.
              onClick={(e) => {
                if (active && !params.get('focus') && !params.get('status')) e.preventDefault()
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

      {/*
        Keyed on the location, so a tile pressed while its tab is already open
        still re-opens the list on what the tile names — both screens read
        their opening filter once, as initial state.
      */}
      {tab === 'queue' && canQueue ? (
        <QueuePage key={location.key} embedded focus={focus} />
      ) : (
        canPermits && <PermitsPage key={location.key} embedded />
      )}
    </div>
  )
}
