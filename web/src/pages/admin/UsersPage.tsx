import { useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { admin, reference } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { toApiError } from '../../lib/api'
import { formatDateTime } from '../../lib/format'
import { useAuth } from '../../stores/auth'
import type {
  AdminCaseload,
  AdminRole,
  AdminUser,
  AdminUserPayload,
  AuditLog,
  CaseloadMovePayload,
  Department,
  ReleasedCaseload,
} from '../../lib/types'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import {
  FieldLabel,
  PageTitle,
  ProtoCard,
  ProtoModal,
  StatusChip,
  inputCls,
  useDialogKeyboard,
} from '../../components/ui/Proto'
import { UsersIcon } from '../../components/icons'

/*
 * Officer Assignment (PDF p93–98) — the super admin's Manage Officer-in-Charge
 * screen: the staff directory, Add officer, Reassign, Edit, Details and
 * Activate/Deactivate.
 *
 * ── What was wrong with it ───────────────────────────────────────────────────
 *
 *  1. "Add Officer" could not create anybody. The form posted `role`; the
 *     endpoint validated `roles`. The 422 came back keyed `roles` and this
 *     screen renders field errors under `role`, so the one message explaining
 *     the failure was addressed to a field name nothing here was reading. The
 *     admin filled the form, pressed Create account, and watched the button
 *     re-enable in silence. Mobile number was required by the API and marked
 *     optional here, which failed the same way the first time round.
 *
 *  2. It could only staff three of the city's seven offices. The role list was
 *     four hard-coded strings, so Zoning, Building Official, CENRO and the
 *     Market Administrator had no option at all, and the label map was missing
 *     three more, which rendered as raw `obo_staff`. Both now come from
 *     GET /admin/roles, which reads `roles.display_name`.
 *
 *  3. "Reassign" was a mock. It collected a scope, a target and a reason, said
 *     "✓ Reassignment recorded", and moved nothing. It is the one control this
 *     screen exists for.
 *
 *  4. The office column was the only thing distinguishing two officers, and the
 *     role — the thing the screen is named after — was not shown at all.
 */

/** Rows per request. Server-side now; the browser used to hold the directory. */
const PAGE_SIZE = 10

function fullName(u: AdminUser): string {
  return [u.first_name, u.last_name].filter(Boolean).join(' ') + (u.suffix ? ` ${u.suffix}` : '')
}

function initials(u: AdminUser): string {
  return `${u.first_name[0] ?? ''}${u.last_name[0] ?? ''}`.toUpperCase()
}

/** Role names as words, using the labels the API supplies. */
function roleLabel(u: AdminUser, roles: AdminRole[]): string {
  return u.roles.map((name) => roles.find((r) => r.name === name)?.label ?? name).join(', ') || '—'
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

/**
 * Read a validation error under any of the keys the API might use for a field.
 *
 * The role select is the reason this takes a list. The endpoint answers under
 * `roles` or `roles.0` depending on which rule failed, and the control on screen
 * is called `role`; a lookup on one key silently swallowed the others, which is
 * how a form ends up refusing to submit without saying why.
 */
function firstError(errors: Record<string, string[]>, ...keys: string[]): string | undefined {
  for (const key of keys) {
    if (errors[key]?.[0]) return errors[key][0]
  }
  return undefined
}

function FieldError({ message }: { message?: string }) {
  return message ? <p className="mt-1 text-xs font-medium text-s-red">{message}</p> : null
}

/** Royal-header overlay with a single full-width Close footer (Details p95). */
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
  if (/reject|delete|blacklist|fail|deactivat/.test(a)) return 'bg-s-red'
  if (/approve|create|issue|pass|register/.test(a)) return 'bg-s-green'
  if (/toggle|update|status|reassign/.test(a)) return 'bg-s-orange'
  return 'bg-royal'
}

function humanizeAction(action: string): string {
  return action
    .split(/[._]/)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ')
}

/**
 * This officer's own activity, asked for by actor id.
 *
 * It used to pull the eight newest pages of the whole audit trail and keep the
 * rows whose actor NAME matched this one. Two things were wrong with that: 200
 * rows out of tens of thousands is a window, not a history — and the newest
 * rows are overwhelmingly sign-ins, so officers with real histories read as
 * having done nothing — and matching on a display name credits one officer with
 * another's work the moment two people share a name. The trail stores the actor
 * id; it is now filtered on that, server-side.
 */
function DetailsModal({ user, onClose }: { user: AdminUser; onClose: () => void }) {
  const { data, loading } = useAsync(
    () => admin.auditLogs({ user_id: user.id, per_page: 50 }),
    [user.id],
  )
  const entries = data?.data ?? []
  const total = data?.total ?? 0

  return (
    <InfoModal
      title="Details"
      onClose={onClose}
      subtitle={
        <div className="flex items-center gap-3">
          <Avatar user={user} size="lg" />
          <div className="min-w-0">
            <p className="truncate text-sm font-bold text-ink">{fullName(user)}</p>
            <p className="truncate text-xs text-ink-muted">
              {user.email} · {user.department?.code ?? 'No office'}
            </p>
          </div>
        </div>
      }
    >
      <div className="grid grid-cols-3 gap-3">
        <div>
          <p className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">Recorded Actions</p>
          <p className="tnum mt-0.5 text-sm font-bold text-ink">{loading ? '…' : total.toLocaleString()}</p>
        </div>
        <div>
          <p className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">Last Active</p>
          <p className="mt-0.5 text-sm font-bold text-ink">
            {loading ? '…' : entries[0] ? formatDateTime(entries[0].created_at) : 'Never'}
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
          : `Showing the ${Math.min(entries.length, total).toLocaleString()} most recent of ${total.toLocaleString()}.`}
      </p>

      <ul className="mt-4 space-y-0">
        {loading ? (
          <li className="py-6 text-center text-sm text-ink-muted">Loading activity…</li>
        ) : entries.length === 0 ? (
          <li className="py-6 text-center text-sm text-ink-muted">
            This officer has not taken any recorded action yet.
          </li>
        ) : (
          entries.map((log: AuditLog, i) => (
            <li key={log.id} className="relative flex gap-3 pb-5">
              {i < entries.length - 1 && (
                <span className="absolute left-[5px] top-4 h-full w-px bg-line" aria-hidden="true" />
              )}
              <span
                className={`mt-1.5 h-[11px] w-[11px] shrink-0 rounded-full ${timelineTone(log.action)}`}
                aria-hidden="true"
              />
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

/* ── Reassign (p96) — the real thing ──────────────────────────────────── */

const SCOPES: { value: CaseloadMovePayload['scope']; label: string }[] = [
  { value: 'all', label: 'Everything they are holding' },
  { value: 'reviews', label: 'Application reviews only' },
  { value: 'inspections', label: 'Scheduled inspections only' },
]

/** The number of cases a given scope would actually move. */
function scopeCount(caseload: AdminCaseload, scope: CaseloadMovePayload['scope']): number {
  if (scope === 'reviews') return caseload.open_reviews
  if (scope === 'inspections') return caseload.open_inspections
  return caseload.total
}

function ReassignModal({
  user,
  onClose,
  onDone,
}: {
  user: AdminUser
  onClose: () => void
  onDone: (message: string) => void
}) {
  const { data: caseload, loading, error } = useAsync(() => admin.caseload(user.id), [user.id])
  const [scope, setScope] = useState<CaseloadMovePayload['scope']>('all')
  // '' means "release to the office queue" — a real choice, not a missing one.
  const [target, setTarget] = useState('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  const moving = caseload ? scopeCount(caseload, scope) : 0

  async function confirm() {
    setBusy(true)
    setFormError(null)
    try {
      const result = await admin.reassignCaseload(user.id, {
        to_user_id: target ? Number(target) : null,
        scope,
        reason: reason.trim(),
      })
      const where = result.to ? `to ${result.to.name}` : 'to the office queue'
      onDone(
        result.total === 0
          ? `${fullName(user)} had nothing open to move.`
          : `Moved ${result.total} ${result.total === 1 ? 'case' : 'cases'} ${where}.`,
      )
    } catch (err) {
      setFormError(toApiError(err).message)
      setBusy(false)
    }
  }

  if (loading) {
    return (
      <ProtoModal title="Reassign" onCancel={onClose} cancelLabel="Cancel">
        <p className="py-6 text-center text-sm text-ink-muted">Checking what this officer is holding…</p>
      </ProtoModal>
    )
  }

  if (error || !caseload) {
    return (
      <ProtoModal title="Reassign" onCancel={onClose} cancelLabel="Close">
        <p className="py-4 text-sm text-s-red">{toApiError(error).message}</p>
      </ProtoModal>
    )
  }

  return (
    <ProtoModal
      title="Reassign"
      cancelLabel="Cancel"
      confirmLabel={target ? 'Move caseload' : 'Release to office'}
      onCancel={onClose}
      onConfirm={confirm}
      confirmDisabled={busy || !reason.trim()}
    >
      <div className="mb-5 border-b border-line pb-3">
        <p className="text-sm font-bold text-ink">{fullName(user)}</p>
        <p className="text-xs text-ink-muted">
          {caseload.department ? caseload.department.name : 'No office'}
        </p>
      </div>

      {/*
        What is actually on the table, before anything is confirmed. The mock
        version asked for a scope and a target without ever saying how much work
        was involved, so "Confirm" was a decision made blind.
      */}
      <dl className="mb-4 grid grid-cols-3 gap-3 rounded-lg bg-canvas px-4 py-3">
        <div>
          <dt className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">Open reviews</dt>
          <dd className="tnum text-base font-bold text-ink">{caseload.open_reviews}</dd>
        </div>
        <div>
          <dt className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">Inspections</dt>
          <dd className="tnum text-base font-bold text-ink">{caseload.open_inspections}</dd>
        </div>
        <div>
          <dt className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">Will move</dt>
          <dd className="tnum text-base font-bold text-royal">{moving}</dd>
        </div>
      </dl>

      {formError && (
        <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">{formError}</p>
      )}

      <div className="space-y-4">
        <label className="block">
          <FieldLabel required>Scope</FieldLabel>
          <select
            className={inputCls}
            value={scope}
            onChange={(e) => setScope(e.target.value as CaseloadMovePayload['scope'])}
          >
            {SCOPES.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label} ({scopeCount(caseload, s.value)})
              </option>
            ))}
          </select>
        </label>

        <label className="block">
          <FieldLabel required>Reassign to</FieldLabel>
          <select className={inputCls} value={target} onChange={(e) => setTarget(e.target.value)}>
            {/*
              Releasing is the DEFAULT, not the fallback. Every office in the
              register is one officer deep, so most of the time there is nobody
              to name — and putting a case back in the office pool is the state
              it starts in, visible to whoever the office next staffs.
            */}
            <option value="">Leave unassigned — back to the office queue</option>
            {caseload.candidates.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name} · holding {c.open_total}
              </option>
            ))}
          </select>
          {caseload.candidates.length === 0 && (
            <p className="mt-1 text-xs text-ink-muted">
              {caseload.department
                ? `No other active officer in ${caseload.department.code}, so this caseload can only go back to the office queue.`
                : 'This account belongs to no office, so there is nobody to hand work to.'}
            </p>
          )}
        </label>

        <label className="block">
          <FieldLabel required>Reason</FieldLabel>
          <textarea
            className={`${inputCls} min-h-24`}
            placeholder="e.g. Officer on extended leave; load balancing"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
          <p className="mt-1 text-xs text-ink-muted">
            Recorded in the audit trail against every case that moves, and sent to whoever receives them.
          </p>
        </label>
      </div>
    </ProtoModal>
  )
}

/* ── Editing (p97) ────────────────────────────────────────────────────── */

/**
 * Is this the super admin?
 *
 * Asked of the roles list rather than by comparing against the string 'admin',
 * so the one role that belongs to no office stays a fact the API states and not
 * a name hard-coded on three screens.
 */
function isSuperAdmin(user: AdminUser, roles: AdminRole[]): boolean {
  return user.roles.some((name) => roles.find((r) => r.name === name)?.wants_department === false)
}

/** Whether this set of roles is the departmentless super admin. */
function wantsOffice(roleNames: string[], roles: AdminRole[]): boolean {
  if (roleNames.length === 0) return true
  return roleNames.every((name) => roles.find((r) => r.name === name)?.wants_department !== false)
}

function EditModal({
  user,
  departments,
  roles,
  onClose,
  onSaved,
}: {
  user: AdminUser
  departments: Department[]
  roles: AdminRole[]
  onClose: () => void
  onSaved: (updated: AdminUser, note: string | null) => void
}) {
  const [form, setForm] = useState({
    last_name: user.last_name,
    first_name: user.first_name,
    email: user.email,
    mobile_number: user.mobile_number ?? '',
    role: user.roles[0] ?? '',
    department_id: user.department ? String(user.department.id) : '',
  })
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)

  const needsOffice = wantsOffice(form.role ? [form.role] : [], roles)
  const officeChanged = form.department_id !== (user.department ? String(user.department.id) : '')
  /*
   * Only send `roles` when the admin actually moved this select.
   *
   * The control is a single select and `roles` is a many-to-many, so posting it
   * unconditionally would sync a multi-role account down to the one role that
   * happened to be showing — silent, irreversible, and triggered by an edit to
   * something else entirely. Every account holds exactly one role today, which
   * is precisely why such a bug would go unnoticed until it didn't. The old
   * modal never sent roles at all; this keeps that safety while still letting a
   * deliberate change through.
   *
   * Compared against what the select was INITIALISED with, not against the
   * account's role count: `user.roles[0]` is what is on screen either way, so
   * "unchanged" is the only thing that can be read off it honestly.
   */
  const initialRole = user.roles[0] ?? ''
  const roleChanged = Boolean(form.role) && form.role !== initialRole
  /*
   * The super-admin row. Its role is fixed both ways — the seat is a singleton
   * and it cannot be vacated, because `user.manage` lives on no other role and
   * emptying it would lock every administrative action out of the app.
   */
  const currentRole = roles.find((r) => r.name === initialRole)
  // `roles` is empty while it loads, and an unknown role must not be mistaken
  // for the super admin — hence the explicit "found it, and it wants no office".
  const isOnlySuperAdmin = currentRole ? !currentRole.wants_department : false

  async function save() {
    setBusy(true)
    setFormError(null)
    setErrors({})
    try {
      const updated = await admin.updateUser(user.id, {
        first_name: form.first_name.trim(),
        last_name: form.last_name.trim(),
        email: form.email.trim(),
        mobile_number: form.mobile_number.trim(),
        roles: roleChanged ? [form.role] : undefined,
        // Null clears it, which is what the super admin needs and what "No
        // office" silently failed to do before: the payload simply omitted the
        // key, so the modal closed reporting success with the office unchanged.
        department_id: needsOffice ? Number(form.department_id) : null,
      } as Partial<AdminUserPayload>)
      onSaved(
        updated,
        officeChanged
          ? 'Moved office. Any open cases in the old office were handed back to it.'
          : null,
      )
    } catch (err) {
      const apiError = toApiError(err)
      if (apiError.status === 422) setErrors(apiError.errors)
      else setFormError(apiError.message)
      setBusy(false)
    }
  }

  return (
    <ProtoModal
      title="Editing"
      cancelLabel="Cancel"
      confirmLabel="Save changes"
      onCancel={onClose}
      onConfirm={save}
      confirmDisabled={busy || !form.role || (needsOffice && !form.department_id)}
    >
      <div className="mb-5 flex items-center gap-3 border-b border-line pb-4">
        <Avatar user={user} size="lg" />
        <div className="min-w-0">
          <p className="text-sm font-bold text-ink">Edit officer</p>
          <p className="truncate text-xs text-ink-muted">{user.email}</p>
        </div>
      </div>

      {formError && <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">{formError}</p>}

      <div className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block">
            <FieldLabel required>Surname</FieldLabel>
            <input
              className={inputCls}
              value={form.last_name}
              onChange={(e) => setForm((f) => ({ ...f, last_name: e.target.value }))}
            />
            <FieldError message={firstError(errors, 'last_name')} />
          </label>
          <label className="block">
            <FieldLabel required>Given name</FieldLabel>
            <input
              className={inputCls}
              value={form.first_name}
              onChange={(e) => setForm((f) => ({ ...f, first_name: e.target.value }))}
            />
            <FieldError message={firstError(errors, 'first_name')} />
          </label>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block">
            <FieldLabel required>Email</FieldLabel>
            <input
              type="email"
              className={inputCls}
              value={form.email}
              onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
            />
            <FieldError message={firstError(errors, 'email')} />
          </label>
          <label className="block">
            <FieldLabel required>Mobile number</FieldLabel>
            <input
              className={inputCls}
              inputMode="numeric"
              value={form.mobile_number}
              onChange={(e) => setForm((f) => ({ ...f, mobile_number: e.target.value }))}
            />
            <FieldError message={firstError(errors, 'mobile_number')} />
          </label>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block">
            <FieldLabel required>Role</FieldLabel>
            {/*
              Editable here for the first time. The role decides which queue an
              officer sees, and it could only ever be set at creation — so the
              only way to correct one was to deactivate the account and make a
              second one.
            */}
            <select
              className={inputCls}
              value={form.role}
              disabled={isOnlySuperAdmin}
              onChange={(e) => setForm((f) => ({ ...f, role: e.target.value }))}
            >
              {roles.map((r) => (
                /*
                 * The role this account already holds is never disabled, even
                 * when it is the taken super-admin seat — it is taken BY this
                 * account, and greying out its own current value would read as
                 * a fault on the row you are editing.
                 */
                <option key={r.name} value={r.name} disabled={!r.available && r.name !== initialRole}>
                  {r.label}
                  {!r.available && r.name !== initialRole && ' — already assigned'}
                </option>
              ))}
            </select>
            {isOnlySuperAdmin && (
              <p className="mt-1 text-xs text-ink-muted">
                The super admin is a single account and the only one that can create office
                accounts, so its role cannot be changed away.
              </p>
            )}
            {user.roles.length > 1 && (
              <p className="mt-1 text-xs text-amber-800">
                This account holds {user.roles.length} roles ({user.roles.join(', ')}). Changing this
                select replaces all of them with the one chosen.
              </p>
            )}
            <FieldError message={firstError(errors, 'roles', 'roles.0', 'role')} />
          </label>
          <label className="block">
            <FieldLabel required={needsOffice}>Office</FieldLabel>
            <select
              className={inputCls}
              value={needsOffice ? form.department_id : ''}
              disabled={!needsOffice}
              onChange={(e) => setForm((f) => ({ ...f, department_id: e.target.value }))}
            >
              <option value="">{needsOffice ? 'Select an office…' : 'Works across every office'}</option>
              {departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.code} — {d.name}
                </option>
              ))}
            </select>
            <FieldError message={firstError(errors, 'department_id')} />
          </label>
        </div>

        {officeChanged && needsOffice && (
          <p className="rounded-lg bg-s-yellow-tint px-3.5 py-3 text-xs leading-relaxed text-amber-800">
            Moving office hands any open cases back to the office they belong to. The cases stay
            where they are; only this officer&apos;s name comes off them.
          </p>
        )}
      </div>
    </ProtoModal>
  )
}

/* ── Add officer ──────────────────────────────────────────────────────── */

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
  roles,
  onCreated,
}: {
  onClose: () => void
  departments: Department[]
  roles: AdminRole[]
  onCreated: (name: string) => void
}) {
  const [form, setForm] = useState<CreateFormState>(EMPTY_FORM)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  function set<K extends keyof CreateFormState>(key: K, value: CreateFormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  const needsOffice = wantsOffice(form.role ? [form.role] : [], roles)
  const selectedRole = roles.find((r) => r.name === form.role)

  async function handleSubmit() {
    setSubmitting(true)
    setFormError(null)
    setErrors({})

    const payload: AdminUserPayload = {
      first_name: form.first_name.trim(),
      last_name: form.last_name.trim(),
      gender: (form.gender || 'M') as 'M' | 'F',
      email: form.email.trim(),
      mobile_number: form.mobile_number.trim(),
      password: form.password,
      // `roles`, plural — the shape the endpoint has always validated. This
      // used to send `role` and 422 every single time.
      roles: form.role ? [form.role] : [],
      ...(form.middle_name.trim() ? { middle_name: form.middle_name.trim() } : {}),
      ...(form.suffix.trim() ? { suffix: form.suffix.trim() } : {}),
      ...(needsOffice && form.department_id ? { department_id: Number(form.department_id) } : {}),
    }

    try {
      await admin.createUser(payload)
      onCreated(`${payload.first_name} ${payload.last_name}`.trim())
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
            <FieldError message={firstError(errors, 'first_name')} />
          </label>
          <label className="block">
            <FieldLabel required>Surname</FieldLabel>
            <input className={inputCls} value={form.last_name} onChange={(e) => set('last_name', e.target.value)} />
            <FieldError message={firstError(errors, 'last_name')} />
          </label>
          <label className="block">
            <FieldLabel>Middle name</FieldLabel>
            <input className={inputCls} value={form.middle_name} onChange={(e) => set('middle_name', e.target.value)} />
            <FieldError message={firstError(errors, 'middle_name')} />
          </label>
          <label className="block">
            <FieldLabel>Suffix</FieldLabel>
            <input className={inputCls} value={form.suffix} onChange={(e) => set('suffix', e.target.value)} />
            <FieldError message={firstError(errors, 'suffix')} />
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
            <FieldError message={firstError(errors, 'gender')} />
          </label>
          {/* Required by the API. It was marked optional here, so the first
              attempt always failed on a field the form said was not needed. */}
          <label className="block">
            <FieldLabel required>Mobile number</FieldLabel>
            <input
              className={inputCls}
              inputMode="numeric"
              placeholder="09171234567"
              value={form.mobile_number}
              onChange={(e) => set('mobile_number', e.target.value)}
            />
            <FieldError message={firstError(errors, 'mobile_number')} />
          </label>
        </div>
        <label className="block">
          <FieldLabel required>Email address</FieldLabel>
          <input type="email" className={inputCls} value={form.email} onChange={(e) => set('email', e.target.value)} />
          <FieldError message={firstError(errors, 'email')} />
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
          <p className="mt-1 text-xs text-ink-muted">
            At least 8 characters. The officer can change this after their first sign-in.
          </p>
          <FieldError message={firstError(errors, 'password')} />
        </label>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block">
            <FieldLabel required>Role</FieldLabel>
            <select className={inputCls} value={form.role} onChange={(e) => set('role', e.target.value)}>
              <option value="">Select a role</option>
              {roles.map((r) => (
                /*
                 * The super admin seat is disabled once it is taken, rather
                 * than offered and refused on submit. Office roles are never
                 * disabled — an office is meant to have as many accounts as it
                 * needs, and only this one role is a singleton.
                 */
                <option key={r.name} value={r.name} disabled={!r.available}>
                  {r.label}
                  {!r.available && ' — already assigned'}
                </option>
              ))}
            </select>
            {selectedRole?.description && (
              <p className="mt-1 text-xs text-ink-muted">{selectedRole.description}</p>
            )}
            <FieldError message={firstError(errors, 'roles', 'roles.0', 'role')} />
          </label>
          <label className="block">
            <FieldLabel required={needsOffice}>Office</FieldLabel>
            <select
              className={inputCls}
              value={needsOffice ? form.department_id : ''}
              disabled={!needsOffice}
              onChange={(e) => set('department_id', e.target.value)}
            >
              {/*
                "No office" is gone. An officer without one signs in to an empty
                queue — the review queue scopes by department and shows a
                departmentless non-admin nothing — and the API refuses it now.
              */}
              <option value="">{needsOffice ? 'Select an office…' : 'Works across every office'}</option>
              {departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.code} — {d.name}
                </option>
              ))}
            </select>
            <FieldError message={firstError(errors, 'department_id')} />
          </label>
        </div>
      </div>
    </ProtoModal>
  )
}

/* ── Deactivate ───────────────────────────────────────────────────────── */

function DeactivateModal({
  user,
  busy,
  onCancel,
  onConfirm,
}: {
  user: AdminUser
  busy: boolean
  onCancel: () => void
  onConfirm: () => void
}) {
  // Only worth asking when there is something to lose: reactivating an account
  // has no caseload to release.
  const { data: caseload, loading } = useAsync(
    () => (user.is_active ? admin.caseload(user.id) : Promise.resolve(null)),
    [user.id, user.is_active],
  )

  return (
    <ProtoModal
      title="WARNING"
      tone={user.is_active ? 'red' : 'blue'}
      cancelLabel="Cancel"
      confirmLabel="Yes"
      onCancel={onCancel}
      onConfirm={onConfirm}
      confirmDisabled={busy}
    >
      <p className="py-3 text-center text-base">
        {user.is_active ? 'Deactivate' : 'Reactivate'} {fullName(user)}?
      </p>
      {user.is_active && (
        <p className="pb-3 text-center text-sm text-ink-secondary">
          They will be signed out and will not be able to sign in again.
        </p>
      )}
      {/*
        What happens to their work, said BEFORE the decision. Deactivation used
        to leave every open review and scheduled inspection named to an account
        that could no longer sign in — live work, in nobody's queue, flagged
        nowhere. It is released now, and the admin should know that is coming.
      */}
      {user.is_active && !loading && caseload && caseload.total > 0 && (
        <p className="rounded-lg bg-s-yellow-tint px-3.5 py-3 text-xs leading-relaxed text-amber-800">
          {caseload.total} open {caseload.total === 1 ? 'case' : 'cases'} ({caseload.open_reviews} review
          {caseload.open_reviews === 1 ? '' : 's'}, {caseload.open_inspections} inspection
          {caseload.open_inspections === 1 ? '' : 's'}) will be handed back to{' '}
          {caseload.department?.code ?? 'their office'} as unassigned. To give them to a named
          colleague instead, cancel and use Reassign first.
        </p>
      )}
    </ProtoModal>
  )
}

/* ── Page ─────────────────────────────────────────────────────────────── */

type ModalState =
  | { kind: 'details' | 'reassign' | 'edit' | 'deactivate'; user: AdminUser }
  | { kind: 'create' }
  | null

type ActiveFilter = 'all' | 'active' | 'inactive'

export function UsersPage() {
  const permissions = useAuth((s) => s.user?.permissions)
  // The bulk move is gated on `oic.assign`, not on `user.manage` which opens
  // this screen — so the control has to be able to disappear.
  const canReassign = Boolean(permissions?.includes('oic.assign'))

  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [office, setOffice] = useState('')
  const [role, setRole] = useState('')
  const [active, setActive] = useState<ActiveFilter>('all')
  const [page, setPage] = useState(1)
  const [modal, setModal] = useState<ModalState>(null)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [banner, setBanner] = useState<{ tone: 'ok' | 'bad'; text: string } | null>(null)

  const { data: departments } = useAsync(() => reference.departments(), [])
  const { data: roles } = useAsync(() => admin.roles(), [])

  /*
   * Searched, filtered and paged on the server.
   *
   * All three used to happen in the browser over a 200-row request, with the
   * footer reporting the slice it happened to hold as though it were the
   * directory. That is survivable at 11 staff and wrong at 81.
   */
  const { data, loading, error, reload, setData } = useAsync(
    () =>
      admin.usersPage({
        // Officers, not citizens. The old screen dropped business owners in the
        // browser; moving the listing server-side has to carry that with it.
        staff: true,
        q: query || undefined,
        role: role || undefined,
        department_id: office ? Number(office) : undefined,
        is_active: active === 'all' ? undefined : active === 'active',
        page,
        per_page: PAGE_SIZE,
      }),
    [query, role, office, active, page],
  )

  // Let the admin finish typing before asking the server.
  useEffect(() => {
    const id = window.setTimeout(() => {
      setQuery(search.trim())
      setPage(1)
    }, 300)
    return () => window.clearTimeout(id)
  }, [search])

  const roleList = useMemo(() => roles ?? [], [roles])
  const rows = data?.data ?? []
  const total = data?.meta.total ?? 0
  const lastPage = data?.meta.last_page ?? 1

  function patchUser(updated: AdminUser) {
    setData((prev) =>
      prev ? { ...prev, data: prev.data.map((u) => (u.id === updated.id ? updated : u)) } : prev!,
    )
  }

  function describeRelease(released: ReleasedCaseload | null): string {
    if (!released) return ''
    const parts: string[] = []
    if (released.reviews) parts.push(`${released.reviews} review${released.reviews === 1 ? '' : 's'}`)
    if (released.inspections)
      parts.push(`${released.inspections} inspection${released.inspections === 1 ? '' : 's'}`)
    return parts.length ? ` ${parts.join(' and ')} returned to the office queue.` : ''
  }

  async function toggleActive(user: AdminUser) {
    setBusyId(user.id)
    setBanner(null)
    try {
      const { user: updated, released } = await admin.toggleActive(user.id)
      patchUser(updated)
      setModal(null)
      setBanner({
        tone: 'ok',
        text: `${fullName(updated)} is now ${updated.is_active ? 'active' : 'inactive'}.${describeRelease(released)}`,
      })
    } catch (err) {
      setBanner({ tone: 'bad', text: toApiError(err).message })
    } finally {
      setBusyId(null)
    }
  }

  const filtersApplied = Boolean(query || role || office || active !== 'all')

  return (
    <div>
      <PageTitle
        right={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-2 pb-1">
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search name or email…"
              aria-label="Search officers"
              className="w-52 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
            <button
              type="button"
              onClick={() => setModal({ kind: 'create' })}
              // Transparent border so it stands exactly as tall as the bordered
              // search field it shares this header row with.
              className="rounded-full border border-transparent bg-royal px-5 py-2 text-sm font-semibold text-white shadow-card hover:bg-royal-hover"
            >
              Add officer
            </button>
          </span>
        }
      >
        Officer Assignment
      </PageTitle>

      {/*
        Filters as real controls rather than the decorative Sort/Filter pair
        that used to sit here: 81 staff across seven offices is not a list you
        scroll to find the Fire inspector in.
      */}
      <div className="mb-5 flex flex-wrap items-end gap-3">
        <label className="block">
          <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-ink-muted">Office</span>
          <select
            className={`${inputCls} w-52`}
            value={office}
            onChange={(e) => {
              setOffice(e.target.value)
              setPage(1)
            }}
          >
            <option value="">All offices</option>
            {(departments ?? []).map((d) => (
              <option key={d.id} value={d.id}>
                {d.code} — {d.name}
              </option>
            ))}
          </select>
        </label>
        <label className="block">
          <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-ink-muted">Role</span>
          <select
            className={`${inputCls} w-52`}
            value={role}
            onChange={(e) => {
              setRole(e.target.value)
              setPage(1)
            }}
          >
            <option value="">All roles</option>
            {roleList.map((r) => (
              <option key={r.name} value={r.name}>
                {r.label}
              </option>
            ))}
          </select>
        </label>
        <label className="block">
          <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-ink-muted">Status</span>
          <select
            className={`${inputCls} w-40`}
            value={active}
            onChange={(e) => {
              setActive(e.target.value as ActiveFilter)
              setPage(1)
            }}
          >
            <option value="all">Active and inactive</option>
            <option value="active">Active only</option>
            <option value="inactive">Inactive only</option>
          </select>
        </label>
        {filtersApplied && (
          <button
            type="button"
            onClick={() => {
              setSearch('')
              setQuery('')
              setOffice('')
              setRole('')
              setActive('all')
              setPage(1)
            }}
            className="rounded-full border border-line bg-white px-4 py-2 text-xs font-semibold text-ink-secondary hover:bg-canvas"
          >
            Clear filters
          </button>
        )}
      </div>

      {banner && (
        <p
          className={`mb-4 rounded-lg px-4 py-3 text-sm font-medium ${
            banner.tone === 'ok' ? 'bg-s-green-tint text-s-green' : 'bg-s-red-tint text-s-red'
          }`}
          role="status"
        >
          {banner.text}
        </p>
      )}

      {loading ? (
        <SkeletonList rows={7} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={UsersIcon}
          title={filtersApplied ? 'No officers match these filters' : 'No officers yet'}
          description={
            filtersApplied
              ? 'Try another office, role or spelling.'
              : 'Add an officer account to get your LGU staff into BizTrack.'
          }
        />
      ) : (
        <ProtoCard className="overflow-hidden rounded-xl">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[52rem] text-left text-sm">
              <thead>
                <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  <th className="px-5 py-3">Officer</th>
                  <th className="px-5 py-3">Role</th>
                  <th className="px-5 py-3">Office</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((user) => (
                  <tr key={user.id} className="border-t border-line">
                    <td className="px-5 py-3.5">
                      <button
                        type="button"
                        onClick={() => setModal({ kind: 'details', user })}
                        className="flex items-center gap-3 text-left hover:underline"
                      >
                        <Avatar user={user} />
                        <span className="min-w-0">
                          <span className="block font-bold text-ink">{fullName(user)}</span>
                          <span className="block truncate text-xs text-ink-muted">{user.email}</span>
                        </span>
                      </button>
                    </td>
                    {/* The thing this screen is named after, and it was not shown. */}
                    <td className="px-5 py-3.5 text-ink-secondary">{roleLabel(user, roleList)}</td>
                    <td className="px-5 py-3.5 text-ink-secondary">{user.department?.code ?? '—'}</td>
                    <td className="px-5 py-3.5">
                      <StatusChip tone={user.is_active ? 'tint-green' : 'tint-gray'}>
                        {user.is_active ? 'Active' : 'Inactive'}
                      </StatusChip>
                    </td>
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-2">
                        {canReassign && (
                          <button
                            type="button"
                            onClick={() => setModal({ kind: 'reassign', user })}
                            // Transparent border, not no border: the outlined
                            // buttons beside it carry a 1px one, so without this
                            // the filled button stands 2px shorter than its row.
                            className="rounded-full border border-transparent bg-royal-deep px-4 py-1.5 text-xs font-semibold text-white hover:brightness-110"
                          >
                            Reassign
                          </button>
                        )}
                        <button
                          type="button"
                          onClick={() => setModal({ kind: 'edit', user })}
                          className="rounded-full border border-line bg-white px-4 py-1.5 text-xs font-semibold text-ink-secondary hover:bg-canvas"
                        >
                          Edit
                        </button>
                        {/*
                          The sole super admin cannot be switched off — it holds
                          the only `user.manage` in the system, so deactivating
                          it locks every administrative action out of the app
                          permanently. The API refuses it; offering a button
                          that always fails is worse than not offering one, so
                          the reason is stated where the control would be.
                        */}
                        <button
                          type="button"
                          onClick={() => setModal({ kind: 'deactivate', user })}
                          disabled={busyId === user.id || isSuperAdmin(user, roleList)}
                          title={
                            isSuperAdmin(user, roleList)
                              ? 'The only super admin cannot be deactivated — no other account can manage accounts.'
                              : undefined
                          }
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

          <div className="flex items-center justify-between gap-4 border-t border-line px-5 py-3.5">
            <p className="text-sm text-ink-muted">
              Showing {rows.length.toLocaleString()} of {total.toLocaleString()} accounts
              {filtersApplied && ' matching these filters'}
            </p>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                aria-label="Previous page"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1 || loading}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas disabled:opacity-40"
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
                disabled={page >= lastPage || loading}
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
        <ReassignModal
          user={modal.user}
          onClose={() => setModal(null)}
          onDone={(text) => {
            setModal(null)
            setBanner({ tone: 'ok', text })
            reload()
          }}
        />
      )}
      {modal?.kind === 'edit' && (
        <EditModal
          user={modal.user}
          departments={departments ?? []}
          roles={roleList}
          onClose={() => setModal(null)}
          onSaved={(updated, note) => {
            patchUser(updated)
            setModal(null)
            setBanner({ tone: 'ok', text: `Saved ${fullName(updated)}.${note ? ` ${note}` : ''}` })
          }}
        />
      )}
      {modal?.kind === 'deactivate' && (
        <DeactivateModal
          user={modal.user}
          busy={busyId === modal.user.id}
          onCancel={() => setModal(null)}
          onConfirm={() => toggleActive(modal.user)}
        />
      )}
      {modal?.kind === 'create' && (
        <CreateOfficerModal
          departments={departments ?? []}
          roles={roleList}
          onClose={() => setModal(null)}
          onCreated={(name) => {
            setModal(null)
            setBanner({ tone: 'ok', text: `${name} can now sign in to the staff portal.` })
            reload()
          }}
        />
      )}
    </div>
  )
}
