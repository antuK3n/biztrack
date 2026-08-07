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

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:5199'

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
