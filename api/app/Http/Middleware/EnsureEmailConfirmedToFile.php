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
 * On the SUBMIT route only. Drafting, uploading and saving stay open, so a new
 * owner can fill the whole application while the code is on its way; only
 * handing it in waits. Tester item 99 is why it is here and not a banner on
 * every page: that banner claimed verification was required when nothing
 * enforced it. Now something does, at the one action it guards, and the wizard
 * shows the code box right there when this answers.
 *
 * Resubmitting a returned filing is NOT gated. That filing was handed in before
 * the rule existed, an office is waiting on it, and holding it back over an
 * address would stall a case the City already has.
 *
 * With mail off this does nothing at all. A gate on a code nobody can receive
 * would lock every owner out of filing.
 *
 * 403 with `reason: email_unconfirmed` so the wizard can tell this apart from
 * every other refusal and offer the code instead of an error.
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
