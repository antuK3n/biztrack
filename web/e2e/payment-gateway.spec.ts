import { expect, test, type Browser, type Page } from '@playwright/test'
import { mergedStorageState, sessionFor } from './helpers'

/*
 * The owner's pay screen in both payment modes (docs/payment-gateway.md).
 *
 *   simulated  Pay Online → Paid, in one press, exactly as before the switch.
 *   online     Pay Online → the payment page (a link) or a QR code → back to a
 *              screen that waits until the payment service confirms → Paid, or
 *              "did not go through" with Try again.
 *
 * ── What the online half needs from the stack ─────────────────────────────
 *
 * The practice KwikPay (FakeKwikPayController), because no request in this
 * suite may reach the real one. The API has to be started with it on:
 *
 *   APP_ENV=local KWIKPAY_FAKE=true KWIKPAY_MERCHANT=FAKE01 KWIKPAY_KEY=<any>
 *   KWIKPAY_PAYMENT_TYPE=1
 *   KWIKPAY_BASE_URL=http://localhost:<api>/api/v1/fake-kwikpay
 *   KWIKPAY_CALLBACK_BASE_URL=http://localhost:<api>
 *   FRONTEND_URL=<this suite's base URL>
 *   PHP_CLI_SERVER_WORKERS=4   ← the API calls the fake on ITSELF; one worker
 *                                 deadlocks waiting for its own answer
 *
 * Without those the online tests skip with the reason, rather than failing in
 * a way that reads as the product. The simulated test always runs.
 *
 * The switch is flipped through the super admin's API and put back to
 * simulated afterwards, whatever happened, so a failure here cannot leave the
 * rest of the suite paying online.
 *
 * Writes: each test creates and bills its own filing through the API (the same
 * walk clearances.spec.ts takes), so it never pays somebody else's.
 */

test.use({ storageState: mergedStorageState(['owner.json', 'bplo.json']) })
test.describe.configure({ mode: 'serial', timeout: 180_000 })

const SHOTS = process.env.PAY_SHOTS_DIR ?? ''

async function shot(page: Page, name: string) {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true })
}

/** Flip the payment switch as the super admin. Returns the status payload. */
async function setMode(browser: Browser, mode: 'simulated' | 'kwikpay') {
  const context = await browser.newContext({ storageState: sessionFor('admin') })
  const page = await context.newPage()
  await page.goto('/admin/login')
  const result = await page.evaluate(async (m) => {
    const token = localStorage.getItem('biztrack.token.admin')
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    }
    const status = await (await fetch('/api/v1/admin/payment-gateway', { headers })).json()
    const kwik = status.data?.kwikpay
    if (m === 'kwikpay' && !(kwik?.configured && kwik?.fake_available)) {
      return { ok: false, reason: 'the practice KwikPay is not configured on this stack' }
    }
    const res = await fetch('/api/v1/admin/payment-gateway', {
      method: 'PUT',
      headers,
      body: JSON.stringify({ mode: m }),
    })
    return { ok: res.ok, reason: res.ok ? '' : `switching answered ${res.status}: ${await res.text()}` }
  }, mode)
  await context.close()
  return result
}

/**
 * A filing of the owner's that BPLO has approved, so it is waiting for payment.
 * Owner makes and submits it; BPLO classifies and approves (see
 * clearances.spec.ts `makePaidApplication` for why each act is needed).
 */
async function makeBilledApplication(page: Page): Promise<number> {
  await page.goto('/dashboard')
  await expect(page.getByRole('heading').first()).toBeVisible({ timeout: 30_000 })

  return page.evaluate(async () => {
    const owner = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}`,
    }
    const bplo = { ...owner, Authorization: `Bearer ${localStorage.getItem('biztrack.token.staff')}` }
    const call = async (url: string, headers: Record<string, string>, body?: unknown) => {
      const res = await fetch(url, {
        method: body === undefined ? 'GET' : 'POST',
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
      })
      if (!res.ok) throw new Error(`${url} answered ${res.status}: ${await res.text()}`)
      return (await res.json()).data
    }

    const barangays = await call('/api/v1/reference/barangays', owner)
    const psic = (await call('/api/v1/reference/psic-codes', owner)).filter(
      (c: { code: string }) => c.code !== '00000',
    )
    const permitTypes = await call('/api/v1/reference/permit-types', owner)
    const business = await call('/api/v1/businesses', owner, {
      name: `E2E Online Payment ${Date.now()}`,
      registration_type: 'DTI',
      registration_number: 'DTI-E2E-PAY',
      tin: '123-456-789-000',
      address: {
        line1: '3 Playwright St.',
        barangay_id: (barangays.find((b: { name: string }) => b.name === 'Longos') ?? barangays[0]).id,
        latitude: 14.6572,
        longitude: 120.9573,
      },
      emergency_contact_name: 'Ana Dela Cruz',
      emergency_contact_number: '0917 123 4567',
      economic_organization: 'single_establishment',
      capital_investment: 500000,
      lines: [{ psic_code_id: psic[0].id, capitalization: 500000, products_services: 'milk tea' }],
    })
    const app = await call('/api/v1/applications', owner, {
      business_id: business.id,
      application_type: 'new',
      permit_type_ids: [permitTypes.find((pt: { code: string }) => pt.code === 'BUSINESS').id],
      data_privacy_consent: true,
      fee_profile: {
        business_structure: 'sole_proprietorship',
        floor_area_sqm: 120,
        employees: 12,
        employees_in_lgu: 6,
        male_employees: 7,
        female_employees: 5,
        lines: [{ psic_code_id: psic[0].id, category: 'retailer', capitalization: 500000 }],
      },
    })
    await call(`/api/v1/applications/${app.id}/submit`, owner, {})

    const queue = await call(
      '/api/v1/assignments?application_status=for_approval&status=pending&per_page=100',
      bplo,
    )
    const assignment = queue.find(
      (row: { application: { id: number } | null }) => row.application?.id === app.id,
    )
    if (!assignment) throw new Error(`filing ${app.id} is not on BPLO's queue`)
    await call(`/api/v1/assignments/${assignment.id}/classification`, bplo, { tier: 'simple' })
    await call(`/api/v1/assignments/${assignment.id}/approve`, bplo, {})

    return app.id as number
  })
}

async function openPayPage(page: Page, appId: number) {
  await page.goto(`/applications/${appId}/pay`)
  await expect(page.getByRole('heading', { name: 'Tax Order of Payment' })).toBeVisible({
    timeout: 30_000,
  })
  // The chips come from the server; wait for them rather than the skeleton.
  await expect(page.getByRole('button', { name: 'GCash', exact: true })).toBeVisible({ timeout: 30_000 })
}

test.afterAll(async ({ browser }) => {
  await setMode(browser, 'simulated')
})

test('simulated: Pay Online is paid in the same press, and says no real charge is made', async ({
  page,
  browser,
}) => {
  const switched = await setMode(browser, 'simulated')
  expect(switched.ok, switched.reason).toBe(true)

  const appId = await makeBilledApplication(page)
  await openPayPage(page, appId)

  // The simulated list, from the server: Card, and none of the online-only channels.
  await expect(page.getByRole('button', { name: 'Card', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'QR Ph', exact: true })).toHaveCount(0)
  await expect(page.getByText('This is a simulated payment. No real charge is made.')).toBeVisible()
  await shot(page, 'simulated-choose')

  await page.getByRole('button', { name: 'Pay Online' }).click()
  await expect(page.getByText('Paid', { exact: true })).toBeVisible({ timeout: 30_000 })
  await shot(page, 'simulated-paid')
})

test.describe('online payment through the practice KwikPay', () => {
  test.beforeEach(async ({ browser }) => {
    const switched = await setMode(browser, 'kwikpay')
    test.skip(!switched.ok, switched.reason)
  })

  test('a link: waiting, then not paid, then paid on the second try', async ({ page }) => {
    const appId = await makeBilledApplication(page)
    await openPayPage(page, appId)

    // The online list: no Card, and the two channels only KwikPay has.
    await expect(page.getByRole('button', { name: 'Card', exact: true })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'QR Ph', exact: true })).toBeVisible()
    await expect(page.getByText('This is a simulated payment')).toHaveCount(0)
    await shot(page, 'online-choose')

    // Sent to the payment page…
    await page.getByRole('button', { name: 'GoTyme', exact: true }).click()
    await page.getByRole('button', { name: 'Pay Online' }).click()
    await expect(page).toHaveURL(/\/fake-kwikpay\/pay\//, { timeout: 30_000 })

    // …and back without paying: the payment is still in flight, and resumed.
    await openPayPageWaiting(page, appId)
    await expect(page.getByText('Waiting for your payment')).toBeVisible()
    await expect(page.getByRole('link', { name: 'Open the payment page' })).toBeVisible()
    await shot(page, 'online-waiting-link')

    // Fail it at the payment service.
    await page.getByRole('link', { name: 'Open the payment page' }).click()
    await page.getByRole('button', { name: 'Fail this payment' }).click()
    await expect(page).toHaveURL(new RegExp(`/applications/${appId}/pay\\?payment=\\d+`), {
      timeout: 30_000,
    })
    await expect(page.getByText('Payment did not go through')).toBeVisible({ timeout: 30_000 })
    await shot(page, 'online-failed')

    // Try again is a new order, and this time it is paid.
    await page.getByRole('button', { name: 'Try again' }).click()
    await expect(page.getByRole('button', { name: 'GCash', exact: true })).toBeVisible({ timeout: 30_000 })
    await page.getByRole('button', { name: 'GCash', exact: true }).click()
    await page.getByRole('button', { name: 'Pay Online' }).click()
    await expect(page).toHaveURL(/\/fake-kwikpay\/pay\//, { timeout: 30_000 })
    await page.getByRole('button', { name: /^Pay / }).click()
    await expect(page.getByText('Paid', { exact: true })).toBeVisible({ timeout: 30_000 })
    await shot(page, 'online-paid')
  })

  test('a QR code: the screen moves on by itself once the payment is made elsewhere', async ({
    page,
    context,
  }) => {
    const appId = await makeBilledApplication(page)
    await openPayPage(page, appId)

    await page.getByRole('button', { name: 'QR Ph', exact: true }).click()
    await page.getByRole('button', { name: 'Pay Online' }).click()

    // Stays on our page and shows the code to scan.
    const qr = page.getByRole('img', { name: /QR code to pay/ })
    await expect(qr).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText(/Scan this code with your GCash, Maya or bank app/)).toBeVisible()
    await shot(page, 'online-waiting-qr')

    // "Scanning" it: the code encodes the practice payment page. Fail first.
    const payPage = (src: string) => src.replace('/qr/', '/pay/')
    const phone = await context.newPage()
    await phone.goto(payPage((await qr.getAttribute('src')) ?? ''))
    await phone.getByRole('button', { name: 'Fail this payment' }).click()

    // The owner's screen was never touched; polling brings the answer.
    await expect(page.getByText('Payment did not go through')).toBeVisible({ timeout: 30_000 })

    await page.getByRole('button', { name: 'Try again' }).click()
    await page.getByRole('button', { name: 'QR Ph', exact: true }).click()
    await page.getByRole('button', { name: 'Pay Online' }).click()
    await expect(qr).toBeVisible({ timeout: 30_000 })

    await phone.goto(payPage((await qr.getAttribute('src')) ?? ''))
    await phone.getByRole('button', { name: /^Pay / }).click()
    await phone.close()

    await expect(page.getByText('Paid', { exact: true })).toBeVisible({ timeout: 30_000 })
  })
})

test.describe('paying a different way', () => {
  test.beforeEach(async ({ browser }) => {
    const switched = await setMode(browser, 'kwikpay')
    test.skip(!switched.ok, switched.reason)
  })

  test('set aside after a warning, pay another way, and a late first payment shows as paid twice', async ({
    page,
    context,
  }) => {
    const appId = await makeBilledApplication(page)
    await openPayPage(page, appId)

    // Start with Maya, then leave the payment page without paying.
    await page.getByRole('button', { name: 'Maya', exact: true }).click()
    await page.getByRole('button', { name: 'Pay Online' }).click()
    await expect(page).toHaveURL(/\/fake-kwikpay\/pay\//, { timeout: 30_000 })
    const firstPaymentPage = page.url()
    await openPayPageWaiting(page, appId)

    // The escape asks first, and "Keep waiting" changes nothing.
    await page.getByRole('button', { name: 'Pay a different way' }).click()
    const dialog = page.getByRole('dialog', { name: 'Pay a different way?' })
    await expect(dialog).toBeVisible()
    await expect(dialog).toContainText(
      'If you already paid with Maya, wait for it to be confirmed instead — paying again could charge you twice.',
    )
    await shot(page, 'online-pay-differently-dialog')
    await dialog.getByRole('button', { name: 'Keep waiting' }).click()
    await expect(dialog).toHaveCount(0)
    await expect(page.getByText('Waiting for your payment')).toBeVisible()

    // Confirmed: the practice gateway still says "waiting", so it is set aside.
    await page.getByRole('button', { name: 'Pay a different way' }).click()
    await dialog.getByRole('button', { name: "I haven't paid — choose another way" }).click()
    await expect(page.getByRole('heading', { name: 'Tax Order of Payment' })).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText(/is set aside\. If it goes through after all, BPLO will contact you/)).toBeVisible()
    await shot(page, 'online-set-aside')

    // Pay with GCash, a new order, and it goes through.
    await page.getByRole('button', { name: 'GCash', exact: true }).click()
    await page.getByRole('button', { name: 'Pay Online' }).click()
    await expect(page).toHaveURL(/\/fake-kwikpay\/pay\//, { timeout: 30_000 })
    expect(page.url(), 'the second payment reused the first order').not.toBe(firstPaymentPage)
    await page.getByRole('button', { name: /^Pay / }).click()
    await expect(page.getByText('Paid', { exact: true })).toBeVisible({ timeout: 30_000 })

    // Then the Maya payment goes through after all: history says paid twice.
    const late = await context.newPage()
    await late.goto(firstPaymentPage)
    await late.getByRole('button', { name: /^Pay / }).click()
    await late.close()

    await page.goto('/profile?tab=payments')
    await expect(page.getByText(/paid twice, BPLO will contact you about a refund/)).toBeVisible({
      timeout: 30_000,
    })
  })
})

/** Back on the pay screen with a payment in flight: it resumes, not re-offers. */
async function openPayPageWaiting(page: Page, appId: number) {
  await page.goto(`/applications/${appId}/pay`)
  await expect(page.getByText('Waiting for your payment')).toBeVisible({ timeout: 30_000 })
}
