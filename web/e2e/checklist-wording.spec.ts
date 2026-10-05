import { expect, test, type Page } from '@playwright/test'
import { sessionFor, WIZARD_PAINT_MS } from './helpers'

/*
 * Wording the client's System Testing Checklist asked for, checked where the
 * applicant reads it.
 */

test.use({ storageState: sessionFor('owner') })

test.beforeEach(async ({ page }) => {
  await page.route('**://nominatim.openstreetmap.org/**', (route) => route.abort())
})

/**
 * A NEW business-permit draft whose Location & Zoning and Business Information
 * are finished, built through the API, so the section map lets the wizard open
 * Business Operation.
 */
async function draftPastBusinessInformation(page: Page): Promise<number> {
  return page.evaluate(async (bizName) => {
    const token = localStorage.getItem('biztrack.token.public')
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    }
    const json = async (res: Response) => {
      if (!res.ok) throw new Error(`${res.url} answered ${res.status}: ${await res.text()}`)
      return (await res.json()).data
    }
    const barangays = await json(await fetch('/api/v1/reference/barangays', { headers }))
    const allPsic = await json(await fetch('/api/v1/reference/psic-codes', { headers }))
    const psic =
      allPsic.find((c: { code: string }) => c.code === '47111') ??
      allPsic.find((c: { code: string }) => c.code !== '00000')
    const permitTypes = await json(await fetch('/api/v1/reference/permit-types', { headers }))
    const longos = barangays.find((b: { name: string }) => b.name === 'Longos') ?? barangays[0]

    const business = await json(
      await fetch('/api/v1/businesses', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          name: bizName,
          registration_type: 'sole_proprietorship',
          registration_number: `DTI-${Math.floor(100000 + Math.random() * 899999)}`,
          tin: '123-456-789-000',
          address: {
            house_bldg_no: '3',
            street: 'Playwright St.',
            barangay_id: longos.id,
            latitude: 14.6572,
            longitude: 120.9573,
            mobile_number: '+63 917 123 4567',
            email: 'wording.e2e@example.com',
          },
          owner: { surname: 'Dela Cruz', given_name: 'Ana', gender: 'F' },
          citizenship: 'Filipino',
          emergency_contact_name: 'Ana Dela Cruz',
          emergency_contact_number: '+63 917 765 4321',
          economic_organization: 'single_establishment',
          capital_investment: 500000,
          is_rented: false,
          has_tax_incentives: false,
          lines: [
            {
              psic_code_id: psic.id,
              capitalization: 500000,
              products_services: 'Bottled drinks, packaged snacks',
            },
          ],
        }),
      }),
    )

    const businessType = permitTypes.find((pt: { code: string }) => pt.code === 'BUSINESS')
    const app = await json(
      await fetch('/api/v1/applications', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          business_id: business.id,
          application_type: 'new',
          title: bizName,
          data_privacy_consent: true,
          permit_type_ids: [businessType.id],
          fee_profile: {
            business_structure: 'sole_proprietorship',
            floor_area_sqm: 120,
            employees: 12,
            employees_in_lgu: 6,
            male_employees: 7,
            female_employees: 5,
            lines: [{ psic_code_id: psic.id, category: 'retailer', capitalization: 500000 }],
          },
        }),
      }),
    )
    return app.id as number
  }, `E2E wording ${Date.now()}`)
}

test('each employee and delivery box says "No. of" what it counts (Apply for Permit 5)', async ({
  page,
}) => {
  await page.goto('/dashboard')
  const id = await draftPastBusinessInformation(page)

  await page.goto(`/apply?draft=${id}`)
  const sections = page.getByRole('list', { name: 'Application sections' })
  await sections
    .getByRole('button', { name: /business operation/i })
    .click({ timeout: WIZARD_PAINT_MS })

  const employees = page.getByRole('group', { name: 'Total number of employees' })
  await expect(employees.getByRole('textbox', { name: /^Total No\. of Employees/ })).toBeVisible()
  await expect(employees.getByRole('textbox', { name: /^No\. of Male Employees/ })).toHaveValue('7')
  await expect(employees.getByRole('textbox', { name: /^No\. of Female Employees/ })).toHaveValue('5')

  const delivery = page.getByRole('group', { name: 'Number of delivery units' })
  await expect(delivery.getByRole('textbox', { name: /^No\. of Motorized Delivery Units/ })).toBeVisible()
  await expect(delivery.getByRole('textbox', { name: /^No\. of Other Delivery Units/ })).toBeVisible()
})
