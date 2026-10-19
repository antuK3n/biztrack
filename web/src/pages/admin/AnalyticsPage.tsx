import { createContext, useContext, useEffect } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import type { ReactNode } from 'react'
import { CircleMarker, MapContainer, Popup, TileLayer, useMap } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'
import { Skeleton, SkeletonCards } from '../../components/ui/primitives'
import { Info, MetricDefinitions } from '../../components/ui/MetricInfo'
import { FilterMenu, PageTitle, ProtoCard, SectionTitle } from '../../components/ui/Proto'
import { HorizontalBars, VerticalBars } from '../../components/charts/Bars'
import type { BarDatum } from '../../components/charts/Bars'
import { CartesianGrid, Line, LineChart, Tooltip, XAxis, YAxis } from 'recharts'
import {
  CHART_AMBER,
  CHART_AXIS_TICK,
  CHART_GRID,
  CHART_MUTED,
  CHART_PURPLE,
  CHART_ROYAL,
  CHART_SLATE,
  CHART_TEAL,
  CHART_TOOLTIP,
  ChartFrame,
} from '../../components/charts/ChartFrame'
import { ShareChart } from '../../components/charts/ShareChart'
import type { ShareSlice } from '../../components/charts/ShareChart'
import { StackedBars } from '../../components/charts/StackedBars'
import { activePortal, portalPath } from '../../lib/api'
import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type {
  BarangayShareRow,
  ComplianceIndicator,
  DashboardReport,
  LineOfBusinessRow,
  MapPoint,
  RankedShareRow,
  SuspendedPermits,
} from '../../lib/types'
import { formatMoney } from '../../lib/format'
import { AnalyticsError } from './AnalyticsError'
import { AnalyticsTabs } from './AnalyticsTabs'
import { ComputedAt } from './ComputedAt'
import { OfficeScope } from './OfficeScope'
import { officePermit } from './officePermit'
import type { OfficePermit } from './officePermit'

/*
 * Analytics Dashboard — docs/r-integration-spec.md §1, mockup 115/116.
 *
 * Everything is computed server-side (App\Support\DashboardAnalytics) and
 * rendered as given. This file does no arithmetic beyond bar widths: no rate is
 * derived here, so there is no second implementation of a formula that could
 * drift from the one the server ships.
 *
 * THREE RULES THIS SCREEN IS BUILT AROUND
 *
 * 1. A null is not a zero. Every figure that can be null renders as a stated
 *    reason — "No filings on record", "Cannot be computed" — never as 0 or 0%. A
 *    dash is honest; a zero is a claim nobody computed.
 *
 * 2. Each panel says which window it used. Volume and outcomes are this month;
 *    fees are this month and this year; the means and rates run on a trailing
 *    window; the rankings, suspended permits and the map are as of today.
 *    Nothing here implies one clock.
 *
 * 3. Only what the register records. On 18 October 2026 the client took off
 *    every panel resting on something nobody fills in: the RA 11032 tier panel
 *    and its compliance card (a tier BPLO no longer sets), the renewal estimate
 *    (too little history), response time and meetings (nothing records them),
 *    and the Cancelled outcome (applicants can no longer cancel). Fees Collected
 *    and Suspended Permits came on, and the department panel became Time in
 *    Office. On 19 October 2026 the client's picker also took off what was left
 *    of the officer panel, Messages & Requests. Do not put a panel back without
 *    the data under it.
 *
 * THE CHART TYPES ARE NOT A STYLE CHOICE. The client's "R INTEGRATION DRAFTS"
 * names a required visualisation per report, and it was written down because
 * the first build of this screen answered every question with a table. The
 * mapping this file implements, so nobody has to re-derive it from the spec:
 *
 *   Application Volume                        vertical bar chart
 *   Decision Outcomes                         donut, approval rate in the hole
 *   Time in Office                            horizontal bar chart
 *   Fees Collected                            two KPI cards and a table
 *   Compliance Rate                           two KPI cards
 *   Suspended Permits                         two KPI cards and a short list
 *   Top Five Barangays / Business Categories  vertical bar charts
 *   Form of Organization                      pie chart
 *   Inspections                               horizontal STACKED bar chart
 *
 * Every one of them goes through components/charts, which is what supplies the
 * accessible name and the sr-only table of the underlying numbers. A chart
 * dropped straight into this file would be an unreadable graphic to a screen
 * reader; read components/charts/ChartFrame.tsx before adding one.
 *
 * THE HEADINGS ARE THE PAPER'S TEN REPORT NAMES, spelled as its §1 table spells
 * them, because the client's instruction is to follow the paper's terms. Each
 * heading's `metric` pulls its info-button name from the same `label` in
 * AnalyticsDefinitions::dashboard(), so a heading renamed here without renaming
 * it there gives one figure two names — which is the fault this screen is least
 * able to afford.
 *
 * The prose on this screen is deliberately short, and got shorter. It used to
 * run to a paragraph per panel; the client's verdict was that the lengthy text
 * made the screen overwhelming and hard to understand on a first visit. What a
 * neighbouring label already says is not repeated, and what survives is the
 * handful of sentences that exist to stop a specific misreading — the legal
 * limits, the denominators, the exclusions. Those are shortened, never dropped.
 * Everything else lives behind the info affordance beside its label
 * (components/ui/MetricInfo), where it costs nothing to whoever does not want
 * it. Resist putting it back inline.
 */

/*
 * The "needs attention" tone — a suspension past 30 days, since the RA 11032
 * tier panel that introduced it was removed (18 October 2026). Deliberately NOT
 * #bd0000: DESIGN.md reserves that for errors and destructive actions and
 * forbids it as a data colour. A long suspension is a finding about the
 * backlog, not a system error — so it reads as a heavy amber that clears 4.5:1
 * on white, and it also carries a word and an icon, because colour alone would
 * fail both WCAG 2.1 AA and the Never Color Alone rule.
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

/** The window the URL asks for, when it is one the menu offers; 12 otherwise. */
function windowFrom(value: string | null): string {
  return PERIOD_OPTIONS.some((option) => option.value === value) ? (value as string) : '12'
}

/*
 * officeWait() and waitText() formatted the officer response time, removed on
 * 18 October 2026 with the figure (nothing records replies). They are in git
 * history if a reply-time figure comes back.
 */

function pct(value: number | null): string {
  return value === null ? '—' : `${value.toFixed(1)}%`
}

function num(value: number): string {
  return value.toLocaleString()
}

/** "2026-07-30" reads as "July 30, 2026" — the month in full (checklist, Apply for Permit 7). */
function dateLabel(iso: string): string {
  const [y, m, d] = iso.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('en-PH', {
    day: 'numeric',
    month: 'long',
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
 *
 * An h3 when the dashboard is a section of the office Home (embedded), where
 * the section's own "Analytics" heading is the h2; an h2 on its own page.
 */
const PanelHeadingLevel = createContext<'h2' | 'h3'>('h2')

function SectionHeading({ children, note, metric }: { children: ReactNode; note?: string; metric?: string }) {
  const Heading = useContext(PanelHeadingLevel)
  return (
    <div className="mb-1.5 flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5">
      {/*
       * The info button is a sibling of the h2, not a child of it. Nesting it
       * inside folds "How X is measured" into the heading's accessible name, so
       * anyone navigating by heading hears the button on every section.
       */}
      <div className="flex items-center">
        <Heading className="text-[17px] font-semibold text-ink">{children}</Heading>
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
  window,
}: {
  value: string
  unit?: string
  label: string
  detail: string
  unavailable?: boolean
  metric?: string
  /** The card's own window, when the cards in a row do not share one. */
  window?: string
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
      {window && <p className="mt-1 text-[11px] font-semibold text-ink-secondary">{window}</p>}
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
  /*
   * Nothing filed yet: a sentence, not three bars of height zero. On the first
   * of the month that is every office's dashboard, and three empty bars over
   * "0 total submitted" read as a chart that failed to load.
   */
  if (report.volume.total === 0) {
    return (
      <ProtoCard className="px-5 py-4">
        <p className="text-[13px] text-ink-secondary">Nothing filed yet this month.</p>
      </ProtoCard>
    )
  }

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
}

function DecisionsPanel({ report }: { report: DashboardReport }) {
  const { rows, approval_rate, approved, decisioned, total } = report.decisions

  /*
   * With nothing filed this month there is nothing to have decided, and
   * "Nothing filed this month has been decided yet" implied filings waiting on
   * a decision that do not exist. Same sentence as the volume panel beside it.
   */
  if (total === 0) {
    return (
      <ProtoCard className="px-5 py-4">
        <p className="text-[13px] text-ink-secondary">Nothing filed yet this month.</p>
      </ProtoCard>
    )
  }
  /*
   * Zero-count outcomes are dropped from the plot: recharts renders them as an
   * invisible wedge with a visible legend entry, which reads as "we lost this
   * one". There is no Cancelled row to hide any more — the server stopped
   * sending it on 18 October 2026, since applicants can no longer cancel.
   */
  const plotted = rows.filter((row) => row.count > 0)

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

/* ── Time in Office ────────────────────────────────────────────────────── */

/*
 * TierPanel stood before this, drawing each RA 11032 tier against its legal
 * limit. It went on 18 October 2026 with its compliance card: both rested on a
 * complexity tier BPLO has not set since 27 September, so every bar was a
 * statement about a column nobody fills. It is in git history if a tier that
 * someone sets comes back.
 */

/**
 * Office headings in the paper's words.
 *
 * The register stores each office's full legal name ("Bureau of Fire
 * Protection"), too long for a 104px label. Officers read codes fluently; this
 * panel is for LGU leadership, who do not. Full name stays in the sentence
 * under the chart.
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
}

/**
 * Time in Office: working days from the day an office receives a permit to the
 * day it issues or refuses it (client, 18 October 2026).
 *
 * It replaced "Average Processing Time by Department", which timed review
 * assignments that close at routing — every office read 0 days and the panel
 * announced "CHO 0 days" as the bottleneck. The review, the wait for the visit
 * and the visit are all inside now, and BPLO is drawn beside the others.
 *
 * Who is drawn, and whether a slowest office is named, are both decided by the
 * server (`drawn`, `bottleneck`): an office with too few permits is left off
 * the chart, and a slowest office is named only when it is at least a day and a
 * quarter above the average. With no name, the panel says nothing about speed —
 * a tie broken alphabetically is not a finding.
 */
function TimeInOfficePanel({ report }: { report: DashboardReport }) {
  const { rows: allRows, bottleneck, mean_days } = report.stages
  const rows = allRows.filter((r) => r.drawn)

  if (rows.length === 0) {
    return (
      <ProtoCard className="px-5 py-4">
        <p className="text-[13px] text-ink-secondary">
          {allRows.length === 0
            ? 'No office issued or refused a permit in this window.'
            : `Too few permits per office to average yet (fewer than ${report.stages.min_reviews} each).`}
        </p>
      </ProtoCard>
    )
  }

  const data: BarDatum[] = rows.map((row) => ({
    key: row.code,
    label: DEPARTMENT_HEADINGS[row.code] ?? row.code,
    value: row.mean_days,
    valueText: `${row.mean_days.toFixed(1)} working days`,
    note: `${num(row.reviews)} permits`,
    // Royal only when the server named it slowest; otherwise one tone, so
    // colour does not point at a difference nobody claimed.
    color: row.code === bottleneck?.code ? CHART_ROYAL : CHART_MUTED,
  }))

  return (
    <ProtoCard className="px-4 pb-3 pt-4">
      <HorizontalBars
        title="Average working days a permit spends with each office"
        data={data}
        categoryHeading="Office"
        valueHeading="Average working days"
        noteHeading="Permits issued or refused"
        categoryWidth={104}
        tooltipUnit="working days"
        footer={
          bottleneck ? (
            <>
              <strong className="font-semibold text-ink">{bottleneck.name}</strong> is the slowest at{' '}
              {bottleneck.mean_days.toFixed(1)} working days, {bottleneck.above_average_days.toFixed(1)} over
              the {mean_days?.toFixed(1)}-day average.
            </>
          ) : undefined
        }
      />
    </ProtoCard>
  )
}

/* ── Fees Collected ────────────────────────────────────────────────────── */

/** Short permit names for the fee rows; the register's full name is the fallback. */
const FEE_HEADINGS: Record<string, string> = {
  BUSINESS: 'Business Permit',
  SANITARY: 'Sanitary',
  FSIC: 'FSIC',
  ZONING: 'Zoning',
  OCCUPANCY: 'Occupancy',
  CEC: 'CEC',
  OTHER: 'Other',
}

/*
 * Money received, this month and this year (client's picker, 18 October 2026).
 * Two totals a Treasurer reads first, then a row per permit — a table, not a
 * chart, because these are figures to copy into a report. Every row is shown
 * at ₱0.00 too, so an empty row reads as nothing collected rather than missing.
 */
function FeesPanel({ report }: { report: DashboardReport }) {
  const fees = report.fees_collected
  const year = fees.year_start.slice(0, 4)

  return (
    <div className="space-y-3">
      {/*
        Not StatCard: its 26px figure does not fit "₱1,234,567.89" in half a
        phone-width row. Same card, a size smaller, allowed to wrap.
      */}
      <div className="grid grid-cols-2 gap-4">
        {[
          { key: 'month', label: 'This month', total: fees.month_total, payments: fees.payments_month },
          { key: 'year', label: `This year (${year})`, total: fees.year_total, payments: fees.payments_year },
        ].map((card) => (
          <ProtoCard key={card.key} className="px-4 py-3.5">
            <p className="text-[11px] uppercase tracking-wide text-ink-muted">{card.label}</p>
            <p className="tnum mt-1 break-words text-[20px] font-bold leading-tight text-royal sm:text-[24px]">
              {formatMoney(card.total)}
            </p>
            <p className="mt-1 text-[11px] text-ink-muted">
              {num(card.payments)} {card.payments === 1 ? 'payment' : 'payments'}
            </p>
          </ProtoCard>
        ))}
      </div>
      <ProtoCard className="overflow-hidden">
        <table className="w-full text-left">
          <caption className="sr-only">Fees collected by permit, this month and this year</caption>
          <thead>
            <tr className="border-b border-line text-[11px] uppercase tracking-wide text-ink-muted">
              <th scope="col" className="px-4 py-2 font-semibold">Permit</th>
              <th scope="col" className="px-3 py-2 text-right font-semibold">This month</th>
              <th scope="col" className="px-4 py-2 text-right font-semibold">This year</th>
            </tr>
          </thead>
          <tbody>
            {fees.rows.map((row) => (
              <tr key={row.code} className="border-b border-line/60 last:border-0">
                <th scope="row" className="px-4 py-1.5 text-[14px] font-normal text-ink" title={row.label}>
                  {FEE_HEADINGS[row.code] ?? row.label}
                </th>
                <td className="tnum px-3 py-1.5 text-right text-[14px] text-ink">{formatMoney(row.month)}</td>
                <td className="tnum px-4 py-1.5 text-right text-[14px] text-ink-secondary">
                  {formatMoney(row.year)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </ProtoCard>
    </div>
  )
}

/* ── Compliance Rate ───────────────────────────────────────────────────── */

/**
 * One compliance indicator, one card, and the separation is load-bearing, not
 * cosmetic. The two rates count different populations (businesses ever issued
 * a permit, permits due for renewal), so anything that presents them as one
 * series invites a reader to average or compare them. Each card states its own
 * denominator underneath for the same reason. The spec's third card, RA 11032
 * processing, went on 18 October 2026: it judged filings by a complexity tier
 * BPLO no longer sets.
 */
/*
 * The words each card is printed with, for the office on screen.
 *
 * The permit-validity card is the one whose meaning moves with the office: for
 * City Health it tests sanitary permits only, so "Business permit compliance"
 * and "hold a valid permit for every type they have been issued" were both
 * wrong there. Scoped to one office the office issues one permit type, so the
 * sentence names it. Every office together keeps the server's wording.
 */
function complianceWords(
  indicator: ComplianceIndicator,
  permit: OfficePermit,
  scoped: boolean,
): { label: string; numerator: string; denominator: string } {
  if (indicator.indicator === 'permit_validity' && scoped) {
    return {
      label: `${permit.label} compliance`,
      numerator: `hold a ${permit.noun} that is valid today`,
      denominator: `businesses ever issued a ${permit.noun}`,
    }
  }
  return {
    label: indicator.label,
    numerator: indicator.numerator_label,
    denominator: indicator.denominator_label,
  }
}

/**
 * Why a rate is missing, when the server did not say.
 *
 * A null rate with no `unavailable_reason` means an empty denominator. For the
 * renewal card that used to print "No permits due for renewal in this window",
 * which is a claim, and for most offices a false one: City Health's sanitary
 * permits fall due every month. What is actually empty is the set of due
 * permits a renewal filing can be credited against — a renewal names the one
 * permit it replaces (`prior_permit_id`), and an office whose permit is never
 * the one named has nothing to count. The sentence says that, and claims
 * nothing about how many permits fell due.
 */
function missingRateReason(indicator: ComplianceIndicator, permit: OfficePermit): string {
  if (indicator.indicator === 'renewal') {
    return `No ${permit.noun} that fell due in this window is named by a renewal filing, so there is nothing to count on-time renewals against.`
  }
  return `No ${indicator.denominator_label} in this window, so there is nothing to count this against.`
}

function ComplianceCard({
  indicator,
  permit,
  scoped,
  window,
}: {
  indicator: ComplianceIndicator
  permit: OfficePermit
  scoped: boolean
  window: string
}) {
  const unavailable = indicator.rate === null
  const words = complianceWords(indicator, permit, scoped)

  return (
    <StatCard
      value={unavailable ? 'Cannot be computed' : `${indicator.rate?.toFixed(0)}`}
      unit={unavailable ? undefined : '%'}
      unavailable={unavailable}
      label={words.label}
      // Keyed off the row's own identifier rather than a literal, so the
      // indicators cannot be wired to each other's definitions.
      metric={`compliance.${indicator.indicator}`}
      detail={
        unavailable
          ? (indicator.unavailable_reason ?? missingRateReason(indicator, permit))
          : `${num(indicator.numerator)} of ${num(indicator.denominator)} ${words.denominator} ${words.numerator}.`
      }
      window={window}
    />
  )
}

/*
 * Each card states its own window, and the heading states none. The two do
 * not share one: renewals count the trailing months the filter sets, while
 * permit validity is a fact about today. A heading reading "Last 12 months"
 * over both would put a twelve-month label on an as-of-today figure.
 */
function CompliancePanel({
  report,
  permit,
  scoped,
  trailing,
  asOf,
}: {
  report: DashboardReport
  permit: OfficePermit
  scoped: boolean
  trailing: string
  asOf: string
}) {
  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {report.compliance.map((indicator) => (
        <ComplianceCard
          key={indicator.indicator}
          indicator={indicator}
          permit={permit}
          scoped={scoped}
          window={indicator.indicator === 'permit_validity' ? asOf : trailing}
        />
      ))}
    </div>
  )
}

/* ── Permits Approaching Expiry ────────────────────────────────────────── */

/*
 * Back from Renewal Risk Prediction, which was removed (checklist 2026-09-27,
 * item 6). It is a table rather than a chart because an office reads it as a
 * worklist — "how many sanitary permits fall due in the next 30 days" is a
 * number to copy into a plan, not a shape to compare.
 *
 * The windows nest (30 ⊂ 60 ⊂ 90), exactly as the payload computes them, and the
 * footnote says so, because a reader adding the three rows gets a figure that
 * counts the nearest permits three times.
 */
const PERMIT_TYPE_HEADINGS: Record<string, string> = {
  BUSINESS: "Mayor's",
  SANITARY: 'Sanitary',
  FSIC: 'Fire',
  ZONING: 'Zoning',
  OCCUPANCY: 'Occupancy',
  CEC: 'Environmental',
}

function ExpiryPanel({ report }: { report: DashboardReport }) {
  const { columns, rows } = report.expiry

  if (columns.length === 0) {
    return (
      <ProtoCard className="px-4 py-4 text-[13px] text-ink-muted">
        No permit has been issued in this scope yet, so nothing can fall due.
      </ProtoCard>
    )
  }

  /*
   * The first and last columns stay put while the permit types scroll between
   * them. At 390px the six permit-type columns pushed Total off the right edge,
   * with nothing to say it was there — and Total is the column an office plans
   * from. White backgrounds so the scrolling cells pass under, not through.
   */
  return (
    <ProtoCard className="overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-left">
          <caption className="sr-only">
            Permits approaching expiry by window and permit type. The windows nest, so the 90-day
            row includes the 30- and 60-day ones.
          </caption>
          <thead>
            <tr className="border-b border-line text-[11px] uppercase tracking-wide text-ink-muted">
              <th scope="col" className="sticky left-0 bg-white px-3 py-2.5 font-semibold sm:px-4">
                Window
              </th>
              {columns.map((column) => (
                <th
                  key={column.code}
                  scope="col"
                  className="px-3 py-2.5 text-right font-semibold"
                  title={column.label}
                >
                  {PERMIT_TYPE_HEADINGS[column.code] ?? column.code}
                </th>
              ))}
              <th
                scope="col"
                className="sticky right-0 border-l border-line bg-white px-3 py-2.5 text-right font-semibold sm:px-4"
              >
                Total
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.window} className="border-b border-line/60 last:border-0">
                <th
                  scope="row"
                  className="sticky left-0 whitespace-nowrap bg-white px-3 py-2 text-[14px] font-normal text-ink sm:px-4"
                >
                  {row.expired ? 'Already expired' : `Within ${row.days} days`}
                </th>
                {columns.map((column) => (
                  <td key={column.code} className="tnum px-3 py-2 text-right text-[14px] text-ink">
                    {num(row.counts[column.code] ?? 0)}
                  </td>
                ))}
                <td className="tnum sticky right-0 border-l border-line bg-white px-3 py-2 text-right text-[14px] font-bold text-ink sm:px-4">
                  {num(row.total)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="border-t border-line px-4 py-2 text-[11px] leading-snug text-ink-muted">
        The windows nest: a permit 20 days out is in all three. Expired permits are counted once,
        in their own row.
        {columns.length > 2 && (
          <span className="sm:hidden"> Slide the table sideways to see every permit type.</span>
        )}
      </p>
    </ProtoCard>
  )
}

/* ── Suspended Permits ─────────────────────────────────────────────────── */

/*
 * RenewalForecastPanel stood here: the coming January's renewals, estimated.
 * It went on 18 October 2026 (client's picker) — the register had too little
 * renewal history for it to estimate anything, so it only ever said so.
 */

/*
 * Permits suspended today (client's picker, 18 October 2026). A suspended
 * Mayor's Permit is a business that cannot trade until another office is
 * satisfied, and after 30 days BPLO may revoke it — so the count, the long
 * ones in the "needs attention" tone with a word and an icon (Never Color
 * Alone), and the oldest five with the office each waits on.
 *
 * The links open the permits register on the same filter the count uses
 * (`suspended_over_days`), for the readers whose register shows Mayor's
 * Permits: BPLO and the two who read every office. A clearance office's
 * register is its own permits, where these do not appear, so it gets the list
 * here and no link to an empty table.
 *
 * `registerLink` builds the address for a `?status=` value: the register's own
 * page, or — on the office Home — the Home's Permits section below.
 */
function SuspendedPanel({
  suspended,
  registerLink,
}: {
  suspended: SuspendedPermits
  registerLink: ((status: string) => string) | null
}) {
  if (suspended.total === 0) {
    return (
      <ProtoCard className="px-4 py-4 text-[13px] text-ink-muted">No permit is suspended.</ProtoCard>
    )
  }

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-4">
        <StatCard
          label="Suspended"
          metric="suspended_permits"
          value={num(suspended.total)}
          detail={suspended.undated > 0 ? `${num(suspended.undated)} with no date on record` : 'permits today'}
        />
        <ProtoCard className="px-4 py-3.5">
          <p className="text-[11px] uppercase tracking-wide text-ink-muted">
            Over {suspended.long_after_days} days
            <Info metric="suspended_permits.long" />
          </p>
          <p
            className="tnum mt-1 text-[26px] font-bold leading-none"
            style={{ color: suspended.long > 0 ? BREACH : undefined }}
          >
            {num(suspended.long)}
          </p>
          <p className="mt-1.5 flex items-center gap-1 text-[11px] text-ink-muted">
            {suspended.long > 0 ? (
              <span
                className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 font-semibold"
                style={{ color: BREACH, backgroundColor: BREACH_TINT }}
              >
                <WarningGlyph />
                BPLO may revoke
              </span>
            ) : (
              'none yet'
            )}
          </p>
        </ProtoCard>
      </div>

      <ProtoCard className="overflow-hidden">
        <table className="w-full text-left">
          <caption className="px-4 pb-1 pt-3 text-left text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
            Oldest
          </caption>
          <thead>
            <tr className="border-b border-line text-[11px] uppercase tracking-wide text-ink-muted">
              <th scope="col" className="px-4 py-2 font-semibold">Business</th>
              <th scope="col" className="px-3 py-2 font-semibold">Since</th>
              <th scope="col" className="px-4 py-2 font-semibold">Waiting on</th>
            </tr>
          </thead>
          <tbody>
            {suspended.oldest.map((row) => (
              <tr key={row.permit_id} className="border-b border-line/60 align-top last:border-0">
                <th scope="row" className="px-4 py-1.5 text-[14px] font-normal text-ink">
                  {row.business}
                  <span className="block text-[11px] text-ink-muted">{row.permit_number}</span>
                </th>
                <td className="px-3 py-1.5 text-[13px] text-ink">
                  {row.suspended_on === null ? '—' : dateLabel(row.suspended_on)}
                  {row.days !== null && (
                    <span
                      className={`block text-[11px] ${row.long ? 'font-semibold' : 'text-ink-muted'}`}
                      style={row.long ? { color: BREACH } : undefined}
                    >
                      {num(row.days)} {row.days === 1 ? 'day' : 'days'}
                    </span>
                  )}
                </td>
                <td className="px-4 py-1.5 text-[13px] text-ink-secondary">
                  {row.waits_on?.office ?? row.waits_on?.permit ?? '—'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {registerLink && (
          <p className="flex flex-wrap gap-x-4 gap-y-1 border-t border-line px-4 py-2 text-[12px]">
            <Link to={registerLink('suspended')} className="font-semibold text-royal hover:underline">
              All suspended
            </Link>
            {suspended.long > 0 && (
              <Link
                to={registerLink('suspended_30')}
                className="font-semibold text-royal hover:underline"
              >
                Over {suspended.long_after_days} days
              </Link>
            )}
          </p>
        )}
      </ProtoCard>
    </div>
  )
}

/* ── New and Closed Businesses ─────────────────────────────────────────── */

/*
 * From Business Growth Analysis's Closure Trend, with registrations drawn
 * beside the closures so the line has something to be read against.
 *
 * Two series, told apart by more than colour: registrations are a solid royal
 * line, closures a dashed purple one, and the legend under the chart names both
 * with their window totals. Purple, not red — a closure is not an error
 * (DESIGN.md, Red Means Stop).
 */
function monthTick(month: string): string {
  const [y, m] = month.split('-').map(Number)
  // "July 2026", not "Jul 26": the month in full (checklist, Apply for Permit 7).
  return new Date(y, m - 1, 1).toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })
}

function MovementPanel({ report }: { report: DashboardReport }) {
  const movement = report.business_movement

  if (movement.rows.length === 0) {
    return (
      <ProtoCard className="px-4 py-4 text-[13px] text-ink-muted">
        Nothing to draw yet. These figures arrive with the next refresh.
      </ProtoCard>
    )
  }

  const data = movement.rows.map((row) => ({ ...row, label: monthTick(row.month) }))

  return (
    <ProtoCard className="px-4 pb-3 pt-4">
      <ChartFrame
        title="Businesses registered and businesses closed, month by month"
        height={190}
        columns={['Month', 'Registered', 'Closed', 'Net']}
        rows={data.map((row) => ({
          header: row.label,
          cells: [num(row.registered), num(row.closed), num(row.net)],
        }))}
        legend={[
          {
            key: 'registered',
            label: 'Registered (solid line)',
            value: num(movement.registered),
            color: CHART_ROYAL,
          },
          {
            key: 'closed',
            label: 'Closed (dashed line)',
            value: num(movement.closed),
            color: CHART_PURPLE,
          },
        ]}
        /*
         * Real openings and closures only, since 18 October 2026: the line
         * counted every business record created and removed, so test records
         * read as 34 openings and 22 closures on a register of 24.
         */
        footer="New: first application submitted. Closed: Mayor's Permit retired, or blacklisted. Removed records count in neither."
      >
        <LineChart data={data} margin={{ top: 8, right: 14, bottom: 4, left: 0 }}>
          <CartesianGrid stroke={CHART_GRID} strokeDasharray="3 3" vertical={false} />
          <XAxis
            dataKey="label"
            tick={CHART_AXIS_TICK}
            tickLine={false}
            axisLine={{ stroke: CHART_GRID }}
            minTickGap={14}
          />
          <YAxis
            tick={CHART_AXIS_TICK}
            tickLine={false}
            axisLine={false}
            allowDecimals={false}
            width={34}
          />
          <Tooltip {...CHART_TOOLTIP} />
          <Line
            type="monotone"
            dataKey="registered"
            name="Registered"
            stroke={CHART_ROYAL}
            strokeWidth={2.5}
            dot={{ r: 3, fill: CHART_ROYAL }}
            isAnimationActive={false}
          />
          <Line
            type="monotone"
            dataKey="closed"
            name="Closed"
            stroke={CHART_PURPLE}
            strokeWidth={2.5}
            strokeDasharray="6 4"
            dot={{ r: 3, fill: CHART_PURPLE, strokeWidth: 0 }}
            isAnimationActive={false}
          />
        </LineChart>
      </ChartFrame>
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
            None of the {num(total)} registered businesses has this on file, and it is not guessed
            from anything else.
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
            overall — {num(combined.passed)} passed of {num(combined.completed)} completed, from{' '}
            {num(combined.scheduled)} scheduled. The rate divides by completed inspections, never by
            scheduled ones.
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

function BusinessMap({ report, permit }: { report: DashboardReport; permit: OfficePermit }) {
  const { points, plotted, mapped, total_businesses } = report.map
  const bounds = pointBounds(points)

  /*
   * What a red pin can and cannot claim. The payload's `lapsed` means only "no
   * valid permit today": a business whose permit expired and one whose first
   * filing is still being processed both arrive as `lapsed`, and nothing in the
   * payload tells them apart. A legend reading "lapsed", or one that implies
   * every red pin let a permit run out, would accuse the second kind of
   * something they have not done. So the legend says what is known and names
   * both possibilities. Scoped to one office it names that office's permit,
   * because a red pin on City Health's map is about the sanitary permit.
   */
  const validLabel = `${permit.label} valid today`
  const missingLabel = `No valid ${permit.noun} today`

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
          {validLabel}
        </span>
        <span className="flex items-center gap-1.5">
          <span
            className="h-3.5 w-3.5 rounded-full"
            style={{ backgroundColor: MAP_LAPSED }}
            aria-hidden="true"
          />
          {missingLabel} (lapsed, or not issued yet)
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
                {point.permit_state === 'active' ? validLabel : missingLabel}
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
            {num(plotted)} of {num(total_businesses)} businesses plotted from their recorded
            coordinates.
            {mapped > plotted && (
              <> {num(mapped - plotted)} more have coordinates but are past this map&rsquo;s cap.</>
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

export function AnalyticsPage({
  embedded = false,
  permitsLink,
  reportsLink,
}: {
  /**
   * Drawn as the Dashboard sub-tab of the office Home's Analytics tab. The
   * same dashboard, under a screen-reader heading instead of the page's, and
   * without the AnalyticsTabs strip: Home draws its own sub-tabs.
   *
   * Since 19 October 2026 it is the ONLY dashboard an office admin or BPLO
   * has (client: "To avoid redundancy, we should only have one existing among
   * them"); /analytics redirects them here (App.tsx). So it keeps everything
   * the full page had — office switcher, window filter, Generate Report — and
   * the "View full analytics" link it used to carry is gone, with nothing
   * left to point at. The /analytics route (the super admin, and anyone not
   * landing on that Home) renders it without, unchanged.
   */
  embedded?: boolean
  /**
   * Embedded only: where the Suspended Permits links go — the Home's own
   * Permits section, when the Home has one. Without it they are not drawn.
   */
  permitsLink?: (status: string) => string
  /**
   * Embedded only: where Generate Report goes — the Home's own Reports
   * sub-tab. Without it the button is not drawn. The full page goes to
   * /analytics/reports.
   */
  reportsLink?: string
} = {}) {
  /*
   * Undefined until the reader picks one: the first request asks for "my
   * default" and the server answers with the reader's own office (or every
   * office for the super admin). See OfficeScope for why the label is read off
   * the response and not off this state.
   */
  /*
   * In the URL, so a reload, a bookmark or the Generate Report link keeps the
   * office. Still only a request: the server decides (AnalyticsOffice).
   *
   * The trailing window is in the URL too, beside the office. It was component
   * state, so a reload or a shared link quietly went back to twelve months
   * while the office it was opened for stayed put — two halves of one view
   * kept two different ways. `months` is left out of the URL at its default,
   * so the plain address stays the plain address.
   */
  /*
   * Embedded, these were component state until 19 October 2026, because the
   * dashboard then shared the Home's URL with the Permits table, which reads
   * `?office=all` and `?status=` on arrival. It no longer does: the dashboard
   * is its own sub-tab (`?tab=analytics`), every Home tab link is written
   * clean, and the Permits table is never mounted beside it. So the URL holds
   * them in both places, and the old /analytics?office=…&months=… address,
   * redirected onto Home with its query, opens on the same view.
   */
  const [params, setParams] = useSearchParams()
  const read = (name: string) => params.get(name) ?? undefined
  const office = read('office')
  const months = windowFrom(read('months') ?? null)
  const setParam = (name: string, value: string | undefined) => {
    const next = new URLSearchParams(params)
    if (value === undefined) next.delete(name)
    else next.set(name, value)
    setParams(next, { replace: true })
  }
  const setOffice = (value: string) => setParam('office', value)
  const setMonths = (value: string) => setParam('months', value === '12' ? undefined : value)

  const {
    data: result,
    loading,
    error,
    reload,
  } = useAsync(() => analytics.dashboard(Number(months), office), [months, office])

  const data = result?.data
  const meta = result?.meta
  const scope = result?.scope

  const monthWindow = data
    ? new Date(`${data.month_start}T00:00:00`).toLocaleDateString('en-PH', {
        month: 'long',
        year: 'numeric',
      })
    : ''
  const trailing = data ? `Last ${data.window_months} months to ${dateLabel(data.today)}` : ''
  const asOf = data ? `As of ${dateLabel(data.today)}` : ''
  // Whose permit the figures count: the office on screen, as the SERVER scoped
  // it (see officePermit).
  const scoped = Boolean(scope?.office)
  const permit = officePermit(scope?.office)

  const windowFilter = (
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
  )

  /*
   * "Generate Report" opens Report Generation (checklist 2026-09-27, item 7)
   * for the office on screen, rather than downloading this dashboard as a PDF.
   * The five LGU reports are what an office files; the dashboard PDF is still
   * there, as one option on that screen. Embedded, that screen is the Home's
   * Reports sub-tab.
   */
  const reportsBase = embedded ? reportsLink : portalPath(activePortal(), '/analytics/reports')
  const generateReport = reportsBase && (
    <Link
      to={
        scope?.can_switch
          ? `${reportsBase}${reportsBase.includes('?') ? '&' : '?'}office=${scope.office ?? 'all'}`
          : reportsBase
      }
      className="rounded-lg bg-royal px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:bg-royal-hover"
    >
      Generate Report
    </Link>
  )

  /*
   * Embedded, the "Showing CHO figures" line is left out for a reader who
   * cannot switch: the Home's own heading is already the office's name.
   */
  const heading = embedded ? (
    <SectionTitle
      id="analytics"
      quiet
      right={
        <span className="flex flex-wrap items-center gap-3 pb-0.5">
          {scope?.can_switch && <OfficeScope scope={scope} onChange={setOffice} />}
          {windowFilter}
          {generateReport}
        </span>
      }
    >
      Analytics Dashboard
    </SectionTitle>
  ) : (
      <PageTitle
        right={
          <span className="flex flex-wrap items-center gap-3 pb-1">
            {scope && <OfficeScope scope={scope} onChange={setOffice} />}
            {windowFilter}
            {generateReport}
          </span>
        }
      >
        Analytics Dashboard
      </PageTitle>
  )

  return (
    <PanelHeadingLevel.Provider value={embedded ? 'h3' : 'h2'}>
    <section aria-labelledby={embedded ? 'analytics-title' : undefined}>
      {heading}

      {!embedded && <AnalyticsTabs />}

      {/*
        Refresh only for the two readers who see every office: one press
        recomputes every office's figures, and the API refuses anyone else
        (403). An office's own figures are recomputed every night.
      */}
      {meta && <ComputedAt meta={meta} onRefreshed={scope?.can_switch ? reload : undefined} />}

      {loading ? (
        <LoadingState />
      ) : error ? (
        <AnalyticsError
          error={error}
          onRetry={reload}
          onOwnOffice={office ? () => setParam('office', undefined) : undefined}
        />
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
              hint={`holding a ${permit.noun} valid today`}
              metric="kpis.active_businesses"
            />
            {/*
              FULL TERM, NOT YEAR TO DATE — the client's note was "do not put YTD
              only; it should be the full term". Since 18 October 2026 it reads
              `applications_all_time`: every filing that is not a draft, of every
              type. It read `applications_ytd`, which left out filings with no
              submission date — 18 of 26 on the live register, under a label
              that said all time. See DashboardAnalytics::kpiFacts().
            */}
            <Kpi
              value={num(data.kpis.applications_all_time)}
              label="Applications (all time)"
              hint="every filing on record"
              metric="kpis.applications_all_time"
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
              hint={scoped ? `${permit.noun} validity, today` : 'permit validity, today'}
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

            {/*
              Time in Office and Fees Collected took the row the RA 11032 tier
              panel and the department panel shared (18 October 2026). The
              one-line note is the client's own wording of what is measured.
            */}
            <section>
              <SectionHeading note={trailing} metric="stages">Time in Office</SectionHeading>
              <p className="-mt-1 mb-1.5 text-[12px] text-ink-secondary">
                From the day an office receives a permit to the day it issues or refuses it.
              </p>
              <TimeInOfficePanel report={data} />
            </section>

            <section>
              <SectionHeading
                note={`This month and since ${dateLabel(data.fees_collected.year_start)}`}
                metric="fees_collected"
              >
                Fees Collected
              </SectionHeading>
              <FeesPanel report={data} />
            </section>
          </div>

          <section className="mt-5">
            {/*
              The paper's §1 term is "Compliance Rate". The adviser's own note
              (docs/r-integration-revisions.md §1.5) proposed "Compliance
              Monitoring Rate through RA 11032", which was never applied, and
              the one card about RA 11032 is gone now (18 October 2026). Her
              substantive demand there was that the indicators be split apart
              with their own denominators, and that is what CompliancePanel does.
            */}
            <SectionHeading>Compliance Rate</SectionHeading>
            <CompliancePanel report={data} permit={permit} scoped={scoped} trailing={trailing} asOf={asOf} />
          </section>

          {/*
            Full width: the expiry table carries a column per permit type (six
            on this register) and was unreadable squeezed into half a row.
          */}
          <section className="mt-5 min-w-0">
            <SectionHeading note={asOf} metric="expiry">
              Permits Approaching Expiry
            </SectionHeading>
            <ExpiryPanel report={data} />
          </section>

          {/*
            Suspended Permits took the Renewal Estimate's place (18 October
            2026), beside New and Closed Businesses: both are about businesses
            that cannot, or no longer, trade.
          */}
          <div className="mt-5 grid gap-x-5 gap-y-5 *:min-w-0 lg:grid-cols-2">
            <section>
              <SectionHeading note={asOf}>Suspended Permits</SectionHeading>
              <SuspendedPanel
                suspended={data.suspended_permits}
                registerLink={
                  !(scope?.can_switch || scope?.office === 'BPLO')
                    ? null
                    : embedded
                      ? (permitsLink ?? null)
                      : (status) => `${portalPath(activePortal(), '/admin/permits')}?status=${status}`
                }
              />
            </section>

            <section>
              <SectionHeading note={trailing} metric="business_movement">
                New and Closed Businesses
              </SectionHeading>
              <MovementPanel report={data} />
            </section>
          </div>

          {/*
            Four sections in a two-column grid, two rows of two. Messages &
            Requests was the fifth until the client's picker took it off
            (19 October 2026). Without Inspections (an office that books no
            visits) the block is three, and the last one runs to the full width
            rather than sitting alone in half a row. "Permits Approaching
            Expiry" used to lead this block; it came back from Renewal Risk
            Prediction (checklist 2026-09-27, item 6) and sits in its own row
            above.
          */}
          <div className="mt-5 grid gap-x-5 gap-y-5 *:min-w-0 lg:grid-cols-2 lg:[&>:last-child:nth-child(odd)]:col-span-2">
            {/*
              Only for an office that inspects. The payload has a row for every
              inspecting office in scope, even at zero visits, so no rows means
              the office on screen books no visits at all — BPLO, which issues
              the Mayor's Permit on the strength of the clearances. It used to
              get an empty chart over "No pass rate overall — 0 passed of 0".
              Without it the block is three sections; see above.
            */}
            {data.inspections.rows.length > 0 && (
              <section>
                {/* Same as Decision Outcomes above: the pass-rate info button
                    already sits beside the pass rate inside the panel. */}
                <SectionHeading note={trailing}>Inspections</SectionHeading>
                <InspectionsPanel report={data} />
              </section>
            )}

            {/*
              The count stays in the title. The shares under these two charts do
              not sum to 100, and a title claiming to rank every barangay while
              showing five of twenty-one is exactly the misread the adviser
              called out — see the override note on `top_lines_of_business` in
              AnalyticsDefinitions. "Five" spelled as a word is the paper's
              spelling; the arithmetic it protects is unchanged.
            */}
            <section>
              <SectionHeading note={asOf} metric="top_barangays">
                Top Five Barangays by Active Businesses
              </SectionHeading>
              <RankedBarsPanel
                rows={data.top_barangays.rows}
                name={(row) => (row as BarangayShareRow).barangay}
                title="The five barangays with the most active businesses"
                categoryHeading="Barangay"
                valueHeading="Active businesses"
                labelBy="name"
                footnote={`Five of ${num(data.top_barangays.groups)} barangays. Shares are of the ${num(data.top_barangays.total)} active businesses with a barangay on record.`}
                empty="No active business has a barangay address on record, so there is nothing to rank."
              />
            </section>

            <section>
              <SectionHeading note={asOf} metric="top_lines_of_business">
                Top Five Business Categories
              </SectionHeading>
              <RankedBarsPanel
                rows={data.top_lines_of_business.rows}
                name={(row) => (row as LineOfBusinessRow).industry}
                title="The five most common business categories among active businesses"
                categoryHeading="Rank"
                valueHeading="Active businesses"
                labelBy="rank"
                footnote={`Five of ${num(data.top_lines_of_business.groups)} categories, grouped by PSIC code. Shares are of the ${num(data.top_lines_of_business.total)} active businesses with a category on record.`}
                empty="No active business has a line of business on record, so there is nothing to rank."
              />
            </section>

            <section>
              <SectionHeading note={asOf} metric="organization_forms">Form of Organization</SectionHeading>
              <OrganizationPanel report={data} />
            </section>

          </div>

          <section className="mt-5">
            <SectionHeading note={asOf} metric="map">GIS Mapping</SectionHeading>
            <BusinessMap report={data} permit={permit} />
          </section>
        </MetricDefinitions>
      ) : null}
    </section>
    </PanelHeadingLevel.Provider>
  )
}
