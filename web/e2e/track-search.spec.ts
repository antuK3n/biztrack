import { expect, test } from '@playwright/test'

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
    status_label: 'Under review',
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
    status_label: 'For payment',
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
    status_label: 'For inspection',
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
  test.use({ storageState: 'e2e/.auth/owner.json' })

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
      status: 'submitted',
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

test.describe('officer queue', () => {
  test.use({ storageState: 'e2e/.auth/bplo.json' })

  /** Every /assignments URL the page asked for, in order. */
  let requested: string[]

  test.beforeEach(async ({ page }) => {
    requested = []
    await page.route('**/api/v1/assignments*', async (route) => {
      const url = new URL(route.request().url())
      requested.push(url.search)
      const wanted = (url.searchParams.get('application_status') ?? '').split(',').filter(Boolean)
      const rows = wanted.length
        ? ASSIGNMENTS.filter((a) => wanted.includes(a.application.status))
        : ASSIGNMENTS
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
            application_status_counts: { submitted: 1, under_review: 2 },
          },
        }),
      })
    })

    await page.goto('/queue')
    await expect(
      page.getByRole('heading', { name: 'Application Verification', level: 1 }),
    ).toBeVisible()
  })

  test('search narrows the queue and reports how much of it was searched', async ({ page }) => {
    const status = page.getByRole('status').filter({ hasText: 'Showing' })
    await expect(status).toHaveText('Showing 3 of 3, newest first.')

    await page
      .getByRole('searchbox', { name: 'Search this queue by tracking ID or business name' })
      .fill('malasiqui')

    await expect(page.locator('a[href^="/queue/"]')).toHaveCount(1)
    await expect(page.locator('a[href^="/queue/"]')).toContainText('Malasiqui Feeds Trading')
    // The count is stated against what was loaded, not implied over the queue.
    await expect(status).toHaveText('Showing 1 of the 3 loaded, newest first.')

    // Searching asks for the API's ceiling rather than a screenful, so a
    // search usually covers the whole queue in one request.
    expect(requested.at(-1)).toContain('per_page=200')
  })

  test('Sort reorders the loaded rows', async ({ page }) => {
    const rows = page.locator('a[href^="/queue/"]')
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
    await page.getByRole('option', { name: 'Under review' }).click()

    // The proof that this is a query change and not a browser-side slice: the
    // page asked the server for the narrowed set. Filtering a page in the
    // browser is the bug this queue already had once.
    await expect
      .poll(() => requested.at(-1))
      .toContain('application_status=under_review')
    await expect(page.locator('a[href^="/queue/"]')).toHaveCount(2)
  })

  test('an empty search result says so and offers a way back', async ({ page }) => {
    const search = page.getByRole('searchbox', { name: /Search this queue/ })
    await search.fill('no such filing')

    await expect(page.getByText('Nothing matches “no such filing”')).toBeVisible()
    await page.getByRole('button', { name: 'Clear search' }).click()

    await expect(search).toHaveValue('')
    await expect(page.locator('a[href^="/queue/"]')).toHaveCount(3)
  })
})
