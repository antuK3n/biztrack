import { reference } from './resources'
import type { User } from './types'
import { useAsync } from './useAsync'
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
 * The owner picks one of Malabon's barangays, and is not asked for a city or
 * province [checklist Register 3, "remove unnecessary registration details
 * (city/municipality, province …)"; Ken, 5 October 2026]: the server writes
 * Malabon and Metro Manila itself. Until then every part was free text, on
 * the reasoning that an owner may live outside Malabon. `home_city` and
 * `home_province` stay on the User payload — older owners hold whatever they
 * typed, and Profile prints it — but no form asks for them.
 */

export type HomeAddressField = 'home_street' | 'home_barangay' | 'home_postal_code'

export type HomeAddressValues = Record<HomeAddressField, string>

export const EMPTY_HOME_ADDRESS: HomeAddressValues = {
  home_street: '',
  home_barangay: '',
  home_postal_code: '',
}

/** The two parts that must be given. ZIP is optional: many people do not know theirs. */
export const HOME_ADDRESS_REQUIRED: readonly HomeAddressField[] = ['home_street', 'home_barangay']

export const HOME_ADDRESS_FIELDS: readonly HomeAddressField[] = [...HOME_ADDRESS_REQUIRED, 'home_postal_code']

export const HOME_ADDRESS_LABELS: Record<HomeAddressField, string> = {
  home_street: 'House No., Building, Street',
  home_barangay: 'Barangay',
  home_postal_code: 'ZIP Code',
}

/** Browser autofill tokens, so a phone can fill the address it already knows. */
export const HOME_ADDRESS_AUTOCOMPLETE: Record<HomeAddressField, string> = {
  home_street: 'address-line1',
  home_barangay: 'address-level3',
  home_postal_code: 'postal-code',
}

/*
 * Only ZIP gets a line of help: it is the one part that is optional. (The
 * province's "In Metro Manila, write Metro Manila." went with the province.)
 */
export const HOME_ADDRESS_HINTS: Partial<Record<HomeAddressField, string>> = {
  home_postal_code: 'Optional. 4 digits.',
}

export const ZIP_DIGITS = 4

const MESSAGES: Record<HomeAddressField, string> = {
  home_street: 'Enter your house number, building and street.',
  home_barangay: 'Enter your barangay.',
  home_postal_code: 'A ZIP code is 4 digits.',
}

export function validateHomeAddressField(field: HomeAddressField, values: HomeAddressValues): string | undefined {
  if (field === 'home_postal_code') {
    const zip = values.home_postal_code.trim()
    return zip === '' || /^\d{4}$/.test(zip) ? undefined : MESSAGES.home_postal_code
  }
  return validateRequired(values[field], MESSAGES[field])
}

/** Whether either required part is still blank. ZIP's shape is checked on its own. */
export function homeAddressMissingParts(values: HomeAddressValues): boolean {
  return HOME_ADDRESS_REQUIRED.some((field) => !!validateHomeAddressField(field, values))
}

export function homeAddressFrom(user: User | null | undefined): HomeAddressValues {
  return {
    home_street: user?.home_street ?? '',
    home_barangay: user?.home_barangay ?? '',
    home_postal_code: user?.home_postal_code ?? '',
  }
}

/**
 * Trimmed, with an empty ZIP sent as '' so the server clears it rather than
 * keeping an old one. No city or province: the server writes those.
 */
export function homeAddressPayload(values: HomeAddressValues): HomeAddressValues {
  return {
    home_street: values.home_street.trim(),
    home_barangay: values.home_barangay,
    home_postal_code: values.home_postal_code.trim(),
  }
}

/**
 * The names the Barangay dropdown offers: Malabon's, from the public list
 * (GET /barangays — sign-up has no token).
 *
 * `current` is the barangay already on file. An owner who registered when the
 * barangay was free text may hold one that is not Malabon's; it is offered as
 * it stands so the dropdown shows what is stored instead of a blank, and so an
 * unrelated edit on Settings sends it back unchanged. The server accepts the
 * stored value for the same reason (AuthController::homeAddressRules).
 */
export function useHomeBarangays(current = '', enabled = true): string[] {
  const list = useAsync(() => (enabled ? reference.barangayNames() : Promise.resolve([])), [enabled])
  const names = (list.data ?? []).map((b) => b.name)
  return current !== '' && !names.includes(current) ? [current, ...names] : names
}

/**
 * The address on one line, the way it is written on an envelope — or null when
 * a required part is missing, so a screen says "not given" rather than printing
 * a fragment that looks like an answer. City and province are printed when on
 * file and skipped when not: they are no longer the owner's to give.
 */
export function formatHomeAddress(user: User): string | null {
  if (!HOME_ADDRESS_REQUIRED.every((field) => (user[field] ?? '').trim() !== '')) return null
  // Older owners typed the word themselves; printing "Brgy. Brgy. Longos" would
  // look like our mistake, so a leading "Brgy." or "Barangay" is dropped first.
  const barangay = (user.home_barangay ?? '').replace(/^\s*(brgy\.?|barangay)\s+/i, '')
  const place = [user.home_street, `Brgy. ${barangay}`, user.home_city, user.home_province]
    .filter((part) => (part ?? '').trim() !== '')
    .join(', ')
  return user.home_postal_code ? `${place} ${user.home_postal_code}` : place
}
