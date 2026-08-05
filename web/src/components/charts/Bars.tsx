import { Bar, BarChart, Cell, LabelList, ReferenceLine, Tooltip, XAxis, YAxis } from 'recharts'
import type { ReactNode } from 'react'
import {
  CHART_AXIS_TICK,
  CHART_GRID,
  CHART_MUTED,
  CHART_ROYAL,
  CHART_TOOLTIP,
  ChartFrame,
} from './ChartFrame'
import type { ChartLegendItem, ChartTableRow } from './ChartFrame'

/*
 * The two bar shapes the client's spec asks for by name: a vertical "bar chart"
 * and a "horizontal bar chart". They are separate components rather than one
 * component with a `layout` prop because almost nothing else about them is
 * shared — a vertical chart labels its categories along the bottom in the space
 * of one tick, a horizontal one gives each category a full-width gutter, and the
 * two want different axis widths, label positions and heights. Merging them
 * produced a component that was mostly branches.
 */

export type BarDatum = {
  key: string
  /** Shown on the axis. Keep it short; the full name belongs in `title`. */
  label: string
  value: number
  /** The value written out, e.g. "31" or "5.5 days". Used in the table and label. */
  valueText: string
  /** Optional second column in the sr-only table, e.g. a percentage share. */
  note?: string
  /** Overrides the default royal/muted ramp — used where a bar means "breaching". */
  color?: string
}

function tableRows(data: BarDatum[], hasNote: boolean): ChartTableRow[] {
  return data.map((row) => ({
    header: row.label,
    cells: hasNote ? [row.valueText, row.note ?? '—'] : [row.valueText],
  }))
}

/**
 * Which bar gets the full-strength royal.
 *
 * The product's convention across every ranked panel: the leader is royal and
 * the rest are the muted blue, so the eye lands on the answer without the chart
 * needing a second colour dimension it does not have data for. Height already
 * encodes the ranking; colour is only pointing at the top of it.
 */
function rampColor(row: BarDatum, index: number): string {
  return row.color ?? (index === 0 ? CHART_ROYAL : CHART_MUTED)
}

/* ── vertical ───────────────────────────────────────────────────────────── */

export function VerticalBars({
  title,
  data,
  valueHeading,
  noteHeading,
  categoryHeading,
  height = 168,
  tooltipUnit,
  legend,
  legendColumns,
  footer,
}: {
  title: string
  data: BarDatum[]
  categoryHeading: string
  valueHeading: string
  noteHeading?: string
  height?: number
  /** Appended in the hover tooltip, e.g. "businesses". */
  tooltipUnit?: string
  /**
   * Only needed when the axis cannot carry the category names — a ranking of
   * PSIC industries, say, where the bars are labelled 1..5 and this is where the
   * names actually live.
   */
  legend?: ChartLegendItem[]
  legendColumns?: 1 | 2 | 3
  footer?: ReactNode
}) {
  const columns = noteHeading
    ? [categoryHeading, valueHeading, noteHeading]
    : [categoryHeading, valueHeading]

  return (
    <ChartFrame
      title={title}
      height={height}
      columns={columns}
      rows={tableRows(data, Boolean(noteHeading))}
      legend={legend}
      legendColumns={legendColumns}
      footer={footer}
    >
      <BarChart data={data} margin={{ top: 18, right: 4, left: 4, bottom: 0 }}>
        <XAxis
          dataKey="label"
          tick={CHART_AXIS_TICK}
          tickLine={false}
          axisLine={{ stroke: CHART_GRID }}
          interval={0}
        />
        {/* Hidden, not absent: the bars still need a scale, but a numeric gutter
            beside labelled bars is spend without return on a dense dashboard. */}
        <YAxis hide />
        <Tooltip
          cursor={{ fill: 'rgb(50 66 202 / 0.06)' }}
          formatter={(value) => [
            `${Number(value).toLocaleString()}${tooltipUnit ? ` ${tooltipUnit}` : ''}`,
            valueHeading,
          ]}
          {...CHART_TOOLTIP}
        />
        {/*
          minPointSize is what makes a genuine zero readable. Without it recharts
          draws nothing for a zero-count category AND drops its label, so an
          empty month of amendments looks identical to a category the payload
          forgot to send — which is exactly the null-versus-zero confusion this
          screen is built to avoid. Two pixels plus the printed "0" says the
          count was measured and it was none.
        */}
        <Bar
          dataKey="value"
          radius={[5, 5, 0, 0]}
          isAnimationActive={false}
          maxBarSize={68}
          minPointSize={2}
        >
          {data.map((row, i) => (
            <Cell key={row.key} fill={rampColor(row, i)} />
          ))}
          <LabelList
            dataKey="valueText"
            position="top"
            offset={6}
            fontSize={12}
            fontWeight={600}
            fill="#1a1f2b"
          />
        </Bar>
      </BarChart>
    </ChartFrame>
  )
}

/* ── horizontal ─────────────────────────────────────────────────────────── */

/**
 * A reference line drawn across every bar — the RA 11032 statutory limit, on the
 * one chart that has a legal threshold to be measured against.
 */
export type BarReference = { value: number; label: string; color: string }

export function HorizontalBars({
  title,
  data,
  categoryHeading,
  valueHeading,
  noteHeading,
  categoryWidth = 116,
  rowHeight = 30,
  reference,
  domainMax,
  tooltipUnit,
  footer,
}: {
  title: string
  data: BarDatum[]
  categoryHeading: string
  valueHeading: string
  noteHeading?: string
  categoryWidth?: number
  rowHeight?: number
  reference?: BarReference
  domainMax?: number
  tooltipUnit?: string
  footer?: ReactNode
}) {
  const columns = noteHeading
    ? [categoryHeading, valueHeading, noteHeading]
    : [categoryHeading, valueHeading]

  /*
   * Height is derived from the row count rather than fixed. A fixed height with
   * three rows leaves bars fat enough to look like a different chart from the
   * seven-row one next to it, and the client's note about "large spaces" is
   * exactly what a fixed height produces when the data is short.
   */
  // The reference line's caption sits above the plot, so the chart has to be
  // told to leave room for it — otherwise it renders clipped by the SVG edge.
  const topMargin = reference ? 18 : 4
  const height = Math.max(96, data.length * rowHeight + 28 + topMargin)

  return (
    <ChartFrame
      title={title}
      height={height}
      columns={columns}
      rows={tableRows(data, Boolean(noteHeading))}
      footer={footer}
    >
      <BarChart
        data={data}
        layout="vertical"
        margin={{ top: topMargin, right: 56, left: 0, bottom: 4 }}
        barCategoryGap="22%"
      >
        <XAxis
          type="number"
          hide
          domain={domainMax === undefined ? [0, 'dataMax'] : [0, domainMax]}
        />
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
          formatter={(value) => [
            `${Number(value).toLocaleString()}${tooltipUnit ? ` ${tooltipUnit}` : ''}`,
            valueHeading,
          ]}
          {...CHART_TOOLTIP}
        />
        {reference && (
          <ReferenceLine
            x={reference.value}
            stroke={reference.color}
            strokeWidth={1.5}
            strokeDasharray="4 3"
            label={{
              value: reference.label,
              position: 'top',
              fontSize: 10,
              fontWeight: 700,
              fill: reference.color,
            }}
          />
        )}
        <Bar dataKey="value" radius={[0, 5, 5, 0]} isAnimationActive={false} maxBarSize={16}>
          {data.map((row, i) => (
            <Cell key={row.key} fill={rampColor(row, i)} />
          ))}
          <LabelList
            dataKey="valueText"
            position="right"
            offset={7}
            fontSize={12}
            fontWeight={600}
            fill="#1a1f2b"
          />
        </Bar>
      </BarChart>
    </ChartFrame>
  )
}
