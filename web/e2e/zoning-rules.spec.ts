import { test, expect, type Page } from '@playwright/test'
import { mergedStorageState, sessionFor } from './helpers'

/*
 * City Ordinance No. 24-2018, rule by rule, on the two screens that read it.
 *
 * The applicant meets the rules on Location & Zoning as an early warning: each
 * says met, not met or that CPDO checks it, with its article and page, and asks
 * its own question where it needs one. The zoning officer meets the same list
 * on the review sheet as the cited checklist CPDO decides against, and records
 * what only CPDO can — the zone the lot is in.
 *
 * The API suite (ZoningOrdinanceRulesTest) proves each rule's logic. What only a
 * browser can prove is that the findings reach a reader, that a question asked
 * under a rule changes that rule's answer, and that "not met" is said in words.
 *
 * Screenshots go to E2E_SHOTS_DIR when it is set, for review by eye.
 */

const SHOTS = process.env.E2E_SHOTS_DIR

async function shot(page: Page, name: string, target?: ReturnType<Page['locator']>) {
  if (!SHOTS) return
  if (target) await target.screenshot({ path: `${SHOTS}/${name}.png` })
  else await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true })
}

/** A finding row, by the rule it is about. */
function finding(scope: ReturnType<Page['locator']>, rule: string) {
  return scope.locator(`li[data-rule="${rule}"]`).first()
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

  test('meets the zoning rules on Location & Zoning, answers their questions, and sees each rule change', async ({
    page,
  }) => {
    await page.getByRole('checkbox').first().check()
    await page.getByRole('button', { name: /next/i }).click()
    await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

    // A sari-sari store, the commonest trade there is.
    const search = page.getByLabel(/search for the one line of business/i)
    await search.click()
    await search.fill('sari-sari')
    await expect(page.getByText(/trades matching “sari-sari”/)).toBeVisible()
    await page.getByRole('radiogroup', { name: /line of business/i }).getByRole('radio').first().click()

    // Longos: Maximum R-2, Socialized Housing, C-1, C-2, the CBD and Institutional.
    await page.getByLabel(/barangay name/i).selectOption({ label: 'Longos' })

    const rules = page.getByTestId('zoning-rules-applicant')
    await expect(rules).toBeVisible({ timeout: 20_000 })
    await expect(rules).toContainText('What the zoning rules say about this filing')
    // Never a refusal, and said so first.
    await expect(rules).toContainText(/nothing here refuses your filing/i)

    // Met: a new business applies for its locational clearance with this filing.
    const clearance = finding(rules, 'IX-2')
    await expect(clearance).toHaveAttribute('data-status', 'met')
    await expect(clearance).toContainText('Met')
    await expect(clearance).toContainText(/Art\. IX §2 · p\. 63/)

    // CPDO checks: Longos has a Socialized Housing zone the ordinance leaves to BP 220.
    const housing = finding(rules, 'V-2.6-BP220')
    await expect(housing).toHaveAttribute('data-status', 'review')
    await expect(housing).toContainText(/applies if your lot is in the socialized housing zone/i)

    // Waiting on an answer: asked right under the rule that needs it.
    const parking = finding(rules, 'VI-5-3')
    await expect(parking).toContainText('Answer below')
    const parkingQuestion = parking.getByRole('group', { name: /park on the street/i })
    await parkingQuestion.getByRole('radio', { name: 'Yes' }).check()

    // Not met — in words and with its own icon, never in error red.
    await expect(parking).toHaveAttribute('data-status', 'not_met', { timeout: 15_000 })
    await expect(parking).toContainText('Not met')
    await expect(parking).toContainText(/Art\. VI §5 · p\. 56/)
    const tagColour = await parking.locator('span.rounded-full').first().evaluate((el) => getComputedStyle(el).color)
    expect(tagColour).not.toBe('rgb(189, 0, 0)')

    // A second rule, with a number: a building 2 m from a creek is inside the 3 m easement.
    const easement = finding(rules, 'V-2.14-3M')
    await easement.getByRole('group', { name: /beside a river, creek/i }).getByRole('radio', { name: 'Yes' }).check()
    await expect(easement.getByLabel(/which waterway/i)).toBeVisible({ timeout: 15_000 })
    await easement.getByLabel(/which waterway/i).selectOption({ label: 'Tullahan River' })
    await easement.getByLabel(/distance from the building to the edge of the waterway/i).fill('2')
    await expect(easement).toHaveAttribute('data-status', 'not_met', { timeout: 15_000 })
    await expect(easement).toContainText(/3 m easement/)

    // The count follows the answers.
    await expect(page.getByTestId('zoning-rules-summary')).toContainText(/[1-9]\d* not met/)

    await shot(page, 'applicant-location-zoning-checklist', rules)
    await rules.scrollIntoViewIfNeeded()
    await shot(page, 'applicant-location-zoning-step')
  })

  /*
   * The trade is read against the ordinance's own words, never one shared
   * word. A gasoline station used to read as allowed in Maximum R-2 because
   * "station" is also in "Water refilling Station"; a veterinary clinic is not
   * named anywhere, but sits beside "medical, dental and similar clinics", which
   * CPDO — not the screen — decides it is like.
   */
  test('says a look-alike trade may be on the list, and never shows a one-word match as allowed', async ({ page }) => {
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
    const rules = page.getByTestId('zoning-rules-applicant')

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

    const use = finding(rules, 'V-2')
    await expect(use).toHaveAttribute('data-status', 'review', { timeout: 20_000 })
    await expect(use).toContainText('Possibly on the zones’ lists')
    await expect(use).toContainText('CPDO checks')
    await note.scrollIntoViewIfNeeded()
    await shot(page, 'applicant-possible-match')
    await shot(page, 'applicant-possible-match-note', note)

    // A gasoline station in Muzon (Maximum R-2 and Institutional): it shares
    // only "station" with "Water refilling Station", and is not on the list.
    await page.getByRole('button', { name: 'Change line of business' }).click()
    await pick('gasoline')
    await page.getByLabel(/barangay name/i).selectOption({ label: 'Muzon' })
    const gasoline = finding(rules, 'V-2')
    await expect(gasoline).toContainText(/not on the list for any zone in muzon/i, { timeout: 20_000 })
    await expect(gasoline).not.toContainText(/water refilling/i)
    await expect(gasoline).not.toHaveAttribute('data-status', 'met')
  })
})

/**
 * A filing that has reached CPDO: drafted with two zoning answers, submitted,
 * read and approved by BPLO, paid, the zoning clearance applied for, its
 * checklist answered and its sheet handed in. Driven through the API from a
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
        zoning_facts: { home_based: true, persons_engaged: 3, parking_on_street: true },
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

  test('reads the cited checklist on the review sheet and records the lot’s zone', async ({
    page,
    browser,
  }) => {
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
    const rules = page.getByTestId('zoning-rules-officer')
    await expect(rules).toBeVisible({ timeout: 30_000 })
    await expect(rules).toContainText('City Ordinance No. 24-2018')

    // Every finding carries its article and printed page.
    await expect(finding(rules, 'IV-5')).toContainText(/Art\. IV §5 · p\. 10/)
    await expect(finding(rules, 'IX-14-1a')).toContainText(/Local Zoning Board of Appeals/)
    // The applicant's own answers reach the officer: parking on the street is not met.
    await expect(finding(rules, 'VI-5-3')).toHaveAttribute('data-status', 'not_met')
    // Pampano St. is a C-1 strip in Longos (Art. IV §5).
    await expect(finding(rules, 'IV-5-STRIP')).toContainText(/Pampano St\. carries a Commercial-1 strip/)

    // Before CPDO places the lot, the home-occupation limit is a question of zone.
    const parking = finding(rules, 'V-2.1-HO-5')
    await expect(parking).toHaveAttribute('data-status', 'review')
    await expect(parking).toContainText(/applies if your lot is in/i)

    // CPDO's own answer: the zone of this lot.
    const zone = finding(rules, 'IV-6-a')
    await expect(zone).toHaveAttribute('data-status', 'review')
    await zone.getByLabel(/zone of this lot/i).selectOption({ label: 'Maximum Residential-2 (R-2-MAX)' })
    await shot(page, 'officer-review-checklist-before-save', rules)

    await rules.getByRole('button', { name: /save cpdo’s answers/i }).click()
    await expect(zone).toContainText(/CPDO placed this lot in Maximum Residential-2/, { timeout: 15_000 })
    // Placed in the residential zone, the street-parking condition binds outright.
    await expect(finding(rules, 'V-2.1-HO-5')).toHaveAttribute('data-status', 'not_met')

    await shot(page, 'officer-review-checklist', rules)
  })
})
