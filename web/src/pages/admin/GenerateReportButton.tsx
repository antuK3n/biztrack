import { useState } from 'react'
import { toApiError } from '../../lib/api'

/*
 * The "Generate Report" button, which docs/r-integration-spec.md puts on every
 * analytics screen and asks to be one component rather than four copies.
 *
 * It owns the three states a download has — idle, in flight, failed — because
 * that is the part every copy of this got slightly differently: one screen
 * disabled the button while downloading, another left it clickable, and a failed
 * download reported itself in a different place on each. A report that silently
 * fails to arrive is worse than one that says why.
 *
 * The error renders next to the button rather than at the top of the page, so it
 * appears where the reader is looking when it happens.
 */
export function GenerateReportButton({
  onGenerate,
  label = 'Generate Report',
}: {
  /** Kicks off the download. Rejections are caught and shown. */
  onGenerate: () => Promise<unknown>
  label?: string
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function run() {
    setBusy(true)
    setError(null)
    try {
      await onGenerate()
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <span className="flex items-center gap-3">
      {error && (
        <span role="alert" className="max-w-[22rem] text-right text-xs font-medium text-s-red">
          {error}
        </span>
      )}
      <button
        type="button"
        onClick={run}
        disabled={busy}
        aria-busy={busy}
        className="rounded-lg bg-royal px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:bg-royal-hover disabled:opacity-60"
      >
        {busy ? 'Generating…' : label}
      </button>
    </span>
  )
}
