import { useEffect, useMemo, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { CalendarIcon, PlusIcon, UsersIcon, XIcon } from '../../components/icons'
import { Modal } from '../../components/ui/Modal'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { PageTitle, ProtoCard, StatusChip } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { formatCalendarDate } from '../../lib/format'
import { inspectors as inspectorsApi } from '../../lib/resources'
import type { InspectorInput } from '../../lib/resources'
import type { InspectorPoolMeta, PoolInspector } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'

/*
 * ── Inspectors: the office's pool ───────────────────────────────────────────
 *
 * [Client, 27 October 2026: "create an inspector pool for inspector
 * assignment … pagsasamahin na sa isang inspector kung ano ano ang
 * magkakalapit na barangay … o kaya sa isang barangay lang".]
 *
 * One card per inspector: the barangays they cover, the days they go out,
 * how many visits a day, and when they are away. "Divide the barangays"
 * proposes a split of all 21 among the active inspectors with neighbours
 * together (BarangayAreas on the server), shown first and applied on a
 * second press; a barangay can then be moved by hand on the inspector's
 * card. The booking on Track's For Inspection reads this pool to suggest
 * who goes.
 *
 * An inspector need not have an account — that is the whole reason the pool
 * is its own list (client, 5 October 2026) — so linking one to an account is
 * optional and only offered for the office's own staff.
 */

const DAYS = [
  { n: 1, short: 'Mon' },
  { n: 2, short: 'Tue' },
  { n: 3, short: 'Wed' },
  { n: 4, short: 'Thu' },
  { n: 5, short: 'Fri' },
  { n: 6, short: 'Sat' },
]

function dayWords(days: number[]): string {
  const office = [1, 2, 3, 4, 5]
  if (office.every((d) => days.includes(d)) && days.length === 5) return 'Mon–Fri'
  return DAYS.filter((d) => days.includes(d.n))
    .map((d) => d.short)
    .join(', ')
}

type Proposal = { id: number; name: string; barangay_ids: number[]; barangays: string[] }[]

export function InspectorsPage() {
  const { data, loading, error, reload, setData } = useAsync(() => inspectorsApi.list(), [])
  const [editing, setEditing] = useState<PoolInspector | 'new' | null>(null)
  const [leaveFor, setLeaveFor] = useState<PoolInspector | null>(null)
  const [proposal, setProposal] = useState<Proposal | null>(null)
  const [busy, setBusy] = useState(false)
  const [note, setNote] = useState<string | null>(null)
  const [fail, setFail] = useState<string | null>(null)

  const rows = data?.data ?? []
  const meta = data?.meta
  const canEdit = meta?.can_edit ?? false
  const active = rows.filter((r) => r.is_active)
  const uncovered = (meta?.barangays ?? []).filter((b) => !b.covered)

  async function run(work: () => Promise<unknown>, done: string) {
    setBusy(true)
    setFail(null)
    try {
      await work()
      setNote(done)
      await reload()
    } catch (err) {
      setFail(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <PageTitle
        right={
          canEdit ? (
            <span className="flex flex-wrap gap-2 pb-1">
              <button
                type="button"
                onClick={() =>
                  run(
                    async () => setProposal(await inspectorsApi.suggestAreas()),
                    'Here is a proposed division. Apply it, or close.',
                  )
                }
                aria-disabled={busy || active.length === 0}
                className="rounded-full border border-line bg-white px-4 py-1.5 text-sm font-semibold text-ink hover:border-royal hover:text-royal aria-disabled:opacity-50"
              >
                Divide the barangays
              </button>
              <button
                type="button"
                onClick={() => setEditing('new')}
                className="inline-flex items-center gap-1.5 rounded-full bg-royal px-4 py-1.5 text-sm font-semibold text-white hover:bg-royal-hover"
              >
                <PlusIcon size={16} aria-hidden="true" /> Add inspector
              </button>
            </span>
          ) : null
        }
      >
        Inspectors
      </PageTitle>

      {meta && (
        <p className="-mt-3 mb-5 text-sm text-ink-secondary">
          {meta.department.name}
          {' · '}
          {active.length.toLocaleString()} active inspector{active.length === 1 ? '' : 's'}
          {uncovered.length > 0 ? (
            <span className="text-amber-800">
              {' · '}
              {uncovered.length} barangay{uncovered.length === 1 ? '' : 's'} nobody covers:{' '}
              {uncovered.map((b) => b.name).join(', ')}
            </span>
          ) : rows.length > 0 ? (
            <span className="text-s-green"> · every barangay is covered</span>
          ) : null}
        </p>
      )}

      {(note || fail) && (
        <p
          role="status"
          className={`mb-4 rounded-lg px-4 py-2.5 text-sm ${fail ? 'bg-s-red-tint text-s-red' : 'bg-s-green-tint text-s-green'}`}
        >
          {fail ?? note}
        </p>
      )}

      {loading && !data ? (
        <SkeletonList rows={4} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={UsersIcon}
          title="No inspectors yet"
          description="Add the people who inspect for your office. Booking a visit will then suggest who goes, by barangay and by who is free."
        />
      ) : (
        <ul className="grid gap-4 md:grid-cols-2">
          {rows.map((i) => (
            <InspectorCard
              key={i.id}
              inspector={i}
              canEdit={canEdit}
              onEdit={() => setEditing(i)}
              onLeave={() => setLeaveFor(i)}
              onRemoveLeave={(leaveId) =>
                run(async () => {
                  const next = await inspectorsApi.removeLeave(i.id, leaveId)
                  setData((d) => ({
                    data: (d?.data ?? []).map((r) => (r.id === next.id ? next : r)),
                    meta: d?.meta ?? meta!,
                  }))
                }, 'Leave removed.')
              }
              onToggleActive={() =>
                run(
                  () => inspectorsApi.update(i.id, { is_active: !i.is_active }),
                  i.is_active ? `${i.name} is now inactive and will not be suggested.` : `${i.name} is active again.`,
                )
              }
              onRemove={() => {
                if (window.confirm(`Remove ${i.name} from the pool? Past visits keep the name.`)) {
                  run(() => inspectorsApi.remove(i.id), `${i.name} removed from the pool.`)
                }
              }}
            />
          ))}
        </ul>
      )}

      {editing && meta && (
        <InspectorDialog
          inspector={editing === 'new' ? null : editing}
          barangays={meta.barangays}
          onClose={() => setEditing(null)}
          onSaved={(saved, created) => {
            setEditing(null)
            setNote(created ? `${saved.name} added to the pool.` : `${saved.name} updated.`)
            reload()
          }}
        />
      )}

      {leaveFor && (
        <LeaveDialog
          inspector={leaveFor}
          onClose={() => setLeaveFor(null)}
          onSaved={() => {
            setLeaveFor(null)
            setNote(`Leave recorded for ${leaveFor.name}.`)
            reload()
          }}
        />
      )}

      <Modal
        open={proposal !== null}
        onClose={() => setProposal(null)}
        title="Divide the barangays"
        description="Neighbouring barangays go to one inspector, shared evenly. Nothing changes until you apply it."
        size="lg"
        footer={
          <>
            <button
              type="button"
              onClick={() => setProposal(null)}
              className="rounded-full border border-line bg-white px-4 py-2 text-sm font-semibold text-ink-secondary hover:bg-canvas"
            >
              Close
            </button>
            <button
              type="button"
              aria-disabled={busy}
              onClick={() =>
                proposal &&
                run(async () => {
                  await inspectorsApi.applyAreas(proposal.map((p) => ({ id: p.id, barangay_ids: p.barangay_ids })))
                  setProposal(null)
                }, 'Barangays divided among your inspectors.')
              }
              className="rounded-full bg-royal px-4 py-2 text-sm font-semibold text-white hover:bg-royal-hover aria-disabled:opacity-60"
            >
              Apply this division
            </button>
          </>
        }
      >
        <ul className="divide-y divide-line">
          {proposal?.map((p) => (
            <li key={p.id} className="py-3">
              <p className="font-semibold text-ink">
                {p.name} <span className="font-normal text-ink-muted">· {p.barangays.length} barangays</span>
              </p>
              <p className="mt-0.5 text-sm text-ink-secondary">{p.barangays.join(', ') || '—'}</p>
            </li>
          ))}
        </ul>
      </Modal>
    </div>
  )
}

function InspectorCard({
  inspector: i,
  canEdit,
  onEdit,
  onLeave,
  onRemoveLeave,
  onToggleActive,
  onRemove,
}: {
  inspector: PoolInspector
  canEdit: boolean
  onEdit: () => void
  onLeave: () => void
  onRemoveLeave: (leaveId: number) => void
  onToggleActive: () => void
  onRemove: () => void
}) {
  const today = new Date().toISOString().slice(0, 10)
  const leaves = i.leaves.filter((l) => l.ends_on >= today)

  return (
    <li>
      <ProtoCard className={`h-full p-5 ${i.is_active ? '' : 'opacity-70'}`}>
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <h2 className="truncate text-base font-bold text-ink">{i.name}</h2>
            <p className="mt-0.5 flex flex-wrap gap-x-2 text-xs text-ink-muted">
              {i.mobile_number && <span className="tnum">{i.mobile_number}</span>}
              {i.user && <span>Account: {i.user.name}</span>}
              <span>
                {dayWords(i.works_days)} · up to {i.max_visits_per_day} visit{i.max_visits_per_day === 1 ? '' : 's'} a
                day
              </span>
            </p>
          </div>
          <StatusChip tone={i.is_active ? 'tint-green' : 'tint-gray'}>{i.is_active ? 'Active' : 'Inactive'}</StatusChip>
        </div>

        <div className="mt-3">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-ink-muted">Covers</p>
          {i.barangays.length === 0 ? (
            <p className="mt-1 text-sm text-amber-800">
              No barangay yet — suggested only as the nearest when nobody covers one.
            </p>
          ) : (
            <ul className="mt-1 flex flex-wrap gap-1.5">
              {i.barangays.map((b) => (
                <li key={b.id} className="rounded-full bg-royal-tint px-2.5 py-0.5 text-xs font-semibold text-royal">
                  {b.name}
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs">
          <span className="text-ink-secondary">
            <b className="tnum font-semibold text-ink">{i.open_visits_count}</b> open visit
            {i.open_visits_count === 1 ? '' : 's'}
          </span>
          {leaves.length > 0 && (
            <ul className="flex flex-wrap gap-1.5">
              {leaves.map((l) => (
                <li
                  key={l.id}
                  className="inline-flex items-center gap-1 rounded-full bg-s-yellow-tint px-2.5 py-0.5 text-amber-800"
                >
                  <CalendarIcon size={12} aria-hidden="true" />
                  Away {formatCalendarDate(l.starts_on)}
                  {l.ends_on !== l.starts_on && ` – ${formatCalendarDate(l.ends_on)}`}
                  {l.reason && ` · ${l.reason}`}
                  {canEdit && (
                    <button
                      type="button"
                      onClick={() => onRemoveLeave(l.id)}
                      aria-label={`Remove this leave of ${i.name}`}
                      className="ml-0.5 hover:text-s-red"
                    >
                      <XIcon size={12} aria-hidden="true" />
                    </button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>

        {canEdit && (
          <div className="mt-4 flex flex-wrap gap-x-4 gap-y-1 border-t border-line pt-3 text-sm">
            <button
              type="button"
              onClick={onEdit}
              aria-label={`Edit ${i.name}`}
              className="font-semibold text-royal hover:underline"
            >
              Edit
            </button>
            <button
              type="button"
              onClick={onLeave}
              aria-label={`Add a leave for ${i.name}`}
              className="font-semibold text-royal hover:underline"
            >
              Add leave
            </button>
            <button
              type="button"
              onClick={onToggleActive}
              aria-label={`${i.is_active ? 'Deactivate' : 'Activate'} ${i.name}`}
              className="font-semibold text-ink-secondary hover:underline"
            >
              {i.is_active ? 'Deactivate' : 'Activate'}
            </button>
            <button
              type="button"
              onClick={onRemove}
              aria-label={`Remove ${i.name} from the pool`}
              className="font-semibold text-s-red hover:underline"
            >
              Remove
            </button>
          </div>
        )}
      </ProtoCard>
    </li>
  )
}

function InspectorDialog({
  inspector,
  barangays,
  onClose,
  onSaved,
}: {
  inspector: PoolInspector | null
  barangays: InspectorPoolMeta['barangays']
  onClose: () => void
  onSaved: (saved: PoolInspector, created: boolean) => void
}) {
  const [name, setName] = useState(inspector?.name ?? '')
  const [mobile, setMobile] = useState(inspector?.mobile_number ?? '')
  const [days, setDays] = useState<number[]>(inspector?.works_days ?? [1, 2, 3, 4, 5])
  const [max, setMax] = useState(inspector?.max_visits_per_day ?? 4)
  const [notes, setNotes] = useState(inspector?.notes ?? '')
  const [picked, setPicked] = useState<number[]>(inspector?.barangays.map((b) => b.id) ?? [])
  const [userId, setUserId] = useState<number | null>(inspector?.user?.id ?? null)
  const [accounts, setAccounts] = useState<{ id: number; name: string }[]>([])
  const [saving, setSaving] = useState(false)
  const [fail, setFail] = useState<string | null>(null)

  useEffect(() => {
    inspectorsApi
      .accounts()
      .then(setAccounts)
      .catch(() => {
        // Linking is optional; without the list it is simply not offered.
      })
  }, [])

  const sorted = useMemo(() => [...barangays].sort((a, b) => a.name.localeCompare(b.name)), [barangays])

  async function submit(e: FormEvent) {
    e.preventDefault()
    if (saving) return
    setSaving(true)
    setFail(null)
    const body: InspectorInput = {
      name: name.trim(),
      mobile_number: mobile.trim() || null,
      works_days: days,
      max_visits_per_day: max,
      notes: notes.trim() || null,
      barangay_ids: picked,
      user_id: userId,
    }
    try {
      const saved = inspector ? await inspectorsApi.update(inspector.id, body) : await inspectorsApi.create(body)
      onSaved(saved, !inspector)
    } catch (err) {
      setFail(toApiError(err).message)
      setSaving(false)
    }
  }

  const formId = 'inspector-form'

  return (
    <Modal
      open
      onClose={onClose}
      title={inspector ? `Edit ${inspector.name}` : 'Add an inspector'}
      size="lg"
      footer={
        <>
          <button
            type="button"
            onClick={onClose}
            className="rounded-full border border-line bg-white px-4 py-2 text-sm font-semibold text-ink-secondary hover:bg-canvas"
          >
            Cancel
          </button>
          <button
            type="submit"
            form={formId}
            aria-disabled={saving || !name.trim()}
            className="rounded-full bg-royal px-4 py-2 text-sm font-semibold text-white hover:bg-royal-hover aria-disabled:opacity-60"
          >
            {saving ? 'Saving…' : inspector ? 'Save' : 'Add to the pool'}
          </button>
        </>
      }
    >
      <form id={formId} onSubmit={submit} className="space-y-4">
        {fail && (
          <p role="alert" className="rounded-lg bg-s-red-tint px-3 py-2 text-sm text-s-red">
            {fail}
          </p>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Full name" htmlFor="insp-name">
            <input
              id="insp-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              maxLength={120}
              required
              className={INPUT}
            />
          </Field>
          <Field label="Mobile (optional)" htmlFor="insp-mobile">
            <input
              id="insp-mobile"
              value={mobile}
              onChange={(e) => setMobile(e.target.value)}
              maxLength={20}
              placeholder="11 digits, starting 09"
              className={`${INPUT} tnum`}
            />
          </Field>
        </div>

        <fieldset>
          <legend className="text-sm text-ink-muted">Goes out on</legend>
          <div className="mt-1 flex flex-wrap gap-1.5">
            {DAYS.map((d) => {
              const on = days.includes(d.n)
              return (
                <label
                  key={d.n}
                  className={`cursor-pointer rounded-full border px-3 py-1 text-sm font-semibold ${on ? 'border-royal bg-royal text-white' : 'border-line bg-white text-ink-secondary'}`}
                >
                  <input
                    type="checkbox"
                    className="sr-only"
                    checked={on}
                    onChange={() => setDays((prev) => (on ? prev.filter((x) => x !== d.n) : [...prev, d.n].sort()))}
                  />
                  {d.short}
                </label>
              )
            })}
          </div>
        </fieldset>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Visits a day, at most" htmlFor="insp-max">
            <input
              id="insp-max"
              type="number"
              min={1}
              max={20}
              value={max}
              onChange={(e) => setMax(Math.max(1, Math.min(20, Number(e.target.value) || 1)))}
              className={`${INPUT} tnum w-24`}
            />
          </Field>
          {accounts.length > 0 && (
            <Field label="Account (optional)" htmlFor="insp-user">
              <select
                id="insp-user"
                value={userId ?? ''}
                onChange={(e) => setUserId(e.target.value ? Number(e.target.value) : null)}
                className={INPUT}
              >
                <option value="">No account — for the record only</option>
                {accounts.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.name}
                  </option>
                ))}
              </select>
            </Field>
          )}
        </div>

        <fieldset>
          <legend className="text-sm text-ink-muted">
            Covers <span className="tnum">{picked.length}</span> of {sorted.length} barangays
          </legend>
          <div className="mt-1 grid grid-cols-2 gap-x-3 gap-y-1 sm:grid-cols-3">
            {sorted.map((b) => {
              const on = picked.includes(b.id)
              return (
                <label key={b.id} className="flex items-center gap-2 text-sm text-ink">
                  <input
                    type="checkbox"
                    checked={on}
                    onChange={() => setPicked((prev) => (on ? prev.filter((x) => x !== b.id) : [...prev, b.id]))}
                    className="h-4 w-4 accent-royal"
                  />
                  <span className={on ? 'font-semibold' : ''}>{b.name}</span>
                  {!b.covered && !on && <span className="text-[10px] text-amber-800">nobody</span>}
                </label>
              )
            })}
          </div>
        </fieldset>

        <Field label="Notes (optional)" htmlFor="insp-notes">
          <input
            id="insp-notes"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            maxLength={500}
            className={INPUT}
          />
        </Field>
      </form>
    </Modal>
  )
}

function LeaveDialog({
  inspector,
  onClose,
  onSaved,
}: {
  inspector: PoolInspector
  onClose: () => void
  onSaved: () => void
}) {
  const today = new Date().toISOString().slice(0, 10)
  const [from, setFrom] = useState(today)
  const [to, setTo] = useState(today)
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)
  const [fail, setFail] = useState<string | null>(null)
  const formId = 'leave-form'

  async function submit(e: FormEvent) {
    e.preventDefault()
    if (saving) return
    setSaving(true)
    setFail(null)
    try {
      await inspectorsApi.addLeave(inspector.id, { starts_on: from, ends_on: to, reason: reason.trim() || null })
      onSaved()
    } catch (err) {
      setFail(toApiError(err).message)
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={`${inspector.name} is away`}
      description="No visit is suggested to them on these days."
      footer={
        <>
          <button
            type="button"
            onClick={onClose}
            className="rounded-full border border-line bg-white px-4 py-2 text-sm font-semibold text-ink-secondary hover:bg-canvas"
          >
            Cancel
          </button>
          <button
            type="submit"
            form={formId}
            aria-disabled={saving}
            className="rounded-full bg-royal px-4 py-2 text-sm font-semibold text-white hover:bg-royal-hover aria-disabled:opacity-60"
          >
            {saving ? 'Saving…' : 'Record the leave'}
          </button>
        </>
      }
    >
      <form id={formId} onSubmit={submit} className="space-y-4">
        {fail && (
          <p role="alert" className="rounded-lg bg-s-red-tint px-3 py-2 text-sm text-s-red">
            {fail}
          </p>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="From" htmlFor="leave-from">
            <input
              id="leave-from"
              type="date"
              value={from}
              min={today}
              onChange={(e) => {
                setFrom(e.target.value)
                if (to < e.target.value) setTo(e.target.value)
              }}
              className={INPUT}
            />
          </Field>
          <Field label="To" htmlFor="leave-to">
            <input
              id="leave-to"
              type="date"
              value={to}
              min={from}
              onChange={(e) => setTo(e.target.value)}
              className={INPUT}
            />
          </Field>
        </div>
        <Field label="Reason (optional)" htmlFor="leave-reason">
          <input
            id="leave-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            maxLength={200}
            placeholder="Leave, training, field duty…"
            className={INPUT}
          />
        </Field>
      </form>
    </Modal>
  )
}

const INPUT =
  'mt-1 w-full rounded-lg border border-input-border bg-input px-3 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal'

function Field({ label, htmlFor, children }: { label: string; htmlFor: string; children: ReactNode }) {
  return (
    <div>
      <label htmlFor={htmlFor} className="block text-sm text-ink-muted">
        {label}
      </label>
      {children}
    </div>
  )
}
