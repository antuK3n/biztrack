import { QRCodeSVG } from 'qrcode.react'
import type { ReactNode } from 'react'
import { formatDate } from '../../lib/format'

/**
 * Malabon's Certificate of Occupancy, NBC Form B-13, on screen and in print.
 *
 * The City's own two-page spread in its blue riveted frame [client, 5 October
 * 2026], kept in step with resources/views/pdf/occupancy.blade.php so the
 * website, the Print button and the downloaded PDF are one document. Left:
 * what was submitted, the requirements verified, and the recommendation.
 * Right: the certificate. The two pages sit side by side from `md` and stack
 * on a phone, where two columns of this text would be unreadable.
 *
 * Every value is this system's: No. is the permit number, the fee is the
 * OBO's share of the bill, the building permit number and date are the OBO's
 * own entries, and the Zoning and Fire Safety lines carry the certificates
 * issued on the same filing.
 */
export interface OccupancyCertificate {
  permit_number: string
  status_label: string | null
  business_name: string | null
  owner_name: string | null
  address: string | null
  barangay: string | null
  city: string | null
  signatories: { role: string; name: string | null }[]
  date_submitted?: string | null
  occ_project?: string | null
  occ_use?: string | null
  occ_area?: string | null
  occ_building_permit_no?: string | null
  occ_building_permit_date?: string | null
  occ_zoning_no?: string | null
  occ_fire_no?: string | null
  office_amount_paid?: string | null
  or_number?: string | null
  date_paid?: string | null
}

function Panel({ children }: { children: ReactNode }) {
  return (
    <div className="border-[9px] border-[#2a5db0] bg-white p-1 [print-color-adjust:exact]">
      <div className="relative h-full border-[1.5px] border-ink px-4 py-5 sm:px-6">
        {(['left-1 top-1', 'right-1 top-1', 'bottom-1 left-1', 'bottom-1 right-1'] as const).map((pos) => (
          <span key={pos} aria-hidden="true" className={`absolute ${pos} h-2.5 w-2.5 rounded-full bg-[#6b5434]`} />
        ))}
        {children}
      </div>
    </div>
  )
}

function Box() {
  return <span aria-hidden="true" className="inline-block h-3 w-3 shrink-0 border border-ink" />
}

function Blank({ value, className = '' }: { value?: string | null; className?: string }) {
  return (
    <span className={`inline-block min-h-[1.1rem] border-b border-ink px-1 text-center font-bold ${className}`}>
      {value || ' '}
    </span>
  )
}

export function OccupancySheet({
  cert,
  verifyUrl,
  validFrom,
}: {
  cert: OccupancyCertificate
  verifyUrl: string
  validFrom: string | null
}) {
  const city = /city/i.test(cert.city ?? '') ? cert.city! : `${cert.city || 'Malabon'} City`
  const located = [cert.address, cert.barangay, city].filter(Boolean).join(', ')
  const official = cert.signatories[0] ?? { role: 'Building Official', name: null }
  const requirements: [string, string | null | undefined][] = [
    ['Locational/Zoning of Land Use', cert.occ_zoning_no],
    ['Line and Grade (Geodetic)', null],
    ['Architectural', null],
    ['Civil/Structural', null],
    ['Electrical', null],
    ['Mechanical', null],
    ['Sanitary', null],
    ['Plumbing', null],
    ['Electronics', null],
    ['Interior Design', null],
    ['Accessibility', null],
    ['Fire Safety', cert.occ_fire_no],
  ]

  return (
    <div className="grid gap-3 text-ink md:grid-cols-2">
      {/* ── Left page ───────────────────────────────────────────────── */}
      <Panel>
        <p className="ml-3 text-xs tracking-wide">NBC FORM NO. B-13</p>
        <p className="mt-2 text-center text-[13px] font-bold tracking-wide">SUBMITTED THE FOLLOWING AS REQUIRED</p>
        <div className="mx-auto mt-2 w-56 text-center">
          <p className="min-h-[1.2rem] border-b border-ink text-xs font-bold">{cert.date_submitted || ' '}</p>
          <p className="text-[10px] tracking-wide">DATE SUBMITTED</p>
        </div>

        <ul className="mt-4 grid gap-1.5 text-[10px] sm:grid-cols-[auto_auto] sm:justify-between">
          <li className="flex items-center gap-1.5 whitespace-nowrap"><Box /> CERTIFICATE OF COMPLETION</li>
          <li className="flex items-center gap-1.5 whitespace-nowrap"><Box /> AS-BUILT PLANS/SPECIFICATIONS</li>
          <li className="flex items-center gap-1.5 whitespace-nowrap"><Box /> DAILY CONSTRUCTION WORKS LOGBOOK</li>
          <li className="flex items-center gap-1.5 whitespace-nowrap"><Box /> (SPECIFY) <Blank className="w-28" /></li>
        </ul>

        <p className="mt-4 indent-6 text-[11px] leading-loose">
          A CERTIFICATE OF COMPLETION WAS SUBMITTED BY <Blank className="w-36" /> <span className="text-[9px]">(Name)</span>{' '}
          A DULY LICENSED <Blank className="w-36" /> <span className="text-[9px]">(Professional)</span> HIRED BY THE OWNER
          WHO UNDERTOOK THE FULL-TIME INSPECTION AND SUPERVISION OF CONSTRUCTION WORKS IN ACCORDANCE WITH SECTION 308,
          CHAPTER 3 OF THE NATIONAL BUILDING CODE, (PD 1096).
        </p>

        <p className="mt-5 text-balance text-center text-[13px] font-bold">VERIFIED &amp; COMPLIED AS TO THE FOLLOWING REQUIREMENTS</p>
        <div className="mt-2 grid grid-cols-2 gap-x-3 gap-y-3 sm:grid-cols-3">
          {requirements.map(([label, value]) => (
            <div key={label} className="text-center">
              <p className="tnum min-h-[1.2rem] border-b border-ink text-[11px] font-bold">{value || ' '}</p>
              <p className="mt-0.5 min-h-[1.6rem] text-[10px] font-bold uppercase leading-tight">{label}</p>
            </div>
          ))}
          <div className="text-center sm:col-start-2">
            <p className="min-h-[1.2rem] border-b border-ink" />
            <p className="mt-0.5 text-[10px] font-bold">OTHERS (SPECIFY)</p>
          </div>
        </div>

        <p className="mt-6 indent-6 text-justify text-[11px] leading-loose">
          THE CONSTRUCTION/ERECTION OF THE BUILDING/STRUCTURE COVERED BY BUILDING PERMIT NO.{' '}
          <Blank value={cert.occ_building_permit_no} className="tnum min-w-[7rem] indent-0" /> ISSUED ON{' '}
          <Blank value={cert.occ_building_permit_date} className="min-w-[7rem] indent-0" /> HAS BEEN COMPLETED, FINALLY
          INSPECTED AND THE REQUIREMENTS REVIEWED AND FOUND SUBSTANTIALLY SATISFACTORY COMPLIED, THEREFORE THE{' '}
          <b>&ldquo;CERTIFICATE OF OCCUPANCY&rdquo;</b> IS HEREBY RECOMMENDED FOR ISSUANCE.
        </p>
      </Panel>

      {/* ── Right page: the certificate ─────────────────────────────── */}
      <Panel>
        <header className="grid grid-cols-[auto_1fr] items-center gap-3">
          <img src="/malabon-seal.png" alt="" aria-hidden="true" width={64} height={65} className="h-14 w-auto sm:h-16" />
          <div className="text-center sm:pr-10">
            <p className="text-[10px] tracking-wide">REPUBLIC OF THE PHILIPPINES</p>
            <p className="text-xl tracking-wide sm:text-2xl">CITY OF MALABON</p>
            <p className="text-xs tracking-wide sm:text-sm">OFFICE OF THE BUILDING OFFICIAL</p>
          </div>
        </header>
        <h1 className="mt-2 text-balance text-center text-xl font-bold tracking-wide text-[#1b2f6e] lg:whitespace-nowrap lg:text-[1.6rem]">
          CERTIFICATE OF OCCUPANCY
        </h1>
        {cert.status_label && cert.status_label !== 'Active' && (
          <p className="mt-1 text-center text-sm font-bold uppercase tracking-wide text-s-red">{cert.status_label}</p>
        )}

        <div className="mt-3 flex items-start justify-center gap-4">
          <dl className="space-y-1 text-[11px]">
            {[
              ['NO.', cert.permit_number],
              ['FEE PAID', cert.office_amount_paid],
              ['OR. NO.', cert.or_number],
              ['DATE PAID', cert.date_paid],
            ].map(([label, value]) => (
              <div key={label} className="grid grid-cols-[4.5rem_10rem] items-end">
                <dt>{label}</dt>
                <dd className="tnum min-h-[1.1rem] border-b border-ink text-center font-bold">{value || ' '}</dd>
              </div>
            ))}
          </dl>
          <div className="flex flex-col items-center gap-1">
            <QRCodeSVG value={verifyUrl} size={72} level="M" />
            <p className="text-[10px] text-ink-muted">Scan to verify</p>
          </div>
        </div>
        <div className="ml-auto mt-2 w-44 text-center">
          <p className="min-h-[1.1rem] border-b border-ink text-xs font-bold">{formatDate(validFrom) || ' '}</p>
          <p className="text-[10px] tracking-wide">DATE ISSUED</p>
        </div>

        <p className="mt-3 indent-6 text-justify text-[11px] leading-relaxed">
          THIS <b>CERTIFICATE OF OCCUPANCY</b> IS ISSUED/GRANTED PURSUANT TO SECTION 309 OF THE NATIONAL BUILDING CODE
          (PD 1096).
        </p>

        <div className="mt-2 space-y-1.5 text-[11px]">
          <p className="flex items-end gap-2">
            <span className="shrink-0">NAME/OWNER</span>
            <Blank value={cert.owner_name?.toUpperCase()} className="flex-1" />
          </p>
          <p className="flex items-end gap-2">
            <span className="shrink-0">NAME OF PROJECT</span>
            <Blank value={(cert.occ_project ?? cert.business_name)?.toUpperCase()} className="flex-1" />
          </p>
          <p className="flex flex-wrap items-end gap-2 sm:flex-nowrap">
            <span className="shrink-0">USE OR CHARACTER OF OCCUPANCY</span>
            <Blank value={cert.occ_use?.toUpperCase()} className="min-w-[6rem] flex-1" />
            <span className="shrink-0">AREA</span>
            <Blank value={cert.occ_area} className="w-24" />
          </p>
          <p className="flex items-end gap-2">
            <span className="shrink-0">LOCATED AT/ALONG</span>
            <Blank value={located.toUpperCase()} className="flex-1" />
          </p>
        </div>

        <div className="mt-3 space-y-2 text-justify text-[10px] leading-snug">
          <p className="indent-6">
            THE OWNER SHALL PROPERLY MAINTAIN THE BUILDING/STRUCTURE TO ENHANCE ARCHITECTURAL WELL BEING, STRUCTURAL
            STABILITY, ELECTRICAL, MECHANICAL, SANITATION, PLUMBING, ELECTRONICS, INTERIOR DESIGN AND FIRE-PROTECTIVE
            PROPERTIES AND SHALL NOT BE OCCUPIED OR USED FOR PURPOSES OTHER THAN ITS INTENDED USE AS STATED ABOVE.
          </p>
          <p className="indent-6">
            THE ARCHITECT OR ENGINEER WHO DREW UP THE PLANS AND SPECIFICATIONS FOR THE BUILDING/STRUCTURE IS AWARE THAT
            UNDER ARTICLE 1723 OF THE CIVIL CODE OF THE PHILIPPINES, HE IS RESPONSIBLE FOR DAMAGES IF WITHIN FIFTEEN (15)
            YEARS FROM THE COMPLETION OF THE BUILDING/STRUCTURE, THE SAME SHOULD COLLAPSE DUE TO DEFECT IN THE PLANS OR
            SPECIFICATIONS OR DEFECTS IN THE GROUND. HE IS THEREFORE ENJOINED TO CONDUCT ANNUAL INSPECTIONS OF THE
            STRUCTURE TO ENSURE THAT THE CONDITIONS UNDER WHICH THE STRUCTURE WAS DESIGNED ARE NOT BEING VIOLATED OR
            ABUSED.
          </p>
          <p className="indent-6">
            THE BUILDING/STRUCTURE SHALL BE SUBJECT TO ANNUAL INSPECTION AND ISSUANCE OF A CERTIFICATE OF OCCUPANCY FOR A
            PERIOD OF ONE (1) YEAR FROM THE DATE OF ISSUANCE OF CERTIFICATE AND YEARLY THEREAFTER.
          </p>
          <p className="indent-6">
            A CERTIFIED COPY HEREOF SHALL BE POSTED WITHIN THE PREMISES OF THE BUILDING AND SHALL NOT BE REMOVED WITHOUT
            AUTHORITY FROM THE BUILDING OFFICIAL.
          </p>
        </div>

        <div className="ml-auto mt-8 w-60 text-center">
          <p className={`text-sm font-bold uppercase ${official.name ? '' : 'invisible'}`}>{official.name || '.'}</p>
          <div className="border-b border-ink" />
          <p className="mt-0.5 text-[11px] font-bold uppercase tracking-wide">{official.role}</p>
        </div>
      </Panel>
    </div>
  )
}
