import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * The receipt on Payment History, read rather than downloaded.
 *
 * Checklist: "view payment history ... having the receipt viewable". Most of
 * this screen already existed — the list, the filters, the receipt PDF itself —
 * and the one thing it could not do was show you the receipt. `GET
 * /payments/{id}/receipt` is behind a Bearer token, so no `<a href>` reaches it
 * and the only offer the page could make was to save a file.
 *
 * Which is why this is a browser test and not a unit one. What had to be proved
 * is that a tab opens and ends up pointing at the PDF: the fetch, the object
 * URL, the handoff into a window opened before the await, and the popup blocker
 * leaving it alone. Every one of those is a browser behaviour, and `tsc` is
 * blind to all of them — a `viewReceipt` that resolved and opened nothing would
 * typecheck perfectly.
 *
 * Read-only. It opens a receipt for a payment that already exists and writes
 * nothing, which matters because this suite can be pointed at a stack holding
 * real testers' filings.
 */
test.describe('payment receipts', () => {
  test.use({ storageState: sessionFor('owner') })

  test('a receipt opens for reading, and can still be saved', async ({ page, context }) => {
    await page.goto('/payments')

    /*
     * A row has to exist before anything here means something. Asserted rather
     * than skipped: the owner has paid filings on every stack this runs
     * against, so an empty list is a broken fixture — and a test that quietly
     * skips itself when the data is missing reports green for a screen it never
     * looked at.
     */
    const view = page.getByRole('button', { name: /view receipt/i }).first()
    await expect(view).toBeVisible({ timeout: 30_000 })

    /*
     * ── What is asserted, and why not the blob URL ─────────────────────────
     *
     * The obvious assertion — that the tab ends up on a `blob:` URL — does not
     * hold in Chromium under Playwright: a popup opened as about:blank and then
     * moved by `location.replace` to a blob the OPENER created keeps reporting
     * about:blank to the driver. It was written that way first and failed for
     * that reason, with a receipt the API had served perfectly (878 KB of real
     * PDF, verified against the endpoint directly).
     *
     * document-actions.spec.ts had already met this and settled on the same
     * two signals used here, which are the ones that actually distinguish
     * success from failure in this code path:
     *
     *   - a popup opened at all, and
     *   - no error was announced.
     *
     * The second is stronger than it looks. `viewReceipt` CLOSES the tab and
     * writes an error the moment the fetch throws, so a silent failure cannot
     * leave both a live tab and a quiet page. Checking the tab is still open is
     * what closes that gap.
     */
    const popup = context.waitForEvent('page')
    await view.click()
    const tab = await popup

    await expect(page.getByRole('alert')).toHaveCount(0)
    expect(tab.isClosed(), 'a failed fetch closes the tab it opened').toBe(false)
    await tab.close()

    /*
     * And saving survived. The download was the only thing this screen offered
     * before, so replacing it rather than adding beside it would take away the
     * one path somebody may already rely on for attaching a receipt to
     * something else.
     */
    await expect(page.getByRole('button', { name: /save receipt/i }).first()).toBeVisible()
  })
})
