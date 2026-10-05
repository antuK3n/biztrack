import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ShieldCheckIcon } from '../../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { PageTitle, ProtoCard, SortFilter } from '../../components/ui/Proto'
import { useAuth } from '../../stores/auth'
import { BusinessRow, FILTERS, SORTS, useHoldings } from './permitHoldings'

/*
 * ── The permits, on a page of their own ───────────────────────────────────
 *
 * This route was a redirect to /profile, where an applicant's permits were the
 * last section of the account record, under their name, gender and join date.
 * Two things followed, and the client hit both [28 September 2026: *"create a
 * page dedicated for approved permits for more visibility and accessibility"*]:
 *
 *  - Profile is not in the rail. It lives behind the avatar menu, and the
 *    avatar menu is part of the rail, which is `hidden … lg:flex`. So on a
 *    phone an applicant could not reach their own permits at all — on the one
 *    device they would actually be holding at a counter when an inspector asks
 *    to see one.
 *  - Even on a desktop they were three scrolls down a page about the account,
 *    which is not where anybody looks for a certificate.
 *
 * So: its own route, its own rail entry, and a place in the mobile tab bar.
 * Profile keeps a one-line summary that links here, because "how many
 * businesses do I hold" is still a fact about the account.
 */
export function PermitsPage() {
  const user = useAuth((s) => s.user)
  /*
   * The one business card that is open. `undefined` until the reader chooses:
   * the first business is open by default, the rest closed [client, 5 October
   * 2026]. `null` means they closed it and nothing is open.
   */
  const [openId, setOpenId] = useState<number | null | undefined>(undefined)

  /*
   * Only applicants. An officer reaching this URL would otherwise be shown the
   * permits their office is routed to, under a heading calling them theirs —
   * the register-wide view has its own screens. The fetcher short-circuits
   * rather than the hook being skipped, because a conditional hook is a
   * different bug.
   */
  const isOwner = user?.roles.includes('business_owner') ?? false
  const { groups, visible, sort, setSort, filter, setFilter, loading, error, reload } =
    useHoldings(isOwner)

  if (!user) return null

  const businesses = groups.length
  const permits = groups.reduce((n, g) => n + g.permits.length, 0)
  const nearing = groups.filter((g) => g.nearing).length
  const expired = groups.filter((g) => g.expired).length

  return (
    <div className="mx-auto max-w-4xl">
      <PageTitle
        right={
          groups.length > 0 && (
            <SortFilter
              sort={{ value: sort, options: SORTS, onChange: setSort }}
              filter={{ value: filter, options: FILTERS, onChange: setFilter }}
            />
          )
        }
      >
        My Permits
      </PageTitle>

      {/*
        ── What you hold, before the list ─────────────────────────────────

        Four figures an applicant would otherwise count by opening every
        business in turn. The two that can be bad news are only drawn when
        there IS bad news: a permanent "0 expiring" reads as a warning slot
        waiting to fill, and teaches the eye to skip the row it sits in.

        Counted from the groups rather than fetched, so this line and the list
        below can never disagree.
      */}
      {!loading && !error && groups.length > 0 && (
        <ProtoCard className="mb-6 flex flex-wrap items-stretch gap-x-8 gap-y-4 px-6 py-4">
          <Figure value={businesses} label={businesses === 1 ? 'business' : 'businesses'} />
          <Figure value={permits} label={permits === 1 ? 'issued permit' : 'issued permits'} />
          {nearing > 0 && (
            <Figure
              value={nearing}
              label={nearing === 1 ? 'business expiring soon' : 'businesses expiring soon'}
              tone="warn"
            />
          )}
          {expired > 0 && (
            <Figure
              value={expired}
              label={expired === 1 ? 'business with a lapsed permit' : 'businesses with a lapsed permit'}
              tone="stop"
            />
          )}
        </ProtoCard>
      )}

      {loading ? (
        <SkeletonList rows={3} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : groups.length === 0 ? (
        <EmptyState
          icon={ShieldCheckIcon}
          title="Nothing issued to you yet"
          description="When an application is approved and the permit is issued, your business and its permits appear here to view, print, or save as PDF. Clearances you submitted a copy of instead of applying for appear here too, under the business they belong to."
          action={
            <Link
              to="/apply?type=new"
              className="rounded-md bg-royal px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-royal-hover"
            >
              Apply for a business permit
            </Link>
          }
        />
      ) : visible.length === 0 ? (
        <EmptyState
          icon={ShieldCheckIcon}
          title="No businesses match this filter"
          description={`None of your ${businesses} ${businesses === 1 ? 'business' : 'businesses'} matches “${FILTERS.find((f) => f.value === filter)?.label}”.`}
          action={
            <button
              type="button"
              onClick={() => setFilter('all')}
              className="rounded-md bg-royal px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-royal-hover"
            >
              Show all businesses
            </button>
          }
        />
      ) : (
        <ul className="space-y-4">
          {/*
            One business open at a time — the first one to begin with, the rest
            closed — so the page reads as a summary of every business rather
            than a wall of permits [client, 5 October 2026].
          */}
          {visible.map((group) => {
            const current = openId === undefined ? visible[0]?.id : openId
            return (
              <BusinessRow
                key={group.id}
                group={group}
                open={current === group.id}
                onToggle={() => setOpenId(current === group.id ? null : group.id)}
              />
            )
          })}
        </ul>
      )}

      {groups.length > 0 && (
        <p className="mt-6 text-xs leading-relaxed text-ink-muted">
          Open a permit to print it or save it as a PDF. Each one carries a QR code that anyone can
          scan to check it is genuine — including an inspector at your premises.
        </p>
      )}
    </div>
  )
}

/**
 * One figure in the summary row.
 *
 * The number is large and the word under it is plain, so the row reads as
 * "3 businesses" rather than as a dashboard tile. Tone is carried by the
 * NUMBER and repeated in the word beside it — "expiring soon" says what amber
 * means, so nobody has to know the colour code to read the row.
 */
function Figure({
  value,
  label,
  tone,
}: {
  value: number
  label: string
  /** Amber for a renewal due, red for one already lapsed. Absent = plain. */
  tone?: 'warn' | 'stop'
}) {
  const colour = tone === 'stop' ? 'text-s-red' : tone === 'warn' ? 'text-amber-800' : 'text-ink'
  return (
    <span className="flex min-w-0 flex-col">
      <span className={`tnum display-serif text-2xl leading-none ${colour}`}>{value}</span>
      <span className="mt-1 text-xs text-ink-muted">{label}</span>
    </span>
  )
}
