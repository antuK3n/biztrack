import { expect, test, type Page } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * One officer's caseload, on a page of its own.
 *
 * Reassign was a modal on Officer Assignment and is now a screen in the
 * Officer in Charge format, scoped to whichever officer was clicked [client,
 * 27 September 2026]. These tests are about the three things that change when
 * a dialog becomes a page:
 *
 *   1. the row has to NAVIGATE, and the page has to have an address;
 *   2. the page has to say whose caseload it is and offer a way back;
 *   3. the two acts on it — empty this desk, fill this desk — have to still
 *      reach the server.
 *
 * Stubbed, because the point is the screen. The endpoints themselves are
 * covered by OfficerAssignmentTest on the API side, which is where the
 * question "does the register actually change" belongs.
 */

test.use({ storageState: sessionFor('admin') })

const OFFICER = 42

const CASELOAD = {
  user: { id: OFFICER, name: 'Liza Reyes' },
  department: { id: 1, code: 'BPLO', name: 'Business Permits and Licensing Office' },
  open_reviews: 2,
  open_inspections: 0,
  total: 2,
  finished_reviews: 4,
  cases: [
    {
      kind: 'review',
      id: 501,
      application_id: 301,
      tracking_id: 'BIZ-2026-00002',
      business: 'RxCare Pharmacy',
      office: { code: 'BPLO', name: 'Business Permits and Licensing Office' },
      permit: "Mayor's / Business Permit",
      status_label: 'Completed',
      /*
       * The office is done; the FILING is not. This is the pair that prompted
       * the split — a row reading Completed that still has to be reassigned.
       */
      application_status_label: 'Permit Released',
      at: '2026-09-24T09:57:00.000000Z',
    },
    {
      kind: 'review',
      id: 502,
      application_id: 302,
      tracking_id: 'BIZ-2026-00009',
      business: 'Aling Nena Sari-Sari Store',
      office: { code: 'BPLO', name: 'Business Permits and Licensing Office' },
      permit: "Mayor's / Business Permit",
      status_label: 'Pending',
      application_status_label: 'For Approval',
      at: '2026-09-24T11:35:00.000000Z',
    },
  ],
  unassigned: [
    {
      kind: 'review',
      id: 777,
      application_id: 303,
      tracking_id: 'BIZ-2026-00011',
      business: 'Malabon Hardware',
      office: { code: 'BPLO', name: 'Business Permits and Licensing Office' },
      permit: "Mayor's / Business Permit",
      status_label: 'Pending',
      application_status_label: 'For Approval',
      at: '2026-09-20T02:00:00.000000Z',
    },
  ],
  candidates: [{ id: 43, name: 'Marites Santos', email: 'bplo.two@biztrack.local', open_total: 1 }],
}

/** Every request the page sent, so an act can be pinned to the server. */
let sent: { url: string; body: unknown }[]

const stub = async (page: Page, caseload: Record<string, unknown> = CASELOAD) => {
  sent = []

  await page.route(`**/api/v1/admin/users/${OFFICER}/caseload*`, (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: caseload }) }),
  )

  await page.route(`**/api/v1/admin/users/${OFFICER}/reassign-caseload`, async (route) => {
    sent.push({ url: route.request().url(), body: route.request().postDataJSON() })
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { moved_reviews: 1, moved_inspections: 0, total: 1, to: null } }),
    })
  })

  await page.route(`**/api/v1/admin/users/${OFFICER}/take-cases`, async (route) => {
    sent.push({ url: route.request().url(), body: route.request().postDataJSON() })
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { total: 1, to: { id: OFFICER, name: 'Liza Reyes' } } }),
    })
  })

  await page.goto(`/admin/users/${OFFICER}/reassign`)
  await expect(page.getByRole('heading', { name: 'Liza Reyes’s caseload', level: 1 })).toBeVisible()
}

test.describe('an officer’s caseload page', () => {
  test('the page says whose it is, which office, and offers a way back', async ({ page }) => {
    await stub(page)

    /*
     * The SUBTITLE, not the office name anywhere on the page: every row carries
     * it in its Office column too, so a bare text match resolves to four
     * elements and fails strict mode. The one that matters is the line under
     * the title, which is where the page states whose desk this is.
     *
     * It carries the disagreement with the Officer in Charge register in the
     * same breath: that screen lists every assignment a name is on, finished
     * ones included; this one can only move what is still open, and an admin
     * reading two different numbers is reading two true sentences.
     */
    await expect(
      page.getByText(/^Business Permits and Licensing Office · also named on 4 finished reviews, which stay$/),
    ).toBeVisible()

    /*
     * A destination page needs a way back that does not depend on the browser's
     * own button — a reader who arrived by a pasted link has no history.
     */
    /*
     * The page's OWN back link, by its arrow: the sidebar carries a nav link
     * to the same screen, so a bare name match resolves to two.
     *
     * The href is asserted rather than just the presence. It pointed at
     * `…/users/:userId` — one segment up from `…/users/:userId/reassign`,
     * which is not a route — and a link that is merely visible would have
     * passed that.
     */
    /*
     * ONE way back, and it is a link.
     *
     * There were two — a pill in the header and a `navigate(-1)` button at the
     * foot — which is two controls for one act, doing different things: the
     * footer one walked the browser's HISTORY, so a reader who arrived by a
     * pasted link went somewhere else. The count is asserted, not just the
     * presence, because "there is a way back" was true of the broken version.
     *
     * The href is asserted too. It pointed at `…/users/:userId`, one segment
     * up from `…/users/:userId/reassign`, which is not a route — and a link
     * that is merely visible would have passed that.
     */
    const back = page.getByRole('link', { name: 'Back to Officer Assignment' })
    await expect(back).toHaveCount(1)
    await expect(back).toBeVisible()
    await expect(back).toHaveAttribute('href', '/admin/users')

    // And it sits ABOVE the title, where this app already puts a back link.
    const order = await page.evaluate(() => {
      const link = [...document.querySelectorAll('a')].find((a) =>
        a.textContent?.includes('Back to Officer Assignment'),
      )
      const title = document.querySelector('h1')
      if (!link || !title) return 'missing'
      return link.compareDocumentPosition(title) & Node.DOCUMENT_POSITION_FOLLOWING
        ? 'link first'
        : 'title first'
    })
    expect(order).toBe('link first')
  })

  test('the tables are the Officer in Charge columns, per list', async ({ page }) => {
    await stub(page)

    await expect(page.getByRole('heading', { name: 'Work Liza Reyes is holding' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Unassigned in BPLO' })).toBeVisible()

    const held = page.locator('table').first()
    /*
     * "Review step" and "Filing", not one "Status".
     *
     * They carry the same words and mean different things: the office's own
     * progress, and where the whole application has got to. One column showed
     * the step, so an administrator saw "Completed" on a row they were being
     * asked to move — the client's question, 27 September 2026.
     */
    for (const column of ['Business', 'Tracking ID', 'Office', 'Permit', 'Assigned', 'Review step', 'Filing']) {
      await expect(held.getByRole('columnheader', { name: column, exact: true })).toBeVisible()
    }

    // And they show different things on the row that prompted the split.
    const row = held.locator('tbody tr', { hasText: 'RxCare Pharmacy' })
    await expect(row).toContainText('Completed')
    await expect(row).toContainText('Permit Released')
    await expect(held.locator('tbody tr')).toHaveCount(2)

    // The unheld table dates the WAIT rather than an assignment it never had.
    await expect(
      page.locator('table').last().getByRole('columnheader', { name: 'Waiting since', exact: true }),
    ).toBeVisible()
  })

  test('a finished review is still held, and the screen says why', async ({ page }) => {
    /*
     * A case is held while the FILING is live, not while one step is open —
     * the officer-in-charge rule. So a row reads "Completed" under a heading
     * saying the officer is holding it, which looks like a contradiction and
     * is not. The sentence is the whole point of this test.
     */
    await stub(page)

    await expect(
      page.getByText(/A filing stays with its officer in charge until the whole filing is decided/),
    ).toBeVisible()

    // And the colour agrees with the word rather than fighting it: the table
    // was drawn entirely in the in-progress tint, so Completed came out amber.
    const completed = page.locator('tbody tr', { hasText: 'RxCare Pharmacy' }).getByText('Completed')
    await expect(completed).toBeVisible()
    await expect(completed).toHaveClass(/green/)
  })

  test('nothing can be moved until a filing and a reason are both given', async ({ page }) => {
    await stub(page)

    const move = page.getByRole('button', { name: /^Release|^Move/ })

    /*
     * aria-disabled, never the native attribute (AGENTS.md §6.2). A disabled
     * button leaves the tab order, so a keyboard reader cannot reach the
     * control to find out why it will not act — and the reason is beside it.
     */
    await expect(move).toHaveAttribute('aria-disabled', 'true')
    expect(await move.evaluate((el) => el.hasAttribute('disabled'))).toBe(false)
    expect(await move.evaluate((el) => (el as HTMLElement).tabIndex)).toBe(0)
    /*
     * Scoped to the fieldset. Both acts on this page carry the same sentence —
     * each says why its OWN button will not fire — so a bare text match
     * resolves to two and fails strict mode. Which is the right shape: the
     * hint belongs to the control it sits under, not to the page.
     */
    const moveBlock = page.getByRole('group', { name: 'Move the selected filings' })
    await expect(moveBlock.getByText('Tick at least one filing above.')).toBeVisible()

    await page.getByRole('checkbox', { name: /Select RxCare Pharmacy/ }).check()
    // Still refused, and the sentence now names the OTHER thing that is missing.
    await expect(move).toHaveAttribute('aria-disabled', 'true')
    await expect(moveBlock.getByText('Give a reason first.')).toBeVisible()

    await page.getByPlaceholder('e.g. Officer on extended leave').fill('Officer on leave')
    await expect(move).not.toHaveAttribute('aria-disabled', 'true')
  })

  test('nothing is ticked on arrival', async ({ page }) => {
    /*
     * The dialog this replaces pre-ticked every held row, because its default
     * act was the sweep. On a page that makes the destructive reading the
     * accidental one: a reader who lands here to LOOK would be one press from
     * moving a caseload. "Select all" is a control of its own instead.
     */
    await stub(page)

    for (const box of await page.getByRole('checkbox', { name: /^Select / }).all()) {
      await expect(box).not.toBeChecked()
    }

    const all = page.locator('table').first().getByRole('checkbox', { name: 'All' })
    await all.check()
    await expect(page.getByText('2 of 2 selected')).toBeVisible()
  })

  test('search narrows both lists, by business and by tracking ID', async ({ page }) => {
    /*
     * The controls the Officer in Charge screen carries, in the same place —
     * this page is that screen scoped to one officer, so a reader moving
     * between the two should find them where they left them.
     */
    await stub(page)

    const held = page.locator('table').first().locator('tbody tr')
    await expect(held).toHaveCount(2)

    const search = page.getByRole('searchbox')
    await search.fill('RxCare')
    await expect(held).toHaveCount(1)
    // The count names BOTH numbers, so a short table is never mistaken for a
    // short caseload.
    await expect(page.getByText('1 of 2 filings')).toBeVisible()

    // The tracking ID, which is the other thing a filing is quoted by — and
    // the label says so, because a box that matches one of two quietly makes a
    // correct query look like missing data.
    await search.fill('BIZ-2026-00009')
    await expect(held).toHaveCount(1)
    await expect(held.first()).toContainText('Aling Nena Sari-Sari Store')

    await expect(search).toHaveAttribute(
      'aria-label',
      'Search Liza Reyes’s caseload by business or tracking ID',
    )
  })

  test('a search that matches nothing does not claim the officer holds nothing', async ({ page }) => {
    /*
     * The defect this is here for, found by a driver probe: the empty state
     * said "Liza Reyes is not holding any open work" over a caseload of two.
     * That is a false statement ABOUT THE OFFICER, and it points the reader at
     * the wrong thing to fix.
     */
    await stub(page)

    await page.getByRole('searchbox').fill('zzzz-no-such-filing')

    await expect(page.getByText('No filing here matches')).toBeVisible()
    await expect(page.getByText(/Liza Reyes is holding 2 filings, and none of them matches/)).toBeVisible()
    await expect(page.getByText('Liza Reyes is not holding any open work')).toHaveCount(0)
  })

  test('the sort menu names orderings, and reorders the rows', async ({ page }) => {
    await stub(page)

    const first = () => page.locator('table').first().locator('tbody tr').first()

    // Longest held first is the default AND what the server already returns,
    // so it describes an untouched list rather than a choice made on arrival.
    await expect(first()).toContainText('RxCare Pharmacy')

    await page.getByRole('button', { name: /^Sort/ }).click()
    await page.getByRole('option', { name: 'Most recently assigned' }).click()
    await expect(first()).toContainText('Aling Nena Sari-Sari Store')

    await page.getByRole('button', { name: /^Sort/ }).click()
    await page.getByRole('option', { name: 'Business (A–Z)' }).click()
    await expect(first()).toContainText('Aling Nena Sari-Sari Store')
  })

  test('the status filter narrows, and says when it has emptied the table', async ({ page }) => {
    await stub(page)

    await page.getByRole('button', { name: /^Filter/ }).click()
    await page.getByRole('option', { name: 'Completed', exact: true }).click()

    const held = page.locator('table').first().locator('tbody tr')
    await expect(held).toHaveCount(1)
    await expect(held.first()).toContainText('RxCare Pharmacy')

    await page.getByRole('button', { name: /^Filter/ }).click()
    await page.getByRole('option', { name: 'Returned', exact: true }).click()
    await expect(page.getByText('No filing here matches')).toBeVisible()
  })

  test('the kind filter is offered only when the rows differ by kind', async ({ page }) => {
    /*
     * A control that cannot narrow anything reads as broken the first time
     * somebody uses it: an officer holding five reviews and no inspections who
     * picks "Site inspections" gets an empty screen and no explanation. Most
     * offices only ever hold one kind.
     */
    await stub(page)
    await page.getByRole('button', { name: /^Filter/ }).click()
    await expect(
      page.locator('.shadow-overlay label').filter({ hasText: 'Kind' }),
    ).toHaveCount(0)
    await page.keyboard.press('Escape')

    // Give this officer one of each, and the control appears.
    await stub(page, {
      ...CASELOAD,
      cases: [
        CASELOAD.cases[0],
        { ...CASELOAD.cases[1], kind: 'inspection', permit: null, status_label: 'Scheduled' },
      ],
    })
    await page.getByRole('button', { name: /^Filter/ }).click()
    const kind = page.locator('.shadow-overlay label').filter({ hasText: 'Kind' }).locator('select')
    await expect(kind).toBeVisible()

    await kind.selectOption('inspection')
    await expect(page.locator('table').first().locator('tbody tr')).toHaveCount(1)
  })

  test('the screen says where the narrowing runs', async ({ page }) => {
    /*
     * The Officer in Charge screen this page is modelled on searches on the
     * SERVER. This endpoint answers with the whole caseload, so the filtering
     * is local — and saying so is what stops a reader assuming the two behave
     * alike and mistrusting a short list.
     */
    await stub(page)

    await expect(page.getByText(/loaded in full/)).toHaveCount(0)
    await page.getByRole('searchbox').fill('RxCare')
    await expect(page.getByText(/Searching and filtering this officer’s own caseload, which is loaded in full/)).toBeVisible()
  })

  test('the move names the rows and the reason, and asks the server', async ({ page }) => {
    await stub(page)

    await page.getByRole('checkbox', { name: /Select RxCare Pharmacy/ }).check()
    await page.getByPlaceholder('e.g. Officer on extended leave').fill('Officer on leave')
    await page.getByRole('button', { name: /^Release/ }).click()

    /*
     * The press opens a confirmation now; the request goes on Confirm. The
     * dialog's own cover is in confirm-dialogs.spec.ts - this only has to walk
     * through it to reach the act it was written to test.
     */
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Release to the queue' })
      .click()

    await expect.poll(() => sent.length).toBeGreaterThan(0)
    expect(sent[0].url).toContain('reassign-caseload')
    expect(sent[0].body).toMatchObject({
      to_user_id: null,
      cases: [{ kind: 'review', id: 501 }],
      reason: 'Officer on leave',
    })

    // What happened, said where a screen reader will hear it.
    await expect(page.getByText('1 filing is now with the BPLO queue.')).toBeVisible()
  })

  test('naming a colleague moves it to them rather than to the queue', async ({ page }) => {
    await stub(page)

    await page.getByRole('checkbox', { name: /Select RxCare Pharmacy/ }).check()
    await page.getByLabel('Move to').selectOption('43')
    await page.getByPlaceholder('e.g. Officer on extended leave').fill('Load balancing')

    // The button says the destination, so the act is read before it is pressed.
    const move = page.getByRole('button', { name: /Move .*to Marites Santos/ })
    await expect(move).toBeVisible()
    await move.click()

    // Through the confirmation, which repeats the destination on its button.
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Move to Marites Santos' })
      .click()

    await expect.poll(() => sent.length).toBeGreaterThan(0)
    expect(sent[0].body).toMatchObject({ to_user_id: 43 })
  })

  test('the office’s unheld work can be handed to this officer', async ({ page }) => {
    /*
     * The other direction, and the reason this is a page: two opposite acts,
     * each with its own list, selection and button. One confirm could not carry
     * both destinations.
     */
    await stub(page)

    const take = page.getByRole('button', { name: /^Assign .*to Liza Reyes/ })
    await expect(take).toHaveAttribute('aria-disabled', 'true')

    await page.getByRole('checkbox', { name: /Select Malabon Hardware/ }).check()
    await expect(take).not.toHaveAttribute('aria-disabled', 'true')
    await take.click()

    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Assign to Liza Reyes' })
      .click()

    await expect.poll(() => sent.length).toBeGreaterThan(0)
    expect(sent[0].url).toContain('take-cases')
    expect(sent[0].body).toMatchObject({ cases: [{ kind: 'review', id: 777 }] })
  })

  test('an officer holding nothing is told so, in words', async ({ page }) => {
    await stub(page, { ...CASELOAD, cases: [], unassigned: [], total: 0, open_reviews: 0 })

    await expect(page.getByText('Liza Reyes is not holding any open work')).toBeVisible()
    // And no act is offered, rather than one that could only fail.
    await expect(page.getByRole('button', { name: /^Release|^Move/ })).toHaveCount(0)
  })

  test('every row checkbox names the filing it selects', async ({ page }) => {
    /*
     * Twelve checkboxes reading "select" are twelve identical stops for a
     * screen reader (AGENTS.md §6.2). The distinction lives in the accessible
     * name, because the visible control is a box.
     */
    await stub(page)

    await expect(
      page.getByRole('checkbox', { name: 'Select RxCare Pharmacy, BIZ-2026-00002' }),
    ).toBeVisible()
    await expect(
      page.getByRole('checkbox', { name: 'Select Malabon Hardware, BIZ-2026-00011' }),
    ).toBeVisible()
  })
})

test.describe('reaching the page from Officer Assignment', () => {
  test('Reassign is a link to the officer’s own caseload', async ({ page }) => {
    /*
     * It was a button that opened a dialog. As a link the page has an address
     * an admin can send to a colleague, and Back means what it says.
     */
    await page.route('**/api/v1/admin/users?*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            {
              id: OFFICER,
              first_name: 'Liza',
              middle_name: null,
              last_name: 'Reyes',
              suffix: null,
              gender: 'F',
              email: 'bplo@biztrack.local',
              mobile_number: '09171234567',
              department: { id: 1, code: 'BPLO', name: 'Business Permits and Licensing Office' },
              is_active: true,
              has_photo: false,
              email_verified_at: null,
              roles: ['bplo_staff'],
              permissions: [],
              last_active_at: null,
            },
          ],
          meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
        }),
      })
    })

    await page.goto('/admin/users')
    await expect(page.locator('tbody tr').first()).toBeVisible({ timeout: 30_000 })

    const link = page.getByRole('link', { name: 'Reassign Liza Reyes’s caseload' })
    await expect(link).toBeVisible()
    // Named by the officer: twenty rows of "Reassign" are twenty identical
    // stops for a screen reader.
    await expect(link).toHaveAttribute('href', `/admin/users/${OFFICER}/reassign`)
  })
})
