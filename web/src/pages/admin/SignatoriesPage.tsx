import { useId, useState } from 'react'
import type { ReactNode } from 'react'
import { toApiError } from '../../lib/api'
import { admin } from '../../lib/resources'
import type { OfficeSignatory, SignatoryOffice } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'
import { ErrorState, SkeletonList } from '../../components/ui/primitives'
import {
  FieldLabel,
  PageTitle,
  ProtoCard,
  ProtoModal,
  inputCls,
  inputClsShort,
} from '../../components/ui/Proto'
import { CheckCircleIcon, PlusIcon } from '../../components/icons'

/*
 * Office Signatories — the names each office signs its documents with.
 *
 * Names on LGU forms are admin-edited data, never baked into a template: an
 * officeholder who leaves keeps printing until somebody redeploys, which is a
 * forgery nobody intended (docs/HANDOFF.md §9.2). The permit certificate prints
 * an office's current signatories in order, and the office's reports print the
 * last of them as "Noted by". The controller behind this screen existed from
 * #51 but had no routes and no screen, so every office but CENRO printed a
 * blank line.
 *
 * Super admin only (`reference.manage`), on the route and in the rail alike.
 *
 * One card per office, the empty ones included: an office with nobody on record
 * is the state this screen exists to find, so it is shown rather than filtered.
 */

const MAX_ORDER = 99

/**
 * The name an office's reports print as "Noted by": its current signatory with
 * the highest order. The same rule as ReportController::notedBy — the API
 * refuses two current signatories at one order, so the two cannot disagree.
 */
function notedByOf(signatories: OfficeSignatory[]): OfficeSignatory | undefined {
  return signatories
    .filter((s) => s.is_active)
    .reduce<OfficeSignatory | undefined>(
      (top, s) => (top === undefined || s.sort_order > top.sort_order ? s : top),
      undefined,
    )
}

/** Read a 422 under the field's key. */
function fieldError(errors: Record<string, string[]>, key: string): string | undefined {
  return errors[key]?.[0]
}

/**
 * A labelled control with its hint and its error beneath it.
 *
 * The label is tied by `htmlFor` rather than by wrapping, so the control's
 * accessible name is the label alone; the hint and the error reach it through
 * `aria-describedby` (see `describedBy`) instead of being read as its name.
 */
function Field({
  id,
  label,
  hint,
  error,
  children,
}: {
  id: string
  label: string
  hint?: string
  error?: string
  children: ReactNode
}) {
  return (
    <div>
      <label htmlFor={id}>
        <FieldLabel required>{label}</FieldLabel>
      </label>
      {children}
      {hint && (
        <p id={`${id}-hint`} className="mt-1 text-xs text-ink-muted">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} className="mt-1 text-xs font-medium text-s-red">
          {error}
        </p>
      )}
    </div>
  )
}

function describedBy(id: string, hasHint: boolean, error: string | undefined): string | undefined {
  const parts = [hasHint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean)
  return parts.length > 0 ? parts.join(' ') : undefined
}

/* ── Add / edit ───────────────────────────────────────────────────────── */

function SignatoryDialog({
  office,
  signatory,
  onClose,
  onSaved,
}: {
  office: SignatoryOffice
  /** Absent = adding a new one to `office`. */
  signatory?: OfficeSignatory
  onClose: () => void
  onSaved: (message: string) => void
}) {
  const editing = signatory !== undefined
  const current = office.signatories.filter((s) => s.is_active && s.id !== signatory?.id)
  /*
   * A newcomer is offered the end of the block, which is the order the API
   * would choose for them anyway. Shown rather than hidden, because it decides
   * whether they become the "Noted by" and the admin should see that coming.
   */
  const nextOrder = current.length
    ? Math.min(MAX_ORDER, Math.max(...current.map((s) => s.sort_order)) + 1)
    : 0

  const [name, setName] = useState(signatory?.name ?? '')
  const [role, setRole] = useState(signatory?.role ?? '')
  const [order, setOrder] = useState(String(signatory?.sort_order ?? nextOrder))
  const [active, setActive] = useState(signatory?.is_active ?? true)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failure, setFailure] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const ids = useId()

  async function save() {
    if (busy) return
    setBusy(true)
    setErrors({})
    setFailure(null)
    const body = {
      name: name.trim(),
      role: role.trim(),
      // Sent as typed — an emptied box goes as null so the API's sentence
      // about it comes back, rather than the screen inventing a number.
      sort_order: order.trim() === '' ? null : Number(order),
      is_active: active,
    }
    try {
      const saved = editing
        ? await admin.updateSignatory(signatory.id, body)
        : await admin.addSignatory(office.id, body)
      onSaved(editing ? `Saved ${saved.name}.` : `Added ${saved.name} to ${office.code}.`)
    } catch (err) {
      const apiError = toApiError(err)
      if (apiError.status === 422 && Object.keys(apiError.errors).length > 0) setErrors(apiError.errors)
      else setFailure(apiError.message)
      setBusy(false)
    }
  }

  const nameError = fieldError(errors, 'name')
  const roleError = fieldError(errors, 'role')
  const orderError = fieldError(errors, 'sort_order')
  const activeError = fieldError(errors, 'is_active')

  return (
    <ProtoModal
      title={editing ? 'Edit signatory' : 'Add signatory'}
      cancelLabel="Cancel"
      confirmLabel={busy ? 'Saving…' : editing ? 'Save changes' : 'Add signatory'}
      onCancel={onClose}
      onConfirm={save}
      confirmDisabled={busy}
    >
      <p className="mb-5 border-b border-line pb-4 text-sm text-ink-secondary">
        <span className="font-bold text-ink">{office.code}</span> · {office.name}
      </p>

      {failure && (
        <p role="alert" className="mb-4 rounded-lg bg-s-red-tint px-3.5 py-2.5 text-sm font-medium text-s-red">
          {failure}
        </p>
      )}

      <div className="space-y-4">
        <Field id={`${ids}-name`} label="Name" error={nameError}>
          <input
            id={`${ids}-name`}
            className={inputCls}
            value={name}
            onChange={(e) => setName(e.target.value)}
            autoComplete="off"
            aria-invalid={nameError ? true : undefined}
            aria-describedby={describedBy(`${ids}-name`, false, nameError)}
          />
        </Field>

        <Field id={`${ids}-role`} label="Position" hint="As printed under the name on the form." error={roleError}>
          <input
            id={`${ids}-role`}
            className={inputCls}
            value={role}
            onChange={(e) => setRole(e.target.value)}
            autoComplete="off"
            aria-invalid={roleError ? true : undefined}
            aria-describedby={describedBy(`${ids}-role`, true, roleError)}
          />
        </Field>

        <Field id={`${ids}-order`} label="Order" hint="Lower numbers print first." error={orderError}>
          <input
            id={`${ids}-order`}
            type="number"
            inputMode="numeric"
            min={0}
            max={MAX_ORDER}
            step={1}
            className={inputClsShort}
            value={order}
            onChange={(e) => setOrder(e.target.value)}
            aria-invalid={orderError ? true : undefined}
            aria-describedby={describedBy(`${ids}-order`, true, orderError)}
          />
        </Field>

        {/*
          Edit only. A new signatory is current by definition; this box is how
          a retired one is brought back, and unticking it does what Retire does.
        */}
        {editing && (
          <div className="flex items-start gap-2.5">
            <input
              id={`${ids}-active`}
              type="checkbox"
              className="mt-1 h-4 w-4 shrink-0 accent-royal"
              checked={active}
              onChange={(e) => setActive(e.target.checked)}
              aria-describedby={describedBy(`${ids}-active`, true, activeError)}
            />
            <div>
              <label htmlFor={`${ids}-active`} className="text-sm font-semibold text-ink">
                Current signatory
              </label>
              <p id={`${ids}-active-hint`} className="text-xs text-ink-muted">
                Unticked, the name stops printing on new forms.
              </p>
              {activeError && (
                <p id={`${ids}-active-error`} className="mt-1 text-xs font-medium text-s-red">
                  {activeError}
                </p>
              )}
            </div>
          </div>
        )}
      </div>
    </ProtoModal>
  )
}

/* ── Retire ───────────────────────────────────────────────────────────── */

function RetireDialog({
  office,
  signatory,
  onClose,
  onRetired,
}: {
  office: SignatoryOffice
  signatory: OfficeSignatory
  onClose: () => void
  onRetired: (message: string) => void
}) {
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState<string | null>(null)

  /*
   * Said BEFORE the decision: retiring the last name moves "Noted by" to
   * whoever is next, or leaves the line blank. That is the consequence an
   * admin cannot see from the button.
   */
  const isNotedBy = notedByOf(office.signatories)?.id === signatory.id
  const successor = notedByOf(office.signatories.filter((s) => s.id !== signatory.id))

  async function confirm() {
    if (busy) return
    setBusy(true)
    setFailure(null)
    try {
      await admin.retireSignatory(signatory.id)
      onRetired(`Retired ${signatory.name}. The entry is kept on record.`)
    } catch (err) {
      setFailure(toApiError(err).message)
      setBusy(false)
    }
  }

  return (
    <ProtoModal
      title="Retire signatory"
      tone="red"
      cancelLabel="Cancel"
      confirmLabel={busy ? 'Retiring…' : 'Retire'}
      onCancel={onClose}
      onConfirm={confirm}
      confirmDisabled={busy}
    >
      <p className="text-base text-ink">
        Retire <span className="font-bold">{signatory.name}</span>, {signatory.role} of {office.code}?
      </p>
      <p className="mt-2 text-sm text-ink-secondary">
        Their name stops printing on {office.code}&rsquo;s new forms. The entry stays on record, and
        can be made current again from Edit.
      </p>
      {isNotedBy && (
        <p className="mt-4 rounded-lg bg-s-yellow-tint px-3.5 py-3 text-sm text-s-yellow-ink">
          {successor
            ? `${successor.name} will print as “Noted by” on ${office.code}’s reports instead.`
            : `${office.code}’s reports will print a blank “Noted by” line until someone is added.`}
        </p>
      )}
      {failure && (
        <p role="alert" className="mt-4 rounded-lg bg-s-red-tint px-3.5 py-2.5 text-sm font-medium text-s-red">
          {failure}
        </p>
      )}
    </ProtoModal>
  )
}

/* ── One office ───────────────────────────────────────────────────────── */

const secondaryBtn =
  'rounded-full border border-line bg-white px-4 py-1.5 text-xs font-semibold text-ink-secondary hover:bg-canvas'

function OfficeCard({
  office,
  onAdd,
  onEdit,
  onRetire,
}: {
  office: SignatoryOffice
  onAdd: () => void
  onEdit: (s: OfficeSignatory) => void
  onRetire: (s: OfficeSignatory) => void
}) {
  const headingId = useId()
  const current = office.signatories.filter((s) => s.is_active)
  const retired = office.signatories.filter((s) => !s.is_active)
  const notedBy = notedByOf(office.signatories)

  return (
    <ProtoCard>
      <section aria-labelledby={headingId}>
        <header className="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
          <div className="min-w-0">
            <h2 id={headingId} className="text-base font-bold text-ink">
              {office.code}
            </h2>
            <p className="text-sm text-ink-secondary">{office.name}</p>
          </div>
          <button
            type="button"
            onClick={onAdd}
            aria-label={`Add a signatory to ${office.code}`}
            className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-royal px-4 py-1.5 text-xs font-semibold text-white hover:bg-royal-hover"
          >
            <PlusIcon size={16} />
            Add
          </button>
        </header>

        {current.length === 0 ? (
          <p className="px-5 py-5 text-sm text-ink-secondary">
            No one on record. {office.code}&rsquo;s reports print a blank &ldquo;Noted by&rdquo; line.
          </p>
        ) : (
          <ol aria-label={`${office.code} signatories, in printed order`} className="divide-y divide-line">
            {current.map((s) => (
              <li key={s.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
                <span className="tnum w-6 shrink-0 text-sm font-semibold text-ink-muted">
                  <span className="sr-only">Order </span>
                  {s.sort_order}
                </span>
                <div className="min-w-0 flex-1">
                  <p className="font-semibold break-words text-ink">{s.name}</p>
                  <p className="text-sm break-words text-ink-secondary">{s.role}</p>
                </div>
                <div className="flex w-full items-center gap-2 pl-10 sm:w-auto sm:pl-0">
                  {notedBy?.id === s.id && (
                    <span className="mr-auto rounded-md border border-line px-2 py-0.5 text-xs font-semibold text-ink-secondary sm:mr-1">
                      Noted by
                    </span>
                  )}
                  <button type="button" onClick={() => onEdit(s)} aria-label={`Edit ${s.name}`} className={`${secondaryBtn} ml-auto sm:ml-0`}>
                    Edit
                  </button>
                  <button
                    type="button"
                    onClick={() => onRetire(s)}
                    aria-label={`Retire ${s.name}`}
                    className="rounded-full border border-line bg-white px-4 py-1.5 text-xs font-semibold text-s-red hover:bg-s-red-tint"
                  >
                    Retire
                  </button>
                </div>
              </li>
            ))}
          </ol>
        )}

        {/*
          The retired, collapsed. They print nowhere, but they are the record of
          who signed before, and Edit is how one is made current again.
        */}
        {retired.length > 0 && (
          <details className="border-t border-line px-5 py-3">
            <summary className="cursor-pointer text-sm font-semibold text-ink-secondary">
              Retired ({retired.length})
            </summary>
            <ul className="mt-2 space-y-2">
              {retired.map((s) => (
                <li key={s.id} className="flex flex-wrap items-center justify-between gap-2">
                  <span className="min-w-0 text-sm break-words text-ink-secondary">
                    <span className="font-semibold text-ink">{s.name}</span> · {s.role}
                  </span>
                  <button type="button" onClick={() => onEdit(s)} aria-label={`Edit ${s.name}`} className={secondaryBtn}>
                    Edit
                  </button>
                </li>
              ))}
            </ul>
          </details>
        )}
      </section>
    </ProtoCard>
  )
}

/* ── Page ─────────────────────────────────────────────────────────────── */

type Dialog =
  | { kind: 'add'; office: SignatoryOffice }
  | { kind: 'edit'; office: SignatoryOffice; signatory: OfficeSignatory }
  | { kind: 'retire'; office: SignatoryOffice; signatory: OfficeSignatory }

export function SignatoriesPage() {
  const { data, loading, error, reload } = useAsync(() => admin.signatories(), [])
  const [dialog, setDialog] = useState<Dialog | null>(null)
  const [notice, setNotice] = useState('')

  const offices = data ?? []

  function done(message: string) {
    setDialog(null)
    setNotice(message)
    // Re-read rather than patch: an edit can move who is "Noted by", which is
    // a fact about the whole office, not the one row.
    reload()
  }

  return (
    <div>
      <PageTitle>Office Signatories</PageTitle>

      <p className="mb-5 max-w-prose text-sm text-ink-secondary">
        Each office&rsquo;s current names print on its forms in this order. The last one prints as
        &ldquo;Noted by&rdquo; on that office&rsquo;s reports.
      </p>

      {/*
        Ink on the green tint, with the tick carrying the colour: s-green as
        text on its own tint measures about 2.3:1, which is not a message
        anyone can read.
      */}
      <p
        role="status"
        className={
          notice
            ? 'mb-5 flex items-start gap-2 rounded-lg bg-s-green-tint px-3.5 py-2.5 text-sm font-medium text-ink'
            : 'sr-only'
        }
      >
        {notice && <CheckCircleIcon size={18} className="mt-px shrink-0 text-green-700" />}
        {notice}
      </p>

      {loading && offices.length === 0 ? (
        <SkeletonList rows={4} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : (
        <div className="grid items-start gap-5 xl:grid-cols-2">
          {offices.map((office) => (
            <OfficeCard
              key={office.id}
              office={office}
              onAdd={() => setDialog({ kind: 'add', office })}
              onEdit={(signatory) => setDialog({ kind: 'edit', office, signatory })}
              onRetire={(signatory) => setDialog({ kind: 'retire', office, signatory })}
            />
          ))}
        </div>
      )}

      {dialog?.kind === 'add' && (
        <SignatoryDialog office={dialog.office} onClose={() => setDialog(null)} onSaved={done} />
      )}
      {dialog?.kind === 'edit' && (
        <SignatoryDialog
          office={dialog.office}
          signatory={dialog.signatory}
          onClose={() => setDialog(null)}
          onSaved={done}
        />
      )}
      {dialog?.kind === 'retire' && (
        <RetireDialog
          office={dialog.office}
          signatory={dialog.signatory}
          onClose={() => setDialog(null)}
          onRetired={done}
        />
      )}
    </div>
  )
}
