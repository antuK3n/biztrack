import { test as setup } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { ACCOUNTS, DEMO_PASSWORD } from './helpers'
import type { E2EPortal } from './helpers'

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

/*
 * ESM has no __dirname; the config is a module, so this file is one too.
 *
 * ── Why the slot is in the path ──────────────────────────────────────────────
 *
 * A session here is bound to TWO things at once: the origin it was saved for
 * (localStorage is per-origin) and the database the token row lives in
 * (sanctum stores it, and every slot has its own copy of the register). So a
 * session minted against slot `life` on :5191 is not merely unhelpful on slot
 * `scope` at :5192 — it is meaningless in both directions.
 *
 * With a single shared `.auth` the second suite to run silently overwrites the
 * first's files, and the first's already-open contexts start bouncing to
 * /staff/login mid-test. That surfaces as a scatter of unrelated failures
 * across whichever suite lost the race, which is a genuinely expensive thing
 * to debug: nothing about it points at sessions.
 *
 * Keying by slot makes concurrent suites possible and costs one directory.
 */
const SLOT = process.env.E2E_SLOT ?? 'default'
const AUTH_DIR = path.join(path.dirname(fileURLToPath(import.meta.url)), '.auth', SLOT)

async function saveSession(
  page: import('@playwright/test').Page,
  account: keyof typeof ACCOUNTS,
  portal: E2EPortal,
  file: string,
  /*
   * Extra token keys this saved state should also carry.
   *
   * Only the super admin uses it, and only because their screens currently
   * answer at TWO prefixes. Checklist item #107 gave them their own portal at
   * /admin/…, and the /staff/admin/… routes stayed — they are not all the
   * administrator's (/staff/admin/permits is gated on `permit.view_all`, which
   * BPLO and the five clearance offices hold). Most of this suite drives the
   * admin's screens at the staff prefix, and those specs belong to other work
   * in flight, so the session is written under both keys rather than rewriting
   * a dozen files' addresses from underneath their owners.
   *
   * State it plainly: this is a TRANSITIONAL fixture, and it describes a
   * browser state the product can no longer produce on its own — the admin
   * cannot sign in at /staff/login any more, so nothing but this line would put
   * their token under the staff key. When the specs move to /admin/…, drop the
   * extra key and this argument with it.
   */
  alsoKeyedAs: E2EPortal[] = [],
) {
  /*
   * Long enough to outlast the limiter window this step may have to wait on.
   *
   * The retry below sleeps 65s on a 429, and a Playwright step times out at 30s
   * — so without this the recovery could never finish, and the step failed at
   * exactly 30s reporting a timeout rather than the rate limit that caused it.
   * Two full windows plus the work, because a run that trips the limiter twice
   * (setup invoked directly and then again as the chromium project's
   * dependency) is the case that produced this.
   */
  setup.setTimeout(150_000)

  /*
   * Wait for the app to stop reloading itself before running anything in it.
   *
   * The FIRST navigation against a freshly raised stack reliably killed this
   * step with "Execution context was destroyed, most likely because of a
   * navigation" — and with setup dead, every test depending on it is skipped,
   * so a whole run reported nothing about the product. It is not a product
   * fault and not a race in the app: Vite's dependency optimiser discovers the
   * app's imports on that first load and then forces a full reload, which
   * throws away the execution context whatever is running in it.
   *
   * `domcontentloaded` plus a real element means the reload has already
   * happened by the time the fetch below runs. The catch is the belt: if it
   * fires anyway, going round once more costs a second and the alternative is
   * an entire suite lost to a dev-server behaviour.
   */
  for (let attempt = 0; ; attempt++) {
    try {
      await page.goto('/login', { waitUntil: 'domcontentloaded' })
      await page.getByRole('button', { name: /sign in|log in/i }).first().waitFor({ timeout: 30_000 })
      break
    } catch (error) {
      if (attempt >= 2) throw error
    }
  }

  /*
   * Waits out a 429 rather than failing on it.
   *
   * There are nine sessions to mint now that all seven offices are covered,
   * against a sign-in limiter of ten per minute per IP. Nine fits, but only
   * just, and anything else on this machine touching /auth/login — a rerun, a
   * spec calling signIn(), a developer with the app open — pushes the run over
   * and fails the whole suite at setup with 429.
   *
   * The fix is emphatically NOT to raise the limiter: it is a control doing
   * exactly its job, and a suite that needs the product weakened to pass is
   * testing a product nobody ships. So the setup waits instead. The limiter
   * window is a minute, hence the 65s.
   */
  const token = await page.evaluate(
    async ([email, password, portalName]) => {
      const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms))

      for (let attempt = 0; attempt < 3; attempt++) {
        const res = await fetch('/api/v1/auth/login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ email, password, portal: portalName }),
        })
        if (res.ok) return (await res.json()).data.token as string
        if (res.status !== 429) {
          throw new Error(`login failed for ${email}: ${res.status} ${await res.text()}`)
        }
        await sleep(65_000)
      }
      throw new Error(`login for ${email} kept hitting the rate limiter`)
    },
    [ACCOUNTS[account], DEMO_PASSWORD, portal] as const,
  )

  /*
   * Keyed by portal, which is what lets the three portals' storage states sit
   * in one browser without any of them evicting the others — the same property
   * the product needs for an admin tab and a citizen tab open at once.
   */
  await page.evaluate(
    ([t, keys]) => {
      for (const key of keys) localStorage.setItem(`biztrack.token.${key}`, t)
    },
    [token, [portal, ...alsoKeyedAs]] as const,
  )

  fs.mkdirSync(AUTH_DIR, { recursive: true })
  await page.context().storageState({ path: path.join(AUTH_DIR, file) })
}

/*
 * The super admin, through their own door [checklist item #107].
 *
 * This said `portal: 'staff'` until `admin` was split out of
 * AuthController::STAFF_ROLES. The API answers that 409 now, so this step
 * failed — and because the default `chromium` project depends on it and takes
 * admin.json as its storage state, EVERY spec in the suite was blocked before
 * it ran. A one-word fixture mistake reading as total product failure is worth
 * the paragraph.
 *
 * The staff key is written too; see the note on `alsoKeyedAs`.
 */
setup('authenticate as admin', async ({ page }) => {
  await saveSession(page, 'admin', 'admin', 'admin.json', ['staff'])
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

/*
 * The remaining four clearance offices.
 *
 * Zoning above already proves an office can inspect. These exist for the
 * claim zoning alone cannot support: that an office sees its OWN filings and
 * nobody else's. One session looking at its own queue is consistent both with
 * scoping that works and with no scoping at all — the difference only shows
 * when a second office looks at the same register and sees something else.
 *
 * Sequential, not parallel: they share the sign-in limiter, and setup steps in
 * one file already run in order.
 */
for (const account of ['sanitary', 'fire', 'obo', 'cenro'] as const) {
  setup(`authenticate as the ${account} office`, async ({ page }) => {
    await saveSession(page, account, 'staff', `${account}.json`)
  })
}
