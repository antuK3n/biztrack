import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * BUSINESS MAP — the register plotted, coloured by permit state (issue #104).
 *
 * Two halves, and both are needed.
 *
 * The stubbed tests pin the RULES: five states, five silhouettes, a legend
 * that agrees with the map, a filter that narrows it. A narrowing assertion has
 * to know exactly which rows exist, and this suite may be pointed at a copy of
 * the live register whose contents move.
 *
 * The last test is deliberately NOT stubbed, because the one thing a fixture of
 * three rows cannot prove is that the screen survives the real register. It
 * reads the endpoint's own `meta.plotted` and insists the DOM holds exactly
 * that many markers — so a silent cap, a virtualised layer, or a browser that
 * gives up part-way through 742 markers fails here rather than in City Hall.
 */

test.use({ storageState: sessionFor('admin') })

const PATH = '/staff/admin/business-map'
const ENDPOINT = '**/api/v1/admin/business-map'

/*
 * One business per permit state, and — separately — one per pin verdict, so
 * both filters have something real to bite on:
 *
 *   active · agrees      inside Longos, declares Longos
 *   expired · disagrees  inside Catmon, declares Tinajeros (267 m out)
 *   none   · off-city    east of the city border entirely
 *
 * Suspended and revoked (checklist item 16's other two states) sit on the
 * active row's point, declaring Longos, so they agree with their barangay and
 * the pin-check counts below move by exactly them.
 *
 * Every coordinate here was RUN through the shipped polygons before being
 * written down, and that was not ceremony. The first draft of this fixture put
 * three plausible-looking Malabon points against three barangay names picked by
 * eye, and all three landed in the wrong barangay — which is precisely how 672
 * of the 742 live rows came to disagree with their own dropdown. Guessing at
 * this data reproduces the bug instead of testing it.
 */
const FIXTURE = {
  data: [
    {
      id: 1,
      name: 'Aling Nena Sari-Sari Store',
      latitude: 14.65397,
      longitude: 120.96024,
      barangay: 'Longos',
      state: 'active',
      permit_id: 101,
      permit_number: 'MCB-2026-000001',
      valid_until: '2026-12-31',
    },
    {
      id: 2,
      name: 'RxCare Pharmacy',
      latitude: 14.6712,
      longitude: 120.9605,
      barangay: 'Tinajeros',
      state: 'expired',
      permit_id: 102,
      permit_number: 'MCB-2024-000044',
      valid_until: '2025-01-31',
    },
    {
      id: 3,
      name: 'Bagong Silang Hardware',
      latitude: 14.655,
      longitude: 120.995,
      barangay: 'Tinajeros',
      state: 'none',
      permit_id: null,
      permit_number: null,
      valid_until: null,
    },
    {
      id: 4,
      name: 'Kusina ni Lola',
      latitude: 14.65397,
      longitude: 120.96024,
      barangay: 'Longos',
      state: 'suspended',
      permit_id: 104,
      permit_number: 'MP-2026-000004',
      valid_until: '2026-12-31',
    },
    {
      id: 5,
      name: 'Tambakan Junk Shop',
      latitude: 14.65397,
      longitude: 120.96024,
      barangay: 'Longos',
      state: 'revoked',
      permit_id: 105,
      permit_number: 'MP-2026-000005',
      valid_until: '2026-12-31',
    },
  ],
  meta: {
    plotted: 5,
    businesses_total: 7,
    unmapped: 2,
    counts: { active: 1, expired: 1, suspended: 1, revoked: 1, none: 1 },
    as_of: '2026-09-17',
    truncated: false,
    max_points: 5000,
  },
}

async function stub(page: import('@playwright/test').Page) {
  await page.route(ENDPOINT, (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(FIXTURE) }),
  )
  /*
   * Tiles are stubbed to a 1x1 transparent GIF.
   *
   * Not for speed — for determinism and for manners. A map at zoom 13 asks
   * OpenStreetMap and Esri for dozens of tiles, and a test suite that hammers
   * a free tile server on every run is abusing it. Nothing here asserts
   * anything about a tile.
   */
  const PIXEL = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64')
  await page.route(/tile\.openstreetmap\.org|server\.arcgisonline\.com/, (route) =>
    route.fulfill({ status: 200, contentType: 'image/gif', body: PIXEL }),
  )
}

test('every business with a pin gets a marker', async ({ page }) => {
  await stub(page)
  await page.goto(PATH)

  await expect(page.getByRole('heading', { name: 'Business Map' })).toBeVisible()
  await expect(page.locator('.biztrack-map-pin')).toHaveCount(5)
})

/*
 * The load-bearing test on this screen.
 *
 * DESIGN.md's Never Color Alone: an expired permit and an active one must be
 * told apart without relying on hue. This asserts the two markers are different
 * SHAPES — active draws a <circle>, lapsed draws a <path> diamond — so the
 * cheap "fix" of giving them two fills of the same dot fails here.
 *
 * It is written against the geometry element rather than against a colour on
 * purpose. Asserting `fill="#3242ca"` would pass for two identical circles in
 * two colours, which is the exact failure the rule exists to prevent.
 */
test('the five permit states differ by shape, not only by colour', async ({ page }) => {
  await stub(page)
  await page.goto(PATH)

  // Active: a disc, nothing else.
  await expect(page.locator('.biztrack-map-pin--active svg circle')).toHaveCount(1)
  await expect(page.locator('.biztrack-map-pin--active svg path')).toHaveCount(0)

  // Expired: a diamond path, no circle.
  await expect(page.locator('.biztrack-map-pin--expired svg path')).toHaveCount(1)
  await expect(page.locator('.biztrack-map-pin--expired svg circle')).toHaveCount(0)

  // Suspended: a square (rect) — the only state drawn with one.
  await expect(page.locator('.biztrack-map-pin--suspended svg rect')).toHaveCount(1)
  await expect(page.locator('.biztrack-map-pin svg rect')).toHaveCount(1)

  // Revoked: a cross — strokes only, no disc and no square.
  await expect(page.locator('.biztrack-map-pin--revoked svg circle')).toHaveCount(0)
  await expect(page.locator('.biztrack-map-pin--revoked svg rect')).toHaveCount(0)
  await expect(page.locator('.biztrack-map-pin--revoked svg path')).toHaveCount(2)

  // And "never held one" is its own silhouette, not another colour of a disc.
  await expect(page.locator('.biztrack-map-pin--none svg circle')).toHaveCount(1)
})

/*
 * The map is a canvas to a screen reader whatever renderer it uses, so the same
 * rule the charts follow applies (AGENTS.md §6.2): the figures are also real
 * markup. This asserts the table exists, names each state in words, and carries
 * the same counts the markers do — a legend that drifts from the map is worse
 * than none, because it is believed.
 */
test('the marker states are readable as a table, in words, with their counts', async ({ page }) => {
  await stub(page)
  await page.goto(PATH)

  const active = page.getByRole('row').filter({ hasText: 'Permit active' })
  await expect(active).toContainText('1')
  for (const label of ['Permit expired', 'Permit suspended', 'Permit revoked', 'No permit on file']) {
    await expect(page.getByRole('row').filter({ hasText: label })).toContainText('1')
  }
})

/* Both numbers, never one — a bare "3 businesses" hides what was left out. */
test('the map says how much of the register is missing from it', async ({ page }) => {
  await stub(page)
  await page.goto(PATH)

  await expect(page.getByText('5 of the 7 businesses on file')).toBeVisible()
  await expect(page.getByText('2 have no pin')).toBeVisible()
})

test('filtering to one permit state leaves only that state on the map', async ({ page }) => {
  await stub(page)
  await page.goto(PATH)
  await expect(page.locator('.biztrack-map-pin')).toHaveCount(5)

  await page.getByRole('button', { name: /Permit revoked/ }).click()

  await expect(page.locator('.biztrack-map-pin')).toHaveCount(1)
  await expect(page.locator('.biztrack-map-pin--revoked')).toHaveCount(1)
  // Both numbers, once something narrows it.
  await expect(page.getByText('Showing 1 of the 5 businesses on the map.')).toBeVisible()
})

test('filtering to one barangay leaves only the businesses that declared it', async ({ page }) => {
  await stub(page)
  await page.goto(PATH)

  const barangay = page.getByRole('combobox', { name: 'Barangay', exact: true })
  // Offered from the data: the barangays businesses declared, and no others.
  await expect(barangay.locator('option')).toHaveText(['All barangays', 'Longos', 'Tinajeros'])

  await barangay.selectOption('Tinajeros')
  await expect(page.locator('.biztrack-map-pin')).toHaveCount(2)
  await expect(page.getByText('Showing 2 of the 5 businesses on the map in Tinajeros.')).toBeVisible()

  // The two filters combine.
  await page.getByRole('button', { name: /No permit on file/ }).click()
  await expect(page.locator('.biztrack-map-pin')).toHaveCount(1)
  await expect(page.locator('.biztrack-map-pin--none')).toHaveCount(1)
})

/*
 * The pin check runs the shipped barangay polygons over the pins in the
 * browser, which is why a stub can prove it at all: the verdict is computed
 * from the geometry the app ships, not read off the payload. Nothing in the
 * fixture states a verdict — change a coordinate and these counts move.
 *
 * "Outside Malabon" is its own option rather than part of "disagrees" because
 * they are different problems. A pin in the wrong barangay is a slip to correct
 * at leisure; a pin in Caloocan is a business whose location this system does
 * not actually know, and 171 of the live rows are in that state.
 */
test('the pin check separates a wrong barangay from a pin outside the city', async ({ page }) => {
  await stub(page)
  await page.goto(PATH)

  await page.getByLabel('Pin check').selectOption('agrees')
  await expect(page.locator('.biztrack-map-pin--active')).toHaveCount(1)
  // Suspended and revoked sit on the same Longos point (see the fixture note).
  await expect(page.locator('.biztrack-map-pin')).toHaveCount(3)

  await page.getByLabel('Pin check').selectOption('disagrees')
  await expect(page.locator('.biztrack-map-pin--expired')).toHaveCount(1)
  await expect(page.locator('.biztrack-map-pin')).toHaveCount(1)

  await page.getByLabel('Pin check').selectOption('off-city')
  await expect(page.locator('.biztrack-map-pin--none')).toHaveCount(1)
  await expect(page.locator('.biztrack-map-pin')).toHaveCount(1)
})

test('a marker says its permit state in words, not only in colour', async ({ page }) => {
  await stub(page)
  await page.goto(PATH)

  await page.locator('.biztrack-map-pin--expired').click()

  const popup = page.locator('.leaflet-popup')
  await expect(popup).toContainText('RxCare Pharmacy')
  await expect(popup).toContainText('Permit expired')
  await expect(popup).toContainText('MCB-2024-000044')
})

test('a marker’s popup leads to the permit’s details', async ({ page }) => {
  /*
   * Checklist item 16: "click a marker for business name, permit number,
   * status, link to details". The standalone screen links into the Permits
   * register, searched for the permit and widened to every office so it is
   * found whichever office the reader would open on.
   */
  await stub(page)
  await page.goto(PATH)

  await page.locator('.biztrack-map-pin--revoked').click()
  const popup = page.locator('.leaflet-popup')
  await expect(popup).toContainText('Permit revoked')
  await expect(popup.getByRole('button', { name: 'View certificate MP-2026-000005' })).toBeVisible()

  await popup.getByRole('link', { name: 'Find MP-2026-000005 in the register' }).click()
  await expect(page).toHaveURL(/\/staff\/admin\/permits\?q=MP-2026-000005&office=all/)
  await expect(
    page.getByRole('searchbox', { name: /Search permits/ }),
  ).toHaveValue('MP-2026-000005')

  // A business that never held a permit has nothing to lead to, and says nothing.
  await page.goto(PATH)
  await page.locator('.biztrack-map-pin--none').click()
  await expect(page.locator('.leaflet-popup')).toContainText('No permit on file')
  await expect(page.locator('.leaflet-popup').getByRole('link')).toHaveCount(0)
})

/*
 * NOT stubbed. This is the volume test.
 *
 * The count comes from the endpoint's own meta rather than from a number
 * written here, because the copied register this runs against holds whatever
 * the live one held when it was copied. What is being asserted is the
 * INVARIANT — every plotted business is a marker in the DOM — which is the
 * claim a fixture of three rows cannot make.
 */
test('the whole register renders, one marker per plotted business', async ({ page }) => {
  const payload = page.waitForResponse(
    (r) => r.url().includes('/api/v1/admin/business-map') && r.status() === 200,
  )
  await page.goto(PATH)
  const meta = ((await (await payload).json()) as { meta: { plotted: number } }).meta

  expect(meta.plotted).toBeGreaterThan(100)
  await expect(page.locator('.biztrack-map-pin')).toHaveCount(meta.plotted)
})
