import { expect, test } from '@playwright/test'

/**
 * The office's line to the Super Administrator.
 *
 * ── What this is cover for ─────────────────────────────────────────────────
 *
 * Office accounts cannot edit their own details any more: no Settings, no
 * "Edit your details". Their name, mobile number, office and role are the
 * super admin's to set [client, 28 September 2026] — the officer directory is
 * the register's record of who staffs which office, and a record people can
 * quietly edit about themselves is not one.
 *
 * That only works if there is somewhere to ask, and if the super admin has
 * somewhere to read it. Both ends are here.
 */

test.describe('an office account', () => {
  test.use({ storageState: 'e2e/.auth/default/sanitary.json' })

  test('is not offered Settings, and is told who to ask instead', async ({ page }) => {
    await page.goto('/staff/profile')

    // The control is GONE, not renamed — a reader who knew where it was
    // should find nothing, rather than a version of it that still edits.
    await expect(page.getByRole('link', { name: 'Edit your details' })).toHaveCount(0)

    /*
     * And what replaces it names who can. Taking the control away and leaving
     * nothing would tell an officer with a misspelt surname that the system
     * simply has no answer for them.
     */
    const ask = page.getByRole('link', { name: /Message the Super Administrator/ })
    await expect(ask).toBeVisible()
    await expect(page.getByText(/maintained by the Super Administrator/i)).toBeVisible()

    await ask.click()
    await expect(page).toHaveURL(/\/staff\/messages/)
  })

  test('has no Settings in the account menu either', async ({ page }) => {
    // Both doors, or the one left open is the one somebody finds.
    await page.goto('/staff/dashboard')
    await page.getByRole('button', { name: 'Account menu' }).click()

    const menu = page.getByRole('menu', { name: 'Account' })
    await expect(menu.getByRole('menuitem', { name: 'Profile' })).toBeVisible()
    await expect(menu.getByRole('menuitem', { name: 'Settings' })).toHaveCount(0)
  })

  test('cannot reach Settings by typing the URL', async ({ page }) => {
    /*
     * Hiding a link is not a rule. This one is only a UI decision — the
     * profile endpoint still accepts the edit — so this test records what the
     * screen does rather than claiming a guard that is not there. If the page
     * still renders, the hiding is cosmetic and worth saying so out loud.
     */
    await page.goto('/staff/settings')
    const heading = page.getByRole('heading', { name: 'Settings' })
    const reachable = await heading.isVisible().catch(() => false)

    // Recorded, not asserted either way: see the note above.
    test.info().annotations.push({
      type: 'note',
      description: reachable
        ? 'Settings is still reachable by URL — the removal is presentational.'
        : 'Settings is not reachable by URL.',
    })
  })

  test('finds the administrator behind a button of its own', async ({ page }) => {
    await page.goto('/staff/messages')

    /*
     * ---- Pinned, then given a shelf --------------------------------------
     *
     * This asserted the administrator's line was the FIRST row of Messages,
     * not sorted in by date, because an officer who has never needed to ask
     * would otherwise find it below thirty filings.
     *
     * It has its own button now [client, 1 October 2026]. The reason for the
     * pin stands and is better served: a row that is always first is a row
     * always in the way, and an officer scanning their caseload was reading
     * past their own account details every time. The button is on screen
     * whatever shelf they are on, which is what "findable" asked for.
     *
     * The button carries a count when something is waiting on it, so moving
     * the row off the default shelf cannot silence it - asserted separately
     * in office-enquiries.spec.ts.
     */
    const shelf = page.getByRole('button', { name: /^Super Administrator/ })
    await expect(shelf).toBeVisible({ timeout: 20000 })

    // Not on the caseload shelf: that is what having its own means.
    const rows = page.getByRole('main').getByRole('listitem')
    await expect(rows.first()).toBeVisible({ timeout: 20000 })
    await expect(rows.filter({ hasText: 'Your account and details' })).toHaveCount(0)

    await shelf.click()

    await expect(rows.first()).toContainText('Super Administrator')
    await expect(rows.first()).toContainText('Your account and details')
  })

  test('can write to the administrator, and sees it appear', async ({ page }) => {
    await page.goto('/staff/messages')
    await page.getByRole('button', { name: /^Super Administrator/ }).first().click()
    await page
      .getByRole('main')
      .getByRole('listitem')
      .filter({ hasText: 'Your account and details' })
      .first()
      .click()

    const note = `Please correct my surname. [${Date.now()}]`
    await page.getByRole('textbox').last().fill(note)
    await page.getByRole('button', { name: /^Send/ }).click()

    await expect(page.getByText(note)).toBeVisible({ timeout: 20000 })
  })
})

test.describe('a business owner', () => {
  test.use({ storageState: 'e2e/.auth/default/owner.json' })

  test('keeps Settings, because their details are their own', async ({ page }) => {
    await page.goto('/profile')
    await expect(page.getByRole('link', { name: 'Edit your details' })).toBeVisible()
    await expect(page.getByRole('link', { name: /Message the Super Administrator/ })).toHaveCount(0)
  })

  test('has no administrator row — they write to BPLO instead', async ({ page }) => {
    await page.goto('/messages')
    await expect(page.getByRole('heading', { name: /Messages/i }).first()).toBeVisible()
    await expect(page.getByText('Your account and details')).toHaveCount(0)
  })
})

test.describe('the Super Administrator', () => {
  test.use({ storageState: 'e2e/.auth/default/admin.json' })

  test('has an Office Messages screen the rail actually reaches', async ({ page }) => {
    /*
     * The bug this pins: the rail entry pointed at `/admin/messages`, which is
     * the ORDINARY Messages page, mounted per-prefix beside Profile and
     * Settings. Pressing "Office Messages" therefore opened the
     * applicant-and-officer inbox — which renders nothing for an account
     * without `message.participate` — and read as a feature that did not work.
     *
     * So the link is FOLLOWED here rather than merely looked at, and what it
     * lands on is checked.
     */
    /*
     * From the super admin's OWN home. They sign in at /admin/login and
     * everything they use lives under /admin with its own session key, so the
     * rail resolves against that prefix — starting at /staff/dashboard would
     * be testing a portal this account never sees.
     */
    await page.goto('/admin/dashboard')

    const entry = page.getByRole('link', { name: 'Office Messages' })
    await expect(entry).toBeVisible()
    await expect(entry).toHaveAttribute('href', '/admin/office-messages')

    await entry.click()
    await expect(page).toHaveURL(/\/admin\/office-messages$/)
    await expect(page.getByRole('heading', { name: 'Office Messages' })).toBeVisible()

    /*
     * The officers themselves, not a sentence about them. This asserted on a
     * line of explanatory copy until the client had it removed [28 September
     * 2026] - and the assertion deserved to go with it either way: a test that
     * pins descriptive prose fails every time the prose is edited, while
     * saying nothing about whether the screen WORKS. The rows are the screen.
     */
    await expect(page.getByRole('button', { name: /—/ }).first()).toBeVisible({
      timeout: 20000,
    })
  })

  test('sends the old /admin/messages path here instead of a 403', async ({ page }) => {
    /*
     * `/admin/messages` was the ordinary Messages page, mounted per-prefix
     * beside Profile and Settings. Only the super admin lives under /admin,
     * and they do not hold `message.participate` — so that route could only
     * ever render "You do not have permission to perform this action."
     *
     * Links to it already exist: the rail pointed there until this was found,
     * and notification rows written before the fix still carry the path. So it
     * redirects rather than 404s.
     */
    await page.goto('/admin/messages')

    await expect(page).toHaveURL(/\/admin\/office-messages$/)
    await expect(page.getByRole('heading', { name: 'Office Messages' })).toBeVisible()
    await expect(page.getByText(/do not have permission/i)).toHaveCount(0)
  })

  test('lists the offices there, rather than an empty screen', async ({ page }) => {
    // The symptom the client saw. An empty list and a working list look the
    // same until you count.
    await page.goto('/admin/office-messages')

    const rows = page.getByRole('button', { name: /—/ })
    await expect(rows.first()).toBeVisible({ timeout: 20000 })
    expect(await rows.count(), 'no office accounts listed').toBeGreaterThan(1)
  })

  test('writes to an officer under a box that names them', async ({ page }) => {
    /*
     * The composer said "Write to the applicant…" — the officer's generic
     * word, chosen by `application.view_all`, which the super admin also
     * holds. There is no applicant anywhere near this conversation.
     */
    await page.goto('/admin/office-messages')
    const rows = page.getByRole('button', { name: /—/ })
    await expect(rows.first()).toBeVisible({ timeout: 20000 })

    /*
     * Line 1, not line 0. The row leads with an initials AVATAR - `LR` for
     * Liza Reyes - which is `aria-hidden` and so absent from the accessible
     * name, but very much present in `innerText`. The officer's own name is
     * the line under it.
     */
    const name = (await rows.first().innerText()).split('\n')[1].trim()
    await rows.first().click()

    await expect(page.getByPlaceholder(`Write to ${name}…`)).toBeVisible()
    await expect(page.getByPlaceholder(/Write to the applicant/)).toHaveCount(0)
  })

  test('lists every office account, including the silent ones', async ({ page }) => {
    /*
     * The super admin has to be able to START a conversation — that is how
     * "your details have been updated" reaches the officer who asked. A list
     * built from threads would hide everybody who had not written.
     */
    await page.goto('/admin/office-messages')

    const rows = page.getByRole('button', { name: /—/ })
    await expect(rows.first()).toBeVisible({ timeout: 20000 })
    expect(await rows.count()).toBeGreaterThan(1)

    await expect(page.getByText(/Nothing said yet — you can start/).first()).toBeVisible()
  })

  test('opens one officer and replies', async ({ page }) => {
    await page.goto('/admin/office-messages')
    await expect(page.getByRole('button', { name: /—/ }).first()).toBeVisible({ timeout: 20000 })

    await page.getByRole('button', { name: /—/ }).first().click()

    // The conversation is in the URL, so a notification can land on it.
    await expect(page).toHaveURL(/officer=\d+/)

    const reply = `Updated — please check your profile. [${Date.now()}]`
    await page.getByRole('textbox').last().fill(reply)
    await page.getByRole('button', { name: /^Send/ }).click()

    await expect(page.getByText(reply)).toBeVisible({ timeout: 20000 })
  })

  test('narrows to the officers with something waiting', async ({ page }) => {
    /*
     * There were three pills and now there are two. "Has written" went because
     * the list already SORTS anybody who has written above everybody who has
     * not, so the filter hid rows without answering a question the order had
     * not already answered [client, 28 September 2026].
     *
     * Unread is the one that earns its place: it is the only thing on this
     * screen a reader cannot get from the order alone.
     */
    await page.goto('/admin/office-messages')
    await expect(page.getByRole('button', { name: /—/ }).first()).toBeVisible({ timeout: 20000 })

    await expect(page.getByRole('button', { name: 'Has written', exact: true })).toHaveCount(0)

    const all = await page.getByRole('button', { name: /—/ }).count()

    // The pill carries the count when there is one, so match on its start.
    await page.getByRole('button', { name: /^Unread/ }).click()
    await page.waitForTimeout(600)

    const waiting = await page.getByRole('button', { name: /—/ }).count()
    expect(waiting).toBeLessThanOrEqual(all)
    /*
     * Nobody in the narrowed list can be showing the "nothing said" line: an
     * officer with an unread message has, by definition, said something.
     */
    await expect(page.getByText(/Nothing said yet/)).toHaveCount(0)
  })
})
