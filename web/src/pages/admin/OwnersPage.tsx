import { useEffect, useMemo, useRef, useState } from 'react'
import { admin } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { toApiError } from '../../lib/api'
import { formatDate, formatDateTime, formatMoney } from '../../lib/format'
import type { AdminBusiness, AuditLog, BusinessStatus } from '../../lib/types'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import {
  FieldLabel,
  PageTitle,
  ProtoCard,
  FilterPills,
  ProtoModal,
  StatusChip,
  inputCls,
  useDialogKeyboard,
} from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { BuildingIcon } from '../../components/icons'
import { BUSINESS_STATUS } from '../../lib/status'

/*
 * Business Owner Status (PDF p99–101): the real /admin/businesses roster with
 * live status chips, the Changing Status modal wired to
 * POST /admin/businesses/{id}/status (all four statuses real), and the
 * audit-fed Status History dot-timeline modal.
 */

/* The words, the tones and the dots all come from lib/status.ts now — see
 * BUSINESS_STATUS there for why they stopped being three private tables. The
 * local names are kept so the call sites below read as they did. */
const STATUS_META = BUSINESS_STATUS

const REASON_CODES = [
  'Falsified / misrepresented documents',
  'Verified complaints from the public',
  'Non-payment of assessed fees',
  'Expired lease contract',
  'Compliance restored',
  'Other (see details)',
]

/* ── Transfer of ownership (MCG-BPLO-FO-003 section II) ──────────────── */

/**
 * Move a business to another owner account.
 *
 * ── Why this screen and not the amendment's approval ──────────────────
 *
 * An approved CHANGE OF OWNERSHIP states a NAME. An account is a different
 * thing: it may not exist, and matching a person to one by name is how a
 * business ends up with the wrong Maria Reyes. So the applicant states the
 * name, BPLO reads the Deed of Transfer, and the judgement about which
 * account that is gets made here by a person — client's decision,
 * 21 September 2026.
 *
 * Until this existed the decision had nowhere to land: `owner_user_id` was
 * written in exactly one place, from the session, when a business was first
 * registered.
 */
function TransferOwnerModal({
  row,
  onClose,
  onTransferred,
}: {
  row: AdminBusiness
  onClose: () => void
  onTransferred: (owner: { id: number; name: string }) => void
}) {
  const [email, setEmail] = useState('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function confirm() {
    setBusy(true)
    setError(null)
    try {
      const moved = await admin.transferBusinessOwner(row.id, email.trim(), reason.trim())
      onTransferred({ id: moved.owner_user_id, name: moved.owner_name })
    } catch (err) {
      setError(toApiError(err).message)
      setBusy(false)
    }
  }

  return (
    <ProtoModal
      title="Transfer Ownership"
      cancelLabel="Cancel"
      confirmLabel="Transfer"
      onCancel={onClose}
      onConfirm={confirm}
      confirmDisabled={busy || email.trim() === '' || reason.trim() === ''}
    >
      <p className="mb-5 border-b border-line pb-3 text-sm text-ink-secondary">
        {row.name}
        {row.owner && (
          <>
            {' · currently '}
            <span className="font-semibold text-ink">{row.owner.name}</span>
          </>
        )}
      </p>
      <div className="space-y-4">
        <label className="block">
          <FieldLabel required>New owner’s BizTrack email</FieldLabel>
          <input
            type="email"
            className={inputCls}
            placeholder="the address they registered with"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
          {/*
            Said before the attempt, not only after it fails. The commonest
            dead end here is a new owner who has never registered, and an
            officer who knows that up front can tell them on the phone
            instead of discovering it at the counter.
          */}
          <span className="mt-1.5 block text-xs text-ink-secondary">
            They must already have a BizTrack account. Filings, permits and deferred fees all move
            with the business, and the previous owner loses access to it.
          </span>
        </label>
        <label className="block">
          <FieldLabel required>Reason</FieldLabel>
          <textarea
            className={`${inputCls} min-h-20`}
            placeholder="e.g. Deed of Sale attached to amendment MCB-2026-000012"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </label>
        {error !== null && (
          <p role="alert" className="text-sm font-medium text-s-red">
            {error}
          </p>
        )}
      </div>
    </ProtoModal>
  )
}

/* ── Changing Status (p100) ───────────────────────────────────────────── */

function ChangeStatusModal({
  row,
  onClose,
  onChanged,
}: {
  row: AdminBusiness
  onClose: () => void
  onChanged: (updated: AdminBusiness) => void
}) {
  const [status, setStatus] = useState<BusinessStatus>(row.status)
  const [reasonCode, setReasonCode] = useState('')
  const [details, setDetails] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function confirm() {
    setBusy(true)
    setError(null)
    try {
      // Reason sent to the API is the code plus any free-text detail.
      const reason = [reasonCode, details.trim()].filter(Boolean).join(' · ')
      const updated = await admin.setBusinessStatus(row.id, status, reason)
      onChanged(updated)
    } catch (err) {
      setError(toApiError(err).message)
      setBusy(false)
    }
  }

  return (
    <ProtoModal
      title="Changing Status"
      cancelLabel="Cancel"
      confirmLabel="Confirm"
      onCancel={onClose}
      onConfirm={confirm}
      confirmDisabled={busy || !reasonCode}
    >
      <p className="mb-5 border-b border-line pb-3 text-sm text-ink-secondary">{row.name}</p>
      <div className="space-y-4">
        <label className="block">
          <FieldLabel required>New status</FieldLabel>
          <select className={inputCls} value={status} onChange={(e) => setStatus(e.target.value as BusinessStatus)}>
            {(Object.keys(STATUS_META) as BusinessStatus[]).map((s) => (
              <option key={s} value={s}>
                {STATUS_META[s].label}
              </option>
            ))}
          </select>
        </label>
        <label className="block">
          <FieldLabel required>Reason code</FieldLabel>
          <select className={inputCls} value={reasonCode} onChange={(e) => setReasonCode(e.target.value)}>
            <option value="">Select reason…</option>
            {REASON_CODES.map((r) => (
              <option key={r}>{r}</option>
            ))}
          </select>
        </label>
        <label className="block">
          <FieldLabel>Details</FieldLabel>
          <textarea
            className={`${inputCls} min-h-20`}
            placeholder="Describe the basis for this status change"
            value={details}
            onChange={(e) => setDetails(e.target.value)}
          />
        </label>
        {error && <p className="rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">{error}</p>}
      </div>
    </ProtoModal>
  )
}

/* ── Status History (p101) — audit-fed ────────────────────────────────── */

interface HistoryEntry {
  key: string
  status: string
  tone: string
  date: string | null
  note: string
}

const STATUS_DOT: Record<BusinessStatus, string> = Object.fromEntries(
  (Object.keys(BUSINESS_STATUS) as BusinessStatus[]).map((s) => [s, BUSINESS_STATUS[s].dot]),
) as Record<BusinessStatus, string>

function HistoryModal({ row, onClose }: { row: AdminBusiness; onClose: () => void }) {
  /*
   * This business's status changes, asked for by subject.
   *
   * It used to pull the eight newest pages of the WHOLE audit trail — 200 rows
   * out of tens of thousands — and keep the handful that happened to be about
   * this business. The newest rows are overwhelmingly sign-ins, so a business
   * blacklisted a month ago showed a timeline containing only "Registered",
   * under a caption explaining that the screen could not see very far. That is
   * a fair description of a window and no way to run a register. The audit
   * endpoint now filters on the subject, so this is the actual history.
   */
  const { data, loading } = useAsync(
    () =>
      admin.auditLogs({
        auditable_type: 'Business',
        auditable_id: row.id,
        action: 'status',
        per_page: 100,
      }),
    [row.id],
  )
  const panelRef = useRef<HTMLDivElement | null>(null)
  const closeRef = useRef<HTMLButtonElement | null>(null)
  // This overlay reimplemented ProtoModal's markup without its keyboard
  // handling: no focus move on open, no Escape, and Tab walked out of it.
  useDialogKeyboard(panelRef, onClose, closeRef)

  const entries = useMemo<HistoryEntry[]>(() => {
    const fromLogs: HistoryEntry[] = (data?.data ?? [])
      .map((log: AuditLog) => {
        /*
         * BusinessStatusController writes { from, to, reason }. This read
         * `changes.status`, which is never there, and fell back to 'active' — so
         * a blacklisting rendered in the timeline as "Active", in green, with the
         * reason dropped. Read the key the API actually writes, and when the
         * status really is missing say so rather than inventing "Active".
         */
        const changed = (log.changes as { from?: string; to?: string; reason?: string } | null) ?? {}
        const to = changed.to as BusinessStatus | undefined
        const meta = to ? STATUS_META[to] : undefined
        const from = changed.from ? (STATUS_META[changed.from as BusinessStatus]?.label ?? changed.from) : null
        return {
          key: `log-${log.id}`,
          status: meta ? (from ? `${from} → ${meta.label}` : meta.label) : 'Status changed',
          tone: to ? (STATUS_DOT[to] ?? 'bg-line') : 'bg-line',
          date: log.created_at,
          note: [log.user?.name ?? 'Actor not recorded', changed.reason].filter(Boolean).join(' · '),
        }
      })

    // Registration bookends the timeline.
    fromLogs.push({
      key: 'registered',
      status: 'Registered',
      tone: 'bg-s-green',
      date: row.created_at,
      note: `${row.owner?.name ?? 'Owner'} · Business registered.`,
    })
    return fromLogs
  }, [data, row])

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label="Status History"
        className="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-md bg-white shadow-overlay"
      >
        <div className="bg-royal px-5 py-3 text-base font-bold tracking-wide text-white">Status History</div>
        <p className="border-b border-line px-5 py-3 text-sm text-ink-secondary">
          {row.name}
          {!loading &&
            ` · ${(data?.total ?? 0).toLocaleString()} status ${
              (data?.total ?? 0) === 1 ? 'change' : 'changes'
            } on record`}
        </p>
        <div className="flex-1 overflow-y-auto px-5 py-4">
          {loading ? (
            <p className="py-6 text-center text-sm text-ink-muted">Loading history…</p>
          ) : (
            <ul>
              {entries.map((entry, i) => (
                <li key={entry.key} className="relative flex gap-3 pb-5">
                  {i < entries.length - 1 && (
                    <span className="absolute left-[5px] top-4 h-full w-px bg-line" aria-hidden="true" />
                  )}
                  <span className={`mt-1.5 h-[11px] w-[11px] shrink-0 rounded-full ${entry.tone}`} aria-hidden="true" />
                  <div className="min-w-0">
                    <p className="text-sm font-bold text-ink">{entry.status}</p>
                    {entry.date && <p className="text-xs text-ink-muted">{formatDateTime(entry.date)}</p>}
                    <p className="mt-0.5 text-xs text-ink-secondary">{entry.note}</p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
        <button
          ref={closeRef}
          type="button"
          onClick={onClose}
          className="bg-modal-cancel py-3.5 text-sm font-semibold text-ink underline underline-offset-2 hover:brightness-95"
        >
          Close
        </button>
      </div>
    </div>
  )
}

/* ── Page ─────────────────────────────────────────────────────────────── */

type ModalState = { kind: 'change' | 'transfer' | 'history' | 'fees'; row: AdminBusiness } | null

/**
 * What a business has been issued and not yet paid for, itemised.
 *
 * ── Why the total is a control and not just a figure ──────────────────────
 *
 * The column shows one number; the officer chasing it needs to know what it is
 * FOR. "₱2,100" is not something you can raise with an owner on the phone;
 * "the Sanitary Permit from June and the FSIC from August" is. So the total
 * opens this.
 *
 * `on_a_bill` earns its own line. A fee already sitting on a renewal the
 * applicant has been shown is still unpaid — which is why it is in the total —
 * but a chase is already in flight and an officer ringing about it should know
 * that before they do.
 */
function FeesModal({ row, onClose }: { row: AdminBusiness; onClose: () => void }) {
  const fees = row.unbilled_fees

  /*
   * `onCancel` with a Close label and no `onConfirm`: this dialog is a record,
   * not a decision, so there is nothing to proceed to. `ProtoModal` takes
   * `onCancel` and not `onClose` — passing the latter is what `HistoryModal`
   * beside this one hand-rolls its whole overlay to avoid, losing the focus
   * trap and the Escape handling in doing so.
   */
  return (
    <ProtoModal title="UNBILLED PERMIT FEES" cancelLabel="Close" onCancel={onClose}>
      <p className="mb-4 border-b border-line pb-3 text-sm text-ink-secondary">{row.name}</p>

      <p className="text-xs leading-relaxed text-ink-secondary">
        A permit renewed outside January is issued straight away and billed on the next business
        permit renewal. These are waiting for that renewal — nothing is overdue and no permit
        lapses, but the fees are not collected yet.
      </p>

      <ul className="mt-4 divide-y divide-line">
        {(fees?.items ?? []).map((item, i) => (
          <li key={`${item.permit_code ?? 'fee'}-${i}`} className="flex items-baseline gap-3 py-2.5">
            <span className="min-w-0 flex-1">
              <span className="block text-sm font-semibold text-ink">
                {item.permit_type ?? 'Permit fee'}
              </span>
              <span className="block text-xs text-ink-muted">
                Issued {formatDate(item.incurred_at)}
                {item.on_a_bill && ' · already on a renewal awaiting payment'}
              </span>
            </span>
            <span className="tnum shrink-0 text-sm font-semibold text-ink">
              {formatMoney(item.amount)}
            </span>
          </li>
        ))}
      </ul>

      <div className="mt-3 flex items-baseline justify-between border-t border-ink/40 pt-3 text-base font-bold text-ink">
        <span>Total uncollected</span>
        <span className="tnum">{formatMoney(fees?.total ?? 0)}</span>
      </div>
    </ProtoModal>
  )
}

/** Rows per request. The roster is 705 businesses and grows with the city. */
const PAGE_SIZE = 25

/** The roster filter: every status, or exactly one. */
type StatusFilter = 'all' | BusinessStatus

const STATUS_FILTERS: { value: StatusFilter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'flagged', label: 'Flagged' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'blacklisted', label: 'Blacklisted' },
]

export function OwnersPage() {
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState<StatusFilter>('all')
  const [page, setPage] = useState(1)
  const [modal, setModal] = useState<ModalState>(null)

  /*
   * Searched and paged on the server. Both used to happen in the browser over
   * the whole roster, which meant every visit pulled all 705 rows and rendered
   * all 705 — and now that /admin/businesses is paged, a browser-side search
   * would only ever have looked at the 50 rows it happened to hold while the
   * footer called that the whole roster.
   */
  const { data, loading, error, reload, setData } = useAsync(
    () =>
      admin.businessesPage({
        q: query || undefined,
        // The endpoint has always accepted this and nothing ever sent it, so
        // "show me the suspended ones" meant paging the whole register by eye.
        status: status === 'all' ? undefined : status,
        page,
        per_page: PAGE_SIZE,
      }),
    [query, status, page],
  )

  // Let the admin finish typing before asking the server.
  useEffect(() => {
    const id = window.setTimeout(() => {
      setQuery(search.trim())
      setPage(1)
    }, 300)
    return () => window.clearTimeout(id)
  }, [search])

  const rows = data?.data ?? []
  const total = data?.meta.total ?? 0
  const lastPage = data?.meta.last_page ?? 1

  /**
   * Fold a status change back into the row it came from.
   *
   * Merged, not replaced. POST /admin/businesses/{id}/status answers with only
   * `{ id, status, status_label }` — no name, no owner, no created_at — so
   * swapping the whole row in blanked the Business column and turned Owner into
   * "—" the moment an admin changed a status. Typing in the search box then took
   * the page down on `r.name.toLowerCase()`. Keeping the fields the response
   * does not carry is correct whatever the endpoint returns.
   */
  function applyChange(updated: AdminBusiness) {
    setData((prev) =>
      prev
        ? { ...prev, data: prev.data.map((r) => (r.id === updated.id ? { ...r, ...updated } : r)) }
        : prev!,
    )
    setModal(null)
  }

  return (
    <div>
      <PageTitle
        right={
          <span className="flex flex-wrap items-center gap-x-4 gap-y-2 pb-1">
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search business or owner…"
              aria-label="Search businesses or owners"
              className="w-56 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
          </span>
        }
      >
        Business Owner Status
      </PageTitle>

      {/*
        A real status filter, replacing the decorative Sort/Filter pair. The
        whole point of this screen is finding the businesses under sanction, and
        they were indistinguishable from the 700 that are not without paging the
        register and reading chips.
      */}
      <div className="mb-5">
        <FilterPills
          options={STATUS_FILTERS}
          value={status}
          onChange={(next) => {
            setStatus(next)
            setPage(1)
          }}
        />
      </div>

      {loading ? (
        <SkeletonList rows={7} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={BuildingIcon}
          title={search ? 'No businesses match your search' : 'No registered businesses yet'}
          description={
            search
              ? 'Try another business or owner name.'
              : 'Businesses appear here as owners register and apply for permits.'
          }
        />
      ) : (
        <ProtoCard className="overflow-hidden rounded-xl">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[44rem] text-left text-sm">
              <thead>
                <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  <th className="px-5 py-3">Business</th>
                  <th className="px-5 py-3">Owner</th>
                  <th className="px-5 py-3">Status</th>
                  {/*
                    Deferred permit fees. Client's decision, 17 September 2026:
                    a clearance renewed outside January is issued unbilled and
                    its fee waits for the next business permit renewal — *"they
                    wait indefinitely, and are visible"*. This is the visible
                    half; the other half is already on the renewal's Tax Order
                    of Payment.

                    Right-aligned, like every money column: the digits line up
                    and a reader scanning for the largest debt does it by eye
                    rather than by reading each one.
                  */}
                  <th className="px-5 py-3 text-right">Unbilled fees</th>
                  <th className="px-5 py-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const meta = STATUS_META[row.status] ?? { label: row.status_label, tone: 'tint-gray' as ChipTone }
                  return (
                    <tr key={row.id} className="border-t border-line">
                      <td className="px-5 py-3.5">
                        <span className="block font-bold text-ink">{row.name}</span>
                        {/*
                          * The number under the name, because this is the
                          * screen where an admin suspends somebody's
                          * livelihood and "which of these is the one the
                          * complaint is about" must not be answered by a name
                          * alone — six rows, two owners, names that share a
                          * word.
                          *
                          * The BAN joins it only when the business has one: it
                          * is the reference a restriction quotes back, so an
                          * owner ringing to ask why they were suspended is
                          * reading out a number this row can be found by.
                          *
                          * Said in words when there is nothing, rather than a
                          * dash: "no number on file" is a fact about the
                          * register, and a dash reads as a value that failed
                          * to load.
                          */}
                        {/*
                          * Name, then number. Nothing else.
                          *
                          * It carried a label and a count — "Latest filing
                          * BIZ-2026-00003 · 2 in total" — because `BIZ-…` is a
                          * FILING's number and a business that renews holds
                          * several, so a bare number can read as the business's
                          * own. The client has seen both and asked for the bare
                          * pair, knowing that: a renewal takes a new number and
                          * this is the newest one.
                          *
                          * Which filing it is remains a real question, and it
                          * is still answered — by the ORDER (latest by
                          * `submitted_at`, not by insertion) rather than by
                          * words on the row.
                          *
                          * "No filing yet" stays in words: a business exists in
                          * the register from the moment it is created, and a
                          * blank line under its name would read as a value that
                          * failed to load.
                          */}
                        <span className="tnum mt-0.5 block text-xs text-ink-muted">
                          {row.tracking_id ?? <span className="italic">No filing yet</span>}
                        </span>
                      </td>
                      <td className="px-5 py-3.5 text-ink-secondary">{row.owner?.name ?? '—'}</td>
                      <td className="px-5 py-3.5">
                        <StatusChip tone={meta.tone}>{meta.label}</StatusChip>
                      </td>
                      <td className="px-5 py-3.5 text-right">
                        {/*
                          Three states, and they are genuinely different:
                          the key absent (an older payload — say nothing rather
                          than claim zero), a zero (nothing deferred, and the
                          dash reads faster than ₱0.00 down a column of them),
                          and a real debt.
                        */}
                        {row.unbilled_fees === undefined ? (
                          <span className="text-ink-muted">—</span>
                        ) : row.unbilled_fees.total === 0 ? (
                          <span className="text-ink-muted">—</span>
                        ) : (
                          <button
                            type="button"
                            onClick={() => setModal({ kind: 'fees', row })}
                            className="tnum font-bold text-royal underline underline-offset-2 hover:text-royal-hover"
                            aria-label={`What ${row.name} owes in unbilled permit fees`}
                          >
                            {formatMoney(row.unbilled_fees.total)}
                          </button>
                        )}
                      </td>
                      <td className="px-5 py-3.5">
                        <div className="flex items-center gap-2">
                          <button
                            type="button"
                            onClick={() => setModal({ kind: 'change', row })}
                            // Transparent border, not no border: its outlined
                            // neighbour carries a 1px one, so without this the
                            // filled button stands 2px shorter than its row.
                            className="rounded-full border border-transparent bg-s-red px-4 py-1.5 text-xs font-semibold text-white hover:brightness-110"
                          >
                            Change Status
                          </button>
                          <button
                            type="button"
                            onClick={() => setModal({ kind: 'transfer', row })}
                            className="rounded-full border border-line bg-white px-4 py-1.5 text-xs font-semibold text-ink-secondary hover:bg-canvas"
                          >
                            Transfer Ownership
                          </button>
                          <button
                            type="button"
                            onClick={() => setModal({ kind: 'history', row })}
                            className="rounded-full border border-line bg-white px-4 py-1.5 text-xs font-semibold text-ink-secondary hover:bg-canvas"
                          >
                            View Status History
                          </button>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          <div className="flex items-center justify-between gap-4 border-t border-line px-5 py-3.5">
            <p className="text-sm text-ink-muted">
              Showing {rows.length.toLocaleString()} of {total.toLocaleString()} businesses
              {query && ' matching your search'}
            </p>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                aria-label="Previous page"
                /*
                 * aria-disabled, never the native attribute (§6.2): a disabled
                 * control leaves the tab order, so a keyboard reader loses the
                 * pager entirely at either end of the list rather than being
                 * told it has reached one. The handler needs no guard — it
                 * already clamps to page 1.
                 */
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                aria-disabled={page <= 1 || loading || undefined}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
              >
                ‹
              </button>
              <span className="text-xs text-ink-muted">
                Page {page.toLocaleString()} of {lastPage.toLocaleString()}
              </span>
              <button
                type="button"
                aria-label="Next page"
                // Clamped to lastPage, so pressing it on the last page does
                // nothing — see the note on Previous.
                onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                aria-disabled={page >= lastPage || loading || undefined}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
              >
                ›
              </button>
            </div>
          </div>
        </ProtoCard>
      )}

      {modal?.kind === 'change' && (
        <ChangeStatusModal
          row={modal.row}
          onClose={() => setModal(null)}
          onChanged={applyChange}
        />
      )}
      {modal?.kind === 'transfer' && (
        <TransferOwnerModal
          row={modal.row}
          onClose={() => setModal(null)}
          onTransferred={(owner) => {
            /*
             * The roster row is patched in place rather than refetched. The
             * owner is the only thing that moved, and a refetch would reset
             * the page and the filter the admin is working through.
             */
            applyChange({ ...modal.row, owner })
            setModal(null)
          }}
        />
      )}
      {modal?.kind === 'history' && <HistoryModal row={modal.row} onClose={() => setModal(null)} />}
      {modal?.kind === 'fees' && <FeesModal row={modal.row} onClose={() => setModal(null)} />}
    </div>
  )
}
