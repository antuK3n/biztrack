import { useState } from 'react'
import {
  CartesianGrid,
  Line,
  LineChart,
  ReferenceArea,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import type { ReactNode } from 'react'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/primitives'
import { Info, MetricDefinitions } from '../../components/ui/MetricInfo'
import { FilterMenu, PageTitle, ProtoCard } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { ProcessingTimeDepartment, ProcessingTimePoint } from '../../lib/types'
import { AnalyticsTabs } from './AnalyticsTabs'
import { ComputedAt } from './ComputedAt'

/*
 * Permit Processing Time Monitoring (mockup 106) — Feature 7, formerly the
 * standalone R project's Shiny tab.
 *
 * Every number on this page comes from /analytics/processing-time, which runs
 * the ported control-chart maths (App\Support\Spc) over real review
 * assignments. Nothing here re-derives statistics in the browser: the front end
 * draws what the server computed, so the chart and the PDF can never disagree.
 */

const ROYAL = '#3242ca'
const GRID = '#c5cfe0'
const BAND = '#dfe6f8'
const FLAG = '#111827'
const AXIS_TICK = { fontSize: 12, fill: '#5b6472' } as const

const WINDOW_OPTIONS = [
  { value: '13', label: 'Last 13 weeks' },
  { value: '26', label: 'Last 26 weeks' },
  { value: '52', label: 'Last 52 weeks' },
  { value: '104', label: 'Last 104 weeks' },
]

/** "Jan 12" — the week-of column in the Flagged Weeks table. */
function weekLabel(isoDate: string): string {
  const [year, month, day] = isoDate.split('-').map(Number)
  return new Date(year, month - 1, day).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}

function signedDays(value: number): string {
  return `${value >= 0 ? '+' : ''}${value.toFixed(1)}`
}

const TREND_LABEL: Record<string, string> = {
  rising: 'Rising',
  steady: 'Steady',
  easing: 'Easing',
}

/** Solid marker on weeks the chart flagged, hollow royal dot everywhere else. */
function WeekDot(props: {
  cx?: number
  cy?: number
  payload?: ProcessingTimePoint
  index?: number
}) {
  const { cx, cy, payload } = props
  if (cx === undefined || cy === undefined || !payload) return null
  const flagged = payload.status === 'out_of_control'
  return (
    <circle
      cx={cx}
      cy={cy}
      r={flagged ? 5 : 3.5}
      fill={flagged ? FLAG : '#ffffff'}
      stroke={flagged ? FLAG : ROYAL}
      strokeWidth={2}
    />
  )
}

function ControlChart({ department }: { department: ProcessingTimeDepartment }) {
  const data = department.points.map((point) => ({ ...point, label: weekLabel(point.week_start) }))
  const flaggedCount = department.flagged.length

  // Pin the axis around both the data and the limits: on a calm office the UCL
  // sits well above every point, and an auto domain would crop the band away.
  const means = department.points.map((point) => point.mean_days)
  const low = Math.min(department.lcl, ...means)
  const high = Math.max(department.ucl, ...means)
  const pad = (high - low) * 0.1 || 1
  const domain: [number, number] = [Math.max(0, low - pad), high + pad]

  return (
    <ProtoCard className="p-5">
      <ResponsiveContainer width="100%" height={230}>
        <LineChart data={data} margin={{ top: 10, right: 16, left: 0, bottom: 4 }}>
          <CartesianGrid stroke={GRID} strokeDasharray="3 3" vertical={false} />
          {/* The normal operating range: everything between the control limits. */}
          <ReferenceArea y1={department.lcl} y2={department.ucl} fill={BAND} fillOpacity={0.9} />
          <ReferenceLine y={department.ucl} stroke={ROYAL} strokeDasharray="4 4" strokeOpacity={0.7} />
          <ReferenceLine y={department.lcl} stroke={ROYAL} strokeDasharray="4 4" strokeOpacity={0.7} />
          <ReferenceLine y={department.center} stroke={ROYAL} strokeOpacity={0.35} />
          <XAxis dataKey="label" tick={AXIS_TICK} tickLine={false} axisLine={{ stroke: GRID }} minTickGap={16} />
          <YAxis
            domain={domain}
            tickFormatter={(value: number) => value.toFixed(1)}
            tick={AXIS_TICK}
            tickLine={false}
            axisLine={false}
            width={44}
          />
          <Tooltip
            formatter={(value, _name, item) => {
              const point = item?.payload as ProcessingTimePoint | undefined
              return [
                `${Number(value).toFixed(2)} days across ${point?.reviews ?? 0} reviews`,
                point?.status === 'out_of_control' ? 'Outside the usual range' : 'Average turnaround',
              ]
            }}
            labelFormatter={(label) => `Week of ${String(label)}`}
          />
          <Line
            type="monotone"
            dataKey="mean_days"
            name="Average turnaround"
            stroke={ROYAL}
            strokeWidth={2.5}
            dot={<WeekDot />}
            activeDot={{ r: 6 }}
            isAnimationActive={false}
          />
        </LineChart>
      </ResponsiveContainer>
      <p className="mt-3 text-[13px] text-ink-muted">
        {department.code} &middot; each dot is one week&rsquo;s average &middot; the shaded band is
        this office&rsquo;s usual range &middot; black dots mark the weeks that fell outside it.
      </p>
      <p className="mt-1 text-xs text-ink-muted">
        A usual week averages {department.center.toFixed(2)} days, and anything from{' '}
        {department.lcl.toFixed(2)} to {department.ucl.toFixed(2)} days counts as usual. That range
        was worked out from this office&rsquo;s first {department.calibration_weeks} weeks and then
        held still, so later weeks are measured against the same yardstick.{' '}
        {flaggedCount === 0
          ? 'No week fell outside it.'
          : `${flaggedCount} week${flaggedCount === 1 ? '' : 's'} fell outside it.`}
      </p>
    </ProtoCard>
  )
}

/**
 * A section title, with the server's account of the panel beside it.
 *
 * The info button is a sibling of the h2 rather than a child, so that "How X is
 * measured" does not fold into the heading's accessible name and get announced
 * on every section by anyone navigating this page by heading.
 */
function SectionHeading({ children, metric }: { children: ReactNode; metric?: string }) {
  return (
    <div className="mb-2.5 flex items-center">
      <h2 className="text-xl text-ink">{children}</h2>
      {metric && <Info metric={metric} />}
    </div>
  )
}

function StatusIndicator({ department }: { department: ProcessingTimeDepartment }) {
  const outside = department.status === 'outside'
  return (
    <ProtoCard className="px-5 py-7 text-center">
      <p className={`text-[34px] font-bold leading-none ${outside ? 'text-s-red' : 'text-royal'}`}>
        {outside ? 'Outside' : 'Inside'}
      </p>
      <p className="mt-2.5 text-[13px] text-ink-muted">
        Process Status Indicator
        <Info metric="departments.status" />
      </p>
      <p className="mt-2 text-xs text-ink-muted">
        The week of {weekLabel(department.latest_week)} averaged{' '}
        {department.latest_mean_days.toFixed(2)} days, which is {outside ? 'outside' : 'inside'} this
        office&rsquo;s usual range.
      </p>
    </ProtoCard>
  )
}

function FlaggedWeeks({ department }: { department: ProcessingTimeDepartment }) {
  if (department.flagged.length === 0) {
    return (
      <ProtoCard className="px-5 py-6">
        <p className="text-sm text-ink-secondary">
          No week in this window fell outside {department.code}&apos;s usual range.
        </p>
      </ProtoCard>
    )
  }

  return (
    <ProtoCard className="overflow-hidden">
      <table className="w-full text-left">
        <thead>
          <tr className="border-b border-line text-[11px] uppercase tracking-wide text-ink-muted">
            <th scope="col" className="px-5 py-2.5 font-semibold">
              Week of
            </th>
            <th scope="col" className="px-5 py-2.5 text-right font-semibold">
              Deviation
            </th>
          </tr>
        </thead>
        <tbody>
          {department.flagged.map((week) => (
            <tr key={week.week_start} className="border-b border-line/60 last:border-0">
              <th scope="row" className="px-5 py-2.5 text-[15px] font-semibold text-ink">
                {weekLabel(week.week_start)}
              </th>
              <td className="tnum px-5 py-2.5 text-right text-[15px] font-semibold text-ink">
                {signedDays(week.deviation_days)} <span className="font-normal text-ink-muted">days</span>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </ProtoCard>
  )
}

/**
 * The EWMA reading, one bar per office: a run of small increases that never
 * breaches a control limit on its own still walks the weighted trend away from
 * centre, and a full bar means it has walked far enough to be flagged.
 */
function SlowdownDetector({ departments }: { departments: ProcessingTimeDepartment[] }) {
  return (
    <ProtoCard className="divide-y divide-line/60">
      {departments.map((department) => {
        const rising = department.trend.direction === 'rising'
        return (
          <div key={department.code} className="flex items-center gap-4 px-5 py-4">
            <div className="w-28 shrink-0">
              <p className="text-sm font-bold text-ink">{department.code}</p>
              <p className="text-[11px] text-ink-muted">recent trend</p>
            </div>
            <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-canvas">
              <div
                className={`h-full rounded-full ${rising ? 'bg-royal' : 'bg-line-strong'}`}
                style={{ width: `${Math.max(4, department.trend.magnitude * 100)}%` }}
              />
            </div>
            <div className="w-32 shrink-0 text-right">
              <p className={`text-sm font-semibold ${rising ? 'text-royal' : 'text-ink-secondary'}`}>
                {TREND_LABEL[department.trend.direction] ?? 'Steady'}
              </p>
              <p className="tnum text-[11px] text-ink-muted">
                {signedDays(department.trend.deviation_days)} days
              </p>
            </div>
          </div>
        )
      })}
    </ProtoCard>
  )
}

function LoadingState() {
  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
      <div className="space-y-6">
        <Skeleton className="h-6 w-64" />
        <Skeleton className="h-64 w-full rounded-2xl" />
        <Skeleton className="h-40 w-full rounded-2xl" />
      </div>
      <div className="space-y-6">
        <Skeleton className="h-32 w-full rounded-2xl" />
        <Skeleton className="h-44 w-full rounded-2xl" />
      </div>
    </div>
  )
}

export function ProcessingTimePage() {
  // 52 weeks by default, matching the server: limits are fitted on the first 24
  // weeks of the window, so a shorter window has almost nothing left to monitor.
  const [weeks, setWeeks] = useState('52')
  const [office, setOffice] = useState<string>('')
  const [downloading, setDownloading] = useState(false)
  const [downloadError, setDownloadError] = useState<string | null>(null)

  // Resolves to { data, meta }: the statistics plus when and by which engine they
  // were computed. ComputedAt renders the meta — see AnalyticsProvenance for why
  // it is not optional on an analytics screen.
  const {
    data: result,
    loading,
    error,
    reload,
  } = useAsync(() => analytics.processingTime(Number(weeks)), [weeks])

  const data = result?.data
  const meta = result?.meta

  const departments = data?.departments ?? []
  // The office filter falls back to the first charted office, so a window
  // change that drops the selected office still renders something.
  const selected = departments.find((d) => d.code === office) ?? departments[0]

  const officeOptions = departments.map((d) => ({ value: d.code, label: `${d.code} · ${d.name}` }))

  async function generateReport() {
    setDownloading(true)
    setDownloadError(null)
    try {
      await analytics.processingTimeReport(Number(weeks))
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
              label="Filter processing time"
              fields={[
                {
                  label: 'Window',
                  value: weeks,
                  options: WINDOW_OPTIONS,
                  onChange: setWeeks,
                },
                ...(officeOptions.length > 1
                  ? [
                      {
                        label: 'Office',
                        value: selected?.code ?? '',
                        options: officeOptions,
                        onChange: setOffice,
                      },
                    ]
                  : []),
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
        Permit Processing Time Monitoring
      </PageTitle>

      <AnalyticsTabs />

      {meta && <ComputedAt meta={meta} onRefreshed={reload} />}

      {downloadError && (
        <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
          {downloadError}
        </p>
      )}

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : !data ? null : selected ? (
        <MetricDefinitions value={meta?.definitions}>
          <div className="grid gap-x-6 gap-y-7 lg:grid-cols-[minmax(0,1fr)_320px]">
            <section>
              <SectionHeading metric="departments">Department Processing Time Chart</SectionHeading>
              <ControlChart department={selected} />
            </section>
            <div className="space-y-5">
              <StatusIndicator department={selected} />
              <section>
                <SectionHeading metric="departments.flagged">Flagged Weeks</SectionHeading>
                <FlaggedWeeks department={selected} />
              </section>
            </div>
            <section>
              <SectionHeading metric="departments.trend">Gradual Slowdown Detector</SectionHeading>
              <SlowdownDetector departments={departments} />
            </section>
          </div>
        </MetricDefinitions>
      ) : (
        <MetricDefinitions value={meta?.definitions}>
          <EmptyState
            title="Not enough review history to chart yet"
            description={
              <>
                Across the last {data.window_weeks} weeks the offices finished{' '}
                {data.completed_reviews}
                <Info metric="completed_reviews" /> reviews, but no single week reached the{' '}
                {data.min_completions_per_week} finished reviews needed before a weekly average means
                anything. The chart appears on its own once enough reviews are being finished each
                week.
              </>
            }
          />
        </MetricDefinitions>
      )}
    </div>
  )
}
