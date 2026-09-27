import { expect, test } from '@playwright/test'

/**
 * Owner Status: the sanctions screen.
 *
 * ── What changed and why these exist ───────────────────────────────────────
 *
 * Three things, all from 27 September 2026:
 *
 *  1. Transfer Ownership is gone from this page. It moved a business to
 *     another account, which is an amendment being carried out, not a
 *     sanction — and it sat one button away from the control that bars an
 *     owner from trading.
 *  2. A blacklisting is a finding against the PERSON. It bars every business
 *     they hold, so the dialog has to say that before it is pressed, and the
 *     roster has to show it afterwards.
 *  3. Nothing here writes on a single press any more.
 *
 * These run against the throwaway stack, and the blacklisting ones WRITE to
 * it — that is the only way to see the cascade, which is the whole point. Each
 * one puts the register back the way it found it.
 */

const CHANGE = '**/api/**/status'

/**
 * Open Change Status on a row that is currently Active.
 *
 * The register carries live sanctions, and the form refuses to review a change
 * that is not one — so a test that takes `.first()` and picks "Blacklisted"
 * fails on a disabled button whenever a barred business happens to sort to the
 * top. That failure reads as a broken dialog; it is a test choosing badly.
 *
 * Narrowing to the Active pill first, rather than hunting the mixed list.
 */
async function openStatusDialogOnAnActiveRow(page: import('@playwright/test').Page) {
  await page.getByRole('button', { name: 'Active', exact: true }).click()
  const row = page.getByRole('row').filter({ hasText: 'Active' }).first()
  await expect(row, 'no active business on this register').toBeVisible()
  await row.getByRole('button', { name: /^Change the status of/ }).click()
  await expect(page.getByRole('dialog')).toBeVisible()
  return row
}

test.describe('Owner Status', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/admin/owners')
    await expect(page.getByRole('heading', { name: /Business Owner Status/i })).toBeVisible()
  })

  test('no longer offers to hand a business to somebody else', async ({ page }) => {
    /*
     * The control is gone from the row AND from the page: a reader who knew
     * where it used to be should find nothing, not a renamed version of it.
     */
    await expect(page.getByRole('button', { name: /transfer/i })).toHaveCount(0)
    await expect(page.getByText(/Transfer Ownership/i)).toHaveCount(0)
  })

  test('the door to a sanction is not itself painted as one', async ({ page }) => {
    /*
     * Every row carried a RED "Change Status" button — seven hundred of them,
     * on a register that is almost entirely businesses trading normally. Red
     * is this app's one "stop" signal, and spending it on the control that
     * merely opens a dialog leaves nothing to say with when the dialog is
     * about to suspend somebody.
     */
    const button = page.getByRole('button', { name: /^Change the status of/ }).first()
    await expect(button).toBeVisible()

    const background = await button.evaluate((el) => getComputedStyle(el).backgroundColor)
    const [r, g, b] = background.match(/\d+/g)!.map(Number)
    expect(r > g + 40 && r > b + 40, `Change Status is still red: ${background}`).toBe(false)
  })

  test('will not review a change that is not a change', async ({ page }) => {
    await page.getByRole('button', { name: /^Change the status of/ }).first().click()

    const dialog = page.getByRole('dialog')
    const review = dialog.getByRole('button', { name: 'Review this change' })

    // The status select opens on what the business already is, so Review was
    // pressable the moment the dialog appeared — and pressing it wrote an
    // audit row recording a change to the same value.
    await expect(review).toHaveAttribute('aria-disabled', 'true')
    await expect(dialog).toContainText(/already/i)
  })

  test('names the consequence of each status before it is chosen', async ({ page }) => {
    let wrote = false
    await page.route(CHANGE, async (route) => {
      if (route.request().method() !== 'GET') wrote = true
      await route.continue()
    })

    await page.getByRole('button', { name: /^Change the status of/ }).first().click()
    const dialog = page.getByRole('dialog')

    await dialog.getByLabel(/new status/i).selectOption('suspended')
    await dialog.getByLabel(/reason code/i).selectOption({ index: 1 })

    const review = dialog.getByRole('button', { name: 'Review this change' })
    await expect(review).not.toHaveAttribute('aria-disabled', 'true')
    await review.click()

    // The consequence, in the reader's terms, at the moment of deciding — the
    // four status words do not say who can still trade, and that is the whole
    // question a sanction turns on.
    await expect(dialog).toContainText(/cannot file or renew/i)
    await expect(dialog).toContainText(/permits are suspended/i)
    // And the verb is on the button, never "Confirm".
    await expect(dialog.getByRole('button', { name: 'Suspend this business' })).toBeVisible()

    expect(wrote, 'reviewing must not write').toBe(false)

    await dialog.getByRole('button', { name: 'Back' }).click()
    await expect(dialog.getByLabel(/new status/i)).toHaveValue('suspended')
    expect(wrote, 'going back must not write').toBe(false)
  })

  test('warns that blacklisting is about the owner, not the shopfront', async ({ page }) => {
    let wrote = false
    await page.route(CHANGE, async (route) => {
      if (route.request().method() !== 'GET') wrote = true
      await route.continue()
    })

    /*
     * A row that is not ALREADY blacklisted. The register carries live
     * sanctions, and the form refuses to review a change that is not one — so
     * `.first()` failed on a disabled button whenever a barred business
     * happened to sort to the top, which looked like a broken dialog rather
     * than a test picking the wrong row.
     */
    await openStatusDialogOnAnActiveRow(page)
    const dialog = page.getByRole('dialog')

    await dialog.getByLabel(/new status/i).selectOption('blacklisted')
    await dialog.getByLabel(/reason code/i).selectOption({ index: 1 })
    await dialog.getByRole('button', { name: 'Review this change' }).click()

    // The title asks about the PERSON.
    await expect(dialog.getByRole('heading')).toContainText(/^Blacklist /)
    await expect(dialog).toContainText(/will be blacklisted, not just this business/i)
    // Red, because this one stops somebody trading.
    const header = dialog.getByRole('heading')
    const bg = await header.evaluate((el) => getComputedStyle(el).backgroundColor)
    const [r, g, b] = bg.match(/\d+/g)!.map(Number)
    expect(r > g + 40 && r > b + 40, `blacklist dialog is not red: ${bg}`).toBe(true)

    expect(wrote).toBe(false)
    await page.keyboard.press('Escape')
  })

  test('a flagging is not dressed as a danger', async ({ page }) => {
    /*
     * The mirror of the test above, and the reason the tone follows the
     * CHOSEN status rather than the screen. A flag is a note to watch a
     * business; nothing is blocked. If it came out red the colour would mean
     * nothing by the time it mattered.
     */
    await page.getByRole('button', { name: /^Change the status of/ }).first().click()
    const dialog = page.getByRole('dialog')

    await dialog.getByLabel(/new status/i).selectOption('flagged')
    await dialog.getByLabel(/reason code/i).selectOption({ index: 1 })
    await dialog.getByRole('button', { name: 'Review this change' }).click()

    await expect(dialog).toContainText(/Nothing is blocked/i)

    const bg = await dialog.getByRole('heading').evaluate((el) => getComputedStyle(el).backgroundColor)
    const [r, g, b] = bg.match(/\d+/g)!.map(Number)
    expect(r > g + 40 && r > b + 40, `a flag came out red: ${bg}`).toBe(false)
  })

  test.describe('the register of barred people', () => {
    test('is a list of owners, each with everything they hold', async ({ page }) => {
      await page.getByRole('button', { name: 'Blacklisted', exact: true }).click()

      /*
       * Either state is a pass, because the throwaway register may hold no
       * blacklisting — but they are different screens and the empty one has
       * to explain what would appear here, not just shrug.
       */
      const empty = page.getByText(/Nobody is blacklisted/i)
      const listed = page.getByText('Businesses barred').first()

      /*
       * Waited for. The register is fetched, so for a moment neither state is
       * on screen and `isVisible()` answers false about an empty list that
       * simply had not arrived - which sent the test down the populated branch
       * and failed it three assertions later, pointing at the wrong thing.
       */
      await expect(empty.or(listed)).toBeVisible()

      if (await empty.isVisible()) {
        await expect(page.getByText(/every business registered to them/i)).toBeVisible()
        return
      }

      // A card per person, carrying the four things a business row cannot:
      // the reason, the date, who decided, and the businesses covered.
      await expect(page.getByText('Reason', { exact: true }).first()).toBeVisible()
      await expect(listed).toBeVisible()
      await expect(page.getByText(/^Registered to /).first()).toBeVisible()
    })

    test('lives on the Blacklisted pill, not on a register of its own', async ({ page }) => {
      /*
       * It began as a second tab beside "Businesses" — one register too many,
       * because the pill row already had a Blacklisted entry and the screen
       * then offered two doors to the same subject. The pill keeps its place;
       * what it shows underneath is people rather than shopfronts, because a
       * blacklisting falls on the person.
       */
      await expect(page.getByRole('tab', { name: /Blacklisted owners/i })).toHaveCount(0)

      // The table is the Active/Flagged/Suspended view.
      await expect(page.getByRole('table')).toBeVisible()

      await page.getByRole('button', { name: 'Blacklisted', exact: true }).click()

      // Same pills, still there — this is a narrowing of one list, not a
      // different screen the reader has to find their way back from.
      await expect(page.getByRole('button', { name: 'Suspended', exact: true })).toBeVisible()

      const empty = page.getByText(/Nobody is blacklisted/i)
      const listed = page.getByText('Businesses barred').first()
      await expect(empty.or(listed)).toBeVisible()

      // And no table, because a person is not a row here.
      await expect(page.getByRole('table')).toHaveCount(0)
    })

    test('offers the same two acts as every other row on the screen', async ({ page }) => {
      await page.getByRole('button', { name: 'Blacklisted', exact: true }).click()

      const listed = page.getByText('Businesses barred').first()
      const empty = page.getByText(/Nobody is blacklisted/i)
      await expect(empty.or(listed)).toBeVisible()
      test.skip(await empty.isVisible(), 'nobody is blacklisted on this register')

      /*
       * Without these the card was read-only, so lifting a bar meant going
       * back to the Businesses pill and finding the row again — on the one
       * screen where the reader is already looking straight at it.
       */
      const change = page.getByRole('button', { name: /^Change the status of/ }).first()
      await expect(change).toBeVisible()
      await expect(page.getByRole('button', { name: /^Status history for/ }).first()).toBeVisible()

      // And the dialog they open is about the business named on that line.
      await change.click()
      await expect(page.getByRole('dialog')).toBeVisible()
      await page.keyboard.press('Escape')
    })

    test('puts the ordering controls away when the list is of people', async ({ page }) => {
      // "Newest registration" means nothing on a list of owners, and a control
      // that stays on screen doing nothing is worse than one that steps aside.
      await expect(page.getByRole('button', { name: /^Sort:/ })).toBeVisible()

      await page.getByRole('button', { name: 'Blacklisted', exact: true }).click()
      await expect(page.getByRole('button', { name: /^Sort:/ })).toHaveCount(0)
    })
  })

  test.describe('ordering and narrowing the roster', () => {
    test('sorts on the server, so the answer is about the whole register', async ({ page }) => {
      /*
       * The roster is paged at twenty-five out of seven hundred. Sorting what
       * happens to be on screen would put the largest debt on page one of the
       * rows already fetched and call it the largest debt in the city — so the
       * order is a request, and this proves one goes.
       */
      const asked = page.waitForRequest(
        (r) => r.url().includes('/admin/businesses') && r.url().includes('sort=fees'),
      )

      await page.getByRole('button', { name: /^Sort:/ }).click()
      await page.getByRole('option', { name: 'Owes the most' }).click()
      await asked

      await expect(page.getByRole('button', { name: /^Sort: Owes the most/ })).toBeVisible()
    })

    test('narrows by what is owed, and says the filter is on', async ({ page }) => {
      const asked = page.waitForRequest(
        (r) => r.url().includes('/admin/businesses') && r.url().includes('fees=owing'),
      )

      await page.getByRole('button', { name: /^Filter/ }).click()
      await page.getByRole('option', { name: 'Has unbilled fees' }).click()
      await asked

      /*
       * The button says what it is set to. A filter that narrows a list
       * silently is how somebody concludes the register has thirty businesses
       * in it.
       */
      await expect(page.getByRole('button', { name: /Has unbilled fees/ })).toBeVisible()
    })

    test('narrows to the businesses caught by somebody else’s blacklisting', async ({ page }) => {
      const asked = page.waitForRequest(
        (r) => r.url().includes('/admin/businesses') && r.url().includes('owner_blacklisted=true'),
      )

      await page.getByRole('button', { name: /^Filter/ }).click()
      /*
       * `selectOption`, not a click. The extra narrowings in the Filter panel
       * are real labelled `<select>`s — a control you cannot name is one a
       * screen reader cannot announce — and a native `<option>` is never
       * "visible" to Playwright, so clicking one waits for ever.
       *
       * By ROLE with a prefix, because the `<label>` wraps the options too, so
       * its text is "Owner" followed by every option's label — nothing an
       * exact match can land on.
       */
      await page.getByRole('combobox', { name: /^Owner/ }).selectOption('1')
      await asked

      // And the button names the narrowing that is actually on. It used to
      // print the PRIMARY filter's label whatever was set, so a panel narrowed
      // only by one of these read "Filter: Any fees" — the neutral label, on a
      // button coloured to say a filter was on.
      await expect(page.getByRole('button', { name: /Owner is blacklisted/ })).toBeVisible()
    })

    test('counts the narrowings when more than one is on', async ({ page }) => {
      /*
       * One opening, two controls. The panel deliberately stays open when it
       * holds more than a bare listbox — an admin setting three narrowings
       * should not have to reopen it three times — so pressing Filter again
       * here lands on the invisible close-the-menu overlay.
       */
      await page.getByRole('button', { name: /^Filter/ }).click()
      await page.getByRole('option', { name: 'Has unbilled fees' }).click()
      await page.getByRole('combobox', { name: /^Filings/ }).selectOption('never')

      // "Filter: Has unbilled fees, Never filed" is wider than the button and
      // stops being readable at two, so past one it counts instead.
      await expect(page.getByRole('button', { name: /2 filters/ })).toBeVisible()
    })

    test('narrows to a span of registration dates', async ({ page }) => {
      const asked = page.waitForRequest(
        (r) =>
          r.url().includes('/admin/businesses') && r.url().includes('registered_from=2026-01-01'),
      )

      await page.getByRole('button', { name: /^Filter/ }).click()
      await page.getByRole('textbox', { name: /^From/ }).fill('2026-01-01')
      await asked
    })

    test('does not repeat the status pills inside the menu', async ({ page }) => {
      /*
       * Active / Flagged / Suspended / Blacklisted are pills, one press away,
       * on their own row. Offering them again in the Filter panel would give
       * the screen two controls for one question, which then have to be kept
       * agreeing with each other.
       */
      await page.getByRole('button', { name: /^Filter/ }).click()
      const panel = page.getByRole('listbox')
      await expect(panel).toBeVisible()
      await expect(panel.getByRole('option', { name: 'Suspended' })).toHaveCount(0)
      await expect(panel.getByRole('option', { name: 'Flagged' })).toHaveCount(0)
    })
  })

  test.describe('the cascade, end to end', () => {
    test('blacklisting one business bars the owner and the rest of theirs', async ({ page }) => {
      /*
       * This one WRITES. It is the only way to see the cascade, which is the
       * behaviour the whole change is about, and it restores the register
       * afterwards.
       */
      await page.getByRole('button', { name: 'Active', exact: true }).click()
      const row = page.getByRole('row').filter({ hasText: 'Active' }).first()
      await expect(row).toBeVisible()
      const business = (await row.locator('td').first().innerText()).split('\n')[0].trim()
      const owner = (await row.locator('td').nth(1).innerText()).split('\n')[0].trim()

      await row.getByRole('button', { name: /^Change the status of/ }).click()
      const dialog = page.getByRole('dialog')
      await dialog.getByLabel(/new status/i).selectOption('blacklisted')
      await dialog.getByLabel(/reason code/i).selectOption({ index: 1 })
      /*
       * By ROLE, because `getByLabel(/details/i)` also matched the Reason code
       * select: its <label> wraps the options, one of which reads "Other (see
       * details)", so the label's text content contains the word.
       */
      await dialog
        .getByRole('textbox', { name: /details/i })
        .fill('End-to-end test — reverted immediately.')
      await dialog.getByRole('button', { name: 'Review this change' }).click()
      await dialog.getByRole('button', { name: 'Blacklist this owner' }).click()
      await expect(dialog).toBeHidden()

      // The roster now marks the OWNER, not only the shopfront — which is how
      // three rows of one owner read as one sanction rather than three.
      await page.getByRole('button', { name: 'All', exact: true }).click()
      const after = page.getByRole('row').filter({ hasText: business }).first()
      await expect(after).toContainText('Blacklisted')
      await expect(after).toContainText('Owner blacklisted')

      // And the person is on the sanctions book, with their businesses.
      await page.getByRole('button', { name: 'Blacklisted', exact: true }).click()
      await expect(page.getByRole('heading', { name: owner })).toBeVisible()
      await expect(page.getByText('End-to-end test — reverted immediately.')).toBeVisible()

      /*
       * Put it back FROM THE CARD, which is the point of giving these rows
       * their own Change Status: the reader is already looking at the business
       * they mean, and sending them back to the All pill to find it again is
       * the trip this button exists to save.
       */
      const line = page
        .getByRole('listitem')
        .filter({ hasText: business })
        .first()
      await line.getByRole('button', { name: /^Change the status of/ }).click()
      await dialog.getByLabel(/new status/i).selectOption('active')
      await dialog.getByLabel(/reason code/i).selectOption({ label: 'Compliance restored' })
      await dialog.getByRole('button', { name: 'Review this change' }).click()
      // The dialog says it lifts the bar from the person, not just this row.
      await expect(dialog).toContainText(/also lifts the blacklisting/i)
      await dialog.getByRole('button', { name: 'Restore to active' }).click()
      await expect(dialog).toBeHidden()

      /*
       * THIS owner leaves the sanctions book — not "nobody is blacklisted",
       * which would be a claim about the whole register. The copied register
       * carries real blacklistings made by the client, and asserting the list
       * is empty made the test fail on somebody else's sanction.
       */
      await expect(page.getByRole('heading', { name: owner })).toHaveCount(0)

      await page.getByRole('button', { name: 'All', exact: true }).click()
      await expect(page.getByRole('row').filter({ hasText: business }).first()).toContainText(
        'Active',
      )
    })
  })
})
