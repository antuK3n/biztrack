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
 *   formula  how the number is produced, denominator named explicitly
 *   covers   which rows it is over: the window, and what is left out
 *   why      what decision it informs, and who makes that decision
 *
 * `covers` is where the honesty lives. A rate whose exclusions are unstated reads
 * as a rate over everything, and several of these are not: the approval rate omits
 * pending filings, the inspection pass rate divides by completed rather than
 * scheduled, and the rankings are shares of the subset that has the field on
 * record at all. Every one of those omissions is defensible and none of them is
 * self-evident from the number.
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
     * @return array<string, array{label: string, formula: string, covers: string, why: string}>
     */
    private static function dashboard(): array
    {
        return [
            'kpis.active_businesses' => [
                'label' => 'Active Businesses',
                'formula' => 'Distinct businesses holding at least one permit that is active and whose validity date has not passed.',
                'covers' => 'The register as it stands today, not the trailing window. Businesses removed from the register are excluded.',
                'why' => 'The size of the regulated population. Every share and rate on this screen is ultimately a fraction of it, so it is stated first rather than left implicit.',
            ],

            'kpis.applications_ytd' => [
                'label' => 'Applications YTD',
                'formula' => 'Filings created on or after 1 January of the current year.',
                'covers' => 'Counted from creation, so drafts a business owner never submitted are included.',
                'why' => 'The annual workload figure BPLO reports upward. Year-to-date rather than trailing twelve months because that is the basis an annual report is written on.',
            ],

            'kpis.applications_this_month' => [
                'label' => 'This Month',
                'formula' => 'Filings created on or after the first day of the current month.',
                'covers' => 'A partial month until the month ends — on the 3rd this is three days of filings, not a monthly rate.',
                'why' => 'Current load, for staffing the counter this week. Read next to Applications YTD it also shows whether this month is running hot or quiet.',
            ],

            'kpis.compliance_rate' => [
                'label' => 'Compliance Rate',
                'formula' => 'The Business Permit Compliance indicator, repeated here as a headline. See that card for its denominator.',
                'covers' => 'Businesses that have ever been issued a permit. A business that never held one is in neither the numerator nor the denominator.',
                'why' => 'The single number leadership asks for. It is surfaced as a KPI and again in full below, because a headline percentage with no denominator on screen is the thing this dashboard is trying not to be.',
            ],

            'volume' => [
                'label' => 'Application Volume',
                'formula' => 'Filings this month grouped by transaction type: new, renewal, amendment. Total is their sum.',
                'covers' => 'The current calendar month, counted from creation. All three types are emitted even at zero, so an empty row means none were filed rather than none were counted.',
                'why' => 'Tells BPLO what kind of work is arriving, not just how much. Renewal season and new-registration season staff differently.',
            ],

            'decisions.approval_rate' => [
                'label' => 'Approval rate',
                'formula' => 'Approved ÷ (approved + returned for revision + rejected) × 100.',
                'covers' => 'Decided filings only. Pending is deliberately excluded from the denominator, and so is cancelled — a filing the applicant withdrew is not an office decision. Dividing by the grand total would report a lower rate every time the queue lengthened.',
                'why' => 'Measures how the office decides, not how fast. Because pending is excluded it does not move when a backlog builds, which is what makes it comparable month to month.',
            ],

            'processing_tiers' => [
                'label' => 'Average Processing Time for (RA 11032) Tier',
                'formula' => 'Mean working days from submission to decision, grouped by the filing\'s statutory complexity tier, against the RA 11032 limit for that tier: 3 days simple, 7 complex, 20 highly technical.',
                'covers' => 'Decided filings in the trailing window that record a tier, a submission and a decision. Working days exclude weekends; Philippine holidays are not modelled, so a real turnaround is never shorter than shown here.',
                'why' => 'RA 11032 is a legal deadline, not a service target, and a breach is a statutory failure. The two figures are kept apart on purpose: the statutory limit is what the law requires, while the deadline recorded on the filing is an internal field that does not vary by tier — reporting the second as the first would show simple filings passing a test they had not taken.',
            ],

            'stages' => [
                'label' => 'Average Time per Department',
                'formula' => 'Mean days from a review being assigned to an office to that office completing it.',
                'covers' => 'Completed reviews in the trailing window. A review still sitting open has no elapsed time yet and is not averaged in, so a department that never finishes anything looks fast rather than slow — read this beside the volume of reviews it handled.',
                'why' => 'A permit waits on six offices in sequence, so the total is set by the slowest. This is the figure that says which office to give people to.',
            ],

            'stages.bottleneck' => [
                'label' => 'Slowest department',
                'formula' => 'The department with the highest mean days, reported with how far it sits above the all-department mean and what share of all reviews it handled.',
                'covers' => 'The same completed reviews as the panel above.',
                'why' => 'Slowest alone is not actionable — an office can be slowest because it is hardest or because it is busiest. The share of total reviews is carried alongside so the two can be told apart before anyone is reassigned.',
            ],

            'compliance.ra11032_processing' => [
                'label' => 'Processing Rate Compliance to RA 11032',
                'formula' => 'Filings decided within their own tier\'s statutory limit ÷ all decided filings with a recorded tier × 100.',
                'covers' => 'The trailing window. Each filing is judged against the limit for its own tier, so a 20-day highly technical decision passes while a 20-day simple one fails.',
                'why' => 'The statutory pass rate, and the one an audit would ask for. It is kept as its own indicator rather than averaged with the two below, which measure different populations entirely and cannot be added.',
            ],

            'compliance.permit_validity' => [
                'label' => 'Business Permit Compliance',
                'formula' => 'Businesses currently holding a valid permit of every type they have ever been issued ÷ businesses ever issued any permit × 100.',
                'covers' => 'Every business with permit history. The test is per type: a business that holds a current sanitary permit but has let its fire clearance lapse counts as non-compliant, because the permit it needs is the set, not any one of them.',
                'why' => 'Says how much of the register is actually covered right now. Its denominator is businesses, where the indicator above counts filings — which is exactly why the two are shown separately.',
            ],

            'compliance.renewal' => [
                'label' => 'Renewal Compliance',
                'formula' => 'Permits that fell due and had a renewal filed before expiry ÷ permits that fell due × 100.',
                'covers' => 'Permits expiring in the trailing window, limited to types the register shows renewals are actually filed against. A renewal counts only once submitted — a draft is not a renewal.',
                'why' => 'Whether businesses renew before lapsing, which is what renewal reminders are meant to move. When too few renewals record which permit they replace, this reports as uncomputable and says so rather than returning 0%: a missing link in the register is a data gap, and printing it as total non-compliance would be a false accusation against every business in it.',
            ],

            'expiry' => [
                'label' => 'Permits Approaching Expiry',
                'formula' => 'Permits counted by time to expiry, per permit type. The forward windows are cumulative — the 60-day count includes the 30-day one — and expired is counted separately.',
                'covers' => 'The register as it stands today. Revoked and suspended permits are excluded: those are enforcement outcomes, and neither is waiting to be renewed.',
                'why' => 'The forward workload, and the list reminders are driven from. It is broken out per clearance type rather than reported as one business-permit figure because the office clearances are what expire and block a renewal; the business permit is the result of having them.',
            ],

            'top_barangays' => [
                'label' => 'Top Barangays',
                'formula' => 'Active businesses per barangay, ranked, with each barangay\'s share of the total.',
                'covers' => 'Shares are of the active businesses that have a barangay on record, not of all businesses — the payload carries both that total and the number of barangays it spans. Only the leading few are listed, so the shares shown do not sum to 100.',
                'why' => 'Where commercial activity actually sits, for siting inspections and reading the location insight a business owner is shown when picking an address.',
            ],

            'top_lines_of_business' => [
                'label' => 'Top Lines of Business',
                'formula' => 'Active businesses per PSIC line, ranked, with each line\'s share of the total.',
                'covers' => 'Grouped by PSIC code, on the business\'s principal line only. Shares are of active businesses with a line on record, and only the leading few are listed, so they do not sum to 100.',
                'why' => 'What kind of city this is, in the register\'s own classification. Feeds the concentration figure an applicant sees before committing to a location.',
            ],

            'organization_forms' => [
                'label' => 'Form of Organization',
                'formula' => 'Registered businesses by legal form — sole proprietorship, corporation, partnership, cooperative — with each as a share of those recorded.',
                'covers' => 'Businesses whose form is not recorded are counted and shown as unrecorded, but excluded from the shares, so the percentages are of known forms only.',
                'why' => 'Legal form drives which documents a filing requires. Showing the unrecorded count beside the shares keeps a near-empty field from reading as a real distribution.',
            ],

            'inspections.pass_rate' => [
                'label' => 'Pass rate',
                'formula' => 'Passed ÷ completed × 100.',
                'covers' => 'Completed inspections only. The denominator is deliberately not scheduled: an inspection not yet carried out has no outcome, and counting it as a non-pass would penalise an office for its own backlog.',
                'why' => 'Stated on screen because the numbers look wrong otherwise — pass, fail and conditional will not add up to the scheduled count, and this is the reason. Read against the scheduled-versus-completed gap it also shows backlog, which matters most where a permit is about to expire.',
            ],

            'officer_activity.mean_response_hours' => [
                'label' => 'Response time',
                'formula' => 'Mean hours from an unanswered applicant message to the next reply from an officer.',
                'covers' => 'Replies sent in the trailing window. Only the first unanswered message in a thread starts the clock, so an applicant who follows up three times is one wait, not three. Threads still waiting are counted separately and are not in the mean — a question never answered cannot lengthen an average.',
                'why' => 'How long an applicant waits to be spoken to, which is the part of the process they experience directly. The median and the waiting-thread count travel with it because a mean alone hides both a long tail and everything still unanswered.',
            ],

            'officer_activity.requests_fulfilled_rate' => [
                'label' => 'Requests fulfilled',
                'formula' => 'Requests marked fulfilled ÷ all requests raised × 100.',
                'covers' => 'Requests raised in the trailing window. One still open counts against the rate, because from the applicant\'s side an outstanding request is exactly what is holding the filing.',
                'why' => 'Whether asking an applicant for something actually closes. A high rate of requests raised with a low rate fulfilled means filings are stalling on paperwork rather than on review.',
            ],

            'officer_activity.meetings_attended_rate' => [
                'label' => 'Meeting participation',
                'formula' => 'Meetings with a recorded applicant response ÷ meetings scheduled × 100.',
                'covers' => 'Meetings scheduled in the trailing window. Attendance is not recorded anywhere in the system, so a logged response is used as the proxy — this measures response, and calls itself participation rather than attendance for that reason.',
                'why' => 'Whether scheduling a meeting achieves anything. The proxy is named rather than hidden, because a figure captioned attendance would be claiming a fact the register does not hold.',
            ],

            'map' => [
                'label' => 'Business locations',
                'formula' => 'Registered business locations plotted from recorded coordinates, marked by whether the business currently holds a valid permit.',
                'covers' => 'Only businesses with coordinates on record, which is fewer than the register holds — the plotted count, the number with coordinates and the register total are all shown so the gap is visible. Beyond a fixed cap the remainder is noted rather than drawn.',
                'why' => 'Turns the barangay ranking into something that can be walked. Lapsed permits are drawn rather than filtered out, since a cluster of lapsed businesses in one area is the pattern worth seeing.',
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
                'formula' => 'For each week, the mean days a department took on the reviews it finished that week — clock starts when the review is assigned to the office and stops when that office completes it. The centre line and the range around it are fitted on the first 24 charted weeks and held fixed after that.',
                'covers' => 'Reviews completed in the window that carry both an assignment and a completion time. Open reviews are excluded, so a department that never finishes anything has fewer points rather than worse ones. Days are calendar days including weekends. A week with fewer than three completions is dropped entirely rather than plotted thin, which is why the reviews behind the chart total less than the window\'s completed count.',
                'why' => 'Lower is better: this is time an applicant spends waiting on one desk. The limits are fitted on the earliest weeks on purpose — if a recent slowdown were allowed into the calibration it would widen the very range meant to catch it, and the office would be graded against its own drift.',
            ],

            'departments.status' => [
                'label' => 'Process Status Indicator',
                'formula' => 'Whether the most recent charted week sat inside the fitted range, or outside it — either beyond the range itself, or flagged by the smoothed trend line.',
                'covers' => 'The latest week only. It says nothing about the weeks before it: a department can read as normal this week and still have been outside the range for the six before, which is what the flagged list underneath is for.',
                'why' => 'Outside does not mean a rule was broken. It means this week did not look like this department\'s own normal, which is a prompt to ask why rather than a finding in itself — a holiday backlog and a genuine breakdown look identical here.',
            ],

            'departments.flagged' => [
                'label' => 'Flagged Weeks',
                'formula' => 'The weeks whose mean sat outside the fitted range, listed with how far above or below the centre line they fell.',
                'covers' => 'Charted weeks only, so a week dropped for having fewer than three completions can never appear here however slow it was.',
                'why' => 'A single unusual week is noise; three in a quarter is a pattern. Listing them with dates lets the office match a slow stretch against something it remembers — a staff absence, a system outage, a surge — instead of guessing from a chart.',
            ],

            'departments.trend' => [
                'label' => 'Gradual Slowdown Detector',
                'formula' => 'A smoothed average that weights the newest week most heavily and fades older ones, compared against the same fitted centre line. Rising or easing is reported once the smoothed value has moved more than half way to the edge of its band.',
                'covers' => 'The same charted weeks as the chart. The bar length is the size of the move regardless of direction, so a department improving quickly and one worsening quickly both show a long bar — the word beside it is what tells them apart.',
                'why' => 'A slide of half a day a week never breaches the range and never appears in the flagged list, but a quarter of it is a week of added waiting. This is the panel that catches the drift no single week is bad enough to trigger.',
            ],

            'completed_reviews' => [
                'label' => 'Completed reviews',
                'formula' => 'All departmental reviews completed inside the window.',
                'covers' => 'Every completion, including those in weeks too thin to chart. It is deliberately larger than the reviews the chart draws — the gap is the volume the chart could not say anything reliable about.',
                'why' => 'The sample size the whole screen rests on. A control chart built on a few dozen reviews is a description of those reviews and not of the office, and this is the number that says which of the two the reader is looking at.',
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
     * @return array<string, array{label: string, formula: string, covers: string, why: string}>
     */
    private static function renewalRisk(): array
    {
        return [
            'at_risk' => [
                'label' => 'Businesses at Risk',
                'formula' => 'Permits falling due inside the horizon, scored against five rules and listed worst first. Ties are broken by whichever expires sooner.',
                'covers' => 'Active and expired permits whose validity ends between 60 days ago and the end of the horizon — recently lapsed ones are kept in, because a permit that quietly expired last month is the case most worth chasing. Revoked and suspended permits are excluded: those are enforcement outcomes, and neither is waiting to be renewed. Only the leading rows are listed, while the band counts below are over every permit scored.',
                'why' => 'The follow-up list. It is ordered by score rather than by expiry date so that a permit expiring in a fortnight with nothing filed and fees outstanding outranks one expiring next week whose renewal is already approved.',
            ],

            'at_risk.score' => [
                'label' => 'Risk score',
                'formula' => 'Points out of 100, added across five rules: how soon the permit expires (30), how far any renewal has got (25), whether this business has renewed late before (20), open compliance findings (15), and unsettled fees on the renewal (10).',
                'covers' => 'A weighted checklist, and nothing behind it is fitted on past behaviour. The register does not record whether a business eventually renewed late, so there was no outcome to fit against and there is no accuracy figure to quote. The number sorts a queue; it says nothing about what any one business will do.',
                'why' => 'Scoring is what lets a hundred permits be worked in the order that matters instead of by expiry date alone. Every rule and its weight is printed below the table on purpose — an officer must be able to disagree with the ranking on the merits, which they cannot do with a number whose workings are hidden.',
            ],

            'at_risk.drivers' => [
                'label' => 'Why this permit is listed',
                'formula' => 'The rules that scored above zero for this permit, heaviest first.',
                'covers' => 'Only the top few are shown, so a permit scoring on all five rules displays the three that moved it most. Two of the rules are deliberately quiet: a business in its first renewal cycle scores half the punctuality weight rather than zero, because no record is not the same as a clean record, and a permit with no renewal filed at all scores nothing on fees, since the missing filing is already carried by the progress rule and would otherwise be counted twice.',
                'why' => 'The reason is what an officer acts on — "no renewal filed" and "fees unsettled" are two different phone calls. A score with no reasons attached can only be trusted or ignored wholesale.',
            ],

            'at_risk.days_to_expiry' => [
                'label' => 'Expires',
                'formula' => 'Calendar days from today to the permit\'s validity date; negative once it has passed.',
                'covers' => 'The date on the permit, not on any renewal filed against it.',
                'why' => 'The hard deadline behind the score. It is shown beside the score rather than folded into it so the reader can see when a high score is urgency and when it is accumulated neglect.',
            ],

            'at_risk.barangay' => [
                'label' => 'Barangay',
                'formula' => 'The barangay recorded on the business\'s registered location.',
                'covers' => 'Businesses with no location on record read as not recorded rather than being dropped from the list. A business holding more than one location row is shown one of them.',
                'why' => 'Follow-up is done on foot. Grouping the list by barangay is what turns it into a route.',
            ],

            'counts.high' => [
                'label' => 'High Risk',
                'formula' => 'Permits scoring 50 or above.',
                'covers' => 'Every permit scored in the window, not only those listed in the table above.',
                'why' => 'The size of the immediate-follow-up queue, which is the number that decides whether this week\'s chasing can be done by the desk or needs help.',
            ],

            'counts.moderate' => [
                'label' => 'Moderate Risk',
                'formula' => 'Permits scoring 25 up to 49.',
                'covers' => 'Every permit scored in the window.',
                'why' => 'The reminder queue — cases a notice will probably resolve without anyone calling. Watching this band grow while the high band holds steady is what an early warning looks like.',
            ],

            'counts.low' => [
                'label' => 'Low Risk',
                'formula' => 'Permits scoring under 25.',
                'covers' => 'Every permit scored in the window. A permit that is not yet due and has nothing else against it lands here by design: the progress rule is switched off entirely more than 30 days out, because without that the whole register would score at least Moderate and this band would be empty.',
                'why' => 'The band that makes the other two mean something. If nearly everything is high risk, nothing is.',
            ],

            'reminders_sent' => [
                'label' => 'Reminders Sent',
                'formula' => 'Expiry notices already issued against the permits in scope — the 60, 30 and 7 day warnings, and the renewal-due notice.',
                'covers' => 'Counted from notices actually recorded as sent, so it reads zero until the nightly permit scan has run at least once. That zero is true rather than missing: no notice has gone out. The lapse notice is not counted — it reports a status change and is not a request to renew, and pooling it in would inflate this figure with messages nobody was asked to act on.',
                'why' => 'Separates a business that has ignored three warnings from one that has had none, which are the same score and opposite conversations.',
            ],

            'actions' => [
                'label' => 'Recommended Actions',
                'formula' => 'Each band\'s count, carried through to the action it implies: immediate follow-up above 50, a reminder from 25, monitoring below that.',
                'covers' => 'All scored permits. The action follows from the band alone and nothing else — it is a restatement of the score, not a second judgement about the business.',
                'why' => 'Requested directly in review: "kaya ako sinusunod, ng risk — so dapat meron ka diyan." A risk figure with no action attached leaves the officer to invent the response, and two officers will invent different ones.',
            ],

            'rulebook' => [
                'label' => 'What drives the score',
                'formula' => 'The five rules and the most each can contribute, listed with what each one measures.',
                'covers' => 'The rules as the scorer applies them, read from the same constants the scoring runs on rather than retyped here.',
                'why' => 'This panel is the reason the score is allowed to exist. A composite number is only defensible if a reader can take it apart, and printing the weights is what lets an officer say the punctuality rule is too harsh instead of only that the ranking feels wrong.',
            ],

            'scored_permits' => [
                'label' => 'Permits scored',
                'formula' => 'All permits that fell inside the window and were put through the rules.',
                'covers' => 'The denominator behind the three band counts, and larger than the table, which lists only the leading rows.',
                'why' => 'Stated so the band counts can be read as shares. Forty high-risk permits out of sixty is a different office from forty out of four thousand.',
            ],

            'methodology' => [
                'label' => 'How this list is built',
                'formula' => 'The five rules in plain words, shipped from the scorer rather than written on the screen.',
                'covers' => 'The whole screen.',
                'why' => 'It travels with the figure so the caveat cannot be separated from the number by a screenshot. This is the sentence that says the score counts warning signs already on the register rather than reaching past today, and it is the one an officer is most likely to need quoted back at them.',
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
                'covers' => 'Counted from the registration date, including businesses since removed from the register — they were registered, and dropping them would rewrite past periods every time one closed. Reported as no prior period rather than as growth when the earlier period is empty, since everything divided by nothing is not infinite growth.',
                'why' => 'Whether the register is growing, against its own recent past rather than a target. Both raw counts are shown beside it because a percentage swing on small numbers is mostly noise.',
            ],

            'registrations' => [
                'label' => 'New registrations',
                'formula' => 'Businesses whose registration date falls inside the period.',
                'covers' => 'Dated from creation, so a business that registered and never filed anything still counts.',
                'why' => 'The raw figure under the growth rate, and the one an annual report is written from.',
            ],

            'closures' => [
                'label' => 'Closures (Period)',
                'formula' => 'Businesses removed from the register during the period.',
                'covers' => 'Dated by when the registration was removed, which is not when the business stopped trading — nothing in the register records that. A business that shut last year and was struck off this month is counted this month.',
                'why' => 'The other half of growth. Read beside new registrations it says whether the register is growing or merely churning.',
            ],

            'status_summary' => [
                'label' => 'Business Status Summary',
                'formula' => 'Every business ever registered, sorted into one of four states as things stand today: closed if struck off, inactive if never permitted, active if it holds a permit still in force, and expired otherwise. Shares are of those four.',
                'covers' => 'Derived from permits, not from the moderation flag on the business record — that field answers whether an account is in good standing, which is a different question. A suspended or revoked permit is enough to make a business expired rather than inactive, but never active.',
                'why' => 'How much of the register is live, which is the figure behind any claim about coverage. The four states are ordered so that each is decided before the next is asked, so a business cannot fall into two.',
            ],

            'cohort_survival' => [
                'label' => 'Business Renewal Performance',
                'formula' => 'Of the businesses that reached a given renewal, the share that had come through every earlier one without a gap in cover — carried forward cycle by cycle. A business still inside its current permit is set aside at that point rather than counted as a lapse.',
                'covers' => 'Mayor\'s permit chains only, so a year in which a business renewed its sanitary and fire clearances too is counted once rather than three times. A permit is treated as continuous if the next one starts within a day of the last one ending, and as lapsed once the gap passes 30 days. Businesses removed from the register are excluded, as are revoked and suspended permits.',
                'why' => 'It describes what this cohort did and is not a forecast of what any business will do next. Setting aside businesses still inside their permit is the whole point: a business registered last month has had no renewal to miss, and counting it as a success would flatter the figure while counting it as a failure would be a false accusation.',
            ],

            'cohort_survival.survival' => [
                'label' => 'Renewal survival',
                'formula' => 'The share still renewing without a gap at the furthest cycle any business in the register has reached.',
                'covers' => 'The furthest cycle, which may rest on very few businesses — the number still at risk at that point is carried alongside for exactly that reason. A register only a few years old will report this off a handful of chains.',
                'why' => 'One number for how well the register holds on to businesses over time. It is the least self-evident figure on the screen, which is why the count behind it is never shown without it.',
            ],

            'top_barangays' => [
                'label' => 'Top Growing Barangays',
                'formula' => 'New registrations per barangay this period against the period before, ranked by the increase.',
                'covers' => 'Only businesses with a barangay on record; one holding several address rows is counted once per row. Ranked by the change rather than the total, so the busiest barangay does not appear here unless it also grew, and a barangay with no registrations before is shown as a raw count instead of an infinite percentage.',
                'why' => 'Where new commercial activity is appearing, which is where inspection and outreach effort should move next. Ranking by size instead would return the same three barangays every period and say nothing.',
            ],

            'closure_trend' => [
                'label' => 'Business Closure Trend',
                'formula' => 'Registrations removed each month across the period.',
                'covers' => 'Dated by removal from the register, as above. The first month is partial because the period begins mid-month, so its bar is short for a reason that has nothing to do with closures.',
                'why' => 'A single period\'s closure count cannot say whether closures are rising. The month-by-month shape can, and a spike that lines up with a renewal deadline is a different story from a steady climb.',
            ],

            'industry_growth' => [
                'label' => 'Business Industry Growth Trend',
                'formula' => 'Lines of business on record per PSIC code, with this period\'s new registrations against the period before, ranked by how many carry that line today.',
                'covers' => 'Counted per declared line, not per business — a business declaring three lines appears under all three. Businesses removed from the register are excluded here, unlike the registration and barangay figures, because this panel describes what is trading now. The bar length is the current total, so the ranking is by size while the growing or declining word beside it is about the change; the longest bar is not necessarily the fastest growing.',
                'why' => 'What kind of city this is becoming, in the register\'s own classification. Feeds zoning and the concentration figure an applicant is shown before committing to a location.',
            ],
        ];
    }
}
