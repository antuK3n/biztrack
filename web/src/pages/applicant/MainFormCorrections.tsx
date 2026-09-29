import { useState } from 'react'
import { CorrectionModal } from '../../components/CorrectionModal'
import { toApiError } from '../../lib/api'
import { Link } from 'react-router-dom'
import {
  registrationNumberHint,
  registrationNumberLabel,
  scalarFieldRule,
} from '../../lib/fieldRules'
import { applications, documents } from '../../lib/resources'
import { mainFormTargets, targetCodes } from '../../lib/returnTargets'
import type { Application } from '../../lib/types'
import { ACCEPT_ATTR, MAX_UPLOAD_BYTES, fileRejection, uploadErrorMessage } from './uploads'

/*
 * What BPLO sent back, and the controls to answer it.
 *
 * ── Why this is its own component ──────────────────────────────────────────
 *
 * It lived inside ApplicationDetailPage, so a returned MAIN FORM could only
 * be answered by opening that page. The client, 30 September 2026, having
 * just had the clearance version open in place: *"this page (the one behind
 * the modal) would be redundant since both can handle resubmissions... just
 * have the modal shown on the tracking page when Resubmit button was clicked
 * there."*
 *
 * So the machinery moved out and both callers use it: the tracking page opens
 * it over the list, and the filing's own page still opens it where it always
 * did. One implementation, so the two cannot drift — the lesson this screen
 * has already taught twice today.
 *
 * ── The page itself is NOT redundant ───────────────────────────────────────
 *
 * Only its correction role was. `/applications/:id` also carries the status
 * card, the Tax Order of Payment, the office visits, the LGU clearances, the
 * Remarks and the timeline, none of which is a resubmission. Deleting the
 * page to save a navigation would take out six working things to fix one.
 */

/**
 * One correction box, carrying its own field's rule.
 *
 * The rule comes from `lib/fieldRules`, which the WIZARD imports too — so a
 * TIN is checked here exactly as it is checked on the form that first asked
 * for it, because it is the same function and not a second copy of it.
 * Client, 28 September 2026: *"this should carry the validation rules from
 * their application fields as well. Ensure consistency."*
 *
 * The message waits for `touched`. A field that says "enter a valid TIN" on
 * the first keystroke is telling somebody they are wrong for having started.
 */
function CorrectionInput({
  code,
  label,
  value,
  touched,
  onChange,
  onBlur,
}: {
  code: string
  label: string
  value: string
  touched: boolean
  onChange: (value: string) => void
  onBlur: () => void
}) {
  const rule = scalarFieldRule(code)
  const error = touched ? rule.validate(value) : undefined
  const errorId = `correction-error-${code.replace(/[^a-z0-9]/gi, '-')}`

  /*
   * A field the form asks as a CHOICE gets a choice here. Citizenship is
   * the case: free text would let a correction put back the very spelling
   * spread the select exists to prevent, on a field the city's register
   * counts.
   *
   * Picking "Other" clears the box rather than storing the word "Other",
   * so the applicant types the nationality itself — the same two-step the
   * form uses.
   */
  const known = rule.choices?.some((c) => c.value === value) ?? false
  if (rule.choices) {
    return (
      <>
        <select
          value={known ? value : value === '' ? '' : 'Other'}
          onChange={(e) => onChange(e.target.value === 'Other' ? '' : e.target.value)}
          onBlur={onBlur}
          aria-label={label}
          className="w-full rounded-lg border border-input-border bg-input px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
        >
          <option value="">Select</option>
          {rule.choices.map((c) => (
            <option key={c.value} value={c.value}>
              {c.label}
            </option>
          ))}
        </select>
        {/* Only for the rare answer, so the common one stays one click. */}
        {!known && (
          <input
            value={value}
            onChange={(e) => onChange(e.target.value)}
            onBlur={onBlur}
            placeholder="Which nationality"
            maxLength={rule.maxLength}
            aria-label={`${label} — other`}
            className="mt-2 w-full rounded-lg border border-input-border bg-input px-3 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
          />
        )}
        {error && (
          <p id={errorId} role="alert" className="mt-1 text-xs font-medium text-s-red">
            {error}
          </p>
        )}
      </>
    )
  }

  return (
    <>
      <input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onBlur={onBlur}
        /* Mirrors the wizard's own input, so the phone keypad matches. */
        inputMode={rule.inputMode}
        maxLength={rule.maxLength}
        aria-label={label}
        aria-invalid={error !== undefined}
        aria-describedby={error ? errorId : undefined}
        className={`w-full rounded-lg border bg-input px-3 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 ${
          error ? 'border-s-red focus:ring-s-red' : 'border-input-border focus:ring-royal'
        }`}
      />
      {error && (
        <p id={errorId} role="alert" className="mt-1 text-xs font-medium text-s-red">
          {error}
        </p>
      )}
    </>
  )
}
/**
 * Re-upload one document BPLO sent the filing back about.
 *
 * ── The same box Section C gave them ────────────────────────────────────────
 *
 * `fileRejection`, `ACCEPT_ATTR` and `MAX_UPLOAD_BYTES` are imported from
 * `./uploads`, which is the module Section C's own uploader uses. Client,
 * 29 September 2026: *"the documentary requirement fields in the application
 * forms should have the same allowable file size with their resubmission
 * field counterparts."* They do, because it is the same constant and the same
 * check — not a matching limit retyped here, which would agree today and
 * drift the first time one of them moved.
 *
 * ── It uploads on choose, and does not wait for the resubmit ────────────────
 *
 * `documents.upload` APPENDS a new file against the document type rather than
 * replacing the old one, which is what the officer wants: the previous copy is
 * the evidence of what was refused, and the newest is what they will read.
 * Uploading immediately also means a large file's progress is not hidden
 * behind a Submit that appears to hang.
 */
function DocumentCorrection({
  applicationId,
  documentType,
  note,
  onUploaded,
}: {
  applicationId: number
  documentType: { id: number; code: string; name: string }
  note: string | null
  /** Names the document, so the card can tell which returns are answered. */
  onUploaded: (code: string) => void
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [done, setDone] = useState<string | null>(null)

  async function choose(file: File | null) {
    if (!file) return

    /* The form's own rule, run before the request rather than after it. */
    const rejection = fileRejection(file)
    if (rejection) {
      setError(rejection)

      return
    }

    setBusy(true)
    setError(null)
    try {
      await documents.upload(applicationId, documentType.id, file)
      setDone(file.name)
      onUploaded(documentType.code)
    } catch (err) {
      setError(uploadErrorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <p className="text-[13px] font-semibold text-ink">
        {documentType.name}{' '}
        <span aria-hidden="true" className="text-s-red">
          *
        </span>
        <span className="sr-only">(required)</span>
      </p>
      {/*
        What the officer actually said, given the weight it earns. This
        was 12px grey — lighter than the file-size hint below it — and
        it is the only line telling the applicant what was WRONG with
        the copy they sent, as opposed to which document is wanted.
      */}
      {note && (
        <p className="mt-1.5 rounded-md border-l-4 border-s-rose bg-s-rose-tint/40 px-3 py-2 text-sm italic text-ink">
          “{note}”
        </p>
      )}
      <input
        type="file"
        accept={ACCEPT_ATTR}
        disabled={busy}
        onChange={(e) => void choose(e.target.files?.[0] ?? null)}
        aria-label={`Re-upload ${documentType.name}`}
        className="mt-1.5 block w-full text-sm text-ink file:mr-3 file:cursor-pointer file:rounded-md file:border file:border-royal/30 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-royal hover:file:bg-royal-tint"
      />
      {/* The limit said out loud, in the same words the form uses. */}
      <p className="mt-1 text-xs text-ink-muted">
        PDF, JPG or PNG, up to {Math.round(MAX_UPLOAD_BYTES / (1024 * 1024))} MB.
      </p>
      {busy && <p className="mt-1 text-xs text-ink-secondary">Uploading…</p>}
      {done && !busy && (
        <p className="mt-1 text-xs font-medium text-s-green">Uploaded {done}.</p>
      )}
      {error && (
        <p role="alert" className="mt-1 text-xs font-medium text-s-red">
          {error}
        </p>
      )}
    </div>
  )
}

/**
 * The correction dialog for a returned main form.
 *
 * Takes the DETAIL payload: the assignments carry BPLO's pointer and the
 * documents carry what was uploaded, neither of which is on the list.
 */
export function MainFormCorrections({
  app,
  onClose,
  onDone,
}: {
  app: Application
  onClose: () => void
  /** Resubmitted, so the caller can re-read the filing behind the dialog. */
  onDone: () => void
}) {
  const status = app.status
  const [corrections, setCorrections] = useState<Record<string, string>>({})
  const [touchedCorrections, setTouchedCorrections] = useState<Record<string, boolean>>({})
  const [uploadedDocs, setUploadedDocs] = useState<string[]>([])
  const [savingCorrections, setSavingCorrections] = useState(false)
  const [correctionError, setCorrectionError] = useState<string | null>(null)

  /*
   * ── The fields BPLO ticked when it returned this filing ──────────────────
   *
   * Read off the assignments rather than held in their own key: the pointer
   * has always lived on `remarks_target`, and one column that every reader
   * parses the same way is what stops the officer's tick and the applicant's
   * boxes drifting apart.
   *
   * Only while the filing is RETURNED. The pointer survives the resubmission —
   * `returnMainForm` replaces it rather than clearing it, so the officer can
   * still see what the last round was about — and drawing correction boxes for
   * a filing already back with BPLO would invite an edit that cannot be sent.
   */
  const returnedFields =
    status === 'returned'
      ? app.assignments.flatMap((a) => mainFormTargets(a.remarks_target))
      : []
  /*
   * ── Two fields a sole proprietor does not type ────────────────────────
   *
   * The form derives and locks item 15 from the proprietor's own name and
   * item 17 from their citizenship, and the corrections endpoint re-derives
   * both on write. Offering a box here would take an answer, send it, and
   * silently replace it — worse than not offering one, because the
   * applicant would believe they had answered.
   *
   * Moved to the SECTION list instead, where they are corrected on the form
   * beside the field that actually drives them. Only for a sole
   * proprietorship: a corporation's president and pooled capital are real
   * answers nobody else knows.
   */
  const DERIVED_FOR_SOLE_PROPRIETOR = ['form:president_officer_name', 'form:capital_participation']
  const derivedHere =
    app.business?.registration_type === 'sole_proprietorship'
      ? DERIVED_FOR_SOLE_PROPRIETOR
      : []

  const returnedScalars = returnedFields.filter(
    (t) => t.kind === 'scalar' && !derivedHere.includes(t.value),
  )
  const returnedSections = returnedFields.filter(
    (t) => t.kind === 'section' || derivedHere.includes(t.value),
  )

  /*
   * ── Documents BPLO sent back ──────────────────────────────────────────
   *
   * A document target is a bare `document_types.code`, which
   * `mainFormTargets` drops — it resolves the wizard's `form:` codes and
   * nothing else. Matched against what the applicant UPLOADED, which is
   * also the only list the officer could have picked from.
   */
  const returnedDocuments =
    status === 'returned'
      ? (() => {
          const named = new Set(
            app.assignments.flatMap((a) => targetCodes(a.remarks_target)),
          )

          return [
            ...new Map(
              app.documents
                .filter((d) => named.has(d.document_type.code))
                .map((d) => [d.document_type.code, d.document_type]),
            ).values(),
          ]
        })()
      : []

  /*
   * Every field BPLO named, answered and valid.
   *
   * Gates the Submit button AND hides the bare Resubmit further down, so
   * there is no route back to BPLO that skips the corrections. `validate`
   * is the field's own rule from `lib/fieldRules` — the same one the
   * application form applies — so "filled in" means filled in ACCEPTABLY,
   * not merely non-empty.
   */
  const correctionsComplete =
    returnedScalars.every(
      (t) =>
        (corrections[t.value] ?? '').trim() !== ''
        && scalarFieldRule(t.value).validate(corrections[t.value] ?? '') === undefined,
    )
    && returnedDocuments.every((dt) => uploadedDocs.includes(dt.code))

  async function submitCorrections() {
    setCorrectionError(null)

    /*
     * Every ticked field has to carry something. The API would accept a blank
     * — a cleared trade name is a real correction for some fields — but an
     * UNTOUCHED box is far more likely to be an oversight than an intention,
     * and resubmitting on an oversight costs the applicant another round trip.
     */
    const missing = returnedScalars.filter((t) => (corrections[t.value] ?? '').trim() === '')
    if (missing.length > 0) {
      setCorrectionError(
        `Answer every field BPLO asked about: ${missing.map((t) => t.label).join(', ')}.`,
      )

      return
    }

    /*
     * And every answer has to satisfy its own field's rule — the same rule
     * the wizard applies, from `lib/fieldRules`. Re-run over ALL of them
     * rather than trusting the live messages: a box nobody touched shows no
     * message and can still be wrong.
     */
    const firstBad = returnedScalars
      .map((t) => ({ t, error: scalarFieldRule(t.value).validate(corrections[t.value] ?? '') }))
      .find((r) => r.error !== undefined)
    if (firstBad) {
      setTouchedCorrections((prev) => ({ ...prev, [firstBad.t.value]: true }))
      setCorrectionError(`${firstBad.t.label}: ${firstBad.error}`)

      return
    }

    setSavingCorrections(true)
    try {
      /*
       * `corrections` writes the scalars and resubmits in one transaction,
       * but refuses a filing that was returned about no single field. A
       * document-only return has none — its uploads were saved as they were
       * chosen — so that filing resubmits directly.
       */
      if (returnedScalars.length > 0) {
        const fields: Record<string, string> = {}
        for (const t of returnedScalars) fields[t.value] = (corrections[t.value] ?? '').trim()
        await applications.corrections(app.id, fields)
      } else {
        await applications.resubmit(app.id)
      }
      // Correcting resubmits, so the whole page changes state — reload rather
      // than patching, which would leave the status card stale.
      onDone()
      onClose()
    } catch (err) {
      setCorrectionError(toApiError(err).message)
    } finally {
      setSavingCorrections(false)
    }
  }

  /*
   * Nothing to answer — a filing no longer returned, or one returned about
   * something this dialog has no control for. Rendering an empty dialog
   * would be worse than not opening.
   */
  if (returnedFields.length === 0 && returnedDocuments.length === 0) return null

  return (
          <CorrectionModal
            office="BPLO"
            count={returnedFields.length + returnedDocuments.length}
            onSubmit={() => void submitCorrections()}
            onClose={onClose}
            submitting={savingCorrections}
            blocked={correctionsComplete ? null : 'Answer everything above first.'}
            error={correctionError}
          >
            <div>
              {returnedScalars.length > 0 && (
                <div className="mt-4 space-y-4">
                  {returnedScalars.map((t) => (
                    <label key={t.value} className="block">
                      <span className="block text-[13px] font-semibold text-ink">
                        {/*
                          The registration number is named for the AGENCY that
                          issued it, exactly as the form names it — a
                          cooperative is asked for its CDA number, not for a
                          generic one. Every other field keeps the picker's
                          own label, which already matches the form.
                        */}
                        {t.value === 'form:registration_number'
                          ? `2. ${registrationNumberLabel(app.business?.registration_type)}`
                          : t.label}{' '}
                        {/*
                          The same marker the application form uses on a
                          required question. These are the strongest
                          requirement in the system — an office has asked for
                          them by name — and carried nothing until now.

                          The glyph is decoration and the word is the signal,
                          which is why the screen-reader text is spelled out
                          rather than left as a bare asterisk.
                        */}
                        <span aria-hidden="true" className="text-s-red">
                          *
                        </span>
                        <span className="sr-only">(required)</span>
                      </span>
                      {/*
                        What BPLO said about THIS field, between its name and
                        its box.

                        Client, 27 September 2026: *"Allow to put 1
                        comment/remark per field selected, not just 1 remark
                        for all fields."* With one remark covering three
                        fields the applicant had to work out which clause
                        belonged to which box — the pointer answered "which
                        field" and the prose put the matching straight back.

                        Absent for a filing returned with plain prose, where
                        the whole remark is in the Remarks section below.
                      */}
                      {(app.return_notes ?? {})[t.value] && (
                        <span className="mb-1.5 mt-1.5 block rounded-md border-l-4 border-s-rose bg-s-rose-tint/40 px-3 py-2 text-sm italic text-ink">
                          “{(app.return_notes ?? {})[t.value]}”
                        </span>
                      )}
                      {/*
                        Which certificate to copy it from — the form's own
                        hint, shown only where there is one to give.
                      */}
                      {t.value === 'form:registration_number'
                        && registrationNumberHint(app.business?.registration_type) && (
                        <span className="mb-1.5 block text-xs text-ink-muted">
                          {registrationNumberHint(app.business?.registration_type)}
                        </span>
                      )}
                      {!(app.return_notes ?? {})[t.value] && <span className="mb-1.5 block" />}
                      <CorrectionInput
                        code={t.value}
                        label={t.label}
                        value={corrections[t.value] ?? ''}
                        touched={Boolean(touchedCorrections[t.value])}
                        onChange={(v) =>
                          setCorrections((prev) => ({ ...prev, [t.value]: v }))
                        }
                        onBlur={() =>
                          setTouchedCorrections((prev) => ({ ...prev, [t.value]: true }))
                        }
                      />
                    </label>
                  ))}
                </div>
              )}

              {/*
                A returned DOCUMENT gets the same upload box Section C gave
                it — same accepted formats and same size limit, from the
                same module, so a change to the limit moves both.
              */}
              {returnedDocuments.length > 0 && (
                <div className="mt-4 space-y-4">
                  {returnedDocuments.map((dt) => (
                    <DocumentCorrection
                      key={dt.code}
                      applicationId={app.id}
                      documentType={dt}
                      note={(app.return_notes ?? {})[dt.code] ?? null}
                      onUploaded={(code) =>
                        setUploadedDocs((prev) =>
                          prev.includes(code) ? prev : [...prev, code],
                        )
                      }
                    />
                  ))}
                </div>
              )}

              {/*
                The section targets. Named rather than silently dropped: an
                applicant told to fix three things and shown two boxes would
                resubmit believing they had finished.
              */}
              {returnedSections.length > 0 && (
                <div className="mt-4 rounded-lg border border-input-border bg-royal-tint px-4 py-3">
                  <p className="text-xs font-semibold text-royal">
                    These are whole sections, so they are corrected on the form itself:
                  </p>
                  {/* Each with its own remark, the same as the boxes above. */}
                  <ul className="mt-1.5 space-y-1">
                    {returnedSections.map((t) => (
                      <li key={t.value} className="text-xs text-ink-secondary">
                        <span className="font-semibold text-ink">{t.label}</span>
                        {(app.return_notes ?? {})[t.value] && (
                          <> — {(app.return_notes ?? {})[t.value]}</>
                        )}
                      </li>
                    ))}
                  </ul>
                  <Link
                    to={`/apply?draft=${app.id}`}
                    className="mt-1.5 inline-block text-xs font-semibold text-royal underline underline-offset-2"
                  >
                    Open the application form
                  </Link>
                </div>
              )}

            </div>
          </CorrectionModal>
  )
}
