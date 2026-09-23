import { useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { Info, MetricDefinitions } from '../../components/ui/MetricInfo'
import { FilterMenu, PageTitle, ProtoCard } from '../../components/ui/Proto'
import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type {
  MetricDefinition,
  OfficePerformanceRow,
  OfficePerformanceTier,
} from '../../lib/types'
import { AnalyticsTabs } from './AnalyticsTabs'
import { ComputedAt } from './ComputedAt'
import { DENSE_PAGE } from './dense'

/*
 * Office Performance — all six offices on one screen (issue #102).
 *
 * The client asked for "an analytics dashboard covering ALL offices". Every
 * analytics screen this product already had answers a question about one office
 * at a time or about the register as a whole, and the gap between those two is
 * exactly the question a super admin has: which of my six offices is the slow
 * one, and is any of them breaking RA 11032 on its own.
 *
 * ── Why this is a table and not a chart ────────────────────────────────────
 *
 * Six rows and five columns is a table. A bar chart of six offices would carry
 * one number each, would need its numbers rendered underneath as a real table
 * anyway (AGENTS.md §6.2 — a screen reader cannot read an SVG), and would then
 * be a picture of the table it sits above. The comparison this screen exists to
 * make is between rows, which is what a table is for.
 *
 * ── What the columns are, and the one that is not what it looks like ───────
 *
 *   Handled              reviews finished in the window
 *   Open now             sitting with the office at this moment, and the oldest
 *   Working days held    how long it kept the ones it finished
 *   Past allowance alone holds that on their own outran the whole RA 11032 tier
 *
 * The third and fourth are BLANK FOR BPLO, and that blank is the single most
 * important thing on this screen to understand before editing it.
 *
 * BPLO acts on a filing twice — once accepting the form at intake, once
 * approving the finished filing — and both acts write `completed_at` onto the
 * SAME assignment row, because WorkflowService::completeAssignment stamps
 * unconditionally and there is one row per office per filing. So BPLO's
 * recorded time is the whole filing's lifetime: the five clearance offices'
 * reviews, the applicant's payment, the wait for an inspection, all of it,
 * labelled BPLO. Putting that beside CENRO's honest two days would be comparing
 * a total with one of its own parts.
 *
 * So the number is excluded and the row SAYS SO, in a full sentence, in the
 * cell where the number would have been. It is not dropped to a dash and it is
 * not footnoted: a blank cell in a comparison reads as zero or as an oversight,
 * and an office missing from a six-office table reads as an office that does
 * not exist — which is precisely what the client reported against Processing
 * Time when three offices were relegated to small print.
 *
 * The full argument, including why the stamp was not simply fixed here and what
 * would remove this exception, is on App\Support\OfficePerformanceAnalytics.
 * Nothing on this page computes a statistic; every figure arrives from
 * GET /analytics/office-performance.
 */

const WINDOW_OPTIONS = [
  { value: '13', label: 'Last 13 weeks' },
  { value: '26', label: 'Last 26 weeks' },
  { value: '52', label: 'Last 52 weeks' },
  { value: '104', label: 'Last 104 weeks' },
]

/*
 * Shortening the server's definitions at the point of display.
 *
 * Same function, same reasoning and the same two-then-one-then-one shape as
 * ProcessingTimePage: the definitions are written next to the queries they
 * describe and must stay there, but the client read them on screen and asked
 * for them shorter while keeping the hovers. Leading sentences only, never a
 * rewrite — the server's first sentence is always its summary, so cutting from
 * the end can shorten the answer but cannot change it.
 *
 * Duplicated rather than lifted into a shared module on purpose, for now: two
 * copies of six lines is cheaper to read than an import that makes a reader of
 * either screen go and find it. If a third screen needs it, that is the moment.
 */
function firstSentences(text: string, count: number): string {
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
 * The info button is a sibling of the h2, not a child, so "How X is measured"
 * does not fold into the heading's accessible name for anyone navigating this
 * page by heading.
 */
function SectionHeading({ children, metric }: { children: ReactNode; metric?: string }) {
  return (
    <div className="mb-2 flex items-center">
      <h2 className="text-base font-semibold text-ink">{children}</h2>
      {metric && <Info metric={metric} />}
    </div>
  )
}

/**
 * A figure, or a dash where there genuinely is none.
 *
 * AGENTS.md §6.4: "A dash, never a zero, where a figure genuinely has no
 * value." Zero working days is a real reading here — an office that finished
 * the same day it received — so rendering a null as 0 would print "instant"
 * where the truth is "nothing to measure".
 */
function Days({ value }: { value: number | null }) {
  if (value === null) return <span className="text-ink-muted">—</span>
  return <span className="tnum">{value.toFixed(1)}</span>
}

/**
 * One office's row.
 *
 * The office name is the row header (`<th scope="row">`), so a screen reader
 * announces "CENRO, working days held, 1.9" rather than reading a bare number
 * out of a grid. On a six-column table that is the difference between a
 * comparison and a list of digits.
 */
function OfficeRow({ office }: { office: OfficePerformanceRow }) {
  /*
   * Red for a non-zero breach rate, and only there.
   *
   * DESIGN.md reserves #bd0000 for errors and destructive actions and forbids
   * it as a category label — so the column HEADING is never red, and neither is
   * a rate of zero. A statutory deadline missed by one office on its own is a
   * genuine fault and is treated as one, which is the same line Processing Time
   * draws for "Outside Normal Range". Colour is the second signal: the figure
   * is printed as a number and a count either way.
   */
  const breaching = (office.breach_rate ?? 0) > 0

  return (
    <tr className="border-b border-line/60 last:border-0 align-top">
      <th scope="row" className="px-3 py-1.5 text-left font-normal">
        <span className="block whitespace-nowrap text-[14px] font-bold text-ink">{office.code}</span>
        <span className="block text-[11px] leading-snug text-ink-muted">{office.name}</span>
      </th>

      <td className="px-3 py-1.5 text-right">
        <span className="tnum text-[14px] font-semibold text-ink">{office.handled}</span>
        {office.test_holds > 0 && (
          /*
           * Named where it matters rather than only in the note at the bottom.
           * "Name both numbers" (AGENTS.md §6.4): 12 of an office's own 463, not
           * a bare 12 the reader has to go and find a denominator for.
           */
          <span className="block whitespace-nowrap text-[11px] text-ink-muted">
            {office.test_holds} from test data
          </span>
        )}
      </td>

      <td className="px-3 py-1.5 text-right">
        <span className="tnum text-[14px] font-semibold text-ink">{office.open}</span>
        {office.oldest_open_working_days !== null && (
          /*
           * The value and its unit are held together so the line breaks after
           * "waiting" rather than orphaning "days" on a line of its own. The
           * unit cannot be dropped to save the wrap: "783 days" beside a column
           * of working-day figures would be read as calendar days, which is a
           * different and much smaller number.
           */
          <span className="block text-[11px] text-ink-muted">
            oldest waiting{' '}
            <span className="tnum whitespace-nowrap">
              {office.oldest_open_working_days.toFixed(0)} working days
            </span>
          </span>
        )}
      </td>

      {office.turnaround_comparable ? (
        <>
          <td className="px-3 py-1.5 text-right">
            <span className="text-[14px] font-semibold text-ink">
              <Days value={office.mean_working_days} />
            </span>
            {/*
             * The middle value under the average, because on this register they
             * diverge: a handful of fortnight-long holds pull an office's
             * average above where most of its work actually lands, and an office
             * whose average sits well above its middle has a tail worth asking
             * about. Both, or the reader cannot tell which they are looking at.
             */}
            <span className="block text-[11px] text-ink-muted">
              middle <Days value={office.median_working_days} /> · slowest{' '}
              <Days value={office.slowest_working_days} />
            </span>
          </td>

          <td className="px-3 py-1.5 text-right">
            <span
              className={`tnum text-[14px] font-semibold ${breaching ? 'text-s-red' : 'text-ink'}`}
            >
              {office.breached ?? '—'}
            </span>
            {office.classified_holds !== null && office.classified_holds > 0 && (
              <span className="tnum block text-[11px] text-ink-muted">
                of {office.classified_holds} · {(office.breach_rate ?? 0).toFixed(1)}%
              </span>
            )}
          </td>
        </>
      ) : (
        /*
         * The exception, spelled out where the numbers would have been.
         *
         * `colSpan` rather than two blank cells: two dashes side by side invite
         * the reading that both figures are zero, and the sentence is one
         * statement about both. It is plain body text on the row's own
         * background — not a tinted panel and not red — because nothing has
         * gone wrong with this office. What is wrong is what the register
         * records about it.
         */
        <td colSpan={2} className="px-3 py-1.5">
          <span className="block max-w-md text-[12px] leading-snug text-ink-secondary">
            {office.not_comparable_reason}
          </span>
        </td>
      )}
    </tr>
  )
}

/** The comparison itself. */
function OfficeTable({ offices }: { offices: OfficePerformanceRow[] }) {
  return (
    <ProtoCard className="overflow-hidden">
      {/*
       * The table keeps its own minimum width and scrolls inside the card
       * rather than squeezing five numeric columns onto a phone, where they
       * would wrap into unreadable stacks. The card, and therefore the page,
       * stays the width of the screen.
       */}
      <div className="overflow-x-auto">
        <table className="w-full min-w-[46rem] text-left">
          <caption className="sr-only">
            Each office&rsquo;s finished reviews, open caseload, working days held and holds that
            outran the RA 11032 allowance on their own
          </caption>
          <thead>
            <tr className="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
              <th scope="col" className="px-3 py-1.5 font-semibold">
                Office
              </th>
              <th scope="col" className="px-3 py-1.5 text-right font-semibold">
                Handled
              </th>
              <th scope="col" className="px-3 py-1.5 text-right font-semibold">
                Open now
              </th>
              <th scope="col" className="px-3 py-1.5 text-right font-semibold">
                Working days held
              </th>
              <th scope="col" className="px-3 py-1.5 text-right font-semibold">
                Past allowance alone
              </th>
            </tr>
          </thead>
          <tbody>
            {offices.map((office) => (
              <OfficeRow key={office.code} office={office} />
            ))}
          </tbody>
        </table>
      </div>
    </ProtoCard>
  )
}

/**
 * The three statutory tiers, and how the comparable offices sat against them.
 *
 * All three are always drawn, including one the window holds no filings for. A
 * tier that vanishes when nothing lands in it turns "no highly technical
 * filings this year" into "this City has two tiers", and RA 11032 has three.
 */
function TierPanel({
  tiers,
  unclassified,
  comparedOffices,
}: {
  tiers: OfficePerformanceTier[]
  unclassified: number
  comparedOffices: number
}) {
  return (
    <div>
      {/*
       * Three across rather than stacked in a narrow column beside the table.
       *
       * The first arrangement put this panel in a 340px rail to the table's
       * right, which cost the table the width its fifth column needed — "Past
       * allowance alone" was clipped at 1440, which is the width most of this
       * City's desks run at, and the clipped column is the one the whole screen
       * is arguing towards. A comparison that cannot show its conclusion has the
       * layout backwards.
       */}
      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {tiers.map((tier) => {
          const share = tier.holds === 0 ? null : (tier.within / tier.holds) * 100
          return (
            <li key={tier.key}>
              <ProtoCard className="h-full px-4 py-3.5">
                <span className="flex flex-wrap items-baseline gap-x-1.5 text-[13px]">
                  <span className="font-semibold text-ink">{tier.label}</span>
                  {/* The allowance beside the name, because the tier name alone
                      does not say what the filings are being judged against. */}
                  <span className="whitespace-nowrap text-ink-muted">
                    {tier.statutory_working_days} working days
                  </span>
                </span>

                {/*
                 * A proportion bar, never the only place the figure appears.
                 * "Never Color Alone" — the same numbers are written out below
                 * it, and the bar is hidden from the accessibility tree so a
                 * screen reader is not handed an empty div after the sentence
                 * that already says it.
                 */}
                <div aria-hidden="true" className="mt-2 h-1.5 rounded-full bg-line">
                  {share !== null && (
                    <div
                      className="h-1.5 rounded-full bg-royal"
                      style={{ width: `${Math.max(share, 2)}%` }}
                    />
                  )}
                </div>

                <span className="tnum mt-2 block text-[13px] text-ink-secondary">
                  {tier.holds === 0 ? (
                    'No filings in this window'
                  ) : (
                    <>
                      {tier.within.toLocaleString()} of {tier.holds.toLocaleString()} inside
                      {tier.over > 0 && (
                        <span className="font-semibold text-s-red"> · {tier.over} over</span>
                      )}
                    </>
                  )}
                </span>
              </ProtoCard>
            </li>
          )
        })}
      </ul>

      <p className="mt-2.5 text-[12px] leading-snug text-ink-secondary">
        Counted over the {comparedOffices} offices whose recorded time is their own step.
        {unclassified > 0 && (
          <>
            {' '}
            {unclassified} hold{unclassified === 1 ? '' : 's'} sat on a filing nobody has put in a
            tier yet, so {unclassified === 1 ? 'it is' : 'they are'} not counted against any
            allowance.
          </>
        )}
      </p>
    </div>
  )
}

/**
 * What the averages on this screen are carrying.
 *
 * The register has no provenance column on `businesses` — nothing records
 * whether a row came from a citizen, the demo seeder or a Playwright run — so
 * every average on this page includes filings the test suite created, and there
 * is no honest way to subtract them. Saying so on the face of the screen is the
 * whole of what can be done about it, and it is worth more than a silent
 * average that reads as clean.
 *
 * The matching rule is printed, not hidden, because it is a guess: a reader who
 * can see "names starting E2E, QA or Test" can judge for themselves how much of
 * the register it is likely to have missed.
 */
function DataQualityNote({
  testBusinesses,
  totalBusinesses,
  testHolds,
  patterns,
  handled,
}: {
  testBusinesses: number
  totalBusinesses: number
  testHolds: number
  patterns: string[]
  handled: number
}) {
  if (testBusinesses === 0) return null

  return (
    <ProtoCard className="mb-3 px-3 py-2">
      <p className="text-[13px] leading-relaxed text-ink-secondary">
        <span className="font-semibold text-ink">These averages include test data.</span>
        <Info metric="data_quality" />{' '}
        {testBusinesses} of the {totalBusinesses.toLocaleString()} businesses on the register were
        created by the automated test suite, and {testHolds} of the {handled.toLocaleString()}{' '}
        finished reviews below belong to them. Nothing records where a business came from, so they
        are identified by name — the ones starting{' '}
        {patterns.map((pattern) => pattern.replace(/%$/, '').trim()).join(', ')} — and the count is
        a guess rather than a figure.
      </p>
    </ProtoCard>
  )
}

function LoadingState() {
  return (
    <div className="space-y-4">
      <Skeleton className="h-14 w-full rounded-2xl" />
      {/* Six offices plus a header row: roughly the height the table lands at,
          so the tier cards below do not jump half a screen on arrival. */}
      <Skeleton className="h-96 w-full rounded-2xl" />
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <Skeleton className="h-24 w-full rounded-2xl" />
        <Skeleton className="h-24 w-full rounded-2xl" />
        <Skeleton className="h-24 w-full rounded-2xl" />
      </div>
    </div>
  )
}

export function OfficePerformancePage() {
  // 52 weeks, matching Processing Time's default. The two screens are read one
  // after the other and a reader should not have to check that the windows
  // agree before wondering which figure is right.
  const [weeks, setWeeks] = useState('52')

  // Resolves to { data, meta }: the figures plus when they were computed and
  // whether that was a stored refresh or this request. These are batch figures
  // — see AnalyticsProvenance for why they have to be dated on the face of the
  // screen rather than left to read as live.
  const {
    data: result,
    loading,
    error,
    reload,
  } = useAsync(() => analytics.officePerformance(Number(weeks)), [weeks])

  const data = result?.data
  const meta = result?.meta

  const definitions = useMemo(() => distil(meta?.definitions), [meta?.definitions])

  return (
    <div {...DENSE_PAGE}>
      <PageTitle
        compact
        right={
          <span className="flex items-center gap-3 pb-1">
            <FilterMenu
              label="Filter office performance"
              fields={[
                { label: 'Window', value: weeks, options: WINDOW_OPTIONS, onChange: setWeeks },
              ]}
            />
          </span>
        }
      >
        Office Performance
      </PageTitle>

      <AnalyticsTabs />

      {meta && <ComputedAt meta={meta} onRefreshed={reload} />}

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : !data ? null : (
        <MetricDefinitions value={definitions}>
          <div className="space-y-4">
            <DataQualityNote
              testBusinesses={data.data_quality.test_businesses}
              totalBusinesses={data.data_quality.total_businesses}
              testHolds={data.data_quality.test_holds}
              patterns={data.data_quality.patterns}
              handled={data.totals.handled}
            />

            <section>
              <SectionHeading metric="offices">
                {/*
                 * Both numbers, per AGENTS.md §6.4. "All six offices" says
                 * nothing a reader can check; the count and the window together
                 * say what they are looking at and over what.
                 */}
                All {data.totals.offices} offices
              </SectionHeading>
              <p className="mb-2 text-[12px] text-ink-secondary">
                {data.totals.handled.toLocaleString()} reviews finished in the last{' '}
                {data.window_weeks} weeks, {data.totals.open.toLocaleString()} still open. Lower is
                better in the time columns.
              </p>
              <OfficeTable offices={data.offices} />
            </section>

            {/*
             * Under the table, not beside it. The tiers are the conclusion the
             * table's last column is arguing towards, so they read in that
             * order — and putting them in a rail narrowed the table enough to
             * clip that column at the width these desks actually run at.
             */}
            <section>
              <SectionHeading metric="tiers">RA 11032 tiers</SectionHeading>
              <TierPanel
                tiers={data.tiers}
                unclassified={data.unclassified_holds}
                comparedOffices={data.totals.compared_offices}
              />
            </section>
          </div>
        </MetricDefinitions>
      )}
    </div>
  )
}
