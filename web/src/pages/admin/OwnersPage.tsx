import { useMemo, useState } from 'react'
import { admin } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { toApiError } from '../../lib/api'
import { formatDateTime } from '../../lib/format'
import type { AdminBusiness, AuditLog, BusinessStatus } from '../../lib/types'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import {
  FieldLabel,
  PageTitle,
  ProtoCard,
  ProtoModal,
  SortFilter,
  StatusChip,
  inputCls,
} from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { BuildingIcon } from '../../components/icons'

/*
 * Business Owner Status (PDF p99–101): the real /admin/businesses roster with
 * live status chips, the Changing Status modal wired to
 * POST /admin/businesses/{id}/status (all four statuses real), and the
 * audit-fed Status History dot-timeline modal.
 */

const STATUS_META: Record<BusinessStatus, { label: string; tone: ChipTone }> = {
  active: { label: 'Active', tone: 'tint-green' },
  flagged: { label: 'Flagged', tone: 'tint-yellow' },
  suspended: { label: 'Suspended', tone: 'tint-purple' },
  blacklisted: { label: 'Blacklisted', tone: 'tint-red' },
}

const REASON_CODES = [
  'Falsified / misrepresented documents',
  'Verified complaints from the public',
  'Non-payment of assessed fees',
  'Expired lease contract',
  'Compliance restored',
  'Other (see details)',
]

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

function HistoryModal({ row, onClose }: { row: AdminBusiness; onClose: () => void }) {
  const { data, loading } = useAsync(() => admin.auditLogs(1), [])

  const entries = useMemo<HistoryEntry[]>(() => {
    const fromLogs: HistoryEntry[] = (data?.data ?? [])
      .filter(
        (log: AuditLog) =>
          log.auditable_type.endsWith('Business') &&
          log.auditable_id === row.id &&
          /status/.test(log.action),
      )
      .map((log) => {
        const changed = (log.changes as { status?: string; reason?: string } | null) ?? {}
        const st = (changed.status as BusinessStatus) ?? 'active'
        const meta = STATUS_META[st] ?? STATUS_META.active
        return {
          key: `log-${log.id}`,
          status: meta.label,
          tone:
            st === 'blacklisted'
              ? 'bg-s-red'
              : st === 'flagged'
                ? 'bg-s-yellow'
                : st === 'suspended'
                  ? 'bg-s-purple'
                  : 'bg-s-green',
          date: log.created_at,
          note: `${log.user?.name ?? 'System'}${changed.reason ? ` · ${changed.reason}` : ''}`,
        }
      })

    // Registration bookends the timeline.
    fromLogs.push({
      key: 'registered',
      status: 'Active',
      tone: 'bg-s-green',
      date: row.created_at,
      note: `${row.owner?.name ?? 'Owner'} · Business registered.`,
    })
    return fromLogs
  }, [data, row])

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="dialog"
      aria-modal="true"
      aria-label="Status History"
    >
      <div className="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-md bg-white shadow-overlay">
        <div className="bg-royal px-5 py-3 text-base font-bold tracking-wide text-white">Status History</div>
        <p className="border-b border-line px-5 py-3 text-sm text-ink-secondary">
          {row.name} · immutable timeline
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

type ModalState = { kind: 'change' | 'history'; row: AdminBusiness } | null

export function OwnersPage() {
  const { data, loading, error, reload, setData } = useAsync(() => admin.businesses(), [])
  const [search, setSearch] = useState('')
  const [modal, setModal] = useState<ModalState>(null)

  const rows = useMemo(() => {
    const all = data ?? []
    const q = search.trim().toLowerCase()
    if (!q) return all
    return all.filter(
      (r) => r.name.toLowerCase().includes(q) || (r.owner?.name ?? '').toLowerCase().includes(q),
    )
  }, [data, search])

  function applyChange(updated: AdminBusiness) {
    setData((prev) => (prev ?? []).map((r) => (r.id === updated.id ? updated : r)))
    setModal(null)
  }

  return (
    <div>
      <PageTitle
        right={
          <span className="flex items-center gap-4 pb-1">
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search…"
              aria-label="Search businesses or owners"
              className="w-56 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
            <SortFilter />
          </span>
        }
      >
        Business Owner Status
      </PageTitle>

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
                  <th className="px-5 py-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const meta = STATUS_META[row.status] ?? { label: row.status_label, tone: 'tint-gray' as ChipTone }
                  return (
                    <tr key={row.id} className="border-t border-line">
                      <td className="px-5 py-3.5 font-bold text-ink">{row.name}</td>
                      <td className="px-5 py-3.5 text-ink-secondary">{row.owner?.name ?? '—'}</td>
                      <td className="px-5 py-3.5">
                        <StatusChip tone={meta.tone}>{meta.label}</StatusChip>
                      </td>
                      <td className="px-5 py-3.5">
                        <div className="flex items-center gap-2">
                          <button
                            type="button"
                            onClick={() => setModal({ kind: 'change', row })}
                            className="rounded-full bg-s-red px-4 py-1.5 text-xs font-semibold text-white hover:brightness-110"
                          >
                            Change Status
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
          <div className="border-t border-line px-5 py-3.5">
            <p className="text-sm text-ink-muted">
              Showing {rows.length} of {(data ?? []).length} businesses
            </p>
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
      {modal?.kind === 'history' && <HistoryModal row={modal.row} onClose={() => setModal(null)} />}
    </div>
  )
}
