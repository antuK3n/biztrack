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
      await expect(report).not.toContainText('Prepared by:')
      await expect(report).not.toContainText('Noted by:')
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

  test('the grand total is the last row of its table, not a footer that repeats on every page', async ({
    page,
  }) => {
    await page.goto('/staff/analytics/reports?report=collections&from=2023-10-01&to=2026-10-01&office=BPLO')
    await waitForAnalytics(page, 'Reports')
    const report = page.getByRole('article')
    await expect(report.locator('table').first()).toBeVisible()

    // A <tfoot> is what print repeats at the foot of every page.
    await expect(report.locator('tfoot')).toHaveCount(0)
    const lastRows = await report.locator('table').evaluateAll((tables) =>
      tables.map((table) => {
        const rows = [...table.querySelectorAll('tbody tr')].filter(
          (row) => getComputedStyle(row).display !== 'none',
        )
        return rows[rows.length - 1]?.querySelector('th')?.textContent ?? ''
      }),
    )
    expect(lastRows).toEqual(['Total', 'Total', 'Total'])
  })

  test('every printed page after the first names the report, period and office, and is numbered', async ({
    page,
  }) => {
    await page.goto('/staff/analytics/reports?report=collections&from=2023-10-01&to=2026-10-01&office=BPLO')
    await waitForAnalytics(page, 'Reports')
    await expect(page.getByRole('article')).toBeVisible()

    // The running line and the page number live in @page margin boxes, built
    // from the report on screen; the first page leaves the running line out.
    const css = await page.locator('style').evaluateAll((styles) =>
      styles.map((s) => s.textContent ?? '').join('\n'),
    )
    expect(css).toMatch(
      /@top-left \{\s*content: "Collections by Nature of Fee · October 1, 2023 to October 1, 2026 · Business Permits and Licensing Office"/,
    )
    expect(css).toContain('content: "Page " counter(page) " of " counter(pages)')
    expect(css).toMatch(/@page :first \{\s*@top-left \{ content: none; \}/)
  })

  test('in print the end of the report rides in the last table with its last rows, so it never starts a page alone', async ({
    page,
  }) => {
    await page.goto('/staff/analytics/reports?report=collections&from=2023-10-01&to=2026-10-01&office=BPLO')
    await waitForAnalytics(page, 'Reports')
    const report = page.getByRole('article')
    await expect(report).toBeVisible()

    await page.emulateMedia({ media: 'print' })
    const tail = report.locator('tbody.lgu-report-tail')
    await expect(tail).toHaveCount(1)
    expect(await tail.evaluate((el) => getComputedStyle(el).breakInside)).toBe('avoid')
    // The last three months, the total and the closing line, in one unbreakable group.
    await expect(tail.locator('tr')).toHaveCount(5)
    await expect(tail).toContainText('October 2026')
    await expect(tail).toContainText('Total')
    await expect(tail.getByText('Generated from the BizTrack register')).toBeVisible()
    // Exactly one copy of the closing line on paper.
    await expect(report.getByText('Generated from the BizTrack register').filter({ visible: true })).toHaveCount(1)

    await page.emulateMedia({ media: 'screen' })
    await expect(tail.getByText('Generated from the BizTrack register')).toBeHidden()
    await expect(report.getByText('Generated from the BizTrack register').filter({ visible: true })).toHaveCount(1)
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

  test('a refusal is said plainly, without a "Try again" that would only be refused again', async ({
    page,
  }) => {
    await page.goto('/staff/analytics/reports?office=BFP')
    await waitForAnalytics(page, 'Reports')
    await expect(page.getByText(/only see your own office/i)).toBeVisible()
    await expect(page.getByRole('button', { name: 'Try again' })).toHaveCount(0)
    await expect(page.getByText("We couldn't load this")).toHaveCount(0)

    await page.getByRole('button', { name: 'Show my office instead' }).click()
    await expect(page).not.toHaveURL(/office=/)
    await expect(page.getByRole('article').locator('header')).toContainText('City Health Office')
  })
})
