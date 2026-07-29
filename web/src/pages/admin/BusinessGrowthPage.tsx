import { useState } from 'react'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ErrorState, Skeleton, SkeletonCards } from '../../components/ui/primitives'
import { FilterMenu, PageTitle, ProtoCard } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { BarangayGrowthRow, BusinessGrowthReport, IndustryGrowthRow } from '../../lib/types'
import { AnalyticsTabs } from './AnalyticsTabs'

/*
 * Business Growth Analysis (mockup 105).
 *
 * Everything is computed server-side from the register (BusinessGrowthAnalytics)
 * and rendered here as-is. Where a figure genuinely cannot be derived — a growth
 * rate against an empty prior period, a renewal rate with nothing decided — the
 * server sends null and this page says so instead of printing a number nobody
 * can defend.
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
]

function signed(value: number): string {
  return `${value >= 0 ? '+' : ''}${value}`
}

/** "2026-03" reads as "Mar 2026" on the closure-trend axis. */
function monthLabel(month: string): string {
  const [year, m] = month.split('-').map(Number)
  return new Date(year, m - 1, 1).toLocaleDateString('en-PH', { month: 'short', year: '2-digit' })
}

function Headline({ value, label, muted }: { value: string; label: string; muted?: boolean }) {
  return (
    <ProtoCard className="px-4 py-7 text-center">
      <p
        className={`tnum font-bold leading-tight ${
          muted ? 'text-[15px] text-ink-secondary' : 'text-[30px] leading-none text-royal'
        }`}
      >
        {value}
      </p>
      <p className="mt-2.5 text-[13px] text-ink-muted">{label}</p>
    </ProtoCard>
  )
}

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
                {row.share.toFixed(1)}%
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="border-t border-line px-5 py-3 text-xs text-ink-muted">
        Active holds a permit valid today, Expired has let every permit lapse, Inactive is registered but
        never permitted, Closed had its registration removed.
      </p>
    </ProtoCard>
  )
}

function BarangayBars({ rows }: { rows: BarangayGrowthRow[] }) {
  const peak = Math.max(1, ...rows.map((row) => row.registrations))
  return (
    <ProtoCard className="space-y-3.5 px-5 py-5">
      {rows.map((row, i) => (
        <div key={row.barangay} className="flex items-center gap-4">
          <div className="w-28 shrink-0">
            <p className="truncate text-[13px] font-bold text-ink">{row.barangay}</p>
            <p className="text-[11px] text-ink-muted">
              {row.growth_rate === null
                ? `${row.registrations} new`
                : `${signed(row.growth_rate)}% vs prior`}
            </p>
          </div>
          <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-canvas">
            <div
              className="h-full rounded-full"
              style={{
                width: `${Math.max(4, (row.registrations / peak) * 100)}%`,
                backgroundColor: i === 0 ? ROYAL : MUTED_BAR,
              }}
            />
          </div>
          <p className="tnum w-12 shrink-0 text-right text-[13px] font-semibold text-ink">
            {signed(row.delta)}
          </p>
        </div>
      ))}
    </ProtoCard>
  )
}

function ClosureTrend({ report }: { report: BusinessGrowthReport }) {
  const data = report.closure_trend.map((row) => ({ ...row, label: monthLabel(row.month) }))
  const total = report.closure_trend.reduce((sum, row) => sum + row.closures, 0)

  return (
    <ProtoCard className="p-5">
      <ResponsiveContainer width="100%" height={190}>
        <LineChart data={data} margin={{ top: 8, right: 12, left: -14, bottom: 4 }}>
          <CartesianGrid stroke={GRID} strokeDasharray="3 3" vertical={false} />
          <XAxis dataKey="label" tick={AXIS_TICK} tickLine={false} axisLine={{ stroke: GRID }} minTickGap={14} />
          <YAxis tick={AXIS_TICK} tickLine={false} axisLine={false} allowDecimals={false} width={34} />
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
          : `${total} closure${total === 1 ? '' : 's'} over ${report.period_months} months, dated by when the registration was removed.`}
      </p>
    </ProtoCard>
  )
}

function IndustryBars({ rows }: { rows: IndustryGrowthRow[] }) {
  const peak = Math.max(1, ...rows.map((row) => row.count))
  return (
    <ProtoCard className="space-y-3.5 px-5 py-5">
      {rows.map((row) => (
        <div key={row.psic_code} className="flex items-center gap-4">
          <div className="w-32 shrink-0">
            <p className="truncate text-[13px] font-bold text-ink" title={row.industry}>
              {row.industry}
            </p>
            <p className="text-[11px] text-ink-muted">{row.direction}</p>
          </div>
          <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-canvas">
            <div
              className="h-full rounded-full"
              style={{
                width: `${Math.max(4, (row.count / peak) * 100)}%`,
                backgroundColor: row.direction === 'declining' ? MUTED_BAR : ROYAL,
              }}
            />
          </div>
          <p className="tnum w-12 shrink-0 text-right text-[13px] font-semibold text-ink">{row.count}</p>
        </div>
      ))}
    </ProtoCard>
  )
}

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
  const [downloading, setDownloading] = useState(false)
  const [downloadError, setDownloadError] = useState<string | null>(null)

  const { data, loading, error, reload } = useAsync(
    () => analytics.businessGrowth(Number(months)),
    [months],
  )

  async function generateReport() {
    setDownloading(true)
    setDownloadError(null)
    try {
      await analytics.businessGrowthReport(Number(months))
    } catch (err) {
      setDownloadError(toApiError(err).message)
    } finally {
      setDownloading(false)
    }
  }

  const top = data?.top_barangays[0]

  return (
    <div>
      <PageTitle
        right={
          <span className="flex items-center gap-3 pb-1">
            <FilterMenu
              label="Filter business growth"
              fields={[
                { label: 'Period', value: months, options: PERIOD_OPTIONS, onChange: setMonths },
              ]}
            />
            <button
              type="button"
              onClick={generateReport}
              disabled={downloading}
              className="rounded-lg bg-royal px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:bg-royal-hover disabled:opacity-60"
            >
              {downloading ? 'Generating…' : 'Generate Report'}
            </button>
          </span>
        }
      >
        Business Growth Analysis
      </PageTitle>

      <AnalyticsTabs />

      {downloadError && (
        <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
          {downloadError}
        </p>
      )}

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : data ? (
        <>
          <div className="grid grid-cols-2 gap-5 lg:grid-cols-4">
            <Headline
              value={data.growth_rate === null ? 'No prior period' : `${signed(data.growth_rate)}%`}
              label="Business Growth Rate"
              muted={data.growth_rate === null}
            />
            <Headline
              value={
                data.renewal_performance.rate === null
                  ? 'Nothing decided yet'
                  : `${Math.round(data.renewal_performance.rate)}%`
              }
              label="Business Renewal Performance"
              muted={data.renewal_performance.rate === null}
            />
            <Headline value={data.closures.toLocaleString()} label="Closures (Period)" />
            <Headline
              value={top ? top.barangay : 'No data'}
              label="Top Growing Barangay"
              muted={!top}
            />
          </div>

          <div className="mt-7 grid gap-x-6 gap-y-7 lg:grid-cols-2">
            <section>
              <h2 className="mb-2.5 text-xl text-ink">Business Status Summary</h2>
              <StatusSummary report={data} />
            </section>

            <section>
              <h2 className="mb-2.5 text-xl text-ink">Top Growing Barangays</h2>
              {data.top_barangays.length > 0 ? (
                <BarangayBars rows={data.top_barangays} />
              ) : (
                <ProtoCard className="px-5 py-6">
                  <p className="text-sm text-ink-secondary">
                    No business registered a barangay address in this period.
                  </p>
                </ProtoCard>
              )}
            </section>

            <section>
              <h2 className="mb-2.5 text-xl text-ink">Business Closure Trend</h2>
              <ClosureTrend report={data} />
            </section>

            <section>
              <h2 className="mb-2.5 text-xl text-ink">Business Industry Growth Trend</h2>
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
            {data.registrations.toLocaleString()} new registrations between {data.period_start} and{' '}
            {data.period_end}, against {data.registrations_prior.toLocaleString()} in the {data.period_months}{' '}
            months before that.
            {data.renewal_performance.decided > 0 &&
              ` Renewal performance is ${data.renewal_performance.approved} approved of ${data.renewal_performance.decided} decided.`}
          </p>
        </>
      ) : null}
    </div>
  )
}
