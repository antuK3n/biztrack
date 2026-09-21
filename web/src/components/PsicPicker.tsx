import {
  forwardRef,
  Fragment,
  useEffect,
  useImperativeHandle,
  useMemo,
  useRef,
  useState,
} from 'react'
import { COMMON_PSIC_CODES, OTHER_PSIC_CODE, psicSection, psicSectionRank } from '../lib/psic'
import type { PsicCode } from '../lib/types'
import { FieldLabel, inputCls } from './ui/Proto'
import { CheckIcon, SearchIcon } from './icons'

/**
 * The one control for choosing a PSIC line of business.
 *
 * ── Why this is a component and not a second dropdown ────────────────────
 *
 * It was written inside `LinesStep`, and when the amendment form needed the
 * same question asked it got a plain `<select>` of all 135 trades instead — no
 * search, no "most common" head start, no section headings, on a list where
 * "sale" alone matches 48 titles. Client, 21 September 2026: *"if you will
 * have to copy something, make sure you do the copy properly."*
 *
 * The proper copy is no copy. The markup below is the original, moved rather
 * than retyped — a reconstruction from memory had already drifted in a dozen
 * small ways (icon size, placeholder, the empty-result wording) and two
 * controls that are ALMOST the same are worse than one that is obviously
 * shared.
 *
 * What a CALLER owns is what it does with the answer, which is genuinely
 * different on the two screens: a new application writes a line with a
 * capitalisation, an amendment records a requested change. What lives HERE is
 * everything about finding a trade.
 *
 * ── "Other (not listed)" is deliberately not offered ─────────────────────
 *
 * It looked like a kindness and behaved like a hole. Picking it stored the
 * catch-all row (code 00000) with a NULL `category`, and 35 of the 36
 * business-tax rules match on `business_category` — so a line filed under
 * Other matched none of them and was assessed no business tax at all. It also
 * came back from Location Insights as unclassifiable, so that applicant got no
 * nearby-trade figures either.
 *
 * 135 codes is enough to find a trade in, and the search is there to find it
 * with. A trade genuinely missing is a gap in the reference data to fix at the
 * source, not something to let an applicant type into a box nothing downstream
 * can read. Rows already filed under it still RENDER wherever a caller shows
 * the current answer — a renewal or a reopened draft can arrive holding one —
 * they just cannot be chosen again.
 */
export interface PsicPickerHandle {
  /** Open the list with the caret already in the search box. */
  reopen: () => void
}

export const PsicPicker = forwardRef<
  PsicPickerHandle,
  {
    codes: PsicCode[]
    /** The trade chosen, or null while the question is unanswered. */
    chosenId: number | null
    onPick: (code: PsicCode) => void
    /** The field's own name, which is what a screen reader announces. */
    label: string
    required?: boolean
    /** Distinct per instance, so two pickers on one page keep their labels. */
    inputId?: string
  }
>(function PsicPicker(
  { codes, chosenId, onPick, label, required = false, inputId = 'psic-search' },
  ref,
) {
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  /*
   * The "Change" control below a caller's own summary reopens the list, so it
   * has to put the caret where the applicant is about to type. Opening without
   * moving focus leaves a keyboard user staring at a list they are not in.
   */
  const search = useRef<HTMLInputElement>(null)

  useImperativeHandle(ref, () => ({
    reopen() {
      setQuery('')
      setOpen(true)
      search.current?.focus()
    },
  }))

  /*
   * ── Every trade is reachable, and nothing is cut in silence ─────────────
   *
   * Two caps used to stand between an applicant and the list. With the box
   * empty they saw the eight-code shortlist and nothing else, so browsing
   * could not reach trade nine of 135. With a query typed the matches were
   * sliced to 25 and the slice was never mentioned — "sale" matches 48 titles,
   * so twenty-three real trades were dropped off the bottom with no sign they
   * had existed, and an applicant whose trade was among them concluded it was
   * not on the list.
   *
   * `commonCount` is where the "Most common" heading is drawn, and it is 0
   * while searching: relevance, not familiarity, orders a search result.
   */
  const { results, commonCount, total } = useMemo(() => {
    const q = query.trim().toLowerCase()
    const listed = codes.filter((c) => c.code !== OTHER_PSIC_CODE)

    if (q) {
      /*
       * Grouped while searching too. The filter is a substring test, so the
       * order it returns is the reference table's and not a score — sorting by
       * section replaces an arbitrary order with a navigable one and loses no
       * relevance, because there was none to lose.
       */
      const matches = listed
        .filter((c) => c.title.toLowerCase().includes(q) || c.code.includes(q))
        .sort((a, b) => {
          const rank = psicSectionRank(psicSection(a.code)) - psicSectionRank(psicSection(b.code))

          return rank !== 0 ? rank : a.title.localeCompare(b.title)
        })

      return { results: matches, commonCount: 0, total: listed.length }
    }

    const common = COMMON_PSIC_CODES.map((code) => listed.find((c) => c.code === code)).filter(
      (c): c is PsicCode => c !== undefined,
    )
    const promoted = new Set(common.map((c) => c.id))

    /*
     * The rest ordered by SECTION so they can carry headings. A heading can
     * only be drawn where the subject actually changes, so the grouping has to
     * exist in the data before it can exist on the screen. Title within
     * section, so each run under a heading is alphabetical and skimmable.
     */
    const rest = listed
      .filter((c) => !promoted.has(c.id))
      .sort((a, b) => {
        const rank = psicSectionRank(psicSection(a.code)) - psicSectionRank(psicSection(b.code))

        return rank !== 0 ? rank : a.title.localeCompare(b.title)
      })

    return { results: [...common, ...rest], commonCount: common.length, total: listed.length }
  }, [codes, query])

  /*
   * Closes on a click elsewhere and on Escape. The list used to be permanently
   * open, which pushed everything below it off the screen.
   */
  useEffect(() => {
    if (!open) return

    function onPointerDown(e: PointerEvent) {
      if (!box.current?.contains(e.target as Node)) setOpen(false)
    }
    function onKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') setOpen(false)
    }

    document.addEventListener('pointerdown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.removeEventListener('pointerdown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  /*
   * Choosing a different trade REPLACES the current one. Re-picking the trade
   * already chosen is not a change and must not read as one.
   */
  function toggle(code: PsicCode) {
    if (code.id === chosenId) return
    onPick(code)
    setOpen(false)
  }

  const chosenCode = codes.find((c) => c.id === chosenId)

  /* relative: the results hang over what follows instead of shoving it down. */
  return (
    <div ref={box} className="relative">
      <label htmlFor={inputId} className="block">
        {/*
         * "Search for the ONE line" — the instruction is in the field's own
         * name, where it cannot be scrolled past, rather than only in help
         * text above it. This is the label a screen reader announces when
         * the applicant arrives in the box, so it is the last chance to say
         * how many answers the question takes before they give one.
         */}
        <FieldLabel required={required}>{label}</FieldLabel>
      </label>
      <div className="relative">
        <SearchIcon
          size={18}
          className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-ink-secondary"
        />
        <input
          id={inputId}
          ref={search}
          role="combobox"
          aria-expanded={open}
          aria-controls={`${inputId}-results`}
          aria-autocomplete="list"
          value={query}
          onFocus={() => setOpen(true)}
          onChange={(e) => {
            setQuery(e.target.value)
            setOpen(true)
          }}
          placeholder="e.g. retail, food, salon"
          className={`${inputCls} pl-10`}
        />
      </div>

      {open && (
        /*
         * z-[1100] rather than a small z-index because the map sits directly
         * below this and Leaflet builds its own stacking world: tile and
         * marker panes at 200-600, controls at 1000. At z-20 the list
         * rendered *under* the map — the results were sliced in half and the
         * panel's white showed through beneath it, which read as a layout bug
         * rather than a dropdown.
         */
        <div className="absolute z-[1100] mt-1 w-full overflow-hidden rounded-lg border border-input-border bg-white shadow-lg">
          {/*
           * ── Item 104a · the confirmation you can actually see ──────────
           *
           * "The selected line of business does not reflect after choosing."
           * It did reflect — in two places the applicant could not see. The
           * row's checkbox ticks inside a list they are still reading, and
           * the "Selected (N)" panel is directly BELOW this dropdown, which
           * is absolutely positioned and up to 16rem tall and therefore
           * sitting on top of it. `document.elementFromPoint` over the
           * "Selected (1)" heading returns a result row, not the heading: at
           * the moment of the click the only confirmation on screen was a
           * 20px tick inside a list of identical rows.
           *
           * So the confirmation is put where the eye already is — pinned to
           * the top of the open list, against the tinted background, naming
           * what is now selected. It cannot be covered by the dropdown
           * because it is part of it, and it needs no scrolling because it
           * is directly under the box being typed in.
           *
           * The panel below takes over the moment the list closes.
           *
           * It reads "Your line of business is X" and NOT "Selected (1)":
           * a running count is what a shopping basket says, and the only
           * reason to print one is that the number can change. It cannot.
           * Reopening this list to pick again is a correction, and the
           * wording says so — "picking another replaces it" — so nobody
           * arrives at a second trade expecting it to be added.
           */}
          {chosenCode && (
            <p className="border-b border-line bg-royal-tint px-4 py-2.5 text-xs font-semibold text-royal">
              <span className="mr-1.5 inline-flex h-4 w-4 translate-y-0.5 items-center justify-center rounded-sm bg-royal text-white">
                <CheckIcon size={11} />
              </span>
              Your line of business is{' '}
              <span className="font-normal text-ink">{chosenCode.title}</span>
              <span className="font-normal text-ink-secondary">
                {' '}
                — picking another replaces it.
              </span>
            </p>
          )}

          <ul
            id={`${inputId}-results`}
            // The rows are radios now, so the list that holds them has to say
            // so — otherwise a screen reader meets a radio with no group.
            role="radiogroup"
            aria-label="Line of business"
            className="max-h-64 divide-y divide-line overflow-y-auto"
          >
            {results.length === 0 ? (
              <li className="px-4 py-4 text-sm text-ink-secondary">
                No trade matches “{query.trim()}”. Try a plainer word — “food” rather than the
                dish, “retail” rather than the goods.
              </li>
            ) : (
              results.map((code, index) => {
                const selected = code.id === chosenId
                return (
                  <Fragment key={code.id}>
                    {/*
                     * The shortlist is a head start, not a fence, so it says
                     * which it is and where it ends. Without the second
                     * heading the ninth row looks like more of the same and
                     * an applicant who has read eight stops reading.
                     */}
                    {commonCount > 0 && index === 0 && (
                      <li className="bg-shell px-4 py-1.5 text-[11px] font-bold uppercase tracking-[0.1em] text-ink-secondary">
                        Most common
                      </li>
                    )}
                    {/*
                     * Item 6 — a heading wherever the SECTION changes, so the
                     * 127 trades past the shortlist are skimmed rather than
                     * read.
                     *
                     * "All other trades (127)" used to stand here alone, and
                     * naming the size of a list is not the same as making it
                     * navigable: it told the applicant exactly how much
                     * scrolling was ahead and nothing about where to stop.
                     *
                     * Drawn on CHANGE rather than by slicing the array into
                     * groups, because the rows are one radiogroup and the
                     * index each row reports is its position in it. Splitting
                     * the list into per-section arrays would restart that
                     * count in every group and break the radio semantics for
                     * the sake of tidier JSX.
                     *
                     * Drawn while searching too. `commonCount` is 0 then, so
                     * `index >= commonCount` is true from the first row and
                     * the whole result set is headed — which is the point,
                     * since a query like "sale" returns 48 rows and that is
                     * the wall this item exists to remove.
                     */}
                    {index >= commonCount &&
                      (index === commonCount ||
                        psicSection(results[index - 1].code) !== psicSection(code.code)) && (
                        <li className="bg-shell px-4 py-1.5 text-[11px] font-bold uppercase tracking-[0.1em] text-ink-secondary">
                          {psicSection(code.code)}
                        </li>
                      )}
                    <li>
                      <button
                        type="button"
                        onClick={() => toggle(code)}
                        /*
                         * radio, not aria-pressed: these are exclusive
                         * answers to one question now, and `aria-pressed`
                         * would announce 134 independent switches.
                         */
                        role="radio"
                        aria-checked={selected}
                        className={`flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors ${
                          selected ? 'bg-input' : 'hover:bg-royal-tint'
                        }`}
                      >
                        <span
                          className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-sm border ${
                            selected
                              ? 'border-royal bg-royal text-white'
                              : 'border-input-border bg-white'
                          }`}
                        >
                          {selected && <CheckIcon size={13} />}
                        </span>
                        <span className="min-w-0">
                          <span className="block text-sm font-medium text-ink">{code.title}</span>
                          <span className="tnum block text-xs text-ink-secondary">
                            PSIC {code.code}
                          </span>
                        </span>
                      </button>
                    </li>
                  </Fragment>
                )
              })
            )}
          </ul>

          {/*
           * Item 104b — the count, stated. Nothing is cut any more, and
           * saying so is the half of the fix that stops an applicant giving
           * up: "8 shown" out of 135 with no total was indistinguishable
           * from "your trade is not on the list".
           */}
          {results.length > 0 && (
            <p className="border-t border-line bg-white px-4 py-2 text-xs text-ink-secondary">
              {query.trim()
                ? `Showing all ${results.length} of ${total} trades matching “${query.trim()}”.`
                : `Showing all ${total} trades — the most common first. Scroll for the rest.`}
            </p>
          )}
        </div>
      )}
    </div>
  )
})

export default PsicPicker
