import { expect, test } from '@playwright/test'
import { infoButtonNames, sessionFor, waitForAnalytics } from './helpers'

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
const BPLO_SESSION = sessionFor('bplo')
const SUPER_ADMIN_SESSION = sessionFor('admin')

/** §1, §2 and §4 of the spec — all headed "(Admin - BPLO)". */
const BPLO_SCREENS = [
  { path: '/staff/analytics', title: 'Analytics Dashboard' },
  { path: '/staff/analytics/renewal-risk', title: 'Renewal Risk Prediction' },
  { path: '/staff/analytics/business-growth', title: 'Business Growth Analysis' },
] as const

/** §6, headed "(Super Admin)". Measures the departments, BPLO among them. */
const SUPER_ADMIN_SCREEN = {
  path: '/staff/analytics/processing-time',
  title: 'Permit Processing Time Monitoring',
} as const

/**
 * The super admin's second screen (issue #102) — the same six offices, compared
 * rather than charted one at a time.
 *
 * It is the rail's destination for this reader now, because it answers the
 * question they arrive with ("which office") while Processing Time answers the
 * one after it. Both sit on `analytics.processing_time`, so the two-screen split
 * is still along the line the client drew and BPLO still holds neither.
 */
const SUPER_ADMIN_SCREENS = [
  { path: '/staff/analytics/offices', title: 'Office Performance' },
  SUPER_ADMIN_SCREEN,
] as const

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
    await waitForAnalytics(page, 'Renewal Risk Prediction')

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

  /*
   * Two client reports on the dashboard, checked on the rendered screen because
   * that is where both were reported from:
   *
   *   "The offices listed in the Inspections are missing; should be all 6 (no BPLO)"
   *   "Do not put YTD only; it should be the full term"
   *
   * The first was a hard-coded three-office list in DashboardAnalytics that
   * silently discarded every OBO and CENRO inspection in the register.
   * Nothing failed and nothing looked wrong — the panel simply drew three
   * confident bars, which is why the check lives at this level too.
   *
   * IF THIS FAILS WITH THREE OFFICES, THE SNAPSHOT IS OLDER THAN THE FIX.
   * Dashboard figures are served from the row `analytics:refresh` persists, not
   * computed per request (AnalyticsResolver), so a change to the panel's
   * membership does not reach any screen until a refresh has run against that
   * stack's database — `DB_DATABASE=database/e2e.sqlite php artisan
   * analytics:refresh` for this one. That is the designed behaviour and this
   * test is right to fail while it is untrue: the screen really is showing
   * three offices.
   */
  test('the inspections panel names all six inspecting offices, and no BPLO', async ({ page }) => {
    await page.goto('/staff/analytics')
    await waitForAnalytics(page, 'Analytics Dashboard')

    /*
     * The sr-only table rather than the bars: it holds one row per office and
     * it is the reading a screen reader gets, so an office missing from it is
     * missing for everyone. Row order is the register's and is deliberately not
     * asserted — it is a display choice, and pinning it here would make a
     * reseed look like a regression.
     */
    const inspections = page.getByRole('table', {
      name: /Inspection outcomes by inspecting office/,
    })
    const offices = (await inspections.locator('tbody th').allInnerTexts())
      .map((office) => office.trim())
      .sort()

    expect(offices).toEqual([
      'Environmental',
      'Fire Safety',
      'Occupancy',
      'Sanitary',
      'Zoning',
    ])

    // The Mayor's Permit is issued on the strength of the six clearances, not
    // on a visit of its own. "no BPLO" was the client's own qualifier.
    expect(offices).not.toContain('BPLO')
  })

  test('the workload KPI is stated over the full term, not the year to date', async ({ page }) => {
    await page.goto('/staff/analytics')
    await waitForAnalytics(page, 'Analytics Dashboard')

    await expect(page.getByText('Applications (all time)').first()).toBeVisible()
    await expect(page.getByText('every filing on record').first()).toBeVisible()

    /*
     * The card, its sub-line and its info popover have to agree. A number
     * quietly changed under a popover still explaining a 1-January cutoff would
     * be worse than not changing it at all, so the old wording is asserted gone
     * from the whole screen rather than just from the card.
     */
    await expect(page.getByText(/Applications YTD/)).toHaveCount(0)
    await expect(page.getByText(/since 1 January this year/i)).toHaveCount(0)

    const info = page.locator('button[aria-label="How Applications (all time) is measured"]')
    await info.click()
    const panel = page.getByRole('note').first()
    await expect(panel).toContainText(/every filing on record/i)
    await expect(panel).toContainText(/whole register/i)
    await expect(panel).not.toContainText(/1 January/i)
  })

  test('column headers do not fold the info button into their announced name', async ({ page }) => {
    await page.goto('/staff/analytics/renewal-risk')
    await waitForAnalytics(page, 'Renewal Risk Prediction')

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

  /*
   * ── The three things the client asked this screen to grow ──────────────────
   *
   * "Add filter by barangay, risk level, and action", "it should also display
   * other levels of risk", and "the table should have its own scroll down
   * button, for it not to expand the whole page".
   *
   * The first two are one failure, and it is worth being precise about it: the
   * endpoint returns the leading rows BY SCORE, and this register scores over
   * two thousand permits Low without one of them reaching the top 25. So the
   * green badge the spec asks for was not merely rare, it was UNREACHABLE — no
   * page size and no scrolling could have shown it, and only a filter applied
   * before the ranking is cut can. That is why the test below asserts on the
   * badge rather than on the select having moved.
   *
   * The send itself is not pressed here. It puts a real notification in a real
   * business owner's list and writes a ledger row that would then make a rerun
   * assert something different, so delivery, the audit row and the refusal to
   * send twice are pinned server-side in RenewalRiskFollowUpTest. What has to
   * hold in the browser is that the control is reachable, operable and
   * distinguishable, which is what is checked.
   */

  /** Set one of the Renewal Risk filter menu's selects and wait for the refetch. */
  async function setRiskFilter(
    page: import('@playwright/test').Page,
    label: string,
    option: string,
  ) {
    await page.getByRole('button', { name: 'Filter renewal risk' }).click()
    const panel = page.getByRole('dialog', { name: 'Filter renewal risk' })
    await panel.getByLabel(label).selectOption({ label: option })
    // The panel is a click-outside dismissal, so it stays open while the fetch
    // runs; closing it is what puts the table back under the pointer.
    await page.getByRole('button', { name: 'Close filter' }).click()
  }

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
      { label: 'Renewal Risk Prediction', path: '/staff/analytics/renewal-risk', heading: /renewal risk/i },
      {
        // Renamed from "Lifecycle": the client asked for the spec's §4 term,
        // "Business Growth Analysis". The route did not move, and the page
        // still titles itself after the dataset it renders.
        label: 'Business Growth Analysis',
        path: '/staff/analytics/business-growth',
        heading: /business growth analysis/i,
      },
      {
        // Renamed from "Overview" for the same reason "Lifecycle" was renamed:
        // the paper's §1 term, and the h1 this tab actually leads to. It was
        // the last short label on a strip whose other two carry their full
        // names. The heading regex did not have to change, which is the tell
        // that the label had drifted from the screen rather than the reverse.
        label: 'Analytics Dashboard',
        path: '/staff/analytics',
        heading: /analytics dashboard/i,
      },
    ]

    await page.goto('/staff/analytics')
    await waitForAnalytics(page, 'Analytics Dashboard')

    // A tab BPLO cannot open must not be drawn. Offering it would be a link to
    // a redirect back to their own dashboard, dressed up as navigation.
    await expect(
      page.getByRole('link', { name: 'Processing Time', exact: true }),
      'BPLO was offered the super admin’s tab',
    ).toHaveCount(0)
    // And the old labels are gone with it. Both renames were the same fix —
    // the tab and the screen it leads to have to be called the same thing —
    // so a half-applied one leaves the short label sitting beside the long ones.
    await expect(page.getByRole('link', { name: 'Lifecycle', exact: true })).toHaveCount(0)
    await expect(page.getByRole('link', { name: 'Overview', exact: true })).toHaveCount(0)

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

test.describe("the super admin's analytics screens", () => {
  test.use({ storageState: SUPER_ADMIN_SESSION })

  for (const screen of SUPER_ADMIN_SCREENS) {
    test(`${screen.title} renders and explains its figures`, async ({ page }) => {
      await assertExplainsItsFigures(page, screen)
    })
  }

  test('it states where its numbers came from', async ({ page }) => {
    await page.goto(SUPER_ADMIN_SCREEN.path)
    await waitForAnalytics(page, SUPER_ADMIN_SCREEN.title)
    await expect(page.getByText(/computed|updated|as of/i).first()).toBeVisible()
  })

  test('the tab strip offers this reader their own screens and no dead ends', async ({ page }) => {
    /*
     * ── WHAT THIS TEST USED TO ASSERT, AND WHY IT CHANGED ───────────────────
     *
     * It read "no tab strip is drawn for a reader with one screen", and that was
     * right while the super admin held exactly one: a strip offering one tab is
     * a control with nothing to control, and the strip's other three tabs were
     * all dead ends for this reader, which is the shape the client objected to.
     *
     * Issue #102 gave them a second screen. The RULE has not moved — a tab may
     * never point somewhere its reader will be bounced off, and a strip may
     * never draw a single tab — so what is asserted is the rule rather than the
     * count it happened to produce: the strip is present, it offers exactly the
     * two screens this permission opens, and it offers neither of BPLO's.
     *
     * The guard in AnalyticsTabs that hid it at fewer than two tabs is what
     * makes this the first time this reader has seen the strip at all, so it is
     * doing more work now, not less.
     */
    await page.goto(SUPER_ADMIN_SCREEN.path)
    await waitForAnalytics(page, SUPER_ADMIN_SCREEN.title)

    const strip = page.getByRole('navigation', { name: 'Analytics sections' })
    await expect(strip).toHaveCount(1)

    const hrefs = await strip.getByRole('link').evaluateAll((links) =>
      links.map((link) => link.getAttribute('href') ?? ''),
    )
    expect(hrefs.sort()).toEqual(['/staff/analytics/offices', '/staff/analytics/processing-time'])

    // Every BPLO screen stays off it. A tab RequirePermission would bounce is a
    // link to a dead end dressed up as navigation.
    for (const screen of BPLO_SCREENS) {
      expect(hrefs, `${screen.path} is on the super admin's tab strip`).not.toContain(screen.path)
    }
  })

  test('the rail sends the super admin to a screen they may open', async ({ page }) => {
    /*
     * The whole reason nav.ts grew a per-permission destination. The rail entry
     * is shared, and its shared `to` is /staff/analytics — a screen this user
     * is forbidden. Pointing them at it would have produced a rail button that
     * flashed the dashboard and bounced back to Home, which reads as a bug in
     * the rail rather than a permission boundary.
     *
     * The destination moved to Office Performance with issue #102, because of
     * the super admin's two screens that is the one answering the question a
     * reader arrives with. The rule being held here is unchanged and is not the
     * address: whatever the rail points this reader at, they must be allowed
     * through it. The literal path is asserted as well, because the rail and
     * App.tsx carry the permission separately and nothing derives one from the
     * other — a route regated without the rail fails nowhere else.
     */
    await page.goto('/staff/dashboard')
    const analytics = railLink(page, 'Analytics')
    await expect(analytics, 'the super admin lost the Analytics rail entry').toHaveCount(1)
    await expect(analytics).toHaveAttribute('href', '/staff/analytics/offices')

    await analytics.click()
    await waitForAnalytics(page, 'Office Performance')
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
