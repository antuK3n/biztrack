import { useEffect, useRef, useState } from 'react'
import type { RefObject } from 'react'
import { SearchIcon } from '../../components/icons'

/**
 * "Search this filing" — highlight every match and jump between them.
 *
 * Client, 19 October 2026: *"put a search option in the admin view of the
 * filing so that he/she can easily search for specific fields."* The choice
 * taken was highlight-and-jump: every match marked, the first scrolled to,
 * "2 of 5" with previous / next, Enter and Shift+Enter, Esc to clear.
 *
 * ── Why a text walk over the sheet, and why the Highlight API ─────────────
 *
 * The sheet is two thousand lines of hand-placed fields, labels and values
 * from a dozen sources (the business, the fee profile, the office sheets, the
 * amendment rows, the documents). A field model to search would be a second
 * copy of all of it, and the first thing to drift. Walking the rendered text
 * inside the sheet searches exactly what the officer can read — labels and
 * answers alike — and nothing else.
 *
 * Matches are painted with the CSS Custom Highlight API rather than by
 * wrapping text in <mark>: the text nodes belong to React, and splitting them
 * under it is how a later render throws "node to be removed is not a child".
 * Where the API is missing, the count and the jump still work; only the paint
 * is lost.
 *
 * Nothing here re-renders the page per keystroke: typing updates this
 * component's own state, the walk is debounced, and the parent is only asked
 * to open a folded section when that section actually holds a match.
 */
export function FilingSearch({
  containerRef,
  reveal,
  version,
}: {
  /** The sheet to search. Text in a `hidden` region is skipped. */
  containerRef: RefObject<HTMLElement | null>
  /**
   * Open any folded section whose text contains `query`. Returns true when
   * something was opened, so the walk waits a frame for it to render.
   */
  reveal: (query: string) => boolean
  /** Changes whenever the sheet's content does, so stale ranges are redone. */
  version: unknown
}) {
  const [text, setText] = useState('')
  const [query, setQuery] = useState('')
  const [count, setCount] = useState(0)
  const [index, setIndex] = useState(0)
  const [open, setOpen] = useState(false)
  const ranges = useRef<Range[]>([])
  const inputRef = useRef<HTMLInputElement>(null)
  const revealRef = useRef(reveal)
  revealRef.current = reveal

  // Debounced: the walk runs once the officer pauses, not per character.
  useEffect(() => {
    const t = window.setTimeout(() => setQuery(text.trim()), 220)

    return () => window.clearTimeout(t)
  }, [text])

  useEffect(() => {
    let cancelled = false
    const run = () => {
      if (cancelled) return
      ranges.current = query.length < 2 ? [] : findRanges(containerRef.current, query)
      setCount(ranges.current.length)
      setIndex(0)
      paint(ranges.current, 0)
      if (ranges.current.length > 0) scrollToRange(ranges.current[0])
    }
    if (query.length >= 2 && revealRef.current(query)) {
      // A section was opened for this query; let it render, then walk.
      window.setTimeout(run, 60)
    } else {
      run()
    }

    return () => {
      cancelled = true
    }
  }, [query, version, containerRef])

  // Clear the paint when the page goes.
  useEffect(() => () => paint([], 0), [])

  const go = (step: number) => {
    const total = ranges.current.length
    if (total === 0) return
    const next = (index + step + total) % total
    setIndex(next)
    paint(ranges.current, next)
    scrollToRange(ranges.current[next])
  }

  const clear = () => {
    setText('')
    setQuery('')
  }

  const status =
    query.length < 2 ? '' : count === 0 ? 'No matches' : `${index + 1} of ${count}`

  return (
    <div role="search" aria-label="Search this filing" className="flex min-w-0 items-center gap-1.5">
      {/* The paint itself. Scoped names, so nothing else on the page is touched. */}
      <style>{HIGHLIGHT_CSS}</style>
      {/*
        Below `sm` the box folds to an icon, so the jump bar keeps its row on
        a phone. Opening it focuses the input; the box stays open while it
        holds a query.
      */}
      <button
        type="button"
        onClick={() => {
          setOpen(true)
          window.setTimeout(() => inputRef.current?.focus(), 0)
        }}
        aria-label="Search this filing"
        className={`${open || text !== '' ? 'hidden' : 'flex'} h-8 w-8 items-center justify-center rounded-md border border-line bg-white text-ink-secondary hover:bg-canvas sm:hidden`}
      >
        <SearchIcon size={15} />
      </button>
      <div
        className={`${open || text !== '' ? 'flex' : 'hidden'} min-w-0 items-center gap-1 rounded-md border border-input-border bg-white px-2 focus-within:border-royal sm:flex`}
      >
        <SearchIcon size={14} className="shrink-0 text-ink-muted" />
        <input
          ref={inputRef}
          type="text"
          value={text}
          onChange={(e) => setText(e.target.value)}
          onBlur={() => text === '' && setOpen(false)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              // A query still inside its debounce is run now, not skipped.
              if (text.trim() !== query) setQuery(text.trim())
              else go(e.shiftKey ? -1 : 1)
            } else if (e.key === 'Escape') {
              e.preventDefault()
              clear()
            }
          }}
          placeholder="Search this filing"
          aria-label="Search this filing"
          aria-describedby="filing-search-status"
          className="h-8 w-36 min-w-0 bg-transparent text-sm text-ink placeholder:text-ink-muted focus:outline-none lg:w-44"
        />
        <span
          id="filing-search-status"
          aria-live="polite"
          className={`whitespace-nowrap text-xs tabular-nums ${count === 0 && query.length >= 2 ? 'font-semibold text-s-red' : 'text-ink-muted'}`}
        >
          {status}
        </span>
        {count > 1 && (
          <>
            <button
              type="button"
              onClick={() => go(-1)}
              aria-label="Previous match"
              className="rounded px-1 text-sm text-ink-secondary hover:bg-canvas"
            >
              ↑
            </button>
            <button
              type="button"
              onClick={() => go(1)}
              aria-label="Next match"
              className="rounded px-1 text-sm text-ink-secondary hover:bg-canvas"
            >
              ↓
            </button>
          </>
        )}
        {text !== '' && (
          <button
            type="button"
            onClick={() => {
              clear()
              inputRef.current?.focus()
            }}
            aria-label="Clear search"
            className="rounded px-1 text-sm text-ink-muted hover:bg-canvas hover:text-ink"
          >
            ✕
          </button>
        )}
      </div>
    </div>
  )
}

/*
 * Yellow for every match, amber for the current one, both behind dark ink:
 * #fff1a8 and #ffc65c each hold well over 7:1 with the page's ink, so the
 * text stays readable under the paint (WCAG 1.4.3). The current one also
 * gets an underline, so it is not told apart by colour alone.
 */
const HIGHLIGHT_CSS =
  '::highlight(filing-search){background-color:#fff1a8;color:#111827}' +
  '::highlight(filing-search-current){background-color:#ffc65c;color:#111827;text-decoration:underline}'

type HighlightRegistry = { set: (name: string, h: unknown) => void; delete: (name: string) => void }
type HighlightCtor = new (...ranges: Range[]) => unknown

function registry(): { reg: HighlightRegistry; Highlight: HighlightCtor } | null {
  const css = (globalThis as { CSS?: { highlights?: HighlightRegistry } }).CSS
  const Highlight = (globalThis as { Highlight?: HighlightCtor }).Highlight
  if (!css?.highlights || !Highlight) return null

  return { reg: css.highlights, Highlight }
}

function paint(ranges: Range[], current: number) {
  const api = registry()
  if (!api) return
  if (ranges.length === 0) {
    api.reg.delete('filing-search')
    api.reg.delete('filing-search-current')

    return
  }
  api.reg.set('filing-search', new api.Highlight(...ranges))
  api.reg.set('filing-search-current', new api.Highlight(ranges[current]))
}

/** Every visible occurrence of `query` in the container's text, in reading order. */
function findRanges(root: HTMLElement | null, query: string): Range[] {
  if (!root) return []
  const needle = query.toLowerCase()
  const found: Range[] = []
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
    acceptNode(node) {
      const parent = node.parentElement
      if (!parent) return NodeFilter.FILTER_REJECT
      // Folded, screen-reader-only and form-control text is not "on the page".
      if (parent.closest('[hidden], .sr-only, select, option, textarea, script, style')) {
        return NodeFilter.FILTER_REJECT
      }
      return (node.textContent ?? '').toLowerCase().includes(needle)
        ? NodeFilter.FILTER_ACCEPT
        : NodeFilter.FILTER_REJECT
    },
  })
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    const haystack = (node.textContent ?? '').toLowerCase()
    for (let at = haystack.indexOf(needle); at !== -1; at = haystack.indexOf(needle, at + needle.length)) {
      const range = document.createRange()
      range.setStart(node, at)
      range.setEnd(node, at + needle.length)
      found.push(range)
    }
  }

  return found
}

/** Scroll the match into view below the sticky bar, not under it. */
function scrollToRange(range: Range) {
  const rect = range.getBoundingClientRect()
  const sticky = document.querySelector<HTMLElement>('[data-review-sticky]')
  const top = (sticky?.getBoundingClientRect().height ?? 0) + 24
  if (rect.top >= top && rect.bottom <= window.innerHeight - 24) return
  window.scrollTo({ top: window.scrollY + rect.top - top - window.innerHeight / 4, behavior: 'smooth' })
}
