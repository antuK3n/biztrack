/**
 * The rules a filed answer must satisfy, in one place.
 *
 * ── Why these left ApplyWizard ───────────────────────────────────────────────
 *
 * They were module-level functions inside the wizard, which was fine while the
 * wizard was the only thing that accepted an answer. Since 27 September 2026 it
 * is not: a returned filing is corrected on the status page, field by field,
 * through `POST /applications/{id}/corrections`.
 *
 * Client, 28 September 2026: *"For all fields, this should carry the validation
 * rules from their application fields as well. Ensure consistency."* The only
 * way to mean that is one copy. A second set of regexes on the corrections card
 * would agree on the day it was written and drift afterwards — which is exactly
 * how `ReturnTargets` shipped eight columns that did not exist, with a parity
 * test happily confirming that both copies said the same wrong thing.
 *
 * So the wizard imports these too. Neither side owns them.
 *
 * ── The shape ────────────────────────────────────────────────────────────────
 *
 * The predicates stay booleans, because that is what the wizard's `fieldErrors`
 * map and its "still needed" list already consume. `SCALAR_FIELD_RULES` wraps
 * them in the message-returning form `lib/validation.ts` uses, which is what a
 * single box on a correction card wants.
 */
import { mobileValid, MOBILE_ERROR } from './phone'

/** A lot area in sq. m.: a positive number, commas allowed. Blank is not checked here. */
export function lotAreaValid(raw: string): boolean {
  const n = Number(plainAmount(raw))

  return plainAmount(raw) !== '' && Number.isFinite(n) && n > 0 && n <= 10_000_000
}

/**
 * A percentage between 0 and 100, to two decimals.
 *
 * Blank passes: whether a field is REQUIRED is the form's question, not this
 * one's, and conflating the two is how an optional field ends up refusing to
 * be left empty.
 */
export function percentValid(raw: string): boolean {
  const trimmed = raw.trim()
  if (!trimmed) return true
  if (!/^\d{1,3}(\.\d{1,2})?$/.test(trimmed)) return false
  const n = Number(trimmed)

  return Number.isFinite(n) && n >= 0 && n <= 100
}

/** DTI / SEC / CDA registration number: at least four characters, one of them a digit. */
export function registrationNumberValid(raw: string): boolean {
  const trimmed = raw.trim()

  return trimmed.length >= 4 && /^(?=.*\d)[A-Za-z0-9][A-Za-z0-9 .\-/]*$/.test(trimmed)
}

/** 9 digits, or 12 to 14 with a branch code. Separators are ignored. */
export function tinValid(raw: string): boolean {
  const trimmed = raw.trim()
  if (!/^[\d\s.-]+$/.test(trimmed)) return false
  const digits = trimmed.replace(/\D/g, '').length

  return digits === 9 || (digits >= 12 && digits <= 14)
}

export const TIN_ERROR =
  'Enter a valid TIN: 9 digits, plus a branch code if you have one, like 123-456-789-000.'

/**
 * Philippine contact number: an 11-digit mobile, the same number written +63,
 * or a landline with or without its area code. Deliberately lenient about
 * separators — the point is to catch a typo, not a format.
 */
export function phoneValid(raw: string): boolean {
  const trimmed = raw.trim()
  if (!/^[+\d\s().-]+$/.test(trimmed)) return false
  const digits = trimmed.replace(/\D/g, '').length

  return digits >= 7 && digits <= 13
}

export function websiteValid(raw: string): boolean {
  const trimmed = raw.trim()
  if (!trimmed) return true
  if (trimmed.includes('@') || /\s/.test(trimmed)) return false

  return /^(https?:\/\/)?[a-z0-9-]+(\.[a-z0-9-]+)+(\/\S*)?$/i.test(trimmed)
}

/**
 * As lenient as `websiteValid` and for the same reason: the mistake this field
 * attracts is a wrong KIND of answer — a phone number, a name, a sentence —
 * not a subtly malformed address. Refusing a valid unusual address is a worse
 * failure than accepting a typo an officer will notice.
 */
export function emailValid(raw: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(raw.trim())
}

/** Strip the display separators before an amount goes to the API. */
export function plainAmount(raw: string): string {
  return raw.replace(/,/g, '').trim()
}

/** A positive amount or count. Blank is the caller's question, not this one's. */
function positiveNumberValid(raw: string, max: number): boolean {
  const trimmed = plainAmount(raw)
  if (trimmed === '') return true
  const n = Number(trimmed)

  return Number.isFinite(n) && n >= 0 && n <= max
}

/** A whole number of people, vehicles or similar. */
function countValid(raw: string): boolean {
  const trimmed = plainAmount(raw)
  if (trimmed === '') return true

  return /^\d{1,6}$/.test(trimmed)
}

/**
 * One returnable field's rule, in the message-returning form the correction
 * card renders directly.
 */
export type FieldRule = {
  /** Undefined when the value is acceptable; the message to show when it is not. */
  validate: (value: string) => string | undefined
  /** Mirrors the wizard's own input, so the phone keypad matches. */
  inputMode?: 'text' | 'decimal' | 'numeric' | 'email' | 'url' | 'tel'
  maxLength?: number
  /**
   * Render a SELECT of these instead of a text box.
   *
   * For a field the form asks as a choice. Citizenship is the case this
   * exists for: free text would let a correction put back exactly the
   * "Filipino" / "filipino" / "Pilipino" spread the select was added to
   * stop, and the register counts that field.
   */
  choices?: { value: string; label: string }[]
}

const required = (value: string, label: string): string | undefined =>
  value.trim() === '' ? `${label} is required.` : undefined

/**
 * The fifteen scalar return targets, each carrying the rule its own wizard
 * field carries.
 *
 * Keyed by the `form:` code, the same key `ReturnTargets::SCALAR_FIELDS` uses
 * on the API. A code missing from here gets a required-and-length check only,
 * which is the honest default rather than a silent pass.
 */
export const SCALAR_FIELD_RULES: Record<string, FieldRule> = {
  'form:registration_number': {
    validate: (v) =>
      required(v, 'The registration number')
      ?? (registrationNumberValid(v)
        ? undefined
        : 'Enter the number as printed on the certificate — at least four characters, including a digit.'),
    maxLength: 60,
  },
  'form:tin': {
    // Blank is allowed: item 3 is optional, and an officer asks for it
    // separately when it is left out.
    validate: (v) => (v.trim() === '' || tinValid(v) ? undefined : TIN_ERROR),
    inputMode: 'numeric',
    maxLength: 20,
  },
  'form:name': {
    validate: (v) => required(v, 'The business name'),
    maxLength: 255,
  },
  'form:trade_name': {
    validate: (v) => required(v, 'The trade name'),
    maxLength: 255,
  },
  'form:telephone': {
    validate: (v) =>
      v.trim() === '' || phoneValid(v) ? undefined : 'Enter a landline number that can be rung.',
    inputMode: 'tel',
    maxLength: 30,
  },
  'form:mobile_number': {
    validate: (v) => (v.trim() === '' || mobileValid(v) ? undefined : MOBILE_ERROR),
    inputMode: 'tel',
    maxLength: 20,
  },
  'form:email': {
    validate: (v) =>
      required(v, 'The e-mail address')
      ?? (emailValid(v) ? undefined : 'Enter an e-mail address, like name@example.com.'),
    inputMode: 'email',
    maxLength: 255,
  },
  'form:website': {
    validate: (v) =>
      v.trim() === '' || websiteValid(v)
        ? undefined
        : 'Enter the website as it is typed into a browser, like malabon.gov.ph.',
    inputMode: 'url',
    maxLength: 255,
  },
  'form:president_officer_name': {
    validate: (v) => required(v, 'The name of the President / OIC'),
    maxLength: 255,
  },
  'form:citizenship': {
    validate: (v) => required(v, 'Citizenship'),
    maxLength: 100,
    /*
     * The form's own two options. "Other" is stored as typed on the
     * form; here it is offered as a named choice so the common answer
     * stays one click and the rare one is still reachable.
     */
    choices: [
      { value: 'Filipino', label: 'Filipino' },
      { value: 'Other', label: 'Other — type it below' },
    ],
  },
  'form:capital_participation': {
    validate: (v) =>
      required(v, 'Capital participation')
      ?? (percentValid(v)
        ? undefined
        : 'Enter the Filipino share as a percentage between 0 and 100, like 100 or 60.'),
    inputMode: 'decimal',
    maxLength: 6,
  },
  'form:floor_area_sqm': {
    validate: (v) =>
      lotAreaValid(v) ? undefined : 'Enter the business area in square metres, as a number.',
    inputMode: 'decimal',
    maxLength: 12,
  },
  'form:employees_in_lgu': {
    validate: (v) => (countValid(v) ? undefined : 'Enter a whole number of employees.'),
    inputMode: 'numeric',
    maxLength: 6,
  },
  'form:delivery_units': {
    validate: (v) => (countValid(v) ? undefined : 'Enter a whole number of delivery units.'),
    inputMode: 'numeric',
    maxLength: 6,
  },
  'form:capital_investment': {
    validate: (v) =>
      required(v, 'Capital investment')
      ?? (positiveNumberValid(v, 1_000_000_000_000)
        ? undefined
        : 'Enter the capital investment as an amount, like 250000.'),
    inputMode: 'decimal',
    maxLength: 20,
  },
}

/**
 * Which agency registers each Form of Organization, and what its number is
 * called.
 *
 * The LABELS only. ApplyWizard keeps the format hints and the "that does
 * not look usual" shapes, which are about typing a number into a fresh
 * form; a correction card needs to name the right certificate and no more.
 */
const AGENCY_BY_STRUCTURE: Record<string, { agency: string; certificate: string }> = {
  sole_proprietorship: { agency: 'DTI', certificate: 'Certificate of Business Name Registration' },
  partnership: { agency: 'SEC', certificate: 'SEC certificate' },
  corporation: { agency: 'SEC', certificate: 'SEC certificate' },
  cooperative: { agency: 'CDA', certificate: 'CDA certificate' },
}

/**
 * "2. DTI Registration Number" rather than "2. Registration Number".
 *
 * Falls back to naming all three when the structure is unknown, which is
 * what the form itself does before the question is answered — better than
 * asserting one agency that may be the wrong one.
 */
export function registrationNumberLabel(structure?: string | null): string {
  const found = structure ? AGENCY_BY_STRUCTURE[structure] : undefined

  return found ? `${found.agency} Registration Number` : 'DTI / SEC / CDA Registration Number'
}

/** Where to copy it from, when we know which agency issued it. */
export function registrationNumberHint(structure?: string | null): string | undefined {
  const found = structure ? AGENCY_BY_STRUCTURE[structure] : undefined

  return found ? `Copy it from your ${found.certificate}.` : undefined
}

/** The rule for a target, or a plain required check when we know no better. */
export function scalarFieldRule(code: string): FieldRule {
  return (
    SCALAR_FIELD_RULES[code] ?? {
      validate: (v) => (v.trim() === '' ? 'This field is required.' : undefined),
      maxLength: 255,
    }
  )
}

/**
 * The two sexes the register records, as code and as words.
 *
 * ── Why the column keeps the code ───────────────────────────────────────────
 *
 * `business_owners.gender` and `users.gender` hold 'M' or 'F', and the API
 * validates `in:M,F` in three controllers. That is the right thing for a
 * column to hold: a short stable value that does not move when the wording
 * does, and that nothing has to parse a word back out of.
 *
 * ── Why this list exists ────────────────────────────────────────────────────
 *
 * Because the pairing was written five times — the wizard's radiogroup and the
 * `<option>` pairs in RegisterPage, SettingsPage and UsersPage — while three
 * other screens printed the raw code at the reader. Client, 29 September 2026:
 * *"it is M or F only, while the application form asks for Male or Female.
 * Kindly apply consistency."*
 *
 * Controls build their options from this; displays read `genderLabel` off it.
 * One list, so a sixth screen cannot invent a sixth wording.
 */
export const GENDERS: { value: string; label: string }[] = [
  { value: 'M', label: 'Male' },
  { value: 'F', label: 'Female' },
]

/**
 * 'M' as "Male", for anywhere a stored sex is shown to a person.
 *
 * An unrecognised code comes back as itself rather than as a blank or a guess:
 * if the register ever holds something this build does not know, an officer
 * should see the value and be able to ask about it, not be told nothing.
 */
export function genderLabel(code: string | null | undefined): string {
  if (!code) return ''

  return GENDERS.find((g) => g.value === code)?.label ?? code
}
