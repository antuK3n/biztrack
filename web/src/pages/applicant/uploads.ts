import { useSyncExternalStore } from 'react'
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
 *
 * Every documentary upload goes through `prepareUpload` since 18 October 2026,
 * when the client widened the types: *"do the fix now, as well as the file
 * additions with conversions … DO THIS FOR ALL INSTANCES INVOLVING DOCUMENTARY
 * UPLOADS."* The profile photo is not a document and keeps its own rule
 * (lib/resources.ts); the admin CSV import is not a document either.
 */

/**
 * What the API accepts (DocumentController, OfficeFormController,
 * OfficerRequestController, MessageController: mimes:pdf,jpg,jpeg,png,webp,
 * max:10240). WEBP is sent as it is — every browser here displays it.
 */
export const ACCEPTED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp']

/**
 * What the browser converts before sending, and into what (uploadConversion.ts).
 * The API never sees these: it cannot read a HEIC, and a TIFF opens in almost
 * no browser, so an office clicking View would get a download instead of a page.
 */
const CONVERTED: Record<string, 'heic' | 'tiff'> = {
  heic: 'heic',
  heif: 'heic',
  tif: 'tiff',
  tiff: 'tiff',
}

export const MAX_UPLOAD_BYTES = 10 * 1024 * 1024

/**
 * The most a HEIC or TIFF may weigh BEFORE conversion. The 10 MB limit applies
 * to what is sent — a HEIC grows as a JPEG and a TIFF usually shrinks as a PDF —
 * so the original cannot be held to it. This only turns away files too big to
 * decode in a browser tab at all.
 */
const MAX_ORIGINAL_BYTES = 50 * 1024 * 1024

/** The allowed types, said once, as every screen tells the applicant. */
export const ACCEPTED_FILES = 'PDF, JPG, PNG, WEBP, HEIC or TIFF'
/** The same, with the limit, for an upload tile's second line. */
export const ACCEPTED_FILES_NOTE = `${ACCEPTED_FILES}, up to 10 MB`

/**
 * The `accept` attribute for a documentary file input. The MIME types are there
 * as well as the extensions because an iPhone's picker goes by type, and offers
 * a HEIC photo only when `image/heic` is named.
 */
export const ACCEPT_ATTR = [
  '.pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.tif,.tiff',
  'application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif,image/tiff',
].join(',')

function extensionOf(file: File): string {
  return file.name.includes('.') ? file.name.split('.').pop()!.toLowerCase() : ''
}

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
 *
 * A HEIC or TIFF is held to the larger pre-conversion ceiling here; the 10 MB
 * check on what it becomes is in `prepareUpload`.
 */
export function fileRejection(file: File): string | null {
  const ext = extensionOf(file)
  const converted = ext in CONVERTED
  if (!converted && !ACCEPTED_EXTENSIONS.includes(ext)) {
    return `“${file.name}” is not a file we can read. Upload a ${ACCEPTED_FILES}.`
  }
  if (file.size === 0) {
    return `“${file.name}” is empty. Check the file opens on your device, then upload it again.`
  }
  if (converted ? file.size > MAX_ORIGINAL_BYTES : file.size > MAX_UPLOAD_BYTES) {
    return `“${file.name}” is ${formatBytes(file.size)}. The limit is 10 MB — try a smaller scan or photo.`
  }
  return null
}

/*
 * ── "Converting…" without threading a flag through every screen ──────────
 *
 * Each upload control already says "Uploading…" while its row is busy, and the
 * busy flag lives wherever that screen keeps it — in the wizard, in a sheet's
 * parent, in a row component. A conversion runs inside the same busy window,
 * so rather than add a second prop beside every busy prop, the count of
 * conversions in flight is kept here and any control can ask for it. One
 * screen uploads one file at a time, so "a conversion is running" and "MY
 * conversion is running" are the same answer.
 */
let converting = 0
const listeners = new Set<() => void>()

function setConverting(delta: number) {
  converting += delta
  listeners.forEach((listener) => listener())
}

function subscribe(listener: () => void) {
  listeners.add(listener)

  return () => {
    listeners.delete(listener)
  }
}

/** True while a HEIC or TIFF is being converted, for the busy label. */
export function useUploadConverting(): boolean {
  return useSyncExternalStore(subscribe, () => converting > 0)
}

export type PreparedUpload = { file: File; error?: undefined } | { file?: undefined; error: string }

/**
 * The file to send, or why there is none: rejected, converted, size-checked.
 *
 * Every documentary upload calls this rather than `fileRejection`, so a HEIC or
 * TIFF is converted on every screen that takes a document, not just the one
 * that was fixed first. The converted file keeps the applicant's name with its
 * new extension ("IMG_1234.HEIC" → "IMG_1234.jpg", "scan.tif" → "scan.pdf"),
 * which is what the office then sees on the filing.
 */
export async function prepareUpload(file: File): Promise<PreparedUpload> {
  const rejection = fileRejection(file)
  if (rejection !== null) return { error: rejection }

  const kind = CONVERTED[extensionOf(file)]
  if (kind === undefined) return { file }

  let result: File
  setConverting(1)
  try {
    const { heicToJpeg, tiffToPdf } = await import('./uploadConversion')
    result = kind === 'heic' ? await heicToJpeg(file) : await tiffToPdf(file)
  } catch {
    // A corrupt file, or a HEIC variant libheif does not decode. Saying which
    // formats always work is the one instruction the applicant can follow.
    return { error: `We could not read “${file.name}”. Save it as a PDF, JPG or PNG and try again.` }
  } finally {
    setConverting(-1)
  }

  if (result.size > MAX_UPLOAD_BYTES) {
    return {
      error: `“${file.name}” is ${formatBytes(result.size)} once converted. The limit is 10 MB — try a smaller scan or photo.`,
    }
  }

  return { file: result }
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
