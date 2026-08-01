import { useState } from 'react'
import { ChevronDownIcon, DownloadIcon, PaymentsIcon } from '../../components/icons'
import { TaxOrderBreakdown } from '../../components/TaxOrderBreakdown'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { PageTitle, SortFilter } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { formatDate, formatMoney } from '../../lib/format'
import { applications, payments } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { FeeAssessment, Payment } from '../../lib/types'

/*
 * Payment History (PDF p21–22): white shadow rows with bold "Ref No. :",
 * italic "Paid:" date, a serif peso amount + chevron; expanding a row reveals
 * that payment's serif Tax Order of Payment card (fetched lazily from the
 * payment's application via the existing applications resource).
 */

/** Serif peso rendering like the prototype ("P 100.00"). */
function serifPeso(amount: string | number): string {
  const num = typeof amount === 'string' ? Number(amount) : amount
  if (!Number.isFinite(num)) return '₱ 0.00'
  return `₱ ${num.toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
}

type FeeDetail = FeeAssessment | 'loading' | 'none'

const SORT_OPTIONS = [
  { value: 'date_desc', label: 'Newest first' },
  { value: 'date_asc', label: 'Oldest first' },
  { value: 'amount_desc', label: 'Amount: high to low' },
  { value: 'amount_asc', label: 'Amount: low to high' },
  { value: 'status', label: 'Status (A to Z)' },
]

const STATUS_OPTIONS = [
  { value: 'all', label: 'All statuses' },
  { value: 'completed', label: 'Completed' },
  { value: 'pending', label: 'Pending' },
  { value: 'failed', label: 'Failed' },
  { value: 'refunded', label: 'Refunded' },
]

/*
 * "2026-07-24" comparison key for a payment's paid date (empty when unpaid).
 *
 * The register stores UTC; the applicant is in Asia/Manila, eight hours ahead.
 * Slicing the ISO string took the UTC calendar day, so anything paid between
 * midnight and 8am Manila belonged to the previous day as far as the filter
 * was concerned — while the row beside it displayed the Manila date. Picking
 * "today" hid a payment the same screen said was made today. The date the
 * filter matches has to be the date the list shows, so derive both from the
 * viewer's own calendar.
 */
const dayKeyFmt = new Intl.DateTimeFormat('en-CA', {
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
})

export function paidDay(p: Payment): string {
  if (!p.paid_at) return ''
  const d = new Date(p.paid_at)
  return Number.isNaN(d.getTime()) ? '' : dayKeyFmt.format(d)
}

function sortPayments(list: Payment[], sortKey: string): Payment[] {
  const amount = (p: Payment) => Number(p.amount) || 0
  const date = (p: Payment) => p.paid_at ?? ''
  const sorted = [...list]
  switch (sortKey) {
    case 'date_asc':
      return sorted.sort((a, b) => date(a).localeCompare(date(b)))
    case 'amount_desc':
      return sorted.sort((a, b) => amount(b) - amount(a))
    case 'amount_asc':
      return sorted.sort((a, b) => amount(a) - amount(b))
    case 'status':
      return sorted.sort((a, b) => a.status.localeCompare(b.status))
    default:
      return sorted.sort((a, b) => date(b).localeCompare(date(a)))
  }
}

function TaxOrderCard({ payment, detail }: { payment: Payment; detail: FeeDetail | undefined }) {
  const fee = typeof detail === 'object' ? detail : null
  return (
    <div className="rounded-xl bg-white px-7 py-6 shadow-card">
      <p className="display-serif text-xl text-ink">
        Reference No: <span className="ml-3 tnum">{payment.reference_number}</span>
      </p>
      <div className="display-serif mt-4 flex items-baseline justify-between border-b border-ink/40 pb-2 text-lg text-ink">
        <span>Description</span>
        <span>Charge</span>
      </div>
      {detail === 'loading' ? (
        <p className="mt-4 text-sm text-ink-muted">Loading fee details…</p>
      ) : fee ? (
        /*
         * The same breakdown the application page and the Pay page render.
         * This card used to keep its own flat list, which grouped nothing by
         * office and printed a line still awaiting an officer's figure as
         * "₱0.00" — a receipt claiming a fee was zero when it was simply not
         * set yet. One receipt, told one way.
         */
        <div className="mt-3">
          <TaxOrderBreakdown fee={fee} />
        </div>
      ) : (
        <ul className="mt-3 space-y-2">
          <li className="flex items-baseline justify-between text-base text-ink">
            <span>Permit fees</span>
            <span className="tnum">{formatMoney(payment.amount)}</span>
          </li>
        </ul>
      )}
      <div className="display-serif mt-5 flex items-baseline justify-between border-t border-ink/40 pt-3 text-xl text-ink">
        <span>Total Amount:</span>
        <span className="tnum">{formatMoney(fee ? fee.total_amount : payment.amount)}</span>
      </div>
    </div>
  )
}

export function PaymentsPage() {
  const { data, loading, error, reload } = useAsync(() => payments.history(), [])
  const list = data ?? []

  const [sortKey, setSortKey] = useState('date_desc')
  const [statusFilter, setStatusFilter] = useState('all')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')

  const visible = sortPayments(
    list.filter((p) => {
      if (statusFilter !== 'all' && p.status !== statusFilter) return false
      const day = paidDay(p)
      if (dateFrom && (!day || day < dateFrom)) return false
      if (dateTo && (!day || day > dateTo)) return false
      return true
    }),
    sortKey,
  )

  const [openId, setOpenId] = useState<number | null>(null)
  const [details, setDetails] = useState<Record<number, FeeDetail>>({})
  const [receiptBusy, setReceiptBusy] = useState<number | null>(null)
  const [receiptError, setReceiptError] = useState<string | null>(null)

  async function downloadReceipt(p: Payment) {
    setReceiptBusy(p.id)
    setReceiptError(null)
    try {
      await payments.receipt(p.id, `receipt-${p.reference_number}.pdf`)
    } catch (err) {
      setReceiptError(toApiError(err).message)
    } finally {
      setReceiptBusy(null)
    }
  }

  function toggle(p: Payment) {
    if (openId === p.id) {
      setOpenId(null)
      return
    }
    setOpenId(p.id)
    if (details[p.id] === undefined) {
      if (!p.application) {
        setDetails((d) => ({ ...d, [p.id]: 'none' }))
        return
      }
      setDetails((d) => ({ ...d, [p.id]: 'loading' }))
      applications
        .get(p.application.id)
        .then((app) => setDetails((d) => ({ ...d, [p.id]: app.fee_assessment ?? 'none' })))
        .catch(() => setDetails((d) => ({ ...d, [p.id]: 'none' })))
    }
  }

  return (
    <div>
      <PageTitle
        right={
          <SortFilter
            sort={{ value: sortKey, options: SORT_OPTIONS, onChange: setSortKey }}
            filter={{ value: statusFilter, options: STATUS_OPTIONS, onChange: setStatusFilter }}
            dateRange={{
              from: dateFrom,
              to: dateTo,
              onChange: (from, to) => {
                setDateFrom(from)
                setDateTo(to)
              },
            }}
          />
        }
      >
        Payment History
      </PageTitle>

      {loading ? (
        <SkeletonList rows={3} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : list.length === 0 ? (
        <EmptyState
          icon={PaymentsIcon}
          title="No payments yet"
          description="Once an application reaches the payment stage and you pay, receipts appear here."
        />
      ) : (
        <>
        {receiptError && (
          <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
            {receiptError}
          </p>
        )}
        {visible.length === 0 && (
          <p className="rounded-xl bg-white px-6 py-5 text-sm text-ink-secondary shadow-card">
            No payments match the current filter.
          </p>
        )}
        <ul className="space-y-5">
          {visible.map((p) => {
            const open = openId === p.id
            return (
              <li key={p.id} className="space-y-3">
                <div className="flex w-full items-center gap-4 rounded-xl bg-white px-7 py-5 shadow-card">
                  <button
                    type="button"
                    onClick={() => toggle(p)}
                    aria-expanded={open}
                    className="flex min-w-0 flex-1 items-center gap-5 text-left"
                  >
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-lg font-bold text-ink">
                        Ref No. : <span className="tnum">{p.reference_number}</span>
                      </span>
                      <span className="mt-0.5 block text-sm italic text-ink-muted">
                        Paid: {formatDate(p.paid_at)}
                      </span>
                    </span>
                    <span className="display-serif tnum shrink-0 text-2xl text-ink">
                      {serifPeso(p.amount)}
                    </span>
                    <ChevronDownIcon
                      size={26}
                      className={`shrink-0 text-ink-secondary transition-transform ${open ? 'rotate-180' : ''}`}
                    />
                  </button>
                  <button
                    type="button"
                    onClick={() => downloadReceipt(p)}
                    disabled={receiptBusy === p.id}
                    className="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-royal px-4 py-1.5 text-xs font-semibold text-royal hover:bg-royal-tint disabled:opacity-60"
                  >
                    <DownloadIcon size={14} />
                    {receiptBusy === p.id ? 'Preparing…' : 'Receipt'}
                  </button>
                </div>
                {open && (
                  <div className="pl-4 sm:pl-8">
                    <TaxOrderCard payment={p} detail={details[p.id]} />
                  </div>
                )}
              </li>
            )
          })}
        </ul>
        </>
      )}
    </div>
  )
}
