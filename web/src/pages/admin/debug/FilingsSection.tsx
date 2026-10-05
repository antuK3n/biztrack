import { useId, useRef, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { debugFilings } from './api'
import type {
  DebugAdvanceTarget,
  DebugFilingHit,
  DebugFilingStep,
  DebugStepResult,
} from './api'
import { useAsync } from '../../../lib/useAsync'
import { toApiError } from '../../../lib/api'
import { formatDate, formatMoney } from '../../../lib/format'
import { applicationStatusMeta, clearanceStatusMeta } from '../../../lib/status'
import { Alert } from '../../../components/ui/Alert'
import { StatusBadge } from '../../../components/ui/StatusBadge'
import { ErrorState, SkeletonList } from '../../../components/ui/primitives'
import { ProtoCard, inputCls } from '../../../components/ui/Proto'
import { CheckCircleIcon, XCircleIcon } from '../../../components/icons'

/*
 * Move a filing along — the Debug page's second section [Ken, 2026-10-04].
 *
 * For the defense: pick a filing by tracking ID and push it to its next stage
 * without signing in as each office. Every button here is one press of an
 * office's own button, run by the server through the same WorkflowService
 * call (App\Services\Debug\FilingMover), so a step the real flow would refuse
 * is refused here too and the refusal is what the page shows. The server
 * decides which steps are legal and what each is called; this screen draws
 * them and nothing more.
 *
 * What is recorded: the super admin, acting for the office — never the
 * office's own officer, who did not press anything. Every step is audited as
 * `debug.filing.*`, and the applicant is notified as they would be.
 *
 * What it will not do: the applicant's half. A permit the applicant has not
 * applied for is listed under "What is holding it", and "Advance to" stops
 * there rather than inventing their answers.
 */

export function FilingsSection() {
  const [selected, setSelected] = useState<number | null>(null)
  /* Bumped after every step, so the list's status chips do not go stale. */
  const [moves, setMoves] = useState(0)

  return (
    <div className="space-y-5">
      <FindFiling selected={selected} onChoose={setSelected} moves={moves} />
      {/* Keyed, so choosing another filing starts its log and note afresh. */}
      {selected !== null && <MoveFiling key={selected} id={selected} onMoved={() => setMoves((n) => n + 1)} />}
    </div>
  )
}

/* ── Finding the filing ─────────────────────────────────────────────────── */

function FindFiling({
  selected,
  onChoose,
  moves,
}: {
  selected: number | null
  onChoose: (id: number) => void
  moves: number
}) {
  const inputId = useId()
  const hintId = useId()
  const [typed, setTyped] = useState('')
  const [query, setQuery] = useState('')
  const hits = useAsync(() => debugFilings.search(query), [query, moves])

  function find(event: FormEvent) {
    event.preventDefault()
    setQuery(typed.trim())
  }

  return (
    <SubCard title="Find the filing">
      <form onSubmit={find} className="flex flex-wrap items-end gap-3">
        <div className="min-w-0 flex-1 basis-56">
          <label htmlFor={inputId} className="mb-1.5 block text-[13px] font-semibold text-ink">
            Tracking ID
          </label>
          <input
            id={inputId}
            value={typed}
            onChange={(event) => setTyped(event.target.value)}
            aria-describedby={hintId}
            autoComplete="off"
            spellCheck={false}
            className={inputCls}
          />
        </div>
        <button
          type="submit"
          className="rounded-full border-2 border-royal bg-white px-5 py-1.5 text-sm font-semibold text-royal hover:bg-royal-tint"
        >
          Find
        </button>
      </form>
      <p id={hintId} className="mt-1.5 text-xs text-ink-muted">
        Any part of it. Empty, it lists the latest filings.
      </p>

      <div className="mt-4">
        {hits.loading && !hits.data ? (
          <SkeletonList rows={3} />
        ) : hits.error ? (
          <ErrorState error={hits.error} onRetry={hits.reload} />
        ) : hits.data && hits.data.length === 0 ? (
          <p className="text-sm text-ink-secondary">
            {query ? `No submitted filing has “${query}” in its tracking ID.` : 'No submitted filings yet.'}
          </p>
        ) : (
          <ul className="divide-y divide-line rounded-lg border border-line" aria-label="Filings found">
            {(hits.data ?? []).map((hit) => (
              <HitRow key={hit.id} hit={hit} chosen={hit.id === selected} onChoose={() => onChoose(hit.id)} />
            ))}
          </ul>
        )}
      </div>
    </SubCard>
  )
}

function HitRow({ hit, chosen, onChoose }: { hit: DebugFilingHit; chosen: boolean; onChoose: () => void }) {
  const meta = applicationStatusMeta(hit.status, hit.status_label)

  return (
    <li>
      <button
        type="button"
        onClick={onChoose}
        aria-pressed={chosen}
        aria-label={`${hit.tracking_id}, ${hit.business ?? 'business removed'}, ${hit.status_label}`}
        className={`grid w-full gap-1 px-4 py-3 text-left text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-royal sm:grid-cols-[1fr_auto] sm:items-center sm:gap-x-6 ${
          chosen ? 'bg-royal-tint' : 'hover:bg-canvas'
        }`}
      >
        <span className="min-w-0">
          <span className="tnum font-semibold text-ink">{hit.tracking_id}</span>
          <span className="text-ink-muted"> · {hit.type_label ?? '—'}</span>
          <span className="block truncate text-ink-secondary">{hit.business ?? '—'}</span>
        </span>
        <span className="flex items-center gap-2">
          <StatusBadge tone={meta.tone} label={hit.status_label} size="sm" />
          {chosen && <span className="text-xs font-semibold text-royal">Chosen</span>}
        </span>
      </button>
    </li>
  )
}

/* ── Moving it ──────────────────────────────────────────────────────────── */

interface LogEntry {
  id: number
  /** "BPLO accepts the form", or "Advance to Paid". */
  title: string
  results: DebugStepResult[]
  /** Why an advance stopped short; null when it got there or for a single step. */
  stopped: string | null
  /** Set on an advance: whether it reached its stage. */
  reached?: boolean
}

function MoveFiling({ id, onMoved }: { id: number; onMoved: () => void }) {
  const filing = useAsync(() => debugFilings.show(id), [id])
  const noteId = useId()
  const noteHintId = useId()
  const noteRef = useRef<HTMLTextAreaElement>(null)
  const [note, setNote] = useState('')
  const [noteError, setNoteError] = useState<string | null>(null)
  /** What is running, for the busy wording; null when idle. */
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [log, setLog] = useState<LogEntry[]>([])

  function record(entry: Omit<LogEntry, 'id'>) {
    setLog((previous) => [{ ...entry, id: Date.now() + previous.length }, ...previous])
  }

  async function runStep(step: DebugFilingStep) {
    if (busy) return
    const trimmed = note.trim()
    if (step.note === 'required' && trimmed === '') {
      // The office screen's own words for a return with no remarks.
      setNoteError('Explain what the applicant needs to fix.')
      noteRef.current?.focus()
      return
    }
    setBusy(step.label)
    setError(null)
    setNoteError(null)
    try {
      const outcome = await debugFilings.step(id, {
        step: step.key,
        permit: step.permit,
        note: step.note && trimmed !== '' ? trimmed : undefined,
      })
      filing.setData(outcome.filing)
      onMoved()
      record({ title: outcome.result.label, results: [outcome.result], stopped: null })
      if (outcome.result.ok && step.note) setNote('')
    } catch (err) {
      const apiError = toApiError(err)
      if (apiError.errors.note?.[0]) {
        setNoteError(apiError.errors.note[0])
        noteRef.current?.focus()
      } else {
        setError(apiError.message)
      }
    } finally {
      setBusy(null)
    }
  }

  async function advance(to: DebugAdvanceTarget, label: string) {
    if (busy) return
    setBusy(`Advance to ${label}`)
    setError(null)
    try {
      const outcome = await debugFilings.advance(id, to)
      filing.setData(outcome.filing)
      onMoved()
      record({ title: `Advance to ${label}`, results: outcome.results, stopped: outcome.stopped, reached: outcome.reached })
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setBusy(null)
    }
  }

  if (filing.loading && !filing.data) return <SkeletonList rows={4} />
  if (filing.error && !filing.data) return <ErrorState error={filing.error} onRetry={filing.reload} />
  const f = filing.data
  /*
   * Whether the city has FINISHED with it, which `status` alone no longer
   * says: a paid filing stands at `approved` while its other permits come in
   * (Application::isDecided). This read `status === 'approved'` and called
   * every one of those "Completed" (5 October 2026). `decided` is sent by
   * FilingMover::describe; typed here until DebugFiling in ./api carries it.
   */
  if (!f) return null
  const decided = f.decided === true

  const takesNote = f.steps.some((step) => step.note !== null)
  const ahead = f.targets.filter((target) => !target.reached)
  const latest = log[0]

  return (
    <div className="space-y-5">
      <SubCard
        title={f.tracking_id}
        action={<StatusBadge tone={applicationStatusMeta(f.status, f.status_label).tone} label={f.status_label} />}
      >
        <dl className="grid gap-x-4 gap-y-1.5 text-sm sm:grid-cols-[max-content_1fr]">
          <Fact term="Business">{f.business ?? '—'}</Fact>
          <Fact term="Applicant">{f.applicant ?? '—'}</Fact>
          <Fact term="Filing">
            {f.type_label}
            {f.submitted_at ? `, submitted ${formatDate(f.submitted_at)}` : ''}
          </Fact>
          <Fact term="Bill">
            {f.fee.assessed
              ? `${formatMoney(f.fee.total_assessed)} assessed, ${formatMoney(f.fee.total_paid)} paid, ${formatMoney(f.fee.balance_due)} due`
              : '—'}
          </Fact>
        </dl>

        <h4 className="mt-5 text-sm font-semibold text-ink">Permits on this filing</h4>
        <ul className="mt-2 divide-y divide-line rounded-lg border border-line">
          {f.permits.map((permit) => (
            <li key={permit.code} className="grid gap-1.5 px-4 py-3 text-sm sm:grid-cols-[1fr_auto] sm:items-center sm:gap-x-6">
              <div className="min-w-0">
                <p className="font-semibold text-ink">{permit.name}</p>
                <p className="text-ink-secondary">
                  {permit.office ?? '—'}
                  {permit.queue ? ` · queue item ${permit.queue.toLowerCase()}` : ''}
                  {permit.inspection
                    ? ` · inspection ${permit.inspection.label.toLowerCase()}${
                        permit.inspection.scheduled_at ? ` (${formatDate(permit.inspection.scheduled_at)})` : ''
                      }`
                    : ''}
                </p>
                {permit.permit_number && (
                  <p className="tnum text-ink-secondary">Issued {permit.permit_number}</p>
                )}
              </div>
              <div>
                <StatusBadge tone={clearanceStatusMeta(permit.status).tone} label={permit.status_label} size="sm" />
              </div>
            </li>
          ))}
        </ul>

        {f.blockers.length > 0 && (
          <>
            <h4 className="mt-5 text-sm font-semibold text-ink">What is holding it</h4>
            <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-ink-secondary">
              {f.blockers.map((blocker) => (
                <li key={blocker}>{blocker}</li>
              ))}
            </ul>
          </>
        )}
      </SubCard>

      <SubCard title="Next steps">
        {error && (
          <div className="mb-4">
            <Alert variant="error">{error}</Alert>
          </div>
        )}
        {latest && <Outcome entry={latest} />}

        {f.steps.length === 0 && ahead.length === 0 ? (
          <p className="text-sm text-ink-secondary">
            {decided ? 'Completed. There is nothing left to move.' : 'Nothing on this page can move it from here.'}
          </p>
        ) : (
          <>
            {f.steps.length > 0 && <StepButtons steps={f.steps} busy={busy} onRun={runStep} />}

            {takesNote && (
              <div className="relative mt-4 max-w-[42rem]">
                <label htmlFor={noteId} className="mb-1.5 block text-[13px] font-semibold text-ink">
                  Note
                </label>
                <textarea
                  id={noteId}
                  ref={noteRef}
                  rows={2}
                  value={note}
                  onChange={(event) => {
                    setNote(event.target.value)
                    if (noteError) setNoteError(null)
                  }}
                  aria-describedby={noteHintId}
                  aria-invalid={noteError ? true : undefined}
                  className={inputCls}
                />
                <p id={noteHintId} className={`mt-1 text-xs ${noteError ? 'font-semibold text-red-700' : 'text-ink-muted'}`}>
                  {noteError ?? 'Sent to the applicant with a return. Kept as the findings of a failed inspection.'}
                </p>
              </div>
            )}

            {ahead.length > 0 && (
              <div className="mt-5 border-t border-line pt-4">
                <p className="mb-2 text-sm font-semibold text-ink">Advance to</p>
                <div className="flex flex-wrap gap-2">
                  {ahead.map((target) => (
                    <button
                      key={target.to}
                      type="button"
                      aria-label={`Advance to ${target.label}`}
                      aria-disabled={busy !== null || undefined}
                      onClick={() => void advance(target.to, target.label)}
                      className="rounded-full bg-royal px-5 py-1.5 text-sm font-semibold text-white hover:bg-royal-hover aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
                    >
                      {target.label}
                    </button>
                  ))}
                </div>
                <p className="mt-2 max-w-[70ch] text-xs text-ink-muted">
                  Runs the steps above in order, forward only, and stops at the first one refused or the first
                  thing only the applicant can do.
                </p>
              </div>
            )}
          </>
        )}

        {/* Always mounted, so a screen reader hears the sentence when it lands. */}
        <p role="status" className="mt-4 text-sm font-semibold text-ink empty:hidden">
          {busy ? `${busy}…` : ''}
        </p>
        <p className="mt-4 max-w-[70ch] text-xs text-ink-muted">
          Each step is the office&apos;s own button, recorded as you acting for that office. The applicant is
          notified by e-mail, text and in BizTrack, as when the office presses it.
        </p>
      </SubCard>

      {log.length > 1 && (
        <SubCard title="Earlier in this session">
          <ol className="space-y-4">
            {log.slice(1).map((entry) => (
              <li key={entry.id}>
                <Outcome entry={entry} />
              </li>
            ))}
          </ol>
        </SubCard>
      )}
    </div>
  )
}

/*
 * The steps, grouped by the office whose button each one is. Forward steps
 * are filled; a return or a failed inspection is outlined, because it sends
 * the filing back rather than on. Neither is red: nothing here destroys
 * anything (DESIGN.md, Red Means Stop).
 */
function StepButtons({
  steps,
  busy,
  onRun,
}: {
  steps: DebugFilingStep[]
  busy: string | null
  onRun: (step: DebugFilingStep) => void
}) {
  const groups = new Map<string, DebugFilingStep[]>()
  for (const step of steps) {
    const office = step.office ?? 'Payment'
    groups.set(office, [...(groups.get(office) ?? []), step])
  }

  return (
    <div className="space-y-3">
      {[...groups.entries()].map(([office, officeSteps]) => (
        <div key={office} className="grid gap-2 sm:grid-cols-[6rem_1fr] sm:items-start">
          <p className="pt-1.5 text-xs font-semibold uppercase tracking-wide text-ink-muted">{office}</p>
          <div className="flex flex-wrap gap-2">
            {officeSteps.map((step) => (
              <button
                key={`${step.key}:${step.permit ?? ''}`}
                type="button"
                aria-disabled={busy !== null || undefined}
                onClick={() => onRun(step)}
                className={`rounded-full px-4 py-1.5 text-left text-sm font-semibold aria-disabled:cursor-not-allowed aria-disabled:opacity-60 ${
                  step.forward
                    ? 'border-2 border-royal bg-royal-tint text-royal hover:bg-white'
                    : 'border-2 border-line bg-white text-ink hover:border-input-border'
                }`}
              >
                {step.label}
              </button>
            ))}
          </div>
        </div>
      ))}
    </div>
  )
}

/* What one press did: each step, done or refused, and what changed. */
function Outcome({ entry }: { entry: LogEntry }) {
  const single = entry.results.length === 1 && entry.reached === undefined

  return (
    <div className="mb-4 rounded-lg border border-line bg-canvas px-4 py-3 text-sm">
      {!single && (
        <p className="font-semibold text-ink">
          {entry.title}:{' '}
          {entry.reached
            ? entry.results.length === 0
              ? 'already there.'
              : `there, in ${entry.results.length} ${entry.results.length === 1 ? 'step' : 'steps'}.`
            : `stopped after ${entry.results.filter((r) => r.ok).length} ${
                entry.results.filter((r) => r.ok).length === 1 ? 'step' : 'steps'
              }.`}
        </p>
      )}
      <ol className={single ? '' : 'mt-2 space-y-2'}>
        {entry.results.map((result, index) => (
          <li key={index}>
            <p className="flex items-start gap-1.5 font-semibold text-ink">
              {result.ok ? (
                <CheckCircleIcon size={16} className="mt-0.5 shrink-0 text-green-700" aria-hidden="true" />
              ) : (
                <XCircleIcon size={16} className="mt-0.5 shrink-0 text-red-700" aria-hidden="true" />
              )}
              <span>
                {result.label}: {result.ok ? 'done' : 'refused'}
              </span>
            </p>
            {result.ok ? (
              result.changes.length > 0 && (
                <ul className="ml-6 mt-0.5 list-disc pl-4 text-ink-secondary">
                  {result.changes.map((change) => (
                    <li key={change}>{change}</li>
                  ))}
                </ul>
              )
            ) : (
              <p className="ml-6 mt-0.5 text-red-700">{result.refusal}</p>
            )}
          </li>
        ))}
      </ol>
      {entry.stopped && !entry.results.some((r) => !r.ok) && (
        <p className="mt-2 text-ink-secondary">Stopped: {entry.stopped}</p>
      )}
    </div>
  )
}

/* ── Pieces, as PaymentsSection draws them ──────────────────────────────── */

function SubCard({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
  const headingId = useId()
  return (
    <ProtoCard className="rounded-xl p-5">
      <section aria-labelledby={headingId}>
        <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
          <h3 id={headingId} className="text-base font-semibold text-ink">
            {title}
          </h3>
          {action}
        </div>
        {children}
      </section>
    </ProtoCard>
  )
}

function Fact({ term, children }: { term: string; children: ReactNode }) {
  return (
    <>
      <dt className="text-ink-muted">{term}</dt>
      <dd className="min-w-0 text-ink">{children}</dd>
    </>
  )
}
