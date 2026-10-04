<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SystemSwitches;
use App\Support\Turnstile;
use Illuminate\Http\JsonResponse;

/**
 * What every screen has to be told about the Debug page's system switches,
 * without being told the switches.
 *
 *   GET system-notices      (signed in) the pretend date, for the banner every
 *                           signed-in user sees while renewal dates are
 *                           simulated. Polled by the app shell, so the banner
 *                           appears within one poll of the switch.
 *   GET auth/sign-in-options (public) whether the sign-in form must carry a
 *                           captcha, so a captcha switched off is not still
 *                           drawn and demanded.
 *
 * Only these facts. The sign-in code needs no notice: the login answer itself
 * says whether a code was sent.
 */
class SystemNoticeController extends Controller
{
    public function notices(): JsonResponse
    {
        return response()->json(['data' => [
            'pretend_date' => SystemSwitches::pretendDate()?->toDateString(),
        ]]);
    }

    public function signInOptions(): JsonResponse
    {
        return response()->json(['data' => [
            'captcha' => Turnstile::enabled(),
        ]]);
    }
}
