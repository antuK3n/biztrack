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
 * ── The two findings, and which one wins ─────────────────────────────────
 *
 * A BLACKLISTING is against the person. It reaches every business they hold
 * and every business they register afterwards.
 *
 * A SUSPENSION is against one premises. The client has asked for it to bar the
 * whole account all the same [30 September 2026: *"di accessible dapat maayos
 * muna yung pagka suspend o blacklisted nya"*], which is a change from the
 * 27 September position that a suspension leaves the owner's other businesses
 * alone. The wider reading is the one implemented; the copy that told them
 * otherwise has gone with it.
 *
 * Blacklisting is reported when both are true. It is the heavier finding, and
 * the advice differs: a suspension points at one shopfront's own conversation,
 * a blacklisting at the general enquiry, because the finding is not about any
 * one business.
 *
 * ── What is deliberately NOT a restriction ───────────────────────────────
 *
 * `Business::hasSuspendedPermit()` — a certificate suspended because another
 * office refused a clearance. That has a fix the owner can make themselves,
 * two screens away, and `isBlockedFromApplying()` already stops the filing it
 * should stop. Locking the account for it would shut the door the owner is
 * meant to walk out through.
 */
final class AccountRestriction
{
    /** The statuses on a BUSINESS that bar its owner. */
    private const BARRED = ['suspended', Business::STATUS_BLACKLISTED];

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

        $businesses = $user->businesses()->get(['id', 'name', 'ban', 'status']);

        if ($user->isBlacklisted()) {
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

        $suspended = $businesses->first(fn (Business $b) => in_array($b->status, self::BARRED, true));

        if ($suspended === null) {
            return null;
        }

        return [
            'kind' => 'suspended',
            'business_name' => $suspended->name,
            'reference_id' => $suspended->ban,
            'covers' => $businesses->count(),
            'conversation' => ['application_id' => self::conversationFor($suspended)],
        ];
    }

    /** True when the account may reach nothing but its messages and notices. */
    public static function bars(?User $user): bool
    {
        return self::for($user) !== null;
    }

    /**
     * The filing whose conversation a suspended business's owner is sent to.
     *
     * "Sa business na acc nya diba may kanya kanyang convo kada business"
     * [client, 30 September 2026] — each business has its own conversation, and
     * that is the one to open, not the general enquiry a blacklisting opens.
     *
     * A conversation belongs to an APPLICATION rather than to a business, so
     * "this business's conversation" is its newest filed application: the one
     * whose offices are currently reviewing it, and the one the suspension is
     * most likely to concern.
     *
     * Null when the business has never filed. There is then no conversation to
     * open, and the caller falls back to the general enquiry — a door that is
     * always there, which is why it exists.
     */
    private static function conversationFor(Business $business): ?int
    {
        return $business->applications()
            ->where('status', '!=', 'draft')
            ->latest('id')
            ->value('id');
    }
}
