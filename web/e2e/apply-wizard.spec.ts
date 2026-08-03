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

test('the wizard is the business permit alone — no clearances, no office sheets', async ({
  page,
}) => {
  /*
   * ── What changed here, and why ────────────────────────────────────────
   *
   * This test used to be called "the LGU permits step comes after the business
   * is described", and it asserted that the permits step EXISTS and sits in a
   * particular place: `at('business') < at('permits') < at('documentary')` and
   * `at('permits') < at('tax profile') < at('documentary')`.
   *
   * Every one of those assertions had to go, because the thing they were
   * about is gone. The six clearances moved out of the wizard entirely and
   * became a stage that opens after the first payment
   * (docs/clearances-after-payment.md, decided 3 August 2026). A test that
   * still demanded a permits step would now be demanding the bug back.
   *
   * The old ordering rule was `permits` before `tax profile` before
   * `documentary`, and it was forced: the documents were the union of the
   * document types on the selected permit types and the tax questions varied by
   * permit code, so both had to follow the cards. Note that the last two are
   * now in the OPPOSITE order — documentary before tax profile. That is not a
   * cosmetic swap, it is the evidence that the dependency is really gone:
   * nothing computes either step from a later answer any more, so they run in
   * the order the client's diagram gives rather than the order the data forced.
   *
   * What is asserted instead is the property that actually matters and that a
   * merge could silently undo: the wizard describes ONE permit.
   */
  const map = await page.locator('ol[aria-label="Application sections"] li').allTextContents()
  const joined = map.join(' | ').toLowerCase()

  // Exactly six sections, and no office form sheet among them. The sheets used
  // to slot into the middle of this map the moment a clearance was ticked.
  expect(map, `the section map is not the six business-permit phases: ${joined}`).toHaveLength(6)
  for (const gone of ['permits & certificates', 'sanitary', 'fsic', 'fire safety', 'occupancy form', 'environmental']) {
    expect(joined, `"${gone}" is still a step in the wizard`).not.toContain(gone)
  }

  const at = (label: string) => joined.indexOf(label)
  expect(at('privacy'), 'privacy consent missing from the step map').toBeGreaterThanOrEqual(0)

  // Consent before collection; the business described before the paperwork
  // that describes it; and the two derived steps last, in the paper's order.
  expect(at('privacy')).toBeLessThan(at('location & zoning'))
  expect(at('location & zoning')).toBeLessThan(at('business information'))
  expect(at('business information')).toBeLessThan(at('documentary'))
  expect(at('documentary')).toBeLessThan(at('tax profile'))
  expect(at('tax profile')).toBeLessThan(at('review'))

  // The count is part of the promise: "Part 1 of 8" was the old flow.
  await expect(page.getByText(/part 1 of 6/i).first()).toBeVisible()
})

test('line of business is asked once, and the one ask is the searchable picker', async ({
  page,
}) => {
  /*
   * Item 69. It used to be asked twice: a plain dropdown on Location & Zoning
   * so the zoning verdict had a trade to be about, and a whole section of its
   * own three steps later. The section is gone and the picker moved onto the
   * zoning step, so the weaker control is not what survived.
   *
   * Scoped to the section map by its label rather than to any `ol`: the map is
   * no longer the only ordered list the wizard renders, and a bare `ol li`
   * would start counting rows that are not sections at all.
   */
  const map = await page.locator('ol[aria-label="Application sections"] li').allTextContents()
  const asks = map.filter((label) => /line of business/i.test(label))
  expect(asks, `line of business has ${asks.length} sections of its own`).toEqual([])

  await page.getByRole('checkbox').first().check()
  await page.getByRole('button', { name: /next/i }).click()
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

  // Search is the thing a <select> cannot do, and it is why this is the
  // control that was kept.
  const search = page.getByLabel(/search your line of business/i)
  await expect(search).toBeVisible()

  /*
   * The results are a dropdown, not a slab. Permanently open, ten trades and a
   * Selected panel pushed the map off the screen on the step whose job is
   * picking a location.
   */
  const results = page.locator('#psic-results')
  await expect(results).toBeHidden()
  await search.click()
  await expect(results).toBeVisible()

  /*
   * "Other (not listed)" is gone on purpose. It stored the catch-all PSIC row
   * with a NULL revenue-code category, and 35 of the 36 business-tax rules
   * match on that category — so a line filed under it was assessed no business
   * tax at all. If anyone puts it back, they are reopening that.
   */
  await expect(page.getByRole('button', { name: /other \(not listed\)/i })).toHaveCount(0)

  // Escape closes it without the applicant having to find somewhere neutral
  // to click.
  await page.keyboard.press('Escape')
  await expect(results).toBeHidden()
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
