import { expect, test, type Page } from '@playwright/test'
import { mergedStorageState } from './helpers'

/*
 * The Assessed Fee on the review sheet is read-only, on every filing.
 *
 * Ken, 6 October 2026: staff never type the assessed fee — new, renewal or
 * amendment — and every filing shows its fixed or computed price. The sheet's
 * Edit mode used to draw an input and a "Save assessment" button behind
 * `fee.adjust`, which posted to `/applications/{id}/fee/adjust`: a route
 * removed on 2026-09-06, so pressing it answered "The route … could not be
 * found." The control is gone; the figure is the server's.
 */
test.describe.configure({ timeout: 180_000 })
test.use({ storageState: mergedStorageState(['owner.json', 'bplo.json']) })

/** The BPLO assignment for a filing on BPLO's For Approval queue, or null. */
async function bploAssignment(page: Page, kind: 'new' | 'amendment'): Promise<{ id: number; fee: string | null; amendmentFee: number | null } | null> {
  return page.evaluate(async (type) => {
    const owner = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}`,
    }
    const bplo = { ...owner, Authorization: `Bearer ${localStorage.getItem('biztrack.token.staff')}` }
    const call = async (url: string, headers: Record<string, string>, body?: unknown) => {
      const res = await fetch(url, {
        method: body === undefined ? 'GET' : 'POST',
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
      })
      if (!res.ok) throw new Error(`${url} answered ${res.status}: ${await res.text()}`)
      return (await res.json()).data
    }
    const permitTypes = await call('/api/v1/reference/permit-types', owner)
    const business = permitTypes.find((pt: { code: string }) => pt.code === 'BUSINESS')

    async function uploadAll(id: number) {
      const pdf = '%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n'
      for (const dt of business.document_types as { id: number; code: string }[]) {
        const form = new FormData()
        form.append('document_type_id', String(dt.id))
        form.append('file', new File([pdf], `${dt.code}.pdf`, { type: 'application/pdf' }))
        await fetch(`/api/v1/applications/${id}/documents`, {
          method: 'POST',
          headers: { Accept: 'application/json', Authorization: owner.Authorization },
          body: form,
        })
      }
    }

    let appId: number
    if (type === 'new') {
      const barangays = await call('/api/v1/reference/barangays', owner)
      const psic = (await call('/api/v1/reference/psic-codes', owner)).filter(
        (c: { code: string }) => c.code !== '00000',
      )
      const biz = await call('/api/v1/businesses', owner, {
        name: `E2E Assessed Fee ${Date.now()}`,
        registration_type: 'DTI',
        registration_number: 'DTI-E2E-FEE',
        tin: '123-456-789-000',
        address: {
          line1: '3 Playwright St.',
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
      const app = await call('/api/v1/applications', owner, {
        business_id: biz.id,
        application_type: 'new',
        permit_type_ids: [business.id],
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
      appId = app.id
      await uploadAll(appId)
      await call(`/api/v1/applications/${appId}/submit`, owner, {})
    } else {
      // One already waiting, else the owner amends an active Business Permit.
      const waiting = (
        await call('/api/v1/assignments?application_status=for_approval&status=pending&per_page=100', bplo)
      ).find((row: { application: { application_type?: string } | null }) => row.application?.application_type === 'amendment')
      if (waiting) {
        appId = waiting.application.id
      } else {
        const permits = (await call('/api/v1/permits?per_page=100', owner)) as {
          id: number
          status: string
          business?: { id: number } | null
          permit_type?: { code: string } | null
        }[]
        const prior = permits.find((p) => p.status === 'active' && p.permit_type?.code === 'BUSINESS' && p.business)
        if (!prior) return null
        const res = await fetch('/api/v1/applications', {
          method: 'POST',
          headers: owner,
          body: JSON.stringify({
            business_id: prior.business!.id,
            data_privacy_consent: true,
            application_type: 'amendment',
            permit_type_ids: [business.id],
            prior_permit_id: prior.id,
            amendment_other: 'Floor area corrected after re-measurement.',
          }),
        })
        if (!res.ok) return null
        appId = (await res.json()).data.id
        await call(`/api/v1/applications/${appId}/amendments`, owner, {
          changes: [{ field: 'business_area_sqm', new_value: '250' }],
        })
        await uploadAll(appId)
        await call(`/api/v1/applications/${appId}/submit`, owner, {})
      }
    }

    const queue = await call('/api/v1/assignments?application_status=for_approval&status=pending&per_page=100', bplo)
    const row = queue.find((r: { application: { id: number } | null }) => r.application?.id === appId)
    if (!row) return null
    const app = await call(`/api/v1/applications/${appId}`, bplo)
    return {
      id: row.id as number,
      fee: (app.fee_assessment?.total_amount ?? null) as string | null,
      amendmentFee: (app.amendment_fee ?? null) as number | null,
    }
  }, kind)
}

const peso = (n: number) =>
  new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(n)

async function openInEditMode(page: Page, assignmentId: number) {
  await page.goto(`/staff/queue/${assignmentId}`)
  await page.getByRole('button', { name: 'Edit', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Edit', exact: true })).toHaveAttribute('aria-pressed', 'true')
}

function assessedFee(page: Page) {
  return page.locator('dl').filter({ has: page.getByText('Assessed Fee (Php)', { exact: true }) }).locator('dd')
}

test('a new filing’s assessed fee is the computed bill, and Edit mode offers no way to change it', async ({ page }) => {
  await page.goto('/dashboard')
  const found = await bploAssignment(page, 'new')
  expect(found, 'the new filing reached BPLO’s queue').not.toBeNull()
  expect(found!.fee).not.toBeNull()

  await openInEditMode(page, found!.id)

  await expect(assessedFee(page)).toHaveText(peso(Number(found!.fee)))
  await expect(page.getByRole('button', { name: 'Save assessment' })).toHaveCount(0)
  await expect(page.getByRole('textbox', { name: /Assessed Fee/ })).toHaveCount(0)
})

test('an amendment’s assessed fee is its fixed price', async ({ page }) => {
  await page.goto('/dashboard')
  const found = await bploAssignment(page, 'amendment')
  test.skip(found === null, 'no amendment could be put on BPLO’s queue from this register')

  expect(found!.fee).toBeNull()
  expect(found!.amendmentFee).toBe(200)

  await openInEditMode(page, found!.id)

  await expect(assessedFee(page)).toHaveText(peso(200))
  await expect(page.getByRole('button', { name: 'Save assessment' })).toHaveCount(0)
})
