import { expect, test } from '@playwright/test'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { PDFDocument } from 'pdf-lib'
import { build } from 'vite'

/*
 * ── The browser-side conversion, proved without the e2e stack ───────────────
 *
 * Client, 18 October 2026: documentary uploads take HEIC/HEIF, WEBP and TIFF
 * too, "with conversions" — a HEIC becomes a JPEG and a TIFF becomes a PDF in
 * the browser, before the upload, because the API's PHP cannot read a HEIC
 * (uploads.ts, uploadConversion.ts).
 *
 * This spec needs no API and no Vite dev server. It bundles `uploads.ts` with
 * Vite itself — the same bundler, the same libraries, the same lazy imports
 * the app ships — into a temporary folder, serves that folder to a blank page
 * through `page.route`, and calls the real `prepareUpload` on the fixtures in
 * e2e/fixtures. So it runs anywhere Playwright's Chromium does:
 *
 *     npx playwright test e2e/upload-conversion.spec.ts --project=chromium --no-deps
 *
 * Fixtures: photo.heic is the sample from heic2any's own demo (MIT); the TIFFs
 * were written with UTIF.encode — a 120 × 60 page at 300 dpi, half transparent,
 * and a two-page file whose second page records no resolution.
 */

const WEB = resolve(import.meta.dirname, '..')
const FIXTURES = join(import.meta.dirname, 'fixtures')
const ORIGIN = 'http://conversion.test'

let outDir = ''

test.beforeAll(async () => {
  test.setTimeout(180_000)
  outDir = mkdtempSync(join(tmpdir(), 'biztrack-conversion-'))
  await build({
    configFile: false,
    root: WEB,
    logLevel: 'warn',
    define: { 'process.env.NODE_ENV': '"production"' },
    build: {
      outDir,
      emptyOutDir: true,
      minify: false,
      lib: { entry: join(WEB, 'src/pages/applicant/uploads.ts'), formats: ['es'], fileName: 'uploads' },
    },
  })
})

test.afterAll(() => {
  if (outDir) rmSync(outDir, { recursive: true, force: true })
})

test.beforeEach(async ({ page }) => {
  await page.route(`${ORIGIN}/**`, (route) => {
    const path = new URL(route.request().url()).pathname
    if (path === '/') {
      return route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>conversion</title>' })
    }
    try {
      return route.fulfill({ contentType: 'text/javascript', body: readFileSync(join(outDir, path)) })
    } catch {
      return route.fulfill({ status: 404, body: '' })
    }
  })
  await page.goto(`${ORIGIN}/`)
})

type Outcome = { error?: string; name?: string; type?: string; size?: number; base64?: string }

/** Run the bundled `prepareUpload` on one fixture, in the page. */
async function prepare(page: import('@playwright/test').Page, fixture: string, bytes?: Buffer): Promise<Outcome> {
  const data = (bytes ?? readFileSync(join(FIXTURES, fixture))).toString('base64')

  return page.evaluate(
    async ({ name, data }) => {
      // Through Function so the test runner cannot rewrite the import; the path
      // resolves against the page, where page.route serves the bundle.
      const { prepareUpload } = await new Function('return import("/uploads.js")')()
      const raw = Uint8Array.from(atob(data), (c) => c.charCodeAt(0))
      const result = await prepareUpload(new File([raw], name))
      if (result.error !== undefined) return { error: result.error }
      const out = new Uint8Array(await result.file.arrayBuffer())
      let binary = ''
      out.forEach((b: number) => (binary += String.fromCharCode(b)))

      return { name: result.file.name, type: result.file.type, size: result.file.size, base64: btoa(binary) }
    },
    { name: fixture, data },
  )
}

test('turns a HEIC photo into a JPEG with the same name', async ({ page }) => {
  const out = await prepare(page, 'photo.heic')

  expect(out.error).toBeUndefined()
  expect(out.name).toBe('photo.jpg')
  expect(out.type).toBe('image/jpeg')
  // A real JPEG: the SOI marker, not just a renamed HEIC.
  expect(Buffer.from(out.base64!, 'base64').subarray(0, 3)).toEqual(Buffer.from([0xff, 0xd8, 0xff]))
})

test('turns a one-page TIFF into a one-page PDF sized from its resolution', async ({ page }) => {
  const out = await prepare(page, 'scan-one-page.tif')

  expect(out.error).toBeUndefined()
  expect(out.name).toBe('scan-one-page.pdf')
  expect(out.type).toBe('application/pdf')
  const pdf = await PDFDocument.load(Buffer.from(out.base64!, 'base64'))
  // 120 × 60 px at 300 dpi is 28.8 × 14.4 pt — the size it would print at.
  expect(pdf.getPageCount()).toBe(1)
  const { width, height } = pdf.getPage(0).getSize()
  expect(width).toBeCloseTo(28.8, 1)
  expect(height).toBeCloseTo(14.4, 1)
})

test('keeps every page of a multi-page TIFF', async ({ page }) => {
  const out = await prepare(page, 'scan-two-pages.tiff')

  expect(out.name).toBe('scan-two-pages.pdf')
  const pdf = await PDFDocument.load(Buffer.from(out.base64!, 'base64'))
  expect(pdf.getPageCount()).toBe(2)
  // The second page records no resolution, so it is fitted inside A4 and,
  // being smaller than A4, left at one point per pixel rather than enlarged.
  const second = pdf.getPage(1).getSize()
  expect(second.width).toBeCloseTo(60, 1)
  expect(second.height).toBeCloseTo(120, 1)
})

test('says plainly when a HEIC cannot be read', async ({ page }) => {
  const out = await prepare(page, 'broken.heic')

  expect(out.error).toBe('We could not read “broken.heic”. Save it as a PDF, JPG or PNG and try again.')
})

test('sends a WEBP as it is and refuses a GIF', async ({ page }) => {
  const webp = await prepare(page, 'storefront.webp', Buffer.from('RIFF....WEBPVP8 '))
  expect(webp.error).toBeUndefined()
  expect(webp.name).toBe('storefront.webp')

  const gif = await prepare(page, 'animation.gif', Buffer.from('GIF89a'))
  expect(gif.error).toBe('“animation.gif” is not a file we can read. Upload a PDF, JPG, PNG, WEBP, HEIC or TIFF.')
})
