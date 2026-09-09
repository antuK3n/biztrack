import { expect, test, type Browser, type Page } from '@playwright/test'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { OFFICES, sessionFor } from './helpers'

/*
 * One filing, from the owner pressing Submit to the permits landing in their
 * Profile, walked through the browser in the order a real one happens.
 *
 * ── Why this is one narrative and not nine independent tests ───────────────
 *
 * Every other spec in this suite finds a fixture in the register and asserts
 * one screen against it. That is the right shape for a screen and the wrong
 * shape for a LIFECYCLE, because the defects this file exists to catch are all
 * of the form "state moved here and did not move there":
 *
 *   - an office approving and the other six losing their Approve button
 *     (INS-1, a client report — a shared branch keyed on the FILING's status
 *     rather than on the reading office's own assignment);
 *   - a filing approved by one office and vanishing from both queue tabs
 *     (INS-2, also a client report);
 *   - `approveAndIssue` running twice and minting a second full set of
 *     numbered permits, which `transition()` then hides by no-opping
 *     Approved → Approved.
 *
 * None of those is visible from a single screen or a single session. They are
 * visible from two sessions looking at the same filing at the same moment, so
 * the offices here are real browser contexts and the assertions are made from
 * inside them.
 *
 * ── Not serial ─────────────────────────────────────────────────────────────
 *
 * `test.describe.configure({ mode: 'serial' })` would be the obvious choice
 * and is deliberately not used: it skips the rest of the file after the first
 * failure, and a spec written to find product defects must not stop reporting
 * the moment it finds one. The narrative order is guaranteed by
 * `fullyParallel: false` and by tests inside a file running in order; each
 * test below re-asserts the state it depends on rather than assuming it.
 */

test.use({ storageState: sessionFor('owner') })

/*
 * Longer than the 30s default, because a stage here is six offices.
 *
 * Most of these tests open a browser context per office, load its queue, search
 * it and read a screen — seven times over against a dev server that compiles on
 * demand. That is minutes of honest work, not a hang, and the default timeout
 * turns it into "Test timeout exceeded" with no indication of which office was
 * being looked at. Raised here rather than in playwright.config.ts so no other
 * spec's timeout moves with it.
 */
test.describe.configure({ timeout: 240_000 })

/**
 * The filing every test below is about — kept on disk, not in a module variable.
 *
 * Playwright discards the worker process after a failing test and starts a
 * fresh one, so anything held in module scope is reset by the first failure.
 * That is normally a virtue: a spec cannot inherit a neighbour's mess. Here it
 * would mean that the moment this file finds a defect it also forgets which
 * filing the remaining stages are about, and reports six more failures that are
 * all the same one wearing a different hat — which is precisely the outcome the
 * "not serial" note above exists to avoid.
 *
 * Three facts, written once by the first test and read by every test after it.
 */
interface Narrative {
  appId: number
  trackingId: string
  businessName: string
}

const NARRATIVE_FILE = path.join(os.tmpdir(), 'biztrack-e2e-lifecycle.json')

function remember(state: Narrative) {
  fs.writeFileSync(NARRATIVE_FILE, JSON.stringify(state), 'utf8')
}

/**
 * The filing under test, or a failure naming what actually went wrong.
 *
 * The first test removes the file before it starts and writes it only once the
 * filing exists, so a stale one from an earlier run can never be picked up —
 * a spec that quietly carried on against yesterday's application would assert
 * about permits somebody else issued.
 */
function recall(): Narrative {
  if (!fs.existsSync(NARRATIVE_FILE)) {
    throw new Error('No filing was created — this narrative starts at the first test in the file.')
  }
  return JSON.parse(fs.readFileSync(NARRATIVE_FILE, 'utf8')) as Narrative
}

/** Every permit code on the filing — BPLO's plus the five required clearances. */
const FILED_CODES = OFFICES.map((o) => o.permit)
/** The offices whose permit type on this filing carries `requires_inspection`. */
const INSPECTING = OFFICES.filter((o) => o.inspects)

/**
 * Act as one office, in its own browser context.
 *
 * `browser.newContext({ storageState })` rather than `test.use`: the point of
 * half this file is what office B sees at the instant office A has acted, and
 * a spec that can only hold one session at a time cannot ask that question —
 * it can only ask it again later, which is a different question with a race in
 * it.
 *
 * The context is closed in a `finally` so that a failed assertion inside the
 * callback does not leak a browser context per office per test.
 */
async function asOffice<T>(
  browser: Browser,
  account: (typeof OFFICES)[number]['account'],
  body: (page: Page) => Promise<T>,
): Promise<T> {
  const context = await browser.newContext({ storageState: sessionFor(account) })
  const page = await context.newPage()
  try {
    return await body(page)
  } finally {
    await context.close()
  }
}

/**
 * Read the filing back through the API, from whichever session `page` holds.
 *
 * Used for the facts a screen cannot state precisely — "how many permits
 * exist", "which offices hold an assignment and in what state". Those are
 * counts, and a screen that renders six of seven rows looks exactly like a
 * screen that renders seven. Everything a user could see is asserted on the
 * screen; this is for the arithmetic behind it.
 */
async function filing(page: Page, portal: 'staff' | 'public', appId: number) {
  /*
   * On-origin before touching localStorage. A context that has not navigated
   * yet is on about:blank, where reading it is a SecurityError — the saved
   * session is attached to the origin, not to the blank page. Several tests
   * below act entirely through office contexts and only then read the register
   * back through the owner's, which is exactly that case.
   */
  if (!page.url().startsWith('http')) {
    await page.goto(portal === 'staff' ? '/staff/queue' : '/dashboard')
  }

  return page.evaluate(
    async ([id, p]) => {
      const token = localStorage.getItem(`biztrack.token.${p}`)
      const res = await fetch(`/api/v1/applications/${id}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
      if (!res.ok) throw new Error(`GET /applications/${id} answered ${res.status}`)
      const app = (await res.json()).data as {
        status: string
        permits: { permit_type?: { code: string } | null; permit_number: string }[]
        permit_types: { code: string }[]
        assignments: { status: string; department: { code: string } }[]
        inspections: {
          status: string
          result: string | null
          conducted_at: string | null
          department: { code: string } | null
        }[]
      }
      return app
    },
    [appId, portal] as const,
  )
}

/** This office's assignment state on the filing, or undefined if unrouted. */
function assignmentOf(
  app: Awaited<ReturnType<typeof filing>>,
  code: string,
): string | undefined {
  return app.assignments.find((a) => a.department.code === code)?.status
}

/**
 * Open this office's own review sheet for the filing, THROUGH the queue.
 *
 * Deliberately not `goto('/staff/queue/' + id)` off an API lookup. Half the
 * claim being made in the tests below is that the row is findable by the
 * officer who owns it — "I approved it as BPLO and it is not in For
 * Inspection" is the client's report 1 in its own words — so the row has to be
 * reached the way the officer reaches it, by searching the queue and clicking
 * what comes back.
 *
 * The row is SEARCHED for by tracking ID and IDENTIFIED by business name, which
 * is not a belt-and-braces flourish — it is working around a defect this file
 * reports separately. The queue row never prints the tracking ID (see "the
 * officer's queue row does not name the filing that was searched for" below), so
 * a locator keyed on it matches nothing even when the search worked perfectly.
 * The business name is unique per run, so it identifies the row exactly; the
 * defect is asserted once, in its own test, rather than being allowed to fail
 * every stage of the narrative with the same finding.
 *
 * Returns the assignment id the row pointed at, so a caller can say which row
 * it opened when an assertion downstream fails.
 */
async function openFromQueue(
  page: Page,
  tab: 'For Approval' | 'For Inspection' | 'Final Approval',
  { trackingId, businessName }: Narrative,
): Promise<string> {
  await page.goto('/staff/queue')
  await expect(page.getByRole('heading', { name: 'Application Verification', level: 1 })).toBeVisible({
    timeout: 30_000,
  })
  await page.getByRole('button', { name: tab }).click()
  await page.getByRole('searchbox', { name: /Search this queue/ }).fill(trackingId)

  const row = page.locator(`a[href^="/staff/queue/"]`).filter({ hasText: businessName })
  await expect(
    row,
    `${trackingId} is not in this office's ${tab} tab, so the officer cannot reach it`,
  ).toHaveCount(1, { timeout: 20_000 })

  const href = (await row.getAttribute('href')) ?? ''
  await row.click()
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 30_000 })
  return href
}

/**
 * Put this office's name to the filing's RA 11032 processing category, if the
 * sheet is still asking for one. Edit mode must already be on.
 *
 * ── Why the narrative has to do this at all ─────────────────────────────────
 *
 * The client's rule: "The admin must not approve the application unless an
 * Application category is chosen." It is enforced twice — ReviewPage draws
 * Approve shut while the category is unclaimed, and
 * WorkflowService::requireProcessingCategory refuses the request behind it — so
 * an officer's first act on a freshly submitted filing is this one, and a
 * narrative that skipped it would be walking a route the product does not
 * offer.
 *
 * "Unclaimed", not "empty", is the state that matters and is the part that is
 * easy to get wrong from a test. A filing arrives already showing a tier,
 * because submit() seeds Ra11032::tierFor's GUESS; what the gate waits for is a
 * person, which the payload reports as `ra11032.source === 'officer'`. So the
 * select can read "Complex — 7 working days" and Approve still be shut, and the
 * thing this function presses is an officer AGREEING with the guess. That saves
 * the tier already on screen, so it claims the provenance without moving the
 * statutory deadline by a day.
 *
 * ── Why a shut Approve reads as an absent one from here ─────────────────────
 *
 * Approve is shut with `aria-disabled` and not `disabled`, deliberately: a
 * control dropped out of the tab order takes the sentence explaining itself
 * with it. Playwright's actionability honours `aria-disabled` exactly as a
 * screen reader does, so `.click()` on the shut button waits for it to open and
 * then times out — the symptom is "there is no Approve on this page", which is
 * a true report of what an assistive technology user meets and a misleading one
 * about what is on the screen. Clearing the gate is the fix; forcing the click
 * would be the test pretending the client's rule is not there.
 *
 * Keyed on the banner rather than on the button's `aria-disabled`, because the
 * banner is what the officer is actually told and it is the same condition. And
 * it does nothing at all once the category is claimed: the category belongs to
 * the FILING, so only the first of the six offices ever finds work here.
 */
async function claimProcessingCategory(page: Page) {
  const banner = page.locator('#approve-blocked-why')
  if ((await banner.count()) === 0) return

  const select = page.locator('#ra11032-tier')
  await expect(
    select,
    'Approve is shut for want of a category and the panel offers no way to set one',
  ).toBeVisible()

  /*
   * The value already shown, and only failing back to the first real tier for a
   * filing that genuinely carries none and is therefore showing the
   * "Not yet categorised" placeholder. Picking a DIFFERENT tier would work too
   * and would be the wrong thing to write down: it would re-count this filing's
   * deadline, so every assertion about lateness downstream would be measuring a
   * clock this helper moved.
   */
  const current = await select.inputValue()
  const tiers = await select
    .locator('option')
    .evaluateAll((els) => els.map((el) => (el as HTMLOptionElement).value).filter((v) => v !== ''))
  await select.selectOption(current || tiers[0])

  const [saved] = await Promise.all([
    page.waitForResponse(
      (r) => /\/assignments\/\d+\/classification$/.test(r.url()) && r.request().method() === 'POST',
      { timeout: 30_000 },
    ),
    page.getByRole('button', { name: 'Save category' }).click(),
  ])
  expect(
    saved.status(),
    `claiming the processing category was refused: ${await saved.text()}`,
  ).toBe(200)

  // The banner going is the screen's own word that Approve is open again.
  await expect(
    banner,
    'the category was saved and the sheet still says the filing has none',
  ).toHaveCount(0, { timeout: 20_000 })
}

/**
 * Press this office's own Approve on a review sheet it already has open.
 *
 * Edit mode first, because that is what turns the decision controls on
 * (checklist item 54) and an officer has to do the same. Then the category, for
 * the reasons written over `claimProcessingCategory` — on this narrative that
 * is one press by BPLO, the first office through, and a no-op for the six after
 * it.
 *
 * The wait is on the RESPONSE and not on the confirmation dialog. Waiting for
 * the POST is the stronger signal: it is the write itself rather than a
 * screen's report of it, and it carries the status code, so an approval refused
 * with a 422 fails here saying so instead of two assertions later saying the
 * assignment never moved. The dialog gets its own test below, which is where a
 * regression in the confirmation belongs rather than spread over every stage.
 */
async function approveOwnReview(page: Page) {
  await page.getByRole('button', { name: 'Edit', exact: true }).click()
  await claimProcessingCategory(page)

  const [response] = await Promise.all([
    page.waitForResponse(
      (r) => /\/assignments\/\d+\/approve$/.test(r.url()) && r.request().method() === 'POST',
      { timeout: 30_000 },
    ),
    page.getByRole('button', { name: 'Approve', exact: true }).click(),
  ])

  expect(
    response.status(),
    `approving this office's review was refused: ${await response.text()}`,
  ).toBe(200)

  // Dismissed only if it is there. See the defect test for when it is not.
  const dialog = page.getByRole('dialog', { name: 'VERIFICATION' })
  if (await dialog.isVisible()) {
    await page.getByRole('button', { name: 'Tracking Page' }).click()
  }
}

/* ──────────────────────────────────────────────────────────────────────────
 * 1. The owner files.
 * ────────────────────────────────────────────────────────────────────────── */

test('an owner files a business permit, and it goes to BPLO to be read', async ({ page }) => {
  // Nothing from an earlier run may be inherited: every stage below asserts
  // counts, and a stale filing would be indistinguishable from this one.
  fs.rmSync(NARRATIVE_FILE, { force: true })

  await page.goto('/dashboard')

  /*
   * The draft is built through the API, and the wizard is walked through the
   * browser. Both halves are deliberate.
   *
   * Filling seven sections of form — a map pin inside the Malabon bounding
   * box, a searchable PSIC picker, a four-box TIN, eight PDF uploads and a fee
   * profile — would make this a test of the wizard, which is
   * `apply-wizard.spec.ts`'s job and is already 1,300 lines of it. What this
   * file needs from the wizard is one thing: that Submit really submits. So
   * the state is created the way `clearances.spec.ts` creates it, and the last
   * step is pressed for real.
   */
  const created = await page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.public')
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    }
    const json = async (res: Response) => {
      if (!res.ok) throw new Error(`${res.url} answered ${res.status}: ${await res.text()}`)
      return (await res.json()).data
    }

    const barangays = await json(await fetch('/api/v1/reference/barangays', { headers }))
    const allPsic = await json(await fetch('/api/v1/reference/psic-codes', { headers }))
    // 00000 "Other (not listed)" carries a NULL revenue-code category and is
    // refused by the address step; 35 of 36 business-tax rules match on it.
    const psic = allPsic.filter((c: { code: string }) => c.code !== '00000')
    const permitTypes = await json(await fetch('/api/v1/reference/permit-types', { headers }))

    // Unique per run, and the handle every screen assertion below scopes by:
    // this owner already has filings in the copied register.
    const name = `E2E Lifecycle ${Date.now()}`

    const business = await json(
      await fetch('/api/v1/businesses', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          name,
          registration_type: 'DTI',
          registration_number: 'DTI-E2E-LIFE',
          tin: '123-456-789-000',
          address: {
            line1: '3 Playwright St.',
            /*
             * Longos by name, not `barangays[0]`. These coordinates are Malabon
             * City Hall, which is in Longos; the first barangay alphabetically
             * is Acacia, about 1.5 km away. The zoning step now refuses a pin
             * that contradicts its barangay, so the old pairing left this filing
             * permanently stuck on section 2 with no way to submit.
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
            lines: [{ psic_code_id: psic[0].id, category: 'retailer', capitalization: 500000 }],
          },
        }),
      }),
    )

    /*
     * The documents step wants the BUSINESS permit type's requirements only
     * (ApplyWizard `requiredDocs`), narrowed by `context`. Mirrored here rather
     * than uploading everything, because a document type whose context is
     * `renewal` cannot be satisfied by a new filing and is not asked for.
     */
    const pdf = '%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n'
    for (const dt of businessType.document_types) {
      const context: string | undefined = dt.context
      const appliesNow =
        !context || context === 'all' || context === 'new' || context.toUpperCase() === 'BUSINESS'
      if (dt.is_required === false || !appliesNow) continue
      const body = new FormData()
      body.append('document_type_id', String(dt.id))
      body.append('file', new File([pdf], `${dt.code}.pdf`, { type: 'application/pdf' }))
      const up = await fetch(`/api/v1/applications/${app.id}/documents`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        body,
      })
      if (!up.ok) throw new Error(`uploading ${dt.code} answered ${up.status}`)
    }

    /*
     * The clearances are NOT applied for here any more.
     *
     * They used to be, because the six were chosen inside the wizard and had to
     * be on the filing before it was submitted. Payment comes first now
     * (docs/clearances-after-payment.md): the clearance stage is locked until
     * the first payment clears, so this endpoint would be refused at this point
     * in the narrative. The loop has moved to test 4, immediately after Pay,
     * which is where an applicant can really reach it.
     *
     * The office sheets moved with it, for the same reason — a sheet is the
     * second half of applying for a clearance.
     */

    return { id: app.id as number, name }
  })

  const appId = created.id
  const businessName = created.name

  /* ── Submit, for real, from the wizard's last step ────────────────────── */

  await page.goto(`/apply?draft=${appId}`)
  // Consent is the one answer the API has no field for.
  await page.getByRole('checkbox').first().check()

  const map = page.locator('ol[aria-label="Application sections"]')
  await expect(map).toBeVisible({ timeout: 30_000 })

  /*
   * Six sections, fixed. It was `7 + 6` — seven phases plus one office sheet
   * per clearance applied for — and that arithmetic is gone with the clearance
   * step: the wizard is the business permit application alone now, so nothing
   * can grow this map.
   *
   * Still asserted, because the jump below is what proves the filing is
   * complete: the map refuses a forward jump over an unfinished section, so
   * reaching Review & Submit in one click IS the statement that nothing is
   * outstanding.
   */
  await expect(map.locator('li')).toHaveCount(6)

  /*
   * Walked to Review & Submit, then submitted through the API rather than by
   * pressing the button.
   *
   * The button read "Submit & Pay" and charged the applicant in the same press.
   * It does not any more, and that is the client's correction rather than a
   * tidy-up: "submission and payment of BP Appl. Form are two different
   * process. After submission, the business owner will wait for the approval of
   * BPLO then the payment will go AFTER." So the label is "Submit", and the
   * stage this narrative walks through next is BPLO reading the form.
   *
   * The API is still used rather than the button, for the older reason: the
   * confirmation dialog is a screen of its own with its own test, and driving
   * it here would make a change to that dialog fail a test about the lifecycle.
   * The wizard is driven to the last step regardless, because reaching Review &
   * Submit is what proves the filing is complete.
   */
  await map.getByRole('button', { name: /review & submit/i }).click()
  await expect(page.getByRole('button', { name: /^submit$/i })).toBeEnabled()
  await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const res = await fetch(`/api/v1/applications/${id}/submit`, {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
    if (!res.ok) throw new Error(`submit answered ${res.status}`)
  }, appId)

  const filed = await filing(page, 'public', appId)
  const trackingId = await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const res = await fetch(`/api/v1/applications/${id}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
    return (await res.json()).data.tracking_id as string
  }, appId)

  remember({ appId, trackingId, businessName })

  expect(trackingId, 'a submitted filing always carries a tracking ID').toMatch(/^BIZ-/)
  /*
   * BPLO reading the form is what is left to do — NOT payment.
   *
   * This asserted `pending_payment`, which was true while submission raised the
   * bill directly. `WorkflowService::submit()` now ends on
   * `ForApproval` and routes to BPLO alone; the bill is raised by
   * `approveMainForm()`, which is a stage of its own further down this file.
   */
  expect(filed.status).toBe('for_approval')

  /*
   * Every permit the filing will need, from the moment it is filed.
   *
   * This asserted `['BUSINESS']` on the reasoning that clearances arrive only
   * when applied for. Half right: applying is still what STARTS one, but
   * `submit()` calls `attachRequiredPermitTypes()`, so all five required
   * clearances are on the pivot from the start at `not_started` — which is what
   * lets `assessFees()` price the whole filing in one go and the applicant pay
   * once. What the applies further down change is each row's STATUS, not
   * whether the row exists.
   */
  const codes = filed.permit_types.map((pt) => pt.code).sort()
  expect(codes, 'the filing was not attached to every permit it needs').toEqual(
    [...FILED_CODES].sort(),
  )
  for (const pt of filed.permit_types) {
    expect(pt.status, `${pt.code} was started before the applicant applied for it`).toBe(
      'not_started',
    )
  }
})

/* ──────────────────────────────────────────────────────────────────────────
 * 2. The owner's Track page, awaiting BPLO.
 * ────────────────────────────────────────────────────────────────────────── */

test('the filing shows on Track as awaiting BPLO, with nothing to pay yet', async ({ page }) => {
  const narrative = recall()
  const { trackingId, businessName } = narrative

  await page.goto('/applications')
  await expect(page.getByRole('heading', { name: 'Permit Tracking', level: 1 })).toBeVisible({
    timeout: 30_000,
  })
  await page.getByLabel(/Search your applications/).fill(trackingId)

  const row = page.locator('li > div').filter({ hasText: businessName })
  await expect(row, 'the filing just submitted is not on the owner’s Track page').toHaveCount(1, {
    timeout: 20_000,
  })

  /*
   * ── Nothing is owed YET, and the row has to say which of the three ────────
   *
   * That block used to be a two-way switch: Pay Online when
   * `status === 'pending_payment'`, a green "Paid" otherwise. With the bill
   * moved behind BPLO's approval there is now a stage on the near side of it,
   * and the else-branch was answering "Paid" for a filing that had never been
   * charged a peso — the strongest colour on the screen making a false claim
   * about money.
   *
   * All three are asserted, not just the one that should be there. A row
   * drawing two of them is the failure nobody looks for, and "Paid is absent"
   * is a different statement from "Not billed yet is present".
   */
  await expect(
    row.getByText('Not billed yet', { exact: true }),
    'a filing BPLO has not approved should say the bill has not been raised',
  ).toBeVisible()
  await expect(
    row.getByRole('link', { name: 'Pay Online' }),
    'the applicant was offered a payment before BPLO approved the form',
  ).toHaveCount(0)
  await expect(
    row.getByText('Paid', { exact: true }),
    'an unbilled filing was shown as paid',
  ).toHaveCount(0)
})

/* ──────────────────────────────────────────────────────────────────────────
 * 3. The gap between Submit and Pay.
 * ────────────────────────────────────────────────────────────────────────── */

test('a newly filed application is BPLO’s alone, and no other office can reach it', async ({
  browser,
}) => {
  const narrative = recall()
  const { appId, trackingId } = narrative

  /*
   * ── What this test used to claim, and why it had to change ────────────────
   *
   * It asserted that between Submit and Pay the filing is "routed to nobody",
   * because assignments were created by `routeToDepartments` and its only
   * caller was `onPaymentCompleted`. Every word of that is now false.
   * `WorkflowService::submit()` ends with `routeTo($app, bplo)`, so a filed
   * application has exactly one assignment from the moment it is filed — and it
   * must, because BPLO reading the form is the next thing that happens to it
   * and an officer cannot open a review sheet that has no assignment behind it.
   *
   * What SURVIVES is the half that was always the point: the other five offices
   * have nothing to do with this filing yet and must not be able to see it.
   * That boundary has not moved; only the number of offices inside it has, from
   * zero to one.
   *
   * BPLO first, and it is now an openable row rather than an explanatory dead
   * end.
   */
  await asOffice(browser, 'bplo', async (page) => {
    await openFromQueue(page, 'For Approval', narrative)

    /*
     * The sheet, with a live decision behind Edit. This is the assertion the
     * old version could not make: there was no assignment, so there was nothing
     * to open, so the row had to apologise for itself instead.
     */
    await page.getByRole('button', { name: 'Edit', exact: true }).click()
    await expect(
      page.getByRole('button', { name: 'Approve', exact: true }),
      'BPLO was routed the filing at submission but has no Approve on it',
    ).toBeVisible()
  })

  /*
   * Every other office: the filing does not exist for them, in any tab.
   *
   * The Pending Payment tab is still not offered to them, and still for the
   * same reason — an office reviewer's boundary IS the assignment row, so the
   * tab could only ever be empty in these seats, and an empty queue is a claim.
   */
  for (const office of OFFICES.filter((o) => o.account !== 'bplo')) {
    await asOffice(browser, office.account, async (page) => {
      await page.goto('/staff/queue')
      await expect(
        page.getByRole('heading', { name: 'Application Verification', level: 1 }),
      ).toBeVisible({ timeout: 30_000 })

      await expect(
        page.getByRole('button', { name: 'Pending Payment' }),
        `${office.code} is offered a Pending Payment tab it can never see anything in`,
      ).toHaveCount(0)

      for (const tab of ['For Approval', 'For Inspection'] as const) {
        await page.getByRole('button', { name: tab }).click()
        await page.getByRole('searchbox', { name: /Search this queue/ }).fill(trackingId)
        await expect(
          page.locator('a[href^="/staff/queue/"]').filter({ hasText: trackingId }),
          `${office.code} has a filing in its ${tab} tab that has only been routed to BPLO`,
        ).toHaveCount(0, { timeout: 20_000 })
      }

      // Nothing is routed here, so nothing is readable — the API says the same
      // thing the queue does, which is what stops a deep link going round it.
      const status = await page.evaluate(async (id) => {
        const token = localStorage.getItem('biztrack.token.staff')
        const res = await fetch(`/api/v1/applications/${id}`, {
          headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        })
        return res.status
      }, appId)
      expect(
        [403, 404],
        `${office.code} can open a filing it was never routed`,
      ).toContain(status)
    })
  }
})

test('the applicant’s row says which permit is moving and which have not started', async ({
  page,
}) => {
  const narrative = recall()
  const { trackingId, businessName } = narrative

  /*
   * ── A DEFECT TEST, RETIRED. Read this before restoring the old assertion. ──
   *
   * This was one of this file's standing bug reports, written to FAIL: an
   * unpaid filing was routed to nobody, and the applicant's expanded row said
   * "For Approval" anyway, because `permitChip()` and `fallbackChip()` both fell
   * through to a hardcoded `{ tone: 'orange', label: 'For Approval' }`. The cost
   * named at the time was real — an applicant who reads "For Approval" has no
   * reason to pay, and nobody could move the filing but them.
   *
   * The September flow resolved it, and not by touching that chip. Submission
   * now goes to `for_approval` and routes to BPLO, so at this point in the
   * narrative an office genuinely IS reading the form: the sentence the screen
   * was printing became true. The unpaid-and-unrouted state the defect lived in
   * no longer exists, because a filing is not billed until BPLO has approved it.
   *
   * So the test is turned the right way up rather than deleted. What it guards
   * now is that the row is EXACT — each permit reporting its own pivot status —
   * which is the property that made the old lie a lie. If a future change
   * flattens these chips back to one hardcoded label, the Not Started
   * assertions below go red, which is the same defect arriving by a new door.
   */
  await page.goto('/applications')
  await page.getByLabel(/Search your applications/).fill(trackingId)

  const row = page.locator('li').filter({ hasText: businessName }).first()
  await row.getByRole('button', { expanded: false }).first().click()

  /*
   * One chip per permit type, all six of them: `submit()` attaches every
   * required clearance, so they are on the row from the start. This read 1 while
   * the clearances were thought to arrive only on payment.
   */
  const chips = await row.locator('ul > li').allInnerTexts()
  expect(chips.length, 'the expanded row should draw one chip per permit type').toBe(
    FILED_CODES.length,
  )

  /*
   * Exactly one is moving. The Business Permit is with BPLO; the five
   * clearances have not been applied for and say so. A row where every chip
   * reads the same thing is the old defect, whichever label it has settled on.
   */
  const moving = chips.filter((c) => c.includes('For Approval'))
  expect(
    moving.length,
    'the row does not name exactly one permit as being read by an office',
  ).toBe(1)
  expect(
    chips.filter((c) => c.includes('Not Yet Submitted')).length,
    'the clearances have not been submitted, so each must say Not Yet Submitted',
  ).toBe(FILED_CODES.length - 1)
})

/* ──────────────────────────────────────────────────────────────────────────
 * 3b. BPLO reads the form, and only then is there a bill.
 * ────────────────────────────────────────────────────────────────────────── */

test('BPLO approving the form is what raises the bill', async ({ page, browser }) => {
  const narrative = recall()
  const { appId } = narrative

  /*
   * The stage that did not exist before September, and the client's own words
   * for why it does: "submission and payment of BP Appl. Form are two different
   * process. After submission, the business owner will wait for the approval of
   * BPLO then the payment will go AFTER."
   *
   * It is asserted as a MOVE — the filing is at `for_approval` before and
   * `pending_payment` after — because the failure this guards against is the one
   * that was live in the product: the wizard called `pay()` straight after
   * `submit()`, the money went through at `for_approval`, and
   * `onPaymentCompleted` ignored it because it returns early on any status but
   * `pending_payment`. Charged, recorded, and the filing did not move. See
   * `ApplicationStatus::isBillable()`.
   */
  const before = await filing(page, 'public', appId)
  expect(before.status, 'the narrative is not where this stage expects it').toBe('for_approval')

  await asOffice(browser, 'bplo', async (officePage) => {
    await openFromQueue(officePage, 'For Approval', narrative)
    await approveOwnReview(officePage)
  })

  const billed = await filing(page, 'public', appId)
  expect(billed.status, 'BPLO approved the form and no bill was raised').toBe('pending_payment')
  expect(assignmentOf(billed, 'BPLO'), 'BPLO’s own review did not complete').toBe('completed')

  /*
   * Nothing else moved. BPLO's approval says the form is fit to be paid for; it
   * does not start a clearance, book a visit or issue anything.
   */
  expect(billed.inspections, 'a visit was booked before the filing was even paid for').toHaveLength(
    0,
  )
  expect(billed.permits, 'a permit was issued on BPLO’s reading of the form').toHaveLength(0)
  for (const office of OFFICES.filter((o) => o.account !== 'bplo')) {
    expect(
      assignmentOf(billed, office.code),
      `${office.code} was routed the filing before the applicant applied to it`,
    ).toBeUndefined()
  }
})

/* ──────────────────────────────────────────────────────────────────────────
 * 4. Payment routes it.
 * ────────────────────────────────────────────────────────────────────────── */

test('paying opens the clearance stage, and applying routes each office', async ({
  page,
  browser,
}) => {
  const narrative = recall()
  const { appId } = narrative

  await page.goto(`/applications/${appId}/pay`)
  await expect(page.getByRole('heading', { name: 'Tax Order of Payment' })).toBeVisible({
    timeout: 30_000,
  })
  await page.getByRole('button', { name: 'Pay Online' }).click()

  // The receipt, not merely the absence of an error: `pay` swallows a failure
  // into an Alert and leaves the page looking much as it did.
  await expect(page.getByText('Paid', { exact: true })).toBeVisible({ timeout: 30_000 })

  const justPaid = await filing(page, 'public', appId)
  expect(justPaid.status, 'payment did not open the clearance stage').toBe(
    'awaiting_other_permits',
  )

  /*
   * ── And NOW the five clearances ──────────────────────────────────────────
   *
   * The clearance stage is locked until the payment clears
   * (`ClearanceService::isUnlocked` is `status->isPaid()`), so this loop is the
   * narrative's version of the applicant opening the stage that has just
   * unlocked and pressing Apply on each one.
   *
   * Through the endpoint the card presses, not by attaching permit types on an
   * update. The rows already exist — `submit()` attached them — so what this
   * changes is each one's STATUS, from `not_started` to `for_approval`, and
   * `startClearance` routes the office in the same transaction. That routing is
   * the thing being measured below.
   */
  await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    }
    for (const code of ['SANITARY', 'FSIC', 'ZONING', 'OCCUPANCY', 'CEC']) {
      const res = await fetch(`/api/v1/applications/${id}/clearances/${code}/apply`, {
        method: 'POST',
        headers,
      })
      if (!res.ok) {
        throw new Error(`applying for ${code} answered ${res.status}: ${await res.text()}`)
      }
    }
    /*
     * The office sheets, which are the second half of applying. Three of the six
     * will not be saved without an answer (`officeFormMissing`); they are filled
     * here for the same reason the rest of the form is, so that an unfilled one
     * is not what this file ends up measuring.
     */
    const sheets: Record<string, Record<string, string>> = {
      SANITARY: { sanitary_classification: 'Food Establishment' },
      OCCUPANCY: { application_type: 'Full' },
    }
    for (const [code, form_data] of Object.entries(sheets)) {
      const res = await fetch(`/api/v1/applications/${id}/office-forms/${code}`, {
        method: 'PUT',
        headers,
        body: JSON.stringify({ form_data }),
      })
      if (!res.ok) {
        throw new Error(`office form ${code} answered ${res.status}: ${await res.text()}`)
      }
    }
  }, appId)

  /* ── One payment, and there is no second one ───────────────────────────── */

  const paid = await filing(page, 'public', appId)

  /*
   * ── There USED to be a second payment here, and there must not be ─────────
   *
   * The old flow had "one ledger, two moments": the business permit was paid to
   * submit, then every clearance applied for afterwards re-assessed onto the
   * same FeeAssessment and raised a balance the permit was withheld against.
   * This test paid twice, through the same screen, because that was the journey.
   *
   * The client's verified procedure replaced it. `assessFees()` is called ONCE
   * now, at submission, over the business permit and all five clearances, and
   * `ClearanceService::apply` no longer touches the assessment at all — so the
   * single payment above covers everything and a second press would have
   * nothing to charge for. Applying is a decision about evidence, not about
   * money.
   *
   * Asserted rather than merely dropped: applying for five clearances must
   * leave the filing exactly where the payment left it. If a future change puts
   * a balance back, this is where it shows up.
   */
  expect(paid.status, 'applying for the clearances moved the filing').toBe(
    'awaiting_other_permits',
  )

  // Every permit is on the filing — the mayor's, and the five clearances.
  expect(
    paid.permit_types.map((pt) => pt.code).sort(),
    'the filing did not carry the mayor’s permit and all five clearances',
  ).toEqual([...FILED_CODES].sort())

  /*
   * ── Who is routed, and who is not ─────────────────────────────────────────
   *
   * Every office, and BPLO's is the odd one out in a way worth pinning. The
   * five clearance offices were routed by `startClearance` in the loop above and
   * their reviews are open; BPLO was routed at SUBMISSION and its review is
   * already `completed`, closed by `approveMainForm` two stages back. So a loop
   * asserting `pending` across all six — which is what this used to be, when
   * payment routed everybody at once — would now fail on BPLO for a correct
   * reason.
   */
  expect(
    paid.assignments.map((a) => a.department.code).sort(),
    'the filing was not routed to every office on it',
  ).toEqual([...OFFICES.map((o) => o.code)].sort())
  expect(
    assignmentOf(paid, 'BPLO'),
    'BPLO’s form review reopened when the clearances were applied for',
  ).toBe('completed')
  for (const office of OFFICES.filter((o) => o.account !== 'bplo')) {
    expect(assignmentOf(paid, office.code), `${office.code}'s review is not open`).toBe('pending')
  }

  // Nothing is booked yet: a visit follows an office's approval, not a payment.
  expect(paid.inspections, 'a visit was booked before any office had read the filing').toHaveLength(0)

  /* ── And each clearance office can now find it, in its own queue ───────── */

  for (const office of OFFICES.filter((o) => o.account !== 'bplo')) {
    await asOffice(browser, office.account, async (officePage) => {
      await openFromQueue(officePage, 'For Approval', narrative)
      /*
       * The row opens on the review sheet with a live decision behind Edit.
       * Presence, not a press: this test is about routing, and the stage below
       * is about what happens when one of these is pressed.
       */
      await officePage.getByRole('button', { name: 'Edit', exact: true }).click()
      await expect(
        officePage.getByRole('button', { name: 'Approve', exact: true }),
        `${office.code} was routed the filing but has no Approve on it`,
      ).toBeVisible()
    })
  }
})

test('the officer’s queue row names the filing that was searched for', async ({ browser }) => {
  const narrative = recall()
  const { trackingId, businessName } = narrative

  /*
   * ── DEFECT. This test is expected to FAIL, and must not be weakened. ──────
   *
   * The queue's search box is named "Search this queue by tracking ID or
   * business name", the tracking ID is the handle the applicant quotes down the
   * phone, and `AssignmentController::index` really does match on it. What comes
   * back does not print it. `QueuePage`'s `QueueItem` carries `trackingId` and
   * uses it in exactly two places — `matchesSearch` and the fallback for a
   * business that has been removed from the register — and never renders it. The
   * row is the business name, a date, and a paid/unpaid block.
   *
   * So an officer who searches "BIZ-2026-00964" is shown a row that nowhere says
   * BIZ-2026-00964, and cannot confirm they are about to open the filing they
   * were asked about. A business with two filings in flight — a renewal and an
   * amendment, which is ordinary — produces two rows that are identical on
   * screen, and the only way to tell them apart is to open one and look.
   *
   * That this was not already caught is worth recording, because it explains why
   * it is still here: `track-search.spec.ts` does assert
   * `rows.first()).toContainText('BIZ-2026-00203')` — but the fixture behind
   * that row has `business: null` ("business removed"), so `nameOf()` falls back
   * to printing the tracking ID AS the name. The one existing assertion about a
   * tracking ID on a queue row passes only down the path where there is no
   * business name to print instead.
   *
   * Asserted from BPLO's seat, which is the coordinating office and the one that
   * fields the phone calls.
   */
  await asOffice(browser, 'bplo', async (page) => {
    await page.goto('/staff/queue')
    await expect(
      page.getByRole('heading', { name: 'Application Verification', level: 1 }),
    ).toBeVisible({ timeout: 30_000 })
    await page.getByRole('searchbox', { name: /Search this queue/ }).fill(trackingId)

    const row = page.locator('a[href^="/staff/queue/"]').filter({ hasText: businessName })
    await expect(row).toHaveCount(1, { timeout: 20_000 })

    await expect(
      row,
      'the row found by a tracking-ID search does not print the tracking ID',
    ).toContainText(trackingId)
  })
})

test('a queue search says how many filings actually matched it', async ({ browser }) => {
  const narrative = recall()
  const { trackingId } = narrative

  /*
   * ── DEFECT. This test is expected to FAIL, and must not be weakened. ──────
   *
   * Search a tracking ID in For Approval and the queue answers, verbatim:
   *
   *     Showing 1 of 11 matching “BIZ-2026-00964”, newest first.
   *
   * One filing matches. Eleven is every filing in the tab, and it is stated as
   * the number that MATCHED the term the officer typed.
   *
   * `QueuePage` takes the assignment tabs' total from
   * `meta.application_status_counts` — summed across the tab's statuses — and
   * that breakdown is computed without the `q` the same request carried.
   * `meta.total` beside it is correctly 1. So the sentence pairs a searched
   * numerator with an unsearched denominator, and it is the denominator that an
   * officer reads as "how much is there".
   *
   * This is the same failure the queue has already paid for once and by name:
   * the client was shown "Showing 0 of the 13 loaded" while searching a business
   * the register plainly held, and the fix was meant to be that the count is the
   * queue's rather than the page's. It is neither now — it is the tab's.
   *
   * `track-search.spec.ts` cannot see it: its stub returns
   * `application_status_counts: { returned: 1, for_approval: 2 }`, which sums to
   * exactly the three rows the stub also returns, so the wrong number and the
   * right number are the same number in the fixture.
   */
  await asOffice(browser, 'bplo', async (page) => {
    await page.goto('/staff/queue')
    await expect(
      page.getByRole('heading', { name: 'Application Verification', level: 1 }),
    ).toBeVisible({ timeout: 30_000 })
    await page.getByRole('searchbox', { name: /Search this queue/ }).fill(trackingId)

    /*
     * Read AFTER the list has settled, not the moment the term appears in the
     * sentence. The search is debounced, so for a beat the caption is rewritten
     * against rows that have not been replaced yet and reads "Showing 12 of 12
     * matching …" — which is a second, transient wrong answer and not the one
     * being reported here. Waiting for the single row puts this assertion on the
     * state the officer is left looking at.
     */
    const status = page.getByRole('status').filter({ hasText: 'Showing' })
    await expect(page.locator('a[href^="/staff/queue/"]')).toHaveCount(1, { timeout: 20_000 })
    await expect(status).toContainText(`matching “${trackingId}”`, { timeout: 20_000 })

    /*
     * Read the two numbers out of the sentence rather than pinning the whole
     * string: the sort clause and the "Load more" tail are copy, and this is
     * about arithmetic. One filing was created by this narrative and one filing
     * carries this tracking ID, so both numbers have to be 1.
     */
    const sentence = (await status.innerText()).trim()
    const [, shown, total] = /Showing ([\d,]+) of ([\d,]+)/.exec(sentence) ?? []
    expect(shown, `the queue said: ${sentence}`).toBe('1')
    expect(
      total,
      `a tracking ID matches one filing, but the queue said: ${sentence}`,
    ).toBe('1')
  })
})

/* ──────────────────────────────────────────────────────────────────────────
 * 5. Approval isolation.
 * ────────────────────────────────────────────────────────────────────────── */

test('one office’s approval closes its own review and moves nobody else’s', async ({
  page,
  browser,
}) => {
  const narrative = recall()
  const { appId, trackingId, businessName } = narrative

  /*
   * ── The first CLEARANCE office goes, not BPLO ─────────────────────────────
   *
   * BPLO used to go first here, because its permit type was the only one with
   * `requires_inspection` false, so its approval booked no visit and left the
   * filing exactly where it was — a clean "nothing advanced" assertion.
   *
   * BPLO cannot go first any more: its approval is `approveMainForm`, it
   * happened three stages back, and it is what raised the bill. Asking it to
   * approve again would find no Approve button and report a missing control
   * that is correctly missing.
   *
   * The claim survives the change of actor, and reads better for it. One
   * clearance office accepting its paperwork moves its OWN permit to
   * `for_inspection` and books its OWN visit; `refreshReadiness` holds the
   * filing at `awaiting_other_permits` until every required permit is approved,
   * so the application itself does not move at all — which is the isolation
   * this stage exists to prove, now with a visit in the picture rather than in
   * spite of one.
   */
  const first = INSPECTING[0]

  await asOffice(browser, first.account, async (officePage) => {
    await openFromQueue(officePage, 'For Approval', narrative)
    await approveOwnReview(officePage)
  })

  const afterFirst = await filing(page, 'public', appId)

  expect(
    assignmentOf(afterFirst, first.code),
    `${first.code}'s own review did not complete`,
  ).toBe('completed')
  expect(
    assignmentOf(afterFirst, 'BPLO'),
    'BPLO’s completed form review was reopened by another office’s approval',
  ).toBe('completed')
  for (const office of OFFICES.filter(
    (o) => o.account !== 'bplo' && o.account !== first.account,
  )) {
    expect(
      assignmentOf(afterFirst, office.code),
      `${first.code} approving moved ${office.code}'s review, which is not theirs to move`,
    ).toBe('pending')
  }

  /*
   * The second machine moved, and only on the one row. This is the assertion
   * the old version could not make at all: there were no per-permit statuses.
   */
  const pivot = (code: string) => afterFirst.permit_types.find((pt) => pt.code === code)?.status
  expect(pivot(first.permit), `${first.code}'s permit did not reach its inspection`).toBe(
    'for_inspection',
  )
  for (const office of INSPECTING.filter((o) => o.account !== first.account)) {
    expect(
      pivot(office.permit),
      `${first.code} approving moved ${office.code}'s permit`,
    ).toBe('for_approval')
  }

  /*
   * The filing itself has not moved, and neither has anything else downstream.
   * The visit count is asserted exactly: a booking loop that ran over the
   * FILING rather than over the approving office would show up here as five.
   */
  expect(afterFirst.status, 'one office’s approval advanced the whole filing').toBe(
    'awaiting_other_permits',
  )
  expect(
    afterFirst.inspections.map((i) => i.department?.code),
    'approving one office’s review booked more than that office’s visit',
  ).toEqual([first.code])
  expect(
    afterFirst.permits,
    'a permit was issued on an office’s reading of the paperwork, before any visit',
  ).toHaveLength(0)

  /* ── The applicant is told the same thing ──────────────────────────────── */

  await page.goto(`/applications/${appId}`)
  await expect(page.getByRole('heading', { name: businessName, level: 1 })).toBeVisible({
    timeout: 30_000,
  })
  /*
   * `.first()` because this screen prints its status twice on purpose: the big
   * card at the top, which is where the filing IS, and the HISTORY timeline at
   * the foot, which is every status it has ever held. The card comes first in
   * the document and is the one an applicant reads as the answer; a page-wide
   * query could not tell "this filing is For Approval" from "this filing was
   * For Approval on Tuesday".
   */
  await expect(
    page.getByText('Awaiting Other Permits', { exact: true }).first(),
    'the applicant’s status card should still read Awaiting Other Permits',
  ).toBeVisible()

  /*
   * "Approved" may not be anywhere at all yet, history included — one office of
   * six has accepted paperwork, no visit has happened, and nothing has been
   * granted. This is the strong form of the assertion and it is available here
   * precisely because the history is still short.
   */
  await expect(
    page.getByText('Approved', { exact: true }),
    'one office accepting paperwork told the applicant something was approved',
  ).toHaveCount(0)

  /* ── The approving office's row moves ONE TAB, it does not vanish (INS-2) ─ */

  await asOffice(browser, first.account, async (officePage) => {
    /*
     * The client's report 1 — "I approved it and it is not in For Inspection" —
     * re-aimed at the machine that answers it now. A row's tab is decided by
     * THIS OFFICE's own outstanding work, and the two halves changed together:
     * For Approval filters on an OPEN assignment, which this office no longer
     * has, and For Inspection filters on this office's own
     * `clearance_status = for_inspection`, which is exactly where
     * `approveClearance` just put its permit.
     *
     * BPLO used to be the seat for this assertion and cannot be: its Business
     * Permit pivot is never `for_inspection`, so after `approveMainForm` it has
     * no row in either tab — correctly, because it has nothing to do until
     * every clearance is in and the filing reaches Final Approval.
     */
    await openFromQueue(officePage, 'For Inspection', narrative)

    await officePage.goto('/staff/queue')
    await officePage.getByRole('button', { name: 'For Approval' }).click()
    await officePage.getByRole('searchbox', { name: /Search this queue/ }).fill(trackingId)
    await expect(
      officePage.locator('a[href^="/staff/queue/"]').filter({ hasText: trackingId }),
      `a completed review is still being offered to ${first.code} as outstanding work`,
    ).toHaveCount(0, { timeout: 20_000 })
  })

  /* ── And every office that still owes one keeps its Approve (INS-1) ────── */

  for (const office of OFFICES.filter(
    (o) => o.account !== 'bplo' && o.account !== first.account,
  )) {
    await asOffice(browser, office.account, async (officePage) => {
      await openFromQueue(officePage, 'For Approval', narrative)
      await officePage.getByRole('button', { name: 'Edit', exact: true }).click()
      await expect(
        officePage.getByRole('button', { name: 'Approve', exact: true }),
        `${office.code} lost its Approve because a different office approved`,
      ).toBeVisible()
      await expect(
        officePage.getByRole('button', { name: 'Return with remarks' }),
        `${office.code} lost its Return because a different office approved`,
      ).toBeVisible()
    })
  }
})

test('a second office’s visit is booked beside the first, not instead of it', async ({
  page,
  browser,
}) => {
  const narrative = recall()
  const { appId } = narrative

  /*
   * ── What this stage used to be, and what it is now ────────────────────────
   *
   * It approved as the FIRST inspecting office and asserted that the filing
   * flipped to `for_inspection` while five assignments stayed pending — the
   * condition under which the review screen used to branch on the filing's
   * status and hand five offices a page with no controls (INS-1), a deadlock no
   * action in the product could clear.
   *
   * Both halves of that have moved. The first office's approval is now the
   * stage above, and no office approval moves the FILING at all: it moves one
   * pivot row. So this takes the second office, where the interesting question
   * is accumulation rather than transition — two offices at their visits at
   * once is the ordinary shape of this stage, and a booking that overwrote
   * rather than appended would look identical from a single seat.
   *
   * The INS-1 guard is kept and is still the point of the loop at the foot.
   */
  const first = INSPECTING[0]
  const second = INSPECTING[1]

  await asOffice(browser, second.account, async (officePage) => {
    await openFromQueue(officePage, 'For Approval', narrative)
    await approveOwnReview(officePage)
  })

  const after = await filing(page, 'public', appId)

  // Two offices are done reading; the application has still not moved.
  expect(after.status, 'a second office’s approval advanced the whole filing').toBe(
    'awaiting_other_permits',
  )
  expect(assignmentOf(after, second.code), `${second.code}'s own review did not complete`).toBe(
    'completed',
  )

  /*
   * TWO visits, one per approving office. `scheduleInspectionFor` books against
   * the office, so a loop that ran over the filing would show five here and a
   * booking that replaced rather than appended would show one.
   */
  expect(
    after.inspections.map((i) => i.department?.code).sort(),
    'the booked visits are not exactly the two offices that have approved',
  ).toEqual([first.code, second.code].sort())

  // Still nothing issued: a permit is granted by a PASSING VISIT, not by an
  // office accepting the paperwork.
  expect(after.permits, 'a permit was issued before any visit had happened').toHaveLength(0)

  /* ── The offices that still owe a review can still reach it ────────────── */

  const owing = OFFICES.filter(
    (o) => o.account !== 'bplo' && o.account !== first.account && o.account !== second.account,
  )
  for (const office of owing) {
    await asOffice(browser, office.account, async (officePage) => {
      /*
       * For Approval, not For Inspection, and that is the assertion rather than
       * a navigation detail. Two other offices are at their site visits; this
       * office's own permit is still paperwork, and the tab that holds the row
       * has to be the one matching THIS office's outstanding work — otherwise
       * the paperwork is filed under a heading about site visits and searching
       * For Approval for it answers "Nothing matches", the client's report 4.
       */
      await openFromQueue(officePage, 'For Approval', narrative)
      await officePage.getByRole('button', { name: 'Edit', exact: true }).click()
      await expect(
        officePage.getByRole('button', { name: 'Approve', exact: true }),
        `${office.code} has no Approve on a filing it still owes a review on`,
      ).toBeVisible()
    })
  }
})

test('approving is confirmed on screen whichever way the filing then moves', async ({ browser }) => {
  const narrative = recall()
  const third = INSPECTING[2]

  /*
   * ── The regression guard for a defect this test reported, now fixed ───────
   *
   * Approving a review ends in a VERIFICATION dialog — "Approval recorded",
   * then Home Page or Tracking Page. That is the whole of the officer's
   * feedback that the decision landed, and it is the only thing on the screen
   * that says so.
   *
   * It used to appear when BPLO approved and not when any of the clearance
   * offices did, and the difference had nothing to do with the offices. The
   * modal was the last thing in the review SHEET's own JSX; approving calls
   * `reload()`, and the reloaded filing sent the sheet down its early return —
   * the compact decision box — which returns before the modal is ever reached.
   * A clearance office's approval is exactly what makes that branch apply to
   * it, so its own confirmation was unmounted by its own success, and the
   * officer with the most consequential approval in the flow was the one told
   * nothing.
   *
   * The condition on that early return has since been re-keyed for the
   * September flow — `awaiting_other_permits`/`for_final_approval` rather than
   * the deleted `for_inspection` — which is exactly why this test still earns
   * its place: the branch moved, and the modal must not have moved back inside
   * it. `ReviewPage` owns `showVerification` and renders the modal as a SIBLING
   * of the whole sheet, so no `return` inside `ReviewSheet` — including the
   * next one somebody adds — can take it down.
   *
   * Asserted on the third inspecting office, so that the approval is a real
   * step of the narrative: this office's review is now in, and the stage below
   * expects it.
   */
  await asOffice(browser, third.account, async (page) => {
    await openFromQueue(page, 'For Approval', narrative)
    await page.getByRole('button', { name: 'Edit', exact: true }).click()

    const [response] = await Promise.all([
      page.waitForResponse(
        (r) => /\/assignments\/\d+\/approve$/.test(r.url()) && r.request().method() === 'POST',
        { timeout: 30_000 },
      ),
      page.getByRole('button', { name: 'Approve', exact: true }).click(),
    ])
    expect(response.status(), `${third.code}'s approval was refused`).toBe(200)

    await expect(
      page.getByRole('dialog', { name: 'VERIFICATION' }),
      `${third.code} approved and was given no confirmation that anything happened`,
    ).toBeVisible({ timeout: 10_000 })
    await page.getByRole('button', { name: 'Tracking Page' }).click()
  })
})

/* ──────────────────────────────────────────────────────────────────────────
 * 6. Everyone approves.
 * ────────────────────────────────────────────────────────────────────────── */

test('once every office has accepted its paperwork, a visit is booked for each', async ({
  page,
  browser,
}) => {
  const narrative = recall()
  const { appId } = narrative

  /*
   * The two still outstanding. BPLO approved the FORM before payment, and the
   * first three inspecting offices have accepted their paperwork in the stages
   * above — the third inside the confirmation test, whose approval landed
   * whether or not it was confirmed on screen. Asking a completed assignment to
   * approve again would find no Approve button and report a missing control
   * that is correctly missing.
   */
  const done = new Set<string>([
    'bplo',
    INSPECTING[0].account,
    INSPECTING[1].account,
    INSPECTING[2].account,
  ])
  const remaining = OFFICES.filter((o) => !done.has(o.account))
  for (const office of remaining) {
    await asOffice(browser, office.account, async (officePage) => {
      await openFromQueue(officePage, 'For Approval', narrative)
      await approveOwnReview(officePage)
    })
  }

  const all = await filing(page, 'public', appId)

  for (const office of OFFICES) {
    expect(assignmentOf(all, office.code), `${office.code}'s review is not recorded`).toBe(
      'completed',
    )
  }

  /*
   * A visit for each office whose permit type on this filing carries
   * `requires_inspection`, and NOT for BPLO. BPLO issues the Mayor's Permit on
   * the strength of the five clearances; a visit of its own would be one nobody
   * performs, and the filing would wait on it forever — it could never be
   * issued by any action the product offers.
   *
   * Driven off `OFFICES`, so a permit type that starts or stops requiring an
   * inspection fails here rather than silently changing what "all visits
   * passed" means.
   */
  const booked = all.inspections.map((i) => i.department?.code).sort()
  expect(booked, 'the booked visits are not exactly the inspecting offices’').toEqual(
    INSPECTING.map((o) => o.code).sort(),
  )
  expect(
    booked,
    'BPLO does not inspect, so a visit booked for it would stall issuance forever',
  ).not.toContain('BPLO')

  /*
   * Every office has read its paperwork and the application STILL has not
   * moved, because `refreshReadiness` gates on permits being APPROVED and every
   * one of them is only at its inspection. This asserted `for_inspection` — a
   * filing-wide status that no longer exists, and could not: five permits at
   * five different points is precisely what the split was for.
   */
  expect(all.status, 'accepting paperwork moved the filing past the clearance stage').toBe(
    'awaiting_other_permits',
  )
  expect(all.permits, 'permits were issued before a single visit had happened').toHaveLength(0)
})

/* ──────────────────────────────────────────────────────────────────────────
 * 7. The visits pass, and the permits are issued.
 * ────────────────────────────────────────────────────────────────────────── */

test('every visit passing issues its clearance, and BPLO’s sign-off issues the permit', async ({
  page,
  browser,
}) => {
  const narrative = recall()
  const { appId } = narrative

  for (const office of INSPECTING) {
    await asOffice(browser, office.account, async (officePage) => {
      /*
       * This office's review is closed and the filing is still being worked, so
       * the review sheet is gone and ReviewPage opens on the compact decision
       * box — the shape the client asked for by name ("it should just be like
       * the other ones where its just a box"). That branch used to key on the
       * filing reading `for_inspection`; it keys on this office having nothing
       * left to do, which is the same seat by a more honest test.
       */
      await openFromQueue(officePage, 'For Inspection', narrative)
      await expect(officePage.locator('section[aria-label="Application status"]')).toBeVisible({
        timeout: 30_000,
      })

      /*
       * Exactly one Approve, and it is this office's.
       *
       * Six visits are on this filing and every one of them is drawn on this
       * screen, but `canAct` offers the pair only for the reader's own
       * department — so a count of one IS the isolation assertion, made from six
       * different seats. The name says whose visit it decides, because a column
       * of buttons all called "Approve" is a list a screen-reader user cannot
       * navigate.
       */
      const approve = officePage.getByRole('button', { name: /^Approve the .+ inspection$/ })
      await expect(
        approve,
        `${office.code} is offered a number of inspection decisions other than its own one`,
      ).toHaveCount(1)

      await approve.click()
      // The card flips to the passed state in place; nothing navigates.
      await expect(officePage.getByText('Inspection Passed').first()).toBeVisible({
        timeout: 30_000,
      })
    })
  }

  const cleared = await filing(page, 'public', appId)

  /*
   * ── Every clearance is in, and the filing is NOT approved ─────────────────
   *
   * This asserted `approved` here, because the last passing visit used to run
   * `approveAndIssue` and mint the whole set in one go. It does not: a passing
   * visit calls `grantClearance`, which issues THAT office's permit and then
   * asks `refreshReadiness` whether every required permit is now approved. It
   * is, so the filing moves to `for_final_approval` — and stops, because
   * granting the Mayor's Permit is BPLO's own act and nobody else's.
   */
  expect(
    cleared.status,
    'every clearance approved, so the filing belongs with BPLO for final approval',
  ).toBe('for_final_approval')

  /*
   * Five permits, one per clearance, and NOT the Mayor's Permit. Asserting the
   * absence is the load-bearing half: a business permit issued here would be
   * one the LGU never signed, and it would look exactly like success.
   */
  expect(
    cleared.permits.map((p) => p.permit_type?.code ?? '(untyped)').sort(),
    'the issued permits are not one per clearance',
  ).toEqual(INSPECTING.map((o) => o.permit).sort())
  expect(
    cleared.permits.map((p) => p.permit_type?.code),
    'the Mayor’s Permit was issued before BPLO approved the application',
  ).not.toContain('BUSINESS')

  // Every visit conducted and passed, with nothing left open behind them — an
  // outstanding visit beside an issued permit is a certificate granted over an
  // inspection nobody performed.
  expect(cleared.inspections).toHaveLength(INSPECTING.length)
  for (const visit of cleared.inspections) {
    expect(visit.conducted_at, `${visit.department?.code}'s visit was never conducted`).not.toBeNull()
    expect(visit.result, `${visit.department?.code}'s visit did not pass`).toBe('passed')
  }

  /* ── BPLO's second act, which is the one that grants the permit ────────── */

  /*
   * The stage that did not exist before September. BPLO approved the FORM
   * before payment; this approves the APPLICATION, on the strength of five
   * clearances it can see are in, and `approveOverall` is the only place the
   * Mayor's Permit is ever minted.
   *
   * Reached through the Final Approval tab rather than For Approval, because
   * BPLO's assignment has been `completed` since it read the form and nothing
   * reopens it — its final approval is work with no open work item behind it,
   * which is why that tab carries no assignment-status filter.
   */
  await asOffice(browser, 'bplo', async (officePage) => {
    await openFromQueue(officePage, 'Final Approval', narrative)
    await approveOwnReview(officePage)
  })

  const issued = await filing(page, 'public', appId)

  expect(issued.status, 'BPLO’s final approval did not decide the application').toBe('approved')

  /*
   * ── The count, not the existence ──────────────────────────────────────────
   *
   * A permit is minted per type and `issuePermitFor` is reachable more than
   * once — a re-inspection conducted after a grant would run it again — with
   * `firstOrCreate` on (application, permit type) as the only thing standing
   * between that and a second, numbered, legally real duplicate. A duplicate
   * hides itself: `transition()` no-ops on Approved → Approved, so the status
   * never changes twice and nothing on screen reports it.
   *
   * So "permits exist" is the assertion that would pass while that bug was
   * live. This asserts the exact total, then that no permit type appears twice
   * — not redundant with the first, because six permits could be five types
   * with one doubled — and then that no NUMBER repeats.
   */
  expect(
    issued.permits,
    'the filing does not hold exactly one permit per requested permit type',
  ).toHaveLength(FILED_CODES.length)

  const byType = issued.permits.map((p) => p.permit_type?.code ?? '(untyped)').sort()
  expect(byType, 'the issued permits are not one per requested type').toEqual(
    [...FILED_CODES].sort(),
  )

  const numbers = issued.permits.map((p) => p.permit_number)
  expect(new Set(numbers).size, 'two permits were issued under one number').toBe(numbers.length)
})

/* ──────────────────────────────────────────────────────────────────────────
 * 8. The owner sees the outcome.
 * ────────────────────────────────────────────────────────────────────────── */

test('the owner is shown the approval and every permit it produced', async ({ page }) => {
  const narrative = recall()
  const { appId, trackingId, businessName } = narrative

  await page.goto(`/applications/${appId}`)
  await expect(page.getByRole('heading', { name: businessName, level: 1 })).toBeVisible({
    timeout: 30_000,
  })
  /*
   * The card, not the page. `.first()` for the same reason as the stage above:
   * the HISTORY timeline at the foot correctly still lists For Inspection and
   * every other status this filing has held, and it should — a decided filing
   * that erased how it got there would be worse. What must not be true is the
   * card at the top still announcing a stage that is over, so the card is what
   * is read.
   */
  const statusCard = page.getByText(/^(Approved|For Inspection|For Approval|Pending)$/).first()
  await expect(statusCard, 'the applicant’s status card does not announce the approval').toHaveText(
    'Approved',
  )

  /*
   * An approved filing has no next step, so it leaves Permit Tracking for
   * Profile — but it must not simply vanish, hence the pointer at the foot of
   * the list. Both halves asserted: the row is gone AND the applicant is told
   * where it went.
   */
  await page.goto('/applications')
  await expect(page.getByRole('heading', { name: 'Permit Tracking', level: 1 })).toBeVisible({
    timeout: 30_000,
  })
  await page.getByLabel(/Search your applications/).fill(trackingId)
  await expect(page.locator('li > div').filter({ hasText: businessName })).toHaveCount(0)
  await expect(page.getByText(/approved applications? (is|are) now in your/)).toBeVisible()

  /* ── The permits themselves ────────────────────────────────────────────── */

  await page.goto('/profile')
  const group = page.getByRole('button', { name: new RegExp(businessName) })
  await expect(group, 'the approved business is not listed on Profile').toBeVisible({
    timeout: 30_000,
  })
  await group.click()

  /*
   * One row per permit, found by the accessible name the row was given so that
   * the eye and the download arrow say which of seven identical icons they
   * belong to: "Sanitary Permit for ‹business› (MCB-2026-000406)".
   *
   * Counted per type, and each expected exactly once. Reading the panel's total
   * alone would pass on seven rows that were five types and one doubled, which
   * is precisely what the duplicate-issuance bug produced.
   */
  const rows = page.getByRole('link', { name: new RegExp(`^View .+ for ${businessName} \\(`) })
  await expect(rows, 'the owner is not shown one row per issued permit').toHaveCount(
    FILED_CODES.length,
  )

  const names = await rows.evaluateAll((els) => els.map((e) => e.getAttribute('aria-label') ?? ''))
  expect(new Set(names).size, 'two permit rows carry the same accessible name').toBe(names.length)
})

test('the approved filing offers the permits it produced, not one of them', async ({ page }) => {
  const narrative = recall()
  const { appId } = narrative

  /*
   * ── The regression guard for a defect this test reported, now fixed ───────
   *
   * The filing issues seven certificates. Its own screen used to offer one.
   *
   * `ApplicationDetailPage` did `const issuedPermit = app.permits[0]` and drew
   * a single eye chip and a single download arrow, both labelled "Business
   * Permit" and "Download Business Permit" as literal strings — not from
   * `permit_type.name`. Two things were wrong at once and only one of them was
   * cosmetic:
   *
   *  - six of the seven certificates the applicant paid for had no route from
   *    the filing that produced them. They were on Profile, so nothing was
   *    lost; but the screen that says "Approved" is the screen an applicant goes
   *    to, and it presented the outcome as a single document;
   *  - the one it did offer was named unconditionally. `permits[0]` is whatever
   *    `approveAndIssue` inserted first, which is the filing's permit-type order
   *    and not a guarantee. The first filing whose order put a clearance ahead
   *    of the Mayor's Permit served a Sanitary Permit under a link that read
   *    "Download Business Permit" — a certificate under another certificate's
   *    name, which on a legal instrument is not a label problem.
   *
   * The card now maps every permit and takes each name from `permit_type.name`.
   * Asserted as a count rather than by inspecting the label, because the count
   * is the user-visible claim: seven were issued, so seven should be reachable.
   */
  await page.goto(`/applications/${appId}`)
  await expect(page.getByText(/^Approved$/).first()).toBeVisible({ timeout: 30_000 })

  /*
   * Counted by DESTINATION, not by control: the card draws an eye chip and a
   * download arrow, so two links point at the same certificate. What is being
   * asserted is how many of the seven are reachable at all.
   */
  const offered = await page
    .locator('a[href^="/permits/"]')
    .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href') ?? ''))])
  const labels = await page
    .locator('a[href^="/permits/"]')
    .evaluateAll((els) => els.map((e) => e.getAttribute('aria-label') ?? e.textContent?.trim() ?? ''))

  expect(
    offered.length,
    `the approved filing offers ${offered.length} of its ${FILED_CODES.length} permits, ` +
      `under the labels: ${labels.join(' / ')}`,
  ).toBe(FILED_CODES.length)
})
