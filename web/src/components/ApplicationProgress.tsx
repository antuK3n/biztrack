import { CheckIcon, DotIcon, XCircleIcon } from './icons'
import { StatusBadge } from './ui/StatusBadge'
import { formatDateTime } from '../lib/format'
import { TONE_CLASSES, applicationStatusMeta, genericStatusTone } from '../lib/status'
import type { Application, ApplicationStatus, Assignment, TimelineEntry } from '../lib/types'

/*
 * "Where is this filing in the process?" — answered on the officer review sheet.
 *
 * The chip in the header says what the filing is; it has never said how far
 * along it is, so an admin had to know the workflow by heart to place a status
 * in it. Meanwhile WorkflowService has recorded every transition since the
 * system was built — 8,628 rows — and no staff screen read one of them.
 *
 * Two halves, because they answer different questions and one cannot stand in
 * for the other:
 *
 *  - The RAIL is the fixed sequence the LGU describes: Pending Payment → For
 *    Approval → For Inspection → Approved. It says where the filing sits and
 *    what is still ahead of it.
 *  - The LOG is what actually happened to this filing — every transition, when,
 *    by whom, and the note the officer left. The rail alone would flatten a
 *    filing that was returned twice into one that sailed through.
 *
 * The rail is drawn from this filing's own facts, never from the shape of a
 * typical one. Three ways a real filing departs from the straight line, all
 * handled here rather than papered over:
 *
 *  1. INSPECTION IS OFTEN SKIPPED. WorkflowService::afterReviewProgress sends
 *     the last office approval straight to approveAndIssue() when no chosen
 *     permit type has `requires_inspection`. Drawing a greyed-out For Inspection
 *     step on those filings would promise a stage that will never arrive, so the
 *     step is not drawn at all — the client's words were "for those permits that
 *     actually has inspection".
 *  2. RETURNED IS A LOOP, NOT A STAGE. It goes back to the applicant from For
 *     Approval and comes back into For Approval. As a fifth box in the line it
 *     would read as progress, which is the opposite of what it is, so it is an
 *     annotation on the For Approval step.
 *  3. REJECTED ENDS THE LINE. The remaining steps are not "not yet" — they will
 *     never happen. The rail stops where the filing stopped.
 *
 * Accessibility: an ordered list of real steps, the current one carrying
 * `aria-current="step"`. Every node states its state in words ("Completed",
 * "Current stage", "Not started") next to its label, and each state has its own
 * glyph — a tick, the status icon, a hollow dot. Colour is the third carrier
 * here, never the first, per DESIGN.md's Never Color Alone rule. Nothing is
 * `disabled`; a step that has not happened is text, not a dead control.
 */

/** Where a step sits relative to the filing's present position. */
type StepState = 'done' | 'current' | 'upcoming'

interface RailStep {
  /** The enum value this node stands for; also its React key. */
  status: ApplicationStatus
  state: StepState
  /** Shown under the label — the loop and stop-reason annotations. */
  note?: string
}

/**
 * The sequence, for this filing.
 *
 * For Inspection appears only when an inspection is genuinely coming. The
 * permit-type flag is the same one `afterReviewProgress` branches on, so the
 * rail and the state machine cannot disagree about it. The two fallbacks matter
 * for old filings: a permit type's flag can be turned off after a filing has
 * already been routed for inspection, and the filing's own inspections — or its
 * own status — are then the better evidence than today's configuration.
 */
function railFor(app: Application): ApplicationStatus[] {
  const inspects =
    app.permit_types.some((pt) => pt.requires_inspection) ||
    (app.inspections?.length ?? 0) > 0 ||
    app.status === 'for_inspection'

  return [
    'pending_payment',
    'under_review',
    ...(inspects ? (['for_inspection'] as ApplicationStatus[]) : []),
    'approved',
  ]
}

/**
 * Which rail step the filing is standing on.
 *
 * `draft` and `submitted` collapse onto Pending Payment: submission assesses the
 * fee and moves straight on, so they are moments inside that step rather than
 * stages of their own. `returned` collapses onto For Approval because that is
 * where it will resume. `issued` is the web's own name for an approved filing
 * whose permits are out — the same end of the same rail.
 */
function positionOf(status: ApplicationStatus, rail: ApplicationStatus[]): number {
  const target: Partial<Record<ApplicationStatus, ApplicationStatus>> = {
    draft: 'pending_payment',
    submitted: 'pending_payment',
    pending_payment: 'pending_payment',
    under_review: 'under_review',
    returned: 'under_review',
    for_inspection: 'for_inspection',
    approved: 'approved',
    issued: 'approved',
  }
  const mapped = target[status]

  return mapped ? rail.indexOf(mapped) : -1
}

/**
 * The stage a terminal filing was stopped at, read off the transition that
 * ended it.
 *
 * `from_status` on the rejection row is the only record of where a reviewer was
 * standing when they rejected, and it is worth surfacing: "Rejected" alone
 * leaves an admin unable to tell a filing refused at review from one that failed
 * an inspection after every office had signed off. Falls back to For Approval,
 * which is where rejection is possible from.
 */
function stoppedAt(history: TimelineEntry[], status: ApplicationStatus, rail: ApplicationStatus[]): number {
  const ending = [...history].reverse().find((h) => h.to_status === status)
  const from = ending?.from_status ? positionOf(ending.from_status, rail) : -1

  return from >= 0 ? from : rail.indexOf('under_review')
}

/** The rail as nodes, with the returns and the terminal stop written onto it. */
function buildSteps(app: Application): { steps: RailStep[]; terminal: ApplicationStatus | null } {
  const rail = railFor(app)
  const history = app.status_history ?? []
  const status = app.status
  const isTerminal = status === 'rejected' || status === 'cancelled'

  /*
   * How many times this filing has been sent back. Counted from history rather
   * than inferred from the current status, because the interesting case is the
   * filing that was returned twice and is now moving again — the status has
   * forgotten that and the admin asking why it is late has not.
   */
  const returns = history.filter((h) => h.to_status === 'returned').length
  const returnNote =
    status === 'returned'
      ? 'Sent back to the applicant — it re-enters this stage when they resubmit'
      : returns > 0
        ? `Sent back to the applicant ${returns === 1 ? 'once' : `${returns} times`} before clearing this stage`
        : undefined

  const here = isTerminal ? stoppedAt(history, status, rail) : positionOf(status, rail)

  const steps: RailStep[] = rail
    // A terminal filing's remaining steps are not pending, they are cancelled
    // futures. Showing them as "Not started" would read as "still coming".
    .slice(0, isTerminal ? here + 1 : undefined)
    .map((s, i) => ({
      status: s,
      state: isTerminal || i < here ? 'done' : i === here ? 'current' : 'upcoming',
      note: s === 'under_review' ? returnNote : undefined,
    }))

  return { steps, terminal: isTerminal ? status : null }
}

/* ── The rail ─────────────────────────────────────────────────────────── */

const STATE_CAPTION: Record<StepState, string> = {
  done: 'Completed',
  current: 'Current stage',
  upcoming: 'Not started',
}

function StepNode({ step, first, last }: { step: RailStep; first: boolean; last: boolean }) {
  const meta = applicationStatusMeta(step.status)
  const { state } = step
  const Glyph = state === 'done' ? CheckIcon : state === 'current' ? meta.icon : DotIcon

  /*
   * Three visually separable treatments, each already redundant with its own
   * glyph and its own caption below: a filled green tick, a tinted ring in the
   * status's own tone, and a hollow outline. Someone who sees no colour at all
   * still reads tick / icon-in-ring / empty-dot, and then the words.
   */
  const circle =
    state === 'done'
      ? 'bg-s-green text-white border-s-green'
      : state === 'current'
        ? `${TONE_CLASSES[meta.tone]} ring-4 ring-royal/15`
        : 'bg-white text-ink-muted border-line'

  return (
    <li
      aria-current={state === 'current' ? 'step' : undefined}
      className="flex min-w-0 flex-1 flex-col items-center text-center"
    >
      {/* Connector + node. The rules are decoration; the state is in the text. */}
      <div className="flex w-full items-center">
        <span className={`h-1 flex-1 rounded-full ${first ? 'invisible' : state === 'upcoming' ? 'bg-line' : 'bg-s-green'}`} aria-hidden="true" />
        <span
          className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2 ${circle}`}
          aria-hidden="true"
        >
          <Glyph size={20} />
        </span>
        <span className={`h-1 flex-1 rounded-full ${last ? 'invisible' : state === 'done' ? 'bg-s-green' : 'bg-line'}`} aria-hidden="true" />
      </div>

      <p className={`mt-2 px-1 text-sm font-bold ${state === 'upcoming' ? 'text-ink-muted' : 'text-ink'}`}>
        {meta.label}
      </p>
      <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
        {STATE_CAPTION[state]}
      </p>
      {step.note && <p className="mt-1 max-w-[22ch] text-[11px] leading-snug text-s-orange-ink">{step.note}</p>}
    </li>
  )
}

/** The end of the line for a rejected or cancelled filing. */
function TerminalNode({ status, stage }: { status: ApplicationStatus; stage: string }) {
  const meta = applicationStatusMeta(status)

  return (
    <li aria-current="step" className="flex min-w-0 flex-1 flex-col items-center text-center">
      <div className="flex w-full items-center">
        <span className="h-1 flex-1 rounded-full bg-line" aria-hidden="true" />
        <span
          className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2 ${TONE_CLASSES[meta.tone]} ring-4 ring-s-red/15`}
          aria-hidden="true"
        >
          <XCircleIcon size={20} />
        </span>
        <span className="h-1 flex-1 invisible" aria-hidden="true" />
      </div>
      <p className="mt-2 px-1 text-sm font-bold text-ink">{meta.label}</p>
      <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">Ends here</p>
      <p className="mt-1 max-w-[22ch] text-[11px] leading-snug text-ink-secondary">During {stage}</p>
    </li>
  )
}

/* ── Which offices are still holding it ───────────────────────────────── */

/**
 * The per-office picture inside For Approval.
 *
 * One assignment is raised per issuing office and `afterReviewProgress` moves
 * nothing until every one of them reads Completed. So a filing can sit in For
 * Approval for a week with five offices finished and one that has not opened it,
 * and until now the rail — and the status chip before it — would have said only
 * "For Approval" the whole time. Naming the office that is holding it is the one
 * thing that turns "it is stuck" into something an admin can act on.
 */
function OfficeProgress({ assignments, ended }: { assignments: Assignment[]; ended: boolean }) {
  if (assignments.length === 0) return null

  const done = assignments.filter((a) => a.status === 'completed')
  /*
   * Nothing is outstanding on a filing that has ended. The assignments of a
   * rejected filing sit at Pending for ever because rejection is decided above
   * them, and reading that back as "waiting on BPLO" would send an admin to
   * chase an office for a decision no one is waiting for.
   */
  const outstanding = ended ? [] : assignments.filter((a) => a.status !== 'completed')

  return (
    <section className="mt-6 border-t border-line pt-5" aria-labelledby="office-progress-heading">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 id="office-progress-heading" className="text-[11px] font-bold uppercase tracking-wide text-ink-muted">
          Office approvals
        </h3>
        <p className="text-xs font-semibold text-ink-secondary">
          <span className="tnum">
            {done.length} of {assignments.length}
          </span>{' '}
          complete
          {/*
           * Named, not counted. "1 outstanding" tells an admin a number they can
           * already see; the office's name tells them who to ring.
           */}
          {outstanding.length > 0 && (
            <span className="font-normal text-ink-muted">
              {' '}
              · waiting on {outstanding.map((a) => a.department?.code ?? a.department?.name ?? 'an office').join(', ')}
            </span>
          )}
        </p>
      </div>

      <ul className="mt-3 grid gap-2 sm:grid-cols-2">
        {assignments.map((a) => (
          <li
            key={a.id}
            className="flex items-start justify-between gap-3 rounded-lg bg-royal-tint px-3 py-2"
          >
            <div className="min-w-0">
              <p className="truncate text-sm font-semibold text-ink">
                {a.department?.name ?? 'Office removed'}
              </p>
              <p className="truncate text-xs text-ink-muted">
                {/* The officer is nullable: unclaimed, or a deleted account. */}
                {a.officer?.name ?? 'Not yet assigned to an officer'}
              </p>
              {a.remarks && <p className="mt-1 text-xs leading-snug text-ink-secondary">“{a.remarks}”</p>}
            </div>
            <StatusBadge
              tone={genericStatusTone(a.status)}
              label={a.status_label || a.status}
              size="sm"
            />
          </li>
        ))}
      </ul>
    </section>
  )
}

/* ── What actually happened ───────────────────────────────────────────── */

function HistoryLog({ history }: { history: TimelineEntry[] }) {
  if (history.length === 0) return null

  return (
    <section className="mt-6 border-t border-line pt-5" aria-labelledby="history-heading">
      <h3 id="history-heading" className="text-[11px] font-bold uppercase tracking-wide text-ink-muted">
        What happened
      </h3>
      {/* Newest first: the last thing that happened is the thing being asked about. */}
      <ol className="mt-3 space-y-3">
        {[...history].reverse().map((entry, i) => {
          const meta = applicationStatusMeta(entry.to_status)

          return (
            <li key={`${entry.to_status}-${entry.created_at}-${i}`} className="flex gap-3">
              <span
                className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${i === 0 ? 'bg-royal' : 'bg-line'}`}
                aria-hidden="true"
              />
              <div className="min-w-0">
                <p className="text-sm text-ink">
                  <span className="font-semibold">{meta.label}</span>
                  {entry.from_status && (
                    <span className="text-ink-muted"> · from {applicationStatusMeta(entry.from_status).label}</span>
                  )}
                </p>
                <p className="text-xs text-ink-muted">
                  <span className="tnum">{formatDateTime(entry.created_at)}</span>
                  {/*
                   * Submission and the payment-triggered routing have no officer
                   * behind them, and a deleted staff account leaves its history
                   * rows standing. "System" is the true answer for both; a blank
                   * by-line reads as data we failed to load.
                   */}
                  {' · '}
                  {entry.changed_by?.name ?? 'System'}
                </p>
                {entry.note && <p className="mt-0.5 text-xs leading-snug text-ink-secondary">{entry.note}</p>}
              </div>
            </li>
          )
        })}
      </ol>
    </section>
  )
}

/* ── The card ─────────────────────────────────────────────────────────── */

export function ApplicationProgress({ app }: { app: Application }) {
  const history = app.status_history ?? []
  const rail = railFor(app)
  const { steps, terminal } = buildSteps(app)
  const meta = applicationStatusMeta(app.status)
  const inspects = rail.includes('for_inspection')
  const stoppedStage = terminal
    ? applicationStatusMeta(rail[stoppedAt(history, terminal, rail)] ?? 'under_review').label
    : ''

  return (
    <section
      className="mb-5 rounded-lg bg-white px-6 py-5 shadow-card"
      aria-labelledby="progress-heading"
    >
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 id="progress-heading" className="text-base font-bold text-ink">
            Application progress
          </h2>
          <p className="text-xs text-ink-muted">
            {terminal
              ? `This filing ended during ${stoppedStage}. Nothing further will happen to it.`
              : inspects
                ? 'Pending Payment → For Approval → For Inspection → Approved'
                : /*
                   * Said out loud rather than left as an absence. An admin who
                   * knows the four-stage process needs to be told this filing has
                   * three, or they will read the missing step as a bug.
                   */
                  'No permit type on this filing requires an inspection, so it goes from For Approval straight to Approved.'}
          </p>
        </div>
        <StatusBadge tone={meta.tone} label={meta.label} icon={meta.icon} />
      </div>

      <ol className="mt-5 flex items-start">
        {steps.map((step, i) => (
          <StepNode
            key={step.status}
            step={step}
            first={i === 0}
            last={i === steps.length - 1 && !terminal}
          />
        ))}
        {terminal && <TerminalNode status={terminal} stage={stoppedStage} />}
      </ol>

      <OfficeProgress assignments={app.assignments ?? []} ended={terminal !== null} />
      <HistoryLog history={history} />
    </section>
  )
}
