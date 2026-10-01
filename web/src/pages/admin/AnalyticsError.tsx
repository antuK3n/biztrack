import { ErrorState } from '../../components/ui/primitives'
import { ProtoCard } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'

/*
 * What the dashboard and the Reports screen show when their request fails.
 *
 * Both used to show the generic error with a "Try again" button whatever the
 * failure. For a refusal that button is a trap: an office admin who opens a
 * link naming another office (App\Support\AnalyticsOffice answers 403) can
 * press it all day and be refused every time, and the red "We couldn't load
 * this" reads as the system being broken when it is the boundary working.
 *
 * So a refusal gets the server's own sentence, plainly, and the one action that
 * helps — dropping the office from the link — when there is an office in the
 * link to drop. "Try again" is kept for the failures retrying can fix: no
 * connection, a server fault, or the rate limit. A 4xx is an answer, not a
 * hiccup, and asking again gets the same answer.
 */
export function AnalyticsError({
  error,
  onRetry,
  onOwnOffice,
}: {
  error: unknown
  onRetry: () => void
  /** Clears the office from the URL. Omit when the URL names none. */
  onOwnOffice?: () => void
}) {
  const apiError = toApiError(error)

  if (apiError.status === 403) {
    return (
      <ProtoCard className="px-5 py-4">
        <p className="text-[14px] font-semibold text-ink">{apiError.message}</p>
        {onOwnOffice && (
          <button
            type="button"
            onClick={onOwnOffice}
            className="mt-3 rounded-lg border border-royal px-4 py-2 text-sm font-semibold text-royal hover:bg-royal-tint"
          >
            Show my office instead
          </button>
        )}
      </ProtoCard>
    )
  }

  const retryable = apiError.status === 0 || apiError.status === 429 || apiError.status >= 500

  return <ErrorState error={error} onRetry={retryable ? onRetry : undefined} />
}
