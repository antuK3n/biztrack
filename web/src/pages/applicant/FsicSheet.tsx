import { QRCodeSVG } from 'qrcode.react'
import type { ReactNode } from 'react'
import { formatDate } from '../../lib/format'

/**
 * The BFP's Fire Safety Inspection Certificate, as the Bureau issues it
 * (BFP-QSF-FSED-005), on screen and in print.
 *
 * Laid out from the issued sheet the client sent [4 October 2026] and kept in
 * step with resources/views/pdf/fsic.blade.php, so the website, the Print
 * button and the downloaded PDF are one document. Every number on it is this
 * system's: the FSIC NO. is the permit number, the date is the date of issue,
 * and the tracking ID stands where the paper prints its control number.
 *
 * The QR sits opposite the City's seal, where the paper has the Bureau's: we
 * hold no copy of that seal, and a redrawn one would be a counterfeit of it.
 */
export interface FsicCertificate {
  permit_number: string
  status_label: string | null
  business_name: string | null
  owner_name: string | null
  address: string | null
  barangay: string | null
  city: string | null
  tracking_id: string | null
  ban?: string | null
  signatories: { role: string; name: string | null; action?: string }[]
  fsic_purpose?: 'occupancy' | 'business' | 'other'
  fsic_others?: string | null
  fsic_valid_for?: string
  fsic_description?: string | null
  office_amount_paid?: string | null
  or_number?: string | null
  date_paid?: string | null
  letterhead?: Record<string, string | null> | null
}

const BFP_BLUE = 'text-[#1f4e9c]'

function Check({ on }: { on: boolean }) {
  return (
    <span
      aria-hidden="true"
      className="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center border border-[#1f4e9c] text-[10px] leading-none text-ink"
    >
      {on ? '✔' : ''}
    </span>
  )
}

/** A ruled fill-in line with the form's italic caption under it. */
function Fill({ children, caption, className = '' }: { children: ReactNode; caption?: string; className?: string }) {
  return (
    <div className={className}>
      <p className="min-h-[1.4rem] border-b border-ink px-1 text-center text-sm font-bold text-ink">{children}</p>
      {caption && <p className="mt-0.5 text-center text-[10px] italic text-ink-secondary">{caption}</p>}
    </div>
  )
}

export function FsicSheet({
  cert,
  verifyUrl,
  validFrom,
  validUntil,
}: {
  cert: FsicCertificate
  verifyUrl: string
  validFrom: string | null
  validUntil: string | null
}) {
  const lh = cert.letterhead ?? {}
  const city = /city/i.test(cert.city ?? '') ? cert.city! : `${cert.city || 'Malabon'} City`
  const postal = [cert.address, cert.barangay, city].filter(Boolean).join(', ')
  const purposes: { key: FsicCertificate['fsic_purpose']; label: string }[] = [
    { key: 'occupancy', label: 'For Certificate of Occupancy' },
    { key: 'business', label: 'For Business Permit (New/Renewal)' },
    { key: 'other', label: 'Others' },
  ]

  return (
    <div className="text-ink">
      {/* Head: the City's seal, the Bureau's letterhead centred, the QR opposite. */}
      <header className="grid grid-cols-[auto_1fr_auto] items-start gap-3 sm:gap-5">
        <img src="/malabon-seal.png" alt="" aria-hidden="true" width={64} height={65} className="h-14 w-auto sm:h-16" />
        <div className="text-center text-[11px] leading-snug sm:text-xs">
          <p>Republic of the Philippines</p>
          <p className="font-bold">Department of the Interior and Local Government</p>
          <p className={`text-sm font-bold uppercase tracking-wide sm:text-base ${BFP_BLUE}`}>
            {lh.agency ?? 'Bureau of Fire Protection'}
          </p>
          {(['region', 'district', 'station', 'address', 'contact'] as const).map(
            (k) => lh[k] && <p key={k}>{lh[k]}</p>,
          )}
        </div>
        <div className="flex flex-col items-center gap-1">
          <QRCodeSVG value={verifyUrl} size={80} level="M" />
          <p className="text-[10px] text-ink-muted">Scan to verify</p>
        </div>
      </header>

      {/* FSIC NO. left, date of issue right. */}
      <div className="mt-4 flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
        <p className="flex items-end gap-2">
          <span className="text-base font-bold text-[#c11212]">FSIC NO.</span>
          <span className="tnum min-w-[11rem] border-b border-ink px-1.5 text-sm">{cert.permit_number}</span>
        </p>
        <div className="w-44 text-center">
          <p className="border-b border-ink text-sm">{formatDate(validFrom) || ' '}</p>
          <p className="text-[10px] italic text-ink-secondary">Date</p>
        </div>
      </div>

      <h1 className={`mt-4 text-balance text-center text-xl font-bold uppercase tracking-wide sm:text-2xl ${BFP_BLUE}`}>
        Fire Safety Inspection Certificate
      </h1>
      {cert.status_label && cert.status_label !== 'Active' && (
        <p className="mt-1 text-center text-sm font-bold uppercase tracking-wide text-s-red">{cert.status_label}</p>
      )}

      {/* The three purposes, the applicable one ticked, as the form sets them. */}
      <ul className={`mx-auto mt-2 w-fit space-y-1 text-xs font-bold uppercase ${BFP_BLUE}`}>
        {purposes.map((p) => (
          <li key={p.key} className="flex items-center gap-2">
            <Check on={cert.fsic_purpose === p.key} />
            <span className="sr-only">{cert.fsic_purpose === p.key ? 'Selected: ' : 'Not selected: '}</span>
            {p.label}
            {p.key === 'other' && (
              <span className="min-w-[10rem] border-b border-ink font-normal normal-case text-ink">
                {cert.fsic_others || ' '}
              </span>
            )}
          </li>
        ))}
      </ul>

      <p className="mt-4 text-sm font-bold">TO WHOM IT MAY CONCERN:</p>

      <div className="mt-1.5 space-y-2 text-[13px] leading-relaxed">
        <p className="indent-12 text-justify">
          By virtue of the provisions of RA 9514 otherwise known as the Fire Code of the Philippines of 2008, the
          application for <b>FIRE SAFETY INSPECTION CERTIFICATE</b> of
        </p>
        <Fill caption="(Name of Establishment)">{cert.business_name ?? 'Business removed from register'}</Fill>

        <div className="grid items-start gap-x-3 gap-y-1 sm:grid-cols-[auto_1fr_auto]">
          <span className="pt-0.5">owned and managed by</span>
          <Fill caption="(Name of Owner/Representative)">{cert.owner_name || ' '}</Fill>
          <span className="pt-0.5">with postal address at</span>
        </div>
        <Fill caption="(Address)">{postal || ' '}</Fill>

        <p className="text-justify">
          is hereby <b>GRANTED</b> after said building structure or facility has been duly inspected with the finding
          that it has fully complied with the fire safety and protection requirements of the Fire Code of the
          Philippines of 2008 and its Revised Implementing Rules and Regulations.
        </p>

        <div className="grid items-end gap-x-3 gap-y-1 sm:grid-cols-[auto_1fr]">
          <span className="sm:pl-12">This certification is valid for</span>
          <Fill>{cert.fsic_valid_for ?? 'Issuance of FSIC'}</Fill>
        </div>
        <div className="grid items-start gap-x-3 gap-y-1 sm:grid-cols-[1fr_auto_11rem]">
          <div>
            <p className="min-h-[1.4rem] border-b border-ink px-1 text-xs">
              {[cert.business_name, cert.fsic_description].filter(Boolean).join(' ') || ' '}
            </p>
            <p className="mt-0.5 text-center text-[10px] italic text-ink-secondary">(Description)</p>
          </div>
          <span className="pt-0.5">valid until</span>
          <Fill>{formatDate(validUntil) || ' '}</Fill>
        </div>

        <p className="indent-12 text-justify">
          Violation of Fire Code provisions shall cause this certificate <i>null and void</i> after appropriate
          proceeding and shall hold the owner liable to the penalties provided for by the said Fire Code.
        </p>
      </div>

      {/* Fees bottom-left, the two signatures bottom-right, as the form sets them. */}
      <div className="mt-6 grid gap-6 sm:grid-cols-2">
        <div className="text-[13px]">
          <p className="font-bold">Fire Code Fees:</p>
          <dl className="mt-1 space-y-1">
            {[
              ['Amount Paid', cert.office_amount_paid],
              ['O.R. Number', cert.or_number],
              ['Date', cert.date_paid],
            ].map(([label, value]) => (
              <div key={label} className="grid grid-cols-[6.5rem_1fr] items-end">
                <dt>{label}:</dt>
                <dd className="tnum min-h-[1.25rem] border-b border-ink text-center">{value || ' '}</dd>
              </div>
            ))}
          </dl>
          <p className="mt-3 text-xs">
            Business Account No.: <b className="tnum">{cert.ban || '—'}</b>
          </p>
        </div>

        <div className="space-y-3 text-[13px]">
          {cert.signatories.map((s) => (
            <div key={s.role}>
              <p className="flex items-center gap-2 font-bold uppercase">
                {s.action === 'Approved' && <Check on />}
                {s.action}:
              </p>
              <p className={`mt-3 text-center text-sm font-bold uppercase ${s.name ? '' : 'invisible'}`}>
                {s.name || '.'}
              </p>
              <div className="mx-2 border-b border-ink" />
              <p className="mt-0.5 text-center text-[11px] uppercase">{s.role}</p>
            </div>
          ))}
        </div>
      </div>

      <p className="mx-auto mt-6 max-w-2xl text-center text-xs font-bold italic leading-snug">
        NOTE: &ldquo;This Certificate does not take the place of any license required by law and is not transferable.
        Any change in the use of occupancy of the premises shall require a new certificate.&rdquo;
      </p>
      <p className="mt-2 text-center text-base font-bold tracking-wide">THIS CERTIFICATE SHALL BE POSTED CONSPICUOUSLY</p>
      <p className="mx-auto mt-1 max-w-2xl text-center text-[10px] font-bold leading-snug text-[#c11212]">
        PAALALA: &ldquo;MAHIGPIT NA IPINAGBABAWAL NG PAMUNUAN NG BUREAU OF FIRE PROTECTION SA MGA KAWANI NITO ANG
        MAGBENTA O MAGREKOMENDA NG ANUMANG BRAND NG FIRE EXTINGUISHER&rdquo;
      </p>
      <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
        <span className="border border-ink px-2 py-0.5 text-[11px] font-bold italic">Applicant/Owner&rsquo;s Copy</span>
        <span className="text-sm font-bold sm:text-base">&ldquo;FIRE SAFETY IS OUR MAIN CONCERN&rdquo;</span>
        <span className="hidden w-28 sm:block" />
      </div>
    </div>
  )
}
