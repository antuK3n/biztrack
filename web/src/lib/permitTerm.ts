import { formatDate } from './format'

/**
 * Permits that never expire and are never renewed.
 *
 * Client, 20 October 2026, of the Occupancy Permit: "It is stated to us that
 * it is only annual inspection instead of annual renewal (like the other
 * permits)." It is issued once; its `valid_until` is the day its next yearly
 * inspection is due, not an expiry.
 *
 * The twin of `PermitType::NO_EXPIRY_CODES` in the API — change both together.
 * A screen asks `permitExpires()` rather than testing the code itself.
 */
export const NO_EXPIRY_CODES: readonly string[] = ['OCCUPANCY']

interface PermitLike {
  /** The API's own answer (PermitResource / VerifyController), when it sent one. */
  expires?: boolean | null
  permit_type?: { code?: string | null } | null
  permit_type_code?: string | null
}

/** Does this permit run out on its `valid_until`? */
export function permitExpires(permit: PermitLike): boolean {
  if (typeof permit.expires === 'boolean') return permit.expires
  const code = permit.permit_type?.code ?? permit.permit_type_code ?? ''
  return !NO_EXPIRY_CODES.includes(code)
}

/** "Next inspection: 3 Jun 2027", for a permit that does not expire. */
export function nextInspectionLabel(validUntil: string | null | undefined): string {
  const date = formatDate(validUntil)
  return date ? `Next inspection: ${date}` : 'No expiry'
}
