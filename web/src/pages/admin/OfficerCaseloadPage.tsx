import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { admin } from '../../lib/resources'
import { toApiError } from '../../lib/api'
import { useAsync } from '../../lib/useAsync'
import { formatDateTime } from '../../lib/format'
import type { CaseloadCase } from '../../lib/types'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { PageTitle, ProtoCard, ProtoModal, SortFilter, StatusChip } from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { ArrowLeftIcon, UsersIcon } from '../../components/icons'

/*
 * One officer's caseload, on a page of its own.
 *
 * ── Why this is a page and not the dialog it replaces ─────────────────────
 *
 * Reassign was a modal on Officer Assignment, and the client asked for it to
 * become a screen in the Officer in Charge format, scoped to whichever officer
 * was clicked [27 September 2026].
 *
 * The dialog had outgrown itself. It held two opposite acts — empty this desk,
 * fill this desk — each with its own list, its own selection and its own
 * button, plus a target, a reason, and a count that had to explain why it
 * disagreed with the OIC register. That is a screen's worth of decision inside
 * a box that darkens the thing it is deciding about, and a modal is the wrong
 * container for work a reader needs to compare against what is behind it.
 *
 * As a page it also gains what a modal cannot have: an address. An admin can
 * send "look at Ana's caseload" to a colleague, and the browser's Back button
 * means what it says.
 *
 * ── The Officer in Charge format, and why it fits ─────────────────────────
 *
 * That screen is the register of WHO HOLDS WHAT, one assignment per row:
 * business, filing number, office, holder, when, status. This is the same
 * question asked of one officer, so it is the same table — a reader moving
 * between the two is reading one thing in two scopes rather than learning two
 * layouts.
 *
 * Two tables rather than one with a flag: "what this officer holds" and "what
 * their office has not given to anyone" are answered by opposite acts, and a
 * single list would need a column to say which way each row could move.
 */

/** The two lists on this page. Each has its own selection and its own act. */
type Side = 'held' | 'free'

/**
 * How the rows are ordered, named as ORDERINGS rather than as a column and a
 * direction to combine.
 *
 * "Longest held first" is the operational question on this screen — who has
 * been sitting on what — and it is also what the server already returns, so
 * the default is a true description of an unsorted list rather than a choice
 * the page has to make on arrival.
 */
const SORTS = [
  { value: 'oldest', label: 'Longest held first' },
  { value: 'newest', label: 'Most recently assigned' },
  { value: 'business', label: 'Business (A–Z)' },
  { value: 'tracking', label: 'Tracking ID (A–Z)' },
] as const

type SortKey = (typeof SORTS)[number]['value']

/**
 * The statuses a row can carry, as words rather than as the enum's values.
 *
 * Matched case-insensitively against `status_label`, which is what the server
 * sends — there is no status CODE on a caseload row, and inventing one here to
 * match against would be a second vocabulary for one fact.
 */
const STATUSES = [
  { value: '', label: 'Any status' },
  { value: 'pending', label: 'Pending' },
  { value: 'completed', label: 'Completed' },
  { value: 'returned', label: 'Returned' },
] as const

const KINDS = [
  { value: '', label: 'Reviews and inspections' },
  { value: 'review', label: 'Application reviews' },
  { value: 'inspection', label: 'Site inspections' },
] as const

const key = (row: CaseloadCase) => `${row.kind}:${row.id}`

/**
 * What a row is called.
 *
 * The business, or the tracking ID where the business has been removed from
 * the register and its filings outlived it. Never a bare dash: this is the
 * column a reader scans, and a row that will not say what it is cannot be
 * decided about.
 */
function nameOf(row: CaseloadCase): string {
  return row.business ?? row.tracking_id ?? 'Business removed from the register'
}

/** A row's own office, which on an inspection is the visit rather than a permit. */
function officeOf(row: CaseloadCase): string {
  return row.office?.name ?? (row.kind === 'inspection' ? 'Site inspection' : '—')
}

/**
 * The tone for a row's own status.
 *
 * Not one tone per table. The held table was drawn entirely in yellow, and the
 * register answers "Completed" for a review step this office has finished — so
 * the screen showed a yellow in-progress chip reading Completed, under a
 * heading saying the officer is holding it. Three things that look like they
 * contradict each other, and none of them was wrong.
 *
 * The word comes from the server; the colour now agrees with it. See the note
 * under the section heading for why a finished step is still held.
 */
function toneFor(label: string | null): ChipTone {
  const text = (label ?? '').toLowerCase()

  if (text.includes('complete')) return 'tint-green'
  // Nothing here is red. A returned filing is waiting on the applicant, which
  // is an ordinary state of the work rather than a stop — "Red Means Stop"
  // (DESIGN.md) holds red for something being wrong, and it would be the
  // loudest thing on a page whose whole job is routine reassignment.
  if (text === '' || text.includes('not yet')) return 'tint-gray'
  return 'tint-yellow'
}

export function OfficerCaseloadPage() {
  const { userId } = useParams<{ userId: string }>()
  const id = Number(userId)

  const { data, loading, error, reload } = useAsync(() => admin.caseload(id), [id])

  /*
   * One selection per side. Held starts EMPTY, unlike the dialog it replaces,
   * which pre-ticked everything.
   *
   * The dialog's default was "this officer has gone, move their work", and
   * pre-ticking saved a dozen clicks on the sweep. On a page the sweep has its
   * own control — "Select all" below — and a screen that arrives with every
   * row already chosen makes the destructive reading the accidental one: a
   * reader who lands here to LOOK is one stray press from moving a caseload.
   */
  const [picked, setPicked] = useState<Record<Side, Set<string>>>({
    held: new Set(),
    free: new Set(),
  })

  /*
   * ── Narrowing, and where it runs ─────────────────────────────────────────
   *
   * In the BROWSER, over the rows already in hand — unlike the Officer in
   * Charge screen this page is modelled on, whose search is a server query
   * because that register is paged over thousands of assignments.
   *
   * Honest here for one reason: `/admin/users/{id}/caseload` answers with the
   * whole caseload, capped at 50 a list. There is no page two to miss, so a
   * local filter cannot hide a match the way it would there. The footer says
   * so rather than leaving a reader to assume the two screens work alike.
   */
  const [query, setQuery] = useState('')
  const [sort, setSort] = useState<SortKey>('oldest')
  const [status, setStatus] = useState('')
  const [kind, setKind] = useState('')

  const [target, setTarget] = useState('')
  const [reason, setReason] = useState('')
  /*
   * ── The act waiting to be confirmed ──────────────────────────────────────
   *
   * Both acts on this page change who is accountable for a filing, write to
   * the audit trail and notify whoever receives the work. They fired on a
   * single press [client, 27 September 2026: "sa lahat ng major decision ...
   * dapat modal na confirmation"].
   *
   * `null` means nothing pending. The side is enough to rebuild the whole
   * question — the selection, the destination and the reason are already in
   * state — so the dialog holds no copy of them and cannot drift from what
   * the page will actually send.
   */
  const [confirming, setConfirming] = useState<Side | null>(null)

  const [busy, setBusy] = useState<Side | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [done, setDone] = useState<string | null>(null)

  /*
   * Where focus goes after an act completes.
   *
   * The button that was pressed may no longer exist — moving every held case
   * empties that table and the button with it — so focus would fall to the
   * document and a screen reader would lose its place entirely. It goes to the
   * live region instead, which is the sentence describing what just happened.
   */
  const resultRef = useRef<HTMLParagraphElement>(null)
  useEffect(() => {
    if (done) resultRef.current?.focus()
  }, [done])

  const allHeld = useMemo(() => data?.cases ?? [], [data])
  const allFree = useMemo(() => data?.unassigned ?? [], [data])
  const candidates = data?.candidates ?? []

  /**
   * Search, filter and sort, applied to one list.
   *
   * Search matches the business AND the tracking ID, which are the two things
   * a filing is ever quoted by — and the field's label says both, because a
   * box that quietly matches one of them makes a correct query look like
   * missing data.
   */
  const narrow = useMemo(() => {
    const term = query.trim().toLowerCase()

    return (rows: CaseloadCase[]) => {
      const kept = rows.filter((row) => {
        if (term && !`${row.business ?? ''} ${row.tracking_id ?? ''}`.toLowerCase().includes(term)) {
          return false
        }
        if (status && !(row.status_label ?? '').toLowerCase().includes(status)) return false
        if (kind && row.kind !== kind) return false
        return true
      })

      /*
       * Sorted on a COPY. `Array.prototype.sort` is in place, and these rows
       * come straight from the async cache — sorting them where they lie
       * would reorder what the next render reads from, so the list would
       * appear to change on its own.
       */
      return [...kept].sort((a, b) => {
        if (sort === 'business') return (a.business ?? '').localeCompare(b.business ?? '')
        if (sort === 'tracking') return (a.tracking_id ?? '').localeCompare(b.tracking_id ?? '')

        /*
         * By date, with the undated last WHICHEVER way the list points. A row
         * with no assigned_at floated to the top of "longest held" would be
         * stating it had waited longest, which is the opposite of what a
         * missing date means.
         */
        const at = a.at ? Date.parse(a.at) : NaN
        const bt = b.at ? Date.parse(b.at) : NaN
        if (Number.isNaN(at) || Number.isNaN(bt)) {
          if (Number.isNaN(at) && Number.isNaN(bt)) return 0
          return Number.isNaN(at) ? 1 : -1
        }
        return sort === 'newest' ? bt - at : at - bt
      })
    }
  }, [query, status, kind, sort])

  const held = useMemo(() => narrow(allHeld), [narrow, allHeld])
  const free = useMemo(() => narrow(allFree), [narrow, allFree])

  /** Is anything narrowing the lists? Decides the empty state's wording. */
  const narrowed = query.trim() !== '' || status !== '' || kind !== ''

  /*
   * The kind filter is offered only when the rows actually differ by kind.
   *
   * A control that cannot narrow anything is a control that reads as broken
   * the first time somebody uses it: an officer holding five reviews and no
   * inspections who picks "Site inspections" gets an empty screen and no
   * explanation. Most offices only ever hold one kind.
   */
  const mixedKinds = useMemo(() => {
    const kinds = new Set([...allHeld, ...allFree].map((row) => row.kind))
    return kinds.size > 1
  }, [allHeld, allFree])

  function toggle(side: Side, row: CaseloadCase) {
    setPicked((prev) => {
      const next = new Set(prev[side])
      if (next.has(key(row))) next.delete(key(row))
      else next.add(key(row))
      return { ...prev, [side]: next }
    })
  }

  function toggleAll(side: Side, rows: CaseloadCase[]) {
    setPicked((prev) => ({
      ...prev,
      [side]: prev[side].size === rows.length ? new Set() : new Set(rows.map(key)),
    }))
  }

  /*
   * The acts read from the NARROWED list, not from everything loaded.
   *
   * A row ticked and then filtered off the screen would otherwise still move,
   * which is the worst kind of surprise: the reader cannot see what they are
   * about to do. Narrowing the source here means the count beside the button
   * and the rows it acts on are always the same set.
   */

  /** Move the ticked cases to the named officer, or back to the office queue. */
  async function move() {
    if (picked.held.size === 0 || !reason.trim() || busy) return

    setBusy('held')
    setFormError(null)
    try {
      const result = await admin.reassignCaseload(id, {
        to_user_id: target ? Number(target) : null,
        cases: held.filter((row) => picked.held.has(key(row))).map((row) => ({ kind: row.kind, id: row.id })),
        reason: reason.trim(),
      })
      setDone(
        `${result.total} ${result.total === 1 ? 'filing is' : 'filings are'} now with ${
          result.to?.name ?? `the ${data?.department?.code ?? 'office'} queue`
        }.`,
      )
      setPicked((prev) => ({ ...prev, held: new Set() }))
      setReason('')
      setConfirming(null)
      reload()
    } catch (err) {
      /*
       * The dialog STAYS open on a failure, carrying the message. Closing it
       * would put the reader back on the table with an error above it and no
       * indication that their selection survived — and the commonest failure
       * here is a race (somebody else moved the filing), where the next thing
       * they want is to read what happened and press Cancel.
       */
      setFormError(toApiError(err).message)
    } finally {
      setBusy(null)
    }
  }

  /** Hand the ticked unheld cases to this officer. */
  async function take() {
    if (picked.free.size === 0 || busy) return

    setBusy('free')
    setFormError(null)
    try {
      const result = await admin.takeCases(id, {
        cases: free
          .filter((row) => picked.free.has(key(row)))
          .map((row) => ({ kind: 'review' as const, id: row.id })),
        reason: reason.trim() || 'Taken from the office queue.',
      })
      setDone(
        `${result.total} ${result.total === 1 ? 'filing is' : 'filings are'} now with ${result.to.name}.`,
      )
      setPicked((prev) => ({ ...prev, free: new Set() }))
      setConfirming(null)
      reload()
    } catch (err) {
      setFormError(toApiError(err).message)
    } finally {
      setBusy(null)
    }
  }

  const officer = data?.user.name ?? 'this officer'
  const office = data?.department

  /*
   * The table, drawn once and used for both lists.
   *
   * Same columns as the Officer in Charge register, in the same order, minus
   * the holder — on this page every row in the first table has the same holder
   * and every row in the second has none, so a column repeating one word down
   * the page would be spending the reader's attention on a constant.
   */
  const table = (side: Side, rows: CaseloadCase[], caption: string, total: number) => {
    const chosen = picked[side]
    const allOn = chosen.size === rows.length && rows.length > 0

    return (
      <ProtoCard className="overflow-hidden">
        <div className="flex flex-wrap items-baseline justify-between gap-3 border-b border-line px-5 py-3">
          <h2 className="text-sm font-bold text-ink">{caption}</h2>
          {/*
            The count of what is ticked, in the heading rather than only beside
            the button. A reader scrolled to the bottom of a long table needs to
            know what they have selected without scrolling back, and "3 of 12
            selected" is the one fact both ends of the page need.
          */}
          {/*
            Two different counts, and which one is shown depends on what the
            reader is doing. Mid-selection the useful number is what is ticked;
            otherwise it is how many rows there are — and, when something is
            narrowing the list, how many there are ALTOGETHER, so a short table
            is never mistaken for a short caseload.
          */}
          <p role="status" className="text-xs text-ink-muted">
            {chosen.size > 0
              ? `${chosen.size} of ${rows.length} selected`
              : narrowed
                ? `${rows.length} of ${total} filing${total === 1 ? '' : 's'}`
                : `${rows.length} filing${rows.length === 1 ? '' : 's'}`}
          </p>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full min-w-[48rem] text-left text-sm">
            <thead>
              <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                <th scope="col" className="px-5 py-3">
                  <label className="flex cursor-pointer items-center gap-2 normal-case">
                    <input
                      type="checkbox"
                      checked={allOn}
                      onChange={() => toggleAll(side, rows)}
                      className="h-4 w-4 accent-royal"
                    />
                    {/*
                      A real label, not a bare checkbox in a header. "Select
                      all" announced as an unnamed checkbox in a column header
                      is the classic table-selection trap; the word is here and
                      visible, because it is also the fastest control on the
                      page for the sweep.
                    */}
                    <span className="text-[11px] font-semibold uppercase tracking-wider">All</span>
                  </label>
                </th>
                <th scope="col" className="px-5 py-3">Business</th>
                <th scope="col" className="px-5 py-3">Tracking ID</th>
                <th scope="col" className="px-5 py-3">Office</th>
                <th scope="col" className="px-5 py-3">Permit</th>
                <th scope="col" className="px-5 py-3">{side === 'held' ? 'Assigned' : 'Waiting since'}</th>
                {/*
                  Two statuses, two columns — the same split the Officer in
                  Charge register uses, so a reader moving between them is
                  reading one idea in two places rather than two layouts.
                  
                  One column said "Status" and showed the office's own review
                  STEP. An administrator then saw "Completed" on a row they
                  were being asked to reassign and could not tell why it was
                  still there. The filing's state is the answer, and it was not
                  on screen.
                */}
                <th scope="col" className="px-5 py-3">Review step</th>
                <th scope="col" className="px-5 py-3">Filing</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const on = chosen.has(key(row))
                return (
                  <tr
                    key={key(row)}
                    className={`border-t border-line align-top ${on ? 'bg-royal-tint/40' : ''}`}
                  >
                    <td className="px-5 py-3.5">
                      <label className="flex cursor-pointer items-center">
                        <input
                          type="checkbox"
                          checked={on}
                          onChange={() => toggle(side, row)}
                          /*
                            Named by the filing it selects. Twelve checkboxes
                            reading "select" are twelve identical stops for a
                            screen reader (AGENTS.md §6.2).
                          */
                          aria-label={`Select ${nameOf(row)}${row.tracking_id ? `, ${row.tracking_id}` : ''}`}
                          className="h-4 w-4 accent-royal"
                        />
                      </label>
                    </td>
                    <td className="px-5 py-3.5 font-semibold text-ink">{nameOf(row)}</td>
                    <td className="tnum px-5 py-3.5 text-ink-secondary">{row.tracking_id ?? '—'}</td>
                    <td className="px-5 py-3.5 text-ink-secondary">{officeOf(row)}</td>
                    <td className="px-5 py-3.5 text-ink-secondary">{row.permit ?? '—'}</td>
                    <td className="px-5 py-3.5 text-ink-secondary">
                      {row.at ? formatDateTime(row.at) : '—'}
                    </td>
                    <td className="px-5 py-3.5">
                      <StatusChip tone={toneFor(row.status_label)}>
                        {row.status_label ?? (side === 'held' ? 'In progress' : 'Not yet taken')}
                      </StatusChip>
                    </td>
                    <td className="px-5 py-3.5">
                      {/*
                        Plain text, not a second chip. Two chips in adjacent
                        cells read as one status shown twice — which is exactly
                        the confusion this column exists to end.
                      */}
                      <span className="text-sm text-ink-secondary">
                        {row.application_status_label ?? '—'}
                      </span>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </ProtoCard>
    )
  }

  return (
    <div>
      {/*
        ── One way back, above the title, on the left ───────────────────────

        There were two: a pill in the header's right slot and a button at the
        foot of the page. Two controls for one act, and they did not even do
        the same thing — the footer one called `navigate(-1)`, which is the
        browser's HISTORY rather than a destination. A reader who arrived by a
        pasted link, or through a redirect, would have been sent somewhere
        else, or nowhere.

        So: one control, and it is the link. A link has an href — it can be
        middle-clicked, opened in a new tab, and is announced as a link — and
        it goes to Officer Assignment whether or not that is where the reader
        came from.

        Above the title and on the left, which is the shape this app already
        uses for exactly this (`PayPage`: "Back to application"). The header's
        right slot was the wrong home twice over: a left-pointing arrow on the
        far right contradicts itself, and that slot is where every other screen
        puts the controls that ACT on a page rather than leave it.

        TWO segments up: this page is at `…/users/:userId/reassign`, so `..`
        lands on `…/users/:userId`, which is not a route. A browser test caught
        that by reading the href rather than only checking the link was there.
        `relative="path"` keeps it right on both portals without this page
        knowing which one it is on.
      */}
      <Link
        to="../.."
        relative="path"
        className="mb-5 inline-flex items-center gap-1.5 text-sm font-semibold text-royal hover:underline"
      >
        <ArrowLeftIcon size={16} /> Back to Officer Assignment
      </Link>

      <PageTitle
        right={
          /*
            Search and the Sort/Filter pair, in the same place and the same
            order the Officer in Charge screen puts them — this page is that
            screen scoped to one officer, so a reader moving between the two
            should find the controls where they left them.

            Drawn only once there is something to narrow. On an officer holding
            nothing they would be three controls over an empty screen, each of
            which can only produce the emptiness already there.
          */
          data && (allHeld.length > 0 || allFree.length > 0) ? (
            <span className="flex flex-wrap items-center gap-3">
              <input
                type="search"
                /*
                  Named for BOTH things it matches. A box that quietly matches
                  one of them makes a correct query look like missing data —
                  the same reasoning that put all six identifiers in the
                  Permits search label.
                */
                aria-label={`Search ${officer}’s caseload by business or tracking ID`}
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Business or tracking ID"
                className="w-64 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
              />
              <SortFilter
                sort={{
                  value: sort,
                  options: SORTS.map(({ value, label }) => ({ value, label })),
                  onChange: (v: string) => setSort(v as SortKey),
                }}
                filter={{
                  value: status,
                  options: STATUSES.map(({ value, label }) => ({ value, label })),
                  onChange: (v: string) => setStatus(v),
                }}
                /*
                  Kind only when the rows differ by kind. Most offices hold one
                  or the other, and a control that cannot narrow anything reads
                  as broken the first time somebody uses it.
                */
                filterFields={
                  mixedKinds
                    ? [
                        {
                          label: 'Kind',
                          value: kind,
                          options: KINDS.map(({ value, label }) => ({ value, label })),
                          onChange: (v: string) => setKind(v),
                        },
                      ]
                    : undefined
                }
              />
            </span>
          ) : undefined
        }
      >
        {loading && !data ? 'Caseload' : `${officer}’s caseload`}
      </PageTitle>

      {/*
        Whose desk this is, said once under the title. The office matters as
        much as the name: everything this page can do is bounded by it — the
        colleagues a case can go to, and the queue a case can come from.
      */}
      {data && (
        <p className="-mt-2 mb-5 text-sm text-ink-muted">
          {office ? office.name : 'No office'}
          {data.finished_reviews ? (
            <>
              {' · '}
              {/*
                Why this page and the Officer in Charge register can disagree.
                That screen lists every assignment a name is on, finished ones
                included; this one can only move what is still open. An admin
                reading "officer in charge of two filings" over there and an
                empty table here is reading two true sentences.
              */}
              <span>
                also named on {data.finished_reviews} finished review
                {data.finished_reviews === 1 ? '' : 's'}, which stay
              </span>
            </>
          ) : null}
        </p>
      )}

      {/*
        The result of the last act, and the one thing on this page that must
        reach a screen reader whether or not it is looked at. `tabIndex={-1}`
        so focus can be moved here when the control that was pressed is gone.
      */}
      <p
        ref={resultRef}
        tabIndex={-1}
        role="status"
        aria-live="polite"
        className={done ? 'mb-4 rounded-lg bg-s-green-tint px-4 py-3 text-sm font-medium text-s-green-ink focus:outline-none' : 'sr-only'}
      >
        {done ?? ''}
      </p>

      {/*
        Hidden while a dialog is open, because the dialog carries the same
        message itself. Two live `role="alert"` regions holding one sentence
        reads it twice to a screen reader, and the page-level copy sits behind
        the overlay where nobody can see it anyway.
      */}
      {formError && confirming === null && (
        <p role="alert" className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
          {formError}
        </p>
      )}

      {loading && !data ? (
        <SkeletonList rows={5} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : !data ? null : (
        <div className="space-y-8">
          {/* ── What this officer is holding ──────────────────────────── */}
          <section>
            {held.length === 0 ? (
              /*
                Two different emptinesses, and a reader has to be able to tell
                them apart.

                "Liza is not holding any open work" in front of somebody who
                has just typed a search is a FALSE statement about the officer
                — the caseload is there, the search simply missed it — and it
                points at the wrong thing to fix. A driver probe caught it
                saying exactly that over a caseload of two.
              */
              <EmptyState
                icon={UsersIcon}
                title={
                  narrowed && allHeld.length > 0
                    ? 'No filing here matches'
                    : `${officer} is not holding any open work`
                }
                description={
                  narrowed && allHeld.length > 0
                    ? `${officer} is holding ${allHeld.length} filing${allHeld.length === 1 ? '' : 's'}, and none of them matches the search or filters above. Clear them to see the whole caseload.`
                    : "There is nothing to move. Work reaches an officer when they claim it from their office's queue, or when the Super Administrator hands it to them below."
                }
              />
            ) : (
              <>
                {/*
                  Why a row can read "Completed" and still be here.

                  A case is held while the FILING is live, not while one step
                  is open — the officer-in-charge rule, settled on 21 September
                  2026. So an office that has finished its own review is still
                  the officer in charge of the filing, and still the person a
                  question about it goes to. Without this sentence the table
                  contradicts itself in front of the reader.
                */}
                {/*
                  The same two-statuses confusion the register carries, said in
                  the one place this screen shows only one of them.
                  
                  The Status column here is the office's own REVIEW STEP. The
                  reason the row is on this page at all is the FILING being
                  live, which is not a column — there is no room for one beside
                  six others, and every row on this page shares the answer
                  anyway for a given filing. So it is a sentence instead.
                */}
                <p className="mb-2 text-xs text-ink-muted">
                  Status below is this office’s own review step. A filing stays with its officer in
                  charge until the <span className="font-semibold">whole filing</span> is decided —
                  Completed, Rejected or Cancelled — so a step marked{' '}
                  <span className="font-semibold">Completed</span> is still theirs to answer for,
                  and still moves from here.
                </p>
                {table('held', held, `Work ${officer} is holding`, allHeld.length)}

                {data.total > held.length && (
                  <p className="mt-2 text-xs text-ink-muted">
                    Showing the {held.length} oldest of {data.total}. Only the listed ones can be
                    moved from here; Deactivate on the Officer Assignment page releases the whole
                    caseload at once.
                  </p>
                )}

                {/*
                  The act, under the table it acts on. Its own fieldset because
                  the three controls answer one question together — what moves,
                  where to, and why — and a reader tabbing through them should
                  be told that once rather than guess it from proximity.
                */}
                <fieldset className="mt-4 rounded-xl border border-line bg-white p-5">
                  <legend className="px-2 text-sm font-bold text-ink">Move the selected filings</legend>

                  <div className="grid gap-4 sm:grid-cols-2">
                    <label className="block">
                      <span className="mb-1.5 block text-xs font-semibold text-ink-secondary">
                        Move to
                      </span>
                      <select
                        value={target}
                        onChange={(e) => setTarget(e.target.value)}
                        className="w-full rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
                      >
                        {/*
                          Releasing is the DEFAULT, not the fallback. Most
                          offices in the register are one officer deep, so most
                          of the time there is nobody to name — and putting a
                          case back in the office queue is the state it starts
                          in, visible to whoever the office next staffs.
                        */}
                        <option value="">Leave unassigned — back to the office queue</option>
                        {candidates.map((c) => (
                          <option key={c.id} value={c.id}>
                            {c.name} · holding {c.open_total}
                          </option>
                        ))}
                      </select>
                      {candidates.length === 0 && (
                        <span className="mt-1 block text-xs text-ink-muted">
                          {office
                            ? `No other active officer in ${office.code}, so these can only go back to the office queue.`
                            : 'This account belongs to no office, so there is nobody to hand work to.'}
                        </span>
                      )}
                    </label>

                    <label className="block">
                      <span className="mb-1.5 block text-xs font-semibold text-ink-secondary">
                        Reason <span className="text-s-red">(required)</span>
                      </span>
                      <input
                        type="text"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="e.g. Officer on extended leave"
                        className="w-full rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
                      />
                      <span className="mt-1 block text-xs text-ink-muted">
                        Recorded in the audit trail against every filing that moves, and sent to
                        whoever receives them.
                      </span>
                    </label>
                  </div>

                  <div className="mt-4 flex flex-wrap items-center gap-3">
                    <button
                      type="button"
                      onClick={() => {
                        setFormError(null)
                        setConfirming('held')
                      }}
                      /*
                        aria-disabled, never the native attribute (AGENTS.md
                        §6.2): a disabled button leaves the tab order, so a
                        keyboard reader cannot reach the control to find out
                        why it will not act. The sentence beside it says why.
                      */
                      aria-disabled={picked.held.size === 0 || !reason.trim() || busy !== null || undefined}
                      className="rounded-full bg-royal px-5 py-2 text-sm font-semibold text-white hover:bg-royal-hover aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                    >
                      {busy === 'held'
                        ? 'Moving…'
                        : target
                          ? `Move ${picked.held.size || ''} to ${candidates.find((c) => String(c.id) === target)?.name ?? 'officer'}`.replace('  ', ' ')
                          : `Release ${picked.held.size || ''} to the office queue`.replace('  ', ' ')}
                    </button>

                    {/*
                      Why the button will not act, in words, beside it. An
                      inert-looking control with no explanation is the thing a
                      reader reports as broken.
                    */}
                    {(picked.held.size === 0 || !reason.trim()) && (
                      <p className="text-xs text-ink-muted">
                        {picked.held.size === 0
                          ? 'Tick at least one filing above.'
                          : 'Give a reason first.'}
                      </p>
                    )}
                  </div>
                </fieldset>
              </>
            )}
          </section>

          {/* ── What nobody is holding ────────────────────────────────── */}
          {allFree.length > 0 && (
            <section>
              {/*
                The section stays when a filter empties it, rather than
                disappearing. A whole block of the page vanishing on a
                keystroke is disorienting, and it would leave a reader who has
                typed a search believing the office queue is empty when it is
                their own filter hiding it.
              */}
              {free.length === 0 ? (
                <EmptyState
                  icon={UsersIcon}
                  title="Nothing unassigned matches"
                  description={`${office?.code ?? 'This office'} has ${allFree.length} filing${allFree.length === 1 ? '' : 's'} nobody is holding, and none of them matches the search or filters above.`}
                />
              ) : (
                table('free', free, `Unassigned in ${office?.code ?? 'this office'}`, allFree.length)
              )}

              {/* The act goes with its rows: no rows on screen, nothing to act on. */}
              <div className={`mt-4 flex flex-wrap items-center gap-3 ${free.length === 0 ? 'hidden' : ''}`}>
                <button
                  type="button"
                  onClick={() => {
                    setFormError(null)
                    setConfirming('free')
                  }}
                  aria-disabled={picked.free.size === 0 || busy !== null || undefined}
                  className="rounded-full bg-royal px-5 py-2 text-sm font-semibold text-white hover:bg-royal-hover aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                >
                  {busy === 'free'
                    ? 'Assigning…'
                    : `Assign ${picked.free.size || ''} to ${officer}`.replace('  ', ' ')}
                </button>
                {picked.free.size === 0 && (
                  <p className="text-xs text-ink-muted">Tick at least one filing above.</p>
                )}
              </div>
            </section>
          )}

          {/*
            -- Confirming the two acts that move work ----------------------

            Both change who is accountable for a filing, write to the audit
            trail and put the work in somebody else's list. They used to fire
            on a single press, beside a checkbox column where a stray click is
            easy [client, 27 September 2026: "sa lahat ng major decision ...
            dapat modal na confirmation"].

            BLUE, not red. DESIGN.md's "Red Means Stop" keeps red for acts that
            destroy something or stop somebody working - Deactivate, on the
            officer directory, is the red one. A reassignment is undone by
            doing it again, and dressing it as danger spends the one alarm this
            app has on an ordinary Tuesday.

            And it NAMES what is about to happen - the count, the destination,
            the filings themselves - rather than asking "are you sure". A
            dialog that only asks trains the reader to press Confirm without
            reading it, which is worse than not asking.
          */}
          {confirming !== null && (
            <ProtoModal
              title={confirming === 'held' ? 'Move these filings?' : 'Assign these filings?'}
              tone="blue"
              cancelLabel="Cancel"
              /*
                The confirm button repeats the ACT, not "OK". It is the last
                thing read before committing, so it is where the verb and the
                destination belong.
              */
              confirmLabel={
                busy !== null
                  ? 'Working…'
                  : confirming === 'held'
                    ? target
                      ? `Move to ${candidates.find((c) => String(c.id) === target)?.name ?? 'officer'}`
                      : 'Release to the queue'
                    : `Assign to ${officer}`
              }
              onCancel={() => {
                setConfirming(null)
                setFormError(null)
              }}
              onConfirm={() => void (confirming === 'held' ? move() : take())}
              confirmDisabled={busy !== null}
            >
              <div className="space-y-4">
                <p className="text-sm text-ink">
                  {confirming === 'held' ? (
                    <>
                      <span className="font-bold">{picked.held.size}</span>{' '}
                      {picked.held.size === 1 ? 'filing' : 'filings'} will leave{' '}
                      <span className="font-bold">{officer}</span>
                      {target ? (
                        <>
                          {' '}
                          and become{' '}
                          <span className="font-bold">
                            {candidates.find((c) => String(c.id) === target)?.name ?? 'the officer'}
                          </span>
                          ’s.
                        </>
                      ) : (
                        <>
                          {' '}
                          and go back to the {office?.code ?? 'office'} queue, with{' '}
                          <span className="font-bold">nobody holding them</span> until someone
                          takes them.
                        </>
                      )}
                    </>
                  ) : (
                    <>
                      <span className="font-bold">{picked.free.size}</span>{' '}
                      {picked.free.size === 1 ? 'filing' : 'filings'} from the{' '}
                      {office?.code ?? 'office'} queue will become{' '}
                      <span className="font-bold">{officer}</span>’s.
                    </>
                  )}
                </p>

                {/*
                  The filings, named.

                  The point of confirming is that the reader can check they
                  ticked what they meant to, and a bare count cannot be checked
                  against anything. Eight, then a tally: a dialog listing forty
                  rows is a second table, and past a handful nobody reads them
                  one by one.
                */}
                <ul className="max-h-48 space-y-1.5 overflow-y-auto rounded-lg border border-line bg-canvas px-4 py-3">
                  {(confirming === 'held' ? held : free)
                    .filter((row) => picked[confirming].has(key(row)))
                    .slice(0, 8)
                    .map((row) => (
                      <li key={key(row)} className="text-sm text-ink">
                        <span className="font-semibold">{nameOf(row)}</span>
                        <span className="tnum text-ink-muted"> · {row.tracking_id ?? '—'}</span>
                      </li>
                    ))}
                  {picked[confirming].size > 8 && (
                    <li className="text-xs text-ink-muted">
                      and {picked[confirming].size - 8} more
                    </li>
                  )}
                </ul>

                {confirming === 'held' && (
                  /*
                    The reason is shown back, not asked again. It was typed on
                    the page a moment ago; re-asking here would make this a
                    second form rather than a confirmation, and a reader who
                    wants to change it can Cancel and edit the field they used.
                  */
                  <p className="text-sm text-ink-secondary">
                    Reason on the record: <span className="italic">“{reason.trim()}”</span>
                  </p>
                )}

                {/*
                  What follows. Reversibility is the fact that makes this a
                  blue dialog rather than a red one, so it is stated rather
                  than left for the reader to assume.
                */}
                <p className="text-xs text-ink-muted">
                  Written to the audit trail against every filing that moves. You can undo this by
                  moving them back.
                </p>

                {formError && (
                  <p
                    role="alert"
                    className="rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red"
                  >
                    {formError}
                  </p>
                )}
              </div>
            </ProtoModal>
          )}

          {narrowed && (
            /*
              Where the narrowing runs, said plainly.

              The Officer in Charge screen this page is modelled on searches on
              the SERVER, because that register is paged over thousands of
              assignments. This endpoint answers with the whole caseload —
              capped at 50 a list — so the filtering is local, and there is no
              page two for a match to hide on. Saying so is what stops a reader
              assuming the two screens behave alike.
            */
            <p role="status" className="text-xs text-ink-muted">
              Searching and filtering this officer’s own caseload, which is loaded in full.
            </p>
          )}
        </div>
      )}
    </div>
  )
}
