<?php

use App\Http\Controllers\Api\BusinessMapController;
use Illuminate\Support\Facades\Route;

/*
 * The GIS surface — issue #104's business map. Mounted at /api/v1 alongside the
 * rest.
 *
 * Its own file rather than another block in routes/workflow.php, following the
 * precedent routes/location.php set: one self-contained feature, one file, so
 * the screen and the route it depends on move together and neither is found by
 * accident while reading something else.
 *
 * Under the `admin` prefix and on `user.manage` because the map reads the whole
 * city at once. See the class comment on BusinessMapController for why
 * `permit.view_all` — which is the permission whose NAME fits — is the wrong
 * gate: every office holds it, and this is a super-admin console.
 */
Route::middleware(['auth:sanctum', 'permission:user.manage'])
    ->get('admin/business-map', [BusinessMapController::class, 'index']);
