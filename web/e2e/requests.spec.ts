import { expect, test } from '@playwright/test'

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

test.use({ storageState: 'e2e/.auth/bplo.json' })

const WITH_DELETED_BUSINESS = [
  {
    id: 90001,
    tracking_id: 'BIZ-2023-00025',
    application_type: 'new',
    title: null,
    status: 'under_review',
    status_label: 'Under review',
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
    status_label: 'Under review',
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

  await page.goto('/requests')
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

  await page.goto('/requests')
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
