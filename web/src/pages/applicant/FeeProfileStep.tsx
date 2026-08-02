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
  category: string
  gross_sales: string
  capitalization: string
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
  employees: string
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
  employees: '',
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

/** Common revenue-code category slugs the API recognizes (datalist hints). */
const COMMON_CATEGORIES = [
  'retailer',
  'essential_retailer',
  'wholesaler',
  'carinderia',
  'restaurant',
  'cafe_cafeteria',
  'fastfood_chain',
  'food_peddler',
  'manufacturer',
  'small_scale_manufacturing',
  'contractor',
  'service_establishment',
  'franchise_holder',
  'gasoline_station',
  'water_refilling_station',
  'internet_cafe',
  'barber_shop',
  'tailor_dress_shop',
  'laundry_dry_cleaning',
  'vulcanizing_shop',
  'vehicle_repair_shop',
  'junkshop',
  'lessor',
  'hotel',
  'pawnshop',
  'bank',
  'private_hospital',
  'medical_clinic',
  'dental_clinic',
  'printing_publication',
]

const OCCUPANCY_GROUPS: { value: string; label: string }[] = [
  { value: 'a1', label: 'Group A-1: Residential, single dwelling' },
  { value: 'a2', label: 'Group A-2: Residential, multiple dwelling' },
  { value: 'b', label: 'Group B: Hotels, apartments, lodging houses' },
  { value: 'c', label: 'Group C: Education & recreation' },
  { value: 'd', label: 'Group D: Institutional' },
  { value: 'e', label: 'Group E: Commercial / mercantile' },
  { value: 'f', label: 'Group F: Light industrial' },
  { value: 'g', label: 'Group G: Storage & hazardous' },
  { value: 'h', label: 'Group H: Assembly (under 1,000 occupants)' },
  { value: 'i', label: 'Group I: Assembly (1,000 or more occupants)' },
  { value: 'j1', label: 'Group J-1: Agricultural (fee by floor area)' },
  { value: 'j2', label: 'Group J-2: Accessory buildings' },
]

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
  {
    value: 'stores_flammables',
    label: 'Stores flammable materials',
    hint: 'Keeps flammable or combustible goods on site. Adds the storage permit fee.',
  },
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
    lines: { id: number; title: string }[]
  },
): FeeProfileIssue[] {
  const issues: FeeProfileIssue[] = []
  const push = (issue: FeeProfileIssue | null) => {
    if (issue) issues.push(issue)
  }
  const isRenewal = opts.applicationType === 'renewal'
  const isNew = opts.applicationType === 'new'
  const has = (code: string) => opts.permitCodes.includes(code)

  if (!draft.business_structure) {
    issues.push({
      key: 'business_structure',
      label: 'Business Structure',
      message: 'Choose how your business is registered.',
    })
  }

  for (const line of opts.lines) {
    const cat = draft.categories[line.id] ?? { category: '', gross_sales: '', capitalization: '' }
    if (!cat.category.trim()) {
      issues.push({
        key: `line:${line.id}:category`,
        label: `Category for ${line.title}`,
        message: 'Type the closest revenue-code category, for example retailer.',
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
    if (isNew) {
      push(
        numericIssue({
          key: `line:${line.id}:capitalization`,
          label: `Capitalization for ${line.title}`,
          value: cat.capitalization,
          required: true,
          blankMessage: 'Enter the capital you are putting into this line, in pesos.',
          positive: true,
          max: MAX_PESOS,
          maxMessage: 'That is higher than this form accepts. Check the amount in pesos.',
        }),
      )
    }
  }

  if (has('BUSINESS')) {
    push(
      numericIssue({
        key: 'floor_area_sqm',
        label: 'Floor Area',
        value: draft.floor_area_sqm,
        required: true,
        blankMessage: 'Enter the floor area of your premises in square metres.',
        positive: true,
        max: MAX_FLOOR_AREA,
        maxMessage: 'Enter the floor area in square metres, not square centimetres.',
      }),
    )
    push(
      numericIssue({
        key: 'employees',
        label: 'Number of Employees',
        value: draft.employees,
        required: true,
        blankMessage: 'Enter how many people you employ. Enter 0 if you work alone.',
        integer: true,
        max: MAX_COUNT,
        maxMessage: 'Enter a headcount below 100,000.',
      }),
    )
    push(
      numericIssue({
        key: 'employees_in_lgu',
        label: 'Employees Residing in Malabon',
        value: draft.employees_in_lgu,
        required: false,
        blankMessage: '',
        integer: true,
        max: MAX_COUNT,
        maxMessage: 'Enter a headcount below 100,000.',
      }),
    )
    const total = toInt(draft.employees)
    const inLgu = toInt(draft.employees_in_lgu)
    if (total !== undefined && inLgu !== undefined && inLgu > total) {
      issues.push({
        key: 'employees_in_lgu',
        label: 'Employees Residing in Malabon',
        message: 'This can’t be more than your total number of employees.',
      })
    }
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

  if (has('MARKET')) {
    push(
      numericIssue({
        key: 'stall_count',
        label: 'Number of Stalls',
        value: draft.stall_count,
        required: true,
        blankMessage: 'Enter how many stalls you are applying for.',
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
    /** Selected permit-type codes (BUSINESS, OCCUPANCY, MARKET, …). */
    permitCodes: string[]
    /** psic_code_id of each declared line of business, in order. */
    lineIds: number[]
  },
): FeeProfile {
  const isRenewal = opts.applicationType === 'renewal'
  const isNew = opts.applicationType === 'new'
  const has = (code: string) => opts.permitCodes.includes(code)

  const lines: FeeProfileLine[] = []
  for (const id of opts.lineIds) {
    const cat = draft.categories[id]
    if (!cat?.category.trim()) continue
    lines.push({
      // psic_code_id keys the line back to the Location & Zoning selection so a reopened
      // draft restores each category onto the right line of business.
      psic_code_id: id,
      category: cat.category.trim(),
      ...(isRenewal ? { gross_sales: toNumber(cat.gross_sales) } : {}),
      ...(isNew ? { capitalization: toNumber(cat.capitalization) } : {}),
    })
  }

  const flags = [...draft.flags]
  if (isRenewal && draft.no_gross_sales) flags.push('no_gross_sales_declared')

  return {
    ...(lines.length > 0 ? { lines } : {}),
    ...(draft.business_structure
      ? { business_structure: draft.business_structure as FeeProfile['business_structure'] }
      : {}),
    ...(has('BUSINESS')
      ? {
          floor_area_sqm: toNumber(draft.floor_area_sqm),
          employees: toInt(draft.employees),
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
    ...(has('MARKET') ? { stall_count: toInt(draft.stall_count) } : {}),
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
    }
  })
  const flags = profile.flags ?? []
  return {
    business_structure: profile.business_structure ?? '',
    categories,
    floor_area_sqm: str(profile.floor_area_sqm),
    employees: str(profile.employees),
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
export function feeProfileMissing(
  draft: FeeProfileDraft,
  opts: {
    applicationType: ApplicationType
    permitCodes: string[]
    lines: { id: number; title: string }[]
  },
): string[] {
  return [...new Set(feeProfileIssues(draft, opts).map((issue) => issue.label))]
}

/* ── Small local pieces (match the wizard's form-sheet language) ────────── */

function SectionMarker({ letter, label }: { letter: string; label: string }) {
  return (
    <div className="flex items-center gap-2.5">
      <span className="flex h-6 w-6 items-center justify-center rounded-sm bg-royal text-[13px] font-bold text-white">
        {letter}
      </span>
      <h2 className="text-[15px] font-bold text-ink">{label}</h2>
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

/** Inline error under a field, in the wizard's voice. */
function FieldError({ children }: { children: string }) {
  return <p className="mt-1 text-xs font-medium text-s-red">{children}</p>
}

/**
 * A number input that formats as it is typed: money groups in thousands,
 * counts stay whole. Nothing but digits (and a decimal point for money) can
 * be entered, so a stray letter never reaches the fee engine.
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
  kind: 'money' | 'count'
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
  const format = kind === 'money' ? formatAmountInput : formatCountInput
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
          inputMode={kind === 'money' ? 'decimal' : 'numeric'}
          value={value}
          onChange={(e) => onChange(format(e.target.value))}
          onBlur={onBlur}
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
  paymentMode,
  onPaymentModeChange,
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
  /** Lines of business declared in Location & Zoning: psic_code_id + title. */
  lines: { id: number; title: string }[]
  value: FeeProfileDraft
  onChange: (next: FeeProfileDraft) => void
  paymentMode: 'annual' | 'quarterly'
  onPaymentModeChange: (next: 'annual' | 'quarterly') => void
}) {
  const isRenewal = applicationType === 'renewal'
  const isNew = applicationType === 'new'
  const hasBusiness = permitCodes.includes('BUSINESS')
  const hasOccupancy = permitCodes.includes('OCCUPANCY')
  const hasMarket = permitCodes.includes('MARKET')
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

  function toggleFlag(flag: string) {
    set(
      'flags',
      value.flags.includes(flag) ? value.flags.filter((f) => f !== flag) : [...value.flags, flag],
    )
  }

  let sectionLetter = 'C'.charCodeAt(0) // sections continue after C · Documents
  const nextLetter = () => String.fromCharCode(++sectionLetter)

  return (
    <div className="space-y-8">
      <p className="-mt-2 text-xs text-ink-secondary">
        These details compute your Tax Order of Payment from the New Revenue Code of Malabon
        (Ord. A10-2016). Everything here is declared by you and verified by the reviewing officer.
      </p>

      {/* ── Structure + per-line classification ─────────────────────────── */}
      <section>
        <SectionMarker letter={nextLetter()} label="Business Structure & Classification" />
        <div className="mt-4 space-y-5">
          {derivedStructure ? (
            /*
             * Item 72 — the answer, not the question again.
             *
             * "Type of Registration" (Business Information) and "Business
             * Structure" (here) are one fact with two names, and asking twice
             * invited two answers that the fee engine and the registration
             * record would then disagree about. It is shown rather than hidden
             * because it changes the tax: a cooperative and a sole
             * proprietorship are assessed differently, and the applicant is
             * signing for this figure.
             *
             * `readOnly`, never `disabled`: a disabled input drops out of the
             * tab order and most screen readers pass over it, so the applicant
             * who most needs to hear what this step assumed about them would be
             * the one who never reaches it. Same reason as the carried-over
             * fields on the office sheets and the locked gross-sales box below.
             */
            <div className="sm:max-w-sm">
              <label className="block">
                <FieldLabel>
                  Business Structure
                  <span className="font-normal text-ink-muted"> (from your application)</span>
                </FieldLabel>
                <input
                  value={derivedStructure.label}
                  readOnly
                  aria-readonly="true"
                  className={`${inputCls} cursor-not-allowed bg-line/60 text-ink-secondary`}
                />
              </label>
              <p className="mt-1 text-xs text-ink-muted">
                Taken from the Type of Registration you chose in Business Information. To change it,
                go back to that section.
              </p>
            </div>
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

          <div>
            <FieldLabel required>Line of Business Classification</FieldLabel>
            <p className="mb-3 text-xs text-ink-secondary">
              Type the closest revenue-code category for each line (e.g. retailer, carinderia,
              manufacturer). Not sure? Pick your best match. The reviewing officer verifies the
              classification during assessment.
            </p>
            <datalist id="fee-categories">
              {COMMON_CATEGORIES.map((c) => (
                <option key={c} value={c} />
              ))}
            </datalist>
            <div className="space-y-3">
              {lines.map((line) => {
                const cat = value.categories[line.id] ?? {
                  category: '',
                  gross_sales: '',
                  capitalization: '',
                }
                return (
                  <div
                    key={line.id}
                    className="rounded-lg border border-input-border bg-royal-tint px-4 py-3.5"
                  >
                    <p className="mb-2.5 truncate text-sm font-semibold text-ink">{line.title}</p>
                    <div className="grid gap-3 sm:grid-cols-2">
                      <div>
                        <FieldLabel required>Category</FieldLabel>
                        <input
                          list="fee-categories"
                          value={cat.category}
                          onChange={(e) => setCategory(line.id, { category: e.target.value })}
                          onBlur={() => touch(`line:${line.id}:category`)}
                          placeholder="e.g. retailer"
                          className={inputCls}
                          aria-invalid={Boolean(errorFor(`line:${line.id}:category`, cat.category))}
                        />
                        {errorFor(`line:${line.id}:category`, cat.category) && (
                          <FieldError>
                            {errorFor(`line:${line.id}:category`, cat.category)}
                          </FieldError>
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
                      {isNew && (
                        <NumberField
                          label="Capitalization (₱)"
                          required
                          kind="money"
                          value={cat.capitalization}
                          onChange={(next) => setCategory(line.id, { capitalization: next })}
                          onBlur={() => touch(`line:${line.id}:capitalization`)}
                          error={errorFor(`line:${line.id}:capitalization`, cat.capitalization)}
                          placeholder="0.00"
                        />
                      )}
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

      {/* ── BUSINESS permit: how the business tax is settled ─────────────── */}
      {hasBusiness && (
        <section>
          <SectionMarker letter={nextLetter()} label="How You Want to Pay" />
          <div>
            <FieldLabel>Mode of Payment</FieldLabel>
            <div className="flex flex-wrap gap-2">
              {(
                [
                  { value: 'annual', label: 'Annually', hint: 'One payment, on or before January 20.' },
                  {
                    value: 'quarterly',
                    label: 'Quarterly',
                    hint: 'Four payments, within the first 20 days of January, April, July and October.',
                  },
                ] as const
              ).map((opt) => {
                const selected = paymentMode === opt.value
                return (
                  <button
                    key={opt.value}
                    type="button"
                    aria-pressed={selected}
                    onClick={() => onPaymentModeChange(opt.value)}
                    className={`flex max-w-xs flex-col items-start gap-1 rounded-md border px-4 py-2.5 text-left transition-colors ${
                      selected
                        ? 'border-royal bg-input text-ink'
                        : 'border-input-border bg-input/60 text-ink-secondary hover:bg-input'
                    }`}
                  >
                    <span className="text-sm font-medium">{opt.label}</span>
                    <span className="text-xs text-ink-secondary">{opt.hint}</span>
                  </button>
                )
              })}
            </div>
            <p className="mt-2 text-xs text-ink-secondary">
              The business tax may be settled in full or in four instalments (Malabon Revenue Code
              Sec. 2N). Regulatory fees are due with the first payment either way.
            </p>
          </div>
        </section>
      )}

      {/* ── BUSINESS permit: premises & operations ───────────────────────── */}
      {hasBusiness && (
        <section>
          <SectionMarker letter={nextLetter()} label="Premises & Operations" />
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <NumberField
              label="Floor Area (sqm)"
              required
              kind="money"
              value={value.floor_area_sqm}
              onChange={(next) => set('floor_area_sqm', next)}
              onBlur={() => touch('floor_area_sqm')}
              error={errorFor('floor_area_sqm', value.floor_area_sqm)}
              placeholder="e.g. 45"
            />
            <NumberField
              label="Number of Employees"
              required
              kind="count"
              value={value.employees}
              onChange={(next) => set('employees', next)}
              onBlur={() => touch('employees')}
              error={errorFor('employees', value.employees)}
              placeholder="e.g. 3"
            />
            {/* Unified form asks this separately; some LGU incentives key off it. */}
            <NumberField
              label="Employees Residing in Malabon"
              kind="count"
              value={value.employees_in_lgu}
              onChange={(next) => set('employees_in_lgu', next)}
              onBlur={() => touch('employees_in_lgu')}
              error={errorFor('employees_in_lgu', value.employees_in_lgu)}
              placeholder="e.g. 2"
            />
            <NumberField
              label="Motorized Delivery Vehicles"
              kind="count"
              value={value.delivery_vehicles_motorized}
              onChange={(next) => set('delivery_vehicles_motorized', next)}
              onBlur={() => touch('delivery_vehicles_motorized')}
              error={errorFor('delivery_vehicles_motorized', value.delivery_vehicles_motorized)}
              placeholder="0"
            />
            <NumberField
              label="Other Delivery Vehicles (pedicab, cart)"
              kind="count"
              value={value.delivery_vehicles_other}
              onChange={(next) => set('delivery_vehicles_other', next)}
              onBlur={() => touch('delivery_vehicles_other')}
              error={errorFor('delivery_vehicles_other', value.delivery_vehicles_other)}
              placeholder="0"
            />
          </div>
          <div className="mt-5">
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

      {/* ── OCCUPANCY permit ─────────────────────────────────────────────── */}
      {hasOccupancy && (
        <section>
          <SectionMarker letter={nextLetter()} label="Occupancy Details" />
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
              <FieldLabel required>Occupancy Group</FieldLabel>
              <select
                value={value.occupancy_group}
                onChange={(e) => set('occupancy_group', e.target.value)}
                onBlur={() => touch('occupancy_group')}
                className={inputCls}
                aria-invalid={Boolean(errorFor('occupancy_group', value.occupancy_group))}
              >
                <option value="">Select occupancy group…</option>
                {OCCUPANCY_GROUPS.map((g) => (
                  <option key={g.value} value={g.value}>
                    {g.label}
                  </option>
                ))}
              </select>
              {errorFor('occupancy_group', value.occupancy_group) && (
                <FieldError>{errorFor('occupancy_group', value.occupancy_group)}</FieldError>
              )}
            </div>
            {value.occupancy_group === 'j1' ? (
              hasBusiness ? (
                <p className="self-end pb-2.5 text-xs text-ink-secondary">
                  Group J-1 is assessed by floor area. The floor area you declared above is used.
                </p>
              ) : (
                <NumberField
                  label="Floor Area (sqm)"
                  required
                  kind="money"
                  value={value.floor_area_sqm}
                  onChange={(next) => set('floor_area_sqm', next)}
                  onBlur={() => touch('floor_area_sqm')}
                  error={errorFor('floor_area_sqm', value.floor_area_sqm)}
                  placeholder="e.g. 120"
                />
              )
            ) : (
              <NumberField
                label="Construction Cost (₱)"
                required
                kind="money"
                value={value.construction_cost}
                onChange={(next) => set('construction_cost', next)}
                onBlur={() => touch('construction_cost')}
                error={errorFor('construction_cost', value.construction_cost)}
                placeholder="0.00"
              />
            )}
          </div>
        </section>
      )}

      {/* ── MARKET clearance ─────────────────────────────────────────────── */}
      {hasMarket && (
        <section>
          <SectionMarker letter={nextLetter()} label="Market Stall Details" />
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <NumberField
              label="Number of Stalls"
              required
              kind="count"
              value={value.stall_count}
              onChange={(next) => set('stall_count', next)}
              onBlur={() => touch('stall_count')}
              error={errorFor('stall_count', value.stall_count)}
              placeholder="e.g. 1"
            />
          </div>
        </section>
      )}
    </div>
  )
}
