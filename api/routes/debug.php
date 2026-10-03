<?php

use App\Http\Controllers\Api\Debug\FilingsController;
use App\Http\Controllers\Api\Debug\PanelController;
use App\Http\Controllers\Api\Debug\PaymentsController;
use Illuminate\Support\Facades\Route;

/*
 * The Debug page's API: /api/v1/debug/* [Ken, 2026-10-04].
 *
 * Every route here is inside this ONE group and behind `debug.panel`
 * (EnsureDebugPanelOpen → App\Support\DebugPanel::allows): the super admin,
 * and the panel opened from the server with
 * `php artisan biztrack:debug-panel on --hours=N`. Anyone else, and everyone
 * while it is closed, gets a 404.
 *
 * ── Adding a section ─────────────────────────────────────────────────────
 *
 * One controller per section in App\Http\Controllers\Api\Debug, routed here
 * under the section's own prefix, and every change it makes recorded with
 * DebugPanel::audit('<section>.<what>', $before, $after) — `debug.*` in the
 * audit log, with the actor and both states. Do not add the middleware per
 * route and do not route a debug control anywhere else: being inside this
 * group IS the access rule.
 */
Route::prefix('debug')->middleware('debug.panel')->group(function () {
    // When the panel closes, for the page's header. Read-only: nothing opens it from here.
    Route::get('panel', [PanelController::class, 'show']);

    // Payments: how owners pay, and what KwikPay collects.
    Route::get('payments', [PaymentsController::class, 'show']);
    Route::put('payments', [PaymentsController::class, 'update']);
    Route::post('payments/test', [PaymentsController::class, 'test'])->middleware('throttle:10,1');

    // Move a filing along: the offices' own steps, the super admin acting for them.
    Route::get('filings', [FilingsController::class, 'index']);
    Route::get('filings/{application}', [FilingsController::class, 'show'])->whereNumber('application');
    Route::post('filings/{application}/steps', [FilingsController::class, 'step'])->whereNumber('application');
    Route::post('filings/{application}/advance', [FilingsController::class, 'advance'])->whereNumber('application');
});
