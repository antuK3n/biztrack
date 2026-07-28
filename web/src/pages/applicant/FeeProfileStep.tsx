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
  business_structure: string
  /** Keyed by psic_code_id of the Part 2 line of business. */
  categories: Record<number, FeeCategoryDraft>
  floor_area_sqm: string
  employees: string
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

/** Assemble the API fee_profile payload from the draft inputs. */
export function buildFeeProfile(
  draft: FeeProfileDraft,
  opts: {
    applicationType: ApplicationType
    /** Selected permit-type codes (BUSINESS, OCCUPANCY, MARKET, …). */
    permitCodes: string[]
    /** psic_code_id of each Part 2 line, in order. */
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
      // psic_code_id keys the line back to the Part 2 selection so a reopened
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
  const categories: Record<number, FeeCategoryDraft> = {}
  ;(profile.lines ?? []).forEach((line, index) => {
    const id = line.psic_code_id ?? lineIds[index]
    if (id == null) return
    categories[id] = {
      category: line.category ?? '',
      gross_sales: str(line.gross_sales),
      capitalization: str(line.capitalization),
    }
  })
  const flags = profile.flags ?? []
  return {
    business_structure: profile.business_structure ?? '',
    categories,
    floor_area_sqm: str(profile.floor_area_sqm),
    employees: str(profile.employees),
    delivery_vehicles_motorized: str(profile.delivery_vehicles_motorized),
    delivery_vehicles_other: str(profile.delivery_vehicles_other),
    occupancy_group: profile.occupancy_group ?? '',
    construction_cost: str(profile.construction_cost),
    stall_count: str(profile.stall_count),
    flags: flags.filter((f) => f !== 'no_gross_sales_declared'),
    no_gross_sales: flags.includes('no_gross_sales_declared'),
  }
}

/**
 * Required fields for this step: business structure, a category per line, and
 * gross sales (renewal) or capitalization (new). Counts and flags are optional.
 */
export function feeProfileMissing(
  draft: FeeProfileDraft,
  opts: { applicationType: ApplicationType; lines: { id: number; title: string }[] },
): string[] {
  const missing: string[] = []
  if (!draft.business_structure) missing.push('Business Structure')
  for (const line of opts.lines) {
    const cat = draft.categories[line.id]
    if (!cat?.category.trim()) {
      missing.push(`Category for ${line.title}`)
    } else if (
      opts.applicationType === 'renewal' &&
      !draft.no_gross_sales &&
      !cat.gross_sales.trim()
    ) {
      missing.push(`Gross sales for ${line.title}`)
    } else if (opts.applicationType === 'new' && !cat.capitalization.trim()) {
      missing.push(`Capitalization for ${line.title}`)
    }
  }
  return missing
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

export function FeeProfileStep({
  applicationType,
  permitCodes,
  lines,
  value,
  onChange,
}: {
  applicationType: ApplicationType
  /** Selected permit-type codes. */
  permitCodes: string[]
  /** Part 2 lines of business: psic_code_id + display title. */
  lines: { id: number; title: string }[]
  value: FeeProfileDraft
  onChange: (next: FeeProfileDraft) => void
}) {
  const isRenewal = applicationType === 'renewal'
  const isNew = applicationType === 'new'
  const hasBusiness = permitCodes.includes('BUSINESS')
  const hasOccupancy = permitCodes.includes('OCCUPANCY')
  const hasMarket = permitCodes.includes('MARKET')

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
                          placeholder="e.g. retailer"
                          className={inputCls}
                        />
                      </div>
                      {isRenewal && (
                        <div>
                          <FieldLabel required={!value.no_gross_sales}>
                            Gross Sales, Preceding Year (₱)
                          </FieldLabel>
                          <input
                            inputMode="decimal"
                            value={cat.gross_sales}
                            onChange={(e) => setCategory(line.id, { gross_sales: e.target.value })}
                            placeholder="0.00"
                            disabled={value.no_gross_sales}
                            className={`${inputCls} disabled:opacity-50`}
                          />
                        </div>
                      )}
                      {isNew && (
                        <div>
                          <FieldLabel required>Capitalization (₱)</FieldLabel>
                          <input
                            inputMode="decimal"
                            value={cat.capitalization}
                            onChange={(e) =>
                              setCategory(line.id, { capitalization: e.target.value })
                            }
                            placeholder="0.00"
                            className={inputCls}
                          />
                        </div>
                      )}
                    </div>
                  </div>
                )
              })}
              {lines.length === 0 && (
                <p className="text-sm text-ink-muted">
                  No lines of business selected yet. Add them in the Line of Business section.
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

      {/* ── BUSINESS permit: premises & operations ───────────────────────── */}
      {hasBusiness && (
        <section>
          <SectionMarker letter={nextLetter()} label="Premises & Operations" />
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
              <FieldLabel>Floor Area (sqm)</FieldLabel>
              <input
                inputMode="decimal"
                value={value.floor_area_sqm}
                onChange={(e) => set('floor_area_sqm', e.target.value)}
                placeholder="e.g. 45"
                className={inputCls}
              />
            </div>
            <div>
              <FieldLabel>Number of Employees</FieldLabel>
              <input
                inputMode="numeric"
                value={value.employees}
                onChange={(e) => set('employees', e.target.value)}
                placeholder="e.g. 3"
                className={inputCls}
              />
            </div>
            <div>
              <FieldLabel>Motorized Delivery Vehicles</FieldLabel>
              <input
                inputMode="numeric"
                value={value.delivery_vehicles_motorized}
                onChange={(e) => set('delivery_vehicles_motorized', e.target.value)}
                placeholder="0"
                className={inputCls}
              />
            </div>
            <div>
              <FieldLabel>Other Delivery Vehicles (pedicab, cart)</FieldLabel>
              <input
                inputMode="numeric"
                value={value.delivery_vehicles_other}
                onChange={(e) => set('delivery_vehicles_other', e.target.value)}
                placeholder="0"
                className={inputCls}
              />
            </div>
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
              <FieldLabel>Occupancy Group</FieldLabel>
              <select
                value={value.occupancy_group}
                onChange={(e) => set('occupancy_group', e.target.value)}
                className={inputCls}
              >
                <option value="">Select occupancy group…</option>
                {OCCUPANCY_GROUPS.map((g) => (
                  <option key={g.value} value={g.value}>
                    {g.label}
                  </option>
                ))}
              </select>
            </div>
            {value.occupancy_group === 'j1' ? (
              hasBusiness ? (
                <p className="self-end pb-2.5 text-xs text-ink-secondary">
                  Group J-1 is assessed by floor area. The floor area you declared above is used.
                </p>
              ) : (
                <div>
                  <FieldLabel>Floor Area (sqm)</FieldLabel>
                  <input
                    inputMode="decimal"
                    value={value.floor_area_sqm}
                    onChange={(e) => set('floor_area_sqm', e.target.value)}
                    placeholder="e.g. 120"
                    className={inputCls}
                  />
                </div>
              )
            ) : (
              <div>
                <FieldLabel>Construction Cost (₱)</FieldLabel>
                <input
                  inputMode="decimal"
                  value={value.construction_cost}
                  onChange={(e) => set('construction_cost', e.target.value)}
                  placeholder="0.00"
                  className={inputCls}
                />
              </div>
            )}
          </div>
        </section>
      )}

      {/* ── MARKET clearance ─────────────────────────────────────────────── */}
      {hasMarket && (
        <section>
          <SectionMarker letter={nextLetter()} label="Market Stall Details" />
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
              <FieldLabel>Number of Stalls</FieldLabel>
              <input
                inputMode="numeric"
                value={value.stall_count}
                onChange={(e) => set('stall_count', e.target.value)}
                placeholder="e.g. 1"
                className={inputCls}
              />
            </div>
          </div>
        </section>
      )}
    </div>
  )
}
