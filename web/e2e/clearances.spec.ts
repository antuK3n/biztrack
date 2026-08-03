import { expect, test, type Page } from '@playwright/test'

/*
 * The LGU Clearances stage, from a business owner's side.
 *
 * Two properties here are not preferences, and both have already been broken
 * once in the wizard the cards came out of (fixed in aabbf21, reported as
 * "sometimes it will just highlight the apply button, sometimes it will
 * actually redirect to the form"):
 *
 *   Apply always opens that office's form.
 *   Submit always opens the upload box.
 *   Neither toggles.
 *
 * They were toggles, so the outcome depended on state the applicant could not
 * see and flipped on every click — and one of the two outcomes was destructive:
 * a second click on "Submitted ✓" deleted the file that had just been uploaded,
 * with no confirmation and no undo. A toggle is exactly the kind of thing that
 * comes back in a merge because it looks tidier, so it is asserted rather than
 * assumed.
 *
 * The third property is the lock: before the first payment clears, the stage is
 * visible but shut, and the reason shown is the API's sentence verbatim.
 */

test.use({ storageState: 'e2e/.auth/owner.json' })

interface Found {
  /** An application whose clearance stage has not unlocked yet. */
  locked: number | null
  /** An unlocked application, and a clearance on it that has an office form. */
  unlocked: { id: number; code: string; name: string } | null
}

/**
 * Find the fixtures in whatever data this stack is carrying.
 *
 * Deliberately not hard-coded ids. This suite runs against a throwaway copy of
 * the SQLite file, and an id that happens to be a paid filing in one copy is a
 * draft in the next — a spec pinned to `/applications/19` would pass or fail on
 * which snapshot it met rather than on the code.
 *
 * The unlocked fixture also has to be one that is ALREADY applied for. Apply on
 * an already-applied clearance is the purest statement of the property under
 * test — it must open the form and must not un-apply — and it is the version
 * that spends none of the applicant's money to check.
 */
async function findFixtures(page: Page): Promise<Found> {
  return page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token')
    const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` }
    const res = await fetch('/api/v1/applications?per_page=100', { headers })
    const body = await res.json()

    const out: {
      locked: number | null
      unlocked: { id: number; code: string; name: string } | null
    } = { locked: null, unlocked: null }

    for (const app of body.data ?? []) {
      if (out.locked !== null && out.unlocked !== null) break
      const c = await fetch(`/api/v1/applications/${app.id}/clearances`, { headers })
      if (!c.ok) continue
      const payload = await c.json()
      if (!payload.meta.unlocked) {
        // A draft is locked for a reason the applicant can act on, which is
        // what the locked state is meant to show.
        if (out.locked === null) out.locked = app.id
        continue
      }
      if (out.unlocked !== null) continue
      const row = (payload.data ?? []).find(
        (r: { state: string; has_office_form: boolean }) =>
          r.state === 'applied' && r.has_office_form,
      )
      if (row) {
        out.unlocked = { id: app.id, code: row.permit_type.code, name: row.permit_type.name }
      }
    }
    return out
  })
}

test('before the first payment the stage is visible but locked, in the API’s own words', async ({
  page,
}) => {
  await page.goto('/dashboard')
  const { locked } = await findFixtures(page)
  expect(locked, 'no application with a locked clearance stage in this dataset').not.toBeNull()

  await page.goto(`/applications/${locked}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  /*
   * Visible, not hidden. The six cards are the point of showing a locked
   * stage at all — an applicant who is shown nothing has no way to learn that
   * six more clearances are coming or what they will cost.
   */
  const cards = page.locator('ul > li').filter({ hasText: /apply/i })
  await expect(cards).toHaveCount(6)

  /*
   * The reason is the server's sentence, not one written here. The condition
   * that opens this stage is the API's to state; a second version in the client
   * would drift the first time the rule moved.
   */
  const reason = page.locator('#clearances-locked')
  await expect(reason).toBeVisible()
  const shown = (await reason.textContent())?.trim() ?? ''
  expect(shown.length, 'the locked stage shows no reason at all').toBeGreaterThan(20)

  const fromApi = await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token')
    const res = await fetch(`/api/v1/applications/${id}/clearances`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
    return (await res.json()).meta.locked_reason as string | null
  }, locked)
  expect(shown, 'the locked reason was paraphrased instead of shown verbatim').toBe(fromApi)

  /*
   * The buttons stay in the tab order. `disabled` drops a control out of it and
   * most screen readers pass over it, so an applicant using one would never
   * learn the button exists or why it does nothing.
   */
  const apply = cards.first().getByRole('button', { name: /^apply$/i })
  await expect(apply).toHaveAttribute('aria-disabled', 'true')
  await expect(apply).toHaveAttribute('aria-describedby', 'clearances-locked')
  /*
   * The native attribute specifically, not Playwright's `toBeDisabled()` —
   * that one treats `aria-disabled="true"` as disabled too, which is right for
   * "can this be operated" and wrong for the question being asked here. The
   * question is whether the control is still REACHABLE: a native `disabled`
   * leaves the tab order, an aria-disabled one does not, and a button an
   * applicant can neither press nor find is a button they will never learn
   * exists.
   */
  const reachable = await apply.evaluate(
    (el) => !(el as HTMLButtonElement).disabled && (el as HTMLButtonElement).tabIndex >= 0,
  )
  expect(reachable, 'the locked Apply button is closed with `disabled`, not `aria-disabled`').toBe(
    true,
  )
  await apply.focus()
  await expect(apply).toBeFocused()

  /*
   * And pressing them does nothing at all: no form, no upload box.
   *
   * `dispatchEvent` rather than `click()`, because Playwright's actionability
   * check refuses to click an aria-disabled control and would fail here for the
   * wrong reason. What is under test is that the HANDLER refuses — the guard is
   * in the click handler, not only in the styling, so a stray Enter from a
   * keyboard user on a focusable button cannot apply for a clearance the stage
   * has not opened.
   */
  await apply.dispatchEvent('click')
  await page.waitForTimeout(500)
  await expect(page.getByRole('dialog')).toBeHidden()
  await expect(page.getByRole('button', { name: /save & back to clearances/i })).toBeHidden()

  const submit = cards.first().getByRole('button', { name: /submit a copy/i })
  await submit.dispatchEvent('click')
  await page.waitForTimeout(500)
  await expect(page.getByRole('dialog')).toBeHidden()
})

test('Apply always opens that office’s form, and never un-applies', async ({ page }) => {
  await page.goto('/dashboard')
  const { unlocked } = await findFixtures(page)
  expect(
    unlocked,
    'no unlocked application with an applied-for clearance that has an office form',
  ).not.toBeNull()

  await page.goto(`/applications/${unlocked!.id}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const card = page.locator('ul > li').filter({ hasText: unlocked!.name })
  await expect(card).toHaveCount(1)
  // The fixture is a clearance already applied for. Under the old toggle this
  // click un-applied it and opened nothing.
  await expect(card).toContainText(/applied for/i)

  const backToCards = page.getByRole('button', { name: /save & back to clearances/i })

  await card.getByRole('button', { name: /^apply$/i }).click()
  await expect(backToCards, 'Apply did not open the office form').toBeVisible()

  await page.getByRole('button', { name: /back without saving/i }).click()
  await expect(backToCards).toBeHidden()

  // Still applied for: the click opened a form, it did not undo anything.
  await expect(card).toContainText(/applied for/i)

  // And again. A toggle would open nothing the second time.
  await card.getByRole('button', { name: /^apply$/i }).click()
  await expect(backToCards, 'a second Apply did not open the office form').toBeVisible()
  await page.getByRole('button', { name: /back without saving/i }).click()
  await expect(card).toContainText(/applied for/i)
})

test('Submit always opens the upload box, and never removes what is there', async ({ page }) => {
  await page.goto('/dashboard')
  const { unlocked } = await findFixtures(page)
  expect(unlocked, 'no unlocked application to open the clearance stage on').not.toBeNull()

  await page.goto(`/applications/${unlocked!.id}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const card = page.locator('ul > li').filter({ hasText: unlocked!.name })
  const dialog = page.getByRole('dialog')

  await card.getByRole('button', { name: /submit a copy/i }).click()
  await expect(dialog, 'Submit did not open the upload box').toBeVisible()
  await expect(dialog).toContainText(/choose your certificate/i)
  /*
   * The consequence, on the box itself. Apply and Submit sit side by side and
   * look alike; one of them spends money and the other does not.
   */
  await expect(dialog).toContainText(/nothing is added to your balance/i)

  await dialog.getByRole('button', { name: /^cancel$/i }).click()
  await expect(dialog).toBeHidden()

  // Again. Under the old toggle a second Submit DELETED the uploaded file.
  await card.getByRole('button', { name: /submit a copy/i }).click()
  await expect(dialog, 'a second Submit did not open the upload box').toBeVisible()
  await dialog.getByRole('button', { name: /^cancel$/i }).click()
})

test('un-applying has its own labelled control, apart from Apply', async ({ page }) => {
  await page.goto('/dashboard')
  const { unlocked } = await findFixtures(page)
  expect(unlocked, 'no unlocked application with an applied-for clearance').not.toBeNull()

  await page.goto(`/applications/${unlocked!.id}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const card = page.locator('ul > li').filter({ hasText: unlocked!.name })

  /*
   * Destroying something must never be the alternate meaning of the button
   * that created it. The withdraw control names the clearance it withdraws, so
   * a screen reader moving button to button is told which of six it is on.
   */
  const withdraw = card.getByRole('button', { name: new RegExp(`don’t apply for the`, 'i') })
  await expect(withdraw).toBeVisible()
  await expect(withdraw).toContainText(unlocked!.name)

  // Three distinct controls, not two doing four jobs between them.
  await expect(card.getByRole('button', { name: /^apply$/i })).toBeVisible()
  await expect(card.getByRole('button', { name: /submit a copy/i })).toBeVisible()
})

test('the balance is a live region, and says plainly what holds the permit', async ({ page }) => {
  await page.goto('/dashboard')
  const { unlocked } = await findFixtures(page)
  expect(unlocked, 'no unlocked application to read a balance from').not.toBeNull()

  await page.goto(`/applications/${unlocked!.id}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  /*
   * Applying moves the balance. A number that changes silently is invisible to
   * a screen reader, so the figures live in a role="status" region rather than
   * being merely redrawn.
   */
  const balance = page.getByRole('status', { name: 'Balance' })
  await expect(balance).toBeVisible()
  await expect(balance).toContainText(/assessed/i)
  await expect(balance).toContainText(/paid/i)
  await expect(balance).toContainText(/balance due/i)
  await expect(balance).toContainText(/not released until this balance is cleared/i)
})
