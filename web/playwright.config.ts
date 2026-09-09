import { defineConfig, devices } from '@playwright/test'

/*
 * End-to-end cover for the screens, which had none.
 *
 * The API suite is thorough about what the server computes and says nothing
 * about whether any of it reaches a reader. Every regression this suite was
 * written against was invisible to `pest` and to `tsc`: a definition whose
 * accessible name read "How How this list is built is measured", a field
 * closed with `disabled` so screen readers skipped it, a screen titled
 * differently from the dataset it renders. Those are browser facts.
 *
 * ── Which stack this runs against ───────────────────────────────────────────
 *
 * NOT the one on :5173. That one is proxied to the API holding real testers'
 * data and is exposed through a public tunnel, so a suite that submits an
 * application would write junk into somebody's live filing.
 *
 * `E2E_BASE_URL` points at a throwaway stack instead — a second Vite whose
 * `VITE_API_TARGET` is a second Laravel serving a *copy* of the SQLite file.
 * Raise it with `npm run e2e:stack` before running, or point this at any
 * disposable environment. It defaults to the isolated port rather than 5173
 * so that forgetting to set it fails safe.
 */

/*
 * 127.0.0.1, not localhost, and it has to match how e2e-stack.sh binds Vite.
 *
 * "localhost" is two addresses on this machine — ::1 and 127.0.0.1 — and which
 * one a client tries first is not ours to decide. e2e-stack.sh now passes
 * `--host 127.0.0.1`, so naming the address here means both ends are talking
 * about the same socket instead of relying on a fallback to find each other.
 *
 * Change one of these two and change the other. The failure when they disagree
 * does not look like a networking problem: the suite simply cannot reach a
 * server that is plainly running, and the port reads as free to half the tools
 * you check it with.
 */
const BASE_URL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5199'

export default defineConfig({
  testDir: './e2e',
  // A shared browser and a shared database mean tests must not race each
  // other into the same session. Files run in parallel; tests inside a file
  // run in order.
  fullyParallel: false,
  workers: process.env.CI ? 1 : 4,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    // The dev server compiles on demand, so a cold first navigation is slow
    // in a way a built app would not be.
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
  },

  /*
   * ── Assertions get the same allowance as actions, and had to ──────────────
   *
   * `actionTimeout` and `navigationTimeout` above were raised because the dev
   * server compiles on demand and a cold first navigation is slow. `expect`'s
   * own timeout was left at Playwright's 5s default, which is the same
   * oversight one line further down: the FIRST assertion after a cold
   * navigation is waiting on exactly the work those two were widened for.
   *
   * Measured on the isolated stack rather than guessed. Opening `/applications`
   * with a saved session:
   *
   *     heading visible after      5,946ms
   *     GET /api/v1/auth/me        1,349ms, starting at ~4,350ms
   *
   * So roughly four and a half seconds of module graph before the app can ask
   * the API anything, then a second and a bit for a single-process PHP server
   * to answer — against a 5,000ms budget. Right on the line, which is why
   * `track-search.spec.ts` failed ten tests one run and a different ten the
   * next: the same tests, sitting either side of the boundary.
   *
   * 15s matches `actionTimeout` rather than exceeding it: an assertion should
   * not out-wait the click before it. A genuinely broken expectation now takes
   * three times as long to report, which is the price of not reporting a slow
   * machine as a broken product.
   *
   * NOT the same problem as the watcher exclusion in vite.config.ts. That one
   * was real and separate — Playwright's own report writes were making the dev
   * server reload the page under test — but fixing it left this count
   * unchanged, because this was always the cause.
   */
  expect: { timeout: 15_000 },
  projects: [
    // Mints the sessions once. Logging in per test tripped the login
    // endpoint's rate limiter, which is a control worth keeping.
    { name: 'setup', testMatch: /auth\.setup\.ts/ },

    // Sign-in itself must be driven through the real form by a visitor with
    // no session, so this project deliberately carries none.
    {
      name: 'anonymous',
      testMatch: /auth\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },

    {
      name: 'chromium',
      testIgnore: /auth\.(setup|spec)\.ts/,
      use: {
        ...devices['Desktop Chrome'],
        // Slot-keyed: see the note in auth.setup.ts. A saved session belongs to
        // one origin AND one copy of the register, so two slots cannot share.
        storageState: `e2e/.auth/${process.env.E2E_SLOT ?? 'default'}/admin.json`,
      },
      dependencies: ['setup'],
    },
  ],
})
