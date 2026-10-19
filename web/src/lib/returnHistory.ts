import { api } from './api'
import { mainFormTargetLabel } from './returnTargets'
import { useAsync } from './useAsync'
import { officeFormFieldLabel } from '../pages/applicant/OfficeFormStep'

/**
 * The return history's data and lookups (App\Support\ReturnHistory on the API).
 * Kept apart from components/ReturnHistory.tsx so that file exports components only.
 */
export type ReturnItem = {
  code: string
  /** Resolved server-side for documents, checklist rows and permits; null for `form:` codes. */
  label: string | null
  kind: 'field' | 'document' | 'answer' | 'other'
  note: string | null
  /** What it said when it was returned (a filename for a document). */
  value: string | null
}

export type ReturnChange = {
  code: string
  label: string | null
  kind: ReturnItem['kind']
  old: string | null
  new: string | null
  changed: boolean
  /** False when there is no single before/after to show. */
  comparable: boolean
  replaced: { id: number | null; filename: string | null }[]
}

export type ReturnRound = {
  id: number
  scope: 'main_form' | 'permit'
  permit_type: { id: number; code: string; name: string } | null
  office: { code: string; name: string } | null
  /** Set only when someone other than the sheet's own office pressed Return (BPLO at Final Approval). */
  returned_by_office: { code: string; name: string } | null
  kind: 'return' | 'refusal'
  status: 'open' | 'complied' | 'superseded'
  remarks: string | null
  returned_at: string | null
  amended_at: string | null
  complied_at: string | null
  closed_at: string | null
  /** Staff readers only; always null for the owner. */
  returned_by: { id: number; name: string } | null
  complied_by: { id: number; name: string } | null
  items: ReturnItem[]
  changes: ReturnChange[] | null
  source: 'recorded' | 'backfill'
}

export async function fetchReturnHistory(applicationId: number): Promise<ReturnRound[]> {
  const res = await api.get<{ data: ReturnRound[] }>(`/applications/${applicationId}/returns`)

  return res.data.data
}

/**
 * Load once, share between `ReturnHistory` and every `ReturnedFieldNote`.
 * `refreshKey` re-fetches when it changes — pass something that moves when the
 * filing does (its status, say).
 */
export function useReturnHistory(applicationId: number | null | undefined, refreshKey: unknown = null) {
  const state = useAsync<ReturnRound[]>(
    () => (applicationId ? fetchReturnHistory(applicationId) : Promise.resolve([])),
    [applicationId, refreshKey],
  )

  return { rounds: state.data ?? [], loading: state.loading, error: state.error, reload: state.reload }
}

/**
 * The name a reader knows a target by: the server's label, then the wizard's
 * numbered question ("8. Do you pay rent for the premises?"), then the
 * office paper's own name for an answer.
 */
export function returnItemLabel(
  item: { code: string; label: string | null },
  permitTypeCode?: string | null,
): string {
  return (
    item.label ??
    mainFormTargetLabel(item.code) ??
    officeFormFieldLabel(permitTypeCode ?? '', item.code)
  )
}

/** Rounds about one sheet: `null` is the main form, a code is that permit's. */
export function inScope(round: ReturnRound, permitTypeCode: string | null | undefined): boolean {
  if (permitTypeCode === undefined) return true

  return permitTypeCode === null
    ? round.scope === 'main_form'
    : round.permit_type?.code === permitTypeCode
}

/** Every round that named this target, newest first. */
export function roundsForField(
  rounds: ReturnRound[],
  code: string,
  permitTypeCode: string | null = null,
): ReturnRound[] {
  return rounds.filter(
    (r) => inScope(r, permitTypeCode) && r.items.some((it) => it.code === code),
  )
}
