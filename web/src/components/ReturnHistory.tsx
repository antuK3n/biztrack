import { useId, useState } from 'react'
import { formatDate } from '../lib/format'
import {
  inScope,
  returnItemLabel,
  roundsForField,
  useReturnHistory,
  type ReturnChange,
  type ReturnRound,
} from '../lib/returnHistory'
import { StatusChip } from './ui/Proto'

/**
 * ── Every return, kept after it is complied ───────────────────────────────
 *
 * Client, 19 October 2026: *"When a returned application was complied, the
 * remark should not be removed."* The live `remarks` on a filing are the
 * CURRENT round and are cleared when the owner answers; this reads
 * `GET /applications/{id}/returns` (App\Support\ReturnHistory), which keeps
 * every round — what was asked, by which office, and what the owner changed.
 *
 * "History on both sides": the same component on the owner's Track page and
 * on the officer's review screen. The server scopes it (an office reads only
 * its own sheet's returns) and withholds officer names from the owner, so the
 * `audience` prop only chooses wording.
 *
 * Self-contained: give it an application id and it fetches; or load once
 * with `useReturnHistory` and hand the rounds to this and to
 * `ReturnedFieldNote` beside each field. The hook, the types and the label
 * lookup are in lib/returnHistory.ts, so this file exports components only.
 */

function statusText(round: ReturnRound, audience: 'owner' | 'staff'): { tone: 'tint-orange' | 'tint-green' | 'tint-gray'; text: string } {
  if (round.status === 'open') {
    return { tone: 'tint-orange', text: audience === 'owner' ? 'Waiting for you' : 'Waiting for the owner' }
  }
  if (round.status === 'complied') {
    return { tone: 'tint-green', text: `Complied ${formatDate(round.complied_at)}` }
  }

  return { tone: 'tint-gray', text: 'No longer needed' }
}

/** One line under an item: what the owner's answer changed. */
function ChangeText({ change }: { change: ReturnChange | undefined }) {
  if (!change || !change.comparable) return null
  const files = change.replaced.map((d) => d.filename).filter(Boolean)
  if (files.length > 0) {
    return (
      <p className="mt-0.5 text-xs text-ink-secondary">
        Replaced document: <span className="font-semibold text-ink">{files.join(', ')}</span>
      </p>
    )
  }
  if (!change.changed) {
    return <p className="mt-0.5 text-xs text-ink-muted">Left as it was</p>
  }

  return (
    <p className="mt-0.5 break-words text-xs text-ink-secondary">
      <span>{change.old ?? '—'}</span>
      <span aria-hidden="true"> → </span>
      <span className="sr-only"> changed to </span>
      <span className="font-semibold text-ink">{change.new ?? '—'}</span>
    </p>
  )
}

/**
 * A per-field return's `remarks` is only the items' notes joined
 * ("Label: note; Label: note" — composed by the officer's Return dialog), so
 * printing it above the items says everything twice. Matched on the notes,
 * not the labels: the dialog's labels and `returnItemLabel` can differ.
 */
function remarksRepeatItems(round: ReturnRound): boolean {
  if (!round.remarks || round.items.length === 0) return false
  const notes = round.items.map((i) => (i.note ?? '').trim())
  if (notes.some((n) => n === '')) return false

  return round.remarks
    .split('; ')
    .every((part) => notes.some((note) => part.trim().endsWith(`: ${note}`)))
}

function Round({ round, audience }: { round: ReturnRound; audience: 'owner' | 'staff' }) {
  const status = statusText(round, audience)
  const permitCode = round.permit_type?.code ?? null
  const where = round.permit_type ? round.permit_type.name : 'Business permit application'
  const office = round.returned_by_office?.name ?? round.office?.name ?? 'The office'
  const verb = round.kind === 'refusal' ? 'Refused' : 'Returned'
  const changes = new Map((round.changes ?? []).map((c) => [c.code, c]))

  return (
    <li className="px-4 py-3 sm:px-5">
      <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
        <div className="min-w-0">
          <p className="text-sm font-semibold text-ink">{where}</p>
          <p className="text-xs text-ink-muted">
            {verb} by {office} · {formatDate(round.returned_at)}
            {audience === 'staff' && round.returned_by ? ` · ${round.returned_by.name}` : ''}
            {round.amended_at ? ` · changed ${formatDate(round.amended_at)}` : ''}
          </p>
        </div>
        <StatusChip tone={status.tone} className="shrink-0">
          {status.text}
        </StatusChip>
      </div>

      {round.remarks && !remarksRepeatItems(round) && (
        <p className="mt-1.5 break-words text-sm text-ink">“{round.remarks}”</p>
      )}

      {round.items.length > 0 && (
        <ul className="mt-2 space-y-1.5">
          {round.items.map((item) => (
            <li
              key={item.code}
              className={`rounded-md border-l-4 px-3 py-1.5 ${
                round.status === 'open' ? 'border-s-rose bg-s-rose-tint/40' : 'border-line bg-canvas'
              }`}
            >
              <p className="break-words text-sm">
                <span className="font-semibold text-ink">{returnItemLabel(item, permitCode)}</span>
                {item.note && <span className="italic text-ink-secondary"> — {item.note}</span>}
              </p>
              {round.status === 'complied' && <ChangeText change={changes.get(item.code)} />}
            </li>
          ))}
        </ul>
      )}

      {audience === 'staff' && round.status === 'complied' && round.complied_by && (
        <p className="mt-1.5 text-xs text-ink-muted">Complied by {round.complied_by.name}</p>
      )}
    </li>
  )
}

/** How many rounds show before "Show earlier". The newest is what anyone opens this for. */
const SHOWN = 3

export function ReturnHistory({
  applicationId,
  rounds: given,
  audience,
  permitTypeCode,
  refreshKey,
  defaultOpen = true,
  className = '',
}: {
  /** Fetched here when `rounds` is not given. */
  applicationId?: number
  /** Already loaded with `useReturnHistory`; skips the fetch. */
  rounds?: ReturnRound[]
  audience: 'owner' | 'staff'
  /** `null` = the main form only; a code = that permit only; omit for every round. */
  permitTypeCode?: string | null
  refreshKey?: unknown
  defaultOpen?: boolean
  className?: string
}) {
  const own = useReturnHistory(given === undefined ? applicationId : null, refreshKey)
  const [all, setAll] = useState(false)
  const [open, setOpen] = useState(defaultOpen)
  const listId = useId()
  const rounds = (given ?? own.rounds).filter((r) => inScope(r, permitTypeCode))
  if (rounds.length === 0) return null

  const waiting = rounds.filter((r) => r.status === 'open').length
  const shown = all ? rounds : rounds.slice(0, SHOWN)

  /*
   * A <button aria-expanded>, not <details>. inspection-review.spec forbids
   * any <details> on an office's finished-review screen — a <details> was the
   * shape of the folded application form the client rejected — and this card
   * renders there too. The button is the same disclosure to a screen reader.
   */
  return (
    <section aria-label="Return history" className={`rounded-xl bg-white shadow-card ${className}`}>
      <button
        type="button"
        aria-expanded={open}
        aria-controls={listId}
        onClick={() => setOpen((v) => !v)}
        className="flex w-full cursor-pointer items-center justify-between gap-3 px-4 py-3 text-left sm:px-5"
      >
        <span className="text-base font-bold text-ink">Return history</span>
        <span className="flex items-center gap-2 text-xs text-ink-muted">
          {rounds.length === 1 ? '1 return' : `${rounds.length} returns`}
          {waiting > 0 ? ` · ${waiting} waiting` : ''}
          <span aria-hidden="true" className={`transition-transform ${open ? 'rotate-180' : ''}`}>
            ▾
          </span>
        </span>
      </button>
      <div id={listId} hidden={!open}>
        <ol className="divide-y divide-line border-t border-line">
          {shown.map((round) => (
            <Round key={round.id} round={round} audience={audience} />
          ))}
        </ol>
        {rounds.length > SHOWN && (
          <div className="border-t border-line px-4 py-2 sm:px-5">
            <button
              type="button"
              onClick={() => setAll((v) => !v)}
              className="text-sm font-semibold text-royal hover:underline"
            >
              {all ? 'Show fewer' : `Show ${rounds.length - SHOWN} earlier`}
            </button>
          </div>
        )}
      </div>
    </section>
  )
}

/**
 * One line beside a field: "Returned October 18, 2026: wrong spelling ·
 * Complied October 19, 2026". The latest round that named it; a count when
 * there were more. Renders nothing for a field never returned.
 */
export function ReturnedFieldNote({
  rounds,
  code,
  permitTypeCode = null,
  className = '',
}: {
  rounds: ReturnRound[]
  code: string
  /** `null` (default) = the main form; a code = that permit's sheet. */
  permitTypeCode?: string | null
  className?: string
}) {
  const hits = roundsForField(rounds, code, permitTypeCode)
  const latest = hits[0]
  if (!latest) return null

  const item = latest.items.find((it) => it.code === code)
  const verb = latest.kind === 'refusal' ? 'Refused' : 'Returned'
  const outcome =
    latest.status === 'open'
      ? 'Waiting'
      : latest.status === 'complied'
        ? `Complied ${formatDate(latest.complied_at)}`
        : 'No longer needed'

  return (
    <p className={`text-xs text-s-orange-ink ${className}`}>
      <span className="font-semibold">
        {verb} {formatDate(latest.returned_at)}
        {hits.length > 1 ? ` (${hits.length} times)` : ''}
      </span>
      {item?.note ? `: ${item.note}` : ''}
      <span className="text-ink-muted"> · {outcome}</span>
    </p>
  )
}
