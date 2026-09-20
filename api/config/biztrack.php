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

];
