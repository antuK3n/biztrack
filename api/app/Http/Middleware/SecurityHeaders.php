<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response hardening.
 *
 * The bearer token lives in localStorage (an accepted capstone tradeoff, see
 * README), which means the realistic session-hijacking route is XSS or the app
 * being framed, not network sniffing. These headers close both. They are set
 * here rather than in nginx so they hold in local dev and behind the tunnel
 * too, where there is no reverse proxy to add them.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            // No framing: kills clickjacking against the officer review screens.
            'X-Frame-Options' => 'DENY',
            // Don't let a browser sniff a JSON body into something executable.
            'X-Content-Type-Options' => 'nosniff',
            // Never leak a tracking ID or application URL to a third-party site.
            'Referrer-Policy' => 'no-referrer',
            // The API serves data, never a document that needs to run anything.
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        ];

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        // Tell browsers to stick to HTTPS once they have seen it. Only over TLS,
        // so a plain-HTTP dev request can never pin localhost to HTTPS.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
