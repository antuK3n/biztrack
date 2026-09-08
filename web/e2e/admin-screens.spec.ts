import { expect, test } from '@playwright/test'
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

const BUSINESSES = [
  {
    id: 21,
    name: 'Aling Nena Sari-Sari Store',
    status: 'active',
    status_label: 'Active',
    owner: { id: 31, name: 'Nena Makiling', email: 'owner@biztrack.local' },
  },
  {
    id: 22,
    name: 'RxCare Pharmacy',
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

      let rows = [...OFFICERS]
      if (q) rows = rows.filter((u) => `${u.first_name} ${u.last_name} ${u.email}`.toLowerCase().includes(q))
      if (dept) rows = rows.filter((u) => String(u.department.id) === dept)
      if (role) rows = rows.filter((u) => u.roles.includes(role))
      if (active === '1') rows = rows.filter((u) => u.is_active)
      if (active === '0') rows = rows.filter((u) => !u.is_active)

      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(page1(rows)) })
    })

    await page.goto('/staff/admin/users')
    await expect(page.getByRole('heading', { name: 'Officer Assignment', level: 1 })).toBeVisible()
    await expect(page.locator('tbody tr')).toHaveCount(OFFICERS.length)
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
    await expect(rows).toHaveCount(OFFICERS.length)

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
