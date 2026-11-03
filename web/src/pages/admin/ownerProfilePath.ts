import { activePortal, portalPath } from '../../lib/api'

/**
 * Where an owner's profile lives, under whichever portal the reader is in.
 * Shared by the Business Owners list and the Permits table's Owner column.
 */
export function ownerProfilePath(ownerId: number): string {
  return portalPath(activePortal(), `/admin/business-owners/${ownerId}`)
}
