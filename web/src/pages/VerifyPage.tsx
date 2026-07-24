import { useParams } from 'react-router-dom'
import { CheckCircleFilledIcon, ShieldCheckIcon, XCircleIcon } from '../components/icons'
import { Skeleton } from '../components/ui/primitives'
import { Logo } from '../components/Logo'
import { formatDate } from '../lib/format'
import { permits } from '../lib/resources'
import { toApiError } from '../lib/api'
import { useAsync } from '../lib/useAsync'

/*
 * PUBLIC permit verification (no auth, outside AppShell) — restyled to the
 * prototype card language: canvas background, centered logo, white shadow
 * rounded card with serif accents and a green/red validity banner. Valid /
 * invalid is stated by icon + text + color (never color alone). Shows only
 * business name / barangay — no PII beyond the contract.
 */

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-4 py-2.5">
      <span className="text-sm text-ink-muted">{label}</span>
      <span className="text-right text-sm font-medium text-ink">{children}</span>
    </div>
  )
}

export function VerifyPage() {
  const { permit_number = '' } = useParams()
  const { data, loading, error } = useAsync(() => permits.verify(permit_number), [permit_number])

  return (
    <div className="min-h-dvh bg-canvas px-4 py-10">
      <div className="mx-auto max-w-xl">
        <div className="flex justify-center">
          <Logo height={34} />
        </div>
        <p className="mt-4 text-center text-xs font-bold uppercase tracking-[0.14em] text-ink-muted">
          Permit Verification
        </p>
        <h1 className="display-serif mt-1 text-center text-2xl text-ink">{permit_number}</h1>

        {loading && (
          <div className="mt-8 space-y-4" role="status" aria-label="Checking this permit">
            <Skeleton className="h-24 w-full rounded-2xl" />
            <Skeleton className="h-52 w-full rounded-2xl" />
          </div>
        )}

        {!loading && (error || !data) && (
          <div className="mt-8 rounded-2xl bg-white p-7 shadow-card">
            <div className="flex items-start gap-3">
              <XCircleIcon size={30} className="shrink-0 text-s-red" />
              <div>
                <p className="text-lg font-bold text-s-red">Permit not found</p>
                <p className="mt-1 text-sm text-ink-secondary">
                  {error
                    ? toApiError(error).message
                    : 'We couldn’t find a permit with this number. Check the number and try again, or contact the issuing office.'}
                </p>
              </div>
            </div>
          </div>
        )}

        {!loading && data && (
          <div className="mt-8 overflow-hidden rounded-2xl bg-white shadow-card">
            {/* Validity banner */}
            <div
              role="status"
              className={`flex items-center gap-3 px-7 py-5 text-white ${
                data.is_valid ? 'bg-s-green' : 'bg-s-red'
              }`}
            >
              {data.is_valid ? (
                <CheckCircleFilledIcon size={30} className="shrink-0" />
              ) : (
                <XCircleIcon size={30} className="shrink-0" />
              )}
              <div>
                <p className="text-lg font-bold">
                  {data.is_valid ? 'Valid Permit' : `Not valid: ${data.status_label}`}
                </p>
                <p className="text-sm opacity-90">
                  {data.is_valid
                    ? 'This permit is active and on record with the issuing LGU.'
                    : 'This permit is not currently valid. Contact the issuing office for details.'}
                </p>
              </div>
            </div>

            <div className="divide-y divide-line px-7 py-4">
              <Row label="Permit number">
                <span className="display-serif tnum text-base">{data.permit_number}</span>
              </Row>
              <Row label="Business">{data.business.name}</Row>
              <Row label="Permit type">{data.permit_type.name}</Row>
              <Row label="Barangay">
                {data.business.address.barangay.name}
                {data.business.address.city ? `, ${data.business.address.city}` : ''}
              </Row>
              <Row label="Valid from">{formatDate(data.valid_from)}</Row>
              <Row label="Valid until">{formatDate(data.valid_until)}</Row>
            </div>
          </div>
        )}

        <p className="mt-7 flex items-center justify-center gap-2 text-sm text-ink-muted">
          <ShieldCheckIcon size={16} />
          Verified against BizTrack’s official permit registry.
        </p>
      </div>
    </div>
  )
}
