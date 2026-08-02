/*
 * Demo autofill — the fast path through the apply wizard.
 *
 * Filing one application by hand means answering the office-form sheets and
 * attaching a dozen separate files: a six-permit application requires six
 * documents for the Business Permit alone and two more per clearance. That is
 * most of the clicking, and it is the reason testing a change to a later step
 * costs several minutes of unrelated data entry.
 *
 * With VITE_DEMO_AUTOFILL on, the wizard fills each step as it is reached.
 * Every answer still travels the real path: the office sheets round-trip
 * through POST /office-forms and the attachments through POST /documents, so
 * the rows that land are the same shape as a real applicant's. Nothing here
 * writes to the database directly and nothing bypasses validation — the point
 * is to skip the typing, not the plumbing.
 *
 * The generated attachments say what they are, in the image itself. A prefilled
 * form that looks hand-entered is a liability in front of a panel; one that
 * renders "DEMO DATA — not a real document" across the page cannot be mistaken
 * for evidence when an officer opens it in the review screen.
 */

import {
  OCCUPANCY_SCOPES,
  SANITARY_CLASSIFICATIONS,
  WATER_SOURCES,
  type OfficeFormCode,
  type OfficeFormData,
} from '../pages/applicant/OfficeFormStep'

/*
 * Off unless asked for, and never inferred from import.meta.env.DEV.
 *
 * The tunnel that testers use serves the Vite dev server, so DEV is true there.
 * Defaulting on would prefill a real tester's sheets with invented answers and
 * let them submit them as their own application, which is worse than any amount
 * of saved typing. Opting in is a line in web/.env.development.
 */
export const DEMO_AUTOFILL: boolean =
  import.meta.env.VITE_DEMO_AUTOFILL === 'true' || import.meta.env.VITE_DEMO_AUTOFILL === '1'

/**
 * Small deterministic hash, so the same draft always fills the same way.
 *
 * Math.random() would give a different sanitary classification every time the
 * step is revisited, which makes a screenshot unreproducible and a bug report
 * unrepeatable. Seeding off the application id spreads answers across the option
 * sets — so a batch of demo filings is not all "Food Establishment" — while
 * keeping any single draft stable.
 */
function seeded(seed: number, salt: string): number {
  let h = seed | 0
  for (let i = 0; i < salt.length; i++) h = (Math.imul(h, 31) + salt.charCodeAt(i)) | 0
  return Math.abs(h)
}

function pick<T>(options: readonly T[], seed: number, salt: string): T {
  return options[seeded(seed, salt) % options.length]
}

/** A past date, which is the only thing officeFormMissing() checks CEC for. */
function demoBirthday(seed: number): string {
  const year = 1968 + (seeded(seed, 'birth-year') % 30)
  const month = 1 + (seeded(seed, 'birth-month') % 12)
  const day = 1 + (seeded(seed, 'birth-day') % 28)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${year}-${pad(month)}-${pad(day)}`
}

/**
 * Plausible answers for one office sheet, keyed exactly as the sheet writes
 * them.
 *
 * Only the applicant-typed fields appear here. `application_date`,
 * `application_type` on SANITARY and CEC, and FSIC's `certificate_applied_for`
 * are derived: OfficeFormController re-applies them over the submitted payload
 * on every write, so anything set for them here would be discarded on the way
 * in and is left out rather than written and silently overwritten.
 */
export function demoOfficeForm(code: OfficeFormCode, seed: number): OfficeFormData {
  switch (code) {
    case 'SANITARY':
      return {
        sanitary_classification: pick(SANITARY_CLASSIFICATIONS, seed, 'sanitary'),
        workers_requiring_health_certs: String(1 + (seeded(seed, 'workers') % 12)),
        water_source: pick(WATER_SOURCES, seed, 'water'),
      }
    case 'CEC':
      return { owner_birthday: demoBirthday(seed) }
    case 'FSIC':
      return { authorized_representative: 'Demo Representative' }
    case 'OCCUPANCY':
      return {
        application_type: pick(OCCUPANCY_SCOPES, seed, 'occupancy'),
        building_permit_no: `BP-2026-${String(seeded(seed, 'bp') % 10000).padStart(4, '0')}`,
        fsec_no: `FSEC-2026-${String(seeded(seed, 'fsec') % 10000).padStart(4, '0')}`,
      }
  }
}

/** Filename-safe slug for the generated attachment. */
function slug(label: string): string {
  return (
    label
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '') || 'document'
  )
}

/** Wrap `text` to at most `max` characters per line, breaking on spaces. */
function wrap(text: string, max: number): string[] {
  const lines: string[] = []
  let line = ''
  for (const word of text.split(/\s+/)) {
    if (line && (line + ' ' + word).length > max) {
      lines.push(line)
      line = word
    } else {
      line = line ? line + ' ' + word : word
    }
  }
  if (line) lines.push(line)
  return lines
}

/**
 * A placeholder attachment for one requirement, generated in the browser.
 *
 * Drawn rather than shipped as a fixture so the document type is rendered into
 * the image: opening it in the officer's review screen shows which requirement
 * it stands in for and that it is not real. A single checked-in placeholder
 * would be indistinguishable across all eleven requirements, and a bundled
 * fixture per type would be eleven binaries in the repo for a testing
 * convenience.
 *
 * PNG at this size measures around 100 KB — comfortably inside the 10 MB cap,
 * and `image/png` is one of the four mime types DocumentController accepts.
 */
export function demoDocumentFile(label: string): Promise<File> {
  const canvas = document.createElement('canvas')
  canvas.width = 1000
  canvas.height = 1400
  const ctx = canvas.getContext('2d')
  if (!ctx) return Promise.reject(new Error('Canvas is unavailable, so demo attachments cannot be generated.'))

  ctx.fillStyle = '#f4f5fb'
  ctx.fillRect(0, 0, canvas.width, canvas.height)

  ctx.strokeStyle = '#3242ca'
  ctx.lineWidth = 8
  ctx.strokeRect(40, 40, canvas.width - 80, canvas.height - 80)

  ctx.textAlign = 'center'

  ctx.fillStyle = '#3242ca'
  ctx.font = 'bold 90px system-ui, sans-serif'
  ctx.fillText('DEMO DATA', canvas.width / 2, 320)

  ctx.fillStyle = '#1a1f36'
  ctx.font = 'bold 56px system-ui, sans-serif'
  wrap(label, 22).forEach((line, i) => {
    ctx.fillText(line, canvas.width / 2, 480 + i * 76)
  })

  ctx.fillStyle = '#6b7280'
  ctx.font = '40px system-ui, sans-serif'
  ctx.fillText('not a real document', canvas.width / 2, 820)
  ctx.fillText('generated by BizTrack for testing', canvas.width / 2, 880)

  // Diagonal watermark, so a cropped screenshot of the file still reads as demo.
  ctx.save()
  ctx.translate(canvas.width / 2, canvas.height / 2)
  ctx.rotate(-Math.PI / 6)
  ctx.fillStyle = 'rgba(50, 66, 202, 0.10)'
  ctx.font = 'bold 150px system-ui, sans-serif'
  ctx.fillText('SAMPLE', 0, 0)
  ctx.restore()

  return new Promise((resolve, reject) => {
    canvas.toBlob((blob) => {
      if (!blob) {
        reject(new Error('Could not generate the demo attachment.'))
        return
      }
      resolve(new File([blob], `demo-${slug(label)}.png`, { type: 'image/png' }))
    }, 'image/png')
  })
}
