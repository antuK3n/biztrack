import { expect, test, type Locator, type Page } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * ITEM 110 — "For the renewal, it should ask first (in modal) the permit ID so
 * the system will know which specific permit to renew."
 *
 * The question used to sit on part 3 of the wizard, after consent, after the
 * map, and after the form had already prefilled itself from a business whose
 * permit it had not been told. This file pins the properties that made moving
 * it worth doing, and the ones that could quietly undo it:
 *
 *   - the dialog is there, first, and asks business then permits;
 *   - ONE renewal can cover SEVERAL permits, and the first tick is the primary;
 *   - two permits of the same type can be told apart;
 *   - a business with no BizTrack permit is NOT trapped (year one is almost
 *     entirely renewals of permits issued on paper);
 *   - a reopened draft that already named its permit is not asked again;
 *   - a draft that never named one, and could have, IS asked again;
 *   - the answer can be changed, and backing out of that change keeps it;
 *   - Continue is never `disabled` — it says why instead.
 *
 * None of these is visible to `tsc` or to the API suite. "The dialog opened
 * over the wizard" and "the button was reachable but explained itself" are
 * browser facts.
 */

test.use({ storageState: sessionFor('owner') })

/*
 * The seeded owner's one business with permits in the register — and it holds
 * TWO, both Mayor's / Business Permits, differing only by number and validity.
 * That is not a convenience, it is the case the item exists for: "which
 * permit" is a real question precisely because the type does not answer it.
 */
const TWO_PERMIT_BUSINESS_ID = 1
const BUSINESS_PERMIT_TYPE_ID = 1

/*
 * Plural, because a renewal covers a set and not a single permit.
 *
 * A shop renewing its Mayor's Permit, its Sanitary Permit and its FSIC makes
 * one visit to the counter and files once; a dialog that could take only one of
 * the three left the other two with no filing for their office to attach a
 * review to. The heading is the first place that change has to be visible —
 * asking "which permit" and then accepting three is a question that lies about
 * its own answer.
 */
const DIALOG = /which permits are you renewing/i

function dialog(page: Page) {
  return page.getByRole('dialog', { name: DIALOG })
}

function businessSelect(page: Page) {
  return dialog(page).getByLabel(/which business are you renewing/i)
}

/**
 * Every row in the dialog's picker — the permits AND the escape.
 *
 * A LIST of checkboxes, not a radiogroup: the answer is a set. Located as the
 * `<li>` rather than as the `<input>` inside it because the assertions below
 * are about what the row SAYS — a permit number, its type, both ends of its
 * validity — and an input carries none of that text itself.
 */
function pickerRows(page: Page) {
  return dialog(page).getByRole('list', { name: DIALOG }).locator('li')
}

/**
 * The permit rows alone.
 *
 * The escape ("none of these — my permit was issued on paper") is a checkbox in
 * the same list, because it answers the same question. It used to be a
 * paragraph shown only when the list came back empty, which meant a business
 * with no BizTrack permit was never ASKED — and an unasked question and a
 * declined one both reached the register as a null nobody could tell apart.
 * Seven renewals of nothing later, it is an option.
 */
function permitRows(page: Page) {
  return pickerRows(page).filter({ hasNotText: /issued on paper|no permit issued through/i })
}

/** The escape row, whether the list it sits under is empty or not. */
function paperPermitRow(page: Page) {
  return pickerRows(page).filter({ hasText: /issued on paper|no permit issued through/i })
}

/** The tick inside a picker row. */
function tickOf(row: Locator) {
  return row.getByRole('checkbox')
}

/**
 * Choose a business, having first checked it is on offer at all.
 *
 * `selectOption` on an absent option only reports "did not find some options"
 * after a fifteen-second timeout, which is how a real bug read as a flake once:
 * the list behind this select is paged, and when the tester's register grew past
 * one page the seeded business stopped being offered — not hidden below a
 * scroll, absent. `businesses.list()` asks for the picker ceiling now, and this
 * says so out loud if that ever comes undone.
 */
async function chooseBusiness(page: Page, id: number) {
  const option = businessSelect(page).locator(`option[value="${id}"]`)
  await expect(option, `business ${id} is not offered — is the list paged again?`).toHaveCount(1)
  await businessSelect(page).selectOption({ value: String(id) })
}

/**
 * Call the API as the app does, through the session already in the page.
 *
 * Used to BUILD fixtures — a draft that already names its permit cannot be
 * produced by driving the wizard without first driving the very dialog under
 * test, which would make the fixture depend on the thing it is fixing.
 */
async function api<T>(page: Page, method: 'GET' | 'POST', path: string, body?: unknown): Promise<T> {
  return page.evaluate(
    async ([m, p, b]) => {
      const token = localStorage.getItem('biztrack.token.public')
      const res = await fetch(`/api/v1${p}`, {
        method: m as string,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: b === null ? undefined : JSON.stringify(b),
      })
      if (!res.ok) throw new Error(`${m} ${p} -> ${res.status} ${await res.text()}`)
      return (await res.json()).data
    },
    [method, path, body ?? null] as const,
  ) as Promise<T>
}

interface SeededPermit {
  id: number
  permit_number: string
}

async function renewablePermits(page: Page, businessId: number): Promise<SeededPermit[]> {
  const result = await api<{ renewable_permits: SeededPermit[] }>(
    page,
    'GET',
    `/businesses/${businessId}/prefill?type=renewal`,
  )
  return result.renewable_permits ?? []
}

/**
 * Business Information on a renewal, without walking the whole wizard.
 *
 * A renewal's running order is not the fixed seven phases — section A1 of
 * MCG-BPLO-FO-002 decides how much of the form exists. Unanswered it is two
 * parts (Data Privacy, Changes Since Last Permit) and Business Information is
 * not in the sequence at all, so there is nothing to jump to. Answering Yes and
 * ticking Ownership is what puts it there, at part 3 of 7 — which is why this
 * has to drive those two answers rather than clicking a chip on the section map
 * that a renewal has not earned yet.
 *
 * The consent tick is deliberately never restored on a reopen (RA 10173:
 * consent is given, not remembered), so it is taken here every time.
 */
async function openBusinessStep(page: Page) {
  await page.getByRole('checkbox').first().check()
  await page.getByRole('button', { name: 'Next' }).click()

  await expect(page.getByText(/part 2 of/i).first()).toBeVisible({ timeout: 20_000 })
  await page.getByRole('button', { name: 'Yes', exact: true }).click()
  // Ownership is the tick that opens Business Information; the box says so.
  await page.getByRole('checkbox', { name: /^Ownership/ }).check()

  await page.getByRole('button', { name: 'Next' }).click()
  await expect(page.getByText(/part 3 of/i).first()).toBeVisible({ timeout: 20_000 })
}

/** A renewal draft on the two-permit business, optionally already naming one. */
async function seedDraft(page: Page, priorPermitId?: number): Promise<number> {
  return seedDraftOn(
    page,
    TWO_PERMIT_BUSINESS_ID,
    priorPermitId ? { prior_permit_id: priorPermitId } : {},
  )
}

/** The same, on any business, carrying whatever prior-permit answer is wanted. */
async function seedDraftOn(
  page: Page,
  businessId: number,
  answer: {
    prior_permit_id?: number
    prior_permit_ids?: number[]
    prior_permit_declared_none?: boolean
  } = {},
): Promise<number> {
  const draft = await api<{ id: number }>(page, 'POST', '/applications', {
    business_id: businessId,
    application_type: 'renewal',
    permit_type_ids: [BUSINESS_PERMIT_TYPE_ID],
    title: `E2E item 110 — ${Object.keys(answer)[0] ?? 'unanswered'} ${Date.now()}`,
    ...answer,
  })
  return draft.id
}

test('a renewal is asked which permits before the wizard opens', async ({ page }) => {
  await page.goto('/apply?type=renewal')

  /*
   * The dialog, not the wizard. `role="dialog"` with an accessible name is
   * what makes a screen reader announce this as an interruption rather than as
   * more page, and `aria-modal` is what stops it reading the wizard behind.
   */
  const modal = dialog(page)
  await expect(modal).toBeVisible({ timeout: 30_000 })
  await expect(modal).toHaveAttribute('aria-modal', 'true')

  /*
   * Business first, permits second. Which business you are renewing decides
   * which permits there are to choose between, so asking them the other way
   * round would be asking a question whose options do not exist yet.
   */
  await expect(businessSelect(page)).toBeVisible()
  // Nothing to pick between yet — not even the escape, which is an answer
  // about a business and cannot be given before one is chosen.
  await expect(pickerRows(page)).toHaveCount(0)

  // And focus is inside the dialog, not left on the page underneath it.
  const focusInside = await modal.evaluate((el) => el.contains(document.activeElement))
  expect(focusInside, 'focus was left behind the dialog').toBe(true)
})

test('two permits of the same type are told apart by number and dates', async ({ page }) => {
  /*
   * A shop renewing late holds last year's Mayor's Permit and this year's:
   * same type, same business, same name. A list showing only the type would be
   * asking the applicant to choose between two identical rows.
   */
  await page.goto('/apply?type=renewal')
  await expect(dialog(page)).toBeVisible({ timeout: 30_000 })

  await chooseBusiness(page, TWO_PERMIT_BUSINESS_ID)

  const rows = permitRows(page)
  await expect(rows).toHaveCount(2, { timeout: 20_000 })

  const texts = await rows.allInnerTexts()
  for (const text of texts) {
    // A permit number, and BOTH ends of the validity it was issued for.
    expect(text, 'a permit row with no permit number').toMatch(/[A-Z]{2,}-\d{4}-\d+/)
    expect(text, 'a permit row with no validity dates').toMatch(
      /\w{3}\s+\d{1,2},\s+\d{4}\s+–\s+\w{3}\s+\d{1,2},\s+\d{4}/,
    )
  }
  // They differ. If two rows ever read the same, the question is unanswerable.
  expect(texts[0]).not.toEqual(texts[1])
})

test('a renewal covers every permit ticked, and the first tick is the primary', async ({ page }) => {
  /*
   * The rule the whole dialog was reshaped around. A business renews the set of
   * permits it holds in one filing, so the picker is checkboxes and not a
   * radiogroup — and the ORDER of the ticks is part of the answer, because the
   * renewal chain is keyed on one permit and somebody has to say which.
   *
   * The chosen convention is "the first one you tick", which is why the second
   * permit is ticked FIRST here: if the primary were merely the topmost row, or
   * the lowest id, this would pass while meaning nothing.
   */
  await page.goto('/apply?type=renewal')
  await expect(dialog(page)).toBeVisible({ timeout: 30_000 })

  const permits = await renewablePermits(page, TWO_PERMIT_BUSINESS_ID)
  expect(permits.length, 'the two-permit fixture has drifted').toBeGreaterThan(1)

  await chooseBusiness(page, TWO_PERMIT_BUSINESS_ID)
  await expect(permitRows(page)).toHaveCount(2, { timeout: 20_000 })

  const second = permitRows(page).filter({ hasText: permits[1].permit_number })
  const first = permitRows(page).filter({ hasText: permits[0].permit_number })

  await tickOf(second).check()
  await tickOf(first).check()
  // Both, at once. A radiogroup could not hold this state at all.
  await expect(tickOf(second)).toBeChecked()
  await expect(tickOf(first)).toBeChecked()

  await dialog(page).getByRole('button', { name: /continue/i }).click()
  await expect(dialog(page)).toBeHidden({ timeout: 20_000 })

  // The primary is what Business Information prints back, and it is the permit
  // ticked first — not the first row, which is the other one.
  await openBusinessStep(page)
  await expect(page.getByText(permits[1].permit_number)).toBeVisible()
})

test('Continue is never disabled — it says what is still missing', async ({ page }) => {
  /*
   * WCAG 3.3.1 / 3.3.3. A disabled Continue is skipped by the tab order, so the
   * one control that would explain the hold-up is the one a keyboard user
   * never reaches, and a sighted user gets a grey button and no reason. The
   * button stays pressable and points at the sentence naming the gap.
   */
  await page.goto('/apply?type=renewal')
  const modal = dialog(page)
  await expect(modal).toBeVisible({ timeout: 30_000 })

  const proceed = modal.getByRole('button', { name: /continue/i })
  await expect(proceed).toBeEnabled()

  /*
   * The reason is described on the button BEFORE it is pressed, so tabbing
   * onto it reads out the hold-up instead of announcing a dead end. The id
   * comes from React's useId and is not a valid CSS selector, hence the
   * attribute match rather than `#id`.
   */
  const first = await proceed.getAttribute('aria-describedby')
  expect(first, 'Continue does not point at a reason').toBeTruthy()
  await expect(page.locator(`[id="${first}"]`)).toHaveText(/choose the business/i)

  // Pressing it does not proceed, and now shows what it was already saying.
  await proceed.click()
  await expect(modal).toBeVisible()
  await expect(page.locator(`[id="${first}"]`)).toBeVisible()

  // With a business chosen, the reason moves on to the permits — and asks for
  // EVERY one this filing covers, because one tick is no longer the whole
  // answer. Both ways out are named, because both are answers.
  await chooseBusiness(page, TWO_PERMIT_BUSINESS_ID)
  await expect(permitRows(page)).toHaveCount(2, { timeout: 20_000 })
  const second = await proceed.getAttribute('aria-describedby')
  expect(second).toBeTruthy()
  await expect(page.locator(`[id="${second}"]`)).toHaveText(
    /tick every permit you are renewing.*none issued through biztrack/i,
  )

  // Answered, there is nothing left to describe. One tick is enough to answer
  // the question even though more are allowed — "which permits" is satisfied
  // by a set of one, and demanding a second would invent a rule.
  await tickOf(permitRows(page).first()).check()
  await expect(proceed).not.toHaveAttribute('aria-describedby', /.+/)
})

test('a business whose permits are on paper is not trapped, but must say so', async ({ page }) => {
  /*
   * The escape, and why it is not optional: in year one almost every renewal
   * is of a permit the old counter process issued on paper, so "not in the
   * register" is the ordinary case. A dialog that insisted on a permit id
   * would shut the renewal flow to most of the city.
   *
   * What has changed is that it must be TAKEN. It used to be the absence of a
   * requirement — where the list came back empty the question was not asked,
   * and Continue went straight through carrying a null the applicant had never
   * been shown. That null is indistinguishable afterwards from a question
   * somebody skipped, and five of the seven renewals-of-nothing in the
   * register are exactly this: businesses holding no permit, never asked.
   *
   * One click is not a burden. It is the difference between an answer and a
   * gap, and it is the only thing that lets submit refuse the gap without also
   * refusing the escape.
   */
  await page.goto('/apply?type=renewal')
  const modal = dialog(page)
  await expect(modal).toBeVisible({ timeout: 30_000 })

  const paperOnly = await businessWithoutPermits(page)
  await businessSelect(page).selectOption({ value: paperOnly })

  const escape = paperPermitRow(page)
  await expect(escape).toHaveCount(1, { timeout: 20_000 })
  await expect(escape).toHaveText(/no permit issued through biztrack/i)
  // The way through is stated, not merely implied by the absence of a list.
  await expect(escape).toHaveText(/upload your paper permit under documentary requirements/i)
  // And it is the only row, because there is nothing else to name.
  await expect(permitRows(page)).toHaveCount(0)

  const proceed = modal.getByRole('button', { name: /continue/i })
  // Unticked, the dialog still holds — and says which tick it is waiting for.
  const reason = await proceed.getAttribute('aria-describedby')
  expect(reason, 'Continue does not point at a reason').toBeTruthy()
  await expect(page.locator(`[id="${reason}"]`)).toHaveText(/no permit issued through biztrack/i)
  await proceed.click()
  await expect(modal).toBeVisible()

  await tickOf(escape).check()
  await expect(tickOf(escape)).toBeChecked()
  await expect(proceed).not.toHaveAttribute('aria-describedby', /.+/)
  await proceed.click()

  // The wizard opens with the dialog gone — that is the escape working.
  await expect(modal).toBeHidden({ timeout: 20_000 })
  await expect(page.getByText(/part 1 of/i).first()).toBeVisible()
})

test('naming permits and declaring there are none are exclusive', async ({ page }) => {
  /*
   * The one exclusivity that survives the move to checkboxes.
   *
   * The permits are no longer exclusive with EACH OTHER — a filing covers as
   * many as the applicant ticks. But "none of these" and "these two" are
   * contradictory statements about the same filing, so ticking either clears
   * the other. The server resolves it the same way, with the named permits
   * winning, so a row can never assert both and slip the gate.
   */
  await page.goto('/apply?type=renewal')
  await expect(dialog(page)).toBeVisible({ timeout: 30_000 })

  await chooseBusiness(page, TWO_PERMIT_BUSINESS_ID)
  await expect(permitRows(page)).toHaveCount(2, { timeout: 20_000 })

  // Two permits at once, which is the state the escape has to clear.
  await tickOf(permitRows(page).nth(0)).check()
  await tickOf(permitRows(page).nth(1)).check()

  await tickOf(paperPermitRow(page)).check()
  await expect(tickOf(paperPermitRow(page))).toBeChecked()
  await expect(tickOf(permitRows(page).nth(0))).not.toBeChecked()
  await expect(tickOf(permitRows(page).nth(1))).not.toBeChecked()

  await tickOf(permitRows(page).first()).check()
  await expect(tickOf(permitRows(page).first())).toBeChecked()
  await expect(tickOf(paperPermitRow(page))).not.toBeChecked()
})

test('a reopened draft that already names its permit is not asked again', async ({ page }) => {
  /*
   * The dialog answers a question ONCE. Putting it in front of every reopen
   * would be the wizard forgetting, every time, a decision the applicant had
   * already made — and charging them the price of making it again to get back
   * into their own draft.
   */
  await page.goto('/apply')
  await expect(page.getByText(/data privacy/i).first()).toBeVisible({ timeout: 30_000 })

  const permits = await renewablePermits(page, TWO_PERMIT_BUSINESS_ID)
  expect(permits.length, 'the two-permit fixture has drifted').toBeGreaterThan(1)
  const draftId = await seedDraft(page, permits[0].id)

  await page.goto(`/apply?draft=${draftId}`)
  await expect(page.getByText(/part 1 of/i).first()).toBeVisible({ timeout: 30_000 })
  /*
   * Not a race with the dialog's own mount: the reopen waits for the
   * prior-permit read before it paints, and that read is what decides. If this
   * ever flakes, the decision has been moved back after the first paint.
   */
  await expect(dialog(page)).toHaveCount(0)

  /*
   * And the answer it was not asked for is on the screen where it was made. A
   * draft that kept the permit but stopped SAYING which one would pass the
   * check above while costing the applicant the same information.
   */
  await openBusinessStep(page)
  await expect(page.getByText(permits[0].permit_number)).toBeVisible()
})

test('a reopened draft covering several permits keeps the whole set', async ({ page }) => {
  /*
   * `prior_permit_ids` is a pivot table, and a pivot table is exactly the kind
   * of thing a reopen can drop on the floor: the singular `prior_permit_id` is
   * still there, still the primary, and a hydration that read only it would
   * look completely correct on this screen while the next autosave wrote an
   * empty pivot over a real answer — silently unrenewing the other permits.
   *
   * So the set is seeded plural and the primary is asserted to be the FIRST of
   * it, which is the same rule the dialog's tick order encodes.
   */
  await page.goto('/apply')
  await expect(page.getByText(/data privacy/i).first()).toBeVisible({ timeout: 30_000 })

  const permits = await renewablePermits(page, TWO_PERMIT_BUSINESS_ID)
  expect(permits.length, 'the two-permit fixture has drifted').toBeGreaterThan(1)
  const draftId = await seedDraftOn(page, TWO_PERMIT_BUSINESS_ID, {
    prior_permit_ids: [permits[1].id, permits[0].id],
  })

  await page.goto(`/apply?draft=${draftId}`)
  await expect(page.getByText(/part 1 of/i).first()).toBeVisible({ timeout: 30_000 })
  await expect(dialog(page)).toHaveCount(0)

  await openBusinessStep(page)
  await expect(page.getByText(permits[1].permit_number)).toBeVisible()

  // And reopening the dialog to look shows BOTH still ticked, which is the
  // half a primary-only hydration would lose.
  await page.getByRole('button', { name: /^change$/i }).click()
  await expect(dialog(page)).toBeVisible()
  await expect(tickOf(permitRows(page).filter({ hasText: permits[0].permit_number }))).toBeChecked()
  await expect(tickOf(permitRows(page).filter({ hasText: permits[1].permit_number }))).toBeChecked()
})

test('a draft that never named a permit, and could have, is asked again', async ({ page }) => {
  /*
   * The other half. A renewal draft with no prior permit against a business
   * that HAS permits is exactly the draft `canCreateDraft` refuses to save —
   * so it is stuck, and reopening it without the dialog would show a form that
   * silently declines to save and never says why.
   */
  await page.goto('/apply')
  await expect(page.getByText(/data privacy/i).first()).toBeVisible({ timeout: 30_000 })

  const draftId = await seedDraft(page)

  await page.goto(`/apply?draft=${draftId}`)
  await expect(dialog(page)).toBeVisible({ timeout: 30_000 })
  // Opened on the business it already knows, so only the missing half is asked.
  await expect(permitRows(page)).toHaveCount(2, { timeout: 20_000 })
})

test('a draft on a business with no permits is asked too', async ({ page }) => {
  /*
   * The five. Every one of the paper-permit renewals in the register that
   * carries no prior permit is a draft on a business holding nothing — and the
   * reopen used to wave those straight through, on the reasoning that a
   * business with an empty list had nothing to be asked. An empty list is WHY
   * the question is asked; it is not a reason to skip it.
   */
  await page.goto('/apply')
  await expect(page.getByText(/data privacy/i).first()).toBeVisible({ timeout: 30_000 })

  const paperOnly = Number(await businessWithoutPermitsById(page))
  const draftId = await seedDraftOn(page, paperOnly)

  await page.goto(`/apply?draft=${draftId}`)
  await expect(dialog(page)).toBeVisible({ timeout: 30_000 })
  await expect(paperPermitRow(page)).toHaveCount(1, { timeout: 20_000 })
})

test('a draft that already declared it has no BizTrack permit is not asked again', async ({
  page,
}) => {
  /*
   * The other side of the same coin. Having taken the escape once, the
   * applicant must not meet it again on every reopen — that would be the
   * wizard forgetting an answer, which is the failure the dialog was moved to
   * the front to avoid. This is what proves the declaration round-trips rather
   * than collapsing back into an ordinary null on the way to the server.
   */
  await page.goto('/apply')
  await expect(page.getByText(/data privacy/i).first()).toBeVisible({ timeout: 30_000 })

  const paperOnly = Number(await businessWithoutPermitsById(page))
  const draftId = await seedDraftOn(page, paperOnly, { prior_permit_declared_none: true })

  await page.goto(`/apply?draft=${draftId}`)
  await expect(page.getByText(/part 1 of/i).first()).toBeVisible({ timeout: 30_000 })
  await expect(dialog(page)).toHaveCount(0)

  // And it says so where the answer lives, rather than leaving a blank the
  // applicant would read as an unanswered question.
  await openBusinessStep(page)
  await expect(page.getByText(/no biztrack permit/i)).toBeVisible()
})

test('a wrong permit can be corrected, and backing out keeps the old answer', async ({ page }) => {
  /*
   * Two permits a year apart look alike in a hurry, so picking the wrong one is
   * the ordinary mistake — and before this there was no way back into the
   * question except abandoning the draft. Change reopens the dialog; Cancel
   * from Change puts back what was there, so pressing it to LOOK costs nothing.
   *
   * Correcting is untick-then-tick now rather than one click, and that is not
   * an accident of the checkboxes: with a set, ticking the other permit ADDS
   * it, which is a different statement from replacing it. Unticking the wrong
   * one is how you say it was wrong.
   */
  await page.goto('/apply')
  await expect(page.getByText(/data privacy/i).first()).toBeVisible({ timeout: 30_000 })

  const permits = await renewablePermits(page, TWO_PERMIT_BUSINESS_ID)
  expect(permits.length, 'the two-permit fixture has drifted').toBeGreaterThan(1)
  const draftId = await seedDraft(page, permits[0].id)

  await page.goto(`/apply?draft=${draftId}`)
  await expect(page.getByText(/part 1 of/i).first()).toBeVisible({ timeout: 30_000 })
  await openBusinessStep(page)
  await expect(page.getByText(permits[0].permit_number)).toBeVisible()

  const wrong = () => permitRows(page).filter({ hasText: permits[0].permit_number })
  const right = () => permitRows(page).filter({ hasText: permits[1].permit_number })

  // Reopen, swap the answer, then back out of it.
  await page.getByRole('button', { name: /^change$/i }).click()
  await expect(dialog(page)).toBeVisible()
  await tickOf(wrong()).uncheck()
  await tickOf(right()).check()
  await dialog(page).getByRole('button', { name: /keep what i had/i }).click()
  await expect(dialog(page)).toBeHidden()
  await expect(page.getByText(permits[0].permit_number)).toBeVisible()

  // Reopen and commit the change this time.
  await page.getByRole('button', { name: /^change$/i }).click()
  await expect(dialog(page)).toBeVisible()
  // Cancel really did keep what was there — the dialog reopens on the old
  // answer, not on the abandoned one.
  await expect(tickOf(wrong())).toBeChecked()
  await tickOf(wrong()).uncheck()
  await tickOf(right()).check()
  await dialog(page).getByRole('button', { name: /continue/i }).click()
  await expect(dialog(page)).toBeHidden({ timeout: 20_000 })
  await expect(page.getByText(permits[1].permit_number)).toBeVisible()
})

/* ── Fixtures read off the register, not hard-coded ───────────────────────── */

/**
 * A business the register holds no permit for — the paper-permit case.
 *
 * Discovered rather than named: the owner's businesses are seeded and then
 * edited by hand on this stack, so pinning one by id or by index makes a test
 * that breaks the next time somebody registers a shop.
 *
 * This version asks the register directly. The one below reads the options out
 * of the open dialog, which is exactly right for the test that is already
 * looking at one; the reopen tests are not, because they seed a draft first and
 * then navigate, so there is no dialog to read.
 */
async function businessWithoutPermitsById(page: Page): Promise<string> {
  const owned = await api<{ id: number }[]>(page, 'GET', '/businesses?per_page=200')
  for (const business of owned) {
    if ((await renewablePermits(page, business.id)).length === 0) return String(business.id)
  }
  throw new Error('every seeded business holds a permit — the paper-permit case is untestable here')
}

async function businessWithoutPermits(page: Page): Promise<string> {
  const options = await businessSelect(page)
    .locator('option')
    .evaluateAll((els) =>
      els.map((el) => (el as HTMLOptionElement).value).filter((value) => value !== ''),
    )
  for (const value of options) {
    if ((await renewablePermits(page, Number(value))).length === 0) return value
  }
  throw new Error('every seeded business holds a permit — the paper-permit case is untestable here')
}
