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
    const token = localStorage.getItem('biztrack.token')
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
    const token = localStorage.getItem('biztrack.token')
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
      const token = localStorage.getItem('biztrack.token')
      await fetch(`/api/v1/applications/${appId}/clearances/${code}/apply`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
    },
    { appId, code },
  )
}

/** Any application of the tester's whose clearance stage has already shut. */
async function findShut(page: Page): Promise<number | null> {
  return page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token')
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

test('a draft can still choose its clearances, and the cards say what each costs', async ({
  page,
}) => {
  await page.goto('/dashboard')
  const appId = await makeDraft(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const cards = page.locator('ul > li').filter({ hasText: /apply/i })
  await expect(cards).toHaveCount(6)

  // Nothing is locked, so no reason is shown: before submission there is
  // nothing to explain.
  await expect(page.locator('#clearances-locked')).toHaveCount(0)

  /*
   * The consequence, on the card, before either button is pressed. Apply and
   * Submit sit side by side and look alike; one of them spends money.
   */
  await expect(cards.first()).toContainText(/tax order of payment|assessment/i)

  const apply = cards.first().getByRole('button', { name: /^apply$/i })
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
   * Visible, not hidden. The six cards are the point of showing a shut stage at
   * all — this is the record of what the filing asked for.
   */
  const cards = page.locator('ul > li').filter({ hasText: /apply/i })
  await expect(cards).toHaveCount(6)

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
    const token = localStorage.getItem('biztrack.token')
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
  const apply = cards.first().getByRole('button', { name: /^apply$/i })
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
  // Under the old toggle this click un-applied it and opened nothing.
  await expect(card).toContainText(/applied for/i)

  const backToCards = page.getByRole('button', { name: /save & back to clearances/i })

  await card.getByRole('button', { name: /^apply$/i }).click()
  await expect(backToCards, 'Apply did not open the office form').toBeVisible()

  await page.getByRole('button', { name: /back without saving/i }).click()
  await expect(backToCards).toBeHidden()

  // Still applied for: the click opened a form, it did not undo anything.
  await expect(card).toContainText(/applied for/i)

  // And again. A toggle would open nothing the second time.
  await card.getByRole('button', { name: /^apply$/i }).click()
  await expect(backToCards, 'a second Apply did not open the office form').toBeVisible()
  await page.getByRole('button', { name: /back without saving/i }).click()
  await expect(card).toContainText(/applied for/i)
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

test('un-applying has its own labelled control, apart from Apply', async ({ page }) => {
  await page.goto('/dashboard')
  const appId = await makeDraft(page)
  await applyFor(page, appId, 'SANITARY')

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const card = page.locator('ul > li').filter({ hasText: /sanitary/i })

  /*
   * Destroying something must never be the alternate meaning of the button
   * that created it. The withdraw control names the clearance it withdraws, so
   * a screen reader moving button to button is told which of six it is on.
   */
  const withdraw = card.getByRole('button', { name: new RegExp(`don’t apply for the`, 'i') })
  await expect(withdraw).toBeVisible()
  await expect(withdraw).toContainText(/sanitary/i)

  // Three distinct controls, not two doing four jobs between them.
  await expect(card.getByRole('button', { name: /^apply$/i })).toBeVisible()
  await expect(card.getByRole('button', { name: /submit a copy/i })).toBeVisible()
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
   * ZONING has no office sheet, so Apply here does not navigate away from the
   * cards and the announcement is the only thing to observe.
   */
  const card = page.locator('ul > li').filter({ hasText: /zoning/i })
  await card.getByRole('button', { name: /^apply$/i }).click()

  const status = page.getByRole('status').filter({ hasText: /applied for your/i })
  await expect(status).toBeVisible()
  await expect(status).toContainText(/tax order of payment/i)
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

  const cards = page.locator('ul > li').filter({ hasText: /apply/i })
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
  await cards.filter({ hasText: /fire/i }).getByRole('button', { name: /^apply$/i }).click()

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
    const token = localStorage.getItem('biztrack.token')
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
