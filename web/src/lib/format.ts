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

const dateFmt = new Intl.DateTimeFormat('en-PH', {
  year: 'numeric',
  month: 'short',
  day: 'numeric',
})

const dateTimeFmt = new Intl.DateTimeFormat('en-PH', {
  year: 'numeric',
  month: 'short',
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
