<?php

use App\Http\Middleware\EnsureDebugPanelOpen;
use App\Http\Middleware\EnsureEmailConfirmedToFile;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'email.confirmed' => EnsureEmailConfirmedToFile::class,
            // The Debug page's API: super admin AND the panel open, else 404.
            'debug.panel' => EnsureDebugPanelOpen::class,
        ]);
        $middleware->append(SecurityHeaders::class);
        /*
         * KwikPay signs the callback over the RAW field values — `remark` may be
         * "" and is signed as "", and a value is hashed exactly as sent (docs
         * FAQ, "My recomputed callback signature never matches"). Trimming, or
         * turning "" into null, would change what we hash and fail every such
         * callback. See KwikPayCallbackController.
         */
        $kwikpayCallback = fn (Request $request) => $request->is('api/v1/payments/kwikpay/callback');
        $middleware->trimStrings(except: [$kwikpayCallback]);
        $middleware->convertEmptyStringsToNull(except: [$kwikpayCallback]);
        /*
         * API-only app: there is no named 'login' route to bounce a guest to.
         * Returning null makes Authenticate throw AuthenticationException, which
         * the JSON renderer below turns into a clean 401. Without this, any
         * unauthenticated request that doesn't send Accept: application/json
         * (a tester pasting a URL into the address bar) got a 500.
         */
        $middleware->redirectGuestsTo(fn () => null);

        /*
         * ── The wizard's scratch save is stored verbatim ──────────────
         *
         * `ConvertEmptyStringsToNull` walks the whole request body, so on
         * `PUT /wizard-drafts/{type}` it reached inside `payload` — a
         * snapshot of the applicant's half-finished form that this API
         * stores and hands back and never interprets — and turned every
         * empty answer into a null.
         *
         * The wizard's `EMPTY` form says those keys are strings and some
         * sixty reads call `.trim()` on them, so restoring one of those
         * payloads took the whole form to a blank page. The client found
         * it on 29 September 2026 by ticking Data Privacy and reopening.
         *
         * The middleware is right everywhere else — for a real form post,
         * "" and "not given" are the same thing and a nullable column
         * wants the null. It is wrong here because this value is not a
         * field; it is a document, and the only correct thing to do with
         * it is give it back unchanged.
         *
         * `TrimStrings` goes with it, ahead of the subtler version of the
         * same bug: it would quietly eat the trailing space from an
         * address somebody is still typing.
         */
        $middleware->convertEmptyStringsToNull(except: [
            /*
             * `wizard-drafts*`, not `wizard-drafts/*`. The create posts to
             * the collection URL with no trailing segment, so the stricter
             * pattern exempted every save EXCEPT the first one — which is
             * the one that would have written a payload full of nulls.
             */
            fn (Request $request) => $request->is('api/*/wizard-drafts*'),
        ]);
        $middleware->trimStrings(except: [
            fn (Request $request) => $request->is('api/*/wizard-drafts*'),
        ]);
        /*
         * Behind a reverse proxy every request arrives from the proxy's address,
         * so without this the sign-in lockout and the audit log would see one
         * visitor: a stranger's wrong passwords would lock everyone out. On the
         * Azure server Caddy and nginx sit in front of PHP on Docker's private
         * network, so TRUSTED_PROXIES names that network there. Unset (local
         * dev, tests) it trusts nobody, exactly as before.
         */
        if (filled(env('TRUSTED_PROXIES'))) {
            $middleware->trustProxies(at: array_map('trim', explode(',', (string) env('TRUSTED_PROXIES'))));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        /*
         * A wrong method on a Debug route is a 404 like everything else there.
         * Route matching answers 405 before any middleware runs, so without
         * this a POST to a GET-only debug route would tell anybody — panel
         * closed, not signed in — that the route exists, which is what the
         * 404s in EnsureDebugPanelOpen are for.
         */
        $exceptions->render(fn (MethodNotAllowedHttpException $e, Request $request) => $request->is('api/v1/debug', 'api/v1/debug/*')
            ? response()->json(['message' => 'Not Found.'], 404)
            : null);

        /*
         * ── A 404 names no class and no id ───────────────────────
         *
         * An applicant opening the renewal dialog was shown
         * "No query results for model [App\Models\Business] 19" inside the
         * permit picker (client's screenshot, 3 October 2026). That is
         * `ModelNotFoundException`'s default, rendered verbatim: it tells a
         * member of the public our namespace, our class names and a primary
         * key, and gives them nothing they can act on.
         *
         * The same fault as the PHP fatal quoted under Download PDF on
         * 2 October, and `web/src/lib/api.ts` already refuses to print a 5xx
         * message for exactly that reason. It deliberately keeps everything
         * BELOW 500, because a 422 or a 403 is the API telling the applicant
         * something true about their request — and that split is right. A
         * route-model-binding 404 is the exception: nobody wrote it for a
         * reader, Laravel generated it from a class name.
         *
         * So it is fixed HERE rather than filtered there. The browser is not
         * the only caller, a leak is a leak whoever reads it, and a client
         * pattern-matching on "No query results" would be guessing at the
         * shape of a framework string.
         *
         * Only the FRAMEWORK's own message is replaced. A 404 a controller
         * raised deliberately — `abort(404, '…')` with a sentence meant for
         * the applicant — keeps its wording, because that one was written
         * for them. `getMessage()` is empty on an aborted 404 with no text,
         * and `ModelNotFoundException` is the only thing that fills it with
         * a class name.
         *
         * The status stays 404. Several endpoints rely on it meaning "not
         * yours, and therefore not found" rather than 403 — see the scope
         * note on `DraftController` — and changing it here would turn a
         * deliberate privacy choice into a permissions error.
         */
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $previous = $e->getPrevious();
            $fromBinding = $previous instanceof ModelNotFoundException;

            if (! $fromBinding) {
                return null;
            }

            return response()->json([
                'message' => 'We could not find that. It may have been removed, or it may belong to another account.',
            ], 404);
        });
    })->create();
