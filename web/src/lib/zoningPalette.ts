/*
 * How a zoning classification is drawn: on the picker map, in its key, and in
 * the classification list beside it — one function each, so the three can
 * never disagree about what "C-1" looks like.
 *
 * Derived from `legend_color` (the database's copy of CPDO's legend) rather
 * than a palette of our own, so a reader holding the printed sheet can still
 * match colours. But not used raw: CPDO's legend is pure primaries — #ff0000,
 * #0000ff, #ffff00 — made for paper, and laid over satellite imagery at full
 * strength they turn the roofs an applicant is looking for into flat paint.
 * So the fill is lifted toward white and drawn at ZONE_FILL_OPACITY, and the
 * edge is the same hue darkened, thin, so neighbouring zones separate by line
 * as well as by colour (DESIGN.md, Never Color Alone; the name in the key and
 * the tooltip is the rest of it).
 *
 * Not red-means-stop territory: these are CPDO's category colours, reproduced
 * as a legend, not our status colours. C-2 is red on the sheet and on the map;
 * nothing about it is an error.
 */

/** Fill opacity for a zone over map tiles. Streets and roofs stay readable through it. */
export const ZONE_FILL_OPACITY = 0.45

const FALLBACK = '#9c9c9c'

function parse(hex: string | null | undefined): [number, number, number] {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex ?? '') ?? /^#?([0-9a-f]{6})$/i.exec(FALLBACK)!
  const n = parseInt(m[1], 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}

function mix(hex: string | null | undefined, toward: [number, number, number], amount: number): string {
  const c = parse(hex)
  const out = c.map((v, i) => Math.round(v + (toward[i] - v) * amount))
  return `#${out.map((v) => v.toString(16).padStart(2, '0')).join('')}`
}

/**
 * The zone's fill: its legend colour, lifted a little toward white — except
 * the residential pastels (R-1 #ffffdb, R-2 #ffffb4), which are already near
 * white and at this opacity vanished into light street tiles altogether. Those
 * are pushed the other way, away from white, so they read as "a yellow zone"
 * at all.
 */
export function zoneFill(legendColor: string | null | undefined): string {
  const [r, g, b] = parse(legendColor)
  if (Math.min(r, g, b) >= 0xb0) {
    const away = (v: number) => Math.max(0, Math.round(255 - (255 - v) * 2))
    return `#${[r, g, b].map((v) => away(v).toString(16).padStart(2, '0')).join('')}`
  }
  return mix(legendColor, [255, 255, 255], 0.1)
}

/**
 * The zone's edge: the same hue, darkened. Pale zones (R-1, R-2's #ffffb4)
 * would otherwise have no visible edge at all over light street tiles.
 */
export function zoneStroke(legendColor: string | null | undefined): string {
  return mix(legendColor, [31, 41, 55], 0.5)
}

/**
 * A key or list swatch: the zone's fill as it reads on the map, flattened onto
 * white, so the swatch matches the map rather than CPDO's raw primary. A touch
 * stronger than the map's own opacity, because a 12 px square has no roofs
 * behind it to lend it weight.
 */
export function zoneSwatch(legendColor: string | null | undefined): string {
  return mix(zoneFill(legendColor), [255, 255, 255], 1 - (ZONE_FILL_OPACITY + 0.2))
}
