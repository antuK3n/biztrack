import { useEffect, useId, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { Skeleton } from '../../components/ui/primitives'
import {
  FieldLabel,
  FilterPills,
  OptionalTag,
  ProtoCard,
  ProtoModal,
  inputCls,
} from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import {
  customReports,
  type CustomDefinition,
  type CustomExportFormat,
  type CustomReportResult,
  type CustomSubject,
  type CustomSubjectSpec,
  type SavedCustomReport,
} from '../../lib/customReports'
import type { ReportColumn, ReportRow } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'
import { AnalyticsError } from './AnalyticsError'
import { OfficeScope } from './OfficeScope'

/*
 * Custom Report (client, 19 October 2026: "the admin can select specific
 * information like the total number of male employees, total number of
 * specific line of businesses, how many registered in specific barangays").
 *
 * ONE SCREEN, FOUR STEPS, READ TOP TO BOTTOM LIKE A SENTENCE: count what →
 * show these numbers → only include… → break it down by. The result is drawn
 * underneath when the reader presses Generate, and downloads as Excel, CSV or
 * PDF — the server's rendering of the same figures (CustomReportController).
 *
 * Every choice and every label comes from the server's catalogue
 * (App\Support\CustomReports), so the screen never names a column and cannot
 * offer something the server would refuse. What the server still refuses (a
 * saved set-up gone stale) comes back as one short line under Generate.
 *
 * Starters fill the four steps in one click and run them; saved set-ups are
 * the reader's own (per user — see the saved_reports migration).
 *
 * Generate is pressed, not live: the result is a document a clerk may export,
 * and a table that redrew under every tick would export whatever half-chosen
 * state it was in. When the choices change after a run, a line says the table
 * is out of date instead of silently showing old figures under new choices.
 */

const EMPTY: CustomDefinition = {
  subject: 'businesses',
  measures: ['count'],
  filters: {},
  period: null,
  breakdown: [],
}

/** A dash, never a zero, where a figure has no value (AGENTS.md §6.4). */
function formatCell(value: string | number | null | undefined, format: ReportColumn['format']): string {
  if (value === null || value === undefined || value === '') return format === 'text' ? '' : '—'
  if (typeof value === 'string') return value
  switch (format) {
    case 'money':
    case 'decimal':
      return value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    default:
      return value.toLocaleString('en-PH')
  }
}

/** The server's first validation line, or its message. */
function errorLine(error: unknown): string {
  const apiError = toApiError(error)
  const first = Object.values(apiError.errors ?? {})[0]
  return (Array.isArray(first) ? first[0] : undefined) ?? apiError.message
}

/** Keep only what the subject offers; used when the subject or catalogue changes. */
function fitTo(definition: CustomDefinition, spec: CustomSubjectSpec | undefined): CustomDefinition {
  if (!spec) return definition
  const measures = definition.measures.filter((m) => spec.measures.some((x) => x.key === m))
  const filters: Record<string, string[]> = {}
  for (const [key, values] of Object.entries(definition.filters)) {
    const filter = spec.filters.find((f) => f.key === key)
    if (!filter) continue
    const kept = values.filter((v) => filter.options.some((o) => o.value === v))
    if (kept.length > 0) filters[key] = kept
  }
  return {
    subject: spec.key,
    measures: measures.length > 0 ? measures : [spec.measures[0].key],
    filters,
    period: spec.date_label ? definition.period : null,
    breakdown: definition.breakdown.filter((b) => spec.breakdowns.some((x) => x.key === b)),
  }
}

function same(a: CustomDefinition, b: CustomDefinition): boolean {
  return JSON.stringify(a) === JSON.stringify(b)
}

/** Step heading: a number and a plain question. */
function Step({ n, title, optional, children }: { n: number; title: string; optional?: boolean; children: ReactNode }) {
  const id = useId()
  return (
    <section aria-labelledby={id} className="border-t border-line pt-4 first:border-t-0 first:pt-0">
      <h3 id={id} className="mb-2.5 text-[15px] font-bold text-ink">
        <span className="tnum mr-1.5 text-royal">{n}.</span>
        {title}
        {optional && <OptionalTag />}
      </h3>
      {children}
    </section>
  )
}

/**
 * One filter's choices: a checkbox list, searchable when it is long (the
 * PSIC list runs to well over a hundred lines).
 */
function FilterEditor({
  filter,
  selected,
  onDone,
  onCancel,
}: {
  filter: CustomSubjectSpec['filters'][number]
  selected: string[]
  onDone: (values: string[]) => void
  onCancel: () => void
}) {
  const [values, setValues] = useState<string[]>(selected)
  const [query, setQuery] = useState('')
  const searchable = filter.options.length > 8
  const shown = filter.options.filter((o) => o.label.toLowerCase().includes(query.trim().toLowerCase()))
  const searchId = useId()

  function toggle(value: string) {
    setValues((current) => (current.includes(value) ? current.filter((v) => v !== value) : [...current, value]))
  }

  return (
    <div role="group" aria-label={`${filter.label} choices`} className="mt-3 rounded-xl border border-line bg-canvas/40 p-3">
      <p className="mb-2 text-[13px] font-semibold text-ink">{filter.label}</p>
      {searchable && (
        <>
          <label htmlFor={searchId} className="sr-only">
            Search {filter.label.toLowerCase()}
          </label>
          <input
            id={searchId}
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Type to narrow the list"
            className={`${inputCls} mb-2`}
          />
        </>
      )}
      {filter.options.length === 0 ? (
        <p className="text-[13px] text-ink-secondary">Nothing on record to choose from.</p>
      ) : (
        <div className="max-h-56 overflow-y-auto pr-1">
          <ul className="grid gap-x-4 gap-y-1 sm:grid-cols-2">
            {shown.map((option) => (
              <li key={option.value}>
                <label className="flex cursor-pointer items-start gap-2 py-0.5 text-[13px] text-ink">
                  <input
                    type="checkbox"
                    className="mt-0.5 h-4 w-4 shrink-0 accent-royal"
                    checked={values.includes(option.value)}
                    onChange={() => toggle(option.value)}
                  />
                  <span className="min-w-0 break-words">{option.label}</span>
                </label>
              </li>
            ))}
          </ul>
          {shown.length === 0 && <p className="text-[13px] text-ink-secondary">No match.</p>}
        </div>
      )}
      <div className="mt-3 flex flex-wrap items-center gap-2">
        <button
          type="button"
          onClick={() => {
            if (values.length > 0) onDone(values)
          }}
          aria-disabled={values.length === 0 || undefined}
          className="rounded-lg bg-royal px-4 py-2 text-sm font-semibold text-white hover:bg-royal-hover aria-disabled:opacity-60"
        >
          Apply
        </button>
        <button type="button" onClick={onCancel} className="rounded-lg px-3 py-2 text-sm font-semibold text-royal hover:bg-royal-tint">
          Cancel
        </button>
        {values.length === 0 && <span className="text-xs text-ink-secondary">Tick at least one.</span>}
      </div>
    </div>
  )
}

function ResultTable({ result }: { result: CustomReportResult }) {
  const numeric = (c: ReportColumn) => c.format !== 'text'
  const total = result.total

  return (
    <div className="overflow-x-auto rounded-lg border border-line">
      <table className="w-full min-w-max border-collapse text-[13px]">
        <thead>
          <tr className="bg-[#eef1f8]">
            {result.columns.map((c) => (
              <th
                key={c.key}
                scope="col"
                className={`whitespace-nowrap border-b border-line px-3 py-2 font-semibold text-ink ${numeric(c) ? 'text-right' : 'text-left'}`}
              >
                {c.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {result.rows.map((row: ReportRow, index) => (
            <tr key={index} className="border-b border-line/70 last:border-b-0">
              {result.columns.map((c, i) =>
                i === 0 ? (
                  <th key={c.key} scope="row" className="max-w-[22rem] px-3 py-1.5 text-left font-normal text-ink">
                    {formatCell(row[c.key], c.format)}
                  </th>
                ) : (
                  <td key={c.key} className={`tnum px-3 py-1.5 text-ink ${numeric(c) ? 'text-right' : 'text-left'}`}>
                    {formatCell(row[c.key], c.format)}
                  </td>
                ),
              )}
            </tr>
          ))}
          <tr className="border-t-2 border-ink/40 bg-canvas/50">
            {result.columns.map((c, i) =>
              i === 0 ? (
                <th key={c.key} scope="row" className="px-3 py-2 text-left font-bold text-ink">
                  Total
                </th>
              ) : (
                <td key={c.key} className={`tnum px-3 py-2 font-bold text-ink ${numeric(c) ? 'text-right' : 'text-left'}`}>
                  {formatCell(total[c.key], c.format)}
                </td>
              ),
            )}
          </tr>
        </tbody>
      </table>
    </div>
  )
}

type Dialog =
  | { kind: 'save' }
  | { kind: 'rename'; saved: SavedCustomReport }
  | { kind: 'delete'; saved: SavedCustomReport }
  | null

/**
 * The builder, drawn as the second part of the Reports screen ("Build your
 * own report", ReportsPage) since 19 October 2026 — client: "what's the
 * difference between Report and Custom Report. I do think it is best to just
 * merge them." It was its own page with its own title and tab strip; both went
 * to the Reports screen around it, and its headings dropped a level (h3) to
 * sit under that section's h2. Its office and choices stay component state, so
 * nothing here can collide with the standard reports' `?report=`, `?from=`,
 * `?to=`, `?office=` or with Home's `?tab=`/`?view=`.
 */
export function CustomReportBuilder() {
  const [office, setOffice] = useState<string | undefined>(undefined)
  const { data: catalogueBody, loading, error, reload } = useAsync(() => customReports.catalogue(office), [office])
  const catalogue = catalogueBody?.data ?? null
  const scope = catalogueBody?.scope ?? null

  const savedList = useAsync(() => customReports.saved(), [])
  const saved = savedList.data ?? []

  const [definition, setDefinition] = useState<CustomDefinition>(EMPTY)
  const [ran, setRan] = useState<CustomDefinition | null>(null)
  const [result, setResult] = useState<CustomReportResult | null>(null)
  const [running, setRunning] = useState(false)
  const [runError, setRunError] = useState<string | null>(null)
  const [exporting, setExporting] = useState<CustomExportFormat | null>(null)
  const [exportError, setExportError] = useState<string | null>(null)
  const [loadedSaved, setLoadedSaved] = useState<SavedCustomReport | null>(null)
  const [editingFilter, setEditingFilter] = useState<string | null>(null)
  const [addingFilter, setAddingFilter] = useState(false)
  const [dialog, setDialog] = useState<Dialog>(null)
  const [dialogName, setDialogName] = useState('')
  const [dialogError, setDialogError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const resultRef = useRef<HTMLDivElement | null>(null)

  const spec = useMemo(
    () => catalogue?.subjects.find((s) => s.key === definition.subject),
    [catalogue, definition.subject],
  )

  // A new catalogue (another office) may offer fewer choices: keep what fits.
  useEffect(() => {
    if (!catalogue) return
    setDefinition((d) => fitTo(d, catalogue.subjects.find((s) => s.key === d.subject)))
    setResult(null)
    setRan(null)
  }, [catalogue])

  const stale = result !== null && ran !== null && !same(ran, definition)

  async function run(next: CustomDefinition = definition) {
    if (next.measures.length === 0) {
      setRunError('Tick at least one number to show.')
      return
    }
    setRunning(true)
    setRunError(null)
    setExportError(null)
    try {
      const answer = await customReports.run(next, office)
      setResult(answer)
      setRan(next)
      // Bring the table into view on a phone, where it is below the steps.
      window.requestAnimationFrame(() => resultRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
    } catch (err) {
      setRunError(errorLine(err))
    } finally {
      setRunning(false)
    }
  }

  function load(next: CustomDefinition, from: SavedCustomReport | null) {
    const fitted = fitTo(next, catalogue?.subjects.find((s) => s.key === next.subject))
    setDefinition(fitted)
    setLoadedSaved(from)
    setEditingFilter(null)
    setAddingFilter(false)
    setNotice(null)
    void run(fitted)
  }

  function update(change: Partial<CustomDefinition>) {
    setDefinition((d) => ({ ...d, ...change }))
    setRunError(null)
  }

  function chooseSubject(subject: CustomSubject) {
    const next = catalogue?.subjects.find((s) => s.key === subject)
    setDefinition((d) => fitTo({ ...d, subject, measures: d.subject === subject ? d.measures : [] }, next))
    setEditingFilter(null)
    setAddingFilter(false)
    setRunError(null)
  }

  function toggleMeasure(key: string) {
    const has = definition.measures.includes(key)
    if (!has && catalogue && definition.measures.length >= catalogue.limits.measures) {
      setRunError(`Show up to ${catalogue.limits.measures} numbers at a time.`)
      return
    }
    update({ measures: has ? definition.measures.filter((m) => m !== key) : [...definition.measures, key] })
  }

  function setFilter(key: string, values: string[] | null) {
    const filters = { ...definition.filters }
    if (values === null) delete filters[key]
    else filters[key] = values
    update({ filters })
    setEditingFilter(null)
    setAddingFilter(false)
  }

  function setPeriod(end: 'from' | 'to', value: string) {
    const period = { from: definition.period?.from ?? null, to: definition.period?.to ?? null, [end]: value || null }
    update({ period: period.from || period.to ? period : null })
  }

  function setBreakdown(level: 0 | 1, value: string) {
    const next = [...definition.breakdown]
    if (value === '') next.splice(level)
    else next[level] = value
    update({ breakdown: next.filter(Boolean).slice(0, 2) })
  }

  async function download(format: CustomExportFormat) {
    if (!ran || exporting || stale) return
    setExporting(format)
    setExportError(null)
    try {
      await customReports.download(format, ran, result?.scope.office ?? null, office)
    } catch (err) {
      setExportError(errorLine(err))
    } finally {
      setExporting(null)
    }
  }

  function openDialog(next: Dialog) {
    setDialog(next)
    setDialogError(null)
    setDialogName(next && next.kind === 'rename' ? next.saved.name : '')
  }

  async function confirmDialog() {
    if (!dialog) return
    setDialogError(null)
    try {
      if (dialog.kind === 'save') {
        const created = await customReports.save(dialogName, definition)
        setLoadedSaved(created)
        setNotice(`Saved as “${created.name}”.`)
      } else if (dialog.kind === 'rename') {
        const renamed = await customReports.update(dialog.saved.id, { name: dialogName })
        if (loadedSaved?.id === renamed.id) setLoadedSaved(renamed)
        setNotice(`Renamed to “${renamed.name}”.`)
      } else {
        await customReports.remove(dialog.saved.id)
        if (loadedSaved?.id === dialog.saved.id) setLoadedSaved(null)
        setNotice(`Deleted “${dialog.saved.name}”.`)
      }
      setDialog(null)
      savedList.reload()
    } catch (err) {
      setDialogError(errorLine(err))
    }
  }

  async function saveChanges() {
    if (!loadedSaved) return
    try {
      const updated = await customReports.update(loadedSaved.id, { definition })
      setLoadedSaved(updated)
      setNotice(`Saved changes to “${updated.name}”.`)
      savedList.reload()
    } catch (err) {
      setNotice(null)
      setRunError(errorLine(err))
    }
  }

  const unusedFilters = spec?.filters.filter((f) => !(f.key in definition.filters)) ?? []
  const savedChanged = loadedSaved !== null && !same(fitTo(loadedSaved.definition, spec), definition)

  return (
    <div>
      {loading && !catalogue ? (
        <ProtoCard className="space-y-3 px-5 py-5">
          <Skeleton className="h-6 w-1/3" />
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-24 w-full" />
        </ProtoCard>
      ) : error ? (
        <AnalyticsError error={error} onRetry={reload} onOwnOffice={office ? () => setOffice(undefined) : undefined} />
      ) : catalogue && spec ? (
        <>
          {/* Starting points: one click fills the steps and runs them. */}
          <ProtoCard className="mb-5 px-4 py-4 sm:px-5">
            <h3 className="mb-2 text-[13px] font-semibold text-ink-secondary">Start from a common report</h3>
            <div className="flex flex-wrap gap-2" role="group" aria-label="Common reports">
              {catalogue.starters.map((starter) => (
                <button
                  key={starter.key}
                  type="button"
                  onClick={() => load(starter.definition, null)}
                  className="rounded-full border border-line bg-white px-3.5 py-1 text-[12.5px] font-semibold text-ink-secondary transition-colors hover:border-royal hover:text-royal"
                >
                  {starter.title}
                </button>
              ))}
            </div>

            <h3 className="mb-2 mt-4 text-[13px] font-semibold text-ink-secondary">Your saved reports</h3>
            {savedList.loading && saved.length === 0 ? (
              <Skeleton className="h-7 w-1/2" />
            ) : saved.length === 0 ? (
              <p className="text-[13px] text-ink-muted">None yet. Set up a report below and press Save.</p>
            ) : (
              <ul className="flex flex-wrap gap-2" aria-label="Your saved reports">
                {saved.map((s) => {
                  const active = loadedSaved?.id === s.id
                  return (
                    <li
                      key={s.id}
                      className={`flex items-center overflow-hidden rounded-full border text-[12.5px] font-semibold ${
                        active ? 'border-royal bg-royal-tint text-royal' : 'border-line bg-white text-ink-secondary'
                      }`}
                    >
                      <button
                        type="button"
                        onClick={() => load(s.definition, s)}
                        aria-pressed={active}
                        className="max-w-[14rem] truncate py-1 pl-3.5 pr-2 hover:text-royal"
                      >
                        {s.name}
                      </button>
                      <button
                        type="button"
                        onClick={() => openDialog({ kind: 'rename', saved: s })}
                        aria-label={`Rename ${s.name}`}
                        className="px-1.5 py-1 text-ink-muted hover:text-royal"
                      >
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                          <path d="M4 20h4L19 9l-4-4L4 16v4z" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
                        </svg>
                      </button>
                      <button
                        type="button"
                        onClick={() => openDialog({ kind: 'delete', saved: s })}
                        aria-label={`Delete ${s.name}`}
                        className="py-1 pl-1 pr-2.5 text-ink-muted hover:text-s-red"
                      >
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                          <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                        </svg>
                      </button>
                    </li>
                  )
                })}
              </ul>
            )}
          </ProtoCard>

          <ProtoCard className="mb-5 space-y-4 px-4 py-5 sm:px-5">
            <Step n={1} title="Count what?">
              <FilterPills
                options={catalogue.subjects.map((s) => ({ value: s.key, label: s.label }))}
                value={definition.subject}
                onChange={chooseSubject}
              />
            </Step>

            <Step n={2} title="Show these numbers">
              <fieldset>
                <legend className="sr-only">Numbers to show</legend>
                <div className="grid gap-x-5 gap-y-1.5 sm:grid-cols-2 lg:grid-cols-3">
                  {spec.measures.map((m) => (
                    <label key={m.key} className="flex cursor-pointer items-start gap-2 text-[13.5px] text-ink">
                      <input
                        type="checkbox"
                        className="mt-0.5 h-4 w-4 shrink-0 accent-royal"
                        checked={definition.measures.includes(m.key)}
                        onChange={() => toggleMeasure(m.key)}
                      />
                      <span>{m.label}</span>
                    </label>
                  ))}
                </div>
              </fieldset>
            </Step>

            <Step n={3} title="Only include…" optional>
              {spec.date_label && (
                <div className="mb-3 grid max-w-md grid-cols-2 gap-3">
                  <label className="block">
                    <FieldLabel>{spec.date_label} from</FieldLabel>
                    <input
                      type="date"
                      className={inputCls}
                      value={definition.period?.from ?? ''}
                      max={definition.period?.to ?? undefined}
                      onChange={(e) => setPeriod('from', e.target.value)}
                    />
                  </label>
                  <label className="block">
                    <FieldLabel>{spec.date_label} to</FieldLabel>
                    <input
                      type="date"
                      className={inputCls}
                      value={definition.period?.to ?? ''}
                      min={definition.period?.from ?? undefined}
                      onChange={(e) => setPeriod('to', e.target.value)}
                    />
                  </label>
                </div>
              )}

              <ul className="flex flex-wrap gap-2" aria-label="Filters in use">
                {Object.entries(definition.filters).map(([key, values]) => {
                  const filter = spec.filters.find((f) => f.key === key)
                  if (!filter) return null
                  const names = values.map((v) => filter.options.find((o) => o.value === v)?.label ?? v)
                  const summary = names.length > 2 ? `${names.slice(0, 2).join(', ')} +${names.length - 2}` : names.join(', ')
                  return (
                    <li
                      key={key}
                      className="flex max-w-full items-center overflow-hidden rounded-full border border-royal bg-royal-tint text-[12.5px] text-royal"
                    >
                      <button
                        type="button"
                        onClick={() => {
                          setAddingFilter(false)
                          setEditingFilter(editingFilter === key ? null : key)
                        }}
                        aria-expanded={editingFilter === key}
                        aria-label={`Change ${filter.label} filter: ${names.join(', ')}`}
                        className="min-w-0 truncate py-1 pl-3.5 pr-2 text-left"
                      >
                        <span className="font-semibold">{filter.label}:</span> {summary}
                      </button>
                      <button
                        type="button"
                        onClick={() => setFilter(key, null)}
                        aria-label={`Remove ${filter.label} filter`}
                        className="py-1 pl-1 pr-2.5 hover:text-s-red"
                      >
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                          <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                        </svg>
                      </button>
                    </li>
                  )
                })}
                {unusedFilters.length > 0 && (
                  <li>
                    <button
                      type="button"
                      onClick={() => {
                        setEditingFilter(null)
                        setAddingFilter(!addingFilter)
                      }}
                      aria-expanded={addingFilter}
                      className="rounded-full border border-dashed border-royal px-3.5 py-1 text-[12.5px] font-semibold text-royal hover:bg-royal-tint"
                    >
                      + Add filter
                    </button>
                  </li>
                )}
              </ul>

              {addingFilter && (
                <div className="mt-3 max-w-sm">
                  <label htmlFor="custom-filter-field">
                    <FieldLabel>Filter on</FieldLabel>
                  </label>
                  <select
                    id="custom-filter-field"
                    className={inputCls}
                    value=""
                    onChange={(e) => {
                      if (!e.target.value) return
                      setAddingFilter(false)
                      setEditingFilter(e.target.value)
                    }}
                  >
                    <option value="">Choose…</option>
                    {unusedFilters.map((f) => (
                      <option key={f.key} value={f.key}>
                        {f.label}
                      </option>
                    ))}
                  </select>
                </div>
              )}

              {editingFilter &&
                (() => {
                  const filter = spec.filters.find((f) => f.key === editingFilter)
                  return filter ? (
                    <FilterEditor
                      key={filter.key}
                      filter={filter}
                      selected={definition.filters[filter.key] ?? []}
                      onDone={(values) => setFilter(filter.key, values)}
                      onCancel={() => setEditingFilter(null)}
                    />
                  ) : null
                })()}
            </Step>

            <Step n={4} title="Break it down by" optional>
              <div className="grid max-w-xl gap-3 sm:grid-cols-2">
                <label className="block">
                  <FieldLabel>First</FieldLabel>
                  <select
                    className={inputCls}
                    value={definition.breakdown[0] ?? ''}
                    onChange={(e) => setBreakdown(0, e.target.value)}
                  >
                    <option value="">No breakdown (totals only)</option>
                    {spec.breakdowns.map((b) => (
                      <option key={b.key} value={b.key}>
                        {b.label}
                      </option>
                    ))}
                  </select>
                </label>
                {definition.breakdown[0] && (
                  <label className="block">
                    <FieldLabel>Then by</FieldLabel>
                    <select
                      className={inputCls}
                      value={definition.breakdown[1] ?? ''}
                      onChange={(e) => setBreakdown(1, e.target.value)}
                    >
                      <option value="">Nothing more</option>
                      {spec.breakdowns
                        .filter((b) => b.key !== definition.breakdown[0])
                        .map((b) => (
                          <option key={b.key} value={b.key}>
                            {b.label}
                          </option>
                        ))}
                    </select>
                  </label>
                )}
              </div>
            </Step>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
              {scope ? (
                <OfficeScope scope={scope} onChange={(value) => setOffice(value)} id="custom-report-office" />
              ) : (
                <span />
              )}
              <div className="flex flex-wrap items-center gap-2">
                {loadedSaved && savedChanged && (
                  <button
                    type="button"
                    onClick={() => void saveChanges()}
                    className="rounded-lg px-3 py-2.5 text-sm font-semibold text-royal hover:bg-royal-tint"
                  >
                    Save changes to “{loadedSaved.name}”
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => openDialog({ kind: 'save' })}
                  className="rounded-lg border border-royal px-5 py-2.5 text-sm font-semibold text-royal hover:bg-royal-tint"
                >
                  {loadedSaved ? 'Save as new…' : 'Save…'}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    if (!running) void run()
                  }}
                  aria-busy={running}
                  aria-describedby={runError ? 'custom-report-error' : undefined}
                  className="rounded-lg bg-royal px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:bg-royal-hover aria-busy:opacity-70"
                >
                  {running ? 'Generating…' : 'Generate'}
                </button>
              </div>
            </div>
            {runError && (
              <p id="custom-report-error" role="alert" className="text-right text-[13px] font-medium text-s-red">
                {runError}
              </p>
            )}
            {notice && (
              <p role="status" className="text-right text-[13px] text-s-green">
                {notice}
              </p>
            )}
          </ProtoCard>

          <div ref={resultRef} className="scroll-mt-4">
            {result ? (
              <ProtoCard className="px-4 py-5 sm:px-5">
                <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h3 className="text-[16px] font-bold text-ink">{result.title}</h3>
                    <p className="mt-0.5 text-[12.5px] text-ink-secondary">
                      {result.scope.office === null ? 'All offices' : result.scope.office_name}
                      {result.period &&
                        ` · ${result.period.from ?? 'Earliest on record'} to ${result.period.to ?? 'today'}`}
                    </p>
                    {result.filters_text.length > 0 && (
                      <p className="mt-0.5 text-[12.5px] text-ink-secondary">Only {result.filters_text.join(' · ')}</p>
                    )}
                  </div>
                  <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Download this report">
                    {(
                      [
                        ['xlsx', 'Excel'],
                        ['csv', 'CSV'],
                        ['pdf', 'PDF'],
                      ] as const
                    ).map(([format, label]) => (
                      <button
                        key={format}
                        type="button"
                        onClick={() => void download(format)}
                        aria-disabled={stale || exporting !== null || undefined}
                        aria-describedby={stale ? 'custom-report-stale' : undefined}
                        aria-label={`Download ${label}`}
                        aria-busy={exporting === format}
                        className="rounded-lg border border-royal px-3.5 py-2 text-sm font-semibold text-royal hover:bg-royal-tint aria-disabled:opacity-60"
                      >
                        {exporting === format ? 'Preparing…' : label}
                      </button>
                    ))}
                  </div>
                </div>

                {stale && (
                  <p id="custom-report-stale" role="status" className="mb-3 rounded-lg bg-s-yellow-tint px-3 py-2 text-[13px] text-amber-800">
                    The choices above have changed. Press Generate to update this table.
                  </p>
                )}
                {exportError && (
                  <p role="alert" className="mb-3 text-[13px] font-medium text-s-red">
                    {exportError}
                  </p>
                )}

                {result.empty ? (
                  <p className="rounded-lg bg-canvas px-4 py-6 text-center text-[14px] text-ink-secondary">{result.empty}</p>
                ) : (
                  <ResultTable result={result} />
                )}

                {result.truncated > 0 && (
                  <p className="mt-2 text-[12.5px] text-ink-secondary">
                    Showing the first {result.rows.length} rows; {result.truncated} more are not listed. The total counts them
                    all.
                  </p>
                )}
                {result.notes.length > 0 && (
                  <ul className="mt-3 list-disc space-y-0.5 pl-5 text-[11.5px] leading-snug text-ink-secondary">
                    {result.notes.map((note) => (
                      <li key={note}>{note}</li>
                    ))}
                  </ul>
                )}
              </ProtoCard>
            ) : (
              <p className="px-1 text-[13px] text-ink-muted">
                Choose a common report above, or set up the four steps and press Generate.
              </p>
            )}
          </div>

          {dialog && (
            <ProtoModal
              title={dialog.kind === 'save' ? 'Save this report' : dialog.kind === 'rename' ? 'Rename report' : 'Delete report?'}
              tone={dialog.kind === 'delete' ? 'red' : 'blue'}
              confirmLabel={dialog.kind === 'save' ? 'Save' : dialog.kind === 'rename' ? 'Rename' : 'Delete'}
              onCancel={() => setDialog(null)}
              onConfirm={() => void confirmDialog()}
              confirmDisabled={dialog.kind !== 'delete' && dialogName.trim() === ''}
              confirmDescribedBy={dialogError ? 'custom-report-dialog-error' : undefined}
            >
              {dialog.kind === 'delete' ? (
                <p className="text-[14px]">
                  “{dialog.saved.name}” will be removed from your saved reports. The figures it showed are not affected.
                </p>
              ) : (
                <label className="relative block">
                  <FieldLabel required>Name</FieldLabel>
                  <input
                    className={inputCls}
                    value={dialogName}
                    maxLength={80}
                    autoFocus
                    onChange={(e) => setDialogName(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' && dialogName.trim() !== '') void confirmDialog()
                    }}
                  />
                </label>
              )}
              {dialogError && (
                <p id="custom-report-dialog-error" role="alert" className="mt-2 text-[13px] font-medium text-s-red">
                  {dialogError}
                </p>
              )}
              {dialog.kind === 'save' && (
                <p className="mt-2 text-[12.5px] text-ink-secondary">Only you can see your saved reports.</p>
              )}
            </ProtoModal>
          )}
        </>
      ) : null}
    </div>
  )
}
