<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\KwikPayCallbackController;
use App\Http\Controllers\Api\OfficeHoursController;
use App\Http\Controllers\FakeKwikPayController;
use App\Services\KwikPay\FakeKwikPay;
use Illuminate\Support\Facades\Route;

/*
 * BizTrack API v1 (mounted at /api/v1 — see bootstrap/app.php apiPrefix).
 * Responses: { data, meta? }. Errors: Laravel validation shape.
 */

// --- Auth (§E1) -------------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    /*
     * The sign-in code, step two [checklist 2026-09-27, Login 5]. Only reached
     * while a real mailer is configured — with mail off, login issues the token
     * itself. Same IP limiter as the password step; the per-account lockout is
     * inside the method.
     */
    Route::post('login/code', [AuthController::class, 'verifySignInCode'])->middleware('throttle:login');
    Route::post('login/code/resend', [AuthController::class, 'resendSignInCode'])->middleware('throttle:6,1');
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
        /*
         * The code a password change needs while mail is on [checklist
         * 2026-09-27, Edit Settings]. The first code and every resend; the
         * per-account ration (one a minute, five per code) is inside the
         * method, this limiter caps password guesses against the session.
         */
        Route::post('password/code', [AuthController::class, 'requestPasswordCode'])
            ->middleware('throttle:6,1');
        // Laravel's own convention for this endpoint. The per-account limiter
        // inside the method is the tighter of the two — see the note there.
        Route::post('email/resend', [AuthController::class, 'resendVerification'])
            ->middleware('throttle:6,1');
        // The code from the confirmation e-mail, when mail is on [Register 1].
        Route::post('email/verify-code', [AuthController::class, 'verifyEmailCode'])
            ->middleware('throttle:10,1');
    });
});

// Is City Hall open now? Public: the sign-in pages show it [Login 6].
Route::get('office-hours', OfficeHoursController::class);

/*
 * KwikPay's deposit callback (docs/payment-gateway.md). Public — KwikPay holds
 * no token of ours; the MD5 signature over the fields is the authentication,
 * checked in KwikPayCallback. No CSRF: API routes carry none. Registered
 * whatever the payment mode is, so a payment opened before the switch was
 * turned off can still be confirmed after. Its raw fields are protected from
 * TrimStrings / ConvertEmptyStringsToNull in bootstrap/app.php.
 */
Route::post('payments/kwikpay/callback', KwikPayCallbackController::class)
    ->name('payments.kwikpay.callback');

/*
 * The stand-in KwikPay for demos and e2e — never on a real server. Both the
 * registration and every action check FakeKwikPay::available() (local/testing
 * AND KWIKPAY_FAKE=true). See FakeKwikPayController.
 */
if (FakeKwikPay::available()) {
    Route::prefix('fake-kwikpay')->group(function () {
        Route::post('api/transfer', [FakeKwikPayController::class, 'transfer']);
        Route::post('api/query', [FakeKwikPayController::class, 'query']);
        Route::post('api/me', [FakeKwikPayController::class, 'me']);
        Route::get('pay/{orderId}', [FakeKwikPayController::class, 'page']);
        Route::post('pay/{orderId}/{outcome}', [FakeKwikPayController::class, 'settle']);
        Route::get('qr/{orderId}', [FakeKwikPayController::class, 'qr']);
    });
}

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

// The Debug page's controls, behind `debug.panel` (App\Support\DebugPanel).
require __DIR__.'/debug.php';
