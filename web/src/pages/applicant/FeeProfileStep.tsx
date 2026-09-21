import { useState } from 'react'
import { FieldLabel, inputCls } from '../../components/ui/Proto'
import type { ApplicationType, FeeProfile, FeeProfileLine } from '../../lib/types'

/*
 * "Business & Tax Profile" wizard step — the applicant-declared inputs the API
 * feeds into the Malabon Revenue Code calculator (fee_profile on the draft
 * application). One screen, grouped with FieldLabel sections; which groups
 * appear adapts to the requested permit types and the application type.
 */

export interface FeeCategoryDraft {
  /**
   * The Revenue Code class, as TYPED by the applicant.
   *
   * Empty for every ordinary filing since 16 September 2026: the class is
   * derived from the line of business server-side, in FeeCalculator::classify.
   * It stays on the draft for the one PSIC code that cannot classify itself —
   * 00000 "Other (not listed)", where the applicant typed their own trade —
   * and so that a draft saved while the question was still asked rehydrates
   * with the answer its applicant gave rather than losing it.
   */
  category: string
  gross_sales: string
  capitalization: string
  /**
   * Sec. 2J.02(c): dealers in ESSENTIAL commodities pay half the rate.
   *
   * The one classification question no industrial code can answer — PSIC
   * cannot tell rice from radios, and a sari-sari store is both at once.
   * Asked only for the 17 codes whose category_branch says so; the other 118
   * either carry the answer in their trade or have no half-rate form at all
   * (a contractor has no cheaper twin).
   */
  essentials: boolean
}

export interface FeeProfileDraft {
  /**
   * Item 72 — mirrored from the business's registration type rather than
   * asked here; see the Business Structure block in FeeProfileStep below.
   */
  business_structure: string
  /** Keyed by psic_code_id of the line of business declared in Location & Zoning. */
  categories: Record<number, FeeCategoryDraft>
  floor_area_sqm: string
  /** BPLO items A? / FSIC / OBO occupancy / CPDD locational all ask it. */
  storeys: string
  employees: string
  /** BPLO B2 (new) / B3 (renewal) and CENRO: the split inside `employees`. */
  male_employees: string
  female_employees: string
  employees_in_lgu: string
  delivery_vehicles_motorized: string
  delivery_vehicles_other: string
  occupancy_group: string
  construction_cost: string
  stall_count: string
  flags: string[]
  no_gross_sales: boolean
}

export const EMPTY_FEE_PROFILE: FeeProfileDraft = {
  business_structure: '',
  categories: {},
  floor_area_sqm: '',
  storeys: '',
  employees: '',
  male_employees: '',
  female_employees: '',
  employees_in_lgu: '',
  delivery_vehicles_motorized: '',
  delivery_vehicles_other: '',
  occupancy_group: '',
  construction_cost: '',
  stall_count: '',
  flags: [],
  no_gross_sales: false,
}

const STRUCTURES = [
  { value: 'sole_proprietorship', label: 'Sole Proprietorship' },
  { value: 'partnership', label: 'Partnership' },
  { value: 'corporation', label: 'Corporation' },
  { value: 'cooperative', label: 'Cooperative' },
]

/*
 * The common Revenue Code categories, each in the words a shop owner would
 * actually use for it.
 *
 * `slug` is what gets STORED and it is not cosmetic. FeeCalculator::matches()
 * (api/app/Services/FeeCalculator.php) does an `array_intersect` of a rule's
 * `business_category` against this exact string, and 35 of the 36 business-tax
 * rules turn on it. Store anything else and the line matches no rule: no
 * error, no warning, just a Tax Order of Payment that is quietly too small.
 * That is why the applicant used to be shown `tailor_dress_shop` — the slug
 * was the only thing safe to put in the box, so it was put in the box.
 *
 * `label` is what the applicant reads and is free to reword, subject to one
 * INVARIANT: normalizeCategory(label) === slug, for every row.
 *
 * That invariant is the whole fix. It is what lets the datalist offer the
 * LABEL as the option value — see the datalist in the render for the browser
 * evidence on why the `label` attribute could not be used instead — and still
 * end up storing the slug. e2e/apply-wizard.spec.ts asserts it over every row
 * here, so a new category whose label does not round-trip fails a test rather
 * than silently costing the city a business tax.
 */
const CATEGORIES: { slug: string; label: string }[] = [
  { slug: 'retailer', label: 'Retailer' },
  { slug: 'essential_retailer', label: 'Essential retailer' },
  { slug: 'wholesaler', label: 'Wholesaler' },
  { slug: 'carinderia', label: 'Carinderia' },
  { slug: 'restaurant', label: 'Restaurant' },
  { slug: 'cafe_cafeteria', label: 'Cafe / cafeteria' },
  { slug: 'fastfood_chain', label: 'Fastfood chain' },
  { slug: 'food_peddler', label: 'Food peddler' },
  { slug: 'manufacturer', label: 'Manufacturer' },
  { slug: 'small_scale_manufacturing', label: 'Small-scale manufacturing' },
  { slug: 'contractor', label: 'Contractor' },
  { slug: 'service_establishment', label: 'Service establishment' },
  { slug: 'franchise_holder', label: 'Franchise holder' },
  { slug: 'gasoline_station', label: 'Gasoline station' },
  { slug: 'water_refilling_station', label: 'Water refilling station' },
  { slug: 'internet_cafe', label: 'Internet cafe' },
  { slug: 'barber_shop', label: 'Barber shop' },
  { slug: 'tailor_dress_shop', label: 'Tailor / dress shop' },
  { slug: 'laundry_dry_cleaning', label: 'Laundry / dry cleaning' },
  { slug: 'vulcanizing_shop', label: 'Vulcanizing shop' },
  { slug: 'vehicle_repair_shop', label: 'Vehicle repair shop' },
  { slug: 'junkshop', label: 'Junkshop' },
  { slug: 'lessor', label: 'Lessor' },
  { slug: 'hotel', label: 'Hotel' },
  { slug: 'pawnshop', label: 'Pawnshop' },
  { slug: 'bank', label: 'Bank' },
  { slug: 'private_hospital', label: 'Private hospital' },
  { slug: 'medical_clinic', label: 'Medical clinic' },
  { slug: 'dental_clinic', label: 'Dental clinic' },
  { slug: 'printing_publication', label: 'Printing & publication' },
]

/** Exported for the test that holds the label→slug round-trip to account. */
export const FEE_CATEGORIES = CATEGORIES

const CATEGORY_LABEL_BY_SLUG = new Map(CATEGORIES.map((c) => [c.slug, c.label]))

/**
 * Human words in, the slug the fee engine matches on out.
 *
 * "Tailor / dress shop" → "tailor_dress_shop". The field is free text on
 * purpose — the Revenue Code has 273 categories against the 30 offered here,
 * and the reviewing officer checks what was typed — so this has to be kind to
 * an answer that is not on the list: "Sari-sari store" → "sari_sari_store" is
 * the right outcome, an empty box is not.
 *
 * Letters and digits are kept as letters and digits, hence \p{L}\p{N} rather
 * than [a-z0-9]: with the ASCII class "Piña" would come out "pi_a", which
 * throws away a letter the officer needs to read the answer back. Nothing in
 * the reference list is non-ASCII, so this only ever affects free text, where
 * preserving what was typed matters more than looking like the seeded slugs.
 */
/**
 * The declared categories whose fees are priced PER STALL.
 *
 * Every one of them is a `conditions.business_category` value on a seeded
 * FeeRule whose `basis` is `stall_count`: the mayor's-permit market and
 * fish-broker-market brackets, and garbage Schedule J's two public-market rows
 * and its private-market row. Five rules, all of them gated on the BUSINESS
 * permit.
 *
 * ── Why this moved here on 6 September 2026 ────────────────────────────────
 *
 * The stall count used to be asked whenever the applicant had selected the
 * MARKET permit type. That was always the wrong question and the removal of the
 * Market Clearance made it an impossible one: these five rules price a business
 * permit for someone who OPERATES a market, and operating a market is not the
 * same as holding a Market Clearance for a stall — the clearance was for the
 * tenant, these fees are for the landlord. Gating on the clearance meant a
 * market operator who never touched that card was billed a flat business permit
 * fee instead of one per stall, and a stall holder who did touch it was asked
 * how many stalls they ran.
 *
 * Asking off the declared category fixes both, and ties the question to the
 * only thing that actually consumes the answer. If no rule with
 * `basis: stall_count` survives a future revenue-code revision, this list and
 * the field it gates should go with them.
 */
export const STALL_PRICED_CATEGORIES = [
  'public_market_100_plus_stalls',
  'public_market_under_100_stalls',
  'private_market',
  'fish_broker_market',
]

/** Does any line of business declare a category that is priced per stall? */
export function needsStallCount(
  categories: Record<number, { category: string }>,
): boolean {
  return Object.values(categories).some((c) =>
    STALL_PRICED_CATEGORIES.includes(normalizeCategory(c.category ?? '')),
  )
}

export function normalizeCategory(text: string): string {
  return text
    .toLowerCase()
    .replace(/[^\p{L}\p{N}]+/gu, '_')
    .replace(/^_+|_+$/g, '')
}

/**
 * The other direction, for what the input SHOWS. A known slug reads back as
 * its label; anything else — a free-typed trade, or a draft saved before this
 * screen offered labels at all — reads back as itself with the underscores
 * opened out, because showing a reopened draft `sari_sari_store` would be the
 * bug this fix is about, one step removed.
 */
function categoryDisplayText(slug: string): string {
  if (!slug) return ''
  const known = CATEGORY_LABEL_BY_SLUG.get(slug)
  if (known) return known
  const words = slug.replace(/_+/g, ' ').trim()

  return words.charAt(0).toUpperCase() + words.slice(1)
}

/*
 * The twelve National Building Code occupancy groups were listed here, for a
 * select that no longer exists — see the note where Occupancy Details used to
 * be drawn. They are recoverable from git and from occupancy.json, which is
 * where the fee rules that read the group live; keeping an unused option list
 * next to a deleted field only invites someone to wire it back up without
 * finding out why it went.
 */

/** User-settable feature flags with one-line plain-language explanations. */
const BUSINESS_FLAGS: { value: string; label: string; hint: string }[] = [
  {
    value: 'sells_liquor',
    label: 'Sells or serves liquor',
    hint: 'Wine, beer, or spirits on the menu or the shelf. Adds the liquor license fee.',
  },
  {
    value: 'is_ambulant_vendor',
    label: 'Ambulant vendor or peddler',
    hint: 'You sell while moving around rather than from a fixed stall or store. Exempt from the zoning clearance fee.',
  },
  {
    value: 'sells_tobacco_retail',
    label: 'Sells tobacco at retail',
    hint: 'Cigarettes or tobacco sold per piece or pack to consumers.',
  },
  {
    value: 'sells_tobacco_wholesale',
    label: 'Sells tobacco at wholesale',
    hint: 'Tobacco products sold in bulk to resellers.',
  },
  {
    value: 'has_signage',
    label: 'Has a signboard or billboard',
    hint: 'Any sign displayed at the premises. Adds the signage fee.',
  },
  /*
   * "Stores flammable materials" is gone. It added nothing and could not:
   * its seven fire-code rules are each priced on a quantity no screen
   * collects — flammables_liters, film_units, celluloid_units, carbide_cases,
   * tar_kilos, coal_tons, other_combustibles_units — so ticking it moved the
   * total by exactly zero, measured against a filing holding all six
   * clearances. A question whose answer cannot reach a fee is a question that
   * only costs the applicant time. Removed 16 September 2026; if BFP wants
   * these fees, the quantities have to be asked for and that is its own
   * decision.
   */
  {
    value: 'employees_need_health_certificates',
    label: 'Staff need health certificates',
    hint: 'Employees handle food or personal-care services and need individual health cards.',
  },
  {
    value: 'is_bmbe',
    label: 'Registered BMBE',
    hint: 'Barangay Micro Business Enterprise, exempt from the local business tax.',
  },
  {
    value: 'is_cooperative',
    label: 'Registered cooperative',
    hint: 'CDA-registered cooperative, statutory tax exemptions apply.',
  },
]

/** "12,500.50" → 12500.5; blank/invalid → undefined. */
function toNumber(raw: string): number | undefined {
  const t = raw.replace(/[, ]/g, '').trim()
  if (!t) return undefined
  const n = Number(t)
  return Number.isFinite(n) && n >= 0 ? n : undefined
}

function toInt(raw: string): number | undefined {
  const n = toNumber(raw)
  return n === undefined ? undefined : Math.round(n)
}

/* ── Money & count inputs ───────────────────────────────────────────────── */

/*
 * Peso fields are read, not scanned: "1000000" and "10000000" look the same at
 * a glance and an applicant who mistypes a zero pays for it. So amounts group
 * as they are typed and are stripped back to a plain number on the way out
 * (toNumber above, which the API mirrors).
 */
export const MAX_PESOS = 10_000_000_000
export const MAX_COUNT = 100_000
const MAX_FLOOR_AREA = 1_000_000
/*
 * MAX_STOREYS was here, mirroring the API's own ceiling on
 * `fee_profile.storeys`, so the wizard could not let through a number the save
 * would then reject. Both went when the storey count did — see the note where
 * its validation used to run. The API rule stays, because the column and the
 * old drafts that filled it both still exist.
 */

/**
 * "1000000" → "1,000,000"; keeps at most two decimals, drops everything else.
 *
 * Accepts a number as well as a string. This formatter is fed by two very
 * different sources: keystrokes, which are always strings, and saved amounts
 * coming back from the API, which are not — `monthly_rental` arrives as a JSON
 * number while the contract calls it a string. A draft with rented premises
 * used to throw "raw.replace is not a function" here mid-restore, which left
 * the wizard holding a blank form it then tried to save over the real one.
 * A formatter has no business deciding an amount is unusable because of its
 * JSON type.
 */
export function formatAmountInput(raw: string | number | null | undefined): string {
  if (raw === null || raw === undefined) return ''
  const cleaned = String(raw).replace(/[^\d.]/g, '')
  const dot = cleaned.indexOf('.')
  const whole = (dot === -1 ? cleaned : cleaned.slice(0, dot)).replace(/^0+(?=\d)/, '')
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  if (dot === -1) return grouped
  const fraction = cleaned.slice(dot + 1).replace(/\./g, '').slice(0, 2)

  return `${grouped}.${fraction}`
}

/**
 * "1,000" → "1,000.00", once the applicant has left the field.
 *
 * Client checklist item 12: "the auto comma is great, but it should also auto
 * decimal." A peso figure written without its centavos reads as an estimate,
 * and the applicant is about to see the same amount printed with two decimal
 * places on the Tax Order of Payment — the two should not disagree on sight.
 * Reopening a saved draft already shows "1,000.00" (`feeProfileToDraft`'s
 * `money`, which routes through the API's decimal columns), so before this the
 * figure changed shape between typing it and coming back to it.
 *
 * On blur and nowhere else. Padding on a keystroke appends ".00" as soon as the
 * first digit lands, and the caret goes with it — the applicant typing 1000.50
 * left to right gets 1.0005000. Blur is the one moment there is no caret to
 * fight.
 *
 * A value holding no digit is returned exactly as given, blanks included.
 * Turning an empty box into "0.00" would assert a capital investment nobody
 * declared, and saying a required amount is missing is `numericIssue`'s job,
 * not a formatter's.
 *
 * What is POSTed is unaffected: every reader strips the separators before
 * Number() — `toNumber` above, `plainAmount` in ApplyWizard — and the columns
 * behind these fields are decimal(15,2), so "1,000.00" stores as 1000.00.
 */
export function padAmountInput(raw: string): string {
  if (!/\d/.test(raw)) return raw
  const grouped = formatAmountInput(raw)
  const dot = grouped.indexOf('.')
  // ".5" groups to ".5", and a bare leading point is not an amount — say the 0.
  const whole = (dot === -1 ? grouped : grouped.slice(0, dot)) || '0'
  const fraction = dot === -1 ? '' : grouped.slice(dot + 1)

  return `${whole}.${fraction.padEnd(2, '0')}`
}

/** Headcounts, vehicles, stalls: whole numbers only. */
export function formatCountInput(raw: string): string {
  return raw.replace(/\D/g, '').replace(/^0+(?=\d)/, '')
}

/* ── Validation ─────────────────────────────────────────────────────────── */

/** One thing wrong on this step: `label` lists it, `message` says how to fix it. */
export interface FeeProfileIssue {
  key: string
  label: string
  message: string
}

interface NumericRule {
  key: string
  label: string
  value: string
  required: boolean
  blankMessage: string
  max: number
  maxMessage: string
  integer?: boolean
  /** Zero is a real answer for a headcount, never for capital or an area. */
  positive?: boolean
  zeroMessage?: string
}

function numericIssue(rule: NumericRule): FeeProfileIssue | null {
  const fail = (message: string) => ({ key: rule.key, label: rule.label, message })
  const raw = rule.value.trim()
  if (!raw) return rule.required ? fail(rule.blankMessage) : null

  const n = Number(raw.replace(/,/g, ''))
  if (!Number.isFinite(n)) return fail('Enter numbers only, without letters or symbols.')
  if (n < 0) return fail('Enter an amount of zero or more.')
  if (rule.integer && !Number.isInteger(n)) return fail('Enter a whole number.')
  if (rule.positive && n === 0) {
    return fail(rule.zeroMessage ?? 'Enter an amount greater than zero.')
  }
  if (n > rule.max) return fail(rule.maxMessage)

  return null
}

/**
 * Everything wrong on the Business & Tax Profile step. Blank required fields
 * and unusable values are one list on purpose: Next is blocked by both, and
 * the applicant should not have to discover the second kind after fixing the
 * first (tester checklist item 39).
 */
export function feeProfileIssues(
  draft: FeeProfileDraft,
  opts: {
    applicationType: ApplicationType
    permitCodes: string[]
    lines: { id: number; title: string; category?: string | null; categoryBranch?: string | null }[]
  },
): FeeProfileIssue[] {
  const issues: FeeProfileIssue[] = []
  const push = (issue: FeeProfileIssue | null) => {
    if (issue) issues.push(issue)
  }
  const isRenewal = opts.applicationType === 'renewal'
  const has = (code: string) => opts.permitCodes.includes(code)

  if (!draft.business_structure) {
    issues.push({
      key: 'business_structure',
      label: 'Business Structure',
      message: 'Choose how your business is registered.',
    })
  }

  for (const line of opts.lines) {
    const cat = draft.categories[line.id] ?? {
      category: '',
      gross_sales: '',
      capitalization: '',
      essentials: false,
    }
    /*
     * Only where the line of business cannot classify itself.
     *
     * This used to be demanded of every line, because the applicant did the
     * classifying. They do not any more — psic_codes.category carries the
     * Sec. 2J.02 class for 134 of the 135 codes — so requiring a typed answer
     * would block a filing on a question the screen no longer asks. The one
     * exception is 00000 "Other (not listed)", where the applicant typed their
     * own trade and there is nothing to derive from.
     */
    if (line.category == null && !cat.category.trim()) {
      issues.push({
        key: `line:${line.id}:category`,
        // Named as the field is named, or the "Still needed" line sends the
        // applicant looking for a "Category" the step no longer calls that.
        label: `Revenue Code category for ${line.title}`,
        // Cased as the applicant now sees it in the list. It said "for example
        // retailer" while the box offered `retailer`, so the two matched; with
        // the box offering "Retailer" a lower-case example would be the only
        // slug left on the screen.
        message: 'Choose or type the closest Revenue Code category, for example Retailer.',
      })
    }
    if (isRenewal && !draft.no_gross_sales) {
      push(
        numericIssue({
          key: `line:${line.id}:gross_sales`,
          label: `Gross sales for ${line.title}`,
          value: cat.gross_sales,
          required: true,
          blankMessage: 'Enter last year’s gross sales for this line, in pesos.',
          positive: true,
          zeroMessage: 'Tick “I have no gross sales to declare” below instead of entering zero.',
          max: MAX_PESOS,
          maxMessage: 'That is higher than this form accepts. Check the amount in pesos.',
        }),
      )
    }
    // A per-line capitalization check was here and went with the per-line
    // field. Capital Investment is one figure now, asked on Business Operation
    // and checked there — see `capitalInvestmentIssue` below.
  }

  if (has('BUSINESS')) {
    push(
      numericIssue({
        key: 'floor_area_sqm',
        /*
         * Named the way the FIELD names itself, which it was not: the control
         * has read "Business Area (sqm)" throughout and this said "Floor Area",
         * so an applicant sent here by the Review summary was hunting for a
         * heading the step does not have. The occupancy branch below keeps
         * "Floor Area" — that is what the OBO sheet calls it.
         */
        label: '1. Business Area (sq. m.)',
        value: draft.floor_area_sqm,
        required: true,
        blankMessage: 'Enter the floor area of your premises in square metres.',
        positive: true,
        max: MAX_FLOOR_AREA,
        maxMessage: 'Enter the floor area in square metres, not square centimetres.',
      }),
    )
    /*
     * The storey count's validation went with its box.
     *
     * It was optional and bounded, and the reasoning for keeping it optional
     * was that four offices ask for it on paper while the Revenue Code charges
     * by it only for real-estate lessors. Measuring settled the rest: the
     * count moves the total by zero, because those lessor rules need a fine
     * permit category still open with BPLO. The one paper that asks — CPDD's
     * VIII.B — takes the answer on its own sheet now.
     *
     * Nothing validates `draft.storeys` any more, and nothing writes it. It
     * stays on the type so a filing saved while it was asked still hydrates.
     */
    push(
      numericIssue({
        key: 'employees',
        label: '2. Total Number of Employees',
        value: draft.employees,
        required: true,
        blankMessage: 'Enter how many people you employ. Enter 0 if you work alone.',
        integer: true,
        max: MAX_COUNT,
        maxMessage: 'Enter a headcount below 100,000.',
      }),
    )
    /*
     * ── B2 and B3 became REQUIRED on 9 September 2026 ──────────────────────
     *
     * They were optional under this file's standing rule — "none of the paper
     * forms marks any field required; every asterisk in this wizard is our own
     * judgement" — and the client reversed that after watching what it cost:
     * a filing reached CENRO with `employees: 3` and no split at all, so the
     * CENRO sheet printed empty boxes where its paper asks for MALE and FEMALE.
     * The office would hand that back.
     *
     * The split is not extra information. MCG-CENRO-FO-001 reads "TOTAL NO. OF
     * EMPLOYEES: MALE ___ FEMALE ___" — the two boxes ARE the total, which is
     * why they must add up to it below rather than merely not exceed it.
     */
    /*
     * The labels match the field labels word for word, and have to: this is
     * what Review prints under "still missing", so a summary naming "Female
     * Employees" beside a box headed "Number of Female Employees" sends the
     * applicant looking for a field that is not there.
     */
    for (const [key, label] of [
      ['male_employees', '2. Number of Male Employees'],
      ['female_employees', '2. Number of Female Employees'],
    ] as const) {
      push(
        numericIssue({
          key,
          label,
          value: draft[key],
          required: true,
          blankMessage: 'Enter how many of your employees are '
            +(key === 'male_employees' ? 'male' : 'female')
            +'. Enter 0 if none.',
          integer: true,
          max: MAX_COUNT,
          maxMessage: 'Enter a headcount below 100,000.',
        }),
      )
    }
    push(
      numericIssue({
        key: 'employees_in_lgu',
        label: '3. Number of Employees Residing in Malabon',
        value: draft.employees_in_lgu,
        required: true,
        blankMessage: 'Enter how many of your employees live in Malabon. Enter 0 if none.',
        integer: true,
        max: MAX_COUNT,
        maxMessage: 'Enter a headcount below 100,000.',
      }),
    )
    const total = toInt(draft.employees)
    const inLgu = toInt(draft.employees_in_lgu)
    /*
     * The split has to reconcile with the headcount typed three fields above
     * it. Checked only when all three parse, so a half-filled step reports
     * "this is missing" rather than "these do not add up" — being told your
     * arithmetic is wrong before you have finished typing it is worse than
     * being told nothing.
     */
    const male = toInt(draft.male_employees)
    const female = toInt(draft.female_employees)
    if (total !== undefined && male !== undefined && female !== undefined && male + female !== total) {
      issues.push({
        key: 'male_employees',
        label: '2. Number of Male and Female Employees',
        message: `These must add up to your total of ${total}. You have entered ${male + female}.`,
      })
    }
    if (total !== undefined && inLgu !== undefined && inLgu > total) {
      issues.push({
        key: 'employees_in_lgu',
        label: '3. Number of Employees Residing in Malabon',
        message: 'This can’t be more than your total number of employees.',
      })
    }
    /*
     * ── The old "cannot EXCEED the total" rule lived here ─────────────────
     *
     * It was deliberately loose, and the reasoning was sound at the time: the
     * split was OPTIONAL, this step autosaves half-typed, and a
     * must-equal-total rule would have lit up the moment somebody typed the
     * male count and before they reached the female box — an error for not
     * having finished typing.
     *
     * Both halves of that changed together. The client chose must-equal on
     * 9 September 2026 ("TOTAL NO. OF EMPLOYEES: MALE ___ FEMALE ___" — the two
     * boxes ARE the total on CENRO's paper), and requiring both fields is what
     * makes it safe: the check above runs only when male, female and the total
     * all parse, so a half-typed split reports "Number of Female Employees is
     * missing" rather than "your arithmetic is wrong". The typing complaint
     * the loose rule existed to avoid cannot arise.
     *
     * The strict rule is a superset — 3 male + 4 female against a total of 5
     * still fails — so nothing it caught is now let through.
     */
    for (const [key, label] of [
      ['delivery_vehicles_motorized', 'Motorized Delivery Vehicles'],
      ['delivery_vehicles_other', 'Other Delivery Vehicles'],
    ] as const) {
      push(
        numericIssue({
          key,
          label,
          value: draft[key],
          required: false,
          blankMessage: '',
          integer: true,
          max: MAX_COUNT,
          maxMessage: 'Enter a count below 100,000.',
        }),
      )
    }
  }

  if (has('OCCUPANCY')) {
    if (!draft.occupancy_group) {
      issues.push({
        key: 'occupancy_group',
        label: 'Occupancy Group',
        message: 'Choose the occupancy group your building falls under.',
      })
    } else if (draft.occupancy_group === 'j1') {
      if (!has('BUSINESS')) {
        push(
          numericIssue({
            key: 'floor_area_sqm',
            label: 'Floor Area',
            value: draft.floor_area_sqm,
            required: true,
            blankMessage: 'Group J-1 is assessed by floor area. Enter it in square metres.',
            positive: true,
            max: MAX_FLOOR_AREA,
            maxMessage: 'Enter the floor area in square metres, not square centimetres.',
          }),
        )
      }
    } else {
      push(
        numericIssue({
          key: 'construction_cost',
          label: 'Construction Cost',
          value: draft.construction_cost,
          required: true,
          blankMessage: 'Enter the construction cost of the building, in pesos.',
          positive: true,
          max: MAX_PESOS,
          maxMessage: 'That is higher than this form accepts. Check the amount in pesos.',
        }),
      )
    }
  }

  /*
   * Asked off the declared category, not off a permit type — see
   * STALL_PRICED_CATEGORIES. Required when it is asked at all, because a
   * category that is priced per stall cannot be priced without the count: a
   * blank would silently bill a market operator one flat business-permit fee.
   */
  if (needsStallCount(draft.categories)) {
    push(
      numericIssue({
        key: 'stall_count',
        label: 'Number of Stalls',
        value: draft.stall_count,
        required: true,
        blankMessage: 'Enter how many stalls are in the market.',
        integer: true,
        positive: true,
        max: MAX_COUNT,
        maxMessage: 'Enter a count below 100,000.',
      }),
    )
  }

  return issues
}

/** Assemble the API fee_profile payload from the draft inputs. */
export function buildFeeProfile(
  draft: FeeProfileDraft,
  opts: {
    applicationType: ApplicationType
    /** Selected permit-type codes (BUSINESS, OCCUPANCY, ZONING, …). */
    permitCodes: string[]
    /** psic_code_id of each declared line of business, in order. */
    lineIds: number[]
    /**
     * BPLO item B7, asked once on Business Operation — the whole business's
     * capital investment, as typed.
     *
     * It used to be asked PER LINE on this step, and that was our invention:
     * the paper has one box. Two figures for one quantity meant an applicant
     * could enter ₱150k and ₱100k against their lines and ₱250k as the total,
     * with nothing to stop the two disagreeing.
     *
     * `FeeCalculator` needed no change to accept it. Both the `min_capitalization`
     * condition and the `capitalization` basis already read
     * `$line['capitalization'] ?? $profile['capitalization']` — a profile-level
     * figure was always a supported path, we simply never sent one.
     *
     * What DOES change is the arithmetic on a multi-line filing: every line is
     * now priced against the whole capital rather than its own share, so a rule
     * graduated by capitalization can land in a higher bracket. That follows the
     * paper, which asks the city to assess off one total.
     */
    capitalInvestment?: string
  },
): FeeProfile {
  const isRenewal = opts.applicationType === 'renewal'
  const has = (code: string) => opts.permitCodes.includes(code)

  const lines: FeeProfileLine[] = []
  for (const id of opts.lineIds) {
    const cat = draft.categories[id]
    /*
     * EVERY declared line is sent now, not only the ones with a category typed
     * into them. That skip was right while the applicant did the classifying —
     * a line with no answer had nothing to contribute — and is wrong now that
     * the server classifies: dropping the line drops its psic_code_id, which
     * is the only thing the derivation has to go on, and its gross sales with
     * it.
     */
    lines.push({
      // psic_code_id keys the line back to the Location & Zoning selection so a reopened
      // draft restores each category onto the right line of business.
      psic_code_id: id,
      /*
       * Normalised again at the boundary, deliberately, even though the step
       * already stores slugs. This is the last line of code before the value
       * reaches FeeCalculator, and the draft can arrive from somewhere the
       * step never touched — feeProfileToDraft rehydrating a filing saved
       * before this screen offered labels, holding whatever was typed then.
       * Idempotent, so a value that is already a slug passes through unchanged.
       */
      /*
       * Only when the applicant actually typed one, which now means only
       * 00000 "Other (not listed)" and drafts saved under the old question.
       * Sending an empty string would be worse than sending nothing:
       * FeeCalculator::classify leaves an EXISTING category alone by design,
       * so an empty one would suppress the derivation and bill no tax at all.
       *
       * Still normalised at the boundary. This is the last line of code before
       * the value reaches FeeCalculator, and the draft can arrive from
       * somewhere the step never touched — feeProfileToDraft rehydrating a
       * filing saved before this screen offered labels. Idempotent, so a value
       * that is already a slug passes through unchanged.
       */
      ...(cat?.category.trim() ? { category: normalizeCategory(cat.category) } : {}),
      // Sec. 2J.02(c). The server ignores it unless the code branches on it.
      ...(cat?.essentials ? { essentials: true } : {}),
      ...(isRenewal ? { gross_sales: toNumber(cat?.gross_sales ?? '') } : {}),
      // No per-line capitalization: it is one figure at profile level now, and
      // `FeeCalculator` falls back to it for every line.
    })
  }

  const flags = [...draft.flags]
  if (isRenewal && draft.no_gross_sales) flags.push('no_gross_sales_declared')

  return {
    ...(lines.length > 0 ? { lines } : {}),
    // BPLO item B7, at profile level — one figure for the filing, which every
    // line falls back to. See the note on `capitalInvestment` above.
    ...(toNumber(opts.capitalInvestment ?? '') === undefined
      ? {}
      : { capitalization: toNumber(opts.capitalInvestment ?? '') }),
    ...(draft.business_structure
      ? { business_structure: draft.business_structure as FeeProfile['business_structure'] }
      : {}),
    ...(has('BUSINESS')
      ? {
          floor_area_sqm: toNumber(draft.floor_area_sqm),
          employees: toInt(draft.employees),
          male_employees: toInt(draft.male_employees),
          female_employees: toInt(draft.female_employees),
          employees_in_lgu: toInt(draft.employees_in_lgu),
          delivery_vehicles_motorized: toInt(draft.delivery_vehicles_motorized),
          delivery_vehicles_other: toInt(draft.delivery_vehicles_other),
        }
      : {}),
    ...(has('OCCUPANCY')
      ? {
          occupancy_group: draft.occupancy_group || undefined,
          ...(draft.occupancy_group === 'j1'
            ? { floor_area_sqm: toNumber(draft.floor_area_sqm) }
            : { construction_cost: toNumber(draft.construction_cost) }),
        }
      : {}),
    // Sent whenever the declared category is priced per stall, whatever permits
    // the filing carries. See STALL_PRICED_CATEGORIES.
    ...(needsStallCount(draft.categories) ? { stall_count: toInt(draft.stall_count) } : {}),
    flags,
  }
}

/**
 * Reverse of buildFeeProfile: hydrate the draft inputs from a saved
 * fee_profile so reopening a draft restores everything the applicant typed.
 * Lines match by psic_code_id when present, falling back to save order.
 */
export function feeProfileToDraft(
  profile: FeeProfile | null | undefined,
  lineIds: number[],
): FeeProfileDraft {
  if (!profile) return EMPTY_FEE_PROFILE
  const str = (v: number | undefined) => (v != null ? String(v) : '')
  // Money comes back as a plain number and goes straight into a grouped input.
  const money = (v: number | undefined) => (v != null ? formatAmountInput(String(v)) : '')
  const categories: Record<number, FeeCategoryDraft> = {}
  ;(profile.lines ?? []).forEach((line, index) => {
    const id = line.psic_code_id ?? lineIds[index]
    if (id == null) return
    categories[id] = {
      category: line.category ?? '',
      gross_sales: money(line.gross_sales),
      capitalization: money(line.capitalization),
      // Sec. 2J.02(c), as the applicant answered it. Absent on a draft saved
      // before the question existed, which is the same as "No".
      essentials: line.essentials ?? false,
    }
  })
  const flags = profile.flags ?? []
  return {
    business_structure: profile.business_structure ?? '',
    categories,
    floor_area_sqm: str(profile.floor_area_sqm),
    storeys: str(profile.storeys),
    employees: str(profile.employees),
    male_employees: str(profile.male_employees),
    female_employees: str(profile.female_employees),
    employees_in_lgu: str(profile.employees_in_lgu),
    delivery_vehicles_motorized: str(profile.delivery_vehicles_motorized),
    delivery_vehicles_other: str(profile.delivery_vehicles_other),
    occupancy_group: profile.occupancy_group ?? '',
    construction_cost: money(profile.construction_cost),
    stall_count: str(profile.stall_count),
    flags: flags.filter((f) => f !== 'no_gross_sales_declared'),
    no_gross_sales: flags.includes('no_gross_sales_declared'),
  }
}

/** Labels for the wizard's "Still needed on this part" line. */
/*
 * OPERATION_ISSUE_KEYS was here: the set of issue keys belonging to the
 * Business Operation step, which existed only because the fee draft was
 * written by TWO steps and each had to be told which issues were its own —
 * otherwise Business Operation blocked on a Revenue Code category the
 * applicant had not been shown yet.
 *
 * One step writes the whole draft since 16 September 2026, when the Tax
 * Classification & Fees step was removed, so there is no split to describe and
 * no scope to pass. Every caller now asks for the whole draft, which is what
 * Review always asked for: a filing is not submittable while anything is
 * missing, wherever it was meant to be typed.
 */

/**
 * BPLO item B7 — the one capital-investment figure, checked as the per-line
 * capitalization used to be.
 *
 * Exported rather than folded into `feeProfileIssues` because the value does not
 * live in `FeeProfileDraft`: it is `businesses.capital_investment`, held in the
 * wizard's own form state. Same rules as the field it replaced — required on a
 * NEW filing, positive, and bounded by the same ceiling — so the merge did not
 * quietly relax what a new business has to declare.
 *
 * A renewal is not asked: its business tax is assessed on last year's gross
 * sales, not on capital, which is why the old per-line field was `isNew` too.
 */
export function capitalInvestmentMissing(
  value: string,
  applicationType: ApplicationType,
): string[] {
  if (applicationType !== 'new') return []

  const issue = numericIssue({
    key: 'capital_investment',
    // Numbered to match the field, so the 'Still needed on this part'
    // summary names what the applicant can actually find on screen.
    label: '6. Capital Investment',
    /*
     * Coerced, and not defensively — this took the whole wizard down.
     *
     * `businesses.capital_investment` has no cast on the model, so the API
     * sends it as a JSON NUMBER. Hydration wrote it straight into form state
     * with `?? ''`, which does nothing to a number, and the first thing this
     * validator does is call `.trim()` on it: "rule.value.trim is not a
     * function", thrown during render, and with no error boundary in the app
     * the entire page went blank. It only happened on a REOPENED draft of a
     * business that had a figure saved, which is why it survived a typecheck
     * and a full test run.
     *
     * The real fix is at the boundary — hydration formats it as the text field
     * expects — and this stays because a validator that a caller can crash is
     * the wrong shape regardless of who calls it correctly today.
     */
    value: String(value ?? ''),
    required: true,
    blankMessage: 'Enter the capital you are putting into this business, in pesos.',
    positive: true,
    max: MAX_PESOS,
    maxMessage: 'That is higher than this form accepts. Check the amount in pesos.',
  })

  return issue ? [issue.label] : []
}

export function feeProfileMissing(
  draft: FeeProfileDraft,
  opts: {
    applicationType: ApplicationType
    permitCodes: string[]
    lines: { id: number; title: string; category?: string | null; categoryBranch?: string | null }[]
  },
): string[] {
  // The whole draft, always. See the note where the scope filter used to be.
  return [...new Set(feeProfileIssues(draft, opts).map((issue) => issue.label))]
}

/* ── Small local pieces (match the wizard's form-sheet language) ────────── */

/*
 * SectionMarker drew a lettered chip beside a heading — D, E, F — continuing
 * the wizard's own A/B/C lettering. It made sense while these fields were a
 * step of their own; inside Business Operation, whose items are numbered 1-8
 * straight off MCG-BPLO-FO-001, a letter would claim a section of that form
 * which does not exist. Plain headings instead. The component is recoverable
 * from git if a lettered step ever comes back.
 */

/**
 * How the Revenue Code classifies this trade — stated, not asked.
 *
 * ── Why this replaced a question ──────────────────────────────────────────
 *
 * The applicant used to pick their own class from a type-ahead of 273 Revenue
 * Code labels, under the heading "Tax Classification (Malabon Revenue Code)".
 * It was not merely a hard question to put to a shopkeeper. It was MIS-BILLING,
 * because two fee groups key on two different vocabularies and the screen
 * offered one box: `business_tax` matches the 22 broad classes of Sec. 2J.02,
 * `mayors_permit` the 117 fine categories of Sec. 3A.03.
 *
 * Measured on a carinderia with ₱1,200,000 of gross sales and 45 sq. m.:
 * answering "Carinderia" billed ₱2,218.25 and answering "Restaurant" billed
 * ₱11,707.00. The more accurate answer was the one that lost ₱9,750 of
 * business tax — 81% of the bill — and no applicant could get it right,
 * because the correct figure needs both keys at once.
 *
 * The line of business decides both, so `psic_codes.category` and
 * `psic_codes.permit_category` carry them and nobody is asked. See
 * App\Support\TaxClassification for the mapping and its open questions.
 *
 * ── Why it is shown at all, rather than silently applied ──────────────────
 *
 * Because it moves the money. The class picks the schedule the whole business
 * tax is computed from, and an applicant signing for an amount is entitled to
 * see the assumption underneath it — the same reasoning that kept the business
 * structure visible in this step's intro after its field was removed. It is
 * `readOnly`-in-spirit: a statement with the route to change it, which is to
 * change the line of business in Location & Zoning.
 *
 * ── The one question that survives ───────────────────────────────────────
 *
 * Sec. 2J.02(c) halves the rate for dealers in essential commodities, and no
 * industrial classification can tell rice from radios: a sari-sari store sells
 * both. Asked for the 17 codes whose `categoryBranch` says so, and for those
 * only — the other 118 either carry the answer in the trade itself (a pharmacy
 * is always essential, a jeweller never) or have no half-rate form to move to.
 *
 * Yes first, as everywhere else on this wizard, and a real radiogroup rather
 * than two `aria-pressed` toggles: the answers are mutually exclusive and
 * `aria-pressed` would announce two independent switches that never say
 * picking one unpicks the other.
 */
function DerivedTaxClass({
  line,
  essentials,
  onEssentials,
}: {
  line: { title: string; category?: string | null; categoryBranch?: string | null }
  essentials: boolean
  onEssentials: (next: boolean) => void
}) {
  const label = line.category ? categoryDisplayText(line.category) : null
  const asks = line.categoryBranch === 'essentials'

  return (
    <div>
      <FieldLabel>Taxed as</FieldLabel>
      <p className="text-sm font-semibold text-ink">{label}</p>
      <p className="mt-1 text-xs text-ink-secondary">
        Worked out from your line of business under the Malabon Revenue Code. To change it, change
        the line of business in Location &amp; Zoning.
      </p>

      {asks && (
        <div className="mt-3.5">
          <FieldLabel required>Do you mainly sell essential commodities?</FieldLabel>
          <p id={`essentials-help-${line.title}`} className="mb-2 text-xs text-ink-secondary">
            Rice and corn, flour, meat, dairy and processed food, sugar and salt, cooking oil and
            cooking gas, laundry soap and detergents, medicine, fertiliser and other farm inputs,
            animal feeds, school supplies, cement. These are taxed at half the ordinary rate
            (Revenue Code Sec. 2J.02(c)).
          </p>
          <div
            role="radiogroup"
            aria-label="Do you mainly sell essential commodities?"
            aria-describedby={`essentials-help-${line.title}`}
            className="flex flex-wrap gap-2"
          >
            {[
              { value: true, label: 'Yes' },
              { value: false, label: 'No' },
            ].map((opt) => {
              const selected = essentials === opt.value

              return (
                <button
                  key={opt.label}
                  type="button"
                  role="radio"
                  aria-checked={selected}
                  onClick={() => onEssentials(opt.value)}
                  className={`rounded-md border px-4 py-2 text-sm font-medium transition-colors ${
                    selected
                      ? 'border-royal bg-input text-ink'
                      : 'border-input-border bg-input/60 text-ink-secondary hover:bg-input'
                  }`}
                >
                  {opt.label}
                </button>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}

function FlagCheckbox({
  flag,
  checked,
  onToggle,
}: {
  flag: { value: string; label: string; hint: string }
  checked: boolean
  onToggle: () => void
}) {
  return (
    <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-input-border bg-input/50 px-4 py-3 transition-colors hover:bg-input">
      <input
        type="checkbox"
        checked={checked}
        onChange={onToggle}
        className="mt-0.5 h-4 w-4 shrink-0 accent-royal"
      />
      <span className="min-w-0">
        <span className="block text-sm font-semibold text-ink">{flag.label}</span>
        <span className="block text-xs text-ink-secondary">{flag.hint}</span>
      </span>
    </label>
  )
}

/**
 * Inline error under a field, in the wizard's voice. `id` is optional because
 * most callers sit inside a wrapping <label> and are found by proximity; the
 * ones that point an `aria-describedby` at their error pass one.
 */
function FieldError({ children, id }: { children: string; id?: string }) {
  return (
    <p id={id} className="mt-1 text-xs font-medium text-s-red">
      {children}
    </p>
  )
}

/**
 * A number input that formats as it is typed: amounts group in thousands,
 * counts stay whole. Nothing but digits (and a decimal point for the two
 * decimal kinds) can be entered, so a stray letter never reaches the fee engine.
 *
 * `money` and `area` group and accept decimals identically; they differ only on
 * blur, where money pads to centavos and an area does not. A floor area is not
 * currency — "45.00 sqm" claims a precision nobody measured — and it comes back
 * from a saved draft as "45" (`feeProfileToDraft` uses `str` for it, `money`
 * only for the peso fields), so padding it would make the field change shape
 * between typing it and reloading it. That inconsistency is the thing item 12
 * is about; do not fix it by padding both.
 */
function NumberField({
  label,
  required,
  kind,
  value,
  onChange,
  onBlur,
  error,
  placeholder,
  locked,
}: {
  label: string
  required?: boolean
  kind: 'money' | 'area' | 'count'
  value: string
  onChange: (next: string) => void
  onBlur: () => void
  error: string
  placeholder?: string
  /**
   * Inert because another answer on this step has already settled it.
   *
   * `readOnly`, never `disabled`: a disabled input drops out of the tab order
   * and most screen readers skip past it entirely, so an applicant using one
   * would tab from the category straight to the next line of business and never
   * learn that a gross sales field exists, let alone why it is closed. Read-only
   * looks identical and stays announceable — the same reason the carried-over
   * fields on the office sheets use it.
   */
  locked?: boolean
}) {
  const format = kind === 'count' ? formatCountInput : formatAmountInput
  /*
   * A read-only field is still focusable — deliberately, so a screen reader
   * reaches it — so it still blurs. Padding it would rewrite an answer the
   * applicant cannot edit, which is the one place a formatter has no business.
   */
  const commit = () => {
    if (kind === 'money' && !locked) {
      const padded = padAmountInput(value)
      if (padded !== value) onChange(padded)
    }
    onBlur()
  }
  return (
    <div>
      {/*
        FieldLabel renders a span, so the visible label was not attached to
        anything: a screen reader announced the placeholder, or on fields
        without one, nothing at all. Wrapping in a real label associates them
        without needing an id on every field (WCAG 2.1 AA 1.3.1 / 3.3.2, and
        PRODUCT.md's "no placeholder-as-label"). The error stays outside the
        label so it is not read as part of the field's name.
      */}
      <label className="block">
        <FieldLabel required={required}>{label}</FieldLabel>
        <input
          inputMode={kind === 'count' ? 'numeric' : 'decimal'}
          value={value}
          onChange={(e) => onChange(format(e.target.value))}
          onBlur={commit}
          placeholder={placeholder}
          readOnly={locked}
          aria-readonly={locked || undefined}
          aria-invalid={Boolean(error)}
          className={`${inputCls} tnum ${locked ? 'cursor-not-allowed bg-line/60 text-ink-secondary' : ''}`}
        />
      </label>
      {error && <FieldError>{error}</FieldError>}
    </div>
  )
}

export function FeeProfileStep({
  applicationType,
  registrationType,
  permitCodes,
  lines,
  value,
  onChange,
  scope,
}: {
  applicationType: ApplicationType
  /**
   * Item 72 — the Type of Registration answered in Business Information, which
   * IS the business structure. Given, this step shows the answer instead of
   * asking for it again; blank, it asks (see the section below).
   */
  registrationType?: string
  /** Selected permit-type codes. */
  permitCodes: string[]
  /**
   * Lines of business declared in Location & Zoning, each carrying what the
   * Revenue Code does with it.
   *
   * `category` is the Sec. 2J.02 tax class derived from the PSIC code, and
   * `categoryBranch` names the follow-up the Code still forces. Both come
   * straight from the reference table; the step displays them and asks at most
   * one Yes/No, rather than asking the applicant to classify themselves.
   *
   * A null `category` means the code cannot classify itself — only 00000
   * "Other (not listed)", where the applicant typed their own trade — and the
   * old question is asked for that line alone.
   */
  lines: {
    id: number
    title: string
    category?: string | null
    categoryBranch?: string | null
  }[]
  value: FeeProfileDraft
  onChange: (next: FeeProfileDraft) => void
  /**
   * Which half of the fee inputs to draw, and where on the page.
   *
   * `paper` is MCG-BPLO-FO-001 section B's own four figures — business area
   * (B1), the employee counts and their split (B2), how many live in the LGU
   * (B3), delivery units (B4). `extras` is what the fee engine needs that the
   * paper does not ask for: how the trade is taxed, gross sales, and what the
   * business does that carries a fee of its own.
   *
   * Both mount on the Business Operation step, and the split is what keeps
   * them in ORDER. The paper's items 1-8 run in sequence and items 5-8 are
   * drawn by ApplyWizard rather than here, so a single mount would wedge the
   * classification and the fee questions between items 4 and 5. Two mounts put
   * `paper` before item 5 and `extras` after item 8.
   *
   * Until 16 September 2026 `extras` was a step of its own, "Tax
   * Classification & Fees". The client removed it as absent from the paper
   * form, and most of what it held went with it: the mode of payment (stored,
   * read by nothing), the occupancy boxes (never rendered at all), the storey
   * count (priced nothing) and the 273-label classification picker (derived
   * from the line of business now, and mis-billing while it was asked). What
   * survived is small enough to sit with the answers it depends on.
   *
   * One component rather than two files, because all of it writes the same
   * `FeeProfileDraft` and shares `NumberField`, the touched-state tracking and
   * `errorFor`. Splitting it would duplicate those three, and the duplicate
   * would drift the first time a validation rule changed.
   */
  scope: 'paper' | 'extras'
}) {
  const isRenewal = applicationType === 'renewal'
  const hasBusiness = permitCodes.includes('BUSINESS')
  const showStallCount = needsStallCount(value.categories)
  /*
   * The structure carried over from Business Information, matched to its
   * label. Unrecognised values fall through to null and the question is asked
   * normally — showing a raw slug like "sole_proprietorship" read-only would be
   * worse than asking.
   */
  const derivedStructure = STRUCTURES.find((s) => s.value === registrationType) ?? null

  /*
   * Errors surface once a field has been left, or immediately if what is in
   * it cannot be used. A step full of red before the applicant has typed
   * anything reads as an accusation, not as help.
   */
  const [touched, setTouched] = useState<Record<string, boolean>>({})
  const touch = (key: string) => setTouched((t) => ({ ...t, [key]: true }))
  const issues = feeProfileIssues(value, { applicationType, permitCodes, lines })
  const errorFor = (key: string, raw: string) => {
    const issue = issues.find((i) => i.key === key)
    if (!issue) return ''

    return touched[key] || raw.trim() !== '' ? issue.message : ''
  }

  function set<K extends keyof FeeProfileDraft>(key: K, v: FeeProfileDraft[K]) {
    onChange({ ...value, [key]: v })
  }

  function setCategory(id: number, patch: Partial<FeeCategoryDraft>) {
    const current = value.categories[id] ?? { category: '', gross_sales: '', capitalization: '' }
    set('categories', { ...value.categories, [id]: { ...current, ...patch } })
  }

  /*
   * What the category box SHOWS, which is not what it stores.
   *
   * The draft holds the slug — that is the contract with FeeCalculator — so
   * the input cannot be bound straight to it or the applicant would watch
   * "Tailor / dress shop" turn into `tailor_dress_shop` under the cursor, and
   * would not be able to type a space. The typed text lives here instead and
   * the slug is derived from it on every keystroke.
   *
   * Absent means "not typed into on this visit", and the box falls back to
   * reading the stored slug back out — which is how a reopened draft shows
   * words rather than a slug. Keyed by psic_code_id, same as the draft.
   */
  const [categoryText, setCategoryText] = useState<Record<number, string>>({})
  const categoryShown = (id: number, slug: string) =>
    categoryText[id] ?? categoryDisplayText(slug)
  function typeCategory(id: number, typed: string) {
    setCategoryText((t) => ({ ...t, [id]: typed }))
    setCategory(id, { category: normalizeCategory(typed) })
  }

  function toggleFlag(flag: string) {
    set(
      'flags',
      value.flags.includes(flag) ? value.flags.filter((f) => f !== flag) : [...value.flags, flag],
    )
  }

  const onPaper = scope === 'paper'
  const onExtras = scope === 'extras'

  /*
   * Section letters only make sense on the fee half. The operation half is
   * drawn INSIDE the wizard's Section B, which puts up its own marker, so a
   * second lettered heading there would number a subsection as though it were a
   * section of the paper.
   */
  /*
   * The lettered SectionMarkers went with the step. They continued the
   * wizard's own A/B/C lettering, which only made sense while this was a step
   * of its own; inside Business Operation, whose items are numbered 1-8 from
   * the paper, a letter would claim a section MCG-BPLO-FO-001 does not have.
   * Plain headings instead.
   */

  return (
    <div className="space-y-8">
      {onExtras && (
        <p className="-mt-2 text-xs leading-relaxed text-ink-secondary">
          At the counter a clerk works these out and writes the amount into the form&rsquo;s
          &ldquo;Assessed Fee&rdquo; box by hand. BizTrack computes it instead, under the Revenue
          Code (Ord. A10-2016), so it has to ask what the clerk would have determined.
          {derivedStructure && (
            <>
              {' '}
              Assessed as a <span className="font-semibold">{derivedStructure.label}</span> &mdash;
              change that on Business Information.
            </>
          )}
        </p>
      )}

      {/* ── Structure + per-line classification ─────────────────────────── */}
      {onExtras && (
      <section>
        <h2 className="text-[15px] font-bold text-ink">How your trade is taxed</h2>
        <div className="mt-4 space-y-5">
          {derivedStructure ? (
            /*
             * Item 72 — nothing. The ANSWER is in this step's opening line now.
             *
             * This was a read-only `Business Structure` field mirroring the Type
             * of Registration from Business Information, and the argument for it
             * was sound: the structure changes the tax, so the applicant should
             * see what the step assumed about them rather than have it applied
             * silently. The client removed it as duplication on 16 September
             * 2026, and by then the better answer to the same worry had arrived
             * — the estimate panel at the foot of this step shows the actual
             * computed figures, which is what they are really signing for.
             *
             * So the fact survives as one clause of the intro rather than as a
             * repeated field with its own label, hint and read-only box. The
             * VALUE is untouched: ApplyWizard syncs
             * `feeDraft.business_structure` from `form.registration_type` in its
             * own effect, so removing the display removed nothing the fee engine
             * reads. The branch below still ASKS, for the draft that arrived
             * without a structure at all.
             */
            null
          ) : (
            /*
             * Only reachable if the registration type never arrived — a draft
             * saved before it was required, say. Better to ask than to leave the
             * fee engine without a structure it needs.
             */
            <div>
              <FieldLabel required>Business Structure</FieldLabel>
              <div className="flex flex-wrap gap-2.5">
                {STRUCTURES.map((s) => {
                  const selected = value.business_structure === s.value
                  return (
                    <button
                      key={s.value}
                      type="button"
                      aria-pressed={selected}
                      onClick={() => set('business_structure', selected ? '' : s.value)}
                      className={`flex items-center gap-2 rounded-md border px-4 py-2 text-sm font-medium transition-colors ${
                        selected
                          ? 'border-royal bg-input text-ink'
                          : 'border-input-border bg-input/60 text-ink-secondary hover:bg-input'
                      }`}
                    >
                      <span
                        className={`h-3.5 w-3.5 rounded-full border-2 ${
                          selected ? 'border-royal bg-royal' : 'border-input-border bg-white'
                        }`}
                      />
                      {s.label}
                    </button>
                  )
                })}
              </div>
            </div>
          )}

          {/*
            This block used to be headed "Line of Business Classification" and
            its input labelled bare "Category", which read as the step asking
            again what Location & Zoning had already been told. Testers filed it
            alongside the genuinely duplicated capital box. It is not a
            duplicate: the line of business is the trade, this is the bracket the
            Revenue Code taxes that trade under, and there are 273 of these
            against 135 PSIC codes — `psic_codes.category` is empty for all 135,
            so nothing can derive one from the other. PSIC 56101 "Restaurants and
            carinderia" alone fans out to carinderia, restaurant, fastfood_chain,
            cafe_cafeteria and five more, at different rates. Only the words
            changed here; the field, its value and where it is stored did not.
          */}
          <div>
            <FieldLabel required>Tax Classification (Malabon Revenue Code)</FieldLabel>
            <p id="fee-category-help" className="mb-3 text-xs text-ink-secondary">
              Not the line of business again — the Revenue Code bracket it is taxed under. A carinderia
              and a franchised fast-food branch are both food, at different rates. Start typing to
              pick the closest match from the list; the reviewing officer checks it.
            </p>
            {/*
              * The list offers the LABEL as the option value, and the slug is
              * recovered from it by normalizeCategory when the answer is
              * stored. This looks like the wrong way round — `<option
              * value={slug} label={human}>` is the attribute pair that exists
              * for exactly this — so here is why it is not used, measured in
              * Chrome for Testing 1xx on macOS rather than assumed.
              *
              * Chromium renders a datalist row that has a `label` as TWO
              * lines: the value and the label, one above the other. The popup
              * is a separate OS window and sizes itself to what it paints, so
              * it can be measured even though it cannot be screenshotted — one
              * option, one typed character, popup window geometry read back:
              *
              *   value "x",    no label      -> 164 x 58   (one line)
              *   value "x",    label 41 ch   -> 413 x 74   (two lines)
              *   value 41 ch,  label "x"     -> 459 x 74   (two lines, and the
              *                                  width is the VALUE's width)
              *
              * The row grows by a line the moment a label exists, and a long
              * value still drives the popup wide. So `label` does not replace
              * the value on screen in Chromium, it is printed underneath it —
              * the applicant would still read `tailor_dress_shop`, which is
              * the entire complaint. Firefox does substitute the label, which
              * is what makes the attribute look like it works; a fix that only
              * works in one engine is not a fix.
              *
              * Putting the words in `value` is the one thing every engine
              * agrees on: the option's value is what the browser puts in the
              * box, so the applicant sees words everywhere, and the slug is
              * this file's job rather than the browser's.
              */}
            <datalist id="fee-categories">
              {CATEGORIES.map((c) => (
                <option key={c.slug} value={c.label} />
              ))}
            </datalist>
            <div className="space-y-3">
              {lines.map((line) => {
                const cat = value.categories[line.id] ?? {
                  category: '',
                  gross_sales: '',
                  capitalization: '',
                  essentials: false,
                }
                return (
                  <div
                    key={line.id}
                    className="rounded-lg border border-input-border bg-royal-tint px-4 py-3.5"
                  >
                    <p className="mb-2.5 truncate text-sm font-semibold text-ink">{line.title}</p>
                    <div className="grid gap-3 sm:grid-cols-2">
                      <div>
                        {line.category ? (
                          <DerivedTaxClass
                            line={line}
                            essentials={cat.essentials}
                            onEssentials={(next) => setCategory(line.id, { essentials: next })}
                          />
                        ) : (
                          <>
                        {/*
                          FieldLabel renders a span, so this input had a visible
                          label attached to nothing and a screen reader announced
                          the placeholder instead. htmlFor/id fixes that, and it
                          is an id rather than a wrapping <label> because the
                          explanation above has to be reachable by
                          aria-describedby, which needs the input addressable
                          anyway (WCAG 2.1 AA 1.3.1 / 3.3.2). The error joins the
                          description rather than the name: it belongs to what
                          the field is telling you, not to what it is called.
                        */}
                        <label htmlFor={`fee-category-${line.id}`} className="block">
                          <FieldLabel required>Revenue Code category</FieldLabel>
                        </label>
                        {/*
                          Reached only for 00000 "Other (not listed)" and for a
                          draft saved while this was asked of everybody. See the
                          derived block above for why.
                        */}
                        <input
                          id={`fee-category-${line.id}`}
                          list="fee-categories"
                          value={categoryShown(line.id, cat.category)}
                          onChange={(e) => typeCategory(line.id, e.target.value)}
                          onBlur={() => touch(`line:${line.id}:category`)}
                          placeholder="e.g. Retailer"
                          className={inputCls}
                          aria-invalid={Boolean(errorFor(`line:${line.id}:category`, cat.category))}
                          aria-describedby={
                            errorFor(`line:${line.id}:category`, cat.category)
                              ? `fee-category-help fee-category-${line.id}-error`
                              : 'fee-category-help'
                          }
                        />
                        {errorFor(`line:${line.id}:category`, cat.category) && (
                          <FieldError id={`fee-category-${line.id}-error`}>
                            {errorFor(`line:${line.id}:category`, cat.category)}
                          </FieldError>
                        )}
                          </>
                        )}
                      </div>
                      {isRenewal && (
                        <NumberField
                          label="Gross Sales, Preceding Year (₱)"
                          required={!value.no_gross_sales}
                          kind="money"
                          value={cat.gross_sales}
                          onChange={(next) => setCategory(line.id, { gross_sales: next })}
                          onBlur={() => touch(`line:${line.id}:gross_sales`)}
                          error={errorFor(`line:${line.id}:gross_sales`, cat.gross_sales)}
                          placeholder={
                            value.no_gross_sales ? 'Closed — you declared no gross sales' : '0.00'
                          }
                          locked={value.no_gross_sales}
                        />
                      )}
                      {/*
                        A per-line "Capitalization (₱)" was here, asked of every
                        new filing. It is one field now — Capital Investment, on
                        Business Operation — because the paper has one box and
                        two boxes for one quantity could disagree. `capitalization`
                        stays on `FeeCategoryDraft` so a draft saved with per-line
                        figures still loads; nothing writes it any more, and the
                        hydrate sums the old values into the single field.
                      */}
                    </div>
                  </div>
                )
              })}
              {lines.length === 0 && (
                <p className="text-sm text-ink-muted">
                  No lines of business selected yet. Add them in the Location &amp; Zoning section.
                </p>
              )}
            </div>
          </div>

          {isRenewal && (
            <div>
              <label className="flex cursor-pointer items-start gap-3 text-sm text-ink">
                <input
                  type="checkbox"
                  checked={value.no_gross_sales}
                  onChange={(e) => set('no_gross_sales', e.target.checked)}
                  className="mt-0.5 h-4 w-4 shrink-0 accent-royal"
                />
                <span className="font-semibold">
                  I have no gross sales to declare for the preceding year.
                </span>
              </label>
              {value.no_gross_sales && (
                <div className="mt-2.5 rounded-lg border border-s-yellow bg-s-yellow-tint px-4 py-3 text-sm text-amber-800">
                  <span className="font-bold">Heads up:</span> declaring no gross sales routes your
                  assessment to an officer for a Presumptive Income Level (PIL) evaluation, and an
                  unexplained zero declaration can be a ground for denial. Only check this if your
                  business truly had no sales.
                </div>
              )}
            </div>
          )}
        </div>
      </section>
      )}

      {/*
        ── "How You Want to Pay" is gone: nothing read the answer ────────────

        A Mode of Payment picker offered Annually or Quarterly, citing the
        Revenue Code's Sec. 2N instalment option, and wrote the choice to
        `applications.payment_mode`. Nothing in the system ever read it back.
        Not the fee engine, not the Tax Order of Payment, not the payment
        stage, not one fee rule — traced, and the column's only other readers
        were the wizard rehydrating its own state and the API validating the
        write.

        So an applicant could elect quarterly payment, be told regulatory fees
        were due with the first instalment, and then be handed a Tax Order of
        Payment for the full annual amount. A choice the system takes and then
        ignores is worse than no choice: it is a promise, and this one was
        never kept. Removed at the client's instruction, 16 September 2026.

        The instalment option is real and the Code does allow it. If BizTrack
        is to offer it, it is a feature of the fee engine, the TOP and the
        payment stage — not a radio button. `applications.payment_mode` is left
        on the table, unwritten, so rows filed while this existed keep what
        they recorded.
      */}

      {/*
        ── Section B's four figures (B1-B4) ──────────────────────────────────

        Drawn on the wizard's Business Operation step, because that is where the
        paper puts them: B1 Business Area, B2 Total No. of Employees with the
        male/female split, B3 No. of Employees Residing within LGU, B4 No. of
        Delivery Units.

        They are not repeated on the fee step. They feed the fee engine exactly
        as before — `buildFeeProfile` reads one `FeeProfileDraft` whichever step
        wrote it — so moving where they are ASKED changed no calculation.

        Storeys and the business flags stayed behind deliberately: neither is
        anywhere on MCG-BPLO-FO-001, and putting them under a heading that says
        "Business Operation" would claim the paper asks for them.
      */}
      {onPaper && hasBusiness && (
        <section>
          {/*
            ── The four employee counts are ONE question ─────────────────────

            They were four boxes in a two-column grid, in source order, and the
            grid put "Number of Employees" beside "Business Area (sqm)" — two
            unrelated figures reading as a pair — while the three counts that
            ACTUALLY belong with it were spread down the next two rows, the
            total separated from its own breakdown by a floor area and a
            sentence. "Employees Residing in Malabon" also sat alone against an
            empty cell, which reads as a missing field rather than a deliberate
            one.

            They are one question with one arithmetic rule: male + female must
            EQUAL the total, and those residing in Malabon cannot exceed it.
            Both are enforced in feeProfileIssues, and a layout that scatters
            the operands makes its own error message — "these must add up to
            your total of 4" — point at a box that may be off-screen.

            So the total leads and the breakdown sits under it, inside a real
            <fieldset> with a <legend> rather than a styled div: the legend is
            what makes a screen reader announce "Employees, Number of Male
            Employees" instead of the label alone, and that is the only thing
            that conveys the grouping to somebody who cannot see the border.
            Same pattern as the amendment blocks in ApplyWizard.

            Every label says "Number of" at the client's instruction, so each
            one states what it counts without depending on the legend above it —
            which matters on the review sheet and the officer's screen, where
            these values are printed away from this grouping.
          */}
          <div className="grid gap-4 sm:grid-cols-2">
            <NumberField
              label="1. Business Area (sq. m.)"
              required
              kind="area"
              value={value.floor_area_sqm}
              onChange={(next) => set('floor_area_sqm', next)}
              onBlur={() => touch('floor_area_sqm')}
              error={errorFor('floor_area_sqm', value.floor_area_sqm)}
              placeholder="e.g. 45"
            />
          </div>

          {/*
            ── One number per paper item ────────────────────────────────────

            The numbers were on the FIELD labels, so items 2 and 4 printed
            theirs once per box — "2. Number of Male Employees" beside "2.
            Number of Female Employees", and "4." twice over on the delivery
            counts. The client asked for each number to appear once.

            So a paper item that is several boxes is now a GROUP, and the group
            carries the number. Inside it the fields say only what they count.
            Item 3 is its own box on the paper and stays its own field here.

            A visible heading plus an sr-only <legend>, rather than a visible
            legend: a legend is laid out inside the fieldset's top border and
            cut into it on a rounded, filled box — tried, and it looked wrong.
            The legend still has to exist, because a <fieldset> without one
            conveys no grouping to a screen reader at all.
          */}
          <fieldset className="mt-4 rounded-lg border border-line bg-canvas px-4 py-4">
            <legend className="sr-only">Total number of employees</legend>
            <p aria-hidden className="text-[13px] font-semibold text-ink">
              2. Total No. of Employees
            </p>

            {/*
              MCG-CENRO-FO-001 prints "TOTAL NO. OF EMPLOYEES: MALE ___ FEMALE
              ___", so the two halves ARE the total — which is why they must add
              up to it below rather than merely not exceed it.
            */}
            <div className="mt-2 grid gap-4 sm:grid-cols-3">
              <NumberField
                label="Total"
                required
                kind="count"
                value={value.employees}
                onChange={(next) => set('employees', next)}
                onBlur={() => touch('employees')}
                error={errorFor('employees', value.employees)}
                placeholder="e.g. 3"
              />
              <NumberField
                label="Male"
                required
                kind="count"
                value={value.male_employees}
                onChange={(next) => set('male_employees', next)}
                onBlur={() => touch('male_employees')}
                error={errorFor('male_employees', value.male_employees)}
              />
              <NumberField
                label="Female"
                required
                kind="count"
                value={value.female_employees}
                onChange={(next) => set('female_employees', next)}
                onBlur={() => touch('female_employees')}
                error={errorFor('female_employees', value.female_employees)}
              />
            </div>
            <p className="mt-2 text-xs leading-relaxed text-ink-secondary">
              Male and female together must add up to the total.
            </p>
          </fieldset>

          {/* Item 3 is its own box on the paper, so it is its own field here. */}
          <div className="mt-4 sm:w-1/2 sm:pr-2">
            <NumberField
              label="3. No. of Employees Residing within Malabon"
              required
              kind="count"
              value={value.employees_in_lgu}
              onChange={(next) => set('employees_in_lgu', next)}
              onBlur={() => touch('employees_in_lgu')}
              error={errorFor('employees_in_lgu', value.employees_in_lgu)}
            />
          </div>

          {/*
            ── Item 4 is one box on the paper and two here, on purpose ──────

            The client asked why. Because the Revenue Code taxes the two kinds
            at different rates and a single number cannot be assessed:

              biztax.delivery_vehicle_motorized  Sec. 2I.01  P750.00 per unit
              biztax.delivery_vehicle_other      Sec. 2I.01  P100.00 per unit

            The paper gets away with one box because a clerk asks which kind at
            the counter and writes the tax in by hand. BizTrack computes it, so
            it has to know. Same reason the tax step exists at all.

            Grouped under one number, with the rates stated, so the split reads
            as the ordinance's doing rather than as the form asking twice.
          */}
          <fieldset className="mt-4 rounded-lg border border-line bg-canvas px-4 py-4">
            <legend className="sr-only">Number of delivery units</legend>
            <p aria-hidden className="text-[13px] font-semibold text-ink">
              4. No. of Delivery Units
            </p>
            <p className="mt-1 text-xs leading-relaxed text-ink-secondary">
              Counted apart because the Revenue Code taxes them differently &mdash; &#8369;750 a
              year per motor vehicle, &#8369;100 per pedicab or cart (Sec. 2I.01). Leave both at 0
              if you have none.
            </p>
            <div className="mt-2 grid gap-4 sm:grid-cols-2">
              <NumberField
                label="Motorized (truck, van, motor vehicle)"
                kind="count"
                value={value.delivery_vehicles_motorized}
                onChange={(next) => set('delivery_vehicles_motorized', next)}
                onBlur={() => touch('delivery_vehicles_motorized')}
                error={errorFor('delivery_vehicles_motorized', value.delivery_vehicles_motorized)}
                placeholder="0"
              />
              <NumberField
                label="Other (pedicab, cart)"
                kind="count"
                value={value.delivery_vehicles_other}
                onChange={(next) => set('delivery_vehicles_other', next)}
                onBlur={() => touch('delivery_vehicles_other')}
                error={errorFor('delivery_vehicles_other', value.delivery_vehicles_other)}
                placeholder="0"
              />
            </div>
          </fieldset>
        </section>
      )}

      {/*
        ── What is NOT on the paper ──────────────────────────────────────────

        Storeys and the business flags. Four office sheets want the storey count
        (BPLO, the FSIC sheet, the OBO occupancy sheet and the CPDD locational
        sheet) and the flags drive Revenue Code rules — but neither appears on
        MCG-BPLO-FO-001, so neither belongs under the Business Operation heading.
      */}
      {/*
        ── "Number of Storeys" is gone, and the heading with it ──────────────

        It priced nothing. Measured against a filing holding all six
        clearances, changing the storey count moved the total by zero pesos:
        only two active rules read it — a lessor's commercial or residential
        building, by storey — and both need a Sec. 3A.03 fine permit category
        that is one of the sixty still open with BPLO, so neither can fire.

        Its one real consumer was MCG-CPDD-FO-003 line VIII.B, "No. of Storey
        of Building", which used to auto-fill from here. That sheet asks the
        question itself now, and its field is editable — the same fix the
        lessor boxes needed when they were removed from this side.

        `storeys` stays on the draft type so a filing saved while this was
        asked still hydrates, and OfficeFormAnswers still seeds VIII.B from it
        for exactly those filings. Nothing writes it any more.

        The section heading went with the box: "Building & Premises" described
        a storey count, not a list of what a business happens to do.
      */}
      {onExtras && hasBusiness && (
        <section>
          <div>
            <FieldLabel>Which of these apply to your business?</FieldLabel>
            <div className="grid gap-2.5 sm:grid-cols-2">
              {BUSINESS_FLAGS.map((f) => (
                <FlagCheckbox
                  key={f.value}
                  flag={f}
                  checked={value.flags.includes(f.value)}
                  onToggle={() => toggleFlag(f.value)}
                />
              ))}
            </div>
          </div>
        </section>
      )}

      {/*
        ── Occupancy Details is gone, and it never once rendered ─────────────

        An "Occupancy Details" section asked for the Occupancy Group, and then
        either a Floor Area or a Construction Cost depending on the group. It
        was gated on `hasOccupancy`, which reads `permitCodes` — and both mount
        points pass `[BUSINESS_PERMIT_CODE]`, hardcoded, since the clearances
        left the wizard. So the gate has been false for every applicant since,
        and not one of these three boxes has ever been drawn.

        Which means the occupancy fee could never be computed either: three
        rules in occupancy.json price on `construction_cost` and fifteen
        mention `occupancy_group`, and nothing anywhere collects either one.
        That was logged rather than fixed at the client's decision, and the
        decision to stop asking the fee questions here settles it — dead code
        is not a fee input worth keeping.

        `occupancy_group` and `construction_cost` stay on the draft type:
        drafts saved while this section existed still carry those keys, and
        hydrating one must not throw. Nothing writes them now. The option list
        did NOT stay — an unused select's worth of constants beside a deleted
        field is an invitation to wire it back up without finding out why it
        went.

        The Floor Area box here was also a second way to ask paper item B1,
        which Business Operation asks as "Business Area (sq. m.)" into the same
        `floor_area_sqm` key. One quantity, one box.
      */}

      {/*
        ── Stalls, for a business that OPERATES a market ──────────────────────

        Drawn off the declared Revenue Code category, not off a permit type.
        This was gated on the MARKET permit until 6 September 2026, which asked
        the stall TENANT how many stalls they ran and never asked the market
        LANDLORD at all — and it is the landlord whose business permit and
        garbage fee are priced per stall. See STALL_PRICED_CATEGORIES.
      */}
      {onExtras && showStallCount && (
        <section>
          <h2 className="text-[15px] font-bold text-ink">Market Stall Details</h2>
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <NumberField
              label="Number of Stalls"
              required
              kind="count"
              value={value.stall_count}
              onChange={(next) => set('stall_count', next)}
              onBlur={() => touch('stall_count')}
              error={errorFor('stall_count', value.stall_count)}
            />
          </div>
        </section>
      )}
    </div>
  )
}
