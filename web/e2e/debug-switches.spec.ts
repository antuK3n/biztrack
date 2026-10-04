import { expect, test, type Browser, type Page } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * The Debug page's System switches, with the panel open, and what the rest of
 * the app shows because of them: the office-hours notice forced either way,
 * and the pretend-date banner on every signed-in screen.
 *
 * Every switch is handed back to the server's own setting afterwards, whatever
 * happened, so a failure here cannot leave the suite with a forced notice or a
 * simulated date.
 */

test.use({ storageState: sessionFor('admin') })
test.describe.configure({ mode: 'serial', timeout: 180_000 })

const SHOTS = process.env.DEBUG_SHOTS_DIR ?? ''

async function shot(page: Page, name: string) {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true })
}

/** Put a switch back to the server's own setting, through the panel's API. */
async function reset(browser: Browser) {
  const context = await browser.newContext({ storageState: sessionFor('admin') })
  const page = await context.newPage()
  await page.goto('/admin/login')
  await page.evaluate(async () => {
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${localStorage.getItem('biztrack.token.admin')}`,
    }
    for (const [name, value] of [
      ['office_hours', 'auto'],
      ['pretend_date', null],
      ['sign_in_codes', 'default'],
      ['captcha', 'default'],
    ]) {
      await fetch('/api/v1/debug/switches', {
        method: 'PUT',
        headers,
        body: JSON.stringify({ switch: name, value }),
      })
    }
  })
  await context.close()
}

test.beforeAll(async ({ browser }) => reset(browser))
test.afterAll(async ({ browser }) => reset(browser))

async function openSwitches(page: Page) {
  await page.goto('/admin/debug#switches')
  await expect(page.getByRole('heading', { name: 'System switches', level: 2 })).toBeVisible({ timeout: 30_000 })
  await expect(page.getByRole('button', { name: 'By the clock' })).toBeVisible({ timeout: 30_000 })
}

test('a switch that cannot go on says why, and stays reachable', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 })
  await openSwitches(page)

  // This stack's mailer delivers to nobody, so sign-in codes cannot go on.
  const codesOn = page.getByRole('button', { name: 'E-mail sign-in codes: On' })
  await expect(codesOn).toHaveAttribute('aria-disabled', 'true')
  await expect(codesOn).toHaveAttribute('aria-describedby', /.+/)
  await expect(page.getByText(/Cannot be turned on: Mail goes nowhere on this server/)).toBeVisible()
  await expect(page.getByRole('button', { name: 'E-mail sign-in codes: Off' })).toHaveAttribute('aria-pressed', 'true')
  await page.locator('#switches').scrollIntoViewIfNeeded()
  await shot(page, 'debug-switches-1280')

  await page.setViewportSize({ width: 390, height: 844 })
  await page.reload()
  await expect(page.getByRole('button', { name: 'By the clock' })).toBeVisible({ timeout: 30_000 })
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
  expect(overflow).toBeLessThanOrEqual(0)
  await shot(page, 'debug-switches-390')
})

test('forces the office-hours notice closed and open for everyone, then hands it back to the clock', async ({
  page,
  browser,
}) => {
  await openSwitches(page)

  await page.getByRole('button', { name: 'Always closed' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'now says closed, whatever the clock says' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Always closed' })).toHaveAttribute('aria-pressed', 'true')

  const owner = await browser.newContext({ storageState: sessionFor('owner') })
  const ownerPage = await owner.newPage()
  await ownerPage.goto('/dashboard')
  await expect(ownerPage.getByText(/City offices are closed now/)).toBeVisible({ timeout: 30_000 })

  await page.getByRole('button', { name: 'Always open' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'now says open, whatever the clock says' })).toBeVisible()
  await ownerPage.reload()
  await expect(ownerPage.getByRole('heading').first()).toBeVisible({ timeout: 30_000 })
  await expect(ownerPage.getByText(/City offices are closed now/)).toHaveCount(0)
  await owner.close()

  await page.getByRole('button', { name: 'By the clock' }).click()
  await expect(page.getByRole('button', { name: 'By the clock' })).toHaveAttribute('aria-pressed', 'true')
})

test('a pretend date puts a banner over every signed-in screen until it is cleared', async ({ page, browser }) => {
  await page.setViewportSize({ width: 1280, height: 900 })
  await openSwitches(page)

  await page.getByLabel('Pretend today is').fill('2027-01-25')
  await page.getByRole('button', { name: 'Use this date' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Renewal dates are now simulated as January 25, 2027.' }))
    .toBeVisible()
  // The Debug page's own screen says it at once, without waiting for a poll.
  const banner = page.getByRole('status').filter({ hasText: 'Renewal dates are being simulated as January 25, 2027.' })
  await expect(banner).toBeVisible()

  // So does an owner's, and an office's.
  for (const account of ['owner', 'bplo'] as const) {
    const context = await browser.newContext({ storageState: sessionFor(account) })
    const other = await context.newPage()
    await other.setViewportSize({ width: account === 'owner' ? 390 : 1280, height: 844 })
    await other.goto(account === 'owner' ? '/permits' : '/staff/dashboard')
    await expect(
      other.getByRole('status').filter({ hasText: 'Renewal dates are being simulated as January 25, 2027.' }),
    ).toBeVisible({ timeout: 30_000 })
    await shot(other, `pretend-date-banner-${account}`)
    await context.close()
  }

  await page.getByRole('button', { name: 'Use the real date' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Renewal dates are real again.' })).toBeVisible()
  await expect(banner).toHaveCount(0)
})
