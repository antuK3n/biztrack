import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { infoButtonNames, sessionFor, waitForAnalytics } from './helpers'

/*
 * The analytics screens, and who may open them.
 *
 * ── The shape since checklist 2026-09-27 (items 1, 6 and 7) ─────────────────
 *
 * ONE dashboard for every office. Each office admin opens it on their own
 * office; BPLO and the super admin get an Office menu with every office and
 * "All offices". The scoping is the server's (App\Support\AnalyticsOffice) and
 * is pinned in Pest — AnalyticsOfficeScopeTest. What is pinned here is what
 * only a browser shows: that the screen says whose figures it is showing, that
 * the menu is offered to exactly the two readers who have one, and that the
 * tabs and the rail lead nowhere a reader will be bounced from.
 *
 * Renewal Risk Prediction and Business Growth Analysis are gone (item 6); their
 * useful panels — Permits Approaching Expiry, New and Closed Businesses — are on
 * the dashboard. Reports (item 7) is the dashboard's neighbour tab and has its
 * own spec, reports.spec.ts.
 *
 *   analytics.view            → every office admin, BPLO, super admin
 *   analytics.processing_time → super admin only (Office Performance, Processing Time)
 *
 * Sessions come from auth.setup.ts via storageState (one sign-in per account
 * per run), so each block declares whose session it runs under.
 */
const BPLO_SESSION = sessionFor('bplo')
const SANITARY_SESSION = sessionFor('sanitary')
const SUPER_ADMIN_SESSION = sessionFor('admin')

const DASHBOARD = { path: '/staff/analytics', title: 'Analytics Dashboard' } as const

/** The rail entry, scoped to the <aside>: the mobile tab bar repeats the label. */
function railLink(page: Page, label: string) {
  return page.locator('aside').getByRole('link', { name: label, exact: true })
}

/** Every info button names a figure, reads "How … is measured", and none stutters. */
async function assertExplainsItsFigures(page: Page, screen: { path: string; title: string }) {
  await page.goto(screen.path)
  await waitForAnalytics(page, screen.title)

  const names = await infoButtonNames(page)
  expect(names.length, `${screen.path} shows no info affordance at all`).toBeGreaterThan(0)
  for (const name of names) {
    expect(name, `stuttering accessible name on ${screen.path}`).not.toMatch(/^How How /)
    expect(name).toMatch(/^How .+ is measured$/)
  }
  const dupes = names.filter((n, i) => names.indexOf(n) !== i)
  expect(new Set(dupes).size, `duplicate info labels on ${screen.path}: ${[...new Set(dupes)]}`)
    .toBeLessThanOrEqual(1)
}

/** Choose an office on the dashboard's menu and wait for that office's figures. */
async function chooseOffice(page: Page, value: string) {
  const answered = page.waitForResponse(
    (res) => res.url().includes('/api/v1/analytics/dashboard') && res.url().includes(`office=${value}`),
  )
  await page.getByLabel('Office', { exact: true }).selectOption(value)
  const res = await answered
  expect(res.status()).toBe(200)
  await waitForAnalytics(page, DASHBOARD.title)
}

test.describe('the dashboard, as BPLO', () => {
  test.use({ storageState: BPLO_SESSION })

  test('renders and explains its figures', async ({ page }) => {
    await assertExplainsItsFigures(page, DASHBOARD)
    await expect(page.getByText(/computed|updated|as of/i).first()).toBeVisible()
  })

  test('opens on BPLO and offers every office, and all of them', async ({ page }) => {
    await page.goto(DASHBOARD.path)
    await waitForAnalytics(page, DASHBOARD.title)

    const menu = page.getByLabel('Office', { exact: true })
    await expect(menu).toHaveValue('BPLO')
    const options = await menu.locator('option').evaluateAll((els) =>
      els.map((el) => (el as HTMLOptionElement).value),
    )
    expect(options.sort()).toEqual(['BFP', 'BPLO', 'CENRO', 'CHO', 'CPDO', 'OBO', 'all'])

    await chooseOffice(page, 'CHO')
    await expect(menu).toHaveValue('CHO')
  })

  test('an info panel opens on click, on keyboard focus, and closes on Escape', async ({ page }) => {
    await page.goto(DASHBOARD.path)
    await waitForAnalytics(page, DASHBOARD.title)

    const button = page.locator('button[aria-label^="How "]').first()
    await expect(button).toHaveAttribute('aria-expanded', 'false')

    // SC 1.4.13: dismissible, hoverable, persistent — and reachable by touch
    // and keyboard, which have no hover.
    await button.click()
    await expect(button).toHaveAttribute('aria-expanded', 'true')
    const panel = page.getByRole('note').first()
    await expect(panel).toContainText(/How it is measured/i)
    await expect(panel).toContainText(/What it covers/i)
    await expect(panel).toContainText(/Why it is here/i)

    await page.keyboard.press('Escape')
    await expect(button).toHaveAttribute('aria-expanded', 'false')

    await page.locator('body').click({ position: { x: 5, y: 5 } })
    await expect(button).not.toBeFocused()
    await button.focus()
    await expect(button).toHaveAttribute('aria-expanded', 'true')
  })

  /*
   * "The offices listed in the Inspections are missing; should be all 6 (no
   * BPLO)". Read across ALL offices — BPLO's own scope has no inspections,
   * because the Mayor's Permit is issued without a visit of its own.
   *
   * If this fails with three offices, the stack's snapshot is older than the
   * fix: run `analytics:refresh` against that stack's database.
   */
  test('the inspections panel names every inspecting office, and no BPLO', async ({ page }) => {
    await page.goto(DASHBOARD.path)
    await waitForAnalytics(page, DASHBOARD.title)
    await chooseOffice(page, 'all')

    const inspections = page.getByRole('table', { name: /Inspection outcomes by inspecting office/ })
    const offices = (await inspections.locator('tbody th').allInnerTexts()).map((o) => o.trim()).sort()

    expect(offices).toEqual(['Environmental', 'Fire Safety', 'Occupancy', 'Sanitary', 'Zoning'])
    expect(offices).not.toContain('BPLO')
  })

  test('the workload KPI is stated over the full term, not the year to date', async ({ page }) => {
    await page.goto(DASHBOARD.path)
    await waitForAnalytics(page, DASHBOARD.title)

    await expect(page.getByText('Applications (all time)').first()).toBeVisible()
    await expect(page.getByText('every filing on record').first()).toBeVisible()
    await expect(page.getByText(/Applications YTD/)).toHaveCount(0)

    await page.locator('button[aria-label="How Applications (all time) is measured"]').click()
    const panel = page.getByRole('note').first()
    await expect(panel).toContainText(/every filing on record/i)
    await expect(panel).not.toContainText(/1 January/i)
  })

  /*
   * Checklist item 6 moved two panels here rather than deleting them with
   * their screens. Both are asserted as a reader meets them: a heading, and
   * figures in a table a screen reader can read.
   */
  test('carries the two panels that came from the retired screens', async ({ page }) => {
    await page.goto(DASHBOARD.path)
    await waitForAnalytics(page, DASHBOARD.title)
    await chooseOffice(page, 'all')

    await expect(page.getByRole('heading', { name: 'Permits Approaching Expiry' })).toBeVisible()
    const expiry = page.getByRole('table', { name: /Permits approaching expiry by window/ })
    await expect(expiry.locator('tbody th')).toHaveText([
      'Within 30 days',
      'Within 60 days',
      'Within 90 days',
      'Already expired',
    ])

    await expect(page.getByRole('heading', { name: 'New and Closed Businesses' })).toBeVisible()
    await expect(page.locator('button[aria-label="How New and Closed Businesses is measured"]')).toHaveCount(1)
  })

  test('the tabs reach the dashboard and Reports, and nothing BPLO may not open', async ({ page }) => {
    await page.goto(DASHBOARD.path)
    await waitForAnalytics(page, DASHBOARD.title)

    const strip = page.getByRole('navigation', { name: 'Analytics sections' })
    const labels = await strip.getByRole('link').allInnerTexts()
    expect(labels).toEqual(['Analytics Dashboard', 'Reports'])

    await strip.getByRole('link', { name: 'Reports', exact: true }).click()
    await expect(page).toHaveURL(/\/staff\/analytics\/reports/)
    await waitForAnalytics(page, 'Reports')
  })

  test('the retired screens are gone, not redirected to something else', async ({ page }) => {
    for (const path of ['/staff/analytics/renewal-risk', '/staff/analytics/business-growth']) {
      await page.goto(path)
      await expect(page.getByRole('heading', { name: /renewal risk|business growth/i })).toHaveCount(0)
    }
  })

  test('Processing Time is still not BPLO’s to open', async ({ page }) => {
    await page.goto('/staff/analytics/processing-time')
    await expect(page).toHaveURL(/\/staff\/dashboard$/, { timeout: 30_000 })
  })

  test('the rail sends BPLO to the dashboard on the staff site', async ({ page }) => {
    await page.goto('/staff/dashboard')
    await expect(railLink(page, 'Analytics')).toHaveAttribute('href', '/staff/analytics')
  })

  test('the dashboard does not scroll sideways on a 390px phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto(DASHBOARD.path)
    await waitForAnalytics(page, DASHBOARD.title)

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow, 'the page scrolls sideways at 390px').toBeLessThanOrEqual(2)
  })
})

test.describe('the dashboard, as an office admin (City Health)', () => {
  test.use({ storageState: SANITARY_SESSION })

  test('the rail offers Analytics, and the screen says whose figures these are', async ({ page }) => {
    await page.goto('/staff/dashboard')
    await railLink(page, 'Analytics').click()
    await waitForAnalytics(page, DASHBOARD.title)

    await expect(page.getByText('Showing', { exact: false }).filter({ hasText: 'City Health Office' })).toBeVisible()
    // No menu: an office admin has nothing to choose.
    await expect(page.getByLabel('Office', { exact: true })).toHaveCount(0)
  })

  test('another office’s figures are refused even when asked for directly', async ({ page }) => {
    await page.goto(DASHBOARD.path)
    await waitForAnalytics(page, DASHBOARD.title)

    const statuses = await page.evaluate(async () => {
      const token = localStorage.getItem('biztrack.token.staff')
      const get = (q: string) =>
        fetch(`/api/v1/analytics/dashboard${q}`, {
          headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        }).then((r) => r.status)
      return [await get('?office=CHO'), await get('?office=BFP'), await get('?office=all')]
    })

    expect(statuses).toEqual([200, 403, 403])
  })

  test('the tabs offer the dashboard and Reports', async ({ page }) => {
    await page.goto(DASHBOARD.path)
    await waitForAnalytics(page, DASHBOARD.title)
    const strip = page.getByRole('navigation', { name: 'Analytics sections' })
    expect(await strip.getByRole('link').allInnerTexts()).toEqual(['Analytics Dashboard', 'Reports'])
  })
})

test.describe('the dashboard, as the super admin', () => {
  test.use({ storageState: SUPER_ADMIN_SESSION })

  test('opens on every office, with the Office menu', async ({ page }) => {
    await page.goto('/admin/analytics')
    await waitForAnalytics(page, DASHBOARD.title)
    await expect(page.getByLabel('Office', { exact: true })).toHaveValue('all')
  })

  test('the tab strip reaches all four screens, on the admin site', async ({ page }) => {
    await page.goto('/admin/analytics')
    await waitForAnalytics(page, DASHBOARD.title)

    const strip = page.getByRole('navigation', { name: 'Analytics sections' })
    const hrefs = await strip.getByRole('link').evaluateAll((links) =>
      links.map((link) => link.getAttribute('href') ?? ''),
    )
    expect(hrefs).toEqual([
      '/admin/analytics',
      '/admin/analytics/reports',
      '/admin/analytics/offices',
      '/admin/analytics/processing-time',
    ])

    await strip.getByRole('link', { name: 'Office Performance' }).click()
    await waitForAnalytics(page, 'Office Performance')
  })

  test('the rail sends the super admin to the dashboard', async ({ page }) => {
    await page.goto('/admin/dashboard')
    const analytics = railLink(page, 'Analytics')
    await expect(analytics).toHaveAttribute('href', '/admin/analytics')
    await analytics.click()
    await waitForAnalytics(page, DASHBOARD.title)
  })
})
