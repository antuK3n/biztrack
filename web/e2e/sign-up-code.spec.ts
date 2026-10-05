import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import fs from 'node:fs'
import { DEMO_PASSWORD } from './helpers'

/*
 * The address is confirmed at sign-up, not on the application [Ken,
 * 6 October 2026: "Upon signing up, the confirm email address should already
 * be asked. It shouldn't be on the application, but when signing up."].
 *
 * With a real mailer, /auth/register answers with the code step instead of a
 * session, and the page goes straight to the sign-in page's code step; typing
 * the code confirms the address and signs the new owner in. Every rule behind
 * it (no session before the code, wrong codes, resend, expiry, the next
 * sign-in asking again) is pinned server-side in EmailCodeTest.
 *
 * The e2e stack runs with mail OFF, as the demo does, so the first test
 * replaces the register answer with the shape AuthController sends when mail
 * is on, as sign-in-notices.spec.ts does for the sign-in code, and the second
 * pins that mail off still signs the owner straight in. The third runs the
 * real thing, only on a stack raised with a mailer that counts as on but
 * whose messages land in a log (see its note); each side skips on the other
 * kind of stack.
 */

function uniqueEmail(tag: string): string {
  return `signup-${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`
}

async function fillSignUp(page: Page, email: string) {
  await page.goto('/register')
  await page.getByLabel('First Name').fill('Rosa')
  await page.getByLabel('Last Name').fill('Manalo')
  await page.getByLabel('Gender').selectOption('F')
  await page.getByLabel('Email Address').fill(email)
  await page.getByLabel('Contact Number').fill('09171234567')
  const home = page.getByRole('group', { name: 'Home Address' })
  await home.getByLabel('House No., Building, Street').fill('30 Rizal Ave.')
  await home.getByLabel('Barangay').selectOption('Tonsuya')
  await page.getByRole('textbox', { name: 'Password (required)', exact: true }).fill(DEMO_PASSWORD)
  await page.getByRole('textbox', { name: 'Confirm Password (required)', exact: true }).fill(DEMO_PASSWORD)
  await page.getByRole('checkbox', { name: /I agree to the Terms of Use/ }).check()
}

test('with mail on, sign-up asks for the e-mailed code next, and the code signs the new owner in', async ({ page }) => {
  // Its right code is stood in for by a password sign-in, which a mail-on
  // stack answers with a code of its own; the test below covers that stack.
  if (process.env.E2E_MAIL_LOG) test.skip(true, 'this stack has mail on')
  const email = uniqueEmail('mocked')
  // The account is really created; only the answer is swapped for the mail-on one.
  await page.route('**/api/v1/auth/register', async (route) => {
    const real = await route.fetch()
    expect(real.status()).toBe(201)
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          code_required: true,
          challenge: 'e2e-sign-up',
          email: 's••••@example.test',
          expires_in_minutes: 30,
          resend_after: 60,
        },
      }),
    })
  })
  // The right code is answered by the REAL password sign-in, so what lands at
  // the end is a working session for the account just made.
  await page.route('**/api/v1/auth/login/code', async (route) => {
    const sent = route.request().postDataJSON() as { challenge: string; code: string }
    expect(sent.challenge).toBe('e2e-sign-up')
    if (sent.code !== '246810') {
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
      postData: JSON.stringify({ email, password: DEMO_PASSWORD, portal: 'public' }),
    })
    await route.fulfill({ response: real })
  })

  await fillSignUp(page, email)
  await page.getByRole('button', { name: 'Sign Up' }).click()

  await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible()
  await expect(page.getByText('We sent a 6-digit code to s••••@example.test. It works for 30 minutes.')).toBeVisible()
  await expect(page.getByRole('button', { name: /send a new code in \d+s/i })).toHaveAttribute('aria-disabled', 'true')

  // Labelled as the confirmation it is, not as a sign-in [Ken, 6 October 2026].
  await expect(page.getByRole('textbox', { name: /sign-in code/i })).toHaveCount(0)
  const code = page.getByRole('textbox', { name: /confirmation code/i })
  await code.fill('000000')
  await page.getByRole('button', { name: /^confirm$/i }).click()
  await expect(page.getByText('That code is not right. You have 4 tries left.')).toBeVisible()

  await code.fill('246810')
  await page.getByRole('button', { name: /^confirm$/i }).click()
  await expect(page).toHaveURL(/\/dashboard/, { timeout: 20_000 })
})

test('with mail off, as on this stack, sign-up signs the owner straight in', async ({ page }) => {
  if (process.env.E2E_MAIL_LOG) test.skip(true, 'this stack has mail on')
  await fillSignUp(page, uniqueEmail('mail-off'))
  await page.getByRole('button', { name: 'Sign Up' }).click()
  await expect(page).toHaveURL(/\/dashboard/, { timeout: 20_000 })
})

/*
 * The real path, end to end. Needs a stack whose mailer counts as on
 * (EmailSwitch) but writes to the log, e.g.
 *
 *   MAIL_MAILER=failover MAIL_HOST=127.0.0.1 MAIL_PORT=9 bash web/scripts/e2e-stack.sh
 *
 * (smtp refuses at once and the failover falls through to `log`), and
 * E2E_MAIL_LOG pointing at that API's storage/logs/laravel.log. The code is
 * read off the last message to the new address, as a reader would read it
 * off the e-mail.
 */
test('with a real mailer, the code from the confirmation e-mail finishes the sign-up', async ({ page }) => {
  const log = process.env.E2E_MAIL_LOG
  test.skip(!log, 'needs a stack with mail on and E2E_MAIL_LOG set')

  const email = uniqueEmail('real')
  await fillSignUp(page, email)
  await page.getByRole('button', { name: 'Sign Up' }).click()

  await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible({ timeout: 20_000 })
  await expect(page.getByText(/It works for 30 minutes\./)).toBeVisible()

  const text = fs.readFileSync(log as string, 'utf8')
  const message = text.slice(text.lastIndexOf(email))
  const found = message.match(/Confirm your email address[\s\S]*?^\s+(\d{6})\s*$/m)
  expect(found, 'the confirmation e-mail in the log').not.toBeNull()

  await page.getByRole('textbox', { name: /confirmation code/i }).fill((found as RegExpMatchArray)[1])
  await page.getByRole('button', { name: /^confirm$/i }).click()
  await expect(page).toHaveURL(/\/dashboard/, { timeout: 20_000 })
})
