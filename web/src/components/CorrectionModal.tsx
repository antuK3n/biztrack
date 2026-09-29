import { useEffect, useRef, type ReactNode } from 'react'

/**
 * "What you need to correct", as a dialog, for every office including BPLO.
 *
 * ── Why this is a modal and not two matching panels ─────────────────────────
 *
 * It was two panels. The client photographed them side by side five times
 * between 30 September 2026 05:00 and 05:25 and they were never quite the
 * same: the clearance copy sat inside the office sheet's white card, then
 * took `multiple` where BPLO's took one file, then listed the slot's contents
 * where BPLO's said "Uploaded x.", and after all three were closed the cards
 * still rendered at different widths because `/applications/:id` is
 * `max-w-4xl` and the clearance stage is `max-w-5xl`.
 *
 * Every one of those was a real difference and every one came from the same
 * cause: two surfaces rendering one idea inside different page contexts.
 * Matching them again would have fixed the width and left the next divergence
 * to be found by the client rather than by us.
 *
 * A dialog has no page context. One component, one width, one set of
 * paddings, opened from wherever the return is — so BPLO's return and CPDD's
 * return are the same screen by construction, which is what was asked for
 * and what the panels kept failing to be.
 *
 * ── What it does NOT own ────────────────────────────────────────────────────
 *
 * The controls. Each caller passes its own items as `children`, because a
 * returned document on the main form uploads through `documents.upload` and
 * one on an office sheet goes through `officeForms.uploadRequirement` — two
 * endpoints, the same shape on screen. Folding those into this component
 * would mean a prop naming which endpoint to use, which is the page context
 * coming back in through the door it was shown out of.
 */
export function CorrectionModal({
  office,
  count,
  children,
  onSubmit,
  onClose,
  submitting = false,
  submitLabel = 'Submit corrections',
  blocked = null,
  error = null,
}: {
  /** Who sent it back, in their own short name: BPLO, CPDD, BFP. */
  office: string
  /** How many items were named, for the opening sentence. */
  count: number
  /** The items themselves — inputs, uploads — supplied by the caller. */
  children: ReactNode
  onSubmit: () => void
  onClose: () => void
  submitting?: boolean
  submitLabel?: string
  /** Why Submit will not go through yet, or null when it will. */
  blocked?: string | null
  /** A failure from the last attempt, or null. */
  error?: string | null
}) {
  const panel = useRef<HTMLDivElement | null>(null)

  /*
   * Escape closes it, and the panel takes focus when it opens.
   *
   * Nothing here is destructive — closing loses no typing, since an upload has
   * already happened by the time it appears and a field is autosaved — so the
   * dialog is dismissible in the ordinary way rather than trapping somebody
   * who opened it to read what was wrong.
   */
  useEffect(() => {
    panel.current?.focus()
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="correction-modal-title"
    >
      <div
        ref={panel}
        tabIndex={-1}
        /*
          Scrollable, and capped at the viewport. A return naming six things
          on a small screen would otherwise push Submit off the bottom with
          no way to reach it — the one control the dialog exists for.
        */
        className="max-h-[85vh] w-full max-w-xl overflow-y-auto rounded-md bg-white shadow-overlay focus:outline-none"
      >
        <div className="border-b border-line px-7 py-5">
          <h2 id="correction-modal-title" className="text-xl font-bold text-ink">
            What you need to correct
          </h2>
          <p className="mt-1 text-sm text-ink-secondary">
            {office} returned this about {count === 1 ? 'one item' : `${count} items`}.
            Everything else you filed stays as it is.
          </p>
        </div>

        <div className="px-7 py-5">{children}</div>

        {error !== null && (
          <p role="alert" className="px-7 pb-2 text-sm font-medium text-s-red">
            {error}
          </p>
        )}

        <div className="flex items-center gap-3 border-t border-line px-7 py-4">
          <button
            type="button"
            onClick={() => {
              if (submitting || blocked !== null) return
              onSubmit()
            }}
            /*
              `aria-disabled`, not `disabled`: a control taken out of the tab
              order takes the sentence explaining itself with it, which is the
              pattern the officer's Approve and the office sheet's own Submit
              both already follow. The press is guarded instead.
            */
            aria-disabled={submitting || blocked !== null}
            aria-describedby={blocked !== null ? 'correction-modal-blocked' : undefined}
            /*
              Blocked is a flat grey, not the royal fill at 60%. Reduced
              opacity reads as a rendering fault — a washed-out enabled
              button — where a different colour reads as a state, and the
              text stays at full contrast either way so the label can be
              read while it waits.
            */
            className={`rounded-md px-6 py-2.5 text-sm font-semibold shadow-card ${
              submitting || blocked !== null
                ? 'cursor-not-allowed bg-line text-ink-secondary'
                : 'bg-royal text-white hover:bg-royal-hover'
            }`}
          >
            {submitting ? 'Sending…' : submitLabel}
          </button>
          {/*
            A button, not underlined text. Beside a filled control, grey
            underlined prose does not read as something you can press —
            and Close is the only way out for somebody who opened this to
            read what was wrong.
          */}
          <button
            type="button"
            onClick={onClose}
            className="rounded-md border border-input-border bg-white px-5 py-2.5 text-sm font-semibold text-ink transition-colors hover:border-ink-secondary hover:bg-input"
          >
            Close
          </button>
        </div>

        {blocked !== null && (
          <p id="correction-modal-blocked" className="px-7 pb-5 text-xs text-ink-muted">
            {blocked}
          </p>
        )}
      </div>
    </div>
  )
}
