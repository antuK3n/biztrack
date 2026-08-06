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
 * ## On the wording (checklist item 68: "remove descriptions that sound AI")
 *
 * Each row is a Title with a Description under it, both dictated by the client
 * down to the capitalisation. Titles are Title Case noun phrases and carry no
 * full stop, because they are the left-hand column of a table and not sentences;
 * descriptions say what the figure beside them actually counted, because a
 * figure whose subject is unstated is not information.
 *
 * The trailing qualifiers went long ago: "in the area", "operating nearby" and
 * "of nearby similar businesses" all restated the radius the row above already
 * gives, and stacked restatement is exactly what reads as machine-written
 * padding.
 *
 * ## The row that is deliberately absent — do not re-add it
 *
 * There was briefly a fifth row, "Businesses in your own category", fed by a
 * `your_line` key on the payload. It existed because the first and third rows
 * count on DIFFERENT widths of PSIC and did not say so: "Nearby Similar
 * Businesses" matches the applicant's 3-digit trade GROUP, while "Most Common
 * Line of Business" takes the mode of the 2-digit DIVISION. Both are correct —
 * widening "similar" to the division would make a coffee shop similar to a
 * canteen — but as adjacent rows of one table they invited arithmetic that does
 * not hold. A dairy applicant met "Similar: 0" above "Most common:
 * Manufacturing, 6 of 33" and filed a bug against a count that was right.
 *
 * The client has since decided against that third figure and asked for it
 * removed. The distinction is now carried by the two titles themselves —
 * "Nearby Similar" versus "Most Common Line of Business" — which is a
 * legitimate way to draw it and their call to make.
 *
 * So this is a decision, not an omission. If the confusion is reported again the
 * answer is wording on those two rows, not a third count; re-adding it would be
 * re-opening something the client has already ruled on. Same note sits on
 * LocationInsights.php, where the payload key was removed.
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
     * Nothing renders it now. The first row's description is fixed copy the
     * client specified — "Similar businesses within {radius}" — where it used to
     * name this title and then admit the count reached past it to the whole
     * 3-digit group. Kept on the type because it is genuinely on the wire and a
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
}

export interface LocationInsightsQuery {
  latitude: number
  longitude: number
  psicCodeId: number | null
  businessId: number | null
}

/** Reads the insights for one pinned point. Computed per request — see LocationInsights.php. */
export function useLocationInsights(query: LocationInsightsQuery | null) {
  const { latitude, longitude, psicCodeId, businessId } = query ?? {
    latitude: 0,
    longitude: 0,
    psicCodeId: null,
    businessId: null,
  }

  return useAsync<LocationInsightsData | null>(async () => {
    if (query === null) return null
    const { data } = await api.get<{ data: LocationInsightsData }>('location-insights', {
      params: {
        latitude,
        longitude,
        ...(psicCodeId !== null ? { psic_code_id: psicCodeId } : {}),
        ...(businessId !== null ? { business_id: businessId } : {}),
      },
    })
    return data.data
  }, [query === null, latitude, longitude, psicCodeId, businessId])
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
 * One row: a Title, the Description that says what the figure beside it
 * measured, and optionally an info affordance sitting on the title.
 *
 * The structure is the client's — every row is a titled thing with a sentence
 * under it, rather than the single run-on label this used to be. The title is
 * the name of the figure; the description is the definition of it.
 */
function InsightRow({
  title,
  description,
  info,
  children,
}: {
  title: string
  description?: string
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
            {description && (
              <span className="mt-0.5 block text-xs font-normal text-ink-muted">{description}</span>
            )}
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
          className="absolute left-0 top-6 z-20 block w-64 max-w-[min(16rem,calc(100vw-2rem))] cursor-default rounded-lg border border-line bg-white p-3 text-left text-xs font-normal leading-relaxed text-ink-secondary shadow-lg"
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
      <span className="text-xs">{children}</span>
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
          <table className="mt-1 w-full border-collapse text-sm">
            <caption className="sr-only">
              Registered businesses near the location you pinned, within {radius}
            </caption>
            <tbody className="divide-y divide-line/70">
              <InsightRow
                title="Nearby Similar Businesses"
                /*
                 * The radius is interpolated, never typed. `radius_m` comes off
                 * the response and MapPicker draws its ring from the same
                 * number, so a hard-coded 500 here would be a second copy that
                 * can disagree with the circle drawn over the applicant's own
                 * street — worse than saying nothing.
                 *
                 * The description is fixed copy the client specified. It
                 * previously named the applicant's own 5-digit sub-class
                 * (`psic_title`) and then admitted the count reached past it to
                 * the whole 3-digit group, because those really are different
                 * sets: an applicant filing 56101 "Restaurants and carinderia"
                 * also matches a fast-food outlet in group 561, and 21 of the
                 * 135 reference codes sit in a group with siblings. The title
                 * now carries that width instead — "Similar", against "Line of
                 * Business" three rows down.
                 */
                description={`Similar businesses within ${radius}`}
              >
                {insights.similar.available && insights.similar.count !== null ? (
                  <span className="tnum">{insights.similar.count}</span>
                ) : (
                  <Unavailable>{similarUnavailableReason(insights.similar.reason)}</Unavailable>
                )}
              </InsightRow>

              <InsightRow
                title="Business Concentration"
                description="Registered businesses in total"
                /*
                 * The band scale used to sit inline, stapled onto the end of
                 * this row's note: "Within 500 m · Low 0–5 · Medium 6–10 · High
                 * 11+". It is reference material — read once, then never again —
                 * and inline it competed for attention with the description
                 * every time the panel rendered. Behind the affordance it is
                 * still one keystroke or one tap away.
                 *
                 * Both boundaries are read off `thresholds` rather than typed.
                 * The scale is the server's; a legend that disagreed with the
                 * banding would send an applicant looking for a bug in the
                 * count.
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
                  className={`inline-flex items-center gap-1.5 rounded-md px-2.5 py-0.5 text-xs font-semibold ${
                    BAND_CLASS[insights.concentration.band]
                  }`}
                >
                  {BAND_LABEL[insights.concentration.band]}
                  <span className="tnum font-normal">({insights.concentration.count})</span>
                </span>
              </InsightRow>

              <InsightRow
                title="Most Common Line of Business"
                /*
                 * "15 of 48" on its own said nothing — fifteen of forty-eight
                 * what? Both numbers need naming: how many nearby businesses
                 * are in this trade, out of how many are nearby at all.
                 *
                 * Withheld entirely when there is nothing nearby, rather than
                 * rendered as "0 of the 0 businesses near this pin". The figure
                 * beside it already says none are registered in range, and a
                 * description of a count that does not exist is noise.
                 */
                description={
                  insights.common_type.available
                    ? `${insights.common_type.count} of the ${insights.common_type.of_total} businesses near this pin`
                    : undefined
                }
              >
                {insights.common_type.available ? (
                  insights.common_type.category
                ) : (
                  <Unavailable>{`none registered within ${radius}`}</Unavailable>
                )}
              </InsightRow>

              {/*
               * A fifth row, "Businesses in your own category", sat here and was
               * removed on the client's instruction. It is not missing by
               * accident — see the module docblock before re-adding it.
               */}

              <InsightRow
                title="Average Distance to Similar Businesses"
                /*
                 * This description says what the figure IS. It used to say what
                 * it is not — "Straight line, not walking distance" — which the
                 * client rejected as vague, and they were right: a caveat is
                 * only meaningful to a reader who already knows what was
                 * measured, and it named neither the set averaged over nor the
                 * fact that it is an average at all.
                 *
                 * Both facts it does carry are load-bearing and neither may be
                 * dropped for brevity:
                 *
                 *   "Average"       — one number standing for several distances,
                 *                     not the distance to the nearest one.
                 *   "straight-line" — this is a haversine over the direct
                 *                     point-to-point distance, not a route. An
                 *                     applicant who reads "320 m" and walks it
                 *                     will find it further, and around a river
                 *                     or a closed block considerably further.
                 *                     That is the whole reason the old note
                 *                     existed and it survives the rewrite.
                 *
                 * "each similar business" names the set explicitly, which is the
                 * same set the first row counts — so the two rows are visibly
                 * about one thing and the reader does not have to guess whether
                 * this averages over every neighbour.
                 */
                description={
                  insights.similar.average_distance_m !== null
                    ? 'Average straight-line distance from your pin to each similar business'
                    : undefined
                }
              >
                {insights.similar.average_distance_m !== null ? (
                  <span className="tnum">{insights.similar.average_distance_m} m</span>
                ) : (
                  <Unavailable>
                    {insights.similar.available
                      ? 'none in range'
                      : similarUnavailableReason(insights.similar.reason)}
                  </Unavailable>
                )}
              </InsightRow>
            </tbody>
          </table>

          {/*
           * "These figures are not part of the zoning decision." stood here and
           * was removed on the client's instruction (checklist item 112). It
           * STAYS removed after the move out of the zoning modal, and the
           * reasoning is worth writing down because the old note left a trigger
           * condition that a reader could easily think has just fired.
           *
           * The sentence guarded one specific confusion: four confident numbers
           * sitting inside a dialog headed CONGRATULATIONS, where anything on
           * screen reads as part of the conformity finding. The old note said it
           * had to come back "if the CPDO line ever leaves that modal".
           *
           * That condition has not fired, in either direction. The CPDO line is
           * still in the modal, untouched. What left the modal is this panel —
           * and it left TOWARDS safety, not away from it. There is no verdict on
           * the map step at all: the step is location capture, its own intro says
           * CPDO evaluates the zoning clearance during processing, and the
           * caption under the map says CPDO checks the actual site. The panel is
           * now further from a conformity claim than the disclaimer ever put it,
           * and next to two sentences that already name who decides.
           *
           * So the trigger condition is restated for where the panel actually
           * lives now. This sentence has to come back if EITHER:
           *   - this panel is ever rendered inside the zoning-result modal, or
           *     any other surface that announces a conformity outcome; or
           *   - the Location & Zoning step stops naming CPDO as the office that
           *     determines the clearance.
           * Either would leave four authoritative-looking figures beside an
           * apparent verdict with nothing saying they are not it.
           */}
        </>
      )}
    </section>
  )
}
