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

/**
 * Why it is suspended, in one or two sentences: since when, which office
 * rejected which permit and its words, and how it comes back.
 */
function suspensionSentence(permit: Permit): string {
  const since = permit.suspended_at ? `Suspended since ${formatDate(permit.suspended_at)}` : 'Suspended'
  const reason = permit.suspension_reason?.trim().replace(/\.$/, '') ?? ''
  const f = permit.suspended_for
  if (f) {
    const office = f.office ?? 'the issuing office'
    return `${since} because ${office} rejected your ${f.name}${reason ? `: ${reason}` : ''}. `
      + `It returns to Active once ${office} approves it.`
  }
  if (reason) return `${since}. Reason: ${reason}.`
  return `${since}. It does not verify while suspended.`
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
    <div className="mx-auto max-w-3xl">
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
        <article className="border-[6px] border-white bg-white px-3 py-5 sm:px-10 sm:py-7 print:border-0 print:p-0">
          <div className="border-2 border-ink/80 px-3.5 py-5 sm:px-8 sm:py-6">
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
                <p className="text-base font-bold uppercase tracking-wide text-ink">City of Malabon</p>
                <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-secondary">
                  {cert?.department_name ?? 'Business Permits and Licensing Office'}
                </p>
              </div>
              <div className="flex shrink-0 flex-col items-center gap-1.5 self-center sm:self-start">
                <QRCodeSVG value={permit.verify_url} size={92} level="M" />
                <p className="text-[10px] text-ink-muted">Scan to verify</p>
              </div>
            </header>

            {/* The permit type is the document's own title, as it is on paper —
                a fire safety certificate should not be headed BUSINESS PERMIT. */}
            {/* Tighter tracking and a smaller size below `sm`: at 0.18em a
                three-word permit name took three lines of a phone screen
                before the document had said anything. */}
            <h1 className="display-serif mt-6 text-balance text-center text-2xl uppercase tracking-[0.08em] text-ink sm:text-3xl sm:tracking-[0.18em]">
              {cert?.permit_type_name ?? permit.permit_type.name}
            </h1>
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

              The cause IS named now. It was left out while this screen could
              not know which it was; since 5 October 2026 the permit records
              it (`suspended_for`, `suspension_reason`), and the client asked
              for exactly this: *"Show WHY it is suspended and WHICH office
              caused it."* "Rejected" covers a failed visit too — the client
              reads the inspection's Reject as the permit being rejected. A
              suspension that named no permit (a sanctioned business, a
              rejected filing) shows its reason alone.

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
                  {suspensionSentence(permit)}
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
            <div className="mt-4 hidden print:block">
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

            {/*
              The URL on its OWN line. `break-all` inside the sentence split it
              mid-token — the footer read "…authenticity at htt" / "p://…" —
              which on a document about authenticity looks like a broken link
              rather than a long one.
            */}
            <p className="mt-8 text-center text-[10px] leading-relaxed text-ink-muted">
              Subject to revocation for non-compliance with existing laws, ordinances, rules and
              regulations. Verify authenticity at
            </p>
            <p className="mt-1 break-all text-center text-[10px] leading-relaxed text-ink-muted underline">
              {permit.verify_url}
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
