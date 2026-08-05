import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'

/*
 * Runs as BPLO, not as the super admin.
 *
 * Opening a filing's uploads goes through the review sheet, which needs
 * `application.review`. The super admin lost it when the client ruled that
 * Messages, Track, Inspections and Other Requirements are "not his role to do
 * those things" — and because the default chromium project hands every spec
 * the admin session, this suite started 403ing on a permission change that had
 * nothing to do with documents.
 *
 * BPLO is the right reader here rather than a clearance office, for two
 * reasons that point the same way. It is routed every filing it coordinates,
 * so its queue is effectively the whole register and a fixture is always
 * findable in it; and it holds `application.view_any_office`, so
 * DocumentController::download — which asks ApplicationVisibility — will let
 * it read any upload it can reach. That matters for the second test, which
 * needs a genuine 200. A departmentally scoped officer would be narrowed on
 * both counts.
 */
test.use({ storageState: 'e2e/.auth/bplo.json' })

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

/**
 * A review sheet on THIS office's queue whose first upload is really on disk.
 *
 * This used to be the literal `/staff/queue/6`, which worked only because the
 * super admin was the reader: `AssignmentController::authorizeDepartment`
 * exempts the `admin` role and refuses every other account an assignment
 * belonging to another department. Handing the suite a BPLO session made that
 * id a 403 and the sheet rendered nothing, so `View` was never found.
 *
 * Which is a mercy, because the id was unsafe for a second reason. Roughly
 * half the seeded uploads have a row and no file behind them — the download
 * answers 404 "File not found" — and the second test below asserts that
 * nothing was announced, so it needs an upload that genuinely opens. Nothing
 * pins document 6's file to disk, and re-running a seeder renumbers the rows
 * regardless.
 *
 * So the sheet is discovered, and the probe is the same request the button
 * makes: the first filing on this office's queue whose FIRST document really
 * downloads. First specifically, because both tests reach for `View` with
 * `.first()` — the document rows carry the type name in their accessible name,
 * and two uploads of one type would make a name-matched locator ambiguous.
 *
 * `for_inspection` is excluded rather than filtered out afterwards: that
 * status renders the compact decision box with no form and no document list on
 * it at all (see inspection-review.spec.ts), so a hit there has no View button
 * to press.
 *
 * Memoised across the file's tests. The scan is ~20 round trips and the answer
 * is a number that cannot go stale inside one run.
 */
let discovered: number | null | undefined

async function openSheetWithReadableDocument(page: Page): Promise<number | null> {
  // On-origin first: a fresh context starts on about:blank, where reading
  // localStorage is a SecurityError.
  await page.goto('/staff/queue')

  if (discovered === undefined) {
    discovered = await page.evaluate(async () => {
      const token = localStorage.getItem('biztrack.token.staff')
      const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

      const list = await fetch(
        '/api/v1/assignments?application_status=under_review,returned&per_page=30',
        { headers },
      )
      const rows = (await list.json()).data as { id: number }[]

      for (const row of rows) {
        // The list payload omits the uploads; only the detail carries them.
        const detail = await fetch(`/api/v1/assignments/${row.id}`, { headers })
        if (!detail.ok) continue
        const data = (await detail.json()).data as {
          application: { documents?: { id: number }[] }
        }
        const first = data.application.documents?.[0]
        if (!first) continue

        // The button's own request. `ok` is settled on the response headers,
        // so the file itself is never pulled down to answer this.
        const file = await fetch(`/api/v1/documents/${first.id}/download`, { headers })
        if (file.ok) return row.id
      }
      return null
    })
  }

  if (discovered === null) return null
  await page.goto(`/staff/queue/${discovered}`)
  return discovered
}

test('shows the server’s reason when a document is refused, not a generic shrug', async ({
  page,
}) => {
  const assignmentId = await openSheetWithReadableDocument(page)
  test.skip(assignmentId === null, 'no filing with a readable upload on this office’s queue')

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
  const assignmentId = await openSheetWithReadableDocument(page)
  test.skip(assignmentId === null, 'no filing with a readable upload on this office’s queue')

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
