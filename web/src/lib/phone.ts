/*
 * ── Item 10 · what a Philippine mobile number is ──────────────────────────
 *
 * The three rules the +63 field in ContactNumberInput enforces, kept out of
 * that component for two reasons. They are pure functions the wizard's own step
 * gate and error map call without rendering anything; and a module that exports
 * both components and plain functions loses Vite's fast refresh, which oxlint
 * flags (react/only-export-components).
 *
 * Deliberately NOT merged with `phoneValid` in ApplyWizard. That one is the
 * loose "a mobile or a landline, catch a typo not a format" rule the emergency
 * contact and the lessor's number share, and it has to stay loose — those two
 * fields take whatever number reaches a person. This one says one thing.
 */

/**
 * Any way a Philippine mobile is written, down to the ten subscriber digits.
 *
 * Every mobile stored in the register is in the 09XXXXXXXXX form — that is what
 * the sign-up field has always demanded and what the account prefills into the
 * business's own field — so this has to READ that form even though the control
 * must never SHOW it. The trunk `0` and the country code `63` are two spellings
 * of the same nothing; both are peeled off and what is left is the number.
 *
 * Deliberately not a validator: it keeps whatever it is given, so a half-typed
 * or malformed number comes back as digits rather than as an empty box that
 * looks like the value was never there. `mobileValid` is what judges it.
 */
export function mobileSubscriberDigits(value: string): string {
  let digits = value.replace(/\D/g, '')
  if (digits.startsWith('63')) digits = digits.slice(2)
  else if (digits.startsWith('0')) digits = digits.slice(1)

  return digits.slice(0, 10)
}

/**
 * The one shape this field ever displays, emits or sends: +63 then ten digits.
 *
 * Empty in, empty out — an untouched optional field must not become "+63",
 * which is a country code and not a number and would be stored as one.
 */
export function canonicalMobile(value: string): string {
  const digits = mobileSubscriberDigits(value)

  return digits === '' ? '' : `+63${digits}`
}

/**
 * Ten digits, the first of which is 9.
 *
 * The leading 9 is the whole PH mobile range, and checking it is what separates
 * a mobile from a landline typed into the wrong box — the mistake this field
 * actually attracts, since both are "a phone number" to the person filling it.
 */
export function mobileValid(value: string): boolean {
  const digits = mobileSubscriberDigits(value)

  return digits.length === 10 && digits.startsWith('9')
}

export const MOBILE_ERROR = 'Enter a Philippine mobile number: 10 digits after +63, starting with 9.'
