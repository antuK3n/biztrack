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
 * Under the `admin` prefix and on `application.view_any_office` — BPLO and the
 * super admin — because the map reads the whole city at once (checklist item
 * 16 put it on BPLO's Permits page; it was `user.manage` until then). See the
 * class comment on BusinessMapController for why `permit.view_all`, which every
 * office holds, is still the wrong gate.
 */
/*
 * Opened to every office on `permit.view_all` [client, 4 October 2026: "maglagay
 * din ng maps tulad sa bplo"]. The controller scopes it: an office maps the
 * businesses holding ITS certificate, coloured by that certificate; BPLO and
 * the super admin keep the whole city on the Mayor's Permit.
 */
Route::middleware(['auth:sanctum', 'permission:permit.view_all'])
    ->get('admin/business-map', [BusinessMapController::class, 'index']);
