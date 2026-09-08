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
        /*
         * POST rather than PUT for the upload: PHP populates $_FILES from a
         * multipart body only on POST, so a PUT arrives with the file missing
         * and validation rejects it as "choose an image" no matter what was
         * picked. The path carries no user id — showPhoto reads the signed-in
         * row, so nobody can ask for another account's photo.
         */
        Route::post('profile/photo', [AuthController::class, 'updatePhoto']);
        Route::get('profile/photo', [AuthController::class, 'showPhoto']);
        Route::delete('profile/photo', [AuthController::class, 'destroyPhoto']);
        Route::put('password', [AuthController::class, 'updatePassword']);
        Route::post('email/resend', [AuthController::class, 'resendVerification']);
    });
});

// Workflow routes are registered in routes/workflow.php (loaded below) once
// their controllers exist.
if (file_exists(__DIR__.'/workflow.php')) {
    require __DIR__.'/workflow.php';
}

// Business Location Insights — the apply wizard's zoning step (spec §5).
if (file_exists(__DIR__.'/location.php')) {
    require __DIR__.'/location.php';
}
