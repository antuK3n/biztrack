import { expect, test } from '@playwright/test'
import { ACCOUNTS, DEMO_PASSWORD } from './helpers'

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
  await page.goto('/analytics/renewal-risk')
  // Whatever the route does, it must not render the register to a stranger.
  await expect(page).toHaveURL(/\/login/, { timeout: 20_000 })
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
