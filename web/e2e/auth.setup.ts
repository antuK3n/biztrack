import { test as setup } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { ACCOUNTS, DEMO_PASSWORD } from './helpers'

/*
 * Sign in once per run and hand the saved session to every other spec.
 *
 * The first draft logged in inside `beforeEach`, which tripped the login
 * endpoint's rate limiter after a dozen tests and failed four specs with 429.
 * That limiter is a control doing its job — a suite that had to have it
 * loosened to pass would have been the wrong fix, and would have left the
 * product weaker than the tests.
 *
 * So the session is minted here, once, and replayed from disk. It also means
 * a spec no longer pays a round trip to prove something unrelated to auth.
 */

// ESM has no __dirname; the config is a module, so this file is one too.
const AUTH_DIR = path.join(path.dirname(fileURLToPath(import.meta.url)), '.auth')

async function saveSession(
  page: import('@playwright/test').Page,
  account: keyof typeof ACCOUNTS,
  portal: 'staff' | 'public',
  file: string,
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
      return (await res.json()).data.token as string
    },
    [ACCOUNTS[account], DEMO_PASSWORD, portal] as const,
  )

  /*
   * Keyed by portal, which is what lets the staff and owner storage states sit
   * in one browser without either evicting the other — the same property the
   * product needs for an admin tab and a citizen tab open at once.
   */
  await page.evaluate(
    ([t, p]) => {
      localStorage.setItem(`biztrack.token.${p}`, t)
    },
    [token, portal] as const,
  )

  fs.mkdirSync(AUTH_DIR, { recursive: true })
  await page.context().storageState({ path: path.join(AUTH_DIR, file) })
}

setup('authenticate as admin', async ({ page }) => {
  await saveSession(page, 'admin', 'staff', 'admin.json')
})

setup('authenticate as business owner', async ({ page }) => {
  await saveSession(page, 'owner', 'public', 'owner.json')
})

setup('authenticate as a BPLO officer', async ({ page }) => {
  await saveSession(page, 'bplo', 'staff', 'bplo.json')
})

/*
 * An officer of a clearance office, for the specs that inspect.
 *
 * The super admin used to cover this and no longer can. The client's
 * instruction was that Messages, Track, Inspections and Other Requirements are
 * "not his role to do those things", so `admin` lost `application.review` and
 * `inspection.manage` along with them — and the default chromium project hands
 * every spec the admin session, which is why two suites started 403ing at once.
 *
 * Zoning rather than BPLO: conducting a visit needs BOTH `application.review`
 * to open the filing and `inspection.manage` to record the result, and BPLO
 * holds only the first. It coordinates the clearances; it does not inspect.
 * Every one of the six clearance offices would do — zoning is simply the one
 * already in ACCOUNTS.
 *
 * Note this session is departmentally scoped by ApplicationVisibility, so a
 * spec using it can only reach filings routed to CPDO. That is the product
 * working, not a limitation to design around.
 */
setup('authenticate as a clearance-office inspector', async ({ page }) => {
  await saveSession(page, 'zoning', 'staff', 'zoning.json')
})
