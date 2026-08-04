import { expect, test, type Page } from '@playwright/test'

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

  /*
   * Matched on the prefix, not the whole string. The label carries a hint
   * after the field name — "Application title — named automatically, edit it
   * if you want your own" — which is copy, and pinning copy character for
   * character makes a test that fails every time someone improves a sentence.
   * What matters here is that there is exactly one field and it is named.
   */
  expect(names).toHaveLength(1)
  expect(names[0]).toMatch(/^Application title/)
  expect(names, 'a field on the consent step has no label').not.toContain('(unlabelled)')

  /*
   * Run the personal-data check against the field's NAME, not its help text.
   * The label reads "Application title — named automatically, …", and the
   * word "named" in that hint is not a request for anybody's name. Matching
   * the whole label failed on its own explanation, which is the sort of false
   * positive that gets a real check deleted rather than fixed.
   */
  for (const name of names) {
    const fieldName = name.split('—')[0].trim()
    expect(fieldName).not.toMatch(/name|address|birth|contact|mobile|email|tin|registration/i)
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

test('the LGU clearances are the last part before Review & Submit', async ({ page }) => {
  /*
   * ── What this test has been through ───────────────────────────────────
   *
   * It began as "the LGU permits step comes after the business is described",
   * asserting `business < permits < documentary` and
   * `permits < tax profile < documentary`. That ordering was forced by a data
   * dependency: the documents were the union of the document types on the
   * selected permit types, and the tax profile's questions varied by permit
   * code, so both had to follow the cards. Checklist item 76 — "place this at
   * the last part before submitting" — was recorded as a deviation.
   *
   * It then became "the wizard is the business permit alone", when the six
   * clearances were moved out into a stage that opened after the first payment.
   * That lasted a day: it cost an accruing balance, a second payment, a gate
   * holding the permit, and a locked stage.
   *
   * What it asserts now is item 76 as asked for
   * (docs/clearances-before-payment.md). The clearances are step 6 of 7, the
   * last decision before Review & Submit, and everything is paid for once
   * afterwards. Note that Documentary Requirements still precedes the tax
   * profile — the reverse of the original forced order, which is the evidence
   * that the dependency really is gone rather than merely reordered around.
   */
  const map = await page.locator('ol[aria-label="Application sections"] li').allTextContents()
  const joined = map.join(' | ').toLowerCase()

  /*
   * Seven sections on a fresh filing. Office form sheets slot into this map
   * behind the clearances step, one per clearance applied for, so the count
   * grows from here — it does not start higher.
   */
  expect(map, `the section map is not the seven phases: ${joined}`).toHaveLength(7)
  // The old catch-all step that bundled the six with the mayor's permit.
  expect(joined, '"permits & certificates" is back as a step').not.toContain(
    'permits & certificates',
  )
  // No sheet is a step until its clearance has been applied for.
  for (const sheet of ['sanitary permit form', 'fire safety', 'occupancy permit form']) {
    expect(joined, `"${sheet}" is a step before any clearance was applied for`).not.toContain(sheet)
  }

  const at = (label: string) => joined.indexOf(label)
  expect(at('privacy'), 'privacy consent missing from the step map').toBeGreaterThanOrEqual(0)

  // Consent before collection; the business described before the paperwork
  // that describes it; the clearances after everything their office sheets are
  // filled in from; and Review last.
  expect(at('privacy')).toBeLessThan(at('location & zoning'))
  expect(at('location & zoning')).toBeLessThan(at('business information'))
  expect(at('business information')).toBeLessThan(at('documentary'))
  expect(at('documentary')).toBeLessThan(at('tax profile'))
  expect(at('tax profile')).toBeLessThan(at('lgu clearances'))
  expect(at('lgu clearances')).toBeLessThan(at('review'))

  // The count is part of the promise: "Part 1 of 8" was the original flow and
  // "Part 1 of 6" was the day the clearances lived outside the wizard.
  await expect(page.getByText(/part 1 of 7/i).first()).toBeVisible()
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

/**
 * Consent, then a complete Location & Zoning step, landing on Business
 * Information (part 3). Everything here is the minimum the step's own gate
 * demands — a trade, a pin inside the city, an address and someone an inspector
 * can reach.
 *
 * No capital is typed, and the step still passes its gate. It used to ask for
 * one per line here and Business & Tax Profile asked for the same figure again;
 * only the second was ever assessed. The question lives on that step alone now,
 * so this step demands place and trade and nothing about money.
 */
async function goToBusinessStep(page: Page) {
  await page.getByRole('checkbox').first().check()
  await page.getByRole('button', { name: /next/i }).click()
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

  const search = page.getByLabel(/search your line of business/i)
  await search.click()
  await search.fill('sari-sari')
  await page.locator('#psic-results').getByRole('button').first().click()

  // Centre of the map is Malabon City Hall, so this pin is always inside.
  const map = page.locator('.leaflet-container')
  await map.scrollIntoViewIfNeeded()
  await map.click()
  await expect(page.getByText(/pinned at/i)).toBeVisible()

  await page.getByLabel(/house no\. & street name/i).fill('24 Rizal Street')
  await page.getByLabel(/barangay name/i).selectOption({ label: 'Acacia' })
  await page.getByLabel(/emergency contact person/i).fill('Juan Dela Cruz')
  await page.getByLabel(/emergency contact number/i).fill('0917 123 4567')

  await page.getByRole('button', { name: /^next$/i }).click()
  // Leaving the zoning step opens the conformity modal on the way out.
  await page.getByRole('button', { name: /proceed to application/i }).click()
  await expect(page.getByText(/part 3 of/i).first()).toBeVisible({ timeout: 20_000 })
}

test('the type of registration is asked before the number it decides', async ({ page }) => {
  /*
   * Checklist item 94. The section used to ask for a "DTI / SEC / CDA
   * Registration Number" first and the Type of Registration four fields later,
   * so it wanted a number without knowing whose number it wanted, offered an
   * example from two different agencies in one placeholder, and never checked
   * that the two answers agreed.
   *
   * DTI registers sole proprietors, CDA registers cooperatives, and the SEC
   * registers BOTH partnerships and corporations — so the structure decides the
   * agency and never the reverse. That direction is what this test pins.
   */
  await goToBusinessStep(page)

  const structure = page.getByRole('radiogroup', { name: /type of registration/i })
  await expect(structure).toBeVisible()

  // Asked first, on the page and in the "still needed" summary a screen reader
  // hears. Ordering is the item; asserting the fields exist would not be.
  const still = page.getByText(/still needed on this part/i)
  await expect(still).toContainText(/type of registration.*registration number/is)

  /*
   * Before an answer the field is generic — and read-only rather than
   * disabled, so it keeps its place in the tab order and can say why it is
   * closed. A disabled field would simply vanish for the applicant who most
   * needs the explanation.
   */
  const help = page.locator('#registration-number-help')
  await expect(help).toHaveText(/choose your type of registration above/i)
  const before = page.getByRole('textbox', { name: /registration number/i })
  await expect(before).toHaveAttribute('readonly', '')

  /* Sole proprietorship → DTI, and the label says so rather than listing three. */
  await structure.getByRole('radio', { name: 'Sole Proprietorship' }).click()
  const dti = page.getByRole('textbox', { name: /DTI Business Name Registration Number/i })
  await expect(dti).toBeVisible()
  await expect(dti).not.toHaveAttribute('readonly', '')
  await expect(help).toContainText(/department of trade and industry/i)
  await dti.fill('3298765')

  /*
   * Corporation → SEC, and the DTI number goes with it. A DTI Business Name
   * number is not this company's SEC registration number, and leaving it in
   * place would submit one agency's reference under another agency's label —
   * the exact mismatch the item is about.
   */
  await structure.getByRole('radio', { name: 'Corporation' }).click()
  const sec = page.getByRole('textbox', { name: /SEC Registration Number/i })
  await expect(sec).toBeVisible()
  await expect(sec).toHaveValue('')
  await expect(help).toContainText(/securities and exchange commission/i)
  await sec.fill('CS201811119')

  /*
   * Partnership → still SEC, so the number stays. Both are registered with the
   * same agency, and clearing it here would punish the applicant for correcting
   * an answer that says nothing about their number.
   */
  await structure.getByRole('radio', { name: 'Partnership' }).click()
  await expect(page.getByRole('textbox', { name: /SEC Registration Number/i })).toHaveValue(
    'CS201811119',
  )

  /* Cooperative → CDA. */
  await structure.getByRole('radio', { name: 'Cooperative' }).click()
  await expect(page.getByRole('textbox', { name: /CDA Registration Number/i })).toBeVisible()
  await expect(help).toContainText(/cooperative development authority/i)

  /*
   * The four are one answer, not four switches. `aria-pressed` announced them
   * as independent toggles, with no way to hear that picking one unpicked
   * another.
   */
  await expect(structure.getByRole('radio')).toHaveCount(4)
  await expect(structure.getByRole('radio', { name: 'Cooperative' })).toBeChecked()
  await expect(structure.getByRole('radio', { name: 'Partnership' })).not.toBeChecked()
})

test('no data field on Business Information is closed with `disabled`', async ({ page }) => {
  /*
   * The same rule as part 2, enforced on the step that now has a field which is
   * deliberately inert until another answer opens it (item 94). That field is
   * exactly the kind that gets written as `disabled` by reflex.
   */
  await goToBusinessStep(page)

  const disabledFields = await page
    .locator('input:disabled, select:disabled, textarea:disabled')
    .evaluateAll((els) =>
      els.map((el) => el.getAttribute('name') ?? el.getAttribute('aria-label') ?? el.tagName),
    )

  expect(
    disabledFields,
    `these fields use disabled where readOnly is required: ${JSON.stringify(disabledFields)}`,
  ).toEqual([])
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
