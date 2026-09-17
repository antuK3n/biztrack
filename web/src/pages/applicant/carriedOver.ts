import { businessName } from '../../lib/format'
import type { CarriedOverBusiness } from './OfficeFormStep'
import type { Application } from '../../lib/types'

/**
 * The business as every office sheet carries it, derived from one saved filing.
 *
 * Lifted out of ClearanceStagePage so a second screen can render those sheets
 * without deriving this a second time. It is fifty lines of "which field on the
 * filing answers which box on which office's paper", and two copies of that is
 * two answers the day one of them is corrected — the CENRO block alone reads
 * eleven fields the BPLO form already collected.
 *
 * Pure, and takes the whole application rather than its parts, so a caller
 * cannot supply three of the four sources and get a sheet that is quietly
 * blank in one corner.
 */
const REGISTRATION_TYPE_LABELS: Record<string, string> = {
  sole_proprietorship: 'Sole Proprietorship',
  partnership: 'Partnership',
  corporation: 'Corporation',
  cooperative: 'Cooperative',
}

const SEX_LABELS: Record<string, string> = {
  male: 'Male',
  female: 'Female',
}

export function carriedOverBusiness(application: Application): CarriedOverBusiness {
  const b = application.business ?? null
  const line = b?.lines?.[0]
  const profile = application.fee_profile ?? null
  const owner = b?.owner ?? null
  /*
   * The paper prints one box per question; the register can hold several lines
   * of business on one filing. Joined rather than truncated to the first, so a
   * business declaring three trades hands CENRO all three — losing two of them
   * silently is how a sheet comes back for correction.
   */
  const joinLines = (pick: (l: NonNullable<typeof line>) => string | null | undefined): string =>
    (b?.lines ?? [])
      .map((l) => (pick(l) ?? '').trim())
      .filter(Boolean)
      .join('; ')

  const carriedOver: CarriedOverBusiness = {
    name: businessName(b),
    tradeName: b?.trade_name ?? '',
    address:
      [b?.address?.line1, b?.address?.line2, b?.address?.barangay?.name]
        .filter(Boolean)
        .join(', ') || '—',
    lineOfBusiness: line?.line_of_business?.trim() || line?.psic_code?.title || '—',
    // MCG-CENRO-FO-001's Ownership and Documentation block. See the type.
    registrationType: REGISTRATION_TYPE_LABELS[b?.registration_type ?? ''] ?? '',
    ownerName:
      [owner?.surname, owner?.given_name, owner?.middle_name, owner?.suffix]
        .map((part) => (part ?? '').trim())
        .filter(Boolean)
        .join(', ') || '',
    ownerSex: SEX_LABELS[owner?.gender ?? ''] ?? '',
    productsServices: joinLines((l) => l.products_services),
    landline: b?.address?.telephone ?? '',
    mobile: b?.address?.mobile_number ?? '',
    businessAreaSqm: profile?.floor_area_sqm != null ? String(profile.floor_area_sqm) : '',
    maleEmployees: profile?.male_employees != null ? String(profile.male_employees) : '',
    femaleEmployees: profile?.female_employees != null ? String(profile.female_employees) : '',
    // MCG-CPDD-FO-003's numbered items. See the type.
    proprietorName:
      (b?.president_officer_name ?? '').trim() ||
      [owner?.given_name, owner?.middle_name, owner?.surname, owner?.suffix]
        .map((part) => (part ?? '').trim())
        .filter(Boolean)
        .join(' '),
    proprietorContact: b?.address?.mobile_number ?? b?.address?.telephone ?? '',
    proprietorEmail: b?.address?.email ?? '',
    /*
     * The paper's items VIII.C and VIII.D — the lessor's name and address — are
     * NOT built here. They are derived server-side into the sheet's `form_data`
     * (`OfficeFormAnswers::derive`), because the officer's review screen renders
     * `form_data` and nothing else: a lessor that exists only on the applicant's
     * side is a lease contract CPDD cannot check the sheet against.
     */
    /*
     * Item V, "Activity (please specify)". The PSIC title says what CATEGORY
     * the trade is; the products say what it actually does. CPDD is judging a
     * USE, so both together are the answer, and joining beats picking.
     */
    activity:
      [line?.line_of_business?.trim() || line?.psic_code?.title, joinLines((l) => l.products_services)]
        .filter(Boolean)
        .join(' — '),
  }

  return carriedOver
}
