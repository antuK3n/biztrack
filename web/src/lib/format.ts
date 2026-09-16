/* Shared formatters. Money is PHP; times are ISO-8601 UTC from the API. */

const peso = new Intl.NumberFormat('en-PH', {
  style: 'currency',
  currency: 'PHP',
  minimumFractionDigits: 2,
})

export function formatMoney(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '₱0.00'
  const num = typeof value === 'string' ? Number(value) : value
  return Number.isFinite(num) ? peso.format(num) : '₱0.00'
}

/**
 * Read a number back out of an ALREADY-FORMATTED peso string — "₱10,801.00".
 *
 * The inverse of what the server does, and it exists because two of our money
 * fields arrive pre-formatted rather than as amounts: `ClearanceMeta`'s three
 * totals and `Clearance.fee_preview`, all of which come through
 * `PermitFees::peso`. Handing those to formatMoney() is the bug this prevents:
 * Number("₱10,801.00") is NaN, formatMoney answers "₱0.00" for NaN, and a
 * filing with ten thousand pesos outstanding renders as fully settled.
 *
 * Only for COMPARING. Never for redisplay — the server's string is the one to
 * print, because round-tripping it through here and back through formatMoney()
 * is two chances for the two formatters to disagree about a currency symbol.
 * The one comparison it exists for is "has the balance reached zero", which
 * decides whether the permit is released.
 *
 * The strip keeps digits, the decimal point and a leading minus. It has to keep
 * the minus: an overpayment is a negative balance, and dropping the sign would
 * turn "we owe you ₱200" into "you owe ₱200" — the one direction of error a
 * fee screen must never make. It drops the group separators, which is why a
 * plain `Number()` will not do.
 *
 * Returns NaN for anything unparseable, deliberately: a caller deciding whether
 * money is owed must be able to tell "zero" from "the server sent something I
 * could not read", and 0 would conflate them into "nothing to pay".
 */
export function pesoToNumber(value: string | number | null | undefined): number {
  if (value === null || value === undefined) return Number.NaN
  if (typeof value === 'number') return value
  // A hyphen anywhere else in the string is not a sign; only a leading one is.
  const negative = value.trim().startsWith('-')
  const digits = value.replace(/[^0-9.]/g, '')
  if (digits === '' || digits === '.') return Number.NaN
  const n = Number(digits)
  return Number.isFinite(n) ? (negative ? -n : n) : Number.NaN
}

/*
 * The month is SPELLED OUT — "September 16, 2026", never "Sep 16, 2026".
 *
 * These two formatters are the only date rendering in the app, so the house
 * style has to live here or not at all. The client asked for whole month names
 * and the reason is the paperwork: a permit's validity, a receipt's date and an
 * RA 11032 deadline are dates people transcribe onto printed forms and quote
 * back at a counter, and an abbreviation is one more thing to expand — or
 * mis-expand, since "Jan" through "Dec" are not the abbreviations every clerk
 * writes. Spelling it out means the screen and the form say the same thing.
 *
 * `month: 'long'` also changes the time connector, which is the knock-on worth
 * knowing: en-PH renders the short form as "Sep 16, 2026, 11:45 PM" and the
 * long form as "September 16, 2026 at 11:45 PM". The comma becomes "at". That
 * is the better string for a timestamp and no caller parses one back, so it is
 * left alone — but anyone diffing screenshots should expect the separator to
 * move, not just the month.
 *
 * No short-form escape hatch, deliberately. Every caller was checked and none
 * is laid out so tightly that it breaks: the list rows that put a date beside a
 * title (Messages, Notifications, the officer queue) give the date `shrink-0`
 * next to a `truncate` neighbour, so the extra characters cost the title some
 * room rather than wrapping the row, and the table cells that carry one are
 * left-aligned columns that simply widen. Adding a `formatDateShort()` for a
 * layout that merely got tighter would reintroduce exactly the inconsistency
 * this issue exists to remove, and the second helper is the one that quietly
 * spreads. If a screen ever genuinely cannot hold the long form, the fix is a
 * named helper that says what it is FOR — not a generic short formatter.
 */
const dateFmt = new Intl.DateTimeFormat('en-PH', {
  year: 'numeric',
  month: 'long',
  day: 'numeric',
})

const dateTimeFmt = new Intl.DateTimeFormat('en-PH', {
  year: 'numeric',
  month: 'long',
  day: 'numeric',
  hour: 'numeric',
  minute: '2-digit',
})

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : dateFmt.format(d)
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : dateTimeFmt.format(d)
}

/** "3 days ago", "in 5 days", "just now" — for timelines and deadlines. */
export function formatRelative(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const diffMs = d.getTime() - Date.now()
  const abs = Math.abs(diffMs)
  const min = 60_000
  const hour = 60 * min
  const day = 24 * hour
  const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })
  if (abs < hour) return rtf.format(Math.round(diffMs / min), 'minute')
  if (abs < day) return rtf.format(Math.round(diffMs / hour), 'hour')
  return rtf.format(Math.round(diffMs / day), 'day')
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

const APPLICATION_TYPE_LABELS: Record<string, string> = {
  new: 'New application',
  renewal: 'Renewal',
  amendment: 'Amendment',
}

export function applicationTypeLabel(type: string): string {
  return APPLICATION_TYPE_LABELS[type] ?? type
}

const PAYMENT_METHOD_LABELS: Record<string, string> = {
  gcash: 'GCash',
  maya: 'Maya',
  card: 'Card',
}

export function paymentMethodLabel(method: string): string {
  return PAYMENT_METHOD_LABELS[method] ?? method
}

/**
 * What to call a filing's business when the register no longer holds one.
 *
 * `Business` soft-deletes and its filings stay behind, so any list that runs
 * deep enough reaches an application whose business is gone. The name is the
 * first thing every one of those screens prints, which is why getting it wrong
 * took whole pages down rather than single rows.
 *
 * The replacement says what happened rather than hiding it. An officer looking
 * at a request queue needs to know the business was removed from the register —
 * that is usually the reason the filing stalled, and blanking the cell or
 * printing the tracking ID alone would leave them hunting for it.
 */
export function businessName(business: { name: string } | null): string {
  return business?.name ?? 'Business removed from register'
}
