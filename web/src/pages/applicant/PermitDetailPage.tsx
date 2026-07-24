import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { QRCodeSVG } from 'qrcode.react'
import { DownloadIcon, PrintIcon, XIcon } from '../../components/icons'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { PillButton } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { permits } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { useAuth } from '../../stores/auth'
import { Logo } from '../../components/Logo'

/*
 * Permit view (PDF p17/p59): a modal-like centered sheet — royal top bar with
 * a white X — over a white "document" styled like the City of Malabon business
 * permit certificate (header, typewriter-ish serif BUSINESS PERMIT title,
 * owner/business rows, QR from verify_url, validity + signature lines).
 */

function CertField({ label, value, wide }: { label: string; value: string; wide?: boolean }) {
  return (
    <div className={`flex items-baseline gap-3 ${wide ? 'col-span-2' : ''}`}>
      <span className="shrink-0 text-[11px] font-bold uppercase tracking-wide text-ink-secondary">
        {label}
      </span>
      <span className="min-w-0 flex-1 truncate border border-line bg-royal-tint px-2.5 py-1 text-sm text-ink">
        {value}
      </span>
    </div>
  )
}

export function PermitDetailPage() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const user = useAuth((s) => s.user)
  const { data: permit, loading, error, reload } = useAsync(() => permits.get(Number(id)), [id])

  const [downloading, setDownloading] = useState(false)
  const [downloadError, setDownloadError] = useState<string | null>(null)

  async function downloadPdf() {
    if (!permit) return
    setDownloading(true)
    setDownloadError(null)
    try {
      await permits.pdf(permit.id, `${permit.permit_number}.pdf`)
    } catch (err) {
      setDownloadError(toApiError(err).message)
    } finally {
      setDownloading(false)
    }
  }

  if (loading) {
    return (
      <div className="mx-auto max-w-3xl space-y-4">
        <Skeleton className="h-5 w-32" />
        <Skeleton className="h-96 w-full rounded-lg" />
      </div>
    )
  }
  if (error || !permit) return <ErrorState error={error ?? new Error('Not found')} onRetry={reload} />

  const expired = permit.days_until_expiry !== null && permit.days_until_expiry < 0
  const ownerName = user ? `${user.first_name} ${user.last_name}` : '—'

  return (
    <div className="mx-auto max-w-3xl">
      {/* Modal-like sheet: royal bar with white X (p59) */}
      <div className="overflow-hidden rounded-md bg-white shadow-overlay">
        <div className="flex items-center justify-end bg-royal px-4 py-2.5 print:hidden">
          <button
            type="button"
            onClick={() => navigate('/permits')}
            aria-label="Close permit view"
            className="text-white transition-opacity hover:opacity-80"
          >
            <XIcon size={22} />
          </button>
        </div>

        {/* The permit "document" */}
        <article className="border-[6px] border-white bg-white px-6 py-7 sm:px-10 print:border-0">
          <div className="border-2 border-ink/80 px-5 py-6 sm:px-8">
            <header className="flex items-start justify-between gap-4">
              <div>
                <Logo height={24} />
                <p className="mt-2 text-base font-bold uppercase tracking-wide text-ink">City of Malabon</p>
                <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-secondary">
                  Business Permit and Licensing Office
                </p>
              </div>
              <div className="flex shrink-0 flex-col items-center gap-1.5">
                <QRCodeSVG value={permit.verify_url} size={92} level="M" />
                <p className="text-[10px] text-ink-muted">Scan to verify</p>
              </div>
            </header>

            <h1 className="display-serif mt-6 text-center text-3xl tracking-[0.18em] text-ink">
              BUSINESS PERMIT
            </h1>
            {expired && (
              <p className="mt-1 text-center text-sm font-bold uppercase tracking-wide text-s-red">
                Expired
              </p>
            )}

            <div className="mt-6 grid gap-3 sm:grid-cols-2">
              <CertField wide label="Name of Owner" value={ownerName} />
              <CertField wide label="Business Name" value={permit.business.name} />
              <CertField label="Permit No." value={permit.permit_number} />
              <CertField label="Permit Type" value={permit.permit_type.name} />
              <CertField label="Date of Issue" value={formatDate(permit.valid_from)} />
              <CertField label="Valid Until" value={formatDate(permit.valid_until)} />
              <CertField wide label="Tracking ID" value={permit.application.tracking_id} />
            </div>

            <div className="mt-5 h-1 bg-royal/70" />

            <div className="mt-4">
              <p className="text-[11px] font-bold uppercase tracking-wide text-ink-secondary">Remarks:</p>
              <div className="mt-1 h-16 border border-line" />
            </div>

            <div className="mt-10 grid grid-cols-2 gap-10 text-center">
              <div>
                <div className="mx-auto w-44 border-b border-ink" />
                <p className="mt-1.5 text-[11px] font-semibold uppercase tracking-wide text-ink-secondary">
                  City Mayor
                </p>
              </div>
              <div>
                <div className="mx-auto w-44 border-b border-ink" />
                <p className="mt-1.5 text-[11px] font-semibold uppercase tracking-wide text-ink-secondary">
                  BPLO Officer-in-Charge
                </p>
              </div>
            </div>

            <p className="mt-8 text-center text-[10px] leading-relaxed text-ink-muted">
              Subject to revocation for non-compliance with existing laws, ordinances, rules and
              regulations. Verify authenticity at{' '}
              <span className="break-all underline">{permit.verify_url}</span>
            </p>
          </div>
        </article>
      </div>

      {/* Actions below the sheet */}
      <div className="mt-6 flex flex-col items-center gap-2 print:hidden">
        <div className="flex justify-center gap-3">
          <PillButton onClick={() => window.print()}>
            <PrintIcon size={18} className="mr-2" /> Print
          </PillButton>
          <PillButton
            className="border-2 border-royal bg-white !text-royal hover:bg-royal-tint"
            onClick={downloadPdf}
            disabled={downloading}
          >
            <DownloadIcon size={18} className="mr-2" /> {downloading ? 'Preparing…' : 'Download PDF'}
          </PillButton>
        </div>
        {downloadError && <p className="text-sm font-medium text-s-red">{downloadError}</p>}
      </div>
    </div>
  )
}
