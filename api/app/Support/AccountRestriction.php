<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;

/**
 * Is this account barred, what from, and where does the reader take it?
 *
 * ── One answer, read by three different things ───────────────────────────
 *
 * The screen raises a modal from it, the navigation hides everything it bars,
 * and `EnforceAccountRestriction` refuses the requests behind what it hid. All
 * three have to agree or the product lies: a rail that still offers Renew, on
 * a page that 403s, is worse than either on its own.
 *
 * So the rule lives here once and the three read it, rather than each deciding
 * for itself what "restricted" means — which is how the dashboard came to show
 * a suspension pop-up over a system that let the same owner file anyway.
 *
 * ── One finding bars the account: a blacklisting ─────────────────────────
 *
 * A BLACKLISTING is against the person. It reaches every business they hold
 * and every business they register afterwards.
 *
 * A SUSPENSION is against one premises, and holds that premises only. Ken,
 * 5 October 2026: *"business suspension should never affect the entirety of
 * the account."* From 30 September until then a suspension barred the whole
 * account [client: *"di accessible dapat maayos muna yung pagka suspend o
 * blacklisted nya"*], so an owner with one suspended shop could not work on
 * the others. What it still holds is the business's own: its filings stay on
 * hold (`Business::filingsOnHoldReason`) and it cannot start a new one
 * (`Business::isBlockedFromApplying`). Neither of those reads the user.
 *
 * ── What is deliberately NOT a restriction ───────────────────────────────
 *
 * `Business::hasSuspendedPermit()` — a certificate suspended because another
 * office refused a clearance. That has a fix the owner can make themselves,
 * two screens away, and `isBlockedFromApplying()` already stops the filing it
 * should stop. Locking the account for it would shut the door the owner is
 * meant to walk out through. A suspended business is not one either, now.
 */
final class AccountRestriction
{
    /**
     * The restriction on this account, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public static function for(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        /*
         * Only a business owner can hold one of these. An officer has no
         * businesses and is never blacklisted, so the query below would find
         * nothing — but asking it on every officer request, on every screen,
         * to learn that, is a round trip for a foregone conclusion.
         */
        if (! $user->hasPermission('business.manage_own')) {
            return null;
        }

        /*
         * A business standing at `blacklisted` still bars its holder, as it
         * did before suspensions were taken out: blacklisting one business
         * blacklists its owner (`BusinessStatusController::carryToTheOwner`),
         * so the status is the person's finding wherever it is found.
         */
        $businesses = $user->businesses()->get(['id', 'status']);
        $blacklisted = $user->isBlacklisted()
            || $businesses->contains(fn (Business $b) => $b->status === Business::STATUS_BLACKLISTED);

        if (! $blacklisted) {
            return null;
        }

        return [
            'kind' => 'blacklisted',
            'business_name' => null,
            // A blacklisting is not about one shopfront, so quoting one
            // business's number would invite a call about that shop and an
            // answer that the finding is not against it.
            'reference_id' => null,
            'covers' => $businesses->count(),
            /*
             * The general enquiry: the conversation that exists without a
             * filing behind it. It is the right one precisely because the
             * finding is against the person, and because a blacklisted
             * owner may have no filing to hang the question on.
             */
            'conversation' => ['application_id' => null],
        ];
    }

    /** True when the account may reach nothing but its messages and notices. */
    public static function bars(?User $user): bool
    {
        return self::for($user) !== null;
    }
}
