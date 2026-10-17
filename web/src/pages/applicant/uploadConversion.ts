/*
 * ── Turning a phone photo or a scanner's TIFF into something a browser opens ─
 *
 * Client, 18 October 2026: widen the documentary uploads to HEIC/HEIF, WEBP and
 * TIFF, "with conversions", so every file an office receives still opens in the
 * browser. WEBP needs nothing — every browser here shows it. HEIC (what an
 * iPhone saves) and TIFF (what an office scanner saves) open in almost none, so
 * they are converted HERE, before the upload, and never reach the API:
 *
 *   - HEIC/HEIF → JPEG, one image.
 *   - TIFF/TIF → PDF, every page, because a scanned lease is often one
 *     multi-page TIFF and dropping pages two onward would lose the signatures.
 *
 * In the browser rather than on the server because the server cannot do it:
 * this machine's PHP has GD (which reads WEBP) but neither Imagick nor libheif,
 * so it cannot read a HEIC at all. If the API ever gains Imagick with HEIC
 * support, conversion could move there and this file could go.
 *
 * The libraries are loaded with `import()` only when such a file is chosen.
 * libheif alone is over a megabyte, and the applicant uploading a PDF — most of
 * them — should not download it.
 */

/** "IMG_1234.HEIC" → "IMG_1234.jpg": the applicant's own name, the new type. */
export function renamed(name: string, ext: string): string {
  const dot = name.lastIndexOf('.')
  return `${dot > 0 ? name.slice(0, dot) : name}.${ext}`
}

/** One JPEG from a HEIC/HEIF photo. A burst or live photo gives its first frame. */
export async function heicToJpeg(file: File): Promise<File> {
  const { default: heic2any } = await import('heic2any')
  const out = await heic2any({ blob: file, toType: 'image/jpeg', quality: 0.9 })
  const blob = Array.isArray(out) ? out[0] : out
  if (!blob || blob.size === 0) throw new Error('empty conversion')

  return new File([blob], renamed(file.name, 'jpg'), {
    type: 'image/jpeg',
    lastModified: file.lastModified,
  })
}

/* A4 in PDF points, for a scan that does not say how many dots per inch it is. */
const A4_SHORT = 595
const A4_LONG = 842

/**
 * The page a TIFF image should print on, in points (1/72 inch).
 *
 * A scan records its resolution, so a 300-dpi A4 scan (2480 × 3508 px) comes
 * out as an A4 page, 595 × 842 pt — the size the office would print it at. A
 * file with no believable resolution is fitted inside A4 instead, never
 * enlarged, so a small image does not become a blurry full page.
 */
function pageSize(
  ifd: { t282?: number[]; t283?: number[]; t296?: number[] },
  width: number,
  height: number,
): [number, number] {
  const xres = ifd.t282?.[0]
  const yres = ifd.t283?.[0] ?? xres
  const unit = ifd.t296?.[0] ?? 2
  // 2 = inch (the default), 3 = centimetre; 1 = "no unit", which is no help.
  const perInch = unit === 3 ? 2.54 : unit === 2 ? 1 : 0
  const dpiX = xres !== undefined && perInch > 0 ? xres * perInch : 0
  const dpiY = yres !== undefined && perInch > 0 ? yres * perInch : 0
  if (dpiX >= 50 && dpiX <= 2400 && dpiY >= 50 && dpiY <= 2400) {
    return [(width * 72) / dpiX, (height * 72) / dpiY]
  }

  const [boxW, boxH] = width > height ? [A4_LONG, A4_SHORT] : [A4_SHORT, A4_LONG]
  const scale = Math.min(1, boxW / width, boxH / height)

  return [width * scale, height * scale]
}

/** A canvas's contents as JPEG bytes. */
function canvasJpeg(canvas: HTMLCanvasElement): Promise<ArrayBuffer> {
  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => (blob ? blob.arrayBuffer().then(resolve, reject) : reject(new Error('encode failed'))),
      'image/jpeg',
      0.85,
    )
  })
}

/**
 * One PDF from a TIFF, a page per image in it.
 *
 * Each page is decoded to RGBA, drawn on a canvas and re-encoded as JPEG,
 * because a raw page of a 300-dpi colour scan is about 26 MB and the limit is
 * 10 MB for the whole file. Transparent pixels are laid on white first: JPEG
 * has no transparency, and left alone they would turn black.
 *
 * Reduced-resolution images (TIFF's own thumbnails, NewSubfileType bit 0) are
 * skipped — they are previews of a page, not another page.
 */
export async function tiffToPdf(file: File): Promise<File> {
  const [{ default: UTIF }, { PDFDocument }] = await Promise.all([import('utif'), import('pdf-lib')])
  const buffer = await file.arrayBuffer()
  const ifds = UTIF.decode(buffer)
  const pages = ifds.filter((ifd) => ifd.t256 !== undefined && ((ifd.t254?.[0] ?? 0) & 1) === 0)
  if (pages.length === 0) throw new Error('no pages')

  const pdf = await PDFDocument.create()
  const canvas = document.createElement('canvas')
  const context = canvas.getContext('2d')
  if (context === null) throw new Error('no canvas')

  for (const ifd of pages) {
    UTIF.decodeImage(buffer, ifd, ifds)
    const width = ifd.width
    const height = ifd.height
    if (!width || !height) throw new Error('page without size')

    const rgba = new Uint8ClampedArray(UTIF.toRGBA8(ifd))
    for (let i = 3; i < rgba.length; i += 4) {
      const alpha = rgba[i]
      if (alpha === 255) continue
      const keep = alpha / 255
      rgba[i - 3] = rgba[i - 3] * keep + 255 * (1 - keep)
      rgba[i - 2] = rgba[i - 2] * keep + 255 * (1 - keep)
      rgba[i - 1] = rgba[i - 1] * keep + 255 * (1 - keep)
      rgba[i] = 255
    }

    canvas.width = width
    canvas.height = height
    context.putImageData(new ImageData(rgba, width, height), 0, 0)
    const jpeg = await pdf.embedJpg(await canvasJpeg(canvas))

    const [pageW, pageH] = pageSize(ifd, width, height)
    pdf.addPage([pageW, pageH]).drawImage(jpeg, { x: 0, y: 0, width: pageW, height: pageH })
    // A decoded page is width × height × 4 bytes; let it go before the next.
    delete ifd.data
  }

  const bytes = await pdf.save()

  // Copied into a plain ArrayBuffer-backed array, which is what File's type wants.
  return new File([new Uint8Array(bytes)], renamed(file.name, 'pdf'), {
    type: 'application/pdf',
    lastModified: file.lastModified,
  })
}
