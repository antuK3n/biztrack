import { useId, useState } from 'react'
import { Alert } from './ui/Alert'
import { FieldLabel, PillButton, ProtoCard } from './ui/Proto'
import { toApiError } from '../lib/api'
import { refusalSentences, todayIso } from '../lib/counterProof'
import { formatCalendarDate } from '../lib/format'
import { paymentProofs } from '../lib/resources'
import type { PaymentProof } from '../lib/types'

/*
 * The pieces of an over-the-counter payment's proof that two screens share
 * (thesis panel revisions, matrix items 1 and 4):
 *
 *   the owner's Pay page   sends the OR number, the date paid and a photo of
 *                          the receipt after paying at City Hall, and shows
 *                          back what was sent — or, since 3 November 2026,
 *                          that BPLO rejected it and why;
 *   BPLO's Mark as paid    shows the owner's proof and asks the officer for
 *                          the same three things, the OR pre-filled from it.
 *
 * One set of fields and one way of showing a proof, so the two sides of the
 * counter name the same three things the same way.
 */

/** The files a requirement takes (DocumentController::store), as the picker filters them. */
const PHOTO_ACCEPT = '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png'

const INPUT =
  'w-full rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal'

export interface OrFieldValues {
  orNumber: string
  orDate: string
  photo: File | null
}

/**
 * OR number, Date paid and Photo of the receipt.
 *
 * `required` marks the first two for the officer, who must enter both; the
 * owner may send either the pair or the photo. `resetKey` empties the file
 * picker, which React cannot do by value.
 */
export function OrFields({
  values,
  onChange,
  required = false,
  resetKey = 0,
}: {
  values: OrFieldValues
  onChange: (next: OrFieldValues) => void
  required?: boolean
  resetKey?: number
}) {
  const id = useId()

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <label className="block" htmlFor={`${id}-or`}>
        <FieldLabel required={required}>OR number</FieldLabel>
        <input
          id={`${id}-or`}
          type="text"
          inputMode="numeric"
          autoComplete="off"
          maxLength={30}
          aria-required={required || undefined}
          value={values.orNumber}
          onChange={(e) => onChange({ ...values, orNumber: e.target.value })}
          className={`tnum ${INPUT}`}
        />
      </label>
      <label className="block" htmlFor={`${id}-date`}>
        <FieldLabel required={required}>Date paid</FieldLabel>
        <input
          id={`${id}-date`}
          type="date"
          max={todayIso()}
          aria-required={required || undefined}
          value={values.orDate}
          onChange={(e) => onChange({ ...values, orDate: e.target.value })}
          className={INPUT}
        />
      </label>
      <label className="block sm:col-span-2" htmlFor={`${id}-photo`}>
        <FieldLabel>Photo of the receipt</FieldLabel>
        <input
          key={resetKey}
          id={`${id}-photo`}
          type="file"
          accept={PHOTO_ACCEPT}
          onChange={(e) => onChange({ ...values, photo: e.target.files?.[0] ?? null })}
          className="block w-full text-sm text-ink-secondary file:mr-3 file:cursor-pointer file:rounded-full file:border-0 file:bg-royal-tint file:px-4 file:py-1.5 file:text-sm file:font-semibold file:text-royal hover:file:brightness-95"
        />
      </label>
    </div>
  )
}

/**
 * Opening a proof's photo in a tab: the View beside it, in either form below.
 *
 * Opened inside the click, before any await, or the popup blocker takes the
 * tab silently — the rule PaymentsPage works to.
 */
function useViewPhoto(proofId: number) {
  const [opening, setOpening] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function view() {
    if (opening) return
    const tab = window.open('', '_blank')
    setOpening(true)
    setError(null)
    try {
      await paymentProofs.view(proofId, tab)
    } catch (err) {
      tab?.close()
      setError(toApiError(err).message)
    } finally {
      setOpening(false)
    }
  }

  return { view, opening, error }
}

const VIEW_BUTTON =
  'font-semibold text-royal underline underline-offset-2 hover:no-underline aria-disabled:opacity-60'

/**
 * A proof as it was sent: the OR number, the date paid and the photo, with a
 * View that opens the photo. A dash where a part was not sent.
 */
export function ProofDetails({ proof, heading }: { proof: PaymentProof; heading?: string }) {
  const { view, opening, error } = useViewPhoto(proof.id)

  return (
    <div className="space-y-2">
      {heading && <h3 className="text-sm font-bold text-ink">{heading}</h3>}
      <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1.5 text-sm">
        <dt className="text-ink-muted">OR number</dt>
        <dd className="tnum text-ink">{proof.or_number ?? '—'}</dd>
        <dt className="text-ink-muted">Date paid</dt>
        <dd className="text-ink">{proof.or_date ? formatCalendarDate(proof.or_date) : '—'}</dd>
        <dt className="text-ink-muted">Photo of the receipt</dt>
        <dd className="min-w-0 text-ink">
          {proof.has_photo ? (
            <span className="flex min-w-0 flex-wrap items-center gap-x-3">
              <span className="truncate">{proof.photo_name}</span>
              <button
                type="button"
                onClick={() => void view()}
                aria-disabled={opening || undefined}
                className={VIEW_BUTTON}
              >
                View
              </button>
            </span>
          ) : (
            '—'
          )}
        </dd>
      </dl>
      {error && (
        <p role="alert" className="text-xs font-medium text-s-red">
          {error}
        </p>
      )}
    </div>
  )
}

/**
 * The same proof on one line, "Sent: OR 1234567 · October 20, 2026 · View",
 * under the owner's notice that BPLO rejected it (Ken, 3 November 2026): the
 * owner sees which receipt was turned down without a second table of it.
 *
 * Only the parts that were sent. A photo sent without an OR has no number to
 * name it by, so it is named by its file instead.
 */
export function ProofSentLine({ proof }: { proof: PaymentProof }) {
  const { view, opening, error } = useViewPhoto(proof.id)
  const parts = [
    proof.or_number ? `OR ${proof.or_number}` : null,
    proof.or_date ? formatCalendarDate(proof.or_date) : null,
    !proof.or_number && proof.has_photo ? proof.photo_name : null,
  ].filter((part): part is string => Boolean(part))

  return (
    <div>
      <p className="text-sm text-ink-secondary">
        Sent: <span className="tnum">{parts.join(' · ')}</span>
        {proof.has_photo && (
          <>
            {' · '}
            <button
              type="button"
              onClick={() => void view()}
              aria-disabled={opening || undefined}
              className={VIEW_BUTTON}
            >
              View
            </button>
          </>
        )}
      </p>
      {error && (
        <p role="alert" className="mt-1 text-xs font-medium text-s-red">
          {error}
        </p>
      )}
    </div>
  )
}

const EMPTY: OrFieldValues = { orNumber: '', orDate: '', photo: null }

/**
 * The owner's proof, sent from the Pay page after paying at the counter
 * (matrix item 4: "The applicant can now upload a photo of the receipt as
 * proof of payment, or enter the OR number and the date it was paid").
 *
 * The OR number with the date paid, the photo, or both; the server says so
 * when neither is there. Sending marks nothing paid — BPLO does that — so what
 * comes back is the sentence that says who acts next, and what was sent. The
 * form stays below it: until BPLO confirms, the owner may send again and the
 * newest proof replaces the pending one.
 *
 * BPLO may reject the receipt instead, with a reason (Ken, 3 November 2026).
 * Then the box above the form is red and says so, with the reason, and what
 * was sent; a new send replaces it with the green one. Which of the two is
 * drawn is `latest`, the owner's newest proof (`paymentProofs.latest`), read
 * by the Pay page — which also opens on Over the Counter when there is one,
 * so a rejected receipt is not hidden behind an unchosen method.
 */
export function SendProofForm({
  applicationId,
  latest,
  onSent,
}: {
  applicationId: number
  latest: PaymentProof | null
  onSent: (proof: PaymentProof) => void
}) {
  const [values, setValues] = useState<OrFieldValues>(EMPTY)
  const [resetKey, setResetKey] = useState(0)
  const [sending, setSending] = useState(false)
  const [errors, setErrors] = useState<string[]>([])

  async function send() {
    if (sending) return
    setSending(true)
    setErrors([])
    try {
      const proof = await paymentProofs.send(applicationId, {
        or_number: values.orNumber.trim() || undefined,
        or_date: values.orDate || undefined,
        photo: values.photo,
      })
      onSent(proof)
      setValues(EMPTY)
      setResetKey((k) => k + 1)
    } catch (err) {
      setErrors(refusalSentences(err))
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="mt-6 space-y-4">
      {latest &&
        (latest.rejected_at ? (
          <ProtoCard className="space-y-3 px-7 py-5">
            <Alert variant="error">
              <span className="block">BPLO did not accept your receipt: {latest.rejection_reason}</span>
              <span className="block">Send a new one below.</span>
            </Alert>
            <ProofSentLine proof={latest} />
          </ProtoCard>
        ) : (
          <ProtoCard className="space-y-3 px-7 py-5">
            <Alert variant="success">Proof sent. BPLO will confirm your payment.</Alert>
            <ProofDetails proof={latest} />
          </ProtoCard>
        ))}

      <ProtoCard className="space-y-5 px-7 py-6">
        <OrFields values={values} onChange={setValues} resetKey={resetKey} />

        {errors.length > 0 && (
          <Alert variant="error">
            {errors.map((sentence) => (
              <span key={sentence} className="block">
                {sentence}
              </span>
            ))}
          </Alert>
        )}

        {/* aria-disabled, never `disabled` (AGENTS.md §6.2); the press is refused in `send`. */}
        <PillButton
          onClick={() => void send()}
          aria-disabled={sending || undefined}
          className="w-full py-3 text-base aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
        >
          Send proof of payment
        </PillButton>
      </ProtoCard>
    </div>
  )
}
