import { useEffect, useRef } from 'react'
import type { ReactNode, RefObject } from 'react'
import { XIcon } from '../icons'

interface ModalProps {
  open: boolean
  onClose: () => void
  title: string
  description?: ReactNode
  children: ReactNode
  /** Footer buttons, right-aligned. */
  footer?: ReactNode
  /**
   * How wide the panel opens. `md` (the default) is every dialog's width so
   * far; `lg` and `xl` are for a modal that holds a table or a long list —
   * the owner profile's "Show all" views — where 32rem would wrap every row.
   */
  size?: 'md' | 'lg' | 'xl'
  /** Drop the body's padding, for a table that runs edge to edge. */
  flush?: boolean
  /**
   * Hold the body at a fixed height, for a list the reader searches and
   * filters: without it the panel shrinks and grows with every keystroke and
   * the search box moves under the reader's hand.
   */
  fixedHeight?: boolean
  /**
   * What takes the focus when the modal opens; the panel itself if unset. A
   * confirmation of something that cannot be undone hands it the Cancel
   * button, so an Enter pressed out of habit backs away instead of deleting.
   */
  initialFocus?: RefObject<HTMLElement | null>
}

const SIZES = { md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' } as const

export function Modal({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = 'md',
  flush = false,
  fixedHeight = false,
  initialFocus,
}: ModalProps) {
  const panelRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    ;(initialFocus?.current ?? panelRef.current)?.focus()
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = prev
    }
  }, [open, onClose, initialFocus])

  if (!open) return null

  return (
    <div
      className="fixed inset-0 z-50 flex items-end justify-center bg-ink/40 p-0 sm:items-center sm:p-4"
      onClick={onClose}
      role="presentation"
    >
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        tabIndex={-1}
        onClick={(e) => e.stopPropagation()}
        className={`w-full ${SIZES[size]} rounded-t-2xl border border-line bg-surface shadow-overlay outline-none sm:rounded-2xl`}
      >
        <header className="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
          <div className="min-w-0">
            <h2 className="text-lg font-semibold text-ink">{title}</h2>
            {description && <p className="mt-0.5 text-sm text-ink-secondary">{description}</p>}
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            className="-mr-1.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-ink-secondary transition-colors duration-150 hover:bg-shell hover:text-ink"
          >
            <XIcon size={20} />
          </button>
        </header>
        <div className={`${fixedHeight ? 'h-[70vh]' : 'max-h-[70vh]'} overflow-y-auto ${flush ? '' : 'px-5 py-4'}`}>
          {children}
        </div>
        {footer && (
          <footer className="flex flex-col-reverse gap-2 border-t border-line px-5 py-4 sm:flex-row sm:justify-end">
            {footer}
          </footer>
        )}
      </div>
    </div>
  )
}
