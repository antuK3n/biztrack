/**
 * What a number box keeps of a keystroke.
 *
 * Moved out of FeeProfileStep on 10 November 2026 (client, "screen + server"),
 * because the office sheets, the amendment step and the officer's correction
 * dialog had number boxes that set `inputMode="numeric"` and nothing else:
 * the keypad changed on a phone, and a desktop keyboard still typed "two".
 * One copy here, so every box that holds a figure drops what is not one the
 * same way. FeeProfileStep re-exports the two it always had.
 *
 * Formatters, not validators: each returns what is left once the impossible
 * characters are gone, and a range (1 to 200 storeys, a 4-digit ZIP) is still
 * the form's own check to make.
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

/** Headcounts, vehicles, stalls: whole numbers only. */
export function formatCountInput(raw: string): string {
  return raw.replace(/\D/g, '').replace(/^0+(?=\d)/, '')
}

/**
 * A decimal with no thousands separator: "120.5", never "1,200.5".
 *
 * For an answer stored as typed rather than read through `plainAmount` —
 * an office sheet's floor area, an amendment's new value — where the API
 * judges it `numeric` and a comma would fail that.
 */
export function formatDecimalInput(raw: string): string {
  return formatAmountInput(raw).replace(/,/g, '')
}

/**
 * Digits only, leading zeros KEPT, at most `max` of them. For a code made of
 * digits rather than a quantity: a ZIP code, a Community Tax Certificate No.
 * "0012" is not twelve.
 */
export function formatDigitsInput(raw: string, max: number): string {
  return raw.replace(/\D/g, '').slice(0, max)
}

/**
 * A phone number as people write one: digits and + ( ) - and spaces. The
 * same characters `phoneValid` in lib/fieldRules.ts accepts.
 */
export function formatPhoneInput(raw: string): string {
  return raw.replace(/[^\d+()\-\s]/g, '').slice(0, 20)
}

/** A person's name never holds a digit; drop any as they are typed. */
export function stripDigits(raw: string): string {
  return raw.replace(/\d/g, '')
}
