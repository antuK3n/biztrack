import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ChevronRightIcon, TrackIcon } from '../../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import {
  FilterPills,
  PageTitle,
  SortFilter,
  type SortFilterOption,
} from '../../components/ui/Proto'
import { ClearanceCorrections } from './ClearanceCorrections'
import { MainFormCorrections } from './MainFormCorrections'
import { businessName, formatDateTime } from '../../lib/format'
import { applications, reference } from '../../lib/resources'
import {
  AMENDMENT_NOTE,
  CLEARANCE_FLOW,
  OTHER_PERMIT_FLOW,
  OTHER_PERMIT_GUIDE,
  OTHER_PERMIT_NOTE,
  DRAFT_LEAD,
  RENEWAL_LEAD,
  STATUS_GUIDE,
  TONE_CLASSES,
  applicationStatusMeta,
  clearanceStatusMeta,
  statusDetoursFor,
  GATHERING_META,
  GUIDE_BY_FLOW,
  filingStatusMeta,
  isGatheringOtherPermits,
  statusFlowFor,
  type GuideFlow,
  type StatusTone,
} from '../../lib/status'
import { useAsync } from '../../lib/useAsync'
import type {
  Application,
  ApplicationListItem,
  ApplicationStatus,
  Inspection,
  InspectionResult,
  PermitStatusChange,
  PermitType,
  ServerClearanceStatus,
} from '../../lib/types'

/*
 * Permit Tracking (PDF p48–49): a collapsible status guide, type filter
 * pills, white accordion rows per application with a status badge in that
 * status's own colour, and expanded per-permit rows with a status chip +
 * submitted date + message icon.
 *
 * The badge was a PAYMENT block — orange "Pay Online", green "Paid", and one
 * grey "Not billed yet" covering draft, for_approval and returned together —
 * which answered a question the row was not being asked. See the badge itself
 * for what changed and why, and StatusGuide for the legend that explains it.
 *
 * This list is the applicant's open work. An approved filing has no next step
 * left, so it moves to Profile, where the permits it produced are listed under
 * "Approved Businesses" (tester item 44). The count of what moved is shown
 * below the list so nothing silently disappears.
 */

type TypeFilter = '' | 'new' | 'renewal' | 'amendment'

/**
 * Finished filings: approval and issuance happen in one transaction
 * (WorkflowService::approveAndIssue), so an approved application always has its
 * permits waiting in Profile. Rejected stays here — it still needs a re-apply.
 */
/*
 * The statuses a FINISHED filing wears — but not, on their own, the test for
 * one. See `isFinished` below, and the client's report of 4 October 2026:
 * *"after paying for the application, THE BUSINESS IS ALREADY GONE IN THE
 * TRACKING PAGE. WHERE IS THE PART THAT I SHOULD APPLY FOR OTHER PERMITS?"*
 */
const FINISHED: ApplicationStatus[] = ['approved', 'issued']

/**
 * Has this filing left the applicant's hands for good?
 *
 * It was `FINISHED.includes(status)`, and that was exactly right while a paid
 * filing still gathering its five clearances wore `awaiting_other_permits`.
 * The client had that status removed on 4 October 2026, and a paid filing
 * wears `approved` from the moment its Mayor's Permit is released — so the
 * status test filed every freshly-paid application under "completed" and
 * dropped it from this page, taking with it the only screen that offers
 * "apply for your other permits". The applicant paid and the filing vanished.
 *
 * `isGatheringOtherPermits` is the row's own answer: it reads the server's
 * `decided` flag, falling back to the permit rows. A filing is finished when
 * it wears a finished status AND nothing is still being gathered on it.
 */
function isFinished(app: ApplicationListItem): boolean {
  return FINISHED.includes(app.status) && !isGatheringOtherPermits(app)
}

const FILTERS: { label: string; value: TypeFilter }[] = [
  { label: 'All', value: '' },
  { label: 'New Permit', value: 'new' },
  { label: 'Renewal', value: 'renewal' },
  { label: 'Amendment', value: 'amendment' },
]

/*
 * Sort, filter and search all run in the browser here, and that is the right
 * choice for this screen rather than a shortcut: `applications.list()` already
 * fetches this applicant's filings in one request up to the API's 200-row
 * ceiling — an owner has a handful, not the register's 1,668 — and the page
 * already filters that list in the browser twice over, drafts out and finished
 * out, before the type pills touch it. Sending these three to the server would
 * be a round trip per keystroke to reorder a list already sitting in memory.
 * The officer queue is the opposite case and is wired the opposite way; see
 * QueuePage.
 */

type SortKey = 'newest' | 'oldest' | 'deadline'

const SORTS: SortFilterOption[] = [
  { value: 'newest', label: 'Newest first' },
  { value: 'oldest', label: 'Oldest first' },
  { value: 'deadline', label: 'Deadline (soonest)' },
]

/**
 * Statuses a filing on this list can actually be in.
 *
 * Offering a status that can never match is a dead end that reads like a bug,
 * so approved/issued are left out (those filings have moved to Profile — see
 * FINISHED) and so is draft (drafts have their own page). Labels come from the
 * shared status table rather than being written again here, so the filter and
 * the chips cannot drift into two vocabularies for one status.
 */
/*
 * The four filing statuses this list can be narrowed to, plus the two terminal
 * ones.
 *
 * `submitted`, `under_review` and `for_inspection` were here and are gone with
 * the enum. Their replacements are not a rename: `for_approval` and
 * `for_final_approval` are BPLO's two separate acts, and the gathering stage
 * (`awaiting_other_permits` then, an undecided `approved` now) is what used to
 * be described — wrongly, once five permits could be at five different points
 * — as one filing-wide "For Inspection".
 *
 * `approved`/`issued` stay out (those filings have moved to Profile — see
 * FINISHED) and so does `draft` (drafts have their own page): offering a status
 * that can never match is a dead end that reads like a bug.
 */
/*
 * `for_final_approval` came off on 3 October 2026 with the stage itself.
 * It is on no guide the applicant can read, and the one route left to it
 * is an internal data problem (no confirmed RA 11032 category) that they
 * did not cause — so offering it as something to filter their own filings
 * by would be offering a word the site no longer explains anywhere.
 *
 * The chip still has a label if one of their filings does land there; see
 * `applicationStatusMeta`. This only keeps it out of the menu.
 */
const FILTERABLE_STATUSES: ApplicationStatus[] = [
  'for_approval',
  'pending_payment',
  'approved',
  'returned',
  'rejected',
  'cancelled',
]

/** "All" stays first: SortFilter marks Filter active by comparing to `options[0]`. */
const STATUS_FILTERS: SortFilterOption[] = [
  { value: '', label: 'All statuses' },
  /*
   * `approved` is on the list because a filing gathering its other permits
   * wears it and this page shows those (finished ones have left for Profile).
   * Labelled "Approved", not the status's own "Completed": here the word
   * only ever means the gathering kind.
   */
  ...FILTERABLE_STATUSES.map((s) => ({
    value: s,
    label: s === 'approved' ? GATHERING_META.label : applicationStatusMeta(s).label,
  })),
]

/**
 * When a filing "happened", for ordering.
 *
 * `submitted_at` is null on anything not yet filed, and ordering on a null
 * silently piles those rows at one end; `created_at` is always set, so it is
 * the honest fallback rather than a defensive one.
 */
function filedAt(a: ApplicationListItem): number {
  return new Date(a.submitted_at ?? a.created_at).getTime()
}

/**
 * Does this filing match what the applicant typed?
 *
 * The three things a person actually looks up: the tracking ID they were given
 * (BIZ-2026-00123), the business name, and their own title for the filing when
 * they gave it one. Matched case-insensitively on substrings so that typing
 * "00123" or "bakery" both work — nobody retypes a whole tracking ID.
 */
function matchesSearch(a: ApplicationListItem, needle: string): boolean {
  if (!needle) return true
  const haystack = [a.tracking_id, a.business?.name ?? '', a.title ?? ''].join(' ').toLowerCase()
  return haystack.includes(needle)
}

/**
 * One per-permit chip.
 *
 * `tone` is a `StatusTone` — status.ts's palette, the same one the filing's own
 * badge and the Track page's status guide read. It was a `ChipTone`, the
 * prototype's solid blocks, and that was the third vocabulary for one set of
 * facts: a clearance at `for_approval` wore solid orange here while a filing at
 * `for_approval` wore tinted purple one line above it, and `pending_payment`
 * was orange in both places by coincidence rather than by rule.
 *
 * The client, 17 September 2026: *"I told you to match the colors for the same
 * status. Do this for ALL, even those that are not captured by the screenshot
 * I sent."* Two maps cannot be kept matching by inspection — the screenshot
 * caught four of them — so there is now one map and these chips read it.
 */
interface Chip {
  tone: StatusTone
  label: string
}

/**
 * How the office that issues one permit type has left this filing.
 *
 * `undefined` on every count means "the detail response has not arrived, or did
 * not carry an answer" — never "no". Callers must fall back, not conclude.
 */
interface OfficeProgress {
  /** Status of the assignment raised against that permit type's department. */
  assignment: string | undefined
  /**
   * Result of that department's CURRENT visit, once it has been conducted.
   *
   * "Current" is the LATEST visit for the office, matching what the API means
   * by it (Inspection::scopeCurrentPerDepartment, and the same predicate
   * WorkflowService::recordInspection uses to decide the filing has cleared).
   * A failed visit is kept on the record rather than overwritten, so an office
   * awaiting a re-inspection carries two rows and only the newer one is its
   * standing.
   *
   * Undefined whenever that current visit has not been conducted — which
   * includes an office that failed once and has a fresh visit booked. Its
   * standing is "still to visit", not "failed": the failure is history, and
   * history is not this office's answer.
   */
  inspection: InspectionResult | undefined
}

/**
 * The chip for a status where the FILING's own state is the whole answer, and
 * no permit of its own has anything to say yet.
 *
 * `rejected` and `cancelled` are the far end of one fact: the filing is decided
 * and no office is working any part of it, so a per-permit chip would report
 * work nobody is doing.
 *
 * `pending_payment` earns its place for a different reason. Every permit on the
 * filing is `not_started` there, and truthfully so — but printing "Not Started"
 * five times beside an orange Pay Online button buries the one thing the
 * applicant can act on. The bill is the whole of the answer at that stage.
 *
 * `submitted` was in this table and the status no longer exists. `for_approval`
 * has deliberately NOT taken its place: the Business Permit really is being read
 * by BPLO then, which is its own permit's status, and the other five really are
 * Not Started — the per-permit chips are exact, so nothing is gained by
 * overriding them with one filing-wide word.
 *
 * The labels are read from the shared status table and never written again here.
 * `api/tests/Feature/StatusLabelParityTest.php` holds that table
 * character-for-character against the PHP enum, so a chip taken from it cannot
 * quietly become a third vocabulary for a state that already has a name.
 *
 * The tones are no longer this list's own either. It held
 * `{pending_payment: 'orange', rejected: 'red', cancelled: 'gray'}` — a private
 * palette that happened to agree with status.ts for two of the three and had no
 * mechanism to keep agreeing. Now the status answers for its own colour, and
 * this table is reduced to the only thing it knows that status.ts does not:
 * WHICH statuses speak for the whole filing.
 */
const APP_STATE_OVERRIDES: readonly ApplicationStatus[] = [
  'pending_payment',
  'rejected',
  'cancelled',
]

function appStateChip(status: ApplicationStatus): Chip | undefined {
  if (!APP_STATE_OVERRIDES.includes(status)) return undefined
  const meta = applicationStatusMeta(status)

  return { tone: meta.tone, label: meta.label }
}

/*
 * ── CLEARANCE_TONES is gone ───────────────────────────────────────────────
 *
 * It mapped each clearance status to a solid chip colour, and every entry it
 * held was a second answer to a question `CLEARANCE_STATUS` in status.ts had
 * already answered. Two of them disagreed outright: `for_approval` was orange
 * here and `review` (purple) there, so one screen printed two colours for one
 * state; and `returned` was red here against `warning` there, which says
 * "refused" about a permit an office has handed back for a correction.
 *
 * `clearanceStatusMeta(status)` gives the label AND the tone from one table, so
 * the chip below asks once. The reasoning the old table carried is not lost —
 * it moved to the table that now owns it, which is where it belongs and where a
 * later change to a colour has to pass it.
 */

/**
 * Per-permit chip (prototype p49) — read off THAT PERMIT'S own status.
 *
 * ── What this used to do, and both ways it was wrong ──────────────────────
 *
 * It inferred the chip from the issuing office's ASSIGNMENT and the
 * APPLICATION's status, because before 6 September 2026 there was nothing else
 * to read: a permit had no state of its own. Two rules did the work — a
 * completed assignment meant "Approved", and everything else fell through to
 * "For Approval" — and the new flow falsifies both.
 *
 *   THE BUSINESS PERMIT WENT GREEN THE MOMENT BPLO ACCEPTED THE FORM.
 *   `approveMainForm()` completes BPLO's assignment to move the filing to
 *   Pending Payment. That is BPLO saying "this form is fit to be paid for", not
 *   "here is your permit" — the permit is issued by `approveOverall()`, five
 *   approved clearances later. The row said Approved on a filing with no permit
 *   issued at all.
 *
 *   THE OTHER FIVE READ "FOR APPROVAL" BEFORE THEY HAD BEEN APPLIED FOR.
 *   They were `not_started`: attached to the filing at submission so the bill
 *   could price them, but not begun. Falling through to the default told the
 *   applicant five offices were reading paperwork that did not exist, and — the
 *   real cost — that there was nothing for them to do.
 *
 * Both are gone because the chip now reads `permit_types[].status`, which is the
 * pivot the API keeps and the same value every other screen uses. The inference
 * is not repaired, it is deleted: a screen holding a second, weaker copy of a
 * state machine is the defect, and it was wrong within a week of the machine
 * changing.
 *
 * ── What the inspection detail is still for ───────────────────────────────
 *
 * `for_inspection` says a visit is due; it cannot say the visit happened. The
 * office's own latest visit refines it, and the labels keep the distinction the
 * client asked for:
 *
 *   "Inspection Passed" is a fact about a VISIT — this office came, and the
 *   premises passed. It claims nothing about issuance, and an applicant knows a
 *   visit and a certificate are two different events.
 *
 *   "Approved" is a fact about a PERMIT, and it is now the pivot's own word,
 *   written by `grantClearance()` when the certificate is actually issued.
 *
 * The tone for a passed visit is `tint-green`, not the solid green of Approved:
 * two states that mean different things must not be one colour on one list.
 * Colour is not carrying it alone either — the labels differ, and the note under
 * the accordion spells the difference out in a sentence.
 *
 * A failed visit is shown too, and deliberately. Surfacing only the good
 * outcomes would be a screen that is selectively honest, and a failed inspection
 * is the single most actionable thing this list can tell anyone. `conditional`
 * progresses like a pass (InspectionResult::progresses), so it is grouped with
 * it rather than given a fourth label nobody asked for.
 */
function permitChip(
  appStatus: ApplicationStatus,
  permitStatus: ServerClearanceStatus | null,
  office: OfficeProgress | undefined,
  permitCode: string,
): Chip {
  // A decided or unpaid filing answers for all of its permits at once.
  const own = appStateChip(appStatus)
  if (own) return own

  /*
   * ── The Mayor's Permit before BPLO has accepted the form ───────────────
   *
   * Checked BEFORE the not-started branch below, because that branch is
   * right about the five clearances and wrong about this one. See the note
   * above the function.
   */
  if (permitCode === 'BUSINESS' && (!permitStatus || permitStatus === 'not_started')) {
    const meta = applicationStatusMeta(appStatus)

    return { tone: meta.tone, label: meta.label }
  }

  // No pivot row: the permit is not on this filing. Nothing to report.
  // The same words and the same colour as a permit that IS on the filing and
  // has not been begun — because to the applicant it is the same situation.
  if (!permitStatus)
    return { tone: clearanceStatusMeta('not_started').tone, label: 'Not Yet Submitted' }

  /*
   * ── The Mayor's Permit, between the two BPLO approvals ───────────────────
   *
   * Reported 17 September 2026: "I just paid my application, so why is the
   * Business Permit tagged as For Approval?"
   *
   * The pivot row really does say `for_approval`, and it is not wrong. BPLO
   * approved the FORM — that is what raised the bill — and the Mayor's Permit
   * itself is issued at the FINAL approval, which cannot run until the other
   * five clearances are approved. So the row is awaiting an approval.
   *
   * What was wrong is the word. "For Approval" on the row right after paying
   * reads as "an office is reading this now", and nothing at all can happen to
   * it until the applicant applies for the other five. The chip said the
   * status and not the situation.
   *
   * Only the outcome permit, and only while the filing is gathering the
   * others. Once BPLO actually has it — `for_final_approval` — `appStateChip`
   * has already answered for every row above, so this cannot mask a real
   * review in progress.
   *
   * A label of its own rather than a ClearanceStatus one, which is the
   * established shape here: "Not Yet Submitted", "Inspection Passed" and
   * "Inspection Failed" are all this function's own words for a situation the
   * status alone does not describe.
   */
  if (
    permitCode === 'BUSINESS' &&
    permitStatus === 'for_approval' &&
    appStatus === 'approved'
  ) {
    /*
     * The FILING's tone, because that is whose situation this is: the permit
     * is waiting on the other five, and the badge saying so is on the row
     * header directly above. `tint-gray` made the one row that explains the
     * wait the only row not coloured like it.
     *
     * `GATHERING_META` rather than a status lookup. It read
     * `applicationStatusMeta('awaiting_other_permits').tone` until that
     * status was removed on 4 October 2026 — after which the lookup missed,
     * fell through to the neutral default and turned this row grey, which is
     * the exact complaint the comment above records.
     */
    return {
      tone: GATHERING_META.tone,
      label: 'Waiting for your other permits',
    }
  }

  /*
   * No special case for the Mayor's Permit at `approved`, and that is now
   * deliberate rather than an omission.
   *
   * One stood here for an hour on 26 September 2026 labelling it "Permit
   * Released" to match the rail. The client took the opposite and better
   * view: *"Approved does not mean it is Completed, and it is similar to the
   * other permits where Approved means the permit was released already."*
   * So the FILING moved to "Approved" instead, and this row falls through to
   * `clearanceStatusMeta` with the other five — one word, six rows, no case.
   */
  if (permitStatus === 'for_inspection' && office) {
    /*
     * Two outcomes of a visit, and the tones are borrowed from the two statuses
     * that mean the same thing about a permit — rejected and approved — rather
     * than picked. A failed inspection is not yet a rejection, but it is the
     * same colour of news, and an applicant reading this row has no use for a
     * shade that exists nowhere else.
     */
    /*
     * `danger` directly, not `clearanceStatusMeta('rejected').tone`. That
     * borrowed the tone from a clearance state that no longer exists — the case
     * went on 17 September 2026 — and the lookup would have fallen through to
     * the neutral default, quietly painting a failed inspection grey.
     */
    if (office.inspection === 'failed') return { tone: 'danger', label: 'Inspection Failed' }
    if (office.inspection === 'passed' || office.inspection === 'conditional')
      return { tone: clearanceStatusMeta('approved').tone, label: 'Inspection Passed' }
  }

  const meta = clearanceStatusMeta(permitStatus)

  return { tone: meta.tone, label: meta.label }
}

/** Solid accordion triangle (p48). */
function Triangle({ open }: { open: boolean }) {
  return (
    <svg
      width="20"
      height="20"
      viewBox="0 0 24 24"
      aria-hidden="true"
      className={`shrink-0 text-ink-secondary transition-transform ${open ? 'rotate-180' : ''}`}
    >
      <path d="M5 8h14l-7 9L5 8Z" fill="currentColor" />
    </svg>
  )
}

/** Message bubble icon (p49). */
function MessageIcon({ size = 22 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <rect x="3" y="4.5" width="18" height="13" rx="3" stroke="currentColor" strokeWidth="1.75" />
      <path
        d="M8 17.5v3l3.5-3"
        stroke="currentColor"
        strokeWidth="1.75"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <circle cx="8.5" cy="11" r="1" fill="currentColor" />
      <circle cx="12" cy="11" r="1" fill="currentColor" />
      <circle cx="15.5" cy="11" r="1" fill="currentColor" />
    </svg>
  )
}

/** Cache of loaded application detail per row id (survives collapse/re-expand). */
type DetailCache = Record<number, Application>

/**
 * Why a filing was rejected, on the row itself (tester item 80).
 *
 * The officer writes a reason into `rejection_reason` when they reject, and
 * this page used to show a red "Rejected" chip and nothing else — a verdict
 * with no grounds, which leaves the applicant with no move except to phone the
 * LGU and ask. It is shown on the collapsed row rather than inside the
 * accordion because it is the one thing on a rejected row worth reading, and a
 * reason nobody expands to find is a reason nobody reads.
 *
 * The reason is not on the list payload (`ApplicationListResource` omits it);
 * it comes from the detail endpoint the page already fetches, which is why this
 * renders three states rather than one — loading, present, and recorded-empty.
 * The empty case still has to say something actionable: "rejected, no reason
 * given" is a worse silence than the chip alone if it looks like a blank box.
 */
function RejectionNote({
  app,
  detail,
}: {
  app: ApplicationListItem
  detail: Application | undefined
}) {
  return (
    /*
     * Pulled up tight under its own row: the list puts 16px between filings
     * and the accordion 12px inside one, so at the default gap this read as a
     * seventh card rather than as a note about the sixth.
     */
    <div className="mt-1! rounded-xl border border-s-red/30 bg-s-red-tint px-5 py-3.5">
      <p className="text-sm font-bold text-s-red">Rejected</p>
      {detail === undefined ? (
        <p className="mt-1 text-sm text-ink-secondary">Loading the reason…</p>
      ) : detail.rejection_reason ? (
        <p className="mt-1 whitespace-pre-line text-sm text-ink">{detail.rejection_reason}</p>
      ) : (
        <p className="mt-1 text-sm text-ink-secondary">
          No reason was recorded with this decision. Message the office handling it for the details.
        </p>
      )}
      <Link
        to={`/applications/${app.id}`}
        // Named for the filing it opens: a list of links all reading "Open
        // this application" is a list a screen reader user cannot choose from.
        aria-label={`Open the rejected application for ${businessName(app.business)}`}
        className="mt-1.5 inline-block text-sm font-semibold text-royal underline underline-offset-2 hover:no-underline"
      >
        Open this application
      </Link>
    </div>
  )
}

/**
 * "BPLO sent this back" — the returned filing's own line on Track.
 *
 * ── Why the card needed one ─────────────────────────────────────────────────
 *
 * A returned filing looked exactly like a filing under review: a status pill
 * and a count of approved permits. The one difference that matters — the
 * office is waiting on YOU — was visible only after opening it.
 *
 * `RejectionNote` directly above solves the same shape of problem for the
 * other status an applicant must act on, so this follows it rather than
 * inventing a second treatment.
 *
 * ── It does not name the fields, and that is not laziness ───────────────────
 *
 * `ApplicationListItem` carries no assignments — the list payload is lean on
 * purpose, since it is fetched for every filing the applicant has — so the
 * pointer that names the returned fields is not here to read. Fattening the
 * list to print them on a card would buy a sentence and cost every row.
 *
 * The card's job is to say YOU are the hold-up and get them one click from
 * the boxes. The status page names each field beside its own input, which is
 * where naming them is actually useful.
 */
function ReturnedNote({
  app,
  onCorrect,
}: {
  app: ApplicationListItem
  /** Open the correction dialog here, rather than navigating to it. */
  onCorrect: () => void
}) {
  return (
    <div className="mt-2 rounded-lg border-l-4 border-s-rose bg-s-rose-tint/40 px-4 py-3">
      <p className="text-sm font-bold text-ink">BPLO sent this back for correction</p>
      <p className="mt-0.5 text-sm text-ink-secondary">
        Open it to see which fields to correct, then resubmit.
      </p>
      {/*
        Opens here, not on the filing's page. The client, 30 September 2026:
        the page behind the dialog was doing nothing the dialog did not.
      */}
      <button
        type="button"
        onClick={onCorrect}
        aria-label={`Fix and resubmit the application for ${businessName(app.business)}`}
        className="mt-2 inline-flex cursor-pointer items-center gap-1 rounded-md border border-royal/30 bg-white px-3 py-1.5 text-sm font-semibold text-royal transition-colors hover:border-royal hover:bg-royal-tint focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
      >
        Fix and resubmit →
      </button>
    </div>
  )
}

/**
 * One or more office clearances sent back, on a collapsed card.
 *
 * `ReturnedNote` above, for the other five offices. The client asked for it
 * on 30 September 2026 — *"I also want it to have something like this, not
 * just for BPLO returns"* — and the reasoning that put the BPLO one here
 * carries across whole: a card showing a status pill and a permit count
 * looks the same whether an office is working on the filing or waiting on
 * the applicant, and that is the one difference worth seeing from the list.
 *
 * Separate from `ReturnedNote` because the repair is somewhere else. A
 * returned form is fixed on the status page where the correction boxes are;
 * a returned clearance is fixed on that office's own sheet.
 *
 * With more than one returned, the link goes to the clearance stage rather
 * than picking one of them — the applicant has two repairs to make and the
 * grid is where both are reachable.
 */
function ReturnedClearanceNote({
  app,
  returned,
  onCorrect,
}: {
  app: ApplicationListItem
  /** The clearances sitting at `returned`; never empty. */
  returned: ApplicationListItem['permit_types']
  /** Open the correction dialog here, rather than navigating to it. */
  onCorrect: (code: string) => void
}) {
  const one = returned.length === 1 ? returned[0] : null

  return (
    <div className="mt-2 rounded-lg border-l-4 border-s-rose bg-s-rose-tint/40 px-4 py-3">
      <p className="text-sm font-bold text-ink">
        {/*
          Refused reads differently from returned, and the difference
          matters to the applicant: a refusal suspends their Business
          Permit while it stands. Both are answered the same way.
        */}
        {one !== null
          ? `${one.name} was ${one.status === 'rejected' ? 'refused' : 'sent back for correction'}`
          : `${returned.length} clearances need your attention`}
      </p>
      <p className="mt-0.5 text-sm text-ink-secondary">
        {/*
          The list payload has no room for the officer's words — see the
          note on ReturnedNote for why it is not widened — so this says
          where they are rather than inventing a summary of them.
        */}
        Open{' '}
        {one !== null ? 'the form' : 'them'} to see what to correct, then resubmit.
      </p>
      {/*
        Opens here, not on another page. One returned clearance is answered
        in the dialog; several go to the stage, because the applicant has
        two repairs to make and the grid is where both are reachable.
      */}
      {one !== null ? (
        <button
          type="button"
          onClick={() => onCorrect(one.code)}
          aria-label={`Fix and resubmit ${one.name} for ${businessName(app.business)}`}
          className="mt-2 inline-flex cursor-pointer items-center gap-1 rounded-md border border-royal/30 bg-white px-3 py-1.5 text-sm font-semibold text-royal transition-colors hover:border-royal hover:bg-royal-tint focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
        >
          Fix and resubmit →
        </button>
      ) : (
        <Link
          to={`/applications/${app.id}/clearances`}
          aria-label={`Fix and resubmit the clearances for ${businessName(app.business)}`}
          className="mt-2 inline-flex cursor-pointer items-center gap-1 rounded-md border border-royal/30 bg-white px-3 py-1.5 text-sm font-semibold text-royal transition-colors hover:border-royal hover:bg-royal-tint focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
        >
          Fix and resubmit →
        </Link>
      )}
    </div>
  )
}

/**
 * What every status on this page means, and what happens after it.
 *
 * ── Why it is here and not in a modal ─────────────────────────────────────
 *
 * The question a guide answers is "which of these am I?", and that needs the
 * guide and the applicant's own row visible at the same time. A modal covers
 * the list, so it has to be read, memorised and dismissed before it can be
 * used — which is the one thing this is meant to spare somebody.
 *
 * Collapsed to start. A first-time applicant has never seen these words and
 * needs it; somebody checking on a filing for the fourth time this week does
 * not, and a legend they scroll past every visit is furniture on a screen
 * whose whole job is showing them their own work.
 *
 * ── Why the colours cannot drift from the badges ──────────────────────────
 *
 * Both read `TONE_CLASSES[applicationStatusMeta(s).tone]`. There is no second
 * list of colours to keep in step — the swatch beside "Pending Payment" here
 * IS the class the badge on the row puts on itself. Before this, the rows had
 * three colours between ten statuses (orange, green, and one grey covering
 * Draft, For Approval and Returned), so a guide with a colour per
 * status would have been describing a screen that did not exist.
 */
/** The three paths, in the order an applicant meets them. */
const GUIDE_PILLS: { value: GuideFlow; label: string }[] = [
  { value: 'new', label: 'New Permit' },
  { value: 'renewal', label: 'Renewal' },
  { value: 'amendment', label: 'Amendment' },
]

function StatusGuide() {
  const [open, setOpen] = useState(false)
  /*
   * Defaults to `new`, not to whatever the applicant last filed.
   *
   * Tempting to guess from their own applications, and wrong: this panel sits
   * above a list that may hold all three kinds, and a guide that silently
   * describes a renewal because the newest row happens to be one is worse than
   * one that always starts somewhere stated. The pill shows which is selected,
   * so `new` is a visible default rather than an assumption.
   */
  const [flow, setFlow] = useState<GuideFlow>('new')
  const rail = statusFlowFor(flow)

  /**
   * One step ON the flow: a numbered node, the badge, the description.
   *
   * ── Why a vertical rail and not a horizontal chain ────────────────────────
   *
   * Seven stages side by side fits a desktop and nothing else. Each one needs
   * its badge AND a sentence, and a horizontal chain would either drop the
   * sentences — leaving the legend this replaced — or wrap into an unreadable
   * grid on a phone, which is where most applicants open this.
   *
   * The rail runs down the numbers, not down the badges. The badges are the
   * status labels, so their widths vary from "Draft" to "Awaiting Other
   * Permits", and a connector centred under them would zig-zag. A
   * fixed-width numbered node gives the line something straight to follow.
   */
  const Step = ({
    status,
    index,
    last,
    description,
    withSubFlow = true,
  }: {
    status: ApplicationStatus
    index: number
    last: boolean
    /**
     * Overrides STATUS_GUIDE for this rail. The other-permit rail needs it:
     * the shared line for `for_approval` names BPLO, and on that path BPLO is
     * not involved at all.
     */
    description?: string
    /**
     * The five-clearance sub-flow belongs to a new application's step 4 and
     * nowhere else. Off on the other-permit rail, where one permit IS the
     * filing and nesting the five inside it would claim the opposite of what
     * the rail is there to say.
     */
    withSubFlow?: boolean
  }) => {
    /*
     * "Approved", never "Completed", on the rail. The badge for `approved`
     * reads Completed once a filing is decided, and the guide's last step was
     * borrowing that word. Client, 5 October 2026: *"Approved means the
     * permit is issued while Completed means the whole process is done"* —
     * and a completed filing has left this page, so the guide has no step to
     * call by that name.
     */
    const meta = status === 'approved' ? GATHERING_META : applicationStatusMeta(status)

    return (
      /*
        ── Tightened 19 September 2026 ──────────────────────────────────────

        Client: *"Can they be tightened a little bit? They took so much of the
        space, making it look like overwhelming when read by the user."* Fair —
        the panel had grown a per-type rail, a lead callout and a second rail,
        each justified on its own and none of them counting the vertical cost.

        Three changes, in order of how much they gave back: the badge and its
        sentence share a ROW instead of stacking (one line saved per step, six
        on this panel), the node is 24px rather than 32px, and the gap between
        steps is 10px rather than 20px. The chevron between nodes is gone — on
        segments this short it read as clutter, and the rail plus the numbers
        already carry the sequence.

        Nothing was made smaller in type. Shrinking text to fit is how a panel
        becomes tight AND unreadable; the words were cut instead (see
        STATUS_GUIDE).
      */
      <li className="relative flex gap-3 pb-2.5 last:pb-0">
        {/*
          Decoration only: the ORDER is carried by the <ol> and the visible
          numbers, so a screen reader gets the sequence without it.

          Positioned off the node's own geometry rather than by flex — the node
          is 24px wide, so its centre is at 12px and a 1px rail sits at 11px.
          Both numbers move together or the rail drifts off the circles.
        */}
        {!last && (
          <span
            aria-hidden="true"
            className="absolute bottom-0 left-[11px] top-6 w-px bg-royal/30"
          />
        )}
        <span
          className={`relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-bold ${TONE_CLASSES[meta.tone]}`}
        >
          {index + 1}
        </span>
        <div className="min-w-0 flex-1">
          {/*
            One row, wrapping only when it has to. `items-baseline` so the
            badge sits on the sentence's own line rather than floating above
            it, and `flex-wrap` so a narrow phone drops the text under the
            badge instead of squeezing it to two words per line.
          */}
          <div className="flex flex-wrap items-baseline gap-x-2.5 gap-y-1">
            <span
              className={`shrink-0 rounded-md border px-2 py-0.5 text-xs font-bold ${TONE_CLASSES[meta.tone]}`}
            >
              {meta.label}
            </span>
            <p className="min-w-0 flex-1 text-[13px] leading-snug text-ink-secondary">
              {description ?? STATUS_GUIDE[status]}
            </p>
          </div>
          {/*
            ── The sub-flow, only under the step that has one ────────────────

            Step 4 is where the application WAITS while the other five permits
            run their own course, and it is the longest stage of the whole
            thing. The main flow said "apply for your other permits" and then
            went quiet, so an applicant sitting there could not see that each
            permit is itself reviewed, inspected and approved — and that those
            approvals are what release the step.

            Indented and in the clearance's own words, which are the words on
            the per-permit chips one tap away on the row itself. It ends at
            Approved on purpose: that is the thing the applicant is waiting
            for, and it is what lets the main flow continue to step 5.
          */}
          {withSubFlow && status === 'approved' && (
            <div className="mt-2.5 rounded-lg border border-line bg-shell px-3.5 py-2.5">
              <p className="text-xs font-semibold text-ink-secondary">Each permit, on its own:</p>
              <ol className="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1.5">
                {CLEARANCE_FLOW.map((clearance, i) => {
                  const step = clearanceStatusMeta(clearance)

                  return (
                    <li key={clearance} className="flex items-center gap-1.5">
                      <span
                        className={`rounded-md border px-2 py-0.5 text-[11px] font-bold ${TONE_CLASSES[step.tone]}`}
                      >
                        {step.label}
                      </span>
                      {i < CLEARANCE_FLOW.length - 1 && (
                        <span aria-hidden="true" className="text-ink-muted">
                          <ChevronRightIcon size={12} />
                        </span>
                      )}
                    </li>
                  )
                })}
              </ol>
              <p className="mt-1.5 text-xs leading-relaxed text-ink-muted">
                {/*
                  Counted from this step's own position rather than written as
                  "step 5". It was hardcoded, and correct only because Awaiting
                  Other Permits happened to be fourth on the one rail that
                  existed. Now there are three rails and the number has to
                  follow the step it is standing on.
                */}
                Once every permit is approved, your application is complete and leaves this page.
              </p>
            </div>
          )}
        </div>
      </li>
    )
  }

  /**
   * One way the flow can be interrupted — off the rail, deliberately.
   *
   * No number and no connector. Reading "Rejected" as step 8 of an ordinary
   * application would be alarming and wrong; none of these three is a step
   * everybody takes. The turnstile glyph says "branches off here" without
   * claiming a position in the sequence.
   */
  const Detour = ({ status }: { status: ApplicationStatus }) => {
    const meta = applicationStatusMeta(status)

    return (
      <li className="flex flex-wrap items-baseline gap-x-2.5 gap-y-1 py-1 sm:flex-nowrap">
        {/*
          Same TONE classes as the badge on the row, which is what recognition
          actually rests on — the colour, not the padding.

          Sized to match the rail's badges above, which are StatusBadge's own
          `sm` (px-2 py-0.5 text-xs). This was px-2.5 py-1 text-xs and matched
          neither of StatusBadge's two sizes — the default's padding carrying
          the small size's text. One badge size inside one panel, and a size
          the design system already has a name for.
        */}
        <span aria-hidden="true" className="shrink-0 pt-0.5 text-ink-muted">
          <ChevronRightIcon size={14} />
        </span>
        <span
          className={`shrink-0 rounded-md border px-2 py-0.5 text-xs font-bold ${TONE_CLASSES[meta.tone]}`}
        >
          {meta.label}
        </span>
        <span className="text-[13px] leading-snug text-ink-secondary">{STATUS_GUIDE[status]}</span>
      </li>
    )
  }

  return (
    <section className="mb-6">
      <h2>
        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          aria-expanded={open}
          aria-controls="status-guide"
          className="flex w-full items-center justify-between gap-3 rounded-lg border border-line bg-white px-5 py-3 text-left transition-colors hover:bg-shell"
        >
          <span className="text-sm font-bold text-ink">How your application moves</span>
          <span className="shrink-0 text-xs font-semibold uppercase tracking-wide text-royal">
            {open ? 'Hide' : 'Show'}
          </span>
        </button>
      </h2>
      <div
        id="status-guide"
        hidden={!open}
        className="rounded-b-lg border border-t-0 border-line bg-white px-5 py-3.5"
      >
        {/*
          ── Which filing the reader is asking about ─────────────────────────

          Client, 19 September 2026: *"add here a filtering or some sort for New
          Permit, Renewal, and Amendment, because all have different
          processes."* They are different, and one rail was describing somebody
          else's filing to two thirds of the people reading it.

          Pills rather than a select, matching the ones this page already uses
          above the list: three named paths are faster to compare than a menu
          you have to open twice to see both answers.
        */}
        <FilterPills options={GUIDE_PILLS} value={flow} onChange={setFlow} />

        {/*
          Where the filing has NOT got to, before the steps it has not
          started. On every tab, because a draft is a draft whatever it will
          become, and because the rail no longer carries a step that says so.
        */}
        <p className="mt-3 text-[13px] leading-snug text-ink-secondary">{DRAFT_LEAD}</p>

        {/*
              What this rail is, BEFORE the reader starts counting steps.

              Without it the renewal rail and the new-application rail both show
              five numbered steps, and the difference between them lives in
              step 4 having changed in kind. That is too much to infer.
            */}
        {flow === 'renewal' && (
          <p className="mt-3 rounded-lg border border-royal/30 bg-royal-tint/50 px-3.5 py-2 text-[13px] leading-snug text-ink">
            {RENEWAL_LEAD}
          </p>
        )}

        {/*
              What an amendment may change, said before the steps rather than
              after: the commonest wrong turn is filing one to change something
              it cannot touch, and that is worth heading off at the top.
            */}
        {flow === 'amendment' && (
          <p className="mt-3 rounded-lg border border-royal/30 bg-royal-tint/50 px-3.5 py-2 text-[13px] leading-snug text-ink">
            {AMENDMENT_NOTE}
          </p>
        )}

        <ol className="mt-3">
          {rail.map((status, index) => (
            <Step
              key={status}
              status={status}
              index={index}
              last={index === rail.length - 1}
              description={GUIDE_BY_FLOW[flow]?.[status]}
              /*
               * The five-clearance sub-flow hangs off `approved` since
               * 4 October 2026, and `approved` is on every rail — so it has to
               * be said here which rail it belongs to. It was `awaiting_other_permits`,
               * which only the new rail carried, and the restriction came for
               * free. A renewal gathers nothing; nesting five clearances under
               * its last step would claim the opposite of what that rail says.
               */
              withSubFlow={flow === 'new'}
            />
          ))}
        </ol>

        {/*
              ── The other permits, as their own process ────────────────────

              Client, 19 September 2026, asking how to make it clear that other
              permits renew on their own "unlike in new application where other
              permits are really part of the application process".

              A second RAIL rather than a footnote, which is what was here
              before. The footnote described a whole separate filing in the
              smallest grey text on the panel, so the independence it was
              announcing read as a caveat on the rail above it. Drawn, and
              visibly shorter, the shape carries the point: fewer steps, no
              payment stage, no BPLO.
            */}
        {flow === 'renewal' && (
          <div className="mt-4 rounded-lg border border-line bg-shell px-3.5 py-3">
            <h3 className="text-xs font-bold uppercase tracking-wide text-ink-secondary">
              Renewing one of your other permits
            </h3>
            <p className="mt-1 text-[13px] leading-snug text-ink-secondary">{OTHER_PERMIT_NOTE}</p>
            <ol className="mt-2.5">
              {OTHER_PERMIT_FLOW.map((status, index) => (
                <Step
                  key={status}
                  status={status}
                  index={index}
                  last={index === OTHER_PERMIT_FLOW.length - 1}
                  description={OTHER_PERMIT_GUIDE[status as keyof typeof OTHER_PERMIT_GUIDE]}
                  withSubFlow={false}
                />
              ))}
            </ol>
          </div>
        )}

        {/*
          The detours, under their own heading and off the rail. See Detour.
          Per flow, because what counts as an interruption differs by type.
          For Final Approval is the case that taught this: it was a STEP on a
          renewal until 3 October 2026, and dropping it from that rail
          without naming it in OMITTED_BY_TYPE would file it under this
          heading as something that might interrupt a filing.

          The list is the derived ones PLUS Suspended, and the difference is
          worth knowing before reading `statusDetoursFor` and finding its
          output one short: that function returns ApplicationStatuses, and a
          suspension happens to the PERMIT. It could not have produced this row
          however correct it is.
        */}
        <h3 className="mt-4 border-t border-line pt-3 text-xs font-bold uppercase tracking-wide text-ink-secondary">
          If something interrupts it
        </h3>
        <ul className="mt-1 divide-y divide-line">
          {statusDetoursFor(flow).map((status) => (
            <Detour key={status} status={status} />
          ))}
          {/*
            Last, because it is the only one that can arrive after everything
            has gone right — and NEW filings only: a suspension follows one of
            the other permits being refused, which an amendment never gathers.

            Red, matching the notice PermitDetailPage prints on a suspended
            certificate — NOT the purple a suspended BUSINESS wears in
            BUSINESS_STATUS wears. That one is an admin action against the whole
            account for a different reason, and one word in two colours is the
            confusion this row exists to prevent.
          */}
          {flow === 'new' && (
            <li className="flex flex-wrap items-baseline gap-x-2.5 gap-y-1 py-1 sm:flex-nowrap">
              <span aria-hidden="true" className="shrink-0 pt-0.5 text-ink-muted">
                <ChevronRightIcon size={14} />
              </span>
              <span
                className={`shrink-0 rounded-md border px-2 py-0.5 text-xs font-bold ${TONE_CLASSES.danger}`}
              >
                Suspended
              </span>
              <span className="text-[13px] leading-snug text-ink-secondary">
                A rejected permit puts your Mayor&rsquo;s Permit on hold until it is settled.
              </span>
            </li>
          )}
        </ul>
      </div>
    </section>
  )
}

function ApplicationRow({
  app,
  permitTypesByCode,
  detail,
  onExpand,
  onChanged,
}: {
  app: ApplicationListItem
  permitTypesByCode: Map<string, PermitType>
  detail: Application | undefined
  onExpand: (id: number) => void
  /** Re-read this filing after the applicant has resubmitted something. */
  onChanged: () => void
}) {
  /*
   * Which permit timelines are open, as "<application id>:<permit code>".
   *
   * A Set rather than one open row: the applicant comparing why two permits
   * are behind should not have the first close when they open the second.
   * Not persisted — it is a reading position, not an answer.
   */
  const [openHistory, setOpenHistory] = useState<Set<string>>(new Set())
  const toggleHistory = (key: string) =>
    setOpenHistory((prev) => {
      const next = new Set(prev)
      if (!next.delete(key)) next.add(key)

      return next
    })
  const [open, setOpen] = useState(false)
  const pending = app.status === 'pending_payment'
  const rejected = app.status === 'rejected'
  /*
   * The clearances an office has sent back.
   *
   * BUSINESS is excluded: it is the filing's outcome rather than one of the
   * five, and a returned MAIN FORM is `app.status`, which `ReturnedNote`
   * already answers. Counting it here would put two rose banners on one card
   * saying the same thing twice.
   */
  /*
   * Clearances waiting on the applicant: returned, and — since 30 September
   * 2026 — refused. Both are answered the same way, by changing what the
   * office asked about and sending the sheet back, and both now carry the
   * pointer that says which rows those are.
   *
   * BUSINESS is excluded: it is the filing's outcome rather than one of the
   * five, and a returned MAIN FORM is `app.status`, which `ReturnedNote`
   * already answers.
   */
  const returnedClearances = (app.permit_types ?? []).filter(
    (pt) =>
      (pt.status === 'returned' || pt.status === 'rejected') && pt.code !== 'BUSINESS',
  )
  /*
   * Which returned clearance the applicant is answering, or null.
   *
   * Held here rather than in the URL: the dialog belongs to this card, and
   * the tracking page shows every filing the applicant has. A query
   * parameter would have to name the filing as well as the permit to say
   * which card it meant.
   */
  const [correcting, setCorrecting] = useState<string | null>(null)
  /*
   * And the MAIN FORM's own return, which is a different dialog: BPLO names
   * fields on the application, not documents on an office checklist.
   *
   * Needs the DETAIL payload — the pointer is on the assignments — so the
   * press asks for it if the card has not already fetched it.
   */
  const [correctingForm, setCorrectingForm] = useState(false)
  /*
   * ── The block stopped being about payment ─────────────────────────────────
   *
   * It went through three shapes, and the history is worth keeping because
   * each fixed the previous one's lie. It began as `pending ? "Pay Online" :
   * "Paid"`, sound while submission led straight to `pending_payment`. The
   * September flow put `for_approval` in that gap — BPLO reads the form BEFORE
   * there is a bill — so the else-branch printed a green "Paid" on filings
   * never charged a peso. A grey "Not billed yet" was added for the near side,
   * which was true and nearly useless: `draft`, `for_approval` and `returned`
   * all landed on it.
   *
   * What it says now is the filing's own STATUS, in that status's own colour.
   * Three consequences, all deliberate:
   *
   *   • "Paid" is gone. It printed on `awaiting_other_permits`,
   *     `for_final_approval` AND `approved` — three stages, one label — for a
   *     fact the status already carries.
   *   • A terminal filing gets a badge where it got nothing. The old note gave
   *     a sound reason for the blank: status alone cannot say whether a
   *     rejected filing had paid, so PAYMENT wording there would be a guess.
   *     That does not reach the status itself.
   *   • `settled` and `ended` are gone with the block they drove.
   *     `isPaidStatus` is still exported and used elsewhere; it simply has no
   *     business deciding what a status badge says.
   */
  // `filingStatusMeta` and not `applicationStatusMeta`: `approved` reads
  // "Completed" as a status and "Approved" on a filing whose other permits
  // are still coming in, and this row is about a particular filing.
  const meta = filingStatusMeta(app)
  /*
   * Layout only. The colour — background, text AND border — comes from
   * TONE_CLASSES at each use, so `text-white` cannot live here: two
   * same-specificity Tailwind utilities are resolved by stylesheet order, not
   * by where they sit in this string, so the two would have fought
   * unpredictably rather than the later one winning.
   */
  const badgeCls =
    'flex w-32 shrink-0 items-center justify-center self-stretch px-3 text-center text-sm font-bold leading-tight'

  function toggle() {
    setOpen((o) => {
      const nextOpen = !o
      if (nextOpen) onExpand(app.id)
      return nextOpen
    })
  }

  /**
   * How the office behind one permit type has left this filing, if loaded.
   *
   * Keyed on the DEPARTMENT, not the permit type, because that is how the API
   * keys both halves: WorkflowService::routeToDepartments raises one assignment
   * per office owning a requested permit type, and scheduleInspections books
   * one visit per inspecting office. Every seeded permit type has its own
   * department, so the mapping is 1:1 today; if an LGU ever routed two
   * clearances to one office, both rows would report that office's single
   * verdict — which is the truth about how the filing is actually being worked.
   *
   * The office's visit is its HIGHEST-id row, and the result is read off that
   * row only — the two steps are in that order and swapping them is a real bug
   * this very screen was caught in.
   *
   * A failed visit is kept on the record rather than overwritten, so an office
   * that failed and has a re-inspection booked carries two rows here: the old
   * failure, and a newer scheduled visit with `result: null`. Filtering to rows
   * that have a result BEFORE taking the newest throws the scheduled row away
   * and hands back the failure — a verdict the office has already moved past,
   * reported as its standing while somebody is booked to come back. That is the
   * same superseded-verdict mistake InspectionResource's `can_reinspect` exists
   * to stop the officer's screen making.
   *
   * So: newest row wins outright, and a newest row that has not been conducted
   * yields `undefined` — "this office is still to visit", which is the truth.
   * Only a conducted visit with a recorded result reports one.
   */
  function officeProgressFor(code: string): OfficeProgress {
    const pt = permitTypesByCode.get(code)
    if (!pt || !detail) return { assignment: undefined, inspection: undefined }
    const deptCode = pt.department.code

    const current = detail.inspections
      .filter((i) => i.department?.code === deptCode)
      .reduce<Inspection | undefined>(
        (newest, i) => (!newest || i.id > newest.id ? i : newest),
        undefined,
      )

    return {
      assignment: detail.assignments.find((a) => a.department.code === deptCode)?.status,
      inspection: current?.conducted_at ? (current.result ?? undefined) : undefined,
    }
  }

  /*
   * The rows this accordion draws. A filing with no permit types still gets one
   * row — the Mayor's Permit is what every filing is ultimately for, and an
   * accordion that opens onto nothing reads as a broken control.
   */
  const rows =
    app.permit_types.length > 0
      ? app.permit_types
      : [{ code: '—', name: 'Business Permit', status: null, status_label: null }]

  /*
   * Counted off `rows` — the very permits listed below — rather than off
   * `otherPermitProgress`, which excludes the business permit and counts only
   * the required clearances. Both are correct about different things, and the
   * one this badge needs is the one the reader can check by looking: a card
   * showing six rows with one Approved must not say "0 of 5".
   */
  const approvedCount = rows.filter((pt) => pt.status === 'approved').length
  /**
   * What the badge says: how many of this filing's permits are approved.
   *
   * On EVERY card, open or closed, whatever the filing's status. Client,
   * 26 September 2026: *"i told you to change all of those to 'n of 6
   * approved'."*
   *
   * An earlier attempt showed the count only after payment and kept the
   * status word before it, reasoning that "0 of 6 approved" says nothing
   * about whether a filing is new, returned or rejected. That is true and it
   * was not the instruction; the statuses it was protecting are all still
   * legible on the card — a returned filing carries its note, a rejected one
   * its rejection panel — and the count is what the client wants read at a
   * glance down a list.
   */
  const permitCount = `${approvedCount} of ${rows.length} approved`

  /*
   * Has at least one office already been and gone?
   *
   * Asked of the DATA, not of the chip labels: a boolean derived from
   * `chip.label === 'Inspection Passed'` would be a second copy of permitChip's
   * decision, kept in step by nothing, and the first person to reword a label
   * would silently switch this off.
   *
   * It gates the sentence below, which exists because "Inspection Passed" is
   * the closest this screen has ever come to saying an applicant holds
   * something. It does not, and the row does not say it does — but the note
   * removes the last inch of room to read it that way, and it is only shown on
   * the filings where somebody could.
   *
   * The outer test was `app.status === 'for_inspection'`, a status that no
   * longer exists — so the note could never appear. It asks the permits now,
   * which is where inspection lives: a filing qualifies when it is still
   * gathering permits and at least one office has already conducted its visit.
   */
  const someOfficeFinished =
    isGatheringOtherPermits(app) &&
    detail !== undefined &&
    rows.some((pt) => officeProgressFor(pt.code).inspection !== undefined)

  return (
    <li className="space-y-3">
      <div className="flex items-stretch overflow-hidden rounded-xl bg-white shadow-card">
        {/*
          Client, 26 September 2026: *"make even the business title box
          reactive too, just like the other boxes."* It has always been a
          button — the triangle says so — and it has never looked like one.

          The same tint and the same `cursor-pointer` the permit rows use, so
          the card and its rows answer a hover the same way. `focus-visible`
          rather than `focus`, so a keyboard user gets the ring and a mouse
          user does not get one on every click.
        */}
        <button
          type="button"
          onClick={toggle}
          aria-expanded={open}
          className="flex min-w-0 flex-1 cursor-pointer items-center gap-5 px-6 py-5 text-left transition-colors hover:bg-royal-tint/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-royal"
        >
          <Triangle open={open} />
          <span className="truncate text-lg font-bold text-ink">{businessName(app.business)}</span>
        </button>
        {/*
          ── The block is a STATUS badge now, in the status's own colour ──────

          It was a payment block with three labels — orange "Pay Online", green
          "Paid", and one flat grey "Not billed yet" that swallowed `draft`,
          `for_approval` and `returned` together. Two problems with that.

          It answered the wrong question. A form submitted an hour ago, with
          BPLO about to give it the first of its two approvals, described
          itself by the one thing that had not happened to it.

          And "Paid" printed on `awaiting_other_permits`, `for_final_approval`
          AND `approved` — three different places in the flow wearing one
          label, for a fact the status already carries: everything past
          `pending_payment` has been paid. Both labels were saying less than
          the status they replaced.

          So the colours come from `applicationStatusMeta().tone`, which is the
          same source the status guide above the list reads. The guide and the
          badges match because there is one definition of the colour, not
          because two lists were kept in step.

          `pending_payment` stays a LINK, because it is the one status with an
          action attached, and keeps its label plus the action rather than
          replacing one with the other.
        */}
        {pending ? (
          <Link
            to={`/applications/${app.id}/pay`}
            className={`${badgeCls} ${TONE_CLASSES[meta.tone]} border-l hover:brightness-95`}
          >
            <span className="text-center leading-tight">
              {permitCount}
              {/*
                The action stays. This badge is a link to the payment page and
                the only route to it on this screen — the count replaced the
                LABEL above it, not this.
              */}
              <span className="mt-0.5 block text-[11px] font-bold uppercase tracking-wide underline underline-offset-2">
                Pay online
              </span>
            </span>
          </Link>
        ) : (
          /*
            ── Where the filing IS, not only what it has not been billed ──────
            *
            * This read "Not billed yet" for every pre-payment state, which was
            * true and nearly useless: `draft`, `for_approval` and `returned`
            * all landed on it, so a form the applicant had just submitted —
            * with BPLO about to give it the first of its two approvals —
            * announced itself by the one thing that had not happened.
            *
            * The filing's own status instead. "For Approval" says a
            * person is reading the form; "Returned" says it is back with the
            * applicant; "Draft" says it was never submitted. Each is the
            * answer to the question the row is actually asked.
            *
            * A terminal filing gets a badge now, where it got nothing. The
            * note on `ended` gave a good reason for the blank — status alone
            * cannot say whether a rejected filing had already paid, so any
            * PAYMENT wording there would have been a guess. That reasoning
            * does not reach the status itself: "Rejected" is not a guess, and
            * a red badge beside the rejection note is the clearest the row has
            * ever been about what happened.
            */
          /*
            ── Closed it states the status; open it counts the permits ──────

            Client, 26 September 2026: *"Why is the Mayor's Permit tagged
            Approved while Johnny's Fried Chicken was tagged Permit
            Released?"*

            They were never in conflict — one is the FILING's status and the
            other is one permit's — but nothing on screen said so. Two chips
            of the same shape, in the same permit-flavoured words, eight
            pixels apart, inviting exactly that question.

            The duplication only exists once the rows are showing, and that
            is precisely when the badge has nothing left to add: every permit
            below states its own status. So open, it becomes the one thing
            the rows cannot say at a glance — how many of them are done.

            Closed, the badge stays, because it is then the only thing
            telling the applicant how this filing is doing. Removing it
            outright would leave a list of business names.
          */
          <span className={`${badgeCls} bg-shell text-ink-secondary border-l`}>
            {permitCount}
          </span>
        )}
      </div>

      {rejected && <RejectionNote app={app} detail={detail} />}

      {/*
        A returned filing is waiting on the APPLICANT, and the card alone did
        not say so — it showed a status pill and a permit count, the same as a
        filing nobody is waiting on.

        CLOSED ONLY. Open, the Mayor's Permit row below carries the same
        message with the officer's actual words and the field they named, so
        this one becomes a worse duplicate stacked on top of a better one —
        which is what the client saw on 28 September 2026. The row cannot
        replace it outright, though: it is inside `open`, and a collapsed card
        would go back to showing nothing to act on.
      */}
      {app.status === 'returned' && !open && (
        <ReturnedNote
          app={app}
          onCorrect={() => {
            /*
              The banner shows on a COLLAPSED card, which has not fetched the
              filing yet — and the dialog is drawn from the detail payload.
              Asking for it on the press is what makes opening in place
              possible at all; the dialog appears when it lands.
            */
            onExpand(app.id)
            setCorrectingForm(true)
          }}
        />
      )}
      {/*
        And the same for the other five offices.

        CLOSED ONLY, for the reason above: open, each returned permit's own
        row carries the officer's actual words and the field they named, so
        this would be a worse duplicate stacked on a better one.

        Not while the FORM is returned. Both can be true at once — BPLO
        sends the form back while a clearance is already out — and two rose
        banners on one card is a card that has stopped saying anything. The
        form is the one that blocks everything else, so it speaks first.
      */}
      {app.status !== 'returned' && !open && returnedClearances.length > 0 && (
        <ReturnedClearanceNote
          app={app}
          returned={returnedClearances}
          onCorrect={setCorrecting}
        />
      )}
      {/*
        The correction dialog, on this page. The office named one document;
        it does not need a page of its own, which is what the client kept
        arriving at. The filing's pointer and checklist are fetched on the
        press — see ClearanceCorrections for why they cannot come off the
        list payload.
      */}
      {/*
        BPLO's return, on this page. `detail` is fetched lazily per card, so
        the press asks for it first — the dialog cannot be drawn from the
        list payload, which carries no assignments.
      */}
      {correctingForm && detail !== undefined && (
        <MainFormCorrections
          app={detail}
          onClose={() => setCorrectingForm(false)}
          onDone={onChanged}
        />
      )}
      {correcting !== null && (
        <ClearanceCorrections
          applicationId={app.id}
          code={correcting}
          onClose={() => setCorrecting(null)}
          /* The row behind the dialog still says Returned until it is re-read. */
          onDone={onChanged}
        />
      )}

      {open && (
        <>
          <ul className="space-y-2.5">
            {rows.map((pt) => {
              /*
               * The permit's own status carries the chip, from the list payload
               * — no waiting for the detail request and no inference from it.
               * The detail is consulted for one thing only: whether the office
               * has actually conducted the visit a `for_inspection` permit is
               * waiting on.
               */
              const chip = permitChip(
                app.status,
                pt.status,
                detail ? officeProgressFor(pt.code) : undefined,
                pt.code,
              )
              /*
               * A permit the applicant has not begun, on a filing where they
               * can. This is the answer to "where do I apply for them?", put on
               * the row that raises the question rather than on a page they have
               * to already know about — the clearance stage was reachable only
               * from a link further inside the application.
               */
              const canStart =
                pt.status === 'not_started' && isGatheringOtherPermits(app)

              /*
               * ── An office has asked for something, and the row says so ──────
               *
               * The client's instruction of 17 September 2026 was that a
               * returned permit is legible from Track: the reason in the
               * office's own words, and one action that goes and fixes it.
               * Without this the row said "Returned" and nothing else, and the
               * applicant had to guess which of six permits, then find the
               * clearance page, then open the sheet.
               *
               * `remarks` and not a guess at it: null here means EITHER nothing
               * was written OR the reader may not see it
               * (`ApplicationVisibility::readsOfficeSheet` — see the note on
               * ApplicationPermitType). The applicant always may see their own,
               * so on this screen null means nothing was written, and the row
               * falls back to naming the office rather than inventing a reason.
               */
              /*
               * A main-form return sets the APPLICATION to returned and
               * leaves every pivot alone, so this row's Returned chip comes
               * from `permitChip`'s BUSINESS branch while its note and link
               * were looking at the pivot. Same condition as that branch,
               * written out rather than read back off the rendered chip.
               */
              const mainFormReturned = pt.code === 'BUSINESS' && app.status === 'returned'
              const returned = pt.status === 'returned' || mainFormReturned
              /*
               * The list payload's permit_types is the narrow shape — code,
               * name, status, status_label — so the note and the date come from
               * the DETAIL, which this row has already fetched to answer the
               * inspection question above it. Widening the list resource would
               * put an office's prose on every row of every filing in the
               * register to render a panel that only opens on one.
               */
              const full = detail?.permit_types.find((row) => row.code === pt.code)
              const note = mainFormReturned
                ? (detail?.assignments?.find((a) => a.remarks)?.remarks?.trim() ?? '')
                : (full?.remarks?.trim() ?? '')
              const historyKey = `${app.id}:${pt.code}`
              const historyOpen = openHistory.has(historyKey)

              return (
                <li
                  key={pt.code}
                  /*
                    `relative` for the covering button; `transition-colors`
                    so the tint arrives rather than snaps. The hover is the
                    royal tint at half strength — enough to read as a
                    response, not enough to compete with the status chip,
                    which is the one coloured thing on the row that MEANS
                    something.
                  */
                  /*
                    No rose bar on a returned row, since 30 September 2026.
                    The badge says Returned in rose and the note underneath
                    says who asked and for what; the bar was the same fact a
                    third time, and the client had the two rows side by side
                    and asked for one shape.
                  */
                  className="relative rounded-lg bg-white px-4 py-2.5 shadow-card transition-colors hover:bg-royal-tint/50"
                >
                  {/*
                    The row's own control, under the content and over the
                    background. Its accessible name says what it does and
                    which permit it does it to — "History" alone, repeated six
                    times down a list, names nothing.
                  */}
                  <button
                    type="button"
                    onClick={() => toggleHistory(historyKey)}
                    aria-expanded={historyOpen}
                    aria-controls={`history-${app.id}-${pt.code}`}
                    className="absolute inset-0 z-0 cursor-pointer rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
                  >
                    <span className="sr-only">
                      {historyOpen ? 'Hide' : 'Show'} history for {pt.name}
                    </span>
                  </button>
                  {/*
                    `pointer-events-none` so a click on the chip or the name
                    reaches the button beneath; the two links below put it
                    back for themselves.
                  */}
                  <div className="pointer-events-none relative z-10 flex items-center gap-4">
                    {/*
                    The same badge shape the filing's own status wears, two rows
                    up — `TONE_CLASSES` and nothing of its own. `StatusChip`
                    drew a solid block from a separate palette, which is what
                    made one status two colours on one screen.

                    `w-24` is kept: the six rows read as a column only if their
                    badges are one width, and the longest label here
                    ("Waiting for your other permits") wraps to three lines
                    either way.
                  */}
                    <span
                      className={`flex w-24 shrink-0 items-center justify-center rounded-md border px-2 py-1.5 text-center text-[11px] font-bold leading-tight ${TONE_CLASSES[chip.tone]}`}
                    >
                      {chip.label}
                    </span>
                    <span className="min-w-0 flex-1 truncate text-sm font-medium text-ink">
                      {pt.name}
                    </span>
                    {/*
                      ── A button, now that the row behind it is one too ──────

                      Client, 26 September 2026: *"Create a reactive box too for
                      'Apply or upload a copy' to make the button
                      distinguishable."* Right — an underlined link sitting
                      inside a box that is itself clickable is two controls
                      wearing one appearance, and the applicant cannot tell
                      which one a click will hit.

                      So it gets a border and a fill of its own: the row tints
                      on hover, this fills, and the two no longer read as the
                      same surface. It is the only thing on the row that leaves
                      the page, which is worth looking different.
                    */}
                    {canStart && (
                      <Link
                        to={`/applications/${app.id}/clearances`}
                        className="pointer-events-auto relative z-20 shrink-0 rounded-md border border-royal/40 bg-white px-3 py-1.5 text-xs font-semibold text-royal transition-colors hover:border-royal hover:bg-royal hover:text-white"
                      >
                        {/* "Apply or upload a copy" until 4 October 2026, when held copies went. */}
                        Apply
                      </Link>
                    )}
                    {/*
                      ── "Filed: 24 September" was here ───────────────────────

                      Client, same day: *"remove the 'Filed' beside Mayor's
                      Permit because it is just redundant with the Application
                      submitted."* It is — the history that opens under this row
                      now leads with "Application submitted" and the same date,
                      with the time as well.

                      Worth recording what it was for, because the reasoning
                      still holds and only the placement changed: it was drawn
                      ONLY for a permit that had actually been started, after a
                      spell when every row printed it and five permits nobody
                      had touched each claimed to have been submitted on the day
                      the main form was.
                    */}
                    {/*
                      The affordance, now that the whole row is the control:
                      a chevron that turns when it opens. `aria-hidden` —
                      the button covering the row already announces the
                      state, and a second announcement of the same fact is
                      noise.
                    */}
                    <span
                      aria-hidden="true"
                      className={`shrink-0 text-ink-muted transition-transform ${
                        historyOpen ? 'rotate-90' : ''
                      }`}
                    >
                      <ChevronRightIcon />
                    </span>
                    <Link
                      to={`/applications/${app.id}`}
                      className="pointer-events-auto relative z-20 shrink-0 text-ink-secondary transition-colors hover:text-royal"
                      aria-label={`View ${pt.name} status`}
                    >
                      <MessageIcon />
                    </Link>
                  </div>

                  {historyOpen && (
                    <div id={`history-${app.id}-${pt.code}`}>
                      <PermitHistory
                        history={full?.history ?? []}
                        submittedAt={app.submitted_at}
                      />
                    </div>
                  )}

                  {returned && (
                    /*
                     * Indented to the badge's right edge (`w-24` + `gap-4`), so
                     * the note reads as belonging to the permit above it rather
                     * than to the row below.
                     */
                    /*
                      `relative z-20` is what makes the link inside CLICKABLE.

                      The whole row is a stretched link: a covering button at
                      `absolute inset-0 z-0`. A POSITIONED element paints above
                      non-positioned siblings whatever the DOM order, so this
                      block sat underneath the button and every click on "Fix
                      and resubmit" opened or closed the row instead. Reported
                      on 28 September 2026 — and it had been true of the
                      clearance return since that block was written.
                    */
                    <div className="relative z-20 mt-2 pl-[7rem]">
                      <p className="text-xs leading-relaxed text-ink-secondary">
                        <span className="font-semibold text-ink">
                          {/*
                            No date, since 30 September 2026.

                            It was printed only when `returned_at` was on the
                            payload — true of a clearance row, not of the main
                            form's — so one line of code said "asked for changes
                            17 minutes ago" on one row and "asked for changes" on
                            the next, decided by which endpoint happened to carry
                            a column rather than by anything the applicant did.
                            The client put the two side by side and asked for one
                            shape, following BPLO's.

                            Nothing is lost that was worth keeping here: the line
                            is about WHO asked and WHAT for, and the history
                            behind the row carries when.
                          */}
                          {mainFormReturned ? 'BPLO' : 'This office'} asked for changes:
                        </span>{' '}
                        {/*
                        The officer's own words, in quotes so it is plainly a
                        person speaking and not the system's wording. No
                        fallback prose invented when there is none — the
                        sentence above already says what happened.

                        Trimmed into a local for the same reason as the date:
                        `full?.remarks.trim() !== ''` short-circuits to
                        undefined and passes, so an absent detail rendered a
                        pair of empty quotation marks.
                      */}
                        {note !== '' && <span className="italic">“{note}”</span>}
                      </p>
                      {/*
                        A returned CLEARANCE is fixed on that office's own
                        sheet; a returned FORM is fixed on the status page,
                        where the correction boxes are. One link, two
                        destinations, because they are two different repairs.

                        The clearance half pointed at the card GRID until 30
                        September 2026, which is a menu rather than a repair:
                        the client pressed it expecting the form they had been
                        asked to correct and got six cards. `pt.code` names the
                        permit this row is about, so two offices returning the
                        same filing give two rows pointing at two sheets.
                      */}
                      {/*
                        A returned CLEARANCE opens the dialog here; a
                        returned FORM still goes to the status page, where
                        its own correction boxes are. Two repairs, two
                        destinations — and only one of them needs a page.
                      */}
                      {!mainFormReturned && (
                        <button
                          type="button"
                          onClick={() => setCorrecting(pt.code)}
                          aria-label={`Fix and resubmit ${pt.name} for ${businessName(app.business)}`}
                          className="mt-1.5 inline-flex cursor-pointer items-center gap-1 rounded-md border border-royal/30 bg-white px-2.5 py-1.5 text-xs font-semibold text-royal transition-colors hover:border-royal hover:bg-royal-tint focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
                        >
                          Fix and resubmit →
                        </button>
                      )}
                      {mainFormReturned && (
                      <Link
                        to={`/applications/${app.id}`}
                        /*
                          A real control, not underlined text: the client asked
                          for the same reactive treatment the permit boxes got —
                          hand cursor, and a tint that arrives on hover so the
                          press is acknowledged before it lands.
                        */
                        className="mt-1.5 inline-flex cursor-pointer items-center gap-1 rounded-md border border-royal/30 bg-white px-2.5 py-1.5 text-xs font-semibold text-royal transition-colors hover:border-royal hover:bg-royal-tint focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
                      >
                        Fix and resubmit →
                      </Link>
                      )}
                    </div>
                  )}
                </li>
              )
            })}
          </ul>
          {someOfficeFinished && (
            <p className="rounded-lg border border-line bg-white px-4 py-2.5 text-xs text-ink-secondary">
              An office passing its inspection does not issue the permit. Every office on this
              application has to pass before any permit is issued, so keep preparing for the visits
              still marked <span className="font-semibold">For Inspection</span>.
            </p>
          )}
        </>
      )}
    </li>
  )
}

/**
 * One permit's status history, and the filing's submission above it.
 *
 * ── Why it is a row that opens, and not a page or a dialog ───────────────────
 *
 * Client, 26 September 2026: *"put a tracking history PER PERMIT ... ensure
 * proper user interface and don't overload them with texts. Just be
 * straightforward."*
 *
 * The permits are already a list of rows, each carrying the status this history
 * explains. Opening in place keeps the two together: the answer to "why does
 * this say Returned" appears directly under the word Returned. A dialog would
 * cover the other five, and a page would lose the comparison that makes the
 * list worth having — which of my six permits is behind.
 *
 * It costs nothing closed, which is what lets all six rows carry one.
 *
 * ── What is on it ────────────────────────────────────────────────────────────
 *
 * The status and when it changed. Not who changed it, not the office's note,
 * not the previous status — all three are available and all three were left
 * out. An applicant reading six of these wants the shape of the delay, and the
 * office's own words already have a home: the returned-permit panel on this
 * same row prints them, in full, when there are any.
 *
 * ── Why submission leads ─────────────────────────────────────────────────────
 *
 * *"Also put there when its application was submitted."* It is the filing's
 * event rather than the permit's, so it is drawn from `submitted_at` and marked
 * as the start of the line rather than stored as a seventh history row against
 * every permit. A permit nobody has touched then still has a timeline with one
 * true entry on it, which reads better than "no history" on a filing the
 * applicant submitted weeks ago.
 */
function PermitHistory({
  history,
  submittedAt,
}: {
  history: PermitStatusChange[]
  submittedAt: string | null
}) {
  const entries = [
    ...(submittedAt ? [{ key: 'filed', label: 'Application submitted', at: submittedAt }] : []),
    ...history.map((h) => ({
      key: `${h.to_status}-${h.created_at}`,
      label: clearanceStatusMeta(h.to_status).label,
      at: h.created_at,
    })),
  ]

  if (entries.length === 0) {
    return (
      <p className="mt-2 pl-[7rem] text-xs text-ink-muted">
        Nothing has happened on this permit yet.
      </p>
    )
  }

  return (
    <ol className="mt-2 space-y-1.5 pl-[7rem]">
      {entries.map((e, i) => (
        <li key={e.key} className="flex items-baseline gap-3 text-xs">
          {/*
            The dot marks the latest entry, so the eye lands on where the permit
            IS before reading how it got there. `aria-hidden` because "filled
            circle" is not information — the entry is last in the list either
            way, and a screen reader reads it last.
          */}
          <span
            aria-hidden="true"
            className={`mt-1 h-1.5 w-1.5 shrink-0 rounded-full ${
              i === entries.length - 1 ? 'bg-royal' : 'bg-line-strong'
            }`}
          />
          {/*
            A column, not a spread. `flex-1` here pinned the date to the far
            edge of the card and left the middle empty — see the note above
            the component.
          */}
          <span className="min-w-[11rem] shrink-0 font-medium text-ink">{e.label}</span>
          {/* `tnum` so the column of dates lines up digit for digit. */}
          <span className="tnum text-ink-muted">{formatDateTime(e.at)}</span>
        </li>
      ))}
    </ol>
  )
}

export function ApplicationsPage() {
  const [type, setType] = useState<TypeFilter>('')
  const [search, setSearch] = useState('')
  const [sort, setSort] = useState<SortKey>('newest')
  const [status, setStatus] = useState('')
  const { data, loading, error, reload } = useAsync(() => applications.list(), [])
  // Reference permit types carry `department` + `requires_inspection`, which we
  // need to map each permit type to its issuing department's assignment.
  const permitTypesRef = useAsync(() => reference.permitTypes(), [])
  const permitTypesByCode = new Map<string, PermitType>(
    (permitTypesRef.data ?? []).map((pt) => [pt.code, pt]),
  )
  // Lazily-loaded full application detail per expanded row (cached).
  const [detailCache, setDetailCache] = useState<DetailCache>({})
  /*
   * Ids already asked for, whether or not the answer is back yet.
   * `detailCache` alone cannot guard this: it is captured per render, so two
   * calls in the same tick — which is exactly what the rejected-row effect
   * below does — both see an empty cache and both fetch.
   */
  const [requested] = useState(() => new Set<number>())

  /*
   * Re-read one filing after the applicant has changed it.
   *
   * `loadDetail` fetches once and remembers, which is right for expanding a
   * row and wrong after a resubmission: the row behind the dialog still says
   * Returned until both the list and the detail are read again.
   */
  function refreshFiling(id: number) {
    requested.delete(id)
    loadDetail(id)
    void reload()
  }

  function loadDetail(id: number) {
    if (requested.has(id)) return
    requested.add(id)
    applications
      .get(id)
      .then((full) => setDetailCache((c) => ({ ...c, [id]: full })))
      .catch(() => {
        // Non-fatal: fall back to the coarse app-status chip. Cleared from
        // `requested` so expanding the row again retries.
        requested.delete(id)
      })
  }

  // Drafts have their own page; keep this list to submitted work still in play.
  const submitted = (data ?? []).filter((a) => a.status !== 'draft')
  const byType = (a: ApplicationListItem) => !type || a.application_type === type
  const inPlay = submitted.filter((a) => !isFinished(a)).filter(byType)
  const finishedCount = submitted.filter(isFinished).filter(byType).length

  /*
   * The rejection reason lives on the detail payload, not the list one, so the
   * rejected rows have to be fetched before they can explain themselves. Only
   * the rejected ones — this is the exception on a filing list, not the rule,
   * and eager-loading every row would put a request per row on every visit.
   */
  const rejectedIds = inPlay.filter((a) => a.status === 'rejected').map((a) => a.id)
  const rejectedKey = rejectedIds.join(',')
  // Keyed on the ids themselves rather than on the array, which is rebuilt
  // every render and would make this run every render.
  useEffect(() => {
    for (const id of rejectedIds) loadDetail(id)
  }, [rejectedKey])

  const needle = search.trim().toLowerCase()
  const items = inPlay
    .filter((a) => !status || a.status === status)
    .filter((a) => matchesSearch(a, needle))
    // Copied before sorting: `inPlay` is derived per render, but sorting the
    // array the filters returned is still a mutation of a value other code on
    // this render reads (`finishedCount` counts a different array, but the
    // habit is what keeps that true).
    .slice()
    .sort((a, b) => {
      if (sort === 'oldest') return filedAt(a) - filedAt(b)
      if (sort === 'deadline') {
        // A filing with no deadline is not "due first" — nulls go last, in
        // their own newest-first order, rather than heading the list at epoch 0.
        const da = a.deadline_at ? new Date(a.deadline_at).getTime() : Infinity
        const db = b.deadline_at ? new Date(b.deadline_at).getTime() : Infinity
        if (da !== db) return da - db
        return filedAt(b) - filedAt(a)
      }
      return filedAt(b) - filedAt(a)
    })

  /** True when the empty list is the doing of a control, not of an empty account. */
  const narrowed = Boolean(needle || status || type)

  function clearSearchAndFilters() {
    setSearch('')
    setStatus('')
    setType('')
  }

  /** Pointer to where an approved filing went, so it is never simply gone. */
  const movedNote = finishedCount > 0 && (
    <p className="mt-6 text-sm text-ink-secondary">
      {finishedCount === 1
        ? '1 completed application is now in your '
        : `${finishedCount} completed applications are now in your `}
      {/* The link already said "Profile" while pointing at /permits, back when
          that was a second screen wearing the same title. It goes straight
          there now rather than through the redirect. */}
      <Link
        to="/profile"
        className="font-semibold text-royal underline underline-offset-2 hover:no-underline"
      >
        Profile
      </Link>
      , with the permits they produced.
    </p>
  )

  return (
    <div>
      <PageTitle
        right={
          <span className="flex flex-wrap items-center gap-x-4 gap-y-2 pb-1">
            {/*
             * Labelled, not just placeheld: a placeholder disappears the moment
             * the field is used and is not an accessible name, so the field
             * would be announced as an unnamed edit box.
             */}
            <label htmlFor="track-search" className="sr-only">
              Search your applications by tracking ID, business name, or title
            </label>
            <input
              id="track-search"
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search tracking ID or business…"
              className="w-64 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
            <SortFilter
              sort={{ value: sort, options: SORTS, onChange: (v) => setSort(v as SortKey) }}
              filter={{ value: status, options: STATUS_FILTERS, onChange: setStatus }}
            />
          </span>
        }
      >
        Permit Tracking
      </PageTitle>

      {/*
        Under the title and above the filters: the guide explains the badges on
        the rows below, so it belongs between the page and the list rather than
        after it. Collapsed by default — see StatusGuide.
      */}
      <StatusGuide />

      <div className="mb-6">
        <FilterPills options={FILTERS} value={type} onChange={setType} />
      </div>

      {/*
       * The result count, announced.
       *
       * Without this a sighted reader watches the list shrink as they type and
       * a screen reader user hears nothing at all — the search would be a
       * control whose entire feedback is visual. `role="status"` is polite, so
       * it waits for a pause in typing rather than interrupting each keystroke.
       */}
      <p role="status" aria-live="polite" className="sr-only">
        {loading
          ? 'Loading applications'
          : `${items.length} ${items.length === 1 ? 'application' : 'applications'} shown${
              narrowed ? ' for the current search and filters' : ''
            }.`}
      </p>

      {loading ? (
        <SkeletonList rows={4} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : items.length === 0 ? (
        <>
          <EmptyState
            icon={TrackIcon}
            title={
              needle
                ? `Nothing matches “${search.trim()}”`
                : narrowed
                  ? 'Nothing matches these filters'
                  : finishedCount > 0
                    ? 'Nothing needs your attention'
                    : 'No applications yet'
            }
            description={
              needle
                ? 'Check the tracking ID, or search by the business name instead.'
                : narrowed
                  ? 'Try a different filter, or start a new application.'
                  : finishedCount > 0
                    ? 'Every application you have filed has been approved. The permits are in your Profile.'
                    : 'When you submit an application, it appears here with its live status and next step.'
            }
          />
          {/* A dead end needs a way out, not just an explanation of itself. */}
          {narrowed && (
            <div className="mt-4 text-center">
              <button
                type="button"
                onClick={clearSearchAndFilters}
                className="rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold text-royal transition-colors hover:bg-canvas"
              >
                Clear search and filters
              </button>
            </div>
          )}
          {movedNote}
        </>
      ) : (
        <>
          <ul className="space-y-4">
            {items.map((app) => (
              <ApplicationRow
                key={app.id}
                app={app}
                permitTypesByCode={permitTypesByCode}
                detail={detailCache[app.id]}
                onExpand={loadDetail}
                onChanged={() => refreshFiling(app.id)}
              />
            ))}
          </ul>
          {movedNote}
        </>
      )}
    </div>
  )
}
