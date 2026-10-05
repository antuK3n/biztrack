<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\ApplicationAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Audit;
use App\Support\Caseload;
use App\Support\StaffCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
     * The office role a typed one copies its permissions from.
     *
     * `sanitary_officer` rather than a list written out here: the five office
     * roles carry the same seven permissions (zoning adds `zoning.evaluate`),
     * so any of them is the standard set — and taking it from a role means a
     * change to what an officer may do reaches roles created this way without
     * anybody remembering a second place to edit.
     */
    private const OFFICE_ROLE_TEMPLATE = 'sanitary_officer';

    /**
     * The staff directory. Paginated, alphabetical.
     *
     * Alphabetical rather than newest-first on purpose: this is a directory you
     * look somebody up in, not a feed. The payload carries every role and every
     * permission per user, so it grows faster than the row count suggests.
     */
    /**
     * What "holding" counts, for `withCount` and `loadCount` alike.
     *
     * Written once because the directory's figure and the caseload screen's
     * figure describe one fact, and because the three endpoints that answer
     * with a single user have to agree with the one that answers with a list.
     * The conditions themselves come from `Caseload`, which is where the rule
     * lives.
     */
    private static function caseloadCounts(): array
    {
        return [
            'assignments as open_reviews_count' => fn ($a) => Caseload::scopeOpen($a),
            'inspections as open_inspections_count' => fn ($i) => $i->whereNotIn('status', Caseload::CLOSED_INSPECTIONS),
        ];
    }

    /**
     * One user, loaded the way the directory loads every row.
     *
     * -- Why this exists -------------------------------------------------
     *
     * Creating, editing and activating all answer with the changed user, and
     * the page puts that answer straight back into the row it came from. None
     * of them counted the caseload, so `open_reviews` was absent from the
     * payload, `whenCounted` dropped the key, and the Holding cell fell back
     * to a dash - a row that read "2 filings" a second ago read "-" for no
     * reason the administrator could see, and stayed that way until the page
     * was reloaded [client, 27 September 2026: *"bat may ganyan pa sa holding,
     * kung wala, it should be automatic na Nothing"*].
     *
     * The fix belongs here rather than in the cell. A dash meant "the server
     * did not say", which was true and useless; printing "Nothing" instead
     * would have invented a zero. Sending the figure makes both readings
     * unnecessary.
     */
    private function withCaseload(User $user): User
    {
        return $user->loadCount(self::caseloadCounts())
            ->load('department', 'roles.permissions');
    }

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

        /*
         * ── The workload column ──────────────────────────────────────────
         *
         * Two counts per officer, so the directory can say who is carrying
         * what without the reader opening every row in turn [client, 27
         * September 2026: "need mo pa pindutin isa isa kung ano laman na
         * permit na hawak nila"].
         *
         * `withCount`, not a per-row query: this list is paginated and a
         * `Caseload::summary()` per officer would be two queries a row.
         *
         * The conditions come from `Caseload` rather than being written again
         * here. The directory's number and the caseload screen's number
         * describe one fact, and a "holding 3" beside a page listing 2 is the
         * kind of disagreement nobody reports — they just stop trusting both.
         */
        $query = User::with('department', 'roles.permissions')
            ->withCount(self::caseloadCounts())
            ->orderBy('name')
            ->orderBy('id');

        if ($q = $request->query('q')) {
            $query->where(fn ($sub) => $sub
                ->whereLike('name', "%{$q}%")
                ->orWhereLike('email', "%{$q}%"));
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
            ->get(['id', 'name', 'display_name', 'description']);

        $superAdminTaken = $this->superAdminExists();

        /*
         * ── Which offices each role is actually used in ─────────────────────
         *
         * There is no role→office column, and there should not be: nothing in
         * the register forbids a BPLO account holding the Fire Inspector role,
         * and the office is what does the scoping anyway. But an admin who has
         * just picked CHO does not want to read six roles, five of which
         * belong to other offices [client, 27 September 2026: *"may dropdown
         * na ng kung ano ano ang role sa office na pinili na yon"*].
         *
         * So the form is told where each role is IN USE, derived from the
         * accounts that hold it, and puts those first. Derived rather than
         * declared, because it stays true without anybody maintaining it — and
         * it is why a role typed for CHO today is a CHO role tomorrow, as soon
         * as the account holding it exists.
         *
         * One query for the whole table, not one per role.
         */
        $usedIn = DB::table('user_roles')
            ->join('users', 'users.id', '=', 'user_roles.user_id')
            ->whereNotNull('users.department_id')
            ->whereNull('users.deleted_at')
            ->select('user_roles.role_id', 'users.department_id')
            ->distinct()
            ->get()
            ->groupBy('role_id')
            ->map(fn ($rows) => $rows->pluck('department_id')->map(fn ($id) => (int) $id)->values()->all());

        return response()->json([
            'data' => $roles->map(fn (Role $role) => [
                'name' => $role->name,
                'label' => $role->display_name,
                'description' => $role->description,
                // The form has to know which choice hides the Office field, and
                // it must not learn that by hard-coding the string 'admin'.
                'wants_department' => $role->name !== self::DEPARTMENTLESS_ROLE,
                /*
                 * There is one super admin seat and it is either free or taken.
                 *
                 * Reported rather than merely enforced so the form can grey the
                 * option out with a reason, instead of letting an admin fill in
                 * a whole account and meet the refusal on submit. Every office
                 * role stays available however many people already hold it —
                 * offices are explicitly allowed more than one account each.
                 */
                'available' => $role->name !== self::DEPARTMENTLESS_ROLE || ! $superAdminTaken,
                /*
                 * The offices where somebody currently holds this role. Empty
                 * for a role nobody has yet — including one typed a moment ago
                 * whose account is still being filled in, which is why the
                 * form must show unused roles too rather than hiding them.
                 */
                'used_in_departments' => $usedIn->get($role->id, []),
            ])->all(),
        ]);
    }

    /** Is the single super-admin seat occupied — by anyone other than $excluding? */
    private function superAdminExists(?User $excluding = null): bool
    {
        return User::whereHas('roles', fn ($r) => $r->where('name', self::DEPARTMENTLESS_ROLE))
            ->when($excluding, fn ($q) => $q->whereKeyNot($excluding->id))
            ->exists();
    }

    public function store(Request $request): JsonResponse
    {
        /*
         * ── Validation inside the transaction, on purpose ───────────────────
         *
         * A typed role is created by `validateUser` before the rest of the
         * request is judged (it has to be, so that `roles.*`'s `exists` rule
         * and the office and super-admin guards all apply to it unchanged).
         * Left outside a transaction, an admin who typed "Records Clerk III"
         * and then failed on a weak password would leave that role in the
         * register for ever, held by nobody and offered to everybody.
         *
         * A ValidationException thrown in here rolls the role back with
         * everything else, so a refused request leaves no trace — which is
         * what a refused request should do.
         */
        return DB::transaction(fn () => $this->createUser($request));
    }

    private function createUser(Request $request): JsonResponse
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
            'data' => new UserResource($this->withCaseload($user)),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        // In a transaction for the same reason as store(): a typed role must
        // not survive a request the rest of which was refused.
        return DB::transaction(fn () => $this->applyUserEdit($request, $user));
    }

    private function applyUserEdit(Request $request, User $user): JsonResponse
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

        /*
         * -- A new password ends the old sessions ---------------------------
         *
         * An admin setting somebody else's password is doing one of two
         * things: handing back an account whose owner is locked out, or taking
         * one away from whoever has it. The second case is the one that
         * matters, and it does not work unless the tokens go: Sanctum tokens
         * are independent of the password, so without this the person who
         * prompted the reset keeps the session they already had and the reset
         * accomplishes nothing at all.
         *
         * Not logged with the password, obviously, and not logged with a hash
         * of it either - the audit trail records THAT it happened, which is
         * what an auditor needs, and nothing a reader of the trail could take
         * an account with.
         */
        if (filled($data['password'] ?? null)) {
            $user->tokens()->delete();
            Audit::log('user.password_reset', $user, ['by' => $request->user()?->id]);
        }

        Audit::log('user.updated', $user);

        return response()->json([
            'data' => new UserResource($this->withCaseload($user->fresh())),
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
                /*
                 * Named on, but finished — so the dialog can reconcile itself
                 * with the OIC register, which lists every assignment an
                 * officer's name is on. Nothing here moves; see Caseload.
                 */
                'finished_reviews' => $summary['finished_reviews'],
                /*
                 * The work itself, so the dialog can name what it is moving
                 * rather than only counting it. Capped; `total` above stays the
                 * exact figure and the screen says when the list is shorter.
                 */
                'cases' => Caseload::cases($user),
                /*
                 * What NOBODY in their office holds — the other direction the
                 * Reassign dialog now offers. Kept as its own list rather than
                 * folded into `cases`, because they are opposite acts: one
                 * moves work away from this officer, the other gives work to
                 * them, and a single list would need a flag on every row to
                 * say which.
                 */
                'unassigned' => Caseload::unassignedInOfficeOf($user),
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
     * Give this officer work nobody is holding.
     *
     * `reassignCaseload` above moves work AWAY from the officer whose row was
     * clicked. This is the same act from the other end, asked for by the
     * client: an office's unassigned filings listed in the same dialog, so a
     * case nobody has picked up can be handed to whichever officer the admin
     * opened.
     *
     * Same permission, because `oic.assign` names who handles a case whichever
     * way the case moves. Same office boundary too: an officer may only be
     * given work their own office was routed, and the check is here rather than
     * left to the caller because `cases` arrives from a browser.
     */
    public function takeCases(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'cases' => ['required', 'array', 'min:1'],
            'cases.*.kind' => ['required', Rule::in(['review'])],
            'cases.*.id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Say why this work is being handed over — it is recorded against the officer.',
        ]);

        abort_if(
            $user->department_id === null,
            422,
            'This account belongs to no office, so there is no office queue to take work from.'
        );

        $ids = collect($data['cases'])->pluck('id');

        /*
         * Checked against what is ACTUALLY free in that officer's office,
         * before anything moves. Without this the endpoint would take any id
         * and move a colleague's case by it — a reassignment nobody asked for,
         * recorded against an admin who chose a different act entirely.
         *
         * Refused whole rather than filtered down to the valid rows: a dialog
         * that asked to take three and took two, silently, is the "reported
         * success without acting" defect in a smaller hat.
         */
        $free = ApplicationAssignment::whereIn('id', $ids)
            ->where('department_id', $user->department_id)
            ->whereNull('officer_user_id')
            ->get();

        if ($free->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages([
                'cases' => ['Some of those filings are no longer free, or belong to another office. Close this dialog and open it again.'],
            ]);
        }

        DB::transaction(function () use ($free, $user, $data) {
            foreach ($free as $assignment) {
                $assignment->forceFill([
                    'officer_user_id' => $user->id,
                    // The date the OIC register prints. An assignment handed
                    // out without one shows a holder and a blank column.
                    'assigned_at' => now(),
                ])->save();

                Audit::log('assignment.reassigned', $assignment, [
                    'officer_user_id' => $user->id,
                    'reason' => $data['reason'],
                    'from' => 'office queue',
                ]);
            }

            Audit::log('user.cases_taken', $user, [
                'count' => $free->count(),
                'reason' => $data['reason'],
            ]);
        });

        return response()->json([
            'data' => [
                'total' => $free->count(),
                'to' => ['id' => $user->id, 'name' => $user->name],
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
            /*
             * Two ways to say what moves, and exactly one of them is required.
             *
             * `cases` names the rows, which is how the dialog asks: the admin
             * ticks the permits this officer is holding. It is the ordinary
             * case — one filing going to a colleague because it is stuck, while
             * the rest of the caseload stays where it is — and no category can
             * express that.
             *
             * `scope` stays because "move everything" is a real intent that
             * should not need forty ids enumerated to state it, and because the
             * Deactivate path releases a whole caseload through this endpoint
             * with no list to hand it.
             */
            'scope' => ['required_without:cases', 'nullable', Rule::in(['all', 'reviews', 'inspections'])],
            'cases' => ['required_without:scope', 'nullable', 'array', 'min:1'],
            'cases.*.kind' => ['required', Rule::in(['review', 'inspection'])],
            'cases.*.id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Say why this caseload is moving — it is recorded against both officers.',
            'scope.required_without' => 'Choose which permits to move.',
            'cases.required_without' => 'Choose which permits to move.',
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

        /*
         * A move that would move nothing is refused, not quietly performed.
         *
         * The endpoint used to answer 200 with `{"total": 0}` and the dialog
         * printed a tick: an admin typed a reason, pressed the button, was told
         * it had happened, and nothing had. That is the exact defect the
         * Reassign dialog was rebuilt to remove — a control reporting success
         * without acting — surviving at the one input nobody tried.
         *
         * Counted per SCOPE rather than off the total, because the total hides
         * the interesting case: an officer holding reviews and no inspections
         * has a caseload, so a total-based guard would wave an
         * inspections-only move through and report zero moved.
         */
        /*
         * A picked list is checked against what the officer ACTUALLY holds,
         * before anything moves.
         *
         * `cases` arrives from a browser. Without this the endpoint would take
         * any id and hand another officer's filing to whoever the dialog named
         * — a reassignment nobody asked for, recorded against an admin who did
         * not choose it. Finished work is refused by the same check, because
         * `Caseload::reviews()` excludes it: a completed review keeps the name
         * of the officer who made it.
         *
         * Refused whole rather than filtered down to the valid rows. A dialog
         * that asked to move three and moved two, silently, is the "reported
         * success without acting" defect wearing a smaller hat.
         */
        $picked = collect($data['cases'] ?? []);
        if ($picked->isNotEmpty()) {
            $heldReviews = Caseload::reviews($user)->pluck('id');
            $heldInspections = Caseload::inspections($user)->pluck('id');

            $strays = $picked->reject(fn (array $case) => $case['kind'] === 'review'
                ? $heldReviews->contains($case['id'])
                : $heldInspections->contains($case['id']));

            if ($strays->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'cases' => ["{$user->name} is not holding all of the cases you picked. Some may have been moved or finished since this page was opened — close it and try again."],
                ]);
            }
        }

        $available = $picked->isNotEmpty() ? $picked->count() : match ($data['scope']) {
            'reviews' => Caseload::reviews($user)->count(),
            'inspections' => Caseload::inspections($user)->count(),
            default => Caseload::summary($user)['total'],
        };

        if ($available === 0) {
            $finished = Caseload::finishedReviews($user)->count();

            throw ValidationException::withMessages([
                'scope' => [$finished > 0
                    ? "{$user->name} has no open work to move. The {$finished} review(s) they are named on are finished, and a completed review keeps the name of the officer who made it."
                    : "{$user->name} is not holding anything, so there is nothing to move."],
            ]);
        }

        $moved = DB::transaction(function () use ($user, $target, $data, $picked) {
            $moved = ['reviews' => 0, 'inspections' => 0];

            // A picked list narrows both queries; `scope` is ignored when one
            // is given, because the list already says exactly what moves.
            $pickedReviews = $picked->where('kind', 'review')->pluck('id');
            $pickedInspections = $picked->where('kind', 'inspection')->pluck('id');
            $scope = $picked->isNotEmpty() ? null : ($data['scope'] ?? 'all');

            if ($scope !== 'inspections' && ($picked->isEmpty() || $pickedReviews->isNotEmpty())) {
                $moved['reviews'] = Caseload::reviews($user)
                    ->when($picked->isNotEmpty(), fn ($q) => $q->whereIn('id', $pickedReviews))
                    ->get()
                    ->each(function ($assignment) use ($target, $data) {
                        $assignment->update(['officer_user_id' => $target?->id]);
                        Audit::log('assignment.reassigned', $assignment, [
                            'officer_user_id' => $target?->id,
                            'reason' => $data['reason'],
                        ]);
                    })->count();
            }

            if ($scope !== 'reviews' && ($picked->isEmpty() || $pickedInspections->isNotEmpty())) {
                $moved['inspections'] = Caseload::inspections($user)
                    ->when($picked->isNotEmpty(), fn ($q) => $q->whereIn('id', $pickedInspections))
                    ->get()
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
                'scope' => $scope,
                // The picked list is recorded too: "scope: null" on its own
                // would leave the trail unable to say what an admin chose.
                'cases' => $picked->isNotEmpty() ? $picked->values()->all() : null,
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
        /*
         * The sole super admin cannot be switched off. `user.manage` lives on no
         * other role, so deactivating this one account locks every remaining
         * administrative action out of the app for good — and it is one button
         * on a row that looks like any other. See assertSuperAdminSeat().
         */
        if ($user->is_active && in_array(self::DEPARTMENTLESS_ROLE, $user->roleNames(), true)) {
            throw ValidationException::withMessages([
                'is_active' => ['This is the only super admin. Deactivating it would leave nobody able to manage accounts.'],
            ]);
        }

        $released = null;
        $deactivating = $user->is_active;
        /*
         * Deactivating retires the account, so the audit row carries the
         * account as it stood — with its roles — before the switch (Audit Log
         * 1). Taken first: after the update it would record the retired state.
         * Reactivation is not a removal and keeps its plain row.
         */
        $snapshot = $deactivating ? Audit::snapshot($user, ['roles']) : null;
        if ($deactivating) {
            $released = $this->releaseCaseload($user, 'user.deactivated');
        }

        $user->update(['is_active' => ! $user->is_active]);
        if (! $user->is_active) {
            $user->tokens()->delete();
        }
        Audit::log('user.toggle_active', $user, ['is_active' => $user->is_active] + ($released ?? []), $snapshot);

        return response()->json([
            'data' => new UserResource($this->withCaseload($user->fresh())),
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

        /*
         * Tidied before it is judged, so `+63 917 123 4567` and `0917-123-4567`
         * are accepted as the numbers they plainly are. A number of the wrong
         * LENGTH is not a formatting preference and still fails below.
         */
        if ($request->has('mobile_number')) {
            $request->merge([
                'mobile_number' => StaffCredentials::normaliseMobile($request->input('mobile_number')),
            ]);
        }

        /*
         * ── A role that is not on the list yet ──────────────────────────────
         *
         * `new_role` is a job title typed into the Role box because the office
         * does not have one by that name [client, 27 September 2026: *"pede
         * rin nila i type yung role kung wala sa choices"*]. It is turned into
         * a real role here and then handled exactly like a chosen one, so
         * everything below — the office check, the super-admin seat, the
         * `exists` rule on `roles.*` — applies to it unchanged.
         *
         * Resolved BEFORE validation rather than after it, for that reason:
         * a second code path that creates accounts, skipping those three
         * guards, is how the singleton super-admin seat stops being singleton.
         */
        if (filled($request->input('new_role')) && ! filled($request->input('roles'))) {
            $request->merge(['roles' => [$this->roleFromTypedTitle($request)]]);
        }

        // Lowercased before the unique check, as it is stored — see
        // AuthController::register for the 500 this answered when it was not.
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }

        $data = $request->validate([
            'first_name' => [$required, 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => [$required, 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'gender' => [$required, 'in:M,F'],
            'email' => [$required, 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'mobile_number' => StaffCredentials::mobileRules(required: $creating),
            /*
             * Optional on an edit and required on a create, both from the one
             * policy. `nullable` is what lets the edit form post an untouched,
             * empty password field without that being read as "clear it" - see
             * the note in update().
             */
            'password' => StaffCredentials::passwordRules(required: $creating),
            'department_id' => ['nullable', 'exists:departments,id'],
            'roles' => [$required, 'array', 'min:1'],
            'roles.*' => [Rule::exists('roles', 'name')->whereNotIn('name', self::EXCLUDED_ROLES)],
        ], [
            'email.unique' => 'This email is already registered.',
            'roles.required' => 'Choose the role this account signs in with.',
            'roles.*.exists' => 'Choose one of the LGU staff roles. Business owners register their own accounts.',
        ] + StaffCredentials::messages());

        $this->assertOfficeMatchesRole($data, $user);
        $this->assertSuperAdminSeat($data, $user);

        return $data;
    }

    /**
     * Turn a typed job title into a real role, and answer with its name.
     *
     * ── Why typing one is safe here, and what makes it safe ────────────────
     *
     * A role in this register is a permission bundle, so the obvious
     * implementation — create the row, attach nothing — mints an account that
     * signs in to a blank app and can do nothing, with no error anywhere to
     * say why. That is the trap this method exists to avoid.
     *
     * What makes it avoidable is that an office role is almost entirely a job
     * TITLE. Measured on the register: `sanitary_officer`, `fire_inspector`,
     * `obo_staff` and `cenro_officer` carry byte-for-byte the same seven
     * permissions, and `zoning_officer` those seven plus `zoning.evaluate`.
     * What actually decides which filings an officer sees is their
     * `department_id`, not their role. So "Sanitary Inspector II" is a real
     * thing an office needs to be able to write down, and it needs exactly the
     * powers every other office role has.
     *
     * The new role therefore COPIES an existing office role's permissions
     * rather than starting empty, and the office still does the scoping.
     *
     * ── The one door this does not open ────────────────────────────────────
     *
     * A departmentless role. `admin` holds fourteen permissions including
     * `user.manage`, which is the power to mint accounts — and a typed name
     * that reached it would be privilege escalation by spelling. A typed role
     * always belongs to an office, and the request is refused when no office
     * is named.
     */
    private function roleFromTypedTitle(Request $request): string
    {
        $label = trim((string) $request->input('new_role'));

        if (mb_strlen($label) < 3) {
            throw ValidationException::withMessages([
                'new_role' => ['A role name needs at least three characters.'],
            ]);
        }

        /*
         * An office is required, and it is required HERE rather than being
         * left to `assertOfficeMatchesRole` below: by the time that runs the
         * role exists, so a refused request would have left a role row behind
         * that nobody holds.
         */
        if (! filled($request->input('department_id'))) {
            throw ValidationException::withMessages([
                'new_role' => [
                    'Choose the office first. A typed role belongs to an office — only the '
                    .'super admin works across every one, and that seat cannot be created by '
                    .'naming it.',
                ],
            ]);
        }

        $name = Str::slug($label, '_');

        /*
         * An existing role wins, matched on the slug and on the display name.
         *
         * Two admins typing "Sanitary Inspector II" a week apart must land on
         * one role, not two that differ by a space — and somebody typing the
         * exact label of a role already on the list gets that role rather than
         * a near-duplicate beside it.
         */
        $existing = Role::where('name', $name)
            ->orWhereRaw('lower(display_name) = ?', [mb_strtolower($label)])
            ->first();

        if ($existing !== null) {
            if (in_array($existing->name, self::EXCLUDED_ROLES, true)) {
                throw ValidationException::withMessages([
                    'new_role' => ['That is not an LGU staff role. Business owners register their own accounts.'],
                ]);
            }

            return $existing->name;
        }

        // The permissions every office role has. Taken from a role rather than
        // listed here, so a change to what an officer may do reaches roles
        // made this way without anybody remembering to update a second list.
        $template = Role::where('name', self::OFFICE_ROLE_TEMPLATE)->firstOrFail();

        $role = Role::create([
            'name' => $name,
            'display_name' => $label,
            'description' => 'Office role added from the officer directory. Works like '
                ."{$template->display_name}.",
        ]);
        $role->permissions()->sync($template->permissions()->pluck('permissions.id'));

        Audit::log('role.created', $role, [
            'display_name' => $label,
            'copied_from' => $template->name,
            'by' => $request->user()?->id,
        ]);

        return $role->name;
    }

    /**
     * There is exactly one super admin: the seat cannot be doubled, and it
     * cannot be vacated.
     *
     * ── Why one ──────────────────────────────────────────────────────────────
     *
     * The super admin is the only account that can create office accounts —
     * `user.manage` is held by no other role — so it is the root of how everyone
     * else gets in. Two of them is two people who can mint officers for any
     * office and reassign any office's caseload, with no record of which of them
     * is the real one; the register's own account of who authorised a staff
     * account stops meaning anything. Nothing enforced this: a second one could
     * be created, or an existing officer promoted, and the endpoint answered 201.
     *
     * ── Why it also cannot be emptied ────────────────────────────────────────
     *
     * The mirror case is worse and reachable by accident. Demote the sole super
     * admin, or deactivate them, and `user.manage` is held by nobody: no account
     * can be created or corrected, no caseload reassigned, no business status
     * changed — permanently, from inside the app, with no way back except a
     * hand-written database edit. A one-click, irreversible lockout is not a
     * thing an admin screen should offer, so both doors are shut here.
     */
    private function assertSuperAdminSeat(array $data, ?User $user): void
    {
        // Nothing to check unless this write actually names the roles.
        if (! isset($data['roles'])) {
            return;
        }

        $becomingSuperAdmin = in_array(self::DEPARTMENTLESS_ROLE, $data['roles'], true);

        if ($becomingSuperAdmin && $this->superAdminExists($user)) {
            throw ValidationException::withMessages([
                'roles' => ['There is already a super admin, and there can only be one. Every office role may be held by as many accounts as you need.'],
            ]);
        }

        $wasSuperAdmin = $user !== null && in_array(self::DEPARTMENTLESS_ROLE, $user->roleNames(), true);

        if ($wasSuperAdmin && ! $becomingSuperAdmin) {
            throw ValidationException::withMessages([
                'roles' => ['This is the only super admin. Changing its role would leave nobody able to create office accounts.'],
            ]);
        }
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
