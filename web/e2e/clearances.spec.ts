import { expect, test, type Page } from '@playwright/test'
import { mergedStorageState } from './helpers'

/*
 * The LGU Clearances, from a business owner's side.
 *
 * Two properties here are not preferences, and both have already been broken
 * once (fixed in aabbf21, reported as "sometimes it will just highlight the
 * apply button, sometimes it will actually redirect to the form"):
 *
 *   Apply always opens that office's form.
 *   Upload an existing copy always opens the upload box.
 *   Neither toggles.
 *
 * ("Submit" was the name of the second one until the client asked for the first:
 * *"instead of 'Submit' why not 'Upload an existing copy' to avoid confusion?"*
 * — the office sheet's own button also read Submit, so one verb meant both
 * "hand the office my answers" and "hand them a certificate I already hold".)
 *
 * They were toggles, so the outcome depended on state the applicant could not
 * see and flipped on every click — and one of the two outcomes was destructive:
 * a second click on "Submitted ✓" deleted the file that had just been uploaded,
 * with no confirmation and no undo. A toggle is exactly the kind of thing that
 * comes back in a merge because it looks tidier, so it is asserted rather than
 * assumed.
 *
 * ── The flow these run against (docs/application-flow-2026-09.md) ────────────
 *
 * The ordering has now been reversed three times, so it is written out rather
 * than implied. As of 6 September 2026:
 *
 *     draft → submit → FOR APPROVAL      BPLO reads the form; nothing is owed
 *           → BPLO approves → PENDING PAYMENT   one Tax Order of Payment,
 *                                               covering the business permit
 *                                               AND all five clearances
 *           → pay → AWAITING OTHER PERMITS      the stage unlocks
 *           → five permits approved → FOR FINAL APPROVAL → APPROVED
 *
 * Three consequences run through every test below, and each one killed an
 * assertion this file used to make:
 *
 *   **BPLO reads the form before any money is asked for.** `submit()` lands on
 *   `for_approval`, and `PaymentController` refuses a filing that has not been
 *   billed (`ApplicationStatus::isBillable`). So reaching the state this stage
 *   opens in takes three acts by two people, which is the whole of why
 *   `makePaidApplication` exists and why this file holds a BPLO session.
 *
 *   **There is no accrual.** One bill at submission prices every permit the
 *   filing will need (`WorkflowService::assessFees`, called once), and
 *   `ClearanceService::reassess()` — which was what re-priced on Apply — no
 *   longer exists. Applying adds NOTHING to the balance. Anything asserting
 *   "each clearance adds its own fee" or "the permit is held until the balance
 *   reaches zero" was asserting the previous arrangement; release is five
 *   approved permits now, not a settled balance.
 *
 *   **APPLYING IS NOT SUBMITTING.** `startClearance(…, MODE_APPLY)` on a permit
 *   that carries an office form — which all five do
 *   (`PermitType::OFFICE_FORM_CODES` is the same five) — records the chosen
 *   `mode` and stops. The status stays `not_started`, nothing is routed, and
 *   `submitClearanceForm()` is what hands it in when the applicant saves the
 *   sheet. It used to do the lot on the press of Apply, and that wrong claim was
 *   read from both seats of the same filing: the applicant's Track page showed
 *   For Approval on clearances they had not filled in (*"I still haven't
 *   submitted any applications yet the status says it is For Approval"*) and
 *   CENRO opened a queue row with no answers on it. So a fixture that presses
 *   Apply leaves the permit at `not_started`/`apply`, and any test asserting
 *   `for_approval` after an Apply is asserting the bug.
 *
 *   **All five clearances are REQUIRED** (`PermitType::REQUIRED_CLEARANCE_CODES`)
 *   and are attached to the filing at submission so the one bill can price them.
 *   `unapply()` refuses a required permit outright, so withdrawing is not a move
 *   that exists — the test that asserted a Withdraw control is gone and the one
 *   asserting the refusal is at the foot of this file. Five cards, not six: the
 *   Market Clearance and the City Market Office were removed on the same day.
 */

/*
 * Two sessions in one browser, which is what the new flow costs.
 *
 * Reaching a paid filing needs BPLO to approve the form in the middle — an
 * applicant cannot pay before they do — so this file cannot be driven by the
 * owner alone the way it was while submission billed straight away. The tokens
 * are keyed by portal (`biztrack.token.public` / `.staff`), so the two sessions
 * coexist rather than overwriting each other; `mergedStorageState` throws if
 * that ever stops being true.
 *
 * Merged rather than logged in per test on purpose: a second sign-in in each of
 * the ten tests that need BPLO would trip the endpoint's lockout, which is a
 * control worth keeping.
 */
test.use({ storageState: mergedStorageState(['owner.json', 'bplo.json']) })

/**
 * Land on the applicant's dashboard and let it settle before evaluating in it.
 *
 * `page.goto` resolves on load, not on the app being done: `api.ts` answers any
 * 401 with `window.location.assign('/login')`, a HARD navigation that tears
 * down the execution context a `page.evaluate` is running in. Four tests in this
 * file failed as "Execution context was destroyed, most likely because of a
 * navigation" for exactly that reason, which reads like a Playwright quirk and
 * is really a race with the page's own boot.
 */
async function onDashboard(page: Page) {
  await page.goto('/dashboard')
  /*
   * The shell, not `networkidle`. The app polls `/unread-summary`, so the
   * network never goes quiet and `waitForLoadState('networkidle')` simply times
   * the test out. A rendered heading means the session was accepted and the
   * page is not about to bounce to the login screen.
   */
  await expect(page.getByRole('heading').first()).toBeVisible({ timeout: 30_000 })
}

/*
 * `makeDraft` stood here and is gone with its last caller.
 *
 * It built the minimum filing — a business, an application, the business permit
 * and nothing else — and the two locked-stage tests used it as "the shut state".
 * A draft is still shut, but it is no longer a state with anything on this
 * screen to look at: the stage renders the FILING's permit types, and the five
 * clearances are not attached until `submit()` runs. See
 * `makeSubmittedApplication` below, which is what those two tests use now.
 *
 * Bring it back pointed at whatever needs a bare draft; it is in git history.
 */

/**
 * A draft complete enough to be walked to the end of the wizard and submitted:
 * every field the earlier steps require, and a fee profile to price it by.
 *
 * The wizard refuses a forward jump over an unfinished section, which is the
 * right behaviour and the reason this exists — reaching Review & Submit by
 * clicking through six sections of form-filling would be a test of the form,
 * not of the step.
 *
 * It no longer uploads the documentary requirements, and that is a measured
 * change rather than a corner cut. `ApplicationController::submit` gates on RA
 * 10173 consent and on the business not being blocked, and on nothing else — a
 * filing with no documents at all submits through the API (verified against
 * this stack). Five PDF uploads per fixture, ten times a run, bought nothing any
 * assertion below reads.
 *
 * The WIZARD is a different matter and still wants them: its section map
 * refuses a forward jump over an unfinished section, so a draft with no
 * documents opens at Documentary Requirements with Review & Submit shut. The
 * one test that walks the wizard calls `uploadRequiredDocuments` for exactly
 * that reason.
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
          /*
           * ── Three fields the wizard will not walk past without ─────────────
           *
           * Added because the one test in this file that drives the WIZARD could
           * not reach Review & Submit any more: the section map refuses a
           * forward jump over an unfinished section, and this fixture left two
           * of the seven unfinished — Location & Zoning and Business Operation,
           * with the latter unreachable behind the former.
           *
           * All three are on the BUSINESS payload rather than the application's,
           * which is why they sit here and not in the fee profile below.
           *
           * `economic_organization` is BPLO item B6 and `capital_investment` is
           * B7, both made required on 9 September 2026 with the rest of the
           * paper fields. B7 in particular moved: it replaced the per-line
           * capitalisation that used to be asked on the fee step.
           *
           * `products_services` is required per line from the same date, and it
           * is the one worth not getting wrong. The PSIC code says what CATEGORY
           * a trade falls in; this says what the business actually sells, and
           * three offices print it on their paper — CENRO's form has a
           * PRODUCTS/SERVICES box beside LINE OF BUSINESS and it reached them
           * empty on the filing that prompted the change. "Retail sale in
           * non-specialized stores" tells a sanitary inspector nothing about
           * whether there is food on the premises.
           */
          economic_organization: 'single_establishment',
          capital_investment: 500000,
          lines: [
            {
              psic_code_id: psic[0].id,
              capitalization: 500000,
              products_services: 'milk tea, fried snacks',
            },
          ],
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
           * The FIRST of the two gates at submit, and it is a column on the
           * application rather than browser state (`ApplicationController::
           * submit` refuses a filing whose `data_privacy_consent` is not true
           * before it looks at anything else). A fixture built through the API
           * has no wizard to tick it, so it is set here — without it every test
           * in this file failed at `makePaidApplication` with a 422 about RA
           * 10173 consent, which says nothing about clearances.
           */
          data_privacy_consent: true,
          fee_profile: {
            business_structure: 'sole_proprietorship',
            floor_area_sqm: 120,
            employees: 12,
            employees_in_lgu: 6,
            /*
             * B2's split, which joined the headcount as a required answer and is
             * the last thing that kept Business Operation unfinished for this
             * fixture. It has to RECONCILE — `feeProfileIssues` refuses a split
             * that does not add up to the total three fields above it, and the
             * API applies the same rule to both halves — so 7 and 5 against a
             * total of 12 rather than any two numbers.
             */
            male_employees: 7,
            female_employees: 5,
            lines: [
              { psic_code_id: psic[0].id, category: 'retailer', capitalization: 500000 },
            ],
          },
        }),
      }),
    )

    return app.id as number
  })
}

/**
 * Every required documentary requirement, so the wizard's documents step is
 * done and its section map is walkable to Review & Submit.
 *
 * Real PDF magic bytes, not a text blob renamed .pdf: the API validates with
 * `mimes:pdf`, which sniffs the content rather than trusting the name.
 */
async function uploadRequiredDocuments(page: Page, appId: number): Promise<void> {
  await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` }
    const types = (
      await (await fetch('/api/v1/reference/permit-types', { headers })).json()
    ).data as {
      code: string
      document_types: { id: number; code: string; is_required?: boolean; context?: string }[]
    }[]
    const businessType = types.find((pt) => pt.code === 'BUSINESS')!

    const pdf = '%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n'
    for (const dt of businessType.document_types) {
      if (dt.is_required === false || (dt.context && dt.context !== 'all')) continue
      const body = new FormData()
      body.append('document_type_id', String(dt.id))
      body.append('file', new File([pdf], `${dt.code}.pdf`, { type: 'application/pdf' }))
      const res = await fetch(`/api/v1/applications/${id}/documents`, {
        method: 'POST',
        headers,
        body,
      })
      if (!res.ok) throw new Error(`uploading ${dt.code} answered ${res.status}`)
    }
  }, appId)
}

/**
 * An application that has been submitted, APPROVED BY BPLO and PAID — the state
 * in which this stage is actually open.
 *
 * ── Why this is three acts and not two ─────────────────────────────────────
 *
 * It used to submit and pay, back to back, on the reasoning that submission
 * raised the Tax Order of Payment. It does not any more. The verified counter
 * procedure puts BPLO's reading of the main form BEFORE the money, so the walk
 * is:
 *
 *   POST /applications/{id}/submit        the owner. draft → FOR APPROVAL.
 *     Assigns the tracking ID, attaches all five required clearances, and
 *     assesses the ONE Tax Order of Payment covering the lot. Nothing is due.
 *   POST /assignments/{id}/classification BPLO. The RA 11032 tier, which
 *     `submit()` only GUESSED — `approveMainForm` refuses until a person has
 *     put their name to it, so this is not optional decoration.
 *   POST /assignments/{id}/approve        BPLO. → PENDING PAYMENT. This is the
 *     act that makes the bill payable.
 *   POST /applications/{id}/pay           the owner. → AWAITING OTHER PERMITS,
 *     which is the first status `ApplicationStatus::isPaid()` accepts and so
 *     the first at which `ClearanceService::isUnlocked` is true.
 *
 * Skipping the middle two is not merely slower to write: `pay` refuses a filing
 * that has not been billed (`isBillable`), so a fixture that submitted and paid
 * got a 422 and every test below it stared at a locked stage and reported that
 * the cards do not work — the least useful failure this suite could produce.
 * Every call is therefore asserted rather than fired and forgotten.
 *
 * BPLO's half runs on `biztrack.token.staff`, which is in this page because the
 * file merges two sessions (see `test.use` at the top).
 */
async function makePaidApplication(page: Page): Promise<number> {
  const appId = await makeSubmittedApplication(page)
  await approveAndPay(page, appId)
  return appId
}

/**
 * A filing that has been SUBMITTED and nothing more — the locked state with
 * cards in it.
 *
 * ── Why the locked tests can no longer use a draft ──────────────────────────
 *
 * They did, and it was right while the stage listed every clearance the register
 * knows about. It does not any more: `ClearanceService::forApplication` renders
 * *the filing's own* permit types (`$carried`), which a renewal forced — an
 * applicant renewing the Sanitary Permit alone would otherwise open this stage
 * looking at four cards for clearances the filing does not carry, is not billed
 * for and cannot grant, and Apply on one of those adds a permit to a filing
 * already priced and paid.
 *
 * A draft carries the business permit and nothing else. The five clearances are
 * attached by `attachRequiredPermitTypes` inside `submit()`, because the one Tax
 * Order of Payment cannot price what is not on the filing. So a draft's
 * clearance stage now draws a locked reason and ZERO cards, and the two tests
 * asserting "visible but locked" were asserting it of the one state that has
 * nothing to see.
 *
 * Submitted-and-unpaid is the state they were always about. It is "before the
 * first payment" exactly — BPLO has not read the form, nothing is payable, the
 * stage is shut — and the five are on the filing to be looked at, which is the
 * half the test exists for.
 */
async function makeSubmittedApplication(page: Page): Promise<number> {
  const appId = await makeCompleteDraft(page)
  await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const submitted = await fetch(`/api/v1/applications/${id}/submit`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
      },
    })
    if (!submitted.ok) {
      throw new Error(`submitting answered ${submitted.status}: ${await submitted.text()}`)
    }
  }, appId)
  return appId
}

/**
 * The middle of the walk: BPLO reads the form, then the applicant settles it.
 *
 * Split out of `makePaidApplication` because one test drives the submission
 * through the wizard — that is the half it is about — and still needs the two
 * acts that follow. Two copies of "find the queue item, classify, approve, pay"
 * is how the fixture and the test it verifies would drift apart.
 */
async function approveAndPay(page: Page, appId: number): Promise<void> {
  await page.evaluate(async (id) => {
    const publicToken = localStorage.getItem('biztrack.token.public')
    const staffToken = localStorage.getItem('biztrack.token.staff')
    const asOwner = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${publicToken}`,
    }
    const asBplo = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${staffToken}`,
    }

    /*
     * BPLO's queue item on this filing. Matched on the application rather than
     * taken as `data[0]`: the shared register holds other offices' work and
     * other runs' filings, and approving somebody else's would be a silent,
     * destructive pass.
     */
    const queue = await fetch(
      '/api/v1/assignments?application_status=for_approval&status=pending&per_page=100',
      { headers: asBplo },
    )
    if (!queue.ok) throw new Error(`reading BPLO's queue answered ${queue.status}`)
    const rows = (await queue.json()).data as { id: number; application: { id: number } | null }[]
    const assignment = rows.find((row) => row.application?.id === id)
    if (!assignment) throw new Error(`the filing ${id} is not on BPLO's queue after submission`)

    const classified = await fetch(`/api/v1/assignments/${assignment.id}/classification`, {
      method: 'POST',
      headers: asBplo,
      body: JSON.stringify({ tier: 'simple' }),
    })
    if (!classified.ok) {
      throw new Error(`classifying answered ${classified.status}: ${await classified.text()}`)
    }

    const approved = await fetch(`/api/v1/assignments/${assignment.id}/approve`, {
      method: 'POST',
      headers: asBplo,
      body: JSON.stringify({}),
    })
    if (!approved.ok) {
      throw new Error(`BPLO's approval answered ${approved.status}: ${await approved.text()}`)
    }

    const paid = await fetch(`/api/v1/applications/${id}/pay`, {
      method: 'POST',
      headers: asOwner,
      body: JSON.stringify({ method: 'gcash' }),
    })
    if (!paid.ok) throw new Error(`paying answered ${paid.status}: ${await paid.text()}`)
  }, appId)
}

/**
 * Press Apply on one clearance — the FIRST of the two acts, and only the first.
 *
 * This was "so a card is in the started state", and it no longer is. All five
 * clearances carry an office form (`PermitType::OFFICE_FORM_CODES` is the same
 * five as `REQUIRED_CLEARANCE_CODES`), and `WorkflowService::startClearance`
 * returns early for MODE_APPLY on a form-bearing permit: it records `mode` and
 * stops. The status stays `not_started`, nothing is routed, and no office has
 * anything to read until `submitClearanceForm()` hands the sheet in.
 *
 * That early return IS the client's report — *"I still haven't submitted any
 * applications yet the status says it is For Approval"* — so a fixture that
 * quietly walked past it would be re-asserting the bug.
 */
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
 * One clearance's state, as the SERVER has it.
 *
 * `application_permit_types.status` is the fact — `not_started` once the filing
 * is submitted and the permit is attached to be billed, `for_approval` once the
 * applicant starts it, then the office's own decisions. Reading it here rather
 * than inferring it from the card is what lets a test say "the filing did not
 * change" without depending on how the card happens to render at the time.
 */
async function readClearanceState(page: Page, appId: number, code: string): Promise<string> {
  return (await readClearance(page, appId, code)).state
}

/**
 * One clearance's state AND the route the applicant chose, as the server has
 * both.
 *
 * `mode` is on this payload because the state stopped being able to answer the
 * question on its own. Apply records `mode: 'apply'` and leaves the status at
 * `not_started`; only handing the sheet in moves it. So "has this applicant
 * pressed Apply" and "has the office got it" are two different reads now, and a
 * test that can only make the second cannot tell an untouched clearance from one
 * whose form is sitting half-filled — which is precisely the pair the two-act
 * split exists to keep apart.
 */
async function readClearance(
  page: Page,
  appId: number,
  code: string,
): Promise<{ state: string; mode: string | null }> {
  return page.evaluate(
    async ({ appId, code }) => {
      const token = localStorage.getItem('biztrack.token.public')
      const res = await fetch(`/api/v1/applications/${appId}/clearances`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
      const rows = (await res.json()).data as {
        permit_type: { code: string }
        state: string
        mode: string | null
      }[]
      const row = rows.find((r) => r.permit_type.code === code)
      return { state: row?.state ?? 'missing', mode: row?.mode ?? null }
    },
    { appId, code },
  )
}

/** What this filing has been assessed, as a number. */
async function readTotal(page: Page, appId: number): Promise<number> {
  return page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const res = await fetch(`/api/v1/applications/${id}/fee`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
    return Number((await res.json()).data.total_amount)
  }, appId)
}

/**
 * The five clearance cards, found by the grid that holds them.
 *
 * ── Two rounds of finding these by their buttons, both of which rotted ──────
 *
 * It read `.filter({ hasText: /apply/i })` first — a filter on the FACE of the
 * Apply button, which reads "Applied ✓" once the clearance is applied for, and
 * "Applied" does not contain "apply". Then it read
 * `.filter({ has: … /^appl(y|ied) for the /i })`, on the ACCESSIBLE name, which
 * was meant to be the stable half. It is not stable either: splitting apply into
 * two acts gave that control three names ("Apply for the ‹clearance›", "Finish
 * the ‹clearance› form — you applied but have not submitted it", "View the
 * ‹clearance› form you submitted"), so a card the applicant had pressed Apply on
 * matched nothing and the grid came back one short.
 *
 * `#clearance-cards` is what does not move. It is an id the page carries for
 * exactly this purpose, it is on the <ul> rather than on any control inside it,
 * and it cannot go stale when a button is renamed — which is the failure this
 * helper has now had twice. Naming the card by a control that changes name with
 * the card's state was the mistake both times.
 */
function clearanceCards(page: Page) {
  return page.locator('#clearance-cards > li')
}

/** The one card for a clearance, by the name printed on it. */
function clearanceCard(page: Page, name: RegExp) {
  return clearanceCards(page).filter({ hasText: name })
}

/**
 * The Apply control on a card, under any of the three names it now answers to.
 *
 * Apply used to be one act with one name. It is two acts now — `startClearance`
 * records the chosen mode and opens the office form, `submitClearanceForm` hands
 * it in — so the control reports which of three points the applicant has reached:
 *
 *   "Apply for the ‹clearance›"                          nothing chosen yet
 *   "Finish the ‹clearance› form — you applied but …"    mode recorded, not sent
 *   "View the ‹clearance› form you submitted"            the office has it
 *
 * Matched as a set, because every test here that presses this button is about
 * what the PRESS does; the name is the subject of exactly one test below, which
 * pins it exactly rather than through this helper.
 */
function applyControl(card: ReturnType<typeof clearanceCards>) {
  return card.getByRole('button', { name: /^(apply for|finish|view) the /i })
}

/**
 * The hand-in-a-copy control, which is no longer named "Submit".
 *
 * The client: *"instead of 'Submit' why not 'Upload an existing copy' to avoid
 * confusion?"* — because the office sheet's own button also read Submit, and one
 * verb meant both "hand the office my answers" and "hand them a certificate I
 * already hold". Two names here, one per state, same as Apply above.
 */
function uploadControl(card: ReturnType<typeof clearanceCards>) {
  return card.getByRole('button', {
    name: /^(upload an existing copy of the|replace the .* copy you uploaded)/i,
  })
}

/**
 * The way off an open office sheet.
 *
 * Both of the old controls are gone. "Save & back to clearances" went when
 * autosave took the saving job away from the footer, and "Back without saving"
 * went with it — the client called it unnecessary and autosave made it untrue.
 * One button remains and it is navigation rather than a decision about saving.
 */
function backToCards(page: Page) {
  return page.getByRole('button', { name: /^back to clearances$/i })
}

test('before the first payment the stage is visible but locked, in the API’s own words', async ({
  page,
}) => {
  /*
   * The gate, and the direction it now points.
   *
   * An unpaid filing is LOCKED. This test asserted the opposite between 4 and 28
   * August — "a draft can still choose its clearances" — because the six were
   * then a step of the wizard and payment was the last thing that happened. It
   * is not a weakening to invert it: what is being asserted is the same property
   * in both cases, that the stage is open exactly when the server says it is and
   * says why when it is not.
   *
   * Visible-but-locked rather than hidden or 404, deliberately. The cards are
   * how an applicant finds out which clearances exist and what they cost, and
   * that is worth knowing before you can act on it — "where do I get my
   * sanitary permit" is the question this page answers even while shut.
   */
  await onDashboard(page)
  /*
   * Submitted, unapproved, unpaid — which is "before the first payment" and is
   * the earliest point at which there is anything on this stage to look at. It
   * was a draft, and a draft now draws no cards at all: see
   * `makeSubmittedApplication` for why the five arrive at submission rather than
   * being listed from the register.
   */
  const appId = await makeSubmittedApplication(page)

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
  const apply = applyControl(cards.first())
  await expect(apply).toHaveAttribute('aria-disabled', 'true')

  /*
   * The balance is on screen even while the stage is shut, and that is the less
   * obvious half of the rule. A locked stage means the bill has not been settled,
   * so the balance is exactly what the applicant must pay to open it — the most
   * actionable number on the page. Hiding it until it stops mattering would be
   * precisely backwards.
   */
  /*
   * `exact` on the term, because "balance due" also appears in the sentence
   * above the grid. The <dt> is the ledger; the paragraph is a description of
   * it, and matching either would let this pass on a page that had lost the
   * block entirely.
   */
  await expect(page.getByText('Balance due', { exact: true })).toBeVisible()

  /*
   * ── What holds the permit back, and what no longer does ───────────────────
   *
   * This waited for `/not released.*balance reaches zero|balance is unpaid/i`,
   * which was the accrual arrangement's sentence: each Apply raised the balance
   * and the Mayor's Permit was withheld until the running total was settled.
   * Neither half survives. `ClearanceService::reassess()` is gone, so applying
   * moves no money, and the release gate is five APPROVED PERMITS
   * (`WorkflowService::refreshReadiness`) rather than a zero balance.
   *
   * So the rule is asserted in its new form and the old promise is asserted
   * absent — a page that quietly grew "balance reaches zero" back would be
   * telling an applicant to pay their way past an inspection.
   *
   * The sentence the page shows depends on which side of the bill the filing is
   * on, and this one is unpaid: the Tax Order of Payment is raised and nothing
   * has cleared, so the paragraph is the owes-money branch. What has to survive
   * on THIS side is that applying is free — an applicant staring at a balance,
   * about to press five buttons, is the one most likely to believe each press
   * adds to it. The five-approvals half renders on the settled branch and is
   * asserted in the test below, where it is what the page actually says.
   */
  await expect(page.getByText(/applying for the clearances below adds nothing to it/i)).toBeVisible()
  await expect(
    page.getByText(/balance reaches zero/i),
    'the screen still holds the permit against a balance rather than against the five approvals',
  ).toHaveCount(0)
})

test('once the stage is open, every card states its price and the ledger behind it', async ({
  page,
}) => {
  /*
   * Renamed. It was "the grid says which button spends money and how much",
   * which was the accrual arrangement's question — Apply raised a balance, so
   * the interesting fact about the grid was which of two look-alike buttons
   * cost money. Neither does now: one Tax Order of Payment at submission prices
   * every permit here and the applicant settled it to get this far. What is
   * still worth asserting is that the money is all on screen and checkable,
   * which is what the ledger and the per-card amount are for.
   *
   * The sentence above the grid still promises that Apply adds a fee, and that
   * promise is the subject of its own test below rather than being quietly
   * dropped here.
   */
  await onDashboard(page)
  const appId = await makePaidApplication(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  // Five, all required. See the locked test above for what the sixth was.
  const cards = clearanceCards(page)
  await expect(cards).toHaveCount(5, { timeout: 30_000 })

  // Paid, so open: no reason is shown, because there is nothing to explain.
  await expect(page.locator('#clearances-locked')).toHaveCount(0)
  const apply = applyControl(cards.first())
  await expect(apply).toHaveAttribute('aria-disabled', 'false')

  /*
   * ── The price came off the cards, and this asserts that it stays off ───────
   *
   * This looped every card for "Fee ₱… | No fee assessed | Fee set by this
   * office" and called a card without one "a clearance card quotes no price at
   * all". That was right while Apply was the moment of commitment: `apply()`
   * re-ran the assessment, each press moved `balance_due`, and there was nowhere
   * downstream to read the price before agreeing to it.
   *
   * `assessFees()` runs once, at submission, over the business permit and all
   * five clearances, and `ClearanceService::reassess()` is gone — so the number
   * on the card was quoting a charge that is never coming, three inches above a
   * ledger reading ₱0.00. The client, on being shown it: *"Would displaying the
   * fee still matter because we have already paid it for that beforehand, right?
   * If not, kindly remove it."*
   *
   * So the assertion inverts rather than disappears, because the danger did: a
   * per-card amount over an Apply button now reads as a threat that the press
   * costs that much again. The breakdown is not lost — a per-permit figure
   * belongs on the Tax Order of Payment, which is reachable from the filing —
   * and the total is asserted on the ledger immediately below.
   */
  for (const card of await cards.all()) {
    await expect(
      card,
      'a card quotes a price over Apply on a filing whose Tax Order of Payment is settled',
    ).not.toContainText(/fee ₱|no fee assessed|fee set by this office/i)
  }

  /*
   * And the ledger. Three figures, so the balance is checkable rather than
   * merely trusted — "you owe ₱0" is not an answer on its own.
   */
  await expect(page.getByText(/^assessed$/i)).toBeVisible()
  await expect(page.getByText(/^paid$/i)).toBeVisible()
  await expect(page.getByText(/^balance due$/i)).toBeVisible()

  /*
   * And what actually holds the Business Permit back, which only this side of
   * the bill states. `WorkflowService::refreshReadiness` releases on five
   * APPROVED permits, not on a settled balance — the balance is already zero
   * here — so a page that grew "balance reaches zero" back would be telling an
   * applicant to pay their way past an inspection. The locked test above asserts
   * that string absent; this asserts the rule that replaced it, on the filing
   * whose paragraph is supposed to carry it.
   */
  await expect(page.getByText(/released once all of them are approved/i)).toBeVisible()

  /*
   * And no badge on a card nobody has touched. "Not requested" used to sit on
   * every one of them — *"tf does 'not requested' even mean"* — which is a
   * status a screen reader reads out five times to say that nothing has
   * happened five times.
   */
  await expect(cards.first()).not.toContainText(/not requested/i)
})

test('the grid never charges for what the Tax Order of Payment already covered', async ({
  page,
}) => {
  /*
   * ── This was LEFT RED on purpose, and the paragraph has since been fixed ───
   *
   * What stood here was the screen contradicting itself about money. Two
   * paragraphs rendered one above the other on a paid filing:
   *
   *   ledger  "Nothing is outstanding — your Tax Order of Payment covered every
   *            permit below, so applying for them costs nothing further."
   *   grid    "Choose the ones your business needs. Apply adds that office's fee
   *            to your balance due; Submit a copy of one you already hold costs
   *            nothing."
   *
   * The ledger sentence was the one that matched the code, and the grid's was
   * the accrual's copy left standing — a button promising a charge the server
   * will not make, three inches under a sentence saying the opposite. "Choose
   * the ones your business needs" was from the same era: all five are required
   * (`PermitType::REQUIRED_CLEARANCE_CODES`), so there was nothing to choose
   * between.
   *
   * The paragraph above `<ul id="clearance-cards">` now reads: every one of
   * these is required and already covered by the payment made, Apply opens that
   * office's form, Upload an existing copy hands them a certificate you hold,
   * and NEITHER costs anything further. So the two absences below are kept —
   * they are what must not come back in a merge — and the third assertion moves
   * from the half-truth it was checking ("Submit a copy costs nothing", which
   * implied Apply did not) to the symmetry that is actually true.
   */
  await onDashboard(page)
  const appId = await makePaidApplication(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })
  /*
   * The CARDS, not the heading, before any "this text is absent" assertion.
   *
   * The heading comes from the surrounding layout and is on screen while
   * `ClearanceStagePage` is still returning skeletons, so a `toHaveCount(0)`
   * taken straight after it passes against a page that has not drawn its
   * paragraphs yet. That is a green light wired to nothing, and it is exactly
   * how this test first reported the copy below as already fixed.
   */
  await expect(clearanceCards(page)).toHaveCount(5, { timeout: 30_000 })

  await expect(
    page.getByText(/apply adds that office.s fee/i),
    'the grid promises a fee that applying does not charge — one bill at submission covers all five',
  ).toHaveCount(0)
  await expect(
    page.getByText(/choose the ones your business needs/i),
    'the grid offers a choice between five permits that are all required',
  ).toHaveCount(0)

  /*
   * What has to be said before either press. It is no longer an asymmetry — one
   * bill at submission covered both routes — so the sentence names the real
   * difference (what the office is given to read) and then closes the money
   * question for both buttons at once. "Neither" is the load-bearing word: drop
   * it and the paragraph goes back to implying that one of them charges.
   */
  await expect(
    page.getByText(/upload an existing copy.*certificate you already hold/i),
  ).toBeVisible()
  await expect(
    page.getByText(/neither costs anything further/i),
    'the grid stopped saying that both routes are already paid for',
  ).toBeVisible()
})

test('a locked Apply stays reachable, and refuses to do anything', async ({ page }) => {
  /*
   * The accessibility half of the lock, split out from the test above because
   * it is a different claim: not "the stage is shut" but "shutting it did not
   * make it invisible to anyone navigating by keyboard or screen reader".
   */
  await onDashboard(page)
  // Submitted and unpaid, so shut — and with the five on the filing, so there
  // are buttons to be reachable. Same reason as the test above.
  const appId = await makeSubmittedApplication(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const cards = clearanceCards(page)
  await expect(cards).toHaveCount(5, { timeout: 30_000 })

  const reason = page.locator('#clearances-locked')
  await expect(reason).toBeVisible()
  const shown = (await reason.textContent())?.trim() ?? ''
  expect(shown.length, 'the shut stage shows no reason at all').toBeGreaterThan(20)

  /*
   * The buttons stay in the tab order. `disabled` drops a control out of it and
   * most screen readers pass over it, so an applicant using one would never
   * learn the button exists or why it does nothing.
   */
  const apply = applyControl(cards.first())
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
  await expect(backToCards(page)).toBeHidden()

  const upload = uploadControl(cards.first())
  await upload.dispatchEvent('click')
  await page.waitForTimeout(500)
  await expect(page.getByRole('dialog')).toBeHidden()
})

/*
 * ── LEFT RED DELIBERATELY: a second Apply opens nothing and says 422 ────────
 *
 * Three tests in this file are red for this one defect, and it is the headline
 * rule of the file: Apply always opens that office's form.
 *
 * `applyNow` decides whether to re-post from `clearanceStarted(row.state)`, and
 * the card's own comment says why it decides at all — *"re-posting would ask the
 * server to attach what is already attached, and on a screen that commits the
 * applicant's money 'probably idempotent' is not good enough."* That predicate
 * was true of Apply while Apply moved the permit to `for_approval`. It does not
 * any more: `startClearance` returns early for a form-bearing permit, records
 * `mode` and stops, so the status stays `not_started` and `clearanceStarted`
 * stays false on a clearance the applicant has demonstrably applied for.
 *
 * So the second press re-posts, and the server refuses it. Verified against this
 * stack, as the applicant's own session:
 *
 *     POST …/clearances/ZONING/apply   → 200, state not_started, mode apply
 *     POST …/clearances/ZONING/apply   → 422, "You have already applied for the
 *                                       Zoning / Locational Clearance on this
 *                                       application."
 *
 * `ClearanceController::apply` aborts on `ClearanceService::isAppliedFor`, which
 * was ALREADY patched for exactly this — it reads `status !== NotStarted ||
 * mode !== null`, and its docblock says in as many words that status alone
 * "reported 'not applied for' about a permit the applicant had demonstrably
 * applied for". The screen's copy of the same question never got the second
 * half. `runAction` then returns false, `applyNow` returns early, and the sheet
 * never opens.
 *
 * What it costs is not a repeated click. The button on that card reads "Finish
 * form" — it is the control for going back to a half-filled sheet, which is the
 * ordinary way to finish one, and the applicant who presses it gets an error
 * banner telling them they have already applied. There is no other way back in.
 *
 * Application code, so it is reported rather than made green. The fix is the one
 * line that brings the screen's predicate level with the server's: `applyNow`
 * skips the post when the clearance has been started OR a mode is recorded.
 */
test('Apply always opens that office’s form, and never un-applies', async ({ page }) => {
  await onDashboard(page)
  const appId = await makePaidApplication(page)
  // SANITARY is one of the four clearances with an applicant-facing sheet, and
  // applying first makes this the purest statement of the property: Apply on an
  // already-applied clearance must open the form and must not undo anything.
  await applyFor(page, appId, 'SANITARY')

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const card = clearanceCard(page, /sanitary/i)
  await expect(card).toHaveCount(1)
  /*
   * Located through `applyControl`, which matches the name in ANY of its three
   * states.
   *
   * It pinned `/^applied for the/i`, then `/^appl(y|ied) for the /i`, and both
   * read the card's own idea of the state rather than the server's. The name is
   * the subject of exactly one test below; pinning it here as well would make a
   * rename fail two tests and hide THIS rule, which is about the click and not
   * about the label: Apply opens the office form, every time, and undoes
   * nothing.
   */
  const apply = applyControl(card)

  // Under the old toggle this click un-applied it and opened nothing.
  await expect(apply).toBeVisible()

  const back = backToCards(page)
  /*
   * Both halves of the row, because "undoes nothing" now has two halves to
   * undo. Apply records `mode` and leaves the status alone, so a press that
   * silently cleared the mode would take the applicant's choice off the filing
   * while leaving `not_started` looking untouched — invisible to a check on the
   * state by itself.
   */
  const rowOf = () => readClearance(page, appId, 'SANITARY')
  const before = await rowOf()

  await apply.click()
  await expect(back, 'Apply did not open the office form').toBeVisible()

  await back.click()
  await expect(back).toBeHidden()

  /*
   * Still as it was, read off the server rather than off the button. This is the
   * "never un-applies" half, and it is the half the original bug (aabbf21)
   * broke: the second press silently detached the permit. Asking the API is
   * also what makes it a claim about the FILING rather than about paint.
   */
  expect(await rowOf(), 'Apply took the clearance back off the filing').toEqual(before)

  // And again. A toggle would open nothing the second time.
  await apply.click()
  await expect(back, 'a second Apply did not open the office form').toBeVisible()
  await back.click()
  expect(await rowOf(), 'a second Apply took the clearance back off the filing').toEqual(before)
})

test('Upload an existing copy always opens the upload box, and never removes what is there', async ({
  page,
}) => {
  await onDashboard(page)
  const appId = await makePaidApplication(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const card = clearanceCard(page, /sanitary/i)
  const dialog = page.getByRole('dialog')

  await uploadControl(card).click()
  await expect(dialog, 'Upload an existing copy did not open the upload box').toBeVisible()
  await expect(dialog).toContainText(/choose your certificate/i)
  /*
   * The consequence, on the box itself. The two controls sit side by side and
   * look alike, and the applicant has to know which one they just pressed
   * before they pick a file.
   */
  await expect(dialog).toContainText(/nothing is added to your fees/i)

  await dialog.getByRole('button', { name: /^cancel$/i }).click()
  await expect(dialog).toBeHidden()

  // Again. Under the old toggle a second press DELETED the uploaded file.
  await uploadControl(card).click()
  await expect(dialog, 'a second press did not open the upload box').toBeVisible()
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
async function uploadCopy(page: Page, confirm: RegExp): Promise<void> {
  const dialog = page.getByRole('dialog')
  await dialog
    .locator('input[type=file]')
    .setInputFiles({ name: 'certificate.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 e2e') })
  await expect(dialog).toContainText(/certificate\.pdf/i)
  await dialog.getByRole('button', { name: confirm }).click()
  await expect(dialog).toBeHidden()
}

/*
 * ── 'withdrawing has its own named control, and Apply is never it' is GONE ───
 *
 * That test asserted a Withdraw link on an applied card, the announcement it
 * made, and that a second press of Apply was not it. It came back in the first
 * place because deleting the control had cost 15 real drafts a way out of an
 * accidental Apply (audit, 2026-08-06).
 *
 * Its subject no longer exists. All five clearances are required of every
 * application (`PermitType::REQUIRED_CLEARANCE_CODES`) and are attached at
 * submission so the one Tax Order of Payment can price them, so there is no
 * such thing as applying for one by mistake — `ClearanceService::unapply()`
 * refuses a required permit with a 422 before it detaches anything. A control
 * offering a withdrawal the server will refuse is CLR-4, the bug on the other
 * screen, so its absence here is right rather than regrettable.
 *
 * What survives of it is asserted rather than lost:
 *   - the refusal itself — 'a required permit cannot be withdrawn from the
 *     application', at the foot of this file;
 *   - "Apply is never a second meaning" — 'Apply always opens that office's
 *     form, and never un-applies', above.
 *
 * If an OPTIONAL clearance is ever added back, the original is in git history
 * at 5f7a0b1~1 and comes back pointed at that permit.
 */

/*
 * CLR-1, the reported sequence, end to end and with a real file.
 *
 * The client's words: *"I cannot remove my application on the Zoning/Locational
 * Clearance once I changed my mind to Submit instead of Apply."* Apply, then
 * Submit with a certificate — the one sequence in the product that failed, and
 * the one that appeared in no test, because reaching the failure needs a file
 * and nothing here had ever picked one.
 *
 * ── What changed under it, and why this is not the same test ────────────────
 *
 * It asserted a withdrawal on the way through: the dialog warned that the
 * application would be "withdrawn", the confirm read "Withdraw & submit", and a
 * live region said so afterwards. That was the mutual exclusion talking — a
 * clearance could be applied for OR handed in, never both, and `storeHeld`
 * refused the overlap.
 *
 * There is no exclusion now. `submitHeld()` and `apply()` go through the same
 * door (`WorkflowService::startClearance`, differing only in `mode`), the fee
 * was settled at submission and covers the inspection either way, and posting a
 * held copy over a started clearance is simply accepted — verified against this
 * stack, 200 with no withdrawal and no change of balance. So the sequence the
 * client could not complete now completes with nothing taken back, and that is
 * what is asserted: the same journey, the outcome it should always have had.
 *
 * ── This was LEFT RED, and the deletion it asked for has been made ────────
 *
 * `ClearanceStagePage.onSubmitHeld` used to withdraw first whenever the
 * clearance had been started (`if (switching) await clearances.unapply(...)`),
 * and the dialog named its confirm "Withdraw & submit". Every one of the five is
 * REQUIRED, and `ClearanceService::unapply` refuses a required permit with a
 * 422 — so the withdrawal threw before the upload was attempted and the
 * applicant got an error banner instead of a filed certificate. That was the
 * client's original report reached by the opposite road: the server refusing the
 * upload became the screen refusing to try.
 *
 * Both are gone. `storeHeld` swaps `mode` in place inside one transaction, so
 * there is nothing to withdraw, and the confirm reads "Upload this copy". What
 * the test asserts is unchanged — the journey completes and takes nothing back —
 * and the names it presses have moved with the fix.
 *
 * ZONING deliberately, which is the card in the client's screenshot.
 */
test('changing your mind from Apply to Submit works, and takes nothing back to do it', async ({
  page,
}) => {
  await onDashboard(page)
  const appId = await makePaidApplication(page)
  await applyFor(page, appId, 'ZONING')
  const totalBefore = await readTotal(page, appId)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const card = clearanceCard(page, /zoning/i)
  await uploadControl(card).click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()
  await expect(dialog).toContainText(/choose your certificate/i)

  await uploadCopy(page, /^upload this copy$/i)

  /*
   * The card is on the other leg, and there is no 422 banner in sight. This is
   * the client's report, inverted into the assertion that it works: the copy is
   * on file, it can be replaced, and it can be taken back off.
   *
   * ── What the card offers afterwards, and why it is Remove rather than ─────
   *    Replace
   *
   * This asserted a Replace control, from the days when uploading was a passive
   * act that left the permit where it was. MODE_UPLOAD submits immediately now —
   * the file IS the answer, so there is nothing further for the applicant to
   * give — which puts the permit straight into `for_approval`. `handedIn` is
   * true from that moment and the upload control is not drawn at all: swapping
   * the evidence under a permit the office has accepted is refused by
   * `storeHeld`, and offering a control the server will refuse is CLR-4 by name.
   *
   * Remove is the one that survives, and it is the one that matters here. The
   * client's report was about not being able to take something back; a copy on
   * file with a named way off it is the answer to it.
   */
  await expect(card.getByRole('button', { name: /remove the .* copy/i })).toBeVisible()
  await expect(card).toContainText(/certificate\.pdf/i)
  await expect(page.getByText(/withdraw that request first/i)).toHaveCount(0)

  // The clearance is still on the filing, and the bill has not moved. Handing
  // in a permit you already hold does not reduce what it cost, because the fee
  // covers the inspection the LGU still carries out.
  expect(
    await readClearanceState(page, appId, 'ZONING'),
    'submitting a copy silently took the clearance off the filing',
  ).toBe('for_approval')
  expect(await readTotal(page, appId), 'submitting a copy moved the bill').toBe(totalBefore)
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
  await onDashboard(page)
  const appId = await makePaidApplication(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  const card = clearanceCard(page, /sanitary/i)
  await uploadControl(card).click()
  await uploadCopy(page, /^upload this copy$/i)
  await expect(card.getByRole('button', { name: /remove the .* copy/i })).toBeVisible()

  /*
   * Apply, and stop.
   *
   * Pressed through `applyControl` rather than by name, because the name on this
   * card is now "View the ‹clearance› form you submitted" — uploading a copy
   * hands the permit to the office, `handedIn` goes true, and the control is
   * relabelled for reading a form. It still runs `onApply`, so the button a
   * card presents as View is the button that offers to delete the applicant's
   * certificate. That is part of what is reported below rather than a separate
   * defect.
   */
  await applyControl(card).click()
  const warning = page.getByRole('dialog')
  await expect(warning, 'Apply deleted the uploaded copy without asking').toBeVisible()
  // The file is named. "Your copy" is not what is about to be lost; a specific
  // file the applicant chose is, and naming it is what makes this a decision.
  await expect(warning).toContainText(/certificate\.pdf/i)
  await warning.getByRole('button', { name: /keep my copy/i }).click()

  await expect(warning).toBeHidden()
  await expect(card.getByRole('button', { name: /remove the .* copy/i })).toBeVisible()
  await expect(applyControl(card)).toBeVisible()

  // Now agree to it. The confirm says Delete, because that is what it does.
  await applyControl(card).click()
  await page.getByRole('dialog').getByRole('button', { name: /^delete & apply$/i }).click()

  /*
   * ── LEFT RED DELIBERATELY: the file is destroyed and the apply then fails ──
   *
   * This test used to be red for a smaller thing — the deletion was silent. It
   * is red for a worse one now, and the silence is a symptom of it.
   *
   * `applyNow` runs the removal, then posts the apply. Verified against this
   * stack, as the applicant's own session:
   *
   *     DELETE …/clearances/SANITARY/held   → 200, the file is gone from disk
   *     POST   …/clearances/SANITARY/apply  → 422, "You have already applied
   *                                           for the Sanitary Permit / Health
   *                                           Certificate on this application."
   *
   * The 422 is the same one described above 'Apply always opens that office's
   * form' — `isAppliedFor` refusing a permit that already carries a mode — but
   * the route in is its own defect and needs its own fix. `destroyHeld` forgets
   * the file and leaves the pivot exactly where `storeHeld` put it:
   * `for_approval`, mode `upload`. So even a screen whose predicate was correct
   * would find nothing to apply for here. The applicant pressed a button, agreed
   * to lose their certificate, and got neither the certificate nor the
   * application form: an error banner, a card with nothing on it, and no way
   * back without the original file.
   *
   * The silence follows from the same failure. `runAction` only writes the live
   * region on SUCCESS, and the one message covering both halves of the act
   * ("Applied for your …, and deleted the copy you had uploaded") rides on the
   * apply that 422s — so the deletion that did happen is announced by nothing.
   *
   * Application code, so it is reported rather than made green. The fix is that
   * taking a held copy back off has to return the permit to a state an apply can
   * be made from: `destroyHeld` resetting the row to `not_started` with a null
   * mode, which is what `unapply` already does for the other route. Two smaller
   * things go with it — a control the card labels "View form" must not be the
   * one that offers to delete the file, and the removal needs its own sentence
   * for the case where the apply that would have carried it does not run.
   */
  await expect(
    backToCards(page),
    'the copy was deleted and the apply behind it failed, so the office form never opened',
  ).toBeVisible()
  await backToCards(page).click()

  // The copy is gone — which is the whole of what was agreed to. What the
  // button reads afterwards is the subject of 'applying is reported on the
  // button' below.
  await expect(card.getByRole('button', { name: /remove the .* copy/i })).toHaveCount(0)
  await expect(
    page.getByRole('status').filter({ hasText: /deleted the copy/i }),
    'the uploaded copy was deleted without a word to anyone using a screen reader',
  ).toBeVisible()
})

test('what just happened is announced, not only drawn', async ({ page }) => {
  await onDashboard(page)
  const appId = await makePaidApplication(page)

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })

  /*
   * Applying starts a request an office will act on and books an inspection of
   * the premises. A card that changes silently is invisible to a screen reader:
   * they hear the button, then nothing, and have no way to know whether the
   * press did anything at all.
   *
   * ── This test caught the press doing literally nothing ────────────────────
   *
   * `applyNow` posted only when `row.state === 'available' || 'submitted'`, and
   * neither value can occur since `attachRequiredPermitTypes` began attaching
   * all five at submission — an untouched clearance arrives here as
   * `not_started`. The press fell through, opened the office sheet, posted
   * nothing and announced nothing, so no office was ever given the work and the
   * filing could never be approved. The guard reads `not_started` now.
   *
   * The announcement no longer claims a fee was added, and that is the accrual
   * going rather than a wording preference: one bill at submission covers all
   * five, so "its fee has been added to your balance due" described a charge
   * that does not happen.
   *
   * Both halves are asserted, because they are separate failures. A test that
   * only read the live region could not tell "said nothing" from "did nothing".
   *
   * SANITARY, which has an office sheet, so the press and the return are both
   * part of the test: Apply opens the sheet, and what it did is still being
   * announced when the applicant comes back to the cards.
   */
  const card = clearanceCard(page, /sanitary/i)
  await applyControl(card).click()

  const back = backToCards(page)
  await expect(back, 'Apply did not open the office sheet').toBeVisible()
  await back.click()

  const status = page.getByRole('status').filter({ hasText: /applied for your/i })
  await expect(status, 'pressing Apply said nothing a screen reader could hear').toBeVisible()

  /*
   * And it really happened, rather than merely being announced. The announcement
   * and the write are separate failures and a test that only reads the live
   * region cannot tell them apart.
   *
   * ── What the write IS, now that apply is two acts ─────────────────────────
   *
   * This asserted `for_approval`, and asserting it back would be re-asserting
   * the bug. `startClearance` returns early for MODE_APPLY on a form-bearing
   * permit — all five are — so the press records `mode` and stops: nothing is
   * routed, no office has anything to read, and the status stays `not_started`.
   * The client's words when it did otherwise: *"I still haven't submitted any
   * applications yet the status says it is For Approval."* CENRO saw the other
   * half of the same wrong claim — a queue row with no answers on it.
   *
   * So both fields are read, and the pair is the assertion. `mode` alone would
   * pass on a press that also routed the office; `state` alone cannot tell a
   * press that recorded nothing from one that did. The sentence the live region
   * says — "fill it in and press Save" — is only true of exactly this pair.
   */
  expect(
    await readClearance(page, appId, 'SANITARY'),
    'pressing Apply either recorded nothing, or handed the office a form nobody had filled in',
  ).toEqual({ state: 'not_started', mode: 'apply' })
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
   *
   * ── What this caught ──────────────────────────────────────────────────────
   *
   * The card computed `applied` as `row.state === 'applied' || 'issued'`, and
   * `application_permit_types.status` produces neither string — its values are
   * `not_started`, `for_approval`, `for_inspection`, `approved`, `rejected` and
   * `returned` (`App\Enums\ClearanceStatus`). The flag was false on every card
   * in every state, so the control stayed named "Apply for the ‹clearance›" even
   * on a clearance whose office was already reviewing it. The status chip beside
   * the name had been removed on the argument that the button was the honest
   * place for the state; the button then stopped carrying it, and the state was
   * nowhere on the card at all. `hasApplied()` is what answers it now.
   */
  await onDashboard(page)
  const appId = await makePaidApplication(page)
  await applyFor(page, appId, 'SANITARY')

  await page.goto(`/applications/${appId}/clearances`)
  const card = clearanceCard(page, /sanitary/i)

  /*
   * The state is on the control that changed it, not in a separate badge — and
   * the state it has to report has grown a middle.
   *
   * It read "Applied ✓", and that was a label for a finished thing worn by a
   * clearance whose form had never been saved: the applicant HAD applied, the
   * office had nothing to read, and the tick claimed the opposite. Splitting
   * apply into two acts made the difference real rather than cosmetic — the
   * permit genuinely sits at `not_started` with a mode recorded — so the button
   * names the work that is left instead of the transaction that happened.
   *
   * Pinned exactly, not through `applyControl`, because this is the one test
   * that owns the NAME. The point of the sentence is the second clause: a
   * control reading only "Applied" is what let two of the five clearances on the
   * register's filing 5 sit applied-for and empty.
   */
  const apply = card.getByRole('button', {
    name: /^finish the .* form — you applied but have not submitted it$/i,
  })
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
   * it is why the controls that survived read one word and put the clearance in
   * their accessible name. Asserted as a property of every control on the card,
   * so it also holds for the next one somebody adds.
   *
   * Whether a withdraw control should be on this card at all is not asked
   * here — all five clearances are required and `unapply()` refuses them, so
   * the answer is no, and it is asserted at the foot of this file where the
   * refusal is. This loop only says that whatever controls the card carries are
   * named the way the client asked.
   */
  for (const label of await card.getByRole('button').allInnerTexts()) {
    /*
     * The rule itself, asserted directly. It was a length budget pegged to
     * "Submitted ✓" — a proxy, and one that has already rotted twice: the face
     * of these buttons now reads "Upload a copy" and "Copy uploaded", both
     * thirteen characters, both perfectly fine, and neither carrying a clearance
     * name anywhere. A proxy that fails on a two-character rename is a test of
     * the file rather than of the rule.
     */
    expect(
      label.trim(),
      `"${label.trim()}" prints its clearance's name on the face of the card`,
    ).not.toMatch(/sanitary|health certificate/i)
    /*
     * And the shape the proxy was reaching for, re-pegged to the longest label
     * the card actually carries. What wrapped the old control onto two lines was
     * a button naming its clearance in full; this keeps the ceiling near the
     * controls that survived, so the next one somebody adds has to earn its
     * width.
     */
    expect(label.trim().length, `"${label.trim()}" is too long for this card`).toBeLessThanOrEqual(
      'Copy uploaded'.length,
    )
  }
  await expect(card.getByRole('button', { name: /don’t apply/i })).toHaveCount(0)

  /*
   * Every control on the grid is named for ITS clearance. The visible labels
   * are one word and identical on all six cards, so the accessible name is the
   * only thing telling them apart.
   */
  await expect(apply).toHaveAccessibleName(/sanitary/i)
  await expect(uploadControl(card)).toHaveAccessibleName(/sanitary/i)

  /*
   * Pressing it again opens the office form. It must NOT un-apply.
   *
   * Asserted on the way back rather than on the press. It read
   * `toHaveCount(0)` on "Apply for the ‹clearance›" straight after the click,
   * which is satisfied by anything at all — the sheet REPLACES the grid, so
   * every control on every card is gone at that moment and the assertion held
   * for a page that had thrown the filing away. Coming back to the cards is what
   * makes it a claim about the clearance: the control still reports an
   * application in progress, which a toggle would have undone.
   *
   * ── LEFT RED DELIBERATELY, from here down ─────────────────────────────────
   *
   * The press answers 422 and opens nothing. Same defect as the one written out
   * above 'Apply always opens that office's form, and never un-applies':
   * `applyNow` re-posts because `clearanceStarted(row.state)` cannot see an
   * applied-for permit any more, and the server refuses the duplicate. The
   * assertions above this point pass, so what is red here is precisely the
   * second press, which is the rule this test exists for.
   */
  await apply.click()
  const back = backToCards(page)
  await expect(back, 'a second Apply did not reopen the office form').toBeVisible()
  await back.click()

  await expect(apply, 'a second Apply took the application back off the card').toBeVisible()
  await expect(
    card.getByRole('button', { name: /^apply for the /i }),
    'the card went back to offering an Apply that had already been made',
  ).toHaveCount(0)
})

test('one bill at submission covers all five, and applying adds nothing to it', async ({
  page,
}) => {
  /*
   * ── The rule this test asserts, and the two it used to ────────────────────
   *
   * This test has now been written three times, once per ordering, and the name
   * changed each time because the RULE changed each time. It began as *"the
   * wizard puts the clearances last, and one Tax Order of Payment covers them"*;
   * it was inverted into *"the wizard bills the business permit alone, and each
   * clearance accrues after payment"*; it is neither now.
   *
   * The property being defended has never moved: the money lands where the flow
   * says it does, and the applicant is told so before they commit. What the flow
   * says (docs/application-flow-2026-09.md) is:
   *
   *   1. the wizard is the business permit form and has no clearance step;
   *   2. submitting attaches all five required clearances and assesses ONE Tax
   *      Order of Payment over every one of them — the fire clearance's fee is
   *      on the bill before the applicant has pressed anything about fire;
   *   3. nothing is payable until BPLO has read the form, and the stage stays
   *      shut through both waits;
   *   4. paying opens it;
   *   5. applying for a clearance adds NOTHING. `ClearanceService::reassess()`
   *      is gone; the bill does not move.
   *
   * The fire clearance is still the one pressed, for the reason it always was:
   * its office sheet has no required field, so this stays a test of the money
   * rather than of filling a form in, and the Fire Code fee is derived (10% of
   * the mayor's permit plus regulatory fees, RA 9514) rather than matched
   * against a business category, so it lands on any filing this happens to
   * build.
   */
  await onDashboard(page)
  const appId = await makeCompleteDraft(page)
  // The wizard's section map will not jump forward over an unfinished section,
  // and Documentary Requirements is one. This is the only test that walks the
  // wizard; see the helper for why the rest do without.
  await uploadRequiredDocuments(page, appId)

  /* ── 1. The wizard, with no clearance step ─────────────────────────────── */

  await page.goto(`/apply?draft=${appId}`)
  const map = page.locator('ol[aria-label="Application sections"]')
  await expect(map).toBeVisible({ timeout: 30_000 })
  await expect(
    map.getByRole('button', { name: /clearance/i }),
    'the wizard grew a clearance step back',
  ).toHaveCount(0)

  /*
   * Back to part 1 for the consent tick, which is not where a reopened draft
   * lands. This read `getByRole('checkbox').first().check()` straight after the
   * navigation, on the assumption that the wizard opens on Data Privacy
   * Consent; it opens on the first UNFINISHED section, and this fixture has
   * none — it reopens on Review & Submit, where there is no checkbox at all.
   *
   * The tick is still needed even though the draft carries
   * `data_privacy_consent: true`: the wizard deliberately never restores it on
   * a reopen, because under RA 10173 consent is given rather than remembered.
   * Submit stays shut until it is given again, and says so.
   */
  await map.getByRole('button', { name: /data privacy consent/i }).click()
  await page.getByRole('checkbox').first().check()

  await map.getByRole('button', { name: /review & submit/i }).click()
  /*
   * The last screen before submission says what is about to happen, and every
   * version of that sentence so far has been retired by the next reordering.
   * Two dead ones are asserted absent because both would mislead now:
   *
   *   "one Tax Order of Payment … nothing else is charged afterwards" — true
   *   again in substance, but it was said about a press that also PAID.
   *   "opens the six LGU clearances" / "balance reaches zero" — six became
   *   five, and release is five approvals rather than a settled balance.
   */
  await expect(page.getByText(/nothing else is charged/i)).toHaveCount(0)
  await expect(page.getByText(/opens the six LGU clearances/i)).toHaveCount(0)
  await expect(page.getByText(/balance reaches zero/i)).toHaveCount(0)
  /*
   * What it has to say instead: three waits, in the order they happen, because
   * each is a delay the applicant would otherwise experience as nothing
   * happening — and the last clause is the one that must survive a trim. An
   * applicant who thinks approval is the end, or that payment is the end, is
   * the surprise this paragraph exists to prevent.
   */
  await expect(page.getByText(/bplo reviews this form first/i)).toBeVisible()
  await expect(page.getByText(/five lgu clearances open/i)).toBeVisible()
  await expect(page.getByText(/released after all of them are approved/i)).toBeVisible()

  /*
   * "Submit", not "Submit & Pay". The press no longer takes money: it landed at
   * For Approval, where PaymentController had no refusal for an unbilled filing,
   * so the charge went through and `onPaymentCompleted` then ignored it — money
   * taken, filing unmoved, and BPLO's approval asking for it again.
   */
  await page.getByRole('button', { name: /^submit$/i }).click()
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

  /* ── 2. One bill, over every permit, and nothing due yet ───────────────── */

  const atSubmit = await read()
  expect(atSubmit.status, 'submitting should hand the form to BPLO, unpaid').toBe('for_approval')
  /*
   * All six permit types on the filing at submission — the business permit and
   * the five clearances — because the one Tax Order of Payment could not price
   * them otherwise. This is the assertion the previous version made the exact
   * opposite of (`toEqual(['BUSINESS'])`, 'a clearance reached the filing
   * before payment'), and the inversion is `attachRequiredPermitTypes` running
   * inside `submit()`.
   */
  expect(atSubmit.permitCodes, 'the required clearances were not attached at submission').toEqual(
    expect.arrayContaining(['BUSINESS', 'ZONING', 'SANITARY', 'FSIC', 'CEC', 'OCCUPANCY']),
  )
  expect(atSubmit.labels, 'the business permit is not on the Tax Order of Payment').toContain(
    'business permit',
  )
  expect(
    atSubmit.labels,
    'the fire clearance was left off the one bill that is supposed to cover everything',
  ).toContain('fire safety inspection certificate fee')
  expect(atSubmit.total).toBeGreaterThan(0)

  /* ── 3. Shut through both waits, and each wait says which one it is ─────── */

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })
  const cards = clearanceCards(page)
  await expect(cards).toHaveCount(5, { timeout: 30_000 })
  /*
   * For Approval and Pending Payment are the two statuses an applicant actually
   * waits in, and both used to fall through `lockedReason`'s match to a default
   * commented "Unreachable" — so most waiting applicants were told only that the
   * clearances were "not open on this application yet", with nothing to do about
   * it. Each arm now names the step that opens the stage, which is what a locked
   * reason is FOR.
   */
  await expect(page.locator('#clearances-locked')).toContainText(/bplo is reviewing/i)

  await approveAndPay(page, appId)

  /* ── 4. Paying opens it ────────────────────────────────────────────────── */

  await page.reload()
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({
    timeout: 30_000,
  })
  // The cards first — the heading is the layout's and is on screen while this
  // page is still a skeleton, so "no locked reason" would otherwise pass on a
  // page that has drawn nothing.
  await expect(cards).toHaveCount(5, { timeout: 30_000 })
  await expect(
    page.locator('#clearances-locked'),
    'the stage stayed shut on a paid filing',
  ).toHaveCount(0)

  const atPaid = await read()
  expect(atPaid.status).toBe('awaiting_other_permits')
  expect(atPaid.total, 'BPLO or the payment moved the amount that was agreed to').toBe(
    atSubmit.total,
  )

  /* ── 5. Applying adds nothing ──────────────────────────────────────────── */

  /*
   * The fire card is on the grid and quotes no price, which is the money rule
   * from the applicant's side. The per-card amount came off at the client's
   * instruction — *"Would displaying the fee still matter because we have
   * already paid it for that beforehand, right? If not, kindly remove it."* —
   * and over an Apply button it read as a threat that the press charges again.
   * The figure itself is not lost: it is on the Tax Order of Payment, which is
   * what `atSubmit.labels` above reads it from.
   */
  const fire = cards.filter({ hasText: /fire/i })
  await expect(fire).toHaveCount(1)
  await expect(fire).not.toContainText(/fee ₱|no fee assessed|fee set by this office/i)

  // Through the API rather than the card, because what is under test here is the
  // money, and the money follows the write rather than the click.
  await applyFor(page, appId, 'FSIC')

  const afterApply = await read()
  /*
   * Applied, not submitted. This asserted `for_approval`, which `startClearance`
   * stopped producing for a form-bearing permit when apply became two acts — and
   * all five carry a form. The money question is unaffected either way, which is
   * the point: the bill does not move on EITHER act.
   */
  expect(
    await readClearance(page, appId, 'FSIC'),
    'applying did not record the applicant’s choice',
  ).toEqual({ state: 'not_started', mode: 'apply' })
  /*
   * The whole of the "no accrual" rule, in one comparison.
   *
   * This asserted `toBeGreaterThan(atSubmit.total)` — the accrual — and the
   * reversal is not a loosening: `ClearanceService::reassess()` was deleted, the
   * client confirmed the intent ("the bill will charge all regardless if the
   * applicant selects upload or apply"), and a bill that grew after it was paid
   * would leave the applicant owing money on a filing they had settled, with a
   * release gate that no longer even reads the balance.
   */
  expect(
    afterApply.total,
    'applying for a clearance charged again for what the Tax Order of Payment covered',
  ).toBe(atSubmit.total)
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
  await onDashboard(page)
  const appId = await makePaidApplication(page)
  /*
   * Started first, and that is the point rather than setup noise.
   * `ClearanceController::unapply` refuses an unstarted permit with "You have
   * not applied for the …", which is a different rule and would let this test
   * pass without ever reaching the one it is about. The refusal under test is
   * `ClearanceService::unapply`'s, and it only fires on a permit there would
   * otherwise be something to take back.
   */
  await applyFor(page, appId, 'SANITARY')

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

  /*
   * And the card still stands, with no control offering the withdrawal.
   *
   * ── This was LEFT RED, and the control has since been taken off the card ──
   *
   * The Withdraw link used to render on any started clearance
   * (`hasApplied(row.state) && unlocked`), and all five are required, so every
   * one of them showed a control whose only possible outcome was the 422
   * asserted above. That is CLR-4 — offering a control the server will refuse —
   * which the card's own comment beside that link forbade in as many words. It
   * is no longer drawn.
   *
   * The rule is the pair, which is why they are asserted together: the server
   * refuses, AND the screen does not invite the refusal.
   */
  await page.goto(`/applications/${appId}/clearances`)
  const cards = clearanceCards(page)
  await expect(cards).toHaveCount(5, { timeout: 30_000 })
  await expect(
    page.getByRole('button', { name: /^withdraw your application for the/i }),
    'the card offers a Withdraw that the API refuses on every one of the five',
  ).toHaveCount(0)
})
