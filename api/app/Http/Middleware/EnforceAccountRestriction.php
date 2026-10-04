<?php

namespace App\Http\Middleware;

use App\Support\AccountRestriction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A barred account reaches its messages and its notices, and nothing else.
 *
 * Barred means blacklisted. A suspension held the whole account too until
 * 5 October 2026; it now holds only the business it is about — see
 * `AccountRestriction`.
 *
 * ── Why this is a gate and not a screen ──────────────────────────────────
 *
 * "Bawal nya na maccess ang iba pa sa system, kundi messages part na lang at
 * pag view ng notif. The rest — ang mga application, renew, amend at marami
 * pang iba — ay di accessible, dapat maayos muna yung pagka suspend o
 * blacklisted nya" [client, 30 September 2026].
 *
 * The navigation hides those destinations and the router refuses them, and
 * neither is a lock: both run in a browser the reader owns. A restriction that
 * only a screen enforces is a suggestion — anybody who kept a tab open, or who
 * types a path, walks straight past it. So the refusal is here, and the screen
 * merely agrees with it.
 *
 * ── What it is attached to ───────────────────────────────────────────────
 *
 * The owner's own capability groups in routes/workflow.php: registering and
 * editing a business, filing, renewing, amending, uploading, paying, answering
 * a requirement. Not `message.participate`, not the notification routes, and
 * not `/auth/*` — those three are the whole of what is left open, and the
 * modal that explains the bar is built from `/auth/me`, so locking that would
 * lock the explanation away with everything else.
 *
 * Attached per GROUP rather than globally, and that is the safer direction:
 * a route added later is reachable until somebody puts it behind a permission
 * that is listed here, whereas a global deny-list would silently bar new
 * endpoints nobody meant to bar. The permission groups are the owner's
 * abilities, so covering them covers the ask.
 *
 * ── GET as well as POST ──────────────────────────────────────────────────
 *
 * Reads are refused too, and deliberately. "Di accessible" is about the screen
 * as much as the act: an owner who can still open the Apply wizard, fill it in
 * and be refused at the last step has been walked into a wall. Refusing the
 * list it opens with is what makes the navigation's absence honest.
 */
class EnforceAccountRestriction
{
    public function handle(Request $request, Closure $next): Response
    {
        $restriction = AccountRestriction::for($request->user());

        if ($restriction === null) {
            return $next($request);
        }

        /*
         * 403 and not 422: this is not a fault in what was sent, and no
         * correction to the request would be accepted. The body carries the
         * finding so a client that meets this without having read /auth/me —
         * a stale tab, a second window — can still say what it is and where
         * to take it, rather than printing a bare refusal.
         *
         * One sentence since 5 October 2026: a suspended business no longer
         * bars the account (see `AccountRestriction`), so a blacklisting is
         * the only finding that reaches here.
         */
        abort(403, 'This account is blacklisted. Message the City BPLO to ask what is needed to have it lifted.');
    }
}
