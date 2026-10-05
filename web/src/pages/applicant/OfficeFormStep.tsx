import { createContext, useContext, useEffect, useRef, type ReactNode } from 'react'
import { targetsInclude } from '../../lib/returnTargets'
import { CorrectionModal } from '../../components/CorrectionModal'
import { DocumentActions } from '../../components/DocumentActions'
import { CheckCircleFilledIcon, DownloadIcon, UploadIcon } from '../../components/icons'
import { FieldError, FieldLabel, inputCls } from '../../components/ui/Proto'
import { genderLabel } from '../../lib/fieldRules'
import { formatBytes, formatDate } from '../../lib/format'
import type { CarriedSource, OfficeFormRequirement } from '../../lib/types'
import { ACCEPT_ATTR, MAX_UPLOAD_BYTES } from './uploads'

/*
 * Per-office application form sheets (UI prototype Parts 4-7, pages 040-043).
 * One step per selected clearance that has a form sheet: ZONING, SANITARY, CEC,
 * FSIC, OCCUPANCY. Fields are free-form and persisted verbatim as opaque JSON
 * via PUT office-forms/{code}. The auto-generated control numbers (SP-2026-…,
 * CEC-2026-…, FSIC-…) are read-only placeholders per the prototype — the real
 * number is minted server-side on issuance.
 *
 * The form never asks for anything the system already holds. Whether this is a
 * new or renewal application, the date it was filed, and which certificate the
 * FSIC is for all come from the application record: the API derives them on
 * every read and write, and they are shown here read-only so the sheet still
 * carries what the paper form needs. Issuance dates ("Date Issued") belong to
 * the office that issued the document and are filled in during officer review.
 *
 * ── Every sheet here is transcribed from paper ─────────────────────────────
 *
 * The CHO, CENRO, BFP and OBO forms are copied from the document the counter
 * hands out, field label for field label, so what BizTrack asks is what the
 * counter asks, word for word.
 *
 * That is now true of all five, which it was not before. MARKET was the
 * standing exception — checklist item 109, *"Application form for Market
 * Clearance is missing. Create something for this since we currently don't have
 * the paper version"* — so its three questions were written rather than copied
 * and the sheet said on its own face that it was interim. It is gone: Market
 * Clearance and the City Market Administrator were removed from the system on
 * 6 September 2026, the client having confirmed with the LGU that neither is
 * needed. Nothing on this screen is invented any more, and the next sheet that
 * would be should be argued for as hard as that one was.
 *
 * ZONING was the other exception, and it resolved itself. CPDD had never sent
 * its locational clearance form
 * (`docs/questions-for-malabon.md` E4/C9 had been chasing it since the first
 * round of testing), so the sheet was assembled from the Revenue Code's zoning
 * article and every field carried a marker — TRANSCRIBED where the wording came
 * from the ordinance, INFERRED where the office plainly needed an answer but
 * nobody had confirmed the question. The instruction was to throw the INFERRED
 * ones away when the form turned up.
 *
 * It turned up: MCG-CPDD-FO-003 v1.2, effective 01-09-2026. It settled the
 * guesses mostly by contradicting them. CPDD does not ask for a proposed land
 * use category — those five options were the fee schedule's vocabulary, not the
 * form's — and it does not ask whether the business is already trading at the
 * address. Both are deleted. What it asks in their place is a free-text project
 * description and, for industrial projects, whether they are pollutive or
 * hazardous.
 *
 * Most of its numbered fields are answers the filing already holds, so the
 * sheet carries them read-only rather than asking twice. Two things on the
 * paper are deliberately *not* built here, and are recorded in
 * questions-for-malabon C9 instead: the hand-drawn Sketch of the Location,
 * which needs an upload or a canvas rather than a text box (the map pin is a
 * coordinate, not a sketch), and the declaration's "MUST BE NOTARIZED PRIOR TO
 * SUBMISSION", which the online flow has no step for at all. One field it asks
 * has nowhere to come from: II. Home Address, which the system does not record
 * anywhere — an account-level gap, not a gap on this sheet.
 */

/**
 * Is this sheet a RECORD of something already sent, rather than a form?
 *
 * The client, 9 September 2026: "We do not promote any editing of forms once
 * submitted... once submitted, please create the button which will allow them
 * to see what they have submitted."
 *
 * A context rather than a prop threaded through five field components and
 * eleven controls. The alternative was passing `readOnly` down every level of
 * every sheet, where the one that gets forgotten is a live input on a form the
 * office has already received — a silent hole, and exactly the kind this file
 * has had before.
 *
 * `readOnly` on the controls, never `disabled`, and the difference is the whole
 * accessibility of the thing. A disabled input leaves the tab order and screen
 * readers pass over it, so an applicant using one could not read back what they
 * had submitted — which is the entire purpose of the view. `readOnly` looks the
 * same, stays focusable and is announced. The two controls that cannot be
 * `readOnly` — the chip pickers and the one <select> — take `disabled` with an
 * `aria-disabled` beside it, because HTML gives them no other way.
 */
const ReadOnlyContext = createContext(false)

function useReadOnly(): boolean {
  return useContext(ReadOnlyContext)
}

export type OfficeFormData = Record<string, unknown>

/**
 * What the applicant has already told us about the business, as every office
 * sheet needs it printed at the top.
 *
 * The paper versions of these forms each open by asking for the name, the
 * address and the trade — which the applicant has answered three sections
 * earlier. Carrying the answers instead of re-asking is the whole point of
 * filing online, but carrying them silently is worse than re-asking: the
 * applicant signs a statutory declaration without ever seeing what it says
 * about them. So they are shown, read-only, on every sheet.
 */
export interface CarriedOverBusiness {
  name: string
  tradeName: string
  address: string
  lineOfBusiness: string
  /*
   * ── The CENRO block ───────────────────────────────────────────────────────
   *
   * MCG-CENRO-FO-001 v2.0's "Ownership and Documentation" asks for eleven
   * things the BPLO form has already collected, and the CEC sheet was showing
   * none of them: an applicant filling it in saw four boxes where the paper has
   * eighteen, and the office received a sheet that did not look like its own
   * form.
   *
   * They are carried, never re-asked, for the reason `CarriedOverSection`
   * states above — two forms asking a similar-sounding question get two
   * answers. Rendered only on the CEC sheet rather than in the shared block,
   * because the other four offices' papers do not ask for them and a shared
   * block that grows to the union of every form is how a sheet stops looking
   * like the paper it is named after.
   *
   * Required rather than optional so the one caller has to supply them. An
   * optional field here would render blank on a payload that simply forgot to
   * pass it, which is indistinguishable from a business that genuinely has no
   * landline.
   */
  /** Sole Proprietorship / Partnership / Corporation / Cooperative. */
  registrationType: string
  /** Family Name, First Name, Middle Name — the person the filing is in. */
  ownerName: string
  ownerSex: string
  productsServices: string
  /** BPLO item A6 — the paper's LANDLINE. */
  landline: string
  /** BPLO item A7 — the paper's MOBILE NO. */
  mobile: string
  /** The paper's BUSINESS AREA (IN SQ. M.). */
  businessAreaSqm: string
  maleEmployees: string
  femaleEmployees: string
  /*
   * ── The CPDD block ────────────────────────────────────────────────────────
   *
   * MCG-CPDD-FO-003 v1.2's numbered items, carried for the same reason as
   * CENRO's: the applicant answered them on the BPLO form and a second asking
   * invites a second answer.
   *
   * `proprietorName` is item I — "Name of Proprietor/President/General
   * Manager". Three job titles for one box, and the register holds two of them:
   * `president_officer_name` for a corporation, partnership or cooperative, and
   * the owner for a sole proprietorship, who IS the proprietor. Whichever the
   * filing has.
   *
   * Items VIII.C and VIII.D, the lessor's name and address, are NOT here. They
   * were one derived sentence — "Leased from Acme Realty" — which read well and
   * answered neither box, and the paper wants them apart. They are now derived
   * server-side into `form_data` instead, so that the OFFICER sees them too:
   * the review sheet renders `form_data` and nothing else, and a lessor CPDD
   * cannot read is a lease contract CPDD cannot check the sheet against.
   */
  proprietorName: string
  /** Item I's CONTACT NO. and EMAIL ADD. — BPLO items A7 and A8. */
  proprietorContact: string
  proprietorEmail: string
  /** Item V, "Activity (please specify)" — the trade in the applicant's words. */
  activity: string
}

/**
 * Which permit-type codes open a form sheet, in wizard order.
 *
 * Deliberately duplicated: `PermitType::OFFICE_FORM_CODES` in the API holds the
 * same list, because the clearance stage has to answer "does Apply open a
 * form?" from a database row and this file cannot be imported from PHP. The two
 * must be changed together or a card offers a sheet that does not exist.
 */
export const OFFICE_FORM_CODES = [
  'ZONING',
  'SANITARY',
  'CEC',
  'FSIC',
  'OCCUPANCY',
] as const
export type OfficeFormCode = (typeof OFFICE_FORM_CODES)[number]

/** Kicker + h1 + form-ref for each office form sheet (verbatim from prototype). */
/**
 * Keys that are machinery, not answers — never shown to a reader.
 *
 * Says which OTHER sheet owns a question when two papers print it: the
 * authorised representative (FSIC owns it, CPDD carries it). The applicant never
 * sees it and the officer should not either — printed in the review grid it
 * reads as a field the applicant answered "FSIC" to.
 *
 * `occupancy_shared_source` was written until 5 October 2026, when the BFP
 * sheet carried the occupancy type and storey count from OBO's. The sheets are
 * separate now (client: *"Make them separate"*); the key stays listed so a
 * sheet saved while it was written keeps it out of the grid.
 *
 * `denr_reason` joined on 5 October 2026. It says which branch of the DENR
 * table matched ('scale' or 'catch_all'), and CENRO's review grid printed it
 * as "Denr Reason: catch_all" (tester). `denr_basis` beside it already names
 * the row in words; the CEC sheet still reads the reason to pick its sentence.
 */
export const OFFICE_FORM_INTERNAL_KEYS: readonly string[] = [
  'authorized_representative_source',
  'occupancy_shared_source',
  'denr_reason',
]

/**
 * What each office's paper calls the box behind a form key.
 *
 * Keyed `CODE.key`, with a bare `key` as the fallback, because the same key
 * means different things on different papers — `application_type` is Full or
 * Partial on OBO's form and New or Renewal on CHO's. Anything absent falls
 * through to `humanizeKey`, which is fine for a plain two-word field and wrong
 * for an acronym, which is why the FSEC rows are named here.
 */
export const OFFICE_FORM_FIELD_LABELS: Record<string, string> = {
  /* Shared across sheets. */
  application_date: 'Date of Application',
  authorized_representative: 'Authorized Representative',
  certified: 'Certification',
  owner_address: 'Address of Owner',

  /* BFP · BFP-QSF-FSED-002 */
  'FSIC.certificate_applied_for': 'Certificate Applied For',
  'FSIC.occupancy_type': 'Type of Occupancy / Business Nature',
  'FSIC.building_storeys': 'No. of Storeys',

  /* OBO · Unified Application Form */
  'OCCUPANCY.application_type': 'Application Type (Full / Partial)',
  'OCCUPANCY.building_permit_no': 'Building Permit No.',
  'OCCUPANCY.building_permit_date': 'Building Permit — Date Issued',
  'OCCUPANCY.fsec_no': 'FSEC No.',
  'OCCUPANCY.fsec_date': 'FSEC — Date Issued',
  'OCCUPANCY.owner_address': 'Address of Owner / Permittee',
  'OCCUPANCY.owner_zip': 'ZIP Code',
  'OCCUPANCY.owner_tel': 'Tel. No.',
  'OCCUPANCY.owner_ctc_no': 'Community Tax Certificate No.',
  'OCCUPANCY.owner_ctc_date': 'CTC — Date Issued',
  'OCCUPANCY.owner_ctc_place': 'CTC — Place Issued',
  'OCCUPANCY.project_name': 'Name of Project',
  'OCCUPANCY.project_location': 'Location of Project',
  'OCCUPANCY.total_floor_area_sqm': 'Total Floor Area (sq. m.)',
  'OCCUPANCY.occupancy_type': 'Use / Character of Occupancy',
  'OCCUPANCY.building_storeys': 'No. of Storeys',
  'OCCUPANCY.building_units': 'No. of Units',
  'OCCUPANCY.completion_date': 'Date of Completion',

  /* CPDD · MCG-CPDD-FO-003 */
  'ZONING.application_type': 'Nature of Application',
  'ZONING.total_floor_area_sqm': 'Floor Area to be Utilized (sq. m.)',
  'ZONING.building_storeys': 'No. of Storeys of Building',
  'ZONING.site_is_rented': 'Site is Rented',

  /* CHO */
  'SANITARY.application_type': 'Nature of Application',
  'SANITARY.sanitary_classification': 'Sanitary Classification',
  'SANITARY.employees_male': 'No. of Employees — Male',
  'SANITARY.employees_female': 'No. of Employees — Female',
  'SANITARY.employees_total': 'No. of Employees — Total',
  'SANITARY.total_floor_area_sqm': 'Floor Area (sq. m.)',
  'SANITARY.operating_hours': 'Operating Hours',
  'SANITARY.seating_capacity': 'Seating Capacity',
  'SANITARY.has_kitchen': 'Food Preparation Area',
  'SANITARY.has_cold_storage': 'Refrigeration / Cold Storage',
  'SANITARY.water_source': 'Water Source',
  'SANITARY.toilets_count': 'No. of Toilets',
  'SANITARY.toilet_type': 'Type of Toilet',
  'SANITARY.toilets_separate_sexes': 'Separate Toilets for Men and Women',
  'SANITARY.sewage_disposal': 'Sewage Disposal',
  'SANITARY.solid_waste_disposal': 'Solid Waste Disposal',
  'SANITARY.waste_segregation': 'Waste Segregation Practised',
  'SANITARY.pest_control': 'Pest Control',
  'SANITARY.pest_control_last_date': 'Last Pest Control Treatment',
  'SANITARY.certified': 'Certification',
  'SANITARY.workers_requiring_health_certs': 'Workers Requiring Health Certificates',

  /*
   * CENRO · the DENR answers the API derives (OfficeFormAnswers). Named
   * since 5 October 2026: humanised they read "Denr Basis" and "Denr Pco".
   */
  'CEC.denr_basis': 'DENR Basis',
  'CEC.denr_certificate': 'DENR Certificate',
  'CEC.denr_permits': 'DENR Permits',
  'CEC.denr_pco': 'Pollution Control Officer',
  'CEC.denr_remarks': 'DENR Remarks',
}

/**
 * The order each office's paper asks its questions in.
 *
 * Read off the office's own form component below — ZoningFields,
 * SanitaryFields, CecFields, FsicFields, OccupancyFields — because that is
 * the order the applicant answered them in and the order the printed form
 * prints them. The officer's review sheet sorts by this, so a reviewer
 * holding the paper reads down both at once.
 *
 * `application_date` leads every sheet: it is the first box on all of them
 * and it is filled in by the system rather than by the applicant, so it
 * does not appear in the form components at all.
 *
 * A key not listed keeps its place after the ones that are. New questions
 * should be added here, but a sheet that grows one and forgets is a sheet
 * with a field at the end — not a sheet that loses it.
 */
export const OFFICE_FORM_FIELD_ORDER: Record<OfficeFormCode, readonly string[]> = {
  ZONING: [
    'application_date',
    'application_type',
    'zoning_project_description',
    'total_floor_area_sqm',
    'building_storeys',
    'site_is_rented',
    'lessor_name',
    'lessor_address',
    'zoning_industrial_project_type',
    'authorized_representative',
  ],
  SANITARY: [
    'application_date',
    'application_type',
    'sanitary_classification',
    'employees_male',
    'employees_female',
    'employees_total',
    'workers_requiring_health_certs',
    'total_floor_area_sqm',
    'operating_hours',
    'seating_capacity',
    'has_kitchen',
    'has_cold_storage',
    'water_source',
    'toilets_count',
    'toilet_type',
    'toilets_separate_sexes',
    'sewage_disposal',
    'solid_waste_disposal',
    'waste_segregation',
    'pest_control',
    'pest_control_last_date',
  ],
  CEC: ['application_date', 'application_type', 'owner_address', 'owner_birthday'],
  FSIC: [
    'application_date',
    'authorized_representative',
    'occupancy_type',
    'building_storeys',
    'certificate_applied_for',
  ],
  OCCUPANCY: [
    'application_date',
    'application_type',
    'building_permit_no',
    'fsec_no',
    'building_permit_date',
    'fsec_date',
    'owner_address',
    'owner_zip',
    'owner_tel',
    'owner_ctc_no',
    'owner_ctc_date',
    'owner_ctc_place',
    'project_name',
    'project_location',
    'occupancy_type',
    'building_storeys',
    'building_units',
    'total_floor_area_sqm',
    'completion_date',
  ],
}

/**
 * Sort a sheet's answers into the order its paper asks them.
 *
 * Anything unlisted keeps its own order and follows — losing an answer off
 * the officer's sheet because nobody added its key here would be a worse
 * failure than showing it last.
 */
export function officeFormFieldRank(code: string, key: string): number {
  const order = OFFICE_FORM_FIELD_ORDER[code as OfficeFormCode]
  const at = order?.indexOf(key) ?? -1

  return at === -1 ? Number.MAX_SAFE_INTEGER : at
}

/** The paper's name for one answer, or a humanised key when it has none. */
export function officeFormFieldLabel(code: string, key: string): string {
  return (
    OFFICE_FORM_FIELD_LABELS[`${code}.${key}`] ??
    OFFICE_FORM_FIELD_LABELS[key] ??
    key
      .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
      .replace(/[_-]+/g, ' ')
      .trim()
      .replace(/\b\w/g, (c) => c.toUpperCase())
  )
}
export const OFFICE_FORM_META: Record<
  OfficeFormCode,
  { kicker: string; title: string; ref: string }
> = {
  /*
   * This line used to cite the Zoning Ordinance rather than a form code, because
   * there was no form to cite and inventing a code would have dressed a sheet we
   * assembled ourselves as one CPDD issued. There is a form now, and its own
   * control block names it. The department reads as CPDD because that is what
   * the letterhead says; the register still seeds the office as CPDO.
   *
   * The Revenue Code section behind the fee stays deliberately absent —
   * checklist item 18 keeps those citations off applicant screens
   * (questions-for-malabon A12).
   */
  ZONING: {
    kicker: 'City Planning and Development Department',
    title: 'Application for Locational Clearance (Business Activities)',
    ref: 'MCG-CPDD-FO-003 · v1.2',
  },
  SANITARY: {
    kicker: 'City Health Office · Sanitation Division',
    title: 'Application for Sanitary Permit to Operate',
    ref: 'Pursuant to PD 856, Code on Sanitation of the Philippines',
  },
  CEC: {
    kicker: 'City Environmental & Natural Resources Office',
    title: 'Application for Certificate of Environmental Clearance (CEC)',
    ref: 'MCG-CENRO-FO-001 · v2.0',
  },
  FSIC: {
    kicker: 'Bureau of Fire Protection · Malabon City Fire Station',
    title: 'Fire Safety Inspection Certificate (FSIC) Application',
    ref: 'BFP-QSF-FSED-002 · Rev. 02 (08.24.20)',
  },
  OCCUPANCY: {
    kicker: 'Office of the Local Building Official',
    title: 'Certificate of Occupancy & Fire Safety Inspection Certificate',
    ref: 'Unified Application Form',
  },
  /*
   * MARKET was here — the one sheet with no paper behind it. Every other entry
   * cites a real form code (MCG-CENRO-FO-001, BFP-QSF-FSED-002,
   * MCG-CPDD-FO-003); that one carried a plain-English ref line saying the
   * office had never printed the application, because inventing
   * "MCG-CMO-FO-001" would have dressed three questions we wrote ourselves as a
   * document the City Market Administrator issued.
   *
   * Market Clearance and that office were removed from the system on
   * 6 September 2026 — the client confirmed with the LGU that neither is
   * needed. A business that genuinely needs one is asked by hand through Other
   * Requirements.
   */
}

/**
 * The paper behind one sheet, or undefined for a code that has none.
 *
 * A string in, because callers hold a `permit_type_code` off the wire rather
 * than a narrowed OfficeFormCode.
 */
export function officeFormMeta(code: string): { kicker: string; title: string; ref: string } | undefined {
  return OFFICE_FORM_META[code as OfficeFormCode]
}

/**
 * What this office sent the clearance back about, and the controls to fix it.
 *
 * The shape of BPLO's own "What you need to correct" panel on
 * /applications/:id — the item, the officer's words, the control, and Submit
 * corrections — on the client's instruction of 30 September 2026, given three
 * times before it was built as asked.
 *
 * ── Why it repeats controls that are also further down ──────────────────────
 *
 * Because that is the point of it. A returned sheet used to open at the top of
 * the letterhead with the only sign of the return a tinted row below the fold,
 * and an office that had returned an ANSWER rather than a document marked
 * nothing at all — only checklist rows carry the inline flag. The applicant
 * had the officer's sentence and a fifty-question form.
 *
 * There is still one writer per value: a document goes through
 * `onRequirementChange` and an answer through `set`, the same handlers the
 * rows below use. This is a second view of those controls, not a second copy
 * of their state.
 */
export function WhatToCorrect({
  code,
  targets,
  notes,
  requirements,
  data,
  set,
  onRequirementChange,
  requirementBusy,
  onSubmit,
  submitting,
  blocked,
  onClose,
  error,
}: {
  code: OfficeFormCode
  targets: string[]
  notes: Record<string, string> | null
  requirements?: OfficeFormRequirement[]
  data: OfficeFormData
  set: (key: string, value: string) => void
  onChange?: never
  onRequirementChange?: (
    documentCode: string,
    file: File | null,
    documentId?: number,
  ) => void | Promise<void>
  requirementBusy?: string | null
  /** Hand the sheet back to the office. The sheet's own Submit, reused. */
  onSubmit?: () => void
  submitting?: boolean
  /** Why Submit will not go through yet, or null. */
  blocked?: string | null
  /** Dismiss the dialog. The form underneath is still there. */
  onClose?: () => void
  /** A failure from the last attempt, shown in the dialog footer. */
  error?: string | null
}) {
  const office = REQUIREMENTS_META[code]?.office ?? 'This office'
  /* The file just sent, per row — BPLO says "Uploaded <name>." */

  /*
   * A target is either a checklist slot or an answer key. The checklist is
   * asked first because its label is the paper's own wording; an answer falls
   * through to the field label, which is also the paper's.
   */
  const items = targets.map((target) => {
    const row = (requirements ?? []).find((r) => r.code === target)

    return {
      target,
      row,
      label: row?.label ?? officeFormFieldLabel(code, target),
      note: notes?.[target] ?? null,
    }
  })

  return (
    <CorrectionModal
      office={office}
      count={items.length}
      onSubmit={() => onSubmit?.()}
      onClose={() => onClose?.()}
      submitting={submitting === true}
      blocked={blocked ?? null}
      error={error ?? null}
    >
      <div>
        <div className="space-y-5">
          {items.map((item) => {
            const busy = requirementBusy === item.target
            /*
             * The files ON the row, which the caller keeps refreshed from
             * each upload. Local state held their NAMES until 30 September
             * 2026 — a worse copy of something already in hand, with no id
             * on it, so the box could say a file had arrived and offer
             * nothing to do with it. Reading the row also means reopening
             * the dialog still shows them.
             */
            const files = item.row?.documents ?? []

            return (
              <div key={item.target}>
                <p className="text-[13px] font-semibold text-ink">
                  {item.label}{' '}
                  <span aria-hidden="true" className="text-s-red">
                    *
                  </span>
                  <span className="sr-only">(required)</span>
                </p>
                {/*
                  The officer's own words, plainly a person speaking. Nothing
                  invented when they left none — the heading says what happened.
                */}
                {item.note !== null && item.note.trim() !== '' && (
                  <p className="mt-1.5 rounded-md border-l-4 border-s-rose bg-s-rose-tint/40 px-3 py-2 text-sm italic text-ink">
                    “{item.note}”
                  </p>
                )}

                {item.row !== undefined ? (
                  <>
                    {/*
                      The checklist row's own control, with the checklist
                      row's own rules. `onRequirementChange` is its handler,
                      so a file put in here lands in the same slot and shows
                      on the row below.

                      It took ONE file until 30 September 2026, which was me
                      matching the wrong thing: the client asked for BPLO's
                      LAYOUT and for the rules of the field each row is
                      about, and a checklist slot takes as many as the
                      applicant has. A two-page endorsement is two files.
                    */}
                    <label
                      className={`mt-2 flex cursor-pointer items-center gap-3 rounded-lg border-2 border-dashed border-input-border bg-input/50 px-4 py-3 transition-colors hover:bg-input ${
                        busy || onRequirementChange === undefined
                          ? 'pointer-events-none opacity-60'
                          : ''
                      }`}
                    >
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-input-border bg-white text-royal">
                        <UploadIcon size={18} />
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm font-semibold text-ink">
                          {busy ? 'Uploading…' : 'Upload a replacement'}
                        </span>
                        {/*
                          The checklist row's own second line: the count once
                          there are files, the rule before that.
                        */}
                        <span className="mt-0.5 block text-xs text-ink-muted">
                          {files.length > 0
                            ? `${files.length} file${files.length === 1 ? '' : 's'} attached · click to add another`
                            : `PDF, JPG or PNG, up to ${Math.round(MAX_UPLOAD_BYTES / (1024 * 1024))} MB.`}
                        </span>
                      </span>
                      {files.length > 0 && !busy && (
                        <span className="inline-flex shrink-0 items-center gap-1.5 text-sm font-semibold text-s-green">
                          <CheckCircleFilledIcon size={16} /> Uploaded
                        </span>
                      )}
                      <input
                        type="file"
                        accept={ACCEPT_ATTR}
                        multiple
                        disabled={busy || onRequirementChange === undefined}
                        aria-label={`Re-upload ${item.label}`}
                        onChange={async (e) => {
                          const chosen = Array.from(e.target.files ?? [])
                          // Let the same file be picked twice — after a
                          // rejection the input would otherwise be inert.
                          e.target.value = ''
                          if (item.row?.code == null) return
                          /*
                            One at a time, awaited. Fired together they race
                            — each response is a full snapshot and the last
                            to arrive wins, so three files commonly showed
                            one, with the rest on disk and invisible.
                          */
                          for (const file of chosen) {
                            await onRequirementChange?.(item.row.code, file)
                          }
                        }}
                        className="sr-only"
                      />
                    </label>
                    {/*
                      What is on the row, with the three things its own
                      checklist entry offers. Sending the wrong scan is the
                      easiest mistake here, and until now the applicant
                      could not open what they had just sent to check it.
                    */}
                    {files.length > 0 && (
                      <ul className="mt-2 space-y-2">
                        {files.map((file) => (
                          <li
                            key={file.id}
                            className="flex items-center gap-3 rounded-lg border border-input-border bg-input/50 px-3 py-2"
                          >
                            <span className="min-w-0 flex-1 truncate text-sm text-ink">
                              {file.filename}
                            </span>
                            <DocumentActions id={file.id} filename={file.filename} />
                            <button
                              type="button"
                              onClick={() =>
                                void onRequirementChange?.(item.row!.code!, null, file.id)
                              }
                              disabled={busy}
                              aria-label={`Remove ${file.filename}`}
                              className="shrink-0 text-sm font-semibold text-s-red underline underline-offset-2 disabled:opacity-60"
                            >
                              Remove
                            </button>
                          </li>
                        ))}
                      </ul>
                    )}
                  </>
                ) : (
                  /*
                    An answer, drawn with the SAME control the sheet draws.

                    This was a bare text box for every field. The client
                    returned a Sanitary Classification — four chips on the
                    sheet — and was handed a free-text input to retype it in
                    (4 October 2026): "ALL RETURNED FIELDS SHOULD BE SIMILAR
                    TO THEIR ORIGINAL COUNTERPARTS, WITH SAME
                    RULES/VALIDATIONS."

                    That is not only inconsistent, it loses the rule. Four
                    chips are a closed set the office can act on; a text box
                    accepts "food est." and "Foods" and sends either back as
                    the correction, so the office returns it again over a
                    spelling. The same argument the citizenship select was
                    added for on the main form's corrections.

                    `set` is still the sheet's own setter either way, so the
                    value lands in the same place and autosave carries it
                    like any other keystroke.
                  */
                  <CorrectionAnswer
                    code={code}
                    field={item.target}
                    label={item.label}
                    value={String(data[item.target] ?? '')}
                    onChange={(v) => set(item.target, v)}
                  />
                )}
              </div>
            )
          })}
        </div>
      </div>
    </CorrectionModal>
  )
}
/** True if a permit-type code renders a prototype office form. */
export function hasOfficeForm(code: string): code is OfficeFormCode {
  return (OFFICE_FORM_CODES as readonly string[]).includes(code)
}

/**
 * The sheets with a required answer, and therefore the ones that say how to get
 * off them — CLR-2.
 *
 * ── What this used to be, and what it is now ──────────────────────────────
 *
 * Applying for a clearance once inserted its sheet as a mandatory STEP of the
 * wizard, directly behind LGU Clearances. Where the sheet had a required
 * answer, Next was disabled until it was given and the section map refused to
 * jump forward over it — so an accidental Apply on Market Clearance meant a
 * shopfront greengrocer had to invent a market name and a stall number or
 * cancel the whole filing.
 *
 * Five real drafts were in exactly that state — 4, 5, 7, 3376 and 3379, split
 * across two testers' accounts, none of them able to reach Review & Submit.
 * Withdrawing MARKET was verified to free all five against a copy of the
 * register; app 5 needed three (it also carried SANITARY and OCCUPANCY with no
 * saved sheet, which is what an accidental "apply for everything" looks like).
 *
 * The stranding itself is gone with the reordering: the sheets are not wizard
 * steps any more, they open over the clearance cards, and Back without saving
 * always works. What is left is milder and still worth a sentence — a sheet
 * that will not SAVE without answers the applicant does not have, on a
 * clearance they did not mean to apply for and are now being charged for.
 *
 * So this list is the sheets that have to POINT AT THE WAY OUT. The applicant
 * who needs it is not looking at the cards; they are looking at the form they
 * cannot finish.
 *
 * Derived from officeFormMissing below rather than typed out, so a sheet that
 * gains or loses a required answer cannot fall out of step with the sentence
 * that explains what to do about it.
 */
export function officeFormCanBlock(code: OfficeFormCode): boolean {
  return officeFormMissing(code, {}).length > 0
}

/** Today as a local-timezone YYYY-MM-DD string (input[type=date] max). */
export function todayISO(): string {
  const d = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/**
 * Required-field check per office form. Returns the labels still missing (or
 * invalid) so the wizard can disable Next and list what is left. Counts and
 * reference numbers stay optional; only the core decisions are required.
 */
export function officeFormMissing(code: OfficeFormCode, data: OfficeFormData): string[] {
  const has = (key: string) => typeof data[key] === 'string' && (data[key] as string).trim() !== ''
  const missing: string[] = []
  // Derived answers (type of application, filing date, certificate applied for)
  // are never required here: the API fills them in, so they cannot be missing.
  //
  // ZONING requires nothing. It used to require Proposed Land Use, which the
  // real CPDD form (MCG-CPDD-FO-003) turns out not to ask; of the two questions
  // it does ask, the paper marks neither mandatory, and inventing a requirement
  // the counter does not enforce is the same mistake in the other direction.
  if (code === 'SANITARY') {
    /*
     * The four facilities PD 856 has the health officer look at on every
     * establishment, and the applicant's own word. The food-only answers and
     * the dates are not gated: a sari-sari store has no seating to count.
     */
    if (!has('sanitary_classification')) missing.push('Sanitary Classification')
    if (!has('water_source')) missing.push('Water Source')
    if (!has('toilets_count')) missing.push('No. of Toilets')
    if (!has('sewage_disposal')) missing.push('Sewage Disposal')
    if (!has('solid_waste_disposal')) missing.push('Solid Waste Disposal')
  }
  if (code === 'CEC') {
    if (has('owner_birthday') && (data.owner_birthday as string) >= todayISO()) {
      missing.push('Owner birthday must be a past date')
    }
    /*
     * Two required answers, and CEC becomes a blocking sheet because of them —
     * `officeFormCanBlock('CEC')` was false and is now true.
     *
     * Both are on the paper above the REMARKS rule, and neither can be derived.
     * The address is the one field on MCG-CENRO-FO-001 the register does not
     * hold anywhere (there is no residential address on a business), and the
     * certification is an act rather than a fact — a sheet submitted without it
     * is one the office would hand back, so the applicant should be stopped
     * here rather than there.
     *
     * The birthday stays optional, as it was: the paper prints the box but the
     * counter accepts the form without it, and inventing a requirement the
     * counter does not enforce is the mistake this list already avoids for
     * ZONING.
     */
    if (!has('owner_address')) missing.push('Owner’s Address')
  }
  if (code === 'FSIC') {
    /*
     * The two the BFP header asks and nothing else answers. Occupancy type
     * and storeys are asked on the OBO sheet when that permit is on the
     * filing and derived onto this one, so they are checked through the
     * DERIVED payload rather than the typed one — `has` reads what the sheet
     * holds, which is the same thing either way.
     */
    if (!has('occupancy_type')) missing.push('Type of Occupancy / Business Nature')
    if (!has('building_storeys')) missing.push('No. of Storeys')
  }
  if (code === 'OCCUPANCY') {
    if (!has('application_type')) missing.push('Application Type')
    if (!has('project_name')) missing.push('Name of Project')
    if (!has('project_location')) missing.push('Location of Project')
    if (!has('occupancy_type')) missing.push('Use / Character of Occupancy')
    if (!has('building_storeys')) missing.push('No. of Storeys')
    if (!has('building_units')) missing.push('No. of Units')
    if (!has('total_floor_area_sqm')) missing.push('Total Floor Area')
    // Date of Completion is not asked: the API sets it (see the field).
  }
  // The MARKET branch was here (name of market, stall no., an optional stall
  // count the fee engine read). Removed with the Market Clearance on
  // 6 September 2026 — see OFFICE_FORM_META.
  return missing
}

/* ── Shared field primitives (prototype chip pills, read-only control no.) ── */

/** Royal square with white section letter, matching the wizard's SectionMarker. */
function SectionMarker({ letter, label, required }: { letter: string; label: string; required?: boolean }) {
  return (
    <div className="flex items-center gap-2.5">
      <span className="flex h-6 w-6 items-center justify-center rounded-sm bg-royal text-[13px] font-bold text-white">
        {letter}
      </span>
      <h2 className="text-[15px] font-bold text-ink">
        {label}
        {required && <span className="text-s-red"> *</span>}
      </h2>
    </div>
  )
}

/** Radio/checkbox chip pill (sanitary classification, Full/Partial occupancy). */
function ChipOption({
  label,
  selected,
  onClick,
}: {
  label: string
  selected: boolean
  onClick: () => void
}) {
  /*
   * A chip cannot be `readOnly` — it is a <button role="radio">, and HTML has
   * no read-only for that. `aria-disabled` with the handler suppressed is the
   * closest honest equivalent: the chip stays in the tab order and is still
   * announced with its checked state, so somebody reading back a submitted
   * sheet can hear which option was chosen, but pressing it does nothing.
   * `disabled` would drop it out of the tree entirely and take the answer with
   * it.
   */
  const ro = useReadOnly()

  return (
    <button
      type="button"
      role="radio"
      aria-checked={selected}
      aria-disabled={ro || undefined}
      onClick={ro ? undefined : onClick}
      className={`flex items-center gap-2.5 rounded-md border px-4 py-2.5 text-sm font-medium transition-colors ${
        selected
          ? 'border-royal bg-input text-ink'
          : 'border-input-border bg-input/60 text-ink-secondary'
      } ${ro ? 'cursor-default' : 'hover:bg-input'}`}
    >
      <span
        className={`h-3.5 w-3.5 shrink-0 rounded-full border-2 ${
          selected ? 'border-royal bg-royal' : 'border-input-border bg-white'
        }`}
      />
      {label}
    </button>
  )
}

/**
 * A row of chip options bound to a single string value.
 *
 * `label` names the group for a screen reader. The chips announce themselves as
 * radio buttons, and a radio with no group around it is read as one loose
 * control — the listener hears "Commercial, radio button" with nothing saying
 * what is being chosen. Optional only because the four older sheets predate it.
 */
/**
 * The office-form fields that are NOT a plain text box on their own sheet.
 *
 * Keyed `CODE.field`, because two sheets use the same key for different
 * questions — `application_type` is Occupancy's Full/Partial chips and
 * Zoning's derived Nature of Application, and a bare field name would have
 * drawn one over the other.
 *
 * Everything absent from here is a text input on the sheet and stays one in
 * the correction dialog. `building_storeys`, `building_units` and `owner_zip`
 * carry `inputMode="numeric"` rather than a different control, and that is
 * reproduced below for the same reason the chips are: a tablet keypad is part
 * of the field.
 */
type CorrectionControl =
  | { kind: 'chips'; options: string[] }
  | { kind: 'select'; options: string[] }
  | { kind: 'date' }
  | { kind: 'numeric' }

/*
 * A function rather than a table, because the option lists are declared
 * further down this file: a `const` map here would read them before they are
 * initialised. Function declarations hoist and this is only ever called from
 * render, by which time they exist.
 */
function correctionControl(key: string): CorrectionControl | undefined {
  const controls: Record<string, CorrectionControl> = {
    'SANITARY.sanitary_classification': { kind: 'chips', options: SANITARY_CLASSIFICATIONS },
    'SANITARY.water_source': { kind: 'select', options: WATER_SOURCES },
    'SANITARY.seating_capacity': { kind: 'numeric' },
    'SANITARY.has_kitchen': { kind: 'chips', options: YES_NO },
    'SANITARY.has_cold_storage': { kind: 'chips', options: YES_NO },
    'SANITARY.toilets_count': { kind: 'numeric' },
    'SANITARY.toilet_type': { kind: 'select', options: TOILET_TYPES },
    'SANITARY.toilets_separate_sexes': { kind: 'chips', options: YES_NO },
    'SANITARY.sewage_disposal': { kind: 'select', options: SEWAGE_DISPOSALS },
    'SANITARY.solid_waste_disposal': { kind: 'select', options: SOLID_WASTE_DISPOSALS },
    'SANITARY.waste_segregation': { kind: 'chips', options: YES_NO },
    'SANITARY.pest_control': { kind: 'select', options: PEST_CONTROL_MEASURES },
    'SANITARY.pest_control_last_date': { kind: 'date' },
    'OCCUPANCY.application_type': { kind: 'chips', options: OCCUPANCY_SCOPES },
    'OCCUPANCY.completion_date': { kind: 'date' },
    'OCCUPANCY.building_storeys': { kind: 'numeric' },
    'OCCUPANCY.building_units': { kind: 'numeric' },
    'OCCUPANCY.owner_zip': { kind: 'numeric' },
    'OCCUPANCY.owner_ctc_date': { kind: 'date' },
    'OCCUPANCY.total_floor_area_sqm': { kind: 'numeric' },
    'ZONING.zoning_industrial_project_type': {
      kind: 'chips',
      options: ZONING_INDUSTRIAL_PROJECT_TYPES,
    },
    'ZONING.building_storeys': { kind: 'numeric' },
    'FSIC.building_storeys': { kind: 'numeric' },
  }

  return controls[key]
}

/**
 * One returned answer, drawn the way its own sheet draws it.
 *
 * The control carries the rule. A closed set of chips is a closed set here
 * too, so a correction cannot introduce a spelling the office would have to
 * return a second time.
 */
export function CorrectionAnswer({
  code,
  field,
  label,
  value,
  onChange,
}: {
  code: OfficeFormCode
  field: string
  label: string
  value: string
  onChange: (v: string) => void
}) {
  const control = correctionControl(`${code}.${field}`)
  const box =
    'mt-1.5 block w-full rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink focus:border-royal focus:outline-none'

  if (control?.kind === 'chips') {
    return (
      <div className="mt-2">
        <ChipRow
          options={control.options}
          value={value}
          onChange={onChange}
          label={`Correct ${label}`}
        />
      </div>
    )
  }

  if (control?.kind === 'select') {
    return (
      <select
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-label={`Correct ${label}`}
        className={box}
      >
        <option value="">Select…</option>
        {control.options.map((o) => (
          <option key={o} value={o}>
            {o}
          </option>
        ))}
      </select>
    )
  }

  return (
    <input
      type={control?.kind === 'date' ? 'date' : 'text'}
      inputMode={control?.kind === 'numeric' ? 'numeric' : undefined}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      aria-label={`Correct ${label}`}
      className={box}
    />
  )
}

function ChipRow({
  options,
  value,
  onChange,
  label,
}: {
  options: string[]
  value: string
  onChange: (v: string) => void
  label?: string
}) {
  return (
    <div
      className="flex flex-wrap gap-2.5"
      role={label ? 'radiogroup' : undefined}
      aria-label={label}
    >
      {options.map((o) => (
        <ChipOption key={o} label={o} selected={value === o} onClick={() => onChange(o)} />
      ))}
    </div>
  )
}

/** Read-only auto-number field (SP-2026-…, CEC-2026-…, FSIC-…). */
function ControlNoField({
  label,
  placeholder,
}: {
  label: ReactNode
  placeholder: string
}) {
  // Wrapped in a real <label>: FieldLabel is a styled <span>, so on its own it
  // names nothing and a screen reader reaches the box with no idea what it is.
  return (
    <label className="block">
      <FieldLabel>{label}</FieldLabel>
      <input
        value=""
        readOnly
        aria-readonly="true"
        placeholder={placeholder}
        className={`${inputCls} tnum cursor-not-allowed bg-line/60 text-ink-secondary`}
      />
    </label>
  )
}

/**
 * Which answers on this sheet are still last year's, offered and unreviewed.
 *
 * A CONTEXT rather than a prop threaded through five sheet components and
 * thirty controls, for the same reason `ReadOnlyContext` above is one: the
 * fields that need it are scattered, the value is the same for all of them,
 * and passing it by hand is how one sheet ends up not getting it.
 */
const CarriedContext = createContext<Record<string, CarriedSource>>({})

/**
 * "From your 2026 application" — the flag on a carried answer.
 *
 * ── Why per field and not one banner ──────────────────────────────────────
 *
 * Client's decision, 18 September 2026. A single notice at the top of the
 * sheet cannot say WHICH answers came from last year, so on a long form it
 * stops meaning anything — the applicant reads it once and then cannot tell a
 * carried answer from one they have already checked. Per field, the flag is
 * also self-clearing: it is rendered from the set of keys whose value is still
 * the offered one, so touching the field removes it.
 *
 * Renders nothing when the key is not carried, which is every field on a new
 * application and every field the applicant has since edited. So it is safe to
 * place beside any answer that CAN carry, and costs nothing where it cannot.
 *
 * The year comes from the answer's own provenance and is not printed: the sheet
 * does not know which filing the answer came from, only that it did, and
 * inventing "2026" would be wrong the moment a business skipped a year.
 */
function CarriedTag({ field }: { field: string }) {
  const source = useContext(CarriedContext)[field]
  if (!source) return null

  /*
   * Two sources, two phrases. The owner's home address comes from the account
   * since 5 October 2026 (`AccountPrefill`), and "from your previous
   * application" on a first filing would send the applicant looking for a
   * filing that does not exist.
   */
  return (
    <span className="mt-1 block text-xs font-normal text-s-orange-ink">
      {source === 'account'
        ? 'From your account’s home address — check this is still right'
        : source === 'application'
          ? 'Suggested from your line of business — change it if it is wrong'
          : source === 'business'
            ? 'From your Business Permit application — change it if it is wrong'
            : 'From your previous application — check this is still right'}
    </span>
  )
}

const AutoTag = () => <span className="font-normal text-ink-muted"> (auto-generated)</span>

const FromApplicationTag = () => (
  <span className="font-normal text-ink-muted"> (from your application)</span>
)

/**
 * An answer the system already holds, shown read-only. The API derives it from
 * the application record on every read and write, so the applicant confirms it
 * instead of re-typing what they already told us.
 */
function DerivedField({
  label,
  value,
  hint,
  className,
}: {
  label: ReactNode
  value: string
  hint?: string
  /** Sizes the cell inside its section's wrap — see the note on the grids. */
  className?: string
}) {
  // The <label> stops at the input: the hint sits outside it so that a long
  // explanatory sentence is not read out as part of the field's name.
  return (
    <div className={className}>
      <label className="block">
        <FieldLabel>{label}</FieldLabel>
        {/*
         * No placeholder. It read "Filled in from your application", which is
         * what `<FromApplicationTag />` in the label beside it already says —
         * so a field with nothing in it said the sentence twice and the value
         * nowhere, and a row of six of them looked like six answers rather
         * than six blanks (client, 2026-09-08).
         *
         * An empty box now reads as empty, which is the true state: these are
         * questions the applicant has not answered on the BPLO form, and the
         * office needs to see that they are unanswered rather than see a
         * reassuring sentence where the answer should be.
         */}
        <input
          value={value}
          readOnly
          aria-readonly="true"
          className={`${inputCls} cursor-not-allowed bg-line/60 text-ink-secondary`}
        />
      </label>
      {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
    </div>
  )
}

/**
 * The business, as this sheet will carry it to the office.
 *
 * Read-only rather than editable, and `readOnly` rather than `disabled`: a
 * disabled input leaves the tab order and most screen readers skip it, so the
 * one group that most needs to hear what the form says about them would be the
 * group that could not reach it. `readOnly` looks the same and stays
 * announceable.
 *
 * Every value here is a single answer shared by every sheet, which is why
 * locking it is safe. Anything an individual office asks in its own words — the
 * sanitary classification, the occupancy split, the zoning project description
 * — stays a real question on that sheet, because two forms asking a
 * similar-sounding question are not always asking the same one.
 *
 * This block is also where four of the CPDD locational clearance's numbered
 * fields land: I. Name of Proprietor, III. Name of Firm, IV/VI. Address, and
 * V. Activity.
 */
function CarriedOverSection({ business }: { business: CarriedOverBusiness }) {
  return (
    <section className="space-y-3">
      <SectionMarker letter="✓" label="Business Details" />
      <p className="text-xs text-ink-muted">
        From your earlier answers. Change it on Business Information or Location &amp; Zoning and every
        office form follows.
      </p>
      {/*
        No `FromApplicationTag` on these four. The sentence directly above says
        exactly what the tag says, and saying it again beside every label put
        "(from your application)" on screen five times in one block — which is
        also what made the labels wrap and the rows tall. The tag stays where a
        carried field sits AMONG asked ones and the reader cannot tell which is
        which; here they are all carried and the heading says so.
      */}
      <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
        <DerivedField className="grow basis-[15rem]" label="Business Name" value={business.name} />
        {business.tradeName !== '' && (
          <DerivedField
            className="grow basis-[13rem]"
            label="Trade Name / Franchise"
            value={business.tradeName}
          />
        )}
        <DerivedField
          className="grow basis-[18rem]"
          label="Business Address"
          value={business.address}
        />
        <DerivedField
          className="grow basis-[18rem]"
          label="Line of Business"
          value={business.lineOfBusiness}
        />
      </div>
    </section>
  )
}

/* ── Per-office field bodies ──────────────────────────────────────────── */

function get(data: OfficeFormData, key: string): string {
  const v = data[key]
  return typeof v === 'string' ? v : ''
}

/** The filing date, formatted for display; blank until the API supplies it. */
function applicationDate(data: OfficeFormData): string {
  const raw = get(data, 'application_date')
  return raw === '' ? '' : formatDate(raw)
}

/** "Date of Application" reads from submitted_at and is never typed (item 11). */
function ApplicationDateField({ data }: { data: OfficeFormData }) {
  return (
    <DerivedField
      label={
        <>
          Date of Application
          <AutoTag />
        </>
      }
      value={applicationDate(data)}
      hint="Recorded by the system when you submit."
    />
  )
}

/*
 * The chip option sets live here rather than inline at each ChipRow, and are
 * exported so anything that needs to name one of these answers can reference
 * the list instead of retyping a string.
 *
 * ChipRow has no way to complain: given a value that is not one of its options
 * it simply renders nothing as selected. So a typo in a duplicated literal does
 * not fail loudly — it looks exactly like a required field the applicant never
 * answered, which is close to the worst way for a mistake of that kind to
 * present itself.
 */
export const WATER_SOURCES = ['Level III (Waterworks)', 'Deep Well', 'Bottled / Refill', 'Other']

export const SANITARY_CLASSIFICATIONS = [
  'Food Establishment',
  'Non-Food Establishment',
  'Personal / Public Service',
  'Industrial',
]
/*
 * ── The rest of the Sanitary sheet's choices, 5 October 2026 ─────────────────
 *
 * The City has no paper for this permit; client: *"Is it good if you make the
 * fields yourself … base it off from the common sanitary permit fields."* These
 * are the facilities PD 856 (Code on Sanitation) has the health officer check
 * on every establishment — water, toilets, sewage, refuse, vermin — in the
 * wording the standard LGU "Application for Sanitary Permit to Operate" uses.
 * Each list ends in Other so a true answer the list did not foresee is not
 * forced into a wrong one.
 */
export const TOILET_TYPES = ['Water-sealed flush', 'Pour-flush', 'Other']
export const SEWAGE_DISPOSALS = ['Public sewer (Maynilad)', 'Septic tank', 'Other']
export const SOLID_WASTE_DISPOSALS = [
  'City garbage collection',
  'Private hauler',
  'Composting / recycling',
  'Other',
]
export const PEST_CONTROL_MEASURES = ['Contracted pest control service', 'Own measures', 'None']
export const YES_NO = ['Yes', 'No']

export const OCCUPANCY_SCOPES = ['Full', 'Partial']

/**
 * MCG-CPDD-FO-003 VIII.E, "Type of Industrial Project".
 *
 * The paper prints these as five tick boxes in three columns — POLLUTIVE over
 * NON-POLLUTIVE, HAZARDOUS over NON-HAZARDOUS, then OTHER — which reads as two
 * independent yes/no judgements rather than one choice of five. A project can
 * plainly be both pollutive and hazardous. We ask it as a single choice because
 * that is what one row of boxes on a form usually means, and because guessing
 * wrong on a multi-select loses an answer the applicant thought they gave.
 * questions-for-malabon C9 asks CPDD which reading is right.
 */
export const ZONING_INDUSTRIAL_PROJECT_TYPES = [
  'Pollutive',
  'Non-Pollutive',
  'Hazardous',
  'Non-Hazardous',
  'Other',
]

/**
 * The CPDD locational clearance sheet, MCG-CPDD-FO-003 v1.2.
 *
 * Read the block at the top of this file for how this sheet came to be rebuilt.
 * The paper numbers its fields I to X, and most of them are answers the filing
 * already holds — so section A asks nothing at all, and B and C between them
 * ask three questions. Where a field is carried rather than asked, the comment
 * names the roman numeral it is standing in for, so a future reader can hold
 * this beside the form and see that nothing was dropped.
 *
 * I (proprietor), III (firm), IV/VI (addresses) and V (activity) are all in the
 * Business Details block that opens every sheet, above this component.
 */
/**
 * MCG-CPDD-FO-003 v1.2, "Application for Locational Clearance (Business
 * Activities)" — items I to IX, in the paper's own numbering.
 *
 * ── No invented sections ──────────────────────────────────────────────────
 *
 * This was carved into A/B/C — Application Details, Project Description,
 * Authorized Representative — and the paper has none of them. It has a numbered
 * run from I to IX and nothing above it, so the numbers ARE the structure and
 * printing them is both more faithful and more useful: an officer holding the
 * paper can find item VIII.C on the screen without reading a word.
 *
 * VIII.C and VIII.D were previously one derived sentence, "Leased from Acme
 * Realty", which read well and answered neither box. The paper wants the
 * lessor's name and the lessor's address separately, so they are separate.
 *
 * Item II, Home Address, is the one field on this form the register does not
 * hold anywhere — the same gap CENRO's "Owner's Address" hits, and the same
 * answer. It is asked here.
 */
function ZoningFields({
  data,
  set,
  business,
}: {
  data: OfficeFormData
  set: (key: string, value: string) => void
  business: CarriedOverBusiness
}) {
  const ro = useReadOnly()

  return (
    <div className="space-y-7">
      <section className="space-y-3">
        <h2 className="text-[15px] font-bold uppercase tracking-wide text-ink">
          Application for Locational Clearance (Business Activities)
        </h2>

        {/*
          The paper's masthead: the "Application No." box top right, which the
          counter fills in on receipt, and the date. Unnumbered on the form —
          they sit above item I — so they sit above item I here.

          MCZ is the prefix the register already mints zoning permits under, so
          the placeholder shows the shape of the number the applicant will
          eventually be given rather than a shape we made up for the box.
        */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <ControlNoField
            label={
              <>
                Locational Clearance No.
                <AutoTag />
              </>
            }
            placeholder="MCZ-2026-________"
          />
          <ApplicationDateField data={data} />
        </div>

        {/* I. NAME OF PROPRIETOR/PRESIDENT/GENERAL MANAGER · CONTACT NO. · EMAIL ADD. */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <DerivedField
            label={
              <>
                Name of Proprietor / President / General Manager
                <FromApplicationTag />
              </>
            }
            value={business.proprietorName}
          />
          <DerivedField
            label={<>Contact No.<FromApplicationTag /></>}
            value={business.proprietorContact}
          />
          <DerivedField
            label={<>Email Address<FromApplicationTag /></>}
            value={business.proprietorEmail}
          />
        </div>

        {/*
          II. HOME ADDRESS — an input, because nothing on the filing answers it.
          `business_addresses` carries an `address_type` column that has only
          ever held `business_location`, so the proprietor's residence has no
          home on the BPLO form. CENRO's sheet asks the same question under a
          different name; if BPLO ever grows the field, both become carried.

          No asterisk since 5 October 2026. It wore one while
          `officeFormMissing` accepted it blank — the paper (MCG-CPDD-FO-003)
          does not mark it mandatory, so the gate was right and the mark was
          the lie (tester).
        */}
        <label className="block">
          <FieldLabel>Home Address</FieldLabel>
          <input
            value={get(data, 'zoning_home_address')}
            onChange={(e) => set('zoning_home_address', e.target.value)}
            readOnly={ro}
            placeholder="No. of Street, Barangay, Municipality/City, Province"
            className={inputCls}
          />
          <CarriedTag field="zoning_home_address" />
        </label>

        {/* III. NAME OF FIRM · CONTACT NO.  |  IV. ADDRESS OF FIRM */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <DerivedField
            label={<>Name of Firm<FromApplicationTag /></>}
            value={business.name}
          />
          <DerivedField
            label={<>Contact No.<FromApplicationTag /></>}
            value={business.proprietorContact}
          />
          <DerivedField
            label={<>Address of Firm<FromApplicationTag /></>}
            value={business.address}
          />
          <DerivedField
            label={<>Activity<FromApplicationTag /></>}
            value={business.activity}
          />
        </div>

        {/*
          VI. LOCATION/ADDRESS OF PROJECT/ACTIVITY.

          The same address as item IV on every filing this system can produce —
          BizTrack records ONE premises per business, and it is where the trade
          happens. Printed anyway rather than folded into IV, because the paper
          asks twice and an officer checking the sheet against the form should
          find both boxes answered.
        */}
        <DerivedField
          label={<>Location / Address of Project or Activity<FromApplicationTag /></>}
          value={business.address}
        />

        {/* VII. NATURE OF APPLICATION — New Business or Renewal, from the filing. */}
        <DerivedField
          label={<>Nature of Application<FromApplicationTag /></>}
          value={get(data, 'application_type')}
        />

        {/* VIII. PROJECT DESCRIPTION */}
        <label className="block">
          <FieldLabel>Project Description</FieldLabel>
          <textarea
            rows={3}
            value={get(data, 'zoning_project_description')}
            onChange={(e) => set('zoning_project_description', e.target.value)}
            readOnly={ro}
            placeholder="e.g. Two-storey coffee shop with a small roasting area at the rear"
            className={inputCls}
          />
          <CarriedTag field="zoning_project_description" />
          <p className="mt-1 text-xs text-ink-muted">
            In your own words, what will be built or operated at this address.
          </p>
        </label>

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <DerivedField
            label={<>Floor Area to be / being Utilized<FromApplicationTag /></>}
            value={get(data, 'total_floor_area_sqm')}
            hint="Square metres, from your Business Operation answers."
          />
          {/*
            VIII.B — asked here now, not carried.

            It was derived from `fee_profile.storeys`, which the BPLO wizard
            collected on its Tax Classification & Fees step until 16 September
            2026. That box was removed because it priced nothing: measured
            against a filing holding all six clearances, the storey count moved
            the total by zero pesos. Only two fee rules read it at all — a
            lessor's building, by storey — and both need a fine permit category
            that is one of the sixty still open with BPLO.

            So the only consumer left was this line, on the one paper that
            actually asks for it. Left derived, it would print empty forever —
            exactly what happened to the lessor boxes below.
          */}
          <label className="block">
            <FieldLabel>No. of Storey of Building</FieldLabel>
            <input
              value={get(data, 'building_storeys')}
              onChange={(e) => set('building_storeys', e.target.value)}
              readOnly={ro}
              inputMode="numeric"
              placeholder="e.g. 2"
              className={inputCls}
            />
            <CarriedTag field="building_storeys" />
          </label>
          {/*
            VIII.C and VIII.D — "(if lessee)" on the paper, so they are blank on
            an owner-occupied site by design rather than by omission.
          */}
          {/*
            Asked here, not carried. They were read-only, derived from
            `businesses.lessor_name` / `lessor_address` — which the BPLO wizard
            collected until 16 September 2026, when the client removed those
            boxes as absent from MCG-BPLO-FO-001. They were right: that form
            asks whether rent is paid and nothing about the lessor.
            MCG-CPDD-FO-003 is the paper that asks, so this is the sheet that
            takes the answer. Left derived, both boxes would print empty
            forever.
          */}
          <label className="block">
            <FieldLabel>Name of Lessor (if lessee)</FieldLabel>
            <input
              value={get(data, 'lessor_name')}
              onChange={(e) => set('lessor_name', e.target.value)}
              readOnly={ro}
              placeholder="Full name"
              className={inputCls}
            />
            <CarriedTag field="lessor_name" />
          </label>
          <label className="block">
            <FieldLabel>Address of Lessor (if lessee)</FieldLabel>
            <input
              value={get(data, 'lessor_address')}
              onChange={(e) => set('lessor_address', e.target.value)}
              readOnly={ro}
              placeholder="No. of Street, Barangay, Municipality/City"
              className={inputCls}
            />
            <CarriedTag field="lessor_address" />
          </label>
        </div>

        <div>
          <FieldLabel>Type of Industrial Project</FieldLabel>
          <ChipRow
            label="Type of industrial project"
            options={ZONING_INDUSTRIAL_PROJECT_TYPES}
            value={get(data, 'zoning_industrial_project_type')}
            onChange={(v) => set('zoning_industrial_project_type', v)}
          />
          <CarriedTag field="zoning_industrial_project_type" />
          <p className="mt-1 text-xs text-ink-muted">
            Only for industrial projects. Leave it alone if yours is not one.
          </p>
        </div>

        {/*
          IX. AUTHORIZED REPRESENTATIVE.

          Asked on the BFP sheet first, so when that sheet is on the filing this
          one carries the answer read-only and the applicant is not asked twice.
          When it is not, nobody has asked, and this sheet takes the input.
        */}
        {get(data, 'authorized_representative_source') === 'FSIC' ? (
          <DerivedField
            label={
              <>
                Authorized Representative
                <span className="font-normal text-ink-muted"> (from your FSIC form)</span>
              </>
            }
            value={get(data, 'authorized_representative')}
            hint="Change it on the Fire Safety Inspection Certificate form and it follows here."
          />
        ) : (
          <label className="block">
            <FieldLabel>Authorized Representative</FieldLabel>
            <input
              value={get(data, 'authorized_representative')}
              onChange={(e) => set('authorized_representative', e.target.value)}
              readOnly={ro}
              placeholder="Full name"
              className={inputCls}
            />
            <CarriedTag field="authorized_representative" />
            <p className="mt-1 text-xs text-ink-muted">
              Leave blank if you are filing this yourself. If you name someone, CPDD asks for an
              authorization letter with your documents.
            </p>
          </label>
        )}
      </section>
    </div>
  )
}

/**
 * The Sanitary Permit sheet.
 *
 * ── Drawn, not transcribed ───────────────────────────────────────────────────
 *
 * The City Health Office gave us no paper for this permit. Client, 5 October
 * 2026: *"Is it good if you make the fields yourself. You may base it off from
 * the common sanitary permit fields in the internet. If something needs
 * auto-filling, do so."* So this is the standard LGU "Application for Sanitary
 * Permit to Operate" under PD 856: the establishment's class and size, then
 * the five things the sanitary inspector checks everywhere — water, toilets,
 * sewage, refuse, vermin — then the food-establishment extras. If the CHO's
 * own form ever turns up, these sections are
 * reordered to it and nothing else moves.
 *
 * ── What is carried and what is asked ────────────────────────────────────────
 *
 * Headcount, floor area and the health-certificate count are the Business &
 * Tax Profile's and are derived server-side (`OfficeFormAnswers`); the
 * classification is suggested from the line of business (`SanitaryPrefill`)
 * and editable; everything else is the applicant's to answer, because nothing
 * on the filing knows how many toilets a shop has.
 */
function SanitaryFields({
  data,
  set,
}: {
  data: OfficeFormData
  set: (key: string, value: string) => void
}) {
  const ro = useReadOnly()
  const isFood = get(data, 'sanitary_classification') === 'Food Establishment'
  const select = (key: string, options: readonly string[], required = false) => (
    <label className="block">
      <FieldLabel required={required}>{officeFormFieldLabel('SANITARY', key)}</FieldLabel>
      <select
        value={get(data, key)}
        onChange={(e) => set(key, e.target.value)}
        disabled={ro}
        aria-disabled={ro}
        className={inputCls}
      >
        <option value="">Select…</option>
        {options.map((o) => (
          <option key={o} value={o}>
            {o}
          </option>
        ))}
      </select>
      <CarriedTag field={key} />
    </label>
  )
  const yesNo = (key: string) => (
    <div>
      <FieldLabel>{officeFormFieldLabel('SANITARY', key)}</FieldLabel>
      <ChipRow options={YES_NO} value={get(data, key)} onChange={(v) => set(key, v)} />
      <CarriedTag field={key} />
    </div>
  )

  return (
    <div className="space-y-7">
      <section className="space-y-3">
        <SectionMarker letter="A" label="Application Details" />
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {/* New vs Renewal is the application's own type — never re-asked. */}
          <DerivedField
            label={
              <>
                Type of Application
                <FromApplicationTag />
              </>
            }
            value={get(data, 'application_type')}
          />
          <ControlNoField
            label={
              <>
                Sanitary Permit No.
                <AutoTag />
              </>
            }
            placeholder="SP-2026-________"
          />
          <ApplicationDateField data={data} />
        </div>
      </section>

      <section className="space-y-3">
        <SectionMarker letter="B" label="Establishment Profile" />
        <div>
          <FieldLabel required>Sanitary Classification</FieldLabel>
          <ChipRow
            options={SANITARY_CLASSIFICATIONS}
            value={get(data, 'sanitary_classification')}
            onChange={(v) => set('sanitary_classification', v)}
          />
          <CarriedTag field="sanitary_classification" />
        </div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {/*
            The headcount and the floor area are the Business & Tax Profile's —
            the figures the sanitary inspection fee is bracketed on — so they
            are carried, not asked again. See `OfficeFormAnswers`.
          */}
          <DerivedField
            label={<>Employees — Male<FromApplicationTag /></>}
            value={get(data, 'employees_male')}
          />
          <DerivedField
            label={<>Employees — Female<FromApplicationTag /></>}
            value={get(data, 'employees_female')}
          />
          <DerivedField
            label={<>Employees — Total<FromApplicationTag /></>}
            value={get(data, 'employees_total')}
          />
          <DerivedField
            label={<>Floor Area (sq. m.)<FromApplicationTag /></>}
            value={get(data, 'total_floor_area_sqm')}
          />
        </div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {/*
           * Asked once, on the Business & Tax Profile, and asked there because
           * that is where it is PRICED: the health certificate fee (Sec.
           * 4D.02) is ₱50 per employee per year against the headcount declared
           * on the profile, charged only when the applicant says their staff
           * need certificates.
           *
           * This box used to ask for the number a second time, in free text
           * that nothing read. Two answers to one question is the
           * capitalization case, and on this sheet it was the bad version of
           * it: the office would have read one number here and been handed a
           * Tax Order of Payment computed on another, for the same fee, on the
           * same filing.
           */}
          <DerivedField
            label={
              <>
                No. of Workers Requiring Health Certificates
                <FromApplicationTag />
              </>
            }
            value={get(data, 'workers_requiring_health_certs')}
            hint="From the employee count on your Business & Tax Profile — the same number the health certificate fee is charged on."
          />
          <label className="block">
            <FieldLabel>Operating Hours</FieldLabel>
            <input
              value={get(data, 'operating_hours')}
              onChange={(e) => set('operating_hours', e.target.value)}
              readOnly={ro}
              placeholder="e.g. 8:00 AM – 10:00 PM, Mon–Sat"
              className={inputCls}
            />
            <CarriedTag field="operating_hours" />
          </label>
        </div>
      </section>

      <section className="space-y-3">
        <SectionMarker letter="C" label="Water and Sanitation Facilities" />
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {select('water_source', WATER_SOURCES, true)}
          <label className="block">
            <FieldLabel required>No. of Toilets</FieldLabel>
            <input
              inputMode="numeric"
              value={get(data, 'toilets_count')}
              onChange={(e) => set('toilets_count', e.target.value)}
              readOnly={ro}
              placeholder="e.g. 2"
              className={`${inputCls} tnum`}
            />
            <CarriedTag field="toilets_count" />
          </label>
          {select('toilet_type', TOILET_TYPES)}
        </div>
        {yesNo('toilets_separate_sexes')}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {select('sewage_disposal', SEWAGE_DISPOSALS, true)}
          {select('solid_waste_disposal', SOLID_WASTE_DISPOSALS, true)}
        </div>
        {yesNo('waste_segregation')}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {select('pest_control', PEST_CONTROL_MEASURES)}
          <label className="block">
            <FieldLabel>Last Pest Control Treatment</FieldLabel>
            <input
              type="date"
              max={todayISO()}
              value={get(data, 'pest_control_last_date')}
              onChange={(e) => set('pest_control_last_date', e.target.value)}
              readOnly={ro}
              className={inputCls}
            />
            <CarriedTag field="pest_control_last_date" />
          </label>
        </div>
      </section>

      {/*
        Only a food establishment has seating to count or a kitchen to keep
        clean; shown when the classification says so, and the answers stay on
        the sheet if the classification is changed back.
      */}
      {isFood && (
        <section className="space-y-3">
          <SectionMarker letter="D" label="Food Establishment" />
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label className="block">
              <FieldLabel>Seating Capacity</FieldLabel>
              <input
                inputMode="numeric"
                value={get(data, 'seating_capacity')}
                onChange={(e) => set('seating_capacity', e.target.value)}
                readOnly={ro}
                placeholder="e.g. 40"
                className={`${inputCls} tnum`}
              />
              <CarriedTag field="seating_capacity" />
            </label>
            {yesNo('has_kitchen')}
            {yesNo('has_cold_storage')}
          </div>
        </section>
      )}

    </div>
  )
}
/**
 * The paper's legend, in the wording the client settled on.
 *
 * CNC is "Certificate on Non-Coverage" by their instruction (2026-09-08). The
 * form itself prints "Certificate on Non-Compliance", which reads like a
 * transcription slip on the paper — the DENR instrument is a non-coverage
 * certificate, issued to a project the ECC system does not cover.
 *
 * PTO carries its condition in the legend because that is where the paper puts
 * it, and because the system cannot answer it: nothing on a filing says whether
 * the business runs a generator.
 */
const DENR_GLOSSARY: { code: string; meaning: string }[] = [
  { code: 'ECC', meaning: 'Environmental Compliance Certificate' },
  { code: 'CNC', meaning: 'Certificate on Non-Coverage' },
  { code: 'WDP', meaning: 'Waste Water Discharge Permit' },
  { code: 'HWP', meaning: 'Hazardous Waste Permit' },
  { code: 'PTO', meaning: 'Permit to Operate (Air Pollution) — required if you have a generator' },
  { code: 'PCO', meaning: 'Registered Pollution Control Officer' },
]

/**
 * The CHECKLIST OF REQUIREMENTS printed on MCG-CPDD-FO-003 v1.2.
 *
 * ── Why the rows are not decided here ─────────────────────────────────────
 *
 * The paper's checklist branches: title, tax declaration and RPT clearance if
 * the site is OWNED, lease and lot-owner consent if it is RENTED, an
 * authorization letter only when somebody else is filing. All three conditions
 * are answers the filing already holds, so `App\Support\ZoningRequirements`
 * takes the branch and this renders what it is given. A second copy of the rule
 * in the browser is the shape this codebase has been bitten by repeatedly — a
 * rule private to one of two consumers — and the second consumer here is CPDD's
 * own review screen, which reads the same list.
 *
 * ── Three kinds of row, and only one of them takes a file ─────────────────
 *
 * `carried` is already on the filing: the applicant attached it at step 4 of
 * the business permit wizard, and asking again would invite a second answer to
 * a question already answered. `sheet` is this form. `upload` is the rest.
 *
 * ── The controls are the wizard's, not new ones ───────────────────────────
 *
 * The client, 9 September 2026: *"just copy the format of the uploading of
 * documents in the BPLO form, where the applicant can remove, download, etc."*
 * So an upload row is the wizard's dashed dropzone, and an attached file is the
 * wizard's file line — filename, size, the shared <DocumentActions> (View and
 * Download), then Remove. The first draft of this panel had a bare Upload
 * button and no way to see what had arrived, which is exactly the defect
 * checklist item 96 raised against the wizard and which <DocumentActions>
 * exists to answer: uploading the wrong scan is the easiest mistake here, and
 * it was the one mistake the screen would not let you check for.
 *
 * ── Every row here blocks the submit button ───────────────────────────────
 *
 * The client, 30 September 2026, reading this panel on the Locational
 * Clearance form: *"Are the documentary fields here not required? Make sure
 * they are required."* They are, now — all of them.
 *
 * It went the other way twice before, so the history is worth keeping. The
 * first reading was that the paper is a counter checklist a clerk ticks on
 * receipt rather than a gate, so a missing lease was shown, said plainly, and
 * submitted anyway — a document still being chased from another office is not
 * a reason to refuse somebody the form. The notarised Applicant Declaration
 * was excepted from that on 17 September, again on the client's report:
 * *"I wonder how I was able to submit the Locational Clearance without
 * submitting the Applicant Declaration."*
 *
 * What the exception turned out to prove is the rule. The reason given for it
 * — a zoning application whose declaration is missing is not one CPDD can act
 * on, so letting it through buys the applicant a return trip and a second
 * wait — is true of the lease and the tax declaration in exactly the same
 * way. The kindness of accepting an incomplete sheet was the applicant's own
 * loss, because the office has to send it back regardless.
 *
 * Notarisation itself is still an open question with the LGU
 * (questions-for-malabon C9 item 2) and none of this closes it. What is
 * settled is that the scan has to BE here, whatever route the signing takes.
 *
 * Which rows gate is `blocking` on each row, decided by the requirement
 * classes behind `App\Support\SheetRequirements` — a `sheet` row is exempt,
 * because it IS this form and cannot be satisfied before it is submitted.
 * This panel, CPDD's review screen and `WorkflowService::submitClearanceForm`
 * all read that one field, and the last of those is what enforces it: the
 * gate in ClearanceStagePage only disables a button.
 */
/**
 * The heading and the office's own name, per sheet.
 *
 * Two papers ask for documents and they call the box different things: CPDD
 * prints "CHECKLIST OF REQUIREMENTS", CENRO prints "REQUIREMENTS FOR
 * APPLICATION". Printing CPDD's title on CENRO's sheet would be the same fault
 * as inventing sections the paper does not have — and naming the wrong office
 * in the copy beneath it is worse, because the applicant would go and ask them.
 */
const REQUIREMENTS_META: Partial<Record<OfficeFormCode, { title: string; office: string }>> = {
  /*
   * ── The office's name, as the LGU writes it ──────────────────────────
   *
   * These were acronyms, and one of them was not even ours: the zoning
   * office read "CPDD" while the register seeds it as CPDO and the city's
   * own verification table calls it the Planning/Zoning Office. An
   * applicant reading "CPDD returned this about one item" is being told
   * which office sent their filing back in letters they have never seen.
   *
   * Taken from the client's own document-verification table (1 October
   * 2026), which is the same list `departments.name` holds:
   *
   *   Occupancy Permit                   Office of the Local Building Official
   *   Sanitary Permit/Health Certificate City Health Office
   *   City Environmental Certificate     City Environmental and Natural Resources Office
   *   Fire Safety Inspection Certificate Bureau of Fire Protection
   *   Zoning Clearance                   Planning/Zoning Office
   */
  ZONING: { title: 'Checklist of Requirements', office: 'Planning/Zoning Office' },
  CEC: {
    title: 'Requirements for Application',
    office: 'City Environmental and Natural Resources Office',
  },
}

function RequirementsChecklist({
  code,
  rows,
  returnTarget = null,
  returnNotes = null,
  busy,
  error,
  onChange,
  onDeclarationTemplate,
}: {
  code: OfficeFormCode
  rows: OfficeFormRequirement[]
  /** The rows an office pointed at on a return; see OfficeFormStep. */
  returnTarget?: string | null
  /** What the office said about each named row, keyed by its code. */
  returnNotes?: Record<string, string> | null
  busy: string | null
  error: string | null
  /**
   * A file to add, or null to remove one.
   *
   * `documentId` says WHICH to remove, because a row holds several now.
   * Without it the endpoint clears the whole slot — which is what Remove
   * meant when a slot held one file, and would now take the first page of
   * a lease off with the second.
   */
  onChange?: (documentCode: string, file: File | null, documentId?: number) => void | Promise<void>
  onDeclarationTemplate?: () => void
}) {
  const ro = useReadOnly()
  /*
   * The rows that stop the submit, named. `blocking` is the server's flag (see
   * the note above the panel), so this reads it rather than deciding it.
   *
   * Named while there are one or two: "1 is still missing" makes the applicant
   * hunt down the list for which. Past that the naming stops helping — a
   * sentence listing eight documents is a list in the wrong place, and the
   * list in the right place is directly underneath, every row saying for
   * itself whether it is filled.
   */
  const blocked = rows.filter((r) => r.blocking === true && !r.satisfied).map((r) => r.label)
  /*
   * What is "still missing" is what stops the submit — the rule
   * ClearanceStagePage gates on, `blocking && !satisfied` — and nothing
   * else. It counted every unsatisfied row until 5 October 2026, so the
   * optional rows and the sheet's own row (satisfied only BY submitting)
   * were in it: "5 are still missing" with 3 missing, and "1 is still
   * missing" after every upload was in (tester).
   */
  const outstanding = blocked.length
  const meta = REQUIREMENTS_META[code] ?? {
    title: 'Requirements',
    office: 'This office',
  }

  return (
    <section className="space-y-3 border-t border-line pt-7">
      <div>
        <h2 className="text-[15px] font-bold uppercase tracking-wide text-ink">{meta.title}</h2>
        <p className="mt-1 text-xs leading-relaxed text-ink-muted">
          {/*
            Required, and said once. Every row blocks now, so the old
            three-way sentence — these stop you, the rest you can add later —
            drew a distinction that no longer exists, and its second half made
            the promise the client had already tested and found untrue.
          */}
          {ro
            ? `What ${meta.office} received with this application.`
            : outstanding === 0
              ? `Everything ${meta.office} requires is here.`
              : blocked.length > 0 && blocked.length <= 2
                ? `${blocked.join(' and ')} ${blocked.length === 1 ? 'is' : 'are'} still missing. ${
                    blocked.length === 1 ? 'It has' : 'They have'
                  } to be uploaded before you can submit this form.`
                : `${meta.office} asks for the starred documents with the application. ${outstanding} ${
                    outstanding === 1 ? 'is' : 'are'
                  } still missing, and the form cannot be submitted until ${
                    outstanding === 1 ? 'it is' : 'they are'
                  } here.`}
        </p>
      </div>

      {error !== null && (
        <p
          role="alert"
          className="rounded-md border border-s-red bg-s-red-tint px-3 py-2 text-sm text-ink"
        >
          {error}
        </p>
      )}

      <div className="space-y-3">
        {rows.map((row) => (
          <RequirementRow
            key={row.key}
            row={row}
            /*
             * Matched on `code`, the document type — the same value the office
             * picked from and the same one this row uploads into. Never on the
             * label, which is prose and is translated and reworded.
             *
             * `targetsInclude` and not `===`: the column has held a
             * comma-separated list since the picker became a checklist on
             * 27 September 2026, and an office ticking two rows produced a
             * pointer equal to neither of them, so nothing was highlighted.
             */
            /*
             * `row.code` is nullable — a row already answered by a
             * business-permit attachment uploads nothing of its own — so it
             * cannot be a lookup key or a needle without being checked. The
             * `===` this replaced tolerated null by accident.
             */
            flagged={row.code !== null && targetsInclude(returnTarget, row.code)}
            /* What the office said about THIS row, when it said something. */
            flagNote={(row.code ? returnNotes?.[row.code] : null) ?? null}
            busy={busy === row.code}
            readOnly={ro}
            onChange={onChange}
            onDeclarationTemplate={
              /*
                The two rows that hand out a sworn page: CPDD's Section X
                declaration and BFP's affidavit of undertaking. Both are named
                on their paper with no layout printed for them, so the wording
                is the thing worth giving.
              */
              row.key === 'DECLARATION' || row.key === 'NO_CHANGES_AFFIDAVIT'
                ? onDeclarationTemplate
                : undefined
            }
          />
        ))}
      </div>
    </section>
  )
}

/** One checklist row, in the wizard's document-upload shape. */
function RequirementRow({
  row,
  flagged = false,
  flagNote = null,
  busy,
  readOnly,
  onChange,
  onDeclarationTemplate,
}: {
  row: OfficeFormRequirement
  /** Did the office point at THIS row when it sent the permit back? */
  flagged?: boolean
  /**
   * The office's own remark for this row.
   *
   * Beside the row it is about rather than in one paragraph above the
   * sheet — the same reasoning as the wizard's per-field boxes. Null on a
   * return that named rows without commenting on each.
   */
  flagNote?: string | null
  busy: boolean
  readOnly: boolean
  /**
   * A file to add, or null to remove one.
   *
   * `documentId` says WHICH to remove, because a row holds several now.
   * Without it the endpoint clears the whole slot — which is what Remove
   * meant when a slot held one file, and would now take the first page of
   * a lease off with the second.
   */
  onChange?: (documentCode: string, file: File | null, documentId?: number) => void | Promise<void>
  onDeclarationTemplate?: () => void
}) {
  /*
   * Any row but the sheet itself takes files — a carried one included.
   *
   * It did not until 30 September 2026: the business permit answered a
   * carried row, so asking again was asking twice. That missed the
   * applicant with a two-page lease and one page attached, and it became a
   * trap the moment every documentary row began blocking the submit, since
   * business permit documents cannot be added once the filing is paid and
   * this stage begins after payment. `code` is null on the sheet row and
   * non-null on the rest, so it carries the distinction on its own.
   */
  /*
   * Every file on this row. `documents` is the list the API sends now;
   * `document` is its first, and the fallback keeps a row rendering if a
   * payload from before 30 September 2026 is still in a tab.
   */
  const files = row.documents ?? (row.document === null ? [] : [row.document])
  /*
   * The copies that belong to the business permit. Remove here means "I
   * attached the wrong page to this checklist", never "take it off my
   * business permit", so it is not offered on these.
   */
  const fromPermit = new Set(row.carried_document_ids ?? [])
  /*
   * ── A row the business permit already answered is read-only ─────────────
   *
   * Client, 5 October 2026, on the zoning checklist: *"Why are some other
   * fields here already answered? If they came from fields from the business
   * permit application, then they should not be editable."* The dropzone on
   * a carried row dates from 30 September, for the applicant whose business
   * permit had NOTHING attached and who was otherwise stuck. That case keeps
   * it. A carried row the permit's own copy answers shows that copy and
   * where it came from, and takes no file — the place to change it is the
   * business permit's documents, which is where it lives.
   */
  const answeredByPermit = row.source === 'carried' && fromPermit.size > 0
  const takesFile = row.code !== null && !readOnly && onChange && !answeredByPermit

  return (
    <div
      /*
       * The mark, and it is a tint and a rule rather than a badge: the row
       * already carries its own tick or box, and a second status chip on it
       * would compete with the one that says whether the document is there.
       */
      /*
        Named so the panel at the top of the sheet can take the applicant
        straight to it. Only on a flagged row: an id per requirement would
        be six anchors nobody links to.
      */
      id={flagged && row.code !== null ? `return-${row.code}` : undefined}
      className={flagged ? '-mx-3 scroll-mt-4 rounded-md border-l-4 border-s-rose bg-s-rose-tint/40 px-3 py-2' : undefined}
    >
      {flagged && (
        <p className="mb-1 text-xs font-bold text-ink">This is what the office asked about</p>
      )}
      {flagged && flagNote && (
        <p className="mb-1.5 text-xs text-ink-secondary">{flagNote}</p>
      )}
      {/*
        ── No tick column ──────────────────────────────────────────

        A drawn checkbox and a green circle stood here, mirroring the
        box a clerk ticks on the paper. Neither could be clicked — the
        square was `aria-hidden` decoration — and both said what the row
        below already shows: a file line means the document is there, a
        dropzone means it is not.

        Client, 30 September 2026: *"make the format/layout of this
        similar to the documentary requirements of the business permit
        application form (no checkboxes)"* — which is a bold name, a
        note, and then the control. The 22px indents went with the
        marker they were clearing.
      */}
      {/*
        ── The form's own shape, where there is something to upload ─────────

        On the business permit form the dashed box IS the requirement: the
        name sits inside it, it stays one row tall however many files are
        attached, and the files list underneath. Copied here on the client's
        instruction of 30 September 2026.

        A `sheet` or `carried` row has no upload, so it keeps a plain heading
        — a dropzone with a name in it and nothing to drop would be copying
        the picture rather than the meaning.
      */}
      {takesFile ? (
        <label
          className={`flex cursor-pointer items-center gap-3 rounded-lg border-2 border-dashed border-input-border bg-input/50 px-5 py-3.5 transition-colors hover:bg-input ${
            busy ? 'pointer-events-none opacity-60' : ''
          }`}
        >
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-input-border bg-white text-royal">
            <UploadIcon size={18} />
          </span>
          <span className="min-w-0 flex-1">
            <span className="block text-sm font-bold text-ink">
              {row.label}
              {/*
                Section C's own mark, and read off the same flag that
                refuses the submit so the two cannot drift apart.
              */}
              {row.blocking === true && <span className="ml-1 text-s-red">*</span>}
            </span>
            {/*
              The note when there is nothing yet, the count once there is —
              the form's own wording, because the count is what tells an
              applicant the earlier pages are still attached.

              Not truncated, unlike the form's: a checklist note is a sentence
              explaining what the office wants ("A letter from the owner of
              the lot agreeing to the business being run there"), and cutting
              it at the tile edge would lose the half that says why.
            */}
            <span className="mt-0.5 block text-xs leading-relaxed text-ink-muted">
              {busy
                ? 'Uploading…'
                : files.length > 0
                  ? `${files.length} file${files.length === 1 ? '' : 's'} attached · click to add another`
                  : row.note}
            </span>
          </span>
          {files.length > 0 && (
            <span className="inline-flex shrink-0 items-center gap-1.5 text-sm font-semibold text-s-green">
              <CheckCircleFilledIcon size={16} /> Uploaded
            </span>
          )}
          <input
            type="file"
            accept={ACCEPT_ATTR}
            /*
              As many as they have. The office slot took one and DELETED the
              previous on every press, so the second page of a lease silently
              destroyed the first — see `storeRequirement`, which adds now.
            */
            multiple
            className="sr-only"
            disabled={busy}
            onChange={async (e) => {
              const chosen = Array.from(e.target.files ?? [])
              // Let the same file be picked twice — after a rejection the
              // input would otherwise be inert.
              e.target.value = ''
              if (!row.code) return
              /*
                ONE AT A TIME, awaited.

                Fired together they raced: every file reached the server,
                but each response carries a full snapshot of this row and
                the handler assigns it wholesale, so the panel ended up
                showing whichever response arrived last rather than every
                file that had landed. Picking two commonly showed one, and
                the other was on disk the whole time — a refresh proved it.
                Which is the worst shape for this to fail in: the applicant
                concludes the upload did not work and picks the file again.
              */
              for (const file of chosen) {
                await onChange!(row.code, file)
              }
            }}
          />
        </label>
      ) : (
        <>
          <p className="flex flex-wrap items-center gap-x-2 text-sm font-bold text-ink">
            {row.label}
            {row.blocking === true && <span className="-ml-1 text-s-red">*</span>}
            {answeredByPermit && (
              <span className="rounded-full bg-royal-tint px-2 py-0.5 text-[11px] font-semibold text-royal">
                From your Business Permit application
              </span>
            )}
          </p>
          <p className="mt-1 text-xs leading-relaxed text-ink-muted">
            {answeredByPermit
              ? 'Already attached to your Business Permit application. To change it, update that attachment.'
              : row.note}
          </p>
        </>
      )}

      {/*
        The declaration template, beside the row that asks for the scan of it.
        Section X is sworn before a notary and nothing in this flow can do
        that, so what the system CAN hand over is the exact page the notary
        expects — with the filing already named on it, so the scan that comes
        back is matchable to an application rather than to a business name.
      */}
      {onDeclarationTemplate !== undefined && (
        <button
          type="button"
          onClick={onDeclarationTemplate}
          className="mt-2 inline-flex items-center gap-1.5 rounded-md border border-royal px-3 py-1.5 text-xs font-semibold text-royal transition-colors hover:bg-royal-tint"
        >
          <DownloadIcon size={14} />
          Download the template
        </button>
      )}

      {/* The files themselves, under the box that adds them. */}
      {files.length > 0 && (
        <ul className="mt-2 space-y-2 pl-4">
          {files.map((file) => (
            <li
              key={file.id}
              className="flex items-center gap-3 rounded-lg border border-input-border bg-input/50 px-4 py-2.5"
            >
              <span className="min-w-0 flex-1 truncate text-sm text-ink">{file.filename}</span>
              {file.size_bytes !== null && (
                <span className="tnum shrink-0 text-xs text-ink-muted">
                  {formatBytes(file.size_bytes)}
                </span>
              )}
              {/*
                Labelled by filename, not by the requirement: two rows can name
                the same document — an owner's title answers TCT and a lessee's
                lease answers Contract of Lease, both from LEASE_TITLE — so
                "View Transfer Certificate of Title" twice over would name one
                file two ways to a screen reader.
              */}
              <DocumentActions id={file.id} filename={file.filename} />
              {takesFile && !fromPermit.has(file.id) && (
                <button
                  type="button"
                  onClick={() => void onChange!(row.code!, null, file.id)}
                  disabled={busy}
                  aria-label={`Remove ${file.filename}`}
                  className="shrink-0 text-sm font-semibold text-s-red underline underline-offset-2 disabled:opacity-60"
                >
                  Remove
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      {row.reference && (
        <p className="mt-2 text-xs text-ink-secondary">
          On file: <span className="tnum font-medium text-ink">{row.reference}</span>
        </p>
      )}

      {row.source === 'carried' && files.length === 0 && !row.satisfied && (
        <p className="mt-2 text-xs font-medium text-s-orange">
          Not attached yet. Add it to your business permit documents and it appears here.
        </p>
      )}
    </div>
  )
}

/**
 * "REQUIRED DENR PERMITS FOR APPLICATION" — told, not collected.
 *
 * The client: "our system will tell the applicant (in the CENRO application
 * form itself) the required DENR permits he/she needs to submit. Just list them
 * somewhere visible (it is not there where the applicant will submit the said
 * DENR permits; just a list)."
 *
 * So this renders no control at all. The permits are DENR's to issue, not the
 * LGU's to receive, and the form's own footnote gives the applicant six months
 * from the CEC's issuance to comply — a screen that demanded them here would
 * withhold a clearance the LGU is willing to grant.
 *
 * Every value is derived server-side from the declared business category, off
 * the same table the CENRO environmental fee is priced from
 * (`App\Support\DenrRequirements`). The matched row is printed BY NAME, because
 * a derivation the applicant cannot check is one they have to take on trust —
 * and if CENRO has them on the wrong row, the row is the thing they need to
 * see to say so.
 *
 * `denr_basis` absent means no row matched. That is a real and common state,
 * not an error: the category picker offers the Revenue Code's business-tax
 * vocabulary and CENRO's table uses its own, so a filing declaring
 * "Manufacturer" sits between the paper's big-scale and small-scale rows with
 * nothing to say which. Saying so is the only safe answer — printing "none
 * required" would be a claim, and the wrong one.
 */
function DenrRequirementsPanel({ data }: { data: OfficeFormData }) {
  const basis = get(data, 'denr_basis')
  const certificate = get(data, 'denr_certificate')
  const permits = get(data, 'denr_permits')
  const pco = get(data, 'denr_pco')
  const remarks = get(data, 'denr_remarks')

  /*
   * Which of the six this business needs.
   *
   * All six are listed either way, ticked or dashed. "You do not need a
   * Hazardous Waste Permit" is as useful to an applicant as the reverse and
   * costs one line to say — and a list of six is easier to read than a table
   * that names three of them and a glossary that repeats two.
   */
  const required = new Set<string>()
  if (certificate !== '') required.add(certificate)
  permits.split(',').forEach((p) => {
    const code = p.trim()
    if (code !== '' && code !== 'None') required.add(code)
  })
  if (pco === 'Required') required.add('PCO')

  return (
    <section className="space-y-3" aria-labelledby="denr-heading">
      <div className="flex items-center gap-2">
        <span className="h-4 w-1 rounded-full bg-s-green" aria-hidden="true" />
        <h3 id="denr-heading" className="text-sm font-bold text-ink">
          Required DENR permits for this business
        </h3>
      </div>

      {basis === '' ? (
        <div className="rounded-lg border border-line bg-canvas px-4 py-3">
          <p className="text-sm text-ink">
            CENRO will confirm which DENR permits your business needs.
          </p>
          {/*
           * Only one reason reaches this branch now, and naming it is the whole
           * improvement. The panel used to say "does not match one of CENRO's
           * listed categories closely enough" on almost every filing, because it
           * read the free-typed fee category and nothing else. It reads the PSIC
           * line of business too, and falls back to the paper's own row 28 for
           * anything genuinely unlisted — so an unanswered panel is now a
           * specific, explicable state rather than a shrug.
           *
           * That state is manufacturing. CENRO's table has two rows for it,
           * "All Big Scale" and "Small-Scale", with very different consequences,
           * and it defines neither. Saying so is better than picking.
           */}
          <p className="mt-1 text-xs leading-relaxed text-ink-secondary">
            {get(data, 'denr_reason') === 'scale'
              ? 'CENRO lists big-scale and small-scale manufacturing separately, so the office decides which yours is.'
              : 'The office decides this when they review your form.'}
          </p>
        </div>
      ) : (
        <div className="rounded-lg border border-s-green/40 bg-s-green-tint px-4 py-3">
          {/*
            One sentence, in the client's own framing: "put a very short,
            user-friendly explanation stating that this is based on the line of
            business from the business permit application form."
          */}
          <p className="text-xs leading-relaxed text-ink-secondary">
            Based on the line of business in your business permit application. DENR issues these,
            not the City — you do not submit them here.
          </p>

          {/*
            The six, in the paper's legend order, each ticked or dashed. This
            replaced a three-column table, a labelled remarks block and a
            glossary that repeated two of the codes — four devices saying what
            fits in six lines. The client: "Do not overcomplicate this part.
            Just list what is required from the 6 DENR permits."
          */}
          <ul className="mt-3 space-y-1.5">
            {DENR_GLOSSARY.map((g) => {
              const need = required.has(g.code)
              return (
                <li key={g.code} className="flex items-baseline gap-2 text-sm leading-relaxed">
                  <span
                    aria-hidden="true"
                    className={`w-4 shrink-0 text-center font-bold ${
                      need ? 'text-s-green' : 'text-ink-muted'
                    }`}
                  >
                    {need ? '✓' : '—'}
                  </span>
                  <span className={need ? 'text-ink' : 'text-ink-muted'}>
                    <span className="font-semibold">{g.code}</span> — {g.meaning}
                    {/* The tick is decorative; the state has to be readable. */}
                    <span className="sr-only">{need ? ' — required' : ' — not required'}</span>
                  </span>
                </li>
              )
            })}
          </ul>

          {/*
            Kept, as one plain line without its label. It is the only thing here
            that can CHANGE the list above — "if you handle hazardous or toxic
            materials, an ECC, a Hazardous Waste Permit and a Pollution Control
            Officer are required" — and the system cannot answer it, so dropping
            it would understate the requirements rather than simplify them.
          */}
          {remarks !== '' && (
            <p className="mt-3 text-xs leading-relaxed text-ink-secondary">{remarks}</p>
          )}

          {/*
            ── The deadline, and what happens if it passes ────────────────────

            The paper's own footnote, and the only line on this panel with a
            consequence attached: "The following required DENR permit/s must be
            submitted/complied to this office within six (6) months upon
            issuance of the CEC, on or before ______, otherwise the CEC issued
            will be automatically revoked."

            The clock runs from ISSUANCE, not from this form — so at the moment
            an applicant reads this there is no date to print. Saying "six
            months from the day CENRO issues it" is the honest version of the
            paper's blank line; the actual date belongs on the issued
            certificate, where issuance has happened and the arithmetic is real.

            Set apart with a rule above it because it is not another item in the
            list: everything above says WHAT is required, this says by when and
            what it costs to miss it.
          */}
          {/*
            The paper's footnote, VERBATIM — the client's instruction, and the
            right call. This was paraphrased ("these must be submitted to CENRO
            within six months of your CEC being issued…"), which read more
            smoothly and was not the text the applicant is being held to. A
            deadline with a revocation attached is exactly the sentence to quote
            rather than improve.

            The blank line is the paper's own: the date is filled in at the
            counter, and at the moment this sheet is read the CEC has not been
            issued, so there is nothing to put in it.
          */}
          <p className="mt-3 border-t border-s-green/30 pt-3 text-xs italic leading-relaxed text-ink">
            * The following required DENR permit/s must be submitted/complied to this office within
            six (6) months upon issuance of the CEC, on or before ______________, otherwise the CEC
            issued will be automatically revoked.
          </p>
        </div>
      )}
    </section>
  )
}

/**
 * MCG-CENRO-FO-001 v2.0, "Ownership and Documentation".
 *
 * The sheet used to be four boxes — type of application, control no., filing
 * date, owner's birthday — against a paper that asks for eighteen. The other
 * fourteen were not missing from the SYSTEM, only from this screen: BPLO
 * collects all but two of them, so they are carried over read-only rather than
 * asked twice, under the same rule as `CarriedOverSection`.
 *
 * The two the system genuinely did not hold are asked here, and both are
 * particular to this office's paper:
 *
 *  - OWNER'S ADDRESS. The register keeps the BUSINESS address (there is a
 *    `business_addresses.address_type` column, and nothing has ever written a
 *    residential row to it), so the proprietor's own address has no home in the
 *    BPLO form yet. It is asked here, plainly, rather than left blank on a
 *    sheet that prints a box for it. If the BPLO form ever grows item A16, this
 *    becomes a carried-over field like the rest and the question comes off.
 *  - THE CERTIFICATION was asked here as a tick over the owner's printed name,
 *    and removed on 5 October 2026 from every office sheet. Client: *"For ALL
 *    application forms containing this, please remove this."*
 *
 * Everything below the paper's REMARKS FINDINGS AND RECOMMENDATIONS rule is the
 * office's half — the evaluator, the chief, the DENR permit checklist — and is
 * deliberately not on the applicant's sheet.
 */
/**
 * MCG-CENRO-FO-001 v2.0, "OWNERSHIP AND DOCUMENTATION" — the whole sheet.
 *
 * ── One block, because the paper has one block ────────────────────────────
 *
 * This was carved into A/B/C/D — Application Details, Ownership, Business
 * Details, Certification — added as a reading aid when the sheet grew from four
 * fields to eighteen. The client removed them, and was right to: the paper has a
 * single band with everything under it, and inventing structure the source
 * document does not have is the same fault as leaving a field off it.
 *
 * The order below is the paper's own reading order, left column then right, row
 * by row. Not its LAYOUT — the paper is a two-column table sized for a
 * typewriter, and reproducing that on a phone would be unfaithful in a different
 * way. Same questions, same sequence.
 *
 * The shared Business Details block is suppressed for this sheet (see
 * OfficeFormSheet): this form asks for the business name, address and line of
 * business itself, so carrying them above as well printed all three twice.
 */
function CecFields({
  data,
  set,
  business,
}: {
  data: OfficeFormData
  set: (key: string, value: string) => void
  business: CarriedOverBusiness
}) {
  const ro = useReadOnly()
  const birthday = get(data, 'owner_birthday')
  const birthdayInFuture = birthday !== '' && birthday >= todayISO()

  return (
    <div className="space-y-7">
      <section className="space-y-3">
        <h2 className="text-[15px] font-bold uppercase tracking-wide text-ink">
          Ownership and Documentation
        </h2>

        {/* TYPE OF APPLICATION | TYPE OF BUSINESS */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <ApplicationDateField data={data} />
          <ControlNoField
            label={
              <>
                Control No.
                <AutoTag />
              </>
            }
            placeholder="CEC-2026-________"
          />
          <DerivedField
            label={
              <>
                Type of Application
                <FromApplicationTag />
              </>
            }
            value={get(data, 'application_type')}
          />
          <DerivedField
            label={
              <>
                Type of Business
                <FromApplicationTag />
              </>
            }
            value={business.registrationType}
          />
        </div>

        {/* BUSINESS AREA | TOTAL NO. OF EMPLOYEES (MALE / FEMALE) */}
        <div className="grid gap-3 sm:grid-cols-3">
          <DerivedField
            label={<>Business Area (in sq. m.)<FromApplicationTag /></>}
            value={business.businessAreaSqm}
          />
          <DerivedField
            label={<>Total No. of Employees — Male<FromApplicationTag /></>}
            value={business.maleEmployees}
          />
          <DerivedField
            label={<>Total No. of Employees — Female<FromApplicationTag /></>}
            value={business.femaleEmployees}
          />
        </div>

        {/* OWNER | BUSINESS NAME */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <DerivedField
            label={
              <>
                Owner (Family Name, First Name, Middle Name)
                <FromApplicationTag />
              </>
            }
            value={business.ownerName}
          />
          <DerivedField label={<>Business Name<FromApplicationTag /></>} value={business.name} />
        </div>

        {/* OWNER&rsquo;S ADDRESS | BUSINESS ADDRESS */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {/*
            The one address the register does not hold. `business_addresses` has
            an `address_type` column and nothing has ever written a residential
            row to it, so the proprietor's own address has no home on the BPLO
            form yet. Asked here as one line, matching the paper's single box and
            its own prompt. If BPLO ever grows item A16 this becomes a carried
            field like the rest and the question comes off.
          */}
          <label className="block">
            <FieldLabel required>Owner&rsquo;s Address</FieldLabel>
            <input
              value={get(data, 'owner_address')}
              onChange={(e) => set('owner_address', e.target.value)}
              readOnly={ro}
              placeholder="No. of Street, Barangay, Municipality/City, Province"
              className={inputCls}
            />
            <CarriedTag field="owner_address" />
          </label>
          <DerivedField
            label={<>Business Address<FromApplicationTag /></>}
            value={business.address}
          />
        </div>

        {/* BIRTHDAY | SEX */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div className="relative">
            <FieldLabel>Birthday</FieldLabel>
            {/* Birthdays can never be in the future: capped here, re-checked by the API. */}
            <input
              type="date"
              max={todayISO()}
              value={birthday}
              onChange={(e) => set('owner_birthday', e.target.value)}
              readOnly={ro}
              className={inputCls}
              aria-invalid={birthdayInFuture}
            />
            <CarriedTag field="owner_birthday" />
            {birthdayInFuture && (
              <FieldError>
                The birthday must be a date in the past.
              </FieldError>
            )}
          </div>
          {/* The word. `ownerSex` carries the stored 'M' or 'F'. */}
          <DerivedField
            label={<>Sex<FromApplicationTag /></>}
            value={genderLabel(business.ownerSex)}
          />
        </div>

        {/* LINE OF BUSINESS | PRODUCTS/SERVICES | CONTACT NUMBERS */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <DerivedField
            label={<>Line of Business<FromApplicationTag /></>}
            value={business.lineOfBusiness}
          />
          <DerivedField
            label={<>Products / Services<FromApplicationTag /></>}
            value={business.productsServices}
          />
          <DerivedField
            label={<>Contact Numbers — Landline<FromApplicationTag /></>}
            value={business.landline}
          />
          <DerivedField
            label={<>Contact Numbers — Mobile No.<FromApplicationTag /></>}
            value={business.mobile}
          />
        </div>
      </section>

      {/*
        ── The paper's REQUIREMENTS FOR APPLICATION is deliberately absent ─────

        All four of its items are things CENRO can already see, so printing a
        checklist of them would be busywork on the applicant's screen:

         - "Application for Renewal / New Business" — the officer reads the BPLO
           form beside this sheet;
         - "Tax Order of Payment and Official Receipt" — BizTrack issues the
           Tax Order of Payment and an invoice (`PaymentController::receipt`),
           which CENRO can open regardless (client, 9 September 2026). The
           Official Receipt comes from the City Treasurer, not BizTrack, so
           this item no longer rests only on what BizTrack produces;
         - "Business permit" — a new business does not have one; it is what this
           filing is FOR;
         - "Certificate of Environmental Compliance (Previous Year)" — renewals
           only, and renewals are not built yet.

        If any of that stops being true — an offline payment method, an office
        that cannot reach the invoice, CENRO wanting the Treasurer's Official
        Receipt — this is the block to bring back.
      */}

      <DenrRequirementsPanel data={data} />
    </div>
  )
}


function FsicFields({
  data,
  set,
  business,
}: {
  data: OfficeFormData
  set: (key: string, value: string) => void
  business: CarriedOverBusiness
}) {
  const ro = useReadOnly()
  return (
    <div className="space-y-7">
      <section className="space-y-3">
        <SectionMarker letter="A" label="Application Details" />
        <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
          <ControlNoField
            label={
              <>
                FSIC Application Number
                <AutoTag />
              </>
            }
            placeholder="FSIC-________"
          />
          {/*
            "NAME OF OWNER" on the BFP form. Carried, not asked: it is the
            name already given on the business permit application, and the
            establishment name and exact address beside it come from the same
            place through CarriedOverSection above.
          */}
          <DerivedField
            className="grow basis-[14rem]"
            label={
              <>
                Name of Owner
                <FromApplicationTag />
              </>
            }
            value={business.ownerName}
          />
          <label className="block grow basis-[14rem]">
            <FieldLabel>Authorized Representative</FieldLabel>
            {/*
             * Item 70 — the placeholder used to carry the rule ("Auto-filled
             * from Business Permit if blank"), which is the one thing about this
             * field that must survive the first keystroke and is exactly what a
             * placeholder does not. It is a hint below the box now, and the
             * placeholder is an example, as every other one on these sheets is.
             *
             * This is also where the CPDD locational clearance's IX. Authorized
             * Representative is answered whenever both sheets are on the filing:
             * one question, asked on the form that asked it first, carried onto
             * the zoning sheet read-only.
             */}
            <input
              value={get(data, 'authorized_representative')}
              onChange={(e) => set('authorized_representative', e.target.value)}
                readOnly={ro}
              placeholder="Full name"
              className={inputCls}
            />
            <p className="mt-1 text-xs text-ink-muted">
              Leave blank to use the name on the Business Permit application.
            </p>
          </label>
          <ApplicationDateField data={data} />
        </div>
      </section>

      <section className="space-y-3">
        <SectionMarker letter="B" label="The Premises" />
        <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
          {/*
            "TYPE OF OCCUPANCY / BUSINESS NATURE". Seeded from the line of
            business and editable, because BFP's vocabulary is not PSIC's — a
            restaurant is "Assembly" to a fire officer — and the applicant is
            the one who knows which.
          */}
          {/*
            Asked here, on BFP's own paper, since 5 October 2026. These two were
            read-only copies of the Occupancy sheet whenever that permit was on
            the filing, which made this sheet wait on that one. The sheets are
            separate now — client: "Make them separate" — and each paper asks
            its own boxes.
          */}
          <label className="block grow basis-[16rem]">
            <FieldLabel required>Type of Occupancy / Business Nature</FieldLabel>
            <input
              value={get(data, 'occupancy_type')}
              onChange={(e) => set('occupancy_type', e.target.value)}
              readOnly={ro}
              placeholder="e.g. Mercantile, Assembly, Business"
              className={inputCls}
            />
            <CarriedTag field="occupancy_type" />
          </label>
          <label className="block shrink-0">
            <FieldLabel required>No. of Storeys</FieldLabel>
            <input
              inputMode="numeric"
              value={get(data, 'building_storeys')}
              onChange={(e) => set('building_storeys', e.target.value)}
              readOnly={ro}
              placeholder="e.g. 2"
              className={`${inputCls} tnum w-[7rem]`}
            />
            <CarriedTag field="building_storeys" />
          </label>
          {/*
            The three the applicant has already given. Floor area is the same
            figure the zoning sheet is assessed on — item 1 of Business
            Operation — so it is carried rather than asked a third time.
          */}
          <DerivedField
            className="shrink-0"
            label="Total Floor Area (sq. m.)"
            value={business.businessAreaSqm}
          />
          <DerivedField
            className="grow basis-[12rem]"
            label="Contact Number"
            value={business.mobile || business.landline}
          />
          <DerivedField
            className="grow basis-[14rem]"
            label="E-mail Address"
            value={business.proprietorEmail}
          />
        </div>
      </section>

      <section className="space-y-3">
        <SectionMarker letter="C" label="Certificate Applied For" />
        {/*
         * The application type already decides this — new or renewal of the
         * Business Permit — so the BFP sheet carries it without asking the
         * applicant to repeat it. Never the Occupancy kind on a business filing;
         * see `OfficeFormAnswers`.
         */}
        <DerivedField
          label={
            <>
              Certificate Applied For
              <FromApplicationTag />
            </>
          }
          value={get(data, 'certificate_applied_for')}
          hint="Set from the application type you chose in step 1: a new business or a renewal."
        />
      </section>

    </div>
  )
}

function OccupancyFields({
  data,
  set,
  business,
}: {
  data: OfficeFormData
  set: (key: string, value: string) => void
  business: CarriedOverBusiness
}) {
  const ro = useReadOnly()
  return (
    <div className="space-y-7">
      <section className="space-y-3">
        <SectionMarker letter="A" label="Application & Permit Details" />
        <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
          {/*
           * Full vs Partial is how much of the building will be occupied — a
           * real applicant decision, not the new/renewal the system knows.
           */}
          <div className="shrink-0">
            <FieldLabel required>Application Type</FieldLabel>
            <ChipRow
              options={OCCUPANCY_SCOPES}
              value={get(data, 'application_type')}
              onChange={(v) => set('application_type', v)}
            />
            <CarriedTag field="application_type" />
          </div>
          <ApplicationDateField data={data} />
          <div>
            <FieldLabel>Building Permit No.</FieldLabel>
            <input
              value={get(data, 'building_permit_no')}
              onChange={(e) => set('building_permit_no', e.target.value)}
              readOnly={ro}
              className={inputCls}
            />
            <CarriedTag field="building_permit_no" />
          </div>
          <div>
            <FieldLabel>FSEC No.</FieldLabel>
            <input
              value={get(data, 'fsec_no')}
              onChange={(e) => set('fsec_no', e.target.value)}
              readOnly={ro}
              className={inputCls}
            />
            <CarriedTag field="fsec_no" />
          </div>
          {/*
            The paper prints a Date Issued under each of the two numbers, so
            each sits beside the number it belongs to — see the note on this
            change for why they are asked here and not on the officer's sheet.
          */}
          <label className="block shrink-0">
            <FieldLabel>Building Permit — Date Issued</FieldLabel>
            <input
              type="date"
              value={get(data, 'building_permit_date')}
              onChange={(e) => set('building_permit_date', e.target.value)}
              readOnly={ro}
              className={`${inputCls} w-[11rem]`}
            />
            <CarriedTag field="building_permit_date" />
          </label>
          <label className="block shrink-0">
            <FieldLabel>FSEC — Date Issued</FieldLabel>
            <input
              type="date"
              value={get(data, 'fsec_date')}
              onChange={(e) => set('fsec_date', e.target.value)}
              readOnly={ro}
              className={`${inputCls} w-[11rem]`}
            />
            <CarriedTag field="fsec_date" />
          </label>
        </div>
      </section>

      <section className="space-y-3">
        <SectionMarker letter="B" label="Owner / Permittee" />
        <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
          <DerivedField
            className="grow basis-[14rem]"
            label={
              <>
                Name of Owner / Permittee
                <FromApplicationTag />
              </>
            }
            value={business.ownerName}
          />
          {/*
            Asked, not carried, and the distinction matters on this one box.
            The paper wants where the OWNER lives; BizTrack holds where the
            BUSINESS is, and on a filing whose premises is rented those are
            different places. Prefilling the business address here would put a
            wrong answer on a form the Building Official posts to.
          */}
          <label className="block grow basis-[18rem]">
            <FieldLabel>Address of Owner / Permittee</FieldLabel>
            <input
              value={get(data, 'owner_address')}
              onChange={(e) => set('owner_address', e.target.value)}
              readOnly={ro}
              placeholder="If not the business address"
              className={inputCls}
            />
            <CarriedTag field="owner_address" />
          </label>
          {/* Printed beside the address on the paper; the OBO posts to it. */}
          <label className="block shrink-0">
            <FieldLabel>ZIP Code</FieldLabel>
            <input
              inputMode="numeric"
              value={get(data, 'owner_zip')}
              onChange={(e) => set('owner_zip', e.target.value)}
              readOnly={ro}
              placeholder="1470"
              className={`${inputCls} tnum w-[7rem]`}
            />
            <CarriedTag field="owner_zip" />
          </label>
          {/*
            ── From the paper, 4 October 2026 ───────────────────────────────

            The unified OBO form prints Tel. No. beside the owner's address,
            and under "Submitted by: Owner/Permittee" asks for the Community
            Tax Certificate — number, date and place issued. All four are the
            owner's to answer and were not asked. The CTC is optional: not
            every owner holds one today, and a blank the office can ask for is
            better than a gate that stops the sheet.
          */}
          <label className="block shrink-0">
            <FieldLabel>Tel. No.</FieldLabel>
            <input
              inputMode="tel"
              value={get(data, 'owner_tel')}
              onChange={(e) => set('owner_tel', e.target.value)}
              readOnly={ro}
              className={`${inputCls} w-[11rem]`}
            />
            <CarriedTag field="owner_tel" />
          </label>
          <label className="block shrink-0">
            <FieldLabel>Community Tax Certificate No.</FieldLabel>
            <input
              value={get(data, 'owner_ctc_no')}
              onChange={(e) => set('owner_ctc_no', e.target.value)}
              readOnly={ro}
              className={`${inputCls} w-[12rem]`}
            />
            <CarriedTag field="owner_ctc_no" />
          </label>
          <label className="block shrink-0">
            <FieldLabel>CTC — Date Issued</FieldLabel>
            <input
              type="date"
              value={get(data, 'owner_ctc_date')}
              onChange={(e) => set('owner_ctc_date', e.target.value)}
              readOnly={ro}
              className={`${inputCls} w-[11rem]`}
            />
            <CarriedTag field="owner_ctc_date" />
          </label>
          <label className="block shrink-0">
            <FieldLabel>CTC — Place Issued</FieldLabel>
            <input
              value={get(data, 'owner_ctc_place')}
              onChange={(e) => set('owner_ctc_place', e.target.value)}
              readOnly={ro}
              placeholder="Malabon City"
              className={`${inputCls} w-[12rem]`}
            />
            <CarriedTag field="owner_ctc_place" />
          </label>
          <DerivedField
            className="grow basis-[12rem]"
            label={
              <>
                Tel. No.
                <FromApplicationTag />
              </>
            }
            value={business.mobile || business.landline}
          />
        </div>
      </section>

      <section className="space-y-3">
        <SectionMarker letter="C" label="The Project" />
        <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
          {/*
            Seeded from the business name and editable: a project on the
            Building Official's books is often named for the structure rather
            than the trade in it, and only the applicant knows which it is.
          */}
          <label className="block grow basis-[14rem]">
            <FieldLabel required>Name of Project</FieldLabel>
            <input
              value={get(data, 'project_name')}
              onChange={(e) => set('project_name', e.target.value)}
              readOnly={ro}
              className={inputCls}
            />
            <CarriedTag field="project_name" />
          </label>
          <label className="block grow basis-[18rem]">
            <FieldLabel required>Location of Project</FieldLabel>
            <input
              value={get(data, 'project_location')}
              onChange={(e) => set('project_location', e.target.value)}
              readOnly={ro}
              placeholder="Lot / Block / Street / Barangay"
              className={inputCls}
            />
            <CarriedTag field="project_location" />
          </label>
          {/*
            This sheet OWNS the occupancy type and the storey count — BFP's
            paper prints them too and carries these answers read-only. See the
            note above the component.
          */}
          <label className="block grow basis-[16rem]">
            <FieldLabel required>Use / Character of Occupancy</FieldLabel>
            <input
              value={get(data, 'occupancy_type')}
              onChange={(e) => set('occupancy_type', e.target.value)}
              readOnly={ro}
              placeholder="e.g. Mercantile, Assembly, Business"
              className={inputCls}
            />
            <CarriedTag field="occupancy_type" />
          </label>
          <label className="block shrink-0">
            <FieldLabel required>No. of Storeys</FieldLabel>
            <input
              inputMode="numeric"
              value={get(data, 'building_storeys')}
              onChange={(e) => set('building_storeys', e.target.value)}
              readOnly={ro}
              placeholder="e.g. 2"
              className={`${inputCls} tnum w-[7rem]`}
            />
            <CarriedTag field="building_storeys" />
          </label>
          <label className="block shrink-0">
            <FieldLabel required>No. of Units</FieldLabel>
            <input
              inputMode="numeric"
              value={get(data, 'building_units')}
              onChange={(e) => set('building_units', e.target.value)}
              readOnly={ro}
              placeholder="e.g. 1"
              className={`${inputCls} tnum w-[7rem]`}
            />
            <CarriedTag field="building_units" />
          </label>
          {/* On both the unified form and the Certificate of Completion; it was asked on neither sheet here. */}
          <label className="block shrink-0">
            <FieldLabel required>Total Floor Area (sq. m.)</FieldLabel>
            <input
              inputMode="decimal"
              value={get(data, 'total_floor_area_sqm')}
              onChange={(e) => set('total_floor_area_sqm', e.target.value)}
              readOnly={ro}
              className={`${inputCls} tnum w-[10rem]`}
            />
            <CarriedTag field="total_floor_area_sqm" />
          </label>
          {/*
            Set by the system, never typed: the day this sheet is submitted,
            and today while it is being filled in (OfficeFormAnswers::derive).
            It was a free date box, which let an applicant claim any date at
            all — request of 6 October 2026.
          */}
          <DerivedField
            className="shrink-0"
            label={
              <>
                Date of Completion
                <AutoTag />
              </>
            }
            value={(() => {
              const raw = get(data, 'completion_date')
              return raw === '' ? '' : formatDate(raw)
            })()}
          />
        </div>
      </section>
    </div>
  )
}

/* ── Office form sheet (composed step) ────────────────────────────────── */

export function OfficeFormSheet({
  code,
  data,
  business,
  onChange,
  readOnly = false,
  requirements,
  returnTarget = null,
  returnNotes = null,
  carried = {},
  requirementBusy = null,
  requirementError = null,
  onRequirementChange,
  onDeclarationTemplate,
}: {
  code: OfficeFormCode
  data: OfficeFormData
  business: CarriedOverBusiness
  onChange: (data: OfficeFormData) => void
  /**
   * The checklist of requirements, on the one sheet whose paper has one.
   *
   * Undefined means "not loaded, or this office has no checklist", and the
   * panel renders nothing rather than an empty list — four offices claiming
   * they ask for no documents would be four wrong claims.
   */
  requirements?: OfficeFormRequirement[]
  /**
   * The document code or answer key an office pointed at when it sent this
   * permit back, or null.
   *
   * Marks one row so the applicant can see what to fix without reading a
   * paragraph and guessing. It does NOT gate anything: the client's decision of
   * 17 September 2026 was highlight-only, because a pointer aimed at the wrong
   * row — or a fix that turns out to be a phone call — must not leave somebody
   * unable to resubmit and unable to say so.
   */
  returnTarget?: string | null
  /** One note per returned row, keyed by the code `returnTarget` names. */
  returnNotes?: Record<string, string> | null
  /**
   * Answers still showing last year's value on a renewal, offered and not yet
   * reviewed. Flagged per field by `CarriedTag`; see the note there for why it
   * is not one banner.
   */
  carried?: Record<string, CarriedSource>
  /** The document code with an upload in flight, so one row can say so. */
  requirementBusy?: string | null
  requirementError?: string | null
  onRequirementChange?: (
    documentCode: string,
    file: File | null,
    documentId?: number,
  ) => void | Promise<void>
  /** Fetch Section X of the CPDD paper, blank, for the applicant's notary. */
  onDeclarationTemplate?: () => void
  /**
   * Render what was submitted, not a form to fill in.
   *
   * True once the office holds this clearance. Defaulted to false so the
   * editing case — every existing caller — is unchanged, and so a caller that
   * has not thought about it gets the safe-to-use rather than the safe-to-lock
   * behaviour; the SERVER is what actually refuses a late write
   * (`OfficeFormController::ownerMayEdit`), and this is the screen agreeing
   * with it rather than the other way round.
   */
  readOnly?: boolean
}) {
  const meta = OFFICE_FORM_META[code]
  const set = (key: string, value: string) => onChange({ ...data, [key]: value })

  /*
   * ── Shown is answered ─────────────────────────────────────────────────
   *
   * Three boxes used to DISPLAY a value from the Business Permit
   * application when empty — `value={answer || business.lineOfBusiness}` —
   * without it ever becoming the answer. The required-field check then
   * read the box as blank and refused "Next" over a field that looked
   * filled in. Client, 5 October 2026: *"Why is this auto-filled, but not
   * considered an answer"*.
   *
   * So the value is written into the sheet as its answer, ONCE per field
   * per opening: an applicant who clears the box to type their own is not
   * refilled under their cursor. Flagged "From your Business Permit
   * application" while it still reads as seeded, so it is visibly not the
   * applicant's own words, and editable like any answer.
   *
   * OCCUPANCY's Location of Project and Total Floor Area joined on
   * 5 October 2026. Both opened empty and were listed as missing while the
   * same values stood beside them as "(from your application)" (tester) —
   * the display-only boxes are gone and the value is the answer instead.
   *
   * A dash is not a value: `carriedOver` renders an unknown address or
   * trade as "—", and seeding that would answer a required box with
   * nothing.
   */
  const seedable = (value: string) => (value.trim() === '—' ? '' : value)
  const seeds: Record<string, string> =
    code === 'FSIC'
      ? { occupancy_type: seedable(business.lineOfBusiness) }
      : code === 'OCCUPANCY'
        ? {
            project_name: business.name,
            occupancy_type: seedable(business.lineOfBusiness),
            project_location: seedable(business.address),
            total_floor_area_sqm: business.businessAreaSqm,
          }
        : {}
  const seededOnce = useRef<Set<string>>(new Set())
  useEffect(() => {
    /*
     * Not before the saved sheet has arrived. A sheet read from the server
     * always carries its derived answers (the application date at least),
     * so an empty one is still loading — and seeding it would race the
     * saved answers, which the caller will not merge over an edit.
     */
    if (readOnly || Object.keys(data).length === 0) return
    const fill: OfficeFormData = {}
    for (const [key, value] of Object.entries(seeds)) {
      if (seededOnce.current.has(key) || !value.trim()) continue
      seededOnce.current.add(key)
      if (!get(data, key).trim()) fill[key] = value
    }
    if (Object.keys(fill).length > 0) onChange({ ...data, ...fill })
    // Keyed on the seed values: `seeds` is a fresh object every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [readOnly, code, business.name, business.lineOfBusiness, business.address, business.businessAreaSqm, data])
  const carriedShown: Record<string, CarriedSource> = { ...carried }
  for (const [key, value] of Object.entries(seeds)) {
    if (!carriedShown[key] && value.trim() && get(data, key) === value) carriedShown[key] = 'business'
  }

  return (
    <ReadOnlyContext.Provider value={readOnly}>
      {/* Which answers are still last year’s; see CarriedTag. */}
      <CarriedContext.Provider value={carriedShown}>
    <div className="rounded-sm bg-white px-6 py-7 shadow-card sm:px-9 sm:py-8">
      {readOnly && (
        <div className="mb-4 rounded-lg border border-s-green/40 bg-s-green-tint px-4 py-3">
          <p className="text-sm font-semibold text-ink">
            This is what you submitted to this office.
          </p>
          <p className="mt-1 text-xs leading-relaxed text-ink-secondary">
            It cannot be changed now that the office has it. If something is wrong, message the
            office from the card you came from and they can send it back to you.
          </p>
        </div>
      )}
      {/*
        The correction panel is NOT here. It is a section of the page,
        rendered above this sheet by ClearanceStagePage — because that
        is where BPLO's is: heading on the page background, one white
        card under it. Rendered inside this card it was a box inside a
        box, which is the difference the client photographed.
      */}
      <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-royal">{meta.kicker}</p>
      <h1 className="mt-1.5 text-2xl font-bold text-ink">{meta.title}</h1>
      <p className="mt-1 text-xs text-ink-muted">Form Ref: {meta.ref}</p>
      {/*
        CLR-2 — where the exit is, said on the screen the mistake leads to.

        This sheet exists because Apply was pressed on the clearance card, and
        on the three sheets with required answers it cannot be SAVED without
        them. An applicant who pressed Apply by mistake — the Market Clearance
        card is on the grid for every business in the city — has no reason to
        guess where the way out is. The audit of 2026-08-06 found five real
        drafts stuck at exactly this point.

        The trap is smaller than it was and the note matters more, which is why
        it survived the reordering rather than going with the mechanism. It used
        to be a WIZARD trap: the sheet was a step, Next stayed disabled and the
        section map refused to skip it, so an unfinished sheet stood between the
        filing and submission. The sheets are not steps now — this one opens
        over the clearance cards, and Back without saving always works — so
        nobody is stranded.

        What replaced the stranding is worse in the one way that counts: Apply
        now spends money the moment it is pressed, against a balance that holds
        the permit until it is settled. So the sentence names the fee, which the
        old one had no need to.

        One line of ordinary text under the form reference, not a banner. It is
        addressed to a minority (most people reading this sheet want it), it is
        not an error, and nothing here is wrong — a tinted panel would say
        otherwise to everyone else. Only on the sheets that can actually block a
        save; the rest can simply be left blank.
      */}
      {/*
        Rewritten twice over, and both corrections are the same shape: it was
        describing controls and consequences that no longer exist.
        `Withdraw` is gone — `ClearanceService::unapply` refuses every required
        clearance and all five are required — and nothing here touches a
        balance, because the bill was settled at submission. What IS still true
        is the way out, so that is all it says now.
      */}
      {officeFormCanBlock(code) && !readOnly && (
        <p className="mt-2 text-xs text-ink-muted">
          Opened this by mistake? Nothing here reaches the office until you press{' '}
          <span className="font-semibold">Submit to this office</span>, so you can leave it and come
          back. Your fees do not change either way.
        </p>
      )}
      <div className="mb-4 mt-3 h-px bg-royal" />
      <div className="space-y-7">
        {/* First, so the sheet opens by showing what it already knows rather
          * than by asking. */}
        {/*
          Not on the CEC or ZONING sheets. MCG-CENRO-FO-001 asks for the business
          name, address and line of business ITSELF, in its own order, so the
          shared block would print all three twice — which is what the client
          saw. MCG-CPDD-FO-003 does the same under its own numbering: III. Name
          of Firm, IV. Address of Firm, V. Activity. The other three papers open
          by asking for them, so they keep it.
        */}
        {code !== 'CEC' && code !== 'ZONING' && <CarriedOverSection business={business} />}
        {code === 'ZONING' && <ZoningFields data={data} set={set} business={business} />}
        {code === 'SANITARY' && <SanitaryFields data={data} set={set} />}
        {code === 'CEC' && <CecFields data={data} set={set} business={business} />}
        {code === 'FSIC' && <FsicFields data={data} set={set} business={business} />}
        {code === 'OCCUPANCY' && <OccupancyFields data={data} set={set} business={business} />}
        {requirements !== undefined && requirements.length > 0 && (
          <RequirementsChecklist
            code={code}
            rows={requirements}
            returnTarget={returnTarget}
            returnNotes={returnNotes}
            busy={requirementBusy}
            error={requirementError}
            onChange={onRequirementChange}
            onDeclarationTemplate={onDeclarationTemplate}
          />
        )}
      </div>
    </div>
      </CarriedContext.Provider>
    </ReadOnlyContext.Provider>
  )
}
