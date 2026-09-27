import type { ComponentType } from 'react'
import { useParams } from 'react-router-dom'
import {
  AlertTriangleIcon,
  CheckCircleFilledIcon,
  ClockIcon,
  ShieldCheckIcon,
  XCircleIcon,
} from '../components/icons'
import { Skeleton } from '../components/ui/primitives'
import { Logo } from '../components/Logo'
import { formatDate } from '../lib/format'
import { permits } from '../lib/resources'
import { toApiError } from '../lib/api'
import { useAsync } from '../lib/useAsync'
import type { VerifyResult } from '../lib/types'

/*
 * PUBLIC permit verification — the page a permit's QR code opens (no sign-in,
 * outside AppShell).
 *
 * ── Who reads it, and what they need ─────────────────────────────────────────
 *
 * Somebody standing in front of a certificate on a shop wall, holding a phone:
 * an inspector, a customer, a landlord. They are asking one question — "is this
 * paper real and still good?" — so the page answers that first, in one word,
 * and then lists only what lets them compare the paper with the register
 * (checklist item 22): permit number, business and trade name, address, permit
 * type and valid until. It had a Valid from row and a serif display face; both
 * went, because neither helps that comparison and the first screen of a phone
 * is all this page gets.
 *
 * Mobile-first: one column, full-width card, the verdict above the fold at
 * 360px. It widens to a max of 32rem on anything larger and does nothing else.
 *
 * ── The verdict ──────────────────────────────────────────────────────────────
 *
 * Five answers, each an icon AND a word AND a sentence — never colour alone
 * (DESIGN.md). Red is kept for the one that is a denial, Revoked; an expired or
 * replaced permit is an ordinary end of a term, not an error, and is amber.
 * The banner is a tint with dark ink rather than white text on a saturated
 * fill: white on the register's green measures about 2.5:1, which fails the AA
 * contrast this page owes a reader squinting at a phone outdoors.
 *
 * What is NOT here, deliberately: the owner's name and the revocation reason.
 * See VerifyController for why.
 */

type Verdict = {
  word: string
  sentence: string
  icon: ComponentType<{ size?: number; className?: string }>
  tone: string
  iconTone: string
}

function verdictFor(data: VerifyResult): Verdict {
  if (data.is_valid) {
    return {
      word: 'Valid',
      sentence: 'This permit is on record with the City of Malabon and in force today.',
      icon: CheckCircleFilledIcon,
      tone: 'bg-s-green-tint',
      iconTone: 'text-s-green',
    }
  }
  switch (data.status) {
    case 'revoked':
      return {
        word: 'Revoked',
        sentence: `The City revoked this permit${data.revoked_at ? ` on ${formatDate(data.revoked_at)}` : ''}. It is no longer valid.`,
        icon: XCircleIcon,
        tone: 'bg-s-red-tint',
        iconTone: 'text-s-red',
      }
    case 'suspended':
      return {
        word: 'Suspended',
        sentence: 'This permit is suspended and not valid while the suspension lasts.',
        icon: AlertTriangleIcon,
        tone: 'bg-s-purple-tint',
        iconTone: 'text-s-purple',
      }
    case 'superseded':
      return {
        word: 'Replaced',
        sentence: 'A newer permit has replaced this one. Ask to see the current permit.',
        icon: ClockIcon,
        tone: 'bg-s-yellow-tint',
        iconTone: 'text-s-yellow-ink',
      }
    default:
      return {
        word: 'Expired',
        sentence: 'This permit’s term has ended. It is not valid until renewed.',
        icon: ClockIcon,
        tone: 'bg-s-yellow-tint',
        iconTone: 'text-s-yellow-ink',
      }
  }
}

/** One label/value pair. A missing value is a dash, never a blank or an invented one. */
function Row({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div className="py-3">
      <dt className="text-xs font-semibold uppercase tracking-wide text-ink-muted">{label}</dt>
      <dd className="mt-0.5 text-base text-ink">{value && value.trim() !== '' ? value : '—'}</dd>
    </div>
  )
}

/** Street, barangay and city on one line, leaving out whichever is missing. */
function addressOf(data: VerifyResult): string | null {
  const { line, barangay, city } = data.business.address
  const parts = [line, barangay ? `Brgy. ${barangay.name}` : null, city].filter(
    (p): p is string => p !== null && p.trim() !== '',
  )
  return parts.length > 0 ? parts.join(', ') : null
}

export function VerifyPage() {
  const { permit_number = '' } = useParams()
  const { data, loading, error } = useAsync(() => permits.verify(permit_number), [permit_number])

  const verdict = data ? verdictFor(data) : null
  const tradeName = data?.business.trade_name
  const showTradeName = tradeName && tradeName.trim() !== '' && tradeName !== data?.business.name

  return (
    <div className="min-h-dvh bg-canvas px-4 py-6 sm:py-10">
      <main className="mx-auto max-w-lg">
        <div className="flex items-center justify-between gap-3">
          <Logo height={28} />
          <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
            Permit verification
          </p>
        </div>

        {loading && (
          <div className="mt-6 space-y-3" role="status" aria-label="Checking this permit">
            <Skeleton className="h-20 w-full rounded-xl" />
            <Skeleton className="h-64 w-full rounded-xl" />
          </div>
        )}

        {!loading && (error || !data) && (
          <div className="mt-6 rounded-xl border border-line bg-white p-5">
            <div className="flex items-start gap-3">
              <XCircleIcon size={28} className="shrink-0 text-s-red" />
              <div>
                <h1 className="text-lg font-bold text-ink">Permit not found</h1>
                <p className="mt-1 text-sm text-ink-secondary">
                  {error
                    ? toApiError(error).message
                    : 'No permit has this number. Check it against the certificate, or ask the Business Permits and Licensing Office.'}
                </p>
                <p className="mt-2 text-sm text-ink-muted tnum">{permit_number}</p>
              </div>
            </div>
          </div>
        )}

        {!loading && data && verdict && (
          <div className="mt-6 overflow-hidden rounded-xl border border-line bg-white">
            {/* The verdict — first, in one word, with its icon and a sentence. */}
            <div role="status" className={`flex items-start gap-3 px-5 py-4 ${verdict.tone}`}>
              <verdict.icon size={28} className={`mt-0.5 shrink-0 ${verdict.iconTone}`} />
              <div>
                <h1 className="text-xl font-bold text-ink">{verdict.word}</h1>
                <p className="mt-0.5 text-sm text-ink-secondary">{verdict.sentence}</p>
              </div>
            </div>

            <dl className="divide-y divide-line px-5">
              <Row label="Permit no." value={data.permit_number} />
              <Row label="Business name" value={data.business.name} />
              {showTradeName && <Row label="Trade name" value={tradeName} />}
              <Row label="Address" value={addressOf(data)} />
              <Row label="Permit type" value={data.permit_type?.name} />
              <Row label="Valid until" value={data.valid_until ? formatDate(data.valid_until) : null} />
            </dl>
          </div>
        )}

        <p className="mt-5 flex items-center justify-center gap-2 text-center text-xs text-ink-muted">
          <ShieldCheckIcon size={14} />
          Checked against the City of Malabon’s permit register.
        </p>
      </main>
    </div>
  )
}
