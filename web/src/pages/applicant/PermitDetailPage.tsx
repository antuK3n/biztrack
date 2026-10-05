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
import type { Permit } from '../../lib/types'
import { FsicSheet } from './FsicSheet'
import { OccupancySheet } from './OccupancySheet'
import { SanitarySheet } from './SanitarySheet'
import { ZoningSheet } from './ZoningSheet'

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
  signatories: { role: string; name: string | null; action?: string }[]
  verify_url: string
  /*
   * ── The Mayor's Permit prints the City's own form ────────────────────────
   *
   * The seven below are sent only when `is_business_permit`, because they are
   * only on that sheet: the BPLO form photographed at the counter [client,
   * 4 October 2026] heads with a Business Account Number and a Mayor's Permit
   * Number, asks the floor area and headcount beside the issue date, and
   * carries the receipt along the fee line. A clearance has none of that — its
   * fee was assessed against the filing, not against it.
   */
  is_business_permit?: boolean
  ban?: string | null
  area_sqm?: string | null
  employees?: string | null
  amount_paid?: string | null
  or_number?: string | null
  date_paid?: string | null
  /*
   * ── CENRO's Certificate of Environment Clearance ─────────────────────────
   *
   * Its own sheet too [client, 4 October 2026]: the office's letterhead at the
   * head, the business named in the middle of the page, a compliance clause,
   * one signature, and a receipt block bottom-left. `office_amount_paid` is
   * CENRO's share of the filing's bill, not the total — see the controller.
   */
  is_cenro_certificate?: boolean
  office_amount_paid?: string | null
  letterhead?: Record<string, string | null> | null
  /*
   * ── The BFP's Fire Safety Inspection Certificate ────────────────────────
   *
   * Its own sheet, drawn by FsicSheet [client, 4 October 2026]. The BFP's share
   * of the bill rides in `office_amount_paid`, as CENRO's does.
   */
  is_fsic?: boolean
  fsic_purpose?: 'occupancy' | 'business' | 'other'
  fsic_others?: string | null
  fsic_valid_for?: string
  fsic_description?: string | null
  /** The CPDO's Zoning Clearance, drawn by ZoningSheet [client, 5 October 2026]. */
  is_zoning?: boolean
  /** The CHO's Sanitary Permit to Operate, drawn by SanitarySheet [client, 5 October 2026]. */
  is_sanitary?: boolean
  sanitary_classification?: string | null
  /** Malabon's Certificate of Occupancy (NBC Form B-13), drawn by OccupancySheet. */
  is_occupancy?: boolean
  date_submitted?: string | null
  occ_project?: string | null
  occ_use?: string | null
  occ_area?: string | null
  occ_building_permit_no?: string | null
  occ_building_permit_date?: string | null
  occ_zoning_no?: string | null
  occ_fire_no?: string | null
}

/**
 * A ruled box with its caption outside it, as the paper draws them.
 *
 * The caption sits to the LEFT on the wide rows and ABOVE on the short ones,
 * which is how the form itself is set: NAME OF OWNER runs the width of the
 * sheet, while DATE OF ISSUE, AREA and EMPLOYEES share a line in thirds.
 *
 * An empty box is drawn, never collapsed. The City's form has a ruled line for
 * every field whether or not the counter filled it, and a certificate that
 * silently drops a row cannot be read against the paper it copies.
 */
function PaperField({
  label,
  value,
  stacked = false,
}: {
  label: string
  value: string | null | undefined
  stacked?: boolean
}) {
  /*
   * Sized to the City's pad, not to the clearance sheet. On the photographed
   * form the captions are about a tenth taller than the values and the rows
   * sit nearly touching; at the clearance sizes the same fields read as a
   * form shrunk to fit — "ang liliit ng font, ang lalaki ng spacing".
   */
  if (stacked) {
    return (
      <div className="min-w-0">
        <p className="text-[11px] font-bold uppercase tracking-wide text-ink sm:text-xs">{label}</p>
        <p className="mt-1 min-h-[2rem] truncate border border-ink/70 px-2.5 py-1.5 text-sm font-bold text-ink sm:text-base">
          {value || ' '}
        </p>
      </div>
    )
  }

  return (
    <div className="flex items-center gap-3">
      <p className="w-32 shrink-0 text-[11px] font-bold uppercase tracking-wide text-ink sm:w-44 sm:text-[13px]">
        {label}
      </p>
      <p className="min-h-[2.1rem] min-w-0 flex-1 truncate border border-ink/70 px-3 py-1.5 text-sm font-bold text-ink sm:text-base">
        {value || ' '}
      </p>
    </div>
  )
}

function CertField({
  label,
  value,
  absent,
  to,
}: {
  label: string
  value: string | null
  /** What the box says when there is no value. Defaults to an em dash. */
  absent?: string
  /** Turns the value into a link. Used to walk back to the filing behind the permit. */
  to?: string
}) {
  /*
   * Wraps rather than truncates. This is a certificate, and a business that
   * declared three lines of business had the third and part of the second
   * replaced by an ellipsis — on the document that is supposed to say what the
   * permit covers. A taller box is the right trade against a shorter truth.
   */
  const box = 'min-w-0 break-words border border-line bg-royal-tint px-2.5 py-1 text-sm'
  const empty = value === null || value === ''
  // Greyed and italic when empty, so a box with nothing in it never reads as a
  // value that failed to render.
  const tone = empty ? 'italic text-ink-muted' : 'text-ink'
  const text = empty ? (absent ?? '—') : value

  /*
   * ── A grid, because a flex row broke this document twice ────────────────
   *
   * It was `flex items-baseline gap-3` with a `shrink-0` label and a `flex-1`
   * box, and that failed at both ends:
   *
   *  - ON A PHONE the label took the width it wanted and the box collapsed to
   *    what was left, so `break-words` broke inside the words. The permit
   *    number rendered one character per line — M, C, B, -, 2, 0, 2, 6 down
   *    the page — and so did the date of issue. On a certificate somebody
   *    holds up to an inspector.
   *  - ON A DESKTOP each label was a different length, so every value box
   *    started at a different x: 487px, 484px, 462px, 502px. A government
   *    form has one left edge for its values, and this had five.
   *
   * One fixed label column fixes both. Below `sm` the label sits on its own
   * line above the value, which is what gives the box the whole width back.
   */
  return (
    <div className="grid grid-cols-1 items-baseline gap-x-3 gap-y-0.5 sm:grid-cols-[10.5rem_minmax(0,1fr)]">
      <span className="text-[11px] font-bold uppercase leading-snug tracking-wide text-ink-secondary">
        {label}
      </span>
      {to && !empty ? (
        <Link
          to={to}
          className={`${box} font-semibold text-royal underline underline-offset-2 hover:no-underline print:no-underline`}
        >
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
  /*
   * ── Sanction, as distinct from a date passing ────────────────────────
   *
   * `expired` above is a date going by and nothing anybody decided. These
   * two are decisions, and they are the ones the PDF already stamps across
   * its face — see `resources/views/pdf/permit.blade.php`, which prints any
   * status but Active in red. This screen printed nothing for either, so a
   * suspended certificate looked fine here and suspended in the download.
   */
  const suspended = permit.status === 'suspended'
  const revoked = permit.status === 'revoked'
  /*
   * ── Replaced by a renewal, and the page has to say so ───────────────────
   *
   * `superseded` is what every renewal leaves behind, and this screen printed
   * nothing for it — so last year's certificate opened looking exactly like a
   * live one, with last year's VALID UNTIL on its face. The client read it as
   * the renewal having failed: *"I just renewed that sanitary form and the
   * expiration date should be Oct. 4, 2027"* [4 October 2026], on
   * `/permits/159` — the superseded one, while the permit they had just been
   * issued sat at a different id with the right dates.
   *
   * The PDF has always stamped it: `permit.blade.php` prints any status but
   * Active in red. This is the screen catching up, the same gap the suspended
   * and revoked notices above were added to close.
   */
  const superseded = permit.status === 'superseded'
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
  /*
   * As the API built it — City Mayor and Officer-in-Charge first, then the
   * issuing office's own names. This used to fall back to a literal pair of
   * nameless captions when no office signatories were configured, which was
   * every office but CENRO; both roles now come down named (see
   * PermitFace::signatureBlock), so the literal is gone rather than left to
   * print a duplicate line. `?? []` is the loading state, not a fallback.
   */
  const signatories = cert?.signatories ?? []

  return (
    /*
      ── The Mayor's Permit is a LANDSCAPE sheet ──────────────────────────────

      The City's pad is wider than it is tall, and the fields are set for that
      width: three ruled boxes across for the date, area and headcount, and the
      fee line running the sheet in one row. At `max-w-3xl` those were being
      squeezed into a portrait column and read nothing like the paper.

      The clearances keep the narrower sheet. They are portrait documents with
      a single column of fields, and widening them would only stretch ten rows
      of label-and-value across a screen.
    */
    <div className={`mx-auto ${cert?.is_business_permit || cert?.is_cenro_certificate || cert?.is_occupancy ? 'max-w-5xl' : 'max-w-3xl'}`}>
      {/*
        Print the same paper the download is on: US Letter, landscape.

        Without this the Print button sends whatever the browser last used —
        usually portrait A4 — so the two routes off this screen produced
        differently shaped certificates of the same permit. `@page` cannot be
        set from a class, which is why it is a tag rather than a utility.
      */}
      {(cert?.is_business_permit || cert?.is_cenro_certificate || cert?.is_occupancy) && (
        <style>{'@media print { @page { size: letter landscape; margin: 0.4in; } }'}</style>
      )}
      {/* The FSIC is a portrait Letter sheet, as the download is. */}
      {(cert?.is_fsic || cert?.is_zoning || cert?.is_sanitary) && <style>{'@media print { @page { size: letter portrait; margin: 0.4in; } }'}</style>}
      {/* Modal-like sheet: royal bar with white X (p59) */}
      <div className="overflow-hidden rounded-md bg-white shadow-overlay print:rounded-none print:shadow-none">
        {/*
          ── The bar says which permit this is ──────────────────────────────

          It was a bare royal band with an X at the right: 40 pixels of colour
          carrying nothing. A reader arriving from a list of six certificates
          had to read down into the document to find out which one they had
          opened, and the only control on the screen was an unlabelled cross.

          The number is the thing to name — it is what an inspector asks for
          and what an owner reads out on the phone.
        */}
        <div className="flex items-center justify-between gap-4 bg-royal px-4 py-2.5 print:hidden">
          {/*
            The NUMBER survives a narrow screen and the type gives way: the
            number is what an inspector asks for and what an owner reads out on
            the phone, so truncating it to "MCB-2026-..." would lose the useful
            half of the line.
          */}
          <p className="flex min-w-0 items-baseline gap-2 text-sm text-white">
            <span className="min-w-0 truncate font-bold">
              {cert?.permit_type_name ?? permit.permit_type.name}
            </span>
            <span className="tnum shrink-0 text-white/80">{permit.permit_number}</span>
          </p>
          {/* Back to the permits page, which is where it was opened from. */}
          <button
            type="button"
            onClick={() => navigate('/permits')}
            aria-label="Close permit view"
            className="shrink-0 rounded text-white transition-opacity hover:opacity-80"
          >
            <XIcon size={22} />
          </button>
        </div>

        {/* The permit "document" */}
        <article
          className={`border-[6px] border-white bg-white px-3 py-5 sm:py-7 print:border-0 print:p-0 ${
            cert?.is_occupancy ? 'sm:px-3' : 'sm:px-10'
          }`}
        >
          {cert?.is_occupancy ? (
            <OccupancySheet cert={cert} verifyUrl={permit.verify_url} validFrom={permit.valid_from} />
          ) : cert?.is_fsic ? (
            <div className="border-2 border-ink/80 px-3.5 py-5 sm:px-8 sm:py-6">
              <FsicSheet
                cert={cert}
                verifyUrl={permit.verify_url}
                validFrom={permit.valid_from}
                validUntil={permit.valid_until}
              />
            </div>
          ) : cert?.is_sanitary ? (
            <div className="border-2 border-ink/80 px-3.5 py-5 sm:px-10 sm:py-7">
              <SanitarySheet
                cert={cert}
                verifyUrl={permit.verify_url}
                validFrom={permit.valid_from}
                validUntil={permit.valid_until}
              />
            </div>
          ) : cert?.is_zoning ? (
            <div className="border-2 border-ink/80 px-3.5 py-5 sm:px-8 sm:py-6">
              <ZoningSheet
                cert={cert}
                verifyUrl={permit.verify_url}
                validFrom={permit.valid_from}
                validUntil={permit.valid_until}
              />
            </div>
          ) : (
          /*
            CENRO's sheet is green [client, 4 October 2026] — a soft tint on
            the ruled frame only, so the margin stays paper-white and the
            small italic clause still reads at AA. `print-color-adjust` keeps
            the tint when printed; browsers drop backgrounds by default.
          */
          <div
            className={`border-2 px-3.5 py-5 sm:px-8 sm:py-6 ${
              cert?.is_cenro_certificate
                ? 'border-[#2e7d4f] bg-[#e8f3ea] [print-color-adjust:exact]'
                : 'border-ink/80'
            }`}
          >
            {/*
              The seal block and the QR sat side by side at every width, so on
              a phone "REPUBLIC OF THE PHILIPPINES" wrapped over four lines in
              a column barely wider than the seal. They stack below `sm`, with
              the QR on its own line where it stays scannable.
            */}
            <header className="flex flex-col items-start gap-4 sm:flex-row sm:justify-between">
              <div>
                {/*
                  Item 95. The BizTrack logo used to sit here, and it had no
                  business on a permit: the document is issued by the city, not
                  by the software that printed it. A vendor mark on a government
                  certificate is the kind of thing that makes a real one look
                  fake and a fake one look plausible.
                  The downloaded PDF never carried it — only this on-screen
                  view did, so the two disagreed about whose document it was.

                  The city seal now sits in that slot, supplied by the client.

                  aria-hidden, and deliberately so: it carries no information a
                  reader needs, and the two lines under it already say Republic
                  of the Philippines and City of Malabon. Announcing "Seal of
                  Malabon" to a screen-reader user before those lines would make
                  them hear the same fact twice, once as an image.
                */}
                <img
                  src="/malabon-seal.png"
                  alt=""
                  aria-hidden="true"
                  width={56}
                  height={57}
                  className="h-14 w-auto"
                />
                <p className="mt-2 text-base font-bold uppercase tracking-wide text-ink">
                  Republic of the Philippines
                </p>
                {cert?.is_cenro_certificate && cert.letterhead ? (
                  /* CENRO's own letterhead, from config/biztrack.php. */
                  <>
                    <p className="max-w-sm text-base font-bold uppercase leading-snug tracking-wide text-ink">
                      {cert.department_name}
                    </p>
                    <p className="mt-1 text-[11px] text-ink-secondary">{cert.letterhead.address}</p>
                    <p className="text-[11px] text-ink-secondary">
                      Trunkline No: {cert.letterhead.trunkline} &nbsp;|&nbsp; Email: {cert.letterhead.email}
                    </p>
                    <p className="text-[11px] text-ink-secondary">Website: {cert.letterhead.website}</p>
                  </>
                ) : (
                  <>
                    <p className="text-base font-bold uppercase tracking-wide text-ink">City of Malabon</p>
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-secondary">
                      {cert?.department_name ?? 'Business Permits and Licensing Office'}
                    </p>
                  </>
                )}
              </div>
              <div className="flex shrink-0 items-start gap-4 self-center sm:self-start">
                {/*
                  The two numbered boxes the City's form heads with, and they
                  are the reason this sheet can be checked against a paper one
                  at a counter: the Business Account Number identifies the
                  payer in the register, the Mayor's Permit Number identifies
                  the certificate.

                  Only on the Mayor's Permit. A clearance has no BAN box on
                  its face, and its number is already printed in the field
                  list below.
                */}
                {cert?.is_business_permit && (
                  <div className="hidden w-52 shrink-0 space-y-2 sm:block">
                    <div>
                      <p className="text-[10px] font-bold uppercase tracking-wide text-ink">
                        Business Account Number
                      </p>
                      <p className="tnum mt-0.5 min-h-[1.75rem] truncate border border-ink/70 px-2.5 py-1 text-[13px] font-bold text-ink">
                        {cert.ban || ' '}
                      </p>
                    </div>
                    <div>
                      <p className="text-[10px] font-bold uppercase tracking-wide text-ink">
                        Mayor&rsquo;s Permit Number
                      </p>
                      <p className="tnum mt-0.5 min-h-[1.75rem] truncate border border-ink/70 px-2.5 py-1 text-[13px] font-bold text-ink">
                        {permit.permit_number}
                      </p>
                    </div>
                  </div>
                )}
                <div className="flex flex-col items-center gap-1.5">
                  <QRCodeSVG value={permit.verify_url} size={92} level="M" />
                  <p className="text-[10px] text-ink-muted">Scan to verify</p>
                </div>
              </div>
            </header>

            {/* The permit type is the document's own title, as it is on paper —
                a fire safety certificate should not be headed BUSINESS PERMIT. */}
            {/* Tighter tracking and a smaller size below `sm`: at 0.18em a
                three-word permit name took three lines of a phone screen
                before the document had said anything. */}
            {cert?.is_cenro_certificate ? (
              /* The office's own title, in the bold sans it prints in. */
              <h1 className="mt-6 text-balance text-center text-xl font-bold uppercase tracking-wide text-ink sm:text-2xl">
                Certificate of Environment Clearance
              </h1>
            ) : (
              <h1 className="display-serif mt-6 text-balance text-center text-2xl uppercase tracking-[0.08em] text-ink sm:text-3xl sm:tracking-[0.18em]">
                {cert?.permit_type_name ?? permit.permit_type.name}
              </h1>
            )}
            {expired && (
              <p className="mt-1 text-center text-sm font-bold uppercase tracking-wide text-s-red">
                Expired
              </p>
            )}
            {/*
              ── A suspension says what to DO about it ─────────────────────

              Not just the word. An owner arriving here has been told their
              Mayor's Permit is suspended and the only question they have is
              how to get it back — so the route is on the screen rather than
              in the notification they have already scrolled past.

              The cause is deliberately NOT named here. A suspension can
              follow a refused clearance, which the clearance page explains
              per permit with the office's own words, and it may in future
              follow an enforcement decision, which has no page yet. Naming
              one cause on a screen that cannot know which it was would be
              wrong half the time; pointing at the page that CAN say is right
              either way.

              Revoked gets the word and no route, because there is no route:
              `PermitStatus::Revoked` has no writer and no way back, and
              offering a button that fixes nothing is worse than silence.
            */}
            {suspended && (
              <div className="mx-auto mt-3 max-w-xl rounded-md border border-s-red bg-s-red-tint px-4 py-3 print:hidden">
                <p className="text-center text-sm font-bold uppercase tracking-wide text-s-red">
                  Suspended
                </p>
                <p className="mt-1.5 text-center text-xs leading-relaxed text-ink-secondary">
                  This permit does not verify while it is suspended — anyone scanning the QR
                  code is told so. If one of your other permits was rejected, apply for it
                  again and this permit is restored as soon as that office approves it.
                </p>
                {permit.application !== null && (
                  <p className="mt-2 text-center">
                    <Link
                      to={`/applications/${permit.application.id}/clearances`}
                      className="text-xs font-semibold text-royal underline underline-offset-2 hover:no-underline"
                    >
                      See your other permits on {permit.application.tracking_id}
                    </Link>
                  </p>
                )}
              </div>
            )}
            {revoked && (
              <p className="mt-1 text-center text-sm font-bold uppercase tracking-wide text-s-red">
                Revoked
              </p>
            )}
            {superseded && (
              <div className="mx-auto mt-3 max-w-xl rounded-md border border-line bg-shell px-4 py-3 print:hidden">
                <p className="text-center text-sm font-bold uppercase tracking-wide text-ink-secondary">
                  Replaced by a newer permit
                </p>
                <p className="mt-1.5 text-center text-xs leading-relaxed text-ink-secondary">
                  This is the certificate you held before renewing, kept as your record. The
                  dates below are its own. Your current one is under My Permits.
                </p>
                <p className="mt-2 text-center">
                  <Link
                    to="/permits"
                    className="text-xs font-semibold text-royal underline underline-offset-2 hover:no-underline"
                  >
                    Go to My Permits
                  </Link>
                </p>
              </div>
            )}

            {/*
              -- One column, all the way down ------------------------------

              Four of these used to sit in a two-column row, and with a fixed
              label column inside each half there was only ~135px left for the
              value: "MCB-2026-000001" wrapped after the year, "December 31,
              2026" after the comma, and a wide gap opened between the pairs.
              A certificate reading down one aligned column is both correct and
              quicker to read than two ragged ones.
            */}
            {cert?.is_cenro_certificate ? (
              /*
                ── CENRO's Certificate of Environment Clearance ──────────────

                Laid out from the sheet the office issues [client, 4 October
                2026]. Unlike the two other faces it has no field grid: the
                business is named in the middle of the page, with its trade
                and address underlined beneath, then the compliance clause,
                the issue line, one signature, and a receipt block at the
                foot. The office letterhead replaces the city block in the
                header, where it is rendered above.
              */
              <>
                <div className="mt-6 text-center">
                  <p className="text-base text-ink">{permit.permit_number}</p>
                  <p className="mt-0.5 text-sm text-ink-secondary">is hereby issued to</p>
                  <p className="mt-5 text-2xl font-bold text-ink underline underline-offset-4 sm:text-3xl">
                    {cert.business_name ?? 'Business removed from register'}
                  </p>
                  <p className="mt-2 text-xs font-bold text-ink underline underline-offset-2 sm:text-sm">
                    {cert.line_of_business || '—'} &ndash; with address at{' '}
                    {[cert.address, cert.barangay].filter(Boolean).join(', ') || '—'},{' '}
                    {(/city/i.test(cert.city ?? '') ? cert.city! : `${cert.city || 'Malabon'} City`).toUpperCase()}
                  </p>
                  <p className="mx-auto mt-5 max-w-3xl text-xs italic leading-relaxed text-ink sm:text-sm">
                    This issuance of certificate shall not exempt the grantee from compliance with
                    applicable permits required by DENR and the City Government of Malabon as stated in
                    the application form and in accordance with Article W &ndash; Environmental
                    Protection and Preservation Fees of the City Ordinance A10-2016, The New Revenue
                    Code of the City of Malabon.
                  </p>
                  <p className="mt-5 text-sm italic text-ink">
                    Issued this {formatDate(permit.valid_from)} at the Malabon City Hall.
                  </p>
                </div>
              </>
            ) : cert?.is_business_permit ? (
              /*
                ── The City's own Business Permit form ──────────────────────

                Laid out from the sheet the BPLO issues over the counter
                [client, 4 October 2026]: ruled boxes rather than a label and
                value list, the three short facts sharing one line, the fee
                line under the rule, and REMARKS as a box a hand writes in.

                The Malabon Ahon mark on the paper is left off deliberately —
                the client asked only that the city seal we already hold stays
                and that the details match. A second logo we do not have an
                asset for would print as a gap.
              */
              <>
                <div className="mt-6 grid gap-2">
                  <PaperField label="Name of Owner" value={ownerName} />
                  <PaperField
                    label="Business Name"
                    value={cert.business_name ?? 'Business removed from register'}
                  />
                  <PaperField label="Address" value={address} />
                </div>

                <div className="mt-2.5 grid grid-cols-3 gap-3">
                  <PaperField stacked label="Date of Issue" value={formatDate(permit.valid_from)} />
                  <PaperField stacked label="Area" value={cert.area_sqm} />
                  <PaperField stacked label="Employees" value={cert.employees} />
                </div>

                {/* The paper's own rule, in the city's blue. */}
                <div className="mt-5 h-1.5 rounded-sm bg-gradient-to-r from-royal to-royal/40" />

                <div className="mt-4 space-y-2.5">
                  <p className="flex flex-wrap items-baseline gap-2 text-[13px] font-bold uppercase tracking-wide text-ink">
                    Line of Business:
                    <span className="text-sm font-semibold normal-case tracking-normal text-ink-secondary">
                      {cert.line_of_business || ' '}
                    </span>
                  </p>
                  <div className="flex flex-wrap gap-x-8 gap-y-2 text-[13px] font-bold uppercase tracking-wide text-ink">
                    <p className="flex items-baseline gap-2">
                      Amount Paid:
                      <span className="tnum font-semibold normal-case tracking-normal text-ink-secondary">
                        {cert.amount_paid || '    '}
                      </span>
                    </p>
                    <p className="flex items-baseline gap-2">
                      OR No.:
                      <span className="tnum font-semibold normal-case tracking-normal text-ink-secondary">
                        {cert.or_number || '    '}
                      </span>
                    </p>
                    <p className="flex items-baseline gap-2">
                      Date Paid:
                      <span className="font-semibold normal-case tracking-normal text-ink-secondary">
                        {cert.date_paid || '    '}
                      </span>
                    </p>
                  </div>
                </div>

                {/*
                  Remarks, and on THIS sheet it is on screen as well as in
                  print. The generic certificate hides it on screen because it
                  is furniture; here it is a box the City's form draws and a
                  reader comparing the two would miss it.
                */}
                <div className="mt-4 flex items-start gap-3">
                  <p className="w-32 shrink-0 pt-1 text-[11px] font-bold uppercase tracking-wide text-ink sm:w-44 sm:text-[13px]">
                    Remarks:
                  </p>
                  <div className="h-[4.5rem] flex-1 border border-ink/70" />
                </div>
              </>
            ) : (
              <>
                <div className="mt-6 grid gap-3">
                  <CertField label="Name of Owner" value={ownerName} />
                  <CertField
                    label="Business Name"
                    value={cert ? cert.business_name : permit.business?.name}
                    absent="Business removed from register"
                  />
                  {cert?.trade_name && <CertField label="Trade Name" value={cert.trade_name} />}
                  <CertField label="Business Address" value={address} />
                  {cert?.line_of_business && (
                    <CertField label="Line of Business" value={cert.line_of_business} />
                  )}
                  <CertField label="Permit No." value={permit.permit_number} />
                  <CertField label="Permit Type" value={permit.permit_type.name} />
                  <CertField label="Date of Issue" value={formatDate(permit.valid_from)} />
                  <CertField label="Valid Until" value={formatDate(permit.valid_until)} />
                  {/* Approved filings leave the tracking list; this walks back to one. */}
                  <CertField
                    label="Tracking ID"
                    value={permit.application?.tracking_id ?? null}
                    to={permit.application ? `/applications/${permit.application.id}` : undefined}
                  />
                </div>

                <div className="mt-5 h-1 bg-royal/70" />
              </>
            )}

            {/*
              ── Remarks is paper furniture ─────────────────────────────────

              An empty bordered box, drawn on screen for a hand to write in.
              Nothing can be typed into it and nothing is ever read out of it,
              so on a screen it is two inches of nothing between the permit's
              facts and its signatures — and a reader on a phone scrolls past
              it wondering what they were meant to have filled in.

              It stays for print, where it is what it has always been: the
              space an officer writes a condition into on the issued copy.
            */}
            {/* Generic sheet only: the Mayor's Permit draws its own Remarks box
                on screen (a second one appeared when it was printed), and
                CENRO's certificate has none. */}
            {!cert?.is_business_permit && !cert?.is_cenro_certificate && (
              <div className="mt-4 hidden print:block">
                <p className="text-[11px] font-bold uppercase tracking-wide text-ink-secondary">Remarks:</p>
                <div className="mt-1 h-16 border border-line" />
              </div>
            )}

            {/* One signature centres rather than sitting in the left column of
                a two-up grid — CENRO signs once, with the Chief. */}
            <div
              className={`mt-10 grid gap-10 text-center ${signatories.length > 1 ? 'sm:grid-cols-2' : ''}`}
            >
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

            {/*
              The URL on its OWN line. `break-all` inside the sentence split it
              mid-token — the footer read "…authenticity at htt" / "p://…" —
              which on a document about authenticity looks like a broken link
              rather than a long one.
            */}
            {/*
              The City's own warning, word for word off the paper, and only on
              the sheet that carries it. It is set in red on the form because
              the three sentences are the enforcement terms — display it, it is
              inspectable, and it is void without the receipt — so they are red
              here too. The expiry line follows, as it does on the paper.
            */}
            {cert?.is_business_permit && (
              <div className="mt-8 space-y-1 text-center">
                <p className="text-xs font-bold leading-relaxed">
                  <span className="text-s-red">Subject for inspection.</span>
                  <span className="text-ink"> Display in a conspicuous place at business establishment. </span>
                  <span className="text-s-red">Not valid without official receipt.</span>
                </p>
                <p className="text-xs font-semibold uppercase tracking-wide text-ink">
                  (This permit will expire on {formatDate(permit.valid_until) || ' '})
                </p>
              </div>
            )}

            {/*
              ── Not on the Mayor's Permit ────────────────────────────────────

              The revocation sentence and the printed verify URL came off on
              the client's instruction [4 October 2026]. Two reasons they are
              no loss here: the City's own form already carries its enforcement
              terms three lines above — subject for inspection, display it, void
              without the receipt — so the sentence was a second, softer version
              of the same warning in our words rather than the City's; and the
              URL was a 60-character localhost string across the foot of a
              document that has a QR code for exactly that purpose.

              Verification is untouched. The QR encodes the same address and is
              captioned "Scan to verify", which is the route an inspector
              actually uses — nobody types a verify URL off a permit.

              The clearances keep both lines: they have no enforcement block of
              their own, so removing them would leave those certificates saying
              nothing about how to check one.
            */}
            {/* CENRO's receipt block, bottom-left as the office prints it. */}
            {cert?.is_cenro_certificate && (
              <dl className="mt-8 space-y-0.5 text-xs text-ink">
                {[
                  ['Official Receipt', cert.or_number],
                  ['Amount Paid', cert.office_amount_paid],
                  ['Date Paid', cert.date_paid],
                  ['Application Control No.', permit.application?.tracking_id],
                ].map(([label, value]) => (
                  <div key={label} className="flex gap-1.5">
                    <dt className="font-bold">{label}:</dt>
                    <dd className="tnum">{value || ' '}</dd>
                  </div>
                ))}
              </dl>
            )}

            {!cert?.is_business_permit && !cert?.is_cenro_certificate && (
              <>
                <p className="mt-8 text-center text-[10px] leading-relaxed text-ink-muted">
                  Subject to revocation for non-compliance with existing laws, ordinances, rules and
                  regulations. Verify authenticity at
                </p>
                <p className="mt-1 break-all text-center text-[10px] leading-relaxed text-ink-muted underline">
                  {permit.verify_url}
                </p>
              </>
            )}
          </div>
          )}
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
