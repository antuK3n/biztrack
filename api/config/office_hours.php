<?php

/*
 * When City Hall is open, for the out-of-office-hours notice [checklist,
 * Login 6, 2026-09-27].
 *
 * Read by App\Support\OfficeHours, served to the web app by
 * GET /api/v1/office-hours, and never worked out again in a component: the
 * browser's clock belongs to the reader, not the City, and a laptop left on
 * another timezone would otherwise tell an owner the offices are open at
 * midnight.
 *
 * Monday to Friday, 08:00–17:00 Manila time is the ordinary LGU day. If BPLO
 * keeps different hours (a Saturday window in renewal season, say), this file
 * is where it changes. Nothing else needs to.
 */

return [

    'timezone' => env('OFFICE_HOURS_TIMEZONE', 'Asia/Manila'),

    // ISO-8601 day numbers: 1 = Monday … 7 = Sunday.
    'days' => [1, 2, 3, 4, 5],

    'opens' => env('OFFICE_HOURS_OPEN', '08:00'),

    'closes' => env('OFFICE_HOURS_CLOSE', '17:00'),

    /*
     * Public holidays, as 'YYYY-MM-DD' dates in the timezone above.
     *
     * EMPTY on purpose. The Philippine list is set each year by proclamation
     * and several dates move (Holy Week, Eid, the last Monday of August,
     * special non-working days declared at short notice). A list typed in here
     * from memory would be wrong somewhere, and nobody would notice until an
     * owner was told the offices were open on a holiday. Weekends are handled;
     * add each year's proclaimed dates here once BPLO confirms them
     * (docs/questions-for-malabon.md). Nothing else in the register models
     * holidays either — see the note in Ra11032.
     */
    'holidays' => [],

];
