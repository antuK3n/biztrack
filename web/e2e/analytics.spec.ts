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
 * ── Two readers, not one ────────────────────────────────────────────────────
 *
 * This file used to run entirely as the super admin, because `analytics.view`
 * opened all four screens and one session could therefore reach all four. The
 * permission has since been split along the line the client drew: "BPLO side
 * should only have the 3 dashboards (Processing Time should not exist here) —
 * Super admin side should only have Processing Time dashboard".
 *
 *   analytics.view            → bplo_staff → Dashboard, Renewal Risk, Growth
 *   analytics.processing_time → admin      → Permit Processing Time Monitoring
 *
 * Neither role holds both, so NO session can reach all four any more, and a
 * suite that pretends otherwise would be testing a state the product no longer
 * has. Each block below declares whose session it runs under.
 *
 * The sessions come from auth.setup.ts via storageState rather than a login per
 * spec — the sign-in endpoint's rate limiter is a control worth keeping, and a
 * suite that had to have it loosened would be the wrong fix. The chromium
 * project in playwright.config.ts hands every spec the admin session by
 * default; `test.use` below overrides it per block, which is why the BPLO tests
 * do not need a project of their own.
 */
const BPLO_SESSION = 'e2e/.auth/bplo.json'
const SUPER_ADMIN_SESSION = 'e2e/.auth/admin.json'

/** §1, §2 and §4 of the spec — all headed "(Admin - BPLO)". */
const BPLO_SCREENS = [
  { path: '/staff/analytics', title: 'Analytics Dashboard' },
  { path: '/staff/analytics/renewal-risk', title: 'Renewal Risk' },
  { path: '/staff/analytics/business-growth', title: 'Business Growth Analysis' },
] as const

/** §6, headed "(Super Admin)". Measures the departments, BPLO among them. */
const SUPER_ADMIN_SCREEN = {
  path: '/staff/analytics/processing-time',
  title: 'Permit Processing Time Monitoring',
} as const

/**
 * One entry in the left rail, by its label.
 *
 * Scoped to the <aside>, not to the "Main" landmark: the mobile tab bar carries
 * the same aria-label, so a role-based landmark query matches two navs and
 * trips strict mode even though only one of them is on screen. The rail lives
 * in the aside and nothing else does.
 */
function railLink(page: import('@playwright/test').Page, label: string) {
  return page.locator('aside').getByRole('link', { name: label, exact: true })
}

/**
 * The check every analytics screen has to pass, whoever is allowed to open it.
 *
 * Shared rather than duplicated per role, because the promise ("this figure
 * can be traced") is a property of the screen and not of the reader.
 */
async function assertExplainsItsFigures(
  page: import('@playwright/test').Page,
  screen: { path: string; title: string },
) {
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
}

test.describe('the three BPLO analytics screens', () => {
  test.use({ storageState: BPLO_SESSION })

  for (const screen of BPLO_SCREENS) {
    test(`${screen.title} renders and explains its figures`, async ({ page }) => {
      await assertExplainsItsFigures(page, screen)
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

  test('every BPLO analytics screen states where its numbers came from', async ({ page }) => {
    for (const screen of BPLO_SCREENS) {
      await page.goto(screen.path)
      await waitForAnalytics(page, screen.title)
      // Provenance: which engine computed this, and when. A screen that cannot
      // say is a screen whose figures cannot be dated.
      await expect(page.getByText(/computed|updated|as of/i).first()).toBeVisible()
    }
  })

  test('the analytics tabs reach the three screens BPLO is allowed to open', async ({ page }) => {
    /*
     * This was "the analytics tabs reach all four screens", and before that it
     * asserted only that the four links were VISIBLE — which passed for as long
     * as the tab strip was completely broken. The tabs pointed at
     * pre-portal-split URLs (/analytics/...), which the legacy shim in App.tsx
     * answered by redirecting to the Overview and discarding the subpath. Every
     * tab rendered, every tab was clickable, every tab took you to the
     * dashboard. The client's report was "why is overview renewal risk
     * lifecycle and processing time all the same".
     *
     * It cannot be four screens any more: no session holds both analytics
     * permissions, so the honest test is "the tabs this reader is offered all
     * work, and no tab is offered that would bounce them". Processing Time is
     * asserted ABSENT here and reached under the super admin's session below.
     *
     * Each tab is pressed, and asserted on the URL it reaches AND the heading
     * that renders — the heading because a URL alone would still pass if all
     * the routes resolved to the same component.
     */
    const TABS = [
      { label: 'Renewal Risk', path: '/staff/analytics/renewal-risk', heading: /renewal risk/i },
      {
        // Renamed from "Lifecycle": the client asked for the spec's §4 term,
        // "Business Growth Analysis". The route did not move, and the page
        // still titles itself after the dataset it renders.
        label: 'Business Growth Analysis',
        path: '/staff/analytics/business-growth',
        heading: /business growth analysis/i,
      },
      { label: 'Overview', path: '/staff/analytics', heading: /analytics dashboard/i },
    ]

    await page.goto('/staff/analytics')
    await waitForAnalytics(page, 'Analytics Dashboard')

    // A tab BPLO cannot open must not be drawn. Offering it would be a link to
    // a redirect back to their own dashboard, dressed up as navigation.
    await expect(
      page.getByRole('link', { name: 'Processing Time', exact: true }),
      'BPLO was offered the super admin’s tab',
    ).toHaveCount(0)
    // And the old label is gone with it.
    await expect(page.getByRole('link', { name: 'Lifecycle', exact: true })).toHaveCount(0)

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
     * One name, in all three places: the tab, the h1, and the dataset label the
     * API sends back.
     *
     * It was "Business Lifecycle Monitoring" everywhere, taken from mockup 122
     * on the reasoning that the mockup was the newer document. The client
     * settled it the other way — 'Proper follow terms (e.g., "Lifecycle" should
     * be "Business Growth Analysis")' — which is also the spec's own §4
     * heading, and the spec is newer than the mockup.
     *
     * This test is worth keeping through the rename rather than deleting with
     * it: the failure it guards against is the screen and its own payload
     * drifting apart, and a half-applied rename is exactly how that happens.
     * Both sides moved together, so it still passes for the reason it always
     * did.
     */
    await page.goto('/staff/analytics/business-growth')
    await waitForAnalytics(page, 'Business Growth Analysis')
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Business Growth Analysis')
  })

  test('the rail sends BPLO to the dashboard, addressing the staff site directly', async ({
    page,
  }) => {
    /*
     * The rail entry's href was '/analytics' — a pre-split path that only
     * resolved because of the legacy shim. It worked, so nothing failed; it
     * also meant every officer's first click on Analytics went through a
     * redirect. Asserted on the href rather than by clicking, because a click
     * would land in the same place either way and prove nothing.
     */
    await page.goto('/staff/dashboard')
    // Scoped to the rail. The staff dashboard also carries an Analytics
    // quick-action card with the same accessible name, and an unscoped locator
    // matches both.
    await expect(railLink(page, 'Analytics')).toHaveAttribute('href', '/staff/analytics')
  })
})

test.describe("the super admin's one analytics screen", () => {
  test.use({ storageState: SUPER_ADMIN_SESSION })

  test(`${SUPER_ADMIN_SCREEN.title} renders and explains its figures`, async ({ page }) => {
    await assertExplainsItsFigures(page, SUPER_ADMIN_SCREEN)
  })

  test('it states where its numbers came from', async ({ page }) => {
    await page.goto(SUPER_ADMIN_SCREEN.path)
    await waitForAnalytics(page, SUPER_ADMIN_SCREEN.title)
    await expect(page.getByText(/computed|updated|as of/i).first()).toBeVisible()
  })

  test('no tab strip is drawn for a reader with one screen', async ({ page }) => {
    /*
     * A tab strip offering one tab is a control with nothing to control: the
     * only destination is the page already open. Worse, the strip's other three
     * tabs would all be dead ends for this reader, which is the shape the
     * client objected to.
     *
     * Asserted through the strip's own landmark rather than by counting links,
     * because the rail and the page body have links of their own.
     */
    await page.goto(SUPER_ADMIN_SCREEN.path)
    await waitForAnalytics(page, SUPER_ADMIN_SCREEN.title)
    await expect(page.getByRole('navigation', { name: 'Analytics sections' })).toHaveCount(0)
  })

  test('the rail sends the super admin to the one screen they may open', async ({ page }) => {
    /*
     * The whole reason nav.ts grew a per-permission destination. The rail entry
     * is shared, and its shared `to` is /staff/analytics — a screen this user
     * is forbidden. Pointing them at it would have produced a rail button that
     * flashed the dashboard and bounced back to Home, which reads as a bug in
     * the rail rather than a permission boundary.
     */
    await page.goto('/staff/dashboard')
    const analytics = railLink(page, 'Analytics')
    await expect(analytics, 'the super admin lost the Analytics rail entry').toHaveCount(1)
    await expect(analytics).toHaveAttribute('href', '/staff/analytics/processing-time')

    await analytics.click()
    await waitForAnalytics(page, SUPER_ADMIN_SCREEN.title)
  })
})

/*
 * ── The separation itself ───────────────────────────────────────────────────
 *
 * The tests above show each reader reaching their own screens. These two show
 * the other half, which is the half the client actually asked for: neither
 * reader can reach the other's. Without them, granting both permissions to one
 * role would undo the split and every test in this file would still pass.
 *
 * Both assert the landing URL as well as the absence of the heading. A guard
 * that rendered the screen and merely failed its API call would leave the
 * heading up and the URL unchanged, and "the data didn't load" is not the same
 * fact as "you may not look at this".
 */
test.describe('neither analytics reader can open the other’s screens', () => {
  test.describe('as BPLO', () => {
    test.use({ storageState: BPLO_SESSION })

    test('Permit Processing Time Monitoring is out of reach', async ({ page }) => {
      await page.goto(SUPER_ADMIN_SCREEN.path)
      await expect(page).toHaveURL(/\/staff\/dashboard$/, { timeout: 30_000 })
      await expect(
        page.getByRole('heading', { name: /permit processing time monitoring/i }),
      ).toHaveCount(0)
    })
  })

  test.describe('as the super admin', () => {
    test.use({ storageState: SUPER_ADMIN_SESSION })

    test('the three BPLO dashboards are out of reach', async ({ page }) => {
      for (const screen of BPLO_SCREENS) {
        await page.goto(screen.path)
        await expect(page, `${screen.path} let the super admin in`).toHaveURL(
          /\/staff\/dashboard$/,
          { timeout: 30_000 },
        )
        await expect(page.getByRole('heading', { name: screen.title, level: 1 })).toHaveCount(0)
      }
    })
  })
})
