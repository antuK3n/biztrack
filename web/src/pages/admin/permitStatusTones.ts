import type { ChipTone } from '../../components/ui/Proto'

/**
 * Status tones, in the tints the other admin tables already use.
 *
 * Superseded and Retired are grey: ordinary ends of a certificate, not
 * problems (DESIGN.md, Red Means Stop). Red is for the two an office decided
 * against the business — Revoked and Rejected. Suspended is grey too, a hold
 * rather than a verdict (client, 5 October 2026); a suspended BUSINESS keeps
 * its purple elsewhere, being an admin action against the account.
 */
export const STATUS_TONES: Record<string, ChipTone> = {
  active: 'tint-green',
  expired: 'tint-yellow',
  superseded: 'tint-gray',
  suspended: 'tint-gray',
  revoked: 'tint-red',
  retired: 'tint-gray',
  rejected: 'tint-red',
}
