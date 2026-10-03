<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\EmailCode;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\DebugPanel;
use App\Support\EmailCodes;
use App\Support\EmailSwitch;
use App\Support\LegacyImport\LegacyClaim;
use App\Support\OfficeHours;
use App\Support\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sanctum bearer auth implementing the sprint-1 §E1 contract that the web mock
 * (web/src/lib/mock.ts) already codifies: same envelopes, status codes, and the
 * 5-attempt lockout.
 */
class AuthController extends Controller
{
    /**
     * Three doors, and an account belongs to exactly one.
     *
     * `public` admits business owners, `staff` the six LGU clearance offices
     * and BPLO, `admin` the super admin alone. Keeping them separate means a
     * leaked credential is useless at the other two sign-ins, and an applicant
     * can never land on an officer dashboard by accident.
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
        'obo_staff', 'cenro_officer',
    ];

    /*
     * `admin` has its own door now [checklist item #107].
     *
     * It used to sit in STAFF_ROLES above, so the super admin signed in at
     * /staff/login alongside all six offices. The two are not the same job: an
     * office reviews filings within its own department, and the super admin
     * creates the accounts that do the reviewing, reassigns cases, and reads
     * the whole register and the audit trail. One door for both meant a leaked
     * office credential and a leaked administrator credential were tried at the
     * same address, and it meant the citizen/staff split could not say which of
     * the two an account belonged to.
     *
     * Three doors, three token keys (web/src/lib/api.ts), one predicate below.
     * Adding a fourth is a matter of adding a list and a case to `portalFor`;
     * the wrong-door check is written against the answer, not against a pair.
     */
    private const ADMIN_ROLES = ['admin'];

    private function withRelations(User $user): User
    {
        return $user->load('department', 'roles.permissions');
    }

    /**
     * Which of the three doors this account belongs to. Exactly one.
     *
     * Admin is tested first: if a role were ever granted to an account that
     * also holds an office role, the narrower answer is the one to give — the
     * administrator's screens are the ones that need the separate session.
     */
    private function portalFor(User $user): string
    {
        $roles = $user->roles->pluck('name');

        if ($roles->intersect(self::ADMIN_ROLES)->isNotEmpty()) {
            return 'admin';
        }

        if ($roles->intersect(self::STAFF_ROLES)->isNotEmpty()) {
            return 'staff';
        }

        return 'public';
    }

    /**
     * The signed-in user as the web app's `User` type, plus the join date the
     * Profile screen shows as "member since". UserResource is shared with the
     * admin user listings, so what only the signed-in user should see — the
     * join date and the home address — is added on this side.
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $user = $this->withRelations($user);

        return (new UserResource($user))->resolve()
            + [
                'created_at' => optional($user->created_at)->toISOString(),
                /*
                 * The owner's home address [checklist 2026-09-28, Register 2],
                 * on the signed-in user's own payload only — see the note in
                 * UserResource for why the admin listings do not carry it. Null
                 * for staff, who are never asked, and for owners who registered
                 * before it was; `home_address_missing` below says which.
                 */
                'home_street' => $user->home_street,
                'home_barangay' => $user->home_barangay,
                'home_city' => $user->home_city,
                'home_province' => $user->home_province,
                'home_postal_code' => $user->home_postal_code,
                /*
                 * Whether this account has to confirm its address before it can
                 * file [checklist 2026-09-27, Register 1]. Worked out here so the
                 * web app never has to know how mail is configured: false for
                 * every account while mail is off, false for staff, and false
                 * once the address is confirmed.
                 */
                'email_verification_required' => EmailSwitch::on()
                    && $user->roles->contains('name', 'business_owner')
                    && ! $user->hasVerifiedEmail(),
                /*
                 * Whether Settings must e-mail a code before a password change
                 * [checklist 2026-09-27, Edit Settings]. The same for every
                 * account — it is the mail switch — but sent here for the same
                 * reason as the line above: the web app never learns how mail
                 * is configured, only what it has to ask the reader for.
                 */
                'password_change_code_required' => EmailSwitch::on(),
                /*
                 * Whether the Debug page is open to this account: the super
                 * admin, with the panel opened from the server
                 * (App\Support\DebugPanel). The web app shows the rail entry
                 * and the route on this alone, so it never learns the rule —
                 * and every /debug endpoint checks it again regardless.
                 */
                'debug_panel' => DebugPanel::allows($user),
                /*
                 * An owner with no home address on file [checklist 2026-09-28,
                 * Register 2] — anyone who registered before it was asked. The
                 * Profile and home pages prompt on this; nothing is refused
                 * over it (docs/questions-for-malabon.md, A27). Staff are never
                 * asked, so never owe one.
                 */
                'home_address_missing' => $user->roles->contains('name', 'business_owner')
                    && ! $user->hasHomeAddress(),
            ];
    }

    /**
     * Validation for the home address, shared by registration and the profile
     * form so a value one accepts the other cannot refuse.
     *
     * Free text throughout, barangay included: an owner may live outside
     * Malabon, so the city's barangay list (the one a business address is
     * picked from) cannot be the list of answers. ZIP is optional and, when
     * given, is the four digits every Philippine ZIP code is.
     *
     * @param  bool  $onUpdate  false at registration, where all four parts are
     *                          simply required. True on the profile form,
     *                          where three rules share the work:
     *                          - an ABSENT key keeps what is stored, so a
     *                          caller that predates the address (or a staff
     *                          account, whose form never shows it) can still
     *                          save a name edit;
     *                          - `filled`: a key that IS sent may not be empty,
     *                          so an address once given is corrected, never
     *                          blanked;
     *                          - `required_with`: sending any one part makes
     *                          the other three required, so an owner cannot
     *                          end up holding a street with no city.
     * @return array<string, array<int, string>>
     */
    private function homeAddressRules(bool $onUpdate): array
    {
        $parts = User::HOME_ADDRESS_REQUIRED;
        $presence = fn (string $field) => $onUpdate
            ? ['required_with:'.implode(',', array_diff($parts, [$field])), 'filled']
            : ['required'];

        return [
            'home_street' => [...$presence('home_street'), 'string', 'max:255'],
            'home_barangay' => [...$presence('home_barangay'), 'string', 'max:100'],
            'home_city' => [...$presence('home_city'), 'string', 'max:100'],
            'home_province' => [...$presence('home_province'), 'string', 'max:100'],
            'home_postal_code' => ['nullable', 'string', 'regex:/^\d{4}$/'],
        ];
    }

    /**
     * One sentence per part, whichever rule caught it — "required",
     * "required_with" and "filled" are the same fact to the person typing.
     *
     * @return array<string, string>
     */
    private function homeAddressMessages(): array
    {
        $said = [
            'home_street' => 'Enter your house number, building and street.',
            'home_barangay' => 'Enter your barangay.',
            'home_city' => 'Enter your city or municipality.',
            'home_province' => 'Enter your province.',
        ];

        $messages = ['home_postal_code.regex' => 'A ZIP code is 4 digits.'];
        foreach ($said as $field => $sentence) {
            foreach (['required', 'required_with', 'filled'] as $rule) {
                $messages["{$field}.{$rule}"] = $sentence;
            }
        }

        return $messages;
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
            /*
             * A business account or permit number from before BizTrack, to
             * claim the businesses the old register holds under this person's
             * name (Ken's checklist, "Migration 1"; see LegacyClaim). Optional:
             * most people registering have nothing to claim.
             */
            'claim_number' => ['nullable', 'string', 'max:60'],
            /*
             * Required here and only here. Registration is the public portal,
             * so everyone reaching this line is a business owner; staff
             * accounts are made by the super admin (Admin\UserController),
             * which never asks for a home address.
             */
            ...$this->homeAddressRules(onUpdate: false),
        ], [
            'email.unique' => 'This email is already registered. Try signing in instead.',
            'data_privacy_consent.accepted' => 'You must agree to the data privacy notice to continue.',
            ...$this->homeAddressMessages(),
        ]);

        // Checked BEFORE the account is written, so a mistyped number is
        // answered on the form rather than with an account that claimed nothing
        // and an email address that can no longer be used to try again.
        $claimFrom = filled($data['claim_number'] ?? null)
            ? LegacyClaim::match($data['claim_number'], $data['last_name'], $request->ip())
            : null;

        $user = User::create([
            'name' => trim("{$data['first_name']} {$data['last_name']}"),
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'suffix' => $data['suffix'] ?? null,
            'gender' => $data['gender'],
            'email' => strtolower(trim($data['email'])),
            'mobile_number' => $data['mobile_number'],
            'home_street' => $data['home_street'],
            'home_barangay' => $data['home_barangay'],
            'home_city' => $data['home_city'],
            'home_province' => $data['home_province'],
            'home_postal_code' => $data['home_postal_code'] ?? null,
            'password' => $data['password'],
            'data_privacy_consent_at' => now(),
            'is_active' => true,
        ]);

        // Self-registration is always a business owner.
        if ($role = Role::where('name', 'business_owner')->first()) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        Audit::log('user.registered', $user);

        if ($claimFrom !== null) {
            LegacyClaim::claim($claimFrom, $user, $data['claim_number']);
        }

        /*
         * Send the verification email, and never let it fail the registration
         * [checklist item #61].
         *
         * The account is already written and the token is already minted by the
         * time this runs, so a mailer that throws — a misconfigured SMTP host, a
         * provider rejecting the key — must not turn a successful sign-up into a
         * 500 that leaves the person unable to register again (the address is
         * taken) and unable to sign in (they never got a response carrying a
         * token). The log line is how that outage becomes visible; the reader
         * gets in either way and can ask for a fresh link from their profile.
         *
         * MAIL_MAILER is `log` by default (config/mail.php), so locally this
         * writes the whole message, link and all, to storage/logs/laravel.log —
         * verifiable without a single credential. Point MAIL_MAILER at a real
         * transport in the deployed environment and nothing here changes.
         */
        /*
         * With a real mailer configured, a six-digit code goes out instead of
         * the link, and filing waits for it [checklist 2026-09-27, Register 1;
         * why a code is on EmailCodes]. With mail off (the demo) the link is
         * written to the log exactly as before, and nothing waits on it.
         */
        try {
            if (EmailSwitch::on()) {
                [, $code] = EmailCodes::issueVerify($user);
                EmailCodes::send($user, EmailCode::VERIFY, $code);
            } else {
                $user->sendEmailVerificationNotification();
            }
        } catch (\Throwable $e) {
            Log::error('Verification email failed to send on registration.', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $this->authPayload($user)->setStatusCode(201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            // Three doors [#107]. Absent means the citizen one, which is what
            // every pre-portal client sent and what a bare curl still means.
            'portal' => ['sometimes', 'in:public,staff,admin'],
            // Cloudflare Turnstile's token [#62]. `sometimes` because the
            // widget is a no-op when no site key is configured, so local dev,
            // the e2e suite and the mock never send one — Turnstile::passes()
            // is what decides whether its absence matters.
            'captcha_token' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);
        $portal = $data['portal'] ?? 'public';

        /*
         * The captcha is checked BEFORE the password, and before the rate
         * limiter is touched [#62].
         *
         * Before the password because the point of a captcha is to make the
         * guess cost something, and a guess that gets as far as a hash
         * comparison has already cost us the expensive part. Before the
         * limiter because a bot that cannot solve the challenge should not be
         * able to spend a real person's five attempts on their behalf — the
         * per-account lockout would otherwise become a denial-of-service tool
         * aimed at any address an attacker knows.
         *
         * It answers 422 on the field rather than 403, so the sign-in page
         * shows it next to the widget like any other validation failure. And
         * it says nothing about the account: the check happens before we have
         * looked one up, so this reply is identical for an address that exists
         * and one that does not.
         */
        if (! Turnstile::passes($data['captcha_token'] ?? null, $request->ip())) {
            throw ValidationException::withMessages([
                'captcha_token' => ['That security check did not complete. Try again.'],
            ]);
        }

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

        /*
         * One refusal, used by two different failures on purpose — see the
         * wrong-door block below. It hits the limiter, advances the persisted
         * attempt count, and answers the single sentence that says nothing.
         */
        $refuse = function (?User $account) use ($key): JsonResponse {
            RateLimiter::hit($key, 15 * 60); // 15-minute decay

            if ($account) {
                $attempts = $account->failed_login_attempts + 1;
                $account->forceFill([
                    'failed_login_attempts' => $attempts,
                    'locked_until' => $attempts >= 5 ? now()->addMinutes(15) : $account->locked_until,
                ])->save();
            }

            return response()->json(['message' => 'Invalid credentials.'], 422);
        };

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $refuse($user);
        }

        /*
         * Deliberately left ABOVE the wrong-door check, and it is worth saying
         * why it is not the leak item #63 closed.
         *
         * This 403 is reached only with the correct password, and it answers
         * the same for a deactivated business owner as for a deactivated
         * officer — so it says "this account is switched off", never "this
         * account belongs to City Hall". It distinguishes nothing the citizen
         * door is meant to keep to itself.
         *
         * Moving it below the door check would be worse: a deactivated officer
         * at the wrong door would be told their credentials are invalid, go
         * round to /staff/login, and be told the real reason there — two
         * different answers to one question, which is what the door check is
         * being careful about in the first place.
         */
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
         * other site. Then both directions were given one shared sentence, so
         * the WORDING stopped saying which kind of account had been typed.
         *
         * ── Why the citizen door now gives nothing at all [item #63] ────────
         *
         * Identical wording was not enough. The status still differed: 409 for
         * a staff or admin credential, 422 for a wrong password. So a stranger
         * on the business-owner sign-in page, holding a leaked password, learned
         * from the status code alone that the address belongs to City Hall —
         * and a scripted run over a list of addresses reads 409 as "staff
         * account, keep this one". That is an enumeration oracle regardless of
         * what the sentence says, and it is the whole reason the citizen side
         * must not hint that a staff portal exists.
         *
         * The side effects were an oracle too. The old code cleared the rate
         * limiter on a wrong-door attempt and left `failed_login_attempts`
         * alone, while a bad password hit both — so even against a client that
         * ignores the body, a staff address was the one that never accumulated
         * a lockout.
         *
         * So at the PUBLIC door a wrong-door attempt now goes through exactly
         * the same `$refuse` as a wrong password: same 422, same sentence, same
         * limiter hit, same attempt count. There is nothing left to tell apart.
         *
         * The cost, stated plainly: an officer who signs in at the citizen page
         * by mistake is told their credentials are invalid, and five such
         * mistakes lock their account for fifteen minutes. That is the price of
         * the citizen side keeping the staff portal's existence to itself, and
         * it is paid by people who have been given the right address.
         *
         * ── A business owner at an LGU door gets the same [checklist
         *    2026-09-27, Login 3] ───────────────────────────────────────────
         *
         * The LGU doors used to answer 409 to ANY right password from the
         * wrong door, owners included. That told a stranger at /staff/login
         * holding a leaked list of owner passwords exactly which ones were
         * right: a free password-checking service, and the owner's lockout
         * never moved. So an owner account at /staff/login or /admin/login now
         * goes through `$refuse` too: 422, the one sentence, a counted attempt.
         *
         * Between the two LGU doors the 409 stays. Someone at /staff/login
         * already knows a staff portal exists — the page they are looking at
         * is one — so an officer who typed the wrong one of the two LGU doors
         * is owed a refusal they can tell apart from a mistyped password. 409
         * rather than 422 because the credentials were right and the conflict
         * is with WHERE they were used.
         */
        $user->loadMissing('roles');
        $belongs = $this->portalFor($user);
        if ($belongs !== $portal) {
            if ($portal === 'public' || $belongs === 'public') {
                return $refuse($user);
            }

            RateLimiter::clear($key);

            return response()->json([
                'message' => 'This account cannot sign in here.',
            ], 409);
        }

        /*
         * Unverified email, when the LGU has asked for that to be a gate.
         *
         * OFF by default, and the flag is the point — see the note on
         * `auth.verification` in config/auth.php. Most accounts in the live
         * register have `email_verified_at` NULL because the resend endpoint
         * used to answer "sent" without sending, so switching this on today
         * would lock the client's testers out over mail they never received.
         *
         * Placed after the door check so the citizen door's refusal cannot
         * start depending on an account's verification state — that would be a
         * new oracle in the shape of the one item #63 just closed. Placed
         * before the token is minted, because a gate that issues a session
         * first is not a gate.
         *
         * 403 with a message the sign-in page can act on: this one names a real
         * condition of the reader's OWN account, reached only with the correct
         * password, so it tells a stranger nothing they did not already have.
         */
        if (config('auth.verification.required_at_login') && ! $user->hasVerifiedEmail()) {
            RateLimiter::clear($key);

            return response()->json([
                'message' => 'Confirm your email address before signing in. Check your inbox for the link we sent when you registered.',
            ], 403);
        }

        /*
         * The second step [checklist 2026-09-27, Login 5]: with a real mailer,
         * a right password earns a code by e-mail, not a session. Everyone —
         * owners, officers, the super admin. With mail off (the demo) the
         * password is enough, as it always was.
         */
        if (EmailSwitch::on()) {
            return $this->startSignInCode($user, $portal);
        }

        return $this->completeSignIn($user, $portal, $key);
    }

    private function loginKey(string $email, Request $request): string
    {
        return 'login:'.Str::lower($email).'|'.$request->ip();
    }

    /** Everything a finished sign-in does, whichever step finished it. */
    private function completeSignIn(User $user, string $portal, string $key): JsonResponse
    {
        RateLimiter::clear($key);
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ])->save();
        Audit::log('user.logged_in', $user, actorId: $user->id);

        /*
         * An LGU sign-in outside office hours is written down [checklist
         * 2026-09-27, Login 6]. The officer is told so on the page; this is the
         * row the administrator reads. Owners are not recorded: filing at night
         * is what an online counter is for, and logging it would be noise.
         */
        if ($portal !== 'public' && ! OfficeHours::isOpen()) {
            Audit::log('user.signed_in_outside_hours', $user, [
                'portal' => $portal,
                'local_time' => OfficeHours::now()->format('D, j M Y H:i'),
            ], actorId: $user->id);
        }

        return $this->authPayload($user, $portal);
    }

    /**
     * Send the sign-in code and hand the page the handle it needs for step two.
     *
     * No token here, and the lockout counters are not reset yet: the password
     * was right, but the sign-in is not finished until the code is.
     */
    private function startSignInCode(User $user, string $portal): JsonResponse
    {
        [$row, $code, $challenge] = EmailCodes::issueLogin($user, $portal);

        try {
            EmailCodes::send($user, EmailCode::LOGIN, $code);
        } catch (\Throwable $e) {
            /*
             * The code did not go, so the sign-in cannot finish, and the page
             * must say that rather than ask for a code that is not coming. The
             * row is closed so nothing is left half-open. 503 because this is
             * the relay's outage, not the reader's mistake. The cost is written
             * in docs/email-setup.md: while the relay is down, nobody can sign
             * in.
             */
            $row->forceFill(['consumed_at' => now()])->save();
            Log::error('Sign-in code could not be sent.', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "We couldn't send your sign-in code just now. Try again in a few minutes.",
            ], 503);
        }

        return response()->json([
            'data' => [
                'code_required' => true,
                'challenge' => $challenge,
                'email' => EmailCodes::mask($user->email),
                'expires_in_minutes' => EmailCodes::minutes(EmailCode::LOGIN),
                'resend_after' => (int) config('auth.email_codes.resend_after', 60),
            ],
        ]);
    }

    /**
     * Step two of a sign-in: the code from the e-mail [Login 5].
     *
     * A wrong code counts against the account exactly as a wrong password
     * does — same limiter key, same `failed_login_attempts`. Without that,
     * somebody holding the password could start a fresh challenge every five
     * guesses and walk the million codes at leisure; with it, five wrong codes
     * lock the account for fifteen minutes, as five wrong passwords do.
     */
    public function verifySignInCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $row = EmailCodes::findChallenge($data['challenge']);
        $user = $row?->user;

        if (! $row || ! $user || ! $user->is_active) {
            return $this->deadSignInCode();
        }

        $key = $this->loginKey($user->email, $request);
        $lockedFor = $user->locked_until?->isFuture() ? (int) now()->diffInSeconds($user->locked_until) : 0;

        if (RateLimiter::tooManyAttempts($key, 5) || $lockedFor > 0) {
            $minutes = max(1, (int) ceil(max(RateLimiter::availableIn($key), $lockedFor) / 60));

            return response()->json([
                'message' => "Account temporarily locked. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
            ], 429);
        }

        $result = $this->tryCode($row, $data['code']);

        if ($result['status'] === 'ok') {
            /*
             * Typing the code proves the reader can open this inbox, which is
             * all confirming an address ever meant. So an account that never
             * confirmed is confirmed now, rather than being sent a second code
             * straight after this one.
             */
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
                Audit::log('user.email_verified', $user, ['via' => 'sign-in code'], actorId: $user->id);
            }

            return $this->completeSignIn($user->fresh(), (string) $row->portal, $key);
        }

        if ($result['status'] === 'malformed') {
            return $this->codeRefusal('Enter the 6 digits from the e-mail.');
        }

        RateLimiter::hit($key, 15 * 60);
        $attempts = $user->failed_login_attempts + 1;
        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= 5 ? now()->addMinutes(15) : $user->locked_until,
        ])->save();

        if ($result['status'] === 'dead') {
            return $this->deadSignInCode();
        }

        return $this->codeRefusal($this->triesLeft($result['remaining']));
    }

    /** A new sign-in code for the same attempt, rationed [Login 5]. */
    public function resendSignInCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:128'],
        ]);

        $row = EmailCodes::findChallenge($data['challenge']);
        $user = $row?->user;

        if (! $row || ! $user || ! $user->is_active) {
            return $this->deadSignInCode();
        }

        $wait = EmailCodes::resendWait($row);

        if ($wait === null) {
            return $this->deadSignInCode();
        }

        if ($wait > 0) {
            return response()->json([
                'message' => "You can ask for a new code in {$wait} second".($wait === 1 ? '' : 's').'.',
                'retry_after' => $wait,
            ], 429);
        }

        $code = EmailCodes::refresh($row);

        try {
            EmailCodes::send($user, EmailCode::LOGIN, $code);
        } catch (\Throwable $e) {
            Log::error('Sign-in code could not be resent.', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "We couldn't send a new code just now. Try again in a few minutes.",
            ], 503);
        }

        return response()->json([
            'message' => 'We sent a new code. Use the newest one.',
            'data' => ['resend_after' => (int) config('auth.email_codes.resend_after', 60)],
        ]);
    }

    /**
     * A typed code, tidied and tried. Spaces and dashes are how people copy a
     * code out of an e-mail, so they are dropped. Anything that is still not
     * six digits is `malformed` and costs no try: it could not have been right,
     * and charging a guess for a typo is how a reader gets locked out by a
     * phone keyboard.
     *
     * @return array{status: 'ok'|'wrong'|'dead'|'malformed', remaining: int}
     */
    private function tryCode(EmailCode $row, string $typed): array
    {
        $code = (string) preg_replace('/[\s-]/', '', $typed);

        if (! $row->isLive()) {
            return ['status' => 'dead', 'remaining' => 0];
        }

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return ['status' => 'malformed', 'remaining' => 0];
        }

        return EmailCodes::check($row, $code);
    }

    private function triesLeft(int $left): string
    {
        return "That code is not right. You have {$left} ".($left === 1 ? 'try' : 'tries').' left.';
    }

    private function codeRefusal(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['code' => [$message]]], 422);
    }

    /**
     * One answer for every sign-in code that cannot work any more — used,
     * expired, guessed out, or never ours. `reason` lets the page take the
     * reader back to the password step instead of leaving them at a dead box.
     */
    private function deadSignInCode(): JsonResponse
    {
        return response()->json([
            'message' => 'This code no longer works. Sign in again to get a new one.',
            'reason' => 'code_expired',
        ], 422);
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
            /*
             * Eleven digits in local 09 form, and nothing else.
             *
             * `string|max:20` accepted anything short enough, and the Edit
             * Profile field passed on whatever was typed — which is how the
             * demo owner's number came to be the three characters "09d". A
             * client-side restriction alone would not have stopped it: the rule
             * has to hold at the endpoint, because the field is not the only
             * thing that can call it.
             *
             * Deliberately narrower than validateMobile on the web side, which
             * also accepts +639…; the field now permits digits only, so the
             * international form cannot be typed and storing two spellings of
             * one number would only make them harder to compare later.
             */
            'mobile_number' => ['required', 'string', 'regex:/^09\d{9}$/'],
            /*
             * The home address [checklist 2026-09-28, Register 2]: how an
             * owner who registered before it was asked completes it. What an
             * absent, emptied or partial answer does is on homeAddressRules.
             */
            ...$this->homeAddressRules(onUpdate: true),
        ], [
            'mobile_number.regex' => 'A mobile number is 11 digits and starts with 09, as in 09171234567.',
            ...$this->homeAddressMessages(),
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
         *
         * The home address goes through the same helper for its "absent keeps"
         * half only: of its parts, ZIP alone may be sent empty, and the
         * validation above refuses an emptied street, barangay, city or
         * province before this runs.
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
            'home_street' => $optional('home_street'),
            'home_barangay' => $optional('home_barangay'),
            'home_city' => $optional('home_city'),
            'home_province' => $optional('home_province'),
            'home_postal_code' => $optional('home_postal_code'),
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
     *
     * With a real mailer it also requires the code from requestPasswordCode
     * [checklist 2026-09-27, Edit Settings; closes View Profile 3]. A session
     * left open on a shared computer, plus a password read over a shoulder,
     * was enough to take the account over for good — the change signs every
     * other device out, the real owner included. The code adds the one thing
     * neither of those gives: the inbox. With mail off (the demo) the current
     * password is enough, as it was, because a code nobody can receive would
     * make the password impossible to change.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $codeRequired = EmailSwitch::on();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            // Not validated at all with mail off, so a request shaped as it
            // was before this feature is answered exactly as it was.
            ...($codeRequired ? ['code' => ['required', 'string', 'max:32']] : []),
        ], [
            'code.required' => 'Enter the 6-digit code we e-mailed you. Press Send code if you do not have one.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is incorrect.'],
            ]);
        }

        if ($codeRequired) {
            if ($locked = $this->lockedResponse($user)) {
                return $locked;
            }

            $row = EmailCodes::latestPassword($user);
            $result = $row ? $this->tryCode($row, $data['code']) : ['status' => 'dead', 'remaining' => 0];

            if ($result['status'] !== 'ok') {
                /*
                 * Wrong and dead codes count against the account as a wrong
                 * sign-in code does (see verifySignInCode), `malformed` does
                 * not. Without this, whoever holds the session and the
                 * password asks for a fresh code every five guesses and walks
                 * the million; with it, five wrong codes lock the account for
                 * fifteen minutes. The cost falls on an owner who mistypes the
                 * code five times: their other devices cannot sign in for
                 * fifteen minutes, and this session is left alone. A request
                 * with no code ever sent (`$row` null) cannot have been a
                 * guess and is not counted.
                 */
                if ($row && $result['status'] !== 'malformed') {
                    $this->countFailedCode($user);
                }

                return $this->codeRefusal(match ($result['status']) {
                    'dead' => 'This code no longer works. Send yourself a new one.',
                    'malformed' => 'Enter the 6 digits from the e-mail.',
                    default => $this->triesLeft($result['remaining']),
                });
            }
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Revoke all other sessions (keep the one performing the change).
        $user->tokens()
            ->where('id', '!=', $user->currentAccessToken()->id)
            ->delete();

        Audit::log('user.password_changed', $user, $codeRequired ? ['via' => 'email code'] : []);

        return response()->json([
            'message' => 'Password updated. Other signed-in devices have been logged out.',
        ]);
    }

    /**
     * E-mail the code a password change needs, and send it again when asked
     * [checklist 2026-09-27, Edit Settings].
     *
     * The current password is checked FIRST, before anything is looked up,
     * issued or sent. A stolen session on its own therefore cannot fill the
     * owner's inbox with codes, and cannot learn from this endpoint whether
     * mail is on or where the address points — it gets the same refusal as a
     * mistyped password.
     *
     * One endpoint for the first code and every resend. While a code is still
     * good, asking again re-sends a new number on the SAME row, so the wrong
     * guesses already made stay counted (the rule the sign-in resend follows);
     * one a minute, five per code (auth.email_codes). Only once it has expired,
     * been used or been guessed out does a new row start.
     *
     * With mail off it sends nothing and says `code_required: false`: the
     * change goes through on the password alone, as it always has.
     */
    public function requestPasswordCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is incorrect.'],
            ]);
        }

        if (! EmailSwitch::on()) {
            return response()->json(['data' => ['code_required' => false]]);
        }

        if ($locked = $this->lockedResponse($user)) {
            return $locked;
        }

        $row = EmailCodes::latestPassword($user);
        $resend = $row !== null && $row->isLive();

        if ($resend) {
            $wait = EmailCodes::resendWait($row);

            // Five sends of one code: wait for it to run out rather than
            // starting a new one, or the ration would reset on demand.
            $wait ??= max(1, (int) ceil(now()->diffInSeconds($row->expires_at)));

            if ($wait > 0) {
                return response()->json([
                    'message' => "You can ask for a new code in {$wait} second".($wait === 1 ? '' : 's').'.',
                    'retry_after' => $wait,
                ], 429);
            }

            $code = EmailCodes::refresh($row);
        } else {
            [$row, $code] = EmailCodes::issuePassword($user);
        }

        try {
            EmailCodes::send($user, EmailCode::PASSWORD, $code);
        } catch (\Throwable $e) {
            // Closed so a code that never arrived cannot be half-used later.
            // Same 503 and wording as the sign-in code.
            $row->forceFill(['consumed_at' => now()])->save();
            Log::error('Password-change code could not be sent.', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "We couldn't send your code just now. Try again in a few minutes.",
            ], 503);
        }

        // Never the code: the audit trail is read by administrators.
        Audit::log('user.password_code_sent', $user, ['resend' => $resend]);

        return response()->json([
            'message' => 'We sent a 6-digit code to '.EmailCodes::mask($user->email).'.',
            'data' => [
                'code_required' => true,
                'email' => EmailCodes::mask($user->email),
                'expires_in_minutes' => EmailCodes::minutes(EmailCode::PASSWORD),
                'resend_after' => (int) config('auth.email_codes.resend_after', 60),
            ],
        ]);
    }

    /**
     * The account lockout, as sign-in applies it: 429 while `locked_until` is
     * in the future. Used by the password-code steps so five wrong codes stop
     * the guessing here as well as at the sign-in page.
     */
    private function lockedResponse(User $user): ?JsonResponse
    {
        if (! $user->locked_until?->isFuture()) {
            return null;
        }

        $minutes = max(1, (int) ceil(now()->diffInSeconds($user->locked_until) / 60));

        return response()->json([
            'message' => "Account temporarily locked. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
        ], 429);
    }

    /** One more failed attempt on the account; the fifth locks it for fifteen minutes. */
    private function countFailedCode(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;
        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= 5 ? now()->addMinutes(15) : $user->locked_until,
        ])->save();
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

    /**
     * The link in the verification email [checklist item #61].
     *
     * ── What this replaced, and why it had to go ────────────────────────────
     *
     * There was a `POST /auth/email/verify` here taking `{id, hash}`, no auth
     * and no signature, that marked an account verified when the hash matched
     * sha1 of its own email address. Both halves are public knowledge: ids run
     * in sequence and the address is the thing being "proved". Anyone could
     * therefore mark anyone's address confirmed, including an address they had
     * never been able to read. It was the verification equivalent of the stub
     * below it — a check that looked like one and asserted nothing.
     *
     * This route is signed instead. Laravel's `hasValidSignature()` verifies an
     * HMAC over the whole URL using APP_KEY, so the link cannot be constructed
     * without the application's own secret, and it carries an expiry
     * (auth.verification.expire) so a link left in an inbox stops working.
     *
     * ── Why the signature is checked here rather than by `signed` middleware ─
     *
     * This address is opened by a person clicking a link in their mail client,
     * not by the SPA. The `signed` middleware aborts 403 with Laravel's JSON or
     * error page, which is a dead end for someone who simply took too long to
     * open their email. Checking it in the method lets every outcome end on the
     * app's own screen, with wording that says which of the three things
     * happened. GET with a side effect is deliberate for the same reason: a
     * mail client cannot POST.
     */
    public function verifyEmailLink(Request $request, string $id, string $hash): RedirectResponse
    {
        $app = rtrim((string) config('app.frontend_url'), '/');

        if (! $request->hasValidSignature()) {
            return redirect()->away($app.'/verify-email?status=expired');
        }

        $user = User::find($id);

        // Constant-time, and not because the hash is a secret — it is sha1 of a
        // published address. It is the convention Laravel's own controller
        // uses, and a timing-safe compare is never the wrong one to reach for.
        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->away($app.'/verify-email?status=invalid');
        }

        if ($user->hasVerifiedEmail()) {
            // Not an error. Clicking the same link twice, or having it
            // prefetched by a mail scanner and then clicking it, is ordinary.
            return redirect()->away($app.'/verify-email?status=already');
        }

        $user->markEmailAsVerified();
        Audit::log('user.email_verified', $user);

        return redirect()->away($app.'/verify-email?status=verified');
    }

    /**
     * Send the verification email again, for real this time [item #61].
     *
     * What was here returned `{"message": "Verification email sent."}` and sent
     * nothing, under a comment calling verification "simulated for the
     * prototype". A tester pressing the button got a green success and an empty
     * inbox, and had no way to tell that apart from a message caught by a spam
     * filter. A feature that is not built is a gap; one that reports success is
     * a lie, and it cost somebody an afternoon of looking in the wrong place.
     *
     * ── Throttled twice, for two different reasons ──────────────────────────
     *
     * The route carries `throttle:6,1` (routes/api.php) — that is the framework
     * convention and it guards the ENDPOINT. The limiter below is per ACCOUNT
     * and much tighter, because the thing being rationed is not requests, it is
     * mail: an unthrottled resend turns this app into a way to post messages
     * into somebody's inbox from a City Hall address, which costs the LGU its
     * sender reputation and the recipient their patience.
     *
     * Three in fifteen minutes, keyed on the user rather than the IP: the point
     * is to protect the address, and the address does not change when the
     * attacker's does.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Your email address is already confirmed.',
            ]);
        }

        $key = 'verify-resend:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            return response()->json([
                'message' => "We've already sent a few. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
            ], 429);
        }

        RateLimiter::hit($key, 15 * 60);

        /*
         * Same swallow-and-log as registration, and for the same reason: a
         * mailer outage must not answer this button with a 500 the reader
         * cannot act on. It must not answer with a cheerful "sent" either —
         * that is the bug this method exists to fix — so the failure gets its
         * own 502 and its own sentence.
         *
         * A code with mail on, the link with mail off: the same split as
         * registration.
         */
        try {
            if (EmailSwitch::on()) {
                [, $code] = EmailCodes::issueVerify($user);
                EmailCodes::send($user, EmailCode::VERIFY, $code);
            } else {
                $user->sendEmailVerificationNotification();
            }
        } catch (\Throwable $e) {
            Log::error('Verification email failed to resend.', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "We couldn't send that email just now. Try again in a few minutes.",
            ], 502);
        }

        Audit::log('user.verification_resent', $user);

        return response()->json([
            'message' => EmailSwitch::on()
                ? 'We sent a new code to '.EmailCodes::mask($user->email).'. It can take a minute.'
                : 'Verification email sent. Check your inbox — it can take a minute.',
        ]);
    }

    /**
     * Confirm the signed-in owner's address with the code from the e-mail
     * [checklist 2026-09-27, Register 1].
     *
     * Behind auth:sanctum, so the code only has to prove the inbox; the session
     * already proves the account. Five wrong guesses close the code, and the
     * reader asks for a new one with the button beside the box.
     */
    public function verifyEmailCode(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Your email address is already confirmed.',
                'data' => $this->userPayload($user),
            ]);
        }

        $row = EmailCodes::latestVerify($user);
        $result = $row ? $this->tryCode($row, $data['code']) : ['status' => 'dead', 'remaining' => 0];

        if ($result['status'] === 'ok') {
            $user->markEmailAsVerified();
            Audit::log('user.email_verified', $user, ['via' => 'code']);

            return response()->json([
                'message' => 'Your email address is confirmed.',
                'data' => $this->userPayload($user->fresh()),
            ]);
        }

        return $this->codeRefusal(match ($result['status']) {
            'dead' => 'This code no longer works. Send yourself a new one.',
            'malformed' => 'Enter the 6 digits from the e-mail.',
            default => $this->triesLeft($result['remaining']),
        });
    }

    /**
     * Replace the signed-in user's profile photo.
     *
     * Images only, and a tighter list than DocumentController takes: a PDF is a
     * sensible scan of a permit and a nonsensical avatar, and the `<img>` that
     * renders this cannot display one anyway. 5 MB rather than the documents'
     * 10 MB for the same reason — this is a face, not an A4 scan, and the cap
     * that matters is the one the client checks before uploading.
     */
    public function updatePhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'photo.required' => 'Choose an image to upload.',
            'photo.mimes' => 'Upload a JPG or PNG image.',
            'photo.max' => 'That image is over 5 MB. Try a smaller photo.',
        ]);

        $user = $request->user();
        $file = $request->file('photo');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension());
        $directory = "private/avatars/{$user->id}";
        $filename = Str::uuid()->toString().'.'.$ext;

        Storage::disk('local')->putFileAs($directory, $file, $filename);

        /*
         * Write the row before deleting the old file, and only delete once the
         * new path is safely stored. The other order — delete, then save — loses
         * the existing photo if the save throws, leaving a row pointing at a
         * file that is gone and an avatar that 404s.
         */
        $previous = $user->avatar_path;
        $user->avatar_path = "{$directory}/{$filename}";
        $user->save();

        if ($previous && $previous !== $user->avatar_path) {
            Storage::disk('local')->delete($previous);
        }

        Audit::log('user.photo_updated', $user);

        return response()->json(['data' => $this->userPayload($user)]);
    }

    /**
     * Stream the signed-in user's own photo.
     *
     * There is no user id in the route on purpose. The photo is read off the
     * authenticated row, so no request can name someone else's file — the
     * separability rule that governs filings applies to a face as much as to a
     * permit.
     */
    public function showPhoto(Request $request): StreamedResponse
    {
        $user = $request->user();

        abort_if($user->avatar_path === null, 404, 'No profile photo.');
        abort_unless(Storage::disk('local')->exists($user->avatar_path), 404, 'File not found.');

        return Storage::disk('local')->response($user->avatar_path);
    }

    /**
     * Drop the photo and fall back to the glyph.
     *
     * Without this the control is a one-way door: a photo uploaded by mistake
     * could be replaced but never taken back off, and "no photo" is the state
     * every account starts in.
     */
    public function destroyPhoto(Request $request): JsonResponse
    {
        $user = $request->user();
        $previous = $user->avatar_path;

        if ($previous !== null) {
            $user->avatar_path = null;
            $user->save();
            Storage::disk('local')->delete($previous);
            Audit::log('user.photo_removed', $user);
        }

        return response()->json(['data' => $this->userPayload($user)]);
    }
}
