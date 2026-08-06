import { useState } from 'react'
import type { AnalyticsProvenance } from '../../lib/types'
import { formatDateTime, formatRelative } from '../../lib/format'
import { analytics } from '../../lib/resources'
import { toApiError } from '../../lib/api'

/*
 * When these figures were computed, and a way to recompute them now.
 *
 * Every analytics screen carries this, and it is not decoration. The statistics
 * are computed in batch: `php artisan analytics:refresh` recomputes them and
 * stores the result, and a page load reads the stored result. So the numbers on
 * screen are as fresh as the last refresh and no fresher.
 *
 * The case that makes this necessary: a tester files an application, opens the
 * dashboard, and does not see it. That is the designed behaviour, not a bug — and
 * without a visible "computed 6 hours ago" it reads as a bug. Saying it plainly
 * costs one line and saves the misread.
 *
 * ## What this line stopped saying, and why
 *
 * It used to lead with "Computed locally, not by R." in an orange panel, followed
 * by a sentence from the server explaining that the requested window was not one
 * of the precomputed windows, so the R service had no result for it. The client
 * asked for that class of copy to go. Three separate things were wrong with it:
 *
 *  1. **It was addressed to nobody on screen.** A BPLO officer did not choose to
 *     run the statistics in a second process. They cannot add a window to
 *     config/analytics.php, and pressing Refresh would not have helped, because
 *     an unprecomputed window is a configuration answer and not an outage. A
 *     reader who cannot act on a fact should not be handed it.
 *  2. **It fired on ordinary use.** Only one dashboard window was precomputed
 *     while the dropdown offered five, so four of five choices raised an orange
 *     panel — and nothing was wrong in any of them. A warning that fires on the
 *     majority of a screen's own options has stopped carrying information. That
 *     half was fixed where it was caused, in config/analytics.php, which now
 *     mirrors the selectors; rewording alone could not have fixed it.
 *  3. **It named an implementation split as if it were a fault.** Both engines
 *     compute the same statistics from the same rows.
 *
 * ## What survives, and where
 *
 * The provenance guarantee is real and is untouched: `meta.source`,
 * `meta.engine`, `meta.engine_version` and `meta.fallback_reason` are on every
 * response, AnalyticsParityTest holds the two implementations to the same
 * fixtures, and the exported PDF reports name the engine in full — a document
 * gets forwarded and quoted by someone who cannot ask. None of that needs a
 * banner above a dashboard. Provenance stayed; the announcement went.
 *
 * ## Three states, still deliberately distinguished
 *
 *   fresh       one quiet line with the timestamp — the normal case should not
 *               compete with the data it qualifies
 *   stale       the figures outlived a refresh cycle, so the age is called out
 *   unrefreshed the figures have never been computed by the batch job, and the
 *               Refresh button beside this line is exactly the fix
 *
 * The third is the only one that gets a panel, because it is the only one that
 * names something the reader can do. Every other fallback reason renders as the
 * ordinary quiet line: the figures are correct and current, which is all the
 * timestamp ever claimed.
 *
 * Colour carries no meaning on its own here. The unrefreshed state is a tinted
 * panel with a text label, not an orange word: #f2a33c on white is about 2:1 and
 * would miss the WCAG 2.1 AA target PRODUCT.md sets, and a reader who cannot
 * separate the hues would lose the distinction entirely.
 */

/**
 * Recompute now.
 *
 * Sits next to the timestamp rather than up in the header beside Generate Report,
 * because it acts on the timestamp: the thing it changes is the sentence it is
 * attached to. Putting it in the header would read as another export.
 *
 * The request takes a few seconds — it pushes a year of review history plus the
 * renewal watchlist to R across eight dataset variants — so the pending state is
 * required, not polish. `aria-busy` and `aria-live` carry the same information to
 * a screen reader that the label change carries visually.
 */
function RefreshButton({ onDone }: { onDone: () => void }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [partial, setPartial] = useState<string | null>(null)

  async function run() {
    setBusy(true)
    setError(null)
    setPartial(null)
    try {
      const result = await analytics.refresh()
      // A 200 does not mean everything recomputed: R can succeed on some
      // datasets and fail others, which leaves this screen fresh and a sibling
      // screen stale. Report it rather than implying a clean run.
      if (result.failed > 0) setPartial(result.message)
      onDone()
    } catch (e) {
      setError(toApiError(e).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <button
        type="button"
        onClick={run}
        disabled={busy}
        aria-busy={busy}
        className="rounded-md px-2 py-0.5 font-semibold text-royal underline decoration-royal/40 underline-offset-2 transition-colors hover:decoration-royal focus:outline-none focus-visible:ring-2 focus-visible:ring-royal disabled:cursor-wait disabled:text-ink-muted disabled:no-underline"
      >
        {busy ? 'Recomputing…' : 'Refresh now'}
      </button>
      {(error || partial) && (
        <span role="status" className="ml-2 text-ink">
          {error ?? partial}
        </span>
      )}
    </>
  )
}

export function ComputedAt({ meta, onRefreshed }: { meta: AnalyticsProvenance; onRefreshed?: () => void }) {
  const when = (
    <time dateTime={meta.computed_at} title={formatDateTime(meta.computed_at)}>
      {formatRelative(meta.computed_at)}
    </time>
  )

  /*
   * The one case that earns a panel.
   *
   * `not_yet_refreshed` means this view IS one of the precomputed ones
   * (config/analytics.php) and the batch job has simply not stored a result for
   * it yet, or its last attempt failed. That is the only fallback reason where
   * the reader is looking at something they can change, and the control that
   * changes it is the button on this very line — so the panel and the button
   * are one statement, not a warning followed by an unrelated affordance.
   *
   * The other three reasons (`no_r_endpoint`, `r_disabled`,
   * `window_not_precomputed`) describe how the server is configured. Pressing
   * Refresh will not move any of them, and none of them means a figure is
   * wrong, so they fall through to the quiet line below with no announcement at
   * all. `window_not_precomputed` in particular is now almost entirely Renewal
   * Risk's filtered and paginated requests, whose key space cannot be
   * precomputed by design — flagging those would be flagging the officer's own
   * filters as a fault.
   *
   * `meta.notice` is deliberately not rendered anywhere here. It is written for
   * the PDF export, which has the opposite need — see AnalyticsResolver's
   * noticeFor and resources/views/pdf/partials/local-notice.blade.php.
   *
   * role="status" rather than role="alert": this qualifies the figures, it does
   * not interrupt. Nothing is broken.
   */
  if (meta.fallback_reason === 'not_yet_refreshed') {
    return (
      <div
        role="status"
        className="mb-5 rounded-lg border border-s-orange bg-s-orange-tint px-4 py-3 text-sm text-ink"
      >
        <span className="font-semibold">These figures have not been recomputed yet.</span>{' '}
        <span className="text-ink-secondary">
          They were worked out {when}, for this page only.
        </span>
        {onRefreshed && (
          <>
            {' '}
            <RefreshButton onDone={onRefreshed} />
          </>
        )}
      </div>
    )
  }

  return (
    <p role="status" className="mb-5 text-sm text-ink-muted">
      {/*
       * The timestamp, and nothing about which process produced it. `by R 4.2.1`
       * stood here; an R patch version is not a fact a licensing officer can use,
       * and it travels on the exported PDF where it is genuinely evidence.
       */}
      Computed {when}
      {/*
       * Only a stored result can be out of date with the register — a locally
       * computed one was derived from the rows as they are right now. So the
       * "does not change on reload" qualifier belongs to the snapshot case only,
       * and stating it for a live computation would be false.
       *
       * It is phrased as a fact about the figures rather than about the job that
       * produces them ("updates when the analytics refresh runs" named an
       * artisan command through the UI). It survives at all because the misread
       * in this file's docblock — file an application, open the dashboard, do
       * not see it — is a question about the figures, and the timestamp alone
       * only half answers it.
       */}
      {meta.source === 'r' && (
        <>
          <span aria-hidden="true"> · </span>
          they change only when they are recomputed, not when you reload
        </>
      )}
      {/*
       * The age, without the diagnosis. "the scheduled refresh may not be
       * running" was a hypothesis about server-side cron offered to a reader
       * with no access to it. How old the figures are is theirs to weigh; why
       * the job did not run is for a monitoring channel.
       */}
      {meta.stale && (
        <>
          {' '}
          <span className="rounded bg-s-orange-tint px-2 py-0.5 font-semibold text-ink">
            Over {meta.stale_after_hours} hours old
          </span>
        </>
      )}
      {onRefreshed && (
        <>
          <span aria-hidden="true"> · </span>
          <RefreshButton onDone={onRefreshed} />
        </>
      )}
    </p>
  )
}
