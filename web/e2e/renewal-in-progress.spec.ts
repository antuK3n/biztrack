import { expect, test, type Page } from '@playwright/test'
import { sessionFor, WIZARD_PAINT_MS } from './helpers'

/*
 * One renewal in progress per permit (Ken, 5 October 2026).
 *
 * While a renewal sat open the picker still offered its permit, and a second
 * renewal of it went all the way to payment — two bills, two Active permits
 * for one term. The server refuses that now (`RenewablePermit`); this pins the
 * half only a browser can see: the permit stays in the list, greyed out,
 * labelled `Renewal in progress`, and cannot be ticked.
 */

test.use({ storageState: sessionFor('owner') })

// The seeded owner's business holding two Mayor's / Business Permits.
const BUSINESS_ID = 1

const DIALOG = /which permits? are you renewing/i

async function api<T>(page: Page, method: 'GET' | 'POST', path: string, body?: unknown): Promise<T> {
  return page.evaluate(
    async ([m, p, b]) => {
      const token = localStorage.getItem('biztrack.token.public')
      const res = await fetch(`/api/v1${p}`, {
        method: m as string,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: b === null ? undefined : JSON.stringify(b),
      })
      if (!res.ok) throw new Error(`${m} ${p} -> ${res.status} ${await res.text()}`)
      return (await res.json()).data
    },
    [method, path, body ?? null] as const,
  ) as Promise<T>
}

test('a permit whose renewal is in progress is greyed out and cannot be ticked', async ({
  page,
}) => {
  await page.goto('/apply?type=renewal')
  await expect(page.getByRole('dialog', { name: DIALOG })).toBeVisible({
    timeout: WIZARD_PAINT_MS,
  })

  const prefill = await api<{
    renewable_permits: { id: number; permit_number: string }[]
    renewal_in_progress_permit_ids: number[]
  }>(page, 'GET', `/businesses/${BUSINESS_ID}/prefill?type=renewal`)
  const free = prefill.renewable_permits.filter(
    (p) => !prefill.renewal_in_progress_permit_ids.includes(p.id),
  )
  test.skip(free.length < 2, 'needs two permits with no renewal in progress')
  const [busy, other] = free

  // A renewal of the first permit, submitted: that is what "in progress" is.
  const renewal = await api<{ id: number }>(page, 'POST', '/applications', {
    business_id: BUSINESS_ID,
    data_privacy_consent: true,
    application_type: 'renewal',
    prior_permit_ids: [busy.id],
  })

  try {
    await api(page, 'POST', `/applications/${renewal.id}/submit`)

    await page.goto('/apply?type=renewal')
    const modal = page.getByRole('dialog', { name: DIALOG })
    await expect(modal).toBeVisible({ timeout: WIZARD_PAINT_MS })
    await modal.getByRole('combobox', { name: /which business/i }).selectOption({
      value: String(BUSINESS_ID),
    })

    const busyRow = modal.locator('li').filter({ hasText: busy.permit_number })
    const otherRow = modal.locator('li').filter({ hasText: other.permit_number })
    await expect(busyRow).toBeVisible({ timeout: WIZARD_PAINT_MS })

    await expect(busyRow).toContainText('Renewal in progress')
    await expect(otherRow).not.toContainText('Renewal in progress')

    const busyInput = busyRow.locator('input')
    await expect(busyInput).toHaveAttribute('aria-disabled', 'true')
    // Reachable, not removed from the page the way `disabled` would.
    await expect(busyInput).not.toHaveAttribute('disabled', /.*/)

    await busyRow.locator('label').click({ force: true })
    await expect(busyInput).not.toBeChecked()

    // The other permit is still an ordinary choice.
    await otherRow.locator('label').click()
    await expect(otherRow.locator('input')).toBeChecked()

    await modal.screenshot({ path: process.env.E2E_SHOT ?? 'test-results/renewal-in-progress.png' })
  } finally {
    // Leave the register as it was found, so the other renewal specs can tick it.
    await api(page, 'POST', `/applications/${renewal.id}/cancel`)
  }
})
