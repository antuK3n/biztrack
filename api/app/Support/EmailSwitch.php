<?php

namespace App\Support;

/**
 * Whether BizTrack can actually deliver e-mail, and so whether anything that
 * DEPENDS on e-mail arriving is allowed to switch on.
 *
 * ── The one place this is decided ──────────────────────────────────────────
 *
 * Three features need a code to reach somebody's inbox: confirming the address
 * at sign-up (asked before the new owner is signed in), the sign-in code
 * every account is asked for after its password, and the resend buttons behind
 * both. (A fourth came later: the code a password change in Settings needs.)
 * Each would lock people out if it ran while mail goes nowhere: a sign-in
 * code written to `laravel.log` is a sign-in nobody can finish. So they ask
 * this, and nothing else, before they act. [Ken, checklist 2026-09-27: "build
 * email features fully, but they switch ON only when a real mailer is
 * configured".]
 *
 * ── What counts as "on" ─────────────────────────────────────────────────────
 *
 * Any default mailer except `log` and `array`. Those two accept a message and
 * deliver it to no one: `log` writes it to the log (the shipped default, and
 * the tester demo today), `array` keeps it in memory (the test suite).
 * Everything else — smtp for Brevo, ses, postmark, a failover — is somebody's
 * real attempt to send, and is treated as one.
 *
 * Deliberately NOT "are the Brevo keys filled in". The mailer is the switch an
 * operator already turns in docs/email-setup.md §2. A second flag would be a
 * way for the two to disagree, and the cost of that disagreement is every
 * account locked behind a code that was never sent.
 *
 * If the mailer is on but broken (wrong key, quota spent), the features stay
 * on and say so at the point of sending — see AuthController. That is an
 * outage, not a configuration, and guessing at it here would turn a broken
 * relay into silently weaker sign-in.
 */
class EmailSwitch
{
    /** Transports that accept a message and deliver it to nobody. */
    private const NOWHERE = ['log', 'array'];

    public static function on(): bool
    {
        return ! in_array((string) config('mail.default'), self::NOWHERE, true);
    }
}
