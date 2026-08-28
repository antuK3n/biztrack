import { useState } from 'react'
import type { ReactNode } from 'react'
import { ErrorState, Skeleton } from '../ui/primitives'
import { Info, MetricDefinitions } from '../ui/MetricInfo'
import { ProtoCard } from '../ui/Proto'

import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type {
  Computed,
  RenewalModelEstimate,
  RenewalModelHorizon,
  RenewalModelReport,
  RiskBand,
} from '../../lib/types'

/*
 * The fitted model, shown BESIDE the rule score rather than instead of it.
 *
 * ── WHY BOTH NUMBERS ARE ON ONE ROW ─────────────────────────────────────────
 *
 * RenewalRiskPage's own note says the column may not read as a percentage,
 * because the index it draws is a weighted rule score with nothing fitted behind
 * it. That note is still correct and this panel does not touch that table.
 *
 * What changed is that a second figure now exists. The renewal outcome was never
 * a column in the register, but it was always implied by the permit dates — a
 * renewal is late when the next permit of the same type began more than a day
 * after the last one lapsed — so there are now roughly thirteen hundred labelled
 * cycles, a logistic regression fitted to the older ones, and an evaluation on
 * the newer ones the fit never saw.
 *
 * The two numbers answer different questions and can disagree, which is the
 * whole reason they are side by side:
 *
 *   - the RULE SCORE ranks a permit by how many warning signs it carries. It is
 *     transparent, it never needed evidence, and it is currently the one the
 *     office trusts.
 *   - the FITTED FIGURE says how often permits in this position actually turned
 *     out to be renewed late. It is new, it is unproven, and it can be wrong in
 *     a way the rule score cannot — which is why its accuracy figures are on
 *     this same panel and not in a report nobody opens.
 *
 * Showing only the fitted one would retire a trusted instrument on the strength
 * of a model trained on generated data. Showing only the rule score would waste
 * the outcome the register turned out to hold. Showing both, labelled, lets an
 * officer notice when they disagree — which is the most useful thing this panel
 * can do in its first months.
 *
 * ── THE TWO THINGS THIS PANEL MUST NEVER DO QUIETLY ─────────────────────────
 *
 *   1. Show a figure without the training-data notice. Almost every outcome the
 *      model was fitted on was written by the analytics seeder, so what the
 *      coefficients describe is the seeder. That sentence renders at the top of
 *      the panel, at full size, above the numbers — not in a tooltip, not
 *      collapsed, not in grey six-point type. It is the finding, not a
 *      disclaimer.
 *   2. Call the figure a probability when it is not calibrated. The server
 *      reports `metrics.calibrated`, and the heading, the column and the reading
 *      note all change with it. A predicted figure that cannot be read as a rate
 *      is a ranking with a scale, and this panel says so in those words rather
 *      than printing a percent sign and hoping.
 *
 * ── WIRING ──────────────────────────────────────────────────────────────────
 *
 * Self-contained on purpose: it fetches its own payload and carries its own
 * loading, error and unavailable states, so mounting it is one line inside the
 * MetricDefinitions provider on RenewalRiskPage and it cannot half-render if the
 * page around it changes shape.
 */

const PERCENT = (value: number) => `${Math.round(value * 100)}%`
const DECIMAL = (value: number | null, places = 3) =>
  value === null ? '—' : value.toFixed(places)

/** The rule score's own colours, matched so a band means one thing on the screen. */
const BAND_TINT: Record<RiskBand, string> = {
  high: 'border-[#c11212]/30 bg-[#c11212]/10 text-[#c11212]',
  moderate: 'border-[#b45309]/30 bg-[#b45309]/10 text-[#b45309]',
  low: 'border-[#12724a]/30 bg-[#12724a]/10 text-[#12724a]',
}

export function RenewalModelPanel() {
  const { data, loading, error } = useAsync<Computed<RenewalModelReport>>(
    () => analytics.renewalModel(),
    [],
  )

  if (loading) return <Skeleton className="h-64 rounded-2xl" />
  if (error) return <ErrorState error={error} />
  if (!data) return null

  const report = data.data

  return (
    /*
     * Its own definitions provider, not the surrounding page's.
     *
     * The info buttons below key into `renewal_model`'s definitions, and the
     * screen this mounts on provides `renewal_risk`'s. Inheriting them would
     * leave every button on this panel silently rendering nothing — which is the
     * worst version of the failure, because the panel would look complete.
     */
    <MetricDefinitions value={data.meta.definitions}>
      <section className="mt-5" aria-labelledby="renewal-model-heading">
        <div className="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          <h2 id="renewal-model-heading" className="text-[15px] font-bold text-ink">
            Fitted model — shown beside the rule score, not instead of it
            <Info metric="estimates" />
          </h2>
          <span className="text-[11px] text-ink-muted">{report.engine}</span>
        </div>

        {/*
          Above everything. A reader who takes one thing from this panel has to
          take this one, so it is not a tooltip, not a collapsed disclosure and
          not set in a smaller size than the figures it qualifies.
        */}
        <TrainingDataNotice notice={report.training_data.notice} />

        {!report.available ? (
          <Unavailable reason={report.unavailable_reason} counts={report.counts} />
        ) : (
          <>
            <Headline report={report} />
            <Estimates report={report} />
            <Evidence report={report} />
          </>
        )}
      </section>
    </MetricDefinitions>
  )
}

function TrainingDataNotice({ notice }: { notice: string }) {
  return (
    <div
      className="mb-4 rounded-2xl border border-[#b45309]/35 bg-[#b45309]/[0.07] px-4 py-3"
      role="note"
    >
      <p className="text-[12px] font-bold uppercase tracking-wide text-[#b45309]">
        Trained on demonstration data
      </p>
      <p className="mt-1 text-[13px] leading-relaxed text-ink">{notice}</p>
    </div>
  )
}

/**
 * What the panel says when there is no model.
 *
 * Deliberately not a blank space and deliberately not the rule score wearing a
 * different heading. "No model" is a state an officer can read — every reason
 * below is now some version of "the register has not accumulated enough settled
 * renewal history yet" — and the counts underneath say how much history there
 * actually is, which is the only thing that will change the answer.
 */
function Unavailable({
  reason,
  counts,
}: {
  reason: string | null
  counts: RenewalModelReport['counts']
}) {
  const sentence: Record<string, string> = {
    // The key is the server's (RenewalModelAnalytics), not ours, and it is left
    // spelled as the server spells it. The SENTENCE is what a reader sees, and
    // it no longer names an outside service: the fit is attempted here, so a
    // failure to fit is a fact about the data, not about a program being down.
    r_did_not_fit:
      'A model could not be fitted to the renewal history on record. There is no second set of figures to show in ' +
      'its place — a rule score presented under a fitted heading would be worse than nothing.',
    no_labelled_history:
      'No completed renewal cycles could be recovered from permit history yet, so there is nothing to fit to.',
    not_enough_training_history:
      'There is not yet enough settled renewal history to fit a model worth reading.',
    not_enough_evaluation_history:
      'There is history to fit on but not yet a later period to test against, and an untested model is not one to show.',
    no_signal_varies:
      'None of the five signals varied enough across the training period to be estimated.',
  }

  return (
    <ProtoCard className="px-5 py-5">
      <p className="text-[13px] font-semibold text-ink">No fitted model to show.</p>
      <p className="mt-1 max-w-3xl text-[13px] leading-relaxed text-ink-secondary">
        {sentence[reason ?? ''] ?? 'The model is unavailable.'} The rule score above is unaffected — it
        is computed from the register directly and does not depend on this.
      </p>
      <p className="mt-3 text-[12px] text-ink-muted">
        {counts.cycles_found.toLocaleString()} renewal cycles found in permit history;{' '}
        {counts.cycles_labelled.toLocaleString()} settled far enough to be labelled,{' '}
        {counts.cycles_unsettled.toLocaleString()} still waiting.
      </p>
    </ProtoCard>
  )
}

/**
 * The four figures that decide whether anyone should believe the panel, and the
 * calibration sentence that qualifies them.
 */
function Headline({ report }: { report: RenewalModelReport }) {
  const m = report.metrics

  return (
    <ProtoCard className="px-5 py-4">
      <div className="grid grid-cols-2 gap-x-6 gap-y-4 md:grid-cols-4">
        <Figure
          label="AUC"
          metric="metrics.auc"
          value={DECIMAL(m.auc)}
          note="Ordering quality on cycles the model never saw. 0.50 is a coin toss."
        />
        <Figure
          label="Brier score"
          metric="metrics.brier"
          value={DECIMAL(m.brier)}
          note={`Against ${DECIMAL(m.baseline_brier)} for guessing the base rate every time. Lower is better.`}
        />
        <Figure
          label="Calibration slope"
          metric="calibration"
          value={DECIMAL(m.calibration_slope, 2)}
          note="1.00 is ideal. Below it the figures are spread wider than the outcomes justify."
        />
        <Figure
          label="Tested on"
          metric="split"
          value={report.evaluation.cycles.toLocaleString()}
          note={`cycles expiring ${report.split.test_from} to ${report.split.test_to}, none of them fitted on.`}
        />
      </div>

      {/*
        The verdict, in the server's own words, generated from the figures rather
        than written once and left to rot. It says "they run high" when they run
        high, which is the only version of a calibration statement worth having.
      */}
      <div
        className={`mt-4 rounded-xl border px-4 py-3 ${
          m.calibrated
            ? 'border-[#12724a]/30 bg-[#12724a]/[0.06]'
            : 'border-[#b45309]/30 bg-[#b45309]/[0.06]'
        }`}
      >
        <p className="text-[12px] font-bold text-ink">
          {m.calibrated
            ? 'These figures can be read as rates.'
            : 'These figures are NOT yet calibrated — read them as a ranking, not as a rate.'}
        </p>
        <p className="mt-1 text-[13px] leading-relaxed text-ink-secondary">
          {report.calibration_statement}
        </p>
      </div>
    </ProtoCard>
  )
}

function Figure({
  label,
  metric,
  value,
  note,
}: {
  label: string
  metric: string
  value: string
  note: ReactNode
}) {
  return (
    <div>
      <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-secondary">
        {label}
        <Info metric={metric} />
      </p>
      <p className="mt-0.5 text-[26px] font-bold leading-none text-ink">{value}</p>
      <p className="mt-1 text-[11px] leading-snug text-ink-muted">{note}</p>
    </div>
  )
}

/**
 * The table where the two numbers sit next to each other.
 *
 * Column order is deliberate: the rule score comes FIRST because it is the one
 * the office already reads and the one that has not changed. The fitted figure
 * is the new column, and it is labelled as fitted in its own heading so nobody
 * has to remember which is which.
 */
function Estimates({ report }: { report: RenewalModelReport }) {
  const calibrated = report.metrics.calibrated

  return (
    <ProtoCard className="mt-4 overflow-hidden">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-5 pt-4">
        <h3 className="text-[14px] font-bold text-ink">
          Highest estimated chance of a late renewal
          <Info metric="estimates" />
        </h3>
        <span className="text-[11px] text-ink-muted">
          Top {report.estimates.length} of the permits on the watchlist
        </span>
      </div>
      <p className="mt-1 max-w-4xl px-5 text-[12px] leading-relaxed text-ink-secondary">
        {report.estimate_note}
      </p>

      <div className="mt-3 overflow-x-auto">
        <table className="w-full min-w-[720px] border-collapse text-left">
          <thead>
            <tr className="border-y border-line bg-surface-muted/40">
              <th scope="col" className="px-4 py-2 text-[11px] font-bold uppercase tracking-wide text-ink-secondary">
                Business
              </th>
              <th scope="col" className="px-4 py-2 text-[11px] font-bold uppercase tracking-wide text-ink-secondary">
                Expires
              </th>
              {/* The trusted number, first and unchanged. */}
              <th scope="col" className="px-4 py-2 text-center text-[11px] font-bold uppercase tracking-wide text-ink-secondary">
                Rule score
                <span className="block font-normal normal-case text-ink-muted">out of 100, not a rate</span>
              </th>
              {/* The new one, named as new. */}
              <th scope="col" className="px-4 py-2 text-center text-[11px] font-bold uppercase tracking-wide text-ink-secondary">
                {calibrated ? 'Fitted probability' : 'Fitted figure'}
                <span className="block font-normal normal-case text-ink-muted">
                  {calibrated ? 'late renewal, from the model' : 'uncalibrated — a ranking'}
                </span>
              </th>
            </tr>
          </thead>
          <tbody>
            {report.estimates.map((row) => (
              <EstimateRow key={`${row.permit_id}`} row={row} />
            ))}
          </tbody>
        </table>
      </div>
    </ProtoCard>
  )
}

function EstimateRow({ row }: { row: RenewalModelEstimate }) {
  return (
    <tr className="border-b border-line/60 align-top last:border-0">
      <th scope="row" className="px-4 py-2.5 text-[13px] font-semibold text-ink">
        {row.business}
        {/*
          A business commonly holds its business, sanitary and fire permits with
          the same expiry, so without the type three rows read as one repeated.
          Same reasoning as the watchlist above.
        */}
        <span className="mt-0.5 block text-[11px] font-normal text-ink-secondary">
          {row.permit_type}
          {row.barangay ? ` · ${row.barangay}` : ''}
        </span>
      </th>
      <td className="px-4 py-2.5 text-[12px] text-ink-secondary">
        {row.valid_until}
        <span className="mt-0.5 block text-[11px] text-ink-muted">
          {row.days_to_expiry < 0
            ? `lapsed ${Math.abs(row.days_to_expiry)}d ago`
            : `in ${row.days_to_expiry}d`}
        </span>
      </td>
      <td className="px-4 py-2.5 text-center">
        <span className="text-[16px] font-bold text-ink">{row.rule_score}</span>
        <span
          className={`mt-1 block w-fit mx-auto rounded-full border px-2 py-0.5 text-[10px] font-semibold ${BAND_TINT[row.rule_band]}`}
        >
          {row.rule_band_label}
        </span>
      </td>
      <td className="px-4 py-2.5 text-center">
        {row.probability === null ? (
          /*
            No number, and the reason in its place. A lapsed permit's renewal IS
            late — that is a fact off the expiry date, not an estimate — and an
            approved renewal has nothing left to wait for. Printing a figure for
            either would be dressing a certainty as an inference.
          */
          <span className="text-[11px] italic leading-snug text-ink-muted">{row.state_label}</span>
        ) : (
          <>
            <span className="text-[16px] font-bold text-ink">{PERCENT(row.probability)}</span>
            <span className="mt-1 block text-[10px] text-ink-muted">
              {row.renewal_stage === 'none' ? 'nothing filed' : row.renewal_stage.replace(/_/g, ' ')}
            </span>
          </>
        )}
      </td>
    </tr>
  )
}

/**
 * The three things a reader needs to argue with the model rather than defer to
 * it: what it learned, whether it beats the calendar, and whether its numbers
 * land where it says they will.
 */
function Evidence({ report }: { report: RenewalModelReport }) {
  const [open, setOpen] = useState(false)

  return (
    <ProtoCard className="mt-4 px-5 py-4">
      <button
        type="button"
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        className="flex w-full items-center justify-between gap-3 text-left"
      >
        <span className="text-[14px] font-bold text-ink">
          How it was fitted, and how well it does
        </span>
        <span className="text-[12px] font-semibold text-ink-secondary">{open ? 'Hide' : 'Show'}</span>
      </button>

      {open && (
        <div className="mt-4 space-y-6">
          <Method report={report} />
          <Coefficients report={report} />
          <HorizonTable rows={report.horizon_auc} />
          <CalibrationTable report={report} />
        </div>
      )}
    </ProtoCard>
  )
}

function Method({ report }: { report: RenewalModelReport }) {
  return (
    <div>
      <h4 className="text-[12px] font-bold uppercase tracking-wide text-ink-secondary">
        How the data was built
        <Info metric="split" />
      </h4>
      <p className="mt-1 max-w-4xl text-[13px] leading-relaxed text-ink">{report.methodology}</p>
      <p className="mt-2 max-w-4xl text-[12px] leading-relaxed text-ink-secondary">
        {report.counts.cycles_found.toLocaleString()} renewal cycles were found across{' '}
        {report.counts.businesses.toLocaleString()} businesses.{' '}
        {report.counts.cycles_labelled.toLocaleString()} had settled far enough to be labelled and{' '}
        {report.counts.cycles_unsettled.toLocaleString()} had not — a permit still in force, or one
        that lapsed too recently for a late renewal to have shown up yet, is left out rather than
        counted as punctual.{' '}
        {/*
          Split by time, stated as a date range rather than as a ratio. A reader
          who can see that training stops where testing starts can check the one
          claim that makes every figure above meaningful.
        */}
        The model was fitted on cycles expiring {report.split.train_from} to {report.split.train_to}{' '}
        and tested on {report.split.test_from} to {report.split.test_to} — split by{' '}
        {report.split.basis}, never at random.
      </p>
      <p className="mt-2 max-w-4xl text-[12px] leading-relaxed text-ink-muted">
        Each cycle is measured at up to {report.label.lead_days.length} points before its permit
        expired ({report.label.lead_days.join(', ')} days out), using only what the register knew on
        that date. Measurements of one cycle are not independent of each other, so the standard
        errors below are optimistic.
      </p>
    </div>
  )
}

function Coefficients({ report }: { report: RenewalModelReport }) {
  return (
    <div>
      <h4 className="text-[12px] font-bold uppercase tracking-wide text-ink-secondary">
        What the model learned
        <Info metric="coefficients" />
      </h4>
      <div className="mt-2 overflow-x-auto">
        <table className="w-full min-w-[560px] border-collapse text-left">
          <thead>
            <tr className="border-b border-line">
              <th scope="col" className="py-1.5 pr-4 text-[11px] font-bold uppercase text-ink-secondary">Signal</th>
              <th scope="col" className="py-1.5 pr-4 text-right text-[11px] font-bold uppercase text-ink-secondary">Effect on odds</th>
              <th scope="col" className="py-1.5 pr-4 text-right text-[11px] font-bold uppercase text-ink-secondary">Std. error</th>
              <th scope="col" className="py-1.5 text-[11px] font-bold uppercase text-ink-secondary">Reading</th>
            </tr>
          </thead>
          <tbody>
            {report.coefficients.map((c) => (
              <tr key={c.term} className="border-b border-line/50 align-top last:border-0">
                <td className="py-2 pr-4 text-[12px] font-semibold text-ink">{c.label}</td>
                <td className="py-2 pr-4 text-right text-[12px] tabular-nums text-ink">
                  {c.odds_ratio.toFixed(2)}
                  {/*
                    Significance is marked, not used as a filter. A signal that
                    did not reach it is still shown with its number, because "we
                    looked and found nothing" is a result an officer is entitled
                    to see rather than an omission.
                  */}
                  {!c.significant && <span className="ml-1 text-[10px] text-ink-muted">n.s.</span>}
                </td>
                <td className="py-2 pr-4 text-right text-[12px] tabular-nums text-ink-secondary">
                  {c.std_error.toFixed(2)}
                </td>
                <td className="py-2 text-[12px] leading-snug text-ink-secondary">{c.interpretation}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {report.dropped.length > 0 && (
        <div className="mt-3 rounded-xl bg-surface-muted/50 px-3 py-2">
          <p className="text-[11px] font-bold uppercase tracking-wide text-ink-secondary">
            Left out of the fit
          </p>
          <ul className="mt-1 space-y-0.5">
            {report.dropped.map((d) => (
              <li key={d.term} className="text-[12px] leading-snug text-ink-secondary">
                <span className="font-semibold text-ink">{d.label}</span> — {d.reason}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

/**
 * The most useful check on the panel, and the one most likely to be skipped.
 *
 * Permits closer to expiry are far more often renewed late, so a model that knew
 * nothing but the date would still post a respectable pooled AUC. Recomputing it
 * within each lead time removes the calendar from the comparison: whatever
 * separation is left is what the other four signals actually contribute.
 */
function HorizonTable({ rows }: { rows: RenewalModelHorizon[] }) {
  return (
    <div>
      <h4 className="text-[12px] font-bold uppercase tracking-wide text-ink-secondary">
        Accuracy with the clock held still
        <Info metric="horizon_auc" />
      </h4>
      <p className="mt-1 max-w-4xl text-[12px] leading-relaxed text-ink-secondary">
        The pooled AUC above is flattered by time alone — permits near expiry are far more often late.
        Recomputed within one lead time, every permit is the same distance out, so what is left is
        what the other four signals add.
      </p>
      <div className="mt-2 overflow-x-auto">
        <table className="w-full min-w-[420px] border-collapse text-left">
          <thead>
            <tr className="border-b border-line">
              <th scope="col" className="py-1.5 pr-4 text-[11px] font-bold uppercase text-ink-secondary">Days out</th>
              <th scope="col" className="py-1.5 pr-4 text-right text-[11px] font-bold uppercase text-ink-secondary">Cycles</th>
              <th scope="col" className="py-1.5 pr-4 text-right text-[11px] font-bold uppercase text-ink-secondary">Late</th>
              <th scope="col" className="py-1.5 text-right text-[11px] font-bold uppercase text-ink-secondary">AUC</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.days_to_expiry} className="border-b border-line/50 last:border-0">
                <td className="py-1.5 pr-4 text-[12px] tabular-nums text-ink">{row.days_to_expiry}</td>
                <td className="py-1.5 pr-4 text-right text-[12px] tabular-nums text-ink-secondary">
                  {row.observations.toLocaleString()}
                </td>
                <td className="py-1.5 pr-4 text-right text-[12px] tabular-nums text-ink-secondary">
                  {PERCENT(row.late_rate)}
                </td>
                <td className="py-1.5 text-right text-[12px] tabular-nums text-ink">
                  {row.auc === null ? (
                    <span className="text-[11px] italic text-ink-muted">every cycle late</span>
                  ) : (
                    row.auc.toFixed(3)
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function CalibrationTable({ report }: { report: RenewalModelReport }) {
  return (
    <div>
      <h4 className="text-[12px] font-bold uppercase tracking-wide text-ink-secondary">
        Predicted against what happened
        <Info metric="calibration" />
      </h4>
      <div className="mt-2 overflow-x-auto">
        <table className="w-full min-w-[420px] border-collapse text-left">
          <thead>
            <tr className="border-b border-line">
              <th scope="col" className="py-1.5 pr-4 text-[11px] font-bold uppercase text-ink-secondary">Group</th>
              <th scope="col" className="py-1.5 pr-4 text-right text-[11px] font-bold uppercase text-ink-secondary">Cycles</th>
              <th scope="col" className="py-1.5 pr-4 text-right text-[11px] font-bold uppercase text-ink-secondary">Model said</th>
              <th scope="col" className="py-1.5 text-right text-[11px] font-bold uppercase text-ink-secondary">Actually late</th>
            </tr>
          </thead>
          <tbody>
            {report.calibration.map((bin) => (
              <tr key={bin.bin} className="border-b border-line/50 last:border-0">
                <td className="py-1.5 pr-4 text-[12px] tabular-nums text-ink">{bin.bin}</td>
                <td className="py-1.5 pr-4 text-right text-[12px] tabular-nums text-ink-secondary">
                  {bin.observations.toLocaleString()}
                </td>
                <td className="py-1.5 pr-4 text-right text-[12px] tabular-nums text-ink-secondary">
                  {PERCENT(bin.predicted)}
                </td>
                <td className="py-1.5 text-right text-[12px] tabular-nums font-semibold text-ink">
                  {PERCENT(bin.observed)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
