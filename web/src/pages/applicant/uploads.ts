import { formatBytes } from '../../lib/format'
import { toApiError } from '../../lib/api'

/*
 * ── What counts as a usable attachment, in one place ──────────────────────
 *
 * These lived inside ApplyWizard while the wizard was the only screen that took
 * a file from an applicant. The LGU Clearances stage now takes one too — the
 * copy of a certificate you already hold — and two screens with two ideas of
 * "10 MB" or "PDF, JPG, PNG" is how one of them starts accepting a file the API
 * then refuses. So the rules moved out rather than being copied.
 */

/** What the API accepts (DocumentController: mimes:pdf,jpg,jpeg,png, max:10240). */
export const ACCEPTED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png']
export const MAX_UPLOAD_BYTES = 10 * 1024 * 1024

/** The `accept` attribute matching ACCEPTED_EXTENSIONS, for a file input. */
export const ACCEPT_ATTR = '.pdf,.jpg,.jpeg,.png'

/**
 * Why this file cannot be sent, checked before it leaves the browser.
 *
 * Everything here was previously discovered only by uploading and reading
 * whatever the server said back, and what it said back was not usable: an
 * empty PDF came back as "Upload a PDF, JPG, or PNG file." (it is one), and a
 * file over the request limit came back as a raw PHP notice that the client
 * rendered as "Something went wrong on our end" — blaming the server for the
 * applicant's 12 MB scan and inviting them to retry it forever. Naming the
 * actual defect, before the upload, is both faster and the only version that
 * tells the applicant what to do next.
 */
export function fileRejection(file: File): string | null {
  const ext = file.name.includes('.') ? file.name.split('.').pop()!.toLowerCase() : ''
  if (!ACCEPTED_EXTENSIONS.includes(ext)) {
    return `“${file.name}” is not a file we can read. Upload a PDF, JPG, or PNG.`
  }
  if (file.size === 0) {
    return `“${file.name}” is empty. Check the file opens on your device, then upload it again.`
  }
  if (file.size > MAX_UPLOAD_BYTES) {
    return `“${file.name}” is ${formatBytes(file.size)}. The limit is 10 MB — try a smaller scan or photo.`
  }
  return null
}

/**
 * An upload error the applicant can act on. The API's own messages are used
 * as-is; the two failures that arrive without a usable message are the ones
 * worth translating, because both mean "your file is too big" and neither says
 * so. (413 is the web server refusing the request before Laravel sees it;
 * "failed to upload" is PHP truncating a file past upload_max_filesize.)
 */
export function uploadErrorMessage(err: unknown): string {
  const apiError = toApiError(err)
  if (apiError.status === 413) {
    return 'That file is too large to upload. Try a smaller scan or photo, under 10 MB.'
  }
  if (/failed to upload/i.test(apiError.message)) {
    return 'That file did not finish uploading — it may be too large. Try a smaller scan or photo.'
  }
  return apiError.message
}
