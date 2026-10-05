import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { legacyImports } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { toApiError } from '../../lib/api'
import { formatBytes, formatDateTime } from '../../lib/format'
import type { LegacyImport, LegacyImportReject, LegacyRejectKind } from '../../lib/types'
import { Alert } from '../../components/ui/Alert'
import { ErrorState, SkeletonList } from '../../components/ui/primitives'
import { PageTitle, PillButton, ProtoCard } from '../../components/ui/Proto'
import { DownloadIcon, UploadIcon } from '../../components/icons'

/*
 * Import Records — the super admin brings the old register into BizTrack.
 *
 * Ken's checklist, 27 September 2026: "Migration 1" (CSV). The flow is the one
 * the brief sets and the API enforces — upload, DRY RUN, read the verdict,
 * confirm, import — so this screen is three states of one card rather than a
 * wizard:
 *
 *   1. upload a CSV in BizTrack's template
 *   2. the dry run: what would be created, updated and rejected, and why each
 *      rejected row was refused
 *   3. the run: done, running on the queue (polled), or failed with the reason
 *
 * Nothing reaches the register before the confirm in step 2. The copy says so,
 * once, because the thing a person hesitates over on an import screen is
 * whether pressing the first button already did something.
 */

/** The reject kinds in words a BPLO clerk would use. */
const KIND_LABEL: Record<LegacyRejectKind, string> = {
  bad_date: 'Bad date',
  unknown_barangay: 'Unknown barangay',
  unknown_permit_type: 'Unknown permit type',
  missing_owner: 'Missing owner',
  duplicate: 'Duplicate',
  missing: 'Missing value',
  invalid: 'Not valid',
}

function plural(n: number, one: string, many = `${one}s`): string {
  return `${n.toLocaleString()} ${n === 1 ? one : many}`
}

export function ImportPage() {
  const [current, setCurrent] = useState<LegacyImport | null>(null)
  const guide = useAsync(() => legacyImports.guide(), [])
  const history = useAsync(() => legacyImports.history(), [])

  /*
   * A queued import finishes on the worker, not in the request, so the card
   * polls until it has an answer. Two seconds: an import of a few thousand
   * rows takes tens of seconds, and nothing here needs to be faster than a
   * person glancing back at the screen.
   */
  const pending = current?.status === 'queued' || current?.status === 'running'
  const pendingId = pending ? current?.id : undefined
  const reloadHistory = history.reload
  useEffect(() => {
    if (pendingId === undefined) return
    const timer = window.setInterval(async () => {
      try {
        const next = await legacyImports.show(pendingId)
        setCurrent(next)
        if (next.status === 'completed' || next.status === 'failed') reloadHistory()
      } catch {
        // A missed poll is retried by the next tick.
      }
    }, 2000)
    return () => window.clearInterval(timer)
  }, [pendingId, reloadHistory])

  const start = () => {
    setCurrent(null)
  }

  return (
    <div>
      <PageTitle>Import Records</PageTitle>

      <p className="mb-5 max-w-[70ch] text-sm leading-relaxed text-ink-secondary">
        Bring businesses and their permits in from the city’s old system. Every import is checked
        first: you see what would be added, updated and refused, and nothing is written until you
        confirm.
      </p>

      {current === null ? (
        <>
          <CsvSourceCard onPreviewed={setCurrent} />
          <ColumnGuide guide={guide} />
        </>
      ) : (
        <ResultCard
          result={current}
          onRun={async () => {
            const next = await legacyImports.run(current.id)
            setCurrent(next)
            history.reload()
          }}
          onRestart={start}
        />
      )}

      <History history={history} />
    </div>
  )
}

/* ── 1. The source ───────────────────────────────────────────────────── */

function CsvSourceCard({ onPreviewed }: { onPreviewed: (i: LegacyImport) => void }) {
  const [file, setFile] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [downloadError, setDownloadError] = useState<string | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!file) {
      setError('Choose the CSV file to check first.')
      return
    }
    setBusy(true)
    setError(null)
    try {
      onPreviewed(await legacyImports.previewCsv(file))
    } catch (err) {
      const apiError = toApiError(err)
      setError(apiError.errors.file?.[0] ?? apiError.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <ProtoCard className="rounded-xl p-5">
      <form onSubmit={submit} className="space-y-5">
        <div>
          <h2 className="text-base font-semibold text-ink">1. Fill in the template</h2>
          <p className="mt-1 max-w-[70ch] text-sm text-ink-secondary">
            One row per permit, repeating the business’s details on each. A business with no permit on
            record is one row with the permit columns left empty. The column guide below says what
            goes in each.
          </p>
          <button
            type="button"
            onClick={async () => {
              setDownloadError(null)
              try {
                await legacyImports.template()
              } catch (err) {
                setDownloadError(toApiError(err).message)
              }
            }}
            className="mt-3 inline-flex items-center gap-2 rounded-full border-2 border-royal px-4 py-1.5 text-sm font-semibold text-royal transition-colors hover:bg-royal-tint"
          >
            <DownloadIcon size={16} />
            Download the template
          </button>
          {downloadError && (
            <p role="alert" className="mt-2 text-xs font-medium text-s-red">
              {downloadError}
            </p>
          )}
        </div>

        <div>
          <h2 className="text-base font-semibold text-ink">2. Upload it to check</h2>
          {/* A real <label> wrapping the input, as on the clearance sheet: the
              control is restyled but keeps its name and its keyboard stop. */}
          <label className="mt-3 flex max-w-xl cursor-pointer items-center gap-3 rounded-lg border-2 border-dashed border-input-border bg-input/50 px-5 py-3.5 transition-colors focus-within:ring-2 focus-within:ring-royal hover:bg-input">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-input-border bg-white text-royal">
              <UploadIcon size={18} />
            </span>
            <span className="min-w-0 flex-1">
              <span className="block truncate text-sm font-semibold text-ink">
                {file ? file.name : 'Choose a CSV file'}
              </span>
              <span className="block text-xs text-ink-secondary">
                {file ? formatBytes(file.size) : 'Saved from Excel as “CSV UTF-8”, up to 20 MB.'}
              </span>
            </span>
            <input
              type="file"
              accept=".csv,text/csv"
              className="sr-only"
              aria-label="CSV file to import"
              onChange={(e) => {
                setFile(e.target.files?.[0] ?? null)
                setError(null)
              }}
            />
          </label>
          {error && (
            <p role="alert" className="mt-2 max-w-xl text-sm font-medium text-s-red">
              {error}
            </p>
          )}
        </div>

        <PillButton type="submit" aria-disabled={busy}>
          {busy ? 'Checking…' : 'Check the file'}
        </PillButton>
      </form>
    </ProtoCard>
  )
}

/* ── 2 and 3. The verdict, then the run ───────────────────────────────── */

function ResultCard({
  result,
  onRun,
  onRestart,
}: {
  result: LegacyImport
  onRun: () => Promise<void>
  onRestart: () => void
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const writable = result.will_create + result.will_update
  const b = result.breakdown

  const run = async () => {
    if (busy || writable === 0) return
    setBusy(true)
    setError(null)
    try {
      await onRun()
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  const done = result.status === 'completed'

  return (
    <ProtoCard className="rounded-xl p-5">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-base font-semibold text-ink">
          {result.status === 'previewed' ? 'Checked — nothing imported yet' : 'Import'}
          <span className="ml-2 font-normal text-ink-secondary">{result.file_name}</span>
        </h2>
        <button type="button" onClick={onRestart} className="text-sm font-semibold text-royal hover:underline">
          {result.status === 'previewed' ? 'Choose another source' : 'Start another import'}
        </button>
      </div>

      {/* The counts as a plain table row: four numbers someone compares, not
          four hero tiles (DESIGN.md — no stat-card dashboards). */}
      <table className="mt-4 w-full max-w-2xl text-left text-sm" aria-label="Import counts">
        <thead>
          <tr className="text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
            <th className="py-1.5 pr-4">Rows read</th>
            <th className="py-1.5 pr-4">{done ? 'Created' : 'Will create'}</th>
            <th className="py-1.5 pr-4">{done ? 'Updated' : 'Will update'}</th>
            <th className="py-1.5">Rejected</th>
          </tr>
        </thead>
        <tbody>
          <tr className="tnum text-lg font-semibold text-ink">
            <td className="py-1 pr-4" data-testid="count-total">{result.total_rows.toLocaleString()}</td>
            <td className="py-1 pr-4" data-testid="count-create">{(done ? result.created_count : result.will_create).toLocaleString()}</td>
            <td className="py-1 pr-4" data-testid="count-update">{(done ? result.updated_count : result.will_update).toLocaleString()}</td>
            <td className="py-1" data-testid="count-rejected">{result.rejected.toLocaleString()}</td>
          </tr>
        </tbody>
      </table>

      {b && <BreakdownLine b={b} />}

      <div className="mt-4 space-y-3">
        {result.status === 'previewed' && (
          <>
            {writable === 0 ? (
              <Alert variant="warning" title="Nothing here can be imported">
                Every row was refused. Correct the rows listed below and check the file again.
              </Alert>
            ) : result.rejected > 0 ? (
              <Alert variant="info">
                The {plural(result.rejected, 'rejected row')} will be skipped. You can import the
                rest now and bring those in later — rows are matched on the old system’s IDs, so
                importing again never makes a copy.
              </Alert>
            ) : null}
            {error && <Alert variant="error">{error}</Alert>}
            <PillButton onClick={run} aria-disabled={busy || writable === 0}>
              {busy ? 'Importing…' : `Import ${plural(writable, 'row')}`}
            </PillButton>
          </>
        )}
        {(result.status === 'queued' || result.status === 'running') && (
          <Alert variant="info" title="Importing">
            {result.processed_rows > 0
              ? `${result.processed_rows.toLocaleString()} of ${result.total_rows.toLocaleString()} rows done.`
              : 'Waiting for the import worker to start.'}{' '}
            You can leave this page; the import carries on and appears in the list below.
          </Alert>
        )}
        {done && (
          <Alert variant="success" title="Imported">
            {plural(result.created_count, 'row')} added something new
            {result.updated_count > 0
              ? ` and ${plural(result.updated_count, 'row')} updated what an earlier import made.`
              : '.'}
            {result.rejected > 0 && ` ${plural(result.rejected, 'rejected row')} ${result.rejected === 1 ? 'was' : 'were'} skipped.`}
          </Alert>
        )}
        {result.status === 'failed' && (
          <Alert variant="error" title="The import stopped">
            {result.error}
          </Alert>
        )}
      </div>

      {(result.rejects?.length ?? 0) > 0 && <RejectsTable rejects={result.rejects ?? []} total={result.rejected} />}
    </ProtoCard>
  )
}

/**
 * What the rows add up to, naming only what is there — "0 updated permits"
 * is a clause a reader has to parse to learn nothing.
 */
function BreakdownLine({ b }: { b: NonNullable<LegacyImport['breakdown']> }) {
  const parts = [
    b.businesses_new > 0 && plural(b.businesses_new, 'new business', 'new businesses'),
    b.businesses_updated > 0 && plural(b.businesses_updated, 'updated business', 'updated businesses'),
    b.permits_new > 0 && plural(b.permits_new, 'new permit'),
    b.permits_updated > 0 && plural(b.permits_updated, 'updated permit'),
  ].filter(Boolean)
  if (parts.length === 0) return null

  return (
    <p className="mt-2 text-sm text-ink-secondary">
      {parts.join(', ')}.
      {b.owners_unclaimed > 0 &&
        (b.owners_unclaimed === 1
          ? ' 1 new business has no owner account yet.'
          : ` ${b.owners_unclaimed.toLocaleString()} new businesses have no owner account yet.`)}
      {b.owners_linked > 0 &&
        ` ${plural(b.owners_linked, 'new business', 'new businesses')} will be linked to the owner’s existing account by email.`}
    </p>
  )
}

function RejectsTable({ rejects, total }: { rejects: LegacyImportReject[]; total: number }) {
  return (
    <div className="mt-6">
      <h3 className="text-sm font-semibold text-ink">
        Rejected rows
        {total > rejects.length && (
          <span className="ml-1 font-normal text-ink-secondary">
            — the first {rejects.length.toLocaleString()} of {total.toLocaleString()}
          </span>
        )}
      </h3>
      <div className="mt-2 overflow-x-auto rounded-lg border border-line">
        <table className="w-full min-w-[40rem] text-left text-sm" aria-label="Rejected rows">
          <thead>
            <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
              <th className="px-4 py-2.5">Row</th>
              <th className="px-4 py-2.5">Business</th>
              <th className="px-4 py-2.5">Why it was refused</th>
            </tr>
          </thead>
          <tbody>
            {rejects.map((r) => (
              <tr key={r.row} className="border-t border-line align-top">
                <td className="tnum px-4 py-2.5 text-ink-secondary">{r.row}</td>
                <td className="px-4 py-2.5">
                  <span className="block font-medium text-ink">{r.business_name ?? '—'}</span>
                  <span className="tnum block text-xs text-ink-muted">{r.legacy_business_id ?? 'No ID'}</span>
                </td>
                <td className="px-4 py-2.5">
                  <ul className="space-y-1">
                    {r.reasons.map((reason, i) => (
                      <li key={i}>
                        <span className="font-semibold text-ink">{KIND_LABEL[reason.kind]}:</span>{' '}
                        <span className="text-ink-secondary">{reason.message}</span>
                      </li>
                    ))}
                  </ul>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/* ── The column guide, and the history ────────────────────────────────── */

function ColumnGuide({ guide }: { guide: ReturnType<typeof useAsync<Awaited<ReturnType<typeof legacyImports.guide>>>> }) {
  if (guide.loading) return <div className="mt-6"><SkeletonList rows={4} /></div>
  if (guide.error || !guide.data) return null
  const data = guide.data

  return (
    <details className="mt-6 rounded-xl border border-line bg-white">
      <summary className="cursor-pointer px-5 py-3.5 text-sm font-semibold text-ink">
        Column guide — what goes in each of the {data.columns.length} columns
      </summary>
      <div className="overflow-x-auto border-t border-line">
        <table className="w-full min-w-[40rem] text-left text-sm">
          <thead>
            <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
              <th className="px-5 py-2.5">Column</th>
              <th className="px-5 py-2.5">Required</th>
              <th className="px-5 py-2.5">What goes in it</th>
            </tr>
          </thead>
          <tbody>
            {data.columns.map((c) => (
              <tr key={c.column} className="border-t border-line align-top">
                <td className="px-5 py-2.5 font-mono text-xs text-ink">{c.column}</td>
                <td className="px-5 py-2.5 text-ink-secondary">{c.required === 'no' ? '—' : c.required}</td>
                <td className="px-5 py-2.5 text-ink-secondary">{c.description}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="border-t border-line px-5 py-3 text-xs text-ink-secondary">
        Barangays: {data.barangays.join(', ')}. Permit types:{' '}
        {data.permit_types.map((t) => `${t.code} (${t.name})`).join(', ')}.
      </p>
    </details>
  )
}

const STATUS_LABEL: Record<LegacyImport['status'], string> = {
  previewed: 'Checked, not imported',
  queued: 'Waiting to run',
  running: 'Running',
  completed: 'Imported',
  failed: 'Stopped',
}

function History({ history }: { history: ReturnType<typeof useAsync<LegacyImport[]>> }) {
  if (history.loading) return null
  if (history.error) return <div className="mt-8"><ErrorState error={history.error} onRetry={history.reload} /></div>
  const rows = history.data ?? []
  if (rows.length === 0) return null

  return (
    <section className="mt-8">
      <h2 className="mb-3 text-base font-semibold text-ink">Recent imports</h2>
      <ProtoCard className="overflow-hidden rounded-xl">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[44rem] text-left text-sm">
            <thead>
              <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                <th className="px-5 py-3">When</th>
                <th className="px-5 py-3">Source</th>
                <th className="px-5 py-3">By</th>
                <th className="px-5 py-3">Status</th>
                <th className="px-5 py-3 text-right">Created · Updated · Rejected</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((i) => (
                <tr key={i.id} className="border-t border-line">
                  <td className="tnum whitespace-nowrap px-5 py-3 text-ink-secondary">{formatDateTime(i.created_at)}</td>
                  <td className="px-5 py-3 text-ink">{i.file_name ?? '—'}</td>
                  <td className="px-5 py-3 text-ink-secondary">{i.user?.name ?? 'Command line'}</td>
                  <td className="px-5 py-3 text-ink-secondary">{STATUS_LABEL[i.status]}</td>
                  <td className="tnum px-5 py-3 text-right text-ink-secondary">
                    {i.status === 'completed'
                      ? `${i.created_count.toLocaleString()} · ${i.updated_count.toLocaleString()} · ${i.rejected.toLocaleString()}`
                      : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </ProtoCard>
    </section>
  )
}
