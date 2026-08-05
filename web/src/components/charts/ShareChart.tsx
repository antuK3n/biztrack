import { Cell, Pie, PieChart, Tooltip } from 'recharts'
import type { ReactNode } from 'react'
import { CHART_TOOLTIP, ChartFrame } from './ChartFrame'
import type { ChartLegendItem } from './ChartFrame'

/*
 * The donut and the pie — two settings of one chart, because that is genuinely
 * all that separates them in recharts (`innerRadius`), and the client's spec
 * asks for both: a donut for Decision Outcomes, a pie for Form of Organization.
 *
 * WHY THE LEGEND IS NOT OPTIONAL HERE. A pie is the least readable of the chart
 * types on this dashboard: slice areas are hard to compare by eye, and the only
 * thing tying a slice to its meaning is its colour. So every share chart in this
 * product prints label + count + share as text beside its swatch. That is
 * DESIGN.md's Never Color Alone rule, and it is also just the difference between
 * a reader knowing that Corporation is 102 and guessing that it is "about a
 * seventh". Slice labels drawn inside the wedges were tried and dropped — at
 * four categories with one dominant slice, three of the four labels have nowhere
 * to sit.
 */

export type ShareSlice = {
  key: string
  label: string
  value: number
  /** Written out for the legend and the table, e.g. "102". */
  valueText: string
  /** The share as text, e.g. "14.2%". Optional: some series have no denominator. */
  shareText?: string
  color: string
}

export function ShareChart({
  title,
  slices,
  variant,
  center,
  categoryHeading,
  valueHeading,
  shareHeading,
  height = 176,
  legendColumns = 2,
  footer,
}: {
  title: string
  slices: ShareSlice[]
  /** `donut` leaves a hole for `center`; `pie` is solid. */
  variant: 'donut' | 'pie'
  /**
   * The headline figure printed in a donut's hole. This is the only place the
   * chart states something the slices do not — an approval rate is a ratio over
   * a subset of the slices, not one of them — so it carries its own label.
   */
  center?: { value: string; label: string }
  categoryHeading: string
  valueHeading: string
  shareHeading?: string
  height?: number
  legendColumns?: 1 | 2 | 3
  footer?: ReactNode
}) {
  const legend: ChartLegendItem[] = slices.map((slice) => ({
    key: slice.key,
    label: slice.label,
    value: slice.valueText,
    note: slice.shareText,
    color: slice.color,
  }))

  const columns = shareHeading
    ? [categoryHeading, valueHeading, shareHeading]
    : [categoryHeading, valueHeading]

  const rows = slices.map((slice) => ({
    header: slice.label,
    cells: shareHeading ? [slice.valueText, slice.shareText ?? '—'] : [slice.valueText],
  }))

  return (
    <div className="relative">
      <ChartFrame
        title={title}
        height={height}
        columns={columns}
        rows={rows}
        legend={legend}
        legendColumns={legendColumns}
        footer={footer}
      >
        <PieChart margin={{ top: 0, right: 0, left: 0, bottom: 0 }}>
          <Tooltip
            formatter={(value, name) => [Number(value).toLocaleString(), String(name)]}
            {...CHART_TOOLTIP}
          />
          <Pie
            data={slices}
            dataKey="value"
            nameKey="label"
            cx="50%"
            cy="50%"
            innerRadius={variant === 'donut' ? '58%' : 0}
            outerRadius="92%"
            /*
             * A white hairline between wedges, so two adjacent slices remain two
             * slices for a reader who cannot separate their hues. paddingAngle
             * was the alternative and is worse: it opens gaps that read as
             * missing data on a chart whose whole claim is that the parts sum to
             * the whole.
             */
            stroke="#ffffff"
            strokeWidth={2}
            isAnimationActive={false}
          >
            {slices.map((slice) => (
              <Cell key={slice.key} fill={slice.color} />
            ))}
          </Pie>
        </PieChart>
      </ChartFrame>

      {/*
        The hole's contents are positioned over the plot rather than drawn into
        the SVG, so they inherit the product's type and are selectable text. The
        wrapper is aria-hidden because ChartFrame's table already states the
        same figure — announcing it twice is noise, not redundancy.
      */}
      {variant === 'donut' && center && (
        <div
          className="pointer-events-none absolute inset-x-0 top-0 flex flex-col items-center justify-center"
          style={{ height }}
          aria-hidden="true"
        >
          <p className="tnum text-[24px] font-bold leading-none text-royal">{center.value}</p>
          <p className="mt-1 max-w-[7.5rem] text-center text-[10px] leading-tight text-ink-muted">
            {center.label}
          </p>
        </div>
      )}
    </div>
  )
}
