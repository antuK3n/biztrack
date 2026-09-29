import { useState } from 'react'
import { Link } from 'react-router-dom'
import type { SVGProps } from 'react'
import { AmendIcon, CalendarIcon, DraftsIcon, FilePlusIcon } from '../../components/icons'
import { EmptyState, ErrorState, LinkButton, SkeletonList } from '../../components/ui/primitives'
import { FilterPills, PageTitle, ProtoModal, SortFilter } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { formatDateTime, formatTileDateTime } from '../../lib/format'
import { applications, wizardDrafts } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { ApplicationType } from '../../lib/types'

/**
 * What one draft card is called, before repeats are numbered.
 *
 * The applicant's own title wins; a draft they never renamed keeps showing
 * the business it is for. A draft whose business was removed falls through
 * to the generic name rather than telling its own author the register
 * dropped them — they find that out on the filing itself, with the context
 * to understand it.
 *
 * Shared with the unfinished cards, so the two kinds cannot drift into
 * different fallbacks: they did, and the client asked for one rule.
 */
function draftName(title: string | null | undefined, business?: string | null): string {
  return title?.trim() || business?.trim() || 'My Application'
}

/**
 * Number the repeats: "Store", "Store (1)", "Store (2)".
 *
 * Three renewals of one business are three cards with one name, and the
 * list gave no way to tell them apart. Client, 29 September 2026: *"if they
 * are multiple similar names, then there should be (1), (2), and so on."*
 *
 * The FIRST keeps the bare name — the convention a file manager uses, and
 * the one that leaves a person with a single draft unaffected.
 *
 * Keyed on a stable id and assigned in STARTED order, deliberately. Doing
 * it in display order would renumber every card whenever the sort control
 * changes, so the draft somebody has been calling "(2)" silently becomes
 * "(1)".
 */
function numberRepeats(
  entries: { key: string; name: string; startedAt: string | null }[],
): Map<string, string> {
  const byStart = [...entries].sort(
    (a, b) => Date.parse(a.startedAt ?? '') - Date.parse(b.startedAt ?? ''),
  )

  const seen = new Map<string, number>()
  const names = new Map<string, string>()
  for (const entry of byStart) {
    const count = seen.get(entry.name) ?? 0
    seen.set(entry.name, count + 1)
    names.set(entry.key, count === 0 ? entry.name : `${entry.name} (${count})`)
  }

  return names
}

/**
 * One card on this page, whichever table it came from.
 *
 * A draft the city holds and a filing saved before it could be one are the
 * same thing to somebody reading this grid: work they started and want to
 * get back to. They differ only in how Resume and Delete are carried out,
 * so each card carries its own way of doing both and nothing above them has
 * to know which kind it is holding.
 *
 * Normalising here is what stops a rule being applied to one kind and
 * forgotten for the other — the filter, sort, numbering, empty state and
 * confirm dialog all read this one array.
 */
type DraftCard = {
  key: string
  name: string
  applicationType: ApplicationType
  /** When it was begun. Drives "Started" and the repeat numbering. */
  startedAt: string | null
  /**
   * When it was last OPENED, or null if it never has been.
   *
   * Drives the default sort and the second line on the card. A separate
   * fact from the last write: an autosave moves `updated_at` and leaves
   * this alone, which is what lets the option be called "Last opened" and
   * mean it.
   */
  openedAt: string | null
  resumeTo: string
  /** The name the applicant set, if any — what the rename box opens with. */
  ownTitle: string | null
  remove: () => Promise<void>
  /** Never blank: this screen will not save an empty name. */
  rename: (title: string) => Promise<void>
}

/* Application Drafts — PDF p20: filter pills + trash, cards on the deep-blue panel. */

type Filter = 'all' | ApplicationType

const FILTERS: { value: Filter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'new', label: 'New Permit' },
  { value: 'renewal', label: 'Renewal' },
  { value: 'amendment', label: 'Amendment' },
]

/*
 * `CalendarIcon` for a renewal, not `RenewIcon`.
 *
 * A circular arrow is among the most learned marks in software and it
 * means refresh. Renewing a permit is a yearly act with a date attached,
 * so the old glyph was arguing with what every reader already knows it
 * for. `RenewIcon` is still right where a renewal is an ACTION being
 * offered; on a card describing what a draft IS, the calendar says it.
 */
const TYPE_ICON = { new: FilePlusIcon, renewal: CalendarIcon, amendment: AmendIcon } as const

/*
 * Each card names its own kind.
 *
 * Colouring the three apart was the other option and the palette is
 * against it: rose is Returned, teal is For Final Approval, purple is For
 * Approval, orange is Pending Payment. Spending those on draft TYPE would
 * have an amendment card and a returned filing wearing one colour for two
 * unrelated reasons. A word cannot be misread that way.
 */
/*
 * A pencil for the rename control.
 *
 * This was here before, as decoration in the card's hero band, and went
 * with it — a picture of a pencil beside a card that is already a link
 * says the same thing twice. It is a button now, so it is back.
 */
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

const TYPE_LABEL: Record<ApplicationType, string> = {
  new: 'New Permit',
  renewal: 'Renewal',
  amendment: 'Amendment',
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
  /*
   * Filings started but not yet a draft — see `wizardDrafts`. Loaded
   * separately because they are a different thing from a different table,
   * and a failure to fetch them must not take the real drafts down with
   * it: `?? []` means the page degrades to what it has always shown.
   */
  const started = useAsync(() => wizardDrafts.list(), [])
  const [filter, setFilter] = useState<Filter>('all')
  const [sort, setSort] = useState<'opened' | 'recent' | 'oldest'>('opened')
  /*
   * The draft the confirmation modal is asking about, held whole rather than by
   * id: the dialog names it ("Delete 'Pedro's Snack Bar'?"), and looking the
   * name back up from the list would go wrong in exactly the case that matters
   * — the list reloading underneath an open dialog.
   */
  const [confirming, setConfirming] = useState<DraftCard | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [deleteError, setDeleteError] = useState<string | null>(null)
  /*
   * The draft being renamed, held whole for the same reason `confirming`
   * is: the dialog names it, and looking the name back up from the list
   * goes wrong exactly when the list reloads under an open dialog.
   */
  const [renaming, setRenaming] = useState<DraftCard | null>(null)
  const [renameTo, setRenameTo] = useState('')
  const [renameBusy, setRenameBusy] = useState(false)
  const [renameError, setRenameError] = useState<string | null>(null)

  async function confirmRename() {
    if (renaming === null) return
    /*
     * The disabled button is not the guard — Enter in the box calls this
     * directly, and a keyboard is how somebody would actually reach a
     * state the pointer cannot.
     */
    const name = renameTo.trim()
    if (name === '') return

    setRenameBusy(true)
    setRenameError(null)
    try {
      await renaming.rename(name)
      setRenaming(null)
      reload()
      started.reload()
    } catch (err) {
      setRenameError(toApiError(err).message)
    } finally {
      setRenameBusy(false)
    }
  }

  /*
   * One path for both kinds. The unfinished cards used to delete on the
   * first click while a draft asked first — the same trash icon, in the
   * same corner of the same grid, meaning two different things depending on
   * which card it was over.
   */
  async function confirmDelete() {
    if (confirming === null) return
    setDeleting(true)
    setDeleteError(null)
    try {
      await confirming.remove()
      setConfirming(null)
      /*
       * Reloaded rather than spliced out of `visible`. The server decides what
       * a draft is — it refuses anything already submitted — so a local removal
       * would be the browser asserting an outcome the server may not have
       * agreed to, and a draft submitted in another tab would vanish from this
       * list while still sitting in the register.
       */
      reload()
      started.reload()
    } catch (err) {
      // Kept OPEN on failure, with the reason. Closing the dialog on an error
      // leaves the draft on screen with no explanation, which reads as the
      // button not working.
      setDeleteError(toApiError(err).message)
    } finally {
      setDeleting(false)
    }
  }

  /*
   * ── Both sources, flattened into one list of cards ──────────────────────
   *
   * A draft the city holds and a filing saved before it could be one are one
   * thing to the person reading this grid. Normalised here so the filter, the
   * sort, the numbering and the delete cannot be applied to one kind and
   * forgotten for the other.
   */
  const cards: DraftCard[] = [
    ...(data ?? []).map((d) => ({
      key: `draft-${d.id}`,
      name: draftName(d.title, d.business?.name),
      applicationType: d.application_type,
      startedAt: d.created_at,
      /*
       * Falls back to the last write for a draft nobody has opened since
       * the column shipped. The COLUMN stays null — inventing a date there
       * would record something nothing observed — but a card has to show
       * the reader their best available answer rather than a dash.
       */
      openedAt: d.last_opened_at ?? d.updated_at,
      resumeTo: `/apply?draft=${d.id}`,
      ownTitle: d.title ?? null,
      remove: async () => {
        await applications.destroy(d.id)
      },
      rename: async (title: string) => {
        await applications.update(d.id, { title })
      },
    })),
    /*
     * An unfinished filing has one date, not two: the wizard rewrites the
     * whole row on every save, so "started" and "last worked on" are the same
     * moment. Both fields take it rather than one being left null, so it
     * sorts sensibly under either order instead of falling to the bottom.
     */
    ...(started.data ?? []).map((d) => ({
      key: `started-${d.id}`,
      name: draftName(d.title),
      applicationType: d.application_type as ApplicationType,
      startedAt: d.updated_at,
      openedAt: d.last_opened_at ?? d.updated_at,
      /*
       * `resume` carries the row's id, so this card opens THIS filing.
       * `/apply?type=new` with no `resume` is a blank form, because that is
       * what the dashboard's New Business Permit card links to.
       */
      resumeTo: `/apply?type=${d.application_type}&resume=${d.id}`,
      ownTitle: d.title ?? null,
      remove: async () => {
        await wizardDrafts.discard(d.id)
      },
      rename: async (title: string) => {
        await wizardDrafts.rename(d.id, title)
      },
    })),
  ]

  const byType =
    filter === 'all' ? cards : cards.filter((c) => c.applicationType === filter)

  /* Repeats numbered across the whole list — see `numberRepeats`. */
  const cardNames = numberRepeats(
    byType.map((c) => ({ key: c.key, name: c.name, startedAt: c.startedAt })),
  )

  /*
   * ── Three orders, and the default is the one people open this page for ──
   *
   * It could only order by when a draft was STARTED, because that was the
   * only date the API sent — the note here used to say an application has no
   * `updated_at`, which was wrong: the column has existed since the table
   * was created (`timestamps()`) and was simply never serialised.
   *
   * "Last opened" leads and is the default, because it answers the
   * question somebody arrives with: where was I? An applicant with six
   * drafts going gets no help from the order they were begun in — the
   * oldest is as likely as any to be the live one.
   *
   * It was "Last worked on" and read `updated_at`, which moves on SAVE.
   * The client asked for the name to change on 29 September 2026 and said
   * the rule should follow it, which was the right call: the note here
   * warned that renaming alone is "the difference between a label and a
   * small lie". `last_opened_at` is stamped when a draft is opened and by
   * nothing else, so the label and the measurement agree.
   *
   * Unfinished filings sort with everything else rather than being pinned to
   * the front. They were pinned, and that was the last of the special cases
   * the client asked to be rid of: a sort control that some cards ignore is a
   * sort control the reader cannot trust.
   */
  const visible = [...byType].sort((a, b) => {
    if (sort === 'opened') {
      return Date.parse(b.openedAt ?? '') - Date.parse(a.openedAt ?? '')
    }
    const diff = Date.parse(a.startedAt ?? '') - Date.parse(b.startedAt ?? '')

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
                 * "Last opened", and it reads a column that moves only when
                 * a draft is opened. The note here used to explain why the
                 * option could NOT be called this — it read `updated_at`,
                 * which moves on save — and warned that naming it otherwise
                 * would be "the difference between a label and a small lie".
                 * The client asked for the name; the measurement came with
                 * it.
                 */
                { value: 'opened', label: 'Last opened' },
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
                const Icon = TYPE_ICON[d.applicationType] ?? FilePlusIcon
                /* `draftName` held the rule; `cardNames` numbers the repeats. */
                const name = cardNames.get(d.key) ?? d.name
                return (
                  /*
                   * `relative`, because the delete button is positioned over
                   * the card and must be a SIBLING of the Link rather than a
                   * child of it. A <button> inside an <a> is invalid HTML, and
                   * in practice the click would open the draft on its way to
                   * the handler — the one interaction where being taken
                   * somewhere unexpected is worst.
                   */
                  <li key={d.key} className="group relative h-full">
                    {/*
                      `h-full` and a flex column so the card fills its grid
                      row: the footer below takes `mt-auto`, which is what
                      puts the dates on one line across the row instead of
                      leaving each card its own height.
                    */}
                    <Link
                      to={d.resumeTo}
                      className="flex h-full flex-col overflow-hidden rounded-md bg-white shadow-card transition-shadow hover:shadow-raised"
                    >
                      {/*
                        ── The name is the hero ─────────────────────────────

                        An 80px glyph filled a 176px band above this until 29
                        September 2026, so the biggest thing on the card told
                        the reader the least — two New Permit drafts were one
                        identical picture twice, told apart only by the caption
                        underneath. The icon is a marker now, beside the word
                        it marks, and the business name has the room.

                        `pr-9` keeps the type row clear of the delete button,
                        which is positioned over this corner.
                      */}
                      <div className="px-4 pb-3 pt-3.5">
                        <div className="flex items-center gap-2 pr-[4.25rem]">
                          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-royal-tint text-royal-deep">
                            <Icon size={16} strokeWidth={1.75} />
                          </span>
                          <span className="truncate text-[11px] font-bold uppercase tracking-wide text-royal">
                            {TYPE_LABEL[d.applicationType] ?? 'Application'}
                          </span>
                        </div>
                        {/*
                          Two lines before it clips, not one. These names run
                          "2026 Amendment — Pedro's Snack Bar (3)", and a
                          single truncated line cuts exactly the part that
                          tells two of them apart.
                        */}
                        {/*
                          One line, cut with an ellipsis. The cards are then
                          the same height because there is nothing left to
                          vary, rather than because a two-line floor was
                          propped under a one-line name.

                          `title` carries the whole name: the ellipsis says
                          there is more, so there has to be somewhere to read
                          it.
                        */}
                        <p
                          title={name}
                          className="mt-2 truncate text-[15px] font-bold leading-snug text-ink"
                        >
                          {name}
                        </p>
                      </div>
                      <div className="mt-auto bg-canvas px-4 py-3">
                        {/*
                          Both facts, because they answer different questions
                          and a card showing only one invites the reader to
                          assume it is the other. Labelling the start date
                          "Edited" once told an applicant who worked on a draft
                          an hour ago that they last edited it three weeks
                          back, which is the sort of thing that makes somebody
                          doubt their work was saved.

                          With a time: two drafts begun the same afternoon read
                          as one date, and the client asked for the hour.
                        */}
                        <p
                          title={formatDateTime(d.startedAt)}
                          className="mt-1 truncate whitespace-nowrap text-xs text-ink-secondary"
                        >
                          Started: {formatTileDateTime(d.startedAt)}
                        </p>
                        {/*
                          `truncate whitespace-nowrap`: on a tile too narrow
                          for it, this cuts off with an ellipsis instead of
                          breaking after the date and leaving "PM" alone on a
                          second line. The full timestamp, year and all, is on
                          the title attribute.
                        */}
                        <p
                          title={formatDateTime(d.openedAt)}
                          className="truncate whitespace-nowrap text-xs text-ink-secondary"
                        >
                          Last opened: {formatTileDateTime(d.openedAt)}
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
                    {/*
                      Rename sits beside delete, for the reason the client
                      asked for it at all: a list of four cards called "New
                      Business Permit" is where you NOTICE the problem, so it
                      should be where you can fix it. The wizard's title box
                      always allowed this and made you open the filing first.

                      Both buttons are siblings of the Link, never children:
                      a <button> inside an <a> is invalid HTML and in
                      practice opens the draft on its way to the handler.
                    */}
                    <button
                      type="button"
                      aria-label={`Rename draft: ${name}`}
                      onClick={() => {
                        setRenameError(null)
                        setRenameTo(d.ownTitle ?? '')
                        setRenaming(d)
                      }}
                      className="absolute right-10 top-2 rounded-md bg-white/90 p-1.5 text-ink-muted shadow-card transition-colors hover:bg-royal-tint hover:text-royal focus-visible:bg-royal-tint focus-visible:text-royal group-hover:text-ink-secondary"
                    >
                      <PencilIcon size={18} />
                    </button>
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
      {renaming !== null && (
        <ProtoModal
          title="Rename this draft"
          cancelLabel="Cancel"
          confirmLabel={renameBusy ? 'Saving…' : 'Save name'}
          confirmDisabled={renameBusy || renameTo.trim() === ''}
          onCancel={() => {
            if (renameBusy) return
            setRenaming(null)
            setRenameError(null)
          }}
          onConfirm={() => void confirmRename()}
        >
          <label className="block">
            <span className="mb-1.5 block text-[13px] font-semibold text-ink">Draft name</span>
            <input
              autoFocus
              value={renameTo}
              maxLength={120}
              onChange={(e) => setRenameTo(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault()
                  void confirmRename()
                }
              }}
              placeholder={renaming.name}
              className="w-full rounded-lg border border-input-border bg-input px-3 py-2 text-sm text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
            />
          </label>
          {renameError !== null && (
            <p
              role="alert"
              className="mt-3 rounded-md border border-s-red bg-s-red-tint px-3 py-2 text-sm text-ink"
            >
              {renameError}
            </p>
          )}
        </ProtoModal>
      )}

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
              {cardNames.get(confirming.key) ?? confirming.name}
            </span>{' '}
            will be removed from your drafts.
          </p>
          {/*
            What goes, and whether it comes back. Four lines stood here and
            the middle of them reassured the reader that nothing had been
            submitted to the LGU — a worry nobody has while deleting a
            DRAFT, since a filing already sent is not in this list.
          */}
          <p className="mt-2 text-sm text-ink-secondary">
            Its answers and documents go with it, and this cannot be undone.
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
