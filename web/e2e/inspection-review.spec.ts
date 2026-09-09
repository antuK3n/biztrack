import { expect, test } from '@playwright/test'
import type { Browser, Page } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * Runs as a clearance office, not as the super admin.
 *
 * Recording a visit needs BOTH `application.review`, to open the filing from
 * Application Verification, and `inspection.manage`, to set the result. The
 * super admin holds neither any more — the client's ruling was that Messages,
 * Track, Inspections and Other Requirements are "not his role to do those
 * things" — and the default chromium project hands every spec the admin
 * session, so this suite began 403ing the moment that landed.
 *
 * BPLO would not serve either: it coordinates the clearances and holds
 * `application.review`, but it does not inspect, so it has no
 * `inspection.manage`. It has to be one of the five offices that issues a
 * clearance. ApplicationVisibility scopes this session to filings routed to
 * CPDO, which is why the helpers below find their filing rather than hard-code
 * one.
 *
 * ── "For Inspection" is a PERMIT's state now, not a filing's ────────────────
 *
 * Every fixture query in this file used to ask for
 * `?application_status=for_inspection`. That status was retired on 6 September
 * 2026 (`ApplicationStatus`, and the note above its cases): inspection moved
 * onto each permit's own `ClearanceStatus`, because a filing where CHO is
 * inspecting while BFP is still reading and CPDO has already issued cannot be
 * summarised by one column. The stage an officer is standing in while visits
 * happen is `awaiting_other_permits` — every office working its own clearance —
 * and the tab that holds the row is keyed on `clearance_status=for_inspection`,
 * this office's own permit.
 *
 * The retired value did not make these queries fail loudly. `AssignmentController`
 * filters the parameter down to valid enum values, so an unrecognised one is
 * dropped and the query returns the office's whole queue unfiltered — the
 * helpers went on finding fixtures, the tests went on passing, and what they
 * were standing on was no longer what their names said. The queries below name
 * the states the product actually has.
 */
test.use({ storageState: sessionFor('zoning') })

/*
 * Opening a For Inspection filing from Application Verification.
 *
 * The regression this guards is the one the client reported in full:
 *
 *   "why cant i approve for inspection stuff, when i click an application that
 *    is for inspection. firstly, why is the entire application form showing it
 *    should just be like the other ones where its just a box (see others), plus
 *    there's no thing to approve something that's for inspection"
 *
 * and, on a first pass that merely folded the form behind a disclosure:
 *
 *   "In reviewing the inspections (admin side), I can still see the application
 *    details. Please remove this."
 *
 * All of it came from one fact that is easy to reintroduce. An office that has
 * approved its own paperwork has a `completed_at` on its assignment, so
 * ReviewPage's `decided` is true — which used to mean a static green
 * "Approved", no controls at all, and the whole 1,200-line BPLO sheet rendered
 * flat. Anyone reasoning about `decided` again without knowing about the
 * compact box will land back there, so these assertions are deliberately
 * literal about what must and must not be on the page.
 *
 * ── Read-only on purpose ────────────────────────────────────────────────────
 *
 * Approving here would consume the fixture: recording a passing visit grants
 * that permit and ISSUES it on the spot, and if it was the last one
 * outstanding the filing moves to `for_final_approval` — so the spec would
 * pass once and then find nothing to open. The button's WIRING is asserted —
 * it exists, it is reachable, it names its own inspection — and the effect of
 * pressing it is walked end to end in `flow-lifecycle.spec.ts`, on a filing
 * that spec creates itself. If this is ever made to press the button, give it
 * a filing of its own too.
 *
 * The same rule covers "Schedule re-inspection" and "Reschedule this
 * inspection", both of which write: their presence and their per-visit
 * accessible names are asserted, their effects are not.
 *
 * ── This file also covers the screen that was DELETED ───────────────────────
 *
 * /staff/inspections was a second, older screen doing this same job, and the
 * client had it removed: "The Track page -> For Inspection is redundant with
 * the Inspections page. Remove the Inspections page. All inspections will
 * happen in The Track page -> For Inspection". Nothing here tested that page
 * directly, so no test was deleted with it — but three things about its removal
 * are worth a test each, and they are at the bottom of this file: the rail
 * entry is gone, the old list address lands on Track, and an old DEEP link
 * lands on the filing it named rather than on a list or a 404.
 */

/**
 * The one heading that only ever appears on the full review sheet.
 *
 * It used to be the header eyebrow, "Business Permit & Licensing Office · Admin
 * Review". That string is gone: the sheet now names the office READING it
 * (SEP-4), so it is a different sentence per account and useless as a marker.
 * Section A's heading is the sheet's first lettered section, it is the same for
 * every reader, and it cannot appear on the compact inspection box.
 */
const ADMIN_REVIEW_SHEET = 'Business Information & Registration'

/**
 * The disclosure that sheet now lives behind, and the two states it has.
 *
 * Third position on this sheet: rendered flat, then deleted for the office
 * that had finished, now collapsed-by-default wherever it renders at all. The
 * long form of that history is in ReviewPage.tsx at `application-as-filed`.
 *
 * A prefix match, not the whole label — the accessible name also carries the
 * summary of what is inside ("business registration and address, ... 8 uploaded
 * requirements, ..."), which is built from the payload and therefore differs per
 * filing. The summary is asserted for its own sake in the collapse test below;
 * pinning it here would make every other test fixture-sensitive.
 */
const SHOW_SHEET = /^Show the application as filed/
const HIDE_SHEET = /^Hide the application as filed/

/**
 * Is the filed sheet ON THE PAGE, however it is folded?
 *
 * `includeHidden` is the load-bearing half. Playwright's role engine skips
 * anything hidden from the accessibility tree by default, so a plain
 * `getByRole` cannot tell "collapsed" from "deleted" — the two states this file
 * exists to keep apart, and the two the product has swung between twice. Every
 * assertion below about the sheet's PRESENCE goes through this; assertions
 * about whether it is on SCREEN use the ordinary visible query.
 */
function filedSheet(page: Page) {
  return page.getByRole('heading', { name: ADMIN_REVIEW_SHEET, includeHidden: true })
}

/**
 * This office's OWN queue rather than a hardcoded id, or the register.
 *
 * Two separate things make anything else wrong here, and both of them present
 * as a blank page rather than as an error, so they are worth naming.
 *
 * The first is churn. Which application is mid-inspection changes every time
 * anybody works the queue — the two this was written against were both approved
 * within the hour — and re-running the analytics history seeder renumbers rows
 * outright. An id written down here is stale by definition.
 *
 * The second is the office boundary, and it is what broke this suite. Picking
 * off `GET /applications?status=…` and following
 * `assignments[0]` looks safe because that list is already narrowed by
 * ApplicationVisibility — but the row it hands back first is BPLO's, since
 * BPLO is routed every filing it coordinates. `GET /assignments/{id}` is
 * narrowed a second time and much harder, by
 * AssignmentController::authorizeDepartment, which answers 403 for any
 * department but the reader's own and exempts exactly one role: `admin`. So
 * this worked for as long as every spec inherited the super admin session and
 * failed the moment that account stopped being able to review at all.
 *
 * `GET /assignments` is the fix rather than a filter on top of the old call:
 * it IS this office's queue, so every row in it is a row this session may
 * open. There is no id here to go stale and no boundary left to trip over.
 *
 * A filing carrying an OUTSTANDING visit for this office is preferred over one
 * that merely has visits. `canAct` in InspectionDecision draws the
 * Approve/Reject pair only for the inspecting department, so a filing whose
 * open visits all belong to Fire or Sanitary would silently skip the two tests
 * below that press those controls. Any filing with visits still satisfies the
 * first test, so that is the fallback rather than the target.
 *
 * Skips rather than fails when the register holds none: that is a fixture gap,
 * not a defect.
 */
async function openForInspectionFiling(page: Page): Promise<number | null> {
  /*
   * On-origin before touching localStorage. A fresh context starts on
   * about:blank, where reading it is a SecurityError — the saved storageState
   * is attached to the origin, not to the blank page.
   */
  await page.goto('/staff/queue')

  const assignmentId = await page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.staff')
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

    /*
     * `status=completed` is load-bearing, not tidiness.
     *
     * The filing's own status says nothing about whether THIS office has
     * finished: `awaiting_other_permits` is where every office sits while its
     * clearance is worked, and five of them can be at five different points at
     * once. The compact box is shown only to an office whose own review is
     * done, so without this filter the helper could hand these tests a filing
     * on which the reading office still owes a review — where the review sheet
     * is CORRECTLY on screen and every assertion below would be measuring the
     * wrong branch. See `openOwedReviewFiling` for the other half.
     *
     * It is also the only state that can carry a visit at all: a visit is
     * booked against a permit whose paperwork the office has accepted, and
     * accepting the paperwork is what closes the assignment.
     */
    const list = await fetch(
      '/api/v1/assignments?application_status=awaiting_other_permits&status=completed&per_page=20',
      { headers },
    )
    const rows = (await list.json()).data as {
      id: number
      department: { code: string } | null
      application: { id: number }
    }[]

    let anyWithVisits: number | null = null

    for (const row of rows) {
      const detail = await fetch(`/api/v1/applications/${row.application.id}`, { headers })
      /*
       * A row this session cannot read in full is skipped, not fatal.
       *
       * `GET /applications/{id}` is scoped by ApplicationVisibility and can
       * answer 403 or 404 for a filing whose assignment row is still in this
       * office's queue — a business removed from the register, a filing whose
       * routing changed between the two requests. The body then carries no
       * `data` key and reading `.inspections` off it threw, taking down a
       * FIXTURE SEARCH because one candidate was unreadable. The search should
       * move on to the next candidate; only an empty search is a skip.
       */
      if (!detail.ok) continue
      const app = ((await detail.json()) as { data?: {
        inspections: {
          status: string
          conducted_at: string | null
          department: { code: string } | null
        }[]
      } }).data
      // A filing with no visit scheduled renders the empty-state copy instead
      // of cards, which is a different branch than the one under test.
      if (!app || app.inspections.length === 0) continue

      // Mirrors `inspectionDone` in InspectionDecision.tsx: a visit is over
      // once it has been conducted, whatever the result was.
      const outstandingHere = app.inspections.some(
        (visit) =>
          // Both codes, never two absences: `undefined === undefined` would
          // call an unrouted visit ours and pick a filing with no buttons.
          Boolean(row.department) &&
          visit.department?.code === row.department?.code &&
          !visit.conducted_at &&
          !['completed', 'passed', 'failed'].includes(visit.status.toLowerCase()),
      )
      if (outstandingHere) return row.id
      anyWithVisits ??= row.id
    }

    return anyWithVisits
  })

  if (assignmentId === null) return null
  await page.goto(`/staff/queue/${assignmentId}`)
  await page.waitForLoadState('networkidle')
  return assignmentId
}

/**
 * A working filing on which this office's own review is still OPEN.
 *
 * The mirror of the helper above, and the state that had no test at all. It is
 * an ordinary state, not a corner: on BIZ-2026-00958 five of seven offices were
 * in it at once, and it is now the USUAL one — every clearance office arrives
 * at `awaiting_other_permits` owing a review, because the applicant routes each
 * office separately as they reach it.
 *
 * No inspection is required of the filing here — an office that has not
 * accepted the paperwork has no visit booked — so this looks only at the
 * assignment.
 */
async function openOwedReviewFiling(page: Page): Promise<number | null> {
  await page.goto('/staff/queue')

  const assignmentId = await page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.staff')
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

    const list = await fetch(
      '/api/v1/assignments?application_status=awaiting_other_permits&status=pending,in_progress,returned&per_page=20',
      { headers },
    )
    const rows = (await list.json()).data as { id: number }[]
    return rows[0]?.id ?? null
  })

  if (assignmentId === null) return null
  await page.goto(`/staff/queue/${assignmentId}`)
  await page.waitForLoadState('networkidle')
  return assignmentId
}

/*
 * ── The rule these two tests exist to pin ───────────────────────────────────
 *
 * The review form appears if and only if THIS OFFICE still owes a review on
 * this filing. Two tests because both halves are live at once on a single
 * filing, and a suite that asserted only one of them is exactly how the product
 * got into the state below.
 *
 * ── What the assertion here used to say, and why it was wrong ──────────────
 *
 * There was one test, and it asserted that the review form is absent on ANY
 * filing at the inspection stage, unconditionally — no regard for whether the
 * reading office's own assignment was still pending. Its helper preferred a
 * filing with an outstanding visit but fell back to `anyWithVisits`, so it
 * could and did land on an office that still owed a review and still demand the
 * form be gone.
 *
 * That assertion locked a DEADLOCK in. An office in that state had no Approve
 * and no Return control anywhere in the product; without an approval no visit
 * is ever booked, without every permit approved the filing never reaches For
 * Final Approval, and the Mayor's Permit can never be issued by any action the
 * product offers. `BIZ-2026-00958` sat there with five offices blocked. The API
 * had no such guard — the block was purely client-side, which is why all 649
 * backend tests passed over it — so the only thing standing between the bug and
 * a fix was this file.
 *
 * It has been rewritten to the real rule rather than relaxed to whatever passes.
 * If a future change makes the form disappear from an office that still owes a
 * review, the second test must go red — do not weaken it to make it green.
 */

test('an office that has FINISHED its review opens on the decision box, not the application form', async ({
  page,
}) => {
  const assignmentId = await openForInspectionFiling(page)
  test.skip(assignmentId === null, 'no filing on this office\'s queue carries a scheduled visit')

  const statusPanel = page.locator('section[aria-label="Application status"]')
  await expect(statusPanel).toBeVisible()

  // The box the client asked for (updated-gui/82.png).
  await expect(page.getByRole('heading', { name: 'Application Status' })).toBeVisible()
  await expect(statusPanel.locator('> ul > li')).not.toHaveCount(0)

  /*
   * The form is GONE, not collapsed. Both halves are asserted because the
   * first attempt at this satisfied "the form does not open" with a <details>
   * disclosure and the client rejected it by name — a hidden form is still a
   * form on the page.
   *
   * Still unconditional, and still correct: this office HAS finished. That is
   * what the helper's `status=completed` now guarantees and what the old
   * version of this test never checked.
   *
   * `filedSheet()` searches the hidden DOM too, which STRENGTHENS this rather
   * than relaxing it. The sheet is now collapsed-by-default everywhere it
   * renders, so a plain visible-only query would have been satisfied by the
   * whole sheet sitting here folded — exactly the outcome the client rejected
   * by name. Nothing short of real absence passes this line.
   *
   * And the disclosure itself must not be here either: a control offering to
   * unfold the application is the application details, one click away.
   */
  await expect(filedSheet(page)).toHaveCount(0)
  await expect(page.getByRole('button', { name: SHOW_SHEET })).toHaveCount(0)
  await expect(page.locator('details')).toHaveCount(0)

  // "but the progress thingy is cool, keep that".
  await expect(page.getByText('Application progress')).toBeVisible()
})

test('an office that still OWES a review reaches its decision while the other permits are worked', async ({
  page,
}) => {
  const assignmentId = await openOwedReviewFiling(page)
  test.skip(assignmentId === null, 'no working filing with an open review for this office')

  /*
   * The review sheet, not the compact box. The filing's status says
   * `awaiting_other_permits` — other offices are inspecting — and this office's
   * assignment does not, and the assignment is what this screen answers to.
   *
   * PRESENT, not visible — and that difference is the whole of the third
   * position on this sheet, so it is worth being exact about what is and is
   * not being conceded here.
   *
   * The deadlock this test was written for was never about the sheet being on
   * screen. It was about an office having no Approve and no Return anywhere in
   * the product, so `scheduleInspectionFor` never fired, `isFullyCleared` never
   * passed, and the permits on that filing could not be issued by any action
   * the product offered. Five offices sat there on BIZ-2026-00958. That is what
   * the block below asserts, and it is unchanged.
   *
   * What HAS changed is that the sheet arrives folded — "this form, when
   * approving something, is like something they can collapse. by default it
   * should be collapsed". Collapsed is not deleted: the officer who needs the
   * barangay before approving is one click away from it, which the deleted
   * version could not offer at any price. So this asserts it is on the page,
   * through the hidden-inclusive query, and the test below asserts the click
   * opens it. Do not relax this to "the disclosure button exists" — the button
   * could be wired to nothing.
   */
  await expect(filedSheet(page)).toHaveCount(1)
  await expect(page.locator('section[aria-label="Application status"]')).toHaveCount(0)

  /*
   * And the decision is REACHABLE, which is the whole point — the deadlock was
   * not a missing sheet, it was a missing button. Edit mode is what turns the
   * decision controls on (checklist item 54), so the test has to open it, the
   * same as an officer would.
   *
   * Presence only. Pressing Approve books this office's visit and writes to a
   * live filing; the header note on this file explains why nothing here writes.
   */
  await page.getByRole('button', { name: 'Edit', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Approve', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Return with remarks' })).toBeVisible()
})

test('the application as filed starts collapsed and opens on one click', async ({ page }) => {
  /*
   * The client's third and current instruction on this sheet, asserted as
   * three separate facts because each one has been got wrong on its own:
   *
   *   "this form, when approving something, is like something they can
   *    collapse. by default it should be collapsed, then with a click it can
   *    be expanded. they dont need this form exactly."
   *
   * Run from the seat the client was in — an office that still owes a review
   * on a For Inspection filing, which is CENRO on BIZ-2026-00958 in the
   * screenshot and CPDO here.
   */
  const assignmentId = await openOwedReviewFiling(page)
  test.skip(assignmentId === null, 'no working filing with an open review for this office')

  /* 1. By default it is collapsed. On the page, off the screen. */
  await expect(filedSheet(page)).toHaveCount(1)
  await expect(page.getByRole('heading', { name: ADMIN_REVIEW_SHEET })).toHaveCount(0)

  /*
   * 2. The control is a real disclosure, and it is never shut.
   *
   * `aria-expanded` on a <button>, not a <details> — <details> was the shape of
   * the rejected second pass and the test above still forbids one anywhere on
   * this screen. `disabled` is checked for on the attribute itself rather than
   * through toBeDisabled(), which treats `disabled` and `aria-disabled` as the
   * same thing and would pass either way: there is no state in which the only
   * route to the application should be dropped out of the tab order.
   */
  const toggle = page.getByRole('button', { name: SHOW_SHEET })
  await expect(toggle).toBeVisible()
  await expect(toggle).toHaveAttribute('aria-expanded', 'false')
  await expect(toggle).not.toHaveAttribute('disabled', /.*/)

  /*
   * 3. It says what is inside. A collapsed region whose label is "Show more"
   * is a mystery box, and an officer hunting for the barangay or the uploaded
   * requirements has no reason to think this is where they are. The summary is
   * built from the payload, so this checks the shape rather than a literal.
   */
  await expect(toggle).toContainText('Sections A–E')
  await expect(toggle).toContainText(/uploaded requirement/)

  /*
   * 4. One click opens it — the actual sheet, not just a state flip. The
   * hidden-inclusive query above would keep passing on a disclosure wired to
   * nothing, so the assertion here is the ordinary VISIBLE one.
   */
  await toggle.click()
  await expect(page.getByRole('heading', { name: ADMIN_REVIEW_SHEET })).toBeVisible()
  await expect(page.getByRole('button', { name: HIDE_SHEET })).toHaveAttribute(
    'aria-expanded',
    'true',
  )

  /*
   * `aria-controls` has to name the region that actually moved, or a screen
   * reader is told about a relationship the page does not have.
   */
  const controls = await page
    .getByRole('button', { name: HIDE_SHEET })
    .getAttribute('aria-controls')
  expect(controls, 'the disclosure names the region it opens').toBeTruthy()
  await expect(page.locator(`#${controls}`)).toBeVisible()

  // And it folds back up, so this is a disclosure rather than a one-way reveal.
  await page.getByRole('button', { name: HIDE_SHEET }).click()
  await expect(page.getByRole('heading', { name: ADMIN_REVIEW_SHEET })).toHaveCount(0)
})

test('collapsing the sheet does not fold away the work the officer came to do', async ({
  page,
}) => {
  /*
   * The counterweight to the test above, and the reason the collapse is drawn
   * where it is rather than around the whole white card.
   *
   * What collapses is the APPLICANT'S filed sheet — the part the client says
   * "they dont need this form exactly". What must not is anything the officer
   * has to type or press: the decision buttons, this office's own clearance
   * panel, and FOR OFFICE USE ONLY, which is where Evaluator Remarks and the
   * assessed fee are recorded. Folding any of those away would re-create the
   * deadlock this file was written for by a different route — the controls
   * would exist, and nobody would find them.
   *
   * Every assertion here is a VISIBLE query, taken with the sheet still shut.
   */
  const assignmentId = await openOwedReviewFiling(page)
  test.skip(assignmentId === null, 'no working filing with an open review for this office')

  await expect(page.getByRole('button', { name: SHOW_SHEET })).toHaveAttribute(
    'aria-expanded',
    'false',
  )

  await page.getByRole('button', { name: 'Edit', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Approve', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Return with remarks' })).toBeVisible()

  // The panel this office records into, reachable without expanding anything.
  await expect(page.locator('#for-office-use')).toBeVisible()
  /*
   * Regex, not the literal. The <label> wraps the input AND the sentence
   * explaining where the remark travels, so the computed accessible name is
   * the whole paragraph — an exact match would fail on correct markup.
   */
  await expect(page.getByLabel(/^Evaluator Remarks/)).toBeVisible()

  /*
   * And the office's own clearance, which is the sheet it is actually
   * deciding. Null when this filing carries no form for this office — BPLO's
   * BUSINESS permit type has none at all — which is a fixture gap, not a
   * defect, so it is checked only when one is there.
   */
  const ownForm = page.locator('section[aria-label^="Your office"]')
  if ((await ownForm.count()) > 0) await expect(ownForm.first()).toBeVisible()

  // Still shut. Nothing above quietly opened it.
  await expect(page.getByRole('heading', { name: ADMIN_REVIEW_SHEET })).toHaveCount(0)
})

/*
 * ── The RA 11032 processing category ────────────────────────────────────────
 *
 * The client's request, verbatim:
 *
 *   "In the average processing time, since it categorizes applications into
 *    simple, complex, and highly technical, allow all office admins to set the
 *    application category during their edit mode when trying to approve the
 *    application."
 *
 * Two halves, and only one of them is anyone's to change. The DEADLINES are
 * statute — three working days simple, seven complex, twenty highly technical
 * — and no LGU may grant itself a fourth tier or a longer count. WHICH TIER a
 * filing belongs to is the LGU's own classification, published in its Citizen's
 * Charter, and Malabon has not told us theirs (open question A10). Until now
 * every filing was tiered by a rule this project invented, and the dashboard's
 * compliance rate was measured against that guess.
 *
 * So these tests pin the control AND its limits: it appears in Edit mode for a
 * reviewing office, it offers exactly the three statutory tiers with their real
 * day counts and nothing else, and it says who set the current one.
 *
 * Read-only, like everything else in this file. Saving a category rewrites a
 * live filing's statutory deadline; the wiring is asserted up to the moment
 * before the press. The server-side behaviour — the recomputed deadline, the
 * audit row, the terminal-filing refusal, every office being able to do it — is
 * covered by api/tests/Feature/Ra11032ClassificationTest.php, which owns its
 * own data and can afford to write.
 */

/** The office's own category select, which only exists in Edit mode. */
function categorySelect(page: Page) {
  return page.getByLabel('Application category', { exact: true })
}

test('a reviewing office can set the RA 11032 category from Edit mode', async ({ page }) => {
  const assignmentId = await openOwedReviewFiling(page)
  test.skip(assignmentId === null, 'no working filing with an open review for this office')

  /*
   * View mode first, and the assertion is that there is nothing to TYPE INTO
   * rather than nothing to read. The category is a fact about the filing and
   * View mode shows facts; what it must not do is offer the control, which is
   * the same rule the assessed fee and the issuance dates follow.
   */
  await expect(categorySelect(page)).toHaveCount(0)
  await expect(page.getByText('RA 11032 · Processing Category')).toBeVisible()

  await page.getByRole('button', { name: 'Edit', exact: true }).click()

  /*
   * In For Office Use Only, not somewhere of its own. That panel is the
   * boundary between the applicant's sworn declaration (locked in both modes)
   * and what the office decides, and the tier is the office's.
   */
  const select = categorySelect(page)
  await expect(select).toBeVisible()
  await expect(page.locator('#for-office-use').getByLabel('Application category')).toHaveCount(1)

  /*
   * Exactly the statutory tiers, each captioned with the day count the law
   * actually gives it. A hard-coded list in the browser could drift into a
   * fourth tier or a wrong number, so this asserts the whole option set rather
   * than spot-checking one — the day counts are the compliance figure.
   *
   * An "Not yet categorised" placeholder is allowed and is not a tier: it is
   * offered only while the filing genuinely has none, and it carries an empty
   * value so it cannot be saved as one.
   */
  const options = await select.locator('option').evaluateAll((els) =>
    els.map((el) => ({ value: (el as HTMLOptionElement).value, text: (el.textContent ?? '').trim() })),
  )
  const tiers = options.filter((o) => o.value !== '')
  expect(
    tiers.map((o) => o.text),
    'the control offers the three RA 11032 tiers and their statutory day counts',
  ).toEqual([
    'Simple — 3 working days',
    'Complex — 7 working days',
    'Highly technical — 20 working days',
  ])
  expect(options.length - tiers.length, 'at most one non-tier placeholder').toBeLessThanOrEqual(1)

  /*
   * It says who decided the current one. An officer must be able to tell they
   * are overruling our automatic guess from being the first to look at it —
   * the whole reason this field was worth adding rather than leaving the rule
   * to decide silently.
   */
  await expect(page.locator('#ra11032-note')).toContainText(
    /Category set by|assigned automatically|has not been categorised/,
  )
  await expect(select).toHaveAttribute('aria-describedby', 'ra11032-note')
})

test('Save category is never `disabled` — it says what is missing instead', async ({ page }) => {
  /*
   * Same rule as the inspection decisions above and the renewal modal's
   * Confirm. `disabled` drops a control out of the tab order and takes the
   * sentence explaining why it is shut with it, so a keyboard user meets a
   * button that has simply stopped existing.
   *
   * Checked on the attribute rather than through toBeDisabled(), which treats
   * `disabled` and `aria-disabled` as the same thing and would pass either way.
   *
   * ── Why this walks two states instead of asserting one ────────────────────
   *
   * This used to open on "nothing has been changed yet, so there is nothing to
   * save", and that premise is no longer true. A filing arrives already
   * carrying a tier, because submit() seeds Ra11032::tierFor's GUESS; what the
   * approval gate waits for is a PERSON, which the payload reports as
   * `ra11032.source === 'officer'`. So on a filing nobody has claimed there is
   * something to save without changing anything — the officer's agreement — and
   * Save is open on arrival by design. Shutting it was the trap the product was
   * fixed to remove: an officer who read the filing and agreed with the guess
   * could only get out by picking a tier they believed was wrong, saving, and
   * picking the right one back. The provenance line says as much on screen
   * ("save the category below, whether or not you change it").
   *
   * The rule this test is NAMED for did not move with it, so rather than drop
   * it, it is asserted from both sides: the button never carries `disabled`,
   * its state is always announced through `aria-disabled`, and the shut state
   * never appears without the sentence saying what is missing. Which state the
   * fixture starts in is a property of the register — an uncategorised filing,
   * an unclaimed guess, or a tier somebody has already put their name to, and a
   * previous run of this test leaves the middle one looking like the last — so
   * it is read off the screen and the OTHER state is then driven from it. That
   * is what makes this repeatable rather than passing once.
   */
  const assignmentId = await openOwedReviewFiling(page)
  test.skip(assignmentId === null, 'no working filing with an open review for this office')

  await page.getByRole('button', { name: 'Edit', exact: true }).click()
  const select = categorySelect(page)
  await expect(select).toBeVisible()

  const save = page.getByRole('button', { name: 'Save category' })
  const why = page.locator('#ra11032-save-why')
  await expect(save).toBeVisible()

  /** The whole rule in one place, so neither state can be checked more weakly. */
  const expectSaveState = async (open: boolean) => {
    await expect(
      save,
      'Save category was closed with the native attribute, which drops it out of the tab order',
    ).not.toHaveAttribute('disabled', /.*/)
    await expect(save).toHaveAttribute('aria-disabled', open ? 'false' : 'true')
    if (open) await expect(why).toHaveCount(0)
    else await expect(why, 'Save category is shut and does not say why').toContainText(/Pick a/)
  }

  const current = await select.inputValue()
  const claimed = /^Category set by/.test((await page.locator('#ra11032-note').innerText()).trim())
  /*
   * Open exactly when there is something a press would record: a tier to save,
   * and nobody's name against it yet. Mirrors ReviewPage's `tierChanged` at the
   * moment of arrival, before the officer has touched anything.
   */
  const openOnArrival = current !== '' && !claimed
  await expectSaveState(openOnArrival)

  if (openOnArrival) {
    /*
     * The shut state, reached by SAVING the tier already on screen. That is the
     * one press here that cannot move a live filing's statutory clock: the tier
     * does not change, so the deadline recomputed from the filing date is the
     * one it already had, and the only thing written is who agreed to it. The
     * button then has nothing left to record — and says so.
     */
    const [saved] = await Promise.all([
      page.waitForResponse(
        (r) =>
          /\/assignments\/\d+\/classification$/.test(r.url()) && r.request().method() === 'POST',
        { timeout: 30_000 },
      ),
      save.click(),
    ])
    expect(saved.status(), `claiming the category was refused: ${await saved.text()}`).toBe(200)

    await expectSaveState(false)
    return
  }

  /*
   * The open state, reached by choosing a category the filing does not already
   * have. Stops there deliberately: pressing it WOULD re-count a live filing's
   * deadline from the date it was filed.
   */
  const other = (
    await select.locator('option').evaluateAll((els) =>
      els.map((el) => (el as HTMLOptionElement).value).filter((v) => v !== ''),
    )
  ).find((v) => v !== current)
  expect(other, 'there is always another tier to move to').toBeTruthy()

  await select.selectOption(other as string)
  await expectSaveState(true)
})

test('the Edit-mode banner names the category as one of the fields it turns on', async ({
  page,
}) => {
  /*
   * SEP-5's rule, applied to the new field: the banner is built from the same
   * gates the controls are drawn behind, so it cannot describe a screen that is
   * not there. An officer who never scrolls to For Office Use Only would
   * otherwise never learn they are allowed to change a statutory deadline.
   */
  const assignmentId = await openOwedReviewFiling(page)
  test.skip(assignmentId === null, 'no working filing with an open review for this office')

  await page.getByRole('button', { name: 'Edit', exact: true }).click()
  /*
   * `.first()` because the banner nests a span inside a <p> and both match the
   * text — strict mode would fail on the ambiguity rather than on the product.
   */
  await expect(
    page.getByText(/Edit mode\. On this filing your office fills in/).first(),
  ).toContainText('the RA 11032 category')
})

test('every outstanding visit carries its own named Approve and Reject', async ({ page }) => {
  const assignmentId = await openForInspectionFiling(page)
  test.skip(assignmentId === null, 'no filing on this office\'s queue carries a scheduled visit')

  const approve = page.getByRole('button', { name: /^Approve the .+ inspection$/ })
  const reject = page.getByRole('button', { name: /^Reject the .+ inspection with remarks$/ })

  const approveCount = await approve.count()
  test.skip(approveCount === 0, 'every visit on this filing has already been conducted')

  // Same number of each: a visit that can be approved can be rejected.
  expect(await reject.count()).toBe(approveCount)

  /*
   * A filing carries a visit per inspecting office — up to five, one for each
   * required clearance, since BPLO is the only office that never books one — so
   * the CARDS repeat, and a column of buttons all called "Approve" is a list a
   * screen-reader user cannot navigate. Each name has to say which office's
   * visit it decides.
   *
   * Read honestly, the uniqueness check alone no longer proves much from this
   * seat: `canAct` draws the pair only for the reader's own department, so a
   * single office session usually sees one. That is the product working — no
   * account can decide another office's visit — but it means the naming rule
   * has to be asserted directly as well, on the office actually appearing in
   * the label, or a control that fell back to the generic "Inspecting office"
   * heading would sail through.
   */
  const names = await approve.evaluateAll((els) => els.map((e) => e.getAttribute('aria-label')))
  expect(new Set(names).size).toBe(names.length)

  const office = await page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.staff')
    const res = await fetch('/api/v1/auth/me', {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
    return ((await res.json()).data as { department: { name: string } | null }).department?.name
  })
  expect(office, 'an office reviewer always belongs to a department').toBeTruthy()
  for (const name of names) {
    expect(name, 'the control names the office whose visit it decides').toBe(
      `Approve the ${office} inspection`,
    )
  }

  /*
   * Shut controls use `aria-disabled`, never the native attribute: `disabled`
   * drops a control out of the tab order, so a screen-reader user never reaches
   * the one thing that would explain the state. Asserted on the attribute
   * itself rather than through toBeDisabled(), which treats the two as the
   * same and would pass either way.
   */
  const nativelyDisabled = await page.evaluate(
    () =>
      [...document.querySelectorAll('button[aria-label*="inspection"]')].filter(
        (b) => (b as HTMLButtonElement).disabled,
      ).length,
  )
  expect(nativelyDisabled, 'decision buttons are shut with aria-disabled, not `disabled`').toBe(0)
})

test('rejecting a visit asks for remarks and will not proceed without them', async ({ page }) => {
  const assignmentId = await openForInspectionFiling(page)
  test.skip(assignmentId === null, 'no filing on this office\'s queue carries a scheduled visit')

  const reject = page.getByRole('button', { name: /^Reject the .+ inspection with remarks$/ })
  test.skip((await reject.count()) === 0, 'every visit on this filing has already been conducted')

  await reject.first().click()
  await expect(page.getByText('REMARKS FOR REJECTION')).toBeVisible()

  /*
   * A rejection with no finding leaves the owner a failed visit and no
   * statement of what to put right. Proceed therefore stays REACHABLE and
   * points at the sentence saying why it will do nothing — the alternative,
   * `disabled`, hides that sentence from the keyboard entirely.
   */
  const proceed = page.getByRole('button', { name: 'Proceed' })
  await expect(proceed).not.toHaveAttribute('disabled', /.*/)
  await expect(proceed).toHaveAttribute('aria-describedby', /.+/)

  await proceed.click()
  await expect(
    page.getByText('REMARKS FOR REJECTION'),
    'an empty rejection is refused rather than sent',
  ).toBeVisible()

  // And it is dismissable without touching the record.
  await page.getByRole('button', { name: 'Cancel' }).click()
  await expect(page.getByText('REMARKS FOR REJECTION')).toHaveCount(0)
})

test('an outstanding visit can still be moved to another date', async ({ page }) => {
  /*
   * "Reschedule this inspection" is the one control that came off the deleted
   * /staff/inspections/{id} with nowhere else to go. Losing it would leave an
   * office able to approve or reject a visit but not to move the appointment,
   * which is the ordinary case — the inspector is ill, the owner is away.
   *
   * Named per visit for the same reason Approve is: up to five of these can be
   * on one filing, one per required clearance.
   */
  const assignmentId = await openForInspectionFiling(page)
  test.skip(assignmentId === null, 'no filing on this office\'s queue carries a scheduled visit')

  const approve = page.getByRole('button', { name: /^Approve the .+ inspection$/ })
  const approveCount = await approve.count()
  test.skip(approveCount === 0, 'every visit on this filing has already been conducted')

  const reschedule = page.getByRole('button', { name: /^Reschedule the .+ inspection$/ })
  expect(
    await reschedule.count(),
    'a visit this office can decide is a visit it can move',
  ).toBe(approveCount)

  /*
   * Opening it reveals the date field and the save, both named after the visit.
   *
   * `getByLabel` rather than a role query: `input[type=datetime-local]` has no
   * mapped ARIA role, so getByRole('textbox') finds nothing however well the
   * control is labelled. The label IS the thing under test here.
   */
  await reschedule.first().click()
  await expect(page.getByLabel(/^New date and time for the .+ inspection$/)).toBeVisible()
  await expect(page.getByRole('button', { name: /^Save the new .+ inspection date$/ })).toBeVisible()

  // And it closes without writing anything.
  await page.getByRole('button', { name: /^Leave the .+ inspection where it is$/ }).click()
  await expect(page.getByRole('button', { name: /^Save the new .+ inspection date$/ })).toHaveCount(0)
})

/* ── The screen that was removed ──────────────────────────────────────────── */

test('the rail no longer offers an Inspections screen', async ({ page }) => {
  await page.goto('/staff/queue')
  /*
   * Scoped to the <aside> for the reason analytics.spec.ts gives: the mobile tab
   * bar carries the same labels and would trip strict mode.
   *
   * Track must still be there. Asserting only the absence would pass just as
   * happily on a rail that failed to render at all.
   */
  await expect(page.locator('aside').getByRole('link', { name: 'Inspections' })).toHaveCount(0)
  await expect(page.locator('aside').getByRole('link', { name: 'Track', exact: true })).toBeVisible()
})

test('the old Inspections list address lands on Track', async ({ page }) => {
  await page.goto('/staff/inspections')
  await expect(page).toHaveURL(/\/staff\/queue$/)
  // Track itself, not a redirect loop or the login door.
  await expect(page.getByRole('heading', { name: 'Application Verification' })).toBeVisible()
})

test('an old inspection deep link opens the filing it named', async ({ page }) => {
  /*
   * The regression this exists for is a redirect that DROPS what it was given.
   * /analytics/* did exactly that once and answered every deep link with the
   * Overview — plausible, silent, wrong. An inspection link names one visit on
   * one filing, so it has to arrive at THAT filing.
   *
   * The assignment id is checked exactly, not just "some queue page", because
   * the tempting shortcut here — reuse the inspection id as the assignment id —
   * would land on an unrelated business's filing whenever the two numbers
   * happen to collide, and a loose assertion would not notice.
   */
  await page.goto('/staff/queue')

  const target = await page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.staff')
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

    /*
     * The same two filters `openForInspectionFiling` uses, and for the same
     * reason: the assertion at the foot of this test is that the shim lands on
     * the DECISION BOX, and the box is drawn only for an office whose own
     * review is `completed` on a filing that is `awaiting_other_permits`. This
     * asked for the retired `for_inspection` status, which the controller drops
     * as unrecognised — so the query returned the whole queue, the search
     * happily found a decided filing with an old visit on it, and the shim
     * correctly delivered a full review sheet to a test demanding a box.
     */
    const list = await fetch(
      '/api/v1/assignments?application_status=awaiting_other_permits&status=completed&per_page=20',
      { headers },
    )
    const rows = (await list.json()).data as {
      id: number
      department: { code: string } | null
      application: { id: number }
    }[]

    for (const row of rows) {
      if (!row.department) continue
      const detail = await fetch(`/api/v1/applications/${row.application.id}`, { headers })
      // Skip an unreadable candidate rather than dying on it — same reason as
      // `openForInspectionFiling` above: this is a fixture search.
      if (!detail.ok) continue
      const app = ((await detail.json()) as {
        data?: { inspections: { id: number; department: { code: string } | null }[] }
      }).data
      if (!app) continue
      // This office's OWN visit: GET /inspections/{id} answers 403 for anybody
      // else's (InspectionController::authorizeDepartment), which is a
      // different branch of the shim than the one under test.
      const mine = app.inspections.find((v) => v.department?.code === row.department?.code)
      if (mine) return { assignmentId: row.id, inspectionId: mine.id }
    }
    return null
  })

  test.skip(target === null, 'no inspection routed to this office on the current register')

  await page.goto(`/staff/inspections/${target!.inspectionId}`)
  // Longer than the default: the shim resolves over two API calls, and the
  // second reads a hundred assignments.
  await expect(page).toHaveURL(new RegExp(`/staff/queue/${target!.assignmentId}$`), {
    timeout: 20_000,
  })
  // And it is the decision box that greets them, not an empty shell.
  await expect(page.locator('section[aria-label="Application status"]')).toBeVisible()
})

test('the sheet leads with the office reading it, and offers no other office’s dates', async ({
  page,
}) => {
  /*
   * The other half of the change: the compact box must not swallow a screen
   * that needs the form. If this fails, an officer who still owes a review has
   * lost every field they are meant to read — a far worse regression than the
   * one being fixed.
   *
   * ── Why this no longer asks for a different STATUS ────────────────────────
   *
   * It used to open an `under_review` filing, on the reasoning that
   * `for_inspection` got the box and every other status got the sheet. Both
   * statuses are retired. The predicate is `app.status ===
   * 'awaiting_other_permits' && !owesReview`, and for a clearance office the
   * only status it can hold an OPEN review on is `awaiting_other_permits` —
   * `startClearance` routes it there and nothing routes it anywhere else. So
   * the half of the predicate that separates the two screens is `owesReview`,
   * and the fixture is an open review rather than a different status.
   *
   * The stale query did not fail loudly, which is the point worth recording:
   * `AssignmentController` drops an unrecognised `application_status`, so the
   * request came back as this office's whole queue and the test went on passing
   * against whatever led it.
   */
  // Open review only: a completed assignment renders the sheet as a closed
  // record with no Mode pills, and the Edit-mode assertions below need them.
  const assignmentId = await openOwedReviewFiling(page)
  test.skip(assignmentId === null, 'no working filing with an open review for this office')

  /*
   * Present, and collapsed — the same shape as every other status that renders
   * the sheet.
   *
   * The collapse is deliberately NOT gated on whether this office is deciding.
   * The client's objection is to the sheet being the thing on screen, and it is
   * the thing on screen in every status that draws it; a closed record reads no
   * differently from an open review at arm's length. Gating it would also mean
   * the page changed shape underneath an officer at the moment they approved,
   * which is the worst possible moment for it to move. So this asserts the
   * collapsed state here too rather than making an exception of the status —
   * if a future change makes the collapse conditional, this goes red on purpose.
   */
  await expect(filedSheet(page)).toHaveCount(1)
  await expect(page.getByRole('button', { name: SHOW_SHEET })).toHaveAttribute(
    'aria-expanded',
    'false',
  )
  await expect(page.locator('section[aria-label="Application status"]')).toHaveCount(0)

  /*
   * The sheet leads with the office READING it (SEP-4).
   *
   * It announced itself as "Business Permit & Licensing Office · Admin Review"
   * to every office on the filing, lettered A–E after BPLO's paper form, with
   * the reader's own four-question clearance buried in Section D. That is most of
   * why the client believed there was a leak on parts of this page where there
   * is none — "I should only see the SANITARY PERMIT".
   *
   * Asserted against the account's real department rather than a literal, so it
   * cannot pass by accident on a hardcoded office name.
   */
  const office = await page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.staff')
    const res = await fetch('/api/v1/auth/me', {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
    return ((await res.json()).data as { department: { name: string } | null }).department?.name
  })
  expect(office, 'an office reviewer always belongs to a department').toBeTruthy()
  await expect(page.getByText(`${office} · Application Review`)).toBeVisible()

  /*
   * And the reader's own clearance is ABOVE section A, not buried in D.
   *
   * A sanitary officer used to read roughly 1,200 lines of BPLO registration
   * data, documents and another office's sheets before reaching the four
   * answers that are their actual clearance. Asserted as document ORDER rather
   * than mere presence, because "it is on the page somewhere" was already true
   * before the fix and is the thing the client complained about.
   *
   * Null when this filing carries no sheet for this office — BPLO's own
   * BUSINESS permit type has no office form at all, and a filing need not
   * include this office's clearance — which is a fixture gap, not a defect.
   */
  const leadsWithOwnOffice = await page.evaluate(() => {
    const lead = document.querySelector('section[aria-label^="Your office"]')
    if (!lead) return null
    const sectionA = [...document.querySelectorAll('h2')].find(
      (h) => h.textContent?.trim() === 'Business Information & Registration',
    )
    if (!sectionA) return false
    return Boolean(lead.compareDocumentPosition(sectionA) & Node.DOCUMENT_POSITION_FOLLOWING)
  })
  if (leadsWithOwnOffice !== null) {
    expect(leadsWithOwnOffice, 'the office’s own form leads the sheet').toBe(true)
  }

  /*
   * And it does not offer another office's issuance dates (SEP-3).
   *
   * The panel used to be keyed off the FILING's permit types, so any office
   * opening a filing that carried an occupancy permit got OBO's date inputs and
   * a live Save dates button that the API answered 403 to. It is keyed off the
   * office-form sheets the payload actually carries now, which is the same rule
   * the server gates the write on — so the control exists exactly where the
   * save would be accepted.
   *
   * Scoped to a clearance office (this suite runs as CPDO): BPLO and admin hold
   * `application.view_any_office`, may write every sheet, and legitimately keep
   * the panel.
   */
  await page.getByRole('button', { name: 'Edit', exact: true }).click()
  await expect(page.getByRole('button', { name: /^Save the .+ issuance dates$/ })).toHaveCount(0)
})

/* ── Booking the FIRST visit ───────────────────────────────────────────────
 *
 * The step that had no screen at all, and the deadlock it caused.
 *
 * An office approves its clearance's paperwork → that permit moves to
 * `ClearanceStatus::for_inspection` and NOTHING is booked. The automatic
 * two-working-days scheduler was deleted on purpose: the client's verified
 * procedure is "Select Inspection Date and Approve Inspection", so the office
 * says when. `InspectionController::schedule` was written for exactly that —
 * `POST /applications/{id}/permits/{code}/inspection`, addressed by permit code
 * because no inspection exists yet — and until now nothing in the browser called
 * it. `resources.ts` offered `conduct`, `reschedule` and `reinspect`, all three
 * keyed on an inspection id, so all three needed the row this endpoint creates.
 *
 * The permit therefore sat at `for_inspection` for good: no visit, so nothing to
 * pass, so the filing never reached For Final Approval and BPLO could never
 * issue the Mayor's Permit. `flow-lifecycle.spec.ts` states the same gap in its
 * own narrative; these two tests pin the fix and its boundary.
 *
 * ── Why the fixture is BUILT rather than found ─────────────────────────────
 *
 * No filing in the copied register is in this state. Every pivot row on it reads
 * `approved` or `not_started` — the register predates the per-permit state
 * machine — so a helper that merely searched would skip on every run and prove
 * nothing. The two steps below are the product's own, taken through the same
 * endpoints the screens press: the applicant opens the clearance, the office
 * accepts its paperwork. Both write, and both write only to the throwaway copy
 * the stack serves.
 */

/** The clearance this suite's session issues. CPDO's, and it is inspected. */
const OWN_PERMIT = { code: 'ZONING', office: 'CPDO' } as const

/** Act as another account in its own context, closing it even on a failure. */
async function asAccount<T>(
  browser: Browser,
  account: 'owner' | 'bplo' | 'sanitary',
  landing: string,
  body: (page: Page) => Promise<T>,
): Promise<T> {
  const context = await browser.newContext({ storageState: sessionFor(account) })
  const page = await context.newPage()
  try {
    await page.goto(landing)
    return await body(page)
  } finally {
    await context.close()
  }
}

type Booking = { assignmentId: number; appId: number; permitName: string }

/**
 * A filing whose ZONING clearance this office has accepted and not yet booked.
 *
 * Walks the product forward rather than reaching into the database:
 *
 *  1. the applicant opens the clearance (`POST /clearances/ZONING/apply`),
 *     which routes CPDO and moves the permit to `for_approval`;
 *  2. CPDO accepts the paperwork (`POST /assignments/{id}/approve`), which is
 *     what moves it to `for_inspection` — and, deliberately, books nothing.
 *
 * A candidate that already has a CPDO visit is passed over: this is about the
 * FIRST one, and the way on from a booked or failed visit is Reschedule or
 * Schedule re-inspection, which the card above already draws.
 *
 * Null when the register offers no filing to walk — a fixture gap, not a defect.
 */
async function bookableClearance(page: Page, browser: Browser): Promise<Booking | null> {
  const appId = await asAccount(browser, 'owner', '/dashboard', async (owner) =>
    owner.evaluate(async (permitCode) => {
      const token = localStorage.getItem('biztrack.token.public')
      const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

      const list = await fetch(
        '/api/v1/applications?status=awaiting_other_permits&per_page=50',
        { headers },
      )
      const rows = (await list.json()).data as {
        id: number
        permit_types: { code: string; status: string | null }[]
      }[]

      for (const row of rows) {
        const state = row.permit_types.find((pt) => pt.code === permitCode)?.status
        /*
         * `for_inspection` is accepted as well as the two states that need
         * walking, and that is what makes this callable twice in one run. The
         * boundary test below leaves the fixture exactly as it found it, so the
         * booking test can pick the same filing up — while a helper that only
         * ever accepted `not_started` would find its own work already done and
         * report a fixture gap that is really a second call.
         */
        if (state !== 'not_started' && state !== 'for_approval' && state !== 'for_inspection') {
          continue
        }

        if (state === 'not_started') {
          const applied = await fetch(
            `/api/v1/applications/${row.id}/clearances/${permitCode}/apply`,
            { method: 'POST', headers },
          )
          // A candidate the workflow refuses is skipped, not fatal: this is a
          // fixture search, and the next filing may well take it.
          if (!applied.ok) continue
        }
        return row.id
      }
      return null
    }, OWN_PERMIT.code),
  )

  if (appId === null) return null

  await page.goto('/staff/queue')
  return page.evaluate(
    async ([id, permitCode, officeCode]) => {
      const token = localStorage.getItem('biztrack.token.staff')
      const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

      const read = async () => {
        const res = await fetch(`/api/v1/applications/${id}`, { headers })
        if (!res.ok) return null
        return (await res.json()).data as {
          permit_types: { code: string; name: string; status: string | null }[]
          assignments: { id: number; department: { code: string } | null }[]
          inspections: { department: { code: string } | null }[]
        }
      }

      let app = await read()
      if (!app) return null

      // Already visited by this office: a different branch of the card.
      if (app.inspections.some((v) => v.department?.code === officeCode)) return null

      const assignment = app.assignments.find((a) => a.department?.code === officeCode)
      if (!assignment) return null

      if (app.permit_types.find((pt) => pt.code === permitCode)?.status === 'for_approval') {
        const approved = await fetch(`/api/v1/assignments/${assignment.id}/approve`, {
          method: 'POST',
          headers,
        })
        if (!approved.ok) return null
        app = await read()
        if (!app) return null
      }

      const permit = app.permit_types.find((pt) => pt.code === permitCode)
      if (permit?.status !== 'for_inspection') return null

      return { assignmentId: assignment.id, appId: id, permitName: permit.name }
    },
    [appId, OWN_PERMIT.code, OWN_PERMIT.office] as const,
  )
}

/*
 * The boundary test is declared BEFORE the booking one, and the order is
 * load-bearing rather than editorial. `fullyParallel` is false, so tests in a
 * file run top to bottom; this one leaves the fixture exactly as it found it,
 * while the one below books the visit and consumes it. Reversed, the second
 * test would find its own clearance already booked and skip itself as a
 * fixture gap — a green run that asserted nothing about the boundary.
 */
test('an office that does not issue the permit is offered no way to book its visit', async ({
  page,
  browser,
}) => {
  test.setTimeout(120_000)

  /*
   * The boundary, from both ends.
   *
   * `InspectionController::schedule` refuses anyone whose department is not the
   * permit's `issuing_department_id`, and the screen must not draw a control the
   * API will answer 403 to. The rule mirrored in the browser is the assignment
   * payload's `clearance` — `AssignmentResource::clearanceRow()`, matched on that
   * same column — so an office holding no clearance on the filing, or holding a
   * different one, gets no card.
   */
  const fixture = await bookableClearance(page, browser)
  test.skip(
    fixture === null,
    'no filing could be walked to a clearance awaiting its first visit — the booking '
      + 'test below consumes the one it finds, so a second run against the same copy of '
      + 'the register skips. Restart e2e-stack.sh, which re-copies it.',
  )
  const { assignmentId, appId, permitName } = fixture as Booking

  // The control exists for the office that DOES issue it, or the rest of this
  // test proves only that the page is empty.
  await page.goto(`/staff/queue/${assignmentId}`)
  await expect(page.getByRole('button', { name: `Book the ${permitName} visit` })).toBeVisible({
    timeout: 30_000,
  })

  /*
   * BPLO on the screen. It is on every filing it coordinates and reads every
   * office's progress by design, so it can open this one — and its own permit
   * is the Mayor's Permit, which is never inspected. No booking control.
   */
  await asAccount(browser, 'bplo', '/staff/queue', async (bplo) => {
    const target = await bplo.evaluate(async (id) => {
      const token = localStorage.getItem('biztrack.token.staff')
      const res = await fetch(`/api/v1/applications/${id}`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      })
      if (!res.ok) return null
      const app = (await res.json()).data as {
        assignments: { id: number; department: { code: string } | null }[]
      }
      return app.assignments.find((a) => a.department?.code === 'BPLO')?.id ?? null
    }, appId)

    if (target !== null) {
      await bplo.goto(`/staff/queue/${target}`)
      await bplo.waitForLoadState('networkidle')
      await expect(
        bplo.getByRole('button', { name: /^Book the .+ visit$/ }),
        'BPLO was offered a booking on another office’s clearance',
      ).toHaveCount(0)
    }

    /*
     * And the endpoint refuses it. BPLO coordinates the clearances but does not
     * inspect, so it does not hold `inspection.manage` and never reaches the
     * controller at all — the route gate is the refusal here.
     */
    const status = await bplo.evaluate(
      async ([id, code]) => {
        const token = localStorage.getItem('biztrack.token.staff')
        const res = await fetch(`/api/v1/applications/${id}/permits/${code}/inspection`, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({ scheduled_at: new Date(Date.now() + 86_400_000).toISOString() }),
        })
        return res.status
      },
      [appId, OWN_PERMIT.code] as const,
    )
    expect(status, 'BPLO was allowed to book another office’s inspection').toBe(403)
  })

  /*
   * And the office boundary proper: the City Health Office DOES hold
   * `inspection.manage` — it inspects its own premises visits — so it passes the
   * route gate and is stopped by the department comparison inside the
   * controller. That is the check the UI mirrors, and the one that would let a
   * sanitary officer book a zoning visit if it were ever dropped.
   */
  await asAccount(browser, 'sanitary', '/staff/queue', async (cho) => {
    const status = await cho.evaluate(
      async ([id, code]) => {
        const token = localStorage.getItem('biztrack.token.staff')
        const res = await fetch(`/api/v1/applications/${id}/permits/${code}/inspection`, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({ scheduled_at: new Date(Date.now() + 86_400_000).toISOString() }),
        })
        return res.status
      },
      [appId, OWN_PERMIT.code] as const,
    )
    expect(status, 'the City Health Office booked a zoning inspection').toBe(403)
  })
})


test('the office that issues a permit books its first visit from the review screen', async ({
  page,
  browser,
}) => {
  test.setTimeout(120_000)

  const fixture = await bookableClearance(page, browser)
  test.skip(
    fixture === null,
    'no filing could be walked to a clearance awaiting its first visit — the booking '
      + 'test below consumes the one it finds, so a second run against the same copy of '
      + 'the register skips. Restart e2e-stack.sh, which re-copies it.',
  )
  const { assignmentId, appId, permitName } = fixture as Booking

  await page.goto(`/staff/queue/${assignmentId}`)

  /*
   * The compact decision box, not the review sheet: accepting the paperwork
   * completes this office's assignment, so `owesReview` is false from here on.
   */
  const panel = page.locator('section[aria-label="Application status"]')
  await expect(panel).toBeVisible({ timeout: 30_000 })

  /*
   * Named after the permit, like every other repeated control on this panel.
   * A filing carries a clearance per office and their cards sit side by side,
   * so a bare "Book this visit" would be one more identical stop for anyone
   * moving through the page by control.
   */
  const dateField = page.getByLabel(`Date and time of the ${permitName} inspection`)
  const book = page.getByRole('button', { name: `Book the ${permitName} visit` })
  await expect(dateField).toBeVisible()
  await expect(book).toBeVisible()

  /*
   * Shut with `aria-disabled` while there is no date, never with `disabled`,
   * and it says what is missing. Checked on the attribute itself: toBeDisabled()
   * treats the two as the same thing and would pass either way.
   */
  await expect(book).not.toHaveAttribute('disabled', /.*/)
  await expect(book).toHaveAttribute('aria-disabled', 'true')
  const why = await book.getAttribute('aria-describedby')
  expect(why, 'the shut booking button does not say what is missing').toBeTruthy()
  // Attribute selector, not `#id`: React's useId mints ids like `:r1:`, which
  // are legal HTML ids and illegal CSS identifiers.
  await expect(page.locator(`[id="${why}"]`)).toContainText(/Choose a date/)

  /*
   * A weekday three days out, at 10:00. Local time on purpose — the control is
   * `datetime-local` and the browser reads it in the reader's zone, which is
   * exactly the conversion the product has to get right for the date the
   * applicant is shown to match the date the officer picked.
   */
  const when = new Date(Date.now() + 3 * 86_400_000)
  when.setHours(10, 0, 0, 0)
  const pad = (n: number) => String(n).padStart(2, '0')
  const localValue = `${when.getFullYear()}-${pad(when.getMonth() + 1)}-${pad(when.getDate())}T10:00`
  await dateField.fill(localValue)
  await expect(book).toHaveAttribute('aria-disabled', 'false')

  const [created] = await Promise.all([
    page.waitForResponse(
      (r) =>
        /\/permits\/[A-Z]+\/inspection$/.test(r.url()) && r.request().method() === 'POST',
      { timeout: 30_000 },
    ),
    book.click(),
  ])
  expect(created.status(), `booking answered: ${await created.text()}`).toBe(201)

  /*
   * ANNOUNCED, not merely redrawn. A card that quietly becomes a different card
   * fires no event a screen reader can report, so the result is written into a
   * live region — and the region is in the tree from the first render, or there
   * is nothing for assistive technology to observe changing.
   */
  const announcement = page.locator('[role="status"]', { hasText: /inspection booked for/ })
  await expect(announcement).toContainText(permitName)

  /*
   * And the visit is REAL: the card the panel now draws is the ordinary
   * outstanding-visit card, with this office's Approve on it. The booking
   * control is gone, because there is no longer a first visit to book.
   */
  await expect(page.getByRole('button', { name: /^Approve the .+ inspection$/ })).toBeVisible({
    timeout: 30_000,
  })
  await expect(page.getByRole('button', { name: `Book the ${permitName} visit` })).toHaveCount(0)

  /*
   * The date reached the record with its TIME intact, which is what the
   * applicant's Awaiting Other Permits card prints with `formatDateTime`. A
   * date-only write would show that visit booked for midnight.
   */
  const scheduled = await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.staff')
    const res = await fetch(`/api/v1/applications/${id}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
    const app = (await res.json()).data as { inspections: { scheduled_at: string | null }[] }
    return app.inspections.map((v) => v.scheduled_at)
  }, appId)
  expect(
    scheduled.some((at) => at !== null && new Date(at).getHours() === 10),
    'the booked visit did not keep the hour the officer chose',
  ).toBe(true)
})
