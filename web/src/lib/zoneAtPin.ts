import { useEffect, useMemo, useState } from 'react'
import { api } from './api'
import { useAsync } from './useAsync'

/*
 * The zone under the owner's pin, and the sentence that stops them when their
 * line of business is clearly not allowed there (Ken, 5 October 2026).
 *
 * The rule is App\Support\Zoning\PinZone's, asked as `GET zone-at-pin`, and
 * submit asks the same one on the server — so the wizard holds no copy of the
 * zone lists or the trade table, and cannot disagree with the 422 it would
 * otherwise meet. Anything unclear comes back with no refusal.
 */

export interface ZoneAtPin {
  /** The traced zone under the pin; null where it is unknown. */
  zone: { codes: string[]; name: string } | null
  /** The sentence the owner is stopped with, or null when nothing stops them. */
  refusal: string | null
}

export interface ZoneAtPinQuery {
  latitude: number
  longitude: number
  barangayId: number
  psicCodeIds: number[]
}

/**
 * Asked once the pin and the line have held still for 400 ms, as the location
 * insights beside it are. `key` names the question, so a caller can tell one
 * answer from the next; `pending` is true until the answer on hand is the
 * answer to THIS question, so a stale one never stands for a new pin. A failed
 * lookup is not pending and refuses nothing — the server checks again at
 * submit.
 */
export function useZoneAtPin(query: ZoneAtPinQuery | null) {
  const key = useMemo(() => (query === null ? null : JSON.stringify(query)), [query])
  const [settled, setSettled] = useState<string | null>(key)

  useEffect(() => {
    const timer = window.setTimeout(() => setSettled(key), 400)
    return () => window.clearTimeout(timer)
  }, [key])

  const result = useAsync<(ZoneAtPin & { key: string }) | null>(async () => {
    if (settled === null) return null
    const q = JSON.parse(settled) as ZoneAtPinQuery
    const { data } = await api.get<{ data: ZoneAtPin }>('zone-at-pin', {
      params: {
        latitude: q.latitude,
        longitude: q.longitude,
        barangay_id: q.barangayId,
        psic_code_ids: q.psicCodeIds,
      },
    })
    return { ...data.data, key: settled }
  }, [settled])

  const answered = key !== null && result.data !== null && result.data.key === key
  const failed = key !== null && settled === key && !result.loading && result.error !== null

  return {
    key,
    data: answered ? result.data : null,
    pending: key !== null && !answered && !failed,
  }
}
