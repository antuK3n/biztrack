import { expect, test } from '@playwright/test'
import { mergedStorageState } from './helpers'

/*
 * After approving, "Home Page" is the officer's own home on the staff site.
 *
 * Rupert: "on the admin side, approving an application sends you to the home
 * page." Approving itself goes nowhere — the screen stays on the filing and
 * opens the VERIFICATION dialog. Its Home Page button went to `/dashboard`,
 * which is the BUSINESS OWNER's home: the staff session is not valid there, so
 * the officer landed on the owner's sign-in page. The dialog opens with focus
 * on that button and Escape calls it too, so a key press did the same.
 *
 * The rail's Home is `portalPath(portal, '/dashboard')` (lib/nav.ts), which on
 * the staff site is `/staff/dashboard`; the button now goes there too.
 *
 * ── The approval is answered here, not by the API ──────────────────────────
 *
 * This is about where the dialog sends the officer, not about approving, which
 * the API suite and flow-lifecycle.spec.ts already cover. Letting the request
 * through would spend one of the register's filings on every run, so the
 * POST is fulfilled with a 200 and the filing is left as it was.
 */
test.use({ storageState: mergedStorageState(['bplo.json']) })

test('Home Page after approving lands on the staff home, not the business owner site', async ({ page }) => {
  await page.goto('/staff/queue')

  /*
   * A filing waiting on BPLO that this officer may act on. A renewal first,
   * because it asks for nothing else before Approve; a new filing also asks
   * which other permits the business needs, which is ticked below. An
   * amendment can be waiting for its Zoning Clearance, with Approve shut.
   */
  const target = await page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.staff')
    const res = await fetch(
      '/api/v1/assignments?application_status=for_approval&status=pending&per_page=100',
      { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } },
    )
    if (!res.ok) return null
    const rows = (await res.json()).data as {
      id: number
      can_act?: boolean
      application: { application_type?: string } | null
    }[]
    const open = rows.filter((r) => r.can_act !== false)
    const row =
      open.find((r) => r.application?.application_type === 'renewal') ??
      open.find((r) => r.application?.application_type === 'new')
    return row ? row.id : null
  })
  test.skip(target === null, 'no renewal or new filing waiting on BPLO in this register')

  await page.route(/\/api\/v1\/assignments\/\d+\/approve$/, (route) =>
    route.request().method() === 'POST'
      ? route.fulfill({ status: 200, json: { data: {} } })
      : route.continue(),
  )

  await page.goto(`/staff/queue/${target}`)
  const assign = page.getByRole('button', { name: 'Assign to Me' })
  const edit = page.getByRole('button', { name: 'Edit', exact: true })
  await expect(assign.or(edit)).toBeVisible({ timeout: 30_000 })
  if (await assign.isVisible()) await assign.click()
  await edit.click()

  const others = page.getByRole('group', { name: 'Other permits this business needs' })
  if (await others.isVisible()) await others.getByRole('checkbox').first().check()

  await page.getByRole('button', { name: 'Approve', exact: true }).click()
  await page.getByRole('button', { name: 'Yes, approve' }).click()

  const dialog = page.getByRole('dialog', { name: 'VERIFICATION' })
  await expect(dialog).toBeVisible()
  // Approving alone moves nobody: the officer is still on the filing.
  await expect(page).toHaveURL(new RegExp(`/staff/queue/${target}$`))

  await dialog.getByRole('button', { name: 'Home Page' }).click()

  await expect(page).toHaveURL(/\/staff\/dashboard$/)
  // Still signed in on the staff site: the rail is there, the owner's sign-in is not.
  await expect(page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Home' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Sign in to BizTrack' })).toHaveCount(0)
})
