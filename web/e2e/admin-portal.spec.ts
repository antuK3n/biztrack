import { expect, test } from '@playwright/test'

/*
 * The super admin's own site [checklist item #107].
 *
 * `admin` used to be one of AuthController::STAFF_ROLES, so the person who
 * creates every officer account signed in at /staff/login beside them and
 * shared their `biztrack.token.staff` key. It is a third portal now: its own
 * door, its own prefix, its own token.
 *
 * auth.spec.ts proves the DOOR — that the API admits the administrator at
 * /admin/login and refuses them at /staff/login, and refuses an officer here.
 * This file proves the other half, which a door test cannot: that there is
 * something behind it. A portal whose sign-in works and whose screens 404 is
 * worse than no portal, because the failure arrives after the password.
 *
 * Driven on the default `chromium` project, whose storage state is admin.json
 * — the super admin's session (see auth.setup.ts).
 */

test('the administrator lands on their own site, not the officers one', async ({ page }) => {
  await page.goto('/admin/dashboard')

  // Not bounced to a sign-in page: the session is keyed `biztrack.token.admin`
  // and this tab's address is what selects that key.
  await expect(page).toHaveURL(/\/admin\/dashboard$/)
  await expect(page.getByRole('heading').first()).toBeVisible()
})

test('every admin console screen answers under the admin prefix', async ({ page }) => {
  /*
   * The whole rail, in one pass.
   *
   * `/admin/*` used to be a redirect shim to /staff/dashboard, so every one of
   * these addresses silently answered with the dashboard. That is the failure
   * this asserts against: a screen that resolves to the WRONG screen looks
   * exactly like one that works, and it is how the analytics tab strip's own
   * broken links stayed invisible for weeks (see the MovedAnalytics note in
   * App.tsx).
   */
  const screens = [
    { path: '/admin/users', heading: 'Officer Assignment' },
    { path: '/admin/records', heading: /records/i },
    { path: '/admin/audit-logs', heading: /audit/i },
  ]

  for (const screen of screens) {
    await page.goto(screen.path)
    await expect(page).toHaveURL(new RegExp(`${screen.path}$`))
    await expect(page.getByRole('heading', { name: screen.heading, level: 1 })).toBeVisible()
  }
})

test('the rail addresses the admin site, so no entry signs the reader out', async ({ page }) => {
  /*
   * `navItemsFor` builds one list of portal-relative paths for all three
   * portals and `portalPath` applies the prefix. The super-admin entries in
   * nav.ts are written '/admin/users', '/admin/records' and so on, from when
   * those screens lived under /staff — so a naive prefix would produce
   * '/admin/admin/users', which no route matches.
   *
   * Nothing on screen would say so. Every rail entry would simply bounce to the
   * sign-in page, reading as a session that had died rather than as a path
   * built wrong, which is why this asserts the hrefs rather than clicking one.
   */
  await page.goto('/admin/dashboard')

  const hrefs = await page
    .getByRole('navigation')
    .getByRole('link')
    .evaluateAll((links) => links.map((l) => l.getAttribute('href') ?? ''))

  expect(hrefs.length).toBeGreaterThan(0)
  for (const href of hrefs) {
    expect(href, `rail entry ${href} is not on the admin site`).toMatch(/^\/admin\//)
    expect(href, `rail entry ${href} is double-prefixed`).not.toMatch(/^\/admin\/admin\//)
  }
})
