import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { InboxIcon } from '../../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import {
  FilterPills,
  PageTitle,
  SortFilter,
  type SortFilterOption,
} from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { applications, assignments } from '../../lib/resources'
import { formatDateTime, formatRelative } from '../../lib/format'
import { TONE_CLASSES, applicationStatusMeta, clearanceStatusMeta } from '../../lib/status'
import { useAsync } from '../../lib/useAsync'
import { useAuth } from '../../stores/auth'
import type {
  ApplicationListItem,
  ApplicationStatus,
  Assignment,
  PageMeta,
  ServerClearanceStatus,
} from '../../lib/types'

/*
 * Application Verification (PDF p61/p80) — the officer queue restyled to the
 * prototype: pill filters, white shadow rows with the solid payment chip block.
 */

type Tab = 'approval' | 'payment' | 'gathering' | 'inspection' | 'final'

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

/**
 * New, renewal or amendment — the third question this screen can be asked.
 *
 * ── Why it was missing, and what adding it does not fix ───────────────────
 *
 * The client, 17 September 2026: *"where is the part where they can see those
 * for Renewal? Currently, it only displays those for New Applications."*
 *
 * There was no such control: `/applications` has taken a `type` filter since
 * it was written, but the four assignment-backed tabs read `/assignments`,
 * which had none — so the only way to find a renewal was to read every row.
 *
 * Worth being exact about what this control can show, because it will look
 * broken otherwise. The register holds two renewals and the queue holds
 * outstanding work: one is `approved` (finished, and the tabs deliberately
 * exclude terminal statuses — see INSPECTION_STATUSES) and the other is a
 * `draft` nobody has filed. So "Renewal" narrows to nothing today, and will
 * hold rows the moment a renewal is actually submitted. That is the filter
 * working, not failing.
 *
 * ── Amendment is BPLO's, on the client's instruction ──────────────────────
 *
 * *"add an Amendment filter too for the BPLO side ONLY."* It is also the honest
 * split: an amendment changes what is on the register for a permit already
 * issued, which is BPLO's business, and offering the pill to a sanitary officer
 * would be a narrowing that can only ever empty their queue — the same reason
 * Pending Payment is hidden from them. (No amendment has been filed yet either;
 * the register has none of the three.)
 */
const TYPE_PILLS: { value: '' | 'new' | 'renewal' | 'amendment'; label: string }[] = [
  { value: '', label: 'All types' },
  { value: 'new', label: 'New' },
  { value: 'renewal', label: 'Renewal' },
  { value: 'amendment', label: 'Amendment' },
]

/** Amendment is BPLO's and the super admin's; see TYPE_PILLS. */
const BPLO_ONLY_TYPES: string[] = ['amendment']

/*
 * ── Five entries, and no officer is shown all five ────────────────────────
 *
 * Ordered by the flow, so whichever four an officer gets read left to right in
 * the order the work happens. `gathering` and `inspection` occupy the same
 * position deliberately: they are the same stage of the filing seen from two
 * seats, and no seat sees both.
 *
 * The client, at the BPLO account, 17 September 2026: *"There should be no For
 * Inspection anymore since BPLO does not have that. There should be Awaiting
 * Other Permits too."* Both halves were already true in the code and neither
 * reached the screen — INSPECTION_STATUSES' own note says "BPLO never appears,
 * and needs no special case", so BPLO was being offered a tab that could not
 * ever hold a row, while the one stage BPLO waits through had no tab at all.
 */
const TABS: { value: Tab; label: string; officeLabel?: string }[] = [
  /*
   * ── "Initial" is BPLO's word, and only BPLO's ─────────────────────────────
   *
   * BPLO approves a filing TWICE — once on the main form before the bill, once
   * on the whole application at the end — so "For Initial Approval" is doing
   * real work in that seat: it says which of the two.
   *
   * A clearance office approves its permit ONCE, by itself, and there is no
   * second pass. The rule was already written down for the status, in
   * status.ts: *"CLEARANCE_STATUS below keeps plain 'For Approval' — a single
   * clearance is approved once, by its own office, and calling that 'initial'
   * would promise a second pass that never comes."* The tab was breaking that
   * rule, and the client read it off the screen from the sanitary account:
   * *"This is still sanitary's account, so why there is Initial Approval? It
   * should be For Approval only."* (17 September 2026.)
   *
   * `officeLabel` rather than a second TABS array, so the two names cannot get
   * out of step over which tab they belong to. Only this one differs; the rest
   * are either the same word in both seats or offered in one seat alone.
   */
  { value: 'approval', label: 'For Initial Approval', officeLabel: 'For Approval' },
  { value: 'payment', label: 'Pending Payment' },
  { value: 'gathering', label: 'Awaiting Other Permits' },
  { value: 'inspection', label: 'For Inspection' },
  { value: 'final', label: 'For Final Approval' },
]


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

/**
 * BPLO between its two approvals: the filing is out with the other five.
 *
 * The same filing status the For Inspection tab reads, and deliberately so —
 * `awaiting_other_permits` IS this stage. What differs is the seat. An
 * inspecting office narrows it to its own permit being out on a visit; BPLO has
 * no permit out on a visit and no visit to conduct, so it gets the stage whole:
 * every filing that has been paid for and is waiting on somebody else.
 *
 * Nothing here is BPLO's work, and the tab is honest about that — it is the
 * answer to "what have I approved that has not come back yet", which is the
 * question the applicant's own Awaiting Other Permits badge raises and which
 * this screen had no way to answer. The actionable stage is For Final Approval,
 * which is where these rows go on their own the moment the last permit lands.
 *
 * No assignment-status filter, for the same reason FINAL_STATUSES gives: BPLO's
 * assignment was closed by `approveMainForm()` at the other end of the process
 * and nothing reopens it, so filtering on an open one would empty the tab
 * permanently. And no clearance filter, which is what keeps it from becoming a
 * second For Inspection: BPLO's Business Permit pivot sits at `for_approval`
 * through the whole of this stage, and narrowing on it would hide the filings
 * whose other permits are the only thing moving.
 */
const GATHERING_STATUSES = ['awaiting_other_permits'] as const

/** The clearance statuses that put a row in the For Inspection tab. */
const INSPECTION_CLEARANCE_STATUSES = 'for_inspection'

/**
 * What an OFFICE's Filter dropdown offers, per tab — its own permit's states.
 *
 * ── Why the filing's statuses were the wrong list to offer them ───────────
 *
 * The dropdown listed `TAB_STATUSES`, which is the FILING's vocabulary, and
 * for an office two of the three entries on the For Approval tab could never
 * match one of its rows: `for_approval` and `returned` are the stage where
 * BPLO is reading the main form, and an office has no assignment then at all.
 * So a sanitary officer was offered two narrowings that empty the queue every
 * time, and one — "Your permit · waiting on your review" — that is the whole
 * tab. Three options, one of them real, none of them in the words the permit
 * is described by anywhere else.
 *
 * The client asked for exactly this check: *"can you verify if the list of
 * status here is correct based on what is shown on the applicant side about the
 * status of other permits."* It was not. The applicant is shown per-permit
 * states — Not Yet Submitted, For Approval, For Inspection, Approved, Rejected,
 * Returned — and those are the states an office's row actually has, so those
 * are what it can usefully filter on.
 *
 * Each list is the states REACHABLE in that tab, not every state a permit can
 * hold. For Approval keeps the office's assignment open, which is
 * `pending,in_progress,returned` — so the permit is being read
 * (`for_approval`), has been sent back (`returned`), or is one of the migrated
 * rows whose permit never started (`not_started`, see APPROVAL_STATUSES). An
 * approved permit closes the assignment and leaves the tab, so offering
 * `approved` here would be the dead option this replaces.
 *
 * For Inspection is one state by construction — the tab IS
 * `clearance_status = for_inspection` — so it offers nothing, and the dropdown
 * falls back to its "All in …" entry alone rather than printing a choice
 * between one thing and itself.
 */
const TAB_CLEARANCE_OPTIONS: Partial<Record<Tab, readonly ServerClearanceStatus[]>> = {
  approval: ['for_approval', 'returned', 'not_started'],
  /*
   * Present and empty, which is not the same as absent.
   *
   * The key is what puts an office on the own-permit vocabulary at all, so
   * leaving it out would drop this tab back to listing FILING statuses — one
   * entry, `awaiting_other_permits`, relabelled "Your permit · site visit
   * outstanding". A choice between one thing and itself, in the vocabulary the
   * client asked to be rid of here. Empty leaves the dropdown with its
   * "All in For Inspection" entry, which is the honest shape for a tab that is
   * already one state.
   */
  inspection: [],
}

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
 */
const FINAL_STATUSES = ['for_final_approval'] as const

const TAB_STATUSES: Record<Tab, readonly ApplicationStatus[]> = {
  approval: APPROVAL_STATUSES,
  payment: PAYMENT_STATUSES,
  gathering: GATHERING_STATUSES,
  inspection: INSPECTION_STATUSES,
  final: FINAL_STATUSES,
}

/**
 * One tab's caption, for the seat reading it. See `officeLabel` on TABS.
 *
 * Reads TABS rather than holding a second copy of the words. The duplicate it
 * replaces was a `Record<Tab, string>` that had to be edited in step with the
 * array above — and the Filter dropdown's "All in …" entry is built from it, so
 * a drift would have shown an officer one caption on the pill and a different
 * one inside the control that narrows it.
 */
function tabLabel(tab: Tab, ownPermit: boolean): string {
  const entry = TABS.find((t) => t.value === tab)

  return (ownPermit ? entry?.officeLabel : undefined) ?? entry?.label ?? tab
}

/**
 * What BPLO's Filter dropdown offers, per tab — the FILING's states.
 *
 * ── This replaced STATUS_IN_TAB, which relabelled instead of excluding ────
 *
 * That map existed because one status, `awaiting_other_permits`, read wrongly
 * from an office chair: to an applicant it means "the other permits are being
 * worked", and an office reading its own queue IS one of the workers, so the
 * bare label explained why a row was not actionable when the reason it was on
 * screen is that it was. The fix was two relabellings — "Your permit · waiting
 * on your review" and "Your permit · site visit outstanding".
 *
 * Both are unreachable now: an office narrows its own permit through
 * TAB_CLEARANCE_OPTIONS and never sees a filing status in this dropdown at all.
 * So the relabelling has nothing left to relabel, and what is left is the
 * question it was working around — which filing statuses can a BPLO row in this
 * tab actually have.
 *
 * `awaiting_other_permits` is NOT among the For Initial Approval options, and
 * that is the whole point of writing this out rather than reusing
 * `TAB_STATUSES`. It is in APPROVAL_STATUSES because that list serves the query
 * for BOTH seats — an office's rows at that stage are how it gets any rows at
 * all — but BPLO's own assignment is `completed` by `approveMainForm()` before
 * a filing reaches it, so BPLO can never hold an open one. Measured on the
 * register: 3 filings at `awaiting_other_permits`, all 3 carrying a BPLO
 * assignment, 0 of them open. Offering it would be a narrowing that empties the
 * queue every time it is chosen.
 */
const TAB_FILING_OPTIONS: Record<Tab, readonly ApplicationStatus[]> = {
  approval: ['for_approval', 'returned'],
  payment: PAYMENT_STATUSES,
  gathering: GATHERING_STATUSES,
  inspection: INSPECTION_STATUSES,
  final: FINAL_STATUSES,
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
 *  - Filter → server, on every tab. Both endpoints take a status list, so
 *    narrowing a tab to one status is a query change and the totals stay exact.
 *  - Search → server, on every tab. This note used to read "server on Pending
 *    Payment, browser on the other two", because `/applications` took `q` and
 *    `/assignments` took nothing of the kind. It takes one now, over the same
 *    two columns (tracking ID and business name, two LIKEs in SQL) and applied
 *    inside the department scoping, so all three tabs search the whole queue
 *    rather than the rows that happen to be loaded. See `searchesOnServer`.
 *  - Sort → browser on every tab, because neither endpoint accepts an ordering
 *    parameter (`/assignments` orders assigned_at DESC and `/applications`
 *    created_at DESC, both unconditionally).
 *
 * Sort is therefore the only one left in the browser, and where it is doing the
 * work the page asks for the API's ceiling instead of a screenful, so it usually
 * covers the whole queue in one request: an office's "For Approval" tab is tens
 * of rows, not thousands. `maxPerPage` is 200 (PaginatesLists) and asking for
 * more is clamped, not obeyed. Where the SERVER is doing the work no deep page
 * is needed, and the status line says "Showing 3 of 3" rather than "3 of the 200
 * loaded" because for once that is the whole truth.
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
  unpaid: boolean
  /**
   * The filing's own status, which `unpaid` was being computed FROM and
   * throwing away.
   *
   * The chip on the right of the row read Unpaid / Paid, and the client asked
   * on 16 September 2026 whether it was worth the space. Nearly not: the For
   * Initial Approval tab holds `for_approval`, `returned` AND
   * `awaiting_other_permits`, so it varies by exactly one of three rows —
   * and payment is implied by the status anyway, since everything past
   * `pending_payment` has been paid.
   *
   * The status says more in the same space. It separates a filing waiting on
   * BPLO from one sent BACK to the applicant, which is the distinction that
   * changes whether the officer is waiting or the applicant is, and those two
   * sat in this tab wearing one identical orange "Unpaid".
   */
  status: ApplicationStatus
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
    status: app.status,
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
    status: app.status,
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
  /** Who holds the case. undefined = all, which is the default tab. */
  oic?: 'unassigned' | 'mine' | 'others'
  /**
   * New / renewal / amendment. undefined = every type.
   *
   * One value in, two spellings out: the two endpoints named this parameter
   * differently and neither name is wrong where it lives. Translated below
   * rather than at the call site, so the screen holds one piece of state and
   * not one per feed.
   */
  appType?: 'new' | 'renewal' | 'amendment'
  page: number
  perPage: number
}): Promise<QueueFeed> {
  if (args.tab === 'payment') {
    const res = await applications.page({
      status: args.statuses,
      ...(args.query ? { q: args.query } : {}),
      // `type` here — ApplicationController has taken that name since it was
      // written, and renaming a public query parameter to match this screen
      // would break every other caller of it.
      ...(args.appType ? { type: args.appType } : {}),
      page: args.page,
      per_page: args.perPage,
    })

    return { items: res.data.map(fromApplication), meta: res.meta }
  }

  const res = await assignments.page({
    application_status: args.statuses,
    ...(args.assignmentStatuses ? { status: args.assignmentStatuses } : {}),
    ...(args.clearanceStatuses ? { clearance_status: args.clearanceStatuses } : {}),
    // `application_type`, not `type`: on this endpoint the bare noun would
    // read as the assignment's own type, and application-vs-assignment is
    // already what goes wrong here most often.
    ...(args.appType ? { application_type: args.appType } : {}),
    ...(args.query ? { q: args.query } : {}),
    ...(args.oic ? { oic: args.oic } : {}),
    page: args.page,
    per_page: args.perPage,
  })

  return { items: res.data.map(fromAssignment), meta: res.meta }
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

function QueueRow({
  item,
  onClaim,
  claiming,
  ownPermit,
}: {
  item: QueueItem
  onClaim?: (item: QueueItem) => void
  claiming?: boolean
  /**
   * Does this reader's badge describe their own permit, or the whole filing?
   *
   * True for the five clearance offices, false for BPLO. Passed in rather than
   * read from the auth store here so that one component renders one row the
   * same way whoever calls it, and so the decision is made once beside the tab
   * gating it belongs with.
   */
  ownPermit: boolean
}) {
  /*
   * The status this row reports, from whichever of the two machines the reader
   * owns. See the note beside the badge itself.
   *
   * `clearanceStatusMeta` takes the SERVER's own label when it has one, so the
   * badge cannot drift from the word the applicant is shown for the same
   * permit; the tone comes from the shared table either way.
   */
  /*
   * `status` is nullable — AssignmentResource sends null for a permit type with
   * no pivot row — so the guard is on the STATUS and not just on the clearance
   * object. Without it the badge would read "Available", which is the label
   * status.ts gives an absent status, on a row an office is holding.
   */
  const own = ownPermit ? item.clearance : null
  const ownPermitBadge = own !== null && own.status !== null
  const badge = ownPermitBadge
    ? {
        tone: clearanceStatusMeta(own.status!).tone,
        label: own.status_label ?? clearanceStatusMeta(own.status!).label,
      }
    : {
        tone: applicationStatusMeta(item.status).tone,
        label: applicationStatusMeta(item.status).label,
      }

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
            {/*
              * The status comes off this line when the badge is carrying it —
              * which is every office row. Printing "Sanitary Permit / Health
              * Certificate · For Approval" three inches from a badge reading
              * "For Approval" spends the row's most readable line on a word
              * already on screen. BPLO's badge shows the FILING's status, so
              * there the permit's own status is still this line's to say.
              */}
            {item.clearance.status_label && !ownPermitBadge && (
              <span className="text-ink-muted"> · {item.clearance.status_label}</span>
            )}
            {/*
              * An uploaded copy has no form behind it — only an image — so the
              * officer needs to know before they open it that there is nothing
              * to read but the attachment.
              */}
            {/*
              ── How long this office has been waiting on the applicant ────────

              Client's decision, 17 September 2026: show the elapsed time on
              BOTH sides and invent no deadline. The applicant's card got it
              first; this is the half that lets an office see which filings have
              gone quiet and ring somebody, which is the reason it was asked
              for.

              No due date, deliberately. RA 11032 fixes the OFFICE's clock, not
              the citizen's, and Malabon has given us no Citizen's Charter
              response window (open question A10) — so a deadline here would be
              one no ordinance backs.

              Rose, and the only coloured thing on this line. The row's badge
              already reads "Returned"; this is the part the badge cannot say,
              and it earns the emphasis because it is the one number an officer
              scans the queue for.
            */}
            {item.clearance.status === 'returned' && item.clearance.returned_at !== null && (
              <span className="font-semibold text-s-rose">
                {' '}
                · waiting on the applicant {formatRelative(item.clearance.returned_at)}
              </span>
            )}
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
        * "Unpaid" said the one thing that chip knew, and by 16 September 2026
        * that was too little. It varied by one row in three on this tab, and
        * the status it was derived from carries the payment fact anyway —
        * anything past `pending_payment` is paid.
        *
        * So the slot shows the STATUS, in the same tone the applicant's Track
        * badges and status guide use, from the same `applicationStatusMeta`.
        * Two things that follow: a filing waiting on BPLO is now visibly not
        * the same as one returned to the applicant, where both used to wear an
        * identical orange "Unpaid"; and the officer and the applicant finally
        * describe a row the same way, in the same colour, which is the whole
        * reason the tones live in one table.
        */}
      {/*
        * ── And WHICH status, which depends on the seat ───────────────────────
        *
        * BPLO gets the filing's. It owns the filing end to end — both its
        * approvals are about the whole application — so "Awaiting Other
        * Permits" is precisely its situation.
        *
        * An office gets its OWN permit's, and the client's reasoning is the
        * whole of it: *"As someone from other permits' office, I think I
        * shouldn't care on the other permits since they are independent of each
        * other, so no need to show Awaiting Other Permits."* (17 September
        * 2026.) The five clearances move independently. A sanitary officer
        * reading "Awaiting Other Permits" beside their own row is being told
        * about four offices' work and nothing about theirs — and the row was
        * already at For Approval FOR THEM, which is the one fact the badge had
        * room for and was not saying.
        *
        * `badge` is computed on the item so the fallback is explicit: an
        * office row with no clearance payload (the Pending Payment tab's rows
        * are applications, not assignments) still has a status to show, and
        * showing the filing's is better than showing none.
        */}
      <span
        className={`flex w-36 shrink-0 items-center justify-center self-stretch border-l px-3 text-center text-sm font-bold leading-tight ${TONE_CLASSES[badge.tone]}`}
      >
        {badge.label}
      </span>
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
              {claiming ? 'Taking…' : 'Claim this filing'}
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
  const [sort, setSort] = useState<SortKey>('newest')
  /** '' means the whole tab; otherwise one status inside it. */
  const [statusFilter, setStatusFilter] = useState('')
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
  /**
   * New / renewal / amendment. '' is every type, which is what the officer sees
   * on arrival — a queue that opens pre-narrowed is a queue that lies about how
   * much work there is.
   *
   * Server-side, like the tab, the search and the holder. A browser filter over
   * one page of a paged feed would tell an officer there are no renewals when
   * theirs are further down.
   */
  const [appType, setAppType] = useState<'' | 'new' | 'renewal' | 'amendment'>('')
  /** The row being claimed, so one press cannot be double-fired. */
  const [claimingId, setClaimingId] = useState<number | null>(null)
  const [claimError, setClaimError] = useState<string | null>(null)

  /*
   * See ANY_OFFICE: an officer handed a permanently empty tab is being told
   * something, and an empty queue is a claim this screen cannot make truthfully
   * to somebody it was never going to show a row.
   *
   * ── It cuts BOTH ways now, and one of the two was showing ────────────────
   *
   * Three tabs are BPLO's. Pending Payment, because an unpaid filing is routed
   * to no office at all. Final Approval, because a filing reaches it only once
   * every clearance is approved, so an office's own work on it is done and the
   * tab could only report what somebody else owes. And Awaiting Other Permits,
   * which is the stage from BPLO's seat — an inspecting office reads the same
   * stage through its own permit, on the tab below.
   *
   * For Inspection is the office tabs' one entry, and it is the half that was
   * missing. It filters on the reader's OWN permit being out for a site visit,
   * and BPLO has no such permit: its Business Permit pivot runs
   * `not_started` → `for_approval` → `approved` and is never
   * `for_inspection` (INSPECTION_STATUSES says so in as many words). So BPLO
   * was being offered a tab that could not hold a row on any filing, ever, and
   * the client found it by pressing it: *"There should be no For Inspection
   * anymore since BPLO does not have that."*
   *
   * Stated as two lists rather than one predicate because they are two facts —
   * "this stage is not yours to act on" and "this stage is not yours to see
   * through your own permit" — and a single `canReadEveryOffice ? … : …`
   * ternary would hide which tab was excluded for which reason.
   */
  const BPLO_ONLY_TABS: Tab[] = ['payment', 'gathering', 'final']
  const OFFICE_ONLY_TABS: Tab[] = ['inspection']
  /*
   * The pills: which tabs this seat gets, captioned the way this seat says it.
   *
   * `label` is rewritten rather than left to the component, because FilterPills
   * renders what it is handed — so the caption has to be resolved here, where
   * the seat is known. `tabLabel` returns the shared word for every tab but
   * one; see `officeLabel` on TABS for the one.
   */
  const tabs = TABS.filter((t) =>
    canReadEveryOffice
      ? !OFFICE_ONLY_TABS.includes(t.value)
      : !BPLO_ONLY_TABS.includes(t.value),
  ).map((t) => ({ value: t.value, label: tabLabel(t.value, !canReadEveryOffice) }))
  const typePills = canReadEveryOffice
    ? TYPE_PILLS
    : TYPE_PILLS.filter((t) => !BPLO_ONLY_TYPES.includes(t.value))
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
  /*
   * Whose status the Filter dropdown is narrowing on this screen.
   *
   * An office filters its own permit, so its selection is a CLEARANCE status
   * and must not be sent as `application_status` — doing that would ask the
   * server for filings whose FILING status is "for_approval" on a tab whose
   * rows are all `awaiting_other_permits`, and empty the queue. The two
   * vocabularies overlap in spelling (`for_approval`, `returned`) and in
   * nothing else, which is exactly how that mistake would go unnoticed.
   */
  const filtersOwnPermit = !canReadEveryOffice && TAB_CLEARANCE_OPTIONS[tab] !== undefined
  const activeStatuses: readonly ApplicationStatus[] =
    statusFilter && !filtersOwnPermit ? [statusFilter as ApplicationStatus] : tabStatuses
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
  /*
   * The For Inspection tab's filter is the tab itself. An office narrowing its
   * own permit inside another tab sends the same parameter, which is why these
   * are one expression and not two — `clearance_status` can only carry one
   * answer, and a tab that defines itself by it has already used it.
   */
  const clearanceStatuses =
    tab === 'inspection'
      ? INSPECTION_CLEARANCE_STATUSES
      : filtersOwnPermit && statusFilter
        ? statusFilter
        : undefined
  // A deep page buys nothing where the server is doing the searching; only the
  // browser-side sorts still need more rows than fit on a screen.
  const deep = searchesOnServer ? sort !== 'newest' : isDeep(search, sort)
  const perPage = deep ? DEEP_PAGE_SIZE : PAGE_SIZE

  const { data, loading, error, reload } = useAsync(
    () =>
      loadPage({
        tab,
        statuses,
        assignmentStatuses,
        clearanceStatuses,
        query: serverQuery,
        // Pending Payment reads `/applications`, which has no assignment to
        // hold; sending the narrowing there would be a parameter that endpoint
        // does not know and a filter the tab cannot honour.
        oic: tab === 'payment' || holder === '' ? undefined : holder,
        /*
         * Both endpoints can narrow by it, and they spell it differently:
         * `/applications` has taken `type` since it was written, the
         * assignment feed takes `application_type` to keep the noun
         * unambiguous beside `application_status`. `loadPage` does the
         * translating, so this passes one value.
         */
        appType: appType === '' ? undefined : appType,
        page,
        perPage,
      }),
    [
      tab,
      statuses,
      assignmentStatuses,
      clearanceStatuses,
      serverQuery,
      holder,
      appType,
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
    restart()
  }

  function selectType(next: '' | 'new' | 'renewal' | 'amendment') {
    if (next === appType) return
    setAppType(next)
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
    try {
      await assignments.claim(item.assignmentId)
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

  /**
   * Status options for the tab in hand — a tab never offers a status it excludes.
   *
   * Two vocabularies, one per seat, for the reason the row badge has two: BPLO
   * narrows the FILING, an office narrows its OWN PERMIT. See
   * TAB_CLEARANCE_OPTIONS for why the filing's list was unusable from an office
   * chair.
   *
   * An office tab with no clearance options — For Inspection, which is one
   * state by construction — is left with the "All in …" entry alone. That is
   * the honest dropdown for it: there is nothing to choose between.
   */
  const statusOptions: SortFilterOption[] = [
    /*
     * Captioned off the SEAT, not off `filtersOwnPermit`. The two agree on
     * every tab an office can currently reach, and they are not the same
     * question: one asks whose vocabulary this officer speaks, the other
     * whether this particular tab has a clearance list to offer. A future
     * office tab without one would print BPLO's wording here.
     */
    { value: '', label: `All in ${tabLabel(tab, !canReadEveryOffice)}` },
    ...(filtersOwnPermit
      ? (TAB_CLEARANCE_OPTIONS[tab] ?? []).map((c) => ({
          value: c,
          label: clearanceStatusMeta(c).label,
        }))
      : TAB_FILING_OPTIONS[tab].map((s) => ({
          value: s,
          label: applicationStatusMeta(s).label,
        }))),
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
  /*
   * Is the officer looking at a NARROWED queue, or at all of it?
   *
   * It decides which empty state they get, and that is not cosmetic: "Your
   * queue is clear" is a claim about the office's workload, and making it to
   * somebody who has just filtered to Renewal would be false — there may be
   * plenty of work, none of it a renewal. The other message, "Nothing matches
   * these filters", says the true thing and points at the cause.
   *
   * `appType` had to be added here for exactly that reason. The register holds
   * no submitted renewal today, so the first thing anybody does with the new
   * pill is produce an empty queue — and without this it would have read as
   * "you have no work".
   */
  const narrowed = Boolean(needle || statusFilter || appType)

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
    // The type pill too: it is one of the things that can have emptied the
    // queue, so a button offering to undo the narrowing has to undo all of it.
    // Leaving it set made "Clear search" hand back a list that was still empty.
    if (appType) selectType('')
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

      {/*
        * ── New / renewal / amendment, FIRST ──────────────────────────────────
        *
        * Client's instruction, 17 September 2026: *"please place the application
        * type at the very top."* It reads as the outer question and the rows
        * below it as narrowings of that, which is also how the work divides: a
        * renewal is a different piece of work from a new registration, and an
        * officer who handles one kind picks it once and then moves between
        * stages inside it.
        *
        * Offered on EVERY tab, unlike the holder pills below: the type is a fact
        * about the filing and is meaningful wherever a filing appears, including
        * Pending Payment, where "which of these unpaid ones are renewals" is a
        * real question and there is no holder to ask about.
        *
        * Amendment is dropped for the five clearance offices — see TYPE_PILLS.
        */}
      <div className="mb-3">
        <FilterPills options={typePills} value={appType} onChange={selectType} />
      </div>

      <div className="mb-3">
        <FilterPills options={tabs} value={tab} onChange={selectTab} />
      </div>

      {/*
        * Who holds the case — the client's four sections, as a second row of
        * pills rather than more tabs.
        *
        * They are a different question from the tabs above, and crossing them is
        * the point: "my assigned filings that are for approval" is the officer's
        * actual working list, and folding these into the tab row would make the
        * two mutually exclusive.
        *
        * Hidden on Pending Payment, where the rows are filings no office has
        * been routed yet. There is nothing to hold there, and offering the
        * narrowing would return an empty list under every heading but "All".
        */}
      {tab !== 'payment' && (
        <div className="mb-3">
          <FilterPills options={HOLDER_PILLS} value={holder} onChange={selectHolder} />
        </div>
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
              ? /*
                 * ── Name the narrowing that emptied it ────────────────────────
                 *
                 * It said "No application in this queue has that status" for
                 * every narrowing, and that became wrong the moment the type
                 * pills arrived: an officer on Amendment was told the QUEUE had
                 * no filing at that STATUS, which is a claim about the stage and
                 * usually false — the stage is full, of new filings.
                 *
                 * The client hit it immediately, on Amendment × Awaiting Other
                 * Permits: *"Make sure the filtering is appropriate because
                 * there are NO AMENDMENTS that are AWAITING OTHER PERMITS."*
                 * True of the register today and worth saying rather than
                 * leaving them to infer it — but NOT a reason to hide the
                 * combination, because an amendment does reach that stage:
                 * `WorkflowService::attachRequiredPermitTypes` leaves
                 * amendments on the NEW path deliberately ("the client has said
                 * they will deal with it separately"), so it attaches all five
                 * clearances and passes through Awaiting Other Permits exactly
                 * as a new filing does. There is simply no amendment on the
                 * register yet. Hiding the pill would hide real rows the day
                 * somebody files one.
                 */
                appType !== ''
                  ? `No ${TYPE_PILLS.find((t) => t.value === appType)?.label.toLowerCase() ?? appType} filing is at this stage right now. Other types may be — try All types.`
                  : 'No application in this queue has that status. Try a different filter.'
              : tab === 'payment'
                ? 'No filing is waiting on payment right now.'
                : tab === 'approval'
                  ? 'Nothing is waiting on your department’s review right now.'
                  : tab === 'gathering'
                    ? // Said from BPLO's seat, because this tab is only ever
                      // read from it: nothing here is waiting on BPLO, and an
                      // empty version of it is good news rather than an idle
                      // queue.
                      'No filing is out with the other offices right now.'
                    : tab === 'final'
                      ? /*
                         * ── Say WHY this tab is usually empty ─────────────────
                         *
                         * It shared the inspection tab's line — "Nothing your
                         * office has approved is still in progress" — which
                         * since 18 September 2026 reads as a system that has
                         * stopped working. A new application no longer stops
                         * here at all: the fifth clearance issues the Mayor's
                         * Permit outright, so the only filings that reach this
                         * stage are renewals, whose uploaded certificates BPLO
                         * genuinely does read.
                         *
                         * An empty destructive-looking queue makes people go
                         * looking for the filings they think they have lost, so
                         * the emptiness is explained rather than merely stated.
                         */
                        'Nothing is waiting on your final approval. New applications no longer stop here — their Mayor’s Permit is issued as soon as the last clearance is approved. Renewals still arrive here for you to check the certificates they uploaded.'
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
                claiming={claimingId === item.assignmentId}
                /* BPLO reads the filing; the five offices read their own permit. */
                ownPermit={!canReadEveryOffice}
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
