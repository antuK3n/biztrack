import { expect, test, type Page } from '@playwright/test'
import { sessionFor, WIZARD_PAINT_MS } from './helpers'

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
  await expect(modal).toBeVisible({ timeout: WIZARD_PAINT_MS })

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
  await expect(permits.first()).toBeVisible({ timeout: WIZARD_PAINT_MS })
  await permits.first().check()

  await modal.getByRole('button', { name: /continue/i }).click()
  await expect(modal).toBeHidden({ timeout: WIZARD_PAINT_MS })
}

/** The wizard's own title box, which is also the draft's name. */
function titleBox(page: Page) {
  return page.getByLabel(/application title/i)
}

/**
 * Run `act`, and resolve once the draft it causes has actually been written.
 *
 * The wizard writes on an 800ms debounce, so there is a window in which the
 * answer is on screen and nowhere else. Anything that reads the draft back
 * has to wait out that window or it is testing the wrong moment.
 */
async function savingSettles(page: Page, act: () => Promise<void>) {
  /*
   * ── The RESPONSE, not the "Saving…" indicator ──────────────────
   *
   * This waited for that indicator to be HIDDEN, reasoning that it shows
   * while the request is out. `toBeHidden` passes instantly on an element
   * that was never there — so between the keystroke and the 800ms debounce
   * it returned at once, the test navigated away, and the draft was written
   * to a page that had already gone. It then failed on the Drafts list,
   * which read as a product bug and was not: driving the same flow by hand
   * showed POST /wizard-drafts 201 followed by PUT 200.
   *
   * The waiter is armed BEFORE the action, or a fast save races it.
   */
  const written = page.waitForResponse(
    (r) => r.url().includes('/wizard-drafts') && r.status() >= 200 && r.status() < 300,
    { timeout: WIZARD_PAINT_MS },
  )
  await act()
  await written

  /* The debounce can coalesce a second write; let it land before moving. */
  await page.waitForTimeout(1500)
}

test('a renewal in progress is saved as a draft and reopens where it was left', async ({
  page,
}) => {
  /*
   * ── Longer than the 180s the config gives every other test ────────────
   *
   * A round trip through a draft opens the apply wizard TWICE — once to fill
   * it in, once to reopen it from the Drafts card — and that route takes
   * around fifty seconds to paint on the isolated stack (see
   * `WIZARD_PAINT_MS` for the measurements and why it is dev-server module
   * loading rather than the product).
   *
   * Two of those, plus the Drafts list between them, does not fit in 180s.
   * It passed when run alone and failed when run with its neighbour, which
   * is the signature of a budget rather than a defect — and a test that
   * passes alone and fails in company is worse than one that simply fails.
   */
  test.setTimeout(420_000)

  await page.goto('/apply?type=renewal')
  await answerEntryDialog(page)

  /*
   * The Data Privacy tick. This is claim 3 and it is the one the client named
   * first, because it is the only answer on the first screen — a draft that
   * loses it asks for consent again on every reopen, which is what teaches
   * people to tick consent without reading it.
   */
  const consent = page.getByRole('checkbox', { name: /i have read/i })
  await expect(consent).toBeVisible({ timeout: WIZARD_PAINT_MS })
  await consent.check()

  /* Name it, so the row is findable by something the test chose. */
  const name = `E2E Renewal Draft ${Date.now()}`
  await savingSettles(page, async () => {
    await titleBox(page).fill(name)
  })

  /* ── 1. It reached the Drafts list ──────────────────────────────────── */
  await page.goto('/drafts')

  /*
   * ── Exactly ONE card, which is half of what this test is for ──────────
   *
   * There were two. A renewal in progress left both a `wizardDrafts` scratch
   * row and a real `applications` draft under one name, and the Drafts page
   * numbered them "(1)" having noticed the clash itself — one filing drawn
   * twice, with no way for the applicant to tell which was theirs.
   *
   * The cause was a race: the scratch row is created inside an 800ms
   * debounce, and on a renewal the real draft is created early, so the
   * discard could run while the create was still in flight and find nothing
   * to throw away. Fixed in the save effect; asserted here.
   *
   * `toHaveCount(1)` and not `.first()`: picking the first of two would make
   * this pass against the very bug it was written for.
   *
   * Deliberately NOT pinned to one kind of link. Which of the two survives
   * is an implementation detail that has already changed once during this
   * work; that there is one of them is the rule.
   */
  const card = page.getByRole('link').filter({ hasText: name })
  await expect(card).toHaveCount(1, { timeout: WIZARD_PAINT_MS })

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
  await expect(titleBox(page)).toHaveValue(name, { timeout: WIZARD_PAINT_MS })
  await expect(dialog(page)).toBeHidden()

  /*
   * ── It did not land on the first section ──────────────────────────────
   *
   * Data Privacy Consent is step one; having answered it, the furthest
   * unfinished section is later than that, and landing back on it is
   * precisely the complaint this thread began with.
   */
  await expect(page.getByRole('heading', { name: /data privacy consent/i })).toBeHidden()

  /*
   * ── And the consent itself survived ───────────────────────────────────
   *
   * Checked by going BACK to step one, which is the only place that box is
   * drawn. The first version of this asserted it straight after reopening
   * and failed with "element(s) not found" — the wizard had done exactly
   * what the test above demands and moved past the step, so the control was
   * not on screen to be checked. A green assertion there would have meant
   * the furthest-section rule was broken.
   */
  await page.getByRole('button', { name: /data privacy consent/i }).click()
  await expect(page.getByRole('checkbox', { name: /i have read/i })).toBeChecked({
    timeout: WIZARD_PAINT_MS,
  })
})

test('an amendment in progress is saved as a draft too', async ({ page }) => {
  /*
   * Same budget as the renewal above, for the same reason: this opens the
   * apply wizard too, and it runs SECOND in the worker, by which point the
   * dev server is already warm for some modules and busy with others.
   *
   * It was briefly marked `fixme` on the reading that the amendment dialog
   * never appeared at all — it had failed a 90s wait where the renewal
   * dialog paints in about fifty, which looked like a second, separate
   * cause. Driving the route on its own showed the dialog present and the
   * page fully rendered. One cause, not two: the earlier failure was this
   * test being starved of the per-test budget by its neighbour.
   */
  test.setTimeout(420_000)

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
  await expect(modal).toBeVisible({ timeout: WIZARD_PAINT_MS })
  await modal.getByLabel(/which business are you amending/i).selectOption({ value: '1' })
  /*
   * No tick here, and the first draft of this test looked for one. The
   * amendment dialog asks which BUSINESS and nothing else — what is changing
   * is asked on the Amendments step inside the wizard.
   */
  await modal.getByRole('button', { name: /continue/i }).click()
  await expect(modal).toBeHidden({ timeout: WIZARD_PAINT_MS })

  const name = `E2E Amendment Draft ${Date.now()}`
  await savingSettles(page, async () => {
    await titleBox(page).fill(name)
  })

  await page.goto('/drafts')
  await expect(page.getByRole('link').filter({ hasText: name })).toBeVisible({ timeout: WIZARD_PAINT_MS })
})
