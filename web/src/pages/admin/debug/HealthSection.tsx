import { useState } from 'react'
import { debugHealth } from './api'
import type { HealthCheck } from './api'
import { useAsync } from '../../../lib/useAsync'
import { toApiError } from '../../../lib/api'
import { formatDateTime } from '../../../lib/format'
import { Alert } from '../../../components/ui/Alert'
import { ErrorState, SkeletonList } from '../../../components/ui/primitives'
import { ProtoCard } from '../../../components/ui/Proto'
import { AlertTriangleIcon, CheckCircleFilledIcon, XCircleIcon } from '../../../components/icons'

/*
 * Health — is everything this server needs actually running (Debug page)?
 *
 * Read-only. One row per check from App\Support\SystemHealth: the database,
 * migrations, the scheduler and the queue worker (by their heartbeats), failed
 * jobs, mail, the KwikPay connection, the last payment check, the version and
 * disk space. Each says ok, warning or failing in words as well as colour
 * (Never Colour Alone), and when its evidence is from, because "the scheduler
 * is fine" is only true as of its last beat.
 *
 * Loaded when the page opens and on Refresh, never on a timer: a check runs
 * one signed call to KwikPay, and a page left open through a defense should
 * not be calling it every few seconds.
 */

/*
 * The word is in ink and the colour is on the tint and the icon: the theme's
 * green-700 and amber-800 measure about 3.5:1 on their own tints, short of AA
 * for text this small.
 */
const STATUS = {
  ok: { word: 'OK', icon: CheckCircleFilledIcon, cls: 'border-green-200 bg-green-50', iconCls: 'text-green-700' },
  warn: { word: 'Warning', icon: AlertTriangleIcon, cls: 'border-amber-200 bg-amber-50', iconCls: 'text-amber-800' },
  fail: { word: 'Failing', icon: XCircleIcon, cls: 'border-red-200 bg-red-50', iconCls: 'text-red-700' },
} as const

export function HealthSection() {
  const report = useAsync(() => debugHealth.report(), [])

  if (report.loading && !report.data) return <SkeletonList rows={5} />
  if (report.error && !report.data) return <ErrorState error={report.error} onRetry={report.reload} />
  const data = report.data
  if (!data) return null

  const failing = data.checks.filter((c) => c.status === 'fail').length
  const warning = data.checks.filter((c) => c.status === 'warn').length

  return (
    <ProtoCard className="rounded-xl p-5">
      <div className="mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
        <p role="status" className="text-sm text-ink">
          <span className="font-semibold">
            {failing === 0 && warning === 0
              ? `All ${data.checks.length} checks are OK.`
              : `${failing} failing and ${warning} with a warning, of ${data.checks.length} checks.`}
          </span>{' '}
          <span className="text-ink-muted">Checked {formatDateTime(data.checked_at)}.</span>
        </p>
        <button
          type="button"
          onClick={report.reload}
          aria-disabled={report.loading || undefined}
          className="text-sm font-semibold text-royal underline underline-offset-2 hover:no-underline aria-disabled:opacity-60"
        >
          {report.loading ? 'Checking…' : 'Refresh'}
        </button>
      </div>

      <ul className="divide-y divide-line rounded-lg border border-line" aria-label="Health checks">
        {data.checks.map((check) => (
          <HealthRow key={check.key} check={check} />
        ))}
      </ul>
    </ProtoCard>
  )
}

function HealthRow({ check }: { check: HealthCheck }) {
  const meta = STATUS[check.status]
  const Icon = meta.icon

  return (
    <li className="grid gap-x-4 gap-y-1.5 px-4 py-3 text-sm sm:grid-cols-[8.5rem_1fr_auto] sm:items-start">
      <span
        className={`inline-flex w-fit items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs font-semibold text-ink ${meta.cls}`}
      >
        <Icon size={14} aria-hidden="true" className={meta.iconCls} /> {meta.word}
      </span>
      <div className="min-w-0">
        <p className="font-semibold text-ink">{check.label}</p>
        <p className="mt-0.5 break-words text-ink-secondary">{check.summary}</p>
        {check.key === 'mail' && <TestMail />}
      </div>
      <p className="text-xs text-ink-muted sm:text-right">
        {check.seen_at ? `Seen ${formatDateTime(check.seen_at)}` : 'Never seen'}
      </p>
    </li>
  )
}

/*
 * The only thing in Health that does anything: one e-mail to the signed-in
 * super admin's own address. Never to anybody else, so it cannot be used to
 * mail an owner from the Debug page. Audited on the server.
 */
function TestMail() {
  const [sending, setSending] = useState(false)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  async function send() {
    if (sending) return
    setSending(true)
    setResult(null)
    try {
      setResult(await debugHealth.testMail())
    } catch (err) {
      setResult({ ok: false, message: toApiError(err).message })
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="mt-2 space-y-2">
      <button
        type="button"
        onClick={send}
        aria-disabled={sending || undefined}
        className="rounded-full border-2 border-royal bg-white px-4 py-1 text-xs font-semibold text-royal hover:bg-royal-tint aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
      >
        {sending ? 'Sending…' : 'Send a test e-mail to me'}
      </button>
      {result && <Alert variant={result.ok ? 'success' : 'warning'}>{result.message}</Alert>}
    </div>
  )
}
