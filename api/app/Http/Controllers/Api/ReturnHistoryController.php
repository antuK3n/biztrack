<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Support\ApplicationVisibility;
use App\Support\ReturnHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every return on a filing, answered or not, newest first (client,
 * 19 October 2026: a complied return keeps its remark).
 *
 * Its own endpoint rather than another key on ApplicationResource: the
 * owner's Track page and the officer's review screen each load that payload
 * on every visit, and the history is read by one component on each. The
 * filing's own gate first, then each round behind the gate its office's
 * remarks already sit behind — see ReturnHistory::forReader.
 */
class ReturnHistoryController extends Controller
{
    public function index(Request $request, Application $application): JsonResponse
    {
        ApplicationVisibility::authorize(
            $request->user(),
            $application,
            'You may not view this application.',
        );

        return response()->json([
            'data' => ReturnHistory::forReader($application, $request->user()),
        ]);
    }
}
