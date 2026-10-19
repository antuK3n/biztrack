import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { HOME_BUILDER_HEADING, HOME_REPORTS, HOME_REPORTS_HEADING, sessionFor, waitForAnalytics } from './helpers'

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
 *
 * ── Where it lives, since later the same day ─────────────────────────────────
 *
 * It is no longer its own tab. The client: "what's the difference between
 * Report and Custom Report. I do think it is best to just merge them." It is
 * the second part of the Reports screen, "Build your own report", opened by
 * `?part=build`; and for BPLO and an office admin that screen is the Reports
 * sub-tab of Home's Analytics tab. The old /staff/analytics/custom-report
 * address lands there with the builder open.
 *
 * The standard reports stay MOUNTED above it, hidden, and carry their own
 * Office control and "Showing … figures" line. So every builder query below is
 * scoped to the builder's panel — a page-wide getByLabel('Office') would find
 * both.
 */
const BPLO_SESSION = sessionFor('bplo')
const SANITARY_SESSION = sessionFor('sanitary')

const BUILDER = `${HOME_REPORTS}&part=build`

/** Open the Reports screen on its builder, and return the builder's panel. */
async function openBuilder(page: Page) {
  await page.goto(BUILDER)
  await waitForAnalytics(page, HOME_BUILDER_HEADING.name, HOME_BUILDER_HEADING.level)
  return page.locator('#reports-build-panel')
}

test.describe('Custom Report, as BPLO', () => {
  test.use({ storageState: BPLO_SESSION })

  test('the builder is the second part of Reports, and the four steps read top to bottom', async ({ page }) => {
    const builder = await openBuilder(page)

    // No Custom Report tab: Reports is the open sub-tab.
    const strip = page.getByRole('navigation', { name: 'Analytics sections' })
    expect(await strip.getByRole('link').allInnerTexts()).toEqual(['Dashboard', 'Reports'])
    await expect(strip.getByRole('link', { name: 'Reports', exact: true })).toHaveAttribute('aria-current', 'page')

    await expect(page.getByRole('button', { name: /^Build your own report/ })).toHaveAttribute('aria-expanded', 'true')
    await expect(page.getByRole('button', { name: /^Standard reports/ })).toHaveAttribute('aria-expanded', 'false')

    for (const step of ['Count what?', 'Show these numbers', 'Only include…', 'Break it down by']) {
      await expect(builder.getByRole('heading', { name: new RegExp(step), level: 3 })).toBeVisible()
    }
    await expect(builder.getByRole('button', { name: 'Businesses', exact: true })).toHaveAttribute('aria-pressed', 'true')
    await expect(builder.getByLabel('Male employees')).toBeVisible()
  })

  test('the old Custom Report address opens Home’s Reports on the builder', async ({ page }) => {
    await page.goto('/staff/analytics/custom-report')
    await expect(page).toHaveURL(/\/staff\/dashboard\?tab=analytics&view=reports&part=build$/, { timeout: 30_000 })
    await waitForAnalytics(page, HOME_BUILDER_HEADING.name, HOME_BUILDER_HEADING.level)
    await expect(page.locator('#reports-build-panel').getByLabel('Male employees')).toBeVisible()
  })

  test('one press on its heading opens the builder from the standard reports, and folds them away', async ({ page }) => {
    await page.goto(HOME_REPORTS)
    await waitForAnalytics(page, HOME_REPORTS_HEADING.name, HOME_REPORTS_HEADING.level)
    await expect(page.locator('#reports-build-panel')).toBeHidden()

    await page.getByRole('button', { name: /^Build your own report/ }).click()
    await expect(page).toHaveURL(/[?&]part=build/)
    await expect(page.locator('#reports-build-panel')).toBeVisible()
    await expect(page.locator('#reports-standard-panel')).toBeHidden()
  })

  test('a starter fills the steps and draws a table with a Total row', async ({ page }) => {
    const builder = await openBuilder(page)

    await builder.getByRole('button', { name: 'Employees by sex' }).click()
    await expect(builder.getByLabel('Male employees')).toBeChecked()
    await expect(builder.getByLabel('Female employees')).toBeChecked()
    await expect(builder.getByLabel('First')).toHaveValue('barangay')

    const table = builder.getByRole('table')
    await expect(table).toBeVisible()
    await expect(table.getByRole('columnheader', { name: 'Barangay' })).toBeVisible()
    await expect(table.getByRole('rowheader', { name: 'Total' })).toBeVisible()
  })

  test('changing a choice after a run says the table is out of date', async ({ page }) => {
    const builder = await openBuilder(page)

    await builder.getByRole('button', { name: 'Businesses per barangay' }).click()
    await expect(builder.getByRole('table')).toBeVisible()

    await builder.getByLabel('Total employees').check()
    await expect(builder.getByText('The choices above have changed.')).toBeVisible()
    await expect(builder.getByRole('button', { name: 'Download Excel' })).toHaveAttribute('aria-disabled', 'true')

    await builder.getByRole('button', { name: 'Generate' }).click()
    await expect(builder.getByText('The choices above have changed.')).toHaveCount(0)
    await expect(builder.getByRole('table').getByRole('columnheader', { name: 'Total employees' })).toBeVisible()
  })

  test('a filter is added as a chip and removed again', async ({ page }) => {
    const builder = await openBuilder(page)

    await builder.getByRole('button', { name: '+ Add filter' }).click()
    await builder.getByLabel('Filter on').selectOption({ label: 'Form of organization' })
    await builder.getByRole('group', { name: 'Form of organization choices' }).getByLabel('Corporation').check()
    await builder.getByRole('button', { name: 'Apply' }).click()

    const chip = builder.getByRole('button', { name: /Change Form of organization filter/ })
    await expect(chip).toContainText('Corporation')

    await builder.getByRole('button', { name: 'Generate' }).click()
    await expect(builder.getByText('Only Form of organization: Corporation')).toBeVisible()

    await builder.getByRole('button', { name: 'Remove Form of organization filter' }).click()
    await expect(chip).toHaveCount(0)
  })

  test('switching to Payments offers dates and a month breakdown', async ({ page }) => {
    const builder = await openBuilder(page)

    await builder.getByRole('button', { name: 'Payments', exact: true }).click()
    await expect(builder.getByLabel('Paid from')).toBeVisible()
    await expect(builder.getByLabel('Amount collected (PHP)')).toBeVisible()
    await expect(builder.getByLabel('Male employees')).toHaveCount(0)
    await builder.getByLabel('First').selectOption({ label: 'Month' })
    await expect(builder.getByLabel('Then by')).toBeVisible()
  })

  test('the downloads are named for the subject and office', async ({ page }) => {
    const builder = await openBuilder(page)
    await builder.getByLabel('Office', { exact: true }).selectOption('all')
    await builder.getByRole('button', { name: 'Permits issued per type' }).click()
    await expect(builder.getByRole('table')).toBeVisible()

    for (const [label, ext] of [
      ['Excel', 'xlsx'],
      ['CSV', 'csv'],
      ['PDF', 'pdf'],
    ] as const) {
      const download = page.waitForEvent('download')
      await builder.getByRole('button', { name: `Download ${label}` }).click()
      expect((await download).suggestedFilename()).toMatch(new RegExp(`^custom-report-permits-\\d{4}-\\d{2}-\\d{2}\\.${ext}$`))
    }
  })

  test('a set-up is saved, re-run and deleted', async ({ page }) => {
    const name = `E2E barangays ${Date.now()}`
    const builder = await openBuilder(page)

    await builder.getByRole('button', { name: 'Businesses per barangay' }).click()
    await builder.getByRole('button', { name: 'Save…' }).click()
    await page.getByRole('dialog').getByLabel(/Name/).fill(name)
    await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click()
    await expect(builder.getByRole('status').filter({ hasText: `Saved as “${name}”` })).toBeVisible()

    // The reload keeps `part=build`, so the builder is still the open part.
    const saved = builder.getByRole('list', { name: 'Your saved reports' })
    await page.reload()
    await waitForAnalytics(page, HOME_BUILDER_HEADING.name, HOME_BUILDER_HEADING.level)
    await saved.getByRole('button', { name, exact: true }).click()
    await expect(builder.getByRole('table')).toBeVisible()

    await saved.getByRole('button', { name: `Delete ${name}` }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Delete' }).click()
    await expect(saved.getByRole('button', { name, exact: true })).toHaveCount(0)
  })
})

test.describe('Custom Report, as an office admin', () => {
  test.use({ storageState: SANITARY_SESSION })

  test('shows the office’s own figures with nothing to switch', async ({ page }) => {
    const builder = await openBuilder(page)

    await expect(builder.getByText(/Showing\s+City Health Office\s+figures/)).toBeVisible()
    await expect(builder.getByLabel('Office', { exact: true })).toHaveCount(0)
  })
})

test.describe('Custom Report on a phone', () => {
  test.use({ storageState: BPLO_SESSION, viewport: { width: 390, height: 844 } })

  test('the page never scrolls sideways; the table scrolls inside its box', async ({ page }) => {
    const builder = await openBuilder(page)
    await builder.getByRole('button', { name: 'Employees by sex' }).click()
    await expect(builder.getByRole('table')).toBeVisible()

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
    expect(overflow).toBeLessThanOrEqual(0)
  })
})
