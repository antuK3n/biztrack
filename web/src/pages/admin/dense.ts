/*
 * ── The compact scale for the staff and admin work screens ──────────────────
 *
 * Client feedback, 2026-09: "When viewing the admin page there's so much space,
 * e.g. when I try to find a field. It doesn't need to be as user-friendly; if
 * possible make it so every detail fits without scrolling." The target taken
 * from that is a 1440×900 screen showing a whole page of a list without the
 * page scrolling, wherever the page size allows it.
 *
 * These are class strings rather than components because the screens they
 * serve already own their markup, their tests query that markup by role and
 * name, and a wrapper component would have changed the DOM the specs read for
 * no gain. Swapping a class string changes only how it looks.
 *
 * Why a separate scale and not smaller defaults in `Proto.tsx`: the applicant
 * side is roomy on purpose. A business owner files once a year on a phone; a
 * BPLO clerk works a queue all day on a desktop. The two audiences want
 * opposite things, so the compact scale is opt-in and lives beside the screens
 * that opt in.
 *
 * Floors that are NOT traded for density (WCAG 2.1 AA, PRODUCT.md):
 *   - no text below 12px — the old 10–11px table headings were raised to 12
 *     here, so the compact tables are more legible in that one spot, not less;
 *   - no control under 24px tall (WCAG 2.5.8 target size) — `h-6` is the floor;
 *   - focus rings kept exactly as the roomy scale draws them;
 *   - colours unchanged, so contrast is unchanged.
 *
 * `DENSE_PAGE` is the marker the app shell looks for: a page that renders it
 * gets the full content width and a shallower top margin (AppShell.tsx,
 * `has-[[data-density=compact]]`). Every other page keeps the centred 6xl
 * column, which is why this is a data attribute a page opts into and not a
 * change to the shell's defaults.
 */

/** Spread onto a page's root element to opt into the compact shell. */
export const DENSE_PAGE = { 'data-density': 'compact' } as const

/** Filled input / select, compact: 32px tall, 13px text. No width — the call site sets one (a `w-full` here would beat a `w-44` there). */
export const dInput =
  'rounded-md border border-input-border bg-input px-2.5 py-[5px] text-[13px] leading-5 text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal'

/** Small uppercase label above a compact filter control. */
export const dLabel = 'mb-0.5 block text-xs font-semibold text-ink-muted'

/** Table: 13px body text. */
export const dTable = 'w-full text-left text-[13px] leading-[18px]'

/** Header row of a compact table. */
export const dTheadRow = 'bg-canvas/50 text-xs font-semibold uppercase tracking-wide text-ink-muted'

/** Header cell. */
export const dTh = 'px-3 py-1.5 font-semibold'

/** Body cell: one 18px line plus 4px either side — a 27px text row, 33px where it holds a 24px button. */
export const dTd = 'px-3 py-1'

/** Footer strip under a table (count + pager). */
export const dFoot = 'flex items-center justify-between gap-4 border-t border-line px-3 py-1.5'

/** Pager arrow: 24px square, the target-size floor. */
export const dPager =
  'flex h-6 w-6 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40'

/** Secondary row action: white pill, 24px tall. */
export const dBtn =
  'inline-flex h-6 items-center justify-center whitespace-nowrap rounded-full border border-line bg-white px-2.5 text-xs font-semibold text-ink-secondary hover:bg-canvas'

/** Primary row action: royal pill, 24px tall. */
export const dBtnPrimary =
  'inline-flex h-6 items-center justify-center whitespace-nowrap rounded-full border border-transparent bg-royal px-2.5 text-xs font-semibold text-white hover:bg-royal-hover'

/** Toolbar button beside the title (Add, Refresh): 32px to sit level with `dInput`. */
export const dToolbarBtn =
  'inline-flex h-8 items-center justify-center whitespace-nowrap rounded-full border border-line bg-white px-3.5 text-[13px] font-semibold text-ink hover:bg-canvas'

/** Primary toolbar button. */
export const dToolbarBtnPrimary =
  'inline-flex h-8 items-center justify-center whitespace-nowrap rounded-full border border-transparent bg-royal px-3.5 text-[13px] font-semibold text-white hover:bg-royal-hover'
