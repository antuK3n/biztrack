<?php

/*
 * LGU settings that are NOT in the revenue code.
 *
 * Everything BizTrack charges comes from `fee_rules`, seeded out of Ordinance
 * A10-2016 with a section reference on every row — because a figure a business
 * pays should be traceable to the text that authorises it. This file is for the
 * handful of amounts the LGU sets that the ordinance does not, and it is
 * deliberately separate so that nothing here can be mistaken for a rule with a
 * section behind it.
 */

return [

    /*
     * What BPLO charges to amend a business permit.
     *
     * ── Zero because it is unknown, not because it is free ─────────────────
     *
     * Searched and verified 19 September 2026: none of the 423 seeded fee rules
     * mentions an amendment, and neither does the 4,330-line extract of the
     * ordinance itself (`docs/revenue-code-extract.md`) — its four "amend" hits
     * are an amended zoning ordinance, the NIRC "as amended", and a chapter
     * heading about the hospital revenue code.
     *
     * The LGU's own Amendment Form nonetheless says "AFTER PAYMENT, PLEASE
     * RETURN THIS FORM AND OTHER REQUIREMENTS TO THE BPLO WINDOW", so the
     * counter collects something. Three readings, and they need different
     * answers rather than a guess:
     *
     *   1. it is in the printed ordinance and the extract missed it;
     *   2. it is levied under the general power (extract, PDF p. 222: the city
     *      may levy "on any base or subject not otherwise specifically
     *      enumerated herein"), set administratively;
     *   3. the payment on the form IS the reissued permit rather than a
     *      separate amendment charge — in which case zero is already correct.
     *
     * A setting rather than a constant in `WorkflowService` so the answer, when
     * it comes, is a value and not a code change. NOT a `fee_rules` row: that
     * table is the ordinance, every row carries the section it came from, and
     * inventing one here would manufacture a provenance this fee does not have.
     *
     * Whatever is set, it is carried to the January business-permit renewal
     * with the deferred clearance fees rather than billed on the spot — client,
     * 19 September 2026.
     */
    'amendment_fee' => (float) env('BIZTRACK_AMENDMENT_FEE', 0),

    /*
     * When a CLEARANCE may be renewed — how early, and how late.
     *
     * Both null, which is exactly what the system does today: no bound at
     * either end. Searched 1 October 2026 and there was no rule anywhere —
     * `renewablePermits` offers every active and expired permit with no date
     * filter, so an applicant can renew eleven months early or resurrect a
     * permit that lapsed years ago, and nothing says otherwise.
     *
     * That is a default nobody chose rather than a decision, and the LGU has
     * not been asked yet. Null keeps today's behaviour so that turning a
     * limit on is a value here and never a code change; see
     * App\Support\RenewalWindow for what each bound means, why the
     * business permit is exempt, and what the applicant is told.
     *
     * `opens_days_before` — 30 would match the first expiry reminder,
     * 60 the worked example in WorkflowService::issuePermitFor.
     *
     * `closes_months_after` — the one worth asking BPLO first. With the late
     * surcharge now live, a permit renewed five years on bills the Sec.
     * 8A.05 36-month interest cap, and whether the city wants that as a
     * renewal at all is a policy question with money attached.
     */
    'renewal_window' => [
        /*
         * 30 days — one month before a clearance expires.
         *
         * ── It was tried once and reverted, and the reason has since gone ─
         *
         * Set to 30 on 1 October 2026 and taken out the same day, because
         * FullLifecycleThenRenewalTest refused a filing the system was then
         * built to accept: six permits expiring 15, 60, 105, 150, 195 and
         * 240 days out, renewed as ONE subset. The note here read "a 30-day
         * window turns that one filing into five", and that was true.
         *
         * It stopped being true on 3 October, when the client ruled that a
         * renewal carries the business permit or ONE other permit (see
         * `App\Support\RenewalScope`). Those six staggered clearances are
         * five separate filings now whatever this value is, so the window
         * costs the applicant nothing it was not already costing them.
         *
         * ── What it buys, which the old note was right to doubt ──────
         *
         * Not revenue and not safety: the term still CONTINUES
         * (`issuePermitFor`) so no paid-for day is lost, and the premises
         * are inspected either way. What it buys is the picker telling the
         * truth. Client, 3 October 2026, on a screenshot of five permits
         * valid for another year, every one of them tickable under a line
         * reading "No renewal needed": *"valid permits should NOT BE
         * RENEWED until their renewal time window is open."*
         *
         * 30 rather than 60 because `ScanPermits::THRESHOLDS` sends its
         * first reminder at 30 days. One number, so the day the applicant
         * is told to renew is the day they first can — today that reminder
         * arrives long after renewal was already possible, which is
         * backwards.
         *
         * Still an env override, so the LGU can move it without a deploy.
         */
        'opens_days_before' => env('BIZTRACK_RENEWAL_OPENS_DAYS_BEFORE', 30),
        /*
         * 36 months, and the number is Sec. 8A.05's, not a round guess.
         *
         * The obvious cutoff is one lapsed term, on the reasoning that a
         * stale permit should be forced through a fresh inspection. That
         * reasoning does not apply here: `ClearanceService::submitHeld` does
         * NOT skip the inspection on a renewal — client, 6 September 2026,
         * *"the LGU inspects the premises, not the paperwork"* — so the
         * premises are visited either way and a tighter bound would buy no
         * safety, only a longer form.
         *
         * What is left is deterrence. Interest accrues at 2% a month and
         * stops at 36 months, so up to that point every further month of
         * delay costs more and renewal is still discouraging lateness. Past
         * it, delay is free at the margin. That is the point where renewal
         * stops deterring anything and a New Application — full documents,
         * BPLO reading the form, billed at submission instead of deferred —
         * should take over.
         */
        'closes_months_after' => env('BIZTRACK_RENEWAL_CLOSES_MONTHS_AFTER', 36),
    ],

    /*
     * The read-only PostgreSQL role ODBC reporting tools sign in as — the one
     * `php artisan biztrack:report-role-sql` creates (docs/odbc.md). Named here
     * because a migration run re-creates the report views, and a re-created
     * view has lost every grant the old one carried: App\Support\ReportViews
     * grants this role SELECT on them again afterwards. Change it only if the
     * role was created under another name.
     */
    'report_role' => env('DB_REPORT_ROLE', 'biztrack_report'),

    /*
     * ── Letterheads the certificates print ──────────────────────────────────
     *
     * CENRO's Certificate of Environment Clearance heads with the office's
     * own address, trunkline, e-mail and website, read off the issued sheet
     * [client, 4 October 2026]. `departments` carries a code, a name and a
     * description and nothing postal, so the letterhead lives here rather
     * than as a literal split across the React view and the PDF blade —
     * one place to correct when the office moves floor or changes its
     * extension, and both renderings read it.
     *
     * Keyed by department code. An office with no entry prints the generic
     * three-line city block instead.
     */
    'letterheads' => [
        'CENRO' => [
            'address' => '4/F, Malabon City Hall, F. Sevilla Blvd., Brgy. Tañong, Malabon City, 1470 Philippines',
            'trunkline' => '8281-4999 loc 1019 / 1013',
            'email' => 'CENRO@malabon.gov.ph',
            'website' => 'Malabon.gov.ph',
        ],
    ],

];
