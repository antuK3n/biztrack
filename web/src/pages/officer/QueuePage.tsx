import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { InboxIcon } from '../../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import {
  FilterPills,
  PageTitle,
  SortFilter,
  StatusChip,
  type SortFilterOption,
} from '../../components/ui/Proto'
import { applications, assignments } from '../../lib/resources'
import { formatDateTime } from '../../lib/format'
import { applicationStatusMeta } from '../../lib/status'
import { useAsync } from '../../lib/useAsync'
import { useAuth } from '../../stores/auth'
import type { ApplicationListItem, ApplicationStatus, Assignment, PageMeta } from '../../lib/types'

/*
 * Application Verification (PDF p61/p80) — the officer queue restyled to the
 * prototype: pill filters, white shadow rows with the solid payment chip block.
 */

type Tab = 'payment' | 'approval' | 'inspection'

/**
 * The stages, in the order the client described them: "pending payment, next up
 * when a user has paid, for approval, then for inspection ..., then approved".
 *
 * Pending Payment leads the row and is NOT the tab that opens. The officer's
 * work starts at For Approval — nothing on the payment stage is actionable by an
 * officer at all (see PaymentQueueRow) — and landing every officer on a tab they
 * can only read would be a worse screen than the one this fixes.
 */
const TABS: { value: Tab; label: string }[] = [
  { value: 'payment', label: 'Pending Payment' },
  { value: 'approval', label: 'For Approval' },
  { value: 'inspection', label: 'For Inspection' },
]

/**
 * Before the money lands. The stage the client kept reporting as missing:
 * "unpaid applications are still not reflected in the tracking of applications".
 *
 * They were missing because the other two tabs read `/assignments`, and an
 * unpaid filing HAS no assignment. WorkflowService::submit takes a draft to
 * `submitted` and straight on to `pending_payment`; routing is
 * WorkflowService::routeToDepartments, and the only caller is
 * `onPaymentCompleted`. That is deliberate — `assigned_at` starts the office's
 * service-time clock that ProcessingTimeAnalytics and StaffingSimulation
 * measure, and it must not start while an applicant is still typing. So there is
 * no assignment row to find, and no filter on the assignment feed could have
 * surfaced one. Listing `pending_payment` under APPROVAL_STATUSES, as this file
 * used to, was querying for something that structurally cannot exist.
 *
 * Hence a different feed for this tab: `/applications`, filtered by status. See
 * loadPage below.
 *
 * `submitted` rides along even though the register holds none of them: submit()
 * passes through it inside one transaction, so it is only ever observable if a
 * filing gets stuck there. A stuck filing appearing in the wrong tab is a
 * nuisance; a stuck filing appearing in NO tab is the bug being fixed here.
 */
const PAYMENT_STATUSES = ['submitted', 'pending_payment'] as const

/**
 * With the offices, waiting on a decision.
 *
 * `submitted` and `pending_payment` used to be here too, and moved to
 * PAYMENT_STATUSES above rather than being listed in both places. They cannot
 * reach this tab — it reads the assignment feed and they have no assignments —
 * so leaving them would have put two entries in this tab's own status Filter
 * that always return nothing. A control that reliably does nothing is the
 * failure mode this codebase already paid for once (see the note on `SortFilter`
 * in e2e/track-search.spec.ts).
 *
 * Checked against ApplicationStatus: draft, submitted, pending_payment,
 * under_review, for_inspection, approved, rejected, returned, cancelled. Every
 * pre-decision status except draft now has a tab, and draft belongs out — an
 * unfiled draft is not an officer's work.
 */
const APPROVAL_STATUSES = ['under_review', 'returned', 'for_inspection'] as const
/**
 * Statuses on the inspection/approved side, for a filing this office has
 * already signed off.
 *
 * `under_review` is in here as well as in APPROVAL_STATUSES, and that is not a
 * duplicate — see the note on OPEN_ASSIGNMENT_STATUSES below. The two lists are
 * never read on their own: each is paired with an assignment-status filter, and
 * between them those two filters partition this office's work with nothing
 * falling between. Same filing, same status, opposite sides of the partition
 * depending on whether the office reading it still owes a review.
 */
const INSPECTION_STATUSES = ['under_review', 'for_inspection', 'approved', 'issued'] as const

const TAB_STATUSES: Record<Tab, readonly ApplicationStatus[]> = {
  payment: PAYMENT_STATUSES,
  approval: APPROVAL_STATUSES,
  inspection: INSPECTION_STATUSES,
}

const TAB_LABEL: Record<Tab, string> = {
  payment: 'Pending Payment',
  approval: 'For Approval',
  inspection: 'For Inspection',
}

/**
 * What a status MEANS inside the tab it is being offered in.
 *
 * The Filter dropdown lists the tab's own statuses, and since the two
 * assignment tabs overlap (see INSPECTION_STATUSES) the same filing status can
 * appear in both. `applicationStatusMeta` labels it the same way in each,
 * because it describes the FILING and knows nothing about which office is
 * reading — so an officer would see "For Approval" offered inside the For
 * Inspection tab and reasonably read it as a bug.
 *
 * These labels say what picking it narrows to from this seat. Deliberately only
 * the two entries the overlap introduced — everything else keeps the shared
 * filing label, which is right where the two readings agree, and renaming a
 * status that was already unambiguous would move a control out from under the
 * tests that press it by name.
 */
const STATUS_IN_TAB: Record<Tab, Partial<Record<ApplicationStatus, string>>> = {
  payment: {},
  approval: {
    for_inspection: 'Waiting on you · another office booked an inspection',
  },
  inspection: {
    under_review: 'You have approved · other offices still reviewing',
  },
}

/**
 * This office's own assignment states that still want a decision (item 111).
 *
 * "After approving an application it still shows approval." The application's
 * status is not this office's status: a filing is routed to every office that
 * issues one of its clearances, and it stays `under_review` until ALL of them
 * have signed off (WorkflowService::afterReviewProgress). So an office that
 * approved its part this morning was still shown the row under For Approval,
 * because the filing really was still under review — by somebody else.
 *
 * Filtering on the assignment instead answers the question the tab is actually
 * asking, which is "what is waiting on ME". `completed` is the one state left
 * out. `returned` stays in: the office sent it back and the filing comes to it
 * again when the applicant answers, so it is still that office's open work.
 *
 * Pending Payment must never adopt this: an unrouted filing matches none of
 * these states, which is precisely how the eight unpaid filings in the register
 * were being filtered out of a tab whose status list already named them.
 */
const OPEN_ASSIGNMENT_STATUSES = 'pending,in_progress,returned'

/**
 * The other half of the partition: this office is done, somebody else is not.
 *
 * ── Why the tabs had a hole in them (INS-2) ────────────────────────────────
 *
 * Until now For Approval carried the assignment filter above and For Inspection
 * carried none, so the second tab was decided by the FILING's status alone.
 * Both filters were written against an invariant that commit 5da4daa deleted —
 * that an assignment can only be pending while the filing is `under_review` or
 * `returned`. Since that commit the first inspecting office's approval flips the
 * whole filing to `for_inspection` while the other five assignments are still
 * `pending`, and two things went wrong at once:
 *
 *  - An office that still OWED a review on a `for_inspection` filing did not
 *    appear under For Approval at all (the tab excluded the status) and did
 *    appear under For Inspection (which asked nothing about the assignment).
 *    Its outstanding paperwork was filed under a heading about site visits, and
 *    the row opened on a screen with no controls — INS-1, reached through the
 *    wrong door. Searching For Approval for it answered "Nothing matches",
 *    which is the client's report 4 in its live form.
 *  - BPLO, whose BUSINESS permit type sets `requires_inspection = 0`, approved a
 *    filing and watched it vanish from BOTH tabs: For Approval had dropped it
 *    because BPLO's assignment was now `completed`, and For Inspection did not
 *    want it because the filing was still `under_review`. That is the client's
 *    report 1 — "I approved it as BPLO and it is not in For Inspection".
 *
 * ── The rule now ──────────────────────────────────────────────────────────
 *
 * A filing lands in the tab matching THIS OFFICE'S outstanding work, not the
 * filing's global status. For Approval is "my review is still open"; For
 * Inspection is "my review is closed and the filing has not finished". The
 * predicate is the same one ReviewPage branches on — an assignment that is
 * `completed` is a review this office no longer owes — so a row can no longer
 * appear in a tab whose screen then offers nothing to do.
 *
 * The two application-status lists overlap on `under_review` and
 * `for_inspection` on purpose: those are exactly the statuses where two offices
 * on one filing are in different states. The assignment filter, not the status
 * list, is what keeps a single filing out of both tabs for a single reader.
 *
 * Terminal statuses (`rejected`, `cancelled`) are in neither list, deliberately.
 * 101 rejected filings on this register still carry a pending assignment
 * (INS-5); they are not work waiting on anybody and must not be offered as if
 * they were.
 */
const DONE_ASSIGNMENT_STATUSES = 'completed'

/** Pre-payment statuses show the orange block; everything else is paid. */
const PENDING_PAYMENT_STATUSES: readonly string[] = PAYMENT_STATUSES

/**
 * Who may open the Pending Payment tab at all.
 *
 * Not a convenience: it is `App\Support\ApplicationVisibility` restated where the
 * user can see it. An office reviewer's boundary is the assignment row —
 * `orWhereHas('assignments', department_id = mine)` — and an unpaid filing has
 * none, so `/applications?status=pending_payment` answers a sanitary officer with
 * an empty page no matter how many are outstanding. That is the boundary failing
 * closed and it is the correct answer: until the fees are settled the filing has
 * not been routed to any office, so there is no office whose remit it is in. It
 * would be wrong to widen it by reading the requested permit types instead —
 * that would show every office a filing it has not been given, which is the
 * data-leak item 56 and item 111 closed.
 *
 * What is left is who owns the stage when nobody owns the filing, and that is
 * BPLO and the super admin: BPLO issues the Tax Order of Payment and coordinates
 * every other office's clearance, and it is the one office role seeded with
 * `application.view_any_office` (RbacSeeder). They are also exactly who the
 * client means by "the admin side".
 *
 * The tab is hidden rather than shown empty because an empty queue is a claim.
 * "Nothing is pending payment" is not something this screen can truthfully tell
 * a sanitary officer while eight filings sit unpaid.
 */
const ANY_OFFICE = 'application.view_any_office'

/**
 * How many rows to put on the page at once.
 *
 * The feed behind this screen is every assignment the office has ever held —
 * 1,649 for BPLO and 4,620 for an admin who sees all of them. It used to be
 * fetched whole and rendered whole, which is the same 2.2 MB that took the
 * browser down on Inspections; the "For Inspection" tab is almost entirely
 * completed work, so it is the larger of the two.
 */
const PAGE_SIZE = 25

/*
 * ── Where sort, filter and search run on this screen ───────────────────────
 *
 * Not the same answer for all three, and — since Pending Payment arrived — not
 * the same answer for all three tabs either, because they are not reading the
 * same endpoint. Filtering a page in the browser is precisely the bug the tab
 * split already had (see the class comment on QueuePage).
 *
 *  - Filter → server, on every tab. Both endpoints take a status list, so
 *    narrowing a tab to one status is a query change and the totals stay exact.
 *  - Search → server on Pending Payment, browser on the other two. Not a
 *    preference: `/applications` takes `q` (tracking ID or business name, two
 *    LIKEs in SQL) and `/assignments` takes nothing of the kind —
 *    AssignmentController::index validates only status / application_status /
 *    page / per_page. Moving the other two tabs off browser search needs `q` on
 *    that endpoint and is not in this change; see the report.
 *  - Sort → browser on every tab, because neither endpoint accepts an ordering
 *    parameter (`/assignments` orders assigned_at DESC and `/applications`
 *    created_at DESC, both unconditionally).
 *
 * Where the browser is doing the work the page asks for the API's ceiling
 * instead of a screenful, so it usually covers the whole queue in one request:
 * an office's "For Approval" tab is tens of rows, not thousands. `maxPerPage`
 * is 200 (PaginatesLists) and asking for more is clamped, not obeyed. Where the
 * SERVER is doing the work no deep page is needed, and the status line says
 * "Showing 3 of 3" rather than "3 of the 200 loaded" because for once that is
 * the whole truth.
 */
const DEEP_PAGE_SIZE = 200

/**
 * How long to wait before a keystroke becomes a request.
 *
 * Only the server-searched tab has this problem, and it is a real one: without
 * it "roberto" is eight authenticated queries against the register, seven of
 * which are answers nobody will read. Long enough to swallow typing, short
 * enough that the list has moved before a hand leaves the keyboard.
 */
const SEARCH_DEBOUNCE_MS = 250

type SortKey = 'newest' | 'waiting' | 'business'

const SORTS: SortFilterOption[] = [
  { value: 'newest', label: 'Newest first' },
  { value: 'waiting', label: 'Waiting longest' },
  { value: 'business', label: 'Business name (A–Z)' },
]

/**
 * One row of the queue, whichever feed it came from.
 *
 * The two feeds answer different shapes — an assignment carries the office and
 * its own clock, an application does not have one yet — and every screen
 * behaviour below (paging, merging, sorting, the status line) is written once
 * against this rather than twice against the union. Normalising at the fetch is
 * what keeps a third tab from being a second copy of the page.
 */
interface QueueItem {
  /**
   * React key, and the identity the page merges on. Prefixed per feed because
   * an assignment id and an application id are both small integers and they
   * collide — an unprefixed key would silently drop rows the moment the two
   * feeds ever appeared in one list.
   */
  key: string
  /**
   * The review sheet, or null when there is nothing yet to open. A filing that
   * has not been paid has not been routed, so no assignment exists and
   * `/staff/queue/:id` has no id to be given. See PaymentQueueRow.
   */
  href: string | null
  trackingId: string
  /** Business name, or the tracking ID when the register row is gone. */
  name: string
  /** True when the business was removed and `name` is standing in for it. */
  nameIsFallback: boolean
  /** Routed-at for an assignment; filed-at for a filing no office holds yet. */
  at: string | null
  /** `at` in milliseconds, for the browser-side sorts. Missing sorts as brand new. */
  atMs: number
  pendingPayment: boolean
}

/** What one page of either feed looks like once it has been normalised. */
interface QueueFeed {
  items: QueueItem[]
  meta: PageMeta
  /**
   * Per-status totals over the whole scoped set, from `/assignments` only.
   * `/applications` needs no equivalent: its `meta.total` is already the total
   * for exactly the status list that was asked for.
   */
  counts?: Partial<Record<ApplicationStatus, number>>
}

/**
 * The business behind a filing, when there still is one.
 *
 * A business can be removed from the register after its filings are decided —
 * 375 of the 4,620 assignments on this system point at one that is gone, and the
 * API sends `business: null` for every one of them. The row still has to render:
 * the filing happened, and an officer looking for it should find it rather than
 * meet a blank page. (Both `Assignment['application']['business']` and
 * `ApplicationListItem['business']` were typed non-nullable once, which is why
 * nothing caught this — see the report.)
 */
function nameOf(business: { name: string } | null | undefined, trackingId: string) {
  const name = business?.name
  return { name: name ?? trackingId, nameIsFallback: !name }
}

function fromAssignment(item: Assignment): QueueItem {
  const app = item.application
  return {
    key: `assignment:${item.id}`,
    href: `/staff/queue/${item.id}`,
    trackingId: app.tracking_id,
    ...nameOf(app.business, app.tracking_id),
    at: item.assigned_at,
    atMs: item.assigned_at ? new Date(item.assigned_at).getTime() : 0,
    pendingPayment: PENDING_PAYMENT_STATUSES.includes(app.status),
  }
}

function fromApplication(app: ApplicationListItem): QueueItem {
  return {
    key: `application:${app.id}`,
    href: null,
    trackingId: app.tracking_id,
    ...nameOf(app.business, app.tracking_id),
    /*
     * Filed-at, not routed-at, and the row says so. `submitted_at` is the only
     * clock an unpaid filing has, and it is the one the officer wants: it is how
     * long the applicant has been sitting on an unsettled Tax Order of Payment.
     */
    at: app.submitted_at,
    atMs: app.submitted_at ? new Date(app.submitted_at).getTime() : 0,
    pendingPayment: PENDING_PAYMENT_STATUSES.includes(app.status),
  }
}

/**
 * One page of whichever feed the tab in hand is built on.
 *
 * The tab decides the endpoint, not a flag on one endpoint, because the two
 * questions genuinely have different answers: "which of my office's assignments
 * is open" cannot be asked about a filing that has none.
 */
async function loadPage(args: {
  tab: Tab
  statuses: string
  assignmentStatuses?: string
  /** Server-side search term, Pending Payment only. '' means no search. */
  query: string
  page: number
  perPage: number
}): Promise<QueueFeed> {
  if (args.tab === 'payment') {
    const res = await applications.page({
      status: args.statuses,
      ...(args.query ? { q: args.query } : {}),
      page: args.page,
      per_page: args.perPage,
    })

    return { items: res.data.map(fromApplication), meta: res.meta }
  }

  const res = await assignments.page({
    application_status: args.statuses,
    ...(args.assignmentStatuses ? { status: args.assignmentStatuses } : {}),
    ...(args.query ? { q: args.query } : {}),
    page: args.page,
    per_page: args.perPage,
  })

  return {
    items: res.data.map(fromAssignment),
    meta: res.meta,
    counts: res.meta.application_status_counts,
  }
}

/** Is the browser being asked to do work the current page of rows cannot answer? */
function isDeep(query: string, sort: SortKey): boolean {
  return query.trim() !== '' || sort !== 'newest'
}

/**
 * What an officer types into the queue search, matched in the browser.
 *
 * Tracking ID and business name — the two things on the row, and the two the
 * assignment feed carries. There is no applicant-given title in
 * `AssignmentResource.application`, so unlike the applicant's Track page this
 * one cannot search on it. `/applications?q=` searches the same two columns, so
 * the server-searched tab and the browser-searched ones agree on what a search
 * covers even though they disagree on where it runs.
 */
function matchesSearch(item: QueueItem, needle: string): boolean {
  if (!needle) return true
  return `${item.trackingId} ${item.nameIsFallback ? '' : item.name}`.toLowerCase().includes(needle)
}

const CARD = 'flex items-stretch overflow-hidden rounded-lg bg-white shadow-card'

function QueueRow({ item }: { item: QueueItem }) {
  const body = (
    <>
      <div className="min-w-0 flex-1 px-6 py-4">
        <p className="truncate text-[17px] font-bold text-ink">{item.name}</p>
        {item.nameIsFallback && (
          <p className="mt-0.5 text-xs font-semibold uppercase tracking-wide text-ink-muted">
            Business removed from the register
          </p>
        )}
        <p className="mt-0.5 text-sm italic text-ink-muted">
          {item.href ? formatDateTime(item.at) : `Filed ${formatDateTime(item.at)}`}
        </p>
        {/*
          * Why this row does not open, said on the row.
          *
          * Every other row on this screen is a link to a review sheet, so one
          * that is not needs to explain itself rather than read as broken. And
          * the explanation is the answer to the question the tab raises: there
          * is no review sheet because there is no assignment, and there is no
          * assignment because nobody has been routed the filing yet. Nothing
          * here is an officer's to act on — the applicant settles the Tax Order
          * of Payment and WorkflowService routes it on the way through.
          */}
        {!item.href && (
          <p className="mt-1 text-sm text-ink-muted">
            Waiting on the applicant’s payment. It reaches an office for review once the fees are
            settled.
          </p>
        )}
      </div>
      <StatusChip
        tone={item.pendingPayment ? 'orange' : 'green'}
        className="w-28 shrink-0 rounded-none! px-4 py-3 text-sm"
      >
        {item.pendingPayment ? (
          <span>
            Pending
            <br />
            Payment
          </span>
        ) : (
          'Paid'
        )}
      </StatusChip>
    </>
  )

  return (
    <li>
      {item.href ? (
        <Link to={item.href} className={`${CARD} transition-shadow hover:shadow-raised`}>
          {body}
        </Link>
      ) : (
        <div className={CARD}>{body}</div>
      )}
    </li>
  )
}

/**
 * The officer queue.
 *
 * Every tab is a server-side filter over a paged feed. Splitting the tabs in the
 * browser is what made this fragile: it pulled the office's whole assignment
 * history to show a screenful, and against a paged feed it would have counted
 * one page's rows and presented that as the queue — a number that is always
 * plausible and always wrong. The assignment tabs' totals come from
 * `meta.application_status_counts`, counted over the whole department-scoped
 * set; Pending Payment's comes from `meta.total`, which is already the total for
 * the status list it asked for.
 */
export function QueuePage() {
  const canReadEveryOffice = useAuth((s) => Boolean(s.user?.permissions.includes(ANY_OFFICE)))
  const [tab, setTab] = useState<Tab>('approval')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<QueueItem[]>([])
  const [search, setSearch] = useState('')
  const [sort, setSort] = useState<SortKey>('newest')
  /** '' means the whole tab; otherwise one status inside it. */
  const [statusFilter, setStatusFilter] = useState('')
  /** The search the SERVER has been asked for. Pending Payment only; see below. */
  const [serverQuery, setServerQuery] = useState('')

  // See ANY_OFFICE: an office reviewer would be handed a permanently empty tab,
  // and an empty queue is a claim this screen cannot make to them truthfully.
  const tabs = canReadEveryOffice ? TABS : TABS.filter((t) => t.value !== 'payment')
  /*
   * Every tab searches on the server now, not just Pending Payment.
   *
   * The other two used to filter the rows already loaded, and reported it
   * honestly — "Showing 0 of the 13 loaded". Honest and still wrong twice
   * over: a filing past the first page could not be found at all, and the
   * search only ever looked inside the OPEN tab. An officer on For Approval
   * searching a business whose filing had moved on to For Inspection was told
   * "Nothing matches". It matched; it was one tab away.
   *
   * `/assignments` had no `q` at the time, which is why this was scoped to the
   * one tab that reads `/applications`. It has one now, applied inside the
   * department scoping so an office still cannot search its way to a filing it
   * was never routed.
   */
  const searchesOnServer = true

  /*
   * The filter narrows the tab's status list rather than replacing it, so
   * picking a status inside the For Approval tab cannot silently show
   * inspection work.
   *
   * The tab and one of its statuses are now both called "For Approval", and
   * that is not a collision to fix: the tab is what is with the offices awaiting
   * a decision — under review, or sent back — and the status is the first of
   * those two.
   */
  const tabStatuses = TAB_STATUSES[tab]
  const activeStatuses: readonly ApplicationStatus[] = statusFilter
    ? [statusFilter as ApplicationStatus]
    : tabStatuses
  const statuses = activeStatuses.join(',')
  /*
   * The half of the partition this tab is asking about (INS-2).
   *
   * Both assignment tabs carry one now. For Approval asks "what review is still
   * open for me"; For Inspection asks "what have I signed off that is not
   * finished yet". Pending Payment gets `undefined` and must keep getting it —
   * its rows come from `/applications` and have no assignment at all, so any
   * value here would filter the whole tab to nothing (see PAYMENT_STATUSES).
   *
   * A useful side effect: `AssignmentController::statusCounts` applies this same
   * filter, so the "Showing N of M" total and the tab badges now count the tab
   * the officer is actually looking at. For Inspection previously counted every
   * assignment the office had ever held in those statuses, open or closed.
   */
  const assignmentStatuses =
    tab === 'approval'
      ? OPEN_ASSIGNMENT_STATUSES
      : tab === 'inspection'
        ? DONE_ASSIGNMENT_STATUSES
        : undefined
  // A deep page buys nothing where the server is doing the searching; only the
  // browser-side sorts still need more rows than fit on a screen.
  const deep = searchesOnServer ? sort !== 'newest' : isDeep(search, sort)
  const perPage = deep ? DEEP_PAGE_SIZE : PAGE_SIZE

  const { data, loading, error, reload } = useAsync(
    () => loadPage({ tab, statuses, assignmentStatuses, query: serverQuery, page, perPage }),
    [tab, statuses, assignmentStatuses, serverQuery, page, perPage],
  )

  // Paging in extends the list being read; a new tab starts its own list. Merged
  // by key so that Try again after a failed page cannot show its rows twice.
  useEffect(() => {
    if (!data) return
    setRows((prev) => {
      if (data.meta.current_page === 1) return data.items
      const seen = new Set(prev.map((r) => r.key))
      return [...prev, ...data.items.filter((r) => !seen.has(r.key))]
    })
  }, [data])

  /*
   * Any change to what is being asked for restarts the list.
   * Done in the handlers rather than an effect on purpose: an effect would see
   * the new query with the old page number first and fire a request for page 3
   * of a list that no longer exists before correcting itself.
   */
  function restart() {
    setPage(1)
    setRows([])
  }

  /*
   * Typing, turned into a query — the one restart that has to be an effect.
   *
   * It cannot go in `changeSearch` because the request must lag the keystroke
   * (SEARCH_DEBOUNCE_MS), and by the time the timer fires there is no handler
   * left to be in. The hazard the handlers avoid is dodged instead by moving the
   * page number in the same commit as the term: React batches these two, so
   * `useAsync` is never shown the new search beside the old page and cannot fire
   * a request for page 3 of a list that no longer exists.
   *
   * The early return on an unchanged term is what keeps `serverQuery` safe in
   * the dependency list, and clearing it on the way out of the tab is what stops
   * a stale `q` riding along on the next tab's request.
   */
  useEffect(() => {
    if (!searchesOnServer) {
      if (serverQuery !== '') {
        setServerQuery('')
        restart()
      }

      return
    }

    const trimmed = search.trim()
    if (trimmed === serverQuery) return

    const timer = setTimeout(() => {
      setServerQuery(trimmed)
      restart()
    }, SEARCH_DEBOUNCE_MS)

    return () => clearTimeout(timer)
  }, [search, searchesOnServer, serverQuery])

  function selectTab(next: Tab) {
    if (next === tab) return
    setTab(next)
    // The status filter belongs to the tab it was chosen in — For Approval is
    // not one of the inspection tab's statuses, and carrying it across would
    // hand the server a status list that matches nothing.
    setStatusFilter('')
    restart()
  }

  function selectStatus(next: string) {
    if (next === statusFilter) return
    setStatusFilter(next)
    restart()
  }

  /**
   * Search and sort only restart the list when they change how deep it is
   * fetched. On the server-searched tab the search's restart is the debounce's,
   * above — restarting here as well would throw away the rows on screen a
   * quarter-second before the replacements were even asked for.
   */
  function changeSearch(next: string) {
    setSearch(next)
    if (!searchesOnServer && isDeep(next, sort) !== isDeep(search, sort)) restart()
  }

  function changeSort(next: SortKey) {
    setSort(next)
    const was = searchesOnServer ? sort !== 'newest' : isDeep(search, sort)
    const now = searchesOnServer ? next !== 'newest' : isDeep(search, next)
    if (was !== now) restart()
  }

  // Counted over the whole set, not the page in hand. `/applications` has no
  // per-status breakdown and needs none: it was asked for exactly these statuses,
  // so its `total` already is this tab's total.
  const counts = data?.counts
  const total = counts
    ? activeStatuses.reduce((sum, s) => sum + (counts[s] ?? 0), 0)
    : (data?.meta.total ?? 0)
  const hasMore = data ? data.meta.current_page < data.meta.last_page : false
  const firstLoad = loading && rows.length === 0

  /** Status options for the tab in hand — a tab never offers a status it excludes. */
  const statusOptions: SortFilterOption[] = [
    { value: '', label: `All in ${TAB_LABEL[tab]}` },
    ...tabStatuses.map((s) => ({
      value: s,
      label: STATUS_IN_TAB[tab][s] ?? applicationStatusMeta(s).label,
    })),
  ]

  const needle = search.trim().toLowerCase()
  // Empty on the server-searched tab: the rows in hand are already the matches,
  // and filtering them again would only re-apply the same rule less well.
  const browserNeedle = searchesOnServer ? '' : needle
  const visible = rows
    .filter((item) => matchesSearch(item, browserNeedle))
    // Copied before sorting: `rows` is state, and Array.prototype.sort is in
    // place — sorting it directly would rewrite the accumulated pages.
    .slice()
    .sort((a, b) => {
      if (sort === 'waiting') return a.atMs - b.atMs
      // Rows whose business is gone are keyed by tracking ID on screen, so that
      // is what they sort by too — `name` already holds the fallback.
      if (sort === 'business') return a.name.localeCompare(b.name)
      return b.atMs - a.atMs
    })

  const sortLabel = SORTS.find((s) => s.value === sort)?.label.toLowerCase() ?? 'newest first'
  const partial = rows.length < total
  const narrowed = Boolean(needle || statusFilter)

  /*
   * What this screen is actually showing, in one sentence, announced.
   *
   * A first page must never read as the whole list — and neither must a search
   * over one. Where the browser is searching, the count is stated against the
   * rows loaded and the queue total is given beside it, so an officer can see
   * that "2 matches" means two out of 200 read, not two in the register.
   *
   * Where the SERVER is searching, that hedge would be a different kind of lie:
   * `meta.total` counts every filing matching the term, not every filing on the
   * page, so "Showing 1 of 1" is exactly true and saying "of the 25 loaded"
   * would understate a search that really did cover the register. The sort is
   * still the browser's, so a non-default sort keeps its caveat.
   */
  const summary = firstLoad
    ? 'Loading the queue…'
    : error
      ? ''
      : searchesOnServer
        ? rows.length === 0
          ? needle
            ? `Nothing in this queue matches “${search.trim()}”.`
            : 'Nothing in this queue right now.'
          : `Showing ${rows.length.toLocaleString()} of ${total.toLocaleString()}` +
            `${needle ? ` matching “${search.trim()}”` : ''}, ${sortLabel}.` +
            `${sort !== 'newest' && partial ? ' Load more to sort the rest.' : ''}`
        : rows.length === 0
          ? 'Nothing in this queue right now.'
          : needle || sort !== 'newest'
            ? `Showing ${visible.length.toLocaleString()} of the ${rows.length.toLocaleString()} loaded` +
              `${partial ? ` (${total.toLocaleString()} in this queue)` : ''}, ${sortLabel}.` +
              `${partial ? ' Load more to reach the rest.' : ''}`
            : `Showing ${rows.length.toLocaleString()} of ${total.toLocaleString()}, newest first.`

  function clearSearchAndFilter() {
    setSearch('')
    if (statusFilter) selectStatus('')
    if (!searchesOnServer && isDeep('', sort) !== isDeep(search, sort)) restart()
  }

  /*
   * Which empty screen to show, keyed on what the officer can see rather than on
   * what came back. Keyed on `visible` and not on `rows` so that a search that
   * found nothing gets the search's empty state and its way out — including on
   * the server-searched tab, where a fruitless search returns no rows at all and
   * would otherwise fall through to "your queue is clear" while the queue is
   * full.
   */
  const nothingToShow = visible.length === 0

  return (
    <div>
      <PageTitle
        right={
          <span className="flex flex-wrap items-center gap-x-4 gap-y-2 pb-1">
            {/*
              * A placeholder is not an accessible name — it vanishes on the
              * first keystroke — so the field carries a real label, hidden
              * only because the magnifying-glass context is obvious visually.
              */}
            <label htmlFor="queue-search" className="sr-only">
              Search this queue by tracking ID or business name
            </label>
            <input
              id="queue-search"
              type="search"
              value={search}
              onChange={(e) => changeSearch(e.target.value)}
              placeholder="Search tracking ID or business…"
              className="w-64 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
            <SortFilter
              sort={{ value: sort, options: SORTS, onChange: (v) => changeSort(v as SortKey) }}
              filter={{ value: statusFilter, options: statusOptions, onChange: selectStatus }}
            />
          </span>
        }
      >
        Application Verification
      </PageTitle>

      <div className="mb-5">
        <FilterPills options={tabs} value={tab} onChange={selectTab} />
      </div>

      {/*
        * Mounted unconditionally, not tucked inside the list branch: an
        * aria-live region only announces changes to text it already owns, so
        * one that is unmounted whenever the list is empty stays silent on the
        * single result that matters most — the search that found nothing.
        */}
      <p role="status" aria-live="polite" className={summary ? 'mb-3 text-sm text-ink-muted' : ''}>
        {summary}
      </p>

      {firstLoad ? (
        <SkeletonList rows={5} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : nothingToShow && !needle ? (
        <EmptyState
          icon={InboxIcon}
          title={narrowed ? 'Nothing matches these filters' : 'Your queue is clear'}
          description={
            narrowed
              ? 'No application in this queue has that status. Try a different filter.'
              : tab === 'payment'
                ? 'No filing is waiting on payment right now.'
                : tab === 'approval'
                  ? 'Nothing is waiting on your department’s review right now.'
                  : // Both halves of what this tab now holds: filings this
                    // office has signed off and that have not finished.
                    'Nothing your office has approved is still in progress.'
          }
        />
      ) : nothingToShow ? (
        <>
          <EmptyState
            icon={InboxIcon}
            title={`Nothing matches “${search.trim()}”`}
            description={
              searchesOnServer
                ? 'The whole queue was searched. Check the tracking ID, or search by the business name instead.'
                : partial
                  ? `Searched the ${rows.length.toLocaleString()} rows loaded so far. Load more to search deeper, or check the tracking ID.`
                  : 'Check the tracking ID, or search by the business name instead.'
            }
          />
          {/* A dead end needs a way out, not just an explanation of itself. */}
          <div className="mt-4 flex justify-center gap-3">
            <button
              type="button"
              onClick={clearSearchAndFilter}
              className="rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold text-royal transition-colors hover:bg-canvas"
            >
              Clear search
            </button>
            {hasMore && !searchesOnServer && (
              <button
                type="button"
                onClick={() => setPage((p) => p + 1)}
                disabled={loading}
                className="rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold text-royal transition-colors hover:bg-canvas disabled:cursor-wait disabled:text-ink-muted"
              >
                {loading ? 'Loading…' : 'Load more and keep searching'}
              </button>
            )}
          </div>
        </>
      ) : (
        <>
          <ul className="space-y-4">
            {visible.map((item) => (
              <QueueRow key={item.key} item={item} />
            ))}
          </ul>
          {hasMore && (
            <button
              type="button"
              onClick={() => setPage((p) => p + 1)}
              disabled={loading}
              className="mt-5 w-full rounded-xl border border-line bg-white py-3 text-sm font-semibold text-royal transition-colors hover:bg-canvas disabled:cursor-wait disabled:text-ink-muted"
            >
              {loading ? 'Loading…' : 'Load more'}
            </button>
          )}
        </>
      )}
    </div>
  )
}
