import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { sessionFor } from './helpers'

/*
 * The chat bubble says what the API said when the API refused a message.
 *
 * Every failed send used to read "Sorry, I couldn't reach the assistant just
 * now", including the two that are not outages at all: a message over 2,000
 * characters (422) and an owner whose account is restricted (403). Both are
 * the server answering with a sentence the owner can act on, and "try again"
 * sent them to repeat something that would only be refused again.
 *
 * The 403 is stubbed rather than seeded, for the reason suspended-owner.spec.ts
 * gives: restricting a real business in the copied register means writing a
 * restriction and then trusting a second write to lift it. The sentence is the
 * one EnforceAccountRestriction sends.
 */

test.use({ storageState: sessionFor('owner') })

const COULD_NOT_REACH = "Sorry, I couldn't reach the assistant just now."

async function openBubble(page: Page) {
  await page.goto('/dashboard')
  await page.getByRole('button', { name: 'Open BizTrack ChatBot' }).click()
  const input = page.getByRole('textbox', { name: 'Message BizTrack ChatBot' })
  await expect(input).toBeVisible()
  return input
}

test('an over-long message is answered with the length, not an outage', async ({ page }) => {
  const input = await openBubble(page)
  await input.fill('a'.repeat(2001))
  const sent = page.waitForResponse(
    (r) => r.url().endsWith('/api/v1/chatbot/messages') && r.request().method() === 'POST',
  )
  await input.press('Enter')
  expect((await sent).status()).toBe(422)

  await expect(page.getByText('Please keep your message to 2,000 characters or fewer.')).toBeVisible()
  await expect(page.getByText(COULD_NOT_REACH, { exact: false })).toHaveCount(0)
})

test('a restricted owner is told why the assistant will not answer', async ({ page }) => {
  const restricted =
    'This account is restricted while one of its businesses is suspended. Message the City BPLO about it.'
  await page.route('**/api/v1/chatbot/messages', (route) =>
    route.request().method() === 'POST'
      ? route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ message: restricted }) })
      : route.continue(),
  )
  const input = await openBubble(page)
  await input.fill('requirements for a sanitary permit')
  await input.press('Enter')

  await expect(page.getByText(restricted)).toBeVisible()
  await expect(page.getByText(COULD_NOT_REACH, { exact: false })).toHaveCount(0)
})

test('a server error still says the assistant could not be reached', async ({ page }) => {
  // A 5xx message is a note to a developer and never goes on the screen.
  await page.route('**/api/v1/chatbot/messages', (route) =>
    route.request().method() === 'POST'
      ? route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'Server Error' }) })
      : route.continue(),
  )
  const input = await openBubble(page)
  await input.fill('requirements for a sanitary permit')
  await input.press('Enter')

  await expect(page.getByText(COULD_NOT_REACH, { exact: false })).toBeVisible()
  await expect(page.getByText('Server Error')).toHaveCount(0)
})
