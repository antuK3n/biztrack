import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { sessionFor, waitForAnalytics } from './helpers'

/*
 * What the dashboard SAYS about its figures, office by office.
 *
 * A review of the screen found figures that were right and words that were
 * wrong: City Health's dashboard said "permit" and "Business permit compliance"
 * about sanitary permits; BPLO, which books no visits, got an empty Inspections
 * chart; working days and office days both printed as "d"; a 20-minute wait
 * printed "0.0h"; the first of the month showed three zero bars; a renewal rate
 * that could not be computed claimed there were no permits due; and the Office
 * menu was cut short at 1440px. Each test below pins one of those as a reader
 * meets it.
 *
 * Several tests rewrite one field of the real payload with page.route — the
 * only way to show a first-of-the-month, or a 20-minute wait, on a register
 * that holds neither today. The rest of the payload is the server's.
 */
const BPLO_SESSION = sessionFor('bplo')
const SANITARY_SESSION = sessionFor('sanitary')

const DASHBOARD = '/staff/analytics'
const TITLE = 'Analytics Dashboard'

type Payload = { data: Record<string, unknown> & Record<string, Record<string, unknown>> }

/**
 * Let the dashboard's real answer through with `edit` applied to it.
 *
 * `route.fetch` takes the 15s action timeout, which a dashboard computed cold
 * on the single-threaded test server can outrun — the page itself waits for it
 * without a limit, so the rewrite is given the navigation budget instead.
 */
async function editPayload(page: Page, edit: (data: Payload['data']) => void) {
  await page.route('**/api/v1/analytics/dashboard?**', async (route) => {
    const response = await route.fetch({ timeout: 30_000 })
    const json = (await response.json()) as Payload
    edit(json.data)
    await route.fulfill({ response, json })
  })
}

/*
 * A payload rewrite still in flight when its test ends fails the NEXT test
 * with "route.fetch: Test ended", which reads as that test's fault.
 */
test.afterEach(async ({ page }) => {
  await page.unrouteAll({ behavior: 'ignoreErrors' })
})

test.describe('as City Health', () => {
  test.use({ storageState: SANITARY_SESSION })

  test('the permit figures name the sanitary permit, not "permit" or the business permit', async ({ page }) => {
    await page.goto(DASHBOARD)
    await waitForAnalytics(page, TITLE)

    await expect(page.getByText('holding a sanitary permit valid today')).toBeVisible()
    await expect(page.getByText('sanitary permit validity, today')).toBeVisible()
    await expect(page.getByText('Sanitary permit compliance')).toBeVisible()
    await expect(page.getByText('Business permit compliance')).toHaveCount(0)
    await expect(page.getByText(/businesses ever issued a sanitary permit hold a sanitary permit that is valid today/)).toBeVisible()

    // The map's red legend says what the payload knows, and no more.
    await expect(page.getByText('Sanitary permit valid today', { exact: true })).toBeVisible()
    await expect(page.getByText('No valid sanitary permit today (lapsed, or not issued yet)')).toBeVisible()
  })

  test('RA 11032 is in working days and time at an office in office days, on every figure', async ({ page }) => {
    await page.goto(DASHBOARD)
    await waitForAnalytics(page, TITLE)

    const tiers = page.getByRole('table', { name: /Average processing time per RA 11032 tier/ })
    for (const cell of await tiers.locator('tbody td:first-of-type').allTextContents()) {
      expect(cell).toMatch(/^\d+\.\d working days$/)
    }

    const offices = page.getByRole('table', { name: /Average office days a review spends with each department/ })
    for (const cell of await offices.locator('tbody td:first-of-type').allTextContents()) {
      expect(cell).toMatch(/^\d+\.\d office days$/)
    }
    await expect(page.getByText(/An office day is 9 office hours/)).toBeVisible()
  })

  test('each compliance card states its own window; the heading claims none', async ({ page }) => {
    await page.goto(DASHBOARD)
    await waitForAnalytics(page, TITLE)

    const heading = page.getByRole('heading', { name: 'Compliance Rate', level: 2 })
    await expect(heading).toBeVisible()
    // The note beside a heading is its sibling in the heading row.
    await expect(heading.locator('xpath=../..')).not.toContainText('Last 12 months')

    const validity = page.locator('div').filter({ hasText: /^Sanitary permit compliance/i }).last()
    await expect(validity).toContainText(/As of /)
    const processing = page.locator('div').filter({ hasText: /^RA 11032 processing/i }).last()
    await expect(processing).toContainText(/Last 12 months to /)
  })

  test('a wait under an hour is given in minutes, not as 0.0h', async ({ page }) => {
    await editPayload(page, (data) => {
      data.officer_activity.mean_response_hours = 0.35
      data.officer_activity.median_response_hours = 0.2
      data.officer_activity.responses = 4
    })
    await page.goto(DASHBOARD)
    await waitForAnalytics(page, TITLE)

    await expect(page.getByText('0.0h')).toHaveCount(0)
    await expect(page.getByText(/^21\s*min$/)).toBeVisible()
    await expect(page.getByText(/middle wait 12 min/)).toBeVisible()
  })

  test('a renewal rate that cannot be computed does not claim there was nothing due', async ({ page }) => {
    await editPayload(page, (data) => {
      const cards = data.compliance as unknown as Record<string, unknown>[]
      const renewal = cards.find((card) => card.indicator === 'renewal') as Record<string, unknown>
      Object.assign(renewal, { numerator: 0, denominator: 0, rate: null, unavailable_reason: null })
    })
    await page.goto(DASHBOARD)
    await waitForAnalytics(page, TITLE)

    const card = page.locator('div').filter({ hasText: /^Renewal compliance/i }).last()
    await expect(card).toContainText('Cannot be computed')
    await expect(card).toContainText('No sanitary permit that fell due in this window is named by a renewal filing')
    await expect(card).not.toContainText('No permits due for renewal')
  })

  test('the first of the month reads as nothing filed yet, not as empty charts', async ({ page }) => {
    await editPayload(page, (data) => {
      const volume = data.volume as { rows: { count: number }[]; total: number }
      volume.rows.forEach((row) => (row.count = 0))
      volume.total = 0
      const decisions = data.decisions as Record<string, unknown> & { rows: { count: number }[] }
      decisions.rows.forEach((row) => (row.count = 0))
      Object.assign(decisions, { total: 0, decisioned: 0, approved: 0, approval_rate: null })
    })
    await page.goto(DASHBOARD)
    await waitForAnalytics(page, TITLE)

    await expect(page.getByText('Nothing filed yet this month.')).toHaveCount(2)
    await expect(page.getByText(/has been decided yet/)).toHaveCount(0)
    await expect(page.getByRole('table', { name: /Applications filed this month by transaction type/ })).toHaveCount(0)
  })

  test('a link to another office is refused plainly, with the way back and no "Try again"', async ({ page }) => {
    await page.goto(`${DASHBOARD}?office=BFP`)
    await expect(page.getByRole('heading', { name: TITLE, level: 1 })).toBeVisible()
    await expect(page.getByText(/only see your own office/i)).toBeVisible()
    await expect(page.getByRole('button', { name: 'Try again' })).toHaveCount(0)

    await page.getByRole('button', { name: 'Show my office instead' }).click()
    await waitForAnalytics(page, TITLE)
    await expect(page).not.toHaveURL(/office=/)
    await expect(page.getByText('Showing', { exact: false }).filter({ hasText: 'City Health Office' })).toBeVisible()
  })
})

test.describe('as BPLO', () => {
  test.use({ storageState: BPLO_SESSION })

  test('an office that books no visits gets no Inspections panel', async ({ page }) => {
    await page.goto(`${DASHBOARD}?office=BPLO`)
    await waitForAnalytics(page, TITLE)
    await expect(page.getByRole('heading', { name: 'Inspections', level: 2 })).toHaveCount(0)

    // An office that does inspect keeps it.
    await page.goto(`${DASHBOARD}?office=CHO`)
    await waitForAnalytics(page, TITLE)
    await expect(page.getByRole('heading', { name: 'Inspections', level: 2 })).toBeVisible()
  })

  test('BPLO’s own figures name the business permit', async ({ page }) => {
    await page.goto(`${DASHBOARD}?office=BPLO`)
    await waitForAnalytics(page, TITLE)
    await expect(page.getByText('holding a business permit valid today')).toBeVisible()
    await expect(page.getByText('No valid business permit today (lapsed, or not issued yet)')).toBeVisible()
  })

  test('the Office menu shows the whole office name at 1440px', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await page.goto(DASHBOARD)
    await waitForAnalytics(page, TITLE)

    const menu = page.getByLabel('Office', { exact: true })
    for (const office of ['BPLO', 'CPDO']) {
      await menu.selectOption(office)
      await waitForAnalytics(page, TITLE)
      // The chosen option's text, measured in the select's own font, against
      // the room the select gives it (less padding and the arrow).
      const fits = await menu.evaluate((el) => {
        const select = el as HTMLSelectElement
        const style = getComputedStyle(select)
        const context = document.createElement('canvas').getContext('2d') as CanvasRenderingContext2D
        context.font = `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`
        const text = select.options[select.selectedIndex].text
        const room = select.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight) - 16
        return { text, needed: context.measureText(text).width, room }
      })
      expect(fits.needed, `"${fits.text}" is cut short`).toBeLessThanOrEqual(fits.room)
    }
  })

  test('the trailing window stays in the URL, like the office', async ({ page }) => {
    await page.goto(DASHBOARD)
    await waitForAnalytics(page, TITLE)

    await page.getByRole('button', { name: 'Filter the dashboard' }).click()
    const asked = page.waitForRequest((req) => req.url().includes('/api/v1/analytics/dashboard') && req.url().includes('months=24'))
    await page.getByRole('dialog', { name: 'Filter the dashboard' }).getByLabel('Trailing window').selectOption('24')
    await asked
    await expect(page).toHaveURL(/months=24/)

    const again = page.waitForRequest((req) => req.url().includes('/api/v1/analytics/dashboard') && req.url().includes('months=24'))
    await page.reload()
    await again
    await waitForAnalytics(page, TITLE)
    await expect(page.getByText(/Last 24 months to /).first()).toBeVisible()
  })

  test.describe('on a 390px phone', () => {
    test.use({ viewport: { width: 390, height: 844 } })

    test('the expiry table keeps its Total column on screen', async ({ page }) => {
      await page.goto(`${DASHBOARD}?office=all`)
      await waitForAnalytics(page, TITLE)

      const table = page.getByRole('table', { name: /Permits approaching expiry by window/ })
      const total = table.getByRole('columnheader', { name: 'Total' })
      await total.scrollIntoViewIfNeeded()
      const box = await total.boundingBox()
      expect(box, 'the Total heading has no box').not.toBeNull()
      expect((box?.x ?? 0) + (box?.width ?? 0)).toBeLessThanOrEqual(390)
      await expect(total).toBeInViewport()
    })

    test('business category names are written in full, not cut off', async ({ page }) => {
      await page.goto(`${DASHBOARD}?office=all`)
      await waitForAnalytics(page, TITLE)

      const heading = page.getByRole('heading', { name: 'Top Five Business Categories' })
      await heading.scrollIntoViewIfNeeded()
      const section = page.locator('section').filter({ has: heading })
      const labels = section.locator('figure ul li span:nth-child(2)')
      expect(await labels.count()).toBeGreaterThan(0)
      for (const fits of await labels.evaluateAll((els) =>
        els.map((el) => ({
          text: el.textContent,
          ellipsis: getComputedStyle(el).textOverflow === 'ellipsis',
          clipped: el.scrollWidth > el.clientWidth + 1,
        })),
      )) {
        expect(fits.ellipsis, `"${fits.text}" is truncated`).toBe(false)
        expect(fits.clipped, `"${fits.text}" overflows its box`).toBe(false)
      }
    })
  })
})
