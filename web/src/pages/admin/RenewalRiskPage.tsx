import { useState } from 'react'
import { ErrorState, Skeleton, SkeletonCards } from '../../components/ui/primitives'
import { FilterMenu, PageTitle, ProtoCard } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { RenewalRiskReport, RenewalRiskRow, RiskBand } from '../../lib/types'
import { AnalyticsTabs } from './AnalyticsTabs'

/*
 * Renewal Risk (mockup 118).
 *
 * A DELIBERATE DEVIATION FROM THE MOCKUP, which must survive future edits.
 *
 * The mockup's table header reads "PROB. DELAY RISK" and its values are
 * percentages — 88%, 81%, 74%. There is no model behind this screen: the server
 * computes a weighted rule score (App\Support\RenewalRiskScoring), nothing is
 * fitted to historical outcomes, and no probability is estimated anywhere. So
 * the column is labelled "Risk score" and reads "88 / 100", the methodology
 * sentence sits under the title, and the rules and their weights are on the page
 * where an officer can check the score against them.
 *
 * The information hierarchy the mockup asked for is intact: four KPI cards
 * (High / Moderate / Low / Reminders Sent), Businesses at Risk on the left,
 * Recommended Actions on the right, Generate Report top-right.
 *
 * If anyone reinstates the percentage wording, they are asserting an inference
 * this product does not perform.
 */

const ROYAL = '#3242ca'
const MUTED_BAR = '#9fb6dd'

const HORIZON_OPTIONS = [
  { value: '30', label: 'Next 30 days' },
  { value: '60', label: 'Next 60 days' },
  { value: '90', label: 'Next 90 days' },
  { value: '180', label: 'Next 6 months' },
  { value: '365', label: 'Next 12 months' },
]

const DEFAULT_HORIZON = '365'

/*
 * Bands never rely on colour alone (DESIGN.md, The Never Color Alone Rule): each
 * chip carries its own word. Red is reserved for errors, so High reads as a
 * strong neutral-warning tone rather than #bd0000 — a permit about to expire is
 * a workload, not a system error.
 */
const BAND_CHIP: Record<RiskBand, string> = {
  high: 'border-amber-300 bg-amber-50 text-amber-900',
  moderate: 'border-line bg-canvas text-ink-secondary',
  low: 'border-line bg-white text-ink-muted',
}

function BandChip({ row }: { row: RenewalRiskRow }) {
  return (
    <span
      className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${BAND_CHIP[row.band]}`}
    >
      {row.band_label}
    </span>
  )
}

function Headline({ value, label, hint }: { value: string; label: string; hint?: string }) {
  return (
    <ProtoCard className="px-4 py-7 text-center">
      <p className="tnum text-[30px] font-bold leading-none text-royal">{value}</p>
      <p className="mt-2.5 text-[13px] text-ink-muted">{label}</p>
      {hint && <p className="mt-1 text-[11px] text-ink-muted">{hint}</p>}
    </ProtoCard>
  )
}

/** "2026-07-31" reads as "Jul 31" in the table, with the year on hover. */
function expiryLabel(date: string): string {
  const [year, month, day] = date.split('-').map(Number)
  return new Date(year, month - 1, day).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}

function AtRiskTable({ rows }: { rows: RenewalRiskRow[] }) {
  return (
    <ProtoCard className="overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-left">
          <thead>
            <tr className="border-b border-line text-[11px] uppercase tracking-wide text-ink-muted">
              <th scope="col" className="px-5 py-2.5 font-semibold">
                Business
              </th>
              <th scope="col" className="px-5 py-2.5 font-semibold">
                Barangay
              </th>
              {/*
                Not "Prob. delay risk". A score out of 100 from a rule set, with
                the band beside it so the number is never read alone.
              */}
              <th scope="col" className="px-5 py-2.5 text-right font-semibold">
                Risk score
              </th>
              <th scope="col" className="px-5 py-2.5 font-semibold">
                Expires
              </th>
              <th scope="col" className="px-5 py-2.5 font-semibold">
                Recommended action
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.permit_id} className="border-b border-line/60 align-top last:border-0">
                <th scope="row" className="px-5 py-3 text-[14px] font-semibold text-ink">
                  {row.business}
                  {/*
                    A business commonly holds its business, sanitary and fire
                    permits with the same expiry date, so without the permit type
                    three rows read as one row repeated three times.
                  */}
                  <span className="mt-0.5 block text-[12px] font-normal text-ink-secondary">
                    {row.permit_type}
                  </span>
                  {/* The score's reasons, on the row that carries the score. */}
                  <span className="mt-1 block text-[11px] font-normal text-ink-muted">
                    {row.drivers.length > 0
                      ? row.drivers.map((driver) => driver.detail).join(' · ')
                      : 'No risk signals on record'}
                  </span>
                </th>
                <td className="px-5 py-3 text-[13px] text-ink-secondary">
                  {row.barangay ?? <span className="text-ink-muted">Not on record</span>}
                </td>
                <td className="px-5 py-3 text-right">
                  <span className="tnum text-[15px] font-semibold text-ink">{row.score}</span>
                  <span className="tnum text-[11px] text-ink-muted"> / 100</span>
                  <span className="mt-1 block">
                    <BandChip row={row} />
                  </span>
                </td>
                <td className="px-5 py-3 text-[13px] text-ink-secondary">
                  <span title={row.valid_until}>{expiryLabel(row.valid_until)}</span>
                  <span className="mt-0.5 block text-[11px] text-ink-muted">
                    {row.days_to_expiry < 0
                      ? `Lapsed ${Math.abs(row.days_to_expiry)}d ago`
                      : `in ${row.days_to_expiry}d`}
                  </span>
                </td>
                <td className="px-5 py-3 text-[13px] text-ink-secondary">
                  {row.action_label}
                  {row.reminders_sent > 0 && (
                    <span className="mt-0.5 block text-[11px] text-ink-muted">
                      {row.reminders_sent} reminder{row.reminders_sent === 1 ? '' : 's'} already sent
                    </span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </ProtoCard>
  )
}

function RecommendedActions({ report }: { report: RenewalRiskReport }) {
  const peak = Math.max(1, ...report.actions.map((action) => action.count))

  return (
    <ProtoCard className="space-y-4 px-5 py-5">
      {report.actions.map((action) => (
        <div key={action.action} className="flex items-center gap-4">
          <p className="w-36 shrink-0 text-[13px] font-bold text-ink">{action.label}</p>
          <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-canvas">
            <div
              className="h-full rounded-full"
              style={{
                width: `${Math.max(2, (action.count / peak) * 100)}%`,
                backgroundColor: action.band === 'high' ? ROYAL : MUTED_BAR,
              }}
            />
          </div>
          <p className="tnum w-14 shrink-0 text-right text-[13px] font-semibold text-ink">
            {action.count.toLocaleString()}
          </p>
        </div>
      ))}
      <p className="border-t border-line pt-3 text-xs text-ink-muted">
        One action per band: {report.thresholds.high} and above needs immediate follow-up,{' '}
        {report.thresholds.moderate} to {report.thresholds.high - 1} a reminder, below{' '}
        {report.thresholds.moderate} monitoring only.
      </p>
    </ProtoCard>
  )
}

/**
 * The rule book, rendered from the server's own weights.
 *
 * This panel is the reason the score can be called a score honestly: an officer
 * who disagrees with a ranking can see exactly which rule they disagree with.
 */
function HowItWorks({ report }: { report: RenewalRiskReport }) {
  return (
    <ProtoCard className="px-5 py-5">
      <ul className="space-y-3">
        {report.rulebook.map((rule) => (
          <li key={rule.rule} className="flex gap-4">
            <span className="tnum w-12 shrink-0 text-right text-[13px] font-semibold text-royal">
              {rule.max} pts
            </span>
            <span className="min-w-0">
              <span className="block text-[13px] font-bold text-ink">{rule.label}</span>
              <span className="block text-[12px] leading-relaxed text-ink-secondary">
                {rule.description}
              </span>
            </span>
          </li>
        ))}
      </ul>
    </ProtoCard>
  )
}

function LoadingState() {
  return (
    <div className="space-y-6">
      <SkeletonCards count={4} />
      <div className="grid gap-6 lg:grid-cols-[1.6fr_1fr]">
        <Skeleton className="h-72 w-full rounded-2xl" />
        <Skeleton className="h-72 w-full rounded-2xl" />
      </div>
    </div>
  )
}

export function RenewalRiskPage() {
  const [days, setDays] = useState(DEFAULT_HORIZON)
  const [downloading, setDownloading] = useState(false)
  const [downloadError, setDownloadError] = useState<string | null>(null)

  const { data, loading, error, reload } = useAsync(() => analytics.renewalRisk(Number(days)), [days])

  async function generateReport() {
    setDownloading(true)
    setDownloadError(null)
    try {
      await analytics.renewalRiskReport(Number(days))
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
              label="Filter renewal risk"
              fields={[{ label: 'Window', value: days, options: HORIZON_OPTIONS, onChange: setDays }]}
            />
            <button
              type="button"
              onClick={generateReport}
              disabled={downloading}
              className="rounded-lg bg-royal px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:bg-royal-hover disabled:opacity-60"
            >
              {downloading ? 'Generating…' : 'Generate Report'}
            </button>
          </span>
        }
      >
        Renewal Risk
      </PageTitle>

      <AnalyticsTabs />

      {downloadError && (
        <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
          {downloadError}
        </p>
      )}

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : data ? (
        <>
          {/*
            Verbatim from the server. It is the sentence that stops four big
            numbers and a ranked table from reading as a forecast, so it sits
            above the numbers, not in a footnote below them.
          */}
          <p className="mb-5 rounded-lg border border-line bg-white px-4 py-3 text-[13px] leading-relaxed text-ink-secondary">
            {data.methodology}
          </p>

          <div className="grid grid-cols-2 gap-5 lg:grid-cols-4">
            <Headline value={data.counts.high.toLocaleString()} label="High Risk" />
            <Headline value={data.counts.moderate.toLocaleString()} label="Moderate Risk" />
            <Headline value={data.counts.low.toLocaleString()} label="Low Risk" />
            <Headline
              value={data.reminders_sent.toLocaleString()}
              label="Reminders Sent"
              hint={
                data.reminders_sent === 0
                  ? 'No expiry reminder recorded for these permits yet'
                  : 'Recorded sends from the expiry-notice ledger'
              }
            />
          </div>

          <div className="mt-7 grid gap-x-6 gap-y-7 lg:grid-cols-[1.6fr_1fr]">
            <section>
              <h2 className="mb-2.5 text-xl text-ink">Businesses at Risk</h2>
              {data.at_risk.length > 0 ? (
                <AtRiskTable rows={data.at_risk} />
              ) : (
                <ProtoCard className="px-5 py-6">
                  <p className="text-sm text-ink-secondary">
                    No permit expires between {data.window_start} and {data.window_end}, so there is
                    nothing to rank.
                  </p>
                </ProtoCard>
              )}
            </section>

            <section>
              <h2 className="mb-2.5 text-xl text-ink">Recommended Actions</h2>
              <RecommendedActions report={data} />
            </section>

            <section className="lg:col-span-2">
              <h2 className="mb-2.5 text-xl text-ink">What drives the score</h2>
              <HowItWorks report={data} />
            </section>
          </div>

          <p className="mt-6 text-xs text-ink-muted">
            {data.scored_permits.toLocaleString()} permit
            {data.scored_permits === 1 ? '' : 's'} scored — those expiring on or before{' '}
            {data.window_end}, plus any that lapsed since {data.window_start}. Revoked and suspended
            permits are excluded.
            {data.at_risk.length < data.scored_permits &&
              ` Showing the ${data.at_risk.length} highest-scoring.`}
          </p>
        </>
      ) : null}
    </div>
  )
}
