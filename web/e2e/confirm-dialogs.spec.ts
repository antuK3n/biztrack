import { expect, test } from '@playwright/test'

/**
 * The dialogs that stand in front of a decision.
 *
 * ── What these are cover for ───────────────────────────────────────────────
 *
 * Four acts on the administrator's screens change something a person will
 * notice: who is holding a filing, what is on an officer's record, whether an
 * account can sign in, and whether an account exists at all. Each of them used
 * to happen on one press — in two cases beside a checkbox column, where a
 * stray click lands easily [client, 27 September 2026: *"sa lahat ng major
 * decision na ccontrolin o gagawin dat modal na confirmation"*].
 *
 * A confirmation is only worth the interruption if it tells the reader
 * something they did not already have, so these assert the CONTENT and not
 * merely that a dialog appeared:
 *
 *  - it names the act and the thing acted on, rather than asking "are you
 *    sure";
 *  - the confirm button carries the verb, never "Yes" or "OK";
 *  - Cancel sends nothing, and the work the reader had done is still there;
 *  - nothing reaches the server until Confirm is pressed.
 *
 * The last two are what a confirmation is FOR, and both are invisible to
 * `tsc`: a dialog wired to the wrong handler still type-checks.
 */

/** Every write this page can make, so a test can prove none of them happened. */
const WRITES = '**/api/**'

/**
 * Open the caseload of an officer who is actually carrying something.
 *
 * The directory is ordered by name, so `.first()` lands on whoever happens to
 * sort first — and on this register that is somebody holding nothing, whose
 * caseload page has no table, no checkbox and no act to confirm. A test built
 * on it does not fail; it skips, which is the same as not having written it.
 *
 * The Holding column prints "2 filings" or "Nothing", so the row that can be
 * acted on names itself. If no officer is holding anything the suite should
 * say so loudly rather than pass quietly, hence a failing expectation and not
 * a skip.
 */
async function openSomebodyHolding(page: import('@playwright/test').Page) {
  await page.goto('/admin/users')
  const row = page.getByRole('row').filter({ hasText: /\d+ filing/ }).first()
  await expect(row, 'no officer on this register is holding open work').toBeVisible()
  await row.getByRole('link', { name: /Reassign/i }).click()
  await expect(page.getByRole('table').first()).toBeVisible()
}

test.describe('confirming what cannot be taken back', () => {
  test.describe('reassigning from an officer caseload', () => {
    test('names the count, the destination and the filings before moving them', async ({
      page,
    }) => {
      await openSomebodyHolding(page)

      const tick = page.getByRole('table').first().getByRole('checkbox').first()
      await tick.check()

      await page.getByLabel(/reason/i).fill('Covering while on leave')

      // Nothing has been sent yet, and pressing the button must not send
      // anything either — that is the whole claim.
      let wrote = false
      await page.route(WRITES, async (route) => {
        if (route.request().method() !== 'GET') wrote = true
        await route.continue()
      })

      await page.getByRole('button', { name: /^Move|^Release/ }).click()

      const dialog = page.getByRole('dialog')
      await expect(dialog).toBeVisible()

      // It says what will happen, not "are you sure".
      await expect(dialog).toContainText(/will leave/i)
      await expect(dialog).toContainText(/Covering while on leave/)
      await expect(dialog).toContainText(/undo this by moving them back/i)

      // The verb is on the button.
      const confirm = dialog.getByRole('button', { name: /^(Move to|Release to|Assign to)/ })
      await expect(confirm).toBeVisible()
      await expect(dialog.getByRole('button', { name: /^(Yes|OK|Proceed)$/ })).toHaveCount(0)

      expect(wrote, 'opening the dialog must not write anything').toBe(false)

      // Cancel is safe, and the reason survives it.
      await dialog.getByRole('button', { name: 'Cancel' }).click()
      await expect(dialog).toBeHidden()
      expect(wrote, 'cancelling must not write anything').toBe(false)
      await expect(page.getByLabel(/reason/i)).toHaveValue('Covering while on leave')
    })

    test('Escape closes it without moving anything', async ({ page }) => {
      await openSomebodyHolding(page)

      await page.getByRole('table').first().getByRole('checkbox').first().check()
      await page.getByLabel(/reason/i).fill('Escape test')

      let wrote = false
      await page.route(WRITES, async (route) => {
        if (route.request().method() !== 'GET') wrote = true
        await route.continue()
      })

      await page.getByRole('button', { name: /^Move|^Release/ }).click()
      await expect(page.getByRole('dialog')).toBeVisible()
      await page.keyboard.press('Escape')
      await expect(page.getByRole('dialog')).toBeHidden()
      expect(wrote).toBe(false)
    })
  })

  test.describe('editing an officer', () => {
    test('shows old beside new, and will not review an unchanged form', async ({ page }) => {
      await page.goto('/admin/users')
      await page.getByRole('button', { name: /^Edit/ }).first().click()

      const dialog = page.getByRole('dialog')
      await expect(dialog).toBeVisible()

      /*
       * The button is held, and it SAYS why rather than sitting grey and mute.
       * `aria-disabled`, never the native attribute, so the reason stays
       * reachable (AGENTS.md 6.2).
       *
       * Which reason varies with the row, and deliberately so: nothing has
       * been typed yet, but the register also holds officers whose stored
       * mobile number is not a number anybody could be reached on — nine
       * digits, twelve digits — and the form now refuses to save those until
       * they are corrected. Both are legitimate answers to "why can I not
       * press this", so the test asserts that ONE of them is given rather
       * than guessing which row it landed on.
       */
      const review = dialog.getByRole('button', { name: 'Review changes' })
      await expect(review).toHaveAttribute('aria-disabled', 'true')
      await expect(dialog).toContainText(
        /Nothing has changed yet|mobile number needs to be|Choose (a role|an office)/i,
      )

      /*
       * A malformed stored number has to be fixed before anything else can be
       * saved, which is what an admin would do — and doing it here is what
       * lets the rest of this test run on any row.
       */
      const mobile = dialog.getByLabel(/mobile/i)
      if (!/^09\d{9}$/.test((await mobile.inputValue()).trim())) {
        await mobile.fill('09171234567')
      }

      const email = dialog.getByLabel(/email/i)
      const before = await email.inputValue()
      await email.fill('changed.address@malabon.gov.ph')

      await expect(review).not.toHaveAttribute('aria-disabled', 'true')

      let wrote = false
      await page.route(WRITES, async (route) => {
        if (route.request().method() !== 'GET') wrote = true
        await route.continue()
      })

      await review.click()

      // The pair, not just the new value: "is this the typo I meant to fix?"
      // cannot be answered by the new value alone.
      await expect(dialog).toContainText('Save these changes?')
      await expect(dialog).toContainText(before)
      await expect(dialog).toContainText('changed.address@malabon.gov.ph')
      await expect(dialog.getByRole('button', { name: 'Save changes' })).toBeVisible()
      expect(wrote, 'reviewing must not save').toBe(false)

      // Back really is back: the field still holds what was typed.
      await dialog.getByRole('button', { name: 'Back' }).click()
      await expect(dialog.getByLabel(/email/i)).toHaveValue('changed.address@malabon.gov.ph')
      expect(wrote).toBe(false)
    })
  })

  test.describe('the row after it is written to', () => {
    test('keeps its Holding figure instead of falling back to a dash', async ({ page }) => {
      /*
       * The row is replaced by whatever the write answered with, and editing
       * used to blank the Holding cell on the spot: the update response
       * carried no caseload counts, so a figure that was right a second ago
       * became "we do not know" and stayed that way until a reload.
       *
       * Against the real stack, because the point is what the SERVER sends -
       * a stub would prove only that the fixture was written correctly.
       */
      await page.goto('/admin/users')
      const row = page.getByRole('row').filter({ hasText: /\d+ filing/ }).first()
      await expect(row).toBeVisible()
      const before = (await row.locator('td').nth(3).innerText()).trim()

      await row.getByRole('button', { name: /^Edit/ }).click()
      const dialog = page.getByRole('dialog')

      /*
       * A value that is DIFFERENT from whatever is there, because the form
       * refuses to review a change that is not one - and this suite runs
       * against a database the last run already wrote to, so a fixed number
       * silently becomes a no-op on the second run and the test passes by
       * doing nothing.
       */
      const mobile = dialog.getByLabel(/mobile/i)
      const now = await mobile.inputValue()
      await mobile.fill(now === '09170000123' ? '09170000124' : '09170000123')

      await dialog.getByRole('button', { name: 'Review changes' }).click()
      await dialog.getByRole('button', { name: 'Save changes' }).click()
      await expect(dialog).toBeHidden()

      const after = page.getByRole('row').filter({ hasText: /\d+ filing/ }).first()
      await expect(after.locator('td').nth(3)).toHaveText(before)
    })
  })

  test.describe('deactivating an account', () => {
    test('names the person and the act, and waits until it knows the cost', async ({ page }) => {
      await page.goto('/admin/users')

      /*
       * By the button, not by the word "Active" in the row: "Active",
       * "Inactive" and "Deactivate" all contain it, so that filter matched
       * every row on the page and then picked one that had no Deactivate on
       * it at all.
       */
      const toggle = page.getByRole('button', { name: 'Deactivate', exact: true }).first()
      await expect(toggle, 'no active account on this register').toBeVisible()
      await toggle.click()

      const dialog = page.getByRole('dialog')
      // The title names the act and the person, where it read "WARNING".
      await expect(dialog.getByRole('heading')).toContainText(/^Deactivate .+\?$/)
      await expect(dialog).not.toContainText('WARNING')

      // The verb is the button.
      await expect(dialog.getByRole('button', { name: 'Deactivate account' })).toBeVisible()
      await expect(dialog.getByRole('button', { name: /^Yes$/ })).toHaveCount(0)

      await expect(dialog).toContainText(/signed out straight away/i)

      let wrote = false
      await page.route(WRITES, async (route) => {
        if (route.request().method() !== 'GET') wrote = true
        await route.continue()
      })
      await dialog.getByRole('button', { name: 'Cancel' }).click()
      await expect(dialog).toBeHidden()
      expect(wrote, 'cancelling a deactivation must not write').toBe(false)
    })
  })

  test.describe('adding an officer', () => {
    test('reads the account back, and shows the password one last time', async ({ page }) => {
      await page.goto('/admin/users')
      await page.getByRole('button', { name: /Add Officer/i }).click()

      const dialog = page.getByRole('dialog')
      const review = dialog.getByRole('button', { name: 'Review account' })

      // An empty form names what is missing rather than greying out in silence.
      await expect(review).toHaveAttribute('aria-disabled', 'true')
      await expect(dialog).toContainText(/Still needed before this can be reviewed/i)

      await dialog.getByLabel(/given name/i).fill('Testing')
      await dialog.getByLabel(/surname/i).fill('Account')
      await dialog.getByLabel(/sex/i).selectOption('F')
      await dialog.getByLabel(/email address/i).fill('testing.account@malabon.gov.ph')
      await dialog.getByLabel(/temporary password/i).fill('Sample-Pass-2026!')
      await dialog.getByLabel(/mobile number/i).fill('09171234567')

      /*
       * Office first: it is what settles which roles exist, so the role box is
       * held shut until one is chosen.
       */
      const role = dialog.getByRole('combobox', { name: /^Role/ })
      await expect(role).toHaveAttribute('aria-disabled', 'true')

      /*
       * Waited for, not assumed. The offices arrive in their own request, and
       * for a moment the select holds only its placeholder and "No office" -
       * so `{ index: 1 }` quietly chose the SUPER ADMIN answer, whose role
       * list is one already-taken seat. The test then failed on a disabled
       * option, three assertions away from the actual cause.
       */
      const office = dialog.getByLabel(/^Office/)
      await expect(office.locator('option')).not.toHaveCount(2)
      await office.selectOption({ index: 1 })
      await expect(role).not.toHaveAttribute('aria-disabled', 'true')

      // The roles arrive in their own request, so the list can be open and
      // empty for a moment. It says "still loading" rather than "no match".
      await role.click()
      await expect(dialog.getByRole('listbox').getByRole('option').first()).toBeVisible()

      /*
       * Typed, not hunted for - the filtered list is what this control adds
       * over the select it replaced.
       *
       * The needle is taken FROM the list rather than guessed at. "off" was a
       * guess, and a guess that matches nothing turns this into a test that
       * quietly stops testing. Scoped to the listbox, because `<option>`
       * inside the Sex select carries the same role and sorts first in the
       * DOM.
       */
      const options = dialog.getByRole('listbox').getByRole('option')
      const before = await options.count()
      const roleName = (await options.first().innerText()).split('\n')[0].trim()

      await role.fill(roleName.slice(0, 4))

      /*
       * By NAME, not by position. A partial title that matches no role exactly
       * also offers "Use “Sani” as a new role" — the office may need a job
       * title the list does not hold — and that offer sits at the top, where
       * a reader who has written something unmatched is looking. `.first()`
       * therefore stopped being the role and started being the invitation to
       * invent one.
       */
      const match = options.filter({ hasText: roleName }).first()
      await expect(match).toBeVisible()
      expect(await options.count(), 'typing narrowed nothing').toBeLessThanOrEqual(before + 1)

      await match.click()
      await expect(role).toHaveValue(roleName)

      let wrote = false
      await page.route(WRITES, async (route) => {
        if (route.request().method() !== 'GET') wrote = true
        await route.continue()
      })

      await expect(review).not.toHaveAttribute('aria-disabled', 'true')
      await review.click()

      await expect(dialog).toContainText('Create this account?')
      await expect(dialog).toContainText('Testing Account')
      await expect(dialog).toContainText('testing.account@malabon.gov.ph')
      /*
       * The password in clear, once. It is stored hashed, so this really is
       * the last screen in the app that can show it — an administrator who
       * does not copy it here has to issue a new one.
       */
      await expect(dialog).toContainText(roleName)
      await expect(dialog).toContainText('Sample-Pass-2026!')
      await expect(dialog).toContainText(/last time it can be read/i)

      expect(wrote, 'reviewing must not create the account').toBe(false)

      await dialog.getByRole('button', { name: 'Back' }).click()
      await expect(dialog.getByLabel(/given name/i)).toHaveValue('Testing')
      await dialog.getByRole('button', { name: 'Cancel' }).click()
      expect(wrote, 'cancelling must not create the account').toBe(false)
    })
  })

  test.describe('reassigning one filing from the register', () => {
    test('will not let the current holder be reassigned to themselves', async ({ page }) => {
      await page.goto('/admin/oic')
      // The register is fetched, so the button does not exist on first paint.
      await expect(page.getByRole('table')).toBeVisible()

      /*
       * A row somebody is HOLDING, which is the case this test is about: the
       * select opens on the current holder, so an unheld row would open with
       * nothing chosen and never reach the state being asserted. The officer's
       * name in that cell is a link to their caseload, so the rows that have
       * one are exactly the held ones.
       */
      const holder = page
        .getByRole('row')
        .filter({ has: page.getByRole('link', { name: /Open .+ caseload/ }) })
        .first()
      await expect(holder, 'nothing on this register is held by anyone').toBeVisible()
      await holder.getByRole('button', { name: 'Reassign', exact: true }).click()

      const dialog = page.getByRole('dialog')
      await expect(dialog).toBeVisible()

      /*
       * The select opens on whoever already holds it, so Confirm was pressable
       * the moment the dialog appeared — and pressing it wrote an "assignment
       * changed" entry to the audit trail recording a change to the same name.
       */
      const confirm = dialog.getByRole('button', { name: /^Reassign to/ })
      await expect(confirm).toHaveAttribute('aria-disabled', 'true')
      await expect(dialog).toContainText(/already has this one/i)

      // Choosing somebody else releases it, and the name is on the button.
      const select = dialog.getByLabel(/new officer in charge/i)
      const others = await select.locator('option').evaluateAll((nodes) =>
        nodes
          .filter((n) => {
            const o = n as HTMLOptionElement
            return o.value !== '' && !o.textContent?.includes('currently in charge')
          })
          .map((n) => (n as HTMLOptionElement).value),
      )
      if (others.length > 0) {
        await select.selectOption(others[0])
        await expect(confirm).not.toHaveAttribute('aria-disabled', 'true')
        await expect(dialog).toContainText(/reassign it again at any time/i)
      }
    })
  })
})
