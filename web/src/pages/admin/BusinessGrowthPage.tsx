import { useState } from 'react'
import type { ReactNode } from 'react'
import {
  CartesianGrid,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { ErrorState, Skeleton, SkeletonCards } from '../../components/ui/primitives'
import { Info, MetricDefinitions } from '../../components/ui/MetricInfo'
import { FilterMenu, PageTitle, ProtoCard } from '../../components/ui/Proto'
import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type {
  BarangayGrowthRow,
  BusinessGrowthReport,
  IndustryGrowthRow,
} from '../../lib/types'
import { AnalyticsTabs } from './AnalyticsTabs'
import { ComputedAt } from './ComputedAt'
import { GenerateReportButton } from './GenerateReportButton'

/*
 * Business Lifecycle Monitoring — docs/r-integration-spec.md §4.
 *
 * Naming follows mockup 122; formulas follow the client's paper. The spec sets
 * that split explicitly — "Mockup is newer, follow it for naming, follow the
 * paper for formulas" — and the rest of the stack already honours it:
 * BusinessGrowthAnalytics, AnalyticsDatasets and the payload types all call this
 * Business Lifecycle Monitoring. This file and the tab strip had reversed it on
 * the grounds that the paper is what gets presented, which left one screen
 * disagreeing with its own API about what it is called.
 *
 * The paper's names, for anyone reading it alongside: "Business Growth Analysis"
 * for the screen and "Business Renewal Performance" for the cohort KPI.
 *
 * Everything is computed server-side (App\Support\BusinessGrowthAnalytics, or R's
 * POST /growth/lifecycle) and rendered as given. Where a figure genuinely cannot
 * be derived — a growth rate against an empty prior period, survival for a cohort
 * that has not reached its first renewal — the server sends null and this page
 * says so rather than printing a number nobody can defend.
 *
 * THE PANEL THAT NEEDS READING CAREFULLY is cohort survival. It is a Kaplan-Meier
 * estimate over renewal cycles, not a single-period ratio, and it is descriptive
 * rather than predictive. The curve carries its at-risk count at every cycle
 * because a late cycle can rest on very few businesses, and a percentage over a
 * hundred businesses should not look like one over six hundred.
 */

const ROYAL = '#3242ca'
const GRID = '#c5cfe0'
const MUTED_BAR = '#9fb6dd'
const AXIS_TICK = { fontSize: 12, fill: '#5b6472' } as const

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

function pct(value: number | null): string {
  return value === null ? '—' : `${value.toFixed(1)}%`
}

/** "2026-03" reads as "Mar 26" on the closure-trend axis. */
function monthLabel(month: string): string {
  const [year, m] = month.split('-').map(Number)
  return new Date(year, m - 1, 1).toLocaleDateString('en-PH', { month: 'short', year: '2-digit' })
}

function Headline({
  value,
  label,
  hint,
  muted,
  metric,
}: {
  value: string
  label: string
  hint?: string
  muted?: boolean
  metric?: string
}) {
  return (
    <ProtoCard className="px-4 py-7 text-center">
      <p
        className={`tnum font-bold leading-tight ${
          muted ? 'text-[15px] text-ink-secondary' : 'text-[30px] leading-none text-royal'
        }`}
      >
        {value}
      </p>
      <p className="mt-2.5 text-[13px] text-ink-muted">
        {label}
        {metric && <Info metric={metric} />}
      </p>
      {hint && <p className="mt-1 text-[11px] leading-snug text-ink-muted">{hint}</p>}
    </ProtoCard>
  )
}

function SectionHeading({
  children,
  note,
  metric,
}: {
  children: ReactNode
  note?: string
  metric?: string
}) {
  return (
    <div className="mb-2.5">
      {/*
        The info button is a sibling of the h2, not a child of it. Nested, its
        label folds into the heading's accessible name, so anyone navigating
        this page by heading hears the button on every section.
      */}
      <div className="flex items-center">
        <h2 className="text-xl text-ink">{children}</h2>
        {metric && <Info metric={metric} />}
      </div>
      {note && <p className="mt-0.5 text-[11px] text-ink-muted">{note}</p>}
    </div>
  )
}

/* ── Business Lifecycle Status ─────────────────────────────────────────── */

function StatusSummary({ report }: { report: BusinessGrowthReport }) {
  return (
    <ProtoCard className="overflow-hidden">
      <table className="w-full text-left">
        <thead>
          <tr className="border-b border-line text-[11px] uppercase tracking-wide text-ink-muted">
            <th scope="col" className="px-5 py-2.5 font-semibold">
              Status
            </th>
            <th scope="col" className="px-5 py-2.5 text-right font-semibold">
              Count
            </th>
            <th scope="col" className="px-5 py-2.5 text-right font-semibold">
              Share
            </th>
          </tr>
        </thead>
        <tbody>
          {report.status_summary.map((row) => (
            <tr key={row.status} className="border-b border-line/60 last:border-0">
              <th scope="row" className="px-5 py-2.5 text-[15px] font-semibold text-ink">
                {row.label}
              </th>
              <td className="tnum px-5 py-2.5 text-right text-[15px] text-ink">
                {row.count.toLocaleString()}
              </td>
              <td className="tnum px-5 py-2.5 text-right text-[15px] text-ink-secondary">
                {pct(row.share)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="border-t border-line px-5 py-3 text-xs text-ink-muted">
        Active holds a permit valid today, Expired has let every permit lapse, Inactive is registered
        but has never held a permit, Closed had its registration removed. Worked out from permits,
        not from the status an admin sets on the business record.
      </p>
    </ProtoCard>
  )
}

/* ── Cohort Survival ───────────────────────────────────────────────────── */

/**
 * The Kaplan-Meier curve, plus per-cohort figures.
 *
 * `at_risk` is shown at every cycle on purpose. Survival through a third renewal
 * can rest on a hundred businesses when the first rests on four hundred, and a
 * reader who cannot see that has no way to judge how much weight the last point
 * carries.
 */

/* ── Top Growing Barangays ─────────────────────────────────────────────── */

function BarangayBars({ rows }: { rows: BarangayGrowthRow[] }) {
  // Scaled by the biggest INCREASE, because that is what the panel ranks. Scaling
  // by volume would put the longest bar on a barangay that did not grow.
  const peak = Math.max(1, ...rows.map((row) => Math.abs(row.delta)))

  return (
    <ProtoCard className="space-y-3.5 px-5 py-5">
      {rows.map((row, i) => (
        <div key={row.barangay} className="flex items-center gap-4">
          <div className="w-28 shrink-0">
            <p className="truncate text-[13px] font-bold text-ink">{row.barangay}</p>
            <p className="text-[11px] text-ink-muted">
              {row.growth_rate === null
                ? `${row.registrations} new, none before`
                : `${signed(row.growth_rate)}% vs last period`}
            </p>
          </div>
          <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-canvas">
            <div
              className="h-full rounded-full"
              style={{
                width: `${Math.max(3, (Math.abs(row.delta) / peak) * 100)}%`,
                backgroundColor: i === 0 && row.delta > 0 ? ROYAL : MUTED_BAR,
              }}
            />
          </div>
          <p className="tnum w-12 shrink-0 text-right text-[13px] font-semibold text-ink">
            {signed(row.delta)}
          </p>
        </div>
      ))}
      <p className="border-t border-line pt-3 text-xs text-ink-muted">
        Ranked by how much new registrations went up against the period before of the same length,
        not by how big the barangay is. The figure on the right is that change.
      </p>
    </ProtoCard>
  )
}

/* ── Business Closure Trend ────────────────────────────────────────────── */

function ClosureTrend({ report }: { report: BusinessGrowthReport }) {
  const data = report.closure_trend.map((row) => ({ ...row, label: monthLabel(row.month) }))
  const total = report.closure_trend.reduce((sum, row) => sum + row.closures, 0)

  return (
    <ProtoCard className="p-5">
      <ResponsiveContainer width="100%" height={200}>
        <LineChart data={data} margin={{ top: 8, right: 12, left: -14, bottom: 4 }}>
          <CartesianGrid stroke={GRID} strokeDasharray="3 3" vertical={false} />
          <XAxis
            dataKey="label"
            tick={AXIS_TICK}
            tickLine={false}
            axisLine={{ stroke: GRID }}
            minTickGap={14}
          />
          <YAxis
            tick={AXIS_TICK}
            tickLine={false}
            axisLine={false}
            allowDecimals={false}
            width={34}
          />
          <Tooltip
            formatter={(value) => [`${Number(value)} closed`, 'Businesses']}
            labelFormatter={(label) => String(label)}
          />
          <Line
            type="monotone"
            dataKey="closures"
            name="Closures"
            stroke={ROYAL}
            strokeWidth={2.5}
            dot={{ r: 3.5, fill: ROYAL }}
            activeDot={{ r: 5 }}
            isAnimationActive={false}
          />
        </LineChart>
      </ResponsiveContainer>
      <p className="mt-2 text-[13px] text-ink-muted">
        {total === 0
          ? `No business closed its registration in the last ${report.period_months} months.`
          : `${total} closure${total === 1 ? '' : 's'} over ${report.period_months} months, dated by when the registration was removed — the only closure date the system records.`}
      </p>
    </ProtoCard>
  )
}

/* ── Business Industry Growth Trend ────────────────────────────────────── */

function IndustryBars({ rows }: { rows: IndustryGrowthRow[] }) {
  const peak = Math.max(1, ...rows.map((row) => row.count))

  return (
    <ProtoCard className="space-y-3.5 px-5 py-5">
      {rows.map((row) => (
        <div key={row.psic_code} className="flex items-center gap-4">
          <div className="w-36 shrink-0">
            <p className="truncate text-[13px] font-bold text-ink" title={row.industry}>
              {row.industry}
            </p>
            {/*
              The direction is a word, never only a bar tone: DESIGN.md's Never
              Color Alone rule, and "declining" is the kind of finding a reader
              should not have to infer from a shade of blue.
            */}
            <p className="text-[11px] text-ink-muted">
              {row.direction} · {signed(row.delta)} vs last period
            </p>
          </div>
          <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-canvas">
            <div
              className="h-full rounded-full"
              style={{
                width: `${Math.max(3, (row.count / peak) * 100)}%`,
                backgroundColor: row.direction === 'declining' ? MUTED_BAR : ROYAL,
              }}
            />
          </div>
          <p className="tnum w-12 shrink-0 text-right text-[13px] font-semibold text-ink">
            {row.count}
          </p>
        </div>
      ))}
      <p className="border-t border-line pt-3 text-xs text-ink-muted">
        The bar is how many businesses carry that line today; growing or declining is the change in
        new registrations against the period before. Grouped by PSIC code — the national numbering
        for industries.
      </p>
    </ProtoCard>
  )
}

/* ── page ──────────────────────────────────────────────────────────────── */

function LoadingState() {
  return (
    <div className="space-y-6">
      <SkeletonCards count={4} />
      <div className="grid gap-6 lg:grid-cols-2">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-52 w-full rounded-2xl" />
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
  const survival = data?.cohort_survival
  const period = data ? `${data.period_start} to ${data.period_end}` : ''
  const lastPoint = survival?.points[survival.points.length - 1]

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
        Business Lifecycle Monitoring
      </PageTitle>

      <AnalyticsTabs />

      {meta && <ComputedAt meta={meta} onRefreshed={reload} />}

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : data && survival ? (
        <MetricDefinitions value={meta?.definitions}>
          <div className="grid grid-cols-2 gap-5 lg:grid-cols-4">
            <Headline
              value={data.growth_rate === null ? 'No prior period' : `${signed(data.growth_rate)}%`}
              label="Business Growth Rate"
              metric="growth_rate"
              hint={
                data.growth_rate === null
                  ? 'nothing registered in the period before this one to compare against'
                  : `${data.registrations} new vs ${data.registrations_prior} before`
              }
              muted={data.growth_rate === null}
            />
            <Headline
              value={
                survival.survival === null
                  ? 'No renewal reached'
                  : `${survival.survival.toFixed(0)}%`
              }
              label="Cohort Survival Rate"
              metric="cohort_survival.survival"
              hint={
                survival.survival === null
                  ? 'no business has reached a first renewal yet'
                  : `still renewing after ${survival.max_cycle} ${survival.max_cycle === 1 ? 'cycle' : 'cycles'}${lastPoint ? ` · ${lastPoint.at_risk} businesses got that far` : ''}`
              }
              muted={survival.survival === null}
            />
            <Headline
              value={data.closures.toLocaleString()}
              label="Closures (Period)"
              metric="closures"
              hint={period}
            />
            <Headline
              value={top ? top.barangay : 'No data'}
              label="Top Growing Barangay"
              metric="top_barangays"
              hint={top ? `${signed(top.delta)} new registrations vs last period` : undefined}
              muted={!top}
            />
          </div>

          <div className="mt-7 grid gap-x-6 gap-y-7 lg:grid-cols-2">
            <section>
              <SectionHeading note="As of today" metric="status_summary">
                Business Status Summary
              </SectionHeading>
              <StatusSummary report={data} />
            </section>

            <section>
              <SectionHeading
                note={`${period}, against the ${data.period_months} months before`}
                metric="top_barangays"
              >
                Top Growing Barangays
              </SectionHeading>
              {data.top_barangays.length > 0 ? (
                <BarangayBars rows={data.top_barangays} />
              ) : (
                <ProtoCard className="px-5 py-6">
                  <p className="text-sm text-ink-secondary">
                    No business registered a barangay address in this period, so there is nothing to
                    rank.
                  </p>
                </ProtoCard>
              )}
            </section>

            <section>
              <SectionHeading note={period} metric="closure_trend">
                Business Closure Trend
              </SectionHeading>
              <ClosureTrend report={data} />
            </section>

            <section>
              <SectionHeading
                note={`${period}, against the ${data.period_months} months before`}
                metric="industry_growth"
              >
                Business Industry Growth Trend
              </SectionHeading>
              {data.industry_growth.length > 0 ? (
                <IndustryBars rows={data.industry_growth} />
              ) : (
                <ProtoCard className="px-5 py-6">
                  <p className="text-sm text-ink-secondary">
                    No line of business is on record yet, so there is nothing to rank.
                  </p>
                </ProtoCard>
              )}
            </section>
          </div>

          <p className="mt-6 text-xs text-ink-muted">
            {data.registrations.toLocaleString()}
            <Info metric="registrations" /> new registrations between {data.period_start} and{' '}
            {data.period_end}, against {data.registrations_prior.toLocaleString()} in the{' '}
            {data.period_months} months before that. Cohort survival follows{' '}
            {survival.businesses.toLocaleString()} businesses through{' '}
            {survival.renewals_observed.toLocaleString()} renewal cycles on record, of which{' '}
            {survival.lapses.toLocaleString()} lapsed.
          </p>

          {/*
            Verbatim from the server, and not optional. The renewal figure is a
            Kaplan-Meier estimate over the cycles this cohort actually reached,
            with businesses still inside their current permit set aside rather
            than counted as failures — a reader who takes it for a plain
            pass rate will read it as far more certain than it is. The server
            ships the sentence with the number for that reason, so it sits on
            the screen rather than only in the info panel.
          */}
          <p className="mt-2 text-xs leading-relaxed text-ink-muted">
            {survival.methodology}
            <Info metric="cohort_survival" />
          </p>
        </MetricDefinitions>
      ) : null}
    </div>
  )
}
