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
 * Longer than the 30s default, because a stage here is seven offices.
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

/** Every permit code on the filing — BPLO's plus the six clearances. */
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
  tab: 'For Approval' | 'For Inspection',
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
 * the FILING, so only the first of the seven offices ever finds work here.
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

test('an owner files a business permit with all six clearances behind it', async ({ page }) => {
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
            barangay_id: barangays[0].id,
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

  await map.getByRole('button', { name: /review & submit/i }).click()
  await page.getByRole('button', { name: /^submit$/i }).click()
  await page.getByRole('button', { name: /^proceed$/i }).click()
  await expect(page.getByText(/tracking/i).first()).toBeVisible({ timeout: 30_000 })

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
  // Payment is what is left to do, and it is what opens the clearance stage
  // (docs/clearances-after-payment.md).
  expect(filed.status).toBe('pending_payment')

  /*
   * The mayor's permit, alone. This asserted all seven codes while the
   * clearances were chosen in the wizard; they cannot be on the filing at this
   * point any more, because the stage that attaches them does not open until
   * the payment below has cleared. The full set is asserted in test 4, after
   * the applies that really put them there.
   */
  const codes = filed.permit_types.map((pt) => pt.code).sort()
  expect(codes, 'a clearance reached the filing before it was paid for').toEqual(['BUSINESS'])
})

/* ──────────────────────────────────────────────────────────────────────────
 * 2. The owner's Track page, awaiting payment.
 * ────────────────────────────────────────────────────────────────────────── */

test('the filing shows on Track as awaiting payment', async ({ page }) => {
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
   * The one control on the collapsed row, and the whole point of the stage:
   * money is owed. The green "Paid" block is the same slot, so asserting Pay
   * Online is present is not the same as asserting Paid is absent — both are
   * made, because the row rendering both would be the failure nobody looks for.
   */
  await expect(row.getByRole('link', { name: 'Pay Online' })).toBeVisible()
  await expect(row.getByText('Paid', { exact: true })).toHaveCount(0)
})

/* ──────────────────────────────────────────────────────────────────────────
 * 3. The gap between Submit and Pay.
 * ────────────────────────────────────────────────────────────────────────── */

test('before payment the filing is routed to nobody, and no office queue holds it', async ({
  browser,
}) => {
  const narrative = recall()
  const { appId, trackingId } = narrative

  /*
   * Assignments are created by `WorkflowService::routeToDepartments`, whose
   * only caller is `onPaymentCompleted`. So between Submit and Pay the filing
   * is routed to nobody at all, and this test states what that costs each seat
   * rather than assuming it is invisible.
   *
   * BPLO first, because BPLO is the seat that CAN see the stage: it holds
   * `application.view_any_office`, so `ApplicationVisibility` does not narrow
   * it to filings it has an assignment on, and the queue gives it a Pending
   * Payment tab fed from `/applications` rather than `/assignments`.
   */
  await asOffice(browser, 'bplo', async (page) => {
    await page.goto('/staff/queue')
    await expect(
      page.getByRole('heading', { name: 'Application Verification', level: 1 }),
    ).toBeVisible({ timeout: 30_000 })

    await page.getByRole('button', { name: 'Pending Payment' }).click()
    await page.getByRole('searchbox', { name: /Search this queue/ }).fill(trackingId)
    await expect(page.getByText(trackingId).first()).toBeVisible({ timeout: 20_000 })

    /*
     * And it says why there is nothing to press. A row an officer cannot open
     * has to explain itself or it reads as a broken link — there is no
     * assignment, so there is no review sheet, so there is no
     * `/staff/queue/:id` to point at.
     */
    await expect(page.locator('a[href^="/staff/queue/"]')).toHaveCount(0)
    await expect(page.getByText(/Waiting on the applicant’s payment/).first()).toBeVisible()
  })

  /*
   * Every other office: the filing does not exist yet, in either tab, and the
   * Pending Payment tab is not offered at all.
   *
   * The hidden tab is the product being honest rather than a gap: an office
   * reviewer's boundary IS the assignment row, so a Pending Payment tab in
   * these six seats could only ever be empty, and an empty queue is a claim.
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
          `${office.code} has an unpaid filing in its ${tab} tab, but nothing was routed to it`,
        ).toHaveCount(0, { timeout: 20_000 })
      }

      // Nothing is routed, so nothing is readable — the API says the same thing
      // the queue does, which is what stops a deep link being the way round it.
      const status = await page.evaluate(async (id) => {
        const token = localStorage.getItem('biztrack.token.staff')
        const res = await fetch(`/api/v1/applications/${id}`, {
          headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        })
        return res.status
      }, appId)
      expect(
        [403, 404],
        `${office.code} can open an unpaid filing it was never routed`,
      ).toContain(status)
    })
  }
})

test('an unpaid filing does not tell the applicant an office is reviewing it', async ({ page }) => {
  const narrative = recall()
  const { trackingId, businessName } = narrative

  /*
   * ── DEFECT. This test is expected to FAIL, and must not be weakened. ──────
   *
   * Nothing has been routed anywhere: `routeToDepartments` has not run, the
   * filing has no assignment, and the previous test proved six of the seven
   * offices cannot so much as open it. The applicant's own screen says the
   * opposite.
   *
   * Expanding the row on Permit Tracking draws one chip per permit type, and
   * for `pending_payment` both `permitChip()` and `fallbackChip()` fall through
   * their branches to the same default:
   *
   *     return { tone: 'orange', label: 'For Approval' }
   *
   * The defect is SMALLER than it was, and no less wrong. It used to be shown
   * seven times over, because the wizard attached all six clearances before
   * submission; an unpaid filing now carries the mayor's permit alone, so there
   * is one chip and one lie on it. The clearances get their chips when they are
   * applied for, which is after payment, so they can never be in this state.
   *
   * "For Approval" is not a spare word. It is `ApplicationStatus::UnderReview`'s
   * own label — the exact string the LGU uses for a filing the offices are
   * reading — and `status.ts` is held character-for-character in step with the
   * PHP enum by `StatusLabelParityTest` precisely so that one state never
   * answers to two names. Here one NAME is answering for two states: the
   * applicant is shown, seven times over, that their filing is with the
   * offices, while the same row carries an orange Pay Online button saying it
   * has not started.
   *
   * The cost is not cosmetic. An applicant who reads "For Approval" has no
   * reason to pay, and an unpaid filing is routed to nobody for as long as they
   * wait — the one state in this product where the applicant is the only person
   * who can move it and the screen tells them somebody else already is.
   *
   * The assertion is on the label, not on the tone, because the label is what a
   * screen reader reads out and what the client will read on the demo.
   */
  await page.goto('/applications')
  await page.getByLabel(/Search your applications/).fill(trackingId)

  const row = page.locator('li').filter({ hasText: businessName }).first()
  await row.getByRole('button', { expanded: false }).first().click()

  /*
   * One chip, for the mayor's permit. This read `FILED_CODES.length` — all
   * seven — which was right while the wizard attached the six clearances before
   * submission and is now wrong: nothing but the business permit can be on an
   * unpaid filing.
   *
   * Corrected rather than relaxed, and the distinction matters on a test whose
   * whole point is to keep failing. Left at seven it went red on THIS line, on
   * a setup fact nobody is arguing about, and the defect below — the one the
   * comment above is about — was never reached. A test that fails for the wrong
   * reason is a test that stops reporting the right one.
   */
  const chips = await row.locator('ul > li').allInnerTexts()
  expect(chips.length, 'the expanded row should draw one chip per permit type').toBe(1)
  for (const chip of chips) {
    expect(
      chip,
      'an unpaid filing is routed to no office, so no row may say "For Approval"',
    ).not.toContain('For Approval')
  }
})

/* ──────────────────────────────────────────────────────────────────────────
 * 4. Payment routes it.
 * ────────────────────────────────────────────────────────────────────────── */

test('paying moves the filing into review and into every routed office’s queue', async ({
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
  expect(justPaid.status, 'payment did not move the filing out of pending_payment').toBe(
    'under_review',
  )

  /*
   * ── And NOW the six clearances, which is the reordering ──────────────────
   *
   * These applies used to run in test 1, before submission, because the six
   * were chosen inside the wizard. They run here because that is the first
   * moment an applicant can reach them: the clearance stage is locked until the
   * first payment clears (docs/clearances-after-payment.md), so this loop is
   * the narrative's version of the applicant opening the stage that has just
   * unlocked and pressing Apply six times.
   *
   * Through the endpoint the card presses, not by attaching permit types on an
   * update. Those look equivalent and are not: `ClearanceService::apply` re-runs
   * `FeeCalculator::assess`, which is what makes each clearance's fee accrue
   * onto the balance the permit is withheld against.
   */
  await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    }
    for (const code of ['SANITARY', 'FSIC', 'ZONING', 'OCCUPANCY', 'CEC', 'MARKET']) {
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
      MARKET: { market_name: 'Malabon Central Market', stall_no: 'A-12' },
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

  /* ── And the bill those six just raised ────────────────────────────────── */

  /*
   * The second of the two moments money changes hands
   * (docs/clearances-after-payment.md, "one ledger, two moments"): the business
   * permit is paid to submit, and every clearance applied for afterwards
   * re-assesses onto the same FeeAssessment, so a balance appears behind a
   * filing that is already under review.
   *
   * This is a step of the narrative and not bookkeeping. Rule 6 of that doc —
   * "the permit is not released while a balance is outstanding" — is enforced by
   * WorkflowService::isFullyCleared, which answers false on an unsettled filing
   * whatever the offices have done. Leave it unpaid and every office signs off,
   * every visit passes, and the filing sits in `for_inspection` with no permits
   * and nothing left in the product that will ever look at it again except
   * another payment. That is the gate working; a narrative that never pays is
   * simply not the journey an applicant takes.
   *
   * Through the same screen as the first payment, because it IS the same screen:
   * `/pay` re-reads the assessment, so the Tax Order of Payment now shows the
   * clearance lines, and PaymentController::pay charges the BALANCE rather than
   * the total precisely so that pressing it twice does not bill the mayor's
   * permit again.
   */
  await page.goto(`/applications/${appId}/pay`)
  await expect(page.getByRole('heading', { name: 'Tax Order of Payment' })).toBeVisible({
    timeout: 30_000,
  })
  await page.getByRole('button', { name: 'Pay Online' }).click()
  await expect(
    page.getByText('Paid', { exact: true }),
    'the clearances’ balance could not be settled, so no permit can ever be released',
  ).toBeVisible({ timeout: 30_000 })

  const paid = await filing(page, 'public', appId)

  /*
   * Settling a balance is not a decision about the filing. It was already under
   * review when the six were applied for, and paying for them leaves it there —
   * `onPaymentCompleted` only routes and transitions on the FIRST payment.
   */
  expect(paid.status, 'settling the clearance balance moved the filing').toBe('under_review')

  // Every clearance applied for is on the filing, alongside the mayor's permit.
  expect(
    paid.permit_types.map((pt) => pt.code).sort(),
    'the filing did not carry the mayor’s permit and all six clearances',
  ).toEqual([...FILED_CODES].sort())

  /*
   * One assignment per office that issues a permit type on the filing, and
   * every one of them still pending. This is the state the whole of test 5
   * measures movement against, so it is pinned before anybody acts.
   *
   * Note what routes them. It was the payment alone — `onPaymentCompleted` ran
   * `routeToDepartments` over whatever the wizard had attached. Each clearance
   * now routes to its own office when it is APPLIED FOR (rule 7 of the doc), so
   * this set is the product of the loop above rather than of the click before
   * it. Asserted after both, because the narrative needs the same end state
   * either way.
   */
  expect(
    paid.assignments.map((a) => a.department.code).sort(),
    'the filing was not routed to every office on it',
  ).toEqual([...OFFICES.map((o) => o.code)].sort())
  for (const office of OFFICES) {
    expect(assignmentOf(paid, office.code), `${office.code}'s review is not open`).toBe('pending')
  }

  // Nothing is booked yet: a visit follows an office's approval, not a payment.
  expect(paid.inspections, 'a visit was booked before any office had read the filing').toHaveLength(0)

  /* ── And each office can now find it, in its own queue ─────────────────── */

  for (const office of OFFICES) {
    await asOffice(browser, office.account, async (officePage) => {
      await openFromQueue(officePage, 'For Approval', narrative)
      /*
       * The row opens on the review sheet with a live decision behind Edit.
       * Presence, not a press: this test is about routing, and test 5 is about
       * what happens when one of these is pressed.
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
   * `application_status_counts: { returned: 1, under_review: 2 }`, which sums to
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
   * BPLO goes first, on purpose.
   *
   * Its permit type is the only one on the filing with `requires_inspection`
   * false, so its approval books no visit and `afterReviewProgress` leaves the
   * filing exactly where it was. That makes "the application itself has NOT
   * advanced" a clean, unambiguous assertion — the filing is still
   * `under_review` and the only thing in the register that changed is one
   * assignment row.
   *
   * It is also the seat of the client's report 1: BPLO approved and the row
   * disappeared from both queue tabs, because For Approval had dropped it (its
   * assignment was complete) and For Inspection did not want it (the filing was
   * still under review). That is asserted below, from BPLO's own session.
   */
  await asOffice(browser, 'bplo', async (officePage) => {
    await openFromQueue(officePage, 'For Approval', narrative)
    await approveOwnReview(officePage)
  })

  const afterBplo = await filing(page, 'public', appId)

  expect(assignmentOf(afterBplo, 'BPLO'), 'BPLO’s own review did not complete').toBe('completed')
  for (const office of OFFICES.filter((o) => o.account !== 'bplo')) {
    expect(
      assignmentOf(afterBplo, office.code),
      `BPLO approving moved ${office.code}'s review, which is not BPLO's to move`,
    ).toBe('pending')
  }

  /*
   * The filing itself has not moved, and neither has anything downstream of it.
   * All three are asserted rather than just the status: a permit minted early
   * would be a status that never changed and a certificate that exists anyway,
   * which is the shape of the duplicate-issuance bug test 7 guards.
   */
  expect(afterBplo.status, 'one office’s approval advanced the whole filing').toBe('under_review')
  expect(afterBplo.inspections, 'BPLO does not inspect, so it must book no visit').toHaveLength(0)
  expect(afterBplo.permits, 'a permit was issued on one office’s approval').toHaveLength(0)

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
    page.getByText('For Approval', { exact: true }).first(),
    'the applicant’s status card should still read For Approval',
  ).toBeVisible()

  /*
   * "Approved" may not be anywhere at all yet, history included — one office of
   * seven has signed off, so no reading of this filing has ever been approved
   * and the timeline must not claim one. This is the strong form of the
   * assertion and it is available here precisely because the history is short.
   */
  await expect(
    page.getByText('Approved', { exact: true }),
    'one office signing off told the applicant the filing was approved',
  ).toHaveCount(0)

  /* ── BPLO's row is still findable, one tab across (INS-2) ──────────────── */

  await asOffice(browser, 'bplo', async (officePage) => {
    /*
     * "I approved it as BPLO and it is not in For Inspection", verbatim. The
     * rule the tabs answer to now is THIS OFFICE'S outstanding work, not the
     * filing's global status: BPLO's review is closed and the filing is not
     * finished, which is what For Inspection means from this seat even though
     * BPLO never inspects anything.
     */
    await openFromQueue(officePage, 'For Inspection', narrative)

    await officePage.goto('/staff/queue')
    await officePage.getByRole('button', { name: 'For Approval' }).click()
    await officePage.getByRole('searchbox', { name: /Search this queue/ }).fill(trackingId)
    await expect(
      officePage.locator('a[href^="/staff/queue/"]').filter({ hasText: trackingId }),
      'a completed review is still being offered to BPLO as outstanding work',
    ).toHaveCount(0, { timeout: 20_000 })
  })

  /* ── And every other office still has its own Approve (INS-1) ──────────── */

  for (const office of OFFICES.filter((o) => o.account !== 'bplo')) {
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

test('the first inspecting office books its own visit and nobody else’s', async ({
  page,
  browser,
}) => {
  const narrative = recall()
  const { appId } = narrative

  /*
   * The second half of the isolation claim, and the harder half.
   *
   * Since 5da4daa the first inspecting office's approval flips the WHOLE filing
   * to `for_inspection` while the other five assignments are still pending —
   * deliberately, so City Health need not wait for the Market Office to open
   * its form. That is exactly the condition under which the review screen used
   * to branch on the filing's status and hand five offices a page with no
   * controls on it (INS-1): a deadlock no action in the product could clear.
   *
   * So this approves as one inspecting office and then goes looking, from five
   * separate sessions, for the button that used to vanish.
   */
  const first = INSPECTING[0]

  await asOffice(browser, first.account, async (officePage) => {
    await openFromQueue(officePage, 'For Approval', narrative)
    await approveOwnReview(officePage)
  })

  const after = await filing(page, 'public', appId)

  expect(after.status, 'an inspecting office’s approval should open the inspection stage').toBe(
    'for_inspection',
  )
  expect(assignmentOf(after, first.code), `${first.code}'s own review did not complete`).toBe(
    'completed',
  )

  /*
   * One visit, and it belongs to the office that approved. A booking loop that
   * ran over the filing rather than over the office would show up here as six.
   */
  expect(
    after.inspections.map((i) => i.department?.code),
    'approving one office’s review booked more than that office’s visit',
  ).toEqual([first.code])

  // Still nothing issued: the filing is one office of seven through its reviews.
  expect(after.permits, 'permits were issued before six offices had read the filing').toHaveLength(0)

  /* ── The five offices that still owe a review can still reach it ───────── */

  const owing = OFFICES.filter((o) => o.account !== 'bplo' && o.account !== first.account)
  for (const office of owing) {
    await asOffice(browser, office.account, async (officePage) => {
      /*
       * For Approval, not For Inspection, and that is the assertion rather than
       * a navigation detail. The filing's status now says `for_inspection`; this
       * office's assignment does not, and the tab that holds the row has to be
       * the one matching the office's own outstanding work — otherwise the
       * paperwork is filed under a heading about site visits and searching For
       * Approval for it answers "Nothing matches", which is the client's report 4.
       */
      await openFromQueue(officePage, 'For Approval', narrative)
      await officePage.getByRole('button', { name: 'Edit', exact: true }).click()
      await expect(
        officePage.getByRole('button', { name: 'Approve', exact: true }),
        `${office.code} has no Approve on a for_inspection filing it still owes a review on`,
      ).toBeVisible()
    })
  }
})

test('approving is confirmed on screen whichever way the filing then moves', async ({ browser }) => {
  const narrative = recall()
  const second = INSPECTING[1]

  /*
   * ── The regression guard for a defect this test reported, now fixed ───────
   *
   * Approving a review ends in a VERIFICATION dialog — "Approval recorded",
   * then Home Page or Tracking Page. That is the whole of the officer's
   * feedback that the decision landed, and it is the only thing on the screen
   * that says so.
   *
   * It used to appear when BPLO approved and not when any of the six clearance
   * offices did, and the difference had nothing to do with the offices. The
   * modal was the last thing in the review SHEET's own JSX; approving calls
   * `reload()`, and the reloaded filing sent the sheet down its early return —
   * `if (app.status === 'for_inspection' && !owesReview)` — which draws the
   * compact inspection box and returns before the modal is ever reached. A
   * clearance office's approval is exactly what makes both halves of that
   * condition true, so its own confirmation was unmounted by its own success,
   * and the officer with the most consequential approval in the flow was the
   * one told nothing.
   *
   * The fix was not to teach that branch to draw the dialog too. `ReviewPage`
   * now owns `showVerification` and renders the modal as a SIBLING of the whole
   * sheet, so no `return` inside `ReviewSheet` — including the next one somebody
   * adds — can take it down. This test is what stops it moving back inside.
   *
   * Asserted on the second inspecting office rather than the first, so that the
   * approval is a real step of the narrative: this office's review is now in,
   * and the stage below expects it.
   */
  await asOffice(browser, second.account, async (page) => {
    await openFromQueue(page, 'For Approval', narrative)
    await page.getByRole('button', { name: 'Edit', exact: true }).click()

    const [response] = await Promise.all([
      page.waitForResponse(
        (r) => /\/assignments\/\d+\/approve$/.test(r.url()) && r.request().method() === 'POST',
        { timeout: 30_000 },
      ),
      page.getByRole('button', { name: 'Approve', exact: true }).click(),
    ])
    expect(response.status(), `${second.code}'s approval was refused`).toBe(200)

    await expect(
      page.getByRole('dialog', { name: 'VERIFICATION' }),
      `${second.code} approved and was given no confirmation that anything happened`,
    ).toBeVisible({ timeout: 10_000 })
    await page.getByRole('button', { name: 'Tracking Page' }).click()
  })
})

/* ──────────────────────────────────────────────────────────────────────────
 * 6. Everyone approves.
 * ────────────────────────────────────────────────────────────────────────── */

test('once every office has approved, a visit is booked for each one that inspects', async ({
  page,
  browser,
}) => {
  const narrative = recall()
  const { appId } = narrative

  /*
   * The four still outstanding. BPLO and the first two inspecting offices have
   * already approved in the stages above — the second of them inside the
   * defect test, whose approval landed whether or not it was confirmed on
   * screen — and asking a completed assignment to approve again would find no
   * Approve button and report a missing control that is correctly missing.
   */
  const done = new Set<string>(['bplo', INSPECTING[0].account, INSPECTING[1].account])
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
   * the strength of the six clearances; a visit of its own would be one nobody
   * performs, and `isFullyCleared` would then wait on it forever — the filing
   * could never be issued by any action the product offers.
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

  expect(all.status, 'every review in, so the filing belongs at the inspection stage').toBe(
    'for_inspection',
  )
  expect(all.permits, 'permits were issued before a single visit had happened').toHaveLength(0)
})

/* ──────────────────────────────────────────────────────────────────────────
 * 7. The visits pass, and the permits are issued.
 * ────────────────────────────────────────────────────────────────────────── */

test('every visit passing issues exactly one permit per requested permit type', async ({
  page,
  browser,
}) => {
  const narrative = recall()
  const { appId } = narrative

  for (const office of INSPECTING) {
    await asOffice(browser, office.account, async (officePage) => {
      /*
       * This office's review is closed and the filing is `for_inspection`, so
       * the review sheet is gone and ReviewPage opens on the compact decision
       * box — the shape the client asked for by name ("it should just be like
       * the other ones where its just a box").
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

  const issued = await filing(page, 'public', appId)

  expect(issued.status, 'every review in and every visit passed, so the filing is approved').toBe(
    'approved',
  )

  /*
   * ── The count, not the existence ──────────────────────────────────────────
   *
   * `approveAndIssue` mints one Permit row per permit type on the filing and is
   * reachable from two directions — the last office's review
   * (`afterReviewProgress`) and the last passing visit (`recordInspection`) —
   * with `isFullyCleared` as the only thing standing between the two and a
   * second full set. A duplicate run writes REAL, numbered certificates and then
   * hides itself, because `transition()` no-ops on Approved → Approved and the
   * status never changes twice.
   *
   * So "permits exist" is the assertion that would have passed while that bug
   * was live. This asserts the exact total, and then that no permit type
   * appears twice — the second check is not redundant with the first, because
   * seven permits could still be six types with one doubled.
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

  // Every visit conducted and passed, with nothing left open behind the
  // approval — an outstanding visit on an approved filing is a permit issued
  // over an inspection nobody performed.
  expect(issued.inspections).toHaveLength(INSPECTING.length)
  for (const visit of issued.inspections) {
    expect(visit.conducted_at, `${visit.department?.code}'s visit was never conducted`).not.toBeNull()
    expect(visit.result, `${visit.department?.code}'s visit did not pass`).toBe('passed')
  }
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
