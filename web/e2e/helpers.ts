import type { Page } from '@playwright/test'
import { expect } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

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
  // The other five clearance offices. Present so a spec can prove an office
  // sees ITS filings and nobody else's — a claim that cannot be made from a
  // single office's session, because one office looking at its own queue looks
  // identical whether scoping works or was never implemented.
  sanitary: 'sanitary@biztrack.local',
  fire: 'fire@biztrack.local',
  obo: 'obo@biztrack.local',
  cenro: 'cenro@biztrack.local',
} as const

/**
 * The seven offices, paired with the permit each one issues.
 *
 * Read off `permit_types.issuing_department_id`, and the pairing is the point:
 * "a sanitary account can only see sanitary permits" is a claim about that
 * table, so a spec asserting it should be driven by the same mapping rather
 * than by a list retyped into a test that can quietly fall out of step.
 *
 * BPLO carries `inspects: false` because it is the only office that reads the
 * papers without ever booking a visit — it coordinates the clearances. Every
 * other office's permit type has `requires_inspection` set.
 */
export const OFFICES = [
  { account: 'bplo', code: 'BPLO', permit: 'BUSINESS', inspects: false },
  { account: 'sanitary', code: 'CHO', permit: 'SANITARY', inspects: true },
  { account: 'fire', code: 'BFP', permit: 'FSIC', inspects: true },
  { account: 'zoning', code: 'CPDO', permit: 'ZONING', inspects: true },
  { account: 'obo', code: 'OBO', permit: 'OCCUPANCY', inspects: true },
  { account: 'cenro', code: 'CENRO', permit: 'CEC', inspects: true },
] as const satisfies ReadonlyArray<{
  account: keyof typeof ACCOUNTS
  code: string
  permit: string
  inspects: boolean
}>

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

  // Keyed by portal: the two sites hold separate sessions (see lib/api.ts).
  await page.evaluate(
    ([t, p]) => {
      localStorage.setItem(`biztrack.token.${p}`, t)
    },
    [token, portal] as const,
  )
}

interface StorageState {
  cookies: unknown[]
  origins: { origin: string; localStorage: { name: string; value: string }[] }[]
}

/** Must match auth.setup.ts — see the note there on why the slot is in the path. */
const AUTH_DIR = path.join(
  path.dirname(fileURLToPath(import.meta.url)),
  '.auth',
  process.env.E2E_SLOT ?? 'default',
)

/** The saved session for an account, for `browser.newContext({ storageState })`. */
export function sessionFor(account: keyof typeof ACCOUNTS): string {
  return path.join(AUTH_DIR, `${account}.json`)
}

/**
 * Two saved sessions in one browser profile.
 *
 * Playwright's `storageState` takes one file, and the point being tested is
 * that two portals' sessions can share a browser — so they are merged here
 * rather than logged in again. Logging in twice more would also trip the
 * sign-in endpoint's 5-attempt lockout, which is a control doing its job.
 *
 * The merge is only possible because the tokens are keyed by portal. If the
 * two files ever collide on a key, this throws rather than silently letting
 * one win — which is precisely the bug the portal split fixed.
 */
export function mergedStorageState(files: string[]): StorageState {
  const states = files.map(
    (f) => JSON.parse(fs.readFileSync(path.join(AUTH_DIR, f), 'utf8')) as StorageState,
  )
  const byOrigin = new Map<string, Map<string, string>>()
  for (const state of states) {
    for (const origin of state.origins ?? []) {
      const entries = byOrigin.get(origin.origin) ?? new Map<string, string>()
      for (const { name, value } of origin.localStorage) {
        const existing = entries.get(name)
        if (existing !== undefined && existing !== value) {
          throw new Error(
            `${files.join(' and ')} both set localStorage "${name}" — the sessions collide, ` +
              'so they cannot both be open in one browser.',
          )
        }
        entries.set(name, value)
      }
      byOrigin.set(origin.origin, entries)
    }
  }
  return {
    cookies: [],
    origins: [...byOrigin].map(([origin, entries]) => ({
      origin,
      localStorage: [...entries].map(([name, value]) => ({ name, value })),
    })),
  }
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
