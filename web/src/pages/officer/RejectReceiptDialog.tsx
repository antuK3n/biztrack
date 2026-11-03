import { useId, useState } from 'react'
import { ProofDetails } from '../../components/CounterProof'
import { Alert } from '../../components/ui/Alert'
import { FieldLabel, ProtoModal } from '../../components/ui/Proto'
import { SkeletonList } from '../../components/ui/primitives'
import { refusalSentences } from '../../lib/counterProof'
import { paymentProofs } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'

/*
 * "Reject receipt", beside Mark as paid on a Pending Payment card (Ken, 3
 * November 2026).
 *
 * The officer holding the filing judges the owner's counter receipt: marks the
 * bill paid against it, or rejects it with a reason the owner reads in the
 * bell, by e-mail and on the Pay page, and sends a new one. A reason is
 * required (the server says so in its own sentence), so the dialog asks for
 * one and shows the receipt being judged above it — the officer should see
 * what they are rejecting, not only its OR number on the card.
 *
 * Built the way MarkPaidDialog is: the app's confirm dialog, the receipt read
 * fresh rather than taken from the card, and a refusal read inside the dialog,
 * which stays open. Red, because rejecting is the action that undoes what the
 * owner did (DESIGN.md, Red Means Stop).
 *
 * The receipt rejected is the one shown. When none is waiting any more — the
 * owner replaced it, or it was settled, since the list was read — the card's
 * receipt is sent and the server answers why it cannot be rejected.
 */
export function RejectReceiptDialog({
  applicationId,
  proofId,
  trackingId,
  name,
  onCancel,
  onRejected,
}: {
  applicationId: number
  /** The receipt the card named, used only when the fresh read finds none. */
  proofId: number
  trackingId: string
  name: string
  onCancel: () => void
  onRejected: () => void
}) {
  const { data: proof, loading } = useAsync(() => paymentProofs.pending(applicationId), [applicationId])
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<string[]>([])
  const reasonId = useId()

  async function confirm() {
    if (saving) return
    setSaving(true)
    setErrors([])
    try {
      await paymentProofs.reject(proof?.id ?? proofId, reason)
      onRejected()
    } catch (err) {
      setErrors(refusalSentences(err))
      setSaving(false)
    }
  }

  return (
    <ProtoModal
      title={`Reject the receipt for ${trackingId} (${name})?`}
      tone="red"
      cancelLabel="Cancel"
      confirmLabel="Reject receipt"
      onCancel={onCancel}
      onConfirm={() => void confirm()}
      confirmDisabled={saving || loading}
      wide
    >
      {loading ? (
        <SkeletonList rows={2} />
      ) : (
        proof && (
          <div className="rounded-lg border border-line bg-canvas/40 px-4 py-3.5">
            <ProofDetails proof={proof} />
          </div>
        )
      )}

      <label className="mt-5 block" htmlFor={reasonId}>
        <FieldLabel required>Reason</FieldLabel>
        <textarea
          id={reasonId}
          rows={3}
          maxLength={500}
          aria-required="true"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          className="min-h-24 w-full rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
        />
      </label>

      {errors.length > 0 && (
        <div className="mt-4">
          <Alert variant="error">
            {errors.map((sentence) => (
              <span key={sentence} className="block">
                {sentence}
              </span>
            ))}
          </Alert>
        </div>
      )}
    </ProtoModal>
  )
}
