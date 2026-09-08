import { expect, test } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * The Sort and Filter controls on Messages and Other Requirements.
 *
 * Same defect as item 90 and the same reason this file exists: `<SortFilter />`
 * has a documented mode where it renders the words and the chevron as a plain
 * span, so a screen can look finished before its sorting is written. Three
 * screens shipped that way. Messages drew a "Filter" that was not a button;
 * Other Requirements and Drafts drew both halves inert. Nothing in `pest` or
 * `tsc` can see the difference between a control that does nothing and one that
 * is broken — only a browser can say whether clicking "Unread" narrowed
 * anything.
 *
 * Payloads are stubbed, not seeded, for the reasons given in track-search.spec:
 * a narrowing assertion has to know exactly which rows exist, and this suite
 * stays read-only against a register that may hold testers' filings.
 */

/* ── Messages ─────────────────────────────────────────────────────────────── */

const OFFICE = { id: 1, department_id: 1, code: 'BPLO', name: 'Business Permits and Licensing Office' }

/** One inbox row, in the shape MessageController::threadRow emits. */
function thread(over: {
  id: number
  business: string
  tracking: string
  unread: number
  messages: number
  mine: boolean | null
  updated: string
}) {
  return {
    kind: 'application',
    thread_id: null,
    user_id: null,
    application_id: over.id,
    tracking_id: over.tracking,
    business_name: over.business,
    status: 'under_review',
    counterparty: { name: OFFICE.name, subtitle: over.business, is_officer: true },
    responsible_office: { code: OFFICE.code, name: OFFICE.name, officer: null },
    offices: [
      {
        ...OFFICE,
        thread_id: over.messages > 0 ? over.id : null,
        messages_count: over.messages,
        unread_count: over.unread,
        last_message_at: over.messages > 0 ? over.updated : null,
        can_message: true,
      },
    ],
    messages_count: over.messages,
    unread_count: over.unread,
    last_message:
      over.mine === null
        ? null
        : {
            body: 'Please send the sanitary permit.',
            sender_name: over.mine ? 'Aling Nena' : 'BPLO Officer',
            mine: over.mine,
            created_at: over.updated,
          },
    updated_at: over.updated,
  }
}

const THREADS = [
  // Two turns from the office, neither opened.
  thread({
    id: 80101,
    business: 'Aling Nena Sari-Sari Store',
    tracking: 'BIZ-2026-00101',
    unread: 2,
    messages: 3,
    mine: false,
    updated: '2026-08-01T00:00:00.000000Z',
  }),
  // The owner wrote last and is waiting on an answer.
  thread({
    id: 80102,
    business: 'Bayanihan Hardware',
    tracking: 'BIZ-2026-00102',
    unread: 0,
    messages: 2,
    mine: true,
    updated: '2026-07-01T00:00:00.000000Z',
  }),
  // Read, and the office spoke last: nothing is owed either way.
  thread({
    id: 80103,
    business: 'Cielo Bakeshop',
    tracking: 'BIZ-2026-00103',
    unread: 0,
    messages: 4,
    mine: false,
    updated: '2026-06-01T00:00:00.000000Z',
  }),
  // A filing nobody has written on. This row IS the way in, which is why the
  // inbox lists it at all — and why "Not started" is a useful narrowing.
  thread({
    id: 80104,
    business: 'Dagupan Auto Supply',
    tracking: 'BIZ-2026-00104',
    unread: 0,
    messages: 0,
    mine: null,
    updated: '2026-05-01T00:00:00.000000Z',
  }),
]

/**
 * The conversation rows in list order.
 *
 * Scoped to the labelled list, because the open thread beside it renders its
 * own buttons and a looser selector counts them as conversations.
 */
async function inboxNames(page: import('@playwright/test').Page): Promise<string[]> {
  return page.locator('ul[aria-label="Conversations"] > li > button').allInnerTexts()
}

test.describe('Messages inbox controls', () => {
  test.use({ storageState: sessionFor('owner') })

  /** Every /message-threads query string the page asked for, in order. */
  let requested: string[]

  /**
   * Serve an inbox, narrowing it the way MessageController::applyNarrow does.
   *
   * The stub has to honour `narrow` or the assertions would pass whether the
   * page sent one or filtered in the browser — which is the exact defect being
   * tested. Same standard the queue's server-side search is held to.
   */
  async function serveInbox(page: import('@playwright/test').Page, rows: typeof THREADS) {
    await page.route('**/api/v1/message-threads*', async (route) => {
      const url = new URL(route.request().url())
      requested.push(url.search)

      const narrow = url.searchParams.get('narrow')
      const data = rows.filter((t) => {
        if (narrow === 'unread') return t.unread_count > 0
        if (narrow === 'awaiting') return t.last_message?.mine === true
        if (narrow === 'quiet') return t.messages_count === 0
        return true
      })

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data,
          meta: { current_page: 1, last_page: 1, per_page: 50, total: data.length },
        }),
      })
    })
  }

  test.beforeEach(async ({ page }) => {
    requested = []
    await serveInbox(page, THREADS)

    // The pane fetches a transcript for whichever row opens first on a wide
    // screen. One officer turn, because where the officer's name and office
    // appear is half of what this file now pins.
    await page.route('**/api/v1/applications/*/messages*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            {
              id: 5001,
              body: 'Please send the sanitary permit.',
              sender: { id: 42, name: 'Juan Dela Cruz', is_officer: true },
              department: OFFICE,
              attachments: [],
              created_at: '2026-08-01T00:00:00.000000Z',
            },
          ],
          meta: { offices: [OFFICE], department_id: OFFICE.department_id },
        }),
      })
    })

    await page.goto('/messages')
    await expect(page.getByRole('heading', { name: 'Messages', level: 1 })).toBeVisible()
  })

  test('the unread count is on the row, not only in the nav badge', async ({ page }) => {
    // Never colour alone: the number is text on the card, so the reason a row
    // appears under "Unread" is legible without opening it.
    await expect(page.getByText('2 unread')).toBeVisible()
  })

  test('a conversation is named after the office, and says it once', async ({ page }) => {
    /*
     * The client's instruction, and what it replaced. A general enquiry read:
     *
     *     Business Permits and Licensing Office
     *     Handled by Business Permits and Licensing Office · no officer assigned yet
     *     General enquiry
     *
     * — the office twice, plus an apology for a person nobody asked for. "No
     * officer assigned yet" is gone outright: a conversation does not need a
     * named officer, and announcing the absence of one makes a normal state
     * look like a fault.
     */
    await expect(page.getByText('no officer assigned yet')).toHaveCount(0)
    await expect(page.getByText('Not yet assigned to an office')).toHaveCount(0)

    // The office names the row; the officer does not.
    const first = page.locator('ul[aria-label="Conversations"] > li > button').first()
    await expect(first).toContainText(OFFICE.name)

    // Said once: the responsible office is not repeated under a title that is
    // already that office.
    expect((await first.innerText()).split(OFFICE.name).length - 1).toBe(1)
  })

  test('the officer’s name appears on their reply, with the office they answer for', async ({
    page,
  }) => {
    // Where a person's name IS a fact rather than a promise: on the turn they
    // wrote. "Juan Dela Cruz · BPLO Officer".
    await page.locator('ul[aria-label="Conversations"] > li > button').first().click()

    await expect(page.getByText('Juan Dela Cruz')).toBeVisible()
    await expect(page.getByText(`· ${OFFICE.code} Officer`)).toBeVisible()
  })

  test('Filter narrows the inbox to what is waiting on somebody', async ({ page }) => {
    const names = () => inboxNames(page)
    expect((await names()).length).toBeGreaterThanOrEqual(4)

    await page.getByRole('button', { name: /^Filter/ }).click()
    await page.getByRole('option', { name: 'Unread' }).click()
    await expect
      .poll(() => names())
      .toEqual([expect.stringContaining('Aling Nena Sari-Sari Store')])
    // The proof it is a query and not a slice of the downloaded page: the inbox
    // is capped at fifty rows, so a browser-side filter would answer for fifty.
    await expect.poll(() => requested.at(-1)).toContain('narrow=unread')

    // Your own turn was last: you are waiting on them.
    await page.getByRole('button', { name: /^Filter/ }).click()
    await page.getByRole('option', { name: 'Awaiting their reply' }).click()
    await expect.poll(() => names()).toEqual([expect.stringContaining('Bayanihan Hardware')])
    await expect.poll(() => requested.at(-1)).toContain('narrow=awaiting')

    await page.getByRole('button', { name: /^Filter/ }).click()
    await page.getByRole('option', { name: 'Not started' }).click()
    await expect.poll(() => names()).toEqual([expect.stringContaining('Dagupan Auto Supply')])

    await page.getByRole('button', { name: /^Filter/ }).click()
    await page.getByRole('option', { name: 'All conversations' }).click()
    await expect.poll(async () => (await names()).length).toBeGreaterThanOrEqual(4)
    // "All" asks for the inbox, not for a narrowing named "all".
    await expect.poll(() => requested.at(-1)).not.toContain('narrow=')
  })

  test('an empty filter says it is the filter, and offers the way back', async ({ page }) => {
    // "No conversations yet" for an empty Unread list told a reader the city
    // had never written to them. Different nothing, different sentence.
    await page.unroute('**/api/v1/message-threads*')
    await serveInbox(page, [THREADS[2]])
    await page.reload()

    await page.getByRole('button', { name: /^Filter/ }).click()
    await page.getByRole('option', { name: 'Unread' }).click()

    await expect(page.getByText('You have read everything')).toBeVisible()
    await expect(page.getByText('No conversations yet')).toHaveCount(0)

    await page.getByRole('button', { name: 'Show all conversations' }).click()
    await expect.poll(() => inboxNames(page)).toEqual([expect.stringContaining('Cielo Bakeshop')])
  })
})

/* ── Other Requirements ───────────────────────────────────────────────────── */

const STATUSES = [
  { value: 'pending', label: 'Pending' },
  { value: 'submitted', label: 'For Review' },
  { value: 'fulfilled', label: 'Approved' },
  { value: 'needs_resubmission', label: 'Needs Resubmission' },
  { value: 'rejected', label: 'Rejected' },
]

/** One requirement row, in the shape OfficerRequestResource emits. */
function requirement(id: number, subject: string, status: string, label: string) {
  return {
    id,
    request_type: 'document',
    subject,
    body: null,
    status,
    status_label: label,
    remarks: null,
    accepts_response: status !== 'fulfilled',
    awaits_applicant: status === 'pending' || status === 'needs_resubmission' || status === 'rejected',
    awaits_office: status === 'submitted',
    is_closed: status === 'fulfilled',
    additional_remarks: null,
    reference: null,
    due_date: null,
    created_at: '2026-08-01T00:00:00.000000Z',
    created_by: { id: 5, name: 'BPLO Officer', department: OFFICE.name },
    from_office: { id: 1, code: OFFICE.code, name: OFFICE.name },
    application: {
      id: 90101,
      business_id: 1,
      tracking_id: 'BIZ-2026-00101',
      business_name: 'Aling Nena Sari-Sari Store',
    },
    responses: [],
  }
}

const REQUIREMENTS = [
  requirement(60001, 'Sanitary permit', 'pending', 'Pending'),
  requirement(60002, 'Water potability test', 'submitted', 'For Review'),
  requirement(60003, 'Health cards', 'rejected', 'Rejected'),
]

test.describe('Other Requirements list controls', () => {
  test.use({ storageState: sessionFor('bplo') })

  /** Every /requests URL the page asked for, in order. */
  let requested: string[]

  test.beforeEach(async ({ page }) => {
    requested = []

    await page.route('**/api/v1/requests*', async (route) => {
      const url = new URL(route.request().url())
      requested.push(url.search)

      /*
       * The stub narrows the way the server does, because the point of the
       * assertion is that the browser ASKED rather than sliced. A stub that
       * ignored the parameter would pass whether the page sent one or not.
       */
      const status = url.searchParams.get('status')
      let rows = status ? REQUIREMENTS.filter((r) => r.status === status) : [...REQUIREMENTS]
      if (url.searchParams.get('sort') === 'oldest') rows = [...rows].reverse()

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: rows,
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 50,
            total: rows.length,
            office_statuses: [
              { value: 'pending', label: 'Pending' },
              { value: 'fulfilled', label: 'Approved' },
              { value: 'rejected', label: 'Rejected' },
            ],
            statuses: STATUSES,
          },
        }),
      })
    })

    // The composer asks for the office's filings on mount.
    await page.route('**/api/v1/applications*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
        }),
      })
    })

    await page.goto('/staff/requests')
    await expect(page.getByRole('heading', { name: 'Other Requirements', level: 1 })).toBeVisible()
  })

  test('Filter narrows on the server, because the list is paged', async ({ page }) => {
    const rows = page.locator('tbody tr')
    await expect(rows).toHaveCount(3)

    await page.getByRole('button', { name: /^Filter/ }).click()
    await page.getByRole('option', { name: 'For Review' }).click()

    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('Water potability test')

    // The proof it is a query and not a slice of what was downloaded. A
    // browser-side filter would answer "none" to an office whose rejected
    // requirements are on page two.
    await expect.poll(() => requested.at(-1)).toContain('status=submitted')

    // Both numbers named, and the sentence says what was asked — "1 of 1"
    // alone would read as the whole register.
    await expect(page.getByRole('status').filter({ hasText: 'Showing' })).toContainText('Showing 1 of 1 for review')
  })

  test('Sort asks the server for the other order', async ({ page }) => {
    const rows = page.locator('tbody tr')
    await expect(rows.first()).toContainText('Sanitary permit')

    await page.getByRole('button', { name: /^Sort/ }).click()
    await page.getByRole('option', { name: 'Oldest first' }).click()

    await expect.poll(() => requested.at(-1)).toContain('sort=oldest')
    await expect(rows.first()).toContainText('Health cards')
    await expect(page.getByRole('status').filter({ hasText: 'Showing' })).toContainText('oldest first')
  })

  test('an empty filter blames the filter, not the register', async ({ page }) => {
    await page.getByRole('button', { name: /^Filter/ }).click()
    await page.getByRole('option', { name: 'Needs Resubmission' }).click()

    await expect(page.getByText('Nothing has that status')).toBeVisible()
    await expect(page.getByText('No requirement is Needs Resubmission right now')).toBeVisible()
    // The old wording. An office reading it concluded it had never raised one.
    await expect(page.getByText('No requests yet')).toHaveCount(0)

    await page.getByRole('button', { name: 'Show all statuses' }).click()
    await expect(page.locator('tbody tr')).toHaveCount(3)
  })
})
