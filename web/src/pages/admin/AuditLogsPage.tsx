import { useState } from 'react'
import { admin } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { formatDateTime } from '../../lib/format'
import type { AuditLog } from '../../lib/types'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { PageTitle, ProtoCard, StatusChip } from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { AuditIcon, ChevronRightIcon } from '../../components/icons'

/*
 * Audit logs, restyled to the prototype table language (white ProtoCard,
 * uppercase muted headers, tinted action chips). Fetch + pagination unchanged.
 */

/** "App\Models\Application" → "Application". */
function shortType(auditableType: string): string {
  const parts = auditableType.split('\\')
  return parts[parts.length - 1] || auditableType
}

function actionTone(action: string): ChipTone {
  const a = action.toLowerCase()
  if (/reject|delete|blacklist|fail|deactivate/.test(a)) return 'tint-red'
  if (/approve|create|issue|pass|register|submit/.test(a)) return 'tint-green'
  if (/toggle|update|reschedul|return/.test(a)) return 'tint-yellow'
  return 'tint-gray'
}

function hasChanges(changes: AuditLog['changes']): boolean {
  return !!changes && Object.keys(changes).length > 0
}

function LogRow({ log }: { log: AuditLog }) {
  const [open, setOpen] = useState(false)
  const canExpand = hasChanges(log.changes)

  return (
    <>
      <tr className="border-t border-line align-top">
        <td className="whitespace-nowrap px-5 py-3.5 text-ink-secondary tnum">{formatDateTime(log.created_at)}</td>
        <td className="px-5 py-3.5">
          <StatusChip tone={actionTone(log.action)} className="tnum">
            {log.action}
          </StatusChip>
        </td>
        {/*
          "System" was asserted for every entry the trail carries no actor for —
          28% of them, and every one is a `user.logged_in` row, which a person
          performed. An audit log that names the wrong actor is worse than one
          that admits it does not know, so an absent actor now reads as absent.
        */}
        <td className="px-5 py-3.5 font-medium text-ink">
          {log.user?.name ?? <span className="text-ink-muted">Not recorded</span>}
        </td>
        <td className="px-5 py-3.5 text-ink-secondary">
          {shortType(log.auditable_type)} #{log.auditable_id}
        </td>
        <td className="px-5 py-3.5 text-right">
          {canExpand ? (
            <button
              type="button"
              onClick={() => setOpen((v) => !v)}
              aria-expanded={open}
              className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm font-semibold text-royal transition-colors duration-150 hover:bg-royal-tint"
            >
              Details
              <ChevronRightIcon size={15} className={`transition-transform duration-150 ${open ? 'rotate-90' : ''}`} />
            </button>
          ) : (
            <span className="text-sm text-ink-muted">—</span>
          )}
        </td>
      </tr>
      {open && canExpand && (
        <tr className="border-t border-line bg-canvas/40">
          <td colSpan={5} className="px-5 py-3">
            <pre className="tnum overflow-x-auto rounded-md border border-line bg-white p-3 text-xs text-ink-secondary">
              {JSON.stringify(log.changes, null, 2)}
            </pre>
          </td>
        </tr>
      )}
    </>
  )
}

export function AuditLogsPage() {
  const [page, setPage] = useState(1)
  const { data, loading, error, reload } = useAsync(() => admin.auditLogs(page), [page])

  const logs = data?.data ?? []
  const lastPage = data?.lastPage ?? 1

  return (
    <div>
      <PageTitle>Audit Logs</PageTitle>

      {loading ? (
        <SkeletonList rows={8} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : logs.length === 0 ? (
        <EmptyState
          icon={AuditIcon}
          title="No audit entries yet"
          description="Actions like submissions, approvals, and account changes will appear here as they happen."
        />
      ) : (
        <ProtoCard className="overflow-hidden rounded-xl">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[48rem] text-left text-sm">
              <thead>
                <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  <th className="px-5 py-3">When</th>
                  <th className="px-5 py-3">Action</th>
                  <th className="px-5 py-3">User</th>
                  <th className="px-5 py-3">Target</th>
                  <th className="px-5 py-3 text-right">Changes</th>
                </tr>
              </thead>
              <tbody>
                {logs.map((log) => (
                  <LogRow key={log.id} log={log} />
                ))}
              </tbody>
            </table>
          </div>

          <div className="flex items-center justify-between border-t border-line px-5 py-3.5">
            <p className="text-sm text-ink-muted">
              Page {page} of {lastPage}
            </p>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                aria-label="Previous page"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas disabled:opacity-40"
              >
                ‹
              </button>
              <span className="flex h-7 min-w-7 items-center justify-center rounded-md bg-royal-deep px-1.5 text-xs font-semibold text-white">
                {page}
              </span>
              <button
                type="button"
                aria-label="Next page"
                onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                disabled={page >= lastPage}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas disabled:opacity-40"
              >
                ›
              </button>
            </div>
          </div>
        </ProtoCard>
      )}
    </div>
  )
}
