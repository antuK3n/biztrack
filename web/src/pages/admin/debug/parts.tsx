import { useId } from 'react'
import type { ReactNode } from 'react'
import { ProtoCard } from '../../../components/ui/Proto'
import { CheckCircleFilledIcon } from '../../../components/icons'

/*
 * The pieces the Debug page's sections are built from, so every section reads
 * as one page: a white sub-card with its own heading, and a pair (or trio) of
 * pressable cards for a setting with a few values.
 */

export function SubCard({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
  const headingId = useId()
  return (
    <ProtoCard className="rounded-xl p-5">
      <section aria-labelledby={headingId}>
        <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
          <h3 id={headingId} className="text-base font-semibold text-ink">
            {title}
          </h3>
          {action}
        </div>
        {children}
      </section>
    </ProtoCard>
  )
}

/*
 * One of two settings, as a pressable card. The name is the setting alone
 * ("The full bill") and the sentence under it is its description, so a screen
 * reader hears "The full bill, toggle button, pressed" rather than the whole
 * card read out as a name. "On" is written as well as drawn (Never Colour
 * Alone).
 *
 * `blockedBy` is the id of the sentence saying why it cannot be chosen. The
 * card stays focusable and says so, rather than going `disabled` and
 * disappearing from a screen reader along with its reason (AGENTS.md §6.2).
 */
export function Choice({
  label,
  name,
  description,
  on,
  busy,
  blockedBy,
  onChoose,
}: {
  label: string
  /**
   * The accessible name, when the visible label repeats elsewhere on the page.
   * Two cards both called "On" are two identical stops to a screen reader
   * (AGENTS.md §6.2), so they are named "E-mail sign-in codes: On" and so on.
   */
  name?: string
  description: string
  on: boolean
  busy: boolean
  blockedBy?: string
  onChoose: () => void
}) {
  const descriptionId = useId()
  const unavailable = !!blockedBy && !on

  return (
    <button
      type="button"
      aria-label={name ?? label}
      aria-pressed={on}
      aria-describedby={[descriptionId, unavailable ? blockedBy : null].filter(Boolean).join(' ')}
      aria-disabled={busy || unavailable || undefined}
      onClick={() => {
        if (!on && !busy && !unavailable) onChoose()
      }}
      className={`flex min-h-[5.5rem] flex-col items-start rounded-xl border-2 px-4 py-3 text-left transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-royal focus-visible:ring-offset-2 aria-disabled:cursor-not-allowed ${
        on ? 'border-royal bg-royal-tint' : 'border-line bg-white hover:border-input-border'
      } ${unavailable ? 'opacity-60' : ''}`}
    >
      <span className="flex w-full items-center justify-between gap-3">
        <span className="font-semibold text-ink">{label}</span>
        {on && (
          <span className="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-royal">
            <CheckCircleFilledIcon size={16} /> On
          </span>
        )}
      </span>
      <span id={descriptionId} className="mt-1 text-sm text-ink-secondary">
        {description}
      </span>
    </button>
  )
}

export function Detail({ term, children }: { term: string; children: ReactNode }) {
  return (
    <>
      <dt className="text-ink-muted">{term}</dt>
      <dd className="min-w-0 break-all text-ink">{children}</dd>
    </>
  )
}
