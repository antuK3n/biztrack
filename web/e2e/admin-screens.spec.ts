import { expect, test, type Page } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * MANAGE OFFICER-IN-CHARGE and MANAGE BUSINESS OWNER STATUS — the two screens
 * the super admin does account work on, which had no browser cover at all.
 *
 * The API side is thorough (OfficerAssignmentTest, BusinessOwnerStatusTest,
 * MultipleOfficeAccountsTest) and says nothing about whether any of it reaches
 * a reader: whether the filters narrow, whether a dialog opens, whether the
 * roster shows what the register holds. Those are browser facts.
 *
 * Stubbed, like track-search and requests: a narrowing assertion has to know
 * exactly which rows exist, and the register these may be pointed at holds real
 * testers' accounts. Stubbing also keeps this file read-only — it never creates
 * or deactivates an account.
 */

test.use({ storageState: sessionFor('admin') })

const DEPARTMENTS = [
  { id: 1, code: 'BPLO', name: 'Business Permits and Licensing Office' },
  { id: 2, code: 'CHO', name: 'City Health Office' },
]

const ROLES = [
  {
    name: 'admin',
    label: 'Administrator',
    description: 'Oversees the register',
    wants_department: false,
    // The seat is taken — there is exactly one super admin.
    available: false,
  },
  {
    name: 'bplo_staff',
    label: 'BPLO Staff',
    description: 'Reviews business permits',
    wants_department: true,
    available: true,
  },
  {
    name: 'sanitary_officer',
    label: 'Sanitary Officer',
    description: 'Reviews sanitary clearances',
    wants_department: true,
    available: true,
  },
]

function officer(over: {
  id: number
  first: string
  last: string
  email: string
  dept: (typeof DEPARTMENTS)[number]
  role: string
  active?: boolean
}) {
  return {
    id: over.id,
    first_name: over.first,
    middle_name: null,
    last_name: over.last,
    suffix: null,
    gender: 'F',
    email: over.email,
    mobile_number: '09171234567',
    department: over.dept,
    is_active: over.active ?? true,
    has_photo: false,
    email_verified_at: '2026-01-01T00:00:00.000000Z',
    roles: [over.role],
    permissions: [],
    last_active_at: null,
  }
}

const OFFICERS = [
  officer({ id: 11, first: 'Liza', last: 'Reyes', email: 'bplo@biztrack.local', dept: DEPARTMENTS[0], role: 'bplo_staff' }),
  // The client's rule: an office may hold MORE THAN ONE account.
  officer({ id: 12, first: 'Marites', last: 'Santos', email: 'bplo.two@biztrack.local', dept: DEPARTMENTS[0], role: 'bplo_staff' }),
  officer({ id: 13, first: 'Carlos', last: 'Dizon', email: 'sanitary@biztrack.local', dept: DEPARTMENTS[1], role: 'sanitary_officer' }),
  officer({ id: 14, first: 'Rosa', last: 'Lim', email: 'retired@biztrack.local', dept: DEPARTMENTS[1], role: 'sanitary_officer', active: false }),
]

/*
 * The super admin, which belongs to NO office. Built by hand rather than
 * through `officer()` because that helper takes a department and this account's
 * whole point is not having one.
 */
const SUPER_ADMIN = {
  ...officer({ id: 15, first: 'Ramon', last: 'Santos', email: 'admin@biztrack.local', dept: DEPARTMENTS[0], role: 'admin' }),
  department: null,
}

/*
 * What an unfiltered /admin/users answers with: the offices AND the seat that
 * belongs to none of them. Counted from here so adding an account to the
 * fixture cannot leave a stale number behind in a test.
 */
const ROSTER = [...OFFICERS, SUPER_ADMIN]

const BUSINESSES = [
  {
    id: 21,
    name: 'Aling Nena Sari-Sari Store',
    tracking_id: 'BIZ-2026-00001',
    applications_count: 2,
    status: 'active',
    status_label: 'Active',
    owner: { id: 31, name: 'Nena Makiling', email: 'owner@biztrack.local' },
  },
  {
    id: 22,
    name: 'RxCare Pharmacy',
    tracking_id: 'BIZ-2026-00002',
    applications_count: 1,
    status: 'suspended',
    status_label: 'Suspended',
    owner: { id: 32, name: 'Juan Ramos', email: 'juan@biztrack.local' },
  },
]

const page1 = <T,>(data: T[]) => ({
  data,
  meta: { current_page: 1, last_page: 1, per_page: 10, total: data.length },
})

test.describe('Officer Assignment', () => {
  /** Every /admin/users query the page asked for. */
  let asked: string[]

  test.beforeEach(async ({ page }) => {
    asked = []

    await page.route('**/api/v1/admin/roles*', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: ROLES }) }),
    )
    await page.route('**/api/v1/reference/departments*', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: DEPARTMENTS }) }),
    )
    await page.route('**/api/v1/admin/users?*', async (route) => {
      const url = new URL(route.request().url())
      asked.push(url.search)

      /*
       * The stub narrows the way the server does, or the assertions would pass
       * whether the page sent the filter or sliced what it already held —
       * which is the defect being tested.
       */
      const q = (url.searchParams.get('q') ?? '').toLowerCase()
      const dept = url.searchParams.get('department_id')
      const role = url.searchParams.get('role')
      const active = url.searchParams.get('is_active')

      let rows = [...ROSTER]
      if (q) rows = rows.filter((u) => `${u.first_name} ${u.last_name} ${u.email}`.toLowerCase().includes(q))
      if (dept) rows = rows.filter((u) => String(u.department?.id) === dept)
      if (role) rows = rows.filter((u) => u.roles.includes(role))
      if (active === '1') rows = rows.filter((u) => u.is_active)
      if (active === '0') rows = rows.filter((u) => !u.is_active)

      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(page1(rows)) })
    })

    await page.goto('/staff/admin/users')
    await expect(page.getByRole('heading', { name: 'Officer Assignment', level: 1 })).toBeVisible()
    await expect(page.locator('tbody tr')).toHaveCount(ROSTER.length)
  })

  test('one office can hold several accounts, and the roster shows them', async ({ page }) => {
    // The client's LOGIN requirement in one assertion: BPLO has two.
    const bplo = page.locator('tbody tr', { hasText: 'BPLO' })
    await expect(bplo).toHaveCount(2)
    await expect(bplo.first()).toContainText('bplo@biztrack.local')
    await expect(bplo.last()).toContainText('bplo.two@biztrack.local')
  })

  test('search and the three filters each narrow, on the server', async ({ page }) => {
    const rows = page.locator('tbody tr')
    /*
     * By position, not by label. The filter captions are styled spans rather
     * than `<label for>`, and "Office" and "Role" are also column headings and
     * field labels inside the dialogs, so getByLabel resolves to three elements
     * and fails strict mode. The filter row is the only place three selects sit
     * together outside a dialog, in this order.
     */
    const [office, role, status] = [0, 1, 2].map((i) => page.locator('select').nth(i))

    await page.getByRole('searchbox', { name: 'Search officers' }).fill('carlos')
    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('Carlos Dizon')
    await expect.poll(() => asked.at(-1)).toContain('q=carlos')

    await page.getByRole('button', { name: 'Clear filters' }).click()
    await expect(rows).toHaveCount(ROSTER.length)

    await office.selectOption({ label: 'CHO — City Health Office' })
    await expect(rows).toHaveCount(2)
    await expect.poll(() => asked.at(-1)).toContain('department_id=2')

    await status.selectOption('inactive')
    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('Rosa Lim')
    await expect.poll(() => asked.at(-1)).toContain('is_active=0')

    await page.getByRole('button', { name: 'Clear filters' }).click()
    await role.selectOption('bplo_staff')
    await expect(rows).toHaveCount(2)
    await expect.poll(() => asked.at(-1)).toContain('role=bplo_staff')
  })

  test('an inactive account is named as inactive and offered reactivation', async ({ page }) => {
    // Never colour alone: the state is a word in the row, and the action offered
    // is the opposite of the state.
    const retired = page.locator('tbody tr', { hasText: 'Rosa Lim' })
    await expect(retired).toContainText('Inactive')
    await expect(retired.getByRole('button', { name: /Reactivate|Activate/ })).toBeVisible()
  })

  test('the super-admin seat is shown as taken, not silently missing', async ({ page }) => {
    /*
     * "The super admin's account is only ONE." A role that cannot be granted
     * has to SAY so — dropping it from the list would leave an admin looking
     * for an option that is simply absent, with nothing to explain why.
     */
    await page.getByRole('button', { name: 'Add officer' }).click()
    await expect(page.getByRole('heading', { name: /Add|Create/ })).toBeVisible()

    // The select inside the dialog that offers roles — named by what it holds
    // rather than by a caption three other controls also carry.
    const admin = page
      .locator('div.fixed.inset-0 select')
      .locator('option', { hasText: 'Administrator' })
    await expect(admin).toHaveCount(1)
    /*
     * Read off the element, not through toBeDisabled(): Playwright's
     * enabled/disabled actionability covers form CONTROLS, and an <option> is
     * not one — it reported "enabled" for `<option disabled value="admin">`.
     *
     * This is also the one place `disabled` is the right tool. An option is not
     * separately focusable, so disabling it takes nothing out of the tab order;
     * the select itself stays reachable and the text says why the seat is gone.
     */
    expect(await admin.evaluate((el) => (el as HTMLOptionElement).disabled)).toBe(true)
    // And it says WHY it cannot be chosen, rather than being quietly inert.
    await expect(admin).toHaveText(/already assigned/)
  })

  test('offers Reassign only to an account that belongs to an office', async ({ page }) => {
    /*
     * Reassign moves an officer's caseload to a colleague in their own office,
     * and hands them work from that office's queue. The super admin belongs to
     * no department, so both halves are empty by construction — nothing to
     * move, no queue to move it from — and the take endpoint answers 422 on
     * exactly that ground. A button that can only fail is worse than no button.
     */
    const officer = page.locator('tbody tr', { hasText: 'bplo@biztrack.local' })
    await expect(officer.getByRole('button', { name: 'Reassign' })).toBeVisible()

    const superAdmin = page.locator('tbody tr', { hasText: 'admin@biztrack.local' })
    await expect(superAdmin).toHaveCount(1)
    await expect(superAdmin.getByRole('button', { name: 'Reassign' })).toHaveCount(0)
    // The rest of the row is untouched: the account is still editable.
    await expect(superAdmin.getByRole('button', { name: 'Edit' })).toBeVisible()
  })

  /*
   * ── The Reassign dialog ──────────────────────────────────────────────────
   *
   * `caseload` is stubbed per test rather than in the beforeEach: the three
   * cases below are three different SERVER answers — a held caseload, an empty
   * list, and an office queue — and the screen is supposed to read differently
   * for each. A shared stub would test one of them three times.
   */
  const caseloadRoute = (page: Page, body: Record<string, unknown>) =>
    page.route('**/api/v1/admin/users/11/caseload*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            user: { id: 11, name: 'Liza Reyes' },
            department: { id: 1, code: 'BPLO', name: 'Business Permits and Licensing Office' },
            open_reviews: 0,
            open_inspections: 0,
            total: 0,
            candidates: [],
            ...body,
          },
        }),
      }),
    )

  const aCase = (over: Partial<Record<string, unknown>> = {}) => ({
    kind: 'review',
    id: 501,
    application_id: 301,
    tracking_id: 'BIZ-2026-00007',
    business: 'Aling Nena Sari-Sari Store',
    office: { code: 'BPLO', name: 'Business Permits and Licensing Office' },
    permit: 'Mayor’s Permit',
    status_label: 'For approval',
    at: '2026-09-01T02:00:00.000000Z',
    ...over,
  })

  const openReassign = async (page: Page) => {
    await page
      .locator('tbody tr', { hasText: 'bplo@biztrack.local' })
      .getByRole('button', { name: 'Reassign' })
      .click()
    await expect(page.getByRole('heading', { name: 'Reassign' })).toBeVisible()
  }

  test('an officer holding nothing is told so, with no scope to choose from', async ({ page }) => {
    /*
     * The client asked for exactly this: drop "Everything they are holding (0)"
     * and leave the sentence. An empty `cases` array is the server SAYING the
     * desk is clear, so offering a chooser over it invites a reader to pick a
     * category, type a reason and be refused.
     */
    await caseloadRoute(page, { cases: [], unassigned: [] })
    await openReassign(page)

    await expect(page.getByText('Liza Reyes is not holding any open work, so there is nothing to move.')).toBeVisible()
    await expect(page.getByText(/Everything they are holding/)).toHaveCount(0)
    await expect(page.getByText(/Scope — the permits/)).toHaveCount(0)
    // And the act it would perform cannot be started.
    await expect(page.getByRole('button', { name: /Release to office|Move caseload/ })).toHaveAttribute(
      'aria-disabled',
      'true',
    )
  })

  test('the scope is the permits the officer actually holds, named one by one', async ({ page }) => {
    await caseloadRoute(page, {
      open_reviews: 1,
      total: 1,
      cases: [aCase()],
      unassigned: [],
    })
    await openReassign(page)

    const scope = page.getByRole('checkbox', { name: /Aling Nena Sari-Sari Store/ })
    await expect(scope).toBeVisible()
    // Ticked to begin with: "this officer has gone, move their work" is the
    // common act and should not cost a click per case.
    await expect(scope).toBeChecked()
    await expect(page.getByText('BIZ-2026-00007').first()).toBeVisible()
  })

  test('the same dialog hands the office’s unheld work to the officer', async ({ page }) => {
    /*
     * The other direction. The client: "ang mga unassign applications pede
     * maiassign kung kanino mang officer ako magclick." Asserted through the
     * REQUEST, not the dialog closing — a screen that ticks without sending
     * the case is the defect this covers.
     */
    let sent: unknown = null
    await page.route('**/api/v1/admin/users/11/take-cases', async (route) => {
      sent = route.request().postDataJSON()
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { total: 1, to: { id: 11, name: 'Liza Reyes' } } }),
      })
    })
    await caseloadRoute(page, {
      cases: [],
      unassigned: [aCase({ id: 777, tracking_id: 'BIZ-2026-00009', business: 'RxCare Pharmacy' })],
    })
    await openReassign(page)

    await expect(page.getByText('Unassigned in BPLO')).toBeVisible()
    const free = page.getByRole('checkbox', { name: /RxCare Pharmacy/ })
    // Nothing ticked to begin with — taking work on is a decision about a
    // particular case, never a sweep.
    await expect(free).not.toBeChecked()

    const hand = page.getByRole('button', { name: /^Assign .*to Liza Reyes$/ })
    await expect(hand).toHaveAttribute('aria-disabled', 'true')

    await free.check()
    await expect(hand).not.toHaveAttribute('aria-disabled', 'true')
    await hand.click()

    await expect.poll(() => sent).not.toBeNull()
    expect(sent).toMatchObject({ cases: [{ kind: 'review', id: 777 }] })
  })

  test('the pager stays reachable at the ends of the list', async ({ page }) => {
    /*
     * §6.2 again: a `disabled` pager drops out of the tab order, so a keyboard
     * reader loses the control entirely at either end rather than being told
     * they have reached one.
     */
    const prev = page.getByRole('button', { name: 'Previous page' })
    await expect(prev).toHaveAttribute('aria-disabled', 'true')
    expect(await prev.evaluate((el) => el.hasAttribute('disabled'))).toBe(false)
    expect(await prev.evaluate((el) => (el as HTMLElement).tabIndex)).toBe(0)
  })
})

test.describe('Owner Status', () => {
  let asked: string[]

  test.beforeEach(async ({ page }) => {
    asked = []
    await page.route('**/api/v1/admin/businesses*', async (route) => {
      const url = new URL(route.request().url())
      asked.push(url.search)
      const q = (url.searchParams.get('q') ?? '').toLowerCase()
      const rows = q
        ? BUSINESSES.filter((b) => `${b.name} ${b.owner.name}`.toLowerCase().includes(q))
        : BUSINESSES
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(page1(rows)) })
    })

    await page.goto('/staff/admin/owners')
    await expect(page.getByRole('heading', { name: /Owner Status/i, level: 1 })).toBeVisible()
    await expect(page.locator('tbody tr')).toHaveCount(BUSINESSES.length)
  })

  test('each business is named with its owner and its status in words', async ({ page }) => {
    const suspended = page.locator('tbody tr', { hasText: 'RxCare Pharmacy' })
    await expect(suspended).toContainText('Juan Ramos')
    await expect(suspended).toContainText('Suspended')
  })

  test('each row carries the business’s filing number, under its name', async ({ page }) => {
    /*
     * This is the screen where an admin suspends somebody's livelihood, and
     * "which of these is the one the complaint is about" must not be answered
     * by a name alone — six rows, two owners, names that share a word.
     *
     * `BIZ-2026-…` is a FILING's number, minted at submit and taken afresh by
     * every renewal, so a business that has filed twice holds two. The row
     * shows the LATEST, which is a claim about ORDER — see the API test that
     * pins it to `submitted_at` rather than to insertion order.
     */
    const store = page.locator('tbody tr', { hasText: 'Aling Nena Sari-Sari Store' })
    await expect(store).toContainText('BIZ-2026-00001')

    const pharmacy = page.locator('tbody tr', { hasText: 'RxCare Pharmacy' })
    await expect(pharmacy).toContainText('BIZ-2026-00002')

    // Name and number, and nothing else: the label and the count came off at
    // the client's request once they knew a renewal takes a new number.
    await expect(store).not.toContainText(/latest filing|in total|filings/i)
  })

  test('says so in words when a business has not filed yet', async ({ page }) => {
    // Not a blank. A business exists in the register from the moment it is
    // created, and an empty line under its name reads as a value that failed
    // to load.
    await page.route('**/api/v1/admin/businesses?*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [{ ...BUSINESSES[0], tracking_id: null, applications_count: 0 }],
          meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
        }),
      }),
    )
    await page.reload()
    await expect(page.locator('tbody tr').first()).toContainText('No filing yet')
  })

  test('both numbers are named', async ({ page }) => {
    // "2 businesses" reads as the whole register; "2 of 2" says it is.
    await expect(page.getByText(/Showing \d+ of \d+ businesses/)).toBeVisible()
  })

  test('search narrows on the server', async ({ page }) => {
    await page.getByRole('searchbox', { name: 'Search businesses or owners' }).fill('rxcare')
    await expect(page.locator('tbody tr')).toHaveCount(1)
    await expect.poll(() => asked.at(-1)).toContain('q=rxcare')
  })

  test('a status change must state a reason before it can be confirmed', async ({ page }) => {
    /*
     * The reason is not decoration: it is what the owner is shown and what the
     * history keeps. Confirm therefore waits for it — and stays reachable while
     * it waits, so the reader can find out why it will not go (§6.2).
     */
    await page.locator('tbody tr', { hasText: 'RxCare Pharmacy' }).getByRole('button', { name: 'Change Status' }).click()
    await expect(page.getByRole('heading', { name: 'Changing Status' })).toBeVisible()

    const confirm = page.getByRole('button', { name: 'Confirm' })
    await expect(confirm).toHaveAttribute('aria-disabled', 'true')
    expect(await confirm.evaluate((el) => el.hasAttribute('disabled'))).toBe(false)

    const modal = page.locator('div.fixed.inset-0')
    await expect(modal.locator('select').first().locator('option')).toHaveText([
      'Active',
      'Flagged',
      'Suspended',
      'Blacklisted',
    ])

    await modal.locator('select').nth(1).selectOption({ index: 1 })
    await expect(confirm).not.toHaveAttribute('aria-disabled', 'true')
  })

  test('the status history is readable and says who changed what', async ({ page }) => {
    /*
     * The history is audit-fed, and reads `changes.{from,to,reason}` — the keys
     * BusinessStatusController actually writes. It once read `changes.status`,
     * which is never there, so a blacklisting rendered as "Active" in green
     * with the reason dropped. Stubbed in the real shape so this test would
     * catch that again.
     */
    await page.route('**/api/v1/admin/audit-logs*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            {
              id: 1,
              action: 'business.status_changed',
              auditable_type: 'Business',
              auditable_id: 22,
              user: { id: 1, name: 'Ramon Santos' },
              changes: {
                from: 'active',
                to: 'suspended',
                reason: 'Falsified / misrepresented documents · Driven by the check.',
              },
              created_at: '2026-09-01T02:00:00.000000Z',
            },
          ],
          meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
        }),
      }),
    )

    await page.locator('tbody tr', { hasText: 'RxCare Pharmacy' })
      .getByRole('button', { name: 'View Status History' })
      .click()

    const history = page.getByRole('dialog').filter({ hasText: 'Status History' })
    await expect(history).toBeVisible()
    await expect(history).toContainText('Ramon Santos')
    await expect(history).toContainText('Falsified / misrepresented documents')
  })
})
