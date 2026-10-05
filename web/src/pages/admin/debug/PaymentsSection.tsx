import { useId, useState } from 'react'
import { debugPayments } from './api'
import { useAsync } from '../../../lib/useAsync'
import { toApiError } from '../../../lib/api'
import { formatDateTime, formatMoney } from '../../../lib/format'
import type {
  PaymentGatewayCharge,
  PaymentGatewayConfirm,
  PaymentGatewayMode,
  PaymentGatewayStatus,
  PaymentGatewayTestResult,
} from '../../../lib/types'
import { Alert } from '../../../components/ui/Alert'
import { ErrorState, SkeletonList } from '../../../components/ui/primitives'
import { ProtoModal } from '../../../components/ui/Proto'
import { Choice, Detail, SubCard } from './parts'

/*
 * Payments — the first section of the Debug page (docs/payment-gateway.md).
 *
 * The super admin's two switches, on a running server, during the defense:
 *
 *   1. How owners pay: simulated (paid on the press, no money moves) or
 *      KwikPay (real money, confirmed by KwikPay). What is missing on the
 *      server when KwikPay cannot be turned on, and a Test connection button.
 *   2. What KwikPay collects: a ₱1 test charge or the full bill. The defense
 *      runs on ₱1; a panelist who asks to see the real amount gets the full
 *      bill from here, and it goes back after [Ken, 2026-10-04].
 *   3. Online payments still waiting, and the ones flagged for staff.
 *
 * The screen says "simulated" and "test" nowhere, since the panel watches it
 * [Ken, 2026-10-05]: the simulated mode is labelled "Instant", the test charge
 * is named by its amount, and the Practice KwikPay row under Connection
 * details is gone (`kwikpay.fake_available` still says it, in the API).
 *
 * Neither switch touches a payment already started: an order keeps the
 * amount it was opened with (payments.gateway_amount), and its confirmation
 * is checked against that. The API is GET/PUT /debug/payments and POST
 * /debug/payments/test, behind the panel's gate and audited as
 * `debug.payments`; `php artisan biztrack:payment-gateway` does the same from
 * a terminal for when this page cannot be reached.
 */

export function PaymentsSection() {
  const status = useAsync(() => debugPayments.status(), [])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  /** Announced after a switch, so the change is said and not only drawn. */
  const [said, setSaid] = useState<string | null>(null)
  const [confirmingFull, setConfirmingFull] = useState(false)

  async function change(body: {
    mode?: PaymentGatewayMode
    charge?: PaymentGatewayCharge
    confirm?: PaymentGatewayConfirm
  }) {
    if (busy) return
    setBusy(true)
    setError(null)
    setSaid(null)
    try {
      const next = await debugPayments.update(body)
      status.setData(next)
      setSaid(body.mode ? modeSaid(next) : body.confirm ? confirmSaid(next) : chargeSaid(next))
    } catch (err) {
      const apiError = toApiError(err)
      setError(
        apiError.errors.mode?.[0] ??
          apiError.errors.charge?.[0] ??
          apiError.errors.confirm?.[0] ??
          apiError.message,
      )
    } finally {
      setBusy(false)
    }
  }

  if (status.loading && !status.data) return <SkeletonList rows={3} />
  if (status.error && !status.data) return <ErrorState error={status.error} onRetry={status.reload} />
  const s = status.data
  if (!s) return null

  const testLabel = formatMoney(s.test_amount)

  return (
    <div className="space-y-5">
      {error && <Alert variant="error">{error}</Alert>}
      {/* Always mounted, so a screen reader hears the sentence when it lands. */}
      <p role="status" className="text-sm font-semibold text-ink empty:hidden">
        {said}
      </p>

      <HowOwnersPay status={s} busy={busy} onChoose={(mode) => change({ mode })} />

      <SubCard title="What KwikPay collects">
        <p className="max-w-[70ch] text-sm text-ink-secondary">
          The bill, the receipt and the records always keep the real assessed amount. Only what
          KwikPay takes from the owner changes.
        </p>
        <div className="mt-4 grid gap-3 sm:grid-cols-2">
          <Choice
            label={testLabel}
            description={`Owners are charged ${formatMoney(s.test_amount)} whatever the bill.`}
            on={s.charge === 'test'}
            busy={busy}
            onChoose={() => change({ charge: 'test' })}
          />
          <Choice
            label="The full bill"
            description="Owners are charged the assessed amount, in real money."
            on={s.charge === 'full'}
            busy={busy}
            onChoose={() => setConfirmingFull(true)}
          />
        </div>
      </SubCard>

      <SubCard title="What marks a payment paid">
        <p className="max-w-[70ch] text-sm text-ink-secondary">
          KwikPay can tell BizTrack a payment went through in two ways: it posts a signed
          confirmation to biztrack.page, or it answers when BizTrack asks about the order.
        </p>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <Choice
            label="Only the signed confirmation"
            description="A payment turns Paid only when KwikPay posts its signed confirmation. On a server KwikPay cannot reach, such as localhost, nothing turns Paid."
            on={s.confirm === 'callback'}
            busy={busy}
            onChoose={() => change({ confirm: 'callback' })}
          />
          <Choice
            label="Its answer when asked, too"
            description="A payment also turns Paid when BizTrack asks KwikPay and it answers success. On 4 October this gateway answered success for an order nobody paid."
            on={s.confirm === 'query'}
            busy={busy}
            onChoose={() => change({ confirm: 'query' })}
          />
          <Choice
            label="Its answer’s message"
            description="A payment also turns Paid when the gateway’s answer says “Transaction completed successfully”. This gateway answers 5 for every order it finds and puts the real state in the message."
            on={s.confirm === 'message'}
            busy={busy}
            onChoose={() => change({ confirm: 'message' })}
          />
        </div>
      </SubCard>

      <StillWaiting status={s} loading={status.loading} onRefresh={status.reload} />

      {confirmingFull && (
        /*
         * Asked, because this one moves real money: from here on an owner
         * paying a ₱2,000 bill is charged ₱2,000. Blue, not red: it is a
         * consequential act, not a destructive one (DESIGN.md, Red Means Stop).
         * Going back to the test charge is never asked about.
         */
        <ProtoModal
          title="Charge owners the full bill?"
          cancelLabel={`Keep ${testLabel}`}
          confirmLabel="Charge the full bill"
          onCancel={() => setConfirmingFull(false)}
          onConfirm={() => {
            setConfirmingFull(false)
            void change({ charge: 'full' })
          }}
        >
          <p className="text-sm">
            Every new KwikPay payment will take the full assessed amount from the owner&apos;s GCash,
            Maya or bank account. Payments already started keep the amount they were opened with.
          </p>
        </ProtoModal>
      )}
    </div>
  )
}

function modeSaid(s: PaymentGatewayStatus): string {
  return s.mode === 'kwikpay'
    ? 'Switched: owners now pay through KwikPay.'
    : 'Switched: KwikPay is off.'
}

function confirmSaid(s: PaymentGatewayStatus): string {
  if (s.confirm === 'callback') return 'Switched: only KwikPay’s signed confirmation marks a payment paid.'
  if (s.confirm === 'message') return 'Switched: the gateway’s answer marks a payment paid when its message says it completed.'
  return 'Switched: KwikPay’s answer when asked also marks a payment paid.'
}

function chargeSaid(s: PaymentGatewayStatus): string {
  return s.charge === 'test'
    ? `Switched: KwikPay now collects ${formatMoney(s.test_amount)} per bill.`
    : 'Switched: KwikPay now collects the full bill.'
}

/* ── 1. How owners pay ──────────────────────────────────────────────────── */

function HowOwnersPay({
  status: s,
  busy,
  onChoose,
}: {
  status: PaymentGatewayStatus
  busy: boolean
  onChoose: (mode: PaymentGatewayMode) => void
}) {
  const missingId = useId()
  const [testing, setTesting] = useState(false)
  const [result, setResult] = useState<PaymentGatewayTestResult | null>(null)
  const blocked = !s.kwikpay.configured

  async function test() {
    if (testing) return
    setTesting(true)
    setResult(null)
    try {
      setResult(await debugPayments.test())
    } catch (err) {
      setResult({ ok: false, message: toApiError(err).message })
    } finally {
      setTesting(false)
    }
  }

  return (
    <SubCard title="How owners pay">
      {blocked && (
        <div id={missingId} className="mb-4">
          <Alert variant="warning" title="KwikPay can't be turned on yet">
            Missing on the server: {s.kwikpay.missing.join(', ')}. These are set in the API&apos;s
            environment by whoever runs the server.
          </Alert>
        </div>
      )}
      <div className="grid gap-3 sm:grid-cols-2">
        <Choice
          label="Instant"
          description="Paid the moment the owner presses Pay."
          on={s.mode === 'simulated'}
          busy={busy}
          onChoose={() => onChoose('simulated')}
        />
        <Choice
          label="KwikPay"
          description="The owner pays with GCash, Maya, QR Ph or GoTyme. Paid once KwikPay confirms."
          on={s.mode === 'kwikpay'}
          busy={busy}
          blockedBy={blocked ? missingId : undefined}
          onChoose={() => onChoose('kwikpay')}
        />
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-3">
        <button
          type="button"
          onClick={test}
          aria-disabled={testing || undefined}
          className="rounded-full border-2 border-royal bg-white px-5 py-1.5 text-sm font-semibold text-royal hover:bg-royal-tint aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
        >
          {testing ? 'Testing…' : 'Test connection'}
        </button>
        <span className="text-xs text-ink-muted">One signed call to KwikPay. Changes nothing.</span>
      </div>
      {result && (
        <div className="mt-3">
          <Alert variant={result.ok ? 'success' : 'error'}>
            {result.message}
            {result.ok && result.merchant_display_name ? ` Merchant: ${result.merchant_display_name}.` : ''}
          </Alert>
        </div>
      )}

      {/*
        For whoever is chasing a payment that will not confirm: which account
        and which address KwikPay is reaching. Folded away because the panel
        never needs it. Names and addresses only — the key never leaves the
        server.
      */}
      <details className="mt-4 text-sm">
        <summary className="cursor-pointer font-semibold text-royal">Connection details</summary>
        <dl className="mt-2 grid gap-x-4 gap-y-1.5 sm:grid-cols-[max-content_1fr]">
          <Detail term="Merchant">{s.kwikpay.merchant || '—'}</Detail>
          <Detail term="KwikPay address">{s.kwikpay.base_url || '—'}</Detail>
          <Detail term="Payment type">{s.kwikpay.payment_type || '—'}</Detail>
          <Detail term="Where KwikPay confirms payments">{s.kwikpay.callback_url}</Detail>
        </dl>
      </details>
    </SubCard>
  )
}

/* ── 3. Online payments still waiting ───────────────────────────────────── */

function StillWaiting({
  status: s,
  loading,
  onRefresh,
}: {
  status: PaymentGatewayStatus
  loading: boolean
  onRefresh: () => void
}) {
  const flagged = s.flagged.length

  return (
    <SubCard
      title="Online payments still waiting"
      action={
        <button
          type="button"
          onClick={onRefresh}
          aria-disabled={loading || undefined}
          className="text-sm font-semibold text-royal underline underline-offset-2 hover:no-underline aria-disabled:opacity-60"
        >
          {loading ? 'Refreshing…' : 'Refresh'}
        </button>
      }
    >
      <p className="text-sm text-ink">
        {s.pending === 0
          ? 'None. Every online payment has been confirmed or has failed.'
          : `${s.pending} ${s.pending === 1 ? 'payment is' : 'payments are'} waiting for KwikPay to confirm, ${flagged} of them flagged for staff.`}
      </p>
      <p className="mt-1 max-w-[70ch] text-xs text-ink-muted">
        Switching either setting does not cancel these. Each is still confirmed by KwikPay, and one
        unconfirmed after a day is flagged here.
      </p>

      {flagged > 0 && (
        <ul className="mt-4 divide-y divide-line rounded-lg border border-line">
          {s.flagged.map((p) => (
            <li key={p.id} className="grid gap-1 px-4 py-3 text-sm sm:grid-cols-[1fr_auto] sm:gap-x-6">
              <div className="min-w-0">
                <p className="font-semibold text-ink">
                  <span className="tnum">{p.reference_number}</span>
                  <span className="font-normal text-ink-muted"> · application </span>
                  <span className="tnum">{p.tracking_id ?? '—'}</span>
                </p>
                <p className="mt-0.5 break-words text-ink-secondary">{p.note ?? '—'}</p>
              </div>
              <div className="text-ink-secondary sm:text-right">
                <p className="tnum font-semibold text-ink">{formatMoney(p.amount)}</p>
                <p className="text-xs">Flagged {formatDateTime(p.flagged_at)}</p>
              </div>
            </li>
          ))}
        </ul>
      )}
    </SubCard>
  )
}
