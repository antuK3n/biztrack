<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

/*
 * BizTrack API v1 (mounted at /api/v1 — see bootstrap/app.php apiPrefix).
 * Responses: { data, meta? }. Errors: Laravel validation shape.
 */

// --- Auth (§E1) -------------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('email/verify', [AuthController::class, 'verifyEmail']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::put('password', [AuthController::class, 'updatePassword']);
        Route::post('email/resend', [AuthController::class, 'resendVerification']);
    });
});

// Workflow routes are registered in routes/workflow.php (loaded below) once
// their controllers exist.
if (file_exists(__DIR__.'/workflow.php')) {
    require __DIR__.'/workflow.php';
}
