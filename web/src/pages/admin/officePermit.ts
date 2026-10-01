/*
 * The permit an office's dashboard is about, in words.
 *
 * Scoped to one office, every "permit" figure on the dashboard counts THAT
 * office's permit type (App\Support\AnalyticsOffice: "permits: those of a
 * permit type the office ISSUES"). City Health's "Active Businesses" are
 * businesses holding a valid sanitary permit, its compliance card tests
 * sanitary permits, and a red pin on its map is a business with no valid
 * sanitary permit. The labels said "permit" and "Business permit compliance"
 * on every office's screen, which reads as the Mayor's permit — so an officer
 * at CHO was told how many businesses hold a business permit, and they do not.
 *
 * ONE MAPPING, from the office code. The dashboard payload does not carry the
 * office's permit name (the expiry table's columns do, but only once a permit
 * of that type has been issued), so this is the single place the pairing is
 * written down. It mirrors permit_types.issuing_department_id in
 * ReferenceSeeder; an office missing here falls back to "permit", which is
 * vague rather than wrong. If the payload ever names the permit, read it from
 * there and delete this table.
 */
export interface OfficePermit {
  /** Mid-sentence: "holding a valid sanitary permit". */
  noun: string
  /** As a label starts: "Sanitary permit compliance". */
  label: string
}

const OFFICE_PERMITS: Record<string, OfficePermit> = {
  BPLO: { noun: 'business permit', label: 'Business permit' },
  CHO: { noun: 'sanitary permit', label: 'Sanitary permit' },
  BFP: { noun: 'fire safety certificate', label: 'Fire safety certificate' },
  CPDO: { noun: 'zoning clearance', label: 'Zoning clearance' },
  OBO: { noun: 'occupancy permit', label: 'Occupancy permit' },
  CENRO: { noun: 'environmental certificate', label: 'Environmental certificate' },
}

/** Every office at once: a business may hold any of the six. */
const ANY_PERMIT: OfficePermit = { noun: 'permit', label: 'Permit' }

/** The permit an office code's figures are about; `null` is every office. */
export function officePermit(office: string | null | undefined): OfficePermit {
  if (!office) return ANY_PERMIT
  return OFFICE_PERMITS[office] ?? ANY_PERMIT
}
