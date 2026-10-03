<?php

use App\Http\Controllers\Api\LocationInsightsController;
use App\Http\Controllers\Api\ZoningCheckController;
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
 * City Ordinance No. 24-2018, applied rule by rule (App\Support\Zoning).
 *
 * Beside location-insights because the wizard's Location & Zoning step asks
 * both as the applicant types, and they move together. `zoning-check` takes
 * what is on screen; the application route answers a stored filing for its
 * owner or any office that may read it; `zoning-facts` is the zoning officer's
 * own answers, behind `zoning.evaluate` (CPDO, BPLO, the super admin).
 */
Route::middleware(['auth:sanctum', 'permission:application.create'])
    ->post('zoning-check', [ZoningCheckController::class, 'preview']);
Route::middleware('auth:sanctum')
    ->get('applications/{application}/zoning-check', [ZoningCheckController::class, 'show']);
Route::middleware(['auth:sanctum', 'permission:zoning.evaluate'])
    ->put('applications/{application}/zoning-facts', [ZoningCheckController::class, 'officerFacts']);
