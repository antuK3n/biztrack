import { useEffect, useState } from 'react'
import { AlertTriangleIcon, BuildingIcon } from '../../components/icons'
import { Modal } from '../../components/ui/Modal'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { FilterPills, PageTitle, ProtoCard, StatusChip } from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { formatDate, formatDateTime } from '../../lib/format'
import { admin } from '../../lib/resources'
import { BUSINESS_STATUS } from '../../lib/status'
import type { AdminOwner, AuditLog, BusinessStatus, OwnerHistoryEntry } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'
import { RequiredReason } from './PermitStatusDialogs'

/*
 * ── Business Owner Status, one row per OWNER ───────────────────────────────
 *
 * [Client, 5 October 2026: "ang nandoon lang tlga specific ay name ng owner,
 * then do drop down na lang din mga businesses nya kung marami, then status,
 * actions (active o blacklisted na lang) then view status history pa rin".]
 *
 *   Owner | Businesses (a dropdown when there are several) | Status | Actions
 *
 * The owner's status is Active or Blacklisted. Blacklisting suspends every
 * business they hold and the certificates with them; while it stands, none of
 * those businesses can be changed — trying opens a modal saying the owner is
 * blacklisted. Reinstating returns the businesses the blacklisting suspended.
 * The owner is notified each time, and their sign-in raises the blacklist
 * modal (AccountRestrictedModal) for as long as it stands.
 */

type OwnerFilter = 'all' | 'active' | 'blacklisted'

const FILTERS: { value: OwnerFilter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'blacklisted', label: 'Blacklisted' },
]

const OWNER_TONE: Record<AdminOwner['status'], ChipTone> = {
  active: 'tint-green',
  blacklisted: 'tint-red',
}

const PAGE_SIZE = 25

type Business = AdminOwner['businesses'][number]

type ModalState =
  | { kind: 'owner-status'; owner: AdminOwner }
  | { kind: 'owner-history'; owner: AdminOwner }
  | { kind: 'business-status'; owner: AdminOwner; business: Business }
  | { kind: 'business-history'; business: Business }
  | null

function businessTone(status: BusinessStatus): ChipTone {
  return BUSINESS_STATUS[status]?.tone ?? 'tint-gray'
}

export function OwnersPage() {
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [filter, setFilter] = useState<OwnerFilter>('all')
  const [page, setPage] = useState(1)
  const [modal, setModal] = useState<ModalState>(null)
  const [open, setOpen] = useState<number | null>(null)

  const { data, loading, error, reload } = useAsync(
    () =>
      admin.owners({
        q: query || undefined,
        status: filter === 'all' ? undefined : filter,
        page,
        per_page: PAGE_SIZE,
      }),
    [query, filter, page],
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

  return (
    <div>
      <PageTitle
        right={
          <span className="flex flex-wrap items-center gap-x-4 gap-y-2 pb-1">
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search owner, email or business…"
              aria-label="Search owners or businesses"
              className="w-60 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
          </span>
        }
      >
        Business Owner Status
      </PageTitle>

      <div className="mb-5">
        <FilterPills
          options={FILTERS}
          value={filter}
          onChange={(next) => {
            setFilter(next)
            setPage(1)
          }}
        />
      </div>

      {loading && !data ? (
        <SkeletonList rows={7} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={BuildingIcon}
          title={search ? 'No owners match your search' : 'No business owners here'}
          description={search ? 'Try another owner, email or business name.' : 'Owners appear here once they register a business.'}
        />
      ) : (
        <ProtoCard className="overflow-hidden rounded-xl">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[48rem] text-left text-sm">
              <thead>
                <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  <th scope="col" className="px-5 py-3">Owner</th>
                  <th scope="col" className="px-5 py-3">Businesses</th>
                  <th scope="col" className="px-5 py-3">Status</th>
                  <th scope="col" className="px-5 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((owner) => {
                  const many = owner.businesses.length > 1
                  const expanded = !many || open === owner.id
                  return (
                    <tr key={owner.id} className="border-t border-line align-top">
                      <td className="px-5 py-4">
                        <span className="block font-bold text-ink">{owner.name}</span>
                        <span className="block text-xs text-ink-muted">{owner.email}</span>
                        {owner.status === 'blacklisted' && (
                          <span className="mt-1 block max-w-xs text-xs leading-snug text-s-red">
                            Blacklisted {formatDate(owner.blacklisted_at)}
                            {owner.reason ? ` — ${owner.reason}` : ''}
                          </span>
                        )}
                      </td>
                      <td className="px-5 py-4">
                        {many && (
                          <button
                            type="button"
                            onClick={() => setOpen(open === owner.id ? null : owner.id)}
                            aria-expanded={open === owner.id}
                            className="inline-flex items-center gap-1.5 rounded-full border border-line bg-white px-3 py-1 text-xs font-semibold text-ink hover:border-royal hover:text-royal"
                          >
                            <span aria-hidden="true" className="text-[10px] text-ink-muted">
                              {open === owner.id ? '▾' : '▸'}
                            </span>
                            {owner.businesses.length} businesses
                          </button>
                        )}
                        {expanded && (
                          <ul className={`space-y-2.5 ${many ? 'mt-2.5' : ''}`}>
                            {owner.businesses.map((b) => (
                              <li key={b.id} className="min-w-[16rem]">
                                <div className="flex flex-wrap items-center gap-2">
                                  <span className="font-semibold text-ink">{b.name}</span>
                                  <StatusChip tone={businessTone(b.status)}>
                                    {BUSINESS_STATUS[b.status]?.label ?? b.status_label}
                                  </StatusChip>
                                </div>
                                <span className="tnum block text-xs text-ink-muted">
                                  {b.tracking_id ?? <span className="italic">No filing yet</span>}
                                </span>
                                <span className="mt-0.5 flex gap-3 text-xs">
                                  <button
                                    type="button"
                                    onClick={() => setModal({ kind: 'business-status', owner, business: b })}
                                    aria-label={`Change the status of ${b.name}`}
                                    className="font-semibold text-royal underline-offset-2 hover:underline"
                                  >
                                    Change status
                                  </button>
                                  <button
                                    type="button"
                                    onClick={() => setModal({ kind: 'business-history', business: b })}
                                    aria-label={`Status history of ${b.name}`}
                                    className="font-semibold text-ink-secondary underline-offset-2 hover:underline"
                                  >
                                    History
                                  </button>
                                </span>
                              </li>
                            ))}
                          </ul>
                        )}
                      </td>
                      <td className="px-5 py-4">
                        <StatusChip tone={OWNER_TONE[owner.status]}>{owner.status_label}</StatusChip>
                      </td>
                      <td className="px-5 py-4 text-right">
                        <div className="inline-flex gap-2">
                          <button
                            type="button"
                            onClick={() => setModal({ kind: 'owner-status', owner })}
                            aria-label={`Change the status of ${owner.name}`}
                            className="rounded-full border border-transparent bg-royal px-4 py-1.5 text-xs font-semibold text-white hover:bg-royal-hover"
                          >
                            Change status
                          </button>
                          <button
                            type="button"
                            onClick={() => setModal({ kind: 'owner-history', owner })}
                            aria-label={`Status history of ${owner.name}`}
                            className="rounded-full border border-line bg-white px-4 py-1.5 text-xs font-semibold text-ink-secondary hover:bg-canvas"
                          >
                            View status history
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
              Showing {rows.length.toLocaleString()} of {total.toLocaleString()} owners
              {query && ' matching your search'}
            </p>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                aria-label="Previous page"
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

      {modal?.kind === 'owner-status' && (
        <OwnerStatusModal owner={modal.owner} onClose={() => setModal(null)} onChanged={reload} />
      )}
      {modal?.kind === 'owner-history' && <OwnerHistoryModal owner={modal.owner} onClose={() => setModal(null)} />}
      {modal?.kind === 'business-status' && (
        <BusinessStatusModal
          owner={modal.owner}
          business={modal.business}
          onClose={() => setModal(null)}
          onChanged={reload}
        />
      )}
      {modal?.kind === 'business-history' && (
        <BusinessHistoryModal business={modal.business} onClose={() => setModal(null)} />
      )}
    </div>
  )
}

/* ── Buttons shared by the dialogs ────────────────────────────────────── */

function DialogButtons({
  onCancel,
  onSave,
  saving,
  canSave,
  destructive,
}: {
  onCancel: () => void
  onSave: () => void
  saving: boolean
  canSave: boolean
  destructive: boolean
}) {
  return (
    <>
      <button
        type="button"
        onClick={onCancel}
        className="rounded-full border border-line px-5 py-2 text-sm font-semibold text-ink hover:bg-shell-deep"
      >
        Cancel
      </button>
      {/* aria-disabled, never disabled (AGENTS.md §6.2). */}
      <button
        type="button"
        aria-disabled={!canSave || undefined}
        onClick={onSave}
        className={`rounded-full px-5 py-2 text-sm font-semibold text-white aria-disabled:cursor-not-allowed aria-disabled:opacity-50 ${
          destructive ? 'bg-s-red hover:brightness-110' : 'bg-royal hover:bg-royal-hover'
        }`}
      >
        {saving ? 'Saving…' : 'Save status'}
      </button>
    </>
  )
}

// The same required Reason field the permit dialogs use.
function ReasonField({ value, onChange, choiceMade = true }: { value: string; onChange: (v: string) => void; choiceMade?: boolean }) {
  return <RequiredReason value={value} onChange={onChange} choiceMade={choiceMade} />
}

/* ── Owner: Change status (Active or Blacklisted) ─────────────────────── */

function OwnerStatusModal({
  owner,
  onClose,
  onChanged,
}: {
  owner: AdminOwner
  onClose: () => void
  onChanged: () => void
}) {
  const to = owner.status === 'blacklisted' ? 'active' : 'blacklisted'
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const count = owner.businesses.length

  async function save() {
    if (saving || reason.trim() === '') return
    setSaving(true)
    setError(null)
    try {
      await admin.setOwnerStatus(owner.id, to, reason.trim())
      onChanged()
      onClose()
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={`Change status — ${owner.name}`}
      description={
        <>
          Currently <span className="font-semibold text-ink">{owner.status_label}</span>.
        </>
      }
      footer={
        <DialogButtons
          onCancel={onClose}
          onSave={save}
          saving={saving}
          canSave={reason.trim() !== '' && !saving}
          destructive={to === 'blacklisted'}
        />
      }
    >
      <p className="text-xs font-bold uppercase tracking-wide text-ink-muted">New status</p>
      <div className="mt-2 rounded-lg border border-royal bg-royal-tint px-3 py-2.5">
        <StatusChip tone={OWNER_TONE[to]}>{to === 'blacklisted' ? 'Blacklisted' : 'Active'}</StatusChip>
        <p className="mt-1.5 text-xs leading-relaxed text-ink-secondary">
          {to === 'blacklisted'
            ? `${count === 1 ? 'Their business is' : `All ${count} of their businesses are`} suspended at once, and their permits stop being valid. The businesses cannot be changed until you reinstate the owner. The owner is notified and sees a notice every time they sign in.`
            : 'The owner is reinstated. The businesses the blacklisting suspended become active again and their permits are restored; a business suspended for its own reasons stays suspended.'}
        </p>
      </div>
      <ReasonField value={reason} onChange={setReason} />
      {error && (
        <p role="alert" className="mt-2 text-xs font-medium text-s-red">
          {error}
        </p>
      )}
    </Modal>
  )
}

/* ── Owner: View status history ───────────────────────────────────────── */

function OwnerHistoryModal({ owner, onClose }: { owner: AdminOwner; onClose: () => void }) {
  const { data, loading, error } = useAsync(() => admin.ownerHistory(owner.id), [owner.id])

  return (
    <Modal
      open
      onClose={onClose}
      title={`Status history — ${owner.name}`}
      description="Newest first."
      footer={<DoneButton onClick={onClose} />}
    >
      {loading ? (
        <p className="text-sm text-ink-muted">Loading…</p>
      ) : error ? (
        <p role="alert" className="text-sm font-medium text-s-red">
          {toApiError(error).message}
        </p>
      ) : (
        <Timeline
          entries={(data ?? []).map((e: OwnerHistoryEntry) => ({
            key: `${e.label}-${e.at}`,
            chip: <StatusChip tone={OWNER_TONE[e.to]}>{e.to === 'blacklisted' ? 'Blacklisted' : 'Active'}</StatusChip>,
            label: e.label,
            meta: `${formatDateTime(e.at) || '—'}${e.by ? ` · ${e.by}` : ''}`,
            reason: e.reason,
          }))}
        />
      )}
    </Modal>
  )
}

/* ── A business: Change status, locked while the owner is blacklisted ─── */

const BUSINESS_CHOICES: { value: Exclude<BusinessStatus, 'blacklisted'>; says: string }[] = [
  { value: 'active', says: 'Trading normally. It can file and renew, and its permits are valid.' },
  { value: 'flagged', says: 'A note to watch it. It still files and renews normally.' },
  {
    value: 'suspended',
    says: 'Barred from filing and renewing, and its permits are suspended until it is set back to Active.',
  },
]

function BusinessStatusModal({
  owner,
  business,
  onClose,
  onChanged,
}: {
  owner: AdminOwner
  business: Business
  onClose: () => void
  onChanged: () => void
}) {
  const [choice, setChoice] = useState<BusinessStatus | ''>('')
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // ── The owner is blacklisted: say so, instead of offering choices.
  if (owner.status === 'blacklisted') {
    return (
      <Modal
        open
        onClose={onClose}
        title="The owner is blacklisted"
        description={business.name}
        footer={<DoneButton onClick={onClose} label="OK" />}
      >
        <div className="flex gap-3">
          <span className="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-s-red-tint text-s-red">
            <AlertTriangleIcon size={22} />
          </span>
          <div className="min-w-0 space-y-2 text-sm leading-relaxed text-ink-secondary">
            <p>
              <b className="text-ink">{owner.name}</b> is blacklisted
              {owner.blacklisted_at ? ` since ${formatDate(owner.blacklisted_at)}` : ''}
              {owner.reason ? ` — ${owner.reason.replace(/\.$/, '')}` : ''}.
            </p>
            <p>
              {business.name} is suspended because of it, and its status cannot be changed here. To release it,
              reinstate the owner with <b className="text-ink">Change status</b> on the owner’s row — the businesses
              the blacklisting suspended become active again.
            </p>
          </div>
        </div>
      </Modal>
    )
  }

  async function save() {
    if (saving || choice === '' || reason.trim() === '') return
    setSaving(true)
    setError(null)
    try {
      await admin.setBusinessStatus(business.id, choice, reason.trim())
      onChanged()
      onClose()
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={`Change status — ${business.name}`}
      description={
        <>
          Owned by {owner.name}. Currently{' '}
          <span className="font-semibold text-ink">{BUSINESS_STATUS[business.status]?.label ?? business.status_label}</span>.
        </>
      }
      footer={
        <DialogButtons
          onCancel={onClose}
          onSave={save}
          saving={saving}
          canSave={choice !== '' && reason.trim() !== '' && !saving}
          destructive={choice === 'suspended'}
        />
      }
    >
      <fieldset>
        <legend className="text-xs font-bold uppercase tracking-wide text-ink-muted">New status</legend>
        <div className="mt-2 space-y-2">
          {BUSINESS_CHOICES.filter((c) => c.value !== business.status).map((c) => (
            <label
              key={c.value}
              className={`flex cursor-pointer gap-3 rounded-lg border px-3 py-2.5 ${
                choice === c.value ? 'border-royal bg-royal-tint' : 'border-line hover:border-ink-muted'
              }`}
            >
              <input
                type="radio"
                name="business-status"
                value={c.value}
                checked={choice === c.value}
                onChange={() => setChoice(c.value)}
                className="mt-1 accent-royal"
              />
              <span className="min-w-0">
                <StatusChip tone={businessTone(c.value)}>{BUSINESS_STATUS[c.value].label}</StatusChip>
                <span className="mt-1 block text-xs leading-relaxed text-ink-secondary">{c.says}</span>
              </span>
            </label>
          ))}
        </div>
      </fieldset>
      <p className="mt-2 text-xs text-ink-muted">
        To blacklist, use Change status on the owner’s row — a blacklisting is of the owner, not of one business.
      </p>
      <ReasonField value={reason} onChange={setReason} choiceMade={choice !== ''} />
      {error && (
        <p role="alert" className="mt-2 text-xs font-medium text-s-red">
          {error}
        </p>
      )}
    </Modal>
  )
}

/* ── A business: its status history ───────────────────────────────────── */

function BusinessHistoryModal({ business, onClose }: { business: Business; onClose: () => void }) {
  const { data, loading, error } = useAsync(
    () => admin.auditLogs({ auditable_type: 'Business', auditable_id: business.id, action: 'status', per_page: 100 }),
    [business.id],
  )

  const entries = (data?.data ?? []).map((log: AuditLog) => {
    const c = (log.changes as { to?: string; reason?: string } | null) ?? {}
    const to = (c.to ?? '') as BusinessStatus
    return {
      key: String(log.id),
      chip: BUSINESS_STATUS[to] ? <StatusChip tone={businessTone(to)}>{BUSINESS_STATUS[to].label}</StatusChip> : null,
      label: 'Status changed',
      meta: `${formatDateTime(log.created_at) || '—'}${log.user?.name ? ` · ${log.user.name}` : ''}`,
      reason: c.reason ?? null,
    }
  })
  entries.push({
    key: 'registered',
    chip: <StatusChip tone="tint-green">Active</StatusChip>,
    label: 'Registered',
    meta: formatDateTime(business.created_at) || '—',
    reason: null,
  })

  return (
    <Modal
      open
      onClose={onClose}
      title={`Status history — ${business.name}`}
      description="Newest first."
      footer={<DoneButton onClick={onClose} />}
    >
      {loading ? (
        <p className="text-sm text-ink-muted">Loading…</p>
      ) : error ? (
        <p role="alert" className="text-sm font-medium text-s-red">
          {toApiError(error).message}
        </p>
      ) : (
        <Timeline entries={entries} />
      )}
    </Modal>
  )
}

/* ── Shared pieces ───────────────────────────────────────────────────── */

function DoneButton({ onClick, label = 'Done' }: { onClick: () => void; label?: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="rounded-full bg-royal px-5 py-2 text-sm font-semibold text-white hover:bg-royal-hover"
    >
      {label}
    </button>
  )
}

function Timeline({
  entries,
}: {
  entries: { key: string; chip: React.ReactNode; label: string; meta: string; reason: string | null }[]
}) {
  return (
    <ol className="relative space-y-4 border-l-2 border-line pl-5">
      {entries.map((e, i) => (
        <li key={e.key} className="relative">
          <span
            aria-hidden="true"
            className={`absolute -left-[27px] top-1 h-3 w-3 rounded-full border-2 border-white ${
              i === 0 ? 'bg-royal' : 'bg-ink-muted'
            }`}
          />
          <div className="flex flex-wrap items-center gap-2">
            {e.chip}
            <span className="text-sm font-semibold text-ink">{e.label}</span>
          </div>
          <p className="mt-0.5 text-xs text-ink-muted">{e.meta}</p>
          {e.reason && <p className="mt-1 text-sm leading-relaxed text-ink-secondary">Reason: {e.reason}</p>}
        </li>
      ))}
    </ol>
  )
}
