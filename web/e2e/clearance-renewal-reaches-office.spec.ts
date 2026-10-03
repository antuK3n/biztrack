import { expect, test, type Page } from '@playwright/test'
import { sessionFor, WIZARD_PAINT_MS } from './helpers'

/*
 * A clearance-only renewal turns up in its office's queue.
 *
 * ── What this is for ────────────────────────────────────────────────────────
 *
 * The client submitted a Sanitary Permit renewal and the City Health Office
 * queue stayed empty (4 October 2026). The filing sat at `for_approval` with
 * its SANITARY pivot `not_started`, no mode and no assignment: nothing had
 * ever been routed, so no office could see it and the applicant was waiting
 * on a desk that had never been told.
 *
 * `WorkflowService::submit` said as much in its own remark — *"Apply for the
 * permit below and its office will review it"* — which describes the
 * CLEARANCE STAGE, where the applicant presses Apply per permit. A
 * clearance-only renewal never goes there; its office sheet is a step of the
 * wizard, finished before Submit.
 *
 * ── Why a browser test and not only the API one ─────────────────────────────
 *
 * `ClearanceRenewalReachesItsOfficeTest` drives `submit()` directly and
 * asserts the assignment row, which is the mechanism. This asserts the thing
 * the client actually reported: that the filing APPEARS, on the screen the
 * officer opens, after a human walked the wizard. The two bugs behind the
 * empty queue were on opposite sides of the wire — one in the transition, one
 * in the wizard's own gate — and only a test that crosses it would have
 * caught both.
 *
 * Sanitary is the case reported and the one with no blocking documents, so
 * the walk is short. FSIC, Occupancy and Zoning all require attachments
 * before their sheet may be handed in; that rule is covered API-side.
 */

const DIALOG = /which permits? are you renewing/i

/** Answer the entry dialog for the first business with something due. */
async function startRenewal(page: Page): Promise<string> {
  await page.goto('/apply?type=renewal')
  const modal = page.getByRole('dialog', { name: DIALOG })
  await expect(modal).toBeVisible({ timeout: WIZARD_PAINT_MS })

  /*
   * The grouped dropdown puts everything renewable under "Due for renewal",
   * so the first real option is a business with something to file. Picked by
   * position rather than by name: which demo shop is due depends on the day
   * the seeder last ran.
   */
  const select = modal.locator('select').first()
  const values = await select
    .locator('option')
    .evaluateAll((os) => os.map((o) => (o as HTMLOptionElement).value).filter(Boolean))
  expect(values.length).toBeGreaterThan(0)
  await select.selectOption({ value: values[0] })

  const permits = modal.locator('ul input')
  await expect(permits.first()).toBeVisible({ timeout: WIZARD_PAINT_MS })
  const permitName = (await modal.locator('ul li').first().innerText()).split('\n')[0].trim()
  await permits.first().check()

  await modal.getByRole('button', { name: /continue/i }).click()
  await expect(modal).toBeHidden({ timeout: WIZARD_PAINT_MS })

  return permitName
}

test('a sanitary renewal reaches the City Health Office queue', async ({ browser }) => {
  /*
   * Two heavy wizard paints plus an officer queue; see `WIZARD_PAINT_MS` for
   * why that does not fit the usual 180s.
   */
  test.setTimeout(420_000)

  const applicant = await browser.newContext({ storageState: sessionFor('owner') })
  const page = await applicant.newPage()

  const permitName = await startRenewal(page)
  console.log(`renewing: ${permitName}`)

  /* Step 1 — consent. */
  const consent = page.getByRole('checkbox', { name: /i have read/i })
  await expect(consent).toBeVisible({ timeout: WIZARD_PAINT_MS })
  await consent.check()

  const title = `E2E Office Routing ${Date.now()}`
  await page.getByLabel(/application title/i).fill(title)
  await page.getByRole('button', { name: /^next$/i }).click()

  /*
   * Step 2 — the office sheet. Sanitary asks one required answer, and the
   * chips are `<button role="radio">` rather than inputs.
   */
  const classification = page.getByRole('radio', { name: /food establishment/i }).first()
  await expect(classification).toBeVisible({ timeout: WIZARD_PAINT_MS })
  await classification.click()

  const next = page.getByRole('button', { name: /^next$/i })
  /*
   * Next must become enabled. It did not until 4 October: `missingFor` kept a
   * closure over a stale `officeData`, so the chip filled in and the gate went
   * on saying the classification was missing.
   */
  await expect(next).toBeEnabled({ timeout: 30_000 })
  await next.click()

  /* Step 3 — review and submit. */
  const submit = page.getByRole('button', { name: /^submit$/i }).first()
  await expect(submit).toBeVisible({ timeout: WIZARD_PAINT_MS })
  await submit.click()

  const confirm = page.getByRole('dialog').first()
  await expect(confirm).toBeVisible({ timeout: 30_000 })
  /* The office that actually receives it, not BPLO. */
  await expect(confirm).toContainText(/city health office/i)
  await confirm.getByRole('button', { name: /yes, submit/i }).click()

  /*
   * A tracking id is the proof it was accepted. Without it the submit threw
   * and rolled back, which is one of the two ways this filing used to reach
   * nobody.
   */
  const tracking = page.getByText(/^BIZ-\d{4}-\d+$/)
  await expect(tracking).toBeVisible({ timeout: WIZARD_PAINT_MS })
  const trackingId = (await tracking.innerText()).trim()
  console.log(`submitted: ${trackingId}`)

  await applicant.close()

  /* ── And now the office's own screen ─────────────────────────────────── */
  const office = await browser.newContext({ storageState: sessionFor('sanitary') })
  const queue = await office.newPage()
  await queue.goto('/staff/queue')

  await expect(queue.getByRole('heading', { name: /manage applications/i })).toBeVisible({
    timeout: WIZARD_PAINT_MS,
  })
  await expect(queue.getByText(trackingId)).toBeVisible({ timeout: WIZARD_PAINT_MS })

  await office.close()
})
