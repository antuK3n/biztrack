import { useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { admin, reference } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { toApiError } from '../../lib/api'
import { formatDateTime } from '../../lib/format'
import type { AdminUser, AdminUserPayload, AuditLog, Department } from '../../lib/types'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import {
  FieldLabel,
  PageTitle,
  ProtoCard,
  ProtoModal,
  SortFilter,
  StatusChip,
  inputCls,
  useDialogKeyboard,
} from '../../components/ui/Proto'
import { UsersIcon } from '../../components/icons'

/*
 * Officer Assignment (PDF p93–98): white table card with avatar rows, Reassign/
 * Edit/Deactivate actions, Details drill-in with an audit timeline, and the
 * Reassigning (visual-only) / Editing / WARNING modals.
 */

const PAGE_SIZE = 7

const ROLE_LABELS: Record<string, string> = {
  business_owner: 'Business owner',
  bplo_staff: 'BPLO Staff',
  sanitary_officer: 'Sanitary Officer',
  fire_inspector: 'Fire Inspector',
  zoning_officer: 'Zoning Officer',
  admin: 'Admin',
}

const CREATABLE_ROLES = ['bplo_staff', 'sanitary_officer', 'fire_inspector', 'admin']

function fullName(u: AdminUser): string {
  return [u.first_name, u.last_name].filter(Boolean).join(' ') + (u.suffix ? ` ${u.suffix}` : '')
}

function initials(u: AdminUser): string {
  return `${u.first_name[0] ?? ''}${u.last_name[0] ?? ''}`.toUpperCase()
}

function roleLabel(u: AdminUser): string {
  return u.roles.map((r) => ROLE_LABELS[r] ?? r).join(', ') || '—'
}

function Avatar({ user, size = 'md' }: { user: AdminUser; size?: 'md' | 'lg' }) {
  return (
    <span
      className={`flex shrink-0 items-center justify-center rounded-full bg-royal-tint font-bold text-royal ${
        size === 'lg' ? 'h-11 w-11 text-sm' : 'h-9 w-9 text-xs'
      }`}
      aria-hidden="true"
    >
      {initials(user)}
    </span>
  )
}

/** Royal-header overlay with a single full-width Close footer (Details p95 / history p101). */
function InfoModal({
  title,
  subtitle,
  onClose,
  children,
}: {
  title: string
  subtitle?: ReactNode
  onClose: () => void
  children: ReactNode
}) {
  const panelRef = useRef<HTMLDivElement | null>(null)
  const closeRef = useRef<HTMLButtonElement | null>(null)
  // This overlay reimplemented ProtoModal's markup without its keyboard
  // handling: it opened with focus still on the row button behind it, ignored
  // Escape, and let Tab walk out into the page underneath.
  useDialogKeyboard(panelRef, onClose, closeRef)

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-md bg-white shadow-overlay"
      >
        <div className="bg-royal px-5 py-3 text-base font-bold tracking-wide text-white">{title}</div>
        {subtitle && <div className="border-b border-line px-5 py-3">{subtitle}</div>}
        <div className="flex-1 overflow-y-auto px-5 py-4">{children}</div>
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

/* ── Details (p95) ────────────────────────────────────────────────────── */

function timelineTone(action: string): string {
  const a = action.toLowerCase()
  if (/reject|delete|blacklist|fail/.test(a)) return 'bg-s-red'
  if (/approve|create|issue|pass|register/.test(a)) return 'bg-s-green'
  if (/toggle|update|status/.test(a)) return 'bg-s-orange'
  return 'bg-royal'
}

function humanizeAction(action: string): string {
  return action
    .split(/[._]/)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ')
}

/**
 * How far back the officer activity panel reads.
 *
 * The audit trail has no per-actor filter, so this screen can only scan the
 * pages it pulls: 8 × 25 = the 200 most recent entries. That is a window, not a
 * total, and the panel below says so — see recentActivity.
 */
const ACTIVITY_PAGES = 8

/** The most recent audit entries, across ACTIVITY_PAGES pages, newest first. */
async function recentActivity(): Promise<{ logs: AuditLog[]; scanned: number }> {
  const pages = await Promise.all(
    Array.from({ length: ACTIVITY_PAGES }, (_, i) => admin.auditLogs(i + 1)),
  )
  const logs = pages.flatMap((p) => p.data)
  return { logs, scanned: logs.length }
}

function DetailsModal({ user, onClose }: { user: AdminUser; onClose: () => void }) {
  const { data, loading } = useAsync(recentActivity, [])
  const name = fullName(user)
  const entries = (data?.logs ?? []).filter((log) => log.user?.name === name)
  const scanned = data?.scanned ?? 0

  /*
   * This panel used to read page 1 of the audit trail — 25 of 21,919 entries,
   * and the newest 25 are almost always sign-ins, which the trail records with
   * no actor at all. So it showed "Total Actions 0 · Last Active —" for officers
   * with real histories, which reads as "this officer has done nothing" rather
   * than "this screen cannot see that far back". A window is now named as a
   * window: the figure says what it counted over, and an empty result says the
   * window was empty rather than asserting the officer has never acted.
   */
  return (
    <InfoModal
      title="Details"
      onClose={onClose}
      subtitle={
        <div className="flex items-center gap-3">
          <Avatar user={user} size="lg" />
          <div className="min-w-0">
            <p className="truncate text-sm font-bold text-ink">{name}</p>
            <p className="truncate text-xs text-ink-muted">
              {roleLabel(user)} · {user.department?.code ?? 'No office'}
            </p>
          </div>
        </div>
      }
    >
      <div className="grid grid-cols-3 gap-3">
        <div>
          <p className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">Recent Actions</p>
          <p className="tnum mt-0.5 text-sm font-bold text-ink">{loading ? '…' : entries.length.toLocaleString()}</p>
        </div>
        <div>
          <p className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">Last Seen Here</p>
          <p className="mt-0.5 text-sm font-bold text-ink">
            {loading ? '…' : entries[0] ? formatDateTime(entries[0].created_at) : 'Not in this window'}
          </p>
        </div>
        <div>
          <p className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">Account Status</p>
          <p className="mt-0.5 text-sm font-bold text-ink">{user.is_active ? 'Active' : 'Inactive'}</p>
        </div>
      </div>
      <p className="mt-2 border-b border-line pb-4 text-xs text-ink-secondary">
        {loading
          ? 'Reading the audit trail…'
          : `Counted over the ${scanned.toLocaleString()} most recent audit entries, not this officer's whole history.`}
      </p>

      <ul className="mt-4 space-y-0">
        {loading ? (
          <li className="py-6 text-center text-sm text-ink-muted">Loading activity…</li>
        ) : entries.length === 0 ? (
          <li className="py-6 text-center text-sm text-ink-muted">
            Nothing from this officer in the {scanned.toLocaleString()} most recent audit entries. Older
            activity is in the full audit log.
          </li>
        ) : (
          entries.map((log: AuditLog, i) => (
            <li key={log.id} className="relative flex gap-3 pb-5">
              {i < entries.length - 1 && (
                <span className="absolute left-[5px] top-4 h-full w-px bg-line" aria-hidden="true" />
              )}
              <span className={`mt-1.5 h-[11px] w-[11px] shrink-0 rounded-full ${timelineTone(log.action)}`} aria-hidden="true" />
              <div className="min-w-0">
                <p className="text-sm font-bold text-ink">{humanizeAction(log.action)}</p>
                <p className="text-xs text-ink-muted">{formatDateTime(log.created_at)}</p>
                <p className="mt-0.5 truncate text-xs text-ink-secondary">
                  {log.auditable_type.split('\\').pop()} #{log.auditable_id}
                </p>
              </div>
            </li>
          ))
        )}
      </ul>
    </InfoModal>
  )
}

/* ── Reassigning (p96 — visual only) ──────────────────────────────────── */

function ReassignModal({
  user,
  officers,
  onClose,
}: {
  user: AdminUser
  officers: AdminUser[]
  onClose: () => void
}) {
  const [scope, setScope] = useState('Entire current caseload')
  const [target, setTarget] = useState('')
  const [reason, setReason] = useState('')
  const [done, setDone] = useState(false)

  if (done) {
    return (
      <ProtoModal title="Reassigning" onCancel={onClose} cancelLabel="Close">
        <p className="text-center text-base text-s-green">
          ✓ Reassignment recorded. {fullName(user)}&apos;s caseload will move to {target || 'the selected officer'}.
        </p>
        <p className="mt-2 text-center text-xs text-ink-muted">
          Demo preview only. No applications were moved.
        </p>
      </ProtoModal>
    )
  }

  return (
    <ProtoModal
      title="Reassigning"
      cancelLabel="Cancel"
      confirmLabel="Confirm"
      onCancel={onClose}
      onConfirm={() => setDone(true)}
      confirmDisabled={!target || !reason.trim()}
    >
      <p className="mb-5 border-b border-line pb-3 text-sm text-ink-secondary">
        Move officer-in-charge for {fullName(user)}
      </p>
      <div className="space-y-4">
        <label className="block">
          <FieldLabel required>Scope</FieldLabel>
          <select className={inputCls} value={scope} onChange={(e) => setScope(e.target.value)}>
            <option>Entire current caseload</option>
            <option>Pending reviews only</option>
            <option>Inspections only</option>
          </select>
        </label>
        <label className="block">
          <FieldLabel required>Reassign to</FieldLabel>
          <select className={inputCls} value={target} onChange={(e) => setTarget(e.target.value)}>
            <option value="">Select officer…</option>
            {officers
              .filter((o) => o.id !== user.id && o.is_active)
              .map((o) => (
                <option key={o.id} value={fullName(o)}>
                  {fullName(o)} · {o.department?.code ?? '—'}
                </option>
              ))}
          </select>
        </label>
        <label className="block">
          <FieldLabel required>Reason for reassignment</FieldLabel>
          <textarea
            className={`${inputCls} min-h-24`}
            placeholder="e.g. Officer on extended leave; load balancing"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </label>
        <p className="rounded-lg bg-royal-tint px-3.5 py-3 text-xs leading-relaxed text-ink-secondary">
          This is a caseload-level preview. To reassign the officer-in-charge for a
          specific application, open it in{' '}
          <Link to="/staff/queue" className="font-semibold text-royal underline underline-offset-2">
            Application Review
          </Link>{' '}
          and use “Assign officer”.
        </p>
      </div>
    </ProtoModal>
  )
}

/* ── Editing (p97) ────────────────────────────────────────────────────── */

function EditModal({
  user,
  departments,
  onClose,
  onSaved,
}: {
  user: AdminUser
  departments: Department[]
  onClose: () => void
  onSaved: (updated: AdminUser) => void
}) {
  const [form, setForm] = useState({
    last_name: user.last_name,
    first_name: user.first_name,
    email: user.email,
    department_id: user.department ? String(user.department.id) : '',
    active: user.is_active ? 'active' : 'inactive',
  })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function save() {
    setBusy(true)
    setError(null)
    try {
      let updated = await admin.updateUser(user.id, {
        first_name: form.first_name.trim(),
        last_name: form.last_name.trim(),
        email: form.email.trim(),
        ...(form.department_id ? { department_id: Number(form.department_id) } : {}),
      })
      if ((form.active === 'active') !== user.is_active) {
        updated = await admin.toggleActive(user.id)
      }
      onSaved(updated)
    } catch (err) {
      setError(toApiError(err).message)
      setBusy(false)
    }
  }

  return (
    <ProtoModal
      title="Editing"
      cancelLabel="Cancel"
      confirmLabel="Confirm"
      onCancel={onClose}
      onConfirm={save}
      /*
       * Office is a required field, and "No office" was silently unsendable:
       * the payload omits department_id when it is blank, so picking "No office"
       * closed the modal reporting success with the officer's office unchanged.
       * Requiring a real office is the honest half of that; actually clearing one
       * needs department_id to accept null in AdminUserPayload — see the report.
       */
      confirmDisabled={busy || !form.department_id}
    >
      <div className="mb-5 flex items-center gap-3 border-b border-line pb-4">
        <span className="flex h-10 w-10 items-center justify-center rounded-full bg-royal-tint text-royal" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
            <path
              d="M4 20h4l11-11a2.1 2.1 0 0 0-3-3L5 17l-1 3Z"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinejoin="round"
            />
          </svg>
        </span>
        <div className="min-w-0">
          <p className="text-sm font-bold text-ink">Edit officer</p>
          <p className="truncate text-xs text-ink-muted">
            {fullName(user)} · {roleLabel(user)} · {user.department?.code ?? 'No office'}
          </p>
        </div>
      </div>

      {error && <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">{error}</p>}

      <div className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block">
            <FieldLabel>Surname</FieldLabel>
            <input
              className={inputCls}
              value={form.last_name}
              onChange={(e) => setForm((f) => ({ ...f, last_name: e.target.value }))}
            />
          </label>
          <label className="block">
            <FieldLabel>Given name</FieldLabel>
            <input
              className={inputCls}
              value={form.first_name}
              onChange={(e) => setForm((f) => ({ ...f, first_name: e.target.value }))}
            />
          </label>
        </div>
        <label className="block">
          <FieldLabel>Email</FieldLabel>
          <input
            type="email"
            className={inputCls}
            value={form.email}
            onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
          />
        </label>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block">
            <FieldLabel required>Office</FieldLabel>
            <select
              className={inputCls}
              value={form.department_id}
              onChange={(e) => setForm((f) => ({ ...f, department_id: e.target.value }))}
            >
              <option value="">No office</option>
              {departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.code}
                </option>
              ))}
            </select>
          </label>
          <label className="block">
            <FieldLabel required>Account status</FieldLabel>
            <select
              className={inputCls}
              value={form.active}
              onChange={(e) => setForm((f) => ({ ...f, active: e.target.value }))}
            >
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </label>
        </div>
        <label className="block">
          <FieldLabel>Permit types handled</FieldLabel>
          <input className={inputCls} value="All permits in office" readOnly />
        </label>
      </div>
    </ProtoModal>
  )
}

/* ── Add officer (existing create wiring, restyled) ───────────────────── */

interface CreateFormState {
  first_name: string
  middle_name: string
  last_name: string
  suffix: string
  gender: '' | 'M' | 'F'
  email: string
  mobile_number: string
  password: string
  role: string
  department_id: string
}

const EMPTY_FORM: CreateFormState = {
  first_name: '',
  middle_name: '',
  last_name: '',
  suffix: '',
  gender: '',
  email: '',
  mobile_number: '',
  password: '',
  role: '',
  department_id: '',
}

function CreateOfficerModal({
  onClose,
  departments,
  onCreated,
}: {
  onClose: () => void
  departments: Department[]
  onCreated: () => void
}) {
  const [form, setForm] = useState<CreateFormState>(EMPTY_FORM)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  function set<K extends keyof CreateFormState>(key: K, value: CreateFormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  function fieldError(key: string): ReactNode {
    const msg = errors[key]?.[0]
    return msg ? <p className="mt-1 text-xs font-medium text-s-red">{msg}</p> : null
  }

  async function handleSubmit() {
    setSubmitting(true)
    setFormError(null)
    setErrors({})

    const payload: AdminUserPayload = {
      first_name: form.first_name.trim(),
      last_name: form.last_name.trim(),
      gender: (form.gender || 'M') as 'M' | 'F',
      email: form.email.trim(),
      role: form.role,
      password: form.password,
      ...(form.middle_name.trim() ? { middle_name: form.middle_name.trim() } : {}),
      ...(form.suffix.trim() ? { suffix: form.suffix.trim() } : {}),
      ...(form.mobile_number.trim() ? { mobile_number: form.mobile_number.trim() } : {}),
      ...(form.department_id ? { department_id: Number(form.department_id) } : {}),
    }

    try {
      await admin.createUser(payload)
      onCreated()
    } catch (err) {
      const apiError = toApiError(err)
      if (apiError.status === 422) setErrors(apiError.errors)
      else setFormError(apiError.message)
      setSubmitting(false)
    }
  }

  return (
    <ProtoModal
      title="Add Officer"
      wide
      cancelLabel="Cancel"
      confirmLabel="Create account"
      onCancel={onClose}
      onConfirm={handleSubmit}
      confirmDisabled={submitting}
    >
      <p className="mb-5 border-b border-line pb-3 text-sm text-ink-secondary">
        Give an LGU staff member access with a role and office.
      </p>
      {formError && (
        <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">{formError}</p>
      )}
      <div className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block">
            <FieldLabel required>Given name</FieldLabel>
            <input className={inputCls} value={form.first_name} onChange={(e) => set('first_name', e.target.value)} />
            {fieldError('first_name')}
          </label>
          <label className="block">
            <FieldLabel required>Surname</FieldLabel>
            <input className={inputCls} value={form.last_name} onChange={(e) => set('last_name', e.target.value)} />
            {fieldError('last_name')}
          </label>
          <label className="block">
            <FieldLabel>Middle name</FieldLabel>
            <input className={inputCls} value={form.middle_name} onChange={(e) => set('middle_name', e.target.value)} />
            {fieldError('middle_name')}
          </label>
          <label className="block">
            <FieldLabel>Suffix</FieldLabel>
            <input className={inputCls} value={form.suffix} onChange={(e) => set('suffix', e.target.value)} />
            {fieldError('suffix')}
          </label>
          <label className="block">
            <FieldLabel required>Sex</FieldLabel>
            <select
              className={inputCls}
              value={form.gender}
              onChange={(e) => set('gender', e.target.value as CreateFormState['gender'])}
            >
              <option value="">Select</option>
              <option value="M">Male</option>
              <option value="F">Female</option>
            </select>
            {fieldError('gender')}
          </label>
          <label className="block">
            <FieldLabel>Mobile number</FieldLabel>
            <input
              className={inputCls}
              inputMode="tel"
              value={form.mobile_number}
              onChange={(e) => set('mobile_number', e.target.value)}
            />
            {fieldError('mobile_number')}
          </label>
        </div>
        <label className="block">
          <FieldLabel required>Email address</FieldLabel>
          <input
            type="email"
            className={inputCls}
            value={form.email}
            onChange={(e) => set('email', e.target.value)}
          />
          {fieldError('email')}
        </label>
        <label className="block">
          <FieldLabel required>Temporary password</FieldLabel>
          <input
            type="password"
            autoComplete="new-password"
            className={inputCls}
            value={form.password}
            onChange={(e) => set('password', e.target.value)}
          />
          <p className="mt-1 text-xs text-ink-muted">The officer can change this after their first sign-in.</p>
          {fieldError('password')}
        </label>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block">
            <FieldLabel required>Role</FieldLabel>
            <select className={inputCls} value={form.role} onChange={(e) => set('role', e.target.value)}>
              <option value="">Select a role</option>
              {CREATABLE_ROLES.map((r) => (
                <option key={r} value={r}>
                  {ROLE_LABELS[r] ?? r}
                </option>
              ))}
            </select>
            {fieldError('role')}
          </label>
          <label className="block">
            <FieldLabel>Office</FieldLabel>
            <select
              className={inputCls}
              value={form.department_id}
              onChange={(e) => set('department_id', e.target.value)}
            >
              <option value="">No office</option>
              {departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
            {fieldError('department_id')}
          </label>
        </div>
      </div>
    </ProtoModal>
  )
}

/* ── Page ─────────────────────────────────────────────────────────────── */

type ModalState =
  | { kind: 'details' | 'reassign' | 'edit' | 'deactivate'; user: AdminUser }
  | { kind: 'create' }
  | null

export function UsersPage() {
  const { data: users, loading, error, reload, setData } = useAsync(() => admin.users(), [])
  const { data: departments } = useAsync(() => reference.departments(), [])
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [modal, setModal] = useState<ModalState>(null)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [toggleError, setToggleError] = useState<string | null>(null)

  const officers = useMemo(() => {
    const all = (users ?? []).filter((u) => !u.roles.includes('business_owner'))
    const q = search.trim().toLowerCase()
    if (!q) return all
    return all.filter((u) => fullName(u).toLowerCase().includes(q) || u.email.toLowerCase().includes(q))
  }, [users, search])

  const pageCount = Math.max(1, Math.ceil(officers.length / PAGE_SIZE))
  const safePage = Math.min(page, pageCount)
  const visible = officers.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE)

  function patchUser(updated: AdminUser) {
    setData((prev) => (prev ?? []).map((u) => (u.id === updated.id ? updated : u)))
  }

  async function toggleActive(user: AdminUser) {
    setBusyId(user.id)
    setToggleError(null)
    try {
      patchUser(await admin.toggleActive(user.id))
      setModal(null)
    } catch (err) {
      setToggleError(toApiError(err).message)
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div>
      <PageTitle
        right={
          <span className="flex items-center gap-4 pb-1">
            <input
              type="search"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
              placeholder="Search…"
              aria-label="Search officers"
              className="w-56 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
            <SortFilter />
            <button
              type="button"
              onClick={() => setModal({ kind: 'create' })}
              className="rounded-full bg-royal px-5 py-2 text-sm font-semibold text-white shadow-card hover:bg-royal-hover"
            >
              Add officer
            </button>
          </span>
        }
      >
        Officer Assignment
      </PageTitle>

      {toggleError && (
        <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">{toggleError}</p>
      )}

      {loading ? (
        <SkeletonList rows={7} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : officers.length === 0 ? (
        <EmptyState
          icon={UsersIcon}
          title={search ? 'No officers match your search' : 'No officers yet'}
          description={search ? 'Try another name or email.' : 'Add an officer account to get your LGU staff into BizTrack.'}
        />
      ) : (
        <ProtoCard className="overflow-hidden rounded-xl">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[44rem] text-left text-sm">
              <thead>
                <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  <th className="px-5 py-3">Officer</th>
                  <th className="px-5 py-3">Office</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {visible.map((user) => (
                  <tr key={user.id} className="border-t border-line">
                    <td className="px-5 py-3.5">
                      <button
                        type="button"
                        onClick={() => setModal({ kind: 'details', user })}
                        className="flex items-center gap-3 text-left hover:underline"
                      >
                        <Avatar user={user} />
                        <span className="font-bold text-ink">{fullName(user)}</span>
                      </button>
                    </td>
                    <td className="px-5 py-3.5 text-ink-secondary">{user.department?.code ?? '—'}</td>
                    <td className="px-5 py-3.5">
                      <StatusChip tone={user.is_active ? 'tint-green' : 'tint-gray'}>
                        {user.is_active ? 'Active' : 'Inactive'}
                      </StatusChip>
                    </td>
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-2">
                        <button
                          type="button"
                          onClick={() => setModal({ kind: 'reassign', user })}
                          className="rounded-full bg-royal-deep px-4 py-1.5 text-xs font-semibold text-white hover:brightness-110"
                        >
                          Reassign
                        </button>
                        <button
                          type="button"
                          onClick={() => setModal({ kind: 'edit', user })}
                          className="rounded-full border border-line bg-white px-4 py-1.5 text-xs font-semibold text-ink-secondary hover:bg-canvas"
                        >
                          Edit
                        </button>
                        <button
                          type="button"
                          onClick={() => setModal({ kind: 'deactivate', user })}
                          disabled={busyId === user.id}
                          className="rounded-full border border-line bg-white px-4 py-1.5 text-xs font-semibold text-ink-secondary hover:bg-canvas disabled:opacity-60"
                        >
                          {user.is_active ? 'Deactivate' : 'Activate'}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="flex items-center justify-between border-t border-line px-5 py-3.5">
            <p className="text-sm text-ink-muted">
              Showing {visible.length} of {officers.length} officers
            </p>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                aria-label="Previous page"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={safePage <= 1}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas disabled:opacity-40"
              >
                ‹
              </button>
              {Array.from({ length: pageCount }, (_, i) => i + 1).map((n) => (
                <button
                  key={n}
                  type="button"
                  onClick={() => setPage(n)}
                  aria-current={n === safePage ? 'page' : undefined}
                  className={`flex h-7 w-7 items-center justify-center rounded-md text-xs font-semibold ${
                    n === safePage
                      ? 'bg-royal-deep text-white'
                      : 'border border-line text-ink-secondary hover:bg-canvas'
                  }`}
                >
                  {n}
                </button>
              ))}
              <button
                type="button"
                aria-label="Next page"
                onClick={() => setPage((p) => Math.min(pageCount, p + 1))}
                disabled={safePage >= pageCount}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas disabled:opacity-40"
              >
                ›
              </button>
            </div>
          </div>
        </ProtoCard>
      )}

      {modal?.kind === 'details' && <DetailsModal user={modal.user} onClose={() => setModal(null)} />}
      {modal?.kind === 'reassign' && (
        <ReassignModal user={modal.user} officers={officers} onClose={() => setModal(null)} />
      )}
      {modal?.kind === 'edit' && (
        <EditModal
          user={modal.user}
          departments={departments ?? []}
          onClose={() => setModal(null)}
          onSaved={(updated) => {
            patchUser(updated)
            setModal(null)
          }}
        />
      )}
      {modal?.kind === 'deactivate' && (
        <ProtoModal
          title="WARNING"
          tone={modal.user.is_active ? 'red' : 'blue'}
          cancelLabel="Cancel"
          confirmLabel="Yes"
          onCancel={() => setModal(null)}
          onConfirm={() => toggleActive(modal.user)}
          confirmDisabled={busyId === modal.user.id}
        >
          <p className="py-4 text-center text-base">
            Are you sure you want to {modal.user.is_active ? 'deactivate' : 'reactivate'} this account?
          </p>
        </ProtoModal>
      )}
      {modal?.kind === 'create' && (
        <CreateOfficerModal
          departments={departments ?? []}
          onClose={() => setModal(null)}
          onCreated={() => {
            setModal(null)
            reload()
          }}
        />
      )}
    </div>
  )
}
