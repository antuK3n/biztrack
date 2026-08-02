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
}
