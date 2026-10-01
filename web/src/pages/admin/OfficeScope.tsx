import type { AnalyticsScope } from '../../lib/types'

/*
 * Whose figures these are, and — for the two readers allowed — a way to change it.
 *
 * Checklist 2026-09-27, item 1: one analytics dashboard for every office. An
 * office admin sees their own office and nothing to change; BPLO and the super
 * admin get a real <select> with every office and "All offices".
 *
 * A visible, labelled control rather than another field inside the filter
 * menu. The office decides every figure on the screen, and a reader who cannot
 * see which office is in force is a reader who will quote BFP's numbers as the
 * city's. The office accounts get the same sentence without the control, for
 * the same reason.
 *
 * The label comes from the SERVER's `scope`, never from local state. The server
 * decides the office (App\Support\AnalyticsOffice) and can overrule a request,
 * so the only honest label is the one that came back with the figures.
 */
export const ALL_OFFICES = 'all'

export function OfficeScope({
  scope,
  onChange,
  id = 'analytics-office',
}: {
  scope: AnalyticsScope
  onChange: (office: string) => void
  id?: string
}) {
  if (!scope.can_switch) {
    return (
      <p className="text-[13px] text-ink-secondary">
        Showing <strong className="font-semibold text-ink">{scope.office_name}</strong> figures
      </p>
    )
  }

  /*
   * The label sits BESIDE the select rather than around it: a wrapping label
   * folds the chosen option into the control's accessible name ("Office All
   * offices BPLO — …"), which a screen reader then announces on every visit.
   *
   * Capped width on a phone only: the longest office name would otherwise size
   * the select wider than the screen, and the dashboard scrolled sideways at
   * 390px because of it. From `sm` up the select takes the width of its longest
   * option instead. The phone's cap used to apply everywhere, so at 1440px the
   * closed menu read "BPLO — Business Permits an" with room to spare beside it.
   */
  return (
    <span className="flex min-w-0 items-center gap-2 text-[13px] text-ink-secondary">
      <label htmlFor={id}>Office</label>
      <select
        id={id}
        value={scope.office ?? ALL_OFFICES}
        onChange={(event) => onChange(event.target.value)}
        className="h-10 w-full min-w-0 max-w-[15rem] rounded-lg sm:w-auto sm:max-w-none border border-line bg-white px-3 text-[14px] font-medium text-ink focus:border-royal focus:outline-none focus:ring-2 focus:ring-royal/30"
      >
        <option value={ALL_OFFICES}>All offices</option>
        {scope.offices.map((office) => (
          <option key={office.code} value={office.code}>
            {office.code} — {office.name}
          </option>
        ))}
      </select>
    </span>
  )
}
