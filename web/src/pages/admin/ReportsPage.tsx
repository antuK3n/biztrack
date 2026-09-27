import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { FieldLabel, PageTitle, ProtoCard, inputCls } from '../../components/ui/Proto'
import { analytics } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { toApiError } from '../../lib/api'
import type {
  LguReport,
  ReportColumn,
  ReportKey,
  ReportRow,
  ReportSection,
} from '../../lib/types'
import { AnalyticsTabs } from './AnalyticsTabs'
import { OfficeScope } from './OfficeScope'

/*
 * Report Generation (checklist "Manage Approved Permits – Ken", item 7).
 *
 * Five reports an LGU office actually files — permits issued new vs renewal,
 * collections by nature of fee, businesses by barangay and kind, clearances per
 * office, and processing time / pending — over a period the reader picks, for
 * their own office (or, for BPLO and the super admin, any office or all). See
 * App\Support\LguReports for what each one is modelled on.
 *
 * THE SCREEN IS THE DOCUMENT. What is shown under the controls is the page that
 * prints: the City of Malabon header, the report, and the signature lines. The
 * print stylesheet at the bottom hides everything else and sets A4, so "Print"
 * and the browser's "Save as PDF" both produce the filed copy. There is no
 * separate PDF rendering to drift from the screen.
 *
 * The choices live in the URL (report, from, to, office), so a report can be
 * bookmarked or sent to a colleague and reopens as it was — the office still
 * decided by the server for whoever opens it.
 */

/*
 * The menu's labels. The server sends the same titles (LguReports::REPORTS) and
 * the printed heading is always the server's; these are here so the menu can
 * draw before the first request returns. LguReportsTest pins the five keys.
 */
const REPORT_TITLES: Record<ReportKey, string> = {
  'permits-issued': 'Permits Issued — New and Renewal',
  collections: 'Collections by Nature of Fee',
  'businesses-by-area': 'Businesses Permitted by Barangay and Kind of Business',
  clearances: 'Clearances Issued per Office',
  'pending-processing': 'Processing Time and Pending Applications',
}

const REPORT_KEYS = Object.keys(REPORT_TITLES) as ReportKey[]

function iso(date: Date): string {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

/** The period presets an office is actually asked for. */
function presets(): { label: string; from: string; to: string }[] {
  const today = new Date()
  const y = today.getFullYear()
  const m = today.getMonth()
  const quarterStart = Math.floor(m / 3) * 3

  return [
    { label: 'This month', from: iso(new Date(y, m, 1)), to: iso(today) },
    { label: 'Last month', from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) },
    { label: 'This quarter', from: iso(new Date(y, quarterStart, 1)), to: iso(today) },
    { label: 'This year', from: iso(new Date(y, 0, 1)), to: iso(today) },
  ]
}

function longDate(value: string): string {
  const [y, m, d] = value.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('en-PH', {
    month: 'long',
    day: 'numeric',
    year: 'numeric',
  })
}

/** A dash, never a zero, where a figure has no value (AGENTS.md §6.4). */
function formatCell(value: string | number | null | undefined, format: ReportColumn['format']): string {
  if (value === null || value === undefined || value === '') return format === 'text' ? '' : '—'
  if (typeof value === 'string') return value
  switch (format) {
    case 'money':
      return value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    case 'decimal':
      return value.toLocaleString('en-PH', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
    case 'percent':
      return `${value.toFixed(1)}%`
    default:
      return value.toLocaleString('en-PH')
  }
}

function SectionTable({ section }: { section: ReportSection }) {
  const numeric = (column: ReportColumn) => column.format !== 'text'
  const cell = (row: ReportRow, column: ReportColumn) => formatCell(row[column.key], column.format)

  return (
    <section className="lgu-report-section mt-5 first:mt-0">
      <h3 className="mb-1.5 text-[13px] font-bold uppercase tracking-wide text-ink">{section.heading}</h3>
      <div className="overflow-x-auto print:overflow-visible">
        <table className="w-full border-collapse text-[12.5px]">
          <thead>
            <tr>
              {section.columns.map((column) => (
                <th
                  key={column.key}
                  scope="col"
                  className={`border border-ink/40 bg-[#eef1f8] px-2 py-1.5 font-semibold text-ink ${
                    numeric(column) ? 'text-right' : 'text-left'
                  }`}
                >
                  {column.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {section.rows.length === 0 ? (
              <tr>
                <td colSpan={section.columns.length} className="border border-ink/40 px-2 py-2 text-ink-muted">
                  Nothing on record for this period.
                </td>
              </tr>
            ) : (
              section.rows.map((row, index) => (
                <tr key={index}>
                  {section.columns.map((column, i) =>
                    i === 0 ? (
                      <th
                        key={column.key}
                        scope="row"
                        className="border border-ink/40 px-2 py-1 text-left font-normal text-ink"
                      >
                        {cell(row, column)}
                      </th>
                    ) : (
                      <td
                        key={column.key}
                        className={`tnum border border-ink/40 px-2 py-1 text-ink ${
                          numeric(column) ? 'text-right' : 'text-left'
                        }`}
                      >
                        {cell(row, column)}
                      </td>
                    ),
                  )}
                </tr>
              ))
            )}
          </tbody>
          {section.total && section.rows.length > 0 && (
            <tfoot>
              <tr>
                {section.columns.map((column, i) =>
                  i === 0 ? (
                    <th
                      key={column.key}
                      scope="row"
                      className="border border-ink/40 px-2 py-1.5 text-left font-bold text-ink"
                    >
                      {cell(section.total as ReportRow, column) || 'Total'}
                    </th>
                  ) : (
                    <td
                      key={column.key}
                      className={`tnum border border-ink/40 px-2 py-1.5 font-bold text-ink ${
                        numeric(column) ? 'text-right' : 'text-left'
                      }`}
                    >
                      {/* A total that does not apply (payments across offices)
                          is left blank rather than dashed: nothing is missing. */}
                      {section.total?.[column.key] === null ? '' : cell(section.total as ReportRow, column)}
                    </td>
                  ),
                )}
              </tr>
            </tfoot>
          )}
        </table>
      </div>
      {section.note && <p className="mt-1 text-[11px] leading-snug text-ink-secondary">{section.note}</p>}
    </section>
  )
}

function SignatureLine({ label, name, position }: { label: string; name?: string; position?: string }) {
  return (
    <div className="min-w-0">
      <p className="text-[12px] text-ink-secondary">{label}</p>
      <div className="mt-8 border-b border-ink" aria-hidden="true" />
      <p className="mt-1 text-[13px] font-semibold uppercase text-ink">{name || ' '}</p>
      <p className="text-[12px] text-ink-secondary">{position || ' '}</p>
    </div>
  )
}

/** The printable document: header, report, signatures. */
function ReportDocument({ report }: { report: LguReport }) {
  const generated = new Date(report.generated_at).toLocaleString('en-PH', {
    month: 'long',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
  const office =
    report.scope.office === null ? 'Business Permits and Licensing Office' : report.scope.office_name

  return (
    <article
      id="lgu-report"
      aria-labelledby="lgu-report-title"
      className="mx-auto max-w-[210mm] bg-white px-6 py-7 text-ink shadow-card sm:px-10 print:max-w-none print:px-0 print:py-0 print:shadow-none"
    >
      <header className="flex items-center gap-4 border-b-2 border-ink pb-3">
        {/* The seal carries no information the lines beside it do not; see
            PermitDetailPage for the same call. */}
        <img src="/malabon-seal.png" alt="" aria-hidden="true" width={56} height={57} className="h-14 w-auto" />
        <div className="leading-tight">
          <p className="text-[12px]">Republic of the Philippines</p>
          <p className="text-[16px] font-bold uppercase tracking-wide">City of Malabon</p>
          <p className="text-[13px]">{office}</p>
        </div>
      </header>

      <div className="mt-4 text-center">
        <h2 id="lgu-report-title" className="text-[17px] font-bold uppercase tracking-wide">
          {report.title}
        </h2>
        <p className="mt-0.5 text-[13px]">
          For the period {longDate(report.period.from)} to {longDate(report.period.to)}
        </p>
        <p className="text-[12px] text-ink-secondary">
          {report.scope.office === null ? 'All offices' : report.scope.office_name}
        </p>
      </div>

      <div className="mt-5">
        {report.sections.map((section) => (
          <SectionTable key={section.heading} section={section} />
        ))}
      </div>

      <div className="lgu-report-signatures mt-10 grid grid-cols-2 gap-10">
        <SignatureLine
          label="Prepared by:"
          name={report.prepared_by.name}
          position={report.prepared_by.position}
        />
        <SignatureLine
          label="Noted by:"
          name={report.noted_by?.name}
          position={report.noted_by?.position ?? 'Head of Office'}
        />
      </div>

      <p className="mt-8 border-t border-line pt-2 text-[10.5px] text-ink-muted">
        Generated from the BizTrack register on {generated}. Figures are counted from the register
        as it stood at that moment.
      </p>
    </article>
  )
}

/*
 * Print: A4, the document only. `visibility` rather than `display: none` on
 * the app chrome, because the report sits inside the shell's layout and hiding
 * its ancestors with display would hide it too.
 */
const PRINT_CSS = `
@media print {
  @page { size: A4 portrait; margin: 14mm 12mm; }
  body { background: #fff !important; }
  body * { visibility: hidden; }
  #lgu-report, #lgu-report * { visibility: visible; }
  #lgu-report { position: absolute; left: 0; top: 0; width: 100%; }
  #lgu-report thead { display: table-header-group; }
  #lgu-report tr, .lgu-report-signatures { break-inside: avoid; }
  #lgu-report .lgu-report-section h3 { break-after: avoid; }
}
`

export function ReportsPage() {
  const [params, setParams] = useSearchParams()
  const defaults = presets()[0]

  const key = (REPORT_KEYS as string[]).includes(params.get('report') ?? '')
    ? (params.get('report') as ReportKey)
    : 'permits-issued'
  const from = params.get('from') ?? defaults.from
  const to = params.get('to') ?? defaults.to
  const office = params.get('office') ?? undefined

  const [csvBusy, setCsvBusy] = useState(false)
  const [csvError, setCsvError] = useState<string | null>(null)

  function update(next: Record<string, string | undefined>) {
    const merged = new URLSearchParams(params)
    for (const [name, value] of Object.entries(next)) {
      if (value === undefined || value === '') merged.delete(name)
      else merged.set(name, value)
    }
    setParams(merged, { replace: true })
  }

  const periodInvalid = from > to

  const { data: report, loading, error, reload } = useAsync(
    () =>
      periodInvalid
        ? Promise.reject(new Error('The end date has to be on or after the start date.'))
        : analytics.report(key, from, to, office),
    [key, from, to, office],
  )

  async function downloadCsv() {
    setCsvBusy(true)
    setCsvError(null)
    try {
      await analytics.reportCsv(key, from, to, office)
    } catch (err) {
      setCsvError(toApiError(err).message)
    } finally {
      setCsvBusy(false)
    }
  }

  async function downloadDashboardPdf() {
    setCsvError(null)
    try {
      await analytics.dashboardReport(12, office)
    } catch (err) {
      setCsvError(toApiError(err).message)
    }
  }

  return (
    <div>
      <style>{PRINT_CSS}</style>
      <PageTitle>Reports</PageTitle>

      <div className="print:hidden">
        <AnalyticsTabs />

        <ProtoCard className="mb-5 px-4 py-4">
          <div className="grid gap-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
            <div>
              <label htmlFor="report-key">
                <FieldLabel>Report</FieldLabel>
              </label>
              <select
                id="report-key"
                className={inputCls}
                value={key}
                onChange={(event) => update({ report: event.target.value })}
              >
                {REPORT_KEYS.map((k) => (
                  <option key={k} value={k}>
                    {REPORT_TITLES[k]}
                  </option>
                ))}
              </select>
            </div>
            <label className="block">
              <FieldLabel>From</FieldLabel>
              <input
                type="date"
                className={inputCls}
                value={from}
                max={to}
                onChange={(event) => update({ from: event.target.value })}
              />
            </label>
            <label className="block">
              <FieldLabel>To</FieldLabel>
              <input
                type="date"
                className={inputCls}
                value={to}
                min={from}
                onChange={(event) => update({ to: event.target.value })}
              />
            </label>
          </div>

          <div className="mt-3 flex flex-wrap items-center gap-2" role="group" aria-label="Period presets">
            {presets().map((preset) => {
              const active = preset.from === from && preset.to === to
              return (
                <button
                  key={preset.label}
                  type="button"
                  aria-pressed={active}
                  onClick={() => update({ from: preset.from, to: preset.to })}
                  className={`rounded-full border px-3.5 py-1 text-[12.5px] font-semibold transition-colors ${
                    active
                      ? 'border-royal bg-royal-tint text-royal'
                      : 'border-line bg-white text-ink-secondary hover:border-royal hover:text-royal'
                  }`}
                >
                  {preset.label}
                </button>
              )
            })}
          </div>

          <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3">
            {report ? (
              <OfficeScope
                scope={report.scope}
                onChange={(value) => update({ office: value })}
                id="report-office"
              />
            ) : (
              <span />
            )}
            <div className="flex flex-wrap items-center gap-2">
              {csvError && (
                <span role="alert" className="max-w-[22rem] text-xs font-medium text-s-red">
                  {csvError}
                </span>
              )}
              <button
                type="button"
                onClick={downloadDashboardPdf}
                className="rounded-lg px-3 py-2.5 text-sm font-semibold text-royal hover:bg-royal-tint"
              >
                Dashboard summary (PDF)
              </button>
              <button
                type="button"
                onClick={() => {
                  if (report && !csvBusy) void downloadCsv()
                }}
                aria-disabled={!report || csvBusy ? true : undefined}
                aria-busy={csvBusy}
                className="rounded-lg border border-royal px-5 py-2.5 text-sm font-semibold text-royal hover:bg-royal-tint aria-disabled:opacity-60"
              >
                {csvBusy ? 'Preparing…' : 'Download CSV'}
              </button>
              <button
                type="button"
                onClick={() => report && window.print()}
                aria-disabled={!report ? true : undefined}
                className="rounded-lg bg-royal px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:bg-royal-hover aria-disabled:opacity-60"
              >
                Print
              </button>
            </div>
          </div>
        </ProtoCard>
      </div>

      {periodInvalid ? (
        <p role="alert" className="text-sm font-medium text-s-red print:hidden">
          The end date has to be on or after the start date.
        </p>
      ) : loading ? (
        <div className="mx-auto max-w-[210mm] space-y-3 bg-white p-8 print:hidden">
          <Skeleton className="h-14 w-2/3" />
          <Skeleton className="h-6 w-1/2" />
          <Skeleton className="h-48 w-full" />
        </div>
      ) : error ? (
        <div className="print:hidden">
          <ErrorState error={error} onRetry={reload} />
        </div>
      ) : report ? (
        <ReportDocument report={report} />
      ) : null}
    </div>
  )
}
