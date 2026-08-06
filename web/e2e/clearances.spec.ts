import { expect, test, type Page } from '@playwright/test'

/*
 * The LGU Clearances, from a business owner's side.
 *
 * Two properties here are not preferences, and both have already been broken
 * once (fixed in aabbf21, reported as "sometimes it will just highlight the
 * apply button, sometimes it will actually redirect to the form"):
 *
 *   Apply always opens that office's form.
 *   Submit always opens the upload box.
 *   Neither toggles.
 *
 * They were toggles, so the outcome depended on state the applicant could not
 * see and flipped on every click — and one of the two outcomes was destructive:
 * a second click on "Submitted ✓" deleted the file that had just been uploaded,
 * with no confirmation and no undo. A toggle is exactly the kind of thing that
 * comes back in a merge because it looks tidier, so it is asserted rather than
 * assumed.
 *
 * The third property is the lock, and it is the one that changed on 4 August
 * 2026. This suite used to assert that the stage was SHUT until the first
 * payment cleared. It is the other way round now: the six are chosen while the
 * filing is a draft, and they shut the moment it is submitted, because payment
 * is the last thing that happens (docs/clearances-before-payment.md). A spec
 * still demanding the old lock would be demanding the accrual back.
 */

test.use({ storageState: 'e2e/.auth/owner.json' })

/**
 * Make a draft of our own, through the API, rather than hunting for one.
 *
 * Deliberately not a fixture found in the data. This suite runs against a
 * throwaway copy of the SQLite file, and whether it happens to contain a draft
 * with a clearance already applied for is not a property of the code — a spec
 * that skips when the snapshot is unlucky is a spec that stops catching
 * anything. Creating the exact state under test costs one round trip.
 */
async function makeDraft(page: Page): Promise<number> {
  return page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.public')
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    }
    const json = async (res: Response) => (await res.json()).data

    const barangays = await json(await fetch('/api/v1/reference/barangays', { headers }))
    const psic = await json(await fetch('/api/v1/reference/psic-codes', { headers }))
    const permitTypes = await json(await fetch('/api/v1/reference/permit-types', { headers }))
    const business = await json(
      await fetch('/api/v1/businesses', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          name: `E2E Clearances ${Date.now()}`,
          registration_type: 'DTI',
          registration_number: 'DTI-E2E-001',
          tin: '123-456-789-000',
          address: { line1: '1 Playwright St.', barangay_id: barangays[0].id },
          lines: [{ psic_code_id: psic[0].id, capitalization: 500000 }],
        }),
      }),
    )
    const app = await json(
      await fetch('/api/v1/applications', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          business_id: business.id,
          application_type: 'new',
          permit_type_ids: [
            permitTypes.find((pt: { code: string }) => pt.code === 'BUSINESS').id,
          ],
        }),
      }),
    )
    return app.id as number
  })
}

/**
 * A draft complete enough that the wizard's section map is walkable to the
 * end: every field the earlier steps require, and every required document.
 *
 * The wizard refuses a forward jump over an unfinished section, which is the
 * right behaviour and the reason this exists — reaching the LGU Clearances
 * step by clicking through six sections of form-filling would be a test of the
 * form, not of the step.
 */
async function makeCompleteDraft(page: Page): Promise<number> {
  return page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.public')
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    }
    const json = async (res: Response) => (await res.json()).data

    const barangays = await json(await fetch('/api/v1/reference/barangays', { headers }))
    const allPsic = await json(await fetch('/api/v1/reference/psic-codes', { headers }))
    /*
     * Anything but 00000. The catch-all "Other (not listed)" row carries a NULL
     * revenue-code category, and the address step refuses a line filed under it
     * — 35 of the 36 business-tax rules match on that category, so a filing
     * carrying one would be assessed no business tax at all.
     */
    const psic = allPsic.filter((c: { code: string }) => c.code !== '00000')
    const permitTypes = await json(await fetch('/api/v1/reference/permit-types', { headers }))
    const business = await json(
      await fetch('/api/v1/businesses', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          name: `E2E Wizard Clearances ${Date.now()}`,
          registration_type: 'DTI',
          registration_number: 'DTI-E2E-002',
          tin: '123-456-789-000',
          address: {
            line1: '2 Playwright St.',
            barangay_id: barangays[0].id,
            // Inside the Malabon bounding box the address step checks.
            latitude: 14.6572,
            longitude: 120.9573,
          },
          emergency_contact_name: 'Ana Dela Cruz',
          emergency_contact_number: '0917 123 4567',
          lines: [{ psic_code_id: psic[0].id, capitalization: 500000 }],
        }),
      }),
    )
    const businessType = permitTypes.find((pt: { code: string }) => pt.code === 'BUSINESS')
    const app = await json(
      await fetch('/api/v1/applications', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          business_id: business.id,
          application_type: 'new',
          permit_type_ids: [businessType.id],
          fee_profile: {
            business_structure: 'sole_proprietorship',
            floor_area_sqm: 120,
            employees: 12,
            employees_in_lgu: 6,
            lines: [
              { psic_code_id: psic[0].id, category: 'retailer', capitalization: 500000 },
            ],
          },
        }),
      }),
    )

    /*
     * Every required documentary requirement, so the documents step is done.
     *
     * Real PDF magic bytes, not a text blob renamed .pdf: the API validates
     * with `mimes:pdf`, which sniffs the content rather than trusting the name.
     */
    const pdf = '%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n'
    for (const dt of businessType.document_types) {
      if (dt.is_required === false || (dt.context && dt.context !== 'all')) continue
      const body = new FormData()
      body.append('document_type_id', String(dt.id))
      body.append('file', new File([pdf], `${dt.code}.pdf`, { type: 'application/pdf' }))
      await fetch(`/api/v1/applications/${app.id}/documents`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        body,
      })
    }

    return app.id as number
  })
}

/** Apply for one clearance on that draft, so a card is in the applied state. */
async function applyFor(page: Page, appId: number, code: string): Promise<void> {
  await page.evaluate(
    async ({ appId, code }) => {
      const token = localStorage.getItem('biztrack.token.public')
      await fetch(`/api/v1/applications/${appId}/clearances/${code}/apply`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
    },
    { appId, code },
  )
}

/**
 * The six clearance cards, found by the one control every card always has.
 *
 * This read `.filter({ hasText: /apply/i })`, which is a filter on the FACE of
 * the Apply button — and that button reads "Applied ✓" once the clearance is
 * applied for. "Applied" does not contain the substring "apply", so a card in
 * the state most of these tests are about matched nothing, and a filing with
 * all six applied for produced a grid of zero cards. It survived only because
 * every card the suite happened to look at was untouched.
 *
 * The accessible name is the stable thing: it is "Apply for the ‹clearance›" or
 * "Applied for the ‹clearance› — open its form", never absent, and naming the
 * card by the control that defines it is closer to what these tests mean than
 * matching a word that happens to be printed inside it.
 */
function clearanceCards(page: Page) {
  return page
    .locator('ul > li')
    .filter({ has: page.getByRole('button', { name: /^appl(y|ied) for the /i }) })
}

/** Any application of the tester's whose clearance stage has already shut. */
async function findShut(page: Page): Promise<number | null> {
  return page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.public')
    const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` }
    const res = await fetch('/api/v1/applications?per_page=100', { headers })
    const body = await res.json()
    for (const app of body.data ?? []) {
      if (app.status === 'draft') continue
      const c = await fetch(`/api/v1/applications/${app.id}/clearances`, { headers })
      if (!c.ok) continue
      if (!(await c.json()).meta.unlocked) return app.id as number
    }
    return null
  })
}

test('a draft can still choose its clearances, and the grid says which button spends money', async ({
  page,
}) => {
  await page.goto('/dashboard')
  const appId = await makeDraft(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  /*
   * Six, including Market.
   *
   * It was five: item 98 derived the Market card from the filing's own
   * declaration and hid it otherwise. That was reversed on the client's
   * instruction, and the reason is worth keeping — the three revenue-code
   * categories it keyed on describe the operator who RUNS a market, not the
   * trader renting one stall inside it. So the card was hidden from precisely
   * the people it exists for, who then had no way to discover it.
   *
   * Shown to everyone, labelled with who it is for, and optional. What is
   * asserted is that it carries that label, since a sixth card with no
   * explanation is just a sixth thing to work out.
   */
  const cards = clearanceCards(page)
  await expect(cards).toHaveCount(6)
  const market = cards.filter({ hasText: /market clearance/i })
  await expect(market).toHaveCount(1)
  await expect(market).toContainText(/optional/i)
  await expect(market).toContainText(/stall in a public or private market/i)

  // Nothing is locked, so no reason is shown: before submission there is
  // nothing to explain.
  await expect(page.locator('#clearances-locked')).toHaveCount(0)

  /*
   * The consequence, before either button is pressed. Apply and Submit sit side
   * by side and look alike, and one of them spends money.
   *
   * The RULE is above the grid, once — it is identical for all six clearances,
   * and the client's *"absurd amount of text"* was six cards each repeating it.
   *
   * The AMOUNT is no longer on the card. It printed there until the client saw
   * the finished grid — a chip, a fee, a tinted panel and three controls per
   * card, six times over — and said the older, plainer card was better. Every
   * clearance's fee still lands on the one Tax Order of Payment at Review &
   * Submit, which is where the money is actually agreed to, and which the last
   * test in this file asserts end to end.
   */
  await expect(page.getByText(/apply adds that office.s fee/i)).toBeVisible()
  await expect(page.getByText(/submit a copy.*costs nothing/i)).toBeVisible()
  await expect(cards.first()).not.toContainText(/fee ₱/i)

  /*
   * And no badge on a card nobody has touched. "Not requested" used to sit on
   * every one of them — *"tf does 'not requested' even mean"* — which is a
   * status a screen reader reads out six times to say that nothing has
   * happened six times.
   */
  await expect(cards.first()).not.toContainText(/not requested/i)

  const apply = cards.first().getByRole('button', { name: /^apply for the/i })
  await expect(apply).toHaveAttribute('aria-disabled', 'false')
})

test('once the filing is submitted the six are shut, in the API’s own words', async ({ page }) => {
  await page.goto('/dashboard')
  const shut = await findShut(page)
  expect(shut, 'no submitted application in this dataset').not.toBeNull()

  await page.goto(`/applications/${shut}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  /*
   * Visible, not hidden. The cards are the point of showing a shut stage at
   * all — this is the record of what the filing asked for.
   *
   * A floor rather than an exact count, because item 98 made the number depend
   * on the filing: the Market Clearance card is on screen only where the
   * declaration says the applicant trades from a stall, or where they applied
   * for it anyway. Whichever submitted filing this dataset hands us, the other
   * five are always there, and pinning "6" would make this test a test of which
   * application happened to be found.
   */
  const cards = clearanceCards(page)
  await expect
    .poll(() => cards.count(), { timeout: 30_000 })
    .toBeGreaterThanOrEqual(5)

  /*
   * The reason is the server's sentence, not one written here. The condition
   * that closes this stage is the API's to state; a second version in the
   * client would drift the first time the rule moved.
   */
  const reason = page.locator('#clearances-locked')
  await expect(reason).toBeVisible()
  const shown = (await reason.textContent())?.trim() ?? ''
  expect(shown.length, 'the shut stage shows no reason at all').toBeGreaterThan(20)

  const fromApi = await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const res = await fetch(`/api/v1/applications/${id}/clearances`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
    return (await res.json()).meta.locked_reason as string | null
  }, shut)
  expect(shown, 'the locked reason was paraphrased instead of shown verbatim').toBe(fromApi)

  /*
   * The buttons stay in the tab order. `disabled` drops a control out of it and
   * most screen readers pass over it, so an applicant using one would never
   * learn the button exists or why it does nothing.
   */
  const apply = cards.first().getByRole('button', { name: /^apply for the/i })
  await expect(apply).toHaveAttribute('aria-disabled', 'true')
  await expect(apply).toHaveAttribute('aria-describedby', 'clearances-locked')
  /*
   * The native attribute specifically, not Playwright's `toBeDisabled()` —
   * that one treats `aria-disabled="true"` as disabled too, which is right for
   * "can this be operated" and wrong for the question being asked here. The
   * question is whether the control is still REACHABLE: a native `disabled`
   * leaves the tab order, an aria-disabled one does not, and a button an
   * applicant can neither press nor find is a button they will never learn
   * exists.
   */
  const reachable = await apply.evaluate(
    (el) => !(el as HTMLButtonElement).disabled && (el as HTMLButtonElement).tabIndex >= 0,
  )
  expect(reachable, 'the shut Apply button is closed with `disabled`, not `aria-disabled`').toBe(
    true,
  )
  await apply.focus()
  await expect(apply).toBeFocused()

  /*
   * And pressing them does nothing at all: no form, no upload box.
   *
   * `dispatchEvent` rather than `click()`, because Playwright's actionability
   * check refuses to click an aria-disabled control and would fail here for the
   * wrong reason. What is under test is that the HANDLER refuses — the guard is
   * in the click handler, not only in the styling, so a stray Enter from a
   * keyboard user on a focusable button cannot change a submitted filing.
   */
  await apply.dispatchEvent('click')
  await page.waitForTimeout(500)
  await expect(page.getByRole('dialog')).toBeHidden()
  await expect(page.getByRole('button', { name: /save & back to clearances/i })).toBeHidden()

  const submit = cards.first().getByRole('button', { name: /submit a copy/i })
  await submit.dispatchEvent('click')
  await page.waitForTimeout(500)
  await expect(page.getByRole('dialog')).toBeHidden()
})

test('Apply always opens that office’s form, and never un-applies', async ({ page }) => {
  await page.goto('/dashboard')
  const appId = await makeDraft(page)
  // SANITARY is one of the four clearances with an applicant-facing sheet, and
  // applying first makes this the purest statement of the property: Apply on an
  // already-applied clearance must open the form and must not undo anything.
  await applyFor(page, appId, 'SANITARY')

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const card = page.locator('ul > li').filter({ hasText: /sanitary/i })
  await expect(card).toHaveCount(1)
  /*
   * The state reads off the button itself now that the status chip is gone —
   * "Applied ✓" where an untouched card says "Apply".
   */
  const applied = card.getByRole('button', { name: /^applied for the/i })

  // Under the old toggle this click un-applied it and opened nothing.
  await expect(applied).toBeVisible()

  const backToCards = page.getByRole('button', { name: /save & back to clearances/i })

  await applied.click()
  await expect(backToCards, 'Apply did not open the office form').toBeVisible()

  await page.getByRole('button', { name: /back without saving/i }).click()
  await expect(backToCards).toBeHidden()

  // Still applied for: the click opened a form, it did not undo anything.
  await expect(applied).toBeVisible()

  // And again. A toggle would open nothing the second time.
  await applied.click()
  await expect(backToCards, 'a second Apply did not open the office form').toBeVisible()
  await page.getByRole('button', { name: /back without saving/i }).click()
  await expect(applied).toBeVisible()
})

test('Submit always opens the upload box, and never removes what is there', async ({ page }) => {
  await page.goto('/dashboard')
  const appId = await makeDraft(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const card = page.locator('ul > li').filter({ hasText: /sanitary/i })
  const dialog = page.getByRole('dialog')

  await card.getByRole('button', { name: /submit a copy/i }).click()
  await expect(dialog, 'Submit did not open the upload box').toBeVisible()
  await expect(dialog).toContainText(/choose your certificate/i)
  /*
   * The consequence, on the box itself. Apply and Submit sit side by side and
   * look alike; one of them spends money and the other does not.
   */
  await expect(dialog).toContainText(/nothing is added to your fees/i)

  await dialog.getByRole('button', { name: /^cancel$/i }).click()
  await expect(dialog).toBeHidden()

  // Again. Under the old toggle a second Submit DELETED the uploaded file.
  await card.getByRole('button', { name: /submit a copy/i }).click()
  await expect(dialog, 'a second Submit did not open the upload box').toBeVisible()
  await dialog.getByRole('button', { name: /^cancel$/i }).click()
})

/**
 * Put a real file into the SUBMISSION dialog and send it.
 *
 * No test in this suite had ever uploaded anything, and that is precisely why
 * CLR-1 shipped: the two tests that open this dialog check its wording and
 * press Cancel, twice, so the request that 422s was never sent by anything but
 * a person. A dialog whose Cancel button works is not a working dialog.
 *
 * The file is built in-process rather than read off disk — a fixture file is
 * one more thing to keep, and the bytes are irrelevant to every assertion here.
 */
async function submitCopy(page: Page, confirm: RegExp): Promise<void> {
  const dialog = page.getByRole('dialog')
  await dialog
    .locator('input[type=file]')
    .setInputFiles({ name: 'certificate.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 e2e') })
  await expect(dialog).toContainText(/certificate\.pdf/i)
  await dialog.getByRole('button', { name: confirm }).click()
  await expect(dialog).toBeHidden()
}

/*
 * RESTORED: 'un-applying has its own labelled control, apart from Apply'.
 *
 * This test was deleted in 9e30b44 along with the control it asserted, and
 * replaced by a comment saying so. That comment closed with *"When that control
 * finds a home, this test should come back pointed at it."* — this is that,
 * pointed at the Withdraw link on the card.
 *
 * What the deletion cost, measured in the audit of 2026-08-06: 15 real drafts
 * that could not take a clearance back off, 5 of which could not be submitted
 * at all, and one route out of an accidental Apply — cancel the entire filing
 * and retype it. Meanwhile the server went on refusing to file a held copy
 * while a clearance was applied for, in a sentence naming a "Withdraw" control
 * that existed on no screen.
 *
 * The property is a pair, and both halves have to be asserted together or the
 * fix for one becomes the other's bug: there IS a way back out, and it is NOT a
 * second press of Apply.
 */
test('withdrawing has its own named control, and Apply is never it', async ({ page }) => {
  await page.goto('/dashboard')
  const appId = await makeDraft(page)
  await applyFor(page, appId, 'SANITARY')

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const card = page.locator('ul > li').filter({ hasText: /sanitary/i })
  const withdraw = card.getByRole('button', { name: /^withdraw your application for the/i })

  await expect(withdraw, 'an applied clearance offers no way back out').toBeVisible()
  // One word on the card; the clearance it belongs to is in the accessible
  // name, because six cards share this grid and all six say "Withdraw".
  await expect(withdraw).toHaveText(/^withdraw$/i)
  await expect(withdraw).toHaveAccessibleName(/sanitary/i)

  await withdraw.click()

  // Back to untouched: the button reads Apply again and the way out is gone
  // with the state it left.
  await expect(card.getByRole('button', { name: /^apply for the/i })).toBeVisible()
  await expect(withdraw).toHaveCount(0)

  // Said out loud, like every other change on this grid.
  await expect(page.getByRole('status').filter({ hasText: /withdrew your application/i })).toBeVisible()

  /*
   * And the half that must not come back. Withdrawing is its own control
   * precisely so that Apply can keep exactly one meaning: the original bug
   * (aabbf21) was a toggle whose second press silently un-applied and opened
   * nothing.
   */
  await card.getByRole('button', { name: /^apply for the/i }).click()
  await expect(page.getByRole('button', { name: /back without saving/i })).toBeVisible()
  await page.getByRole('button', { name: /back without saving/i }).click()
  await expect(card.getByRole('button', { name: /^applied for the/i })).toBeVisible()
})

/*
 * CLR-1, the reported sequence, end to end and with a real file.
 *
 * The client's words: *"I cannot remove my application on the Zoning/Locational
 * Clearance once I changed my mind to Submit instead of Apply."* Apply, then
 * Submit with a certificate — the one sequence in the product that failed, and
 * the one that appeared in no test, because reaching the failure needs a file
 * and nothing here had ever picked one.
 *
 * ZONING deliberately, which is the card in the client's screenshot.
 */
test('changing your mind from Apply to Submit works, and says what it does first', async ({
  page,
}) => {
  await page.goto('/dashboard')
  const appId = await makeDraft(page)
  await applyFor(page, appId, 'ZONING')

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const card = page.locator('ul > li').filter({ hasText: /zoning/i })
  await card.getByRole('button', { name: /submit a copy of the/i }).click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()

  /*
   * The switch is stated before it happens and named on the button that does
   * it. A confirm reading "Submit" would withdraw an application for a
   * clearance without the word appearing anywhere on the control that did it —
   * the unnamed second meaning this card forbids everywhere else.
   */
  await expect(dialog).toContainText(/you applied for this one/i)
  await expect(dialog).toContainText(/withdrawn/i)
  const confirm = /^withdraw & submit$/i
  await expect(dialog.getByRole('button', { name: confirm })).toBeVisible()

  await submitCopy(page, confirm)

  // The card is on the other leg, and there is no 422 banner in sight.
  await expect(card.getByRole('button', { name: /replace the .* copy you submitted/i })).toBeVisible()
  await expect(card.getByRole('button', { name: /remove the .* copy/i })).toBeVisible()
  await expect(card.getByRole('button', { name: /^withdraw your application for the/i })).toHaveCount(0)
  await expect(page.getByText(/withdraw that request first/i)).toHaveCount(0)

  await expect(
    page.getByRole('status').filter({ hasText: /withdrew your application/i }),
  ).toBeVisible()
})

/*
 * CLR-3 — Apply over an uploaded copy asks before it deletes anything.
 *
 * The mutual exclusion is right in both directions; what was wrong is who
 * agreed to the deletion. Apply used to call removeHeld inline whenever a copy
 * was on file, which takes the row AND the file off disk, with no prompt and
 * no undo, on a button named "Apply" — against the rule written on this very
 * card: *"destroying something must never be the alternate meaning of the
 * button that created it."*
 *
 * Both answers are asserted. A confirmation nobody can decline is a delay.
 */
test('applying over a copy you uploaded asks first, and Cancel keeps the file', async ({
  page,
}) => {
  await page.goto('/dashboard')
  const appId = await makeDraft(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const card = page.locator('ul > li').filter({ hasText: /sanitary/i })
  await card.getByRole('button', { name: /submit a copy of the/i }).click()
  await submitCopy(page, /^submit$/i)
  await expect(card.getByRole('button', { name: /remove the .* copy/i })).toBeVisible()

  // Apply, and stop.
  await card.getByRole('button', { name: /^apply for the/i }).click()
  const warning = page.getByRole('dialog')
  await expect(warning, 'Apply deleted the uploaded copy without asking').toBeVisible()
  // The file is named. "Your copy" is not what is about to be lost; a specific
  // file the applicant chose is, and naming it is what makes this a decision.
  await expect(warning).toContainText(/certificate\.pdf/i)
  await warning.getByRole('button', { name: /keep my copy/i }).click()

  await expect(warning).toBeHidden()
  await expect(card.getByRole('button', { name: /remove the .* copy/i })).toBeVisible()
  await expect(card.getByRole('button', { name: /^apply for the/i })).toBeVisible()

  // Now agree to it. The confirm says Delete, because that is what it does.
  await card.getByRole('button', { name: /^apply for the/i }).click()
  await page.getByRole('dialog').getByRole('button', { name: /^delete & apply$/i }).click()

  await expect(page.getByRole('button', { name: /back without saving/i })).toBeVisible()
  await page.getByRole('button', { name: /back without saving/i }).click()

  await expect(card.getByRole('button', { name: /^applied for the/i })).toBeVisible()
  await expect(card.getByRole('button', { name: /remove the .* copy/i })).toHaveCount(0)
  // Announced once, for both halves of the act — a live region only holds the
  // last thing written to it, so two sentences would be one deletion nobody
  // was told about.
  await expect(page.getByRole('status').filter({ hasText: /deleted the copy/i })).toBeVisible()
})

test('what just happened is announced, not only drawn', async ({ page }) => {
  await page.goto('/dashboard')
  const appId = await makeDraft(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  /*
   * Applying commits the applicant to a fee. A card that changes silently is
   * invisible to a screen reader: they hear the button, then nothing, and have
   * no way to know they have just added money to a bill they cannot see. The
   * running balance that used to carry this job is gone with the accrual, so
   * the live region is the sentence naming what the press did.
   *
   * This used to press MARKET, because MARKET was the last clearance with no
   * office sheet and so the last on which Apply stayed put. Checklist item 109
   * gave it one, so now every one of the six navigates away — first ZONING
   * (item 101), now the rest. The press and the return are therefore both part
   * of the test: Apply opens the sheet, and the announcement of what it just
   * cost is still standing when the applicant comes back to the cards.
   */
  const card = page.locator('ul > li').filter({ hasText: /sanitary/i })
  await card.getByRole('button', { name: /^apply for the/i }).click()

  const back = page.getByRole('button', { name: /back without saving/i })
  await expect(back, 'Apply did not open the office sheet').toBeVisible()
  await back.click()

  const status = page.getByRole('status').filter({ hasText: /applied for your/i })
  await expect(status).toBeVisible()
  await expect(status).toContainText(/tax order of payment/i)
})

test('the Market Clearance is offered to everyone, and says who it is for', async ({ page }) => {
  /*
   * ITEM 98 — *"Market clearance should not be required. It is only required
   * for stall holders."*
   *
   * First built as a derivation: the card was hidden unless the filing's
   * declared revenue-code category or stall count implied market trade. The
   * client reversed it after seeing it, and was right to. The categories the
   * derivation read — public_market_100_plus_stalls,
   * public_market_under_100_stalls, private_market — describe the operator who
   * RUNS a market. The stall holder the client named is that operator's tenant
   * and carries none of them. So the card was hidden from exactly the people it
   * exists for, who had no way to find out it existed.
   *
   * The rule now is show-and-label, not hide-and-derive. What this guards is
   * that the label survives: a sixth card with nothing saying who it is for is
   * a sixth thing every applicant has to work out is not addressed to them.
   */
  await page.goto('/dashboard')
  const appId = await makeDraft(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const marketCard = page.locator('ul > li').filter({ hasText: /market clearance/i })
  await expect(marketCard).toHaveCount(1)

  // Optional, and who it is for — both on the card's face, not in a tooltip.
  await expect(marketCard).toContainText(/optional/i)
  await expect(marketCard).toContainText(/stall in a public or private market/i)

  /*
   * And it is tied to the buttons, so the two words that decide whether this
   * card is yours are heard BEFORE Apply or Submit, not after. A note only a
   * sighted reader gets is not a note that stops anyone applying for a market
   * stall they do not have.
   */
  const apply = marketCard.getByRole('button', { name: /^apply for the/i })
  const describedBy = await apply.getAttribute('aria-describedby')
  expect(describedBy, 'the Apply button names nothing that says who the card is for').toBeTruthy()
  await expect(page.locator(`#${describedBy!.split(' ').at(-1)}`)).toContainText(/stall/i)
})

test('the Market Clearance opens a sheet, and asks which stall it is clearing', async ({ page }) => {
  /*
   * ITEM 109 — *"Application form for Market Clearance is missing. Create
   * something for this since we currently don't have the paper version."*
   * Applying for it used to collect nothing whatsoever, so the Office of the
   * City Market Administrator received a request naming neither a market nor a
   * stall.
   *
   * The sheet is written rather than transcribed — the only one of the six that
   * is — so this test pins the two answers it refuses to do without. See the
   * header of web/src/pages/applicant/OfficeFormStep.tsx for why those two and
   * not more.
   */
  await page.goto('/dashboard')
  const appId = await makeDraft(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })
  // No reveal step any more — the card is on the grid with the other five.
  const marketCard = page.locator('ul > li').filter({ hasText: /market clearance/i })
  await marketCard.getByRole('button', { name: /^apply for the/i }).click()

  // Apply opens the sheet, as it does for the other five.
  await expect(page.getByRole('heading', { name: /market clearance \(stall holders\)/i })).toBeVisible()
  // It says on its face that it is interim — no invented form code.
  await expect(page.getByText(/the office has no printed version/i)).toBeVisible()

  // It opens with what it already knows rather than by asking again.
  await expect(page.getByLabel(/business name/i)).toHaveAttribute('readonly', '')
  await expect(page.getByLabel(/type of application/i)).toHaveAttribute('readonly', '')

  const save = page.getByRole('button', { name: /save & back to clearances/i })
  await expect(save).toBeDisabled()
  await expect(page.getByText(/still needed on this form/i)).toContainText(/name of market/i)
  await expect(page.getByText(/still needed on this form/i)).toContainText(/stall no/i)

  await page.getByLabel(/name of market/i).fill('Malabon Central Market')
  await page.getByLabel(/stall no/i).fill('B-14')

  // The stall count is optional, but a typed answer has to be countable: the
  // revenue-code market lines multiply it by a peso rate.
  await page.getByLabel(/number of stalls held/i).fill('two')
  await expect(page.getByRole('alert')).toContainText(/whole number/i)
  await expect(save).toBeDisabled()
  await page.getByLabel(/number of stalls held/i).fill('2')
  await expect(page.getByRole('alert')).toHaveCount(0)

  await expect(save).toBeEnabled()
  await save.click()
  await expect(marketCard.first()).toBeVisible()

  // Saved as the office will read it, derived answer and all.
  const stored = await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const res = await fetch(`/api/v1/applications/${id}/office-forms`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
    const forms = (await res.json()).data as {
      permit_type_code: string
      form_data: Record<string, unknown>
    }[]
    return forms.find((f) => f.permit_type_code === 'MARKET')?.form_data ?? null
  }, appId)

  expect(stored, 'the Market sheet was not stored').not.toBeNull()
  expect(stored).toMatchObject({
    market_name: 'Malabon Central Market',
    stall_no: 'B-14',
    stall_count: '2',
  })
  // Derived by the API, never typed.
  expect(String(stored?.application_type)).toMatch(/market clearance/i)
})

test('applying is reported on the button, and never by a second meaning of it', async ({
  page,
}) => {
  /*
   * Replaces the ITEM 107 test, which asserted the paint on a "Don't apply for
   * the <clearance>" button. That control is gone from the card along with the
   * status chip, the fee line and the tinted panel around them: six cards each
   * carrying five pieces of furniture made the grid unreadable, and the
   * client's verdict on seeing it was that the older, plainer card was better.
   *
   * What has to stay true is the rule underneath that control, which is why
   * this test exists rather than nothing: Apply reports its own state, and
   * pressing it again must not mean the opposite. The original bug was a
   * toggle — a second click silently un-applied and opened no form.
   */
  await page.goto('/dashboard')
  const appId = await makeDraft(page)
  await applyFor(page, appId, 'SANITARY')

  await page.goto(`/applications/${appId}/clearances`)
  const card = page.locator('ul > li').filter({ hasText: /sanitary/i })

  // The state is on the control that changed it, not in a separate badge.
  const apply = card.getByRole('button', { name: /^applied for the/i })
  await expect(apply).toBeVisible()

  /*
   * ── This assertion used to read `toHaveCount(0)` on any withdraw control ──
   *
   * `await expect(card.getByRole('button', { name: /don't apply/i })).toHaveCount(0)`
   *
   * Which enforced CLR-1. Written in 9e30b44 in place of the test that asserted
   * the control existed, it turned the client's "this card is too busy" into
   * "this card has no way out", and any fix restoring one would have gone red
   * — the wrong way round, and the reason the audit called this the most
   * dangerous thing it found. A test that has to be deleted to fix a bug was
   * never testing the rule; it was testing the state of the file.
   *
   * The real rule is about SHAPE, and it is the one the client actually gave:
   * nothing on this card may carry the full name of its clearance on its face.
   * That is what wrapped the old control onto two lines, on all six cards, and
   * it is why the withdraw link that replaced it reads one word and puts the
   * clearance in its accessible name. Asserted as a property of every control
   * on the card, so it also holds for the next one somebody adds.
   *
   * Whether a withdraw control exists at all is asserted positively, near the
   * top of this file — 'withdrawing has its own named control, and Apply is
   * never it'.
   */
  for (const label of await card.getByRole('button').allInnerTexts()) {
    expect(label.trim().length, `"${label.trim()}" is too long for this card`).toBeLessThanOrEqual(
      'Submitted ✓'.length,
    )
  }
  await expect(card.getByRole('button', { name: /don’t apply/i })).toHaveCount(0)

  /*
   * Every control on the grid is named for ITS clearance. The visible labels
   * are one word and identical on all six cards, so the accessible name is the
   * only thing telling them apart.
   */
  await expect(apply).toHaveAccessibleName(/sanitary/i)
  await expect(card.getByRole('button', { name: /^submit a copy of the/i })).toHaveAccessibleName(
    /sanitary/i,
  )

  // Pressing it again opens the office form. It must NOT un-apply.
  await apply.click()
  await expect(card.getByRole('button', { name: /^apply for the/i })).toHaveCount(0)
})

test('the wizard puts the clearances last, and one Tax Order of Payment covers them', async ({
  page,
}) => {
  /*
   * The whole point of the reorder, end to end: choose a clearance on the last
   * step before Review & Submit, submit, and be charged once for the business
   * permit and that clearance together.
   *
   * This is the test the previous arrangement could not have: the six were
   * only reachable after a payment, so "chosen before the bill" had no
   * meaning. It is also the one that would catch the office sheets failing to
   * slot in behind the cards — they are steps of the wizard again, not a panel
   * swapped in over them.
   */
  await page.goto('/dashboard')
  const appId = await makeCompleteDraft(page)

  await page.goto(`/apply?draft=${appId}`)
  // Consent is the one answer the API has no field for, so it is ticked here.
  await page.getByRole('checkbox').first().check()

  const map = page.locator('ol[aria-label="Application sections"]')
  await map.getByRole('button', { name: /lgu clearances/i }).click()

  // Five: the Market Clearance is derived (item 98) and this draft declares no
  // market category, so its card is not addressed to this applicant.
  // Six, Market included — it is shown to everyone and labelled optional
  // rather than derived and hidden. See the item 98 test above for why.
  const cards = clearanceCards(page)
  await expect(cards).toHaveCount(6, { timeout: 30_000 })

  /*
   * Until a clearance is decided the step does not pass — item 76's other
   * half. Walking past the six without touching them is not a decision.
   */
  await expect(page.getByText(/still needed on this part: at least one clearance/i)).toBeVisible()

  /*
   * The fire clearance, for two reasons. Its sheet has no required field, so
   * this stays a test of the sheet becoming a STEP rather than of filling one
   * in; and the Fire Code fee is derived (10% of the mayor's permit plus
   * regulatory fees, RA 9514) rather than matched against a business category,
   * so it is on the bill for any filing — which is what makes the assertion at
   * the end mean something. Apply must open the sheet, never toggle the card
   * off.
   */
  await cards.filter({ hasText: /fire/i }).getByRole('button', { name: /^apply for the/i }).click()

  const backToCards = page.getByRole('button', { name: /save & back to clearances/i })
  await expect(backToCards, 'Apply did not open the office sheet as a wizard step').toBeVisible()
  // And the sheet joined the section map, immediately behind the cards.
  await expect(map.getByRole('button', { name: /fire safety \(fsic\) form/i })).toBeVisible()

  await backToCards.click()
  await expect(cards.first()).toBeVisible()
  await expect(page.getByText(/still needed on this part/i)).toBeHidden()

  await map.getByRole('button', { name: /review & submit/i }).click()
  // The last screen before submission names what was decided, and which way.
  await expect(page.getByText(/fire.*applied for/i)).toBeVisible()
  await expect(page.getByText(/one tax order of payment/i)).toBeVisible()

  await page.getByRole('button', { name: /^submit$/i }).click()
  await page.getByRole('button', { name: /^proceed$/i }).click()
  await expect(page.getByText(/tracking/i).first()).toBeVisible({ timeout: 30_000 })

  /*
   * The bill itself. One Tax Order of Payment, and the fire office's line is
   * on it — assessed at submit from the permit types chosen on the step that
   * had just run, with no second charge behind it.
   */
  const filed = await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` }
    const app = (await (await fetch(`/api/v1/applications/${id}`, { headers })).json()).data
    const fee = (await (await fetch(`/api/v1/applications/${id}/fee`, { headers })).json()).data
    return {
      status: app.status as string,
      permitCodes: (app.permit_types ?? []).map((pt: { code: string }) => pt.code) as string[],
      lineItems: fee.line_items as { label: string; amount: number }[],
      total: Number(fee.total_amount),
    }
  }, appId)

  // The choice made on the wizard's last step reached the filing itself.
  expect(filed.permitCodes, 'the clearance chosen in the wizard is not on the filing').toContain(
    'FSIC',
  )
  // And payment is what is left to do, not something already behind us.
  expect(filed.status).toBe('pending_payment')

  const labels = filed.lineItems.map((l) => l.label.toLowerCase()).join(' | ')
  expect(labels, 'the business permit is not on the Tax Order of Payment').toContain(
    'business permit',
  )
  expect(labels, 'the fire clearance chosen in the wizard was not billed').toContain(
    'fire safety inspection certificate fee',
  )
  expect(filed.total).toBeGreaterThan(0)
})

/*
 * CLR-2 — an Apply pressed by mistake must not strand the whole filing.
 *
 * Applying inserts that clearance's sheet as a wizard STEP behind the cards,
 * and the Market sheet will not be walked past without a market name and a
 * stall number: Next is disabled while anything is missing, and the section map
 * refuses a forward jump over an unfinished step. So a shopfront greengrocer
 * who pressed Apply on the Market card — a card deliberately shown to every
 * business in the city, item 98 — had two options: invent a market they do not
 * trade from, or cancel the filing and retype it. The audit of 2026-08-06 found
 * five real drafts in exactly that state, none of them able to reach Review &
 * Submit.
 *
 * Nothing about the gating was wrong; the sheet's answers really are required.
 * What was missing is the thing the wizard's own comment already assumed
 * ("Withdrawing a clearance removes its sheet"), which had been true until the
 * control was deleted. This walks the trap and then walks out of it.
 *
 * MARKET rather than SANITARY or OCCUPANCY because it is the one with two
 * required answers and the one the client already objected to being offered
 * universally — the likeliest accidental Apply in the product.
 */
test('a clearance applied for by mistake can be withdrawn, and its wizard step goes with it', async ({
  page,
}) => {
  await page.goto('/dashboard')
  const appId = await makeCompleteDraft(page)

  await page.goto(`/apply?draft=${appId}`)
  await page.getByRole('checkbox').first().check()

  const map = page.locator('ol[aria-label="Application sections"]')
  await map.getByRole('button', { name: /lgu clearances/i }).click()

  const cards = clearanceCards(page)
  await expect(cards).toHaveCount(6, { timeout: 30_000 })
  const marketCard = cards.filter({ hasText: /market clearance/i })

  await marketCard.getByRole('button', { name: /^apply for the/i }).click()

  // The trap, exactly as five real drafts hit it: a step that cannot be
  // finished, cannot be skipped, and stands between the filing and submission.
  const marketStep = map.getByRole('button', { name: /market clearance form/i })
  await expect(marketStep, 'applying did not spawn the sheet as a step').toBeVisible()
  await expect(page.getByText(/still needed on this part/i)).toContainText(/name of market/i)
  await expect(page.getByRole('button', { name: /save & back to clearances/i })).toBeDisabled()

  /*
   * The way out, named on the screen you cannot leave. The applicant standing
   * here is not looking at the cards — this sheet is the one screen the trap
   * put them on, and it was the one screen that never mentioned them.
   */
  await expect(page.getByText(/applied for this by mistake/i)).toContainText(/withdraw/i)

  // The wizard's plain Back, since Save is refused until the sheet is answered.
  await page.getByRole('button', { name: /^back$/i }).click()
  await expect(marketCard.first()).toBeVisible()

  await marketCard.getByRole('button', { name: /^withdraw your application for the/i }).click()

  // The step goes with the clearance — the thing ApplyWizard's own comment has
  // assumed all along.
  await expect(marketStep, 'withdrawing left the sheet in the wizard').toHaveCount(0)
  await expect(marketCard.getByRole('button', { name: /^apply for the/i })).toBeVisible()

  /*
   * And the filing is submittable again. Something still has to be decided on
   * this step (walking past the six is not a decision), so this applies for the
   * fire clearance — whose sheet requires nothing — and goes on to the end. The
   * point is that Review & Submit is REACHABLE, which it was not while MARKET
   * was on the filing without its answers.
   */
  await cards.filter({ hasText: /fire/i }).getByRole('button', { name: /^apply for the/i }).click()
  await page.getByRole('button', { name: /save & back to clearances/i }).click()

  await map.getByRole('button', { name: /review & submit/i }).click()
  await expect(page.getByRole('button', { name: /^submit$/i })).toBeEnabled()
  await expect(page.getByText(/fire.*applied for/i)).toBeVisible()
  // And the clearance that was withdrawn is not on the filing being submitted.
  await expect(page.getByText(/market.*applied for/i)).toHaveCount(0)
})
