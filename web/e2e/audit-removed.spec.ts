import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * AUDIT LOGS — "Removed", and the record kept for each removal (Ken's
 * checklist, 27 September 2026, "Audit Log 1").
 *
 * Stubbed: what is pinned is that the screen asks the API for removals only
 * and shows the snapshot as fields a person can read. Which removals exist on
 * a given register is not this spec's business, and Pest covers the server.
 */

test.use({ storageState: sessionFor('admin') })

const REMOVED = {
  id: 9001,
  action: 'application.draft_deleted',
  user: { name: 'Nena Dela Cruz' },
  auditable_type: 'App\\Models\\Application',
  auditable_id: 4242,
  changes: { application_type: 'new', business_id: 7 },
  snapshot: {
    id: 4242,
    status: 'draft',
    business_id: 7,
    has_amendments: false,
    rejection_reason: null,
    documents: [{ id: 1, original_filename: 'dti-certificate.pdf' }],
  },
  created_at: '2026-09-27T02:00:00.000000Z',
}

const ORDINARY = {
  id: 9000,
  action: 'business.status_changed',
  user: { name: 'Ramon Santos' },
  auditable_type: 'App\\Models\\Business',
  auditable_id: 7,
  changes: { from: 'active', to: 'flagged', reason: 'Watch.' },
  snapshot: null,
  created_at: '2026-09-27T01:00:00.000000Z',
}

test('the Removed filter asks for removals only and shows the record as it stood', async ({ page }) => {
  const asked: string[] = []
  await page.route('**/api/v1/admin/audit-logs**', async (route) => {
    const url = new URL(route.request().url())
    asked.push(url.search)
    const removedOnly = url.searchParams.get('removed') === '1'
    await route.fulfill({
      json: {
        data: removedOnly ? [REMOVED] : [REMOVED, ORDINARY],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: removedOnly ? 1 : 2 },
      },
    })
  })

  await page.goto('/admin/audit-logs')
  await expect(page.getByRole('row')).toHaveCount(3) // header + 2

  await page.getByRole('button', { name: 'Removed', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Removed', exact: true })).toHaveAttribute('aria-pressed', 'true')
  await expect.poll(() => asked.some((q) => q.includes('removed=1'))).toBe(true)
  await expect(page.getByRole('row')).toHaveCount(2) // header + 1

  await page.getByRole('button', { name: 'Removed record for application.draft_deleted on Application #4242' }).click()
  await expect(page.getByText('The record as it stood before it was removed')).toBeVisible()
  await expect(page.getByText('dti-certificate.pdf')).toBeVisible()
  // A field with no value reads as a dash, never a blank.
  const reason = page.locator('dl div').filter({ hasText: 'rejection_reason' })
  await expect(reason).toContainText('—')

  await page.screenshot({
    path: `${process.env.E2E_SCREENSHOT_DIR ?? '/tmp'}/audit-removed.png`,
    fullPage: true,
  })
})

/*
 * A row about nothing in the register (admin-audit-import row 2). A forged,
 * unsigned POST to the public KwikPay callback writes
 * `payment.callback_refused` with no subject, and the screen read the
 * subject's type regardless — "Cannot read properties of null (reading
 * 'split')" — so one anonymous request blanked the whole trail for the super
 * admin. The row now renders with a dash where the subject would be.
 */
test('a row with no subject still renders, with a dash for its target', async ({ page }) => {
  const errors: string[] = []
  page.on('pageerror', (e) => errors.push(e.message))

  const REFUSED = {
    id: 9002,
    action: 'payment.callback_refused',
    user: null,
    auditable_type: null,
    auditable_id: null,
    changes: { reason: 'bad_signature', order_id: 'FORGED-1' },
    snapshot: null,
    created_at: '2026-09-27T03:00:00.000000Z',
  }
  await page.route('**/api/v1/admin/audit-logs**', (route) =>
    route.fulfill({
      json: { data: [REFUSED, ORDINARY], meta: { current_page: 1, last_page: 1, per_page: 25, total: 2 } },
    }),
  )

  await page.goto('/admin/audit-logs')
  await expect(page.getByRole('row')).toHaveCount(3) // header + 2

  const row = page.getByRole('row').filter({ hasText: 'payment.callback_refused' })
  await expect(row.getByRole('cell').nth(3)).toHaveText('—')
  await row.getByRole('button', { name: 'Details for payment.callback_refused' }).click()
  await expect(page.getByText('FORGED-1')).toBeVisible()
  // The ordinary row beside it is unaffected.
  await expect(page.getByRole('row').filter({ hasText: 'Business #7' })).toBeVisible()
  expect(errors).toEqual([])

  await page.screenshot({
    path: `${process.env.E2E_SCREENSHOT_DIR ?? '/tmp'}/audit-no-subject.png`,
    fullPage: true,
  })
})
