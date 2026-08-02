import type { Page } from '@playwright/test'
import { expect } from '@playwright/test'

/*
 * Seeded demo accounts. Every one of them has the password below, which is
 * why this stack must never be the one on a public tunnel — see
 * playwright.config.ts.
 */
export const DEMO_PASSWORD = 'biztrack1'

export const ACCOUNTS = {
  admin: 'admin@biztrack.local',
  bplo: 'bplo@biztrack.local',
  zoning: 'zoning@biztrack.local',
  owner: 'owner@biztrack.local',
} as const

/**
 * Sign in through the API and hand the token to the app.
 *
 * Driving the login form would test the form on every single spec instead of
 * once, and would make a change to the sign-in page fail forty unrelated
 * tests. auth.spec.ts drives the real form; everything else takes this door.
 *
 * The portal argument is not cosmetic: the server rejects an LGU account on
 * the public portal with 409 and vice versa, so passing the wrong one here
 * fails in a way that looks like bad credentials.
 */
export async function signIn(
  page: Page,
  account: keyof typeof ACCOUNTS,
  portal: 'staff' | 'public' = 'staff',
) {
  await page.goto('/login')

  const token = await page.evaluate(
    async ([email, password, portalName]) => {
      const res = await fetch('/api/v1/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email, password, portal: portalName }),
      })
      if (!res.ok) throw new Error(`login failed: ${res.status} ${await res.text()}`)
      const body = await res.json()
      return body.data.token as string
    },
    [ACCOUNTS[account], DEMO_PASSWORD, portal] as const,
  )

  await page.evaluate(
    ([t, p]) => {
      localStorage.setItem('biztrack.token', t)
      localStorage.setItem('biztrack.portal', p)
    },
    [token, portal] as const,
  )
}

/**
 * Every info affordance on the page, by the figure it explains.
 *
 * The button names itself "How {label} is measured", so reading the names
 * back is how a definition whose label was wrong gets caught — the label is
 * server-side prose that no type checks and nothing on screen displays.
 */
export async function infoButtonNames(page: Page): Promise<string[]> {
  return page.locator('button[aria-label^="How "]').evaluateAll((els) =>
    els.map((el) => el.getAttribute('aria-label') ?? ''),
  )
}

/** Wait for an analytics screen to have finished loading its payload. */
export async function waitForAnalytics(page: Page, heading: string | RegExp) {
  await expect(page.getByRole('heading', { name: heading, level: 1 })).toBeVisible({
    timeout: 30_000,
  })
  // The skeletons go when the fetch resolves; the info buttons only exist
  // once `meta.definitions` has arrived, so they are the real ready signal.
  await page.waitForFunction(
    () => document.querySelectorAll('.animate-pulse').length === 0,
    undefined,
    { timeout: 30_000 },
  )
}
