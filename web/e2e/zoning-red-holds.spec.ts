import { test, expect, type Page } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * A red box beside the map holds the owner on Location & Zoning, by every road
 * out of it — not only Next.
 *
 * Ken, testing production: "Non-conformance in the zoning should not allow the
 * user to go to the next pages (even when the pin was changed in the middle of
 * the application)." Next was held, but the section map along the top still
 * jumped to any section already opened, a reopened draft landed past the step
 * on the first unfinished section after it, and a failed `zone-at-pin` lookup
 * opened Next under a box that was already red. Back stays open: the pin and
 * the line are fixed on this step.
 *
 * A pharmacy at the middle of Acacia is in its Industry zone, whose list has no
 * drugstore; in Baritan the list names one, and in Acacia a warehouse is
 * listed (zoning-rules and line-of-business-change hold those answers).
 *
 * Screenshots go to E2E_SHOTS_DIR when it is set, for review by eye.
 */

const SHOTS = process.env.E2E_SHOTS_DIR
const REFUSAL =
  "Retail sale of pharmaceutical goods (pharmacy) isn't allowed in the Industry zone where your pin is. To petition this, visit the Business Permits and Licensing Office (BPLO) at Malabon City Hall."

test.use({ storageState: sessionFor('owner') })

test.beforeEach(async ({ page }) => {
  await page.route('**://nominatim.openstreetmap.org/**', (route) => route.abort())
})

async function toLocation(page: Page) {
  await page.goto('/apply')
  await expect(page.getByText(/data privacy/i).first()).toBeVisible({ timeout: 30_000 })
  await page.getByRole('checkbox').first().check()
  await page.getByRole('button', { name: /next/i }).click()
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })
}

async function pickLine(page: Page, words: string) {
  const search = page.getByLabel(/search for the one line of business/i)
  await search.click()
  await search.fill(words)
  await expect(page.getByText(new RegExp(`trades matching “${words}”`))).toBeVisible()
  await page.getByRole('radiogroup', { name: /line of business/i }).getByRole('radio').first().click()
}

/** Everything Location & Zoning asks, with the pin at the middle of `barangay`. */
async function fillLocation(page: Page, words: string, barangay: string) {
  await pickLine(page, words)
  await page.getByRole('textbox', { name: /products \/ services/i }).first().fill('goods')
  await page.getByLabel(/^street/i).fill('Rizal Street')
  await page.getByLabel(/emergency contact person/i).fill('Juan Dela Cruz')
  await page.getByLabel(/emergency contact number/i).fill('0917 123 4567')
  await pinAt(page, barangay)
}

async function pinAt(page: Page, barangay: string) {
  await page.getByLabel(/barangay name/i).selectOption({ label: barangay })
  const map = page.locator('.leaflet-container')
  await map.scrollIntoViewIfNeeded()
  await map.click()
  await expect(page.getByTestId('pin-status')).toBeVisible()
}

const sections = (page: Page) => page.getByRole('list', { name: 'Application sections' })
const businessSection = (page: Page) => sections(page).getByRole('button', { name: /business information/i })
const next = (page: Page) => page.getByRole('button', { name: /^next$/i })
const note = (page: Page) => page.getByTestId('zoning-note')

/** Held on part 2: Next, and every later section on the map, refuse to move. */
async function expectHeld(page: Page) {
  await expect(note(page)).toHaveAttribute('data-verdict', 'refused', { timeout: 20_000 })
  await expect(note(page)).toHaveText(REFUSAL)
  await expect(next(page)).toBeDisabled()
  await expect(businessSection(page)).toBeDisabled()
  // Forced, so a control that only LOOKS shut is caught too.
  await businessSection(page).click({ force: true })
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible()
  await expect(page.getByText(/part 3 of/i)).toHaveCount(0)
}

test('moving the pin into a zone that refuses the line, after leaving the step, holds every way forward', async ({
  page,
}) => {
  await toLocation(page)
  await fillLocation(page, 'pharmacy', 'Baritan')
  await expect(note(page)).toHaveAttribute('data-verdict', 'listed', { timeout: 20_000 })
  await expect(next(page)).toBeEnabled({ timeout: 20_000 })
  await next(page).click()
  await expect(page.getByText(/part 3 of/i).first()).toBeVisible({ timeout: 20_000 })

  await page.getByRole('button', { name: 'Back', exact: true }).click()
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible()
  await pinAt(page, 'Acacia')
  await expectHeld(page)
  // Back still works.
  await page.getByRole('button', { name: 'Back', exact: true }).click()
  await expect(page.getByText(/part 1 of/i).first()).toBeVisible()
  // …and from part 1 the map does not jump over the red step either.
  await expect(businessSection(page)).toBeDisabled()
})

test('changing the line of business to one the pin refuses, after leaving the step, holds every way forward', async ({
  page,
}) => {
  await toLocation(page)
  await fillLocation(page, 'warehousing', 'Acacia')
  await expect(note(page)).toHaveAttribute('data-verdict', 'listed', { timeout: 20_000 })
  await expect(next(page)).toBeEnabled({ timeout: 20_000 })
  await next(page).click()
  await expect(page.getByText(/part 3 of/i).first()).toBeVisible({ timeout: 20_000 })

  await page.getByRole('button', { name: 'Back', exact: true }).click()
  await page.getByRole('button', { name: 'Change line of business' }).click()
  await pickLine(page, 'pharmacy')
  await expectHeld(page)
  await note(page).scrollIntoViewIfNeeded()
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/zg-after.png`, fullPage: true })
})

test('a red box holds Next even when the zone-at-pin lookup fails', async ({ page }) => {
  await page.route('**/api/v1/zone-at-pin**', (route) => route.abort())
  await toLocation(page)
  await fillLocation(page, 'pharmacy', 'Acacia')
  await expect(note(page)).toHaveAttribute('data-verdict', 'refused', { timeout: 20_000 })
  await expect(next(page)).toBeDisabled()
})

/** The pin the map gives the middle of Acacia, read off the step. */
async function acaciaPin(page: Page): Promise<{ latitude: number; longitude: number }> {
  await toLocation(page)
  await fillLocation(page, 'pharmacy', 'Acacia')
  const pin = page.getByTestId('pin-status')
  return {
    latitude: Number(await pin.getAttribute('data-latitude')),
    longitude: Number(await pin.getAttribute('data-longitude')),
  }
}

/**
 * A NEW business-permit draft for `trade` at `pin` in Acacia, built through the
 * API with every answer and upload Review asks for (submit-waits-for-autosave's
 * fixture).
 */
async function draftAt(page: Page, trade: string, pin: { latitude: number; longitude: number }) {
  return page.evaluate(
    async ({ trade, pin }) => {
      const owner = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}`,
      }
      const call = async (url: string, init: RequestInit = {}) => {
        const res = await fetch(url, { headers: owner, ...init })
        if (!res.ok) throw new Error(`${init.method ?? 'GET'} ${url} answered ${res.status}: ${await res.text()}`)
        return (await res.json()).data
      }
      const barangays = (await call('/api/v1/reference/barangays')) as { id: number; name: string }[]
      const psic = (await call('/api/v1/reference/psic-codes')) as { id: number; title: string }[]
      const line = psic.find((c) => new RegExp(trade, 'i').test(c.title))!
      const types = (await call('/api/v1/reference/permit-types')) as {
        id: number
        code: string
        document_types: { id: number; code: string }[]
      }[]
      const businessType = types.find((t) => t.code === 'BUSINESS')!
      const name = `E2E Red Pin ${Date.now()}`
      const business = await call('/api/v1/businesses', {
        method: 'POST',
        body: JSON.stringify({
          name,
          registration_type: 'sole_proprietorship',
          registration_number: `DTI-${Math.floor(100000 + Math.random() * 899999)}`,
          tin: '123-456-789-000',
          address: {
            house_bldg_no: '3',
            street: 'Rizal St.',
            barangay_id: barangays.find((b) => b.name === 'Acacia')!.id,
            latitude: pin.latitude,
            longitude: pin.longitude,
            mobile_number: '+63 917 123 4567',
            email: 'red.pin.e2e@example.com',
          },
          owner: { surname: 'Dela Cruz', given_name: 'Ana', gender: 'F' },
          citizenship: 'Filipino',
          emergency_contact_name: 'Ana Dela Cruz',
          emergency_contact_number: '+63 917 765 4321',
          economic_organization: 'single_establishment',
          capital_investment: 500000,
          is_rented: false,
          has_tax_incentives: false,
          lines: [{ psic_code_id: line.id, capitalization: 500000, products_services: 'goods' }],
        }),
      })
      const app = await call('/api/v1/applications', {
        method: 'POST',
        body: JSON.stringify({
          business_id: business.id,
          application_type: 'new',
          title: name,
          data_privacy_consent: true,
          permit_type_ids: [businessType.id],
          fee_profile: {
            business_structure: 'sole_proprietorship',
            floor_area_sqm: 120,
            employees: 12,
            employees_in_lgu: 6,
            male_employees: 7,
            female_employees: 5,
            lines: [{ psic_code_id: line.id, category: 'retailer', capitalization: 500000 }],
          },
        }),
      })
      const pdf = '%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n'
      for (const dt of businessType.document_types) {
        if (!['DTI_SEC_CDA', 'LOCATION_SKETCH', 'LAND_TITLE'].includes(dt.code)) continue
        const body = new FormData()
        body.append('document_type_id', String(dt.id))
        body.append('file', new File([pdf + dt.code], `${dt.code}.pdf`, { type: 'application/pdf' }))
        const res = await fetch(`/api/v1/applications/${app.id}/documents`, {
          method: 'POST',
          headers: { Accept: 'application/json', Authorization: owner.Authorization },
          body,
        })
        if (!res.ok) throw new Error(`uploading ${dt.code} answered ${res.status}`)
      }
      return app.id as number
    },
    { trade, pin },
  )
}

// A saved draft whose pin is red reopens on Location & Zoning, under the red
// box — not on the first unfinished section after it, nor on Review.
test('a saved draft whose pin is red reopens on Location & Zoning, held', async ({ page }) => {
  const draftId = await draftAt(page, 'pharmacy', await acaciaPin(page))
  await page.goto(`/apply?draft=${draftId}`)
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 30_000 })
  await expectHeld(page)
})

// Changed on Review, where Location & Zoning is edited in place: the red box
// stays on Review once the section is folded back, and Submit is held.
test('a line of business made red on Review keeps the box on Review and holds Submit', async ({ page }) => {
  const draftId = await draftAt(page, 'warehousing', await acaciaPin(page))
  await page.goto(`/apply?draft=${draftId}`)
  const sections = page.getByRole('list', { name: 'Application sections' })
  await expect(sections.getByRole('button', { name: /review & submit/i })).toHaveAttribute('aria-current', 'step', {
    timeout: 30_000,
  })
  const submit = page.getByRole('button', { name: /^submit$/i })
  const ready = page.getByText('Your Business Permit application is ready to submit')
  await expect(submit).toBeEnabled({ timeout: 20_000 })
  await expect(ready).toBeVisible()

  const location = page.locator('section[aria-labelledby="review-address"]')
  await location.getByRole('button', { name: 'Edit this section' }).click()
  await page.getByRole('button', { name: 'Change line of business' }).click()
  await pickLine(page, 'pharmacy')
  // One box on screen at a time: the section's own while it is open for
  // editing, Review's once it is folded back (the section's stays, hidden).
  const shown = page.locator('[data-testid="zoning-note"]:visible')
  await expect(shown).toHaveAttribute('data-verdict', 'refused', { timeout: 20_000 })
  await expect(shown).toHaveCount(1)
  await location.getByRole('button', { name: 'Done editing' }).click()
  await expect(shown).toHaveCount(1)
  await expect(shown).toHaveText(REFUSAL)
  await expect(submit).toBeDisabled()
  // Not "ready to submit" under a red box (Ken, 6 October 2026).
  await expect(ready).toHaveCount(0)
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/zg-after-review.png`, fullPage: true })

  // Back to a line the zone lists: the box goes, the line and Submit return.
  await location.getByRole('button', { name: 'Edit this section' }).click()
  await page.getByRole('button', { name: 'Change line of business' }).click()
  await pickLine(page, 'warehousing')
  await expect(shown).toHaveAttribute('data-verdict', 'listed', { timeout: 20_000 })
  await location.getByRole('button', { name: 'Done editing' }).click()
  await expect(page.locator('[data-testid="zoning-note"][data-verdict="refused"]')).toHaveCount(0)
  await expect(ready).toBeVisible()
  await expect(submit).toBeEnabled({ timeout: 20_000 })
})
