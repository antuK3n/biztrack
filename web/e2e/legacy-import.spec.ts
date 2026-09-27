import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * IMPORT RECORDS — the super admin brings the old register in (Ken's
 * checklist, 27 September 2026, "Migration 1").
 *
 * Against the real API, not stubbed: the point is that the dry run the admin
 * reads is the one the server computed, and that confirming it writes. It
 * writes only businesses keyed on a run-unique legacy id, so it is safe on a
 * throwaway stack and never collides with a previous run — but like every
 * mutating spec it must never be pointed at :5173 (AGENTS.md §2.3).
 */

test.use({ storageState: sessionFor('admin') })

const HEADER =
  'legacy_business_id,business_name,trade_name,organization_type,registration_number,business_account_no,tin,owner_legacy_id,owner_first_name,owner_middle_name,owner_last_name,owner_suffix,owner_email,owner_mobile,address_line,barangay,legacy_permit_id,permit_type,permit_number,valid_from,valid_until,permit_status'

function csv(run: string): string {
  const row = (id: string, name: string, barangay: string, permitType: string, from: string, until: string, last = 'Aquino') =>
    [`E2E-${run}-${id}`, name, '', 'sole_proprietorship', '', '', '', '', 'Lorna', '', last, '', '', '', '1 Test St', barangay,
      `E2EP-${run}-${id}`, permitType, `E2E-${run}-${id}-MP`, from, until, ''].join(',')
  return [
    HEADER,
    row('1', 'E2E Bakery', 'Catmon', 'BUSINESS', '2025-01-10', '2099-12-31'),
    row('2', 'E2E Hardware', 'Tañong', 'BUSINESS', '01/10/2025', '12/31/2099'),
    row('3', 'E2E Bad Date', 'Catmon', 'BUSINESS', '2025-01-10', '31/12/2025'),
    row('4', 'E2E Atlantis', 'Atlantis', 'BUSINESS', '2025-01-10', '2099-12-31'),
    row('5', 'E2E Liquor', 'Catmon', 'LIQUOR', '2025-01-10', '2099-12-31'),
    row('6', 'E2E Nobody', 'Catmon', 'BUSINESS', '2025-01-10', '2099-12-31', ''),
  ].join('\n')
}

test('the admin checks a file, reads why rows were refused, and imports the rest', async ({ page }) => {
  const run = `${Date.now()}`
  await page.goto('/admin/import')
  await expect(page.getByRole('heading', { name: 'Import Records' })).toBeVisible()

  await page.getByLabel('CSV file to import').setInputFiles({
    name: 'old-register.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(csv(run), 'utf-8'),
  })
  await page.getByRole('button', { name: 'Check the file' }).click()

  // The dry run: nothing written yet, and the four numbers say what would be.
  await expect(page.getByRole('heading', { name: /Checked — nothing imported yet/ })).toBeVisible()
  await expect(page.getByTestId('count-total')).toHaveText('6')
  await expect(page.getByTestId('count-create')).toHaveText('2')
  await expect(page.getByTestId('count-update')).toHaveText('0')
  await expect(page.getByTestId('count-rejected')).toHaveText('4')

  // Every refused row carries its reason, in words.
  const rejects = page.getByRole('table', { name: 'Rejected rows' })
  await expect(rejects.getByRole('row')).toHaveCount(5) // header + 4
  await expect(rejects).toContainText('Bad date:')
  await expect(rejects).toContainText('Unknown barangay:')
  await expect(rejects).toContainText('Unknown permit type:')
  await expect(rejects).toContainText('Missing owner:')
  await page.screenshot({
    path: `${process.env.E2E_SCREENSHOT_DIR ?? '/tmp'}/import-dry-run.png`,
    fullPage: true,
  })

  await page.getByRole('button', { name: 'Import 2 rows' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Imported' })).toBeVisible()
  await expect(page.getByTestId('count-create')).toHaveText('2')
  await expect(page.getByRole('heading', { name: 'Recent imports' })).toBeVisible()
  await expect(page.getByRole('row').filter({ hasText: 'old-register.csv' }).first()).toContainText('Imported')
  await page.screenshot({
    path: `${process.env.E2E_SCREENSHOT_DIR ?? '/tmp'}/import-done.png`,
    fullPage: true,
  })

  // Re-importing the same file updates what the first run made; nothing new.
  await page.getByRole('button', { name: 'Start another import' }).click()
  await page.getByLabel('CSV file to import').setInputFiles({
    name: 'old-register.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(csv(run), 'utf-8'),
  })
  await page.getByRole('button', { name: 'Check the file' }).click()
  await expect(page.getByTestId('count-create')).toHaveText('0')
  await expect(page.getByTestId('count-update')).toHaveText('2')
})

test('a file missing a required column is refused before anything is checked, and says which', async ({ page }) => {
  await page.goto('/admin/import')
  await page.getByLabel('CSV file to import').setInputFiles({
    name: 'wrong.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from('legacy_business_id,business_name\nX-1,Shop\n', 'utf-8'),
  })
  await page.getByRole('button', { name: 'Check the file' }).click()
  await expect(page.getByRole('alert')).toContainText('owner_last_name')
  await expect(page.getByRole('heading', { name: /Checked/ })).toHaveCount(0)
})

test('the column guide lists every template column', async ({ page }) => {
  await page.goto('/admin/import')
  await page.getByText(/Column guide — what goes in each of the 22 columns/).click()
  await expect(page.getByRole('cell', { name: 'legacy_business_id', exact: true })).toBeVisible()
  await expect(page.getByRole('cell', { name: 'valid_until', exact: true })).toBeVisible()
})
