import type { ChipTone } from '../../components/ui/Proto'

/**
 * Status tones, in the tints the other admin tables already use.
 *
 * Superseded and Retired are grey: ordinary ends of a certificate, not
 * problems (DESIGN.md, Red Means Stop). Red is for the two an office decided
 * against the business — Revoked and Rejected; purple for Suspended.
 */
export const STATUS_TONES: Record<string, ChipTone> = {
  active: 'tint-green',
  expired: 'tint-yellow',
  superseded: 'tint-gray',
  suspended: 'tint-purple',
  revoked: 'tint-red',
  retired: 'tint-gray',
  rejected: 'tint-red',
}
