<?php

namespace App\Http\Controllers\Api\Debug;

use App\Http\Controllers\Controller;
use App\Support\DebugPanel;
use Illuminate\Http\JsonResponse;

/**
 * GET debug/panel: when the panel closes, so the page can say so at the top.
 *
 * Read-only on purpose. The panel is opened and closed from the server
 * (`php artisan biztrack:debug-panel`), never from here — see DebugPanel.
 */
class PanelController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            // Null while APP_ENV=local holds it open without the flag.
            'open_until' => DebugPanel::openUntil()?->toIso8601String(),
            'local' => DebugPanel::bypassed(),
        ]]);
    }
}
