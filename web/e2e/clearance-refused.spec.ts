import { expect, request, test, type APIRequestContext, type APIResponse } from '@playwright/test'
import fs from 'node:fs'
import { sessionFor } from './helpers'

/*
 * A clearance an office REFUSED, applied for again from the owner's screen
 * (owner-clearances row 26, office-review row 23).
 *
 * The refused card says "Apply for this permit again below", and the way back
 * from the suspended Business Permit runs through that button. It opened the
 * sheet read-only — "It cannot be changed now that the office has it" — with
 * no Submit, so the owner could not re-apply without staff help. And when the
 * re-application did reach the server, the office's assignment stayed
 * Completed from the refusal, so the permit sat For Approval in nobody's queue.
 *
 * Fire Safety, because its sheet asks the owner for one thing of its own:
 * occupancy and storeys come from the OBO sheet and the checklist is attached
 * before the first hand-in, so ticking the certification is the edit that
 * proves the sheet is a form again, and Submit then carries the press through
 * to the office's queue.
 *
 * The office's half is driven through the API with the sessions auth.setup.ts
 * saved (BPLO and BFP share the staff token key, so they cannot both be in the
 * page's own storage). The owner's half is the screen.
 */

test.use({ storageState: sessionFor('owner') })

type Account = 'owner' | 'bplo' | 'fire'

const BASE = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5199'

function tokenOf(account: Account): string {
  const state = JSON.parse(fs.readFileSync(sessionFor(account), 'utf8')) as {
    origins: { localStorage: { name: string; value: string }[] }[]
  }
  const key = account === 'owner' ? 'biztrack.token.public' : 'biztrack.token.staff'
  for (const origin of state.origins) {
    const hit = origin.localStorage.find((e) => e.name === key)
    if (hit) return hit.value
  }
  throw new Error(`no ${key} saved for ${account}`)
}

async function as(account: Account): Promise<APIRequestContext> {
  return request.newContext({
    baseURL: BASE,
    extraHTTPHeaders: { Accept: 'application/json', Authorization: `Bearer ${tokenOf(account)}` },
  })
}

async function ok<T = unknown>(res: APIResponse, what: string): Promise<T> {
  if (!res.ok()) throw new Error(`${what} answered ${res.status()}: ${await res.text()}`)
  const text = await res.text()
  return (text ? JSON.parse(text).data : null) as T
}

/** A new filing, approved by BPLO and paid, so the clearance stage is open. */
async function paidFiling(): Promise<number> {
  const owner = await as('owner')
  const bplo = await as('bplo')

  const barangays = await ok<{ id: number }[]>(await owner.get('/api/v1/reference/barangays'), 'barangays')
  const psic = (
    await ok<{ id: number; code: string }[]>(await owner.get('/api/v1/reference/psic-codes'), 'psic')
  ).filter((c) => c.code !== '00000')
  const types = await ok<{ id: number; code: string }[]>(
    await owner.get('/api/v1/reference/permit-types'),
    'permit types',
  )
  const businessType = types.find((t) => t.code === 'BUSINESS')
  if (!businessType) throw new Error('no BUSINESS permit type on this register')

  const business = await ok<{ id: number }>(
    await owner.post('/api/v1/businesses', {
      data: {
        name: `Refused Sheet ${Date.now()}`,
        registration_type: 'DTI',
        registration_number: `DTI-RS-${Date.now() % 100000}`,
        tin: '123-456-789-000',
        address: { line1: '6 Refusal St.', barangay_id: barangays[0].id },
        lines: [{ psic_code_id: psic[0].id, capitalization: 500000 }],
      },
    }),
    'business',
  )
  const app = await ok<{ id: number }>(
    await owner.post('/api/v1/applications', {
      data: {
        business_id: business.id,
        application_type: 'new',
        permit_type_ids: [businessType.id],
        data_privacy_consent: true,
        fee_profile: {
          business_structure: 'sole_proprietorship',
          floor_area_sqm: 120,
          employees: 12,
          employees_in_lgu: 6,
        },
      },
    }),
    'application',
  )
  await ok(await owner.post(`/api/v1/applications/${app.id}/submit`), 'submit')

  const queue = await ok<{ id: number; application: { id: number } | null }[]>(
    await bplo.get('/api/v1/assignments?application_status=for_approval&status=pending&per_page=100'),
    'BPLO queue',
  )
  const assignment = queue.find((row) => row.application?.id === app.id)
  if (!assignment) throw new Error(`filing ${app.id} is not on BPLO's queue`)
  await ok(
    await bplo.post(`/api/v1/assignments/${assignment.id}/classification`, { data: { tier: 'simple' } }),
    'classify',
  )
  await ok(await bplo.post(`/api/v1/assignments/${assignment.id}/approve`, { data: {} }), 'BPLO approve')
  await ok(await owner.post(`/api/v1/applications/${app.id}/pay`, { data: { method: 'gcash' } }), 'pay')

  return app.id
}

const PDF = Buffer.from('%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n')

/** A file on every checklist row the sheet's Submit waits for. */
async function satisfyChecklist(appId: number, code: string): Promise<void> {
  const owner = await as('owner')
  const sheets = await ok<
    { permit_type_code: string; requirements?: { code: string | null; blocking?: boolean; satisfied?: boolean }[] }[]
  >(await owner.get(`/api/v1/applications/${appId}/office-forms`), 'sheets')
  const sheet = sheets.find((s) => s.permit_type_code === code)
  for (const row of sheet?.requirements ?? []) {
    if (row.blocking !== true || row.satisfied === true || !row.code) continue
    await ok(
      await owner.post(`/api/v1/applications/${appId}/office-forms/${code}/requirements/${row.code}`, {
        multipart: { file: { name: `${row.code}.pdf`, mimeType: 'application/pdf', buffer: PDF } },
      }),
      `checklist ${row.code}`,
    )
  }
}

/** BFP's queue item on this filing. */
async function bfpAssignment(appId: number): Promise<number> {
  const fire = await as('fire')
  const detail = await ok<{ assignments?: { id: number; department?: { code: string } }[] }>(
    await fire.get(`/api/v1/applications/${appId}`),
    'detail',
  )
  const assignment = (detail.assignments ?? []).find((a) => a.department?.code === 'BFP')
  if (!assignment) throw new Error(`BFP holds no assignment on ${appId}`)
  return assignment.id
}

test('a refused permit, applied for again, opens an editable sheet and goes back to its office', async ({
  page,
}) => {
  const appId = await paidFiling()
  const owner = await as('owner')
  const fire = await as('fire')

  // Owner hands the FSIC sheet in; BFP accepts the papers, visits, and refuses.
  await ok(await owner.post(`/api/v1/applications/${appId}/clearances/FSIC/apply`), 'apply')
  await satisfyChecklist(appId, 'FSIC')
  // The two FSIC answers the OBO sheet owns, saved there and not submitted.
  await ok(
    await owner.put(`/api/v1/applications/${appId}/office-forms/OCCUPANCY`, {
      data: { form_data: { occupancy_type: 'Mercantile', building_storeys: '2' }, submit: false },
    }),
    'OBO answers',
  )
  await ok(
    await owner.put(`/api/v1/applications/${appId}/office-forms/FSIC`, {
      data: { form_data: {}, submit: true },
    }),
    'hand in',
  )
  const assignmentId = await bfpAssignment(appId)
  await ok(await fire.post(`/api/v1/assignments/${assignmentId}/approve`, { data: {} }), 'BFP approve')
  /*
   * Today, as a date: the result is recorded straight after, and a visit
   * takes no result before its booked day. A bare date carries no time, so
   * the office-hours rule has nothing to check; a weekday is still required.
   */
  const today = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  const when = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`
  const visit = await ok<{ id: number }>(
    await fire.post(`/api/v1/applications/${appId}/permits/FSIC/inspection`, { data: { scheduled_at: when } }),
    'book',
  )
  await ok(
    await fire.post(`/api/v1/inspections/${visit.id}/conduct`, {
      data: { result: 'failed', findings: 'No fire exits.' },
    }),
    'conduct',
  )
  await ok(
    await fire.post(`/api/v1/assignments/${assignmentId}/reject`, {
      data: { reason: 'No fire exits.', remedy: 'Build two exits, then apply again.' },
    }),
    'refuse',
  )

  await page.goto(`/applications/${appId}/clearances`)
  await expect(page.getByRole('heading', { name: /lgu clearances/i })).toBeVisible({ timeout: 90_000 })
  await expect(page.getByText(/Apply for this permit again below/)).toBeVisible({ timeout: 30_000 })

  // The card's own advice: apply again. The sheet opens as a form, not a record.
  await page.getByRole('button', { name: /^Apply for the Fire Safety/ }).click()
  const submit = page.getByRole('button', { name: 'Submit to this office' })
  await expect(submit).toBeVisible({ timeout: 30_000 })
  await expect(page.getByText('This is what you submitted to this office.')).toHaveCount(0)
  await page.screenshot({
    path: `${process.env.E2E_SCREENSHOT_DIR ?? '/tmp'}/clearance-refused-reapply.png`,
    fullPage: true,
  })

  const certify = page.getByRole('checkbox', { name: /I hereby certify the correctness/ })
  await certify.check()
  await expect(page.getByText('Your answers are saved automatically. You can leave and come back.')).toBeVisible({
    timeout: 20_000,
  })
  await expect(submit).toHaveAttribute('aria-disabled', 'false')
  await submit.click()
  await page.getByRole('dialog').getByRole('button', { name: 'Yes, submit it' }).click()
  await expect(page.getByRole('dialog')).toBeHidden({ timeout: 30_000 })

  // Back in front of BFP: For Approval, and on the office's For Approval tab.
  const fsic = (
    await ok<{ permit_type: { code: string }; state: string }[]>(
      await owner.get(`/api/v1/applications/${appId}/clearances`),
      'clearances',
    )
  ).find((row) => row.permit_type.code === 'FSIC')
  expect(fsic?.state).toBe('for_approval')

  const forApproval = await ok<{ id: number }[]>(
    await fire.get('/api/v1/assignments?status=pending,in_progress,returned&per_page=200'),
    'BFP queue',
  )
  expect(forApproval.map((row) => row.id)).toContain(assignmentId)
})
