import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { MessageThreadView } from '../../components/MessagesPanel'
import { UsersIcon, XIcon } from '../../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { FilterPills, PageTitle } from '../../components/ui/Proto'
import { formatDateTime, formatListStamp, initialsOf } from '../../lib/format'
import { messages as messagesApi } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { StaffMessageRow } from '../../lib/types'

/*
 * ── Where the offices reach the System Administrator ──────────────────────
 *
 * Office accounts cannot edit their own details: no Settings, no "Edit your
 * details". Their name, mobile number, office and role are this seat's to set
 * [client, 28 September 2026] — the officer directory is the register's record
 * of who staffs which office, and a record people can quietly edit about
 * themselves is not one.
 *
 * That leaves them needing somewhere to ask, and this seat needing somewhere
 * to read it. The officer's end is a conversation pinned to the top of their
 * Messages; this is the other end of the same thread.
 *
 * ── Why it is not the ordinary Messages page ──────────────────────────────
 *
 * The super admin does not hold `message.participate`, and should not: that
 * permission opens every filing conversation in the city, and what this seat
 * needs is the handful about accounts. So this screen is gated on
 * `user.manage` — the permission that already means "this account administers
 * the others" — and reads one endpoint that answers only those threads.
 */

/*
 * The two narrowings live at the call site now, because one of them carries
 * the unread COUNT and a constant cannot.
 *
 * There was a third, "Has written", and it earned its place on neither
 * screen: the list already sorts anybody who has written above everybody who
 * has not, so the filter hid rows without answering a question the ORDER had
 * not already answered [client, 28 September 2026].
 */


export function StaffMessagesPage() {
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [narrow, setNarrow] = useState<'all' | 'unread'>('all')

  // Let the reader finish typing before asking the server.
  useEffect(() => {
    const id = window.setTimeout(() => setQuery(search.trim()), 300)
    return () => window.clearTimeout(id)
  }, [search])

  const { data, loading, error, reload } = useAsync(
    () =>
      messagesApi.staffThreads({
        q: query || undefined,
        narrow: narrow === 'all' ? undefined : narrow,
      }),
    [query, narrow],
  )

  const rows = data ?? []

  /*
   * Which conversation is open lives in the URL, so a thread can be linked to
   * and survives a reload — and so the notification that says "Liza Reyes sent
   * you a message" can land on the message rather than on the list.
   */
  const openedId = Number(params.get('officer')) || null
  const opened = rows.find((r) => r.user.id === openedId) ?? null

  const unread = rows.reduce((n, r) => n + r.unread_count, 0)

  return (
    /*
     * ── The page does not scroll; the panes do ─────────────────────────────
     *
     * A conversation screen that scrolls as a whole is the wrong shape: the
     * reader loses the composer off the bottom while looking for an officer,
     * and the list of officers slides away while they read a reply. Every
     * messaging app people already use — Messenger among them — pins the
     * chrome and scrolls the two columns independently, which is what this
     * does [client, 28 September 2026].
     *
     * The height is taken from the viewport rather than from the content:
     * `100dvh` less the shell's own padding (`pt-8` plus `pb-28` on a phone,
     * `lg:pb-16` on a desktop). `dvh` rather than `vh` because a phone's
     * address bar shrinks and grows as you scroll, and `vh` measures the tall
     * state — which puts the composer under the browser's own furniture.
     *
     * `min-h-0` on every flex child that contains a scroller: without it a
     * flex item's automatic minimum is its content, so the child grows to fit
     * the whole transcript and the page scrolls after all. It is the single
     * thing most often missing when a layout like this "nearly" works.
     */
    <div className="flex h-[calc(100dvh-9rem)] flex-col overflow-hidden lg:h-[calc(100dvh-6rem)]">
      <PageTitle
        right={
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search officer or email…"
            aria-label="Search office accounts"
            className="w-56 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
          />
        }
      >
        Office Messages
      </PageTitle>

      {/*
        Header: fixed, and one line instead of three.

        The explanation and the pills used to sit on separate rows with a third
        of margin between them, which on a screen whose whole point is the two
        panes below spent ~90px saying very little. They share a line now, and
        the line wraps rather than stacks on a phone.
      */}
      <div className="mb-4 shrink-0">
        {/*
          The pills alone. A sentence explaining that office accounts cannot
          edit their own details sat here and was removed [client, 28 September
          2026] — the screen is called Office Messages and lists officers, and
          a reader who has reached it does not need to be told what it is for.

          The unread FIGURE moved onto the control that acts on it. It used to
          be a clause at the end of that sentence, which is the wrong place: a
          count is worth having where you would press to see them.
        */}
        <FilterPills
          options={[
            { value: 'all' as const, label: 'All officers' },
            { value: 'unread' as const, label: unread > 0 ? `Unread (${unread})` : 'Unread' },
          ]}
          value={narrow}
          onChange={setNarrow}
        />
      </div>

      {/*
        Two panes on a wide screen, one on a phone — the same shape the
        applicant's Messages page has, because it is the same job: a list on
        the left, the conversation on the right.

        `minmax(0,1fr)` at every width: a grid item's automatic minimum is its
        min-content, and an officer's name is one nowrap line, so the single
        mobile column would size itself to the longest one.
      */}
      <div className="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
        {/*
          The left column scrolls on its own, and keeps a little padding at the
          foot so the last officer does not sit flush against the edge.

          On a phone it gives way entirely once a conversation is open: two
          panes do not fit, and the one being read is the one that matters.
        */}
        <div
          className={`min-h-0 overflow-y-auto pb-2 ${opened ? 'hidden lg:block' : ''}`}
        >
          {loading && rows.length === 0 ? (
            <SkeletonList rows={6} />
          ) : error ? (
            <ErrorState error={error} onRetry={reload} />
          ) : rows.length === 0 ? (
            <EmptyState
              icon={UsersIcon}
              title={query ? 'No officer matches your search' : 'No office accounts yet'}
              description={
                query
                  ? 'Try another name or email address.'
                  : 'Officers appear here as soon as their accounts are created, whether or not they have written.'
              }
            />
          ) : (
            /*
              ONE surface, divided — not a stack of floating cards.

              Nine cards with 10px of canvas between them reads as nine
              unrelated things; a conversation list is one thing with rows in
              it, which is why every messaging app draws it that way. It also
              buys back ~90px of the column, which on a scrolling list is a
              whole extra officer.
            */
            <ul className="divide-y divide-line overflow-hidden rounded-xl bg-white shadow-card">
              {rows.map((row) => (
                <li key={row.user.id}>
                  <OfficerRow
                    row={row}
                    open={row.user.id === openedId}
                    onOpen={() => setParams({ officer: String(row.user.id) })}
                  />
                </li>
              ))}
            </ul>
          )}
        </div>

        {opened ? (
          <section className="flex min-h-0 flex-col overflow-hidden rounded-xl bg-white shadow-card">
            <header className="flex items-start justify-between gap-4 border-b border-line px-5 py-3.5">
              <div className="min-w-0">
                <h2 className="truncate text-base font-bold text-ink">{opened.user.name}</h2>
                <p className="truncate text-xs text-ink-muted">
                  {opened.office ? `${opened.office.code} — ${opened.office.name}` : 'No office'}
                  {' · '}
                  {opened.user.email}
                </p>
              </div>
              <button
                type="button"
                onClick={() => setParams({})}
                aria-label="Close conversation"
                className="shrink-0 rounded-md p-1 text-royal hover:bg-royal-tint"
              >
                <XIcon size={22} />
              </button>
            </header>

            <MessageThreadView
              // Keyed by the officer, so switching conversations remounts
              // rather than showing the previous transcript while the next one
              // loads.
              key={opened.user.id}
              target={{ kind: 'admin', userId: opened.user.id }}
              counterpartyName={opened.user.name}
              className="flex-1 px-5 pb-5 pt-4"
              scrollClassName="min-h-0"
              onSent={reload}
            />
          </section>
        ) : (
          <div className="hidden min-h-0 items-center justify-center rounded-xl bg-white p-10 shadow-card lg:flex">
            <p className="text-sm text-ink-secondary">
              Choose an officer on the left to read and reply.
            </p>
          </div>
        )}
      </div>
    </div>
  )
}

/**
 * One officer in the list.
 *
 * ── What the row has to say, in the order it is read ──────────────────────
 *
 * An avatar to find the person by, their name, the office under it, and the
 * last thing said. A reader scanning for "who was I talking to" recognises a
 * shape before they read a word, which is why every messaging app leads with
 * one — and here the initials do it without a photo the register does not
 * hold.
 *
 * ── Unread is carried by weight, not only by colour ───────────────────────
 *
 * An unread row is set in ink rather than grey and its name is bolder, so the
 * difference survives a monochrome screen and a reader who cannot pick the red
 * badge out. The badge is the count; the weight is the state.
 *
 * An officer who has never written still gets a row, saying so — the super
 * admin has to be able to START a conversation, which is how "your details
 * have been updated" reaches the person who asked.
 */
function OfficerRow({
  row,
  open,
  onOpen,
}: {
  row: StaffMessageRow
  open: boolean
  onOpen: () => void
}) {
  const quiet = row.messages_count === 0
  const unread = row.unread_count > 0

  return (
    <button
      type="button"
      onClick={onOpen}
      aria-current={open ? 'true' : undefined}
      /*
        The selected row is marked on its LEFT EDGE as well as by its fill.
        A tint alone is a light wash on a white list, and on the one row that
        matters it should be unmistakable at a glance down the column.
      */
      className={`flex w-full items-start gap-3 border-l-[3px] px-4 py-3 text-left transition-colors ${
        open ? 'border-royal bg-royal-tint' : 'border-transparent hover:bg-canvas/60'
      }`}
    >
      <span
        aria-hidden="true"
        className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-bold ${
          unread ? 'bg-royal text-white' : 'bg-canvas text-ink-secondary'
        }`}
      >
        {initialsOf(row.user.name)}
      </span>

      <span className="min-w-0 flex-1">
        <span className="flex items-baseline justify-between gap-2">
          <span
            className={`truncate text-sm text-ink ${unread ? 'font-bold' : 'font-semibold'}`}
          >
            {row.user.name}
          </span>
          {/*
            The time sits with the name, where a reader looks for it, rather
            than on a line of its own at the foot of the row — which cost a
            line per row down the whole column.
          */}
          {row.last_message_at && (
            <span
              className="shrink-0 text-[11px] text-ink-muted"
              title={formatDateTime(row.last_message_at)}
            >
              {formatListStamp(row.last_message_at)}
            </span>
          )}
        </span>

        <span className="mt-0.5 block truncate text-xs text-ink-muted">
          {row.office ? `${row.office.code} — ${row.office.name}` : 'No office'}
        </span>

        <span className="mt-1 flex items-center gap-2">
          <span
            className={`min-w-0 flex-1 truncate text-xs ${
              quiet ? 'italic text-ink-muted' : unread ? 'font-medium text-ink' : 'text-ink-secondary'
            }`}
          >
            {quiet ? (
              'Nothing said yet — you can start'
            ) : (
              <>
                {row.last_sender && <span className="font-semibold">{row.last_sender}: </span>}
                {row.preview}
              </>
            )}
          </span>

          {/*
            The count, only when there is one. A permanent "0" beside every
            officer teaches the eye to skip the column that matters.
          */}
          {/*
            Royal, not red. Red on this palette means STOP - a rejection, a
            blocked action, something that has gone wrong (DESIGN.md, "Red
            Means Stop"). Unread mail is none of those; it is the ordinary
            state of a message nobody has opened yet, and dressing it as an
            alarm spends the one colour that should still startle somebody.
            It matches the applicant's inbox, which never made that mistake.
          */}
          {unread && (
            <span className="tnum shrink-0 rounded-full bg-royal px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">
              {row.unread_count}
              <span className="sr-only"> unread</span>
            </span>
          )}
        </span>
      </span>
    </button>
  )
}
