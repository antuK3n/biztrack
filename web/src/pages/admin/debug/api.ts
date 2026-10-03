import { api } from '../../../lib/api'
import type {
  PaymentGatewayCharge,
  PaymentGatewayMode,
  PaymentGatewayStatus,
  PaymentGatewayTestResult,
} from '../../../lib/types'

/*
 * The Debug page's API client: /api/v1/debug/*, one object per section.
 *
 * Kept beside the page rather than in lib/resources.ts because nothing else in
 * the app may call these, and a section added to the page brings its client
 * with it. Every route answers 404 unless the panel is open to this account
 * (App\Support\DebugPanel), so a 404 from here means "closed", not "missing".
 */

async function unwrap<T>(request: Promise<{ data: { data: T } }>): Promise<T> {
  return (await request).data.data
}

/** When the panel closes: null while APP_ENV=local holds it open without the flag. */
export interface DebugPanelWindow {
  open_until: string | null
  local: boolean
}

export const debugPanel = {
  window: () => unwrap<DebugPanelWindow>(api.get('/debug/panel')),
}

/** Payments: how owners pay, and what KwikPay collects (docs/payment-gateway.md). */
export const debugPayments = {
  status: () => unwrap<PaymentGatewayStatus>(api.get('/debug/payments')),
  /** Either switch or both; the answer is the status after the change. */
  update: (body: { mode?: PaymentGatewayMode; charge?: PaymentGatewayCharge }) =>
    unwrap<PaymentGatewayStatus>(api.put('/debug/payments', body)),
  /** One signed call to KwikPay. Changes nothing. */
  test: () => unwrap<PaymentGatewayTestResult>(api.post('/debug/payments/test')),
}
