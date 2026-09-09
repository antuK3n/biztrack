import { expect, test, type Browser, type Page } from '@playwright/test'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { OFFICES, sessionFor } from './helpers'

/*
 * One filing, from the owner pressing Submit to the permits landing in their
 * Profile, walked through the browser in the order a real one happens.
 *
 * ── The order it happens in, since 6 September 2026 ─────────────────────────
 *
 * This file used to walk a flow that no longer exists — pay, then seven offices
 * review in parallel, then every permit is released together against a zero
 * balance. `docs/application-flow-2026-09.md` replaced it, and the shape the
 * client verified against the counter procedure is the one below:
 *
 *   draft → for_approval        the owner submits; BPLO alone is routed
 *          → pending_payment     BPLO has READ the form; now it is payable
 *          → awaiting_other_permits   one payment, covering everything
 *          → for_final_approval  all five other permits approved
 *          → approved            BPLO signs, and the Mayor's Permit is minted
 *
 * Three of those edges are the reversal, and each one falsified a test here:
 *
 *  - **BPLO reads the form BEFORE any money is asked for.** `submit()` lands on
 *    `for_approval`, not `pending_payment`, and the bill becomes payable only
 *    when BPLO says the form is fit to be paid for.
 *  - **Payment routes nobody.** It opens the other five permits; each office is
 *    routed one at a time, when the APPLICANT starts that permit. A filed and
 *    paid filing has BPLO as its only assignment.
 *  - **There is no balance.** One Tax Order of Payment is assessed at
 *    submission and covers every permit; applying for a clearance adds nothing.
 *    A permit is released by its own office's inspection passing, not by a
 *    balance reaching zero.
 *
 * ── Why this is one narrative and not a dozen independent tests ────────────
 *
 * Every other spec in this suite finds a fixture in the register and asserts
 * one screen against it. That is the right shape for a screen and the wrong
 * shape for a LIFECYCLE, because the defects this file exists to catch are all
 * of the form "state moved here and did not move there":
 *
 *   - an office approving and the others losing their Approve button
 *     (INS-1, a client report — a shared branch keyed on the FILING's status
 *     rather than on the reading office's own assignment);
 *   - a filing approved by one office and vanishing from both queue tabs
 *     (INS-2, also a client report);
 *   - a permit minted twice, which `transition()` then hides by no-opping
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
 * it and read a screen — six times over against a dev server that compiles on
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
 * filing the remaining stages are about, and reports a dozen more failures that
 * are all the same one wearing a different hat — which is precisely the outcome
 * the "not serial" note above exists to avoid.
 *
 * Four facts, written once by the first test and read by every test after it.
 * `billedAtSubmission` is one of them because "there is no fee accrual any
 * more" is a claim about two numbers taken at two different moments, and the
 * first of them is only observable in the first test.
 */
interface Narrative {
  appId: number
  trackingId: string
  businessName: string
  billedAtSubmission: number
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

/** Every permit code on the filing — BPLO's, plus the five clearances. */
const FILED_CODES = OFFICES.map((o) => o.permit)

/**
 * The five clearance offices: everyone but BPLO.
 *
 * This was `INSPECTING`, filtered on `o.inspects`, and the two lists are now
 * the same list — every one of the five required clearances is inspected, and
 * BPLO is the only office that never books a visit. Named for what it IS rather
 * than for the property it is selected by, because the interesting thing about
 * these five is no longer that they inspect: it is that each is routed
 * separately, when the applicant reaches it.
 */
const CLEARANCES = OFFICES.filter((o) => o.account !== 'bplo')

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
 * exist", "which offices hold an assignment and in what state", "what is each
 * permit's own status". Those are counts, and a screen that renders five of six
 * rows looks exactly like a screen that renders six. Everything a user could
 * see is asserted on the screen; this is for the arithmetic behind it.
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
        tracking_id: string
        permits: { permit_type?: { code: string } | null; permit_number: string }[]
        permit_types: { code: string; status: string | null }[]
        assignments: { status: string; department: { code: string } }[]
        inspections: {
          id: number
          status: string
          result: string | null
          conducted_at: string | null
          department: { code: string } | null
        }[]
        fee_assessment: { total_amount: number | string } | null
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

/** One permit's own `ClearanceStatus` on the filing, or undefined if unattached. */
function permitState(
  app: Awaited<ReturnType<typeof filing>>,
  code: string,
): string | null | undefined {
  return app.permit_types.find((pt) => pt.code === code)?.status
}

/** The one bill, as a number. Assessed once, at submission, for everything. */
function billed(app: Awaited<ReturnType<typeof filing>>): number {
  return Number(app.fee_assessment?.total_amount ?? 0)
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
 * is belt and braces on purpose: the business name is unique per run, so it
 * picks out this narrative's row among a register that holds several filings
 * for this owner, and the tracking ID proves the SEARCH is what put it there.
 *
 * Returns the assignment id the row pointed at, so a caller can say which row
 * it opened when an assertion downstream fails.
 */
async function openFromQueue(
  page: Page,
  tab: 'For Approval' | 'For Inspection' | 'Pending Payment' | 'Final Approval',
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
 * the FILING, so only BPLO — the first office to read it, and now the only one
 * that reads it before payment — ever finds work here.
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
 * is one press by BPLO and a no-op for the five after it.
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

  // Dismissed only if it is there. See the confirmation test for when it is not.
  const dialog = page.getByRole('dialog', { name: 'VERIFICATION' })
  if (await dialog.isVisible()) {
    await page.getByRole('button', { name: 'Tracking Page' }).click()
  }
}

/**
 * Book this office's first site visit on one permit, and pick the date.
 *
 * ── Through the API, and this is a finding rather than a shortcut ───────────
 *
 * `POST /applications/{id}/permits/{code}/inspection` is the endpoint the
 * client's step "Select Inspection Date and Approve Inspection" was built for,
 * and it is the only way a first visit comes into existence now that the
 * automatic two-working-days scheduler is gone. **The web has no client for
 * it.** `web/src/lib/resources.ts` offers `conduct`, `reschedule` and
 * `reinspect` — all three act on a visit that already exists — and nothing
 * calls this route at all, so `InspectionDecisionPanel` renders its empty state
 * on a permit that is For Inspection with no visit, and there is no control on
 * any screen that would create one.
 *
 * That is a real gap in the product and not this file's to fix. It is driven
 * here so the rest of the narrative can be walked, and it is named in a test of
 * its own — "an office that has accepted the paperwork can book its own visit"
 * — so the gap is reported once, by a test that goes red for it, rather than
 * being silently papered over at five separate stages.
 */
async function bookVisit(page: Page, appId: number, code: string) {
  const status = await page.evaluate(
    async ([id, permitCode]) => {
      const token = localStorage.getItem('biztrack.token.staff')
      const when = new Date(Date.now() + 3 * 86_400_000).toISOString()
      const res = await fetch(`/api/v1/applications/${id}/permits/${permitCode}/inspection`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ scheduled_at: when }),
      })
      if (!res.ok) throw new Error(`booking the ${permitCode} visit answered ${res.status}: ${await res.text()}`)
      return res.status
    },
    [appId, code] as const,
  )
  expect(status, `booking the ${code} visit`).toBe(201)
}

/* ──────────────────────────────────────────────────────────────────────────
 * 1. The owner files.
 * ────────────────────────────────────────────────────────────────────────── */

test('an owner files, and the form goes to BPLO alone with one bill behind it', async ({ page }) => {
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
             * is Acacia, about 1.5 km away. The zoning step refuses a pin that
             * contradicts its barangay — and the map is locked until a BARANGAY
             * is chosen, with a change of barangay clearing the pin — so the old
             * pairing left this filing permanently stuck on section 2 with no
             * way to submit.
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
          /*
           * The first of the two gates at submit, in order: RA 10173 consent,
           * then blocked-business. `ApplicationController::submit` refuses a
           * filing whose `data_privacy_consent` is not true before it looks at
           * anything else, and the field lives on the APPLICATION rather than on
           * the submit request — so a fixture that only ticks the wizard's
           * checkbox has satisfied the screen and not the server.
           */
          data_privacy_consent: true,
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
     * The clearances are NOT applied for here, and cannot be.
     *
     * `startClearance` refuses a filing that is not paid, and payment is two
     * transitions away. They are attached to the filing at submission all the
     * same — `attachRequiredPermitTypes` runs once, so the one bill can price
     * every permit the filing will ever need — but attached is not begun: each
     * sits at `not_started` until the applicant opens it in the clearance
     * stage, which is test 6.
     */

    return { id: app.id as number, name }
  })

  const appId = created.id
  const businessName = created.name

  /* ── Submit, for real, from the wizard's last step ────────────────────── */

  await page.goto(`/apply?draft=${appId}`)

  /*
   * The consent checkbox is NOT ticked here, and that is the assertion rather
   * than an omission.
   *
   * It was ticked, back when the field did not exist and the wizard's `consent`
   * was browser state alone. `data_privacy_consent` is a column on the
   * application now — the first of the two gates at submit, ahead of
   * blocked-business — so it was set in the payload above, and the wizard
   * hydrates its own checkbox from it. Section 1 therefore opens already
   * complete, the checkbox is not on screen at all, and `.check()` on it waited
   * fifteen seconds for a control the product was right not to draw.
   *
   * Submit being enabled below is what proves the wizard read the field: the
   * button is shut on `!consent` and says so ("Tick the Data Privacy Consent on
   * the first part before submitting").
   */
  const map = page.locator('ol[aria-label="Application sections"]')
  await expect(map).toBeVisible({ timeout: 30_000 })

  /*
   * SEVEN sections, fixed: Data Privacy Consent, Location & Zoning, Business
   * Information & Registration, Business Operation, Documentary Requirements,
   * Fees & Tax Computation, Review & Submit.
   *
   * It was `7 + 6` once — seven phases plus one office sheet per clearance —
   * and then briefly 6. Neither arithmetic exists now: the office sheets live
   * on `ClearanceStagePage`, which is a screen the applicant only reaches after
   * paying, so nothing can grow or shrink this map.
   *
   * Still asserted, because the jump below is what proves the filing is
   * complete: the map refuses a forward jump over an unfinished section, so
   * reaching Review & Submit in one click IS the statement that nothing is
   * outstanding.
   */
  await expect(map.locator('li')).toHaveCount(7)

  /*
   * The button is pressed for real, and that is a change.
   *
   * It read "Submit & Pay" for a while and raised the Tax Order of Payment and
   * settled it in one press, so this file drove the wizard to the last step and
   * then called `/submit` through the API — otherwise the unpaid middle the
   * next three tests are about would have been deleted by the click. The charge
   * has moved behind BPLO's approval and the label is "Submit" again, so the
   * press does exactly one thing and there is nothing left to work around.
   */
  const reviewStep = map.getByRole('button', { name: /review & submit/i })
  if ((await reviewStep.getAttribute('aria-current')) !== 'step') {
    await reviewStep.click()
  }
  await expect(
    reviewStep,
    'the map would not let this filing reach Review & Submit, so a section is outstanding',
  ).toHaveAttribute('aria-current', 'step')

  /*
   * And all seven sections read complete, which is the same claim made
   * positively. The map refuses a forward jump over an unfinished section, so
   * standing on the last one already implies this — but the implication is a
   * behaviour of the map, and if that behaviour ever loosens this file should
   * fail here rather than submit a half-filled form and report the confusion
   * six stages later. Seven and not six: Review & Submit counts itself, and
   * carries the mark because there is nothing left on it to answer.
   */
  await expect(
    map.getByRole('button', { name: /\(complete\)$/ }),
    'the wizard reached Review & Submit with sections still outstanding',
  ).toHaveCount(7)

  const submitButton = page.getByRole('button', { name: /^submit$/i })
  await expect(submitButton).toBeEnabled()

  const [submitted] = await Promise.all([
    page.waitForResponse(
      (r) => /\/applications\/\d+\/submit$/.test(r.url()) && r.request().method() === 'POST',
      { timeout: 30_000 },
    ),
    (async () => {
      await submitButton.click()
      await page.getByRole('button', { name: 'Proceed' }).click()
    })(),
  ])
  expect(submitted.status(), `submitting was refused: ${await submitted.text()}`).toBe(200)

  const filed = await filing(page, 'public', appId)

  remember({
    appId,
    trackingId: filed.tracking_id,
    businessName,
    billedAtSubmission: billed(filed),
  })

  expect(filed.tracking_id, 'a submitted filing always carries a tracking ID').toMatch(/^BIZ-/)

  /*
   * For Approval, not Pending Payment, and that single word is the reversal.
   * The old flow billed on submission and reviewed after payment, so an
   * applicant paid before anybody had read what they filed. BPLO reads it
   * first now, and their approval is what makes the bill payable.
   */
  expect(filed.status, 'submitting should hand the form to BPLO, unpaid').toBe('for_approval')

  /*
   * All six permit types, at submission, every one of them `not_started`.
   *
   * Attached here and never again, which is what makes one bill possible: the
   * permit set has to be final at the moment it is priced. Asserting the
   * STATUSES as well as the codes is what keeps "attached" from being read as
   * "applied for" — five of these are on the filing solely so the applicant can
   * be told what it will cost before they wait.
   */
  const codes = filed.permit_types.map((pt) => pt.code).sort()
  expect(codes, 'the filing does not carry the mayor’s permit and all five clearances').toEqual(
    [...FILED_CODES].sort(),
  )
  for (const office of OFFICES) {
    expect(
      permitState(filed, office.permit),
      `${office.permit} was begun at submission, before anybody could have applied for it`,
    ).toBe('not_started')
  }

  /*
   * One bill, raised now, covering everything. It is COMPUTED at submission
   * because the applicant is entitled to see the cost before they wait; it is
   * not due until BPLO approves.
   */
  expect(billed(filed), 'no Tax Order of Payment was raised for this filing').toBeGreaterThan(0)

  /*
   * BPLO, and nobody else. Routing the other five now would put five filings in
   * five queues that nobody can act on, and start five service-time clocks
   * against work that has not been handed over.
   */
  expect(
    filed.assignments.map((a) => a.department.code),
    'a submitted form should be routed to BPLO alone',
  ).toEqual(['BPLO'])
  expect(filed.permits, 'a permit was issued at submission').toHaveLength(0)
  expect(filed.inspections, 'a visit was booked before anybody had read the form').toHaveLength(0)
})

/* ──────────────────────────────────────────────────────────────────────────
 * 2. The owner's Track page, before the bill exists.
 * ────────────────────────────────────────────────────────────────────────── */

test('a form waiting on BPLO asks the applicant for no money yet', async ({ page }) => {
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
   * Not offered a way to pay, and this half passes: `pending` is
   * `status === 'pending_payment'`, so a form still with BPLO draws no
   * Pay Online. That is right — the bill is computed but not yet due.
   */
  await expect(
    row.getByRole('link', { name: 'Pay Online' }),
    'the applicant is asked to pay for a form BPLO has not read yet',
  ).toHaveCount(0)

  /*
   * ── DEFECT. This assertion is expected to FAIL, and must not be weakened. ──
   *
   * The pay block on a Track row is a two-way branch and nothing else:
   *
   *     {pending ? <Link …>Pay Online</Link> : <span …>Paid</span>}
   *
   * so every status that is not `pending_payment` is reported to the applicant,
   * in solid green, as PAID. A form sitting with BPLO has had nothing collected
   * against it; neither has a draft, nor one BPLO has returned for revision.
   * All three now wear the green block.
   *
   * This is the same defect the OFFICER's queue was fixed for, on the same day
   * and for the same reason, and `QueuePage` says so where it defines
   * `UNPAID_STATUSES`: "Written out rather than aliased to PAYMENT_STATUSES,
   * which is what it used to be. Those two lists answered the same question
   * while payment was the first thing that happened; they stopped agreeing when
   * BPLO's approval moved in front of it. A `for_approval` filing is unpaid and
   * would have shown a green 'Paid' chip." The officer's list learned the
   * distinction. The applicant's did not, and the applicant is the person the
   * claim is about.
   *
   * The cost is not cosmetic. An applicant told their filing is paid has no
   * reason to look for the bill when it arrives, and a green block is the
   * strongest "nothing to do here" this screen has. The fix is the same shape
   * as the queue's: draw the green block for the paid statuses rather than for
   * "not pending_payment".
   */
  await expect(
    row.getByText('Paid', { exact: true }),
    'a form BPLO has not even read yet is reported to the applicant as paid',
  ).toHaveCount(0)
})

/* ──────────────────────────────────────────────────────────────────────────
 * 3. The gap between Submit and BPLO's first approval.
 * ────────────────────────────────────────────────────────────────────────── */

test('a form with BPLO is invisible to the five clearance offices', async ({ browser }) => {
  const narrative = recall()
  const { appId, trackingId, businessName } = narrative

  /*
   * The filing is routed to exactly one office, so exactly one office can see
   * it. This states what that costs each seat rather than assuming it.
   *
   * BPLO first, and the positive half: a boundary that refuses everyone is not
   * a boundary, it is an outage.
   */
  await asOffice(browser, 'bplo', async (page) => {
    await openFromQueue(page, 'For Approval', narrative)
    await page.getByRole('button', { name: 'Edit', exact: true }).click()
    await expect(
      page.getByRole('button', { name: 'Approve', exact: true }),
      'BPLO was routed the form and has no Approve on it',
    ).toBeVisible()
  })

  /*
   * Every other office: the filing does not exist, in either tab, and neither
   * of BPLO's two coordinating tabs is offered at all.
   *
   * The hidden tabs are the product being honest rather than a gap. Pending
   * Payment and Final Approval are BPLO's own two moments — reading the form
   * before the bill, and signing the whole thing off after — and an office
   * reviewer's boundary IS the assignment row, so those tabs in these five
   * seats could only ever be empty. An empty queue is a claim.
   */
  for (const office of CLEARANCES) {
    await asOffice(browser, office.account, async (page) => {
      await page.goto('/staff/queue')
      await expect(
        page.getByRole('heading', { name: 'Application Verification', level: 1 }),
      ).toBeVisible({ timeout: 30_000 })

      for (const tab of ['Pending Payment', 'Final Approval'] as const) {
        await expect(
          page.getByRole('button', { name: tab }),
          `${office.code} is offered a ${tab} tab it can never see anything in`,
        ).toHaveCount(0)
      }

      for (const tab of ['For Approval', 'For Inspection'] as const) {
        await page.getByRole('button', { name: tab }).click()
        await page.getByRole('searchbox', { name: /Search this queue/ }).fill(trackingId)
        await expect(
          page.locator('a[href^="/staff/queue/"]').filter({ hasText: businessName }),
          `${office.code} has a filing in its ${tab} tab that was never routed to it`,
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
        `${office.code} can open a filing it was never routed`,
      ).toContain(status)
    })
  }
})

test('the officer’s queue row names the filing that was searched for', async ({ browser }) => {
  const narrative = recall()
  const { trackingId, businessName } = narrative

  /*
   * ── The regression guard for a defect this test reported, now fixed ───────
   *
   * The queue's search box is named "Search this queue by tracking ID or
   * business name", the tracking ID is the handle the applicant quotes down the
   * phone, and `AssignmentController::index` really does match on it. What came
   * back did not print it: `QueueItem` carried `trackingId` and used it in
   * exactly two places — `matchesSearch` and the fallback for a business
   * removed from the register — so the row was a business name, a date and a
   * paid/unpaid block.
   *
   * An officer who searched "BIZ-2026-00964" was shown a row that nowhere said
   * BIZ-2026-00964, and could not confirm they were about to open the filing
   * they were asked about. A business with two filings in flight — a renewal
   * and an amendment, which is ordinary — produced two rows identical on
   * screen, and the only way to tell them apart was to open one and look.
   *
   * `QueueRow` prints the tracking ID under the business name now. That this
   * went unnoticed for so long is worth keeping on the record, because it is
   * how the same hole would reopen: `track-search.spec.ts` does assert
   * `rows.first()).toContainText('BIZ-2026-00203')` — but the fixture behind
   * that row has `business: null`, so `nameOf()` falls back to printing the
   * tracking ID AS the name. The one existing assertion about a tracking ID on
   * a queue row passes only down the path where there is no business name to
   * print instead. This one is made on a row that has both.
   *
   * Asserted from BPLO's seat, which is the coordinating office and the one
   * that fields the phone calls.
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
   * ── The regression guard for a defect this test reported, now fixed ───────
   *
   * Searching a tracking ID in For Approval used to answer, verbatim:
   *
   *     Showing 1 of 11 matching “BIZ-2026-00964”, newest first.
   *
   * One filing matched. Eleven was every filing in the tab, stated as the
   * number that MATCHED the term the officer typed. `QueuePage` took the
   * assignment tabs' total from `meta.application_status_counts` — summed
   * across the tab's statuses — and that breakdown was computed without the `q`
   * the same request carried. `meta.total` beside it was correctly 1. So the
   * sentence paired a searched numerator with an unsearched denominator, and it
   * is the denominator an officer reads as "how much is there".
   *
   * It was the same failure the queue had already paid for once and by name:
   * the client was shown "Showing 0 of the 13 loaded" while searching a
   * business the register plainly held, and the fix was meant to be that the
   * count is the queue's rather than the page's. It was neither — it was the
   * tab's. The server-searched branch reads `meta.total` now.
   *
   * `track-search.spec.ts` cannot see this: its stub returns
   * `application_status_counts: { returned: 1, under_review: 2 }`, which sums
   * to exactly the three rows the stub also returns, so the wrong number and
   * the right number are the same number in the fixture. This asserts it
   * against a register where they differ by an order of magnitude.
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
 * 4. BPLO reads the form, and that is what raises the bill.
 * ────────────────────────────────────────────────────────────────────────── */

test('BPLO approving the form is what makes the bill payable, and grants nothing', async ({
  page,
  browser,
}) => {
  const narrative = recall()
  const { appId, trackingId, businessName, billedAtSubmission } = narrative

  await asOffice(browser, 'bplo', async (officePage) => {
    await openFromQueue(officePage, 'For Approval', narrative)
    await approveOwnReview(officePage)
  })

  const afterBplo = await filing(page, 'public', appId)

  expect(afterBplo.status, 'BPLO’s approval did not make the filing payable').toBe('pending_payment')
  expect(assignmentOf(afterBplo, 'BPLO'), 'BPLO’s own review did not complete').toBe('completed')

  /*
   * The first of BPLO's two acts says the form is fit to be PAID FOR, not that
   * the application is granted. The grant is `approveOverall()`, five approved
   * clearances later, and it is the only place the Mayor's Permit is minted.
   * The Business Permit's own row moves to For Approval and stops there.
   */
  expect(
    permitState(afterBplo, 'BUSINESS'),
    'BPLO accepting the form should open its own permit, not grant it',
  ).toBe('for_approval')
  expect(afterBplo.permits, 'a permit was issued when BPLO merely accepted the form').toHaveLength(0)
  expect(afterBplo.inspections, 'BPLO does not inspect, so it must book no visit').toHaveLength(0)

  /*
   * Still BPLO alone. The other five are routed when the APPLICANT starts them,
   * which is two stages away — this is the usual cause of an unexpected 403 or
   * an empty queue, so it is pinned at every stage rather than once.
   */
  expect(
    afterBplo.assignments.map((a) => a.department.code),
    'BPLO approving the form routed offices the applicant has not reached',
  ).toEqual(['BPLO'])

  /*
   * And the bill is the same number it was at submission. BPLO cannot adjust it
   * (client, 6 September 2026: system-computed only), and moving the figure at
   * the moment it becomes payable would move it under somebody who had already
   * decided to pay it.
   */
  expect(
    billed(afterBplo),
    'the amount due changed at the moment it became payable',
  ).toBe(billedAtSubmission)

  /* ── The applicant is now asked for the money, and told why ────────────── */

  await page.goto('/applications')
  await page.getByLabel(/Search your applications/).fill(trackingId)
  const row = page.locator('li').filter({ hasText: businessName }).first()
  await expect(
    row.getByRole('link', { name: 'Pay Online' }),
    'BPLO has approved the form and the applicant is not offered a way to pay',
  ).toBeVisible({ timeout: 20_000 })

  /*
   * ── Every chip reads Pending Payment, and none of them reads For Approval ──
   *
   * This was a defect test and the defect is fixed. `permitChip()` used to
   * infer a permit's state from the issuing office's ASSIGNMENT and the
   * FILING's status, with everything that matched neither rule falling through
   * to a hard-coded `{ tone: 'orange', label: 'For Approval' }`. On an unpaid
   * filing that told the applicant, once per permit, that offices were reading
   * paperwork which did not exist — and, the real cost, that there was nothing
   * for them to do. "For Approval" is not a spare word either: it is a status
   * label held character-for-character in step with the PHP enum by
   * `StatusLabelParityTest`, precisely so one state never answers to two names.
   *
   * `appStateChip()` answers for all six at once now: a `pending_payment`
   * filing is waiting on the applicant, so every chip says so. Both halves are
   * asserted — the label that must be there, and the one that must not.
   */
  await row.getByRole('button', { expanded: false }).first().click()
  const chips = await row.locator('ul > li').allInnerTexts()
  expect(chips.length, 'the expanded row should draw one chip per permit type').toBe(
    FILED_CODES.length,
  )
  for (const chip of chips) {
    expect(chip, 'a filing waiting on the applicant’s money should say so').toContain(
      'Pending Payment',
    )
    expect(
      chip,
      'an unpaid filing is with the applicant, so no row may say “For Approval”',
    ).not.toContain('For Approval')
  }
})

/* ──────────────────────────────────────────────────────────────────────────
 * 5. Payment opens the other permits — and routes nobody.
 * ────────────────────────────────────────────────────────────────────────── */

test('paying opens the other permits without routing a single office', async ({ page, browser }) => {
  const narrative = recall()
  const { appId, businessName, billedAtSubmission } = narrative

  await page.goto(`/applications/${appId}/pay`)
  await expect(page.getByRole('heading', { name: 'Tax Order of Payment' })).toBeVisible({
    timeout: 30_000,
  })
  await page.getByRole('button', { name: 'Pay Online' }).click()

  // The receipt, not merely the absence of an error: `pay` swallows a failure
  // into an Alert and leaves the page looking much as it did.
  await expect(page.getByText('Paid', { exact: true })).toBeVisible({ timeout: 30_000 })

  const paid = await filing(page, 'public', appId)

  expect(paid.status, 'payment did not open the other permits').toBe('awaiting_other_permits')

  /*
   * ── Payment routes NOBODY, and this is the assertion the old file got
   *    exactly backwards ──────────────────────────────────────────────────
   *
   * `onPaymentCompleted` used to call `routeToDepartments`, which handed the
   * filing to every office on it in one go. That method is gone: each office is
   * routed by `startClearance`, when the applicant opens that permit, so that
   * `assigned_at` is an honest start for the office's measured service time.
   * Routing all five when the money landed charged every office for the days
   * the applicant spent filling in the others' forms.
   *
   * So a filed-and-paid filing still has BPLO as its only assignment, and the
   * five clearance offices still cannot open it.
   */
  expect(
    paid.assignments.map((a) => a.department.code),
    'payment routed offices the applicant has not started with',
  ).toEqual(['BPLO'])
  expect(paid.inspections, 'a visit was booked by a payment').toHaveLength(0)
  expect(paid.permits, 'a permit was issued by a payment').toHaveLength(0)

  /*
   * And no clearance has begun. They were attached at submission so the bill
   * could price them; paying the bill does not open any of them.
   */
  for (const office of CLEARANCES) {
    expect(
      permitState(paid, office.permit),
      `${office.permit} was begun by the payment rather than by the applicant`,
    ).toBe('not_started')
  }

  /*
   * ── One bill, and it has not moved ────────────────────────────────────────
   *
   * This is where the old narrative paid a SECOND time. Clearances were chosen
   * after payment and each one re-ran `FeeCalculator::assess`, so a balance
   * appeared behind a filing already under review and rule 6 of the old spec —
   * "the permit is not released while a balance is outstanding" — held the
   * whole filing against it.
   *
   * None of that exists. `assessFees` is called once, at submission, over every
   * permit the filing will need; `startClearance` calls it not at all. Release
   * is five permit approvals, not a zero balance. Asserted against the figure
   * recorded before BPLO had even read the form, so a fee that crept in at any
   * point in between fails here.
   */
  expect(
    billed(paid),
    'settling the bill changed what the bill was',
  ).toBe(billedAtSubmission)

  /* ── The five offices still cannot reach it ────────────────────────────── */

  for (const office of CLEARANCES) {
    await asOffice(browser, office.account, async (officePage) => {
      await officePage.goto('/staff/queue')
      await expect(
        officePage.getByRole('heading', { name: 'Application Verification', level: 1 }),
      ).toBeVisible({ timeout: 30_000 })

      const status = await officePage.evaluate(async (id) => {
        const token = localStorage.getItem('biztrack.token.staff')
        const res = await fetch(`/api/v1/applications/${id}`, {
          headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        })
        return res.status
      }, appId)
      expect(
        [403, 404],
        `${office.code} can open a paid filing whose permit nobody has started`,
      ).toContain(status)
    })
  }

  // And the applicant's own row no longer offers to pay: the bill is settled.
  await page.goto('/applications')
  await page.getByLabel(/Search your applications/).fill(narrative.trackingId)
  const row = page.locator('li').filter({ hasText: businessName }).first()
  await expect(row.getByRole('link', { name: 'Pay Online' })).toHaveCount(0)
})

/* ──────────────────────────────────────────────────────────────────────────
 * 6. The applicant opens one permit, and one office is routed.
 * ────────────────────────────────────────────────────────────────────────── */

test('the clearance stage’s Apply really applies for that clearance', async ({ page }) => {
  const narrative = recall()
  const { appId } = narrative

  /*
   * ── DEFECT. This test is expected to FAIL, and must not be weakened. ──────
   *
   * Apply on a clearance card is the ONE act in this product that routes an
   * office. `startClearance` moves that permit `not_started → for_approval` and
   * hands the filing to its issuing department; nothing else does, which is why
   * a filed and paid filing has BPLO as its only assignment. If Apply does not
   * post, the five clearances never open, the filing never reaches For Final
   * Approval, and no Mayor's Permit can ever be issued.
   *
   * It does not post. `ClearanceStagePage`'s `applyNow` guards the call:
   *
   *     if (row.state === 'available' || row.state === 'submitted') { … apply … }
   *
   * and `ClearanceService::state()` returns the pivot's own status verbatim, so
   * a required clearance reads `not_started` — not `available`. `available` is
   * now reachable only by a permit that is not attached to the filing at all,
   * and `attachRequiredPermitTypes()` attaches all five at SUBMISSION, because
   * the one Tax Order of Payment has to be able to price them. So the branch is
   * dead for exactly the five permits it exists to start.
   *
   * The press is not inert, which is what hides it: `applyNow` falls through to
   * `hasOfficeForm(code)` and opens the office's sheet, and saving that sheet
   * calls `officeForms.save` alone. The applicant fills in the form, sees it
   * saved, and has applied for nothing. `ClearanceService::isAppliedFor` carries
   * a long note about this exact class of bug — attachment being read as
   * application — and was fixed for it; `applyNow` is the same mistake spelled
   * the other way round and was not.
   *
   * Asserted on the POST rather than on the card's own state afterwards,
   * because the state is what is being got wrong: a check on the badge would be
   * reading the same broken source the branch reads.
   */
  await page.goto(`/applications/${appId}/clearances`)
  const applyOne = page.getByRole('button', { name: /^Apply for the .+$/ }).first()
  await expect(applyOne, 'the clearance stage offers nothing to apply for').toBeVisible({
    timeout: 30_000,
  })
  const appliedFor = (await applyOne.getAttribute('aria-label')) ?? ''

  const [applied] = await Promise.all([
    page.waitForResponse(
      (r) => /\/clearances\/[A-Z]+\/apply$/i.test(r.url()) && r.request().method() === 'POST',
      { timeout: 20_000 },
    ),
    applyOne.click(),
  ])
  expect(applied.status(), `${appliedFor} was refused: ${await applied.text()}`).toBeLessThan(300)
})

test('each clearance routes its own office, one at a time, as the applicant reaches it', async ({
  page,
  browser,
}) => {
  const narrative = recall()
  const { appId, billedAtSubmission } = narrative

  const first = CLEARANCES[0]

  /*
   * Through the endpoint the card is supposed to press, because the card does
   * not press it — see the defect above. The claim being made here is about the
   * WORKFLOW rather than the screen: one office is routed per permit started,
   * when it is started, and that is what makes `assigned_at` an honest start
   * for an office's measured service time.
   */
  await page.goto('/dashboard')
  await page.evaluate(
    async ([id, code]) => {
      const token = localStorage.getItem('biztrack.token.public')
      const res = await fetch(`/api/v1/applications/${id}/clearances/${code}/apply`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
      if (!res.ok) throw new Error(`applying for ${code} answered ${res.status}: ${await res.text()}`)
    },
    [appId, first.permit] as const,
  )

  const afterOne = await filing(page, 'public', appId)

  /*
   * Exactly two offices on the filing: BPLO, from submission, and the one whose
   * permit the applicant has just opened.
   */
  expect(
    afterOne.assignments.map((a) => a.department.code).sort(),
    'starting one permit did not route exactly its own office',
  ).toEqual(['BPLO', first.code].sort())
  expect(
    permitState(afterOne, first.permit),
    `${first.permit} was applied for and did not open`,
  ).toBe('for_approval')

  // And every clearance the applicant has not reached is untouched and unrouted.
  for (const office of CLEARANCES.filter((o) => o.code !== first.code)) {
    expect(
      permitState(afterOne, office.permit),
      `applying for ${first.permit} also began ${office.permit}`,
    ).toBe('not_started')
    await asOffice(browser, office.account, async (officePage) => {
      await officePage.goto('/staff/queue')
      const status = await officePage.evaluate(async (id) => {
        const token = localStorage.getItem('biztrack.token.staff')
        const res = await fetch(`/api/v1/applications/${id}`, {
          headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        })
        return res.status
      }, appId)
      expect(
        [403, 404],
        `${office.code} was routed a filing whose ${office.permit} nobody has applied for`,
      ).toContain(status)
    })
  }

  /*
   * And now the remaining four, so the rest of the narrative has a filing with
   * every office on it.
   *
   * The office sheets go with them. Two of the five refuse to be filed without
   * an answer (`officeFormMissing`), and they are filled here for the same
   * reason the rest of the form is: so that an unfilled sheet is not what this
   * file ends up measuring.
   */
  await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    }

    const current = await fetch(`/api/v1/applications/${id}/clearances`, { headers })
    const rows = (await current.json()).data as {
      permit_type: { code: string }
      state: string
    }[]

    for (const row of rows) {
      const code = row.permit_type.code
      /*
       * `not_started` and `available` are the only two states that may be
       * applied for, and the pair is not interchangeable with "has no permit
       * yet". `ClearanceService::state()` returns the pivot's own
       * ClearanceStatus verbatim, so the one started a moment ago already reads
       * `for_approval`; skipping on the old `'applied'` string — which no
       * longer exists — sent this loop back at it and earned a 422 that stopped
       * the other four ever being applied for.
       */
      if (code === 'BUSINESS') continue
      if (row.state !== 'not_started' && row.state !== 'available') continue
      const res = await fetch(`/api/v1/applications/${id}/clearances/${code}/apply`, {
        method: 'POST',
        headers,
      })
      if (!res.ok) throw new Error(`applying for ${code} answered ${res.status}: ${await res.text()}`)
    }

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
      if (!res.ok) throw new Error(`office form ${code} answered ${res.status}: ${await res.text()}`)
    }
  }, appId)

  const all = await filing(page, 'public', appId)

  /*
   * One assignment per office on the filing, and every clearance office's
   * pending. This is the state the isolation tests below measure movement
   * against, so it is pinned before anybody acts.
   */
  expect(
    all.assignments.map((a) => a.department.code).sort(),
    'the filing was not routed to every office the applicant has now reached',
  ).toEqual([...OFFICES.map((o) => o.code)].sort())
  for (const office of CLEARANCES) {
    expect(assignmentOf(all, office.code), `${office.code}'s review is not open`).toBe('pending')
    expect(permitState(all, office.permit), `${office.permit} was not applied for`).toBe(
      'for_approval',
    )
  }

  // BPLO's is still closed, from its first act. Nothing reopens it.
  expect(assignmentOf(all, 'BPLO'), 'applying for a clearance reopened BPLO’s review').toBe(
    'completed',
  )

  /*
   * And still one bill. Five clearances have been applied for since it was
   * raised and not a centavo has been added — "each clearance adds its own fee"
   * is the copy of a flow that no longer exists.
   */
  expect(
    billed(all),
    'applying for five clearances added to a bill that was already settled',
  ).toBe(billedAtSubmission)

  expect(all.permits, 'a permit was issued by applying for one').toHaveLength(0)
  expect(all.inspections, 'a visit was booked by applying for a permit').toHaveLength(0)
})

/* ──────────────────────────────────────────────────────────────────────────
 * 7. Approval isolation, across five offices working independently.
 * ────────────────────────────────────────────────────────────────────────── */

test('one office accepting its paperwork moves its permit and nobody else’s', async ({
  page,
  browser,
}) => {
  const narrative = recall()
  const { appId, trackingId } = narrative

  const first = CLEARANCES[0]

  await asOffice(browser, first.account, async (officePage) => {
    await openFromQueue(officePage, 'For Approval', narrative)
    await approveOwnReview(officePage)
  })

  const after = await filing(page, 'public', appId)

  /*
   * ── Accepting the paperwork is not granting the permit ────────────────────
   *
   * `approveClearance` moves one permit For Approval → For Inspection and books
   * NOTHING. The old service scheduled a visit two working days out to the
   * least-loaded inspector the instant an office approved; the client's
   * verified step is "Select Inspection Date and Approve Inspection", so the
   * office says when, separately. A visit appearing here would be a promise
   * made to the applicant by a scheduler that does not know whether anyone is
   * free.
   */
  expect(
    permitState(after, first.permit),
    `${first.code} accepted the paperwork and its permit did not reach inspection`,
  ).toBe('for_inspection')
  expect(
    after.inspections,
    'approving the paperwork booked a visit; the office is supposed to pick the date',
  ).toHaveLength(0)
  expect(assignmentOf(after, first.code), `${first.code}'s own review did not complete`).toBe(
    'completed',
  )

  /*
   * The FILING has not moved, and neither has anything downstream of it. All
   * three are asserted rather than just the status: a permit minted early would
   * be a status that never changed and a certificate that exists anyway, which
   * is the shape of the duplicate-issuance bug the final stage guards.
   */
  expect(after.status, 'one office’s approval advanced the whole filing').toBe(
    'awaiting_other_permits',
  )
  expect(after.permits, 'a permit was issued on paperwork alone').toHaveLength(0)

  // And the other four permits are exactly where they were.
  for (const office of CLEARANCES.filter((o) => o.code !== first.code)) {
    expect(
      permitState(after, office.permit),
      `${first.code} approving moved ${office.permit}, which is not ${first.code}'s to move`,
    ).toBe('for_approval')
    expect(
      assignmentOf(after, office.code),
      `${first.code} approving moved ${office.code}'s review`,
    ).toBe('pending')
  }

  /* ── This office's row is still findable, one tab across (INS-2) ───────── */

  await asOffice(browser, first.account, async (officePage) => {
    /*
     * "I approved it and it is not in For Inspection", the client's report 1 in
     * its own words. The rule the tabs answer to is THIS OFFICE'S own permit:
     * For Inspection holds a filing whose clearance for this office is
     * `for_inspection`, whatever the filing as a whole is doing.
     */
    await openFromQueue(officePage, 'For Inspection', narrative)

    await officePage.goto('/staff/queue')
    await officePage.getByRole('button', { name: 'For Approval' }).click()
    await officePage.getByRole('searchbox', { name: /Search this queue/ }).fill(trackingId)
    await expect(
      officePage.locator('a[href^="/staff/queue/"]').filter({ hasText: trackingId }),
      'a completed review is still being offered to this office as outstanding work',
    ).toHaveCount(0, { timeout: 20_000 })
  })

  /* ── And every other office still has its own Approve (INS-1) ──────────── */

  for (const office of CLEARANCES.filter((o) => o.code !== first.code)) {
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

test('approving is confirmed on screen whichever way the filing then moves', async ({ browser }) => {
  const narrative = recall()
  const second = CLEARANCES[1]

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
   * the branch that draws the compact decision box — which returns before the
   * modal is ever reached. A clearance office's approval is exactly what makes
   * that branch apply, so its own confirmation was unmounted by its own
   * success, and the officer with the most consequential approval in the flow
   * was the one told nothing.
   *
   * The fix was not to teach that branch to draw the dialog too. `ReviewPage`
   * owns `showVerification` and renders the modal as a SIBLING of the whole
   * sheet, so no `return` inside `ReviewSheet` — including the next one somebody
   * adds — can take it down. This test is what stops it moving back inside.
   *
   * Asserted on the second clearance office rather than the first, so that the
   * approval is a real step of the narrative: this office's paperwork is now
   * accepted, and the stage below expects it.
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
 * 8. The office picks the date — and there is no screen that lets it.
 * ────────────────────────────────────────────────────────────────────────── */

test('an office that has accepted the paperwork can book its own visit from the screen', async ({
  browser,
}) => {
  const narrative = recall()
  const first = CLEARANCES[0]

  /*
   * ── DEFECT. This test is expected to FAIL, and must not be weakened. ──────
   *
   * The client's verified step is "Select Inspection Date and Approve
   * Inspection": the automatic scheduler was deleted precisely so the office
   * would say when. `InspectionController::schedule` was written for it —
   * `POST /applications/{id}/permits/{code}/inspection`, addressed by permit
   * code because no inspection exists yet, gated on `inspection.manage` and on
   * the caller's own department.
   *
   * Nothing in the browser calls it. `web/src/lib/resources.ts` exposes
   * `inspections.conduct`, `.reschedule` and `.reinspect`; all three take an
   * inspection id, so all three require the visit this endpoint is what
   * creates. `InspectionDecisionPanel` draws its cards from
   * `app.inspections` — an empty array here — and its "Schedule re-inspection"
   * control is drawn against a FAILED visit, which is a different act.
   *
   * So an office arrives at the compact decision box with its permit correctly
   * For Inspection, and the screen offers it nothing: no date field, no book
   * button, no way at all to reach the state where Approve appears. The permit
   * cannot be granted, the filing cannot reach For Final Approval, and BPLO can
   * never issue the Mayor's Permit. It is a deadlock no action in the product
   * clears — the same shape as INS-1, at the next stage along.
   *
   * The rest of this narrative drives the endpoint directly (see `bookVisit`)
   * so that the stages after it can still be walked and can still report their
   * own defects. This test is where the gap is stated, once, by something that
   * goes red for it.
   */
  await asOffice(browser, first.account, async (page) => {
    await openFromQueue(page, 'For Inspection', narrative)

    const panel = page.locator('section[aria-label="Application status"]')
    await expect(panel, 'the office’s decision box did not open').toBeVisible({ timeout: 30_000 })

    await expect(
      page.getByRole('button', { name: new RegExp(`^Book the .+ visit$`) }),
      `${first.code} has accepted the paperwork and the screen offers no way to book the visit`,
    ).toBeVisible()
  })
})

/* ──────────────────────────────────────────────────────────────────────────
 * 9. A passed visit issues that permit, on its own.
 * ────────────────────────────────────────────────────────────────────────── */

test('a passed visit issues that one permit without waiting for the others', async ({
  page,
  browser,
}) => {
  const narrative = recall()
  const { appId } = narrative

  const first = CLEARANCES[0]

  await asOffice(browser, first.account, async (officePage) => {
    await officePage.goto('/staff/queue')
    await bookVisit(officePage, appId, first.permit)

    await openFromQueue(officePage, 'For Inspection', narrative)
    await expect(officePage.locator('section[aria-label="Application status"]')).toBeVisible({
      timeout: 30_000,
    })

    /*
     * Exactly one Approve, and it is this office's.
     *
     * Every visit on the filing is drawn on this screen, but `canAct` offers
     * the decision pair only for the reader's own department — so a count of
     * one IS the isolation assertion, made from the seat that would leak. The
     * name says whose visit it decides, because a column of buttons all called
     * "Approve" is a list a screen-reader user cannot navigate.
     */
    const approve = officePage.getByRole('button', { name: /^Approve the .+ inspection$/ })
    await expect(
      approve,
      `${first.code} is offered a number of inspection decisions other than its own one`,
    ).toHaveCount(1)

    await approve.click()
    // The card flips to the passed state in place; nothing navigates.
    await expect(officePage.getByText('Inspection Passed').first()).toBeVisible({ timeout: 30_000 })
  })

  const after = await filing(page, 'public', appId)

  /*
   * ── One permit, released by its own office, now ───────────────────────────
   *
   * Rule 7 of the spec, and the client was explicit: "the other 6 permits are
   * automatically released once they are approved by their respective admins;
   * no need to wait for each other to be approved." There is no whole-filing
   * check on this path at all — the old service's `isFullyCleared` gate, which
   * also refused to release anything while a balance stood, is gone.
   *
   * So this office's certificate exists while four other permits are still
   * outstanding, and the filing has not moved.
   */
  expect(permitState(after, first.permit), `${first.permit} did not pass its inspection`).toBe(
    'approved',
  )
  expect(
    after.permits.map((p) => p.permit_type?.code),
    'a passed visit did not issue exactly that office’s certificate',
  ).toEqual([first.permit])
  expect(after.status, 'one permit passing advanced the whole filing').toBe(
    'awaiting_other_permits',
  )

  for (const office of CLEARANCES.filter((o) => o.code !== first.code)) {
    expect(
      permitState(after, office.permit),
      `${first.code}'s visit passing moved ${office.permit}`,
    ).not.toBe('approved')
  }
})

/* ──────────────────────────────────────────────────────────────────────────
 * 10. The other four, and BPLO's second act.
 * ────────────────────────────────────────────────────────────────────────── */

test('the fifth approved permit is what puts the filing in front of BPLO again', async ({
  page,
  browser,
}) => {
  const narrative = recall()
  const { appId } = narrative

  /*
   * The four still outstanding. The first two clearance offices have already
   * accepted their paperwork in the stages above — the second inside the
   * confirmation test, whose approval landed whether or not it was confirmed on
   * screen — so asking them to approve again would find no Approve button and
   * report a missing control that is correctly missing.
   */
  const paperworkDone = new Set([CLEARANCES[0].account, CLEARANCES[1].account])

  for (const office of CLEARANCES) {
    await asOffice(browser, office.account, async (officePage) => {
      if (!paperworkDone.has(office.account)) {
        await openFromQueue(officePage, 'For Approval', narrative)
        await approveOwnReview(officePage)
      }

      // The first office's visit is already booked and passed.
      if (office.code === CLEARANCES[0].code) return

      await officePage.goto('/staff/queue')
      await bookVisit(officePage, appId, office.permit)

      await openFromQueue(officePage, 'For Inspection', narrative)
      const approve = officePage.getByRole('button', { name: /^Approve the .+ inspection$/ })
      await expect(
        approve,
        `${office.code} is offered a number of inspection decisions other than its own one`,
      ).toHaveCount(1, { timeout: 30_000 })
      await approve.click()
      await expect(officePage.getByText('Inspection Passed').first()).toBeVisible({
        timeout: 30_000,
      })
    })
  }

  const ready = await filing(page, 'public', appId)

  for (const office of CLEARANCES) {
    expect(permitState(ready, office.permit), `${office.permit} was never approved`).toBe('approved')
  }

  /*
   * Five permits, one per clearance, and the Mayor's Permit NOT among them.
   * Each was minted by its own office as it finished; the one BPLO issues on
   * the strength of them is still to come.
   */
  expect(
    ready.permits.map((p) => p.permit_type?.code).sort(),
    'the issued certificates are not exactly the five clearances',
  ).toEqual([...CLEARANCES.map((o) => o.permit)].sort())
  expect(permitState(ready, 'BUSINESS'), 'the Mayor’s Permit was granted before BPLO signed').toBe(
    'for_approval',
  )

  /*
   * And `refreshReadiness` has moved the filing into BPLO's second queue. This
   * is the edge that did not exist in the old machine, and it goes both ways:
   * an office reversing itself must take the filing back out again, or BPLO is
   * left holding an Approve over an application that no longer qualifies.
   */
  expect(ready.status, 'every other permit is approved and BPLO has not been asked to sign').toBe(
    'for_final_approval',
  )

  // Every visit conducted and passed, one per clearance office.
  expect(ready.inspections).toHaveLength(CLEARANCES.length)
  for (const visit of ready.inspections) {
    expect(visit.conducted_at, `${visit.department?.code}'s visit was never conducted`).not.toBeNull()
    expect(visit.result, `${visit.department?.code}'s visit did not pass`).toBe('passed')
  }
})

test('BPLO’s Final Approval tab offers the filing, and the sheet offers the approval', async ({
  browser,
}) => {
  const narrative = recall()

  /*
   * ── DEFECT. This test is expected to FAIL, and must not be weakened. ──────
   *
   * BPLO has two acts and the screen only supports the first.
   *
   * The queue half is right, and its own note in `QueuePage` says why: the
   * Final Approval tab reads the ASSIGNMENT feed rather than `/applications`,
   * because a row here has to be openable, and it deliberately applies no
   * assignment-status filter — "BPLO's assignment is `completed` here, closed
   * by `approveMainForm()` at the other end of the process, and nothing reopens
   * it. Its final approval is work with no open work item behind it."
   *
   * `ReviewPage` never got the other half of that reasoning. It computes
   * `decided = rejected || approvedHere || Boolean(data.completed_at)` off the
   * assignment alone, and `editing = mode === 'edit' && !decided`. BPLO's
   * assignment carries `completed_at` from its first approval, so `decided` is
   * true, so Edit turns nothing on and no Approve is ever drawn — on the one
   * screen the tab exists to lead to.
   *
   * The API is willing: `WorkflowService::approveAssignment` branches on the
   * FILING's status and maps `for_final_approval` to `approveOverall()`. It is
   * only the screen that cannot ask. The result is a filing with every permit
   * approved, sitting in a tab BPLO can open and cannot act on, and no Mayor's
   * Permit issuable by any action the product offers.
   *
   * The stage below drives `/approve` directly so the narrative can finish and
   * the assertions about issuance can still be made. This test is where the gap
   * is stated, and it must go red until `decided` learns that a completed
   * assignment is not a finished office.
   */
  await asOffice(browser, 'bplo', async (page) => {
    await openFromQueue(page, 'Final Approval', narrative)

    /*
     * Edit first, and asserted rather than merely clicked. `decided` replaces
     * the whole Mode group with a static green "Approved", so a bare `.click()`
     * reports a fifteen-second timeout on a control that was never drawn —
     * true, and no use to a reader. This says what is missing.
     */
    const edit = page.getByRole('button', { name: 'Edit', exact: true })
    await expect(
      edit,
      'the sheet is a closed record: BPLO’s completed assignment hides the Mode control, so there is no way into Edit',
    ).toBeVisible()

    await edit.click()
    await expect(
      page.getByRole('button', { name: 'Approve', exact: true }),
      'BPLO can reach the filing that wants its signature and cannot sign it',
    ).toBeVisible()
  })
})

test('BPLO’s final approval issues the Mayor’s Permit and nothing twice', async ({
  page,
  browser,
}) => {
  const narrative = recall()
  const { appId } = narrative

  /*
   * Driven through `POST /assignments/{id}/approve` — the endpoint the review
   * sheet's own button posts to — because the button is not drawn. The defect
   * is reported by the test above; repeating it here would cost this file every
   * assertion about issuance, which is the thing the whole narrative is for.
   */
  await asOffice(browser, 'bplo', async (officePage) => {
    const href = await openFromQueue(officePage, 'Final Approval', narrative)
    const assignmentId = Number(href.split('/').pop())
    expect(assignmentId, `the Final Approval row points at ${href}`).toBeGreaterThan(0)

    const status = await officePage.evaluate(async (id) => {
      const token = localStorage.getItem('biztrack.token.staff')
      const res = await fetch(`/api/v1/assignments/${id}/approve`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({}),
      })
      if (!res.ok) throw new Error(`final approval answered ${res.status}: ${await res.text()}`)
      return res.status
    }, assignmentId)
    expect(status, 'BPLO’s final approval was refused').toBe(200)
  })

  const issued = await filing(page, 'public', appId)

  expect(issued.status, 'every permit is in and the filing is not approved').toBe('approved')
  expect(permitState(issued, 'BUSINESS'), 'the Mayor’s Permit was not granted').toBe('approved')

  /*
   * ── The count, not the existence ──────────────────────────────────────────
   *
   * Permits are minted from two directions — `grantClearance()` off a passing
   * visit, and `approveOverall()` for the Business Permit — and a duplicate run
   * writes REAL, numbered certificates and then hides itself, because
   * `transition()` no-ops Approved → Approved and the status never changes
   * twice. `issuePermitFor` uses `firstOrCreate` on (application, permit type)
   * precisely because the old service had that bug.
   *
   * So "permits exist" is the assertion that would have passed while it was
   * live. This asserts the exact total, then that no permit type appears twice
   * — the second check is not redundant with the first, because six permits
   * could still be five types with one doubled — and then that no two share a
   * number.
   */
  expect(
    issued.permits,
    'the filing does not hold exactly one permit per requested permit type',
  ).toHaveLength(FILED_CODES.length)

  const byType = issued.permits.map((p) => p.permit_type?.code ?? '(untyped)').sort()
  expect(byType, 'the issued permits are not one per requested type').toEqual([...FILED_CODES].sort())

  const numbers = issued.permits.map((p) => p.permit_number)
  expect(new Set(numbers).size, 'two permits were issued under one number').toBe(numbers.length)

  // Every visit conducted and passed, with nothing left open behind the
  // approval — an outstanding visit on an approved filing is a permit issued
  // over an inspection nobody performed.
  expect(issued.inspections).toHaveLength(CLEARANCES.length)
  for (const visit of issued.inspections) {
    expect(visit.conducted_at, `${visit.department?.code}'s visit was never conducted`).not.toBeNull()
    expect(visit.result, `${visit.department?.code}'s visit did not pass`).toBe('passed')
  }
})

/* ──────────────────────────────────────────────────────────────────────────
 * 11. The owner sees the outcome.
 * ────────────────────────────────────────────────────────────────────────── */

test('the owner is shown the approval and every permit it produced', async ({ page }) => {
  const narrative = recall()
  const { appId, trackingId, businessName } = narrative

  await page.goto(`/applications/${appId}`)
  await expect(page.getByRole('heading', { name: businessName, level: 1 })).toBeVisible({
    timeout: 30_000,
  })
  /*
   * The card, not the page. `.first()` because this screen prints its status
   * twice on purpose: the big card at the top, which is where the filing IS,
   * and the HISTORY timeline at the foot, which correctly still lists every
   * status it has ever held. A decided filing that erased how it got there
   * would be worse. What must not be true is the card at the top still
   * announcing a stage that is over, so the card is what is read.
   */
  const statusCard = page
    .getByText(/^(Approved|Awaiting Other Permits|For Final Approval|For Approval|Pending)$/)
    .first()
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
   * the eye and the download arrow say which of six identical icons they belong
   * to: "Sanitary Permit for ‹business› (MCB-2026-000406)".
   *
   * Counted per type, and each expected exactly once. Reading the panel's total
   * alone would pass on six rows that were five types and one doubled, which is
   * precisely what the duplicate-issuance bug produced.
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
   * The filing issues six certificates. Its own screen used to offer one.
   *
   * `ApplicationDetailPage` did `const issuedPermit = app.permits[0]` and drew
   * a single eye chip and a single download arrow, both labelled "Business
   * Permit" and "Download Business Permit" as literal strings — not from
   * `permit_type.name`. Two things were wrong at once and only one of them was
   * cosmetic:
   *
   *  - five of the six certificates the applicant paid for had no route from
   *    the filing that produced them. They were on Profile, so nothing was
   *    lost; but the screen that says "Approved" is the screen an applicant goes
   *    to, and it presented the outcome as a single document;
   *  - the one it did offer was named unconditionally. `permits[0]` is whatever
   *    was inserted first, and permits are minted one at a time now — a
   *    clearance's, as each visit passes, then BPLO's — so the Mayor's Permit is
   *    reliably LAST. Under the old link that meant a Sanitary Permit served
   *    under a link reading "Download Business Permit": a certificate under
   *    another certificate's name, which on a legal instrument is not a label
   *    problem.
   *
   * The card maps every permit and takes each name from `permit_type.name`.
   * Asserted as a count rather than by inspecting the label, because the count
   * is the user-visible claim: six were issued, so six should be reachable.
   */
  await page.goto(`/applications/${appId}`)
  await expect(page.getByText(/^Approved$/).first()).toBeVisible({ timeout: 30_000 })

  /*
   * Counted by DESTINATION, not by control: the card draws an eye chip and a
   * download arrow, so two links point at the same certificate. What is being
   * asserted is how many of the six are reachable at all.
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
