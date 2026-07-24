<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Sanctum bearer auth implementing the sprint-1 §E1 contract that the web mock
 * (web/src/lib/mock.ts) already codifies: same envelopes, status codes, and the
 * 5-attempt lockout.
 */
class AuthController extends Controller
{
    private function withRelations(User $user): User
    {
        return $user->load('department', 'roles.permissions');
    }

    private function authPayload(User $user): JsonResponse
    {
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($this->withRelations($user)),
            ],
        ], 200);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'gender' => ['required', 'in:M,F'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'mobile_number' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            'data_privacy_consent' => ['accepted'],
        ], [
            'email.unique' => 'This email is already registered. Try signing in instead.',
            'data_privacy_consent.accepted' => 'You must agree to the data privacy notice to continue.',
        ]);

        $user = User::create([
            'name' => trim("{$data['first_name']} {$data['last_name']}"),
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'suffix' => $data['suffix'] ?? null,
            'gender' => $data['gender'],
            'email' => strtolower(trim($data['email'])),
            'mobile_number' => $data['mobile_number'],
            'password' => $data['password'],
            'data_privacy_consent_at' => now(),
            'is_active' => true,
        ]);

        // Self-registration is always a business owner.
        if ($role = Role::where('name', 'business_owner')->first()) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        Audit::log('user.registered', $user);

        return $this->authPayload($user)->setStatusCode(201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'login:'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            $minutes = max(1, (int) ceil($seconds / 60));

            return response()->json([
                'message' => "Account temporarily locked. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
            ], 429);
        }

        $user = User::where('email', Str::lower($data['email']))->first();

        // DB-level lockout mirrors the rate limiter so the persisted columns
        // (paper Table 35: failed_login_attempts, locked_until) match reality.
        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            $minutes = max(1, (int) ceil(now()->diffInSeconds($user->locked_until) / 60));

            return response()->json([
                'message' => "Account temporarily locked. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
            ], 429);
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 15 * 60); // 15-minute decay

            if ($user) {
                $attempts = $user->failed_login_attempts + 1;
                $user->forceFill([
                    'failed_login_attempts' => $attempts,
                    'locked_until' => $attempts >= 5 ? now()->addMinutes(15) : $user->locked_until,
                ])->save();
            }

            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Your account is deactivated. Contact the City BPLO.'], 403);
        }

        RateLimiter::clear($key);
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ])->save();
        Audit::log('user.logged_in', $user);

        return $this->authPayload($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($this->withRelations($request->user())),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // Best-effort; never reveal whether the email exists (no enumeration).
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'If that email is registered, a reset link is on its way.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        return response()->json(['message' => 'Your password has been reset.']);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required'],
            'hash' => ['required', 'string'],
        ]);

        $user = User::find($data['id']);

        if (! $user || ! hash_equals(sha1($user->email), $data['hash'])) {
            throw ValidationException::withMessages([
                'hash' => ['This verification link is invalid or has expired.'],
            ]);
        }

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return response()->json(['message' => 'Email verified.']);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        // Verification is simulated for the prototype (no live SMTP required).
        return response()->json(['message' => 'Verification email sent.']);
    }
}
