import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { DEMO_PASSWORD, sessionFor } from './helpers'

/*
 * Changing a password from Settings [checklist 2026-09-27, Edit Settings;
 * closes View Profile 3].
 *
 * The e2e stack runs with mail OFF, as the demo does, so the real API asks for
 * the current password and nothing else — the first test drives that path end
 * to end on a freshly registered owner, so the seeded owner every other spec
 * signs in as keeps `biztrack1`.
 *
 * The code step only exists with mail on. It is driven the way
 * sign-in-notices.spec.ts drives the sign-in code: the real /auth/me answer is
 * amended to say a code is required, and the two password endpoints are
 * answered in the shapes AuthController sends (pinned server-side in
 * PasswordChangeCodeTest). Those tests never reach the real PUT, so the seeded
 * password is untouched.
 */

async function openDialog(page: Page) {
  await page.goto('/settings')
  await page.getByRole('button', { name: 'Reset Password' }).click()
  const dialog = page.getByRole('dialog', { name: 'Change Password' })
  await expect(dialog).toBeVisible()
  return dialog
}

async function fillPasswords(page: Page, current: string, next: string) {
  await page.getByLabel(/^current password/i).fill(current)
  await page.getByLabel(/^enter new password/i).fill(next)
  await page.getByLabel(/^confirm new password/i).fill(next)
}

test('with mail off, the current password alone changes it', async ({ page }) => {
  const email = `pw-change-${Date.now()}@example.com`
  const next = 'a-new-pass-123'

  await page.goto('/login')
  const token = await page.evaluate(
    async ([address, password]) => {
      const res = await fetch('/api/v1/auth/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          first_name: 'Pat',
          last_name: 'Changer',
          gender: 'F',
          email: address,
          mobile_number: '09171234567',
          password,
          password_confirmation: password,
          data_privacy_consent: true,
          // Owners give a home address at sign-up (checklist Register 2).
          home_street: '12 Gen. Luna St.',
          home_barangay: 'Longos',
          home_city: 'Malabon',
          home_province: 'Metro Manila',
        }),
      })
      if (!res.ok) throw new Error(`register failed: ${res.status} ${await res.text()}`)
      return ((await res.json()) as { data: { token: string } }).data.token
    },
    [email, DEMO_PASSWORD] as const,
  )
  await page.evaluate((t) => localStorage.setItem('biztrack.token.public', t), token)

  let codeRequested = false
  page.on('request', (r) => {
    if (r.url().includes('/api/v1/auth/password/code')) codeRequested = true
  })

  const dialog = await openDialog(page)
  // Nothing about a code while mail is off.
  await expect(dialog.getByText(/code/i)).toHaveCount(0)

  await fillPasswords(page, DEMO_PASSWORD, next)
  await dialog.getByRole('button', { name: 'Save Changes' }).click()

  await expect(page.getByRole('status').filter({ hasText: 'Password updated.' })).toBeVisible()
  await expect(dialog).toHaveCount(0)
  expect(codeRequested).toBe(false)

  /*
   * The new password is the stored one: the API accepts it as the CURRENT
   * password for a change straight back. Proved on this endpoint rather than
   * by signing in, because /auth/login shares a ten-a-minute budget with
   * auth.spec.ts and the setup project, and a 429 there would say nothing
   * about this change. The account is a throwaway either way.
   */
  const back = await page.evaluate(
    async ([t, current, password]) =>
      (
        await fetch('/api/v1/auth/password', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${t}` },
          body: JSON.stringify({ current_password: current, password, password_confirmation: password }),
        })
      ).status,
    [token, next, DEMO_PASSWORD] as const,
  )
  expect(back).toBe(200)
})

test.describe('with a code required', () => {
  test.use({ storageState: sessionFor('owner') })

  test('the dialog sends a code, refuses a wrong one, and changes the password with the right one', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 375, height: 740 })
    await page.route('**/api/v1/auth/me', async (route) => {
      const real = await route.fetch()
      const body = (await real.json()) as { data: Record<string, unknown> }
      await route.fulfill({ response: real, json: { data: { ...body.data, password_change_code_required: true } } })
    })

    const codeRequests: { current_password: string }[] = []
    await page.route('**/api/v1/auth/password/code', async (route) => {
      const sent = route.request().postDataJSON() as { current_password: string }
      codeRequests.push(sent)
      if (sent.current_password !== DEMO_PASSWORD) {
        await route.fulfill({
          status: 422,
          json: {
            message: 'Your current password is incorrect.',
            errors: { current_password: ['Your current password is incorrect.'] },
          },
        })
        return
      }
      await route.fulfill({
        json: {
          message: 'We sent a 6-digit code to o••••@biztrack.local.',
          data: { code_required: true, email: 'o••••@biztrack.local', expires_in_minutes: 10, resend_after: 60 },
        },
      })
    })

    const changes: { code?: string }[] = []
    await page.route('**/api/v1/auth/password', async (route) => {
      const sent = route.request().postDataJSON() as { code?: string; current_password: string }
      changes.push(sent)
      expect(sent.current_password).toBe(DEMO_PASSWORD)
      if (sent.code?.replace(/\s/g, '') !== '246810') {
        await route.fulfill({
          status: 422,
          json: {
            message: 'That code is not right. You have 4 tries left.',
            errors: { code: ['That code is not right. You have 4 tries left.'] },
          },
        })
        return
      }
      await route.fulfill({ json: { message: 'Password updated. Other signed-in devices have been logged out.' } })
    })

    const dialog = await openDialog(page)

    // A wrong current password is answered on its own field, and no code goes.
    await fillPasswords(page, 'not-my-password', 'a-new-pass-123')
    await dialog.getByRole('button', { name: 'Send Code' }).click()
    const current = page.getByLabel(/^current password/i)
    await expect(dialog.getByText('Your current password is incorrect.')).toBeVisible()
    await expect(current).toHaveAttribute('aria-invalid', 'true')
    await expect(current).toHaveAttribute('aria-describedby', 'settings-current-error')

    await current.fill(DEMO_PASSWORD)
    await dialog.getByRole('button', { name: 'Send Code' }).click()
    await expect(dialog.getByText('o••••@biztrack.local')).toBeVisible()
    await expect(dialog.getByRole('button', { name: /send a new code in \d+s/i })).toHaveAttribute(
      'aria-disabled',
      'true',
    )

    const code = dialog.getByRole('textbox', { name: /code from the e-mail/i })
    await expect(code).toHaveAttribute('autocomplete', 'one-time-code')

    // Nothing sideways on a phone with the code step open.
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(0)

    await code.fill('111111')
    await dialog.getByRole('button', { name: 'Change Password' }).click()
    await expect(dialog.getByText('That code is not right. You have 4 tries left.')).toBeVisible()
    await expect(code).toHaveAttribute('aria-invalid', 'true')

    await code.fill('246 810')
    await dialog.getByRole('button', { name: 'Change Password' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Password updated.' })).toBeVisible()
    await expect(dialog).toHaveCount(0)

    expect(codeRequests).toHaveLength(2)
    expect(changes.map((c) => c.code)).toEqual(['111111', '246 810'])
  })

  test('a page that did not know a code was needed moves to the code step when the API asks for one', async ({
    page,
  }) => {
    // /auth/me is left as the stack answers it (mail off, no code), and the
    // PUT is answered as the API would once mail had been switched on.
    await page.route('**/api/v1/auth/password', (route) =>
      route.fulfill({
        status: 422,
        json: {
          message: 'Enter the 6-digit code we e-mailed you. Press Send code if you do not have one.',
          errors: { code: ['Enter the 6-digit code we e-mailed you. Press Send code if you do not have one.'] },
        },
      }),
    )

    const dialog = await openDialog(page)
    await fillPasswords(page, DEMO_PASSWORD, 'a-new-pass-123')
    await dialog.getByRole('button', { name: 'Save Changes' }).click()

    await expect(dialog.getByRole('alert')).toHaveText(
      'Changing your password now needs a code by e-mail. Press Send Code.',
    )
    await expect(dialog.getByRole('button', { name: 'Send Code' })).toBeVisible()
  })
})
