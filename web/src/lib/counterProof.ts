import { toApiError } from './api'

/*
 * Two helpers for the proof of an over-the-counter payment, shared by the
 * owner's Pay page and BPLO's Mark as paid (components/CounterProof.tsx and
 * pages/officer/MarkPaidDialog.tsx). Kept out of the component file so it
 * exports components alone, as fast refresh wants.
 */

/** Today as the browser's calendar has it, "2026-10-21" — the latest a date paid can be. */
export function todayIso(): string {
  const d = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/**
 * Every sentence a refusal carries, one per line.
 *
 * Laravel's own `message` for a 422 is the first error plus "(and 1 more
 * error)", which names a count instead of the thing to fix. The field errors
 * are the API's sentences, so those are what is shown.
 */
export function refusalSentences(error: unknown): string[] {
  const e = toApiError(error)
  const listed = Object.values(e.errors ?? {}).flat()
  return listed.length > 0 ? [...new Set(listed)] : [e.message]
}
