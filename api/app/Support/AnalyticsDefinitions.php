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
 * WRITE THESE FOR A BPLO CLERK, not for a statistician. The reader is the officer
 * who has to act on the figure, and client testing (checklist item 102) came back
 * asking for plainer words here. So: "average", not "mean"; "the middle value,
 * half above and half below", not "the median"; "what it is divided by", not "the
 * denominator"; "the months set by the filter at the top", not "the trailing
 * window". Where a term is genuinely the name of the thing — cohort survival, PSIC
 * code, RA 11032 tier — keep the term and gloss it in the same sentence rather
 * than dropping either the word or its meaning.
 *
 * Plainer must never become looser. Every window, table and exclusion these
 * sentences name is a claim the reader can check, and simplifying is not licence
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
                'formula' => 'Every business holding at least one permit that is still active and has not passed its expiry date. A business is counted once however many permits it holds.',
                'covers' => 'The register as it stands today, not the months set by the filter at the top. Businesses removed from the register are left out.',
                'why' => 'How many businesses the city is regulating right now. Nearly every percentage on this screen is a slice of this number, so it is stated first rather than left unsaid.',
            ],

            'kpis.applications_ytd' => [
                'label' => 'Applications YTD',
                'formula' => 'Filings created on or after 1 January of the current year.',
                'covers' => 'Counted from creation, so drafts a business owner never submitted are included.',
                'why' => 'The yearly workload figure BPLO reports upward. It counts from 1 January rather than from twelve months ago, because that is the basis an annual report is written on.',
            ],

            'kpis.applications_this_month' => [
                'label' => 'This Month',
                'formula' => 'Filings created on or after the first day of the current month.',
                'covers' => 'A partial month until the month ends — on the 3rd this is three days of filings, not a monthly rate.',
                'why' => 'Current load, for staffing the counter this week. Read next to Applications YTD it also shows whether this month is running hot or quiet.',
            ],

            'kpis.compliance_rate' => [
                'label' => 'Compliance Rate',
                'formula' => 'The same figure as the Business Permit Compliance card further down, repeated here as a headline. That card states what it is a percentage of.',
                'covers' => 'Businesses that have ever been issued a permit. A business that has never held one is left out of the sum altogether, on both sides of the division.',
                'why' => 'The single number leadership asks for. It appears here and again in full below, because a headline percentage with nothing on screen to say what it is a percentage of is exactly what this dashboard is trying not to be.',
            ],

            'volume' => [
                'label' => 'Application Volume',
                'formula' => 'Filings this month grouped by transaction type: new, renewal, amendment. Total is their sum.',
                'covers' => 'The current calendar month, counted from creation. All three types are emitted even at zero, so an empty row means none were filed rather than none were counted.',
                'why' => 'Tells BPLO what kind of work is arriving, not just how much. Renewal season and new-registration season staff differently.',
            ],

            'decisions.approval_rate' => [
                'label' => 'Approval rate',
                'formula' => 'Approved filings ÷ all filings already decided (approved + returned for revision + rejected) × 100.',
                'covers' => 'Decided filings only. Filings still pending are deliberately left out, and so are cancelled ones — a filing the applicant withdrew is not a decision the office made. If every filing were counted instead, the rate would fall every time the queue got longer, even when the office was deciding just as well.',
                'why' => 'Measures how the office decides, not how fast. Because pending filings are left out, it does not move when a backlog builds, which is what makes one month comparable with the next.',
            ],

            'processing_tiers' => [
                'label' => 'Average Processing Time for (RA 11032) Tier',
                'formula' => 'The average number of working days from submission to decision, grouped by how complex the law treats the filing, and set against the RA 11032 deadline for that group: 3 days simple, 7 complex, 20 highly technical.',
                'covers' => 'Decided filings in the months set by the filter at the top that record a complexity group, a submission and a decision. Working days leave out weekends; Philippine holidays are not allowed for on either side, so a real turnaround is never shorter than what is shown here.',
                'why' => 'RA 11032 sets a legal deadline, not an office target, and going over it is a breach of the law. The two figures are kept apart on purpose: the statutory limit is what the law requires, while the deadline recorded on the filing is an internal field that does not vary by tier — reporting the second as the first would show simple filings passing a test they had not taken.',
            ],

            'stages' => [
                'label' => 'Average Time per Department',
                'formula' => 'The average number of days from a review landing with an office to that office finishing it.',
                'covers' => 'Finished reviews in the months set by the filter at the top. A review still sitting open has no finish time yet and so is not in the average — which means a department that never finishes anything looks fast rather than slow. Read this next to how many reviews it handled.',
                'why' => 'A permit waits on six offices in sequence, so the total is set by the slowest. This is the figure that says which office to give people to.',
            ],

            'stages.bottleneck' => [
                'label' => 'Slowest department',
                'formula' => 'The department with the highest average, shown with how many days above the all-department average that is, and what share of all reviews it handled.',
                'covers' => 'The same finished reviews as the panel above.',
                'why' => 'Slowest alone is not actionable — an office can be slowest because it is hardest or because it is busiest. The share of total reviews is carried alongside so the two can be told apart before anyone is reassigned.',
            ],

            'compliance.ra11032_processing' => [
                'label' => 'Processing Rate Compliance to RA 11032',
                'formula' => 'Filings decided inside the legal deadline for their own complexity group ÷ all decided filings that record a group × 100.',
                'covers' => 'The months set by the filter at the top. Each filing is judged against the deadline for its own group, so a 20-day highly technical decision passes while a 20-day simple one fails.',
                'why' => 'The pass rate against the law, and the one an audit would ask for. It is kept as its own figure rather than blended with the two below, because those two count different things entirely and cannot be added together.',
            ],

            'compliance.permit_validity' => [
                'label' => 'Business Permit Compliance',
                'formula' => 'Businesses currently holding a valid permit of every type they have ever been issued ÷ businesses ever issued any permit × 100.',
                'covers' => 'Every business with permit history. The test is per type: a business that holds a current sanitary permit but has let its fire clearance lapse counts as non-compliant, because the permit it needs is the set, not any one of them.',
                'why' => 'Says how much of the register is actually covered right now. This one counts businesses, while the figure above counts filings — which is exactly why the two are shown separately.',
            ],

            'compliance.renewal' => [
                'label' => 'Renewal Compliance',
                'formula' => 'Permits that fell due and had a renewal filed before expiry ÷ permits that fell due × 100.',
                'covers' => 'Permits expiring in the months set by the filter at the top, limited to the permit types the register shows renewals are actually filed against. A renewal counts only once it has been submitted — a draft is not a renewal.',
                'why' => 'Whether businesses renew before lapsing, which is what renewal reminders are meant to move. When too few renewals record which permit they replace, this says it cannot be computed rather than returning 0%: a missing link in the register is a data gap, and printing it as total non-compliance would be a false accusation against every business in it.',
            ],

            'expiry' => [
                'label' => 'Permits Approaching Expiry',
                'formula' => 'Permits counted by how long is left before they expire, per permit type. The forward columns overlap on purpose — the 60-day count includes everything in the 30-day one — and already-expired permits are counted on their own.',
                'covers' => 'The register as it stands today. Revoked and suspended permits are excluded: those are enforcement outcomes, and neither is waiting to be renewed.',
                'why' => 'The forward workload, and the list reminders are driven from. It is broken out per clearance type rather than reported as one business-permit figure because the office clearances are what expire and block a renewal; the business permit is the result of having them.',
            ],

            'top_barangays' => [
                'label' => 'Top Barangays',
                'formula' => 'Active businesses per barangay, ranked, with each barangay\'s share of the total.',
                'covers' => 'The shares are out of the active businesses that have a barangay on record, not out of every business — both that total and the number of barangays it spans are shown with the table. Only the leading few are listed, so the percentages shown do not add up to 100.',
                'why' => 'Where commercial activity actually sits, for siting inspections and reading the location insight a business owner is shown when picking an address.',
            ],

            'top_lines_of_business' => [
                'label' => 'Top Lines of Business',
                'formula' => 'Active businesses in each line of business, ranked, with each line\'s share of the total. Lines are grouped by PSIC code — the national numbering for industries.',
                'covers' => 'Each business is counted under its main line only. The shares are out of the active businesses that have a line on record, and only the leading few are listed, so the percentages shown do not add up to 100.',
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
                'formula' => 'Inspections passed ÷ inspections actually carried out × 100.',
                'covers' => 'Completed inspections only. It is deliberately not divided by the inspections merely scheduled: one that has not been carried out yet has no result, and treating it as a fail would punish an office for its own backlog.',
                'why' => 'Stated on screen because the numbers look wrong otherwise — pass, fail and conditional will not add up to the scheduled count, and this is the reason. Read against the scheduled-versus-completed gap it also shows backlog, which matters most where a permit is about to expire.',
            ],

            'officer_activity.mean_response_hours' => [
                'label' => 'Response time',
                'formula' => 'The average number of hours from an applicant message nobody has answered to the next reply from an officer.',
                'covers' => 'Replies sent in the months set by the filter at the top. Only the first unanswered message in a conversation starts the clock, so an applicant who follows up three times is one wait, not three. Conversations still waiting are counted on their own and are not in the average — a question never answered cannot make an average longer.',
                'why' => 'How long an applicant waits to be spoken to, which is the part of the process they feel directly. The middle wait (half were answered faster, half slower) and the count of conversations still waiting are shown beside it, because an average on its own hides both a handful of very long waits and everything nobody has replied to at all.',
            ],

            'officer_activity.requests_fulfilled_rate' => [
                'label' => 'Requests fulfilled',
                'formula' => 'Requests marked fulfilled ÷ all requests raised × 100.',
                'covers' => 'Requests raised in the months set by the filter at the top. One still open counts against the rate, because from the applicant\'s side an outstanding request is exactly what is holding the filing.',
                'why' => 'Whether asking an applicant for something actually closes. A high rate of requests raised with a low rate fulfilled means filings are stalling on paperwork rather than on review.',
            ],

            'officer_activity.meetings_attended_rate' => [
                'label' => 'Meeting participation',
                'formula' => 'Meetings with a recorded applicant response ÷ meetings scheduled × 100.',
                'covers' => 'Meetings scheduled in the months set by the filter at the top. Whether anyone actually turned up is not recorded anywhere in the system, so a recorded reply is used as a stand-in — this measures replies, and calls itself participation rather than attendance for that reason.',
                'why' => 'Whether scheduling a meeting achieves anything. The stand-in is named rather than hidden, because a figure captioned attendance would be claiming something the register does not actually record.',
            ],

            'map' => [
                'label' => 'Business locations',
                'formula' => 'Registered business locations plotted from recorded coordinates, marked by whether the business currently holds a valid permit.',
                'covers' => 'Only businesses that have map coordinates on record, which is fewer than the register holds — the number plotted, the number with coordinates and the register total are all shown so the gap is visible. Past a fixed limit the rest are counted in a note rather than drawn.',
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
                'formula' => 'For each week, the average number of days a department took on the reviews it finished that week. The clock starts when the review lands with the office and stops when that office finishes it. The centre line and the normal range around it are worked out from the first 24 weeks on the chart and then held still, so every later week is measured against the same yardstick.',
                'covers' => 'Reviews finished inside the window that record both when they arrived and when they were finished. Reviews still open are left out, so a department that never finishes anything ends up with fewer dots rather than worse ones. Days are ordinary calendar days, weekends included. A week with fewer than three finished reviews is left off the chart altogether rather than drawn from too little work, which is why the reviews behind the chart add up to less than the window\'s total.',
                'why' => 'Lower is better: this is time an applicant spends waiting on one desk. The normal range is set from the earliest weeks on purpose — if a recent slowdown were allowed to help set it, it would stretch the very range meant to catch that slowdown, and the office would be measured against its own slippage.',
            ],

            'departments.status' => [
                'label' => 'Process Status Indicator',
                'formula' => 'Whether the most recent week on the chart sat inside this department\'s normal range or outside it — either past the edge of that range, or picked up by the gradual-slowdown line described below.',
                'covers' => 'The latest week only. It says nothing about the weeks before it: a department can read as normal this week and still have been outside the range for the six weeks before, which is what the flagged list underneath is for.',
                'why' => 'Outside does not mean a rule was broken. It means this week did not look like this department\'s own usual pace, which is a reason to ask why rather than a finding in itself — a holiday backlog and a real breakdown look identical here.',
            ],

            'departments.flagged' => [
                'label' => 'Flagged Weeks',
                'formula' => 'The weeks whose average sat outside the normal range, listed with how many days above or below the centre line they landed.',
                'covers' => 'Weeks on the chart only, so a week left off for having fewer than three finished reviews can never appear here however slow it was.',
                'why' => 'One odd week on its own is usually just chance; three in a quarter is a pattern. Listing them with dates lets the office match a slow stretch against something it remembers — a staff absence, a system outage, a surge — instead of guessing from a chart.',
            ],

            'departments.trend' => [
                'label' => 'Gradual Slowdown Detector',
                'formula' => 'A running average that counts the newest week most and each older week less and less, compared with the same centre line as the chart. It is called rising or easing once that running average has travelled more than half way from the centre to the edge of the normal range.',
                'covers' => 'The same weeks as the chart. The bar length is how big the move is, whichever way it went, so a department getting quickly better and one getting quickly worse both show a long bar — the word beside it is what tells them apart.',
                'why' => 'A slide of half a day a week never crosses the edge of the range and never appears in the flagged list, but a quarter of that is a whole week of added waiting. This is the panel that catches the drift no single week is bad enough to trigger.',
            ],

            'completed_reviews' => [
                'label' => 'Completed reviews',
                'formula' => 'Every departmental review finished inside the window.',
                'covers' => 'All finished reviews, including those in weeks that had too few to chart. It is deliberately larger than the number of reviews the chart draws — the gap is the work the chart could not say anything dependable about.',
                'why' => 'How much work the whole screen rests on. A chart drawn from a few dozen reviews describes those few reviews rather than the office, and this is the number that tells the reader which of the two they are looking at.',
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
                'formula' => 'Permits falling due inside the period set by the filter at the top, scored against five rules and listed worst first. Where two score the same, whichever expires sooner goes first.',
                'covers' => 'Active and expired permits whose cover ends between 60 days ago and the end of that period — recently lapsed ones are kept in, because a permit that quietly expired last month is the case most worth chasing. Revoked and suspended permits are excluded: those are enforcement outcomes, and neither is waiting to be renewed. Only the leading rows are listed, while the band counts below are over every permit scored.',
                'why' => 'The follow-up list. It is ordered by score rather than by expiry date so that a permit expiring in a fortnight with nothing filed and fees outstanding outranks one expiring next week whose renewal is already approved.',
            ],

            'at_risk.score' => [
                'label' => 'Risk score',
                'formula' => 'Points out of 100, added up across five rules. The number in brackets is the most each rule can add: how soon the permit expires (30), how far any renewal has got (25), whether this business has renewed late before (20), open compliance findings (15), and unpaid fees on the renewal (10).',
                'covers' => 'A checklist in which some rules are worth more points than others. Nothing behind it is worked out from what businesses did in the past: the register does not record whether a business ended up renewing late, so there was no past result to work from and there is no accuracy figure to quote. The number sorts a queue; it says nothing about what any one business will do.',
                'why' => 'Scoring is what lets a hundred permits be worked in the order that matters instead of by expiry date alone. Every rule and its weight is printed below the table on purpose — an officer must be able to disagree with the ranking on the merits, which they cannot do with a number whose workings are hidden.',
            ],

            'at_risk.drivers' => [
                'label' => 'Why this permit is listed',
                'formula' => 'The rules that scored above zero for this permit, heaviest first.',
                'covers' => 'Only the top few are shown, so a permit that scores on all five rules displays the three that moved it most. Two of the rules are deliberately gentle: a business in its first renewal cycle picks up half the points on the late-renewal rule rather than none, because having no history is not the same as having a clean one; and a permit with no renewal filed at all picks up nothing on fees, since the missing filing is already counted by the progress rule and would otherwise be counted twice.',
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
                'why' => 'The band that makes the other two mean something. If nearly every permit is high risk, none of them is.',
            ],

            'reminders_sent' => [
                'label' => 'Reminders Sent',
                'formula' => 'Expiry notices already issued against the permits in scope — the 60, 30 and 7 day warnings, and the renewal-due notice.',
                'covers' => 'Counted from notices actually recorded as sent, so it reads zero until the nightly permit scan has run at least once. That zero is true rather than missing: no notice has gone out. The lapse notice is not counted — it reports a change of status rather than asking anyone to renew, and counting it here would pad this figure with messages nobody was asked to act on.',
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
                'why' => 'This panel is the reason the score is allowed to exist. A number built out of several other numbers is only defensible if a reader can take it apart, and printing the points is what lets an officer say the late-renewal rule is too harsh instead of only that the ranking feels wrong.',
            ],

            'scored_permits' => [
                'label' => 'Permits scored',
                'formula' => 'All permits that fell inside the window and were put through the rules.',
                'covers' => 'The total that the three band counts are out of. It is larger than the table, which lists only the leading rows.',
                'why' => 'Stated so the band counts can be read as shares. Forty high-risk permits out of sixty is a different office from forty out of four thousand.',
            ],

            'methodology' => [
                'label' => 'Scoring method',
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
                'covers' => 'Counted from the registration date, and businesses since removed from the register are still counted — they were registered at the time, and dropping them would rewrite past periods every time one closed. When the earlier period had no registrations at all this reads as no prior period rather than as growth, because dividing by nothing is not the same as growing infinitely.',
                'why' => 'Whether the register is growing, against its own recent past rather than a target. Both plain counts are shown beside it, because on small numbers a big percentage swing is mostly chance.',
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
                'why' => 'The other half of growth. Read next to new registrations, it says whether the register is really growing or only replacing the businesses it loses.',
            ],

            'status_summary' => [
                'label' => 'Business Status Summary',
                'formula' => 'Every business ever registered, sorted into one of four states as things stand today: closed if struck off, inactive if never permitted, active if it holds a permit still in force, and expired otherwise. Shares are of those four.',
                'covers' => 'Worked out from permits, not from the status an admin sets on the business record — that answers whether an account is in good standing, which is a different question. A suspended or revoked permit is enough to make a business expired rather than inactive, but never active.',
                'why' => 'How much of the register is live, which is the figure behind any claim about coverage. The four states are checked in a fixed order, so a business can only ever land in one of them.',
            ],

            'cohort_survival' => [
                'label' => 'Cohort survival',
                'formula' => 'A cohort is simply a group of businesses followed together over time. Of those that reached a given renewal, this is the share that had come through every earlier renewal with no gap in cover, carried forward one cycle at a time. A business still inside its current permit is set aside at that point rather than counted as a lapse.',
                'covers' => 'Mayor\'s permits only, so a year in which a business also renewed its sanitary and fire clearances counts once rather than three times. Cover is treated as unbroken if the next permit starts within a day of the last one ending, and as lapsed once the gap passes 30 days. Businesses removed from the register are excluded, as are revoked and suspended permits.',
                'why' => 'It describes what this group of businesses actually did. It does not say what any business will do next. Setting aside businesses still inside their permit is the whole point: a business registered last month has had no renewal to miss, and counting it as a success would flatter the figure while counting it as a failure would be a false accusation.',
            ],

            'cohort_survival.survival' => [
                'label' => 'Cohort Survival Rate',
                'formula' => 'The share still renewing without a gap at the furthest cycle any business in the register has reached.',
                'covers' => 'The furthest cycle, which may rest on very few businesses — the number that had actually reached that point is shown alongside for exactly that reason. A register only a few years old will work this out from a handful of businesses.',
                'why' => 'One number for how well the city holds on to its businesses over time. It is the hardest figure on the screen to read at a glance, which is why the count behind it is never shown without it.',
            ],

            'top_barangays' => [
                'label' => 'Top Growing Barangays',
                'formula' => 'New registrations per barangay this period against the period before, ranked by the increase.',
                'covers' => 'Only businesses with a barangay on record; one holding several address rows is counted once for each. Ranked by the change rather than by the total, so the busiest barangay does not appear here unless it also grew, and a barangay that had no registrations at all last period is shown as a plain count rather than as a percentage rise from nothing.',
                'why' => 'Where new commercial activity is appearing, which is where inspection and outreach effort should move next. Ranking by size instead would return the same three barangays every period and say nothing.',
            ],

            'closure_trend' => [
                'label' => 'Business Closure Trend',
                'formula' => 'Registrations removed each month across the period.',
                'covers' => 'Dated by removal from the register, as above. The first month is only a part-month because the period starts mid-month, so its point sits low for a reason that has nothing to do with closures.',
                'why' => 'A single period\'s closure count cannot say whether closures are rising. The month-by-month shape can, and a spike that lines up with a renewal deadline is a different story from a steady climb.',
            ],

            'industry_growth' => [
                'label' => 'Business Industry Growth Trend',
                'formula' => 'Lines of business on record, grouped by PSIC code — the national numbering for industries — with this period\'s new registrations set against the period before, ranked by how many businesses carry that line today.',
                'covers' => 'Counted per declared line, not per business — a business declaring three lines appears under all three. Businesses removed from the register are excluded here, unlike the registration and barangay figures, because this panel describes what is trading now. The bar length is how many carry that line today, so the ranking is by size while the growing or declining word beside it is about the change — the longest bar is not necessarily the fastest growing.',
                'why' => 'What kind of city this is becoming, in the register\'s own classification. Feeds zoning and the concentration figure an applicant is shown before committing to a location.',
            ],
        ];
    }
}
