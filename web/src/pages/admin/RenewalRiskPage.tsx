import { useState } from 'react'
import type { ReactNode } from 'react'
import { ErrorState, Skeleton, SkeletonCards } from '../../components/ui/primitives'
import { Info, MetricDefinitions } from '../../components/ui/MetricInfo'
import { FilterMenu, PageTitle, ProtoCard } from '../../components/ui/Proto'
import { RISK_ARC, RiskScoreDial } from '../../components/charts/RiskScoreDial'
import { toApiError } from '../../lib/api'
import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { RenewalRiskReport, RenewalRiskRow, RiskAction, RiskBand } from '../../lib/types'
import { AnalyticsTabs } from './AnalyticsTabs'
import { ComputedAt } from './ComputedAt'

/*
 * Renewal Risk — the four elements the client's FINAL DRAFT asks for.
 *
 *   1. Renewal Risk Level        colour-coded badge per row, counts as cards up top
 *   2. Renewal Risk Index        circular badge, score at the centre, ring filled to it
 *   3. Permit Expiration Monitor colour-coded days-remaining badge, stepped 30/15/7/1
 *   4. Recommended Actions       an action tag per row; "Send Reminder" as a button
 *
 * ── A DELIBERATE DEVIATION, which must survive future edits ─────────────────
 *
 * The spec's prose calls the index a "predicted probability" and the mockup's
 * column header reads "PROB. DELAY RISK" against percentages — 88%, 81%, 74%.
 * The spec's own formula, in the same table, is additive:
 *
 *     Risk Score = w_expiry + w_progress + w_punctuality + w_findings + w_fees
 *
 * That is a transparent weighted score, not a fitted model. Nothing in the
 * register records whether a business eventually renewed late, so there is no
 * outcome anything could have been fitted against and no calibration to report.
 * So the column reads "Risk index" out of 100, the server's methodology
 * sentence sits under the title, and the weights are on the page where an
 * officer can check a ranking against the rule they disagree with.
 *
 * api/tests/Feature/AnalyticsDefinitionsTest.php fails the build if the server
 * definitions reach for probability / likelihood / prediction wording. This
 * screen holds the same line. Whoever reinstates the percentages is asserting
 * an inference the product does not perform. Open question D1 in
 * docs/questions-for-malabon.md is where that argument belongs, not here.
 *
 * ── The client's three notes on the analytics screens ───────────────────────
 *
 *   "Remove unnecessary, long explanations"  Every standing paragraph is gone.
 *     The rule descriptions and a row's reasons are now behind disclosures, so
 *     the words are one click away instead of occupying the screen. The one
 *     paragraph that stayed is the server's methodology sentence: it is the
 *     sentence that stops a ranked table of big numbers from reading as a
 *     forecast, and it cannot do that job collapsed.
 *   "the information hover thing is pretty good"  Kept, on every figure. The
 *     text inside comes from the server (AnalyticsDefinitions.php) and is not
 *     authored here; distilling it is a change on that side.
 *   "Remove large spaces"  One full-width table instead of a 1.6fr/1fr split
 *     that left a column of air beside a five-item list, cards at py-3.5
 *     instead of py-7, and section gaps at mt-5 instead of mt-7.
 *
 * ── The client's four notes on THIS screen ──────────────────────────────────
 *
 *   "Change the name to Renewal Risk Prediction"  Done at the h1, which is the
 *     paper's §2 name for the feature. It names the feature; it does not soften
 *     what the number claims. See the note on the title itself.
 *   "Add button to send reminder/immediate follow up (the business owner will
 *   be notified upon this in their notifications)"  The button used to be drawn
 *     and marked unavailable, because nothing raised a reminder on demand. It
 *     now posts to the API and puts a real notification in the owner's list.
 *     See the note above ActionCell for why "Monitor" is not one of them.
 *   "Add filter by barangay, risk level, and action" and "it should also
 *   display other levels of risk"  These are one change, and the second is the
 *     reason for the first: the endpoint returns the leading rows BY SCORE, so
 *     with 2,060 permits scoring Low and none of them in the top 25, the green
 *     badge the design asks for was UNREACHABLE. A larger page size never fixed
 *     it and could not — it loads more of the top of the same ranking. The
 *     filters are server-side and applied before the ranking is cut.
 *   "The table should have its own scroll down button, for it not to expand the
 *   whole page"  A capped, focusable scroll region with a sticky header, so the
 *     cards and the rule book stay on screen beside the rows they explain.
 */

const HORIZON_OPTIONS = [
  { value: '30', label: 'Next 30 days' },
  { value: '60', label: 'Next 60 days' },
  { value: '90', label: 'Next 90 days' },
  { value: '180', label: 'Next 6 months' },
  { value: '365', label: 'Next 12 months' },
]

const DEFAULT_HORIZON = '365'

/*
 * How many rows fit on one page of the table.
 *
 * This used to be the whole answer to "how do I see a low-risk business": the
 * endpoint ranks by index and returns the leading rows, so on this register the
 * leading 25 are all High and raising the ceiling to 200 was the only way to
 * reach anything else. It never worked. 2,060 permits score Low and the two
 * hundredth row is still High — a bigger slice off the top of the same ranking
 * does not reach the bottom of it, it just loads more of the top.
 *
 * The level and action filters are the actual fix (they select which band the
 * table lists, server-side, before the ranking is cut), so this went back to
 * being what it should always have been: a page size. The pager underneath
 * reaches the rest.
 */
const ROW_OPTIONS = [
  { value: '25', label: '25 rows' },
  { value: '50', label: '50 rows' },
  { value: '100', label: '100 rows' },
]

const DEFAULT_ROWS = '25'

/**
 * The sentinel the selects use for "no filter".
 *
 * A `<select>` option must carry a string value and an empty one reads as an
 * unset control rather than as a deliberate "all", so the word travels and the
 * server maps it back to null. It is also why a barangay called "all" would
 * break this — Malabon has none, and the server's own normaliser treats the
 * literal the same way, so the two ends agree.
 */
const ANY = 'all'

const LEVEL_OPTIONS = [
  { value: ANY, label: 'All levels' },
  { value: 'high', label: 'High risk' },
  { value: 'moderate', label: 'Moderate risk' },
  { value: 'low', label: 'Low risk' },
]

/*
 * The three recommended actions, as a filter.
 *
 * Each one is a function of the band and not a second judgement (the server
 * says so in RenewalRiskScoring), so this control reaches the same three sets
 * the level filter reaches. It exists anyway because the client asked for it
 * and because the two are different questions to an officer: "how bad is it"
 * and "what am I doing about it today" are asked by different people on
 * different mornings, and neither should have to translate into the other.
 */
const ACTION_OPTIONS = [
  { value: ANY, label: 'All actions' },
  { value: 'immediate_follow_up', label: 'Immediate follow-up' },
  { value: 'send_reminder', label: 'Send reminder' },
  { value: 'monitor', label: 'Monitor' },
]

/*
 * The risk-level badge, in the traffic-light scale the spec asks for.
 *
 * Never Color Alone (DESIGN.md): the word is in the badge, so the colour is
 * decoration on a label that already says "High". The ink tones are the
 * darkened pairs — #c11212 on its tint clears 4.9:1 and the green clears 5.1:1,
 * both AA at the 11px this renders at. The raw #22b573 would not.
 */
const LEVEL_BADGE: Record<RiskBand, string> = {
  high: 'border-s-red bg-s-red-tint text-s-red',
  moderate: 'border-s-yellow bg-s-yellow-tint text-s-yellow-ink',
  low: 'border-s-green bg-s-green-tint text-[#12724a]',
}

function LevelBadge({ band, label }: { band: RiskBand; label: string }) {
  return (
    <span
      className={`inline-flex items-center whitespace-nowrap rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${LEVEL_BADGE[band]}`}
    >
      {label} risk
    </span>
  )
}

/**
 * Permit Expiration Monitoring, as a badge that shifts green to red.
 *
 * The four steps are the monitoring marks the spec names — 30, 15, 7 and 1 day
 * — rather than an even gradient, so the colour changes on the same days the
 * reminder job fires and an officer sees the same boundaries the system acts
 * on. Lapsed is its own step: a permit whose cover ended is not "0 days left",
 * it is a business trading without one.
 *
 * The number is always in the text. The colour only says how hard to look.
 */
function expiryBadge(days: number): { className: string; text: string } {
  if (days < 0) {
    return {
      className: 'border-s-red bg-s-red-tint text-s-red',
      text: `Lapsed ${Math.abs(days)} ${Math.abs(days) === 1 ? 'day' : 'days'} ago`,
    }
  }
  if (days <= 7) {
    return {
      className: 'border-s-red bg-s-red-tint text-s-red',
      text: days === 0 ? 'Expires today' : `${days} ${days === 1 ? 'day' : 'days'} left`,
    }
  }
  if (days <= 15) {
    return { className: 'border-s-orange bg-s-orange-tint text-s-orange-ink', text: `${days} days left` }
  }
  if (days <= 30) {
    return { className: 'border-s-yellow bg-s-yellow-tint text-s-yellow-ink', text: `${days} days left` }
  }
  return { className: 'border-s-green bg-s-green-tint text-[#12724a]', text: `${days} days left` }
}

/** "2026-07-31" reads as "Jul 31" in the table, with the full date on hover. */
function expiryLabel(date: string): string {
  const [year, month, day] = date.split('-').map(Number)
  return new Date(year, month - 1, day).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}

/**
 * A section title, with the server's account of the panel beside it.
 *
 * The info button is a sibling of the h2 rather than a child. Nested, its label
 * folds into the heading's accessible name, so anyone navigating this page by
 * heading would hear "Businesses Requiring Review How Businesses at Risk is
 * measured" on every section.
 */
function SectionHeading({ children, metric }: { children: ReactNode; metric?: string }) {
  return (
    <div className="mb-2 flex items-center">
      <h2 className="text-lg text-ink">{children}</h2>
      {metric && <Info metric={metric} />}
    </div>
  )
}

/**
 * One risk-level summary card: the count of businesses at that level.
 *
 * `accent` is a bar, not a background wash. A card tinted red behind a figure
 * of 186 reads as "something has gone wrong with this number" rather than
 * "these 186 need chasing", and the label under it already carries the level.
 */
function SummaryCard({
  value,
  label,
  metric,
  accent,
}: {
  value: number
  label: string
  metric: string
  accent?: string
}) {
  return (
    <ProtoCard className="flex items-center gap-3 px-4 py-3.5">
      <span
        aria-hidden="true"
        className="h-10 w-1.5 shrink-0 rounded-full"
        style={{ backgroundColor: accent ?? '#3242ca' }}
      />
      <span className="min-w-0">
        <span className="tnum block text-[26px] font-bold leading-none text-ink">
          {value.toLocaleString()}
        </span>
        <span className="mt-1 block text-[12px] font-semibold text-ink-muted">
          {label}
          <Info metric={metric} />
        </span>
      </span>
    </ProtoCard>
  )
}

/**
 * The reasons a permit scored what it scored, revealed on demand.
 *
 * Requirement 2.4 in docs/r-integration-revisions.md: the flat table was
 * rejected, and each row must open to say why it is at risk. Standing text
 * would put five clauses under every business name and is exactly the prose the
 * client asked us to cut — so it is a disclosure, and the button's accessible
 * name carries the business, because twenty-five buttons all called "Why" are
 * indistinguishable to a screen-reader user.
 */
function DriverDisclosure({ row }: { row: RenewalRiskRow }) {
  const [open, setOpen] = useState(false)

  if (row.drivers.length === 0) {
    return <span className="block text-[11px] font-normal text-ink-muted">No risk signals on record</span>
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen((was) => !was)}
        aria-expanded={open}
        aria-label={`Why ${row.business} is listed — ${row.drivers.length} signal${row.drivers.length === 1 ? '' : 's'}`}
        className="mt-1 inline-flex items-center gap-1 rounded text-[11px] font-semibold text-royal hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
      >
        <span aria-hidden="true">{open ? '−' : '+'}</span>
        {row.drivers.length} signal{row.drivers.length === 1 ? '' : 's'}
      </button>

      {open && (
        <span className="mt-1.5 block space-y-1">
          {row.drivers.map((driver) => (
            <span key={driver.rule} className="block text-[11px] font-normal text-ink-secondary">
              <span className="tnum font-semibold text-ink">
                +{driver.points}
              </span>{' '}
              {driver.label} — {driver.detail}
            </span>
          ))}
        </span>
      )}
    </>
  )
}

/*
 * The Send reminder button, which now actually sends.
 *
 * It used to be drawn where the spec puts it and marked `aria-disabled` with a
 * note saying so, because nothing raised a reminder on demand — they were
 * written only by the scheduled `biztrack:scan-permits` command, and a button
 * reporting "reminder sent" for a send that never happened is the one failure
 * this screen cannot afford. `POST /analytics/renewal-risk/{permit}/remind`
 * closed that gap: it puts a real notification in the business owner's list
 * through the same NotificationService every other message in the product goes
 * through.
 *
 * Three things about it that look like detail and are not:
 *
 *  - **Only two of the three actions are buttons.** "Monitor" is a
 *    recommendation to the officer about their own attention, not a message to
 *    the applicant; there is nothing to say to a business whose permit is 200
 *    days off with nothing against it. The server refuses it too, so the
 *    absence is a rule rather than a hidden control.
 *  - **The accessible name carries the business AND the permit number.** A
 *    business commonly holds three permits with the same expiry, so a name
 *    built from the business alone would announce three identical buttons —
 *    which is the bug this file already fixed once for the "Why" disclosures.
 *  - **`aria-disabled`, never `disabled`** (DESIGN.md). A disabled control
 *    leaves the tab order, so a keyboard user mid-send would lose their place
 *    in a table of two hundred rows. The click guard is in the handler.
 */
const REMINDER_NOTE_ID = 'renewal-risk-reminder-note'

/** Where one row's button has got to. Absent means "not pressed this session". */
type SendState = 'sending' | { at: string | null; repeat: boolean } | { error: string }

function isSent(state: SendState | undefined): state is { at: string | null; repeat: boolean } {
  return typeof state === 'object' && state !== null && 'at' in state
}

/** "2026-08-06T09:12:00Z" reads as "6 Aug" beside a row. */
function sentLabel(iso: string | null): string {
  if (!iso) return 'today'
  return new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}

function ActionCell({
  row,
  state,
  onSend,
}: {
  row: RenewalRiskRow
  state: SendState | undefined
  onSend: (row: RenewalRiskRow) => void
}) {
  // Monitor is advice to the reader, not a message to the applicant.
  if (row.action === 'monitor') {
    return (
      <span className="inline-flex items-center whitespace-nowrap rounded-full border border-line bg-canvas px-2.5 py-0.5 text-[11px] font-semibold text-ink-secondary">
        {row.action_label}
      </span>
    )
  }

  const sending = state === 'sending'
  const sent = isSent(state)

  return (
    <>
      <button
        type="button"
        onClick={() => onSend(row)}
        aria-disabled={sending || sent}
        aria-describedby={REMINDER_NOTE_ID}
        /*
         * Business AND permit number: a business with a business, sanitary and
         * fire permit expiring together is three rows, and three buttons all
         * announcing "Send renewal reminder to Aling Nena's Store" cannot be
         * told apart by anyone navigating this table by control.
         */
        aria-label={
          sent
            ? `Reminder already sent to ${row.business} about permit ${row.permit_number}`
            : `${row.action_label} — notify ${row.business} about permit ${row.permit_number}`
        }
        className="whitespace-nowrap rounded-full border border-royal px-3 py-1 text-[11px] font-semibold text-royal hover:bg-royal-tint focus:outline-none focus-visible:ring-2 focus-visible:ring-royal aria-disabled:border-line aria-disabled:text-ink-muted aria-disabled:hover:bg-transparent"
      >
        {sending ? 'Sending…' : sent ? 'Reminder sent' : row.action_label}
      </button>

      {/*
        The row's own record of what happened, separate from the live region
        that announced it: an officer who scrolls back to this row five minutes
        later still needs to see that they have already rung this business.
      */}
      {sent && (
        <span className="mt-1 block text-[11px] font-semibold text-[#12724a]">
          {state.repeat ? `Already sent ${sentLabel(state.at)}` : 'Sent just now'}
        </span>
      )}
      {typeof state === 'object' && state !== null && 'error' in state && (
        <span className="mt-1 block text-[11px] font-semibold text-s-red">{state.error}</span>
      )}
    </>
  )
}

/**
 * How tall the table gets before it scrolls itself.
 *
 * The client's note was "the table should have its own scroll down button, for
 * it not to expand the whole page" — with a hundred rows on screen the summary
 * cards, the recommended actions and the rule book all left the viewport, so
 * reading a row meant losing the figures it was supposed to be read against.
 *
 * 34rem is a little over a dozen rows at this row height: enough that the
 * scroll bar is obviously a scroll bar rather than a rounding error, and short
 * enough that the panels below stay reachable without a long scroll back.
 */
const TABLE_MAX_HEIGHT = 'max-h-[34rem]'

/**
 * What makes a header cell stay put while the body scrolls under it.
 *
 * `bg-white` is not decoration — a transparent sticky header lets the rows
 * slide visibly through the column names. The bottom border is on the cell for
 * the same reason: a border on the `tr` scrolls away with the rest of the row
 * box and leaves the header floating.
 */
const STICKY_TH = 'sticky top-0 z-10 border-b border-line bg-white'

function ReviewTable({
  rows,
  sendState,
  onSend,
}: {
  rows: RenewalRiskRow[]
  sendState: Record<number, SendState>
  onSend: (row: RenewalRiskRow) => void
}) {
  return (
    <ProtoCard className="overflow-hidden">
      {/*
        The scroll container, and three rules it has to keep.

        1. `tabIndex={0}` and an accessible name. A scrollable box that is not
           focusable cannot be scrolled from the keyboard at all — the arrow
           keys act on the page behind it — so a keyboard-only officer would
           simply never reach row fourteen. Focusable, it needs a name and a
           role, or a screen reader announces an unlabelled group appearing out
           of nowhere.
        2. Both axes on one element. `overflow-y-auto` alone re-establishes a
           block formatting context that clips the x axis to `visible`, and the
           table is wider than a 390px phone — which is the note left on the
           growth screen after an `sr-only` table pushed the whole page
           sideways. `overflow-auto` keeps horizontal scrolling inside the card.
        3. The sticky header sits on the `th` cells rather than on `<thead>`.
           Sticky on a `thead` is unreliable across browsers where the table
           uses collapsed borders, and the header of a table you have scrolled
           thirty rows into is the difference between a column of numbers and a
           column of risk indices.
      */}
      <div
        role="region"
        tabIndex={0}
        aria-label={`Businesses requiring review, ${rows.length} row${rows.length === 1 ? '' : 's'}, scrollable`}
        className={`${TABLE_MAX_HEIGHT} overflow-auto focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-royal`}
      >
        <table className="w-full text-left">
          <thead>
            {/*
              aria-label pins each header's accessible name to the label alone.
              A column header takes its name from its contents, and that name is
              announced against every cell beneath it — so without this, every
              score in the column would read as "Risk index How Risk score is
              measured". The info button cannot move out of the cell the way it
              does for the section headings, so the name is stated instead.
            */}
            {/*
              The sticky offset, the white background and the bottom border all
              live on the `th` rather than on this `tr`. A `tr` cannot be made
              sticky in every browser, and a transparent header sliding over the
              rows beneath it is worse than no sticky header at all.
            */}
            <tr className="text-[11px] uppercase tracking-wide text-ink-muted">
              <th
                scope="col"
                aria-label="Business"
                className={`${STICKY_TH} px-4 py-2 font-semibold`}
              >
                Business
                <Info metric="at_risk.drivers" />
              </th>
              <th
                scope="col"
                aria-label="Barangay"
                className={`${STICKY_TH} px-4 py-2 font-semibold`}
              >
                Barangay
                <Info metric="at_risk.barangay" />
              </th>
              {/*
                Not "Prob. delay risk". A score out of 100 from a published rule
                set, with the level beside it so the number is never read alone.
              */}
              {/*
                "/ 100" is on the header, and it is load-bearing rather than
                decoration.

                The spec asks for the score at the centre of the ring and
                nothing else, so each dial prints a bare number — and a bare
                number between 0 and 100, in a column of them, reads as a
                percentage. It is not one. It is points out of 100 added across
                five rules, and a reader who takes 75 for 75% has been told the
                system estimates a three-in-four chance of a late renewal,
                which is precisely the claim this screen must not make.

                Stating the denominator once on the header carries it for the
                whole column without repeating it on every row — the dial stays
                exactly as the spec draws it, and the units are still on screen.
                The per-row aria-label says "out of 100" in full for anyone who
                never sees the header.
              */}
              <th
                scope="col"
                aria-label="Risk index"
                className={`${STICKY_TH} px-4 py-2 text-center font-semibold`}
              >
                Index <span className="font-normal text-ink-muted">/ 100</span>
                <Info metric="at_risk.score" />
              </th>
              <th scope="col" className={`${STICKY_TH} px-4 py-2 font-semibold`}>
                Level
              </th>
              <th
                scope="col"
                aria-label="Expires"
                className={`${STICKY_TH} px-4 py-2 font-semibold`}
              >
                Expires
                <Info metric="at_risk.days_to_expiry" />
              </th>
              <th scope="col" className={`${STICKY_TH} px-4 py-2 font-semibold`}>
                Action
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const expiry = expiryBadge(row.days_to_expiry)

              return (
                <tr key={row.permit_id} className="border-b border-line/60 align-top last:border-0">
                  <th scope="row" className="px-4 py-2.5 text-[13px] font-semibold text-ink">
                    {row.business}
                    {/*
                      A business commonly holds its business, sanitary and fire
                      permits with the same expiry date, so without the permit
                      type three rows read as one row repeated three times.
                    */}
                    <span className="mt-0.5 block text-[11px] font-normal text-ink-secondary">
                      {row.permit_type}
                    </span>
                    <DriverDisclosure row={row} />
                  </th>
                  <td className="px-4 py-2.5 text-[12px] text-ink-secondary">
                    {row.barangay ?? <span className="text-ink-muted">Not on record</span>}
                  </td>
                  <td className="px-4 py-2.5">
                    <span className="flex justify-center">
                      <RiskScoreDial score={row.score} band={row.band} bandLabel={row.band_label} />
                    </span>
                  </td>
                  <td className="px-4 py-2.5">
                    <LevelBadge band={row.band} label={row.band_label} />
                  </td>
                  <td className="px-4 py-2.5 text-[12px] text-ink-secondary">
                    <span
                      className={`inline-flex items-center whitespace-nowrap rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${expiry.className}`}
                    >
                      {expiry.text}
                    </span>
                    <span className="mt-1 block text-[11px] text-ink-muted" title={row.valid_until}>
                      {expiryLabel(row.valid_until)}
                    </span>
                  </td>
                  <td className="px-4 py-2.5">
                    <ActionCell row={row} state={sendState[row.permit_id]} onSend={onSend} />
                    {/*
                      Two counts, kept apart on purpose.

                      "3 sent" is the nightly scan's expiry notices — the same
                      figure the Reminders Sent card totals, whose published
                      definition names the 30/15/7/1-day buckets and the
                      renewal-due nudge. "Followed up 4 Aug" is an officer
                      pressing this button. Pooling them would make the card's
                      own explanation false while leaving it on screen, and
                      would tell a reader three warnings had gone out when two
                      had. Same row, two different conversations.
                    */}
                    {row.reminders_sent > 0 && (
                      <span className="mt-1 block text-[11px] text-ink-muted">
                        {row.reminders_sent} sent
                      </span>
                    )}
                    {row.manual_reminder_at && !isSent(sendState[row.permit_id]) && (
                      <span className="mt-1 block text-[11px] text-ink-muted">
                        Followed up {sentLabel(row.manual_reminder_at)}
                      </span>
                    )}
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

/**
 * How many permits fall to each action, with the cut-offs stated as arithmetic
 * rather than as a paragraph.
 */
function RecommendedActions({ report }: { report: RenewalRiskReport }) {
  const peak = Math.max(1, ...report.actions.map((action) => action.count))

  return (
    <ProtoCard className="px-4 py-4">
      <div className="space-y-2.5">
        {report.actions.map((action) => (
          <div key={action.action} className="flex items-center gap-3">
            {/* nowrap: "Immediate follow-up" breaks across two lines otherwise,
                which pushes the three bars out of alignment with each other. */}
            <p className="w-36 shrink-0 whitespace-nowrap text-[12px] font-bold text-ink">
              {action.label}
            </p>
            <div className="h-2 flex-1 overflow-hidden rounded-full bg-canvas">
              <div
                className="h-full rounded-full"
                style={{
                  width: `${Math.max(2, (action.count / peak) * 100)}%`,
                  backgroundColor: RISK_ARC[action.band],
                }}
              />
            </div>
            <p className="tnum w-12 shrink-0 text-right text-[12px] font-semibold text-ink">
              {action.count.toLocaleString()}
            </p>
          </div>
        ))}
      </div>
      <p className="tnum mt-3 border-t border-line pt-2.5 text-[11px] text-ink-muted">
        {report.thresholds.high}+ follow up · {report.thresholds.moderate}–
        {report.thresholds.high - 1} remind · under {report.thresholds.moderate} monitor
      </p>
    </ProtoCard>
  )
}

/**
 * The rule book, rendered from the server's own weights.
 *
 * This panel is the reason the index can be called an index honestly: an
 * officer who disagrees with a ranking can see which rule they disagree with.
 * The descriptions sit inside `<details>` because five of them standing open is
 * the block of prose the client asked us to remove — collapsed, the panel is a
 * weight table, which is the part that gets read.
 */
function Rulebook({ report }: { report: RenewalRiskReport }) {
  return (
    <ProtoCard className="px-4 py-4">
      <ul className="space-y-1.5">
        {report.rulebook.map((rule) => (
          <li key={rule.rule}>
            <details className="group">
              <summary className="flex cursor-pointer list-none items-baseline gap-3 rounded py-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-royal">
                <span className="tnum w-12 shrink-0 text-right text-[12px] font-semibold text-royal">
                  {rule.max} pts
                </span>
                <span className="text-[12px] font-bold text-ink group-open:text-royal">
                  {rule.label}
                </span>
                <span aria-hidden="true" className="text-[10px] text-ink-muted group-open:hidden">
                  +
                </span>
              </summary>
              <p className="mt-1 pl-[3.75rem] text-[11px] leading-relaxed text-ink-secondary">
                {rule.description}
              </p>
            </details>
          </li>
        ))}
      </ul>
    </ProtoCard>
  )
}

function LoadingState() {
  return (
    <div className="space-y-4">
      <SkeletonCards count={4} />
      <Skeleton className="h-96 w-full rounded-2xl" />
    </div>
  )
}

export function RenewalRiskPage() {
  const [days, setDays] = useState(DEFAULT_HORIZON)
  const [rows, setRows] = useState(DEFAULT_ROWS)
  const [barangay, setBarangay] = useState(ANY)
  const [band, setBand] = useState(ANY)
  const [action, setAction] = useState(ANY)
  const [offset, setOffset] = useState(0)
  const [downloading, setDownloading] = useState(false)
  const [downloadError, setDownloadError] = useState<string | null>(null)
  /*
   * Per-row send state, keyed on permit id rather than on row index. The rows
   * are re-fetched whenever a filter moves, so an index would follow whichever
   * business happened to land in that position next and mark the wrong one sent.
   */
  const [sendState, setSendState] = useState<Record<number, SendState>>({})
  const [lastSend, setLastSend] = useState<string | null>(null)

  const perPage = Number(rows)

  /*
   * Every one of these is a server round trip, and that is the point — see the
   * note on `analytics.renewalRisk`. `offset` is in the dependency list too, so
   * a page turn is a query rather than a slice of rows already in the browser,
   * which is what keeps "showing 51–75 of 2,060" a fact rather than a guess.
   */
  const {
    data: result,
    loading,
    error,
    reload,
  } = useAsync(
    () =>
      analytics.renewalRisk(Number(days), perPage, {
        barangay: barangay === ANY ? undefined : barangay,
        band: band === ANY ? undefined : (band as RiskBand),
        action: action === ANY ? undefined : (action as RiskAction),
        offset: offset === 0 ? undefined : offset,
      }),
    [days, perPage, barangay, band, action, offset],
  )

  const data = result?.data
  const meta = result?.meta

  /**
   * Change a filter and go back to the first page.
   *
   * Without the reset, narrowing from 2,060 low-risk rows to the eleven in one
   * barangay while sitting on page nine lands the reader on an empty table. The
   * server clamps that case to the last populated page rather than returning
   * nothing, but "you are now on page one of eleven results" is still the only
   * answer that matches what the reader just asked for.
   */
  function filter(set: (value: string) => void) {
    return (value: string) => {
      set(value)
      setOffset(0)
    }
  }

  /**
   * Send one follow-up, and refuse to send it twice.
   *
   * Two guards, and they are not redundant. This one stops a double-click
   * making two REQUESTS; the server's ledger row stops two requests becoming
   * two MESSAGES, and it is the one that holds across a reload, a second tab,
   * or a second officer. Neither is sufficient alone: without this the officer
   * sees two spinners for one send, and without the server's guard a refresh
   * between clicks would genuinely message the owner twice.
   */
  async function sendReminder(row: RenewalRiskRow) {
    if (sendState[row.permit_id] === 'sending' || isSent(sendState[row.permit_id])) return

    setSendState((was) => ({ ...was, [row.permit_id]: 'sending' }))
    try {
      const sent = await analytics.sendRenewalReminder(row.permit_id)
      setSendState((was) => ({
        ...was,
        [row.permit_id]: { at: sent.sent_at, repeat: sent.already_sent },
      }))
      // The live region below announces it. The message is the server's, not a
      // paraphrase: it is the only thing that knows whether anything was sent.
      setLastSend(`${row.business}: ${sent.message}`)
    } catch (err) {
      const message = toApiError(err).message
      setSendState((was) => ({ ...was, [row.permit_id]: { error: message } }))
      setLastSend(`${row.business}: ${message}`)
    }
  }

  async function generateReport() {
    if (downloading) return
    setDownloading(true)
    setDownloadError(null)
    try {
      await analytics.renewalRiskReport(Number(days))
    } catch (err) {
      setDownloadError(toApiError(err).message)
    } finally {
      setDownloading(false)
    }
  }

  return (
    <div>
      <PageTitle
        right={
          <span className="flex items-center gap-3 pb-1">
            {/*
              The barangay options come from the payload rather than from the
              reference table, so the menu only ever offers a barangay that has
              a permit in the window — an option that can only return an empty
              table is a broken control. The list is computed over the
              UNFILTERED window, so picking one does not collapse the menu to
              the one already picked.
            */}
            <FilterMenu
              label="Filter renewal risk"
              fields={[
                { label: 'Window', value: days, options: HORIZON_OPTIONS, onChange: filter(setDays) },
                {
                  label: 'Barangay',
                  value: barangay,
                  options: [
                    { value: ANY, label: 'All barangays' },
                    ...(data?.barangays ?? []).map((name) => ({ value: name, label: name })),
                  ],
                  onChange: filter(setBarangay),
                },
                { label: 'Risk level', value: band, options: LEVEL_OPTIONS, onChange: filter(setBand) },
                {
                  label: 'Recommended action',
                  value: action,
                  options: ACTION_OPTIONS,
                  onChange: filter(setAction),
                },
                { label: 'Rows per page', value: rows, options: ROW_OPTIONS, onChange: filter(setRows) },
              ]}
            />
            {/*
              aria-disabled, never `disabled` — a disabled button leaves the tab
              order, so a keyboard user mid-download loses their place on the
              page. The click guard is in generateReport().
            */}
            <button
              type="button"
              onClick={generateReport}
              aria-disabled={downloading}
              className="rounded-lg bg-royal px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:bg-royal-hover aria-disabled:opacity-60"
            >
              {downloading ? 'Generating…' : 'Generate Report'}
            </button>
          </span>
        }
      >
        {/*
          The paper's §2 heading, in full: "Renewal Risk Prediction". The screen
          said "Renewal Risk" while AnalyticsDatasets already sent the long form
          as this dataset's label, so the page and its own payload disagreed —
          the same drift the growth screen's name test exists to catch.

          "Prediction" names the FEATURE, and changes nothing about what the
          index claims. The number under it is still points out of 100 across
          five rules, still not a fitted probability, and the ban on
          probability/likelihood wording in AnalyticsDefinitions still holds. If
          this title ever starts being read as a promise that the score is a
          forecast, the fix is the title, not a softening of what sits beneath
          it.
        */}
        Renewal Risk Prediction
      </PageTitle>

      <AnalyticsTabs />

      {meta && <ComputedAt meta={meta} onRefreshed={reload} />}

      {downloadError && (
        <p className="mb-3 rounded-lg bg-s-red-tint px-4 py-2.5 text-sm font-medium text-s-red">
          {downloadError}
        </p>
      )}

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : data ? (
        <MetricDefinitions value={meta?.definitions}>
          {/*
            Verbatim from the server, never paraphrased. It is the sentence that
            stops three big numbers and a ranked table from reading as a
            forecast, so it sits above the numbers rather than in a footnote
            below them — and it stays open rather than behind a disclosure for
            the same reason.
          */}
          <p className="mb-4 text-[12px] leading-relaxed text-ink-secondary">
            {data.methodology}
            <Info metric="methodology" />
          </p>

          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <SummaryCard
              value={data.counts.high}
              label="High risk"
              metric="counts.high"
              accent={RISK_ARC.high}
            />
            <SummaryCard
              value={data.counts.moderate}
              label="Moderate risk"
              metric="counts.moderate"
              accent={RISK_ARC.moderate}
            />
            <SummaryCard
              value={data.counts.low}
              label="Low risk"
              metric="counts.low"
              accent={RISK_ARC.low}
            />
            {/*
              Real sends from the expiry-notice ledger, not an estimate — and
              the nightly scan's only. Officer follow-ups sent from the table
              below are deliberately not added in here: this card's own
              explanation says it counts the 30/15/7/1-day warnings and reads
              zero until the scan has run, and a figure that quietly included
              something else would make its explanation the lie.
            */}
            <SummaryCard value={data.reminders_sent} label="Reminders sent" metric="reminders_sent" />
          </div>

          <section className="mt-5">
            <SectionHeading metric="at_risk">Businesses Requiring Review</SectionHeading>

            {/*
              One live region for the whole table rather than a status message
              per row. Two hundred polite regions is two hundred things a screen
              reader has to hold open; one, updated with the server's own
              sentence and the business it concerns, says what happened once and
              in full. `polite` because a send is not an interruption — the
              officer is looking at the row they pressed.
            */}
            <p role="status" aria-live="polite" className="sr-only">
              {lastSend ?? ''}
            </p>

            {data.at_risk.length > 0 ? (
              <ReviewTable rows={data.at_risk} sendState={sendState} onSend={sendReminder} />
            ) : (
              <ProtoCard className="px-4 py-5">
                <p className="text-sm text-ink-secondary">
                  {/*
                    An empty table has two very different causes and the reader
                    has to be able to tell them apart. "Nothing expires in this
                    window" is a fact about the register; "no permit matches
                    this filter" is a fact about the control they just moved,
                    and only one of them is fixed by changing the filter back.
                  */}
                  {data.scored_permits === 0
                    ? `No permit expires between ${data.window_start} and ${data.window_end}.`
                    : 'No permit in this window matches the current filter.'}
                </p>
              </ProtoCard>
            )}

            {/*
              Rendered only when the table actually holds a button that sends.
              Standing over a table of nothing but Monitor rows it would explain
              a control that is not on screen.
            */}
            {data.at_risk.some((row) => row.action !== 'monitor') && (
              <p id={REMINDER_NOTE_ID} className="mt-2 text-[11px] text-ink-muted">
                Sending puts a notice in the business owner’s BizTrack notifications straight away.
                One per business per day — reminders also go out automatically 30, 15, 7 and 1 day
                before a permit expires.
              </p>
            )}

            {/*
              The status line, and the pager under it.

              `matching` is the row count for the CURRENT filter and
              `scored_permits` is what the three band counts are out of. Both
              are stated because they answer different questions — "how much of
              this list have I seen" and "how much of the register is this list"
              — and because a footer carrying only the first is how "25 of
              2,060" quietly becomes an officer's estimate of the whole city.
            */}
            <div className="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
              <p className="tnum text-[11px] text-ink-muted">
                {data.matching > 0
                  ? `Showing ${(data.offset + 1).toLocaleString()}–${(
                      data.offset + data.at_risk.length
                    ).toLocaleString()} of ${data.matching.toLocaleString()}`
                  : 'Showing none'}
                {' · '}
                {data.scored_permits.toLocaleString()} permits scored
                <Info metric="scored_permits" />
                {' · '}
                {data.window_start} to {data.window_end}
                {/*
                  Said once, where the filtered count is, because Generate
                  Report sits at the top of this page beside the filter and the
                  two do not agree: the PDF is the whole watchlist, worst first.
                  The report template states the window it covers but has no way
                  to state a filter, so an officer who narrows to one barangay
                  and downloads would otherwise get the city back with no sign
                  that anything had been ignored. Making the PDF follow the
                  filter needs a line in the template naming it — see the
                  report.
                */}
                {(data.filters.barangay || data.filters.band || data.filters.action) &&
                  ' · Generate Report covers the whole watchlist, not this filter'}
              </p>

              {data.matching > perPage && (
                <span className="flex items-center gap-1.5">
                  {/*
                    aria-disabled, never `disabled` (DESIGN.md): a disabled
                    control leaves the tab order, so a keyboard user on the last
                    page would find focus jumping past the pager entirely rather
                    than resting on a button that says it can go no further.
                    The guards are in the handlers.
                  */}
                  <button
                    type="button"
                    aria-label="Previous page of businesses"
                    aria-disabled={data.offset === 0 || loading}
                    onClick={() => setOffset(Math.max(0, data.offset - perPage))}
                    className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas focus:outline-none focus-visible:ring-2 focus-visible:ring-royal aria-disabled:opacity-40 aria-disabled:hover:bg-transparent"
                  >
                    <span aria-hidden="true">‹</span>
                  </button>
                  <span className="tnum text-[11px] text-ink-muted">
                    Page {(Math.floor(data.offset / perPage) + 1).toLocaleString()} of{' '}
                    {Math.max(1, Math.ceil(data.matching / perPage)).toLocaleString()}
                  </span>
                  <button
                    type="button"
                    aria-label="Next page of businesses"
                    aria-disabled={data.offset + perPage >= data.matching || loading}
                    onClick={() =>
                      setOffset((was) => (was + perPage >= data.matching ? was : was + perPage))
                    }
                    className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas focus:outline-none focus-visible:ring-2 focus-visible:ring-royal aria-disabled:opacity-40 aria-disabled:hover:bg-transparent"
                  >
                    <span aria-hidden="true">›</span>
                  </button>
                </span>
              )}
            </div>
          </section>

          <div className="mt-5 grid gap-4 *:min-w-0 lg:grid-cols-2">
            <section>
              <SectionHeading metric="actions">Recommended Actions</SectionHeading>
              <RecommendedActions report={data} />
            </section>

            <section>
              <SectionHeading metric="rulebook">What drives the index</SectionHeading>
              <Rulebook report={data} />
            </section>
          </div>
        </MetricDefinitions>
      ) : null}
    </div>
  )
}
