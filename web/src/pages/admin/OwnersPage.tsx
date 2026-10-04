import { useEffect, useMemo, useRef, useState } from 'react'
import { admin } from '../../lib/resources'
import type { AdminBusinessFilters } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { toApiError } from '../../lib/api'
import { formatDate, formatDateTime, formatMoney } from '../../lib/format'
import type { AdminBusiness, AuditLog, BlacklistedOwner, BusinessStatus } from '../../lib/types'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import {
  FieldLabel,
  PageTitle,
  ProtoCard,
  FilterPills,
  ProtoModal,
  SortFilter,
  StatusChip,
  inputCls,
  useDialogKeyboard,
} from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { BuildingIcon } from '../../components/icons'
import { BUSINESS_STATUS } from '../../lib/status'

/*
 * Business Owner Status (PDF p99–101): the real /admin/businesses roster with
 * live status chips, the Changing Status modal wired to
 * POST /admin/businesses/{id}/status (all four statuses real), and the
 * audit-fed Status History dot-timeline modal.
 */

/* The words, the tones and the dots all come from lib/status.ts now — see
 * BUSINESS_STATUS there for why they stopped being three private tables. The
 * local names are kept so the call sites below read as they did. */
const STATUS_META = BUSINESS_STATUS

const REASON_CODES = [
  'Falsified / misrepresented documents',
  'Verified complaints from the public',
  'Non-payment of assessed fees',
  'Expired lease contract',
  'Compliance restored',
  'Other (see details)',
]

/*
 * ── Transfer of ownership is not on this screen ────────────────────
 *
 * The dialog that lived here moved a business to another owner account, for
 * MCG-BPLO-FO-003 section II. The endpoint still exists and an approved CHANGE
 * OF OWNERSHIP still has to land somewhere — but not here [client,
 * 27 September 2026: *"sa owner status page, delete transfer ownership"*].
 *
 * They are right that it did not belong. This page is where an admin bars
 * somebody from trading; handing a business to a different person is an
 * amendment being carried out, not a sanction, and the two sitting side by
 * side on one row invited the wrong button on the worst possible screen.
 */

/* ── Changing Status (p100) ───────────────────────────────────────────── */

function ChangeStatusModal({
  row,
  onClose,
  onChanged,
}: {
  row: AdminBusiness
  onClose: () => void
  onChanged: (updated: AdminBusiness) => void
}) {
  const [status, setStatus] = useState<BusinessStatus>(row.status)
  const [reasonCode, setReasonCode] = useState('')
  const [details, setDetails] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  /*
   * -- The second step ----------------------------------------------------
   *
   * This dialog wrote on one press, and what it writes is somebody's
   * livelihood: a suspension stops a business trading, and a blacklisting
   * bars its owner and every other business they hold. The reason code is a
   * dropdown whose neighbouring entries are "Compliance restored" and
   * "Non-payment of assessed fees", one row apart [client, 27 September 2026:
   * *"sa lahat ng major decision ... dapat modal na confirmation"*].
   *
   * One dialog, two views, rather than a second overlay on top: stacked
   * dialogs mean two focus traps and an Escape whose meaning depends on which
   * you believe is in front. Back returns to the form with everything still
   * typed.
   */
  const [review, setReview] = useState(false)

  const moving = status !== row.status
  const sanction = status === 'suspended' || status === 'blacklisted'

  /*
   * How many OTHER businesses this owner holds. A blacklisting reaches all of
   * them, so the dialog can only state the size of what it is about to do if
   * it knows - and asking is cheap next to getting this wrong.
   *
   * Only fetched for the one status that needs it.
   */
  const { data: siblings } = useAsync(
    () =>
      status === 'blacklisted' && row.owner
        ? admin.businessesPage({ q: row.owner.name, per_page: 100 })
        : Promise.resolve(null),
    [status, row.owner?.name],
  )
  const alsoAffected = (siblings?.data ?? []).filter(
    (b) => b.owner?.id === row.owner?.id && b.id !== row.id && b.status !== 'blacklisted',
  )

  async function confirm() {
    setBusy(true)
    setError(null)
    try {
      // Reason sent to the API is the code plus any free-text detail.
      const reason = [reasonCode, details.trim()].filter(Boolean).join(' · ')
      const updated = await admin.setBusinessStatus(row.id, status, reason)
      onChanged(updated)
    } catch (err) {
      /*
       * The dialog stays open, carrying the message. Closing it would put the
       * reader back on a roster with an error above it and no sign that what
       * they typed survived.
       */
      setError(toApiError(err).message)
      setReview(false)
      setBusy(false)
    }
  }

  return (
    <ProtoModal
      title={review ? confirmQuestion(status, row) : 'Changing Status'}
      /*
        -- The colour is the decision's, not the screen's ------------------

        RED for a suspension or a blacklisting, because those are the two acts
        that stop somebody trading - the definition DESIGN.md gives red under
        "Red Means Stop". BLUE for Active and Flagged: restoring a business is
        good news and flagging one is a note to watch it, and dressing either
        in the danger colour spends the one signal this app has on something
        that stops nobody.

        It follows the CHOSEN status, so the dialog changes colour as the
        reader moves down the list. That is the point: the warning belongs to
        what they are about to do, not to the screen they did it from.
      */
      tone={sanction ? 'red' : 'blue'}
      cancelLabel={review ? 'Back' : 'Cancel'}
      confirmLabel={
        review
          ? busy
            ? 'Saving…'
            : confirmVerb(status)
          : 'Review this change'
      }
      onCancel={review ? () => setReview(false) : onClose}
      onConfirm={review ? confirm : () => setReview(true)}
      confirmDisabled={busy || (!review && (!reasonCode || !moving))}
      confirmDescribedBy={!review && (!reasonCode || !moving) ? 'status-blocker' : undefined}
    >
      <p className="mb-5 border-b border-line pb-3 text-sm text-ink-secondary">{row.name}</p>

      {review && (
        <div className="space-y-4">
          <p className="text-sm text-ink">
            <span className="font-bold">{row.name}</span> moves from{' '}
            <span className="font-semibold">{STATUS_META[row.status]?.label ?? row.status}</span> to{' '}
            <span className="font-bold">{STATUS_META[status]?.label ?? status}</span>.
          </p>

          {/*
            What the status MEANS, in the reader's terms, at the moment they
            commit. The word alone does not say whether the owner can still
            file, and that is the whole question a sanction turns on.
          */}
          <p className="rounded-lg border border-line bg-canvas px-4 py-3 text-sm text-ink-secondary">
            {CONSEQUENCE[status]}
          </p>

          {status === 'blacklisted' && (
            /*
              The size of it. A blacklisting is a finding against the PERSON,
              so it reaches every business they hold - and an admin clicking
              one row is entitled to know that before they do, rather than
              discovering it on the roster afterwards.
            */
            <div className="rounded-lg bg-s-red-tint px-4 py-3 text-sm text-s-red">
              <p className="font-bold">
                {row.owner?.name ?? 'This owner'} will be blacklisted, not just this business.
              </p>
              {alsoAffected.length > 0 ? (
                <>
                  <p className="mt-1.5 text-xs leading-relaxed">
                    {alsoAffected.length} other{' '}
                    {alsoAffected.length === 1 ? 'business' : 'businesses'} registered to them will
                    be blacklisted in the same act, and their permits suspended:
                  </p>
                  <ul className="mt-2 space-y-1">
                    {alsoAffected.slice(0, 6).map((b) => (
                      <li key={b.id} className="text-xs font-semibold">
                        {b.name}
                      </li>
                    ))}
                    {alsoAffected.length > 6 && (
                      <li className="text-xs">and {alsoAffected.length - 6} more</li>
                    )}
                  </ul>
                </>
              ) : (
                <p className="mt-1.5 text-xs leading-relaxed">
                  This is the only business registered to them today. Any they register from now on
                  is barred too, while the blacklisting stands.
                </p>
              )}
            </div>
          )}

          {status === 'active' && row.owner?.blacklisted && (
            <div className="rounded-lg bg-s-green-tint px-4 py-3 text-sm text-s-green">
              <p className="font-bold">
                This also lifts the blacklisting from {row.owner.name}.
              </p>
              <p className="mt-1.5 text-xs leading-relaxed">
                Every business barred by it comes back with them, and their suspended permits are
                restored. Reinstating one business of a blacklisted owner cannot mean anything less
                — the bar is on the person.
              </p>
            </div>
          )}

          <dl className="divide-y divide-line rounded-lg border border-line">
            <div className="grid grid-cols-3 gap-3 px-4 py-3">
              <dt className="text-xs font-semibold text-ink-muted">Reason</dt>
              <dd className="col-span-2 text-sm text-ink">{reasonCode}</dd>
            </div>
            {details.trim() && (
              <div className="grid grid-cols-3 gap-3 px-4 py-3">
                <dt className="text-xs font-semibold text-ink-muted">Details</dt>
                <dd className="col-span-2 text-sm text-ink">{details.trim()}</dd>
              </div>
            )}
          </dl>

          <p className="text-xs text-ink-muted">
            {/*
              Both facts the reader needs after the fact: it is on the record,
              and the owner is told. An owner finding out by being refused a
              renewal is the failure this sentence exists to rule out.
            */}
            Written to the status history, and {row.owner?.name ?? 'the owner'} is notified with
            this reason and told to message the City BPLO.
          </p>

          {error && (
            <p role="alert" className="rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
              {error}
            </p>
          )}
        </div>
      )}

      <div className={review ? 'hidden' : 'space-y-4'} aria-hidden={review || undefined}>
        <label className="block">
          <FieldLabel required>New status</FieldLabel>
          <select className={inputCls} value={status} onChange={(e) => setStatus(e.target.value as BusinessStatus)}>
            {(Object.keys(STATUS_META) as BusinessStatus[]).map((s) => (
              <option key={s} value={s}>
                {STATUS_META[s].label}
              </option>
            ))}
          </select>
        </label>
        <label className="block">
          <FieldLabel required>Reason code</FieldLabel>
          <select className={inputCls} value={reasonCode} onChange={(e) => setReasonCode(e.target.value)}>
            <option value="">Select reason…</option>
            {REASON_CODES.map((r) => (
              <option key={r}>{r}</option>
            ))}
          </select>
        </label>
        <label className="block">
          <FieldLabel>Details</FieldLabel>
          <textarea
            className={`${inputCls} min-h-20`}
            placeholder="Describe the basis for this status change"
            value={details}
            onChange={(e) => setDetails(e.target.value)}
          />
        </label>
        {(!reasonCode || !moving) && (
          <p id="status-blocker" className="text-xs text-ink-muted">
            {!moving
              ? `${row.name} is already ${(STATUS_META[status]?.label ?? status).toLowerCase()}. Choose a different status to change anything.`
              : 'Choose a reason code — it goes on the record and the owner is shown it.'}
          </p>
        )}

        {!review && error && (
          <p role="alert" className="rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
            {error}
          </p>
        )}
      </div>
    </ProtoModal>
  )
}

/** The question the confirmation asks, named for the act rather than "Confirm?". */
function confirmQuestion(status: BusinessStatus, row: AdminBusiness): string {
  if (status === 'blacklisted') return `Blacklist ${row.owner?.name ?? 'this owner'}?`
  if (status === 'suspended') return `Suspend ${row.name}?`
  if (status === 'flagged') return `Flag ${row.name} for watching?`
  return `Restore ${row.name} to active?`
}

/** The verb on the button, so the last thing read is what will happen. */
function confirmVerb(status: BusinessStatus): string {
  if (status === 'blacklisted') return 'Blacklist this owner'
  if (status === 'suspended') return 'Suspend this business'
  if (status === 'flagged') return 'Flag this business'
  return 'Restore to active'
}

/**
 * What each status DOES, said at the moment of deciding.
 *
 * The four words are not self-explanatory and the difference between two of
 * them is whether a family stops earning this month. An admin choosing from a
 * dropdown of labels is choosing between consequences, so the consequences are
 * on screen.
 */
const CONSEQUENCE: Record<BusinessStatus, string> = {
  active:
    'The business can file and renew as normal, and any permits suspended by an earlier sanction are restored.',
  flagged:
    'A note to watch this business. Nothing is blocked — it can still file and renew, and its permits are untouched.',
  suspended:
    'This business cannot file or renew, and its permits are suspended, so the QR check at the counter will read them as not valid. Its owner’s other businesses are unaffected.',
  blacklisted:
    'The OWNER is barred, not just this business: none of the businesses registered to them can file or renew, all of their permits are suspended, and anything they register from now on is barred too.',
}

/* ── Status History (p101) — audit-fed ────────────────────────────────── */

interface HistoryEntry {
  key: string
  status: string
  tone: string
  date: string | null
  note: string
}

const STATUS_DOT: Record<BusinessStatus, string> = Object.fromEntries(
  (Object.keys(BUSINESS_STATUS) as BusinessStatus[]).map((s) => [s, BUSINESS_STATUS[s].dot]),
) as Record<BusinessStatus, string>

function HistoryModal({ row, onClose }: { row: AdminBusiness; onClose: () => void }) {
  /*
   * This business's status changes, asked for by subject.
   *
   * It used to pull the eight newest pages of the WHOLE audit trail — 200 rows
   * out of tens of thousands — and keep the handful that happened to be about
   * this business. The newest rows are overwhelmingly sign-ins, so a business
   * blacklisted a month ago showed a timeline containing only "Registered",
   * under a caption explaining that the screen could not see very far. That is
   * a fair description of a window and no way to run a register. The audit
   * endpoint now filters on the subject, so this is the actual history.
   */
  const { data, loading } = useAsync(
    () =>
      admin.auditLogs({
        auditable_type: 'Business',
        auditable_id: row.id,
        action: 'status',
        per_page: 100,
      }),
    [row.id],
  )
  const panelRef = useRef<HTMLDivElement | null>(null)
  const closeRef = useRef<HTMLButtonElement | null>(null)
  // This overlay reimplemented ProtoModal's markup without its keyboard
  // handling: no focus move on open, no Escape, and Tab walked out of it.
  useDialogKeyboard(panelRef, onClose, closeRef)

  const entries = useMemo<HistoryEntry[]>(() => {
    const fromLogs: HistoryEntry[] = (data?.data ?? [])
      .map((log: AuditLog) => {
        /*
         * BusinessStatusController writes { from, to, reason }. This read
         * `changes.status`, which is never there, and fell back to 'active' — so
         * a blacklisting rendered in the timeline as "Active", in green, with the
         * reason dropped. Read the key the API actually writes, and when the
         * status really is missing say so rather than inventing "Active".
         */
        const changed = (log.changes as { from?: string; to?: string; reason?: string } | null) ?? {}
        const to = changed.to as BusinessStatus | undefined
        const meta = to ? STATUS_META[to] : undefined
        const from = changed.from ? (STATUS_META[changed.from as BusinessStatus]?.label ?? changed.from) : null
        return {
          key: `log-${log.id}`,
          status: meta ? (from ? `${from} → ${meta.label}` : meta.label) : 'Status changed',
          tone: to ? (STATUS_DOT[to] ?? 'bg-line') : 'bg-line',
          date: log.created_at,
          note: [log.user?.name ?? 'Actor not recorded', changed.reason].filter(Boolean).join(' · '),
        }
      })

    // Registration bookends the timeline.
    fromLogs.push({
      key: 'registered',
      status: 'Registered',
      tone: 'bg-s-green',
      date: row.created_at,
      note: `${row.owner?.name ?? 'Owner'} · Business registered.`,
    })
    return fromLogs
  }, [data, row])

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label="Status History"
        className="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-md bg-white shadow-overlay"
      >
        <div className="bg-royal px-5 py-3 text-base font-bold tracking-wide text-white">Status History</div>
        <p className="border-b border-line px-5 py-3 text-sm text-ink-secondary">
          {row.name}
          {!loading &&
            ` · ${(data?.total ?? 0).toLocaleString()} status ${
              (data?.total ?? 0) === 1 ? 'change' : 'changes'
            } on record`}
        </p>
        <div className="flex-1 overflow-y-auto px-5 py-4">
          {loading ? (
            <p className="py-6 text-center text-sm text-ink-muted">Loading history…</p>
          ) : (
            <ul>
              {entries.map((entry, i) => (
                <li key={entry.key} className="relative flex gap-3 pb-5">
                  {i < entries.length - 1 && (
                    <span className="absolute left-[5px] top-4 h-full w-px bg-line" aria-hidden="true" />
                  )}
                  <span className={`mt-1.5 h-[11px] w-[11px] shrink-0 rounded-full ${entry.tone}`} aria-hidden="true" />
                  <div className="min-w-0">
                    <p className="text-sm font-bold text-ink">{entry.status}</p>
                    {entry.date && <p className="text-xs text-ink-muted">{formatDateTime(entry.date)}</p>}
                    <p className="mt-0.5 text-xs text-ink-secondary">{entry.note}</p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
        <button
          ref={closeRef}
          type="button"
          onClick={onClose}
          className="bg-modal-cancel py-3.5 text-sm font-semibold text-ink underline underline-offset-2 hover:brightness-95"
        >
          Close
        </button>
      </div>
    </div>
  )
}

/* ── Page ─────────────────────────────────────────────────────────────── */

type ModalState =
  | { kind: 'change' | 'history' | 'fees'; row: AdminBusiness }
  /** Releasing a blacklisted OWNER, and everything they hold, in one act. */
  | { kind: 'release'; owner: BlacklistedOwner }
  | null

/**
 * What a business has been issued and not yet paid for, itemised.
 *
 * ── Why the total is a control and not just a figure ──────────────────────
 *
 * The column shows one number; the officer chasing it needs to know what it is
 * FOR. "₱2,100" is not something you can raise with an owner on the phone;
 * "the Sanitary Permit from June and the FSIC from August" is. So the total
 * opens this.
 *
 * `on_a_bill` earns its own line. A fee already sitting on a renewal the
 * applicant has been shown is still unpaid — which is why it is in the total —
 * but a chase is already in flight and an officer ringing about it should know
 * that before they do.
 */
function FeesModal({ row, onClose }: { row: AdminBusiness; onClose: () => void }) {
  const fees = row.unbilled_fees

  /*
   * `onCancel` with a Close label and no `onConfirm`: this dialog is a record,
   * not a decision, so there is nothing to proceed to. `ProtoModal` takes
   * `onCancel` and not `onClose` — passing the latter is what `HistoryModal`
   * beside this one hand-rolls its whole overlay to avoid, losing the focus
   * trap and the Escape handling in doing so.
   */
  return (
    <ProtoModal title="UNBILLED PERMIT FEES" cancelLabel="Close" onCancel={onClose}>
      <p className="mb-4 border-b border-line pb-3 text-sm text-ink-secondary">{row.name}</p>

      <p className="text-xs leading-relaxed text-ink-secondary">
        A permit renewed outside January is issued straight away and billed on the next business
        permit renewal. These are waiting for that renewal — nothing is overdue and no permit
        lapses, but the fees are not collected yet.
      </p>

      <ul className="mt-4 divide-y divide-line">
        {(fees?.items ?? []).map((item, i) => (
          <li key={`${item.permit_code ?? 'fee'}-${i}`} className="flex items-baseline gap-3 py-2.5">
            <span className="min-w-0 flex-1">
              <span className="block text-sm font-semibold text-ink">
                {item.permit_type ?? 'Permit fee'}
              </span>
              <span className="block text-xs text-ink-muted">
                Issued {formatDate(item.incurred_at)}
                {item.on_a_bill && ' · already on a renewal awaiting payment'}
              </span>
            </span>
            <span className="tnum shrink-0 text-sm font-semibold text-ink">
              {formatMoney(item.amount)}
            </span>
          </li>
        ))}
      </ul>

      <div className="mt-3 flex items-baseline justify-between border-t border-ink/40 pt-3 text-base font-bold text-ink">
        <span>Total uncollected</span>
        <span className="tnum">{formatMoney(fees?.total ?? 0)}</span>
      </div>
    </ProtoModal>
  )
}

/**
 * Who is barred, and everything they hold.
 *
 * ── This IS the Blacklisted pill ──────────────────────────────────────────
 *
 * It began as a second tab beside "Businesses", which was one register too
 * many: the roster already had a Blacklisted pill, so the screen offered two
 * doors to the same subject and the reader had to know which one answered
 * their question [client, 27 September 2026: *"theres blacklisted owners
 * seperated section just remove that at kung pano yung laman nya ilagay mo na
 * lang sa Businesses, Blacklisted section"*].
 *
 * They are right, and the reason the two existed is worth keeping in view: a
 * blacklisting falls on the PERSON, so a list of blacklisted shopfronts is
 * three rows repeating one name, saying nothing about the finding or who made
 * it. So the pill keeps its place in the row — where a reader looks for it —
 * and renders people instead of rows.
 *
 * Cards rather than a table. Each entry is one person with a reason, a date, a
 * signature and a business list of a variable length — a table would give five
 * columns of which one is a nested list, and the reason (the thing worth
 * reading) would be squeezed into whatever width was left.
 */
function BlacklistedOwners({
  query,
  refreshKey,
  onRelease,
  onHistory,
}: {
  query: string
  /*
   * Bumped by the page when a status change lands.
   *
   * This list fetches on its own, so lifting a bar from one of its cards left
   * the card sitting there: the page reloaded the BUSINESS roster it was not
   * showing, and the register of people kept the owner it had just released.
   * The reader's only clue was that pressing Change Status again offered to
   * restore a business that was already active.
   */
  refreshKey: number
  /**
   * Release this OWNER, and with them everything they hold.
   *
   * It used to be one Change Status per business, and that was incoherent: a
   * blacklisting falls on the person, so freeing one shopfront while the
   * others stayed barred left the register contradicting itself — the owner
   * barred, and one of their businesses reading Active [client, 28 September
   * 2026].
   */
  onRelease: (owner: BlacklistedOwner) => void
  onHistory: (business: AdminBusiness) => void
}) {
  const [page, setPage] = useState(1)
  useEffect(() => setPage(1), [query])

  const { data, loading, error, reload } = useAsync(
    () => admin.blacklistedOwners({ q: query || undefined, page, per_page: 20 }),
    [query, page, refreshKey],
  )

  const rows = data?.data ?? []
  const total = data?.meta.total ?? 0
  const lastPage = data?.meta.last_page ?? 1

  if (loading) return <SkeletonList rows={4} />
  if (error) return <ErrorState error={error} onRetry={reload} />

  if (rows.length === 0) {
    return (
      <EmptyState
        icon={BuildingIcon}
        title={query ? 'Nobody blacklisted matches your search' : 'Nobody is blacklisted'}
        description={
          query
            ? 'Try another owner, email or business name.'
            : 'An owner appears here when one of their businesses is set to Blacklisted. The bar covers every business registered to them.'
        }
      />
    )
  }

  return (
    <div className="space-y-4">
      <p role="status" className="text-sm text-ink-muted">
        {total.toLocaleString()} {total === 1 ? 'owner is' : 'owners are'} barred from filing
        {query && ' and match your search'}. Each one&apos;s businesses are listed with them.
      </p>

      {rows.map((owner) => (
        <ProtoCard key={owner.id} className="overflow-hidden rounded-xl">
          {/*
            A red left edge rather than a red card. The sanction is the
            heading's business; tinting the whole surface would drown the
            reason and the list, which are what an admin came to read.
          */}
          <div className="border-l-4 border-s-red px-5 py-4">
            <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-2">
              <div className="min-w-0">
                <h3 className="text-base font-bold text-ink">{owner.name}</h3>
                <p className="mt-0.5 text-xs text-ink-muted">
                  {owner.email}
                  {owner.mobile_number && <span className="tnum"> · {owner.mobile_number}</span>}
                </p>
              </div>
              <StatusChip tone="tint-red">Blacklisted</StatusChip>
            </div>

            <dl className="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-3">
              <div className="sm:col-span-3">
                <dt className="text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  Reason
                </dt>
                {/*
                  The reason in full, not truncated. It is the one field that
                  has to survive being read back to an owner on the phone, and
                  a card that ends it in an ellipsis makes the admin open the
                  audit log to finish a sentence they are already reading.
                */}
                <dd className="mt-0.5 text-sm text-ink">
                  {owner.reason ?? (
                    <span className="italic text-ink-muted">
                      No reason on record — this bar predates the register keeping one.
                    </span>
                  )}
                </dd>
              </div>
              <div>
                <dt className="text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  Blacklisted
                </dt>
                <dd className="mt-0.5 text-sm text-ink">{formatDate(owner.blacklisted_at)}</dd>
              </div>
              <div>
                <dt className="text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  By
                </dt>
                <dd className="mt-0.5 text-sm text-ink">
                  {/* A sanction nobody signed is one nobody can follow up. */}
                  {owner.blacklisted_by ?? <span className="italic text-ink-muted">Not recorded</span>}
                </dd>
              </div>
              <div>
                <dt className="text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  Businesses barred
                </dt>
                <dd className="tnum mt-0.5 text-sm font-bold text-ink">
                  {owner.businesses.length}
                </dd>
              </div>
            </dl>
          </div>

          <div className="border-t border-line bg-canvas/40 px-5 py-3">
            <p className="text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
              Registered to {owner.name}
            </p>
            <ul className="mt-2 divide-y divide-line/70">
              {owner.businesses.map((business) => {
                /*
                  The card's business list carries enough to open either
                  dialog, but not a whole roster row — the blacklisted-owners
                  payload has no tracking id, no fee breakdown, no
                  registration date, because a sanctions register has no use
                  for them.

                  So the object handed to the dialogs is built here and is
                  honestly partial: `created_at` empty and the optional fields
                  absent. ChangeStatusModal reads the id, the name, the status
                  and the owner; HistoryModal reads the id and the name. Both
                  are here. Inventing a tracking id to satisfy the type would
                  be worse than leaving it out.
                */
                const asRow: AdminBusiness = {
                  id: business.id,
                  name: business.name,
                  status: business.status,
                  status_label: business.status_label,
                  owner: { id: owner.id, name: owner.name, blacklisted: true },
                  created_at: '',
                }

                return (
                  <li
                    key={business.id}
                    className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-2.5"
                  >
                    <span className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                      <span className="text-sm font-semibold text-ink">{business.name}</span>
                      <StatusChip tone={STATUS_META[business.status]?.tone ?? 'tint-gray'}>
                        {business.status_label}
                      </StatusChip>
                      {business.registered_after && (
                        /*
                          The one row that needs explaining. A business
                          registered AFTER the bar was imposed was never
                          touched by the cascade, so its own status reads
                          Active while every filing attempt is refused. Left
                          unsaid, an admin reading "Active" under a
                          blacklisted owner would reasonably conclude the bar
                          had a hole in it.
                        */
                        <span className="rounded-full bg-s-yellow-tint px-2.5 py-0.5 text-[11px] font-semibold text-amber-800">
                          Registered after the bar — barred by the owner&apos;s blacklisting
                        </span>
                      )}
                    </span>

                    {/*
                      The same two acts every other row on this screen offers,
                      and the same words for them [client, 27 September 2026:
                      *"lalagayan mo pa ng change status, at view status
                      history tulad sa iba"*].

                      Without them this card was read-only, so lifting a bar
                      meant going back to the Businesses pill and finding the
                      row again — on the one screen where the reader is already
                      looking straight at it.
                    */}
                    <button
                      type="button"
                      onClick={() => onHistory(asRow)}
                      className="shrink-0 rounded-full border border-line bg-white px-4 py-1.5 text-xs font-semibold text-ink-secondary hover:bg-canvas"
                      aria-label={`Status history for ${business.name}`}
                    >
                      View Status History
                    </button>
                  </li>
                )
              })}
            </ul>

            {/*
              ── One way back, for the whole account ──────────────────────

              The bar went on as one act and it comes off as one. A button per
              business would let an administrator free one shopfront and leave
              the owner barred — a register that disagrees with itself, and an
              owner refused a filing on a business the screen calls Active
              [client, 28 September 2026: *"hindi pwedeng isahang business lang
              ang mamomodify mo tas yung iba naka tag pa rin sa blacklisted"*].
            */}
            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3">
              <p className="text-xs text-ink-muted">
                Releasing {owner.name} moves{' '}
                {owner.businesses.length === 1
                  ? 'their business'
                  : `all ${owner.businesses.length} of their businesses`}{' '}
                together.
              </p>
              <button
                type="button"
                onClick={() => onRelease(owner)}
                className="shrink-0 rounded-full border border-transparent bg-royal px-4 py-1.5 text-xs font-semibold text-white hover:bg-royal-hover"
                aria-label={`Change the status of ${owner.name} and their businesses`}
              >
                Change Status
              </button>
            </div>
          </div>
        </ProtoCard>
      ))}

      {lastPage > 1 && (
        <div className="flex items-center justify-end gap-1.5">
          <button
            type="button"
            aria-label="Previous page"
            onClick={() => setPage((p) => Math.max(1, p - 1))}
            aria-disabled={page <= 1 || undefined}
            className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
          >
            ‹
          </button>
          <span className="text-xs text-ink-muted">
            Page {page.toLocaleString()} of {lastPage.toLocaleString()}
          </span>
          <button
            type="button"
            aria-label="Next page"
            onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
            aria-disabled={page >= lastPage || undefined}
            className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
          >
            ›
          </button>
        </div>
      )}
    </div>
  )
}

/** Rows per request. The roster is 705 businesses and grows with the city. */
const PAGE_SIZE = 25

/**
 * The roster filter: every status, exactly one, or the retired businesses.
 *
 * Retired — removed from the register — is last and on its own because it is
 * not a status: "All" means every business still on the register, and a
 * retired one is listed only when asked for (checklist item 21).
 */
type StatusFilter = 'all' | BusinessStatus | 'retired'

const STATUS_FILTERS: { value: StatusFilter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'flagged', label: 'Flagged' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'blacklisted', label: 'Blacklisted' },
  { value: 'retired', label: 'Retired' },
]

/**
 * The orders this roster can be read in.
 *
 * ── Named by the question each one answers ────────────────────────────────
 *
 * Not "created_at desc". Every label is a sentence an admin would say out
 * loud — "who owes the most" is why somebody sorts by fees, and "sort by
 * unbilled_fees descending" is the same instruction with the reason taken out.
 *
 * The `value` carries both halves, split on the way to the query, so the menu
 * can offer a column in one direction only where the other direction answers
 * nothing: nobody wants the smallest debt first.
 */
const SORT_OPTIONS = [
  { value: 'registered:desc', label: 'Newest registration' },
  { value: 'registered:asc', label: 'Oldest registration' },
  { value: 'name:asc', label: 'Business name A–Z' },
  { value: 'name:desc', label: 'Business name Z–A' },
  { value: 'owner:asc', label: 'Owner name A–Z' },
  { value: 'fees:desc', label: 'Owes the most' },
  { value: 'status_changed:desc', label: 'Status changed most recently' },
]

/*
 * ── What the Filter panel holds, and what it deliberately does not ────────
 *
 * NOT the status. Active / Flagged / Suspended / Blacklisted are pills, on
 * their own row, one press away — repeating them inside a menu would give the
 * screen two controls for one question, which then have to be kept agreeing
 * with each other [client, 27 September 2026, pointing at the pills].
 *
 * What is left is the three facts an admin acts on that a status does not
 * carry, and the one span of time they are usually asked about:
 *
 *   MONEY      Who owes us something. The commonest reason to open this page
 *              when nobody is being sanctioned, so it takes the main slot.
 *   THE OWNER  Whether the bar is the PERSON's. A blacklisting reaches every
 *              business they hold, so most blacklisted rows are not being
 *              judged on their own conduct — this separates the two.
 *   PAPERWORK  A registration nothing was ever filed against.
 *   REGISTERED A date range. "Everything registered this quarter" is the shape
 *              of half the questions asked of this register, and sorting by
 *              date does not answer it — it puts the quarter at the top of
 *              seven hundred rows and leaves the reader to find where it ends.
 *
 * The first option of each is the neutral one, which is the convention
 * `SortFilter` relies on to decide whether to colour the Filter button.
 */
const FEE_OPTIONS = [
  { value: '', label: 'Any fees' },
  { value: 'owing', label: 'Has unbilled fees' },
  { value: 'clear', label: 'Nothing outstanding' },
]

const OWNER_OPTIONS = [
  { value: '', label: 'Any owner' },
  { value: '1', label: 'Owner is blacklisted' },
  { value: '0', label: 'Owner is not blacklisted' },
]

const FILED_OPTIONS = [
  { value: '', label: 'Filed or not' },
  { value: 'yes', label: 'Has filed at least once' },
  { value: 'never', label: 'Never filed' },
]

export function OwnersPage() {
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState<StatusFilter>('all')
  const [page, setPage] = useState(1)
  const [modal, setModal] = useState<ModalState>(null)

  /*
   * The order and the two extra narrowings, both asked of the server.
   *
   * Browser-side would be wrong for the same reason the search is not: this
   * list is paged at twenty-five out of seven hundred, so sorting what happens
   * to be on screen would put the largest debt on page one of the rows already
   * fetched, and call it the largest debt in the city.
   */
  const [sort, setSort] = useState('registered:desc')
  const [ownerFilter, setOwnerFilter] = useState('')
  const [feeFilter, setFeeFilter] = useState('')
  const [filedFilter, setFiledFilter] = useState('')
  const [registeredFrom, setRegisteredFrom] = useState('')
  const [registeredTo, setRegisteredTo] = useState('')

  /*
   * -- The Blacklisted pill shows PEOPLE --------------------------------
   *
   * Everywhere else on this screen a row is a shopfront. A blacklisting is a
   * finding against the owner and reaches every business they hold, so listing
   * it as shopfronts gives three rows repeating one name with no room for the
   * reason, the date or who signed it. Same pill, same place in the row; a
   * different thing under it, because it is a different question.
   */
  const showingOwners = status === 'blacklisted'

  /*
   * A status change can empty a card, so the register of people has to be told
   * one happened. It fetches separately from the business roster — see the
   * note on the prop.
   */
  const [ownersRefresh, setOwnersRefresh] = useState(0)

  /*
   * Searched and paged on the server. Both used to happen in the browser over
   * the whole roster, which meant every visit pulled all 705 rows and rendered
   * all 705 — and now that /admin/businesses is paged, a browser-side search
   * would only ever have looked at the 50 rows it happened to hold while the
   * footer called that the whole roster.
   */
  const { data, loading, error, reload, setData } = useAsync(
    () => {
      /*
       * One string in state, two parameters on the wire. The menu needs a
       * single value to tick and the endpoint takes a column and a direction,
       * so the split happens here — at the boundary, rather than by keeping
       * two pieces of state that can disagree with each other.
       */
      const [by, dir] = sort.split(':') as [
        NonNullable<AdminBusinessFilters['sort']>,
        'asc' | 'desc',
      ]
      return admin.businessesPage({
        q: query || undefined,
        // The endpoint has always accepted this and nothing ever sent it, so
        // "show me the suspended ones" meant paging the whole register by eye.
        status: status === 'all' ? undefined : status,
        sort: by,
        dir,
        owner_blacklisted: ownerFilter === '' ? undefined : ownerFilter === '1',
        fees: (feeFilter || undefined) as 'owing' | 'clear' | undefined,
        filed: (filedFilter || undefined) as 'yes' | 'never' | undefined,
        registered_from: registeredFrom || undefined,
        registered_to: registeredTo || undefined,
        page,
        per_page: PAGE_SIZE,
      })
    },
    [
      query,
      status,
      sort,
      ownerFilter,
      feeFilter,
      filedFilter,
      registeredFrom,
      registeredTo,
      page,
    ],
  )

  // Let the admin finish typing before asking the server.
  useEffect(() => {
    const id = window.setTimeout(() => {
      setQuery(search.trim())
      setPage(1)
    }, 300)
    return () => window.clearTimeout(id)
  }, [search])

  const rows = data?.data ?? []
  const total = data?.meta.total ?? 0
  const lastPage = data?.meta.last_page ?? 1

  /**
   * Fold a status change back into the row it came from.
   *
   * Merged, not replaced. POST /admin/businesses/{id}/status answers with only
   * `{ id, status, status_label }` — no name, no owner, no created_at — so
   * swapping the whole row in blanked the Business column and turned Owner into
   * "—" the moment an admin changed a status. Typing in the search box then took
   * the page down on `r.name.toLowerCase()`. Keeping the fields the response
   * does not carry is correct whatever the endpoint returns.
   */
  function applyChange(updated: AdminBusiness) {
    /*
     * -- One press can move rows nobody clicked -------------------------
     *
     * Blacklisting an owner blacklists every business they hold, and lifting
     * it brings them all back. Patching only the clicked row would leave the
     * roster showing two of the three as Active while the endpoint refuses
     * every filing for them - a screen quietly disagreeing with the register
     * it is a view of, and the admin's only clue would be a reload they had
     * no reason to perform.
     *
     * So: patch when the change was local, refetch when it reached further.
     * The reply says which (`others_blacklisted` / `others_restored`), which
     * is why those fields exist.
     */
    const reached = (updated.others_blacklisted ?? 0) + (updated.others_restored ?? 0) > 0

    setModal(null)
    // Always, whether or not it reached further: the change may have been the
    // one that put this owner on the sanctions book, or took them off it.
    setOwnersRefresh((n) => n + 1)

    if (reached) {
      reload()
      return
    }

    /*
     * Merged, not replaced. POST /admin/businesses/{id}/status answers with a
     * handful of fields - no name, no owner, no created_at - so swapping the
     * whole row in blanked the Business column and turned Owner into an
     * em dash the moment an admin changed a status.
     */
    setData((prev) =>
      prev
        ? {
            ...prev,
            data: prev.data.map((r) =>
              r.id === updated.id
                ? {
                    ...r,
                    ...updated,
                    // The owner object is not in the reply, so its own
                    // blacklisted flag has to be carried across by hand.
                    owner: r.owner
                      ? { ...r.owner, blacklisted: updated.owner_blacklisted ?? r.owner.blacklisted }
                      : r.owner,
                  }
                : r,
            ),
          }
        : prev!,
    )
  }

  return (
    <div>
      <PageTitle
        right={
          <span className="flex flex-wrap items-center gap-x-4 gap-y-2 pb-1">
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={
                showingOwners ? 'Search owner, email or business…' : 'Search business or owner…'
              }
              aria-label={
                showingOwners ? 'Search blacklisted owners' : 'Search businesses or owners'
              }
              className="w-56 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
          </span>
        }
      >
        Business Owner Status
      </PageTitle>

      {/*
        A real status filter, replacing the decorative Sort/Filter pair. The
        whole point of this screen is finding the businesses under sanction, and
        they were indistinguishable from the 700 that are not without paging the
        register and reading chips.

        The Sort and Filter menus sit on the same line, to its right: the pills
        answer "which state", and the menus answer "in what order" and "owing
        or not" — three questions about one list, so they belong together
        rather than stacked into three rows of controls.

        They are hidden while the Blacklisted pill is showing people. Ordering
        by "Newest registration" means nothing on a list of owners, and a
        control that stays on screen doing nothing is worse than one that steps
        out of the way.
      */}
      <div className="mb-5 flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
        <FilterPills
          options={STATUS_FILTERS}
          value={status}
          onChange={(next) => {
            setStatus(next)
            setPage(1)
          }}
        />

        {!showingOwners && (
          <SortFilter
            sort={{
              value: sort,
              options: SORT_OPTIONS,
              onChange: (next) => {
                setSort(next)
                setPage(1)
              },
            }}
            filter={{
              value: feeFilter,
              options: FEE_OPTIONS,
              onChange: (next) => {
                setFeeFilter(next)
                setPage(1)
              },
            }}
            filterFields={[
              {
                label: 'Owner',
                value: ownerFilter,
                options: OWNER_OPTIONS,
                onChange: (next: string) => {
                  setOwnerFilter(next)
                  setPage(1)
                },
              },
              {
                label: 'Filings',
                value: filedFilter,
                options: FILED_OPTIONS,
                onChange: (next: string) => {
                  setFiledFilter(next)
                  setPage(1)
                },
              },
            ]}
            dateRange={{
              from: registeredFrom,
              to: registeredTo,
              onChange: (from, to) => {
                setRegisteredFrom(from)
                setRegisteredTo(to)
                setPage(1)
              },
            }}
          />
        )}
      </div>

      {showingOwners ? (
        <BlacklistedOwners
          query={query}
          refreshKey={ownersRefresh}
          onRelease={(owner) => setModal({ kind: 'release', owner })}
          onHistory={(business) => setModal({ kind: 'history', row: business })}
        />
      ) : (
        <>
      {loading ? (
        <SkeletonList rows={7} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={BuildingIcon}
          title={
            search
              ? 'No businesses match your search'
              : status === 'retired'
                ? 'No retired businesses'
                : 'No registered businesses yet'
          }
          description={
            search
              ? 'Try another business or owner name.'
              : status === 'retired'
                ? 'A business is listed here once it has been removed from the register.'
                : 'Businesses appear here as owners register and apply for permits.'
          }
        />
      ) : (
        <ProtoCard className="overflow-hidden rounded-xl">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[44rem] text-left text-sm">
              <thead>
                <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  <th className="px-5 py-3">Business</th>
                  <th className="px-5 py-3">Owner</th>
                  <th className="px-5 py-3">Status</th>
                  {/*
                    Deferred permit fees. Client's decision, 17 September 2026:
                    a clearance renewed outside January is issued unbilled and
                    its fee waits for the next business permit renewal — *"they
                    wait indefinitely, and are visible"*. This is the visible
                    half; the other half is already on the renewal's Tax Order
                    of Payment.

                    Right-aligned, like every money column: the digits line up
                    and a reader scanning for the largest debt does it by eye
                    rather than by reading each one.
                  */}
                  <th className="px-5 py-3 text-right">Unbilled fees</th>
                  <th className="px-5 py-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const retired = Boolean(row.retired_at)
                  const meta = retired
                    ? { label: 'Retired', tone: 'tint-gray' as ChipTone }
                    : (STATUS_META[row.status] ?? { label: row.status_label, tone: 'tint-gray' as ChipTone })
                  return (
                    <tr key={row.id} className="border-t border-line">
                      <td className="px-5 py-3.5">
                        <span className="block font-bold text-ink">{row.name}</span>
                        {/*
                          * The number under the name, because this is the
                          * screen where an admin suspends somebody's
                          * livelihood and "which of these is the one the
                          * complaint is about" must not be answered by a name
                          * alone — six rows, two owners, names that share a
                          * word.
                          *
                          * The BAN joins it only when the business has one: it
                          * is the reference a restriction quotes back, so an
                          * owner ringing to ask why they were suspended is
                          * reading out a number this row can be found by.
                          *
                          * Said in words when there is nothing, rather than a
                          * dash: "no number on file" is a fact about the
                          * register, and a dash reads as a value that failed
                          * to load.
                          */}
                        {/*
                          * Name, then number. Nothing else.
                          *
                          * It carried a label and a count — "Latest filing
                          * BIZ-2026-00003 · 2 in total" — because `BIZ-…` is a
                          * FILING's number and a business that renews holds
                          * several, so a bare number can read as the business's
                          * own. The client has seen both and asked for the bare
                          * pair, knowing that: a renewal takes a new number and
                          * this is the newest one.
                          *
                          * Which filing it is remains a real question, and it
                          * is still answered — by the ORDER (latest by
                          * `submitted_at`, not by insertion) rather than by
                          * words on the row.
                          *
                          * "No filing yet" stays in words: a business exists in
                          * the register from the moment it is created, and a
                          * blank line under its name would read as a value that
                          * failed to load.
                          */}
                        <span className="tnum mt-0.5 block text-xs text-ink-muted">
                          {row.tracking_id ?? <span className="italic">No filing yet</span>}
                        </span>
                      </td>
                      <td className="px-5 py-3.5 text-ink-secondary">
                        {row.owner?.name ?? '—'}
                        {row.owner?.blacklisted && (
                          /*
                            Whose bar it is.

                            After a blacklisting cascades, three rows of one
                            owner all read "Blacklisted" with nothing to say
                            they are ONE sanction rather than three - and an
                            admin looking for the finding would have opened
                            three status histories to learn it.
                          */
                          <span className="mt-0.5 block text-xs font-semibold text-s-red">
                            Owner blacklisted
                          </span>
                        )}
                      </td>
                      <td className="px-5 py-3.5">
                        <StatusChip tone={meta.tone}>{meta.label}</StatusChip>
                      </td>
                      <td className="px-5 py-3.5 text-right">
                        {/*
                          Three states, and they are genuinely different:
                          the key absent (an older payload — say nothing rather
                          than claim zero), a zero (nothing deferred, and the
                          dash reads faster than ₱0.00 down a column of them),
                          and a real debt.
                        */}
                        {row.unbilled_fees === undefined ? (
                          <span className="text-ink-muted">—</span>
                        ) : row.unbilled_fees.total === 0 ? (
                          /*
                            "None", not an em-dash.

                            The client has objected to this exact shape once
                            already, on the permit register: "'—' bat may ganyan
                            pa sa holding, kung wala, it should be automatic na
                            'nothing'" [27 September 2026]. A dash is a
                            typographic shrug — it reads as "no data" as easily
                            as "nothing owed", and a column of them tells a
                            reader nothing either way. The word says which.
                          */
                          <span className="text-ink-muted">None</span>
                        ) : (
                          <button
                            type="button"
                            onClick={() => setModal({ kind: 'fees', row })}
                            className="tnum font-bold text-royal underline underline-offset-2 hover:text-royal-hover"
                            aria-label={`What ${row.name} owes in unbilled permit fees`}
                          >
                            {formatMoney(row.unbilled_fees.total)}
                          </button>
                        )}
                      </td>
                      <td className="px-5 py-3.5">
                        {retired ? (
                          /*
                            No buttons on a retired row. Every action here binds
                            the business, and binding skips removed rows, so
                            each would answer 404 — a control that can only
                            fail. Said in words instead of left blank.
                          */
                          <span className="text-xs text-ink-muted">
                            Removed from the register on {formatDate(row.retired_at ?? null)}
                          </span>
                        ) : (
                        <div className="flex items-center gap-2">
                          <button
                            type="button"
                            onClick={() => setModal({ kind: 'change', row })}
                            /*
                              Royal, not red.

                              Every row carried a red Change Status button -
                              seven hundred of them, on a register that is
                              almost entirely businesses trading normally. Red
                              is this app's one "stop" signal (DESIGN.md), and
                              spending it on the control that OPENS a dialog
                              leaves nothing to say with when the dialog is
                              about to suspend somebody. The act may be red;
                              the door to it is not.

                              Transparent border, not no border: its outlined
                              neighbour carries a 1px one, so without this the
                              filled button stands 2px shorter than its row.
                            */
                            className="rounded-full border border-transparent bg-royal px-4 py-1.5 text-xs font-semibold text-white hover:bg-royal-hover"
                            aria-label={`Change the status of ${row.name}`}
                          >
                            Change Status
                          </button>
                          <button
                            type="button"
                            onClick={() => setModal({ kind: 'history', row })}
                            className="rounded-full border border-line bg-white px-4 py-1.5 text-xs font-semibold text-ink-secondary hover:bg-canvas"
                            aria-label={`Status history for ${row.name}`}
                          >
                            View Status History
                          </button>
                        </div>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          <div className="flex items-center justify-between gap-4 border-t border-line px-5 py-3.5">
            <p className="text-sm text-ink-muted">
              Showing {rows.length.toLocaleString()} of {total.toLocaleString()} businesses
              {query && ' matching your search'}
            </p>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                aria-label="Previous page"
                /*
                 * aria-disabled, never the native attribute (§6.2): a disabled
                 * control leaves the tab order, so a keyboard reader loses the
                 * pager entirely at either end of the list rather than being
                 * told it has reached one. The handler needs no guard — it
                 * already clamps to page 1.
                 */
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                aria-disabled={page <= 1 || loading || undefined}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
              >
                ‹
              </button>
              <span className="text-xs text-ink-muted">
                Page {page.toLocaleString()} of {lastPage.toLocaleString()}
              </span>
              <button
                type="button"
                aria-label="Next page"
                // Clamped to lastPage, so pressing it on the last page does
                // nothing — see the note on Previous.
                onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                aria-disabled={page >= lastPage || loading || undefined}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
              >
                ›
              </button>
            </div>
          </div>
        </ProtoCard>
      )}
        </>
      )}

      {modal?.kind === 'change' && (
        <ChangeStatusModal
          row={modal.row}
          onClose={() => setModal(null)}
          onChanged={applyChange}
        />
      )}
      {modal?.kind === 'release' && (
        <ReleaseOwnerModal
          owner={modal.owner}
          onClose={() => setModal(null)}
          onReleased={() => {
            setModal(null)
            setOwnersRefresh((n) => n + 1)
            reload()
          }}
        />
      )}
      {modal?.kind === 'history' && <HistoryModal row={modal.row} onClose={() => setModal(null)} />}
      {modal?.kind === 'fees' && <FeesModal row={modal.row} onClose={() => setModal(null)} />}
    </div>
  )
}

/* ── Releasing a blacklisted owner ────────────────────────────────────── */

/** The three a released business can land on. Blacklisted is not one: they are. */
const RELEASE_TO: { value: 'active' | 'flagged' | 'suspended'; label: string; says: string }[] = [
  {
    value: 'active',
    label: 'Active',
    says: 'They can file and renew again, and their suspended permits are restored.',
  },
  {
    value: 'flagged',
    label: 'Flagged',
    says: 'A note to watch them. Nothing is blocked — they can file, and their permits are restored.',
  },
  {
    value: 'suspended',
    label: 'Suspended',
    says: 'Still barred from filing, and their permits stay suspended — but the blacklisting is lifted.',
  },
]

/**
 * Lift a blacklisting from the owner, and move everything they hold together.
 *
 * ── Why the whole account, and why it asks ───────────────────────────────
 *
 * The card used to offer Change Status per business, which let an
 * administrator free one shopfront and leave the owner barred — a register
 * disagreeing with itself. The bar went on as one act, so it comes off as one
 * [client, 28 September 2026].
 *
 * That makes it a bigger act than the per-business one it replaces, which is
 * exactly why it is confirmed: it names the count, the destination and every
 * business it will move before anything is written.
 */
function ReleaseOwnerModal({
  owner,
  onClose,
  onReleased,
}: {
  owner: BlacklistedOwner
  onClose: () => void
  onReleased: () => void
}) {
  const [status, setStatus] = useState<'active' | 'flagged' | 'suspended'>('active')
  const [reasonCode, setReasonCode] = useState('')
  const [details, setDetails] = useState('')
  const [review, setReview] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const chosen = RELEASE_TO.find((o) => o.value === status)!
  const count = owner.businesses.length

  async function confirm() {
    setBusy(true)
    setError(null)
    try {
      const reason = [reasonCode, details.trim()].filter(Boolean).join(' · ')
      await admin.liftOwnerBlacklist(owner.id, status, reason)
      onReleased()
    } catch (err) {
      // The dialog stays open carrying the message: closing it would put the
      // reader back on the card with no sign their typing survived.
      setError(toApiError(err).message)
      setReview(false)
      setBusy(false)
    }
  }

  return (
    <ProtoModal
      title={review ? `Release ${owner.name}?` : 'Change Status'}
      /*
        BLUE, not red. DESIGN.md keeps red for acts that stop somebody
        trading; every option here either frees this owner or leaves them
        exactly as barred as they already are. Lifting a sanction is not a
        danger, and colouring it as one spends the alarm on good news.
      */
      tone="blue"
      cancelLabel={review ? 'Back' : 'Cancel'}
      confirmLabel={review ? (busy ? 'Saving…' : `Set all to ${chosen.label}`) : 'Review this change'}
      onCancel={review ? () => setReview(false) : onClose}
      onConfirm={review ? confirm : () => setReview(true)}
      confirmDisabled={busy || (!review && !reasonCode)}
      confirmDescribedBy={!review && !reasonCode ? 'release-blocker' : undefined}
    >
      <p className="mb-5 border-b border-line pb-3 text-sm text-ink-secondary">{owner.name}</p>

      {review ? (
        <div className="space-y-4">
          <p className="text-sm text-ink">
            The blacklisting is lifted from <span className="font-bold">{owner.name}</span>, and{' '}
            <span className="font-bold">
              {count === 1 ? 'their business' : `all ${count} of their businesses`}
            </span>{' '}
            move to <span className="font-bold">{chosen.label}</span>.
          </p>

          <p className="rounded-lg border border-line bg-canvas px-4 py-3 text-sm text-ink-secondary">
            {chosen.says}
          </p>

          {/* Named, so the reader can check they are releasing who they meant. */}
          <ul className="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-line px-4 py-3">
            {owner.businesses.map((b) => (
              <li key={b.id} className="text-sm text-ink">
                {b.name}
              </li>
            ))}
          </ul>

          <p className="text-xs text-ink-muted">
            Written to each business's status history, and {owner.name} is notified once — one
            piece of news, not one per business.
          </p>

          {error && (
            <p role="alert" className="rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
              {error}
            </p>
          )}
        </div>
      ) : (
        <div className="space-y-4">
          <label className="block">
            <FieldLabel required>Move all their businesses to</FieldLabel>
            <select
              className={inputCls}
              value={status}
              onChange={(e) => setStatus(e.target.value as typeof status)}
            >
              {RELEASE_TO.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
            {/*
              Blacklisted is absent on purpose, and saying so stops a reader
              hunting for it: they already are, and re-applying it would only
              re-date a sanction that is already in force.
            */}
            <p className="mt-1 text-xs text-ink-muted">{chosen.says}</p>
          </label>

          <label className="block">
            <FieldLabel required>Reason code</FieldLabel>
            <select
              className={inputCls}
              value={reasonCode}
              onChange={(e) => setReasonCode(e.target.value)}
            >
              <option value="">Select reason…</option>
              {REASON_CODES.map((r) => (
                <option key={r}>{r}</option>
              ))}
            </select>
          </label>

          <label className="block">
            <FieldLabel>Details</FieldLabel>
            <textarea
              rows={3}
              className={`${inputCls} min-h-20`}
              placeholder="Describe the basis for lifting this"
              value={details}
              onChange={(e) => setDetails(e.target.value)}
            />
          </label>

          {!reasonCode && (
            <p id="release-blocker" className="text-xs text-ink-muted">
              Choose a reason code — it goes on the record and the owner is shown it.
            </p>
          )}

          {error && (
            <p role="alert" className="rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
              {error}
            </p>
          )}
        </div>
      )}
    </ProtoModal>
  )
}
