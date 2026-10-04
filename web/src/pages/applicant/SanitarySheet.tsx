import { QRCodeSVG } from 'qrcode.react'
import { formatDate } from '../../lib/format'

/**
 * The CHO's Sanitary Permit to Operate, on screen and in print.
 *
 * Laid out from the issued sheet the client sent [5 October 2026] and kept in
 * step with resources/views/pdf/sanitary.blade.php, so the website, the Print
 * button and the downloaded PDF are one document. The SANITARY PERMIT NO. is
 * this system's permit number, the date of expiration is the permit's
 * valid-until, and the type of establishment is the CHO sheet's Sanitary
 * Classification (the line of business when the sheet was never answered).
 */
export interface SanitaryCertificate {
  permit_number: string
  status_label: string | null
  business_name: string | null
  owner_name: string | null
  address: string | null
  barangay: string | null
  city: string | null
  line_of_business: string | null
  tracking_id: string | null
  ban?: string | null
  signatories: { role: string; name: string | null; action?: string }[]
  sanitary_classification?: string | null
  letterhead?: Record<string, string | null> | null
}

function Fill({ children, caption, wide = false }: { children: string; caption?: string; wide?: boolean }) {
  return (
    <div className="min-w-0 flex-1">
      <p
        className={`min-h-[1.4rem] border-b border-ink px-1 text-center font-serif font-bold uppercase ${
          wide ? 'text-[15px]' : 'text-[13px]'
        }`}
      >
        {children || ' '}
      </p>
      {caption && <p className="mt-0.5 text-center text-[11px]">{caption}</p>}
    </div>
  )
}

function Row({ label, italic = false, children }: { label: string; italic?: boolean; children: React.ReactNode }) {
  return (
    <div className="flex flex-wrap items-end gap-x-3 gap-y-1 sm:flex-nowrap">
      <span className={`shrink-0 pb-0.5 text-[13px] ${italic ? 'italic' : ''}`}>{label}</span>
      {children}
    </div>
  )
}

export function SanitarySheet({
  cert,
  verifyUrl,
  validFrom,
  validUntil,
}: {
  cert: SanitaryCertificate
  verifyUrl: string
  validFrom: string | null
  validUntil: string | null
}) {
  const lh = cert.letterhead ?? {}
  const city = /city/i.test(cert.city ?? '') ? cert.city! : `${cert.city || 'Malabon'} City`
  const address = [cert.address, cert.barangay, city].filter(Boolean).join(', ')
  const type = cert.sanitary_classification || cert.line_of_business || ''

  return (
    <div className="text-ink">
      <header className="grid grid-cols-[auto_1fr_auto] items-start gap-3 sm:gap-5">
        <img src="/malabon-seal.png" alt="" aria-hidden="true" width={80} height={81} className="h-16 w-auto sm:h-20" />
        <div className="pt-1 text-center sm:pt-2">
          <p className="text-xs font-bold uppercase sm:text-sm">{lh.republic ?? 'Republic of the Philippines'}</p>
          <p className="text-xs font-bold uppercase sm:text-sm">{lh.city ?? 'City of Malabon'}</p>
          <p className="mt-1 font-serif text-base uppercase sm:text-lg">{lh.office ?? 'City Health Office'}</p>
        </div>
        <div className="flex flex-col items-center gap-1">
          <QRCodeSVG value={verifyUrl} size={80} level="M" />
          <p className="text-[10px] text-ink-muted">Scan to verify</p>
        </div>
      </header>

      <h1 className="mt-7 text-balance text-center text-2xl font-bold tracking-wide sm:text-[2rem]">
        SANITARY PERMIT TO OPERATE
      </h1>
      {cert.status_label && cert.status_label !== 'Active' && (
        <p className="mt-1 text-center text-sm font-bold uppercase tracking-wide text-s-red">{cert.status_label}</p>
      )}

      <div className="mt-8 space-y-5">
        <Row label="ISSUED TO" italic>
          <Fill caption="(Registered Name)">{cert.owner_name ?? ''}</Fill>
        </Row>
        <Fill caption="(Name of Establishment)" wide>
          {cert.business_name ?? 'Business removed from register'}
        </Fill>
        <Row label="Type of Establishment">
          <Fill>{type}</Fill>
        </Row>
        <Row label="ADDRESS" italic>
          <Fill>{address}</Fill>
        </Row>
        <div className="grid gap-5 sm:grid-cols-2">
          <Row label="Sanitary Permit No.">
            <Fill>{cert.permit_number}</Fill>
          </Row>
          <Row label="Date Issued">
            <Fill>{formatDate(validFrom) ?? ''}</Fill>
          </Row>
        </div>
        <div className="grid gap-5 sm:grid-cols-2">
          <Row label="Date of Expiration">
            <Fill>{formatDate(validUntil) ?? ''}</Fill>
          </Row>
          <Row label="Business Account No.">
            <Fill>{cert.ban ?? ''}</Fill>
          </Row>
        </div>
      </div>

      <p className="mt-9 text-[13px] leading-relaxed">
        THIS PERMIT IS NOT TRANSFERABLE AND WILL BE REVOKED FOR VIOLATION OF THE SANITARY RULES, LAWS OR REGULATION
        OF P.D. 522/P.D. 856 (CODE ON SANITATION OF THE PHILIPPINES) AND PERTINENT LOCAL ORDINANCES OF THE CITY OF
        MALABON.
      </p>

      {/* Stepped right, Recommending then Approved further over, as the paper sets them. */}
      <div className="mt-10 space-y-8">
        {cert.signatories.map((s, i) => (
          <div
            key={s.role}
            className={`flex flex-wrap items-end gap-x-3 gap-y-1 sm:flex-nowrap ${i === 0 ? 'sm:pl-16' : 'sm:pl-36'}`}
          >
            <span className="w-full shrink-0 whitespace-nowrap pb-5 text-[13px] italic sm:w-44 sm:text-right">{s.action}</span>
            <div className="w-full max-w-xs text-center">
              <p className={`font-serif text-sm font-bold uppercase ${s.name ? '' : 'invisible'}`}>{s.name || '.'}</p>
              <div className="border-b border-ink" />
              <p className="mt-0.5 text-[13px]">{s.role}</p>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
