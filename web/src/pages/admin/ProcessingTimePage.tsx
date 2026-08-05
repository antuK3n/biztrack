import { useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/primitives'
import { Info, MetricDefinitions } from '../../components/ui/MetricInfo'
import { FilterMenu, PageTitle, ProtoCard } from '../../components/ui/Proto'
import {
  SpcControlChart,
  spcSignedDays,
  spcWeekDate,
} from '../../components/charts/SpcControlChart'
import { toApiError } from '../../lib/api'
import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type {
  MetricDefinition,
  ProcessingTimeDepartment,
  ProcessingTimeReport,
} from '../../lib/types'
import { AnalyticsTabs } from './AnalyticsTabs'
import { ComputedAt } from './ComputedAt'

/*
 * Permit Processing Time Monitoring — "R INTEGRATION DRAFTS" §6, the super
 * admin's only analytics screen.
 *
 * It watches every department's weekly average processing time against the
 * range that department normally produces, "so that ordinary ups and downs are
 * ignored, and only movements beyond the normal range are treated as real
 * problems". BPLO is one of the departments measured here, which is why the
 * screen sits with the office doing the oversight rather than the office being
 * overseen (see the note on the admin role in RbacSeeder.php).
 *
 * The spec names four things this screen must show, and they are the four
 * sections below in order:
 *
 *   Department Processing Time Chart   the series with its normal range drawn round it
 *   Process Status Indicator           "Within Normal Range" / "Outside Normal Range"
 *   Noted Delays                       the specific weeks that went beyond, with dates
 *   Gradual Slowdown Warnings          the small slowdowns that build up week on week
 *
 * ── Three client notes drove this revision ─────────────────────────────────
 *
 *   "Remove unnecessary, long explanations in the analytics, because its way
 *   too long. but the information hover thing is pretty good, maybe distill it
 *   a bit more."
 *
 * So the running prose that used to sit under each panel is gone — what
 * survives on the face is a single line per panel that a reader needs in order
 * to read the numbers at all (which direction is good, what the clock
 * measures). The info hovers stay, shortened: see `distil` below.
 *
 *   "Remove large spaces; fix the UI design of the analytics."
 *
 * The status indicator was a 34px word alone in a tall card, and every panel
 * had a paragraph under it. Status is now a four-across strip that doubles as
 * the office picker, so one row carries both the per-department verdict the
 * spec asks for and the control that used to be buried in the Filter menu.
 *
 *   Plain language, not control-chart vocabulary.
 *
 * UCL, LCL, sigma and "out of control" appear nowhere on screen. The client
 * asked what "inside" and "flag" even meant (docs/r-integration-revisions.md
 * §6.2); the spec's own wording — "Within Normal Range", "Outside Normal
 * Range", "normal range", "Noted Delays" — is what is printed instead.
 *
 * Nothing here computes a statistic. Every figure comes from
 * GET /analytics/processing-time, which runs R's `qcc` port (App\Support\Spc)
 * over real review assignments, so the screen and the PDF report cannot
 * disagree. Where the payload has nothing to say, the panel says so rather
 * than filling the gap — see `verdictOf` and the empty states below.
 */

const WINDOW_OPTIONS = [
  { value: '13', label: 'Last 13 weeks' },
  { value: '26', label: 'Last 26 weeks' },
  { value: '52', label: 'Last 52 weeks' },
  { value: '104', label: 'Last 104 weeks' },
]

/*
 * ── The Process Status Indicator, and what to do when it is missing ────────
 *
 * `status` is typed 'inside' | 'outside', but a department can reach the page
 * with neither: the Pest suite records "Unverified against an empty panel:
 * processing_time.departments.status", meaning a seeded database can hand this
 * screen a department the server never classified.
 *
 * The tempting default is "inside" — everything looks calm, nobody complains.
 * That is the one answer this screen must never give, because it is the same
 * word an office gets when it has genuinely been checked and found normal. A
 * third verdict, `unreported`, keeps "we checked and it is fine" apart from
 * "we have nothing to report", which on an oversight screen is the whole
 * difference.
 */
type Verdict = 'within' | 'outside' | 'unreported'

function verdictOf(department: ProcessingTimeDepartment): Verdict {
  if (department.status === 'outside') return 'outside'
  if (department.status === 'inside') return 'within'
  return 'unreported'
}

/** The spec's own words, used verbatim wherever a verdict is printed. */
const VERDICT_LABEL: Record<Verdict, string> = {
  within: 'Within Normal Range',
  outside: 'Outside Normal Range',
  unreported: 'Not yet classified',
}

/*
 * Colour is the second signal here, never the first: every verdict above is
 * printed as words. "Outside Normal Range" is a genuine problem the spec wants
 * treated as one, so it earns the error red; "Within Normal Range" is an
 * ordinary week and gets the calm royal, not a green badge that would read as
 * an achievement.
 */
const VERDICT_TONE: Record<Verdict, string> = {
  within: 'text-royal',
  outside: 'text-s-red',
  unreported: 'text-ink-muted',
}

/*
 * ── Distilling the info hovers ─────────────────────────────────────────────
 *
 * The definitions are written server-side, next to the queries they describe
 * (AnalyticsDefinitions.php), and must stay there — a formula retyped into a
 * component is a copy of the truth that goes stale silently. But the client
 * read them on screen and said they were too long while asking to keep the
 * hovers themselves, so the shortening happens here, at the point of display.
 *
 * Leading sentences only, and never a rewrite: the server's first sentence is
 * always its summary, so cutting from the end can shorten the answer but cannot
 * change it. Two sentences for "how it is measured", because that field carries
 * both what is counted and what the clock runs between, and one each for the
 * other two, which are single claims already.
 *
 * If the server text is ever rewritten this still yields its opening sentences,
 * and the full text remains one API response away for anyone auditing.
 */
function firstSentences(text: string, count: number): string {
  // Split only where a full stop is followed by something that starts a new
  // sentence, so "3.5 days" and "e.g." survive intact.
  const parts = text.split(/(?<=[.!?])\s+(?=[A-Z"“(])/)
  return parts.slice(0, count).join(' ')
}

function distil(
  definitions: Record<string, MetricDefinition> | undefined,
): Record<string, MetricDefinition> | undefined {
  if (!definitions) return undefined
  return Object.fromEntries(
    Object.entries(definitions).map(([key, definition]) => [
      key,
      {
        ...definition,
        formula: firstSentences(definition.formula, 2),
        covers: firstSentences(definition.covers, 1),
        why: firstSentences(definition.why, 1),
      },
    ]),
  )
}

/**
 * A section title with the server's account of the panel beside it.
 *
 * The info button is a sibling of the h2 rather than a child, so "How X is
 * measured" does not fold into the heading's accessible name and get announced
 * to anyone navigating this page by heading.
 */
function SectionHeading({ children, metric }: { children: ReactNode; metric?: string }) {
  return (
    <div className="mb-2 flex items-center">
      <h2 className="text-lg font-semibold text-ink">{children}</h2>
      {metric && <Info metric={metric} />}
    </div>
  )
}

/*
 * ── Process Status Indicator ───────────────────────────────────────────────
 *
 * The spec asks this to classify *each* department, not just the one on the
 * chart, so all of them are on screen at once. Each is also the button that
 * points the chart at that department: the reader's next move after seeing
 * "Outside Normal Range" is always "show me that one", and making the verdict
 * itself the control saves a trip to the filter menu — which is where this used
 * to live, one office at a time, behind a menu.
 */
function StatusStrip({
  departments,
  selected,
  onSelect,
}: {
  departments: ProcessingTimeDepartment[]
  selected: ProcessingTimeDepartment
  onSelect: (code: string) => void
}) {
  return (
    <div
      role="group"
      aria-label="Process status by department"
      className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4"
    >
      {departments.map((department) => {
        const verdict = verdictOf(department)
        const active = department.code === selected.code
        return (
          <button
            key={department.code}
            type="button"
            aria-pressed={active}
            onClick={() => onSelect(department.code)}
            className={`rounded-xl bg-white px-3.5 py-2.5 text-left shadow-card transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-royal ${
              active ? 'ring-2 ring-royal' : 'ring-1 ring-transparent hover:ring-line-strong'
            }`}
          >
            <span className="flex items-baseline gap-1.5 overflow-hidden">
              <span className="text-[13px] font-bold text-ink">{department.code}</span>
              {/* The office picker needs a name, but the code is what fits. */}
              <span className="truncate text-[11px] text-ink-muted">{department.name}</span>
            </span>
            <span className={`mt-0.5 block text-[13px] font-semibold ${VERDICT_TONE[verdict]}`}>
              {VERDICT_LABEL[verdict]}
            </span>
            <span className="mt-0.5 block text-[11px] text-ink-muted">
              {department.latest_week
                ? `week of ${spcWeekDate(department.latest_week)} · ${department.latest_mean_days.toFixed(1)} days`
                : 'no week reported'}
            </span>
          </button>
        )
      })}
    </div>
  )
}

/*
 * ── Noted Delays ───────────────────────────────────────────────────────────
 *
 * The weeks that went beyond the normal range, each with its date and how far
 * beyond it went — the spec's three requirements, and the reason the dates are
 * written out in full rather than as "Jan 12": a 104-week window spans two
 * years, and a delay nobody can place on a calendar cannot be matched against
 * the staff absence or the system outage that caused it.
 *
 * A week can leave the range in either direction and the server lists both. A
 * week that finished *faster* than usual is not a delay and is not labelled as
 * one; it stays in the list because an unexplained speed-up is also worth a
 * question, and it is marked as what it is.
 */
function NotedDelays({ department }: { department: ProcessingTimeDepartment }) {
  const weeks = department.flagged ?? []

  if (weeks.length === 0) {
    /*
     * Two different silences. A department the server classified and found
     * normal genuinely had no week outside the range; a department it never
     * classified has told us nothing, and saying "no delays" for that one would
     * be inventing the finding.
     */
    const classified = verdictOf(department) !== 'unreported'
    return (
      <ProtoCard className="px-4 py-3.5">
        <p className="text-[13px] text-ink-secondary">
          {classified
            ? `No week in this window went beyond ${department.code}'s normal range.`
            : `Nothing reported for ${department.code} in this window.`}
        </p>
      </ProtoCard>
    )
  }

  return (
    <ProtoCard className="overflow-hidden">
      <table className="w-full text-left">
        <caption className="sr-only">
          Weeks where {department.name} went beyond its normal range, with the size of the gap
        </caption>
        <thead>
          <tr className="border-b border-line text-[10px] uppercase tracking-wide text-ink-muted">
            <th scope="col" className="px-4 py-2 font-semibold">
              Week of
            </th>
            <th scope="col" className="px-4 py-2 text-right font-semibold">
              Beyond the range
            </th>
          </tr>
        </thead>
        <tbody>
          {weeks.map((week) => {
            const slower = week.deviation_days > 0
            /*
             * `rule_hit` says which watch caught the week: past the edge of the
             * range, the gradual-slowdown line, or both. Named in plain words
             * because it changes what the reader should look for — a single bad
             * week and a slow slide are different problems.
             */
            const drift = (week.rule_hit ?? '').includes('ewma_drift')
            const breach = (week.rule_hit ?? '').includes('beyond_limits')
            return (
              <tr key={week.week_start} className="border-b border-line/60 last:border-0">
                <th scope="row" className="px-4 py-2 align-top font-normal">
                  <span className="block text-[14px] font-semibold text-ink">
                    {spcWeekDate(week.week_start)}
                  </span>
                  <span className="block text-[11px] text-ink-muted">
                    {breach && drift
                      ? 'past the edge, and drifting'
                      : drift
                        ? 'caught by the slowdown watch'
                        : 'past the edge of the range'}
                  </span>
                </th>
                <td className="px-4 py-2 text-right align-top">
                  <span
                    className={`tnum block text-[14px] font-semibold ${slower ? 'text-s-red' : 'text-ink'}`}
                  >
                    {spcSignedDays(week.deviation_days)} days
                  </span>
                  {/* The sign alone would carry this; the words carry it too. */}
                  <span className="block text-[11px] text-ink-muted">
                    {slower ? 'slower than usual' : 'faster than usual'}
                  </span>
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </ProtoCard>
  )
}

/*
 * ── Gradual Slowdown Warnings ──────────────────────────────────────────────
 *
 * The supporting indicator: R weights recent weeks more heavily than old ones,
 * so a slide of half a day a week — which never crosses the edge of the range
 * and so never appears in Noted Delays — still walks the weighted average away
 * from centre until it trips.
 *
 * "Rising" and "easing" are the server's words and they are ambiguous on their
 * own (rising *what*?), so they are printed as what they mean to an office:
 * slowing down, or speeding up. Direction decides the tone — an office getting
 * quicker must not be painted as a warning just because the movement is large.
 */
const TREND_LABEL: Record<string, string> = {
  rising: 'Slowing down',
  steady: 'Holding steady',
  easing: 'Speeding up',
}

function SlowdownWarnings({ departments }: { departments: ProcessingTimeDepartment[] }) {
  const reported = departments.filter((department) => department.trend?.direction)

  if (reported.length === 0) {
    return (
      <ProtoCard className="px-4 py-3.5">
        <p className="text-[13px] text-ink-secondary">
          No slowdown trend was reported for any department in this window.
        </p>
      </ProtoCard>
    )
  }

  const slowing = reported.filter((department) => department.trend.direction === 'rising')

  return (
    <ProtoCard className="px-4 py-3">
      {/*
       * The finding first, in one sentence, so a reader who only wants the
       * answer does not have to read four bars to get it.
       */}
      <p className="mb-2 text-[13px] text-ink-secondary">
        {slowing.length === 0
          ? 'No department is drifting slower week on week.'
          : `${slowing.map((d) => d.code).join(', ')} ${slowing.length === 1 ? 'is' : 'are'} drifting slower week on week.`}
      </p>
      <ul className="divide-y divide-line/60">
        {reported.map((department) => {
          const direction = department.trend.direction
          const rising = direction === 'rising'
          // A warning is a rising trend the server actually flagged; a large but
          // improving move is not one, however long its bar.
          const warn = rising && department.trend.drift_flagged
          return (
            <li key={department.code} className="flex items-center gap-3 py-2">
              <span className="w-24 shrink-0 text-[13px] font-bold text-ink">{department.code}</span>
              {/*
               * The bar is decoration for a number already written out beside
               * it — hidden from assistive tech so the reading is not announced
               * twice.
               */}
              <span aria-hidden="true" className="h-2 flex-1 overflow-hidden rounded-full bg-canvas">
                <span
                  className={`block h-full rounded-full ${rising ? 'bg-s-red-deep' : 'bg-royal'}`}
                  style={{ width: `${Math.max(4, (department.trend.magnitude ?? 0) * 100)}%` }}
                />
              </span>
              <span className="w-36 shrink-0 text-right">
                <span
                  className={`block text-[13px] font-semibold ${rising ? 'text-s-red' : 'text-ink-secondary'}`}
                >
                  {TREND_LABEL[direction] ?? 'Holding steady'}
                </span>
                <span className="tnum block text-[11px] text-ink-muted">
                  {spcSignedDays(department.trend.deviation_days)} days
                  {warn && <span className="font-semibold text-s-red"> · watch</span>}
                </span>
              </span>
            </li>
          )
        })}
      </ul>
    </ProtoCard>
  )
}

/**
 * The offices the server could not chart, named rather than silently dropped.
 *
 * An oversight screen that shows four departments when the city has seven
 * implies the other three were checked. They were not: a week with fewer than
 * three finished reviews is left off, because an average of one review is a
 * fact about that review and not about the office.
 */
function NotCharted({ thin }: { thin: ProcessingTimeReport['thin'] }) {
  if (!thin?.length) return null
  return (
    <p className="mt-1.5 text-[12px] text-ink-muted">
      Not charted: {thin.map((office) => office.code).join(', ')} — too few finished reviews a week
      to average.
    </p>
  )
}

function LoadingState() {
  return (
    <div className="space-y-4">
      <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
        <Skeleton className="h-20 w-full rounded-xl" />
        <Skeleton className="h-20 w-full rounded-xl" />
        <Skeleton className="h-20 w-full rounded-xl" />
        <Skeleton className="h-20 w-full rounded-xl" />
      </div>
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
        <Skeleton className="h-72 w-full rounded-2xl" />
        <Skeleton className="h-72 w-full rounded-2xl" />
      </div>
      <Skeleton className="h-32 w-full rounded-2xl" />
    </div>
  )
}

export function ProcessingTimePage() {
  // 52 weeks by default, matching the server: the limits are fitted on the
  // first 24 weeks of the window, so a shorter window leaves almost nothing to
  // monitor against them.
  const [weeks, setWeeks] = useState('52')
  const [office, setOffice] = useState<string>('')
  const [downloading, setDownloading] = useState(false)
  const [downloadError, setDownloadError] = useState<string | null>(null)

  // Resolves to { data, meta }: the statistics plus when and by which engine
  // they were computed. ComputedAt renders the meta — see AnalyticsProvenance
  // for why provenance is not optional on an analytics screen.
  const {
    data: result,
    loading,
    error,
    reload,
  } = useAsync(() => analytics.processingTime(Number(weeks)), [weeks])

  const data = result?.data
  const meta = result?.meta

  const departments = data?.departments ?? []
  // Falls back to the first charted office, so changing the window to one that
  // drops the selected office still renders a chart.
  const selected = departments.find((d) => d.code === office) ?? departments[0]

  const definitions = useMemo(() => distil(meta?.definitions), [meta?.definitions])

  async function generateReport() {
    if (downloading) return
    setDownloading(true)
    setDownloadError(null)
    try {
      await analytics.processingTimeReport(Number(weeks))
    } catch (err) {
      setDownloadError(toApiError(err).message)
    } finally {
      setDownloading(false)
    }
  }

  return (
    <div>
      <PageTitle
        right={
          <span className="flex items-center gap-3 pb-1">
            <FilterMenu
              label="Filter processing time"
              fields={[{ label: 'Window', value: weeks, options: WINDOW_OPTIONS, onChange: setWeeks }]}
            />
            {/*
             * `aria-disabled` while the PDF is being built, never `disabled`:
             * a disabled button leaves the tab order and most screen readers
             * pass over it, so the one reader who cannot see the label change
             * to "Generating…" would find the control had simply vanished. The
             * click guard in generateReport is what actually stops a second
             * request.
             */}
            <button
              type="button"
              onClick={generateReport}
              aria-disabled={downloading}
              aria-busy={downloading}
              className="rounded-lg bg-royal px-6 py-2.5 text-sm font-semibold text-white shadow-card transition-colors hover:bg-royal-hover aria-disabled:cursor-wait aria-disabled:bg-royal/60"
            >
              {downloading ? 'Generating…' : 'Generate Report'}
            </button>
          </span>
        }
      >
        Permit Processing Time Monitoring
      </PageTitle>

      <AnalyticsTabs />

      {meta && <ComputedAt meta={meta} onRefreshed={reload} />}

      {downloadError && (
        <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
          {downloadError}
        </p>
      )}

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : !data ? null : selected ? (
        <MetricDefinitions value={definitions}>
          <div className="space-y-4">
            <section>
              <SectionHeading metric="departments.status">Process Status Indicator</SectionHeading>
              <StatusStrip departments={departments} selected={selected} onSelect={setOffice} />
              <NotCharted thin={data.thin} />
            </section>

            <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
              <section>
                <SectionHeading metric="departments">
                  Department Processing Time Chart
                </SectionHeading>
                {/* Keyed so switching office remounts rather than animating one office's scale into another's. */}
                <SpcControlChart key={selected.code} department={selected} />
              </section>

              <section>
                <SectionHeading metric="departments.flagged">Noted Delays</SectionHeading>
                <NotedDelays department={selected} />
              </section>
            </div>

            <section>
              <SectionHeading metric="departments.trend">Gradual Slowdown Warnings</SectionHeading>
              <SlowdownWarnings departments={departments} />
            </section>
          </div>
        </MetricDefinitions>
      ) : (
        <MetricDefinitions value={definitions}>
          <EmptyState
            title="Not enough review history to chart yet"
            description={
              <>
                Across the last {data.window_weeks} weeks the offices finished{' '}
                {data.completed_reviews}
                <Info metric="completed_reviews" /> reviews, but no single week reached the{' '}
                {data.min_completions_per_week} finished reviews a weekly average needs. The chart
                appears once enough reviews are being finished each week.
              </>
            }
          />
        </MetricDefinitions>
      )}
    </div>
  )
}
