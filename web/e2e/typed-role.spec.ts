import { expect, test } from '@playwright/test'

/**
 * Typing a role that is not on the list.
 *
 * ── What this is cover for ─────────────────────────────────────────────────
 *
 * An office needs to be able to write down the job title it actually uses
 * [client, 27 September 2026: *"pede rin nila i type yung role kung wala sa
 * choices"*]. Until now the Role box only filtered: a title with no match left
 * the reader looking at "No role matches", with nothing to press.
 *
 * Two things have to hold, and they pull against each other:
 *
 *  - a title with no match IS offered, reachable by keyboard, and says what
 *    the role will be able to do before it is created; and
 *  - it can never reach a departmentless role. `admin` holds `user.manage` —
 *    the power to mint accounts — so a typed name that got there would be
 *    privilege escalation by spelling.
 */

/** Open Add Officer with an office chosen, which is what unlocks the box. */
async function addOfficerWithOffice(page: import('@playwright/test').Page, office: 'real' | 'none') {
  await page.goto('/admin/users')
  await page.getByRole('button', { name: /Add Officer/i }).click()

  const dialog = page.getByRole('dialog')
  const select = dialog.getByLabel(/^Office/)

  /*
   * Waited for. The offices arrive in their own request, and for a moment the
   * select holds only its placeholder and "No office" — so `{ index: 1 }`
   * quietly chooses the SUPER ADMIN answer.
   */
  await expect(select.locator('option')).not.toHaveCount(2)
  await select.selectOption(office === 'none' ? 'none' : { index: 1 })

  return dialog
}

test.describe('a role typed because it is not on the list', () => {
  test('is offered, and says what it will be able to do', async ({ page }) => {
    const dialog = await addOfficerWithOffice(page, 'real')
    const role = dialog.getByRole('combobox', { name: /^Role/ })

    // The offer is on screen before anybody discovers it by accident.
    await expect(dialog.getByText(/Not on the list\?/i)).toBeVisible()

    await role.fill('Sanitary Inspector II')

    const offer = dialog.getByRole('option', { name: /Use .Sanitary Inspector II. as a new role/ })
    await expect(offer).toBeVisible()
    /*
     * What it will be able to do, BEFORE it is made. A role is a set of
     * powers, and one created with none would sign its holder in to a blank
     * app with no error anywhere to say why — so the fact that it copies the
     * standard office set is the reassurance that belongs here.
     */
    await expect(offer).toContainText(/same powers every office role has/i)

    await offer.click()
    await expect(role).toHaveValue('Sanitary Inspector II')

    // And once taken up, the box says a role is being CREATED rather than
    // matched — otherwise the two are indistinguishable.
    await expect(dialog.getByText(/will be added as a new role for this office/i)).toBeVisible()
  })

  test('commits on Enter, for a reader who never touches the mouse', async ({ page }) => {
    const dialog = await addOfficerWithOffice(page, 'real')
    const role = dialog.getByRole('combobox', { name: /^Role/ })

    await role.fill('Records Clerk III')
    // Nothing matches, so there is no row to highlight — Enter takes the
    // title, which is what a box that let you type it should do.
    await role.press('Enter')

    await expect(role).toHaveValue('Records Clerk III')
    await expect(dialog.getByText(/will be added as a new role/i)).toBeVisible()
  })

  test('prefers a real match over inventing one', async ({ page }) => {
    const dialog = await addOfficerWithOffice(page, 'real')
    const role = dialog.getByRole('combobox', { name: /^Role/ })

    // Part-way through a word: both are offered, because the reader may be
    // heading for either.
    await role.fill('Sanitary')
    await expect(dialog.getByRole('option', { name: /Use .Sanitary. as a new role/ })).toBeVisible()

    // But once what is written IS a role's own label, inventing a second one
    // beside it is never what was meant.
    await role.fill('Sanitary Officer')
    await expect(dialog.getByRole('option', { name: /as a new role/ })).toHaveCount(0)
    await expect(dialog.getByRole('option', { name: 'Sanitary Officer' })).toBeVisible()
  })

  test('will not take a title too short to be one', async ({ page }) => {
    const dialog = await addOfficerWithOffice(page, 'real')
    const role = dialog.getByRole('combobox', { name: /^Role/ })

    await role.fill('II')
    await expect(dialog.getByRole('option', { name: /as a new role/ })).toHaveCount(0)
    // And it says why, rather than showing an empty list.
    await expect(dialog.getByText(/too short for a role name/i)).toBeVisible()
  })

  test('is never offered on the super-admin path', async ({ page }) => {
    /*
     * The one door typing must not open. `admin` carries fourteen permissions
     * including `user.manage`; a typed name that reached a departmentless role
     * would be a way to mint that power by spelling it.
     */
    const dialog = await addOfficerWithOffice(page, 'none')
    const role = dialog.getByRole('combobox', { name: /^Role/ })

    await expect(dialog.getByText(/Not on the list\?/i)).toHaveCount(0)

    await role.fill('Deputy Super Admin')
    await expect(dialog.getByRole('option', { name: /as a new role/ })).toHaveCount(0)
  })

  test('is named as NEW on the review step, not as any other role', async ({ page }) => {
    const dialog = await addOfficerWithOffice(page, 'real')

    await dialog.getByLabel(/given name/i).fill('Typed')
    await dialog.getByLabel(/surname/i).fill('Rolecheck')
    await dialog.getByLabel(/sex/i).selectOption('F')
    await dialog.getByLabel(/email address/i).fill('typed.rolecheck@malabon.gov.ph')
    await dialog.getByLabel(/temporary password/i).fill('Malabon-City-2026!')
    await dialog.getByLabel(/mobile number/i).fill('09171234567')

    const role = dialog.getByRole('combobox', { name: /^Role/ })
    await role.fill('Sanitary Inspector II')
    await role.press('Enter')

    let wrote = false
    await page.route('**/api/**', async (route) => {
      if (route.request().method() !== 'GET') wrote = true
      await route.continue()
    })

    await dialog.getByRole('button', { name: 'Review account' }).click()

    /*
     * Creating a role is a SECOND act the reader is confirming, not a detail
     * of creating an account — so the review step says so rather than printing
     * the title as though it were already on the list.
     */
    await expect(dialog).toContainText(/Sanitary Inspector II — new role for this office/)

    expect(wrote, 'reviewing must not create anything').toBe(false)
    await dialog.getByRole('button', { name: 'Back' }).click()
    await dialog.getByRole('button', { name: 'Cancel' }).click()
    expect(wrote, 'cancelling must not create anything').toBe(false)
  })

  test('is dropped when the office changes under it', async ({ page }) => {
    /*
     * A title is written FOR the office showing above it. Carried over to the
     * fire station it would be created there instead, silently — nothing on
     * screen says which office a typed role belongs to except that select.
     */
    const dialog = await addOfficerWithOffice(page, 'real')
    const role = dialog.getByRole('combobox', { name: /^Role/ })

    await role.fill('Sanitary Inspector II')
    await role.press('Enter')
    await expect(dialog.getByText(/will be added as a new role/i)).toBeVisible()

    await dialog.getByLabel(/^Office/).selectOption({ index: 2 })

    await expect(dialog.getByText(/will be added as a new role/i)).toHaveCount(0)
    await expect(role).toHaveValue('')
  })

  test('puts the office’s own roles at the top of the list', async ({ page }) => {
    /*
     * An admin who has just chosen an office does not want to read six roles,
     * five of which belong elsewhere [client, 27 September 2026: *"may
     * dropdown na ng kung ano ano ang role sa office na pinili na yon"*].
     *
     * ORDERED, not filtered, and the difference is the point: nothing in the
     * register ties a role to an office, so hiding the rest would invent a
     * rule the server does not enforce and remove a legitimate choice. Both
     * headings therefore have to be there.
     */
    const dialog = await addOfficerWithOffice(page, 'real')
    await dialog.getByRole('combobox', { name: /^Role/ }).click()

    const list = dialog.getByRole('listbox')
    await expect(list.getByRole('option').first()).toBeVisible()

    await expect(list).toContainText('Used in this office')
    await expect(list).toContainText('Other office roles')

    /*
     * And the office's own role really is first. Read off the rendered list
     * rather than assumed: this is the whole claim, and an ordering that
     * silently stopped happening would leave every other assertion passing.
     */
    /*
     * Lower-cased first. The headings are styled `uppercase`, and `innerText`
     * honours `text-transform` — so the source spelling is nowhere in what
     * comes back, and both `indexOf`s answered -1.
     */
    const text = (await list.innerText()).toLowerCase()
    const first = text.indexOf('used in this office')
    const second = text.indexOf('other office roles')

    expect(first).toBeGreaterThanOrEqual(0)
    expect(second).toBeGreaterThan(first)
  })

  test('still lets a role be picked off the list, untouched', async ({ page }) => {
    // Typing is an addition, not a replacement. The ordinary path must not
    // have become the exceptional one.
    const dialog = await addOfficerWithOffice(page, 'real')
    const role = dialog.getByRole('combobox', { name: /^Role/ })

    await role.click()
    const first = dialog.getByRole('listbox').getByRole('option').first()
    const label = (await first.innerText()).split('\n')[0].trim()
    await first.click()

    await expect(role).toHaveValue(label)
    await expect(dialog.getByText(/will be added as a new role/i)).toHaveCount(0)
  })
})
