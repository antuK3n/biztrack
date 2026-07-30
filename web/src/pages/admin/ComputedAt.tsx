import { useState } from 'react'
import type { AnalyticsProvenance } from '../../lib/types'
import { formatDateTime, formatRelative } from '../../lib/format'
import { analytics } from '../../lib/resources'
import { toApiError } from '../../lib/api'

/*
 * When these figures were computed, by what, and a way to recompute them now.
 *
 * Every analytics screen carries this, and it is not decoration. The statistics
 * run in R as a separate program, in batch: `php artisan analytics:refresh` pushes
 * register rows to R and stores the result, and a page load reads the stored
 * result. So the numbers on screen are as fresh as the last refresh and no
 * fresher.
 *
 * The case that makes this necessary: a tester files an application, opens the
 * dashboard, and does not see it. That is the designed behaviour, not a bug — and
 * without a visible "computed 6 hours ago" it reads as a bug. Saying it plainly
 * costs one line and saves the misread.
 *
 * Three states, deliberately distinguished rather than collapsed into one badge:
 *
 *   R, fresh    one quiet line — the normal case should not compete with the data
 *   R, stale    the figures outlived a refresh cycle, so something is not running
 *   local       R was unreachable, so the PHP fallback computed these
 *
 * The last must never be silent. The fallback is a second implementation of the
 * same statistics, and two implementations can drift; a screen that presented
 * fallback output as R's would make that drift invisible. AnalyticsParityTest keeps
 * the two honest, this line keeps them distinguishable.
 *
 * Colour carries no meaning on its own here. The fallback state is a tinted panel
 * with a text label, not an orange word: #f2a33c on white is about 2:1 and would
 * miss the WCAG 2.1 AA target PRODUCT.md sets, and a reader who cannot separate
 * the hues would lose the distinction entirely.
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

  // role="status" rather than role="alert": this qualifies the figures, it does
  // not interrupt. Nothing is broken and nothing is asked of the reader.
  if (meta.source === 'local') {
    return (
      <div
        role="status"
        className="mb-5 rounded-lg border border-s-orange bg-s-orange-tint px-4 py-3 text-sm text-ink"
      >
        <span className="font-semibold">Computed locally, not by R.</span>{' '}
        <span className="text-ink-secondary">
          {meta.notice} These figures were computed {when} to answer this request.
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
      Computed {when} by R{meta.engine_version ? ` ${meta.engine_version}` : ''}
      <span aria-hidden="true"> · </span>
      updates when the analytics refresh runs, not on page load
      {meta.stale && (
        <>
          {' '}
          <span className="rounded bg-s-orange-tint px-2 py-0.5 font-semibold text-ink">
            Over {meta.stale_after_hours} hours old — the scheduled refresh may not be running.
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
