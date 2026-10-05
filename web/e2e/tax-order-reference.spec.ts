import { expect, test } from '@playwright/test'
import { mergedStorageState } from './helpers'
import { makeBilledApplication } from './payments'

/*
 * The Tax Order of Payment carries its own Reference No. (TOP-YYYY-NNNNNN,
 * Numbering::taxOrderReference on the API), not the filing's tracking ID.
 * Ken, October 2026: "Reference No. should not be the same as the Tracking ID."
 */
test.describe.configure({ timeout: 180_000 })
test.use({ storageState: mergedStorageState(['owner.json', 'bplo.json']) })

test('the Pay page shows the bill its own reference number, not the tracking ID', async ({ page }) => {
  const appId = await makeBilledApplication(page)

  const filing = await page.evaluate(async (id) => {
    const res = await fetch(`/api/v1/applications/${id}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}` },
    })
    const data = (await res.json()).data
    return { tracking: data.tracking_id as string, reference: data.fee_assessment.reference_number as string }
  }, appId)
  expect(filing.reference).toMatch(/^TOP-\d{4}-\d{6}$/)

  await page.goto(`/applications/${appId}/pay`)
  const reference = page.getByText(/^Reference No:/)
  await expect(reference).toContainText(filing.reference)
  await expect(reference).not.toContainText(filing.tracking)
})
