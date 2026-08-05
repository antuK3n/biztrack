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
 */

/** The one line that only ever appears on the full BPLO admin-review sheet. */
const ADMIN_REVIEW_SHEET = 'Business Permit & Licensing Office · Admin Review'

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

    const list = await fetch(
      '/api/v1/assignments?application_status=for_inspection&per_page=20',
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
      const app = (await detail.json()).data as {
        inspections: {
          status: string
          conducted_at: string | null
          department: { code: string } | null
        }[]
      }
      // A filing with no visit scheduled renders the empty-state copy instead
      // of cards, which is a different branch than the one under test.
      if (app.inspections.length === 0) continue

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

test('a For Inspection filing opens on the decision box, not the application form', async ({
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
   */
  await expect(page.getByText(ADMIN_REVIEW_SHEET)).toHaveCount(0)
  await expect(page.locator('details')).toHaveCount(0)

  // "but the progress thingy is cool, keep that".
  await expect(page.getByText('Application progress')).toBeVisible()
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
    const list = await fetch('/api/v1/assignments?application_status=under_review&per_page=3', {
      headers,
    })
    const rows = (await list.json()).data as { id: number }[]
    return rows[0]?.id ?? null
  })
  test.skip(assignmentId === null, 'no under_review filing on this office’s queue')

  await page.goto(`/staff/queue/${assignmentId}`)
  await page.waitForLoadState('networkidle')

  await expect(page.getByText(ADMIN_REVIEW_SHEET)).toBeVisible()
  await expect(page.locator('section[aria-label="Application status"]')).toHaveCount(0)
})
