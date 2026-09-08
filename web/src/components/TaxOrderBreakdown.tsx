import { StatusChip } from './ui/Proto'
import { formatMoney } from '../lib/format'
import type { FeeAssessment, FeeLineItem } from '../lib/types'

/*
 * Shared Tax Order of Payment line-item breakdown (applicant FeeDialog +
 * Pay page + officer review). Revenue-code assessments group items by
 * collecting office with subtotals and per-line citations; legacy
 * { label, amount } assessments render as the original flat list.
 * Callers keep their own Total Amount footer and Pay actions.
 */

/** Canonical collecting-office order; unknown offices append after. */
const OFFICE_ORDER = ['BPLO', 'CTO', 'CPDO', 'CHO', 'CENRO', 'OBO', 'BFP']

const OFFICE_LABELS: Record<string, string> = {
  BPLO: 'Business Permits & Licensing Office (BPLO)',
  CTO: "City Treasurer's Office (CTO)",
  CPDO: 'City Planning and Development Office (CPDO)',
  CHO: 'City Health Office (CHO)',
  CENRO: 'City Environment & Natural Resources Office (CENRO)',
  OBO: 'Office of the Building Official (OBO)',
  BFP: 'Bureau of Fire Protection (BFP)',
}

function amountOf(item: FeeLineItem): number {
  const n = typeof item.amount === 'string' ? Number(item.amount) : item.amount
  return Number.isFinite(n) ? n : 0
}

/** "Sec. 2A.01 · Ord. A10-2016" — whichever citation parts exist. */
function citation(item: FeeLineItem): string {
  return [item.section, item.source].filter(Boolean).join(' · ')
}

function LineRow({ item, showCitations }: { item: FeeLineItem; showCitations: boolean }) {
  const pending = item.requires_officer && amountOf(item) === 0
  const cite = showCitations ? citation(item) : ''
  return (
    <li className="flex items-baseline justify-between gap-4 text-base text-ink">
      <span className="min-w-0">
        <span>{item.label}</span>
        {item.requires_officer && (
          <StatusChip tone="tint-yellow" className="ml-2 align-middle">
            Officer assessment
          </StatusChip>
        )}
        {cite && <span className="block text-xs text-ink-muted">{cite}</span>}
      </span>
      <span className="tnum shrink-0">{pending ? '—' : formatMoney(item.amount)}</span>
    </li>
  )
}

export function TaxOrderBreakdown({
  fee,
  showCitations = false,
}: {
  fee: FeeAssessment | null | undefined
  /** Officer views only: per-line legal citations + the ordinance footnote. */
  showCitations?: boolean
}) {
  const items = fee?.line_items ?? []
  if (items.length === 0) {
    return (
      <ul className="min-h-24">
        <li className="text-sm text-ink-muted">No fees assessed yet.</li>
      </ul>
    )
  }

  // Legacy assessments carry only { label, amount }: keep the flat list as-is.
  const rich = items.some((li) => li.office)
  if (!rich) {
    return (
      <ul className="min-h-24 space-y-2">
        {items.map((li, i) => (
          <li key={i} className="flex items-baseline justify-between text-base text-ink">
            <span>{li.label}</span>
            <span className="tnum">{formatMoney(li.amount)}</span>
          </li>
        ))}
      </ul>
    )
  }

  // Group by office, canonical order first, any unknown offices after.
  const byOffice = new Map<string, FeeLineItem[]>()
  for (const li of items) {
    const key = li.office ?? 'OTHER'
    const bucket = byOffice.get(key)
    if (bucket) bucket.push(li)
    else byOffice.set(key, [li])
  }
  const offices = [
    ...OFFICE_ORDER.filter((o) => byOffice.has(o)),
    ...[...byOffice.keys()].filter((o) => !OFFICE_ORDER.includes(o)),
  ]

  return (
    <div className="min-h-24">
      <div className="space-y-5">
        {offices.map((office) => {
          const group = byOffice.get(office) ?? []
          const subtotal = group.reduce((sum, li) => sum + amountOf(li), 0)
          return (
            <section key={office} aria-label={OFFICE_LABELS[office] ?? office}>
              <div className="flex items-baseline justify-between gap-4 border-b border-line pb-1">
                <h3 className="text-[11px] font-bold uppercase tracking-wide text-ink-secondary">
                  {OFFICE_LABELS[office] ?? office}
                </h3>
                <span className="tnum text-xs font-semibold text-ink-secondary">
                  {formatMoney(subtotal)}
                </span>
              </div>
              <ul className="mt-2 space-y-2">
                {group.map((li, i) => (
                  <LineRow key={li.code ?? `${office}-${i}`} item={li} showCitations={showCitations} />
                ))}
              </ul>
            </section>
          )
        })}
      </div>
      {showCitations ? (
        <p className="mt-5 text-xs text-ink-muted">
          Computed from the New Revenue Code of Malabon (Ord. A10-2016). Lines marked for officer
          assessment are finalized during review.
        </p>
      ) : (
        items.some((li) => li.requires_officer) && (
          <p className="mt-5 text-xs text-ink-muted">
            Lines marked for officer assessment are finalized during review.
          </p>
        )
      )}
    </div>
  )
}
