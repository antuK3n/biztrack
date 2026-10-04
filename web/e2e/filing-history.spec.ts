import { expect, test } from '@playwright/test'
import { mergedStorageState } from './helpers'
import { makeBilledApplication } from './payments'

/*
 * The History on the owner's filing page names each move for THIS filing.
 *
 * Since 4 October 2026 a paid filing reaches `approved` at payment and gathers
 * its other permits there. The status table's word for `approved` is
 * "Completed", so History printed "Completed" on the payment row of a filing
 * whose card, one screen up, calls it Approved (owner-track 19). The row that
 * closes a filing still reads Completed; that is `labelHistory` in status.ts.
 *
 * Writes one filing of the owner's, so it belongs on the throwaway stack only.
 */
test.use({ storageState: mergedStorageState(['owner.json', 'bplo.json']) })

test('a paid filing still gathering its other permits reads Approved in History, not Completed', async ({ page }) => {
  const id = await makeBilledApplication(page)
  const paid = await page.evaluate(async (appId) => {
    const res = await fetch(`/api/v1/applications/${appId}/pay`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}`,
      },
      body: JSON.stringify({ method: 'gcash' }),
    })
    return res.status
  }, id)
  expect(paid).toBe(201)

  await page.goto(`/applications/${id}`)
  const history = page
    .locator('section')
    .filter({ has: page.getByText('History', { exact: true }) })
    .locator('ol > li')
  await expect(history.first()).toContainText('Approved')
  await expect(history.first()).not.toContainText('Completed')
})
