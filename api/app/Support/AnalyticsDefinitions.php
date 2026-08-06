<?php

namespace App\Support;

/**
 * What each figure on an analytics screen actually measures, and why it is there.
 *
 * These travel in `meta.definitions` beside `meta.engine` and `meta.computed_at`,
 * never inside `data`. The reason is the one AnalyticsController already states
 * about provenance: `data` is exactly the payload the engine returned, and how a
 * figure was derived is not one of the figures. Putting definitions in `data`
 * would also mean R had to emit them, which would fork the wording across two
 * implementations — the precise drift the parity test exists to prevent.
 *
 * They live in PHP rather than in the React screens because a sentence typed into
 * a component is a copy of the truth, not the truth. Change a `where` clause in
 * DashboardAnalytics and a frontend string describing it goes stale silently, on a
 * screen whose entire purpose is to be trusted. AnalyticsDefinitionsTest walks
 * every key here and fails if it no longer resolves against a built payload, so a
 * renamed metric breaks the build instead of shipping a confident lie.
 *
 * Each entry answers four questions, and the fourth is not decoration. The
 * professor's cross-cutting requirement 0.1 (docs/r-integration-revisions.md) is
 * that every data element be justified — "state why it is there, who uses it, how
 * they use it" — and she flagged it as the question most likely to be asked. A
 * formula alone answers how, and leaves why unanswered:
 *
 *   label    the name as printed on screen
 *   formula  how the number is produced, saying explicitly what is divided by what
 *   covers   which rows it is over: the window, and what is left out
 *   why      what decision it informs, and who makes that decision
 *
 * `covers` is where the honesty lives. A rate whose exclusions are unstated reads
 * as a rate over everything, and several of these are not: the approval rate omits
 * pending filings, the inspection pass rate divides by completed rather than
 * scheduled, and the rankings are shares of the subset that has the field on
 * record at all. Every one of those omissions is defensible and none of them is
 * self-evident from the number.
 *
 * ── HOW SHORT, AND WHY ──────────────────────────────────────────────────────
 *
 * These are read inside a 320px popover that opens on top of the chart it
 * explains. The client's verdict on the previous draft was that the screens are
 * "overwhelming" and that every one of these must be "straightforward and simple
 * to understand"; the screenshot they sent was a Business Growth popover running
 * past a hundred words and covering the curve underneath it. So:
 *
 *   - The FIRST sentence says what the number is. Nothing qualifies it yet.
 *   - One idea per sentence. No "which is why" chains, no double negatives.
 *   - Short common words: "average" not "mean", "the middle wait" not "the
 *     median", "what it is divided by" not "the denominator".
 *   - Anything a neighbouring on-screen label already says is deleted here.
 *     Repeating it is most of what made the screens feel heavy.
 *
 * WRITE THESE FOR A BPLO CLERK, not for a statistician. Where a term is genuinely
 * the name of the thing — PSIC code, RA 11032 tier — keep the term and gloss it
 * in the same sentence rather than dropping either the word or its meaning. Where
 * it is the name of a METHOD rather than of the thing — Kaplan-Meier, censoring,
 * cohort — say what it does instead. The reader has to act on the figure, not
 * reproduce it.
 *
 * Plainer must never become looser. Every window, table and exclusion these
 * sentences name is a claim the reader can check, and shortening is not licence
 * to drop one — nor to upgrade a rule score into something the register cannot
 * support. See the renewalRisk() docblock for where that line sits.
 */
final class AnalyticsDefinitions
{
    /**
     * @return array<string, array{label: string, formula: string, covers: string, why: string}>
     */
    public static function for(string $dataset): array
    {
        return match ($dataset) {
            AnalyticsDatasets::DASHBOARD => self::dashboard(),
            AnalyticsDatasets::PROCESSING_TIME => self::processingTime(),
            AnalyticsDatasets::RENEWAL_RISK => self::renewalRisk(),
            AnalyticsDatasets::RENEWAL_MODEL => self::renewalModel(),
            AnalyticsDatasets::BUSINESS_GROWTH => self::businessGrowth(),
            default => [],
        };
    }

    /**
     * Keys are dot paths into the dashboard payload.
     *
     * Panels are defined once at the panel level rather than once per cell. A
     * count in a table ("New: 12") needs no formula; what the reader cannot see is
     * the window it counts over and what it drops, and that is a property of the
     * panel. Rates and derived figures get their own entry because those are the
     * ones where the denominator is the whole question.
     *
     * ── THE LABELS ARE THE PAPER'S TEN REPORT NAMES ─────────────────────────
     *
     * The client's instruction is to "follow the terms mentioned in the paper",
     * and the paper's §1 table names each report exactly once. A `label` here is
     * the name the info button announces ("How {label} is measured"), so a label
     * that drifts from the heading above it gives one figure two names. Every
     * label below that appears in that table is spelled as the table spells it.
     *
     * @return array<string, array{label: string, formula: string, covers: string, why: string}>
     */
    private static function dashboard(): array
    {
        return [
            'kpis.active_businesses' => [
                'label' => 'Active Businesses',
                'formula' => 'Businesses holding at least one permit still in force today. A business counts once, however many permits it holds.',
                'covers' => 'The register as it stands today, not the months set by the filter. Businesses removed from the register are left out.',
                'why' => 'How many businesses the city regulates right now. Most percentages on this screen are a slice of this number.',
            ],

            /*
             * The key still says ytd; the figure has not been year-to-date
             * since the client asked for "the full term" instead. The key is a
             * wire name R echoes verbatim — see DashboardAnalytics::kpiFacts().
             * Every word a reader sees is about the whole register.
             */
            'kpis.applications_ytd' => [
                'label' => 'Applications (all time)',
                'formula' => 'Every filing on record, counted from creation.',
                'covers' => 'The whole register — not this calendar year, and not the months set by the filter. Drafts nobody submitted are included; filings removed from the register are left out.',
                'why' => 'The full-term workload figure: everything the office has ever been asked to process. It sits beside This Month so the total and the current load can be read together.',
            ],

            'kpis.applications_this_month' => [
                'label' => 'This Month',
                'formula' => 'Filings created since the first day of this month.',
                'covers' => 'A part month until the month ends. On the 3rd this is three days of filings, not a monthly rate.',
                'why' => 'Current load, for staffing the counter this week.',
            ],

            'kpis.compliance_rate' => [
                'label' => 'Compliance Rate',
                'formula' => 'Businesses holding a valid permit of every type they have been issued ÷ businesses ever issued a permit × 100.',
                'covers' => 'Businesses with permit history. One that has never held a permit is left out of both sides.',
                'why' => 'The one number leadership asks for. The Business Permit Compliance card below shows the same rate with its counts.',
            ],

            'volume' => [
                'label' => 'Application Volume',
                'formula' => 'Filings this month by transaction type: new, renewal, amendment. Total is the sum of the three.',
                'covers' => 'This calendar month, counted from creation. All three types are shown even at zero, so an empty row means none were filed.',
                'why' => 'Shows what kind of work is arriving, not just how much. Renewal season and new-registration season staff differently.',
            ],

            'decisions.approval_rate' => [
                'label' => 'Approval rate',
                'formula' => 'Approved filings ÷ decided filings (approved + returned + rejected) × 100.',
                'covers' => 'Decided filings only. Pending and cancelled filings are left out — a withdrawn filing is not a decision the office made.',
                'why' => 'Measures how the office decides, not how fast. Leaving pending filings out is why a growing backlog does not move it.',
            ],

            'processing_tiers' => [
                // The paper's §1 term, exactly: "Average Processing Time (RA
                // 11032)". It replaces "Average Processing Time for (RA 11032)
                // Tier", which said "tier" twice over — once in the heading and
                // again in every bar label underneath it.
                'label' => 'Average Processing Time (RA 11032)',
                'formula' => 'Average working days from submission to decision, per complexity tier, against that tier\'s RA 11032 limit: 3 days simple, 7 complex, 20 highly technical.',
                'covers' => 'Decided filings in the months set by the filter that record a tier, a submission and a decision. Working days skip weekends. Holidays are not allowed for, so a real turnaround is never faster than shown.',
                'why' => 'RA 11032 sets a legal deadline, not an office target: going over it breaks the law. The limit here is the statutory one, never the flat deadline this system stamps on a filing — that field does not change with the tier.',
            ],

            'stages' => [
                // The paper reads "Average Processing Time by Department", and so
                // did the heading on screen; this label did not. Both now say the
                // same thing. The adviser's note here (§1.4) was to get rid of
                // "Time-in-Stage", which stays gone.
                'label' => 'Average Processing Time by Department',
                'formula' => 'Average days from a review reaching an office to that office finishing it.',
                'covers' => 'Reviews finished in the months set by the filter. An open review has no finish time and is left out, so an office that finishes nothing looks fast. Read this beside the review counts.',
                'why' => 'A permit waits on six offices in turn, so the slowest sets the total. This says which office to give people to.',
            ],

            'stages.bottleneck' => [
                'label' => 'Slowest department',
                'formula' => 'The department with the highest average, with how far above the all-department average it sits and what share of reviews it handled.',
                'covers' => 'The same finished reviews as the panel above.',
                'why' => 'Slowest can mean hardest or busiest. The share of reviews sits beside it so the two can be told apart before anyone is reassigned.',
            ],

            'compliance.ra11032_processing' => [
                'label' => 'Processing Rate Compliance to RA 11032',
                'formula' => 'Filings decided inside the legal deadline for their own tier ÷ decided filings that record a tier × 100.',
                'covers' => 'The months set by the filter. Each filing is judged against its own tier, so a 20-day highly technical decision passes where a 20-day simple one fails.',
                'why' => 'The pass rate against the law. It counts filings, so it cannot be averaged with the two cards beside it.',
            ],

            'compliance.permit_validity' => [
                'label' => 'Business Permit Compliance',
                'formula' => 'Businesses holding a valid permit of every type they have been issued ÷ businesses ever issued a permit × 100.',
                'covers' => 'Every business with permit history. The test is per type: a current sanitary permit with a lapsed fire clearance still counts as non-compliant.',
                'why' => 'How much of the register is covered right now. This counts businesses; the card beside it counts filings.',
            ],

            'compliance.renewal' => [
                'label' => 'Renewal Compliance',
                'formula' => 'Permits that fell due and had a renewal filed before expiry ÷ permits that fell due × 100.',
                'covers' => 'Permits expiring in the months set by the filter, for the types renewals are actually filed against. A draft is not a renewal; it has to be submitted.',
                'why' => 'Whether businesses renew before lapsing. When too few renewals record which permit they replace it says it cannot be computed rather than 0%, because a gap in the register is not proof that nobody renewed.',
            ],

            /*
             * There is deliberately no 'expiry' entry, and the dashboard payload
             * still carries an `expiry` panel.
             *
             * The client moved "Permits Approaching Expiry" to Renewal Risk
             * Prediction, where the officer who works that list already is, and
             * asked for its first column to become four named states rather than
             * three overlapping time windows. The panel is gone from this screen
             * and its definition went with it — a definition for a figure nobody
             * can see is the stalest kind.
             *
             * The KEY could not go. DashboardAnalytics::computeExpiry() is one
             * half of a two-engine contract: r/R/service.R computes `expiry` in
             * .dash_expiry() from `permit_type_columns`, `expiring_permits` and
             * `expiry_windows`, and AnalyticsParityTest compares the two key sets
             * in both directions. Dropping it from PHP alone would fail parity as
             * "present in R, absent from PHP"; dropping it from both is an R
             * change, which this one was not allowed to be. So the payload key
             * stays, unread by any screen, and this comment is why.
             *
             * AnalyticsDefinitionsTest's panel list drops `expiry` to match. Do
             * not add it back without a screen to put it on.
             */

            'top_barangays' => [
                'label' => 'Top Five Barangays by Active Businesses',
                'formula' => 'Active businesses per barangay, ranked, with each barangay\'s share of the total.',
                'covers' => 'Only active businesses with a barangay on record. Five are listed, so the shares do not add up to 100.',
                'why' => 'Where commercial activity sits, for siting inspections and for the location insight an applicant sees when picking an address.',
            ],

            'top_lines_of_business' => [
                /*
                 * ADVISER OVERRIDE, RECORDED — do not "fix" this back.
                 *
                 * docs/r-integration-revisions.md §1.8 is the adviser asking for
                 * "Top 5" by name: "Eh 'Top Lines' eh. Dapat Top 5." Her reason
                 * was arithmetic rather than style — the shares shown (6.8 + 5.7
                 * + 5.7 + 5.5 + 5.3) do not sum to 100, and a title claiming to
                 * rank every category while showing five is what makes that look
                 * like a bug.
                 *
                 * The client was shown the conflict and chose the paper's term,
                 * which spells the same number as a word: "Top Five Business
                 * Categories". The substance she asked for survives — the count
                 * is still in the title, and `covers` still says the shares do
                 * not reach 100. Only the digit became a word.
                 */
                'label' => 'Top Five Business Categories',
                'formula' => 'Active businesses per category, ranked, with each category\'s share of the total. Categories are PSIC codes — the national numbering for industries.',
                'covers' => 'Each business counts under its main category only. Shares are of active businesses with a category on record, and five are listed, so they do not add up to 100.',
                'why' => 'What kind of city this is, in the register\'s own classification.',
            ],

            'organization_forms' => [
                'label' => 'Form of Organization',
                'formula' => 'Registered businesses by legal form — sole proprietorship, corporation, partnership, cooperative — each as a share of those recorded.',
                'covers' => 'Businesses with no form on file are counted separately and left out of the shares.',
                'why' => 'Legal form decides which documents a filing needs. The unrecorded count sits beside the shares so a near-empty field cannot read as a real split.',
            ],

            'inspections.pass_rate' => [
                'label' => 'Pass rate',
                'formula' => 'Inspections passed ÷ inspections completed × 100.',
                'covers' => 'Completed inspections only, never the ones merely scheduled. One not yet carried out has no result, and counting it as a fail would punish an office for its own backlog.',
                'why' => 'Passed, failed and conditional will not add up to the scheduled count, and this is why. The gap between scheduled and completed is the backlog.',
            ],

            'officer_activity.mean_response_hours' => [
                'label' => 'Response time',
                'formula' => 'Average hours from an unanswered applicant message to the next reply from an officer.',
                'covers' => 'Replies sent in the months set by the filter. Only the first unanswered message starts the clock, so three follow-ups are one wait. Conversations still waiting are counted separately.',
                'why' => 'How long an applicant waits to be spoken to. The middle wait and the number still waiting sit beside it, because an average hides both long waits and unanswered questions.',
            ],

            'officer_activity.requests_fulfilled_rate' => [
                'label' => 'Requests fulfilled',
                'formula' => 'Requests marked fulfilled ÷ requests raised × 100.',
                'covers' => 'Requests raised in the months set by the filter. One still open counts against the rate, because it is what is holding the filing.',
                'why' => 'Whether asking an applicant for something actually closes. Many raised and few fulfilled means filings are stalling on paperwork rather than on review.',
            ],

            /*
             * There is deliberately no 'officer_activity.meetings_attended_rate'
             * entry here, and the paper asks for one.
             *
             * The client's paper lists a third Officer Activity figure, "meeting
             * participation". BizTrack has no meetings feature — nothing in the
             * product schedules one, and nothing records attendance — so the
             * figure described nothing an officer had done, and the card has
             * been removed from the dashboard. The reasoning in full is in
             * OfficerPanel, web/src/pages/admin/AnalyticsPage.tsx.
             *
             * A definition is the sentence a reader is handed when they ask what
             * a number in front of them means. With no number in front of them
             * there is nothing to explain, and shipping the explanation anyway
             * would keep "meeting participation" travelling to the client in
             * meta.definitions after the screen had stopped saying it. Restore
             * this entry only alongside the card.
             */

            'map' => [
                'label' => 'Business locations',
                'formula' => 'Business locations plotted from recorded coordinates, marked by whether the business holds a valid permit today.',
                'covers' => 'Only businesses with coordinates on record, which is fewer than the register holds — the plotted count, the mapped count and the register total are all shown. Past a fixed cap the rest are counted in a note instead of drawn.',
                'why' => 'Turns the barangay ranking into something that can be walked. Lapsed permits are drawn rather than hidden, since a cluster of them is the pattern worth seeing.',
            ],
        ];
    }

    /**
     * Keys are dot paths into the processing-time payload.
     *
     * This screen is a control chart, and a control chart is the one analytics
     * shape whose vocabulary the reader is least likely to share. Revision 6.1
     * (docs/r-integration-revisions.md) is a question about exactly that —
     * "ito bang processing ay processed? o processing time is from the
     * application until the process?" — and 6.2 struck through the word
     * "Inside" on the status pill. So the two things these entries owe the
     * reader before anything else are which two timestamps the clock runs
     * between, and which direction on the chart is the good one.
     *
     * @return array<string, array{label: string, formula: string, covers: string, why: string}>
     */
    private static function processingTime(): array
    {
        return [
            'departments' => [
                'label' => 'Department Processing Time Chart',
                'formula' => 'For each week, the average days a department took on the reviews it finished that week. The clock starts when a review reaches the office and stops when that office finishes it. The centre line and the normal range are fixed from the first 24 weeks, so every later week is measured against the same yardstick.',
                'covers' => 'Finished reviews only; open ones are left out. Days are ordinary calendar days, weekends included. A week with fewer than three finished reviews is left off the chart, so the chart covers fewer reviews than the window holds.',
                'why' => 'Lower is better: this is time an applicant spends waiting at one desk. The range comes from the earliest weeks so a recent slowdown cannot widen the range meant to catch it.',
            ],

            'departments.status' => [
                'label' => 'Process Status Indicator',
                'formula' => 'Whether the latest week sat inside this department\'s normal range or outside it.',
                'covers' => 'The latest week only, judged against this department\'s own past. No status means the week is not yet classified, which is not the same as being fine. Earlier weeks are in the flagged list.',
                'why' => 'Outside does not mean a rule was broken. It means this week did not look like this department\'s usual pace, which is a reason to ask why. A holiday backlog and a real breakdown look identical here.',
            ],

            'departments.flagged' => [
                'label' => 'Flagged Weeks',
                'formula' => 'Weeks whose average sat outside the normal range, with how far above or below the centre line each landed.',
                'covers' => 'Charted weeks only. A week left off for having fewer than three finished reviews never appears here, however slow it was.',
                'why' => 'One odd week is usually chance; three in a quarter is a pattern. The dates let the office match a slow stretch to a staff absence, an outage or a surge.',
            ],

            'departments.trend' => [
                'label' => 'Gradual Slowdown Detector',
                'formula' => 'A running average that counts the newest week most and older weeks less, compared with the chart\'s centre line. It reads as rising or easing once it has moved more than half way from the centre to the edge of the range.',
                'covers' => 'The same weeks as the chart. Bar length is the size of the move either way, so fast improvement and fast decline both draw a long bar. The word beside it says which.',
                'why' => 'A slide of half a day a week never crosses the edge of the range, but over a quarter it adds a week of waiting. This catches the drift no single week triggers.',
            ],

            'completed_reviews' => [
                'label' => 'Completed reviews',
                'formula' => 'Every departmental review finished inside the window.',
                'covers' => 'All finished reviews, including weeks with too few to chart. It is larger than the number the chart draws.',
                'why' => 'How much work the screen rests on. A chart drawn from a few dozen reviews describes those reviews, not the office.',
            ],
        ];
    }

    /**
     * Keys are dot paths into the renewal-risk payload.
     *
     * Read the honesty constraint in docs/r-integration-spec.md before editing
     * a word of this. The paper and the mockup both described this column as an
     * "Estimated Probability of Delayed Renewal" and printed percentages
     * against it. No model exists: nothing here is fitted on historical
     * outcomes, there is no outcome variable, and there is no calibration to
     * report. What exists is a weighted rule score whose every rule is printed
     * on the same screen.
     *
     * So these entries may not call the score a probability, a prediction, a
     * likelihood or a confidence, and may not render it as a percentage. The
     * risk is concrete rather than academic: an officer who reads "88%" as
     * calibrated will act on it as calibrated, and the register cannot support
     * that. The score's only claim is ordinal — it sorts, it does not forecast.
     *
     * This is also the one section where SHORTENING can break the constraint.
     * "How likely a business is to renew late" is shorter than the honest
     * sentence and is exactly the claim the score cannot make.
     * AnalyticsDefinitionsTest bans the vocabulary, but no test can ban a
     * paraphrase. Where brevity and honesty pull apart here, honesty wins and
     * the sentence stays long — which is why `at_risk.score` is the longest
     * entry in this file.
     *
     * @return array<string, array{label: string, formula: string, covers: string, why: string}>
     */
    private static function renewalRisk(): array
    {
        return [
            'at_risk' => [
                'label' => 'Businesses Requiring Review',
                'formula' => 'Permits falling due in the period set by the filter, scored against five rules and listed worst first. Ties go to the earlier expiry.',
                'covers' => 'Active and expired permits whose cover ends between 60 days ago and the end of the period — a permit that quietly expired last month is the case most worth chasing. Revoked and suspended permits are left out. The band counts below cover every permit scored, not only the rows listed.',
                'why' => 'The follow-up list. Ordered by score rather than by date, so a permit expiring in a fortnight with nothing filed and fees outstanding comes before one expiring next week whose renewal is already approved.',
            ],

            'at_risk.score' => [
                'label' => 'Renewal Risk Index',
                /*
                 * The client's own wording, near enough verbatim, with one
                 * substitution that is worth explaining because it looks like
                 * an edit for its own sake.
                 *
                 * They wrote: "A higher score means more warning signs — it is
                 * not a prediction, and it does not say how likely a renewal is
                 * to be late." That is exactly the claim this screen must make,
                 * and it CANNOT be written that way: AnalyticsDefinitionsTest
                 * fails the build on the substring `predict`, and `prediction`
                 * contains it. The guard cannot tell a denial from an
                 * assertion, so the sentence denying the forecast trips the test
                 * that exists to stop the forecast being claimed.
                 *
                 * Weakening the guard to allow negated forms would be the wrong
                 * trade — it is a blunt instrument on purpose, and every draft
                 * of this text that has drifted toward a probability claim was
                 * caught by exactly that bluntness. So the meaning is kept and
                 * the banned stem is not used: "it does not say what any one
                 * business will do next" says the same thing.
                 *
                 * `likely` is deliberately still allowed by the test as
                 * ordinary English; `likelihood` is not. Do not reach for
                 * either here — the sentence does not need them.
                 */
                'formula' => 'Each permit is checked against five things: how soon it expires, whether a renewal has been filed, whether this business has renewed late before, open compliance findings, and unpaid fees. Each adds points, up to 100 — a score, not a percentage.',
                'covers' => 'A higher score means more warning signs. Nothing here is fitted to past outcomes: the register never records whether a business ended up renewing late, so there is no past result to work from and no accuracy figure to quote. The number sorts a queue. It does not say what any one business will do next.',
                'why' => 'A score lets a hundred permits be worked in the order that matters instead of by date alone. Every rule and its points are printed below the table, so an officer can disagree with the ranking on the merits.',
            ],

            'at_risk.drivers' => [
                'label' => 'Why this permit is listed',
                'formula' => 'The rules that scored above zero for this permit, heaviest first.',
                'covers' => 'Only the top few are shown. Two rules are deliberately gentle: a business in its first renewal cycle picks up half the late-renewal points rather than none, and a permit with no renewal filed picks up nothing on fees, since the progress rule already counts that.',
                'why' => 'The reason is what an officer acts on — "no renewal filed" and "fees unsettled" are two different phone calls.',
            ],

            'at_risk.days_to_expiry' => [
                'label' => 'Expires',
                'formula' => 'Calendar days from today to the permit\'s validity date; negative once it has passed.',
                'covers' => 'The date on the permit, not on any renewal filed against it.',
                'why' => 'The hard deadline behind the score. It sits beside the score so the reader can see when a high score is urgency and when it is accumulated neglect.',
            ],

            'at_risk.barangay' => [
                'label' => 'Barangay',
                'formula' => 'The barangay recorded on the business\'s registered location.',
                'covers' => 'A business with no location on record reads as not recorded rather than being dropped. One with several location rows shows one of them.',
                'why' => 'Follow-up is done on foot. Grouping by barangay turns the list into a route.',
            ],

            'counts.high' => [
                'label' => 'High Risk',
                'formula' => 'Permits scoring 50 or above.',
                'covers' => 'Every permit scored in the window, not only those in the table.',
                'why' => 'The size of the immediate follow-up queue, which decides whether this week\'s chasing needs help.',
            ],

            'counts.moderate' => [
                'label' => 'Moderate Risk',
                'formula' => 'Permits scoring 25 up to 49.',
                'covers' => 'Every permit scored in the window.',
                'why' => 'The reminder queue — cases a notice usually settles without a call. This band growing while the high band holds steady is an early warning.',
            ],

            'counts.low' => [
                'label' => 'Low Risk',
                'formula' => 'Permits scoring under 25.',
                'covers' => 'Every permit scored in the window. A permit not yet due with nothing else against it lands here: the progress rule is switched off entirely more than 30 days out, and without that the whole register would score at least Moderate.',
                'why' => 'The band that makes the other two mean something. If nearly every permit is high risk, none of them is.',
            ],

            /*
             * The panel the client moved off the Analytics Dashboard, with its
             * first column rebuilt.
             *
             * Two things about this wording carry weight. First, it must not
             * read as a fifth risk band — the cards above this table count risk
             * LEVEL and this table counts permit STATE, they are different axes
             * over the same permits, and `lifecycle.near_expiry` says so in as
             * many words because that is the pair most easily confused. Second,
             * `lifecycle.pending_renewal` is the entry that has to explain the
             * axis change at all: it is the one state that is not a date, and it
             * is the reason the client asked for named states instead of
             * 30/60/90.
             */
            'lifecycle' => [
                'label' => 'Permit Lifecycle',
                'formula' => 'Every permit on the watchlist put into one of four states, counted per permit type. The first state that fits wins: lapsed, then renewal filed and undecided, then inside 30 days, then everything else.',
                'covers' => 'The same permits the risk levels above are counted from, so the four totals add up to the number of permits scored. Each permit is in exactly one state.',
                'why' => 'Who needs chasing today. A permit 12 days out with a renewal already lodged and one 12 days out with nothing filed used to share a column, and they are two different phone calls.',
            ],

            'lifecycle.active' => [
                'label' => 'Active / Compliant',
                'formula' => 'In force and more than 30 days from expiry — or already renewed, whatever the date.',
                'covers' => 'Permits whose renewal has been approved are counted here: the replacement has been issued, so nothing is left to chase.',
                'why' => 'The baseline the other three are read against. If almost every permit were near expiry, none of them would stand out.',
            ],

            'lifecycle.near_expiry' => [
                'label' => 'Near Expiry',
                'formula' => 'Expires within 30 days, with no renewal submitted against it.',
                'covers' => 'Thirty days is the mark the first automatic reminder goes out on, and the same mark the score starts counting a missing renewal from. A renewal saved as a draft, or one that was rejected, counts as nothing submitted.',
                'why' => 'The chase list. This is a different thing from the risk levels in the table above: those rank how much is wrong with a permit, this says where the permit stands.',
            ],

            'lifecycle.pending_renewal' => [
                'label' => 'Pending Renewal',
                'formula' => 'A renewal was submitted against the permit and no decision has been made on it. The expiry date does not come into this one.',
                'covers' => 'Submitted filings, including ones returned to the applicant for corrections. A draft has never reached the LGU, so it is not counted. An approved renewal is a decision, so it moves to Active.',
                'why' => 'These businesses are already being handled and do not need ringing. It is the only state here about the paperwork rather than the calendar, which is why the four states are more useful than three date ranges.',
            ],

            'lifecycle.overdue' => [
                'label' => 'Overdue / Expired',
                'formula' => 'The expiry date has passed. Counted here even when a renewal is under review.',
                'covers' => 'Permits that lapsed within the last 60 days. Anything older has left the watchlist and is not in any of these four counts.',
                'why' => 'The business is trading without cover today, and a filing in the queue does not give that cover back. That is why this state outranks the other three.',
            ],

            'reminders_sent' => [
                'label' => 'Reminders Sent',
                'formula' => 'Expiry notices already sent against these permits — the 60, 30 and 7 day warnings, and the renewal-due notice.',
                'covers' => 'Counted from notices recorded as sent, so it reads zero until the nightly permit scan has run. That zero is true: nothing has gone out. Lapse notices are not counted, because they report a change of status rather than asking anyone to renew.',
                'why' => 'Separates a business that has ignored three warnings from one that has had none. Same score, opposite conversations.',
            ],

            'actions' => [
                'label' => 'Recommended Actions',
                'formula' => 'Each band\'s count, carried through to the action it implies: immediate follow-up above 50, a reminder from 25, monitoring below that.',
                'covers' => 'All scored permits. The action follows from the band alone — it restates the score, and is not a second judgement about the business.',
                'why' => 'Requested directly in review: "kaya ako sinusunod, ng risk — so dapat meron ka diyan." A risk figure with no action attached leaves each officer to invent their own response.',
            ],

            'rulebook' => [
                'label' => 'What drives the score',
                'formula' => 'The five rules and the most each can add, with what each one measures.',
                'covers' => 'The rules as the scorer applies them, read from the same constants the scoring runs on.',
                'why' => 'This panel is why the score is allowed to exist. A number built out of other numbers is only defensible if a reader can take it apart.',
            ],

            'scored_permits' => [
                'label' => 'Permits scored',
                'formula' => 'All permits that fell inside the window and were put through the rules.',
                'covers' => 'The total the three band counts are out of. It is larger than the table, which lists only the leading rows.',
                'why' => 'Stated so the band counts can be read as shares. Forty high-risk permits out of sixty is a different office from forty out of four thousand.',
            ],

            'methodology' => [
                'label' => 'Scoring method',
                'formula' => 'The five rules in plain words, shipped from the scorer rather than written on the screen.',
                'covers' => 'The whole screen.',
                'why' => 'It travels with the figure, so a screenshot cannot separate the caveat from the number. It says the score counts warning signs already on the register rather than reaching past today.',
            ],
        ];
    }

    /**
     * Keys are dot paths into the business-growth payload.
     *
     * The trap on this screen is that its panels do not all count the same
     * population. Registrations and the barangay ranking include businesses
     * later removed from the register, because they were genuinely registered
     * in the period; the industry breakdown excludes them, because it describes
     * what is trading now. Both are defensible and the difference is invisible
     * in the bars, so each entry says which population it is over.
     *
     * @return array<string, array{label: string, formula: string, covers: string, why: string}>
     */
    private static function businessGrowth(): array
    {
        return [
            'growth_rate' => [
                'label' => 'Business Growth Rate',
                'formula' => 'New registrations this period minus the period before, divided by the period before, as a percentage.',
                'covers' => 'Counted from the registration date. Businesses since removed are still counted — they were registered at the time. When the earlier period had none at all this reads as no prior period rather than as growth.',
                'why' => 'Whether the register is growing against its own recent past. Both plain counts sit beside it, because on small numbers a big percentage swing is mostly chance.',
            ],

            'registrations' => [
                'label' => 'New registrations',
                'formula' => 'Businesses whose registration date falls inside the period.',
                'covers' => 'Dated from creation, so a business that registered and filed nothing still counts.',
                'why' => 'The raw figure under the growth rate, and the one an annual report is written from.',
            ],

            'closures' => [
                'label' => 'Closures (Period)',
                'formula' => 'Businesses removed from the register during the period.',
                'covers' => 'Dated by removal, which is not when the business stopped trading — the register does not record that.',
                'why' => 'The other half of growth. Read beside new registrations it says whether the register is really growing or only replacing what it loses.',
            ],

            'status_summary' => [
                'label' => 'Business Status Summary',
                'formula' => 'Every business ever registered, sorted into one of four states as things stand today: closed if struck off, inactive if never permitted, active if it holds a permit in force, expired otherwise.',
                'covers' => 'Worked out from permits, not from the status an admin sets on the record. A suspended or revoked permit makes a business expired, never active.',
                'why' => 'How much of the register is live. The four states are checked in a fixed order, so a business lands in exactly one.',
            ],

            'cohort_survival' => [
                /*
                 * Was "Cohort survival", which left the panel headed "Business
                 * Renewal Performance" opening a popover with a different name on
                 * it — the client's screenshot was of exactly that. The paper's §4
                 * table names this report once, and this is the name.
                 *
                 * "Kaplan-Meier", "censoring" and "cohort" are all gone from the
                 * reader-facing text. They name the method, not the figure. What
                 * an officer has to know is which businesses were followed and
                 * which were set aside, and that is now said in plain words.
                 */
                'label' => 'Business Renewal Performance',
                'formula' => 'Of the businesses that reached a given renewal, the share that came through every earlier renewal with no gap in cover. Carried forward one renewal at a time.',
                'covers' => 'Mayor\'s permits only, so a year with sanitary and fire renewals too counts once. Cover is unbroken if the next permit starts within a day of the last, and lapsed once the gap passes 30 days. A business still inside its permit is set aside, not counted as a lapse — one registered last month has had no renewal to miss. Removed businesses and revoked or suspended permits are left out.',
                'why' => 'How well the city holds on to its businesses across renewal cycles. It describes what this group did, not what any business will do next.',
            ],

            'cohort_survival.survival' => [
                'label' => 'Business Renewal Performance',
                'formula' => 'The share still renewing without a gap at the furthest renewal any business has reached.',
                'covers' => 'That furthest renewal only, which may rest on very few businesses — the number that got there is shown beside it for exactly that reason.',
                'why' => 'One number for how well the city holds on to its businesses over time. It is the hardest figure here to read at a glance, which is why the count behind it is never shown without it.',
            ],

            'top_barangays' => [
                'label' => 'Top Growing Barangays',
                'formula' => 'New registrations per barangay this period against the period before, ranked by the increase.',
                'covers' => 'Only businesses with a barangay on record. Ranked by the change rather than the total, so the busiest barangay appears only if it also grew. A barangay with none last period shows a plain count rather than a rise from nothing.',
                'why' => 'Where new commercial activity is appearing, which is where inspection and outreach effort should move next.',
            ],

            'closure_trend' => [
                'label' => 'Business Closure Trend',
                'formula' => 'Registrations removed each month across the period.',
                'covers' => 'Dated by removal from the register, as above. The first month is only a part month because the period starts mid-month, so its point sits low for a reason that has nothing to do with closures.',
                'why' => 'One period\'s closure count cannot say whether closures are rising. The month-by-month shape can.',
            ],

            /*
             * One definition for one panel, and the lens toggle does not get a
             * second. The three lenses are three orderings of the same figures
             * over the same six slots — swapping the ranking does not change
             * what a point on the chart means — and a second info button beside
             * the first would announce a near-identical explanation to a
             * screen-reader user for no gain. The same reasoning already keeps
             * the Top Growing Barangay summary card from carrying one; see the
             * note in BusinessGrowthPage.tsx.
             *
             * What DID have to change is the text: the criterion for appearing
             * on this chart is the whole of the question this panel was asked,
             * so it is now stated here as well as on the screen.
             */
            'industry_growth' => [
                'label' => 'Business Industry Growth Trend',
                'formula' => 'Lines of business on record, grouped by PSIC code — the national numbering for industries — with this period\'s new registrations against the period before. Six lines are drawn, chosen by the lens above the chart: Largest ranks by how many businesses carry the line today, Fastest growing and Fastest declining rank by the change between the two periods.',
                'covers' => 'Counted per declared line, not per business: one declaring three lines appears under all three. Businesses removed from the register are left out here, unlike the registration and barangay figures, because this panel describes what is trading now. The two change lenses rank only lines carrying at least '.BusinessGrowthAnalytics::INDUSTRY_LENS_MIN_BUSINESSES.' businesses — below that a single filing swings the figure more than a real trend would, and the count left out is printed under the chart. Where fewer than six lines qualify, fewer are drawn and the chart says so rather than making the number up.',
                'why' => 'What kind of city this is becoming. Six is a limit, not a shortlist: the register holds 135 PSIC codes and no palette keeps that many series apart, so the honest move is to let the reader pick which six. The biggest lines and the fastest-moving ones are different questions and the same chart could only ever answer one of them at a time.',
            ],
        ];
    }

    /**
     * The fitted model that sits beside the rule score.
     *
     * ── THE ONE PLACE THE WORD "PROBABILITY" IS ALLOWED, AND WHY ────────────
     *
     * renewalRisk() above may not use it. AnalyticsDefinitionsTest fails the
     * build on probability, probable, likelihood, predict, forecast or
     * confidence appearing anywhere in those definitions, and that ban is not
     * lifted, not loosened and not scoped away — it still covers every word of
     * every renewal-risk definition, because the rule score is still a weighted
     * rule score with nothing fitted behind it and an officer who reads "88%" as
     * a rate will act on it as one.
     *
     * These definitions describe a different object, and the difference is not a
     * matter of tone. The figure here is fitted to outcomes recovered from
     * permit history (RenewalOutcomes), evaluated on a period of the register the
     * fit never saw, and reported with the AUC, Brier score and calibration
     * reading that say how far it can be trusted. That is what earns a figure the
     * name, and the test now enforces the earning: this dataset may use the word
     * only in entries that also carry the evidence, and only while the payload
     * ships metrics beside it.
     *
     * Two claims are therefore made carefully and never merged:
     *
     *  - it IS a probability in the ordinary sense — a fitted estimate of how
     *    often permits in this position turned out to be renewed late;
     *  - it is NOT yet a well calibrated one. The evaluation says the figures run
     *    high and the worst decile is out by 22 points, so `metrics.calibrated`
     *    is false and the screen says in plain words that the number should be
     *    read as a ranking with a scale rather than as a rate. When that flag
     *    turns true the wording on screen changes with it.
     *
     * And the sentence that outranks all of it, which is why `training_data` has
     * an entry of its own here: the history this was fitted on was generated by
     * the analytics seeder, so what the coefficients describe is the seeder.
     *
     * @return array<string, array{label: string, formula: string, covers: string, why: string}>
     */
    private static function renewalModel(): array
    {
        return [
            'training_data' => [
                'label' => 'What this model was trained on',
                'formula' => 'Renewal outcomes recovered from the permit table: a renewal counts as late when the '
                    .'next permit of the same type began more than a day after the previous one lapsed.',
                'covers' => 'The renewal history in this register was generated for testing rather than loaded from '
                    .'the city, so every figure on this panel measures the method against generated behaviour. It is '
                    .'the first thing to know about the numbers below and it is stated above them, not here.',
                'why' => 'A model is only ever as good as what it was fitted to. Quoting an accuracy figure without '
                    .'saying whose behaviour it was measured on is the easiest way to mislead a reader who is doing '
                    .'nothing wrong.',
            ],

            'estimates' => [
                'label' => 'Estimated chance of a late renewal',
                'formula' => 'The fitted probability that the next permit begins more than a day after this one '
                    .'lapses, from a logistic regression over five signals: time to expiry, renewal progress, this '
                    .'business\'s earlier renewals, open compliance findings and unsettled fees.',
                'covers' => 'Only permits where there is still something to estimate. A permit that has already '
                    .'lapsed is late — a fact, not an estimate — and one whose renewal is already approved has '
                    .'nothing left to wait for; both are listed with the reason in place of a number. The figure is '
                    .'conditional on no renewal having been granted yet, which is the position of every permit an '
                    .'officer would be chasing.',
                'why' => 'The rule score beside it ranks permits by warning signs and is unchanged. This one answers '
                    .'a different question — how often permits in this position actually turned out late — and it '
                    .'can be wrong in a way the rule score cannot, which is why the accuracy figures are on the same '
                    .'screen rather than in a report nobody opens.',
            ],

            'metrics.auc' => [
                'label' => 'AUC',
                'formula' => 'The chance that a cycle which turned out late was scored above one that did not, '
                    .'measured on the newer cycles the model was not fitted on. 0.5 is a coin toss; 1.0 is perfect '
                    .'separation.',
                'covers' => 'Pooled across every lead time, which flatters it: permits closer to expiry are far more '
                    .'often late, so a model that knew nothing but the date would still score well here. The '
                    .'per-horizon table below removes the date from the comparison and is the honest reading of what '
                    .'the other four signals add.',
                'why' => 'It says whether the ordering is any good. It says nothing about whether the numbers '
                    .'themselves are right — that is what the Brier score and the calibration reading are for.',
            ],

            'metrics.brier' => [
                'label' => 'Brier score',
                'formula' => 'The average squared distance between the figure given and what happened, over the '
                    .'evaluation period. Lower is better; 0 is perfect.',
                'covers' => 'Shown against the score for always guessing the training period\'s own late rate, so '
                    .'the improvement over knowing nothing is visible rather than implied.',
                'why' => 'Unlike AUC this punishes being confident and wrong, which is the failure an officer would '
                    .'actually feel — a business rung twice about a renewal that was never at risk.',
            ],

            'calibration' => [
                'label' => 'Calibration',
                'formula' => 'The evaluation cycles sorted into ten equal groups by the figure they were given, with '
                    .'the rate that actually turned out late in each. The two columns match when the figures can be '
                    .'read as rates.',
                'covers' => 'The newer cycles only, never the ones the model was fitted on. The sentence above the '
                    .'table states the finding, including when the finding is that the figures are out.',
                'why' => 'A model can rank perfectly and still be wrong about the numbers — saying 90% where it '
                    .'means 40% would leave the AUC untouched and every staffing decision made from the figure '
                    .'wrong. This is the check that catches it.',
            ],

            'coefficients' => [
                'label' => 'What the model learned',
                'formula' => 'One row per signal, with the direction and size of its effect on the odds of a late '
                    .'renewal, holding the others still. Above 1 raises the chance, below 1 lowers it.',
                'covers' => 'Only signals that varied enough in the training period to be estimated. A signal that '
                    .'was the same on every training row, or one whose cases all went the same way, is named in the '
                    .'list of what was left out rather than shown with a figure that would be meaningless.',
                'why' => 'This is why the model is a regression and not something stronger. An officer can read a '
                    .'row here, disagree with it, and be right — which is not possible with a method whose reasoning '
                    .'cannot be printed.',
            ],

            'horizon_auc' => [
                'label' => 'Accuracy by time to expiry',
                'formula' => 'AUC recomputed within each lead time separately, so every permit in the comparison is '
                    .'the same distance from expiry.',
                'covers' => 'The evaluation cycles, split by how far out they were measured. The last row is blank '
                    .'where every cycle at that distance turned out late, because there is nothing left to separate.',
                'why' => 'The single most useful check on this screen. Holding the date still removes the one signal '
                    .'nobody needed a model for; whatever separation is left is what the other four signals '
                    .'contribute, and if these sat at 0.5 the model would be the calendar wearing a coat.',
            ],

            'split' => [
                'label' => 'How the data was split',
                'formula' => 'Cycles are ordered by the expiry date of the permit being renewed; the older 70% train '
                    .'the model and the newer 30% test it. Never a random split.',
                'covers' => 'Every measurement of one cycle stays on the same side of the cut, so nothing about a '
                    .'business can be learned and then tested on itself.',
                'why' => 'A random split would let the model see 2026 while being marked on 2025 — the future '
                    .'explaining the past. Every accuracy figure that comes out of one is too good, and the amount '
                    .'it is too good by cannot be measured afterwards.',
            ],

            'training' => [
                'label' => 'Cycles fitted',
                'formula' => 'Completed renewal cycles in the training period, each measured at up to seven points '
                    .'before its permit expired.',
                'covers' => 'Only cycles whose outcome had settled: a permit still in force, or one that lapsed too '
                    .'recently for a late renewal to have shown up yet, is left out rather than counted as punctual. '
                    .'The count of what was left out is shown beside it.',
                'why' => 'The sample size behind everything else on the screen. A reader told how many cycles were '
                    .'used and not how many were dropped has been handed a number with no denominator.',
            ],
        ];
    }
}
