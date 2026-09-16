import { useEffect, useMemo, useState } from 'react'
import type { ComponentType, ReactNode, SVGProps } from 'react'
import { admin, applications } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { useAuth } from '../../stores/auth'
import { businessName, formatDate } from '../../lib/format'
import { applicationStatusMeta } from '../../lib/status'
import type {
  AdminBusiness,
  AdminUser,
  ApplicationListItem,
  BusinessStatus,
  PageMeta,
} from '../../lib/types'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { FilterPills, PageTitle, ProtoCard, StatusChip } from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { StatusBadge } from '../../components/ui/StatusBadge'
import { BuildingIcon, ClipboardIcon, UsersIcon } from '../../components/icons'

/*
 * Records — the super admin's read-only view of the register.
 *
 * Modelled on the LGU "License Management" screen: one grid, three tabs, and
 * nothing on it that changes anything. Applications, Businesses and Owners are
 * three different endpoints wearing the same four columns, which is why the
 * three tabs are one component rather than three pages — see RecordRow.
 *
 * ── Why a row opens nothing, and must not be "fixed" ───────────────────────
 *
 * The rows are deliberately inert: no link, no pointer cursor, no hover lift,
 * no chevron. A row that looks interactive and does nothing reads as a broken
 * screen, so nothing here dresses up as a control. The only things on this page
 * that look pressable — the tabs, the column headings, Refresh, the pager — all
 * do something.
 *
 * This is not a detail view that was forgotten. The super admin does not hold
 * `application.review`: the client had Messages, Track, Inspections and Other
 * Requirements taken off that account because oversight is not participation
 * (RbacSeeder, the `admin` role, at length). The consequence for this screen is
 * that there is nowhere for a row to go.
 *
 *   - /staff/queue/:id is gated on `application.review`, so a link there bounces
 *     this account to /staff/dashboard.
 *   - /applications/:id belongs to the CITIZEN site, and the session token is
 *     keyed by portal (lib/api.ts). A staff reader following that link arrives
 *     at the citizen sign-in page — for an account the API refuses on that
 *     portal with a 409.
 *   - There is no per-business and no per-owner route anywhere in the app.
 *
 * A read-only oversight detail view is the right answer and is a later slice. A
 * link into the reviewer's screen is not: it would either dead-end the admin or
 * hand the overseer the buttons of the overseen, which is the separation this
 * role exists to hold.
 *
 * ── Where search, sort and paging run ──────────────────────────────────────
 *
 *  - Search → server, on all three tabs. Every endpoint takes `q` and applies
 *    it inside its own scoping, so a search reaches the whole register rather
 *    than the twenty-five rows that happen to be loaded.
 *  - Paging → server, on all three tabs.
 *  - Sort → browser, over the page in hand, because not one of the three
 *    endpoints accepts an ordering: /applications and /admin/businesses order
 *    created_at DESC unconditionally, /admin/users orders by name. Do not add
 *    an `order` param here to "fix" that — it would be a query string the API
 *    silently ignores, which is a control that looks like it works. QueuePage
 *    reached the same conclusion for the same reason.
 *
 * Because the sort is local, the footer names its own reach whenever one is
 * active. "Sorted by Business" over 25 of 1,668 rows is a claim a reader would
 * otherwise take as covering the register.
 */

type IconType = ComponentType<SVGProps<SVGSVGElement> & { size?: number }>

/** Rows per request. Matches Owner Status; the register is thousands deep. */
const PAGE_SIZE = 25

/**
 * How long to wait before a keystroke becomes a request.
 *
 * Same 300ms the two sibling admin screens use. Without it "sari-sari" is nine
 * authenticated queries against the register, eight of them answers nobody will
 * read.
 */
const SEARCH_DEBOUNCE_MS = 300

type Tab = 'applications' | 'businesses' | 'owners'

/**
 * One row of the grid, whichever endpoint it came from.
 *
 * The three feeds answer three different shapes, and every behaviour below —
 * sorting, the count line, the empty states, the pager — is written once
 * against this rather than three times against the union. Four columns, because
 * all three registers answer the same four questions: what is it, whose is it,
 * what state is it in, and when.
 */
interface RecordRow {
  key: string
  primary: string
  secondary: string
  /*
   * The chip, and separately the words inside it.
   *
   * Two fields because the tabs do not share one status vocabulary: a filing's
   * state is written with StatusBadge (tone + icon + label, the way every other
   * screen writes it) while a business's and an account's use the tinted
   * StatusChip the sibling admin tables use. A comparator cannot sort a
   * ReactNode, so the sortable form is carried alongside the renderable one.
   */
  status: ReactNode
  statusLabel: string
  date: string | null
}

/**
 * The four business statuses in the tones Owner Status already gives them.
 *
 * Copied rather than imported: OwnersPage keeps its map private and this screen
 * has no business reaching into another page's internals. If the palette moves
 * there, move it here too — a register that greys a blacklisting is worse than
 * one that does not colour it at all.
 */
const BUSINESS_TONES: Record<BusinessStatus, ChipTone> = {
  active: 'tint-green',
  flagged: 'tint-yellow',
  suspended: 'tint-purple',
  blacklisted: 'tint-red',
}

function applicationRow(app: ApplicationListItem): RecordRow {
  const meta = applicationStatusMeta(app.status, app.status_label)
  return {
    key: `application-${app.id}`,
    primary: app.tracking_id,
    // `businessName`, not a dereference: Business soft-deletes and its filings
    // stay, so this is null on 139 rows of the live register and the helper says
    // what happened instead of blanking the cell.
    secondary: businessName(app.business),
    status: <StatusBadge tone={meta.tone} label={meta.label} icon={meta.icon} size="sm" />,
    statusLabel: meta.label,
    /*
     * When it was filed, which a draft never was. `submitted_at` is null there
     * and `formatDate` renders the dash a figure with genuinely no value gets;
     * substituting `created_at` would print the day someone opened a form and
     * call it a filing date.
     */
    date: app.submitted_at,
  }
}

function businessRow(business: AdminBusiness): RecordRow {
  return {
    key: `business-${business.id}`,
    primary: business.name,
    secondary: business.owner?.name ?? '—',
    status: (
      <StatusChip tone={BUSINESS_TONES[business.status] ?? 'tint-gray'}>
        {business.status_label}
      </StatusChip>
    ),
    statusLabel: business.status_label,
    date: business.created_at,
  }
}

function ownerRow(user: AdminUser): RecordRow {
  const name =
    [user.first_name, user.last_name].filter(Boolean).join(' ') + (user.suffix ? ` ${user.suffix}` : '')
  const label = user.is_active ? 'Active' : 'Inactive'
  return {
    key: `owner-${user.id}`,
    primary: name,
    secondary: user.email,
    status: <StatusChip tone={user.is_active ? 'tint-green' : 'tint-gray'}>{label}</StatusChip>,
    statusLabel: label,
    /*
     * Verification, not registration. `User` carries no created_at, and the
     * verification date is the one an administrator comes to this tab asking
     * about anyway: an owner who never confirmed their address is the account
     * that cannot be written to.
     */
    date: user.email_verified_at,
  }
}

interface TabConfig {
  value: Tab
  label: string
  /**
   * What this tab needs on top of reaching the screen at all.
   *
   * Kept even though the screen is now the super admin's alone.
   *
   * The rail and the route gate on `user.manage`, which only the admin holds,
   * so in practice every tab below is reachable by everyone who gets this far
   * and none of these checks fire. They stay because the three endpoints do NOT
   * agree with each other about who may call them: /applications is open to any
   * signed-in reader and scoped per office by ApplicationVisibility, while
   * /admin/businesses is `permission:owner.manage_status` and /admin/users is
   * `permission:user.manage`. The tab list is the only place that difference is
   * written down on this side.
   *
   * This was load-bearing until the gate changed. The screen was first gated on
   * `application.view_all` — the permission that names reading — and that turns
   * out to be held by BPLO and all five clearance offices, so six more rails
   * grew a console nobody had asked for. These per-tab checks are what made
   * that landing survivable rather than two 403s in an error state. If the gate
   * is ever widened again, they are already correct.
   *
   * Same shape as the `oic.assign` guard on Officer Assignment's Reassign
   * button: the rail decides who reaches the screen, the screen decides what is
   * on it.
   */
  permission?: string
  /** Column headings in row order: primary, secondary, status, date. */
  columns: [string, string, string, string]
  /** The search box's real label — and, said exactly, what `q` matches. */
  searchLabel: string
  searchPlaceholder: string
  /** Plural noun for the count line. */
  noun: string
  /** How the SERVER orders this feed. Named on screen when a sort is active. */
  serverOrder: string
  icon: IconType
  emptyTitle: string
  emptyDescription: string
}

const TABS: TabConfig[] = [
  {
    value: 'applications',
    label: 'Applications',
    columns: ['Tracking ID', 'Business', 'Status', 'Filed'],
    /*
     * Named exactly, because `q` on /applications matches the tracking ID and
     * the business name and nothing else. An owner's own name will not find
     * their filing here, and a label promising "search filings" would make that
     * look like missing data rather than the wrong box.
     */
    searchLabel: 'Search filings by tracking ID or business name',
    searchPlaceholder: 'Tracking ID or business…',
    noun: 'filings',
    serverOrder: 'newest first',
    icon: ClipboardIcon,
    emptyTitle: 'No filings in the register yet',
    emptyDescription: 'Filings appear here as owners submit them.',
  },
  {
    value: 'businesses',
    label: 'Businesses',
    permission: 'owner.manage_status',
    columns: ['Business', 'Owner', 'Status', 'Registered'],
    searchLabel: 'Search businesses by business or owner name',
    searchPlaceholder: 'Business or owner…',
    noun: 'businesses',
    serverOrder: 'newest first',
    icon: BuildingIcon,
    emptyTitle: 'No registered businesses yet',
    emptyDescription: 'Businesses appear here as owners register them.',
  },
  {
    value: 'owners',
    label: 'Owners',
    permission: 'user.manage',
    columns: ['Owner', 'Email', 'Account', 'Email verified'],
    searchLabel: 'Search owners by name or email',
    searchPlaceholder: 'Name or email…',
    noun: 'owners',
    serverOrder: 'by name',
    icon: UsersIcon,
    emptyTitle: 'No business owners yet',
    emptyDescription: 'Owners appear here as citizens register for an account.',
  },
]

interface RecordPage {
  rows: RecordRow[]
  meta: PageMeta
}

/**
 * One page of whichever register the tab in hand names.
 *
 * `/businesses` (BusinessController) is deliberately not among these. It is
 * hard-scoped to `owner_user_id`, so the super admin would be shown its own
 * businesses — none — under a heading claiming to be the city's roster. The
 * admin roster is `/admin/businesses`, which is what Owner Status reads.
 */
async function loadTab(tab: Tab, q: string, page: number): Promise<RecordPage> {
  const params = { q: q || undefined, page, per_page: PAGE_SIZE }

  if (tab === 'applications') {
    /*
     * `page()` and not `list()`: the wrapper named `list` throws the page meta
     * away and hard-codes the 200-row picker ceiling, so a screen built on it
     * cannot print a total or know how many pages it has.
     *
     * If a type filter is ever added here, the parameter is `type`. There is no
     * `application_type` on this endpoint — ApplicationController validates
     * status, type, q, per_page, page — and an unknown key is dropped in
     * silence, so the filter would appear to work and narrow nothing.
     */
    const { data, meta } = await applications.page(params)
    return { rows: data.map(applicationRow), meta }
  }

  if (tab === 'businesses') {
    const { data, meta } = await admin.businessesPage(params)
    return { rows: data.map(businessRow), meta }
  }

  /*
   * Owners are asked for by role, not by the absence of `staff`.
   *
   * `staff` is truthy-only on the server — `$request->boolean('staff')` — so
   * `staff=0` does not mean "citizens", it means no filter at all, and this tab
   * would quietly list the city's officers alongside its owners under a heading
   * that says Owners. `role=business_owner` is the real narrowing, and it works
   * on this endpoint: the EXCLUDED_ROLES list that hides owners applies only to
   * the `staff=true` branch and to the roles picker.
   */
  const { data, meta } = await admin.usersPage({ ...params, role: 'business_owner' })
  return { rows: data.map(ownerRow), meta }
}

/** Which of the four columns a sort is on. */
type SortKey = 'primary' | 'secondary' | 'status' | 'date'

interface Sort {
  key: SortKey
  dir: 'asc' | 'desc'
}

const SORT_KEYS: SortKey[] = ['primary', 'secondary', 'status', 'date']

function sortRows(rows: RecordRow[], sort: Sort): RecordRow[] {
  const { key } = sort
  const dir = sort.dir === 'asc' ? 1 : -1

  return [...rows].sort((a, b) => {
    if (key === 'date') {
      const at = a.date ? Date.parse(a.date) : NaN
      const bt = b.date ? Date.parse(b.date) : NaN
      /*
       * A missing date sorts last whichever way the column points. A draft was
       * not filed at the beginning of time, and floating it to the top of an
       * ascending sort would state that it was the oldest filing on record.
       */
      if (Number.isNaN(at) || Number.isNaN(bt)) {
        if (Number.isNaN(at) && Number.isNaN(bt)) return 0
        return Number.isNaN(at) ? 1 : -1
      }
      return dir * (at - bt)
    }
    const av = key === 'status' ? a.statusLabel : a[key]
    const bv = key === 'status' ? b.statusLabel : b[key]
    return dir * av.localeCompare(bv)
  })
}

export function RecordsPage() {
  const permissions = useAuth((s) => s.user?.permissions)

  const [tab, setTab] = useState<Tab>('applications')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [sort, setSort] = useState<Sort | null>(null)
  const [page, setPage] = useState(1)

  // Applications carries no permission of its own, so this can never come back
  // empty and the opening tab is always one of them.
  const tabs = useMemo(
    () => TABS.filter((t) => !t.permission || permissions?.includes(t.permission)),
    [permissions],
  )
  const config = tabs.find((t) => t.value === tab) ?? tabs[0]

  // Keyed on `config.value` rather than `tab`, so the request and the column
  // headings can never disagree about which register is on screen.
  const feed = config.value
  const { data, loading, error, reload } = useAsync(() => loadTab(feed, query, page), [feed, query, page])

  // Let the admin finish typing before asking the server.
  useEffect(() => {
    const id = window.setTimeout(() => {
      setQuery(search.trim())
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(id)
  }, [search])

  /*
   * Everything a tab holds is about that tab. Carrying the search across would
   * be worse than useless — the boxes match different columns, so "BIZ-2026" is
   * a tracking ID on one tab and matches no business name on the next — and a
   * page number or a sort survives even less well between registers of
   * different depths.
   */
  function selectTab(next: Tab) {
    setTab(next)
    setSearch('')
    setQuery('')
    setSort(null)
    setPage(1)
  }

  function toggleSort(key: SortKey) {
    setSort((prev) => {
      if (!prev || prev.key !== key) return { key, dir: 'asc' }
      if (prev.dir === 'asc') return { key, dir: 'desc' }
      /*
       * A third press clears the sort. Without it, a reader who sorted once
       * could never get back to the order the server actually counts in, and
       * the only way out would be to leave the screen.
       */
      return null
    })
  }

  const rows = useMemo(() => {
    const loaded = data?.rows ?? []
    return sort ? sortRows(loaded, sort) : loaded
  }, [data, sort])

  const total = data?.meta.total ?? 0
  const lastPage = data?.meta.last_page ?? 1
  const sortedColumn = sort ? config.columns[SORT_KEYS.indexOf(sort.key)] : null

  return (
    <div>
      <PageTitle
        right={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-2 pb-1">
            {/*
              A placeholder is not an accessible name — it disappears on the
              first keystroke — so the field carries a real label, hidden only
              because the search box is obvious to look at. The label changes
              with the tab because what `q` matches changes with the tab.
            */}
            <label htmlFor="records-search" className="sr-only">
              {config.searchLabel}
            </label>
            <input
              id="records-search"
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={config.searchPlaceholder}
              className="w-60 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
            <button
              type="button"
              onClick={reload}
              /*
               * aria-disabled, never the native attribute (§6.2): a disabled
               * control leaves the tab order, so a keyboard reader loses
               * Refresh entirely while the thing it would refresh is loading.
               * The handler needs no guard — `reload` bumps a nonce and
               * useAsync cancels the in-flight request.
               */
              aria-disabled={loading || undefined}
              className="rounded-full border border-line bg-white px-4 py-2 text-sm font-semibold text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
            >
              Refresh
            </button>
          </span>
        }
      >
        Records
      </PageTitle>

      <div className="mb-5">
        <FilterPills options={tabs} value={config.value} onChange={selectTab} />
      </div>

      {loading ? (
        <SkeletonList rows={8} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={config.icon}
          title={query ? `No ${config.noun} match your search` : config.emptyTitle}
          description={query ? 'Try another spelling.' : config.emptyDescription}
        />
      ) : (
        <ProtoCard className="overflow-hidden rounded-xl">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[44rem] text-left text-sm">
              <thead>
                <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  {config.columns.map((label, i) => {
                    const key = SORT_KEYS[i]
                    const active = sort?.key === key
                    return (
                      <th
                        key={label}
                        scope="col"
                        // The state of the sort belongs on the column, not in the
                        // arrow: a screen reader announces `aria-sort` and cannot
                        // read a glyph.
                        aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
                        className="px-5 py-3"
                      >
                        <button
                          type="button"
                          onClick={() => toggleSort(key)}
                          className="inline-flex items-center gap-1 rounded uppercase tracking-wider hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
                        >
                          {label}
                          <span aria-hidden="true" className={active ? 'text-royal' : 'opacity-40'}>
                            {active ? (sort.dir === 'asc' ? '▲' : '▼') : '⇅'}
                          </span>
                        </button>
                      </th>
                    )
                  })}
                </tr>
              </thead>
              <tbody>
                {/*
                  A plain row, on purpose. No onClick, no hover state, no cursor
                  change and no chevron — see the note at the top of this file
                  for why there is nowhere for a row to go, and why wiring one up
                  is not the fix it looks like.
                */}
                {rows.map((row) => (
                  <tr key={row.key} className="border-t border-line">
                    <td className="px-5 py-3.5 font-bold text-ink">{row.primary}</td>
                    <td className="px-5 py-3.5 text-ink-secondary">{row.secondary}</td>
                    <td className="px-5 py-3.5">{row.status}</td>
                    <td className="px-5 py-3.5 text-ink-secondary">{formatDate(row.date)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="flex items-center justify-between gap-4 border-t border-line px-5 py-3.5">
            <div>
              <p role="status" aria-live="polite" className="text-sm text-ink-muted">
                Showing {rows.length.toLocaleString()} of {total.toLocaleString()} {config.noun}
                {query && ' matching your search'}
              </p>
              {/*
                What the sort actually covers, said where the sort is used. It
                reorders the rows in hand and cannot reach the rest of the
                register, so a reader looking at 25 of 1,668 rows needs to be
                told that the top row is not the whole register's first.
              */}
              {sortedColumn && (
                <p className="mt-1 text-xs text-ink-muted">
                  Sorted by {sortedColumn} within this page. The register itself is ordered{' '}
                  {config.serverOrder}.
                </p>
              )}
            </div>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                aria-label="Previous page"
                /*
                 * aria-disabled, never the native attribute (§6.2): a disabled
                 * control leaves the tab order, so a keyboard reader loses the
                 * pager entirely at either end of the register rather than
                 * being told it has reached one. The handlers need no guard —
                 * they already clamp to page 1 and to lastPage.
                 */
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                aria-disabled={page <= 1 || loading || undefined}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
              >
                ‹
              </button>
              <span className="text-xs text-ink-muted">
                Page {page.toLocaleString()} of {lastPage.toLocaleString()}
              </span>
              <button
                type="button"
                aria-label="Next page"
                onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                aria-disabled={page >= lastPage || loading || undefined}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
              >
                ›
              </button>
            </div>
          </div>
        </ProtoCard>
      )}
    </div>
  )
}
