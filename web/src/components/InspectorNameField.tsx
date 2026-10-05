import { useEffect, useId, useRef, useState } from 'react'
import { toApiError } from '../lib/api'
import { inspections as inspectionsApi } from '../lib/resources'

/*
 * The inspector's name on a site visit, typed by the office for the record.
 *
 * The client, 5 October 2026, on the For Inspection card that named an account
 * with Assign/Unassign beside it: *"since an inspector can have no account in
 * the system, would it be better if the admin just type the name of the
 * inspector assigned? The officer in charge is still the one to approve or
 * reject the inspection, but he/she must still be able to put the inspector
 * name just for the record. The field must be editable."*
 *
 * So it is a name, not a person picker: shown as text, with a small Edit that
 * opens one input. Enter or Save keeps it, Escape or Cancel puts it back, and
 * a blank save clears it. Never required — the result can be recorded with no
 * name at all.
 *
 * Used on the queue card and on the review page's inspection card, so the two
 * cannot drift. The parent does not reload on save: the name changes nothing
 * else on either screen, so the field keeps the server's answer itself.
 */

/*
 * One request per page, not one per card: a queue page can show a dozen
 * visits, and every field on it offers the same office's names. Dropped after
 * a save so a newly typed name is offered next time.
 */
let namesRequest: Promise<string[]> | null = null

function officeNames(): Promise<string[]> {
  namesRequest ??= inspectionsApi.inspectorNames().catch(() => {
    // An autocomplete that fails is a plain text box, not an error.
    namesRequest = null
    return []
  })
  return namesRequest
}

export function InspectorNameField({
  inspectionId,
  name,
  canEdit,
  label = 'Inspector (for the record)',
  context,
}: {
  inspectionId: number
  name: string | null | undefined
  canEdit: boolean
  /** The visible label. The queue card uses the shorter "Inspector". */
  label?: string
  /** Which visit, for the accessible names: several can share one page. */
  context: string
}) {
  const [saved, setSaved] = useState<string | null>(name ?? null)
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [names, setNames] = useState<string[]>([])
  const inputRef = useRef<HTMLInputElement | null>(null)
  const listId = useId()

  // A reload upstream (a result recorded, the list re-read) brings a new copy.
  useEffect(() => setSaved(name ?? null), [name])

  useEffect(() => {
    if (!editing) return
    inputRef.current?.focus()
    let live = true
    void officeNames().then((list) => live && setNames(list))
    return () => {
      live = false
    }
  }, [editing])

  function open() {
    setDraft(saved ?? '')
    setError(null)
    setEditing(true)
  }

  async function save() {
    if (busy) return
    const next = draft.trim() || null
    if (next === saved) {
      setEditing(false)
      return
    }
    if (next && next.length > 120) {
      setError('Keep it under 120 characters.')
      return
    }
    setBusy(true)
    setError(null)
    try {
      const visit = await inspectionsApi.nameInspector(inspectionId, next)
      setSaved(visit.inspector_name ?? null)
      namesRequest = null
      setEditing(false)
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  if (editing) {
    return (
      <div className="flex flex-col gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <input
            ref={inputRef}
            type="text"
            value={draft}
            maxLength={120}
            list={listId}
            placeholder="Full name"
            aria-label={`${label}, ${context}`}
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault()
                void save()
              } else if (e.key === 'Escape') {
                e.preventDefault()
                setEditing(false)
              }
            }}
            className="w-48 rounded-lg border border-input-border bg-input px-2.5 py-1 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
          />
          <datalist id={listId}>
            {names.map((n) => (
              <option key={n} value={n} />
            ))}
          </datalist>
          <button
            type="button"
            onClick={() => void save()}
            aria-disabled={busy || undefined}
            aria-label={`Save the inspector’s name, ${context}`}
            className="rounded-full bg-royal px-3 py-1 text-xs font-semibold text-white hover:bg-royal-hover aria-disabled:opacity-60"
          >
            {busy ? 'Saving…' : 'Save'}
          </button>
          <button
            type="button"
            onClick={() => setEditing(false)}
            aria-label={`Cancel editing the inspector’s name, ${context}`}
            className="text-xs font-semibold text-ink-muted underline underline-offset-2"
          >
            Cancel
          </button>
        </div>
        {error && (
          <p role="alert" className="text-xs font-medium text-s-red">
            {error}
          </p>
        )}
      </div>
    )
  }

  return (
    <p className="text-sm text-ink-secondary">
      <span className="text-ink-muted">{label}: </span>
      {saved ? (
        <span className="font-semibold text-ink">{saved}</span>
      ) : (
        <span className="text-ink-muted">Not recorded</span>
      )}
      {canEdit && (
        <button
          type="button"
          onClick={open}
          aria-label={`${saved ? 'Edit' : 'Add'} the inspector’s name, ${context}`}
          className="ml-2 text-xs font-semibold text-royal underline underline-offset-2 hover:no-underline"
        >
          {saved ? 'Edit' : 'Add'}
        </button>
      )}
    </p>
  )
}
