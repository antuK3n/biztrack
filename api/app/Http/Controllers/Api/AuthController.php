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
    /**
     * The citizen-facing portal admits business owners only; the staff portal
     * admits LGU officers and the super admin. Keeping the two doors separate
     * means a leaked staff credential is useless at the public sign-in, and an
     * applicant can never land on an officer dashboard by accident.
     */
    /*
     * `market_admin` was on this list and is gone [client, 2026-09-06], with the
     * Market Clearance and the CMO Market Office it belonged to. Nothing could
     * hold the role — the 2026_09_06 migration deleted the row — so the entry
     * admitted nobody. Removed anyway: a name left in a door list outlives the
     * reason it was harmless, and the next role created under it would have been
     * waved through the staff sign-in without anyone deciding that it should be.
     */
    private const STAFF_ROLES = [
        'bplo_staff', 'sanitary_officer', 'fire_inspector', 'zoning_officer',
        'obo_staff', 'cenro_officer', 'admin',
    ];

    private function withRelations(User $user): User
    {
        return $user->load('department', 'roles.permissions');
    }

    private function isStaff(User $user): bool
    {
        return $user->roles->pluck('name')->intersect(self::STAFF_ROLES)->isNotEmpty();
    }

    /**
     * The signed-in user as the web app's `User` type, plus the join date the
     * Profile screen shows as "member since". UserResource is shared with the
     * admin user listings, so the extra field is added on this side.
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return (new UserResource($this->withRelations($user)))->resolve()
            + ['created_at' => optional($user->created_at)->toISOString()];
    }

    private function authPayload(User $user, string $portal = 'public'): JsonResponse
    {
        // The token name records which door was used, so revoking one portal's
        // sessions later doesn't take the other's down with it.
        $token = $user->createToken("web:{$portal}")->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $this->userPayload($user),
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
            'portal' => ['sometimes', 'in:public,staff'],
        ]);
        $portal = $data['portal'] ?? 'public';

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

        /*
         * Wrong door. Refused, and deliberately without saying which door is
         * right.
         *
         * This used to name the other portal and ship a `portal` field so the
         * sign-in page could offer a "Go there now" link. The client asked for
         * that to stop: a refusal should be a refusal, not an invitation to the
         * other site.
         *
         * The same wording answers both directions, which also closes a small
         * disclosure. Two different sentences told an unauthenticated visitor
         * on the citizen page that the address they had just typed belongs to
         * an LGU staff account — a fact about somebody else's account, handed
         * over for the price of one guess at a password that then failed to
         * matter. One sentence says only "not here", which is all the person
         * typing needs and all a stranger should get.
         *
         * Still 409 rather than 422: the credentials are correct, so this is a
         * conflict with where they were used, not a bad password. The status
         * carries that distinction for the API's own consumers without the
         * response body spelling it out on screen.
         */
        $user->loadMissing('roles');
        if ($this->isStaff($user) !== ($portal === 'staff')) {
            RateLimiter::clear($key);

            return response()->json([
                'message' => 'This account cannot sign in here.',
            ], 409);
        }

        RateLimiter::clear($key);
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ])->save();
        Audit::log('user.logged_in', $user);

        return $this->authPayload($user, $portal);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->userPayload($request->user()),
        ]);
    }

    /**
     * Update the signed-in user's own profile fields. Email changes are
     * intentionally not supported here: the address is the login identifier
     * and the prototype has no live re-verification flow.
     *
     * Every name part registration collects is editable here, because the
     * Profile screen renders `fullName()` — middle name and suffix included —
     * and a name a screen prints but no screen can correct is worse than one it
     * never asked for. Same reasoning for gender: registration requires it.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            // Nullable, not `required|in:M,F` as registration has it: accounts
            // seeded or created before the column existed hold null, and forcing
            // a value on them would make an unrelated name edit fail validation.
            'gender' => ['nullable', 'in:M,F'],
            'mobile_number' => ['required', 'string', 'max:20'],
        ]);

        $user = $request->user();

        /*
         * Absent key keeps the stored value; a key sent empty clears it.
         *
         * These three are optional, so the empty string is a real answer — "I
         * have no suffix" — and ConvertEmptyStringsToNull turns it into null
         * before it reaches here. `?? $user->middle_name` could not tell that
         * apart from a client that never sent the field, so it read as "keep"
         * either way and a middle name, once saved, could never be removed.
         */
        $optional = function (string $key) use ($data, $user) {
            return array_key_exists($key, $data) ? $data[$key] : $user->{$key};
        };

        $user->fill([
            // `name` stays first + last: it is the framework compat column and
            // the short form every list and notification already prints.
            'name' => trim("{$data['first_name']} {$data['last_name']}"),
            'first_name' => $data['first_name'],
            'middle_name' => $optional('middle_name'),
            'last_name' => $data['last_name'],
            'suffix' => $optional('suffix'),
            'gender' => $optional('gender'),
            'mobile_number' => $data['mobile_number'],
        ])->save();

        Audit::log('user.profile_updated', $user);

        return response()->json([
            'data' => $this->userPayload($user),
        ]);
    }

    /**
     * Change the signed-in user's password. Requires the current password and
     * revokes every other token so a hijacked session dies with the old
     * credential; the token making this request stays valid.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is incorrect.'],
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Revoke all other sessions (keep the one performing the change).
        $user->tokens()
            ->where('id', '!=', $user->currentAccessToken()->id)
            ->delete();

        Audit::log('user.password_changed', $user);

        return response()->json([
            'message' => 'Password updated. Other signed-in devices have been logged out.',
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
