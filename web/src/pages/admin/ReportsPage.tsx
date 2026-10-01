import { useState } from 'react'
import type { ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Skeleton } from '../../components/ui/primitives'
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
import { AnalyticsError } from './AnalyticsError'
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

/*
 * How many of the last section's rows travel with the signatures. Three is
 * enough that the "Noted by" line visibly signs the end of a table rather than
 * a total floating on its own, and few enough that the page before is not left
 * noticeably short.
 */
const TAIL_ROWS = 3

/**
 * One section's table.
 *
 * `closing` is passed to the LAST section only: the signature block, which in
 * print rides inside this table's final row group together with the last few
 * rows, the total and the note. That row group is `break-inside: avoid`, so
 * the signatures can never start a page on their own — they move with the end
 * of the figures they sign. On screen the same block is drawn after the
 * document instead (see ReportDocument), and the in-table copy is not shown.
 *
 * Why inside the table rather than `break-before: avoid` on a block after it:
 * that was tried, and Chrome honoured it only for some page heights. On the
 * three-year BPLO collections report it still printed a third page holding
 * the signatures and nothing else. A row group that may not be split is a
 * rule Chrome keeps every time.
 */
function SectionTable({ section, closing }: { section: ReportSection; closing?: ReactNode }) {
  const numeric = (column: ReportColumn) => column.format !== 'text'
  const cell = (row: ReportRow, column: ReportColumn) => formatCell(row[column.key], column.format)

  const dataRow = (row: ReportRow, index: number) => (
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
  )

  const emptyRow = (
    <tr key="empty">
      <td colSpan={section.columns.length} className="border border-ink/40 px-2 py-2 text-ink-muted">
        Nothing on record for this period.
      </td>
    </tr>
  )

  /*
   * The total is a row in the table's LAST ROW GROUP, never a <tfoot>. Print
   * repeats a tfoot at the foot of every page the table crosses, so a
   * three-year collections report printed "Total 3,079,096.36" under page
   * one's nine months as if they summed to it. In the last row group it prints
   * once, after the last row, which is where a ledger's total belongs.
   */
  const totalRow = section.total && section.rows.length > 0 && (
    <tr key="total">
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
  )

  const note = (className: string) =>
    section.note && (
      <p className={`mt-1 text-[11px] leading-snug text-ink-secondary ${className}`}>{section.note}</p>
    )

  const split = closing ? Math.max(0, section.rows.length - TAIL_ROWS) : section.rows.length
  const body = section.rows.slice(0, split)
  const tail = section.rows.slice(split)

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
          {section.rows.length === 0 ? (
            <tbody className={closing ? 'lgu-report-tail' : undefined}>
              {emptyRow}
              {closing && <ClosingRow span={section.columns.length}>{closing}</ClosingRow>}
            </tbody>
          ) : (
            <>
              {body.length > 0 && <tbody>{body.map(dataRow)}</tbody>}
              <tbody className={closing ? 'lgu-report-tail' : undefined}>
                {tail.map((row, index) => dataRow(row, split + index))}
                {totalRow}
                {closing && (
                  <ClosingRow span={section.columns.length}>
                    {note('')}
                    {closing}
                  </ClosingRow>
                )}
              </tbody>
            </>
          )}
        </table>
      </div>
      {/* With a closing block the note prints inside the table's last row
          group, beside the signatures; on screen it is drawn here as always. */}
      {note(closing ? 'print:hidden' : '')}
    </section>
  )
}

/** A print-only, borderless row spanning the table: where the signatures ride. */
function ClosingRow({ span, children }: { span: number; children: ReactNode }) {
  return (
    <tr className="hidden print:table-row">
      <td colSpan={span} className="border-0 p-0">
        {children}
      </td>
    </tr>
  )
}

function SignatureLine({ label, name, position }: { label: string; name?: string; position?: string }) {
  return (
    <div className="min-w-0">
      <p className="text-[12px] text-ink-secondary">{label}</p>
      <div className="mt-8 border-b border-ink" aria-hidden="true" />
      <p className="mt-1 text-[13px] font-semibold uppercase text-ink">{name || ' '}</p>
      <p className="text-[12px] text-ink-secondary">{position || ' '}</p>
    </div>
  )
}

/** "Prepared by", "Noted by" and the line saying when the figures were counted. */
function ReportClosing({ report }: { report: LguReport }) {
  const generated = new Date(report.generated_at).toLocaleString('en-PH', {
    month: 'long',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })

  return (
    <>
      <div className="mt-10 grid grid-cols-2 gap-10">
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
    </>
  )
}

/** The printable document: header, report, signatures. */
function ReportDocument({ report }: { report: LguReport }) {
  const office =
    report.scope.office === null ? 'Business Permits and Licensing Office' : report.scope.office_name
  const last = report.sections.length - 1

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
        <p className="text-[12px] text-ink-secondary">{reportOffice(report)}</p>
      </div>

      <div className="mt-5">
        {report.sections.map((section, index) => (
          <SectionTable
            key={section.heading}
            section={section}
            closing={index === last ? <ReportClosing report={report} /> : undefined}
          />
        ))}
      </div>

      {/* The screen's copy of the signatures. Print uses the one inside the
          last table (see SectionTable), so this one stays off paper. A report
          with no sections at all has no table to carry it, and prints this. */}
      <div className={last >= 0 ? 'print:hidden' : undefined}>
        <ReportClosing report={report} />
      </div>
    </article>
  )
}

/** The office line a report is headed with. */
function reportOffice(report: LguReport): string {
  return report.scope.office === null ? 'All offices' : report.scope.office_name
}

/** A string as a CSS `content` value: quoted, with quotes and backslashes escaped. */
function cssString(value: string): string {
  return `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, ' ')}"`
}

/*
 * Print: A4, the document only.
 *
 * THE REPORT PRINTS IN THE PAGE'S OWN FLOW. It used to be lifted out with
 * `position: absolute` over an app shell hidden by `visibility`. Hidden is not
 * gone: the invisible shell — title, tabs, the controls card — still took up
 * its height, so the printout's page count was the shell's, not the report's,
 * and a report that nearly filled its last page could print a blank one after
 * it. Now everything that is neither the report nor one of its ancestors is
 * `display: none`, and the ancestors (found with :has) are flattened to plain
 * blocks with no padding, width or offset, so the report starts at the top
 * left of page one as before and the paper ends where the report does.
 *
 * EVERY PAGE SAYS WHAT IT IS. Page two of a three-year collections report used
 * to be a bare table of months: separated from page one, nothing on it said
 * which report, which office or which period it belonged to. The running line
 * and the page number go in the @page MARGIN BOXES, which Chrome and Edge have
 * printed since version 131. They are built from the report on screen, so they
 * change with it. Page one leaves the running line out, because the letterhead
 * under it already says the same thing in full.
 *
 * Firefox and Safari do not print margin boxes yet. There the report still
 * prints correctly — the table headings still repeat on every page — it just
 * loses the running line and the page number. That was preferred to a running
 * row inside every table, which would print the title three times on page one
 * of a three-table report.
 *
 * THE SIGNATURES KEEP COMPANY. The last table's final row group — its last
 * few rows, the total, the note and the signatures — is `break-inside: avoid`
 * (see SectionTable), so the "Prepared by" and "Noted by" lines move to a new
 * page only together with the end of the figures they sign. The three-year
 * BPLO collections report used to end on a page holding the signatures and
 * nothing else.
 */
function printCss(report: LguReport | null): string {
  const running = report
    ? `${report.title} · ${longDate(report.period.from)} to ${longDate(report.period.to)} · ${reportOffice(report)}`
    : ''

  return `
@media print {
  @page {
    size: A4 portrait;
    margin: 16mm 12mm 14mm;
    @top-left {
      content: ${cssString(running)};
      font-family: 'Poppins', 'Segoe UI', system-ui, sans-serif;
      font-size: 8pt;
      color: #3c4350;
      vertical-align: bottom;
      padding-bottom: 3mm;
    }
    @bottom-right {
      content: "Page " counter(page) " of " counter(pages);
      font-family: 'Poppins', 'Segoe UI', system-ui, sans-serif;
      font-size: 8pt;
      color: #3c4350;
      vertical-align: top;
      padding-top: 3mm;
    }
  }
  @page :first {
    @top-left { content: none; }
  }
  body { background: #fff !important; }
  body *:not(#lgu-report):not(#lgu-report *):not(:has(#lgu-report)) { display: none !important; }
  body *:has(#lgu-report) {
    display: block !important;
    position: static !important;
    margin: 0 !important;
    padding: 0 !important;
    width: auto !important;
    min-width: 0 !important;
    max-width: none !important;
    height: auto !important;
    min-height: 0 !important;
    overflow: visible !important;
    border: 0 !important;
    box-shadow: none !important;
    background: none !important;
    transform: none !important;
  }
  #lgu-report thead { display: table-header-group; }
  #lgu-report tr { break-inside: avoid; }
  #lgu-report .lgu-report-section h3 { break-after: avoid; }
  #lgu-report .lgu-report-tail { break-inside: avoid; }
}
`
}

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
      <style>{printCss(report ?? null)}</style>
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
          <AnalyticsError
            error={error}
            onRetry={reload}
            onOwnOffice={office ? () => update({ office: undefined }) : undefined}
          />
        </div>
      ) : report ? (
        <ReportDocument report={report} />
      ) : null}
    </div>
  )
}
