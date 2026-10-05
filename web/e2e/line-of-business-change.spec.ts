import { test, expect } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * Changing the line of business on Location & Zoning keeps the barangay and
 * the pin, and the map keeps taking pins.
 *
 * Ken, testing production: "When I reselect a line of business, the barangay
 * being selected is not recorded, and I can't pin on the map anymore." The
 * re-pick blanked Products / Services, which the map waits for, so the map
 * locked over a barangay and a pin that were both still there. Changing the
 * BARANGAY still clears the pin; that is apply-wizard's, and by design.
 *
 * Acacia's middle is Industrial-2: a warehouse is listed there and a pharmacy
 * is not, so the box beside the map has to change its answer when the trade
 * changes under a pin that did not move.
 *
 * Screenshots go to E2E_SHOTS_DIR when it is set, for review by eye.
 */

const SHOTS = process.env.E2E_SHOTS_DIR

test.use({ storageState: sessionFor('owner') })

test('changing the line of business keeps the barangay and the pin, and the map still takes a pin', async ({
  page,
}) => {
  await page.route('**://nominatim.openstreetmap.org/**', (route) => route.abort())
  await page.goto('/apply')
  await expect(page.getByText(/data privacy/i).first()).toBeVisible({ timeout: 30_000 })
  await page.getByRole('checkbox').first().check()
  await page.getByRole('button', { name: /next/i }).click()
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

  const search = page.getByLabel(/search for the one line of business/i)
  const pick = async (words: string) => {
    await search.fill(words)
    await expect(page.getByText(new RegExp(`trades matching “${words}”`))).toBeVisible()
    await page.getByRole('radiogroup', { name: /line of business/i }).getByRole('radio').first().click()
  }
  const barangay = page.getByLabel(/barangay name/i)
  const map = page.locator('.leaflet-container')
  const pin = page.getByTestId('pin-status')
  const note = page.getByTestId('zoning-note')

  await search.click()
  await pick('warehousing')
  await page.getByRole('textbox', { name: /products \/ services/i }).first().fill('storage')
  await barangay.selectOption({ label: 'Acacia' })
  await map.scrollIntoViewIfNeeded()
  await map.click()
  await expect(pin).toBeVisible()
  await expect(note).toHaveAttribute('data-verdict', 'listed', { timeout: 20_000 })
  const placed = await pin.getAttribute('data-latitude')

  // Change the trade: the barangay and the pin stay, and the box judges the
  // same pin again for the new trade.
  await page.getByRole('button', { name: 'Change line of business' }).click()
  await pick('pharmacy')
  await expect(page.getByTestId('chosen-line')).toHaveText('Retail sale of pharmaceutical goods (pharmacy)')
  await expect(barangay).toHaveValue(/\d+/)
  await expect(barangay.locator('option:checked')).toHaveText('Acacia')
  await expect(pin).toHaveAttribute('data-latitude', placed!)
  await expect(note).toHaveAttribute('data-verdict', 'refused', { timeout: 20_000 })
  await expect(map).toHaveAttribute('aria-label', /click to drop a pin/i)

  // And the map still takes a pin: a click a little off the first one moves it.
  const box = (await map.boundingBox())!
  await map.click({ position: { x: box.width / 2 + 6, y: box.height / 2 + 6 } })
  await expect(pin).not.toHaveAttribute('data-latitude', placed!)
  await expect(barangay.locator('option:checked')).toHaveText('Acacia')
  await expect(note).toHaveAttribute('data-verdict', 'refused', { timeout: 20_000 })
  await note.scrollIntoViewIfNeeded()
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/lb-after.png`, fullPage: true })
})
