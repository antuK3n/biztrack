import { expect, test } from '@playwright/test'
import { infoButtonNames, waitForAnalytics } from './helpers'

/*
 * The four analytics screens, and the promise they make.
 *
 * Each one states figures an LGU officer is expected to act on, and the whole
 * argument for showing them is that a reader can check where they came from.
 * That promise is kept by `meta.definitions` reaching an info button beside
 * the figure — which means an empty definitions map is not a cosmetic fault,
 * it is the screen quietly dropping the only claim it makes for itself.
 *
 * Three of these four screens shipped in exactly that state: definitions
 * written for the dashboard, `[]` returned for the rest, every info button
 * rendering nothing. Nothing failed. That is what this file is for.
 */

/*
 * The admin session arrives from auth.setup.ts via storageState, so no test
 * here spends a login on proving something about analytics.
 */

const SCREENS = [
  { path: '/staff/analytics', title: 'Analytics Dashboard' },
  { path: '/staff/analytics/renewal-risk', title: 'Renewal Risk' },
  { path: '/staff/analytics/business-growth', title: 'Business Lifecycle Monitoring' },
  { path: '/staff/analytics/processing-time', title: 'Permit Processing Time Monitoring' },
] as const

for (const screen of SCREENS) {
  test(`${screen.title} renders and explains its figures`, async ({ page }) => {
    await page.goto(screen.path)
    await waitForAnalytics(page, screen.title)

    const names = await infoButtonNames(page)
    expect(names.length, `${screen.path} shows no info affordance at all`).toBeGreaterThan(0)

    /*
     * The button reads "How {label} is measured", so a label that is itself a
     * question stutters — "How How this list is built is measured" shipped
     * once and is invisible unless the name is read back. A label may not
     * begin with a verb-led question.
     */
    for (const name of names) {
      expect(name, `stuttering accessible name on ${screen.path}`).not.toMatch(/^How How /)
      expect(name).toMatch(/^How .+ is measured$/)
    }

    // Nothing renders twice under the same name for different figures.
    const dupes = names.filter((n, i) => names.indexOf(n) !== i)
    expect(new Set(dupes).size, `duplicate info labels on ${screen.path}: ${[...new Set(dupes)]}`)
      .toBeLessThanOrEqual(1)
  })
}

test('an info panel opens on click, on keyboard focus, and closes on Escape', async ({ page }) => {
  await page.goto('/staff/analytics/renewal-risk')
  await waitForAnalytics(page, 'Renewal Risk')

  const button = page.locator('button[aria-label^="How "]').first()
  await expect(button).toHaveAttribute('aria-expanded', 'false')

  /*
   * SC 1.4.13 says content revealed on hover or focus must be dismissible,
   * hoverable and persistent. Touch has no hover and keyboard has no pointer,
   * so all three doors are tested: an LGU officer on a tablet and one on a
   * screen reader are the two people most likely to need this panel.
   */
  await button.click()
  await expect(button).toHaveAttribute('aria-expanded', 'true')
  const panel = page.getByRole('note').first()
  await expect(panel).toBeVisible()
  await expect(panel).toContainText(/How it is measured/i)
  await expect(panel).toContainText(/What it covers/i)
  await expect(panel).toContainText(/Why it is here/i)

  await page.keyboard.press('Escape')
  await expect(button).toHaveAttribute('aria-expanded', 'false')

  /*
   * Escape dismisses without moving focus, which is the rest of SC 1.4.13 and
   * is why the button still holds focus here. Focus has to genuinely leave
   * before it can arrive again — calling focus() on the already-focused
   * element fires nothing, which is a property of the DOM and not of the
   * component.
   */
  await page.locator('body').click({ position: { x: 5, y: 5 } })
  await expect(button).not.toBeFocused()

  // Keyboard focus alone opens it — a keyboard user never learns the content
  // exists otherwise.
  await button.focus()
  await expect(button).toHaveAttribute('aria-expanded', 'true')
})

test('column headers do not fold the info button into their announced name', async ({ page }) => {
  await page.goto('/staff/analytics/renewal-risk')
  await waitForAnalytics(page, 'Renewal Risk')

  /*
   * A header cell takes its accessible name from its contents, and that name
   * is announced against every cell beneath it. With the button nested and
   * unnamed, every score in the column reads as "Risk score How Risk score is
   * measured". The fix is an explicit aria-label; this asserts it stayed.
   */
  for (const header of ['Risk score', 'Barangay', 'Expires', 'Business']) {
    const th = page.locator(`th[aria-label="${header}"]`)
    if ((await th.count()) === 0) continue
    await expect(th.first()).toHaveAttribute('aria-label', header)
  }
})

test('the renewal risk screen never calls its score a probability', async ({ page }) => {
  await page.goto('/staff/analytics/renewal-risk')
  await waitForAnalytics(page, 'Renewal Risk')

  /*
   * The paper and the mockup both labelled this column "PROB. DELAYED" with
   * percentages. Nothing is fitted and the register records no outcome to
   * have fitted against, so the wording is the only place the claim can be
   * made — see the honesty constraint in docs/r-integration-spec.md.
   *
   * Read from the rendered page rather than the source, because the words can
   * arrive from the server at runtime and a source grep would miss that.
   */
  const body = ((await page.locator('body').textContent()) ?? '').toLowerCase()
  for (const word of ['probability', 'likelihood', 'prob.', 'predicted']) {
    expect(body, `renewal risk screen says "${word}"`).not.toContain(word)
  }

  // The score is out of 100, never a percentage.
  await expect(page.getByText(/\/\s*100/).first()).toBeVisible()
})

test('every analytics screen states where its numbers came from', async ({ page }) => {
  for (const screen of SCREENS) {
    await page.goto(screen.path)
    await waitForAnalytics(page, screen.title)
    // Provenance: which engine computed this, and when. A screen that cannot
    // say is a screen whose figures cannot be dated.
    await expect(page.getByText(/computed|updated|as of/i).first()).toBeVisible()
  }
})

test('the analytics tabs reach all four screens', async ({ page }) => {
  /*
   * This test used to assert only that the four links were VISIBLE, and it
   * passed for as long as the tab strip was completely broken.
   *
   * The tabs pointed at pre-portal-split URLs (/analytics/...), which the
   * legacy shim in App.tsx answered by redirecting to the Overview and
   * discarding the subpath. So every tab rendered, every tab was clickable,
   * every tab took you to the dashboard. The client's report was "why is
   * overview renewal risk lifecycle and processing time all the same".
   *
   * A navigation test that never navigates is not a navigation test. Each tab
   * is now pressed, and asserted on the URL it reaches AND the heading that
   * renders — the heading because a URL alone would still pass if all four
   * routes resolved to the same component.
   */
  const TABS = [
    { label: 'Renewal Risk', path: '/staff/analytics/renewal-risk', heading: /renewal risk/i },
    {
      label: 'Lifecycle',
      path: '/staff/analytics/business-growth',
      heading: /business lifecycle monitoring/i,
    },
    {
      label: 'Processing Time',
      path: '/staff/analytics/processing-time',
      heading: /permit processing time monitoring/i,
    },
    { label: 'Overview', path: '/staff/analytics', heading: /analytics dashboard/i },
  ]

  await page.goto('/staff/analytics')
  await waitForAnalytics(page, 'Analytics Dashboard')

  for (const tab of TABS) {
    await page.getByRole('link', { name: tab.label, exact: true }).first().click()
    await expect(page, `the ${tab.label} tab did not change the URL`).toHaveURL(
      new RegExp(`${tab.path}$`),
    )
    await expect(
      page.getByRole('heading', { name: tab.heading }).first(),
      `the ${tab.label} tab did not render its own screen`,
    ).toBeVisible({ timeout: 30_000 })
  }
})

test('a link made before the portal split still lands on the right screen', async ({ page }) => {
  /*
   * The shim for /analytics/* exists for bookmarks and already-sent
   * notifications. It threw the subpath away, so every one of them arrived at
   * the Overview — and that silent absorption is what kept the broken tab
   * strip above from ever looking broken.
   */
  await page.goto('/analytics/renewal-risk')
  await expect(page).toHaveURL(/\/staff\/analytics\/renewal-risk$/)
  await expect(page.getByRole('heading', { name: /renewal risk/i }).first()).toBeVisible({
    timeout: 30_000,
  })
})

test('the growth screen agrees with its own API about its name', async ({ page }) => {
  /*
   * The spec splits naming from formulas: mockup wins on naming, paper wins on
   * formulas. The page and the tab strip had reversed the first half, so a
   * screen titled "Business Growth Analysis" rendered a dataset labelled
   * "Business Lifecycle Monitoring".
   */
  await page.goto('/staff/analytics/business-growth')
  await waitForAnalytics(page, 'Business Lifecycle Monitoring')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Business Lifecycle Monitoring')
})
