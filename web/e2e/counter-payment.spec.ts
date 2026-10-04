import { expect, test } from '@playwright/test'
import { mergedStorageState } from './helpers'
import { makeBilledApplication } from './payments'

/*
 * BPLO marks a filing paid at the counter [Ken, 4 October 2026].
 *
 * An owner who pays in person at City Hall: BPLO finds the filing on its
 * Pending Payment tab and marks it paid, entering nothing. The row leaves the
 * tab, and the owner's payments say "Paid at the counter".
 */
test.describe.configure({ timeout: 180_000 })
test.use({ storageState: mergedStorageState(['owner.json', 'bplo.json']) })

test('BPLO marks a filing paid at the counter from the Pending Payment tab', async ({ page }) => {
  const appId = await makeBilledApplication(page)

  const filing = await page.evaluate(async (id) => {
    const res = await fetch(`/api/v1/applications/${id}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}` },
    })
    const data = (await res.json()).data
    return { tracking: data.tracking_id as string, status: data.status as string }
  }, appId)
  expect(filing.status).toBe('pending_payment')

  await page.goto('/staff/queue')
  await page.getByRole('radio', { name: 'Pending Payment', exact: true }).check()

  const row = page.getByRole('listitem').filter({ hasText: filing.tracking })
  await expect(row).toBeVisible({ timeout: 30_000 })
  await row.getByRole('button', { name: 'Mark as paid' }).click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toContainText(`Mark ${filing.tracking}`)
  await expect(dialog).toContainText('the same as an online payment')
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
    }[]
    return {
      status: app.status as string,
      methods: list.filter((p) => p.application?.id === id).map((p) => `${p.method}:${p.status}`),
    }
  }, appId)

  expect(after.status).not.toBe('pending_payment')
  expect(after.methods).toContain('counter:completed')
})
