import type { RiskBand } from '../../lib/types'

/*
 * The Renewal Risk Index, drawn as the spec asks for it: a circular badge with
 * the number at its centre, filled proportionally around the ring, in the
 * colour of the row's risk level.
 *
 * ── Why hand-rolled SVG and not recharts ────────────────────────────────────
 *
 * recharts is already a dependency and has RadialBarChart, but every recharts
 * chart carries a ResponsiveContainer and a resize observer. This badge appears
 * once per table row — twenty-five observers on a table that exists to be
 * scanned quickly. Two circles and a `stroke-dasharray` is the whole drawing,
 * and it renders at its final size on first paint instead of measuring first.
 *
 * ── Why the number is SVG text and the ring is not the message ──────────────
 *
 * DESIGN.md's Never Color Alone rule. The ring is redundant with two things
 * that are already text: the digits at the centre and the level badge in the
 * next cell. That redundancy is what lets the arc use vivid traffic colours —
 * a reader who cannot separate the green from the red loses nothing, because
 * neither the score nor the level was ever carried by hue alone.
 *
 * The whole badge is one `role="img"` with an aria-label, so a screen reader
 * hears "Risk index 75 out of 100, High risk" rather than a bare "75" floating
 * between two table cells.
 */

/**
 * The traffic-light scale, shared so the ring, the level badge and the summary
 * cards cannot drift apart.
 *
 * Red is normally reserved for errors (DESIGN.md), and it is spent here on
 * purpose: the spec asks for a traffic-light scale, and a permit whose cover
 * has already lapsed is a genuine warning rather than a stylistic accent.
 *
 * Each value clears 3:1 against white (WCAG 2.1 SC 1.4.11, non-text contrast).
 * That is why moderate is a dark gold rather than the token `--color-s-yellow`
 * (#f5c518): the raw yellow sits near 1.6:1 on white, which on a 4px ring is a
 * suggestion of a line rather than a line.
 */
export const RISK_ARC: Record<RiskBand, string> = {
  low: '#1c8f5c',
  moderate: '#b58500',
  high: '#bd0000',
}

/** Geometry, in the SVG's own user units. */
const SIZE = 44
const STROKE = 4
const RADIUS = (SIZE - STROKE) / 2
const CIRCUMFERENCE = 2 * Math.PI * RADIUS

export function RiskScoreDial({
  score,
  band,
  bandLabel,
}: {
  /** 0–100, out of a weighted rule total. Not a percentage of anything. */
  score: number
  band: RiskBand
  /** The server's own word for the band — "High", "Moderate", "Low". */
  bandLabel: string
}) {
  // Clamped because the arc is geometry: a score outside 0–100 would wrap the
  // dash around the circle and draw a ring that reads as a lower number.
  const filled = Math.max(0, Math.min(100, score)) / 100

  return (
    <svg
      width={SIZE}
      height={SIZE}
      viewBox={`0 0 ${SIZE} ${SIZE}`}
      role="img"
      aria-label={`Risk index ${score} out of 100, ${bandLabel} risk`}
      className="block"
    >
      {/* The unfilled remainder, so the ring reads as a proportion of a whole. */}
      <circle
        cx={SIZE / 2}
        cy={SIZE / 2}
        r={RADIUS}
        fill="none"
        stroke="#e7ebf3"
        strokeWidth={STROKE}
      />
      {/*
        Rotated so the fill starts at twelve o'clock, which is where a reader
        expects a dial to start. `strokeLinecap="round"` would overhang the arc
        by half a stroke at both ends — visible as roughly 3 extra points on a
        low score — so the cap stays butt.
      */}
      <circle
        cx={SIZE / 2}
        cy={SIZE / 2}
        r={RADIUS}
        fill="none"
        stroke={RISK_ARC[band]}
        strokeWidth={STROKE}
        strokeDasharray={CIRCUMFERENCE}
        strokeDashoffset={CIRCUMFERENCE * (1 - filled)}
        transform={`rotate(-90 ${SIZE / 2} ${SIZE / 2})`}
      />
      {/*
        The score at the centre, as the spec requires. `aria-hidden` because the
        parent's aria-label already says it with its units and its level
        attached; without this a screen reader reads the number twice, once
        stripped of both.
      */}
      <text
        x={SIZE / 2}
        y={SIZE / 2}
        textAnchor="middle"
        dominantBaseline="central"
        className="tnum"
        fontSize="14"
        fontWeight="700"
        fill="#14171d"
        aria-hidden="true"
      >
        {score}
      </text>
    </svg>
  )
}
