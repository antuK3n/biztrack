import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * RECORDS — the super admin's read-only console over the register.
 *
 * Stubbed, like admin-screens and track-search, for the same two reasons: a
 * narrowing assertion has to know exactly which rows exist, and the register
 * these may be pointed at holds real testers' filings. Stubbing also keeps this
 * file read-only, which is the whole claim the screen makes about itself.
 *
 * The load-bearing test here is the last one. Rows on this screen deliberately
 * open nothing — the super admin does not hold `application.review`, so
 * /staff/queue/:id would bounce it and /applications/:id belongs to the citizen
 * portal whose token it does not have. That is easy to "fix" by wiring up a
 * link, and this asserts it has not been.
 */

test.use({ storageState: sessionFor('admin') })

const APPLICATIONS = [
  {
    id: 501,
    tracking_id: 'BIZ-2026-00473',
    application_type: 'new',
    title: null,
    status: 'for_approval',
    status_label: 'For Approval',
    business: { id: 21, name: 'Aling Nena Sari-Sari Store' },
    applicant: { id: 31, name: 'Nena Makiling' },
    submitted_at: '2026-08-14T02:10:00.000000Z',
    deadline_at: null,
    permit_types: [],
    created_at: '2026-08-14T02:00:00.000000Z',
  },
  {
    id: 502,
    tracking_id: 'BIZ-2026-00488',
    application_type: 'renewal',
    title: null,
    status: 'approved',
    status_label: 'Approved',
    business: { id: 22, name: 'RxCare Pharmacy' },
    applicant: { id: 32, name: 'Juan Ramos' },
    submitted_at: '2026-08-21T05:30:00.000000Z',
    deadline_at: null,
    permit_types: [],
    created_at: '2026-08-21T05:00:00.000000Z',
  },
  {
    /*
     * A draft, and a filing whose business has been removed from the register.
     * Both are real states of the live data — Business soft-deletes and its
     * filings stay — and both are cells this screen has to render without
     * dereferencing a null or inventing a filing date.
     */
    id: 503,
    tracking_id: 'BIZ-2026-00490',
    application_type: 'new',
    title: null,
    status: 'draft',
    status_label: 'Draft',
    business: null,
    applicant: { id: 33, name: 'Ester Cruz' },
    submitted_at: null,
    deadline_at: null,
    permit_types: [],
    created_at: '2026-08-25T01:00:00.000000Z',
  },
]

const BUSINESSES = [
  {
    id: 21,
    name: 'Aling Nena Sari-Sari Store',
    owner: { id: 31, name: 'Nena Makiling' },
    status: 'active',
    status_label: 'Active',
    created_at: '2026-01-12T00:00:00.000000Z',
  },
  {
    id: 22,
    name: 'RxCare Pharmacy',
    owner: { id: 32, name: 'Juan Ramos' },
    status: 'suspended',
    status_label: 'Suspended',
    created_at: '2026-02-03T00:00:00.000000Z',
  },
]

const OWNERS = [
  {
    id: 31,
    first_name: 'Nena',
    middle_name: null,
    last_name: 'Makiling',
    suffix: null,
    gender: 'F',
    email: 'owner@biztrack.local',
    mobile_number: '09171234567',
    department: null,
    has_photo: false,
    is_active: true,
    email_verified_at: '2026-01-10T00:00:00.000000Z',
    roles: ['business_owner'],
    permissions: [],
  },
  {
    id: 32,
    first_name: 'Juan',
    middle_name: null,
    last_name: 'Ramos',
    suffix: null,
    gender: 'M',
    email: 'juan@biztrack.local',
    mobile_number: '09171234568',
    department: null,
    has_photo: false,
    is_active: false,
    // Never confirmed their address — the column exists to find these.
    email_verified_at: null,
    roles: ['business_owner'],
    permissions: [],
  },
]

const page1 = <T,>(data: T[]) => ({
  data,
  meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length },
})

/** Every request each tab made, so a narrowing can be pinned to the server. */
let asked: string[]

test.beforeEach(async ({ page }) => {
  asked = []

  await page.route('**/api/v1/applications?*', async (route) => {
    const url = new URL(route.request().url())
    asked.push(url.search)
    /*
     * The stub narrows the way ApplicationController does — tracking ID or
     * business name, and nothing else — or the search assertions would pass
     * whether the page sent `q` or sliced the rows it already held, which is
     * precisely the defect worth catching.
     */
    const q = (url.searchParams.get('q') ?? '').toLowerCase()
    const rows = q
      ? APPLICATIONS.filter((a) =>
          `${a.tracking_id} ${a.business?.name ?? ''}`.toLowerCase().includes(q),
        )
      : APPLICATIONS
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(page1(rows)),
    })
  })

  await page.route('**/api/v1/admin/businesses?*', async (route) => {
    const url = new URL(route.request().url())
    asked.push(url.search)
    const q = (url.searchParams.get('q') ?? '').toLowerCase()
    const rows = q
      ? BUSINESSES.filter((b) => `${b.name} ${b.owner?.name ?? ''}`.toLowerCase().includes(q))
      : BUSINESSES
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(page1(rows)),
    })
  })

  await page.route('**/api/v1/admin/users?*', async (route) => {
    const url = new URL(route.request().url())
    asked.push(url.search)
    const q = (url.searchParams.get('q') ?? '').toLowerCase()
    const rows = q
      ? OWNERS.filter((u) => `${u.first_name} ${u.last_name} ${u.email}`.toLowerCase().includes(q))
      : OWNERS
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(page1(rows)),
    })
  })

  await page.goto('/staff/admin/records')
  await expect(page.getByRole('heading', { name: 'Records', level: 1 })).toBeVisible()
})

test('the three registers are offered as tabs, and Applications opens', async ({ page }) => {
  for (const label of ['Applications', 'Businesses', 'Owners']) {
    await expect(page.getByRole('button', { name: label, exact: true })).toBeVisible()
  }

  // FilterPills marks the open tab with aria-pressed, so "which tab am I on" is
  // a fact a screen reader can get at rather than a colour.
  await expect(page.getByRole('button', { name: 'Applications', exact: true })).toHaveAttribute(
    'aria-pressed',
    'true',
  )
  await expect(page.locator('tbody tr')).toHaveCount(APPLICATIONS.length)
})

test('the Applications tab lists filings, and says what it cannot show', async ({ page }) => {
  const rows = page.locator('tbody tr')
  await expect(rows).toHaveCount(3)
  await expect(rows.first()).toContainText('BIZ-2026-00473')
  await expect(rows.first()).toContainText('Aling Nena Sari-Sari Store')
  // Never colour alone: the state is a word in the row, not just a tone.
  await expect(rows.first()).toContainText('For Approval')

  /*
   * The two null cases, rendered rather than crashed through. A filing whose
   * business was removed says so, and a draft that was never submitted gets the
   * dash a figure with no value gets — not a date borrowed from created_at.
   */
  const draft = rows.filter({ hasText: 'BIZ-2026-00490' })
  await expect(draft).toContainText('Business removed from register')
  await expect(draft).toContainText('—')

  await expect(page.getByText('Showing 3 of 3 filings')).toBeVisible()
})

test('searching narrows the list, and does it on the server', async ({ page }) => {
  const rows = page.locator('tbody tr')
  await expect(rows).toHaveCount(3)

  await page
    .getByRole('searchbox', { name: 'Search filings by tracking ID or business name' })
    .fill('rxcare')

  await expect(rows).toHaveCount(1)
  await expect(rows.first()).toContainText('BIZ-2026-00488')
  // The narrowing has to be a query the server answered. Filtering the rows
  // already loaded would find nothing past the first page.
  await expect.poll(() => asked.at(-1)).toContain('q=rxcare')
  await expect(page.getByText('Showing 1 of 1 filings matching your search')).toBeVisible()
})

test('a search that matches nothing says so, and offers the way out', async ({ page }) => {
  await page
    .getByRole('searchbox', { name: 'Search filings by tracking ID or business name' })
    .fill('nothing matches this')

  await expect(page.getByText('No filings match your search')).toBeVisible()
  await expect(page.locator('tbody tr')).toHaveCount(0)
})

test('each tab reads its own register, and Owners asks for owners by role', async ({ page }) => {
  await page.getByRole('button', { name: 'Businesses', exact: true }).click()
  await expect(page.locator('tbody tr')).toHaveCount(BUSINESSES.length)
  await expect(page.locator('tbody tr').filter({ hasText: 'RxCare Pharmacy' })).toContainText(
    'Suspended',
  )
  await expect.poll(() => asked.at(-1)).toContain('per_page=25')

  await page.getByRole('button', { name: 'Owners', exact: true }).click()
  await expect(page.locator('tbody tr')).toHaveCount(OWNERS.length)
  /*
   * `role=business_owner`, and not `staff=0`. The staff flag is truthy-only on
   * the server, so `staff=0` is no filter at all and this tab would list the
   * city's officers under a heading that says Owners.
   */
  await expect.poll(() => asked.at(-1)).toContain('role=business_owner')
  await expect(page.getByText('Showing 2 of 2 owners')).toBeVisible()
})

test('columns sort the page in hand, and the screen says that is all they do', async ({ page }) => {
  const first = page.locator('tbody tr').first()
  await expect(first).toContainText('BIZ-2026-00473')

  const trackingId = page.getByRole('button', { name: 'Tracking ID' })
  await trackingId.click()
  await expect(page.locator('th', { has: trackingId })).toHaveAttribute('aria-sort', 'ascending')

  await trackingId.click()
  await expect(page.locator('th', { has: trackingId })).toHaveAttribute('aria-sort', 'descending')
  await expect(page.locator('tbody tr').first()).toContainText('BIZ-2026-00490')

  /*
   * The honesty line. A browser sort reaches the rows in hand and no further,
   * and a reader looking at 25 of 1,668 would otherwise take the top row for the
   * register's first.
   */
  await expect(
    page.getByText('Sorted by Tracking ID within this page. The register itself is ordered newest first.'),
  ).toBeVisible()

  // A third press puts the register back in its own order, which is the only
  // order the totals are counted in.
  await trackingId.click()
  await expect(page.locator('th', { has: trackingId })).toHaveAttribute('aria-sort', 'none')
  await expect(page.locator('tbody tr').first()).toContainText('BIZ-2026-00473')
})

test('Refresh re-asks the server', async ({ page }) => {
  await expect(page.locator('tbody tr')).toHaveCount(3)
  const before = asked.length

  await page.getByRole('button', { name: 'Refresh' }).click()

  await expect.poll(() => asked.length).toBeGreaterThan(before)
  await expect(page.locator('tbody tr')).toHaveCount(3)
})

test('a row opens nothing, and nothing on it pretends otherwise', async ({ page }) => {
  /*
   * This is the assertion that stops the screen being "fixed" into a broken
   * one. There is no detail view the super admin may reach: /staff/queue/:id is
   * gated on `application.review`, which this account deliberately does not
   * hold, and /applications/:id is on the citizen portal, whose token is a
   * different key in localStorage. A row link would dead-end either way, so the
   * rows carry no link and no interactive dressing at all.
   */
  const row = page.locator('tbody tr').first()
  await expect(row.getByRole('link')).toHaveCount(0)
  await expect(row.getByRole('button')).toHaveCount(0)
  await expect(row).not.toHaveClass(/cursor-pointer/)

  // And the address does not move when one is clicked.
  await row.click()
  await expect(page).toHaveURL(/\/staff\/admin\/records$/)
})
