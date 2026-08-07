import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * The officer request composer, against a filing whose business is gone.
 *
 * `Business` soft-deletes and its filings stay behind — 139 applications
 * currently point at a deleted one — and `ApplicationListResource` emits
 * `business: null` for every one of them. The composer read `.business.name`
 * straight through, so opening it threw, and with no error boundary in the
 * tree the throw blanked the entire page rather than one row of a dropdown.
 *
 * It hid because the newest applications all have their businesses. Nothing
 * surfaced until a queue ran deep enough to reach a deleted one, which is the
 * same way the identical bug hid the last two times it was fixed — see the
 * note on `Assignment` in lib/types.ts.
 *
 * The response is stubbed rather than seeded. The null only appears once a
 * list runs past the clean rows, so a test that relied on real data would
 * pass on a fresh database and fail a year later; and it is the component's
 * contract with a shape, not the register's contents, that is under test.
 */

test.use({ storageState: sessionFor('bplo') })

const WITH_DELETED_BUSINESS = [
  {
    id: 90001,
    tracking_id: 'BIZ-2023-00025',
    application_type: 'new',
    title: null,
    status: 'under_review',
    status_label: 'For Approval',
    // The register no longer holds this one.
    business: null,
    submitted_at: '2026-01-05T00:00:00.000000Z',
    deadline_at: null,
    permit_types: [{ code: 'BUSINESS', name: 'Business Permit' }],
    created_at: '2026-01-05T00:00:00.000000Z',
  },
  {
    id: 90002,
    tracking_id: 'BIZ-2026-00100',
    application_type: 'renewal',
    title: null,
    status: 'under_review',
    status_label: 'For Approval',
    business: { id: 5, name: 'Dela Cruz Trading' },
    submitted_at: '2026-06-01T00:00:00.000000Z',
    deadline_at: null,
    permit_types: [{ code: 'BUSINESS', name: 'Business Permit' }],
    created_at: '2026-06-01T00:00:00.000000Z',
  },
]

test('the request composer survives a filing whose business was removed', async ({ page }) => {
  const crashes: string[] = []
  page.on('pageerror', (err) => crashes.push(err.message))

  await page.route('**/api/v1/applications*', async (route) => {
    // Only the list call carries this shape; detail routes go through.
    const url = new URL(route.request().url())
    if (!/\/api\/v1\/applications\/?$/.test(url.pathname)) return route.fallback()
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: WITH_DELETED_BUSINESS }),
    })
  })

  await page.goto('/staff/requests')
  await page.getByRole('button', { name: /request/i }).first().click()

  // The modal has to open at all — this is the assertion that failed.
  await expect(page.getByRole('heading', { name: /^request$/i })).toBeVisible({ timeout: 15_000 })

  expect(crashes, `the composer threw: ${crashes.join(' | ')}`).toEqual([])
})

test('a removed business is named as removed, not left blank', async ({ page }) => {
  await page.route('**/api/v1/applications*', async (route) => {
    const url = new URL(route.request().url())
    if (!/\/api\/v1\/applications\/?$/.test(url.pathname)) return route.fallback()
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: WITH_DELETED_BUSINESS }),
    })
  })

  await page.goto('/staff/requests')
  await page.getByRole('button', { name: /request/i }).first().click()
  await expect(page.getByRole('heading', { name: /^request$/i })).toBeVisible({ timeout: 15_000 })

  /*
   * An officer picking a filing to chase needs to know the register dropped
   * the business — that is usually why the filing stalled. A blank cell or a
   * bare tracking ID would send them hunting for the reason.
   */
  const options = await page.locator('select option').allTextContents()
  const orphan = options.find((o) => o.includes('BIZ-2023-00025'))

  expect(orphan, 'the filing with no business vanished from the picker').toBeTruthy()
  expect(orphan).toContain('Business removed from register')

  // The healthy row still reads normally.
  expect(options.find((o) => o.includes('BIZ-2026-00100'))).toContain('Dela Cruz Trading')
})

/*
 * Checklist item 89 — "requests for other requirements should have recipients".
 *
 * The recipient is shown rather than picked, because the model has exactly one
 * to offer: only the business_owner role holds `request.respond`, so a request
 * addressed to an office would arrive somewhere nobody could answer it. What
 * has to be true of a field that states rather than asks is that it is still
 * readable — `readOnly`, not `disabled`, or the keyboard skips it and screen
 * readers commonly drop it (WCAG 2.1 AA), which would hide the very fact the
 * field exists to state.
 */
test('the composer names who the request is going to, readably', async ({ page }) => {
  await page.route('**/api/v1/applications*', async (route) => {
    const url = new URL(route.request().url())
    if (!/\/api\/v1\/applications\/?$/.test(url.pathname)) return route.fallback()
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: WITH_DELETED_BUSINESS }),
    })
  })

  await page.goto('/staff/requests')
  await page.getByRole('button', { name: /request/i }).first().click()
  await expect(page.getByRole('heading', { name: /^request$/i })).toBeVisible({ timeout: 15_000 })

  const recipient = page.getByLabel(/to \(recipient\)/i)
  await expect(recipient).toBeVisible()
  await expect(recipient).toHaveAttribute('readonly', '')
  await expect(recipient).toBeEnabled()

  // Empty until a filing is chosen — there is no recipient before there is an
  // application, and inventing one would be the fake picker in another costume.
  await expect(recipient).toHaveValue('')

  // By value: the option label carries the business name, which is the thing
  // under test, so selecting by it would assert nothing.
  await page.getByLabel(/application/i).first().selectOption('90002')

  /*
   * This stub carries no `applicant`, which is the real nullable case: User
   * soft-deletes and its filings stay. The fallback has to name somebody rather
   * than go blank, and it still has to say which business.
   */
  await expect(recipient).toHaveValue(/business owner on file · applicant for Dela Cruz Trading/i)
})
