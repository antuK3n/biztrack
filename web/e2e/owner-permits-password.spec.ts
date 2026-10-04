import { expect, test } from '@playwright/test'

/**
 * The business owner's side: their permits, and their password.
 *
 * Three asks, all from 28 September 2026:
 *
 *  1. *"create a page dedicated for approved permits for more visibility and
 *     accessibility"* — they were the bottom section of Profile, and Profile
 *     sits behind the avatar menu, which lives in a rail that is
 *     `hidden … lg:flex`. On a phone an applicant could not reach their own
 *     certificates at all.
 *  2. *"fix the layout of virtual permits"* — on a phone the permit number
 *     rendered one character per line.
 *  3. *"implement email verification in the change password"* — covered by
 *     PasswordChangeCodeTest and password-change.spec.ts. When this branch was
 *     merged on 2 October 2026, the team kept the other implementation of the
 *     same ask (the code is asked for only while e-mail is switched on), so the
 *     tests for this branch's two-step dialog went with it.
 */

test.use({ storageState: 'e2e/.auth/default/owner.json' })

test.describe('the permits page', () => {
  test('is in the rail, and is where /permits lands', async ({ page }) => {
    await page.goto('/dashboard')

    // A rail entry of its own — it had none, and the route only redirected.
    const entry = page.getByRole('link', { name: 'My Permits' })
    await expect(entry).toBeVisible()
    await entry.click()

    await expect(page).toHaveURL(/\/permits$/)
    await expect(page.getByRole('heading', { name: 'My Permits' })).toBeVisible()
  })

  test('says what you hold before you open anything', async ({ page }) => {
    await page.goto('/permits')
    await expect(page.getByText(/issued permit/).first()).toBeVisible({ timeout: 20000 })

    /*
     * Figures an applicant would otherwise count by opening every business in
     * turn. Counted from the same groups the list is built from, so the line
     * and the list cannot disagree.
     */
    await expect(page.getByText(/^businesses?$/)).toBeVisible()
    await expect(page.getByText(/issued permits?/)).toBeVisible()
  })

  test('is reachable on a phone, which is the whole point', async ({ page }) => {
    /*
     * The rail is desktop-only, so the tab bar IS mobile navigation. Permits
     * had no place in it, and Profile — where they lived — has none either.
     */
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto('/dashboard')

    const tab = page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: /permits/i })
    await expect(tab).toBeVisible()
    await tab.click()
    await expect(page.getByRole('heading', { name: 'My Permits' })).toBeVisible()
  })

  test('did not cost Payment History its place on the tab bar', async ({ page }) => {
    /*
     * The bar was capped at five and an owner's rail now yields six. Slicing
     * would have dropped the last one — and nothing else on a phone can reach
     * it, so a sliced entry is not demoted, it is gone.
     */
    await page.setViewportSize({ width: 360, height: 780 })
    await page.goto('/dashboard')

    const bar = page.getByRole('navigation', { name: 'Main' })
    for (const label of ['Home', 'Track', 'My Permits', 'Messages', 'Drafts', 'Payment History']) {
      await expect(bar.getByRole('link', { name: label })).toBeVisible()
    }
  })

  test('Profile keeps the count and links here, rather than carrying the list', async ({ page }) => {
    await page.goto('/profile')
    // Inside MAIN: the rail carries a "My Permits" link too, and an unscoped
    // locator cannot tell the page's own card from the navigation beside it.
    const link = page.getByRole('main').getByRole('link', { name: /My Permits/ })
    await expect(link).toBeVisible()
    await link.click()
    await expect(page.getByRole('heading', { name: 'My Permits' })).toBeVisible()
  })
})

test.describe('the virtual permit', () => {
  test('keeps every value on one line on a phone', async ({ page }) => {
    /*
     * The bug this is cover for: the label was `shrink-0` and the value box
     * `flex-1`, so on a narrow screen the box collapsed to what was left and
     * `break-words` broke inside the words. "MCB-2026-000001" came out as M,
     * C, B, -, 2, 0, 2, 6 down the page — on a certificate somebody holds up
     * to an inspector.
     *
     * Measured, not eyeballed: a box one character wide is what a character
     * stack looks like to the DOM.
     */
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto('/permits/1')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 20000 })

    const value = page.getByText(/^MCB-\d{4}-\d{6}$/).first()
    await expect(value).toBeVisible()

    const box = await value.boundingBox()
    expect(box, 'the permit number has no box').not.toBeNull()
    // One line of 14px text is about 22px tall. A per-character stack of
    // fifteen characters would be ten times that.
    expect(box!.height, 'the permit number is stacked vertically').toBeLessThan(60)
    expect(box!.width, 'the permit number box collapsed').toBeGreaterThan(120)
  })

  test('lines every value box up on one left edge', async ({ page }) => {
    /*
     * Each label was a different length and each box began where its own label
     * ended, so the values started at five different x positions. A government
     * form has one left edge for its values.
     */
    await page.setViewportSize({ width: 1280, height: 900 })
    await page.goto('/permits/1')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 20000 })

    const lefts = await page
      .locator('.bg-royal-tint')
      .evaluateAll((nodes) => nodes.map((n) => Math.round(n.getBoundingClientRect().left)))

    expect(lefts.length).toBeGreaterThan(4)
    expect(new Set(lefts).size, `value boxes start at ${new Set(lefts).size} different x`).toBe(1)
  })

  test('names the permit in the bar, so you know what you opened', async ({ page }) => {
    // It was a bare royal band with an unlabelled cross: forty pixels of
    // colour carrying nothing, above a document you had to read to identify.
    await page.goto('/permits/1')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 20000 })

    // Inside MAIN again: the rail is `.bg-royal` too, and it sorts first.
    const bar = page.getByRole('main').locator('.bg-royal').first()
    await expect(bar).toContainText(/MCB-\d{4}-\d{6}/)
  })

  test('closes back to the permits page', async ({ page }) => {
    await page.goto('/permits/1')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 20000 })

    await page.getByRole('button', { name: 'Close permit view' }).click()
    await expect(page).toHaveURL(/\/permits$/)
  })

  test('hides the app furniture when printing', async ({ page }) => {
    /*
     * `window.print()` was called against a page that had never been told what
     * printing means, so the sheet came out with the royal rail down its left
     * edge, the notification bell over its corner and the chat bubble on the
     * signature lines.
     */
    await page.goto('/permits/1')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 20000 })

    await page.emulateMedia({ media: 'print' })

    await expect(page.getByRole('navigation', { name: 'Main' })).toBeHidden()
    await expect(page.getByRole('button', { name: 'Close permit view' })).toBeHidden()
    // The certificate itself stays.
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await page.emulateMedia({ media: 'screen' })
  })
})
