<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\LegacyImport\LegacyClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Claim businesses from the old register after signing up.
 *
 * The sign-up form is where most owners will claim (AuthController::register).
 * This is the same check for an owner who already had an account, or who
 * skipped the field — against the surname on their own account, never one
 * they type, so an account cannot claim under somebody else's name.
 */
class LegacyClaimController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'claim_number' => ['required', 'string', 'max:60'],
        ]);

        $user = $request->user();
        $owner = LegacyClaim::match($data['claim_number'], (string) $user->last_name, $request->ip());
        $count = LegacyClaim::claim($owner, $user, $data['claim_number']);

        return response()->json(['data' => ['claimed' => $count]]);
    }
}
