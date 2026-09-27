import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { toApiError } from '../../lib/api'
import { formatDateTime } from '../../lib/format'
import { admin, assignments } from '../../lib/resources'
import type { OicAssignment, OicCandidate } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import {
  FieldLabel,
  PageTitle,
  ProtoCard,
  ProtoModal,
  SortFilter,
  StatusChip,
  inputCls,
} from '../../components/ui/Proto'
import { ArrowLeftIcon, UsersIcon } from '../../components/icons'

/*
 * Officer in Charge — the super admin's register of who holds what.
 *
 * The client's §6: business, number, filing, office, current OIC, assignment
 * date, status, and a Reassign action. It is the counterpart of the Officer
 * Assignment screen, and the pairing is deliberate — that one starts from an
 * OFFICER and moves their whole caseload (somebody left, somebody is on leave);
 * this one starts from a CASE and moves the one (this filing is stuck, or went
 * to the wrong desk). Both write `application_assignments.officer_user_id`, so
 * the two screens cannot come to disagree about who holds a filing.
 *
 * ── Why the row is an assignment and not an application ─────────────────────
 *
 * One filing carrying six clearances is six offices' work, and each office
 * holds its own officer (§9). A screen keyed on the filing would have to pick
 * one of six names to print, and every choice is wrong for five readers. So the
 * row is the assignment — one office's piece — and a filing appears once per
 * office involved, which is what the client's own example shows: BPLO to
 * Officer A, Sanitary to Officer C, Fire to Officer D on the same business.
 */

/**
 * Still open, or finished?
 *
 * The register counts every assignment a name is on, because it is the record
 * of who did what; the officer directory's Holding column counts only work
 * that can still be moved. On the live register that reads 34 against 2, and
 * nothing on either screen explained the gap.
 *
 * This is the control that closes it, and "Still open" narrows to exactly the
 * set the Holding column is talking about — both use `Caseload::scopeOpen`.
 */
/*
 * ── This register is the work that can still be moved ────────────────────
 *
 * It listed every assignment a name was ever on — 34 rows, 30 of them on
 * filings decided months ago, each with a Reassign button that would have
 * rewritten the record of who did the work. The client put the principle
 * plainly [27 September 2026]: *"yung finished bawal na mareassign kasi tapos
 * na na dapat nasa Records page na lang sya."*
 *
 * So the screen asks for `state=open` and offers no control to widen it. Two
 * things follow and both are improvements:
 *
 *  - every row here can actually be acted on, so Reassign always means
 *    something; and
 *  - the total is the figure the officer directory's Holding column is a
 *    subset of, with no reconciliation to explain. 4 assignments open, 2 of
 *    them held. A "still open" filter and a sentence defining it were here to
 *    bridge 34 to 2, and neither is needed once the 30 are gone.
 *
 * The endpoint keeps its `state` parameter — `finished` is a real question and
 * a tested one; this screen is simply not where it is asked. The refusal is
 * enforced there too (`AssignmentController::assign` answers 422 on a decided
 * filing), because a screen that merely hides the rows is not a rule.
 */

const HOLDER_OPTIONS = [
  { value: '', label: 'Everyone' },
  { value: 'unassigned', label: 'Not yet taken' },
  { value: 'assigned', label: 'Taken' },
]

/** The three-part heading of one row, so the table and the dialog agree. */
function nameOf(row: OicAssignment): string {
  return row.business?.name ?? row.tracking_id ?? 'Filing removed from the register'
}

function ReassignModal({
  row,
  onClose,
  onDone,
}: {
  row: OicAssignment
  onClose: () => void
  onDone: () => void
}) {
  const { data, loading, error } = useAsync(() => admin.oicCandidates(row.id), [row.id])
  const [officerId, setOfficerId] = useState<number | '' | 'release'>('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState<string | null>(null)

  const candidates: OicCandidate[] = data ?? []

  /*
   * The dialog opens on the current holder, and derives that rather than
   * syncing it into state with an effect.
   *
   * `'' `means "the reader has not chosen yet", so the current holder stands in
   * until they do — which also makes the reset free when a different row is
   * opened. The effect version had to depend on `candidates`, a fresh array on
   * every render, so it re-ran constantly and could overwrite a choice the
   * reader had just made.
   */
  const current = candidates.find((c) => c.is_current)

  /*
   * Three states, not two, and the select's value has to tell them apart:
   *
   *   ''        nothing chosen yet — the current holder stands in
   *   'release' back to the office queue, with nobody holding it
   *   a number  that officer
   *
   * `release` is a sentinel rather than `null` because a <select>'s value is
   * always a string: `value={null}` renders as the empty option, which is the
   * "not chosen yet" state, and the two would have collapsed into one.
   */
  const selected: number | '' | 'release' =
    officerId === '' ? (current?.id ?? '') : officerId

  /*
   * Choosing the officer who already holds it is not a reassignment.
   *
   * The select opens on the current holder, so Confirm was pressable the
   * moment the dialog appeared, and pressing it wrote an "assignment changed"
   * entry to the audit trail recording a change to the same name. The rule
   * belongs here rather than in the label alone, because the keyboard path
   * (Enter on the dialog) does not read labels.
   */
  const unchanged = selected !== '' && selected !== 'release' && selected === current?.id

  async function confirm() {
    if (selected === '' || unchanged || busy) return
    setBusy(true)
    setFailure(null)
    try {
      /*
       * Releasing is the same act with nobody on the end, so it is the same
       * endpoint with a null holder — the meaning `reassign-caseload` has
       * always given `to_user_id: null`.
       *
       * This screen could not do it at all until now: its dialog required a
       * successor, so an office losing its only officer had nowhere to release
       * the work from. That was the one thing the caseload page could do and
       * this one could not.
       */
      await assignments.assign(
        row.id,
        selected === 'release' ? null : selected,
        reason.trim() || undefined,
      )
      onDone()
    } catch (err) {
      setFailure(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <ProtoModal
      title="Reassign officer in charge"
      onCancel={onClose}
      onConfirm={confirm}
      /*
        The label names the act, because the two are different enough that a
        reader should know which one they are about to perform without
        re-reading the select above it.
      */
      confirmLabel={
        busy
          ? selected === 'release'
            ? 'Releasing…'
            : 'Reassigning…'
          : selected === 'release'
            ? 'Release to office queue'
            : /*
                The destination is IN the label. "Reassign" alone made the
                reader look back up at a select they had set a moment ago; the
                name puts the whole decision under the cursor.
              */
              `Reassign to ${candidates.find((c) => c.id === selected)?.name ?? 'officer'}`
      }
      confirmDisabled={selected === '' || unchanged || busy}
      confirmDescribedBy={unchanged ? 'reassign-unchanged' : undefined}
    >
      <div className="space-y-5">
        <div>
          <p className="text-base font-bold text-ink">{nameOf(row)}</p>
          <p className="tnum text-sm text-ink-muted">{row.tracking_id ?? '—'}</p>
          <p className="mt-1 text-sm text-ink-secondary">
            {row.office?.name ?? 'Office removed'}
            {' · '}
            {row.officer ? (
              <>
                currently with <span className="font-semibold text-ink">{row.officer.name}</span>
              </>
            ) : (
              'not yet taken by anyone'
            )}
          </p>
        </div>

        {loading ? (
          <SkeletonList rows={2} />
        ) : error ? (
          <ErrorState error={error} />
        ) : candidates.length === 0 ? (
          /*
           * An office with no active account cannot be GIVEN the case, and the
           * screen says which office rather than showing an empty dropdown. It
           * is a real state: the Market office was retired with its one officer
           * on 6 September 2026, and any assignment it still held would land
           * here.
           *
           * It is no longer a dead end. Releasing needs no successor, so the
           * one act still available is offered here — which is exactly the
           * office this dialog used to strand.
           */
          <div className="space-y-3">
            <p className="rounded-lg bg-s-orange-tint px-3.5 py-2.5 text-sm font-medium text-s-orange-ink">
              {row.office?.name ?? 'This office'} has no active officer to take it. Create or
              reactivate an account in that office to hand it to somebody.
            </p>
            {row.officer && (
              <label className="flex cursor-pointer items-start gap-2.5">
                <input
                  type="checkbox"
                  checked={officerId === 'release'}
                  onChange={(e) => setOfficerId(e.target.checked ? 'release' : '')}
                  className="mt-0.5 h-4 w-4 shrink-0 accent-royal"
                />
                <span className="text-sm text-ink">
                  Take it off <span className="font-semibold">{row.officer.name}</span> and leave it
                  in the office queue, for whoever the office next staffs.
                </span>
              </label>
            )}
          </div>
        ) : (
          <label className="block">
            <FieldLabel required>New officer in charge</FieldLabel>
            <select
              className={inputCls}
              value={selected === '' ? '' : String(selected)}
              onChange={(e) =>
                setOfficerId(
                  e.target.value === ''
                    ? ''
                    : e.target.value === 'release'
                      ? 'release'
                      : Number(e.target.value),
                )
              }
            >
              <option value="">Choose an officer…</option>
              {candidates.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                  {c.is_current ? ' — currently in charge' : ''}
                </option>
              ))}
              {/*
                Below the officers, and only when somebody holds it: releasing
                a filing nobody has taken is a no-op, and offering it would be
                a choice with no effect.
              */}
              {row.officer && <option value="release">Leave unassigned — back to the office queue</option>}
            </select>
            {/*
              * Only this office's officers are listed, because the endpoint
              * refuses anybody else with a 422. Offering a name the confirm step
              * then rejects would be a worse screen than offering none.
              */}
            <p className="mt-1.5 text-xs text-ink-muted">
              Officers of {row.office?.name ?? 'this office'} only — a review belongs to the office
              that was routed it.
            </p>
          </label>
        )}

        <label className="block">
          <FieldLabel>Reason (optional)</FieldLabel>
          <textarea
            rows={3}
            className={inputCls}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="On leave, workload, wrong desk…"
          />
          <p className="mt-1.5 text-xs text-ink-muted">
            Kept on the audit trail beside the change, so the move can be explained later.
          </p>
        </label>

        {unchanged && (
          <p id="reassign-unchanged" className="text-xs text-ink-muted">
            <span className="font-semibold text-ink">{current?.name}</span> already has this one.
            Choose somebody else, or leave it unassigned, to change anything.
          </p>
        )}

        {/*
          What follows, in the same words the officer caseload page uses for
          the same act - the two screens can both reassign and should not
          describe it differently.

          Reversibility is the fact that makes this dialog BLUE rather than
          red: DESIGN.md holds red for acts that destroy something or stop
          somebody working, and this moves a name.
        */}
        {selected !== '' && !unchanged && (
          <p className="text-xs text-ink-muted">
            {selected === 'release'
              ? 'The filing stays exactly where it is in the process; only the name comes off it. Anyone in the office can take it, and you can hand it back at any time.'
              : 'They see it in their queue straight away. The filing does not move backwards, and you can reassign it again at any time.'}
          </p>
        )}

        {failure && (
          <p role="alert" className="rounded-lg bg-s-red-tint px-3.5 py-2.5 text-sm font-medium text-s-red">
            {failure}
          </p>
        )}
      </div>
    </ProtoModal>
  )
}

export function OicPage() {
  const [page, setPage] = useState(1)
  const [office, setOffice] = useState('')
  const [holder, setHolder] = useState('')

  const [query, setQuery] = useState('')
  /** The term the SERVER has: the register is paged, so search cannot be local. */
  const [asked, setAsked] = useState('')
  const [reassigning, setReassigning] = useState<OicAssignment | null>(null)

  const { data, loading, error, reload } = useAsync(
    () =>
      admin.oicAssignments({
        page,
        per_page: 50,
        ...(office ? { department_id: Number(office) } : {}),
        ...(holder ? { holder: holder as 'assigned' | 'unassigned' } : {}),
        // Always. See the note above: this screen is the movable work.
        state: 'open' as const,
        ...(asked ? { q: asked } : {}),
      }),
    [page, office, holder, asked],
  )

  // Typing lags the request; every other narrowing restarts the list at once.
  useEffect(() => {
    const timer = setTimeout(() => {
      if (query.trim() !== asked) {
        setAsked(query.trim())
        setPage(1)
      }
    }, 300)
    return () => clearTimeout(timer)
  }, [query, asked])

  const rows = data?.data ?? []
  const meta = data?.meta
  const offices = meta?.departments ?? []
  const narrowed = office !== '' || holder !== '' || asked !== ''

  function narrow(next: () => void) {
    next()
    setPage(1)
  }

  return (
    <div>
      {/*
        ── The way back ─────────────────────────────────────────────────────
        
        This screen came off the sidebar on 27 September 2026 and is now
        reached from Officer Assignment's "View all assignments". A page you
        arrive at through another page needs a way back to it — without one,
        the only exit is the browser's own button, and a reader who followed a
        pasted link has no history to use it on.
        
        Same shape as the caseload page and `PayPage` before it: a real link
        with an href, above the title, on the left. Relative, so it lands on
        whichever portal this page is being read on.
      */}
      <Link
        to="../users"
        relative="path"
        className="mb-5 inline-flex items-center gap-1.5 text-sm font-semibold text-royal hover:underline"
      >
        <ArrowLeftIcon size={16} /> Back to Officer Assignment
      </Link>

      <PageTitle
        right={
          <span className="flex flex-wrap items-center gap-3">
            <input
              type="search"
              aria-label="Search the officer-in-charge register"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Business or tracking ID"
              className="w-64 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
            <SortFilter
              sort={{
                value: holder,
                options: HOLDER_OPTIONS,
                onChange: (v) => narrow(() => setHolder(v)),
              }}
              filter={{
                value: office,
                options: [
                  { value: '', label: 'Every office' },
                  ...offices.map((d) => ({ value: String(d.id), label: d.name })),
                ],
                onChange: (v) => narrow(() => setOffice(v)),
              }}

            />
          </span>
        }
      >
        Officer in Charge
      </PageTitle>

      {/*
        Both numbers, and the chain between them.

        "Showing 34 of 34 assignments" beside an officer directory saying
        "holding 2" is the kind of disagreement nobody reports as a bug — they
        stop trusting both. The sentence carries the whole reconciliation, so a
        reader can walk 34 → 4 → 2 without leaving the screen, and the last
        figure is exactly what the Holding column adds up to.

        Only when nothing is narrowing the list: against a filtered view these
        register-wide figures would read as a description of what is on screen.
      */}
      {/*
        One number now, and it needs no explaining.

        This said "Showing 34 of 34 assignments · 4 still open, of which 2 have
        an officer" — a sentence whose whole job was to bridge a total that
        included work nobody could move. The screen shows only the movable work
        now, so the total IS that figure, and the officer directory's Holding
        column is plainly a subset of it.
      */}
      <p role="status" className="mb-4 text-sm text-ink-muted">
        {meta ? (
          <>
            Showing {rows.length} of {meta.total} open assignment{meta.total === 1 ? '' : 's'}
            {!narrowed && meta.open_assigned !== undefined && (
              <>
                {' · '}
                <span className="font-semibold text-ink">{meta.open_assigned}</span>{' '}
                {meta.open_assigned === 1 ? 'has' : 'have'} an officer, the rest are waiting to be
                taken
              </>
            )}
          </>
        ) : (
          ' '
        )}
      </p>

      {loading && rows.length === 0 ? (
        <SkeletonList rows={5} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={UsersIcon}
          title={narrowed ? 'Nothing matches these filters' : 'No assignments yet'}
          description={
            narrowed
              ? 'No assignment matches the office, holder or search you have chosen.'
              : 'A filing is routed to an office when its fees are settled. Nothing has reached an office yet.'
          }
        />
      ) : (
        <ProtoCard>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[62rem] text-left text-sm">
              <thead>
                <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  <th className="px-5 py-3">Business</th>
                  <th className="px-5 py-3">Business No.</th>
                  <th className="px-5 py-3">Office</th>
                  <th className="px-5 py-3">Officer in charge</th>
                  <th className="px-5 py-3">Assigned</th>
                  {/*
                    Two statuses, two columns.

                    "Status" alone showed the office's own REVIEW STEP and said
                    nothing about the filing — which is the thing the open /
                    finished filter acts on. A reader filtering to "Still open"
                    saw rows marked Completed and had no way to tell that both
                    were true.
                  */}
                  <th className="px-5 py-3">Review step</th>
                  <th className="px-5 py-3">Filing</th>
                  <th className="px-5 py-3">Action</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-t border-line align-top">
                    <td className="px-5 py-3.5 font-semibold text-ink">{nameOf(row)}</td>
                    <td className="tnum px-5 py-3.5 text-ink-secondary">{row.tracking_id ?? '—'}</td>
                    <td className="px-5 py-3.5 text-ink-secondary">{row.office?.name ?? '—'}</td>
                    <td className="px-5 py-3.5">
                      {row.officer ? (
                        <>
                          {/*
                            The holder's name is a way INTO their desk.
                            
                            This screen answers "who has it"; the caseload page
                            answers "what else are they holding, and can I move
                            it in one go". They were two screens a reader had
                            to join by hand — find the name here, then go to
                            Officer Assignment and find it again. One link
                            closes that, and it is the shape this table already
                            invites: the name is the most specific thing in the
                            row.
                            
                            Relative, so it lands on the right portal without
                            this page knowing which one it is on:
                            `/admin/oic` → `/admin/users/:id/reassign`,
                            `/staff/admin/oic` → `/staff/admin/users/:id/…`.
                          */}
                          <Link
                            to={`../users/${row.officer.id}/reassign`}
                            relative="path"
                            aria-label={`Open ${row.officer.name}’s caseload`}
                            className="font-semibold text-royal hover:underline"
                          >
                            {row.officer.name}
                          </Link>
                          <span className="block text-xs text-ink-muted">{row.officer.email}</span>
                        </>
                      ) : (
                        /*
                          * Not a dash. On the officer's own queue a null holder
                          * can mean "you may not be told"; here the reader sees
                          * every office, so null has exactly one meaning and the
                          * screen states it — this is the row the super admin
                          * opened the page to find.
                          */
                        <span className="font-semibold text-s-orange-ink">Not yet taken</span>
                      )}
                    </td>
                    <td className="px-5 py-3.5 text-ink-secondary">
                      {row.assigned_at ? formatDateTime(row.assigned_at) : '—'}
                    </td>
                    <td className="px-5 py-3.5">
                      <StatusChip tone={row.completed_at ? 'tint-green' : 'tint-yellow'}>
                        {row.status_label ?? '—'}
                      </StatusChip>
                    </td>
                    <td className="px-5 py-3.5">
                      {/*
                        Where the FILING has got to — the fact that decides
                        whether this row is open or finished. Plain text rather
                        than a second chip: two chips in adjacent cells read as
                        one status shown twice, which is the confusion this
                        column exists to end.
                      */}
                      <span className="text-sm text-ink-secondary">
                        {row.application_status_label ?? '—'}
                      </span>
                    </td>
                    <td className="px-5 py-3.5">
                      <button
                        type="button"
                        onClick={() => setReassigning(row)}
                        className="rounded-full border border-transparent bg-royal px-4 py-1.5 text-xs font-semibold text-white hover:bg-royal-hover"
                      >
                        Reassign
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </ProtoCard>
      )}

      {meta && meta.last_page > 1 && (
        <div className="mt-5 flex items-center justify-center gap-3">
          <button
            type="button"
            onClick={() => setPage((p) => Math.max(1, p - 1))}
            aria-disabled={page <= 1 || undefined}
            aria-label="Previous page"
            className="rounded-full border border-line px-4 py-1.5 text-sm font-semibold text-ink hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
          >
            Previous
          </button>
          <span className="tnum text-sm text-ink-muted">
            Page {meta.current_page} of {meta.last_page}
          </span>
          <button
            type="button"
            onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
            aria-disabled={page >= meta.last_page || undefined}
            aria-label="Next page"
            className="rounded-full border border-line px-4 py-1.5 text-sm font-semibold text-ink hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
          >
            Next
          </button>
        </div>
      )}

      {reassigning && (
        <ReassignModal
          row={reassigning}
          onClose={() => setReassigning(null)}
          onDone={() => {
            setReassigning(null)
            // Re-read rather than patch: the office filter and the holder filter
            // both decide whether this row still belongs in the list at all.
            reload()
          }}
        />
      )}
    </div>
  )
}
