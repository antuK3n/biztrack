import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { sessionFor, waitForAnalytics } from './helpers'

/*
 * Do the dashboards answer to the register, or do they merely draw?
 *
 * The client's question was "see if the respective analytics will change
 * depending on your actions", and it is the right question to ask of a screen
 * whose numbers arrive fully formed from somewhere else. Every other spec in
 * this folder reads what the screens SAY. This one changes the register and
 * checks the screens noticed.
 *
 * ── The shape of the test, and why it is not the obvious shape ──────────────
 *
 * Analytics on this product are BATCH, not live. `analytics:refresh` recomputes
 * every dataset from the register and persists the result; every screen reads
 * that persisted row rather than computing on the request (AnalyticsResolver).
 * Nothing an officer does in the browser reaches a dashboard until a refresh has
 * run. That was true when the statistics were computed by a separate service and
 * it is still true now they are computed in-process — the batch boundary is a
 * product decision, not a consequence of where the arithmetic happened.
 *
 * So the honest sequence is: read the figure, change the register, read it again
 * and expect it UNCHANGED, refresh, read a third time and expect the exact
 * delta. A test that changed the register and expected the screen to move at
 * once would be asserting a product behaviour that does not exist and never did
 * — and it would fail for the right-looking wrong reason, sending someone to fix
 * a dashboard that is working as designed.
 *
 * ── Which refresh, and why ──────────────────────────────────────────────────
 *
 * `POST /api/v1/analytics/refresh` (routes/workflow.php), not the artisan
 * command. Three reasons and they all point the same way: it sits on
 * `analytics.view`, which is BPLO's own permission, so the officer whose figures
 * these are can already trigger it; it refreshes EVERY pushable dataset in one
 * call, which is what makes the cross-dataset invariant below meaningful; and it
 * runs inside the API process this stack raised, which is bound to this slot's
 * database — so the refresh cannot wander off and recompute somebody else's
 * register the way a mistyped `DB_DATABASE=` on the command line could. It is
 * throttled to 4/minute, which is why this file spends them carefully.
 *
 * ── Two readings taken on the same clock ────────────────────────────────────
 *
 * The baseline is taken AFTER a first refresh rather than off whatever snapshot
 * the stack was copied with. Half of these figures are windowed on `now` — the
 * 12-month registration window, permits valid today — so a snapshot computed
 * yesterday and one computed now differ for reasons that have nothing to do with
 * any action. Refreshing first puts both readings on the same clock, and leaves
 * the register as the only thing that changed between them.
 */

/*
 * Sessions come from sessionFor(), never from a hand-written path.
 *
 * These were literals — 'e2e/.auth/bplo.json' — written before sessions were
 * keyed by slot. Those files still exist as orphans from an earlier run, so the
 * literals kept resolving and kept handing back a token minted against a
 * DIFFERENT origin and a different copy of the register. Every context loaded a
 * dead session, every screen bounced to the login page, and seven tests failed
 * reporting that an <h1> was missing — which is true, and says nothing whatever
 * about the dashboards.
 *
 * sessionFor() reads the same E2E_SLOT the config and the setup project use, so
 * the three cannot drift apart.
 */
const BPLO_SESSION = sessionFor('bplo')
const SANITARY_SESSION = sessionFor('sanitary')
const SUPER_ADMIN_SESSION = sessionFor('admin')

/**
 * One API call made from inside the app's own origin, carrying the session the
 * browser is already holding.
 *
 * Same door the other specs use (see inspection-review.spec.ts): the token lives
 * in localStorage keyed by portal, and it is only readable once the page is on
 * origin — a fresh context starts on about:blank, where reading it is a
 * SecurityError.
 *
 * The status comes back rather than being thrown on, because two tests below are
 * ABOUT a status: a 422 that must happen and a 200 that must not be refused.
 */
async function api(
  page: Page,
  path: string,
  init: { method?: string; body?: Record<string, unknown> } = {},
): Promise<{ status: number; body: Record<string, unknown> | null }> {
  return page.evaluate(
    async (call: { path: string; method: string; body: Record<string, unknown> | null }) => {
      const token = localStorage.getItem('biztrack.token.staff')
      const res = await fetch(call.path, {
        method: call.method,
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
          ...(call.body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: call.body ? JSON.stringify(call.body) : undefined,
      })
      const body = (await res.json().catch(() => null)) as Record<string, unknown> | null

      return { status: res.status, body }
    },
    { path, method: init.method ?? 'GET', body: init.body ?? null },
  )
}

/**
 * The numbers off a chart's accessible table, keyed by row header.
 *
 * ChartFrame draws the picture and states the same figures in an `sr-only`
 * <table> beside it, because a recharts SVG carries no numbers at all. Reading
 * that table rather than scraping paths is not a workaround: it is the copy a
 * screen reader gets, so a figure that is wrong here is wrong for a reader who
 * has no other copy.
 */
async function figures(page: Page, table: string | RegExp): Promise<Map<string, string[]>> {
  const rows = await page
    .getByRole('table', { name: table })
    .locator('tbody tr')
    .evaluateAll((trs) =>
      trs.map((tr) => [...tr.children].map((cell) => (cell.textContent ?? '').trim())),
    )

  return new Map(rows.map((cells) => [cells[0] ?? '', cells.slice(1)]))
}

/** "1,670" → 1670. Thousands separators are a display choice, not a value. */
function numeric(text: string): number {
  return Number(text.replace(/[^0-9.-]/g, ''))
}

const DECISIONS = 'Decision outcomes for applications filed this month, with the approval rate'
const VOLUME = 'Applications filed this month by transaction type'
const BARANGAYS = 'Top Growing Barangays'

/**
 * The Decision Outcomes donut as a plain count per outcome.
 *
 * Pending's row header reads "Pending (not in the rate)" on screen — the panel
 * says out loud that it is in the ring and out of the approval-rate denominator
 * — so the qualifier is stripped here rather than retyped into every assertion.
 * An outcome with no filings is dropped from the plot entirely (a zero-width
 * wedge with a legend entry reads as "we lost this one"), hence `?? 0` at the
 * call sites rather than an assertion that all five rows exist.
 */
async function decisions(page: Page): Promise<Record<string, number>> {
  const rows = await figures(page, DECISIONS)
  const counts: Record<string, number> = {}
  for (const [header, cells] of rows) {
    counts[header.replace(' (not in the rate)', '')] = numeric(cells[0] ?? '0')
  }

  return counts
}

/**
 * A KPI card's figure, found through its label.
 *
 * The cards are three stacked <p>s with no table of their own, and the label's
 * <p> also holds the info button — so its text reads "Applications (all time)i"
 * and an exact-text match finds nothing. Anchoring on the label and stepping to
 * the sibling above it is what survives that.
 */
function kpi(page: Page, label: string) {
  return page.locator(
    `xpath=//p[starts-with(normalize-space(.), ${JSON.stringify(label)})]/preceding-sibling::p[1]`,
  )
}

/** Open a BPLO analytics screen and wait for its charts to have drawn. */
async function openDashboard(page: Page) {
  await page.goto('/staff/analytics')
  await waitForAnalytics(page, 'Analytics Dashboard')
  // The frame renders after the payload resolves, so the table is the ready
  // signal for the figures specifically rather than for the page.
  await expect(page.getByRole('table', { name: DECISIONS })).toHaveCount(1)
}

/**
 * Recompute every dataset and persist the result, then say how many sets moved.
 *
 * Asserted rather than fired and forgotten: a refresh that silently did nothing
 * would make every "the figure did not move" assertion below pass for the wrong
 * reason, which is the exact failure this file exists to catch.
 */
async function refreshAnalytics(page: Page) {
  const { status, body } = await api(page, '/api/v1/analytics/refresh', { method: 'POST' })

  expect(status, `the refresh endpoint answered ${status}: ${JSON.stringify(body)}`).toBe(200)

  const data = (body?.data ?? {}) as { refreshed?: number; failed?: number }
  expect(data.refreshed ?? 0, 'the refresh persisted no snapshots at all').toBeGreaterThan(0)
  expect(data.failed ?? 0, 'a dataset failed to refresh, so the figures below are half old').toBe(0)
}

test.describe('the dashboard answers to the register', () => {
  test.use({ storageState: BPLO_SESSION })

  /*
   * Two refreshes, each a full recompute of every dataset over the register,
   * plus the page loads around them. The default 30s is for a spec that reads a
   * screen; this one drives a batch pipeline twice.
   */
  test('a rejection moves Decision Outcomes by exactly one, and moves nothing else', async ({
    page,
  }) => {
    test.setTimeout(240_000)

    await openDashboard(page)
    await refreshAnalytics(page)
    await page.reload()
    await waitForAnalytics(page, 'Analytics Dashboard')
    await expect(page.getByRole('table', { name: DECISIONS })).toHaveCount(1)

    const before = await decisions(page)
    const volumeBefore = await figures(page, VOLUME)
    const allTimeBefore = numeric(await kpi(page, 'Applications (all time)').innerText())
    const thisMonthBefore = numeric(await kpi(page, 'This Month').innerText())

    /*
     * A second dataset entirely, read from the second screen. The refresh
     * recomputes all five datasets in one pass, so a dashboard that "responds"
     * by re-deriving noise would show up here as barangay registration counts
     * shifting under a decision that touched no business. This is the control.
     */
    await page.goto('/staff/analytics/business-growth')
    await waitForAnalytics(page, 'Business Growth Analysis')
    await expect(page.getByRole('table', { name: BARANGAYS })).toHaveCount(1)
    const barangaysBefore = await figures(page, BARANGAYS)

    /*
     * ── The action ─────────────────────────────────────────────────────────
     *
     * A filing REJECTED, which is one status change and therefore an
     * unambiguous arithmetic claim: Rejected +1, Pending −1, and the panel's
     * total untouched because the filing was already counted in it.
     *
     * Found rather than written down. Which application sits in `under_review`
     * changes every time anybody works the queue and a reseed renumbers the
     * table outright, so an id in this file is stale by definition — the same
     * reasoning as openForInspectionFiling in inspection-review.spec.ts.
     *
     * Two conditions on the candidate, and both are load-bearing:
     *
     *  - FILED THIS MONTH. Decision Outcomes buckets the filings created since
     *    `month_start` (DashboardAnalytics::decisionFacts). A July filing would
     *    be rejected for real and correctly change nothing on this panel, which
     *    would read exactly like the bug being hunted.
     *  - TYPE `new`, never a renewal. Business Renewal Performance on the growth
     *    screen is fitted on renewal outcomes, so rejecting a renewal could
     *    legitimately move the dataset being held fixed as the control.
     *
     * Every live status is searched rather than `under_review` alone, and that is
     * about the test not running out of register. This is a one-way action —
     * `rejectApplication` is terminal — so each run spends a filing, and a pool
     * of three would leave the fourth run SKIPPING, which is the failure mode
     * this whole file is written against: a rule nobody checked, reported as a
     * pass. Decision Outcomes buckets every live status into Pending by
     * exclusion (decisionFacts), so the arithmetic below is the same whichever
     * one the filing was in. `draft` is left out because a draft has not been
     * filed with the office and there is nothing to refuse.
     *
     * If this ever does skip, the fix is to restart e2e-stack.sh: it re-copies
     * the register into the slot and the pool comes back.
     */
    const LIVE = 'submitted,pending_payment,under_review,returned,for_inspection'
    const dashboard = await api(page, '/api/v1/analytics/dashboard')
    const monthStart = String(
      ((dashboard.body?.data ?? {}) as { month_start?: string }).month_start ?? '',
    )
    expect(monthStart, 'the dashboard payload no longer states the month it covers').toMatch(
      /^\d{4}-\d{2}-\d{2}$/,
    )

    const list = await api(page, `/api/v1/applications?status=${LIVE}&per_page=100`)
    const candidates = ((list.body?.data ?? []) as {
      id: number
      tracking_id: string
      application_type: string
      created_at: string
    }[]).filter((row) => row.application_type === 'new' && row.created_at >= monthStart)

    test.skip(
      candidates.length === 0,
      'no `new` filing under review was filed this month, so there is nothing to decide',
    )
    const target = candidates[0]!

    const rejected = await api(page, `/api/v1/applications/${target.id}/reject`, {
      method: 'POST',
      body: { reason: 'Automated end-to-end check: confirming the dashboard follows the register.' },
    })
    expect(
      rejected.status,
      `rejecting ${target.tracking_id} failed: ${JSON.stringify(rejected.body)}`,
    ).toBe(200)

    // The register really moved. Without this, every assertion below could be
    // satisfied by an action that never happened.
    const reread = await api(page, `/api/v1/applications/${target.id}`)
    expect((reread.body?.data as { status?: string })?.status).toBe('rejected')

    /*
     * ── The batch boundary, stated rather than glossed ─────────────────────
     *
     * The register has changed. The screen has not, and MUST not: it is reading
     * a persisted snapshot and no refresh has run since. This is the product's
     * real contract, and it is worth a demo audience knowing it before they
     * click around expecting a live figure. If this ever fails, analytics have
     * quietly become per-request and the snapshot layer is being bypassed.
     */
    await openDashboard(page)
    const stale = await decisions(page)
    expect(stale, 'the screen changed before any refresh ran — analytics are no longer batch').toEqual(
      before,
    )

    await refreshAnalytics(page)
    await page.reload()
    await waitForAnalytics(page, 'Analytics Dashboard')
    await expect(page.getByRole('table', { name: DECISIONS })).toHaveCount(1)

    const after = await decisions(page)

    /*
     * The RIGHT delta, not merely a different number. "It went up" would pass a
     * panel that double-counted, and a double-counted decision is worse than a
     * frozen one: it is confidently wrong.
     */
    expect(after['Rejected'] ?? 0, 'the rejection did not reach Decision Outcomes').toBe(
      (before['Rejected'] ?? 0) + 1,
    )
    expect(after['Pending'] ?? 0, 'the filing left Pending for somewhere other than Rejected').toBe(
      (before['Pending'] ?? 0) - 1,
    )

    /*
     * ── And the figures that must NOT have moved ───────────────────────────
     *
     * This half is what separates a dashboard that responds from one that
     * recomputes noise. Each of these was recomputed in the same pass, from the
     * same register, seconds after the ones above — so an unchanged reading here
     * is a positive result and not an absence of evidence.
     */
    expect(after['Approved'] ?? 0, 'an approval appeared out of a rejection').toBe(
      before['Approved'] ?? 0,
    )
    expect(after['Returned for revision'] ?? 0).toBe(before['Returned for revision'] ?? 0)

    // The donut still describes the same population: a decision moved a filing
    // between buckets, it did not add or remove one.
    const sum = (counts: Record<string, number>) =>
      Object.values(counts).reduce((run, n) => run + n, 0)
    expect(sum(after), 'the number of filings in the panel changed under a status change').toBe(
      sum(before),
    )

    // Same window, same snapshot, a different question: how many were FILED
    // this month. A decision is not a filing.
    expect(
      numeric(await kpi(page, 'This Month').innerText()),
      'deciding a filing changed how many filings were made',
    ).toBe(thisMonthBefore)
    expect(
      numeric(await kpi(page, 'Applications (all time)').innerText()),
      'the all-time filing count moved under a decision',
    ).toBe(allTimeBefore)
    expect(
      await figures(page, VOLUME),
      'Application Volume counts filings by type and cannot know a decision was made',
    ).toEqual(volumeBefore)

    await page.goto('/staff/analytics/business-growth')
    await waitForAnalytics(page, 'Business Growth Analysis')
    await expect(page.getByRole('table', { name: BARANGAYS })).toHaveCount(1)
    expect(
      await figures(page, BARANGAYS),
      'a decision on one filing moved business registration counts in every barangay',
    ).toEqual(barangaysBefore)
  })
})

/*
 * ── The RA 11032 processing-category gate ───────────────────────────────────
 *
 * Shipped today. The client: "On the admin side, choosing the Application
 * category must be required. The admin must not approve the application unless
 * an Application category is chosen."
 *
 * Two enforcements and both are tested, because either alone is a false
 * comfort. The server throws a 422 keyed on `complexity`
 * (WorkflowService::requireProcessingCategory) — that is the rule. The review
 * sheet shuts Approve and says why — that is the officer never meeting the rule
 * as a red bar after the fact. A screen-only guard is a suggestion; a
 * server-only guard is an officer pressing a button and being told no with no
 * idea what to do next.
 *
 * The uncategorised filing is found, not written down. `submit()` seeds a tier
 * from Ra11032::tierFor(), so every filing made through the product arrives
 * categorised and the gate is quiet on it; what is left null are the rows that
 * predate submission-time classification, and the register holds a handful.
 * They are also the rows an id would go stale on fastest, since categorising one
 * is a single control away.
 */

/**
 * An open review this office owes, on a filing nobody has categorised.
 *
 * `GET /assignments` is this office's own queue, so every row in it is a row
 * this session may open — `GET /assignments/{id}` is narrowed a second time by
 * authorizeDepartment and answers 403 for anyone else's. Only `pending`,
 * `in_progress` and `returned`: a completed assignment renders the sheet as a
 * closed record with no Mode pills and no Approve at all.
 */
async function findUncategorisedReview(
  page: Page,
): Promise<{ assignmentId: number; applicationId: number; trackingId: string } | null> {
  await page.goto('/staff/queue')

  const list = await api(
    page,
    '/api/v1/assignments?application_status=under_review&status=pending,in_progress,returned&per_page=50',
  )
  const rows = (list.body?.data ?? []) as { id: number; application: { id: number } | null }[]

  for (const row of rows) {
    if (!row.application) continue
    const detail = await api(page, `/api/v1/applications/${row.application.id}`)
    // A row this session cannot read in full is skipped, not fatal: visibility
    // can answer 403 for a filing whose routing moved between the two calls.
    const app = detail.body?.data as
      | { id: number; tracking_id: string; ra11032?: { tier: string | null } }
      | undefined
    if (!app?.ra11032) continue
    if (app.ra11032.tier === null) {
      return { assignmentId: row.id, applicationId: app.id, trackingId: app.tracking_id }
    }
  }

  return null
}

/**
 * The same uncategorised filing, found from the register rather than a queue.
 *
 * BPLO needs its own door and this is not duplication for its own sake: BPLO
 * coordinates every filing and signs off FIRST, so its assignments on the old
 * uncategorised rows are all `completed` and its review queue is empty of them.
 * Looking for one there found nothing and skipped the test, which is the worst
 * outcome available — a rule nobody checked, reported as a pass.
 *
 * `GET /applications` is scoped by ApplicationVisibility, and BPLO's scope is
 * the register, so this reaches the filings its own queue no longer holds.
 *
 * A filing NOT in `under_review` is preferred, and that is fixture care rather
 * than fussiness. Rejecting is terminal, so this test spends an uncategorised
 * filing every run — and uncategorised filings are exactly what the two tests
 * above need. Taking one an office still owes a review on would starve the
 * review-sheet test first; taking one already past review starves nothing.
 */
async function findUncategorisedFiling(
  page: Page,
): Promise<{ applicationId: number; trackingId: string; status: string } | null> {
  await page.goto('/staff/queue')

  const list = await api(
    page,
    '/api/v1/applications?status=submitted,pending_payment,under_review,returned,for_inspection&per_page=100',
  )
  const rows = (list.body?.data ?? []) as { id: number }[]
  const uncategorised: { applicationId: number; trackingId: string; status: string }[] = []

  for (const row of rows) {
    // The list resource does not carry `ra11032` — the tier only travels on the
    // single-filing payload, so each candidate costs a read.
    const detail = await api(page, `/api/v1/applications/${row.id}`)
    const app = detail.body?.data as
      | { id: number; tracking_id: string; status: string; ra11032?: { tier: string | null } }
      | undefined
    if (!app?.ra11032) continue
    if (app.ra11032.tier === null) {
      uncategorised.push({ applicationId: app.id, trackingId: app.tracking_id, status: app.status })
    }
  }

  return (
    uncategorised.find((app) => app.status !== 'under_review') ?? uncategorised[0] ?? null
  )
}

test.describe('an uncategorised filing cannot be approved', () => {
  test.use({ storageState: SANITARY_SESSION })

  test('the review sheet shuts Approve and says what to do about it', async ({ page }) => {
    const target = await findUncategorisedReview(page)
    test.skip(target === null, 'every filing on this office’s queue already has a category')

    await page.goto(`/staff/queue/${target!.assignmentId}`)
    // The sheet opens in View, where there is no Approve to shut. The rule is a
    // property of the deciding screen, so the test has to be on it.
    await page.getByRole('group', { name: 'Mode' }).getByRole('button', { name: 'Edit' }).click()

    const approve = page.getByRole('button', { name: 'Approve', exact: true })
    await expect(approve).toBeVisible()
    await expect(approve).toHaveAttribute('aria-disabled', 'true')

    /*
     * `aria-disabled`, never the native attribute, and this is the whole
     * accessibility of the rule rather than a style note. A `disabled` button
     * leaves the tab order, so a keyboard or screen-reader user meets a control
     * that is simply absent — and `aria-describedby` on a control nobody can
     * reach announces nothing. Shut-but-focusable keeps the button, its state
     * and its reason together. Asserted on the DOM property because
     * `toBeDisabled` treats the two spellings as the same thing, and the entire
     * point is that they are not.
     */
    expect(
      await approve.evaluate((el: HTMLButtonElement) => el.disabled),
      'Approve was closed with the native attribute, which drops it out of the tab order',
    ).toBe(false)

    // The reason is announced WITH the control, not merely printed near it.
    await expect(approve).toHaveAttribute('aria-describedby', 'approve-blocked-why')
    const why = page.locator('#approve-blocked-why')
    await expect(why).toBeVisible()

    /*
     * WCAG 3.3.2 asks for what is REQUIRED, not merely that something is wrong.
     * So the sentence has to name the three tiers and point at the panel that
     * sets them — "cannot be approved yet" on its own leaves an officer hunting.
     */
    await expect(why).toContainText(/no processing category/i)
    for (const tier of ['Simple', 'Complex', 'Highly technical']) {
      await expect(why).toContainText(tier)
    }
    await expect(why.getByRole('link', { name: 'For Office Use Only' })).toHaveAttribute(
      'href',
      '#for-office-use',
    )

    /*
     * An aria-disabled control is still clickable — that is the cost of keeping
     * it in the tab order — so the sheet guards the press as well as the
     * button, and does it out loud rather than returning into silence. `force`
     * because Playwright's actionability treats aria-disabled as not enabled;
     * an officer's mouse has no such scruples.
     */
    await approve.click({ force: true })
    await expect(
      page.getByText(/Choose this application’s processing category under For Office Use Only/i),
    ).toBeVisible()

    // Still under review. A shut button that submitted anyway would be the
    // worst of both worlds.
    const after = await api(page, `/api/v1/applications/${target!.applicationId}`)
    expect((after.body?.data as { status?: string })?.status).toBe('under_review')
  })

  test('the API refuses a direct approval with a 422 keyed on complexity', async ({ page }) => {
    /*
     * The screen's guard is a courtesy. This is the rule, and it is the half
     * that still holds for a caller who never loaded the screen — a stale tab, a
     * script, a replayed request. If only the browser check survived a future
     * refactor, every test above would still pass and permits would issue with
     * no statutory clock behind them.
     */
    const target = await findUncategorisedReview(page)
    test.skip(target === null, 'every filing on this office’s queue already has a category')

    const refused = await api(page, `/api/v1/assignments/${target!.assignmentId}/approve`, {
      method: 'POST',
      body: {},
    })

    expect(refused.status, 'the server approved an uncategorised filing').toBe(422)

    /*
     * Keyed on `complexity`, not merely a 422 with prose. The key is what the
     * review sheet would bind to a field, so a message delivered under the wrong
     * key reaches the officer as an unattached red bar.
     */
    const errors = (refused.body?.errors ?? {}) as Record<string, string[]>
    expect(Object.keys(errors), 'the refusal is not keyed on the field at fault').toContain(
      'complexity',
    )
    expect(errors['complexity']?.[0] ?? '').toMatch(/processing category/i)
  })
})

test.describe('rejection is deliberately not gated', () => {
  test.use({ storageState: BPLO_SESSION })

  test('an uncategorised filing can still be refused', async ({ page }) => {
    /*
     * The other half of the client's rule, and the half a gate is most likely to
     * over-apply. A rejected filing never enters a processing clock, so
     * demanding a tier before rejecting would stop an officer saying no for the
     * sake of a field nothing will ever measure — RA 11032 has no deadline for a
     * transaction that was refused. `requireProcessingCategory` is called from
     * approveAssignment and approveAndIssue and from nowhere else; this is what
     * proves that stayed true after today's change.
     *
     * Run as BPLO because `application.reject` is BPLO's and the super admin's.
     * The six clearance offices decide their own review and cannot refuse the
     * filing outright, which is why the sheet above showed no Reject at all.
     */
    const target = await findUncategorisedFiling(page)
    test.skip(target === null, 'no uncategorised filing is under review')

    const refusal = await api(page, `/api/v1/applications/${target!.applicationId}/reject`, {
      method: 'POST',
      body: { reason: 'Automated end-to-end check: rejection must not require a category.' },
    })

    expect(
      refusal.status,
      `rejecting the uncategorised ${target!.trackingId} was blocked: ${JSON.stringify(refusal.body)}`,
    ).toBe(200)

    const after = await api(page, `/api/v1/applications/${target!.applicationId}`)
    const app = after.body?.data as { status?: string; ra11032?: { tier: string | null } }
    expect(app?.status).toBe('rejected')
    // And it went through WITHOUT one being invented on the way, which would
    // satisfy the gate by putting a statutory deadline on a filing that never
    // had one.
    expect(app?.ra11032?.tier, 'the refusal quietly assigned a processing category').toBeNull()
  })
})

/*
 * ── Which reader reaches which screen ───────────────────────────────────────
 *
 * The permission was split along the line the client drew: "BPLO side should
 * only have the 3 dashboards (Processing Time should not exist here) — Super
 * admin side should only have Processing Time dashboard". analytics.spec.ts
 * already presses the tabs and asserts the bounces; what is added here is the
 * half that matters for a demo, which is that each screen a reader IS allowed
 * arrives carrying figures. A screen that renders its heading and an empty frame
 * passes a reachability test and fails an audience.
 */

/**
 * Every screen states its numbers somewhere a reader can get at.
 *
 * Any <table>, not `figure table`: the three screens do not agree on the
 * container and they are right not to. The dashboard and the growth screen wrap
 * a drawing and its sr-only twin in a <figure>; Renewal Risk's watchlist and
 * review list are tables in their own right with nothing drawn beside them, so
 * there is no figure to be inside. Both are the reading a screen reader gets,
 * which is the only property this assertion is about.
 */
async function assertStatesFigures(page: Page, path: string, heading: string) {
  await page.goto(path)
  await waitForAnalytics(page, heading)

  const tables = page.locator('table')
  await expect(tables.first(), `${path} drew no data table at all`).toBeAttached()

  const cells = await tables.locator('tbody td').allTextContents()
  const numbers = cells.filter((cell) => /\d/.test(cell))
  expect(numbers.length, `${path} renders tables but not one figure in them`).toBeGreaterThan(0)
}

test.describe('the three screens BPLO is allowed, and the one it is not', () => {
  test.use({ storageState: BPLO_SESSION })

  const SCREENS = [
    { path: '/staff/analytics', title: 'Analytics Dashboard' },
    { path: '/staff/analytics/renewal-risk', title: 'Renewal Risk Prediction' },
    { path: '/staff/analytics/business-growth', title: 'Business Growth Analysis' },
  ] as const

  for (const screen of SCREENS) {
    test(`${screen.title} arrives with figures on it`, async ({ page }) => {
      await assertStatesFigures(page, screen.path, screen.title)
    })
  }

  test('Permit Processing Time Monitoring is not BPLO’s to open', async ({ page }) => {
    await page.goto('/staff/analytics/processing-time')
    await expect(page).toHaveURL(/\/staff\/dashboard$/, { timeout: 30_000 })
    await expect(
      page.getByRole('heading', { name: /permit processing time monitoring/i }),
    ).toHaveCount(0)
  })
})

test.describe('the one screen the super admin is allowed, and the three they are not', () => {
  test.use({ storageState: SUPER_ADMIN_SESSION })

  test('Permit Processing Time Monitoring arrives with figures on it', async ({ page }) => {
    await assertStatesFigures(
      page,
      '/staff/analytics/processing-time',
      'Permit Processing Time Monitoring',
    )
  })

  test('the three BPLO dashboards are out of reach', async ({ page }) => {
    for (const path of [
      '/staff/analytics',
      '/staff/analytics/renewal-risk',
      '/staff/analytics/business-growth',
    ]) {
      await page.goto(path)
      await expect(page, `${path} let the super admin in`).toHaveURL(/\/staff\/dashboard$/, {
        timeout: 30_000,
      })
    }
  })
})
