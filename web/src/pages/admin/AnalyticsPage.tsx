import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { CircleMarker, MapContainer, Popup, TileLayer, useMap } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'
import { ErrorState, Skeleton, SkeletonCards } from '../../components/ui/primitives'
import { Info, MetricDefinitions } from '../../components/ui/MetricInfo'
import { FilterMenu, PageTitle, ProtoCard } from '../../components/ui/Proto'
import { HorizontalBars, VerticalBars } from '../../components/charts/Bars'
import type { BarDatum } from '../../components/charts/Bars'
import {
  CHART_AMBER,
  CHART_MUTED,
  CHART_PURPLE,
  CHART_ROYAL,
  CHART_SLATE,
  CHART_TEAL,
} from '../../components/charts/ChartFrame'
import { ShareChart } from '../../components/charts/ShareChart'
import type { ShareSlice } from '../../components/charts/ShareChart'
import { StackedBars } from '../../components/charts/StackedBars'
import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type {
  BarangayShareRow,
  ComplianceIndicator,
  DashboardReport,
  ExpiryRow,
  LineOfBusinessRow,
  MapPoint,
  RankedShareRow,
  StageRow,
} from '../../lib/types'
import { AnalyticsTabs } from './AnalyticsTabs'
import { ComputedAt } from './ComputedAt'
import { GenerateReportButton } from './GenerateReportButton'

/*
 * Analytics Dashboard — docs/r-integration-spec.md §1, mockup 115/116.
 *
 * Everything is computed server-side (App\Support\DashboardAnalytics, or R's
 * POST /dashboard) and rendered as given. This file does no arithmetic beyond bar
 * widths: no rate is derived here, so there is no second implementation of a
 * formula that could drift from the one the parity test pins.
 *
 * THREE RULES THIS SCREEN IS BUILT AROUND
 *
 * 1. A null is not a zero. Every figure that can be null renders as a stated
 *    reason — "No filings on record", "Cannot be computed" — never as 0 or 0%. A
 *    dash is honest; a zero is a claim nobody computed.
 *
 * 2. Each panel says which window it used. The KPI cards, volume and outcomes are
 *    this month or year-to-date; the means and rates run on a trailing window; the
 *    rankings and the map are as of today. Nothing here implies one clock.
 *
 * 3. A statutory breach must be impossible to skim past. RA 11032's 3/7/20-day
 *    limits are law, not service targets. See TierPanel for how that is drawn
 *    and why it is drawn that way.
 *
 * THE CHART TYPES ARE NOT A STYLE CHOICE. The client's "R INTEGRATION DRAFTS"
 * names a required visualisation per report, and it was written down because
 * the first build of this screen answered every question with a table. The
 * mapping this file implements, so nobody has to re-derive it from the spec:
 *
 *   Application Volume            vertical bar chart
 *   Decision Outcomes             donut, approval rate in the hole
 *   Processing time by RA tier    horizontal bar chart
 *   Time-in-stage by department   horizontal bar chart
 *   Compliance                    three KPI cards
 *   Top 5 barangays / categories  vertical bar charts
 *   Form of Organization          pie chart
 *   Inspections                   horizontal STACKED bar chart
 *   Officer activity              three KPI cards
 *
 * Every one of them goes through components/charts, which is what supplies the
 * accessible name and the sr-only table of the underlying numbers. A chart
 * dropped straight into this file would be an unreadable graphic to a screen
 * reader; read components/charts/ChartFrame.tsx before adding one.
 *
 * The prose on this screen is deliberately short. It used to run to a paragraph
 * per panel and the client's verdict was that it was unreadable; the long-form
 * account of every figure now lives behind the info affordance beside its label
 * (components/ui/MetricInfo), where it is available to whoever wants it and
 * costs nothing to whoever does not. Resist putting it back inline.
 */

/*
 * The breach tone. Deliberately NOT #bd0000: DESIGN.md reserves that for errors
 * and destructive actions and forbids it as a chart data colour. A statutory
 * breach is a finding about the office, not a system error — so it reads as a
 * heavy amber that clears 4.5:1 on white, and every breach also carries a word
 * and an icon, because colour alone would fail both WCAG 2.1 AA and the Never
 * Color Alone rule.
 */
const BREACH = CHART_AMBER
const BREACH_TINT = '#fdf1e3'

/*
 * MAP PERMIT-STATE COLOURS — a deliberate, client-requested exception to
 * DESIGN.md's "Red Means Stop" rule. Please read this before "correcting" it.
 *
 * DESIGN.md reserves red for errors, denials and destructive actions, and says
 * it must never label a category. BREACH above obeys that rule. This map does
 * not, and it is the one place where breaking it is right: on a map of who is
 * trading without a permit, red is not decorating a category — "no valid
 * permit" IS the exceptional, act-on-it state an officer opens this panel to
 * find. That is the same thing the rule protects. (Client testing checklist
 * item 92 asked for green/red here; this is the reasoning for saying yes.)
 *
 * It is still not #bd0000. That exact hex stays unique to errors and
 * destructive actions, so LAPSED uses --color-s-red (#c11212), the semantic
 * status red already used for status chips, and which lands within 0.3
 * percentage points of #bd0000 on the tile measurements below anyway.
 *
 * CONTRAST — measured against the actual OpenStreetMap tiles for Malabon at
 * z14, not against white, because nothing on this panel sits on white. Sampling
 * three tiles covering the plotted area: the map's own palette runs from
 * #ffffff roads through #f2efe9 land and #d1c6bd buildings to #aad3df water.
 * Share of tile pixels each colour clears WCAG 1.4.11's 3:1 floor against:
 *
 *   #125c3b (VALID, opaque stroke)   96.6%   <- the incumbent blue managed 96.4%
 *   #c11212 (LAPSED, opaque stroke)  93.9%
 *   #c11212 (LAPSED, fill @ 0.9)     93.1%
 *
 * No flat colour clears 3:1 on 100% of OSM tiles — the worst offender is the
 * blue-grey #9b9bb5 of trunk-road casings, where even the blue this replaced
 * only reached 2.80:1. We are at or above parity with what shipped, and the
 * shape cue below is what carries the remaining few percent.
 *
 * VALID is --color-green-700 (#1c8f5c) taken darker at the same hue. The token
 * itself, and --color-s-green (#22b573), are far too light to sit on a map:
 * they clear 3:1 on 44% and 0.7% of tile pixels respectively. They are fine on
 * white chips; they are unreadable over cartography.
 *
 * NEVER COLOUR ALONE — this pairing needs it more than any other in the
 * product. Red-green is the confusable pair (~1 in 12 men), and measured
 * greyscale contrast between these two is 1.28:1: to a deuteranope, or in a
 * photocopy of a printed report, #125c3b and #c11212 are the same dark blob.
 * So the two states differ in SIZE and in FILL, and colour is the third cue:
 *
 *   valid   -> small (r 3.5) hollow ring, thin stroke
 *   lapsed  -> large (r 5.5) solid disc
 *
 * The polarity is not arbitrary. A hollow marker's interior is the map showing
 * through — measured at 1.0:1 against the tile — so a hollow marker is carried
 * entirely by its thin ring. The state an officer is hunting must not be the
 * hollow one. That is also why this panel previously failed: both states were
 * the same size and the same blue, separated only by fill, which is exactly the
 * weakest of the three cues at 700+ overlapping points.
 */
const MAP_VALID = '#125c3b'
const MAP_LAPSED = '#c11212'

const PERIOD_OPTIONS = [
  { value: '3', label: 'Last 3 months' },
  { value: '6', label: 'Last 6 months' },
  { value: '12', label: 'Last 12 months' },
  { value: '24', label: 'Last 24 months' },
  { value: '36', label: 'Last 36 months' },
]

function pct(value: number | null): string {
  return value === null ? '—' : `${value.toFixed(1)}%`
}

function num(value: number): string {
  return value.toLocaleString()
}

/** "2026-07-30" reads as "30 Jul 2026". */
function dateLabel(iso: string): string {
  const [y, m, d] = iso.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('en-PH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

function WarningGlyph() {
  return (
    <svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true" className="shrink-0">
      <path
        d="M8 1.4 15 14H1L8 1.4Z"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinejoin="round"
      />
      <path d="M8 6v3.4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
      <circle cx="8" cy="11.6" r="0.9" fill="currentColor" />
    </svg>
  )
}

/*
 * `metric` is the figure's dot path in the payload (`decisions.approval_rate`).
 * Passing it puts an info affordance beside the label that opens the server's
 * own account of what the number measures and why it is on the screen. Omitting
 * it renders nothing, so a panel with no definition looks unremarkable rather
 * than broken.
 */
/*
 * The heading and its window, on ONE line.
 *
 * The window ("Last 12 months to 5 Aug 2026") used to sit on a second line
 * under every heading. Multiplied by twelve sections that was most of the dead
 * vertical space the client complained about, and it is a caption, not a
 * statement — so it now sits beside the title and only wraps when it has to.
 */
function SectionHeading({ children, note, metric }: { children: ReactNode; note?: string; metric?: string }) {
  return (
    <div className="mb-1.5 flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5">
      {/*
       * The info button is a sibling of the h2, not a child of it. Nesting it
       * inside folds "How X is measured" into the heading's accessible name, so
       * anyone navigating by heading hears the button on every section.
       */}
      <div className="flex items-center">
        <h2 className="text-[17px] font-semibold text-ink">{children}</h2>
        {metric && <Info metric={metric} />}
      </div>
      {note && <p className="text-[11px] text-ink-muted">{note}</p>}
    </div>
  )
}

function Kpi({ value, label, hint, metric }: { value: string; label: string; hint?: string; metric?: string }) {
  return (
    <ProtoCard className="px-4 py-3.5 text-center">
      <p className="tnum text-[26px] font-bold leading-none text-royal">{value}</p>
      <p className="mt-1.5 text-[12px] text-ink-muted">
        {label}
        {metric && <Info metric={metric} />}
      </p>
      {hint && <p className="mt-0.5 text-[11px] text-ink-muted">{hint}</p>}
    </ProtoCard>
  )
}

/**
 * A KPI card whose figure may not exist.
 *
 * The spec asks for "three KPI cards" twice — compliance and staff activity —
 * and both of them can legitimately have nothing to report. `unavailable` is
 * the whole point of this component: it prints the stated reason where the
 * figure would go, because a null rendered as 0% is a compliance failure the
 * register never measured, and on a screen an LGU acts from that is not a
 * rounding error, it is a false accusation.
 */
function StatCard({
  value,
  unit,
  label,
  detail,
  unavailable,
  metric,
}: {
  value: string
  unit?: string
  label: string
  detail: string
  unavailable?: boolean
  metric?: string
}) {
  return (
    <ProtoCard className="px-4 py-3.5">
      <p className="text-[11px] uppercase tracking-wide text-ink-muted">
        {label}
        {metric && <Info metric={metric} />}
      </p>
      {unavailable ? (
        <p className="mt-1.5 text-[15px] font-semibold text-ink-secondary">{value}</p>
      ) : (
        <p className="tnum mt-1 text-[26px] font-bold leading-none text-royal">
          {value}
          {unit && <span className="text-[14px] font-semibold text-ink-muted">{unit}</span>}
        </p>
      )}
      <p className="mt-1.5 text-[11px] leading-snug text-ink-muted">{detail}</p>
    </ProtoCard>
  )
}

/* ── Application Volume ─────────────────────────────────────────────────── */

function VolumePanel({ report }: { report: DashboardReport }) {
  /*
   * Total is stated beside the chart, not drawn as a fourth bar. It is the sum
   * of the other three, so plotting it on the same axis would guarantee one bar
   * taller than every other and squash the comparison the panel exists to make.
   */
  /*
   * One colour for all three. These are transaction types, not a ranking, so
   * the leader-highlight ramp the ranked panels use would be pointing at
   * whichever type happened to come first in the payload.
   */
  const data: BarDatum[] = report.volume.rows.map((row) => ({
    key: row.type,
    label: row.label,
    value: row.count,
    valueText: num(row.count),
    color: CHART_ROYAL,
  }))

  return (
    <ProtoCard className="px-4 pb-3 pt-4">
      <VerticalBars
        title="Applications filed this month by transaction type"
        data={data}
        categoryHeading="Transaction type"
        valueHeading="Applications filed"
        tooltipUnit="filed"
        footer={
          <>
            <strong className="font-semibold text-ink">{num(report.volume.total)}</strong> total
            submitted
          </>
        }
      />
    </ProtoCard>
  )
}

/* ── Decision Outcomes ─────────────────────────────────────────────────── */

/*
 * One tone per outcome, and none of them is the error red.
 *
 * "Rejected" is the tempting one to paint #bd0000 and it is the one that must
 * not be. DESIGN.md's Red Means Stop reserves that hex for system errors and
 * destructive actions; a rejected application is a lawful decision an officer
 * made, and colouring it as a fault would misrepresent the office to the person
 * reading its own dashboard. Amber and purple carry the weight instead, and
 * every slice prints its count as text beside the swatch regardless.
 */
const OUTCOME_COLORS: Record<string, string> = {
  approved: CHART_ROYAL,
  returned: CHART_AMBER,
  rejected: CHART_PURPLE,
  pending: CHART_MUTED,
  cancelled: CHART_SLATE,
}

function DecisionsPanel({ report }: { report: DashboardReport }) {
  const { rows, approval_rate, approved, decisioned, total } = report.decisions
  /*
   * Cancelled only earns a slice once it has happened — otherwise it is a
   * permanent zero explaining nothing. Zero-count outcomes are dropped from the
   * plot for the same reason: recharts renders them as an invisible wedge with
   * a visible legend entry, which reads as "we lost this one".
   */
  const visible = rows.filter((row) => row.outcome !== 'cancelled' || row.count > 0)
  const plotted = visible.filter((row) => row.count > 0)

  const slices: ShareSlice[] = plotted.map((row) => ({
    key: row.outcome,
    label: row.decisioned ? row.label : `${row.label} (not in the rate)`,
    value: row.count,
    valueText: num(row.count),
    shareText: total > 0 ? `${((row.count / total) * 100).toFixed(1)}%` : undefined,
    color: OUTCOME_COLORS[row.outcome] ?? CHART_SLATE,
  }))

  return (
    <ProtoCard className="px-4 pb-3 pt-4">
      {/*
        The rate lives in the hole rather than in a row of its own. It is not a
        share of the ring — pending filings are in the ring and out of the
        denominator — so it needs to read as a separate claim about the same
        data, which is exactly what the centre of a donut is for.
      */}
      <ShareChart
        title="Decision outcomes for applications filed this month, with the approval rate"
        slices={slices}
        variant="donut"
        center={
          approval_rate === null
            ? { value: '—', label: 'no approval rate yet' }
            : { value: pct(approval_rate), label: 'approval rate' }
        }
        categoryHeading="Outcome"
        valueHeading="Applications"
        shareHeading="Share of filings"
        footer={
          <>
            {approval_rate === null
              ? 'Nothing filed this month has been decided yet.'
              : `${num(approved)} approved of ${num(decisioned)} decided; pending filings are excluded.`}
            <Info metric="decisions.approval_rate" />
          </>
        }
      />
    </ProtoCard>
  )
}

/* ── Average Processing Time by RA 11032 Tier ──────────────────────────── */

/**
 * The three statutory tiers against their legal limits, as a horizontal bar
 * chart — the visualisation the client's spec names for this report.
 *
 * THE AXIS IS A PERCENTAGE OF EACH TIER'S OWN LIMIT, AND THAT IS THE WHOLE
 * DESIGN. Read this before "fixing" it to plot days.
 *
 * RA 11032 gives simple transactions 3 working days, complex 7, and highly
 * technical 20. Plot the means on one shared day-axis and the highly-technical
 * bar is seven times the length of the simple one no matter how each is
 * performing: a simple tier running 83% over the law renders as a stub, and a
 * highly-technical tier comfortably inside its own limit renders as the longest
 * bar on the chart. That is a chart which hides breaches, on a panel whose only
 * job is to surface them.
 *
 * Scaling each bar by its own limit puts the legal threshold at 100% for all
 * three, so one dashed reference line governs every row and crossing it means
 * the same thing everywhere. The actual figure is not lost: the day value and
 * its limit are written at the end of each bar, and the sr-only table carries
 * both in full.
 *
 * A breach then gets four independent signals, because one is skippable and
 * colour alone is not accessible: the bar visibly crosses a marked line, the bar
 * changes tone, the row below carries a warning icon, and the overage is written
 * out in working days. Nothing here softens how a breach reads.
 */
function TierPanel({ report }: { report: DashboardReport }) {
  const tiers = report.processing_tiers
  const measured = tiers.filter((t) => t.mean_working_days !== null)
  const breaching = tiers.filter((t) => t.breaching)

  /*
   * Tiers whose own recorded deadline is more generous than the law allows. The
   * workflow stamps every filing with a flat ten-working-day deadline, so a
   * simple transaction can be comfortably "on time" against the system and
   * still be more than three times over the statute. Anyone reading a high
   * on-time figure elsewhere in the product has to be told that.
   */
  const lenient = measured.filter(
    (t) =>
      t.recorded_deadline_working_days !== null &&
      t.recorded_deadline_working_days > t.statutory_working_days,
  )

  const data: BarDatum[] = measured.map((row) => {
    const mean = row.mean_working_days as number
    return {
      key: row.tier,
      label: `${row.label} · ${row.statutory_working_days}d`,
      value: Number(((mean / row.statutory_working_days) * 100).toFixed(1)),
      valueText: `${mean.toFixed(1)}d`,
      note: `${row.statutory_working_days}-day statutory limit`,
      color: row.breaching ? BREACH : CHART_ROYAL,
    }
  })

  const unmeasured = tiers.filter((t) => t.mean_working_days === null)

  return (
    <ProtoCard className="px-4 pb-3 pt-4">
      {data.length === 0 ? (
        <p className="py-3 text-[13px] text-ink-secondary">
          No application has been decided in this window, so no tier has an average to compare
          against its statutory limit.
        </p>
      ) : (
        <HorizontalBars
          title="Mean processing time per RA 11032 tier, as a percentage of that tier's own statutory limit"
          data={data}
          categoryHeading="Tier and statutory limit"
          valueHeading="Mean working days"
          noteHeading="Statutory limit"
          categoryWidth={132}
          rowHeight={34}
          reference={{ value: 100, label: 'Legal limit', color: '#1a1f2b' }}
          /*
           * Head-room past the reference line so a breaching bar has somewhere
           * to overshoot into. Without it the worst tier pins to the right edge
           * and stops looking worse than the one just over the line.
           */
          domainMax={Math.max(130, ...data.map((row) => row.value * 1.15))}
          tooltipUnit="% of the legal limit"
        />
      )}

      {/*
        Per-tier findings, one line each. This is the text half of "never colour
        alone": the amber bar above says breach to someone who can see it, this
        says it to everyone else — and it carries the two figures the bar cannot,
        the overage in working days and the share of individual filings that met
        the limit.
      */}
      <ul className="mt-2.5 space-y-1 border-t border-line pt-2.5">
        {measured.map((row) => (
          <li key={row.tier} className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px]">
            {row.breaching ? (
              <span
                className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 font-bold"
                style={{ color: BREACH, backgroundColor: BREACH_TINT }}
              >
                <WarningGlyph />
                {row.label}: over by {row.overage_days?.toFixed(1)}d
              </span>
            ) : (
              <span className="font-semibold text-ink-secondary">
                {row.label}: inside by {Math.abs(row.overage_days ?? 0).toFixed(1)}d
              </span>
            )}
            <span className="text-ink-muted">
              {num(row.within_statutory)}/{num(row.observations)} inside the limit (
              {pct(row.within_statutory_rate)}) · {row.mean_calendar_days?.toFixed(1)}d calendar
            </span>
          </li>
        ))}
        {unmeasured.map((row) => (
          <li key={row.tier} className="text-[11px] text-ink-muted">
            {row.label}: nothing decided in this window, so there is no average to compare against
            the {row.statutory_working_days}-day limit.
          </li>
        ))}
      </ul>

      <p className="mt-2 text-[11px] leading-snug text-ink-muted">
        Limits and averages are in <strong className="font-semibold">working days</strong>, as RA
        11032 sets them.
        {breaching.length > 0 && (
          <span className="font-semibold text-ink">
            {' '}
            {breaching.length} of {measured.length} measured{' '}
            {measured.length === 1 ? 'tier is' : 'tiers are'} over the legal limit.
          </span>
        )}
        {lenient.length > 0 && (
          <>
            {' '}
            <strong className="font-semibold text-ink">
              The deadline this system records is not the statutory one
            </strong>{' '}
            — every filing gets a flat {lenient[0].recorded_deadline_working_days}-working-day
            internal deadline, so on-time figures elsewhere are more forgiving than the law.
          </>
        )}
      </p>
    </ProtoCard>
  )
}

/* ── Average Time-in-Stage by Department ───────────────────────────────── */

/**
 * Department headings in the paper's words.
 *
 * The register stores each office's full legal name ("Bureau of Fire
 * Protection"), too long for a 128px label, so the code was rendered instead —
 * leaving the panel reading BFP/CHO/CPDO where the paper reads Fire
 * Protection/City Health/Zoning. Officers read codes fluently; this panel is
 * for LGU leadership, who do not. Full name stays in the `title` tooltip.
 *
 * Unmapped codes fall back to the code, so a new office is visibly unmapped
 * rather than silently mislabelled.
 */
const DEPARTMENT_HEADINGS: Record<string, string> = {
  BPLO: 'BPLO',
  CHO: 'City Health',
  BFP: 'Fire Protection',
  CPDO: 'Zoning',
  OBO: 'Building Official',
  CENRO: 'Environment',
  'CMO-MARKET': 'Market',
}

/**
 * A mean over one or two reviews is not a department's processing time, it is
 * an anecdote. Three offices each hold a single review left over from earlier
 * manual testing, all reading 0.0d, and they were crowding out the four
 * departments this panel exists to report on. Same threshold the control chart
 * applies for the same reason (Spc::MIN_COMPLETIONS_PER_WEEK).
 */
const MIN_REVIEWS_FOR_STAGE = 3

function StagePanel({ report }: { report: DashboardReport }) {
  const { rows: allRows, bottleneck, mean_days } = report.stages
  const rows = allRows.filter((r: StageRow) => r.reviews >= MIN_REVIEWS_FOR_STAGE)

  if (rows.length === 0) {
    return (
      <ProtoCard className="px-5 py-4">
        <p className="text-[13px] text-ink-secondary">
          No review assignment was completed in this window, so there is no time-in-stage to report.
        </p>
      </ProtoCard>
    )
  }

  /*
   * The slowest office takes the royal; the rest take the muted blue. Length
   * already ranks them, so colour is only pointing at the answer to "who is the
   * bottleneck" — which is the question this panel exists for.
   */
  const data: BarDatum[] = rows.map((row) => ({
    key: row.code,
    label: DEPARTMENT_HEADINGS[row.code] ?? row.code,
    value: row.mean_days,
    valueText: `${row.mean_days.toFixed(1)}d`,
    note: `${num(row.reviews)} reviews`,
    color: row.code === bottleneck?.code ? CHART_ROYAL : CHART_MUTED,
  }))

  return (
    <ProtoCard className="px-4 pb-3 pt-4">
      <HorizontalBars
        title="Mean days a review spends with each department"
        data={data}
        categoryHeading="Department"
        valueHeading="Mean days per review"
        noteHeading="Reviews completed"
        categoryWidth={104}
        tooltipUnit="days per review"
        /*
         * Assembled from the computed values, never a fixed sentence: a
         * hardcoded "Fire Protection is the bottleneck" would keep reading as
         * true long after Fire Protection got faster.
         */
        footer={
          bottleneck && (
            <>
              <strong className="font-semibold text-ink">{bottleneck.name}</strong> is the slowest at{' '}
              {bottleneck.mean_days.toFixed(1)}d
              {bottleneck.above_average_days !== null && bottleneck.above_average_days > 0 && (
                <> ({bottleneck.above_average_days.toFixed(1)}d over the {mean_days?.toFixed(1)}d
                  average)</>
              )}
              , handling {bottleneck.share_of_reviews.toFixed(1)}% of reviews.
            </>
          )
        }
      />
    </ProtoCard>
  )
}

/* ── Compliance Monitoring ─────────────────────────────────────────────── */

/**
 * One compliance indicator, one card — the spec asks for three KPI cards here
 * and the separation is load-bearing, not cosmetic. The three rates count three
 * different populations (decided filings, businesses ever issued a permit,
 * permits due for renewal), so anything that presents them as one series
 * invites a reader to average or compare them. Each card states its own
 * denominator underneath for the same reason.
 */
function ComplianceCard({ indicator }: { indicator: ComplianceIndicator }) {
  const unavailable = indicator.rate === null

  return (
    <StatCard
      value={unavailable ? 'Cannot be computed' : `${indicator.rate?.toFixed(0)}`}
      unit={unavailable ? undefined : '%'}
      unavailable={unavailable}
      label={indicator.label}
      // Keyed off the row's own identifier rather than a literal, so the three
      // indicators cannot be wired to each other's definitions.
      metric={`compliance.${indicator.indicator}`}
      detail={
        unavailable
          ? (indicator.unavailable_reason ??
            `Nothing to count this against: no ${indicator.denominator_label} in this window.`)
          : `${num(indicator.numerator)} of ${num(indicator.denominator)} ${indicator.denominator_label} ${indicator.numerator_label}.`
      }
    />
  )
}

function CompliancePanel({ report }: { report: DashboardReport }) {
  return (
    <div className="grid gap-4 sm:grid-cols-3">
      {report.compliance.map((indicator) => (
        <ComplianceCard key={indicator.indicator} indicator={indicator} />
      ))}
    </div>
  )
}

/* ── Permits Approaching Expiry ────────────────────────────────────────── */

/**
 * Column headings for the expiry table, in the paper's words.
 *
 * The register stores each permit type's legal name ("Fire Safety Inspection
 * Certificate"), which is far too long for a table heading, so the code was
 * shown instead — leaving the column reading "FSIC" where the paper reads
 * "Fire". Officers know the codes; the panel this appears in is read by people
 * who do not, and the full name stays available as the `title` tooltip.
 *
 * Unknown codes fall back to the code itself rather than to a guess, so a
 * permit type added later is visibly unmapped instead of silently mislabelled.
 */
const PERMIT_TYPE_HEADINGS: Record<string, string> = {
  BUSINESS: 'Bus.',
  SANITARY: 'Sanitary',
  FSIC: 'Fire',
  ZONING: 'Zoning',
  OCCUPANCY: 'Occupancy',
  CEC: 'Environmental',
  MARKET: 'Market',
}

function shortPermitType(code: string, _label: string): string {
  return PERMIT_TYPE_HEADINGS[code] ?? code
}

function ExpiryPanel({ report }: { report: DashboardReport }) {
  const { columns, rows } = report.expiry

  return (
    <ProtoCard className="overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-left">
          <caption className="sr-only">
            Permits approaching expiry by permit type. The 30, 60 and 90 day columns overlap, so a
            permit counted in the 30 day column is counted in the other two as well.
          </caption>
          <thead>
            <tr className="border-b border-line text-[11px] uppercase tracking-wide text-ink-muted">
              <th scope="col" className="px-5 py-2.5 font-semibold">
                Window
              </th>
              {columns.map((column) => (
                <th
                  key={column.code}
                  scope="col"
                  className="px-4 py-2.5 text-right font-semibold"
                  title={column.label}
                >
                  {shortPermitType(column.code, column.label)}
                </th>
              ))}
              <th scope="col" className="px-5 py-2.5 text-right font-semibold">
                Total
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row: ExpiryRow) => (
              <tr
                key={row.window}
                className={`border-b border-line/60 last:border-0 ${row.expired ? 'bg-canvas' : ''}`}
              >
                <th
                  scope="row"
                  className={`px-5 py-2.5 text-[14px] text-ink ${row.expired ? 'font-bold' : 'font-normal'}`}
                >
                  {row.label}
                </th>
                {columns.map((column) => (
                  <td key={column.code} className="tnum px-4 py-2.5 text-right text-[14px] text-ink">
                    {num(row.counts[column.code] ?? 0)}
                  </td>
                ))}
                <td
                  className={`tnum px-5 py-2.5 text-right text-[14px] font-bold ${row.expired ? 'text-ink' : 'text-royal'}`}
                >
                  {num(row.total)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="border-t border-line px-5 py-2.5 text-[11px] text-ink-muted">
        Forward windows overlap: a permit expiring in 20 days is in all three. Expired is separate.
      </p>
    </ProtoCard>
  )
}

/* ── Ranked panels ─────────────────────────────────────────────────────── */

/**
 * A top-five ranking as the bar chart the spec asks for.
 *
 * THE AXIS-LABEL PROBLEM, AND WHY THERE ARE TWO MODES. Barangay names are one
 * word and fit under a bar. PSIC industry names are not — "Water collection,
 * treatment and supply (water refilling)" is the actual top category, and there
 * is no honest way to shorten it in code: truncating it to "Water collection…"
 * loses the parenthetical that tells a reader it means water refilling
 * stations. So long-named series label their bars by RANK and print the full
 * names, counts and shares in a numbered legend underneath, where they have the
 * width to be read. Nothing is abbreviated and nothing is invented.
 *
 * The legend is not merely a fallback: it is also what satisfies Never Color
 * Alone and what puts the counts on screen as text for every reader.
 */
function RankedBarsPanel({
  rows,
  name,
  title,
  categoryHeading,
  valueHeading,
  labelBy,
  footnote,
  empty,
}: {
  rows: (BarangayShareRow | LineOfBusinessRow)[]
  name: (row: BarangayShareRow | LineOfBusinessRow) => string
  title: string
  categoryHeading: string
  valueHeading: string
  /** `name` puts the name under each bar; `rank` puts "1".."5" and relies on the legend. */
  labelBy: 'name' | 'rank'
  footnote: string
  empty: string
}) {
  if (rows.length === 0) {
    return (
      <ProtoCard className="px-5 py-4">
        <p className="text-[13px] text-ink-secondary">{empty}</p>
      </ProtoCard>
    )
  }

  const color = (row: RankedShareRow) => (row.rank === 1 ? CHART_ROYAL : CHART_MUTED)

  const data: BarDatum[] = rows.map((row) => ({
    key: String(row.rank),
    label: labelBy === 'rank' ? String(row.rank) : name(row),
    value: row.count,
    valueText: num(row.count),
    note: pct(row.share),
    color: color(row),
  }))

  return (
    <ProtoCard className="px-4 pb-3 pt-4">
      <VerticalBars
        title={title}
        data={data}
        categoryHeading={categoryHeading}
        valueHeading={valueHeading}
        noteHeading="Share"
        tooltipUnit="businesses"
        legend={
          labelBy === 'rank'
            ? rows.map((row) => ({
                key: String(row.rank),
                label: `${row.rank}. ${name(row)}`,
                value: num(row.count),
                note: pct(row.share),
                color: color(row),
              }))
            : undefined
        }
        legendColumns={1}
        footer={footnote}
      />
    </ProtoCard>
  )
}

/* ── Form of Organization ──────────────────────────────────────────────── */

/*
 * Four legal forms, four tones. There is no ordinal meaning between a
 * corporation and a partnership, so this is a qualitative palette rather than a
 * ramp — but each entry still clears 3:1 on white, because a slice a reader
 * cannot see is a slice they cannot match to its legend swatch.
 */
const ORGANIZATION_COLORS = [CHART_ROYAL, CHART_TEAL, CHART_AMBER, CHART_PURPLE]

function OrganizationPanel({ report }: { report: DashboardReport }) {
  const { rows, recorded, unrecorded, total } = report.organization_forms

  const slices: ShareSlice[] = rows
    // Recharts draws a zero-value slice as an invisible wedge that still owns a
    // legend row; dropping it keeps the legend honest about what is in the pie.
    .filter((row) => row.count > 0)
    .map((row, i) => ({
      key: row.form,
      label: row.label,
      value: row.count,
      valueText: num(row.count),
      shareText: pct(row.share),
      color: ORGANIZATION_COLORS[i % ORGANIZATION_COLORS.length],
    }))

  return (
    <ProtoCard className="px-4 pb-3 pt-4">
      {recorded === 0 ? (
        /*
         * The honest empty state. `businesses.form_of_organization` exists and is
         * unpopulated, so four zero slices would read as "no corporations in
         * Malabon" rather than "nobody has filled this in". Deriving the form from
         * registration_type was considered and rejected: DTI vs SEC separates sole
         * proprietors from the rest but cannot tell a corporation from a
         * partnership, so half the panel would be a guess wearing a real column's
         * name.
         */
        <>
          <p className="text-[13px] font-semibold text-ink">Not recorded for any business yet</p>
          <p className="mt-1 text-[12px] leading-snug text-ink-secondary">
            None of the {num(total)} registered businesses has this field on file, and it is not
            inferred from anything else in the register.
          </p>
        </>
      ) : (
        <>
          <ShareChart
            title="Registered businesses by legal form of organization"
            slices={slices}
            variant="pie"
            categoryHeading="Form of organization"
            valueHeading="Businesses"
            shareHeading="Share of those recorded"
            footer={
              unrecorded > 0 && (
                <>
                  {num(unrecorded)} of {num(total)} businesses have no form on file and are excluded
                  from these shares.
                </>
              )
            }
          />
        </>
      )}
    </ProtoCard>
  )
}

/* ── Inspections ───────────────────────────────────────────────────────── */

/*
 * Passed / conditional / failed, best to worst — and none of them red.
 *
 * A failed inspection is a recorded finding about a premises, not a fault in
 * the system, so DESIGN.md's Red Means Stop keeps #bd0000 away from it; this is
 * the same reasoning that gives a statutory breach its amber above. The three
 * segments are also the only three: they partition the inspections actually
 * carried out, which is what makes stacking them truthful.
 */
const INSPECTION_SERIES = [
  { key: 'passed', label: 'Passed', color: CHART_ROYAL },
  { key: 'conditional', label: 'Conditional', color: CHART_AMBER },
  { key: 'failed', label: 'Failed', color: CHART_PURPLE },
]

function InspectionsPanel({ report }: { report: DashboardReport }) {
  /*
   * The per-office rows only. The "combined" row is the sum of them, so putting
   * it in the same stack would draw the total as a fourth office three times
   * the length of any real one — the classic way a stacked chart lies. It goes
   * underneath as a sentence instead.
   */
  const rows = report.inspections.rows
  const combined = report.inspections.combined

  return (
    <ProtoCard className="px-4 pb-3 pt-4">
      <StackedBars
        title="Inspection outcomes by inspecting office, with each office's pass rate"
        series={INSPECTION_SERIES}
        categoryHeading="Inspecting office"
        extraHeadings={['Scheduled', 'Completed']}
        rows={rows.map((row) => ({
          key: row.type,
          label: row.label,
          values: { passed: row.passed, conditional: row.conditional, failed: row.failed },
          valueText: row.pass_rate === null ? 'none carried out' : `${pct(row.pass_rate)} pass rate`,
          extras: [num(row.scheduled), num(row.completed)],
        }))}
        footer={
          <>
            <strong className="font-semibold text-ink">
              {combined.pass_rate === null ? 'No pass rate' : pct(combined.pass_rate)}
            </strong>{' '}
            overall — {num(combined.passed)} passed of {num(combined.completed)} carried out, from{' '}
            {num(combined.scheduled)} scheduled. The rate divides by inspections carried out, never
            by ones scheduled.
            <Info metric="inspections.pass_rate" />
          </>
        }
      />
      {/*
        Per-office pass rates as text. The stack shows the mix; it cannot show
        the rate, because a long bar can belong to a busy office with a poor
        rate. Both readings have to be available, and this is the one that
        survives being photocopied.
      */}
      <ul className="mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5 text-[11px] text-ink-muted">
        {rows.map((row) => (
          <li key={row.type}>
            {row.label}{' '}
            <span className="tnum font-semibold text-ink">
              {row.pass_rate === null ? 'none carried out' : pct(row.pass_rate)}
            </span>{' '}
            of {num(row.completed)} done
          </li>
        ))}
      </ul>
    </ProtoCard>
  )
}

/* ── Officer Activity ──────────────────────────────────────────────────── */

function OfficerPanel({ report }: { report: DashboardReport }) {
  const a = report.officer_activity

  return (
    /*
     * Three cards, as the spec asks — and the split is the point. The client's
     * note on this panel was that "officer activity" averages three unrelated
     * measures under one label: how fast staff answer, how many requests they
     * close, how many meetings they turn up to. Nothing here is a breakdown of
     * anything else, so nothing here shares a denominator or a card.
     */
    <div className="grid gap-4 sm:grid-cols-3">
      <StatCard
        label="Response time"
        metric="officer_activity.mean_response_hours"
        unavailable={a.mean_response_hours === null}
        value={a.mean_response_hours === null ? 'No replies yet' : a.mean_response_hours.toFixed(1)}
        unit="h"
        detail={
          (a.mean_response_hours === null
            ? 'No applicant message has been answered in this window.'
            : `Mean over ${num(a.responses)} ${a.responses === 1 ? 'reply' : 'replies'}; median ${a.median_response_hours?.toFixed(1)}h.`) +
          (a.threads_awaiting_reply > 0
            ? ` ${num(a.threads_awaiting_reply)} ${a.threads_awaiting_reply === 1 ? 'thread' : 'threads'} still waiting.`
            : '')
        }
      />

      <StatCard
        label="Requests fulfilled"
        metric="officer_activity.requests_fulfilled_rate"
        unavailable={a.requests_total === 0}
        value={a.requests_total === 0 ? 'None raised' : num(a.requests_fulfilled)}
        detail={
          a.requests_total === 0
            ? 'No officer request was raised in this window.'
            : `${num(a.requests_fulfilled)} of ${num(a.requests_total)} raised (${pct(a.requests_fulfilled_rate)}).`
        }
      />

      <StatCard
        label="Meetings attended"
        metric="officer_activity.meetings_attended_rate"
        unavailable={a.meetings_scheduled === 0}
        value={a.meetings_scheduled === 0 ? 'None scheduled' : num(a.meetings_attended)}
        detail={
          a.meetings_scheduled === 0
            ? /*
               * A true zero, and it must not read as 0% participation: the
               * column exists and nothing has ever been written to it, so no
               * officer has skipped anything.
               */
              'No meeting has been scheduled in this window, so there is no participation to report.'
            : `${num(a.meetings_attended)} of ${num(a.meetings_scheduled)} scheduled had a recorded response (${pct(a.meetings_attended_rate)}).`
        }
      />
    </div>
  )
}

/* ── GIS Mapping ───────────────────────────────────────────────────────── */

const MALABON: [number, number] = [14.669, 120.957]

/**
 * Frame the map on the businesses it is plotting, and keep it framed.
 *
 * A fixed centre and zoom left the whole register as a small clump off to one
 * side of a mostly empty tile — technically correct and useless to read. Fitting
 * the bounds means the panel always shows the extent of what is actually on
 * record, whether that is one barangay or the whole city.
 *
 * The ResizeObserver is not optional. Leaflet caches its container size, so when
 * the panel changes width — a window resize, the sidebar collapsing, a tall
 * full-page capture — the map keeps its old pixel dimensions and the fitted view
 * silently drifts off centre. `invalidateSize()` is what tells it to look again,
 * and re-fitting afterwards is what puts the businesses back in frame.
 */
function KeepFitted({ bounds }: { bounds: [[number, number], [number, number]] | null }) {
  const map = useMap()

  useEffect(() => {
    if (bounds === null) return

    const fit = () => {
      map.invalidateSize()
      map.fitBounds(bounds, { padding: [24, 24] })
    }

    const observer = new ResizeObserver(fit)
    observer.observe(map.getContainer())

    return () => observer.disconnect()
  }, [map, bounds])

  return null
}

/**
 * The corners of the box containing every plotted business, or null when there is
 * nothing to plot.
 *
 * Two corners rather than the whole point list: `fitBounds` accepts either, but
 * the two-corner form is what `MapContainer`'s `bounds` prop wants, and computing
 * it once here keeps the initial framing and the re-framing on resize working from
 * exactly the same numbers.
 */
function pointBounds(points: MapPoint[]): [[number, number], [number, number]] | null {
  if (points.length === 0) return null

  let south = points[0].latitude
  let north = points[0].latitude
  let west = points[0].longitude
  let east = points[0].longitude

  for (const p of points) {
    if (p.latitude < south) south = p.latitude
    if (p.latitude > north) north = p.latitude
    if (p.longitude < west) west = p.longitude
    if (p.longitude > east) east = p.longitude
  }

  return [
    [south, west],
    [north, east],
  ]
}

function BusinessMap({ report }: { report: DashboardReport }) {
  const { points, plotted, mapped, total_businesses } = report.map
  const bounds = pointBounds(points)

  /*
   * Lapsed permits render last, which in Leaflet means on top.
   *
   * At 700+ points on one city the markers overlap heavily, and whichever pin
   * happens to come later in the server's ordering wins the pixel. That made
   * the panel's answer to "who is trading without a permit" depend on row
   * order: a lapsed business could sit invisible under a valid neighbour. Sort
   * on the state alone, not on anything else, so the framing, the counts and
   * the popups are untouched — only the stacking changes.
   */
  const drawOrder = [...points].sort(
    (a, b) =>
      (a.permit_state === 'active' ? 0 : 1) - (b.permit_state === 'active' ? 0 : 1),
  )

  return (
    <ProtoCard className="p-4">
      {/*
       * The legend swatches mirror the markers exactly — same colour, same
       * hollow-vs-solid, same relative size. A legend that only matched on
       * colour would be the one thing a red-green colour-blind officer could
       * not use to decode the map, which is the whole point of drawing the
       * states differently in the first place.
       */}
      <p className="mb-2 flex flex-wrap items-center gap-4 text-[11px] text-ink-muted">
        <span className="flex items-center gap-1.5">
          <span
            // h-3 against the lapsed h-3.5, tracking the markers' 4.5:5.5. A
            // legend that shows a different size ratio from the thing it
            // explains is teaching the reader the wrong cue.
            className="h-3 w-3 rounded-full border-[1.75px] bg-white"
            style={{ borderColor: MAP_VALID }}
            aria-hidden="true"
          />
          Permit valid today
        </span>
        <span className="flex items-center gap-1.5">
          <span
            className="h-3.5 w-3.5 rounded-full"
            style={{ backgroundColor: MAP_LAPSED }}
            aria-hidden="true"
          />
          No valid permit
        </span>
      </p>
      <div className="overflow-hidden rounded-lg">
        <MapContainer
          /*
           * `bounds` frames the map on the businesses actually on record; the
           * centre and zoom are the fallback for when none has coordinates, so the
           * panel still shows Malabon rather than the whole world.
           */
          bounds={bounds ?? undefined}
          boundsOptions={{ padding: [24, 24] }}
          center={bounds ? undefined : MALABON}
          zoom={bounds ? undefined : 13}
          scrollWheelZoom={false}
          /*
           * 420, down from 520. The map is the single tallest block on the
           * dashboard and the client's note was that the screen has too much
           * dead space; at full width this still frames the whole register with
           * room to tell the barangays apart, and the zoom control is there for
           * anyone who needs closer. (Scroll-wheel zoom stays off on purpose —
           * a map that swallows the page scroll is a trap on a long dashboard.)
           */
          style={{ height: 420, width: '100%' }}
          aria-label="Map of registered businesses across Malabon"
        >
          <TileLayer
            attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          />
          <KeepFitted bounds={bounds} />
          {drawOrder.map((point: MapPoint) => (
            <CircleMarker
              key={point.business_id}
              center={[point.latitude, point.longitude]}
              /*
               * Size is the primary cue, colour the secondary one — see
               * MAP_VALID/MAP_LAPSED for why that order matters here. The
               * lapsed disc is deliberately the bigger and the solid one: it is
               * what an officer opens this map to find, and a hollow marker is
               * carried only by its ring.
               */
              /*
               * 4.5 against 5.5, not 3.5 against 5.5. At the wider gap the
               * lapsed disc had 2.47x the area of a valid ring and sat on top
               * of it, so red carried about 60% of the ink on a register that
               * is 37% lapsed — the map read as a city in far worse shape than
               * it is, which is its own kind of dishonesty on a screen an
               * officer skims. Closing the gap puts the ink near the true share
               * while leaving solid-versus-hollow, the draw order and the
               * colour to do the telling apart.
               */
              radius={point.permit_state === 'active' ? 4.5 : 5.5}
              pathOptions={{
                color: point.permit_state === 'active' ? MAP_VALID : MAP_LAPSED,
                // The ring is the whole marker when it is hollow, so the valid
                // state gets the heavier stroke of the two despite being small.
                weight: point.permit_state === 'active' ? 1.75 : 1,
                fillColor: point.permit_state === 'active' ? '#ffffff' : MAP_LAPSED,
                // Valid pins stay washy so several hundred of them read as
                // density rather than merging into one solid shape; lapsed pins
                // stay near-opaque so no single one can be washed out.
                fillOpacity: point.permit_state === 'active' ? 0.55 : 0.9,
              }}
            >
              <Popup>
                <span className="font-semibold">{point.business}</span>
                {point.barangay && <> · {point.barangay}</>}
                <br />
                {point.permit_state === 'active' ? 'Permit valid today' : 'No valid permit'}
              </Popup>
            </CircleMarker>
          ))}
        </MapContainer>
      </div>
      <p className="mt-2 text-[11px] text-ink-muted">
        {plotted === 0 ? (
          'No business has coordinates on record yet, so there is nothing to plot.'
        ) : (
          <>
            {num(plotted)} of {num(total_businesses)} businesses plotted from their
            business-location coordinates.
            {mapped > plotted && (
              <> {num(mapped - plotted)} more have coordinates but exceed this layer&rsquo;s cap.</>
            )}
          </>
        )}
      </p>
    </ProtoCard>
  )
}

/* ── page ──────────────────────────────────────────────────────────────── */

function LoadingState() {
  return (
    <div className="space-y-4">
      <SkeletonCards count={4} />
      <div className="grid gap-4 lg:grid-cols-2">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-52 w-full rounded-2xl" />
        ))}
      </div>
    </div>
  )
}

export function AnalyticsPage() {
  const [months, setMonths] = useState('12')

  const {
    data: result,
    loading,
    error,
    reload,
  } = useAsync(() => analytics.dashboard(Number(months)), [months])

  const data = result?.data
  const meta = result?.meta

  const monthWindow = data
    ? new Date(`${data.month_start}T00:00:00`).toLocaleDateString('en-PH', {
        month: 'long',
        year: 'numeric',
      })
    : ''
  const trailing = data ? `Last ${data.window_months} months to ${dateLabel(data.today)}` : ''
  const asOf = data ? `As of ${dateLabel(data.today)}` : ''

  return (
    <div>
      <PageTitle
        right={
          <span className="flex items-center gap-3 pb-1">
            <FilterMenu
              label="Filter the dashboard"
              fields={[
                {
                  label: 'Trailing window',
                  value: months,
                  options: PERIOD_OPTIONS,
                  onChange: setMonths,
                },
              ]}
            />
            <GenerateReportButton onGenerate={() => analytics.dashboardReport(Number(months))} />
          </span>
        }
      >
        Analytics Dashboard
      </PageTitle>

      <AnalyticsTabs />

      {meta && <ComputedAt meta={meta} onRefreshed={reload} />}

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : data ? (
        <MetricDefinitions value={meta?.definitions}>
          {/*
            SPACING. The client's note on this screen was "remove large spaces",
            and the fix is a single rhythm rather than a per-panel guess: gap-4
            between cards in a row, mt-5 between blocks, and headings that sit on
            one line with their window. Anything looser and the dashboard needs
            three scrolls to answer a question an LGU officer asked once.
          */}
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Kpi
              value={num(data.kpis.active_businesses)}
              label="Active Businesses"
              hint="holding a permit valid today"
              metric="kpis.active_businesses"
            />
            <Kpi
              value={num(data.kpis.applications_ytd)}
              label="Applications YTD"
              hint={`since ${dateLabel(data.ytd_start)}`}
              metric="kpis.applications_ytd"
            />
            <Kpi
              value={num(data.kpis.applications_this_month)}
              label="This Month"
              hint={monthWindow}
              metric="kpis.applications_this_month"
            />
            <Kpi
              value={
                data.kpis.compliance_rate === null
                  ? '—'
                  : `${data.kpis.compliance_rate.toFixed(0)}%`
              }
              label="Compliance Rate"
              hint="permit validity"
              metric="kpis.compliance_rate"
            />
          </div>

          <div className="mt-5 grid gap-x-5 gap-y-5 *:min-w-0 lg:grid-cols-2">
            <section>
              <SectionHeading note={monthWindow} metric="volume">Application Volume</SectionHeading>
              <VolumePanel report={data} />
            </section>

            <section>
              {/*
                No `metric` here: DecisionsPanel already puts the approval-rate
                info button beside the rate itself. Adding a second one to the
                section heading rendered the same control twice under one
                accessible name ("How Approval rate is measured"), which for a
                screen-reader user moving button to button is two identical
                stops explaining one figure. The button belongs next to the
                number it explains, not on the heading above it.
              */}
              <SectionHeading note={monthWindow}>Decision Outcomes</SectionHeading>
              <DecisionsPanel report={data} />
            </section>

            <section>
              <SectionHeading note={trailing} metric="processing_tiers">
                Average Processing Time by RA 11032 Tier
              </SectionHeading>
              <TierPanel report={data} />
            </section>

            <section>
              <SectionHeading note={trailing} metric="stages">Average Processing Time by Department</SectionHeading>
              <StagePanel report={data} />
            </section>
          </div>

          <section className="mt-5">
            <SectionHeading note={trailing}>Compliance Monitoring</SectionHeading>
            <CompliancePanel report={data} />
          </section>

          <div className="mt-5 grid gap-x-5 gap-y-5 *:min-w-0 lg:grid-cols-2">
            <section>
              <SectionHeading note={asOf} metric="expiry">Permits Approaching Expiry</SectionHeading>
              <ExpiryPanel report={data} />
            </section>

            <section>
              {/* Same as Decision Outcomes above: the pass-rate info button
                  already sits beside the pass rate inside the panel. */}
              <SectionHeading note={trailing}>Inspections</SectionHeading>
              <InspectionsPanel report={data} />
            </section>

            {/*
              "Top 5", not "Top": the shares here do not sum to 100 and the
              client called that out by name. A title that claims to rank every
              barangay while showing five of twenty-one is the misread.
            */}
            <section>
              <SectionHeading note={asOf} metric="top_barangays">
                Top 5 Barangays by Active Businesses
              </SectionHeading>
              <RankedBarsPanel
                rows={data.top_barangays.rows}
                name={(row) => (row as BarangayShareRow).barangay}
                title="The five barangays with the most active businesses"
                categoryHeading="Barangay"
                valueHeading="Active businesses"
                labelBy="name"
                footnote={`Counted out of the ${num(data.top_barangays.total)} active businesses with a barangay on record, across ${num(data.top_barangays.groups)} barangays.`}
                empty="No active business has a barangay address on record, so there is nothing to rank."
              />
            </section>

            <section>
              <SectionHeading note={asOf} metric="top_lines_of_business">
                Top 5 Business Categories
              </SectionHeading>
              <RankedBarsPanel
                rows={data.top_lines_of_business.rows}
                name={(row) => (row as LineOfBusinessRow).industry}
                title="The five most common lines of business among active businesses"
                categoryHeading="Rank"
                valueHeading="Active businesses"
                labelBy="rank"
                footnote={`Grouped by PSIC code across ${num(data.top_lines_of_business.groups)} lines on record; shares are of the ${num(data.top_lines_of_business.total)} active businesses with one recorded.`}
                empty="No active business has a line of business on record, so there is nothing to rank."
              />
            </section>

            <section>
              <SectionHeading note={asOf} metric="organization_forms">Form of Organization</SectionHeading>
              <OrganizationPanel report={data} />
            </section>

            <section>
              <SectionHeading note={trailing}>Staff Activity</SectionHeading>
              <OfficerPanel report={data} />
            </section>
          </div>

          <section className="mt-5">
            <SectionHeading note={asOf} metric="map">GIS Mapping</SectionHeading>
            <BusinessMap report={data} />
          </section>
        </MetricDefinitions>
      ) : null}
    </div>
  )
}
