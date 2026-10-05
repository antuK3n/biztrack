<?php

namespace App\Http\Middleware;

use App\Support\EmailSwitch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * No filing until the owner has confirmed their e-mail address — while mail
 * is on [checklist 2026-09-27, Register 1].
 *
 * A guard, no longer the place the code is asked. Since 6 October 2026 sign-up
 * asks for the code before it hands out a session (AuthController::register,
 * Ken: "it shouldn't be on the application, but when signing up"), and an
 * owner who closes that tab is sent a code at their next sign-in, so an owner
 * reaching Submit is normally confirmed already. The wizard's own code box went
 * with that change, and sign-in asks an unconfirmed owner for the code
 * whenever mail is on. This stays for whoever is not: a session held from
 * before those. They are refused with the sentence below and confirm from
 * Profile.
 *
 * On the SUBMIT route only. Drafting, uploading and saving stay open. Tester
 * item 99 is why it is here and not a banner on every page: that banner
 * claimed verification was required when nothing enforced it.
 *
 * Resubmitting a returned filing is NOT gated. That filing was handed in before
 * the rule existed, an office is waiting on it, and holding it back over an
 * address would stall a case the City already has.
 *
 * With mail off this does nothing at all. A gate on a code nobody can receive
 * would lock every owner out of filing.
 *
 * 403 with `reason: email_unconfirmed`, kept so a client can tell this apart
 * from every other refusal.
 */
class EnsureEmailConfirmedToFile
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (EmailSwitch::on() && $user && ! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Confirm your email address before you file. Enter the 6-digit code we sent you.',
                'reason' => 'email_unconfirmed',
            ], 403);
        }

        return $next($request);
    }
}
