import { useState } from 'react'
import { Link } from 'react-router-dom'
import type { SVGProps } from 'react'
import { AmendIcon, DraftsIcon, FilePlusIcon, RenewIcon } from '../../components/icons'
import { EmptyState, ErrorState, LinkButton, SkeletonList } from '../../components/ui/primitives'
import { FilterPills, PageTitle, SortFilter } from '../../components/ui/Proto'
import { formatDate } from '../../lib/format'
import { applications } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { ApplicationType } from '../../lib/types'

/* Application Drafts — PDF p20: filter pills + trash, cards on the deep-blue panel. */

type Filter = 'all' | ApplicationType

const FILTERS: { value: Filter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'new', label: 'New Permit' },
  { value: 'renewal', label: 'Renewal' },
  { value: 'amendment', label: 'Amendment' },
]

const TYPE_ICON = { new: FilePlusIcon, renewal: RenewIcon, amendment: AmendIcon } as const

function PencilIcon({ size = 18, ...props }: SVGProps<SVGSVGElement> & { size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.75}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      {...props}
    >
      <path d="M11 4.5H6A1.5 1.5 0 0 0 4.5 6v12A1.5 1.5 0 0 0 6 19.5h12a1.5 1.5 0 0 0 1.5-1.5v-5" />
      <path d="M17.8 3.7a2 2 0 0 1 2.8 2.8L13 14.1l-3.7.7.7-3.6 7.8-7.5Z" />
    </svg>
  )
}

function TrashIcon({ size = 24, ...props }: SVGProps<SVGSVGElement> & { size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.75}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      {...props}
    >
      <path d="M4.5 6.5h15M9.5 6.5V4.75A1.25 1.25 0 0 1 10.75 3.5h2.5a1.25 1.25 0 0 1 1.25 1.25V6.5" />
      <path d="M6.5 6.5l.8 12.6a1.5 1.5 0 0 0 1.5 1.4h6.4a1.5 1.5 0 0 0 1.5-1.4l.8-12.6" />
      <path d="M10 10.5v6M14 10.5v6" />
    </svg>
  )
}

export function DraftsPage() {
  const { data, loading, error, reload } = useAsync(() => applications.list({ status: 'draft' }), [])
  const [filter, setFilter] = useState<Filter>('all')

  const drafts = data ?? []
  const visible = filter === 'all' ? drafts : drafts.filter((d) => d.application_type === filter)

  return (
    <div>
      <PageTitle right={<SortFilter />}>Application Drafts</PageTitle>

      <div className="mb-5 flex items-center justify-between gap-4">
        <FilterPills options={FILTERS} value={filter} onChange={setFilter} />
        <button
          type="button"
          aria-label="Delete drafts"
          className="text-royal-deep transition-colors hover:text-s-red"
        >
          <TrashIcon size={26} />
        </button>
      </div>

      {loading ? (
        <SkeletonList rows={3} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : (
        <div className="rounded-2xl bg-panel p-6 sm:p-8">
          {visible.length === 0 ? (
            <div className="rounded-xl bg-white p-6 shadow-card">
              <EmptyState
                icon={DraftsIcon}
                title={filter === 'all' ? 'No drafts' : 'No drafts of this type'}
                description="When you save an application without submitting it, it waits for you here."
                action={<LinkButton to="/apply">Start an application</LinkButton>}
              />
            </div>
          ) : (
            <ul className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
              {visible.map((d) => {
                const Icon = TYPE_ICON[d.application_type] ?? FilePlusIcon
                // The applicant's own title wins; a draft they never renamed
                // keeps showing the business it is for. A draft whose business
                // was removed falls through to the generic name rather than
                // telling its own author the register dropped them — they will
                // find that out on the filing itself, with the context to
                // understand it.
                const name = d.title?.trim() || d.business?.name || 'My Application'
                return (
                  <li key={d.id}>
                    <Link
                      to={`/apply?draft=${d.id}`}
                      className="block overflow-hidden rounded-md bg-white shadow-card transition-shadow hover:shadow-raised"
                    >
                      <div className="flex h-44 items-center justify-center text-royal-deep">
                        <Icon size={80} strokeWidth={1.5} />
                      </div>
                      <div className="bg-canvas px-4 py-3">
                        <div className="flex items-center justify-between gap-2">
                          <p className="truncate text-[15px] font-bold text-ink">{name}</p>
                          <PencilIcon size={17} className="shrink-0 text-royal-deep" />
                        </div>
                        {/*
                          `created_at` is the day the draft was started, not
                          the day it was last touched — the API does not expose
                          an updated_at. Labelling it "Edited" told an applicant
                          who worked on this draft an hour ago that they last
                          edited it three weeks back, which is the sort of thing
                          that makes someone doubt their work was saved.
                        */}
                        <p className="mt-1 text-xs text-ink-secondary">Started: {formatDate(d.created_at)}</p>
                      </div>
                    </Link>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
      )}
    </div>
  )
}
