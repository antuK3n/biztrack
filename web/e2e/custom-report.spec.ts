import { expect, test } from '@playwright/test'
import { sessionFor, waitForAnalytics } from './helpers'

/*
 * The Custom Report builder (client, 19 October 2026).
 *
 * The figures, the whitelist, the office boundary and saved set-ups are pinned
 * in Pest (CustomReportTest). What only a browser can show is pinned here: the
 * four steps read as one screen, a starter fills them and runs, the table has
 * a Total row, a choice changed after a run says the table is out of date, a
 * filter is added and removed as a chip, the downloads arrive named for the
 * subject, a set-up can be saved and deleted, and nothing scrolls sideways on
 * a phone.
 */
const BPLO_SESSION = sessionFor('bplo')
const SANITARY_SESSION = sessionFor('sanitary')

test.describe('Custom Report, as BPLO', () => {
  test.use({ storageState: BPLO_SESSION })

  test('the tab sits beside Reports and the four steps read top to bottom', async ({ page }) => {
    await page.goto('/staff/analytics/custom-report')
    await waitForAnalytics(page, 'Custom Report')

    await expect(page.getByRole('navigation', { name: 'Analytics sections' }).getByRole('link', { name: 'Custom Report' })).toBeVisible()
    for (const step of ['Count what?', 'Show these numbers', 'Only include…', 'Break it down by']) {
      await expect(page.getByRole('heading', { name: new RegExp(step) })).toBeVisible()
    }
    await expect(page.getByRole('button', { name: 'Businesses', exact: true })).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByLabel('Male employees')).toBeVisible()
  })

  test('a starter fills the steps and draws a table with a Total row', async ({ page }) => {
    await page.goto('/staff/analytics/custom-report')
    await waitForAnalytics(page, 'Custom Report')

    await page.getByRole('button', { name: 'Employees by sex' }).click()
    await expect(page.getByLabel('Male employees')).toBeChecked()
    await expect(page.getByLabel('Female employees')).toBeChecked()
    await expect(page.getByLabel('First')).toHaveValue('barangay')

    const table = page.getByRole('table')
    await expect(table).toBeVisible()
    await expect(table.getByRole('columnheader', { name: 'Barangay' })).toBeVisible()
    await expect(table.getByRole('rowheader', { name: 'Total' })).toBeVisible()
  })

  test('changing a choice after a run says the table is out of date', async ({ page }) => {
    await page.goto('/staff/analytics/custom-report')
    await waitForAnalytics(page, 'Custom Report')

    await page.getByRole('button', { name: 'Businesses per barangay' }).click()
    await expect(page.getByRole('table')).toBeVisible()

    await page.getByLabel('Total employees').check()
    await expect(page.getByText('The choices above have changed.')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Download Excel' })).toHaveAttribute('aria-disabled', 'true')

    await page.getByRole('button', { name: 'Generate' }).click()
    await expect(page.getByText('The choices above have changed.')).toHaveCount(0)
    await expect(page.getByRole('table').getByRole('columnheader', { name: 'Total employees' })).toBeVisible()
  })

  test('a filter is added as a chip and removed again', async ({ page }) => {
    await page.goto('/staff/analytics/custom-report')
    await waitForAnalytics(page, 'Custom Report')

    await page.getByRole('button', { name: '+ Add filter' }).click()
    await page.getByLabel('Filter on').selectOption({ label: 'Form of organization' })
    await page.getByRole('group', { name: 'Form of organization choices' }).getByLabel('Corporation').check()
    await page.getByRole('button', { name: 'Apply' }).click()

    const chip = page.getByRole('button', { name: /Change Form of organization filter/ })
    await expect(chip).toContainText('Corporation')

    await page.getByRole('button', { name: 'Generate' }).click()
    await expect(page.getByText('Only Form of organization: Corporation')).toBeVisible()

    await page.getByRole('button', { name: 'Remove Form of organization filter' }).click()
    await expect(chip).toHaveCount(0)
  })

  test('switching to Payments offers dates and a month breakdown', async ({ page }) => {
    await page.goto('/staff/analytics/custom-report')
    await waitForAnalytics(page, 'Custom Report')

    await page.getByRole('button', { name: 'Payments', exact: true }).click()
    await expect(page.getByLabel('Paid from')).toBeVisible()
    await expect(page.getByLabel('Amount collected (PHP)')).toBeVisible()
    await expect(page.getByLabel('Male employees')).toHaveCount(0)
    await page.getByLabel('First').selectOption({ label: 'Month' })
    await expect(page.getByLabel('Then by')).toBeVisible()
  })

  test('the downloads are named for the subject and office', async ({ page }) => {
    await page.goto('/staff/analytics/custom-report')
    await waitForAnalytics(page, 'Custom Report')
    await page.getByLabel('Office', { exact: true }).selectOption('all')
    await page.getByRole('button', { name: 'Permits issued per type' }).click()
    await expect(page.getByRole('table')).toBeVisible()

    for (const [label, ext] of [
      ['Excel', 'xlsx'],
      ['CSV', 'csv'],
      ['PDF', 'pdf'],
    ] as const) {
      const download = page.waitForEvent('download')
      await page.getByRole('button', { name: `Download ${label}` }).click()
      expect((await download).suggestedFilename()).toMatch(new RegExp(`^custom-report-permits-\\d{4}-\\d{2}-\\d{2}\\.${ext}$`))
    }
  })

  test('a set-up is saved, re-run and deleted', async ({ page }) => {
    const name = `E2E barangays ${Date.now()}`
    await page.goto('/staff/analytics/custom-report')
    await waitForAnalytics(page, 'Custom Report')

    await page.getByRole('button', { name: 'Businesses per barangay' }).click()
    await page.getByRole('button', { name: 'Save…' }).click()
    await page.getByRole('dialog').getByLabel(/Name/).fill(name)
    await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click()
    await expect(page.getByRole('status').filter({ hasText: `Saved as “${name}”` })).toBeVisible()

    const saved = page.getByRole('list', { name: 'Your saved reports' })
    await page.reload()
    await waitForAnalytics(page, 'Custom Report')
    await saved.getByRole('button', { name, exact: true }).click()
    await expect(page.getByRole('table')).toBeVisible()

    await saved.getByRole('button', { name: `Delete ${name}` }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Delete' }).click()
    await expect(saved.getByRole('button', { name, exact: true })).toHaveCount(0)
  })
})

test.describe('Custom Report, as an office admin', () => {
  test.use({ storageState: SANITARY_SESSION })

  test('shows the office’s own figures with nothing to switch', async ({ page }) => {
    await page.goto('/staff/analytics/custom-report')
    await waitForAnalytics(page, 'Custom Report')

    await expect(page.getByText(/Showing\s+City Health Office\s+figures/)).toBeVisible()
    await expect(page.getByLabel('Office', { exact: true })).toHaveCount(0)
  })
})

test.describe('Custom Report on a phone', () => {
  test.use({ storageState: BPLO_SESSION, viewport: { width: 390, height: 844 } })

  test('the page never scrolls sideways; the table scrolls inside its box', async ({ page }) => {
    await page.goto('/staff/analytics/custom-report')
    await waitForAnalytics(page, 'Custom Report')
    await page.getByRole('button', { name: 'Employees by sex' }).click()
    await expect(page.getByRole('table')).toBeVisible()

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
    expect(overflow).toBeLessThanOrEqual(0)
  })
})
