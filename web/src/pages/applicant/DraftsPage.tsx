import { useState } from 'react'
import { Link } from 'react-router-dom'
import type { SVGProps } from 'react'
import { AmendIcon, DraftsIcon, FilePlusIcon, RenewIcon } from '../../components/icons'
import { EmptyState, ErrorState, LinkButton, SkeletonList } from '../../components/ui/primitives'
import { FilterPills, PageTitle, ProtoModal, SortFilter } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { applications } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { ApplicationListItem, ApplicationType } from '../../lib/types'

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
  const [sort, setSort] = useState<'worked' | 'recent' | 'oldest'>('worked')
  /*
   * The draft the confirmation modal is asking about, held whole rather than by
   * id: the dialog names it ("Delete 'Pedro's Snack Bar'?"), and looking the
   * name back up from the list would go wrong in exactly the case that matters
   * — the list reloading underneath an open dialog.
   */
  const [confirming, setConfirming] = useState<ApplicationListItem | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  async function confirmDelete() {
    if (confirming === null) return
    setDeleting(true)
    setDeleteError(null)
    try {
      await applications.destroy(confirming.id)
      setConfirming(null)
      /*
       * Reloaded rather than spliced out of `visible`. The server decides what
       * a draft is — it refuses anything already submitted — so a local removal
       * would be the browser asserting an outcome the server may not have
       * agreed to, and a draft submitted in another tab would vanish from this
       * list while still sitting in the register.
       */
      reload()
    } catch (err) {
      // Kept OPEN on failure, with the reason. Closing the dialog on an error
      // leaves the draft on screen with no explanation, which reads as the
      // button not working.
      setDeleteError(toApiError(err).message)
    } finally {
      setDeleting(false)
    }
  }

  const drafts = data ?? []
  const byType = filter === 'all' ? drafts : drafts.filter((d) => d.application_type === filter)
  /*
   * ── Three orders, and the default is the one people open this page for ──
   *
   * It could only order by when a draft was STARTED, because that was the
   * only date the API sent — the note here used to say an application has no
   * `updated_at`, which was wrong: the column has existed since the table
   * was created (`timestamps()`) and was simply never serialised.
   *
   * "Last worked on" leads and is the default, because it answers the
   * question somebody arrives with: where was I? An applicant with six
   * drafts going gets no help from the order they were begun in — the
   * oldest is as likely as any to be the live one.
   *
   * Copied rather than sorted in place: `data` is the hook's array and
   * sorting it would reorder the source of a memo-free render.
   */
  const visible = [...byType].sort((a, b) => {
    if (sort === 'worked') {
      return Date.parse(b.updated_at) - Date.parse(a.updated_at)
    }
    const diff = Date.parse(a.created_at) - Date.parse(b.created_at)
    return sort === 'recent' ? -diff : diff
  })

  return (
    <div>
      <PageTitle
        right={
          /*
            Sort only. The Filter half was drawn here too and did nothing, with
            the working filter — the pills below — sitting directly under it:
            two controls for one job, one of them inert. The pills stay because
            four named types are faster to hit than a menu.
          */
          <SortFilter
            sort={{
              value: sort,
              options: [
                /*
                 * "Last worked on" rather than "Last accessed": the date it
                 * reads moves when the draft is SAVED, and opening one to
                 * read it saves nothing. Naming it for what it measures is
                 * the difference between a label and a small lie.
                 */
                { value: 'worked', label: 'Last worked on' },
                { value: 'recent', label: 'Newest first' },
                { value: 'oldest', label: 'Oldest first' },
              ],
              onChange: (v) => setSort(v as 'recent' | 'oldest'),
            }}
          />
        }
      >
        Application Drafts
      </PageTitle>

      {/*
        The pills alone.

        A trash button sat here, labelled "Delete drafts", with no `onClick` at
        all — drawn from the PDF mockup (p20: "filter pills + trash") and never
        wired to anything. So the page advertised a delete it did not have,
        which is how the client came to ask whether it had one.

        It is not wired up in place, it is GONE. Up here the control has no
        object: "delete drafts" could mean the one you are looking at, the ones
        this filter shows, or all of them, and a destructive button whose scope
        the reader has to guess is the one most likely to be pressed by
        accident. Each card now carries its own, which can name what it deletes.
      */}
      <div className="mb-5">
        <FilterPills options={FILTERS} value={filter} onChange={setFilter} />
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
            <ul
              // `*:min-w-0`: a grid item's automatic minimum is its min-content,
              // so one long draft title sized the whole mobile column wider than
              // the panel it sits in.
              className="grid gap-6 *:min-w-0 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
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
                  /*
                   * `relative`, because the delete button is positioned over
                   * the card and must be a SIBLING of the Link rather than a
                   * child of it. A <button> inside an <a> is invalid HTML, and
                   * in practice the click would open the draft on its way to
                   * the handler — the one interaction where being taken
                   * somewhere unexpected is worst.
                   */
                  <li key={d.id} className="group relative">
                    <Link
                      to={`/apply?draft=${d.id}`}
                      className="block overflow-hidden rounded-md bg-white shadow-card transition-shadow hover:shadow-raised"
                    >
                      <div className="flex h-44 items-center justify-center text-royal-deep">
                        <Icon size={80} strokeWidth={1.5} />
                      </div>
                      <div className="bg-canvas px-4 py-3">
                        <div className="flex items-center justify-between gap-2">
                          {/* min-w-0 or the flex item refuses to go below the
                              title's nowrap width and the tile grows past the
                              panel it sits in, which is what truncate is for. */}
                          <p className="min-w-0 truncate text-[15px] font-bold text-ink">{name}</p>
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
                        <p className="mt-1 text-xs text-ink-secondary">
                          Started: {formatDate(d.created_at)}
                        </p>
                      </div>
                    </Link>
                    {/*
                      Always in the DOM and always reachable, never
                      hover-to-reveal.

                      A control that appears on hover does not exist on a touch
                      screen, and City Hall's applicants are largely on phones.
                      Hover and focus raise its contrast rather than summon it,
                      so it is discoverable by pointer, keyboard and finger
                      alike — and the label names the draft, because a screen
                      reader moving through four cards otherwise hears "Delete
                      draft" four times with no way to tell them apart.
                    */}
                    <button
                      type="button"
                      aria-label={`Delete draft: ${name}`}
                      onClick={() => {
                        setDeleteError(null)
                        setConfirming(d)
                      }}
                      className="absolute right-2 top-2 rounded-md bg-white/90 p-1.5 text-ink-muted shadow-card transition-colors hover:bg-s-red-tint hover:text-s-red focus-visible:bg-s-red-tint focus-visible:text-s-red group-hover:text-ink-secondary"
                    >
                      <TrashIcon size={18} />
                    </button>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
      )}

      {/*
        ── The confirmation the client asked for ───────────────────────────────

        Red tone, because this is the destructive branch of ProtoModal's three
        and the header colour is the first thing read.

        The draft is NAMED. "Are you sure?" over an unnamed draft is the dialog
        people learn to dismiss without reading, and on a page of four cards
        that all look alike it genuinely does not say which one is about to go.

        It also says what is lost, in the applicant's terms — the answers and
        the uploads — rather than "this action cannot be undone". On the server
        the delete is soft and the row survives for recovery, so "cannot be
        undone" would be false; but there is no restore button in the product,
        so promising recoverability would be worse. "You will have to start it
        again" is true from where the applicant stands, which is the only place
        that matters here.
      */}
      {confirming !== null && (
        <ProtoModal
          title="Delete this draft?"
          tone="red"
          cancelLabel="Keep it"
          confirmLabel={deleting ? 'Deleting…' : 'Delete draft'}
          confirmDisabled={deleting}
          onCancel={() => {
            if (deleting) return
            setConfirming(null)
            setDeleteError(null)
          }}
          onConfirm={() => void confirmDelete()}
        >
          <p className="text-sm text-ink">
            <span className="font-bold">
              {confirming.title?.trim() || confirming.business?.name || 'My Application'}
            </span>{' '}
            will be removed from your drafts.
          </p>
          <p className="mt-2 text-sm text-ink-secondary">
            The answers you have filled in and any documents you attached to it go with it. Nothing
            has been submitted to the LGU, so there is nothing to cancel — but you will have to
            start this application again if you want it back.
          </p>
          {deleteError !== null && (
            <p
              role="alert"
              className="mt-3 rounded-md border border-s-red bg-s-red-tint px-3 py-2 text-sm text-ink"
            >
              {deleteError}
            </p>
          )}
        </ProtoModal>
      )}
    </div>
  )
}
