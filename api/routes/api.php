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

    /*
     * The address printed in the verification email.
     *
     * GET, because a mail client can only follow a link, and named because
     * `App\Notifications\VerifyEmailAddress` builds the URL with
     * `URL::temporarySignedRoute('verification.verify', …)` — the name is the
     * contract between the two.
     *
     * No `signed` middleware on purpose: the controller checks the signature
     * itself so an expired link ends on the app's own screen rather than on
     * Laravel's 403 page. See the note on `verifyEmailLink`.
     *
     * This replaced an UNSIGNED `POST email/verify` taking `{id, hash}`, which
     * let anyone mark any account's address confirmed from public knowledge.
     * If something still calls that address it is out of date and should be
     * pointed at the link in the email instead — there is no supported way to
     * confirm an address without holding a link we sent.
     */
    Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmailLink'])
        ->middleware('throttle:6,1')
        ->name('verification.verify');

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
        // Laravel's own convention for this endpoint. The per-account limiter
        // inside the method is the tighter of the two — see the note there.
        Route::post('email/resend', [AuthController::class, 'resendVerification'])
            ->middleware('throttle:6,1');
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

// The GIS surface — the super admin's business map (issue #104).
if (file_exists(__DIR__.'/gis.php')) {
    require __DIR__.'/gis.php';
}
