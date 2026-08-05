import { Bar, BarChart, Tooltip, XAxis, YAxis } from 'recharts'
import type { ReactNode } from 'react'
import {
  CHART_AXIS_TICK,
  CHART_TOOLTIP,
  ChartFrame,
} from './ChartFrame'
import type { ChartLegendItem, ChartTableRow } from './ChartFrame'

/*
 * The horizontal stacked bar the spec asks for on Inspections.
 *
 * WHAT A STACK CAN AND CANNOT SAY. Stacking only tells the truth when the
 * segments are parts of one whole. Passed + failed + conditional IS a whole —
 * it is every inspection actually carried out — so those three stack. Scheduled
 * is NOT a fourth segment: it is the denominator of a different question ("how
 * much of the plan got done"), and adding it to the stack would double-count
 * every completed inspection and make the longest bar the office with the
 * biggest backlog. Scheduled travels as text on the row instead.
 *
 * Segments are ordered best-to-worst left to right and are never distinguished
 * by colour alone: the legend prints each segment's count, and the sr-only table
 * carries the full grid.
 */

export type StackSeries = { key: string; label: string; color: string }

export type StackRow = {
  key: string
  label: string
  /** Segment key → count. Missing keys render as zero-width, not as gaps. */
  values: Record<string, number>
  /** Written at the end of the bar, e.g. "83.7% pass rate". */
  valueText: string
  /** Extra columns for the sr-only table, in `extraHeadings` order. */
  extras?: string[]
}

export function StackedBars({
  title,
  series,
  rows,
  categoryHeading,
  extraHeadings = [],
  categoryWidth = 96,
  rowHeight = 34,
  footer,
}: {
  title: string
  series: StackSeries[]
  rows: StackRow[]
  categoryHeading: string
  extraHeadings?: string[]
  categoryWidth?: number
  rowHeight?: number
  footer?: ReactNode
}) {
  const data = rows.map((row) => ({ label: row.label, ...row.values }))
  const height = Math.max(96, rows.length * rowHeight + 24)

  /*
   * The legend totals each segment across every row, so it says something the
   * bars do not: "918 passed in all" is the figure a reader repeats out loud.
   * Per-row segment counts stay in the table rather than crowding the plot.
   */
  const legend: ChartLegendItem[] = series.map((s) => ({
    key: s.key,
    label: s.label,
    value: rows.reduce((sum, row) => sum + (row.values[s.key] ?? 0), 0).toLocaleString(),
    color: s.color,
  }))

  const tableRows: ChartTableRow[] = rows.map((row) => ({
    header: row.label,
    cells: [
      ...series.map((s) => (row.values[s.key] ?? 0).toLocaleString()),
      row.valueText,
      ...(row.extras ?? []),
    ],
  }))

  return (
    <ChartFrame
      title={title}
      height={height}
      columns={[categoryHeading, ...series.map((s) => s.label), 'Pass rate', ...extraHeadings]}
      rows={tableRows}
      legend={legend}
      legendColumns={3}
      footer={footer}
    >
      <BarChart
        data={data}
        layout="vertical"
        margin={{ top: 2, right: 4, left: 0, bottom: 2 }}
        barCategoryGap="26%"
      >
        <XAxis type="number" hide />
        <YAxis
          type="category"
          dataKey="label"
          tick={CHART_AXIS_TICK}
          tickLine={false}
          axisLine={false}
          width={categoryWidth}
          interval={0}
        />
        <Tooltip
          cursor={{ fill: 'rgb(50 66 202 / 0.06)' }}
          formatter={(value, name) => [Number(value).toLocaleString(), String(name)]}
          {...CHART_TOOLTIP}
        />
        {series.map((s, i) => (
          <Bar
            key={s.key}
            dataKey={s.key}
            name={s.label}
            stackId="outcome"
            fill={s.color}
            isAnimationActive={false}
            maxBarSize={18}
            // Only the outermost segment rounds, or every internal boundary
            // grows a notch that reads as a gap in the data.
            radius={
              i === 0 ? [5, 0, 0, 5] : i === series.length - 1 ? [0, 5, 5, 0] : undefined
            }
          />
        ))}
      </BarChart>
    </ChartFrame>
  )
}
