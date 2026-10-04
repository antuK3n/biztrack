<?php

use App\Http\Middleware\EnsureDebugPanelOpen;
use App\Http\Middleware\EnsureEmailConfirmedToFile;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

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
    })->create();
