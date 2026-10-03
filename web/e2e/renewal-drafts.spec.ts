import { expect, test, type Page } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * A renewal is a draft the same way a new permit is.
 *
 * ── What this is for ────────────────────────────────────────────────────────
 *
 * Client, 3 October 2026: *"Are renewals saved in the Drafts the same way as
 * the New Permit? Make sure their rulings are the same (e.g., Data Privacy
 * ticking saves them in draft too, opening of the draft transport them to the
 * farthest section, naming scheme, etc.)"*
 *
 * They were not. Both halves of the scratch-draft machinery in `ApplyWizard`
 * were gated on `applicationType === 'new'` — the autosave and the restore —
 * so a renewal or an amendment in progress was never written to
 * `wizardDrafts` at all. Nothing reached the Drafts list, ticking Data
 * Privacy saved nothing, and there was no row to reopen at the furthest
 * section. Every draft rule the new permit had was a rule only it had.
 *
 * ── Why it has to be a browser test ─────────────────────────────────────────
 *
 * None of this is visible to `tsc` or to the API suite. The endpoint always
 * accepted `application_type: 'renewal'` — it validates a string and stores a
 * free-form payload — so the server was never the thing refusing. What was
 * missing was the browser ever calling it, and "the tab wrote a draft and a
 * later tab read it back" is a browser fact.
 *
 * ── The four claims ─────────────────────────────────────────────────────────
 *
 *  1. a renewal in progress reaches the Drafts list;
 *  2. reopening it restores the answers AND the entry dialog's answer, so the
 *     dialog does not open again over them;
 *  3. the Data Privacy tick survives the round trip;
 *  4. reopening lands on the furthest unfinished section, not back at the top.
 *
 * Claims 2 and 4 are the ones with teeth. A draft that comes back with its
 * form filled but its business forgotten is a renewal of nothing, and a draft
 * that comes back at Data Privacy Consent is the complaint that started the
 * whole draft thread.
 */

test.use({ storageState: sessionFor('owner') })

const DIALOG = /which permits? are you renewing/i

function dialog(page: Page) {
  return page.getByRole('dialog', { name: DIALOG })
}

/**
 * Answer the entry dialog: business 1, its first permit.
 *
 * Business 1 is the seeded owner's one business with permits in the register
 * — the same one `apply-wizard.spec.ts` uses, chosen by value rather than by
 * index so another shop registered on the stack cannot shift it.
 */
async function answerEntryDialog(page: Page) {
  const modal = dialog(page)
  await expect(modal).toBeVisible({ timeout: 30_000 })

  /*
   * By the ACCESSIBLE name, which is the full question, while the visible
   * label is the single word "Business". The two differ on purpose — the
   * dialog's title asks the question a line above the field, so repeating it
   * on the field was the third telling (client, 3 October 2026: *"So much
   * texts and very lengthy explanations"*), but a screen-reader user tabbing
   * in hears the field and not the heading above it.
   */
  await modal.getByLabel(/which business are you renewing/i).selectOption({ value: '1' })

  /* By tag: the picker is radios or checkboxes depending on the rule. */
  const permits = modal.locator('ul[aria-label*="are you renewing"] input')
  await expect(permits.first()).toBeVisible({ timeout: 20_000 })
  await permits.first().check()

  await modal.getByRole('button', { name: /continue/i }).click()
  await expect(modal).toBeHidden({ timeout: 20_000 })
}

/** The wizard's own title box, which is also the draft's name. */
function titleBox(page: Page) {
  return page.getByLabel(/application title/i)
}

/**
 * Wait for the debounced save to have landed.
 *
 * The wizard writes on an 800ms debounce and shows "Saving…" while the
 * request is out, so the honest signal that a draft exists is that indicator
 * going quiet again — not a fixed sleep, which would be either flaky or slow
 * and usually both.
 */
async function savedOnce(page: Page) {
  await expect(page.getByText(/saving…/i)).toBeHidden({ timeout: 30_000 })
}

test('a renewal in progress is saved as a draft and reopens where it was left', async ({
  page,
}) => {
  await page.goto('/apply?type=renewal')
  await answerEntryDialog(page)

  /*
   * The Data Privacy tick. This is claim 3 and it is the one the client named
   * first, because it is the only answer on the first screen — a draft that
   * loses it asks for consent again on every reopen, which is what teaches
   * people to tick consent without reading it.
   */
  const consent = page.getByRole('checkbox', { name: /i have read/i })
  await expect(consent).toBeVisible({ timeout: 20_000 })
  await consent.check()

  /* Name it, so the row is findable by something the test chose. */
  const name = `E2E Renewal Draft ${Date.now()}`
  await titleBox(page).fill(name)

  await savedOnce(page)

  /* ── 1. It reached the Drafts list ──────────────────────────────────── */
  await page.goto('/drafts')
  const card = page.getByRole('link').filter({ hasText: name })
  await expect(card).toBeVisible({ timeout: 30_000 })

  /* ── 2 and 4. Reopening restores it, and does not re-ask ────────────── */
  await card.click()

  /*
   * The dialog must NOT be here. It opened for every renewal without a
   * `draft` id, which included every resumed one, so the applicant was asked
   * which business they were renewing on top of the answers just restored.
   *
   * Asserted with a wait rather than a bare `toBeHidden`, because the restore
   * is asynchronous: a dialog that appears 200ms later and is then dismissed
   * would pass an instant check and still be the flash this fixes.
   */
  await expect(titleBox(page)).toHaveValue(name, { timeout: 30_000 })
  await expect(dialog(page)).toBeHidden()

  /* The consent answer came back with it. */
  await expect(page.getByRole('checkbox', { name: /i have read/i })).toBeChecked()

  /*
   * And it did not land on the first section. Data Privacy Consent is step
   * one; having answered it, the furthest unfinished section is later than
   * that, and landing back on it is precisely the complaint this thread
   * began with.
   */
  await expect(page.getByRole('heading', { name: /data privacy consent/i })).toBeHidden()
})

test('an amendment in progress is saved as a draft too', async ({ page }) => {
  /*
   * The same machinery, asserted for the third filing type because the gate
   * that excluded renewals excluded amendments in exactly the same words —
   * so a fix that reached only one of them would look complete from the
   * renewal's side.
   *
   * Shorter than the renewal case on purpose: the claim here is that the row
   * is written at all. What a resumed amendment restores is the renewal
   * test's subject, and the two share every line of the code that does it.
   */
  await page.goto('/apply?type=amendment')

  const modal = page.getByRole('dialog')
  await expect(modal).toBeVisible({ timeout: 30_000 })
  await modal.getByLabel(/which business are you amending/i).selectOption({ value: '1' })
  await modal.getByRole('checkbox').first().check()
  await modal.getByRole('button', { name: /continue/i }).click()
  await expect(modal).toBeHidden({ timeout: 20_000 })

  const name = `E2E Amendment Draft ${Date.now()}`
  await titleBox(page).fill(name)
  await savedOnce(page)

  await page.goto('/drafts')
  await expect(page.getByRole('link').filter({ hasText: name })).toBeVisible({ timeout: 30_000 })
})
