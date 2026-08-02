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

  await page.evaluate(
    ([t, p]) => {
      localStorage.setItem('biztrack.token', t)
      localStorage.setItem('biztrack.portal', p)
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
