import { expect, test, type Page } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * The Debug page's "Move a filing along" section
 * (App\Services\Debug\FilingMover) [Ken, 2026-10-04].
 *
 * At the defense the super admin takes a filing the owner has just submitted
 * and pushes it along on screen: BPLO accepts the form, the bill is paid as a
 * simulated payment and the Business Permit comes out. These drive exactly
 * that through the page, and the return path a panelist may ask to see.
 *
 * ── What the stack needs ──────────────────────────────────────────────────
 *
 * The panel open on the stack's copy of the register:
 *   DB_DATABASE=<the copy> php artisan biztrack:debug-panel on --hours=6
 * (or APP_ENV=local, which needs no flag). Closed, the page is not there
 * and the first test fails on its heading.
 */

test.describe.configure({ mode: 'serial', timeout: 180_000 })

const SHOTS = process.env.DEBUG_SHOTS_DIR ?? ''

async function shot(page: Page, name: string) {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true })
}

/**
 * A new filing the owner has made and submitted, so it waits for BPLO.
 *
 * The first half of payments.ts `makeBilledApplication`, stopped before BPLO
 * acts: that half is what this spec hands to the Debug page. Kept here
 * rather than split out of payments.ts, which is being changed on another
 * branch at the same time.
 */
async function makeSubmittedApplication(page: Page): Promise<{ id: number; tracking_id: string }> {
  await page.goto('/dashboard')
  await expect(page.getByRole('heading').first()).toBeVisible({ timeout: 30_000 })

  return page.evaluate(async () => {
    const owner = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}`,
    }
    const call = async (url: string, body?: unknown) => {
      const res = await fetch(url, {
        method: body === undefined ? 'GET' : 'POST',
        headers: owner,
        body: body === undefined ? undefined : JSON.stringify(body),
      })
      if (!res.ok) throw new Error(`${url} answered ${res.status}: ${await res.text()}`)
      return (await res.json()).data
    }

    const barangays = await call('/api/v1/reference/barangays')
    const psic = (await call('/api/v1/reference/psic-codes')).filter((c: { code: string }) => c.code !== '00000')
    const permitTypes = await call('/api/v1/reference/permit-types')
    const business = await call('/api/v1/businesses', {
      name: `E2E Debug Mover ${Date.now()}`,
      registration_type: 'DTI',
      registration_number: 'DTI-E2E-MOVE',
      tin: '123-456-789-000',
      address: {
        line1: '5 Playwright St.',
        barangay_id: (barangays.find((b: { name: string }) => b.name === 'Longos') ?? barangays[0]).id,
        latitude: 14.6572,
        longitude: 120.9573,
      },
      emergency_contact_name: 'Ana Dela Cruz',
      emergency_contact_number: '0917 123 4567',
      economic_organization: 'single_establishment',
      capital_investment: 500000,
      lines: [{ psic_code_id: psic[0].id, capitalization: 500000, products_services: 'milk tea' }],
    })
    const app = await call('/api/v1/applications', {
      business_id: business.id,
      application_type: 'new',
      permit_type_ids: [permitTypes.find((pt: { code: string }) => pt.code === 'BUSINESS').id],
      data_privacy_consent: true,
      fee_profile: {
        business_structure: 'sole_proprietorship',
        floor_area_sqm: 120,
        employees: 12,
        employees_in_lgu: 6,
        male_employees: 7,
        female_employees: 5,
        lines: [{ psic_code_id: psic[0].id, category: 'retailer', capitalization: 500000 }],
      },
    })
    const submitted = await call(`/api/v1/applications/${app.id}/submit`, {})

    return { id: app.id as number, tracking_id: submitted.tracking_id as string }
  })
}

/** The filing as the server holds it, read with the super admin's token. */
async function serverFiling(page: Page, id: number): Promise<{ status: string; permits: { code: string; permit_number: string | null }[] }> {
  return page.evaluate(async (filingId) => {
    const res = await fetch(`/api/v1/debug/filings/${filingId}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('biztrack.token.admin')}` },
    })
    return (await res.json()).data
  }, id)
}

async function openFiling(page: Page, filing: { tracking_id: string }) {
  await page.goto('/admin/debug#filings')
  await expect(page.getByRole('heading', { name: 'Move a filing along', level: 2 })).toBeVisible({ timeout: 30_000 })

  await page.getByLabel('Tracking ID').fill(filing.tracking_id)
  await page.getByRole('button', { name: 'Find', exact: true }).click()
  await page.getByRole('button', { name: new RegExp(`^${filing.tracking_id},`) }).click()

  return page.getByRole('region', { name: filing.tracking_id })
}

test.describe('the super admin', () => {
  test.use({ storageState: sessionFor('admin') })

  test('takes a fresh filing from For Approval to ready to pay, then to paid with its Business Permit issued', async ({
    page,
    browser,
  }) => {
    const ownerContext = await browser.newContext({ storageState: sessionFor('owner') })
    const filing = await makeSubmittedApplication(await ownerContext.newPage())
    await ownerContext.close()

    await page.setViewportSize({ width: 1280, height: 900 })
    const card = await openFiling(page, filing)
    const steps = page.getByRole('region', { name: 'Next steps' })
    await expect(card).toContainText('For Approval')
    await expect(steps.getByRole('button', { name: 'Advance to Ready to pay' })).toBeVisible()
    await shot(page, 'mover-1-for-approval-1280')

    // One step: BPLO's own Approve, as BPLO's screen presses it.
    await steps.getByRole('button', { name: 'BPLO accepts the form' }).click()
    await expect(steps.getByText('BPLO accepts the form: done')).toBeVisible()
    await expect(steps.getByText('Filing: For Approval → Pending Payment')).toBeVisible()
    await expect(card).toContainText('Pending Payment')
    expect((await serverFiling(page, filing.id)).status).toBe('pending_payment')
    await shot(page, 'mover-2-ready-to-pay-1280')

    // Then "Advance to Paid": the simulated payment, and the permit with it.
    await steps.getByRole('button', { name: 'Advance to Paid' }).click()
    await expect(steps.getByText('Advance to Paid: there, in 1 step.')).toBeVisible({ timeout: 30_000 })
    await expect(steps.getByText('Pay the bill: done')).toBeVisible()
    // The prefix is the register's own (permit_types.permit_number_prefix).
    await expect(card.getByText(/^Issued [A-Z]+-\d{4}-\d+$/)).toBeVisible()
    // The clearances are the applicant's to apply for, and the page says so.
    await expect(card.getByText('What is holding it')).toBeVisible()
    await expect(card).toContainText('the applicant has not applied for it yet')

    const after = await serverFiling(page, filing.id)
    expect(after.status).toBe('awaiting_other_permits')
    expect(after.permits.find((p) => p.code === 'BUSINESS')?.permit_number).toMatch(/^[A-Z]+-\d{4}-\d+$/)
    await shot(page, 'mover-3-paid-1280')

    // Nothing pushes the page sideways on a phone.
    await page.setViewportSize({ width: 390, height: 844 })
    await expect(card).toBeVisible()
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
    expect(overflow).toBeLessThanOrEqual(0)
    await shot(page, 'mover-4-paid-390')
  })

  test('returns a filing to the applicant only with a note, and says why in the office screen’s words', async ({
    page,
    browser,
  }) => {
    const ownerContext = await browser.newContext({ storageState: sessionFor('owner') })
    const filing = await makeSubmittedApplication(await ownerContext.newPage())
    await ownerContext.close()

    await page.setViewportSize({ width: 1280, height: 900 })
    const card = await openFiling(page, filing)
    const steps = page.getByRole('region', { name: 'Next steps' })
    const returnForm = steps.getByRole('button', { name: 'BPLO returns the form to the applicant' })

    await returnForm.click()
    await expect(steps.getByText('Explain what the applicant needs to fix.')).toBeVisible()
    await expect(steps.getByLabel('Note')).toBeFocused()
    expect((await serverFiling(page, filing.id)).status).toBe('for_approval')

    await steps.getByLabel('Note').fill('The trade name does not match the DTI certificate.')
    await returnForm.click()
    await expect(steps.getByText('BPLO returns the form to the applicant: done')).toBeVisible()
    await expect(card).toContainText('Returned')
    await expect(card).toContainText('It moves again when the applicant resubmits it.')
    expect((await serverFiling(page, filing.id)).status).toBe('returned')
    // Nothing forward to offer: it is the applicant's move.
    await expect(steps.getByRole('button', { name: /^Advance to/ })).toHaveCount(0)
    await expect(steps.getByText('Nothing on this page can move it from here.')).toBeVisible()
    // And the list it was chosen from says so too.
    await expect(page.getByRole('button', { name: new RegExp(`^${filing.tracking_id},.*Returned$`) })).toBeVisible()
    await shot(page, 'mover-5-returned-1280')
  })
})
