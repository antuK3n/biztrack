import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { OFFICE_COLUMNS, OFFICES, type OfficeCode } from '../src/pages/admin/permitColumns'

/*
 * Every answer an office sheet holds reaches the register table.
 *
 * ── Why this file exists ──────────────────────────────────────────────────
 *
 * The client's instruction was "make sure iba iba yan kada offices kung ano
 * man need na permit at mga data ron" — each office's columns must actually be
 * that office's own fields, and all of them.
 *
 * The first cut of the table was built by reading the applicant's form and
 * copying the labelled boxes, and that method quietly missed three groups:
 *
 *   - the whole DENR block on the CEC sheet (five answers), because the sheet
 *     renders it as a NARRATIVE PANEL rather than labelled fields, so a sweep
 *     of `<FieldLabel>` never saw it;
 *   - `site_is_rented` on the zoning sheet, derived from the business rather
 *     than asked on the sheet; and
 *   - `building_permit_date` and `fsec_date` on the occupancy sheet, which the
 *     OFFICE writes during review and the applicant never sees at all.
 *
 * All three are exactly the data an office needs on its own permit, and none
 * of them was findable by looking at the form. So the check is made against
 * the SOURCE OF THE ANSWERS instead — `OfficeFormAnswers::derive` and the
 * form's own `set(...)` calls — and it runs every time the suite does.
 *
 * ── What it does NOT do ───────────────────────────────────────────────────
 *
 * It does not check labels or order. Those are judgement, and a test that
 * pinned them would have to be edited every time a heading is reworded. It
 * checks that no answer an office holds is missing a column, which is the
 * failure that loses data silently.
 */

const API = new URL('../../api/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')
const WEB = new URL('../', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')

const read = (path: string) => readFileSync(path, 'utf8')

/**
 * The keys `OfficeFormAnswers::derive` writes, per permit type code.
 *
 * Parsed from the PHP rather than duplicated here: a list retyped in a test is
 * a list that stops matching the thing it describes, and this one is the whole
 * point of the check.
 */
function derivedKeys(): Record<string, string[]> {
  const php = read(`${API}app/Support/OfficeFormAnswers.php`)
  const out: Record<string, string[]> = {}
  let current: string | null = null

  for (const line of php.split('\n')) {
    const branch = line.match(/permitTypeCode === '([A-Z]+)'/)
    if (branch) current = branch[1]

    const write = line.match(/\$derived\['([a-z0-9_]+)'\]/)
    if (write && current) {
      out[current] ??= []
      if (!out[current].includes(write[1])) out[current].push(write[1])
    }
  }

  return out
}

/**
 * The keys each office's FIELD BODY writes, from `set('key', …)`.
 *
 * The bodies are found by their function names and their extent taken to the
 * next top-level `function`, which is how this file is laid out.
 */
function typedKeys(): Record<string, string[]> {
  const tsx = read(`${WEB}src/pages/applicant/OfficeFormStep.tsx`).split('\n')
  const bodies: Record<string, string> = {
    ZONING: 'ZoningFields',
    SANITARY: 'SanitaryFields',
    CEC: 'CecFields',
    FSIC: 'FsicFields',
    OCCUPANCY: 'OccupancyFields',
  }

  const starts = tsx
    .map((line, i) => ({ i, name: line.match(/^(?:export )?function ([A-Za-z]+)/)?.[1] }))
    .filter((row) => row.name)

  const out: Record<string, string[]> = {}

  for (const [code, fn] of Object.entries(bodies)) {
    const at = starts.findIndex((row) => row.name === fn)
    expect(at, `no ${fn} in OfficeFormStep.tsx`).toBeGreaterThanOrEqual(0)

    const from = starts[at].i
    const to = starts[at + 1]?.i ?? tsx.length
    const body = tsx.slice(from, to).join('\n')

    out[code] = [...body.matchAll(/set\('([a-z0-9_]+)'/g)].map((m) => m[1])
  }

  return out
}

/** The issuance dates an OFFICE records during review, per permit type. */
function officerKeys(): Record<string, string[]> {
  const tsx = read(`${WEB}src/pages/officer/ReviewPage.tsx`)
  const block = tsx.match(/OFFICER_DATE_FIELDS[^=]*=\s*\{([\s\S]*?)\n\}/)
  expect(block, 'no OFFICER_DATE_FIELDS in ReviewPage.tsx').not.toBeNull()

  const out: Record<string, string[]> = {}
  let current: string | null = null

  for (const line of (block as RegExpMatchArray)[1].split('\n')) {
    const head = line.match(/^\s*([A-Z]+):\s*\[/)
    if (head) current = head[1]
    const key = line.match(/key:\s*'([a-z0-9_]+)'/)
    if (key && current) {
      out[current] ??= []
      out[current].push(key[1])
    }
  }

  return out
}

/*
 * Answers that are deliberately NOT columns, and why each one is not.
 *
 * Every entry here is a decision, not a backlog. A key added to this list
 * without a reason beside it is a column quietly dropped.
 */
const NOT_COLUMNS: Record<string, string> = {
  // On every sheet, and already a SHARED column — "Date of Application" — so
  // repeating it under each office would be the same date five times.
  application_date: 'shared column',
  // Internal codes ('scale', 'catch_all') that steer a sentence on the
  // applicant's screen. A cell reading "catch_all" says nothing to anybody.
  denr_reason: 'internal code, not a value',
  // Says which sheet the representative's name came FROM, not an answer. The
  // name itself is the column beside it.
  authorized_representative_source: 'provenance of another answer',
}

test.describe('the register table carries every office answer', () => {
  const derived = derivedKeys()
  const typed = typedKeys()
  const officer = officerKeys()

  for (const { code, office } of OFFICES) {
    const columns = OFFICE_COLUMNS[code as OfficeCode]

    test(`${office} (${code}) has a column for every answer its sheet holds`, () => {
      const expected = [
        ...(derived[code] ?? []),
        ...(typed[code] ?? []),
        ...(officer[code] ?? []),
      ].filter((key, i, all) => all.indexOf(key) === i && !(key in NOT_COLUMNS))

      if (code === 'BUSINESS') {
        // The one permit type with no sheet at all. Nothing to carry, and the
        // API answers `office_form: null` rather than an empty object.
        expect(expected).toEqual([])
        expect(columns).toEqual([])
        return
      }

      expect(expected.length, `${code} holds no answers — the parser probably missed its body`).toBeGreaterThan(0)

      /*
       * Read the key each column actually reads, out of its own source. Going
       * through the column's `value` function would need a fixture row per
       * key; the literal is what the column is for.
       */
      const source = read(`${WEB}src/pages/admin/permitColumns.ts`)
      const start = source.indexOf(`  ${code}: [`)
      expect(start, `no ${code} block in permitColumns.ts`).toBeGreaterThan(0)
      const end = source.indexOf('\n  ],', start)
      const block = source.slice(start, end)

      const covered = [...block.matchAll(/answer(?:Date)?\(r, '([a-z0-9_]+)'\)/g)].map((m) => m[1])

      const missing = expected.filter((key) => !covered.includes(key))
      expect(
        missing,
        `${office}'s sheet holds ${missing.join(', ')} and the table has no column for it`,
      ).toEqual([])
    })

    if (code !== 'BUSINESS') {
      test(`${office} (${code}) reads no other office's answers`, () => {
        /*
         * The other direction. The sheets share field names —
         * `application_type` is on four of the five — so a column copied
         * between offices and left pointing at a key its own sheet never holds
         * would render as a permanent dash that looks like missing data.
         */
        const held = new Set([
          ...(derived[code] ?? []),
          ...(typed[code] ?? []),
          ...(officer[code] ?? []),
        ])

        const source = read(`${WEB}src/pages/admin/permitColumns.ts`)
        const start = source.indexOf(`  ${code}: [`)
        const end = source.indexOf('\n  ],', start)
        const block = source.slice(start, end)
        const reads = [...block.matchAll(/answer(?:Date)?\(r, '([a-z0-9_]+)'\)/g)].map((m) => m[1])

        const stray = reads.filter((key) => !held.has(key))
        expect(
          stray,
          `${office}'s columns read ${stray.join(', ')}, which its sheet never holds`,
        ).toEqual([])
      })
    }

    test(`${office} (${code}) column keys are unique across the table`, () => {
      // The key is the React key and the header/body pairing. A duplicate
      // silently drops a column from the render.
      const keys = columns.map((c) => c.key)
      expect(new Set(keys).size).toBe(keys.length)
    })
  }

  test('no two offices are given the same set of columns', () => {
    /*
     * The client's instruction in one assertion: "make sure iba iba yan kada
     * offices". Five sheets that happened to carry identical columns would
     * satisfy every check above and still be wrong.
     */
    const shapes = OFFICES.filter(({ code }) => OFFICE_COLUMNS[code as OfficeCode].length > 0).map(
      ({ code }) => OFFICE_COLUMNS[code as OfficeCode].map((c) => c.label).join('|'),
    )

    expect(shapes.length).toBeGreaterThan(1)
    expect(new Set(shapes).size).toBe(shapes.length)
  })
})
