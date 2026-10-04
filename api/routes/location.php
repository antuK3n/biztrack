<?php

use App\Http\Controllers\Api\LocationInsightsController;
use App\Http\Controllers\Api\ZoneAtPinController;
use Illuminate\Support\Facades\Route;

/*
 * Business Location Insights (docs/r-integration-spec.md §5) — mounted at
 * /api/v1 alongside the workflow surface.
 *
 * A separate file rather than another block in routes/workflow.php: this is one
 * self-contained applicant-facing feature, and keeping it here means the wizard
 * step and its route move together.
 */
Route::middleware(['auth:sanctum', 'permission:application.create'])
    ->get('location-insights', [LocationInsightsController::class, 'show']);

/*
 * The zone under the owner's pin, and the sentence that stops them when their
 * line of business is clearly not allowed in it (App\Support\Zoning\PinZone).
 * Beside location-insights because the wizard asks both as the pin settles.
 */
Route::middleware(['auth:sanctum', 'permission:application.create'])
    ->get('zone-at-pin', [ZoneAtPinController::class, 'show']);
