import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeftIcon, CheckCircleFilledIcon } from '../../components/icons'
import { Alert } from '../../components/ui/Alert'
import { TaxOrderBreakdown } from '../../components/TaxOrderBreakdown'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { PillButton, ProtoCard, StatusCard } from '../../components/ui/Proto'
import { formatDateTime, formatMoney, paymentMethodLabel } from '../../lib/format'
import { toApiError } from '../../lib/api'
import { applications, payments } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { FeeAssessment, Payment, PaymentMethod } from '../../lib/types'

/*
 * Pay page (PDF p51): the white "Tax Order of Payment" card — serif
 * Reference No / Description / Charge / Total Amount — light-blue method
 * chips, and a royal "Pay Online" pill. Success flips to the green Paid state.
 */

const METHODS: { value: PaymentMethod; label: string }[] = [
  { value: 'gcash', label: 'GCash' },
  { value: 'maya', label: 'Maya' },
  { value: 'card', label: 'Card' },
]

export function PayPage() {
  const { id = '' } = useParams()
  const appId = Number(id)
  const navigate = useNavigate()

  const { data: app, loading: appLoading, error: appError, reload } = useAsync(
    () => applications.get(appId),
    [appId],
  )
  const { data: fee } = useAsync<FeeAssessment>(() => payments.fee(appId), [appId])

  const [method, setMethod] = useState<PaymentMethod>('gcash')
  const [paying, setPaying] = useState(false)
  const [payError, setPayError] = useState<string | null>(null)
  const [receipt, setReceipt] = useState<Payment | null>(null)

  async function pay() {
    setPaying(true)
    setPayError(null)
    try {
      const result = await payments.pay(appId, method)
      setReceipt(result)
    } catch (err) {
      setPayError(toApiError(err).message)
    } finally {
      setPaying(false)
    }
  }

  if (appLoading) {
    return (
      <div className="mx-auto max-w-2xl space-y-4">
        <Skeleton className="h-5 w-32" />
        <Skeleton className="h-64 w-full rounded-lg" />
      </div>
    )
  }
  if (appError || !app) return <ErrorState error={appError ?? new Error('Not found')} onRetry={reload} />

  const assessment = fee ?? app.fee_assessment

  /* ── Paid state (green, receipt) ──────────────────────────────────────── */
  if (receipt) {
    return (
      <div className="mx-auto max-w-2xl">
        <h2 className="display-serif mb-5 text-center text-3xl text-ink">Payment Status</h2>
        <StatusCard tone="green">
          <div className="flex items-center gap-4 py-1 text-ink">
            <CheckCircleFilledIcon size={44} className="text-s-green" />
            <span className="text-4xl font-medium">Paid</span>
          </div>
          <div className="mt-5 w-full max-w-md space-y-2 text-sm text-ink">
            <p className="flex justify-between">
              <span className="text-ink-muted">Amount paid</span>
              <span className="tnum font-semibold">{formatMoney(receipt.amount)}</span>
            </p>
            <p className="flex justify-between">
              <span className="text-ink-muted">Method</span>
              <span>{paymentMethodLabel(receipt.method)}</span>
            </p>
            <p className="flex justify-between">
              <span className="text-ink-muted">Reference no.</span>
              <span className="tnum">{receipt.reference_number}</span>
            </p>
            <p className="flex justify-between">
              <span className="text-ink-muted">Paid on</span>
              <span>{formatDateTime(receipt.paid_at)}</span>
            </p>
            <p className="flex justify-between">
              <span className="text-ink-muted">Application</span>
              <span className="tnum">{app.tracking_id}</span>
            </p>
          </div>
        </StatusCard>
        {/*
          * This screen is the exact instant the six LGU clearances unlock —
          * ClearanceService::isUnlocked turns on the first cleared payment —
          * and until now it said nothing about them and offered no way there.
          *
          * A tester reported the other permits "missing". They are not: they
          * are one link on the application detail page, below the fold, and
          * nothing anywhere announces the moment they become available. Telling
          * someone what just became possible, at the moment it becomes
          * possible, is cheaper than another place to go looking.
          *
          * Leading the button row rather than trailing it, because it is now
          * the most useful thing on the screen; "Back to application" was only
          * ever a way out. The sentence above it carries the meaning in text so
          * the button is not the only thing saying what changed.
          */}
        {/*
          "Apply for the ones your business needs — each adds its own fee" was
          two wrong claims in one line. Five of the clearances are required, so
          choosing among them is not on offer; and this payment already covered
          all of them, so none of them adds a fee. What is released and when
          also changed: the gate is five approvals, not a zero balance.
        */}
        <p className="mt-6 text-center text-sm text-ink-secondary">
          Your five LGU Clearances are now open, and this payment already covered them. Apply for
          each one, or hand in a copy if you already hold it — your Business Permit is released
          once all five are approved.
        </p>
        <div className="mt-4 flex flex-wrap justify-center gap-3">
          <PillButton onClick={() => navigate(`/applications/${appId}/clearances`)}>
            Apply for LGU Clearances
          </PillButton>
          <PillButton
            className="border-2 border-royal bg-white !text-royal hover:bg-royal-tint"
            onClick={() => navigate(`/applications/${appId}`)}
          >
            Back to application
          </PillButton>
          <PillButton
            className="border-2 border-royal bg-white !text-royal hover:bg-royal-tint"
            onClick={() => navigate('/payments')}
          >
            Payment History
          </PillButton>
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-2xl">
      <Link
        to={`/applications/${appId}`}
        className="mb-5 inline-flex items-center gap-1.5 text-sm font-semibold text-royal hover:underline"
      >
        <ArrowLeftIcon size={16} /> Back to application
      </Link>

      {payError && (
        <div className="mb-4">
          <Alert variant="error">{payError}</Alert>
        </div>
      )}

      {/* ── Tax Order of Payment card (p51) ────────────────────────────── */}
      <ProtoCard className="px-8 py-7 sm:px-10">
        <h1 className="text-xl font-bold text-ink">Tax Order of Payment</h1>
        <p className="display-serif mt-4 text-lg text-ink">
          Reference No: <span className="ml-3">{app.tracking_id}</span>
        </p>
        <div className="display-serif mt-6 flex items-baseline justify-between border-b border-ink/40 pb-2 text-lg text-ink">
          <span>Description</span>
          <span>Charge</span>
        </div>
        <div className="mt-3">
          <TaxOrderBreakdown fee={assessment} />
        </div>
        <div className="display-serif mt-6 flex items-baseline justify-between border-t border-ink/40 pt-4 text-2xl text-ink">
          <span>Total Amount:</span>
          {/* Nothing assessed is not nothing owed — say which one it is. */}
          {assessment ? (
            <span className="tnum">{formatMoney(assessment.total_amount)}</span>
          ) : (
            <span className="text-base text-ink-muted">Not assessed yet</span>
          )}
        </div>
      </ProtoCard>

      {/* ── Method chips ───────────────────────────────────────────────── */}
      <fieldset className="mt-6">
        <legend className="mb-2.5 text-sm font-bold text-ink">Choose how to pay</legend>
        <div className="flex flex-wrap gap-3">
          {METHODS.map((m) => {
            const selected = method === m.value
            return (
              <button
                key={m.value}
                type="button"
                aria-pressed={selected}
                onClick={() => setMethod(m.value)}
                className={`rounded-full border px-6 py-2 text-sm font-semibold transition-colors ${
                  selected
                    ? 'border-royal bg-royal text-white'
                    : 'border-input-border bg-input text-ink hover:brightness-95'
                }`}
              >
                {m.label}
              </button>
            )
          })}
        </div>
      </fieldset>

      <div className="mt-7">
        <PillButton onClick={pay} disabled={paying || !assessment} className="w-full py-3 text-base">
          {paying ? 'Processing…' : 'Pay Online'}
        </PillButton>
        <p className="mt-2.5 text-center text-xs text-ink-muted">
          This is a simulated payment. No real charge is made.
        </p>
      </div>
    </div>
  )
}
