import { useCallback, useEffect, useState } from 'react'
import { Alert } from '../../components/ui/Alert'
import { toApiError } from '../../lib/api'
import { clearances, officeForms } from '../../lib/resources'
import type { OfficeFormRequirement } from '../../lib/types'
import { WhatToCorrect, hasOfficeForm, type OfficeFormCode } from './OfficeFormStep'
import { fileRejection, uploadErrorMessage } from './uploads'

/**
 * A returned clearance, answered without leaving the tracking page.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * The client, 30 September 2026: *"It still opens another page with the modal
 * in it."* Fix and resubmit navigated — first to the office sheet, then to the
 * clearance cards — and put the dialog on top of whatever had loaded. Both
 * were pages the applicant had not asked for, to answer a return about one
 * document.
 *
 * So the dialog opens where they already are. This component is the small
 * amount of machinery that makes that possible off the list payload, which
 * carries a permit's code, name and status and nothing else: the office's
 * pointer and note live on the clearance rows, and the checklist lives on the
 * office form, so both are fetched when the dialog opens and not before. One
 * filing's worth, on a press, rather than on every row of every filing.
 *
 * ── What it is NOT ──────────────────────────────────────────────────────────
 *
 * A replacement for the clearance stage. `/applications/:id/clearances` is
 * where an applicant APPLIES for a clearance, hands in a copy they already
 * hold, and opens each office's sheet to fill it in — none of which is a
 * correction. Removing that page to save one navigation would delete four
 * working things to fix one. What changes is only that a correction no longer
 * routes through it.
 */
export function ClearanceCorrections({
  applicationId,
  code,
  onClose,
  onDone,
}: {
  applicationId: number
  /** The returned permit, e.g. ZONING. */
  code: string
  onClose: () => void
  /** Resubmitted, so the caller can refresh the row behind the dialog. */
  onDone: () => void
}) {
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [target, setTarget] = useState('')
  const [notes, setNotes] = useState<Record<string, string> | null>(null)
  const [requirements, setRequirements] = useState<OfficeFormRequirement[]>([])
  const [data, setData] = useState<Record<string, unknown>>({})
  const [busyCode, setBusyCode] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  /*
   * Both halves of the return, in one pass.
   *
   * `clearances.list` carries what the office pointed at and what it said;
   * `officeForms.list` carries the checklist those codes name and the answers
   * already on the sheet. Neither is on the applications list payload, which
   * is lean on purpose — it is fetched for every filing the applicant has.
   */
  const load = useCallback(async () => {
    try {
      const [rows, forms] = await Promise.all([
        clearances.list(applicationId),
        officeForms.list(applicationId),
      ])
      const row = rows.data.find((r) => r.permit_type.code === code)
      const form = forms.find((f) => f.permit_type_code === code)

      setTarget(row?.return_target ?? '')
      setNotes(row?.return_notes ?? null)
      setRequirements(form?.requirements ?? [])
      setData((form?.form_data ?? {}) as Record<string, unknown>)
      setError(null)
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setLoading(false)
    }
  }, [applicationId, code])

  useEffect(() => {
    void load()
  }, [load])

  async function changeRequirement(documentCode: string, file: File | null, documentId?: number) {
    setBusyCode(documentCode)
    setError(null)
    try {
      if (file !== null) {
        /* The form's own rule, run before the request rather than after it. */
        const rejection = fileRejection(file)
        if (rejection) {
          setError(rejection)

          return
        }
      }
      const result =
        file !== null
          ? await officeForms.uploadRequirement(applicationId, code, documentCode, file)
          : await officeForms.removeRequirement(applicationId, code, documentCode, documentId)
      setRequirements(result.requirements)
    } catch (err) {
      setError(file !== null ? uploadErrorMessage(err) : toApiError(err).message)
    } finally {
      setBusyCode((c) => (c === documentCode ? null : c))
    }
  }

  async function submit() {
    setSubmitting(true)
    setError(null)
    try {
      /*
       * `submit: true` — the same call the office sheet's own button makes,
       * so a correction sent from here and one sent from the form are one act
       * with one server path. The answers go with it because a returned
       * ANSWER is corrected in this dialog and there is no autosave behind it
       * here, unlike on the sheet.
       */
      await officeForms.save(applicationId, code, data, true)
      onDone()
      onClose()
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return null

  /*
   * Nothing to answer. A link followed twice, or a second tab that already
   * resubmitted — either way the applicant should not be shown an empty
   * dialog about a return that is settled.
   */
  const targets = target
    .split(',')
    .map((t) => t.trim())
    .filter((t) => t !== '')
  if (targets.length === 0 || !hasOfficeForm(code)) {
    if (error === null) {
      onClose()

      return null
    }

    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div className="w-full max-w-md rounded-md bg-white p-6 shadow-overlay">
          <Alert variant="error" title="This could not be opened">
            {error}
          </Alert>
          <button
            type="button"
            onClick={onClose}
            className="mt-4 text-sm font-semibold text-royal underline underline-offset-2"
          >
            Close
          </button>
        </div>
      </div>
    )
  }

  return (
    <WhatToCorrect
      code={code as OfficeFormCode}
      targets={targets}
      notes={notes}
      requirements={requirements}
      data={data}
      set={(key, value) => setData((d) => ({ ...d, [key]: value }))}
      onRequirementChange={changeRequirement}
      requirementBusy={busyCode}
      onSubmit={() => void submit()}
      onClose={onClose}
      submitting={submitting}
      blocked={null}
      error={error}
    />
  )
}
