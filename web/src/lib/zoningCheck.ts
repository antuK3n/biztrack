import { useEffect, useMemo, useState } from 'react'
import { api } from './api'
import { useAsync } from './useAsync'

/*
 * City Ordinance No. 24-2018, applied rule by rule — the shape of the answer
 * App\Support\Zoning\ZoningCheck returns, and the two ways a screen asks for it.
 *
 * Every finding carries its citation (article, section, printed page) and one
 * of four statuses. None of them refuses anything: the ordinance makes every
 * land use "a use by right" subject to review, and the review is CPDO's. The
 * applicant reads the list as an early warning; the zoning officer reads the
 * same list as the checklist they decide against. See docs/zoning-ordinance/
 * rules.json for every rule and what the system does with it.
 */

export type ZoningStatus = 'met' | 'not_met' | 'review' | 'info'

export type ZoningGroup =
  | 'where'
  | 'uses'
  | 'home'
  | 'conditions'
  | 'special'
  | 'overlay'
  | 'site'
  | 'signs'
  | 'performance'
  | 'nonconforming'
  | 'procedure'
  | 'conflict'

export interface ZoningFinding {
  /** The rule the finding is about; `rules` adds the ones it also applies. */
  rule: string
  rules: string[]
  group: ZoningGroup
  status: ZoningStatus
  title: string
  /** The rule in plain English, from rules.json. */
  rule_text: string
  /** Why this status, for this filing. */
  reason: string
  citation: string
  citations: string[]
  /** Each rule the finding applies, in the inventory's words. */
  rules_detail: { id: string; title: string; citation: string; plain: string }[]
  /** "Applies if your lot is in …" when the rule binds only some zones here. */
  scope: string | null
  /** Facts this finding reads that the applicant answers. */
  asks: string[]
  /** Facts only CPDO records. */
  officer_asks: string[]
  /** The facts it read that nobody has answered yet. */
  missing: string[]
  /** docs/questions-for-malabon.md entry, where the ordinance needs the City's answer. */
  question: string | null
  /** 'officer' findings are for CPDO's checklist and not shown to the applicant. */
  audience: 'both' | 'officer'
}

export type ZoningFactValue = boolean | number | string

export interface ZoningFact {
  key: string
  label: string
  type: 'bool' | 'number' | 'choice' | 'date'
  who: 'applicant' | 'officer'
  help: string | null
  unit: string | null
  options: { value: string; label: string }[] | null
  value: ZoningFactValue | null
  answered_by: 'applicant' | 'officer' | null
}

export interface ZoningCheckResult {
  ready: boolean
  zones: { code: string; name: string; source: 'text' | 'sheet' | 'both'; governing: boolean }[]
  overlays: string[]
  lot_zone: string | null
  findings: ZoningFinding[]
  summary: Record<ZoningStatus, number>
  facts: ZoningFact[]
  principle: { text: string; citation: string }
}

/** The applicant's answers, as the API stores them. */
export type ZoningFactValues = Record<string, ZoningFactValue | null>

export interface ZoningCheckQuery {
  barangay_id: number | null
  application_type: 'new' | 'renewal' | 'amendment'
  lines: { psic_code_id: number; description?: string; capitalization?: number | null }[]
  floor_area_sqm?: number | null
  lot_area_sqm?: number | null
  employees?: number | null
  capitalization?: number | null
  street?: string | null
  is_rented?: boolean | null
  storeys?: number | null
  zoning_facts: ZoningFactValues
}

/**
 * The check for what is on screen, saved or not.
 *
 * Debounced like the location insights it sits beside: a number typed digit by
 * digit is one question, not four. The answer on screen while a newer question
 * is in flight is kept (not blanked) and marked `stale`, so the list does not
 * flash empty under the applicant's hand.
 */
export function useZoningCheck(query: ZoningCheckQuery | null) {
  const key = useMemo(() => (query === null ? null : JSON.stringify(query)), [query])
  const [settled, setSettled] = useState<string | null>(key)

  useEffect(() => {
    const timer = window.setTimeout(() => setSettled(key), 500)
    return () => window.clearTimeout(timer)
  }, [key])

  const result = useAsync<ZoningCheckResult | null>(async () => {
    if (settled === null) return null
    const body = JSON.parse(settled) as ZoningCheckQuery
    if (body.barangay_id === null) return null
    const { data } = await api.post<{ data: ZoningCheckResult }>('zoning-check', body)
    return data.data
  }, [settled])

  return { ...result, stale: key !== settled || result.loading }
}

/** The check for a stored filing — its owner's, or an office's that may read it. */
export function fetchZoningCheck(applicationId: number): Promise<ZoningCheckResult> {
  return api
    .get<{ data: ZoningCheckResult }>(`applications/${applicationId}/zoning-check`)
    .then(({ data }) => data.data)
}

/** The zoning officer's own answers. Keys sent as null clear. */
export function saveOfficerZoningFacts(
  applicationId: number,
  facts: ZoningFactValues,
): Promise<ZoningCheckResult> {
  return api
    .put<{ data: ZoningCheckResult }>(`applications/${applicationId}/zoning-facts`, { facts })
    .then(({ data }) => data.data)
}
