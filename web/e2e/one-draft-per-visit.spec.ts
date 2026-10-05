import { expect, test, type Page } from '@playwright/test'
import { sessionFor, WIZARD_PAINT_MS } from './helpers'

/*
 * One opening of the wizard is one draft (Ken, 6 October 2026: "multiple
 * drafts are being created … it was fixed by Rupert before, but now it's
 * back").
 *
 * Production held six scratch rows for one tester in seven minutes, two of
 * them begun in the same second. Driving the wizard reproduced both causes:
 *
 *  - a save that left while the first save's create was still on its way
 *    found no id yet and began a row of its own, so a slow connection made
 *    one row per pause in the typing (three in two seconds here);
 *  - a refresh opened a blank form, and its first answer began a second row
 *    beside the first.
 *
 * Rupert's rule still holds and is checked again: opening and leaving
 * writes nothing.
 */

test.use({ storageState: sessionFor('owner') })

type Row = { id: number; title: string | null }

async function drafts(page: Page): Promise<Row[]> {
  return page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.public')
    const res = await fetch('/api/v1/wizard-drafts', {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
    return (await res.json()).data
  })
}

async function discard(page: Page, ids: number[]) {
  await page.evaluate(async (list) => {
    const token = localStorage.getItem('biztrack.token.public')
    for (const id of list) {
      await fetch(`/api/v1/wizard-drafts/${id}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
    }
  }, ids)
}

async function openNew(page: Page) {
  await page.goto('/apply?type=new')
  await expect(page.getByLabel(/application title/i)).toBeVisible({ timeout: WIZARD_PAINT_MS })
}

test.beforeEach(async ({ page }) => {
  await page.route('**://nominatim.openstreetmap.org/**', (route) => route.abort())
})

test('opening the wizard and leaving writes no draft', async ({ page }) => {
  await page.goto('/dashboard')
  const before = (await drafts(page)).length

  await openNew(page)
  await page.waitForTimeout(2_500)
  await page.goto('/dashboard')

  expect((await drafts(page)).length).toBe(before)
})

test('a slow first save, more typing and a refresh still leave one draft', async ({ page }) => {
  await page.goto('/dashboard')
  const before = new Set((await drafts(page)).map((d) => d.id))
  const posts: string[] = []
  page.on('request', (r) => {
    if (r.method() === 'POST' && r.url().endsWith('/wizard-drafts')) posts.push(r.url())
  })

  /* The first create answers slowly, as it did on the server the testers use. */
  await page.route('**/api/v1/wizard-drafts', async (route) => {
    if (route.request().method() === 'POST') await new Promise((r) => setTimeout(r, 2_500))
    await route.continue()
  })

  await openNew(page)
  const title = page.getByLabel(/application title/i)
  await page.getByRole('checkbox').first().check()
  await page.waitForTimeout(1_000)
  await title.fill('One Visit Bakery')
  await page.waitForTimeout(1_000)
  await title.fill('One Visit Bakery Two')
  await expect
    .poll(async () => (await drafts(page)).filter((d) => !before.has(d.id)).map((d) => d.title), {
      timeout: 15_000,
    })
    .toEqual(['One Visit Bakery Two'])

  /* A refresh comes back to the same draft, answers and all, and keeps writing to it. */
  await page.reload()
  await expect(title).toHaveValue('One Visit Bakery Two', { timeout: WIZARD_PAINT_MS })
  await title.fill('One Visit Bakery Three')
  await expect
    .poll(async () => (await drafts(page)).filter((d) => !before.has(d.id)).map((d) => d.title), {
      timeout: 15_000,
    })
    .toEqual(['One Visit Bakery Three'])

  expect(posts).toHaveLength(1)

  await discard(
    page,
    (await drafts(page)).filter((d) => !before.has(d.id)).map((d) => d.id),
  )
})
