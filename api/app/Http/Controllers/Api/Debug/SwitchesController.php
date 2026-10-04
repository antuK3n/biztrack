<?php

namespace App\Http\Controllers\Api\Debug;

use App\Http\Controllers\Controller;
use App\Support\DebugPanel;
use App\Support\SystemSwitches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Debug page's System switches (App\Support\SystemSwitches).
 *
 *   GET debug/switches  every switch: what it is now, whether the Debug page is
 *                       overriding its default, and whether it could go on
 *   PUT debug/switches  {switch, value}: one switch at a time
 *
 * One at a time, because each is its own decision with its own audit row
 * (`debug.switches`, with the switch's state before and after). A refusal —
 * sign-in codes on with no mailer, the captcha on with no secret, a date that
 * is not one — is a 422 naming why, and changes nothing.
 */
class SwitchesController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => SystemSwitches::state()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'switch' => ['required', 'string', Rule::in(SystemSwitches::NAMES)],
            'value' => ['present', 'nullable', 'string', 'max:20'],
        ]);

        try {
            $change = SystemSwitches::set($data['switch'], $data['value']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['value' => [$e->getMessage()]]);
        }

        DebugPanel::audit(
            'switches',
            [$data['switch'] => $change['before']],
            [$data['switch'] => $change['after']],
            actorId: $request->user()->id,
        );

        return response()->json(['data' => SystemSwitches::state()]);
    }
}
