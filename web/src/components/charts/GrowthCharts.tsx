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
import { useId, useState } from 'react'
import type {
  BarangayGrowthRow,
  BusinessStatusRow,
  IndustryLens,
  IndustryLenses,
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
 * /analytics/business-growth already derived (App\Support\BusinessGrowthAnalytics)
 * and is drawn as given, so the screen and the PDF can never disagree about a
 * number.
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
            /*
             * Pinned to the top of the plot, and only on the vertical axis.
             *
             * A tooltip that follows the pointer lands in the hole, because the
             * pointer is on the ring and the hole is the nearest empty space —
             * so hovering a segment covered the total with the segment's own
             * count and neither was readable. The hole is not empty: the figure
             * in it is the reason the chart is a donut rather than a pie.
             *
             * recharts resolves `position` one axis at a time
             * (getTooltipTranslateXY returns position[key] early only for the
             * key it was handed), so giving it `y` alone stops the box climbing
             * into the centre while leaving the horizontal pointer-follow that
             * tells the reader which segment they are on. It now covers the top
             * of the ring instead — a shape, not a number.
             *
             * Same fix as ShareChart.tsx, for the same reason.
             */
            position={{ y: 0 }}
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

/** "1st", "2nd", "3rd", "4th" — see the axis note on GrowthRenewalCurve. */
function ordinal(cycle: number): string {
  const teens = cycle % 100
  if (teens >= 11 && teens <= 13) {
    return `${cycle}th`
  }

  return `${cycle}${['th', 'st', 'nd', 'rd'][cycle % 10] ?? 'th'}`
}

/**
 * The spec's Business Renewal Performance: compliance across renewal periods.
 *
 * It is a curve and not a single ratio on purpose. The spec asks for a
 * Kaplan-Meier estimate, which follows one group of businesses through renewal
 * after renewal, so the answer is a rate per cycle. Collapsing it to one number
 * would throw away the shape, which is the only part that says whether the city
 * loses businesses at the first renewal or slowly over years.
 *
 * The at-risk count rides along on every point because a late cycle can rest on
 * very few businesses — 117 behind cycle 3 where 451 stood behind cycle 1 — and
 * a reader who cannot see that has no way to weigh the last point.
 *
 * ── Why the ticks are ordinals ──────────────────────────────────────────────
 *
 * They read "Renewal 1", "Renewal 2", "Renewal 3", and a panelist asked in
 * those words what that meant. Fairly: "Renewal 1" is shaped like a category
 * name — Region 1, Barangay 1 — so it says nothing about being a position in a
 * sequence, and the screen carries a period selector directly above it, which
 * invites a reader to guess the axis is windowed by it. It is not.
 *
 * BusinessGrowthAnalytics::cohortObservations() builds each business's
 * mayor's-permit chain from its FIRST permit with no date filter at all, and
 * cycle k is the k-th renewal along that chain. So the axis is each business's
 * own renewal history, not the calendar and not the selected period. "1st
 * renewal" reads as an ordinal position, which is what a cycle is; the caption
 * under the chart in BusinessGrowthPage says whose first it is.
 *
 * Nothing here fixes the count of ticks at three. `points` is however many
 * cycles the register supports — survivalCurve() walks t = 1..max_cycle and
 * stops when no business reached the next one — so a register with a longer
 * history draws a 4th and a 5th without a change on this side.
 */
export function GrowthRenewalCurve({ points }: { points: SurvivalPoint[] }) {
  const data = points.map((point) => ({ ...point, label: `${ordinal(point.cycle)} renewal` }))

  return (
    <GrowthChartFrame
      label="Business Renewal Performance"
      summary="Share still renewing on time at each renewal, counted from each business's own first permit"
      columns={['Renewal', 'Still renewing on time', 'Businesses that reached it', 'Lapses']}
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

/*
 * ── The lens toggle, and the question it answers ────────────────────────────
 *
 * A panelist asked whether there is any criterion for which line of business
 * shows up on this chart, and what happens if all of them do. Both halves are
 * fair. The register holds 135 PSIC codes; the palette above keeps six series
 * apart WITHOUT colour (six hues, six dash patterns, a named legend and a hidden
 * table), and a seventh would have to repeat one of them. So six is a hard
 * ceiling, not an editorial shortlist — which makes "which six" the entire
 * question.
 *
 * It used to be answered once, by size, on a panel titled Growth Trend. A trade
 * that went from three businesses to six never appeared; two of the six on
 * screen were shrinking. Same six slots now, three questions, reader's choice.
 *
 * Largest is the default. It is the only one of the three that describes the
 * register rather than a window — it needs no prior period, no floor and no
 * comparison to mean something, and it is the ranking that shipped, so nobody's
 * saved reading of this screen changes underneath them.
 */

/** The lens picker: three exclusive answers to one question about the chart. */
function IndustryLensToggle({
  lenses,
  active,
  onSelect,
  labelId,
}: {
  lenses: IndustryLens[]
  active: string
  onSelect: (key: IndustryLens['key']) => void
  labelId: string
}) {
  return (
    <div
      /*
       * `role="group"` with `aria-pressed` buttons rather than a radiogroup.
       * These are three views of one chart, not three answers being submitted —
       * the same reading that puts `aria-pressed` on the office cards in
       * ProcessingTimePage and a radiogroup on the wizard's registration type.
       *
       * A lens with nothing to draw is NOT `disabled`. The native attribute
       * takes it out of the tab order and most screen readers walk straight
       * past, so a reader working the strip by keyboard would meet two lenses
       * and conclude the chart offers two. `aria-disabled` announces it and
       * keeps it reachable, and pressing it lands on the panel's own sentence
       * explaining that nothing declined — which is the answer they came for.
       */
      role="group"
      aria-labelledby={labelId}
      // royal-tint as the track, so the unselected lenses read as one control
      // rather than as three loose buttons on the card's white.
      className="inline-flex flex-wrap gap-1 rounded-full bg-royal-tint p-1"
    >
      {lenses.map((lens) => {
        const isActive = lens.key === active
        const empty = lens.rows.length === 0

        return (
          <button
            key={lens.key}
            type="button"
            aria-pressed={isActive}
            aria-disabled={empty ? true : undefined}
            onClick={() => onSelect(lens.key)}
            className={`rounded-full px-3 py-1 text-[12px] font-semibold transition ${
              isActive
                ? 'bg-white text-royal shadow-card ring-1 ring-royal'
                : // An empty lens is still a lens: muted, never removed. It is
                  // the answer to "did anything decline?" and the reader has to
                  // be able to ask.
                  empty
                  ? 'text-ink-muted hover:bg-white/60'
                  : 'text-ink-secondary hover:bg-white/70 hover:text-ink'
            }`}
          >
            {lens.label}
          </button>
        )
      })}
    </div>
  )
}

/**
 * How this lens chose its six, in one sentence the reader can check.
 *
 * The exclusion is never silent. If a floor kept 7 of 30 lines out of the
 * ranking, the number is on the screen — a reader who cannot see what was left
 * out has no way to tell a considered cut from a bug, and "why isn't my trade on
 * here" is precisely the question this panel gets asked.
 */
function lensCaption(lens: IndustryLens, lenses: IndustryLenses): string {
  const drawn = lens.rows.length
  const below = lenses.lines_on_record - lenses.above_floor

  if (lens.key === 'largest') {
    const basis = `The ${drawn} lines of business with the most businesses registered.`
    return drawn < lenses.slots
      ? `${basis} That is every line on the register.`
      : `${basis} ${lenses.lines_on_record - drawn} smaller lines are not shown.`
  }

  const moved = lens.key === 'growing' ? 'gained the most' : 'lost the most'
  const verb = lens.key === 'growing' ? 'grew' : 'declined'

  /*
   * One idea per sentence, at the client's request: the old caption opened on
   * "Ranked by the change between the two periods. Only industries with at
   * least 10 businesses on record are ranked — 7 of 30 lines fall below that
   * and are left out." — three separate facts fused into a clause a reader has
   * to unpick before learning what they are looking at.
   *
   * So: what the chart shows, then who was excluded, then why fewer than six
   * lines are drawn when that happens. The exclusion count is still printed —
   * "why isn't my trade on here" is the question this panel actually gets, and
   * a floor a reader cannot see is indistinguishable from a bug.
   */
  const basis = `The ${drawn === 1 ? 'industry' : `${drawn} industries`} that ${moved} businesses this period.`
  const floor =
    below > 0
      ? ` Lines with fewer than ${lenses.min_businesses} businesses are too small to rank — ${below} of ${lenses.lines_on_record} are left out.`
      : ` Every line on the register has at least ${lenses.min_businesses} businesses, so none are left out.`

  if (drawn === 0) {
    return `No industry large enough to rank ${verb} this period, so there is nothing to plot.${floor}`
  }

  const noun = lens.qualifying === 1 ? 'industry' : 'industries'

  // Said out loud rather than left to the reader to count. A chart with four
  // lines where the other panels have six reads as a chart that lost two.
  if (lens.qualifying < lenses.slots) {
    return `${basis}${floor} Only ${lens.qualifying} ${noun} ${verb} at all, so there are ${drawn} ${drawn === 1 ? 'line' : 'lines'} instead of ${lenses.slots}.`
  }

  if (lens.qualifying === lenses.slots) {
    return `${basis}${floor} Exactly ${lens.qualifying} ${noun} ${verb}, and all of them are here.`
  }

  return `${basis}${floor} ${lens.qualifying} ${noun} ${verb} in total.`
}

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
 *
 * The lens lives in this component rather than on the page because everything it
 * changes lives here too: the series, the legend, the hidden table and the
 * caption all have to move together or the chart and the sentence under it stop
 * describing the same six industries.
 */
export function GrowthIndustryTrend({
  lenses,
  priorLabel,
  currentLabel,
}: {
  lenses: IndustryLenses
  priorLabel: string
  currentLabel: string
}) {
  const [lensKey, setLensKey] = useState<IndustryLens['key']>('largest')
  const labelId = useId()

  // Falls back to the first lens the server sent rather than assuming
  // 'largest' exists: a payload from before the splice carries only one.
  const lens = lenses.lenses.find((candidate) => candidate.key === lensKey) ?? lenses.lenses[0]
  const rows = lens?.rows ?? []
  const caption = lens ? lensCaption(lens, lenses) : ''

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
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
        {/*
          A real label for the group, visible rather than sr-only. The control
          is a question ("which six?") and the answer buttons are terse enough
          that without it "Largest" beside a chart could be read as a legend.
        */}
        <span id={labelId} className="text-[12px] font-semibold text-ink-secondary">
          Which six industries
        </span>
        {/* One lens is not a choice — see the fallback note in BusinessGrowthPage. */}
        {lenses.lenses.length > 1 && (
          <IndustryLensToggle
            lenses={lenses.lenses}
            active={lens?.key ?? ''}
            onSelect={setLensKey}
            labelId={labelId}
          />
        )}
      </div>

      {/*
        aria-live so a lens change is announced. The chart itself is an
        aria-hidden SVG and the hidden table below it re-renders silently, so
        without this a screen-reader user presses "Fastest declining" and is
        told nothing at all happened. The caption names the lens' rule and its
        exclusions, which is the most useful thing to hear.
      */}
      <p aria-live="polite" className="mb-3 text-[11px] leading-snug text-ink-muted">
        {caption}
      </p>

      {rows.length === 0 ? (
        <p className="px-1 py-8 text-center text-[13px] text-ink-secondary">
          Nothing to plot under this lens. The caption above says why.
        </p>
      ) : (
        <div className="grid gap-3 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)] lg:items-center">
          <GrowthChartFrame
            label="Business Industry Growth Trend"
            summary={`${lens?.label ?? ''}. ${caption} New registrations per industry, ${priorLabel} against ${currentLabel}`}
            columns={[
              'Industry',
              'PSIC code',
              priorLabel,
              currentLabel,
              'Change',
              'On record today',
            ]}
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
      )}
    </div>
  )
}
