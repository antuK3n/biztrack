import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { api } from '../../lib/api'
import { Skeleton } from '../../components/ui/primitives'
import { useAsync } from '../../lib/useAsync'

/*
 * Business Location Insights (docs/r-integration-spec.md §5) — the panel under
 * the map on the apply wizard's Location & Zoning step, for the point the
 * applicant has pinned.
 *
 * ## Why it is on the map step and not in the zoning-result modal
 *
 * It used to render inside the conformity modal, which opened on the way OUT of
 * this step. That put decision support after the decision. An applicant reads
 * "how many shops like mine are already on this block" in order to CHOOSE where
 * to pin; behind a modal they only meet it once the choice is made and the next
 * thing on screen is a Proceed button. The client asked for it on the pin, and
 * the client is right: these four figures are an input to picking a location,
 * so they belong beside the map while the pin can still be moved.
 *
 * The move changes the lifecycle, not the content. In the modal the query was
 * frozen once and thrown away on close; here the pin can move any number of
 * times, so ApplyWizard debounces the coordinates before they become a query and
 * treats the interval between a new pin and its answer as loading rather than
 * showing the previous point's numbers under the new pin. See `insightsQuery`
 * there — the staleness rule is the part that would be easy to get wrong.
 *
 * Types are declared here rather than in lib/types.ts and the fetch is inline
 * rather than in lib/resources.ts: this shape has exactly one consumer, and
 * keeping it beside that consumer means the whole feature is one file to read.
 *
 * Every label on screen names the thing that was actually measured. The radius
 * comes from the API rather than a constant duplicated here, "similar" says
 * which trade it matched, and the mean distance says it is straight-line — an
 * applicant deciding where to open a shop is entitled to know that "320 m" is
 * as-the-crow-flies and that the comparison set is their own PSIC group. That
 * same `radius_m` is what MapPicker draws the ring from, for the same reason:
 * one number, stated once by the side that measured it.
 *
 * ## Two rows, and why not four (client, 23 September 2026: "less is more")
 *
 * The panel had four titled rows, each with a sentence under it: nearby similar
 * businesses, business concentration, the most common line of business, and the
 * average distance to similar businesses. The client asked for less. What an
 * applicant choosing a spot actually weighs is how many shops like theirs are
 * already there and how busy the area is, so those two stay and the rest went:
 *
 *   - "Most Common Line of Business" counted a different width of PSIC (the
 *     2-digit division) from the similar count (the 3-digit group), and the two
 *     side by side invited arithmetic that does not hold — a dairy applicant
 *     once filed a bug against a count that was right. Gone, that confusion
 *     goes with it.
 *   - "Average Distance" restated the similar count as a distance, with a
 *     caveat (straight-line, not walking) that needed its own sentence.
 *
 * The radius is said once, in the caption, instead of in each row. The payload
 * is unchanged — `common_type` and `average_distance_m` are still sent and
 * pinned by LocationInsightsApiTest — so either row can come back without an
 * API change if the client asks for it.
 *
 * The fifth row once briefly here, "Businesses in your own category" (a
 * `your_line` key), was removed earlier on the client's instruction and is not
 * to be re-added; the note on LocationInsights.php says why.
 */

interface LocationInsightsData {
  radius_m: number
  concentration: {
    count: number
    band: 'low' | 'medium' | 'high'
    thresholds: { medium_from: number; high_from: number }
  }
  similar: {
    /** False when there is no PSIC group to compare against — see `reason`. */
    available: boolean
    /**
     * Why there is no figure, when there is none.
     *
     * `line_not_chosen` is the applicant's to fix; `line_unclassified` is not —
     * they picked "Other (not listed)", which classifies nothing, and asking them
     * to choose a line again would send them back for something they already did.
     */
    reason: 'line_not_chosen' | 'line_unclassified' | null
    psic_group: string | null
    /**
     * The applicant's own 5-digit sub-class title, still sent by the controller.
     *
     * Nothing renders it now; the row is titled "Similar Businesses" and the
     * count covers the whole 3-digit group, not just this sub-class. Kept on the type because it is genuinely on the wire and a
     * type that omits a field it receives is a type that lies; removing it for
     * real means editing LocationInsightsController, which is a separate change.
     */
    psic_title: string | null
    count: number | null
    average_distance_m: number | null
  }
  common_type: {
    available: boolean
    category: string | null
    count: number | null
    of_total: number
  }
  /**
   * What City Ordinance 24-2018 LISTS for the zones on this barangay's CPDO
   * sheet (checklist item 20).
   *
   * A lookup, never a determination — `ZoningConformance` in the API carries
   * the four reasons the ordinance cannot be automated into a verdict. Hence
   * `listed` / `not_listed` / `undetermined` rather than conforming and
   * prohibited, and hence `matched_use`: quoting the clause that matched makes
   * a bad match visible to the applicant instead of hiding it behind a word.
   */
  zoning: {
    verdict: 'listed' | 'not_listed' | 'undetermined'
    reason: string
    trade: string | null
    zones: {
      code: string
      name: string
      use_count: number
      listed: boolean
      matched_use: string | null
    }[]
  }
}

export interface LocationInsightsQuery {
  latitude: number
  longitude: number
  psicCodeId: number | null
  businessId: number | null
  /** The barangay the applicant chose; the zoning answer is keyed on it. */
  barangayId: number | null
}

/** Reads the insights for one pinned point. Computed per request — see LocationInsights.php. */
export function useLocationInsights(query: LocationInsightsQuery | null) {
  const { latitude, longitude, psicCodeId, businessId, barangayId } = query ?? {
    latitude: 0,
    longitude: 0,
    psicCodeId: null,
    businessId: null,
    barangayId: null,
  }

  return useAsync<LocationInsightsData | null>(async () => {
    if (query === null) return null
    const { data } = await api.get<{ data: LocationInsightsData }>('location-insights', {
      params: {
        latitude,
        longitude,
        ...(psicCodeId !== null ? { psic_code_id: psicCodeId } : {}),
        ...(businessId !== null ? { business_id: businessId } : {}),
        ...(barangayId !== null ? { barangay_id: barangayId } : {}),
      },
    })
    return data.data
  }, [query === null, latitude, longitude, psicCodeId, businessId, barangayId])
}

/*
 * Bands are an ordinal scale, not a verdict: a busy block is not an error and a
 * quiet one is not a pass, so nothing here borrows the error red (DESIGN.md, the
 * Red Means Stop rule). The band word is always rendered as text next to the
 * tint, so the scale survives with colour off (the Never Color Alone rule).
 */
const BAND_LABEL: Record<'low' | 'medium' | 'high', string> = {
  low: 'Low',
  medium: 'Medium',
  high: 'High',
}

const BAND_CLASS: Record<'low' | 'medium' | 'high', string> = {
  low: 'bg-canvas text-ink-secondary',
  medium: 'bg-s-yellow-tint text-amber-800',
  high: 'bg-s-purple-tint text-s-purple',
}

/**
 * One row: a title, the figure, and optionally an info affordance on the
 * title. Rows had a description sentence under the title until the panel was
 * cut to two rows (client, 23 September 2026); the caption above the table
 * now says once what both count.
 */
function InsightRow({
  title,
  info,
  children,
}: {
  title: string
  /** Rendered inline after the title. See `InfoNote`. */
  info?: ReactNode
  children: ReactNode
}) {
  return (
    <tr className="align-baseline">
      <th scope="row" className="py-2.5 pr-3 text-left font-normal text-ink">
        <span className="flex gap-2">
          <span aria-hidden="true" className="mt-[0.45rem] h-1.5 w-1.5 shrink-0 rounded-full bg-ink-muted" />
          <span>
            <span className="font-medium">{title}</span>
            {info}
          </span>
        </span>
      </th>
      <td className="w-px whitespace-nowrap py-2.5 pl-3 text-right align-baseline text-sm font-semibold text-ink">
        {children}
      </td>
    </tr>
  )
}

/*
 * The small "i" that holds the concentration band scale.
 *
 * ## Why this is not `<Info>` from components/ui/MetricInfo
 *
 * That component is the right PATTERN and the wrong fit twice over. It reads its
 * text from `DefinitionsContext`, which is filled from an analytics response's
 * `meta.definitions` — the location-insights endpoint sends no such block, and
 * inventing one for a single band scale would put a fake analytics metric on the
 * wire. And `MetricInfo` renders a fixed three-part body (How it is measured /
 * What it covers / Why it is here) from a `MetricDefinition`; the client asked
 * for exactly the band scale and nothing else, so two of those three parts would
 * be padding invented to fill a shape.
 *
 * So this is the smallest thing that keeps the BEHAVIOUR, and the behaviour is
 * the part that matters. Deliberately NOT a `title=` tooltip: there is no hover
 * on touch, so an applicant on a phone — most of them — would never see it, and
 * hover is not reachable by keyboard either. WCAG 2.1 AA SC 1.4.13 (Content on
 * Hover or Focus) sets the rest:
 *
 *   dismissible  Escape closes it without moving focus
 *   hoverable    the pointer can travel into the panel without it vanishing,
 *                which is why the mouse handlers are on the wrapper, not the
 *                button
 *   persistent   it stays until dismissed; nothing closes it on a timer
 *
 * Click pins it open, which is what makes it work on touch where there is no
 * hover to sustain. Same three-way open (pointer, focus, tap) as the analytics
 * screens, so the affordance means the same thing wherever an applicant or an
 * officer meets it.
 */
function InfoNote({ label, children }: { label: string; children: ReactNode }) {
  const [open, setOpen] = useState(false)
  // Pinned by click/tap: a pointer leaving must not close what a tap opened.
  const [pinned, setPinned] = useState(false)
  const wrapper = useRef<HTMLSpanElement>(null)
  const panelId = useId()

  useEffect(() => {
    if (!open) return

    function onKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        setOpen(false)
        setPinned(false)
      }
    }
    // A pinned panel must not outlive the reader's interest in it.
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

  return (
    <span
      ref={wrapper}
      className="relative inline-flex align-middle"
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => !pinned && setOpen(false)}
    >
      <button
        type="button"
        aria-expanded={open}
        aria-controls={open ? panelId : undefined}
        // Names what opens, so a screen reader announces the subject rather than
        // yet another anonymous "more information".
        aria-label={label}
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
          /*
           * `span`, not `div`: this lives inside a `<th>`, where a block element
           * is invalid HTML and browsers reflow it out of position.
           */
          className="absolute left-0 top-6 z-20 block w-64 max-w-[min(16rem,calc(100vw-2rem))] cursor-default rounded-lg border border-line bg-white p-3 text-left text-sm font-normal leading-relaxed text-ink-secondary shadow-lg"
        >
          {children}
        </span>
      )}
    </span>
  )
}

/** The dash a figure gets when it genuinely has no value, never a zero. */
function Unavailable({ children }: { children: string }) {
  return (
    <span className="font-normal text-ink-muted">
      <span aria-hidden="true">— </span>
      <span className="text-sm">{children}</span>
    </span>
  )
}

/*
 * Why a "similar businesses" figure is missing.
 *
 * The two cases used to share one message, "choose your Line of Business first",
 * which is wrong half the time: an applicant who picked "Other (not listed)" did
 * choose, and sending them back to choose again gets them nowhere. Checklist item
 * 67 lets them type their own trade under Other, so that half is about to grow.
 */
const SIMILAR_UNAVAILABLE: Record<'line_not_chosen' | 'line_unclassified', string> = {
  line_not_chosen: 'choose your line of business first',
  line_unclassified: 'your line is not in the PSIC list',
}

function similarUnavailableReason(reason: 'line_not_chosen' | 'line_unclassified' | null): string {
  return SIMILAR_UNAVAILABLE[reason ?? 'line_not_chosen']
}

export function LocationInsightsPanel({
  insights,
  loading,
  error,
}: {
  insights: LocationInsightsData | null
  loading: boolean
  error: unknown
}) {
  /*
   * The radius every label quotes, straight off the response.
   *
   * The fallback used to be the string "500 m". It was unreachable — every use
   * below sits inside the branch where `insights` is non-null — but an
   * unreachable 500 is still a second copy of a number the server owns, and the
   * kind that gets found and "reused" later. Empty string keeps the type a
   * string without asserting a distance nobody measured.
   */
  const radius = insights !== null ? `${insights.radius_m} m` : ''

  return (
    <section
      /*
       * A peer card to the map above it, matching the other cards on this step
       * (white, rounded-2xl, shadow-card) rather than the inset grey block it
       * was as a sub-section of the zoning modal. It is its own thing on the
       * page now, not a footnote to a dialog.
       */
      className="rounded-2xl bg-white px-5 py-4 shadow-card"
      aria-labelledby="location-insights-heading"
    >
      {/*
        * The client's own name for it. It was "Location insights:" with a
        * trailing colon, which read as a label introducing the block beneath it
        * inside the modal; a card heading is a heading and takes no colon.
        */}
      <h3 id="location-insights-heading" className="text-base font-semibold text-ink">
        Business Location Insights
      </h3>

      {loading && (
        <div className="mt-2 space-y-3 py-1" role="status" aria-label="Loading location insights">
          {[0, 1, 2, 3].map((i) => (
            <div key={i} className="flex items-center justify-between gap-6">
              <Skeleton className="h-3.5 w-56" />
              <Skeleton className="h-3.5 w-16" />
            </div>
          ))}
        </div>
      )}

      {/*
       * A failed lookup must never block the filing. These figures are advisory;
       * the zoning clearance does not depend on them, so the panel says it could
       * not load and the step's Next stays enabled.
       *
       * Still true after the move, and more load-bearing than it was. In the
       * modal a failure met an applicant who had already finished the step. Here
       * it meets one mid-decision, staring at a map — so the message has to say
       * the filing is unaffected, or a broken advisory lookup reads as the step
       * itself refusing to go on. Nothing in ApplyWizard's `stepMissing` gate
       * consults `insights`, and nothing should ever be added that does.
       */}
      {!loading && error !== null && (
        <p className="mt-1.5 text-sm text-ink-secondary">
          We couldn&rsquo;t load these figures. They are not part of your application, so you
          can continue.
        </p>
      )}

      {!loading && error === null && insights !== null && (
        <>
          {/*
           * The radius, said once for both rows. It is interpolated from the
           * response, never typed: MapPicker draws its ring from the same
           * `radius_m`, and a hard-coded 500 here could disagree with the
           * circle drawn over the applicant's own street.
           */}
          <p className="mt-1 text-sm text-ink-secondary">Within {radius} of your pin</p>
          <table className="mt-1 w-full border-collapse text-sm">
            <caption className="sr-only">
              Registered businesses near the location you pinned, within {radius}
            </caption>
            <tbody className="divide-y divide-line/70">
              <InsightRow title="Similar Businesses">
                {insights.similar.available && insights.similar.count !== null ? (
                  <span className="tnum">{insights.similar.count}</span>
                ) : (
                  <Unavailable>{similarUnavailableReason(insights.similar.reason)}</Unavailable>
                )}
              </InsightRow>

              <InsightRow
                title="Business Concentration"
                /*
                 * The band scale sits behind the affordance rather than inline:
                 * it is reference material, read once. Both boundaries are read
                 * off `thresholds` — the scale is the server's, and a legend
                 * that disagreed with the banding would look like a bug.
                 */
                info={
                  <InfoNote label="What the Business Concentration bands mean">
                    {`Low 0–${insights.concentration.thresholds.medium_from - 1} · Medium ${
                      insights.concentration.thresholds.medium_from
                    }–${insights.concentration.thresholds.high_from - 1} · High ${
                      insights.concentration.thresholds.high_from
                    }+`}
                  </InfoNote>
                }
              >
                <span
                  className={`inline-flex items-center gap-1.5 rounded-md px-2.5 py-0.5 text-sm font-semibold ${
                    BAND_CLASS[insights.concentration.band]
                  }`}
                >
                  {BAND_LABEL[insights.concentration.band]}
                  {/* Named, not bare: "(12)" alone does not say twelve of what. */}
                  <span className="tnum font-normal">
                    ({insights.concentration.count} registered)
                  </span>
                </span>
              </InsightRow>
            </tbody>
          </table>

          {/*
           * "These figures are not part of the zoning decision." stood here and
           * was removed on the client's instruction (checklist item 112). It
           * guarded one confusion: figures inside a dialog headed
           * CONGRATULATIONS, where anything on screen reads as the verdict.
           *
           * That dialog is gone (23 September 2026). The zoning answer is now
           * ZoningConformanceNote beside this panel, which words itself as a
           * lookup and names CPDO as the decider, and BarangayZoningMap says
           * "CPDO confirms what applies to your exact location". So the step
           * still names who decides, twice, and the sentence stays out.
           *
           * It has to come back if EITHER:
           *   - this panel is rendered on any surface that announces a
           *     conformity outcome without saying CPDO decides; or
           *   - the step stops naming CPDO as the office that determines the
           *     clearance (both lines above removed).
           */}
        </>
      )}
    </section>
  )
}

/**
 * What the ordinance lists for this barangay's zones, as the applicant types.
 *
 * ── Why this is not a verdict, and does not look like one ──────────────────
 *
 * The client asked for the conforming / non-conforming message to appear live
 * rather than after Next (checklist item 20). It now does, and it is anchored
 * to the 695 uses read off City Ordinance 24-2018 instead of to the
 * `?zoning=deny` query parameter it used to come from.
 *
 * It is still a LOOKUP. `ZoningConformance` in the API sets out the four
 * reasons the ordinance cannot be automated into an answer — an empty Fishpond
 * section, broken inheritance chains, the same activity carrying different
 * conditions in different zones, and conditionality buried in "provided that"
 * prose — and Annex A makes the enumeration explicitly open, so absence from
 * the list is not prohibition. Every word below is chosen so that a reader who
 * takes it at face value is not misled: the ordinance *lists*, or it does not,
 * and CPDO decides either way.
 *
 * `matched_use` is quoted rather than summarised on purpose. The match is a
 * text heuristic and it can be wrong — a dairy MANUFACTURER can match a clause
 * about dairy SHOPS — so the clause is put in front of the applicant, who knows
 * their own trade and can see the mismatch. A bare "conforming" would hide it.
 *
 * ── Colour ────────────────────────────────────────────────────────────────
 *
 * `not_listed` is amber, never `#bd0000`. It is not an error and not a refusal:
 * the applicant has done nothing wrong and the filing is not blocked. Red here
 * would say "stop", which is the one thing this screen has no authority to say
 * (DESIGN.md, Red Means Stop). Tone never carries the meaning alone — the
 * sentence says it in words in all three states.
 */
export function ZoningConformanceNote({
  zoning,
  barangayName,
}: {
  zoning: LocationInsightsData['zoning'] | null
  barangayName: string | null
}) {
  if (!zoning || zoning.verdict === 'undetermined') return null

  const listed = zoning.verdict === 'listed'
  const matched = zoning.zones.find((z) => z.listed)
  const where = barangayName ?? 'this barangay'

  return (
    <section
      /*
       * Announced, not only drawn. The verdict changes under the applicant's
       * hands as they change barangay or trade, and a change nobody is told
       * about is a change a screen-reader user never learns happened.
       */
      aria-live="polite"
      className={`rounded-xl border p-4 ${
        listed ? 'border-royal/30 bg-royal/5' : 'border-s-yellow bg-s-yellow/10'
      }`}
    >
      <p className="text-sm font-semibold text-ink">
        {listed
          ? `The zoning ordinance lists this use for ${where}.`
          : `This use is not on the ordinance’s list for ${where}.`}
      </p>

      {listed && matched?.matched_use && (
        /*
         * The clause, cut to its first breath. The ordinance writes its uses as
         * single sentences that run for a paragraph — R-1's home-occupation
         * entry is 600 characters of provisos — and quoted whole it was the
         * largest block on the step, which buried the figures above it and the
         * address fields beside it. `title` keeps the full text one hover away
         * for anyone who wants to read the provisos, and the clause is still
         * verbatim up to the cut, so it cannot mislead by paraphrase.
         */
        <p className="mt-1.5 text-sm text-ink-secondary" title={matched.matched_use}>
          <span className="font-semibold">{matched.name}</span> — “{firstClause(matched.matched_use)}”
        </p>
      )}

      {!listed && zoning.zones.length > 0 && (
        <p className="mt-1.5 text-sm text-ink-secondary">
          {where} is zoned {zoning.zones.map((z) => z.name).join(', ')}. A use not on the list is
          not automatically refused.
        </p>
      )}

      {/* The one line that keeps a lookup from reading as a clearance. Do not trim it. */}
      <p className="mt-2 text-sm text-ink-muted">
        The Zoning Office (CPDO) makes the final determination on your locational clearance.
      </p>
    </section>
  )
}

/**
 * The head of an ordinance clause — everything before its first proviso.
 *
 * The ordinance's uses are single sentences carrying their conditions inline
 * ("…, provided that the number of persons engaged shall not exceed five (5),
 * inclusive of owner; there shall be no change in…"). The head names the
 * activity, which is what the applicant is checking; the tail is CPDO's to
 * apply. Cut at the first proviso marker, then hard-capped, then trimmed back
 * to a word boundary so the quote never ends mid-word.
 */
function firstClause(use: string): string {
  const head = use.split(/,?\s*provided\s+that\b|;/i)[0]?.trim() ?? use
  if (head.length <= 150) return head === use ? head : `${head}…`
  const cut = head.slice(0, 150)
  return `${cut.slice(0, cut.lastIndexOf(' '))}…`
}
