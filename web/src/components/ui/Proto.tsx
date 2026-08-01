import { useEffect, useId, useRef, useState } from 'react'
import type { ReactNode, RefObject } from 'react'
import { ChevronDownIcon } from '../icons'

/*
 * Shared prototype-fidelity primitives (docs/rehaul-spec.md).
 * Every rebuilt screen composes these so the app reads as ONE product.
 */

/**
 * Make a dialog usable from the keyboard (WCAG 2.1 AA — 2.1.2 No Keyboard Trap,
 * 2.4.3 Focus Order). Point `panelRef` at the dialog panel and pass whatever
 * closes it. Three things it does:
 *
 *  - moves focus into the dialog on open, and back to whatever opened it on
 *    close, so the reading position is never lost;
 *  - closes on Escape, matching every other dialog the user has ever met;
 *  - cycles Tab inside the dialog instead of walking out into the dead page.
 *
 * Extracted from ProtoModal because two hand-rolled overlays — the officer
 * Details sheet and the Owner Status history — reimplemented the markup without
 * any of this. Both opened with focus still on the button behind the overlay,
 * ignored Escape, and let Tab walk out into a page the user could no longer see.
 * `initialFocusRef` picks the control to land on; the panel itself is the
 * fallback for a dialog whose only control is its Close button.
 */
export function useDialogKeyboard(
  panelRef: RefObject<HTMLElement | null>,
  onClose: () => void,
  initialFocusRef?: RefObject<HTMLElement | null>,
) {
  // Read through a ref so a handler recreated on every render does not tear the
  // listener down and re-run the focus move under it.
  const closeRef = useRef(onClose)
  closeRef.current = onClose

  useEffect(() => {
    const opener = document.activeElement as HTMLElement | null
    // Captured now, not read in the cleanup: by teardown the ref has already
    // been nulled, so focus would never come back.
    const panel = panelRef.current
    const target = initialFocusRef?.current ?? panel
    if (target) {
      // A panel is not focusable by default; make it so for this one move.
      if (target === panel && !panel.hasAttribute('tabindex')) panel.setAttribute('tabindex', '-1')
      target.focus()
    }

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.stopPropagation()
        closeRef.current()
        return
      }
      if (event.key !== 'Tab') return

      const focusable = panel?.querySelectorAll<HTMLElement>(
        'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
      )
      if (!focusable || focusable.length === 0) return

      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      const active = document.activeElement

      if (event.shiftKey && (active === first || !panel?.contains(active))) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && (active === last || !panel?.contains(active))) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      /*
       * Put the reader back where they were.
       *
       * The test used to be `panel.contains(document.activeElement)`, which never
       * once passed. This is a passive effect, so its cleanup runs *after* React
       * has detached the panel — the focused element is gone by then and the
       * browser has already reset focus to <body>. So the condition read false
       * every time and focus was silently dropped to the top of the document on
       * every dialog close, which is the same lost reading position the focus
       * trap exists to prevent.
       *
       * `body` (or nothing) is precisely the signature of focus falling off a
       * removed node. Anything else means the close did something deliberate with
       * focus — a confirm that navigates — and is left alone.
       */
      const active = document.activeElement
      const focusWasLost = !active || active === document.body
      if (focusWasLost || panel?.contains(active)) opener?.focus()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
}

/** Blue/red-header modal with the split Cancel/Confirm footer (PDF p18/p35/p47/p82). */
export function ProtoModal({
  title,
  tone = 'blue',
  children,
  cancelLabel = 'Cancel',
  confirmLabel = 'Proceed',
  onCancel,
  onConfirm,
  confirmDisabled,
  wide,
}: {
  title: string
  tone?: 'blue' | 'red' | 'green'
  children: ReactNode
  cancelLabel?: string
  confirmLabel?: string
  onCancel: () => void
  onConfirm?: () => void
  confirmDisabled?: boolean
  wide?: boolean
}) {
  const headerBg = tone === 'red' ? 'bg-s-red' : tone === 'green' ? 'bg-s-green' : 'bg-royal'
  const cancelBg = tone === 'red' ? 'bg-modal-cancel-red' : 'bg-modal-cancel'
  const confirmBg = tone === 'red' ? 'bg-modal-confirm-red' : 'bg-modal-confirm'
  const titleId = useId()
  const panelRef = useRef<HTMLDivElement | null>(null)
  const cancelRef = useRef<HTMLButtonElement | null>(null)

  // Focus in on open, Escape to dismiss, Tab kept inside. See useDialogKeyboard.
  useDialogKeyboard(panelRef, onCancel, cancelRef)

  /*
   * `z-[2000] transform-gpu` rather than `z-50`, for two separate reasons that
   * both showed up as the Leaflet map on the zoning step painting straight
   * through this dialog:
   *
   *  - Leaflet's own stylesheet puts its panes and controls at z-index 200–1000,
   *    and `.leaflet-container` sets no z-index of its own, so those panes
   *    compete in the root stacking context and beat a z-50 overlay.
   *  - Its tile pane is 3D-transformed, which promotes it to a compositor layer.
   *    A layer is ordered by the compositor, not by paint order, so it drew over
   *    an overlay that had no layer of its own — hit-testing said the dialog was
   *    on top while the pixels said otherwise. `transform-gpu` gives the overlay
   *    its own layer so both agree.
   */
  return (
    <div className="fixed inset-0 z-[2000] flex transform-gpu items-center justify-center bg-black/40 p-4">
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className={`w-full ${wide ? 'max-w-2xl' : 'max-w-lg'} overflow-hidden rounded-md bg-white shadow-overlay`}
      >
        <h2 id={titleId} className={`${headerBg} px-5 py-3 text-base font-bold tracking-wide text-white`}>
          {title}
        </h2>
        <div className="max-h-[70vh] overflow-y-auto px-7 py-7 text-ink">{children}</div>
        <div className="grid grid-cols-2">
          <button
            ref={cancelRef}
            type="button"
            onClick={onCancel}
            className={`${cancelBg} py-3.5 text-sm font-semibold text-ink underline underline-offset-2 hover:brightness-95`}
          >
            {cancelLabel}
          </button>
          {onConfirm ? (
            <button
              type="button"
              onClick={onConfirm}
              disabled={confirmDisabled}
              className={`${confirmBg} py-3.5 text-sm font-semibold text-ink underline underline-offset-2 hover:brightness-95 disabled:opacity-60`}
            >
              {confirmLabel}
            </button>
          ) : (
            <span className={`${confirmBg} opacity-40`} aria-hidden="true" />
          )}
        </div>
      </div>
    </div>
  )
}

/**
 * Account restricted modal (p006) — red header, informational body, bold
 * Reference ID, single dismiss block over a red-tint footer. Purely
 * informational: 422s already block filing. Variant flips the copy for
 * suspended vs. blacklisted accounts.
 */
export function AccountRestrictedModal({
  variant,
  referenceId,
  onClose,
}: {
  variant: 'blacklisted' | 'suspended'
  referenceId?: string | null
  onClose: () => void
}) {
  const title = variant === 'suspended' ? 'Account Suspended' : 'Account Blacklisted'
  const word = variant === 'suspended' ? 'suspended' : 'blacklisted'
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="alertdialog"
      aria-modal="true"
      aria-label={title}
    >
      <div className="w-full max-w-lg overflow-hidden rounded-md bg-white shadow-overlay">
        <div className="bg-s-red px-6 py-3.5 text-lg font-bold tracking-wide text-white">{title}</div>
        <div className="space-y-4 px-7 py-7 text-ink">
          <p className="text-base leading-relaxed">
            This account has been {word} and access to the platform has been restricted.
          </p>
          <p className="text-base leading-relaxed">
            If you believe this action was taken in error, please contact support for further review.
          </p>
          {referenceId && (
            <p className="text-base">
              <span className="font-bold">Reference ID:</span> {referenceId}
            </p>
          )}
        </div>
        <button
          type="button"
          onClick={onClose}
          className="w-full bg-modal-cancel-red py-3.5 text-sm font-semibold text-ink underline underline-offset-2 hover:brightness-95"
        >
          Understood
        </button>
      </div>
    </div>
  )
}

/** Round filter pills row (p20/p48/p61): active = white w/ royal outline text, inactive = royal. */
export function FilterPills<T extends string>({
  options,
  value,
  onChange,
}: {
  options: { value: T; label: string }[]
  value: T
  onChange: (v: T) => void
}) {
  return (
    <div className="flex flex-wrap gap-2.5">
      {options.map((o) => {
        const active = o.value === value
        return (
          <button
            key={o.value}
            type="button"
            aria-pressed={active}
            onClick={() => onChange(o.value)}
            className={`rounded-full px-5 py-1.5 text-sm font-semibold transition-colors ${
              active
                ? 'border-2 border-royal bg-white text-royal'
                : 'border-2 border-royal bg-royal text-white hover:bg-royal-hover'
            }`}
          >
            {o.label}
          </button>
        )
      })}
    </div>
  )
}

const CHIP_TONES = {
  orange: 'bg-s-orange text-white',
  green: 'bg-s-green text-white',
  yellow: 'bg-s-yellow text-ink',
  red: 'bg-s-red text-white',
  gray: 'bg-line text-ink-secondary',
  // soft tints for table chips (super-admin p93/p99)
  'tint-green': 'bg-s-green-tint text-s-green',
  'tint-yellow': 'bg-s-yellow-tint text-amber-800',
  'tint-red': 'bg-s-red-tint text-s-red',
  'tint-purple': 'bg-s-purple-tint text-s-purple',
  'tint-gray': 'bg-canvas text-ink-muted',
} as const
export type ChipTone = keyof typeof CHIP_TONES

/** Solid status block/chip (Pay Online, Paid, For Approval, Rejected…). */
export function StatusChip({
  tone,
  children,
  className = '',
}: {
  tone: ChipTone
  children: ReactNode
  className?: string
}) {
  return (
    <span
      className={`inline-flex items-center justify-center rounded-md px-3 py-1 text-center text-xs font-semibold leading-tight ${CHIP_TONES[tone]} ${className}`}
    >
      {children}
    </span>
  )
}

/** Page heading with the thin underline rule (p11/p19/p21). */
export function PageTitle({ children, right }: { children: ReactNode; right?: ReactNode }) {
  return (
    <div className="mb-6 border-b-2 border-ink/50 pb-2">
      <div className="flex items-end justify-between gap-4">
        <h1 className="text-2xl font-bold text-ink">{children}</h1>
        {right}
      </div>
    </div>
  )
}

export interface SortFilterOption {
  value: string
  label: string
}

export interface SortFilterMenu {
  value: string
  options: SortFilterOption[]
  onChange: (value: string) => void
}

/** Optional date-range inputs shown inside the Filter menu. */
export interface SortFilterDateRange {
  from: string
  to: string
  onChange: (from: string, to: string) => void
}

function SortFilterMenuPanel({
  menu,
  dateRange,
  onClose,
}: {
  menu: SortFilterMenu
  dateRange?: SortFilterDateRange
  onClose: () => void
}) {
  return (
    <>
      <button
        type="button"
        aria-label="Close menu"
        onClick={onClose}
        className="fixed inset-0 z-30 cursor-default"
        tabIndex={-1}
      />
      <div className="absolute right-0 top-full z-40 mt-2 w-56 rounded-lg border border-line bg-white p-1.5 text-left shadow-overlay">
        <ul role="listbox">
          {menu.options.map((option) => {
            const active = option.value === menu.value
            return (
              <li key={option.value}>
                <button
                  type="button"
                  role="option"
                  aria-selected={active}
                  onClick={() => {
                    menu.onChange(option.value)
                    if (!dateRange) onClose()
                  }}
                  className={`w-full rounded-md px-3 py-1.5 text-left text-sm ${
                    active ? 'bg-royal-tint font-semibold text-royal' : 'text-ink hover:bg-canvas'
                  }`}
                >
                  {option.label}
                </button>
              </li>
            )
          })}
        </ul>
        {dateRange && (
          <div className="mt-1.5 space-y-2 border-t border-line px-3 py-2.5">
            <label className="block text-xs font-semibold text-ink-secondary">
              From
              <input
                type="date"
                value={dateRange.from}
                onChange={(e) => dateRange.onChange(e.target.value, dateRange.to)}
                className="mt-1 w-full rounded-md border border-input-border bg-input px-2 py-1 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
              />
            </label>
            <label className="block text-xs font-semibold text-ink-secondary">
              To
              <input
                type="date"
                value={dateRange.to}
                onChange={(e) => dateRange.onChange(dateRange.from, e.target.value)}
                className="mt-1 w-full rounded-md border border-input-border bg-input px-2 py-1 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
              />
            </label>
          </div>
        )}
      </div>
    </>
  )
}

/**
 * `Sort ⇅  Filter ▽` affordance (p14). With no props it stays the prototype's
 * static ornament; pass `sort`/`filter` menus to make either control real.
 */
export function SortFilter({
  sort,
  filter,
  dateRange,
}: {
  sort?: SortFilterMenu
  filter?: SortFilterMenu
  dateRange?: SortFilterDateRange
} = {}) {
  const [openMenu, setOpenMenu] = useState<'sort' | 'filter' | null>(null)
  const filterActive =
    (filter && filter.value !== filter.options[0]?.value) ||
    Boolean(dateRange && (dateRange.from || dateRange.to))

  const sortInner = (
    <>
      Sort <ChevronDownIcon size={14} className="rotate-180" />
      <ChevronDownIcon size={14} className="-ml-2.5" />
    </>
  )
  const filterInner = (
    <>
      Filter
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M4 5h16l-6.5 8v5L10 20v-7L4 5Z" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
      </svg>
    </>
  )

  return (
    <span className="flex items-center gap-4 text-sm text-ink-secondary">
      {sort ? (
        <span className="relative">
          <button
            type="button"
            aria-haspopup="listbox"
            aria-expanded={openMenu === 'sort'}
            onClick={() => setOpenMenu(openMenu === 'sort' ? null : 'sort')}
            className={`inline-flex items-center gap-1 rounded px-1 hover:text-ink ${
              openMenu === 'sort' ? 'text-ink' : ''
            }`}
          >
            {sortInner}
          </button>
          {openMenu === 'sort' && (
            <SortFilterMenuPanel menu={sort} onClose={() => setOpenMenu(null)} />
          )}
        </span>
      ) : (
        <span className="inline-flex items-center gap-1">{sortInner}</span>
      )}
      {filter ? (
        <span className="relative">
          <button
            type="button"
            aria-haspopup="listbox"
            aria-expanded={openMenu === 'filter'}
            onClick={() => setOpenMenu(openMenu === 'filter' ? null : 'filter')}
            className={`inline-flex items-center gap-1 rounded px-1 hover:text-ink ${
              openMenu === 'filter' || filterActive ? 'font-semibold text-royal' : ''
            }`}
          >
            {filterInner}
          </button>
          {openMenu === 'filter' && (
            <SortFilterMenuPanel menu={filter} dateRange={dateRange} onClose={() => setOpenMenu(null)} />
          )}
        </span>
      ) : (
        <span className="inline-flex items-center gap-1">{filterInner}</span>
      )}
    </span>
  )
}

export interface FilterField {
  label: string
  value: string
  options: SortFilterOption[]
  onChange: (value: string) => void
}

/**
 * The slider-glyph filter control the analytics screens carry in their header
 * (p105/p106). The glyph is the prototype's affordance; the panel behind it
 * holds real labelled selects, because a control you cannot name is a control
 * you cannot use with a screen reader.
 */
export function FilterMenu({ fields, label = 'Filter' }: { fields: FilterField[]; label?: string }) {
  const [open, setOpen] = useState(false)

  return (
    <span className="relative">
      <button
        type="button"
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={label}
        onClick={() => setOpen(!open)}
        className={`flex h-10 w-10 items-center justify-center rounded-lg border transition-colors ${
          open ? 'border-royal bg-royal-tint text-royal' : 'border-transparent text-royal hover:bg-royal-tint'
        }`}
      >
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <circle cx="15" cy="7" r="2.2" fill="#d1dbeb" stroke="currentColor" strokeWidth="2" />
          <circle cx="9" cy="12" r="2.2" fill="#d1dbeb" stroke="currentColor" strokeWidth="2" />
          <circle cx="13" cy="17" r="2.2" fill="#d1dbeb" stroke="currentColor" strokeWidth="2" />
        </svg>
      </button>
      {open && (
        <>
          <button
            type="button"
            aria-label="Close filter"
            onClick={() => setOpen(false)}
            className="fixed inset-0 z-30 cursor-default"
            tabIndex={-1}
          />
          <div
            role="dialog"
            aria-label={label}
            className="absolute right-0 top-full z-40 mt-2 w-64 space-y-3 rounded-lg border border-line bg-white p-4 text-left shadow-overlay"
          >
            {fields.map((field) => (
              <label key={field.label} className="block">
                <FieldLabel>{field.label}</FieldLabel>
                <select
                  value={field.value}
                  onChange={(e) => field.onChange(e.target.value)}
                  className={inputCls}
                >
                  {field.options.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </label>
            ))}
          </div>
        </>
      )}
    </span>
  )
}

/** White shadow card — the prototype's base surface. */
export function ProtoCard({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`rounded-2xl bg-white shadow-card ${className}`}>{children}</div>
}

/** Status card with the colored top bar (p50/p52/p54/p57/p58). */
export function StatusCard({
  tone,
  children,
}: {
  tone: 'orange' | 'green' | 'yellow' | 'red'
  children: ReactNode
}) {
  const bar =
    tone === 'green' ? 'bg-s-green' : tone === 'yellow' ? 'bg-s-yellow' : tone === 'red' ? 'bg-s-red' : 'bg-s-orange'
  return (
    <div className="overflow-hidden rounded-md bg-white shadow-card">
      <div className={`h-2.5 ${bar}`} />
      <div className="flex flex-col items-center gap-3 px-8 py-10">{children}</div>
    </div>
  )
}

/** Filled light-blue input classes — apply to input/select/textarea. */
export const inputCls =
  'w-full rounded-lg border border-input-border bg-input px-3.5 py-2.5 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal'

/** Field label above an input. */
export function FieldLabel({ children, required }: { children: ReactNode; required?: boolean }) {
  return (
    <span className="mb-1.5 block text-[13px] font-semibold text-ink">
      {children}
      {required && <span className="text-s-red"> *</span>}
    </span>
  )
}

/** Royal pill button (primary). */
export function PillButton({
  children,
  onClick,
  type = 'button',
  disabled,
  className = '',
}: {
  children: ReactNode
  onClick?: () => void
  type?: 'button' | 'submit'
  disabled?: boolean
  className?: string
}) {
  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      className={`inline-flex items-center justify-center rounded-full bg-royal px-7 py-2.5 text-sm font-semibold text-white shadow-card transition-colors hover:bg-royal-hover disabled:opacity-60 ${className}`}
    >
      {children}
    </button>
  )
}
