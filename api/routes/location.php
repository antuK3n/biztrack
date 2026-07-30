<?php

use App\Http\Controllers\Api\LocationInsightsController;
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
