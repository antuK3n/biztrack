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
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/primitives'
import { FilterMenu, PageTitle, ProtoCard } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { ProcessingTimeDepartment, ProcessingTimePoint } from '../../lib/types'
import { AnalyticsTabs } from './AnalyticsTabs'

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
                point?.status === 'out_of_control' ? 'Outside range' : 'Mean turnaround',
              ]
            }}
            labelFormatter={(label) => `Week of ${String(label)}`}
          />
          <Line
            type="monotone"
            dataKey="mean_days"
            name="Mean turnaround"
            stroke={ROYAL}
            strokeWidth={2.5}
            dot={<WeekDot />}
            activeDot={{ r: 6 }}
            isAnimationActive={false}
          />
        </LineChart>
      </ResponsiveContainer>
      <p className="mt-3 text-[13px] text-ink-muted">
        {department.code} &middot; shaded band is the normal operating range &middot; black points mark weeks
        outside range.
      </p>
      <p className="mt-1 text-xs text-ink-muted">
        Centre line {department.center.toFixed(2)} days, range {department.lcl.toFixed(2)} to{' '}
        {department.ucl.toFixed(2)} days, fitted on the first {department.calibration_weeks} weeks.{' '}
        {flaggedCount === 0
          ? 'No week fell outside range.'
          : `${flaggedCount} week${flaggedCount === 1 ? '' : 's'} outside range.`}
      </p>
    </ProtoCard>
  )
}

function StatusIndicator({ department }: { department: ProcessingTimeDepartment }) {
  const outside = department.status === 'outside'
  return (
    <ProtoCard className="px-5 py-7 text-center">
      <p className={`text-[34px] font-bold leading-none ${outside ? 'text-s-red' : 'text-royal'}`}>
        {outside ? 'Outside' : 'Inside'}
      </p>
      <p className="mt-2.5 text-[13px] text-ink-muted">Process Status Indicator</p>
      <p className="mt-2 text-xs text-ink-muted">
        Week of {weekLabel(department.latest_week)} averaged {department.latest_mean_days.toFixed(2)} days.
      </p>
    </ProtoCard>
  )
}

function FlaggedWeeks({ department }: { department: ProcessingTimeDepartment }) {
  if (department.flagged.length === 0) {
    return (
      <ProtoCard className="px-5 py-6">
        <p className="text-sm text-ink-secondary">
          No week in this window fell outside {department.code}&apos;s normal operating range.
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
              <p className="text-[11px] text-ink-muted">weighted trend</p>
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

  const { data, loading, error, reload } = useAsync(
    () => analytics.processingTime(Number(weeks)),
    [weeks],
  )

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
        <>
          <div className="grid gap-x-6 gap-y-7 lg:grid-cols-[minmax(0,1fr)_320px]">
            <section>
              <h2 className="mb-2.5 text-xl text-ink">Department Processing Time Chart</h2>
              <ControlChart department={selected} />
            </section>
            <div className="space-y-5">
              <StatusIndicator department={selected} />
              <section>
                <h2 className="mb-2.5 text-xl text-ink">Flagged Weeks</h2>
                <FlaggedWeeks department={selected} />
              </section>
            </div>
            <section>
              <h2 className="mb-2.5 text-xl text-ink">Gradual Slowdown Detector</h2>
              <SlowdownDetector departments={departments} />
            </section>
          </div>

          {data.thin.length > 0 && (
            <p className="mt-5 text-sm text-ink-muted">
              Not charted yet:{' '}
              {data.thin
                .map(
                  (row) =>
                    `${row.code} (${row.completed_reviews} review${row.completed_reviews === 1 ? '' : 's'})`,
                )
                .join(', ')}
              . A week needs at least {data.min_completions_per_week} completed reviews before its average
              is trustworthy.
            </p>
          )}
        </>
      ) : (
        <EmptyState
          title="Not enough review history to chart yet"
          description={
            <>
              Across the last {data.window_weeks} weeks the offices completed {data.completed_reviews}{' '}
              reviews, but no single week reached the {data.min_completions_per_week} completions a control
              chart needs before its average means anything. The chart appears on its own once review
              volume gets there.
            </>
          }
        />
      )}
    </div>
  )
}
