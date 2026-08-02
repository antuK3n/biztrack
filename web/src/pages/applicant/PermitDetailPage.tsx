import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { QRCodeSVG } from 'qrcode.react'
import { DownloadIcon, PrintIcon, XIcon } from '../../components/icons'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { PillButton } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { permits } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { Logo } from '../../components/Logo'
import type { Permit } from '../../lib/types'

/*
 * Permit view (PDF p17/p59): a modal-like centered sheet — royal top bar with
 * a white X — over a white "document" styled like the City of Malabon business
 * permit certificate (header, typewriter-ish serif BUSINESS PERMIT title,
 * owner/business rows, QR from verify_url, validity + signature lines).
 *
 * The face is filled from `certificate`, which GET /permits/{id} answers
 * alongside the list-row fields (PermitController::certificateData). It carries
 * what the paper form asks for and the list row does not — owner, address, line
 * of business — and the same array renders the PDF, so what is downloaded is
 * what was on screen rather than a second renderer's idea of it.
 */

/**
 * The certificate face, as the API answers it.
 *
 * Declared here rather than in lib/types.ts because this screen and the PDF
 * blade are its only two consumers; every other screen wants the small Permit
 * row and is unaffected.
 */
interface PermitCertificate {
  permit_number: string
  permit_type_name: string
  department_name: string | null
  status_label: string | null
  /** Null when the business was soft-deleted out of the register. */
  business_name: string | null
  trade_name: string | null
  owner_name: string | null
  address: string | null
  barangay: string | null
  city: string | null
  line_of_business: string | null
  tracking_id: string | null
  valid_from: string | null
  valid_until: string | null
  /** Admin-edited office signatories; never a name compiled into this file. */
  signatories: { role: string; name: string }[]
  verify_url: string
}

function CertField({
  label,
  value,
  absent,
  wide,
  to,
}: {
  label: string
  value: string | null
  /** What the box says when there is no value. Defaults to an em dash. */
  absent?: string
  wide?: boolean
  /** Turns the value into a link. Used to walk back to the filing behind the permit. */
  to?: string
}) {
  /*
   * Wraps rather than truncates. This is a certificate, and a business that
   * declared three lines of business had the third and part of the second
   * replaced by an ellipsis — on the document that is supposed to say what the
   * permit covers. A taller box is the right trade against a shorter truth.
   */
  const box = 'min-w-0 flex-1 break-words border border-line bg-royal-tint px-2.5 py-1 text-sm'
  const empty = value === null || value === ''
  // Greyed and italic when empty, so a box with nothing in it never reads as a
  // value that failed to render.
  const tone = empty ? 'italic text-ink-muted' : 'text-ink'
  const text = empty ? (absent ?? '—') : value

  return (
    <div className={`flex items-baseline gap-3 ${wide ? 'col-span-2' : ''}`}>
      <span className="shrink-0 text-[11px] font-bold uppercase tracking-wide text-ink-secondary">
        {label}
      </span>
      {to && !empty ? (
        <Link to={to} className={`${box} font-semibold text-royal underline underline-offset-2 hover:no-underline print:no-underline`}>
          {text}
        </Link>
      ) : (
        <span className={`${box} ${tone}`}>{text}</span>
      )}
    </div>
  )
}

export function PermitDetailPage() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const { data, loading, error, reload } = useAsync(() => permits.get(Number(id)), [id])
  const permit = data as (Permit & { certificate?: PermitCertificate }) | null

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
  const cert = permit.certificate
  /*
   * The owner's name comes off the permit, not off the session.
   *
   * It used to be the signed-in user's, which is only right when the applicant
   * is looking at their own — a BPLO reviewer opening any permit saw their own
   * name printed as its holder. It is the business owner's name on the paper
   * certificate, so it is the business owner's name here.
   */
  const ownerName = cert?.owner_name ?? null
  const address = cert
    ? [cert.address, cert.barangay, cert.city].filter(Boolean).join(', ') || null
    : null
  const signatories =
    cert?.signatories?.length
      ? cert.signatories
      : /*
         * Role captions with no name, used when the issuing office has no
         * signatories configured. The blank line is a document waiting for a wet
         * signature; a name written here in code would be a forgery that keeps
         * printing after the officeholder has moved on.
         */
        [
          { role: 'City Mayor', name: '' },
          { role: 'Officer-in-Charge', name: '' },
        ]

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
                  {cert?.department_name ?? 'Business Permits and Licensing Office'}
                </p>
              </div>
              <div className="flex shrink-0 flex-col items-center gap-1.5">
                <QRCodeSVG value={permit.verify_url} size={92} level="M" />
                <p className="text-[10px] text-ink-muted">Scan to verify</p>
              </div>
            </header>

            {/* The permit type is the document's own title, as it is on paper —
                a fire safety certificate should not be headed BUSINESS PERMIT. */}
            <h1 className="display-serif mt-6 text-center text-3xl uppercase tracking-[0.18em] text-ink">
              {cert?.permit_type_name ?? permit.permit_type.name}
            </h1>
            {expired && (
              <p className="mt-1 text-center text-sm font-bold uppercase tracking-wide text-s-red">
                Expired
              </p>
            )}

            <div className="mt-6 grid gap-3 sm:grid-cols-2">
              <CertField wide label="Name of Owner" value={ownerName} />
              <CertField
                wide
                label="Business Name"
                value={cert ? cert.business_name : permit.business?.name}
                absent="Business removed from register"
              />
              {cert?.trade_name && <CertField wide label="Trade Name" value={cert.trade_name} />}
              <CertField wide label="Business Address" value={address} />
              {cert?.line_of_business && (
                <CertField wide label="Line of Business" value={cert.line_of_business} />
              )}
              <CertField label="Permit No." value={permit.permit_number} />
              <CertField label="Permit Type" value={permit.permit_type.name} />
              <CertField label="Date of Issue" value={formatDate(permit.valid_from)} />
              <CertField label="Valid Until" value={formatDate(permit.valid_until)} />
              {/* Approved filings leave the tracking list; this walks back to one. */}
              <CertField
                wide
                label="Tracking ID"
                value={permit.application?.tracking_id ?? null}
                to={permit.application ? `/applications/${permit.application.id}` : undefined}
              />
            </div>

            <div className="mt-5 h-1 bg-royal/70" />

            <div className="mt-4">
              <p className="text-[11px] font-bold uppercase tracking-wide text-ink-secondary">Remarks:</p>
              <div className="mt-1 h-16 border border-line" />
            </div>

            <div className="mt-10 grid gap-10 text-center sm:grid-cols-2">
              {signatories.map((s) => (
                <div key={s.role}>
                  {/* Invisible placeholder when unnamed, so every signature line
                      still sits on the same baseline. */}
                  <p className={`text-sm font-bold ${s.name ? 'text-ink' : 'invisible'}`}>{s.name || '.'}</p>
                  <div className="mx-auto w-44 border-b border-ink" />
                  <p className="mt-1.5 text-[11px] font-semibold uppercase tracking-wide text-ink-secondary">
                    {s.role}
                  </p>
                </div>
              ))}
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
