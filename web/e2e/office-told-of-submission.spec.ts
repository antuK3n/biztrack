import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * BPLO hears of a new application in its bell.
 *
 * Thesis panel revision (Tool Defense matrix, item 8): "The office handling an
 * application is notified when an applicant submits it."
 * `SubmissionNotifiesItsOfficeTest` pins who is told and what the notice says,
 * office by office. This is the part only a browser can show: the officer's
 * bell counts it, the notice reads as worded, and opening it lands on BPLO's
 * review of THAT filing. The link names the office's own review rather than
 * the application id, which can belong to another filing's review.
 *
 * Screenshots go to E2E_SHOTS_DIR when it is set, for review by eye.
 */

const SHOTS = process.env.E2E_SHOTS_DIR

test('BPLO’s bell announces a new application and opens its review', async ({ browser }) => {
  test.setTimeout(240_000)

  /* ── The owner files and submits, through the API the wizard uses ─────── */
  const applicant = await browser.newContext({ storageState: sessionFor('owner') })
  const page = await applicant.newPage()
  await page.goto('/dashboard')
  await expect(page.getByRole('heading').first()).toBeVisible({ timeout: 30_000 })

  const filed = await page.evaluate(async () => {
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
    const psic = (await call('/api/v1/reference/psic-codes')).filter(
      (c: { code: string }) => c.code !== '00000',
    )
    const permitTypes = await call('/api/v1/reference/permit-types')
    const businessType = permitTypes.find((pt: { code: string }) => pt.code === 'BUSINESS')
    const business = await call('/api/v1/businesses', {
      name: `E2E Bell Store ${Date.now()}`,
      registration_type: 'DTI',
      registration_number: 'DTI-E2E-BELL',
      tin: '123-456-789-000',
      address: {
        line1: '5 Bell Street',
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
      permit_type_ids: [businessType.id],
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

    // Every business-permit document, as real PDF bytes: submit refuses a
    // filing missing a required one, and the API sniffs `mimes:pdf`.
    const pdf = '%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n'
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

    const submitted = await call(`/api/v1/applications/${app.id}/submit`, {})
    return { tracking: submitted.tracking_id as string, name: business.name as string }
  })
  await applicant.close()

  /* ── BPLO's bell ──────────────────────────────────────────────────────── */
  const office = await browser.newContext({ storageState: sessionFor('bplo') })
  const staff = await office.newPage()
  await staff.goto('/staff/notifications')
  await expect(staff.getByRole('heading', { name: 'Notifications' })).toBeVisible({ timeout: 30_000 })

  await expect(staff.getByRole('link', { name: /^Notifications, \d+ unread$/ })).toBeVisible()

  const notice = staff
    .getByRole('listitem')
    .filter({ hasText: `${filed.tracking} · ${filed.name} was submitted.` })
  await expect(notice).toBeVisible()
  await expect(notice).toContainText('New application')

  if (SHOTS) await staff.screenshot({ path: `${SHOTS}/nt-bell.png` })

  /* ── And the notice opens BPLO's review of this filing, not another ───── */
  await notice.getByRole('link').click()
  await expect(staff).toHaveURL(/\/staff\/queue\/\d+$/)
  await expect(staff.getByText(filed.tracking).first()).toBeVisible({ timeout: 30_000 })

  await office.close()
})
