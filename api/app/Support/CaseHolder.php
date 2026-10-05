<?php

namespace App\Support;

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Department;
use App\Models\User;

/**
 * Who may open and who may work an office's case — one answer for every door.
 *
 * ── The rule, since 6 October 2026 ───────────────────────────────────────
 *
 * Request of that day, for the offices other than BPLO: *"The officer must
 * click 'Assign to Me' before they can access and process the application.
 * Once assigned, other officers can only view the application."*
 *
 *  - An UNHELD case of one of those offices can be neither opened nor worked:
 *    the queue offers Assign to Me and nothing else.
 *  - A HELD case is worked by its holder alone. Colleagues in the office may
 *    open it and read it.
 *
 * BPLO keeps the rule it had, because the request named the other offices:
 * an unheld case is open to the office, and acting on it claims it
 * (`AssignmentController::recordHolder`) — "an office of one should not have
 * to press Claim to be allowed to do its job". Widening this to BPLO is
 * `requiresClaim()` and nothing else.
 *
 * Before this class each door asked its own version of the question — the
 * approve/return actions, the business-field edits, the office sheet, the
 * requests, the inspections, the messages — and they did not agree. Every one
 * of them now asks here, and the review page's `can_act` is `mayAct()`, so a
 * button the screen offers is a button the API accepts.
 */
final class CaseHolder
{
    /** Refusal for an unheld case of an office that requires a claim. */
    public const UNCLAIMED = 'Assign this application to yourself first.';

    /** Refusal for a case another officer holds — the wording it always had. */
    public const HELD_ELSEWHERE = 'This filing is with another officer. Only the Super Administrator can move it.';

    /** Must this office's case be claimed before it is opened or worked? */
    public static function requiresClaim(ApplicationAssignment $assignment): bool
    {
        return self::claimRequiredFor($assignment->department_id);
    }

    /** Does this office work only what it has claimed? Every office but BPLO. */
    public static function claimRequiredFor(?int $departmentId): bool
    {
        return $departmentId !== null && $departmentId !== self::bploId();
    }

    /** May this user work the case — approve, return, edit, book, write? */
    public static function mayAct(?User $user, ApplicationAssignment $assignment): bool
    {
        return self::refusal($user, $assignment) === null;
    }

    /**
     * Why this user may not work the case, or null when they may.
     *
     * Assumes the office boundary was already checked by the caller; a user of
     * another office is refused here too, but with the generic sentence.
     */
    public static function refusal(?User $user, ApplicationAssignment $assignment): ?string
    {
        if ($user === null || $user->department_id === null || $user->department_id !== $assignment->department_id) {
            return 'This assignment belongs to another department.';
        }

        if ($assignment->officer_user_id === $user->id) {
            return null;
        }

        if ($assignment->officer_user_id !== null) {
            return self::HELD_ELSEWHERE;
        }

        return self::requiresClaim($assignment) ? self::UNCLAIMED : null;
    }

    /**
     * May this user open the case at all? Held cases are readable by the whole
     * office; an unheld one only where no claim is required (BPLO).
     */
    public static function mayOpen(?User $user, ApplicationAssignment $assignment): bool
    {
        if ($user === null || $user->department_id === null || $user->department_id !== $assignment->department_id) {
            return false;
        }

        return $assignment->officer_user_id !== null || ! self::requiresClaim($assignment);
    }

    /**
     * Refuse unless this user may work their own office's case on this filing.
     *
     * For the doors that start from an APPLICATION rather than an assignment —
     * requests, inspections, messages. A user whose office has no case on the
     * filing (the super admin, or BPLO raising on another office's behalf) is
     * not judged here; those doors keep their own office checks.
     */
    public static function authorizeOn(?User $user, Application $application): void
    {
        $review = self::caseOf($user, $application);

        if ($review !== null) {
            $refusal = self::refusal($user, $review);
            abort_if($refusal !== null, 403, (string) $refusal);
        }
    }

    /** This user's office's case on the filing, or null. */
    public static function caseOf(?User $user, Application $application): ?ApplicationAssignment
    {
        if ($user === null || $user->department_id === null) {
            return null;
        }

        return $application->assignments()
            ->where('department_id', $user->department_id)
            ->orderBy('id')
            ->first();
    }

    private static function bploId(): ?int
    {
        // `once`: one lookup per request, forgotten between tests (which
        // reseed departments) — unlike a static, which would outlive both.
        return once(fn () => Department::where('code', 'BPLO')->value('id'));
    }
}
