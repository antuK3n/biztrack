import { api } from '../../lib/api'

/*
 * Two small things the officer's filing view needs and nothing else reads,
 * kept beside it rather than in lib/resources.ts (which several people edit
 * at once).
 */

/**
 * The three Edit-mode groups, as the API enforces them.
 *
 * Client, 19 October 2026: *"have restrictions on what certain fields are
 * allowed to be edited only by the admin."* The lists live in ONE place —
 * `App\Support\OfficerEditRules` on the API — and are served at
 * `GET /reference/officer-edit-rules`, so the lock icons on the review page
 * cannot drift from what PUT /applications/{id}/fields and the office-form
 * PUT will accept. Anything not listed as office-use or clerical is locked.
 */
export interface OfficerEditRules {
  business: { office: string; office_use: string[]; clerical: string[] }
  sheets: Record<string, { office_use: string[]; clerical: string[] }>
  messages: { locked: string; reason: string; not_your_form: string }
}

export async function fetchOfficerEditRules(): Promise<OfficerEditRules> {
  const res = await api.get<{ data: OfficerEditRules }>('/reference/officer-edit-rules')

  return res.data.data
}

/**
 * An office's correction to its own sheet, with the reason group 2 requires.
 *
 * The same PUT `officeForms.save` makes, plus `reason` — kept here rather than
 * widening the shared helper, whose other callers (the applicant's sheet)
 * never send one.
 */
export async function saveSheetCorrection(
  applicationId: number,
  permitTypeCode: string,
  formData: Record<string, unknown>,
  reason: string,
): Promise<void> {
  await api.put(`/applications/${applicationId}/office-forms/${permitTypeCode}`, {
    form_data: formData,
    reason: reason.trim() || undefined,
  })
}

/** The tooltip on a locked answer (client's wording, 19 October 2026). */
export const LOCKED_TOOLTIP = 'Only the applicant can change this — use Return.'
