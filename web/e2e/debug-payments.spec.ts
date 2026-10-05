import { expect, test, type Page } from '@playwright/test'
import { mergedStorageState, sessionFor } from './helpers'
import { gateway, makeBilledApplication, type GatewayState } from './payments'

/*
 * The Debug page's Payments section, and what the owner is told about it
 * (docs/payment-gateway.md).
 *
 * At the defense the gateway takes real payments at ₱1. If a panelist asks to
 * see the real amount, the super admin flips the charge to the full bill from
 * /admin/debug and flips it back after [Ken, 2026-10-04]. These prove the
 * flip happens on screen and on the server, and that the owner's pay screen
 * says nothing about it: it behaves as in production (Ken, 6 October 2026).
 *
 * ── What the stack needs ──────────────────────────────────────────────────
 *
 * The flip itself needs nothing. The owner's half needs KwikPay CONFIGURED on
 * the stack (KWIKPAY_KEY and friends; see payment-gateway.spec.ts), because
 * the test charge only applies while payments are online, and the last test
 * needs the practice KwikPay as well. Without them those tests skip with the reason.
 *
 * Both switches are put back to how this spec found them, whatever happens,
 * so a failure here cannot leave the rest of the suite paying online or at
 * the full bill.
 */

test.describe.configure({ mode: 'serial', timeout: 180_000 })

const SHOTS = process.env.DEBUG_SHOTS_DIR ?? ''

async function shot(page: Page, name: string) {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true })
}

let found: GatewayState | null = null

test.beforeAll(async ({ browser }) => {
  found = (await gateway(browser)).data
})

test.afterAll(async ({ browser }) => {
  if (found) await gateway(browser, { mode: found.mode, charge: found.charge })
})

test.describe('the super admin', () => {
  test.use({ storageState: sessionFor('admin') })

  test('switches what KwikPay collects to the full bill after a confirmation, and back without one', async ({
    page,
    browser,
  }) => {
    const start = await gateway(browser, { charge: 'test' })
    expect(start.ok, start.text).toBe(true)
    const testLabel = `₱${start.data?.test_amount}`

    await page.setViewportSize({ width: 1280, height: 900 })
    await page.goto('/admin/debug')
    await expect(page.getByRole('heading', { name: 'Debug', level: 1 })).toBeVisible({ timeout: 30_000 })
    await expect(page.getByRole('heading', { name: 'Payments', level: 2 })).toBeVisible()

    const testCharge = page.getByRole('button', { name: testLabel, exact: true })
    const fullBill = page.getByRole('button', { name: 'The full bill' })
    await expect(testCharge).toHaveAttribute('aria-pressed', 'true')
    await expect(fullBill).toHaveAttribute('aria-pressed', 'false')
    // The rule the panel needs to hear, said on the screen itself.
    await expect(
      page.getByText('The bill, the receipt and the records always keep the real assessed amount.'),
    ).toBeVisible()
    // Nothing in Payments calls payments simulated or a test (Ken, 5 October
    // 2026). Scoped to the section: Health prints the build's branch name.
    await expect(
      page.getByRole('region', { name: 'Payments' }).getByText(/simulated|test charge|practice kwikpay/i),
    ).toHaveCount(0)
    await shot(page, 'debug-payments-1280')

    // Real money is asked about first, and keeping the test charge changes nothing.
    await fullBill.click()
    const dialog = page.getByRole('dialog', { name: 'Charge owners the full bill?' })
    await expect(dialog).toContainText('Payments already started keep the amount they were opened with.')
    await shot(page, 'debug-payments-confirm-1280')
    await dialog.getByRole('button', { name: `Keep ${testLabel}` }).click()
    await expect(dialog).toHaveCount(0)
    await expect(testCharge).toHaveAttribute('aria-pressed', 'true')
    expect((await gateway(browser)).data?.charge).toBe('test')

    await fullBill.click()
    await dialog.getByRole('button', { name: 'Charge the full bill' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Switched: KwikPay now collects the full bill.' }))
      .toBeVisible()
    await expect(fullBill).toHaveAttribute('aria-pressed', 'true')
    await expect(testCharge).toHaveAttribute('aria-pressed', 'false')
    // The server's answer, not only the screen's.
    expect((await gateway(browser)).data?.charge).toBe('full')
    await page.reload()
    await expect(page.getByRole('button', { name: 'The full bill' })).toHaveAttribute('aria-pressed', 'true')

    // Back to the test charge: no question, because it moves less money.
    await page.getByRole('button', { name: testLabel, exact: true }).click()
    await expect(dialog).toHaveCount(0)
    await expect(
      page.getByRole('status').filter({ hasText: `Switched: KwikPay now collects ₱${start.data?.test_amount} per bill.` }),
    ).toBeVisible()
    expect((await gateway(browser)).data?.charge).toBe('test')

    await page.setViewportSize({ width: 390, height: 844 })
    await page.reload()
    await expect(page.getByRole('heading', { name: 'Payments', level: 2 })).toBeVisible({ timeout: 30_000 })
    // Nothing pushes the page sideways on a phone.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
    expect(overflow).toBeLessThanOrEqual(0)
    await shot(page, 'debug-payments-390')
  })

  test('finds the page on the rail, and at the bare /debug address', async ({ page }) => {
    await page.goto('/debug')
    await expect(page).toHaveURL(/\/admin\/debug$/, { timeout: 30_000 })
    await expect(page.getByRole('heading', { name: 'Debug', level: 1 })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Debug' })).toHaveAttribute('href', '/admin/debug')
  })
})

test.describe('the owner', () => {
  test.use({ storageState: mergedStorageState(['owner.json', 'bplo.json']) })

  test('an office account has no Debug page, and is sent home from its address', async ({ page }) => {
    // This profile also holds BPLO's session, which is the one the staff site uses.
    await page.goto('/staff/admin/debug')
    await expect(page).toHaveURL(/\/staff\/dashboard$/, { timeout: 30_000 })
    await expect(page.getByRole('link', { name: 'Debug' })).toHaveCount(0)
  })

  test('is not told about the test charge, whichever way the switches are', async ({ page, browser }) => {
    const online = await gateway(browser, { mode: 'kwikpay', charge: 'test' })
    test.skip(!online.ok, `KwikPay cannot be turned on on this stack: ${online.text}`)

    const appId = await makeBilledApplication(page)
    await page.setViewportSize({ width: 1280, height: 900 })
    await openPayPage(page, appId)

    // The owner is not told the charge differs from the bill (Ken, 6 October 2026).
    const note = page.getByText(/you will be charged|keep the full amount/i)
    await expect(page.getByRole('button', { name: 'Pay Online' })).toBeVisible()
    await expect(note).toHaveCount(0)
    // Nor the words "test charge" (Ken, 5 October 2026).
    await expect(page.getByText(/test charge/i)).toHaveCount(0)
    await shot(page, 'pay-test-charge-1280')
    await page.setViewportSize({ width: 390, height: 844 })
    await expect(note).toHaveCount(0)
    await shot(page, 'pay-test-charge-390')

    // The full bill: still no note.
    expect((await gateway(browser, { charge: 'full' })).ok).toBe(true)
    await openPayPage(page, appId)
    await expect(note).toHaveCount(0)

    // Simulated collects nothing, so a test charge there is not mentioned,
    // and nothing calls the payment simulated (Ken, 5 October 2026).
    expect((await gateway(browser, { mode: 'simulated', charge: 'test' })).ok).toBe(true)
    await openPayPage(page, appId)
    await expect(page.getByRole('button', { name: 'Pay Online' })).toBeVisible()
    await expect(page.getByText(/simulated|no real charge/i)).toHaveCount(0)
    await expect(note).toHaveCount(0)
  })

  test('with the practice KwikPay, a payment opened at ₱1 still asks for ₱1 after the switch moves', async ({
    page,
    browser,
  }) => {
    const before = await gateway(browser)
    test.skip(!before.data?.kwikpay.fake_available, 'the practice KwikPay is not on for this stack')
    const online = await gateway(browser, { mode: 'kwikpay', charge: 'test' })
    expect(online.ok, online.text).toBe(true)
    const amount = `₱${online.data?.test_amount}`

    const appId = await makeBilledApplication(page)
    await page.setViewportSize({ width: 1280, height: 900 })
    await openPayPage(page, appId)
    await page.getByRole('button', { name: 'QR Ph', exact: true }).click()
    await page.getByRole('button', { name: 'Pay Online' }).click()

    const qr = page.getByRole('img', { name: `QR code to pay ${amount}` })
    await expect(qr).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText(/is recorded in full/)).toHaveCount(0)
    await shot(page, 'pay-test-charge-waiting-1280')

    // The super admin moves to the full bill while this one is waiting.
    expect((await gateway(browser, { charge: 'full' })).ok).toBe(true)
    await page.reload()
    await expect(page.getByRole('img', { name: `QR code to pay ${amount}` })).toBeVisible({ timeout: 30_000 })

    // Paid at the practice gateway for what it asked: ₱1, and the bill is settled.
    const phone = await page.context().newPage()
    await phone.goto(((await qr.getAttribute('src')) ?? '').replace('/qr/', '/pay/'))
    await expect(phone.getByRole('button', { name: `Pay ${amount}` })).toBeVisible()
    await phone.getByRole('button', { name: `Pay ${amount}` }).click()
    await phone.close()
    await expect(page.getByText('Paid', { exact: true })).toBeVisible({ timeout: 30_000 })
  })
})

async function openPayPage(page: Page, appId: number) {
  await page.goto(`/applications/${appId}/pay`)
  await expect(page.getByRole('heading', { name: 'Tax Order of Payment' })).toBeVisible({ timeout: 30_000 })
  await expect(page.getByRole('button', { name: 'GCash', exact: true })).toBeVisible({ timeout: 30_000 })
}
