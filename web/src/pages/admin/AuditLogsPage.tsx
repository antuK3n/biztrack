import { useState } from 'react'
import { admin } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { formatDateTime } from '../../lib/format'
import type { AuditLog } from '../../lib/types'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { PageTitle, ProtoCard, StatusChip } from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { AuditIcon, ChevronRightIcon } from '../../components/icons'
import { DENSE_PAGE, dFoot, dPager, dTable, dTh, dTheadRow } from './dense'

/*
 * 2px of cell padding, not dense.ts's 4px: the action chip (~23px) and the
 * 24px Details button set each row's height, so at 2px a row is 29px and a
 * full page of entries fits a 1440×900 screen (client, 2026-09).
 */
const cell = 'px-3 py-0.5'

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
      <tr className="border-t border-line align-middle">
        <td className={`${cell} whitespace-nowrap text-ink-secondary tnum`}>{formatDateTime(log.created_at)}</td>
        <td className={cell}>
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
        <td className={`${cell} font-medium text-ink`}>
          {log.user?.name ?? <span className="text-ink-muted">Not recorded</span>}
        </td>
        <td className={`${cell} text-ink-secondary`}>
          {shortType(log.auditable_type)} #{log.auditable_id}
        </td>
        <td className={`${cell} text-right`}>
          {canExpand ? (
            <button
              type="button"
              onClick={() => setOpen((v) => !v)}
              aria-expanded={open}
              className="inline-flex h-6 items-center gap-1 rounded-md px-2 text-[13px] font-semibold text-royal transition-colors duration-150 hover:bg-royal-tint"
            >
              Details
              <ChevronRightIcon size={15} className={`transition-transform duration-150 ${open ? 'rotate-90' : ''}`} />
            </button>
          ) : (
            <span className="text-ink-muted">—</span>
          )}
        </td>
      </tr>
      {open && canExpand && (
        <tr className="border-t border-line bg-canvas/40">
          <td colSpan={5} className="px-3 py-2">
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
    <div {...DENSE_PAGE}>
      <PageTitle compact>Audit Logs</PageTitle>

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
            <table className={`${dTable} min-w-[48rem]`}>
              <thead>
                <tr className={dTheadRow}>
                  <th className={dTh}>When</th>
                  <th className={dTh}>Action</th>
                  <th className={dTh}>User</th>
                  <th className={dTh}>Target</th>
                  <th className={`${dTh} text-right`}>Changes</th>
                </tr>
              </thead>
              <tbody>
                {logs.map((log) => (
                  <LogRow key={log.id} log={log} />
                ))}
              </tbody>
            </table>
          </div>

          <div className={dFoot}>
            <p className="text-[13px] text-ink-muted">
              Page {page} of {lastPage}
            </p>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                aria-label="Previous page"
                /*
                 * aria-disabled, never the native attribute (AGENTS.md §6.2):
                 * a disabled control leaves the tab order, so a keyboard reader
                 * lost the pager at either end of the trail. The handlers clamp
                 * to page 1 and lastPage, so they need no guard.
                 */
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                aria-disabled={page <= 1 || undefined}
                className={dPager}
              >
                ‹
              </button>
              <span className="flex h-6 min-w-6 items-center justify-center rounded-md bg-royal-deep px-1.5 text-xs font-semibold text-white">
                {page}
              </span>
              <button
                type="button"
                aria-label="Next page"
                onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                aria-disabled={page >= lastPage || undefined}
                className={dPager}
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
