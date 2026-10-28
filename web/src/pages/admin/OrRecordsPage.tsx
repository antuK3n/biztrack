import { useEffect, useState } from 'react'
import { ClipboardIcon } from '../../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { PageTitle, ProtoCard } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { formatCalendarDate, formatMoney } from '../../lib/format'
import { orRecords, paymentProofs } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { OrRecord } from '../../lib/types'

/*
 * OR Records (thesis panel revisions, matrix item 5): "The OR number, date
 * paid, amount, and linked application are stored, and the records can be
 * searched and viewed."
 *
 * One row per payment carrying an Official Receipt — what BPLO's Mark as paid
 * records at the counter. BPLO and the super admin only: the route in App.tsx,
 * the rail entry in nav.ts and the endpoint all ask for
 * `application.view_any_office`, so none of the three can admit someone the
 * others refuse.
 *
 * The table language is Audit Logs' and Permits': a white ProtoCard, uppercase
 * muted headers, a search box beside the title, a pager underneath. Search and
 * paging run on the server, newest date paid first, so page 2 continues the
 * order rather than restarting it.
 */

const PAGE_SIZE = 25

/** Let the officer finish typing before asking the server, as Permits does. */
const SEARCH_DEBOUNCE_MS = 300

const SEARCH_LABEL = 'Search OR no., tracking ID or business'

export function OrRecordsPage() {
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [viewing, setViewing] = useState<number | null>(null)
  const [viewError, setViewError] = useState<string | null>(null)

  const { data, loading, error, reload } = useAsync(
    () => orRecords.page({ q: query || undefined, page, per_page: PAGE_SIZE }),
    [query, page],
  )

  useEffect(() => {
    const id = window.setTimeout(() => {
      setQuery(search.trim())
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(id)
  }, [search])

  /*
   * The photo of the receipt, opened in a tab. The tab is opened inside the
   * click, before any await, or the popup blocker takes it silently — the rule
   * PaymentsPage works to.
   */
  async function viewProof(row: OrRecord) {
    // The press is refused here, since the button is aria-disabled and not `disabled`.
    if (!row.proof || viewing !== null) return
    const tab = window.open('', '_blank')
    setViewing(row.id)
    setViewError(null)
    try {
      await paymentProofs.view(row.proof.id, tab)
    } catch (err) {
      tab?.close()
      setViewError(toApiError(err).message)
    } finally {
      setViewing(null)
    }
  }

  const rows = data?.data ?? []
  const lastPage = data?.meta.last_page ?? 1

  return (
    <div>
      <PageTitle
        right={
          <span className="pb-1">
            {/* The placeholder is the field's whole wording, so it is its name too. */}
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              aria-label={SEARCH_LABEL}
              placeholder={SEARCH_LABEL}
              className="w-80 max-w-full rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
          </span>
        }
      >
        OR Records
      </PageTitle>

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
        /*
         * `query`, not `search`: the list on screen is the answer to what was
         * sent. A search that finds nothing is not a register with no ORs.
         */
        <EmptyState
          icon={ClipboardIcon}
          title={query ? 'No OR records match your search.' : 'No OR records yet.'}
        />
      ) : (
        <ProtoCard className="overflow-hidden rounded-xl">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[56rem] text-left text-sm">
              <thead>
                <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  <th scope="col" className="px-5 py-3">OR No.</th>
                  <th scope="col" className="px-5 py-3">Date paid</th>
                  <th scope="col" className="px-5 py-3 text-right">Amount</th>
                  <th scope="col" className="px-5 py-3">Tracking ID</th>
                  <th scope="col" className="px-5 py-3">Business</th>
                  <th scope="col" className="px-5 py-3">Recorded by</th>
                  <th scope="col" className="px-5 py-3 text-right">Proof</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-t border-line align-top">
                    <td className="tnum whitespace-nowrap px-5 py-3.5 font-semibold text-ink">{row.or_number}</td>
                    <td className="whitespace-nowrap px-5 py-3.5 text-ink-secondary">{formatCalendarDate(row.or_date)}</td>
                    <td className="tnum whitespace-nowrap px-5 py-3.5 text-right text-ink">{formatMoney(row.amount)}</td>
                    <td className="tnum whitespace-nowrap px-5 py-3.5 text-ink-secondary">
                      {row.application?.tracking_id ?? '—'}
                    </td>
                    <td className="px-5 py-3.5 font-medium text-ink">{row.business?.name ?? '—'}</td>
                    <td className="px-5 py-3.5 text-ink-secondary">{row.recorded_by?.name ?? '—'}</td>
                    <td className="px-5 py-3.5 text-right">
                      {row.proof ? (
                        <button
                          type="button"
                          onClick={() => void viewProof(row)}
                          aria-disabled={viewing === row.id || undefined}
                          // A View on every row needs a name of its own (AGENTS.md §6.2).
                          aria-label={`View OR No. ${row.or_number}`}
                          className="rounded-md px-2 py-1 text-sm font-semibold text-royal underline underline-offset-2 hover:bg-royal-tint aria-disabled:opacity-60"
                        >
                          View
                        </button>
                      ) : (
                        <span className="text-sm text-ink-muted">—</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="flex items-center justify-between border-t border-line px-5 py-3.5">
            <p className="text-sm text-ink-muted">
              Page {page} of {lastPage}
            </p>
            <div className="flex items-center gap-1.5">
              {/* aria-disabled, never `disabled`: the pager stays in the tab order at either end. */}
              <button
                type="button"
                aria-label="Previous page"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                aria-disabled={page <= 1 || undefined}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:opacity-40"
              >
                ‹
              </button>
              <span className="flex h-7 min-w-7 items-center justify-center rounded-md bg-royal-deep px-1.5 text-xs font-semibold text-white">
                {page}
              </span>
              <button
                type="button"
                aria-label="Next page"
                onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                aria-disabled={page >= lastPage || undefined}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:opacity-40"
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
