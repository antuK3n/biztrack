import { expect, test, type Page } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * REVOKE AND THE MAP — checklist items 23 and 16, against the real register.
 *
 * Unstubbed on purpose. admin-permits.spec.ts pins the dialog's rules against
 * a fixture; what a fixture cannot prove is the whole act: that the row the
 * officer revoked comes back Revoked from the server, and that the public page
 * a permit's QR opens stops vouching for it. Likewise the map: that BPLO —
 * refused it until this change — gets it on the Permits page from the real
 * endpoint, and that a marker leads back into the register.
 *
 * This MUTATES the register it runs against (one permit is revoked), which is
 * why it must only ever point at a throwaway copy (AGENTS.md §2.3).
 *
 * Screenshots go to E2E_SHOTS when set, for the checklist report.
 */

const SHOTS = process.env.E2E_SHOTS

async function shot(page: Page, name: string) {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false })
}

/** Tiles are stubbed so the test never waits on OpenStreetMap. */
async function quietTiles(page: Page) {
  const PIXEL = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64')
  await page.route(/tile\.openstreetmap\.org|server\.arcgisonline\.com/, (route) =>
    route.fulfill({ status: 200, contentType: 'image/gif', body: PIXEL }),
  )
}

test.describe('BPLO revokes a permit', () => {
  test.use({ storageState: sessionFor('bplo') })

  test('the permit comes back Revoked, and its QR page says so', async ({ page }) => {
    await page.goto('/staff/admin/permits')
    await expect(page.getByRole('heading', { name: 'Permits', level: 1 })).toBeVisible()

    // Active Mayor's Permits only, so the row picked is one Revoke is offered on.
    await page.getByRole('button', { name: /^Filter/ }).click()
    await page.getByRole('option', { name: 'Active', exact: true }).click()
    await page.keyboard.press('Escape')

    // Revoked from Change status in the Actions column [client, 5 October 2026].
    const revoke = page.getByRole('button', { name: /^Change status of / }).first()
    await expect(revoke).toBeVisible({ timeout: 30_000 })
    const permitNumber = ((await revoke.getAttribute('aria-label')) ?? '').replace(/^Change status of /, '')
    expect(permitNumber).toMatch(/\S+-\d{4}-\d+/)

    // Before: the public page vouches for it.
    const verify = await page.context().newPage()
    await verify.setViewportSize({ width: 390, height: 844 })
    await verify.goto(`/verify/${permitNumber}`)
    await expect(verify.getByRole('heading', { name: 'Valid', level: 1 })).toBeVisible({ timeout: 20_000 })
    await shot(verify, 'verify-valid-mobile')

    await revoke.click()
    const dialog = page.getByRole('dialog', { name: `Change status — ${permitNumber}` })
    await expect(dialog).toBeVisible()
    await dialog.getByRole('radio', { name: /Revoked/ }).check()
    await dialog.getByRole('textbox', { name: 'Reason' }).fill('Closure order from the Mayor, e2e check.')
    await shot(page, 'revoke-dialog')
    await dialog.getByRole('button', { name: 'Save status' }).click()
    await expect(dialog).toHaveCount(0)

    // The server's answer, not the page's: search it back out under Revoked.
    await page.getByRole('button', { name: /^Filter/ }).click()
    await page.getByRole('option', { name: 'Revoked', exact: true }).click()
    await page.keyboard.press('Escape')
    await page.getByRole('searchbox', { name: /Search permits/ }).fill(permitNumber)

    const row = page.locator('tbody tr').filter({ hasText: permitNumber })
    await expect(row).toHaveCount(1, { timeout: 20_000 })
    await expect(row).toContainText('Revoked')
    await expect(row).toContainText('Closure order from the Mayor, e2e check.')
    await shot(page, 'register-revoked-row')

    // After: the QR page says Revoked, with a date and without the reason.
    await verify.reload()
    await expect(verify.getByRole('heading', { name: 'Revoked', level: 1 })).toBeVisible({ timeout: 20_000 })
    await expect(verify.getByText(/The issuing office revoked this permit on /)).toBeVisible()
    await expect(verify.locator('body')).not.toContainText('Closure order from the Mayor')
    await expect(verify.getByText(permitNumber, { exact: true })).toBeVisible()
    await shot(verify, 'verify-revoked-mobile')
    await verify.close()
  })
})

test.describe('an office changes its own certificates, and maps them', () => {
  test.use({ storageState: sessionFor('fire') })

  /*
   * Client, 4 October 2026: "yung mga kanya kanya nilang permit pwede nilang
   * irevoke syempre tas maglagay din ng maps tulad sa bplo". The revoking
   * half was withdrawn on 5 October 2026 (Ken): only BPLO and the super admin
   * revoke, and an office's Change status is Active and Rejected.
   */
  test('the fire office is offered Change status on FSICs only, and a Map of its own certificate', async ({ page }) => {
    await page.goto('/staff/admin/permits')
    await expect(page.locator('tbody tr').first()).toBeVisible({ timeout: 30_000 })

    // Every Change status on its table is on one of its own certificates.
    for (const label of await page.getByRole('button', { name: /^Change status of / }).evaluateAll((els) =>
      els.map((e) => e.getAttribute('aria-label') ?? ''),
    )) {
      expect(label).toMatch(/^Change status of FSIC-/)
    }

    await page.getByRole('group', { name: 'Permits view' }).getByRole('button', { name: 'Map' }).click()
    await expect(page.getByText(/businesses holding a Fire Safety Inspection Certificate carry a/)).toBeVisible({ timeout: 30_000 })
  })
})

test.describe('the Map view on the Permits page', () => {
  test.use({ storageState: sessionFor('bplo') })

  test('BPLO sees every pinned business, in five states, and can find one in the register', async ({
    page,
  }) => {
    await quietTiles(page)
    await page.setViewportSize({ width: 1440, height: 1000 })
    await page.goto('/staff/admin/permits')
    await expect(page.getByRole('heading', { name: 'Permits', level: 1 })).toBeVisible()

    const payload = page.waitForResponse(
      (r) => r.url().includes('/api/v1/admin/business-map') && r.status() === 200,
    )
    await page.getByRole('button', { name: 'Map', exact: true }).click()
    await expect(page.getByRole('button', { name: 'Map', exact: true })).toHaveAttribute('aria-pressed', 'true')

    const body = (await (await payload).json()) as {
      data: { state: string; barangay: string | null; permit_number: string | null }[]
      meta: { plotted: number; counts: Record<string, number> }
    }
    expect(body.meta.plotted).toBeGreaterThan(0)
    await expect(page.locator('.biztrack-map-pin')).toHaveCount(body.meta.plotted)

    // The legend is five rows, in words, with the server's counts.
    for (const [state, label] of [
      ['active', 'Permit active'],
      ['expired', 'Permit expired'],
      ['suspended', 'Permit suspended'],
      ['revoked', 'Permit revoked'],
      ['none', 'No permit on file'],
    ] as const) {
      await expect(page.getByRole('row').filter({ hasText: label })).toContainText(
        String(body.meta.counts[state]),
      )
    }
    await shot(page, 'map-tab')

    // The barangay filter narrows to what that barangay declared.
    const barangay = body.data.find((r) => r.barangay !== null)?.barangay as string
    const inIt = body.data.filter((r) => r.barangay === barangay).length
    await page.getByRole('combobox', { name: 'Barangay', exact: true }).selectOption(barangay)
    await expect(page.locator('.biztrack-map-pin')).toHaveCount(inIt)
    await expect(
      page.getByText(`Showing ${inIt} of the ${body.meta.plotted} businesses on the map in ${barangay}.`),
    ).toBeVisible()

    // A marker with a permit leads back into the register, searched for it.
    await page.getByRole('combobox', { name: 'Barangay', exact: true }).selectOption('')
    await page.getByRole('button', { name: /^Permit active/ }).click()
    const target = body.data.find((r) => r.state === 'active' && r.permit_number !== null)
    expect(target, 'no active business on the map').toBeTruthy()
    // Dispatched rather than clicked: 700 markers on one city overlap, and a
    // real pointer lands on whichever neighbour is drawn on top.
    await page.locator('.biztrack-map-pin--active').first().dispatchEvent('click')
    const popup = page.locator('.leaflet-popup')
    await expect(popup).toContainText('Permit active')
    await shot(page, 'map-popup')

    const find = popup.getByRole('button', { name: /^Find .+ in the register$/ })
    const number = ((await find.getAttribute('aria-label')) ?? '').replace(/^Find | in the register$/g, '')
    await find.click()

    await expect(page.getByRole('button', { name: 'Table', exact: true })).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByRole('searchbox', { name: /Search permits/ })).toHaveValue(number)
    await expect(page.locator('tbody tr').filter({ hasText: number })).toHaveCount(1, { timeout: 20_000 })
  })
})

test.describe('the super admin', () => {
  test.use({ storageState: sessionFor('admin') })

  test('gets the Map view on Permits', async ({ page }) => {
    await quietTiles(page)
    await page.goto('/admin/permits')
    await expect(page.getByRole('heading', { name: 'Permits', level: 1 })).toBeVisible()
    await page.getByRole('button', { name: 'Map', exact: true }).click()
    await expect(page.locator('.biztrack-map-pin').first()).toBeVisible({ timeout: 30_000 })
  })
})
