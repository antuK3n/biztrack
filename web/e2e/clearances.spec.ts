import { expect, test, type Page } from '@playwright/test'
import { sessionFor } from './helpers'

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
 * The third property is the lock, and it has now been round the houses twice.
 * It asserted a stage shut until the first payment cleared; then, from 4 August
 * 2026, the reverse — the six chosen while the filing was a draft, shut the
 * moment it was submitted. As of 28 August it is the first arrangement again,
 * and this time it is the whole point rather than an implementation detail
 * (docs/clearances-after-payment.md):
 *
 *     wizard (business permit only) → submit → Tax Order of Payment #1 → PAID
 *         → the stage unlocks
 *         → each Apply adds its office's fee to a running balance
 *         → the permit is not released until that balance reaches zero
 *
 * So a DRAFT is locked here, which inverts the setup of almost every test
 * below: exercising Apply or Submit needs a PAID application, not a draft, and
 * `makePaidApplication` is what most of them now call.
 *
 * Two consequences worth asserting explicitly, because both are money:
 *
 *   The balance is on the screen. Applying re-assesses the filing, so the
 *   number moves under the applicant's hand, and a stage that charged them
 *   without showing it would be taking money in the dark.
 *
 *   The price is on the card BEFORE the press. `fee_preview` was allowed off
 *   the cards while one Tax Order of Payment at submit covered everything —
 *   there was a later screen to read the amount on. There is not now.
 */

test.use({ storageState: sessionFor('owner') })

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
            /*
             * Longos by name, not `barangays[0]`. There is no bounding box any
             * more: the address step checks the real city polygon AND that the
             * pin agrees with the chosen barangay. These coordinates are Malabon
             * City Hall, which is in Longos; Acacia — first alphabetically — is
             * about 1.5 km off and would now be refused.
             */
            barangay_id: (barangays.find((b: { name: string }) => b.name === 'Longos') ?? barangays[0])
              .id,
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

/**
 * An application that has been submitted AND PAID — the state in which this
 * stage is actually open.
 *
 * This is the setup most of this file needs now, and it did not exist before,
 * because until 28 August a draft was the state the six were chosen in. A draft
 * is locked now, so a test that presses Apply on one is testing the lock rather
 * than the thing it means to test.
 *
 * It builds on `makeCompleteDraft` rather than repeating it: the reason that
 * helper fills in every required field and uploads every required document is
 * that submission refuses an incomplete filing, and that reason has not
 * changed. What is added on the end is the two calls that used to be somebody
 * else's problem —
 *
 *   POST /applications/{id}/submit  — assigns the tracking ID, moves the filing
 *     to pending_payment, and assesses the Tax Order of Payment for the
 *     business permit.
 *   POST /applications/{id}/pay     — the simulated gateway. This is what flips
 *     `meta.unlocked`, and it is the whole subject of this file.
 *
 * Both are asserted rather than fire-and-forget. A silent failure here would
 * leave every test below staring at a locked stage and reporting that the cards
 * do not work, which is the least useful failure this suite could produce.
 */
async function makePaidApplication(page: Page): Promise<number> {
  const appId = await makeCompleteDraft(page)
  await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    }
    const submitted = await fetch(`/api/v1/applications/${id}/submit`, { method: 'POST', headers })
    if (!submitted.ok) {
      throw new Error(`submitting answered ${submitted.status}: ${await submitted.text()}`)
    }
    const paid = await fetch(`/api/v1/applications/${id}/pay`, {
      method: 'POST',
      headers,
      body: JSON.stringify({ method: 'gcash' }),
    })
    if (!paid.ok) throw new Error(`paying answered ${paid.status}: ${await paid.text()}`)
  }, appId)
  return appId
}

/** Apply for one clearance, so a card is in the applied state. */
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

test('before the first payment the stage is visible but locked, in the API’s own words', async ({
  page,
}) => {
  /*
   * The gate, and the direction it now points.
   *
   * A draft is LOCKED. This test asserted the opposite between 4 and 28 August
   * — "a draft can still choose its clearances" — because the six were then a
   * step of the wizard and payment was the last thing that happened. It is not
   * a weakening to invert it: what is being asserted is the same property in
   * both cases, that the stage is open exactly when the server says it is and
   * says why when it is not.
   *
   * Visible-but-locked rather than hidden or 404, deliberately. The cards are
   * how an applicant finds out which clearances exist and what they cost, and
   * that is worth knowing before you can act on it — "where do I get my
   * sanitary permit" is the question this page answers even while shut.
   */
  await page.goto('/dashboard')
  // A draft, unpaid on purpose — that IS the locked state under test.
  const appId = await makeDraft(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  /*
   * All five are on screen, and every one of them is required.
   *
   * It was six until 6 September 2026, the sixth being the Market Clearance.
   * That card had already been through two reversals — hidden behind a
   * derivation from the filing's declared revenue-code category, then shown to
   * everyone again because those categories describe the operator who RUNS a
   * market rather than the trader renting one stall inside it, so the card was
   * hidden from exactly the people it existed for. The permit and its office
   * have now been removed from the system entirely.
   */
  const cards = clearanceCards(page)
  await expect(cards).toHaveCount(5)
  await expect(cards.filter({ hasText: /market/i })).toHaveCount(0)
  await expect(market).toContainText(/optional/i)
  await expect(market).toContainText(/stall in a public or private market/i)

  /*
   * The reason is the server's sentence, shown verbatim. There is no heading
   * over it any more: one used to read "These can no longer be changed", which
   * is right for a stage closed after release and flatly wrong for one that has
   * not opened yet — and this is the second case, telling an applicant who has
   * not paid that they had missed their chance.
   */
  const reason = page.locator('#clearances-locked')
  await expect(reason, 'a locked stage explains nothing').toBeVisible()
  const shownReason = (await reason.textContent())?.trim() ?? ''
  const apiReason = await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const res = await fetch(`/api/v1/applications/${id}/clearances`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
    return (await res.json()).meta.locked_reason as string | null
  }, appId)
  expect(shownReason, 'the locked reason was paraphrased instead of shown verbatim').toBe(apiReason)

  // And nothing can be pressed. The buttons stay reachable — see the test
  // below for why that is asserted separately and at length.
  const apply = cards.first().getByRole('button', { name: /^apply for the/i })
  await expect(apply).toHaveAttribute('aria-disabled', 'true')

  /*
   * The balance is on screen even while the stage is shut, and that is the less
   * obvious half of the rule. A locked stage means the first payment has not
   * cleared, so the balance is exactly what the applicant must pay to open it —
   * the most actionable number on the page. Hiding it until it stops mattering
   * would be precisely backwards.
   */
  /*
   * `exact` on the term, because "balance due" also appears in the sentence
   * above the grid explaining what Apply costs. The <dt> is the ledger; the
   * paragraph is a description of it, and matching either would let this pass
   * on a page that had lost the block entirely.
   */
  await expect(page.getByText('Balance due', { exact: true })).toBeVisible()
  await expect(page.getByText(/not released.*balance reaches zero|balance is unpaid/i)).toBeVisible()
})

test('once the stage is open, the grid says which button spends money and how much', async ({
  page,
}) => {
  await page.goto('/dashboard')
  const appId = await makePaidApplication(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const cards = clearanceCards(page)
  await expect(cards).toHaveCount(6, { timeout: 30_000 })

  // Paid, so open: no reason is shown, because there is nothing to explain.
  await expect(page.locator('#clearances-locked')).toHaveCount(0)
  const apply = cards.first().getByRole('button', { name: /^apply for the/i })
  await expect(apply).toHaveAttribute('aria-disabled', 'false')

  /*
   * The consequence, before either button is pressed. Apply and Submit sit side
   * by side and look alike, and one of them spends money.
   *
   * The RULE is above the grid, once — it is identical for all six clearances,
   * and the client's *"absurd amount of text"* was six cards each repeating it.
   */
  await expect(page.getByText(/apply adds that office.s fee/i)).toBeVisible()
  await expect(page.getByText(/submit a copy.*costs nothing/i)).toBeVisible()

  /*
   * The AMOUNT, on the card, before the press. This assertion is the exact
   * inverse of what stood here on 4 August (`not.toContainText(/fee ₱/i)`), and
   * the inversion is the reordering itself rather than a change of taste.
   *
   * The fee came off these cards on the client's instruction, and the argument
   * for removing it was that nothing was lost: every clearance's fee landed on
   * the one Tax Order of Payment at Review & Submit, a later screen where the
   * money was actually agreed to. There is no later screen now. The Tax Order
   * of Payment has been raised and paid before this stage opens, so Apply IS
   * the moment of commitment, and a button that spends an unstated amount is
   * the defect this guards against.
   *
   * Asserted across the grid rather than on one card, because `fee_preview` is
   * legitimately null where an office sets the amount case by case, and
   * legitimately zero where no revenue-code rule prices the permit. All three
   * cases have to say something; none may say nothing. (The Market Clearance
   * was the standing example of the null case until it was removed on
   * 6 September 2026.)
   */
  for (const card of await cards.all()) {
    await expect(card, 'a clearance card quotes no price at all').toContainText(
      /fee ₱|no fee assessed|fee set by this office/i,
    )
  }

  /*
   * And the ledger the press moves. Three figures, so the balance is checkable
   * rather than merely trusted.
   */
  await expect(page.getByText(/^assessed$/i)).toBeVisible()
  await expect(page.getByText(/^paid$/i)).toBeVisible()
  await expect(page.getByText(/^balance due$/i)).toBeVisible()

  /*
   * And no badge on a card nobody has touched. "Not requested" used to sit on
   * every one of them — *"tf does 'not requested' even mean"* — which is a
   * status a screen reader reads out six times to say that nothing has
   * happened six times.
   */
  await expect(cards.first()).not.toContainText(/not requested/i)
})

test('a locked Apply stays reachable, and refuses to do anything', async ({ page }) => {
  /*
   * The accessibility half of the lock, split out from the test above because
   * it is a different claim: not "the stage is shut" but "shutting it did not
   * make it invisible to anyone navigating by keyboard or screen reader".
   */
  await page.goto('/dashboard')
  // Unpaid, so shut. Same reason as the test above.
  const appId = await makeDraft(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const cards = clearanceCards(page)
  await expect(cards).toHaveCount(6, { timeout: 30_000 })

  const reason = page.locator('#clearances-locked')
  await expect(reason).toBeVisible()
  const shown = (await reason.textContent())?.trim() ?? ''
  expect(shown.length, 'the shut stage shows no reason at all').toBeGreaterThan(20)

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
   * keyboard user on a focusable button cannot commit an unpaid filing to a fee
   * it has no way to settle.
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
  const appId = await makePaidApplication(page)
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
  const appId = await makePaidApplication(page)

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
  const appId = await makePaidApplication(page)
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
  const appId = await makePaidApplication(page)
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
  const appId = await makePaidApplication(page)

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
  const appId = await makePaidApplication(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  /*
   * Applying commits the applicant to a fee. A card that changes silently is
   * invisible to a screen reader: they hear the button, then nothing, and have
   * no way to know they have just added money to a bill they cannot see.
   *
   * The running balance above the grid carries half of this now that fees
   * accrue again — but only the half a sighted reader gets. A number that
   * changes elsewhere on the page is not an announcement, so the live region
   * still has to say what the press did and what it cost.
   *
   * Every one of the five navigates away — first ZONING (item 101), then the
   * rest. This used to press MARKET, which was the last permit with no office
   * sheet and so the last on which Apply stayed put; item 109 gave it one, and
   * the permit itself went on 6 September 2026. The press and the return are
   * therefore both part of the test: Apply opens the sheet, and the
   * announcement of what it just cost is still standing when the applicant
   * comes back to the cards.
   */
  const card = page.locator('ul > li').filter({ hasText: /sanitary/i })
  await card.getByRole('button', { name: /^apply for the/i }).click()

  const back = page.getByRole('button', { name: /back without saving/i })
  await expect(back, 'Apply did not open the office sheet').toBeVisible()
  await back.click()

  const status = page.getByRole('status').filter({ hasText: /applied for your/i })
  await expect(status).toBeVisible()
  /*
   * "balance due", not "Tax Order of Payment". The applicant has already paid
   * one of those to get to this screen, so naming it here would point at a
   * settled bill rather than at the number that just moved.
   */
  await expect(status).toContainText(/balance due/i)
})

/*
 * Two Market Clearance tests lived here and went with the permit on
 * 6 September 2026.
 *
 *  - ITEM 98, "the Market Clearance is offered to everyone, and says who it is
 *    for": it asserted the card carried an applicability note naming stall
 *    holders, tied to Apply via aria-describedby so a screen reader heard who
 *    the card was for BEFORE the button.
 *  - ITEM 109, "the Market Clearance opens a sheet, and asks which stall it is
 *    clearing": it asserted the invented sheet stored a market name, a stall
 *    number and a stall count.
 *
 * Both rules survive their subject. If a conditional permit is ever added, the
 * aria-describedby assertion is the one to copy — APPLICABILITY in
 * ClearanceStagePage is still wired to it and still empty.
 */
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
  const appId = await makePaidApplication(page)
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

test('the wizard bills the business permit alone, and each clearance accrues after payment', async ({
  page,
}) => {
  /*
   * ── The rule this test asserts, and the one it used to ────────────────────
   *
   * It was named *"the wizard puts the clearances last, and one Tax Order of
   * Payment covers them"*, and it asserted exactly that: pick a clearance on
   * the wizard's step 6, submit, and find both the business permit and that
   * clearance on a single bill with nothing charged afterwards.
   *
   * That rule is inverted (docs/clearances-after-payment.md), so this test is
   * inverted with it rather than deleted — the property is still "the money
   * lands where the flow says it does", which is worth just as much pointed the
   * other way. It walks the whole reordering end to end:
   *
   *   1. the wizard has no clearance step, and the bill it produces carries the
   *      business permit and NO clearance line;
   *   2. before that bill is paid the stage is shut;
   *   3. paying opens it;
   *   4. applying for the fire clearance ADDS its fee — the accrual, which is
   *      the mechanism the previous arrangement existed to avoid and this one
   *      is built on.
   *
   * The fire clearance, for two reasons. Its office sheet has no required
   * field, so this stays a test of the money rather than of filling a form in;
   * and the Fire Code fee is derived (10% of the mayor's permit plus regulatory
   * fees, RA 9514) rather than matched against a business category, so it lands
   * on any filing at all — which is what makes step 4's assertion mean
   * something for whichever business this happens to build.
   */
  await page.goto('/dashboard')
  const appId = await makeCompleteDraft(page)

  /* ── 1. The wizard, with no clearance step and no clearance on its bill ─── */

  await page.goto(`/apply?draft=${appId}`)
  // Consent is the one answer the API has no field for, so it is ticked here.
  await page.getByRole('checkbox').first().check()

  const map = page.locator('ol[aria-label="Application sections"]')
  await expect(map).toBeVisible({ timeout: 30_000 })
  await expect(
    map.getByRole('button', { name: /clearance/i }),
    'the wizard grew a clearance step back',
  ).toHaveCount(0)

  await map.getByRole('button', { name: /review & submit/i }).click()
  /*
   * The last screen before submission says what is about to happen, and it is
   * the opposite of what it used to say. It read "Submitting produces one Tax
   * Order of Payment covering your Business Permit and every clearance below.
   * Nothing else is charged afterwards." — every clause of which is now false,
   * and the last one dangerously so.
   */
  await expect(page.getByText(/one tax order of payment/i)).toHaveCount(0)
  await expect(page.getByText(/nothing else is charged/i)).toHaveCount(0)
  /*
   * It said "once that payment clears" until the gate moved off the money.
   * Submitting is what opens the six now, so promising the applicant that a
   * payment opens them would be the same kind of false clause as the two
   * asserted absent above — and this one would send them to a Pay screen
   * looking for a door that is already open.
   */
  await expect(page.getByText(/once that payment clears/i)).toHaveCount(0)
  await expect(page.getByText(/opens the six LGU clearances/i)).toBeVisible()
  await expect(page.getByText(/balance reaches zero/i)).toBeVisible()

  await page.getByRole('button', { name: /^submit & pay$/i }).click()
  await page.getByRole('button', { name: /^proceed$/i }).click()
  await expect(page.getByText(/tracking/i).first()).toBeVisible({ timeout: 30_000 })

  const read = async () =>
    page.evaluate(async (id) => {
      const token = localStorage.getItem('biztrack.token.public')
      const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` }
      const app = (await (await fetch(`/api/v1/applications/${id}`, { headers })).json()).data
      const fee = (await (await fetch(`/api/v1/applications/${id}/fee`, { headers })).json()).data
      return {
        status: app.status as string,
        permitCodes: (app.permit_types ?? []).map((pt: { code: string }) => pt.code) as string[],
        labels: (fee.line_items as { label: string }[])
          .map((l) => l.label.toLowerCase())
          .join(' | '),
        total: Number(fee.total_amount),
      }
    }, appId)

  const atSubmit = await read()
  /*
   * NOT `pending_payment` any more, and that is the point of the button.
   *
   * "Submit & Pay" raises the Tax Order of Payment and settles it in one press,
   * so the filing never rests in the unpaid state the applicant used to have to
   * navigate to a second screen to clear. The unpaid middle still exists in the
   * data model and is still asserted — flow-lifecycle submits via the API
   * precisely so it can observe it — but it is no longer a place the wizard
   * leaves anyone standing.
   */
  expect(atSubmit.status, 'Submit & Pay left the filing awaiting payment').not.toBe(
    'pending_payment',
  )
  // The business permit, alone. This is the assertion the old test made the
  // exact opposite of.
  expect(atSubmit.permitCodes, 'a clearance reached the filing before payment').toEqual(['BUSINESS'])
  expect(atSubmit.labels, 'the business permit is not on the Tax Order of Payment').toContain(
    'business permit',
  )
  expect(
    atSubmit.labels,
    'a clearance was billed on the business permit’s Tax Order of Payment',
  ).not.toContain('fire safety inspection certificate fee')
  expect(atSubmit.total).toBeGreaterThan(0)
  /*
   * Settled by the one press. The receipt is on the screen the applicant is
   * standing on, which is what makes this a payment rather than a silent
   * charge — DESIGN.md's rule that money is never moved without showing it.
   */
  await expect(page.getByText(/submitted and paid/i)).toBeVisible()
  await expect(page.getByText(/paid ₱/i)).toBeVisible()

  /* ── 2. Open on submission, with the bill still outstanding ────────────── */

  /*
   * This step asserted the opposite until 2026-09-02: the stage was shut here
   * and only the first cleared payment opened it. Payment in this build is a
   * dummy, so nothing ever cleared it — the six were unreachable and testers
   * twice reported them as missing outright.
   *
   * What the billing half of this test proves is untouched by that: the Tax
   * Order of Payment at submission still covers the business permit ALONE
   * (asserted just above), and each clearance still accrues its own fee when
   * applied for (asserted below). The money still works the same way; it just
   * no longer bars the door.
   */
  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })
  const cards = clearanceCards(page)
  await expect(cards).toHaveCount(6, { timeout: 30_000 })
  await expect(
    page.locator('#clearances-locked'),
    'the stage stayed shut on a submitted filing',
  ).toHaveCount(0)
  // The bill was real before it was settled — otherwise the accrual asserted
  // below would be measuring movement from nothing.
  expect(atSubmit.total).toBeGreaterThan(0)

  /*
   * There is no separate "now go and pay" step here any more.
   *
   * It navigated to /pay and pressed Pay Online, which is exactly the walk the
   * client asked to be removed — and it now fails outright, because Submit &
   * Pay already settled the bill and the Pay screen correctly refuses a filing
   * that owes nothing. The screen still exists for a filing that arrives
   * unpaid by some other route; flow-lifecycle drives it there.
   */

  /* ── 3. Applying accrues ───────────────────────────────────────────────── */

  const fire = cards.filter({ hasText: /fire/i })
  // Quoted before the press, which is the whole reason the amount is back on
  // the card: there is no later screen on which to discover it.
  await expect(fire).toContainText(/fee ₱/i)

  await fire.getByRole('button', { name: /^apply for the/i }).click()
  // Apply opens that office's sheet — over the cards now, not as a wizard step.
  const backToCards = page.getByRole('button', { name: /save & back to clearances/i })
  await expect(backToCards, 'Apply did not open the office sheet').toBeVisible()
  await backToCards.click()
  await expect(cards.first()).toBeVisible()

  const afterApply = await read()
  expect(afterApply.permitCodes, 'applying did not attach the clearance').toContain('FSIC')
  expect(afterApply.labels, 'applying for the fire clearance was not billed').toContain(
    'fire safety inspection certificate fee',
  )
  /*
   * The accrual, stated as a comparison rather than an absolute. The point is
   * not what the fire fee happens to be — it is derived from this filing's own
   * mayor's permit — but that the bill GREW after a payment that was supposed
   * to have settled it. That growth is what the balance block on the screen is
   * for, and what the release gate holds the permit against.
   */
  expect(
    afterApply.total,
    'the assessment did not grow when a clearance was applied for',
  ).toBeGreaterThan(atSubmit.total)
})


/*
 * ── The withdraw test was here, and its subject no longer exists ───────────
 *
 * "a clearance applied for by mistake can be withdrawn, and its fee comes back
 * off" pressed Apply on the Market Clearance, asserted the sheet named its own
 * way out, withdrew it, and checked the fee left the balance. Two separate
 * changes retired it on 6 September 2026:
 *
 *  - the Market Clearance and its office were removed from the system, and it
 *    was chosen for this test precisely because it was the likeliest accidental
 *    Apply — the one with two required answers that the client had objected to
 *    being offered universally.
 *  - the remaining five permits are REQUIRED on every application, so there is
 *    no such thing as applying for one by mistake and nothing to withdraw.
 *    `ClearanceService::unapply` now refuses a required permit outright.
 *
 * What replaces it asserts the new rule rather than deleting the coverage: a
 * required permit cannot be taken back off. If an optional permit is ever added
 * again, the withdrawal path and its fee assertion come back with it — the
 * original is in git history at 5f7a0b1~1.
 */
test('a required permit cannot be withdrawn from the application', async ({ page }) => {
  await page.goto('/dashboard')
  const appId = await makePaidApplication(page)

  const refusal = await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const res = await fetch(`/api/v1/applications/${id}/clearances/SANITARY/apply`, {
      method: 'DELETE',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
    return { status: res.status, body: await res.text() }
  }, appId)

  expect(refusal.status, 'a required permit was allowed to be withdrawn').toBe(422)
  expect(refusal.body).toMatch(/required on every application/i)

  // And the card still stands, with no control offering the withdrawal.
  await page.goto(`/applications/${appId}/clearances`)
  const cards = clearanceCards(page)
  await expect(cards).toHaveCount(5, { timeout: 30_000 })
  await expect(page.getByRole('button', { name: /^withdraw your application for the/i })).toHaveCount(
    0,
  )
})
