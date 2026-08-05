<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Roles + permissions matrix. Permission names match web/src/lib/mock.ts exactly
 * so the real API and the frontend agree on what each role can see and do.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        // Actor mapping (paper: Business Owner / Office Admins / Super Admin):
        //   Business Owner -> business_owner
        //   Office Admins  -> bplo_staff, sanitary_officer, fire_inspector,
        //                     zoning_officer, obo_staff, cenro_officer, market_admin
        //   Super Admin    -> admin
        // The granular roles refine the paper's "Office Admin" per office queue.
        /*
         * `application.view_all` means "may read filings other than your own".
         * It does NOT mean "may read every filing": App\Support\ApplicationVisibility
         * narrows the holder to the applications routed to their own department
         * (tester checklist item 56 — "no they cant see what theyre not included
         * in"). Only `application.view_any_office` lifts that boundary, and only
         * BPLO, the issuing office that coordinates every other office's
         * clearance, and the super admin hold it.
         */
        /*
         * `inspection.manage` is on every office that issues a clearance, and
         * that is now all six rather than the two it used to be.
         *
         * It was on `sanitary_officer` and `fire_inspector` only, on the
         * reasoning that they were the only permit types carrying
         * `requires_inspection`. That reasoning inverted the dependency: the
         * flag was false for OCCUPANCY / CEC / ZONING / MARKET *because* those
         * offices could not conduct a visit, and they could not conduct a visit
         * because the flag was false. The client broke the loop from the other
         * end — "OBO, CENRO, Market, and Zoning admins cannot approve
         * inspection. Only Sanitary and Fire has it. so basically their permits
         * also have inspections lol, not just those two" — and ReferenceSeeder
         * now sets the flag true for all six.
         *
         * Without this permission that change strands filings rather than
         * fixing anything. Every /inspections* route is behind
         * `permission:inspection.manage`, so an OBO / CENRO / CPDO / Market
         * officer could not so much as SEE the visit booked against their own
         * office; WorkflowService::recordInspection only issues once every
         * inspection on the file has passed, so the filing would sit in
         * `for_inspection` with no one but the super admin able to move it.
         * The nav entry and the /inspections routes on the web side read the
         * same permission off the profile payload, so granting it here is what
         * puts the screen in front of those four offices too.
         *
         * `zoning_officer` keeps its own entry further down because it also
         * holds `zoning.evaluate`; the other three share this list.
         */
        $review = ['application.view_all', 'application.review', 'inspection.manage',
            'permit.view_all', 'request.create', 'message.participate', 'compliance.view'];

        $matrix = [
            'business_owner' => [
                'display_name' => 'Business Owner',
                'description' => 'Applies for and manages the permits of their own businesses.',
                'permissions' => [
                    'business.manage_own', 'application.create', 'application.view_own',
                    'document.upload_own', 'payment.make', 'permit.view_own',
                    'request.respond', 'message.participate',
                ],
            ],
            /*
             * Tester checklist item 78: "the dashboard should be transferred to
             * BPLO admin, not super admin."
             *
             * `analytics.view` was written as super-admin-only on the reasoning
             * that the aggregates count every office's filings, and letting an
             * office reviewer read them would hand them a register-wide summary
             * that ApplicationVisibility deliberately keeps out of their queue.
             *
             * That reasoning never applied to BPLO. BPLO is the issuing office
             * that coordinates every other office's clearance, and it is the one
             * office role that already holds `application.view_any_office` — the
             * permission that lifts the departmental boundary. The aggregates
             * therefore expose nothing BPLO cannot already open one filing at a
             * time; they only save it the counting. The other office roles
             * (sanitary, fire, zoning, OBO, CENRO, market) still do not get it,
             * and for them the original reasoning stands unchanged.
             */
            'bplo_staff' => [
                'display_name' => 'BPLO Staff',
                'description' => 'Reviews applications, adjusts fees, and issues business permits.',
                'permissions' => [
                    'application.view_all', 'application.view_any_office',
                    'application.review', 'application.reject',
                    'fee.adjust', 'permit.view_all', 'permit.issue', 'request.create',
                    'message.participate', 'compliance.view', 'zoning.evaluate',
                    'analytics.view',
                ],
            ],
            'sanitary_officer' => [
                'display_name' => 'Sanitary Officer',
                'description' => 'Reviews sanitary requirements and conducts health inspections.',
                'permissions' => [
                    'application.view_all', 'application.review', 'inspection.manage',
                    'permit.view_all', 'request.create', 'message.participate',
                    'compliance.view',
                ],
            ],
            'fire_inspector' => [
                'display_name' => 'Fire Inspector',
                'description' => 'Reviews fire safety requirements and conducts fire inspections.',
                'permissions' => [
                    'application.view_all', 'application.review', 'inspection.manage',
                    'permit.view_all', 'request.create', 'message.participate',
                    'compliance.view',
                ],
            ],
            'obo_staff' => [
                'display_name' => 'Building Official Staff',
                'description' => 'Reviews occupancy-permit requirements for the OBO.',
                'permissions' => $review,
            ],
            'cenro_officer' => [
                'display_name' => 'CENRO Officer',
                'description' => 'Reviews environmental-certificate requirements for CENRO.',
                'permissions' => $review,
            ],
            'market_admin' => [
                'display_name' => 'Market Administrator',
                'description' => 'Reviews market-clearance requirements for the CMO Market Office.',
                'permissions' => $review,
            ],
            /*
             * The super admin OVERSEES the process; it does not work inside it.
             *
             * Four permissions came off this role, and each one was a rail entry
             * the client asked to be rid of: "In the super admin's account
             * (admin@), remove Messages, Track, Inspections, and Other
             * Requirements. It is not his role to do those things."
             *
             *   message.participate  -> Messages
             *   application.review   -> Track (the officer queue at /queue)
             *   inspection.manage    -> Inspections
             *   request.create       -> Other Requirements
             *
             * The nav filters off the permissions in the profile payload, so
             * dropping the permission is what removes the entry; there is no
             * frontend special case and there must not be one, because the API
             * routes are gated on the same four names and a hidden-but-callable
             * screen is the worse half of the bug.
             *
             * The distinction being drawn is doing versus watching. Reviewing a
             * filing, closing a site visit, asking an applicant for a document
             * and answering their message are all an OFFICE's work, and every
             * one of them belongs to an office that is accountable for it —
             * which is also what makes the audit trail mean anything. The super
             * admin's job is the register itself: accounts, reference data,
             * OIC cover, the audit log, and the Processing Time oversight
             * screen that watches the departments (including BPLO) for
             * slowdowns. Handing the overseer the same buttons as the overseen
             * is what this separation avoids.
             *
             * What it keeps and why: `application.view_all` plus
             * `application.view_any_office` still let the admin READ any
             * filing in the register — oversight needs to see everything and
             * change nothing. `permit.issue` and `fee.adjust` stay because they
             * were not asked for and are the escalation path when an office
             * cannot act. `application.reject` also stays, unasked, and is now
             * a permission with no screen behind it: rejection is driven from
             * the review queue this role no longer reaches. Left in place
             * deliberately rather than tidied away, because removing it is a
             * policy decision the client has not made.
             */
            'admin' => [
                'display_name' => 'Administrator',
                'description' => 'Super admin: oversight of the register — accounts, reference data, audit, and processing-time monitoring.',
                'permissions' => [
                    'application.view_all', 'application.view_any_office',
                    'application.reject',
                    'fee.adjust', 'permit.view_all', 'permit.issue',
                    'compliance.view',
                    /*
                     * The super admin holds `analytics.processing_time` and NOT
                     * `analytics.view`, which reads like a mistake and is not.
                     *
                     * "R INTEGRATION DRAFTS" assigns each analytics feature an
                     * owner in its own heading: §1 Analytics Dashboard (Admin -
                     * BPLO), §2 Renewal Risk Prediction (Admin - BPLO), §4
                     * Business Growth Analysis (Admin - BPLO), and §6 Permit
                     * Processing Time Monitoring (Super Admin). Three screens
                     * belong to BPLO and exactly one to the super admin, and the
                     * client confirmed it in those words: "BPLO side should only
                     * have the 3 dashboards (Processing Time should not exist
                     * here) — Super admin side should only have Processing Time
                     * dashboard."
                     *
                     * That is a real separation of duties rather than a display
                     * preference. The three BPLO screens are operational: they
                     * count filings, rank businesses by renewal risk and drive
                     * follow-up. Processing Time is oversight — it watches the
                     * DEPARTMENTS, including BPLO itself, for genuine slowdowns.
                     * Handing the office being measured the same view as the
                     * office measuring it is what this split avoids.
                     */
                    'analytics.processing_time', 'zoning.evaluate', 'user.manage',
                    'owner.manage_status', 'oic.assign', 'reference.manage', 'audit.view',
                ],
            ],
            /*
             * Tester checklist item 75: zoning had no `request.create`, so the
             * one office that most often needs a missing sketch or lot plan was
             * the only one that could not ask for it. That was an oversight, not
             * a policy — the role already approves and returns its own
             * assignment via application.review, so it was never "view-only",
             * and without the request its only recourse to one missing document
             * was to return the entire filing.
             */
            'zoning_officer' => [
                'display_name' => 'Zoning Officer',
                'description' => 'Reviews zoning/locational clearance for the CPDO.',
                'permissions' => [
                    'application.view_all', 'application.review', 'zoning.evaluate',
                    // Locational clearance is a statement about a site, so CPDO
                    // conducts a visit like the other five clearance offices —
                    // see the note on `$review` above.
                    'inspection.manage',
                    'permit.view_all', 'request.create', 'message.participate',
                    'compliance.view',
                ],
            ],
        ];

        // Create every distinct permission once.
        $allPerms = collect($matrix)->pluck('permissions')->flatten()->unique();
        foreach ($allPerms as $name) {
            Permission::updateOrCreate(['name' => $name]);
        }

        foreach ($matrix as $roleName => $def) {
            $role = Role::updateOrCreate(['name' => $roleName], [
                'display_name' => $def['display_name'],
                'description' => $def['description'],
            ]);
            $ids = Permission::whereIn('name', $def['permissions'])->pluck('id');
            $role->permissions()->sync($ids);
        }
    }
}
