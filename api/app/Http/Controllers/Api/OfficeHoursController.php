<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\OfficeHours;
use Illuminate\Http\JsonResponse;

/**
 * GET /office-hours — is City Hall open now? [checklist 2026-09-27, Login 6]
 *
 * Public, because the sign-in pages show the notice before anyone is signed
 * in. It tells a stranger nothing the City's own website does not: the hours
 * and the time.
 */
class OfficeHoursController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => OfficeHours::status()]);
    }
}
