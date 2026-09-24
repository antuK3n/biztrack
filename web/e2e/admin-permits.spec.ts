import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * PERMITS — every issued certificate as one table (issue #103).
 *
 * Two halves, deliberately, because the screen makes two different kinds of
 * claim and only one of them can be proved against a fixture.
 *
 *  1. The table, stubbed. A narrowing assertion has to know exactly which rows
 *     exist, and "searching found one row" means nothing against a register
 *     whose contents move. Stubbing also pins the narrowing to the SERVER: the
 *     stub filters the way PermitController::index does, so a page that sliced
 *     the twenty-five rows it already held would fail here rather than pass and
 *     then find nothing on page two.
 *
 *  2. View, unstubbed. The certificate is a real dompdf render behind a Bearer
 *     token, and what has to be proved is that a tab opens and stays open —
 *     the fetch, the object URL, the handoff into a window opened before the
 *     await, and the popup blocker leaving it alone. Every one of those is a
 *     browser behaviour that `tsc` is blind to: a `viewPdf` that resolved and
 *     opened nothing would typecheck perfectly.
 *
 * There is no revoke test because there is no revoke. See the head of
 * web/src/pages/admin/PermitsPage.tsx for why that is a decision rather than an
 * omission — and if a Revoke control ever appears on this screen without the
 * status, the audit entry and the notification behind it, this file is where it
 * should have been caught.
 */

/*
 * The BPLO session, not the super admin's — and the reason is the gate.
 *
 * This screen is gated on `permit.view_all`, which BPLO, the five clearance
 * offices AND the super admin all hold (RbacSeeder). BPLO is one of the two
 * seats PermitController lets read the WHOLE register — PermitOfficeScopingTest
 * asserts exactly that for `bplo@` and `admin@` together — so it exercises the
 * same unscoped table the super admin sees, and it is the session that proves
 * the gate is the permission rather than the role.
 *
 * It is also the session that currently mints: `auth.setup.ts` signs the super
 * admin in at `portal: 'staff'`, and the account is being moved to a third
 * `admin` portal on the auth side as this is written, so an admin storage state
 * is 409ing. Nothing about this screen depends on which of the two it is; if
 * the admin session is the one wanted later, swapping the account here is the
 * whole change.
 */
test.use({ storageState: sessionFor('bplo') })

/**
 * The register rows, as `GET /permits?detail=1` answers them.
 *
 * `PermitRegisterResource` — `Permit` plus the BAN that leads the table, the
 * certificate face, the record of issuance and the office sheet. The three
 * rows are chosen to cover the three shapes the table has to draw: an office
 * with no sheet at all, an office with one and answers on it, and a permit
 * whose business was removed from the register.
 */
const face = (over: Record<string, string | null> = {}) => ({
  business_name: null,
  trade_name: null,
  owner_name: null,
  address: null,
  barangay: null,
  city: null,
  line_of_business: null,
  ...over,
})

const PERMITS = [
  {
    /*
     * The Mayor's Permit — the ONE type of the six with no office sheet.
     * `office_form: null` is the server saying "this office asks for no
     * form", which the table has to draw differently from an unanswered one.
     */
    ban: 'BP-2026-0001',
    id: 901,
    permit_number: 'MCB-2026-000001',
    status: 'active',
    status_label: 'Active',
    valid_from: '2026-01-02',
    valid_until: '2026-12-31',
    days_until_expiry: 105,
    permit_type: { code: 'BUSINESS', name: "Mayor's Permit" },
    business: { id: 21, name: 'Aling Nena Sari-Sari Store' },
    application: { id: 501, tracking_id: 'BIZ-2026-00473' },
    verify_url: 'http://localhost/verify/MCB-2026-000001',
    face: face({
      business_name: 'Aling Nena Sari-Sari Store',
      trade_name: 'Aling Nena',
      owner_name: 'Nena Makiling',
      address: '12 Gen. Luna St.',
      barangay: 'Longos',
      city: 'Malabon',
      line_of_business: 'Retail sale in non-specialized stores',
    }),
    issued_at: '2026-01-02T08:00:00.000000Z',
    issued_by: 'Liza Reyes',
    prior_permit_number: null,
    revoked_at: null,
    revoked_reason: null,
    office_form: null,
  },
  {
    /*
     * A sanitary permit with its sheet answered. This row is what "pati mga
     * finill outan kada permit" means: the CHO form's four boxes, carrying the
     * labels the applicant saw on the paper.
     */
    ban: 'BP-2026-0002',
    id: 902,
    permit_number: 'MCS-2025-000770',
    status: 'expired',
    status_label: 'Expired',
    valid_from: '2025-01-02',
    valid_until: '2025-12-31',
    days_until_expiry: -260,
    permit_type: { code: 'SANITARY', name: 'Sanitary Permit' },
    business: { id: 22, name: 'RxCare Pharmacy' },
    application: { id: 502, tracking_id: 'BIZ-2025-00311' },
    verify_url: 'http://localhost/verify/MCS-2025-000770',
    face: face({
      business_name: 'RxCare Pharmacy',
      owner_name: 'Juan Ramos',
      address: '88 Rizal Ave.',
      barangay: 'Catmon',
      city: 'Malabon',
    }),
    issued_at: '2025-01-02T08:00:00.000000Z',
    issued_by: 'Carlos Dizon',
    prior_permit_number: 'MCS-2024-000512',
    revoked_at: null,
    revoked_reason: null,
    office_form: {
      application_date: '2025-01-02',
      application_type: 'Renewal',
      sanitary_classification: 'Food Establishment',
      workers_requiring_health_certs: '4',
      water_source: 'Level III (Waterworks)',
    },
  },
  {
    /*
     * A permit whose business was removed from the register.
     *
     * Business soft-deletes and its certificates stay, so the payload answers
     * `business: null` on 139 rows of the live data (AGENTS.md §11). The
     * `Permit` type claims that relation is non-nullable, which is exactly why
     * this row is in the fixture: a cell written as `permit.business.name`
     * typechecks and then throws on this row.
     *
     * Its BAN is null for the same reason, and a zoning sheet sits on it so
     * that the table is asked to draw an office's answers on a row whose
     * business is gone.
     */
    ban: null,
    id: 903,
    permit_number: 'MCZ-2026-000014',
    status: 'superseded',
    status_label: 'Superseded',
    valid_from: '2026-02-01',
    valid_until: '2027-01-31',
    days_until_expiry: 136,
    permit_type: { code: 'ZONING', name: 'Zoning Clearance' },
    business: null,
    application: { id: 503, tracking_id: 'BIZ-2026-00490' },
    verify_url: 'http://localhost/verify/MCZ-2026-000014',
    face: face({ city: 'Malabon' }),
    issued_at: '2026-02-01T08:00:00.000000Z',
    issued_by: null,
    prior_permit_number: null,
    revoked_at: null,
    revoked_reason: null,
    office_form: {
      application_date: '2026-02-01',
      application_type: 'New Locational Clearance',
      zoning_project_description: 'Retail store, ground floor',
      total_floor_area_sqm: '42',
    },
  },
]

/** Every query string the screen sent, so a narrowing can be pinned to the server. */
let asked: string[]

test.describe('the permit register table', () => {
  test.beforeEach(async ({ page }) => {
    asked = []

    await page.route('**/api/v1/permits?*', async (route) => {
      const url = new URL(route.request().url())
      asked.push(url.search)

      /*
       * Narrowed the way the endpoint narrows it — permit number, business name
       * and tracking ID for `q`, an exact match for `status`. If the stub
       * returned every row regardless, the search assertions would pass whether
       * the page asked the server or filtered what it held, which is the one
       * defect worth catching here.
       */
      const q = (url.searchParams.get('q') ?? '').toLowerCase()
      const status = url.searchParams.get('status') ?? ''
      const office = url.searchParams.get('permit_type') ?? ''
      let rows = PERMITS.filter((p) => {
        const haystack =
          `${p.permit_number} ${p.ban ?? ''} ${p.business?.name ?? ''} ${p.face.owner_name ?? ''} ${p.application.tracking_id} ${p.permit_type.name}`.toLowerCase()
        return (
          (!q || haystack.includes(q)) &&
          (!status || p.status === status) &&
          (!office || p.permit_type.code === office)
        )
      })

      /*
       * Ordered the way the SERVER orders it. Sorting moved off the browser —
       * the page sends `sort` and `dir` and renders what comes back — so a
       * stub that ignored them would let a test pass whether the page asked
       * for the order or produced it itself, which is the defect worth
       * catching.
       */
      const sort = url.searchParams.get('sort')
      if (sort) {
        const dir = url.searchParams.get('dir') === 'asc' ? 1 : -1
        const key = (p: (typeof PERMITS)[number]) =>
          sort === 'ban' ? (p.ban ?? '') : sort === 'business' ? (p.business?.name ?? '') : p.permit_number
        rows = [...rows].sort((a, b) => dir * key(a).localeCompare(key(b)))
      }

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: rows,
          meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length },
        }),
      })
    })

    await page.goto('/staff/admin/permits')
    await expect(page.getByRole('heading', { name: 'Permits', level: 1 })).toBeVisible()
  })

  test('the table leads with the BAN and carries the whole record beside it', async ({ page }) => {
    /*
     * The client's ordering, asserted as an ordering rather than as a
     * presence: "mauuna ang BAN then the rest of info na meron sa permit".
     * A column that is merely PRESENT somewhere in forty-five satisfies a
     * `toBeVisible` and misses the instruction entirely.
     */
    const headers = page.locator('thead th')
    await expect(headers.first()).toContainText('BAN')

    // The identifiers, then the face, then the record — in that order.
    const order = [
      'BAN',
      'Permit No.',
      'Tracking ID',
      'Permit / Certificate',
      'Office',
      'Business',
      'Trade Name',
      'Owner',
      'Address',
      'Barangay',
      'City',
      'Line of Business',
      'Status',
      'Valid from',
      'Valid until',
      'Days to expiry',
      'Issued on',
      'Issued by',
      'Replaces',
      'Revoked on',
      'Revocation reason',
      'Date of Application',
    ]
    for (const [i, label] of order.entries()) {
      await expect(headers.nth(i)).toContainText(label)
    }

    const rows = page.locator('tbody tr')
    await expect(rows).toHaveCount(PERMITS.length)

    const first = rows.first()
    await expect(first).toContainText('BP-2026-0001')
    await expect(first).toContainText('MCB-2026-000001')
    await expect(first).toContainText('BIZ-2026-00473')
    await expect(first).toContainText('Nena Makiling')
    await expect(first).toContainText('Longos')
    await expect(first).toContainText('Liza Reyes')
    // Never colour alone: the state is a word in the row, not just a tint.
    await expect(first).toContainText('Active')

    // The removed business says so rather than blanking or throwing, and its
    // missing BAN prints as a dash rather than an empty cell.
    const orphan = rows.filter({ hasText: 'MCZ-2026-000014' })
    await expect(orphan).toContainText('Business removed from register')

    // Both numbers named, and the noun says which register is being counted.
    await expect(page.getByText('Showing 3 of 3 issued permits')).toBeVisible()
  })

  test('the face it prints is the snapshot, not the register as it reads today', async ({ page }) => {
    /*
     * A permit is a frozen document. The owner, address and barangay come from
     * `permits.issued_details` through PermitFace, so a business that has since
     * moved does not rewrite a certificate it already holds. The fixture's
     * third row makes the point the hard way: its business is gone from the
     * register entirely and the face still carries what was signed.
     */
    const orphan = page.locator('tbody tr').filter({ hasText: 'MCZ-2026-000014' })
    await expect(orphan).toContainText('Malabon')
  })

  test('each office’s own form is a set of columns, named as the applicant saw them', async ({
    page,
  }) => {
    /*
     * "pati mga finill outan kada offices permit". The headings are the labels
     * from `OfficeFormStep.tsx` verbatim — an administrator checking a sheet
     * against the paper is comparing two documents, and renaming the boxes
     * would make that a translation exercise.
     */
    for (const label of [
      'Sanitary Classification',
      'No. of Workers Requiring Health Certificates',
      'Water Source',
      'Project Description',
      'Floor Area to be / being Utilized',
      'Certificate Applied For',
      'Building Permit No.',
      'Owner’s Address',
    ]) {
      await expect(page.getByRole('columnheader', { name: new RegExp(label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i') })).toBeVisible()
    }

    // Each column says whose form it is, so forty-five headings do not read as
    // one undifferentiated row.
    await expect(page.getByRole('columnheader', { name: /Sanitary Classification/i })).toContainText(
      'CHO form',
    )

    // And the answers are actually in the row.
    const sanitary = page.locator('tbody tr').filter({ hasText: 'MCS-2025-000770' })
    await expect(sanitary).toContainText('Food Establishment')
    await expect(sanitary).toContainText('Level III (Waterworks)')
  })

  test('one office’s answers never appear under another office’s heading', async ({ page }) => {
    /*
     * The sheets SHARE field names — `application_type` is on four of the five
     * — and every column reads the same `office_form` object. Without a guard
     * the zoning row's "Nature of Application" would also fill the CHO and
     * CENRO headings, which is not a blank cell but a false one: it would say
     * the health office asked a question on a filing it had no sheet for.
     */
    const zoning = page.locator('tbody tr').filter({ hasText: 'MCZ-2026-000014' })
    const headers = page.locator('thead th')

    const indexOf = async (label: string, office: string) => {
      const n = await headers.count()
      for (let i = 0; i < n; i++) {
        const text = (await headers.nth(i).innerText()).replace(/\s+/g, ' ')
        if (text.includes(label) && text.includes(office)) return i
      }
      throw new Error(`no ${office} column named ${label}`)
    }

    // Its own sheet is filled…
    const mine = await indexOf('NATURE OF APPLICATION', 'CPDD')
    await expect(zoning.locator('td').nth(mine)).toHaveText('New Locational Clearance')

    // …and the health office's identically-named box is empty on this row.
    const theirs = await indexOf('NATURE OF APPLICATION', 'CHO')
    await expect(zoning.locator('td').nth(theirs)).toHaveText('—')
  })

  test('picking an office drops the other offices’ columns, and asks the server', async ({
    page,
  }) => {
    /*
     * The other half of the ask — "naka depende kung anong office ito". The
     * narrowing is the server's, not the browser's: filtering rows already in
     * hand would find nothing past the first page of a register that holds
     * thousands.
     */
    const wide = await page.locator('thead th').count()

    await page.locator('select').first().selectOption('SANITARY')

    await expect.poll(() => asked.at(-1)).toContain('permit_type=SANITARY')
    await expect(page.locator('tbody tr')).toHaveCount(1)
    await expect(page.getByRole('columnheader', { name: /Sanitary Classification/i })).toBeVisible()
    // The other four sheets are gone.
    await expect(page.getByRole('columnheader', { name: /Floor Area/i })).toHaveCount(0)
    await expect(page.getByRole('columnheader', { name: /Certificate Applied For/i })).toHaveCount(0)
    expect(await page.locator('thead th').count()).toBeLessThan(wide)

    // The count line names the office it narrowed to.
    await expect(page.getByText(/issued by CHO/)).toBeVisible()
  })

  test('searching narrows on the server, by every identifier the label names', async ({ page }) => {
    const rows = page.locator('tbody tr')
    const search = page.getByRole('searchbox', { name: 'Search permits by permit number, BAN, business name, owner, tracking ID or permit type' })

    await search.fill('rxcare')
    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('MCS-2025-000770')
    // The narrowing has to be a query the server answered. Filtering the rows
    // already loaded would find nothing past the first page.
    await expect.poll(() => asked.at(-1)).toContain('q=rxcare')
    await expect(page.getByText('Showing 1 of 1 issued permits matching your search')).toBeVisible()

    await search.fill('MCB-2026-000001')
    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('Aling Nena Sari-Sari Store')

    /*
     * The BAN and the owner. Both are columns on this table now, and a box
     * that shows a value it will not match makes a correct query look like
     * missing data — which is why the label names all six.
     */
    await search.fill('BP-2026-0002')
    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('MCS-2025-000770')

    await search.fill('Makiling')
    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('MCB-2026-000001')

    // And the tracking ID, which names the FILING rather than the permit.
    await search.fill('BIZ-2026-00490')
    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('MCZ-2026-000014')
  })

  test('the status pills filter on the server and are counted there', async ({ page }) => {
    await page.getByRole('button', { name: 'Active', exact: true }).click()

    await expect(page.locator('tbody tr')).toHaveCount(1)
    await expect.poll(() => asked.at(-1)).toContain('status=active')
    /*
     * The count line reads from meta.total, so it is the count of what was
     * asked for rather than of the page. A total assembled in the browser is
     * always ≤ per_page and always looks plausible, which is the worst kind of
     * wrong number.
     */
    await expect(page.getByText('Showing 1 of 1 active permits')).toBeVisible()

    // FilterPills marks the open filter with aria-pressed, so "what am I
    // looking at" is a fact a screen reader can get at, not a colour.
    await expect(page.getByRole('button', { name: 'Active', exact: true })).toHaveAttribute(
      'aria-pressed',
      'true',
    )

    await page.getByRole('button', { name: 'All', exact: true }).click()
    await expect(page.locator('tbody tr')).toHaveCount(PERMITS.length)
  })

  test('columns sort on the server, across the whole register', async ({ page }) => {
    /*
     * This used to sort in the browser over the 25 rows in hand, because
     * /permits accepted no ordering, and the footer had to say so. The
     * endpoint takes `sort` and `dir` against its own whitelist now, so the
     * assertion is that the page ASKS — a sort the browser performed would
     * order these three rows identically and prove nothing.
     */
    const heading = page.getByRole('button', { name: /^BAN/ })

    await heading.click()
    await expect(page.locator('th', { has: heading })).toHaveAttribute('aria-sort', 'ascending')
    await expect.poll(() => asked.at(-1)).toContain('sort=ban')
    await expect.poll(() => asked.at(-1)).toContain('dir=asc')

    await heading.click()
    await expect(page.locator('th', { has: heading })).toHaveAttribute('aria-sort', 'descending')
    await expect.poll(() => asked.at(-1)).toContain('dir=desc')

    // The footer names the sort's reach, and the reach is now the register.
    await expect(
      page.getByText('Sorted by BAN, descending, across the whole register.'),
    ).toBeVisible()

    // A third press puts the register back in the order its totals are counted in.
    await heading.click()
    await expect(page.locator('th', { has: heading })).toHaveAttribute('aria-sort', 'none')
    await expect(page.getByText('Ordered by issue date, newest first.')).toBeVisible()
  })

  test('a column the server cannot order by is not offered as one', async ({ page }) => {
    /*
     * An office-sheet answer lives in a JSON column no index reaches, and
     * `days_until_expiry` is computed per row rather than stored. Their
     * headings are plain text: a pressable header that did nothing would be a
     * control that appears to work, and one that silently sorted by a
     * DIFFERENT column would be worse.
     */
    for (const label of ['Sanitary Classification', 'Days to expiry', 'Office', 'Owner']) {
      const header = page.getByRole('columnheader', { name: new RegExp(label, 'i') }).first()
      await expect(header.getByRole('button')).toHaveCount(0)
      // And it does not claim to be sortable to a screen reader either.
      await expect(header).not.toHaveAttribute('aria-sort', /.*/)
    }
  })

  test('every View button names the permit it opens', async ({ page }) => {
    /*
     * Three buttons reading "View" are three identical stops for a screen
     * reader (AGENTS.md §6.2). Twenty-five of them is the real case. The
     * distinction lives in the accessible name, not the visible label.
     */
    for (const permit of PERMITS) {
      await expect(
        page.getByRole('button', { name: `View certificate ${permit.permit_number}` }),
      ).toBeVisible()
    }
  })
})

test.describe('viewing a certificate', () => {
  test('View opens the issued PDF in a tab, and keeps it open', async ({ page, context }) => {
    /*
     * Unstubbed: the point is the real certificate behind the real token.
     * Read-only from the register's point of view — it renders a permit that
     * already exists and creates no row. (`GET /permits/{id}/pdf` does cache
     * the rendered file and stamp `pdf_path`; that is the endpoint's existing
     * behaviour, not something this screen introduces.)
     */
    await page.goto('/staff/admin/permits')
    await expect(page.getByRole('heading', { name: 'Permits', level: 1 })).toBeVisible()

    /*
     * A row has to exist before anything here means something. Asserted rather
     * than skipped: BPLO reads the whole register on every stack this runs
     * against, so an empty table is a broken fixture — and a test that quietly
     * skips itself reports green for a screen it never looked at.
     */
    const view = page.getByRole('button', { name: /^View certificate / }).first()
    await expect(view).toBeVisible({ timeout: 30_000 })

    /*
     * ── What is asserted, and why not the blob URL ─────────────────────────
     *
     * The obvious assertion — that the tab ends up on a `blob:` URL — does not
     * hold in Chromium under Playwright: a popup opened as about:blank and
     * then moved by `location.replace` to a blob the OPENER created keeps
     * reporting about:blank to the driver. payment-receipt.spec.ts and
     * document-actions.spec.ts both met this and settled on the two signals
     * that actually separate success from failure in this code path:
     *
     *   - a popup opened at all, and
     *   - no error was announced.
     *
     * The second is stronger than it looks. `view()` closes the tab and writes
     * the error the moment the fetch throws, so a silent failure cannot leave
     * both a live tab and a quiet page.
     */
    const popup = context.waitForEvent('page')
    /*
     * And the response is waited for explicitly, which the two older specs do
     * not do. Without it the popup arrives synchronously — `window.open` runs
     * before the first await — so every assertion below could be made while the
     * certificate was still rendering, and a 403 or a dompdf crash landing a
     * moment later would leave this green. The status assertion is what makes
     * this a test of the PDF rather than of `window.open`.
     */
    const rendered = page.waitForResponse(
      (res) => /\/api\/v1\/permits\/\d+\/pdf/.test(res.url()),
    )

    await view.click()
    const tab = await popup
    const response = await rendered

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toContain('pdf')
    // A certificate is a rendered document, not an empty stream: dompdf writing
    // a zero-byte file would still answer 200 with a PDF content type.
    expect((await response.body()).byteLength).toBeGreaterThan(1_000)

    await expect(page.getByRole('alert')).toHaveCount(0)
    expect(tab.isClosed(), 'a failed fetch closes the tab it opened').toBe(false)
    await tab.close()
  })
})

/*
 * ── An office sees the certificates it issued, and no others ──────────────
 *
 * The scoping lives in PermitController::scopeToReader and is pinned there by
 * two API tests. Nothing above exercises it in a browser: every test in this
 * file stubs `/api/v1/permits`, so a rail entry that landed an office on the
 * whole register would pass all of them. This one goes to the real endpoint.
 *
 * It asserts the SHAPE of what comes back — every row is the office's own
 * certificate type, and the total is smaller than what BPLO sees — rather
 * than pinning counts, which the seeder is free to change.
 */
/**
 * Read one column of every row, found by its HEADING rather than its position.
 *
 * `td:nth-child(3)` was the Type column when this file was written and is the
 * Tracking ID column now that the table leads with the BAN. The BPLO test
 * below went GREEN on that change while asserting nothing it meant to: two
 * Mayor's Permits have one type between them and two different tracking IDs,
 * so "more than one certificate type" became true of the wrong column.
 *
 * A position is not a column. This looks the heading up.
 */
async function columnValues(page: import('@playwright/test').Page, heading: RegExp) {
  const headers = page.locator('thead th')
  const n = await headers.count()

  for (let i = 0; i < n; i++) {
    if (heading.test((await headers.nth(i).innerText()).replace(/\s+/g, ' '))) {
      return page.locator('tbody tr').locator(`td:nth-child(${i + 1})`).allTextContents()
    }
  }

  throw new Error(`no column heading matched ${heading}`)
}

test.describe('an office reads its own certificates only', () => {
  test.use({ storageState: sessionFor('fire') })

  test('the fire office sees FSICs and nothing else, from the server', async ({ page }) => {
    let total: number | null = null
    page.on('response', async (res) => {
      if (res.url().includes('/api/v1/permits?') && res.ok()) {
        total = (await res.json()).meta?.total ?? null
      }
    })

    await page.goto('/staff/admin/permits')
    await expect(page.getByRole('heading', { name: 'Permits', level: 1 })).toBeVisible()

    const rows = page.locator('tbody tr')
    await expect(rows.first()).toBeVisible({ timeout: 15_000 })

    // Every type cell, on the page the office actually got, says FSIC.
    const types = await columnValues(page, /PERMIT \/ CERTIFICATE/)
    expect(types.length).toBeGreaterThan(0)
    for (const t of types) expect(t).toMatch(/fire safety inspection certificate/i)

    // And the server said so too — this is not the browser hiding rows.
    expect(total, 'no /permits response was seen').not.toBeNull()
    expect(total as number).toBeGreaterThan(0)
  })
})

test.describe('BPLO reads the whole register', () => {
  test.use({ storageState: sessionFor('bplo') })

  test('BPLO sees more than one certificate type', async ({ page }) => {
    await page.goto('/staff/admin/permits')
    const rows = page.locator('tbody tr')
    await expect(rows.first()).toBeVisible({ timeout: 15_000 })
    const types = new Set(await columnValues(page, /PERMIT \/ CERTIFICATE/))
    expect(types.size, `BPLO's first page held one type only: ${[...types].join(', ')}`).toBeGreaterThan(1)
  })
})
