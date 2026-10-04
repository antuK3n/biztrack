import { QRCodeSVG } from 'qrcode.react'
import { formatDate } from '../../lib/format'

/**
 * The CPDO's Zoning Clearance (For Business Permit), on screen and in print.
 *
 * Laid out from the issued sheet the client sent [5 October 2026] and kept in
 * step with resources/views/pdf/zoning.blade.php, so the website, the Print
 * button and the downloaded PDF are one document. The ZONING PERMIT NO. is
 * this system's permit number, the date issued is the date of issue, and the
 * type of establishment is the line of business on the face.
 *
 * The QR sits opposite the City's seal, where the paper has the office's own
 * CPDO seal: we hold no copy of it, and a redrawn one would be a counterfeit.
 */
export interface ZoningCertificate {
  permit_number: string
  status_label: string | null
  business_name: string | null
  address: string | null
  barangay: string | null
  city: string | null
  line_of_business: string | null
  tracking_id: string | null
  ban?: string | null
  signatories: { role: string; name: string | null }[]
  letterhead?: Record<string, string | null> | null
}

function Line({ label, value, big = false }: { label: string; value: string; big?: boolean }) {
  return (
    <div className="flex flex-wrap items-end gap-x-2 gap-y-0.5 sm:flex-nowrap">
      <span className="shrink-0 text-[13px]">{label}</span>
      <span
        className={`min-h-[1.4rem] w-full flex-1 border-b border-ink px-1.5 font-serif font-bold uppercase sm:w-auto ${
          big ? 'text-base sm:text-lg' : 'text-[13px]'
        }`}
      >
        {value || ' '}
      </span>
    </div>
  )
}

export function ZoningSheet({
  cert,
  verifyUrl,
  validFrom,
  validUntil,
}: {
  cert: ZoningCertificate
  verifyUrl: string
  validFrom: string | null
  validUntil: string | null
}) {
  const lh = cert.letterhead ?? {}
  const city = /city/i.test(cert.city ?? '') ? cert.city! : `${cert.city || 'Malabon'} City`
  const address = [cert.address, cert.barangay, city].filter(Boolean).join(', ')
  const administrator = cert.signatories[0] ?? {
    role: 'City Planning & Dev’t Coordinator / Zoning Administrator',
    name: null,
  }

  return (
    <div className="relative overflow-hidden text-ink">
      {/* The City's seal, faint, behind the sheet — as the paper carries its office's. */}
      <img
        src="/malabon-seal.png"
        alt=""
        aria-hidden="true"
        className="pointer-events-none absolute left-1/2 top-1/2 w-3/5 max-w-md -translate-x-1/2 -translate-y-1/3 opacity-[0.07] [print-color-adjust:exact]"
      />

      <div className="relative">
        <header className="grid grid-cols-[auto_1fr_auto] items-start gap-3 sm:gap-5">
          <img src="/malabon-seal.png" alt="" aria-hidden="true" width={72} height={73} className="h-14 w-auto sm:h-[4.5rem]" />
          <div className="pt-2 text-center font-serif leading-snug sm:pt-3">
            <p className="text-xs sm:text-sm">{lh.republic ?? 'Republic of the Philippines'}</p>
            <p className="text-sm font-bold uppercase sm:text-base">{lh.city ?? 'City of Malabon'}</p>
            <p className="text-xs font-bold uppercase sm:text-sm">{lh.office ?? 'City Planning & Development Office'}</p>
          </div>
          <div className="flex flex-col items-center gap-1">
            <QRCodeSVG value={verifyUrl} size={80} level="M" />
            <p className="text-[10px] text-ink-muted">Scan to verify</p>
          </div>
        </header>

        <h1 className="mt-7 text-center font-serif text-2xl font-bold underline underline-offset-4 sm:text-[1.7rem]">
          ZONING CLEARANCE
        </h1>
        <p className="mt-0.5 text-center font-serif text-sm font-bold">(For Business Permit)</p>
        {cert.status_label && cert.status_label !== 'Active' && (
          <p className="mt-1 text-center text-sm font-bold uppercase tracking-wide text-s-red">{cert.status_label}</p>
        )}

        <div className="mt-6 space-y-3 border-[1.5px] border-ink px-4 py-5 sm:px-6">
          <Line label="BUSINESS NAME:" value={cert.business_name ?? 'Business removed from register'} big />
          <Line label="TYPE OF ESTABLISHMENT:" value={cert.line_of_business ?? ''} />
          <Line label="ADDRESS:" value={address} />
          <div className="grid gap-3 sm:grid-cols-2">
            <Line label="ZONING PERMIT NO.:" value={cert.permit_number} />
            <Line label="DATE ISSUED:" value={formatDate(validFrom) ?? ''} />
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <Line label="VALID UNTIL:" value={formatDate(validUntil) ?? ''} />
            <Line label="BUSINESS ACCOUNT NO.:" value={cert.ban ?? ''} />
          </div>
          <p className="pt-1 text-[13px]">
            DECISION &ndash; <b className="font-serif">ZONING CLEARANCE GRANTED</b>
          </p>
        </div>

        <p className="ml-0 mt-7 text-[13px] font-bold sm:ml-6">CONDITIONS:</p>
        <ul className="ml-2 mt-2 space-y-1.5 text-[13px] leading-snug sm:ml-11 sm:mr-6">
          {[
            'All conditions stipulated herein form part of this clearance and are subject to monitoring;',
            'No activity other than applied for shall be conducted within the project site;',
            'No major expansion, alteration and/or improvement shall be introduced without prior clearance from this Office;',
            'Any misrepresentation, false statements or allegations material to the issuance of this decision shall be sufficient cause of its revocation.',
          ].map((c) => (
            <li key={c} className="flex gap-2">
              <span aria-hidden="true" className="text-xs">✔</span>
              <span>{c}</span>
            </li>
          ))}
        </ul>

        <div className="mx-auto mt-16 max-w-md text-center sm:mt-20">
          <p className={`font-serif text-sm font-bold uppercase ${administrator.name ? '' : 'invisible'}`}>
            {administrator.name || '.'}
          </p>
          <div className="border-b border-ink" />
          <p className="mt-1 font-serif text-xs font-bold">{administrator.role}</p>
        </div>

        <p className="mt-16 text-center font-serif text-sm sm:mt-20">(This must be displayed within public view)</p>
      </div>
    </div>
  )
}
