import type { User } from './types'
import { validateRequired } from './validation'

/*
 * The business owner's home address [checklist 2026-09-28, Register 2 — "Make
 * sure that profile details are complete (like home details)"].
 *
 * One definition of its parts, labels and checks, because three screens use
 * them — sign-up, Edit Profile on Settings, and the Profile record — and the
 * point of the item is that they agree on what "complete" means. The server
 * holds the same rules (AuthController::homeAddressRules) and sends the same
 * sentences, so an answer this file lets through is not refused there in
 * different words.
 *
 * Every part is free text, barangay included. An owner may live outside
 * Malabon, so the city's barangay list — which a BUSINESS address is picked
 * from — cannot be the list of answers here.
 */

export type HomeAddressField =
  | 'home_street'
  | 'home_barangay'
  | 'home_city'
  | 'home_province'
  | 'home_postal_code'

export type HomeAddressValues = Record<HomeAddressField, string>

export const EMPTY_HOME_ADDRESS: HomeAddressValues = {
  home_street: '',
  home_barangay: '',
  home_city: '',
  home_province: '',
  home_postal_code: '',
}

/** The four parts that must all be given. ZIP is optional: many people do not know theirs. */
export const HOME_ADDRESS_REQUIRED: readonly HomeAddressField[] = [
  'home_street',
  'home_barangay',
  'home_city',
  'home_province',
]

export const HOME_ADDRESS_FIELDS: readonly HomeAddressField[] = [...HOME_ADDRESS_REQUIRED, 'home_postal_code']

export const HOME_ADDRESS_LABELS: Record<HomeAddressField, string> = {
  home_street: 'House No., Building, Street',
  home_barangay: 'Barangay',
  home_city: 'City or Municipality',
  home_province: 'Province',
  home_postal_code: 'ZIP Code',
}

/** Browser autofill tokens, so a phone can fill the address it already knows. */
export const HOME_ADDRESS_AUTOCOMPLETE: Record<HomeAddressField, string> = {
  home_street: 'address-line1',
  home_barangay: 'address-level3',
  home_city: 'address-level2',
  home_province: 'address-level1',
  home_postal_code: 'postal-code',
}

/*
 * Only the two parts a reader could be unsure how to answer get a line of
 * help. Metro Manila has no province, and "what do I put there?" is the one
 * question the label alone leaves open; ZIP is the one part that is optional.
 */
export const HOME_ADDRESS_HINTS: Partial<Record<HomeAddressField, string>> = {
  home_province: 'In Metro Manila, write Metro Manila.',
  home_postal_code: 'Optional. 4 digits.',
}

export const ZIP_DIGITS = 4

const MESSAGES: Record<HomeAddressField, string> = {
  home_street: 'Enter your house number, building and street.',
  home_barangay: 'Enter your barangay.',
  home_city: 'Enter your city or municipality.',
  home_province: 'Enter your province.',
  home_postal_code: 'A ZIP code is 4 digits.',
}

export function validateHomeAddressField(field: HomeAddressField, values: HomeAddressValues): string | undefined {
  if (field === 'home_postal_code') {
    const zip = values.home_postal_code.trim()
    return zip === '' || /^\d{4}$/.test(zip) ? undefined : MESSAGES.home_postal_code
  }
  return validateRequired(values[field], MESSAGES[field])
}

/** Whether any of the four required parts is still blank. ZIP's shape is checked on its own. */
export function homeAddressMissingParts(values: HomeAddressValues): boolean {
  return HOME_ADDRESS_REQUIRED.some((field) => !!validateHomeAddressField(field, values))
}

export function homeAddressFrom(user: User | null | undefined): HomeAddressValues {
  return {
    home_street: user?.home_street ?? '',
    home_barangay: user?.home_barangay ?? '',
    home_city: user?.home_city ?? '',
    home_province: user?.home_province ?? '',
    home_postal_code: user?.home_postal_code ?? '',
  }
}

/** Trimmed, with an empty ZIP sent as '' so the server clears it rather than keeping an old one. */
export function homeAddressPayload(values: HomeAddressValues): HomeAddressValues {
  return {
    home_street: values.home_street.trim(),
    home_barangay: values.home_barangay.trim(),
    home_city: values.home_city.trim(),
    home_province: values.home_province.trim(),
    home_postal_code: values.home_postal_code.trim(),
  }
}

/**
 * The address on one line, the way it is written on an envelope — or null when
 * a required part is missing, so a screen says "not given" rather than printing
 * a fragment that looks like an answer.
 */
export function formatHomeAddress(user: User): string | null {
  if (!HOME_ADDRESS_REQUIRED.every((field) => (user[field] ?? '').trim() !== '')) return null
  // People often type the word themselves; printing "Brgy. Brgy. Longos" would
  // look like our mistake, so a leading "Brgy." or "Barangay" is dropped first.
  const barangay = (user.home_barangay ?? '').replace(/^\s*(brgy\.?|barangay)\s+/i, '')
  const place = [user.home_street, `Brgy. ${barangay}`, user.home_city, user.home_province].join(', ')
  return user.home_postal_code ? `${place} ${user.home_postal_code}` : place
}
