import { test, expect, type Page } from '@playwright/test'
const SHOTS = '/private/tmp/claude-501/-Users-kenmondragon-Documents-GitHub-biztrack/887899e9-082a-4c5c-9da1-a57e6c131f84/scratchpad'
test.use({ storageState: 'e2e/.auth/owner.json' })
async function makeDraft(page: Page): Promise<number> {
  return page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.public')
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    }
    const json = async (res: Response) => (await res.json()).data

    const barangays = await json(await fetch('/api/v1/reference/barangays', { headers }))
    const psic = await json(await fetch('/api/v1/reference/psic-codes', { headers }))
    const permitTypes = await json(await fetch('/api/v1/reference/permit-types', { headers }))
    const business = await json(
      await fetch('/api/v1/businesses', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          name: `E2E Clearances ${Date.now()}`,
          registration_type: 'DTI',
          registration_number: 'DTI-E2E-001',
          tin: '123-456-789-000',
          address: { line1: '1 Playwright St.', barangay_id: barangays[0].id },
          lines: [{ psic_code_id: psic[0].id, capitalization: 500000 }],
        }),
      }),
    )
    const app = await json(
      await fetch('/api/v1/applications', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          business_id: business.id,
          application_type: 'new',
          permit_type_ids: [
            permitTypes.find((pt: { code: string }) => pt.code === 'BUSINESS').id,
          ],
        }),
      }),
    )
    return app.id as number
  })
}

test('grid shots', async ({ page }) => {
  await page.goto('/dashboard')
  const appId = await makeDraft(page)
  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({ timeout: 30_000 })
  await page.screenshot({ path: `${SHOTS}/20-grid-clean.png`, fullPage: true })
  await page.getByRole('button', { name: /show the market clearance/i }).click()
  const market = page.locator('ul > li').filter({ hasText: /market clearance/i })
  await market.screenshot({ path: `${SHOTS}/21-market-card.png` })
  const zoning = page.locator('ul > li').filter({ hasText: /zoning/i })
  await zoning.screenshot({ path: `${SHOTS}/22-untouched-card.png` })
  await zoning.getByRole('button', { name: /^apply$/i }).click()
  await page.getByRole('button', { name: /back without saving/i }).click()
  await zoning.screenshot({ path: `${SHOTS}/23-applied-card.png` })
  await page.screenshot({ path: `${SHOTS}/24-grid-after.png`, fullPage: true })
})
