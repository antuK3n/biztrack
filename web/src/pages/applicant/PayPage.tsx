import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeftIcon, CheckCircleFilledIcon, ClockIcon, XCircleIcon } from '../../components/icons'
import { Alert } from '../../components/ui/Alert'
import { TaxOrderBreakdown } from '../../components/TaxOrderBreakdown'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { PillButton, ProtoCard, ProtoModal, StatusCard } from '../../components/ui/Proto'
import { formatDateTime, formatMoney, paymentMethodLabel } from '../../lib/format'
import { toApiError } from '../../lib/api'
import { applications, payments } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { FeeAssessment, Payment, PaymentMethod, PaymentOptions } from '../../lib/types'

/*
 * Pay page (PDF p51): the white "Tax Order of Payment" card — serif
 * Reference No / Description / Charge / Total Amount — light-blue method
 * chips, and a royal "Pay Online" pill. Success flips to the green Paid state.
 *
 * ── Two ways a payment is made (docs/payment-gateway.md) ─────────────────
 *
 * The super admin's switch decides, and this page asks the server which one is
 * on rather than assuming (`payments.options`):
 *
 *   simulated  Pay → Paid in the same press, exactly as before the switch.
 *   online     Pay → the owner finishes in their GCash / Maya / bank app, by
 *              following a link or scanning a QR code → this page waits, asks
 *              the server every few seconds, and shows Paid once the payment
 *              service has confirmed it. It never shows Paid on its own
 *              say-so: "accepted" is not "paid".
 *
 * The method chips come from the server for the same reason — Card exists only
 * in simulated mode, QR Ph and GoTyme only online.
 *
 * Coming back from the payment app lands here with `?payment=<id>&returned=1`
 * (the gateway's return address). `returned` makes the page ask once straight
 * away instead of waiting for the next poll.
 *
 * ── The test charge ───────────────────────────────────────────────────────
 *
 * While the super admin's charge switch says `test`, the payment service
 * collects ₱1.00 however large the bill (docs/payment-gateway.md). The page
 * says so before the owner pays (`options.test_charge`), so a panelist
 * watching a ₱1 payment settle a ₱2,000 bill sees that it is deliberate. Once
 * a payment is open, the waiting screen shows what that payment asks for
 * (`gateway_amount`), which a later switch does not change.
 */

/** What the payment service was asked to collect, falling back to the bill. */
function collected(payment: Payment): string {
  return payment.gateway_amount ?? payment.amount
}

/** True when this payment collects a test amount rather than its bill. */
function isTestCharge(payment: Payment): boolean {
  return payment.gateway_amount != null && Number(payment.gateway_amount) !== Number(payment.amount)
}

const POLL_MS = 5000

export function PayPage() {
  const { id = '' } = useParams()
  const appId = Number(id)
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()

  const { data: app, loading: appLoading, error: appError, reload } = useAsync(
    () => applications.get(appId),
    [appId],
  )
  const { data: fee } = useAsync<FeeAssessment>(() => payments.fee(appId), [appId])
  const {
    data: options,
    error: optionsError,
    reload: reloadOptions,
  } = useAsync<PaymentOptions>(() => payments.options(appId), [appId])

  const [method, setMethod] = useState<PaymentMethod | null>(null)
  const [paying, setPaying] = useState(false)
  const [payError, setPayError] = useState<string | null>(null)
  /** The payment this screen is showing: none yet, waiting, paid or failed. */
  const [attempt, setAttempt] = useState<Payment | null>(null)
  const [checking, setChecking] = useState(false)
  const [checkedAt, setCheckedAt] = useState<Date | null>(null)
  const [checkNote, setCheckNote] = useState<string | null>(null)
  /** Said on the choose screen after "Pay a different way" set a payment aside. */
  const [setAsideNote, setSetAsideNote] = useState<string | null>(null)
  const [abandoning, setAbandoning] = useState(false)

  /* ── Resume: back from the payment app, or a payment already in flight ── */
  const returnedId = Number(params.get('payment')) || null
  const returned = params.get('returned') === '1'
  const resumed = useRef(false)
  useEffect(() => {
    if (resumed.current) return
    if (returnedId) {
      resumed.current = true
      const load = returned ? payments.check(returnedId) : payments.get(returnedId)
      load
        .then((p) => {
          // One set aside is not the payment being waited on any more.
          if (p.status === 'pending' && p.set_aside) return
          setAttempt(p)
          setCheckedAt(new Date())
        })
        .catch(() => undefined)
      // Asked once; a refresh should not ask again.
      if (returned) setParams({ payment: String(returnedId) }, { replace: true })
      return
    }
    if (options) {
      resumed.current = true
      if (options.in_progress) setAttempt(options.in_progress)
    }
  }, [returnedId, returned, options, setParams])

  /* ── Waiting: poll our own record until the gateway has decided ────────── */
  const waitingId = attempt?.status === 'pending' ? attempt.id : null
  useEffect(() => {
    if (!waitingId) return
    const timer = window.setInterval(() => {
      payments
        .get(waitingId)
        .then((p) => {
          setAttempt(p)
          setCheckedAt(new Date())
        })
        .catch(() => undefined)
    }, POLL_MS)
    return () => window.clearInterval(timer)
  }, [waitingId])

  const methods = options?.methods ?? []
  const chosen: PaymentMethod | null = method ?? methods[0]?.value ?? null
  const online = options?.mode === 'kwikpay'

  async function pay() {
    if (paying || !chosen || !(fee ?? app?.fee_assessment)) return
    setPaying(true)
    setPayError(null)
    try {
      const result = await payments.pay(appId, chosen)
      setAttempt(result)
      setCheckedAt(new Date())
      if (result.status === 'pending' && result.pay_url && result.pay_url_kind === 'link') {
        // The payment page is the gateway's; its return address brings the
        // owner back here with the marker above.
        window.location.assign(result.pay_url)
      }
    } catch (err) {
      setPayError(toApiError(err).message)
    } finally {
      setPaying(false)
    }
  }

  async function checkNow() {
    if (!attempt || checking) return
    setChecking(true)
    setCheckNote(null)
    try {
      const p = await payments.check(attempt.id)
      setAttempt(p)
      setCheckedAt(new Date())
      if (p.status === 'pending') setCheckNote('Not confirmed yet. If you have paid, it can take a few minutes.')
    } catch (err) {
      setCheckNote(toApiError(err).message)
    } finally {
      setChecking(false)
    }
  }

  function tryAgain() {
    setAttempt(null)
    setPayError(null)
    setCheckNote(null)
    setSetAsideNote(null)
    setParams({}, { replace: true })
    reloadOptions()
  }

  /*
   * "Pay a different way", after the owner confirmed they have not paid. The
   * server asks the payment service once before letting go of the payment,
   * because an open order may have been paid a moment ago:
   *   completed → it was; show Paid, and there is nothing more to pay
   *   failed    → back to the choice
   *   set aside → back to the choice, saying what happens if it was paid
   */
  async function payDifferently() {
    if (!attempt || abandoning) return
    setAbandoning(true)
    setCheckNote(null)
    try {
      const p = await payments.abandon(attempt.id)
      if (p.status === 'completed') {
        setAttempt(p)
        return
      }
      const note =
        p.status === 'pending'
          ? `Your ${paymentMethodLabel(p.method)} payment (${p.reference_number}) is set aside. If it goes through after all, BPLO will contact you about refunding the extra payment.`
          : null
      tryAgain()
      setSetAsideNote(note)
    } catch (err) {
      setCheckNote(toApiError(err).message)
    } finally {
      setAbandoning(false)
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
  if (attempt?.status === 'completed') {
    const receipt = attempt
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

  /* ── Waiting for the payment service to confirm ───────────────────────── */
  if (attempt?.status === 'pending') {
    return (
      <div className="mx-auto max-w-2xl">
        <h2 className="display-serif mb-5 text-center text-3xl text-ink">Payment Status</h2>
        <WaitingCard
          payment={attempt}
          checking={checking}
          checkedAt={checkedAt}
          checkNote={checkNote}
          onCheck={checkNow}
          abandoning={abandoning}
          onPayDifferently={payDifferently}
        />
        <div className="mt-6 flex justify-center">
          <Link
            to={`/applications/${appId}`}
            className="inline-flex items-center gap-1.5 text-sm font-semibold text-royal hover:underline"
          >
            <ArrowLeftIcon size={16} /> Back to application
          </Link>
        </div>
      </div>
    )
  }

  /* ── The payment service said no ──────────────────────────────────────── */
  if (attempt?.status === 'failed') {
    return (
      <div className="mx-auto max-w-2xl">
        <h2 className="display-serif mb-5 text-center text-3xl text-ink">Payment Status</h2>
        <StatusCard tone="red">
          <div className="flex items-center gap-4 py-1 text-ink">
            <XCircleIcon size={44} className="text-s-red" />
            <span className="text-3xl font-medium">Payment did not go through</span>
          </div>
          <p className="max-w-md text-center text-sm text-ink-secondary">
            Your {paymentMethodLabel(attempt.method)} payment of {formatMoney(collected(attempt))} was
            not completed, so your application is still waiting for payment. You can try again, with
            the same method or another one.
          </p>
          <p className="text-xs text-ink-muted">
            Reference no. <span className="tnum">{attempt.reference_number}</span>
          </p>
          <PillButton onClick={tryAgain} className="mt-2">
            Try again
          </PillButton>
        </StatusCard>
      </div>
    )
  }

  /* ── Choose how to pay ────────────────────────────────────────────────── */
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
      {setAsideNote && !payError && (
        <div className="mb-4">
          <Alert variant="info">{setAsideNote}</Alert>
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

      {/* ── Method chips — the server's list for the current mode ────────── */}
      <fieldset className="mt-6">
        <legend className="mb-2.5 text-sm font-bold text-ink">Choose how to pay</legend>
        {optionsError ? (
          <Alert variant="error">
            The ways to pay could not be loaded.{' '}
            <button type="button" onClick={reloadOptions} className="font-semibold underline">
              Try again
            </button>
          </Alert>
        ) : !options ? (
          <div className="flex gap-3">
            <Skeleton className="h-9 w-24 rounded-full" />
            <Skeleton className="h-9 w-24 rounded-full" />
            <Skeleton className="h-9 w-24 rounded-full" />
          </div>
        ) : (
          <div className="flex flex-wrap gap-3">
            {methods.map((m) => {
              const selected = chosen === m.value
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
                  {/* The short name the rest of the app uses ("Card", as the
                      chip always read), the server's own label as fallback. */}
                  {paymentMethodLabel(m.value) === m.value ? m.label : paymentMethodLabel(m.value)}
                </button>
              )
            })}
          </div>
        )}
      </fieldset>

      {/*
        Before the button, not after it: this is what the owner is about to
        be charged, and it is not the Total Amount on the card above. Info,
        not a warning — nothing is wrong, it is a test.
      */}
      {online && options?.test_charge && (
        <div className="mt-6">
          <Alert variant="info" title="Test charge">
            You will be charged {formatMoney(options.test_charge)} for this bill
            {assessment ? ` instead of ${formatMoney(assessment.total_amount)}` : ''}. The bill and your
            receipt keep the full amount.
          </Alert>
        </div>
      )}

      <div className="mt-7">
        <PillButton
          onClick={pay}
          aria-disabled={paying || !assessment || !chosen}
          className="w-full py-3 text-base aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
        >
          {paying ? 'Processing…' : 'Pay Online'}
        </PillButton>
        {options && (
          <p className="mt-2.5 text-center text-xs text-ink-muted">
            {online
              ? `You will finish paying in your ${chosen === 'qrph' ? 'bank or e-wallet' : paymentMethodLabel(chosen ?? '')} app. Your application moves on once the payment is confirmed.`
              : 'This is a simulated payment. No real charge is made.'}
          </p>
        )}
      </div>
    </div>
  )
}

/*
 * The owner has been sent to pay (or shown a code to scan) and we are waiting
 * for the payment service. Yellow, like every other "in progress" card: it is
 * not an error, and red here would read as money lost.
 */
function WaitingCard({
  payment,
  checking,
  checkedAt,
  checkNote,
  onCheck,
  abandoning,
  onPayDifferently,
}: {
  payment: Payment
  checking: boolean
  checkedAt: Date | null
  checkNote: string | null
  onCheck: () => void
  abandoning: boolean
  onPayDifferently: () => void
}) {
  const [qrBroken, setQrBroken] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const isQr = payment.pay_url_kind === 'qr' && !!payment.pay_url && !qrBroken
  // What the owner's app will ask for, which is the test amount while the
  // test charge was on when this payment was opened — not the bill.
  const amount = formatMoney(collected(payment))

  return (
    <StatusCard tone="yellow">
      <div className="flex items-center gap-3 text-ink">
        <ClockIcon size={36} className="text-s-yellow-ink" />
        <span className="text-3xl font-medium">Waiting for your payment</span>
      </div>

      {isQr ? (
        <>
          <p className="max-w-md text-center text-sm text-ink-secondary">
            Scan this code with your GCash, Maya or bank app and pay{' '}
            <span className="tnum font-semibold text-ink">{amount}</span>. This page updates by itself
            once the payment is confirmed.
          </p>
          <img
            src={payment.pay_url ?? ''}
            alt={`QR code to pay ${amount}`}
            onError={() => setQrBroken(true)}
            className="h-56 w-56 rounded-lg border border-input-border bg-white p-2"
          />
        </>
      ) : (
        <p className="max-w-md text-center text-sm text-ink-secondary">
          Finish paying <span className="tnum font-semibold text-ink">{amount}</span> in your{' '}
          {paymentMethodLabel(payment.method)} app. If you have already paid, this page updates by
          itself once the payment is confirmed — usually within a minute.
        </p>
      )}

      {isTestCharge(payment) && (
        <p className="max-w-md text-center text-xs text-ink-muted">
          Test charge. Your bill of {formatMoney(payment.amount)} is recorded in full.
        </p>
      )}

      <div className="mt-1 w-full max-w-md space-y-2 text-sm text-ink">
        <p className="flex justify-between">
          <span className="text-ink-muted">Method</span>
          <span>{paymentMethodLabel(payment.method)}</span>
        </p>
        <p className="flex justify-between">
          <span className="text-ink-muted">Reference no.</span>
          <span className="tnum">{payment.reference_number}</span>
        </p>
      </div>

      <div className="mt-2 flex flex-wrap justify-center gap-3">
        <PillButton onClick={onCheck} aria-disabled={checking}>
          {checking ? 'Checking…' : 'Check payment status'}
        </PillButton>
        {payment.pay_url && !isQr && (
          <a
            href={payment.pay_url}
            className="inline-flex items-center justify-center rounded-full border-2 border-royal bg-white px-7 py-2 text-sm font-semibold text-royal hover:bg-royal-tint"
          >
            Open the payment page
          </a>
        )}
      </div>

      {/*
        * The way out of a payment the owner cannot or will not finish — a
        * different app, a declined wallet, the wrong method picked. Quiet on
        * purpose (a text button, not red): it is not destructive, and the
        * confirmation is where the one real risk, paying twice, is said.
        */}
      <button
        type="button"
        onClick={() => setConfirming(true)}
        aria-disabled={abandoning || undefined}
        className="text-sm font-semibold text-royal underline underline-offset-2 hover:no-underline aria-disabled:opacity-60"
      >
        {abandoning ? 'Checking your payment…' : 'Pay a different way'}
      </button>

      {confirming && (
        <ProtoModal
          title="Pay a different way?"
          cancelLabel="Keep waiting"
          confirmLabel="I haven't paid — choose another way"
          onCancel={() => setConfirming(false)}
          onConfirm={() => {
            setConfirming(false)
            onPayDifferently()
          }}
        >
          <p className="text-sm">
            If you already paid with {paymentMethodLabel(payment.method)}, wait for it to be
            confirmed instead — paying again could charge you twice.
          </p>
        </ProtoModal>
      )}

      {/* Announced, so a screen reader hears the answer to the button it pressed. */}
      <p role="status" className="min-h-5 text-center text-xs text-ink-muted">
        {checkNote ??
          (checkedAt
            ? `Last checked ${checkedAt.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })}.`
            : '')}
      </p>
    </StatusCard>
  )
}
