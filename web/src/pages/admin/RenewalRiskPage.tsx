import { useState } from 'react'
import type { ReactNode } from 'react'
import { ErrorState, Skeleton, SkeletonCards } from '../../components/ui/primitives'
import { Info, MetricDefinitions } from '../../components/ui/MetricInfo'
import { FilterMenu, PageTitle, ProtoCard } from '../../components/ui/Proto'
import { RISK_ARC, RiskScoreDial } from '../../components/charts/RiskScoreDial'
import { toApiError } from '../../lib/api'
import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { RenewalRiskReport, RenewalRiskRow, RiskBand } from '../../lib/types'
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
 * How many rows the table asks the server for.
 *
 * The endpoint ranks by index and returns the leading rows, and on this
 * register the leading 25 are all High — every badge red, every action
 * "Immediate follow-up", and the Send Reminder button (which follows Moderate)
 * never drawn. The traffic-light scale the spec asks for only exists on screen
 * if the officer can reach past the top of the ranking, so the existing `limit`
 * parameter is exposed rather than hard-coded. 200 is the server's own ceiling;
 * asking for more returns 200.
 */
const ROW_OPTIONS = [
  { value: '25', label: 'Top 25' },
  { value: '50', label: 'Top 50' },
  { value: '100', label: 'Top 100' },
  { value: '200', label: 'Top 200' },
]

const DEFAULT_ROWS = '25'

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
 * Why "Send reminder" is a button that cannot yet send.
 *
 * The spec asks for this recommendation to be pressable so BPLO can notify a
 * business immediately. Nothing in api/routes raises a reminder on demand —
 * they are written by the scheduled `biztrack:scan-permits` command — so a
 * button that reported "reminder sent" would be reporting a send that never
 * happened, on the one screen whose whole point is that its figures can be
 * trusted. The control is drawn where the spec puts it and says so instead.
 *
 * `aria-disabled`, never `disabled` (DESIGN.md): a disabled button drops out of
 * the tab order, so a keyboard user could not reach it to discover why it is
 * unavailable. And the reason is not hidden behind pressing it — a control that
 * announces itself unavailable cannot also require a press to explain itself —
 * so every button points at one standing note through `aria-describedby`.
 */
const REMINDER_NOTE_ID = 'renewal-risk-reminder-note'

function ReviewTable({ rows }: { rows: RenewalRiskRow[] }) {
  return (
    <ProtoCard className="overflow-hidden">
      <div className="overflow-x-auto">
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
            <tr className="border-b border-line text-[11px] uppercase tracking-wide text-ink-muted">
              <th scope="col" aria-label="Business" className="px-4 py-2 font-semibold">
                Business
                <Info metric="at_risk.drivers" />
              </th>
              <th scope="col" aria-label="Barangay" className="px-4 py-2 font-semibold">
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
              <th scope="col" aria-label="Risk index" className="px-4 py-2 text-center font-semibold">
                Index <span className="font-normal text-ink-muted">/ 100</span>
                <Info metric="at_risk.score" />
              </th>
              <th scope="col" className="px-4 py-2 font-semibold">
                Level
              </th>
              <th scope="col" aria-label="Expires" className="px-4 py-2 font-semibold">
                Expires
                <Info metric="at_risk.days_to_expiry" />
              </th>
              <th scope="col" className="px-4 py-2 font-semibold">
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
                    {row.action === 'send_reminder' ? (
                      /*
                        The accessible name carries the business: there are up
                        to two hundred rows here, and a screen-reader user
                        tabbing through a column of buttons all called "Send
                        reminder" has no way to tell which business they are on.
                      */
                      <button
                        type="button"
                        aria-disabled="true"
                        aria-describedby={REMINDER_NOTE_ID}
                        aria-label={`Send renewal reminder to ${row.business}`}
                        className="whitespace-nowrap rounded-full border border-royal px-3 py-1 text-[11px] font-semibold text-royal opacity-70 focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
                      >
                        Send reminder
                      </button>
                    ) : (
                      <span className="inline-flex items-center whitespace-nowrap rounded-full border border-line bg-canvas px-2.5 py-0.5 text-[11px] font-semibold text-ink-secondary">
                        {row.action_label}
                      </span>
                    )}
                    {row.reminders_sent > 0 && (
                      <span className="mt-1 block text-[11px] text-ink-muted">
                        {row.reminders_sent} sent
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
  const [downloading, setDownloading] = useState(false)
  const [downloadError, setDownloadError] = useState<string | null>(null)

  // Resolves to { data, meta } — see AnalyticsProvenance and ComputedAt.
  const {
    data: result,
    loading,
    error,
    reload,
  } = useAsync(() => analytics.renewalRisk(Number(days), Number(rows)), [days, rows])

  const data = result?.data
  const meta = result?.meta

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
            <FilterMenu
              label="Filter renewal risk"
              fields={[
                { label: 'Window', value: days, options: HORIZON_OPTIONS, onChange: setDays },
                { label: 'Rows shown', value: rows, options: ROW_OPTIONS, onChange: setRows },
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
            {/* Real sends from the expiry-notice ledger, not an estimate. */}
            <SummaryCard value={data.reminders_sent} label="Reminders sent" metric="reminders_sent" />
          </div>

          <section className="mt-5">
            <SectionHeading metric="at_risk">Businesses Requiring Review</SectionHeading>
            {data.at_risk.length > 0 ? (
              <ReviewTable rows={data.at_risk} />
            ) : (
              <ProtoCard className="px-4 py-5">
                <p className="text-sm text-ink-secondary">
                  No permit expires between {data.window_start} and {data.window_end}.
                </p>
              </ProtoCard>
            )}

            {/*
              Rendered only when the table actually holds a Send reminder
              button. Standing on a table of nothing but High-risk rows it would
              be an explanation of a control that is not on screen.
            */}
            {data.at_risk.some((row) => row.action === 'send_reminder') && (
              <p id={REMINDER_NOTE_ID} className="mt-2 text-[11px] text-ink-muted">
                Sending from this screen is not connected yet — reminders go out automatically 30,
                15, 7 and 1 day before a permit expires.
              </p>
            )}

            <p className="tnum mt-2 text-[11px] text-ink-muted">
              {data.scored_permits.toLocaleString()} permits scored
              <Info metric="scored_permits" />
              {data.at_risk.length < data.scored_permits &&
                ` · ${data.at_risk.length} highest listed`}{' '}
              · {data.window_start} to {data.window_end}
            </p>
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
