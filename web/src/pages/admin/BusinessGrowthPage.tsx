import { useState } from 'react'
import type { ReactNode } from 'react'
import { ErrorState, Skeleton, SkeletonCards } from '../../components/ui/primitives'
import { Info, MetricDefinitions } from '../../components/ui/MetricInfo'
import { FilterMenu, PageTitle, ProtoCard } from '../../components/ui/Proto'
import {
  GrowthBarangayBars,
  GrowthClosureTrend,
  GrowthIndustryTrend,
  GrowthRenewalCurve,
  GrowthStatusDonut,
} from '../../components/charts/GrowthCharts'
import { GROWTH_DOWN, GROWTH_FLAT, GROWTH_UP } from '../../components/charts/GrowthChartFrame'
import type { IndustryLenses } from '../../lib/types'
import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { AnalyticsTabs } from './AnalyticsTabs'
import { ComputedAt } from './ComputedAt'
import { GenerateReportButton } from './GenerateReportButton'

/*
 * Business Growth Analysis — docs/r-integration-spec.md §4, "(Admin - BPLO)".
 *
 * ── On the name ─────────────────────────────────────────────────────────────
 *
 * This screen used to be titled "Business Lifecycle Monitoring", and the
 * comment that sat here defended it: mockup 122 retitles the screen, the mockup
 * was newer than the paper, so the mockup won on naming. That reasoning is now
 * superseded on both counts. The R INTEGRATION DRAFTS spec is newer than the
 * mockup and heads §4 "Business Growth Analysis", and the client asked for the
 * rename directly — "Proper follow terms (e.g. 'Lifecycle' should be 'Business
 * Growth Analysis')". A direct instruction plus the newest spec beats an
 * inference drawn from file dates, so the spec's term is what ships.
 *
 * The same rename applies inside the screen: the cohort KPI is the spec's
 * "Business Renewal Performance", not "Cohort Survival Rate".
 *
 * KNOWN DISAGREEMENT, deliberately left alone here: the API still labels this
 * dataset "Business Lifecycle Monitoring" (App\Support\AnalyticsDatasets, and
 * the PDF template and the cohort_survival.survival definition with it). Those
 * are server-side strings and renaming them is a separate change against api/;
 * until that lands, the screen's title and its dataset label disagree, which is
 * the tradeoff the client's instruction buys.
 *
 * ── What is drawn, and why each chart is the chart it is ────────────────────
 *
 * The spec names a visualisation per report and the client asked that they be
 * followed exactly. Growth rate is a summary card with a direction indicator;
 * barangays are horizontal bars; the status split is a donut; renewal
 * performance and closures are lines; industry growth is one line per industry
 * with a colour-coded legend. The components live in components/charts and
 * every one of them also renders its numbers as a hidden table, because
 * recharts emits an SVG a screen reader cannot read.
 *
 * ── On the prose ────────────────────────────────────────────────────────────
 *
 * There used to be a footnote under every panel restating what the panel meant.
 * The client's note was blunt — the explanations are "way too long" — but also
 * said the info popovers are worth keeping. So the footnotes are gone and the
 * <Info> buttons stay: same explanations, server-authored, one click away
 * instead of permanently occupying the screen.
 *
 * The one paragraph that survives is the survival methodology, and it survives
 * because the server ships it attached to the number. A Kaplan-Meier estimate
 * that censors businesses still inside their current permit reads, to anyone
 * who has not been told, as a plain pass rate — far more certain than it is.
 * It sits directly under the curve it qualifies rather than in the popover.
 *
 * Everything is computed server-side (App\Support\BusinessGrowthAnalytics) and
 * rendered as given. Where a figure genuinely
 * cannot be derived — a growth rate against an empty prior period, renewal
 * performance for a cohort that has not reached its first renewal — the server
 * sends null and this page says so rather than printing a number nobody can
 * defend.
 */

const PERIOD_OPTIONS = [
  { value: '3', label: 'Last 3 months' },
  { value: '6', label: 'Last 6 months' },
  { value: '12', label: 'Last 12 months' },
  { value: '24', label: 'Last 24 months' },
  { value: '36', label: 'Last 36 months' },
]

function signed(value: number): string {
  return `${value >= 0 ? '+' : ''}${value}`
}

/** "2026-03" reads as "Mar 26" on the closure-trend axis. */
function monthLabel(month: string): string {
  const [year, m] = month.split('-').map(Number)
  return new Date(year, m - 1, 1).toLocaleDateString('en-PH', { month: 'short', year: '2-digit' })
}

/** "2025-08-03" reads as "Aug 2025" on the industry axis. */
function monthYear(date: string): string {
  const [year, m] = date.split('-').map(Number)
  return new Date(year, m - 1, 1).toLocaleDateString('en-PH', { month: 'short', year: 'numeric' })
}

/**
 * The spec's summary card: the percentage, with an upward or downward indicator.
 *
 * ── Why a decline is not drawn in the error red ─────────────────────────────
 *
 * DESIGN.md's Red Means Stop rule reserves #bd0000 for errors and destructive
 * actions, and a register that shrank is neither. It is a finding — arguably
 * the single most useful finding on the screen — and painting it the same
 * colour as a failed upload tells a BPLO officer that something has gone wrong
 * with the system rather than with the city's business count. So down is amber,
 * up is the darkened green DESIGN.md already sanctions, and both clear 4.5:1 on
 * white because the colour carries text and not just a swatch.
 *
 * The arrow is aria-hidden and the direction is spelled out in words beside it:
 * Never Color Alone, and an arrow glyph is no better than a colour to a screen
 * reader.
 */
function DirectionIndicator({ value }: { value: number }) {
  const rising = value > 0
  const flat = value === 0
  const color = flat ? GROWTH_FLAT : rising ? GROWTH_UP : GROWTH_DOWN

  return (
    <span
      className="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-bold"
      style={{ color, borderColor: color }}
    >
      <svg width="9" height="9" viewBox="0 0 10 10" aria-hidden="true">
        {flat ? (
          <rect x="0" y="4" width="10" height="2" fill={color} />
        ) : (
          <path d={rising ? 'M5 0 L10 9 H0 Z' : 'M5 10 L0 1 H10 Z'} fill={color} />
        )}
      </svg>
      {flat ? 'no change' : rising ? 'up' : 'down'}
    </span>
  )
}

function SummaryCard({
  value,
  label,
  hint,
  metric,
  muted,
  indicator,
}: {
  value: string
  label: string
  hint?: string
  metric?: string
  muted?: boolean
  indicator?: ReactNode
}) {
  return (
    <ProtoCard className="px-4 py-4">
      <p className="text-[12px] leading-snug text-ink-muted">
        {label}
        {metric && <Info metric={metric} />}
      </p>
      <p className="mt-1.5 flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <span
          className={`tnum font-bold leading-none ${
            muted ? 'text-[15px] text-ink-secondary' : 'text-[26px] text-royal'
          }`}
        >
          {value}
        </span>
        {indicator}
      </p>
      {hint && <p className="mt-1.5 text-[11px] leading-snug text-ink-muted">{hint}</p>}
    </ProtoCard>
  )
}

function SectionHeading({ children, metric }: { children: ReactNode; metric?: string }) {
  return (
    // The info button is a sibling of the h2, not a child of it. Nested, its
    // label folds into the heading's accessible name, so anyone navigating this
    // page by heading hears the button on every section.
    <div className="mb-2 flex items-center">
      <h2 className="text-[15px] font-bold text-ink">{children}</h2>
      {metric && <Info metric={metric} />}
    </div>
  )
}

/** Card, heading and info button in one, so the four panels stay identical. */
function Panel({
  title,
  metric,
  className = '',
  children,
}: {
  title: string
  metric?: string
  className?: string
  children: ReactNode
}) {
  return (
    // The card stretches to the row rather than sitting at its natural height.
    // Two panels side by side rarely hold the same amount — the renewal curve
    // carries a methodology paragraph the closure trend does not — and without
    // this the shorter one leaves a band of empty canvas under it, which is
    // exactly the "large space" the client asked us to take out.
    <section className={`flex flex-col ${className}`}>
      <SectionHeading metric={metric}>{title}</SectionHeading>
      <ProtoCard className="flex flex-1 flex-col justify-center px-4 py-3.5">{children}</ProtoCard>
    </section>
  )
}

function Unavailable({ children }: { children: ReactNode }) {
  return <p className="px-1 py-8 text-center text-[13px] text-ink-secondary">{children}</p>
}

function LoadingState() {
  return (
    <div className="space-y-4">
      <SkeletonCards count={4} />
      <div className="grid gap-4 lg:grid-cols-2">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-48 w-full rounded-2xl" />
        ))}
      </div>
    </div>
  )
}

export function BusinessGrowthPage() {
  const [months, setMonths] = useState('12')

  const {
    data: result,
    loading,
    error,
    reload,
  } = useAsync(() => analytics.businessGrowth(Number(months)), [months])

  const data = result?.data
  const meta = result?.meta

  const top = data?.top_barangays[0]
  const renewal = data?.cohort_survival
  const lastPoint = renewal?.points[renewal.points.length - 1]

  /*
   * The industry panel's three lenses, or the one ranking a payload from before
   * the splice can offer.
   *
   * `industry_lenses` is added to the response by AnalyticsController at serve
   * time and is not part of the stored dataset — the long argument is on that
   * controller method, but the short of it is that the lenses are three ways of
   * ordering `industry_growth` rather than a fourth measurement, so they are
   * assembled with the response and a snapshot from before they existed is
   * still a valid snapshot.
   *
   * The fallback is deliberately a ONE-lens object rather than three lenses with
   * two of them empty. `industry_growth` is exactly the Largest ranking, and
   * nothing in it can answer "what grew fastest" — offering the reader two
   * buttons that draw nothing would be worse than offering no choice at all, and
   * GrowthIndustryTrend hides the toggle when there is only one lens to pick.
   */
  const industryLenses: IndustryLenses = data?.industry_lenses ?? {
    slots: data?.industry_growth.length ?? 0,
    min_businesses: 0,
    lines_on_record: data?.industry_growth.length ?? 0,
    above_floor: data?.industry_growth.length ?? 0,
    lenses: [
      {
        key: 'largest',
        label: 'Largest',
        floored: false,
        qualifying: data?.industry_growth.length ?? 0,
        rows: data?.industry_growth ?? [],
      },
    ],
  }

  return (
    <div>
      <PageTitle
        right={
          <span className="flex items-center gap-3 pb-1">
            <FilterMenu
              label="Filter business growth analysis"
              fields={[
                { label: 'Period', value: months, options: PERIOD_OPTIONS, onChange: setMonths },
              ]}
            />
            <GenerateReportButton
              onGenerate={() => analytics.businessGrowthReport(Number(months))}
            />
          </span>
        }
      >
        Business Growth Analysis
      </PageTitle>

      <AnalyticsTabs />

      {meta && <ComputedAt meta={meta} onRefreshed={reload} />}

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : data && renewal ? (
        <MetricDefinitions value={meta?.definitions}>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <SummaryCard
              value={data.growth_rate === null ? 'No prior period' : `${signed(data.growth_rate)}%`}
              label="Business Growth Rate"
              metric="growth_rate"
              muted={data.growth_rate === null}
              indicator={
                data.growth_rate === null ? undefined : (
                  <DirectionIndicator value={data.growth_rate} />
                )
              }
              hint={
                data.growth_rate === null
                  ? 'nothing registered in the period before to compare against'
                  : `${data.registrations} new vs ${data.registrations_prior} before`
              }
            />
            <SummaryCard
              value={
                renewal.survival === null ? 'No renewal reached' : `${renewal.survival.toFixed(0)}%`
              }
              label="Business Renewal Performance"
              metric="cohort_survival.survival"
              muted={renewal.survival === null}
              hint={
                renewal.survival === null
                  ? 'no business has reached a first renewal yet'
                  : /*
                     * "renewal periods" and not "renewals" until now, on a
                     * screen whose selector is labelled "Last 12 months". Two
                     * things called a period, only one of them a period, and
                     * this figure is not filtered by the other one at all. The
                     * shorter word is also the accurate one.
                     */
                    `after ${renewal.max_cycle} ${renewal.max_cycle === 1 ? 'renewal' : 'renewals'}${lastPoint ? ` · ${lastPoint.at_risk} businesses got that far` : ''}`
              }
            />
            <SummaryCard
              value={data.closures.toLocaleString()}
              label="Closures (Period)"
              metric="closures"
              hint={`${data.period_start} to ${data.period_end}`}
            />
            {/*
              No `metric` here, and it is not an oversight. The Top Growing
              Barangays panel further down carries the same definition under the
              same name, so an info button here rendered a second control
              announcing "How Top Growing Barangays is measured" with identical
              contents — two stops for a screen-reader user, one explanation. The
              same reasoning already governs the approval rate and the pass rate
              on the dashboard: the button belongs beside the panel it explains.
            */}
            <SummaryCard
              value={top ? top.barangay : 'No data'}
              label="Top Growing Barangay"
              muted={!top}
              hint={top ? `${signed(top.delta)} new registrations vs last period` : undefined}
            />
          </div>

          <div className="mt-4 grid gap-x-4 gap-y-4 lg:grid-cols-2">
            <Panel title="Business Status Summary" metric="status_summary">
              {data.status_summary.length > 0 ? (
                <GrowthStatusDonut rows={data.status_summary} />
              ) : (
                <Unavailable>No business is on the register yet.</Unavailable>
              )}
            </Panel>

            <Panel title="Top Growing Barangays" metric="top_barangays">
              {data.top_barangays.length > 0 ? (
                <GrowthBarangayBars rows={data.top_barangays} />
              ) : (
                <Unavailable>
                  No business registered a barangay address in this period, so there is nothing to
                  rank.
                </Unavailable>
              )}
            </Panel>

            <Panel title="Business Renewal Performance" metric="cohort_survival">
              {renewal.points.length > 0 ? (
                <>
                  <GrowthRenewalCurve points={renewal.points} />
                  {/*
                    Two sentences, and they do different jobs. The second is
                    verbatim from the server and not optional — see the note at
                    the top of this file — and it explains the MEASURE.

                    The first names the AXIS, which nothing on the screen did.
                    A panelist asked "what does Renewal 1, 2, 3 mean?" and the
                    caption could not answer: it says how the share is worked
                    out but never what the three points are, so a reader cannot
                    tell a business's own first renewal from the first one in
                    the window the selector above is set to. It is the former —
                    the curve follows each business's permit chain from its
                    first permit and ignores the period entirely — and that is
                    one clause's worth of fact, so it gets one clause and stays
                    in the same block rather than becoming a second footnote.
                  */}
                  <p className="mt-2 border-t border-line pt-2 text-[11px] leading-snug text-ink-muted">
                    The 1st renewal is a business&rsquo;s own first, not a calendar year and not the
                    first in this period. {renewal.methodology}
                  </p>
                </>
              ) : (
                <Unavailable>
                  No business has reached a first renewal yet, so there is no compliance rate to
                  follow.
                </Unavailable>
              )}
            </Panel>

            <Panel title="Business Closure Trend" metric="closure_trend">
              {data.closure_trend.length > 0 ? (
                <GrowthClosureTrend
                  data={data.closure_trend.map((row) => ({
                    ...row,
                    label: monthLabel(row.month),
                  }))}
                />
              ) : (
                <Unavailable>
                  No closure is on record for this period, so there is no trend to plot.
                </Unavailable>
              )}
            </Panel>

            <Panel
              title="Business Industry Growth Trend"
              metric="industry_growth"
              className="lg:col-span-2"
            >
              {industryLenses.lines_on_record > 0 ? (
                <GrowthIndustryTrend
                  lenses={industryLenses}
                  priorLabel={`${monthYear(data.prior_period_start)} – ${monthYear(data.period_start)}`}
                  currentLabel={`${monthYear(data.period_start)} – ${monthYear(data.period_end)}`}
                />
              ) : (
                <Unavailable>
                  No line of business is on record yet, so there is nothing to plot.
                </Unavailable>
              )}
            </Panel>
          </div>
        </MetricDefinitions>
      ) : null}
    </div>
  )
}
