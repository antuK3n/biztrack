import type { AmendmentRow, RequestedChange } from '../lib/types'

/**
 * ── What an amendment changes, as "label · current → new" ─────────────────
 *
 * Tester, 5 October 2026: the applicant never saw what their own amendment
 * changed. Review named the fields ("Trade name, Total employees") with no
 * values, the confirmation dialog was generic, and the filing's page never
 * read `requested_changes` at all — so the one person who asked for the
 * change was the one person who could not check it.
 *
 * One block, used by the wizard's Review step, its confirmation dialog and
 * ApplicationDetailPage, so the three screens cannot word the same change
 * three ways. Each reader hands over its own row shape; the two helpers
 * below map either onto `ChangeLine`.
 */
interface ChangeLine {
  field: string
  label: string
  /** What the register holds (or held, once applied). Null reads as a dash. */
  from: string | null
  to: string | null
}

/*
 * The pin is a "lat,lng" pair, which nobody can read as an address. Whether
 * it moved is the only fact worth saying — and it is the fact that costs a
 * new Zoning Clearance (`WorkflowService::amendmentMovesPremises`).
 */
const PIN_FIELD = 'address_pin'

/*
 * The four free-text boxes (`AmendableFields`, type `note`). They replace
 * nothing on the register, so "— → text" would print a dash as if a value
 * had been lost; the text alone is the honest line.
 */
const NOTE_FIELD = /(_details|^other_amendment)$/

function readable(value: string | null | undefined, label: string | null | undefined): string | null {
  const shown = (label ?? value ?? '').trim()

  return shown === '' ? null : shown
}

/** The wizard's rows: only the ones asked for, current → new. */
function linesFromAmendmentRows(rows: AmendmentRow[]): ChangeLine[] {
  return rows
    .filter((r) => r.requested)
    .map((r) => ({
      field: r.field,
      label: r.label,
      from: readable(r.current_value, r.current_label),
      to: readable(r.new_value, r.new_label),
    }))
}

/**
 * The filing's rows. Once applied, `old_value` is what the change actually
 * replaced, which is the record worth showing — the register's current value
 * by then IS the new one.
 */
function linesFromRequestedChanges(rows: RequestedChange[]): ChangeLine[] {
  return rows.map((r) => ({
    field: r.field,
    label: r.label,
    from: r.applied_at
      ? readable(r.old_value, r.old_label)
      : readable(r.current_value, r.current_label),
    to: readable(r.new_value, r.new_label),
  }))
}

/**
 * Either row shape: the wizard's `AmendmentRow`s (only the requested ones are
 * drawn) or the filing's `requested_changes`. Mapped in here rather than by
 * each caller, so this file exports a component and nothing else.
 */
export function RequestedChanges(
  props: { amendRows: AmendmentRow[] } | { requested: RequestedChange[] },
) {
  const lines =
    'amendRows' in props
      ? linesFromAmendmentRows(props.amendRows)
      : linesFromRequestedChanges(props.requested)
  if (lines.length === 0) return null

  return (
    <dl className="divide-y divide-line text-left text-sm">
      {lines.map((line) => (
        <div
          key={line.field}
          className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 py-1.5"
        >
          <dt className="font-semibold text-ink">{line.label}</dt>
          <dd className="min-w-0 text-ink-secondary">
            {line.field === PIN_FIELD ? (
              'Map location moved'
            ) : NOTE_FIELD.test(line.field) ? (
              <span className="text-ink">{line.to ?? '—'}</span>
            ) : (
              <>
                <span>{line.from ?? '—'}</span>
                <span aria-hidden="true"> → </span>
                <span className="sr-only"> changes to </span>
                <span className="font-semibold text-ink">{line.to ?? '—'}</span>
              </>
            )}
          </dd>
        </div>
      ))}
    </dl>
  )
}
