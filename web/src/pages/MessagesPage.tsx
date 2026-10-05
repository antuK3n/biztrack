import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { ArrowLeftIcon, MailIcon, SearchIcon } from '../components/icons'
import { MessageThreadView } from '../components/MessagesPanel'
import { EmptyState, ErrorState, SkeletonList } from '../components/ui/primitives'
import { PageTitle, SortFilter, StatusChip } from '../components/ui/Proto'
import { formatDate, formatListStamp, initialsOf } from '../lib/format'
import { messages as messagesApi } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import { useAuth } from '../stores/auth'
import type { CounterpartyStanding, MessageThreadSummary } from '../lib/types'

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

/*
 * ---- Permits, and the people ---------------------------------------------
 *
 * "Can you do another button para sa general inquiry" [client, 1 October 2026].
 *
 * An office's Messages page is two lists that happen to share a shape. One is
 * the caseload: a row per permit assigned to them, which is what they work
 * from. The other is the people they may hear from - a row per owner, whether
 * or not anybody has written, because the row is how the office OPENS a
 * conversation.
 *
 * They were merged, and the second buried the first. BPLO's list is every
 * registered account: on a register of any size the permits an officer is
 * actually holding would sit below a hundred citizens who have never written
 * to anybody.
 *
 * So they are two views of one screen, and the button says which you are in.
 * An applicant sees neither control - their inbox is their own filings and
 * their one enquiry, and there is nothing to choose between.
 */
type Shelf = 'permits' | 'enquiries' | 'admin'

/**
 * What each shelf holds, and the row kind it is built from.
 *
 * The administrator's line got its own [client, 1 October 2026]. It was pinned
 * to the top of the permits shelf, where it belonged to neither list - it is
 * the officer's OWN account, not a permit and not an applicant - and every
 * officer carries exactly one of it.
 *
 * Its own button is better than a pin for the same reason the enquiries got
 * one: a row that is always first is a row that is always in the way, and an
 * officer scanning their caseload was reading past their own account details
 * every time.
 */
const SHELVES: {
  value: Shelf
  /** The full name, for the tooltip and the accessible name. */
  label: string
  /** What fits on a third of a narrow column without wrapping. */
  short: string
  kind: MessageThreadSummary['kind']
}[] = [
  { value: 'permits', label: 'Permits', short: 'Permits', kind: 'application' },
  { value: 'enquiries', label: 'General enquiries', short: 'Enquiries', kind: 'general' },
  { value: 'admin', label: 'Super Administrator', short: 'Super Admin', kind: 'admin' },
]

/**
 * What to call what is on a shelf, in the plural the count needs.
 *
 * Written out rather than assembled from the label: "1 general enquiries" and
 * "1 Super Administrators" are what a template produces, and the third shelf
 * holds exactly one row for every officer alive, so its singular is the only
 * form anybody will ever read.
 */
function shelfNoun(shelf: Shelf, count: number): string {
  if (shelf === 'admin') return count === 1 ? 'conversation with the Super Administrator' : 'conversations with the Super Administrator'
  if (shelf === 'enquiries') return count === 1 ? 'general enquiry' : 'general enquiries'

  return count === 1 ? 'permit' : 'permits'
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
 * A finding against the person this office is talking to.
 *
 * ---- Why an officer is shown this at all ----------------------------------
 *
 * "Paki lagyan din ng note sa other admin offices sa messages page kung ang
 * kumokontak sa kanya ay currently suspended, flagged, blacklisted" [client,
 * 30 September 2026].
 *
 * An office reading its mail cannot otherwise tell: a blacklisted owner and one
 * in good standing write identical rows, and the reply differs. Somebody barred
 * from filing should not be told to file.
 *
 * ---- Why it is not red -----------------------------------------------------
 *
 * DESIGN.md: red means STOP. This is a fact about the sender, not a refusal or
 * a danger to the reader, and a scarlet chip beside somebody's name reads as an
 * accusation rather than a note. The tints carry the weight instead — red-tint
 * for the bar, yellow-tint for the watch — which is the same vocabulary the
 * owner register uses for the same three words.
 *
 * Never shown to an applicant. `standing` is absent from every row they read;
 * their own standing is delivered by the restriction notice, which explains it
 * and offers somewhere to take it.
 */
function StandingNote({ standing }: { standing: CounterpartyStanding }) {
  const tone = standing.kind === 'flagged' ? 'tint-yellow' : 'tint-red'

  /*
   * "Pwede rin i-note doon na may isa, dalawa, ... syang business na
   * suspended" [client, 1 October 2026].
   *
   * Only past the first, and only on a suspension. One suspended business is
   * already what the chip says, so "1 of their businesses" would be the same
   * fact twice; the number earns its place when it says the finding is not
   * isolated. A blacklisting carries no count - the cascade leaves none of
   * their businesses suspended - and a flag is a watch on this shopfront,
   * not a tally.
   */
  const alsoSuspended = standing.kind === 'suspended' && standing.suspended_count > 1

  return (
    <StatusChip tone={tone} className="shrink-0 px-2 py-0.5 text-[10px]">
      {standing.label}
      {alsoSuspended && (
        <span className="ml-1 font-bold">
          · {standing.suspended_count} of theirs
          <span className="sr-only"> are suspended</span>
        </span>
      )}
    </StatusChip>
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
  /*
   * "Nothing said yet" and not "No messages yet. Start the conversation."
   *
   * An office's inbox is its caseload now, so most rows on it are permits
   * nobody has written about - eight of ten on BPLO's screen carried the same
   * eleven-word sentence, one per row, saying the same thing eight times. The
   * short form says as much, is set in italic so it reads as a STATE rather
   * than as somebody's words, and leaves the row's shape to the rest.
   */
  const preview = last
    ? `${last.mine ? 'You' : (last.sender_name ?? thread.counterparty.name)}: ${last.body}`
    : 'Nothing said yet'
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
  //  - the row is the administrator's line, which has no office on either
  //    side at all.
  const office = thread.kind === 'application' ? officeLine(thread) : null

  /*
   * ── What names the row, and for whom ─────────────────────────────────────
   *
   * An APPLICANT is looking for their business. Their inbox holds one row per
   * filing, and the counterparty of every one of them is an office — so a card
   * titled by the office read "Business Permits and Licensing Office" five
   * times down the page, with the thing the reader was actually looking for
   * demoted to a suffix. The business is what they recognise; the office is a
   * fact about the row, and it has a line of its own below.
   *
   * An OFFICER is looking for a person. Their counterparty IS the applicant,
   * which is already the right title, and the business sits under it.
   *
   * A general enquiry has no business, so it keeps the office as its title —
   * there is nothing else it could honestly be called.
   */
  const isApplicant = readerOffice === null
  const businessTitle =
    isApplicant && thread.kind === 'application'
      ? (thread.business_name ?? thread.tracking_id)
      : null
  const title = businessTitle ?? thread.counterparty.name

  /*
   * The "Handled by " prefix is gone with the card's extra line. On a list
   * where the line is always an office, in royal, under a business name, the
   * two words were furniture - and they pushed the office name itself into a
   * truncation on the narrow left pane, which is the one part of it a reader
   * actually needs.
   */
  const handledBy =
    office && office !== title && office !== readerOffice ? office : null

  /*
   * The suffix beside the title. Suppressed when the title is already the
   * business: the subtitle carries the business name too, and printing it
   * twice on one line is how the old card read.
   */
  const subtitle =
    !businessTitle &&
    thread.counterparty.subtitle &&
    thread.counterparty.subtitle !== thread.responsible_office?.name
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
          thread.business_name && thread.business_name !== subtitle && !businessTitle
            ? thread.business_name
            : null,
          thread.tracking_id,
        ]
          .filter(Boolean)
          .join(' · ')
      : null

  const unread = thread.unread_count > 0
  const quiet = thread.messages_count === 0
  /*
   * Only an office is told. The server omits `standing` from every row an
   * APPLICANT reads - their own standing reaches them through the restriction
   * notice, which explains it and offers somewhere to take it, rather than as
   * a chip on their own conversation.
   */
  const standing = thread.counterparty.standing ?? null

  return (
    <li>
      <button
        type="button"
        onClick={onOpen}
        aria-current={active ? 'true' : undefined}
        /*
          ---- A row in a list, not a card floating on canvas ---------------

          These were separate white cards with 12px of canvas between them and
          20px of padding inside. Ten of them filled a laptop screen with six
          conversations' worth of information, and read as ten unrelated
          things: a conversation list is ONE thing with rows in it, which is
          why every messaging app draws it that way. The dividers are drawn by
          the <ul>; the row brings only its selected state.

          The selected row is marked on its LEFT EDGE as well as by its fill,
          because a tint alone is a light wash on a white list and the one row
          that matters should be unmistakable scanning down the column.
        */
        className={`flex w-full items-start gap-3 border-l-[3px] px-4 py-3 text-left transition-colors ${
          active ? 'border-royal bg-royal-tint' : 'border-transparent hover:bg-canvas/60'
        }`}
      >
        <span
          aria-hidden="true"
          className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-bold ${
            unread ? 'bg-royal text-white' : 'bg-canvas text-ink-secondary'
          }`}
        >
          {/*
            Initials of the row's OWN title, not of the counterparty.

            An applicant's row is titled after the business; the counterparty
            is the office. Taking the letters from the office put "BO" beside
            "Test" and "BP" beside "SAMPLE Aling Nena Bakery" - two letters
            that appear nowhere in the words next to them, which reads as a
            rendering fault rather than as an avatar. The shape is there to be
            recognised, so it has to be a shape OF the thing named.
          */}
          {initialsOf(title)}
        </span>

        <span className="min-w-0 flex-1">
          <span className="flex items-baseline justify-between gap-2">
            <span
              className={`min-w-0 flex-1 truncate text-sm text-ink ${unread ? 'font-bold' : 'font-semibold'}`}
            >
              {title}
              {subtitle && (
                <span className="font-medium italic text-ink-secondary"> · {subtitle}</span>
              )}
            </span>
            {/*
              The date sits WITH the name, where a reader looks for it, rather
              than wrapping onto a line of its own - which cost a line per row
              down the whole column on the narrow left pane.
            */}
            {/* Said, not only placed: the owner's General enquiry stays on top. */}
            {isApplicant && thread.kind === 'general' && (
              <span className="shrink-0 rounded-full bg-royal-tint px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-royal">
                Pinned
              </span>
            )}
            <span
              className="shrink-0 text-[11px] text-ink-muted"
              title={formatDate(thread.updated_at)}
            >
              {formatListStamp(thread.updated_at)}
            </span>
          </span>

          {/*
            The filing and the office answerable for it, on ONE muted line.

            They had a line each, and the office's was set in bold royal - the
            colour this app uses for links and for the thing you are meant to
            press. On a row where the whole card is the control, a second
            pressable-looking thing is a lie, and three lines of small print
            under every title is what made ten rows fill a screen.

            Both facts are still here, in the order a reader wants them: which
            filing, then whose desk it is on.
          */}
          {(identity || handledBy || standing) && (
            <span className="mt-0.5 flex items-center gap-1.5">
              {/*
                The finding first, and outside the truncation. It is the one
                thing on this line an officer must not miss, and a tracking
                number long enough to cut it off is the ordinary case.
              */}
              {standing && <StandingNote standing={standing} />}
              {(identity || handledBy) && (
                <span className="min-w-0 truncate text-xs text-ink-muted">
                  {[identity, handledBy].filter(Boolean).join(' · ')}
                </span>
              )}
            </span>
          )}

          <span className="mt-1 flex items-center gap-2">
            <span
              className={`min-w-0 flex-1 truncate text-xs ${
                quiet
                  ? 'italic text-ink-muted'
                  : unread
                    ? 'font-medium text-ink'
                    : 'text-ink-secondary'
              }`}
            >
              {preview}
            </span>

            {/*
              A filter for something the card never showed is a filter you
              cannot check. The badge is the reason a row is in the Unread
              list - and it carries the number as TEXT, not colour alone, so
              it is readable in monochrome; the word is kept for a screen
              reader, which has no room problem.

              Only when there is one. A permanent "0 unread" beside every row
              teaches the eye to skip the column that matters.
            */}
            {unread && (
              <span className="tnum shrink-0 rounded-full bg-royal px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">
                {thread.unread_count}
                <span className="sr-only"> unread</span>
              </span>
            )}
          </span>
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
  /*
   * Which half of the office's mail is on screen. An applicant never sees the
   * control and never leaves 'permits', which for them means "everything" -
   * their enquiry is one row among their filings and belongs with them.
   */
  const [shelf, setShelf] = useState<Shelf>('permits')
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
  /*
   * A row is identified by a KEY, not by an application id - an enquiry with
   * no filing has no id to be identified by. Filings keep their numeric id as
   * their key, so a link someone already has (?application=12) still opens the
   * same conversation; the enquiry uses the literal 'general', which Number()
   * reads as NaN and no filing can collide with.
   *
   * Briefly this was `general-<office>`, while each office had an inbox row of
   * its own. The office is a choice inside the one conversation now [client,
   * 30 September 2026], so there is one key again - and the old form is still
   * accepted below, because links to it exist.
   */
  const rowKey = (t: MessageThreadSummary) => {
    if (t.kind === 'admin') return 'admin'
    if (t.kind !== 'general') return String(t.application_id)

    /*
     * ---- An enquiry's key depends on WHO is reading -------------------
     *
     * An applicant has exactly one enquiry row - one conversation, the office
     * chosen inside it - so the bare word is its key, and a link somebody
     * already holds (`?application=general`, which the restriction notice and
     * the BPLO shortcut both use) keeps working.
     *
     * An OFFICE has one row per owner it may hear from, and they were all
     * given that same bare word. Two consequences, both seen on screen:
     *
     *  - `selected` is a `find` on this key, so pressing Juan Ramos opened
     *    Nena Makiling - whichever row the list happened to hold first;
     *  - React had duplicate keys in one list, so switching shelves left an
     *    orphaned row behind: the Administrator shelf showed a general enquiry
     *    above the one conversation it holds, while the count beside it
     *    correctly said one.
     *
     * The owner id is what tells those rows apart, so it is what keys them.
     */
    return readerIsOfficer && t.user_id ? `general-${t.user_id}` : 'general'
  }

  const selectedKey = params.get('application')
  // An office named by the link (?office=<department id>) opens that conversation.
  const linkedOffice = Number(params.get('office')) || null
  const selected = threads.find((t) => rowKey(t) === selectedKey) ?? null

  /*
   * Changing shelf lets go of the conversation.
   *
   * Without this the two halves of the screen disagreed: the Administrator
   * shelf listed its one row on the left while the right still held the
   * general enquiry opened a moment before, which reads as the list having
   * lost something rather than as the reader having moved.
   *
   * The wide-screen effect below then opens the newest conversation on the
   * shelf just chosen, so the pane follows rather than emptying.
   */
  function pickShelf(next: Shelf) {
    /*
     * Looked at, even when it is the shelf already open: pressing the button
     * that carries the badge and having the badge stay is the one thing this
     * must not do.
     */
    markSeen(next)

    if (next === shelf) return

    setShelf(next)
    setParams({}, { replace: true })
  }

  /*
   * The shelf being read never badges itself.
   *
   * "Something is waiting over there" is the whole of what this badge says, so
   * saying it about the list already on screen is noise - and the rows below
   * are carrying their own unread marks while it does.
   *
   * On `threads` so it holds as pages load and as the poll brings new rows in:
   * anything that arrives on the shelf you are reading is marked looked-at,
   * because you are looking at it.
   */
  useEffect(() => {
    if (threads.length > 0) markSeen(shelf)
    // `markSeen` is rebuilt every render; listing it would loop.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [threads, shelf])

  /** Remember what the rows on this shelf had been said on, as of now. */
  function markSeen(which: Shelf) {
    const kind = SHELVES.find((sh) => sh.value === which)?.kind

    setSeen((prev) => {
      const next = { ...prev }
      for (const t of threads) {
        if (t.kind === kind) next[rowKey(t)] = t.updated_at ?? ''
      }

      return next
    })
  }

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
  /*
   * The shelf is applied AFTER the search and the sort, not before: a search
   * narrows what is on the shelf you are looking at, and swapping shelves
   * keeps the search you typed. Doing it the other way round would clear the
   * query every time the button was pressed.
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
    const byDate = sort === 'recent' ? ordered.reverse() : ordered

    /*
     * -- The administrator's line stays on top ---------------------------
     *
     * The server pins it, and this sort used to undo that: an officer who has
     * never written has no `updated_at`, so `Date.parse(undefined)` is NaN and
     * the row fell to wherever the comparator left it - which on the screen
     * the pin exists FOR (an officer looking for where to ask about their own
     * details) was the bottom of the list.
     *
     * Pinned here rather than by leaning on the server's order, because this
     * page sorts and filters on its own and any ordering it does not respect
     * is an ordering it will silently lose.
     */
    /*
     * And a business owner's General enquiry, on top under it [client, 5
     * October 2026: "naka pin sa taas ang General enquiry · Ask any office"]:
     * it is the one conversation not about any filing, the place to ask
     * anything, and it must not sink below months of permits. An owner has
     * exactly one; an office has one per owner, so it is not pinned there.
     */
    const isPinned = (t: (typeof byDate)[number]) => t.kind === 'admin' || (!readerIsOfficer && t.kind === 'general')
    const pinned = byDate.filter(isPinned)
    return pinned.length > 0 ? [...pinned, ...byDate.filter((t) => !isPinned(t))] : byDate
  }, [threads, query, sort, readerIsOfficer])
  /*
   * ---- Which half of the office's mail is on screen --------------------
   *
   * Applied AFTER the search and the sort, so swapping shelves keeps the query
   * you typed and a search narrows the shelf you are looking at.
   *
   * The administrator's line stays with the permits, which is the shelf the
   * page opens on. It belongs to neither list - it is the officer's own
   * account, not a permit and not an applicant - and filing it under general
   * enquiries would hide the one row that is pinned precisely so it cannot be
   * missed.
   *
   * An applicant has no shelves. Their inbox is their filings and their one
   * enquiry, and there is nothing to choose between.
   */
  const onShelf = useMemo(() => {
    if (!readerIsOfficer) return visible

    const kind = SHELVES.find((sh) => sh.value === shelf)?.kind ?? 'application'

    return visible.filter((t) => t.kind === kind)
  }, [visible, shelf, readerIsOfficer])

  /*
   * On a wide screen an empty pane is wasted space: open the newest
   * conversation.
   *
   * It skipped untouched enquiries for a while, back when each office had a
   * row of its own: the rows sort by when they last moved, and merely LOOKING
   * at one created its thread and pushed it to the top, so the screen kept
   * opening on an empty "write to the fire office" box. One enquiry row
   * [client, 30 September 2026] takes that away - it sorts by the last thing
   * actually said, like every other row.
   */
  useEffect(() => {
    // The newest on the SHELF being read, not in the inbox as a whole: on the
    // Administrator shelf the inbox's newest row is somebody else's permit.
    if (selectedKey || onShelf.length === 0) return
    if (window.matchMedia('(min-width: 1024px)').matches) {
      setParams({ application: rowKey(onShelf[0]) }, { replace: true })
    }
  }, [selectedKey, onShelf, setParams])

  /*
   * ---- What is waiting on the shelves you are not looking at -------------
   *
   * A shelf behind a button is a shelf you can miss, and the administrator's
   * line is the one that was PINNED precisely so it could not be: an officer
   * cannot change their own details and has to be able to find where to ask.
   * Moving it behind a button without this would have answered the client's
   * request by undoing the reason the row exists.
   *
   * ---- And why looking is enough to clear it ---------------------------
   *
   * "Paki lagyan ng parang notif tas number kung may new messages sa part
   * dyan, once clicked mawawala na dapat" [client, 1 October 2026].
   *
   * So the badge is not a second copy of the unread count - the rows carry
   * that, and so does the rail. It answers one narrower question: is there
   * something over there I have not looked at? Pressing the shelf answers it,
   * and the badge goes.
   *
   * It is NOT cleared by marking anything read. An officer who glances at the
   * Enquiries shelf and leaves has still not read those conversations, and the
   * rows keep saying so in bold with their own counts. Only the nag stops.
   *
   * `seen` remembers what each row had been said on WHEN the shelf was
   * looked at, so a genuinely new message brings the badge back: the stored
   * timestamp no longer matches. Remembering the keys alone would silence a
   * shelf for good after one visit.
   *
   * Counted from the rows that are loaded, which is what the list itself
   * shows. It is not the server's total - the inbox is paged at fifty - so it
   * can undercount a register deep enough to page, and undercounting is the
   * safe direction: the number never promises more than the reader can find.
   */
  const [seen, setSeen] = useState<Record<string, string>>({})

  const waitingOn = useMemo(() => {
    const counts: Record<Shelf, number> = { permits: 0, enquiries: 0, admin: 0 }

    for (const sh of SHELVES) {
      counts[sh.value] = threads.filter(
        (t) =>
          t.kind === sh.kind &&
          t.unread_count > 0 &&
          seen[rowKey(t)] !== (t.updated_at ?? ''),
      ).length
    }

    return counts
  }, [threads, seen])



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
    /*
     * ---- The column scrolls; the page does not ------------------------
     *
     * "Gawin yung mga nasa left, may scroll bar na lang tulad sa office
     * messages ng admin" [client, 30 September 2026] - the shape the Office
     * Messages screen already has, and the shape every messaging app people
     * use already has.
     *
     * What it fixes: this page scrolled as a whole, so reading down to the
     * ninth conversation took the transcript, its composer and the search box
     * off the top of the screen. The title, the search and the filters are
     * chrome - they belong where you left them - and only the conversations
     * move.
     *
     * `min-h-0` on the column and on the scroller: a flex item's automatic
     * minimum is its content, so without it the column grows to fit every row
     * and the page scrolls after all. It is the single thing most often
     * missing when a layout like this nearly works.
     */
    <div className={`flex min-h-0 flex-col ${selected ? 'hidden lg:flex' : ''}`}>
      <PageTitle
        right={
          <span className="flex flex-wrap items-center gap-x-4 gap-y-2">
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
          </span>
        }
      >
        Messages
      </PageTitle>

      {/*
        ---- One control, three segments, on a row of its own --------------

        Three rounded pills of three different widths wrapped onto two lines in
        a 26rem column and pushed Sort and Filter onto a third, so the chrome
        stood 150px tall before the search box began - on a screen whose whole
        point is the list underneath it.

        A segmented control is the right shape for this: the three are mutually
        exclusive views of one inbox, which is what a single bordered group of
        equal parts says and what three free-floating pills do not. Equal
        thirds, so the control does not reflow as the labels take their counts.

        Only an office has anything to choose between. For an applicant the
        control is absent rather than disabled: one meaningful position is not
        a choice.
      */}
      {readerIsOfficer && (
        <div
          role="group"
          aria-label="Which conversations"
          className="mb-3 grid shrink-0 grid-cols-3 gap-1 rounded-xl bg-canvas p-1"
        >
          {SHELVES.map((sh) => {
            const active = sh.value === shelf
            const waiting = waitingOn[sh.value]

            return (
              <button
                key={sh.value}
                type="button"
                onClick={() => pickShelf(sh.value)}
                aria-pressed={active}
                title={sh.label}
                /*
                  The FULL name to anybody listening, and the short one on the
                  glass. `title` alone does not do this: an element with text
                  content takes its accessible name from the text, so a screen
                  reader would have announced "Super Admin" - which is the
                  abbreviation, and the abbreviation only fits because the
                  sighted reader has two other segments beside it for context.

                  The count goes in it too. It is a superscript badge visually,
                  and "Super Administrator 2" announced as two separate things
                  is a number with nothing attached to it.
                */
                aria-label={waiting > 0 ? `${sh.label}, ${waiting} waiting` : sh.label}
                className={`flex items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-semibold transition-colors ${
                  active
                    ? 'bg-white text-royal shadow-card'
                    : 'text-ink-secondary hover:bg-white/60 hover:text-ink'
                }`}
              >
                {/*
                  The short name on the control and the full one in `title` and
                  in the sentence below it. "Super Administrator" across a
                  third of a 26rem column is three lines; the segment has to
                  stay one.
                */}
                <span className="truncate">{sh.short}</span>
                {waiting > 0 && (
                  <span
                    className={`tnum shrink-0 rounded-full px-1.5 text-[10px] font-bold leading-4 ${
                      active ? 'bg-royal text-white' : 'bg-royal/15 text-royal'
                    }`}
                  >
                    {waiting}
                  </span>
                )}
              </button>
            )
          })}
        </div>
      )}

      {/*
        ── One panel, the height of the row ────────────────────────────────

        The search, the count and the conversations now sit inside a single
        white card that fills the column, instead of the card being drawn
        behind the rows alone.

        What that fixes is the thing the client was looking at: with the white
        stopping at the last conversation, the left column ended 160px short of
        the transcript beside it and the two halves of the screen did not line
        up. The shorter the inbox, the worse it read — a seven-row list left a
        pane-sized hole under it.

        It is also the shape asked for in the first place: "tulad sa office
        messages ng admin" [30 September 2026]. The transcript is one panel
        with its own furniture pinned and its middle scrolling; this is the
        same, so the two columns are a matched pair rather than a card and a
        list that happen to be side by side.
      */}
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl bg-white shadow-card">
        {/* Chrome: fixed. Everything below it is what moves. */}
        <label className="relative block shrink-0 border-b border-line p-4">
          <span className="sr-only">Search messages</span>
          <SearchIcon
            size={18}
            className="pointer-events-none absolute left-8 top-1/2 -translate-y-1/2 text-ink-secondary"
          />
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search messages"
            className="w-full rounded-full bg-royal-tint py-2.5 pl-11 pr-4 text-sm text-ink placeholder:text-ink-secondary focus:outline-none focus:ring-2 focus:ring-royal"
          />
        </label>

        {/*
          The scroller. The foot padding stops the last conversation sitting
          flush against the bottom of the panel.
        */}
        <div className="min-h-0 flex-1 overflow-y-auto pb-2">
        {firstLoad ? (
          <SkeletonList rows={4} />
        ) : error ? (
          <ErrorState error={error} onRetry={reload} />
        ) : onShelf.length === 0 ? (
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
          <div className="flex flex-col gap-4 px-4 pt-3">
            {/*
              Both numbers named (§6.4). "50 conversations" reads as the whole
              inbox; "50 of 137" is the only version that tells a clerk there is
              more of it, which is what this page never said.

            */}
            <p className="-mb-2 px-1 text-sm text-ink-muted" role="status">
              {/*
                On a SHELF the two numbers stop being comparable, so they stop
                being joined by "of". `onShelf.length` counts one half of the
                mail and `total` counts all of it, and "Showing 8 of 10" read
                as two permits missing rather than two enquiries on the other
                shelf.

                Both are still named (§6.4) - the shelf's own count, and the
                inbox total that says there is more of it - but as two facts
                rather than one ratio, because that is what they are. An
                applicant has no shelves and keeps the sentence it always had.
              */}
              {readerIsOfficer ? (
                <>
                  Showing {onShelf.length.toLocaleString()} {shelfNoun(shelf, onShelf.length)}
                  {' · '}
                  {total.toLocaleString()} conversation{total === 1 ? '' : 's'} in all
                </>
              ) : (
                <>
                  Showing {onShelf.length.toLocaleString()} of {total.toLocaleString()} conversation
                  {total === 1 ? '' : 's'}
                </>
              )}
              {narrow !== 'all' ? ` (${NARROW_LABELS[narrow].toLowerCase()})` : ''}
              {query ? ', searched within the ones loaded' : ''}.
            </p>
            {/*
              No card of its own any more — the panel around the whole column
              is the card now, so a second rounded white box inside it would
              draw a border nobody needs and inset the rows from their own
              scroller.
            */}
            <ul aria-label="Conversations" className="divide-y divide-line">
              {onShelf.map((t) => (
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
      </div>
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
  /*
   * Nothing HANDLES a general enquiry, so nothing says so.
   *
   * The row carries the office that spoke last, which is worth printing on a
   * card in a list of seven. In the pane it read "Handled by Bureau of Fire
   * Protection" directly above a picker with that office already selected —
   * the same fact twice, and the first telling of it untrue: an enquiry has no
   * filing, so there is nobody it is assigned to.
   */
  const handledElsewhere =
    selected?.kind !== 'general' && paneOffice && paneOffice !== selected?.counterparty.name
      ? paneOffice
      : null

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
      /*
        No height of its own any more: the row it sits in has one, and a pane
        that also declared `100dvh-9rem` was measuring the viewport from
        inside a box already measured from the viewport - which is how it came
        to hang past the bottom of the screen by exactly the height of the
        header above it.
      */
      className="flex h-full min-h-0 flex-col overflow-hidden rounded-xl bg-white shadow-card"
    >
      {/*
        * Two lines, not three.
        *
        * The header sat above a fixed-height transcript, so every line in it
        * was a line of the conversation the reader could not see. "Handled by"
        * and the tracking id are both short and both secondary; they belong on
        * one row, separated the way the cards separate them. The title keeps
        * its own line because it is what the pane is about.
        */}
      <header className="flex items-center gap-3 bg-royal-tint px-5 py-3">
        <Avatar size={38} />
        <div className="min-w-0 flex-1">
          <p className="flex items-center gap-2">
            <span className="min-w-0 truncate text-base font-bold text-ink">{paneTitle}</span>
            {/*
              Repeated here, and not only on the row behind it. An officer
              reading a conversation has the list hidden on a phone and
              scrolled past on a desktop, and the finding matters most while
              they are composing the reply.
            */}
            {selected.counterparty.standing && (
              <StandingNote standing={selected.counterparty.standing} />
            )}
          </p>
          {(handledElsewhere || paneSubtitle) && (
            <p className="truncate text-xs text-ink-secondary">
              {handledElsewhere && (
                <span className="font-semibold text-royal">Handled by {handledElsewhere}</span>
              )}
              {handledElsewhere && paneSubtitle && <span className="text-ink-muted"> · </span>}
              {paneSubtitle && <span className="italic">{paneSubtitle}</span>}
            </p>
          )}
        </div>
        {/*
          ---- Back on a phone, and nothing at all on a desktop -------------

          This was an X, on every width. On a desktop the list and the
          transcript are side by side, so closing the conversation empties half
          the screen and gives the reader nothing - "nonsense", and it is
          [client, 1 October 2026].

          On a phone it is not nothing: the list is hidden while a conversation
          is open, so this was the only way back to it. Deleting it outright
          would have left a phone reader with the browser's own back button and
          no control on the page.

          So it survives where it does something, and says what it does. An X
          reads as "dismiss this" - it was closing a conversation nobody asked
          to close; an arrow and the word read as "back to the list", which is
          where it actually goes.
        */}
        <button
          type="button"
          onClick={() => setParams({})}
          className="flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-sm font-semibold text-royal hover:bg-white/60 lg:hidden"
        >
          <ArrowLeftIcon size={18} aria-hidden="true" />
          Back
        </button>
      </header>

      <MessageThreadView
        key={rowKey(selected)}
        target={
          selected.kind === 'admin'
            ? { kind: 'admin' }
            : selected.kind === 'general'
              ? { kind: 'general', userId: selected.user_id }
              : { kind: 'application', applicationId: selected.application_id! }
        }
        initialOfficeId={linkedOffice}
        className="flex-1 px-5 pb-5 pt-4"
        scrollClassName="min-h-0"
        onSent={reload}
      />
    </section>
  ) : (
    // `h-full min-h-0`: it fills the row like the real pane does, rather than
    // shrinking to its one sentence and leaving the column half empty.
    <div className="hidden h-full min-h-0 items-center justify-center rounded-xl bg-white p-10 shadow-card lg:flex">
      <p className="text-sm text-ink-secondary">
        Choose a conversation on the left to read and reply.
      </p>
    </div>
  )

  return (
    /*
     * The screen is one viewport tall and does not scroll; the two columns
     * inside it do.
     *
     * The height is taken from the viewport rather than from the content:
     * `100dvh` less the shell's own padding (`pt-8` plus `pb-28` on a phone,
     * `lg:pb-16` on a desktop). `dvh` and not `vh`, because a phone's address
     * bar shrinks and grows as you scroll and `vh` measures the tall state -
     * which puts the composer under the browser's own furniture.
     *
     * `minmax(0,1fr)` at every width, not just lg. A grid item's automatic
     * minimum size is its min-content, and a thread title is one nowrap line,
     * so the single mobile column sized itself to the longest subject — 732px
     * on a 390px screen. The `truncate` on the titles only ever took effect
     * once the column was told it may be narrower than they are.
     */
    <div className="flex h-[calc(100dvh-9rem)] flex-col overflow-hidden lg:h-[calc(100dvh-6rem)]">
      <div className="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
        {list}
        {pane}
      </div>
    </div>
  )
}
