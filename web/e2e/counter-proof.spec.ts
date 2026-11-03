import { expect, test } from '@playwright/test'
import { mergedStorageState } from './helpers'
import { makeBilledApplication } from './payments'

/*
 * The owner's proof of a payment made at the counter (thesis panel revisions,
 * matrix item 4): after choosing Over the Counter and paying at City Hall, the
 * owner sends the OR number and the date paid, a photo of the receipt, or
 * both. Sending marks nothing paid; sending again replaces the proof BPLO has
 * not confirmed yet.
 */
test.describe.configure({ timeout: 180_000 })
test.use({ storageState: mergedStorageState(['owner.json', 'bplo.json']) })

const SHOTS = process.env.E2E_SHOTS_DIR

/** A real one-page PDF; the API sniffs the bytes, not the name. */
const RECEIPT = { name: 'receipt.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF\n') }

function yesterday(): string {
  const d = new Date(Date.now() - 24 * 60 * 60 * 1000)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

async function statusOf(page: import('@playwright/test').Page, id: number): Promise<string> {
  return page.evaluate(async (appId) => {
    const res = await fetch(`/api/v1/applications/${appId}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}` },
    })
    return (await res.json()).data.status as string
  }, id)
}

test('the owner sends the OR and a photo of the receipt, and the bill stays with BPLO', async ({ page }) => {
  const appId = await makeBilledApplication(page)

  await page.goto(`/applications/${appId}/pay`)
  await page.getByRole('button', { name: 'Over the Counter' }).click()
  await expect(page.getByText('Pay at the BPLO counter, Malabon City Hall. BPLO records your payment.')).toBeVisible()

  // Neither the OR nor a photo: the server's own sentence, and nothing kept.
  await page.getByRole('button', { name: 'Send proof of payment' }).click()
  await expect(
    page.getByText('Enter the OR number and the date paid, or attach a photo of the receipt.'),
  ).toBeVisible()

  await page.getByLabel('OR number').fill('7654321')
  await page.getByLabel('Date paid').fill(yesterday())
  await page.getByLabel('Photo of the receipt').setInputFiles(RECEIPT)
  await page.getByRole('button', { name: 'Send proof of payment' }).click()

  await expect(page.getByText('Proof sent. BPLO will confirm your payment.')).toBeVisible()
  const sent = page.locator('dl').filter({ hasText: 'OR number' })
  await expect(sent).toContainText('7654321')
  await expect(sent).toContainText('receipt.pdf')
  await expect(sent.getByRole('button', { name: 'View' })).toBeVisible()
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/oc-owner-proof.png`, fullPage: true })

  // Proof is not payment.
  expect(await statusOf(page, appId)).toBe('pending_payment')

  // Sent again before BPLO confirms: the newest replaces the pending one.
  await page.getByLabel('OR number').fill('7654322')
  await page.getByLabel('Date paid').fill(yesterday())
  await page.getByRole('button', { name: 'Send proof of payment' }).click()
  await expect(sent).toContainText('7654322')
  await expect(sent).not.toContainText('7654321')

  // Coming back to the page shows what is waiting on BPLO.
  await page.reload()
  await page.getByRole('button', { name: 'Over the Counter' }).click()
  await expect(page.getByText('Proof sent. BPLO will confirm your payment.')).toBeVisible()
  await expect(page.locator('dl').filter({ hasText: 'OR number' })).toContainText('7654322')
  expect(await statusOf(page, appId)).toBe('pending_payment')
})
