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

/** One Health check: ok / warn / fail, a sentence, and when its evidence is from. */
export interface HealthCheck {
  key: string
  label: string
  status: 'ok' | 'warn' | 'fail'
  summary: string
  /** When the evidence is from: a heartbeat's time, or the moment of checking. Null = never seen. */
  seen_at: string | null
}

export interface HealthReport {
  checked_at: string
  checks: HealthCheck[]
}

/** Health: is everything this server needs running. Read-only, bar one test e-mail. */
export const debugHealth = {
  report: () => unwrap<HealthReport>(api.get('/debug/health')),
  /** One plain e-mail to the signed-in super admin's own address. */
  testMail: () => unwrap<{ ok: boolean; message: string }>(api.post('/debug/health/test-mail')),
}

/** An on/off switch: what it is now, whether the Debug page set it, and whether it could go on. */
export interface OnOffSwitch {
  on: boolean
  /** True while a Debug-page setting overrides what the server's env decides. */
  overridden: boolean
  can_turn_on: boolean
  why_not: string | null
}

export interface SystemSwitchesState {
  sign_in_codes: OnOffSwitch
  captcha: OnOffSwitch
  office_hours: { mode: 'auto' | 'open' | 'closed'; open_by_the_clock: boolean }
  pretend_date: { date: string | null; real_today: string }
}

export type SystemSwitchName = keyof SystemSwitchesState

/** System switches: sign-in codes, captcha, the office-hours notice, the pretend date. */
export const debugSwitches = {
  state: () => unwrap<SystemSwitchesState>(api.get('/debug/switches')),
  /** One switch. `default` (or null for the date) hands it back to the env. */
  set: (name: SystemSwitchName, value: string | null) =>
    unwrap<SystemSwitchesState>(api.put('/debug/switches', { switch: name, value })),
}
