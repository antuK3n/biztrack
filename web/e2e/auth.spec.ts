import { expect, test } from '@playwright/test'
import { ACCOUNTS, DEMO_PASSWORD, mergedStorageState } from './helpers'

/*
 * Sign-in, driven through the real form.
 *
 * Every other spec takes the API shortcut in helpers.ts, so this file is the
 * only place the form itself is exercised. If it goes, everything else keeps
 * passing against a door nobody can open.
 */

test('the sign-in page is reachable and labelled', async ({ page }) => {
  await page.goto('/login')
  await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()

  // Placeholder-as-label fails WCAG 1.3.1 / 3.3.2 and PRODUCT.md says so
  // explicitly. Both fields must have a real accessible name.
  await expect(page.getByRole('textbox', { name: /email/i })).toBeVisible()
  await expect(page.getByRole('textbox', { name: /password/i })).toBeVisible()
})

test('a business owner signs in and lands in the app', async ({ page }) => {
  await page.goto('/login')
  await page.getByRole('textbox', { name: /email/i }).fill(ACCOUNTS.owner)
  await page.getByRole('textbox', { name: /password/i }).fill(DEMO_PASSWORD)
  await page.getByRole('button', { name: /sign in/i }).click()

  await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 })
  await expect(page.getByRole('heading').first()).toBeVisible()
})

test('wrong credentials are refused without leaking which half was wrong', async ({ page }) => {
  await page.goto('/login')
  await page.getByRole('textbox', { name: /email/i }).fill(ACCOUNTS.owner)
  await page.getByRole('textbox', { name: /password/i }).fill('not-the-password')
  await page.getByRole('button', { name: /sign in/i }).click()

  await expect(page).toHaveURL(/\/login/)
  const body = ((await page.locator('body').textContent()) ?? '').toLowerCase()
  // "No account with that email" tells an attacker which addresses are
  // registered. The message must not distinguish the two failures.
  expect(body).not.toContain('no account')
  expect(body).not.toContain('user not found')
})

test('an LGU account is turned away from the citizen portal, and told where to go', async ({
  page,
}) => {
  /*
   * The two portals are separated on purpose: the citizen-facing one admits
   * business owners only. The refusal has to name the other door, or a staff
   * member reads it as "my password is wrong" and resets a working password.
   */
  await page.goto('/login')
  const result = await page.evaluate(
    async ([email, password]) => {
      const res = await fetch('/api/v1/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email, password, portal: 'public' }),
      })
      return { status: res.status, body: await res.json() }
    },
    [ACCOUNTS.admin, DEMO_PASSWORD] as const,
  )

  expect(result.status).toBe(409)
  expect(result.body.portal).toBe('staff')
  expect(String(result.body.message)).toMatch(/staff portal/i)
})

test('a business owner is turned away from the staff portal', async ({ page }) => {
  await page.goto('/login')
  const status = await page.evaluate(
    async ([email, password]) => {
      const res = await fetch('/api/v1/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email, password, portal: 'staff' }),
      })
      return res.status
    },
    [ACCOUNTS.owner, DEMO_PASSWORD] as const,
  )

  expect(status).toBe(409)
})

test('an unauthenticated visitor cannot reach an analytics screen', async ({ page }) => {
  await page.goto('/staff/analytics/renewal-risk')
  /*
   * It must not render the register to a stranger — and it must turn them out
   * at the STAFF door, not the citizen one. The two sites hold separate
   * sessions, so bouncing an officer to `/login` would show them a sign-in
   * whose token has nothing to do with the page they asked for.
   */
  await expect(page).toHaveURL(/\/staff\/login/, { timeout: 20_000 })
})

test('an admin tab and an owner tab are signed in at the same time', async ({ browser }) => {
  /*
   * The whole point of splitting the portals into two sites.
   *
   * Both sessions previously lived at one localStorage key, which is shared by
   * every tab on an origin — so signing in as an administrator silently evicted
   * the business owner, and demonstrating the two sides of this system meant
   * two browsers or a lot of signing in and out. The token is keyed by portal
   * now, and which key a tab uses is read from its own address bar.
   *
   * ONE context on purpose: separate contexts have separate storage and would
   * pass this test no matter how the app behaved. The shared storage is the
   * thing under test.
   */
  const context = await browser.newContext({
    storageState: mergedStorageState(['owner.json', 'admin.json']),
  })
  const citizen = await context.newPage()
  const staff = await context.newPage()

  await citizen.goto('/dashboard')

  // Both sessions are present at once — neither evicted the other.
  const keys = await citizen.evaluate(() =>
    Object.keys(localStorage)
      .filter((k) => k.startsWith('biztrack.token'))
      .sort(),
  )
  expect(keys).toEqual(['biztrack.token.public', 'biztrack.token.staff'])

  await staff.goto('/staff/dashboard')

  // Neither tab was bounced to a sign-in page, and each is on its own site.
  await expect(citizen).toHaveURL(/\/dashboard$/, { timeout: 20_000 })
  await expect(staff).toHaveURL(/\/staff\/dashboard$/, { timeout: 20_000 })

  // And each is showing its own user's home, not the other's.
  await expect(citizen.getByText(/track your businesses with/i)).toBeVisible()
  await expect(staff.getByRole('heading', { name: /application verification/i })).toBeVisible()

  await context.close()
})

test('signing out of one portal leaves the other signed in', async ({ browser }) => {
  /*
   * The mirror of the test above. An officer ending their session must not
   * also sign out the owner account in the next tab: different people, as far
   * as this browser is concerned, and the API revokes only the token sent.
   */
  const context = await browser.newContext({
    storageState: mergedStorageState(['owner.json', 'admin.json']),
  })
  const citizen = await context.newPage()
  const staff = await context.newPage()

  await staff.goto('/staff/dashboard')
  await expect(staff).toHaveURL(/\/staff\/dashboard$/, { timeout: 20_000 })

  await staff.getByRole('button', { name: /account menu/i }).click()
  await staff.getByRole('menuitem', { name: /log out/i }).click()
  // The WARNING modal (p18): "Are you sure you want to log out of this account?"
  await staff.getByRole('dialog').getByRole('button', { name: /^yes$/i }).click()
  await expect(staff).toHaveURL(/\/staff\/login/, { timeout: 20_000 })

  // The citizen tab is untouched.
  await citizen.goto('/dashboard')
  await expect(citizen).toHaveURL(/\/dashboard$/, { timeout: 20_000 })
  await expect(citizen.getByText(/track your businesses with/i)).toBeVisible()

  await context.close()
})

test('the API refuses an analytics request with no token', async ({ page }) => {
  await page.goto('/login')
  const status = await page.evaluate(async () => {
    const res = await fetch('/api/v1/analytics/renewal-risk', {
      headers: { Accept: 'application/json' },
    })
    return res.status
  })
  expect([401, 403]).toContain(status)
})
