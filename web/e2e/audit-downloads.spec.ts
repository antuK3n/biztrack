import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * AUDIT LOGS — "Downloaded a file" (thesis panel revision matrix, item 2:
 * "Every download is logged with the user, the file, and the date and time").
 *
 * Not stubbed, unlike audit-removed.spec.ts: what is pinned here is that a
 * real download made through the app reaches the trail and reads in words on
 * this screen. The super admin downloads the import template — a file every
 * register has and theirs to take — then finds the row under its own filter.
 * Pest covers each serving route and each refusal.
 */

test.use({ storageState: sessionFor('admin') })

test('a file downloaded through the app is on Audit Logs as "Downloaded a file", under a "Downloaded file" column', async ({ page }) => {
  await page.goto('/admin/import')
  const served = page.waitForResponse(
    (res) => res.url().includes('/api/v1/admin/legacy-imports/template') && res.ok(),
  )
  await page.getByRole('button', { name: 'Download the template' }).click()
  await served

  await page.goto('/admin/audit-logs')
  const filter = page.getByRole('button', { name: 'Downloaded a file', exact: true })
  const asked = page.waitForRequest((req) => req.url().includes('action=file.downloaded'))
  await filter.click()
  await asked
  await expect(filter).toHaveAttribute('aria-pressed', 'true')

  // Newest first: the row just written. Which person it names is pinned in
  // Pest against the account's id; here it is enough that one is named.
  const row = page.getByRole('row').nth(1)
  await expect(row.getByRole('cell').nth(1)).toHaveText('Downloaded a file')
  await expect(row.getByRole('cell').nth(2)).not.toHaveText(/^(Not recorded)?$/)
  await row.getByRole('button', { name: 'Details for Downloaded a file' }).click()
  await expect(page.getByText('biztrack-legacy-import-template.csv')).toBeVisible()

  // The fourth column is headed for what it holds under this filter. Matched
  // on the header's text, which CSS uppercases for display; the cells keep
  // reading as before ("PaymentProof #3", "Permit #198").
  const targetHeader = page.getByRole('columnheader').nth(3)
  await expect(targetHeader).toHaveText('Downloaded file')

  await page.screenshot({
    path: `${process.env.E2E_SCREENSHOT_DIR ?? '/tmp'}/dl-audit.png`,
    fullPage: true,
  })

  // Every other filter mixes subjects, so the header goes back to "Target".
  const all = page.getByRole('button', { name: 'All actions', exact: true })
  await all.click()
  await expect(all).toHaveAttribute('aria-pressed', 'true')
  await expect(targetHeader).toHaveText('Target')
})
