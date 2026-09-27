import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import type { ReactNode } from 'react'
import { admin, reference } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { toApiError } from '../../lib/api'
import { formatDateTime } from '../../lib/format'
import { useAuth } from '../../stores/auth'
import type {
  AdminRole,
  AdminUser,
  AdminUserPayload,
  AuditLog,
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
import {
  FieldError,
  MobileField,
  PasswordField,
  RolePicker,
  mobileLooksRight,
  normaliseMobile,
  passwordMeetsRules,
} from './officerFields'

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

/*
 * ── Reassign moved to a page of its own ──────────────────────────────────
 *
 * A ~300-line dialog stood here. It held two opposite acts — empty this desk,
 * fill this desk — each with its own list, selection and button, plus a
 * target, a reason, and a count that had to explain why it disagreed with the
 * Officer in Charge register.
 *
 * That is a screen's worth of decision inside a box that darkens the thing it
 * is deciding about. The client asked for it to become a page in the Officer
 * in Charge format, scoped to the officer whose row was clicked [27 September
 * 2026]; it lives in `OfficerCaseloadPage`, and the row above links to it.
 *
 * `SCOPES` and `scopeCount` went with it. The category chooser — everything /
 * reviews only / inspections only — was already dead on this screen: Scope had
 * become the list of permits the officer is actually holding, and the
 * categories only still rendered for a payload old enough to carry no list.
 * The page selects rows, and `scope` remains on the API for Deactivate, which
 * releases a whole caseload without naming forty ids.
 */

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
    /*
     * A job title typed in because the office has no role by that name.
     *
     * Kept apart from `role` rather than folded into it, and that separation
     * is the safety of the whole feature: `role` names a role that EXISTS,
     * `newRole` one the server is being asked to create. One field would have
     * made "give them the sanitary role" and "invent a role called sanitary"
     * the same request, told apart only by whether a lookup happened to miss.
     */
    newRole: '',
    // 'none' for an account that works across every office; see below.
    department_id: user.department ? String(user.department.id) : 'none',
  })

  /*
   * -- Issuing a new password --------------------------------------------
   *
   * There was no way to do this from anywhere in the app. An officer who
   * forgot theirs had to be deactivated and recreated under a second account,
   * which loses the name on every filing they had already handled
   * [client, 27 September 2026: *"make it add changing password"*].
   *
   * Behind a toggle rather than as a seventh field, because almost every edit
   * is a typo in a surname, and a password box sitting open on a form about
   * something else is an invitation to type in it by accident. Closing the
   * toggle clears it, so a half-typed password cannot be saved by a reader who
   * changed their mind.
   */
  const [issuing, setIssuing] = useState(false)
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)

  /*
   * -- The office is asked first, and it decides the roles -----------------
   *
   * It used to run the other way: pick a role, and the Office field appeared
   * or greyed itself out according to what you picked. That put the reader on
   * a question whose options had not been settled yet, and on the edit form it
   * meant the Office select could disable itself under the cursor.
   *
   * The office is the fact an administrator actually knows when they sit down
   * ("Liza is moving to Fire"), so the form asks that, and then offers the
   * roles that can hold it [client, 27 September 2026: *"unahin muna ang kung
   * anong office then doon pipili ng role"*].
   *
   * `'none'` is a real choice and not the empty one: an account attached to no
   * office is the super admin, and saying so in the list is how the reader
   * learns the two facts go together. `''` is still "not answered".
   */
  const officeChosen = form.department_id !== ''
  const worksEverywhere = form.department_id === 'none'
  const needsOffice = !worksEverywhere

  /*
   * Only the roles the chosen office can hold. The super admin belongs to no
   * office and every other role must have one - the API refuses the other
   * combinations (`assertOfficeMatchesRole`), so offering them here would be
   * offering a choice the save will reject.
   */
  const rolesForOffice = useMemo(
    () => roles.filter((r) => r.wants_department === !worksEverywhere),
    [roles, worksEverywhere],
  )

  const officeChanged =
    (worksEverywhere ? '' : form.department_id) !== (user.department ? String(user.department.id) : '')
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
  // A typed title is always a change: whatever the account holds now, it is
  // not a role that did not exist a moment ago.
  const roleTyped = form.newRole.trim() !== ''
  /*
   * The super-admin row. Its role is fixed both ways — the seat is a singleton
   * and it cannot be vacated, because `user.manage` lives on no other role and
   * emptying it would lock every administrative action out of the app.
   */
  const currentRole = roles.find((r) => r.name === initialRole)
  // `roles` is empty while it loads, and an unknown role must not be mistaken
  // for the super admin — hence the explicit "found it, and it wants no office".
  const isOnlySuperAdmin = currentRole ? !currentRole.wants_department : false

  /*
   * -- What is about to change, in the reader's words ----------------------
   *
   * Saving used to write straight from the form. The form is six controls on
   * one screen and this is somebody's account - their email is how they are
   * reached, their role is what they may do, their office is which work
   * reaches them at all - so the last press deserves to show its work
   * [client, 27 September 2026: "sa lahat ng major decision ... dapat modal
   * na confirmation"].
   *
   * Built from FORM AGAINST RECORD, field by field, so the list is the
   * payload: anything the request will change appears here and nothing else
   * does. A dialog that summarised the act in prose could drift from what is
   * sent; this cannot.
   */
  const changes = useMemo(() => {
    const list: { field: string; from: string; to: string }[] = []
    const text = (field: string, from: string, to: string) => {
      if (from.trim() !== to.trim()) {
        list.push({ field, from: from.trim() || 'Not set', to: to.trim() || 'Not set' })
      }
    }
    text('Surname', user.last_name, form.last_name)
    text('Given name', user.first_name, form.first_name)
    text('Email', user.email, form.email)
    text('Mobile', user.mobile_number ?? '', form.mobile_number)
    if (roleChanged || roleTyped) {
      list.push({
        field: 'Role',
        from: roles.find((r) => r.name === initialRole)?.label ?? initialRole ?? 'None',
        // A typed title is marked as new HERE rather than left to look like
        // any other role: the reader is confirming that a role will be
        // created, which is a different act from moving somebody onto one.
        to: roleTyped
          ? `${form.newRole.trim()} — new role`
          : (roles.find((r) => r.name === form.role)?.label ?? form.role),
      })
    }
    if (officeChanged) {
      list.push({
        field: 'Office',
        from: user.department?.name ?? 'Works across every office',
        to: worksEverywhere
          ? 'Works across every office'
          : (departments.find((d) => String(d.id) === form.department_id)?.name ?? 'Not set'),
      })
    }
    if (issuing && password) {
      /*
        Named, never shown. The review list exists so the reader can check
        what they are about to do, and "a new one" is the whole of what they
        need to check here - printing the password into a list that stays on
        screen while they read the rest of it is not.
      */
      list.push({ field: 'Password', from: 'Their current one', to: 'A new one' })
    }
    return list
  }, [
    user,
    form,
    roleChanged,
    roleTyped,
    officeChanged,
    worksEverywhere,
    roles,
    departments,
    initialRole,
    issuing,
    password,
  ])

  /*
   * One dialog, two views, rather than a second dialog on top of this one.
   * Stacked overlays mean two focus traps and an Escape key whose meaning
   * depends on which one you believe is in front; a reader who wants to change
   * something presses Back and is on the field, with everything they typed
   * still there.
   */
  const [review, setReview] = useState(false)

  async function save() {
    setBusy(true)
    setFormError(null)
    setErrors({})
    try {
      const updated = await admin.updateUser(user.id, {
        first_name: form.first_name.trim(),
        last_name: form.last_name.trim(),
        email: form.email.trim(),
        mobile_number: normaliseMobile(form.mobile_number),
        // Only when one was actually typed. An empty string here is what used
        // to reach the column as null - see the note in UserController@update.
        ...(issuing && password ? { password } : {}),
        roles: roleChanged ? [form.role] : undefined,
        // The server turns this into a real role and then handles it exactly
        // like a chosen one — see UserController::roleFromTypedTitle.
        ...(roleTyped ? { new_role: form.newRole.trim() } : {}),
        // Null clears it, which is what the super admin needs and what "No
        // office" silently failed to do before: the payload simply omitted the
        // key, so the modal closed reporting success with the office unchanged.
        department_id: worksEverywhere ? null : Number(form.department_id),
      } as Partial<AdminUserPayload>)
      onSaved(
        updated,
        officeChanged
          ? 'Moved office. Any open cases in the old office were handed back to it.'
          : null,
      )
    } catch (err) {
      const apiError = toApiError(err)
      if (apiError.status === 422) {
        /*
         * Back to the form. A 422 is the server pointing at a field, and its
         * message renders under that field - leaving the reader on the review
         * list would show them a red line with no field in sight.
         */
        setErrors(apiError.errors)
        setReview(false)
      } else setFormError(apiError.message)
      setBusy(false)
    }
  }

  /*
   * Everything the form itself can tell is wrong, named one at a time so the
   * line under the button says what to do rather than that something is amiss.
   * The server still decides; this only saves a round trip.
   */
  const blocker: string | null = !officeChosen
    ? 'Choose an office first — it decides which roles are available.'
    : !form.role && !roleTyped
      ? 'Choose the role this account signs in with, or type the office’s own job title.'
      : form.mobile_number.trim() !== '' && !mobileLooksRight(form.mobile_number)
        ? 'The mobile number needs to be 11 digits starting 09.'
        : issuing && !passwordMeetsRules(password)
          ? 'The new password does not meet all four requirements yet.'
          : null

  const incomplete = blocker !== null

  return (
    <ProtoModal
      title={review ? 'Save these changes?' : 'Editing'}
      cancelLabel={review ? 'Back' : 'Cancel'}
      confirmLabel={review ? (busy ? 'Saving…' : 'Save changes') : 'Review changes'}
      onCancel={review ? () => setReview(false) : onClose}
      onConfirm={review ? save : () => setReview(true)}
      confirmDisabled={busy || incomplete || changes.length === 0}
      /*
       * Reachable and pressable while it waits, pointing at the sentence that
       * says what for - see the note on confirmDescribedBy in Proto.tsx.
       */
      confirmDescribedBy={
        !review && (incomplete || changes.length === 0) ? 'edit-nothing-to-save' : undefined
      }
    >
      <div className="mb-5 flex items-center gap-3 border-b border-line pb-4">
        <Avatar user={user} size="lg" />
        <div className="min-w-0">
          <p className="text-sm font-bold text-ink">{review ? fullName(user) : 'Edit officer'}</p>
          <p className="truncate text-xs text-ink-muted">{user.email}</p>
        </div>
      </div>

      {review && (
        <div className="space-y-4">
          <p className="text-sm text-ink">
            {changes.length === 1 ? 'One field' : `${changes.length} fields`} will change on this
            account.
          </p>

          {/*
            Old beside new, not just new.

            "Email: liza.reyes@malabon.gov.ph" tells a reader nothing about
            whether they fixed the typo or introduced one. The pair does.
          */}
          <dl className="divide-y divide-line rounded-lg border border-line">
            {changes.map((c) => (
              <div key={c.field} className="grid grid-cols-3 gap-3 px-4 py-3">
                <dt className="text-xs font-semibold text-ink-muted">{c.field}</dt>
                <dd className="col-span-2 text-sm text-ink">
                  <span className="text-ink-muted line-through">{c.from}</span>
                  <span className="mx-2 text-ink-muted">→</span>
                  <span className="font-semibold">{c.to}</span>
                </dd>
              </div>
            ))}
          </dl>

          {issuing && password && (
            <p className="rounded-lg bg-s-yellow-tint px-3.5 py-3 text-xs leading-relaxed text-amber-800">
              <span className="font-bold">{fullName(user)} will be signed out everywhere</span> and
              will need the new password to get back in. Give it to them yourself — it is stored
              scrambled, so nobody can read it back afterwards.
            </p>
          )}

          {officeChanged && needsOffice && (
            /*
              The one change with a consequence beyond this record, said BEFORE
              it happens. It was only ever reported afterwards, in the toast.
            */
            <p className="rounded-lg bg-s-yellow-tint px-3.5 py-3 text-xs leading-relaxed text-amber-800">
              Moving office also hands any open cases back to{' '}
              {user.department?.code ?? 'their old office'} as unassigned. The cases stay where they
              are; only this officer&apos;s name comes off them.
            </p>
          )}

          {formError && (
            <p role="alert" className="rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
              {formError}
            </p>
          )}
        </div>
      )}

      {!review && formError && (
        <p role="alert" className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
          {formError}
        </p>
      )}

      {/*
        HIDDEN, not unmounted. The fields keep their values and the browser
        keeps its undo history, so Back really is back rather than a fresh
        form that happens to look the same.
      */}
      <div className={review ? 'hidden' : 'space-y-4'} aria-hidden={review || undefined}>
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
          <MobileField
            value={form.mobile_number}
            onChange={(v) => setForm((f) => ({ ...f, mobile_number: v }))}
            error={firstError(errors, 'mobile_number')}
          />
        </div>
        {/*
          Office, then role. The office narrows the list the role is chosen
          from, so it is asked first and gets its own row - side by side, the
          second field would look answerable before the first one had been.
        */}
        <label className="block">
          <FieldLabel required>Office</FieldLabel>
          <select
            className={inputCls}
            value={form.department_id}
            disabled={isOnlySuperAdmin}
            onChange={(e) =>
              setForm((f) => ({
                ...f,
                department_id: e.target.value,
                /*
                  A role belonging to the office they just left cannot survive
                  the move, so it is cleared rather than left to be refused on
                  save. The current role stays if it still fits.
                */
                role: roles.find((r) => r.name === f.role)?.wants_department === (e.target.value !== 'none')
                  ? f.role
                  : '',
                /*
                  And a typed title always goes. It was written FOR the office
                  that was showing — carrying "Sanitary Inspector II" over to
                  the fire station would create it there, silently, because
                  nothing on screen says which office a typed role belongs to
                  except the one selected above it.
                */
                newRole: '',
              }))
            }
          >
            {/*
              Nothing is not the same as nothing yet. The offices arrive in
              their own request, and "Choose an office…" above an empty list
              is an instruction the reader cannot follow - worse, the next
              option down is "No office", so a reader obeying the prompt picks
              the super-admin answer by elimination.
            */}
            <option value="">
              {departments.length === 0 ? 'Loading offices…' : 'Choose an office…'}
            </option>
            {departments.map((d) => (
              <option key={d.id} value={d.id}>
                {d.code} — {d.name}
              </option>
            ))}
            <option value="none">No office — works across every one (super admin)</option>
          </select>
          <FieldError message={firstError(errors, 'department_id')} />
          {isOnlySuperAdmin && (
            <p className="mt-1 text-xs text-ink-muted">
              The super admin is a single account and the only one that can create office accounts,
              so it cannot be moved into an office.
            </p>
          )}
        </label>

        {/*
          Editable here for the first time. The role decides which queue an
          officer sees, and it could only ever be set at creation - so the only
          way to correct one was to deactivate the account and make a second.
        */}
        <RolePicker
          roles={rolesForOffice}
          // So the office's own roles come first. Ordering, not filtering —
          // see the note in RolePicker.
          officeId={worksEverywhere ? undefined : form.department_id}
          value={form.role}
          typed={form.newRole}
          currentRole={initialRole}
          onChange={(name, typedTitle) =>
            setForm((f) => ({ ...f, role: name, newRole: typedTitle }))
          }
          /*
            Never on the super-admin path. `admin` holds `user.manage` — the
            power to mint accounts — so a typed name that reached a
            departmentless role would be privilege escalation by spelling. The
            server refuses it too; this keeps the offer off a screen where it
            could not be honoured.
          */
          allowTyped={needsOffice}
          disabled={isOnlySuperAdmin || !officeChosen}
          disabledReason={
            isOnlySuperAdmin
              ? 'The super admin holds the only role that answers for every office, so its role cannot be changed away.'
              : 'Choose an office above first — it decides which roles are available.'
          }
          error={firstError(errors, 'new_role', 'roles', 'roles.0', 'role')}
        />
        {user.roles.length > 1 && (
          <p className="text-xs text-amber-800">
            This account holds {user.roles.length} roles ({user.roles.join(', ')}). Choosing one here
            replaces all of them with it.
          </p>
        )}

        {/*
          -- The password, behind a door ------------------------------------

          An officer who forgets theirs had no way back: there is no reset on
          the staff portal, so the account was deactivated and a second one
          made, which takes their name off every filing they had handled.

          Shut by default and cleared when it shuts, because almost every edit
          on this form is a typo in a surname and a password box standing open
          on that form is somewhere to type by accident.
        */}
        <div className="rounded-lg border border-line px-4 py-3">
          <label className="flex cursor-pointer items-start gap-2.5">
            <input
              type="checkbox"
              checked={issuing}
              onChange={(e) => {
                setIssuing(e.target.checked)
                if (!e.target.checked) setPassword('')
              }}
              className="mt-0.5 h-4 w-4 shrink-0 accent-royal"
            />
            <span className="text-sm font-semibold text-ink">
              Give this account a new password
              <span className="ml-2 font-normal text-ink-muted">
                for somebody who is locked out
              </span>
            </span>
          </label>

          {issuing && (
            <div className="mt-4">
              <PasswordField
                label="New password"
                required
                value={password}
                onChange={setPassword}
                error={firstError(errors, 'password')}
                hint="You will have to pass it to them yourself, and they should change it. It is stored scrambled, so nobody — including you — can read it back afterwards."
              />
            </div>
          )}
        </div>

        {officeChanged && needsOffice && (
          <p className="rounded-lg bg-s-yellow-tint px-3.5 py-3 text-xs leading-relaxed text-amber-800">
            Moving office hands any open cases back to the office they belong to. The cases stay
            where they are; only this officer&apos;s name comes off them.
          </p>
        )}

        {(incomplete || changes.length === 0) && (
          <p id="edit-nothing-to-save" className="text-xs text-ink-muted">
            {blocker ?? 'Nothing has changed yet, so there is nothing to save.'}
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
  /**
   * A job title typed in because the office has no role by that name.
   *
   * Apart from `role` on purpose: that one names a role that EXISTS, this one
   * a role the server is being asked to create. Folding them together would
   * make "give them the sanitary role" and "invent a role called sanitary"
   * the same request, told apart only by whether a lookup happened to miss.
   */
  new_role: string
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
  new_role: '',
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

  /*
   * Office first, then role - the same order as the edit form, and for the
   * same reason: the office settles which roles are possible, so asking for
   * the role first is asking a question whose options are not decided yet.
   * See the longer note in EditModal.
   */
  const worksEverywhere = form.department_id === 'none'
  const needsOffice = !worksEverywhere
  const officeChosen = form.department_id !== ''
  const rolesForOffice = roles.filter((r) => r.wants_department === !worksEverywhere)
  const selectedRole = roles.find((r) => r.name === form.role)

  /*
   * -- Reviewing the account before it exists ------------------------------
   *
   * Creating one grants a stranger a way into the system with a role attached,
   * and two of the fields decide how much: the role is what they may do, the
   * office is whose work they will see. A long form makes those easy to lose
   * among the name fields, so they are read back on their own before the
   * account is made.
   *
   * It is also the last moment the password is visible. After this it exists
   * only wherever the administrator wrote it down, and there is no screen in
   * the app that will show it to them again.
   */
  const [review, setReview] = useState(false)

  const missing = [
    !form.first_name.trim() && 'given name',
    !form.last_name.trim() && 'surname',
    !form.gender && 'sex',
    !form.email.trim() && 'email',
    !form.mobile_number.trim() && 'mobile number',
    !form.password && 'password',
    !officeChosen && 'office',
    // A typed title counts: the reader has answered the question, and the
    // answer is a role that does not exist yet.
    !form.role && !form.new_role.trim() && 'role',
  ].filter(Boolean) as string[]

  /*
   * Present but not acceptable, which is a different sentence from absent.
   * "Still needed: password" is wrong advice for somebody who has typed one
   * and just needs a symbol in it.
   */
  const wrong: string | null =
    form.mobile_number.trim() !== '' && !mobileLooksRight(form.mobile_number)
      ? 'The mobile number needs to be 11 digits starting 09.'
      : form.password !== '' && !passwordMeetsRules(form.password)
        ? 'The password does not meet all four requirements yet.'
        : null

  const notReady = missing.length > 0 || wrong !== null

  async function handleSubmit() {
    setSubmitting(true)
    setFormError(null)
    setErrors({})

    const payload: AdminUserPayload = {
      first_name: form.first_name.trim(),
      last_name: form.last_name.trim(),
      gender: (form.gender || 'M') as 'M' | 'F',
      email: form.email.trim(),
      mobile_number: normaliseMobile(form.mobile_number),
      password: form.password,
      // `roles`, plural — the shape the endpoint has always validated. This
      // used to send `role` and 422 every single time.
      roles: form.role ? [form.role] : [],
      /*
       * Only when nothing was picked. The server reads `new_role` only in
       * that case too, but sending both would leave the question of which one
       * wins to be settled on the far end — and the answer would differ
       * between the two endpoints the moment one of them changed.
       */
      ...(!form.role && form.new_role.trim() ? { new_role: form.new_role.trim() } : {}),
      ...(form.middle_name.trim() ? { middle_name: form.middle_name.trim() } : {}),
      ...(form.suffix.trim() ? { suffix: form.suffix.trim() } : {}),
      ...(needsOffice && officeChosen ? { department_id: Number(form.department_id) } : {}),
    }

    try {
      await admin.createUser(payload)
      onCreated(`${payload.first_name} ${payload.last_name}`.trim())
    } catch (err) {
      const apiError = toApiError(err)
      if (apiError.status === 422) {
        // Back to the fields the messages belong under. See EditModal.
        setErrors(apiError.errors)
        setReview(false)
      } else setFormError(apiError.message)
      setSubmitting(false)
    }
  }

  return (
    <ProtoModal
      title={review ? 'Create this account?' : 'Add Officer'}
      wide
      cancelLabel={review ? 'Back' : 'Cancel'}
      confirmLabel={review ? (submitting ? 'Creating…' : 'Create account') : 'Review account'}
      onCancel={review ? () => setReview(false) : onClose}
      onConfirm={review ? handleSubmit : () => setReview(true)}
      confirmDisabled={submitting || (!review && notReady)}
      confirmDescribedBy={!review && notReady ? 'create-missing' : undefined}
    >
      <p className="mb-5 border-b border-line pb-3 text-sm text-ink-secondary">
        {review
          ? 'Check the role and office. They decide what this person may do and whose work reaches them.'
          : 'Give an LGU staff member access with a role and office.'}
      </p>

      {review && (
        <div className="space-y-4">
          <dl className="divide-y divide-line rounded-lg border border-line">
            {[
              {
                label: 'Name',
                value: [form.first_name, form.middle_name, form.last_name, form.suffix]
                  .map((part) => part.trim())
                  .filter(Boolean)
                  .join(' '),
              },
              { label: 'Email', value: form.email.trim() },
              { label: 'Mobile', value: form.mobile_number.trim() || 'Not set' },
              {
                label: 'Role',
                // Marked as new, because creating a role is a second act the
                // reader is confirming — not a detail of creating an account.
                value: form.new_role.trim()
                  ? `${form.new_role.trim()} — new role for this office`
                  : (selectedRole?.label ?? form.role),
              },
              {
                label: 'Office',
                value: worksEverywhere
                  ? 'Works across every office'
                  : (departments.find((d) => String(d.id) === form.department_id)?.name ?? 'Not set'),
              },
            ].map((row) => (
              <div key={row.label} className="grid grid-cols-3 gap-3 px-4 py-3">
                <dt className="text-xs font-semibold text-ink-muted">{row.label}</dt>
                <dd className="col-span-2 text-sm font-semibold text-ink">{row.value}</dd>
              </div>
            ))}
          </dl>

          {/*
            The password, shown once more.

            It is stored hashed, so this is genuinely the last time anybody can
            read it. Saying so here is the difference between an administrator
            copying it now and ringing back tomorrow to ask for it.
          */}
          <p className="rounded-lg bg-s-yellow-tint px-3.5 py-3 text-xs leading-relaxed text-amber-800">
            Give them this password yourself: <span className="font-bold">{form.password}</span>. It
            is stored scrambled, so this is the last time it can be read. They sign in at{' '}
            <span className="font-semibold">/staff/login</span> and should change it.
          </p>

          {formError && (
            <p role="alert" className="rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
              {formError}
            </p>
          )}
        </div>
      )}

      {!review && formError && (
        <p role="alert" className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
          {formError}
        </p>
      )}
      <div className={review ? 'hidden' : 'space-y-4'} aria-hidden={review || undefined}>
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
          <MobileField
            value={form.mobile_number}
            onChange={(v) => set('mobile_number', v)}
            error={firstError(errors, 'mobile_number')}
          />
        </div>
        <label className="block">
          <FieldLabel required>Email address</FieldLabel>
          <input type="email" className={inputCls} value={form.email} onChange={(e) => set('email', e.target.value)} />
          <FieldError message={firstError(errors, 'email')} />
        </label>
        {/*
          The rules on screen before they are broken, rather than a sentence
          saying "at least 8 characters" beside a field the server holds to
          four separate clauses. See PasswordField.
        */}
        <PasswordField
          label="Temporary password"
          required
          value={form.password}
          onChange={(v) => set('password', v)}
          error={firstError(errors, 'password')}
          hint="You hand this to them; they should change it after their first sign-in."
        />
        {/*
          Office, then role — the office narrows the list the role comes from,
          so it is asked first and on its own row. Same shape as the edit form,
          deliberately: these two dialogs ask the same questions and ought to
          ask them the same way.
        */}
        <label className="block">
          <FieldLabel required>Office</FieldLabel>
          <select
            className={inputCls}
            value={form.department_id}
            onChange={(e) => {
              set('department_id', e.target.value)
              // A role that belonged to the other kind of account cannot
              // survive the change, so it is cleared rather than refused later.
              if (roles.find((r) => r.name === form.role)?.wants_department !== (e.target.value !== 'none')) {
                set('role', '')
              }
              // A typed title always goes — it was written for the office that
              // was showing. See the same note on the edit form.
              set('new_role', '')
            }}
          >
            {/*
              Nothing is not the same as nothing yet. The offices arrive in
              their own request, and "Choose an office…" above an empty list
              is an instruction the reader cannot follow - worse, the next
              option down is "No office", so a reader obeying the prompt picks
              the super-admin answer by elimination.
            */}
            <option value="">
              {departments.length === 0 ? 'Loading offices…' : 'Choose an office…'}
            </option>
            {departments.map((d) => (
              <option key={d.id} value={d.id}>
                {d.code} — {d.name}
              </option>
            ))}
            {/*
              "No office" as a blank is gone. An officer without one signs in
              to an empty queue — the review queue scopes by department — and
              the API refuses it. The one account that legitimately has none is
              the super admin, and this names it rather than leaving it to be
              discovered by picking a role.
            */}
            <option value="none">No office — works across every one (super admin)</option>
          </select>
          <FieldError message={firstError(errors, 'department_id')} />
        </label>

        <RolePicker
          roles={rolesForOffice}
          officeId={worksEverywhere ? undefined : form.department_id}
          value={form.role}
          typed={form.new_role}
          onChange={(name, typedTitle) => {
            set('role', name)
            set('new_role', typedTitle)
          }}
          // See the note on the edit form's picker: never on the super-admin
          // path, where a typed name would be escalation by spelling.
          allowTyped={needsOffice}
          disabled={!officeChosen}
          disabledReason="Choose an office above first — it decides which roles are available."
          error={firstError(errors, 'new_role', 'roles', 'roles.0', 'role')}
        />

        {notReady && (
          /*
            Named, not counted. "6 fields required" sends a reader back up the
            form hunting; a list tells them where to look (WCAG 3.3.1/3.3.3).
            And a field that is filled in wrongly gets its own sentence, since
            "still needed" is the wrong instruction for it.
          */
          <p id="create-missing" className="text-xs text-ink-muted">
            {wrong ?? `Still needed before this can be reviewed: ${missing.join(', ')}.`}
          </p>
        )}
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

  /*
   * Confirm is held while the caseload is still being counted.
   *
   * The amber line below is the whole reason this dialog is worth showing for
   * a deactivation - "4 open cases will be handed back to BPLO" is the fact
   * that changes the answer. It arrives a moment after the dialog opens, and
   * until it does the button was pressable, so a quick hand could decide
   * before reading the thing it was meant to read. `confirmDescribedBy` points
   * at the reason it is waiting, because a button that is merely grey explains
   * nothing (WCAG 3.3.1).
   */
  const counting = user.is_active && loading

  return (
    <ProtoModal
      /*
       * The title NAMES the act. It read "WARNING" - shouted, and describing a
       * feeling rather than an event, on a reactivation that warns of nothing.
       * The person's name belongs here too: this dialog is reached from a row
       * in a directory of forty, and the one thing worth double-checking is
       * that it is the row you meant.
       */
      title={user.is_active ? `Deactivate ${fullName(user)}?` : `Reactivate ${fullName(user)}?`}
      tone={user.is_active ? 'red' : 'blue'}
      cancelLabel="Cancel"
      /*
       * "Yes" answers a question nobody re-reads at the moment of pressing.
       * The verb is the label, so the last thing seen before committing is
       * what will happen.
       */
      confirmLabel={user.is_active ? 'Deactivate account' : 'Reactivate account'}
      onCancel={onCancel}
      onConfirm={onConfirm}
      confirmDisabled={busy || counting}
      confirmDescribedBy={counting ? 'deactivate-counting' : undefined}
    >
      {user.is_active ? (
        <p className="pb-4 text-sm text-ink-secondary">
          They will be signed out straight away and will not be able to sign in again. Their name
          stays on everything they have already done.
        </p>
      ) : (
        <p className="pb-4 text-sm text-ink-secondary">
          They will be able to sign in again at once. Nothing is handed back to them automatically -
          any work they were carrying was released when the account was deactivated.
        </p>
      )}

      {counting && (
        <p id="deactivate-counting" className="pb-3 text-sm text-ink-muted">
          Checking what they are carrying…
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

/**
 * What one officer is carrying, as the directory cell prints it.
 *
 * ── "Nothing", not a dash ─────────────────────────────────────────────────
 *
 * A dash means "no value"; this has a value and it is zero. An officer with a
 * clear desk is a fact an administrator is looking FOR — it is who the next
 * case goes to — so the cell says it rather than leaving absence to be read as
 * ignorance.
 *
 * The dash is gone, and not by defaulting the missing case to "Nothing" —
 * that would have printed a zero the server never sent. Creating, editing and
 * activating an officer each answer with the changed user and the page puts
 * that answer back into the row, and none of those three counted the
 * caseload; the key was absent, so a row reading "2 filings" turned into "—"
 * the moment it was edited [client, 27 September 2026: *"bat may ganyan pa sa
 * holding, kung wala, it should be automatic na Nothing"*]. All four
 * endpoints count it now (`UserController::withCaseload`), so there is no
 * longer a payload this cell has to apologise for.
 *
 * ── The number is not a link ──────────────────────────────────────────────
 *
 * Reassign, two cells along, already opens that officer's caseload. Two
 * controls to one destination in one row is two things to explain. This is
 * here to say WHICH row to press, not to be pressed.
 */
function holding(user: AdminUser) {
  const inspections = user.open_inspections ?? 0
  const total = (user.open_reviews ?? 0) + inspections

  if (total === 0) return <span className="text-sm text-ink-muted">Nothing</span>

  return (
    <span className="text-sm font-semibold text-ink">
      <span className="tnum">{total}</span>{' '}
      <span className="font-normal text-ink-muted">
        {total === 1 ? 'filing' : 'filings'}
        {/*
          Site visits named separately when there are any. They move by a
          different act on the caseload screen, so an administrator planning a
          reassignment needs to know the load is not all paperwork.
        */}
        {inspections > 0 && ` · ${inspections} site visit${inspections === 1 ? '' : 's'}`}
      </span>
    </span>
  )
}

/* ── Page ─────────────────────────────────────────────────────────────── */

type ModalState =
  | { kind: 'details' | 'edit' | 'deactivate'; user: AdminUser }
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
            {/*
              The way to the whole picture.
              
              This directory lists OFFICERS; the Officer in Charge register
              lists ASSIGNMENTS, every office at once, with the holder on each
              row — "Showing 34 of 34". They answer two halves of one question
              and there was no door between them in this direction: the
              register's holder names already link INTO an officer's caseload,
              and a reader starting here had nowhere to go but row by row.
              
              A link, not a button, so it can be middle-clicked and is
              announced as a link. Relative, so it lands on whichever portal
              this page is being read on.
              
              Gated on the same permission the register itself is
              (`oic.assign`): offering a door a reader would be refused at is
              worse than not offering one.
            */}
            {canReassign && (
              <Link
                /*
                  Lands on the register UNFILTERED, because that is what the
                  label promises. Its count line carries the chain from the
                  register's own total down to the figure the Holding column
                  adds up to, and a "Still open" filter sits beside it — so a
                  reader who wants only the open work is one control away,
                  with the context that makes the smaller number mean
                  something.
                  
                  Linking straight to `?state=open` was the other option and it
                  is worse twice over: a button reading "View all assignments"
                  that shows four of thirty-four is a button that lies, and the
                  second door it needed kept landing in the table header, where
                  navigation does not belong.
                */
                to="../oic"
                relative="path"
                className="rounded-full border border-line bg-white px-5 py-2 text-sm font-semibold text-ink-secondary hover:bg-canvas"
              >
                View all assignments
              </Link>
            )}
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
                  {/*
                    What each officer is carrying, so the directory answers
                    "who has work" without the reader opening every row in turn
                    [client, 27 September 2026: "need mo pa pindutin isa isa
                    kung ano laman na permit na hawak nila"].

                    Beside Office rather than at the end: it is a fact ABOUT
                    the officer, and Actions is where the row stops describing
                    and starts offering.
                  */}
                  {/*
                    Just the word.
                    
                    A link to the register's matching view lived in here — first
                    beside the heading, where "Holding open work" read as one
                    four-word column name, then stacked under it, where the
                    two-line cell made every other heading in the row sit
                    unevenly against it. Both were attempts to put navigation
                    inside a column header, which is not what a column header
                    is for.
                    
                    It moved to the page header, beside "View all assignments",
                    where the rest of this screen's navigation already is.
                  */}
                  <th className="px-5 py-3">Holding</th>
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
                    <td className="px-5 py-3.5">{holding(user)}</td>
                    <td className="px-5 py-3.5">
                      <StatusChip tone={user.is_active ? 'tint-green' : 'tint-gray'}>
                        {user.is_active ? 'Active' : 'Inactive'}
                      </StatusChip>
                    </td>
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-2">
                        {/*
                          * Not on an account with NO OFFICE.
                          *
                          * Reassign moves an officer's caseload to a colleague
                          * in their own office, and now also hands them work
                          * from that office's queue. The super admin belongs to
                          * no department — it oversees the register rather than
                          * working inside it — so both halves of the dialog are
                          * empty by construction: nothing to move, no queue to
                          * move it from, and the take endpoint answers 422 on
                          * exactly that ground.
                          *
                          * Keyed on `department`, not on the role's name. An
                          * account is offered this because it belongs to an
                          * office, and that stays true if another
                          * departmentless role is ever added.
                          */}
                        {canReassign && user.department && (
                          <Link
                            /*
                              A LINK to a page, not a button that opens a
                              dialog [client, 27 September 2026]. The caseload
                              screen is the Officer in Charge format scoped to
                              one officer; see OfficerCaseloadPage for why the
                              dialog outgrew itself.

                              Relative, so the same row works on both the staff
                              and the admin portal without this page knowing
                              which one it is on — and a real <Link> rather
                              than an anchor because this is a route WITHIN one
                              portal, unlike the cross-portal links elsewhere
                              in the app which must remount.
                            */
                            to={`${user.id}/reassign`}
                            // Named by the officer, because twenty rows of
                            // "Reassign" are twenty identical stops for a
                            // screen reader (AGENTS.md §6.2).
                            aria-label={`Reassign ${fullName(user)}’s caseload`}
                            // Transparent border, not no border: the outlined
                            // buttons beside it carry a 1px one, so without this
                            // the filled button stands 2px shorter than its row.
                            className="rounded-full border border-transparent bg-royal-deep px-4 py-1.5 text-xs font-semibold text-white hover:brightness-110"
                          >
                            Reassign
                          </Link>
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
                /*
                 * aria-disabled, never the native attribute (§6.2): a disabled
                 * control leaves the tab order, so a keyboard reader loses the
                 * pager entirely at either end of the roster rather than being
                 * told it has reached one. The handlers need no guard — they
                 * already clamp to page 1 and to lastPage.
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

      {modal?.kind === 'details' && <DetailsModal user={modal.user} onClose={() => setModal(null)} />}
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
