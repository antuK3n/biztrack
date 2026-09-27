import { expect, test } from '@playwright/test'
import { ACCOUNTS, DEMO_PASSWORD, sessionFor } from './helpers'

/*
 * What the sign-in pages say, beyond the form [checklist 2026-09-27]:
 * the session-expired notice (Login 4), the office-hours notice (Login 6) and
 * the e-mailed sign-in code (Login 5).
 *
 * Kept out of auth.spec.ts on purpose. That file spends the ten-a-minute login
 * budget (AppServiceProvider) on the real endpoint and waits a minute first to
 * have it; these tests answer /auth/login themselves with page.route, and only
 * one of them reaches the real endpoint at all.
 *
 * The e2e stack runs with mail OFF, as the demo does, so the API never asks
 * for a code there. The code step is therefore driven by replacing the first
 * answer with the shape AuthController sends when mail is on — that shape and
 * every rule behind it are pinned server-side in EmailCodeTest.
 */

test('a signed-out session says why, in plain words and not in red', async ({ page }) => {
  // What lib/api.ts leaves behind when a live session answers 401.
  await page.goto('/login')
  await page.evaluate(() => sessionStorage.setItem('biztrack.session_expired', '1'))
  await page.reload()

  const notice = page.getByRole('status').filter({ hasText: 'You were signed out after 12 hours. Please sign in again.' })
  await expect(notice).toBeVisible()
  // Amber (warning), never the error red: being signed out on schedule is not
  // something the reader did wrong.
  await expect(notice).toHaveClass(/amber/)
  await expect(page.getByRole('alert')).toHaveCount(0)
})

test('with a sign-in code required, the second step finishes the sign-in', async ({ page }) => {
  /*
   * Login 5, driven through the page. The e2e stack runs with mail off, so the
   * API never asks for a code there; the first answer is replaced with the
   * shape AuthController sends when mail is on (pinned server-side in
   * EmailCodeTest). The code step is then answered by the REAL password
   * sign-in, so what lands at the end is a working session, not a stub.
   */
  let codeTries = 0
  await page.route('**/api/v1/auth/login', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          code_required: true,
          challenge: 'e2e-challenge',
          email: 'o••••@biztrack.local',
          expires_in_minutes: 10,
          resend_after: 60,
        },
      }),
    })
  })
  await page.route('**/api/v1/auth/login/code', async (route) => {
    codeTries += 1
    const sent = route.request().postDataJSON() as { challenge: string; code: string }
    expect(sent.challenge).toBe('e2e-challenge')
    // The API drops spaces before comparing (AuthController::tryCode); so does this.
    if (sent.code.replace(/\s/g, '') !== '123456') {
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'That code is not right. You have 4 tries left.',
          errors: { code: ['That code is not right. You have 4 tries left.'] },
        }),
      })
      return
    }
    const real = await route.fetch({
      url: route.request().url().replace(/\/code$/, ''),
      postData: JSON.stringify({ email: ACCOUNTS.owner, password: DEMO_PASSWORD, portal: 'public' }),
    })
    await route.fulfill({ response: real })
  })

  await page.goto('/login')
  await page.getByRole('textbox', { name: /email/i }).fill(ACCOUNTS.owner)
  await page.getByRole('textbox', { name: /password/i }).fill(DEMO_PASSWORD)
  await page.getByRole('button', { name: /sign in/i }).click()

  await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible()
  await expect(page.getByText('o••••@biztrack.local')).toBeVisible()
  // The resend button waits out the server's minute, and says so.
  await expect(page.getByRole('button', { name: /send a new code in \d+s/i })).toHaveAttribute('aria-disabled', 'true')

  const code = page.getByRole('textbox', { name: /sign-in code/i })
  await expect(code).toHaveAttribute('autocomplete', 'one-time-code')
  await code.fill('000000')
  await page.getByRole('button', { name: /^sign in$/i }).click()
  await expect(page.getByText('That code is not right. You have 4 tries left.')).toBeVisible()

  await code.fill('123 456')
  await page.getByRole('button', { name: /^sign in$/i }).click()
  await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 })
  expect(codeTries).toBe(2)
})

test('a dead sign-in code sends the reader back to the password step', async ({ page }) => {
  await page.route('**/api/v1/auth/login', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: { code_required: true, challenge: 'x', email: 'o••••@biztrack.local', expires_in_minutes: 10, resend_after: 0 },
      }),
    }),
  )
  await page.route('**/api/v1/auth/login/code', (route) =>
    route.fulfill({
      status: 422,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'This code no longer works. Sign in again to get a new one.', reason: 'code_expired' }),
    }),
  )

  await page.goto('/login')
  await page.getByRole('textbox', { name: /email/i }).fill(ACCOUNTS.owner)
  await page.getByRole('textbox', { name: /password/i }).fill(DEMO_PASSWORD)
  await page.getByRole('button', { name: /sign in/i }).click()
  await page.getByRole('textbox', { name: /sign-in code/i }).fill('123456')
  await page.getByRole('button', { name: /^sign in$/i }).click()

  await expect(page.getByText('This code no longer works. Sign in again to get a new one.')).toBeVisible()
  await expect(page.getByRole('textbox', { name: /password/i })).toBeVisible()
})


/*
 * The office-hours notice reads the server's answer, never the browser clock,
 * so these fulfil /office-hours rather than moving the clock.
 */
const closed = {
  data: {
    open: false,
    now: '2026-09-26T21:15:00+08:00',
    timezone: 'Asia/Manila',
    opens: '08:00',
    closes: '17:00',
    days: [1, 2, 3, 4, 5],
  },
}

test('outside office hours an owner is told they can still file', async ({ page }) => {
  await page.route('**/api/v1/office-hours', (route) => route.fulfill({ json: closed }))
  await page.goto('/login')

  await expect(
    page.getByText('City offices are closed now. You can still file; offices will process it on the next working day.'),
  ).toBeVisible()
  // Not the staff wording: nothing about an owner's sign-in is recorded.
  await expect(page.getByText(/recorded/)).toHaveCount(0)
})

test('outside office hours an officer is told the sign-in is recorded', async ({ page }) => {
  await page.route('**/api/v1/office-hours', (route) => route.fulfill({ json: closed }))
  await page.goto('/staff/login')

  await expect(
    page.getByText(
      'City offices are closed now (Monday to Friday, 8:00 AM to 5:00 PM). Sign-ins outside office hours are recorded in the audit log.',
    ),
  ).toBeVisible()
})

test('during office hours there is no notice, and a dismissed one stays dismissed', async ({ page }) => {
  await page.route('**/api/v1/office-hours', (route) =>
    route.fulfill({ json: { data: { ...closed.data, open: true } } }),
  )
  await page.goto('/login')
  await expect(page.getByRole('button', { name: /dismiss the office hours notice/i })).toHaveCount(0)

  await page.unroute('**/api/v1/office-hours')
  await page.route('**/api/v1/office-hours', (route) => route.fulfill({ json: closed }))
  await page.reload()
  await page.getByRole('button', { name: /dismiss the office hours notice/i }).click()
  await expect(page.getByText(/city offices are closed now/i)).toHaveCount(0)
  await page.reload()
  await expect(page.getByText(/city offices are closed now/i)).toHaveCount(0)
})

test.describe('confirming the address from Profile', () => {
  test.use({ storageState: sessionFor('owner') })

  test('an owner who must confirm sees the code box on Profile, and it goes once confirmed', async ({ page }) => {
    /*
     * Register 1. `email_verification_required` is only ever true while the API
     * has a real mailer, so it is switched on here by amending the real
     * /auth/me answer, and the confirm call is answered in the two shapes
     * AuthController::verifyEmailCode sends (pinned in EmailCodeTest).
     */
    let me: Record<string, unknown> | null = null
    await page.route('**/api/v1/auth/me', async (route) => {
      const real = await route.fetch()
      const body = (await real.json()) as { data: Record<string, unknown> }
      me = body.data
      await route.fulfill({ response: real, json: { data: { ...body.data, email_verification_required: true } } })
    })
    await page.route('**/api/v1/auth/email/verify-code', async (route) => {
      const { code } = route.request().postDataJSON() as { code: string }
      if (code !== '654321') {
        await route.fulfill({
          status: 422,
          json: {
            message: 'That code is not right. You have 4 tries left.',
            errors: { code: ['That code is not right. You have 4 tries left.'] },
          },
        })
        return
      }
      await route.fulfill({
        json: { message: 'Your email address is confirmed.', data: { ...me, email_verification_required: false } },
      })
    })

    await page.goto('/profile')
    await expect(page.getByRole('heading', { name: 'Confirm your email address' })).toBeVisible()

    const code = page.getByRole('textbox', { name: /confirmation code/i })
    await code.fill('111111')
    await page.getByRole('button', { name: /^confirm$/i }).click()
    await expect(page.getByText('That code is not right. You have 4 tries left.')).toBeVisible()

    await code.fill('654321')
    await page.getByRole('button', { name: /^confirm$/i }).click()
    await expect(page.getByRole('heading', { name: 'Confirm your email address' })).toHaveCount(0)
  })

  test('with mail off, as on this stack, Profile asks for nothing', async ({ page }) => {
    await page.goto('/profile')
    await expect(page.getByRole('heading', { name: 'Account details' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Confirm your email address' })).toHaveCount(0)
  })
})
