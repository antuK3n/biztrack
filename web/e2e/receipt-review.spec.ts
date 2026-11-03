import { expect, test, type Page } from '@playwright/test'
import { mergedStorageState } from './helpers'
import { makeBilledApplication } from './payments'

/*
 * BPLO reviews the owner's counter receipt (Ken, 3 November 2026).
 *
 * Mark as paid waits for the owner's receipt, and the BPLO officer holding the
 * filing may reject it with a reason instead. The whole round, as both sides
 * see it:
 *
 *   1. the owner sends the OR from the Pay page;
 *   2. the holder's Pending Payment card reads "Receipt sent: OR …", with
 *      Reject receipt and Mark as paid;
 *   3. the holder rejects it, giving a reason (required);
 *   4. the card goes back to "Waiting for the owner's receipt." with no Mark
 *      as paid;
 *   5. the owner's Pay page opens on Over the Counter, in red, with the reason
 *      and what was sent;
 *   6. the owner sends a new receipt;
 *   7. the holder marks the bill paid against it.
 *
 * CounterReceiptRejectionTest pins the rules on the server; this is what only
 * a browser shows. Screenshots go to E2E_SHOTS_DIR when it is set, at
 * 1440×900, for review by eye.
 */
test.describe.configure({ timeout: 240_000 })
test.use({
  storageState: mergedStorageState(['owner.json', 'bplo.json']),
  viewport: { width: 1440, height: 900 },
})

const SHOTS = process.env.E2E_SHOTS_DIR

const REASON = 'The OR number on the photo does not match the one typed.'

function yesterday(): string {
  const d = new Date(Date.now() - 24 * 60 * 60 * 1000)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/** Yesterday as the Pay page prints a date paid: "November 2, 2026". */
function yesterdayLong(): string {
  return new Date(Date.now() - 24 * 60 * 60 * 1000).toLocaleDateString('en-PH', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

/** Seven digits of its own, so a second run never meets this run's OR. */
function freshOr(offset: number): string {
  return String((Date.now() + offset) % 10_000_000).padStart(7, '0')
}

async function filingOf(page: Page, id: number) {
  return page.evaluate(async (appId) => {
    const res = await fetch(`/api/v1/applications/${appId}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}` },
    })
    const data = (await res.json()).data
    return { tracking: data.tracking_id as string, business: data.business.name as string, status: data.status as string }
  }, id)
}

/** The filing's card on BPLO's Pending Payment tab, found by searching for it. */
async function pendingPaymentCard(page: Page, tracking: string) {
  await page.goto('/staff/queue')
  await page.getByRole('radio', { name: 'Pending Payment', exact: true }).check()
  await page.getByLabel('Search this queue by tracking ID or business name').fill(tracking)
  const card = page.getByRole('listitem').filter({ hasText: tracking })
  await expect(card).toBeVisible({ timeout: 30_000 })
  await expect(card).toContainText('Officer in charge:')
  return card
}

async function sendReceipt(page: Page, orNumber: string) {
  await page.getByLabel('OR number').fill(orNumber)
  await page.getByLabel('Date paid').fill(yesterday())
  await page.getByRole('button', { name: 'Send proof of payment' }).click()
  await expect(page.getByText('Proof sent. BPLO will confirm your payment.')).toBeVisible()
}

test('BPLO rejects the owner’s receipt with a reason, the owner sends another, and BPLO marks it paid', async ({ page }) => {
  const appId = await makeBilledApplication(page)
  const filing = await filingOf(page, appId)
  expect(filing.status).toBe('pending_payment')
  const firstOr = freshOr(0)
  const secondOr = freshOr(1)

  /* ── Before anything is sent, the card waits and offers no Mark as paid ── */
  let card = await pendingPaymentCard(page, filing.tracking)
  await expect(card).toContainText('Waiting for the owner’s receipt.')
  await expect(card.getByRole('button', { name: 'Mark as paid' })).toHaveCount(0)

  /* ── 1. The owner sends the OR ────────────────────────────────────────── */
  await page.goto(`/applications/${appId}/pay`)
  await page.getByRole('button', { name: 'Over the Counter' }).click()
  await sendReceipt(page, firstOr)

  /* ── 2. The holder sees it, with both buttons ────────────────────────── */
  card = await pendingPaymentCard(page, filing.tracking)
  await expect(card).toContainText(`Receipt sent: OR ${firstOr}`)
  await expect(card).not.toContainText('Waiting for the owner’s receipt.')
  await expect(card.getByRole('button', { name: 'Reject receipt' })).toBeVisible()
  await expect(card.getByRole('button', { name: 'Mark as paid' })).toBeVisible()
  await card.scrollIntoViewIfNeeded()
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/receipt-b-sent.png` })

  /* ── 3. Rejected, with a reason, which is required ───────────────────── */
  await card.getByRole('button', { name: 'Reject receipt' }).click()
  const dialog = page.getByRole('dialog')
  await expect(
    dialog.getByRole('heading', { name: `Reject the receipt for ${filing.tracking} (${filing.business})?`, exact: true }),
  ).toBeVisible()
  // The receipt being judged, above the reason.
  await expect(dialog.locator('dl')).toContainText(firstOr)
  await expect(dialog.locator('dl')).toContainText(yesterdayLong())
  const reason = dialog.getByLabel('Reason')
  await expect(reason).toHaveAttribute('aria-required', 'true')
  // The red asterisk the app's required labels carry.
  await expect(dialog.locator('label').filter({ hasText: 'Reason' }).locator('.text-s-red')).toHaveText(' *')
  await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeVisible()

  await reason.fill(REASON)
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/receipt-c-reject-dialog.png` })

  // No reason: the server's sentence, read inside the dialog, which stays open.
  await reason.fill('')
  await dialog.getByRole('button', { name: 'Reject receipt' }).click()
  await expect(dialog.getByText('Enter a reason.')).toBeVisible()
  await expect(dialog).toBeVisible()

  await reason.fill(REASON)
  await dialog.getByRole('button', { name: 'Reject receipt' }).click()
  await expect(dialog).toHaveCount(0)

  /* ── 4. Back to waiting, and no Mark as paid ─────────────────────────── */
  card = page.getByRole('listitem').filter({ hasText: filing.tracking })
  await expect(card).toContainText('Waiting for the owner’s receipt.', { timeout: 30_000 })
  await expect(card).not.toContainText('Receipt sent')
  await expect(card.getByRole('button', { name: 'Mark as paid' })).toHaveCount(0)
  await expect(card.getByRole('button', { name: 'Reject receipt' })).toHaveCount(0)
  await card.scrollIntoViewIfNeeded()
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/receipt-a-waiting.png` })

  // The owner was told, in the bell, linked to the Pay page.
  const notice = await page.evaluate(async () => {
    const headers = { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}` }
    const list = (await (await fetch('/api/v1/notifications?per_page=50', { headers })).json()).data as {
      title: string
      body: string
      link: string | null
    }[]
    return list.find((n) => n.title === 'Receipt not accepted') ?? null
  })
  expect(notice).toEqual({
    ...notice,
    body: `${filing.tracking} · ${filing.business}: ${REASON}`,
    link: `/applications/${appId}/pay`,
  })

  /* ── 5. The owner's Pay page opens on Over the Counter, in red ───────── */
  await page.goto(`/applications/${appId}/pay`)
  await expect(page.getByRole('button', { name: 'Over the Counter' })).toHaveAttribute('aria-pressed', 'true')
  const refused = page.getByRole('alert').filter({ hasText: 'BPLO did not accept your receipt' })
  await expect(refused).toContainText(`BPLO did not accept your receipt: ${REASON}`)
  await expect(refused).toContainText('Send a new one below.')
  await expect(page.getByText(`Sent: OR ${firstOr} · ${yesterdayLong()}`)).toBeVisible()
  await expect(page.getByText('Proof sent. BPLO will confirm your payment.')).toHaveCount(0)
  // The send form is below it, as usual.
  await expect(page.getByRole('button', { name: 'Send proof of payment' })).toBeVisible()
  await refused.scrollIntoViewIfNeeded()
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/receipt-d-owner-rejected.png` })

  /* ── 6. A new receipt replaces the red box with the green one ────────── */
  await sendReceipt(page, secondOr)
  await expect(page.getByText('BPLO did not accept your receipt')).toHaveCount(0)
  await expect(page.locator('dl').filter({ hasText: 'OR number' })).toContainText(secondOr)

  /* ── 7. The holder marks the bill paid against the new receipt ───────── */
  card = await pendingPaymentCard(page, filing.tracking)
  await expect(card).toContainText(`Receipt sent: OR ${secondOr}`)
  await card.getByRole('button', { name: 'Mark as paid' }).click()
  const pay = page.getByRole('dialog')
  await expect(pay.getByLabel('OR number')).toHaveValue(secondOr)
  await pay.getByRole('button', { name: 'Mark as paid' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'marked paid at the counter' })).toBeVisible()

  expect((await filingOf(page, appId)).status).not.toBe('pending_payment')
})
