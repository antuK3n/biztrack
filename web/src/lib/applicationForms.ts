/**
 * The filing printed on the office's own paper form (client, 19 October 2026):
 * "a downloadable application form in the admin side when viewing the
 * filing/application … the printed format should be the same as the
 * paper-based forms."
 *
 * Two kinds, both server-rendered PDFs:
 *
 *  - the Business Permit application — MCG-BPLO-FO-001 (new), FO-002
 *    (renewal) or the BPLO Amendment Form FO-003, chosen by the API from the
 *    filing's type: `GET /applications/{id}/form-pdf`;
 *  - one office's sheet as the owner filled it in — ZONING, FSIC, SANITARY,
 *    CEC or OCCUPANCY: `GET /applications/{id}/office-forms/{code}/pdf`.
 *
 * Reviewing officers only, scoped like the review sheet: every office on the
 * filing may print the business permit form, a clearance office only its own
 * sheet, BPLO and the super admin any. A refusal arrives as a 403 envelope
 * (`toApiError(err).message` reads it); a sheet the applicant has not filled
 * in is a 404.
 *
 * Why this is its own file rather than more of `resources.ts`: the two blob
 * helpers there are module-private, and this feature was asked to stay out of
 * shared files while several people edit them. The fetch is the same — a
 * Bearer GET as a blob, with a refused blob read back into its JSON envelope
 * so the officer sees the server's sentence and not "something went wrong".
 *
 * ── Opening in a tab ───────────────────────────────────────────────────────
 *
 * The endpoints need the Bearer token, so a plain link or `window.open(url)`
 * gets a 401. `view*` fetches the blob and points a tab at it. Open that tab
 * SYNCHRONOUSLY in the click handler and pass it in — by the time the fetch
 * resolves the click gesture has expired and a popup blocker eats a late
 * `window.open`. Close it again on failure:
 *
 *     async function onPrint() {
 *       const tab = window.open('', '_blank')
 *       try {
 *         await viewBusinessPermitForm(application.id, tab)
 *       } catch (err) {
 *         tab?.close()
 *         setError(toApiError(err).message)
 *       }
 *     }
 */
import axios from 'axios'
import { api } from './api'
import type { OfficeForm } from './types'

/** The office sheets that have a printable paper form. Same list as the API's. */
export const PRINTABLE_OFFICE_FORMS = ['ZONING', 'SANITARY', 'CEC', 'FSIC', 'OCCUPANCY'] as const
export type PrintableOfficeForm = (typeof PRINTABLE_OFFICE_FORMS)[number]

/** The paper each code prints on — short names for buttons, and the form code. */
export const OFFICE_FORM_PAPER: Record<PrintableOfficeForm, { label: string; form: string }> = {
  ZONING: { label: 'Zoning application', form: 'MCG-CPDD-FO-003' },
  SANITARY: { label: 'Sanitary application', form: 'Sanitary Permit to Operate' },
  CEC: { label: 'CENRO application', form: 'MCG-CENRO-FO-001' },
  FSIC: { label: 'FSIC application', form: 'BFP-QSF-FSED-002' },
  OCCUPANCY: { label: 'Occupancy application', form: 'OBO Unified Application Form' },
}

/** The BPLO form a filing prints on, by its type. */
export function businessPermitFormCode(applicationType: string | null | undefined): string {
  if (applicationType === 'renewal') return 'MCG-BPLO-FO-002'
  if (applicationType === 'amendment') return 'MCG-BPLO-FO-003'
  return 'MCG-BPLO-FO-001'
}

/**
 * Which office sheets this filing can print: the ones the applicant actually
 * saved (`form_saved`), in the order the API listed them.
 *
 * Pass `office_forms` from the application / assignment payload. The officer
 * payload already narrows it to the sheets this reader may see, so what comes
 * back is also what the PDF endpoint will allow. A sheet with no saved
 * answers is left out — the endpoint answers 404 for it, because a form of
 * blank boxes would read as a filed copy.
 */
export function printableOfficeSheets(
  forms: OfficeForm[] | null | undefined,
): { code: PrintableOfficeForm; label: string; form: string }[] {
  return (forms ?? [])
    .filter((f) => f.form_saved !== false)
    .map((f) => f.permit_type_code.toUpperCase())
    .filter((code): code is PrintableOfficeForm =>
      (PRINTABLE_OFFICE_FORMS as readonly string[]).includes(code),
    )
    .map((code) => ({ code, ...OFFICE_FORM_PAPER[code] }))
}

function businessPermitUrl(applicationId: number): string {
  return `/applications/${applicationId}/form-pdf`
}

function officeSheetUrl(applicationId: number, code: string): string {
  return `/applications/${applicationId}/office-forms/${encodeURIComponent(code.toUpperCase())}/pdf`
}

/** A refused blob request, read back into the JSON envelope toApiError expects. */
async function rethrowBlobError(error: unknown): Promise<never> {
  const res = axios.isAxiosError(error) ? error.response : undefined
  if (res?.data instanceof Blob) {
    try {
      res.data = JSON.parse(await res.data.text())
    } catch {
      /* not JSON — leave it for the generic message */
    }
  }
  throw error
}

async function fetchPdf(url: string): Promise<Blob> {
  const res = await api.get(url, { responseType: 'blob' }).catch(rethrowBlobError)
  return res.data as Blob
}

async function openPdf(url: string, target?: Window | null): Promise<void> {
  const objectUrl = URL.createObjectURL(await fetchPdf(url))
  if (target && !target.closed) target.location.replace(objectUrl)
  else window.open(objectUrl, '_blank', 'noopener')
  // The tab needs the URL to survive the handoff; reclaim it a minute later.
  window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60_000)
}

async function savePdf(url: string, filename: string): Promise<void> {
  const objectUrl = URL.createObjectURL(await fetchPdf(url))
  const anchor = document.createElement('a')
  anchor.href = objectUrl
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(objectUrl)
}

/** Open the Business Permit application (FO-001/002/003) in a tab. */
export function viewBusinessPermitForm(applicationId: number, target?: Window | null): Promise<void> {
  return openPdf(businessPermitUrl(applicationId), target)
}

/**
 * Save the Business Permit application as a file. `trackingId` names it the
 * way the server does (`mcg-bplo-fo-001-BIZ-2026-00018.pdf`).
 */
export function downloadBusinessPermitForm(
  applicationId: number,
  trackingId: string,
  applicationType?: string | null,
): Promise<void> {
  return savePdf(
    businessPermitUrl(applicationId),
    `${businessPermitFormCode(applicationType).toLowerCase()}-${trackingId}.pdf`,
  )
}

/** Open one office's sheet in a tab. */
export function viewOfficeSheet(
  applicationId: number,
  code: string,
  target?: Window | null,
): Promise<void> {
  return openPdf(officeSheetUrl(applicationId, code), target)
}

/** Save one office's sheet as a file (`zoning-form-BIZ-2026-00018.pdf`). */
export function downloadOfficeSheet(applicationId: number, code: string, trackingId: string): Promise<void> {
  return savePdf(officeSheetUrl(applicationId, code), `${code.toLowerCase()}-form-${trackingId}.pdf`)
}
