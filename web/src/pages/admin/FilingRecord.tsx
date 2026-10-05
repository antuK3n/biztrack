import { DocumentActions } from '../../components/DocumentActions'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { StatusChip } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { applications, officeForms, permits } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { PermitRegisterRow } from '../../lib/types'
import { OFFICE_COLUMNS, officeOf, type OfficeCode } from './permitColumns'
import { useState } from 'react'

/**
 * One filing, everything on it, read-only — the oversight view Records was
 * waiting for.
 *
 * [Client, 4 October 2026: "sa super admin records page it should be
 * magrereflect lahat pati finill outan, inuploads, at mga permit".] Three
 * sections, in the order the filing happened: the office sheets the applicant
 * filled in, the requirements they uploaded, and the permits that came out.
 *
 * ── Why this does not break the separation Records was built around ──────
 *
 * RecordsPage's own note forbids linking a row into the reviewer's screen:
 * the super admin does not hold `application.review`, and a link there would
 * either bounce the account or hand the overseer the buttons of the overseen.
 * This is the other thing that note asked for — "a read-only oversight detail
 * view is the right answer" — and it keeps to it. There is nothing here that
 * changes a filing. The only controls are View and Download on an upload and
 * View on a certificate, which read.
 *
 * ── The answers use the register's own labels ─────────────────────────────
 *
 * Each sheet is printed through OFFICE_COLUMNS — the same column definitions
 * the Permits table draws — so "No. of Storey of Building" says the same thing
 * here as it does there, and a field added to one appears in both.
 */
export function FilingRecord({ applicationId }: { applicationId: number }) {
  const { data, loading, error, reload } = useAsync(
    () => Promise.all([applications.get(applicationId), officeForms.list(applicationId)]),
    [applicationId],
  )
  const [viewing, setViewing] = useState<number | null>(null)
  const [viewError, setViewError] = useState<string | null>(null)

  if (loading && !data) {
    return (
      <div className="space-y-2 p-5">
        <Skeleton className="h-4 w-48" />
        <Skeleton className="h-16 w-full" />
      </div>
    )
  }
  if (error || !data) return <ErrorState error={error} onRetry={reload} />

  const [app, sheets] = data

  async function openPermit(id: number) {
    // The tab is opened inside the click, before the await, or the popup
    // blocker takes it — the rule the Permits page works to.
    const tab = window.open('', '_blank')
    setViewing(id)
    setViewError(null)
    try {
      await permits.viewPdf(id, tab)
    } catch (err) {
      tab?.close()
      setViewError(toApiError(err).message)
    } finally {
      setViewing(null)
    }
  }

  return (
    <div className="grid gap-6 bg-canvas/40 px-5 py-5 lg:grid-cols-3">
      <section aria-labelledby={`sheets-${applicationId}`}>
        <h3 id={`sheets-${applicationId}`} className="text-[11px] font-bold uppercase tracking-wider text-ink-muted">
          Forms filled in
        </h3>
        {sheets.length === 0 ? (
          <p className="mt-2 text-sm text-ink-muted">No office sheets on this filing.</p>
        ) : (
          <div className="mt-2 space-y-3">
            {sheets.map((sheet) => {
              const code = sheet.permit_type_code as OfficeCode
              const columns = OFFICE_COLUMNS[code] ?? []
              /*
               * A minimal row the column definitions can read: they look up
               * `office_form[key]` and check the permit type's code, nothing else.
               */
              const row = { office_form: sheet.form_data, permit_type: { code } } as unknown as PermitRegisterRow
              const answered = columns
                .map((c) => ({ label: c.label, value: c.value(row) }))
                .filter((a) => a.value !== null && a.value.trim() !== '')

              return (
                <div key={code} className="rounded-lg border border-line bg-white p-3">
                  <div className="flex items-center justify-between gap-2">
                    <p className="text-sm font-semibold text-ink">{officeOf(code)}</p>
                    <StatusChip tone={sheet.form_saved ? 'tint-green' : 'tint-gray'}>
                      {sheet.form_saved ? 'Submitted' : 'Not opened'}
                    </StatusChip>
                  </div>
                  {answered.length > 0 ? (
                    <dl className="mt-2 space-y-1 text-xs">
                      {answered.map((a) => (
                        <div key={a.label} className="grid grid-cols-[minmax(0,9rem)_1fr] gap-2">
                          <dt className="text-ink-muted">{a.label}</dt>
                          <dd className="font-medium text-ink">{a.value}</dd>
                        </div>
                      ))}
                    </dl>
                  ) : (
                    <p className="mt-2 text-xs text-ink-muted">No answers recorded.</p>
                  )}
                </div>
              )
            })}
          </div>
        )}
      </section>

      <section aria-labelledby={`uploads-${applicationId}`}>
        <h3 id={`uploads-${applicationId}`} className="text-[11px] font-bold uppercase tracking-wider text-ink-muted">
          Requirements uploaded
        </h3>
        {app.documents.length === 0 ? (
          <p className="mt-2 text-sm text-ink-muted">Nothing uploaded.</p>
        ) : (
          <ul className="mt-2 space-y-2">
            {app.documents.map((d) => (
              <li key={d.id} className="rounded-lg border border-line bg-white p-3">
                <p className="text-sm font-semibold text-ink">{d.document_type.name}</p>
                <p className="truncate text-xs text-ink-muted" title={d.original_filename}>
                  {d.original_filename} · {formatDate(d.created_at)}
                </p>
                <div className="mt-1.5">
                  <DocumentActions id={d.id} filename={d.original_filename} label={d.document_type.name} />
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section aria-labelledby={`permits-${applicationId}`}>
        <h3 id={`permits-${applicationId}`} className="text-[11px] font-bold uppercase tracking-wider text-ink-muted">
          Permits issued
        </h3>
        {app.permits.length === 0 ? (
          <p className="mt-2 text-sm text-ink-muted">No permit issued yet.</p>
        ) : (
          <ul className="mt-2 space-y-2">
            {app.permits.map((p) => (
              <li key={p.id} className="flex items-center justify-between gap-3 rounded-lg border border-line bg-white p-3">
                <div className="min-w-0">
                  <p className="tnum text-sm font-semibold text-ink">{p.permit_number}</p>
                  <p className="truncate text-xs text-ink-muted">
                    {p.permit_type?.name} · {p.status_label}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => openPermit(p.id)}
                  aria-label={`View certificate ${p.permit_number}`}
                  aria-disabled={viewing === p.id || undefined}
                  className="shrink-0 rounded-full border border-royal px-3.5 py-1.5 text-xs font-semibold text-royal hover:bg-royal hover:text-white aria-disabled:cursor-wait aria-disabled:opacity-50"
                >
                  {viewing === p.id ? 'Opening…' : 'View'}
                </button>
              </li>
            ))}
          </ul>
        )}
        {viewError && <p className="mt-2 text-xs font-medium text-s-red">{viewError}</p>}
      </section>
    </div>
  )
}
