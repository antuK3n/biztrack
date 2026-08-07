import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * Search, sort and filter on the two Track pages (checklist items 88 and 90),
 * and the rejection reason on the applicant's row (item 80).
 *
 * Item 90 is the reason this file is worth its weight: `<SortFilter />` was
 * rendered with no props on both pages, which is the component's documented
 * "static ornament" mode. It looked interactive, it had a chevron, and it did
 * nothing when clicked — a control that lies is worse than one that is absent,
 * and neither `tsc` nor the API suite can see the difference. Only a browser
 * can tell whether clicking "Oldest first" reordered anything.
 *
 * The payloads are stubbed, not seeded. Ordering assertions need known dates,
 * and a search assertion needs to know exactly which rows exist; against real
 * data both would pass today and rot the first time a tester files something.
 * Stubbing also keeps this suite read-only, which matters because the stack it
 * may be pointed at holds real testers' filings.
 */

const OWNER_APPS = [
  {
    id: 90101,
    tracking_id: 'BIZ-2026-00101',
    application_type: 'new',
    title: null,
    status: 'under_review',
    status_label: 'For Approval',
    business: { id: 1, name: 'Aling Nena Sari-Sari Store' },
    submitted_at: '2026-06-01T00:00:00.000000Z',
    deadline_at: '2026-08-20T00:00:00.000000Z',
    permit_types: [{ code: 'BUSINESS', name: 'Business Permit' }],
    created_at: '2026-06-01T00:00:00.000000Z',
  },
  {
    id: 90102,
    tracking_id: 'BIZ-2026-00102',
    application_type: 'renewal',
    // The applicant's own name for the filing — the third thing search covers.
    title: 'Second branch renewal',
    status: 'pending_payment',
    status_label: 'Pending Payment',
    business: { id: 2, name: 'Bayanihan Hardware' },
    submitted_at: '2026-07-01T00:00:00.000000Z',
    deadline_at: '2026-08-05T00:00:00.000000Z',
    permit_types: [{ code: 'BUSINESS', name: 'Business Permit' }],
    created_at: '2026-07-01T00:00:00.000000Z',
  },
  {
    id: 90103,
    tracking_id: 'BIZ-2026-00103',
    application_type: 'new',
    title: null,
    status: 'rejected',
    status_label: 'Rejected',
    business: { id: 3, name: 'Cielo Bakeshop' },
    submitted_at: '2026-05-01T00:00:00.000000Z',
    deadline_at: null,
    permit_types: [{ code: 'BUSINESS', name: 'Business Permit' }],
    created_at: '2026-05-01T00:00:00.000000Z',
  },
  {
    id: 90104,
    tracking_id: 'BIZ-2026-00104',
    application_type: 'new',
    title: null,
    status: 'for_inspection',
    status_label: 'For Inspection',
    business: { id: 4, name: 'Dagupan Auto Supply' },
    submitted_at: '2026-07-20T00:00:00.000000Z',
    deadline_at: '2026-09-01T00:00:00.000000Z',
    permit_types: [{ code: 'BUSINESS', name: 'Business Permit' }],
    created_at: '2026-07-20T00:00:00.000000Z',
  },
]

const REJECTION_REASON =
  'The sketch plan does not match the pinned location, and the lessor’s consent is unsigned.'

/**
 * Rows in list order, read off the accordion buttons.
 *
 * Scoped to the row shape rather than to `[aria-expanded]` alone: the header
 * menu and the Sort/Filter buttons carry that attribute too, and a bare
 * attribute selector quietly counted them as applications.
 */
async function trackRowNames(page: import('@playwright/test').Page): Promise<string[]> {
  return page.locator('li > div > button[aria-expanded]').allInnerTexts()
}

test.describe('applicant Track page', () => {
  test.use({ storageState: sessionFor('owner') })

  test.beforeEach(async ({ page }) => {
    await page.route('**/api/v1/applications*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: OWNER_APPS,
          meta: { current_page: 1, last_page: 1, per_page: 200, total: OWNER_APPS.length },
        }),
      })
    })

    // The reason lives on the detail payload, not the list one; the page
    // fetches it for rejected rows so the row can explain itself.
    await page.route('**/api/v1/applications/*', async (route) => {
      const app = OWNER_APPS.find((a) => route.request().url().endsWith(String(a.id)))
      if (!app) return route.continue()
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            ...app,
            applicant: { id: 9, name: 'Owner' },
            documents: [],
            fee_assessment: null,
            payments: [],
            assignments: [],
            inspections: [],
            permits: [],
            rejection_reason: app.status === 'rejected' ? REJECTION_REASON : null,
          },
        }),
      })
    })

    await page.goto('/applications')
    await expect(page.getByRole('heading', { name: 'Permit Tracking', level: 1 })).toBeVisible()
  })

  test('searches by tracking ID, business name and the applicant’s own title', async ({ page }) => {
    const search = page.getByRole('searchbox', {
      name: 'Search your applications by tracking ID, business name, or title',
    })

    // Nobody retypes a whole tracking ID, so a fragment has to work.
    await search.fill('00103')
    await expect(await trackRowNames(page)).toEqual(['Cielo Bakeshop'])

    await search.fill('bayanihan')
    await expect(await trackRowNames(page)).toEqual(['Bayanihan Hardware'])

    // The filing's own title, which is the only one of the three that is not
    // shown on the row — searching for something invisible still has to work.
    await search.fill('second branch')
    await expect(await trackRowNames(page)).toEqual(['Bayanihan Hardware'])
  })

  test('announces the result count to a screen reader', async ({ page }) => {
    const status = page.getByRole('status').filter({ hasText: 'shown' })
    await expect(status).toHaveText(/4 applications shown\.$/)

    await page
      .getByRole('searchbox', { name: /Search your applications/ })
      .fill('Cielo')
    await expect(status).toHaveText(/1 application shown for the current search and filters\./)
  })

  test('an empty search result says so and offers a way back', async ({ page }) => {
    const search = page.getByRole('searchbox', { name: /Search your applications/ })
    await search.fill('no such business')

    await expect(page.getByText('Nothing matches “no such business”')).toBeVisible()
    await page.getByRole('button', { name: 'Clear search and filters' }).click()

    await expect(search).toHaveValue('')
    expect(await trackRowNames(page)).toHaveLength(4)
  })

  test('Sort reorders the list and Filter narrows it by status', async ({ page }) => {
    // Default is newest first: Dagupan (Jul 20) … Cielo (May 1).
    expect(await trackRowNames(page)).toEqual([
      'Dagupan Auto Supply',
      'Bayanihan Hardware',
      'Aling Nena Sari-Sari Store',
      'Cielo Bakeshop',
    ])

    await page.getByRole('button', { name: /^Sort/ }).click()
    await page.getByRole('option', { name: 'Oldest first' }).click()
    expect(await trackRowNames(page)).toEqual([
      'Cielo Bakeshop',
      'Aling Nena Sari-Sari Store',
      'Bayanihan Hardware',
      'Dagupan Auto Supply',
    ])

    // Deadline order is not date order: Cielo has none and must sort last
    // rather than heading the list as an epoch-zero timestamp.
    await page.getByRole('button', { name: /^Sort/ }).click()
    await page.getByRole('option', { name: 'Deadline (soonest)' }).click()
    expect(await trackRowNames(page)).toEqual([
      'Bayanihan Hardware',
      'Aling Nena Sari-Sari Store',
      'Dagupan Auto Supply',
      'Cielo Bakeshop',
    ])

    await page.getByRole('button', { name: /^Filter/ }).click()
    await page.getByRole('option', { name: 'Rejected' }).click()
    expect(await trackRowNames(page)).toEqual(['Cielo Bakeshop'])
  })

  test('a rejected filing shows why, without expanding anything', async ({ page }) => {
    // The whole point of item 80: the verdict and the grounds arrive together.
    await expect(page.getByText(REJECTION_REASON)).toBeVisible()
  })
})

/* ── Officer queue ────────────────────────────────────────────────────────── */

const ASSIGNMENTS = [
  {
    id: 70001,
    status: 'pending',
    status_label: 'Pending',
    remarks: null,
    department: { code: 'BPLO', name: 'Business Permits and Licensing Office' },
    officer: null,
    assigned_at: '2026-07-28T09:00:00.000000Z',
    completed_at: null,
    application: {
      id: 90201,
      tracking_id: 'BIZ-2026-00201',
      business: { name: 'Zamora Printing Press' },
      application_type: 'new',
      /*
       * `returned`, not `submitted`. A pre-payment filing cannot reach this feed
       * at all — it has no assignment row until payment routes it — so a stub
       * that put one here was describing something the API cannot produce, and
       * it stopped matching the queue the moment those statuses moved to their
       * own tab. `returned` is the approval tab's other real status, so the
       * Filter test below still has two things to partition.
       */
      status: 'returned',
    },
  },
  {
    id: 70002,
    status: 'pending',
    status_label: 'Pending',
    remarks: null,
    department: { code: 'BPLO', name: 'Business Permits and Licensing Office' },
    officer: null,
    assigned_at: '2026-07-20T09:00:00.000000Z',
    completed_at: null,
    application: {
      id: 90202,
      tracking_id: 'BIZ-2026-00202',
      business: { name: 'Malasiqui Feeds Trading' },
      application_type: 'renewal',
      status: 'under_review',
    },
  },
  {
    id: 70003,
    status: 'pending',
    status_label: 'Pending',
    remarks: null,
    department: { code: 'BPLO', name: 'Business Permits and Licensing Office' },
    officer: null,
    assigned_at: '2026-06-30T09:00:00.000000Z',
    completed_at: null,
    application: {
      id: 90203,
      tracking_id: 'BIZ-2026-00203',
      // Soft-deleted business: the row is keyed by tracking ID, and so is its
      // place in an A–Z sort.
      business: null,
      application_type: 'new',
      status: 'under_review',
    },
  },
]

/*
 * The Pending Payment tab's rows, which come off a different endpoint.
 *
 * They have to: an unpaid filing has no assignment row at all. Routing is
 * WorkflowService::routeToDepartments and its only caller is onPaymentCompleted,
 * so nothing exists on /assignments until the fees are settled — which is why
 * every one of these is an application, not an assignment, and why none of them
 * has a `/staff/queue/:id` to link to.
 */
const UNPAID = [
  {
    id: 93001,
    tracking_id: 'BIZ-2026-00301',
    application_type: 'new',
    title: null,
    status: 'pending_payment',
    status_label: 'Pending Payment',
    business: { id: 11, name: 'Roberto’s Laundry Shop' },
    applicant: { id: 51, name: 'Roberto Dela Cruz' },
    submitted_at: '2026-08-01T02:00:00.000000Z',
    deadline_at: '2026-08-15T00:00:00.000000Z',
    permit_types: [{ code: 'BUSINESS', name: 'Business Permit' }],
    created_at: '2026-08-01T02:00:00.000000Z',
  },
  {
    id: 93002,
    tracking_id: 'BIZ-2026-00302',
    application_type: 'renewal',
    title: null,
    status: 'pending_payment',
    status_label: 'Pending Payment',
    business: { id: 12, name: 'Kalayaan Water Refilling' },
    applicant: { id: 52, name: 'Imelda Santos' },
    submitted_at: '2026-07-11T02:00:00.000000Z',
    deadline_at: '2026-08-25T00:00:00.000000Z',
    permit_types: [{ code: 'BUSINESS', name: 'Business Permit' }],
    created_at: '2026-07-11T02:00:00.000000Z',
  },
]

test.describe('officer queue', () => {
  test.use({ storageState: sessionFor('bplo') })

  /** Every /assignments URL the page asked for, in order. */
  let requested: string[]
  /** Every /applications URL the page asked for, decoded, in order. */
  let applicationQueries: string[]

  test.beforeEach(async ({ page }) => {
    requested = []
    applicationQueries = []

    /*
     * The Pending Payment feed. `q` is honoured here on purpose: the point of
     * the tests below is that the term reaches the API and comes back as both
     * the rows AND the total, which is the difference between "Showing 1 of 1"
     * and the "Showing 0 of the 13 loaded" the client was shown while searching
     * a business the register plainly held.
     */
    await page.route('**/api/v1/applications*', async (route) => {
      const url = new URL(route.request().url())
      applicationQueries.push(decodeURIComponent(url.search))
      const q = (url.searchParams.get('q') ?? '').toLowerCase()
      const rows = q
        ? UNPAID.filter((a) => `${a.tracking_id} ${a.business.name}`.toLowerCase().includes(q))
        : UNPAID
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: rows,
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: Number(url.searchParams.get('per_page') ?? 25),
            total: rows.length,
          },
        }),
      })
    })

    await page.route('**/api/v1/assignments*', async (route) => {
      const url = new URL(route.request().url())
      requested.push(url.search)
      const wanted = (url.searchParams.get('application_status') ?? '').split(',').filter(Boolean)
      let rows = wanted.length
        ? ASSIGNMENTS.filter((a) => wanted.includes(a.application.status))
        : ASSIGNMENTS

      /*
       * The stub has to search, because the endpoint it stands in for does.
       *
       * It honoured `application_status` and ignored `q`, which was fine while
       * the queue filtered in the browser — the term never reached the network.
       * Now that it does, a stub that returns the full set regardless would let
       * a completely broken search pass this suite: the rows would be there,
       * the assertions would find them, and nothing would have been narrowed.
       *
       * Same two columns as AssignmentController::index — the tracking ID and
       * the business name — so the fake and the real one disagree about as
       * little as possible.
       */
      const q = (url.searchParams.get('q') ?? '').toLowerCase()
      if (q) {
        rows = rows.filter(
          (a) =>
            a.application.tracking_id.toLowerCase().includes(q) ||
            (a.application.business?.name ?? '').toLowerCase().includes(q),
        )
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: rows,
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: Number(url.searchParams.get('per_page') ?? 25),
            total: rows.length,
            application_status_counts: { returned: 1, under_review: 2 },
          },
        }),
      })
    })

    await page.goto('/staff/queue')
    await expect(
      page.getByRole('heading', { name: 'Application Verification', level: 1 }),
    ).toBeVisible()
  })

  test('search narrows the queue on the server, over the whole queue', async ({ page }) => {
    /*
     * This test used to assert the opposite, and passed for as long as the
     * search was broken.
     *
     * The queue filtered the rows it already held and said so honestly —
     * "Showing 1 of the 3 loaded". Honest and wrong twice over: a filing past
     * the first page could not be found at all, and the search only ever looked
     * inside the OPEN tab, so an officer on For Approval searching a business
     * whose filing had moved to For Inspection was told "Nothing matches". It
     * matched; it was one tab away. The client hit exactly that, searching
     * "roberto" against a filing sitting in the other tab.
     *
     * `/assignments` now takes `q`, so the narrowing is a query rather than a
     * slice of the page — and the wording drops "loaded", because the count is
     * no longer hedged against what happened to be in the browser.
     */
    const status = page.getByRole('status').filter({ hasText: 'Showing' })
    await expect(status).toHaveText('Showing 3 of 3, newest first.')

    await page
      .getByRole('searchbox', { name: 'Search this queue by tracking ID or business name' })
      .fill('malasiqui')

    await expect(page.locator('a[href^="/staff/queue/"]')).toHaveCount(1)
    await expect(page.locator('a[href^="/staff/queue/"]')).toContainText('Malasiqui Feeds Trading')

    // The proof it is a query and not a browser-side slice: the term went to
    // the server. Same standard the Filter test below holds itself to.
    await expect.poll(() => requested.at(-1)).toContain('q=malasiqui')
    await expect(status).not.toContainText('loaded')
  })

  test('Sort reorders the loaded rows', async ({ page }) => {
    const rows = page.locator('a[href^="/staff/queue/"]')
    await expect(rows).toHaveCount(3)

    await page.getByRole('button', { name: /^Sort/ }).click()
    await page.getByRole('option', { name: 'Waiting longest' }).click()
    // Oldest assignment first: Jun 30 (business removed) … Jul 28.
    await expect(rows.first()).toContainText('BIZ-2026-00203')

    await page.getByRole('button', { name: /^Sort/ }).click()
    await page.getByRole('option', { name: 'Business name (A–Z)' }).click()
    await expect(rows.first()).toContainText('BIZ-2026-00203')
    await expect(rows.last()).toContainText('Zamora Printing Press')
  })

  test('Filter narrows the queue server-side, not in the browser', async ({ page }) => {
    await page.getByRole('button', { name: /^Filter/ }).click()
    /*
     * `exact` because the tab and one of its statuses are both called "For
     * Approval" now, so the listbox holds "All in For Approval" as well as the
     * status itself. That is the intended wording — the tab is what is with the
     * offices awaiting a decision (under review, or sent back), the status is
     * the first of those two — and it is only ambiguous to a substring match.
     */
    await page.getByRole('option', { name: 'For Approval', exact: true }).click()

    // The proof that this is a query change and not a browser-side slice: the
    // page asked the server for the narrowed set. Filtering a page in the
    // browser is the bug this queue already had once.
    await expect
      .poll(() => requested.at(-1))
      .toContain('application_status=under_review')
    await expect(page.locator('a[href^="/staff/queue/"]')).toHaveCount(2)
  })

  test('an empty search result says so and offers a way back', async ({ page }) => {
    const search = page.getByRole('searchbox', { name: /Search this queue/ })
    await search.fill('no such filing')

    await expect(page.getByText('Nothing matches “no such filing”')).toBeVisible()
    await page.getByRole('button', { name: 'Clear search' }).click()

    await expect(search).toHaveValue('')
    await expect(page.locator('a[href^="/staff/queue/"]')).toHaveCount(3)
  })

  /* ── Pending Payment ──────────────────────────────────────────────────── */

  test('Pending Payment shows the filings the assignment feed cannot hold', async ({ page }) => {
    await page.getByRole('button', { name: 'Pending Payment' }).click()

    /*
     * A different endpoint, and the whole pre-payment stage asked for in ONE
     * request. Both halves matter: the assignment feed can never answer for this
     * stage, and a stage split across two requests would have to be totalled in
     * the browser — which is the "count one page and call it the queue" failure
     * the other two tabs were built to avoid.
     */
    await expect
      .poll(() => applicationQueries.at(-1))
      .toContain('status=submitted,pending_payment')

    await expect(page.getByRole('status').filter({ hasText: 'Showing' })).toHaveText(
      'Showing 2 of 2, newest first.',
    )
    await expect(page.getByText('Roberto’s Laundry Shop')).toBeVisible()

    /*
     * Nothing on this tab is an officer's to open, and the rows say so rather
     * than looking broken: there is no assignment, so there is no review sheet,
     * so there is no `/staff/queue/:id` to link to.
     */
    await expect(page.locator('a[href^="/staff/queue/"]')).toHaveCount(0)
    await expect(page.getByText(/Waiting on the applicant’s payment/).first()).toBeVisible()
  })

  test('Pending Payment searches on the server, not over the loaded rows', async ({ page }) => {
    await page.getByRole('button', { name: 'Pending Payment' }).click()
    await expect(page.getByText('Roberto’s Laundry Shop')).toBeVisible()

    await page.getByRole('searchbox', { name: /Search this queue/ }).fill('roberto')

    // The term reached the API …
    await expect.poll(() => applicationQueries.at(-1)).toContain('q=roberto')
    // … so the count is the queue's and not the page's. "1 of 1", with no
    // "of the N loaded" hedge, is the assertion that would fail the moment this
    // tab started filtering rows it had already fetched.
    await expect(page.getByRole('status').filter({ hasText: 'Showing' })).toHaveText(
      'Showing 1 of 1 matching “roberto”, newest first.',
    )
    await expect(page.getByText('Kalayaan Water Refilling')).toBeHidden()
  })

  test('Pending Payment offers only its own statuses in the Filter', async ({ page }) => {
    await page.getByRole('button', { name: 'Pending Payment' }).click()
    await page.getByRole('button', { name: /^Filter/ }).click()

    // A tab never offers a status it excludes — and, since the pre-payment
    // statuses moved here, For Approval no longer offers two that could only
    // ever return nothing.
    await expect(page.getByRole('option', { name: 'All in Pending Payment' })).toBeVisible()
    await expect(page.getByRole('option', { name: 'Pending Payment', exact: true })).toBeVisible()
    await expect(page.getByRole('option', { name: 'For Approval', exact: true })).toBeHidden()
  })
})
