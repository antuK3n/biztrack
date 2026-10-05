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
  reviews?: number
  inspections?: number
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
    /*
     * The workload counts, which the server sends only on this screen
     * (`whenCounted`). Defaulted to zero so a fixture row that does not care
     * still renders the column, and overridden per officer below for the
     * three cases the cell has to tell apart: carrying work, carrying none,
     * and a payload that carried no counts at all.
     */
    open_reviews: over.reviews ?? 0,
    open_inspections: over.inspections ?? 0,
  }
}

const OFFICERS = [
  officer({ id: 11, first: 'Liza', last: 'Reyes', email: 'bplo@biztrack.local', dept: DEPARTMENTS[0], role: 'bplo_staff', reviews: 3 }),
  // The client's rule: an office may hold MORE THAN ONE account.
  officer({ id: 12, first: 'Marites', last: 'Santos', email: 'bplo.two@biztrack.local', dept: DEPARTMENTS[0], role: 'bplo_staff' }),
  officer({ id: 13, first: 'Carlos', last: 'Dizon', email: 'sanitary@biztrack.local', dept: DEPARTMENTS[1], role: 'sanitary_officer' }),
  officer({ id: 14, first: 'Rosa', last: 'Lim', email: 'retired@biztrack.local', dept: DEPARTMENTS[1], role: 'sanitary_officer', active: false, reviews: 1, inspections: 2 }),
]

/*
 * The super admin, which belongs to NO office. Built by hand rather than
 * through `officer()` because that helper takes a department and this account's
 * whole point is not having one.
 */
const SUPER_ADMIN = {
  ...officer({ id: 15, first: 'Ramon', last: 'Santos', email: 'admin@biztrack.local', dept: DEPARTMENTS[0], role: 'admin' }),
  department: null,
  /*
   * No counts at all — the `undefined` case, which is a payload from before
   * they existed rather than an officer holding nothing. The cell prints a
   * dash for it and "Nothing" for a real zero, and those are different facts.
   */
  open_reviews: undefined,
  open_inspections: undefined,
}

/*
 * What an unfiltered /admin/users answers with: the offices AND the seat that
 * belongs to none of them. Counted from here so adding an account to the
 * fixture cannot leave a stale number behind in a test.
 */
const ROSTER = [...OFFICERS, SUPER_ADMIN]

const OWNERS = [
  {
    id: 31,
    name: 'Ana Cruz',
    email: 'ana.cruz@example.com',
    status: 'active',
    status_label: 'Active',
    blacklisted_at: null,
    reason: null,
    blacklisted_by: null,
    businesses: [
      { id: 21, name: 'RxCare Pharmacy', ban: null, status: 'active', status_label: 'Active', tracking_id: 'BIZ-2026-00002', created_at: '2026-08-01T00:00:00Z' },
    ],
  },
  {
    id: 32,
    name: 'Three Shops Reyes',
    email: 'three.shops@example.com',
    status: 'blacklisted',
    status_label: 'Blacklisted',
    blacklisted_at: '2026-10-01T00:00:00Z',
    reason: 'Falsified sanitary clearance.',
    blacklisted_by: 'Ramon Santos',
    businesses: ['Reyes Sari-Sari', 'Reyes Hardware', 'Reyes Canteen'].map((name, i) => ({
      id: 40 + i, name, ban: null, status: 'suspended', status_label: 'Suspended', tracking_id: null, created_at: '2026-07-01T00:00:00Z',
    })),
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
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByRole('heading', { name: /Add|Create/ })).toBeVisible()

    /*
     * Through the office first, because the office is what decides which roles
     * are offered at all: the super admin is the one account belonging to no
     * office, so it lives behind that choice and nowhere else.
     */
    await dialog.getByLabel(/^Office/).selectOption('none')

    const role = dialog.getByRole('combobox', { name: /^Role/ })
    await expect(role).toBeEnabled()
    await role.click()

    const admin = dialog.getByRole('option', { name: /Administrator/ })
    await expect(admin).toHaveCount(1)

    // Marked as taken, and it SAYS so rather than being quietly inert. A role
    // that cannot be granted has to explain itself; dropping it from the list
    // would leave an admin hunting for an option that is simply absent.
    await expect(admin).toHaveAttribute('aria-disabled', 'true')
    await expect(admin).toContainText(/already assigned/)

    /*
     * And pressing it changes nothing, which is the part the attribute alone
     * does not prove.
     *
     * Dispatched rather than clicked: Playwright reads `aria-disabled` as
     * not-enabled and refuses the click, so a plain `.click()` here would
     * only re-assert the attribute that was just checked. The event goes
     * straight at the handler, which is where the actual guard lives - an
     * option that merely LOOKS unavailable is the bug this is about.
     */
    await admin.dispatchEvent('mousedown')
    await expect(role).toHaveValue('')
  })

  test('offers Reassign only to an account that belongs to an office', async ({ page }) => {
    /*
     * Reassign moves an officer's caseload to a colleague in their own office,
     * and hands them work from that office's queue. The super admin belongs to
     * no department, so both halves are empty by construction — nothing to
     * move, no queue to move it from — and the take endpoint answers 422 on
     * exactly that ground. A button that can only fail is worse than no button.
     */
    /*
     * A LINK now, not a button: Reassign opens a page rather than a dialog
     * [27 September 2026]. The claim is unchanged — the control is offered
     * only where it can work — and it is the reason this test survived the
     * move while the three that drove the dialog did not.
     */
    const officer = page.locator('tbody tr', { hasText: 'bplo@biztrack.local' })
    await expect(officer.getByRole('link', { name: /^Reassign .*caseload$/ })).toBeVisible()

    const superAdmin = page.locator('tbody tr', { hasText: 'admin@biztrack.local' })
    await expect(superAdmin).toHaveCount(1)
    await expect(superAdmin.getByRole('link', { name: /^Reassign/ })).toHaveCount(0)
    // The rest of the row is untouched: the account is still editable.
    await expect(superAdmin.getByRole('button', { name: 'Edit' })).toBeVisible()
  })

  /*
   * ── Three dialog tests retired ──────────────────────────────────────────
   *
   * They drove the Reassign MODAL: that an officer holding nothing was told
   * so with no scope to choose from, that Scope listed the permits one by one,
   * and that the same dialog could hand over the office's unheld work.
   *
   * The dialog is a page now, and every one of those claims is made against
   * it in `officer-caseload.spec.ts` — "an officer holding nothing is told so,
   * in words", "the tables are the Officer in Charge columns, per list" with
   * "every row checkbox names the filing it selects", and "the office's unheld
   * work can be handed to this officer".
   *
   * Deleted rather than pointed at the new screen: a test in this file would
   * have to navigate away from the screen this file is about, and two specs
   * asserting one page is how they drift.
   */

  test('the directory says what each officer is carrying', async ({ page }) => {
    /*
     * Client, 27 September 2026: "need mo pa pindutin isa isa kung ano laman
     * na permit na hawak nila." The directory listed name, office and status
     * and said nothing about load, so finding who was carrying work meant
     * opening every row in turn.
     */
    /*
     * Not `exact`. The heading carries a link to the register's matching view
     * beside the word, so its accessible name is "Holding open work" — and
     * that link is part of what this column is for: the number here and the
     * rows there are the same set.
     */
    await expect(page.getByRole('columnheader', { name: 'Holding', exact: true })).toBeVisible()

    /*
     * And nothing else in it.
     *
     * A link to the register's matching view lived in this heading — first
     * beside the word, where "Holding open work" read as one four-word column
     * name, then under it, where the two-line cell left every other heading in
     * the row sitting unevenly against it. Navigation belongs in the page
     * header, which is where it went.
     */
    const headers = page.locator('thead th')
    await expect(headers.locator('a')).toHaveCount(0)

    // Every heading the same height, which is what the link was breaking.
    const heights = await headers.evaluateAll((cells) =>
      cells.map((c) => Math.round(c.getBoundingClientRect().height)),
    )
    expect(new Set(heights).size).toBe(1)

    const busy = page.locator('tbody tr', { hasText: 'bplo@biztrack.local' }).first()
    await expect(busy).toContainText('3 filings')

    /*
     * "Nothing", not a dash. A dash means "no value"; this has a value and it
     * is zero — and a clear desk is the fact an administrator is looking FOR,
     * because it is who the next case goes to.
     */
    const free = page.locator('tbody tr', { hasText: 'sanitary@biztrack.local' })
    await expect(free).toContainText('Nothing')
  })

  test('site visits are named apart from paperwork', async ({ page }) => {
    // They move by a different act on the caseload screen, so an administrator
    // planning a reassignment needs to know the load is not all paperwork.
    const mixed = page.locator('tbody tr', { hasText: 'retired@biztrack.local' })
    await expect(mixed).toContainText('site visit')
  })

  test('no row prints a dash, because every payload carries the figure', async ({ page }) => {
    /*
     * This asserted the opposite: a dash for the one payload that carried no
     * counts. The dash was honest - `undefined` is not zero, and printing
     * "Nothing" would have invented a figure - but it was the wrong thing to
     * be honest about, because the reason the counts were missing was fixable
     * [client, 27 September 2026: *"bat may ganyan pa sa holding, kung wala,
     * it should be automatic na Nothing"*].
     *
     * All four endpoints that answer with a user count the caseload now
     * (`UserController::withCaseload`), so there is no payload left for the
     * cell to apologise for, and a dash reappearing here means one of them
     * stopped counting.
     */
    const holding = page.locator('tbody tr').locator('td:nth-child(4)')
    await expect(holding.first()).toBeVisible()

    const cells = await holding.allInnerTexts()
    expect(cells.length).toBeGreaterThan(0)
    for (const text of cells) {
      expect(text.trim(), 'a Holding cell still says it does not know').not.toBe('—')
      expect(text.trim()).toMatch(/Nothing|filing/)
    }
  })

  test('offers a way to the whole register, not just row by row', async ({ page }) => {
    /*
     * This directory lists OFFICERS; the Officer in Charge register lists
     * ASSIGNMENTS, every office at once, with the holder on each row. They
     * answer two halves of one question, and the door only existed in one
     * direction — that register's holder names already link into an officer's
     * caseload.
     */
    const door = page.getByRole('link', { name: 'View all assignments' })
    await expect(door).toBeVisible()
    await expect(door).toHaveAttribute('href', '/staff/admin/oic')
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

test.describe('Business Owner Status', () => {
  /*
   * One row per OWNER [client, 5 October 2026]: their businesses (a dropdown
   * when there are several), the owner's status, Change status (Active or
   * Blacklisted) and View status history.
   */
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/v1/admin/owners?*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: OWNERS, meta: { current_page: 1, last_page: 1, per_page: 25, total: OWNERS.length } }),
      }),
    )
    await page.goto('/admin/owners')
    await expect(page.getByRole('heading', { name: 'Business Owner Status' })).toBeVisible()
  })

  test('lists owners, with their businesses behind a dropdown when there are several', async ({ page }) => {
    for (const label of ['Owner', 'Businesses', 'Status', 'Actions']) {
      await expect(page.getByRole('columnheader', { name: label })).toBeVisible()
    }
    const many = page.locator('tbody tr', { hasText: 'Three Shops Reyes' })
    await expect(many).not.toContainText('Reyes Hardware')
    await many.getByRole('button', { name: /3 businesses/ }).click()
    await expect(many).toContainText('Reyes Hardware')

    // One business shows itself without a dropdown.
    await expect(page.locator('tbody tr', { hasText: 'Ana Cruz' })).toContainText('RxCare Pharmacy')
  })

  test('offers the owner only Active or Blacklisted, with a reason', async ({ page }) => {
    let sent: unknown = null
    await page.route('**/api/v1/admin/owners/*/status', async (route) => {
      sent = route.request().postDataJSON()
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { id: 31, status: 'blacklisted', status_label: 'Blacklisted', businesses_moved: 1 } }) })
    })

    await page.getByRole('button', { name: 'Change the status of Ana Cruz' }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toContainText('Blacklisted')
    await expect(dialog).toContainText('suspended at once')
    const save = dialog.getByRole('button', { name: 'Save status' })
    await expect(save).toHaveAttribute('aria-disabled', 'true')
    await dialog.getByRole('textbox', { name: 'Reason' }).fill('Falsified documents.')
    await save.click()
    await expect(dialog).toHaveCount(0)
    expect(sent).toEqual({ status: 'blacklisted', reason: 'Falsified documents.' })
  })

  test('locks a blacklisted owner’s businesses behind a modal', async ({ page }) => {
    const row = page.locator('tbody tr', { hasText: 'Three Shops Reyes' })
    await row.getByRole('button', { name: /3 businesses/ }).click()
    await row.getByRole('button', { name: 'Change the status of Reyes Hardware' }).click()
    const dialog = page.getByRole('dialog', { name: 'The owner is blacklisted' })
    await expect(dialog).toBeVisible()
    await expect(dialog).not.toContainText('Save status')
  })
})
