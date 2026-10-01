<?php

namespace App\Support;

/**
 * What each figure on an analytics screen actually measures, and why it is there.
 *
 * These travel in `meta.definitions` beside `meta.engine` and `meta.computed_at`,
 * never inside `data`. The reason is the one AnalyticsController already states
 * about provenance: `data` is exactly the payload the engine returned, and how a
 * figure was derived is not one of the figures. Keeping them out of `data` also
 * keeps them out of the frozen golden fixtures, so re-wording an explanation is
 * never mistaken for a change in a number.
 *
 * They live server-side rather than in the React screens because a sentence typed into
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
 * to drop one — nor to call a count or a rule-based figure a prediction.
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
            AnalyticsDatasets::OFFICE_PERFORMANCE => self::officePerformance(),
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
             * wire name the screens and the PDF already read, so it was left
             * alone — see DashboardAnalytics::kpiFacts(). Every word a reader
             * sees is about the whole register.
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
                'formula' => 'Average working days per complexity tier, against that tier\'s RA 11032 limit: 3 days simple, 7 complex, 20 highly technical. All offices: from submission to decision. One office: only its own review, from the filing reaching it to the office finishing. For BPLO, the days the filing sat at its desk (For Approval, For Final Approval); filings from before September 2026, when every office reviewed at once, cannot show that and are left out of BPLO\'s view.',
                /*
                 * The holiday sentence read "never faster than shown", which
                 * is backwards: a holiday counted as a working day makes the
                 * figure LONGER than the truth, so the truth is never slower.
                 */
                'covers' => 'Filings decided, or one office\'s reviews finished, in the months set by the filter, with a tier on record. Days the filing waited on the applicant to pay or to resubmit are left out; a single permit sent back by its own office still counts against that office. Working days skip weekends. Holidays are not on the register and count as working days, so a real turnaround is never slower than shown.',
                'why' => 'RA 11032 sets a legal deadline, not an office target: going over it breaks the law. The limit here is the statutory one, never the flat deadline this system stamps on a filing — that field does not change with the tier.',
            ],

            'stages' => [
                // The paper reads "Average Processing Time by Department", and so
                // did the heading on screen; this label did not. Both now say the
                // same thing. The adviser's note here (§1.4) was to get rid of
                // "Time-in-Stage", which stays gone.
                'label' => 'Average Processing Time by Department',
                'formula' => 'Average office days from a review reaching an office to that office finishing it. Only office hours count (Monday to Friday, 8:00 to 17:00): a review that arrives at 7 pm starts at 8 the next morning, weekends are not counted, and one office day is 9 office hours.',
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
                'covers' => 'The months set by the filter, timed as in Average Processing Time: the applicant\'s days are left out, and one office is judged on its own review only. Each filing is judged against its own tier, so a 20-day highly technical decision passes where a 20-day simple one fails.',
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
             * `expiry` is back on this screen. It moved to Renewal Risk
             * Prediction and came back when that screen was removed (checklist
             * 2026-09-27, item 6): a register of what falls due in the next 30,
             * 60 and 90 days is the list an office plans its renewal season from,
             * and the dashboard is now the only place it could live.
             */
            'expiry' => [
                'label' => 'Permits Approaching Expiry',
                'formula' => 'Permits still in force that expire within 30, 60 and 90 days of today, per permit type. The Expired row counts businesses whose latest permit of that type has run out, with no newer one in force.',
                'covers' => 'The windows nest: a permit 20 days out is counted in all three. A business that renewed is not expired, however many old permits it holds, and one lapsed on two permit types is one business in two columns. A closed business, and a permit that was revoked or suspended, are left out.',
                'why' => 'How much renewal work is coming, and for which office, before it arrives at the counter.',
            ],

            /*
             * From Business Growth Analysis's Closure Trend, which drew closures
             * alone. Moved with the new registrations beside it, because a
             * closure count only means something next to what came in.
             */
            'business_movement' => [
                'label' => 'New and Closed Businesses',
                'formula' => 'Businesses registered each month, and businesses removed from the register or blacklisted each month, across the window.',
                'covers' => 'A closure is dated by the removal or the blacklisting, which is not when the business stopped trading — the register does not record that. A blacklisting with no date on record cannot be placed in a month and is left out. The first month is a part month.',
                'why' => 'Whether the register is growing or only replacing what it loses.',
            ],

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
                'formula' => 'Average office hours from an unanswered applicant message to the next reply from an officer. Only office hours count (Monday to Friday, 8:00 to 17:00), so a message sent at night starts waiting when the office opens.',
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
     * Keys are dot paths into the office-performance payload.
     *
     * This screen puts six offices in one table, so its definitions carry a duty
     * the other screens' do not: every column has to say whether it means the
     * same thing in all six rows. Two of them do not, and both say so in
     * `covers` rather than in a footnote — the reader comparing two numbers is
     * looking at the number, not at the bottom of the page.
     *
     * The tone rule from the class docblock applies hardest here. "Held" and
     * "handed on" are what an office does; "turnaround", "throughput" and
     * "SLA breach" are what a consultant calls it.
     *
     * @return array<string, array{label: string, formula: string, covers: string, why: string}>
     */
    private static function officePerformance(): array
    {
        return [
            'offices' => [
                'label' => 'Office Performance',
                'formula' => 'One row per office: reviews finished in the window, filings sitting with it now, and how long it held the ones it finished.',
                'covers' => 'All six offices, whether or not they finished anything. Finished reviews only in the time columns; open work is counted separately because it has no end date yet.',
                'why' => 'The only screen that puts the offices side by side. A queue building at one desk is invisible on a screen that shows one office at a time.',
            ],

            'offices.mean_working_days' => [
                'label' => 'Working days held',
                'formula' => 'Working days from a filing reaching the office to that office finishing with it. The average and the middle value are both shown, because a few long waits pull an average up while the middle value stays where most of the work lands.',
                'covers' => 'BPLO is blank here, and that is not missing data. Its record is stamped a second time when it approves the finished filing, so the time recorded against it is the whole filing rather than BPLO\'s own step. Weekends are not counted; public holidays are.',
                'why' => 'This is time an applicant spends waiting at one desk, in the unit RA 11032 uses, so it can be read straight against the 3, 7 and 20 day allowances.',
            ],

            'offices.turnaround_comparable' => [
                'label' => 'Comparable',
                'formula' => 'Whether the time recorded against an office measures that office\'s own step.',
                'covers' => 'Five of the six offices. BPLO is the exception and the row says why in full.',
                'why' => 'A blank cell in a comparison reads as zero or as an oversight. Naming the one office whose clock measures something else is the difference between a gap and a lie.',
            ],

            'offices.open' => [
                'label' => 'Open now',
                'formula' => 'Filings assigned to the office and not yet finished, counted at this moment, with the longest wait beside the count.',
                'covers' => 'Everything still open, including filings assigned before the window opened. Deliberately not cut to the window: the filing that has sat longest is usually the oldest one.',
                'why' => 'The count says how much is waiting; the longest wait says whether anything has been forgotten. A small queue with a nine-day head is worse than a large one that keeps moving.',
            ],

            'offices.breached' => [
                'label' => 'Past the allowance alone',
                'formula' => 'Filings where one office on its own took more working days than RA 11032 allows for the whole transaction — 3 simple, 7 complex, 20 highly technical.',
                'covers' => 'Filings with a tier set. Ones nobody has classified are left out rather than assumed into a tier, and the number left out is shown under the table.',
                'why' => 'The statute gives the City one allowance for the whole filing and does not divide it between offices, so no office can be charged a share of it. What can be said is that an office which alone outran the full allowance put that filing past its deadline whatever anyone else did.',
            ],

            'tiers' => [
                'label' => 'RA 11032 tiers',
                'formula' => 'Each tier\'s statutory allowance, and how many office holds finished inside it.',
                'covers' => 'Holds by the five offices whose recorded time is their own step. BPLO\'s are left out because its figure already contains the other five.',
                'why' => 'Shows which tier the pressure is in. Twenty days is generous and three is not, so the same office can look comfortable on one and stretched on the other.',
            ],

            'data_quality' => [
                'label' => 'Test data inside these averages',
                'formula' => 'Businesses whose names match the shapes the automated test suite creates, counted against the whole register.',
                'covers' => 'A guess from the name, because nothing records where a row came from. It will miss a test business named like a real one and could catch a real one named like a test.',
                'why' => 'Filings from test businesses are inside every average on this screen. An average that cannot be cleaned should at least say what it is carrying.',
            ],
        ];
    }
}
