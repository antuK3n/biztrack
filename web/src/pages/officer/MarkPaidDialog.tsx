import { useEffect, useState } from 'react'
import { OrFields, ProofDetails, type OrFieldValues } from '../../components/CounterProof'
import { Alert } from '../../components/ui/Alert'
import { ProtoModal } from '../../components/ui/Proto'
import { SkeletonList } from '../../components/ui/primitives'
import { refusalSentences } from '../../lib/counterProof'
import { paymentProofs, payments } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'

/*
 * "Mark as paid", asked as a confirmation that also takes the Official Receipt
 * (thesis panel revisions, matrix items 1, 5 and 6).
 *
 * It asked one question until then — "no need to enter anything" [Ken, 4
 * October 2026] — and the panel asked for a record instead: the OR number and
 * the date paid, both required, and a photo of the receipt if the officer has
 * one. When the owner has sent proof from their Pay page it is shown first,
 * under "Proof of payment", and the two fields start from it, so at the
 * counter the officer checks the paper against what is on screen rather than
 * typing it again.
 *
 * Only the officer holding the filing reaches this (the row offers it to them
 * alone, and the server refuses everyone else). The dialog stays open while
 * the payment is recorded, so a refusal — a missing date, a date in the
 * future — is read beside the fields it is about.
 */
export function MarkPaidDialog({
  applicationId,
  trackingId,
  name,
  onCancel,
  onPaid,
}: {
  applicationId: number
  trackingId: string
  name: string
  onCancel: () => void
  onPaid: () => void
}) {
  const { data: proof, loading } = useAsync(() => paymentProofs.pending(applicationId), [applicationId])
  const [values, setValues] = useState<OrFieldValues>({ orNumber: '', orDate: '', photo: null })
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<string[]>([])

  /*
   * Pre-filled from the owner's proof once it arrives, and only into fields
   * the officer has not started on — a late answer must not overwrite what
   * they typed.
   */
  useEffect(() => {
    if (!proof) return
    setValues((v) => ({
      ...v,
      orNumber: v.orNumber || proof.or_number || '',
      orDate: v.orDate || proof.or_date || '',
    }))
  }, [proof])

  async function confirm() {
    if (saving) return
    setSaving(true)
    setErrors([])
    try {
      await payments.markPaidAtCounter(applicationId, {
        or_number: values.orNumber.trim(),
        or_date: values.orDate,
        photo: values.photo,
      })
      onPaid()
    } catch (err) {
      setErrors(refusalSentences(err))
      setSaving(false)
    }
  }

  return (
    <ProtoModal
      title={`Mark ${trackingId} (${name}) as paid at the counter?`}
      confirmLabel="Mark as paid"
      onCancel={onCancel}
      onConfirm={() => void confirm()}
      confirmDisabled={saving || loading}
      wide
    >
      <p className="text-sm text-ink-secondary">
        The owner’s Business Permit is released and their clearances open, the same as an online
        payment.
      </p>

      {loading ? (
        <div className="mt-5">
          <SkeletonList rows={2} />
        </div>
      ) : (
        <>
          {proof && (
            <div className="mt-5 rounded-lg border border-line bg-canvas/40 px-4 py-3.5">
              <ProofDetails proof={proof} heading="Proof of payment" />
            </div>
          )}
          <div className="mt-5">
            <OrFields values={values} onChange={setValues} required />
          </div>
        </>
      )}

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
