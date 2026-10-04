import { test, expect, type Page } from '@playwright/test'
import { mergedStorageState, sessionFor } from './helpers'

/*
 * What the two zoning screens say about City Ordinance No. 24-2018.
 *
 * Both used to carry a rule-by-rule checklist — every rule with its article,
 * page and a met / not met / CPDO checks status, and the questions those rules
 * needed answered. Ken removed it on 5 October 2026, from the applicant's
 * Location & Zoning step, from Review and from CPDO's review sheet. What is
 * left to prove in a browser is that it is gone, and that the note under the
 * map still reads a trade against the ordinance's own words.
 *
 * Screenshots go to E2E_SHOTS_DIR when it is set, for review by eye.
 */

const SHOTS = process.env.E2E_SHOTS_DIR

async function shot(page: Page, name: string, target?: ReturnType<Page['locator']>) {
  if (!SHOTS) return
  if (target) await target.screenshot({ path: `${SHOTS}/${name}.png` })
  else await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true })
}

test.describe('the applicant', () => {
  test.use({ storageState: sessionFor('owner') })

  test.beforeEach(async ({ page }) => {
    // The address geocoder is a third-party service this spec has nothing to
    // say about; aborted, it behaves as the ordinary miss (see apply-wizard).
    await page.route('**://nominatim.openstreetmap.org/**', (route) => route.abort())
    await page.goto('/apply')
    await expect(page.getByText(/data privacy/i).first()).toBeVisible({ timeout: 30_000 })
  })

  test('Location & Zoning shows no rule checklist, under the map or on Review', async ({ page }) => {
    await page.getByRole('checkbox').first().check()
    await page.getByRole('button', { name: /next/i }).click()
    await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

    // A sari-sari store in Longos, which is where the checklist used to open.
    const search = page.getByLabel(/search for the one line of business/i)
    await search.click()
    await search.fill('sari-sari')
    await expect(page.getByText(/trades matching “sari-sari”/)).toBeVisible()
    await page.getByRole('radiogroup', { name: /line of business/i }).getByRole('radio').first().click()
    await page.getByLabel(/barangay name/i).selectOption({ label: 'Longos' })
    await expect(page.getByRole('heading', { name: /zones in longos/i })).toBeVisible({ timeout: 20_000 })

    await expect(page.getByTestId('zoning-rules-applicant')).toHaveCount(0)
    await expect(page.getByText('What the zoning rules say about this filing')).toHaveCount(0)
    await shot(page, 'applicant-location-zoning-step')
  })

  /*
   * The trade is read against the ordinance's own words, never one shared
   * word. A veterinary clinic is not named anywhere, but sits beside
   * "medical, dental and similar clinics", which CPDO — not the screen —
   * decides it is like. (That one shared word never lists a trade — a gasoline
   * station beside "Water refilling Station" — is ZoningConformanceTest's.)
   */
  test('says a look-alike trade may be on the list, and never shows it as allowed', async ({ page }) => {
    await page.getByRole('checkbox').first().check()
    await page.getByRole('button', { name: /next/i }).click()
    await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

    const search = page.getByLabel(/search for the one line of business/i)
    const pick = async (words: string) => {
      await search.click()
      await search.fill(words)
      await expect(page.getByText(new RegExp(`trades matching “${words}”`))).toBeVisible()
      await page.getByRole('radiogroup', { name: /line of business/i }).getByRole('radio').first().click()
      // Products / Services opens the map; the note under it follows the pin.
      await page.getByRole('textbox', { name: /products \/ services/i }).first().fill('consultations')
    }
    // A veterinary clinic in Longos: no list names it; "medical, dental and
    // similar clinics" may take it in. The map opens on City Hall, in Longos.
    await pick('veterinary')
    await page.getByLabel(/barangay name/i).selectOption({ label: 'Longos' })
    const map = page.locator('.leaflet-container')
    await map.scrollIntoViewIfNeeded()
    await map.click()
    await expect(page.getByText(/pin placed/i)).toBeVisible()

    const note = page.getByTestId('zoning-note')
    await expect(note).toHaveAttribute('data-verdict', 'possible', { timeout: 20_000 })
    await expect(note).toContainText('May be on the zoning list')
    await expect(note).toContainText(/similar clinic/i)
    await expect(note).not.toContainText('Allowed here')
    await note.scrollIntoViewIfNeeded()
    await shot(page, 'applicant-possible-match')
    await shot(page, 'applicant-possible-match-note', note)
  })
})

/**
 * A filing that has reached CPDO: drafted, submitted, read and approved by
 * BPLO, paid, the zoning clearance applied for, its checklist of requirements
 * answered and its sheet handed in. Driven through the API from a
 * page holding the owner's and BPLO's sessions, the way clearances.spec.ts
 * builds its fixtures, and every call asserted — a fixture that fails quietly
 * reports itself as a broken screen.
 */
async function filingAtCpdo(page: Page): Promise<number> {
  return page.evaluate(async () => {
    const owner = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}`,
    }
    const bplo = { ...owner, Authorization: `Bearer ${localStorage.getItem('biztrack.token.staff')}` }
    const call = async (url: string, headers: Record<string, string>, init: RequestInit = {}) => {
      const res = await fetch(url, { headers, ...init })
      if (!res.ok) throw new Error(`${init.method ?? 'GET'} ${url} answered ${res.status}: ${await res.text()}`)
      return (await res.json()).data
    }

    const barangays = await call('/api/v1/reference/barangays', owner)
    const psic = (await call('/api/v1/reference/psic-codes', owner)) as { id: number; code: string }[]
    const sariSari = psic.find((c) => c.code === '47111')!
    const types = (await call('/api/v1/reference/permit-types', owner)) as { id: number; code: string }[]
    const business = await call('/api/v1/businesses', owner, {
      method: 'POST',
      body: JSON.stringify({
        name: `E2E Zoning Rules ${Date.now()}`,
        registration_type: 'DTI',
        registration_number: 'DTI-E2E-ZR1',
        tin: '123-456-789-000',
        address: {
          line1: '3 Pampano St.',
          street: 'Pampano St.',
          barangay_id: barangays.find((b: { name: string }) => b.name === 'Longos').id,
          latitude: 14.6572,
          longitude: 120.9573,
        },
        emergency_contact_name: 'Ana Dela Cruz',
        emergency_contact_number: '0917 123 4567',
        economic_organization: 'single_establishment',
        capital_investment: 80000,
        lines: [{ psic_code_id: sariSari.id, capitalization: 80000, products_services: 'rice, canned goods' }],
      }),
    })
    const app = await call('/api/v1/applications', owner, {
      method: 'POST',
      body: JSON.stringify({
        business_id: business.id,
        application_type: 'new',
        permit_type_ids: [types.find((t) => t.code === 'BUSINESS')!.id],
        data_privacy_consent: true,
        fee_profile: {
          business_structure: 'sole_proprietorship',
          floor_area_sqm: 15,
          employees: 2,
          employees_in_lgu: 2,
          male_employees: 1,
          female_employees: 1,
          lines: [{ psic_code_id: sariSari.id, category: 'retailer', capitalization: 80000 }],
        },
      }),
    })
    await call(`/api/v1/applications/${app.id}/submit`, owner, { method: 'POST' })

    const queue = await call('/api/v1/assignments?application_status=for_approval&status=pending&per_page=100', bplo)
    const bploRow = (queue as { id: number; application: { id: number } | null }[]).find(
      (r) => r.application?.id === app.id,
    )
    if (!bploRow) throw new Error('the filing is not on BPLO’s queue')
    await call(`/api/v1/assignments/${bploRow.id}/classification`, bplo, {
      method: 'POST',
      body: JSON.stringify({ tier: 'simple' }),
    })
    await call(`/api/v1/assignments/${bploRow.id}/approve`, bplo, { method: 'POST', body: '{}' })
    await call(`/api/v1/applications/${app.id}/pay`, owner, {
      method: 'POST',
      body: JSON.stringify({ method: 'gcash' }),
    })
    await call(`/api/v1/applications/${app.id}/clearances/ZONING/apply`, owner, { method: 'POST' })

    // CPDD's checklist: a real PDF in every slot that blocks the submit.
    const forms = (await call(`/api/v1/applications/${app.id}/office-forms`, owner)) as {
      permit_type_code: string
      requirements?: { code: string | null; blocking: boolean; satisfied: boolean }[] | null
    }[]
    const zoningForm = forms.find((f) => f.permit_type_code === 'ZONING')
    const pdf = '%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n'
    for (const row of zoningForm?.requirements ?? []) {
      if (!row.blocking || row.satisfied || row.code === null) continue
      const body = new FormData()
      body.append('file', new File([pdf], `${row.code}.pdf`, { type: 'application/pdf' }))
      const res = await fetch(`/api/v1/applications/${app.id}/office-forms/ZONING/requirements/${row.code}`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: owner.Authorization },
        body,
      })
      if (!res.ok) throw new Error(`uploading ${row.code} answered ${res.status}: ${await res.text()}`)
    }
    await call(`/api/v1/applications/${app.id}/office-forms/ZONING`, owner, {
      method: 'PUT',
      body: JSON.stringify({
        form_data: { zoning_home_address: '3 Pampano St., Longos, Malabon City, Metro Manila' },
        submit: true,
      }),
    })

    return app.id as number
  })
}

test.describe('the zoning officer', () => {
  test.use({ storageState: sessionFor('zoning') })

  test('reads no rule checklist on the review sheet', async ({ page, browser }) => {
    // The filing, built from a second browser holding the owner's and BPLO's sessions.
    const builder = await browser.newContext({ storageState: mergedStorageState(['owner.json', 'bplo.json']) })
    const builderPage = await builder.newPage()
    await builderPage.goto('/')
    const appId = await filingAtCpdo(builderPage)
    await builder.close()

    await page.goto('/staff/queue')
    await expect(page.getByRole('heading').first()).toBeVisible({ timeout: 30_000 })
    const assignmentId = await page.evaluate(async (id) => {
      const token = localStorage.getItem('biztrack.token.staff')
      const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
      const res = await fetch(
        '/api/v1/assignments?application_status=awaiting_other_permits&status=pending,in_progress&per_page=100',
        { headers },
      )
      const rows = (await res.json()).data as { id: number; application: { id: number } | null }[]
      return rows.find((r) => r.application?.id === id)?.id ?? null
    }, appId)
    expect(assignmentId, 'the filing reached CPDO’s queue').not.toBeNull()

    await page.goto(`/staff/queue/${assignmentId}`)
    // CPDD's own sheet is still there, with its checklist of requirements.
    await expect(page.getByText(/show the application as filed/i).first()).toBeVisible({ timeout: 30_000 })
    await expect(page.getByTestId('zoning-rules-officer')).toHaveCount(0)
    await expect(page.getByText('City Ordinance No. 24-2018')).toHaveCount(0)
    await shot(page, 'officer-review-sheet')
  })
})
