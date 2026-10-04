import { expect, test, type Page } from '@playwright/test'

/*
 * ── A front door per office ───────────────────────────────────────────────
 *
 * "Sa business owner side kung wala pang mismong officer in charge sa
 * application nila, magkakaroon na rin ng general inquiry ang ibat ibang
 * offices, tulad ng pinagawa ko sa bplo" [client, 28 September 2026].
 *
 * Which offices are offered, and who may read what, are proved in
 * api/tests/Feature/OfficeInboxScopeTest.php, where the register can be
 * arranged. What only a browser can answer is whether the owner can FIND the
 * door and get a message through it, which is what this file is for.
 *
 * ── Nothing here writes into a door ──────────────────────────────────────
 *
 * A door is CONSUMED by being used: write in it once and it stops being a
 * front door and joins the conversations above. So "open a door and send" is
 * a fixture that dismantles itself — the stack's register is a throwaway, but
 * it is long-lived, and by the third run of this file there were no doors
 * left and every test was failing on its own leavings rather than on the
 * product.
 *
 * So the door tests only OPEN doors, and the one test that has to send writes
 * into an enquiry that is already a conversation and stays one. That a row
 * moves out of the doors once it has something in it is a server rule, and it
 * is asserted where server rules are asserted.
 */

/**
 * The composer, and the office it says it will reach.
 *
 * Read from the PLACEHOLDER rather than from the row that was pressed. A door
 * prints the office as a code badge and a name that wraps on a narrow pane,
 * so `innerText` line numbers move with the width and with the length of the
 * office's name — properties of the stylesheet, not of the product. The
 * placeholder is the product's own answer to "who am I writing to", which is
 * the thing under test anyway.
 */
function composerOn(page: Page) {
  return page.locator('textarea[placeholder^="Write to "]')
}

async function officeInComposer(page: Page): Promise<string> {
  const placeholder = await composerOn(page).getAttribute('placeholder')

  // "Write to Bureau of Fire Protection…" → "Bureau of Fire Protection"
  return (placeholder ?? '')
    .replace(/^Write to /, '')
    .replace(/[….]+$/, '')
    .trim()
}

test.describe('a business owner', () => {
  test.use({ storageState: 'e2e/.auth/default/owner.json' })

  test('is offered an office to ask that no permit has reached', async ({ page }) => {
    await page.goto('/messages')

    /*
     * A section, not cards in the list. Five "No messages yet, start the
     * conversation" rows the size of real exchanges used to sit above the
     * conversations; the doors are drawn as doors now, under a heading.
     */
    const doors = page.getByRole('region', { name: 'Ask an office' })
    await expect(doors).toBeVisible({ timeout: 20000 })

    await doors.getByRole('button').first().click()

    /*
     * The composer names an OFFICE, and a real one. Every general thread went
     * to BPLO whatever the screen said until an enquiry could be addressed —
     * a question for the fire office arrived in BPLO's post, and the screen
     * gave no sign of it.
     */
    await expect(composerOn(page)).toBeVisible({ timeout: 20000 })
    expect((await officeInComposer(page)).length).toBeGreaterThan(0)

    // And it opens empty, because nobody has written in it.
    await expect(page.getByText(/No messages yet/)).toBeVisible()
  })

  test('keeps each office a separate conversation', async ({ page }) => {
    await page.goto('/messages')

    const doors = page.getByRole('region', { name: 'Ask an office' })
    await expect(doors).toBeVisible({ timeout: 20000 })

    await doors.getByRole('button').first().click()
    await expect(composerOn(page)).toBeVisible({ timeout: 20000 })
    const door = await officeInComposer(page)

    /*
     * An enquiry that already has something in it, opened from the list.
     *
     * The row key is what keeps the two apart. An enquiry used to be keyed by
     * the bare word 'general', which was right while an owner had exactly one;
     * with a door per office that key made every enquiry row select every
     * other one, and all of them opened whichever the list happened to find
     * first.
     */
    const spoken = page
      .getByRole('list', { name: 'Conversations' })
      .getByRole('button')
      .filter({ hasText: 'General enquiry' })
      .filter({ hasNotText: door })

    await expect(spoken.first()).toBeVisible({ timeout: 20000 })
    await spoken.first().click()
    await expect(composerOn(page)).toBeVisible({ timeout: 20000 })

    expect(await officeInComposer(page)).not.toBe(door)
    // The one with something in it is not showing the empty one's state.
    await expect(page.getByText(/No messages yet/)).toHaveCount(0)
  })

  test('sends when the Send button is pressed, not only on Enter', async ({ page }) => {
    /*
     * ── A press that did nothing, and why nothing caught it ──────────────
     *
     * Send was dead to the mouse, on every conversation in the product. The
     * specs that reply to anybody type the message, and the ones that press
     * the button do it without the box being focused first, so the one path a
     * real person uses most was the one path nothing exercised.
     *
     * Mousedown blurred the textarea; the "Press Enter to send" hint below
     * the row is shown only while the box has focus, so it collapsed, the
     * composer got ~24px shorter, the transcript above grew into the gap and
     * carried the button down with it. Mouseup landed on the transcript, and
     * a press that starts and ends on different elements is not a click.
     *
     * The assertion is the transcript, not the request: what was broken was
     * that nothing happened, and "the message is on screen" is the shape that
     * failure took for a reader.
     */
    await page.goto('/messages')

    const enquiry = page
      .getByRole('list', { name: 'Conversations' })
      .getByRole('button')
      .filter({ hasText: 'General enquiry' })

    await expect(enquiry.first()).toBeVisible({ timeout: 20000 })
    await enquiry.first().click()
    await expect(composerOn(page)).toBeVisible({ timeout: 20000 })

    const body = `Pressed, not typed [${Date.now()}]`
    await composerOn(page).fill(body)

    const send = page.getByRole('button', { name: /^Send/ })
    await expect(send).toBeEnabled()
    await send.click()

    await expect(page.getByText(body)).toBeVisible({ timeout: 20000 })
    // And the box is cleared, which is the app's own signal that it went.
    await expect(composerOn(page)).toHaveValue('')
  })
})

test.describe('an office', () => {
  test.use({ storageState: 'e2e/.auth/default/bplo.json' })

  test('sees the permits assigned to it, said on or not', async ({ page }) => {
    /*
     * "Ang andon lang sa messages page nila ay kung ano ang mga naka assign na
     * business permit sa kanila" [client, 28 September 2026].
     *
     * A permit handed to this office appears whether or not anybody has
     * written about it — an officer given a file has to be able to write the
     * first word from the screen they read their mail on. The row says as
     * much rather than pretending to be a conversation.
     */
    await page.goto('/staff/messages')

    const rows = page.getByRole('list', { name: 'Conversations' }).getByRole('button')
    await expect(rows.first()).toBeVisible({ timeout: 20000 })

    await expect(page.getByText('Nothing said yet').first()).toBeVisible()
  })
})
