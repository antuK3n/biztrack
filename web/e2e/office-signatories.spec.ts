import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * OFFICE SIGNATORIES — the super admin adds, edits and retires the names each
 * office signs with. The last current name is the "Noted by" on the office's
 * reports, which is why the screen marks it.
 *
 * Against the real API, not stubbed: what matters is that a save reaches the
 * register and the screen re-reads it. Each run writes one signatory to CHO
 * under a run-unique position and retires it again, so it is repeatable on a
 * throwaway stack — but like every mutating spec it must never be pointed at
 * :5173 (AGENTS.md §2.3).
 *
 * The permission rule itself (super admin only, everyone else 403) is proved
 * server-side in OfficeSignatoriesTest; the BPLO test below only checks the
 * rail agrees with it.
 */

const SHOTS = process.env.E2E_SCREENSHOT_DIR ?? '/tmp'

test.describe('as the super admin', () => {
  test.use({ storageState: sessionFor('admin') })

  test('adds a signatory, edits them, and retires them', async ({ page }) => {
    const run = `${Date.now()}`.slice(-6)
    const name = `Dr. Ana Santos ${run}`
    const position = `City Health Officer ${run}`

    await page.goto('/admin/office-signatories')
    await expect(page.getByRole('heading', { name: 'Office Signatories', level: 1 })).toBeVisible()
    // Reached from the rail, not only by typing the address.
    await expect(
      page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Office Signatories' }),
    ).toHaveAttribute('href', '/admin/office-signatories')
    await expect(page.getByText('The last one prints as “Noted by” on that office’s reports.')).toBeVisible()

    const cho = page.getByRole('region', { name: 'CHO' })

    // ── Add, with one refused save first: the API's sentence reaches the field.
    await cho.getByRole('button', { name: 'Add a signatory to CHO' }).click()
    let dialog = page.getByRole('dialog', { name: 'Add signatory' })
    await dialog.getByRole('button', { name: 'Add signatory' }).click()
    await expect(dialog.getByText('Enter the name as it should be printed.')).toBeVisible()
    await expect(dialog.getByLabel('Name')).toHaveAttribute('aria-invalid', 'true')

    await dialog.getByLabel('Name').fill(name)
    await dialog.getByLabel('Position').fill(position)
    await dialog.getByRole('button', { name: 'Add signatory' }).click()
    await expect(dialog).toBeHidden()

    await expect(page.getByRole('status').filter({ hasText: `${name} is added to CHO.` })).toBeVisible()
    let row = cho.getByRole('listitem').filter({ hasText: name })
    await expect(row).toContainText(position)
    // The newcomer goes to the end of the block, so they are the one who signs last.
    await expect(row).toContainText('Noted by')

    await page.setViewportSize({ width: 1440, height: 900 })
    await page.screenshot({ path: `${SHOTS}/office-signatories-1440.png`, fullPage: true })

    // ── Edit: name and position change, and the screen re-reads them.
    const renamed = `${name} Jr.`
    await row.getByRole('button', { name: `Edit ${name}` }).click()
    dialog = page.getByRole('dialog', { name: 'Edit signatory' })
    await expect(dialog.getByLabel('Name')).toHaveValue(name)
    await dialog.getByLabel('Name').fill(renamed)
    await dialog.getByLabel('Position').fill(`Acting ${position}`)
    await dialog.getByRole('button', { name: 'Save changes' }).click()
    await expect(dialog).toBeHidden()

    await expect(page.getByRole('status').filter({ hasText: `${renamed} is saved.` })).toBeVisible()
    row = cho.getByRole('listitem').filter({ hasText: renamed })
    await expect(row).toContainText(`Acting ${position}`)

    // ── Retire, behind a confirmation that says what happens to "Noted by".
    await row.getByRole('button', { name: `Retire ${renamed}` }).click()
    dialog = page.getByRole('dialog', { name: 'Retire signatory' })
    await expect(dialog).toContainText(`Retire ${renamed}`)
    await expect(dialog).toContainText('Noted by')
    await page.setViewportSize({ width: 390, height: 844 })
    await page.screenshot({ path: `${SHOTS}/office-signatories-retire-390.png` })
    await dialog.getByRole('button', { name: 'Retire', exact: true }).click()
    await expect(dialog).toBeHidden()

    await expect(page.getByRole('status').filter({ hasText: `${renamed} is retired.` })).toBeVisible()
    await expect(cho.getByRole('list', { name: /in printed order/ }).getByText(renamed)).toHaveCount(0)

    // Kept, not deleted: the retired entry is still on record, under the fold.
    await cho.getByText(/^Retired \(\d+\)$/).click()
    await expect(cho.getByRole('button', { name: `Edit ${renamed}` })).toBeVisible()

    // Nothing on the phone layout runs off the side of the screen.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
    expect(overflow).toBeLessThanOrEqual(0)
    await page.screenshot({ path: `${SHOTS}/office-signatories-390.png`, fullPage: true })
  })
})

test.describe('as BPLO', () => {
  test.use({ storageState: sessionFor('bplo') })

  test('the rail offers no Office Signatories, and the address sends them home', async ({ page }) => {
    await page.goto('/staff/admin/office-signatories')
    await expect(page).toHaveURL(/\/staff\/dashboard$/)
    await expect(page.getByRole('link', { name: 'Office Signatories' })).toHaveCount(0)
  })
})
