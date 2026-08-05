import { useId } from 'react'
import type { ReactElement, ReactNode } from 'react'
import { ResponsiveContainer } from 'recharts'

/*
 * The plumbing every chart on Business Growth Analysis shares.
 *
 * ── Why a frame and not just <ResponsiveContainer> ──────────────────────────
 *
 * recharts draws an SVG made of <path> and <g>. There is no text in it for a
 * screen reader to read, and no amount of aria on the wrapper invents any: an
 * aria-label of "Business Closure Trend" tells a blind officer the chart exists
 * and nothing whatsoever about the closures. PRODUCT.md sets WCAG 2.1 AA as the
 * floor, and a figure that only exists as a shape fails it outright.
 *
 * So every chart here ships twice. The SVG is marked aria-hidden — it is
 * decoration as far as assistive tech is concerned — and the same numbers are
 * rendered again as a real <table> in the accessibility tree, visually hidden.
 * That is not a fallback or a courtesy: it is the only copy of the data a
 * non-sighted reader gets, so it carries every series and every point the chart
 * plots, and it is built from the same array the chart is built from rather
 * than typed out separately, which is how the two would drift.
 *
 * The <figure>/<figcaption> pairing gives the whole thing an accessible name,
 * so the chart is announced as a named figure rather than as an unlabelled
 * graphic somewhere in the middle of the page.
 */

/**
 * Series colours for anything that plots more than one line at a time.
 *
 * Two rules bind this palette and neither is negotiable.
 *
 * DESIGN.md's "Red Means Stop": #bd0000 is for errors and destructive actions.
 * Nothing on this screen is an error — a declining industry, a falling growth
 * rate and a closure count are all just findings — so no red appears here at
 * all. The warm end of the ramp stops at amber.
 *
 * DESIGN.md's "Never Color Alone": ~1 in 12 men cannot separate some of these
 * hues, and six lines is more than any palette separates reliably anyway. So
 * each series also carries its own dash pattern, and every series is named in
 * the legend beside the chart and again in the hidden table. Turn the colour
 * off entirely and the chart is still readable.
 *
 * Every colour clears 4.5:1 on white, because these same values are used for
 * the legend's text and not only for its swatch.
 */
export const GROWTH_SERIES = [
  { color: '#1d4b9e', dash: undefined }, // royal-deep, solid
  { color: '#b5620a', dash: '7 4' }, // amber-800, dashed
  { color: '#125c3b', dash: '2 3' }, // deep green (DESIGN.md's darkened green-700), dotted
  { color: '#7a4bd0', dash: '13 4' }, // s-purple, long dash
  { color: '#3d4453', dash: '9 3 2 3' }, // ink-secondary, dash-dot
  { color: '#0b6a6a', dash: '2 3 9 3' }, // deep teal, dot-dash
] as const

/**
 * The four lifecycle states, in the fixed order the server sends them.
 *
 * Closed is purple rather than red on purpose. A business that closed did not
 * do anything wrong and nothing on this screen needs stopping — see the Red
 * Means Stop note above.
 */
export const GROWTH_STATUS_COLORS: Record<string, string> = {
  active: '#1d4b9e',
  expired: '#b5620a',
  inactive: '#5b6472',
  closed: '#7a4bd0',
}

/** Rising is a finding, not a success; falling is a finding, not an error. */
export const GROWTH_UP = '#125c3b'
export const GROWTH_DOWN = '#b5620a'
export const GROWTH_FLAT = '#5b6472'

export const GROWTH_ROYAL = '#3242ca'
export const GROWTH_GRID = '#c5cfe0'
export const GROWTH_AXIS_TICK = { fontSize: 11, fill: '#5b6472' } as const

/** recharts' default tooltip is a bare white box; this one obeys the tokens. */
export const GROWTH_TOOLTIP = {
  contentStyle: {
    borderRadius: 10,
    border: '1px solid #c5cfe0',
    boxShadow: '0 6px 16px rgba(20, 23, 29, 0.12)',
    fontSize: 12,
  },
  labelStyle: { color: '#14171d', fontWeight: 600 },
} as const

export interface GrowthTableRow {
  /** First cell becomes the row header, so the table reads as a list of things. */
  cells: ReactNode[]
}

export function GrowthChartFrame({
  label,
  summary,
  columns,
  rows,
  height = 180,
  overlay,
  children,
}: {
  label: string
  /** One sentence of context, read out after the name. Keep it short. */
  summary?: string
  columns: string[]
  rows: GrowthTableRow[]
  height?: number
  /** Centred over the plot area — the total inside a donut, and nothing else. */
  overlay?: ReactNode
  children: ReactElement
}) {
  const captionId = useId()

  return (
    <figure aria-labelledby={captionId} className="m-0">
      <figcaption id={captionId} className="sr-only">
        {summary ? `${label}. ${summary}` : label}
      </figcaption>

      {/*
        aria-hidden because there is nothing in here to read. Everything the
        SVG shows is in the table below, which is the copy screen readers get.
      */}
      <div className="relative" aria-hidden="true">
        <ResponsiveContainer width="100%" height={height}>
          {children}
        </ResponsiveContainer>
        {overlay && (
          <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
            {overlay}
          </div>
        )}
      </div>

      {/*
        sr-only sits on a wrapping div, never on the <table> itself.
        `sr-only` is width:1px + overflow:hidden, and a table refuses to lay out
        narrower than its own content no matter what width it is given — so the
        class applied directly to a table leaves a ~550px box in the flow and
        the whole page gains a horizontal scrollbar on a phone. The div clips it
        properly; the table inside is free to be as wide as its content.
      */}
      <div className="sr-only">
        <table>
          <caption>{label}</caption>
          <thead>
            <tr>
              {columns.map((column) => (
                <th key={column} scope="col">
                  {column}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, rowIndex) => (
              <tr key={rowIndex}>
                {row.cells.map((cell, cellIndex) =>
                  cellIndex === 0 ? (
                    <th key={cellIndex} scope="row">
                      {cell}
                    </th>
                  ) : (
                    <td key={cellIndex}>{cell}</td>
                  ),
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </figure>
  )
}

export interface GrowthLegendItem {
  color: string
  dash?: string
  label: string
  /** The figure itself, so the legend answers the chart without reading it. */
  value?: string
}

/**
 * The visible half of "Never Color Alone".
 *
 * Written as HTML rather than recharts' own <Legend> so the name and the number
 * are real text — selectable, wrappable, and sized by the type scale instead of
 * by an SVG font attribute. The swatch mirrors the series' dash pattern for
 * lines, so a reader matching legend to chart has the stroke style to go on and
 * not just the hue.
 */
export function GrowthLegend({
  items,
  variant = 'dot',
}: {
  items: GrowthLegendItem[]
  variant?: 'dot' | 'line'
}) {
  return (
    <ul className="flex flex-col gap-1.5">
      {items.map((item) => (
        <li key={item.label} className="flex items-baseline gap-2 text-[12px] leading-snug">
          <span aria-hidden="true" className="mt-1 shrink-0">
            {variant === 'line' ? (
              <svg width="18" height="8" viewBox="0 0 18 8">
                <line
                  x1="0"
                  y1="4"
                  x2="18"
                  y2="4"
                  stroke={item.color}
                  strokeWidth="2.5"
                  strokeDasharray={item.dash}
                  strokeLinecap="round"
                />
              </svg>
            ) : (
              <span
                className="block h-2.5 w-2.5 rounded-sm"
                style={{ backgroundColor: item.color }}
              />
            )}
          </span>
          {/*
            Wraps rather than truncates. An industry name here runs to "Retail
            sale in non-specialized stores (sari-sari store)", and an ellipsis
            in a legend is worse than a second line: the reader is matching a
            name to a line and a clipped name matches nothing.
          */}
          <span className="min-w-0 flex-1 text-ink">{item.label}</span>
          {item.value && (
            <span className="tnum shrink-0 font-semibold text-ink-secondary">{item.value}</span>
          )}
        </li>
      ))}
    </ul>
  )
}
