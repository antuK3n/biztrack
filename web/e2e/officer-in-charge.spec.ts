import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * Officer in Charge, in the browser: the three screens the rule surfaces on.
 *
 * ── What this file is for, and what it is NOT ───────────────────────────────
 *
 * The RULE is proved server-side, where it is enforced — OfficerInChargeTest
 * covers claiming, the race between two officers, the refusal of every act to
 * anyone but the holder, reassignment, and the applicant's view, across six
 * offices on one shared filing. Nothing here re-proves any of that, and a test
 * that did would be asserting the same thing twice while pretending to check
 * a screen.
 *
 * What only a browser can answer is whether the answer REACHES the reader:
 * whether the officer holding a case is named on the row, whether a colleague's
 * case is visibly read-only rather than merely unclickable, whether the
 * super admin's Reassign dialog offers the right officers, and whether the
 * applicant is told who has their filing. Those are the assertions below.
 *
 * Payloads are stubbed for the reason track-search.spec gives: a narrowing
 * assertion has to know exactly which rows exist, and this suite stays
 * read-only against a register that may hold testers' filings.
 */

const OFFICE = { id: 1, code: 'BPLO', name: 'Business Permits and Licensing Office' }
const ME = { id: 2, name: 'Liza Reyes' }
const COLLEAGUE = { id: 30, name: 'Marites Cruz' }

/** One row of `/assignments`, in the shape AssignmentResource emits. */
function assignment(
  id: number,
  tracking: string,
  business: string,
  officer: { id: number; name: string } | null,
  can: { claim: boolean; act: boolean },
) {
  return {
    id,
    status: 'pending',
    status_label: 'Pending',
    remarks: null,
    department: { code: OFFICE.code, name: OFFICE.name },
    officer,
    officer_withheld: false,
    can_claim: can.claim,
    can_act: can.act,
    assigned_at: officer ? '2026-09-01T02:00:00.000000Z' : null,
    completed_at: null,
    application: {
      id: 500 + id,
      tracking_id: tracking,
      application_type: 'new',
      status: 'for_approval',
      status_label: 'For Approval',
      business: { id: 900 + id, name: business },
      submitted_at: '2026-09-01T00:00:00.000000Z',
      deadline_at: null,
      permit_types: [{ code: 'BUSINESS', name: 'Business Permit' }],
      created_at: '2026-09-01T00:00:00.000000Z',
    },
    clearance: { code: 'BUSINESS', name: 'Business Permit', status: 'for_approval', status_label: 'For Approval', mode: 'apply' },
  }
}

const FREE = assignment(11, 'BIZ-2026-00011', 'Aling Nena Bakery', null, { claim: true, act: true })
const MINE = assignment(12, 'BIZ-2026-00012', 'Malabon Hardware', ME, { claim: false, act: true })
const THEIRS = assignment(13, 'BIZ-2026-00013', 'Riverside Carinderia', COLLEAGUE, { claim: false, act: false })

/* ── The office Track page ────────────────────────────────────────────────── */

test.describe('the office Track page', () => {
  test.use({ storageState: sessionFor('bplo') })

  /** Every /assignments query the page asked for, in order. */
  let asked: string[]
  /** Assignment ids the page tried to claim. */
  let claimed: number[]

  test.beforeEach(async ({ page }) => {
    asked = []
    claimed = []

    await page.route('**/api/v1/assignments/*/claim', async (route) => {
      claimed.push(Number(new URL(route.request().url()).pathname.split('/').at(-2)))
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { ...FREE, officer: ME, can_claim: false, can_act: true } }),
      })
    })

    await page.route('**/api/v1/assignments?*', async (route) => {
      const url = new URL(route.request().url())
      asked.push(url.search)

      /*
       * The stub narrows the way the server does, because the point of the
       * assertion is that the browser ASKED. A stub that ignored `oic` would
       * pass whether the page sent the parameter or sliced the rows itself.
       */
      const rows = [FREE, MINE, THEIRS].filter((a) => {
        const narrow = url.searchParams.get('oic')
        if (narrow === 'unassigned') return a.officer === null
        if (narrow === 'mine') return a.officer?.id === ME.id
        if (narrow === 'others') return a.officer !== null && a.officer.id !== ME.id
        return true
      })

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: rows,
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 20,
            total: rows.length,
            application_status_counts: {},
          },
        }),
      })
    })

    await page.goto('/staff/queue')
    await expect(page.getByRole('heading', { name: /application verification/i })).toBeVisible({
      timeout: 30_000,
    })
  })

  test('says who holds each filing, and offers Claim only on the free one', async ({ page }) => {
    const free = page.getByRole('listitem').filter({ hasText: 'Aling Nena Bakery' })
    const mine = page.getByRole('listitem').filter({ hasText: 'Malabon Hardware' })
    const theirs = page.getByRole('listitem').filter({ hasText: 'Riverside Carinderia' })

    await expect(free).toContainText('Not yet taken by anyone')
    await expect(free.getByRole('button', { name: /claim this filing/i })).toBeVisible()

    // Held by the reader: named, and no Claim — you cannot take what is yours.
    await expect(mine).toContainText(`Officer in charge: ${ME.name}`)
    await expect(mine.getByRole('button', { name: /claim this filing/i })).toHaveCount(0)

    /*
     * A colleague's case is the one that matters. It must be visibly SOMEBODY
     * ELSE'S rather than merely un-clickable: an officer who cannot tell the
     * difference between "not mine" and "broken" will ask why the button is
     * missing, and the answer has to be on the row.
     */
    await expect(theirs).toContainText(`Officer in charge: ${COLLEAGUE.name}`)
    await expect(theirs).toContainText('read-only for you')
    await expect(theirs.getByRole('button', { name: /claim this filing/i })).toHaveCount(0)
  })

  test('claiming asks the server, and re-reads the list rather than editing it', async ({ page }) => {
    const free = page.getByRole('listitem').filter({ hasText: 'Aling Nena Bakery' })
    await free.getByRole('button', { name: /claim this filing/i }).click()

    await expect.poll(() => claimed).toContain(FREE.id)

    /*
     * The list is re-read, not patched. Under "Unassigned" a claimed row must
     * LEAVE the list, and a local edit would leave it sitting under a heading
     * that no longer describes it.
     */
    await expect.poll(() => asked.length).toBeGreaterThan(1)
  })

  test('the four sections narrow on the server, because the queue is paged', async ({ page }) => {
    const rows = page.getByRole('listitem')

    await page.getByRole('button', { name: 'Unassigned' }).click()
    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('Aling Nena Bakery')
    await expect.poll(() => asked.at(-1)).toContain('oic=unassigned')

    await page.getByRole('button', { name: 'My assigned' }).click()
    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('Malabon Hardware')
    await expect.poll(() => asked.at(-1)).toContain('oic=mine')

    await page.getByRole('button', { name: 'Assigned to others' }).click()
    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('Riverside Carinderia')
    await expect.poll(() => asked.at(-1)).toContain('oic=others')

    // "All" is a narrowing removed, not a fourth value sent to the server.
    await page.getByRole('button', { name: 'All', exact: true }).click()
    await expect(rows).toHaveCount(3)
    await expect.poll(() => asked.at(-1)).not.toContain('oic=')
  })
})

/* ── The super admin's Officer in Charge page ─────────────────────────────── */

const REGISTER = [
  {
    id: 11,
    application_id: 511,
    tracking_id: 'BIZ-2026-00011',
    application_type: 'new',
    business: { id: 911, name: 'Aling Nena Bakery' },
    office: OFFICE,
    officer: null,
    assigned_at: null,
    completed_at: null,
    status: 'pending',
    status_label: 'Pending',
    application_status: 'for_approval',
    application_status_label: 'For Approval',
  },
  {
    id: 13,
    application_id: 513,
    tracking_id: 'BIZ-2026-00013',
    application_type: 'new',
    business: { id: 913, name: 'Riverside Carinderia' },
    office: OFFICE,
    officer: { id: COLLEAGUE.id, name: COLLEAGUE.name, email: 'marites@biztrack.local' },
    assigned_at: '2026-09-01T02:00:00.000000Z',
    completed_at: null,
    status: 'in_progress',
    status_label: 'In progress',
    application_status: 'for_approval',
    application_status_label: 'For Approval',
  },
]

test.describe('the super admin’s Officer in Charge page', () => {
  test.use({ storageState: sessionFor('admin') })

  /** Bodies posted to the reassign endpoint. */
  let assigned: { id: number; body: unknown }[]

  test.beforeEach(async ({ page }) => {
    assigned = []

    await page.route('**/api/v1/admin/oic-assignments?*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: REGISTER,
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 50,
            total: REGISTER.length,
            departments: [OFFICE],
          },
        }),
      })
    })

    await page.route('**/api/v1/admin/oic-assignments/*/candidates', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            { id: ME.id, name: ME.name, email: 'bplo@biztrack.local', department_id: OFFICE.id, is_current: false },
            { id: COLLEAGUE.id, name: COLLEAGUE.name, email: 'marites@biztrack.local', department_id: OFFICE.id, is_current: true },
          ],
        }),
      })
    })

    await page.route('**/api/v1/assignments/*/assign', async (route) => {
      assigned.push({
        id: Number(new URL(route.request().url()).pathname.split('/').at(-2)),
        body: route.request().postDataJSON(),
      })
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {} }) })
    })

    await page.goto('/staff/admin/oic')
    await expect(page.getByRole('heading', { name: 'Officer in Charge', level: 1 })).toBeVisible({
      timeout: 30_000,
    })
  })

  test('carries every column the client asked for', async ({ page }) => {
    /*
     * Exact names. A loose match on "Business" also matches "Business No." and
     * resolves to two elements — worth keeping in mind rather than only fixing:
     * the two columns are deliberately different facts, the trading name and
     * the tracking ID, and a test that could not tell them apart would pass on
     * a table that printed either one twice.
     */
    for (const column of ['Business', 'Business No.', 'Office', 'Officer in charge', 'Assigned', 'Status', 'Action']) {
      await expect(page.getByRole('columnheader', { name: column, exact: true })).toBeVisible()
    }

    const held = page.locator('tbody tr').filter({ hasText: 'Riverside Carinderia' })
    await expect(held).toContainText('BIZ-2026-00013')
    await expect(held).toContainText(OFFICE.name)
    await expect(held).toContainText(COLLEAGUE.name)

    /*
     * "Not yet taken", not a dash. On an officer's own queue a null holder can
     * mean "you may not be told"; this reader sees every office, so null has
     * exactly one meaning — and it is the row the super admin opened the page
     * to find.
     */
    const free = page.locator('tbody tr').filter({ hasText: 'Aling Nena Bakery' })
    await expect(free).toContainText('Not yet taken')
  })

  test('reassigns to a colleague of the same office, and says who holds it now', async ({ page }) => {
    const held = page.locator('tbody tr').filter({ hasText: 'Riverside Carinderia' })
    await held.getByRole('button', { name: 'Reassign' }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog).toContainText('Riverside Carinderia')
    await expect(dialog).toContainText(COLLEAGUE.name)

    // The dialog opens on the current holder rather than on an empty choice.
    const picker = dialog.getByLabel(/new officer in charge/i)
    await expect(picker).toHaveValue(String(COLLEAGUE.id))

    await picker.selectOption(String(ME.id))
    await dialog.getByRole('textbox').fill('Marites is on leave.')
    await dialog.getByRole('button', { name: 'Reassign', exact: true }).click()

    await expect.poll(() => assigned.map((a) => a.id)).toContain(13)
    expect(assigned.at(-1)?.body).toMatchObject({ officer_user_id: ME.id, reason: 'Marites is on leave.' })
  })

  /*
   * Who may open this page is NOT asserted here. It is a permission — the route
   * is gated on `oic.assign` and every endpoint behind it on the same — and the
   * API suite proves the refusal for an office account and for an applicant
   * (OfficerInChargeTest: "closes the OIC register to everyone but the super
   * admin"). A browser test of the same thing would need a session this project
   * mints per seeded account, and would restate a server rule while looking
   * like a screen check.
   */
})

/* ── The business owner's side ────────────────────────────────────────────── */

test.describe('the business owner is told who holds their filing', () => {
  test.use({ storageState: sessionFor('owner') })

  const FILING = {
    id: 777,
    tracking_id: 'BIZ-2026-00777',
    application_type: 'new',
    status: 'for_approval',
    status_label: 'For Approval',
    business: { id: 777, name: 'Aling Nena Bakery', address: null, lines: [] },
    applicant: { id: 4, name: 'Nena Dela Cruz' },
    submitted_at: '2026-09-01T00:00:00.000000Z',
    deadline_at: null,
    created_at: '2026-09-01T00:00:00.000000Z',
    permit_types: [],
    documents: [],
    payments: [],
    inspections: [],
    rejection_reason: null,
    assignments: [
      {
        id: 1,
        status: 'in_progress',
        status_label: 'In progress',
        remarks: null,
        department: { code: 'BPLO', name: OFFICE.name },
        officer: ME,
        officer_withheld: false,
        assigned_at: '2026-09-01T02:00:00.000000Z',
        completed_at: null,
        clearance: null,
      },
      {
        id: 2,
        status: 'pending',
        status_label: 'Pending',
        remarks: null,
        department: { code: 'CHO', name: 'City Health Office' },
        officer: null,
        officer_withheld: false,
        assigned_at: null,
        completed_at: null,
        clearance: null,
      },
    ],
  }

  test.beforeEach(async ({ page }) => {
    await page.route('**/api/v1/applications/777', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: FILING }),
      })
    })
    await page.goto('/applications/777')
  })

  test('names one officer per office, and says which office has nobody yet', async ({ page }) => {
    const panel = page.locator('section[aria-labelledby="handling-heading"]')
    await expect(panel).toBeVisible({ timeout: 30_000 })

    const rows = panel.getByRole('listitem')
    await expect(rows).toHaveCount(2)

    /*
     * One line per OFFICE. The screen used to print a single name picked with
     * `assignments.find(a => a.officer) ?? assignments[0]`, so on a filing
     * several offices share it named one of them by list order and the
     * applicant read it as "the officer handling my application".
     */
    const bplo = rows.filter({ hasText: OFFICE.name })
    await expect(bplo).toContainText(ME.name)

    const cho = rows.filter({ hasText: 'City Health Office' })
    await expect(cho).toContainText('Not yet taken')
    await expect(cho).not.toContainText(ME.name)
  })

  test('offers a way to ask the office about it', async ({ page }) => {
    const panel = page.locator('section[aria-labelledby="handling-heading"]')
    await expect(panel).toBeVisible({ timeout: 30_000 })

    await panel.getByRole('link', { name: /message an office about this application/i }).click()
    await expect(page).toHaveURL(/\/messages\?application=777/)
  })
})
