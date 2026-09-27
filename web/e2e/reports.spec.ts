import { expect, test } from '@playwright/test'
import { sessionFor, waitForAnalytics } from './helpers'

/*
 * Report Generation (checklist "Manage Approved Permits – Ken", item 7).
 *
 * The figures and the office boundary are pinned in Pest (LguReportsTest).
 * What only a browser can show is pinned here: the screen IS the printed page —
 * City of Malabon header, the report, signature lines — and printing hides
 * everything else; the CSV arrives named for its office and period; the period
 * controls say what is wrong instead of fetching nonsense; and an office admin
 * is shown their own office with nothing to switch.
 */
const BPLO_SESSION = sessionFor('bplo')
const SANITARY_SESSION = sessionFor('sanitary')

const REPORTS = [
  'Permits Issued — New and Renewal',
  'Collections by Nature of Fee',
  'Businesses Permitted by Barangay and Kind of Business',
  'Clearances Issued per Office',
  'Processing Time and Pending Applications',
]

test.describe('Reports, as BPLO', () => {
  test.use({ storageState: BPLO_SESSION })

  test('each of the five reports renders as a City of Malabon document', async ({ page }) => {
    await page.goto('/staff/analytics/reports?from=2026-01-01&to=2026-09-30&office=all')
    await waitForAnalytics(page, 'Reports')

    const report = page.getByRole('article')
    for (const title of REPORTS) {
      await page.getByLabel('Report', { exact: true }).selectOption({ label: title })
      await expect(report.getByRole('heading', { level: 2 })).toHaveText(title)
      await expect(report).toContainText('City of Malabon')
      await expect(report).toContainText('For the period January 1, 2026 to September 30, 2026')
      await expect(report.locator('table').first()).toBeVisible()
      await expect(report).toContainText('Prepared by:')
      await expect(report).toContainText('Noted by:')
    }
  })

  test('switching office re-heads the document with that office', async ({ page }) => {
    await page.goto('/staff/analytics/reports?report=clearances')
    await waitForAnalytics(page, 'Reports')

    await page.getByLabel('Office', { exact: true }).selectOption('CHO')
    const report = page.getByRole('article')
    await expect(report.locator('header')).toContainText('City Health Office')
    await expect(page).toHaveURL(/office=CHO/)
  })

  test('the CSV is named for the report, office and period', async ({ page }) => {
    await page.goto('/staff/analytics/reports?report=permits-issued&from=2026-01-01&to=2026-06-30&office=CHO')
    await waitForAnalytics(page, 'Reports')
    await expect(page.getByRole('article')).toBeVisible()

    const download = page.waitForEvent('download')
    await page.getByRole('button', { name: 'Download CSV' }).click()
    expect((await download).suggestedFilename()).toBe('permits-issued-cho-2026-01-01-to-2026-06-30.csv')
  })

  test('a period that ends before it starts is refused on the screen', async ({ page }) => {
    await page.goto('/staff/analytics/reports?from=2026-09-10&to=2026-09-01')
    await waitForAnalytics(page, 'Reports')
    await expect(page.getByRole('alert')).toContainText('The end date has to be on or after the start date.')
    await expect(page.getByRole('article')).toHaveCount(0)
  })

  test('a preset sets the period', async ({ page }) => {
    await page.goto('/staff/analytics/reports')
    await waitForAnalytics(page, 'Reports')

    await page.getByRole('button', { name: 'This year' }).click()
    await expect(page.getByRole('button', { name: 'This year' })).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByLabel('From')).toHaveValue(`${new Date().getFullYear()}-01-01`)
  })

  test('printing shows the document and nothing else', async ({ page }) => {
    await page.goto('/staff/analytics/reports?report=collections')
    await waitForAnalytics(page, 'Reports')
    await expect(page.getByRole('article')).toBeVisible()

    await page.emulateMedia({ media: 'print' })
    await expect(page.getByRole('article')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Download CSV' })).toBeHidden()
    await expect(page.locator('aside').first()).toBeHidden()
  })
})

test.describe('Reports, as an office admin (City Health)', () => {
  test.use({ storageState: SANITARY_SESSION })

  test('opens on their own office with nothing to switch', async ({ page }) => {
    await page.goto('/staff/analytics/reports')
    await waitForAnalytics(page, 'Reports')

    await expect(page.getByRole('article').locator('header')).toContainText('City Health Office')
    await expect(page.getByLabel('Office', { exact: true })).toHaveCount(0)
  })

  test('a link naming another office is refused, not quietly narrowed', async ({ page }) => {
    await page.goto('/staff/analytics/reports?office=BFP')
    await waitForAnalytics(page, 'Reports')
    await expect(page.getByRole('article')).toHaveCount(0)
    await expect(page.getByText(/only see your own office/i)).toBeVisible()
  })
})
