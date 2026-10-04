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

  test('retired businesses are listed only when asked for, and offer no actions', async ({ page }) => {
    /*
     * Checklist item 21. "All" is every business still on the register; a
     * retired one — removed from it — is listed only under Retired, says so in
     * words, and carries no buttons, because every action binds a business
     * the server no longer finds.
     */
    expect(asked[0] ?? '').not.toContain('status=')

    await page.route('**/api/v1/admin/businesses?*', async (route) => {
      const url = new URL(route.request().url())
      asked.push(url.search)
      const retired = url.searchParams.get('status') === 'retired'
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(
          page1(retired ? [{ ...BUSINESSES[0], retired_at: '2026-08-01T00:00:00.000Z' }] : BUSINESSES),
        ),
      })
    })

    await page.getByRole('button', { name: 'Retired', exact: true }).click()
    await expect.poll(() => asked.at(-1)).toContain('status=retired')

    const row = page.locator('tbody tr')
    await expect(row).toHaveCount(1)
    await expect(row).toContainText('Retired')
    await expect(row).toContainText('Removed from the register on')
    await expect(row.getByRole('button', { name: 'Change Status' })).toHaveCount(0)
    await expect(row.getByRole('button', { name: 'Transfer Ownership' })).toHaveCount(0)
  })

  test('a status change must state a reason before it can be confirmed', async ({ page }) => {
    /*
     * The reason is not decoration: it is what the owner is shown and what the
     * history keeps. Confirm therefore waits for it — and stays reachable while
     * it waits, so the reader can find out why it will not go (§6.2).
     */
    /*
     * By the ACCESSIBLE name, which now carries the business: twenty rows of
     * identical "Change Status" is twenty identical stops for a screen-reader
     * reader, so each button names what it acts on.
     */
    await page
      .locator('tbody tr', { hasText: 'RxCare Pharmacy' })
      .getByRole('button', { name: /^Change the status of/ })
      .click()
    await expect(page.getByRole('heading', { name: 'Changing Status' })).toBeVisible()

    /*
     * "Review this change", not "Confirm". The dialog gained a review step —
     * suspending a business stops it trading and blacklisting bars its owner
     * everywhere, so neither happens on one press any more.
     */
    const confirm = page.getByRole('button', { name: 'Review this change' })
    await expect(confirm).toHaveAttribute('aria-disabled', 'true')
    expect(await confirm.evaluate((el) => el.hasAttribute('disabled'))).toBe(false)

    const modal = page.locator('div.fixed.inset-0')
    await expect(modal.locator('select').first().locator('option')).toHaveText([
      'Active',
      'Flagged',
      'Suspended',
      'Blacklisted',
    ])

    /*
     * A different status AND a reason. RxCare is already suspended in the stub,
     * so the change has to be to something else: the dialog refuses a change that
     * is not one — the status select opens on what the business already is, so
     * Review used to be pressable the moment it appeared and pressing it wrote
     * an audit row recording a change to the same value.
     */
    await modal.locator('select').first().selectOption('flagged')
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
      .getByRole('button', { name: /^Status history for/ })
      .click()

    const history = page.getByRole('dialog').filter({ hasText: 'Status History' })
    await expect(history).toBeVisible()
    await expect(history).toContainText('Ramon Santos')
    await expect(history).toContainText('Falsified / misrepresented documents')
  })
})
