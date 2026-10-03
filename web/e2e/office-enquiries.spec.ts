import { expect, test, type Page } from '@playwright/test'

/*
 * ── One enquiry, every office ─────────────────────────────────────────────
 *
 * "Nasa iisang convo na lang uli ang mga general inquiry sa ibat ibang
 * offices, tas may choices na lang ulit don kung anong office" [client, 30
 * September 2026].
 *
 * The enquiry began as a single conversation with BPLO. When every office got
 * a front door it became a row each, which put six conversations on an
 * applicant's inbox — six titles, six dates, six previews — for what they
 * think of as one thing: asking the City a question. So the row is one again
 * and the office is a choice inside it, the shape a permit's conversation has.
 *
 * The rules underneath — who may read what, which offices are offered — are
 * proved in api/tests/Feature/OfficeInboxScopeTest.php, where the register can
 * be arranged. What only a browser can answer is whether an owner can find the
 * picker and get a message through it.
 */

function composerOn(page: Page) {
  return page.locator('textarea[placeholder^="Write to "]')
}

/**
 * The office the composer says it will reach.
 *
 * Read from the PLACEHOLDER rather than from the pill that was pressed: the
 * chosen pill spells the office out while the rest are codes, so the pill's
 * own text changes with which one is active. The placeholder is the product's
 * answer to "who am I writing to", which is the thing under test anyway.
 */
async function officeInComposer(page: Page): Promise<string> {
  const placeholder = await composerOn(page).getAttribute('placeholder')

  return (placeholder ?? '')
    .replace(/^Write to /, '')
    .replace(/[….]+$/, '')
    .trim()
}

function enquiryRow(page: Page) {
  return page
    .getByRole('list', { name: 'Conversations' })
    .getByRole('button')
    .filter({ hasText: 'General enquiry' })
}

test.describe('a business owner', () => {
  test.use({ storageState: 'e2e/.auth/default/owner.json' })

  test('has one enquiry row, not one per office', async ({ page }) => {
    await page.goto('/messages')

    await expect(enquiryRow(page)).toHaveCount(1, { timeout: 20000 })

    // And the section that briefly held a row per office is gone with them.
    await expect(page.getByRole('region', { name: 'Ask an office' })).toHaveCount(0)
  })

  test('picks the office inside the conversation', async ({ page }) => {
    await page.goto('/messages')
    await enquiryRow(page).click({ timeout: 20000 })

    /*
     * The picker, in the enquiry's own words. On a permit it asks which office
     * the message is ABOUT; an enquiry is not about anything yet, so it asks
     * who you are writing to.
     */
    const picker = page.getByRole('group', { name: /Which office are you asking/i })
    await expect(picker).toBeVisible({ timeout: 20000 })

    const pills = picker.getByRole('button')
    expect(await pills.count()).toBeGreaterThan(1)

    await expect(composerOn(page)).toBeVisible()
    const first = await officeInComposer(page)

    /*
     * Pressing a different office changes who the message goes to. Every
     * enquiry went to BPLO whatever the screen said until they could be
     * addressed — a question for the fire office arrived in BPLO's post, and
     * nothing on screen gave a sign of it.
     */
    const other = pills.filter({ hasNotText: first }).first()
    await other.click()
    await expect
      .poll(() => officeInComposer(page), { timeout: 20000 })
      .not.toBe(first)
  })

  test('sends to the office that is chosen, and clears the box', async ({ page }) => {
    await page.goto('/messages')
    await enquiryRow(page).click({ timeout: 20000 })
    await expect(composerOn(page)).toBeVisible({ timeout: 20000 })

    const office = await officeInComposer(page)
    const body = `Asking ${office} [${Date.now()}]`
    await composerOn(page).fill(body)

    /*
     * Pressed, not typed. Send was dead to the mouse on every conversation in
     * the product until recently — mousedown blurred the textarea, the focus
     * hint collapsed, the composer lost ~24px and the transcript grew into the
     * gap, carrying the button out from under the pointer before mouseup. The
     * specs all typed, so nothing caught it.
     */
    const send = page.getByRole('button', { name: /^Send/ })
    await expect(send).toBeEnabled()
    await send.click()

    await expect(page.getByText(body)).toBeVisible({ timeout: 20000 })
    await expect(composerOn(page)).toHaveValue('')
  })
})

test.describe('an office', () => {
  test.use({ storageState: 'e2e/.auth/default/bplo.json' })

  test('sees the permits assigned to it, said on or not', async ({ page }) => {
    /*
     * "Ang andon lang sa messages page nila ay kung ano ang mga naka assign na
     * business permit sa kanila" [client, 28 September 2026].
     *
     * A permit handed to this office appears whether or not anybody has
     * written about it — an officer given a file has to be able to write the
     * first word from the screen they read their mail on. The row says as much
     * rather than pretending to be a conversation.
     */
    await page.goto('/staff/messages')

    const rows = page.getByRole('list', { name: 'Conversations' }).getByRole('button')
    await expect(rows.first()).toBeVisible({ timeout: 20000 })

    await expect(page.getByText('Nothing said yet').first()).toBeVisible()
  })

  test('is offered no picker on an enquiry it may only read its own side of', async ({ page }) => {
    /*
     * The picker is a choice for the person who HAS one. An office seat may
     * read only its own conversation with an applicant, so offering it six
     * offices would be offering five it must be refused.
     */
    await page.goto('/staff/messages')

    // Wait for the list before counting: a count taken while the first fetch
    // is still in flight is always zero, and the skip below would then hide
    // the test rather than report it.
    const rows = page.getByRole('list', { name: 'Conversations' }).getByRole('button')
    await expect(rows.first()).toBeVisible({ timeout: 20000 })

    const enquiry = enquiryRow(page)
    test.skip((await enquiry.count()) === 0, 'nobody has written to this office yet')

    await enquiry.first().click()
    await expect(composerOn(page)).toBeVisible({ timeout: 20000 })
    await expect(page.getByRole('group', { name: /Which office/i })).toHaveCount(0)
  })
})

test.describe('an office’s two shelves', () => {
  test.use({ storageState: 'e2e/.auth/default/bplo.json' })

  /*
   * "Can you do another button para sa general inquiry" [client, 1 October
   * 2026].
   *
   * An office's Messages page is two lists that share a shape: the caseload it
   * works from, and the people it may hear from. They were merged, and the
   * second buried the first — BPLO's enquiry list is every registered account,
   * so on a register of any size the permits an officer is actually holding
   * would sit below a hundred citizens who have never written to anybody.
   */
  test('separates the caseload from the people', async ({ page }) => {
    await page.goto('/staff/messages')

    const rows = page.getByRole('list', { name: 'Conversations' }).getByRole('button')
    await expect(rows.first()).toBeVisible({ timeout: 20000 })

    // The permits shelf holds no enquiries…
    await expect(rows.filter({ hasText: 'General enquiry' })).toHaveCount(0)
    const permits = await rows.count()
    expect(permits).toBeGreaterThan(0)

    await page.getByRole('button', { name: 'General enquiries' }).click()

    // …and the enquiries shelf holds nothing else.
    await expect(rows.first()).toBeVisible({ timeout: 20000 })
    expect(await rows.count()).toBe(await rows.filter({ hasText: 'General enquiry' }).count())

    /*
     * The counts stop being joined by "of" on a shelf, because they stop being
     * comparable: one counts half the mail and the other counts all of it, and
     * "Showing 8 of 10" read as two permits missing rather than two enquiries
     * on the other shelf.
     */
    await expect(page.getByRole('status').filter({ hasText: 'Showing' })).toContainText(
      /general enquir(y|ies) · \d+ conversations? in all/,
    )
  })

  test('lists an owner who has never written, so the office can write first', async ({ page }) => {
    await page.goto('/staff/messages')
    await page.getByRole('button', { name: 'General enquiries' }).click()

    const rows = page.getByRole('list', { name: 'Conversations' }).getByRole('button')
    await expect(rows.first()).toBeVisible({ timeout: 20000 })

    /*
     * The row IS the way in. An office that can only answer what it has been
     * asked cannot start the conversation, and "matic na pag gumagawa ng
     * account may magrereflect na sa general inquiry ng BPLO" is precisely a
     * row for somebody who has said nothing yet.
     */
    const silent = rows.filter({ hasText: 'Nothing said yet' }).first()
    await expect(silent).toBeVisible()

    await silent.click()
    await expect(page.locator('textarea[placeholder^="Write to "]')).toBeVisible({ timeout: 20000 })
  })
})

test.describe('the administrator’s own shelf', () => {
  test.use({ storageState: 'e2e/.auth/default/bplo.json' })

  test('is reachable from any shelf, and says when something waits on it', async ({ page }) => {
    /*
     * The administrator's line was PINNED to the top of Messages, precisely so
     * an officer who cannot change their own details could always find where
     * to ask. It has its own button now [client, 1 October 2026], which serves
     * that better — a row always first is a row always in the way — but only
     * while the button itself cannot be missed.
     *
     * So: present on every shelf, and carrying a count when a reply is
     * waiting behind it. Without the count, moving the row off the default
     * shelf would answer the request by undoing the reason the row exists.
     */
    await page.goto('/staff/messages')

    const shelf = page.getByRole('button', { name: /^System Administrator/ })
    await expect(shelf).toBeVisible({ timeout: 20000 })

    for (const other of ['General enquiries', 'Permits']) {
      await page.getByRole('button', { name: new RegExp(`^${other}`) }).click()
      await expect(shelf).toBeVisible()
    }

    /*
     * The segment shows the ABBREVIATION and announces the full name: "Admin"
     * is only legible because two other segments sit beside it, and a screen
     * reader has no such context.
     *
     * The count is a badge when anything is unread and absent otherwise — a
     * permanent "0" beside every shelf teaches the eye to skip the number
     * that matters.
     */
    await expect(shelf).toHaveText(/^Admin\d*$/)
  })
})

test.describe('the shelves keep to themselves', () => {
  test.use({ storageState: 'e2e/.auth/default/bplo.json' })

  test('opens the enquiry that was pressed, not the first one in the list', async ({ page }) => {
    /*
     * ---- One key for many rows ------------------------------------------
     *
     * An applicant has one enquiry row, so its key was the bare word
     * 'general'. An office has one per OWNER, and they were all given that
     * same word, which broke two things at once:
     *
     *  - `selected` is a `find` on the key, so pressing one owner opened
     *    whichever the list happened to hold first;
     *  - React had duplicate keys in one list, so switching shelves left an
     *    orphan behind — the Administrator shelf showed a general enquiry
     *    above the one conversation it holds, while the count beside it
     *    correctly said one.
     */
    await page.goto('/staff/messages')
    await page.getByRole('button', { name: /^General enquiries/ }).click()

    const rows = page.getByRole('main').getByRole('listitem')
    await expect(rows.first()).toBeVisible({ timeout: 20000 })
    test.skip((await rows.count()) < 2, 'needs two owners to tell apart')

    /*
     * Compared rather than parsed. The claim is that pressing a row opens THAT
     * row, and the honest way to check it is to open two and see that they
     * differ - reading a name out of the row's text depends on where the
     * avatar, the wrap and the standing chip put it, none of which is the
     * product under test.
     */
    /*
     * The PANE's own label, not the composer's placeholder: an officer's
     * composer reads "Write to the applicant" on every conversation, because
     * that is the officer's generic word for whoever is on the other side. The
     * pane is labelled after the person.
     */
    const pane = page.locator('section[aria-label^="Messages about"]')

    await rows.nth(0).click()
    await expect(pane).toBeVisible({ timeout: 20000 })
    const first = await pane.getAttribute('aria-label')

    await rows.nth(1).click()
    await expect(pane).toBeVisible({ timeout: 20000 })

    await expect.poll(() => pane.getAttribute('aria-label'), { timeout: 20000 }).not.toBe(first)
  })

  test('shows the administrator’s shelf and nothing else on it', async ({ page }) => {
    await page.goto('/staff/messages')

    // Open an enquiry first: the orphan only appeared on the way BACK.
    await page.getByRole('button', { name: /^General enquiries/ }).click()
    const rows = page.getByRole('main').getByRole('listitem')
    await expect(rows.first()).toBeVisible({ timeout: 20000 })
    await rows.first().click()

    await page.getByRole('button', { name: /^System Administrator/ }).click()
    await expect(rows.first()).toBeVisible({ timeout: 20000 })

    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('Your account and details')
    await expect(rows.filter({ hasText: 'General enquiry' })).toHaveCount(0)

    /*
     * And the two halves agree. Changing shelf lets go of the conversation, so
     * the pane cannot be left holding a row the list no longer shows.
     */
    await expect(page.getByRole('main')).not.toContainText('General enquiry')
  })
})

test.describe('the shelf badges', () => {
  test.use({ storageState: 'e2e/.auth/default/bplo.json' })

  /** The number on a shelf button, or null when it carries none. */
  async function badgeOn(page: import('@playwright/test').Page, label: RegExp) {
    const text = (await page.getByRole('button', { name: label }).textContent()) ?? ''

    return (text.match(/(\d+)$/) ?? [])[1] ?? null
  }

  test('never nags about the shelf already on screen', async ({ page }) => {
    /*
     * "Something is waiting over there" is the whole of what the badge says,
     * so saying it about the list already open is noise — and the rows below
     * are carrying their own unread marks while it does.
     */
    await page.goto('/staff/messages')

    const rows = page.getByRole('main').getByRole('listitem')
    await expect(rows.first()).toBeVisible({ timeout: 20000 })

    expect(await badgeOn(page, /^Permits/)).toBeNull()

    await page.getByRole('button', { name: /^General enquiries/ }).click()
    await expect(rows.first()).toBeVisible({ timeout: 20000 })
    expect(await badgeOn(page, /^General enquiries/)).toBeNull()
  })

  test('drops a shelf’s badge once it has been looked at', async ({ page }) => {
    /*
     * "Once clicked mawawala na dapat" [client, 1 October 2026].
     *
     * The badge is not a second copy of the unread count — the rows carry that
     * and so does the rail. It answers one narrower question: is there
     * something over there I have not looked at? Pressing the shelf answers
     * it.
     *
     * Skipped when the register happens to have nothing unread on another
     * shelf: this asserts what the badge does when there IS one, and inventing
     * the state would mean stubbing the whole inbox payload.
     */
    await page.goto('/staff/messages')

    const rows = page.getByRole('main').getByRole('listitem')
    await expect(rows.first()).toBeVisible({ timeout: 20000 })

    const other = [/^General enquiries/, /^System Administrator/]
    const carrying = []
    for (const label of other) {
      if ((await badgeOn(page, label)) !== null) carrying.push(label)
    }

    test.skip(carrying.length === 0, 'nothing unread on another shelf to clear')

    const label = carrying[0]
    await page.getByRole('button', { name: label }).click()
    await expect(rows.first()).toBeVisible({ timeout: 20000 })

    await expect.poll(() => badgeOn(page, label), { timeout: 20000 }).toBeNull()
  })
})
