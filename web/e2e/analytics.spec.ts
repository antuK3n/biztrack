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
  { path: '/staff/analytics/renewal-risk', title: 'Renewal Risk Prediction' },
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
   * silently discarded every OBO, CENRO and Market inspection in the register.
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
      'Market',
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
   * ── The panel the client moved, and the column they rebuilt ────────────────
   *
   * "Permits approaching expiry, put it in renewal risk prediction. the first
   * column, it wont be 30 60 90d, make it into active/compliant, near expiry,
   * pending renewal, overdue expired."
   *
   * Two properties, and both are the kind that a passing unit test can still
   * leave broken in the browser: the panel is on the screen the officer works
   * from and NOT on the one it came from, and the four counts a reader can see
   * add up to the population the same screen claims to cover. The arithmetic is
   * pinned server-side in RenewalRiskLifecycleTest; what is pinned here is that
   * the figures which reconcile are the figures actually rendered.
   */
  test('shows the four permit states on Renewal Risk and nowhere else', async ({ page }) => {
    await page.goto('/staff/analytics/renewal-risk')
    await waitForAnalytics(page, 'Renewal Risk Prediction')

    const panel = page.getByRole('table', { name: /permits on the renewal watchlist/i })
    await expect(panel).toBeVisible()

    /*
     * The client's four states, in the client's order. Read as row headers so
     * this fails if they are ever rendered as anything a reader cannot see —
     * "Never Color Alone": every state has to survive with the colour removed.
     */
    const states = ['Active / Compliant', 'Near Expiry', 'Pending Renewal', 'Overdue / Expired']
    const headers = await panel.locator('tbody th').allInnerTexts()

    for (const [index, state] of states.entries()) {
      expect(headers[index], 'the four states, in the order the client listed them')
        .toContain(state)
    }

    /*
     * Mutually exclusive and total, as the reader sees it: the four row totals
     * add up to the "N permits scored" the table footer prints. If these ever
     * disagree, one of the two panels is describing a different set of permits
     * and nothing on the page says which.
     *
     * The footer count is pulled out by pattern rather than by selector because
     * the number and its label are two text nodes in one paragraph that also
     * carries the paging range and the window dates — reading the whole element
     * and stripping non-digits would silently concatenate five figures.
     */
    const totals = await panel.locator('tbody tr td:last-child').allInnerTexts()
    const summed = totals.reduce((run, cell) => run + Number(cell.replace(/[^0-9]/g, '')), 0)

    const footer = (await page.getByText(/permits scored/).last().innerText()) ?? ''
    const scored = /([\d,]+)\s+permits scored/.exec(footer)?.[1]

    expect(scored, 'the footer no longer states how many permits were scored').toBeTruthy()
    expect(summed).toBe(Number((scored ?? '').replace(/,/g, '')))

    /* Never the error red for Near Expiry (DESIGN.md — red is for errors). */
    const nearExpiryDot = panel
      .locator('tbody tr')
      .nth(1)
      .locator('span[aria-hidden="true"]')
      .first()
    const dotColour = await nearExpiryDot.evaluate((el) => getComputedStyle(el).backgroundColor)
    expect(dotColour, 'Near Expiry must not use the error red').not.toMatch(
      /rgb\(193, 18, 18\)|rgb\(189, 0, 0\)/,
    )
  })

  test('the analytics dashboard no longer carries the expiry panel', async ({ page }) => {
    await page.goto('/staff/analytics')
    await waitForAnalytics(page, 'Analytics Dashboard')

    // Moved, not duplicated. A heading left behind here is the failure this
    // guards: two screens counting one population, drifting apart quietly.
    await expect(page.getByRole('heading', { name: /permits approaching expiry/i })).toHaveCount(0)
    await expect(page.getByRole('heading', { name: /permit lifecycle/i })).toHaveCount(0)

    // And the banding that was rejected went with it, rather than surviving in
    // a table that merely lost its heading.
    const body = (await page.locator('body').textContent()) ?? ''
    expect(body).not.toMatch(/Next 30d|Next 60d|Next 90d/)
  })

  test('the renewal risk screen never calls its score a probability', async ({ page }) => {
    await page.goto('/staff/analytics/renewal-risk')
    await waitForAnalytics(page, 'Renewal Risk Prediction')

    /*
     * The paper and the mockup both labelled this column "PROB. DELAYED" with
     * percentages. Nothing is fitted and the register records no outcome to
     * have fitted against, so the wording is the only place the claim can be
     * made — see the honesty constraint in docs/r-integration-spec.md.
     *
     * Read from the rendered page rather than the source, because the words can
     * arrive from the server at runtime and a source grep would miss that.
     */
    /*
     * "predicted", NOT "prediction" — and the difference is the whole point,
     * so do not tidy this list by adding the shorter stem.
     *
     * The screen is titled "Renewal Risk Prediction" because that is the
     * paper's §2 name for the FEATURE, and the client asked for the paper's
     * terms. Naming a feature is not a claim about a number. "Predicted",
     * "probability" and "prob." are claims about the number, and the number is
     * a weighted sum of five rules with no fitted model behind it and no
     * recorded outcome to have fitted against.
     *
     * Banning the stem would force the screen to contradict the paper to pass
     * its own honesty test, which is the wrong trade in both directions.
     */
    const body = ((await page.locator('body').textContent()) ?? '').toLowerCase()
    for (const word of ['probability', 'likelihood', 'prob.', 'predicted']) {
      expect(body, `renewal risk screen says "${word}"`).not.toContain(word)
    }

    // The score is out of 100, never a percentage.
    await expect(page.getByText(/\/\s*100/).first()).toBeVisible()
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

  test('the risk level filter reaches rows the ranking alone never shows', async ({ page }) => {
    await page.goto('/staff/analytics/renewal-risk')
    await waitForAnalytics(page, 'Renewal Risk Prediction')

    const table = page.getByRole('region', { name: /businesses requiring review/i })

    // The default is worst-first, which is right for a follow-up screen: the
    // top of an unfiltered watchlist is High risk and nothing else.
    await expect(table.getByText('High risk').first()).toBeVisible()

    await setRiskFilter(page, 'Risk level', 'Low risk')

    /*
     * The badge states its level in text (DESIGN.md, Never Color Alone), so
     * this asserts the words rather than the colour — and it is the same
     * assertion a colour-blind officer's reading depends on.
     */
    await expect(table.getByText('Low risk').first()).toBeVisible({ timeout: 30_000 })
    await expect(table.getByText('High risk')).toHaveCount(0)

    // The summary cards keep describing every scored permit, not the page, so
    // the count on the Low card is the size of the set just filtered to.
    await expect(page.getByText(/showing 1–\d+ of [\d,]+/i)).toBeVisible()
  })

  test('the action filter is offered for all three recommended actions', async ({ page }) => {
    await page.goto('/staff/analytics/renewal-risk')
    await waitForAnalytics(page, 'Renewal Risk Prediction')

    await page.getByRole('button', { name: 'Filter renewal risk' }).click()
    const panel = page.getByRole('dialog', { name: 'Filter renewal risk' })

    // Every filter the client named, each a labelled select rather than an
    // unnamed glyph — a control you cannot name is a control a screen-reader
    // user cannot operate.
    for (const label of ['Window', 'Barangay', 'Risk level', 'Recommended action', 'Rows per page']) {
      await expect(panel.getByLabel(label)).toBeVisible()
    }

    const actions = panel.getByLabel('Recommended action')
    for (const option of ['Immediate follow-up', 'Send reminder', 'Monitor']) {
      await expect(actions.getByRole('option', { name: option })).toHaveCount(1)
    }

    // And barangay is a real list off the register, not a placeholder.
    const barangays = await panel.getByLabel('Barangay').getByRole('option').count()
    expect(barangays, 'the barangay filter offers nothing to filter by').toBeGreaterThan(1)
  })

  test('the table scrolls itself, and a keyboard can scroll it', async ({ page }) => {
    await page.goto('/staff/analytics/renewal-risk')
    await waitForAnalytics(page, 'Renewal Risk Prediction')

    const table = page.getByRole('region', { name: /businesses requiring review/i })
    await expect(table).toBeVisible()

    /*
     * A scrollable box that is not focusable cannot be scrolled from the
     * keyboard at all — the arrow keys act on the page behind it — so an
     * officer working without a mouse would simply never reach the rows below
     * the fold. tabindex="0" plus a name is the whole fix, and it is invisible
     * unless something asserts it.
     */
    await expect(table).toHaveAttribute('tabindex', '0')
    await table.focus()
    await expect(table).toBeFocused()

    // It is bounded, which is the client's actual complaint: the page must not
    // grow with the row count.
    const box = await table.boundingBox()
    expect(box, 'the table has no box').not.toBeNull()
    expect(box!.height, 'the table is not capped, so the page grows with it').toBeLessThan(700)

    const scrolled = await table.evaluate((el) => {
      const before = el.scrollTop
      el.scrollTop = 200
      return { before, after: el.scrollTop }
    })
    expect(scrolled.after, 'the region does not scroll its own content').toBeGreaterThan(
      scrolled.before,
    )

    // The header has to survive the scroll, or a column of numbers thirty rows
    // down is a column of numbers with no name.
    await expect(page.locator('th[aria-label="Risk index"]')).toBeInViewport()
  })

  test('a follow-up button names the business it would contact', async ({ page }) => {
    await page.goto('/staff/analytics/renewal-risk')
    await waitForAnalytics(page, 'Renewal Risk Prediction')

    const table = page.getByRole('region', { name: /businesses requiring review/i })
    const buttons = table.getByRole('button', { name: /notify .+ about permit/i })

    await expect(buttons.first()).toBeVisible()

    /*
     * The names must differ. A business commonly holds its business, sanitary
     * and fire permits with the same expiry, so a name built from the business
     * alone announces three identical buttons in three adjacent rows — the same
     * fault this screen already fixed once for the "Why" disclosures.
     */
    const names = await buttons.evaluateAll((els) =>
      els.map((el) => el.getAttribute('aria-label') ?? ''),
    )
    expect(names.length).toBeGreaterThan(1)
    expect(new Set(names).size, `repeated button names: ${names.join(' | ')}`).toBe(names.length)

    // Reachable and operable, not a decoration. `disabled` would drop it out of
    // the tab order (DESIGN.md); this asserts it did not.
    await expect(buttons.first()).toHaveAttribute('aria-disabled', 'false')
    await expect(buttons.first()).toHaveAttribute('aria-describedby', /reminder-note/)
  })

  test('the screen does not scroll sideways on a 390px phone', async ({ page }) => {
    /*
     * The growth screen learned this one the hard way: an `sr-only` table left
     * the whole page scrolling horizontally. Here the risk is the same table,
     * six columns wide — which is why the horizontal overflow is INSIDE the
     * scroll region rather than on the document.
     */
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto('/staff/analytics/renewal-risk')
    await waitForAnalytics(page, 'Renewal Risk Prediction')

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    // A pixel or two is sub-pixel rounding; a column's worth is a bug.
    expect(overflow, 'the page scrolls sideways at 390px').toBeLessThanOrEqual(2)
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

  test('the industry chart lets the reader choose which six, and says what it left out', async ({
    page,
  }) => {
    /*
     * A panelist asked whether there is any criterion for which line of business
     * appears in the Business Industry Growth Trend, and what happens if all of
     * them do. The register holds 135 PSIC codes; six series is what the palette
     * can keep apart without colour. So the answer is a lens toggle over the
     * same six slots — and the honesty of it is a browser fact, which is why it
     * is tested here and not only in pest:
     *
     *  - the toggle is a real control with an accessible name, not three loose
     *    words above a chart;
     *  - the exclusion the growth floor makes is ON SCREEN, in a number;
     *  - the chart, its legend and the hidden table all move together, because
     *    the hidden table is the only copy of this data a screen reader gets and
     *    a stale one is worse than none.
     */
    await page.goto('/staff/analytics/business-growth')
    await waitForAnalytics(page, 'Business Growth Analysis')

    const lenses = page.getByRole('group', { name: 'Which six industries' })
    await expect(lenses).toBeVisible()

    const largest = lenses.getByRole('button', { name: 'Largest' })
    const growing = lenses.getByRole('button', { name: 'Fastest growing' })
    const declining = lenses.getByRole('button', { name: 'Fastest declining' })

    // Largest is what shipped, so it is what opens — nobody's saved reading of
    // this screen changes underneath them.
    await expect(largest).toHaveAttribute('aria-pressed', 'true')
    await expect(growing).toHaveAttribute('aria-pressed', 'false')

    /*
     * Never the native `disabled` attribute, not even on a lens with nothing to
     * draw: it takes the button out of the tab order and most screen readers
     * walk straight past, so a reader working the strip by keyboard would meet
     * two lenses and conclude the chart offers two questions. `aria-disabled`
     * is the one that may appear. Same rule as the thin-office cards on
     * Processing Time. Asserted on the DOM property rather than through
     * `toBeDisabled`, which treats the two as the same thing — and the whole
     * point here is that they are not.
     */
    for (const lens of [largest, growing, declining]) {
      expect(await lens.evaluate((el: HTMLButtonElement) => el.disabled)).toBe(false)
    }

    /*
     * The hidden table, which is the only copy of this data a screen reader
     * gets. `textContent` rather than `innerText`: it is deliberately clipped to
     * a 1px box, and asserting on rendered text would be asserting on the
     * clipping.
     */
    const table = page.getByRole('table', { name: 'Business Industry Growth Trend' })
    const figcaption = page.locator('figure', { has: table }).locator('figcaption')

    await expect(figcaption).toContainText('Largest')

    await growing.click()
    await expect(growing).toHaveAttribute('aria-pressed', 'true')
    await expect(largest).toHaveAttribute('aria-pressed', 'false')
    // The figure's accessible name moved with the toggle, so a screen reader is
    // not still being told it is looking at the largest six.
    await expect(figcaption).toContainText('Fastest growing')

    /*
     * The floor, stated. "Industries with at least N businesses" has to be a
     * number a reader can check, not a rule applied quietly — "why isn't my
     * trade on here" is the question this panel gets asked, and an exclusion
     * nobody can see is indistinguishable from a bug.
     *
     * Located on the live region rather than by text, because the sentence
     * deliberately appears twice — once for the eye and once inside the
     * figure's accessible name for a screen reader, which is the pairing this
     * whole panel is built on. Matching on text alone finds both.
     */
    await expect(
      page.locator('p[aria-live="polite"]', {
        hasText: /at least \d+ businesses on record are ranked/,
      }),
    ).toBeVisible()

    // Every line drawn under a change lens actually moved that way. Nothing
    // steady is padded in to make the count come out at six.
    for (const row of (await table.getByRole('row').allTextContents()).slice(1)) {
      expect(row).toContain('growing')
    }

    await declining.click()
    await expect(figcaption).toContainText('Fastest declining')
    for (const row of (await table.getByRole('row').allTextContents()).slice(1)) {
      expect(row).toContain('declining')
    }

    // Six is a ceiling, never exceeded: a seventh series would have to repeat a
    // colour and a dash pattern, and "Never Color Alone" only holds while those
    // pairs are unique. The header row is not one of the six.
    for (const lens of [largest, growing, declining]) {
      await lens.click()
      expect((await table.getByRole('row').count()) - 1).toBeLessThanOrEqual(6)
    }
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
