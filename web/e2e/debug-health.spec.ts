import { expect, test, type Page } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * The Debug page's Health section, with the panel open (the stack has it
 * opened with `php artisan biztrack:debug-panel on`, or runs APP_ENV=local).
 *
 * Read-only apart from the test e-mail, which goes to the super admin's own
 * address — and on a stack whose mailer is `log`, to nobody, which is what
 * the section has to say rather than claim it was sent.
 */

test.use({ storageState: sessionFor('admin') })

const SHOTS = process.env.DEBUG_SHOTS_DIR ?? ''

async function shot(page: Page, name: string) {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true })
}

test('lists every check with its state in words and when it was seen, and refreshes on request', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 900 })
  await page.goto('/admin/debug#health')
  await expect(page.getByRole('heading', { name: 'Health', level: 2 })).toBeVisible({ timeout: 30_000 })

  const checks = page.getByRole('list', { name: 'Health checks' }).getByRole('listitem')
  await expect(checks).toHaveCount(10, { timeout: 30_000 })
  for (const label of [
    'Database',
    'Migrations',
    'Scheduler',
    'Queue worker',
    'Failed jobs',
    'E-mail',
    'KwikPay connection',
    'Payment checks',
    'Version',
    'Disk space',
  ]) {
    const row = checks.filter({ hasText: label })
    // The state is a word, not only a colour.
    await expect(row.first()).toContainText(/OK|Warning|Failing/)
    await expect(row.first()).toContainText(/Seen |Never seen/)
  }
  await expect(checks.filter({ hasText: 'Database' })).toContainText('OK')
  await expect(page.getByRole('status').filter({ hasText: /checks/ })).toContainText('Checked ')
  await page.locator('#health').scrollIntoViewIfNeeded()
  await shot(page, 'debug-health-1280')

  await page.getByRole('button', { name: 'Refresh' }).last().click()
  await expect(checks).toHaveCount(10)

  await page.setViewportSize({ width: 390, height: 844 })
  await page.reload()
  await expect(checks).toHaveCount(10, { timeout: 30_000 })
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
  expect(overflow).toBeLessThanOrEqual(0)
  await shot(page, 'debug-health-390')
})

test('the test e-mail says plainly whether it reached an inbox', async ({ page }) => {
  await page.goto('/admin/debug#health')
  const mail = page.getByRole('list', { name: 'Health checks' }).getByRole('listitem').filter({ hasText: 'E-mail' })
  await expect(mail).toBeVisible({ timeout: 30_000 })

  await mail.getByRole('button', { name: 'Send a test e-mail to me' }).click()
  // Either answer is honest; what matters is that one of them is given.
  await expect(mail.getByRole('status')).toContainText(/Sent to .+@|Not sent: the mail driver is/, { timeout: 30_000 })
})
