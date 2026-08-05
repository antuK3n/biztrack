import { expect, test } from '@playwright/test'

/*
 * Checklist item 111, first sub-item: "view/download file uploaded not working"
 * on the admin side.
 *
 * The buttons themselves work — the first case below opens a real file — and
 * that is why this stayed open so long: the reporter was not looking at a broken
 * download, they were looking at a REFUSED one that could not say so.
 *
 * `documents.view()` and `documents.download()` ask axios for a blob, and
 * `responseType: 'blob'` applies to the error body too. So a 403 arrived with
 * its `{"message": "..."}` wrapped in a Blob, `toApiError()` looked for
 * `data.message`, found undefined, and fell back to "Something went wrong on our
 * end. Please try again." — a sentence that describes a server fault. The office
 * boundary added for items 56 and 111 is exactly what produces those 403s, so
 * closing the leak without this makes the mask MORE common, not less.
 *
 * The refusal is forced with a route stub rather than hunted for in the seed
 * data: the screen only ever lists documents the reader may open, so the failure
 * path has no natural click. What is under test is the client's handling of a
 * refusal, and that is the same whichever document provoked it.
 */

/** A filing with attachments on it; the admin session may open any of them. */
const REVIEW_SHEET = '/staff/queue/6'

test('shows the server’s reason when a document is refused, not a generic shrug', async ({
  page,
}) => {
  await page.goto(REVIEW_SHEET)

  const view = page.getByRole('button', { name: /^View / }).first()
  await expect(view).toBeVisible()

  await page.route('**/api/v1/documents/*/download', (route) =>
    route.fulfill({
      status: 403,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'This application belongs to another office.' }),
    }),
  )

  await view.click()

  const alert = page.getByRole('alert').filter({ hasText: 'another office' })
  await expect(alert).toBeVisible()
  // The whole point: the reader is told which door is shut, not that the
  // building fell down.
  await expect(page.getByText('Something went wrong on our end')).toHaveCount(0)
})

test('still opens a document the office is allowed to read', async ({ page }) => {
  await page.goto(REVIEW_SHEET)

  const view = page.getByRole('button', { name: /^View / }).first()
  await expect(view).toBeVisible()

  // The control opens the tab synchronously and only then fetches, so the
  // popup is the thing to wait on.
  const popup = page.context().waitForEvent('page')
  await view.click()
  await popup

  // No failure was announced: the file was fetched and handed to the tab.
  await expect(page.getByRole('alert')).toHaveCount(0)
})
