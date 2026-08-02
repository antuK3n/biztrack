import { expect, test } from '@playwright/test'

/*
 * The apply wizard, from a business owner's side.
 *
 * This is the only screen in the product where a citizen hands over personal
 * data, so two of its properties are not preferences:
 *
 *   - consent is asked before anything is collected, not after
 *   - nothing is asked twice that was already answered
 *
 * Both were broken and both were fixed by moving steps around, which is
 * exactly the kind of change that silently reverts in a merge. Ordering is
 * asserted here rather than assumed.
 */

test.use({ storageState: 'e2e/.auth/owner.json' })

test.beforeEach(async ({ page }) => {
  await page.goto('/apply')
  await expect(page.getByText(/data privacy/i).first()).toBeVisible({ timeout: 30_000 })
})

test('consent is the first thing asked, before any data is collected', async ({ page }) => {
  /*
   * RA 10173 consent has to precede collection to mean anything. It used to
   * sit at the foot of the documents step, six screens after the applicant
   * had already typed their name, address and line of business.
   */
  await expect(page.getByText(/part 1 of/i).first()).toBeVisible()
  await expect(page.getByText(/data privacy/i).first()).toBeVisible()

  /*
   * Nothing personal may be asked on this step. "Application title" is the
   * one text field here and it is not an exception to that — it is the
   * applicant naming their own draft so they can find it again, the way a
   * document gets a filename. It says nothing about who they are.
   *
   * So the assertion is not "no inputs" but "no input that would need
   * consent": no name, address, contact detail, or registration number.
   */
  const names = await page
    .getByRole('textbox')
    .evaluateAll((els) => els.map((el) => el.closest('label')?.textContent?.trim() ?? '(unlabelled)'))

  expect(names).toEqual(['Application title'])
  expect(names, 'a field on the consent step has no label').not.toContain('(unlabelled)')

  for (const name of names) {
    expect(name).not.toMatch(/name|address|birth|contact|mobile|email|tin|registration/i)
  }
})

test('the wizard will not advance until consent is given, and says why', async ({ page }) => {
  const next = page.getByRole('button', { name: /next/i })
  await expect(next).toBeDisabled()

  // A disabled button with no explanation is a dead end. The step has to name
  // what it is still waiting for.
  await expect(page.getByText(/still needed/i)).toBeVisible()
  await expect(page.getByText(/data privacy consent/i).last()).toBeVisible()

  await page.getByRole('checkbox').first().check()
  await expect(next).toBeEnabled()
})

test('the LGU permits step comes after the business is described', async ({ page }) => {
  /*
   * The office forms prefill from the business sections, so those have to be
   * answered first — otherwise every clearance sheet opens blank and the
   * applicant retypes their business name four times, once per office.
   *
   * Permits is not literally last: the required documents are the union of
   * the document types on the selected permits, and the tax questions vary by
   * permit code, so both of those steps must follow it.
   */
  const map = await page.locator('ol li, nav li').allTextContents()
  const joined = map.join(' | ').toLowerCase()

  const at = (label: string) => joined.indexOf(label)
  expect(at('privacy'), 'privacy consent missing from the step map').toBeGreaterThanOrEqual(0)

  // Consent first; business description before permits; permits before the
  // steps that are computed from it.
  expect(at('privacy')).toBeLessThan(at('business'))
  expect(at('business')).toBeLessThan(at('permits'))
  expect(at('permits')).toBeLessThan(at('documentary'))

  /*
   * Item 76 asked for the LGU Section last. This is as close as the data
   * allows: only the two sections derived from it, plus its own office
   * sheets, may follow. If a section ever appears between the cards and the
   * profile, the reason above stopped being the reason.
   */
  expect(at('permits')).toBeLessThan(at('tax profile'))
  expect(at('tax profile')).toBeLessThan(at('documentary'))
})

test('line of business is asked once, and the one ask is the searchable picker', async ({
  page,
}) => {
  /*
   * Item 69. It used to be asked twice: a plain dropdown on Location & Zoning
   * so the zoning verdict had a trade to be about, and a whole section of its
   * own three steps later. The section is gone and the picker moved onto the
   * zoning step, so the weaker control is not what survived.
   */
  const map = await page.locator('ol li').allTextContents()
  const asks = map.filter((label) => /line of business/i.test(label))
  expect(asks, `line of business has ${asks.length} sections of its own`).toEqual([])

  await page.getByRole('checkbox').first().check()
  await page.getByRole('button', { name: /next/i }).click()
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

  // Search and the free-text escape are the two things a <select> cannot do,
  // and both are why this is the control that was kept.
  await expect(page.getByLabel(/search your line of business/i)).toBeVisible()
  await expect(page.getByRole('button', { name: /other \(not listed\)/i })).toBeVisible()
})

test('a pin outside Malabon is refused, and says only what was checked', async ({ page }) => {
  /*
   * Item 86. There are no zone polygons and no water layer here, so the only
   * honest check is whether the point is anywhere near the city — and the
   * message must not imply more than that was looked at.
   */
  await page.getByRole('checkbox').first().check()
  await page.getByRole('button', { name: /next/i }).click()
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

  /*
   * By class, not by its accessible name, because it has none: MapPicker sets
   * aria-label on <MapContainer>, and react-leaflet 4.2.1 destructures that
   * prop away before it reaches the div, so the map renders with only class,
   * tabindex and style. Locating it the honest way documents the gap rather
   * than hiding it — the map is outside this change's files, and the missing
   * name is worth fixing there.
   */
  const map = page.locator('.leaflet-container')
  await expect(map).toBeVisible()
  /*
   * The map opens centred on Malabon City Hall at zoom 13 — about 18 m per
   * pixel — so a corner of a 320 px-tall viewport is several kilometres out of
   * the city in both axes. Bottom-left specifically: Leaflet puts its zoom
   * buttons top-left and its attribution bottom-right, and a click that lands
   * on either is not a click on the map.
   */
  // Element-relative, so this scrolls the map into view first: the line of
  // business picker now sits above it and pushes it below the fold.
  await map.scrollIntoViewIfNeeded()
  const box = await map.boundingBox()
  expect(box).not.toBeNull()
  await map.click({ position: { x: 12, y: box!.height - 12 } })

  const refusal = page.getByRole('alert').filter({ hasText: /outside malabon/i })
  await expect(refusal).toBeVisible()
  // Never claim a check that was not made.
  await expect(refusal).not.toContainText(/water|river|sea|zoning verdict/i)
  await expect(page.getByText(/pinned at/i)).toBeHidden()
})

test('placeholders show a real example, never restate the label', async ({ page }) => {
  await page.getByRole('checkbox').first().check()
  await page.getByRole('button', { name: /next/i }).click()

  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

  /*
   * "House No. and Street Name" as a placeholder is the label again in grey,
   * which fails 3.3.2 and tells a first-time applicant nothing about the
   * format expected. Every placeholder here should read as an example.
   */
  const placeholders = await page
    .locator('input[placeholder]')
    .evaluateAll((els) => els.map((el) => el.getAttribute('placeholder') ?? ''))

  expect(placeholders.length).toBeGreaterThan(0)
  for (const p of placeholders) {
    expect(p, `placeholder restates its label: "${p}"`).not.toMatch(
      /^(house no|registered business|trade name|lessor|who to reach|who you pay|enter |barangay name$)/i,
    )
  }
})

test('no field is closed with `disabled`, which screen readers skip', async ({ page }) => {
  await page.getByRole('checkbox').first().check()
  await page.getByRole('button', { name: /next/i }).click()
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

  /*
   * A disabled input leaves the tab order and most screen readers pass over
   * it, so an applicant using one never learns the field exists or why it is
   * closed. `readOnly` looks identical and stays announceable. Submit and
   * navigation buttons are legitimately disabled; data fields are not.
   */
  const disabledFields = await page
    .locator('input:disabled, select:disabled, textarea:disabled')
    .evaluateAll((els) =>
      els.map((el) => ({
        name: el.getAttribute('name') ?? el.getAttribute('aria-label') ?? el.tagName,
        type: el.getAttribute('type'),
      })),
    )

  expect(
    disabledFields,
    `these fields use disabled where readOnly is required: ${JSON.stringify(disabledFields)}`,
  ).toEqual([])
})

test('every input carries a real accessible name', async ({ page }) => {
  await page.getByRole('checkbox').first().check()
  await page.getByRole('button', { name: /next/i }).click()
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

  // A placeholder is not a label: it vanishes on the first keystroke, which
  // is when a returning applicant most needs to know what the field wants.
  const unnamed = await page
    .locator('input:not([type=hidden]), select, textarea')
    .evaluateAll((els) =>
      els
        .filter((el) => {
          const id = el.getAttribute('id')
          const labelled =
            el.getAttribute('aria-label') ||
            el.getAttribute('aria-labelledby') ||
            el.closest('label') ||
            (id && document.querySelector(`label[for="${id}"]`))
          return !labelled
        })
        .map((el) => el.getAttribute('placeholder') ?? el.getAttribute('name') ?? el.tagName),
    )

  expect(unnamed, `inputs with no accessible name: ${JSON.stringify(unnamed)}`).toEqual([])
})
