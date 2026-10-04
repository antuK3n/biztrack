import { useEffect, useState } from 'react'
import { AlertTriangleIcon } from '../../components/icons'
import { Modal } from '../../components/ui/Modal'
import { StatusChip } from '../../components/ui/Proto'
import { STATUS_TONES } from './permitStatusTones'
import { toApiError } from '../../lib/api'
import { businessName, formatDateTime } from '../../lib/format'
import { permits } from '../../lib/resources'
import type { PermitHistoryEntry, PermitRegisterRow, PermitStatusOptions } from '../../lib/types'

/*
 * ── Change status and View status history ──────────────────────────────────
 *
 * [Client, 5 October 2026.] The Actions column at the end of the Permits table
 * opens these. Change status is offered only to the office that issued the
 * permit; its choices come from the server (`/status-options`), which knows
 * whether the permit is still movable and whether a rejected clearance is
 * holding a Mayor's Permit suspended:
 *
 *   BPLO, on a Mayor's Permit   Active · Suspended · Retired · Revoked
 *   A clearance office          Active · Rejected
 *
 * A Mayor's Permit held by a rejected clearance opens a modal saying so, and
 * which permits hold it, instead of the choices — BPLO cannot change it until
 * the office that rejected sets its permit back to Active.
 */


/** What each choice does, said where it is chosen. */
function consequenceOf(value: string, isMayors: boolean): string {
  switch (value) {
    case 'active':
      return isMayors
        ? 'In force. The business may operate on this permit.'
        : 'In force. Your office’s approval stands, and the Mayor’s Permit is restored if nothing else is rejected.'
    case 'suspended':
      return 'Not valid for now. Set it back to Active to lift the suspension.'
    case 'retired':
      return 'The business has stopped operating. Final — it cannot be changed again.'
    case 'revoked':
      return 'Taken away for a violation. Final — the owner has to apply again.'
    case 'rejected':
      return 'Your office withdraws its approval. The business’s Mayor’s Permit is suspended automatically until you set this back to Active.'
    default:
      return ''
  }
}

const DESTRUCTIVE = new Set(['suspended', 'retired', 'revoked', 'rejected'])

export function ChangeStatusDialog({
  permit,
  onClose,
  onChanged,
}: {
  permit: PermitRegisterRow
  onClose: () => void
  onChanged: () => void
}) {
  const [opts, setOpts] = useState<PermitStatusOptions | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [choice, setChoice] = useState<string>('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const isMayors = permit.permit_type?.code === 'BUSINESS'
  const name = permit.permit_type?.name ?? 'Permit'

  useEffect(() => {
    let cancelled = false
    permits
      .statusOptions(permit.id)
      .then((o) => !cancelled && setOpts(o))
      .catch((err) => !cancelled && setLoadError(toApiError(err).message))
    return () => {
      cancelled = true
    }
  }, [permit.id])

  async function save() {
    if (busy || choice === '' || reason.trim() === '') return
    setBusy(true)
    setError(null)
    try {
      await permits.changeStatus(permit.id, choice, reason.trim())
      onChanged()
      onClose()
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  // ── Held by a rejected clearance: say so, and why, instead of the choices.
  if (opts && opts.locked.length > 0) {
    return (
      <Modal
        open
        onClose={onClose}
        title="This permit can’t be changed right now"
        description={`${name} ${permit.permit_number}`}
        footer={
          <button
            type="button"
            onClick={onClose}
            className="rounded-full bg-royal px-5 py-2 text-sm font-semibold text-white hover:bg-royal/90"
          >
            OK
          </button>
        }
      >
        <div className="flex gap-3">
          <span className="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-s-purple-tint text-s-purple">
            <AlertTriangleIcon size={22} />
          </span>
          <div className="min-w-0 space-y-2 text-sm leading-relaxed text-ink-secondary">
            <p>
              It is <b className="text-ink">suspended automatically</b> because another permit of{' '}
              {businessName(permit.business)} is rejected. It stays suspended, and cannot be changed here, until the
              office that rejected it sets that permit back to Active:
            </p>
            <ul className="list-disc space-y-1 pl-5 text-ink">
              {opts.locked.map((l) => (
                <li key={l}>{l}</li>
              ))}
            </ul>
          </div>
        </div>
      </Modal>
    )
  }

  const canSave = choice !== '' && reason.trim() !== '' && !busy

  return (
    <Modal
      open
      onClose={onClose}
      title={`Change status — ${permit.permit_number}`}
      description={
        <>
          {name} for <span className="font-semibold text-ink">{businessName(permit.business)}</span>. Currently{' '}
          <span className="font-semibold text-ink">{opts?.current_label ?? permit.status_label}</span>.
        </>
      }
      footer={
        opts && !opts.final && opts.options.length > 0 ? (
          <>
            <button
              type="button"
              onClick={onClose}
              className="rounded-full border border-line px-5 py-2 text-sm font-semibold text-ink hover:bg-shell-deep"
            >
              Cancel
            </button>
            {/* aria-disabled, never disabled (AGENTS.md §6.2). */}
            <button
              type="button"
              aria-disabled={!canSave || undefined}
              onClick={save}
              className={`rounded-full px-5 py-2 text-sm font-semibold text-white aria-disabled:cursor-not-allowed aria-disabled:opacity-50 ${
                DESTRUCTIVE.has(choice) ? 'bg-s-red hover:brightness-110' : 'bg-royal hover:bg-royal/90'
              }`}
            >
              {busy ? 'Saving…' : 'Save status'}
            </button>
          </>
        ) : (
          <button
            type="button"
            onClick={onClose}
            className="rounded-full bg-royal px-5 py-2 text-sm font-semibold text-white hover:bg-royal/90"
          >
            OK
          </button>
        )
      }
    >
      {loadError ? (
        <p role="alert" className="text-sm font-medium text-s-red">
          {loadError}
        </p>
      ) : opts === null ? (
        <p className="text-sm text-ink-muted">Loading…</p>
      ) : opts.final ? (
        <p className="text-sm leading-relaxed text-ink-secondary">
          This permit is <b className="text-ink">{opts.current_label}</b>, and a {opts.current_label.toLowerCase()}{' '}
          permit can no longer be changed. See its status history for how it got here.
        </p>
      ) : (
        <>
          <fieldset>
            <legend className="text-xs font-bold uppercase tracking-wide text-ink-muted">New status</legend>
            <div className="mt-2 space-y-2">
              {opts.options.map((o) => (
                <label
                  key={o.value}
                  className={`flex cursor-pointer gap-3 rounded-lg border px-3 py-2.5 ${
                    choice === o.value ? 'border-royal bg-royal-tint' : 'border-line hover:border-ink-muted'
                  }`}
                >
                  <input
                    type="radio"
                    name="new-status"
                    value={o.value}
                    checked={choice === o.value}
                    onChange={() => setChoice(o.value)}
                    className="mt-1 accent-royal"
                  />
                  <span className="min-w-0">
                    <span className="flex items-center gap-2">
                      <StatusChip tone={STATUS_TONES[o.value] ?? 'tint-gray'}>{o.label}</StatusChip>
                    </span>
                    <span className="mt-1 block text-xs leading-relaxed text-ink-secondary">
                      {consequenceOf(o.value, isMayors)}
                    </span>
                  </span>
                </label>
              ))}
            </div>
          </fieldset>
          <label className="mt-4 block">
            <span className="text-xs font-bold uppercase tracking-wide text-ink-muted">Reason</span>
            <textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={3}
              aria-required="true"
              className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
            />
          </label>
          <p className="mt-1 text-xs text-ink-muted">
            The owner is notified with this reason, and it is recorded in the status history and the audit log.
          </p>
          {error && (
            <p role="alert" className="mt-2 text-xs font-medium text-s-red">
              {error}
            </p>
          )}
        </>
      )}
    </Modal>
  )
}

export function StatusHistoryDialog({ permit, onClose }: { permit: PermitRegisterRow; onClose: () => void }) {
  const [entries, setEntries] = useState<PermitHistoryEntry[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    permits
      .history(permit.id)
      .then((h) => !cancelled && setEntries(h))
      .catch((err) => !cancelled && setError(toApiError(err).message))
    return () => {
      cancelled = true
    }
  }, [permit.id])

  return (
    <Modal
      open
      onClose={onClose}
      title={`Status history — ${permit.permit_number}`}
      description={`${permit.permit_type?.name ?? 'Permit'} for ${businessName(permit.business)}. Newest first.`}
      footer={
        <button
          type="button"
          onClick={onClose}
          className="rounded-full bg-royal px-5 py-2 text-sm font-semibold text-white hover:bg-royal/90"
        >
          Done
        </button>
      }
    >
      {error ? (
        <p role="alert" className="text-sm font-medium text-s-red">
          {error}
        </p>
      ) : entries === null ? (
        <p className="text-sm text-ink-muted">Loading…</p>
      ) : (
        <ol className="relative space-y-4 border-l-2 border-line pl-5">
          {entries.map((e, i) => (
            <li key={`${e.action}-${e.at}-${i}`} className="relative">
              <span
                aria-hidden="true"
                className={`absolute -left-[27px] top-1 h-3 w-3 rounded-full border-2 border-white ${
                  i === 0 ? 'bg-royal' : 'bg-ink-muted'
                }`}
              />
              <div className="flex flex-wrap items-center gap-2">
                {e.to_label && <StatusChip tone={STATUS_TONES[e.to ?? ''] ?? 'tint-gray'}>{e.to_label}</StatusChip>}
                <span className="text-sm font-semibold text-ink">{e.label}</span>
              </div>
              <p className="mt-0.5 text-xs text-ink-muted">
                {formatDateTime(e.at) || '—'}
                {e.by ? ` · ${e.by}` : ''}
                {e.by_office ? `, ${e.by_office}` : ''}
              </p>
              {e.reason && <p className="mt-1 text-sm leading-relaxed text-ink-secondary">Reason: {e.reason}</p>}
            </li>
          ))}
        </ol>
      )}
    </Modal>
  )
}
