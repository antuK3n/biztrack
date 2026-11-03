import { expect, test, type Page } from '@playwright/test'
import { mergedStorageState, sessionFor } from './helpers'
import { makeBilledApplication } from './payments'

/*
 * AUDIT LOGS — "Payments", and the receipt photo on a payment row.
 *
 * Not stubbed: the owner sends a real photo of the receipt, BPLO marks the
 * filing paid at the counter against it, and the super admin finds that
 * payment under Payments and opens its Details to see the picture.
 *
 * What is pinned beyond that is the cost of looking. Every fetch of a receipt
 * writes a `file.downloaded` row (App\Support\DownloadLog), so the list must
 * fetch none and an opened Details exactly one, however often the list is
 * loaded and however often View is pressed while it stays open. Pest covers
 * which actions the filter holds and which receipts each row names.
 */
test.describe.configure({ timeout: 180_000 })
test.use({ storageState: mergedStorageState(['owner.json', 'bplo.json']) })

const SHOTS = process.env.E2E_SHOTS_DIR

/**
 * The owner sends a PNG of the receipt and the BPLO officer holding the filing
 * marks it paid at the counter. Answers the payment and the proof.
 *
 * The picture is drawn on a canvas rather than shipped as a fixture: a real
 * PNG, which the API sniffs (`mimes:pdf,jpg,jpeg,png`) and the screen draws.
 */
async function paidAtCounterWithPhoto(page: Page, appId: number, orNumber: string) {
  return page.evaluate(
    async ([id, or]) => {
      const canvas = document.createElement('canvas')
      canvas.width = 360
      canvas.height = 520
      const ctx = canvas.getContext('2d')!
      ctx.fillStyle = '#fbfaf5'
      ctx.fillRect(0, 0, 360, 520)
      ctx.fillStyle = '#3242ca'
      ctx.fillRect(24, 28, 312, 36)
      ctx.fillStyle = '#c9c6bb'
      for (let y = 96; y < 440; y += 28) ctx.fillRect(24, y, y % 56 === 0 ? 200 : 312, 10)
      ctx.fillStyle = '#1b1b1f'
      ctx.fillRect(220, 460, 116, 24)
      const png = await new Promise<Blob>((resolve) => canvas.toBlob((b) => resolve(b!), 'image/png'))

      const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })
      const owner = { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}` }
      const bplo = { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.staff')}` }

      const receipt = new FormData()
      receipt.append('or_number', or)
      receipt.append('or_date', today)
      receipt.append('photo', new File([png], `receipt-${or}.png`, { type: 'image/png' }))
      const sent = await fetch(`/api/v1/applications/${id}/payment-proofs`, { method: 'POST', headers: owner, body: receipt })
      if (!sent.ok) throw new Error(`payment-proofs answered ${sent.status}: ${await sent.text()}`)
      const proof = (await sent.json()).data

      const body = new FormData()
      body.append('or_number', or)
      body.append('or_date', today)
      const paid = await fetch(`/api/v1/applications/${id}/counter-payment`, { method: 'POST', headers: bplo, body })
      if (!paid.ok) throw new Error(`counter-payment answered ${paid.status}: ${await paid.text()}`)

      return { paymentId: (await paid.json()).data.id as number, proofId: proof.id as number }
    },
    [appId, orNumber] as const,
  )
}

/** How many times this receipt has been served, read off the trail as the super admin. */
async function timesServed(page: Page, proofId: number): Promise<number> {
  return page.evaluate(async (id) => {
    const query = new URLSearchParams({
      action: 'file.downloaded',
      auditable_type: 'PaymentProof',
      auditable_id: String(id),
      per_page: '1',
    })
    const res = await fetch(`/api/v1/admin/audit-logs?${query}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.admin')}` },
    })
    if (!res.ok) throw new Error(`audit-logs answered ${res.status}`)
    return (await res.json()).meta.total as number
  }, proofId)
}

test('a counter payment is under Payments, and its Details shows the receipt, fetched once', async ({ page, browser }) => {
  const appId = await makeBilledApplication(page)
  const orNumber = String(Date.now() % 10_000_000).padStart(7, '0')
  const { paymentId, proofId } = await paidAtCounterWithPhoto(page, appId, orNumber)

  const admin = await browser.newContext({ storageState: sessionFor('admin'), viewport: { width: 1440, height: 900 } })
  const adminPage = await admin.newPage()
  const fetches: string[] = []
  adminPage.on('request', (req) => {
    if (req.url().includes(`/api/v1/payment-proofs/${proofId}/file`)) fetches.push(req.url())
  })

  await adminPage.goto('/admin/audit-logs')
  await expect(adminPage.getByRole('heading', { name: 'Audit Logs' })).toBeVisible({ timeout: 30_000 })

  // Between All actions and Downloaded a file, and reached from the keyboard like them.
  await expect(adminPage.locator('button[aria-pressed]')).toHaveText(['All actions', 'Payments', 'Removed', 'Downloaded a file'])
  const filter = adminPage.getByRole('button', { name: 'Payments', exact: true })
  await adminPage.getByRole('button', { name: 'All actions', exact: true }).focus()
  await adminPage.keyboard.press('Tab')
  await expect(filter).toBeFocused()

  const asked = adminPage.waitForRequest((req) => req.url().includes('/admin/audit-logs') && req.url().includes('group=payments'))
  await adminPage.keyboard.press('Enter')
  await asked
  await expect(filter).toHaveAttribute('aria-pressed', 'true')

  // The row this payment wrote, by its action key, its actor and its subject.
  const row = adminPage.getByRole('row').filter({ hasText: `Payment #${paymentId}` })
  await expect(row).toHaveCount(1)
  await expect(row.getByRole('cell').nth(1)).toHaveText('payment.completed')
  await expect(row.getByRole('cell').nth(2)).toHaveText('Liza Reyes')
  // Every row under Payments is a payment, a receipt or a payment switch.
  for (const action of await adminPage.getByRole('row').locator('td:nth-child(2)').allInnerTexts()) {
    expect(action.trim()).toMatch(/^(payment|payment_proof|payment_gateway)\.|^debug\.payments$/)
  }

  // Loading the list, again and again, fetches no receipt.
  await adminPage.getByRole('button', { name: 'All actions', exact: true }).click()
  await filter.click()
  await expect(row).toHaveCount(1)
  await adminPage.reload()
  await adminPage.getByRole('button', { name: 'Payments', exact: true }).click()
  await expect(row).toHaveCount(1)
  expect(fetches).toHaveLength(0)
  expect(await timesServed(adminPage, proofId)).toBe(0)
  // Settled: the pills' colour transition finished and no focus ring on the one pressed.
  await adminPage.getByRole('button', { name: 'Payments', exact: true }).blur()
  if (SHOTS) await adminPage.screenshot({ path: `${SHOTS}/audit-payments-a-list.png`, animations: 'disabled' })

  // Details draws the picture under the row's changes.
  await row.getByRole('button', { name: `Details for payment.completed on Payment #${paymentId}` }).click()
  const picture = adminPage.getByRole('img', { name: 'Photo of the receipt' })
  await expect(picture).toBeVisible()
  await expect.poll(() => picture.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBe(360)
  const box = await picture.boundingBox()
  expect(Math.round(box!.height)).toBe(240)
  await expect(adminPage.getByText(`receipt-${orNumber}.png`)).toBeVisible()
  await picture.scrollIntoViewIfNeeded()
  if (SHOTS) await adminPage.screenshot({ path: `${SHOTS}/audit-payments-b-receipt.png`, animations: 'disabled' })

  // View opens the same copy full size, in a tab: no second fetch.
  const view = adminPage.getByRole('button', { name: `View receipt-${orNumber}.png` })
  const tab = admin.waitForEvent('page')
  await view.click()
  const opened = await tab
  await expect.poll(() => opened.url()).toMatch(/^blob:/)
  await opened.close()

  // One opening, one row on the trail and one fetch — not one per list load.
  expect(fetches).toHaveLength(1)
  await expect.poll(() => timesServed(adminPage, proofId)).toBe(1)

  await admin.close()
})
