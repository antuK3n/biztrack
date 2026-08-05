import { useId } from 'react'
import type { ReactElement, ReactNode } from 'react'
import { ResponsiveContainer } from 'recharts'

/*
 * The shared scaffolding every analytics chart on this product sits inside.
 *
 * ── WHY A FRAME AT ALL ──────────────────────────────────────────────────────
 *
 * recharts draws an SVG made of <path> and <rect>. None of it carries the
 * numbers: to a screen reader a finished bar chart is an unlabelled graphic, and
 * to a keyboard user it is nothing at all. The client's spec names a required
 * visualisation per report (donut, pie, bar, horizontal bar, stacked bar), and
 * PRODUCT.md sets WCAG 2.1 AA — so "draw the picture" and "state the numbers"
 * have to be the same component, or the second one gets forgotten per panel.
 *
 * So every chart here is a <figure> holding three things:
 *
 *   1. the drawing, marked aria-hidden — it is a duplicate of (3), and a chart
 *      announced as "graphic" adds nothing;
 *   2. a visible legend, where the value is written as TEXT beside its swatch.
 *      DESIGN.md's Never Color Alone rule: a reader who cannot separate two
 *      hues must still be able to read the panel, and so must anyone looking at
 *      a photocopy of it;
 *   3. an sr-only <table> of the same figures, so assistive technology gets the
 *      whole series at once.
 *
 * ── TWO THINGS THAT LOOK LIKE IMPROVEMENTS AND ARE NOT ──────────────────────
 *
 * Do not add recharts' `accessibilityLayer` to charts inside this frame. It
 * makes the plot focusable for arrow-key tooltip browsing, and a focusable
 * element inside an aria-hidden subtree is exactly the broken state WAI-ARIA
 * warns about — focus lands somewhere the screen reader refuses to describe.
 * The table is the better affordance regardless: one tooltip at a time is a
 * worse way to read ten values than a table of ten rows.
 *
 * Do not swap the sr-only table for `hidden` or `display:none`. Hidden content
 * is dropped from the accessibility tree entirely, which deletes the only
 * textual copy of the data.
 */

/* ── palette ────────────────────────────────────────────────────────────────
 *
 * Every fill below clears WCAG 1.4.11's 3:1 floor against white, because a
 * reader has to be able to match a legend swatch to the slice or bar it labels.
 * That rules out the prototype's pale companion blues as data colours — #d1dbeb
 * measures about 1.3:1 and disappears on a projector or a printout.
 *
 * None of these is #bd0000, and that is deliberate rather than incidental.
 * DESIGN.md reserves the error red for errors and destructive actions ("Red
 * Means Stop"), so a rejected application, a failed inspection or a breached
 * statutory limit — all of them findings about the register, none of them a
 * system fault — take amber and purple instead. The one place the product
 * breaks that rule is the permit-state map, and the reasoning for the exception
 * is written out there.
 */
export const CHART_ROYAL = '#3242ca'
export const CHART_DEEP = '#1d4b9e'
export const CHART_MUTED = '#7796c5'
/** Findings that need weight without claiming to be errors. ~7:1 on white. */
export const CHART_AMBER = '#8a4b00'
export const CHART_PURPLE = '#5a3286'
export const CHART_TEAL = '#146b5e'
export const CHART_SLATE = '#5b6472'

export const CHART_GRID = '#c5cfe0'
export const CHART_AXIS_TICK = { fontSize: 11, fill: '#5b6472' } as const

/**
 * One legend entry. `value` is the figure written out; `note` is the optional
 * second line (a share, a denominator) that keeps the legend from needing a
 * separate table underneath it.
 */
export type ChartLegendItem = {
  key: string
  label: string
  value: string
  note?: string
  color: string
}

/** A row of the sr-only data table: a row header plus its cells, already formatted. */
export type ChartTableRow = { header: string; cells: string[] }

export function ChartFrame({
  title,
  height,
  columns,
  rows,
  legend,
  legendColumns = 2,
  footer,
  children,
}: {
  /**
   * What the figure is, in a sentence a screen reader can act on. This is the
   * figure's accessible name, so it should not repeat the section heading
   * verbatim — "Applications filed this month by transaction type" tells a
   * reader more than "Application Volume" does.
   */
  title: string
  height: number
  /** Column headings for the sr-only table; the first names the row header. */
  columns: string[]
  rows: ChartTableRow[]
  legend?: ChartLegendItem[]
  legendColumns?: 1 | 2 | 3
  footer?: ReactNode
  children: ReactElement
}) {
  const captionId = useId()

  return (
    <figure aria-labelledby={captionId} className="m-0">
      <figcaption id={captionId} className="sr-only">
        {title}
      </figcaption>

      {/*
        aria-hidden and NOT focusable — see the note at the top of this file
        before removing either half of that pair.
      */}
      <div aria-hidden="true">
        <ResponsiveContainer width="100%" height={height}>
          {children}
        </ResponsiveContainer>
      </div>

      {legend && legend.length > 0 && (
        <ul
          className={`mt-2 grid gap-x-4 gap-y-1 ${
            legendColumns === 1 ? '' : legendColumns === 2 ? 'sm:grid-cols-2' : 'sm:grid-cols-3'
          }`}
        >
          {legend.map((item) => (
            <li key={item.key} className="flex items-baseline gap-2 text-[12px] leading-snug">
              <span
                className="mt-[3px] h-2.5 w-2.5 shrink-0 rounded-[3px]"
                style={{ backgroundColor: item.color }}
                aria-hidden="true"
              />
              <span className="min-w-0 flex-1 truncate text-ink" title={item.label}>
                {item.label}
              </span>
              <span className="tnum shrink-0 font-semibold text-ink">{item.value}</span>
              {item.note && <span className="tnum shrink-0 text-ink-muted">{item.note}</span>}
            </li>
          ))}
        </ul>
      )}

      <table className="sr-only">
        <caption>{title}</caption>
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
          {rows.map((row) => (
            <tr key={row.header}>
              <th scope="row">{row.header}</th>
              {row.cells.map((cell, i) => (
                <td key={i}>{cell}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>

      {/*
        A <p>, not a second <figcaption>: HTML allows exactly one figcaption per
        figure, and the sr-only one above is already spending it on the
        accessible name.
      */}
      {footer && <p className="mt-2 text-[11px] leading-snug text-ink-muted">{footer}</p>}
    </figure>
  )
}

/**
 * The tooltip skin every chart here shares.
 *
 * recharts' default tooltip inherits nothing from the app, so without this each
 * panel would hand out a different-looking box. Passed as `contentStyle`, not
 * as a custom `content` component, because the default already handles the
 * pointer-follows-series behaviour correctly and a hand-rolled one would have
 * to reimplement it.
 */
export const CHART_TOOLTIP = {
  contentStyle: {
    borderRadius: 10,
    border: '1px solid #c5cfe0',
    boxShadow: '0 4px 16px rgb(16 24 40 / 0.10)',
    fontSize: 12,
    padding: '6px 10px',
  },
  labelStyle: { fontWeight: 600, color: '#1a1f2b' },
} as const
