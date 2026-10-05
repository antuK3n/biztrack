import { expect, test } from '@playwright/test'
import { mergedStorageState } from './helpers'

/*
 * The review sheet carries no Messages thread (Ken, 6 October 2026), in BPLO's
 * seat or any office's. The conversation lives on the Messages page in the
 * rail, which stays.
 */
test.use({ storageState: mergedStorageState(['bplo.json']) })

test('the review sheet has no Messages section, and the rail still has Messages', async ({ page }) => {
  await page.goto('/staff/queue')
  const first = page.locator('ul li a[href^="/staff/queue/"]').first()
  const any = await first.waitFor({ timeout: 30_000 }).then(() => true, () => false)
  test.skip(!any, 'nothing on BPLO’s queue to open on this register')

  await first.click()
  await expect(page.getByRole('link', { name: 'Back to Manage Applications' })).toBeVisible({ timeout: 30_000 })
  await expect(page.getByRole('heading', { name: 'Messages', exact: true })).toHaveCount(0)
  await expect(page.getByPlaceholder(/Write to the applicant/)).toHaveCount(0)
  await expect(page.getByRole('link', { name: /^Messages/ }).first()).toBeVisible()
})
