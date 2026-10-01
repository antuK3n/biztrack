import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * The two renewal rules, as the applicant meets them on screen.
 *
 * Both were built on 1 October 2026 and both are invisible to the Pest suite,
 * which can prove the API sends `renewal_blocked_reason` and an
 * `unbilled_fees` total but cannot prove a checkbox is greyed out or a card is
 * on the page. That gap is the whole reason this file exists:
 *
 *  1. A clearance lapsed past the 36-month cutoff must be REFUSED IN THE
 *     PICKER. The server refuses it at submit either way; without this the
 *     applicant learns that after filling in the entire wizard.
 *
 *  2. A permit issued unbilled must SAY SO ON PROFILE. Being handed a
 *     certificate and asked for no money reads as "paid", and the charge
 *     arriving months later on a January bill is how a correct rule becomes a
 *     complaint at the counter.
 *
 * ── The fixtures are seeded, not found ─────────────────────────────────────
 *
 * This suite runs against a copy of the live register, which holds neither
 * state — measured on the day: no lapsed clearance at all, and no unbilled fee
 * carrying a surcharge. The usual convention here is `test.skip` when the copy
 * has nothing suitable, and that would be wrong for these two: they would skip
 * for ever and the suite would report green over screens nobody had checked.
 * `biztrack:seed-renewal-ui-fixtures` creates both, and refuses to run against
 * any database that is not `e2e.sqlite`.
 */

test.use({ storageState: sessionFor('owner') })

test.describe('renewal limits, as the applicant sees them', () => {
  test('shows what was issued unbilled, and what the lateness added', async ({ page }) => {
    await page.goto('/profile')

    const card = page.getByRole('heading', { name: 'Fees due with your January renewal' })
    await expect(card).toBeVisible()

    /*
     * The three facts the card exists to carry, asserted separately because
     * they fail for different reasons: the fee (the payload reached the page),
     * the penalty (the surcharge is not folded into the fee), and WHEN it is
     * collected — a figure with no date reads as a demand.
     */
    /*
     * Scoped to the card's own list. `locator('section, div')` resolved to
     * #root, so the first draft was asserting against the whole page and
     * would have passed on text from anywhere on it.
     */
    const panel = page.locator('div').filter({ has: card }).last()

    await expect(panel).toContainText('Sanitary')
    await expect(panel).toContainText('1,100')
    /*
     * 357.50, not 275: the card prints ONE penalty figure, the surcharge and
     * its interest added (275.00 + 82.50). The first draft asserted the
     * surcharge alone and failed — the code was right and the expectation
     * was written from the database columns rather than from the screen.
     */
    await expect(panel).toContainText('357.50')
    await expect(panel).toContainText('late')
    await expect(page.getByText(/collects once a year/i)).toBeVisible()

    /*
     * The ordinance behind the surcharge, named on the page. An applicant
     * disputing a charge needs to know which rule produced it, and this is
     * the only screen that tells them before the bill.
     */
    await expect(page.getByText(/8A\.04/)).toBeVisible()
  })

  test('refuses a long-lapsed clearance in the picker, not after the whole form', async ({
    page,
  }) => {
    /*
     * The picker is the identity DIALOG a renewal opens with, reached by the
     * type in the query string — `/apply` alone opens a new filing and asks
     * nothing about permits, which is why the first draft timed out hunting
     * for a "renewal" button that was never on that page.
     */
    await page.goto('/apply?type=renewal')

    const modal = page.getByRole('dialog', { name: /which permits are you renewing/i })
    await expect(modal).toBeVisible({ timeout: 30_000 })

    // Business 1 is the seeded owner's shop, and the one the fixture
    // attached the long-lapsed sanitary permit to.
    await modal
      .getByLabel(/which business are you renewing/i)
      .selectOption({ value: '1' })

    const blocked = modal.getByText(/can no longer be renewed/i).first()
    await expect(blocked).toBeVisible({ timeout: 20_000 })

    /*
     * The sentence has to tell them what to do instead. "Outside the renewal
     * window" would be a refusal they cannot act on; this one names the form
     * that will work.
     */
    await expect(blocked).toContainText('New Application')

    /*
     * And the row cannot be ticked. This is the assertion that would have
     * caught the original defect: the reason could be printed beside a live
     * checkbox and the applicant would still fill in the wizard.
     */
    /*
     * `hasText`, not `has`. A `has:` locator is matched RELATIVE to each
     * candidate, and the first draft handed it an absolute dialog-scoped
     * one — which matched nothing and reported the checkbox missing rather
     * than enabled, hiding whether the assertion had any teeth at all.
     */
    const row = modal.locator('li').filter({ hasText: /can no longer be renewed/i })
    await expect(row.getByRole('checkbox')).toBeDisabled()
  })
})
