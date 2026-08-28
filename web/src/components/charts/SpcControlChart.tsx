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
import type { ProcessingTimeDepartment, ProcessingTimePoint } from '../../lib/types'

/*
 * The Department Processing Time Chart — one office's weekly average review
 * turnaround drawn against the range it normally stays within.
 *
 * This is a control chart, and its whole point is stated in the client's spec:
 * it "establishes the range of variation a process normally produces, so that
 * ordinary ups and downs are ignored, and only movements beyond the normal
 * range are treated as real problems." Everything the picture needs — the
 * centre line, the two edges of the range, which weeks fell outside — is
 * computed server-side (App\Support\Spc), so nothing on this file
 * re-derives a statistic. The browser draws what the server decided; that is
 * what keeps the screen and the PDF report from ever disagreeing.
 *
 * Two constraints shaped the markup more than the maths did.
 *
 *  1. **The vocabulary stays plain.** No UCL, LCL, sigma or "out of control"
 *     appears on the face. The client rejected control-chart jargon outright
 *     ("Ito, ano 'yung *inside*? … Ano 'yung *flag*?" — docs/r-integration-
 *     revisions.md §6.2), so the range is "the normal range" and a week is
 *     "slower" or "faster" than usual. The statistical terms survive only in
 *     the server's own info-hover text.
 *
 *  2. **A screen reader gets the numbers, not the SVG.** recharts renders an
 *     SVG of unlabelled paths; a control chart is the worst case for that,
 *     because the finding IS the relationship between a dot and a band and
 *     neither is text. So the figure carries a written summary as its
 *     accessible name, and every week the chart plots is also available as a
 *     real table underneath. Sighted readers get it collapsed, which also
 *     serves the client's "remove large spaces" note.
 */

const ROYAL = '#3242ca'
const GRID = '#c5cfe0'
/** The normal range, filled — royal at a wash so the line still reads on top. */
const BAND = '#dfe6f8'
/** A week slower than the office's normal range: a real delay, so error red. */
const SLOW = '#bd0000'
/**
 * A week faster than the normal range. Deliberately NOT red.
 *
 * "Red Means Stop" (DESIGN.md): red is reserved for something that has gone
 * wrong. A week that finished quicker than usual is unusual — the statistics
 * flag it, and it belongs in the list because an unexplained speed-up can mean
 * work was waved through — but it is not a delay and must not be dressed as
 * one.
 */
const FAST = '#14171d'

const AXIS_TICK = { fontSize: 12, fill: '#5b6472' } as const

/** "20 Apr 2026" — the written form used wherever a week is named as text. */
export function spcWeekDate(isoDate: string): string {
  const [year, month, day] = isoDate.split('-').map(Number)
  return new Date(year, month - 1, day).toLocaleDateString('en-PH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

/** "20 Apr" — the axis form, where the year would only crowd the ticks. */
export function spcWeekTick(isoDate: string): string {
  const [year, month, day] = isoDate.split('-').map(Number)
  return new Date(year, month - 1, day).toLocaleDateString('en-PH', { day: 'numeric', month: 'short' })
}

/** "+1.8" / "-0.5" — a deviation only means something with its sign attached. */
export function spcSignedDays(value: number): string {
  return `${value >= 0 ? '+' : ''}${value.toFixed(1)}`
}

/**
 * Where one week sat relative to the office's normal range.
 *
 * Derived from the server's own limits rather than from `point.status` alone,
 * because the status says *that* a week was outside and this says *which side*
 * — and the two sides mean opposite things to a reader.
 */
export function spcWeekSide(
  point: Pick<ProcessingTimePoint, 'mean_days' | 'status'>,
  department: Pick<ProcessingTimeDepartment, 'lcl' | 'ucl'>,
): 'normal' | 'slower' | 'faster' {
  if (point.status !== 'out_of_control') return 'normal'
  return point.mean_days > department.ucl ? 'slower' : 'faster'
}

/** Filled marker on the weeks that left the range, hollow royal dot elsewhere. */
function WeekDot(props: {
  cx?: number
  cy?: number
  payload?: ProcessingTimePoint & { side?: 'normal' | 'slower' | 'faster' }
}) {
  const { cx, cy, payload } = props
  if (cx === undefined || cy === undefined || !payload) return null
  const side = payload.side ?? 'normal'
  const colour = side === 'slower' ? SLOW : side === 'faster' ? FAST : ROYAL
  return (
    <circle
      cx={cx}
      cy={cy}
      r={side === 'normal' ? 3 : 5}
      fill={side === 'normal' ? '#ffffff' : colour}
      stroke={colour}
      strokeWidth={2}
    />
  )
}

export function SpcControlChart({ department }: { department: ProcessingTimeDepartment }) {
  const points = department.points ?? []

  const data = points.map((point) => ({
    ...point,
    tick: spcWeekTick(point.week_start),
    side: spcWeekSide(point, department),
  }))

  /*
   * Pin the vertical axis around the data AND the limits together. recharts'
   * automatic domain fits the plotted series only, so on a calm office — where
   * every week sits comfortably inside — the edges of the range would fall off
   * the top and bottom of the picture, and the one thing the chart exists to
   * show would not be in it.
   */
  const means = points.map((point) => point.mean_days)
  const low = Math.min(department.lcl, ...means)
  const high = Math.max(department.ucl, ...means)
  const pad = (high - low) * 0.1 || 1
  const domain: [number, number] = [Math.max(0, low - pad), high + pad]

  const outside = data.filter((point) => point.side !== 'normal').length

  /*
   * The chart's accessible name. A screen reader hears the finding — the range,
   * the centre, how many weeks left it — instead of "graphic", and the table
   * below carries the week-by-week detail for anyone who wants it.
   */
  const summary =
    `${department.name} weekly average processing time, ${points.length} weeks. ` +
    `A normal week averages ${department.center.toFixed(1)} days and the normal range runs from ` +
    `${department.lcl.toFixed(1)} to ${department.ucl.toFixed(1)} days. ` +
    (outside === 0
      ? 'No week went beyond that range.'
      : `${outside} week${outside === 1 ? '' : 's'} went beyond that range.`)

  return (
    <figure className="m-0 rounded-2xl bg-white p-4 shadow-card">
      <div role="img" aria-label={summary}>
        <ResponsiveContainer width="100%" height={224}>
          <LineChart data={data} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
            <CartesianGrid stroke={GRID} strokeDasharray="3 3" vertical={false} />
            {/* The normal range: everything between the two limits, shaded. */}
            <ReferenceArea y1={department.lcl} y2={department.ucl} fill={BAND} fillOpacity={0.9} />
            <ReferenceLine y={department.ucl} stroke={ROYAL} strokeDasharray="4 4" strokeOpacity={0.7} />
            <ReferenceLine y={department.lcl} stroke={ROYAL} strokeDasharray="4 4" strokeOpacity={0.7} />
            <ReferenceLine y={department.center} stroke={ROYAL} strokeOpacity={0.35} />
            <XAxis
              dataKey="tick"
              tick={AXIS_TICK}
              tickLine={false}
              axisLine={{ stroke: GRID }}
              minTickGap={24}
            />
            <YAxis
              domain={domain}
              tickFormatter={(value: number) => value.toFixed(1)}
              tick={AXIS_TICK}
              tickLine={false}
              axisLine={false}
              width={38}
            />
            <Tooltip
              formatter={(value, _name, item) => {
                const point = item?.payload as (ProcessingTimePoint & { side?: string }) | undefined
                const side = point?.side ?? 'normal'
                return [
                  `${Number(value).toFixed(1)} days over ${point?.reviews ?? 0} reviews`,
                  side === 'slower'
                    ? 'Slower than the normal range'
                    : side === 'faster'
                      ? 'Faster than the normal range'
                      : 'Within the normal range',
                ]
              }}
              labelFormatter={(label) => `Week of ${String(label)}`}
            />
            <Line
              type="monotone"
              dataKey="mean_days"
              name="Average days"
              stroke={ROYAL}
              strokeWidth={2.5}
              dot={<WeekDot />}
              activeDot={{ r: 6 }}
              isAnimationActive={false}
            />
          </LineChart>
        </ResponsiveContainer>
      </div>

      {/*
       * One line, not the three paragraphs this carried before. It answers the
       * two questions the client put to this chart directly (§6.1): which way
       * is good, and what the clock actually measures. Everything else moved
       * into the info hover.
       */}
      <figcaption className="mt-2 text-[13px] leading-snug text-ink-secondary">
        <span className="font-semibold text-ink">Lower is better.</span> The clock starts when a
        review reaches the office and stops when that office finishes it.
      </figcaption>

      {/* A key in words as well as colour — nothing here is carried by hue alone. */}
      <ul className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-ink-muted">
        <li className="flex items-center gap-1.5">
          <span aria-hidden="true" className="inline-block h-2.5 w-4 rounded-sm bg-[#dfe6f8] ring-1 ring-royal/40" />
          Normal range {department.lcl.toFixed(1)}&ndash;{department.ucl.toFixed(1)} days (usual{' '}
          {department.center.toFixed(1)})
        </li>
        <li className="flex items-center gap-1.5">
          <span aria-hidden="true" className="inline-block h-2.5 w-2.5 rounded-full bg-s-red-deep" />
          Slower than normal
        </li>
        <li className="flex items-center gap-1.5">
          <span aria-hidden="true" className="inline-block h-2.5 w-2.5 rounded-full bg-ink" />
          Faster than normal
        </li>
      </ul>

      {/*
       * The same figures as text. `details` rather than a visually-hidden table
       * so that a sighted reader who wants an exact week can also reach it —
       * this is the only place on the screen the individual numbers exist.
       */}
      <details className="group mt-2 border-t border-line pt-2">
        <summary className="cursor-pointer list-none text-[12px] font-semibold text-royal focus:outline-none focus-visible:ring-2 focus-visible:ring-royal">
          <span aria-hidden="true" className="mr-1 inline-block transition-transform group-open:rotate-90">
            &rsaquo;
          </span>
          Weekly figures as a table ({points.length} weeks)
        </summary>
        <div className="mt-2 max-h-64 overflow-auto">
          <table className="w-full text-left text-[12px]">
            <caption className="sr-only">
              {department.name}: weekly average processing time against its normal range
            </caption>
            <thead className="sticky top-0 bg-white">
              <tr className="border-b border-line text-[10px] uppercase tracking-wide text-ink-muted">
                <th scope="col" className="py-1.5 pr-3 font-semibold">
                  Week of
                </th>
                <th scope="col" className="py-1.5 pr-3 text-right font-semibold">
                  Average days
                </th>
                <th scope="col" className="py-1.5 pr-3 text-right font-semibold">
                  Reviews
                </th>
                <th scope="col" className="py-1.5 font-semibold">
                  Against the normal range
                </th>
              </tr>
            </thead>
            <tbody>
              {data.map((point) => (
                <tr key={point.week_start} className="border-b border-line/50 last:border-0">
                  <th scope="row" className="py-1 pr-3 font-medium text-ink">
                    {spcWeekDate(point.week_start)}
                  </th>
                  <td className="tnum py-1 pr-3 text-right text-ink">{point.mean_days.toFixed(1)}</td>
                  <td className="tnum py-1 pr-3 text-right text-ink-muted">{point.reviews}</td>
                  <td
                    className={`py-1 ${
                      point.side === 'slower'
                        ? 'font-semibold text-s-red'
                        : point.side === 'faster'
                          ? 'font-semibold text-ink'
                          : 'text-ink-muted'
                    }`}
                  >
                    {point.side === 'slower' ? 'Slower' : point.side === 'faster' ? 'Faster' : 'Within'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </figure>
  )
}
