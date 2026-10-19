/**
 * Business Operation's numbered questions, written once.
 *
 * The same words are printed in four places — the box on the form, the
 * "Still needed on this part" line under it, the Review summary, and the
 * fee-profile check that produces the still-needed entries — and until
 * 19 October 2026 each place spelled them for itself. That is how the form
 * said "No. of Male Employees" over a box while the list under it said
 * "2. Number of Male Employees", and how one number came to be printed twice.
 *
 * ── 2a / 2b, not 2 and 2 ─────────────────────────────────────────────────
 *
 * Client, 19 October 2026, reading the still-needed line "2. Number of Male
 * Employees, 2. Number of Female Employees": *"to avoid repetition of
 * numbers, you may put 2a, 2b, etc. instead. APPLY that number scheming to the
 * application form itself too."* Item 2 on MCG-BPLO-FO-001 is one box split
 * MALE / FEMALE, and item 4 is one box the Revenue Code needs split into two
 * rates, so each part takes its parent's number and a letter. The parent keeps
 * the plain number, on the frame's heading.
 *
 * The total employees box inside item 2's frame carries no letter: it is not
 * asked, it adds itself up, and lettering it would make the applicant look
 * for an answer to give.
 *
 * Only duplicated numbers become letters. Every other number mirrors the
 * paper's sequence and is left as it is.
 */
export const OPERATION_LABELS = {
  floor_area_sqm: '1. Business Area (sq. m.)',
  employees: '2. Total No. of Employees',
  male_employees: '2a. No. of Male Employees',
  female_employees: '2b. No. of Female Employees',
  employees_in_lgu: '3. No. of Employees Residing within Malabon',
  delivery_units: '4. No. of Delivery Units',
  delivery_vehicles_motorized: '4a. No. of Motorized Delivery Units',
  delivery_vehicles_other: '4b. No. of Other Delivery Units',
  economic_organization: '5. Economic Organization',
  capital_investment: '6. Capital Investment (₱)',
} as const

/**
 * Sort a step's still-needed entries into the order the step asks them.
 *
 * Client, 19 October 2026: *"Still needed on this part: 5. Economic
 * Organization, 1. Business Area (sq. m.), 2. Number of Male Employees … —
 * The order of the list seems incorrect."* The list was in the order the
 * CHECKS ran — the wizard's own question first, then the fee profile's, then
 * capital — which is nobody's order but the code's.
 *
 * Entries that start with an item number ("2a. …") are ordered by it, the
 * letter breaking ties. Entries without one keep their relative order and go
 * last: on Business Operation those are the questions under "How your trade
 * is taxed", which sit below item 8 on the page. A step whose unnumbered
 * entries come FIRST on screen (Business Information's "which business are
 * you renewing") must put them in front itself.
 */
export function inItemOrder(labels: readonly string[]): string[] {
  const key = (label: string): [number, string] | null => {
    const match = /^(\d+)([a-z]?)\.\s/.exec(label)
    return match ? [Number(match[1]), match[2]] : null
  }

  return labels
    .map((label, index) => ({ label, index, key: key(label) }))
    .sort((a, b) => {
      if (a.key && b.key) {
        return a.key[0] - b.key[0] || a.key[1].localeCompare(b.key[1]) || a.index - b.index
      }
      if (a.key) return -1
      if (b.key) return 1
      return a.index - b.index
    })
    .map((entry) => entry.label)
}
