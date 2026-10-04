import { expect, test } from '@playwright/test'

/**
 * Business Owner Status — the blacklisting, end to end.
 *
 * [Client, 5 October 2026.] One row per owner. Blacklisting the owner
 * suspends every business they hold; while it stands those businesses cannot
 * be changed (a modal says the owner is blacklisted); reinstating returns
 * them. The stubbed layout tests live in admin-screens.spec.ts — this one
 * WRITES to the throwaway stack, because the cascade is the point, and puts
 * the owner back the way it found them.
 */

test.describe('Business Owner Status, against the stack', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/admin/owners')
    await expect(page.getByRole('heading', { name: /Business Owner Status/i })).toBeVisible()
  })

  test('blacklisting an owner suspends their businesses and locks them, and reinstating brings them back', async ({
    page,
  }) => {
    await page.getByRole('button', { name: 'Active', exact: true }).click()
    const row = page.locator('tbody tr').first()
    await expect(row, 'no active owner on this register').toBeVisible({ timeout: 30_000 })
    const name = (await row.locator('td').first().locator('span').first().innerText()).trim()

    // Blacklist.
    await row.getByRole('button', { name: `Change the status of ${name}` }).click()
    let dialog = page.getByRole('dialog')
    await dialog.getByRole('textbox', { name: 'Reason' }).fill('E2E check — blacklisting.')
    await dialog.getByRole('button', { name: 'Save status' }).click()
    await expect(dialog).toHaveCount(0)

    await page.getByRole('button', { name: 'Blacklisted', exact: true }).click()
    await page.getByRole('searchbox', { name: /Search owners/ }).fill(name)
    const barred = page.locator('tbody tr', { hasText: name })
    await expect(barred).toContainText('Blacklisted', { timeout: 20_000 })

    // Its businesses read Suspended, and changing one opens the lock modal.
    const toggle = barred.getByRole('button', { name: /\d+ businesses/ })
    if (await toggle.count()) await toggle.click()
    await expect(barred).toContainText('Suspended')
    await barred.getByRole('button', { name: /^Change the status of / }).nth(1).click()
    await expect(page.getByRole('dialog', { name: 'The owner is blacklisted' })).toBeVisible()
    await page.getByRole('dialog').getByRole('button', { name: 'OK' }).click()

    // Reinstate — and the register is as it was found.
    await barred.getByRole('button', { name: `Change the status of ${name}` }).click()
    dialog = page.getByRole('dialog')
    await dialog.getByRole('textbox', { name: 'Reason' }).fill('E2E check — reinstated.')
    await dialog.getByRole('button', { name: 'Save status' }).click()
    await expect(dialog).toHaveCount(0)

    await page.getByRole('button', { name: 'All', exact: true }).click()
    await expect(page.locator('tbody tr', { hasText: name })).toContainText('Active', { timeout: 20_000 })
  })
})
