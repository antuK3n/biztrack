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

const PERMITS = [
  {
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
  },
  {
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
  },
  {
    /*
     * A permit whose business was removed from the register.
     *
     * Business soft-deletes and its certificates stay, so PermitResource
     * answers `business: null` on 139 rows of the live data (AGENTS.md §11).
     * The `Permit` type claims that relation is non-nullable, which is exactly
     * why this row is in the fixture: a cell written as `permit.business.name`
     * typechecks and then throws on this row.
     */
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
      const rows = PERMITS.filter((p) => {
        const haystack =
          `${p.permit_number} ${p.business?.name ?? ''} ${p.application.tracking_id}`.toLowerCase()
        return (!q || haystack.includes(q)) && (!status || p.status === status)
      })

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

  test('the table carries the five columns the screen was scoped to, and no more', async ({
    page,
  }) => {
    /*
     * The column list IS the design decision. "Only the necessary columns" was
     * the instruction, and the way that gets undone is one useful-looking field
     * at a time, so the count is asserted alongside the names.
     */
    const headers = page.locator('thead th')
    await expect(headers).toHaveCount(6) // five data columns + the actions column
    for (const label of ['Permit No.', 'Business', 'Type', 'Valid until', 'Status']) {
      await expect(page.getByRole('columnheader', { name: new RegExp(label, 'i') })).toBeVisible()
    }

    const rows = page.locator('tbody tr')
    await expect(rows).toHaveCount(PERMITS.length)

    const first = rows.first()
    await expect(first).toContainText('MCB-2026-000001')
    await expect(first).toContainText('Aling Nena Sari-Sari Store')
    await expect(first).toContainText("Mayor's Permit")
    // Never colour alone: the state is a word in the row, not just a tint.
    await expect(first).toContainText('Active')

    // The removed business says so rather than blanking or throwing.
    await expect(rows.filter({ hasText: 'MCZ-2026-000014' })).toContainText(
      'Business removed from register',
    )

    // Both numbers named, and the noun says which register is being counted.
    await expect(page.getByText('Showing 3 of 3 issued permits')).toBeVisible()
  })

  test('searching narrows the table, on the server, by number and by business', async ({ page }) => {
    const rows = page.locator('tbody tr')
    const search = page.getByRole('searchbox', {
      name: 'Search permits by permit number, business name or tracking ID',
    })

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

    // And the tracking ID, which has no column but is searchable on purpose:
    // an administrator holding one can find the permit the filing produced.
    await search.fill('BIZ-2026-00490')
    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('MCZ-2026-000014')
  })

  test('a search that matches nothing says so, and says what it matched on', async ({ page }) => {
    await page
      .getByRole('searchbox', { name: 'Search permits by permit number, business name or tracking ID' })
      .fill('nothing matches this')

    await expect(page.getByText('No permits match your search')).toBeVisible()
    await expect(page.locator('tbody tr')).toHaveCount(0)
    await expect(
      page.getByText(/Search matches the permit number, the business name and the tracking ID/),
    ).toBeVisible()
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

  test('columns sort the page in hand, and the screen says that is all they do', async ({ page }) => {
    const heading = page.getByRole('button', { name: /Permit No\./ })

    await heading.click()
    await expect(page.locator('th', { has: heading })).toHaveAttribute('aria-sort', 'ascending')
    await expect(page.locator('tbody tr').first()).toContainText('MCB-2026-000001')

    await heading.click()
    await expect(page.locator('th', { has: heading })).toHaveAttribute('aria-sort', 'descending')
    await expect(page.locator('tbody tr').first()).toContainText('MCZ-2026-000014')

    /*
     * The honesty line. /permits accepts no ordering — the sort reaches the
     * rows in hand and no further — and a reader looking at 25 of 5,475 would
     * otherwise take the top row for the register's first.
     */
    await expect(
      page.getByText(
        'Sorted by Permit No. within this page. The register itself is ordered by issue date, newest first.',
      ),
    ).toBeVisible()

    // A third press puts the register back in the order its totals are counted in.
    await heading.click()
    await expect(page.locator('th', { has: heading })).toHaveAttribute('aria-sort', 'none')
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
    const types = await rows.locator('td:nth-child(3)').allTextContents()
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
    const types = new Set(await rows.locator('td:nth-child(3)').allTextContents())
    expect(types.size, `BPLO's first page held one type only: ${[...types].join(', ')}`).toBeGreaterThan(1)
  })
})
