import { expect, test, type Page } from '@playwright/test'
import { mergedStorageState } from './helpers'
import { makeBilledApplication } from './payments'

/*
 * BPLO marks a filing paid at the counter, after the owner chose Over the
 * Counter on their Pay page.
 *
 * Rewritten for the thesis panel's revisions (matrix items 1, 4 and 6). It used
 * to press Mark as paid and enter nothing [Ken, 4 October 2026]. Now the owner
 * sends the OR from the Pay page; only the BPLO officer holding the filing sees
 * Mark as paid; the dialog shows the owner's proof and asks for the OR number
 * and the date paid, pre-filled from it; and a filing nobody holds is taken
 * with Assign to Me first. The row still leaves the tab once paid, and the
 * owner's payments still say "Paid at the counter".
 */
test.describe.configure({ timeout: 180_000 })
test.use({ storageState: mergedStorageState(['owner.json', 'bplo.json']) })

const SHOTS = process.env.E2E_SHOTS_DIR

function daysAgo(n: number): string {
  const d = new Date(Date.now() - n * 24 * 60 * 60 * 1000)
  const pad = (k: number) => String(k).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

async function filingOf(page: Page, id: number): Promise<{ tracking: string; status: string }> {
  return page.evaluate(async (appId) => {
    const res = await fetch(`/api/v1/applications/${appId}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}` },
    })
    const data = (await res.json()).data
    return { tracking: data.tracking_id as string, status: data.status as string }
  }, id)
}

async function pendingPaymentRow(page: Page, tracking: string) {
  await page.goto('/staff/queue')
  await page.getByRole('radio', { name: 'Pending Payment', exact: true }).check()
  const row = page.getByRole('listitem').filter({ hasText: tracking })
  await expect(row).toBeVisible({ timeout: 30_000 })
  return row
}

test('the owner sends the OR, and the BPLO officer holding the filing marks it paid with it', async ({ page }) => {
  const appId = await makeBilledApplication(page)
  const filing = await filingOf(page, appId)
  expect(filing.status).toBe('pending_payment')

  // The owner chooses Over the Counter, pays at City Hall, and sends the OR.
  await page.goto(`/applications/${appId}/pay`)
  await page.getByRole('button', { name: 'Over the Counter' }).click()
  await expect(page.getByRole('button', { name: 'Over the Counter' })).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByText('Pay at the BPLO counter, Malabon City Hall. BPLO records your payment.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Pay Online' })).toHaveCount(0)
  const orNumber = String(Date.now() % 10_000_000).padStart(7, '0')
  await page.getByLabel('OR number').fill(orNumber)
  await page.getByLabel('Date paid').fill(daysAgo(1))
  await page.getByRole('button', { name: 'Send proof of payment' }).click()
  await expect(page.getByText('Proof sent. BPLO will confirm your payment.')).toBeVisible()
  expect((await filingOf(page, appId)).status).toBe('pending_payment')

  // BPLO approved the form through its own queue, so the officer holds the filing.
  const row = await pendingPaymentRow(page, filing.tracking)
  await expect(row).toContainText('Officer in charge:')
  await expect(row).toContainText('Liza Reyes')
  await row.getByRole('button', { name: 'Mark as paid' }).click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toContainText(`Mark ${filing.tracking}`)
  await expect(dialog).toContainText('the same as an online payment')
  await expect(dialog.getByRole('heading', { name: 'Proof of payment' })).toBeVisible()
  await expect(dialog.locator('dl')).toContainText(orNumber)
  // Pre-filled from the owner's proof, and required.
  await expect(dialog.getByLabel('OR number')).toHaveValue(orNumber)
  await expect(dialog.getByLabel('OR number')).toHaveAttribute('aria-required', 'true')
  await expect(dialog.getByLabel('Date paid')).toHaveValue(daysAgo(1))
  await expect(dialog.getByLabel('Photo of the receipt')).toBeVisible()
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/oc-mark-paid.png` })

  // Required: emptied, the server's sentence is read beside the field.
  await dialog.getByLabel('Date paid').fill('')
  await dialog.getByRole('button', { name: 'Mark as paid' }).click()
  await expect(dialog.getByText('Enter the date paid.')).toBeVisible()
  await dialog.getByLabel('Date paid').fill(daysAgo(1))
  await dialog.getByRole('button', { name: 'Mark as paid' }).click()

  await expect(page.getByRole('status').filter({ hasText: 'marked paid at the counter' })).toBeVisible()
  await expect(page.getByRole('listitem').filter({ hasText: filing.tracking })).toHaveCount(0)

  const after = await page.evaluate(async (id) => {
    const headers = { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}` }
    const app = (await (await fetch(`/api/v1/applications/${id}`, { headers })).json()).data
    const list = (await (await fetch('/api/v1/payments?per_page=100', { headers })).json()).data as {
      application?: { id: number }
      method: string
      status: string
      or_number: string | null
    }[]
    return {
      status: app.status as string,
      payments: list.filter((p) => p.application?.id === id).map((p) => `${p.method}:${p.status}:${p.or_number}`),
    }
  }, appId)

  expect(after.status).not.toBe('pending_payment')
  expect(after.payments).toContain(`counter:completed:${orNumber}`)
})

test('a filing nobody at BPLO holds offers Assign to Me and no Mark as paid until it is taken', async ({ page }) => {
  const appId = await makeBilledApplication(page)
  const filing = await filingOf(page, appId)

  // Put back to the office, as "Unassign from me" does.
  await page.evaluate(async (id) => {
    const headers = { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.staff')}` }
    const list = (await (await fetch('/api/v1/applications?status=pending_payment&per_page=200', { headers })).json())
      .data as { id: number; bplo_assignment?: { id: number } | null }[]
    const assignment = list.find((a) => a.id === id)?.bplo_assignment?.id
    if (!assignment) throw new Error(`filing ${id} carries no BPLO assignment`)
    const res = await fetch(`/api/v1/assignments/${assignment}/release`, { method: 'POST', headers })
    if (!res.ok) throw new Error(`release answered ${res.status}`)
  }, appId)

  const row = await pendingPaymentRow(page, filing.tracking)
  await expect(row).toContainText('Not yet taken by anyone')
  await expect(row.getByRole('button', { name: 'Mark as paid' })).toHaveCount(0)

  await row.getByRole('button', { name: 'Assign to Me' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'you are now the officer in charge' })).toBeVisible()
  const taken = page.getByRole('listitem').filter({ hasText: filing.tracking })
  await expect(taken.getByRole('button', { name: 'Mark as paid' })).toBeVisible({ timeout: 30_000 })
})
