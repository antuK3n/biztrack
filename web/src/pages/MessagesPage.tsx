import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { MailIcon, SearchIcon, XIcon } from '../components/icons'
import { MessageThreadView } from '../components/MessagesPanel'
import { EmptyState, ErrorState, SkeletonList } from '../components/ui/primitives'
import { PageTitle, SortFilter } from '../components/ui/Proto'
import { formatDate } from '../lib/format'
import { messages as messagesApi } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import { useAuth } from '../stores/auth'
import type { MessageThreadSummary } from '../lib/types'

/*
 * Messages (revised GUI screens 8-10 applicant, 101-102 staff): the dedicated
 * page the in-application panel was standing in for. Conversation list on the
 * left — search, sort, one card per application — and the open thread on the
 * right behind its light-blue name bar. Both sides read the same screen; the
 * conversation is named after whoever the reader is talking to.
 *
 * The responsible office (checklist item 73) is stated outright rather than
 * inferred. The counterparty line answers "who wrote to me last", which drifts
 * as different officers reply and says nothing at all before anybody has; an
 * applicant asking which office holds their permit got no answer from it. The
 * API resolves ONE office per thread — see MessageController::responsibleOffice
 * — because a filing is routed to every office that issues one of its
 * clearances, and listing four of them is not an answer either.
 *
 * ── The list pages by FILING, the pane picks the OFFICE ──────────────────────
 *
 * A conversation is with an office now, so a filing routed to four of them has
 * up to four. The left-hand list still shows one card per filing and the office
 * choice lives in the pane (MessageThreadView's picker), for two reasons: a
 * filing NOBODY has written on still needs a card, because that card is how an
 * applicant starts their first conversation and it cannot come from a list of
 * threads that do not exist; and an applicant thinks in permits — "my bakery" —
 * not in offices, so the filing is the right thing to scan for and the office
 * is the right thing to choose once you are inside it.
 */

type Sort = 'recent' | 'oldest'

/*
 * What the Filter menu narrows the inbox to.
 *
 * Search answers "which conversation is the one about X". These answer "which
 * of them needs me", which is the other question an inbox is opened with and
 * the one it could not answer at all: the control was drawn but inert, so a
 * BPLO clerk with sixty rows had no way to see the four that had gone unread.
 *
 * 'quiet' is offered to applicants only, and deliberately: an office's inbox
 * lists a filing only once its own conversation has something in it (see
 * MessageController::threads), so for an officer this option can only ever
 * return nothing. An option that is always empty is the same lie as a button
 * that does nothing.
 */
type Narrow = 'all' | 'unread' | 'awaiting' | 'quiet'

const NARROW_LABELS: Record<Narrow, string> = {
  all: 'All conversations',
  unread: 'Unread',
  awaiting: 'Awaiting their reply',
  quiet: 'Not started',
}

/*
 * What an empty result MEANS, written out per option rather than assembled
 * from the label. "Nothing is not started" is what a template produces and it
 * is not a sentence; each of these says the good news the empty list actually
 * carries.
 */
const NARROW_EMPTY: Record<Narrow, string> = {
  all: 'No conversations yet',
  unread: 'You have read everything',
  awaiting: 'Nothing is waiting on a reply',
  quiet: 'Every filing has a conversation started',
}

/** Royal circle with a person glyph, the GUI's conversation avatar. */
function Avatar({ size = 44 }: { size?: number }) {
  return (
    <span
      aria-hidden="true"
      className="flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-royal text-white"
      style={{ width: size, height: size }}
    >
      <svg viewBox="0 0 24 24" className="h-full w-full fill-current" style={{ marginTop: size * 0.16 }}>
        <circle cx="12" cy="8.5" r="3.6" />
        <path d="M12 13.6c-4.1 0-6.6 2.5-6.6 6.4h13.2c0-3.9-2.5-6.4-6.6-6.4Z" />
      </svg>
    </span>
  )
}

/**
 * The office answerable for the permit (checklist item 73), or null when the
 * filing has not been routed yet.
 *
 * The OFFICE and nothing else. It used to append the assigned officer —
 * "Sanitary Office · Dr. Reyes" — and, when no officer was on it, the pane
 * added "· no officer assigned yet" beside it. Both are gone on the client's
 * instruction: a conversation does not need a named officer, and saying one is
 * missing reads as something being wrong when nothing is. Whoever replies puts
 * their own name on their reply, which is where a person's name is a fact
 * rather than a promise — see senderRole() in MessagesPanel.
 */
function officeLine(thread: MessageThreadSummary): string | null {
  return thread.responsible_office?.name ?? null
}

function ThreadCard({
  thread,
  readerOffice,
  active,
  onOpen,
}: {
  thread: MessageThreadSummary
  /** The reader's own office, when they have one. Null for an applicant. */
  readerOffice: string | null
  active: boolean
  onOpen: () => void
}) {
  const last = thread.last_message
  const preview = last
    ? `${last.mine ? 'You' : (last.sender_name ?? thread.counterparty.name)}: ${last.body}`
    : 'No messages yet. Start the conversation.'
  /*
   * "Handled by X" only where X is a fact the card has not already given.
   *
   * Three ways it was noise, all of them seen on one screen:
   *
   *  - the conversation is already NAMED after that office (the applicant's
   *    side), so the line repeated the title two rows below it;
   *  - the reader IS that office. "Handled by Business Permits and Licensing
   *    Office" on BPLO's own screen, above "Conversation with Business Permits
   *    and Licensing Office" — the same office, twice, told to itself;
   *  - the row is a general ENQUIRY, which has no filing and therefore nothing
   *    being handled. The office is who you are writing to, and the title
   *    already says so.
   */
  const office = thread.kind === 'general' ? null : officeLine(thread)
  const handledBy =
    office && office !== thread.counterparty.name && office !== readerOffice ? office : null
  const subtitle =
    thread.counterparty.subtitle && thread.counterparty.subtitle !== thread.responsible_office?.name
      ? thread.counterparty.subtitle
      : null

  /*
   * ── "Conversation with X", deleted ───────────────────────────────────────
   *
   * The card used to name, for an officer, the offices a filing was being
   * discussed with. It was added because an officer's card names the APPLICANT
   * and is otherwise silent about which office's mail this is — genuinely
   * ambiguous, the note said, for BPLO, "who reads every office's".
   *
   * BPLO does not. readsThread() has no `readsEveryOffice` escape and says so
   * at length: reading every office's CLEARANCES does not extend to reading
   * their mail. So the list could only ever hold the reader's own office, and
   * every card on every office's inbox carried a line naming the office reading
   * it — under a header already naming it, on a screen they reached from their
   * own office's menu.
   *
   * What would bring it back: an office that may read another's conversation.
   * If readsThread() ever admits one, this line has a job again, and the rule
   * is "the offices with messages, minus the reader's own".
   */

  /*
   * Which business, and which filing of it, this conversation is about.
   *
   * The counterparty subtitle carries the business name OR the tracking id —
   * whichever it has — so an owner with two filings for the same business saw
   * two identical cards and no way to tell which was which. The tracking id is
   * the thing that differs, so it is always printed; the business name joins it
   * only when it is not already said above.
   *
   * A general enquiry has neither and prints nothing here: there is no filing.
   */
  const identity =
    thread.kind === 'application' && thread.tracking_id
      ? [
          thread.business_name && thread.business_name !== subtitle ? thread.business_name : null,
          thread.tracking_id,
        ]
          .filter(Boolean)
          .join(' · ')
      : null

  return (
    <li>
      <button
        type="button"
        onClick={onOpen}
        aria-current={active ? 'true' : undefined}
        className={`flex w-full items-center gap-4 rounded-xl bg-white px-5 py-4 text-left shadow-card transition-shadow hover:shadow-raised ${
          active ? 'ring-2 ring-royal' : ''
        }`}
      >
        <Avatar />
        <span className="min-w-0 flex-1">
          <span className="flex items-baseline gap-2">
            <span className="min-w-0 flex-1 truncate text-[15px] font-bold text-ink">
              {thread.counterparty.name}
              {subtitle && (
                <span className="font-semibold italic text-ink-secondary"> · {subtitle}</span>
              )}
            </span>
            {/*
              A filter for something the card never showed is a filter you
              cannot check. The badge is the reason a row is in the Unread
              list — and it carries the number as text, not colour alone, so
              "3 unread" is readable to a screen reader and in monochrome.
            */}
            {thread.unread_count > 0 && (
              <span className="tnum shrink-0 rounded-full bg-royal px-2 py-0.5 text-[11px] font-bold text-white">
                {thread.unread_count} unread
              </span>
            )}
            <span className="shrink-0 text-xs italic text-ink-muted">
              {formatDate(thread.updated_at)}
            </span>
          </span>
          {identity && (
            <span className="mt-0.5 block truncate text-xs font-medium text-ink-muted">
              {identity}
            </span>
          )}
          {handledBy && (
            <span className="mt-0.5 block truncate text-xs font-semibold text-royal">
              Handled by {handledBy}
            </span>
          )}
          <span className="mt-0.5 block truncate text-sm text-ink-secondary">{preview}</span>
        </span>
      </button>
    </li>
  )
}

export function MessagesPage() {
  const user = useAuth((s) => s.user)
  const readerIsOfficer = Boolean(user?.permissions.includes('application.view_all'))
  // Their own office, so the cards can stop telling an office its own name.
  const readerOffice = user?.department?.name ?? null
  const [params, setParams] = useSearchParams()
  const [query, setQuery] = useState('')
  const [sort, setSort] = useState<Sort>('recent')
  const [narrow, setNarrow] = useState<Narrow>('all')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<MessageThreadSummary[]>([])

  /*
   * ── The inbox is paged, and now says so ──────────────────────────────────
   *
   * `/message-threads` has been capped at fifty rows a page since the lists
   * were bounded, and this screen asked for one page with `threads()`,
   * discarded the meta, and rendered the result as though it were the whole
   * inbox. Nothing on the page said otherwise: no count, no control that could
   * reach row fifty-one. Two things follow and both are fixed here — the
   * reader is told how many conversations there are, and the Filter goes to
   * the server, because a narrowing applied to fifty downloaded rows answers
   * for fifty rows while claiming to answer for the inbox.
   */
  const { data, loading, error, reload } = useAsync(
    () => messagesApi.threadsPage({ page, ...(narrow !== 'all' ? { narrow } : {}) }),
    [page, narrow],
  )

  // Append rather than replace, so "Load more" extends the list being read
  // instead of dropping the reader back at the top. De-duplicated by row key:
  // a retried page would otherwise render its rows twice.
  useEffect(() => {
    if (!data) return
    setRows((prev) => {
      if (data.meta.current_page === 1) return data.data
      const seen = new Set(prev.map(rowKey))
      return [...prev, ...data.data.filter((t) => !seen.has(rowKey(t)))]
    })
  }, [data])

  // A different question starts at the first page.
  function narrowTo(next: Narrow) {
    setNarrow(next)
    setPage(1)
  }

  const threads = rows
  const total = data?.meta.total ?? 0
  const hasMore = data ? data.meta.current_page < data.meta.last_page : false
  const firstLoad = loading && rows.length === 0
  /*
   * A row is identified by a KEY, not by an application id — an enquiry with
   * no filing has no id to be identified by. Filings keep their numeric id as
   * their key, so a link someone already has (?application=12) still opens the
   * same conversation; the enquiry uses the literal 'general', which
   * Number() reads as NaN and no filing can collide with.
   */
  const rowKey = (t: MessageThreadSummary) =>
    t.kind === 'general' ? 'general' : String(t.application_id)

  const selectedKey = params.get('application')
  const selected = threads.find((t) => rowKey(t) === selectedKey) ?? null

  // On a wide screen an empty pane is wasted space: open the newest thread.
  useEffect(() => {
    if (selectedKey || threads.length === 0) return
    if (window.matchMedia('(min-width: 1024px)').matches) {
      setParams({ application: rowKey(threads[0]) }, { replace: true })
    }
  }, [selectedKey, threads, setParams])

  /*
   * Search stays in the browser; the Filter does not.
   *
   * They are narrowing different things. The Filter asks a question about
   * STATE — unread, awaiting a reply — which the register can answer over
   * every row the reader has, and must, because only fifty are downloaded.
   * Search is a typed substring over the rows in front of you, refining what
   * you can already see, and moving it to the server would put a round trip on
   * every keystroke to no benefit. The honest cost is that a search only looks
   * at the loaded page, which is why the count below names both numbers.
   */
  const visible = useMemo(() => {
    const needle = query.trim().toLowerCase()
    const matched = needle
      ? threads.filter((t) =>
          [
            t.counterparty.name,
            t.counterparty.subtitle,
            t.business_name,
            t.tracking_id,
            t.last_message?.body,
            // Now that the office is on the card, it has to be findable: an
            // applicant chasing a health clearance searches "sanitary".
            t.responsible_office?.name,
            t.responsible_office?.officer?.name,
            // And the offices the filing is actually being discussed with,
            // which are not always the one answerable for it.
            ...t.offices.map((o) => o.name),
          ]
            .filter(Boolean)
            .some((field) => (field as string).toLowerCase().includes(needle)),
        )
      : threads
    const ordered = [...matched].sort(
      (a, b) => Date.parse(a.updated_at ?? '') - Date.parse(b.updated_at ?? ''),
    )
    return sort === 'recent' ? ordered.reverse() : ordered
  }, [threads, query, sort])

  /*
   * ── The grouping is gone, and why ────────────────────────────────────────
   *
   * There used to be a heading above each group of cards, carrying the business
   * name and the tracking number. It was written when the inbox listed one row
   * per CONVERSATION, so a filing routed to four offices produced four cards
   * that needed something to sit under.
   *
   * The list has paged by FILING since then — one row per filing, its offices
   * named on the row — so every "group" held exactly one card and every heading
   * repeated what the card underneath it already said:
   *
   *     Nena's Sari-Sari Store  BIZ-2026-00001      ← heading
   *     Nena Makiling · Nena's Sari-Sari Store      ← the card
   *     BIZ-2026-00001
   *
   * Three lines, two facts, in a column 26rem wide. Say it once (§6.4): the
   * card carries its own identity and the headings are deleted.
   *
   * If the inbox ever pages by conversation again, the grouping comes back with
   * it — that is the condition, and it is the only one.
   */
  function open(key: string) {
    setParams({ application: key })
  }

  // 'all' has to head the list: SortFilter reads options[0] as the neutral
  // choice and only highlights the control when something else is picked.
  const narrowOptions = (['all', 'unread', 'awaiting', 'quiet'] as Narrow[])
    .filter((n) => n !== 'quiet' || !readerIsOfficer)
    .map((n) => ({ value: n, label: NARROW_LABELS[n] }))

  const list = (
    <div className={selected ? 'hidden lg:block' : ''}>
      <PageTitle
        right={
          <SortFilter
            sort={{
              value: sort,
              options: [
                { value: 'recent', label: 'Most recent' },
                { value: 'oldest', label: 'Oldest first' },
              ],
              onChange: (v) => setSort(v as Sort),
            }}
            filter={{
              value: narrow,
              options: narrowOptions,
              onChange: (v) => narrowTo(v as Narrow),
            }}
          />
        }
      >
        Messages
      </PageTitle>

      <label className="relative mb-5 block">
        <span className="sr-only">Search messages</span>
        <SearchIcon
          size={18}
          className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-ink-secondary"
        />
        <input
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search messages"
          className="w-full rounded-full bg-royal-tint py-2.5 pl-11 pr-4 text-sm text-ink placeholder:text-ink-secondary focus:outline-none focus:ring-2 focus:ring-royal"
        />
      </label>

      {firstLoad ? (
        <SkeletonList rows={4} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : visible.length === 0 ? (
        /*
         * Three different nothings, and they need three different answers.
         * "No conversations yet" told a clerk whose Unread filter was empty
         * that the city had never written to them — the filter is checked
         * first because it is the one the reader is least likely to remember
         * setting.
         */
        narrow !== 'all' && !query ? (
          <EmptyState
            icon={MailIcon}
            title={NARROW_EMPTY[narrow]}
            description="Your other conversations are still here — this is the filter, not the inbox."
            action={
              <button
                type="button"
                onClick={() => narrowTo('all')}
                className="rounded-full bg-royal px-5 py-2 text-sm font-semibold text-white hover:bg-royal-hover"
              >
                Show all conversations
              </button>
            }
          />
        ) : (
          <EmptyState
            icon={MailIcon}
            title={query ? 'No conversations match' : 'No conversations yet'}
            description={
              query
                ? 'Try a different name, business, or tracking number.'
                : 'Messages about an application will show up here once the conversation starts.'
            }
          />
        )
      ) : (
        /*
         * A flat list of cards. Each one names the business it is about and the
         * filing it belongs to — two filings of one business differ by their
         * tracking number, which is why the card prints it rather than leaning
         * on a heading to separate them. See the note on the deleted grouping.
         */
        <div className="flex flex-col gap-4">
          {/*
            Both numbers named (§6.4). "50 conversations" reads as the whole
            inbox; "50 of 137" is the only version that tells a clerk there is
            more of it, which is what this page never said.
          */}
          <p className="-mb-2 px-1 text-sm text-ink-muted" role="status">
            Showing {visible.length.toLocaleString()} of {total.toLocaleString()} conversation
            {total === 1 ? '' : 's'}
            {narrow !== 'all' ? ` (${NARROW_LABELS[narrow].toLowerCase()})` : ''}
            {query ? ', searched within the ones loaded' : ''}.
          </p>
          <ul aria-label="Conversations" className="flex flex-col gap-3">
            {visible.map((t) => (
              <ThreadCard
                key={rowKey(t)}
                thread={t}
                readerOffice={readerOffice}
                active={rowKey(t) === selectedKey}
                onOpen={() => open(rowKey(t))}
              />
            ))}
          </ul>
          {hasMore && (
            <button
              type="button"
              // aria-disabled, never `disabled`: a screen reader skips a
              // disabled control and takes its label with it, so the guard is
              // in the handler instead.
              aria-disabled={loading}
              onClick={() => {
                if (!loading) setPage((p) => p + 1)
              }}
              className="rounded-xl border border-line bg-white py-3 text-sm font-semibold text-royal transition-colors hover:bg-canvas aria-disabled:cursor-wait aria-disabled:text-ink-muted"
            >
              {loading ? 'Loading…' : 'Load more conversations'}
            </button>
          )}
        </div>
      )}
    </div>
  )

  /*
   * The office answerable for the permit (item 73) — printed only when it is a
   * SECOND fact.
   *
   * The header used to carry three lines about offices and two of them were
   * noise. On a general enquiry it read:
   *
   *     Business Permits and Licensing Office
   *     Handled by Business Permits and Licensing Office · no officer assigned yet
   *     General enquiry
   *
   * — the same office twice, plus an apology for a person nobody asked for.
   * "No officer assigned yet" is gone entirely on the client's instruction: a
   * conversation does not need an officer's name on it, and announcing the
   * absence of one makes a normal state look like a fault. The office line
   * survives only where it says something the title does not: when the permit
   * is handled by a DIFFERENT office from the one this conversation is with.
   *
   * Nothing is said when the filing has not been routed. "Not yet assigned to
   * an office" used to be printed there, and it contradicted the picker two
   * lines below saying which office the conversation is with; where the permit
   * is in the queue is a question for the filing, not for its mail.
   */
  const paneOffice = selected ? officeLine(selected) : null
  const handledElsewhere =
    paneOffice && paneOffice !== selected?.counterparty.name ? paneOffice : null

  /*
   * What to call the open pane — and why it stopped being the counterparty.
   *
   * The API names an applicant's conversation after whoever is on the other
   * side, falling back to the lead office when no officer has picked the filing
   * up. That was fine while a filing had one conversation. It is actively
   * misleading now that the pane also carries an office PICKER: a filing routed
   * to BPLO and the fire office opened with "Business Permits and Licensing
   * Office" in bold at the top and "Bureau of Fire Protection" selected two
   * lines below, which reads as a contradiction and is really two different
   * facts wearing the same shape — who is handling the permit, and who you are
   * writing to.
   *
   * So when the fallback has fired — the name is one of the filing's offices
   * rather than a person — the pane is titled after the FILING, which is what
   * is actually constant across every conversation in it. A real officer's name
   * still wins: "Liza Reyes" is who you are talking to and the picker beneath
   * says which office she is answering for.
   */
  const titledAfterAnOffice =
    selected !== null && selected.offices.some((o) => o.name === selected.counterparty.name)
  const paneTitle = selected
    ? (titledAfterAnOffice
        ? (selected.business_name ?? selected.tracking_id ?? selected.counterparty.name)
        : selected.counterparty.name)
    : ''

  /*
   * "Central Perk · BIZ-2026-00005", never the same value twice — and never a
   * value the line above has already given. The unlabelled subtitle IS the
   * office on most applicant threads, so without this last filter the header
   * printed the office name twice, two lines apart, looking like two facts.
   */
  const paneSubtitle = selected
    ? [selected.counterparty.subtitle, selected.tracking_id]
        .filter(
          (part, i, all): part is string =>
            Boolean(part) &&
            all.indexOf(part) === i &&
            part !== selected.responsible_office?.name &&
            // …nor the title, which is now sometimes the business name itself,
            // nor an office — the picker below names the office, and on an
            // unrouted filing the API's fallback subtitle IS an office name.
            part !== paneTitle &&
            !selected.offices.some((o) => o.name === part),
        )
        .join(' · ')
    : ''

  const pane = selected ? (
    <section
      aria-label={`Messages about ${paneTitle}`}
      className="flex min-h-[32rem] flex-col overflow-hidden rounded-xl bg-white shadow-card lg:h-[calc(100dvh-9rem)]"
    >
      <header className="flex items-center gap-3 bg-royal-tint px-5 py-4">
        <Avatar size={38} />
        <div className="min-w-0 flex-1">
          <p className="truncate text-base font-bold text-ink">{paneTitle}</p>
          {handledElsewhere && (
            <p className="truncate text-xs font-semibold text-royal">
              Handled by {handledElsewhere}
            </p>
          )}
          {paneSubtitle && (
            <p className="truncate text-sm italic text-ink-secondary">{paneSubtitle}</p>
          )}
        </div>
        <button
          type="button"
          onClick={() => setParams({})}
          aria-label="Close conversation"
          className="shrink-0 rounded-md p-1 text-royal hover:bg-white/60"
        >
          <XIcon size={22} />
        </button>
      </header>

      <MessageThreadView
        key={rowKey(selected)}
        target={
          selected.kind === 'general'
            ? { kind: 'general', userId: selected.user_id }
            : { kind: 'application', applicationId: selected.application_id! }
        }
        className="flex-1 px-5 pb-5 pt-4"
        scrollClassName="min-h-0"
        onSent={reload}
      />
    </section>
  ) : (
    <div className="hidden items-center justify-center rounded-xl bg-white p-10 shadow-card lg:flex">
      <p className="text-sm text-ink-secondary">
        Choose a conversation on the left to read and reply.
      </p>
    </div>
  )

  return (
    /*
     * `minmax(0,1fr)` at every width, not just lg. A grid item's automatic
     * minimum size is its min-content, and a thread title is one nowrap line,
     * so the single mobile column sized itself to the longest subject — 732px
     * on a 390px screen. The `truncate` on the titles only ever took effect
     * once the column was told it may be narrower than they are.
     */
    <div className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
      {list}
      {pane}
    </div>
  )
}
