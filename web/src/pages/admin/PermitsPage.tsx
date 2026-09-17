import { useEffect, useMemo, useState } from 'react'
import { permits } from '../../lib/resources'
import { toApiError } from '../../lib/api'
import { useAsync } from '../../lib/useAsync'
import { businessName, formatDate } from '../../lib/format'
import type { Permit } from '../../lib/types'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { FilterPills, PageTitle, ProtoCard, StatusChip } from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { FileTextIcon } from '../../components/icons'

/*
 * Permits — every certificate the City has issued, as one table.
 *
 * Issue #103: "A page listing ALL approved permits as a table, with only the
 * necessary columns, allowing the admin to view a permit, revoke it, and the
 * other actions in the use case diagram."
 *
 * Two of those three are here. The third is not, and its absence is decided
 * rather than unfinished — see "Revoke is not on this screen" below.
 *
 * ── Five columns, and what was deliberately left off ───────────────────────
 *
 * The register holds 5,475 issued permits, 2,182 of them active. At that depth
 * a column is not free: every one added is one more thing the eye has to skip
 * on every row, and a table wide enough to scroll sideways hides the column
 * that decides the answer. "Only the necessary columns" is the instruction, so:
 *
 *   Permit No.    the identifier. It is what an administrator is handed over
 *                 the counter or down the phone, and the only value on the row
 *                 that is unique.
 *   Business      whose it is. A permit number alone is unreadable to a person.
 *   Type          which of the six. A business holds several at once, so the
 *                 number and the name together still do not say which document
 *                 this row is.
 *   Valid until   whether it is still good. The one date that decides anything.
 *   Status        active / expired / superseded — because the date does not
 *                 settle it: a superseded permit is inside its own term and is
 *                 not the one in force.
 *
 * Left off on purpose:
 *
 *   Valid from      the pair reads as one fact and only the end of it is ever
 *                   scanned. It is on the certificate, one click away.
 *   Tracking ID     names the FILING, not the permit. Records already lists
 *                   filings by tracking ID; repeating it here invites the two
 *                   identifiers to be used interchangeably, which AGENTS.md §11
 *                   is explicit that they are not. It is still SEARCHABLE — an
 *                   administrator holding one can find the permit it produced —
 *                   it just does not earn a column.
 *   Owner           the business name answers "whose" for the purpose of this
 *                   screen, and the owner is on the certificate.
 *   Days to expiry  the expiry date said twice. Ranking permits by how close
 *                   they are to lapsing is Renewal Risk's whole screen, and it
 *                   weights more than the date.
 *   Issued on       not in PermitResource, and not a question asked of this
 *                   table. `valid_from` is the operative start date anyway.
 *   Verify URL      a public link for a third party checking a certificate, not
 *                   an internal reader who is already looking at the register.
 *
 * ── Where search, sort and paging run ──────────────────────────────────────
 *
 * Same split RecordsPage settled on, for the same reasons:
 *
 *  - Search → server (`q`), so it reaches all 5,475 rows rather than the 25 in
 *    hand. It matches permit number, business name and tracking ID; the field's
 *    label says exactly that, because a search box that quietly matches one
 *    column makes a correct query look like missing data.
 *  - Status → server (`status`), so the count line under the table is the
 *    count of what was asked for and not of the page.
 *  - Paging → server.
 *  - Sort → browser, over the page in hand, because /permits accepts no
 *    ordering: PermitController::index is `issued_at DESC, id DESC`,
 *    unconditionally. Do NOT add an `order` param to "fix" this — an unknown
 *    key is dropped in silence, which is a control that looks like it works.
 *    The footer therefore names the sort's reach whenever one is active.
 *
 * ── Revoke is not on this screen ───────────────────────────────────────────
 *
 * The issue asks for it. It is not built, and adding it here without the
 * decisions below would be worse than leaving the gap:
 *
 *  - PermitStatus::Revoked and ::Suspended exist as enum cases, and
 *    `permits.revoked_at` / `permits.revoked_reason` exist as columns — but
 *    NOTHING in the codebase writes any of them, and the live register holds
 *    zero rows in either state (active 2,182 / expired 3,162 / superseded 131).
 *    So revocation is not a status that is merely unexposed; it is a terminal
 *    state nothing has ever entered, with no writer, no audit entry, no
 *    notification and no rule about the PDF already in the owner's hands.
 *  - A revoked permit means a business is trading unlawfully. That is an
 *    enforcement act with a real-world consequence, not a row update, and it
 *    needs the City to say who may do it and on what grounds.
 *  - The issue cites "the other actions in the use case diagram" and that
 *    diagram is not in this repository. Guessing at the actions is how the
 *    wrong ones get shipped.
 *
 * The open questions are written up for the client rather than answered here;
 * when they come back, the control belongs in this row's action group beside
 * View, in #bd0000, behind a confirmation that takes a reason.
 */

/** Rows per request. Matches Records and Owner Status. */
const PAGE_SIZE = 25

/** How long to wait before a keystroke becomes a request. Same as Records. */
const SEARCH_DEBOUNCE_MS = 300

/**
 * The status filter, and why it is not simply the PermitStatus enum.
 *
 * Only the three states the register actually holds are offered. A pill for
 * Revoked would return an empty table on every press — and worse, it would
 * advertise a capability this system does not have, which is the same claim
 * the missing Revoke button is careful not to make. Add the pill in the same
 * change that adds the writer, not before.
 */
type StatusFilter = '' | 'active' | 'expired' | 'superseded'

const STATUS_FILTERS: { value: StatusFilter; label: string }[] = [
  { value: '', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'expired', label: 'Expired' },
  { value: 'superseded', label: 'Superseded' },
]

/**
 * Status tones, in the tints the other admin tables already use.
 *
 * Superseded is grey rather than red: it is an ordinary, correct outcome — the
 * business renewed early and this certificate was replaced — and colouring it
 * as a problem would break "Red Means Stop" (DESIGN.md). Red is held for
 * `revoked`, which is the one enforcement state in this map, and is listed
 * even though no row carries it so that the day a writer exists the table does
 * not silently render it in the neutral grey of the fallback.
 */
const STATUS_TONES: Record<string, ChipTone> = {
  active: 'tint-green',
  expired: 'tint-yellow',
  superseded: 'tint-gray',
  suspended: 'tint-purple',
  revoked: 'tint-red',
}

/** Which column a sort is on. The actions column is not one of them. */
type SortKey = 'number' | 'business' | 'type' | 'validUntil' | 'status'

interface Sort {
  key: SortKey
  dir: 'asc' | 'desc'
}

interface Column {
  key: SortKey
  label: string
}

const COLUMNS: Column[] = [
  { key: 'number', label: 'Permit No.' },
  { key: 'business', label: 'Business' },
  { key: 'type', label: 'Type' },
  { key: 'validUntil', label: 'Valid until' },
  { key: 'status', label: 'Status' },
]

/**
 * The sortable text behind each cell.
 *
 * `business` goes through `businessName`, not `permit.business.name`: the
 * `Permit` type claims that relation is non-nullable and it is not. Business
 * soft-deletes and its permits stay on the register, so the payload answers
 * null on an orphaned row (AGENTS.md §11) — the helper prints "Business removed
 * from register" where a dereference would throw.
 */
function cellText(permit: Permit, key: SortKey): string {
  switch (key) {
    case 'number':
      return permit.permit_number
    case 'business':
      return businessName(permit.business)
    case 'type':
      return permit.permit_type?.name ?? '—'
    case 'validUntil':
      return permit.valid_until ?? ''
    case 'status':
      return permit.status_label
  }
}

function sortPermits(rows: Permit[], sort: Sort): Permit[] {
  const dir = sort.dir === 'asc' ? 1 : -1

  return [...rows].sort((a, b) => {
    if (sort.key === 'validUntil') {
      const at = a.valid_until ? Date.parse(a.valid_until) : NaN
      const bt = b.valid_until ? Date.parse(b.valid_until) : NaN
      /*
       * A permit with no recorded expiry sorts last whichever way the column
       * points. Floating it to the top of an ascending sort would state it was
       * the first to lapse, which is the opposite of what a blank date means.
       */
      if (Number.isNaN(at) || Number.isNaN(bt)) {
        if (Number.isNaN(at) && Number.isNaN(bt)) return 0
        return Number.isNaN(at) ? 1 : -1
      }
      return dir * (at - bt)
    }
    return dir * cellText(a, sort.key).localeCompare(cellText(b, sort.key))
  })
}

export function PermitsPage() {
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState<StatusFilter>('')
  const [sort, setSort] = useState<Sort | null>(null)
  const [page, setPage] = useState(1)

  /*
   * Which row's certificate is being fetched, and what went wrong if it did.
   *
   * Keyed by permit id rather than a bare boolean: a shared flag would grey
   * every View button on the page, telling 24 readers their click had stalled
   * when it was somebody else's row that was loading.
   */
  const [viewing, setViewing] = useState<number | null>(null)
  const [viewError, setViewError] = useState<string | null>(null)

  const { data, loading, error, reload } = useAsync(
    () => permits.page({ q: query || undefined, status: status || undefined, page, per_page: PAGE_SIZE }),
    [query, status, page],
  )

  // Let the admin finish typing before asking the server.
  useEffect(() => {
    const id = window.setTimeout(() => {
      setQuery(search.trim())
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(id)
  }, [search])

  function selectStatus(next: StatusFilter) {
    setStatus(next)
    // Page 9 of the whole register is not page 9 of the active permits, and
    // landing past the end of the narrowed set shows an empty table that reads
    // as "no active permits".
    setPage(1)
  }

  function toggleSort(key: SortKey) {
    setSort((prev) => {
      if (!prev || prev.key !== key) return { key, dir: 'asc' }
      if (prev.dir === 'asc') return { key, dir: 'desc' }
      // A third press clears it, so a reader can get back to the order the
      // server actually counts in without leaving the screen.
      return null
    })
  }

  /**
   * View — open the certificate the City issued, not a screen about it.
   *
   * `/permits/{id}/pdf` already renders the full face (owner, address, line of
   * business, signature block, QR). A second detail screen built from the list
   * payload would show less and look just as authoritative, and would drift
   * from the paper the moment either renderer changed.
   */
  async function view(permit: Permit) {
    /*
     * The tab is opened inside the click, before any await. By the time the
     * authenticated fetch resolves the user gesture has expired and the popup
     * blocker takes the tab silently — the rule PaymentsPage works to.
     */
    const tab = window.open('', '_blank')
    setViewing(permit.id)
    setViewError(null)
    try {
      await permits.viewPdf(permit.id, tab)
    } catch (err) {
      // Close the blank tab, or the failure leaves them staring at an empty
      // window with the message on the page behind it.
      tab?.close()
      setViewError(toApiError(err).message)
    } finally {
      setViewing(null)
    }
  }

  const rows = useMemo(() => {
    const loaded = data?.data ?? []
    return sort ? sortPermits(loaded, sort) : loaded
  }, [data, sort])

  const total = data?.meta.total ?? 0
  const lastPage = data?.meta.last_page ?? 1
  const sortedColumn = sort ? COLUMNS.find((c) => c.key === sort.key)?.label : null
  const filterLabel = STATUS_FILTERS.find((f) => f.value === status)?.label ?? 'All'

  return (
    <div>
      <PageTitle
        right={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-2 pb-1">
            {/*
              A placeholder is not an accessible name — it disappears on the
              first keystroke — so the field carries a real label, hidden only
              because a search box is obvious to look at. The label names all
              three things `q` matches, because that is the whole of what it
              matches.
            */}
            <label htmlFor="permits-search" className="sr-only">
              Search permits by permit number, business name or tracking ID
            </label>
            <input
              id="permits-search"
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Permit no., business or tracking ID…"
              className="w-64 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
            <button
              type="button"
              onClick={reload}
              // aria-disabled, never the native attribute (AGENTS.md §6.2): a
              // disabled control leaves the tab order, so a keyboard reader
              // loses Refresh while the thing it refreshes is loading. The
              // handler needs no guard — `reload` bumps a nonce and useAsync
              // cancels the in-flight request.
              aria-disabled={loading || undefined}
              className="rounded-full border border-line bg-white px-4 py-2 text-sm font-semibold text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
            >
              Refresh
            </button>
          </span>
        }
      >
        Permits
      </PageTitle>

      <div className="mb-5">
        <FilterPills options={STATUS_FILTERS} value={status} onChange={selectStatus} />
      </div>

      {/*
        The failure of one row's View, said once above the table rather than
        inside the row. A 403 here is almost always the office boundary
        answering — PermitController::authorizeView refuses a certificate
        issued by another office — and that sentence is worth reading in full,
        which it cannot be in a table cell.
      */}
      {viewError && (
        <p role="alert" className="mb-4 rounded-lg border border-s-red/30 bg-s-red-tint px-4 py-3 text-sm text-s-red">
          {viewError}
        </p>
      )}

      {loading ? (
        <SkeletonList rows={8} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={FileTextIcon}
          title={query ? 'No permits match your search' : `No ${filterLabel.toLowerCase()} permits`}
          description={
            query
              ? 'Search matches the permit number, the business name and the tracking ID. Try another spelling.'
              : 'Permits appear here as offices approve filings and issue certificates.'
          }
        />
      ) : (
        <ProtoCard className="overflow-hidden rounded-xl">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[52rem] text-left text-sm">
              <thead>
                <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  {COLUMNS.map((column) => {
                    const active = sort?.key === column.key
                    return (
                      <th
                        key={column.key}
                        scope="col"
                        // The sort state belongs on the column: a screen reader
                        // announces `aria-sort` and cannot read a glyph.
                        aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
                        className="px-5 py-3"
                      >
                        <button
                          type="button"
                          onClick={() => toggleSort(column.key)}
                          className="inline-flex items-center gap-1 rounded uppercase tracking-wider hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
                        >
                          {column.label}
                          <span aria-hidden="true" className={active ? 'text-royal' : 'opacity-40'}>
                            {active ? (sort.dir === 'asc' ? '▲' : '▼') : '⇅'}
                          </span>
                        </button>
                      </th>
                    )
                  })}
                  {/*
                    The actions column has no heading text a reader needs, but
                    it cannot be an empty `<th>`: a column header with no
                    accessible name is announced as blank. The word is there and
                    hidden.
                  */}
                  <th scope="col" className="px-5 py-3 text-right">
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((permit) => (
                  <tr key={permit.id} className="border-t border-line">
                    <td className="px-5 py-3.5 font-bold text-ink">{permit.permit_number}</td>
                    <td className="px-5 py-3.5 text-ink-secondary">{businessName(permit.business)}</td>
                    <td className="px-5 py-3.5 text-ink-secondary">{permit.permit_type?.name ?? '—'}</td>
                    <td className="px-5 py-3.5 text-ink-secondary">{formatDate(permit.valid_until)}</td>
                    <td className="px-5 py-3.5">
                      {/*
                        The chip is tinted AND worded — "Never Color Alone"
                        (DESIGN.md). The label comes from the server so the
                        browser never has to name a status it has not been
                        taught, which is how a `revoked` row would otherwise
                        render as a blank pill.
                      */}
                      <StatusChip tone={STATUS_TONES[permit.status] ?? 'tint-gray'}>
                        {permit.status_label}
                      </StatusChip>
                    </td>
                    <td className="px-5 py-3.5 text-right">
                      <button
                        type="button"
                        onClick={() => view(permit)}
                        /*
                         * Twenty-five buttons reading "View" are twenty-five
                         * identical stops for a screen reader (AGENTS.md §6.2),
                         * so the permit number goes in the accessible name and
                         * the visible label stays one word.
                         */
                        aria-label={`View certificate ${permit.permit_number}`}
                        aria-disabled={viewing === permit.id || undefined}
                        className="rounded-full border border-royal px-4 py-1.5 text-xs font-semibold text-royal hover:bg-royal hover:text-white aria-disabled:cursor-wait aria-disabled:opacity-50"
                      >
                        {viewing === permit.id ? 'Opening…' : 'View'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="flex items-center justify-between gap-4 border-t border-line px-5 py-3.5">
            <div>
              {/*
                Both numbers named (AGENTS.md §6.4). "Showing 25" says nothing;
                "25 of 2,182 active permits" says what the pager is moving
                through.
              */}
              <p role="status" aria-live="polite" className="text-sm text-ink-muted">
                Showing {rows.length.toLocaleString()} of {total.toLocaleString()}{' '}
                {status ? `${filterLabel.toLowerCase()} permits` : 'issued permits'}
                {query && ' matching your search'}
              </p>
              {sortedColumn && (
                <p className="mt-1 text-xs text-ink-muted">
                  Sorted by {sortedColumn} within this page. The register itself is ordered by issue
                  date, newest first.
                </p>
              )}
            </div>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                aria-label="Previous page"
                // aria-disabled, never the native attribute: the pager must
                // stay in the tab order at either end of the register so a
                // keyboard reader is told they have reached one rather than
                // losing the control.
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
