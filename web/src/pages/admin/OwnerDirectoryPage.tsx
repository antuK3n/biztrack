import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { BuildingIcon, ChevronRightIcon } from '../../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { FilterPills, PageTitle, ProtoCard, StatusChip } from '../../components/ui/Proto'
import { initialsOf } from '../../lib/format'
import { admin } from '../../lib/resources'
import { BUSINESS_STATUS } from '../../lib/status'
import type { OwnerDirectoryRow } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'
import { ownerProfilePath } from './ownerProfilePath'

/*
 * ── Business Owners: the people behind the businesses ─────────────────────
 *
 * [Client, 20 October 2026: "gagawa uli ng another button sa pinaka left gilid
 * para sa mga owner, tas don pwede i click ang mga owner then once click
 * makikita na mga details nya".] One row per owner; pressing a row opens
 * their profile (OwnerProfilePage).
 *
 * Every office has it, and each sees only its own caseload — the server
 * narrows the list (OwnerProfileController), so a clearance office never
 * browses the city's whole register of owner accounts.
 *
 * The row is clickable as a whole for a mouse, and the owner's name is a real
 * link, so a keyboard and a screen reader reach the same place by the usual
 * route, and a middle-click opens a new tab.
 */

type OwnerFilter = 'all' | 'active' | 'blacklisted'

const FILTERS: { value: OwnerFilter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'blacklisted', label: 'Blacklisted' },
]

const PAGE_SIZE = 25

export function OwnerDirectoryPage() {
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [filter, setFilter] = useState<OwnerFilter>('all')
  const [page, setPage] = useState(1)

  const { data, loading, error, reload } = useAsync(
    () =>
      admin.ownerDirectory({
        q: query || undefined,
        status: filter === 'all' ? undefined : filter,
        page,
        per_page: PAGE_SIZE,
      }),
    [query, filter, page],
  )

  // Let the reader finish typing before asking the server.
  useEffect(() => {
    const id = window.setTimeout(() => {
      setQuery(search.trim())
      setPage(1)
    }, 300)
    return () => window.clearTimeout(id)
  }, [search])

  const rows = data?.data ?? []
  const total = data?.meta.total ?? 0
  const lastPage = data?.meta.last_page ?? 1
  // The owner's own email and mobile are BPLO's and the super admin's.
  const showsContact = Boolean((data?.meta as { shows_contact?: boolean } | undefined)?.shows_contact)

  return (
    <div>
      <PageTitle
        right={
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Name, email, business or BAN"
            aria-label="Search owners"
            className="mb-1 w-72 max-w-full rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
          />
        }
      >
        Business Owners
      </PageTitle>

      <div className="mb-5">
        <FilterPills
          options={FILTERS}
          value={filter}
          onChange={(next) => {
            setFilter(next)
            setPage(1)
          }}
        />
      </div>

      {loading && !data ? (
        <SkeletonList rows={7} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={BuildingIcon}
          title={query ? 'No owners match your search' : 'No business owners here yet'}
          description={
            query
              ? 'Try a name, an email address, a mobile number, a business name or a Business Account No.'
              : 'An owner appears here once one of their filings reaches your office.'
          }
        />
      ) : (
        <ProtoCard className="overflow-hidden rounded-xl">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[52rem] text-left text-sm">
              <thead>
                <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  <th scope="col" className="px-5 py-3">
                    Owner
                  </th>
                  {showsContact && (
                    <th scope="col" className="px-5 py-3">
                      Mobile
                    </th>
                  )}
                  <th scope="col" className="px-5 py-3">
                    Businesses
                  </th>
                  <th scope="col" className="px-5 py-3 text-right">
                    Active permits
                  </th>
                  <th scope="col" className="px-5 py-3">
                    Status
                  </th>
                  <th scope="col" className="w-10 px-3 py-3">
                    <span className="sr-only">Open</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((owner) => (
                  <OwnerRow
                    key={owner.id}
                    owner={owner}
                    showsContact={showsContact}
                    onOpen={() => navigate(ownerProfilePath(owner.id))}
                  />
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex flex-wrap items-center justify-between gap-4 border-t border-line px-5 py-3.5">
            <p className="text-sm text-ink-muted">
              Showing {rows.length.toLocaleString()} of {total.toLocaleString()} owners
              {query && ' matching your search'}
            </p>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                aria-label="Previous page"
                onClick={() => page > 1 && setPage(page - 1)}
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
                onClick={() => page < lastPage && setPage(page + 1)}
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

/** Two business names, then a count — the row stays one line tall. */
const NAMES_SHOWN = 2

function OwnerRow({
  owner,
  showsContact,
  onOpen,
}: {
  owner: OwnerDirectoryRow
  showsContact: boolean
  onOpen: () => void
}) {
  const shown = owner.businesses.slice(0, NAMES_SHOWN)
  const more = owner.businesses.length - shown.length

  return (
    <tr
      onClick={(e) => {
        // A press on the link already navigates; this is for the rest of the row.
        if ((e.target as HTMLElement).closest('a')) return
        onOpen()
      }}
      className="cursor-pointer border-t border-line align-middle transition-colors hover:bg-royal-tint/40"
    >
      <td className="px-5 py-3.5">
        <div className="flex items-center gap-3">
          <span
            aria-hidden="true"
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-royal-tint text-xs font-bold text-royal"
          >
            {initialsOf(owner.name)}
          </span>
          <span className="min-w-0">
            <Link
              to={ownerProfilePath(owner.id)}
              className="block truncate font-bold text-ink hover:text-royal hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal"
            >
              {owner.name}
            </Link>
            {showsContact && <span className="block truncate text-xs text-ink-muted">{owner.email ?? '—'}</span>}
          </span>
        </div>
      </td>
      {showsContact && (
        <td className="tnum whitespace-nowrap px-5 py-3.5 text-ink-secondary">{owner.mobile_number ?? '—'}</td>
      )}
      <td className="px-5 py-3.5">
        <ul className="space-y-1">
          {shown.map((b) => (
            <li key={b.id} className="flex items-center gap-2">
              <span
                aria-hidden="true"
                className={`h-2 w-2 shrink-0 rounded-full ${BUSINESS_STATUS[b.status]?.dot ?? 'bg-line'}`}
              />
              <span className="truncate text-ink">{b.name}</span>
              {b.status !== 'active' && <span className="shrink-0 text-xs text-ink-muted">({b.status_label})</span>}
            </li>
          ))}
        </ul>
        {more > 0 && <span className="mt-1 block text-xs text-ink-muted">and {more} more</span>}
      </td>
      <td className="tnum px-5 py-3.5 text-right font-semibold text-ink">{owner.active_permits.toLocaleString()}</td>
      <td className="px-5 py-3.5">
        <StatusChip tone={owner.status === 'blacklisted' ? 'tint-red' : 'tint-green'}>{owner.status_label}</StatusChip>
      </td>
      <td className="px-3 py-3.5 text-ink-muted">
        <ChevronRightIcon size={16} aria-hidden="true" />
      </td>
    </tr>
  )
}
