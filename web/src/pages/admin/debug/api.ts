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

/*
 * Move a filing along: the offices' own steps, the super admin acting for
 * them (App\Services\Debug\FilingMover). Labels, statuses and refusals all
 * come from the server, which is the authority on what each step means.
 */
export type DebugStepKey =
  | 'bplo_approve'
  | 'bplo_return'
  | 'payment'
  | 'permit_approve'
  | 'permit_return'
  | 'inspection_book'
  | 'inspection_pass'
  | 'inspection_fail'
  | 'inspection_rebook'

export type DebugAdvanceTarget = 'pending_payment' | 'paid' | 'approved'

export interface DebugFilingHit {
  id: number
  tracking_id: string
  business: string | null
  type_label: string | null
  status: string
  status_label: string
}

export interface DebugFilingStep {
  key: DebugStepKey
  /** The permit's code on an office's step; null on BPLO's and the payment. */
  permit: string | null
  label: string
  office: string | null
  note: 'required' | 'optional' | null
  forward: boolean
}

export interface DebugFilingPermit {
  code: string
  name: string
  office: string | null
  office_code: string | null
  status: string
  status_label: string
  mode: string | null
  /** The office's queue item, by its status label; null when it has none. */
  queue: string | null
  inspection: { status: string; result: string | null; label: string; scheduled_at: string | null } | null
  permit_number: string | null
}

export interface DebugFiling {
  id: number
  tracking_id: string
  type: string
  type_label: string
  status: string
  status_label: string
  business: string | null
  applicant: string | null
  submitted_at: string | null
  fee: { assessed: boolean; total_assessed: string; total_paid: string; balance_due: string }
  permits: DebugFilingPermit[]
  blockers: string[]
  steps: DebugFilingStep[]
  targets: { to: DebugAdvanceTarget; label: string; reached: boolean }[]
}

export interface DebugStepResult {
  step: DebugStepKey
  permit: string | null
  label: string
  ok: boolean
  refusal: string | null
  changes: string[]
}

export interface DebugStepOutcome {
  result: DebugStepResult
  filing: DebugFiling
}

export interface DebugAdvanceOutcome {
  results: DebugStepResult[]
  stopped: string | null
  reached: boolean
  filing: DebugFiling
}

export const debugFilings = {
  /** Newest first; an empty query lists the latest filings. */
  search: (q: string) => unwrap<DebugFilingHit[]>(api.get('/debug/filings', { params: { q } })),
  show: (id: number) => unwrap<DebugFiling>(api.get(`/debug/filings/${id}`)),
  /**
   * One step. A step the service REFUSES answers 422 with the result and the
   * filing as it stands, and resolves here like any other outcome — the
   * refusal is an answer, not a failure of the request. A 422 without them (a
   * return with no note) still throws.
   */
  step: async (id: number, body: { step: DebugStepKey; permit?: string | null; note?: string }) => {
    try {
      return await unwrap<DebugStepOutcome>(api.post(`/debug/filings/${id}/steps`, body))
    } catch (err) {
      const refused = (err as { response?: { status?: number; data?: { data?: DebugStepOutcome } } }).response
      if (refused?.status === 422 && refused.data?.data?.result) return refused.data.data
      throw err
    }
  },
  /** The forward steps until `to`, stopping at the first refusal. */
  advance: (id: number, to: DebugAdvanceTarget) =>
    unwrap<DebugAdvanceOutcome>(api.post(`/debug/filings/${id}/advance`, { to })),
}
