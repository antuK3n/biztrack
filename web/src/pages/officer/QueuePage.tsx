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
import { toApiError } from '../../lib/api'
import { applications, assignments } from '../../lib/resources'
import { formatDateTime } from '../../lib/format'
import { applicationStatusMeta } from '../../lib/status'
import { useAsync } from '../../lib/useAsync'
import { useAuth } from '../../stores/auth'
import type {
  ApplicationListItem,
  ApplicationStatus,
  ApplicationType,
  Assignment,
  PageMeta,
} from '../../lib/types'

/*
 * Application Verification (PDF p61/p80) — the officer queue restyled to the
 * prototype: pill filters, white shadow rows with the solid payment chip block.
 */

type Tab = 'approval' | 'inspection' | 'payment' | 'final'

/**
 * The stages, in the order the flow visits them (docs/application-flow-2026-09.md):
 * For Approval → Pending Payment → the other permits (reviewed, then inspected)
 * → BPLO's Final Approval.
 *
 * For Approval opens, and still is not first in the row. The officer's work
 * starts there — nothing on the payment stage is actionable by an officer at all
 * (see the note on the row that cannot be opened) — and landing every officer on
 * a tab they can only read would be a worse screen than the one this fixes. The
 * row is ordered by the process; the default is ordered by the job.
 *
 * ── What these four replaced, and why three tabs were not enough ──────────
 *
 * They were Pending Payment / For Approval / For Inspection, filtering on
 * `submitted`, `under_review` and `for_inspection`. All three of those statuses
 * were deleted on 6 September 2026, so every tab matched nothing and the whole
 * queue was empty however many filings were waiting.
 *
 * Rebuilding it needed a fourth, because BPLO now acts TWICE — once on the main
 * form before payment, once on the whole application after every other permit is
 * in — and those two acts are different work at opposite ends of the process.
 * Folding them into one tab would put a form nobody has paid for next to an
 * application waiting only on a signature.
 */
/*
 * The client's §10 sections. "All" first and selected by default: an officer
 * arriving at the screen should see the office's work, not an empty list under
 * a narrowing they did not choose.
 */
const HOLDER_PILLS: { value: '' | 'unassigned' | 'mine' | 'others'; label: string }[] = [
  { value: '', label: 'All' },
  { value: 'unassigned', label: 'Unassigned' },
  { value: 'mine', label: 'My assigned' },
  { value: 'others', label: 'Assigned to others' },
]

/** What each section is a list OF, said under the pills while it is chosen. */
const HOLDER_HINT: Record<'unassigned' | 'mine' | 'others', string> = {
  unassigned: 'filings nobody has taken yet',
  mine: 'the filings you are officer in charge of',
  others: 'filings a colleague is holding, read-only for you',
}

const TABS: { value: Tab; label: string }[] = [
  { value: 'approval', label: 'For Approval' },
  { value: 'payment', label: 'Pending Payment' },
  { value: 'inspection', label: 'For Inspection' },
  { value: 'final', label: 'Final Approval' },
]

/**
 * What KIND of filing this is (item 97) — the third narrowing on this screen.
 *
 * A genuinely different question from the two pill rows above it, which is why
 * it is a third row rather than more tabs or a second `SortFilter` menu. The
 * tabs ask where the filing is, the holder pills ask whose it is, and this asks
 * what it is; all three cross, and "my assigned renewals awaiting approval" is
 * one officer's working list rather than three separate ones. `SortFilter`
 * carries exactly one `filter` menu and that one is already spent on the tab's
 * statuses, so pills are also the only control here that does not mean altering
 * a shared component.
 *
 * "All filings" rather than plain "All", because the holder row directly above
 * already offers an "All" and two unlabelled pill rows each opening with the
 * same word is two controls the officer cannot tell apart at a glance.
 *
 * Kept when the tab changes, unlike the status filter. A status belongs to the
 * tab it was chosen in — For Approval is not one of the inspection tab's
 * statuses — but a renewal is a renewal in every tab, so carrying it across is
 * what the officer means by having set it.
 */
const TYPE_PILLS: { value: '' | ApplicationType; label: string }[] = [
  { value: '', label: 'All filings' },
  { value: 'new', label: 'New' },
  { value: 'renewal', label: 'Renewal' },
  { value: 'amendment', label: 'Amendment' },
]

/** The same three, as a plural noun, for the sentences that count what is on screen. */
const TYPE_PLURAL: Record<ApplicationType, string> = {
  new: 'new applications',
  renewal: 'renewals',
  amendment: 'amendments',
}


/**
 * Before the money lands, and it is no longer an unrouted filing.
 *
 * The stage the client kept reporting as missing: "unpaid applications are still
 * not reflected in the tracking of applications". The old reason was structural
 * — routing happened on payment, so an unpaid filing had no assignment and no
 * filter on the assignment feed could surface one.
 *
 * That is only half true now. `WorkflowService::submit()` routes to BPLO
 * immediately, so a filing awaiting payment DOES have an assignment: BPLO's,
 * already `completed`, because BPLO approving the form is what raised the bill.
 * The tab still reads `/applications` rather than the assignment feed, for a
 * better reason than before — this stage is waiting on the APPLICANT, not on an
 * office, and there is no office whose queue it belongs in.
 *
 * `submitted` used to ride along here for stuck filings. That status no longer
 * exists; `for_approval` is a real stage with its own tab and does not belong to
 * this one.
 */
const PAYMENT_STATUSES = ['pending_payment'] as const

/**
 * Waiting on this office to READ something.
 *
 * Three filing statuses, because two different offices are answered by this one
 * tab and they are busy at different points:
 *
 *  - `for_approval` / `returned` — BPLO, reading the main form. Its assignment
 *    is `pending` from submission and stays open until it approves.
 *  - `awaiting_other_permits` — one of the five other offices, reading the
 *    clearance the applicant has just applied for. `startClearance()` routes to
 *    that office at that moment, so its assignment opens then and not before.
 *
 * The assignment filter is what keeps those from bleeding into each other, and
 * it does the whole job here: BPLO's assignment is `completed` by
 * `approveMainForm()`, so BPLO drops out of this tab the instant it approves,
 * and each other office drops out as `approveClearance()` completes its own.
 * Nothing needs the clearance status — an office holding an open assignment owes
 * a reading, whichever office it is.
 *
 * `draft` belongs out: an unfiled draft is not an officer's work. Terminal
 * statuses belong out for the reason INSPECTION_STATUSES gives below.
 *
 * ── One row here can have nothing to read, and only on old filings ────────
 *
 * A row whose permit reads "Not Started" is a filing migrated from the previous
 * model, where payment routed all six offices at once. Its assignment is open
 * and the applicant has not started that permit, so the office is holding
 * something with no form behind it yet.
 *
 * No new filing can produce it: `startClearance()` routes the office and moves
 * the permit to `for_approval` in one transaction, so the assignment and the
 * paperwork arrive together. It is left visible rather than filtered out because
 * the row states its own permit's status — an officer reads "Not Started" and
 * knows why there is nothing to do — whereas hiding it would strand a real
 * assignment in no tab at all, which is the failure this screen was rebuilt to
 * end. Filtering it away would also need `clearance_status` on this tab, and
 * that would drop BPLO: its Business Permit pivot is `not_started` for exactly
 * as long as BPLO's own first review is open.
 */
const APPROVAL_STATUSES = ['for_approval', 'returned', 'awaiting_other_permits'] as const

/**
 * This office's permit is out for a site visit.
 *
 * ── Why this tab stopped being a filing status ────────────────────────────
 *
 * It filtered on `for_inspection`, which used to be a status of the APPLICATION
 * and is not one any more. It could not be: five permits are inspected
 * independently, so one filing can have a fire inspection booked, a sanitary
 * inspection passed and a zoning clearance not yet applied for, all at once. A
 * single column cannot hold that, and the column that tried was deleted.
 *
 * So this tab asks the second machine instead — `clearance_status`, which the
 * assignment feed now takes, matched against the reader's OWN office. Every
 * filing here is `awaiting_other_permits`; what varies is whose permit is where,
 * and that is exactly what the filter answers.
 *
 * No assignment-status filter, and that is the important part rather than an
 * omission. `approveClearance()` marks the assignment `completed` at the moment
 * it moves the permit to `for_inspection` — accepting the paperwork and
 * conducting the visit are one office's two acts and only the first closes the
 * assignment — so filtering on an OPEN assignment here would empty the tab of
 * precisely the rows it exists to show.
 *
 * BPLO never appears, and needs no special case: its Business Permit pivot goes
 * `not_started` → `for_approval` → `approved` and is never `for_inspection`.
 *
 * `approved` and `issued` were on this list once and are gone. The reasoning had
 * been that an inspector wants the approved filings still in view — but this is
 * a QUEUE, and a decided filing is nobody's outstanding work. The client: "Those
 * who are already done with the whole application process (accepted and all) is
 * still displayed in the For inspection tab of the Track page of all admins."
 * The register holds over 1,400 approved filings against a handful in flight, so
 * left here the tab converges on a list of finished work with the live cases
 * buried in it. Reaching a decided filing is a different question and already
 * has answers: search finds it by tracking ID or business name, server-side over
 * the whole queue, and the permit is on the business.
 */
const INSPECTION_STATUSES = ['awaiting_other_permits'] as const

/** The clearance statuses that put a row in the For Inspection tab. */
const INSPECTION_CLEARANCE_STATUSES = 'for_inspection'

/**
 * BPLO's second act: the application is complete and wants a signature.
 *
 * `refreshReadiness()` moves a filing here the moment the last required permit
 * is approved, and moves it back out if one stops being approved. So this tab is
 * the set of applications that qualify RIGHT NOW — nothing in it is waiting on
 * anybody but BPLO.
 *
 * It reads the assignment feed rather than `/applications`, unlike Pending
 * Payment, and the difference is that a row here has to be OPENABLE. BPLO has to
 * press Approve, and the review sheet is addressed by assignment id — so a feed
 * that cannot supply one would render a tab of rows that do not click.
 *
 * No assignment-status filter, for a reason worth stating plainly because it
 * looks like an oversight: BPLO's assignment is `completed` here, closed by
 * `approveMainForm()` at the other end of the process, and nothing reopens it.
 * Its final approval is work with no open work item behind it. Filtering on an
 * open assignment would empty this tab permanently.
 *
 * ── Why `awaiting_other_permits` is here too ────────────────────────────────
 *
 * A paid filing lands on `awaiting_other_permits` and stays there while the
 * clearance offices work and while any Other Requirement is open. BPLO's own
 * part is finished at that point, so the filing appeared in NO tab BPLO could
 * see: it left For Approval when BPLO approved the form, it is not Pending
 * Payment, and it had not yet qualified for Final Approval. BPLO lost sight of
 * every paid filing until the moment it was ready to sign — which is the one
 * stretch where somebody needs to notice a filing that has stopped moving.
 *
 * So the tab is "filings that have paid and are heading for my signature",
 * which is what the client describes: after payment it goes to Final Approval,
 * and everything else has to be complete before it can be signed. The two are
 * told apart on the row and on the sheet — `for_final_approval` can be
 * approved, `awaiting_other_permits` says what it is still waiting for — and
 * the API refuses the early approval either way.
 */
const FINAL_STATUSES = ['awaiting_other_permits', 'for_final_approval'] as const

const TAB_STATUSES: Record<Tab, readonly ApplicationStatus[]> = {
  approval: APPROVAL_STATUSES,
  payment: PAYMENT_STATUSES,
  inspection: INSPECTION_STATUSES,
  final: FINAL_STATUSES,
}

const TAB_LABEL: Record<Tab, string> = {
  approval: 'For Approval',
  payment: 'Pending Payment',
  inspection: 'For Inspection',
  final: 'Final Approval',
}

/**
 * What a status MEANS inside the tab it is being offered in.
 *
 * The Filter dropdown lists the tab's own statuses, and `applicationStatusMeta`
 * labels each one the same way everywhere because it describes the FILING and
 * knows nothing about which office is reading. Two of them need saying
 * differently from this seat.
 *
 * Both are `awaiting_other_permits`. To an applicant that status means "the
 * other permits are being worked"; an office reading its own queue is one of the
 * workers, and the row is there because the work is theirs. The bare label would
 * read as an explanation of why the row is NOT actionable, which is the opposite
 * of true.
 *
 * Deliberately only where the two readings diverge. Everywhere else the shared
 * filing label is right, and renaming a status that was already unambiguous
 * would move a control out from under the tests that press it by name.
 */
const STATUS_IN_TAB: Record<Tab, Partial<Record<ApplicationStatus, string>>> = {
  approval: {
    awaiting_other_permits: 'Your permit · waiting on your review',
  },
  payment: {},
  inspection: {
    awaiting_other_permits: 'Your permit · site visit outstanding',
  },
  final: {},
}

/**
 * This office's own assignment states that still want a decision (item 111).
 *
 * "After approving an application it still shows approval." The application's
 * status is not this office's status: a filing is routed to every office that
 * issues one of its permits, and it stays in flight until all of them have
 * signed off. So an office that approved its part this morning was still shown
 * the row under For Approval, because the filing really was still in progress —
 * by somebody else.
 *
 * Filtering on the assignment instead answers the question the tab is actually
 * asking, which is "what is waiting on ME". `completed` is the one state left
 * out. `returned` stays in: the office sent it back and the filing comes to it
 * again when the applicant answers, so it is still that office's open work.
 *
 * Used by For Approval alone now. The other three tabs each say in their own
 * note why an assignment filter would be wrong for them, and the short version
 * is the same in all three: an assignment closes before the office's work does.
 */
const OPEN_ASSIGNMENT_STATUSES = 'pending,in_progress,returned'

/**
 * Which statuses still owe money, for the chip on the right of every row.
 *
 * Written out rather than aliased to PAYMENT_STATUSES, which is what it used to
 * be. Those two lists answered the same question while payment was the first
 * thing that happened; they stopped agreeing when BPLO's approval moved in front
 * of it. A `for_approval` filing is unpaid and would have shown a green "Paid"
 * chip, on the very tab that exists to hold it.
 */
const UNPAID_STATUSES: readonly string[] = [
  'draft',
  'for_approval',
  'returned',
  'pending_payment',
]

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
 *  - Filter (status) → server, on every tab. Both endpoints take a status list,
 *    so narrowing a tab to one status is a query change and the totals stay
 *    exact.
 *  - Filter (filing type) → server on Pending Payment, browser on the other
 *    three, and the split is the endpoints' and not a preference. `/applications`
 *    takes `type` (ApplicationController::index validates it and applies
 *    `where('application_type', …)`), so the Pending Payment tab sends it and
 *    `meta.total` keeps counting the rows beside it. `/assignments` takes
 *    nothing of the kind — `AssignmentController::index` validates exactly
 *    `status`, `application_status`, `clearance_status`, `q`, `oic`, `per_page`
 *    and `page`, and an unlisted parameter is ignored rather than refused, which
 *    would be a narrowing that silently did not happen. So those three tabs
 *    match `item.type` over the rows in hand, exactly as the sort below does,
 *    and the status line says "of the N loaded" whenever they are the ones
 *    doing it. The fix for that half is a `type` filter on the assignment feed,
 *    at which point this bullet collapses into the one above it.
 *    NOTE the key is `type`, not `application_type`: on `/applications` the
 *    latter is the request BODY's field on create, and the query parameter that
 *    narrows the list is `type`.
 *  - Search → server, on every tab. This note used to read "server on Pending
 *    Payment, browser on the other two", because `/applications` took `q` and
 *    `/assignments` took nothing of the kind. It takes one now, over the same
 *    two columns (tracking ID and business name, two LIKEs in SQL) and applied
 *    inside the department scoping, so all three tabs search the whole queue
 *    rather than the rows that happen to be loaded. See `searchesOnServer`.
 *  - Sort → browser, EXCEPT for the one order the tab's endpoint already
 *    returns. Neither takes an ordering parameter, but each has a fixed order
 *    and they no longer agree: `/assignments` orders `assigned_at is null`,
 *    then `assigned_at` ASC, then `id` — oldest routing first, un-routed last
 *    (issue #91) — and `/applications` orders `created_at` DESC then `id` DESC.
 *    So the free sort is "Waiting longest" on the three assignment tabs and
 *    "Newest first" on Pending Payment; see `serverSort`, which is what decides
 *    whether a sort costs a deep page or nothing at all.
 *
 * Sort and the filing type are what is left in the browser, and wherever the
 * browser is doing the work the page asks for the API's ceiling instead of a
 * screenful, so it usually covers the whole queue in one request: an office's
 * "For Approval" tab is tens of rows, not thousands. `maxPerPage` is 200
 * (PaginatesLists) and asking for more is clamped, not obeyed. Where the SERVER
 * is doing the work no deep page is needed, and the status line says "Showing 3
 * of 3" rather than "3 of the 200 loaded" because for once that is the whole
 * truth.
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

/**
 * "Waiting longest" moved to the head of the list because it is now the order
 * the screen opens in (DEFAULT_SORT), and a menu whose first entry is not the
 * one in force reads as though the screen is sorted some fourth way.
 *
 * Its label is not marked "(default)". `SortFilterMenuPanel` already renders the
 * option in force as `aria-selected` and tinted, so the word would be a second
 * claim about the same thing — and the label is quoted verbatim in the status
 * line below ("Showing 12 of 12, waiting longest."), where a parenthesis about
 * defaults is noise in the middle of a sentence.
 *
 * "Waiting longest" and not "Oldest first", which is the client's phrasing: an
 * officer sorting a queue is asking whose case has been outstanding longest, and
 * the oldest FILING and the longest-waiting one are the same row here only
 * because the clock on the row is the routing date.
 */
const SORTS: SortFilterOption[] = [
  { value: 'waiting', label: 'Waiting longest' },
  { value: 'newest', label: 'Newest first' },
  { value: 'business', label: 'Business name (A–Z)' },
]

/**
 * The oldest filing is the top row (issue #91). DO NOT flip this back.
 *
 * Client's words: "The OLDEST application should appear at the top, not the
 * latest." It reads as a preference and it is not one — it is RA 11032, the Ease
 * of Doing Business Act, which puts a statutory processing clock on every filing
 * (3 / 7 / 20 working days by tier; `App\Support\Ra11032` holds the counts and
 * `deadline_at` is computed from them). The filing nearest to breaching that
 * clock is always the one that has waited longest, so newest-first ordered the
 * queue by the one fact the deadline does not care about — and in the direction
 * that hides the breach. An office taking in more than it clears pushes its
 * oldest case further down every time somebody files, which makes the row most
 * at risk the row least likely to be seen. Sorting a statutory queue by arrival
 * is a service-standard failure, not a display choice.
 *
 * It is also now the order `/assignments` returns (oldest `assigned_at` first,
 * un-routed last, `id` breaking ties), so on three of the four tabs this default
 * costs nothing: the server already sorted it, `serverSort` says so, and the
 * page keeps its 25-row first request. Defaulting to `newest` against that feed
 * was actively wrong rather than merely unhelpful — it fetched the 25 OLDEST
 * rows and re-sorted those newest-first, so the top row was "the newest of the
 * oldest 25", which is not a queue order anybody asked for.
 */
const DEFAULT_SORT: SortKey = 'waiting'

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
  unpaid: boolean
  /**
   * New / renewal / amendment, for the filing-type pills (item 97).
   *
   * Carried on the row rather than re-fetched because three of the four tabs
   * match it here: `/assignments` has no `type` parameter, and
   * `AssignmentResource` has sent `application.application_type` all along —
   * `AssignmentController::index` names the column in its eager `select`, so it
   * is on the wire on every tab and costs this page nothing.
   */
  type: ApplicationType
  /**
   * This office's own permit on the filing, when the row came from an
   * assignment. Null on the Pending Payment tab, whose rows are applications and
   * belong to no office yet.
   *
   * The row prints this rather than the filing's status, because they answer
   * different questions and only one of them is the officer's. Five offices
   * share `awaiting_other_permits`, and printing it would tell all five the same
   * thing while each is at a different point.
   */
  clearance: Assignment['clearance']
  /**
   * The assignment id, when this row came from the assignment feed. Claiming
   * addresses the assignment, not the filing: one filing is several offices'
   * work and each office holds its own row.
   */
  assignmentId: number | null
  /** Who holds this case, or null when nobody has taken it (client §2). */
  officer: { id: number; name: string } | null
  /** May this reader take it, and may they work it? Both come from the server. */
  canClaim: boolean
  canAct: boolean
  /** Does this reader hold it? What "My assigned" means, on one row. */
  mine: boolean
}

/**
 * What one page of either feed looks like once it has been normalised.
 *
 * `meta` carries the only total this screen quotes. It used to carry a second
 * one — `meta.application_status_counts`, lifted out into a `counts` field here
 * — and see the note on `total` in QueuePage for why nothing reads it any more.
 */
interface QueueFeed {
  items: QueueItem[]
  meta: PageMeta
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
    unpaid: UNPAID_STATUSES.includes(app.status),
    type: app.application_type,
    clearance: item.clearance,
    assignmentId: item.id,
    officer: item.officer,
    /*
     * Read from the payload, never worked out here. The server decides who may
     * take and who may act (AssignmentResource::canClaim/canAct), and an older
     * payload without the flags means "do not offer the button" rather than
     * "offer it and find out" — a button the server then refuses is worse than
     * no button.
     */
    canClaim: item.can_claim === true,
    canAct: item.can_act === true,
    /*
     * Held by THIS reader. `can_act` is true on an unheld case too — acting on
     * one claims it — so it cannot answer "is this mine", and the give-back
     * control must not appear on a case nobody holds.
     */
    mine: item.officer !== null && item.can_act === true,
  }
}

function fromApplication(app: ApplicationListItem): QueueItem {
  return {
    key: `application:${app.id}`,
    href: null,
    // No assignment exists yet on this tab, so there is nothing to hold and
    // nobody to hold it — the filing has not been routed to an office.
    assignmentId: null,
    officer: null,
    canClaim: false,
    canAct: false,
    mine: false,
    trackingId: app.tracking_id,
    ...nameOf(app.business, app.tracking_id),
    /*
     * Filed-at, not routed-at, and the row says so. `submitted_at` is the only
     * clock an unpaid filing has, and it is the one the officer wants: it is how
     * long the applicant has been sitting on an unsettled Tax Order of Payment.
     */
    at: app.submitted_at,
    atMs: app.submitted_at ? new Date(app.submitted_at).getTime() : 0,
    unpaid: UNPAID_STATUSES.includes(app.status),
    /*
     * Set even though this tab narrows by type on the server, so that one row
     * shape means one thing on every tab. A field that is only populated where
     * it happens to be read is the kind that goes missing the first time
     * something else reads it.
     */
    type: app.application_type,
    // No office holds this filing yet, so there is no "your permit" to report.
    clearance: null,
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
  /** This office's own permit's state. For Inspection only; see that tab's note. */
  clearanceStatuses?: string
  /** Server-side search term. '' means no search. */
  query: string
  /**
   * Server-side filing type. '' means every type — and it is ALWAYS '' on the
   * three assignment tabs, whose endpoint has no such parameter; see the note on
   * `typeOnServer`. `ApplicationFilters` spells this `type`, not
   * `application_type`.
   */
  type: string
  /** Who holds the case. undefined = all, which is the default tab. */
  oic?: 'unassigned' | 'mine' | 'others'
  page: number
  perPage: number
}): Promise<QueueFeed> {
  if (args.tab === 'payment') {
    const res = await applications.page({
      status: args.statuses,
      ...(args.query ? { q: args.query } : {}),
      ...(args.type ? { type: args.type } : {}),
      page: args.page,
      per_page: args.perPage,
    })

    return { items: res.data.map(fromApplication), meta: res.meta }
  }

  const res = await assignments.page({
    application_status: args.statuses,
    ...(args.assignmentStatuses ? { status: args.assignmentStatuses } : {}),
    ...(args.clearanceStatuses ? { clearance_status: args.clearanceStatuses } : {}),
    ...(args.query ? { q: args.query } : {}),
    ...(args.oic ? { oic: args.oic } : {}),
    page: args.page,
    per_page: args.perPage,
  })

  return { items: res.data.map(fromAssignment), meta: res.meta }
}

/**
 * Is the browser being asked to do work the current page of rows cannot answer?
 *
 * `serverSort` is a parameter rather than the literal 'newest' it used to be:
 * the free ordering is whatever the tab's own endpoint already returns, and
 * since issue #91 the two endpoints behind this screen return different ones.
 * Hard-coding 'newest' here would have said a Pending Payment sort was free on
 * the assignment tabs, where it is the one that costs a 200-row page.
 */
function isDeep(query: string, sort: SortKey, serverSort: SortKey): boolean {
  return query.trim() !== '' || sort !== serverSort
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

function QueueRow({
  item,
  onClaim,
  onRelease,
  claiming,
}: {
  item: QueueItem
  onClaim?: (item: QueueItem) => void
  onRelease?: (item: QueueItem) => void
  claiming?: boolean
}) {
  const body = (
    <>
      <div className="min-w-0 flex-1 px-6 py-4">
        <p className="truncate text-[17px] font-bold text-ink">{item.name}</p>
        {/*
          * The tracking ID, on the row, in its own right.
          *
          * It was searchable on both feeds and never once printed, so an officer
          * who searched "BIZ-2026-00969" got back a row that did not contain the
          * thing they had searched by — and two filings from one business were
          * the same three lines twice, with nothing on screen to tell them apart.
          * It is also what an applicant quotes over the phone, which makes it the
          * one handle both sides of that call share.
          *
          * One slot holding one of two captions, deliberately, rather than a line
          * added beside the existing one. When the business is gone `nameOf` has
          * already promoted the tracking ID into the heading above; printing it
          * again here would say the same thing twice AND leave the heading looking
          * like an ID with no explanation. The caption that earns the space in
          * that case is the one that explains the heading. Either way the row
          * carries the tracking ID exactly once.
          */}
        <p className="mt-0.5 text-xs font-semibold uppercase tracking-wide text-ink-muted">
          {item.nameIsFallback ? 'Business removed from the register' : item.trackingId}
        </p>
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
            Waiting on the applicant’s payment. It reaches an office for review once BPLO has
            approved the form and the fees are settled.
          </p>
        )}
        {/*
          * Which permit this row is, and where it has got to.
          *
          * Without it the four other offices' rows are indistinguishable: they
          * share the filing, the business and the tracking ID, and the only
          * thing that differs is the permit — which was the one fact the row did
          * not carry. An officer with sanitary and fire work on the same
          * business saw the same three lines twice.
          *
          * The office's own name is deliberately absent. Every row in this queue
          * belongs to the reader's office, so printing it would repeat the same
          * word down the page; the PERMIT is what varies.
          */}
        {item.clearance && (
          <p className="mt-1 text-sm text-ink-secondary">
            {item.clearance.name}
            {item.clearance.status_label && (
              <span className="text-ink-muted"> · {item.clearance.status_label}</span>
            )}
            {/*
              * An uploaded copy has no form behind it — only an image — so the
              * officer needs to know before they open it that there is nothing
              * to read but the attachment.
              */}
            {item.clearance.mode === 'upload' && (
              <span className="text-ink-muted"> · copy on file</span>
            )}
          </p>
        )}
      </div>
      {/*
        * Whether the fees are settled — and NOT the filing's stage, which is
        * what it looked like it was saying.
        *
        * It read "Pending Payment", which was unambiguous while unpaid and
        * at-the-payment-stage were the same fact. They came apart on 6 September
        * 2026: BPLO now reads the main form BEFORE the bill is raised, so a
        * `for_approval` filing is unpaid and is two steps away from Pending
        * Payment. The chip then sat on the For Approval tab, in the same words
        * as the tab beside it, reporting a stage the filing had not reached —
        * and it was read exactly that way, immediately, by the first person to
        * open the screen.
        *
        * "Unpaid" says the one thing this chip knows. The stage is the tab's job
        * and the permit line's, and no two of the three now use the same words
        * for different things.
        *
        * ── Why "Paid" is gone and "Unpaid" stayed (item 92) ──────────────────
        *
        * The client asked for the chip removed outright: "every filing there is
        * already paid." That is true of two tabs and false of the other two, and
        * the two it is false of include the one this screen opens on.
        *
        * `UNPAID_STATUSES` and the tab status lists above decide it between
        * them, and they overlap:
        *
        *   For Approval   for_approval + returned are unpaid; awaiting_other_permits is paid → MIXED
        *   Pending Payment  pending_payment                                                  → ALL unpaid
        *   For Inspection   awaiting_other_permits                                           → all paid
        *   Final Approval   for_final_approval                                               → all paid
        *
        * Not theoretical. Counted against this register on 17 September 2026:
        * 13 assignments sit on a filing at `for_approval` or `returned`, and 4
        * filings sit at `pending_payment`. So an officer on the default tab can
        * have a paid row and an unpaid row side by side, and the chip is the only
        * thing on either that tells them apart — BPLO reading a form nobody has
        * been billed for is different work from an office reading a clearance on
        * a filing that has settled.
        *
        * So the noise the client is seeing is real but it is the GREEN chip: on
        * the two tabs where nothing can be unpaid it printed "Paid" on every row,
        * down the whole page, saying the same thing about all of them. Rendering
        * only the exception removes it from those two tabs entirely and leaves
        * the one case that carries information. Absence now means paid, which is
        * the ordinary state and the one that needs no words.
        */}
      {item.unpaid && (
        <StatusChip tone="orange" className="w-28 shrink-0 rounded-none! px-4 py-3 text-sm">
          Unpaid
        </StatusChip>
      )}
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
      {/*
        * Who holds the case, under the row rather than inside it.
        *
        * Outside the Link on purpose: Claim is a button and the row is an
        * anchor, and a button inside an anchor is both invalid markup and a
        * control that navigates when pressed by a keyboard.
        *
        * A row from the Pending Payment tab has no assignment, so it says
        * nothing here — there is no office holding it yet, and "Unassigned"
        * would read as work waiting to be taken.
        */}
      {item.assignmentId !== null && (
        <div className="-mt-px flex flex-wrap items-center justify-between gap-3 rounded-b-xl border-t border-line bg-white px-6 py-2.5">
          <p className="text-sm text-ink-secondary">
            {item.officer ? (
              <>
                <span className="text-ink-muted">Officer in charge: </span>
                <span className="font-semibold text-ink">{item.officer.name}</span>
                {!item.canAct && <span className="text-ink-muted"> · read-only for you</span>}
              </>
            ) : (
              <span className="text-ink-muted">Not yet taken by anyone</span>
            )}
          </p>
          {item.canClaim && onClaim && (
            <button
              type="button"
              onClick={() => onClaim(item)}
              aria-disabled={claiming || undefined}
              className="rounded-full bg-royal px-4 py-1.5 text-xs font-semibold text-white hover:bg-royal-hover aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
            >
              {claiming ? 'Assigning…' : 'Assign to Me'}
            </button>
          )}
          {/*
            * Putting it down again.
            *
            * Only on rows this reader holds — `mine` is the payload's answer,
            * not an id comparison here. Claiming is one click and was
            * irreversible without the super admin, which is what stops officers
            * using it: a mis-click, or a case that turns out to be a
            * colleague's area, needed an admin to unpick.
            *
            * Quiet styling on purpose. It sits beside "Assign to Me" in the
            * same strip, and an outline button is how the row says this is the
            * undo rather than the action.
            */}
          {item.mine && onRelease && (
            <button
              type="button"
              onClick={() => onRelease(item)}
              aria-disabled={claiming || undefined}
              className="rounded-full border border-line px-4 py-1.5 text-xs font-semibold text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
            >
              {claiming ? 'Releasing…' : 'Unassign from me'}
            </button>
          )}
        </div>
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
 * plausible and always wrong. Every tab's total is `meta.total`, counted by the
 * paginator over the same query that produced the rows beside it; see the note
 * on `total` below for why it is not assembled out of anything else.
 */
export function QueuePage() {
  const canReadEveryOffice = useAuth((s) => Boolean(s.user?.permissions.includes(ANY_OFFICE)))
  const [tab, setTab] = useState<Tab>('approval')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<QueueItem[]>([])
  const [search, setSearch] = useState('')
  const [sort, setSort] = useState<SortKey>(DEFAULT_SORT)
  /** '' means the whole tab; otherwise one status inside it. */
  const [statusFilter, setStatusFilter] = useState('')
  /** '' means every kind of filing; otherwise new, renewal or amendment (item 97). */
  const [filingType, setFilingType] = useState<'' | ApplicationType>('')
  /** The search the SERVER has been asked for. Pending Payment only; see below. */
  const [serverQuery, setServerQuery] = useState('')
  /*
   * Who holds the case (client §10). '' is All, which is what the officer sees
   * on arrival — the other three are narrowings of it, not a replacement for it.
   *
   * Server-side, like the tab and the search: the queue is paged, and a browser
   * split of one page would tell an officer they hold nothing because their
   * cases are further down the feed.
   */
  const [holder, setHolder] = useState<'' | 'unassigned' | 'mine' | 'others'>('')
  /** The row being claimed, so one press cannot be double-fired. */
  const [claimingId, setClaimingId] = useState<number | null>(null)
  const [claimError, setClaimError] = useState<string | null>(null)
  /*
   * What just happened, said rather than only drawn.
   *
   * Taking a filing or putting it back changes a row the reader is often not
   * looking at — under "Unassigned" the row LEAVES the list, so the only
   * feedback was a list that silently got shorter. A screen-reader user got
   * nothing at all.
   *
   * A live region rather than a dialog: this is the most frequent act on the
   * screen, and a modal would put a dismissal between an officer and every
   * case they take. The message names the business, so it is an account of
   * what happened rather than a tick.
   */
  const [claimMessage, setClaimMessage] = useState<string | null>(null)

  /*
   * See ANY_OFFICE: an office reviewer would be handed a permanently empty tab,
   * and an empty queue is a claim this screen cannot make to them truthfully.
   *
   * Two tabs are hidden now rather than one. Final Approval joins Pending
   * Payment because it is BPLO's act, not an office's: a filing reaches it only
   * once every clearance is approved, so a sanitary officer's own work on it is
   * finished and the tab could only ever tell them what somebody else owes.
   */
  const BPLO_ONLY_TABS: Tab[] = ['payment', 'final']
  const tabs = canReadEveryOffice ? TABS : TABS.filter((t) => !BPLO_ONLY_TABS.includes(t.value))
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
   * Which of the three server-side filters each tab needs, and — as important —
   * which it must NOT send.
   *
   * For Approval is the only one that asks about the assignment. It is asking
   * "what review is still open for me", and an open assignment is exactly that,
   * for BPLO reading a main form and for an office reading a clearance alike.
   *
   * For Inspection asks the clearance instead. It used to send
   * `status=completed`, on a partition that assumed an assignment stays open
   * until the office's work is done. It does not: `approveClearance()` closes it
   * when the paperwork is accepted, which is BEFORE the site visit. So
   * `completed` now covers both the visit outstanding and the permit issued, and
   * the tab needs the clearance status to tell them apart.
   *
   * Final Approval sends neither. BPLO's assignment there is `completed` —
   * closed by its own first approval and never reopened — and its Business
   * Permit pivot has read `for_approval` since Pending Payment. Both filters
   * would be wrong; the application's status is the whole of the question.
   *
   * Pending Payment gets `undefined` for both and must keep getting them: its
   * rows come from `/applications` and are not assignments at all.
   *
   * A useful side effect of filtering server-side: `meta.total` — and with it
   * the "Showing N of M" line — counts the tab the officer is actually looking
   * at, rather than every assignment the office has ever held.
   */
  const assignmentStatuses = tab === 'approval' ? OPEN_ASSIGNMENT_STATUSES : undefined
  const clearanceStatuses = tab === 'inspection' ? INSPECTION_CLEARANCE_STATUSES : undefined
  /*
   * Which end of the wire narrows by filing type, decided by which endpoint the
   * tab reads and nothing else.
   *
   * `/applications` takes `type` and `/assignments` does not, so Pending Payment
   * gets an exact `meta.total` and the other three get a browser-side match over
   * the rows in hand. Written as one flag read in four places rather than
   * `tab === 'payment'` repeated, because the day the assignment feed grows a
   * `type` filter this becomes `false` and every consequence follows from it.
   */
  const typeOnServer = tab === 'payment'
  const serverType = typeOnServer ? filingType : ''
  /*
   * The order the endpoint behind THIS tab already returns rows in.
   *
   * Neither takes a `sort` parameter, so this is not a request — it is what the
   * page knows about each feed, and the only reason to know it is that asking
   * for that same order costs nothing: no re-sort matters and no deep page is
   * needed, because page 1 really is the first 25 rows of the order on screen.
   *
   * The two disagree, which is why this cannot be a constant. `/assignments`
   * orders oldest routing first (issue #91, see DEFAULT_SORT) and
   * `/applications` orders `created_at` DESC — so the free sort is "Waiting
   * longest" on the three assignment tabs and "Newest first" on Pending Payment.
   */
  const serverSort: SortKey = tab === 'payment' ? 'newest' : 'waiting'
  /*
   * A deep page buys nothing where the server is doing the searching or the
   * narrowing; it is the browser-side work — the sorts, and the filing type on
   * the three assignment tabs — that needs more rows than fit on a screen,
   * because it can only ever answer over what has been fetched.
   */
  const browserType = typeOnServer ? '' : filingType
  const deep = (searchesOnServer ? sort !== serverSort : isDeep(search, sort, serverSort)) || browserType !== ''
  const perPage = deep ? DEEP_PAGE_SIZE : PAGE_SIZE

  const { data, loading, error, reload } = useAsync(
    () =>
      loadPage({
        tab,
        statuses,
        assignmentStatuses,
        clearanceStatuses,
        query: serverQuery,
        type: serverType,
        // Pending Payment reads `/applications`, which has no assignment to
        // hold; sending the narrowing there would be a parameter that endpoint
        // does not know and a filter the tab cannot honour.
        oic: tab === 'payment' || holder === '' ? undefined : holder,
        page,
        perPage,
      }),
    [
      tab,
      statuses,
      assignmentStatuses,
      clearanceStatuses,
      serverQuery,
      // `serverType` and not `filingType`: on the three browser-filtered tabs it
      // is '' whatever the pills say, so changing the type there does not refire
      // a request that would come back with exactly the same rows.
      serverType,
      holder,
      page,
      perPage,
    ],
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

  function selectHolder(next: '' | 'unassigned' | 'mine' | 'others') {
    if (next === holder) return
    setHolder(next)
    setClaimError(null)
    setClaimMessage(null)
    restart()
  }

  /*
   * Take the case (client §2).
   *
   * The list is re-read rather than patched in place. A claim changes more than
   * the one row's officer — under "Unassigned" the row must LEAVE the list, and
   * under "Assigned to others" a colleague's claim must appear in it — and a
   * local edit would leave the row sitting under a heading that no longer
   * describes it.
   *
   * A 409 is the ordinary outcome of two officers pressing at once, not a fault:
   * the server's message names who got there first, and it is shown as written
   * rather than replaced by "Something went wrong".
   */
  async function claim(item: QueueItem) {
    if (item.assignmentId === null || claimingId !== null) return
    setClaimingId(item.assignmentId)
    setClaimError(null)
    setClaimMessage(null)
    try {
      await assignments.claim(item.assignmentId)
      setClaimMessage(`${item.name} is yours — you are now the officer in charge.`)
      restart()
      reload()
    } catch (err) {
      setClaimError(toApiError(err).message)
    } finally {
      setClaimingId(null)
    }
  }

  /*
   * Give it back to the office (client: "unassigned to me").
   *
   * Re-read rather than patched, for the reason claim() gives: under "My
   * assigned" a released row must LEAVE the list, and under "Unassigned" it
   * must appear — a local edit leaves it under a heading that no longer
   * describes it.
   */
  async function release(item: QueueItem) {
    if (item.assignmentId === null || claimingId !== null) return
    setClaimingId(item.assignmentId)
    setClaimError(null)
    setClaimMessage(null)
    try {
      await assignments.release(item.assignmentId)
      setClaimMessage(`${item.name} is back with the office. Any officer here can take it.`)
      restart()
      reload()
    } catch (err) {
      setClaimError(toApiError(err).message)
    } finally {
      setClaimingId(null)
    }
  }

  function selectStatus(next: string) {
    if (next === statusFilter) return
    setStatusFilter(next)
    restart()
  }

  /**
   * The filing type, restarting the list only when the REQUEST changes (item 97).
   *
   * `restart()` empties `rows` and sets the page to 1, and it is `useAsync`'s
   * dependencies that then refill them. So restarting when nothing in those
   * dependencies moved does not reload the list — it blanks it, permanently,
   * until some other control happens to fire a request. That is the hazard
   * `changeSort` is already written around, and it bites harder here: on the
   * three browser-filtered tabs `serverType` stays '' whatever is pressed, so
   * three of the four tabs are exactly the case where nothing moved.
   *
   * Two things can move. On Pending Payment the type goes into the query, so
   * every press is a new request. Everywhere else only the DEPTH moves, and only
   * as the filter switches on or off: renewal → amendment is the same 200 rows
   * re-matched in the browser, and throwing them away to ask for them again
   * would be a blank list and a round trip for a filter that is already local.
   */
  function selectFilingType(next: '' | ApplicationType) {
    if (next === filingType) return
    const wasDeep = !typeOnServer && filingType !== ''
    const nowDeep = !typeOnServer && next !== ''
    setFilingType(next)
    if (typeOnServer || wasDeep !== nowDeep) restart()
  }

  /**
   * Search and sort only restart the list when they change how deep it is
   * fetched. On the server-searched tab the search's restart is the debounce's,
   * above — restarting here as well would throw away the rows on screen a
   * quarter-second before the replacements were even asked for.
   */
  function changeSearch(next: string) {
    setSearch(next)
    if (!searchesOnServer && isDeep(next, sort, serverSort) !== isDeep(search, sort, serverSort)) restart()
  }

  function changeSort(next: SortKey) {
    setSort(next)
    // Against `serverSort`, not a fixed 'newest': what makes a sort free is the
    // tab's own feed already being in it, and the two feeds are in different
    // orders since issue #91.
    const was = searchesOnServer ? sort !== serverSort : isDeep(search, sort, serverSort)
    const now = searchesOnServer ? next !== serverSort : isDeep(search, next, serverSort)
    if (was !== now) restart()
  }

  /*
   * The denominator, taken from the same query as the numerator.
   *
   * `meta.total` is what the paginator counted over the very query that returned
   * these rows: the tab's status list, the assignment-status half of the
   * partition and the search term, all applied. So "Showing 1 of 1" is
   * arithmetic on one question rather than on two.
   *
   * The assignment tabs used to sum `meta.application_status_counts` across the
   * tab's statuses instead, and that breakdown is a SECOND query — one that does
   * not carry `q`. A search matching a single filing therefore announced
   * "Showing 1 of 12 matching “BIZ-2026-00969”" while `meta.total` sat correctly
   * at 1 in the same payload. Twelve was a truthful answer to a question nobody
   * had asked.
   *
   * Fixed by dropping the summation rather than by teaching the counts about
   * `q`, because this is the third denominator this screen has quoted from
   * somewhere other than the rows — "Showing 0 of the 13 loaded" was the second
   * — and re-syncing two queries only holds until the next filter is added to
   * one of them. Nothing summed is nothing left to drift.
   *
   * No precision is lost by it: `/assignments` filters on `application_status`
   * server-side, so its `total` already IS this tab's total. OfficerQueueFilterTest
   * asserts exactly that — every per-status count equals the total of asking for
   * that status on its own — which is what makes the breakdown a reconstruction
   * of a number the payload was already carrying.
   */
  const total = data?.meta.total ?? 0
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
    // Empty on Pending Payment, where `/applications?type=` has already done it
    // — matching the same rule twice would only re-apply it over fewer rows.
    .filter((item) => !browserType || item.type === browserType)
    // Copied before sorting: `rows` is state, and Array.prototype.sort is in
    // place — sorting it directly would rewrite the accumulated pages.
    .slice()
    .sort((a, b) => {
      /*
       * A row with no timestamp goes LAST here, not first.
       *
       * `atMs` is 0 when the row has no clock, which under an ascending compare
       * sorts it to the very top — and the top of this queue now means "closest
       * to breaching RA 11032". `AssignmentController` states the same rule in
       * SQL (`orderByRaw('assigned_at is null')`, whose note explains that
       * SQLite sorts NULL first and Postgres last, so neither default could be
       * relied on); a browser comparator that disagreed with it would reorder
       * the page it was handed and put an un-routed row above every real one.
       * There are none today — 0 of 6,209 — which is exactly why this would go
       * unnoticed until the one that fixes itself to the top of every office's
       * queue appears.
       */
      if (sort === 'waiting') {
        if (a.atMs === 0 || b.atMs === 0) return a.atMs === b.atMs ? 0 : a.atMs === 0 ? 1 : -1
        return a.atMs - b.atMs
      }
      // Rows whose business is gone are keyed by tracking ID on screen, so that
      // is what they sort by too — `name` already holds the fallback.
      if (sort === 'business') return a.name.localeCompare(b.name)
      return b.atMs - a.atMs
    })

  const sortLabel = SORTS.find((s) => s.value === sort)?.label.toLowerCase() ?? 'waiting longest'
  const partial = rows.length < total
  const narrowed = Boolean(needle || statusFilter || filingType)

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
   *
   * ── And so does the filing type, on the three tabs that match it here ─────
   *
   * That branch comes FIRST because it is the only one of the three that can be
   * wrong about its own numerator. Everywhere else `rows.length` is what is on
   * screen; where the browser is filtering, `rows` holds the fetched page and
   * `visible` holds what survived it, and quoting the first would announce 200
   * rows above a list of six. `meta.total` is no better as a denominator there —
   * the server counted a query that never heard of the filter — so the count is
   * stated against the rows LOADED, with the queue total beside it, which is the
   * same hedge the browser-search branch below already carries and for the same
   * reason. Pending Payment does not take this branch: `browserType` is '' there
   * because the server did the narrowing, and "Showing 2 of 2" is exactly true.
   */


  /**
   * What to say when the queue comes back with nothing in it.
   *
   * ── Assembled, because two branches each added a dimension ───────────
   *
   * `dev` named the FILING TYPE here ("No renewals in this queue") and
   * `mike` named the HOLDER ("You are not holding any filings in this office
   * yet"). Neither knew about the other, and both wrote their own nest of
   * ternaries inside the summary — carrying both that way would have been six
   * levels deep, so the clauses are built rather than enumerated.
   *
   * Most specific first, and holder beats type deliberately: an officer who
   * filtered to "mine" is asking about their own desk, and "you are not
   * holding any renewals" answers that better than "no renewals here". A
   * search term beats both — it is the narrowest thing they did.
   */
  const emptyLine = ((): string => {
    const what = browserType ? TYPE_PLURAL[browserType] : 'filings'

    if (needle) return `Nothing in this queue matches “${search.trim()}”.`
    if (holder === 'mine') return `You are not holding any ${what} in this office yet.`
    if (holder === 'unassigned') {
      return `Every one of this office’s ${what} is already with an officer.`
    }
    if (holder === 'others') return `No ${what} here are with another officer.`
    if (browserType) return `No ${what} in this queue.`

    return 'Nothing in this queue right now.'
  })()

  const summary = firstLoad
    ? 'Loading the queue…'
    : error
      ? ''
      : browserType
        ? visible.length === 0
          ? emptyLine
          : `Showing ${visible.length.toLocaleString()} ${TYPE_PLURAL[browserType]} of the ` +
            `${rows.length.toLocaleString()} loaded` +
            `${partial ? ` (${total.toLocaleString()} in this queue)` : ''}, ${sortLabel}.` +
            `${partial ? ' Load more to reach the rest.' : ''}`
        : searchesOnServer
          ? rows.length === 0
            ? emptyLine
            : `Showing ${rows.length.toLocaleString()} of ${total.toLocaleString()}` +
              `${needle ? ` matching “${search.trim()}”` : ''}, ${sortLabel}.` +
              // Only a sort the SERVER did not already do leaves the rest
              // unsorted; asking for the order the feed arrives in sorts nothing.
              `${sort !== serverSort && partial ? ' Load more to sort the rest.' : ''}`
          : rows.length === 0
            ? emptyLine
            : needle || sort !== serverSort
              ? `Showing ${visible.length.toLocaleString()} of the ${rows.length.toLocaleString()} loaded` +
                `${partial ? ` (${total.toLocaleString()} in this queue)` : ''}, ${sortLabel}.` +
                `${partial ? ' Load more to reach the rest.' : ''}`
              : `Showing ${rows.length.toLocaleString()} of ${total.toLocaleString()}, ${sortLabel}.`

  function clearSearchAndFilter() {
    setSearch('')
    if (statusFilter) selectStatus('')
    // Through the handler, not `setFilingType`, so the restart-only-when-the-
    // request-changes rule is applied here too rather than restated.
    if (filingType) selectFilingType('')
    if (!searchesOnServer && isDeep('', sort, serverSort) !== isDeep(search, sort, serverSort)) restart()
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

      {/*
        * ── The two rows stack, and the stage row never goes away ────────────
        *
        * The tabs answer WHERE A FILING IS; the sections answer WHOSE DESK IT
        * IS ON. Crossing them is the useful question — "the filings I hold that
        * are waiting on my review" — and both rows stay on screen so the reader
        * can see which two they have chosen.
        *
        * They were briefly exclusive, and that was a fix aimed at the wrong
        * thing. "My assigned" came back empty for a BPLO officer holding four
        * live filings, and the cause was not the stacking: it was that a paid
        * filing at `awaiting_other_permits` appeared in NO tab BPLO could see,
        * so the office's own work was invisible whatever section was chosen.
        * That is fixed where it belongs — FINAL_STATUSES now carries the paid
        * stage — and with the stage row honest again the two narrow together
        * without contradicting each other.
        *
        * Pending Payment carries no sections. Those rows are filings no office
        * has been routed yet: no assignment, so nobody to hold one, and every
        * section but All would be empty by construction.
        */}
      <div className="mb-5">
        <FilterPills options={tabs} value={tab} onChange={selectTab} />
      </div>

      {tab !== 'payment' && (
        <div className="mb-5">
          <FilterPills options={HOLDER_PILLS} value={holder} onChange={selectHolder} />
          {holder !== '' && (
            <p className="mt-2 text-xs text-ink-muted">
              {TAB_LABEL[tab]} — {HOLDER_HINT[holder]}.
            </p>
          )}
        </div>
      )}

      {/*
        * What kind of filing (item 97) — offered on every tab, including Pending
        * Payment, where it is the one narrowing that endpoint can honour.
        *
        * Named as a group because it is the third row of pills on this screen
        * and they are drawn identically: sighted readers tell them apart by the
        * words on the pills, and a reader who meets them one button at a time
        * has nothing to tell them apart by at all. The label is what says which
        * question this row is answering.
        */}
      <div className="mb-5" role="group" aria-label="Filter by filing type">
        <FilterPills options={TYPE_PILLS} value={filingType} onChange={selectFilingType} />
      </div>

      {claimMessage && (
        <p
          role="status"
          className="mb-4 rounded-lg bg-s-green-tint px-3.5 py-2.5 text-sm font-medium text-s-green"
        >
          {claimMessage}
        </p>
      )}

      {claimError && (
        <p role="alert" className="mb-4 rounded-lg bg-s-red-tint px-3.5 py-2.5 text-sm font-medium text-s-red">
          {claimError}
        </p>
      )}

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
              ? // Both narrowings, not just the status one. A filing type is as
                // able to empty this list as a status is — "amendments awaiting
                // inspection" is routinely none — and naming only the status
                // would send an officer to re-check a control that was not the
                // one holding the rows back.
                'No application in this queue matches every filter set above. Try widening one.'
              : tab === 'payment'
                ? 'No filing is waiting on payment right now.'
                : tab === 'approval'
                  ? /*
                     * Points at the sections, because this is where the screen
                     * misleads. A tab is a STAGE, and an office can own live
                     * filings while owing nothing at this one — BPLO's
                     * assignment is marked done the moment it approves the main
                     * form, months before the filing is decided. An officer
                     * holding four live filings was landing on "Your queue is
                     * clear" and reasonably concluding the feature was broken.
                     */
                    'Nothing is waiting on your department’s review at this stage. Unassigned and My assigned above cover every stage.'
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
              <QueueRow
                key={item.key}
                item={item}
                onClaim={claim}
                onRelease={release}
                claiming={claimingId === item.assignmentId}
              />
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
