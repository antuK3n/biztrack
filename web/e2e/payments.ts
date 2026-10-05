import { expect, type Browser, type Page } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * Shared by the specs that pay: payment-gateway.spec.ts (the owner's pay
 * screen in both modes) and debug-payments.spec.ts (the super admin's
 * switches and what the owner is told about them). Moved here from the first
 * when the second needed the same filing; two copies of a walk through
 * submission and BPLO's approval would drift the first time either changed.
 */

/**
 * A filing of the owner's that BPLO has approved, so it is waiting for payment.
 * Owner makes and submits it; BPLO classifies and approves (see
 * clearances.spec.ts `makePaidApplication` for why each act is needed).
 */
export async function makeBilledApplication(page: Page): Promise<number> {
  await page.goto('/dashboard')
  await expect(page.getByRole('heading').first()).toBeVisible({ timeout: 30_000 })

  return page.evaluate(async () => {
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

    const barangays = await call('/api/v1/reference/barangays', owner)
    const psic = (await call('/api/v1/reference/psic-codes', owner)).filter(
      (c: { code: string }) => c.code !== '00000',
    )
    const permitTypes = await call('/api/v1/reference/permit-types', owner)
    const business = await call('/api/v1/businesses', owner, {
      name: `E2E Online Payment ${Date.now()}`,
      registration_type: 'DTI',
      registration_number: 'DTI-E2E-PAY',
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
    /*
     * Submit refuses a filing missing a required document (RequiredDocuments,
     * whose list turns on the business's own circumstances), so upload every
     * business-permit document rather than guess which apply — real PDF
     * bytes, since the API sniffs `mimes:pdf`.
     */
    const pdf = '%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n'
    const businessType = permitTypes.find((pt: { code: string }) => pt.code === 'BUSINESS')
    for (const dt of businessType.document_types as { id: number; code: string }[]) {
      const body = new FormData()
      body.append('document_type_id', String(dt.id))
      body.append('file', new File([pdf], `${dt.code}.pdf`, { type: 'application/pdf' }))
      const up = await fetch(`/api/v1/applications/${app.id}/documents`, {
        method: 'POST',
        headers: { Accept: owner.Accept, Authorization: owner.Authorization },
        body,
      })
      if (!up.ok) throw new Error(`uploading ${dt.code} answered ${up.status}: ${await up.text()}`)
    }
    await call(`/api/v1/applications/${app.id}/submit`, owner, {})

    const queue = await call(
      '/api/v1/assignments?application_status=for_approval&status=pending&per_page=100',
      bplo,
    )
    const assignment = queue.find(
      (row: { application: { id: number } | null }) => row.application?.id === app.id,
    )
    if (!assignment) throw new Error(`filing ${app.id} is not on BPLO's queue`)
    await call(`/api/v1/assignments/${assignment.id}/classification`, bplo, { tier: 'simple' })
    // BPLO ticks the other permits at this approval since 5 October 2026; all five here.
    await call(`/api/v1/assignments/${assignment.id}/approve`, bplo, {
      permit_type_ids: permitTypes
        .filter((pt: { code: string }) => ['SANITARY', 'FSIC', 'ZONING', 'OCCUPANCY', 'CEC'].includes(pt.code))
        .map((pt: { id: number }) => pt.id),
    })

    return app.id as number
  })
}

export interface GatewayState {
  mode: 'simulated' | 'kwikpay'
  charge: 'test' | 'full'
  test_amount: string
  kwikpay: { configured: boolean; fake_available: boolean }
}

/**
 * Read the payment switches as the super admin, or change them and read the
 * result. Through the API rather than the Debug page, for specs whose subject
 * is somewhere else; debug-payments.spec.ts drives the page itself.
 */
export async function gateway(
  browser: Browser,
  change?: { mode?: 'simulated' | 'kwikpay'; charge?: 'test' | 'full' },
): Promise<{ ok: boolean; status: number; data: GatewayState | null; text: string }> {
  const context = await browser.newContext({ storageState: sessionFor('admin') })
  const page = await context.newPage()
  await page.goto('/admin/login')
  const result = await page.evaluate(async (body) => {
    const res = await fetch('/api/v1/admin/payment-gateway', {
      method: body ? 'PUT' : 'GET',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${localStorage.getItem('biztrack.token.admin')}`,
      },
      body: body ? JSON.stringify(body) : undefined,
    })
    const text = await res.text()
    let data = null
    try {
      data = JSON.parse(text).data ?? null
    } catch {
      data = null
    }
    return { ok: res.ok, status: res.status, data, text }
  }, change ?? null)
  await context.close()
  return result
}
