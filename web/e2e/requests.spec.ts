import { expect, test, type Page } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * The officer request composer, against a filing whose business is gone.
 *
 * `Business` soft-deletes and its filings stay behind — 139 applications
 * currently point at a deleted one — and `ApplicationListResource` emits
 * `business: null` for every one of them. The composer read `.business.name`
 * straight through, so opening it threw, and with no error boundary in the
 * tree the throw blanked the entire page rather than one row of a dropdown.
 *
 * It hid because the newest applications all have their businesses. Nothing
 * surfaced until a queue ran deep enough to reach a deleted one, which is the
 * same way the identical bug hid the last two times it was fixed — see the
 * note on `Assignment` in lib/types.ts.
 *
 * The response is stubbed rather than seeded. The null only appears once a
 * list runs past the clean rows, so a test that relied on real data would
 * pass on a fresh database and fail a year later; and it is the component's
 * contract with a shape, not the register's contents, that is under test.
 */

test.use({ storageState: sessionFor('bplo') })

const WITH_DELETED_BUSINESS = [
  {
    id: 90001,
    tracking_id: 'BIZ-2023-00025',
    application_type: 'new',
    title: null,
    status: 'for_approval',
    status_label: 'For Initial Approval',
    // The register no longer holds this one.
    business: null,
    submitted_at: '2026-01-05T00:00:00.000000Z',
    deadline_at: null,
    permit_types: [{ code: 'BUSINESS', name: 'Business Permit' }],
    created_at: '2026-01-05T00:00:00.000000Z',
  },
  {
    id: 90002,
    tracking_id: 'BIZ-2026-00100',
    application_type: 'renewal',
    title: null,
    status: 'for_approval',
    status_label: 'For Initial Approval',
    business: { id: 5, name: 'Dela Cruz Trading' },
    submitted_at: '2026-06-01T00:00:00.000000Z',
    deadline_at: null,
    permit_types: [{ code: 'BUSINESS', name: 'Business Permit' }],
    created_at: '2026-06-01T00:00:00.000000Z',
  },
]

test('the request composer survives a filing whose business was removed', async ({ page }) => {
  const crashes: string[] = []
  page.on('pageerror', (err) => crashes.push(err.message))

  await page.route('**/api/v1/applications*', async (route) => {
    // Only the list call carries this shape; detail routes go through.
    const url = new URL(route.request().url())
    if (!/\/api\/v1\/applications\/?$/.test(url.pathname)) return route.fallback()
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: WITH_DELETED_BUSINESS }),
    })
  })

  await page.goto('/staff/requests')
  await page.getByRole('button', { name: /request/i }).first().click()

  /*
   * The modal has to open at all — this is the assertion that failed.
   *
   * Matched on the composer's real heading rather than on /^request$/i. The
   * dialog was renamed "Create Other Requirement" when the client's create form
   * was rebuilt, and this kept asking for the old title: three tests red for a
   * heading that had simply moved on, which is the failure mode a name-based
   * locator has. It is still an exact name, not a substring — a modal that
   * fails to open must still fail this.
   */
  await expect(page.getByRole('heading', { name: 'Create Other Requirement' })).toBeVisible({ timeout: 15_000 })

  expect(crashes, `the composer threw: ${crashes.join(' | ')}`).toEqual([])
})

test('a removed business is named as removed, not left blank', async ({ page }) => {
  await page.route('**/api/v1/applications*', async (route) => {
    const url = new URL(route.request().url())
    if (!/\/api\/v1\/applications\/?$/.test(url.pathname)) return route.fallback()
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: WITH_DELETED_BUSINESS }),
    })
  })

  await page.goto('/staff/requests')
  await page.getByRole('button', { name: /request/i }).first().click()
  await expect(page.getByRole('heading', { name: 'Create Other Requirement' })).toBeVisible({ timeout: 15_000 })

  /*
   * An officer picking a filing to chase needs to know the register dropped
   * the business — that is usually why the filing stalled. A blank cell or a
   * bare tracking ID would send them hunting for the reason.
   */
  const options = await page.locator('select option').allTextContents()
  const orphan = options.find((o) => o.includes('BIZ-2023-00025'))

  expect(orphan, 'the filing with no business vanished from the picker').toBeTruthy()
  expect(orphan).toContain('Business removed from register')

  // The healthy row still reads normally.
  expect(options.find((o) => o.includes('BIZ-2026-00100'))).toContain('Dela Cruz Trading')
})

/*
 * Checklist item 89 — "requests for other requirements should have recipients".
 *
 * The recipient is shown rather than picked, because the model has exactly one
 * to offer: only the business_owner role holds `request.respond`, so a request
 * addressed to an office would arrive somewhere nobody could answer it. What
 * has to be true of a field that states rather than asks is that it is still
 * readable — `readOnly`, not `disabled`, or the keyboard skips it and screen
 * readers commonly drop it (WCAG 2.1 AA), which would hide the very fact the
 * field exists to state.
 */
test('the composer names who the request is going to, readably', async ({ page }) => {
  await page.route('**/api/v1/applications*', async (route) => {
    const url = new URL(route.request().url())
    if (!/\/api\/v1\/applications\/?$/.test(url.pathname)) return route.fallback()
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: WITH_DELETED_BUSINESS }),
    })
  })

  await page.goto('/staff/requests')
  await page.getByRole('button', { name: /request/i }).first().click()
  await expect(page.getByRole('heading', { name: 'Create Other Requirement' })).toBeVisible({ timeout: 15_000 })

  const recipient = page.getByLabel(/to \(recipient\)/i)
  await expect(recipient).toBeVisible()
  await expect(recipient).toHaveAttribute('readonly', '')
  await expect(recipient).toBeEnabled()

  // Empty until a filing is chosen — there is no recipient before there is an
  // application, and inventing one would be the fake picker in another costume.
  await expect(recipient).toHaveValue('')

  /*
   * By value: the option label carries the business name, which is the thing
   * under test, so selecting by it would assert nothing.
   *
   * The picker is labelled "Business" now, not "Application" — the client's
   * rebuilt create form asks which BUSINESS this requirement is for, because an
   * owner with two shops could not tell two filings apart by tracking number
   * alone. `/application/i` matched an input rather than the select after that
   * rename, and the failure read "Element is not a <select>".
   */
  await page.getByLabel(/business/i).first().selectOption('90002')

  /*
   * This stub carries no `applicant`, which is the real nullable case: User
   * soft-deletes and its filings stay. The fallback has to name somebody rather
   * than go blank, and it still has to say which business.
   */
  await expect(recipient).toHaveValue(/business owner on file · applicant for Dela Cruz Trading/i)
})

/*
 * An owner's requirements live in Messages, and so does their count.
 *
 * Issue 88 put a count on the home screen's Other Requirements tile — "like
 * notifications, which does not reduce until the requirement is submitted".
 * The client then asked for the tile gone: "Other Requirements — remove it
 * from the homepage; merge it into Messages." So the rules these tests hold
 * the screen to are now:
 *
 *   - the home screen draws no Other Requirements tile, and no link to one;
 *   - the Messages rail entry carries the count, and its accessible name says
 *     what the number is — a bare "2" after "Messages" reads as two unread
 *     conversations;
 *   - Messages has a Requirements tab listing the requests with their letter
 *     and Respond flow, and the old /requests address lands on it.
 *
 * The count is still `awaits_applicant`, the API's own answer to whose move it
 * is: Pending and Needs Resubmission count, a submission waiting on the office
 * does not. A count derived in the browser from `status` would have to
 * re-guess that.
 *
 * Stubbed, not seeded: the owner's real register has whatever it has, and a
 * test that asserted "2" against it would pass today and fail the first time
 * an office raises a third request. The unread-message count is stubbed to
 * zero so the badge's name is about requirements alone.
 */
test.describe('an owner answers requirements from Messages', () => {
  test.use({ storageState: sessionFor('owner') })

  const ownerRequirement = (id: number, subject: string, status: string, label: string) => ({
    id,
    subject,
    status,
    status_label: label,
    remarks: status === 'needs_resubmission' ? 'The scan is unreadable.' : null,
    accepts_response: status !== 'fulfilled',
    // The API decides this; the stub mirrors what it emits rather than what the
    // page would like. Submitted is with the office, so it is NOT owed.
    awaits_applicant: status === 'pending' || status === 'needs_resubmission',
    awaits_office: status === 'submitted',
    is_closed: status === 'fulfilled',
    additional_remarks: null,
    reference: null,
    due_date: null,
    reviewed_at: null,
    created_at: '2026-08-01T00:00:00.000000Z',
    created_by: { id: 5, name: 'CHO Officer', department: 'City Health Office' },
    from_office: { id: 2, code: 'CHO', name: 'City Health Office' },
    recipient: null,
    application: {
      id: 90101,
      business_id: 1,
      tracking_id: 'BIZ-2026-00101',
      business_name: 'Aling Nena Sari-Sari Store',
    },
    responses: [],
  })

  /** Stub /requests with these rows, and report every call the page made. */
  async function serve(page: Page, rows: unknown[]) {
    const calls: string[] = []
    await page.route('**/api/v1/requests*', async (route) => {
      calls.push(route.request().url())
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: rows,
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 100,
            total: rows.length,
            office_statuses: [],
            statuses: [],
          },
        }),
      })
    })
    return calls
  }

  /** Pin the unread-message half of the Messages badge to zero. */
  async function noUnreadMessages(page: Page) {
    await page.route('**/api/v1/unread-summary*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { messages: 0, notifications: 0 } }),
      }),
    )
  }

  const OWED_AND_NOT = () => [
    ownerRequirement(70001, 'Health certificate', 'pending', 'Pending'),
    ownerRequirement(70002, 'Water potability test', 'needs_resubmission', 'Needs Resubmission'),
    // Already answered — it is with the office, so it must NOT be counted.
    ownerRequirement(70003, 'Fire safety plan', 'submitted', 'For Review'),
    ownerRequirement(70004, 'Barangay clearance', 'fulfilled', 'Fulfilled'),
  ]

  test('the home screen has no Other Requirements tile', async ({ page }) => {
    await serve(page, OWED_AND_NOT())
    await noUnreadMessages(page)

    await page.goto('/')
    // The three tiles that remain, so the absence below is not a page that
    // failed to render.
    await expect(page.getByRole('link', { name: 'New Business Permit', exact: true })).toBeVisible({
      timeout: 15_000,
    })
    await expect(page.getByRole('link', { name: /Other Requirements/ })).toHaveCount(0)
    await expect(page.locator('a[href="/requests"]')).toHaveCount(0)
  })

  test('the Messages rail entry counts what is owed, and says so out loud', async ({ page }) => {
    await serve(page, OWED_AND_NOT())
    await noUnreadMessages(page)

    await page.goto('/')

    /*
     * By accessible name, because the name IS the feature. Needs Resubmission
     * still counts: if it were ever dropped from `awaits_applicant` this reads
     * 1 and the owner is told they owe one document fewer than they do.
     */
    const rail = page.getByRole('navigation', { name: 'Main' })
    const messages = rail.getByRole('link', { name: 'Messages, 2 requirements waiting on you' })
    await expect(messages).toBeVisible({ timeout: 15_000 })
    await expect(messages).toContainText('2')
  })

  test('one waiting requirement is counted as one', async ({ page }) => {
    await serve(page, [ownerRequirement(70005, 'Health certificate', 'pending', 'Pending')])
    await noUnreadMessages(page)

    await page.goto('/')

    const messages = page
      .getByRole('navigation', { name: 'Main' })
      .getByRole('link', { name: 'Messages, 1 requirement waiting on you' })
    await expect(messages).toBeVisible({ timeout: 15_000 })
  })

  test('nothing owed draws no badge at all', async ({ page }) => {
    await serve(page, [
      ownerRequirement(70006, 'Fire safety plan', 'submitted', 'For Review'),
      ownerRequirement(70007, 'Barangay clearance', 'fulfilled', 'Fulfilled'),
    ])
    await noUnreadMessages(page)

    await page.goto('/')

    /*
     * The plain name, and no digit on the entry. A "0" badge is a thing to
     * read and dismiss on every visit, which is how a badge stops meaning
     * anything.
     */
    const messages = page
      .getByRole('navigation', { name: 'Main' })
      .getByRole('link', { name: 'Messages', exact: true })
    await expect(messages).toBeVisible({ timeout: 15_000 })
    await expect(messages).not.toContainText(/\d/)
  })

  test('the old /requests address opens the Requirements tab, and a request can be answered there', async ({
    page,
  }) => {
    await serve(page, OWED_AND_NOT())
    await noUnreadMessages(page)

    await page.goto('/requests')
    await expect(page).toHaveURL(/\/messages\?tab=requirements$/)
    await expect(page.getByRole('heading', { name: 'Messages', level: 1 })).toBeVisible({
      timeout: 15_000,
    })

    // The tab says where the reader is, and carries the same count as the rail.
    const tabs = page.getByRole('navigation', { name: 'Messages sections' })
    await expect(tabs.getByRole('link', { name: 'Requirements 2 waiting on you' })).toHaveAttribute(
      'aria-current',
      'page',
    )
    await expect(tabs.getByRole('link', { name: 'Conversations' })).not.toHaveAttribute(
      'aria-current',
      'page',
    )

    // Every request is listed, owed or not — the tab is the whole register.
    for (const subject of [
      'Health certificate',
      'Water potability test',
      'Fire safety plan',
      'Barangay clearance',
    ]) {
      await expect(page.getByRole('cell', { name: new RegExp(subject) })).toBeVisible()
    }

    // And the existing letter-and-respond flow is behind each row.
    await page
      .getByRole('row', { name: /Health certificate/ })
      .getByRole('button', { name: 'View' })
      .click()
    await expect(page.getByRole('heading', { name: 'Health certificate', level: 1 })).toBeVisible()
    await page.getByRole('button', { name: 'Respond' }).click()
    await expect(page.getByRole('textbox', { name: 'Response' })).toBeVisible()
    await expect(page.getByLabel('Attach a document')).toBeAttached()

    // Back returns to the list inside Messages, not to a page that is gone.
    await page.getByRole('button', { name: 'All requirements' }).click()
    await expect(page.getByRole('navigation', { name: 'Messages sections' })).toBeVisible()
  })
})
