<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Audit;
use App\Support\Caseload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Admin user management — create/edit officers, move a caseload, toggle
 * activation. Backs the Officer Assignment screen.
 */
class UserController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Roles an admin may actually hand out on this screen, in display order.
     *
     * `business_owner` is deliberately absent. An owner account is made by
     * self-registration, which also records the data-privacy consent and ties
     * the account to a business it owns; minting one here would produce an
     * account with neither, sitting in the citizen portal with nothing to do.
     * The screen has always excluded owners from its listing — this stops the
     * endpoint from being a way round that.
     */
    private const EXCLUDED_ROLES = ['business_owner'];

    /**
     * The one role that must NOT hold a department, and every other role must.
     *
     * Both halves matter and both were unenforced.
     *
     * An officer with no office sees an empty queue: AssignmentController's
     * scopeToDepartment sends a departmentless non-admin down `whereRaw('1=0')`.
     * So "create a Sanitary Officer, leave Office blank" produced an account
     * that signed in successfully and could see nothing, with no error anywhere
     * to explain it.
     *
     * The super admin is the mirror image. AssignmentController decides who may
     * reassign another office's case by asking whether the caller has NO
     * department — that structural test is what lets a city-wide coordinator
     * cross offices while an office's own OIC cannot. Giving the super admin a
     * department here would quietly revoke their reassignment power, and nothing
     * would fail loudly enough to connect the two.
     */
    private const DEPARTMENTLESS_ROLE = 'admin';

    /**
     * Every role that must NOT hold an office — the super admin and citizens.
     *
     * `business_owner` belongs here for a different reason than `admin`: an
     * owner is not staff at all, so there is no office for them to be in. It is
     * separate from DEPARTMENTLESS_ROLE because that one also drives the
     * `wants_department` flag on the roles endpoint, and that endpoint only ever
     * lists roles an admin may assign — which owners are not.
     *
     * Getting this wrong is not theoretical: with only `admin` listed, editing a
     * citizen's surname through this endpoint answered "Choose an office. An
     * officer with no office signs in to an empty queue" — a sentence that is
     * both wrong and impossible to act on, about an account that must never have
     * one.
     */
    private const OFFICELESS_ROLES = ['admin', 'business_owner'];

    /**
     * The staff directory. Paginated, alphabetical.
     *
     * Alphabetical rather than newest-first on purpose: this is a directory you
     * look somebody up in, not a feed. The payload carries every role and every
     * permission per user, so it grows faster than the row count suggests.
     */
    public function index(Request $request): JsonResponse
    {
        /*
         * "true"/"false" are folded to booleans before the rules run, for every
         * boolean this endpoint accepts.
         *
         * Laravel's `boolean` rule accepts true, false, 1, 0, "1" and "0" — and
         * NOT the strings "true" and "false", which is exactly what any JS
         * client produces when it puts a boolean in a query string. So the
         * status filter on the Officer Assignment screen 422'd the entire staff
         * directory the moment anyone chose "Active only": the screen went to an
         * error state, and the message named a field with no visible control.
         *
         * Only those two spellings are folded. "yes", "on" and nonsense still
         * fail the rule, so this widens the contract rather than abandoning it.
         */
        foreach (['is_active', 'staff'] as $flag) {
            $raw = $request->query($flag);
            if (is_string($raw) && in_array(strtolower($raw), ['true', 'false'], true)) {
                $request->merge([$flag => strtolower($raw) === 'true']);
            }
        }

        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role' => ['sometimes', 'nullable', 'string', 'max:60'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            /*
             * Tri-state, and `sometimes` is doing real work: absent means "both",
             * which is not the same as false. A plain boolean rule would read a
             * missing filter as "inactive only" the moment a caller omitted it.
             */
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            /*
             * Staff only — leave the citizens out.
             *
             * The Officer Assignment screen used to pull the directory and drop
             * business owners in the browser. Moving the listing server-side
             * lost that filter, so three citizens appeared on a screen whose
             * every action is about officers: Reassign (they hold no caseload),
             * Edit (their role cannot be assigned from here) and Deactivate.
             * Not a leak — the reader already holds `user.manage` — but the
             * wrong roster, and one that made "Showing 11 of 11 accounts" a
             * misleading count of a seven-office staff.
             */
            'staff' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = User::with('department', 'roles.permissions')
            ->orderBy('name')
            ->orderBy('id');

        if ($q = $request->query('q')) {
            $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"));
        }
        if ($role = $request->query('role')) {
            $query->whereHas('roles', fn ($r) => $r->where('name', $role));
        }
        // Lets the officer picker on the review screen ask for one office's
        // staff instead of paging the whole directory to find them.
        if ($departmentId = $request->query('department_id')) {
            $query->where('department_id', $departmentId);
        }
        if ($request->has('is_active') && $request->query('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->boolean('staff')) {
            $query->whereDoesntHave('roles', fn ($r) => $r->whereIn('name', self::EXCLUDED_ROLES));
        }

        $users = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'meta' => $this->pageMeta($users),
        ]);
    }

    /**
     * The roles this screen may assign, with the labels the register already holds.
     *
     * The Officer Assignment form used to carry its own hard-coded list of four
     * roles and its own map of labels. The register has nine roles across seven
     * offices, so four of the city's offices — Zoning, Building Official, CENRO
     * and the Market Administrator — simply could not be staffed from the admin
     * screen, and the three missing from the label map rendered as raw
     * `obo_staff` in the table. `roles.display_name` has held the right words
     * since the first migration; nothing read them.
     */
    public function roles(): JsonResponse
    {
        $roles = Role::whereNotIn('name', self::EXCLUDED_ROLES)
            ->orderBy('display_name')
            ->get(['name', 'display_name', 'description']);

        return response()->json([
            'data' => $roles->map(fn (Role $role) => [
                'name' => $role->name,
                'label' => $role->display_name,
                'description' => $role->description,
                // The form has to know which choice hides the Office field, and
                // it must not learn that by hard-coding the string 'admin'.
                'wants_department' => $role->name !== self::DEPARTMENTLESS_ROLE,
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateUser($request, creating: true);

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
            'department_id' => $data['department_id'] ?? null,
            'is_active' => true,
            'data_privacy_consent_at' => now(),
            'email_verified_at' => now(),
        ]);
        $user->roles()->sync(Role::whereIn('name', $data['roles'])->pluck('id'));

        Audit::log('user.created', $user, ['roles' => $data['roles']]);

        return response()->json([
            'data' => new UserResource($user->load('department', 'roles.permissions')),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $this->validateUser($request, creating: false, user: $user);

        /*
         * `password` is dropped unless one was actually typed.
         *
         * It used to go straight through this fill(). The rule is `nullable`, so
         * an edit form that always posts its password field — empty, because the
         * admin came to fix a typo in a surname — sends `password: ""`, and
         * ConvertEmptyStringsToNull turns that into null. The `hashed` cast
         * passes null through untouched, and `users.password` is NOT NULL, so
         * the whole edit died on a PDOException and the endpoint answered 500:
         * the surname change was lost and the message said nothing about
         * passwords. Where the column is nullable the same write succeeds and is
         * worse — a 200 that has locked the account out of every password it
         * will ever be given.
         */
        $writable = collect($data)->except(['roles', 'email']);
        if (! filled($data['password'] ?? null)) {
            $writable->forget('password');
        }

        /*
         * Moving an officer to another office leaves their old office's cases
         * behind them.
         *
         * Editing the Office field used to be a bare column write, so the
         * officer landed in Fire while still named on City Health's open
         * reviews — rows AssignmentController now refuses to show them, because
         * it scopes by the officer's CURRENT department. The case was live, had
         * a name against it, and appeared in nobody's queue.
         */
        $movingOffice = array_key_exists('department_id', $data)
            && (int) $data['department_id'] !== (int) $user->department_id;
        $released = $movingOffice ? $this->releaseCaseload($user, 'user.office_changed') : null;

        $user->fill($writable->toArray());
        if (isset($data['email'])) {
            $user->email = strtolower(trim($data['email']));
        }
        if (isset($data['first_name']) || isset($data['last_name'])) {
            $user->name = trim(($data['first_name'] ?? $user->first_name).' '.($data['last_name'] ?? $user->last_name));
        }
        $user->save();

        if (isset($data['roles'])) {
            $user->roles()->sync(Role::whereIn('name', $data['roles'])->pluck('id'));
        }

        Audit::log('user.updated', $user);

        return response()->json([
            'data' => new UserResource($user->fresh()->load('department', 'roles.permissions')),
            'meta' => $released ? ['released' => $released] : [],
        ]);
    }

    /**
     * What this officer is holding, and who could take it.
     *
     * One call, because the Reassign dialog and the Deactivate warning both have
     * to state the same two numbers before the admin commits to anything. A
     * dialog that says "Confirm" without saying what it is about to move is how
     * a caseload goes somewhere nobody meant it to.
     */
    public function caseload(Request $request, User $user): JsonResponse
    {
        $summary = Caseload::summary($user);

        /*
         * Candidates are same-office and active, because those are the only
         * people the move can actually land on: AssignmentController::assign
         * refuses an officer from another department, and an inactive account
         * cannot sign in to work the case. Offering names the action would
         * reject is worse than offering none — the admin picks one, confirms,
         * and gets a 422 naming a rule the dialog never mentioned.
         */
        $candidates = $user->department_id === null
            ? collect()
            : User::where('department_id', $user->department_id)
                ->where('id', '!=', $user->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email']);

        return response()->json([
            'data' => [
                'user' => ['id' => $user->id, 'name' => $user->name],
                'department' => $user->department
                    ? ['id' => $user->department->id, 'code' => $user->department->code, 'name' => $user->department->name]
                    : null,
                'open_reviews' => $summary['reviews'],
                'open_inspections' => $summary['inspections'],
                'total' => $summary['total'],
                'candidates' => $candidates->map(fn (User $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'email' => $c->email,
                    'open_total' => Caseload::summary($c)['total'],
                ])->all(),
            ],
        ]);
    }

    /**
     * Move an officer's open work to a colleague, or release it to the office.
     *
     * ── What this replaces ───────────────────────────────────────────────────
     *
     * The Reassign dialog on Officer Assignment was a mock. It collected a
     * scope, a target and a reason, showed "✓ Reassignment recorded", and moved
     * nothing — the small print said "Demo preview only", which is the honest
     * half, but the green tick is what an admin reads. The screen's whole
     * purpose is naming who is in charge of what, and it was the one thing on
     * it that did not happen.
     *
     * ── Why a null target is a first-class answer, not a missing one ─────────
     *
     * Every office in the register is one officer deep today, so "hand it to
     * somebody else in the same office" frequently has no candidate at all. The
     * useful action then is to put the case back in the office's pool, where it
     * is visible to whoever the office next staffs — an assignment with no
     * officer is the ordinary state a case starts in, not a broken one. Refusing
     * the whole operation for want of a named successor would leave the work on
     * the officer who has gone.
     */
    public function reassignCaseload(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            // Null is meaningful: release to the office queue. `present` so a
            // caller has to say which they mean rather than fall into one.
            'to_user_id' => ['present', 'nullable', 'integer', 'exists:users,id'],
            'scope' => ['required', Rule::in(['all', 'reviews', 'inspections'])],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Say why this caseload is moving — it is recorded against both officers.',
        ]);

        $target = $data['to_user_id'] ? User::findOrFail($data['to_user_id']) : null;

        if ($target) {
            if ($target->id === $user->id) {
                throw ValidationException::withMessages([
                    'to_user_id' => ['That is the same officer. Choose a colleague, or release the caseload to the office.'],
                ]);
            }
            /*
             * Same rule as AssignmentController::assign, checked once here
             * rather than discovered on row 14 of 20 — this loop writes, so a
             * refusal partway through would leave half a caseload moved.
             */
            if ($target->department_id !== $user->department_id) {
                throw ValidationException::withMessages([
                    'to_user_id' => ['An officer can only take cases from their own office.'],
                ]);
            }
            if (! $target->is_active) {
                throw ValidationException::withMessages([
                    'to_user_id' => ['That account is deactivated, so it cannot take a caseload.'],
                ]);
            }
        }

        $moved = DB::transaction(function () use ($user, $target, $data) {
            $moved = ['reviews' => 0, 'inspections' => 0];

            if ($data['scope'] !== 'inspections') {
                $moved['reviews'] = Caseload::reviews($user)->get()
                    ->each(function ($assignment) use ($target, $data) {
                        $assignment->update(['officer_user_id' => $target?->id]);
                        Audit::log('assignment.reassigned', $assignment, [
                            'officer_user_id' => $target?->id,
                            'reason' => $data['reason'],
                        ]);
                    })->count();
            }

            if ($data['scope'] !== 'reviews') {
                $moved['inspections'] = Caseload::inspections($user)->get()
                    ->each(function ($inspection) use ($target, $data) {
                        $inspection->update(['inspector_user_id' => $target?->id]);
                        Audit::log('inspection.reassigned', $inspection, [
                            'inspector_user_id' => $target?->id,
                            'reason' => $data['reason'],
                        ]);
                    })->count();
            }

            Audit::log('user.caseload_reassigned', $user, [
                'to_user_id' => $target?->id,
                'scope' => $data['scope'],
                'reason' => $data['reason'],
            ] + $moved);

            return $moved;
        });

        $total = $moved['reviews'] + $moved['inspections'];

        /*
         * Tell the officer who just inherited the work. Reassignment is the one
         * action on this screen whose whole effect lands on somebody else's
         * queue, and a caseload that appears overnight with no explanation is
         * indistinguishable from a bug in the queue.
         */
        if ($target && $total > 0) {
            $this->notifications->push(
                $target,
                'assignment',
                'Cases reassigned to you',
                "{$total} open ".($total === 1 ? 'case has' : 'cases have')." been moved to you from {$user->name}. Reason: {$data['reason']}",
                '/staff/queue',
            );
        }

        return response()->json([
            'data' => [
                'moved_reviews' => $moved['reviews'],
                'moved_inspections' => $moved['inspections'],
                'total' => $total,
                'to' => $target ? ['id' => $target->id, 'name' => $target->name] : null,
            ],
        ]);
    }

    /**
     * Activate or deactivate an account.
     *
     * Deactivation now takes the caseload with it. It used to delete the
     * officer's tokens and stop — correct as far as it went, and it left every
     * open review and scheduled inspection still bearing the name of somebody
     * who can no longer sign in. Nothing was flagged, no queue showed the work
     * as loose, and the only way to find it was to already know. Releasing to
     * the office pool is the same state a case occupies before anyone picks it
     * up, so the office sees it as work waiting rather than work done.
     */
    public function toggleActive(Request $request, User $user): JsonResponse
    {
        // Route-gated by permission:owner.manage_status.
        $released = null;
        if ($user->is_active) {
            $released = $this->releaseCaseload($user, 'user.deactivated');
        }

        $user->update(['is_active' => ! $user->is_active]);
        if (! $user->is_active) {
            $user->tokens()->delete();
        }
        Audit::log('user.toggle_active', $user, ['is_active' => $user->is_active] + ($released ?? []));

        return response()->json([
            'data' => new UserResource($user->fresh()->load('department', 'roles.permissions')),
            'meta' => $released ? ['released' => $released] : [],
        ]);
    }

    /**
     * Hand this officer's open work back to their office, recording why.
     *
     * @return array{reviews: int, inspections: int}|null null when there was nothing to release
     */
    private function releaseCaseload(User $user, string $reason): ?array
    {
        $released = DB::transaction(function () use ($user, $reason) {
            $reviews = Caseload::reviews($user)->get()->each(function ($assignment) use ($reason) {
                $assignment->update(['officer_user_id' => null]);
                Audit::log('assignment.reassigned', $assignment, ['officer_user_id' => null, 'reason' => $reason]);
            })->count();

            $inspections = Caseload::inspections($user)->get()->each(function ($inspection) use ($reason) {
                $inspection->update(['inspector_user_id' => null]);
                Audit::log('inspection.reassigned', $inspection, ['inspector_user_id' => null, 'reason' => $reason]);
            })->count();

            return ['reviews' => $reviews, 'inspections' => $inspections];
        });

        return $released['reviews'] + $released['inspections'] > 0 ? $released : null;
    }

    /**
     * Validate a create or an edit, and normalise the role field.
     *
     * ── The `role` / `roles` split ───────────────────────────────────────────
     *
     * This endpoint has always validated `roles` (an array). The published
     * client contract — web/src/lib/types.ts AdminUserPayload — has always sent
     * `role` (a string). So "Add Officer" could not create anybody: the request
     * 422'd on a missing `roles`, and the modal renders errors under the key
     * `role`, so the one message explaining the failure was addressed to a field
     * name nothing on the screen was looking for. The admin filled the form,
     * pressed Create account, and watched the button re-enable in silence.
     *
     * Both spellings are accepted rather than one being picked, because either
     * choice alone breaks a caller that is already out there. The singular is
     * folded into the plural before the rules run, so there is exactly one
     * shape below this line.
     */
    private function validateUser(Request $request, bool $creating, ?User $user = null): array
    {
        if ($request->has('role') && ! $request->has('roles')) {
            $request->merge(['roles' => array_filter((array) $request->input('role'))]);
        }

        $required = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'first_name' => [$required, 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => [$required, 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'gender' => [$required, 'in:M,F'],
            'email' => [$required, 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'mobile_number' => [$required, 'string', 'max:20'],
            'password' => $creating
                ? ['required', PasswordRule::min(8)]
                : ['nullable', PasswordRule::min(8)],
            'department_id' => ['nullable', 'exists:departments,id'],
            'roles' => [$required, 'array', 'min:1'],
            'roles.*' => [Rule::exists('roles', 'name')->whereNotIn('name', self::EXCLUDED_ROLES)],
        ], [
            'email.unique' => 'This email is already registered.',
            'roles.required' => 'Choose the role this account signs in with.',
            'roles.*.exists' => 'Choose one of the LGU staff roles. Business owners register their own accounts.',
        ]);

        $this->assertOfficeMatchesRole($data, $user);

        return $data;
    }

    /**
     * An officer needs an office; the super admin must not have one.
     *
     * Checked against the roles and department this account will END UP with,
     * not the ones in the request — an edit that changes only the office still
     * has to hold against the role already on the account, and vice versa.
     */
    private function assertOfficeMatchesRole(array $data, ?User $user): void
    {
        $roles = $data['roles'] ?? $user?->roleNames() ?? [];
        if ($roles === []) {
            return;
        }

        $departmentId = array_key_exists('department_id', $data)
            ? $data['department_id']
            : $user?->department_id;

        $officeless = array_intersect($roles, self::OFFICELESS_ROLES) !== [];

        if ($officeless && $departmentId !== null) {
            throw ValidationException::withMessages([
                'department_id' => [
                    in_array(self::DEPARTMENTLESS_ROLE, $roles, true)
                        ? 'The super admin works across every office, so this account cannot belong to one.'
                        : 'A business owner is not LGU staff, so this account cannot belong to an office.',
                ],
            ]);
        }

        if (! $officeless && $departmentId === null) {
            throw ValidationException::withMessages([
                'department_id' => ['Choose an office. An officer with no office signs in to an empty queue.'],
            ]);
        }
    }
}
