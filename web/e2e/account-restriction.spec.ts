import { expect, test, type Page } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * ── A barred account, and the one door left open ─────────────────────────
 *
 * "Pag open na pag open pa lang ng account ng business owner na yon may
 * bubungad na agad na modal for warning, at magdidirect sa kanya sa specific na
 * chat sa BPLO pag business is suspended — sa business na acc nya, diba may
 * kanya kanyang convo kada business — tas pag account is blacklisted ma-direct
 * naman dapat sa general inquiry ng BPLO. Note na bawal nya na ma-access ang
 * iba pa sa system, kundi messages part na lang at pag view ng notif"
 * [client, 30 September 2026].
 *
 * ── Stubbed, not seeded ──────────────────────────────────────────────────
 *
 * Writing a finding onto a live tester's account is not something a test may
 * do, and taking it back off is a second write that can fail and leave them
 * locked out of everything but their messages. suspended-owner.spec.ts has said
 * so since it was written, and the e2e stack now lifts the register's own
 * restriction off the throwaway copy for the same reason — so a suite that
 * relied on real data would have nothing to find.
 *
 * What the SERVER does about a restriction is asserted where it can be set up
 * honestly: api/tests/Feature/AccountRestrictionTest.php.
 */

test.use({ storageState: sessionFor('owner') })

type Finding = {
  kind: 'blacklisted' | 'suspended'
  business_name: string | null
  reference_id: string | null
  covers: number
  conversation: { application_id: number | null }
}

const BLACKLISTED: Finding = {
  kind: 'blacklisted',
  business_name: null,
  reference_id: null,
  covers: 3,
  conversation: { application_id: null },
}

const SUSPENDED: Finding = {
  kind: 'suspended',
  business_name: 'Nena’s Sari-Sari Store',
  reference_id: 'BAN-2026-0007',
  covers: 3,
  conversation: { application_id: 4101 },
}

/*
 * The signed-in owner, as /auth/me answers for them.
 *
 * Built here rather than fetched and amended. The app re-asks /auth/me while
 * the page lives, so a handler that fetched the real response raced the test's
 * own teardown — "Response has been disposed", on every test in this file. A
 * static answer has no such race, and what these tests assert is the SHELL's
 * behaviour given a finding, not the register's contents.
 */
const OWNER = {
  id: 1,
  email: 'owner@biztrack.local',
  mobile_number: '09171234567',
  first_name: 'Nena',
  middle_name: null,
  last_name: 'Dela Cruz',
  suffix: null,
  gender: 'F',
  department: null,
  has_photo: false,
  is_active: true,
  email_verified_at: '2026-07-01T08:00:00Z',
  roles: ['business_owner'],
  permissions: [
    'business.manage_own',
    'application.create',
    'application.view_own',
    'document.upload_own',
    'payment.make',
    'permit.view_own',
    'request.respond',
    'message.participate',
  ],
}

/** Answer /auth/me with that owner, and a finding against them. */
async function serveRestriction(page: Page, finding: Finding | null) {
  await page.route('**/api/v1/auth/me', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { ...OWNER, restriction: finding } }),
    }),
  )
}

/*
 * The route outlives the assertions: the app keeps asking while the page is
 * torn down, and an unrouted handler mid-flight fails the test on the way out
 * for a reason that has nothing to do with what it was checking.
 */
test.afterEach(async ({ page }) => {
  await page.unrouteAll({ behavior: 'ignoreErrors' })
})

test('raises the warning wherever the account is opened', async ({ page }) => {
  await serveRestriction(page, BLACKLISTED)

  /*
   * Not the dashboard. The notice used to be raised by the home page, so an
   * owner arriving from a notification link, a bookmark, or a reload on the
   * page they were last reading was never told. It is the SESSION that is
   * barred, so the shell raises it.
   */
  await page.goto('/notifications')

  const notice = page.getByRole('alertdialog', { name: /Account Blacklisted/i })
  await expect(notice).toBeVisible({ timeout: 20000 })
  await expect(notice).toContainText(/Messages/)
})

test('sends a blacklisting to the general enquiry', async ({ page }) => {
  await serveRestriction(page, BLACKLISTED)
  await page.goto('/messages')

  const notice = page.getByRole('alertdialog')
  await expect(notice).toBeVisible({ timeout: 20000 })

  /*
   * The finding is against the PERSON, so there is no one business to argue
   * about — the general enquiry is the conversation that needs no filing
   * behind it, which is exactly the case a blacklisted owner may be in.
   */
  await notice.getByRole('link', { name: /Message the City BPLO/ }).click()
  await expect(page).toHaveURL(/\/messages\?application=general/)
})

test('sends a suspension to that business’s own conversation', async ({ page }) => {
  await serveRestriction(page, SUSPENDED)
  await page.goto('/messages')

  const notice = page.getByRole('alertdialog', { name: /Business Suspended/i })
  await expect(notice).toBeVisible({ timeout: 20000 })

  // The business is named, and so is its reference, for the reader to quote.
  await expect(notice).toContainText('Nena’s Sari-Sari Store')
  await expect(notice).toContainText('BAN-2026-0007')

  await notice.getByRole('link', { name: /Message the City BPLO/ }).click()
  await expect(page).toHaveURL(/\/messages\?application=4101/)
})

test('offers nothing in the rail but Messages', async ({ page }) => {
  await serveRestriction(page, BLACKLISTED)
  await page.goto('/messages')

  await page.getByRole('alertdialog').getByRole('button', { name: 'Understood' }).click()

  /*
   * Removed, not greyed. A disabled rail is a promise the page behind it will
   * not keep, and the server refuses those paths anyway — see
   * EnforceAccountRestriction.
   */
  for (const gone of ['Apply', 'Renew', 'My Permits', 'Drafts', 'Payment History', 'Track']) {
    await expect(page.getByRole('link', { name: gone, exact: true })).toHaveCount(0)
  }

  await expect(page.getByRole('link', { name: 'Messages', exact: true })).toHaveCount(1)
})

test('turns a typed path back to the conversation', async ({ page }) => {
  await serveRestriction(page, SUSPENDED)

  /*
   * The rail offers none of these while a restriction stands, so in ordinary
   * use this never fires. It is for the bookmark, the browser's back button
   * and the tab that was already open — and it lands on the conversation
   * rather than on a refusal, which is the same instruction the modal gives.
   */
  await page.goto('/permits')
  await expect(page).toHaveURL(/\/messages\?application=4101/, { timeout: 20000 })
})

test('leaves the notifications reachable', async ({ page }) => {
  await serveRestriction(page, BLACKLISTED)
  await page.goto('/notifications')

  await page.getByRole('alertdialog').getByRole('button', { name: 'Understood' }).click()

  /*
   * The other half of what a barred account keeps. The notices carry the
   * reason the finding was recorded, which the warning itself points at, so
   * barring them would send the reader to a page they may not open.
   */
  await expect(page).toHaveURL(/\/notifications/)
  await expect(page.getByRole('heading', { name: 'Notifications' })).toBeVisible()
})
