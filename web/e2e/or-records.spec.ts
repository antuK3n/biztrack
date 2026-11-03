import { expect, test, type Page } from '@playwright/test'
import { mergedStorageState, sessionFor } from './helpers'
import { makeBilledApplication } from './payments'

/*
 * OR Records (thesis panel revisions, matrix item 5): "The OR number, date
 * paid, amount, and linked application are stored, and the records can be
 * searched and viewed."
 *
 * The owner sends the receipt and BPLO marks a billed filing paid at the
 * counter with its OR (through the API — the dialog has its own spec,
 * counter-payment.spec.ts), then finds it on OR Records by searching. The
 * super admin reaches the same page on its own site.
 */
test.describe.configure({ timeout: 180_000 })
test.use({ storageState: mergedStorageState(['owner.json', 'bplo.json']) })

const SHOTS = process.env.E2E_SHOTS_DIR

/**
 * The owner sends the receipt and the BPLO officer holding the filing marks it
 * paid at the counter with this OR, through the API. Answers the filing.
 */
async function paidAtCounter(page: Page, appId: number, orNumber: string): Promise<{ tracking: string; business: string }> {
  return page.evaluate(
    async ([id, or]) => {
      const headers = { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.staff')}` }
      const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })

      // The owner's receipt first, which Mark as paid waits for (Ken, 3 November 2026).
      const owner = { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}` }
      const receipt = new FormData()
      receipt.append('or_number', or)
      receipt.append('or_date', today)
      const sent = await fetch(`/api/v1/applications/${id}/payment-proofs`, { method: 'POST', headers: owner, body: receipt })
      if (!sent.ok) throw new Error(`payment-proofs answered ${sent.status}: ${await sent.text()}`)

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
}

test('BPLO finds a counter payment on OR Records by its OR number', async ({ page }) => {
  const appId = await makeBilledApplication(page)

  // Seven digits of its own, so a second run finds only this one.
  const orNumber = String(Date.now() % 10_000_000).padStart(7, '0')
  const paid = await paidAtCounter(page, appId, orNumber)

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

  // Nothing matches: the page says the search found nothing, not that there are no records.
  await search.fill('no such receipt anywhere')
  await expect(page.getByText('No OR records match your search.')).toBeVisible({ timeout: 15_000 })
  await expect(page.getByText('No OR records yet.')).toHaveCount(0)
})

test('the tracking ID opens BPLO’s review of the filing, and the super admin’s rows carry no link', async ({ page, browser }) => {
  const appId = await makeBilledApplication(page)
  const orNumber = String((Date.now() + 7) % 10_000_000).padStart(7, '0')
  const paid = await paidAtCounter(page, appId, orNumber)

  await page.goto('/staff/admin/or-records')
  await page.getByRole('searchbox', { name: 'Search OR no., tracking ID or business' }).fill(orNumber)
  const row = page.getByRole('table').getByRole('row').filter({ hasText: orNumber })
  await expect(row).toHaveCount(1, { timeout: 15_000 })

  // One link a row, named by the tracking ID; it opens the filing on the staff site.
  const link = row.getByRole('link', { name: paid.tracking, exact: true })
  await expect(link).toHaveAttribute('href', /^\/staff\/queue\/\d+$/)
  await expect(row.getByRole('link')).toHaveCount(1)
  await link.click()
  await expect(page).toHaveURL(/\/staff\/queue\/\d+$/)
  // BPLO's review of this filing, loaded and headed by its business.
  await expect(page.getByRole('heading', { level: 1, name: paid.business })).toBeVisible({ timeout: 30_000 })
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/or-records-opened.png` })

  // The super admin's site has no screen for a filing: the same row, no link.
  const admin = await browser.newContext({ storageState: sessionFor('admin') })
  const adminPage = await admin.newPage()
  await adminPage.goto('/admin/or-records')
  await adminPage.getByRole('searchbox', { name: 'Search OR no., tracking ID or business' }).fill(orNumber)
  const adminRow = adminPage.getByRole('table').getByRole('row').filter({ hasText: orNumber })
  await expect(adminRow).toHaveCount(1, { timeout: 30_000 })
  await expect(adminRow).toContainText(paid.tracking)
  await expect(adminRow.getByRole('link')).toHaveCount(0)
  await admin.close()
})

test('says "No OR records yet." only when nothing is searched for', async ({ page }) => {
  // The copied register holds ORs, so an empty one is answered here.
  await page.route(
    (url) => url.pathname === '/api/v1/or-records',
    (route) => route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } }),
  )

  await page.goto('/staff/admin/or-records')
  await expect(page.getByText('No OR records yet.')).toBeVisible({ timeout: 30_000 })
  await expect(page.getByText('No OR records match your search.')).toHaveCount(0)

  const search = page.getByRole('searchbox', { name: 'Search OR no., tracking ID or business' })
  await search.fill('4200001')
  await expect(page.getByText('No OR records match your search.')).toBeVisible({ timeout: 15_000 })
  await expect(page.getByText('No OR records yet.')).toHaveCount(0)

  // Cleared, it is the empty register again.
  await search.fill('')
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
