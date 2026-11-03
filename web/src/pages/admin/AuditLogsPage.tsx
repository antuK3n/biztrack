import { useCallback, useEffect, useRef, useState } from 'react'
import { admin, paymentProofs, showBlob } from '../../lib/resources'
import type { AuditLogFilters } from '../../lib/resources'
import { toApiError } from '../../lib/api'
import { useAsync } from '../../lib/useAsync'
import { formatDateTime } from '../../lib/format'
import type { AuditLog, AuditReceipt } from '../../lib/types'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { FilterPills, PageTitle, ProtoCard, StatusChip } from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { AuditIcon, ChevronRightIcon } from '../../components/icons'

/*
 * Audit logs, restyled to the prototype table language (white ProtoCard,
 * uppercase muted headers, tinted action chips). Fetch + pagination unchanged.
 */

/** A file served to a signed-in reader (App\Support\DownloadLog). */
const DOWNLOADED = 'file.downloaded'

/*
 * The words an action is shown in, where it has been given any.
 *
 * Every other action still reads as its key, as it always has. The download
 * row was worded when it was added (thesis panel revision matrix, item 2), and
 * the filter below names it the same way, so the pill and the chip it finds
 * say the same thing.
 */
const ACTION_LABELS: Record<string, string> = {
  [DOWNLOADED]: 'Downloaded a file',
}

function actionLabel(action: string): string {
  return ACTION_LABELS[action] ?? action
}

/** "App\Models\Application" → "Application". */
function shortType(auditableType: string): string {
  const parts = auditableType.split('\\')
  return parts[parts.length - 1] || auditableType
}

function actionTone(action: string): ChipTone {
  const a = action.toLowerCase()
  if (/reject|delete|remove|withdrawn|blacklist|fail|deactivate/.test(a)) return 'tint-red'
  if (/approve|create|issue|pass|register|submit/.test(a)) return 'tint-green'
  if (/toggle|update|reschedul|return/.test(a)) return 'tint-yellow'
  return 'tint-gray'
}

function hasChanges(changes: AuditLog['changes']): boolean {
  return !!changes && Object.keys(changes).length > 0
}

/** A field's value as text — a dash, never a blank, where it had none. */
function fieldText(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  return String(value)
}

/*
 * The removed record, one field per row (Audit Log 1).
 *
 * A key/value table rather than the raw JSON the Changes panel shows, because
 * this is the one place the record still exists and the super admin reads it
 * to answer "what exactly was deleted" — forty columns of minified JSON is not
 * an answer. Child rows (a draft's documents, a business's address) have
 * their own shape, so they stay as indented JSON under their own heading.
 */
function SnapshotView({ snapshot }: { snapshot: Record<string, unknown> }) {
  const entries = Object.entries(snapshot)
  const fields = entries.filter(([, v]) => v === null || typeof v !== 'object')
  const children = entries.filter(([, v]) => v !== null && typeof v === 'object')

  return (
    <div className="space-y-3">
      <p className="text-sm font-semibold text-ink">The record as it stood before it was removed</p>
      <dl className="grid grid-cols-1 gap-x-6 gap-y-1 rounded-md border border-line bg-white p-3 text-xs sm:grid-cols-2">
        {fields.map(([key, value]) => (
          <div key={key} className="flex min-w-0 gap-2 border-b border-line/60 py-1 last:border-b-0">
            <dt className="w-40 shrink-0 font-medium text-ink-muted">{key}</dt>
            <dd className="tnum min-w-0 break-words text-ink">{fieldText(value)}</dd>
          </div>
        ))}
      </dl>
      {children.map(([key, value]) => (
        <div key={key}>
          <p className="mb-1 text-xs font-semibold text-ink-secondary">{key}</p>
          <pre className="tnum overflow-x-auto rounded-md border border-line bg-white p-3 text-xs text-ink-secondary">
            {JSON.stringify(value, null, 2)}
          </pre>
        </div>
      ))}
    </div>
  )
}

/** JPG and PNG are drawn; anything else (a PDF) is named, with View. */
function isPicture(receipt: AuditReceipt): boolean {
  return receipt.mime_type?.startsWith('image/') ?? false
}

/*
 * One receipt under a payment row's Details: the picture, or a PDF's name,
 * and View to open it full size in a tab, as OR Records does.
 *
 * Fetched here and nowhere else, and this mounts only while Details is open.
 * Every fetch of a receipt writes a `file.downloaded` row (App\Support\
 * DownloadLog), so a thumbnail in the collapsed list would write one row per
 * payment every time the page loaded and bury the trail in its own reads.
 * One fetch per opening, kept while it stays open: View opens the copy in
 * hand rather than fetching again. A PDF has no preview, so it is fetched on
 * its first View and kept the same way.
 *
 * The request lives in a ref so React's development StrictMode, which runs
 * the effect twice, reuses it instead of fetching — and logging — twice.
 *
 * A file that cannot be had (gone from the disk, or refused) shows nothing:
 * the row's details above still say what happened.
 */
function Receipt({ receipt }: { receipt: AuditReceipt }) {
  const picture = isPicture(receipt)
  const file = useRef<Promise<Blob> | null>(null)
  const [src, setSrc] = useState<string | null>(null)
  const [missing, setMissing] = useState(false)
  const [opening, setOpening] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(() => {
    file.current ??= paymentProofs.file(receipt.id).catch((err: unknown) => {
      // Not kept: a View pressed again asks again.
      file.current = null
      throw err
    })
    return file.current
  }, [receipt.id])

  useEffect(() => {
    if (!picture) return
    let live = true
    let url: string | null = null
    load().then(
      (blob) => {
        if (!live) return
        url = URL.createObjectURL(blob)
        setSrc(url)
      },
      () => {
        if (live) setMissing(true)
      },
    )
    return () => {
      live = false
      if (url) URL.revokeObjectURL(url)
    }
  }, [picture, load])

  // The tab is opened inside the click, before any await, or the popup blocker takes it.
  async function view() {
    if (opening) return
    const tab = window.open('', '_blank')
    setOpening(true)
    setError(null)
    try {
      showBlob(await load(), tab)
    } catch (err) {
      tab?.close()
      setError(toApiError(err).message)
    } finally {
      setOpening(false)
    }
  }

  if (missing) return null

  return (
    <li className="min-w-0 space-y-1.5">
      {picture &&
        (src ? (
          <img
            src={src}
            alt="Photo of the receipt"
            className="h-60 max-w-full rounded-md border border-line bg-white object-contain"
          />
        ) : (
          <div aria-hidden="true" className="h-60 w-44 animate-pulse rounded-md border border-line bg-canvas" />
        ))}
      <p className="flex min-w-0 flex-wrap items-center gap-x-3 text-sm">
        {receipt.file_name && <span className="truncate text-ink-secondary">{receipt.file_name}</span>}
        <button
          type="button"
          onClick={() => void view()}
          aria-disabled={opening || undefined}
          // Two receipts can be open at once; each View is named by its file (AGENTS.md §6.2).
          aria-label={receipt.file_name ? `View ${receipt.file_name}` : undefined}
          className="font-semibold text-royal underline underline-offset-2 hover:no-underline aria-disabled:opacity-60"
        >
          View
        </button>
      </p>
      {error && (
        <p role="alert" className="text-xs font-medium text-s-red">
          {error}
        </p>
      )}
    </li>
  )
}

function LogRow({ log }: { log: AuditLog }) {
  const [open, setOpen] = useState(false)
  const removed = log.snapshot !== null && typeof log.snapshot === 'object'
  const receipts = log.receipts ?? []
  const canExpand = hasChanges(log.changes) || removed || receipts.length > 0
  /*
   * Some rows are about nothing in the register — a KwikPay callback refused
   * for its signature, a gateway switched — and carry no subject. They read
   * as a dash, as an empty field does in `fieldText`; reading the type
   * regardless threw on `split` and blanked the screen.
   */
  const target =
    log.auditable_type !== null ? `${shortType(log.auditable_type)} #${log.auditable_id}` : null

  return (
    <>
      <tr className="border-t border-line align-top">
        <td className="whitespace-nowrap px-5 py-3.5 text-ink-secondary tnum">{formatDateTime(log.created_at)}</td>
        <td className="px-5 py-3.5">
          <StatusChip tone={actionTone(log.action)} className="tnum">
            {actionLabel(log.action)}
          </StatusChip>
          {/* Said in words as well as by the chip's tone (Never Color Alone). */}
          {removed && <span className="ml-2 text-xs font-semibold text-ink-secondary">Removed</span>}
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
        <td className="px-5 py-3.5 text-ink-secondary">{target ?? '—'}</td>
        <td className="px-5 py-3.5 text-right">
          {canExpand ? (
            <button
              type="button"
              onClick={() => setOpen((v) => !v)}
              aria-expanded={open}
              aria-label={`${removed ? 'Removed record' : 'Details'} for ${actionLabel(log.action)}${target !== null ? ` on ${target}` : ''}`}
              className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm font-semibold text-royal transition-colors duration-150 hover:bg-royal-tint"
            >
              {removed ? 'View record' : 'Details'}
              <ChevronRightIcon size={15} className={`transition-transform duration-150 ${open ? 'rotate-90' : ''}`} />
            </button>
          ) : (
            <span className="text-sm text-ink-muted">—</span>
          )}
        </td>
      </tr>
      {open && canExpand && (
        <tr className="border-t border-line bg-canvas/40">
          <td colSpan={5} className="space-y-3 px-5 py-3">
            {hasChanges(log.changes) && (
              <pre className="tnum overflow-x-auto rounded-md border border-line bg-white p-3 text-xs text-ink-secondary">
                {JSON.stringify(log.changes, null, 2)}
              </pre>
            )}
            {/* Mounted only while open, so the pictures are fetched only then (see Receipt). */}
            {receipts.length > 0 && (
              <ul className="flex flex-wrap items-start gap-4">
                {receipts.map((receipt) => (
                  <Receipt key={receipt.id} receipt={receipt} />
                ))}
              </ul>
            )}
            {removed && <SnapshotView snapshot={log.snapshot as Record<string, unknown>} />}
          </td>
        </tr>
      )}
    </>
  )
}

type Scope = 'all' | 'payments' | 'removed' | 'downloads'

/*
 * What each filter asks the API for.
 *
 * Payments is a named group the server holds (App\Support\PaymentAudit::
 * ACTIONS) rather than a list kept here, so it pages and sorts like the rest
 * and a payment action added later joins it without a change to this screen.
 */
const SCOPE_FILTERS: Record<Scope, AuditLogFilters> = {
  all: {},
  payments: { group: 'payments' },
  removed: { removed: 1 },
  downloads: { action: DOWNLOADED },
}

export function AuditLogsPage() {
  const [page, setPage] = useState(1)
  const [scope, setScope] = useState<Scope>('all')
  const { data, loading, error, reload } = useAsync(
    () => admin.auditLogs({ page, ...SCOPE_FILTERS[scope] }),
    [page, scope],
  )

  const logs = data?.data ?? []
  const lastPage = data?.lastPage ?? 1

  return (
    <div>
      <PageTitle>Audit Logs</PageTitle>

      <div className="mb-5">
        <FilterPills<Scope>
          options={[
            { value: 'all', label: 'All actions' },
            { value: 'payments', label: 'Payments' },
            { value: 'removed', label: 'Removed' },
            { value: 'downloads', label: actionLabel(DOWNLOADED) },
          ]}
          value={scope}
          onChange={(v) => {
            setScope(v)
            setPage(1)
          }}
        />
      </div>

      {loading ? (
        <SkeletonList rows={8} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : logs.length === 0 ? (
        scope === 'removed' ? (
          <EmptyState
            icon={AuditIcon}
            title="Nothing has been removed"
            description="Deleted drafts and documents, deactivated accounts, and suspended or blacklisted records will appear here, each with a copy of what was removed."
          />
        ) : (
          <EmptyState
            icon={AuditIcon}
            title="No audit entries yet"
            description="Actions like submissions, approvals, and account changes will appear here as they happen."
          />
        )
      ) : (
        <ProtoCard className="overflow-hidden rounded-xl">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[48rem] text-left text-sm">
              <thead>
                <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  <th className="px-5 py-3">When</th>
                  <th className="px-5 py-3">Action</th>
                  <th className="px-5 py-3">User</th>
                  {/*
                    Under the downloads filter this column holds the record each
                    file was served from (PaymentProof #3, Permit #198), so it is
                    headed for that. The cells are the same under every filter;
                    only the heading changes, and every other filter keeps
                    "Target".
                  */}
                  <th className="px-5 py-3">{scope === 'downloads' ? 'Downloaded file' : 'Target'}</th>
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
                aria-disabled={page <= 1}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:opacity-40"
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
                aria-disabled={page >= lastPage}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:opacity-40"
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
