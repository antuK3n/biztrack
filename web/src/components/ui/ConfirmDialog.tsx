import { useCallback, useEffect, useRef } from 'react'
import type { ReactNode } from 'react'
import { AlertTriangleIcon, Spinner } from '../icons'
import { Modal } from './Modal'

/*
 * ── ConfirmDialog: "are you sure?" said properly ────────────────────────────
 *
 * In place of window.confirm, which cannot say what a decision costs, looks
 * nothing like the system, and on some browsers reads as the site's own
 * warning. The dialog names the decision in its title, lists what follows
 * from it, and puts the action on a button that says what it does — never a
 * bare "OK".
 *
 * A `danger` decision (one that cannot be undone) opens with Cancel focused
 * and the action in red, so an Enter pressed out of habit backs away. While
 * the work runs the action shows a spinner and refuses a second press
 * (aria-disabled, never `disabled`); a failure stays in the dialog, beside
 * the decision it belongs to, so the reader can try again or back away.
 */

interface ConfirmDialogProps {
  open: boolean
  title: string
  /** One sentence on what is about to happen. */
  description?: ReactNode
  /** What follows from it — a short list reads best. */
  children?: ReactNode
  /** The action, said as a verb: "Remove inspector", not "OK". */
  confirmLabel: string
  cancelLabel?: string
  tone?: 'danger' | 'default'
  busy?: boolean
  error?: string | null
  onConfirm: () => void
  onCancel: () => void
}

export function ConfirmDialog({
  open,
  title,
  description,
  children,
  confirmLabel,
  cancelLabel = 'Cancel',
  tone = 'default',
  busy = false,
  error,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  const cancelRef = useRef<HTMLButtonElement>(null)
  const confirmRef = useRef<HTMLButtonElement>(null)
  const danger = tone === 'danger'

  // A stable close, so the Modal does not refocus on every parent render;
  // and no closing while the work is still out.
  const latest = useRef({ onCancel, busy })
  useEffect(() => {
    latest.current = { onCancel, busy }
  })
  const close = useCallback(() => {
    if (!latest.current.busy) latest.current.onCancel()
  }, [])

  return (
    <Modal
      open={open}
      onClose={close}
      title={title}
      description={description}
      initialFocus={danger ? cancelRef : confirmRef}
      footer={
        <>
          <button
            ref={cancelRef}
            type="button"
            onClick={close}
            aria-disabled={busy}
            className="rounded-full border border-line bg-white px-4 py-2 text-sm font-semibold text-ink-secondary hover:bg-canvas aria-disabled:opacity-60"
          >
            {cancelLabel}
          </button>
          <button
            ref={confirmRef}
            type="button"
            onClick={() => {
              if (!busy) onConfirm()
            }}
            aria-disabled={busy}
            aria-busy={busy || undefined}
            className={`inline-flex items-center justify-center gap-2 rounded-full px-4 py-2 text-sm font-semibold text-white aria-disabled:opacity-70 ${
              danger ? 'bg-s-red hover:bg-s-red-deep' : 'bg-royal hover:bg-royal-hover'
            }`}
          >
            {busy && <Spinner size={16} />}
            {confirmLabel}
          </button>
        </>
      }
    >
      <div className="flex gap-3">
        <span
          className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${
            danger ? 'bg-s-red-tint text-s-red' : 'bg-s-yellow-tint text-amber-800'
          }`}
          aria-hidden="true"
        >
          <AlertTriangleIcon size={18} />
        </span>
        <div className="min-w-0 flex-1 text-sm text-ink-secondary">{children}</div>
      </div>
      {error && (
        <p role="alert" className="mt-3 rounded-lg bg-s-red-tint px-3 py-2 text-sm text-s-red">
          {error}
        </p>
      )}
    </Modal>
  )
}
