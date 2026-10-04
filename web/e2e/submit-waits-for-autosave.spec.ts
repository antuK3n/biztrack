import { expect, test, type Page } from '@playwright/test'
import { sessionFor, WIZARD_PAINT_MS } from './helpers'

/*
 * Submit waits for the wizard's pending autosave (Ken, 5 October 2026).
 *
 * Autosave is debounced, and Submit did not wait for it. An owner who changed
 * Business Area on Review from 120 to 3000 and pressed Submit → Yes at once
 * filed — and was billed — on 120; the save landed after the filing had left
 * Draft and was refused in silence (scenario run, owner-apply-new 18).
 */

test.use({ storageState: sessionFor('owner') })

test.beforeEach(async ({ page }) => {
  await page.route('**://nominatim.openstreetmap.org/**', (route) => route.abort())
})

async function api<T>(page: Page, path: string): Promise<T> {
  return page.evaluate(async (p) => {
    const token = localStorage.getItem('biztrack.token.public')
    const res = await fetch(`/api/v1${p}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
    if (!res.ok) throw new Error(`GET ${p} -> ${res.status}`)
    return (await res.json()).data
  }, path) as Promise<T>
}

/** A NEW business-permit draft complete enough to reach Review, built through the API. */
async function completeDraft(page: Page): Promise<number> {
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
            email: 'autosave.e2e@example.com',
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

    const pdf = '%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n'
    for (const dt of businessType.document_types) {
      // New, owned premises, no tax incentives.
      if (!['DTI_SEC_CDA', 'LOCATION_SKETCH', 'LAND_TITLE'].includes(dt.code)) continue
      const body = new FormData()
      body.append('document_type_id', String(dt.id))
      body.append('file', new File([pdf + dt.code], `${dt.code}.pdf`, { type: 'application/pdf' }))
      const up = await fetch(`/api/v1/applications/${app.id}/documents`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        body,
      })
      if (!up.ok) throw new Error(`uploading ${dt.code} answered ${up.status}`)
    }
    return app.id as number
  }, `E2E autosave ${Date.now()}`)
}

test('an edit made on Review and submitted at once reaches the filing', async ({
  page,
}) => {
  await page.goto('/dashboard')
  const id = await completeDraft(page)

  await page.goto(`/apply?draft=${id}`)
  const sections = page.getByRole('list', { name: 'Application sections' })
  await expect(
    sections.getByRole('button', { name: /documentary requirements.*complete/i }),
  ).toBeVisible({ timeout: WIZARD_PAINT_MS })
  await sections.getByRole('button', { name: /review & submit/i }).click()
  await expect(page.getByText(/all changes saved/i).first()).toBeVisible({ timeout: 20_000 })

  await page.getByRole('button', { name: /^change .*business area/i }).first().click()
  await page.getByRole('textbox', { name: /business area/i }).first().fill('3000')
  // Straight to Submit, inside the autosave delay.
  await page.getByRole('button', { name: /^submit/i }).click()
  await page.getByRole('button', { name: /yes, submit/i }).click()
  await expect(page.getByText(/BIZ-\d{4}-\d{5}/).first()).toBeVisible({ timeout: 30_000 })

  const filed = await api<{
    status: string
    fee_profile: { floor_area_sqm?: number | string } | null
  }>(page, `/applications/${id}`)
  expect(filed.status).toBe('for_approval')
  expect(String(filed.fee_profile?.floor_area_sqm)).toBe('3000')
})
