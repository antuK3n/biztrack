import { expect, test } from '@playwright/test'
import type { APIRequestContext } from '@playwright/test'
import fs from 'node:fs'
import { ACCOUNTS, OFFICES, sessionFor } from './helpers'

/*
 * ── "as a cenro officer, i can only see cenro stuff" ────────────────────────
 *
 * The client asked the question twice, in two shapes:
 *
 *   "see if for example, as a cenro officer, i can only see cenro stuff"
 *   "sanitary accounts can only see sanitary permits, and fire accounts can
 *    only see fire"
 *
 * ── Why every claim here is made from TWO seats ─────────────────────────────
 *
 * An office looking at its own queue looks IDENTICAL whether the boundary is
 * enforced perfectly or was never written. `GET /assignments` returns CHO rows
 * to the CHO officer under a rule that scopes by department AND under no rule
 * at all, if CHO happens to be the only office with rows on that page. So a
 * spec that signs in as CENRO and asserts "I see CENRO things" proves exactly
 * nothing, and would have passed on every version of this product including
 * the ones the client complained about.
 *
 * Every scoping claim below is therefore differential: something is located
 * that demonstrably belongs to office A, and office B is then shown to be
 * unable to reach it. And the positive half is always asserted alongside —
 * "nobody can reach anything" is a state a broken deployment reaches too, and
 * a suite that only proves refusals would sail through it.
 *
 * ── Why this is fought at the API and not on the screen ─────────────────────
 *
 * A row filtered out of a list but still fetchable by id is not a boundary, it
 * is a decoration. The ids in this register are sequential integers and
 * `/api/v1/permits/8324/pdf` is a URL anyone can type. So the queue tests read
 * the same endpoints the screens read, with another office's bearer token, and
 * insist on 403/404 rather than on the row being missing from some list.
 *
 * The sessions are the ones auth.setup.ts already minted, one per office, read
 * straight off disk — signing in again here would trip the login limiter (see
 * that file), and the token is all these tests need since the API authenticates
 * on the Authorization header rather than on a cookie.
 */

type Office = (typeof OFFICES)[number]
type Account = keyof typeof ACCOUNTS

/**
 * The staff token auth.setup.ts minted for one office.
 *
 * Through `sessionFor` rather than a hand-built path, because the directory is
 * keyed by E2E_SLOT: every slot serves its own copy of the register, sanctum
 * stores the token as a row in that copy, and a session file written by a
 * neighbouring slot LOOKS right — same shape, same leading id, since the copies
 * start identical and their ids march in lockstep — while answering 401 here.
 *
 * That failure mode is worth naming because of what it does to THIS file
 * specifically. A dead session produces 401s and empty lists, which is
 * indistinguishable at a glance from "this office correctly cannot see that" —
 * so a clobbered token turns every refusal in this suite into a false proof of
 * safety, the one outcome worse than a red test.
 *
 * Hence `assertLive` below: every account is checked to be genuinely signed in
 * before a single scoping conclusion is drawn from it.
 */
function bearer(account: Account): Record<string, string> {
  const file = sessionFor(account)
  const state = JSON.parse(fs.readFileSync(file, 'utf8')) as {
    origins?: { localStorage: { name: string; value: string }[] }[]
  }
  for (const origin of state.origins ?? []) {
    const hit = origin.localStorage.find((e) => e.name === 'biztrack.token.staff')
    if (hit) return { Authorization: `Bearer ${hit.value}`, Accept: 'application/json' }
  }
  throw new Error(`${file} holds no staff token — run the setup project for this slot`)
}

/**
 * Every session this file reasons from is signed in, before anything is read.
 *
 * A whole-suite guard rather than a per-test one: the tests below are a web of
 * cross-office reads, and one dead account would quietly make several of them
 * agree that the boundary holds. `/auth/me` also proves WHICH account the token
 * belongs to and which department it sits in, so a session file that had been
 * swapped for another office's — the exact accident this is guarding against —
 * is caught rather than silently believed.
 */
async function assertLive(request: APIRequestContext, account: Account, code: string | null) {
  const { status, body } = await getAs(request, account, '/api/v1/auth/me')
  expect(status, `the saved session for ${account} is not signed in`).toBe(200)
  const me = body.data as { email: string; department: DepartmentRef | null }
  expect(me.email, `${sessionFor(account)} holds somebody else's session`).toBe(ACCOUNTS[account])
  expect(me.department?.code ?? null, `${account} is not the ${code ?? 'department-less'} account`)
    .toBe(code)
}

test.beforeAll(async ({ request }) => {
  for (const office of OFFICES) await assertLive(request, office.account, office.code)
  // The super admin belongs to no department, which is itself load-bearing:
  // ApplicationVisibility's office branch fails closed on a null department.
  await assertLive(request, 'admin', null)
})

/** GET as one office, returning status and parsed body together. */
async function getAs(
  request: APIRequestContext,
  account: Account,
  url: string,
): Promise<{ status: number; body: Record<string, unknown> }> {
  const res = await request.get(url, { headers: bearer(account) })
  let body: Record<string, unknown> = {}
  try {
    body = (await res.json()) as Record<string, unknown>
  } catch {
    // A PDF, or an error page with no JSON body. The status is the assertion.
  }
  return { status: res.status(), body }
}

/**
 * Refused, by either honest answer.
 *
 * 403 is what this API says when the row exists and the reader may not have it,
 * and 404 is what it would say if a future change chose to hide the row's
 * existence too. Both are boundaries; only 200 is a leak. Accepting either is
 * not a weakening — it is refusing to pin an implementation detail that no
 * client can observe the difference between.
 */
const REFUSED = [403, 404]

interface DepartmentRef {
  code: string
  name: string
}

interface AssignmentRow {
  id: number
  department: DepartmentRef | null
  application: { id: number }
}

interface InspectionRow {
  id: number
  department: DepartmentRef | null
  findings: string | null
  result: string | null
  inspector: { id: number; name: string } | null
}

interface PermitRow {
  id: number
  permit_number: string
  permit_type: { code: string; name: string }
}

/*
 * ── 1. The queues ───────────────────────────────────────────────────────────
 */

test('every office queue carries its own department’s work, and carries some', async ({
  request,
}) => {
  /*
   * Driven off OFFICES rather than a list retyped here, because the mapping
   * account → department code IS the claim: if a seventh office is added or a
   * department renamed, this must follow it rather than keep asserting the old
   * shape and passing.
   *
   * Both halves in one loop on purpose. The `toEqual([code])` alone would be
   * satisfied by an empty queue — `[...new Set([])]` is `[]`, not `[code]`,
   * which is why the emptiness check is a separate assertion rather than being
   * left implied.
   */
  for (const office of OFFICES) {
    const { status, body } = await getAs(request, office.account, '/api/v1/assignments?per_page=50')
    expect(status, `${office.account} can open its own queue`).toBe(200)

    const rows = body.data as AssignmentRow[]
    expect(rows.length, `${office.code}'s queue has rows to reason about`).toBeGreaterThan(0)

    const codes = [...new Set(rows.map((r) => r.department?.code ?? '(unrouted)'))].sort()
    expect(codes, `${office.account}'s queue is ${office.code} work and nothing else`).toEqual([
      office.code,
    ])
  }
})

test('an assignment on one office’s queue is unreachable from every other office', async ({
  request,
}) => {
  /*
   * The differential half of the test above, and the one that actually proves
   * something. `GET /assignments/{id}` is the endpoint the review screen opens
   * on, so a 200 here is not a hypothetical — it is another office's filing
   * rendered in full, with the applicant's particulars and this office's
   * decision controls.
   *
   * Note there is no cross-office reader to exempt here. BPLO holds
   * `application.view_any_office` and reads every FILING, but
   * AssignmentController::authorizeDepartment is deliberately narrower than
   * that permission — an assignment is one office's piece of work, and BPLO is
   * refused the other six the same as anybody else. If that changes, this goes
   * red, which is the point.
   */
  const own = new Map<Office, number>()
  for (const office of OFFICES) {
    const { body } = await getAs(request, office.account, '/api/v1/assignments?per_page=1')
    const rows = body.data as AssignmentRow[]
    expect(rows[0]?.id, `${office.code} has an assignment to be protective of`).toBeTruthy()
    own.set(office, rows[0].id)
  }

  for (const office of OFFICES) {
    const id = own.get(office) as number

    // The positive half, restated per office: a boundary that refuses everyone
    // is not a boundary, it is an outage.
    const mine = await getAs(request, office.account, `/api/v1/assignments/${id}`)
    expect(mine.status, `${office.code} can open its OWN assignment ${id}`).toBe(200)

    for (const other of OFFICES) {
      if (other.account === office.account) continue
      const theirs = await getAs(request, other.account, `/api/v1/assignments/${id}`)
      expect(
        REFUSED,
        `${other.code} reached ${office.code}'s assignment ${id} — that is another office's filing`,
      ).toContain(theirs.status)
    }
  }
})

/*
 * ── 2. The filing itself, by id ─────────────────────────────────────────────
 */

test('a filing is closed to an office it was never routed to', async ({ request }) => {
  /*
   * One step coarser than the assignment test: not "may you work this", but
   * "may you read this filing at all". A filing carries the applicant's home
   * address, their uploaded government ID and their sworn declaration, so the
   * answer for an office that was never on it has to be no.
   *
   * The routing is read through BPLO, which is the one account documented to
   * see across offices (`application.view_any_office`) — asking the office
   * under test who else is on its filing would be asking the thing being
   * tested. Every office it names as absent is then made to try the door.
   *
   * A filing routed to all seven is no use here and is simply skipped; on this
   * register most are, which is exactly why this searches rather than
   * hard-codes.
   *
   * The offices made to try the door are the six clearance offices, never BPLO,
   * and that is not a hole. BPLO holds `application.view_any_office` BY DESIGN —
   * it issues the mayor's permit and coordinates the other six, so it reads the
   * register the same as the super admin does. Listing it as an outsider would
   * be asserting a product nobody asked for. Its cross-office read is asserted
   * positively at the bottom of this test instead, which is the honest place for
   * it: the exemption is real and is meant to be visible.
   */
  for (const office of OFFICES) {
    const mine = await getAs(request, office.account, '/api/v1/applications?per_page=40')
    const candidates = (mine.body.data as { id: number; tracking_id: string }[]) ?? []
    expect(candidates.length, `${office.code} has filings to reason about`).toBeGreaterThan(0)

    let probed = false
    for (const candidate of candidates) {
      const full = await getAs(request, 'bplo', `/api/v1/applications/${candidate.id}`)
      if (full.status !== 200) continue
      const detail = full.body.data as { assignments: AssignmentRow[] }
      const routed = new Set(detail.assignments.map((a) => a.department?.code))

      /*
       * The office under test must really be ON this filing, or its own 200
       * below says nothing about scoping.
       *
       * A `continue` and not an assertion, because a filing can legitimately be
       * on an office's list without being routed to it: BPLO reads every
       * filing including the unsubmitted ones, which carry no assignments at
       * all, and those are the newest rows so they lead its list.
       */
      if (!routed.has(office.code)) continue

      const outsiders = CLEARANCE_OFFICES.filter((o) => !routed.has(o.code))
      if (outsiders.length === 0) continue

      const readable = await getAs(request, office.account, `/api/v1/applications/${candidate.id}`)
      expect(readable.status, `${office.code} can read its own ${candidate.tracking_id}`).toBe(200)

      for (const outsider of outsiders) {
        const res = await getAs(request, outsider.account, `/api/v1/applications/${candidate.id}`)
        expect(
          REFUSED,
          `${outsider.code} opened ${candidate.tracking_id}, a filing it was never routed to`,
        ).toContain(res.status)
      }

      // The coordinator's exemption, stated rather than assumed — and it is
      // what makes the refusals above a boundary rather than an outage.
      expect(
        full.status,
        `BPLO coordinates ${candidate.tracking_id} and must keep reading it`,
      ).toBe(200)

      probed = true
      break
    }

    expect(
      probed,
      `no filing on ${office.code}'s list excluded another office — nothing was proved for it`,
    ).toBe(true)
  }
})

/*
 * ── 3. Inspections ──────────────────────────────────────────────────────────
 */

test('each inspecting office sees only its own visits, and BPLO sees none', async ({ request }) => {
  /*
   * `inspects: false` on BPLO is not a footnote. It is the only office that
   * reads the papers without ever booking a visit — it coordinates the
   * clearances — so it holds no `inspection.manage` and the whole endpoint has
   * to be shut to it. That is asserted here rather than left as an absence,
   * because "BPLO sees an empty list" and "BPLO is refused" are different
   * products and only one of them is what the client described.
   */
  for (const office of OFFICES) {
    const { status, body } = await getAs(request, office.account, '/api/v1/inspections?per_page=50')

    if (!office.inspects) {
      expect(
        REFUSED,
        `${office.code} does not inspect, so the visit register is not its to list`,
      ).toContain(status)
      continue
    }

    expect(status, `${office.code} can list its own visits`).toBe(200)
    const rows = body.data as InspectionRow[]
    expect(rows.length, `${office.code} has visits to reason about`).toBeGreaterThan(0)
    const codes = [...new Set(rows.map((r) => r.department?.code ?? '(unrouted)'))].sort()
    expect(codes, `${office.account} sees ${office.code} visits and nothing else`).toEqual([
      office.code,
    ])
  }
})

test('a visit belongs to the office that booked it, by id as well as by list', async ({
  request,
}) => {
  /*
   * The same differential as the assignment matrix, on the other half of an
   * office's work. A visit carries the inspector's name, the date they attended
   * and their findings — an officer's working notes on somebody else's
   * premises.
   *
   * BPLO is included as a reader even though it cannot list visits: a route gate
   * that shuts the index and leaves the record open is precisely the shape of
   * leak this file exists to find.
   */
  const own = new Map<Office, number>()
  for (const office of OFFICES.filter((o) => o.inspects)) {
    const { body } = await getAs(request, office.account, '/api/v1/inspections?per_page=1')
    const rows = body.data as InspectionRow[]
    expect(rows[0]?.id, `${office.code} has a visit to be protective of`).toBeTruthy()
    own.set(office, rows[0].id)
  }

  for (const [office, id] of own) {
    const mine = await getAs(request, office.account, `/api/v1/inspections/${id}`)
    expect(mine.status, `${office.code} can open its OWN visit ${id}`).toBe(200)

    for (const other of OFFICES) {
      if (other.account === office.account) continue
      const theirs = await getAs(request, other.account, `/api/v1/inspections/${id}`)
      expect(
        REFUSED,
        `${other.code} opened ${office.code}'s visit ${id} by id`,
      ).toContain(theirs.status)
    }
  }
})

test('the review payload does not hand an office the findings it is refused by id', async ({
  request,
}) => {
  /*
   * ── A leak, and the one that is hardest to see from a screen ───────────────
   *
   * `GET /inspections/{id}` refuses another office's visit with 403 — the test
   * above proves it, and InspectionController::authorizeDepartment says so in
   * as many words: "a visit belongs to the office that booked it".
   *
   * `GET /assignments/{id}` is the endpoint the officer's review screen opens
   * on, and its `application.inspections` array carries EVERY office's visit on
   * the filing, in full: result, the inspector's name, and the findings text.
   * So the same session, on the same row, gets two different answers depending
   * on which door it uses — and the door the product itself opens is the one
   * that hands the data over.
   *
   * Measured on this register: as sanitary@biztrack.local,
   * `GET /inspections/5274` (an OBO visit) answers 403, while
   * `GET /assignments/9190` — CHO's own assignment, which that account is
   * entitled to — returns that same visit with the findings text and
   * "Teodoro Mangahas" named on it.
   *
   * ── What is and is not being asserted ──────────────────────────────────────
   *
   * Not "the payload must not mention other offices' visits". A filing's
   * PROGRESS is legitimately everyone's business — an officer needs to know
   * whether fire has been and whether the filing is waiting on them, and the
   * client asked for the progress display to be kept ("but the progress thingy
   * is cool, keep that").
   *
   * What is asserted is narrower and not arguable: the two fields that are the
   * inspecting officer's own record rather than the filing's state. `findings`
   * is free prose written by another office about another office's visit, and
   * `inspector` names a colleague who is not accountable to this reader. If a
   * fix lands, it should redact those two and leave status/result alone.
   */
  const leaks: string[] = []

  for (const office of OFFICES.filter((o) => o.inspects)) {
    const queue = await getAs(request, office.account, '/api/v1/assignments?per_page=15')
    for (const row of (queue.body.data as AssignmentRow[]) ?? []) {
      const full = await getAs(request, office.account, `/api/v1/assignments/${row.id}`)
      if (full.status !== 200) continue
      const detail = full.body.data as { application?: { inspections?: InspectionRow[] } }
      const visits = detail.application?.inspections ?? []

      for (const visit of visits) {
        if (visit.department?.code === office.code) continue
        // Only a visit this session is genuinely refused by id counts. A visit
        // it may read anyway is not a leak, it is consistency.
        const direct = await getAs(request, office.account, `/api/v1/inspections/${visit.id}`)
        if (!REFUSED.includes(direct.status)) continue
        if (visit.findings || visit.inspector) {
          leaks.push(
            `${office.account} was refused GET /inspections/${visit.id} (${direct.status}) but ` +
              `GET /assignments/${row.id} handed over that ${visit.department?.code} visit's ` +
              `${visit.findings ? 'findings' : ''}${visit.findings && visit.inspector ? ' and ' : ''}` +
              `${visit.inspector ? `inspector (${visit.inspector.name})` : ''}`,
          )
        }
      }
      // One filing per office is enough evidence; the search is expensive.
      if (visits.length > 1) break
    }
  }

  expect(
    leaks,
    'another office’s inspection findings and named inspector rode along on the review payload',
  ).toEqual([])
})

/*
 * ── 4. Permits and clearances ───────────────────────────────────────────────
 */

/**
 * The client's sentence, expressed as the table it is a claim about.
 *
 * "sanitary accounts can only see sanitary permits, and fire accounts can only
 * see fire" is a statement about `permit_types.issuing_department_id`: the FSIC
 * belongs to whoever issues FSIC. OFFICES already pairs each account with the
 * permit its department issues, so the rule is read off there rather than
 * retyped — the same reason the office-form boundary is drawn on that column
 * (ApplicationVisibility::readsOfficeSheet).
 *
 * BPLO is exempt and is skipped throughout this section. It issues the mayor's
 * permit and coordinates every other office's clearance, and holds
 * `application.view_any_office` to do it; the super admin likewise. Those two
 * are the documented cross-office readers, and treating them as leaks would be
 * asserting a product nobody asked for.
 */
const CLEARANCE_OFFICES = OFFICES.filter((o) => o.account !== 'bplo')

test('an office’s permit list holds only the clearance that office issues', async ({ request }) => {
  /*
   * ── A leak, and the one the client named directly ─────────────────────────
   *
   * PermitController::scopeToReader draws the boundary at the FILING — "permits
   * issued off filings their office was routed to" — not at the issuing office.
   * A filing routed to six offices produces six clearances, so every one of
   * those offices is handed all six.
   *
   * Measured on this register: sanitary@biztrack.local's `GET /permits` reports
   * 5,327 rows, and the first page of 200 contains 39 FSIC, 22 CEC, 22
   * OCCUPANCY, 21 ZONING and 38 BUSINESS certificates alongside its
   * own 39 SANITARY. That is the exact thing the client asked to be checked,
   * and the answer is that the account can see them.
   *
   * The positive half is asserted first and separately. A list that came back
   * empty, or an account that had quietly lost `permit.view_all`, would satisfy
   * "no foreign permits" perfectly and prove nothing at all.
   */
  for (const office of CLEARANCE_OFFICES) {
    const { status, body } = await getAs(request, office.account, '/api/v1/permits?per_page=200')
    expect(status, `${office.code} can list permits`).toBe(200)

    const rows = (body.data as PermitRow[]) ?? []
    expect(rows.length, `${office.code} has permits to reason about`).toBeGreaterThan(0)
    expect(
      rows.some((p) => p.permit_type.code === office.permit),
      `${office.code} can still see its own ${office.permit} certificates`,
    ).toBe(true)

    const foreign = [...new Set(rows.map((p) => p.permit_type.code))]
      .filter((code) => code !== office.permit)
      .sort()
    expect(
      foreign,
      `${office.account} was handed clearances issued by other offices — ` +
        `"${office.account} accounts can only see ${office.permit.toLowerCase()} permits"`,
    ).toEqual([])
  }
})

test('another office’s certificate is not readable by id, and its PDF is not downloadable', async ({
  request,
}) => {
  /*
   * ── The same leak, at the two routes that matter more than the list ───────
   *
   * A row filtered off a list is a nicety; a record and a rendered certificate
   * that answer to a typed URL are the actual exposure. `/permits/{id}/pdf`
   * prints the owner's full name and street address — more than the public
   * /verify endpoint gives out, and deliberately so.
   *
   * Measured on this register: sanitary@biztrack.local reaches
   * `GET /permits/8324` (MCF-2026-000297, a Fire Safety Inspection Certificate)
   * with 200 and a full certificate block naming the owner and the address, and
   * `GET /permits/8324/pdf` with 200 and 1.1 MB of PDF.
   *
   * Two offices, named because the client named them, but resolved through
   * OFFICES so the codes cannot drift out of step with the table.
   */
  test.slow() // rendering a certificate PDF is not a cheap request.

  const sanitary = OFFICES.find((o) => o.account === 'sanitary') as Office
  const fire = OFFICES.find((o) => o.account === 'fire') as Office

  for (const [reader, other] of [
    [sanitary, fire],
    [fire, sanitary],
  ] as const) {
    const { body } = await getAs(request, reader.account, '/api/v1/permits?per_page=200')
    const rows = (body.data as PermitRow[]) ?? []

    // The positive half: the office's own certificate opens, record and PDF
    // alike. Without this the refusals below would also pass on an account that
    // had simply been locked out of permits altogether.
    const mine = rows.find((p) => p.permit_type.code === reader.permit)
    expect(mine, `${reader.code} has one of its own ${reader.permit} permits to open`).toBeTruthy()
    const ownRecord = await getAs(request, reader.account, `/api/v1/permits/${mine!.id}`)
    expect(ownRecord.status, `${reader.code} can read its own ${mine!.permit_number}`).toBe(200)
    const ownPdf = await request.get(`/api/v1/permits/${mine!.id}/pdf`, {
      headers: bearer(reader.account),
    })
    expect(ownPdf.status(), `${reader.code} can print its own ${mine!.permit_number}`).toBe(200)

    /*
     * And now the other office's, sourced from BPLO.
     *
     * ── Why not from the reader's own list ────────────────────────────────────
     *
     * It used to be, on the reasoning that a row the product itself handed over
     * is not a fishing expedition. That reasoning was sound while the list was
     * leaking, and it quietly destroyed the test the moment the leak was fixed:
     * with the list correctly filtered there is no foreign row left to find,
     * `theirs` is undefined, and the whole thing skips. Green suite, and the two
     * routes that actually matter — the record and the rendered certificate —
     * never exercised again.
     *
     * That is the worse failure mode. A list is a nicety; permit ids are
     * sequential and `/permits/{id}/pdf` prints the owner's name and street
     * address, so the typed URL is the exposure. The id therefore comes from
     * BPLO, which reads every office's certificates by design, and is then
     * offered to a reader that must refuse it. The reader never needed to see
     * the row for the id to be guessable.
     */
    const { body: coordinator } = await getAs(request, 'bplo', '/api/v1/permits?per_page=200')
    const theirs = ((coordinator.data as PermitRow[]) ?? []).find(
      (p) => p.permit_type.code === other.permit,
    )
    expect(
      theirs,
      `BPLO can see a ${other.permit} certificate for ${reader.code} to be refused — ` +
        'without one this test proves nothing',
    ).toBeTruthy()

    const record = await getAs(request, reader.account, `/api/v1/permits/${theirs!.id}`)
    expect(
      REFUSED,
      `${reader.account} read ${other.code}'s certificate ${theirs!.permit_number} by id`,
    ).toContain(record.status)

    const pdf = await request.get(`/api/v1/permits/${theirs!.id}/pdf`, {
      headers: bearer(reader.account),
    })
    expect(
      REFUSED,
      `${reader.account} downloaded ${other.code}'s certificate ${theirs!.permit_number} as a PDF — ` +
        'that document carries the owner’s name and street address',
    ).toContain(pdf.status())
  }
})

/*
 * ── 5. The office questionnaire on a shared filing ──────────────────────────
 */

test('an office reads its own clearance sheet on a shared filing and no other', async ({
  request,
}) => {
  /*
   * The half of the boundary that IS drawn on `issuing_department_id`, kept
   * under test so the permit findings above cannot be read as "office scoping
   * does not exist here". It does, on the office forms, and it was fought for:
   * a seven-office filing used to hand the CHO officer CENRO's `owner_birthday`
   * — a date of birth on a screen that prints an RA 10173 consent notice eight
   * sections earlier (checklist item 111).
   *
   * Asserted through the assignment payload, which is what the review screen
   * actually reads. The dedicated `/office-forms` endpoint has its own filter;
   * this one did not, and the two disagreeing is how the bug survived a fix.
   */
  const checked: string[] = []

  for (const office of CLEARANCE_OFFICES) {
    const queue = await getAs(request, office.account, '/api/v1/assignments?per_page=10')
    for (const row of (queue.body.data as AssignmentRow[]) ?? []) {
      const full = await getAs(request, office.account, `/api/v1/assignments/${row.id}`)
      if (full.status !== 200) continue
      const detail = full.body.data as {
        application?: { office_forms?: { permit_type_code: string }[] }
      }
      const sheets = detail.application?.office_forms ?? []
      if (sheets.length === 0) continue

      const codes = [...new Set(sheets.map((s) => s.permit_type_code))].sort()
      expect(
        codes,
        `${office.account} was handed another office's questionnaire on assignment ${row.id}`,
      ).toEqual([office.permit])
      checked.push(office.code)
      break
    }
  }

  // Every assertion above is inside a conditional, so the count is the thing
  // that keeps this test from passing by never running.
  expect(
    checked.length,
    'no office had a clearance sheet to check — nothing was proved',
  ).toBeGreaterThan(0)
})

/*
 * ── 6. The super admin ──────────────────────────────────────────────────────
 */

test('the super admin has genuinely lost review and inspection at the API', async ({ request }) => {
  /*
   * The client's ruling was that Messages, Track, Inspections and Other
   * Requirements are "not his role to do those things", and `admin` was stripped
   * of `application.review`, `inspection.manage` and `message.participate` to
   * make it so.
   *
   * Taking an entry off the rail is not taking a capability away — the route is
   * still routed and the endpoint is still an endpoint — so this is asserted
   * where it either holds or does not. `GET /assignments` and the approve/return
   * writes sit in the SAME `permission:application.review` route group, so a 403
   * on the read is a 403 on the whole group; nothing here writes.
   *
   * The positive half matters more than usual for this account. The super admin
   * is the auditor and must not have been quietly turned into a locked-out user:
   * the register and the audit trail have to stay open, or the fix went too far.
   */
  const shut: [string, string][] = [
    ['/api/v1/assignments', 'the officer queue'],
    ['/api/v1/inspections', 'the visit register'],
    ['/api/v1/message-threads', 'the message inbox'],
  ]
  for (const [url, what] of shut) {
    const res = await getAs(request, 'admin', url)
    expect(REFUSED, `the super admin still reaches ${what} at ${url}`).toContain(res.status)
  }

  // By id, too. A group that shuts the list and leaves the record open would
  // pass every assertion above.
  const zoningQueue = await getAs(request, 'zoning', '/api/v1/assignments?per_page=1')
  const assignmentId = (zoningQueue.body.data as AssignmentRow[])[0]?.id
  expect(assignmentId, 'an office queue supplies a row to try the super admin against').toBeTruthy()
  const oneAssignment = await getAs(request, 'admin', `/api/v1/assignments/${assignmentId}`)
  expect(
    REFUSED,
    `the super admin opened assignment ${assignmentId} by id`,
  ).toContain(oneAssignment.status)

  const zoningVisits = await getAs(request, 'zoning', '/api/v1/inspections?per_page=1')
  const inspectionId = (zoningVisits.body.data as InspectionRow[])[0]?.id
  expect(inspectionId, 'an office supplies a visit to try the super admin against').toBeTruthy()
  const oneVisit = await getAs(request, 'admin', `/api/v1/inspections/${inspectionId}`)
  expect(REFUSED, `the super admin opened visit ${inspectionId} by id`).toContain(oneVisit.status)

  // And a filing's message thread, which is `message.participate` rather than
  // review — a separate permission the same ruling took away.
  const zoningApps = await getAs(request, 'zoning', '/api/v1/applications?per_page=1')
  const applicationId = (zoningApps.body.data as { id: number }[])[0]?.id
  expect(applicationId, 'an office supplies a filing to try the super admin against').toBeTruthy()
  const thread = await getAs(request, 'admin', `/api/v1/applications/${applicationId}/messages`)
  expect(
    REFUSED,
    `the super admin read the conversation on filing ${applicationId}`,
  ).toContain(thread.status)

  // The auditor is still an auditor.
  const register = await getAs(request, 'admin', '/api/v1/applications?per_page=1')
  expect(register.status, 'the super admin can still read the register').toBe(200)
  const audit = await getAs(request, 'admin', '/api/v1/admin/audit-logs?per_page=1')
  expect(audit.status, 'the super admin can still read the audit trail').toBe(200)
})

test('the super admin’s rail does not offer the screens that are not his role', async ({
  browser,
}) => {
  /*
   * The screen half of the test above. Both are needed and neither substitutes
   * for the other: a rail entry pointing at a 403 is a trap, and a capability
   * revoked only in the rail is not revoked.
   *
   * Scoped to the <aside> for the reason analytics.spec.ts gives — the mobile
   * tab bar carries the same labels and would trip strict mode.
   */
  const context = await browser.newContext({ storageState: sessionFor('admin') })
  const page = await context.newPage()
  try {
    await page.goto('/staff/dashboard')
    const rail = page.locator('aside')

    /*
     * Present first, and this line is doing more work than it looks.
     *
     * A session that has quietly died bounces this page to /staff/login, where
     * there is no rail at all — and every "the rail does not offer X" assertion
     * below would then pass on an empty page. Proving one entry IS there is what
     * separates "the super admin cannot reach Track" from "nobody is signed in".
     */
    await expect(rail.getByRole('link', { name: 'Officer Assignment' })).toBeVisible()

    for (const label of ['Track', 'Messages', 'Other Requirements']) {
      await expect(
        rail.getByRole('link', { name: label, exact: true }),
        `the rail still offers the super admin ${label}`,
      ).toHaveCount(0)
    }

    /*
     * And the home screen does not offer the card either. The rail is one route
     * in; DashboardPage draws its own tiles from the same permissions, and a
     * tile the guard would bounce is a dead end with the client's name on it.
     */
    await expect(
      page.getByRole('link', { name: 'Application Verification' }),
      'the super admin’s home screen still offers an Application Verification card',
    ).toHaveCount(0)

    /*
     * And typing the address does not get him there either — RequirePermission
     * has to bounce him off /staff/queue.
     *
     * Asserted on the URL rather than on the queue's heading, and that is not a
     * softening: the staff HOME screen is itself titled "Application
     * Verification" (DashboardPage's <h1>), so a heading query passes on the
     * screen he is bounced TO and would report a leak that is not there.
     */
    await page.goto('/staff/queue')
    await expect(
      page,
      'the super admin stayed on Application Verification by typing the address',
    ).not.toHaveURL(/\/staff\/queue$/)
  } finally {
    await context.close()
  }
})

/*
 * ── 7. Adjacent: the business behind the filing ─────────────────────────────
 */

test('a business is closed to an office that never saw one of its filings', async ({ request }) => {
  /*
   * A filing's boundary is worth little if the business record behind it is
   * open, because that record carries the owner, the address and the whole
   * trading history — and `/businesses/{id}/prefill` hands back the answers to
   * an application form.
   *
   * Same construction as the filing test: BPLO names who is on the filing, and
   * every office it does not name tries both doors.
   */
  for (const office of OFFICES) {
    const mine = await getAs(request, office.account, '/api/v1/applications?per_page=40')
    let probed = false

    for (const candidate of (mine.body.data as { id: number; tracking_id: string }[]) ?? []) {
      const full = await getAs(request, 'bplo', `/api/v1/applications/${candidate.id}`)
      if (full.status !== 200) continue
      const detail = full.body.data as {
        assignments: AssignmentRow[]
        business: { id: number; name: string } | null
      }
      if (!detail.business) continue
      const routed = new Set(detail.assignments.map((a) => a.department?.code))
      // Same two guards as the filing test above, and for the same reasons:
      // the reader has to be genuinely routed here, and BPLO's cross-office
      // read is a documented exemption rather than an outsider's leak.
      if (!routed.has(office.code)) continue
      const outsiders = CLEARANCE_OFFICES.filter((o) => !routed.has(o.code))
      if (outsiders.length === 0) continue

      const readable = await getAs(request, office.account, `/api/v1/businesses/${detail.business.id}`)
      expect(
        readable.status,
        `${office.code} can read ${detail.business.name}, whose filing it is working`,
      ).toBe(200)

      for (const outsider of outsiders) {
        for (const url of [
          `/api/v1/businesses/${detail.business.id}`,
          `/api/v1/businesses/${detail.business.id}/prefill`,
        ]) {
          const res = await getAs(request, outsider.account, url)
          expect(
            REFUSED,
            `${outsider.code} reached ${detail.business.name} at ${url}`,
          ).toContain(res.status)
        }
      }
      probed = true
      break
    }

    expect(probed, `nothing was proved for ${office.code}`).toBe(true)
  }
})

/*
 * ── 8. Adjacent: the conversation ───────────────────────────────────────────
 */

test('offices on one filing do not read each other’s conversation with the applicant', async ({
  request,
}) => {
  /*
   * The finest of the boundaries here, and the one most easily lost: two offices
   * are legitimately on the same filing and both legitimately read its message
   * thread, so the only thing separating them is which MESSAGES they are shown.
   *
   * MessageController::scopeMessagesToReader draws that line. This finds a
   * filing where two offices have both written, and asserts each sees its own
   * side and not the other's — the positive and the negative on the same row,
   * which is the only way this claim can be made honestly.
   *
   * ── The APPLICANT'S messages are not anybody's private side ────────────────
   *
   * This is the trap in the middle of this test, and it produced a false leak
   * report on the first pass. A thread holds three kinds of message: CHO's,
   * CENRO's, and the applicant's own — and the applicant's are addressed to the
   * City, so every office on the filing is meant to read them. Comparing raw id
   * sets therefore "finds" a leak on the first filing where the owner said
   * hello, and the accusation is against the one message on the thread nobody
   * was hiding.
   *
   * So the applicant's own messages are excluded, identified by the applicant
   * id on the filing rather than by the absence of a department on the sender —
   * MessageResource does not publish the sender's office, and inferring one
   * office's staff from a missing field is how the first pass went wrong.
   *
   * Skips when the register holds no such filing: that is a fixture gap, not a
   * defect, and on a fresh seed there may be no officer messages at all.
   */
  interface MessageRow {
    id: number
    body: string
    sender: { id: number; name: string } | null
  }

  /** Message ids on this filing that were written by an OFFICE, not the owner. */
  async function officerMessages(account: Account, application: number, applicant: number) {
    const res = await getAs(request, account, `/api/v1/applications/${application}/messages`)
    if (res.status !== 200) return null
    return new Set(
      ((res.body.data as MessageRow[]) ?? [])
        .filter((m) => m.sender?.id !== applicant)
        .map((m) => m.id),
    )
  }

  const inbox = new Map<Office, Set<number>>()
  for (const office of CLEARANCE_OFFICES) {
    const threads = await getAs(request, office.account, '/api/v1/message-threads?per_page=40')
    if (threads.status !== 200) continue
    const rows = (threads.body.data as { application_id: number }[]) ?? []
    inbox.set(office, new Set(rows.map((r) => r.application_id)))
  }

  // A filing both offices can open, on which both have written something.
  let evidence: {
    application: number
    a: Office
    b: Office
    idsA: Set<number>
    idsB: Set<number>
  } | null = null
  const offices = [...inbox.keys()]
  outer: for (let i = 0; i < offices.length; i++) {
    for (let j = i + 1; j < offices.length; j++) {
      const a = offices[i]
      const b = offices[j]
      const shared = [...(inbox.get(a) as Set<number>)].filter((id) =>
        (inbox.get(b) as Set<number>).has(id),
      )
      for (const application of shared.slice(0, 12)) {
        const filing = await getAs(request, 'bplo', `/api/v1/applications/${application}`)
        if (filing.status !== 200) continue
        const applicant = (filing.body.data as { applicant: { id: number } }).applicant.id

        const idsA = await officerMessages(a.account, application, applicant)
        const idsB = await officerMessages(b.account, application, applicant)
        if (!idsA || !idsB) continue

        // Each must have written something, or there is nothing here to be
        // right or wrong about.
        if (idsA.size > 0 && idsB.size > 0) {
          evidence = { application, a, b, idsA, idsB }
          break outer
        }
      }
    }
  }

  test.skip(
    evidence === null,
    'no filing on this register carries messages from two different offices',
  )

  const { application, a, b, idsA, idsB } = evidence as NonNullable<typeof evidence>

  /*
   * The coordinator holds the whole thread, and neither office does. BPLO's
   * `application.view_any_office` is what makes this a meaningful contrast
   * rather than a restatement of "the thread is short" — the messages exist,
   * somebody can read all of them, and it is not either of these two.
   */
  const coordinator = await getAs(request, 'bplo', `/api/v1/applications/${application}/messages`)
  expect(coordinator.status, 'BPLO coordinates and reads the whole thread').toBe(200)
  const all = new Set(((coordinator.body.data as MessageRow[]) ?? []).map((m) => m.id))
  expect(all.size, 'the coordinator sees more than either office').toBeGreaterThan(idsA.size)
  expect(all.size, 'the coordinator sees more than either office').toBeGreaterThan(idsB.size)

  for (const [reader, own, theirs] of [
    [a, idsA, idsB],
    [b, idsB, idsA],
  ] as const) {
    expect(
      own.size,
      `${reader.code} still reads its own side of filing ${application}`,
    ).toBeGreaterThan(0)
    const bled = [...theirs].filter((id) => own.has(id))
    expect(
      bled,
      `${reader.code} read messages the other office wrote on filing ${application}`,
    ).toEqual([])
  }
})
