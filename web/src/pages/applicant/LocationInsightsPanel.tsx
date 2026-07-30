import type { ReactNode } from 'react'
import { api } from '../../lib/api'
import { Skeleton } from '../../components/ui/primitives'
import { useAsync } from '../../lib/useAsync'

/*
 * Business Location Insights (docs/r-integration-spec.md §5) — the panel inside
 * the apply wizard's zoning-result modal, for the point the applicant just
 * pinned.
 *
 * Types are declared here rather than in lib/types.ts and the fetch is inline
 * rather than in lib/resources.ts: this shape has exactly one consumer, and
 * keeping it beside that consumer means the whole feature is one file to read.
 *
 * Every label on screen names the thing that was actually measured. The radius
 * comes from the API rather than a constant duplicated here, "similar" says
 * which trade it matched, and the mean distance says it is straight-line — an
 * applicant deciding where to open a shop is entitled to know that "320 m" is
 * as-the-crow-flies and that the comparison set is their own PSIC group.
 */

interface LocationInsightsData {
  radius_m: number
  concentration: {
    count: number
    band: 'low' | 'medium' | 'high'
    thresholds: { medium_from: number; high_from: number }
  }
  similar: {
    /** False when the applicant has not named their line of business yet. */
    available: boolean
    psic_group: string | null
    psic_title: string | null
    count: number | null
    average_distance_m: number | null
  }
  common_type: {
    available: boolean
    category: string | null
    count: number | null
    of_total: number
  }
}

export interface LocationInsightsQuery {
  latitude: number
  longitude: number
  psicCodeId: number | null
  businessId: number | null
}

/** Reads the insights for one pinned point. Computed per request — see LocationInsights.php. */
export function useLocationInsights(query: LocationInsightsQuery | null) {
  const { latitude, longitude, psicCodeId, businessId } = query ?? {
    latitude: 0,
    longitude: 0,
    psicCodeId: null,
    businessId: null,
  }

  return useAsync<LocationInsightsData | null>(async () => {
    if (query === null) return null
    const { data } = await api.get<{ data: LocationInsightsData }>('location-insights', {
      params: {
        latitude,
        longitude,
        ...(psicCodeId !== null ? { psic_code_id: psicCodeId } : {}),
        ...(businessId !== null ? { business_id: businessId } : {}),
      },
    })
    return data.data
  }, [query === null, latitude, longitude, psicCodeId, businessId])
}

/*
 * Bands are an ordinal scale, not a verdict: a busy block is not an error and a
 * quiet one is not a pass, so nothing here borrows the error red (DESIGN.md, the
 * Red Means Stop rule). The band word is always rendered as text next to the
 * tint, so the scale survives with colour off (the Never Color Alone rule).
 */
const BAND_LABEL: Record<'low' | 'medium' | 'high', string> = {
  low: 'Low',
  medium: 'Medium',
  high: 'High',
}

const BAND_CLASS: Record<'low' | 'medium' | 'high', string> = {
  low: 'bg-canvas text-ink-secondary',
  medium: 'bg-s-yellow-tint text-amber-800',
  high: 'bg-s-purple-tint text-s-purple',
}

/** One `label : value` row, with the note that says what the figure measured. */
function InsightRow({
  label,
  note,
  children,
}: {
  label: string
  note?: string
  children: ReactNode
}) {
  return (
    <tr className="align-baseline">
      <th scope="row" className="py-2.5 pr-3 text-left font-normal text-ink">
        <span className="flex gap-2">
          <span aria-hidden="true" className="mt-[0.45rem] h-1.5 w-1.5 shrink-0 rounded-full bg-ink-muted" />
          <span>
            {label}
            {note && <span className="mt-0.5 block text-xs text-ink-muted">{note}</span>}
          </span>
        </span>
      </th>
      <td className="w-px whitespace-nowrap py-2.5 pl-3 text-right align-baseline text-sm font-semibold text-ink">
        {children}
      </td>
    </tr>
  )
}

/** The dash a figure gets when it genuinely has no value, never a zero. */
function Unavailable({ children }: { children: string }) {
  return (
    <span className="font-normal text-ink-muted">
      <span aria-hidden="true">— </span>
      <span className="text-xs">{children}</span>
    </span>
  )
}

export function LocationInsightsPanel({
  insights,
  loading,
  error,
}: {
  insights: LocationInsightsData | null
  loading: boolean
  error: unknown
}) {
  const radius = insights ? `${insights.radius_m} m` : '500 m'

  return (
    <section className="mt-5 rounded-lg bg-canvas px-4 py-3.5" aria-labelledby="location-insights-heading">
      <h3 id="location-insights-heading" className="text-base font-semibold text-ink">
        Location insights:
      </h3>

      {loading && (
        <div className="mt-2 space-y-3 py-1" role="status" aria-label="Loading location insights">
          {[0, 1, 2, 3].map((i) => (
            <div key={i} className="flex items-center justify-between gap-6">
              <Skeleton className="h-3.5 w-56" />
              <Skeleton className="h-3.5 w-16" />
            </div>
          ))}
        </div>
      )}

      {/*
       * A failed lookup must never block the filing. These figures are advisory;
       * the zoning clearance does not depend on them, so the panel says it could
       * not load and Proceed stays enabled.
       */}
      {!loading && error !== null && (
        <p className="mt-1.5 text-sm text-ink-secondary">
          We couldn&rsquo;t load the insights for this location. This does not affect your
          application — you can continue.
        </p>
      )}

      {!loading && error === null && insights !== null && (
        <>
          <table className="mt-1 w-full border-collapse text-sm">
            <caption className="sr-only">
              Registered businesses near the location you pinned, within {radius}
            </caption>
            <tbody className="divide-y divide-line/70">
              <InsightRow
                label={`Similar businesses within ${radius}.`}
                note={
                  insights.similar.available
                    ? `Same PSIC group as your line${
                        insights.similar.psic_title ? ` — ${insights.similar.psic_title}` : ''
                      }`
                    : undefined
                }
              >
                {insights.similar.available && insights.similar.count !== null ? (
                  <span className="tnum">{insights.similar.count}</span>
                ) : (
                  <Unavailable>choose your Line of Business first</Unavailable>
                )}
              </InsightRow>

              <InsightRow
                label="Overall commercial activity in the area."
                note={`All registered businesses within ${radius} · Low 0–5 · Medium 6–10 · High ${insights.concentration.thresholds.high_from}+`}
              >
                <span
                  className={`inline-flex items-center gap-1.5 rounded-md px-2.5 py-0.5 text-xs font-semibold ${
                    BAND_CLASS[insights.concentration.band]
                  }`}
                >
                  {BAND_LABEL[insights.concentration.band]}
                  <span className="tnum font-normal">({insights.concentration.count})</span>
                </span>
              </InsightRow>

              <InsightRow
                label="Dominant line of business operating nearby."
                note={
                  insights.common_type.available
                    ? `${insights.common_type.count} of ${insights.common_type.of_total} nearby businesses`
                    : undefined
                }
              >
                {insights.common_type.available ? (
                  insights.common_type.category
                ) : (
                  <Unavailable>no registered businesses in range</Unavailable>
                )}
              </InsightRow>

              <InsightRow
                label="Average distance of nearby similar businesses."
                note={insights.similar.average_distance_m !== null ? 'Straight-line mean' : undefined}
              >
                {insights.similar.average_distance_m !== null ? (
                  <span className="tnum">{insights.similar.average_distance_m} m</span>
                ) : (
                  <Unavailable>
                    {insights.similar.available
                      ? 'none in range'
                      : 'choose your Line of Business first'}
                  </Unavailable>
                )}
              </InsightRow>
            </tbody>
          </table>

          {/*
           * Says out loud what the panel is for. Without this an applicant can
           * read four confident figures as part of the conformity decision, which
           * is the one thing they are not.
           */}
          <p className="mt-3 text-xs leading-relaxed text-ink-muted">
            For your information only — these figures are not part of the zoning decision.
          </p>
        </>
      )}
    </section>
  )
}
