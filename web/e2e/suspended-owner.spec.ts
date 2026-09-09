import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * A suspended owner is told to appeal, and given no way to.
 *
 * Three screens tell a business owner their account has been restricted: the
 * pop-up the dashboard raises, the notification the LGU sent when it happened,
 * and the body of that notification, which ends "If you believe this is a
 * mistake, message the City BPLO." Every one of them said *contact BPLO* and
 * none of them said *how* — the pop-up's only control is "Understood", and the
 * notification's link goes to /dashboard, which raises the same pop-up again.
 *
 * The instruction was a dead end, which is worse than no instruction: the
 * reader is told an appeal exists, tries to find it, and concludes the system
 * is refusing them. A suspension stops them filing anything, so this is the one
 * moment they most need a person to talk to.
 *
 * The destination is the general enquiry — the conversation that exists without
 * a filing behind it, addressed to BPLO. It is the right one precisely because
 * a suspended owner may have no filing to hang the question on.
 *
 * Stubbed rather than seeded: suspending a real business in the register would
 * mean writing a restriction onto a live tester's account, and restoring it
 * afterwards is a second write that can fail and leave them locked out.
 */

test.use({ storageState: sessionFor('owner') })

/** Where "Contact BPLO" has to land. */
const BPLO_ENQUIRY = /\/messages\?application=general/

/*
 * Matched by exact accessible name, not a loose /bplo/i.
 *
 * The row's own link wraps the whole notification, so its accessible name
 * includes the body — which ends "message the City BPLO". A loose match found
 * that too and resolved to two elements. Worth recording rather than just
 * fixing: the row link matching is the dead end this change is about. It reads
 * as the appeal and goes to /dashboard.
 */
const CONTACT = { name: 'Message the City BPLO', exact: true } as const

const SUSPENDED_BUSINESS = {
  id: 4101,
  name: 'Nena’s Sari-Sari Store',
  trade_name: null,
  registration_type: 'sole',
  registration_number: null,
  tin: null,
  ban: 'BAN-2026-0007',
  is_active: true,
  status: 'suspended',
}

/** The notification the LGU sends when it changes a business's standing. */
function accountStatusNotice(id: number, title: string, body: string, read = false) {
  return {
    id,
    type: 'account_status',
    title,
    body,
    link: '/dashboard',
    read_at: read ? '2026-09-01T00:00:00.000000Z' : null,
    created_at: '2026-09-01T00:00:00.000000Z',
  }
}

const NOTICES = [
  accountStatusNotice(
    7001,
    'Business account suspended',
    'Nena’s Sari-Sari Store is now Suspended. New applications cannot be filed for it while this stands. Reason: Unpaid assessment. If you believe this is a mistake, message the City BPLO.',
  ),
  accountStatusNotice(
    7002,
    'Business account restored',
    'Nena’s Sari-Sari Store is active again and can file applications. Reason: Assessment settled.',
    true,
  ),
  {
    id: 7003,
    type: 'status_change',
    title: 'Application update',
    body: 'BIZ-2026-00001 is now “For Approval”.',
    link: '/applications/1',
    read_at: null,
    created_at: '2026-09-02T00:00:00.000000Z',
  },
]

/* ── The pop-up ───────────────────────────────────────────────────────────── */

test.describe('the suspension pop-up', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/v1/businesses*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [SUSPENDED_BUSINESS],
          meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
        }),
      })
    })
    await page.goto('/dashboard')
  })

  test('offers a way to reach BPLO, and reaching it opens the conversation', async ({ page }) => {
    const dialog = page.getByRole('alertdialog', { name: 'Account Suspended' })
    await expect(dialog).toBeVisible()

    const contact = dialog.getByRole('link', CONTACT)
    await expect(contact, 'the pop-up tells the owner to appeal and offers no way to').toBeVisible()

    await contact.click()

    // Not just "a route changed" — the conversation with BPLO is open, which is
    // the only thing that makes the button an answer rather than a redirect.
    await expect(page).toHaveURL(BPLO_ENQUIRY)
    await expect(page.getByRole('heading', { name: 'Messages', level: 1 })).toBeVisible()
    await expect(page.getByRole('textbox', { name: /message/i }).first()).toBeVisible({
      timeout: 20_000,
    })
  })

  test('still dismisses, because the owner may only want to read it', async ({ page }) => {
    const dialog = page.getByRole('alertdialog', { name: 'Account Suspended' })
    await expect(dialog).toBeVisible()

    await dialog.getByRole('button', { name: 'Understood' }).click()
    await expect(dialog).toBeHidden()
  })
})

/* ── The notification ─────────────────────────────────────────────────────── */

test.describe('the suspension notification', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/v1/notifications*', async (route) => {
      if (route.request().method() !== 'GET') {
        await route.fulfill({ status: 200, contentType: 'application/json', body: '{}' })
        return
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: NOTICES,
          meta: { current_page: 1, last_page: 1, per_page: 50, total: NOTICES.length, unread: 2 },
        }),
      })
    })
    await page.goto('/notifications')
    // The dev server compiles this route on first request, so the shell can sit
    // on "Loading BizTrack" for longer than the default expect window.
    await expect(page.getByRole('heading', { name: 'Notifications', level: 1 })).toBeVisible({
      timeout: 30_000,
    })
  })

  test('carries the way to appeal, and it lands on the BPLO conversation', async ({ page }) => {
    const row = page.getByRole('listitem').filter({ hasText: 'Business account suspended' })
    const contact = row.getByRole('link', CONTACT)
    await expect(contact, 'the notice says to message BPLO and gives no way to').toBeVisible()

    await contact.click()
    await expect(page).toHaveURL(BPLO_ENQUIRY)
    await expect(page.getByRole('heading', { name: 'Messages', level: 1 })).toBeVisible()
  })

  test('and a restoration does not, because there is nothing to appeal', async ({ page }) => {
    const restored = page.getByRole('listitem').filter({ hasText: 'Business account restored' })
    await expect(restored).toBeVisible()
    await expect(
      restored.getByRole('link', CONTACT),
      'good news was given an appeal button',
    ).toHaveCount(0)

    const other = page.getByRole('listitem').filter({ hasText: 'Application update' })
    await expect(other.getByRole('link', CONTACT)).toHaveCount(0)
  })
})
