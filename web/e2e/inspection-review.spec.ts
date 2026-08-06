import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'

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
 * `inspection.manage`. It has to be one of the six offices that issues a
 * clearance. ApplicationVisibility scopes this session to filings routed to
 * CPDO, which is why the helpers below find their filing rather than hard-code
 * one.
 */
test.use({ storageState: 'e2e/.auth/zoning.json' })

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
 * All of it came from one fact that is easy to reintroduce. A filing only
 * reaches `for_inspection` once every review assignment has completed
 * (WorkflowService::afterReviewProgress), so `completed_at` is always set by
 * then and ReviewPage's `decided` is always true — which used to mean a static
 * green "Approved", no controls at all, and the whole 1,200-line BPLO sheet
 * rendered flat. Anyone reasoning about `decided` again without knowing about
 * this status will land back there, so these assertions are deliberately
 * literal about what must and must not be on the page.
 *
 * ── Read-only on purpose ────────────────────────────────────────────────────
 *
 * Approving here would consume the fixture: recording the last outstanding
 * visit issues the permits and moves the filing to `approved`, so the spec
 * would pass once and then find nothing to open. The button's WIRING is
 * asserted — it exists, it is reachable, it names its own inspection — and the
 * fact that pressing it moves a filing from `for_inspection` to `approved` with
 * permits issued was verified by hand against live data. If this is ever made
 * to press the button, give it a filing it creates itself.
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
 * This office's OWN queue rather than a hardcoded id, or the register.
 *
 * Two separate things make anything else wrong here, and both of them present
 * as a blank page rather than as an error, so they are worth naming.
 *
 * The first is churn. Which application sits in `for_inspection` changes every
 * time anybody works the queue — the two this was written against were both
 * approved within the hour — and re-running the analytics history seeder
 * renumbers rows outright. An id written down here is stale by definition.
 *
 * The second is the office boundary, and it is what broke this suite. Picking
 * off `GET /applications?status=for_inspection` and following
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
     * `for_inspection` no longer implies this office has finished its review:
     * since commit 5da4daa the FIRST inspecting office's approval flips the
     * whole filing while every other office's assignment is still `pending`.
     * The compact box is shown only to an office whose own review is done, so
     * without this filter the helper could hand these tests a filing on which
     * the reading office still owes a review — where the review sheet is
     * CORRECTLY on screen and every assertion below would be measuring the
     * wrong branch. See `openOwedReviewFiling` for the other half.
     *
     * It also happens to be the only state that can carry a visit at all:
     * WorkflowService::scheduleInspectionFor books the office's visit on its
     * approval, so an office that has not approved has nothing to inspect.
     */
    const list = await fetch(
      '/api/v1/assignments?application_status=for_inspection&status=completed&per_page=20',
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
 * A `for_inspection` filing on which this office's own review is still OPEN.
 *
 * The mirror of the helper above, and the state that had no test at all. It is
 * an ordinary state, not a corner: on BIZ-2026-00958 five of seven offices were
 * in it at once.
 *
 * No inspection is required of the filing here — an office that has not
 * approved has not had a visit booked for it — so this looks only at the
 * assignment.
 */
async function openOwedReviewFiling(page: Page): Promise<number | null> {
  await page.goto('/staff/queue')

  const assignmentId = await page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.staff')
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

    const list = await fetch(
      '/api/v1/assignments?application_status=for_inspection&status=pending,in_progress,returned&per_page=20',
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
 * `for_inspection` filing, unconditionally — no regard for whether the reading
 * office's own assignment was still pending. Its helper preferred a filing with
 * an outstanding visit but fell back to `anyWithVisits`, so it could and did
 * land on an office that still owed a review and still demand the form be gone.
 *
 * That assertion locked a DEADLOCK in. An office in that state had no Approve
 * and no Return control anywhere in the product; without an approval
 * `scheduleInspectionFor` never fires, without every approval `isFullyCleared`
 * never passes, and the permits on that filing can never be issued by any
 * action the product offers. `BIZ-2026-00958` sat there with five offices
 * blocked. The API had no such guard — the block was purely client-side, which
 * is why all 649 backend tests passed over it — so the only thing standing
 * between the bug and a fix was this file.
 *
 * It has been rewritten to the real rule rather than relaxed to whatever passes.
 * If a future change makes the form disappear from an office that still owes a
 * review, the second test must go red — do not weaken it to make it green.
 */

test('an office that has FINISHED its review opens on the decision box, not the application form', async ({
  page,
}) => {
  const assignmentId = await openForInspectionFiling(page)
  test.skip(assignmentId === null, 'no for_inspection filing with a scheduled visit on this stack')

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
   */
  await expect(page.getByRole('heading', { name: ADMIN_REVIEW_SHEET })).toHaveCount(0)
  await expect(page.locator('details')).toHaveCount(0)

  // "but the progress thingy is cool, keep that".
  await expect(page.getByText('Application progress')).toBeVisible()
})

test('an office that still OWES a review can reach its decision on a For Inspection filing', async ({
  page,
}) => {
  const assignmentId = await openOwedReviewFiling(page)
  test.skip(assignmentId === null, 'no for_inspection filing with an open review for this office')

  /*
   * The review sheet, not the compact box. The filing's status says
   * `for_inspection`; this office's assignment does not, and the assignment is
   * what this screen answers to.
   */
  await expect(page.getByRole('heading', { name: ADMIN_REVIEW_SHEET })).toBeVisible()
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

test('every outstanding visit carries its own named Approve and Reject', async ({ page }) => {
  const assignmentId = await openForInspectionFiling(page)
  test.skip(assignmentId === null, 'no for_inspection filing with a scheduled visit on this stack')

  const approve = page.getByRole('button', { name: /^Approve the .+ inspection$/ })
  const reject = page.getByRole('button', { name: /^Reject the .+ inspection with remarks$/ })

  const approveCount = await approve.count()
  test.skip(approveCount === 0, 'every visit on this filing has already been conducted')

  // Same number of each: a visit that can be approved can be rejected.
  expect(await reject.count()).toBe(approveCount)

  /*
   * A filing carries a visit per inspecting office — up to six now that every
   * clearance requires one — so the CARDS repeat, and a column of buttons all
   * called "Approve" is a list a screen-reader user cannot navigate. Each name
   * has to say which office's visit it decides.
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
  test.skip(assignmentId === null, 'no for_inspection filing with a scheduled visit on this stack')

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
   * Named per visit for the same reason Approve is: up to six of these can be
   * on one filing.
   */
  const assignmentId = await openForInspectionFiling(page)
  test.skip(assignmentId === null, 'no for_inspection filing with a scheduled visit on this stack')

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

    const list = await fetch('/api/v1/assignments?application_status=for_inspection&per_page=20', {
      headers,
    })
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

test('a filing that is not for inspection still opens on the full review sheet', async ({
  page,
}) => {
  /*
   * The other half of the change: only `for_inspection` gets the box. If this
   * fails, the compact screen has swallowed a status that needs the form —
   * which is a far worse regression than the one being fixed, because an
   * officer under review would lose every field they are meant to read.
   */
  await page.goto('/staff/queue')
  // This office's own queue, for the same reason as above: an assignment on
  // somebody else's department answers 403 and leaves an empty page behind.
  const assignmentId = await page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.staff')
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    // Open review only: a completed assignment renders the sheet as a closed
    // record with no Mode pills, and the Edit-mode assertions below need them.
    const list = await fetch(
      '/api/v1/assignments?application_status=under_review&status=pending,in_progress,returned&per_page=3',
      { headers },
    )
    const rows = (await list.json()).data as { id: number }[]
    return rows[0]?.id ?? null
  })
  test.skip(assignmentId === null, 'no open under_review review on this office’s queue')

  await page.goto(`/staff/queue/${assignmentId}`)
  await page.waitForLoadState('networkidle')

  await expect(page.getByRole('heading', { name: ADMIN_REVIEW_SHEET })).toBeVisible()
  await expect(page.locator('section[aria-label="Application status"]')).toHaveCount(0)

  /*
   * The sheet leads with the office READING it (SEP-4).
   *
   * It announced itself as "Business Permit & Licensing Office · Admin Review"
   * to all seven offices, lettered A–E after BPLO's paper form, with the
   * reader's own four-question clearance buried in Section D. That is most of
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
