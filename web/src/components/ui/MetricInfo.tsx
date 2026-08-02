import { createContext, useContext, useEffect, useId, useRef, useState } from 'react'
import type { MetricDefinition } from '../../lib/types'

/*
 * "Where did this number come from?" — answered in place, on the figure itself.
 *
 * Every panel on an analytics screen states a number that somebody is expected
 * to act on, and almost none of them are self-evident: the approval rate leaves
 * out pending filings, the inspection pass rate divides by completed rather
 * than scheduled, the barangay shares are of the subset that has a barangay on
 * record. Each of those is defensible and none is visible in the digits. This
 * is where the reader gets to check.
 *
 * The text comes from the server (`meta.definitions`, written beside the
 * queries in AnalyticsDefinitions.php) and is never authored here. A formula
 * typed into a component is a copy of the truth: change the query and the copy
 * goes stale silently, which on a screen whose purpose is to be trusted is
 * worse than saying nothing.
 *
 * ── Why this is a button and not a CSS tooltip ──────────────────────────────
 *
 * The obvious build is `title=` or a hover-only panel. Both fail the WCAG 2.1
 * AA target PRODUCT.md sets, and they fail it for the two groups most likely to
 * be reading a government dashboard:
 *
 *   - There is no hover on touch. An LGU officer on a tablet would never see
 *     any of it.
 *   - Hover is not reachable by keyboard, so a keyboard or screen-reader user
 *     would not know the content existed.
 *
 * So it opens three ways — pointer, keyboard focus, and click or tap — and SC
 * 1.4.13 (Content on Hover or Focus) sets the rest of the behaviour:
 *
 *   dismissible  Escape closes it without moving focus
 *   hoverable    the pointer can travel into the panel without it vanishing,
 *                which is why the mouse handlers sit on the wrapper and not on
 *                the button
 *   persistent   it stays until dismissed; nothing closes it on a timer
 *
 * Clicking pins it open. That is what makes it work on touch, where there is no
 * hover to sustain and no focus ring to hold — and it also lets a mouse user
 * park the panel while they read a long one instead of holding the pointer
 * still.
 */

const DefinitionsContext = createContext<Record<string, MetricDefinition>>({})

/**
 * Puts a screen's `meta.definitions` in reach of every panel below it.
 *
 * Context rather than props because these panels are nested three deep in
 * places — a heading inside a card inside a grid — and threading a lookup table
 * through every one of them to reach a heading would put the map in the
 * signature of components that have no other interest in it.
 */
export function MetricDefinitions({
  value,
  children,
}: {
  value: Record<string, MetricDefinition> | undefined
  children: React.ReactNode
}) {
  return <DefinitionsContext.Provider value={value ?? {}}>{children}</DefinitionsContext.Provider>
}

/**
 * The call site: `<Info metric="decisions.approval_rate" />` beside a label.
 *
 * Takes the payload's own dot path as its key, so the thing the screen asks for
 * is the thing the server named. A key that no longer exists renders nothing
 * rather than an empty panel, and AnalyticsDefinitionsTest fails the build when
 * a definition stops matching the payload — so a silent gap here means the
 * definition was never written, not that it drifted.
 */
export function Info({ metric }: { metric: string }) {
  return <MetricInfo definition={useContext(DefinitionsContext)[metric]} />
}

export function MetricInfo({ definition }: { definition: MetricDefinition | undefined }) {
  const [open, setOpen] = useState(false)
  // Pinned by click/tap: a pointer leaving must not close what a tap opened.
  const [pinned, setPinned] = useState(false)
  const wrapper = useRef<HTMLSpanElement>(null)
  const panelId = useId()

  // Close on Escape (SC 1.4.13, dismissible) without moving focus, and on a
  // click elsewhere — otherwise a pinned panel would outlive the reader's
  // interest in it.
  useEffect(() => {
    if (!open) return

    function onKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        setOpen(false)
        setPinned(false)
      }
    }
    function onPointerDown(e: PointerEvent) {
      if (!wrapper.current?.contains(e.target as Node)) {
        setOpen(false)
        setPinned(false)
      }
    }

    document.addEventListener('keydown', onKeyDown)
    document.addEventListener('pointerdown', onPointerDown)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.removeEventListener('pointerdown', onPointerDown)
    }
  }, [open])

  /*
   * A metric with no definition renders nothing at all. An info affordance that
   * opens to "no description available" spends the reader's attention to tell
   * them nothing; the server-side test is what keeps this from happening
   * quietly, by failing the build when a panel has no entry.
   */
  if (!definition) return null

  return (
    <span
      ref={wrapper}
      className="relative inline-flex"
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => !pinned && setOpen(false)}
    >
      <button
        type="button"
        aria-expanded={open}
        aria-controls={open ? panelId : undefined}
        // Names the figure, so a screen reader announces "How Approval rate is
        // measured" rather than a row of identical "more information" buttons.
        aria-label={`How ${definition.label} is measured`}
        onFocus={() => setOpen(true)}
        onBlur={() => !pinned && setOpen(false)}
        onClick={() => {
          setPinned((was) => !was)
          setOpen((was) => !was || !pinned)
        }}
        className="ml-1.5 inline-flex h-4 w-4 items-center justify-center rounded-full border border-line text-[10px] font-semibold leading-none text-ink-muted transition-colors hover:border-royal hover:text-royal focus:outline-none focus-visible:ring-2 focus-visible:ring-royal aria-expanded:border-royal aria-expanded:text-royal"
      >
        {/* Decorative: the accessible name is on the button. */}
        <span aria-hidden="true">i</span>
      </button>

      {open && (
        <span
          id={panelId}
          role="note"
          className="absolute left-0 top-6 z-20 block w-80 max-w-[min(20rem,calc(100vw-2rem))] cursor-default rounded-lg border border-line bg-white p-3 text-left text-xs font-normal leading-relaxed shadow-lg"
        >
          <span className="block font-semibold text-ink">{definition.label}</span>

          <Row term="How it is measured" detail={definition.formula} />
          <Row term="What it covers" detail={definition.covers} />
          <Row term="Why it is here" detail={definition.why} />
        </span>
      )}
    </span>
  )
}

/*
 * Three labelled parts rather than one paragraph. The formula alone answers how
 * and leaves why unanswered, and "why is this on the dashboard" is the question
 * the panel actually asks first — see requirement 0.1 in
 * docs/r-integration-revisions.md. Splitting them also lets a reader who only
 * wants the denominator find it without reading the rest.
 *
 * `span` throughout, not `div` or `dl`: this sits inside table cells and
 * headings, where a block element would be invalid HTML and browsers reflow it
 * out of position.
 */
function Row({ term, detail }: { term: string; detail: string }) {
  return (
    <span className="mt-2 block">
      <span className="block text-[11px] font-semibold uppercase tracking-wide text-ink-muted">{term}</span>
      <span className="block text-ink-secondary">{detail}</span>
    </span>
  )
}
