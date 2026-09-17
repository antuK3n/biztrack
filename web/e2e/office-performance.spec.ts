import { expect, test } from '@playwright/test'
import { sessionFor, waitForAnalytics } from './helpers'

/*
 * Office Performance — all six offices on one screen (issue #102).
 *
 * The client asked for "an analytics dashboard covering ALL offices", and the
 * two things that phrase can quietly fail to deliver are exactly what this file
 * checks on the rendered page:
 *
 *   1. ALL six offices are named. Not the four with enough data, not the five
 *      with honest clocks — all six, every time. Processing Time shipped in the
 *      four-office state and the client reported the missing three as missing,
 *      which is the correct reading of a screen that claims to cover a city.
 *
 *   2. The comparison is not silently comparing different things. BPLO's
 *      recorded time is the whole filing's lifetime rather than BPLO's own step
 *      (see App\Support\OfficePerformanceAnalytics), so its turnaround is
 *      excluded — and a reader has to be able to SEE that it was excluded and
 *      why, or the blank reads as zero or as a bug.
 *
 * Both are properties of what reaches the browser. The arithmetic is pinned
 * server-side in AnalyticsOfficePerformanceTest; what is pinned here is that the
 * figures which reconcile are the figures actually rendered, and that the
 * exclusion is legible rather than merely correct.
 *
 * IF THE FIGURES LOOK STALE: analytics are served from the row
 * `analytics:refresh` persists, not computed per request, so a change to this
 * payload does not reach any screen until a refresh has run against THIS
 * stack's database — `DB_DATABASE=database/e2e.sqlite php artisan
 * analytics:refresh`. That is the designed behaviour, and the ComputedAt line
 * this file asserts is what tells a reader about it.
 */

const SUPER_ADMIN_SESSION = sessionFor('admin')
const BPLO_SESSION = sessionFor('bplo')

const SCREEN = { path: '/staff/analytics/offices', title: 'Office Performance' } as const

/** The City's six offices, as `departments.code` spells them. */
const OFFICES = ['BPLO', 'CHO', 'BFP', 'OBO', 'CENRO', 'CPDO'] as const

/**
 * The office whose clock measures something other than its own step.
 *
 * Named as a constant rather than written into each assertion so that the day
 * WorkflowService::completeAssignment stops re-stamping, this file fails in one
 * obvious place instead of three scattered ones.
 */
const NOT_COMPARABLE = 'BPLO'

test.describe('Office Performance', () => {
  test.use({ storageState: SUPER_ADMIN_SESSION })

  test('names all six offices, whatever the register holds for them', async ({ page }) => {
    await page.goto(SCREEN.path)
    await waitForAnalytics(page, SCREEN.title)

    /*
     * Read off the row headers of the comparison table, which is the reading a
     * screen reader gets — an office missing from there is missing for
     * everyone, however many bars are drawn.
     *
     * Row ORDER is deliberately not asserted. It follows the register's own
     * department ordering, which is a display choice; pinning it would make a
     * reseed look like a regression.
     */
    const table = page.getByRole('table', { name: /finished reviews, open caseload/i })
    await expect(table).toBeVisible()

    const codes = await table.locator('tbody th').evaluateAll((cells) =>
      cells.map((cell) => (cell.querySelector('span')?.textContent ?? '').trim()),
    )

    for (const office of OFFICES) {
      expect(codes, `${office} is not on a screen that claims to cover every office`).toContain(
        office,
      )
    }
    expect(codes).toHaveLength(OFFICES.length)
  })

  test('keeps BPLO\'s volume, drops its turnaround, and prints the reason in the cell', async ({
    page,
  }) => {
    /*
     * The defect this screen is shaped around, checked where a reader meets it.
     *
     * BPLO acts on a filing twice and both acts write `completed_at` onto the
     * same assignment row, so the time recorded against BPLO is the whole
     * filing — the other five offices' reviews, the payment and the inspection
     * wait included. Three things therefore have to be true on the row at once,
     * and any one of them alone would be a defensible-looking mistake:
     *
     *   - the counts are still there (they are counts of rows and are unharmed)
     *   - no working-days figure is printed (printing one would be the lie)
     *   - the reason is in the cell (a blank here reads as zero, or as an
     *     oversight the reader should report)
     */
    await page.goto(SCREEN.path)
    await waitForAnalytics(page, SCREEN.title)

    const row = page.locator('tbody tr').filter({ has: page.getByText(NOT_COMPARABLE, { exact: true }) })
    await expect(row).toHaveCount(1)

    // Volume survives: the Handled cell carries a number.
    await expect(row.locator('td').first()).toHaveText(/\d/)

    // And the reason is on the row, in the reader's own words rather than a code.
    await expect(row).toContainText(/stamped again at final approval/i)
    await expect(row).toContainText(/whole filing/i)

    /*
     * The exclusion is stated rather than implied: the row must not carry a
     * "middle … slowest …" pair, which is the shape every comparable row's
     * turnaround cell takes. Asserting the absence of that phrase is more
     * specific than asserting the absence of a digit — the row legitimately
     * holds digits in Handled and Open now.
     */
    await expect(row.getByText(/middle/i)).toHaveCount(0)
  })

  test('gives every other office a turnaround in working days', async ({ page }) => {
    /*
     * The other half of the exclusion. One office being blank is the design;
     * two would mean the exclusion had spread, and five would mean the screen
     * had quietly stopped comparing anything at all.
     */
    await page.goto(SCREEN.path)
    await waitForAnalytics(page, SCREEN.title)

    const comparable = OFFICES.filter((office) => office !== NOT_COMPARABLE)

    for (const office of comparable) {
      const row = page
        .locator('tbody tr')
        .filter({ has: page.getByText(office, { exact: true }) })
        .first()
      await expect(row, `${office} lost its turnaround figure`).toContainText(/middle/i)
    }
  })

  test('states the three RA 11032 tiers with the allowance each one carries', async ({ page }) => {
    /*
     * The tiers are statute (App\Support\Ra11032) and the screen may not appear
     * to disagree with it: three tiers, 3 / 7 / 20 working days. A tier that
     * disappears because the window holds none of it turns "no highly technical
     * filings this year" into "this City has two tiers".
     *
     * The day counts are asserted as they are PRINTED, because the chatbot once
     * quoted "10 working days" — a tier that does not exist — from a hard-coded
     * number, and a screen is just as capable of it.
     */
    await page.goto(SCREEN.path)
    await waitForAnalytics(page, SCREEN.title)

    const tiers = page.getByRole('heading', { name: 'RA 11032 tiers', level: 2 })
    await expect(tiers).toBeVisible()

    for (const [label, days] of [
      ['Simple', 3],
      ['Complex', 7],
      ['Highly technical', 20],
    ] as const) {
      const row = page.locator('li').filter({ hasText: label }).first()
      await expect(row, `the ${label} tier is missing from the screen`).toBeVisible()
      await expect(row).toContainText(`${days} working days`)
    }
  })

  test('says how much test data is inside its own averages', async ({ page }) => {
    /*
     * `businesses` has no provenance column, so the test suite's own fixtures
     * are inside every average on this page and cannot be subtracted. Saying so
     * is the whole of what can be done about it — and the matching rule is
     * printed too, because it is a guess from the name and a reader is entitled
     * to judge how much it is likely to have missed.
     *
     * The count is not asserted: it moves every time the suite runs, which is
     * the very property being declared.
     */
    await page.goto(SCREEN.path)
    await waitForAnalytics(page, SCREEN.title)

    const note = page.getByText(/These averages include test data/i)
    await expect(note).toBeVisible()
    await expect(note.locator('..')).toContainText(/created by the automated test suite/i)
    await expect(note.locator('..')).toContainText(/identified by name/i)
  })

  test('dates its figures on the face of the screen', async ({ page }) => {
    /*
     * Every analytics screen in this product carries provenance, and a new one
     * is not exempt. These are batch figures — as fresh as the last
     * `analytics:refresh` and no fresher — so a tester who files something and
     * does not see it here has found the designed behaviour. Without this line
     * that reads as a bug; see the argument in ComputedAt.tsx.
     */
    await page.goto(SCREEN.path)
    await waitForAnalytics(page, SCREEN.title)

    /*
     * Asserted on the timestamp rather than on the word "Computed", because
     * ComputedAt has two states and they word themselves differently: a stored
     * refresh reads "Computed 20 minutes ago by BizTrack", while a view the
     * batch job has not written yet gets a panel reading "These figures have
     * not been recomputed yet". Both are correct, which one appears depends on
     * whether `analytics:refresh` has run against this stack's copy of the
     * register, and pinning the wording would make this test a check on the
     * state of a database rather than on the screen.
     *
     * What must be true in BOTH is that the figures carry a machine-readable
     * date inside a status region. That is the promise; the sentence around it
     * is the state.
     */
    const provenance = page.getByRole('status').filter({ has: page.locator('time[datetime]') })
    await expect(provenance.first()).toBeVisible()
  })
})

test.describe('who may open Office Performance', () => {
  /*
   * The screen ranks the departments against each other, BPLO among them, so it
   * belongs to the office doing the oversight rather than to one of the offices
   * being overseen. `analytics.view` would have been the obvious door and is the
   * wrong one: it would put BPLO in front of a league table it appears in.
   *
   * The permission is written out in three places that nothing derives from one
   * another — the route in App.tsx, the rail entry in lib/nav.ts and the tab in
   * AnalyticsTabs — so a route regated without the rail fails nowhere except
   * here.
   */
  test.describe('as BPLO', () => {
    test.use({ storageState: BPLO_SESSION })

    test('the screen is out of reach and the rail does not offer it', async ({ page }) => {
      await page.goto(SCREEN.path)
      await expect(page, 'BPLO was let into the screen that ranks BPLO').toHaveURL(
        /\/staff\/dashboard$/,
        { timeout: 30_000 },
      )
      await expect(page.getByRole('heading', { name: SCREEN.title, level: 1 })).toHaveCount(0)

      const analytics = page.locator('aside').getByRole('link', { name: 'Analytics', exact: true })
      await expect(analytics).toHaveAttribute('href', '/staff/analytics')
    })
  })

  test.describe('as the super admin', () => {
    test.use({ storageState: SUPER_ADMIN_SESSION })

    test('the rail lands on it and the tab strip reaches Processing Time', async ({ page }) => {
      await page.goto('/staff/dashboard')

      const analytics = page.locator('aside').getByRole('link', { name: 'Analytics', exact: true })
      await expect(analytics).toHaveAttribute('href', SCREEN.path)
      await analytics.click()
      await waitForAnalytics(page, SCREEN.title)

      /*
       * The second screen is one tab away, which is the arrangement that
       * justifies the rail pointing here rather than there: this answers "which
       * office", Processing Time answers "what has that office been doing".
       */
      const strip = page.getByRole('navigation', { name: 'Analytics sections' })
      await strip.getByRole('link', { name: 'Processing Time' }).click()
      await waitForAnalytics(page, 'Permit Processing Time Monitoring')
    })
  })
})
