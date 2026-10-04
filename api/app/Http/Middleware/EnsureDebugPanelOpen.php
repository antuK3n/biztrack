<?php

namespace App\Http\Middleware;

use App\Support\DebugPanel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `debug.panel`: every /api/v1/debug/* route sits behind this and nothing else
 * (routes/debug.php). DebugPanel::allows() is the rule.
 *
 * A 404, never a 403, for everyone it refuses — a guest, an office account,
 * the super admin while the panel is closed. A 403 says "this exists and is
 * not for you"; outside the defense the panel should not appear to exist.
 *
 * It authenticates the token itself rather than sitting behind auth:sanctum,
 * because that middleware answers a guest 401 before this one runs — which is
 * also a way of saying the route exists. Once it lets a request through, the
 * sanctum guard is made the default, so controllers and Audit::log see the
 * user exactly as they would behind auth:sanctum.
 */
class EnsureDebugPanelOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();

        abort_unless(DebugPanel::allows($user), 404);

        Auth::shouldUse('sanctum');

        return $next($request);
    }
}
