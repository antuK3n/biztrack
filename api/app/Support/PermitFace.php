<?php

namespace App\Support;

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;

/**
 * The business details printed on a certificate — built once, in one place.
 *
 * ── Two doors, one builder ─────────────────────────────────────────────────
 *
 * These five facts are needed at two different moments: when a permit is
 * ISSUED, to be frozen onto the row, and when its PDF is RENDERED, for the
 * permits issued before freezing existed. `PermitController::certificateData`
 * built them inline, which was fine while there was one caller and is exactly
 * how this codebase has repeatedly ended up with a rule in one of two doors.
 *
 * So the builder lives here and both callers ask it. A field added to the face
 * appears in the snapshot and on the page together, or not at all.
 *
 * ── Why a permit is a snapshot and not a view ─────────────────────────────
 *
 * The PDF used to be assembled from the live business record every time it was
 * downloaded, which meant editing a business rewrote every certificate it had
 * ever held. The full argument is in the migration that added
 * `permits.issued_details`; the short version is that a Sanitary Permit is
 * CHO's statement about premises CHO inspected, and its face must not quietly
 * start describing somewhere else.
 */
class PermitFace
{
    /**
     * The keys a snapshot carries. Order is the certificate's own.
     *
     * Kept as a constant so `changedSince()` can compare exactly these and not
     * whatever a future `capture()` happens to return — a comparison that
     * silently widens is how a stale-details flag starts firing on a corrected
     * typo in an emergency contact.
     */
    public const KEYS = [
        'business_name',
        'trade_name',
        'owner_name',
        'address',
        'barangay',
        'city',
        'line_of_business',
    ];

    /**
     * Who signed it — the City Mayor, and the officer who issued it.
     *
     * ── Separate from KEYS, and that is the whole point ──────────────────────
     *
     * These are frozen with the rest of the face, for the same reason: a
     * certificate names the people who signed it, and a mayor leaving office
     * must not retroactively re-sign every permit the city has ever issued.
     *
     * They are NOT in `KEYS`, because `changedSince()` walks that list to tell
     * an office which printed details no longer match the register. That
     * question is about the BUSINESS. Put the signatories in it and the next
     * mayor flags every live permit in the city as having drifted, which is
     * true of the paper and useless as a signal.
     */
    public const SIGNATORY_KEYS = [
        'mayor_name',
        'officer_in_charge',
    ];

    /**
     * The City Mayor, as the certificate prints it.
     *
     * One constant rather than a column, because the LGU has one mayor at a
     * time and a permit freezes the name at signature anyway — so the only
     * thing a table would buy is somewhere for the name to go stale
     * independently of the permits carrying it.
     *
     * "Hon." is the Philippine civic form of address and belongs to the name
     * rather than to the role line beneath it, which reads "City Mayor".
     */
    public const MAYOR = 'Hon. Jeannie Sandoval';

    /**
     * What is true of this business right now.
     *
     * @return array<string, string|null>
     */
    public static function capture(?Business $business): array
    {
        $address = $business?->address;

        return [
            // Null, not '', so a reader can tell "removed from the register"
            // from "never named".
            'business_name' => $business?->name,
            'trade_name' => $business?->trade_name,
            'owner_name' => $business?->owner?->fullName(),
            'address' => $address?->line1,
            'barangay' => $address?->barangay?->name,
            'city' => $address?->city,
            // Every declared line, joined: a permit face lists the activities
            // it covers, and a business may carry more than one.
            'line_of_business' => $business?->lines
                ->map(fn ($l) => $l->psicCode?->title)
                ->filter()
                ->implode(', ') ?: null,
        ];
    }

    /**
     * The face to print: the frozen one, or the live one for older permits.
     *
     * The 8 permits issued before `issued_details` existed cannot be given a
     * truthful snapshot after the fact — what their business looked like on the
     * day is not recoverable — so they keep rendering live and behave exactly
     * as they did. New permits print what was signed.
     *
     * @return array<string, string|null>
     */
    public static function forPrinting(Permit $permit): array
    {
        $frozen = $permit->issued_details;

        if (is_array($frozen) && $frozen !== []) {
            // Filled through KEYS rather than returned as stored, so a snapshot
            // written before a key was added renders that key blank instead of
            // the view reaching for an index that is not there.
            $face = collect(self::KEYS)
                ->mapWithKeys(fn (string $key) => [$key => $frozen[$key] ?? null])
                ->all();

            /*
             * The signatories, with a fallback that is honest about what it
             * knows. Every permit issued before this existed has no frozen
             * mayor, and the only truthful answer for it is the mayor now —
             * the alternative is a certificate with a blank where a signature
             * belongs.
             *
             * The officer is re-derived the same way it would have been frozen
             * rather than read off `issued_by_user_id`, which for every
             * Business Permit is the applicant who paid: the certificate is
             * released at payment, so the user on that column is not an
             * officer at all. Re-deriving reads the assignment the filing
             * still carries, and answers null once that filing is gone.
             */
            return $face + [
                'mayor_name' => $frozen['mayor_name'] ?? self::MAYOR,
                'officer_in_charge' => $frozen['officer_in_charge']
                    ?? self::officerInChargeFor($permit->application, $permit->permitType)?->fullName(),
            ];
        }

        return self::capture($permit->business)
            + self::captureSignatories(self::officerInChargeFor($permit->application, $permit->permitType));
    }

    /**
     * Who signs this certificate, at the moment it is signed.
     *
     * Null officer is a real state, not a bug: a permit minted by a seeder, by
     * a scheduled job, or off a filing no office has taken yet has nobody to
     * name, and a ruled line is better than a guess.
     *
     * @return array<string, string|null>
     */
    public static function captureSignatories(?User $officer): array
    {
        return [
            'mayor_name' => self::MAYOR,
            'officer_in_charge' => $officer?->fullName(),
        ];
    }

    /**
     * The officer in charge of a permit: the one holding the ISSUING office's
     * assignment on the filing behind it.
     *
     * ── Not the signed-in user, and that was the first attempt ──────────────
     *
     * Reading `Auth::user()` at issuance looks right and is wrong on the
     * certificate that matters most. The Business Permit is released at
     * PAYMENT [LGU, 24 September 2026 — see PermitReleasedAtPaymentTest], and
     * the act that releases it is the applicant's. So the Mayor's Permit would
     * have been signed "Officer-in-Charge: <the business owner>", which is not
     * a wrong name so much as a different kind of document.
     *
     * The officer in charge is already a first-class thing in this system —
     * `application_assignments.officer_user_id`, the row the Manage
     * Officer-in-Charge screens assign and reassign — so the certificate names
     * that person for the office that issues it. On a clearance that is the
     * reviewer who passed it; on the Mayor's Permit it is BPLO's officer on
     * the filing, which is who the applicant dealt with.
     *
     * Latest assignment wins. A filing can be routed to one office more than
     * once (a re-routing after an amendment), and the one that issued this
     * permit is the one standing when it was issued.
     *
     * ── And the second place it looks, which is not a guess ─────────────────
     *
     * `officer_user_id` is nullable, and on a filing nobody has claimed it IS
     * null: an office's queue holds unassigned work and `assignOfficer` is a
     * deliberate act. Stopping at the assignment therefore left the signature
     * blank on most Business Permits, which is the opposite of what the client
     * asked for.
     *
     * So when no one holds the case, it falls to whoever CLASSIFIED the filing
     * for that office. That is not an arbitrary second choice: classification
     * is a required officer act — the workflow refuses to approve a filing
     * nobody has categorised — so on the Business Permit it names the BPLO
     * officer who actually processed it. The department check keeps it honest;
     * a classifier from another office is not this certificate's officer and
     * the line stays blank instead.
     *
     * Deliberately NOT `Auth::user()`. This is read at print time as well as
     * at issue, and reading the session there would print the name of whoever
     * opened the certificate — the exact bug `owner_name` already carries a
     * comment about.
     */
    public static function officerInChargeFor(?Application $app, ?PermitType $type): ?User
    {
        $departmentId = $type?->issuing_department_id;

        if ($app === null || $departmentId === null) {
            return null;
        }

        $assigned = ApplicationAssignment::where('application_id', $app->id)
            ->where('department_id', $departmentId)
            ->whereNotNull('officer_user_id')
            ->latest('id')
            ->first()
            ?->officer;

        if ($assigned !== null) {
            return $assigned;
        }

        $classifier = $app->complexitySetBy;

        return $classifier?->department_id === $departmentId ? $classifier : null;
    }

    /**
     * The signature block, in the order a certificate prints it.
     *
     * ── Why this moved out of the two views ─────────────────────────────────
     *
     * The React page and the dompdf blade each carried their own fallback
     * block — `[['City Mayor', null], ['Officer-in-Charge', null]]`, written
     * twice — and each said in a comment that a name in code would be a
     * forgery. That was right while the system had no record of who signed.
     * It does now, so the names come from the permit, and the two literals
     * collapse into this one list that both views render.
     *
     * The client's instruction of 1 October 2026 — *"include na rin don name
     * of mayor (Jeannie Sandoval) and officer in charge, sa lahat na yan ng
     * permits"* — is what overrules the old stance, and only for these two
     * roles. Everything the offices configure themselves still comes from
     * `office_signatories`; this does not replace that table, it sits above it.
     *
     * A null name still prints as a ruled line, which is the old behaviour and
     * the honest one: a permit minted with no signed-in officer behind it has
     * nobody to name, and a blank line is a document waiting for a wet
     * signature.
     *
     * @param  array<string, string|null>  $face  the output of `forPrinting`
     * @return list<array{role: string, name: string|null}>
     */
    public static function signatureBlock(array $face): array
    {
        return [
            ['role' => 'City Mayor', 'name' => $face['mayor_name'] ?? null],
            ['role' => 'Officer-in-Charge', 'name' => $face['officer_in_charge'] ?? null],
        ];
    }

    /**
     * Which printed details no longer match the register.
     *
     * This is the durable half of telling an office that a business has been
     * amended. A notification is read once and then gone; this is computable
     * for as long as the certificate exists, so "your Sanitary Permit was
     * issued for a different address" still answers itself in two years.
     *
     * Empty for a permit with no snapshot: an older permit renders live, so its
     * face cannot disagree with the register by construction. Saying "nothing
     * has changed" about it would be a guess dressed as a fact.
     *
     * @return array<string, array{was: string|null, now: string|null}>
     */
    public static function changedSince(Permit $permit): array
    {
        $frozen = $permit->issued_details;
        if (! is_array($frozen) || $frozen === []) {
            return [];
        }

        $now = self::capture($permit->business);
        $drift = [];

        foreach (self::KEYS as $key) {
            $was = $frozen[$key] ?? null;
            if (($now[$key] ?? null) !== $was) {
                $drift[$key] = ['was' => $was, 'now' => $now[$key] ?? null];
            }
        }

        return $drift;
    }
}
