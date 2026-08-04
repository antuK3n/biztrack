import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { CircleMarker, MapContainer, Popup, TileLayer, useMap } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'
import { ErrorState, Skeleton, SkeletonCards } from '../../components/ui/primitives'
import { Info, MetricDefinitions } from '../../components/ui/MetricInfo'
import { FilterMenu, PageTitle, ProtoCard } from '../../components/ui/Proto'
import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type {
  BarangayShareRow,
  ComplianceIndicator,
  DashboardReport,
  ExpiryRow,
  InspectionRow,
  LineOfBusinessRow,
  MapPoint,
  ProcessingTierRow,
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
 *    limits are law, not service targets. See TierBar for how that is drawn and
 *    why it is drawn that way.
 */

const ROYAL = '#3242ca'
const MUTED_BAR = '#9fb6dd'
/*
 * The breach tone. Deliberately NOT #bd0000: DESIGN.md reserves that for errors
 * and destructive actions and forbids it as a chart data colour. A statutory
 * breach is a finding about the office, not a system error — so it reads as a
 * heavy amber that clears 4.5:1 on white, and every breach also carries a word
 * and an icon, because colour alone would fail both WCAG 2.1 AA and the Never
 * Color Alone rule.
 */
const BREACH = '#8a4b00'
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

/** Where the statutory target sits on every tier track, as a percentage. */
const TARGET_MARK = 60

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
function SectionHeading({ children, note, metric }: { children: ReactNode; note?: string; metric?: string }) {
  return (
    <div className="mb-2.5">
      {/*
       * The info button is a sibling of the h2, not a child of it. Nesting it
       * inside folds "How X is measured" into the heading's accessible name, so
       * anyone navigating by heading hears the button on every section.
       */}
      <div className="flex items-center">
        <h2 className="text-xl text-ink">{children}</h2>
        {metric && <Info metric={metric} />}
      </div>
      {note && <p className="mt-0.5 text-[11px] text-ink-muted">{note}</p>}
    </div>
  )
}

function Kpi({ value, label, hint, metric }: { value: string; label: string; hint?: string; metric?: string }) {
  return (
    <ProtoCard className="px-4 py-7 text-center">
      <p className="tnum text-[30px] font-bold leading-none text-royal">{value}</p>
      <p className="mt-2.5 text-[13px] text-ink-muted">
        {label}
        {metric && <Info metric={metric} />}
      </p>
      {hint && <p className="mt-1 text-[11px] text-ink-muted">{hint}</p>}
    </ProtoCard>
  )
}

/* ── Application Volume ─────────────────────────────────────────────────── */

function VolumePanel({ report }: { report: DashboardReport }) {
  return (
    <ProtoCard className="overflow-hidden">
      <table className="w-full text-left">
        <caption className="sr-only">Applications filed this month by transaction type</caption>
        <tbody>
          {report.volume.rows.map((row) => (
            <tr key={row.type} className="border-b border-line/60">
              <th scope="row" className="px-5 py-2.5 text-[15px] font-normal text-ink">
                {row.label}
              </th>
              <td className="tnum px-5 py-2.5 text-right text-[15px] text-ink">{num(row.count)}</td>
            </tr>
          ))}
          <tr>
            <th scope="row" className="px-5 py-3 text-[15px] font-bold text-royal">
              Total
            </th>
            <td className="tnum px-5 py-3 text-right text-[15px] font-bold text-royal">
              {num(report.volume.total)}
            </td>
          </tr>
        </tbody>
      </table>
    </ProtoCard>
  )
}

/* ── Decision Outcomes ─────────────────────────────────────────────────── */

function DecisionsPanel({ report }: { report: DashboardReport }) {
  const { rows, approval_rate, approved, decisioned } = report.decisions
  // Cancelled is neither a decision nor pending, so it only earns a row once it
  // has happened — otherwise it is a permanent zero explaining nothing.
  const visible = rows.filter((row) => row.outcome !== 'cancelled' || row.count > 0)

  return (
    <ProtoCard className="overflow-hidden">
      <table className="w-full text-left">
        <caption className="sr-only">Decision outcomes for applications filed this month</caption>
        <tbody>
          {visible.map((row) => (
            <tr key={row.outcome} className="border-b border-line/60">
              <th scope="row" className="px-5 py-2.5 text-[15px] font-normal text-ink">
                {row.label}
                {!row.decisioned && (
                  <span className="ml-2 text-[11px] text-ink-muted">not counted in the rate</span>
                )}
              </th>
              <td className="tnum px-5 py-2.5 text-right text-[15px] text-ink">{num(row.count)}</td>
            </tr>
          ))}
          <tr>
            {/*
              * aria-label pins the header's accessible name to the label alone.
              * A header cell takes its name from its contents, so the nested
              * info button would otherwise fold in — every figure in this
              * column would be announced as "Approval rate How Approval rate is
              * measured". The button can't move out of the cell the way it did
              * for the section headings, so the name is stated instead.
              */}
            <th scope="row" aria-label="Approval rate" className="px-5 py-3 text-[15px] font-bold text-royal">
              Approval rate
              <Info metric="decisions.approval_rate" />
            </th>
            <td className="tnum px-5 py-3 text-right text-[15px] font-bold text-royal">
              {pct(approval_rate)}
            </td>
          </tr>
        </tbody>
      </table>
      <p className="border-t border-line px-5 py-3 text-xs text-ink-muted">
        {approval_rate === null
          ? 'Nothing filed this month has been decided yet, so there is no approval rate to report.'
          : `${num(approved)} approved of ${num(decisioned)} decided. Filings still pending are not counted in this rate.`}
      </p>
    </ProtoCard>
  )
}

/* ── Average Processing Time by RA 11032 Tier ──────────────────────────── */

/**
 * One tier against its statutory limit.
 *
 * THE DESIGN PROBLEM. The three tiers have limits of 3, 7 and 20 working days.
 * Draw them on a shared axis and the 20-day tier dwarfs the 3-day one, so a 3-day
 * tier running 60% over its legal limit looks like a stub while a 20-day tier
 * comfortably inside its own looks alarming. That is a chart that hides breaches.
 *
 * THE FIX. Each bar is scaled by its OWN target, so the target line sits at the
 * same place on every track. A bar past that line is over the legal limit —
 * legible at a glance and comparable across tiers whose limits differ sevenfold.
 *
 * A breach then gets four independent signals, because one is skippable and
 * colour alone is not accessible: the bar visibly crosses a marked line, the bar
 * and the value change tone, the row carries a warning icon, and the overage is
 * written out in words. Nothing here softens how a breach reads.
 */
function TierBar({ row }: { row: ProcessingTierRow }) {
  const target = row.statutory_working_days
  const mean = row.mean_working_days

  if (mean === null) {
    return (
      <div className="py-3">
        <div className="flex items-baseline justify-between gap-3">
          <p className="text-[13px] font-bold text-ink">
            {row.label}
            <span className="ml-2 font-normal text-ink-muted">{target}-day statutory limit</span>
          </p>
          <p className="text-[13px] text-ink-muted">No filings on record</p>
        </div>
        <div className="mt-2 h-2.5 rounded-full border border-dashed border-line bg-canvas" />
        <p className="mt-1.5 text-[11px] text-ink-muted">
          No {row.label.toLowerCase()} application has been decided in this window, so there is no
          average to compare against the {target}-working-day limit.
        </p>
      </div>
    )
  }

  const width = Math.min(100, Math.max(2, (mean / target) * TARGET_MARK))
  const breach = row.breaching

  return (
    <div className="py-3">
      <div className="flex items-baseline justify-between gap-3">
        <p className="text-[13px] font-bold text-ink">
          {row.label}
          <span className="ml-2 font-normal text-ink-muted">{target}-day statutory limit</span>
        </p>
        <p className="tnum text-[15px] font-bold" style={{ color: breach ? BREACH : ROYAL }}>
          {mean.toFixed(1)}d
        </p>
      </div>

      <div className="relative mt-2 h-2.5 overflow-hidden rounded-full bg-canvas">
        <div
          className="h-full rounded-full"
          style={{ width: `${width}%`, backgroundColor: breach ? BREACH : ROYAL }}
        />
        {/* The statutory limit, drawn on the track it governs. */}
        <div
          className="absolute inset-y-0 w-[2px] bg-ink"
          style={{ left: `${TARGET_MARK}%` }}
          aria-hidden="true"
        />
      </div>

      <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
        {breach ? (
          <span
            className="inline-flex items-center gap-1.5 rounded px-1.5 py-0.5 text-[11px] font-bold"
            style={{ color: BREACH, backgroundColor: BREACH_TINT }}
          >
            <WarningGlyph />
            Over the statutory limit by {row.overage_days?.toFixed(1)} working days
          </span>
        ) : (
          <span className="text-[11px] font-semibold text-ink-secondary">
            Within the statutory limit by {Math.abs(row.overage_days ?? 0).toFixed(1)} working days
          </span>
        )}
        {/*
          The per-filing statutory pass rate, on the SAME yardstick as the breach
          flag above it. These two figures answer different questions — "is the
          average inside the limit" and "what share of filings were" — and both
          belong here, but they have to be separately labelled or a high pass rate
          beside a breach flag reads as a contradiction.
        */}
        <span className="text-[11px] text-ink-muted">
          {num(row.within_statutory)} of {num(row.observations)} filings inside the statutory limit (
          {pct(row.within_statutory_rate)}) · {row.mean_calendar_days?.toFixed(1)}d counting every
          day of the week
        </span>
      </div>
    </div>
  )
}

function TierPanel({ report }: { report: DashboardReport }) {
  const breaching = report.processing_tiers.filter((t) => t.breaching)
  const measured = report.processing_tiers.filter((t) => t.mean_working_days !== null)

  /*
   * Tiers whose own recorded deadline is more generous than the law allows. This
   * is a finding, not a footnote: the workflow stamps every filing with a flat
   * ten-working-day deadline, so a simple transaction can be comfortably "on
   * time" against the system and still be more than three times over the statute.
   * Anyone reading a high on-time figure elsewhere in the product deserves to
   * know that.
   */
  const lenient = measured.filter(
    (t) =>
      t.recorded_deadline_working_days !== null &&
      t.recorded_deadline_working_days > t.statutory_working_days,
  )

  return (
    <ProtoCard className="px-5 py-3">
      <div className="divide-y divide-line/60">
        {report.processing_tiers.map((row) => (
          <TierBar key={row.tier} row={row} />
        ))}
      </div>
      <p className="mt-1 border-t border-line pt-3 text-xs text-ink-muted">
        Republic Act 11032 sets these limits in{' '}
        <strong className="font-semibold">working days</strong>, so the averages and the pass rates
        are measured in working days too; weekends are left out, and public holidays are not allowed
        for on either side of the comparison. Tier comes from each application&rsquo;s recorded
        complexity.{' '}
        {breaching.length > 0 ? (
          <span className="font-semibold text-ink">
            {breaching.length} of {measured.length} measured{' '}
            {measured.length === 1 ? 'tier is' : 'tiers are'} over the legal limit:{' '}
            {breaching.map((t) => t.label.toLowerCase()).join(', ')}.
          </span>
        ) : measured.length > 0 ? (
          <span className="text-ink">Every measured tier is inside its legal limit.</span>
        ) : null}
      </p>
      {lenient.length > 0 && (
        <p className="mb-2 rounded-lg bg-canvas px-3 py-2.5 text-xs leading-snug text-ink-secondary">
          <strong className="font-semibold text-ink">
            The deadline this system records is not the statutory one.
          </strong>{' '}
          Every filing is stamped with a{' '}
          {lenient[0].recorded_deadline_working_days}-working-day internal deadline whatever its
          tier, which is more time than RA 11032 allows for{' '}
          {lenient.map((t) => `${t.label.toLowerCase()} (${t.statutory_working_days} days)`).join(' and ')}
          . Figures elsewhere that count filings as on time against that internal deadline are
          therefore more forgiving than the law.
        </p>
      )}
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
  const { rows: allRows, bottleneck, mean_days, reviews } = report.stages
  const rows = allRows.filter((r: StageRow) => r.reviews >= MIN_REVIEWS_FOR_STAGE)
  const peak = Math.max(1, ...rows.map((r: StageRow) => r.mean_days))

  if (rows.length === 0) {
    return (
      <ProtoCard className="px-5 py-6">
        <p className="text-sm text-ink-secondary">
          No review assignment was completed in this window, so there is no time-in-stage to report.
        </p>
      </ProtoCard>
    )
  }

  return (
    <ProtoCard className="px-5 py-5">
      <div className="space-y-3.5">
        {rows.map((row, i) => (
          <div key={row.code} className="flex items-center gap-4">
            <div className="w-36 shrink-0">
              <p className="truncate text-[13px] font-bold text-ink" title={row.name}>
                {DEPARTMENT_HEADINGS[row.code] ?? row.code}
              </p>
              <p className="text-[11px] text-ink-muted">{num(row.reviews)} reviews</p>
            </div>
            <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-canvas">
              <div
                className="h-full rounded-full"
                style={{
                  width: `${Math.max(3, (row.mean_days / peak) * 100)}%`,
                  backgroundColor: i === 0 ? ROYAL : MUTED_BAR,
                }}
              />
            </div>
            <p className="tnum w-12 shrink-0 text-right text-[13px] font-semibold text-ink">
              {row.mean_days.toFixed(1)}d
            </p>
          </div>
        ))}
      </div>

      {/*
        Assembled from the computed values, never a fixed sentence: a hardcoded
        "Fire Protection is the bottleneck" would keep reading as true long after
        Fire Protection got faster.
      */}
      {bottleneck && (
        <p className="mt-4 border-t border-line pt-3 text-xs text-ink-secondary">
          <strong className="font-semibold text-ink">{bottleneck.name}</strong> is the slowest stage at{' '}
          {bottleneck.mean_days.toFixed(1)} days per review
          {bottleneck.above_average_days !== null && bottleneck.above_average_days > 0 && (
            <>
              {' '}
              — {bottleneck.above_average_days.toFixed(1)} days slower than the{' '}
              {mean_days?.toFixed(1)}-day average across all offices
            </>
          )}
          , and handles {bottleneck.share_of_reviews.toFixed(1)}% of the {num(reviews)} reviews
          completed in this window.
        </p>
      )}

    </ProtoCard>
  )
}

/* ── Compliance Monitoring ─────────────────────────────────────────────── */

function ComplianceCard({ indicator }: { indicator: ComplianceIndicator }) {
  const unavailable = indicator.rate === null

  return (
    <div className="flex-1 px-5 py-4">
      {/*
       * Keyed off the row's own identifier rather than a literal, so the three
       * indicators cannot be wired to each other's definitions — they measure
       * three different populations, which is the whole reason they are three
       * cards and not one.
       */}
      <p className="text-[11px] uppercase tracking-wide text-ink-muted">
        {indicator.label}
        <Info metric={`compliance.${indicator.indicator}`} />
      </p>
      {unavailable ? (
        <p className="mt-1.5 text-[15px] font-semibold text-ink-secondary">Cannot be computed</p>
      ) : (
        <p className="tnum mt-1 text-[28px] font-bold leading-none text-royal">
          {indicator.rate?.toFixed(0)}
          <span className="text-[15px] font-semibold text-ink-muted">%</span>
        </p>
      )}
      <p className="mt-1.5 text-[11px] leading-snug text-ink-muted">
        {unavailable
          ? (indicator.unavailable_reason ??
            `Nothing to count this against: no ${indicator.denominator_label} in this window.`)
          : `${num(indicator.numerator)} of ${num(indicator.denominator)} ${indicator.denominator_label} ${indicator.numerator_label}.`}
      </p>
    </div>
  )
}

function CompliancePanel({ report }: { report: DashboardReport }) {
  return (
    <ProtoCard className="flex flex-col divide-y divide-line/60 sm:flex-row sm:divide-x sm:divide-y-0">
      {report.compliance.map((indicator) => (
        <ComplianceCard key={indicator.indicator} indicator={indicator} />
      ))}
    </ProtoCard>
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
      <p className="border-t border-line px-5 py-3 text-xs text-ink-muted">
        The three forward windows overlap on purpose — a permit expiring in 20 days is counted in
        all three. Expired counts permits already past their validity date and is separate from the
        other three.
      </p>
    </ProtoCard>
  )
}

/* ── Ranked panels ─────────────────────────────────────────────────────── */

function RankedTable({
  rows,
  nameHeading,
  name,
  footnote,
  empty,
}: {
  rows: (BarangayShareRow | LineOfBusinessRow)[]
  nameHeading: string
  name: (row: BarangayShareRow | LineOfBusinessRow) => string
  footnote: string
  empty: string
}) {
  if (rows.length === 0) {
    return (
      <ProtoCard className="px-5 py-6">
        <p className="text-sm text-ink-secondary">{empty}</p>
      </ProtoCard>
    )
  }

  return (
    <ProtoCard className="overflow-hidden">
      <table className="w-full text-left">
        <thead>
          <tr className="border-b border-line text-[11px] uppercase tracking-wide text-ink-muted">
            <th scope="col" className="px-5 py-2.5 font-semibold">
              {nameHeading}
            </th>
            <th scope="col" className="px-5 py-2.5 text-right font-semibold">
              Count
            </th>
            <th scope="col" className="px-5 py-2.5 text-right font-semibold">
              Share
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.rank} className="border-b border-line/60 last:border-0">
              <th scope="row" className="px-5 py-2.5 text-[14px] font-semibold text-ink">
                <span className="tnum mr-2 text-ink-muted">{row.rank}.</span>
                {name(row)}
              </th>
              <td className="tnum px-5 py-2.5 text-right text-[14px] text-ink">{num(row.count)}</td>
              <td className="tnum px-5 py-2.5 text-right text-[14px] text-ink-secondary">
                {pct(row.share)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="border-t border-line px-5 py-3 text-xs text-ink-muted">{footnote}</p>
    </ProtoCard>
  )
}

/* ── Form of Organization ──────────────────────────────────────────────── */

function OrganizationPanel({ report }: { report: DashboardReport }) {
  const { rows, recorded, unrecorded, total } = report.organization_forms
  const peak = Math.max(1, ...rows.map((r) => r.count))

  return (
    <ProtoCard className="px-5 py-5">
      {recorded === 0 ? (
        /*
         * The honest empty state. `businesses.form_of_organization` exists and is
         * unpopulated, so four zero bars would read as "no corporations in
         * Malabon" rather than "nobody has filled this in". Deriving the form from
         * registration_type was considered and rejected: DTI vs SEC separates sole
         * proprietors from the rest but cannot tell a corporation from a
         * partnership, so half the panel would be a guess wearing a real column's
         * name.
         */
        <>
          <p className="text-sm font-semibold text-ink">Not recorded for any business yet</p>
          <p className="mt-1.5 text-[13px] leading-snug text-ink-secondary">
            None of the {num(total)} registered businesses has a form of organization on file, so this
            breakdown has nothing to count. The four categories appear here as soon as the field is
            captured — they are not being inferred from anything else in the register.
          </p>
        </>
      ) : (
        <div className="space-y-3.5">
          {rows.map((row, i) => (
            <div key={row.form} className="flex items-center gap-4">
              <div className="w-32 shrink-0">
                <p className="truncate text-[13px] font-bold text-ink">{row.label}</p>
                <p className="text-[11px] text-ink-muted">{pct(row.share)}</p>
              </div>
              <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-canvas">
                <div
                  className="h-full rounded-full"
                  style={{
                    width: `${Math.max(3, (row.count / peak) * 100)}%`,
                    backgroundColor: i === 0 ? ROYAL : MUTED_BAR,
                  }}
                />
              </div>
              <p className="tnum w-14 shrink-0 text-right text-[13px] font-semibold text-ink">
                {num(row.count)}
              </p>
            </div>
          ))}
          {unrecorded > 0 && (
            <p className="border-t border-line pt-3 text-xs text-ink-muted">
              {num(unrecorded)} of {num(total)} businesses have no form of organization on file and are
              excluded from the shares above.
            </p>
          )}
        </div>
      )}
    </ProtoCard>
  )
}

/* ── Inspections ───────────────────────────────────────────────────────── */

function InspectionsPanel({ report }: { report: DashboardReport }) {
  const all = [...report.inspections.rows, report.inspections.combined]

  return (
    <ProtoCard className="overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-left">
          <thead>
            <tr className="border-b border-line text-[11px] uppercase tracking-wide text-ink-muted">
              <th scope="col" className="px-5 py-2.5 font-semibold">
                Type
              </th>
              {['Sched.', 'Done', 'Passed', 'Failed', 'Cond.'].map((heading) => (
                <th key={heading} scope="col" className="px-3 py-2.5 text-right font-semibold">
                  {heading}
                </th>
              ))}
              {/* aria-label: see the Approval rate header — keeps the info
                * button out of the column's accessible name. */}
              <th scope="col" aria-label="Pass rate" className="px-5 py-2.5 text-right font-semibold">
                Pass rate
                <Info metric="inspections.pass_rate" />
              </th>
            </tr>
          </thead>
          <tbody>
            {all.map((row: InspectionRow) => {
              const combined = row.type === 'combined'
              return (
                <tr
                  key={row.type}
                  className={`border-b border-line/60 last:border-0 ${combined ? 'bg-canvas' : ''}`}
                >
                  <th
                    scope="row"
                    className={`px-5 py-2.5 text-[14px] text-ink ${combined ? 'font-bold' : 'font-semibold'}`}
                  >
                    {row.label}
                  </th>
                  {[row.scheduled, row.completed, row.passed, row.failed, row.conditional].map(
                    (value, i) => (
                      <td key={i} className="tnum px-3 py-2.5 text-right text-[14px] text-ink">
                        {num(value)}
                      </td>
                    ),
                  )}
                  <td
                    className={`tnum px-5 py-2.5 text-right text-[14px] font-bold ${combined ? 'text-ink' : 'text-royal'}`}
                  >
                    {row.pass_rate === null ? (
                      <span className="font-normal text-ink-muted">none done</span>
                    ) : (
                      pct(row.pass_rate)
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <p className="border-t border-line px-5 py-3 text-xs text-ink-muted">
        Pass rate divides the inspections passed by the ones actually{' '}
        <strong className="font-semibold">carried out</strong>, not by the ones scheduled. Type comes
        from the inspecting office, because the inspection-type field is empty on every record. A
        type with nothing carried out shows no rate rather than 0%.
      </p>
    </ProtoCard>
  )
}

/* ── Officer Activity ──────────────────────────────────────────────────── */

function OfficerPanel({ report }: { report: DashboardReport }) {
  const a = report.officer_activity

  return (
    <ProtoCard className="px-5 py-5">
      <div className="grid gap-5 sm:grid-cols-3">
        <div>
          <p className="text-[11px] uppercase tracking-wide text-ink-muted">Response<Info metric="officer_activity.mean_response_hours" /></p>
          {a.mean_response_hours === null ? (
            <p className="mt-1.5 text-[15px] font-semibold text-ink-secondary">No replies yet</p>
          ) : (
            <p className="tnum mt-1 text-[26px] font-bold leading-none text-royal">
              {a.mean_response_hours.toFixed(1)}
              <span className="text-[14px] font-semibold text-ink-muted">h</span>
            </p>
          )}
          <p className="mt-1.5 text-[11px] leading-snug text-ink-muted">
            {a.mean_response_hours === null
              ? 'No applicant message has been answered in this window.'
              : `Averaged over ${num(a.responses)} ${a.responses === 1 ? 'reply' : 'replies'}; the middle reply took ${a.median_response_hours?.toFixed(1)}h, with half faster and half slower.`}
            {a.threads_awaiting_reply > 0 &&
              ` ${num(a.threads_awaiting_reply)} ${a.threads_awaiting_reply === 1 ? 'thread is' : 'threads are'} still waiting.`}
          </p>
        </div>

        <div>
          <p className="text-[11px] uppercase tracking-wide text-ink-muted">Requests<Info metric="officer_activity.requests_fulfilled_rate" /></p>
          {a.requests_total === 0 ? (
            <p className="mt-1.5 text-[15px] font-semibold text-ink-secondary">None raised</p>
          ) : (
            <p className="tnum mt-1 text-[26px] font-bold leading-none text-royal">
              {num(a.requests_fulfilled)}
            </p>
          )}
          <p className="mt-1.5 text-[11px] leading-snug text-ink-muted">
            {a.requests_total === 0
              ? 'No officer request was raised in this window.'
              : `${num(a.requests_fulfilled)} fulfilled of ${num(a.requests_total)} raised (${pct(a.requests_fulfilled_rate)}).`}
          </p>
        </div>

        <div>
          <p className="text-[11px] uppercase tracking-wide text-ink-muted">Meetings<Info metric="officer_activity.meetings_attended_rate" /></p>
          {a.meetings_scheduled === 0 ? (
            <p className="mt-1.5 text-[15px] font-semibold text-ink-secondary">None scheduled</p>
          ) : (
            <p className="tnum mt-1 text-[26px] font-bold leading-none text-royal">
              {num(a.meetings_attended)}
            </p>
          )}
          <p className="mt-1.5 text-[11px] leading-snug text-ink-muted">
            {a.meetings_scheduled === 0
              ? /*
                 * A true zero, and it must not read as 0% participation: the
                 * column exists and nothing has ever been written to it, so no
                 * officer has skipped anything.
                 */
                'No meeting has been scheduled in this window, so there is no participation to report.'
              : `${num(a.meetings_attended)} of ${num(a.meetings_scheduled)} scheduled meetings had a recorded response (${pct(a.meetings_attended_rate)}).`}
          </p>
        </div>
      </div>
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
      <p className="mb-3 flex flex-wrap items-center gap-4 text-xs text-ink-muted">
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
          style={{ height: 520, width: '100%' }}
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
      <p className="mt-3 text-xs text-ink-muted">
        {plotted === 0 ? (
          'No business has coordinates on record yet, so there is nothing to plot.'
        ) : (
          <>
            {num(plotted)} of {num(total_businesses)} registered businesses plotted from the
            coordinates on their business-location address.
            {mapped > plotted && (
              <> {num(mapped - plotted)} more have coordinates but fall beyond this layer&rsquo;s cap.</>
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
    <div className="space-y-6">
      <SkeletonCards count={4} />
      <div className="grid gap-6 lg:grid-cols-2">
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
          <div className="grid grid-cols-2 gap-5 lg:grid-cols-4">
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

          <div className="mt-7 grid gap-x-6 gap-y-7 *:min-w-0 lg:grid-cols-2">
            <section>
              <SectionHeading note={monthWindow} metric="volume">Application Volume</SectionHeading>
              <VolumePanel report={data} />
            </section>

            <section>
              <SectionHeading note={trailing} metric="processing_tiers">
                Average Processing Time by RA 11032 Tier
              </SectionHeading>
              <TierPanel report={data} />
            </section>

            <section>
              <SectionHeading note={monthWindow}>Decision Outcomes</SectionHeading>
              <DecisionsPanel report={data} />
            </section>

            <section>
              <SectionHeading note={trailing} metric="stages">Average Time-in-Stage by Department</SectionHeading>
              <StagePanel report={data} />
            </section>
          </div>

          <section className="mt-7">
            <SectionHeading note={trailing}>Compliance Monitoring</SectionHeading>
            <CompliancePanel report={data} />
          </section>

          <div className="mt-7 grid gap-x-6 gap-y-7 *:min-w-0 lg:grid-cols-2">
            <section>
              <SectionHeading note={asOf} metric="expiry">Permits Approaching Expiry</SectionHeading>
              <ExpiryPanel report={data} />
            </section>

            <section>
              <SectionHeading note={trailing}>Inspections</SectionHeading>
              <InspectionsPanel report={data} />
            </section>

            <section>
              <SectionHeading note={asOf} metric="top_barangays">Top Barangays</SectionHeading>
              <RankedTable
                rows={data.top_barangays.rows}
                nameHeading="Barangay"
                name={(row) => (row as BarangayShareRow).barangay}
                footnote={`Active businesses per barangay, as a share of the ${num(data.top_barangays.total)} with a barangay on record across ${num(data.top_barangays.groups)} barangays.`}
                empty="No active business has a barangay address on record, so there is nothing to rank."
              />
            </section>

            <section>
              <SectionHeading note={asOf} metric="top_lines_of_business">Top Lines of Business</SectionHeading>
              <RankedTable
                rows={data.top_lines_of_business.rows}
                nameHeading="Line of business"
                name={(row) => (row as LineOfBusinessRow).industry}
                footnote={`Grouped by PSIC code — the national numbering for industries — across ${num(data.top_lines_of_business.groups)} lines on record. Shares are out of the ${num(data.top_lines_of_business.total)} active businesses that have a line of business recorded.`}
                empty="No active business has a line of business on record, so there is nothing to rank."
              />
            </section>

            <section>
              <SectionHeading note={asOf} metric="organization_forms">Form of Organization</SectionHeading>
              <OrganizationPanel report={data} />
            </section>

            <section>
              <SectionHeading note={trailing}>Officer Activity</SectionHeading>
              <OfficerPanel report={data} />
            </section>
          </div>

          <section className="mt-7">
            <SectionHeading note={asOf} metric="map">GIS Mapping</SectionHeading>
            <BusinessMap report={data} />
          </section>
        </MetricDefinitions>
      ) : null}
    </div>
  )
}
