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
 * costs one line and saves the misread. That argument is the whole reason this
 * component exists, and nothing below weakens it: a batch figure has to be
 * dated on the face of the screen or it will be misread as a live one.
 *
 * ## There is one engine now, and the line says which
 *
 * This file used to carry a long argument about presenting an ENGINE BOUNDARY to
 * a licensing officer. BizTrack once ran its statistics in a second program and
 * fell back to a PHP implementation when that program was unreachable, so a
 * screen could be showing figures from either of two codebases and the banner
 * agonised over whether to say so. That split is gone: the PHP implementation is
 * the only implementation, `meta.engine` is always the string 'BizTrack', and a
 * screen cannot show a division that no longer exists.
 *
 * What remains is a plain attribution — "Computed 51 minutes ago by BizTrack" —
 * which the client asked for by name. It is one clause, it never varies, and it
 * costs nothing; its job is to stop a reader wondering whose arithmetic they are
 * looking at when the figure is quoted onward. `meta.engine_version` is null and
 * is expected to stay null, so it is rendered only when the server actually
 * sends one. Printing "by BizTrack null" is the failure this guards against; if
 * a version is ever populated it will simply appear, and that is intentional.
 *
 * Read the engine name from `meta.engine` rather than hard-coding it here. If
 * the product is ever renamed, or a screen ever serves a figure some other
 * component produced, the server is the place that knows.
 *
 * ## What did NOT go away: the freshness story
 *
 * Precompute survives in full. Snapshots, the nightly refresh, POST
 * /analytics/refresh and the Refresh button on this line are all still here —
 * they just recompute locally instead of calling out. So `meta.source` still
 * distinguishes a stored result ('snapshot') from one worked out during this
 * request ('local'), and that distinction is still load-bearing on screen,
 * because only a stored result can have gone out of date with the register.
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
 * names something the reader can do. `not_yet_refreshed` is now the ONLY
 * fallback reason the server can send — the three that described an unreachable,
 * disabled or endpoint-less second engine died with that engine — but the
 * fall-through below is kept rather than collapsed into an unconditional panel.
 * A reason this screen does not recognise must render as the ordinary quiet
 * line, because an unfamiliar code is not evidence that a figure is wrong.
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
 * The request takes a few seconds — it recomputes eight dataset variants, a year
 * of review history plus the renewal watchlist among them — so the pending state
 * is required, not polish. It got faster when the statistics stopped travelling
 * to a second process, but "faster" is not "instant" and the spinner stays.
 * `aria-busy` and `aria-live` carry the same information to a screen reader that
 * the label change carries visually.
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
      // A 200 does not mean everything recomputed: a refresh walks eight
      // datasets and can store some and fail others, which leaves this screen
      // fresh and a sibling screen stale. Report it rather than implying a
      // clean run.
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
   * it yet, or its last attempt failed. That is the one fallback reason where
   * the reader is looking at something they can change, and the control that
   * changes it is the button on this very line — so the panel and the button
   * are one statement, not a warning followed by an unrelated affordance.
   *
   * It is also, now, the only reason the server has left to send. The three it
   * used to sit beside all described a second statistics process that could be
   * missing, switched off, or short of an endpoint, and there is no second
   * process. This stays an equality test rather than becoming `!== null` so
   * that a reason nobody here has heard of falls through to the quiet line: an
   * unrecognised code is not a claim that a figure is wrong, and raising a
   * panel over one would be inventing an outage.
   *
   * `meta.notice` is deliberately not rendered anywhere here. It is written for
   * the PDF export, which has the opposite need — a filed document gets
   * forwarded and quoted by a reader who cannot ask when it was produced. See
   * AnalyticsResolver's noticeFor and the notice partial the PDF views pull in.
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
       * The timestamp and the engine that produced it, which is now always this
       * product. `by R 4.2.1` once stood here and was cut, on the grounds that a
       * third-party patch version is not a fact a licensing officer can use;
       * that objection died with the third party. "by BizTrack" is a constant,
       * so it cannot mislead, and the client asked for it in as many words.
       *
       * The version is appended only when the server sends one. It is null under
       * the current contract and expected to remain so — reading it
       * unconditionally is how the line would come to read "by BizTrack null".
       */}
      Computed {when} by {meta.engine}
      {meta.engine_version && ` ${meta.engine_version}`}
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
      {meta.source === 'snapshot' && (
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
