import { useEffect, useState } from 'react'
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
import { UsersIcon } from '../../components/icons'

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
  const [officerId, setOfficerId] = useState<number | ''>('')
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
  const selected = officerId === '' ? (current?.id ?? '') : officerId

  async function confirm() {
    if (selected === '' || busy) return
    setBusy(true)
    setFailure(null)
    try {
      await assignments.assign(row.id, selected, reason.trim() || undefined)
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
      confirmLabel={busy ? 'Reassigning…' : 'Reassign'}
      confirmDisabled={selected === '' || busy}
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
           * An office with no active account cannot be given the case, and the
           * screen says which office rather than showing an empty dropdown. It
           * is a real state: the Market office was retired with its one officer
           * on 6 September 2026, and any assignment it still held would land
           * here.
           */
          <p className="rounded-lg bg-s-orange-tint px-3.5 py-2.5 text-sm font-medium text-s-orange-ink">
            {row.office?.name ?? 'This office'} has no active officer to take it. Create or
            reactivate an account in that office first.
          </p>
        ) : (
          <label className="block">
            <FieldLabel required>New officer in charge</FieldLabel>
            <select
              className={inputCls}
              value={selected === '' ? '' : String(selected)}
              onChange={(e) => setOfficerId(e.target.value === '' ? '' : Number(e.target.value))}
            >
              <option value="">Choose an officer…</option>
              {candidates.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                  {c.is_current ? ' — currently in charge' : ''}
                </option>
              ))}
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

      <p role="status" className="mb-4 text-sm text-ink-muted">
        {meta ? `Showing ${rows.length} of ${meta.total} assignments` : ' '}
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
                  <th className="px-5 py-3">Status</th>
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
                          <span className="font-semibold text-ink">{row.officer.name}</span>
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
