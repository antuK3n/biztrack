import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { DEMO_PASSWORD } from './helpers'

/*
 * The business owner's home address [checklist 2026-09-28, Register 2 — "Make
 * sure that profile details are complete (like home details)"].
 *
 * Two journeys: a new owner gives it at sign-up, and an owner who registered
 * before it was asked is prompted on Profile and completes it from there. The
 * rules themselves (required parts, ZIP shape, no blanking) are pinned in
 * HomeAddressTest; this file is about whether they reach a reader.
 *
 * Every account here is registered fresh, so nothing leans on — or changes —
 * the seeded owner that other specs sign in as.
 */

function uniqueEmail(tag: string): string {
  return `home-${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`
}

/**
 * Register through the API and keep the token in the citizen site's slot, the
 * way a completed sign-up leaves it. Used where the sign-up form is not what
 * the test is about.
 */
async function registerViaApi(page: Page, email: string) {
  await page.goto('/login')
  const token = await page.evaluate(
    async ([address, password]) => {
      const res = await fetch('/api/v1/auth/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          first_name: 'Lorna',
          last_name: 'Tolentino',
          gender: 'F',
          email: address,
          mobile_number: '09171234567',
          password,
          password_confirmation: password,
          data_privacy_consent: true,
          home_street: '12 Gen. Luna St.',
          home_barangay: 'Longos',
          home_city: 'Malabon',
          home_province: 'Metro Manila',
        }),
      })
      if (!res.ok) throw new Error(`register failed: ${res.status} ${await res.text()}`)
      return (await res.json()).data.token as string
    },
    [email, DEMO_PASSWORD] as const,
  )
  await page.evaluate((t) => localStorage.setItem('biztrack.token.public', t), token)
}

/**
 * Make /auth/me answer as it does for an owner who registered before the
 * address was asked: no address on file, and the flag the API sets for it.
 *
 * Registration cannot produce that account any more — which is the point of
 * the item — so the real answer is fetched and only those fields are replaced.
 * The shape is AuthController::userPayload's; HomeAddressTest pins the server
 * side of it. Saving from Edit Profile still goes to the real endpoint.
 */
async function answerAsOwnerWithoutAddress(page: Page) {
  await page.route('**/api/v1/auth/me', async (route) => {
    const response = await route.fetch()
    const body = await response.json()
    Object.assign(body.data, {
      home_street: null,
      home_barangay: null,
      home_city: null,
      home_province: null,
      home_postal_code: null,
      home_address_missing: true,
    })
    await route.fulfill({ response, json: body })
  })
}

test('a new owner gives their home address at sign-up, and it is on their Profile', async ({ page }) => {
  const email = uniqueEmail('signup')
  await page.goto('/register')

  await page.getByLabel('First Name').fill('Rosa')
  await page.getByLabel('Last Name').fill('Manalo')
  await page.getByLabel('Gender').selectOption('F')
  await page.getByLabel('Email Address').fill(email)
  await page.getByLabel('Contact Number').fill('09171234567')
  await page.getByRole('textbox', { name: 'Password (required)', exact: true }).fill(DEMO_PASSWORD)
  await page.getByRole('textbox', { name: 'Confirm Password (required)', exact: true }).fill(DEMO_PASSWORD)
  await page.getByRole('checkbox', { name: /I agree to the Terms of Use/ }).check()

  // Left blank first: the form must refuse, say which part, and point there.
  await page.getByRole('button', { name: 'Sign Up' }).click()
  const home = page.getByRole('group', { name: 'Home Address' })
  const street = home.getByLabel('House No., Building, Street')
  await expect(street).toHaveAttribute('aria-invalid', 'true')
  await expect(street).toBeFocused()
  await expect(street).toHaveAccessibleDescription('Enter your house number, building and street.')
  await expect(home.getByLabel('Barangay')).toHaveAccessibleDescription('Enter your barangay.')
  // ZIP is the one optional part.
  await expect(home.getByLabel('ZIP Code')).not.toHaveAttribute('aria-invalid', 'true')
  await expect(page).toHaveURL(/\/register/)

  // An owner who lives outside Malabon, with a barangay not on the city's list.
  await street.fill('7 M. Naval St.')
  await home.getByLabel('Barangay').fill('San Roque')
  await home.getByLabel('City or Municipality').fill('Navotas')
  await home.getByLabel('Province').fill('Metro Manila')
  // Digits only, four at most, as they are typed.
  await home.getByLabel('ZIP Code').fill('14a85x9')
  await expect(home.getByLabel('ZIP Code')).toHaveValue('1485')

  await page.getByRole('button', { name: 'Sign Up' }).click()
  await expect(page).toHaveURL(/\/dashboard/, { timeout: 20_000 })
  // Nothing to complete, so no prompt on the home page.
  await expect(page.getByRole('region', { name: /Add your home address/ })).toHaveCount(0)

  await page.goto('/profile')
  const row = page.getByRole('term').filter({ hasText: 'Home address' }).locator('xpath=following-sibling::dd')
  await expect(row).toHaveText('7 M. Naval St., Brgy. San Roque, Navotas, Metro Manila 1485')
  await expect(page.getByRole('region', { name: /Add your home address/ })).toHaveCount(0)
})

test('an owner from before the address was asked is prompted on Profile and completes it', async ({ page }) => {
  await registerViaApi(page, uniqueEmail('older'))
  await answerAsOwnerWithoutAddress(page)

  await page.goto('/profile')
  const prompt = page.getByRole('region', { name: 'Add your home address to complete your profile' })
  await expect(prompt).toBeVisible()
  // Blue and quiet: nothing is wrong and nothing is refused, so it is neither
  // red nor an alert — and it says filing is not held up.
  await expect(prompt).toHaveClass(/blue/)
  await expect(prompt).not.toHaveClass(/red/)
  await expect(page.getByRole('alert')).toHaveCount(0)
  await expect(prompt).toContainText('Your applications are not held up while it is missing.')
  const row = page.getByRole('term').filter({ hasText: 'Home address' }).locator('xpath=following-sibling::dd')
  await expect(row).toHaveText('Not given yet')

  // The home page carries the same prompt.
  await page.goto('/dashboard')
  await expect(page.getByRole('region', { name: 'Add your home address to complete your profile' })).toBeVisible()

  await page.getByRole('link', { name: 'Add home address' }).click()
  await expect(page).toHaveURL(/\/settings\?edit=profile/)
  const dialog = page.getByRole('dialog', { name: 'Edit Profile' })
  await expect(dialog).toBeVisible()

  // Save waits for the address, and says why.
  const save = dialog.getByRole('button', { name: 'Save Changes' })
  await expect(save).toHaveAttribute('aria-disabled', 'true')
  await expect(save).toHaveAccessibleDescription('Fill in the starred parts of your home address to save.')

  const home = dialog.getByRole('group', { name: 'Home Address' })
  await home.getByLabel('House No., Building, Street').fill('Blk 4 Lot 12, Sampaguita St.')
  await home.getByLabel('Barangay').fill('Tonsuya')
  await home.getByLabel('City or Municipality').fill('Malabon')
  await home.getByLabel('Province').fill('Metro Manila')
  await expect(save).not.toHaveAttribute('aria-disabled', 'true')
  await save.click()

  await expect(page.getByRole('status').filter({ hasText: 'Profile changes saved.' })).toBeVisible()
  await expect(dialog).toHaveCount(0)
  // Closing drops ?edit=profile, so a reload does not reopen the dialog.
  await expect(page).toHaveURL(/\/settings$/)

  // Stop pretending: what Profile shows now is what the server stored.
  await page.unroute('**/api/v1/auth/me')
  await page.goto('/profile')
  await expect(row).toHaveText('Blk 4 Lot 12, Sampaguita St., Brgy. Tonsuya, Malabon, Metro Manila')
  await expect(page.getByRole('region', { name: /Add your home address/ })).toHaveCount(0)
})

test('the sign-up form does not scroll sideways on a phone', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 780 })
  await page.goto('/register')
  await expect(page.getByRole('group', { name: 'Home Address' })).toBeVisible()
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  )
  expect(overflow).toBeLessThanOrEqual(0)
})
