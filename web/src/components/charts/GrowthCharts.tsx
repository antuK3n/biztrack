import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  LabelList,
  Line,
  LineChart,
  Pie,
  PieChart,
  ReferenceLine,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import type {
  BarangayGrowthRow,
  BusinessStatusRow,
  IndustryGrowthRow,
  SurvivalPoint,
} from '../../lib/types'
import {
  GROWTH_AXIS_TICK,
  GROWTH_GRID,
  GROWTH_ROYAL,
  GROWTH_SERIES,
  GROWTH_STATUS_COLORS,
  GROWTH_TOOLTIP,
  GROWTH_DOWN,
  GrowthChartFrame,
  GrowthLegend,
} from './GrowthChartFrame'

/*
 * The five charts of Business Growth Analysis (r-integration spec §4).
 *
 * The spec names a visual for each report and the naming is not decorative —
 * the client asked specifically that "proper visualization is followed, whether
 * it is donut chart, etc." So: donut for the status split, horizontal bars for
 * the barangay ranking, a line across renewal cycles for renewal performance,
 * a line across months for closures, and one line per industry for the industry
 * trend. Nothing here picks its own chart type.
 *
 * Every one of them is wrapped in GrowthChartFrame, which is what carries the
 * numbers into the accessibility tree — see the long note in that file.
 *
 * Nothing in here computes a statistic. Every figure arrives from
 * /analytics/business-growth already derived (App\Support\BusinessGrowthAnalytics,
 * or R's POST /growth/lifecycle) and is drawn as given, so the screen and the
 * PDF can never disagree about a number.
 */

function signed(value: number): string {
  return `${value >= 0 ? '+' : ''}${value}`
}

function pct(value: number | null): string {
  return value === null ? 'not available' : `${value.toFixed(1)}%`
}

/* ── Business Status Summary — donut ───────────────────────────────────── */

/**
 * Active / Expired / Closed / Inactive as proportional segments, per the spec.
 *
 * The legend sits beside the ring rather than under it and carries the count
 * and the share as text, because a ring on its own answers "roughly how much"
 * and this screen is read by people who have to write the exact figure into a
 * report.
 */
export function GrowthStatusDonut({ rows }: { rows: BusinessStatusRow[] }) {
  const total = rows.reduce((sum, row) => sum + row.count, 0)

  return (
    <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] sm:items-center">
      <GrowthChartFrame
        label="Business Status Summary"
        summary={`${total.toLocaleString()} businesses on the register, split four ways`}
        columns={['Status', 'Businesses', 'Share']}
        rows={rows.map((row) => ({
          cells: [row.label, row.count.toLocaleString(), pct(row.share)],
        }))}
        height={160}
        overlay={
          <>
            <span className="tnum text-[19px] font-bold leading-none text-ink">
              {total.toLocaleString()}
            </span>
            <span className="mt-0.5 text-[10px] text-ink-muted">on register</span>
          </>
        }
      >
        <PieChart margin={{ top: 4, right: 4, bottom: 4, left: 4 }}>
          <Pie
            data={rows}
            dataKey="count"
            nameKey="label"
            innerRadius="62%"
            outerRadius="94%"
            paddingAngle={1}
            stroke="#fff"
            strokeWidth={2}
            isAnimationActive={false}
          >
            {rows.map((row) => (
              <Cell key={row.status} fill={GROWTH_STATUS_COLORS[row.status] ?? GROWTH_ROYAL} />
            ))}
          </Pie>
          <Tooltip
            {...GROWTH_TOOLTIP}
            formatter={(value, name) => [`${Number(value).toLocaleString()} businesses`, name]}
          />
        </PieChart>
      </GrowthChartFrame>

      <GrowthLegend
        items={rows.map((row) => ({
          color: GROWTH_STATUS_COLORS[row.status] ?? GROWTH_ROYAL,
          label: row.label,
          value: `${row.count.toLocaleString()} · ${pct(row.share)}`,
        }))}
      />
    </div>
  )
}

/* ── Top Growing Barangays — horizontal bars ───────────────────────────── */

/**
 * Ranked by the INCREASE, which is why the bar plots `delta` and not volume.
 *
 * Scaling by how many businesses a barangay has would put the longest bar on
 * whichever barangay is biggest, which the panel already knows and nobody
 * needs a chart to learn. The number sits on the end of every bar so the exact
 * change is readable without a tooltip and without the colour.
 */
export function GrowthBarangayBars({ rows }: { rows: BarangayGrowthRow[] }) {
  return (
    <GrowthChartFrame
      label="Top Growing Barangays"
      summary="Change in new registrations against the previous period of the same length"
      columns={['Barangay', 'Change', 'New this period', 'Previous period', 'Growth rate']}
      rows={rows.map((row) => ({
        cells: [
          row.barangay,
          signed(row.delta),
          row.registrations,
          row.prior,
          row.growth_rate === null ? 'no prior registrations' : `${signed(row.growth_rate)}%`,
        ],
      }))}
      height={Math.max(140, rows.length * 26 + 24)}
    >
      <BarChart data={rows} layout="vertical" margin={{ top: 4, right: 30, bottom: 4, left: 4 }}>
        <CartesianGrid stroke={GROWTH_GRID} strokeDasharray="3 3" horizontal={false} />
        <XAxis type="number" tick={GROWTH_AXIS_TICK} tickLine={false} axisLine={false} hide />
        {/*
          interval={0} because recharts' automatic tick thinning is written for
          a continuous axis, where dropping every other tick loses nothing. On a
          category axis it drops every other BARANGAY NAME, leaving bars with no
          label at all — the reader can see that somewhere grew by 13 and cannot
          see where. Every category on a ranked list gets its name.
        */}
        <YAxis
          type="category"
          dataKey="barangay"
          interval={0}
          tick={GROWTH_AXIS_TICK}
          tickLine={false}
          axisLine={{ stroke: GROWTH_GRID }}
          width={88}
        />
        <ReferenceLine x={0} stroke={GROWTH_GRID} />
        <Tooltip
          {...GROWTH_TOOLTIP}
          cursor={{ fill: '#eef2fc' }}
          formatter={(value) => [`${signed(Number(value))} registrations`, 'Change']}
        />
        <Bar dataKey="delta" barSize={13} radius={[0, 4, 4, 0]} isAnimationActive={false}>
          {rows.map((row) => (
            <Cell key={row.barangay} fill={row.delta < 0 ? GROWTH_DOWN : GROWTH_ROYAL} />
          ))}
          <LabelList
            dataKey="delta"
            position="right"
            formatter={(value) => (value === undefined ? '' : signed(Number(value)))}
            style={{ fontSize: 11, fontWeight: 700, fill: '#14171d' }}
          />
        </Bar>
      </BarChart>
    </GrowthChartFrame>
  )
}

/* ── Business Renewal Performance — line across renewal cycles ─────────── */

/**
 * The spec's Business Renewal Performance: compliance across renewal periods.
 *
 * It is a curve and not a single ratio on purpose. The spec has R fit this with
 * the `survival` package, which follows one group of businesses through renewal
 * after renewal, so the answer is a rate per cycle. Collapsing it to one number
 * would throw away the shape, which is the only part that says whether the city
 * loses businesses at the first renewal or slowly over years.
 *
 * The at-risk count rides along on every point because a late cycle can rest on
 * very few businesses — 117 behind cycle 3 where 451 stood behind cycle 1 — and
 * a reader who cannot see that has no way to weigh the last point.
 */
export function GrowthRenewalCurve({ points }: { points: SurvivalPoint[] }) {
  const data = points.map((point) => ({ ...point, label: `Renewal ${point.cycle}` }))

  return (
    <GrowthChartFrame
      label="Business Renewal Performance"
      summary="Share of the cohort still renewing on time at each renewal period"
      columns={['Renewal period', 'Still renewing on time', 'Businesses that reached it', 'Lapses']}
      rows={data.map((point) => ({
        cells: [point.label, pct(point.survival), point.at_risk, point.lapses],
      }))}
      height={180}
    >
      {/*
        left margin 0, not negative. Pulling the plot left to reclaim the gutter
        drags the Y axis off the canvas with it, and recharts clips rather than
        wraps: "100%" renders as "0%", which is not tight spacing, it is a wrong
        number. The XAxis padding is what keeps the first and last cycle labels
        from being half-cut at the edges, since a three-point line puts them
        exactly on the boundary.
      */}
      <LineChart data={data} margin={{ top: 8, right: 14, bottom: 4, left: 0 }}>
        <CartesianGrid stroke={GROWTH_GRID} strokeDasharray="3 3" vertical={false} />
        <XAxis
          dataKey="label"
          interval={0}
          padding={{ left: 30, right: 30 }}
          tick={GROWTH_AXIS_TICK}
          tickLine={false}
          axisLine={{ stroke: GROWTH_GRID }}
        />
        <YAxis
          domain={[0, 100]}
          tick={GROWTH_AXIS_TICK}
          tickLine={false}
          axisLine={false}
          width={42}
          tickFormatter={(value) => `${value}%`}
        />
        <Tooltip
          {...GROWTH_TOOLTIP}
          formatter={(value, _name, item) => [
            `${Number(value).toFixed(1)}% of ${item?.payload?.at_risk ?? '—'} businesses`,
            'Still renewing on time',
          ]}
        />
        <Line
          type="monotone"
          dataKey="survival"
          name="Renewal performance"
          stroke={GROWTH_ROYAL}
          strokeWidth={2.5}
          dot={{ r: 3.5, fill: GROWTH_ROYAL }}
          activeDot={{ r: 5 }}
          isAnimationActive={false}
        />
      </LineChart>
    </GrowthChartFrame>
  )
}

/* ── Business Closure Trend — closures per reporting period ────────────── */

export function GrowthClosureTrend({
  data,
}: {
  data: { month: string; label: string; closures: number }[]
}) {
  return (
    <GrowthChartFrame
      label="Business Closure Trend"
      summary="Registrations removed each month across the period"
      columns={['Month', 'Closures']}
      rows={data.map((row) => ({ cells: [row.label, row.closures] }))}
      height={180}
    >
      {/* left margin 0: see the note on the renewal curve — a negative gutter
          clips the Y axis into a different number. */}
      <LineChart data={data} margin={{ top: 8, right: 14, bottom: 4, left: 0 }}>
        <CartesianGrid stroke={GROWTH_GRID} strokeDasharray="3 3" vertical={false} />
        <XAxis
          dataKey="label"
          tick={GROWTH_AXIS_TICK}
          tickLine={false}
          axisLine={{ stroke: GROWTH_GRID }}
          minTickGap={14}
        />
        <YAxis
          tick={GROWTH_AXIS_TICK}
          tickLine={false}
          axisLine={false}
          allowDecimals={false}
          width={34}
        />
        <Tooltip {...GROWTH_TOOLTIP} formatter={(value) => [`${Number(value)}`, 'Closures']} />
        <Line
          type="monotone"
          dataKey="closures"
          name="Closures"
          stroke={GROWTH_ROYAL}
          strokeWidth={2.5}
          dot={{ r: 3, fill: GROWTH_ROYAL }}
          activeDot={{ r: 5 }}
          isAnimationActive={false}
        />
      </LineChart>
    </GrowthChartFrame>
  )
}

/* ── Business Industry Growth Trend — one line per industry ────────────── */

/**
 * A line per industry, colour-coded and named, exactly as the spec asks.
 *
 * ── Two points per line, and why that is the honest maximum ─────────────────
 *
 * /analytics/business-growth returns each industry once, with this period's new
 * registrations and the matching count for the period before. That is two real,
 * comparable observations per industry and no more — the payload carries no
 * monthly or yearly series per PSIC code. So each line runs from the previous
 * period to this one, and the axis is labelled with the actual date ranges
 * rather than with invented intermediate points. Interpolating a month-by-month
 * industry curve out of two totals would draw numbers nobody computed.
 *
 * The dataKey is the PSIC code because two lines of business can share a name
 * in the register; the code cannot collide. It is digits only, so recharts
 * reads it as a plain key rather than as a nested path.
 */
export function GrowthIndustryTrend({
  rows,
  priorLabel,
  currentLabel,
}: {
  rows: IndustryGrowthRow[]
  priorLabel: string
  currentLabel: string
}) {
  const series = rows.map((row, i) => ({
    row,
    ...GROWTH_SERIES[i % GROWTH_SERIES.length],
  }))

  const priorPoint: Record<string, number | string> = { period: priorLabel }
  const currentPoint: Record<string, number | string> = { period: currentLabel }
  for (const row of rows) {
    priorPoint[row.psic_code] = row.prior
    currentPoint[row.psic_code] = row.registrations
  }

  return (
    <div className="grid gap-3 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)] lg:items-center">
      <GrowthChartFrame
        label="Business Industry Growth Trend"
        summary={`New registrations per industry, ${priorLabel} against ${currentLabel}`}
        columns={['Industry', 'PSIC code', priorLabel, currentLabel, 'Change', 'On record today']}
        rows={rows.map((row) => ({
          cells: [
            row.industry,
            row.psic_code,
            row.prior,
            row.registrations,
            `${signed(row.delta)} (${row.direction})`,
            row.count,
          ],
        }))}
        height={190}
      >
        <LineChart
          data={[priorPoint, currentPoint]}
          margin={{ top: 8, right: 16, bottom: 4, left: 0 }}
        >
          <CartesianGrid stroke={GROWTH_GRID} strokeDasharray="3 3" vertical={false} />
          <XAxis
            dataKey="period"
            interval={0}
            padding={{ left: 56, right: 56 }}
            tick={GROWTH_AXIS_TICK}
            tickLine={false}
            axisLine={{ stroke: GROWTH_GRID }}
          />
          <YAxis
            tick={GROWTH_AXIS_TICK}
            tickLine={false}
            axisLine={false}
            allowDecimals={false}
            width={34}
          />
          <Tooltip
            {...GROWTH_TOOLTIP}
            formatter={(value, name) => [`${Number(value)} new`, name]}
          />
          {series.map(({ row, color, dash }) => (
            <Line
              key={row.psic_code}
              type="linear"
              dataKey={row.psic_code}
              name={row.industry}
              stroke={color}
              strokeWidth={2.5}
              strokeDasharray={dash}
              dot={{ r: 3, fill: color, strokeWidth: 0 }}
              activeDot={{ r: 5 }}
              isAnimationActive={false}
            />
          ))}
        </LineChart>
      </GrowthChartFrame>

      <GrowthLegend
        variant="line"
        items={series.map(({ row, color, dash }) => ({
          color,
          dash,
          label: row.industry,
          value: `${signed(row.delta)} · ${row.direction}`,
        }))}
      />
    </div>
  )
}
