import { expect, test } from '@playwright/test'
import { mergedStorageState } from './helpers'
import { makeBilledApplication } from './payments'

/*
 * The owner's Track card, opened: the Mayor's Permit leads.
 *
 * The filing attaches BUSINESS at submit, after the clearances, so the API
 * hands it back last and the card listed the permit the filing is for at the
 * bottom.
 *
 * A real filing, with only the permit order rearranged on the way in.
 * Writes one filing of the owner's, so it belongs on the throwaway stack only.
 */
test.use({ storageState: mergedStorageState(['owner.json', 'bplo.json']) })

test('the Business Permit is the first row', async ({ page }) => {
  const id = await makeBilledApplication(page)
  const trackingId = await page.evaluate(async (appId) => {
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}`,
    }
    const paid = await fetch(`/api/v1/applications/${appId}/pay`, {
      method: 'POST',
      headers,
      body: JSON.stringify({ method: 'gcash' }),
    })
    if (paid.status !== 201) throw new Error(`pay answered ${paid.status}`)
    const res = await fetch(`/api/v1/applications/${appId}`, { headers })
    return (await res.json()).data.tracking_id as string
  }, id)

  /*
   * `makeBilledApplication` files BUSINESS first; the wizard attaches it last.
   * Hand the page the wizard's order, so the row order is the page's doing.
   */
  type Row = { code: string }
  const businessLast = (rows: Row[]) => [
    ...rows.filter((r) => r.code !== 'BUSINESS'),
    ...rows.filter((r) => r.code === 'BUSINESS'),
  ]
  await page.route('**/api/v1/applications**', async (route) => {
    const res = await route.fetch()
    const json = await res.json()
    const apps: { permit_types?: Row[] }[] = Array.isArray(json.data) ? json.data : [json.data]
    for (const app of apps) if (app?.permit_types) app.permit_types = businessLast(app.permit_types)
    await route.fulfill({ response: res, json })
  })

  await page.goto('/applications')
  await page.getByRole('searchbox', { name: /Search your applications/ }).fill(trackingId)
  await page.locator('li > div > button[aria-expanded]').first().click()

  const permitRows = page.locator(`button[aria-controls^="history-${id}-"]`)
  await expect(permitRows.first()).toBeVisible()
  expect(await permitRows.count()).toBeGreaterThan(1)
  await expect(permitRows.first()).toHaveAttribute('aria-controls', `history-${id}-BUSINESS`)

})
