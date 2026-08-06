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
  const search = page.getByLabel(/search for the one line of business/i)
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

test('choosing a line of business is confirmed where it can be seen', async ({ page }) => {
  /*
   * Item 104a. "The selected line of business does not reflect after choosing."
   *
   * The state was never the problem — the row's checkbox ticked and the
   * "Selected (N)" panel updated. That panel sits directly BELOW the results,
   * which are absolutely positioned and up to 16rem tall, so the dropdown was
   * lying on top of the only confirmation the applicant could read.
   * `elementFromPoint` over the panel's heading returned a result row.
   *
   * The assertion is therefore not "the panel exists" — it did before — but
   * "the confirmation is on top, naming the trade, at the moment of the click".
   * A test that only checked for text would have passed against the bug.
   */
  await page.getByRole('checkbox').first().check()
  await page.getByRole('button', { name: /next/i }).click()
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

  const search = page.getByLabel(/search for the one line of business/i)
  await search.click()
  await search.fill('sari-sari')
  // The footer names the query it counted, so waiting on it is waiting for the
  // rows below to be the search's rows rather than the full list still on
  // screen from the moment before.
  await expect(page.getByText(/trades matching “sari-sari”/)).toBeVisible()

  const results = page.locator('#psic-results')
  // Matched on the element, not on a role: the rows carry `role="radio"` now
  // that a filing declares one trade, so `getByRole('button')` finds nothing.
  const row = results.locator('button').first()
  const trade = ((await row.textContent()) ?? '').replace(/PSIC\s*\d+$/, '').trim()
  await row.click()

  /*
   * The confirmation now lives in the "Selected" panel below the search box,
   * and it is readable because the dropdown closes on choosing.
   *
   * That is a change of mechanism, not of promise. When this was a
   * multi-select the list had to stay open to pick a second trade, so the
   * confirmation was pinned inside the dropdown to escape being covered by it.
   * A filing declares ONE trade now — the zoning verdict, the carried-over
   * business block and the Location Insights lookup all read lines[0] and
   * ignored the rest — so choosing is finishing, the list closes, and the
   * panel it used to hide is in plain sight.
   *
   * What is still asserted is the thing item 104a was about: at the moment of
   * the click, the applicant can SEE which trade they picked, named rather
   * than counted. Two rows in this list differ only by the words in their
   * brackets, so a count alone confirms nothing.
   *
   * The panel used to be headed "Selected (1)" and this test matched on that
   * string. The heading is now "Your line of business", because a running
   * count is the vocabulary of a list you are adding to and the client read it
   * as exactly that. The assertion is the same one — the confirmation is
   * visible, on top, and names the trade — against the wording that replaced
   * it, and the count is now something the test forbids rather than expects.
   */
  await expect(results).toBeHidden()

  const confirmation = page.getByText(/^Your line of business$/)
  await expect(confirmation).toBeVisible()
  await expect(page.getByText(trade, { exact: false }).first()).toBeVisible()

  // And nothing is lying on top of it — the original bug was a covered
  // confirmation, so the hit-test stays even though the coverer is gone.
  const box = await confirmation.boundingBox()
  expect(box).not.toBeNull()
  const onTop = await page.evaluate((b) => {
    const el = document.elementFromPoint(b!.x + 8, b!.y + b!.height / 2)
    return el?.textContent?.trim() ?? null
  }, box)
  expect(onTop, 'something is covering the selection confirmation').toContain(
    'Your line of business',
  )

  // And the same fact for somebody who cannot see it at all — a sentence, not
  // "Selected 1", which read aloud is a strong hint that a second is expected.
  const announced = page.locator('[aria-live="polite"]', { hasText: /^Your line of business is / })
  await expect(announced).toHaveCount(1)
})

test('the line of business step reads as one choice, and changing it is not an error', async ({
  page,
}) => {
  /*
   * The client, with a screenshot: "even though we made this to be just 1, the
   * checklist still kinda say that hey you should be able to select more".
   *
   * The behaviour had been single-select for weeks — picking a second trade
   * replaces the first — but the copy and the layout never caught up. The step
   * still said "Add every line you trade in — each one is assessed
   * separately", still counted the answer in a "Selected (1)" panel, and still
   * offered "Remove" in the error red. Every one of those is a promise the
   * control does not keep, and copy that contradicts a control loses: the
   * applicant believes the sentence and blames themselves for the control.
   *
   * Three separate things are held here, because they failed separately:
   * the words, the styling of the way out, and the radio semantics underneath.
   */
  await page.getByRole('checkbox').first().check()
  await page.getByRole('button', { name: /next/i }).click()
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

  // 1. Nothing on the step invites a second trade.
  await expect(page.getByText(/add every line you trade in/i)).toHaveCount(0)
  await expect(page.getByText(/each one is assessed separately/i)).toHaveCount(0)
  await expect(page.getByText(/at least one line of business/i)).toHaveCount(0)

  const search = page.getByLabel(/search for the one line of business/i)
  await search.click()
  await search.fill('sari-sari')
  await expect(page.getByText(/trades matching “sari-sari”/)).toBeVisible()

  /*
   * 2. The radio semantics are load-bearing and stay. They are the only thing
   * telling a screen-reader user that this is one-of-many rather than a pile
   * of independent checkboxes, and they are easy to lose in a copy rewrite —
   * which is what this test is guarding against.
   */
  const group = page.locator('#psic-results')
  await expect(group).toHaveAttribute('role', 'radiogroup')
  await expect(group).toHaveAttribute('aria-label', /line of business/i)
  const rows = group.getByRole('radio')
  expect(await rows.count()).toBeGreaterThan(0)
  await expect(rows.first()).toHaveAttribute('aria-checked', 'false')
  const trade = ((await rows.first().textContent()) ?? '').replace(/PSIC\s*\d+$/, '').trim()
  await rows.first().click()
  await expect(group).toBeHidden()
  await expect(page.getByText(trade, { exact: false }).first()).toBeVisible()

  /*
   * 3. Changing your mind is not an error. "Remove" was #bd0000, which
   * DESIGN.md reserves for errors and destructive actions; the clearance card
   * had to be un-reddened for the same reason (checklist item 107 — "This
   * should not look like a warning message or something"). The escape hatch
   * survives, in ink and royal, under words that describe a correction.
   */
  const change = page.getByRole('button', { name: /^change line of business$/i })
  const clear = page.getByRole('button', { name: /^clear line of business$/i })
  await expect(change).toBeVisible()
  await expect(clear).toBeVisible()
  await expect(page.getByRole('button', { name: /^remove$/i })).toHaveCount(0)

  for (const control of [change, clear]) {
    const colour = await control.evaluate((el) => getComputedStyle(el).color)
    // #bd0000 and its neighbours. Parsed rather than string-matched so a
    // different-but-still-red token cannot slip through.
    const [r, g, b] = (colour.match(/\d+/g) ?? []).map(Number)
    expect(
      r > 120 && g < 80 && b < 80,
      `a way out of a choice is painted error red (${colour})`,
    ).toBe(false)
  }

  // Change puts the applicant back in the picker rather than emptying the
  // answer, and Clear is still there for somebody who wants the box empty.
  await change.click()
  await expect(page.locator('#psic-results')).toBeVisible()
  await expect(search).toBeFocused()
  await page.keyboard.press('Escape')
  await clear.click()
  await expect(page.getByText(/^Your line of business$/)).toHaveCount(0)
  await expect(page.getByText(/required: choose your line of business/i)).toBeVisible()
})

/*
 * The 30 Revenue Code category slugs the applicant is offered, pinned.
 *
 * These are not labels and they are not free to change. FeeCalculator::matches()
 * (api/app/Services/FeeCalculator.php) does an `array_intersect` of a fee rule's
 * `business_category` against the stored string, and 35 of the 36 business-tax
 * rules turn on it. A slug that drifts matches no rule, and nothing complains:
 * the applicant gets a Tax Order of Payment that is quietly too small and the
 * city loses the tax. That failure is invisible to `tsc`, invisible on screen
 * and invisible in the API suite, which is why it is nailed down here.
 *
 * Rewording a LABEL is fine and needs no change to this list. Adding a category
 * means adding its slug here and to the API's fee rules together.
 */
const REVENUE_CODE_SLUGS = [
  'retailer',
  'essential_retailer',
  'wholesaler',
  'carinderia',
  'restaurant',
  'cafe_cafeteria',
  'fastfood_chain',
  'food_peddler',
  'manufacturer',
  'small_scale_manufacturing',
  'contractor',
  'service_establishment',
  'franchise_holder',
  'gasoline_station',
  'water_refilling_station',
  'internet_cafe',
  'barber_shop',
  'tailor_dress_shop',
  'laundry_dry_cleaning',
  'vulcanizing_shop',
  'vehicle_repair_shop',
  'junkshop',
  'lessor',
  'hotel',
  'pawnshop',
  'bank',
  'private_hospital',
  'medical_clinic',
  'dental_clinic',
  'printing_publication',
]

test('the Revenue Code category shows words and stores the slug the fee engine matches on', async ({
  page,
}) => {
  /*
   * The client: "revenue code categoery, is still lower case lol."
   *
   * The datalist offered raw API slugs — `tailor_dress_shop`,
   * `water_refilling_station` — so the applicant was shown one and, on picking
   * it, got it verbatim in the box. The obvious fix is the option's `label`
   * attribute, and it does not work: Chromium renders a datalist row that has
   * a label as two lines, the value ABOVE the label, so the slug stays on
   * screen (measured — see the comment on the datalist in FeeProfileStep.tsx).
   * Firefox does substitute the label, which is what makes the attribute look
   * like the answer.
   *
   * So the words are the option's VALUE, and the slug is recovered from them
   * by normalizeCategory on the way into the draft. That trade is only safe if
   * the recovery is exact, which is what this test is for. It runs against the
   * module itself rather than the rendered step: the category box lives on
   * part 5 of 7 behind a map pin, and a test that has to fill four steps to
   * reach an assertion fails for four reasons that are not the assertion.
   *
   * The dev server transforms the .tsx on request, so the browser can import
   * it directly. The specifier is a variable so that `tsc` does not try to
   * resolve a server path against the filesystem.
   */
  const spec = '/src/pages/applicant/FeeProfileStep.tsx'
  const found = await page.evaluate(async (path) => {
    const mod = (await import(/* @vite-ignore */ path)) as {
      FEE_CATEGORIES: { slug: string; label: string }[]
      EMPTY_FEE_PROFILE: Record<string, unknown>
      normalizeCategory: (text: string) => string
      buildFeeProfile: (
        draft: unknown,
        opts: { applicationType: string; permitCodes: string[]; lineIds: number[] },
      ) => { lines?: { category: string }[] }
    }
    const { FEE_CATEGORIES, EMPTY_FEE_PROFILE, normalizeCategory, buildFeeProfile } = mod

    // What the payload actually carries when the applicant takes each offered
    // row: the label goes into the box, so the label is what is normalised.
    const stored = FEE_CATEGORIES.map((c) => {
      const draft = {
        ...EMPTY_FEE_PROFILE,
        categories: { 7: { category: normalizeCategory(c.label), gross_sales: '', capitalization: '' } },
      }
      const profile = buildFeeProfile(draft, {
        applicationType: 'new',
        permitCodes: ['BUSINESS'],
        lineIds: [7],
      })

      return profile.lines?.[0]?.category ?? null
    })

    return {
      labels: FEE_CATEGORIES.map((c) => c.label),
      slugs: FEE_CATEGORIES.map((c) => c.slug),
      stored,
      // A trade that is not on the list — the field is free text on purpose,
      // because the Revenue Code has 273 categories and 30 are offered here.
      freeTyped: normalizeCategory('Sari-sari store'),
      accented: normalizeCategory('Piña colada bar'),
      // Idempotent, because buildFeeProfile normalises again at the boundary
      // and a draft saved before this change already holds a slug.
      alreadySlug: normalizeCategory('tailor_dress_shop'),
      messy: normalizeCategory('  Tailor  /  Dress   Shop  '),
    }
  }, spec)

  // 1. The offered slugs are still exactly the ones the fee rules match on.
  expect(found.slugs).toEqual(REVENUE_CODE_SLUGS)

  /*
   * 2. And picking any of them stores the slug, not the words. This is the
   * assertion the whole change hangs on: get it wrong and the box looks nicer
   * while the business tax silently stops being assessed.
   */
  expect(found.stored).toEqual(REVENUE_CODE_SLUGS)

  // 3. What the applicant is offered is readable. No underscores anywhere, and
  // the words are the ones a shop owner would use for the trade.
  for (const label of found.labels) {
    expect(label, `“${label}” still reads like a slug`).not.toMatch(/_/)
    expect(label, `“${label}” is not capitalised like a name`).toMatch(/^[A-Z]/)
  }
  expect(found.labels).toContain('Tailor / dress shop')
  expect(found.labels).toContain('Water refilling station')

  /*
   * 4. And a trade the applicant typed themselves survives. Turning
   * "Sari-sari store" into `sari_sari_store` is the intended outcome — the
   * officer reads it either way — but losing it, or dropping the ñ out of a
   * name, would be the normalisation doing harm to answer nobody offered.
   */
  expect(found.freeTyped).toBe('sari_sari_store')
  expect(found.accented).toBe('piña_colada_bar')
  expect(found.alreadySlug).toBe('tailor_dress_shop')
  expect(found.messy).toBe('tailor_dress_shop')
})

test('every line of business is reachable, and the count is stated', async ({ page }) => {
  /*
   * Item 104b — "all lines of business in the choices should appear".
   *
   * Two caps used to hide most of the register: an empty box showed the
   * eight-code shortlist and stopped, and a query was sliced to 25 without
   * saying so. "sale" matches 48 titles, so 23 real trades vanished and an
   * applicant whose trade was among them concluded it was not on the list.
   *
   * The numbers below are deliberately relative to the reference data rather
   * than hard-coded to 135: seeding another PSIC code must not fail this test,
   * but capping the list again must.
   */
  await page.getByRole('checkbox').first().check()
  await page.getByRole('button', { name: /next/i }).click()
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

  const total = await page.evaluate(async () => {
    const res = await fetch('/api/v1/reference/psic-codes', {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${localStorage.getItem('biztrack.token.public')}`,
      },
    })
    const body = await res.json()
    // '00000' is the catch-all row and is excluded from the picker on purpose:
    // it carries a NULL revenue-code category, and 35 of the 36 business-tax
    // rules match on that category.
    return (body.data as { code: string }[]).filter((c) => c.code !== '00000').length
  })
  expect(total).toBeGreaterThan(100)

  const search = page.getByLabel(/search for the one line of business/i)
  await search.click()
  const results = page.locator('#psic-results')

  // Browsing reaches everything. The shortlist is a head start, and it says so.
  // Matched on the element, not on a role: the rows carry `role="radio"` now
  // that a filing declares one trade, so `getByRole('button')` finds nothing.
  await expect(results.locator('button')).toHaveCount(total)
  await expect(results.getByText(/^Most common$/)).toBeVisible()
  await expect(page.getByText(new RegExp(`Showing all ${total} trades`))).toBeVisible()

  // Searching shows every match, and states how many that is out of how many.
  await search.fill('sale')
  const matches = await results.locator('button').count()
  expect(matches, 'the 25-row cap is back').toBeGreaterThan(25)
  await expect(
    page.getByText(new RegExp(`Showing all ${matches} of ${total} trades matching`)),
  ).toBeVisible()
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
 * Item 110 — answer the identity dialog a renewal or amendment opens with.
 *
 * Business 1 is the seeded owner's only business with permits in the register,
 * and it is also the one with a stored TIN, which is what the reading-back test
 * below needs. Choosing it by value rather than by index keeps this working
 * when somebody registers another shop on the test stack.
 */
async function answerIdentityDialog(page: Page, type: 'renewal' | 'amendment') {
  const name = type === 'renewal' ? /which permit are you renewing/i : /what are you amending/i
  const modal = page.getByRole('dialog', { name })
  await expect(modal).toBeVisible({ timeout: 30_000 })

  await modal.getByLabel(new RegExp(`which business are you ${type === 'renewal' ? 'renewing' : 'amending'}`, 'i')).selectOption({ value: '1' })

  const permits = modal.getByRole('radiogroup', { name: /which permit/i }).getByRole('radio')
  await expect(permits.first()).toBeVisible({ timeout: 20_000 })
  await permits.first().click()

  // An amendment must also say what it amends before the dialog will close.
  if (type === 'amendment') await modal.getByRole('checkbox').first().check()

  await modal.getByRole('button', { name: /continue/i }).click()
  await expect(modal).toBeHidden({ timeout: 20_000 })
}

/**
 * Consent, then Location & Zoning (part 2) with a line of business declared and
 * nothing else answered yet.
 *
 * Split out of `goToBusinessStep` because the zoning step is a destination in
 * its own right now, not only somewhere to pass through: Business Location
 * Insights renders on it, so tests need to arrive and stop here.
 */
async function goToZoningStep(page: Page, type?: 'renewal' | 'amendment') {
  // `beforeEach` already opened a new filing. A renewal has to be opened as
  // one from the start, because the type decides whether the wizard asks which
  // permit this filing is against at all.
  if (type) {
    await page.goto(`/apply?type=${type}`)
    /*
     * Item 110 — a renewal or amendment now meets the identity dialog BEFORE
     * the wizard, so it has to be answered here or nothing below this line is
     * reachable. Its own cover is in renewal-modal.spec.ts; this is only
     * getting past it, on the one seeded business that holds permits.
     */
    await answerIdentityDialog(page, type)
    await expect(page.getByText(/data privacy/i).first()).toBeVisible({ timeout: 30_000 })
  }
  await page.getByRole('checkbox').first().check()
  await page.getByRole('button', { name: /next/i }).click()
  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })

  const search = page.getByLabel(/search for the one line of business/i)
  await search.click()
  await search.fill('sari-sari')
  /*
   * Wait for the list to be the SEARCH's list before clicking a row in it.
   * Opening the picker now renders all 135 trades — item 104b, the shortlist
   * was hiding the other 127 — and the row a bare `.first()` resolves to is
   * detached the instant the query narrows it. The footer names the query it
   * counted, so it appears only once the results below it are the right ones.
   */
  await expect(page.getByText(/trades matching “sari-sari”/)).toBeVisible()
  // Matched on the element, not on a role: the rows carry `role="radio"` now
  // that a filing declares one trade, so `getByRole('button')` finds nothing.
  await page.locator('#psic-results button').first().click()
}

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
async function goToBusinessStep(page: Page, type?: 'renewal' | 'amendment') {
  await goToZoningStep(page, type)

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
  /*
   * Leaving the zoning step opens the conformity modal on the way out. The
   * modal is now the conformity message and nothing else — Business Location
   * Insights used to render inside it and does not any more. See the tests
   * below for why that matters.
   */
  await page.getByRole('button', { name: /proceed to application/i }).click()
  await expect(page.getByText(/part 3 of/i).first()).toBeVisible({ timeout: 20_000 })
}

/** The Business Location Insights card, wherever on the page it happens to be. */
function insightsPanel(page: Page) {
  return page.getByRole('region', { name: /business location insights/i })
}

test('Business Location Insights answers the pin, not the confirmation modal', async ({ page }) => {
  /*
   * The client's item, and it is a sequencing point rather than a layout one.
   *
   * These four figures are decision support for choosing a location. They used
   * to render inside the zoning-result modal, which opens on the way OUT of the
   * step — so they reached the applicant only after the location was chosen,
   * with a Proceed button under them. Useless there, and quietly misleading:
   * numbers inside a dialog headed CONGRATULATIONS read as part of the
   * conformity finding.
   *
   * So this asserts both halves. On the step, with the pin, while it can still
   * be moved — and NOT in the modal.
   */
  await goToZoningStep(page)

  // Nothing to report before there is a point to report on.
  await expect(insightsPanel(page)).toBeHidden()

  const map = page.locator('.leaflet-container')
  await map.scrollIntoViewIfNeeded()

  /*
   * Read the radius off the wire rather than hard-coding 500. The whole point of
   * `radius_m` being in the payload is that the server owns the number; a test
   * that asserts 500 would keep passing on the day the API changed it and the
   * screen went on saying 500.
   */
  const answered = page.waitForResponse(
    (r) => r.url().includes('location-insights') && r.status() === 200,
  )
  await map.click()
  const payload = (await (await answered).json()).data
  const radiusM = payload.radius_m as number
  const mediumFrom = payload.concentration.thresholds.medium_from as number
  const highFrom = payload.concentration.thresholds.high_from as number
  expect(radiusM).toBeGreaterThan(0)

  // The card is on the step, headed the way the client names it.
  const panel = insightsPanel(page)
  await expect(panel).toBeVisible()
  await expect(panel.getByRole('heading', { name: /business location insights/i })).toBeVisible()

  /*
   * And it has actually reported something — the skeleton is not the answer.
   * "Registered businesses in total" is the one row that always resolves: it
   * needs no line of business and it has no unavailable state, so it is the
   * honest proof that a real payload rendered.
   */
  await expect(panel.getByText(/registered businesses in total/i)).toBeVisible()
  await expect(panel.getByText(new RegExp(`within ${radiusM} m`, 'i')).first()).toBeVisible()

  /*
   * Four rows, each a Title over a Description, named exactly as the client
   * specified them. The titles are load-bearing and not decoration: "Nearby
   * Similar Businesses" counts the applicant's 3-digit PSIC trade GROUP while
   * "Most Common Line of Business" takes the mode of the 2-digit DIVISION, and
   * since the client removed the third figure that used to reconcile the two,
   * these words are now the only thing on screen carrying that difference.
   */
  for (const title of [
    'Nearby Similar Businesses',
    'Business Concentration',
    'Most Common Line of Business',
    'Average Distance to Similar Businesses',
  ]) {
    await expect(panel.getByText(title, { exact: true })).toBeVisible()
  }

  /*
   * And the row that used to sit between them is gone and stays gone. It
   * reconciled the two widths above with a third count, and the client decided
   * against it in favour of the titles doing that work — so a reader meeting the
   * old bug report must not "restore" it. See the panel's module docblock.
   */
  await expect(panel.getByText(/businesses in your own category/i)).toBeHidden()

  /*
   * The band is a word, not just a tint. DESIGN.md's Never Color Alone rule —
   * an ordinal scale carried in colour alone is no scale at all for a reader who
   * cannot separate the tints, and it must never be the error red either,
   * because a busy block is not a fault.
   *
   * Anchored to the count in brackets so this matches the BADGE specifically.
   */
  await expect(panel.getByText(/^(Low|Medium|High)\s*\(\d+\)$/)).toBeVisible()

  /*
   * ── The band scale, behind an info affordance ────────────────────────────
   *
   * "Low 0–5 · Medium 6–10 · High 11+" used to be printed inline under this
   * row. The client moved it into an affordance, which is the right call for
   * reference material read once — but only if the affordance is a real one.
   *
   * A `title=` tooltip would satisfy the request and fail the applicant: there
   * is no hover on touch, and hover is not reachable by keyboard. So this
   * asserts the WCAG 2.1 AA SC 1.4.13 behaviour rather than the presence of an
   * icon — it opens from the KEYBOARD, and Escape dismisses it.
   *
   * The scale itself is built from the payload's own thresholds, so a test that
   * hard-coded 5, 6 and 11 could not catch the legend drifting from the banding.
   */
  const bandScale = `Low 0–${mediumFrom - 1} · Medium ${mediumFrom}–${highFrom - 1} · High ${highFrom}+`
  await expect(panel.getByText(bandScale)).toBeHidden()

  const bandInfo = panel.getByRole('button', { name: /business concentration bands/i })
  await expect(bandInfo).toHaveAttribute('aria-expanded', 'false')

  await bandInfo.focus()
  await expect(bandInfo).toHaveAttribute('aria-expanded', 'true')
  await expect(panel.getByText(bandScale)).toBeVisible()

  await page.keyboard.press('Escape')
  await expect(panel.getByText(bandScale)).toBeHidden()
  await expect(bandInfo).toHaveAttribute('aria-expanded', 'false')

  /* ── and the modal it used to live in no longer carries it ─────────────── */
  await page.getByLabel(/house no\. & street name/i).fill('24 Rizal Street')
  await page.getByLabel(/barangay name/i).selectOption({ label: 'Acacia' })
  await page.getByLabel(/emergency contact person/i).fill('Juan Dela Cruz')
  await page.getByLabel(/emergency contact number/i).fill('0917 123 4567')
  await page.getByRole('button', { name: /^next$/i }).click()

  const modal = page.getByRole('dialog', { name: /congratulations/i })
  await expect(modal).toBeVisible()
  await expect(modal.getByRole('region', { name: /business location insights/i })).toBeHidden()
  await expect(modal.getByText(/registered businesses in total/i)).toBeHidden()

  /*
   * What the modal must keep. This CPDO line is the standing condition under
   * which the panel's removed "not part of the zoning decision" disclaimer would
   * have to come back — without it, CONGRATULATIONS is the only verdict on
   * screen and nothing says who actually issues the clearance.
   */
  await expect(modal.getByText(/zoning office \(cpdo\) makes the final determination/i)).toBeVisible()
})

test('the pin is ringed at the radius the figures were measured over', async ({ page }) => {
  /*
   * "Show a circle so the user knows how big 500 meters is." A distance is
   * abstract until it is drawn over the streets it covers.
   *
   * Three properties, and each one is a way this could be built wrong:
   *   - the ring is scaled in METRES (leaflet Circle), not pixels
   *     (CircleMarker) — a pixel radius would mean a different distance at
   *     every zoom level, which is the opposite of a scale reference
   *   - its size comes from the API's `radius_m`, not a 500 in the client
   *   - it does not eat clicks, or it would block the correction it invites
   */
  await goToZoningStep(page)

  const map = page.locator('.leaflet-container')
  await map.scrollIntoViewIfNeeded()

  const answered = page.waitForResponse(
    (r) => r.url().includes('location-insights') && r.status() === 200,
  )
  const box = (await map.boundingBox())!
  await map.click({ position: { x: box.width / 2, y: box.height / 2 } })
  const radiusM = (await (await answered).json()).data.radius_m as number

  const ring = page.locator('path.biztrack-radius-ring')
  await expect(ring).toBeVisible()

  /*
   * Said in words as well as drawn, so the ring means something with colour off
   * and to a screen reader, which cannot see an SVG path at all — and stated as
   * the API's own number, so caption and circle can never disagree.
   */
  await expect(page.getByText(new RegExp(`circle around it covers ${radiusM} m`, 'i'))).toBeVisible()

  /*
   * The ring is scenery, and this is checked BEFORE zooming so the offset below
   * is still comfortably inside it. It is centred on the pin, so the area an
   * applicant is most likely to click to CORRECT a slightly-off pin is exactly
   * the area it covers — if it were interactive (Leaflet's default for a Path)
   * it would swallow that click and the pin could never be nudged.
   *
   * 20 px in from the centre: inside the ring at the opening zoom, and far
   * enough from the previous click that the browser does not fold the two into
   * a double-click, which Leaflet would answer by zooming instead of pinning.
   */
  await expect(ring).not.toHaveClass(/leaflet-interactive/)
  const pinnedBefore = await page.getByText(/pinned at/i).innerText()
  await map.click({ position: { x: box.width / 2 + 20, y: box.height / 2 } })
  await expect
    .poll(async () => page.getByText(/pinned at/i).innerText(), { timeout: 10_000 })
    .not.toBe(pinnedBefore)

  /*
   * Metres, not pixels: zooming out must shrink the ring on screen, because the
   * ground it covers has not changed. A CircleMarker would hold its pixel size
   * here, and this is the assertion that catches the swap.
   */
  const widthBefore = (await ring.boundingBox())!.width
  expect(widthBefore).toBeGreaterThan(0)
  await page.getByRole('button', { name: /zoom out/i }).click()
  await expect
    .poll(async () => (await ring.boundingBox())!.width, { timeout: 10_000 })
    .toBeLessThan(widthBefore * 0.75)
})

test('moving the pin does not stampede the lookup, and never shows the old point’s figures', async ({
  page,
}) => {
  /*
   * The lifecycle the move introduced. In the modal the query was frozen once;
   * on the step the pin can move as often as the applicant likes, and each move
   * is a new coordinate.
   *
   * Two failure modes, both invisible to tsc:
   *   - a request per click, at an endpoint that scans the register per call
   *   - the PREVIOUS point's figures sitting under the new pin during the gap,
   *     which is worse than a delay because a stale number looks exactly like a
   *     fresh one
   */
  await goToZoningStep(page)

  const map = page.locator('.leaflet-container')
  await map.scrollIntoViewIfNeeded()
  const box = (await map.boundingBox())!

  let lookups = 0
  page.on('request', (r) => {
    if (r.url().includes('location-insights')) lookups += 1
  })

  /*
   * Four pins in quick succession. Debounced, this is one question; undebounced
   * it is four.
   *
   * page.mouse rather than locator.click, because locator.click re-runs
   * actionability checks each time and the burst has to be faster than the
   * 400 ms debounce to be testing anything.
   *
   * The offsets are spread rather than adjacent for two reasons. Clicks a few
   * pixels apart get folded into a double-click, which Leaflet answers by
   * zooming instead of pinning — so the burst would silently stop moving the
   * pin. And at the opening zoom (~18 m per pixel) every one of these lands
   * within a kilometre of Malabon City Hall, so all four are inside the city
   * bounding box and none is refused.
   */
  const cx = box.x + box.width / 2
  const cy = box.y + box.height / 2
  for (const [dx, dy] of [
    [0, 0],
    [40, -20],
    [-40, -30],
    [30, 20],
  ]) {
    await page.mouse.click(cx + dx, cy + dy)
  }

  // The figures for the LAST pin arrive and the panel settles.
  await expect(insightsPanel(page).getByText(/registered businesses in total/i)).toBeVisible({
    timeout: 15_000,
  })
  /*
   * At most two: the debounce may legitimately let an early click through if the
   * browser is slow enough that 400 ms elapses mid-burst. Four would mean it is
   * not debouncing at all, which is the regression this guards.
   */
  expect(lookups, 'one lookup per click means the debounce is gone').toBeLessThanOrEqual(2)
  expect(lookups).toBeGreaterThanOrEqual(1)

  /*
   * Now move the pin and catch the gap. While the answer is behind the pin the
   * panel must be in its loading state — the previous point's figures must not
   * be on screen under a marker they were never measured from.
   */
  await map.click({ position: { x: box.width / 2 - 30, y: box.height / 2 - 30 } })
  await expect(insightsPanel(page).getByRole('status', { name: /loading location insights/i })).toBeVisible()
  await expect(insightsPanel(page).getByText(/registered businesses in total/i)).toBeHidden()

  // ...and it comes back on its own.
  await expect(insightsPanel(page).getByText(/registered businesses in total/i)).toBeVisible({
    timeout: 15_000,
  })
})

test('a failed insights lookup never blocks the filing', async ({ page }) => {
  /*
   * The panel says these figures are not part of the application, and that
   * promise has to be true in the gate as well as in the copy. It matters more
   * here than it did in the modal: a failure now meets an applicant mid-decision
   * staring at a map, where a broken advisory lookup could easily read as the
   * step itself refusing to continue.
   */
  await page.route('**/location-insights**', (route) => route.abort())

  await goToZoningStep(page)
  const map = page.locator('.leaflet-container')
  await map.scrollIntoViewIfNeeded()
  await map.click()
  await expect(page.getByText(/pinned at/i)).toBeVisible()

  // It says so plainly rather than showing a broken table or nothing at all.
  const panel = insightsPanel(page)
  await expect(panel.getByText(/couldn’t load these figures/i)).toBeVisible()
  await expect(panel.getByText(/you can continue/i)).toBeVisible()

  // No ring, because no radius was ever reported. A guessed circle would be a
  // claim about a distance nothing measured.
  await expect(page.locator('path.biztrack-radius-ring')).toBeHidden()

  // And the step still goes on, all the way through the conformity modal.
  await page.getByLabel(/house no\. & street name/i).fill('24 Rizal Street')
  await page.getByLabel(/barangay name/i).selectOption({ label: 'Acacia' })
  await page.getByLabel(/emergency contact person/i).fill('Juan Dela Cruz')
  await page.getByLabel(/emergency contact number/i).fill('0917 123 4567')
  await page.getByRole('button', { name: /^next$/i }).click()
  await page.getByRole('button', { name: /proceed to application/i }).click()
  await expect(page.getByText(/part 3 of/i).first()).toBeVisible({ timeout: 20_000 })
})

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

/* ── Item 105 · the TIN, entered as four boxes ─────────────────────────────
 *
 * The tests below are about the two ways a split input goes wrong. Neither is
 * hypothetical: both were reachable in the first draft of this control.
 */

/** The four boxes, in printed order. */
function tinBoxes(page: Page) {
  const group = page.getByRole('group', { name: /tax identification/i })

  return [0, 1, 2, 3].map((i) => group.getByRole('textbox').nth(i))
}

test('the TIN is four boxes of three, and typing walks across them', async ({ page }) => {
  await goToBusinessStep(page)

  const boxes = tinBoxes(page)
  for (const box of boxes) {
    await expect(box).toHaveAttribute('maxlength', '3')
    // A soft keyboard that opens on letters for a field that only takes digits
    // is a barrier, not a preference.
    await expect(box).toHaveAttribute('inputmode', 'numeric')
  }

  /*
   * Typed straight through, with no Tab. Auto-advance is what makes four boxes
   * bearable — and it is also what broke: an early version deferred the caret
   * to a requestAnimationFrame, which lost the race against fast typing and
   * produced 123-645-789-000. The right digits in the wrong order is the one
   * outcome a TIN must never have, and it is invisible to a length check, so
   * the assertion is per box.
   */
  await boxes[0].click()
  await page.keyboard.type('123456789000')
  await expect(boxes[0]).toHaveValue('123')
  await expect(boxes[1]).toHaveValue('456')
  await expect(boxes[2]).toHaveValue('789')
  await expect(boxes[3]).toHaveValue('000')

  /*
   * Backspace in an empty box steps back. Without it the applicant who mistypes
   * the third digit is auto-advanced into an empty box where Backspace does
   * nothing, and the box they want is behind them, reachable only by mouse.
   */
  await boxes[3].fill('')
  await boxes[3].focus()
  await page.keyboard.press('Backspace')
  await expect(boxes[2]).toBeFocused()
  // And it moved rather than also deleting: one keypress, one visible effect.
  await expect(boxes[2]).toHaveValue('789')
})

test('a TIN pasted into the first box spreads across all four', async ({ page, context }) => {
  /*
   * The single most common failure of split inputs, and the likeliest to be hit
   * here: a TIN is a number people copy off an email or a BIR certificate, not
   * one they retype. `maxLength={3}` truncates a paste to fit the box, so
   * without an explicit onPaste handler "123-456-789-000" dropped into the
   * first box becomes "123" and nine digits are gone without a word.
   */
  await context.grantPermissions(['clipboard-read', 'clipboard-write'])
  await goToBusinessStep(page)

  const boxes = tinBoxes(page)

  // Written the way it is printed, dashes and all.
  await page.evaluate(() => navigator.clipboard.writeText('987-654-321-111'))
  await boxes[0].click()
  await page.keyboard.press('ControlOrMeta+v')
  await expect(boxes[0]).toHaveValue('987')
  await expect(boxes[1]).toHaveValue('654')
  await expect(boxes[2]).toHaveValue('321')
  await expect(boxes[3]).toHaveValue('111')

  // And written as twelve bare digits, which is how it arrives from a
  // spreadsheet or another system's export.
  for (const box of boxes) await box.fill('')
  await page.evaluate(() => navigator.clipboard.writeText('123456789000'))
  await boxes[0].click()
  await page.keyboard.press('ControlOrMeta+v')
  await expect(boxes[0]).toHaveValue('123')
  await expect(boxes[1]).toHaveValue('456')
  await expect(boxes[2]).toHaveValue('789')
  await expect(boxes[3]).toHaveValue('000')

  /*
   * A nine-digit TIN with no branch code is not a malformed one — seven
   * businesses in the register are filed under exactly that shape — so the last
   * box may be left empty and the value must still be accepted.
   */
  for (const box of boxes) await box.fill('')
  await page.evaluate(() => navigator.clipboard.writeText('111-111-111'))
  await boxes[0].click()
  await page.keyboard.press('ControlOrMeta+v')
  await expect(boxes[2]).toHaveValue('111')
  await expect(boxes[3]).toHaveValue('')
  await page.getByLabel(/^business name/i).click()
  await expect(page.locator('#tin-error')).toHaveCount(0)
})

test('a TIN already on file reads back into the four boxes', async ({ page }) => {
  /*
   * The other half of "the wire format does not change": a filing made before
   * this control existed holds "123-456-789-000" in businesses.tin, and it has
   * to arrive in four boxes without a migration and without the applicant
   * retyping it. Renewal prefill is the shortest real path to a stored value —
   * the API hands back exactly what a reopened draft would.
   *
   * The business used to be picked here, on this step. Item 110 moved that
   * question into the dialog the wizard now opens with, so by the time we are
   * on part 3 the prefill has already run — which is the point of the item and
   * makes this test read the way it always meant to.
   */
  await goToBusinessStep(page, 'renewal')

  const boxes = tinBoxes(page)
  await expect(boxes[0]).toHaveValue(/^\d{3}$/)
  await expect(boxes[1]).toHaveValue(/^\d{3}$/)
  await expect(boxes[2]).toHaveValue(/^\d{3}$/)
  // The branch code is optional in the register, so it is asserted as "digits
  // or nothing" rather than pinned — but never as a fragment of the nine.
  await expect(boxes[3]).toHaveValue(/^\d*$/)

  // A value that arrived correctly does not complain about itself.
  await page.getByLabel(/^business name/i).click()
  await expect(page.locator('#tin-error')).toHaveCount(0)
})

test('the four TIN boxes are one named question, not four nameless ones', async ({ page }) => {
  /*
   * Four unlabelled boxes are the classic screen-reader failure of this
   * pattern: "edit text, edit text, edit text, edit text", with no statement
   * anywhere that they add up to a TIN. WCAG 2.1 AA, 1.3.1 and 3.3.2.
   */
  await goToBusinessStep(page)

  const group = page.getByRole('group', { name: /tax identification number/i })
  await expect(group).toHaveCount(1)

  // Each box says which digits of the printed number it holds, so somebody
  // reading their certificate aloud knows where they are.
  const names = await group
    .getByRole('textbox')
    .evaluateAll((els) => els.map((el) => el.getAttribute('aria-label')))
  expect(names).toEqual([
    'TIN, first three digits',
    'TIN, second three digits',
    'TIN, third three digits',
    'TIN branch code, three digits',
  ])

  /*
   * The error is described on the GROUP, not on each box, so it is heard once
   * on entering the question instead of four times on the way across it.
   */
  const boxes = tinBoxes(page)
  await boxes[0].click()
  await page.keyboard.type('12')
  // Nothing yet: a format error on the first digit paints the whole group red
  // for the eleven digits it takes to reach a right answer.
  await expect(page.locator('#tin-error')).toHaveCount(0)

  await page.getByLabel(/^business name/i).click()
  await expect(page.locator('#tin-error')).toBeVisible()

  const describedBy = await group.getAttribute('aria-describedby')
  expect(describedBy, 'the TIN error is not described on the group').toContain('tin-error')
  const perBox = await group
    .getByRole('textbox')
    .evaluateAll((els) => els.filter((el) => el.getAttribute('aria-describedby')).length)
  expect(perBox, 'the error is described on each box, so it is read four times').toBe(0)
})
