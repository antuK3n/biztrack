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
             * The tile read `applications_ytd`, which left out every filing
             * with no submission date — 18 of 26 on the live register. The
             * client chose the all-time count (18 October 2026); see
             * DashboardAnalytics::kpiFacts(). `applications_ytd` is still on
             * the payload for older readers and has no definition because no
             * screen shows it.
             */
            'kpis.applications_all_time' => [
                'label' => 'Applications (all time)',
                'formula' => 'Every filing on record that is not a draft, of every type: new, renewal and amendment.',
                'covers' => 'The whole register — not this calendar year, and not the months set by the filter. Drafts and filings removed from the register are left out.',
                'why' => 'Everything the City has been asked to process. It sits beside This Month so the total and the current load can be read together.',
            ],

            'kpis.applications_this_month' => [
                'label' => 'This Month',
                'formula' => 'Filings submitted since the first day of this month (Manila time).',
                'covers' => 'A part month until the month ends. On the 3rd this is three days of filings, not a monthly rate. Drafts not yet submitted are left out.',
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
                'covers' => 'This calendar month (Manila time), counted from submission; drafts not yet submitted are left out. All three types are shown even at zero, so an empty row means none were filed.',
                'why' => 'Shows what kind of work is arriving, not just how much. Renewal season and new-registration season staff differently.',
            ],

            'decisions.approval_rate' => [
                'label' => 'Approval rate',
                'formula' => 'Approved filings ÷ decided filings (approved + rejected) × 100.',
                'covers' => 'Decided filings only. Pending and returned filings are left out: a returned filing is back with the applicant to fix and is still owed a decision.',
                'why' => 'Measures how the office decides, not how fast. Leaving pending filings out is why a growing backlog does not move it.',
            ],

            /*
             * 'processing_tiers' and 'compliance.ra11032_processing' went on
             * 18 October 2026 with the panel and the row they explained: both
             * judged filings by an RA 11032 complexity tier that BPLO stopped
             * setting on 27 September. Restore them only with a tier someone
             * sets.
             *
             * 'stages' was "Average Processing Time by Department", timed from
             * review assignments that close at routing — every office read 0
             * days. It is Time in Office now (client, 18 October 2026).
             */
            'stages' => [
                'label' => 'Time in Office',
                'formula' => 'Average working days from the day an office receives a permit application to the day it issues or refuses the permit. The office\'s review, the wait for its inspection and the inspection are all inside.',
                'covers' => 'Permits issued or refused in the months set by the filter. Days the permit was sent back to the applicant, and days waiting for the applicant to pay, are left out. An office with fewer than three permits is listed but not drawn. Weekends are not counted; holidays are not on the register and count as working days. Permits decided before 26 September 2026 have no record of when the office received them and are left out.',
                'why' => 'A business waits on several offices, so the slowest one sets the pace. This shows where a permit spends its time.',
            ],

            'stages.bottleneck' => [
                'label' => 'Slowest office',
                'formula' => 'Named only when the slowest office is at least one working day, and at least a quarter, above the average of all offices.',
                'covers' => 'The same permits as the panel, and only the offices drawn there. When the offices are close, no office is named.',
                'why' => 'A small gap is noise, not a queue. Naming an office only when it clearly stands out keeps the sentence worth acting on.',
            ],

            'compliance.permit_validity' => [
                'label' => 'Business Permit Compliance',
                'formula' => 'Businesses holding a valid permit of every type they have been issued ÷ businesses ever issued a permit × 100.',
                'covers' => 'Every business with permit history. The test is per type: a current sanitary permit with a lapsed fire clearance still counts as non-compliant.',
                'why' => 'How much of the register is covered right now. This counts businesses; the renewal card beside it counts permits.',
            ],

            'compliance.renewal' => [
                'label' => 'Renewal Compliance',
                'formula' => 'Permits that fell due and had a renewal filed before expiry ÷ permits that fell due × 100. A renewal counts for a permit when the same business filed one carrying that permit type during the permit\'s term.',
                'covers' => 'Permits expiring in the months set by the filter, for the types renewals are actually filed for. A draft is not a renewal; it has to be submitted.',
                'why' => 'Whether businesses renew before lapsing. When no renewal on the register can be matched to a permit that fell due it says it cannot be computed rather than 0%, because a gap in the register is not proof that nobody renewed.',
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
             *
             * Redefined 18 October 2026: it counted every business record
             * created and every one removed, so test records made and deleted
             * in the same month showed as 34 openings and 22 closures on a
             * register of 24. See DashboardAnalytics::movementFacts().
             */
            'business_movement' => [
                'label' => 'New and Closed Businesses',
                'formula' => 'New: businesses whose first application was submitted that month (or, for one brought over from the old register, whose first permit is dated that month). Closed: businesses whose Mayor\'s Permit BPLO retired that month, or that were blacklisted that month.',
                'covers' => 'Businesses removed from the register are counted in neither. A business record with no submitted application is not new. A suspension is not a closure. A blacklisting with no date on record is left out. The current month is marked as still running rather than read as a drop.',
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
                'why' => 'Passed, failed and conditional will not add up to the scheduled count, and this is why. The gap between scheduled and completed is the backlog. A cancelled visit is not counted as scheduled; a rescheduled one is, because it is still owed.',
            ],

            /*
             * "Officer Activity" became "Messages & Requests" on 18 October
             * 2026. Its response-time card ('officer_activity.mean_response_hours')
             * and, earlier, meeting participation were removed because nothing
             * in the product records them: the live register had no answered
             * thread and no meetings feature exists. Restore an entry only
             * with a card for it.
             */
            'officer_activity.threads_awaiting_reply' => [
                'label' => 'Awaiting reply',
                'formula' => 'Conversations where the applicant wrote last and no one at the office has answered.',
                'covers' => 'Messages sent in the months set by the filter. For one office, only its own conversations.',
                'why' => 'Each one is a question an applicant is still waiting on.',
            ],

            'officer_activity.requests_fulfilled_rate' => [
                'label' => 'Requests fulfilled',
                'formula' => 'Requests marked fulfilled ÷ requests raised × 100.',
                'covers' => 'Requests raised in the months set by the filter. One still open counts against the rate, because it is what is holding the filing.',
                'why' => 'Whether asking an applicant for something actually closes. Many raised and few fulfilled means filings are stalling on paperwork rather than on review.',
            ],

            'fees_collected' => [
                'label' => 'Fees Collected',
                'formula' => 'Payments received this month and this year, split by the permit each fee line was for. Business tax and other charges billed on the Mayor\'s Permit count under the Business Permit. A permit fee carried over onto a later bill counts under its own permit.',
                'covers' => 'Completed payments only, dated by when they cleared (Manila time); pending, failed and refunded ones are left out, and so are payments on records removed from the register. Other is late-renewal surcharges, interest and fees that belong to no single permit.',
                'why' => 'What each office\'s permits brought in, for the Treasurer\'s monthly and yearly report.',
            ],

            'suspended_permits' => [
                'label' => 'Suspended Permits',
                'formula' => 'Permits suspended today, how many of them for over 30 days, and the oldest five with the office each is waiting on.',
                'covers' => 'Permits on businesses still on the register. A suspension recorded before 5 October 2026 may have no date; it is counted but has no age and is never called long.',
                'why' => 'A suspended permit is a business that cannot trade. After 30 days unresolved BPLO may revoke it, so the long ones are the list to work through.',
            ],

            'suspended_permits.long' => [
                'label' => 'Over 30 days',
                'formula' => 'Suspended permits whose suspension started more than 30 days ago.',
                'covers' => 'The same count as the permits register\'s "Suspended over 30 days" filter, which the link opens.',
                'why' => 'BPLO may revoke these with a reason (client, 5 October 2026).',
            ],

            'map' => [
                'label' => 'Business locations',
                'formula' => 'Business locations plotted from recorded coordinates, marked by whether the business holds a valid permit today.',
                'covers' => 'Only businesses with coordinates on record, which is fewer than the register holds — the plotted count, the mapped count and the register total are all shown. Past a fixed cap the rest are counted in a note instead of drawn. For one office, its businesses are those with a filing sent to it or a permit it issued, including permits brought over from the old register; the other panels on this page use the same set.',
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

    /**
     * The dashboard's definitions as one office reads them.
     *
     * Scoped to an office, three figures count only the permit that office
     * issues, but their info buttons still said "Business Permit Compliance"
     * and "a valid permit of every type" — true for the whole city, wrong in
     * CHO's view, where the number is about sanitary permits. The permit's own
     * name comes from the register (permit_types), never from this file.
     *
     * @param  array<string, array<string, string>>  $definitions
     * @return array<string, array<string, string>>
     */
    public static function forOffice(array $definitions, ?string $office): array
    {
        if ($office === null) {
            return $definitions;
        }

        $permit = \Illuminate\Support\Facades\DB::table('permit_types')
            ->join('departments', 'departments.id', '=', 'permit_types.issuing_department_id')
            ->where('departments.code', $office)
            ->orderBy('permit_types.id')
            ->value('permit_types.name');
        if ($permit === null) {
            return $definitions;
        }
        $permit = (string) $permit;

        $rate = [
            'formula' => "Businesses holding a valid {$permit} today ÷ businesses ever issued one × 100.",
            'covers' => "Every business ever issued a {$permit}. One never issued it is left out of both sides.",
        ];

        foreach (['kpis.compliance_rate', 'compliance.permit_validity'] as $key) {
            if (isset($definitions[$key])) {
                $definitions[$key] = array_replace($definitions[$key], $rate);
            }
        }
        if (isset($definitions['compliance.permit_validity'])) {
            $definitions['compliance.permit_validity']['label'] = "{$permit} Compliance";
        }
        if (isset($definitions['kpis.compliance_rate']['why'])) {
            $definitions['kpis.compliance_rate']['why'] = "The one number leadership asks for. The {$permit} Compliance card below shows the same rate with its counts.";
        }
        if (isset($definitions['kpis.active_businesses'])) {
            $definitions['kpis.active_businesses']['formula'] = "Businesses holding a {$permit} still in force today.";
        }
        /*
         * The two panels added on 18 October 2026, as one office reads them:
         * its own permit's fees, and the permits held up by its own permit.
         */
        if (isset($definitions['fees_collected'])) {
            $definitions['fees_collected']['formula'] = "Payments received this month and this year for the {$permit}, from the fee lines billed for it — including a {$permit} fee carried over onto a later bill.";
        }
        if (isset($definitions['suspended_permits'])) {
            $definitions['suspended_permits']['formula'] = "Permits suspended today because of the {$permit}, plus this office's own permits suspended for another reason, with how many have been suspended over 30 days.";
        }

        return $definitions;
    }

}
