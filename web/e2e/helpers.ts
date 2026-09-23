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
 * The three sign-in doors. `admin` is the super admin's and nobody else's.
 *
 * It was part of `staff` until checklist item #107 split it out, and the split
 * is enforced server-side — an administrator posting `portal: 'staff'` is now
 * answered 409, which is exactly how this suite's setup broke the first time.
 */
export type E2EPortal = 'public' | 'staff' | 'admin'

/**
 * Sign in through the API and hand the token to the app.
 *
 * Driving the login form would test the form on every single spec instead of
 * once, and would make a change to the sign-in page fail forty unrelated
 * tests. auth.spec.ts drives the real form; everything else takes this door.
 *
 * The portal argument is not cosmetic: the server refuses an account at a door
 * it does not belong to, so passing the wrong one here fails in a way that
 * looks like bad credentials — and at the CITIZEN door it now fails with the
 * same 422 and the same sentence as a wrong password (item #63), so there is
 * nothing in the failure to hint that the portal was the problem.
 */
export async function signIn(
  page: Page,
  account: keyof typeof ACCOUNTS,
  portal: E2EPortal = 'staff',
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

  // Keyed by portal: the three sites hold separate sessions (see lib/api.ts).
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
 *
 * All three portals share ONE origin — they are path prefixes on the same host
 * — so every key lands in the same `origins` entry and the collision check is
 * the only thing keeping two sessions from overwriting each other. It is doing
 * real work: `admin.json` carries two keys (see auth.setup.ts), so a future
 * fixture that also writes `biztrack.token.staff` would be caught here rather
 * than producing a spec that signs in as the wrong person.
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

/**
 * A draft complete enough to be walked to the end of the wizard and submitted:
 * every field the earlier steps require, and a fee profile to price it by.
 *
 * The wizard refuses a forward jump over an unfinished section, which is the
 * right behaviour and the reason this exists — reaching Review & Submit by
 * clicking through six sections of form-filling would be a test of the form,
 * not of the step.
 *
 * It no longer uploads the documentary requirements, and that is a measured
 * change rather than a corner cut. `ApplicationController::submit` gates on RA
 * 10173 consent and on the business not being blocked, and on nothing else — a
 * filing with no documents at all submits through the API (verified against
 * this stack). Five PDF uploads per fixture, ten times a run, bought nothing any
 * assertion below reads.
 *
 * The WIZARD is a different matter and still wants them: its section map
 * refuses a forward jump over an unfinished section, so a draft with no
 * documents opens at Documentary Requirements with Review & Submit shut. The
 * tests that walk the wizard call `uploadRequiredDocuments` for exactly that
 * reason — in clearances.spec.ts and the review-step tests in
 * apply-wizard.spec.ts, which is why both helpers live here.
 */
export async function makeCompleteDraft(page: Page): Promise<number> {
  return page.evaluate(async () => {
    const token = localStorage.getItem('biztrack.token.public')
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    }
    const json = async (res: Response) => (await res.json()).data

    const barangays = await json(await fetch('/api/v1/reference/barangays', { headers }))
    const allPsic = await json(await fetch('/api/v1/reference/psic-codes', { headers }))
    /*
     * Anything but 00000. The catch-all "Other (not listed)" row carries a NULL
     * revenue-code category, and the address step refuses a line filed under it
     * — 35 of the 36 business-tax rules match on that category, so a filing
     * carrying one would be assessed no business tax at all.
     */
    const psic = allPsic.filter((c: { code: string }) => c.code !== '00000')
    const permitTypes = await json(await fetch('/api/v1/reference/permit-types', { headers }))
    const business = await json(
      await fetch('/api/v1/businesses', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          name: `E2E Wizard Clearances ${Date.now()}`,
          registration_type: 'DTI',
          registration_number: 'DTI-E2E-002',
          // Required at submission since 23 September (client: Trade Name is mandatory).
          trade_name: 'E2E Trade Name',
          /*
           * Business Information's other required answers, so the section map
           * will walk to Review & Submit. Items 11 (the sole proprietor, since
           * a DTI registration is one) and 13–15 (the President / OIC, asked of
           * every structure now), plus the business's own mobile and e-mail.
           * Without them the draft opened with part 3 unfinished and this file's
           * one wizard walk stopped at a shut Review button.
           */
          owner: { surname: 'Dela Cruz', given_name: 'Ana', gender: 'F' },
          president_officer_name: 'Ana Dela Cruz',
          citizenship: 'Filipino',
          capital_participation_filipino: 100,
          tin: '123-456-789-000',
          address: {
            line1: '2 Playwright St.',
            mobile_number: '+639171234567',
            email: 'e2e-wizard@example.com',
            /*
             * Longos by name, not `barangays[0]`. There is no bounding box any
             * more: the address step checks the real city polygon AND that the
             * pin agrees with the chosen barangay. These coordinates are Malabon
             * City Hall, which is in Longos; Acacia — first alphabetically — is
             * about 1.5 km off and would now be refused.
             */
            barangay_id: (barangays.find((b: { name: string }) => b.name === 'Longos') ?? barangays[0])
              .id,
            latitude: 14.6572,
            longitude: 120.9573,
          },
          emergency_contact_name: 'Ana Dela Cruz',
          emergency_contact_number: '0917 123 4567',
          /*
           * ── Three fields the wizard will not walk past without ─────────────
           *
           * Added because the one test in this file that drives the WIZARD could
           * not reach Review & Submit any more: the section map refuses a
           * forward jump over an unfinished section, and this fixture left two
           * of the seven unfinished — Location & Zoning and Business Operation,
           * with the latter unreachable behind the former.
           *
           * All three are on the BUSINESS payload rather than the application's,
           * which is why they sit here and not in the fee profile below.
           *
           * `economic_organization` is BPLO item B6 and `capital_investment` is
           * B7, both made required on 9 September 2026 with the rest of the
           * paper fields. B7 in particular moved: it replaced the per-line
           * capitalisation that used to be asked on the fee step.
           *
           * `products_services` is required per line from the same date, and it
           * is the one worth not getting wrong. The PSIC code says what CATEGORY
           * a trade falls in; this says what the business actually sells, and
           * three offices print it on their paper — CENRO's form has a
           * PRODUCTS/SERVICES box beside LINE OF BUSINESS and it reached them
           * empty on the filing that prompted the change. "Retail sale in
           * non-specialized stores" tells a sanitary inspector nothing about
           * whether there is food on the premises.
           */
          economic_organization: 'single_establishment',
          capital_investment: 500000,
          lines: [
            {
              psic_code_id: psic[0].id,
              capitalization: 500000,
              products_services: 'milk tea, fried snacks',
            },
          ],
        }),
      }),
    )
    const businessType = permitTypes.find((pt: { code: string }) => pt.code === 'BUSINESS')
    const app = await json(
      await fetch('/api/v1/applications', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          business_id: business.id,
          application_type: 'new',
          permit_type_ids: [businessType.id],
          /*
           * The FIRST of the two gates at submit, and it is a column on the
           * application rather than browser state (`ApplicationController::
           * submit` refuses a filing whose `data_privacy_consent` is not true
           * before it looks at anything else). A fixture built through the API
           * has no wizard to tick it, so it is set here — without it every test
           * in this file failed at `makePaidApplication` with a 422 about RA
           * 10173 consent, which says nothing about clearances.
           */
          data_privacy_consent: true,
          fee_profile: {
            business_structure: 'sole_proprietorship',
            floor_area_sqm: 120,
            employees: 12,
            employees_in_lgu: 6,
            /*
             * B2's split, which joined the headcount as a required answer and is
             * the last thing that kept Business Operation unfinished for this
             * fixture. It has to RECONCILE — `feeProfileIssues` refuses a split
             * that does not add up to the total three fields above it, and the
             * API applies the same rule to both halves — so 7 and 5 against a
             * total of 12 rather than any two numbers.
             */
            male_employees: 7,
            female_employees: 5,
            lines: [
              { psic_code_id: psic[0].id, category: 'retailer', capitalization: 500000 },
            ],
          },
        }),
      }),
    )

    return app.id as number
  })
}

/**
 * Every required documentary requirement, so the wizard's documents step is
 * done and its section map is walkable to Review & Submit.
 *
 * Real PDF magic bytes, not a text blob renamed .pdf: the API validates with
 * `mimes:pdf`, which sniffs the content rather than trusting the name.
 */
export async function uploadRequiredDocuments(page: Page, appId: number): Promise<void> {
  await page.evaluate(async (id) => {
    const token = localStorage.getItem('biztrack.token.public')
    const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` }
    const types = (
      await (await fetch('/api/v1/reference/permit-types', { headers })).json()
    ).data as {
      code: string
      document_types: { id: number; code: string; is_required?: boolean; context?: string }[]
    }[]
    const businessType = types.find((pt) => pt.code === 'BUSINESS')!

    const pdf = '%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n'
    /*
     * The documents the wizard asks of THIS draft — a new filing, owner
     * occupied, no tax incentives — by the same context tokens its
     * `requiredDocs` reads. Uploading only the `all` ones left the "new" and
     * "owned" requirements outstanding, so Documentary Requirements stayed
     * unfinished and Review & Submit stayed shut.
     */
    const applies = (context?: string) => {
      const tokens = (context ?? '')
        .split(',')
        .map((t) => t.trim())
        .filter(Boolean)

      return (
        tokens.length === 0 ||
        tokens.some((t) => ['all', 'new', 'owned'].includes(t) || t.toUpperCase() === 'BUSINESS')
      )
    }
    for (const dt of businessType.document_types) {
      if (dt.is_required === false || !applies(dt.context)) continue
      const body = new FormData()
      body.append('document_type_id', String(dt.id))
      body.append('file', new File([pdf], `${dt.code}.pdf`, { type: 'application/pdf' }))
      const res = await fetch(`/api/v1/applications/${id}/documents`, {
        method: 'POST',
        headers,
        body,
      })
      if (!res.ok) throw new Error(`uploading ${dt.code} answered ${res.status}`)
    }
  }, appId)
}
