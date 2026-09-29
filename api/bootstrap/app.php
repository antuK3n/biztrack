<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
