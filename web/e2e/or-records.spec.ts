import { expect, test } from '@playwright/test'
import { mergedStorageState, sessionFor } from './helpers'
import { makeBilledApplication } from './payments'

/*
 * OR Records (thesis panel revisions, matrix item 5): "The OR number, date
 * paid, amount, and linked application are stored, and the records can be
 * searched and viewed."
 *
 * BPLO marks a billed filing paid at the counter with its OR (through the API
 * — the dialog has its own spec, counter-payment.spec.ts), then finds it on
 * OR Records by searching. The super admin reaches the same page on its own
 * site.
 */
test.describe.configure({ timeout: 180_000 })
test.use({ storageState: mergedStorageState(['owner.json', 'bplo.json']) })

const SHOTS = process.env.E2E_SHOTS_DIR

test('BPLO finds a counter payment on OR Records by its OR number', async ({ page }) => {
  const appId = await makeBilledApplication(page)

  // Seven digits of its own, so a second run finds only this one.
  const orNumber = String(Date.now() % 10_000_000).padStart(7, '0')
  const paid = await page.evaluate(
    async ([id, or]) => {
      const headers = { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.staff')}` }
      const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })
      const body = new FormData()
      body.append('or_number', or)
      body.append('or_date', today)
      const res = await fetch(`/api/v1/applications/${id}/counter-payment`, { method: 'POST', headers, body })
      if (!res.ok) throw new Error(`counter-payment answered ${res.status}: ${await res.text()}`)
      const app = (await (await fetch(`/api/v1/applications/${id}`, { headers })).json()).data
      return { tracking: app.tracking_id as string, business: app.business?.name as string }
    },
    [appId, orNumber] as const,
  )

  await page.goto('/staff/admin/or-records')
  await expect(page.getByRole('heading', { name: 'OR Records' })).toBeVisible({ timeout: 30_000 })

  const search = page.getByRole('searchbox', { name: 'Search OR no., tracking ID or business' })
  await expect(search).toHaveAttribute('placeholder', 'Search OR no., tracking ID or business')
  await search.fill(orNumber)

  const table = page.getByRole('table')
  for (const column of ['OR No.', 'Date paid', 'Amount', 'Tracking ID', 'Business', 'Recorded by', 'Proof']) {
    await expect(table.getByRole('columnheader', { name: column })).toBeVisible()
  }
  const row = table.getByRole('row').filter({ hasText: orNumber })
  await expect(row).toHaveCount(1, { timeout: 15_000 })
  await expect(row).toContainText(paid.tracking)
  await expect(row).toContainText(paid.business)
  await expect(row).toContainText('Liza Reyes')
  // No photo was sent, so the Proof cell is a dash rather than a View.
  await expect(row.getByRole('button', { name: `View OR No. ${orNumber}` })).toHaveCount(0)

  // Searching by the tracking ID finds the same record.
  await search.fill(paid.tracking)
  await expect(table.getByRole('row').filter({ hasText: orNumber })).toHaveCount(1, { timeout: 15_000 })

  await search.fill(orNumber)
  await expect(table.getByRole('row').filter({ hasText: orNumber })).toHaveCount(1, { timeout: 15_000 })
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/oc-or-records.png`, fullPage: true })

  // Nothing matches: the page says there are no records rather than drawing an empty table.
  await search.fill('no such receipt anywhere')
  await expect(page.getByText('No OR records yet.')).toBeVisible({ timeout: 15_000 })
})

test.describe('the super admin', () => {
  test.use({ storageState: sessionFor('admin') })

  test('opens OR Records from its own rail', async ({ page }) => {
    await page.goto('/admin/dashboard')
    const entry = page.getByRole('navigation').getByRole('link', { name: 'OR Records' })
    await expect(entry).toHaveAttribute('href', '/admin/or-records', { timeout: 30_000 })
    await entry.click()
    await expect(page.getByRole('heading', { name: 'OR Records' })).toBeVisible({ timeout: 30_000 })
  })
})
